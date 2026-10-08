<?php

/**
 * Domain Context Middleware
 * Resolves tenant context from domain before request processing
 *
 * @package EduTrack
 * @subpackage Middleware
 * @version 1.0
 * @filepath app/middleware/DomainContextMiddleware.php
 */

class DomainContextMiddleware
{
    private $domainResolver;
    private $tenantContext;
    private $platformDomain;

    public function __construct()
    {
        require_once __DIR__ . '/../services/Domain/DomainResolver.php';
        $this->domainResolver = new DomainResolver();
        $this->tenantContext = TenantContext::getInstance();

        // Platform domain from environment or default
        $this->platformDomain = $_ENV['PLATFORM_DOMAIN'] ?? 'admin.edutrack.local';
        // Also check for localhost/dev
        $this->platformDomains = [
            $this->platformDomain,
            'localhost',
            '127.0.0.1',
            '::1'
        ];
    }

    /**
     * Process the request - should be called at the start of each request
     */
    public function handle(): bool
    {
        // Get hostname
        $hostname = $_SERVER['HTTP_HOST'] ?? '';
        $hostname = strtolower(trim($hostname));

        // Remove port if present
        $hostname = preg_replace('/:\d+$/', '', $hostname);

        // Skip for platform domain
        if ($this->isPlatformDomain($hostname)) {
            return true;
        }

        // Resolve domain
        $context = $this->domainResolver->resolveFromHostname($hostname);

        // Domain not found or invalid
        if (!$context || isset($context['error'])) {
            $this->handleInvalidDomain($hostname, $context);
            return false;
        }

        // Store context in TenantContext
        $this->tenantContext->setTenantId($context['tenant_id']);
        $this->tenantContext->setTenantName($context['tenant_name']);
        $this->tenantContext->setSchoolId($context['school_id']);
        $this->tenantContext->setSchoolName($context['school_name']);
        $this->tenantContext->setCampusId($context['campus_id']);
        $this->tenantContext->setCampusName($context['campus_name']);
        $this->tenantContext->setDomainId($context['domain_id']);
        $this->tenantContext->setDomainName($context['domain_name']);
        $this->tenantContext->setDomainType($context['domain_type']);
        $this->tenantContext->setIsVerified($context['is_verified']);

        // Store in session for quick access
        $_SESSION['tenant_context'] = $context;

        // Also set as global for easy access
        $GLOBALS['current_tenant_context'] = $context;

        return true;
    }

    /**
     * Check if hostname is platform domain
     */
    private function isPlatformDomain(string $hostname): bool
    {
        foreach ($this->platformDomains as $platform) {
            if ($hostname === $platform || strpos($hostname, $platform) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Handle invalid domain
     */
    private function handleInvalidDomain(string $hostname, ?array $context)
    {
        // Determine error type
        $errorType = 'domain_not_found';
        $errorMessage = 'The domain you are trying to access could not be found.';

        if ($context && isset($context['error'])) {
            switch ($context['error']) {
                case 'domain_inactive':
                    $errorType = 'domain_inactive';
                    $errorMessage = 'This school portal is currently unavailable.';
                    break;
                case 'domain_not_verified':
                    $errorType = 'domain_not_verified';
                    $errorMessage = 'This domain has not been verified.';
                    break;
                case 'tenant_inactive':
                    $errorType = 'tenant_inactive';
                    $errorMessage = 'This school portal is temporarily unavailable. Please contact the administrator.';
                    break;
            }
        }

        // Log the error
        error_log("Domain Resolution Failed: $hostname - $errorType");

        // Set response headers
        http_response_code(404);

        // Include error page
        $errorFile = __DIR__ . '/../public/errors/domain_' . $errorType . '.php';
        if (file_exists($errorFile)) {
            require_once $errorFile;
        } else {
            require_once __DIR__ . '/../public/errors/domain_not_found.php';
        }

        exit;
    }
}
