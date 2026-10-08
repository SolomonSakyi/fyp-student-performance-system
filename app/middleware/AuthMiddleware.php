<?php

/**
 * AuthMiddleware.php
 *
 * Authentication Middleware for Platform API
 *
 * @package EduTrack
 * @subpackage Middleware
 * @version 2.0
 */

// Get project root path
$projectRoot = dirname(__DIR__, 2) . '/';

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/JwtHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/models/Platform/PlatformUser.php';
require_once $projectRoot . 'app/models/Platform/PlatformSession.php';

class AuthMiddleware
{
    private $db;
    private $logger;
    private $jwt;
    private $currentUserId = null;
    private $currentUser = null;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->jwt = new JwtHelper();
    }

    /**
     * Handle authentication
     */
    public function handle(): bool
    {
        try {
            // Get Authorization header
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            
            if (empty($authHeader)) {
                $this->logger->debug('No Authorization header found');
                return false;
            }

            // Extract token
            if (strpos($authHeader, 'Bearer ') !== 0) {
                $this->logger->debug('Invalid Authorization header format');
                return false;
            }

            $token = substr($authHeader, 7);
            
            if (empty($token)) {
                $this->logger->debug('Empty token');
                return false;
            }

            // Validate JWT token
            $payload = $this->jwt->validateToken($token);
            
            if (!$payload || !isset($payload['user_id'])) {
                $this->logger->debug('Invalid JWT token');
                return false;
            }

            $userId = (int)$payload['user_id'];

            // Verify session exists in database
            $sessionModel = new PlatformSession();
            $session = $sessionModel->findByToken($token);

            if (!$session) {
                $this->logger->debug('Session not found for token');
                return false;
            }

            // Check if session is expired
            if ($session['expiry_time'] && strtotime($session['expiry_time']) < time()) {
                $this->logger->debug('Session expired');
                return false;
            }

            // Get user
            $userModel = new PlatformUser();
            $user = $userModel->find($userId);

            if (!$user || !$user['is_active']) {
                $this->logger->debug('User not found or inactive');
                return false;
            }

            // Check if user is locked
            if ($user['is_locked']) {
                if ($user['lockout_until'] && strtotime($user['lockout_until']) > time()) {
                    $this->logger->debug('User is locked');
                    return false;
                } else {
                    // Unlock if lockout has expired
                    $userModel->unlock($userId);
                }
            }

            // Set current user
            $this->currentUserId = $userId;
            $this->currentUser = $user;

            return true;

        } catch (Exception $e) {
            $this->logger->error('AuthMiddleware error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get current user ID
     */
    public function getCurrentUserId(): ?int
    {
        return $this->currentUserId;
    }

    /**
     * Get current user
     */
    public function getCurrentUser(): ?array
    {
        return $this->currentUser;
    }

    /**
     * Check if user is authenticated
     */
    public function isAuthenticated(): bool
    {
        return $this->currentUserId !== null;
    }

    /**
     * Check if user has role
     */
    public function hasRole(string $roleCode): bool
    {
        if (!$this->currentUserId) {
            return false;
        }

        $userModel = new PlatformUser();
        return $userModel->hasRole($this->currentUserId, $roleCode);
    }

    /**
     * Check if user has permission
     */
    public function hasPermission(string $permissionCode): bool
    {
        if (!$this->currentUserId) {
            return false;
        }

        $userModel = new PlatformUser();
        return $userModel->hasPermission($this->currentUserId, $permissionCode);
    }

    /**
     * Require authentication (returns JSON response if failed)
     */
    public function requireAuth(): bool
    {
        if (!$this->handle()) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Authentication required',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            return false;
        }
        return true;
    }

    /**
     * Require specific role
     */
    public function requireRole(string $roleCode): bool
    {
        if (!$this->requireAuth()) {
            return false;
        }

        if (!$this->hasRole($roleCode)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Insufficient permissions. Required role: ' . $roleCode,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            return false;
        }

        return true;
    }

    /**
     * Require specific permission
     */
    public function requirePermission(string $permissionCode): bool
    {
        if (!$this->requireAuth()) {
            return false;
        }

        if (!$this->hasPermission($permissionCode)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Insufficient permissions. Required permission: ' . $permissionCode,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            return false;
        }

        return true;
    }
}