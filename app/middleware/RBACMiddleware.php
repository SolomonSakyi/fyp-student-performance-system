<?php

/**
 * RBAC Middleware
 * Enforces Role-Based Access Control on routes
 *
 * @package EduTrack
 * @subpackage Middleware
 * @version 1.0
 * @filepath app/middleware/RBACMiddleware.php
 */

class RBACMiddleware
{
    private $rbacService;
    private $tenantContext;

    public function __construct()
    {
        $this->rbacService = new RBACService();
        $this->tenantContext = TenantContext::getInstance();
    }

    /**
     * Require a specific role for the current route
     *
     * @param string|array $roles Single role or array of roles
     * @return bool
     */
    public function requireRole($roles): bool
    {
        $userId = $this->tenantContext->getUserId();

        if (!$userId) {
            $this->denyAccess('User not authenticated');
            return false;
        }

        $roles = is_array($roles) ? $roles : [$roles];

        foreach ($roles as $role) {
            if ($this->rbacService->hasRole($userId, $role)) {
                return true;
            }
        }

        $this->denyAccess('Insufficient role privileges');
        return false;
    }

    /**
     * Require a specific permission for the current route
     *
     * @param string|array $permissions Single permission or array of permissions
     * @return bool
     */
    public function requirePermission($permissions): bool
    {
        $userId = $this->tenantContext->getUserId();

        if (!$userId) {
            $this->denyAccess('User not authenticated');
            return false;
        }

        $permissions = is_array($permissions) ? $permissions : [$permissions];

        foreach ($permissions as $permission) {
            if ($this->rbacService->hasPermission($userId, $permission)) {
                return true;
            }
        }

        $this->denyAccess('Insufficient permission privileges');
        return false;
    }

    /**
     * Require access to a specific entity
     *
     * @param string $entityType
     * @param int $entityId
     * @return bool
     */
    public function requireEntityAccess(string $entityType, int $entityId): bool
    {
        $userId = $this->tenantContext->getUserId();

        if (!$userId) {
            $this->denyAccess('User not authenticated');
            return false;
        }

        if (!$this->rbacService->hasEntityAccess($userId, $entityType, $entityId)) {
            $this->denyAccess('Insufficient scope privileges');
            return false;
        }

        return true;
    }

    /**
     * Require admin access
     *
     * @return bool
     */
    public function requireAdmin(): bool
    {
        $userId = $this->tenantContext->getUserId();

        if (!$userId) {
            $this->denyAccess('User not authenticated');
            return false;
        }

        if (!$this->rbacService->isAdmin($userId)) {
            $this->denyAccess('Admin privileges required');
            return false;
        }

        return true;
    }

    /**
     * Require super admin access
     *
     * @return bool
     */
    public function requireSuperAdmin(): bool
    {
        $userId = $this->tenantContext->getUserId();

        if (!$userId) {
            $this->denyAccess('User not authenticated');
            return false;
        }

        if (!$this->rbacService->hasRole($userId, RBACService::ROLE_SUPER_ADMIN)) {
            $this->denyAccess('Super admin privileges required');
            return false;
        }

        return true;
    }

    /**
     * Require tenant admin access
     *
     * @return bool
     */
    public function requireTenantAdmin(): bool
    {
        $userId = $this->tenantContext->getUserId();
        $tenantId = $this->tenantContext->getTenantId();

        if (!$userId || !$tenantId) {
            $this->denyAccess('Invalid context');
            return false;
        }

        $adminLevel = $this->rbacService->getAdminLevel($userId, $tenantId);
        if (!in_array($adminLevel, ['super_admin', 'tenant_admin'])) {
            $this->denyAccess('Tenant admin privileges required');
            return false;
        }

        return true;
    }

    /**
     * Deny access and handle error
     */
    private function denyAccess(string $message): void
    {
        error_log('RBAC Access Denied: ' . $message);

        // Log the denial
        $userId = $this->tenantContext->getUserId();
        if ($userId) {
            try {
                $this->logDenial($userId, $message);
            } catch (Exception $e) {
                // Ignore audit errors
            }
        }

        http_response_code(403);
        require_once __DIR__ . '/../public/errors/forbidden.php';
        exit;
    }

    /**
     * Log access denial for audit
     */
    private function logDenial(int $userId, string $message): void
    {
        $db = DatabaseHelper::getInstance();
        $db->execute("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, tenant_id, details, created_at)
            VALUES (?, 'access_denied', 'auth', ?, ?, ?, NOW())
        ", [
            $userId,
            $userId,
            $this->tenantContext->getTenantId(),
            json_encode([
                'message' => $message,
                'route' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ])
        ]);
    }

    /**
     * Get RBAC service instance
     */
    public function getRBACService(): RBACService
    {
        return $this->rbacService;
    }
}
