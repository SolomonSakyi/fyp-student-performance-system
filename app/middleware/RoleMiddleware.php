<?php
/**
 * RoleMiddleware.php
 *
 * Role-Based Access Control Middleware
 *
 * @package EduTrack
 * @subpackage Middleware
 * @version 1.0
 */

// Get project root
$projectRoot = dirname(__DIR__, 2) . '/';

require_once $projectRoot . 'app/middleware/AuthMiddleware.php';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class RoleMiddleware
{
    private $db;
    private $logger;
    private $auth;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->auth = new AuthMiddleware();
    }

    public function hasRole(int $userId, string $roleCode): bool
    {
        try {
            $sql = "SELECT COUNT(*) as count 
                    FROM platform_roles pr
                    JOIN platform_user_roles pur ON pr.id = pur.role_id
                    WHERE pur.user_id = ? AND pr.role_code = ? AND pr.is_active = 1";
            $result = $this->db->fetchOne($sql, [$userId, $roleCode]);
            return ($result['count'] ?? 0) > 0;
        } catch (Exception $e) {
            $this->logger->error('hasRole error: ' . $e->getMessage());
            return false;
        }
    }

    public function requirePlatformSuperAdmin(): bool
    {
        if (!$this->auth->handle()) {
            return false;
        }

        $userId = $this->auth->getCurrentUserId();
        if (!$userId) {
            return false;
        }

        return $this->hasRole($userId, 'platform_super_admin') || 
               $this->hasRole($userId, 'platform_owner');
    }

    public function requirePlatformAdmin(): bool
    {
        if (!$this->auth->handle()) {
            return false;
        }

        $userId = $this->auth->getCurrentUserId();
        if (!$userId) {
            return false;
        }

        return $this->hasRole($userId, 'platform_admin') || 
               $this->hasRole($userId, 'platform_super_admin') || 
               $this->hasRole($userId, 'platform_owner');
    }

    public function requirePlatformFinance(): bool
    {
        if (!$this->auth->handle()) {
            return false;
        }

        $userId = $this->auth->getCurrentUserId();
        if (!$userId) {
            return false;
        }

        return $this->hasRole($userId, 'platform_finance') || 
               $this->hasRole($userId, 'platform_super_admin') || 
               $this->hasRole($userId, 'platform_owner');
    }

    public function requirePlatformSupport(): bool
    {
        if (!$this->auth->handle()) {
            return false;
        }

        $userId = $this->auth->getCurrentUserId();
        if (!$userId) {
            return false;
        }

        return $this->hasRole($userId, 'platform_support') || 
               $this->hasRole($userId, 'platform_super_admin') || 
               $this->hasRole($userId, 'platform_owner');
    }

    public function requirePlatformSecurity(): bool
    {
        if (!$this->auth->handle()) {
            return false;
        }

        $userId = $this->auth->getCurrentUserId();
        if (!$userId) {
            return false;
        }

        return $this->hasRole($userId, 'platform_security') || 
               $this->hasRole($userId, 'platform_super_admin') || 
               $this->hasRole($userId, 'platform_owner');
    }

    public function requirePlatformAuditor(): bool
    {
        if (!$this->auth->handle()) {
            return false;
        }

        $userId = $this->auth->getCurrentUserId();
        if (!$userId) {
            return false;
        }

        return $this->hasRole($userId, 'platform_auditor') || 
               $this->hasRole($userId, 'platform_super_admin') || 
               $this->hasRole($userId, 'platform_owner');
    }

    public function requirePlatformOwner(): bool
    {
        if (!$this->auth->handle()) {
            return false;
        }

        $userId = $this->auth->getCurrentUserId();
        if (!$userId) {
            return false;
        }

        return $this->hasRole($userId, 'platform_owner');
    }
}