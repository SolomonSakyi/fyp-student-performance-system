<?php
require_once __DIR__ . '/_fatal_catch.php';

/**
 * Tenant Dashboard - Main landing page for tenant users
 * 
 * This page is ONLY accessible to Tenant Admin users.
 * Super Admin users are redirected to the platform dashboard.
 * All data is isolated to the specific tenant.
 * 
 * @package EduTrack
 * @subpackage Platform\Tenant
 * @version 1.0
 * @filepath public/platform/tenant/dashboard.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Dashboard file of the tenant-surface sweep. Three changes:
 *     - The hardcoded brand literal in the sidebar header
 *       changed from 'EduTrack' to 'Student 360'.
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Tenant' to 'Student 360 Tenant'.
 *     - The @version tag was unified to 1.0.
 *   The inline sidebar is kept as-is. This is the only page
 *   in the tenant surface that renders the school crest
 *   (the conditional <img class="school-crest">) and the
 *   school-scoped sidebar footer. The shared partial does not
 *   carry the crest; replacing the inline sidebar with the
 *   partial would remove it. The sidebar's nav items match the
 *   partial's items, but the header keeps the crest. This is a
 *   deliberate divergence, recorded here.
 *   Every other line of the file is byte-identical to v2.1.
 *
 * v2.1 changes [SCHOOL-IDENTITY]:
 *  - The page reads its identity from $_SESSION['school_name'] and
 *    $_SESSION['school_logo'] instead of the v2.0 fallback
 *    $_SESSION['tenant_name'] ?? 'My Organization'.
 *  - Adds a conditional school crest <img> in the sidebar header
 *    (rendered only when $_SESSION['school_logo'] is not null).
 *  - Adds a conditional <link rel="icon"> in <head> (same condition).
 *  - Rewrites the welcome paragraph copy from "tenant dashboard ...
 *    Manage your schools, staff, and students" to a school-scoped
 *    sentence that reads the school name.
 *  - Every stats query, list, card, and script is unchanged from v2.0.
 */

// =============================================
// SESSION START
// =============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =============================================
// AUTHENTICATION CHECK
// =============================================

// 1. Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/tenant/login.php');
    exit;
}

// 2. IMPORTANT: Check if user is Super Admin
//    If Super Admin, redirect to platform dashboard
if (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] === true) {
    header('Location: /platform/index.php');
    exit;
}

// 3. Check if user has a tenant
if (!isset($_SESSION['tenant_id']) || $_SESSION['tenant_id'] == 0) {
    // If no tenant, logout and redirect to login
    session_destroy();
    header('Location: /platform/tenant/login.php?error=no_tenant');
    exit;
}

// =============================================
// [SCHOOL-IDENTITY] SCHOOL IDENTITY FROM SESSION
// =============================================
// The school name and crest are populated by login.php v2.2 from
// the resolved tenant_domains -> schools row. On a school-scoped
// page there is no tenant name and no fallback to "My Organization".
// If the key is missing (older session, direct include), we fall
// back to a plain 'School' literal so the page does not lie.
// =============================================
$schoolName = $_SESSION['school_name'] ?? 'School';
$schoolLogo = $_SESSION['school_logo'] ?? null;

// =============================================
// PAGE SETUP
// =============================================
$pageTitle = 'Dashboard - Student 360 Tenant';
$currentPage = 'dashboard';

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];

// =============================================
// LOAD CONFIG AND DATABASE HELPER
// =============================================
$projectRoot = dirname(__DIR__, 3);

if (file_exists($projectRoot . '/config/config.php')) {
    require_once $projectRoot . '/config/config.php';
} else {
    die('config.php not found!');
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';

try {
    $db = DatabaseHelper::getInstance();
} catch (Exception $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// =============================================
// DISPLAY SUCCESS MESSAGE FROM SESSION
// =============================================
$successMessage = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);

// =============================================
// GET TENANT DETAILS
// =============================================
$tenantId = (int)$_SESSION['tenant_id'];

// Verify tenant exists and belongs to this user
$tenantCheck = $db->getValue(
    "SELECT COUNT(*) FROM tenants WHERE id = ? AND deleted_at IS NULL",
    [$tenantId]
);

if ($tenantCheck == 0) {
    // Tenant doesn't exist or was deleted
    session_destroy();
    header('Location: /platform/tenant/login.php?error=tenant_not_found');
    exit;
}

// =============================================
// GET TENANT-SPECIFIC STATS (ISOLATED)
// =============================================

// Total Schools
$totalSchools = $db->getValue(
    "SELECT COUNT(*) FROM schools WHERE tenant_id = ? AND deleted_at IS NULL",
    [$tenantId]
) ?? 0;

// Active Schools
$activeSchools = $db->getValue(
    "SELECT COUNT(*) FROM schools WHERE tenant_id = ? AND deleted_at IS NULL AND status = 'active'",
    [$tenantId]
) ?? 0;

// Total Staff
$totalStaff = $db->getValue(
    "SELECT COUNT(*) FROM staff s 
     JOIN persons p ON s.person_id = p.id 
     WHERE s.tenant_id = ? AND s.deleted_at IS NULL AND p.deleted_at IS NULL",
    [$tenantId]
) ?? 0;

// Total Students
$totalStudents = $db->getValue(
    "SELECT COUNT(*) FROM students WHERE tenant_id = ? AND deleted_at IS NULL",
    [$tenantId]
) ?? 0;

// Active Students
$activeStudents = $db->getValue(
    "SELECT COUNT(*) FROM students WHERE tenant_id = ? AND deleted_at IS NULL AND enrollment_status = 'Active'",
    [$tenantId]
) ?? 0;

// Total Users
$totalUsers = $db->getValue(
    "SELECT COUNT(*) FROM platform_users WHERE tenant_id = ? AND deleted_at IS NULL",
    [$tenantId]
) ?? 0;

// Total Campuses
$totalCampuses = $db->getValue(
    "SELECT COUNT(*) FROM campuses c 
     JOIN schools s ON c.school_id = s.id 
     WHERE s.tenant_id = ? AND c.deleted_at IS NULL",
    [$tenantId]
) ?? 0;

// =============================================
// GET SUBSCRIPTION INFORMATION
// =============================================
$subscription = $db->fetchOne(
    "SELECT ts.*, sp.plan_name, sp.plan_code, sp.price, sp.max_schools, sp.max_campuses, 
            sp.max_staff, sp.max_students, sp.max_storage_mb, sp.max_api_calls, 
            sp.max_ai_requests, sp.sms_balance, sp.email_balance
     FROM tenant_subscriptions ts
     JOIN subscription_plans sp ON ts.plan_id = sp.id
     WHERE ts.tenant_id = ? AND ts.status IN ('active', 'trial')
     AND (ts.deleted_at IS NULL OR ts.deleted_at = '')
     ORDER BY ts.created_at DESC LIMIT 1",
    [$tenantId]
);

$planName = $subscription['plan_name'] ?? 'Free';
$planCode = $subscription['plan_code'] ?? 'FREE';
$planPrice = $subscription['price'] ?? 0;
$planStatus = $subscription['status'] ?? 'active';

// Calculate usage percentages
$usagePercentages = [];
$usageLimits = [];

// Schools
$maxSchools = $subscription['max_schools'] ?? 0;
$usageLimits['schools'] = $maxSchools;
$usagePercentages['schools'] = $maxSchools > 0 ? min(100, round(($totalSchools / $maxSchools) * 100)) : 0;

// Staff
$maxStaff = $subscription['max_staff'] ?? 0;
$usageLimits['staff'] = $maxStaff;
$usagePercentages['staff'] = $maxStaff > 0 ? min(100, round(($totalStaff / $maxStaff) * 100)) : 0;

// Students
$maxStudents = $subscription['max_students'] ?? 0;
$usageLimits['students'] = $maxStudents;
$usagePercentages['students'] = $maxStudents > 0 ? min(100, round(($totalStudents / $maxStudents) * 100)) : 0;

// Determine if any limit is nearing or exceeded
$hasWarning = false;
$hasDanger = false;
foreach ($usagePercentages as $key => $percentage) {
    if ($usageLimits[$key] > 0) {
        if ($percentage >= 90) {
            $hasDanger = true;
        } elseif ($percentage >= 70) {
            $hasWarning = true;
        }
    }
}

// Determine plan status badge color
$planStatusBadge = 'success';
if ($planStatus === 'trial') {
    $planStatusBadge = 'warning';
} elseif ($planStatus === 'ended' || $planStatus === 'cancelled') {
    $planStatusBadge = 'danger';
}

// =============================================
// GET PENDING REQUESTS (TENANT-SPECIFIC)
// =============================================
$pendingRequests = 0;
try {
    $pendingRequests = $db->getValue(
        "SELECT COUNT(*) FROM school_requests 
         WHERE tenant_id = ? AND status = 'pending' AND (deleted_at IS NULL OR deleted_at = '')",
        [$tenantId]
    ) ?? 0;
} catch (Exception $e) {
    $pendingRequests = 0;
}

// =============================================
// GET UNREAD NOTIFICATIONS
// =============================================
$unreadNotifications = 0;
try {
    // Check if notifications table exists
    $tableExists = $db->getValue(
        "SELECT COUNT(*) FROM information_schema.tables 
         WHERE table_schema = ? AND table_name = 'notifications'",
        [DB_NAME]
    );

    if ($tableExists > 0) {
        $unreadNotifications = $db->getValue(
            "SELECT COUNT(*) FROM notifications 
             WHERE user_id = ? AND is_read = 0",
            [$_SESSION['user_id']]
        ) ?? 0;
    }
} catch (Exception $e) {
    $unreadNotifications = 0;
}

// =============================================
// GET RECENT ACTIVITY (TENANT-SPECIFIC)
// =============================================
$recentActivity = $db->fetchAll(
    "SELECT 
        'school' as type, 
        school_name as name, 
        created_at 
    FROM schools 
    WHERE tenant_id = ? AND deleted_at IS NULL
    
    UNION ALL
    
    SELECT 
        'staff' as type, 
        CONCAT(p.first_name, ' ', p.last_name) as name, 
        s.created_at 
    FROM staff s
    JOIN persons p ON s.person_id = p.id
    WHERE s.tenant_id = ? AND s.deleted_at IS NULL AND p.deleted_at IS NULL
    
    UNION ALL
    
    SELECT 
        'student' as type, 
        CONCAT(first_name, ' ', last_name) as name, 
        created_at 
    FROM students 
    WHERE tenant_id = ? AND deleted_at IS NULL
    
    ORDER BY created_at DESC 
    LIMIT 10",
    [$tenantId, $tenantId, $tenantId]
);

// =============================================
// GET RECENT NOTIFICATIONS
// =============================================
$recentNotifications = [];
try {
    $tableExists = $db->getValue(
        "SELECT COUNT(*) FROM information_schema.tables 
         WHERE table_schema = ? AND table_name = 'notifications'",
        [DB_NAME]
    );

    if ($tableExists > 0) {
        $recentNotifications = $db->fetchAll(
            "SELECT * FROM notifications 
             WHERE user_id = ? 
             ORDER BY created_at DESC 
             LIMIT 5",
            [$_SESSION['user_id']]
        );
    }
} catch (Exception $e) {
    $recentNotifications = [];
}

// =============================================
// GREETING
// =============================================
$greeting = 'Good Morning';
$hour = date('H');
if ($hour >= 12 && $hour < 17) {
    $greeting = 'Good Afternoon';
} elseif ($hour >= 17) {
    $greeting = 'Good Evening';
}

// =============================================
// CURRENT USER
// =============================================
$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userFirstName = $_SESSION['first_name'] ?? 'User';
$userAvatar = strtoupper(substr($userFirstName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?php /* [SCHOOL-IDENTITY] School crest as favicon when one exists. */ ?>
    <?php if (!empty($schoolLogo)): ?>
        <link rel="icon" href="<?php echo htmlspecialchars($schoolLogo); ?>">
    <?php endif; ?>
    <style>
        /* ================================================ */
        /* GLOBAL RESET */
        /* ================================================ */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            overflow-x: hidden !important;
            width: 100%;
            max-width: 100%;
            background: #f0f2f5;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            line-height: 1.6;
            color: #1a1a2e;
        }

        .container-fluid {
            padding: 0;
            margin: 0;
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
        }

        .row {
            margin: 0;
            width: 100%;
            max-width: 100%;
        }

        [class*="col-"] {
            padding-left: 12px;
            padding-right: 12px;
        }

        /* ================================================ */
        /* SIDEBAR */
        /* ================================================ */
        .sidebar-toggle {
            display: none;
            position: fixed;
            top: 14px;
            left: 14px;
            z-index: 1001;
            background: #1a1a2e;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 22px;
            cursor: pointer;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.2);
        }

        .sidebar-toggle:hover {
            background: #2a2a4e;
        }

        .sidebar {
            min-height: 100vh;
            background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
            color: #fff;
            position: fixed;
            width: 260px;
            left: 0;
            top: 0;
            z-index: 1000;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            overflow-y: auto;
            padding: 0;
        }

        .sidebar .sidebar-header {
            padding: 25px 24px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        /* [SCHOOL-IDENTITY] School crest in the sidebar header. */
        .sidebar .sidebar-header .school-crest {
            max-height: 40px;
            max-width: 40px;
            margin-bottom: 10px;
            display: block;
        }

        .sidebar .sidebar-header h4 {
            font-weight: 700;
            font-size: 20px;
            margin: 0;
        }

        .sidebar .sidebar-header h4 i {
            color: #4facfe;
        }

        .sidebar .sidebar-header small {
            color: rgba(255, 255, 255, 0.4);
            font-size: 12px;
        }

        .sidebar .nav {
            padding: 16px 12px;
        }

        .sidebar .nav-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.3);
            padding: 0 12px 8px;
            font-weight: 600;
        }

        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.6);
            padding: 10px 16px;
            border-radius: 10px;
            margin: 2px 0;
            transition: all 0.3s;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            text-decoration: none;
        }

        .sidebar .nav-link:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
        }

        .sidebar .nav-link.active {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: #fff;
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.3);
        }

        .sidebar .nav-link i {
            width: 22px;
            text-align: center;
            margin-right: 12px;
            font-size: 15px;
        }

        .sidebar .sidebar-footer {
            position: absolute;
            bottom: 0;
            width: 100%;
            padding: 20px 24px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(0, 0, 0, 0.2);
        }

        .sidebar .sidebar-footer .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar .sidebar-footer .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            color: #fff;
            flex-shrink: 0;
        }

        .sidebar .sidebar-footer .user-name {
            font-weight: 600;
            font-size: 14px;
        }

        .sidebar .sidebar-footer .user-role {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.4);
        }

        .sidebar .sidebar-footer .logout-btn {
            color: rgba(255, 255, 255, 0.4);
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            font-size: 14px;
        }

        .sidebar .sidebar-footer .logout-btn:hover {
            color: #ff6b6b;
        }

        .main-content {
            margin-left: 260px;
            padding: 24px 32px 40px;
            background: #f0f2f5;
            min-height: 100vh;
            width: calc(100% - 260px);
            max-width: 100%;
            overflow-x: hidden;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 0 20px 0;
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-bar .page-title h1 {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2e;
            margin: 0;
        }

        .top-bar .page-title p {
            color: #6c757d;
            margin: 0;
            font-size: 14px;
        }

        .top-bar .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .top-bar .header-actions .btn {
            border-radius: 10px;
            padding: 8px 18px;
            font-weight: 500;
            font-size: 14px;
        }

        .top-bar .header-actions .btn i {
            margin-right: 6px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border: none;
            color: #fff;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 172, 254, 0.4);
            color: #fff;
        }

        .btn-outline-secondary {
            background: transparent;
            border: 2px solid #e9ecef;
            color: #6c757d;
        }

        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: #ced4da;
        }

        /* ================================================ */
        /* WELCOME SECTION */
        /* ================================================ */
        .welcome-section {
            background: linear-gradient(135deg, #1a1a2e 0%, #2a2a4e 100%);
            border-radius: 14px;
            padding: 24px 28px;
            color: #fff;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }

        .welcome-section:after {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(79, 172, 254, 0.06);
            border-radius: 50%;
        }

        .welcome-section h1 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
            position: relative;
            z-index: 1;
        }

        .welcome-section p {
            opacity: 0.7;
            margin-bottom: 0;
            font-size: 14px;
            position: relative;
            z-index: 1;
        }

        .welcome-section .badge-tenant {
            background: rgba(255, 255, 255, 0.12);
            padding: 4px 16px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-block;
            position: relative;
            z-index: 1;
        }

        .welcome-section .date-display {
            opacity: 0.5;
            font-size: 12px;
            position: relative;
            z-index: 1;
        }

        /* ================================================ */
        /* STATS ROW */
        /* ================================================ */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: #fff;
            border-radius: 14px;
            padding: 16px 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            display: flex;
            align-items: center;
            gap: 14px;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .stat-card .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .stat-card .stat-icon.blue {
            background: #e3f0ff;
            color: #0d6efd;
        }

        .stat-card .stat-icon.green {
            background: #d4edda;
            color: #28a745;
        }

        .stat-card .stat-icon.purple {
            background: #e8d5f5;
            color: #6f42c1;
        }

        .stat-card .stat-icon.orange {
            background: #ffe8d9;
            color: #fd7e14;
        }

        .stat-card .stat-icon.teal {
            background: #d0f0f0;
            color: #20c997;
        }

        .stat-card .stat-number {
            font-size: 22px;
            font-weight: 700;
            color: #1a1a2e;
            line-height: 1.2;
        }

        .stat-card .stat-label {
            font-size: 12px;
            color: #6c757d;
            font-weight: 500;
        }

        /* ================================================ */
        /* SUBSCRIPTION CARD */
        /* ================================================ */
        .subscription-card .progress {
            height: 6px;
            border-radius: 10px;
            background: #e9ecef;
        }

        .subscription-card .progress .progress-bar {
            border-radius: 10px;
            transition: width 0.6s ease;
        }

        /* ================================================ */
        /* ALERT PENDING */
        /* ================================================ */
        .alert-pending {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            border-radius: 12px;
            padding: 12px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .alert-pending .btn {
            border-radius: 8px;
            padding: 4px 16px;
            font-size: 13px;
        }

        /* ================================================ */
        /* ALERT CUSTOM */
        /* ================================================ */
        .alert-custom {
            border-radius: 12px;
            border: none;
            padding: 16px 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            display: flex;
            align-items: flex-start;
            gap: 14px;
            transition: all 0.3s ease;
            animation: slideDown 0.4s ease;
            margin-bottom: 20px;
        }

        .alert-custom .alert-icon {
            font-size: 20px;
            flex-shrink: 0;
            margin-top: 2px;
            width: 28px;
            text-align: center;
        }

        .alert-custom .alert-content {
            flex: 1;
        }

        .alert-custom .alert-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 2px;
        }

        .alert-custom .alert-message {
            font-size: 13px;
            opacity: 0.9;
        }

        .alert-custom .btn-close-custom {
            background: none;
            border: none;
            color: inherit;
            opacity: 0.6;
            cursor: pointer;
            padding: 4px 8px;
            font-size: 18px;
            transition: opacity 0.2s;
            flex-shrink: 0;
            margin-top: -2px;
        }

        .alert-custom .btn-close-custom:hover {
            opacity: 1;
        }

        .alert-custom.alert-success {
            background: #f0fdf4;
            color: #166534;
            border-left: 4px solid #28a745;
        }

        @keyframes slideDown {
            0% {
                opacity: 0;
                transform: translateY(-10px);
            }

            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ================================================ */
        /* CARDS */
        /* ================================================ */
        .card-custom {
            background: #fff;
            border-radius: 14px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            width: 100%;
        }

        .card-custom .card-header-custom {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .card-custom .card-header-custom h6 {
            font-weight: 600;
            margin: 0;
            font-size: 15px;
            color: #1a1a2e;
        }

        .card-custom .card-body-custom {
            padding: 16px 24px;
        }

        /* ================================================ */
        /* ACTIVITY */
        /* ================================================ */
        .activity-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid #f0f2f5;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-item .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            flex-shrink: 0;
            font-size: 14px;
        }

        .activity-item .activity-icon.school {
            background: #4facfe;
        }

        .activity-item .activity-icon.staff {
            background: #28a745;
        }

        .activity-item .activity-icon.student {
            background: #6f42c1;
        }

        .activity-item .activity-content {
            flex: 1;
        }

        .activity-item .activity-content .activity-name {
            font-weight: 500;
            font-size: 14px;
            color: #1a1a2e;
        }

        .activity-item .activity-content .activity-time {
            font-size: 12px;
            color: #6c757d;
        }

        /* ================================================ */
        /* NOTIFICATION ITEM */
        /* ================================================ */
        .notification-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px solid #f0f2f5;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item .notif-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .notification-item .notif-dot.unread {
            background: #4facfe;
        }

        .notification-item .notif-dot.read {
            background: #dee2e6;
        }

        .notification-item .notif-content {
            flex: 1;
        }

        .notification-item .notif-content .notif-title {
            font-size: 13px;
            color: #1a1a2e;
        }

        .notification-item .notif-content .notif-time {
            font-size: 11px;
            color: #adb5bd;
        }

        .notification-item .notif-link {
            color: #4facfe;
            font-size: 12px;
            text-decoration: none;
        }

        .notification-item .notif-link:hover {
            text-decoration: underline;
        }

        /* ================================================ */
        /* RESPONSIVE */
        /* ================================================ */
        @media (max-width: 1200px) {
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 72px;
                overflow: hidden;
            }

            .sidebar .sidebar-header h4 {
                font-size: 0;
            }

            .sidebar .sidebar-header h4 i {
                font-size: 24px;
            }

            .sidebar .sidebar-header small {
                display: none;
            }

            .sidebar .sidebar-header .school-crest {
                max-height: 32px;
                max-width: 32px;
                margin: 0 auto 8px;
            }

            .sidebar .nav-link span {
                display: none;
            }

            .sidebar .nav-link i {
                margin-right: 0;
                font-size: 18px;
            }

            .sidebar .nav-link {
                text-align: center;
                padding: 12px;
                justify-content: center;
            }

            .sidebar .nav-label {
                display: none;
            }

            .sidebar .sidebar-footer .user-info span {
                display: none;
            }

            .sidebar .sidebar-footer .user-info {
                justify-content: center;
            }

            .sidebar .sidebar-footer .logout-btn span {
                display: none;
            }

            .main-content {
                margin-left: 72px;
                width: calc(100% - 72px);
                padding: 20px;
            }

            .sidebar-toggle {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .sidebar-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
                width: 280px;
                position: fixed;
                z-index: 1000;
                top: 0;
                left: 0;
                height: 100vh;
                overflow-y: auto;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .sidebar .sidebar-header h4 {
                font-size: 20px;
            }

            .sidebar .sidebar-header small {
                display: block;
            }

            .sidebar .sidebar-header .school-crest {
                max-height: 40px;
                max-width: 40px;
                margin: 0 0 10px;
            }

            .sidebar .nav-link span {
                display: inline;
            }

            .sidebar .nav-link i {
                margin-right: 12px;
                font-size: 15px;
            }

            .sidebar .nav-link {
                text-align: left;
                padding: 10px 16px;
                justify-content: flex-start;
            }

            .sidebar .nav-label {
                display: block;
            }

            .sidebar .sidebar-footer .user-info span {
                display: inline;
            }

            .sidebar .sidebar-footer .user-info {
                justify-content: flex-start;
            }

            .sidebar .sidebar-footer .logout-btn span {
                display: inline;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 16px;
                padding-top: 70px;
            }

            .top-bar .page-title h1 {
                font-size: 20px;
            }

            .top-bar .page-title p {
                font-size: 12px;
            }

            .top-bar .header-actions .btn {
                font-size: 12px;
                padding: 6px 12px;
            }

            .stats-row {
                grid-template-columns: 1fr 1fr;
            }

            .welcome-section {
                padding: 16px 20px;
            }

            .welcome-section h1 {
                font-size: 20px;
            }

            .card-custom .card-header-custom,
            .card-custom .card-body-custom {
                padding: 12px 16px;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 10px 12px 20px;
                padding-top: 65px;
            }

            .top-bar .page-title h1 {
                font-size: 18px;
            }

            .top-bar .page-title p {
                font-size: 11px;
            }

            .top-bar .header-actions .btn {
                font-size: 11px;
                padding: 4px 10px;
            }

            .stats-row {
                grid-template-columns: 1fr;
            }

            .welcome-section {
                padding: 14px 16px;
            }

            .welcome-section h1 {
                font-size: 18px;
            }

            .activity-item {
                flex-wrap: wrap;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <!-- Sidebar Toggle -->
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>

            <!-- Sidebar -->
            <nav class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <?php /* [SCHOOL-IDENTITY] Conditional school crest in sidebar header. */ ?>
                    <?php if (!empty($schoolLogo)): ?>
                        <img class="school-crest"
                            src="<?php echo htmlspecialchars($schoolLogo); ?>"
                            alt="">
                    <?php endif; ?>
                    <h4><i class="fas fa-graduation-cap me-2"></i>Student 360</h4>
                    <?php /* [SCHOOL-IDENTITY] School name replaces the fallback text. */ ?>
                    <small><?php echo htmlspecialchars($schoolName); ?></small>
                </div>
                <div class="nav">
                    <div class="nav-label">Main</div>
                    <a class="nav-link active" href="/platform/tenant/dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                    </a>
                    <a class="nav-link" href="/platform/tenant/schools/index.php">
                        <i class="fas fa-school"></i> <span>Schools</span>
                    </a>
                    <a class="nav-link" href="/platform/tenant/campuses/index.php">
                        <i class="fas fa-map-marker-alt"></i> <span>Campuses</span></a>
                    <div class="nav-label mt-3">People</div>
                    <a class="nav-link" href="/platform/tenant/staff/index.php">
                        <i class="fas fa-user-tie"></i> <span>Staff</span>
                    </a>
                    <a class="nav-link" href="/platform/tenant/students/index.php">
                        <i class="fas fa-user-graduate"></i> <span>Students</span>
                    </a>

                    <div class="nav-label mt-3">System</div>
                    <a class="nav-link" href="/platform/tenant/subscriptions/upgrade.php">
                        <i class="fas fa-crown"></i> <span>Subscription</span>
                    </a>
                    <a class="nav-link" href="/platform/tenant/notifications.php">
                        <i class="fas fa-bell"></i> <span>Notifications</span>
                        <?php if ($unreadNotifications > 0): ?>
                            <span class="badge bg-danger ms-1"><?php echo $unreadNotifications; ?></span>
                        <?php endif; ?>
                    </a>
                    <a class="nav-link" href="/platform/tenant/settings/index.php">
                        <i class="fas fa-cog"></i> <span>Settings</span>
                    </a>
                    <a class="nav-link" href="/platform/logout.php">
                        <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
                    </a>
                </div>
                <div class="sidebar-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="user-info">
                            <div class="user-avatar" id="userAvatar"><?php echo $userAvatar; ?></div>
                            <div>
                                <div class="user-name" id="userName"><?php echo htmlspecialchars($currentUser); ?></div>
                                <div class="user-role" id="userRole">Tenant Administrator</div>
                            </div>
                        </div>
                        <button class="logout-btn" onclick="logout()" title="Logout">
                            <i class="fas fa-sign-out-alt"></i>
                        </button>
                    </div>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="main-content">
                <!-- Top Bar -->
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-tachometer-alt me-2"></i>Dashboard</h1>
                        <p>Overview of your organization</p>
                    </div>
                    <div class="header-actions">
                        <?php /* [SCHOOL-IDENTITY] School name in the top-bar badge. */ ?>
                        <span class="badge bg-primary text-white me-2 d-none d-md-inline-block">
                            <i class="fas fa-school me-1"></i> <?php echo htmlspecialchars($schoolName); ?>
                        </span>
                        <button class="btn btn-outline-secondary btn-sm" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt me-2"></i> Refresh
                        </button>
                    </div>
                </div>

                <!-- Success Message -->
                <?php if (!empty($successMessage)): ?>
                    <div class="alert-custom alert-success">
                        <div class="alert-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="alert-content">
                            <div class="alert-title">Success!</div>
                            <div class="alert-message"><?php echo htmlspecialchars($successMessage); ?></div>
                        </div>
                        <button class="btn-close-custom" onclick="this.closest('.alert-custom').remove()" aria-label="Close">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <!-- Welcome Section -->
                <div class="welcome-section">
                    <div class="d-flex flex-wrap justify-content-between align-items-center">
                        <div>
                            <h1><?php echo $greeting; ?> <?php echo htmlspecialchars($userFirstName); ?>!</h1>
                            <?php /* [SCHOOL-IDENTITY] School-scoped welcome copy. */ ?>
                            <p>Welcome to your school dashboard. Manage staff, students, campuses, and academic records.</p>
                            <?php /* [SCHOOL-IDENTITY] School name in the welcome badge. */ ?>
                            <span class="badge-tenant">
                                <i class="fas fa-school me-1"></i> <?php echo htmlspecialchars($schoolName); ?>
                            </span>
                        </div>
                        <div class="text-end">
                            <div class="date-display"><?php echo date('l, F d, Y'); ?></div>
                            <div class="date-display mt-1"><?php echo date('h:i A'); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Subscription Status Card -->
                <div class="card-custom subscription-card mb-3">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-crown me-2 text-warning"></i>Subscription Status</h6>
                        <div>
                            <span class="badge bg-<?php echo $planStatusBadge; ?> me-2">
                                <?php echo ucfirst($planStatus); ?>
                            </span>
                            <a href="/platform/tenant/subscriptions/upgrade.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-arrow-up me-1"></i> Upgrade
                            </a>
                        </div>
                    </div>
                    <div class="card-body-custom">
                        <div class="row">
                            <div class="col-md-3 col-6 mb-2">
                                <div class="text-muted small">Plan</div>
                                <div class="fw-bold"><?php echo htmlspecialchars($planName); ?></div>
                                <div class="text-muted small"><?php echo htmlspecialchars($planCode); ?></div>
                            </div>
                            <div class="col-md-3 col-6 mb-2">
                                <div class="text-muted small">Price</div>
                                <div class="fw-bold"><?php echo $planPrice > 0 ? 'GHS ' . number_format($planPrice, 2) : 'Free'; ?></div>
                            </div>
                            <div class="col-md-6 col-12">
                                <div class="text-muted small mb-1">Usage Overview</div>
                                <div class="d-flex flex-wrap gap-3">
                                    <!-- Schools -->
                                    <div style="min-width:100px;">
                                        <div class="d-flex justify-content-between small">
                                            <span>Schools</span>
                                            <span><?php echo $totalSchools; ?>/<?php echo $maxSchools > 0 ? $maxSchools : '∞'; ?></span>
                                        </div>
                                        <div class="progress">
                                            <div class="progress-bar <?php echo $usagePercentages['schools'] >= 90 ? 'bg-danger' : ($usagePercentages['schools'] >= 70 ? 'bg-warning' : 'bg-success'); ?>"
                                                style="width: <?php echo $usagePercentages['schools']; ?>%;"></div>
                                        </div>
                                    </div>
                                    <!-- Staff -->
                                    <div style="min-width:100px;">
                                        <div class="d-flex justify-content-between small">
                                            <span>Staff</span>
                                            <span><?php echo $totalStaff; ?>/<?php echo $maxStaff > 0 ? $maxStaff : '∞'; ?></span>
                                        </div>
                                        <div class="progress">
                                            <div class="progress-bar <?php echo $usagePercentages['staff'] >= 90 ? 'bg-danger' : ($usagePercentages['staff'] >= 70 ? 'bg-warning' : 'bg-success'); ?>"
                                                style="width: <?php echo $usagePercentages['staff']; ?>%;"></div>
                                        </div>
                                    </div>
                                    <!-- Students -->
                                    <div style="min-width:100px;">
                                        <div class="d-flex justify-content-between small">
                                            <span>Students</span>
                                            <span><?php echo $totalStudents; ?>/<?php echo $maxStudents > 0 ? $maxStudents : '∞'; ?></span>
                                        </div>
                                        <div class="progress">
                                            <div class="progress-bar <?php echo $usagePercentages['students'] >= 90 ? 'bg-danger' : ($usagePercentages['students'] >= 70 ? 'bg-warning' : 'bg-success'); ?>"
                                                style="width: <?php echo $usagePercentages['students']; ?>%;"></div>
                                        </div>
                                    </div>
                                </div>
                                <?php if ($hasDanger): ?>
                                    <div class="mt-2 text-danger small">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        One or more limits are at 90% or above. Please upgrade your plan.
                                    </div>
                                <?php elseif ($hasWarning): ?>
                                    <div class="mt-2 text-warning small">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        One or more limits are at 70% or above. Consider upgrading soon.
                                    </div>
                                <?php else: ?>
                                    <div class="mt-2 text-success small">
                                        <i class="fas fa-check-circle"></i>
                                        All usage is within your plan limits.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pending Requests Alert -->
                <?php if ($pendingRequests > 0): ?>
                    <div class="alert-pending">
                        <i class="fas fa-clock fa-lg"></i>
                        <span>You have <strong><?php echo $pendingRequests; ?></strong> pending school request(s).</span>
                        <a href="/platform/tenant/schools/index.php" class="btn btn-warning btn-sm ms-auto">
                            <i class="fas fa-eye me-1"></i> View Requests
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Stats Row -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-school"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($totalSchools); ?></div>
                            <div class="stat-label">Total Schools</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-user-tie"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($totalStaff); ?></div>
                            <div class="stat-label">Total Staff</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-user-graduate"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($totalStudents); ?></div>
                            <div class="stat-label">Total Students</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($totalUsers); ?></div>
                            <div class="stat-label">Total Users</div>
                        </div>
                    </div>
                </div>

                <!-- Second Row of Stats -->
                <div class="stats-row" style="margin-bottom: 24px;">
                    <div class="stat-card">
                        <div class="stat-icon teal"><i class="fas fa-check-circle"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($activeSchools); ?></div>
                            <div class="stat-label">Active Schools</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon teal"><i class="fas fa-user-check"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($activeStudents); ?></div>
                            <div class="stat-label">Active Students</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-map-marker-alt"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($totalCampuses); ?></div>
                            <div class="stat-label">Total Campuses</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-clock"></i></div>
                        <div>
                            <div class="stat-number"><?php echo count($recentActivity); ?></div>
                            <div class="stat-label">Recent Activities</div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity & Notifications -->
                <div class="row">
                    <div class="col-md-7 col-12">
                        <div class="card-custom">
                            <div class="card-header-custom">
                                <h6><i class="fas fa-history me-2 text-primary"></i>Recent Activity</h6>
                                <span class="text-muted small">
                                    <i class="fas fa-clock me-1"></i> Last <?php echo count($recentActivity); ?> activities
                                </span>
                            </div>
                            <div class="card-body-custom">
                                <?php if (empty($recentActivity)): ?>
                                    <div style="text-align:center;padding:20px 0;color:#6c757d;">
                                        <i class="fas fa-clock" style="font-size:32px;opacity:0.3;display:block;margin-bottom:8px;"></i>
                                        <p style="margin:0;">No recent activity</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($recentActivity as $activity): ?>
                                        <div class="activity-item">
                                            <div class="activity-icon <?php echo $activity['type']; ?>">
                                                <i class="fas <?php echo $activity['type'] == 'school' ? 'fa-school' : ($activity['type'] == 'staff' ? 'fa-user-tie' : 'fa-user-graduate'); ?>"></i>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-name"><?php echo htmlspecialchars($activity['name']); ?></div>
                                                <div class="activity-time">
                                                    <i class="fas fa-clock me-1"></i>
                                                    <?php echo date('M d, Y h:i A', strtotime($activity['created_at'])); ?>
                                                </div>
                                            </div>
                                            <span class="badge bg-light text-dark text-uppercase" style="font-size:10px;padding:3px 10px;">
                                                <?php echo $activity['type']; ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-5 col-12">
                        <div class="card-custom">
                            <div class="card-header-custom">
                                <h6><i class="fas fa-bell me-2 text-primary"></i>Recent Notifications</h6>
                                <?php if ($unreadNotifications > 0): ?>
                                    <a href="/platform/tenant/notifications.php" class="text-primary small">
                                        View All <i class="fas fa-arrow-right ms-1"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="card-body-custom">
                                <?php if (empty($recentNotifications)): ?>
                                    <div style="text-align:center;padding:20px 0;color:#6c757d;">
                                        <i class="fas fa-bell-slash" style="font-size:32px;opacity:0.3;display:block;margin-bottom:8px;"></i>
                                        <p style="margin:0;">No notifications</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($recentNotifications as $notif): ?>
                                        <div class="notification-item">
                                            <div class="notif-dot <?php echo $notif['is_read'] ? 'read' : 'unread'; ?>"></div>
                                            <div class="notif-content">
                                                <div class="notif-title">
                                                    <?php echo htmlspecialchars($notif['title']); ?>
                                                </div>
                                                <div class="notif-time">
                                                    <?php echo date('M d, Y h:i A', strtotime($notif['created_at'])); ?>
                                                </div>
                                            </div>
                                            <?php if (!empty($notif['link'])): ?>
                                                <a href="<?php echo htmlspecialchars($notif['link']); ?>" class="notif-link">
                                                    <i class="fas fa-arrow-right"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if ($unreadNotifications > 0): ?>
                                        <div class="text-center mt-2">
                                            <a href="/platform/tenant/notifications.php" class="btn btn-sm btn-outline-primary">
                                                View All Notifications
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card-custom mt-3">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-bolt me-2 text-warning"></i>Quick Actions</h6>
                    </div>
                    <div class="card-body-custom">
                        <div class="d-flex flex-wrap gap-2">
                            <a href="/platform/tenant/schools/request.php" class="btn btn-warning btn-sm">
                                <i class="fas fa-plus me-1"></i> Request School
                            </a>
                            <a href="/platform/tenant/staff/create.php" class="btn btn-success btn-sm">
                                <i class="fas fa-user-plus me-1"></i> Add Staff
                            </a>
                            <a href="/platform/tenant/students/create.php" class="btn btn-info btn-sm">
                                <i class="fas fa-user-graduate me-1"></i> Add Student
                            </a>
                            <a href="/platform/tenant/notifications.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-bell me-1"></i> Notifications
                                <?php if ($unreadNotifications > 0): ?>
                                    <span class="badge bg-danger ms-1"><?php echo $unreadNotifications; ?></span>
                                <?php endif; ?>
                            </a>
                            <a href="/platform/tenant/settings/index.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-cog me-1"></i> Settings
                            </a>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ================================================
        // SIDEBAR TOGGLE
        // ================================================
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }

        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.getElementById('sidebarToggle');
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });

        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                document.getElementById('sidebar').classList.remove('open');
            }
        });

        // ================================================
        // LOGOUT
        // ================================================
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '/platform/logout.php';
            }
        }

        // ================================================
        // LOAD USER INFO
        // ================================================
        function loadUserInfo() {
            const userStr = localStorage.getItem('user');
            if (userStr) {
                try {
                    const user = JSON.parse(userStr);
                    document.getElementById('userName').textContent = user.first_name || 'Admin';
                    document.getElementById('userAvatar').textContent = (user.first_name || 'A').charAt(0);
                    document.getElementById('userRole').textContent = (user.roles || ['Administrator'])[0];
                } catch (e) {
                    console.error('Error parsing user:', e);
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();
        });
    </script>
</body>

</html>