<?php

/**
 * Domain Resolver Service
 * Resolves tenant context from domain name
 *
 * @package EduTrack
 * @subpackage Services\Domain
 * @version 1.0
 * @filepath app/services/Domain/DomainResolver.php
 */

class DomainResolver
{
    private $db;
    private $cache = [];

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Resolve tenant context from hostname
     *
     * @param string $hostname
     * @return array|null Returns context array or null if not found
     */
    public function resolveFromHostname(string $hostname): ?array
    {
        // Normalize hostname
        $hostname = $this->normalizeHostname($hostname);

        // Check cache
        if (isset($this->cache[$hostname])) {
            return $this->cache[$hostname];
        }

        // Validate hostname
        if (!$this->isValidHostname($hostname)) {
            return null;
        }

        // Query domain record
        $domain = $this->db->fetchOne("
            SELECT 
                d.id as domain_id,
                d.domain_name,
                d.domain_type,
                d.is_primary,
                d.is_verified,
                d.status as domain_status,
                d.ssl_status,
                d.tenant_id,
                d.school_id,
                d.campus_id,
                t.tenant_name,
                t.status as tenant_status,
                t.tenant_code,
                s.id as school_id,
                s.school_name,
                s.status as school_status,
                s.school_code,
                c.id as campus_id,
                c.campus_name,
                c.status as campus_status
            FROM domains d
            LEFT JOIN tenants t ON d.tenant_id = t.id
            LEFT JOIN schools s ON (d.school_id = s.id OR (d.school_id IS NULL AND s.tenant_id = t.id AND s.is_primary = 1))
            LEFT JOIN campuses c ON (d.campus_id = c.id OR (d.campus_id IS NULL AND c.school_id = s.id AND c.is_primary = 1))
            WHERE d.domain_name = ? 
              AND d.deleted_at IS NULL
              AND t.deleted_at IS NULL
              AND (s.deleted_at IS NULL OR s.deleted_at = '')
              AND (c.deleted_at IS NULL OR c.deleted_at = '')
            LIMIT 1
        ", [$hostname]);

        if (!$domain) {
            $this->cache[$hostname] = null;
            return null;
        }

        // Validate domain status
        if ($domain['domain_status'] !== 'active') {
            $this->cache[$hostname] = ['error' => 'domain_inactive'];
            return null;
        }

        // Validate domain verification
        if ((int)$domain['is_verified'] !== 1) {
            $this->cache[$hostname] = ['error' => 'domain_not_verified'];
            return null;
        }

        // Validate tenant status
        if ($domain['tenant_status'] !== 'active') {
            $this->cache[$hostname] = ['error' => 'tenant_inactive'];
            return null;
        }

        // Build context
        $context = [
            'domain_id' => (int)$domain['domain_id'],
            'domain_name' => $domain['domain_name'],
            'domain_type' => $domain['domain_type'] ?? 'primary',
            'is_primary' => (int)($domain['is_primary'] ?? 0),
            'is_verified' => (int)($domain['is_verified'] ?? 0),
            'ssl_status' => $domain['ssl_status'] ?? 'none',
            'tenant_id' => (int)$domain['tenant_id'],
            'tenant_name' => $domain['tenant_name'],
            'tenant_code' => $domain['tenant_code'],
            'school_id' => $domain['school_id'] ? (int)$domain['school_id'] : null,
            'school_name' => $domain['school_name'],
            'school_code' => $domain['school_code'],
            'campus_id' => $domain['campus_id'] ? (int)$domain['campus_id'] : null,
            'campus_name' => $domain['campus_name'],
            'resolved_at' => date('Y-m-d H:i:s')
        ];

        // Cache the result
        $this->cache[$hostname] = $context;

        return $context;
    }

    /**
     * Get public login context (safe for API responses)
     *
     * @param string $hostname
     * @return array|null
     */
    public function getLoginContext(string $hostname): ?array
    {
        $context = $this->resolveFromHostname($hostname);

        if (!$context || isset($context['error'])) {
            return null;
        }

        // Return only safe public data
        return [
            'success' => true,
            'tenant' => [
                'id' => $context['tenant_id'],
                'name' => $context['tenant_name'],
                'code' => $context['tenant_code']
            ],
            'school' => [
                'id' => $context['school_id'],
                'name' => $context['school_name'],
                'code' => $context['school_code']
            ],
            'campus' => [
                'id' => $context['campus_id'],
                'name' => $context['campus_name']
            ],
            'domain' => [
                'id' => $context['domain_id'],
                'name' => $context['domain_name'],
                'type' => $context['domain_type'],
                'ssl_status' => $context['ssl_status']
            ],
            'login_audiences' => ['staff', 'student', 'admin']
        ];
    }

    /**
     * Normalize hostname for lookup
     */
    private function normalizeHostname(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));
        // Remove www. prefix
        $hostname = preg_replace('/^www\./', '', $hostname);
        // Remove port if present
        $hostname = preg_replace('/:\d+$/', '', $hostname);
        return $hostname;
    }

    /**
     * Validate hostname for security
     */
    private function isValidHostname(string $hostname): bool
    {
        // Empty or too long
        if (empty($hostname) || strlen($hostname) > 255) {
            return false;
        }

        // Must be a valid domain format
        if (!preg_match('/^[a-z0-9\-\.]+$/', $hostname)) {
            return false;
        }

        // Must have at least one dot (not an IP or localhost)
        if (strpos($hostname, '.') === false) {
            return false;
        }

        // Prevent IP addresses
        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            return false;
        }

        return true;
    }

    /**
     * Check if domain exists and is active
     */
    public function domainExists(string $hostname): bool
    {
        $context = $this->resolveFromHostname($hostname);
        return $context !== null && !isset($context['error']);
    }

    /**
     * Get domain status for display
     */
    public function getDomainStatus(string $hostname): array
    {
        $context = $this->resolveFromHostname($hostname);

        if (!$context) {
            return ['status' => 'not_found', 'message' => 'Domain not found'];
        }

        if (isset($context['error'])) {
            $messages = [
                'domain_inactive' => 'This domain is currently inactive.',
                'domain_not_verified' => 'This domain has not been verified.',
                'tenant_inactive' => 'The tenant associated with this domain is inactive.'
            ];
            return [
                'status' => $context['error'],
                'message' => $messages[$context['error']] ?? 'Domain configuration error.'
            ];
        }

        return [
            'status' => 'active',
            'message' => 'Domain is active and verified.',
            'context' => $context
        ];
    }
}
