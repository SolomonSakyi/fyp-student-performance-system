<?php

/**
 * UserService.php
 * User Service for Platform Management
 * 
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 2.0
 */

$projectRoot = dirname(__DIR__, 3) . '/';

require_once $projectRoot . 'app/models/Platform/PlatformUser.php';
require_once $projectRoot . 'app/models/Platform/PlatformRole.php';
require_once $projectRoot . 'app/models/Platform/PlatformAuditLog.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class UserService
{
    private $userModel;
    private $roleModel;
    private $auditModel;
    private $logger;
    private $db;

    public function __construct()
    {
        $this->userModel = new PlatformUser();
        $this->roleModel = new PlatformRole();
        $this->auditModel = new PlatformAuditLog();
        $this->logger = new LoggerHelper();
        $this->db = DatabaseHelper::getInstance();
    }

    public function getUsers(array $filters = [], int $page = 1, int $limit = 20): array
    {
        try {
            $offset = ($page - 1) * $limit;
            $where = [];
            $params = [];

            if (!empty($filters['search'])) {
                $search = '%' . $filters['search'] . '%';
                $where[] = "(u.username LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
            }

            if (!empty($filters['status'])) {
                $where[] = "u.is_active = ?";
                $params[] = ($filters['status'] === 'active') ? 1 : 0;
            }

            if (!empty($filters['tenant_id'])) {
                $where[] = "u.tenant_id = ?";
                $params[] = (int)$filters['tenant_id'];
            }

            if (!empty($filters['role'])) {
                $where[] = "EXISTS (SELECT 1 FROM platform_user_roles pur JOIN platform_roles pr ON pur.role_id = pr.id WHERE pur.user_id = u.id AND pr.code = ?)";
                $params[] = $filters['role'];
            }

            $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
            $whereClause .= " AND u.deleted_at IS NULL";

            $countSql = "SELECT COUNT(*) as total FROM platform_users u {$whereClause}";
            $countResult = $this->userModel->rawFetchOne($countSql, $params);
            $total = $countResult['total'] ?? 0;
            $totalPages = ceil($total / $limit);

            $sql = "SELECT 
                        u.*,
                        t.tenant_name
                    FROM platform_users u
                    LEFT JOIN tenants t ON u.tenant_id = t.id
                    {$whereClause}
                    ORDER BY u.id DESC
                    LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            $users = $this->userModel->rawFetch($sql, $params);

            foreach ($users as &$user) {
                $roles = $this->userModel->getRoles($user['id']);
                $user['roles'] = array_column($roles, 'code');
            }

            return [
                'success' => true,
                'data' => [
                    'users' => $users,
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $limit,
                    'total_pages' => $totalPages
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('getUsers error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getUser(int $userId): array
    {
        try {
            $user = $this->userModel->find($userId);
            if (!$user) {
                return ['success' => false, 'message' => 'User not found'];
            }

            $roles = $this->userModel->getRoles($userId);
            $user['roles'] = array_column($roles, 'code');

            if (!empty($user['tenant_id'])) {
                $tenant = $this->userModel->rawFetchOne("SELECT tenant_name FROM tenants WHERE id = ?", [$user['tenant_id']]);
                $user['tenant_name'] = $tenant['tenant_name'] ?? null;
            }

            unset($user['password_hash']);

            return ['success' => true, 'data' => $user];
        } catch (Exception $e) {
            $this->logger->error('getUser error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function createUser(array $data): array
    {
        try {
            $required = ['first_name', 'last_name', 'username', 'email', 'password', 'role'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required'];
                }
            }

            $existing = $this->userModel->findByUsername($data['username']);
            if ($existing) {
                return ['success' => false, 'message' => 'Username already exists'];
            }

            $existing = $this->userModel->findByEmail($data['email']);
            if ($existing) {
                return ['success' => false, 'message' => 'Email already exists'];
            }

            if (strlen($data['password']) < 8) {
                return ['success' => false, 'message' => 'Password must be at least 8 characters'];
            }

            $role = $this->roleModel->findByCode($data['role']);
            if (!$role) {
                return ['success' => false, 'message' => 'Invalid role: ' . $data['role']];
            }

            $userData = [
                'username' => $data['username'],
                'email' => $data['email'],
                'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'] ?? '',
                'tenant_id' => !empty($data['tenant_id']) ? (int)$data['tenant_id'] : null,
                'is_active' => isset($data['is_active']) ? (int)$data['is_active'] : 1
            ];

            $userId = $this->userModel->create($userData);
            if (!$userId) {
                return ['success' => false, 'message' => 'Failed to create user'];
            }

            $this->userModel->assignRole($userId, $role['id'], $data['created_by'] ?? null);

            $this->auditModel->log([
                'user_id' => $data['created_by'] ?? null,
                'action_type' => 'USER_CREATED',
                'module' => 'User',
                'resource' => 'user',
                'resource_id' => $userId,
                'new_data' => json_encode(['username' => $data['username'], 'email' => $data['email'], 'role' => $data['role']]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            return ['success' => true, 'message' => 'User created successfully', 'data' => ['id' => $userId]];
        } catch (Exception $e) {
            $this->logger->error('createUser error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateUser(int $userId, array $data, ?int $updatedBy = null): array
    {
        try {
            $user = $this->userModel->find($userId);
            if (!$user) {
                return ['success' => false, 'message' => 'User not found'];
            }

            $updateData = [];

            if (isset($data['first_name'])) $updateData['first_name'] = $data['first_name'];
            if (isset($data['last_name'])) $updateData['last_name'] = $data['last_name'];
            if (isset($data['email'])) {
                $existing = $this->userModel->findByEmail($data['email']);
                if ($existing && $existing['id'] != $userId) {
                    return ['success' => false, 'message' => 'Email already exists'];
                }
                $updateData['email'] = $data['email'];
            }
            if (isset($data['phone'])) $updateData['phone'] = $data['phone'];
            if (isset($data['tenant_id'])) $updateData['tenant_id'] = !empty($data['tenant_id']) ? (int)$data['tenant_id'] : null;
            if (isset($data['is_active'])) $updateData['is_active'] = (int)$data['is_active'];

            if (isset($data['password']) && !empty($data['password'])) {
                if (strlen($data['password']) < 8) {
                    return ['success' => false, 'message' => 'Password must be at least 8 characters'];
                }
                $updateData['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            }

            if (!empty($updateData)) {
                $this->userModel->update($userId, $updateData);
            }

            if (isset($data['role']) && !empty($data['role'])) {
                $role = $this->roleModel->findByCode($data['role']);
                if ($role) {
                    $this->userModel->removeAllRoles($userId);
                    $this->userModel->assignRole($userId, $role['id'], $updatedBy);
                }
            }

            $this->auditModel->log([
                'user_id' => $updatedBy,
                'action_type' => 'USER_UPDATED',
                'module' => 'User',
                'resource' => 'user',
                'resource_id' => $userId,
                'old_data' => json_encode(['username' => $user['username']]),
                'new_data' => json_encode($updateData),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            return ['success' => true, 'message' => 'User updated successfully'];
        } catch (Exception $e) {
            $this->logger->error('updateUser error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteUser(int $userId): array
    {
        try {
            $user = $this->userModel->find($userId);
            if (!$user) {
                return ['success' => false, 'message' => 'User not found'];
            }

            $this->userModel->delete($userId);

            $this->auditModel->log([
                'user_id' => null,
                'action_type' => 'USER_DELETED',
                'module' => 'User',
                'resource' => 'user',
                'resource_id' => $userId,
                'old_data' => json_encode(['username' => $user['username']]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            return ['success' => true, 'message' => 'User deleted successfully'];
        } catch (Exception $e) {
            $this->logger->error('deleteUser error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getStats(): array
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
                        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as pending
                    FROM platform_users WHERE deleted_at IS NULL";
            $stats = $this->userModel->rawFetchOne($sql);

            $adminSql = "SELECT COUNT(DISTINCT pur.user_id) as tenant_admins 
                        FROM platform_user_roles pur 
                        JOIN platform_roles pr ON pur.role_id = pr.id 
                        WHERE pr.code = 'tenant_admin'";
            $adminStats = $this->userModel->rawFetchOne($adminSql);

            return [
                'success' => true,
                'data' => [
                    'total' => $stats['total'] ?? 0,
                    'active' => $stats['active'] ?? 0,
                    'inactive' => $stats['inactive'] ?? 0,
                    'pending' => $stats['pending'] ?? 0,
                    'tenant_admins' => $adminStats['tenant_admins'] ?? 0
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('getStats error: ' . $e->getMessage());
            return ['success' => false, 'data' => ['total' => 0, 'active' => 0, 'inactive' => 0, 'pending' => 0, 'tenant_admins' => 0]];
        }
    }

    public function exportUsers(): array
    {
        try {
            $users = $this->userModel->getAll('id', 'DESC');
            $csv = [];
            $headers = ['ID', 'Username', 'Email', 'First Name', 'Last Name', 'Phone', 'Tenant', 'Status', 'Last Login', 'Created At'];
            $csv[] = $headers;

            foreach ($users as $user) {
                $roles = $this->userModel->getRoles($user['id']);
                $roleNames = implode(', ', array_column($roles, 'name'));

                $tenantName = '';
                if (!empty($user['tenant_id'])) {
                    $tenant = $this->userModel->rawFetchOne("SELECT tenant_name FROM tenants WHERE id = ?", [$user['tenant_id']]);
                    $tenantName = $tenant['tenant_name'] ?? '';
                }

                $csv[] = [
                    $user['id'],
                    $user['username'],
                    $user['email'],
                    $user['first_name'] ?? '',
                    $user['last_name'] ?? '',
                    $user['phone'] ?? '',
                    $tenantName,
                    $user['is_active'] ? 'Active' : 'Inactive',
                    $user['last_login'] ?? '',
                    $user['created_at']
                ];
            }

            $output = fopen('php://temp', 'r+');
            foreach ($csv as $row) {
                fputcsv($output, $row);
            }
            rewind($output);
            $csvContent = stream_get_contents($output);
            fclose($output);

            return [
                'success' => true,
                'data' => $csvContent,
                'filename' => 'users_export_' . date('Y-m-d') . '.csv'
            ];
        } catch (Exception $e) {
            $this->logger->error('exportUsers error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
