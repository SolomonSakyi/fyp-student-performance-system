<?php

/**
 * OTPService - One-Time Password generation, validation, and delivery
 * @package EduTrack
 * @filepath app/services/OTPService.php
 * @version 1.3
 *
 * v1.3 change (2026-10-05) [ITEM-18]:
 *   sendSMS() now delivers the OTP through the tenant's configured
 *   SMS provider. The previous version returned an honest
 *   success => false with the message "SMS delivery is not yet
 *   implemented for provider '<provider>'". This version:
 *     - loads app/services/SMS/SMSProviderFactory.php;
 *     - resolves the provider by the tenant's sms_provider value;
 *     - builds a $config array for the provider with api_key,
 *       sender_id, and timeout;
 *     - calls the provider's send() method;
 *     - returns the provider's result unchanged.
 *   The read of sms_sender_id is added — the previous version read
 *   only sms_provider and sms_api_key. When the factory returns
 *   null (an unsupported provider), sendSMS() returns
 *   success => false with a message naming the value.
 *   Every other method is byte-identical to v1.2.
 *
 * v1.2 change (2026-10-05) [ITEM-21]:
 *   sendSMS() is no longer a stub that returns success => true
 *   without sending an SMS. (Superseded by v1.3, which delivers
 *   through a real provider.)
 *
 * v1.1 change (2026-10-05) [ITEM-20]:
 *   Every query target was changed from the non-existent table
 *   `platform_otp` to the live table `login_otps`. The column list
 *   was remapped to match the live schema.
 */

class OTPService
{
    private static $config = null;
    private static $db = null;

    /**
     * Get database instance
     */
    private static function getDb()
    {
        if (self::$db === null) {
            $projectRoot = dirname(__DIR__, 2);
            require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
            self::$db = DatabaseHelper::getInstance();
        }
        return self::$db;
    }

    /**
     * Get configuration
     */
    private static function getConfig()
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $projectRoot = dirname(__DIR__, 2);
        $configPath = $projectRoot . '/config/auth.php';

        if (file_exists($configPath)) {
            self::$config = include $configPath;
        } else {
            self::$config = [
                'otp' => [
                    'length' => 6,
                    'expiry_seconds' => 900,
                    'max_attempts' => 5,
                    'resend_cooldown' => 60,
                    'methods' => ['sms', 'email'],
                    'default_method' => 'email',
                ]
            ];
        }

        return self::$config;
    }

    /**
     * Read the current tenant's integration settings.
     *
     * [v1.2 ITEM-21] Local helper used by sendSMS() to determine
     * whether SMS delivery is configured. Reads through
     * SettingsService::getSettings('integrations', $tenantId).
     * Returns an empty array on any failure so callers treat
     * missing settings as "not configured".
     */
    private static function getIntegrationSettings()
    {
        $tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
        if ($tenantId <= 0) {
            return [];
        }
        try {
            $projectRoot = dirname(__DIR__, 2);
            require_once $projectRoot . '/app/services/Platform/SettingsService.php';
            if (!class_exists('SettingsService')) {
                return [];
            }
            $svc = new SettingsService();
            return $svc->getSettings('integrations', $tenantId);
        } catch (Exception $e) {
            error_log('OTPService::getIntegrationSettings failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Generate a random OTP code
     *
     * [v1.1 ITEM-20] Writes to login_otps. The plaintext code is
     * returned to the caller and never stored; only its hash (with
     * the purpose prepended) is written.
     */
    public static function generate($userId, $purpose = 'verification')
    {
        $db = self::getDb();
        $config = self::getConfig();
        $length = $config['otp']['length'] ?? 6;
        $expirySeconds = $config['otp']['expiry_seconds'] ?? 900;

        // Generate OTP (plaintext, in memory only)
        $otp = str_pad(random_int(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);

        // Expiry time
        $expiresAt = date('Y-m-d H:i:s', time() + $expirySeconds);

        // Hash the OTP with the purpose prepended.
        $otpHash = password_hash($purpose . ':' . $otp, PASSWORD_DEFAULT);
        $uuid = bin2hex(random_bytes(16));

        // Mark old, unused OTPs for this user as used.
        $db->execute(
            "UPDATE login_otps SET used_at = NOW()
             WHERE user_id = ? AND used_at IS NULL",
            [$userId]
        );

        // Insert the new OTP.
        $db->execute(
            "INSERT INTO login_otps (uuid, user_id, otp_hash, expires_at)
             VALUES (?, ?, ?, ?)",
            [$uuid, $userId, $otpHash, $expiresAt]
        );

        return $otp;
    }

    /**
     * Verify an OTP code
     *
     * [v1.1 ITEM-20] Reads from login_otps. The comparison is
     * password_verify($purpose . ':' . $otpCode, $otp_hash).
     */
    public static function verify($userId, $otpCode, $purpose = 'verification')
    {
        $db = self::getDb();

        $otp = $db->fetchOne(
            "SELECT id, otp_hash, expires_at, used_at
             FROM login_otps
             WHERE user_id = ? AND used_at IS NULL
             ORDER BY created_at DESC LIMIT 1",
            [$userId]
        );

        if (!$otp) {
            return [
                'success' => false,
                'message' => 'No valid OTP found. Please request a new one.'
            ];
        }

        if (strtotime($otp['expires_at']) < time()) {
            return [
                'success' => false,
                'message' => 'OTP has expired. Please request a new one.'
            ];
        }

        if (!password_verify($purpose . ':' . $otpCode, $otp['otp_hash'])) {
            return [
                'success' => false,
                'message' => 'Invalid OTP.'
            ];
        }

        $db->execute(
            "UPDATE login_otps SET used_at = NOW() WHERE id = ?",
            [$otp['id']]
        );

        return [
            'success' => true,
            'message' => 'OTP verified successfully.'
        ];
    }

    /**
     * Send OTP via email
     *
     * Unchanged from v1.0. This method reads only platform_users.
     */
    public static function sendEmail($userId, $otp, $purpose = 'verification')
    {
        $db = self::getDb();

        $user = $db->fetchOne(
            "SELECT email, first_name FROM platform_users WHERE id = ?",
            [$userId]
        );

        if (!$user || empty($user['email'])) {
            return [
                'success' => false,
                'message' => 'User email not found.'
            ];
        }

        $subject = "Your OTP Code - EduTrack";
        $body = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: linear-gradient(135deg, #4facfe, #00f2fe); color: #fff; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                    .content { padding: 30px; background: #f8f9fa; border-radius: 0 0 10px 10px; }
                    .otp-code { font-size: 32px; font-weight: 700; color: #4facfe; text-align: center; padding: 20px; background: #fff; border-radius: 10px; margin: 20px 0; letter-spacing: 5px; }
                    .footer { text-align: center; color: #6c757d; font-size: 12px; margin-top: 20px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>EduTrack Platform</h2>
                    </div>
                    <div class='content'>
                        <p>Hello <strong>" . htmlspecialchars($user['first_name']) . "</strong>,</p>
                        <p>Your OTP code for <strong>" . htmlspecialchars($purpose) . "</strong> is:</p>
                        <div class='otp-code'>" . $otp . "</div>
                        <p>This code will expire in 15 minutes.</p>
                        <p>If you didn't request this code, please ignore this email.</p>
                        <div class='footer'>
                            <p>&copy; " . date('Y') . " EduTrack. All rights reserved.</p>
                        </div>
                    </div>
                </div>
            </body>
            </html>
        ";

        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: EduTrack <noreply@edutrack.com>\r\n";
        $headers .= "Reply-To: support@edutrack.com\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        $result = mail($user['email'], $subject, $body, $headers);

        return [
            'success' => $result,
            'message' => $result ? 'OTP sent to email.' : 'Failed to send OTP email.'
        ];
    }

    /**
     * Send OTP via SMS
     *
     * [v1.3 ITEM-18] Delivers through the tenant's configured SMS
     * provider. This method now:
     *   - reads the tenant's integrations settings through
     *     SettingsService::getSettings('integrations', $tenantId);
     *   - if sms_provider is empty or 'none', or sms_api_key is
     *     empty, returns success => false with a clear message;
     *   - if both are set, resolves the provider through
     *     SMSProviderFactory::make($provider);
     *   - if the factory returns null, returns success => false with
     *     a message naming the unsupported value;
     *   - otherwise builds a $config array with api_key, sender_id
     *     and timeout, calls the provider's send() method, and
     *     returns its result unchanged.
     *
     * The public signature is unchanged.
     */
    public static function sendSMS($userId, $otp, $purpose = 'verification')
    {
        $db = self::getDb();

        // Get user details.
        $user = $db->fetchOne(
            "SELECT phone, first_name FROM platform_users WHERE id = ?",
            [$userId]
        );

        if (!$user || empty($user['phone'])) {
            return [
                'success' => false,
                'message' => 'User phone number not found.'
            ];
        }

        // [v1.2 ITEM-21] Check whether SMS delivery is configured.
        $integrations = self::getIntegrationSettings();
        $provider = isset($integrations['sms_provider']) ? trim((string)$integrations['sms_provider']) : '';
        $apiKey   = isset($integrations['sms_api_key'])  ? trim((string)$integrations['sms_api_key'])  : '';
        // [v1.3 ITEM-18] Read the sender ID. The previous version did
        // not read this key.
        $senderId = isset($integrations['sms_sender_id']) ? trim((string)$integrations['sms_sender_id']) : '';

        if ($provider === '' || $provider === 'none' || $apiKey === '') {
            return [
                'success' => false,
                'message' => 'SMS delivery is not configured for this tenant.'
            ];
        }

        // [v1.3 ITEM-18] Resolve the provider class.
        $projectRoot = dirname(__DIR__, 2);
        require_once $projectRoot . '/app/services/SMS/SMSProviderFactory.php';

        $providerInstance = SMSProviderFactory::make($provider);
        if ($providerInstance === null) {
            return [
                'success' => false,
                'message' => "SMS provider '" . $provider . "' is not supported."
            ];
        }

        // Build the message body.
        $message = "Your OTP code for EduTrack is: " . $otp . ". Valid for 15 minutes.";

        // Build the $config array for the provider.
        $providerConfig = [
            'api_key'   => $apiKey,
            'sender_id' => $senderId,
            'timeout'   => 10,
        ];

        // Call the provider.
        $result = $providerInstance->send((string)$user['phone'], $message, $providerConfig);

        // Log a failure for diagnostics, but return the provider's
        // result unchanged.
        if (empty($result['success'])) {
            error_log(
                "OTPService::sendSMS - provider '" . $provider
                    . "' failed for user_id=" . (int)$userId
                    . ': ' . (string)($result['message'] ?? 'unknown error')
            );
        }

        return $result;
    }

    /**
     * Send OTP using the configured method
     */
    public static function send($userId, $otp = null, $purpose = 'verification')
    {
        $config = self::getConfig();

        if ($otp === null) {
            $otp = self::generate($userId, $purpose);
        }

        $method = $config['otp']['default_method'] ?? 'email';
        $methods = $config['otp']['methods'] ?? ['email'];

        $results = [];

        foreach ($methods as $method) {
            switch ($method) {
                case 'email':
                    $results['email'] = self::sendEmail($userId, $otp, $purpose);
                    break;
                case 'sms':
                    $results['sms'] = self::sendSMS($userId, $otp, $purpose);
                    break;
            }
        }

        $success = false;
        foreach ($results as $result) {
            if ($result['success']) {
                $success = true;
                break;
            }
        }

        return [
            'success' => $success,
            'otp' => $otp,
            'results' => $results,
            'message' => $success ? 'OTP sent successfully.' : 'Failed to send OTP.'
        ];
    }

    /**
     * Resend OTP
     *
     * [v1.1 ITEM-20] The cooldown query reads from login_otps,
     * filtered by used_at IS NULL.
     */
    public static function resend($userId, $purpose = 'verification')
    {
        $config = self::getConfig();
        $cooldown = $config['otp']['resend_cooldown'] ?? 60;

        $db = self::getDb();

        $lastOtp = $db->fetchOne(
            "SELECT created_at FROM login_otps
             WHERE user_id = ? AND used_at IS NULL
             ORDER BY created_at DESC LIMIT 1",
            [$userId]
        );

        if ($lastOtp) {
            $lastTime = strtotime($lastOtp['created_at']);
            $elapsed = time() - $lastTime;
            if ($elapsed < $cooldown) {
                return [
                    'success' => false,
                    'message' => "Please wait " . ($cooldown - $elapsed) . " seconds before requesting a new OTP."
                ];
            }
        }

        return self::send($userId, null, $purpose);
    }

    /**
     * Clean up expired OTPs
     *
     * [v1.1 ITEM-20] Marks expired OTPs used by setting used_at,
     * and deletes OTPs older than one hour.
     */
    public static function cleanup()
    {
        $db = self::getDb();

        $db->execute(
            "UPDATE login_otps SET used_at = NOW()
             WHERE expires_at < NOW() AND used_at IS NULL"
        );

        $db->execute(
            "DELETE FROM login_otps WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );

        return [
            'success' => true,
            'message' => 'OTP cleanup completed.'
        ];
    }
}
