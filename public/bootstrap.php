<?php

/**
 * bootstrap.php
 * Application bootstrap - load middleware
 * 
 * @filepath public/index.php or app/bootstrap.php
 */

// ============================================================
// LOAD TENANT MIDDLEWARE
// ============================================================
require_once __DIR__ . '/../app/middleware/TenantMiddleware.php';

// Initialize and handle tenant resolution
$tenantMiddleware = new TenantMiddleware();
$tenantMiddleware->handle();

// ============================================================
// ROUTE BASED ON TENANT CONTEXT
// ============================================================
$isPlatform = $_SESSION['is_platform_request'] ?? false;
$tenantId = $_SESSION['tenant_id'] ?? null;

if ($isPlatform || $tenantId === null) {
    // Route to platform (Super Admin) code
    require_once __DIR__ . '/platform/routes.php';
} else {
    // Route to tenant-specific code
    require_once __DIR__ . '/tenant/routes.php';
}
