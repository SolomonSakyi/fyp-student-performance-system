<?php

/**
 * TenantDomain.php
 *
 * Tenant Domain Model for Platform Management
 * Manages subdomains and custom domains for tenants
 *
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 2.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class TenantDomain extends BaseModel
{
    /**
     * @var string Table name
     */
    protected string $table = 'tenant_domains';

    /**
     * @var array Fillable fields
     */
    protected array $fillable = [
        'uuid',
        'tenant_id',
        'domain_name',
        'domain_type',
        'is_primary',
        'ssl_status',
        'dns_verified',
        'verification_token',
        'status',
        'is_active',
        'created_by',
        'deleted_at'
    ];

    /**
     * @var array Hidden fields
     */
    protected array $hidden = ['verification_token'];

    /**
     * Domain status constants
     */
    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_FAILED = 'failed';
    const STATUS_VERIFIED = 'verified';

    const SSL_STATUS_PENDING = 'pending';
    const SSL_STATUS_ACTIVE = 'active';
    const SSL_STATUS_INACTIVE = 'inactive';
    const SSL_STATUS_FAILED = 'failed';

    const DOMAIN_TYPE_SUBDOMAIN = 'subdomain';
    const DOMAIN_TYPE_CUSTOM = 'custom';
    const DOMAIN_TYPE_ALIAS = 'alias';

    /**
     * Find domain by name
     */
    public function findByDomainName(string $domainName): ?array
    {
        return $this->findBy('domain_name', $domainName);
    }

    /**
     * Get domains for a tenant
     */
    public function getByTenant(int $tenantId): array
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE tenant_id = ? AND deleted_at IS NULL 
                ORDER BY is_primary DESC, domain_name";
        return $this->rawFetch($sql, [$tenantId]);
    }

    /**
     * Get active domains for a tenant
     */
    public function getActiveByTenant(int $tenantId): array
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL 
                AND status = 'active'
                ORDER BY is_primary DESC, domain_name";
        return $this->rawFetch($sql, [$tenantId]);
    }

    /**
     * Get primary domain for a tenant
     */
    public function getPrimaryDomain(int $tenantId): ?array
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE tenant_id = ? AND is_primary = 1 AND deleted_at IS NULL 
                AND is_active = 1 AND status = 'active'
                LIMIT 1";
        return $this->rawFetchOne($sql, [$tenantId]);
    }

    /**
     * Get domains by type
     */
    public function getByType(int $tenantId, string $type): array
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE tenant_id = ? AND domain_type = ? AND deleted_at IS NULL 
                ORDER BY domain_name";
        return $this->rawFetch($sql, [$tenantId, $type]);
    }

    /**
     * Get domains by SSL status
     */
    public function getBySslStatus(string $status): array
    {
        $sql = "SELECT d.*, t.tenant_name, t.tenant_code 
                FROM {$this->table} d
                JOIN tenants t ON d.tenant_id = t.id
                WHERE d.ssl_status = ? AND d.deleted_at IS NULL 
                ORDER BY d.created_at DESC";
        return $this->rawFetch($sql, [$status]);
    }

    /**
     * Get domains pending verification
     */
    public function getPendingVerification(): array
    {
        $sql = "SELECT d.*, t.tenant_name, t.tenant_code, t.email as tenant_email
                FROM {$this->table} d
                JOIN tenants t ON d.tenant_id = t.id
                WHERE d.dns_verified = 0 AND d.deleted_at IS NULL 
                AND d.status = 'pending'
                ORDER BY d.created_at ASC";
        return $this->rawFetch($sql);
    }

    /**
     * Check if domain exists (excluding self)
     */
    public function domainExists(string $domainName, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} WHERE domain_name = ?";
        $params = [$domainName];
        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        $result = $this->rawFetchOne($sql, $params);
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Check if tenant has a primary domain
     */
    public function hasPrimaryDomain(int $tenantId): bool
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} 
                WHERE tenant_id = ? AND is_primary = 1 AND deleted_at IS NULL";
        $result = $this->rawFetchOne($sql, [$tenantId]);
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Set domain as primary (unset others)
     */
    public function setPrimary(int $domainId, int $tenantId): bool
    {
        try {
            $this->beginTransaction();

            // Unset all primary for this tenant
            $sql = "UPDATE {$this->table} SET is_primary = 0 
                    WHERE tenant_id = ? AND deleted_at IS NULL";
            $this->db->query($sql, [$tenantId]);

            // Set this domain as primary
            $result = $this->update($domainId, ['is_primary' => 1]);

            $this->commit();
            return $result;
        } catch (Exception $e) {
            $this->rollBack();
            $this->logger->error('setPrimary error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify domain DNS
     */
    public function verifyDomain(int $domainId): bool
    {
        try {
            $domain = $this->find($domainId);
            if (!$domain) {
                return false;
            }

            // In production, this would perform actual DNS verification
            // For now, simulate verification
            $verified = $this->performDnsVerification($domain['domain_name'], $domain['verification_token'] ?? '');

            $status = $verified ? 'active' : 'failed';
            $result = $this->update($domainId, [
                'dns_verified' => $verified ? 1 : 0,
                'status' => $status,
                'ssl_status' => $verified ? 'pending' : 'failed'
            ]);

            return $result;
        } catch (Exception $e) {
            $this->logger->error('verifyDomain error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Perform DNS verification (simulated)
     */
    private function performDnsVerification(string $domainName, string $token): bool
    {
        // In production, this would:
        // 1. Check DNS TXT record for verification token
        // 2. Or check CNAME record pointing to platform
        // 3. Or check A/AAAA record

        // For demo, we'll consider it verified if token is not empty
        // and domain name is valid
        return !empty($token) && !empty($domainName);
    }

    /**
     * Update SSL status
     */
    public function updateSslStatus(int $domainId, string $status): bool
    {
        return $this->update($domainId, ['ssl_status' => $status]);
    }

    /**
     * Generate verification token
     */
    public function generateVerificationToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Get domain statistics
     */
    public function getStats(): array
    {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN domain_type = 'subdomain' THEN 1 ELSE 0 END) as subdomains,
                    SUM(CASE WHEN domain_type = 'custom' THEN 1 ELSE 0 END) as custom_domains,
                    SUM(CASE WHEN domain_type = 'alias' THEN 1 ELSE 0 END) as aliases,
                    SUM(CASE WHEN is_primary = 1 THEN 1 ELSE 0 END) as primary_domains,
                    SUM(CASE WHEN status = 'active' AND is_active = 1 THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN ssl_status = 'active' THEN 1 ELSE 0 END) as ssl_active,
                    SUM(CASE WHEN ssl_status = 'pending' THEN 1 ELSE 0 END) as ssl_pending,
                    SUM(CASE WHEN dns_verified = 1 THEN 1 ELSE 0 END) as dns_verified
                FROM {$this->table} WHERE deleted_at IS NULL";

        $result = $this->rawFetchOne($sql);
        return $result ?? [
            'total' => 0,
            'subdomains' => 0,
            'custom_domains' => 0,
            'aliases' => 0,
            'primary_domains' => 0,
            'active' => 0,
            'pending' => 0,
            'failed' => 0,
            'ssl_active' => 0,
            'ssl_pending' => 0,
            'dns_verified' => 0
        ];
    }

    /**
     * Get tenant ID for a domain
     */
    public function getTenantIdByDomain(string $domainName): ?int
    {
        $domain = $this->findByDomainName($domainName);
        return $domain ? $domain['tenant_id'] : null;
    }

    /**
     * Activate domain
     */
    public function activate(int $domainId): bool
    {
        return $this->update($domainId, [
            'is_active' => 1,
            'status' => 'active'
        ]);
    }

    /**
     * Deactivate domain
     */
    public function deactivate(int $domainId): bool
    {
        return $this->update($domainId, [
            'is_active' => 0,
            'status' => 'inactive'
        ]);
    }

    /**
     * Get domains with tenant details
     */
    public function getWithTenantDetails(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        try {
            $offset = ($page - 1) * $perPage;
            $where = [];
            $params = [];

            if (!empty($filters['tenant_id'])) {
                $where[] = "d.tenant_id = ?";
                $params[] = $filters['tenant_id'];
            }

            if (!empty($filters['domain_type'])) {
                $where[] = "d.domain_type = ?";
                $params[] = $filters['domain_type'];
            }

            if (!empty($filters['status'])) {
                $where[] = "d.status = ?";
                $params[] = $filters['status'];
            }

            if (!empty($filters['ssl_status'])) {
                $where[] = "d.ssl_status = ?";
                $params[] = $filters['ssl_status'];
            }

            if (!empty($filters['is_primary'])) {
                $where[] = "d.is_primary = ?";
                $params[] = $filters['is_primary'];
            }

            if (!empty($filters['dns_verified'])) {
                $where[] = "d.dns_verified = ?";
                $params[] = $filters['dns_verified'];
            }

            if (!empty($filters['search'])) {
                $searchTerm = "%{$filters['search']}%";
                $where[] = "(d.domain_name LIKE ? OR t.tenant_name LIKE ? OR t.tenant_code LIKE ?)";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

            // Count total
            $countSql = "SELECT COUNT(*) as total FROM {$this->table} d 
                         JOIN tenants t ON d.tenant_id = t.id 
                         {$whereClause}";
            $countResult = $this->rawFetchOne($countSql, $params);
            $total = $countResult['total'] ?? 0;
            $totalPages = ceil($total / $perPage);

            // Get domains
            $sql = "SELECT 
                        d.*,
                        t.tenant_name,
                        t.tenant_code,
                        t.email as tenant_email,
                        t.phone as tenant_phone
                    FROM {$this->table} d
                    JOIN tenants t ON d.tenant_id = t.id
                    {$whereClause}
                    ORDER BY d.is_primary DESC, d.domain_name ASC
                    LIMIT ? OFFSET ?";

            $params[] = $perPage;
            $params[] = $offset;

            $domains = $this->rawFetch($sql, $params);

            return [
                'success' => true,
                'data' => [
                    'domains' => $domains,
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => $totalPages
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('getWithTenantDetails error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
