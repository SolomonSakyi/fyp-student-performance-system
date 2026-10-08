<?php

/**
 * TenantMiddleware.php
 * Middleware to enforce tenant context on every request
 * 
 * @package EduTrack
 * @subpackage Middleware
 * @filepath app/middleware/TenantMiddleware.php
 */

class TenantMiddleware
{
    private $resolver;
    private $context;
    private $db;

    public function __construct()
    {
        $this->resolver = new DomainResolver();
        $this->context = TenantContext::getInstance();
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Handle the request - set tenant context
     */
    public function handle(): void
    {
        // Resolve tenant from domain
        $tenantData = $this->resolver->getTenantData();

        // If this is a platform domain (no tenant)
        if ($this->resolver->isPlatformRequest()) {
            $_SESSION['is_platform_request'] = true;
            $_SESSION['tenant_id'] = null;
            return;
        }

        // If no tenant found for this domain
        if (!$tenantData) {
            // Redirect to platform or show 404
            $this->handleNoTenant();
            return;
        }

        // Set tenant context in session
        $_SESSION['tenant_id'] = $tenantData['tenant_id'];
        $_SESSION['tenant_code'] = $tenantData['tenant_code'];
        $_SESSION['tenant_name'] = $tenantData['tenant_name'];
        $_SESSION['domain_type'] = $tenantData['domain_type'];
        $_SESSION['is_platform_request'] = false;

        // Initialize TenantContext
        $this->context->initialize();

        // Log the domain resolution
        $this->logDomainResolution($tenantData);
    }

    /**
     * Handle case where no tenant is found for the domain
     */
    private function handleNoTenant(): void
    {
        $host = $_SERVER['HTTP_HOST'];

        // Check if this looks like a tenant subdomain that doesn't exist
        if (strpos($host, '.edutrack.local') !== false) {
            // Show tenant not found page
            http_response_code(404);
            include BASE_PATH . '/public/errors/tenant_not_found.php';
            exit;
        }

        // Otherwise redirect to platform
        header('Location: https://admin.edutrack.local/platform/login.php');
        exit;
    }

    /**
     * Log domain resolution for audit
     */
    private function logDomainResolution(array $tenantData): void
    {
        $sql = "INSERT INTO audit_logs (
                    tenant_id, action, ip_address, user_agent, 
                    details, created_at
                ) VALUES (?, ?, ?, ?, ?, NOW())";

        $this->db->execute($sql, [
            $tenantData['tenant_id'],
            'domain_resolved',
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'],
            json_encode([
                'domain' => $_SERVER['HTTP_HOST'],
                'domain_type' => $tenantData['domain_type'],
                'resolved_to' => $tenantData['tenant_name']
            ])
        ]);
    }
}
