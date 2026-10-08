<?php

/**
 * Tenant Context Middleware
 * Restores and validates tenant context for each request
 *
 * @package EduTrack
 * @subpackage Middleware
 * @version 2.1
 * @filepath app/middleware/TenantContextMiddleware.php
 *
 * v2.1 change (2026-10-08) [RAILWAY DEPLOY]:
 *   - Read PLATFORM_DOMAIN from the constant defined in config/config.php,
 *     with the previous $_ENV read as fallback. The constant is guaranteed
 *     to be defined after bootstrap because config/config.php is required
 *     first. The $_ENV read was unreliable because bootstrap.php does not
 *     load .env.
 *   - Add '.up.railway.app' to the platform-hostname whitelist so that
 *     requests arriving at a Railway URL skip tenant resolution instead
 *     of trying to look up a tenant_domains row that does not exist.
 *   - Nothing else changed.
 */

class TenantContextMiddleware
{
    private $tenantContext;
    private $domainResolver;
    private $platformDomain;

    public function __construct()
    {
        $this->tenantContext = TenantContext::getInstance();
        require_once __DIR__ . '/../services/Domain/DomainResolver.php';
        $this->domainResolver = new DomainResolver();

        // v2.1: prefer the constant defined by config/config.php.
        if (defined('PLATFORM_DOMAIN')) {
            $this->platformDomain = PLATFORM_DOMAIN;
        } else {
            $this->platformDomain = $_ENV['PLATFORM_DOMAIN'] ?? 'admin.edutrack.local';
        }
    }

    /**
     * Process the request - should be called at the start of each request
     *
     * @return bool True if context is valid, false otherwise
     */
    public function handle(): bool
    {
        // Get hostname
        $hostname = $_SERVER['HTTP_HOST'] ?? '';
        $hostname = strtolower(trim($hostname));
        $hostname = preg_replace('/^www\./', '', $hostname);
        $hostname = preg_replace('/:\d+$/', '', $hostname);

        // Check if this is a platform request
        $isPlatform = $this->isPlatformRequest($hostname);

        // If it's a platform request, skip tenant context validation
        if ($isPlatform) {
            return true;
        }

        // Check if we have a saved context
        if (isset($_SESSION['tenant_context'])) {
            // Restore context from session
            $this->tenantContext->restoreFromSession();

            // Verify the context matches the current domain
            if ($this->tenantContext->getDomainName() !== $hostname) {
                // Domain changed - re-resolve
                error_log("TenantContext: Domain changed from {$this->tenantContext->getDomainName()} to $hostname");
                return $this->resolveFromDomain($hostname);
            }

            // Verify tenant is still active
            if (!$this->verifyTenantActive()) {
                error_log("TenantContext: Tenant {$this->tenantContext->getTenantId()} is no longer active");
                return false;
            }

            return true;
        }

        // No saved context - resolve from domain
        return $this->resolveFromDomain($hostname);
    }

    /**
     * Resolve tenant context from domain
     */
    private function resolveFromDomain(string $hostname): bool
    {
        $context = $this->domainResolver->resolveFromHostname($hostname);

        if (!$context || isset($context['error'])) {
            return false;
        }

        // Set context
        $this->tenantContext
            ->setTenantId($context['tenant_id'])
            ->setTenantName($context['tenant_name'])
            ->setTenantCode($context['tenant_code'] ?? null)
            ->setSchoolId($context['school_id'])
            ->setSchoolName($context['school_name'])
            ->setSchoolCode($context['school_code'] ?? null)
            ->setCampusId($context['campus_id'])
            ->setCampusName($context['campus_name'])
            ->setDomainId($context['domain_id'])
            ->setDomainName($context['domain_name'])
            ->setDomainType($context['domain_type'])
            ->setIsVerified($context['is_verified'])
            ->setScope($context['school_id'] ? 'school' : 'tenant');

        // Load settings
        $this->tenantContext->loadTenantSettings();
        $this->tenantContext->loadSchoolSettings();

        // Save to session
        $this->tenantContext->saveToSession();

        // Store in global for easy access
        $GLOBALS['current_tenant_context'] = $this->tenantContext->getContext();

        return true;
    }

    /**
     * Verify tenant is still active
     */
    private function verifyTenantActive(): bool
    {
        $tenantId = $this->tenantContext->getTenantId();

        if (!$tenantId) {
            return false;
        }

        try {
            $db = DatabaseHelper::getInstance();
            $status = $db->getValue(
                "SELECT status FROM tenants WHERE id = ? AND deleted_at IS NULL",
                [$tenantId]
            );

            return $status === 'active';
        } catch (Exception $e) {
            error_log('Error verifying tenant status: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if the request is for the platform domain
     */
    private function isPlatformRequest(string $hostname): bool
    {
        $platformDomains = [
            $this->platformDomain,
            'localhost',
            '127.0.0.1',
            '::1',
            '.up.railway.app',   // v2.1: Railway hostnames
        ];

        foreach ($platformDomains as $domain) {
            if ($hostname === $domain || strpos($hostname, $domain) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate that the request context matches the authenticated user
     * Prevents cross-tenant access
     */
    public function validateUserContext(): bool
    {
        // If not authenticated, skip
        if (!$this->tenantContext->isAuthenticated()) {
            return true;
        }

        $tenantId = $this->tenantContext->getTenantId();
        $userId = $this->tenantContext->getUserId();

        try {
            $db = DatabaseHelper::getInstance();

            // Verify user belongs to this tenant
            $userTenant = $db->getValue(
                "SELECT tenant_id FROM platform_users WHERE id = ? AND deleted_at IS NULL",
                [$userId]
            );

            if ($userTenant != $tenantId) {
                error_log("Context validation failed: User $userId does not belong to tenant $tenantId");
                return false;
            }

            // If school context is set, verify user has access to this school
            $schoolId = $this->tenantContext->getSchoolId();
            if ($schoolId) {
                $hasSchoolAccess = $db->getValue(
                    "SELECT COUNT(*) FROM user_school_access WHERE user_id = ? AND school_id = ?",
                    [$userId, $schoolId]
                );

                if (!$hasSchoolAccess) {
                    // Check if user has tenant-wide access
                    $isTenantAdmin = $this->tenantContext->hasRole('tenant_admin');
                    if (!$isTenantAdmin) {
                        error_log("Context validation failed: User $userId does not have access to school $schoolId");
                        return false;
                    }
                }
            }

            return true;
        } catch (Exception $e) {
            error_log('Error validating user context: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Handle invalid tenant context
     */
    public function handleInvalidContext(): void
    {
        // Clear any existing context
        $this->tenantContext->clear();
        unset($_SESSION['tenant_context']);

        // Redirect to domain error
        http_response_code(404);
        require_once __DIR__ . '/../public/errors/domain_not_found.php';
        exit;
    }
}
