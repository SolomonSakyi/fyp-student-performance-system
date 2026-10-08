<?php

/**
 * Identity Documents - View Document
 * 
 * @package EduTrack
 * @subpackage Identity\Documents
 * @version 2.0
 * @filepath public/identity/documents/view.php
 */

session_start();

// Check authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

$documentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$documentId) {
    header('Location: /identity/documents/index.php?error=invalid_id');
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

$pageTitle = 'View Document - EduTrack Platform';
$currentPage = 'documents';
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
        /* DOCUMENT PROFILE HEADER                        */
        /* ================================================ */
        .doc-header {
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
        }

        .doc-header .doc-icon {
            width: 72px;
            height: 72px;
            border-radius: 16px;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: #fff;
            flex-shrink: 0;
        }

        .doc-header .doc-info {
            flex: 1;
            min-width: 200px;
        }

        .doc-header .doc-info .doc-type {
            font-size: 20px;
            font-weight: 700;
            color: #1a1a2e;
        }

        .doc-header .doc-info .doc-details {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 4px;
        }

        .doc-header .doc-info .doc-details .detail-item {
            font-size: 13px;
            color: #6c757d;
        }

        .doc-header .doc-info .doc-details .detail-item i {
            width: 18px;
            color: #4facfe;
        }

        .doc-header .doc-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-left: auto;
        }

        /* ================================================ */
        /* DETAILS CARD                                   */
        /* ================================================ */
        .details-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            overflow: hidden;
        }

        .details-card .card-header-custom {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            font-weight: 600;
            font-size: 15px;
            color: #1a1a2e;
            display: flex;
            align-items: center;
            gap: 10px;
            background: transparent;
        }

        .details-card .card-header-custom i {
            color: #4facfe;
        }

        .details-card .card-body-custom {
            padding: 24px;
        }

        /* ================================================ */
        /* INFO GRID                                      */
        /* ================================================ */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
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

        .badge-status.expired {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-status.verified {
            background: #d4edda;
            color: #155724;
        }

        .badge-status.rejected {
            background: #f8d7da;
            color: #721c24;
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

            .doc-header .doc-icon {
                width: 56px;
                height: 56px;
                font-size: 24px;
            }

            .doc-header .doc-info .doc-type {
                font-size: 18px;
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

            .doc-header {
                flex-direction: column;
                align-items: flex-start;
                padding: 20px;
            }

            .doc-header .doc-icon {
                width: 64px;
                height: 64px;
                font-size: 28px;
            }

            .doc-header .doc-actions {
                margin-left: 0;
                width: 100%;
            }

            .doc-header .doc-actions .btn {
                flex: 1;
                justify-content: center;
            }

            .details-card .card-header-custom {
                padding: 12px 16px;
            }

            .details-card .card-body-custom {
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

            .doc-header {
                padding: 16px;
            }

            .doc-header .doc-icon {
                width: 48px;
                height: 48px;
                font-size: 20px;
            }

            .doc-header .doc-info .doc-type {
                font-size: 16px;
            }

            .doc-header .doc-info .doc-details .detail-item {
                font-size: 12px;
            }

            .details-card .card-body-custom {
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
                    <a class="nav-link" href="/identity/index.php">
                        <i class="fas fa-user-friends"></i> <span>People</span>
                    </a>
                    <a class="nav-link active" href="/identity/documents/index.php">
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
                        <h1><i class="fas fa-id-card me-2"></i>Document Details</h1>
                        <p id="pageSubtitle">Loading...</p>
                    </div>
                    <div class="header-actions">
                        <a href="/identity/documents/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back
                        </a>
                        <a href="/identity/documents/edit.php?id=<?php echo $documentId; ?>" class="btn btn-primary">
                            <i class="fas fa-edit me-2"></i> Edit
                        </a>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer"></div>

                <!-- Document Content -->
                <div id="documentContent">
                    <div class="loading-state">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p>Loading document details...</p>
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
        const DOCUMENT_ID = <?php echo $documentId; ?>;
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
                'pending': 'Pending',
                'expired': 'Expired',
                'verified': 'Verified',
                'rejected': 'Rejected'
            };
            const classes = {
                'active': 'active',
                'pending': 'pending',
                'expired': 'expired',
                'verified': 'verified',
                'rejected': 'rejected'
            };
            return `<span class="badge-status ${classes[status] || 'pending'}">${labels[status] || status}</span>`;
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
        // LOAD DOCUMENT DATA
        // ================================================
        function loadDocument() {
            fetch(API_BASE + '/index.php?endpoint=identity&action=get&id=' + DOCUMENT_ID, {
                    headers: getHeaders()
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success && result.data) {
                        renderDocument(result.data);
                    } else {
                        showAlert(result.message || 'Document not found', 'danger');
                        document.getElementById('documentContent').innerHTML = `
                            <div class="empty-state" style="text-align:center;padding:60px 20px;">
                                <div class="empty-icon" style="font-size:56px;color:#dee2e6;margin-bottom:16px;">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                                <h5 style="font-weight:600;color:#1a1a2e;">Document Not Found</h5>
                                <p style="color:#6c757d;">The document you are looking for could not be found.</p>
                                <a href="/identity/documents/index.php" class="btn btn-primary mt-2">
                                    <i class="fas fa-arrow-left me-2"></i> Back to Documents
                                </a>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading document:', error);
                    showAlert('Error loading document details', 'danger');
                });
        }

        // ================================================
        // RENDER DOCUMENT
        // ================================================
        function renderDocument(document) {
            const container = document.getElementById('documentContent');

            // Update page subtitle
            document.getElementById('pageSubtitle').textContent = document.document_type || 'Document';

            const status = document.verification_status || document.status || 'pending';

            container.innerHTML = `
                <!-- Document Header -->
                <div class="doc-header">
                    <div class="doc-icon">
                        <i class="fas fa-file-pdf"></i>
                    </div>
                    <div class="doc-info">
                        <div class="doc-type">${document.document_type || 'Document'}</div>
                        <div class="doc-details">
                            <span class="detail-item"><i class="fas fa-hashtag"></i> ${document.document_number || 'N/A'}</span>
                            <span class="detail-item">${getStatusBadge(status)}</span>
                            <span class="detail-item"><i class="fas fa-calendar"></i> Added ${formatDateTime(document.created_at)}</span>
                        </div>
                    </div>
                    <div class="doc-actions">
                        <a href="/identity/documents/edit.php?id=${DOCUMENT_ID}" class="btn btn-primary btn-sm">
                            <i class="fas fa-edit me-1"></i> Edit
                        </a>
                        <button class="btn btn-outline-danger btn-sm" onclick="deleteDocument()">
                            <i class="fas fa-trash me-1"></i> Delete
                        </button>
                    </div>
                </div>

                <!-- Document Details -->
                <div class="details-card">
                    <div class="card-header-custom">
                        <i class="fas fa-info-circle"></i> Document Information
                    </div>
                    <div class="card-body-custom">
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="label">Document Type</span>
                                <span class="value">${document.document_type || 'N/A'}</span>
                            </div>
                            <div class="info-item">
                                <span class="label">Document Number</span>
                                <span class="value"><code>${document.document_number || 'N/A'}</code></span>
                            </div>
                            <div class="info-item">
                                <span class="label">Document Name</span>
                                <span class="value">${document.document_name || 'N/A'}</span>
                            </div>
                            <div class="info-item">
                                <span class="label">Issuing Authority</span>
                                <span class="value">${document.issuing_authority || 'N/A'}</span>
                            </div>
                            <div class="info-item">
                                <span class="label">Issue Date</span>
                                <span class="value">${formatDate(document.issue_date)}</span>
                            </div>
                            <div class="info-item">
                                <span class="label">Expiry Date</span>
                                <span class="value">${formatDate(document.expiry_date)}</span>
                            </div>
                            <div class="info-item">
                                <span class="label">Status</span>
                                <span class="value">${getStatusBadge(document.status)}</span>
                            </div>
                            <div class="info-item">
                                <span class="label">Verification Status</span>
                                <span class="value">${getStatusBadge(document.verification_status)}</span>
                            </div>
                            ${document.file_path ? `
                            <div class="info-item">
                                <span class="label">File Path</span>
                                <span class="value"><code>${document.file_path}</code></span>
                            </div>
                            ` : ''}
                            ${document.file_name ? `
                            <div class="info-item">
                                <span class="label">File Name</span>
                                <span class="value">${document.file_name}</span>
                            </div>
                            ` : ''}
                            ${document.file_size ? `
                            <div class="info-item">
                                <span class="label">File Size</span>
                                <span class="value">${(document.file_size / 1024).toFixed(2)} KB</span>
                            </div>
                            ` : ''}
                            ${document.mime_type ? `
                            <div class="info-item">
                                <span class="label">MIME Type</span>
                                <span class="value">${document.mime_type}</span>
                            </div>
                            ` : ''}
                            ${document.tenant_id ? `
                            <div class="info-item">
                                <span class="label">Tenant ID</span>
                                <span class="value">${document.tenant_id}</span>
                            </div>
                            ` : ''}
                            ${document.tenant_name ? `
                            <div class="info-item">
                                <span class="label">Tenant</span>
                                <span class="value">${document.tenant_name}</span>
                            </div>
                            ` : ''}
                            ${document.verified_by ? `
                            <div class="info-item">
                                <span class="label">Verified By</span>
                                <span class="value">${document.verified_by}</span>
                            </div>
                            ` : ''}
                            ${document.verified_at ? `
                            <div class="info-item">
                                <span class="label">Verified At</span>
                                <span class="value">${formatDateTime(document.verified_at)}</span>
                            </div>
                            ` : ''}
                            ${document.created_by ? `
                            <div class="info-item">
                                <span class="label">Created By</span>
                                <span class="value">${document.created_by}</span>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                </div>

                <!-- System Information -->
                <div class="details-card mt-3">
                    <div class="card-header-custom">
                        <i class="fas fa-clock"></i> System Information
                    </div>
                    <div class="card-body-custom">
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="label">Created At</span>
                                <span class="value">${formatDateTime(document.created_at)}</span>
                            </div>
                            <div class="info-item">
                                <span class="label">Last Updated</span>
                                <span class="value">${formatDateTime(document.updated_at)}</span>
                            </div>
                            ${document.deleted_at ? `
                            <div class="info-item">
                                <span class="label">Deleted At</span>
                                <span class="value text-danger">${formatDateTime(document.deleted_at)}</span>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                </div>
            `;
        }

        // ================================================
        // DELETE DOCUMENT
        // ================================================
        function deleteDocument() {
            if (!confirm('Are you sure you want to delete this document? This action can be reversed.')) return;

            fetch(API_BASE + '/index.php?endpoint=identity&action=delete&id=' + DOCUMENT_ID, {
                    method: 'DELETE',
                    headers: getHeaders()
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showAlert('Document deleted successfully', 'success');
                        setTimeout(() => {
                            window.location.href = '/identity/documents/index.php';
                        }, 1500);
                    } else {
                        showAlert(result.message || 'Error deleting document', 'danger');
                    }
                })
                .catch(error => {
                    console.error('Error deleting document:', error);
                    showAlert('Error deleting document', 'danger');
                });
        }

        // ================================================
        // INITIALIZE
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();
            loadDocument();
        });
    </script>
</body>

</html>