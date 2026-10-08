<?php

/**
 * TenantController.php
 * Controller for managing tenants with multi-tenant isolation
 * 
 * @package EduTrack
 * @subpackage Controllers\Platform
 * @version 2.0
 * @filepath app/controllers/Platform/TenantController.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/helpers/ResponseHelper.php';
require_once $projectRoot . 'app/helpers/UuidHelper.php';
require_once $projectRoot . 'app/services/Tenant/TenantContext.php';

class TenantController
{
    private $db;
    private $logger;
    private $response;
    private $context;
    private $authenticatedUserId;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->response = new ResponseHelper();
        $this->context = TenantContext::getInstance();
        $this->authenticatedUserId = null;
        $this->authenticate();
    }

    /**
     * Authenticate user from session or JWT token
     */
    private function authenticate(): bool
    {
        // Check session first
        if (isset($_SESSION['user_id'])) {
            $this->authenticatedUserId = $_SESSION['user_id'];
            return true;
        }

        // Check Authorization header
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';

        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
            $parts = explode('.', $token);
            if (count($parts) === 3) {
                $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
                if ($payload && isset($payload['user_id'])) {
                    $this->authenticatedUserId = $payload['user_id'];
                    $_SESSION['user_id'] = $payload['user_id'];
                    $_SESSION['tenant_id'] = $payload['tenant_id'] ?? null;
                    $_SESSION['user_roles'] = $payload['roles'] ?? [];
                    return true;
                }
            }
        }

        return false;
    }

    private function isAuthenticated(): bool
    {
        return $this->authenticatedUserId !== null;
    }

    private function getTenantId(): ?int
    {
        return $this->context->getTenantId();
    }

    private function isPlatformAdmin(): bool
    {
        return $this->context->isPlatformAdmin();
    }

    /**
     * Get all tenants with pagination and filters
     * GET /api/platform/index.php?endpoint=tenants&action=list
     */
    public function getTenants(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $status = isset($_GET['status']) ? trim($_GET['status']) : '';

            $params = [];
            $where = ["t.deleted_at IS NULL"];

            if (!$this->isPlatformAdmin()) {
                $tenantId = $this->getTenantId();
                if ($tenantId) {
                    $where[] = "t.id = ?";
                    $params[] = $tenantId;
                } else {
                    $this->response->error('Access denied', 403);
                    return;
                }
            }

            if (!empty($search)) {
                $searchTerm = "%$search%";
                $where[] = "(t.tenant_name LIKE ? OR t.legal_name LIKE ? OR t.email LIKE ?)";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            if (!empty($status)) {
                $where[] = "t.status = ?";
                $params[] = $status;
            }

            $whereClause = "WHERE " . implode(" AND ", $where);
            $offset = ($page - 1) * $limit;

            $countSql = "SELECT COUNT(*) as total FROM tenants t $whereClause";
            $countResult = $this->db->fetchOne($countSql, $params);
            $total = (int)($countResult['total'] ?? 0);
            $totalPages = $total > 0 ? ceil($total / $limit) : 0;

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

            $this->response->success([
                'tenants' => $tenants,
                'total' => $total,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'per_page' => $limit
            ]);
        } catch (Exception $e) {
            error_log('getTenants error: ' . $e->getMessage());
            $this->response->error('Error fetching tenants: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get a single tenant by ID
     * GET /api/platform/index.php?endpoint=tenants&action=get&id={id}
     */
    public function getTenant(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            if (!$this->isPlatformAdmin()) {
                $tenantId = $this->getTenantId();
                if ($tenantId && $tenantId != $id) {
                    $this->response->error('Access denied', 403);
                    return;
                }
            }

            $sql = "SELECT t.*,
                    (SELECT COUNT(*) FROM platform_users pu WHERE pu.tenant_id = t.id AND pu.deleted_at IS NULL) as user_count,
                    (SELECT COUNT(*) FROM schools s WHERE s.tenant_id = t.id AND s.deleted_at IS NULL) as school_count
                    FROM tenants t
                    WHERE t.id = ? AND t.deleted_at IS NULL";
            $tenant = $this->db->fetchOne($sql, [$id]);

            if (!$tenant) {
                $this->response->error('Tenant not found', 404);
                return;
            }

            $this->response->success($tenant);
        } catch (Exception $e) {
            error_log('getTenant error: ' . $e->getMessage());
            $this->response->error('Error fetching tenant: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create a new tenant
     * POST /api/platform/index.php?endpoint=tenants&action=create
     * 
     * NOTE: UUID is NOT included in the INSERT statement.
     * The database trigger (before_insert_tenants) automatically generates the UUID.
     */
    public function createTenant(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            if (!$this->isPlatformAdmin()) {
                $this->response->error('Only platform admins can create tenants', 403);
                return;
            }

            $data = $this->getRequestData();
            if (empty($data)) {
                $this->response->error('Invalid request data', 400);
                return;
            }

            if (empty($data['tenant_name'])) {
                $this->response->error('Tenant name is required', 400);
                return;
            }

            // ============================================================
            // FIX: UUID is REMOVED from the INSERT statement.
            // The database trigger (before_insert_tenants) will automatically
            // generate a UUID when the column is NULL.
            // ============================================================

            $sql = "INSERT INTO tenants (
                tenant_name, legal_name, email, phone, address, 
                website, status, settings, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $params = [
                $data['tenant_name'],
                $data['legal_name'] ?? $data['tenant_name'],
                $data['email'] ?? null,
                $data['phone'] ?? null,
                $data['address'] ?? null,
                $data['website'] ?? null,
                $data['status'] ?? 'active',
                !empty($data['settings']) ? json_encode($data['settings']) : null
            ];

            $result = $this->db->execute($sql, $params);

            if (!$result) {
                $this->response->error('Failed to create tenant', 500);
                return;
            }

            $tenantId = $this->db->lastInsertId();

            // Get the newly created tenant with its UUID
            $newTenant = $this->db->fetchOne(
                "SELECT id, uuid, tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL",
                [$tenantId]
            );

            $this->logger->info('Tenant created', [
                'tenant_id' => $tenantId,
                'tenant_name' => $data['tenant_name'],
                'uuid' => $newTenant['uuid'] ?? 'generated_by_trigger'
            ]);

            $this->response->success([
                'message' => 'Tenant created successfully',
                'tenant_id' => $tenantId,
                'tenant' => $newTenant
            ]);
        } catch (Exception $e) {
            error_log('createTenant error: ' . $e->getMessage());
            $this->response->error('Error creating tenant: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update a tenant
     * PUT /api/platform/index.php?endpoint=tenants&action=update&id={id}
     */
    public function updateTenant(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            if (!$this->isPlatformAdmin()) {
                $tenantId = $this->getTenantId();
                if ($tenantId && $tenantId != $id) {
                    $this->response->error('Access denied', 403);
                    return;
                }
            }

            $data = $this->getRequestData();
            if (empty($data)) {
                $this->response->error('Invalid request data', 400);
                return;
            }

            $tenant = $this->db->fetchOne("SELECT id FROM tenants WHERE id = ? AND deleted_at IS NULL", [$id]);
            if (!$tenant) {
                $this->response->error('Tenant not found', 404);
                return;
            }

            $updates = [];
            $params = [];

            $allowedFields = ['tenant_name', 'legal_name', 'email', 'phone', 'address', 'website', 'status'];
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (isset($data['settings'])) {
                $updates[] = "settings = ?";
                $params[] = json_encode($data['settings']);
            }

            if (empty($updates)) {
                $this->response->error('No fields to update', 400);
                return;
            }

            $updates[] = "updated_at = NOW()";
            $params[] = $id;

            $sql = "UPDATE tenants SET " . implode(", ", $updates) . " WHERE id = ? AND deleted_at IS NULL";
            $this->db->execute($sql, $params);

            $this->logger->info('Tenant updated', ['tenant_id' => $id]);

            $this->response->success(['message' => 'Tenant updated successfully']);
        } catch (Exception $e) {
            error_log('updateTenant error: ' . $e->getMessage());
            $this->response->error('Error updating tenant: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update tenant status
     * PUT /api/platform/index.php?endpoint=tenants&action=update_status&id={id}
     */
    public function updateTenantStatus(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            if (!$this->isPlatformAdmin()) {
                $this->response->error('Only platform admins can update tenant status', 403);
                return;
            }

            $data = $this->getRequestData();
            if (empty($data) || empty($data['status'])) {
                $this->response->error('Status is required', 400);
                return;
            }

            $allowedStatuses = ['active', 'inactive', 'pending', 'suspended'];
            if (!in_array($data['status'], $allowedStatuses)) {
                $this->response->error('Invalid status. Allowed: ' . implode(', ', $allowedStatuses), 400);
                return;
            }

            $tenant = $this->db->fetchOne("SELECT id FROM tenants WHERE id = ? AND deleted_at IS NULL", [$id]);
            if (!$tenant) {
                $this->response->error('Tenant not found', 404);
                return;
            }

            $sql = "UPDATE tenants SET status = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
            $this->db->execute($sql, [$data['status'], $id]);

            $this->logger->info('Tenant status updated', [
                'tenant_id' => $id,
                'status' => $data['status']
            ]);

            $this->response->success(['message' => 'Tenant status updated successfully']);
        } catch (Exception $e) {
            error_log('updateTenantStatus error: ' . $e->getMessage());
            $this->response->error('Error updating tenant status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a tenant (soft delete)
     * DELETE /api/platform/index.php?endpoint=tenants&action=delete&id={id}
     */
    public function deleteTenant(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            if (!$this->isPlatformAdmin()) {
                $this->response->error('Only platform admins can delete tenants', 403);
                return;
            }

            $tenant = $this->db->fetchOne("SELECT id FROM tenants WHERE id = ? AND deleted_at IS NULL", [$id]);
            if (!$tenant) {
                $this->response->error('Tenant not found', 404);
                return;
            }

            $sql = "UPDATE tenants SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
            $this->db->execute($sql, [$id]);

            $this->logger->info('Tenant deleted', ['tenant_id' => $id]);

            $this->response->success(['message' => 'Tenant deleted successfully']);
        } catch (Exception $e) {
            error_log('deleteTenant error: ' . $e->getMessage());
            $this->response->error('Error deleting tenant: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Check if email already exists
     * POST /api/platform/index.php?endpoint=tenants&action=check_email
     */
    public function checkEmail(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();
            if (empty($data['email'])) {
                $this->response->error('Email is required', 400);
                return;
            }

            $excludeId = isset($data['exclude_id']) ? (int)$data['exclude_id'] : 0;

            $sql = "SELECT id FROM tenants WHERE email = ? AND deleted_at IS NULL";
            $params = [$data['email']];
            if ($excludeId > 0) {
                $sql .= " AND id != ?";
                $params[] = $excludeId;
            }

            $result = $this->db->fetchOne($sql, $params);

            $this->response->success([
                'exists' => (bool)$result,
                'email' => $data['email']
            ]);
        } catch (Exception $e) {
            error_log('checkEmail error: ' . $e->getMessage());
            $this->response->error('Error checking email: ' . $e->getMessage(), 500);
        }
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
}
