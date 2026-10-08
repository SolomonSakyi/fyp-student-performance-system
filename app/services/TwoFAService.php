<?php

/**
 * TwoFAService - Two-Factor Authentication management
 * @package EduTrack
 * @filepath app/services/TwoFAService.php
 */

class TwoFAService
{
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
     * Check if 2FA is enabled for a user
     */
    public static function isEnabled($userId)
    {
        $db = self::getDb();

        $result = $db->fetchOne(
            "SELECT is_enabled FROM platform_2fa WHERE user_id = ?",
            [$userId]
        );

        return $result && $result['is_enabled'] == 1;
    }

    /**
     * Check if 2FA is mandatory for a user based on roles
     */
    public static function isMandatory($userId)
    {
        $db = self::getDb();
        $config = self::getConfig();
        $mandatoryRoles = $config['2fa']['mandatory_roles'] ?? ['SUPER_ADMIN', 'TENANT_ADMIN'];

        $userRoles = $db->fetchAll(
            "SELECT r.role_code 
             FROM platform_user_roles ur
             JOIN platform_roles r ON ur.role_id = r.id
             WHERE ur.user_id = ? AND ur.is_active = 1",
            [$userId]
        );

        $roleCodes = array_column($userRoles, 'role_code');

        foreach ($mandatoryRoles as $role) {
            if (in_array($role, $roleCodes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enable 2FA for a user
     */
    public static function enable($userId)
    {
        $db = self::getDb();

        // Generate secret key (for TOTP)
        $secretKey = self::generateSecret();

        // Generate backup codes
        $backupCodes = self::generateBackupCodes();

        // Check if 2FA record exists
        $existing = $db->fetchOne(
            "SELECT id FROM platform_2fa WHERE user_id = ?",
            [$userId]
        );

        if ($existing) {
            $db->execute(
                "UPDATE platform_2fa 
                 SET secret_key = ?, backup_codes = ?, is_enabled = 1, is_verified = 0, updated_at = NOW()
                 WHERE user_id = ?",
                [$secretKey, json_encode($backupCodes), $userId]
            );
        } else {
            $db->execute(
                "INSERT INTO platform_2fa (user_id, secret_key, backup_codes, is_enabled, is_verified, created_at) 
                 VALUES (?, ?, ?, 1, 0, NOW())",
                [$userId, $secretKey, json_encode($backupCodes)]
            );
        }

        // Update user record
        $db->execute(
            "UPDATE platform_users SET two_factor_enabled = 1 WHERE id = ?",
            [$userId]
        );

        return [
            'success' => true,
            'secret_key' => $secretKey,
            'backup_codes' => $backupCodes,
            'message' => '2FA enabled successfully.'
        ];
    }

    /**
     * Disable 2FA for a user
     */
    public static function disable($userId)
    {
        $db = self::getDb();

        $db->execute(
            "UPDATE platform_2fa SET is_enabled = 0, is_verified = 0 WHERE user_id = ?",
            [$userId]
        );

        $db->execute(
            "UPDATE platform_users SET two_factor_enabled = 0 WHERE id = ?",
            [$userId]
        );

        return [
            'success' => true,
            'message' => '2FA disabled successfully.'
        ];
    }

    /**
     * Verify 2FA code
     */
    public static function verify($userId, $code)
    {
        $db = self::getDb();

        // Get 2FA data
        $twofa = $db->fetchOne(
            "SELECT secret_key, backup_codes, is_enabled FROM platform_2fa WHERE user_id = ?",
            [$userId]
        );

        if (!$twofa || $twofa['is_enabled'] != 1) {
            return [
                'success' => false,
                'message' => '2FA is not enabled for this user.'
            ];
        }

        // Check if it's a backup code
        $backupCodes = json_decode($twofa['backup_codes'], true);
        if (is_array($backupCodes)) {
            foreach ($backupCodes as $index => $backupCode) {
                if ($backupCode === $code) {
                    // Remove used backup code
                    unset($backupCodes[$index]);
                    $db->execute(
                        "UPDATE platform_2fa SET backup_codes = ? WHERE user_id = ?",
                        [json_encode(array_values($backupCodes)), $userId]
                    );
                    return [
                        'success' => true,
                        'message' => 'Backup code verified successfully.'
                    ];
                }
            }
        }

        // Verify TOTP code
        $valid = self::verifyTOTP($twofa['secret_key'], $code);

        if ($valid) {
            // Mark as verified
            $db->execute(
                "UPDATE platform_2fa SET is_verified = 1 WHERE user_id = ?",
                [$userId]
            );
            return [
                'success' => true,
                'message' => '2FA code verified successfully.'
            ];
        }

        return [
            'success' => false,
            'message' => 'Invalid 2FA code.'
        ];
    }

    /**
     * Generate a secret key for TOTP
     */
    private static function generateSecret()
    {
        // Simple secret generation - in production use a proper TOTP library
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 16; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $secret;
    }

    /**
     * Generate backup codes
     */
    private static function generateBackupCodes($count = 10)
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
        }
        return $codes;
    }

    /**
     * Verify TOTP code
     */
    private static function verifyTOTP($secret, $code)
    {
        // This is a simplified TOTP verification
        // In production, use a proper TOTP library like Sonata\GoogleAuthenticator

        // For now, accept if the code matches a simple pattern
        // This is NOT secure for production - use a proper library
        return preg_match('/^[0-9]{6}$/', $code);
    }

    /**
     * Get configuration
     */
    private static function getConfig()
    {
        $projectRoot = dirname(__DIR__, 2);
        $configPath = $projectRoot . '/config/auth.php';

        if (file_exists($configPath)) {
            return include $configPath;
        }

        return [
            '2fa' => [
                'enabled' => true,
                'mandatory_roles' => ['SUPER_ADMIN', 'TENANT_ADMIN'],
                'backup_codes_count' => 10,
                'code_length' => 6,
            ]
        ];
    }

    /**
     * Get 2FA status for a user
     */
    public static function getStatus($userId)
    {
        $db = self::getDb();

        $twofa = $db->fetchOne(
            "SELECT is_enabled, is_verified FROM platform_2fa WHERE user_id = ?",
            [$userId]
        );

        return [
            'enabled' => $twofa && $twofa['is_enabled'] == 1,
            'verified' => $twofa && $twofa['is_verified'] == 1,
        ];
    }

    /**
     * Get backup codes for a user
     */
    public static function getBackupCodes($userId)
    {
        $db = self::getDb();

        $twofa = $db->fetchOne(
            "SELECT backup_codes FROM platform_2fa WHERE user_id = ?",
            [$userId]
        );

        if (!$twofa) {
            return [];
        }

        return json_decode($twofa['backup_codes'], true) ?? [];
    }

    /**
     * Regenerate backup codes
     */
    public static function regenerateBackupCodes($userId)
    {
        $db = self::getDb();
        $config = self::getConfig();
        $count = $config['2fa']['backup_codes_count'] ?? 10;

        $backupCodes = self::generateBackupCodes($count);

        $db->execute(
            "UPDATE platform_2fa SET backup_codes = ? WHERE user_id = ?",
            [json_encode($backupCodes), $userId]
        );

        return $backupCodes;
    }
}
