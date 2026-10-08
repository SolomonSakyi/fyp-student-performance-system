<?php

/**
 * Create Subscription Plan - Super Admin creates a new plan
 * 
 * @package EduTrack
 * @subpackage Platform\Subscriptions
 * @version 2.0
 * @filepath public/platform/subscriptions/create.php
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
$pageTitle = 'Create Subscription Plan - EduTrack Platform';
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
// BILLING CYCLES
// =============================================
$billingCycles = [
    'monthly' => 'Monthly',
    'yearly' => 'Yearly',
    'lifetime' => 'Lifetime'
];

$currencies = [
    'GHS' => 'GHS - Ghana Cedi',
    'USD' => 'USD - US Dollar',
    'EUR' => 'EUR - Euro',
    'GBP' => 'GBP - British Pound'
];

// =============================================
// HANDLE FORM SUBMISSION
// =============================================
$errors = [];
$formData = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = $_POST;

    // Validate required fields
    if (empty($formData['plan_name'])) {
        $errors[] = 'Plan name is required';
    }
    if (empty($formData['plan_code'])) {
        $errors[] = 'Plan code is required';
    }
    if (!isset($formData['price']) || $formData['price'] === '') {
        $errors[] = 'Price is required';
    }

    // Check if plan_code already exists
    if (!empty($formData['plan_code'])) {
        $existing = $db->getValue(
            "SELECT COUNT(*) FROM subscription_plans WHERE plan_code = ? AND (deleted_at IS NULL OR deleted_at = '')",
            [$formData['plan_code']]
        );
        if ($existing > 0) {
            $errors[] = 'Plan code already exists. Please choose a different one.';
        }
    }

    if (empty($errors)) {
        try {
            $db->execute(
                "INSERT INTO subscription_plans 
                 (plan_name, plan_code, description, price, currency, 
                  max_students, max_staff, max_campuses, max_schools,
                  max_storage_mb, max_api_calls, max_ai_requests, 
                  sms_balance, email_balance,
                  is_trial, trial_days, is_popular, is_active, 
                  billing_cycle, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    trim($formData['plan_name']),
                    strtoupper(trim($formData['plan_code'])),
                    trim($formData['description'] ?? ''),
                    (float)($formData['price'] ?? 0),
                    $formData['currency'] ?? 'GHS',
                    (int)($formData['max_students'] ?? 0),
                    (int)($formData['max_staff'] ?? 0),
                    (int)($formData['max_campuses'] ?? 0),
                    (int)($formData['max_schools'] ?? 0),
                    (int)($formData['max_storage_mb'] ?? 0),
                    (int)($formData['max_api_calls'] ?? 0),
                    (int)($formData['max_ai_requests'] ?? 0),
                    (int)($formData['sms_balance'] ?? 0),
                    (int)($formData['email_balance'] ?? 0),
                    isset($formData['is_trial']) ? 1 : 0,
                    (int)($formData['trial_days'] ?? 0),
                    isset($formData['is_popular']) ? 1 : 0,
                    1,
                    $formData['billing_cycle'] ?? 'monthly',
                    'active'
                ]
            );

            $success = true;
            $_SESSION['success_message'] = 'Subscription plan created successfully!';
            header('Location: /platform/subscriptions/index.php');
            exit;
        } catch (Exception $e) {
            error_log('Create plan error: ' . $e->getMessage());
            $errors[] = 'Error creating plan: ' . $e->getMessage();
        }
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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

        .form-label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            margin-bottom: 4px;
            display: block;
        }

        .form-label .required {
            color: #dc3545;
            margin-left: 2px;
        }

        .form-control,
        .form-select {
            border-radius: 10px;
            padding: 10px 14px;
            border: 2px solid #e9ecef;
            font-size: 13px;
            width: 100%;
            display: block;
            max-width: 100%;
            background: #fff;
            color: #1a1a2e;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
            height: 44px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 4px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .form-control.is-invalid,
        .form-select.is-invalid {
            border-color: #dc3545;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%23dc3545' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='12' cy='12' r='10'%3E%3C/circle%3E%3Cline x1='15' y1='9' x2='9' y2='15'%3E%3C/line%3E%3Cline x1='9' y1='9' x2='15' y2='15'%3E%3C/line%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 18px;
            padding-right: 40px;
            box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.1);
        }

        .invalid-feedback {
            display: block;
            font-size: 12px;
            color: #dc3545;
            margin-top: 4px;
        }

        .form-text {
            font-size: 11px;
            color: #6c757d;
            margin-top: 4px;
        }

        .mb-2 {
            margin-bottom: 10px;
        }

        .mb-3 {
            margin-bottom: 16px;
        }

        .mt-3 {
            margin-top: 16px;
        }

        .gap-2 {
            gap: 8px;
        }

        .d-flex {
            display: flex;
        }

        .flex-wrap {
            flex-wrap: wrap;
        }

        .justify-content-end {
            justify-content: flex-end;
        }

        hr {
            border: none;
            border-top: 1px solid #f0f2f5;
            margin: 16px 0;
        }

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

        .alert-custom .alert-message ul {
            margin: 4px 0 0 0;
            padding-left: 18px;
        }

        .alert-custom .alert-message ul li {
            padding: 1px 0;
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

        .alert-custom.alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border-left: 4px solid #dc3545;
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

            .form-control,
            .form-select {
                font-size: 14px;
                padding: 8px 14px;
                height: 44px;
            }

            .form-label {
                font-size: 13px;
            }

            [class*="col-"] {
                padding-left: 6px;
                padding-right: 6px;
            }

            .row {
                margin: 0 -6px;
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

            .form-control,
            .form-select {
                font-size: 13px;
                padding: 6px 12px;
                height: 40px;
            }

            .btn {
                font-size: 12px;
                padding: 6px 14px;
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

                    <div class="nav-label mt-3">Institution</div>
                    <a class="nav-link" href="/platform/schools/index.php"><i class="fas fa-school"></i> <span>Schools</span></a>
                    <a class="nav-link" href="/platform/campuses/index.php"><i class="fas fa-map-marker-alt"></i> <span>Campuses</span></a>

                    <div class="nav-label mt-3">Management</div>
                    <a class="nav-link active" href="/platform/subscriptions/index.php"><i class="fas fa-crown"></i> <span>Subscriptions</span></a>
                    <a class="nav-link" href="/platform/domains/index.php"><i class="fas fa-globe"></i> <span>Domains</span></a>

                    <div class="nav-label mt-3">System</div>
                    <a class="nav-link" href="/platform/audit/index.php"><i class="fas fa-history"></i> <span>Audit Logs</span></a>
                    <a class="nav-link" href="/platform/monitoring/index.php"><i class="fas fa-chart-line"></i> <span>Monitoring</span></a>
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
                        <h1><i class="fas fa-plus-circle me-2"></i>Create Subscription Plan</h1>
                        <p>Create a new subscription plan for tenants</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/subscriptions/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back
                        </a>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer">
                    <?php if (!empty($errors)): ?>
                        <div class="alert-custom alert-danger">
                            <div class="alert-icon"><i class="fas fa-exclamation-circle"></i></div>
                            <div class="alert-content">
                                <div class="alert-title">Please fix the following errors:</div>
                                <div class="alert-message">
                                    <ul>
                                        <?php foreach ($errors as $error): ?>
                                            <li><?php echo htmlspecialchars($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                            <button class="btn-close-custom" onclick="this.closest('.alert-custom').remove()" aria-label="Close">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Create Form -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-crown me-2 text-primary"></i>Plan Details</h6>
                        <span class="text-muted small">All fields marked with <span class="text-danger">*</span> are required</span>
                    </div>
                    <div class="card-body-custom">
                        <form id="createForm" method="POST" action="" novalidate>
                            <!-- Basic Information -->
                            <h6 class="mb-3"><i class="fas fa-info-circle me-2 text-primary"></i>Basic Information</h6>
                            <div class="row">
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="planName">Plan Name <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="planName" name="plan_name"
                                            placeholder="e.g., Professional"
                                            value="<?php echo htmlspecialchars($formData['plan_name'] ?? ''); ?>" required>
                                        <div class="invalid-feedback">Plan name is required</div>
                                    </div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="planCode">Plan Code <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="planCode" name="plan_code"
                                            placeholder="e.g., PRO"
                                            value="<?php echo htmlspecialchars(strtoupper($formData['plan_code'] ?? '')); ?>" required>
                                        <div class="form-text">Unique code (2-20 characters, uppercase letters, numbers)</div>
                                        <div class="invalid-feedback">Plan code is required</div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="description">Description</label>
                                        <input type="text" class="form-control" id="description" name="description"
                                            placeholder="Brief description of the plan"
                                            value="<?php echo htmlspecialchars($formData['description'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <!-- Pricing -->
                            <h6 class="mb-3"><i class="fas fa-money-bill-wave me-2 text-primary"></i>Pricing</h6>
                            <div class="row">
                                <div class="col-md-4 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="price">Price <span class="required">*</span></label>
                                        <input type="number" class="form-control" id="price" name="price"
                                            placeholder="0.00" step="0.01" min="0"
                                            value="<?php echo htmlspecialchars($formData['price'] ?? ''); ?>" required>
                                        <div class="form-text">Set to 0 for free plan</div>
                                        <div class="invalid-feedback">Price is required</div>
                                    </div>
                                </div>
                                <div class="col-md-4 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="currency">Currency</label>
                                        <select class="form-select" id="currency" name="currency">
                                            <?php foreach ($currencies as $key => $label): ?>
                                                <option value="<?php echo $key; ?>" <?php echo (isset($formData['currency']) && $formData['currency'] == $key) ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="billingCycle">Billing Cycle</label>
                                        <select class="form-select" id="billingCycle" name="billing_cycle">
                                            <?php foreach ($billingCycles as $key => $label): ?>
                                                <option value="<?php echo $key; ?>" <?php echo (isset($formData['billing_cycle']) && $formData['billing_cycle'] == $key) ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <!-- Limits -->
                            <h6 class="mb-3"><i class="fas fa-sliders-h me-2 text-primary"></i>Limits (0 = Unlimited)</h6>
                            <div class="row">
                                <div class="col-md-3 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="maxSchools">Max Schools</label>
                                        <input type="number" class="form-control" id="maxSchools" name="max_schools"
                                            placeholder="0 = Unlimited" min="0"
                                            value="<?php echo htmlspecialchars($formData['max_schools'] ?? 0); ?>">
                                        <div class="form-text">0 = Unlimited</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="maxCampuses">Max Campuses</label>
                                        <input type="number" class="form-control" id="maxCampuses" name="max_campuses"
                                            placeholder="0 = Unlimited" min="0"
                                            value="<?php echo htmlspecialchars($formData['max_campuses'] ?? 0); ?>">
                                    </div>
                                </div>
                                <div class="col-md-3 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="maxStaff">Max Staff</label>
                                        <input type="number" class="form-control" id="maxStaff" name="max_staff"
                                            placeholder="0 = Unlimited" min="0"
                                            value="<?php echo htmlspecialchars($formData['max_staff'] ?? 0); ?>">
                                    </div>
                                </div>
                                <div class="col-md-3 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="maxStudents">Max Students</label>
                                        <input type="number" class="form-control" id="maxStudents" name="max_students"
                                            placeholder="0 = Unlimited" min="0"
                                            value="<?php echo htmlspecialchars($formData['max_students'] ?? 0); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-3 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="maxStorageMb">Storage (MB)</label>
                                        <input type="number" class="form-control" id="maxStorageMb" name="max_storage_mb"
                                            placeholder="0 = Unlimited" min="0"
                                            value="<?php echo htmlspecialchars($formData['max_storage_mb'] ?? 0); ?>">
                                    </div>
                                </div>
                                <div class="col-md-3 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="maxApiCalls">API Calls (per month)</label>
                                        <input type="number" class="form-control" id="maxApiCalls" name="max_api_calls"
                                            placeholder="0 = Unlimited" min="0"
                                            value="<?php echo htmlspecialchars($formData['max_api_calls'] ?? 0); ?>">
                                    </div>
                                </div>
                                <div class="col-md-3 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="maxAiRequests">AI Requests (per month)</label>
                                        <input type="number" class="form-control" id="maxAiRequests" name="max_ai_requests"
                                            placeholder="0 = Unlimited" min="0"
                                            value="<?php echo htmlspecialchars($formData['max_ai_requests'] ?? 0); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-3 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="smsBalance">SMS Credits</label>
                                        <input type="number" class="form-control" id="smsBalance" name="sms_balance"
                                            placeholder="0" min="0"
                                            value="<?php echo htmlspecialchars($formData['sms_balance'] ?? 0); ?>">
                                    </div>
                                </div>
                                <div class="col-md-3 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="emailBalance">Email Credits</label>
                                        <input type="number" class="form-control" id="emailBalance" name="email_balance"
                                            placeholder="0" min="0"
                                            value="<?php echo htmlspecialchars($formData['email_balance'] ?? 0); ?>">
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <!-- Additional Settings -->
                            <h6 class="mb-3"><i class="fas fa-cog me-2 text-primary"></i>Additional Settings</h6>
                            <div class="row">
                                <div class="col-md-3 col-12">
                                    <div class="mb-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="isPopular" name="is_popular"
                                                <?php echo isset($formData['is_popular']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="isPopular">Mark as Popular</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-12">
                                    <div class="mb-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="isTrial" name="is_trial"
                                                <?php echo isset($formData['is_trial']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="isTrial">Has Trial</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="trialDays">Trial Days</label>
                                        <input type="number" class="form-control" id="trialDays" name="trial_days"
                                            placeholder="0" min="0"
                                            value="<?php echo htmlspecialchars($formData['trial_days'] ?? 0); ?>">
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <!-- Form Actions -->
                            <div class="d-flex gap-2 flex-wrap justify-content-end">
                                <a href="/platform/subscriptions/index.php" class="btn btn-outline-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <i class="fas fa-save me-2"></i> Create Plan
                                </button>
                            </div>
                        </form>
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

            // Plan code to uppercase
            document.getElementById('planCode').addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });

            // Required field validation
            const requiredInputs = document.querySelectorAll('input[required]');
            requiredInputs.forEach(input => {
                input.addEventListener('blur', function() {
                    if (this.value.trim() === '') {
                        this.classList.add('is-invalid');
                    } else {
                        this.classList.remove('is-invalid');
                    }
                });
                input.addEventListener('input', function() {
                    if (this.value.trim().length > 0) {
                        this.classList.remove('is-invalid');
                    }
                });
            });
        });
    </script>
</body>

</html>