<?php

/**
 * View Subscription Plan - Super Admin views plan details
 * 
 * @package EduTrack
 * @subpackage Platform\Subscriptions
 * @version 2.0
 * @filepath public/platform/subscriptions/view.php
 */

// =============================================
// SESSION START
// =============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =============================================
// AUTHENTICATION CHECK - Super Admin Only
// =============================================
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

if (!isset($_SESSION['is_super_admin']) || $_SESSION['is_super_admin'] !== true) {
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

// =============================================
// PAGE SETUP
// =============================================
$pageTitle = 'View Subscription Plan - EduTrack Platform';
$currentPage = 'subscriptions';

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
$db = DatabaseHelper::getInstance();

// =============================================
// GET PLAN ID
// =============================================
$planId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$planId) {
    header('Location: /platform/subscriptions/index.php?message=' . urlencode('Invalid plan ID') . '&type=danger');
    exit;
}

// =============================================
// GET PLAN DETAILS
// =============================================
$plan = $db->fetchOne(
    "SELECT * FROM subscription_plans WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '')",
    [$planId]
);

if (!$plan) {
    header('Location: /platform/subscriptions/index.php?message=' . urlencode('Plan not found') . '&type=danger');
    exit;
}

// =============================================
// GET TENANTS USING THIS PLAN
// =============================================
$tenants = $db->fetchAll(
    "SELECT t.id, t.tenant_name, t.tenant_code, t.status, ts.status as subscription_status,
            ts.created_at as subscribed_at
     FROM tenants t
     JOIN tenant_subscriptions ts ON t.id = ts.tenant_id
     WHERE ts.plan_id = ? AND (t.deleted_at IS NULL OR t.deleted_at = '')
     ORDER BY t.tenant_name ASC",
    [$planId]
);

$totalTenants = count($tenants);
$activeTenants = 0;
foreach ($tenants as $tenant) {
    if ($tenant['subscription_status'] === 'active') {
        $activeTenants++;
    }
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

        .btn-danger {
            background: #dc3545;
            border: none;
            color: #fff;
        }

        .btn-danger:hover {
            background: #c82333;
            color: #fff;
        }

        /* ================================================ */
        /* CARD */
        /* ================================================ */
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
        /* PLAN DETAILS */
        /* ================================================ */
        .detail-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid #f0f2f5;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-row .detail-label {
            font-weight: 500;
            color: #6c757d;
            width: 180px;
            flex-shrink: 0;
        }

        .detail-row .detail-value {
            color: #1a1a2e;
            flex: 1;
        }

        .badge-status {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-status.active {
            background: #d4edda;
            color: #155724;
        }

        .badge-status.inactive {
            background: #e9ecef;
            color: #6c757d;
        }

        /* ================================================ */
        /* RESPONSIVE */
        /* ================================================ */
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

            .card-custom .card-body-custom {
                padding: 10px 12px;
            }

            .detail-row {
                flex-direction: column;
                gap: 2px;
            }

            .detail-row .detail-label {
                width: 100%;
                font-size: 12px;
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
                    <h4><i class="fas fa-graduation-cap me-2"></i>EduTrack</h4>
                    <small>Platform Administration</small>
                </div>
                <div class="nav">
                    <div class="nav-label">Main</div>
                    <a class="nav-link" href="/platform/index.php"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a>
                    <a class="nav-link" href="/platform/tenants/index.php"><i class="fas fa-building"></i> <span>Tenants</span></a>
                    <a class="nav-link" href="/platform/users/index.php"><i class="fas fa-users"></i> <span>Users</span></a>

                    <div class="nav-label mt-3">Management</div>
                    <a class="nav-link active" href="/platform/subscriptions/index.php"><i class="fas fa-crown"></i> <span>Subscriptions</span></a>

                    <div class="nav-label mt-3">System</div>
                    <a class="nav-link" href="/platform/audit/index.php"><i class="fas fa-history"></i> <span>Audit Logs</span></a>
                    <a class="nav-link" href="/platform/settings/index.php"><i class="fas fa-cog"></i> <span>Settings</span></a>
                </div>
                <div class="sidebar-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="user-info">
                            <div class="user-avatar" id="userAvatar"><?php echo $userAvatar; ?></div>
                            <div>
                                <div class="user-name" id="userName"><?php echo htmlspecialchars($currentUser); ?></div>
                                <div class="user-role">Super Administrator</div>
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
                        <h1><i class="fas fa-crown me-2"></i>Plan Details</h1>
                        <p>View subscription plan information</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/subscriptions/edit.php?id=<?php echo $planId; ?>" class="btn btn-primary">
                            <i class="fas fa-edit me-2"></i> Edit Plan
                        </a>
                        <a href="/platform/subscriptions/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back
                        </a>
                    </div>
                </div>

                <!-- Plan Details -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-info-circle me-2 text-primary"></i>Plan Information</h6>
                        <span class="badge-status <?php echo $plan['is_active'] ? 'active' : 'inactive'; ?>">
                            <?php echo $plan['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                    <div class="card-body-custom">
                        <div class="row">
                            <div class="col-md-6 col-12">
                                <div class="detail-row">
                                    <div class="detail-label">Plan Name</div>
                                    <div class="detail-value"><strong><?php echo htmlspecialchars($plan['plan_name']); ?></strong></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Plan Code</div>
                                    <div class="detail-value"><code><?php echo htmlspecialchars($plan['plan_code']); ?></code></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Plan Type</div>
                                    <div class="detail-value">
                                        <span class="badge <?php echo $plan['plan_type'] == 'free' ? 'bg-success' : ($plan['plan_type'] == 'enterprise' ? 'bg-info' : 'bg-primary'); ?>">
                                            <?php echo ucfirst($plan['plan_type'] ?? 'Paid'); ?>
                                        </span>
                                        <?php if ($plan['is_popular']): ?>
                                            <span class="badge bg-warning text-dark ms-1">Popular</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Description</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($plan['description'] ?? 'No description'); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6 col-12">
                                <div class="detail-row">
                                    <div class="detail-label">Price</div>
                                    <div class="detail-value">
                                        <?php if ($plan['price'] == 0): ?>
                                            <span class="text-success fw-bold">Free</span>
                                        <?php else: ?>
                                            <span class="fw-bold"><?php echo htmlspecialchars($plan['currency'] ?? 'GHS'); ?> <?php echo number_format($plan['price'], 2); ?></span>
                                            <span class="text-muted small">/ <?php echo $plan['billing_cycle'] ?? 'monthly'; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Billing Cycle</div>
                                    <div class="detail-value"><?php echo ucfirst($plan['billing_cycle'] ?? 'Monthly'); ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Duration</div>
                                    <div class="detail-value"><?php echo ($plan['duration_months'] ?? 1) . ' month(s)'; ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Trial</div>
                                    <div class="detail-value">
                                        <?php if ($plan['is_trial']): ?>
                                            <span class="text-success"><?php echo $plan['trial_days']; ?> days</span>
                                        <?php else: ?>
                                            <span class="text-muted">No trial</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Plan Limits -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-sliders-h me-2 text-primary"></i>Plan Limits</h6>
                        <span class="text-muted small">0 = Unlimited</span>
                    </div>
                    <div class="card-body-custom">
                        <div class="row">
                            <div class="col-md-3 col-6 text-center mb-3">
                                <div class="text-muted small">Max Schools</div>
                                <div class="fs-3 fw-bold"><?php echo ($plan['max_schools'] ?? 0) > 0 ? $plan['max_schools'] : '∞'; ?></div>
                            </div>
                            <div class="col-md-3 col-6 text-center mb-3">
                                <div class="text-muted small">Max Staff</div>
                                <div class="fs-3 fw-bold"><?php echo ($plan['max_staff'] ?? 0) > 0 ? $plan['max_staff'] : '∞'; ?></div>
                            </div>
                            <div class="col-md-3 col-6 text-center mb-3">
                                <div class="text-muted small">Max Students</div>
                                <div class="fs-3 fw-bold"><?php echo ($plan['max_students'] ?? 0) > 0 ? $plan['max_students'] : '∞'; ?></div>
                            </div>
                            <div class="col-md-3 col-6 text-center mb-3">
                                <div class="text-muted small">Max Campuses</div>
                                <div class="fs-3 fw-bold"><?php echo ($plan['max_campuses'] ?? 0) > 0 ? $plan['max_campuses'] : '∞'; ?></div>
                            </div>
                            <div class="col-md-3 col-6 text-center mb-3">
                                <div class="text-muted small">Storage (MB)</div>
                                <div class="fs-3 fw-bold"><?php echo ($plan['max_storage_mb'] ?? 0) > 0 ? $plan['max_storage_mb'] : '∞'; ?></div>
                            </div>
                            <div class="col-md-3 col-6 text-center mb-3">
                                <div class="text-muted small">API Calls</div>
                                <div class="fs-3 fw-bold"><?php echo ($plan['max_api_calls'] ?? 0) > 0 ? $plan['max_api_calls'] : '∞'; ?></div>
                            </div>
                            <div class="col-md-3 col-6 text-center mb-3">
                                <div class="text-muted small">AI Requests</div>
                                <div class="fs-3 fw-bold"><?php echo ($plan['max_ai_requests'] ?? 0) > 0 ? $plan['max_ai_requests'] : '∞'; ?></div>
                            </div>
                            <div class="col-md-3 col-6 text-center mb-3">
                                <div class="text-muted small">SMS Credits</div>
                                <div class="fs-3 fw-bold"><?php echo $plan['sms_balance'] ?? 0; ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tenants Using This Plan -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-building me-2 text-primary"></i>Tenants Using This Plan</h6>
                        <div>
                            <span class="badge bg-primary me-1"><?php echo $totalTenants; ?> Total</span>
                            <span class="badge bg-success"><?php echo $activeTenants; ?> Active</span>
                        </div>
                    </div>
                    <div class="card-body-custom p-0">
                        <div class="table-responsive">
                            <table class="table mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Tenant Name</th>
                                        <th>Code</th>
                                        <th>Status</th>
                                        <th>Subscription</th>
                                        <th>Subscribed At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($tenants)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-muted">
                                                <i class="fas fa-info-circle me-2"></i> No tenants are currently using this plan.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($tenants as $tenant): ?>
                                            <tr>
                                                <td>
                                                    <a href="/platform/tenants/view.php?id=<?php echo $tenant['id']; ?>">
                                                        <?php echo htmlspecialchars($tenant['tenant_name']); ?>
                                                    </a>
                                                </td>
                                                <td><code><?php echo htmlspecialchars($tenant['tenant_code']); ?></code></td>
                                                <td>
                                                    <span class="badge <?php echo $tenant['status'] == 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                                        <?php echo ucfirst($tenant['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $tenant['subscription_status'] == 'active' ? 'bg-success' : 'bg-warning'; ?>">
                                                        <?php echo ucfirst($tenant['subscription_status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M d, Y', strtotime($tenant['subscribed_at'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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

        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '/platform/logout.php';
            }
        }

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

        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();
        });
    </script>
</body>

</html>