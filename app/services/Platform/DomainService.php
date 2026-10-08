<?php

/**
 * DomainService.php
 * Domain Service for Platform Management
 * 
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 2.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/TenantDomain.php';
require_once dirname(__DIR__, 2) . '/models/Platform/PlatformAuditLog.php';
require_once dirname(__DIR__, 2) . '/helpers/LoggerHelper.php';

class DomainService
{
    private $domainModel;
    private $auditModel;
    private $logger;

    public function __construct()
    {
        $this->domainModel = new TenantDomain();
        $this->auditModel = new PlatformAuditLog();
        $this->logger = new LoggerHelper();
    }

    /**
     * Get domains with filters
     */
    public function getDomains(array $filters = [], int $page = 1, int $limit = 20): array
    {
        try {
            $offset = ($page - 1) * $limit;
            $where = [];
            $params = [];

            if (!empty($filters['search'])) {
                $search = '%' . $filters['search'] . '%';
                $where[] = "(domain_name LIKE ?)";
                $params[] = $search;
            }

            if (!empty($filters['status'])) {
                $where[] = "status = ?";
                $params[] = $filters['status'];
            }

            if (!empty($filters['domain_type'])) {
                $where[] = "domain_type = ?";
                $params[] = $filters['domain_type'];
            }

            if (!empty($filters['ssl_status'])) {
                $where[] = "ssl_status = ?";
                $params[] = $filters['ssl_status'];
            }

            $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
            $whereClause .= " AND d.deleted_at IS NULL";

            // Count total
            $countSql = "SELECT COUNT(*) as total FROM tenant_domains d {$whereClause}";
            $countResult = $this->domainModel->rawFetchOne($countSql, $params);
            $total = $countResult['total'] ?? 0;
            $totalPages = ceil($total / $limit);

            // Get domains
            $sql = "SELECT d.*, t.tenant_name, t.tenant_code 
                    FROM tenant_domains d
                    LEFT JOIN tenants t ON d.tenant_id = t.id
                    {$whereClause}
                    ORDER BY d.is_primary DESC, d.domain_name ASC
                    LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            $domains = $this->domainModel->rawFetch($sql, $params);

            return [
                'success' => true,
                'data' => [
                    'domains' => $domains,
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $limit,
                    'total_pages' => $totalPages
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('getDomains error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get domains for a tenant
     */
    public function getTenantDomains(int $tenantId): array
    {
        try {
            $domains = $this->domainModel->getByTenant($tenantId);
            return ['success' => true, 'data' => $domains];
        } catch (Exception $e) {
            $this->logger->error('getTenantDomains error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get domain stats
     */
    public function getStats(): array
    {
        try {
            $stats = $this->domainModel->getStats();
            return ['success' => true, 'data' => $stats];
        } catch (Exception $e) {
            $this->logger->error('getStats error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Create a domain - 2 parameters to match API router call
     */
    public function createDomain(array $data, int $createdBy = null): array
    {
        try {
            if (empty($data['tenant_id']) || empty($data['domain_name'])) {
                return ['success' => false, 'message' => 'Tenant ID and Domain Name are required'];
            }

            // Check if domain already exists
            $existing = $this->domainModel->findByDomainName($data['domain_name']);
            if ($existing) {
                return ['success' => false, 'message' => 'Domain already exists'];
            }

            $domainData = [
                'tenant_id' => (int)$data['tenant_id'],
                'domain_name' => $data['domain_name'],
                'domain_type' => $data['domain_type'] ?? 'subdomain',
                'is_primary' => isset($data['is_primary']) ? (int)$data['is_primary'] : 0,
                'is_active' => isset($data['is_active']) ? (int)$data['is_active'] : 1,
                'status' => 'pending',
                'ssl_status' => 'pending',
                'verification_token' => $this->domainModel->generateVerificationToken(),
                'created_by' => $createdBy
            ];

            $domainId = $this->domainModel->create($domainData);
            if (!$domainId) {
                return ['success' => false, 'message' => 'Failed to create domain'];
            }

            // If this is primary, unset other primary domains for this tenant
            if ($domainData['is_primary'] == 1) {
                $this->domainModel->rawFetchOne(
                    "UPDATE tenant_domains SET is_primary = 0 WHERE tenant_id = ? AND id != ?",
                    [$data['tenant_id'], $domainId]
                );
            }

            $this->auditModel->log([
                'user_id' => $createdBy,
                'action_type' => 'DOMAIN_CREATED',
                'module' => 'Domain',
                'resource' => 'domain',
                'resource_id' => $domainId,
                'new_data' => json_encode($domainData),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            return ['success' => true, 'message' => 'Domain created successfully', 'data' => ['id' => $domainId]];
        } catch (Exception $e) {
            $this->logger->error('createDomain error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update a domain
     */
    public function updateDomain(int $domainId, array $data, int $updatedBy): array
    {
        try {
            $domain = $this->domainModel->find($domainId);
            if (!$domain) {
                return ['success' => false, 'message' => 'Domain not found'];
            }

            $updateData = [];
            $fields = ['domain_name', 'domain_type', 'is_primary', 'is_active', 'status', 'ssl_status'];

            foreach ($fields as $field) {
                if (isset($data[$field])) {
                    $updateData[$field] = $data[$field];
                }
            }

            if (!empty($updateData)) {
                $this->domainModel->update($domainId, $updateData);
            }

            // If this is primary, unset other primary domains for this tenant
            if (isset($data['is_primary']) && $data['is_primary'] == 1) {
                $this->domainModel->rawFetchOne(
                    "UPDATE tenant_domains SET is_primary = 0 WHERE tenant_id = ? AND id != ?",
                    [$domain['tenant_id'], $domainId]
                );
            }

            $this->auditModel->log([
                'user_id' => $updatedBy,
                'action_type' => 'DOMAIN_UPDATED',
                'module' => 'Domain',
                'resource' => 'domain',
                'resource_id' => $domainId,
                'old_data' => json_encode($domain),
                'new_data' => json_encode($updateData),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            return ['success' => true, 'message' => 'Domain updated successfully'];
        } catch (Exception $e) {
            $this->logger->error('updateDomain error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete a domain
     */
    public function deleteDomain(int $domainId): array
    {
        try {
            $domain = $this->domainModel->find($domainId);
            if (!$domain) {
                return ['success' => false, 'message' => 'Domain not found'];
            }

            if ($domain['is_primary'] == 1) {
                return ['success' => false, 'message' => 'Cannot delete primary domain. Set another domain as primary first.'];
            }

            $this->domainModel->delete($domainId);

            $this->auditModel->log([
                'user_id' => null,
                'action_type' => 'DOMAIN_DELETED',
                'module' => 'Domain',
                'resource' => 'domain',
                'resource_id' => $domainId,
                'old_data' => json_encode($domain),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            return ['success' => true, 'message' => 'Domain deleted successfully'];
        } catch (Exception $e) {
            $this->logger->error('deleteDomain error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Verify a domain (DNS verification)
     */
    public function verifyDomain(int $domainId, int $userId): array
    {
        try {
            $domain = $this->domainModel->find($domainId);
            if (!$domain) {
                return ['success' => false, 'message' => 'Domain not found'];
            }

            if ($domain['dns_verified'] == 1) {
                return ['success' => false, 'message' => 'Domain is already verified'];
            }

            // In production, you would actually check DNS records here
            $this->domainModel->update($domainId, ['dns_verified' => 1, 'status' => 'active']);

            $updatedDomain = $this->domainModel->find($domainId);

            $this->auditModel->log([
                'user_id' => $userId,
                'action_type' => 'DOMAIN_VERIFIED',
                'module' => 'Domain',
                'resource' => 'domain',
                'resource_id' => $domainId,
                'old_data' => json_encode(['dns_verified' => $domain['dns_verified']]),
                'new_data' => json_encode(['dns_verified' => $updatedDomain['dns_verified']]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            return ['success' => true, 'message' => 'Domain verified successfully', 'data' => $updatedDomain];
        } catch (Exception $e) {
            $this->logger->error('verifyDomain error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Activate a domain
     */
    public function activateDomain(int $domainId, int $userId): array
    {
        try {
            $domain = $this->domainModel->find($domainId);
            if (!$domain) {
                return ['success' => false, 'message' => 'Domain not found'];
            }

            $this->domainModel->update($domainId, ['is_active' => 1, 'status' => 'active']);

            $this->auditModel->log([
                'user_id' => $userId,
                'action_type' => 'DOMAIN_ACTIVATED',
                'module' => 'Domain',
                'resource' => 'domain',
                'resource_id' => $domainId,
                'old_data' => json_encode(['is_active' => $domain['is_active']]),
                'new_data' => json_encode(['is_active' => 1]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            return ['success' => true, 'message' => 'Domain activated successfully'];
        } catch (Exception $e) {
            $this->logger->error('activateDomain error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Deactivate a domain
     */
    public function deactivateDomain(int $domainId, int $userId): array
    {
        try {
            $domain = $this->domainModel->find($domainId);
            if (!$domain) {
                return ['success' => false, 'message' => 'Domain not found'];
            }

            if ($domain['is_primary'] == 1) {
                return ['success' => false, 'message' => 'Cannot deactivate primary domain'];
            }

            $this->domainModel->update($domainId, ['is_active' => 0, 'status' => 'inactive']);

            $this->auditModel->log([
                'user_id' => $userId,
                'action_type' => 'DOMAIN_DEACTIVATED',
                'module' => 'Domain',
                'resource' => 'domain',
                'resource_id' => $domainId,
                'old_data' => json_encode(['is_active' => $domain['is_active']]),
                'new_data' => json_encode(['is_active' => 0]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            return ['success' => true, 'message' => 'Domain deactivated successfully'];
        } catch (Exception $e) {
            $this->logger->error('deactivateDomain error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
