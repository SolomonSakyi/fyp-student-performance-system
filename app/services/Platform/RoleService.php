<?php

/**
 * RoleService.php
 * Role Service for Platform Management
 * 
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 2.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/PlatformRole.php';
require_once dirname(__DIR__, 2) . '/services/Platform/BaseService.php';

class RoleService extends BaseService
{
    private $roleModel;

    public function __construct()
    {
        parent::__construct();
        $this->roleModel = new PlatformRole();
    }

    /**
     * Get all roles
     */
    public function getRoles(bool $onlyActive = true): array
    {
        try {
            $roles = $onlyActive ? $this->roleModel->getActive() : $this->roleModel->getAllRoles();
            return [
                'success' => true,
                'data' => $roles
            ];
        } catch (Exception $e) {
            $this->logger->error('getRoles error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get a single role
     */
    public function getRole(int $roleId): array
    {
        try {
            $role = $this->roleModel->find($roleId);
            if (!$role) {
                return ['success' => false, 'message' => 'Role not found'];
            }
            return ['success' => true, 'data' => $role];
        } catch (Exception $e) {
            $this->logger->error('getRole error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get role by code
     */
    public function getRoleByCode(string $code): array
    {
        try {
            $role = $this->roleModel->findByCode($code);
            if (!$role) {
                return ['success' => false, 'message' => 'Role not found'];
            }
            return ['success' => true, 'data' => $role];
        } catch (Exception $e) {
            $this->logger->error('getRoleByCode error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Create a new role
     */
    public function createRole(array $data): array
    {
        try {
            $this->beginTransaction();

            $required = ['name', 'code'];
            $validation = $this->validateRequired($data, $required);
            if ($validation) {
                $this->rollback();
                return ['success' => false, 'message' => $validation];
            }

            // Check if code exists
            $existing = $this->roleModel->findByCode($data['code']);
            if ($existing) {
                $this->rollback();
                return ['success' => false, 'message' => 'Role code already exists'];
            }

            if (!isset($data['is_active'])) {
                $data['is_active'] = 1;
            }

            $roleId = $this->roleModel->create($data);
            if (!$roleId) {
                $this->rollback();
                return ['success' => false, 'message' => 'Failed to create role'];
            }

            $this->logAudit([
                'user_id' => $data['created_by'] ?? null,
                'action_type' => 'ROLE_CREATED',
                'module' => 'Role',
                'resource' => 'role',
                'resource_id' => $roleId,
                'new_data' => json_encode($data)
            ]);

            $this->commit();

            return ['success' => true, 'message' => 'Role created successfully', 'data' => ['id' => $roleId]];
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('createRole error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update a role
     */
    public function updateRole(int $roleId, array $data): array
    {
        try {
            $this->beginTransaction();

            $role = $this->roleModel->find($roleId);
            if (!$role) {
                $this->rollback();
                return ['success' => false, 'message' => 'Role not found'];
            }

            if (isset($data['code']) && $data['code'] !== $role['code']) {
                $existing = $this->roleModel->findByCode($data['code']);
                if ($existing && $existing['id'] != $roleId) {
                    $this->rollback();
                    return ['success' => false, 'message' => 'Role code already exists'];
                }
            }

            $result = $this->roleModel->update($roleId, $data);
            if (!$result) {
                $this->rollback();
                return ['success' => false, 'message' => 'Failed to update role'];
            }

            $this->logAudit([
                'user_id' => $data['updated_by'] ?? null,
                'action_type' => 'ROLE_UPDATED',
                'module' => 'Role',
                'resource' => 'role',
                'resource_id' => $roleId,
                'old_data' => json_encode($role),
                'new_data' => json_encode($data)
            ]);

            $this->commit();

            return ['success' => true, 'message' => 'Role updated successfully'];
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('updateRole error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete a role
     */
    public function deleteRole(int $roleId): array
    {
        try {
            $this->beginTransaction();

            $role = $this->roleModel->find($roleId);
            if (!$role) {
                $this->rollback();
                return ['success' => false, 'message' => 'Role not found'];
            }

            // Check if role is a system role
            if ($role['is_system'] == 1) {
                $this->rollback();
                return ['success' => false, 'message' => 'Cannot delete system role'];
            }

            // Check if role is assigned to any users
            $usage = $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM platform_user_roles WHERE role_id = ?",
                [$roleId]
            );
            if ($usage && ($usage['count'] ?? 0) > 0) {
                $this->rollback();
                return ['success' => false, 'message' => 'Cannot delete role that is assigned to users'];
            }

            $result = $this->roleModel->delete($roleId);
            if (!$result) {
                $this->rollback();
                return ['success' => false, 'message' => 'Failed to delete role'];
            }

            $this->logAudit([
                'user_id' => null,
                'action_type' => 'ROLE_DELETED',
                'module' => 'Role',
                'resource' => 'role',
                'resource_id' => $roleId,
                'old_data' => json_encode($role)
            ]);

            $this->commit();

            return ['success' => true, 'message' => 'Role deleted successfully'];
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('deleteRole error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get role statistics
     */
    public function getStats(): array
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                        SUM(CASE WHEN is_system = 1 THEN 1 ELSE 0 END) as system_roles
                    FROM {$this->roleModel->getTable()} WHERE deleted_at IS NULL";
            $stats = $this->db->fetchOne($sql);

            // Get assigned roles count
            $assigned = $this->db->fetchOne(
                "SELECT COUNT(DISTINCT role_id) as assigned FROM platform_user_roles"
            );

            return [
                'success' => true,
                'data' => [
                    'total' => $stats['total'] ?? 0,
                    'active' => $stats['active'] ?? 0,
                    'system_roles' => $stats['system_roles'] ?? 0,
                    'assigned_roles' => $assigned['assigned'] ?? 0
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('getStats error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get roles by user
     */
    public function getRolesByUser(int $userId): array
    {
        try {
            $sql = "SELECT r.* FROM platform_roles r 
                    JOIN platform_user_roles pur ON r.id = pur.role_id 
                    WHERE pur.user_id = ? AND r.deleted_at IS NULL";
            $roles = $this->db->fetchAll($sql, [$userId]);
            return ['success' => true, 'data' => $roles];
        } catch (Exception $e) {
            $this->logger->error('getRolesByUser error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Assign role to user
     */
    public function assignRoleToUser(int $userId, int $roleId, ?int $assignedBy = null): array
    {
        try {
            $this->beginTransaction();

            // Check if role exists
            $role = $this->roleModel->find($roleId);
            if (!$role) {
                $this->rollback();
                return ['success' => false, 'message' => 'Role not found'];
            }

            // Check if already assigned
            $existing = $this->db->fetchOne(
                "SELECT * FROM platform_user_roles WHERE user_id = ? AND role_id = ?",
                [$userId, $roleId]
            );
            if ($existing) {
                $this->rollback();
                return ['success' => false, 'message' => 'Role already assigned to user'];
            }

            $sql = "INSERT INTO platform_user_roles (user_id, role_id, granted_by, granted_at) 
                    VALUES (?, ?, ?, NOW())";
            $this->db->query($sql, [$userId, $roleId, $assignedBy]);

            $this->logAudit([
                'user_id' => $assignedBy,
                'action_type' => 'ROLE_ASSIGNED',
                'module' => 'Role',
                'resource' => 'user_role',
                'resource_id' => $userId,
                'new_data' => json_encode(['user_id' => $userId, 'role_id' => $roleId])
            ]);

            $this->commit();

            return ['success' => true, 'message' => 'Role assigned to user successfully'];
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('assignRoleToUser error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Remove role from user
     */
    public function removeRoleFromUser(int $userId, int $roleId): array
    {
        try {
            $this->beginTransaction();

            $sql = "DELETE FROM platform_user_roles WHERE user_id = ? AND role_id = ?";
            $this->db->query($sql, [$userId, $roleId]);

            $this->logAudit([
                'user_id' => null,
                'action_type' => 'ROLE_REMOVED',
                'module' => 'Role',
                'resource' => 'user_role',
                'resource_id' => $userId,
                'old_data' => json_encode(['user_id' => $userId, 'role_id' => $roleId])
            ]);

            $this->commit();

            return ['success' => true, 'message' => 'Role removed from user successfully'];
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('removeRoleFromUser error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
