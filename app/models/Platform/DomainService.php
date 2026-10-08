<?php
/**
 * DomainService.php
 *
 * Domain Management Service
 *
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 1.0
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../../models/Platform/TenantDomain.php';
require_once __DIR__ . '/../../models/Platform/Tenant.php';
require_once __DIR__ . '/../../models/Platform/PlatformAuditLog.php';

class DomainService extends BaseService
{
    /**
     * @var TenantDomain Domain model
     */
    private $domainModel;

    /**
     * @var Tenant Tenant model
     */
    private $tenantModel;

    /**
     * @var PlatformAuditLog Audit log model
     */
    private $auditModel;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->domainModel = new TenantDomain();
        $this->tenantModel = new Tenant();
        $this->auditModel = new PlatformAuditLog();
    }

    /**
     * Register a domain for a tenant
     */
    public function registerDomain(int $tenantId, array $data): array
    {
        try {
            $required = ['domain_name', 'domain_type'];
            $this->validateRequired($data, $required);

            $tenant = $this->tenantModel->find($tenantId);
            if (!$tenant) {
                return $this->error('Tenant not found');
            }

            // Check if domain already exists
            $existing = $this->domainModel->findByName($data['domain_name']);
            if ($existing) {
                return $this->error('Domain already registered');
            }

            // Generate verification token
            $verificationToken = $this->generateUuid();

            $domainId = $this->domainModel->create([
                'tenant_id' => $tenantId,
                'domain_name' => $data['domain_name'],
                'domain_type' => $data['domain_type'],
                'is_primary' => $data['is_primary'] ?? 0,
                'ssl_status' => TenantDomain::SSL_PENDING,
                'dns_verified' => 0,
                'verification_token' => $verificationToken,
                'status' => TenantDomain::STATUS_PENDING,
                'created_by' => $_SESSION['user_id'] ?? null
            ]);

            if (!$domainId) {
                return $this->error('Failed to register domain');
            }

            // If this is the first domain, set as primary
            $domains = $this->domainModel->getByTenant($tenantId);
            if (count($domains) === 1) {
                $this->domainModel->update($domainId, ['is_primary' => 1]);
            }

            // Log audit
            $this->auditModel->log([
                'user_id' => $_SESSION['user_id'] ?? null,
                'tenant_id' => $tenantId,
                'action_type' => 'REGISTER_DOMAIN',
                'module' => 'Domain',
                'resource' => 'tenant_domain',
                'resource_id' => $domainId,
                'new_data' => json_encode(['domain_name' => $data['domain_name']]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            $this->logInfo("Domain registered", ['tenant_id' => $tenantId, 'domain' => $data['domain_name']]);

            return $this->success('Domain registered successfully', [
                'domain_id' => $domainId,
                'domain_name' => $data['domain_name'],
                'verification_token' => $verificationToken
            ]);
        } catch (Exception $e) {
            $this->logError('Failed to register domain: ' . $e->getMessage());
            return $this->error('Failed to register domain: ' . $e->getMessage());
        }
    }

    /**
     * Get domains for a tenant
     */
    public function getTenantDomains(int $tenantId): array
    {
        try {
            $domains = $this->domainModel->getByTenant($tenantId);
            return $this->success('Domains retrieved successfully', $domains);
        } catch (Exception $e) {
            $this->logError('Failed to get tenant domains: ' . $e->getMessage());
            return $this->error('Failed to get tenant domains');
        }
    }

    /**
     * Verify a domain
     */
    public function verifyDomain(int $domainId): array
    {
        try {
            $domain = $this->domainModel->find($domainId);
            if (!$domain) {
                return $this->error('Domain not found');
            }

            $result = $this->domainModel->verify($domainId);
            if (!$result) {
                return $this->error('Failed to verify domain');
            }

            // Log audit
            $this->auditModel->log([
                'user_id' => $_SESSION['user_id'] ?? null,
                'tenant_id' => $domain['tenant_id'],
                'action_type' => 'VERIFY_DOMAIN',
                'module' => 'Domain',
                'resource' => 'tenant_domain',
                'resource_id' => $domainId,
                'new_data' => json_encode(['status' => TenantDomain::STATUS_ACTIVE]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            $this->logInfo("Domain verified", ['domain_id' => $domainId]);

            return $this->success('Domain verified successfully');
        } catch (Exception $e) {
            $this->logError('Failed to verify domain: ' . $e->getMessage());
            return $this->error('Failed to verify domain');
        }
    }

    /**
     * Set primary domain
     */
    public function setPrimaryDomain(int $domainId): array
    {
        try {
            $domain = $this->domainModel->find($domainId);
            if (!$domain) {
                return $this->error('Domain not found');
            }

            // Unset current primary
            $this->db->query(
                "UPDATE tenant_domains SET is_primary = 0 WHERE tenant_id = ? AND is_primary = 1",
                [$domain['tenant_id']]
            );

            // Set this as primary
            $result = $this->domainModel->update($domainId, ['is_primary' => 1]);
            if (!$result) {
                return $this->error('Failed to set primary domain');
            }

            $this->logInfo("Primary domain set", ['domain_id' => $domainId]);

            return $this->success('Primary domain set successfully');
        } catch (Exception $e) {
            $this->logError('Failed to set primary domain: ' . $e->getMessage());
            return $this->error('Failed to set primary domain');
        }
    }

    /**
     * Delete domain
     */
    public function deleteDomain(int $domainId): array
    {
        try {
            $domain = $this->domainModel->find($domainId);
            if (!$domain) {
                return $this->error('Domain not found');
            }

            if ($domain['is_primary']) {
                return $this->error('Cannot delete primary domain');
            }

            $result = $this->domainModel->delete($domainId);
            if (!$result) {
                return $this->error('Failed to delete domain');
            }

            $this->logInfo("Domain deleted", ['domain_id' => $domainId]);

            return $this->success('Domain deleted successfully');
        } catch (Exception $e) {
            $this->logError('Failed to delete domain: ' . $e->getMessage());
            return $this->error('Failed to delete domain');
        }
    }

    /**
     * Generate subdomain
     */
    public function generateSubdomain(string $tenantCode): string
    {
        return strtolower($tenantCode) . '.edutrack.com';
    }
}