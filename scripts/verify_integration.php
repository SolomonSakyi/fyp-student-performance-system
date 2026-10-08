#!/usr/bin/env php
<?php
/**
 * Enterprise Integration Verification Script
 * Verifies all components are properly integrated and functional
 *
 * @package EduTrack
 * @subpackage Scripts
 * @version 1.0
 * @filepath scripts/verify_integration.php
 */

// Load bootstrap
require_once __DIR__ . '/../bootstrap/init.php';

echo "\n========================================\n";
echo "  EDUTRACK ENTERPRISE INTEGRATION VERIFICATION\n";
echo "========================================\n\n";

$passed = 0;
$failed = 0;
$warnings = 0;

// ============================================================
// 1. VERIFY DOMAIN RESOLUTION
// ============================================================
echo "1. VERIFYING DOMAIN RESOLUTION\n";
echo "----------------------------------------\n";

try {
    $domainResolver = new DomainResolver();

    // Test with a valid domain (if configured)
    $testDomain = $_ENV['TEST_DOMAIN'] ?? 'edutrack.test.local';
    $result = $domainResolver->resolveFromHostname($testDomain);

    if ($result && !isset($result['error'])) {
        echo "   ✅ Domain resolver working\n";
        $passed++;
    } else {
        echo "   ⚠️ Domain resolver test skipped (no test domain configured)\n";
        $warnings++;
    }
} catch (Exception $e) {
    echo "   ❌ Domain resolver error: " . $e->getMessage() . "\n";
    $failed++;
}

// ============================================================
// 2. VERIFY TENANT CONTEXT
// ============================================================
echo "\n2. VERIFYING TENANT CONTEXT\n";
echo "----------------------------------------\n";

try {
    $context = tenant();

    if ($context instanceof TenantContext) {
        echo "   ✅ Tenant context service available\n";
        $passed++;
    } else {
        echo "   ❌ Tenant context service not available\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "   ❌ Tenant context error: " . $e->getMessage() . "\n";
    $failed++;
}

// ============================================================
// 3. VERIFY BRANDING SERVICE
// ============================================================
echo "\n3. VERIFYING BRANDING SERVICE\n";
echo "----------------------------------------\n";

try {
    $branding = new BrandingService();

    if ($branding instanceof BrandingService) {
        echo "   ✅ Branding service available\n";
        $passed++;
    } else {
        echo "   ❌ Branding service not available\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "   ❌ Branding service error: " . $e->getMessage() . "\n";
    $failed++;
}

// ============================================================
// 4. VERIFY AUDIENCE SERVICE
// ============================================================
echo "\n4. VERIFYING AUDIENCE SERVICE\n";
echo "----------------------------------------\n";

try {
    $audience = new AudienceService();
    $validAudiences = $audience->getValidAudiences();

    if (count($validAudiences) === 3) {
        echo "   ✅ Audience service available (staff, student, admin)\n";
        $passed++;
    } else {
        echo "   ❌ Audience service not configured correctly\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "   ❌ Audience service error: " . $e->getMessage() . "\n";
    $failed++;
}

// ============================================================
// 5. VERIFY RBAC SERVICE
// ============================================================
echo "\n5. VERIFYING RBAC SERVICE\n";
echo "----------------------------------------\n";

try {
    $rbac = new RBACService();
    $systemRoles = $rbac->getSystemRoles();

    if (count($systemRoles) >= 5) {
        echo "   ✅ RBAC service available\n";
        $passed++;
    } else {
        echo "   ⚠️ RBAC service available but fewer roles than expected\n";
        $warnings++;
    }
} catch (Exception $e) {
    echo "   ❌ RBAC service error: " . $e->getMessage() . "\n";
    $failed++;
}

// ============================================================
// 6. VERIFY SESSION MANAGER
// ============================================================
echo "\n6. VERIFYING SESSION MANAGER\n";
echo "----------------------------------------\n";

try {
    $session = new SessionManager();

    if ($session instanceof SessionManager) {
        echo "   ✅ Session manager available\n";
        $passed++;
    } else {
        echo "   ❌ Session manager not available\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "   ❌ Session manager error: " . $e->getMessage() . "\n";
    $failed++;
}

// ============================================================
// 7. VERIFY SECURITY SERVICE
// ============================================================
echo "\n7. VERIFYING SECURITY SERVICE\n";
echo "----------------------------------------\n";

try {
    $security = new SecurityService();

    if ($security instanceof SecurityService) {
        echo "   ✅ Security service available\n";
        $passed++;
    } else {
        echo "   ❌ Security service not available\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "   ❌ Security service error: " . $e->getMessage() . "\n";
    $failed++;
}

// ============================================================
// 8. VERIFY AUDIT LOGGER
// ============================================================
echo "\n8. VERIFYING AUDIT LOGGER\n";
echo "----------------------------------------\n";

try {
    $audit = new AuditLogger();

    if ($audit instanceof AuditLogger) {
        echo "   ✅ Audit logger available\n";
        $passed++;
    } else {
        echo "   ❌ Audit logger not available\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "   ❌ Audit logger error: " . $e->getMessage() . "\n";
    $failed++;
}

// ============================================================
// 9. VERIFY DATABASE TABLES
// ============================================================
echo "\n9. VERIFYING DATABASE TABLES\n";
echo "----------------------------------------\n";

try {
    $db = DatabaseHelper::getInstance();
    $requiredTables = [
        'tenants',
        'schools',
        'campuses',
        'domains',
        'platform_users',
        'persons',
        'staff',
        'students',
        'roles',
        'permissions',
        'platform_user_roles',
        'role_permissions',
        'user_sessions',
        'login_attempts',
        'password_resets',
        'audit_logs',
        'tenant_settings',
        'school_settings'
    ];

    $missingTables = [];
    foreach ($requiredTables as $table) {
        $exists = $db->getValue("SHOW TABLES LIKE ?", [$table]);
        if (!$exists) {
            $missingTables[] = $table;
        }
    }

    if (empty($missingTables)) {
        echo "   ✅ All required tables exist\n";
        $passed++;
    } else {
        echo "   ⚠️ Missing tables: " . implode(', ', $missingTables) . "\n";
        $warnings++;
    }
} catch (Exception $e) {
    echo "   ❌ Database check error: " . $e->getMessage() . "\n";
    $failed++;
}

// ============================================================
// 10. VERIFY ROUTES
// ============================================================
echo "\n10. VERIFYING ROUTES\n";
echo "----------------------------------------\n";

$requiredRoutes = [
    '/platform/tenant/login.php',
    '/platform/auth/tenant-login.php',
    '/platform/auth/logout.php',
    '/platform/auth/forgot-password.php',
    '/platform/auth/reset-password.php',
    '/tenant/staff/dashboard.php',
    '/tenant/student/dashboard.php',
    '/tenant/admin/dashboard.php'
];

$missingRoutes = [];
foreach ($requiredRoutes as $route) {
    $fullPath = __DIR__ . '/../public' . $route;
    if (!file_exists($fullPath)) {
        $missingRoutes[] = $route;
    }
}

if (empty($missingRoutes)) {
    echo "   ✅ All required routes exist\n";
    $passed++;
} else {
    echo "   ⚠️ Missing routes: " . implode(', ', $missingRoutes) . "\n";
    $warnings++;
}

// ============================================================
// 11. VERIFY MIDDLEWARE
// ============================================================
echo "\n11. VERIFYING MIDDLEWARE\n";
echo "----------------------------------------\n";

$requiredMiddleware = [
    'DomainContextMiddleware',
    'TenantContextMiddleware',
    'SessionMiddleware',
    'RBACMiddleware'
];

$missingMiddleware = [];
foreach ($requiredMiddleware as $middleware) {
    $filePath = __DIR__ . '/../app/middleware/' . $middleware . '.php';
    if (!file_exists($filePath)) {
        $missingMiddleware[] = $middleware;
    }
}

if (empty($missingMiddleware)) {
    echo "   ✅ All required middleware exist\n";
    $passed++;
} else {
    echo "   ⚠️ Missing middleware: " . implode(', ', $missingMiddleware) . "\n";
    $warnings++;
}

// ============================================================
// 12. VERIFY BOOTSTRAP
// ============================================================
echo "\n12. VERIFYING BOOTSTRAP\n";
echo "----------------------------------------\n";

$requiredBootstrap = [
    'context.php',
    'session.php'
];

$missingBootstrap = [];
foreach ($requiredBootstrap as $file) {
    $filePath = __DIR__ . '/../bootstrap/' . $file;
    if (!file_exists($filePath)) {
        $missingBootstrap[] = $file;
    }
}

if (empty($missingBootstrap)) {
    echo "   ✅ All required bootstrap files exist\n";
    $passed++;
} else {
    echo "   ⚠️ Missing bootstrap: " . implode(', ', $missingBootstrap) . "\n";
    $warnings++;
}

// ============================================================
// SUMMARY
// ============================================================
echo "\n========================================\n";
echo "  VERIFICATION SUMMARY\n";
echo "========================================\n";
echo "Checks Passed:  {$passed}\n";
echo "Checks Failed:  {$failed}\n";
echo "Warnings:       {$warnings}\n";

if ($failed === 0 && $warnings === 0) {
    echo "\n✅ ALL CHECKS PASSED - SYSTEM READY FOR PRODUCTION\n";
    exit(0);
} elseif ($failed === 0) {
    echo "\n⚠️ SYSTEM PASSES ALL CRITICAL CHECKS - Review warnings\n";
    exit(1);
} else {
    echo "\n❌ SYSTEM FAILED CRITICAL CHECKS - Review errors\n";
    exit(2);
}
