<?php

/**
 * Bootstrap Context
 * Initializes tenant context on every request
 *
 * @package EduTrack
 * @subpackage Bootstrap
 * @version 1.0
 * @filepath bootstrap/context.php
 */

// Load required classes
require_once __DIR__ . '/../app/services/Tenant/TenantContext.php';
require_once __DIR__ . '/../app/middleware/TenantContextMiddleware.php';
require_once __DIR__ . '/../app/helpers/TenantContextHelper.php';

// Initialize tenant context middleware
$contextMiddleware = new TenantContextMiddleware();

// Handle the request
$valid = $contextMiddleware->handle();

if (!$valid) {
    $contextMiddleware->handleInvalidContext();
}

// If user is authenticated, validate user context
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if (!$contextMiddleware->validateUserContext()) {
        // Clear session and redirect to login
        session_destroy();
        header('Location: /platform/tenant/login.php');
        exit;
    }
}

// Store context for global access
$GLOBALS['tenant_context'] = tenant()->getContext();
$GLOBALS['tenant_public_context'] = tenant()->getPublicContext();
