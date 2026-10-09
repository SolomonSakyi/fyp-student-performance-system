<?php

/**
 * Upgrade Subscription - Tenant Admin upgrades their plan
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Subscriptions
 * @version 2.1
 * @filepath public/platform/tenant/subscriptions/upgrade.php
 *
 * v2.1 change (2026-10-09) [QUERY-FIX]:
 *   The page's SQL carried nine malformed second comparisons on the
 *   deleted_at column, all of the form:
 *       (X.deleted_at IS NULL OR X.deleted_at = '')
 *   deleted_at is a datetime column. Comparing it to an empty
 *   string is a type-mismatched comparison that MariaDB 10.4 (the
 *   local dev engine) tolerates but that MySQL 9.7 (the Railway
 *   production engine) can reject, producing an uncaught
 *   PDOException and a 500. The soft-delete predicate is
 *   deleted_at IS NULL alone. The OR branch is removed at all nine
 *   sites:
 *     1. The tenants_subscriptions query in $currentSubscription.
 *     2. The subscription_plans query in $plans.
 *     3. The schools count in $schoolsCount.
 *     4. The campuses count in $campusesCount (two sites: c. and s.).
 *     5. The staff count in $staffCount.
 *     6. The students count in $studentsCount.
 *     7. The subscription_plans lookup in the POST branch ($newPlan).
 *     8. The UPDATE tenant_subscriptions in the POST branch.
 *     9. The subscription_plans re-query after $upgradeSuccess.
 *   This is the same class of fix applied to dashboard.php v1.1 and
 *   schools/index.php v1.1. Every other line, query, variable,
 *   markup block, style rule, and script is byte-identical to v2.0.
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
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/tenant/login.php');
    exit;
}

// Super Admin should not access this page - redirect to platform subscription management
if (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] === true) {
    header('Location: /platform/subscriptions/manage.php');
    exit;
}

$tenantId = $_SESSION['tenant_id'] ?? 0;
if (!$tenantId) {
    header('Location: /platform/tenant/login.php?error=no_tenant');
    exit;
}

// =============================================
// PAGE SETUP
// =============================================
$pageTitle = 'Upgrade Plan - EduTrack Tenant';
$currentPage = 'subscriptions';

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];

// =============================================
// LOAD CONFIG AND DATABASE HELPER
// =============================================
$projectRoot = dirname(__DIR__, 4);

if (file_exists($projectRoot . '/config/config.php')) {
    require_once $projectRoot . '/config/config.php';
} else {
    die('config.php not found!');
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// =============================================
// GET CURRENT SUBSCRIPTION
// =============================================
$currentSubscription = $db->fetchOne(
    "SELECT ts.*, sp.plan_name, sp.plan_code, sp.price
     FROM tenant_subscriptions ts
     JOIN subscription_plans sp ON ts.plan_id = sp.id
     WHERE ts.tenant_id = ? AND ts.status IN ('active', 'trial')
     AND ts.deleted_at IS NULL
     ORDER BY ts.created_at DESC LIMIT 1",
    [$tenantId]
);

$currentPlanId = $currentSubscription['plan_id'] ?? 0;
$currentPlanName = $currentSubscription['plan_name'] ?? 'Free';
$currentPlanCode = $currentSubscription['plan_code'] ?? 'FREE';

// =============================================
// GET ALL AVAILABLE PLANS
// =============================================
$plans = $db->fetchAll(
    "SELECT * FROM subscription_plans
     WHERE is_active = 1
     AND deleted_at IS NULL
     ORDER BY price ASC"
);

// =============================================
// GET USAGE STATS
// =============================================
$schoolsCount = $db->getValue(
    "SELECT COUNT(*) FROM schools WHERE tenant_id = ? AND deleted_at IS NULL",
    [$tenantId]
);

$campusesCount = $db->getValue(
    "SELECT COUNT(*) FROM campuses c
     JOIN schools s ON c.school_id = s.id
     WHERE s.tenant_id = ? AND c.deleted_at IS NULL
     AND s.deleted_at IS NULL",
    [$tenantId]
);

$staffCount = $db->getValue(
    "SELECT COUNT(*) FROM staff WHERE tenant_id = ? AND deleted_at IS NULL",
    [$tenantId]
);

$studentsCount = $db->getValue(
    "SELECT COUNT(*) FROM students WHERE tenant_id = ? AND deleted_at IS NULL",
    [$tenantId]
);

$usageStats = [
    'schools' => ['current' => $schoolsCount, 'max' => 0],
    'campuses' => ['current' => $campusesCount, 'max' => 0],
    'staff' => ['current' => $staffCount, 'max' => 0],
    'students' => ['current' => $studentsCount, 'max' => 0]
];

// =============================================
// HANDLE UPGRADE REQUEST
// =============================================
$upgradeError = '';
$upgradeSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['plan_id'])) {
    $newPlanId = (int)$_POST['plan_id'];

    // Verify the plan exists and is active
    $newPlan = $db->fetchOne(
        "SELECT * FROM subscription_plans WHERE id = ? AND is_active = 1 AND deleted_at IS NULL",
        [$newPlanId]
    );

    if (!$newPlan) {
        $upgradeError = 'Selected plan is not available.';
    } elseif ($newPlanId == $currentPlanId) {
        $upgradeError = 'You are already on this plan.';
    } else {
        try {
            // Begin transaction
            $db->beginTransaction();

            // End current subscription
            $db->execute(
                "UPDATE tenant_subscriptions
                 SET status = 'ended'
                 WHERE tenant_id = ? AND status IN ('active', 'trial')
                 AND deleted_at IS NULL",
                [$tenantId]
            );

            // Create new subscription
            $db->execute(
                "INSERT INTO tenant_subscriptions
                 (tenant_id, plan_id, status, created_at)
                 VALUES (?, ?, 'active', NOW())",
                [$tenantId, $newPlanId]
            );

            // Try to update subscription_plan_id in tenants table
            // Check if the column exists first
            $columnExists = $db->getValue(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'subscription_plan_id'"
            );

            if ($columnExists > 0) {
                $db->execute(
                    "UPDATE tenants
                     SET subscription_plan_id = ?
                     WHERE id = ?",
                    [$newPlanId, $tenantId]
                );
            }

            $db->commit();

            // Log the upgrade
            error_log("Tenant $tenantId upgraded from plan $currentPlanId to plan $newPlanId");

            $upgradeSuccess = true;

            // Refresh current plan data
            $currentPlanId = $newPlanId;
            $currentPlanName = $newPlan['plan_name'];
            $currentPlanCode = $newPlan['plan_code'];

            // Refresh usage stats with new limits
            $usageStats['schools']['max'] = $newPlan['max_schools'] ?? 0;
            $usageStats['campuses']['max'] = $newPlan['max_campuses'] ?? 0;
            $usageStats['staff']['max'] = $newPlan['max_staff'] ?? 0;
            $usageStats['students']['max'] = $newPlan['max_students'] ?? 0;
        } catch (Exception $e) {
            $db->rollBack();
            $upgradeError = 'Failed to upgrade plan: ' . $e->getMessage();
            error_log("Upgrade error for tenant $tenantId: " . $e->getMessage());
        }
    }
}

// After upgrade success, refresh plans list to show current status
if ($upgradeSuccess) {
    $plans = $db->fetchAll(
        "SELECT * FROM subscription_plans
         WHERE is_active = 1
         AND deleted_at IS NULL
         ORDER BY price ASC"
    );
}

// =============================================
// CURRENT USER
// =============================================
$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userFirstName = $_SESSION['first_name'] ?? 'User';
$userAvatar = strtoupper(substr($userFirstName, 0, 1));
$tenantName = $_SESSION['tenant_name'] ?? 'My Organization';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $pageTitle; ?></title>
    <link href="/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/vendor/fontawesome/all.min.css" rel="stylesheet">
    <link href="/assets/vendor/inter/inter.css" rel="stylesheet">
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

        .top-bar .page-title h1 i {
            color: #ffc107;
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

        .btn-success {
            background: #28a745;
            border: none;
            color: #fff;
        }

        .btn-success:hover {
            background: #218838;
            color: #fff;
        }

        .btn-success:disabled {
            background: #6c757d;
            cursor: not-allowed;
            opacity: 0.65;
        }

        .btn-outline-secondary:disabled {
            cursor: not-allowed;
            opacity: 0.65;
        }

        .card-custom {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
            overflow: hidden;
            width: 100%;
        }

        .card-custom .card-header-custom {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: transparent;
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
            padding: 20px 24px;
        }

        /* ================================================ */
        /* ALERT */
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

        .alert-custom.alert-success {
            background: #f0fdf4;
            color: #166534;
            border-left: 4px solid #28a745;
        }

        .alert-custom.alert-success .alert-icon {
            color: #28a745;
        }

        .alert-custom.alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border-left: 4px solid #dc3545;
        }

        .alert-custom.alert-danger .alert-icon {
            color: #dc3545;
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
        /* PLAN CARDS */
        /* ================================================ */
        .plan-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }

        .plan-card {
            background: #fff;
            border-radius: 14px;
            padding: 24px;
            border: 2px solid #e9ecef;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.3s;
            text-align: center;
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .plan-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }

        .plan-card.popular {
            border-color: #4facfe;
            box-shadow: 0 4px 20px rgba(79, 172, 254, 0.15);
        }

        .plan-card.popular .popular-badge {
            position: absolute;
            top: -10px;
            right: 20px;
            background: #4facfe;
            color: #fff;
            padding: 2px 16px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .plan-card.current {
            border-color: #28a745;
            background: #f0fdf4;
        }

        .plan-card .plan-name {
            font-weight: 700;
            font-size: 20px;
            color: #1a1a2e;
        }

        .plan-card .plan-price {
            font-size: 32px;
            font-weight: 800;
            color: #1a1a2e;
            margin: 8px 0;
        }

        .plan-card .plan-price .currency {
            font-size: 16px;
            font-weight: 500;
            color: #6c757d;
        }

        .plan-card .plan-price .cycle {
            font-size: 14px;
            font-weight: 400;
            color: #6c757d;
        }

        .plan-card .plan-description {
            font-size: 13px;
            color: #6c757d;
            margin-bottom: 12px;
        }

        .plan-card .plan-features {
            text-align: left;
            margin: 12px 0;
            padding: 0;
            list-style: none;
            flex: 1;
        }

        .plan-card .plan-features li {
            padding: 4px 0;
            font-size: 13px;
            color: #1a1a2e;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .plan-card .plan-features li i {
            color: #28a745;
            width: 16px;
            flex-shrink: 0;
        }

        .plan-card .plan-features li .text-muted {
            color: #adb5bd;
        }

        .plan-card .btn {
            width: 100%;
            border-radius: 10px;
            padding: 10px;
            font-weight: 600;
            margin-top: 8px;
        }

        /* Current plan badge positioning */
        .plan-card .badge-current {
            position: absolute;
            top: -10px;
            left: 20px;
            background: #28a745;
            color: #fff;
            padding: 2px 16px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        /* ================================================ */
        /* RESPONSIVE */
        /* ================================================ */
        @media (max-width: 1200px) {
            .plan-grid {
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

            .plan-grid {
                grid-template-columns: 1fr;
            }

            .card-custom .card-header-custom {
                padding: 12px 16px;
            }

            .card-custom .card-body-custom {
                padding: 14px 16px;
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

            .plan-card {
                padding: 16px;
            }

            .plan-card .plan-price {
                font-size: 26px;
            }

            .card-custom .card-body-custom {
                padding: 10px 12px;
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
                    <h4><i class="fas fa-graduation-cap me-2"></i>Student 360</h4>
                    <small><?php echo htmlspecialchars($tenantName); ?></small>
                </div>
                <div class="nav">
                    <div class="nav-label">Main</div>
                    <a class="nav-link" href="/platform/tenant/dashboard.php"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a>
                    <a class="nav-link" href="/platform/tenant/schools/index.php"><i class="fas fa-school"></i> <span>Schools</span></a>
                    <a class="nav-link" href="/platform/tenant/campuses/index.php"><i class="fas fa-map-marker-alt"></i> <span>Campuses</span></a>

                    <div class="nav-label mt-3">People</div>
                    <a class="nav-link" href="/platform/tenant/staff/index.php"><i class="fas fa-user-tie"></i> <span>Staff</span></a>
                    <a class="nav-link" href="/platform/tenant/students/index.php"><i class="fas fa-user-graduate"></i> <span>Students</span></a>

                    <div class="nav-label mt-3">System</div>
                    <a class="nav-link active" href="/platform/tenant/subscriptions/upgrade.php"><i class="fas fa-crown"></i> <span>Subscription</span></a>
                    <a class="nav-link" href="/platform/tenant/notifications.php"><i class="fas fa-bell"></i> <span>Notifications</span></a>
                    <a class="nav-link" href="/platform/tenant/settings/index.php"><i class="fas fa-cog"></i> <span>Settings</span></a>
                    <a class="nav-link" href="/platform/logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
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
                        <button class="logout-btn" onclick="logout()" title="Logout"><i class="fas fa-sign-out-alt"></i></button>
                    </div>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="main-content">
                <!-- Top Bar -->
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-crown me-2"></i>Upgrade Plan</h1>
                        <p>Choose a plan that fits your organization's needs</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/dashboard.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back
                        </a>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer">
                    <?php if ($upgradeSuccess): ?>
                        <div class="alert-custom alert-success">
                            <div class="alert-icon"><i class="fas fa-check-circle"></i></div>
                            <div class="alert-content">
                                <div class="alert-title">Upgrade Successful!</div>
                                <div class="alert-message">Your plan has been upgraded to <strong><?php echo htmlspecialchars($currentPlanName); ?></strong>.</div>
                            </div>
                            <button class="btn-close-custom" onclick="this.closest('.alert-custom').remove()" aria-label="Close">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    <?php endif; ?>

                    <?php if ($upgradeError): ?>
                        <div class="alert-custom alert-danger">
                            <div class="alert-icon"><i class="fas fa-exclamation-circle"></i></div>
                            <div class="alert-content">
                                <div class="alert-title">Upgrade Failed</div>
                                <div class="alert-message"><?php echo htmlspecialchars($upgradeError); ?></div>
                            </div>
                            <button class="btn-close-custom" onclick="this.closest('.alert-custom').remove()" aria-label="Close">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Current Plan Info -->
                <div class="card-custom mb-3">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-info-circle me-2 text-primary"></i>Current Plan</h6>
                    </div>
                    <div class="card-body-custom">
                        <div class="d-flex flex-wrap justify-content-between align-items-center">
                            <div>
                                <span class="badge bg-success fs-6 p-2"><?php echo htmlspecialchars($currentPlanName); ?></span>
                                <span class="text-muted ms-2">(<?php echo htmlspecialchars($currentPlanCode); ?>)</span>
                            </div>
                            <div>
                                <span class="text-muted">Usage:</span>
                                <span class="fw-bold">
                                    Schools <?php echo (int)($usageStats['schools']['current'] ?? 0); ?>/<?php echo ($usageStats['schools']['max'] ?? 0) > 0 ? (int)($usageStats['schools']['max']) : 'âˆž'; ?> |
                                    Staff <?php echo (int)($usageStats['staff']['current'] ?? 0); ?>/<?php echo ($usageStats['staff']['max'] ?? 0) > 0 ? (int)($usageStats['staff']['max']) : 'âˆž'; ?> |
                                    Students <?php echo (int)($usageStats['students']['current'] ?? 0); ?>/<?php echo ($usageStats['students']['max'] ?? 0) > 0 ? (int)($usageStats['students']['max']) : 'âˆž'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Plans Grid -->
                <div class="plan-grid">
                    <?php foreach ($plans as $plan):
                        $isCurrent = ($plan['id'] == $currentPlanId);
                        $isPopular = isset($plan['is_popular']) && $plan['is_popular'] == 1;
                        $priceDisplay = $plan['price'] == 0 ? 'Free' : number_format($plan['price'], 2);
                        $currency = $plan['currency'] ?? 'GHS';
                        $billingCycle = $plan['billing_cycle'] ?? 'monthly';
                    ?>
                        <div class="plan-card <?php echo $isCurrent ? 'current' : ''; ?> <?php echo $isPopular ? 'popular' : ''; ?>">
                            <?php if ($isPopular): ?>
                                <span class="popular-badge">Popular</span>
                            <?php endif; ?>
                            <?php if ($isCurrent): ?>
                                <span class="badge-current">Current</span>
                            <?php endif; ?>
                            <div class="plan-name"><?php echo htmlspecialchars($plan['plan_name']); ?></div>
                            <div class="plan-price">
                                <span class="currency"><?php echo $currency; ?></span> <?php echo $priceDisplay; ?>
                                <span class="cycle">/ <?php echo $billingCycle; ?></span>
                            </div>
                            <div class="plan-description"><?php echo htmlspecialchars($plan['description'] ?? ''); ?></div>
                            <ul class="plan-features">
                                <li><i class="fas fa-check"></i> <?php echo ($plan['max_schools'] ?? 0) > 0 ? (int)$plan['max_schools'] : 'Unlimited'; ?> Schools</li>
                                <li><i class="fas fa-check"></i> <?php echo ($plan['max_campuses'] ?? 0) > 0 ? (int)$plan['max_campuses'] : 'Unlimited'; ?> Campuses</li>
                                <li><i class="fas fa-check"></i> <?php echo ($plan['max_staff'] ?? 0) > 0 ? (int)$plan['max_staff'] : 'Unlimited'; ?> Staff</li>
                                <li><i class="fas fa-check"></i> <?php echo ($plan['max_students'] ?? 0) > 0 ? (int)$plan['max_students'] : 'Unlimited'; ?> Students</li>
                                <li><i class="fas fa-check"></i> <?php echo ($plan['max_storage_mb'] ?? 0) > 0 ? (int)$plan['max_storage_mb'] . ' MB' : 'Unlimited'; ?> Storage</li>
                                <?php if (!empty($plan['sms_balance'])): ?>
                                    <li><i class="fas fa-check"></i> <?php echo (int)$plan['sms_balance']; ?> SMS Credits</li>
                                <?php endif; ?>
                                <?php if (!empty($plan['email_balance'])): ?>
                                    <li><i class="fas fa-check"></i> <?php echo (int)$plan['email_balance']; ?> Email Credits</li>
                                <?php endif; ?>
                            </ul>
                            <?php if ($isCurrent): ?>
                                <button class="btn btn-success" disabled>Current Plan</button>
                            <?php elseif ($plan['price'] == 0 && $currentPlanId != 0): ?>
                                <button class="btn btn-outline-secondary" disabled>Free Plan</button>
                            <?php else: ?>
                                <button class="btn btn-primary" onclick="upgradePlan(<?php echo (int)$plan['id']; ?>, '<?php echo htmlspecialchars($plan['plan_name']); ?>', <?php echo (float)$plan['price']; ?>)">
                                    <i class="fas fa-arrow-up me-1"></i> Upgrade
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Footer Note -->
                <div class="text-center mt-4">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Upgrading your plan will take effect immediately.
                    </small>
                </div>
            </main>
        </div>
    </div>

    <script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
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
                localStorage.removeItem('token');
                localStorage.removeItem('user');
                window.location.href = '<?php echo $protocol; ?>://<?php echo $host; ?>/platform/logout.php';
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
                } catch (e) {
                    console.error('Error parsing user:', e);
                }
            }
        }

        // ================================================
        // UPGRADE PLAN
        // ================================================
        function upgradePlan(planId, planName, planPrice) {
            const priceDisplay = planPrice == 0 ? 'Free' : planPrice.toFixed(2);
            const message = 'Are you sure you want to upgrade to the "' + planName + '" plan?\n\n' +
                'Price: ' + priceDisplay + '\n\n' +
                'This action will take effect immediately.';

            if (confirm(message)) {
                // Create and submit form
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'plan_id';
                input.value = planId;

                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // ================================================
        // INITIALIZE
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();
        });
    </script>
</body>

</html>