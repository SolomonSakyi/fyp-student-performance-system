<?php

/**
 * AuthService.php
 * Authentication Service for the platform
 * 
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 1.0
 * 
 * @filepath app/services/Platform/AuthService.php
 */

require_once dirname(__DIR__, 3) . '/app/helpers/DatabaseHelper.php';

class AuthService
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Login user with email and password
     */
    public function login(string $email, string $password): array
    {
        $user = $this->db->fetchOne(
            "SELECT * FROM platform_users WHERE email = ? AND is_active = 1",
            [$email]
        );

        if (!$user) {
            return ['success' => false, 'message' => 'Invalid credentials'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Invalid credentials'];
        }

        // Get user roles
        $roles = $this->db->fetchAll(
            "SELECT r.role_code 
             FROM platform_user_roles pur
             JOIN platform_roles r ON pur.role_id = r.id
             WHERE pur.user_id = ?",
            [$user['id']]
        );

        $roleCodes = array_column($roles, 'role_code');

        // Set session - with null checks for undefined keys
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['tenant_id'] = isset($user['tenant_id']) && $user['tenant_id'] ? (int)$user['tenant_id'] : null;
        $_SESSION['school_id'] = isset($user['school_id']) && $user['school_id'] ? (int)$user['school_id'] : null;
        $_SESSION['roles'] = $roleCodes;

        $_SESSION['tenant_context'] = [
            'tenant_id' => isset($user['tenant_id']) && $user['tenant_id'] ? (int)$user['tenant_id'] : null,
            'school_id' => isset($user['school_id']) && $user['school_id'] ? (int)$user['school_id'] : null,
            'user_id' => (int)$user['id'],
            'roles' => $roleCodes,
        ];

        $this->db->query(
            "UPDATE platform_users SET last_login = NOW() WHERE id = ?",
            [$user['id']]
        );

        return [
            'success' => true,
            'message' => 'Login successful',
            'user' => [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'username' => $user['username'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'tenant_id' => isset($user['tenant_id']) && $user['tenant_id'] ? (int)$user['tenant_id'] : null,
                'roles' => $roleCodes
            ]
        ];
    }

    public function logout(): array
    {
        session_destroy();
        return ['success' => true, 'message' => 'Logout successful'];
    }

    public function isAuthenticated(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public function getCurrentUser(): ?array
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        $user = $this->db->fetchOne(
            "SELECT * FROM platform_users WHERE id = ? AND is_active = 1",
            [$_SESSION['user_id']]
        );

        if (!$user) {
            return null;
        }

        unset($user['password_hash']);
        return $user;
    }
}
