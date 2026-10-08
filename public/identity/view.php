<?php

/**
 * People Management - Person Profile View
 * 
 * @package EduTrack
 * @subpackage Identity
 * @version 2.0
 * @filepath public/identity/view.php
 */

session_start();

// Check authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

$personId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$personId) {
    header('Location: /identity/index.php?error=invalid_id');
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

$pageTitle = 'Person Profile - EduTrack Platform';
$currentPage = 'identity';

// Get active tab from URL
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'overview';
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
        /* PERSON PROFILE HEADER                          */
        /* ================================================ */
        .profile-header {
            background: #fff;
            border-radius: 16px;
            padding: 24px 28px;
            margin-bottom: 24px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            display: flex;
            align-items: center;
            gap: 24px;
            flex-wrap: wrap;
            position: relative;
        }

        .profile-header .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
        }

        .profile-header .profile-info {
            flex: 1;
            min-width: 200px;
        }

        .profile-header .profile-info .name {
            font-size: 22px;
            font-weight: 700;
            color: #1a1a2e;
        }

        .profile-header .profile-info .details {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 6px;
        }

        .profile-header .profile-info .details .detail-item {
            font-size: 13px;
            color: #6c757d;
        }

        .profile-header .profile-info .details .detail-item i {
            width: 18px;
            color: #4facfe;
        }

        .profile-header .profile-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-left: auto;
        }

        /* ================================================ */
        /* PROFILE TABS                                   */
        /* ================================================ */
        .profile-tabs {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            overflow: hidden;
        }

        .profile-tabs .tab-header {
            display: flex;
            flex-wrap: nowrap;
            overflow-x: auto;
            border-bottom: 2px solid #f0f2f5;
            background: #fafbfc;
            padding: 0 8px;
        }

        .profile-tabs .tab-header .tab-link {
            padding: 14px 20px;
            color: #6c757d;
            font-weight: 500;
            font-size: 13px;
            text-decoration: none;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            cursor: pointer;
        }

        .profile-tabs .tab-header .tab-link:hover {
            color: #1a1a2e;
            background: #f8f9fa;
        }

        .profile-tabs .tab-header .tab-link.active {
            color: #4facfe;
            border-bottom-color: #4facfe;
            background: transparent;
        }

        .profile-tabs .tab-header .tab-link i {
            font-size: 14px;
        }

        .profile-tabs .tab-content {
            padding: 24px;
        }

        /* ================================================ */
        /* PROFILE SECTIONS                               */
        /* ================================================ */
        .info-section {
            margin-bottom: 28px;
        }

        .info-section:last-child {
            margin-bottom: 0;
        }

        .info-section .section-title {
            font-weight: 600;
            font-size: 15px;
            color: #1a1a2e;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f2f5;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-section .section-title i {
            color: #4facfe;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 12px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            padding: 8px 0;
        }

        .info-item .label {
            font-size: 12px;
            color: #6c757d;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .info-item .value {
            font-size: 14px;
            color: #1a1a2e;
            font-weight: 500;
            margin-top: 2px;
        }

        .info-item .value .text-muted {
            font-weight: 400;
        }

        /* ================================================ */
        /* BADGES                                         */
        /* ================================================ */
        .badge-status {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
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

        .badge-status.inactive {
            background: #e9ecef;
            color: #6c757d;
        }

        .badge-status.suspended {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-status.archived {
            background: #e9ecef;
            color: #6c757d;
        }

        /* ================================================ */
        /* EMPTY STATE                                    */
        /* ================================================ */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }

        .empty-state .empty-icon {
            font-size: 40px;
            color: #dee2e6;
            margin-bottom: 12px;
        }

        .empty-state h6 {
            font-weight: 600;
            color: #1a1a2e;
        }

        .empty-state p {
            color: #6c757d;
            font-size: 13px;
            margin-bottom: 0;
        }

        /* ================================================ */
        /* LOADING STATE                                  */
        /* ================================================ */
        .loading-state {
            text-align: center;
            padding: 60px 20px;
        }

        .loading-state .spinner-border {
            width: 40px;
            height: 40px;
        }

        .loading-state p {
            color: #6c757d;
            margin-top: 16px;
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

            .profile-header .profile-avatar {
                width: 64px;
                height: 64px;
                font-size: 24px;
            }

            .profile-header .profile-info .name {
                font-size: 20px;
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

            .profile-header {
                flex-direction: column;
                align-items: flex-start;
                padding: 20px;
            }

            .profile-header .profile-avatar {
                width: 72px;
                height: 72px;
                font-size: 28px;
            }

            .profile-header .profile-actions {
                margin-left: 0;
                width: 100%;
            }

            .profile-header .profile-actions .btn {
                flex: 1;
                justify-content: center;
            }

            .profile-tabs .tab-header {
                flex-wrap: nowrap;
                overflow-x: auto;
                padding: 0 4px;
            }

            .profile-tabs .tab-header .tab-link {
                padding: 12px 14px;
                font-size: 12px;
                white-space: nowrap;
            }

            .profile-tabs .tab-header .tab-link span {
                display: none;
            }

            .profile-tabs .tab-content {
                padding: 16px;
            }

            .info-grid {
                grid-template-columns: 1fr 1fr;
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

            .profile-header {
                padding: 16px;
            }

            .profile-header .profile-avatar {
                width: 56px;
                height: 56px;
                font-size: 22px;
            }

            .profile-header .profile-info .name {
                font-size: 18px;
            }

            .profile-header .profile-info .details .detail-item {
                font-size: 12px;
            }

            .profile-tabs .tab-content {
                padding: 12px;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .info-item .label {
                font-size: 11px;
            }

            .info-item .value {
                font-size: 13px;
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
                    <a class="nav-link active" href="/identity/index.php">
                        <i class="fas fa-user-friends"></i> <span>People</span>
                    </a>
                    <a class="nav-link" href="/identity/documents/index.php">
                        <i class="fas fa-id-card"></i> <span>Documents</span>
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
                        <h1><i class="fas fa-user-circle me-2"></i>Person Profile</h1>
                        <p id="pageSubtitle">Loading...</p>
                    </div>
                    <div class="header-actions">
                        <a href="/identity/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back
                        </a>
                        <a href="/identity/edit.php?id=<?php echo $personId; ?>" class="btn btn-primary" id="editPersonBtn">
                            <i class="fas fa-edit me-2"></i> Edit
                        </a>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer"></div>

                <!-- Profile Content -->
                <div id="profileContent">
                    <div class="loading-state">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p>Loading person profile...</p>
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
        const PERSON_ID = <?php echo $personId; ?>;
        const TOKEN = localStorage.getItem('token') || '';
        const ACTIVE_TAB = '<?php echo $activeTab; ?>';

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
        function formatDate(dateStr) {
            if (!dateStr) return 'N/A';
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        }

        function formatDateTime(dateStr) {
            if (!dateStr) return 'N/A';
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function getStatusBadge(status) {
            const labels = {
                'active': 'Active',
                'inactive': 'Inactive',
                'pending': 'Pending',
                'suspended': 'Suspended',
                'archived': 'Archived'
            };
            return `<span class="badge-status ${status}">${labels[status] || status}</span>`;
        }

        function getTypeLabel(type) {
            const labels = {
                'student': 'Student',
                'teacher': 'Teacher',
                'staff': 'Staff',
                'admin': 'Administrator',
                'parent': 'Parent',
                'guardian': 'Guardian',
                'alumni': 'Alumni',
                'visitor': 'Visitor',
                'contractor': 'Contractor',
                'volunteer': 'Volunteer'
            };
            return labels[type] || type;
        }

        function getGenderLabel(gender) {
            const labels = {
                'male': 'Male',
                'female': 'Female',
                'other': 'Other',
                'prefer_not_to_say': 'Prefer not to say'
            };
            return labels[gender] || gender;
        }

        function getFullName(person) {
            let name = person.first_name || '';
            if (person.middle_name) name += ' ' + person.middle_name;
            if (person.last_name) name += ' ' + person.last_name;
            return name.trim() || 'Unknown';
        }

        function getInitials(person) {
            let initials = '';
            if (person.first_name) initials += person.first_name.charAt(0);
            if (person.last_name) initials += person.last_name.charAt(0);
            return initials.toUpperCase() || '?';
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
        // LOAD PERSON PROFILE
        // ================================================
        function loadPerson() {
            fetch(API_BASE + '/index.php?endpoint=identity&action=get&id=' + PERSON_ID, {
                    headers: getHeaders()
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success && result.data) {
                        renderProfile(result.data);
                    } else {
                        showAlert(result.message || 'Person not found', 'danger');
                        document.getElementById('profileContent').innerHTML = `
                            <div class="empty-state">
                                <div class="empty-icon"><i class="fas fa-user-slash"></i></div>
                                <h6>Person Not Found</h6>
                                <p>The person you are looking for could not be found.</p>
                                <a href="/identity/index.php" class="btn btn-primary mt-2">
                                    <i class="fas fa-arrow-left me-2"></i> Back to People
                                </a>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading person:', error);
                    showAlert('Error loading person profile', 'danger');
                });
        }

        // ================================================
        // RENDER PROFILE
        // ================================================
        function renderProfile(person) {
            const container = document.getElementById('profileContent');

            // Update page subtitle
            document.getElementById('pageSubtitle').textContent = getFullName(person);

            // Build tabs
            const tabs = [{
                    id: 'overview',
                    icon: 'fa-info-circle',
                    label: 'Overview'
                },
                {
                    id: 'details',
                    icon: 'fa-user',
                    label: 'Details'
                },
                {
                    id: 'contact',
                    icon: 'fa-address-book',
                    label: 'Contact & Address'
                },
                {
                    id: 'documents',
                    icon: 'fa-id-card',
                    label: 'Documents'
                },
                {
                    id: 'roles',
                    icon: 'fa-user-tag',
                    label: 'Roles & Assignments'
                },
            ];

            let tabsHtml = tabs.map(tab => `
                <a class="tab-link ${tab.id === ACTIVE_TAB ? 'active' : ''}" 
                   href="?id=${PERSON_ID}&tab=${tab.id}" 
                   onclick="switchTab('${tab.id}'); return false;">
                    <i class="fas ${tab.icon}"></i> <span>${tab.label}</span>
                </a>
            `).join('');

            // Build profile content
            let contentHtml = `
                <!-- Profile Header -->
                <div class="profile-header">
                    <div class="profile-avatar">${getInitials(person)}</div>
                    <div class="profile-info">
                        <div class="name">${getFullName(person)}</div>
                        <div class="details">
                            <span class="detail-item"><i class="fas fa-hashtag"></i> ${person.person_number || 'N/A'}</span>
                            <span class="detail-item"><i class="fas fa-tag"></i> ${getTypeLabel(person.person_type)}</span>
                            <span class="detail-item">${getStatusBadge(person.status)}</span>
                            <span class="detail-item"><i class="fas fa-calendar"></i> Added ${formatDate(person.created_at)}</span>
                        </div>
                    </div>
                    <div class="profile-actions">
                        <a href="/identity/edit.php?id=${PERSON_ID}" class="btn btn-primary btn-sm">
                            <i class="fas fa-edit me-1"></i> Edit
                        </a>
                        <button class="btn btn-outline-danger btn-sm" onclick="deletePerson()">
                            <i class="fas fa-trash me-1"></i> Delete
                        </button>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="profile-tabs">
                    <div class="tab-header">${tabsHtml}</div>
                    <div class="tab-content" id="tabContent">
                        ${renderTabContent(person, ACTIVE_TAB)}
                    </div>
                </div>
            `;

            container.innerHTML = contentHtml;
        }

        // ================================================
        // RENDER TAB CONTENT
        // ================================================
        function renderTabContent(person, tab) {
            switch (tab) {
                case 'overview':
                    return renderOverviewTab(person);
                case 'details':
                    return renderDetailsTab(person);
                case 'contact':
                    return renderContactTab(person);
                case 'documents':
                    return renderDocumentsTab(person);
                case 'roles':
                    return renderRolesTab(person);
                default:
                    return renderOverviewTab(person);
            }
        }

        // ================================================
        // OVERVIEW TAB
        // ================================================
        function renderOverviewTab(person) {
            return `
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-section">
                            <div class="section-title"><i class="fas fa-user"></i> Personal Information</div>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="label">Person Number</span>
                                    <span class="value"><code>${person.person_number || 'N/A'}</code></span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Full Name</span>
                                    <span class="value">${getFullName(person)}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Preferred Name</span>
                                    <span class="value">${person.preferred_name || 'N/A'}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Date of Birth</span>
                                    <span class="value">${formatDate(person.date_of_birth)}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Gender</span>
                                    <span class="value">${getGenderLabel(person.gender)}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Person Type</span>
                                    <span class="value">${getTypeLabel(person.person_type)}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Status</span>
                                    <span class="value">${getStatusBadge(person.status)}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Nationality</span>
                                    <span class="value">${person.nationality || 'N/A'}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-section">
                            <div class="section-title"><i class="fas fa-address-card"></i> Contact Information</div>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="label">Primary Phone</span>
                                    <span class="value">${person.primary_phone || 'N/A'}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Primary Email</span>
                                    <span class="value">${person.primary_email || 'N/A'}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Digital Address</span>
                                    <span class="value">${person.digital_address || 'N/A'}</span>
                                </div>
                            </div>
                        </div>
                        ${person.tenant_name ? `
                        <div class="info-section">
                            <div class="section-title"><i class="fas fa-building"></i> Tenant</div>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="label">Tenant</span>
                                    <span class="value">${person.tenant_name}</span>
                                </div>
                            </div>
                        </div>
                        ` : ''}
                        <div class="info-section">
                            <div class="section-title"><i class="fas fa-clock"></i> System Information</div>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="label">Created</span>
                                    <span class="value">${formatDateTime(person.created_at)}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Last Updated</span>
                                    <span class="value">${formatDateTime(person.updated_at)}</span>
                                </div>
                                ${person.deleted_at ? `
                                <div class="info-item">
                                    <span class="label">Deleted</span>
                                    <span class="value text-danger">${formatDateTime(person.deleted_at)}</span>
                                </div>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        // ================================================
        // DETAILS TAB
        // ================================================
        function renderDetailsTab(person) {
            return `
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-section">
                            <div class="section-title"><i class="fas fa-user-circle"></i> Personal Details</div>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="label">First Name</span>
                                    <span class="value">${person.first_name || 'N/A'}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Middle Name</span>
                                    <span class="value">${person.middle_name || 'N/A'}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Last Name</span>
                                    <span class="value">${person.last_name || 'N/A'}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Preferred Name</span>
                                    <span class="value">${person.preferred_name || 'N/A'}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Date of Birth</span>
                                    <span class="value">${formatDate(person.date_of_birth)}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Gender</span>
                                    <span class="value">${getGenderLabel(person.gender)}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Nationality</span>
                                    <span class="value">${person.nationality || 'N/A'}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Preferred Language</span>
                                    <span class="value">${person.preferred_language || 'N/A'}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-section">
                            <div class="section-title"><i class="fas fa-id-badge"></i> Type & Status</div>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="label">Person Type</span>
                                    <span class="value">${getTypeLabel(person.person_type)}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Status</span>
                                    <span class="value">${getStatusBadge(person.status)}</span>
                                </div>
                                ${person.person_number ? `
                                <div class="info-item">
                                    <span class="label">Person Number</span>
                                    <span class="value"><code>${person.person_number}</code></span>
                                </div>
                                ` : ''}
                            </div>
                        </div>
                        ${person.photo_reference ? `
                        <div class="info-section">
                            <div class="section-title"><i class="fas fa-image"></i> Photo</div>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="label">Photo Reference</span>
                                    <span class="value">${person.photo_reference}</span>
                                </div>
                            </div>
                        </div>
                        ` : ''}
                    </div>
                </div>
            `;
        }

        // ================================================
        // CONTACT TAB
        // ================================================
        function renderContactTab(person) {
            return `
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-section">
                            <div class="section-title"><i class="fas fa-phone"></i> Contact Information</div>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="label">Primary Phone</span>
                                    <span class="value">${person.primary_phone || 'N/A'}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Primary Email</span>
                                    <span class="value">${person.primary_email || 'N/A'}</span>
                                </div>
                                ${person.mobile ? `
                                <div class="info-item">
                                    <span class="label">Mobile</span>
                                    <span class="value">${person.mobile}</span>
                                </div>
                                ` : ''}
                                ${person.work_phone ? `
                                <div class="info-item">
                                    <span class="label">Work Phone</span>
                                    <span class="value">${person.work_phone}</span>
                                </div>
                                ` : ''}
                                ${person.home_phone ? `
                                <div class="info-item">
                                    <span class="label">Home Phone</span>
                                    <span class="value">${person.home_phone}</span>
                                </div>
                                ` : ''}
                                ${person.emergency_contact ? `
                                <div class="info-item">
                                    <span class="label">Emergency Contact</span>
                                    <span class="value">${person.emergency_contact}</span>
                                </div>
                                ` : ''}
                                ${person.emergency_phone ? `
                                <div class="info-item">
                                    <span class="label">Emergency Phone</span>
                                    <span class="value">${person.emergency_phone}</span>
                                </div>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-section">
                            <div class="section-title"><i class="fas fa-map-pin"></i> Address Information</div>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="label">Address Line 1</span>
                                    <span class="value">${person.address_line_1 || 'N/A'}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Address Line 2</span>
                                    <span class="value">${person.address_line_2 || 'N/A'}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">City</span>
                                    <span class="value">${person.city || 'N/A'}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">State/Province</span>
                                    <span class="value">${person.state || 'N/A'}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Country</span>
                                    <span class="value">${person.country || 'N/A'}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Postal Code</span>
                                    <span class="value">${person.postal_code || 'N/A'}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Digital Address</span>
                                    <span class="value">${person.digital_address || 'N/A'}</span>
                                </div>
                                <div class="info-item">
                                    <span class="label">Residential Address</span>
                                    <span class="value">${person.residential_address || 'N/A'}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        // ================================================
        // DOCUMENTS TAB
        // ================================================
        function renderDocumentsTab(person) {
            // Load documents from API - placeholder
            return `
                <div class="info-section">
                    <div class="section-title"><i class="fas fa-id-card"></i> Identity Documents</div>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-file-alt"></i></div>
                        <h6>No Documents Found</h6>
                        <p>This person does not have any documents attached.</p>
                        <a href="/identity/documents/create.php?person_id=${PERSON_ID}" class="btn btn-primary btn-sm mt-2">
                            <i class="fas fa-plus me-1"></i> Add Document
                        </a>
                    </div>
                </div>
            `;
        }

        // ================================================
        // ROLES TAB
        // ================================================
        function renderRolesTab(person) {
            // Load roles from API - placeholder
            return `
                <div class="info-section">
                    <div class="section-title"><i class="fas fa-user-tag"></i> Roles & Assignments</div>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-user-tag"></i></div>
                        <h6>No Roles Assigned</h6>
                        <p>This person does not have any roles or assignments.</p>
                        <button class="btn btn-primary btn-sm mt-2" onclick="showAlert('Role assignment coming soon', 'info')">
                            <i class="fas fa-plus me-1"></i> Assign Role
                        </button>
                    </div>
                </div>
            `;
        }

        // ================================================
        // SWITCH TAB
        // ================================================
        function switchTab(tabId) {
            window.location.href = '?id=' + PERSON_ID + '&tab=' + tabId;
        }

        // ================================================
        // DELETE PERSON
        // ================================================
        function deletePerson() {
            if (!confirm('Are you sure you want to delete this person? This action can be reversed.')) return;

            fetch(API_BASE + '/index.php?endpoint=identity&action=delete&id=' + PERSON_ID, {
                    method: 'DELETE',
                    headers: getHeaders()
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showAlert('Person deleted successfully', 'success');
                        setTimeout(() => {
                            window.location.href = '/identity/index.php';
                        }, 1500);
                    } else {
                        showAlert(result.message || 'Error deleting person', 'danger');
                    }
                })
                .catch(error => {
                    console.error('Error deleting person:', error);
                    showAlert('Error deleting person', 'danger');
                });
        }

        // ================================================
        // INITIALIZE
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();
            loadPerson();
        });
    </script>
</body>

</html>