<?php

/**
 * TenantService.php
 * Service for managing tenants
 * 
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 2.0
 * @filepath app/services/Platform/TenantService.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class TenantService
{
    private $db;
    private $logger;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
    }

    /**
     * Get all tenants with pagination and stats
     * FIXED: Removed pur.deleted_at references
     */
    public function getTenants(int $page = 1, int $limit = 20): array
    {
        try {
            $offset = ($page - 1) * $limit;

            // Get tenants with counts
            $sql = "SELECT t.*,
                    (SELECT COUNT(*) FROM platform_users pu WHERE pu.tenant_id = t.id AND pu.deleted_at IS NULL) as user_count,
                    (SELECT COUNT(*) FROM schools s WHERE s.tenant_id = t.id AND s.deleted_at IS NULL) as school_count
                    FROM tenants t 
                    WHERE t.deleted_at IS NULL 
                    ORDER BY t.created_at DESC 
                    LIMIT ? OFFSET ?";

            $tenants = $this->db->fetchAll($sql, [$limit, $offset]);

            $countSql = "SELECT COUNT(*) as total FROM tenants WHERE deleted_at IS NULL";
            $countResult = $this->db->fetchOne($countSql);
            $total = (int)($countResult['total'] ?? 0);

            $stats = $this->getTenantStats();

            return [
                'tenants' => $tenants,
                'total' => $total,
                'total_pages' => ceil($total / $limit),
                'stats' => $stats
            ];
        } catch (Exception $e) {
            $this->logger->error('getTenants error: ' . $e->getMessage());
            return [
                'tenants' => [],
                'total' => 0,
                'total_pages' => 0,
                'stats' => ['total' => 0, 'active' => 0, 'pending' => 0, 'suspended' => 0, 'inactive' => 0]
            ];
        }
    }

    /**
     * Get tenants with filters
     * FIXED: Removed pur.deleted_at references
     */
    public function getTenantsWithFilters(array $filters = [], int $page = 1, int $limit = 20): array
    {
        try {
            $params = [];
            $where = ["t.deleted_at IS NULL"];

            // Search filter
            if (!empty($filters['search'])) {
                $searchTerm = '%' . $filters['search'] . '%';
                $where[] = "(t.tenant_name LIKE ? OR t.legal_name LIKE ? OR t.email LIKE ?)";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            // Status filter
            if (!empty($filters['status'])) {
                $where[] = "t.status = ?";
                $params[] = $filters['status'];
            }

            // User filter - FIXED: Removed deleted_at check on platform_user_roles
            if (!empty($filters['user_id']) && empty($filters['is_admin'])) {
                $where[] = "EXISTS (SELECT 1 FROM platform_user_roles pur 
                            WHERE pur.user_id = ? AND pur.tenant_id = t.id)";
                $params[] = $filters['user_id'];
            }

            $whereClause = "WHERE " . implode(" AND ", $where);
            $offset = ($page - 1) * $limit;

            // Count total
            $countSql = "SELECT COUNT(*) as total FROM tenants t $whereClause";
            $countResult = $this->db->fetchOne($countSql, $params);
            $total = (int)($countResult['total'] ?? 0);

            // Get tenants with counts
            $sql = "SELECT t.*,
                    (SELECT COUNT(*) FROM platform_users pu WHERE pu.tenant_id = t.id AND pu.deleted_at IS NULL) as user_count,
                    (SELECT COUNT(*) FROM schools s WHERE s.tenant_id = t.id AND s.deleted_at IS NULL) as school_count
                    FROM tenants t
                    $whereClause
                    ORDER BY t.created_at DESC
                    LIMIT ? OFFSET ?";

            $params[] = $limit;
            $params[] = $offset;

            $tenants = $this->db->fetchAll($sql, $params);

            return [
                'success' => true,
                'data' => [
                    'tenants' => $tenants,
                    'total' => $total,
                    'total_pages' => ceil($total / $limit),
                    'current_page' => $page,
                    'per_page' => $limit
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('getTenantsWithFilters error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get a single tenant by ID
     */
    public function getTenant(int $id): ?array
    {
        try {
            $sql = "SELECT t.*,
                    (SELECT COUNT(*) FROM platform_users pu WHERE pu.tenant_id = t.id AND pu.deleted_at IS NULL) as user_count,
                    (SELECT COUNT(*) FROM schools s WHERE s.tenant_id = t.id AND s.deleted_at IS NULL) as school_count
                    FROM tenants t
                    WHERE t.id = ? AND t.deleted_at IS NULL";
            return $this->db->fetchOne($sql, [$id]);
        } catch (Exception $e) {
            $this->logger->error('getTenant error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get tenant stats
     */
    public function getTenantStats(): array
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended,
                        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive
                    FROM tenants
                    WHERE deleted_at IS NULL";

            $result = $this->db->fetchOne($sql);

            return [
                'total' => (int)($result['total'] ?? 0),
                'active' => (int)($result['active'] ?? 0),
                'pending' => (int)($result['pending'] ?? 0),
                'suspended' => (int)($result['suspended'] ?? 0),
                'inactive' => (int)($result['inactive'] ?? 0)
            ];
        } catch (Exception $e) {
            $this->logger->error('getTenantStats error: ' . $e->getMessage());
            return ['total' => 0, 'active' => 0, 'pending' => 0, 'suspended' => 0, 'inactive' => 0];
        }
    }

    /**
     * Generate a unique tenant code
     */
    private function generateTenantCode(string $name): string
    {
        $clean = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $name));
        $prefix = substr($clean, 0, 4);
        if (strlen($prefix) < 2) {
            $prefix = 'TEN';
        }
        return $prefix . date('YmdHis') . rand(10, 99);
    }

    /**
     * Update a tenant
     */
    public function updateTenant(int $tenantId, array $data): array
    {
        try {
            // Check if tenant exists
            $checkSql = "SELECT id FROM tenants WHERE id = ? AND deleted_at IS NULL";
            $existing = $this->db->fetchOne($checkSql, [$tenantId]);
            if (!$existing) {
                return ['success' => false, 'message' => 'Tenant not found'];
            }

            // Build update fields
            $fields = [];
            $params = [];

            $allowedFields = [
                'tenant_name',
                'legal_name',
                'institution_type',
                'email',
                'phone',
                'website',
                'region',
                'district',
                'postal_address',
                'status'
            ];

            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $fields[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($fields)) {
                return ['success' => false, 'message' => 'No fields to update'];
            }

            $params[] = $tenantId;

            $sql = "UPDATE tenants SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";

            $result = $this->db->execute($sql, $params);

            return [
                'success' => $result,
                'message' => $result ? 'Tenant updated successfully' : 'Failed to update tenant'
            ];
        } catch (Exception $e) {
            $this->logger->error('updateTenant error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update tenant status
     */
    public function updateStatus(int $tenantId, string $status): array
    {
        try {
            $validStatuses = ['active', 'inactive', 'pending', 'suspended', 'archived'];
            if (!in_array($status, $validStatuses)) {
                return ['success' => false, 'message' => 'Invalid status'];
            }

            $sql = "UPDATE tenants SET status = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
            $result = $this->db->execute($sql, [$status, $tenantId]);

            return [
                'success' => $result,
                'message' => $result ? 'Status updated successfully' : 'Failed to update status'
            ];
        } catch (Exception $e) {
            $this->logger->error('updateStatus error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete a tenant (soft delete)
     */
    public function deleteTenant(int $tenantId): array
    {
        try {
            $sql = "UPDATE tenants SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
            $result = $this->db->execute($sql, [$tenantId]);

            return [
                'success' => $result,
                'message' => $result ? 'Tenant deleted successfully' : 'Failed to delete tenant'
            ];
        } catch (Exception $e) {
            $this->logger->error('deleteTenant error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Create a tenant - WITH SCHOOL AND CAMPUS CREATION
     */
    public function createTenant(array $data): array
    {
        try {
            if (empty($data['tenant_name'])) {
                return ['success' => false, 'message' => 'Tenant name is required'];
            }
            if (empty($data['email'])) {
                return ['success' => false, 'message' => 'Tenant email is required'];
            }

            // CHECK FOR DUPLICATE EMAIL IN TENANTS TABLE
            $checkSql = "SELECT id FROM tenants WHERE email = ? AND deleted_at IS NULL";
            $existing = $this->db->fetchOne($checkSql, [$data['email']]);
            if ($existing) {
                return ['success' => false, 'message' => 'A tenant with this email already exists'];
            }

            // CHECK FOR DUPLICATE EMAIL IN PLATFORM_USERS TABLE
            $checkSql = "SELECT id FROM platform_users WHERE email = ? AND deleted_at IS NULL";
            $existing = $this->db->fetchOne($checkSql, [$data['email']]);
            if ($existing) {
                return ['success' => false, 'message' => 'A user with this email already exists'];
            }

            // Start transaction
            $this->db->beginTransaction();

            try {
                $tenantCode = $this->generateTenantCode($data['tenant_name']);

                // 1. Create Tenant
                $sql = "INSERT INTO tenants (
                    tenant_name,
                    tenant_code,
                    legal_name,
                    institution_type,
                    email,
                    phone,
                    website,
                    region,
                    district,
                    postal_address,
                    status,
                    is_active,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 1, NOW())";

                $params = [
                    $data['tenant_name'],
                    $tenantCode,
                    $data['legal_name'] ?? '',
                    $data['institution_type'] ?? '',
                    $data['email'],
                    $data['phone'] ?? '',
                    $data['website'] ?? '',
                    $data['region'] ?? '',
                    $data['district'] ?? '',
                    $data['postal_address'] ?? ''
                ];

                $result = $this->db->execute($sql, $params);

                if (!$result) {
                    throw new Exception('Failed to create tenant');
                }

                $tenantId = (int)$this->db->lastInsertId();

                if ($tenantId === 0) {
                    throw new Exception('Failed to get tenant ID');
                }

                // 2. Create Default School
                $schoolName = $data['tenant_name'] . ' School';
                $cleanName = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $data['tenant_name']));
                $schoolCode = 'SCH-' . substr($cleanName, 0, 3) . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);

                $schoolSql = "INSERT INTO schools (
                    tenant_id,
                    school_name,
                    school_code,
                    school_type,
                    status,
                    created_at
                ) VALUES (?, ?, ?, 'combined', 'active', NOW())";

                $schoolResult = $this->db->execute($schoolSql, [$tenantId, $schoolName, $schoolCode]);

                if (!$schoolResult) {
                    throw new Exception('Failed to create school');
                }

                $schoolId = (int)$this->db->lastInsertId();

                // 3. Create Default Campus
                $campusName = 'Main Campus';
                $campusCode = 'CMP-' . substr($cleanName, 0, 3) . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);

                $campusSql = "INSERT INTO campuses (
                    tenant_id,
                    school_id,
                    campus_name,
                    campus_code,
                    status,
                    created_at
                ) VALUES (?, ?, ?, ?, 'active', NOW())";

                $this->db->execute($campusSql, [$tenantId, $schoolId, $campusName, $campusCode]);

                // Commit transaction
                $this->db->commit();

                return [
                    'success' => true,
                    'message' => 'Tenant created successfully',
                    'data' => [
                        'tenant_id' => $tenantId,
                        'tenant_code' => $tenantCode,
                        'school_id' => $schoolId
                    ]
                ];
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            $this->logger->error('createTenant error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Register a new tenant
     */
    public function registerTenant(array $data): array
    {
        try {
            // Extract tenant data
            $tenantData = $data['tenant'] ?? $data;
            $adminData = $data['admin'] ?? [];

            // Validate
            if (empty($tenantData['tenant_name'])) {
                return ['success' => false, 'message' => 'Tenant name is required'];
            }
            if (empty($tenantData['email'])) {
                return ['success' => false, 'message' => 'Tenant email is required'];
            }

            // CHECK ADMIN EMAIL DUPLICATE
            if (!empty($adminData['email'])) {
                $checkSql = "SELECT id FROM platform_users WHERE email = ? AND deleted_at IS NULL";
                $existing = $this->db->fetchOne($checkSql, [$adminData['email']]);
                if ($existing) {
                    return ['success' => false, 'message' => 'Admin email already exists'];
                }
            }

            // CHECK ADMIN USERNAME DUPLICATE
            if (!empty($adminData['username'])) {
                $checkSql = "SELECT id FROM platform_users WHERE username = ? AND deleted_at IS NULL";
                $existing = $this->db->fetchOne($checkSql, [$adminData['username']]);
                if ($existing) {
                    return ['success' => false, 'message' => 'Admin username already exists'];
                }
            }

            // Create tenant with school and campus
            return $this->createTenant($tenantData);
        } catch (Exception $e) {
            $this->logger->error('registerTenant error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
