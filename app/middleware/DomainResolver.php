<?php

/**
 * DomainResolver.php
 * Resolves the incoming host to a tenant
 * 
 * @package EduTrack
 * @subpackage Middleware
 * @filepath app/middleware/DomainResolver.php
 */

class DomainResolver
{
    private $db;
    private $cache = [];
    private $platformDomains = ['admin.edutrack.local', 'platform.edutrack.local'];

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Resolve the current host to a tenant
     * 
     * @return array|null ['tenant_id' => int, 'tenant_code' => string, 'tenant_name' => string, 'domain_type' => string]
     */
    public function resolve(): ?array
    {
        $host = $_SERVER['HTTP_HOST'];

        // Remove port if present
        $host = preg_replace('/:\d+$/', '', $host);

        // Check if this is a platform domain
        if ($this->isPlatformDomain($host)) {
            return $this->resolvePlatformDomain($host);
        }

        // Step 1: Check custom domain mapping (tenant purchases own domain)
        $result = $this->resolveCustomDomain($host);
        if ($result) {
            return $result;
        }

        // Step 2: Check subdomain mapping (tenant_code.edutrack.local)
        $result = $this->resolveSubdomain($host);
        if ($result) {
            return $result;
        }

        // Step 3: No tenant found - return null (will redirect to login or 404)
        return null;
    }

    /**
     * Check if this is a platform domain
     */
    private function isPlatformDomain(string $host): bool
    {
        foreach ($this->platformDomains as $platformDomain) {
            if ($host === $platformDomain || strpos($host, $platformDomain) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve platform domain (no tenant context)
     */
    private function resolvePlatformDomain(string $host): array
    {
        return [
            'tenant_id' => null,
            'tenant_code' => null,
            'tenant_name' => 'EduTrack Platform',
            'domain_type' => 'platform',
            'is_platform' => true
        ];
    }

    /**
     * Resolve custom domain (tenant purchases own domain)
     * e.g., https://greenhill.edu.gh → tenant_id: 1
     */
    private function resolveCustomDomain(string $host): ?array
    {
        // Check cache first
        if (isset($this->cache['custom_' . $host])) {
            return $this->cache['custom_' . $host];
        }

        // Check if domain exists in tenant_domains table
        $sql = "SELECT 
                    td.tenant_id, 
                    td.domain_name,
                    td.is_primary,
                    td.is_custom,
                    t.tenant_name,
                    t.tenant_code,
                    t.status as tenant_status
                FROM tenant_domains td
                JOIN tenants t ON td.tenant_id = t.id AND t.deleted_at IS NULL
                WHERE td.domain_name = ? 
                  AND td.is_active = 1 
                  AND td.deleted_at IS NULL
                  AND t.status = 'active'
                LIMIT 1";

        $result = $this->db->fetchOne($sql, [$host]);

        if ($result) {
            $tenantData = [
                'tenant_id' => $result['tenant_id'],
                'tenant_code' => $result['tenant_code'],
                'tenant_name' => $result['tenant_name'],
                'domain_type' => 'custom',
                'is_platform' => false,
                'domain_name' => $result['domain_name'],
                'is_primary' => (bool)$result['is_primary']
            ];

            $this->cache['custom_' . $host] = $tenantData;
            return $tenantData;
        }

        return null;
    }

    /**
     * Resolve subdomain (tenant_code.edutrack.local)
     * e.g., https://ghl001.edutrack.local → tenant_id: 1
     */
    private function resolveSubdomain(string $host): ?array
    {
        // Check cache first
        if (isset($this->cache['subdomain_' . $host])) {
            return $this->cache['subdomain_' . $host];
        }

        // Define valid base domains
        $validBaseDomains = [
            '.edutrack.local',
            '.edutrack.com',
            '.edutrack.org',
            '.edutrack.net'
        ];

        $matchedBase = null;
        $tenantCode = null;

        foreach ($validBaseDomains as $baseDomain) {
            if (strpos($host, $baseDomain) !== false) {
                $matchedBase = $baseDomain;
                $prefix = str_replace($baseDomain, '', $host);
                $tenantCode = strtolower($prefix);
                break;
            }
        }

        if (!$tenantCode) {
            return null;
        }

        // Query tenant by tenant_code
        $sql = "SELECT 
                    id, 
                    tenant_name, 
                    tenant_code, 
                    status 
                FROM tenants 
                WHERE LOWER(tenant_code) = ? 
                  AND status = 'active' 
                  AND deleted_at IS NULL
                LIMIT 1";

        $result = $this->db->fetchOne($sql, [$tenantCode]);

        if ($result) {
            $tenantData = [
                'tenant_id' => $result['id'],
                'tenant_code' => $result['tenant_code'],
                'tenant_name' => $result['tenant_name'],
                'domain_type' => 'subdomain',
                'is_platform' => false,
                'subdomain' => $tenantCode,
                'base_domain' => $matchedBase
            ];

            $this->cache['subdomain_' . $host] = $tenantData;
            return $tenantData;
        }

        return null;
    }

    /**
     * Get the tenant ID from the current request
     */
    public function getTenantId(): ?int
    {
        $result = $this->resolve();
        return $result ? $result['tenant_id'] : null;
    }

    /**
     * Get the full tenant data from the current request
     */
    public function getTenantData(): ?array
    {
        return $this->resolve();
    }

    /**
     * Check if the current request is a platform request
     */
    public function isPlatformRequest(): bool
    {
        $result = $this->resolve();
        return $result ? $result['is_platform'] : false;
    }
}
