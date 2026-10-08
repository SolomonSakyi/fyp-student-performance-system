<?php

/**
 * DomainController.php
 * Controller for managing tenant domains with multi-tenant isolation
 * 
 * @package EduTrack
 * @subpackage Controllers\Platform
 * @version 2.0
 * @filepath app/controllers/Platform/DomainController.php
 */

class DomainController
{
    private $db;
    private $logger;
    private $response;
    private $context;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->response = new ResponseHelper();
        $this->context = TenantContext::getInstance();
    }

    /**
     * Get all domains with pagination and filters
     * GET /api/platform/index.php?endpoint=domains&action=list
     */
    public function getDomains(): void
    {
        try {
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $status = isset($_GET['status']) ? trim($_GET['status']) : '';

            $params = [];
            $where = ["d.deleted_at IS NULL"];

            if (!empty($search)) {
                $searchTerm = "%$search%";
                $where[] = "(d.domain_name LIKE ? OR t.tenant_name LIKE ?)";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            if (!empty($status)) {
                $where[] = "d.status = ?";
                $params[] = $status;
            }

            $whereClause = "WHERE " . implode(" AND ", $where);
            $offset = ($page - 1) * $limit;

            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM domains d 
                         LEFT JOIN tenants t ON d.tenant_id = t.id 
                         $whereClause";
            $countResult = $this->db->fetchOne($countSql, $params);
            $total = (int)($countResult['total'] ?? 0);
            $totalPages = $total > 0 ? ceil($total / $limit) : 0;

            // Get domains with tenant info - FIXED: removed legal_name
            $sql = "SELECT 
                        d.*,
                        t.tenant_name
                    FROM domains d
                    LEFT JOIN tenants t ON d.tenant_id = t.id
                    $whereClause
                    ORDER BY d.created_at DESC
                    LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $domains = $this->db->fetchAll($sql, $params);

            $this->response->success([
                'domains' => $domains,
                'total' => $total,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'per_page' => $limit
            ]);
        } catch (Exception $e) {
            error_log('getDomains error: ' . $e->getMessage());
            $this->response->error('Error fetching domains: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get a single domain by ID
     * GET /api/platform/index.php?endpoint=domains&action=get&id={id}
     */
    public function getDomain(int $id): void
    {
        try {
            // FIXED: removed legal_name, using tenant_name only
            $sql = "SELECT 
                        d.*,
                        t.tenant_name
                    FROM domains d
                    LEFT JOIN tenants t ON d.tenant_id = t.id
                    WHERE d.id = ? AND d.deleted_at IS NULL";
            $domain = $this->db->fetchOne($sql, [$id]);

            if (!$domain) {
                $this->response->error('Domain not found', 404);
                return;
            }

            $this->response->success($domain);
        } catch (Exception $e) {
            error_log('getDomain error: ' . $e->getMessage());
            $this->response->error('Error fetching domain: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create a new domain
     * POST /api/platform/index.php?endpoint=domains&action=create
     */
    public function createDomain(): void
    {
        try {
            $data = $this->getRequestData();

            if (empty($data['domain_name'])) {
                $this->response->error('Domain name is required', 400);
                return;
            }
            if (empty($data['tenant_id'])) {
                $this->response->error('Tenant ID is required', 400);
                return;
            }

            // Check if domain already exists
            $existing = $this->db->getValue(
                "SELECT COUNT(*) FROM domains WHERE domain_name = ? AND deleted_at IS NULL",
                [$data['domain_name']]
            );
            if ($existing > 0) {
                $this->response->error('Domain already exists', 400);
                return;
            }

            // Check if tenant exists
            $tenant = $this->db->getValue(
                "SELECT id FROM tenants WHERE id = ? AND deleted_at IS NULL",
                [$data['tenant_id']]
            );
            if (!$tenant) {
                $this->response->error('Tenant not found', 404);
                return;
            }

            $sql = "INSERT INTO domains (
                        tenant_id, domain_name, domain_type, is_primary, 
                        is_verified, status, ssl_enabled, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

            $params = [
                (int)$data['tenant_id'],
                $data['domain_name'],
                $data['domain_type'] ?? 'secondary',
                isset($data['is_primary']) ? (int)$data['is_primary'] : 0,
                isset($data['is_verified']) ? (int)$data['is_verified'] : 0,
                $data['status'] ?? 'pending',
                isset($data['ssl_enabled']) ? (int)$data['ssl_enabled'] : 0
            ];

            $this->db->execute($sql, $params);
            $domainId = $this->db->getValue("SELECT LAST_INSERT_ID()");

            // Audit log
            $this->logger->info('Domain created', [
                'domain_id' => $domainId,
                'domain_name' => $data['domain_name'],
                'tenant_id' => $data['tenant_id']
            ]);

            $this->response->success([
                'message' => 'Domain created successfully',
                'domain_id' => $domainId,
                'domain' => [
                    'id' => $domainId,
                    'domain_name' => $data['domain_name'],
                    'tenant_id' => $data['tenant_id']
                ]
            ]);
        } catch (Exception $e) {
            error_log('createDomain error: ' . $e->getMessage());
            $this->response->error('Error creating domain: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update a domain
     * PUT /api/platform/index.php?endpoint=domains&action=update&id={id}
     */
    public function updateDomain(int $id): void
    {
        try {
            $data = $this->getRequestData();

            // Check if domain exists
            $domain = $this->db->fetchOne(
                "SELECT id FROM domains WHERE id = ? AND deleted_at IS NULL",
                [$id]
            );
            if (!$domain) {
                $this->response->error('Domain not found', 404);
                return;
            }

            $updates = [];
            $params = [];

            $allowedFields = ['domain_name', 'status', 'is_primary', 'ssl_enabled', 'is_verified'];
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($updates)) {
                $this->response->error('No fields to update', 400);
                return;
            }

            $updates[] = "updated_at = NOW()";
            $params[] = $id;

            $sql = "UPDATE domains SET " . implode(", ", $updates) . " WHERE id = ? AND deleted_at IS NULL";
            $this->db->execute($sql, $params);

            $this->logger->info('Domain updated', ['domain_id' => $id]);

            $this->response->success(['message' => 'Domain updated successfully']);
        } catch (Exception $e) {
            error_log('updateDomain error: ' . $e->getMessage());
            $this->response->error('Error updating domain: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a domain (soft delete)
     * DELETE /api/platform/index.php?endpoint=domains&action=delete&id={id}
     */
    public function deleteDomain(int $id): void
    {
        try {
            // Check if domain exists
            $domain = $this->db->fetchOne(
                "SELECT id FROM domains WHERE id = ? AND deleted_at IS NULL",
                [$id]
            );
            if (!$domain) {
                $this->response->error('Domain not found', 404);
                return;
            }

            $sql = "UPDATE domains SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
            $this->db->execute($sql, [$id]);

            $this->logger->info('Domain deleted', ['domain_id' => $id]);

            $this->response->success(['message' => 'Domain deleted successfully']);
        } catch (Exception $e) {
            error_log('deleteDomain error: ' . $e->getMessage());
            $this->response->error('Error deleting domain: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Verify a domain
     * POST /api/platform/index.php?endpoint=domains&action=verify&id={id}
     */
    public function verifyDomain(int $id): void
    {
        try {
            // Check if domain exists
            $domain = $this->db->fetchOne(
                "SELECT id, status FROM domains WHERE id = ? AND deleted_at IS NULL",
                [$id]
            );
            if (!$domain) {
                $this->response->error('Domain not found', 404);
                return;
            }

            $sql = "UPDATE domains SET 
                        status = 'verified', 
                        is_verified = 1,
                        verified_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ? AND deleted_at IS NULL";
            $this->db->execute($sql, [$id]);

            $this->logger->info('Domain verified', ['domain_id' => $id]);

            $this->response->success(['message' => 'Domain verified successfully']);
        } catch (Exception $e) {
            error_log('verifyDomain error: ' . $e->getMessage());
            $this->response->error('Error verifying domain: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Activate a domain
     * PUT /api/platform/index.php?endpoint=domains&action=activate&id={id}
     */
    public function activateDomain(int $id): void
    {
        try {
            // Check if domain exists
            $domain = $this->db->fetchOne(
                "SELECT id FROM domains WHERE id = ? AND deleted_at IS NULL",
                [$id]
            );
            if (!$domain) {
                $this->response->error('Domain not found', 404);
                return;
            }

            $sql = "UPDATE domains SET 
                        status = 'active', 
                        updated_at = NOW()
                    WHERE id = ? AND deleted_at IS NULL";
            $this->db->execute($sql, [$id]);

            $this->logger->info('Domain activated', ['domain_id' => $id]);

            $this->response->success(['message' => 'Domain activated successfully']);
        } catch (Exception $e) {
            error_log('activateDomain error: ' . $e->getMessage());
            $this->response->error('Error activating domain: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Deactivate a domain
     * PUT /api/platform/index.php?endpoint=domains&action=deactivate&id={id}
     */
    public function deactivateDomain(int $id): void
    {
        try {
            // Check if domain exists
            $domain = $this->db->fetchOne(
                "SELECT id FROM domains WHERE id = ? AND deleted_at IS NULL",
                [$id]
            );
            if (!$domain) {
                $this->response->error('Domain not found', 404);
                return;
            }

            $sql = "UPDATE domains SET 
                        status = 'inactive', 
                        updated_at = NOW()
                    WHERE id = ? AND deleted_at IS NULL";
            $this->db->execute($sql, [$id]);

            $this->logger->info('Domain deactivated', ['domain_id' => $id]);

            $this->response->success(['message' => 'Domain deactivated successfully']);
        } catch (Exception $e) {
            error_log('deactivateDomain error: ' . $e->getMessage());
            $this->response->error('Error deactivating domain: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get domain statistics
     * GET /api/platform/index.php?endpoint=domains&action=stats
     */
    public function getStats(): void
    {
        try {
            $total = $this->db->getValue("SELECT COUNT(*) FROM domains WHERE deleted_at IS NULL") ?? 0;
            $active = $this->db->getValue("SELECT COUNT(*) FROM domains WHERE deleted_at IS NULL AND status = 'active'") ?? 0;
            $pending = $this->db->getValue("SELECT COUNT(*) FROM domains WHERE deleted_at IS NULL AND status = 'pending'") ?? 0;
            $verified = $this->db->getValue("SELECT COUNT(*) FROM domains WHERE deleted_at IS NULL AND status = 'verified'") ?? 0;
            $inactive = $this->db->getValue("SELECT COUNT(*) FROM domains WHERE deleted_at IS NULL AND status = 'inactive'") ?? 0;

            $tenantsWithDomains = $this->db->getValue(
                "SELECT COUNT(DISTINCT tenant_id) FROM domains WHERE deleted_at IS NULL"
            ) ?? 0;

            $this->response->success([
                'total' => $total,
                'active' => $active,
                'pending' => $pending,
                'verified' => $verified,
                'inactive' => $inactive,
                'tenants' => $tenantsWithDomains
            ]);
        } catch (Exception $e) {
            error_log('getDomainStats error: ' . $e->getMessage());
            $this->response->error('Error fetching domain stats: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Check domain availability
     * GET /api/platform/index.php?endpoint=domains&action=check&domain={domain}
     */
    public function checkDomainAvailability(): void
    {
        try {
            $domain = isset($_GET['domain']) ? trim($_GET['domain']) : '';
            if (empty($domain)) {
                $this->response->error('Domain name is required', 400);
                return;
            }

            $exists = $this->db->getValue(
                "SELECT COUNT(*) FROM domains WHERE domain_name = ? AND deleted_at IS NULL",
                [$domain]
            );

            $this->response->success([
                'available' => $exists == 0,
                'domain' => $domain
            ]);
        } catch (Exception $e) {
            error_log('checkDomainAvailability error: ' . $e->getMessage());
            $this->response->error('Error checking domain: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get domains by tenant
     * GET /api/platform/index.php?endpoint=domains&action=by-tenant&tenant_id={tenant_id}
     */
    public function getDomainsByTenant(): void
    {
        try {
            $tenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
            if ($tenantId <= 0) {
                $this->response->error('Tenant ID is required', 400);
                return;
            }

            $sql = "SELECT * FROM domains 
                    WHERE tenant_id = ? AND deleted_at IS NULL 
                    ORDER BY is_primary DESC, created_at DESC";
            $domains = $this->db->fetchAll($sql, [$tenantId]);

            $this->response->success($domains);
        } catch (Exception $e) {
            error_log('getDomainsByTenant error: ' . $e->getMessage());
            $this->response->error('Error fetching tenant domains: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Autocomplete domains
     * GET /api/platform/index.php?endpoint=domains&action=autocomplete&search={search}
     */
    public function autocompleteDomains(): void
    {
        try {
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

            if (empty($search) || strlen($search) < 2) {
                $this->response->success([]);
                return;
            }

            $searchTerm = "%$search%";
            $sql = "SELECT d.id, d.domain_name, d.status, d.is_primary, t.tenant_name
                    FROM domains d
                    LEFT JOIN tenants t ON d.tenant_id = t.id
                    WHERE d.deleted_at IS NULL 
                    AND (d.domain_name LIKE ? OR t.tenant_name LIKE ?)
                    ORDER BY d.domain_name ASC
                    LIMIT ?";
            $domains = $this->db->fetchAll($sql, [$searchTerm, $searchTerm, $limit]);

            $this->response->success($domains);
        } catch (Exception $e) {
            error_log('autocompleteDomains error: ' . $e->getMessage());
            $this->response->error('Error autocompleting domains: ' . $e->getMessage(), 500);
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
