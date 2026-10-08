<?php
/**
 * Security Service
 * Handles security-related functions including rate limiting, CSRF, and security headers
 *
 * @package EduTrack
 * @subpackage Services\Security
 * @version 1.0
 * @filepath app/services/Security/SecurityService.php
 */

class SecurityService
{
    private $db;
    private $tenantContext;

    // Rate limiting configuration
    private const RATE_LIMIT_LOGIN = 5; // Max attempts
    private const RATE_LIMIT_WINDOW = 900; // 15 minutes in seconds
    private const RATE_LIMIT_BLOCK = 3600; // 1 hour block

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->tenantContext = TenantContext::getInstance();
    }

    /**
     * Check if login is rate limited
     *
     * @param string $identifier
     * @param string $ip
     * @return array ['allowed' => bool, 'remaining' => int, 'blocked_until' => string|null]
     */
    public function checkLoginRateLimit(string $identifier, string $ip): array
    {
        $tenantId = $this->tenantContext->getTenantId() ?? 0;

        // Check for existing block
        $blocked = $this->db->fetchOne("
            SELECT blocked_until 
            FROM login_attempts 
            WHERE (identifier = ? OR ip_address = ?) 
              AND tenant_id = ?
              AND blocked_until > NOW()
              AND deleted_at IS NULL
            ORDER BY blocked_until DESC 
            LIMIT 1
        ", [$identifier, $ip, $tenantId]);

        if ($blocked) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'blocked_until' => $blocked['blocked_until']
            ];
        }

        // Count recent attempts
        $count = $this->db->getValue("
            SELECT COUNT(*) 
            FROM login_attempts 
            WHERE (identifier = ? OR ip_address = ?) 
              AND tenant_id = ?
              AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
              AND deleted_at IS NULL
        ", [$identifier, $ip, $tenantId, self::RATE_LIMIT_WINDOW]);

        $remaining = max(0, self::RATE_LIMIT_LOGIN - $count);

        return [
            'allowed' => $count < self::RATE_LIMIT_LOGIN,
            'remaining' => $remaining,
            'blocked_until' => null
        ];
    }

    /**
     * Record login attempt
     *
     * @param string $identifier
     * @param string $ip
     * @param bool $success
     * @param string|null $reason
     */
    public function recordLoginAttempt(string $identifier, string $ip, bool $success, ?string $reason = null): void
    {
        $tenantId = $this->tenantContext->getTenantId() ?? 0;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        try {
            $this->db->execute("
                INSERT INTO login_attempts (
                    tenant_id,
                    identifier,
                    ip_address,
                    user_agent,
                    success,
                    reason,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())
            ", [$tenantId, $identifier, $ip, $userAgent, $success ? 1 : 0, $reason]);

            // If failed, check if we need to block
            if (!$success) {
                $this->checkAndBlock($identifier, $ip, $tenantId);
            }
        } catch (Exception $e) {
            error_log('Failed to record login attempt: ' . $e->getMessage());
        }
    }

    /**
     * Check and block if too many failed attempts
     */
    private function checkAndBlock(string $identifier, string $ip, int $tenantId): void
    {
        $failedCount = $this->db->getValue("
            SELECT COUNT(*) 
            FROM login_attempts 
            WHERE (identifier = ? OR ip_address = ?) 
              AND tenant_id = ?
              AND success = 0
              AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
              AND deleted_at IS NULL
        ", [$identifier, $ip, $tenantId, self::RATE_LIMIT_WINDOW]);

        if ($failedCount >= self::RATE_LIMIT_LOGIN) {
            $this->db->execute("
                INSERT INTO login_attempts (
                    tenant_id,
                    identifier,
                    ip_address,
                    blocked_until,
                    created_at
                ) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())
            ", [$tenantId, $identifier, $ip, self::RATE_LIMIT_BLOCK]);
        }
    }

    /**
     * Generate CSRF token
     *
     * @return string
     */
    public function generateCSRFToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time();
        return $token;
    }

    /**
     * Validate CSRF token
     *
     * @param string $token
     * @return bool
     */
    public function validateCSRFToken(string $token): bool
    {
        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
            return false;
        }

        // Token expires after 1 hour
        if (time() - $_SESSION['csrf_token_time'] > 3600) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Generate secure random token
     *
     * @param int $length
     * @return string
     */
    public function generateSecureToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Hash data securely
     *
     * @param string $data
     * @return string
     */
    public function hashData(string $data): string
    {
        return password_hash($data, PASSWORD_DEFAULT);
    }

    /**
     * Verify hashed data
     *
     * @param string $data
     * @param string $hash
     * @return bool
     */
    public function verifyHash(string $data, string $hash): bool
    {
        return password_verify($data, $hash);
    }

    /**
     * Sanitize input
     *
     * @param string $input
     * @param string $type
     * @return string
     */
    public function sanitizeInput(string $input, string $type = 'text'): string
    {
        $input = trim($input);

        switch ($type) {
            case 'email':
                return filter_var($input, FILTER_SANITIZE_EMAIL);
            case 'url':
                return filter_var($input, FILTER_SANITIZE_URL);
            case 'int':
                return filter_var($input, FILTER_SANITIZE_NUMBER_INT);
            case 'text':
            default:
                return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        }
    }

    /**
     * Validate input
     *
     * @param string $input
     * @param string $type
     * @return bool
     */
    public function validateInput(string $input, string $type = 'text'): bool
    {
        switch ($type) {
            case 'email':
                return filter_var($input, FILTER_VALIDATE_EMAIL) !== false;
            case 'url':
                return filter_var($input, FILTER_VALIDATE_URL) !== false;
            case 'int':
                return filter_var($input, FILTER_VALIDATE_INT) !== false;
            case 'hostname':
                return preg_match('/^[a-zA-Z0-9\-\.]+$/', $input) === 1;
            case 'text':
            default:
                return !empty($input) && strlen($input) <= 65535;
        }
    }

    /**
     * Get security headers
     *
     * @return array
     */
    public function getSecurityHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'Content-Security-Policy' => $this->getCSPHeader()
        ];
    }

    /**
     * Get CSP header
     *
     * @return string
     */
    private function getCSPHeader(): string
    {
        $csp = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com",
            "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com",
            "img-src 'self' data: https://*",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'"
        ];

        return implode('; ', $csp);
    }

    /**
     * Apply security headers
     */
    public function applySecurityHeaders(): void
    {
        $headers = $this->getSecurityHeaders();
        foreach ($headers as $name => $value) {
            header("$name: $value");
        }
    }

    /**
     * Check if request is from a secure connection
     *
     * @return bool
     */
    public function isSecureConnection(): bool
    {
        return (
            isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ||
            isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ||
            isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on'
        );
    }

    /**
     * Get client IP address
     *
     * @return string
     */
    public function getClientIP(): string
    {
        $ipHeaders = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($ipHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Check if IP is blocked
     *
     * @param string $ip
     * @return bool
     */
    public function isIPBlocked(string $ip): bool
    {
        $blocked = $this->db->getValue("
            SELECT COUNT(*) 
            FROM blocked_ips 
            WHERE ip_address = ? 
              AND (expires_at IS NULL OR expires_at > NOW())
              AND deleted_at IS NULL
        ", [$ip]);

        return $blocked > 0;
    }

    /**
     * Block an IP address
     *
     * @param string $ip
     * @param int $duration Seconds to block (0 = permanent)
     * @param string $reason
     */
    public function blockIP(string $ip, int $duration = 0, string $reason = ''): void
    {
        $expiresAt = $duration > 0 ? date('Y-m-d H:i:s', time() + $duration) : null;

        $this->db->execute("
            INSERT INTO blocked_ips (ip_address, reason, expires_at, created_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                reason = VALUES(reason),
                expires_at = VALUES(expires_at),
                updated_at = NOW()
        ", [$ip, $reason, $expiresAt]);
    }

    /**
     * Unblock an IP address
     *
     * @param string $ip
     */
    public function unblockIP(string $ip): void
    {
        $this->db->execute("
            UPDATE blocked_ips 
            SET deleted_at = NOW() 
            WHERE ip_address = ?
        ", [$ip]);
    }
}