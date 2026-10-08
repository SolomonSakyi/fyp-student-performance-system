<?php

/**
 * Payment Dashboard - Payment monitoring and management
 */
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

$pageTitle = 'Payment Dashboard - EduTrack Platform';
$currentPage = 'payments';

// Determine API base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$apiBase = $protocol . '://' . $host . '/api/platform';
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

        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 20px;
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

        .stat-card .stat-icon.yellow {
            background: #fff3cd;
            color: #ffc107;
        }

        .stat-card .stat-icon.red {
            background: #f8d7da;
            color: #dc3545;
        }

        .stat-card .stat-icon.purple {
            background: #e8d5f5;
            color: #6f42c1;
        }

        .stat-card .stat-icon.orange {
            background: #ffe8d6;
            color: #fd7e14;
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

        .filter-section {
            background: #fff;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 20px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
        }

        .filter-section .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-section .filter-group label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            margin: 0;
        }

        .filter-section .filter-group .form-control,
        .filter-section .filter-group .form-select {
            border-radius: 8px;
            padding: 6px 12px;
            border: 2px solid #e9ecef;
            font-size: 13px;
            height: 38px;
            min-width: 130px;
            background: #fff;
        }

        .filter-section .filter-group .form-control:focus,
        .filter-section .filter-group .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .filter-section .filter-group .btn {
            border-radius: 8px;
            padding: 6px 14px;
            font-size: 13px;
            height: 38px;
        }

        .filter-section .filter-group .btn i {
            margin-right: 4px;
        }

        .search-wrapper {
            position: relative;
            flex: 1;
            min-width: 200px;
        }

        .autocomplete-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #fff;
            border: 2px solid #4facfe;
            border-top: none;
            border-radius: 0 0 10px 10px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .autocomplete-dropdown .autocomplete-item {
            padding: 8px 14px;
            cursor: pointer;
            border-bottom: 1px solid #f0f2f5;
            font-size: 13px;
            transition: background 0.2s;
        }

        .autocomplete-dropdown .autocomplete-item:hover {
            background: #f0f7ff;
        }

        .autocomplete-dropdown .autocomplete-item:last-child {
            border-bottom: none;
        }

        .table-container {
            background: #fff;
            border-radius: 14px;
            padding: 0;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            width: 100%;
        }

        .table-container .table {
            margin: 0;
            font-size: 13px;
            width: 100%;
        }

        .table-container .table thead th {
            background: #f8f9fa;
            padding: 12px 16px;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            border-bottom: 2px solid #e9ecef;
            white-space: nowrap;
        }

        .table-container .table tbody td {
            padding: 12px 16px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .table-container .table tbody tr:hover {
            background: #f8f9fa;
        }

        .table-container .table tbody tr:last-child td {
            border-bottom: none;
        }

        .badge-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-status.success {
            background: #d4edda;
            color: #155724;
        }

        .badge-status.pending {
            background: #fff3cd;
            color: #856404;
        }

        .badge-status.failed {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-status.processing {
            background: #cce5ff;
            color: #004085;
        }

        .badge-status.refunded {
            background: #e8d5f5;
            color: #6f42c1;
        }

        .table-actions .btn {
            padding: 4px 8px;
            font-size: 12px;
            border-radius: 6px;
            line-height: 1.4;
        }

        .table-actions .btn i {
            margin-right: 2px;
        }

        .pagination-wrapper {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .pagination-wrapper .info {
            color: #6c757d;
            font-size: 13px;
        }

        .pagination .page-link {
            padding: 6px 12px;
            font-size: 13px;
            border-radius: 6px;
            color: #1a1a2e;
        }

        .pagination .page-item.active .page-link {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border-color: #4facfe;
            color: #fff;
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

            .stats-row {
                grid-template-columns: repeat(2, 1fr);
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

            .stats-row {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }

            .stat-card {
                padding: 12px 14px;
            }

            .stat-card .stat-number {
                font-size: 18px;
            }

            .stat-card .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 14px;
            }

            .filter-section {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-section .filter-group {
                flex-wrap: wrap;
            }

            .filter-section .filter-group .form-control,
            .filter-section .filter-group .form-select {
                min-width: 100px;
                flex: 1;
            }

            .table-container .table {
                font-size: 12px;
            }

            .table-container .table thead th {
                padding: 8px 10px;
                font-size: 10px;
            }

            .table-container .table tbody td {
                padding: 8px 10px;
            }

            .pagination-wrapper {
                flex-direction: column;
                align-items: center;
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
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }

            .stat-card {
                padding: 10px 12px;
            }

            .stat-card .stat-number {
                font-size: 16px;
            }

            .stat-card .stat-icon {
                width: 32px;
                height: 32px;
                font-size: 12px;
            }

            .stat-card .stat-label {
                font-size: 10px;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>

            <nav class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <h4><i class="fas fa-graduation-cap me-2"></i>EduTrack</h4>
                    <small>Platform Administration</small>
                </div>
                <div class="nav">
                    <div class="nav-label">Main</div>
                    <a class="nav-link <?php echo ($currentPage == 'dashboard') ? 'active' : ''; ?>" href="/platform/index.php">
                        <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                    </a>
                    <a class="nav-link <?php echo ($currentPage == 'tenants') ? 'active' : ''; ?>" href="/platform/tenants/index.php">
                        <i class="fas fa-building"></i> <span>Tenants</span>
                    </a>
                    <a class="nav-link <?php echo ($currentPage == 'users') ? 'active' : ''; ?>" href="/platform/users/index.php">
                        <i class="fas fa-users"></i> <span>Users</span>
                    </a>

                    <div class="nav-label mt-3">Institution</div>
                    <a class="nav-link <?php echo ($currentPage == 'schools') ? 'active' : ''; ?>" href="/platform/schools/index.php">
                        <i class="fas fa-school"></i> <span>Schools</span>
                    </a>
                    <a class="nav-link <?php echo ($currentPage == 'campuses') ? 'active' : ''; ?>" href="/platform/campuses/index.php">
                        <i class="fas fa-map-marker-alt"></i> <span>Campuses</span>
                    </a>

                    <div class="nav-label mt-3">Management</div>
                    <a class="nav-link <?php echo ($currentPage == 'subscriptions') ? 'active' : ''; ?>" href="/platform/subscriptions/index.php">
                        <i class="fas fa-crown"></i> <span>Subscriptions</span>
                    </a>
                    <a class="nav-link <?php echo ($currentPage == 'domains') ? 'active' : ''; ?>" href="/platform/domains/index.php">
                        <i class="fas fa-globe"></i> <span>Domains</span>
                    </a>

                    <div class="nav-label mt-3">System</div>
                    <a class="nav-link <?php echo ($currentPage == 'audit') ? 'active' : ''; ?>" href="/platform/audit/index.php">
                        <i class="fas fa-history"></i> <span>Audit Logs</span>
                    </a>
                    <a class="nav-link <?php echo ($currentPage == 'monitoring') ? 'active' : ''; ?>" href="/platform/monitoring/index.php">
                        <i class="fas fa-chart-line"></i> <span>Monitoring</span>
                    </a>
                    <a class="nav-link <?php echo ($currentPage == 'settings') ? 'active' : ''; ?>" href="/platform/settings/index.php">
                        <i class="fas fa-cog"></i> <span>Settings</span>
                    </a>
                </div>
                <div class="sidebar-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="user-info">
                            <div class="user-avatar" id="userAvatar">A</div>
                            <div>
                                <div class="user-name" id="userName">Admin</div>
                                <div class="user-role" id="userRole">Administrator</div>
                            </div>
                        </div>
                        <button class="logout-btn" onclick="logout()" title="Logout">
                            <i class="fas fa-sign-out-alt"></i>
                        </button>
                    </div>
                </div>
            </nav>

            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-credit-card me-2"></i>Payment Dashboard</h1>
                        <p>Monitor and manage all payment transactions</p>
                    </div>
                    <div class="header-actions">
                        <span id="lastUpdated" style="font-size:12px;color:#6c757d;">
                            <i class="fas fa-clock me-1"></i> Last updated: Just now
                        </span>
                        <button class="btn btn-outline-secondary" onclick="refreshData()">
                            <i class="fas fa-sync-alt me-2"></i> Refresh
                        </button>
                        <button class="btn btn-outline-success" onclick="runReconciliation()">
                            <i class="fas fa-balance-scale me-2"></i> Run Reconciliation
                        </button>
                    </div>
                </div>

                <div id="alertContainer"></div>

                <!-- Stats Row 1 -->
                <div class="stats-row" id="statsRow">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-dollar-sign"></i></div>
                        <div>
                            <div class="stat-number" id="totalRevenue">GHS 0</div>
                            <div class="stat-label">Total Revenue</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                        <div>
                            <div class="stat-number" id="successfulPayments">0</div>
                            <div class="stat-label">Successful Payments</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon yellow"><i class="fas fa-clock"></i></div>
                        <div>
                            <div class="stat-number" id="pendingPayments">0</div>
                            <div class="stat-label">Pending Payments</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red"><i class="fas fa-exclamation-circle"></i></div>
                        <div>
                            <div class="stat-number" id="failedPayments">0</div>
                            <div class="stat-label">Failed Payments</div>
                        </div>
                    </div>
                </div>

                <!-- Stats Row 2 -->
                <div class="stats-row" id="statsRow2">
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-file-invoice"></i></div>
                        <div>
                            <div class="stat-number" id="outstandingInvoices">0</div>
                            <div class="stat-label">Outstanding Invoices</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-crown"></i></div>
                        <div>
                            <div class="stat-number" id="activeSubscriptions">0</div>
                            <div class="stat-label">Active Subscriptions</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red"><i class="fas fa-times-circle"></i></div>
                        <div>
                            <div class="stat-number" id="expiredSubscriptions">0</div>
                            <div class="stat-label">Expired Subscriptions</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-undo"></i></div>
                        <div>
                            <div class="stat-number" id="refunds">0</div>
                            <div class="stat-label">Refunds</div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filter-section">
                    <div class="filter-group">
                        <label for="searchInput"><i class="fas fa-search"></i></label>
                        <div class="search-wrapper">
                            <input type="text" class="form-control" id="searchInput" placeholder="Search transactions..." oninput="handleSearchInput()" onkeydown="handleSearchKeydown(event)">
                            <div class="autocomplete-dropdown" id="autocompleteDropdown"></div>
                        </div>
                    </div>
                    <div class="filter-group">
                        <label for="statusFilter">Status</label>
                        <select class="form-select" id="statusFilter" onchange="applyFilters()">
                            <option value="">All Status</option>
                            <option value="SUCCESS">Success</option>
                            <option value="PENDING">Pending</option>
                            <option value="PROCESSING">Processing</option>
                            <option value="FAILED">Failed</option>
                            <option value="CANCELLED">Cancelled</option>
                            <option value="REFUNDED">Refunded</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="providerFilter">Provider</label>
                        <select class="form-select" id="providerFilter" onchange="applyFilters()">
                            <option value="">All Providers</option>
                            <option value="hubtel">Hubtel</option>
                            <option value="paystack">Paystack</option>
                            <option value="flutterwave">Flutterwave</option>
                            <option value="bank">Bank API</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="tenantFilter">Tenant</label>
                        <select class="form-select" id="tenantFilter" onchange="applyFilters()">
                            <option value="">All Tenants</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <button class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-filter me-1"></i> Apply</button>
                        <button class="btn btn-outline-secondary" onclick="resetFilters()"><i class="fas fa-undo me-1"></i> Reset</button>
                    </div>
                </div>

                <!-- Transactions Table -->
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Tenant</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Provider</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th style="text-align:center;min-width:120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="transactionsTableBody">
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-2 text-muted">Loading transactions...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="pagination-wrapper">
                        <div class="info" id="paginationInfo">Showing 0 of 0 transactions</div>
                        <nav>
                            <ul class="pagination" id="paginationControls">
                                <li class="page-item disabled"><a class="page-link" href="#" onclick="loadTransactions(1)">First</a></li>
                                <li class="page-item disabled"><a class="page-link" href="#" onclick="loadTransactions(currentPage - 1)">Prev</a></li>
                                <li class="page-item active"><a class="page-link" href="#">1</a></li>
                                <li class="page-item disabled"><a class="page-link" href="#" onclick="loadTransactions(currentPage + 1)">Next</a></li>
                                <li class="page-item disabled"><a class="page-link" href="#" onclick="loadTransactions(totalPages)">Last</a></li>
                            </ul>
                        </nav>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // ================================================
        // CONFIGURATION
        // ================================================
        const API_BASE = '<?php echo $apiBase; ?>';
        const TOKEN = localStorage.getItem('token') || '';
        let currentPage = 1;
        let totalPages = 1;
        let totalRecords = 0;
        let searchTimeout = null;

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
                window.location.href = '<?php echo $protocol; ?>://<?php echo $host; ?>/platform/login.php';
            }
        }

        // ================================================
        // API HELPERS
        // ================================================
        function getHeaders() {
            return {
                'Authorization': 'Bearer ' + TOKEN,
                'Content-Type': 'application/json'
            };
        }

        function formatCurrency(amount) {
            if (!amount || amount == 0) return 'GHS 0';
            return 'GHS ' + parseFloat(amount).toFixed(2);
        }

        function formatDateTime(dateStr) {
            if (!dateStr) return 'N/A';
            const date = new Date(dateStr);
            return date.toLocaleString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function getStatusBadge(status) {
            const map = {
                'SUCCESS': '<span class="badge-status success">Success</span>',
                'PENDING': '<span class="badge-status pending">Pending</span>',
                'PROCESSING': '<span class="badge-status processing">Processing</span>',
                'FAILED': '<span class="badge-status failed">Failed</span>',
                'CANCELLED': '<span class="badge-status failed">Cancelled</span>',
                'EXPIRED': '<span class="badge-status failed">Expired</span>',
                'REFUNDED': '<span class="badge-status refunded">Refunded</span>'
            };
            return map[status] || '<span class="badge-status">' + status + '</span>';
        }

        // ================================================
        // SHOW ALERT
        // ================================================
        function showAlert(message, type = 'info') {
            const container = document.getElementById('alertContainer');
            const colors = {
                success: 'alert-success',
                danger: 'alert-danger',
                warning: 'alert-warning',
                info: 'alert-info'
            };
            container.innerHTML = `
                <div class="alert ${colors[type] || 'alert-info'} alert-dismissible fade show" role="alert" style="border-radius:12px;border:none;box-shadow:0 4px 20px rgba(0,0,0,0.06);">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle'} me-2"></i>
                    ${message}
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

        // ================================================
        // LOAD TENANT DROPDOWN
        // ================================================
        async function loadTenantDropdown() {
            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=tenants&action=list`, {
                    headers: getHeaders()
                });
                const result = await response.json();
                if (result.success && result.data && result.data.tenants) {
                    const select = document.getElementById('tenantFilter');
                    while (select.options.length > 1) {
                        select.remove(1);
                    }
                    result.data.tenants.forEach(tenant => {
                        const option = document.createElement('option');
                        option.value = tenant.id;
                        option.textContent = tenant.tenant_name || tenant.legal_name || 'Tenant #' + tenant.id;
                        select.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('Error loading tenants:', error);
            }
        }

        // ================================================
        // AUTO-SEARCH / INTELLISENSE
        // ================================================
        async function handleSearchInput() {
            const searchTerm = document.getElementById('searchInput').value.trim();
            const dropdown = document.getElementById('autocompleteDropdown');

            clearTimeout(searchTimeout);

            if (searchTerm.length < 2) {
                dropdown.style.display = 'none';
                return;
            }

            searchTimeout = setTimeout(async () => {
                try {
                    const response = await fetch(`${API_BASE}/index.php?endpoint=payments&action=autocomplete&search=${encodeURIComponent(searchTerm)}`, {
                        headers: getHeaders()
                    });
                    const result = await response.json();

                    if (result.success && result.data && result.data.length > 0) {
                        dropdown.innerHTML = result.data.map(item =>
                            `<div class="autocomplete-item" onclick="selectAutocompleteItem('${item.internal_reference || item.value || ''}', '${item.id || ''}')">
                                <strong>${item.internal_reference || item.value || 'N/A'}</strong>
                                ${item.tenant_name ? `<span class="text-muted ms-2">${item.tenant_name}</span>` : ''}
                                ${item.amount ? `<span class="badge bg-secondary ms-2">${formatCurrency(item.amount)}</span>` : ''}
                                ${item.status ? `<span class="badge bg-info ms-2">${item.status}</span>` : ''}
                            </div>`
                        ).join('');
                        dropdown.style.display = 'block';
                    } else {
                        dropdown.innerHTML = `<div class="autocomplete-item text-muted">No results found</div>`;
                        dropdown.style.display = 'block';
                    }
                } catch (error) {
                    console.error('Autocomplete error:', error);
                    dropdown.style.display = 'none';
                }
            }, 300);
        }

        function handleSearchKeydown(event) {
            if (event.key === 'Enter') {
                document.getElementById('autocompleteDropdown').style.display = 'none';
                applyFilters();
            }
            if (event.key === 'Escape') {
                document.getElementById('autocompleteDropdown').style.display = 'none';
            }
        }

        function selectAutocompleteItem(value, id) {
            document.getElementById('searchInput').value = value;
            document.getElementById('autocompleteDropdown').style.display = 'none';
            applyFilters();
        }

        document.addEventListener('click', function(event) {
            const wrapper = document.querySelector('.search-wrapper');
            if (wrapper && !wrapper.contains(event.target)) {
                document.getElementById('autocompleteDropdown').style.display = 'none';
            }
        });

        // ================================================
        // UPDATE LAST UPDATED
        // ================================================
        function updateLastUpdated() {
            const now = new Date();
            document.getElementById('lastUpdated').innerHTML = `
                <i class="fas fa-clock me-1"></i> Last updated: ${now.toLocaleTimeString()}
            `;
        }

        // ================================================
        // LOAD STATS
        // ================================================
        async function loadStats() {
            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=payments&action=stats`, {
                    headers: getHeaders()
                });
                const result = await response.json();
                console.log('Stats Response:', result);
                if (result.success && result.data) {
                    const stats = result.data;
                    document.getElementById('totalRevenue').textContent = formatCurrency(stats.total_revenue || 0);
                    document.getElementById('successfulPayments').textContent = stats.successful || 0;
                    document.getElementById('pendingPayments').textContent = stats.pending || 0;
                    document.getElementById('failedPayments').textContent = stats.failed || 0;
                    document.getElementById('outstandingInvoices').textContent = stats.outstanding_invoices || 0;
                    document.getElementById('activeSubscriptions').textContent = stats.active_subscriptions || 0;
                    document.getElementById('expiredSubscriptions').textContent = stats.expired_subscriptions || 0;
                    document.getElementById('refunds').textContent = stats.refunds || 0;
                }
            } catch (error) {
                console.error('Error loading stats:', error);
            }
        }

        // ================================================
        // FILTERS
        // ================================================
        function getFilters() {
            return {
                search: document.getElementById('searchInput').value.trim(),
                status: document.getElementById('statusFilter').value,
                provider: document.getElementById('providerFilter').value,
                tenantId: document.getElementById('tenantFilter').value
            };
        }

        function applyFilters() {
            document.getElementById('autocompleteDropdown').style.display = 'none';
            loadTransactions(1);
        }

        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('statusFilter').value = '';
            document.getElementById('providerFilter').value = '';
            document.getElementById('tenantFilter').value = '';
            document.getElementById('autocompleteDropdown').style.display = 'none';
            loadTransactions(1);
        }

        // ================================================
        // LOAD TRANSACTIONS
        // ================================================
        async function loadTransactions(page = 1) {
            currentPage = page;
            const tbody = document.getElementById('transactionsTableBody');
            const filters = getFilters();

            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2 text-muted">Loading transactions...</p>
                    </td>
                </tr>
            `;

            try {
                let url = `${API_BASE}/index.php?endpoint=payments&action=transactions&page=${page}&limit=20`;
                if (filters.search) url += `&search=${encodeURIComponent(filters.search)}`;
                if (filters.status) url += `&status=${encodeURIComponent(filters.status)}`;
                if (filters.provider) url += `&provider=${encodeURIComponent(filters.provider)}`;
                if (filters.tenantId) url += `&tenant_id=${encodeURIComponent(filters.tenantId)}`;

                console.log('🔍 Fetching transactions from:', url);

                const response = await fetch(url, {
                    headers: getHeaders()
                });
                const result = await response.json();

                console.log('📊 Full API Response:', JSON.stringify(result, null, 2));

                if (result.success && result.data) {
                    let transactions = [];
                    let total = 0;

                    if (result.data.transactions) {
                        transactions = result.data.transactions;
                        total = result.data.total || transactions.length;
                        totalPages = result.data.total_pages || 1;
                    } else if (Array.isArray(result.data)) {
                        transactions = result.data;
                        total = transactions.length;
                        totalPages = 1;
                    } else if (result.data.data && Array.isArray(result.data.data)) {
                        transactions = result.data.data;
                        total = result.data.total || transactions.length;
                        totalPages = result.data.total_pages || 1;
                    } else {
                        transactions = [];
                        total = 0;
                        totalPages = 1;
                    }

                    console.log('📊 Transactions found:', transactions.length);
                    if (transactions.length > 0) {
                        console.log('📊 First transaction:', transactions[0]);
                    }

                    totalRecords = total;

                    if (transactions.length === 0) {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="fas fa-credit-card fa-2x d-block mb-2"></i>
                                    No transactions found
                                    <br><small class="text-muted">Total records: ${total}</small>
                                </td>
                            </tr>
                        `;
                    } else {
                        tbody.innerHTML = transactions.map((tx) => {
                            const statusBadge = getStatusBadge(tx.status);
                            const providerName = tx.provider_name || tx.provider || 'N/A';
                            const tenantName = tx.tenant_name || 'N/A';

                            return `
                                <tr>
                                    <td>
                                        <div style="font-weight:500;font-size:13px;">${tx.internal_reference || 'N/A'}</div>
                                        <div style="font-size:11px;color:#6c757d;">${tx.provider_transaction_id || ''}</div>
                                    </td>
                                    <td>${tenantName}</td>
                                    <td><strong>${formatCurrency(tx.amount)}</strong></td>
                                    <td style="font-size:12px;">${tx.payment_method || 'N/A'}</td>
                                    <td style="font-size:12px;">${providerName}</td>
                                    <td>${statusBadge}</td>
                                    <td style="font-size:12px;color:#6c757d;">${formatDateTime(tx.created_at)}</td>
                                    <td>
                                        <div class="table-actions" style="justify-content:center;">
                                            <a href="/platform/payments/view.php?id=${tx.id}" class="btn btn-sm btn-outline-info" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            ${tx.status === 'PENDING' ? `<button class="btn btn-sm btn-outline-primary" onclick="verifyTransaction(${tx.id})" title="Verify">
                                                <i class="fas fa-check"></i>
                                            </button>` : ''}
                                            ${tx.status === 'SUCCESS' ? `<button class="btn btn-sm btn-outline-warning" onclick="refundTransaction(${tx.id})" title="Refund">
                                                <i class="fas fa-undo"></i>
                                            </button>` : ''}
                                        </div>
                                    </td>
                                </tr>
                            `;
                        }).join('');
                    }

                    updatePagination();

                } else {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="8" class="text-center py-4 text-danger">
                                <i class="fas fa-exclamation-circle fa-2x d-block mb-2"></i>
                                ${result.message || 'Failed to load transactions'}
                            </td>
                        </tr>
                    `;
                }
            } catch (error) {
                console.error('❌ Error loading transactions:', error);
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center py-4 text-danger">
                            <i class="fas fa-exclamation-circle fa-2x d-block mb-2"></i>
                            Error loading transactions: ${error.message}
                        </td>
                    </tr>
                `;
            }
        }

        // ================================================
        // UPDATE PAGINATION
        // ================================================
        function updatePagination() {
            const info = document.getElementById('paginationInfo');
            const controls = document.getElementById('paginationControls');
            const current = currentPage;
            const total = totalPages;

            const start = (current - 1) * 20 + 1;
            const end = Math.min(current * 20, totalRecords);
            info.textContent = `Showing ${start} to ${end} of ${totalRecords} transactions`;

            let html = `
                <li class="page-item ${current <= 1 ? 'disabled' : ''}">
                    <a class="page-link" href="#" onclick="loadTransactions(1)">First</a>
                </li>
                <li class="page-item ${current <= 1 ? 'disabled' : ''}">
                    <a class="page-link" href="#" onclick="loadTransactions(${current - 1})">Prev</a>
                </li>
            `;

            const maxPages = 7;
            let startPage = Math.max(1, current - Math.floor(maxPages / 2));
            let endPage = Math.min(total, startPage + maxPages - 1);
            if (endPage - startPage < maxPages - 1) {
                startPage = Math.max(1, endPage - maxPages + 1);
            }

            if (startPage > 1) {
                html += `<li class="page-item disabled"><a class="page-link">...</a></li>`;
            }

            for (let i = startPage; i <= endPage; i++) {
                html += `
                    <li class="page-item ${i === current ? 'active' : ''}">
                        <a class="page-link" href="#" onclick="loadTransactions(${i})">${i}</a>
                    </li>
                `;
            }

            if (endPage < total) {
                html += `<li class="page-item disabled"><a class="page-link">...</a></li>`;
            }

            html += `
                <li class="page-item ${current >= total ? 'disabled' : ''}">
                    <a class="page-link" href="#" onclick="loadTransactions(${current + 1})">Next</a>
                </li>
                <li class="page-item ${current >= total ? 'disabled' : ''}">
                    <a class="page-link" href="#" onclick="loadTransactions(${total})">Last</a>
                </li>
            `;

            controls.innerHTML = html;
        }

        // ================================================
        // REFRESH DATA
        // ================================================
        function refreshData() {
            const btn = document.querySelector('.btn-outline-secondary i');
            if (btn) btn.className = 'fas fa-sync-alt fa-spin';

            Promise.all([loadStats(), loadTransactions(currentPage)]).then(() => {
                if (btn) btn.className = 'fas fa-sync-alt';
                updateLastUpdated();
                showAlert('Data refreshed successfully', 'success');
            });
        }

        // ================================================
        // VERIFY TRANSACTION
        // ================================================
        async function verifyTransaction(id) {
            if (!confirm('Verify this transaction with the provider?')) return;

            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=payments&action=verify&id=${id}`, {
                    method: 'POST',
                    headers: getHeaders()
                });
                const result = await response.json();
                if (result.success) {
                    showAlert('Transaction verified successfully', 'success');
                    loadStats();
                    loadTransactions(currentPage);
                } else {
                    showAlert('✗ ' + (result.message || 'Verification failed'), 'danger');
                }
            } catch (error) {
                console.error('Error verifying transaction:', error);
                showAlert('Error verifying transaction: ' + error.message, 'danger');
            }
        }

        // ================================================
        // REFUND TRANSACTION
        // ================================================
        async function refundTransaction(id) {
            const amount = prompt('Enter refund amount (leave blank for full refund):');
            if (amount === null) return;

            const refundAmount = amount ? parseFloat(amount) : null;
            if (refundAmount !== null && (isNaN(refundAmount) || refundAmount <= 0)) {
                showAlert('Please enter a valid amount', 'danger');
                return;
            }

            if (!confirm(`Process refund for this transaction?${refundAmount ? ' Amount: GHS ' + refundAmount : ' (Full refund)'}`)) return;

            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=payments&action=refund&id=${id}`, {
                    method: 'POST',
                    headers: getHeaders(),
                    body: JSON.stringify({
                        amount: refundAmount
                    })
                });
                const result = await response.json();
                if (result.success) {
                    showAlert('Refund processed successfully', 'success');
                    loadStats();
                    loadTransactions(currentPage);
                } else {
                    showAlert('✗ ' + (result.message || 'Refund failed'), 'danger');
                }
            } catch (error) {
                console.error('Error processing refund:', error);
                showAlert('Error processing refund: ' + error.message, 'danger');
            }
        }

        // ================================================
        // RUN RECONCILIATION
        // ================================================
        async function runReconciliation() {
            if (!confirm('Run payment reconciliation? This will verify all pending transactions with the providers.')) return;

            const btn = document.querySelector('.btn-outline-success');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Running...';

            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=payments&action=reconcile`, {
                    method: 'POST',
                    headers: getHeaders()
                });
                const result = await response.json();
                if (result.success) {
                    showAlert(`Reconciliation completed: ${result.data?.processed || 0} transactions processed`, 'success');
                    loadStats();
                    loadTransactions(currentPage);
                } else {
                    showAlert('✗ ' + (result.message || 'Reconciliation failed'), 'danger');
                }
            } catch (error) {
                console.error('Error running reconciliation:', error);
                showAlert('Error running reconciliation: ' + error.message, 'danger');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }

        // ================================================
        // INITIALIZE
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();
            loadTenantDropdown();
            loadStats();
            loadTransactions(1);
            updateLastUpdated();

            setInterval(function() {
                loadStats();
            }, 60000);
        });
    </script>
</body>

</html>