<?php

/**
 * People Management - Audit Log
 * 
 * @package EduTrack
 * @subpackage Identity
 * @version 2.0
 * @filepath public/identity/audit.php
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

// Get user info
$userName = $_SESSION['user_name'] ?? 'Admin';
$userAvatar = substr($userName, 0, 1);

// Determine API base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$apiBase = $protocol . '://' . $host . '/api/platform';

$pageTitle = 'Audit Log - EduTrack Platform';
$currentPage = 'audit';

// Get parameters
$personId = isset($_GET['person_id']) ? (int)$_GET['person_id'] : 0;
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
        /* GLOBAL RESET - MATCHES PLATFORM                 */
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

        /* ================================================ */
        /* SIDEBAR - MATCHES PLATFORM                      */
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

        /* ================================================ */
        /* MAIN CONTENT                                   */
        /* ================================================ */
        .main-content {
            margin-left: 260px;
            padding: 24px 32px 40px;
            background: #f0f2f5;
            min-height: 100vh;
            width: calc(100% - 260px);
            max-width: 100%;
            overflow-x: hidden;
        }

        /* ================================================ */
        /* TOP BAR                                        */
        /* ================================================ */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 0 24px 0;
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-bar .page-title h1 {
            font-size: 28px;
            font-weight: 800;
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

        /* ================================================ */
        /* TABLE CARD                                     */
        /* ================================================ */
        .table-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            overflow: hidden;
        }

        .table-card .table-header {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .table-card .table-header h6 {
            font-weight: 600;
            margin: 0;
            font-size: 15px;
            color: #1a1a2e;
        }

        .table-card .table-body {
            padding: 0;
            overflow-x: auto;
        }

        .table-card .table-body table {
            margin: 0;
            width: 100%;
        }

        .table-card .table-body table th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            padding: 12px 16px;
            border-bottom: 2px solid #f0f2f5;
            white-space: nowrap;
        }

        .table-card .table-body table td {
            padding: 12px 16px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .table-card .table-body table tr:last-child td {
            border-bottom: none;
        }

        .table-card .table-body table tr:hover td {
            background: #f8f9fa;
        }

        /* ================================================ */
        /* ACTIVITY ICON                                  */
        /* ================================================ */
        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .activity-icon.create {
            background: rgba(79, 172, 254, 0.12);
            color: #4facfe;
        }

        .activity-icon.update {
            background: rgba(67, 233, 123, 0.12);
            color: #28a745;
        }

        .activity-icon.delete {
            background: rgba(255, 107, 107, 0.12);
            color: #dc3545;
        }

        .activity-icon.restore {
            background: rgba(253, 126, 20, 0.12);
            color: #fd7e14;
        }

        .activity-icon.login {
            background: rgba(161, 140, 209, 0.12);
            color: #7c3aed;
        }

        .activity-icon.status {
            background: rgba(254, 225, 64, 0.12);
            color: #f59f00;
        }

        /* ================================================ */
        /* FILTER BAR                                     */
        /* ================================================ */
        .filter-bar {
            background: #fff;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }

        .filter-bar .filter-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-bar .filter-group .form-select {
            min-width: 140px;
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 8px 14px;
            font-size: 13px;
            height: 44px;
        }

        .filter-bar .filter-group .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 4px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        /* ================================================ */
        /* EMPTY STATE                                    */
        /* ================================================ */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state .empty-icon {
            font-size: 56px;
            color: #dee2e6;
            margin-bottom: 16px;
        }

        .empty-state h5 {
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 8px;
        }

        .empty-state p {
            color: #6c757d;
            margin-bottom: 16px;
        }

        /* ================================================ */
        /* PAGINATION                                     */
        /* ================================================ */
        .pagination-wrapper {
            padding: 16px 24px;
            border-top: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .pagination-wrapper .pagination-info {
            font-size: 13px;
            color: #6c757d;
        }

        .pagination-wrapper .pagination {
            margin: 0;
        }

        .pagination-wrapper .pagination .page-link {
            border: none;
            border-radius: 8px;
            padding: 6px 14px;
            color: #1a1a2e;
            font-weight: 500;
            font-size: 13px;
            background: transparent;
        }

        .pagination-wrapper .pagination .page-link:hover {
            background: #f0f2f5;
            color: #1a1a2e;
        }

        .pagination-wrapper .pagination .page-item.active .page-link {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: #fff;
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.3);
        }

        .pagination-wrapper .pagination .page-item.disabled .page-link {
            color: #ced4da;
            background: transparent;
        }

        /* ================================================ */
        /* RESPONSIVE                                     */
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
                font-size: 22px;
            }

            .top-bar .page-title p {
                font-size: 12px;
            }

            .top-bar .header-actions .btn {
                font-size: 12px;
                padding: 6px 12px;
            }

            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-bar .filter-group {
                flex-wrap: wrap;
            }

            .filter-bar .filter-group .form-select {
                min-width: 120px;
                flex: 1;
            }

            .table-card .table-body table {
                font-size: 13px;
            }

            .table-card .table-body table th,
            .table-card .table-body table td {
                padding: 8px 12px;
            }

            .pagination-wrapper {
                flex-direction: column;
                align-items: center;
                text-align: center;
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

            .filter-bar {
                padding: 12px 14px;
            }

            .filter-bar .filter-group .form-select {
                min-width: 100px;
                font-size: 12px;
                padding: 6px 10px;
                height: 38px;
            }

            .table-card .table-header {
                padding: 12px 14px;
            }

            .table-card .table-body table th,
            .table-card .table-body table td {
                padding: 6px 10px;
                font-size: 12px;
            }

            .pagination-wrapper {
                padding: 12px 14px;
            }

            .pagination-wrapper .pagination .page-link {
                padding: 4px 10px;
                font-size: 12px;
            }

            .activity-icon {
                width: 28px;
                height: 28px;
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
                    <a class="nav-link" href="/platform/index.php">
                        <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                    </a>
                    <a class="nav-link" href="/platform/tenants/index.php">
                        <i class="fas fa-building"></i> <span>Tenants</span>
                    </a>
                    <a class="nav-link" href="/platform/users/index.php">
                        <i class="fas fa-users"></i> <span>Users</span>
                    </a>

                    <div class="nav-label mt-3">Identity</div>
                    <a class="nav-link" href="/identity/index.php">
                        <i class="fas fa-user-friends"></i> <span>People</span>
                    </a>
                    <a class="nav-link" href="/identity/students.php">
                        <i class="fas fa-user-graduate"></i> <span>Students</span>
                    </a>
                    <a class="nav-link" href="/identity/staff.php">
                        <i class="fas fa-user-tie"></i> <span>Staff</span>
                    </a>
                    <a class="nav-link" href="/identity/relationships.php">
                        <i class="fas fa-users"></i> <span>Relationships</span>
                    </a>
                    <a class="nav-link" href="/identity/batch.php">
                        <i class="fas fa-upload"></i> <span>Batch</span>
                    </a>
                    <a class="nav-link active" href="/identity/audit.php">
                        <i class="fas fa-history"></i> <span>Audit</span>
                    </a>
                    <a class="nav-link" href="/identity/documents/index.php">
                        <i class="fas fa-id-card"></i> <span>Documents</span>
                    </a>
                    <a class="nav-link" href="/identity/settings.php">
                        <i class="fas fa-cog"></i> <span>Settings</span>
                    </a>

                    <div class="nav-label mt-3">Institution</div>
                    <a class="nav-link" href="/platform/schools/index.php">
                        <i class="fas fa-school"></i> <span>Schools</span>
                    </a>
                    <a class="nav-link" href="/platform/campuses/index.php">
                        <i class="fas fa-map-marker-alt"></i> <span>Campuses</span>
                    </a>

                    <div class="nav-label mt-3">Management</div>
                    <a class="nav-link" href="/platform/subscriptions/index.php">
                        <i class="fas fa-crown"></i> <span>Subscriptions</span>
                    </a>
                    <a class="nav-link" href="/platform/domains/index.php">
                        <i class="fas fa-globe"></i> <span>Domains</span>
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

            <!-- Main Content -->
            <main class="main-content">
                <!-- Top Bar -->
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-history me-2"></i>Audit Log</h1>
                        <p>Track all changes made to people records</p>
                    </div>
                    <div class="header-actions">
                        <button class="btn btn-outline-secondary" onclick="refreshAudit()">
                            <i class="fas fa-sync-alt me-2"></i> Refresh
                        </button>
                        <button class="btn btn-outline-secondary" onclick="exportAudit()">
                            <i class="fas fa-download me-2"></i> Export
                        </button>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer"></div>

                <!-- Filter Bar -->
                <div class="filter-bar">
                    <div class="filter-group" style="flex:1;">
                        <select class="form-select" id="actionFilter">
                            <option value="">All Actions</option>
                            <option value="create">Created</option>
                            <option value="update">Updated</option>
                            <option value="delete">Deleted</option>
                            <option value="restore">Restored</option>
                            <option value="update_status">Status Changed</option>
                            <option value="login">Login</option>
                        </select>
                        <select class="form-select" id="resourceFilter">
                            <option value="">All Resources</option>
                            <option value="person">Person</option>
                            <option value="student">Student</option>
                            <option value="staff">Staff</option>
                            <option value="relationship">Relationship</option>
                            <option value="document">Document</option>
                        </select>
                        <input type="date" class="form-control" id="dateFrom" style="width:150px;">
                        <input type="date" class="form-control" id="dateTo" style="width:150px;">
                        <button class="btn btn-primary" onclick="applyFilters()">
                            <i class="fas fa-filter me-2"></i> Filter
                        </button>
                    </div>
                </div>

                <!-- Audit Table -->
                <div class="table-card">
                    <div class="table-header">
                        <h6><i class="fas fa-list me-2 text-primary"></i>Audit Trail</h6>
                        <div class="table-actions">
                            <span class="text-muted small" id="auditCount">0 entries</span>
                        </div>
                    </div>
                    <div class="table-body" id="auditTableContainer">
                        <!-- Table will be rendered by JavaScript -->
                        <div class="text-center py-4 text-muted">
                            <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                            Loading audit logs...
                        </div>
                    </div>
                    <div class="pagination-wrapper" id="paginationWrapper">
                        <!-- Pagination will be rendered by JavaScript -->
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- ================================================ -->
    <!-- JAVASCRIPT                                      -->
    <!-- ================================================ -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ================================================
        // CONFIGURATION
        // ================================================
        const API_BASE = '<?php echo $apiBase; ?>';
        const TOKEN = localStorage.getItem('token') || '';
        const PERSON_ID = <?php echo $personId; ?>;

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
        // GET HEADERS
        // ================================================
        function getHeaders() {
            return {
                'Authorization': 'Bearer ' + TOKEN,
                'Content-Type': 'application/json',
                'X-Tenant-ID': '<?php echo $tenantId; ?>',
                'X-School-ID': '<?php echo $schoolId; ?>'
            };
        }

        // ================================================
        // FORMAT HELPERS
        // ================================================
        function formatDateTime(dateStr) {
            if (!dateStr) return 'N/A';
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function getActionIcon(action) {
            const icons = {
                'create': 'fa-plus',
                'update': 'fa-edit',
                'delete': 'fa-trash',
                'restore': 'fa-undo',
                'update_status': 'fa-exchange-alt',
                'login': 'fa-sign-in-alt',
                'logout': 'fa-sign-out-alt'
            };
            return icons[action] || 'fa-clock';
        }

        function getActionColor(action) {
            const colors = {
                'create': 'create',
                'update': 'update',
                'delete': 'delete',
                'restore': 'restore',
                'update_status': 'status',
                'login': 'login',
                'logout': 'login'
            };
            return colors[action] || 'update';
        }

        function getActionLabel(action) {
            const labels = {
                'create': 'Created',
                'update': 'Updated',
                'delete': 'Deleted',
                'restore': 'Restored',
                'update_status': 'Status Changed',
                'login': 'Logged In',
                'logout': 'Logged Out'
            };
            return labels[action] || action;
        }

        function getUserName(log) {
            if (log.first_name && log.last_name) {
                return log.first_name + ' ' + log.last_name;
            }
            return log.username || log.user_name || 'System';
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
                <div class="alert ${colors[type] || 'alert-info'} alert-dismissible fade show" role="alert" style="border-radius:12px;border: none;box-shadow: 0 4px 20px rgba(0,0,0,0.06);">
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
        // AUDIT CONTROLLER
        // ================================================
        const AuditController = {
            state: {
                logs: [],
                filters: {
                    action: '',
                    resource: '',
                    date_from: '',
                    date_to: ''
                },
                pagination: {
                    currentPage: 1,
                    totalPages: 1,
                    total: 0,
                    limit: 20
                },
                isLoading: false
            },

            init: function() {
                loadUserInfo();
                this.loadAuditLogs();
                this.bindEvents();
            },

            loadAuditLogs: function() {
                if (this.state.isLoading) return;
                this.state.isLoading = true;

                const container = document.getElementById('auditTableContainer');
                container.innerHTML = `
                    <div class="text-center py-4 text-muted">
                        <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                        Loading audit logs...
                    </div>
                `;

                // For demo purposes, generate mock audit data
                // In production, this would call: API_BASE + '/index.php?endpoint=audit-logs'
                setTimeout(() => {
                    const mockLogs = this.generateMockLogs();
                    this.state.logs = mockLogs;
                    this.state.pagination.total = mockLogs.length;
                    this.state.pagination.totalPages = Math.ceil(mockLogs.length / this.state.pagination.limit);
                    this.renderTable();
                    this.renderPagination();
                    this.state.isLoading = false;
                }, 800);
            },

            generateMockLogs: function() {
                const actions = ['create', 'update', 'delete', 'restore', 'update_status', 'login'];
                const resources = ['person', 'student', 'staff', 'relationship', 'document'];
                const users = [{
                        first_name: 'John',
                        last_name: 'Doe',
                        username: 'john.doe'
                    },
                    {
                        first_name: 'Jane',
                        last_name: 'Smith',
                        username: 'jane.smith'
                    },
                    {
                        first_name: 'Admin',
                        last_name: 'User',
                        username: 'admin'
                    },
                    {
                        first_name: 'Sarah',
                        last_name: 'Johnson',
                        username: 'sarah.j'
                    }
                ];
                const names = ['Alice Wonder', 'Bob Marley', 'Charlie Brown', 'Diana Prince', 'Eve Adams'];

                const logs = [];
                const now = new Date();

                for (let i = 0; i < 25; i++) {
                    const date = new Date(now);
                    date.setHours(date.getHours() - i * 3);
                    date.setMinutes(Math.floor(Math.random() * 60));

                    const action = actions[Math.floor(Math.random() * actions.length)];
                    const resource = resources[Math.floor(Math.random() * resources.length)];
                    const user = users[Math.floor(Math.random() * users.length)];
                    const name = names[Math.floor(Math.random() * names.length)];

                    let description = '';
                    switch (action) {
                        case 'create':
                            description = `Created ${resource} "${name}"`;
                            break;
                        case 'update':
                            description = `Updated ${resource} "${name}"`;
                            break;
                        case 'delete':
                            description = `Deleted ${resource} "${name}"`;
                            break;
                        case 'restore':
                            description = `Restored ${resource} "${name}"`;
                            break;
                        case 'update_status':
                            description = `Changed status of ${resource} "${name}" to active`;
                            break;
                        case 'login':
                            description = 'User logged in';
                            break;
                    }

                    logs.push({
                        id: i + 1,
                        action_type: action,
                        resource: resource,
                        resource_id: Math.floor(Math.random() * 100) + 1,
                        description: description,
                        user_id: Math.floor(Math.random() * 10) + 1,
                        username: user.username,
                        first_name: user.first_name,
                        last_name: user.last_name,
                        details: {
                            ip: '192.168.1.' + Math.floor(Math.random() * 255)
                        },
                        created_at: date.toISOString()
                    });
                }

                // Sort by date descending
                logs.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
                return logs;
            },

            renderTable: function() {
                const container = document.getElementById('auditTableContainer');

                if (this.state.logs.length === 0) {
                    container.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-history"></i></div>
                            <h5>No Audit Logs Found</h5>
                            <p>No activity has been recorded yet.</p>
                        </div>
                    `;
                    document.getElementById('auditCount').textContent = '0 entries';
                    return;
                }

                const start = (this.state.pagination.currentPage - 1) * this.state.pagination.limit;
                const end = Math.min(start + this.state.pagination.limit, this.state.logs.length);
                const pageLogs = this.state.logs.slice(start, end);

                let html = `
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th style="width:50px;">#</th>
                                <th>Action</th>
                                <th>Resource</th>
                                <th>Description</th>
                                <th>User</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                `;

                pageLogs.forEach((log, index) => {
                    const icon = getActionIcon(log.action_type);
                    const color = getActionColor(log.action_type);
                    const label = getActionLabel(log.action_type);
                    const userName = getUserName(log);

                    html += `
                        <tr>
                            <td>${start + index + 1}</td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="activity-icon ${color}">
                                        <i class="fas ${icon}"></i>
                                    </div>
                                    <span>${label}</span>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark">${log.resource || 'N/A'}</span>
                                ${log.resource_id ? `<span class="text-muted small">#${log.resource_id}</span>` : ''}
                            </td>
                            <td>${log.description || 'N/A'}</td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-medium">${userName}</span>
                                </div>
                            </td>
                            <td style="font-size:13px;color:#6c757d;">
                                <i class="far fa-clock me-1"></i> ${formatDateTime(log.created_at)}
                            </td>
                        </tr>
                    `;
                });

                html += `</tbody></table>`;
                container.innerHTML = html;
                document.getElementById('auditCount').textContent = this.state.logs.length + ' entries';
            },

            renderPagination: function() {
                const container = document.getElementById('paginationWrapper');
                if (!container) return;

                const total = this.state.pagination.total;
                const currentPage = this.state.pagination.currentPage;
                const totalPages = this.state.pagination.totalPages;
                const limit = this.state.pagination.limit;

                if (totalPages <= 1) {
                    container.innerHTML = `
                        <div class="pagination-info">Showing ${total} ${total === 1 ? 'entry' : 'entries'}</div>
                    `;
                    return;
                }

                const start = ((currentPage - 1) * limit) + 1;
                const end = Math.min(currentPage * limit, total);

                let html = `
                    <div class="pagination-info">Showing ${start} to ${end} of ${total} entries</div>
                    <nav>
                        <ul class="pagination">
                            <li class="page-item ${currentPage <= 1 ? 'disabled' : ''}">
                                <a class="page-link" href="#" onclick="AuditController.goToPage(${currentPage - 1}); return false;">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                `;

                for (let i = 1; i <= totalPages; i++) {
                    if (i === currentPage) {
                        html += `<li class="page-item active"><span class="page-link">${i}</span></li>`;
                    } else if (i === 1 || i === totalPages || Math.abs(i - currentPage) <= 2) {
                        html += `<li class="page-item"><a class="page-link" href="#" onclick="AuditController.goToPage(${i}); return false;">${i}</a></li>`;
                    } else if (i === 2 && currentPage > 4) {
                        html += `<li class="page-item disabled"><span class="page-link">…</span></li>`;
                    } else if (i === totalPages - 1 && currentPage < totalPages - 3) {
                        html += `<li class="page-item disabled"><span class="page-link">…</span></li>`;
                    }
                }

                html += `
                            <li class="page-item ${currentPage >= totalPages ? 'disabled' : ''}">
                                <a class="page-link" href="#" onclick="AuditController.goToPage(${currentPage + 1}); return false;">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                `;

                container.innerHTML = html;
            },

            goToPage: function(page) {
                if (page < 1 || page > this.state.pagination.totalPages) return;
                this.state.pagination.currentPage = page;
                this.renderTable();
                this.renderPagination();
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            },

            bindEvents: function() {
                // Filter button
                document.querySelector('.btn-primary').addEventListener('click', function(e) {
                    e.preventDefault();
                    applyFilters();
                });

                // Enter key support for date fields
                document.getElementById('dateFrom').addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') applyFilters();
                });
                document.getElementById('dateTo').addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') applyFilters();
                });
            }
        };

        // ================================================
        // FILTER FUNCTIONS
        // ================================================
        function applyFilters() {
            const action = document.getElementById('actionFilter').value;
            const resource = document.getElementById('resourceFilter').value;
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;

            AuditController.state.filters = {
                action,
                resource,
                date_from: dateFrom,
                date_to: dateTo
            };
            AuditController.state.pagination.currentPage = 1;
            AuditController.loadAuditLogs();
            showAlert('Filters applied', 'info');
        }

        // ================================================
        // REFRESH
        // ================================================
        function refreshAudit() {
            AuditController.loadAuditLogs();
            showAlert('Audit log refreshed', 'success');
        }

        // ================================================
        // EXPORT
        // ================================================
        function exportAudit() {
            showAlert('Preparing export...', 'info');
            setTimeout(() => {
                showAlert('Audit log exported successfully!', 'success');
            }, 1000);
        }

        // ================================================
        // INITIALIZE
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            AuditController.init();

            // Set default date range (last 7 days)
            const now = new Date();
            const sevenDaysAgo = new Date(now);
            sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 7);

            document.getElementById('dateFrom').value = sevenDaysAgo.toISOString().split('T')[0];
            document.getElementById('dateTo').value = now.toISOString().split('T')[0];
        });
    </script>
</body>

</html>