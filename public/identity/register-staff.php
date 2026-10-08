<?php

/**
 * Staff Registration - Register a new staff member
 *
 * @package EduTrack
 * @subpackage Identity\Staff
 * @version 2.0
 * @filepath public/identity/register-staff.php
 */

session_start();

// Check authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

// Get tenant and school context from session
$tenantId = $_SESSION['tenant_id'] ?? 0;
$schoolId = $_SESSION['school_id'] ?? 0;
$schoolName = $_SESSION['school_name'] ?? '';

// If no school context, redirect to school selection
if (!$schoolId) {
    header('Location: /platform/schools/select.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// Get user info
$userName = $_SESSION['first_name'] ?? 'Admin';
$userAvatar = substr($userName, 0, 1);

// Determine API base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$apiBase = $protocol . '://' . $host . '/api/platform';

$pageTitle = 'Staff Registration - ' . $schoolName;
$currentPage = 'staff';
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
        /* =============================================== */
        /* GLOBAL RESET - MATCHES PLATFORM */
        /* =============================================== */
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
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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

        /* =============================================== */
        /* SIDEBAR - MATCHES PLATFORM */
        /* =============================================== */
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

        .sidebar .sidebar-header .school-badge {
            background: rgba(255, 255, 255, 0.1);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            color: #4facfe;
            display: inline-block;
            margin-top: 4px;
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
            font-weight: 600;
            font-size: 18px;
            color: #fff;
            flex-shrink: 0;
        }

        .sidebar .sidebar-footer .user-name {
            font-weight: 600;
            font-size: 14px;
        }

        .sidebar .sidebar-footer .user-role {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.5);
        }

        .sidebar .sidebar-footer .logout-btn {
            background: rgba(255, 255, 255, 0.1);
            border: none;
            color: #fff;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sidebar .sidebar-footer .logout-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        /* =============================================== */
        /* MAIN CONTENT */
        /* =============================================== */
        .main-content {
            margin-left: 260px;
            padding: 20px 28px 40px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }

        .top-bar .page-title h1 {
            font-size: 26px;
            font-weight: 700;
            color: #1a1a2e;
            margin: 0;
            letter-spacing: -0.5px;
        }

        .top-bar .page-title h1 i {
            color: #4facfe;
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
            border-radius: 12px;
            padding: 8px 20px;
            font-weight: 500;
            font-size: 13px;
            transition: all 0.3s;
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
            background: linear-gradient(135deg, #28a745, #20c997);
            border: none;
            color: #fff;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
            color: #fff;
        }

        /* =============================================== */
        /* SCHOOL CONTEXT BANNER */
        /* =============================================== */
        .school-context-banner {
            background: #fff;
            border-radius: 16px;
            padding: 16px 24px;
            margin-bottom: 24px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .school-context-banner .school-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .school-context-banner .school-info .school-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 18px;
        }

        .school-context-banner .school-info .school-name {
            font-weight: 600;
            color: #1a1a2e;
        }

        .school-context-banner .school-info .school-id {
            font-size: 12px;
            color: #6c757d;
        }

        .school-context-banner .badge-tenant {
            background: #f0f2f5;
            color: #6c757d;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
        }

        .school-context-banner .badge-tenant i {
            margin-right: 4px;
        }

        /* =============================================== */
        /* FORM CARD */
        /* =============================================== */
        .form-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            overflow: hidden;
        }

        .form-card .card-header-custom {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            background: transparent;
        }

        .form-card .card-header-custom h6 {
            font-weight: 600;
            margin: 0;
            font-size: 15px;
            color: #1a1a2e;
        }

        .form-card .card-body-custom {
            padding: 24px;
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
            box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.1);
        }

        .form-text {
            font-size: 11px;
            color: #6c757d;
            margin-top: 4px;
        }

        .form-section {
            margin-bottom: 28px;
        }

        .form-section:last-child {
            margin-bottom: 0;
        }

        .form-section .section-title {
            font-weight: 600;
            font-size: 15px;
            color: #1a1a2e;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f2f5;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-section .section-title i {
            color: #4facfe;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            padding-top: 16px;
            border-top: 2px solid #f0f2f5;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .form-actions .btn {
            border-radius: 10px;
            padding: 10px 28px;
            font-weight: 600;
            font-size: 13px;
        }

        /* =============================================== */
        /* STAFF CATEGORY SELECTION */
        /* =============================================== */
        .staff-category-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }

        .staff-category-card {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .staff-category-card:hover {
            border-color: #4facfe;
            background: #fff;
            transform: translateY(-2px);
        }

        .staff-category-card.selected {
            border-color: #4facfe;
            background: #e7f3ff;
        }

        .staff-category-card .category-icon {
            font-size: 28px;
            color: #4facfe;
            margin-bottom: 8px;
        }

        .staff-category-card .category-name {
            font-weight: 600;
            font-size: 14px;
            color: #1a1a2e;
        }

        .staff-category-card .category-desc {
            font-size: 12px;
            color: #6c757d;
        }

        /* =============================================== */
        /* STEP INDICATOR */
        /* =============================================== */
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 24px;
            padding: 0 20px;
            position: relative;
        }

        .step-indicator::before {
            content: '';
            position: absolute;
            top: 18px;
            left: 40px;
            right: 40px;
            height: 2px;
            background: #e9ecef;
            z-index: 0;
        }

        .step-indicator .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .step-indicator .step .step-circle {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #e9ecef;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s;
        }

        .step-indicator .step.active .step-circle {
            background: #4facfe;
            color: #fff;
            box-shadow: 0 4px 12px rgba(79, 172, 254, 0.4);
        }

        .step-indicator .step.completed .step-circle {
            background: #28a745;
            color: #fff;
        }

        .step-indicator .step .step-label {
            font-size: 11px;
            color: #6c757d;
            margin-top: 6px;
            text-align: center;
        }

        .step-indicator .step.active .step-label {
            color: #4facfe;
            font-weight: 600;
        }

        /* =============================================== */
        /* RESPONSIVE */
        /* =============================================== */
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

            .sidebar .sidebar-header .school-badge {
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

            .staff-category-grid {
                grid-template-columns: 1fr 1fr;
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

            .sidebar .sidebar-header h4 i {
                font-size: 20px;
            }

            .sidebar .sidebar-header small {
                display: block;
            }

            .sidebar .sidebar-header .school-badge {
                display: inline-block;
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
                font-size: 22px;
            }

            .top-bar .page-title p {
                font-size: 12px;
            }

            .top-bar .header-actions .btn {
                font-size: 12px;
                padding: 6px 12px;
            }

            .form-card .card-body-custom {
                padding: 16px;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
            }

            .staff-category-grid {
                grid-template-columns: 1fr 1fr;
            }

            .step-indicator {
                padding: 0 10px;
            }

            .step-indicator .step .step-label {
                font-size: 9px;
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

            .form-card .card-body-custom {
                padding: 12px;
            }

            .form-control,
            .form-select {
                font-size: 13px;
                padding: 6px 12px;
                height: 40px;
            }

            .form-label {
                font-size: 12px;
            }

            .staff-category-grid {
                grid-template-columns: 1fr;
            }

            .step-indicator .step .step-circle {
                width: 28px;
                height: 28px;
                font-size: 11px;
            }

            .step-indicator .step .step-label {
                font-size: 8px;
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
                    <small><?php echo htmlspecialchars($schoolName); ?></small>
                    <div class="school-badge"><i class="fas fa-building me-1"></i>School ID: <?php echo $schoolId; ?></div>
                </div>
                <div class="nav">
                    <div class="nav-label">Main</div>
                    <a class="nav-link" href="/platform/index.php">
                        <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                    </a>
                    <a class="nav-link" href="/platform/tenants/index.php">
                        <i class="fas fa-building"></i> <span>Tenants</span>
                    </a>
                    <a class="nav-link" href="/platform/users/index.php">
                        <i class="fas fa-users"></i> <span>Users</span>
                    </a>
                    <div class="nav-label mt-3">People</div>
                    <a class="nav-link" href="/platform/people/index.php">
                        <i class="fas fa-user-friends"></i> <span>People Directory</span>
                    </a>
                    <a class="nav-link" href="/identity/index.php">
                        <i class="fas fa-user-plus"></i> <span>Register</span>
                    </a>
                    <a class="nav-link" href="/identity/register-student.php">
                        <i class="fas fa-user-graduate"></i> <span>Students</span>
                    </a>
                    <a class="nav-link active" href="/identity/register-staff.php">
                        <i class="fas fa-user-tie"></i> <span>Staff</span>
                    </a>
                    <div class="nav-label mt-3">Institution</div>
                    <a class="nav-link" href="/platform/schools/index.php">
                        <i class="fas fa-school"></i> <span>Schools</span>
                    </a>
                    <a class="nav-link" href="/platform/campuses/index.php">
                        <i class="fas fa-map-marker-alt"></i> <span>Campuses</span>
                    </a>
                    <div class="nav-label mt-3">System</div>
                    <a class="nav-link" href="/platform/audit/index.php">
                        <i class="fas fa-history"></i> <span>Audit Logs</span>
                    </a>
                    <a class="nav-link" href="/platform/monitoring/index.php">
                        <i class="fas fa-chart-line"></i> <span>Monitoring</span>
                    </a>
                    <a class="nav-link" href="/platform/settings/index.php">
                        <i class="fas fa-cog"></i> <span>Settings</span>
                    </a>
                </div>
                <div class="sidebar-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="user-info">
                            <div class="user-avatar" id="userAvatar"><?php echo $userAvatar; ?></div>
                            <div>
                                <div class="user-name" id="userName"><?php echo htmlspecialchars($userName); ?></div>
                                <div class="user-role" id="userRole">Administrator</div>
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
                        <h1><i class="fas fa-user-tie me-2"></i>Staff Registration</h1>
                        <p>Register a new staff member for <strong><?php echo htmlspecialchars($schoolName); ?></strong></p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/people/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to People
                        </a>
                    </div>
                </div>

                <!-- School Context Banner -->
                <div class="school-context-banner">
                    <div class="school-info">
                        <div class="school-icon"><i class="fas fa-school"></i></div>
                        <div>
                            <div class="school-name"><?php echo htmlspecialchars($schoolName); ?></div>
                            <div class="school-id">School ID: <?php echo $schoolId; ?> | Tenant ID: <?php echo $tenantId; ?></div>
                        </div>
                    </div>
                    <span class="badge-tenant"><i class="fas fa-building"></i> Current School Context</span>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer"></div>

                <!-- Staff Registration Form -->
                <div class="form-card">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-user-tie me-2 text-primary"></i>Staff Registration Form</h6>
                        <span class="text-muted small">All fields marked with <span class="text-danger">*</span> are required</span>
                    </div>
                    <div class="card-body-custom">
                        <form id="staffRegistrationForm" novalidate>

                            <!-- Step Indicator -->
                            <div class="step-indicator" id="stepIndicator">
                                <div class="step active" data-step="1">
                                    <div class="step-circle">1</div>
                                    <span class="step-label">Category</span>
                                </div>
                                <div class="step" data-step="2">
                                    <div class="step-circle">2</div>
                                    <span class="step-label">Personal</span>
                                </div>
                                <div class="step" data-step="3">
                                    <div class="step-circle">3</div>
                                    <span class="step-label">Contact</span>
                                </div>
                                <div class="step" data-step="4">
                                    <div class="step-circle">4</div>
                                    <span class="step-label">Employment</span>
                                </div>
                                <div class="step" data-step="5">
                                    <div class="step-circle">5</div>
                                    <span class="step-label">Review</span>
                                </div>
                            </div>

                            <!-- STEP 1: Select Staff Category -->
                            <div class="form-section step-panel" data-step="1">
                                <div class="section-title"><i class="fas fa-tag"></i> Select Staff Category</div>
                                <div class="alert alert-info" role="alert">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Please select the staff category that best describes the staff member's role.
                                </div>
                                <div class="staff-category-grid" id="staffCategoryGrid">
                                    <div class="staff-category-card" data-category-id="1" onclick="selectCategory(1, 'Teaching Staff')">
                                        <div class="category-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                                        <div class="category-name">Teaching Staff</div>
                                        <div class="category-desc">Teacher, Lecturer, Instructor</div>
                                    </div>
                                    <div class="staff-category-card" data-category-id="2" onclick="selectCategory(2, 'Administrative Staff')">
                                        <div class="category-icon"><i class="fas fa-user-tie"></i></div>
                                        <div class="category-name">Administrative Staff</div>
                                        <div class="category-desc">Admin, Registrar, Secretary</div>
                                    </div>
                                    <div class="staff-category-card" data-category-id="3" onclick="selectCategory(3, 'Finance / Accounts')">
                                        <div class="category-icon"><i class="fas fa-coins"></i></div>
                                        <div class="category-name">Finance / Accounts</div>
                                        <div class="category-desc">Accountant, Bursar, Finance Officer</div>
                                    </div>
                                    <div class="staff-category-card" data-category-id="4" onclick="selectCategory(4, 'ICT / Technical')">
                                        <div class="category-icon"><i class="fas fa-laptop"></i></div>
                                        <div class="category-name">ICT / Technical</div>
                                        <div class="category-desc">IT Officer, Systems Administrator</div>
                                    </div>
                                    <div class="staff-category-card" data-category-id="5" onclick="selectCategory(5, 'Medical / Health')">
                                        <div class="category-icon"><i class="fas fa-heartbeat"></i></div>
                                        <div class="category-name">Medical / Health</div>
                                        <div class="category-desc">Nurse, Doctor, Health Officer</div>
                                    </div>
                                    <div class="staff-category-card" data-category-id="6" onclick="selectCategory(6, 'Library')">
                                        <div class="category-icon"><i class="fas fa-book"></i></div>
                                        <div class="category-name">Library</div>
                                        <div class="category-desc">Librarian, Library Assistant</div>
                                    </div>
                                    <div class="staff-category-card" data-category-id="7" onclick="selectCategory(7, 'Counselling')">
                                        <div class="category-icon"><i class="fas fa-hand-holding-heart"></i></div>
                                        <div class="category-name">Counselling</div>
                                        <div class="category-desc">Counsellor, Psychologist</div>
                                    </div>
                                    <div class="staff-category-card" data-category-id="8" onclick="selectCategory(8, 'Operations')">
                                        <div class="category-icon"><i class="fas fa-tasks"></i></div>
                                        <div class="category-name">Operations</div>
                                        <div class="category-desc">Operations Manager, Logistics</div>
                                    </div>
                                    <div class="staff-category-card" data-category-id="9" onclick="selectCategory(9, 'Maintenance')">
                                        <div class="category-icon"><i class="fas fa-tools"></i></div>
                                        <div class="category-name">Maintenance</div>
                                        <div class="category-desc">Maintenance Officer, Facilities</div>
                                    </div>
                                    <div class="staff-category-card" data-category-id="10" onclick="selectCategory(10, 'Security')">
                                        <div class="category-icon"><i class="fas fa-shield-alt"></i></div>
                                        <div class="category-name">Security</div>
                                        <div class="category-desc">Security Officer, Guard</div>
                                    </div>
                                    <div class="staff-category-card" data-category-id="11" onclick="selectCategory(11, 'Transport')">
                                        <div class="category-icon"><i class="fas fa-bus"></i></div>
                                        <div class="category-name">Transport</div>
                                        <div class="category-desc">Driver, Transport Officer</div>
                                    </div>
                                    <div class="staff-category-card" data-category-id="12" onclick="selectCategory(12, 'Boarding')">
                                        <div class="category-icon"><i class="fas fa-bed"></i></div>
                                        <div class="category-name">Boarding</div>
                                        <div class="category-desc">House Master, Matron</div>
                                    </div>
                                    <div class="staff-category-card" data-category-id="13" onclick="selectCategory(13, 'Other')">
                                        <div class="category-icon"><i class="fas fa-users"></i></div>
                                        <div class="category-name">Other</div>
                                        <div class="category-desc">Other Staff Categories</div>
                                    </div>
                                </div>
                                <input type="hidden" id="staffCategoryId" name="staffCategoryId" value="">
                                <div id="categoryError" class="text-danger mt-2" style="display:none;">Please select a staff category.</div>
                            </div>

                            <!-- STEP 2: Personal Information -->
                            <div class="form-section step-panel" data-step="2" style="display:none;">
                                <div class="section-title"><i class="fas fa-user"></i> Personal Information</div>
                                <div class="row">
                                    <div class="col-md-4 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">First Name <span class="required">*</span></label>
                                            <input type="text" class="form-control" id="firstName" name="firstName" required placeholder="Enter first name">
                                        </div>
                                    </div>
                                    <div class="col-md-4 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Middle Name</label>
                                            <input type="text" class="form-control" id="middleName" name="middleName" placeholder="Enter middle name">
                                        </div>
                                    </div>
                                    <div class="col-md-4 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Last Name <span class="required">*</span></label>
                                            <input type="text" class="form-control" id="lastName" name="lastName" required placeholder="Enter last name">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Date of Birth <span class="required">*</span></label>
                                            <input type="date" class="form-control" id="dateOfBirth" name="dateOfBirth" required>
                                        </div>
                                    </div>
                                    <div class="col-md-4 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Gender</label>
                                            <select class="form-select" id="gender" name="gender">
                                                <option value="">Select Gender</option>
                                                <option value="Male">Male</option>
                                                <option value="Female">Female</option>
                                                <option value="Other">Other</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Nationality</label>
                                            <input type="text" class="form-control" id="nationality" name="nationality" placeholder="Enter nationality" value="Ghanaian">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Primary Phone <span class="required">*</span></label>
                                            <input type="tel" class="form-control" id="primaryPhone" name="primaryPhone" required placeholder="Enter phone number">
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Primary Email <span class="required">*</span></label>
                                            <input type="email" class="form-control" id="primaryEmail" name="primaryEmail" required placeholder="Enter email address">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Address</label>
                                            <textarea class="form-control" id="address" name="address" rows="2" placeholder="Enter residential address"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- STEP 3: Contact & Address Details -->
                            <div class="form-section step-panel" data-step="3" style="display:none;">
                                <div class="section-title"><i class="fas fa-address-book"></i> Contact & Address Details</div>
                                <div class="row">
                                    <div class="col-md-6 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Secondary Phone</label>
                                            <input type="tel" class="form-control" id="secondaryPhone" name="secondaryPhone" placeholder="Enter secondary phone">
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Secondary Email</label>
                                            <input type="email" class="form-control" id="secondaryEmail" name="secondaryEmail" placeholder="Enter secondary email">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Emergency Contact Name</label>
                                            <input type="text" class="form-control" id="emergencyContactName" name="emergencyContactName" placeholder="Enter emergency contact name">
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Emergency Contact Phone</label>
                                            <input type="tel" class="form-control" id="emergencyContactPhone" name="emergencyContactPhone" placeholder="Enter emergency contact phone">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Religion</label>
                                            <input type="text" class="form-control" id="religion" name="religion" placeholder="Enter religion">
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Marital Status</label>
                                            <select class="form-select" id="maritalStatus" name="maritalStatus">
                                                <option value="">Select Marital Status</option>
                                                <option value="Single">Single</option>
                                                <option value="Married">Married</option>
                                                <option value="Divorced">Divorced</option>
                                                <option value="Widowed">Widowed</option>
                                                <option value="Separated">Separated</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- STEP 4: Employment Information -->
                            <div class="form-section step-panel" data-step="4" style="display:none;">
                                <div class="section-title"><i class="fas fa-briefcase"></i> Employment Information</div>
                                <div class="row">
                                    <div class="col-md-4 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Staff Number</label>
                                            <input type="text" class="form-control" id="staffNumber" name="staffNumber" placeholder="Auto-generated if left blank">
                                            <div class="form-text">Leave blank to auto-generate</div>
                                        </div>
                                    </div>
                                    <div class="col-md-4 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Employment Type <span class="required">*</span></label>
                                            <select class="form-select" id="employmentTypeId" name="employmentTypeId" required>
                                                <option value="">Select Employment Type</option>
                                                <option value="1">Full-Time</option>
                                                <option value="2">Part-Time</option>
                                                <option value="3">Contract</option>
                                                <option value="4">National Service</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Staff Status <span class="required">*</span></label>
                                            <select class="form-select" id="staffStatusId" name="staffStatusId" required>
                                                <option value="">Select Staff Status</option>
                                                <option value="1">Active</option>
                                                <option value="2">On Leave</option>
                                                <option value="3">Suspended</option>
                                                <option value="4">Terminated</option>
                                                <option value="5">Resigned</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Joining Date <span class="required">*</span></label>
                                            <input type="date" class="form-control" id="joiningDate" name="joiningDate" required>
                                        </div>
                                    </div>
                                    <div class="col-md-4 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Qualification</label>
                                            <input type="text" class="form-control" id="qualification" name="qualification" placeholder="e.g., BSc Computer Science, MBA">
                                        </div>
                                    </div>
                                    <div class="col-md-4 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Is Teaching Staff?</label>
                                            <select class="form-select" id="isTeachingStaff" name="isTeachingStaff">
                                                <option value="0">No</option>
                                                <option value="1">Yes</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Bank Name</label>
                                            <input type="text" class="form-control" id="bankName" name="bankName" placeholder="Enter bank name">
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Bank Account Number</label>
                                            <input type="text" class="form-control" id="bankAccountNumber" name="bankAccountNumber" placeholder="Enter bank account number">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">SSNIT Number</label>
                                            <input type="text" class="form-control" id="ssnitNumber" name="ssnitNumber" placeholder="Enter SSNIT number">
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-12">
                                        <div class="mb-3">
                                            <label class="form-label">TIN Number</label>
                                            <input type="text" class="form-control" id="tinNumber" name="tinNumber" placeholder="Enter TIN number">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Notes</label>
                                            <textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Additional notes about the staff member"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- STEP 5: Review & Submit -->
                            <div class="form-section step-panel" data-step="5" style="display:none;">
                                <div class="section-title"><i class="fas fa-check-double"></i> Review & Submit</div>
                                <div class="alert alert-info" role="alert">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Please review all information before submitting. You can go back to any step to make changes.
                                </div>
                                <div id="reviewContainer"></div>
                            </div>

                            <!-- Form Navigation -->
                            <div class="form-actions" id="formActions">
                                <button type="button" class="btn btn-outline-secondary" id="prevBtn" style="display:none;" onclick="prevStep()">
                                    <i class="fas fa-arrow-left me-2"></i> Previous
                                </button>
                                <button type="button" class="btn btn-primary" id="nextBtn" onclick="nextStep()">
                                    <i class="fas fa-arrow-right me-2"></i> Next
                                </button>
                                <button type="button" class="btn btn-success" id="submitBtn" style="display:none;" onclick="submitForm()">
                                    <i class="fas fa-save me-2"></i> Register Staff
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
        // ==============================================
        // CONFIGURATION
        // ==============================================
        const API_BASE = '<?php echo $apiBase; ?>';
        const TENANT_ID = <?php echo json_encode($tenantId); ?>;
        const SCHOOL_ID = <?php echo json_encode($schoolId); ?>;
        const TOKEN = localStorage.getItem('token') || '';

        let currentStep = 1;
        const totalSteps = 5;
        let selectedCategoryId = 0;
        let selectedCategoryName = '';

        // ==============================================
        // TOGGLE SIDEBAR
        // ==============================================
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }

        // ==============================================
        // LOGOUT
        // ==============================================
        function logout() {
            localStorage.removeItem('token');
            window.location.href = '/platform/login.php';
        }

        // ==============================================
        // HELPER FUNCTIONS
        // ==============================================
        function getHeaders() {
            return {
                'Authorization': 'Bearer ' + TOKEN,
                'Content-Type': 'application/json',
                'X-Tenant-ID': TENANT_ID,
                'X-School-ID': SCHOOL_ID
            };
        }

        function showAlert(message, type = 'info') {
            const container = document.getElementById('alertContainer');
            const colors = {
                success: 'alert-success',
                danger: 'alert-danger',
                warning: 'alert-warning',
                info: 'alert-info'
            };
            const icons = {
                success: 'fa-check-circle',
                danger: 'fa-exclamation-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            };
            container.innerHTML = `
            <div class="alert ${colors[type] || 'alert-info'} alert-dismissible fade show" role="alert" style="border-radius:12px;border:none;box-shadow:0 4px 20px rgba(0,0,0,0.06);">
                <i class="fas ${icons[type] || 'fa-info-circle'} me-2"></i> ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
            setTimeout(() => {
                const alert = container.querySelector('.alert');
                if (alert) {
                    alert.classList.remove('show');
                    setTimeout(() => {
                        container.innerHTML = '';
                    }, 300);
                }
            }, 5000);
        }

        // ==============================================
        // SELECT STAFF CATEGORY
        // ==============================================
        function selectCategory(id, name) {
            selectedCategoryId = id;
            selectedCategoryName = name;
            document.getElementById('staffCategoryId').value = id;
            document.getElementById('categoryError').style.display = 'none';

            // Update UI
            document.querySelectorAll('.staff-category-card').forEach(card => {
                card.classList.toggle('selected', parseInt(card.dataset.categoryId) === id);
            });

            // Move to next step
            currentStep = 2;
            showStep(currentStep);
            updateStepIndicator();
        }

        // ==============================================
        // STEP NAVIGATION
        // ==============================================
        function nextStep() {
            if (!validateStep(currentStep)) {
                return;
            }
            if (currentStep < totalSteps) {
                currentStep++;
                showStep(currentStep);
                updateStepIndicator();
                document.querySelector('.form-card').scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        }

        function prevStep() {
            if (currentStep > 1) {
                currentStep--;
                showStep(currentStep);
                updateStepIndicator();
                document.querySelector('.form-card').scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        }

        function showStep(step) {
            document.querySelectorAll('.step-panel').forEach(el => {
                el.style.display = parseInt(el.dataset.step) === step ? 'block' : 'none';
            });

            document.getElementById('prevBtn').style.display = step > 1 ? 'inline-block' : 'none';
            document.getElementById('nextBtn').style.display = step < totalSteps ? 'inline-block' : 'none';
            document.getElementById('submitBtn').style.display = step === totalSteps ? 'inline-block' : 'none';

            if (step === totalSteps) {
                buildReview();
            }
        }

        function updateStepIndicator() {
            document.querySelectorAll('.step-indicator .step').forEach(el => {
                const stepNum = parseInt(el.dataset.step);
                el.classList.remove('active', 'completed');
                if (stepNum === currentStep) {
                    el.classList.add('active');
                } else if (stepNum < currentStep) {
                    el.classList.add('completed');
                }
            });
        }

        // ==============================================
        // VALIDATE STEP
        // ==============================================
        function validateStep(step) {
            let valid = true;

            if (step === 1) {
                if (!selectedCategoryId) {
                    document.getElementById('categoryError').style.display = 'block';
                    valid = false;
                }
            }

            if (step === 2) {
                const firstName = document.getElementById('firstName').value.trim();
                const lastName = document.getElementById('lastName').value.trim();
                const dob = document.getElementById('dateOfBirth').value;
                const phone = document.getElementById('primaryPhone').value.trim();
                const email = document.getElementById('primaryEmail').value.trim();

                if (!firstName) {
                    document.getElementById('firstName').classList.add('is-invalid');
                    valid = false;
                } else {
                    document.getElementById('firstName').classList.remove('is-invalid');
                }
                if (!lastName) {
                    document.getElementById('lastName').classList.add('is-invalid');
                    valid = false;
                } else {
                    document.getElementById('lastName').classList.remove('is-invalid');
                }
                if (!dob) {
                    document.getElementById('dateOfBirth').classList.add('is-invalid');
                    valid = false;
                } else {
                    document.getElementById('dateOfBirth').classList.remove('is-invalid');
                }
                if (!phone) {
                    document.getElementById('primaryPhone').classList.add('is-invalid');
                    valid = false;
                } else {
                    document.getElementById('primaryPhone').classList.remove('is-invalid');
                }
                if (!email) {
                    document.getElementById('primaryEmail').classList.add('is-invalid');
                    valid = false;
                } else {
                    document.getElementById('primaryEmail').classList.remove('is-invalid');
                }

                if (!valid) {
                    showAlert('Please fill in all required personal information fields.', 'warning');
                }
            }

            if (step === 4) {
                const employmentType = document.getElementById('employmentTypeId').value;
                const staffStatus = document.getElementById('staffStatusId').value;
                const joiningDate = document.getElementById('joiningDate').value;

                if (!employmentType) {
                    document.getElementById('employmentTypeId').classList.add('is-invalid');
                    valid = false;
                } else {
                    document.getElementById('employmentTypeId').classList.remove('is-invalid');
                }
                if (!staffStatus) {
                    document.getElementById('staffStatusId').classList.add('is-invalid');
                    valid = false;
                } else {
                    document.getElementById('staffStatusId').classList.remove('is-invalid');
                }
                if (!joiningDate) {
                    document.getElementById('joiningDate').classList.add('is-invalid');
                    valid = false;
                } else {
                    document.getElementById('joiningDate').classList.remove('is-invalid');
                }

                if (!valid) {
                    showAlert('Please fill in all required employment information fields.', 'warning');
                }
            }

            return valid;
        }

        // ==============================================
        // BUILD REVIEW
        // ==============================================
        function buildReview() {
            const container = document.getElementById('reviewContainer');

            const firstName = document.getElementById('firstName').value || 'N/A';
            const middleName = document.getElementById('middleName').value || '';
            const lastName = document.getElementById('lastName').value || 'N/A';
            const fullName = firstName + (middleName ? ' ' + middleName : '') + ' ' + lastName;

            let html = `
            <div class="review-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="review-section" style="background:#f8f9fa;padding:16px;border-radius:12px;">
                    <h6 style="font-weight:600;color:#1a1a2e;margin-bottom:12px;">
                        <i class="fas fa-tag me-2 text-primary"></i>Category
                    </h6>
                    <div><strong>Staff Category:</strong> ${selectedCategoryName}</div>
                </div>
                <div class="review-section" style="background:#f8f9fa;padding:16px;border-radius:12px;">
                    <h6 style="font-weight:600;color:#1a1a2e;margin-bottom:12px;">
                        <i class="fas fa-user me-2 text-primary"></i>Personal Information
                    </h6>
                    <div><strong>Full Name:</strong> ${fullName}</div>
                    <div><strong>Date of Birth:</strong> ${document.getElementById('dateOfBirth').value || 'N/A'}</div>
                    <div><strong>Gender:</strong> ${document.getElementById('gender').value || 'N/A'}</div>
                    <div><strong>Nationality:</strong> ${document.getElementById('nationality').value || 'N/A'}</div>
                    <div><strong>Phone:</strong> ${document.getElementById('primaryPhone').value || 'N/A'}</div>
                    <div><strong>Email:</strong> ${document.getElementById('primaryEmail').value || 'N/A'}</div>
                </div>
                <div class="review-section" style="background:#f8f9fa;padding:16px;border-radius:12px;">
                    <h6 style="font-weight:600;color:#1a1a2e;margin-bottom:12px;">
                        <i class="fas fa-address-book me-2 text-primary"></i>Contact Details
                    </h6>
                    <div><strong>Secondary Phone:</strong> ${document.getElementById('secondaryPhone').value || 'N/A'}</div>
                    <div><strong>Secondary Email:</strong> ${document.getElementById('secondaryEmail').value || 'N/A'}</div>
                    <div><strong>Emergency Contact:</strong> ${document.getElementById('emergencyContactName').value || 'N/A'}</div>
                    <div><strong>Emergency Phone:</strong> ${document.getElementById('emergencyContactPhone').value || 'N/A'}</div>
                    <div><strong>Religion:</strong> ${document.getElementById('religion').value || 'N/A'}</div>
                    <div><strong>Marital Status:</strong> ${document.getElementById('maritalStatus').value || 'N/A'}</div>
                </div>
                <div class="review-section" style="background:#f8f9fa;padding:16px;border-radius:12px;">
                    <h6 style="font-weight:600;color:#1a1a2e;margin-bottom:12px;">
                        <i class="fas fa-briefcase me-2 text-primary"></i>Employment Information
                    </h6>
                    <div><strong>Staff Number:</strong> ${document.getElementById('staffNumber').value || 'Auto-generated'}</div>
                    <div><strong>Employment Type:</strong> ${document.getElementById('employmentTypeId').options[document.getElementById('employmentTypeId').selectedIndex]?.text || 'N/A'}</div>
                    <div><strong>Staff Status:</strong> ${document.getElementById('staffStatusId').options[document.getElementById('staffStatusId').selectedIndex]?.text || 'N/A'}</div>
                    <div><strong>Joining Date:</strong> ${document.getElementById('joiningDate').value || 'N/A'}</div>
                    <div><strong>Qualification:</strong> ${document.getElementById('qualification').value || 'N/A'}</div>
                    <div><strong>Teaching Staff:</strong> ${document.getElementById('isTeachingStaff').value === '1' ? 'Yes' : 'No'}</div>
                </div>
            </div>
        `;

            container.innerHTML = html;
        }

        // ==============================================
        // SUBMIT FORM - NESTED STRUCTURE LIKE STUDENT API
        // ==============================================
        function submitForm() {
            if (!validateStep(currentStep)) {
                return;
            }

            // Build payload with nested structure (matching student API pattern)
            const payload = {
                person: {
                    first_name: document.getElementById('firstName').value.trim(),
                    middle_name: document.getElementById('middleName').value.trim(),
                    last_name: document.getElementById('lastName').value.trim(),
                    date_of_birth: document.getElementById('dateOfBirth').value,
                    gender: document.getElementById('gender').value,
                    nationality: document.getElementById('nationality').value,
                    primary_phone: document.getElementById('primaryPhone').value.trim(),
                    primary_email: document.getElementById('primaryEmail').value.trim(),
                    secondary_phone: document.getElementById('secondaryPhone').value.trim(),
                    secondary_email: document.getElementById('secondaryEmail').value.trim(),
                    address: document.getElementById('address').value.trim(),
                    emergency_contact_name: document.getElementById('emergencyContactName').value.trim(),
                    emergency_contact_phone: document.getElementById('emergencyContactPhone').value.trim(),
                    religion: document.getElementById('religion').value,
                    marital_status: document.getElementById('maritalStatus').value,
                    school_id: SCHOOL_ID,
                    tenant_id: TENANT_ID,
                    person_type: 'staff',
                    is_staff: 1,
                    is_active: 1
                },
                staff: {
                    staff_number: document.getElementById('staffNumber').value.trim() || '',
                    staff_category_id: selectedCategoryId,
                    employment_type_id: parseInt(document.getElementById('employmentTypeId').value),
                    staff_status_id: parseInt(document.getElementById('staffStatusId').value),
                    hire_date: document.getElementById('joiningDate').value,
                    qualification: document.getElementById('qualification').value.trim(),
                    is_teaching_staff: parseInt(document.getElementById('isTeachingStaff').value),
                    bank_name: document.getElementById('bankName').value.trim(),
                    bank_account_number: document.getElementById('bankAccountNumber').value.trim(),
                    ssnit_number: document.getElementById('ssnitNumber').value.trim(),
                    tin_number: document.getElementById('tinNumber').value.trim(),
                    notes: document.getElementById('notes').value.trim(),
                    is_active: 1,
                    school_id: SCHOOL_ID
                }
            };

            // Log the payload for debugging
            console.log('Sending payload:', JSON.stringify(payload, null, 2));

            // Disable submit button
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Registering...';

            // Submit to API
            fetch(API_BASE + '/index.php?endpoint=staff&action=register', {
                    method: 'POST',
                    headers: getHeaders(),
                    body: JSON.stringify(payload)
                })
                .then(response => response.json())
                .then(result => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-save me-2"></i> Register Staff';

                    if (result.success) {
                        showAlert('Staff registered successfully!', 'success');
                        setTimeout(() => {
                            window.location.href = '/platform/people/view.php?id=' + result.data.person_id;
                        }, 2000);
                    } else {
                        if (result.message && result.message.includes('Duplicate entry')) {
                            showAlert('A staff member with this staff number already exists. Please check the staff number or leave it blank to auto-generate.', 'warning');
                        } else {
                            showAlert(result.message || 'Error registering staff. Please try again.', 'danger');
                        }
                    }
                })
                .catch(error => {
                    console.error('Error registering staff:', error);
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-save me-2"></i> Register Staff';
                    showAlert('Error connecting to server. Please try again.', 'danger');
                });
        }
        // ==============================================
        // ENTER KEY SUPPORT
        // ==============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
                const nextBtn = document.getElementById('nextBtn');
                if (nextBtn.style.display !== 'none') {
                    e.preventDefault();
                    nextBtn.click();
                }
            }
        });

        // ==============================================
        // INITIALIZE
        // ==============================================
        document.addEventListener('DOMContentLoaded', function() {
            // Load user info
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

            // Set default date for joining date
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('joiningDate').value = today;

            // Show step 1
            showStep(1);
            updateStepIndicator();
        });
    </script>
</body>

</html>