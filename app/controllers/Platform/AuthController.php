<?php

/**
 * AuthController.php
 * Authentication controller for platform users with JWT token support
 * 
 * @package EduTrack
 * @subpackage Controllers\Platform
 * @version 2.0
 * @filepath app/controllers/Platform/AuthController.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/services/Auth/JWTService.php';
require_once $projectRoot . 'app/services/Auth/AuthService.php';

class AuthController
{
    private $db;
    private $logger;
    private $jwtService;
    private $authService;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->jwtService = new JWTService();
        $this->authService = new AuthService();
    }

    /**
     * Get request data from JSON body
     */
    private function getRequestData(): array
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return [];
        }
        return json_decode($input, true) ?? [];
    }

    /**
     * JSON Response helper
     */
    private function jsonResponse($data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Authenticate user and return JWT token
     * POST /api/platform/index.php?endpoint=auth&action=login
     */
    public function login(): void
    {
        try {
            $data = $this->getRequestData();

            $this->logger->info('Login attempt', ['username' => $data['username'] ?? 'unknown']);

            // Validate required fields
            if (empty($data['username']) || empty($data['password'])) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Username and password are required'
                ], 400);
                return;
            }

            // Authenticate user
            $result = $this->authService->authenticate($data['username'], $data['password']);

            if (!$result['success']) {
                $this->logger->warning('Login failed', [
                    'username' => $data['username'],
                    'reason' => $result['message'] ?? 'Invalid credentials'
                ]);
                $this->jsonResponse([
                    'success' => false,
                    'message' => $result['message'] ?? 'Invalid credentials'
                ], 401);
                return;
            }

            $user = $result['user'];

            // Ensure roles is an array
            $roles = $user['roles'] ?? ['tenant_user'];
            if (!is_array($roles)) {
                $roles = [$roles];
            }

            // Make sure admin has proper roles
            if ($user['username'] === 'admin' || $user['email'] === 'admin@edutrack.com' || $user['id'] == 1) {
                if (!in_array('platform_super_admin', $roles)) {
                    $roles[] = 'platform_super_admin';
                }
                if (!in_array('platform_admin', $roles)) {
                    $roles[] = 'platform_admin';
                }
                if (!in_array('admin', $roles)) {
                    $roles[] = 'admin';
                }
            }

            // Set session variables
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['tenant_id'] = $user['tenant_id'] ?? 1;
            $_SESSION['user_roles'] = $roles;
            $_SESSION['is_platform_admin'] = true;

            // Debug logging
            error_log('Login successful - Session set:');
            error_log('  user_id: ' . $_SESSION['user_id']);
            error_log('  username: ' . $_SESSION['username']);
            error_log('  tenant_id: ' . $_SESSION['tenant_id']);
            error_log('  user_roles: ' . json_encode($_SESSION['user_roles']));
            error_log('  is_platform_admin: ' . ($_SESSION['is_platform_admin'] ? 'true' : 'false'));

            // Generate JWT token
            $token = $this->jwtService->generateToken([
                'user_id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'tenant_id' => $user['tenant_id'] ?? null,
                'roles' => $roles
            ]);

            $_SESSION['auth_token'] = $token;

            // Log the successful login
            $this->logger->info('Login successful', [
                'user_id' => $user['id'],
                'username' => $user['username']
            ]);

            // Remove sensitive data before sending response
            unset($user['password_hash']);
            unset($user['password']);

            $this->jsonResponse([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'token' => $token,
                    'user' => $user,
                    'tenant_id' => $user['tenant_id'] ?? 1,
                    'roles' => $roles
                ]
            ]);
        } catch (Exception $e) {
            $this->logger->error('Login error: ' . $e->getMessage());
            $this->jsonResponse([
                'success' => false,
                'message' => 'Login error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Logout user
     * POST /api/platform/index.php?endpoint=auth&action=logout
     */
    public function logout(): void
    {
        try {
            // Clear session
            $_SESSION = [];
            session_destroy();

            $this->logger->info('Logout successful');

            $this->jsonResponse([
                'success' => true,
                'message' => 'Logout successful'
            ]);
        } catch (Exception $e) {
            $this->logger->error('Logout error: ' . $e->getMessage());
            $this->jsonResponse([
                'success' => false,
                'message' => 'Logout error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Refresh JWT token
     * POST /api/platform/index.php?endpoint=auth&action=refresh
     */
    public function refreshToken(): void
    {
        try {
            $token = $_SESSION['auth_token'] ?? '';

            if (empty($token)) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'No token provided'
                ], 401);
                return;
            }

            // Validate existing token
            $payload = $this->jwtService->validateToken($token);

            if (!$payload || !isset($payload['data']['user_id'])) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Invalid or expired token'
                ], 401);
                return;
            }

            $userId = $payload['data']['user_id'];

            // Get user data
            $user = $this->db->fetchOne(
                "SELECT u.*, GROUP_CONCAT(pr.role_code) as role_codes 
                 FROM platform_users u
                 LEFT JOIN platform_user_roles pur ON u.id = pur.user_id
                 LEFT JOIN platform_roles pr ON pur.role_id = pr.id
                 WHERE u.id = ? AND u.deleted_at IS NULL
                 GROUP BY u.id",
                [$userId]
            );

            if (!$user) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
                return;
            }

            $roles = !empty($user['role_codes']) ? explode(',', $user['role_codes']) : ['tenant_user'];
            unset($user['role_codes']);
            unset($user['password_hash']);

            // Generate new token
            $newToken = $this->jwtService->generateToken([
                'user_id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'tenant_id' => $user['tenant_id'] ?? null,
                'roles' => $roles
            ]);

            $_SESSION['auth_token'] = $newToken;

            $this->jsonResponse([
                'success' => true,
                'data' => [
                    'token' => $newToken,
                    'user' => $user
                ]
            ]);
        } catch (Exception $e) {
            $this->logger->error('Refresh token error: ' . $e->getMessage());
            $this->jsonResponse([
                'success' => false,
                'message' => 'Refresh token error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get current authenticated user
     * GET /api/platform/index.php?endpoint=auth&action=me
     */
    public function getCurrentUser(): void
    {
        try {
            $userId = $_SESSION['user_id'] ?? 0;

            if (!$userId) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Not authenticated'
                ], 401);
                return;
            }

            $user = $this->db->fetchOne(
                "SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.phone, 
                        u.tenant_id, u.is_active, u.created_at,
                        COALESCE(t.tenant_name, t.legal_name) as tenant_name,
                        GROUP_CONCAT(pr.role_code) as role_codes
                 FROM platform_users u
                 LEFT JOIN tenants t ON u.tenant_id = t.id
                 LEFT JOIN platform_user_roles pur ON u.id = pur.user_id
                 LEFT JOIN platform_roles pr ON pur.role_id = pr.id
                 WHERE u.id = ? AND u.deleted_at IS NULL
                 GROUP BY u.id",
                [$userId]
            );

            if (!$user) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
                return;
            }

            $roles = !empty($user['role_codes']) ? explode(',', $user['role_codes']) : ['tenant_user'];
            $user['roles'] = $roles;
            unset($user['role_codes']);
            unset($user['password_hash']);

            $this->jsonResponse([
                'success' => true,
                'data' => $user
            ]);
        } catch (Exception $e) {
            $this->logger->error('Get current user error: ' . $e->getMessage());
            $this->jsonResponse([
                'success' => false,
                'message' => 'Error fetching user: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Change password
     * POST /api/platform/index.php?endpoint=auth&action=change-password
     */
    public function changePassword(): void
    {
        try {
            $userId = $_SESSION['user_id'] ?? 0;

            if (!$userId) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Not authenticated'
                ], 401);
                return;
            }

            $data = $this->getRequestData();

            if (empty($data['current_password']) || empty($data['new_password'])) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Current password and new password are required'
                ], 400);
                return;
            }

            if (strlen($data['new_password']) < 8) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'New password must be at least 8 characters'
                ], 400);
                return;
            }

            // Get user with password hash
            $user = $this->db->fetchOne(
                "SELECT id, password_hash FROM platform_users WHERE id = ? AND deleted_at IS NULL",
                [$userId]
            );

            if (!$user) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
                return;
            }

            // Verify current password
            if (!password_verify($data['current_password'], $user['password_hash'])) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ], 400);
                return;
            }

            // Update password
            $newHash = password_hash($data['new_password'], PASSWORD_DEFAULT);
            $this->db->execute(
                "UPDATE platform_users SET password_hash = ?, updated_at = NOW() WHERE id = ?",
                [$newHash, $userId]
            );

            $this->logger->info('Password changed', ['user_id' => $userId]);

            $this->jsonResponse([
                'success' => true,
                'message' => 'Password changed successfully'
            ]);
        } catch (Exception $e) {
            $this->logger->error('Change password error: ' . $e->getMessage());
            $this->jsonResponse([
                'success' => false,
                'message' => 'Error changing password: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validate JWT token
     */
    public function validateToken(string $token): ?array
    {
        return $this->jwtService->validateToken($token);
    }

    /**
     * Forgot password - send reset link
     * POST /api/platform/index.php?endpoint=auth&action=forgot-password
     */
    public function forgotPassword(): void
    {
        try {
            $data = $this->getRequestData();

            if (empty($data['email'])) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Email is required'
                ], 400);
                return;
            }

            $user = $this->db->fetchOne(
                "SELECT id, email FROM platform_users WHERE email = ? AND deleted_at IS NULL",
                [$data['email']]
            );

            if (!$user) {
                $this->jsonResponse([
                    'success' => true,
                    'message' => 'If your email is registered, you will receive a password reset link'
                ]);
                return;
            }

            // Generate reset token
            $resetToken = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Store reset token
            $this->db->execute(
                "INSERT INTO password_resets (email, token, expires_at, created_at) 
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE token = ?, expires_at = ?, created_at = NOW()",
                [$user['email'], $resetToken, $expiresAt, $resetToken, $expiresAt]
            );

            $this->logger->info('Password reset requested', ['email' => $user['email']]);

            $this->jsonResponse([
                'success' => true,
                'message' => 'If your email is registered, you will receive a password reset link'
            ]);
        } catch (Exception $e) {
            $this->logger->error('Forgot password error: ' . $e->getMessage());
            $this->jsonResponse([
                'success' => false,
                'message' => 'Error processing request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset password using token
     * POST /api/platform/index.php?endpoint=auth&action=reset-password
     */
    public function resetPassword(): void
    {
        try {
            $data = $this->getRequestData();

            if (empty($data['token']) || empty($data['password'])) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Token and new password are required'
                ], 400);
                return;
            }

            if (strlen($data['password']) < 8) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Password must be at least 8 characters'
                ], 400);
                return;
            }

            // Validate token
            $reset = $this->db->fetchOne(
                "SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW()",
                [$data['token']]
            );

            if (!$reset) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Invalid or expired reset token'
                ], 400);
                return;
            }

            // Update password
            $newHash = password_hash($data['password'], PASSWORD_DEFAULT);
            $this->db->execute(
                "UPDATE platform_users SET password_hash = ?, updated_at = NOW() WHERE email = ?",
                [$newHash, $reset['email']]
            );

            // Delete used token
            $this->db->execute(
                "DELETE FROM password_resets WHERE token = ?",
                [$data['token']]
            );

            $this->logger->info('Password reset successful', ['email' => $reset['email']]);

            $this->jsonResponse([
                'success' => true,
                'message' => 'Password reset successfully'
            ]);
        } catch (Exception $e) {
            $this->logger->error('Reset password error: ' . $e->getMessage());
            $this->jsonResponse([
                'success' => false,
                'message' => 'Error resetting password: ' . $e->getMessage()
            ], 500);
        }
    }
}
