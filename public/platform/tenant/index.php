<?php

/**
 * Tenants Management - List all tenants with List/Grid view
 * 
 * @package EduTrack
 * @subpackage Platform\Tenants
 * @version 2.0
 * @filepath public/platform/tenants/index.php
 */

// Check if session is already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

$pageTitle = 'Tenants - EduTrack Platform';
$currentPage = 'tenants';

// Determine API base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$apiBase = $protocol . '://' . $host . '/api/platform';

// =============================================
// LOAD DATABASE HELPER
// =============================================
$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// =============================================
// GET FILTERS
// =============================================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$viewMode = isset($_GET['view']) ? $_GET['view'] : 'grid';

// =============================================
// GET ALL TENANTS
// =============================================
$sql = "SELECT * FROM tenants WHERE deleted_at IS NULL";
$params = [];

if (!empty($search)) {
    $sql .= " AND (tenant_name LIKE ? OR tenant_code LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}
if (!empty($statusFilter)) {
    $sql .= " AND status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY tenant_name ASC";
$tenants = $db->fetchAll($sql, $params);

// Get school count for each tenant
foreach ($tenants as &$tenant) {
    $tenant['school_count'] = $db->getValue(
        "SELECT COUNT(*) FROM schools WHERE tenant_id = ? AND deleted_at IS NULL",
        [$tenant['id']]
    ) ?? 0;
    $tenant['campus_count'] = $db->getValue(
        "SELECT COUNT(*) FROM campuses c 
         JOIN schools s ON c.school_id = s.id 
         WHERE s.tenant_id = ? AND c.deleted_at IS NULL",
        [$tenant['id']]
    ) ?? 0;
}

$statuses = ['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended', 'pending' => 'Pending'];

$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userAvatar = substr($currentUser, 0, 1);
$currentTenantId = $_SESSION['tenant_id'] ?? 0;
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

        /* ================================================ */
        /* STATS ROW */
        /* ================================================ */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
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

        .stat-card .stat-icon.purple {
            background: #e8d5f5;
            color: #6f42c1;
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
        /* FILTER SECTION - PROPERLY ALIGNED */
        /* ================================================ */
        .filter-section {
            background: #fff;
            border-radius: 14px;
            padding: 12px 20px;
            margin-bottom: 20px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 12px;
        }

        .filter-section .filter-group {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filter-section .filter-group label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            margin: 0;
            white-space: nowrap;
        }

        .filter-section .filter-group .form-control,
        .filter-section .filter-group .form-select {
            border-radius: 8px;
            padding: 6px 12px;
            border: 2px solid #e9ecef;
            font-size: 13px;
            height: 38px;
            min-width: 150px;
            background: #fff;
        }

        .filter-section .filter-group .form-control:focus,
        .filter-section .filter-group .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .filter-section .filter-group.search-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-section .filter-group.search-group .form-control {
            width: 100%;
            min-width: 180px;
        }

        .filter-section .filter-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-left: auto;
        }

        .filter-section .filter-actions .btn {
            border-radius: 8px;
            padding: 6px 14px;
            font-size: 13px;
            height: 38px;
        }

        .filter-section .filter-actions .btn i {
            margin-right: 4px;
        }

        /* ================================================ */
        /* VIEW TOGGLE */
        /* ================================================ */
        .view-toggle {
            display: flex;
            gap: 4px;
            background: #f0f2f5;
            border-radius: 8px;
            padding: 3px;
        }

        .view-toggle .btn {
            border: none;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 13px;
            background: transparent;
            color: #6c757d;
            transition: all 0.3s;
        }

        .view-toggle .btn.active {
            background: #fff;
            color: #4facfe;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .view-toggle .btn:hover:not(.active) {
            background: rgba(255, 255, 255, 0.5);
        }

        /* ================================================ */
        /* TENANT GRID */
        /* ================================================ */
        .tenant-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }

        .tenant-grid .tenant-card {
            background: #fff;
            border-radius: 14px;
            padding: 20px 24px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.3s;
            text-decoration: none;
            color: #1a1a2e;
            display: block;
        }

        .tenant-grid .tenant-card:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
            border-color: #4facfe;
        }

        /* ================================================ */
        /* TENANT LIST */
        /* ================================================ */
        .tenant-list .tenant-card {
            background: #fff;
            border-radius: 14px;
            padding: 16px 24px;
            margin-bottom: 12px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.3s;
            text-decoration: none;
            color: #1a1a2e;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .tenant-list .tenant-card:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border-color: #4facfe;
        }

        .tenant-list .tenant-card .tenant-info {
            flex: 1;
            min-width: 200px;
        }

        .tenant-list .tenant-card .tenant-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .tenant-card .tenant-name {
            font-weight: 600;
            font-size: 16px;
            color: #1a1a2e;
        }

        .tenant-card .tenant-code {
            font-size: 12px;
            color: #6c757d;
            font-weight: 500;
        }

        .tenant-card .tenant-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 6px;
            font-size: 13px;
            color: #6c757d;
        }

        .tenant-card .tenant-meta i {
            width: 16px;
            margin-right: 4px;
        }

        .tenant-card .tenant-actions .btn {
            padding: 4px 12px;
            font-size: 12px;
            border-radius: 8px;
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

        .badge-status.pending {
            background: #fff3cd;
            color: #856404;
        }

        .badge-status.suspended {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-status.inactive {
            background: #e9ecef;
            color: #6c757d;
        }

        .current-tenant-badge {
            background: #e3f0ff;
            color: #0d6efd;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            margin-left: 8px;
        }

        /* ================================================ */
        /* SEARCH RESULTS DROPDOWN */
        /* ================================================ */
        #searchResults {
            display: none;
            position: fixed;
            z-index: 9999;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
            border: 1px solid #e9ecef;
            padding: 8px 0;
            max-height: 400px;
            overflow-y: auto;
            min-width: 300px;
        }

        #searchResults .search-result-item {
            padding: 10px 16px;
            cursor: pointer;
            transition: background 0.15s;
            border-bottom: 1px solid #f8f9fa;
        }

        #searchResults .search-result-item:hover {
            background: #f8f9fa;
        }

        #searchResults .search-result-item:last-child {
            border-bottom: none;
        }

        #searchResults .search-result-item .highlight {
            background: #fff3cd;
            font-weight: 600;
            color: #1a1a2e;
            padding: 0 2px;
            border-radius: 2px;
        }

        #searchInput.loading {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%234facfe' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='12' cy='12' r='10'/%3E%3Cpath d='M12 6v6l4 2'/%3E%3C/svg%3E");
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 20px;
        }

        #searchResults::-webkit-scrollbar {
            width: 6px;
        }

        #searchResults::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        #searchResults::-webkit-scrollbar-thumb {
            background: #c1c7cd;
            border-radius: 3px;
        }

        #searchResults::-webkit-scrollbar-thumb:hover {
            background: #a8afb6;
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

            .tenant-grid {
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
                grid-template-columns: 1fr 1fr;
            }

            .tenant-grid {
                grid-template-columns: 1fr;
            }

            .tenant-list .tenant-card {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-section {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }

            .filter-section .filter-group.search-group {
                min-width: unset;
            }

            .filter-section .filter-group {
                width: 100%;
            }

            .filter-section .filter-group .form-control,
            .filter-section .filter-group .form-select {
                width: 100%;
                min-width: unset;
            }

            .filter-section .filter-actions {
                margin-left: 0;
                width: 100%;
                justify-content: flex-start;
                flex-wrap: wrap;
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

            .tenant-card {
                padding: 14px 16px;
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
                                <div class="user-role" id="userRole">Administrator</div>
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
                        <h1><i class="fas fa-building me-2"></i>Tenants</h1>
                        <p>Manage all tenants on the platform</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenants/register.php" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i> Add Tenant
                        </a>
                        <button class="btn btn-outline-secondary" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt me-2"></i> Refresh
                        </button>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer">
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="fas fa-check-circle me-2"></i> <?php echo $_SESSION['success'];
                                                                        unset($_SESSION['success']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Stats -->
                <div class="stats-row" id="statsRow">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-building"></i></div>
                        <div>
                            <div class="stat-number"><?php echo count($tenants); ?></div>
                            <div class="stat-label">Total Tenants</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                        <div>
                            <div class="stat-number">
                                <?php
                                $activeCount = 0;
                                foreach ($tenants as $t) {
                                    if ($t['status'] === 'active') $activeCount++;
                                }
                                echo $activeCount;
                                ?>
                            </div>
                            <div class="stat-label">Active</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="stat-number">
                                <?php
                                $totalSchools = 0;
                                foreach ($tenants as $t) {
                                    $totalSchools += $t['school_count'];
                                }
                                echo $totalSchools;
                                ?>
                            </div>
                            <div class="stat-label">Total Schools</div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-section">
                    <div class="filter-group search-group">
                        <label for="searchInput"><i class="fas fa-search"></i></label>
                        <input type="text" class="form-control" id="searchInput" name="search"
                            placeholder="Search tenants..." value="<?php echo htmlspecialchars($search ?? ''); ?>" autocomplete="off">
                    </div>
                    <div class="filter-group">
                        <label for="statusFilter">Status</label>
                        <select class="form-select" id="statusFilter" name="status">
                            <option value="">All</option>
                            <?php foreach ($statuses as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo (isset($statusFilter) && $statusFilter == $key) ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-actions">
                        <button type="button" class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-filter"></i> Filter</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
                        <div class="view-toggle">
                            <button class="btn <?php echo $viewMode == 'grid' ? 'active' : ''; ?>" onclick="setView('grid')" title="Grid View"><i class="fas fa-th"></i></button>
                            <button class="btn <?php echo $viewMode == 'list' ? 'active' : ''; ?>" onclick="setView('list')" title="List View"><i class="fas fa-list"></i></button>
                        </div>
                    </div>
                </div>

                <!-- Search Results Dropdown -->
                <div id="searchResults"></div>

                <!-- Tenants Container -->
                <div id="tenantsContainer" class="<?php echo $viewMode == 'grid' ? 'tenant-grid' : 'tenant-list'; ?>">
                    <?php if (empty($tenants)): ?>
                        <div class="card-custom" style="padding:60px 40px;text-align:center;grid-column:1/-1;">
                            <i class="fas fa-building" style="font-size:64px;color:#dee2e6;margin-bottom:20px;display:block;"></i>
                            <h4 style="color:#1a1a2e;margin-bottom:8px;">No Tenants Found</h4>
                            <p style="color:#6c757d;font-size:14px;max-width:400px;margin:0 auto 20px;">
                                No tenants have been registered yet. Create your first tenant to get started.
                            </p>
                            <a href="/platform/tenants/register.php" class="btn btn-primary btn-lg">
                                <i class="fas fa-plus me-2"></i> Create Tenant
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($tenants as $tenant): ?>
                            <div class="tenant-card">
                                <div class="tenant-info">
                                    <div class="tenant-name">
                                        <?php echo htmlspecialchars($tenant['tenant_name']); ?>
                                        <?php if ($currentTenantId == $tenant['id']): ?>
                                            <span class="current-tenant-badge"><i class="fas fa-check-circle"></i> Current</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="tenant-code">
                                        <i class="fas fa-tag"></i> <?php echo htmlspecialchars($tenant['tenant_code'] ?? 'N/A'); ?>
                                        <span class="badge-status <?php echo $tenant['status'] ?? 'active'; ?>">
                                            <i class="fas fa-circle" style="font-size:8px;margin-right:4px;"></i>
                                            <?php echo ucfirst($tenant['status'] ?? 'Active'); ?>
                                        </span>
                                    </div>
                                    <div class="tenant-meta">
                                        <?php if (!empty($tenant['email'])): ?>
                                            <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($tenant['email']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($tenant['phone'])): ?>
                                            <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($tenant['phone']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($tenant['city'])): ?>
                                            <span><i class="fas fa-city"></i> <?php echo htmlspecialchars($tenant['city']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($tenant['region'])): ?>
                                            <span><i class="fas fa-map-pin"></i> <?php echo htmlspecialchars($tenant['region']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="tenant-meta" style="margin-top:4px;">
                                        <span><i class="fas fa-school"></i> <?php echo $tenant['school_count'] ?? 0; ?> Schools</span>
                                        <span><i class="fas fa-map-marker-alt"></i> <?php echo $tenant['campus_count'] ?? 0; ?> Campuses</span>
                                        <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($tenant['created_at'] ?? 'now')); ?></span>
                                    </div>
                                </div>
                                <div class="tenant-actions">
                                    <a href="/platform/tenants/view.php?id=<?php echo $tenant['id']; ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>
                                    <a href="/platform/tenants/edit.php?id=<?php echo $tenant['id']; ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-edit"></i> Edit</a>
                                    <a href="/platform/schools/index.php?tenant_id=<?php echo $tenant['id']; ?>" class="btn btn-outline-info btn-sm"><i class="fas fa-school"></i> Schools</a>
                                    <?php if ($currentTenantId != $tenant['id']): ?>
                                        <button class="btn btn-outline-success btn-sm" onclick="selectTenant(<?php echo $tenant['id']; ?>, '<?php echo htmlspecialchars($tenant['tenant_name']); ?>')"><i class="fas fa-check"></i> Select</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ================================================
        // CONFIGURATION
        // ================================================
        const API_BASE = '<?php echo $apiBase; ?>';
        const TOKEN = localStorage.getItem('token') || '';

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
        // SELECT TENANT
        // ================================================
        function selectTenant(tenantId, tenantName) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/platform/tenants/select.php';
            const tenantInput = document.createElement('input');
            tenantInput.type = 'hidden';
            tenantInput.name = 'tenant_id';
            tenantInput.value = tenantId;
            form.appendChild(tenantInput);
            const nameInput = document.createElement('input');
            nameInput.type = 'hidden';
            nameInput.name = 'tenant_name';
            nameInput.value = tenantName;
            form.appendChild(nameInput);
            const redirectInput = document.createElement('input');
            redirectInput.type = 'hidden';
            redirectInput.name = 'redirect';
            redirectInput.value = '/platform/tenants/index.php';
            form.appendChild(redirectInput);
            document.body.appendChild(form);
            form.submit();
        }

        // ================================================
        // APPLY FILTERS
        // ================================================
        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;
            const view = '<?php echo $viewMode; ?>';
            let url = '/platform/tenants/index.php?';
            if (search) url += 'search=' + encodeURIComponent(search) + '&';
            if (status) url += 'status=' + encodeURIComponent(status) + '&';
            if (view) url += 'view=' + view;
            window.location.href = url;
        }

        // ================================================
        // RESET FILTERS
        // ================================================
        function resetFilters() {
            window.location.href = '/platform/tenants/index.php?view=<?php echo $viewMode; ?>';
        }

        // ================================================
        // SET VIEW
        // ================================================
        function setView(view) {
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('view', view);
            window.location.href = currentUrl.toString();
        }

        // ================================================
        // SEARCH AUTOCOMPLETE
        // ================================================
        let searchTimeout = null;
        let isSearching = false;

        function performSearch() {
            const searchInput = document.getElementById('searchInput');
            const searchTerm = searchInput ? searchInput.value.trim() : '';

            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }

            if (!searchTerm || searchTerm.length < 2) {
                const container = document.getElementById('searchResults');
                if (container) {
                    container.style.display = 'none';
                    container.innerHTML = '';
                }
                return;
            }

            if (searchInput) {
                searchInput.classList.add('loading');
            }

            searchTimeout = setTimeout(() => {
                fetchSearchResults(searchTerm);
            }, 300);
        }

        async function fetchSearchResults(searchTerm) {
            const searchInput = document.getElementById('searchInput');
            const container = document.getElementById('searchResults');

            if (isSearching) return;
            isSearching = true;

            try {
                const timestamp = new Date().getTime();
                const url = API_BASE + '/index.php?endpoint=tenants&action=autocomplete&search=' + encodeURIComponent(searchTerm) + '&_=' + timestamp;

                const response = await fetch(url, {
                    headers: {
                        'Authorization': 'Bearer ' + TOKEN,
                        'Content-Type': 'application/json'
                    }
                });
                const result = await response.json();

                if (searchInput) {
                    searchInput.classList.remove('loading');
                }

                if (result.success && result.data && result.data.length > 0) {
                    showSearchResults(result.data, searchTerm);
                } else {
                    showSearchResults([], searchTerm);
                }
            } catch (error) {
                console.error('Search error:', error);
                if (searchInput) {
                    searchInput.classList.remove('loading');
                }
                showSearchResults([], searchTerm);
            } finally {
                isSearching = false;
            }
        }

        function showSearchResults(results, searchTerm) {
            const container = document.getElementById('searchResults');
            const searchInput = document.getElementById('searchInput');

            if (!container || !searchInput) return;

            const rect = searchInput.getBoundingClientRect();

            container.style.position = 'fixed';
            container.style.top = (rect.bottom + 5) + 'px';
            container.style.left = rect.left + 'px';
            container.style.width = Math.max(rect.width, 300) + 'px';
            container.style.maxHeight = '400px';
            container.style.overflowY = 'auto';
            container.style.zIndex = '9999';
            container.style.background = '#fff';
            container.style.borderRadius = '12px';
            container.style.boxShadow = '0 8px 30px rgba(0,0,0,0.15)';
            container.style.border = '1px solid #e9ecef';
            container.style.display = 'block';
            container.style.padding = '8px 0';

            if (!results || results.length === 0) {
                container.innerHTML = `
                    <div style="padding: 12px 16px; color: #6c757d; font-size: 14px; text-align: center;">
                        <i class="fas fa-search me-2"></i> No tenants found for "<strong>${searchTerm}</strong>"
                    </div>
                `;
                return;
            }

            let html = '';
            results.forEach((tenant) => {
                const name = tenant.tenant_name || 'Unnamed Tenant';
                const code = tenant.tenant_code || '';
                const status = tenant.status || 'active';
                const id = tenant.id || 0;

                const highlightedName = highlightText(name, searchTerm);
                const highlightedCode = highlightText(code, searchTerm);

                const statusColors = {
                    'active': '#28a745',
                    'pending': '#ffc107',
                    'suspended': '#dc3545',
                    'inactive': '#6c757d'
                };
                const dotColor = statusColors[status] || '#6c757d';

                html += `
                    <div class="search-result-item" 
                         data-id="${id}"
                         onclick="selectTenantFromSearch(${id}, '${name.replace(/'/g, "\\'")}')"
                         onmouseenter="this.style.background='#f8f9fa'"
                         onmouseleave="this.style.background='transparent'">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <div style="font-weight: 600; color: #1a1a2e; font-size: 14px;">
                                    ${highlightedName}
                                </div>
                                ${code ? `<div style="font-size: 12px; color: #6c757d; margin-top: 2px;">
                                    <i class="fas fa-tag me-1"></i> ${highlightedCode}
                                </div>` : ''}
                            </div>
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: ${dotColor};"></span>
                                <span style="font-size: 11px; color: #6c757d; text-transform: capitalize;">${status}</span>
                            </div>
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
        }

        function highlightText(text, searchTerm) {
            if (!text || !searchTerm) return text;
            const escaped = searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const regex = new RegExp('(' + escaped + ')', 'gi');
            return text.replace(regex, '<span class="highlight">$1</span>');
        }

        function selectTenantFromSearch(id, name) {
            const searchInput = document.getElementById('searchInput');
            const container = document.getElementById('searchResults');

            if (searchInput) {
                searchInput.value = name;
            }
            if (container) {
                container.style.display = 'none';
                container.innerHTML = '';
            }

            window.location.href = '/platform/tenants/view.php?id=' + id;
        }

        // Close search results on outside click
        document.addEventListener('click', function(event) {
            const container = document.getElementById('searchResults');
            const searchInput = document.getElementById('searchInput');

            if (container && searchInput) {
                if (!container.contains(event.target) && event.target !== searchInput) {
                    container.style.display = 'none';
                    container.innerHTML = '';
                }
            }
        });

        // Keyboard navigation for search results
        document.addEventListener('keydown', function(event) {
            const container = document.getElementById('searchResults');
            if (!container || container.style.display === 'none') return;

            const items = container.querySelectorAll('.search-result-item');
            if (items.length === 0) return;

            let currentIndex = -1;
            items.forEach((item, index) => {
                if (item.style.background === 'rgb(248, 249, 250)' || item.style.background === '#f8f9fa') {
                    currentIndex = index;
                }
            });

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                const nextIndex = Math.min(currentIndex + 1, items.length - 1);
                items.forEach((item, index) => {
                    item.style.background = index === nextIndex ? '#f8f9fa' : 'transparent';
                });
                if (items[nextIndex]) {
                    items[nextIndex].scrollIntoView({
                        block: 'nearest'
                    });
                }
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                const prevIndex = Math.max(currentIndex - 1, 0);
                items.forEach((item, index) => {
                    item.style.background = index === prevIndex ? '#f8f9fa' : 'transparent';
                });
                if (items[prevIndex]) {
                    items[prevIndex].scrollIntoView({
                        block: 'nearest'
                    });
                }
            } else if (event.key === 'Enter') {
                event.preventDefault();
                const selected = container.querySelector('.search-result-item[style*="background: #f8f9fa"]') || items[0];
                if (selected) {
                    selected.click();
                }
            } else if (event.key === 'Escape') {
                container.style.display = 'none';
                container.innerHTML = '';
            }
        });

        // ================================================
        // INITIALIZE
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();

            // Set up search input
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                // Remove existing event listeners by cloning
                const newInput = searchInput.cloneNode(true);
                searchInput.parentNode.replaceChild(newInput, searchInput);

                newInput.addEventListener('input', function(e) {
                    performSearch();
                });

                newInput.addEventListener('keydown', function(e) {
                    const container = document.getElementById('searchResults');
                    if (e.key === 'Enter' && container && container.style.display !== 'none') {
                        const items = container.querySelectorAll('.search-result-item');
                        if (items.length > 0) {
                            e.preventDefault();
                            const selected = container.querySelector('.search-result-item[style*="background: #f8f9fa"]') || items[0];
                            if (selected) {
                                selected.click();
                            }
                        }
                    }
                });

                newInput.addEventListener('blur', function() {
                    setTimeout(() => {
                        const container = document.getElementById('searchResults');
                        if (container) {
                            container.style.display = 'none';
                            container.innerHTML = '';
                        }
                    }, 200);
                });
            }

            // Auto-apply filter on status change
            document.getElementById('statusFilter').addEventListener('change', function() {
                applyFilters();
            });
        });
    </script>
</body>

</html>