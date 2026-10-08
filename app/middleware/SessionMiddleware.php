<?php

/**
 * Session Middleware
 * Validates session on every request and enforces authentication
 *
 * @package EduTrack
 * @subpackage Middleware
 * @version 1.0
 * @filepath app/middleware/SessionMiddleware.php
 */

class SessionMiddleware
{
    private $sessionManager;
    private $tenantContext;

    // Public routes that don't require authentication
    private $publicRoutes = [
        '/platform/tenant/login.php',
        '/platform/auth/tenant-login.php',
        '/platform/auth/logout.php',
        '/platform/auth/forgot-password.php',
        '/platform/auth/reset-password.php',
        '/platform/errors/',
        '/errors/'
    ];

    // API public routes
    private $publicApiRoutes = [
        '/api/auth/login',
        '/api/auth/resolve-domain',
        '/api/auth/refresh'
    ];

    public function __construct()
    {
        require_once __DIR__ . '/../services/Auth/SessionManager.php';
        $this->sessionManager = new SessionManager();
        $this->tenantContext = TenantContext::getInstance();
    }

    /**
     * Process the request - validates session
     *
     * @return bool True if valid, false if invalid (handles redirects internally)
     */
    public function handle(): bool
    {
        // Check if current route is public
        if ($this->isPublicRoute()) {
            return true;
        }

        // Check if session is valid
        if (!$this->sessionManager->validateSession()) {
            // Not authenticated - redirect to login
            $this->redirectToLogin();
            return false;
        }

        // Update last activity
        $this->sessionManager->updateLastActivity();

        // Check if session is about to expire (within 5 minutes)
        $remaining = $this->sessionManager->getRemainingTime();
        if ($remaining !== null && $remaining < 300) {
            // Extend session automatically
            $this->sessionManager->extendSession();
        }

        // Restore context from session
        $this->restoreContextFromSession();

        return true;
    }

    /**
     * Check if current route is public
     */
    private function isPublicRoute(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        // Check public routes
        foreach ($this->publicRoutes as $route) {
            if (strpos($path, $route) === 0) {
                return true;
            }
        }

        // Check API public routes
        foreach ($this->publicApiRoutes as $route) {
            if (strpos($path, $route) === 0) {
                return true;
            }
        }

        // Check if it's a static asset
        if ($this->isStaticAsset($path)) {
            return true;
        }

        return false;
    }

    /**
     * Check if path is a static asset
     */
    private function isStaticAsset(string $path): bool
    {
        $extensions = ['css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'ico', 'webp', 'woff', 'woff2', 'ttf', 'eot'];
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        return in_array(strtolower($ext), $extensions);
    }

    /**
     * Redirect to login page
     */
    private function redirectToLogin(): void
    {
        // Check if it's an API request
        if ($this->isApiRequest()) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => 'Session expired or invalid. Please login again.',
                'code' => 'SESSION_EXPIRED'
            ]);
            exit;
        }

        // Store return URL for after login
        $returnUrl = $_SERVER['REQUEST_URI'] ?? '/';
        $returnUrl = urlencode($returnUrl);

        header('Location: /platform/tenant/login.php?return=' . $returnUrl);
        exit;
    }

    /**
     * Check if request is an API request
     */
    private function isApiRequest(): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        return strpos($path, '/api/') === 0 ||
            strpos($path, '/platform/api/') === 0 ||
            !empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
    }

    /**
     * Restore context from session
     */
    private function restoreContextFromSession(): void
    {
        $sessionData = $this->sessionManager->getSessionData();

        if ($sessionData) {
            // Restore tenant context
            $this->tenantContext
                ->setUserId($sessionData['user_id'] ?? null)
                ->setPersonId($sessionData['person_id'] ?? null)
                ->setTenantId($sessionData['tenant_id'] ?? null)
                ->setTenantName($sessionData['tenant_name'] ?? null)
                ->setSchoolId($sessionData['school_id'] ?? null)
                ->setSchoolName($sessionData['school_name'] ?? null)
                ->setCampusId($sessionData['campus_id'] ?? null)
                ->setCampusName($sessionData['campus_name'] ?? null)
                ->setDomainId($sessionData['domain_id'] ?? null)
                ->setDomainName($sessionData['domain_name'] ?? null)
                ->setLoginAudience($sessionData['login_audience'] ?? null)
                ->setUserRoles($sessionData['roles'] ?? [])
                ->setUserPermissions($sessionData['permissions'] ?? []);

            // Set admin level if available
            if (isset($sessionData['admin_level'])) {
                $this->tenantContext->setIsSuperAdmin(
                    $sessionData['admin_level'] === 'super_admin'
                );
            }

            // Store in global
            $GLOBALS['current_user'] = [
                'id' => $sessionData['user_id'],
                'name' => $sessionData['full_name'] ?? $sessionData['username'],
                'email' => $sessionData['email'],
                'roles' => $sessionData['roles'] ?? [],
                'permissions' => $sessionData['permissions'] ?? [],
                'login_audience' => $sessionData['login_audience']
            ];
        }
    }

    /**
     * Get session manager instance
     */
    public function getSessionManager(): SessionManager
    {
        return $this->sessionManager;
    }

    /**
     * Check if user is authenticated
     */
    public function isAuthenticated(): bool
    {
        return $this->sessionManager->isAuthenticated();
    }

    /**
     * Get current user ID
     */
    public function getUserId(): ?int
    {
        return $this->sessionManager->getUserId();
    }

    /**
     * Get current user data
     */
    public function getCurrentUser(): ?array
    {
        return $GLOBALS['current_user'] ?? null;
    }
}
