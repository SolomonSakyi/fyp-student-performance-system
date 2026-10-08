<?php

/**
 * Identity Documents - Edit Document
 * 
 * @package EduTrack
 * @subpackage Identity\Documents
 * @version 2.0
 * @filepath public/identity/documents/edit.php
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

$pageTitle = 'Edit Document - EduTrack Platform';
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
        /* FORM CARD                                      */
        /* ================================================ */
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
            padding-top: 20px;
            border-top: 2px solid #f0f2f5;
            margin-top: 8px;
            flex-wrap: wrap;
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

            .form-card .card-header-custom {
                padding: 12px 16px;
            }

            .form-card .card-body-custom {
                padding: 16px;
            }

            .form-control,
            .form-select {
                font-size: 14px;
                padding: 8px 14px;
                height: 44px;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
                justify-content: center;
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

            .form-card .card-header-custom {
                padding: 12px 14px;
            }

            .form-card .card-body-custom {
                padding: 12px 14px;
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
                        <h1><i class="fas fa-id-card me-2"></i>Edit Document</h1>
                        <p id="pageSubtitle">Loading...</p>
                    </div>
                    <div class="header-actions">
                        <a href="/identity/documents/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back
                        </a>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer"></div>

                <!-- Edit Form -->
                <div id="formContainer">
                    <div class="loading-state">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p>Loading document data...</p>
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
        // FORMAT HELPERS
        // ================================================
        function formatDateForInput(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            return date.toISOString().split('T')[0];
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
                        renderForm(result.data);
                        document.getElementById('pageSubtitle').textContent =
                            `Editing ${result.data.document_type || 'Document'}`;
                    } else {
                        showAlert(result.message || 'Document not found', 'danger');
                        document.getElementById('formContainer').innerHTML = `
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
                    showAlert('Error loading document data', 'danger');
                });
        }

        // ================================================
        // RENDER FORM
        // ================================================
        function renderForm(document) {
            const container = document.getElementById('formContainer');

            container.innerHTML = `
                <div class="form-card">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-file-alt me-2 text-primary"></i>Edit Document Information</h6>
                        <span class="text-muted small">All fields marked with <span class="text-danger">*</span> are required</span>
                    </div>
                    <div class="card-body-custom">
                        <form id="editDocumentForm" novalidate>
                            <input type="hidden" name="id" value="${document.id}">

                            <!-- Document Details -->
                            <div class="form-section">
                                <div class="section-title"><i class="fas fa-info-circle"></i> Document Details</div>
                                <div class="row">
                                    <div class="col-md-6 col-12">
                                        <div class="mb-2">
                                            <label class="form-label">Document Type <span class="required">*</span></label>
                                            <select class="form-select" id="documentType" name="document_type" required>
                                                <option value="">Select Document Type</option>
                                                <option value="Ghana Card" ${document.document_type === 'Ghana Card' ? 'selected' : ''}>Ghana Card</option>
                                                <option value="Passport" ${document.document_type === 'Passport' ? 'selected' : ''}>Passport</option>
                                                <option value="Birth Certificate" ${document.document_type === 'Birth Certificate' ? 'selected' : ''}>Birth Certificate</option>
                                                <option value="National ID" ${document.document_type === 'National ID' ? 'selected' : ''}>National ID</option>
                                                <option value="Staff ID" ${document.document_type === 'Staff ID' ? 'selected' : ''}>Staff ID</option>
                                                <option value="Student ID" ${document.document_type === 'Student ID' ? 'selected' : ''}>Student ID</option>
                                                <option value="Drivers License" ${document.document_type === 'Drivers License' ? 'selected' : ''}>Driver's License</option>
                                                <option value="Voter ID" ${document.document_type === 'Voter ID' ? 'selected' : ''}>Voter ID</option>
                                                <option value="Other" ${document.document_type === 'Other' ? 'selected' : ''}>Other</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-12">
                                        <div class="mb-2">
                                            <label class="form-label">Document Number <span class="required">*</span></label>
                                            <input type="text" class="form-control" id="documentNumber" name="document_number"
                                                placeholder="Enter document number" value="${document.document_number || ''}" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-12">
                                        <div class="mb-2">
                                            <label class="form-label">Document Name</label>
                                            <input type="text" class="form-control" id="documentName" name="document_name"
                                                placeholder="Enter document name" value="${document.document_name || ''}">
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-12">
                                        <div class="mb-2">
                                            <label class="form-label">Issuing Authority</label>
                                            <input type="text" class="form-control" id="issuingAuthority" name="issuing_authority"
                                                placeholder="e.g., National Identification Authority" value="${document.issuing_authority || ''}">
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-12">
                                        <div class="mb-2">
                                            <label class="form-label">Issue Date</label>
                                            <input type="date" class="form-control" id="issueDate" name="issue_date"
                                                value="${formatDateForInput(document.issue_date)}">
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-12">
                                        <div class="mb-2">
                                            <label class="form-label">Expiry Date</label>
                                            <input type="date" class="form-control" id="expiryDate" name="expiry_date"
                                                value="${formatDateForInput(document.expiry_date)}">
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-12">
                                        <div class="mb-2">
                                            <label class="form-label">Status</label>
                                            <select class="form-select" id="status" name="status">
                                                <option value="active" ${document.status === 'active' ? 'selected' : ''}>Active</option>
                                                <option value="pending" ${document.status === 'pending' ? 'selected' : ''}>Pending</option>
                                                <option value="expired" ${document.status === 'expired' ? 'selected' : ''}>Expired</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-12">
                                        <div class="mb-2">
                                            <label class="form-label">Verification Status</label>
                                            <select class="form-select" id="verificationStatus" name="verification_status">
                                                <option value="pending" ${document.verification_status === 'pending' ? 'selected' : ''}>Pending</option>
                                                <option value="verified" ${document.verification_status === 'verified' ? 'selected' : ''}>Verified</option>
                                                <option value="rejected" ${document.verification_status === 'rejected' ? 'selected' : ''}>Rejected</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- File Upload -->
                            <div class="form-section">
                                <div class="section-title"><i class="fas fa-upload"></i> File Upload</div>
                                <div class="row">
                                    <div class="col-md-6 col-12">
                                        <div class="mb-2">
                                            <label class="form-label">File</label>
                                            <input type="file" class="form-control" id="file" name="file">
                                            <div class="form-text">Supported formats: PDF, JPG, PNG (Max: 10MB)</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-12">
                                        <div class="mb-2">
                                            <label class="form-label">File Name</label>
                                            <input type="text" class="form-control" id="fileName" name="file_name"
                                                placeholder="Enter file name" value="${document.file_name || ''}">
                                        </div>
                                    </div>
                                    ${document.file_path ? `
                                    <div class="col-12">
                                        <div class="mb-2">
                                            <label class="form-label">Current File</label>
                                            <div><code>${document.file_path}</code></div>
                                        </div>
                                    </div>
                                    ` : ''}
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="form-actions">
                                <a href="/identity/documents/index.php" class="btn btn-outline-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <i class="fas fa-save me-2"></i> Update Document
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            `;

            // Bind form submission
            const form = document.getElementById('editDocumentForm');
            const submitBtn = document.getElementById('submitBtn');

            form.addEventListener('submit', function(e) {
                e.preventDefault();

                // Validate required fields
                const requiredFields = form.querySelectorAll('[required]');
                let isValid = true;

                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        field.classList.add('is-invalid');
                        isValid = false;
                    } else {
                        field.classList.remove('is-invalid');
                    }
                });

                if (!isValid) {
                    showAlert('Please fill in all required fields', 'danger');
                    return;
                }

                // Collect form data
                const formData = new FormData(form);
                const data = {};
                formData.forEach((value, key) => {
                    if (key !== 'id' && key !== 'file') {
                        data[key] = value.trim();
                    }
                });

                // Disable button
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Updating...';

                // Submit to API
                fetch(API_BASE + '/index.php?endpoint=identity&action=update&id=' + DOCUMENT_ID, {
                        method: 'PUT',
                        headers: getHeaders(),
                        body: JSON.stringify(data)
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            showAlert('Document updated successfully!', 'success');
                            setTimeout(() => {
                                window.location.href = '/identity/documents/index.php';
                            }, 1500);
                        } else {
                            showAlert(result.message || 'Error updating document', 'danger');
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = '<i class="fas fa-save me-2"></i> Update Document';
                        }
                    })
                    .catch(error => {
                        console.error('Error updating document:', error);
                        showAlert('Error updating document: ' + error.message, 'danger');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fas fa-save me-2"></i> Update Document';
                    });
            });

            // Real-time validation on input
            const inputs = form.querySelectorAll('input, select');
            inputs.forEach(input => {
                input.addEventListener('input', function() {
                    if (this.hasAttribute('required') && this.value.trim()) {
                        this.classList.remove('is-invalid');
                    }
                });
                input.addEventListener('change', function() {
                    if (this.hasAttribute('required') && this.value.trim()) {
                        this.classList.remove('is-invalid');
                    }
                });
            });

            // Enter key support
            inputs.forEach(input => {
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && this.tagName !== 'TEXTAREA') {
                        e.preventDefault();
                        const focusable = Array.from(form.querySelectorAll(
                            'input:not([readonly]), select, textarea, button'));
                        const index = focusable.indexOf(this);
                        if (index < focusable.length - 1) {
                            focusable[index + 1].focus();
                        }
                    }
                });
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