<?php

/**
 * Session Manager
 * Handles secure session creation, validation, and management
 *
 * @package EduTrack
 * @subpackage Services\Auth
 * @version 1.0
 * @filepath app/services/Auth/SessionManager.php
 */

class SessionManager
{
    private $db;
    private $tenantContext;
    private $sessionId = null;
    private $sessionData = null;

    // Session configuration
    private const SESSION_LIFETIME = 86400; // 24 hours
    private const IDLE_TIMEOUT = 1800; // 30 minutes
    private const REMEMBER_ME_LIFETIME = 604800; // 7 days

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->tenantContext = TenantContext::getInstance();

        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->sessionId = session_id();
    }

    /**
     * Create a new session for authenticated user
     *
     * @param array $user User data from authentication
     * @param bool $rememberMe Whether to extend session lifetime
     * @return array Session data
     */
    public function createSession(array $user, bool $rememberMe = false): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $schoolId = $this->tenantContext->getSchoolId();
        $campusId = $this->tenantContext->getCampusId();
        $domainId = $this->tenantContext->getDomainId();

        // Generate secure session token
        $sessionToken = $this->generateSecureToken();
        $expiresAt = $rememberMe ? self::REMEMBER_ME_LIFETIME : self::SESSION_LIFETIME;

        // Insert session record
        $sessionId = $this->db->insert("
            INSERT INTO user_sessions (
                user_id,
                session_token,
                session_id,
                tenant_id,
                school_id,
                campus_id,
                domain_id,
                login_audience,
                ip_address,
                user_agent,
                expires_at,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())
        ", [
            $user['user_id'],
            $sessionToken,
            $this->sessionId,
            $tenantId,
            $schoolId,
            $campusId,
            $domainId,
            $user['login_audience'] ?? 'staff',
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            $expiresAt
        ]);

        // Build session data
        $sessionData = [
            'session_id' => $sessionId,
            'session_token' => $sessionToken,
            'user_id' => $user['user_id'],
            'person_id' => $user['person_id'] ?? null,
            'username' => $user['username'] ?? '',
            'first_name' => $user['first_name'] ?? '',
            'last_name' => $user['last_name'] ?? '',
            'full_name' => $user['full_name'] ?? '',
            'email' => $user['email'] ?? '',
            'tenant_id' => $tenantId,
            'tenant_name' => $this->tenantContext->getTenantName(),
            'school_id' => $schoolId,
            'school_name' => $this->tenantContext->getSchoolName(),
            'campus_id' => $campusId,
            'campus_name' => $this->tenantContext->getCampusName(),
            'domain_id' => $domainId,
            'domain_name' => $this->tenantContext->getDomainName(),
            'login_audience' => $user['login_audience'] ?? 'staff',
            'roles' => $user['roles'] ?? [],
            'permissions' => $user['permissions'] ?? [],
            'admin_level' => $user['admin_level'] ?? 'none',
            'is_active' => $user['is_active'] ?? true,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + $expiresAt),
            'last_activity' => date('Y-m-d H:i:s')
        ];

        // Store in PHP session
        $this->storeSessionData($sessionData);

        // Store session token in cookie for validation
        $this->setSessionCookie($sessionToken, $rememberMe);

        $this->sessionData = $sessionData;

        return $sessionData;
    }

    /**
     * Validate current session
     *
     * @return bool
     */
    public function validateSession(): bool
    {
        // Check PHP session
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        // Check if session data exists
        if (!isset($_SESSION['auth_session'])) {
            return false;
        }

        $sessionData = $_SESSION['auth_session'];

        // Check if session has expired
        if (isset($sessionData['expires_at']) && strtotime($sessionData['expires_at']) < time()) {
            $this->destroySession();
            return false;
        }

        // Check idle timeout
        if (isset($sessionData['last_activity']) && (time() - strtotime($sessionData['last_activity'])) > self::IDLE_TIMEOUT) {
            $this->destroySession();
            return false;
        }

        // Validate session token against database
        if (isset($sessionData['session_token'])) {
            $valid = $this->validateSessionToken(
                $sessionData['user_id'],
                $sessionData['session_token']
            );

            if (!$valid) {
                $this->destroySession();
                return false;
            }
        }

        // Update last activity
        $this->updateLastActivity();

        return true;
    }

    /**
     * Validate session token against database
     */
    private function validateSessionToken(int $userId, string $token): bool
    {
        $session = $this->db->fetchOne("
            SELECT id, expires_at 
            FROM user_sessions 
            WHERE user_id = ? 
              AND session_token = ? 
              AND deleted_at IS NULL
        ", [$userId, $token]);

        if (!$session) {
            return false;
        }

        // Check if expired
        if (strtotime($session['expires_at']) < time()) {
            $this->db->execute(
                "UPDATE user_sessions SET deleted_at = NOW() WHERE id = ?",
                [$session['id']]
            );
            return false;
        }

        return true;
    }

    /**
     * Store session data in PHP session
     */
    private function storeSessionData(array $data): void
    {
        $_SESSION['auth_session'] = [
            'session_id' => $data['session_id'],
            'session_token' => $data['session_token'],
            'user_id' => $data['user_id'],
            'person_id' => $data['person_id'],
            'username' => $data['username'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'full_name' => $data['full_name'],
            'email' => $data['email'],
            'tenant_id' => $data['tenant_id'],
            'tenant_name' => $data['tenant_name'],
            'school_id' => $data['school_id'],
            'school_name' => $data['school_name'],
            'campus_id' => $data['campus_id'],
            'campus_name' => $data['campus_name'],
            'domain_id' => $data['domain_id'],
            'domain_name' => $data['domain_name'],
            'login_audience' => $data['login_audience'],
            'roles' => $data['roles'],
            'permissions' => $data['permissions'],
            'admin_level' => $data['admin_level'],
            'is_active' => $data['is_active'],
            'expires_at' => $data['expires_at'],
            'last_activity' => $data['last_activity']
        ];

        // Also store basic session info for quick access
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = $data['user_id'];
        $_SESSION['person_id'] = $data['person_id'];
        $_SESSION['user_name'] = $data['full_name'] ?? $data['username'];
        $_SESSION['first_name'] = $data['first_name'];
        $_SESSION['last_name'] = $data['last_name'];
        $_SESSION['username'] = $data['username'];
        $_SESSION['email'] = $data['email'];
        $_SESSION['tenant_id'] = $data['tenant_id'];
        $_SESSION['tenant_name'] = $data['tenant_name'];
        $_SESSION['school_id'] = $data['school_id'];
        $_SESSION['school_name'] = $data['school_name'];
        $_SESSION['campus_id'] = $data['campus_id'];
        $_SESSION['campus_name'] = $data['campus_name'];
        $_SESSION['login_audience'] = $data['login_audience'];
        $_SESSION['user_roles'] = $data['roles'];
        $_SESSION['user_permissions'] = $data['permissions'];
        $_SESSION['admin_level'] = $data['admin_level'];
        $_SESSION['session_expires_at'] = $data['expires_at'];
        $_SESSION['session_last_activity'] = $data['last_activity'];
    }

    /**
     * Set session cookie
     */
    private function setSessionCookie(string $token, bool $rememberMe): void
    {
        $lifetime = $rememberMe ? self::REMEMBER_ME_LIFETIME : self::SESSION_LIFETIME;
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        $domain = $_SERVER['HTTP_HOST'] ?? '';

        // Remove port if present
        $domain = preg_replace('/:\d+$/', '', $domain);

        // Set cookie with secure flags
        setcookie(
            'edutrack_session',
            $token,
            [
                'expires' => time() + $lifetime,
                'path' => '/',
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
    }

    /**
     * Update last activity timestamp
     */
    public function updateLastActivity(): void
    {
        if (isset($_SESSION['auth_session'])) {
            $_SESSION['auth_session']['last_activity'] = date('Y-m-d H:i:s');
            $_SESSION['session_last_activity'] = date('Y-m-d H:i:s');

            // Update in database
            if (isset($_SESSION['auth_session']['session_id'])) {
                $this->db->execute(
                    "UPDATE user_sessions SET last_activity = NOW() WHERE id = ?",
                    [$_SESSION['auth_session']['session_id']]
                );
            }
        }
    }

    /**
     * Destroy current session
     */
    public function destroySession(): void
    {
        // Clear session data
        $_SESSION = [];

        // Clear session cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        // Clear custom session cookie
        setcookie('edutrack_session', '', time() - 42000, '/');

        // Mark session as deleted in database
        if (isset($_SESSION['auth_session']['session_id'])) {
            try {
                $this->db->execute(
                    "UPDATE user_sessions SET deleted_at = NOW() WHERE id = ?",
                    [$_SESSION['auth_session']['session_id']]
                );
            } catch (Exception $e) {
                // Ignore database errors during logout
            }
        }

        // Destroy session
        session_destroy();

        $this->sessionData = null;
        $this->sessionId = null;
    }

    /**
     * Get current session data
     */
    public function getSessionData(): ?array
    {
        if ($this->sessionData) {
            return $this->sessionData;
        }

        if (isset($_SESSION['auth_session'])) {
            return $_SESSION['auth_session'];
        }

        return null;
    }

    /**
     * Get session ID
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Generate secure token
     */
    private function generateSecureToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Check if session is valid and user is authenticated
     */
    public function isAuthenticated(): bool
    {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }

    /**
     * Get user ID from session
     */
    public function getUserId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get tenant ID from session
     */
    public function getTenantId(): ?int
    {
        return $_SESSION['tenant_id'] ?? null;
    }

    /**
     * Get school ID from session
     */
    public function getSchoolId(): ?int
    {
        return $_SESSION['school_id'] ?? null;
    }

    /**
     * Get login audience from session
     */
    public function getLoginAudience(): ?string
    {
        return $_SESSION['login_audience'] ?? null;
    }

    /**
     * Regenerate session ID to prevent fixation
     */
    public function regenerateSessionId(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $this->sessionId = session_id();

            // Update session record
            if (isset($_SESSION['auth_session']['session_id'])) {
                $this->db->execute(
                    "UPDATE user_sessions SET session_id = ? WHERE id = ?",
                    [$this->sessionId, $_SESSION['auth_session']['session_id']]
                );
            }
        }
    }

    /**
     * Get session expiration time
     */
    public function getExpirationTime(): ?int
    {
        if (isset($_SESSION['session_expires_at'])) {
            return strtotime($_SESSION['session_expires_at']);
        }
        return null;
    }

    /**
     * Get remaining session time in seconds
     */
    public function getRemainingTime(): ?int
    {
        $expires = $this->getExpirationTime();
        if ($expires) {
            $remaining = $expires - time();
            return $remaining > 0 ? $remaining : 0;
        }
        return null;
    }

    /**
     * Extend session lifetime
     */
    public function extendSession(int $seconds = null): bool
    {
        $seconds = $seconds ?? self::SESSION_LIFETIME;

        if (!$this->isAuthenticated() || !isset($_SESSION['auth_session']['session_id'])) {
            return false;
        }

        try {
            $this->db->execute(
                "UPDATE user_sessions SET expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?",
                [$seconds, $_SESSION['auth_session']['session_id']]
            );

            $_SESSION['auth_session']['expires_at'] = date('Y-m-d H:i:s', time() + $seconds);
            $_SESSION['session_expires_at'] = date('Y-m-d H:i:s', time() + $seconds);

            return true;
        } catch (Exception $e) {
            error_log('Error extending session: ' . $e->getMessage());
            return false;
        }
    }
}
