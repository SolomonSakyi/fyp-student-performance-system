<?php

/**
 * View Notification - View notification details
 */
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

$pageTitle = 'View Notification - EduTrack Platform';
$currentPage = 'notifications';

$notificationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
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
        /* Standard dashboard styles */
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

        .badge-status.sent {
            background: #cce5ff;
            color: #004085;
        }

        .badge-status.delivered {
            background: #d4edda;
            color: #155724;
        }

        .detail-row {
            display: flex;
            padding: 8px 0;
            border-bottom: 1px solid #f0f2f5;
            flex-wrap: wrap;
            gap: 4px;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-row .detail-label {
            width: 140px;
            font-weight: 500;
            color: #6c757d;
            flex-shrink: 0;
            font-size: 13px;
        }

        .detail-row .detail-value {
            flex: 1;
            font-weight: 500;
            color: #1a1a2e;
            font-size: 14px;
            word-break: break-word;
        }

        .loading-spinner {
            text-align: center;
            padding: 60px 20px;
        }

        .loading-spinner .spinner-border {
            width: 3rem;
            height: 3rem;
        }

        .loading-spinner p {
            margin-top: 12px;
            color: #6c757d;
            font-size: 15px;
        }

        .delivery-item {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f0f2f5;
        }

        .delivery-item:last-child {
            border-bottom: none;
        }

        .delivery-item .delivery-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            flex-shrink: 0;
        }

        .delivery-item .delivery-icon.sms {
            background: rgba(79, 172, 254, 0.12);
            color: #4facfe;
        }

        .delivery-item .delivery-icon.email {
            background: rgba(67, 233, 123, 0.12);
            color: #28a745;
        }

        .delivery-item .delivery-icon.push {
            background: rgba(254, 225, 64, 0.12);
            color: #f59f00;
        }

        .delivery-item .delivery-icon.inapp {
            background: rgba(161, 140, 209, 0.12);
            color: #7c3aed;
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

            .detail-row .detail-label {
                width: 100px;
                font-size: 12px;
            }

            .detail-row .detail-value {
                font-size: 13px;
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

            .detail-row .detail-label {
                width: 80px;
                font-size: 11px;
            }

            .detail-row .detail-value {
                font-size: 12px;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <!-- Sidebar -->
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
            <!-- Main Content -->
            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-bell me-2"></i>Notification Details</h1>
                        <p>View notification details and delivery status</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/notifications/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back
                        </a>
                    </div>
                </div>

                <div id="alertContainer"></div>

                <div id="loading" class="loading-spinner">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p>Loading notification details...</p>
                </div>

                <div id="viewContent" style="display: none;">
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-info-circle me-2 text-primary"></i>Notification Information</h6>
                            <span id="statusBadge"></span>
                        </div>
                        <div class="card-body-custom">
                            <div class="row">
                                <div class="col-md-6 col-12">
                                    <div class="detail-row">
                                        <span class="detail-label">Subject</span>
                                        <span class="detail-value" id="notifSubject">-</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Event</span>
                                        <span class="detail-value" id="notifEvent">-</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Category</span>
                                        <span class="detail-value" id="notifCategory">-</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Priority</span>
                                        <span class="detail-value" id="notifPriority">-</span>
                                    </div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="detail-row">
                                        <span class="detail-label">Created</span>
                                        <span class="detail-value" id="notifCreated">-</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Sent</span>
                                        <span class="detail-value" id="notifSent">-</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Delivered</span>
                                        <span class="detail-value" id="notifDelivered">-</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Channels</span>
                                        <span class="detail-value" id="notifChannels">-</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-align-left me-2 text-secondary"></i>Message</h6>
                        </div>
                        <div class="card-body-custom">
                            <div id="notifBody" style="white-space:pre-wrap;font-size:14px;line-height:1.8;">-</div>
                        </div>
                    </div>

                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-truck me-2 text-info"></i>Deliveries</h6>
                            <span class="badge bg-secondary" id="deliveryCount">0</span>
                        </div>
                        <div class="card-body-custom" id="deliveriesList">
                            <div class="text-center py-3 text-muted">No deliveries</div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // ================================================
        // CONFIGURATION
        // ================================================
        const API_BASE = 'http://localhost:8000/api/platform';
        const TOKEN = localStorage.getItem('token') || '';
        const notificationId = <?php echo $notificationId; ?>;

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
                window.location.href = 'http://localhost:8000/platform/login.php';
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
                'SENT': '<span class="badge-status sent">Sent</span>',
                'DELIVERED': '<span class="badge-status delivered">Delivered</span>',
                'FAILED': '<span class="badge-status failed">Failed</span>',
                'PENDING': '<span class="badge-status pending">Pending</span>',
                'QUEUED': '<span class="badge-status pending">Queued</span>',
                'PROCESSING': '<span class="badge-status pending">Processing</span>'
            };
            return map[status] || '<span class="badge-status">' + status + '</span>';
        }

        function getChannelIcon(channel) {
            const map = {
                'sms': 'fa-sms',
                'email': 'fa-envelope',
                'push': 'fa-mobile-alt',
                'inapp': 'fa-bell',
                'whatsapp': 'fa-whatsapp'
            };
            return map[channel] || 'fa-bell';
        }

        function getChannelClass(channel) {
            const map = {
                'sms': 'sms',
                'email': 'email',
                'push': 'push',
                'inapp': 'inapp',
                'whatsapp': 'sms'
            };
            return map[channel] || 'sms';
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
                <div class="alert ${colors[type] || 'alert-info'} alert-dismissible fade show" role="alert">
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
        // LOAD NOTIFICATION
        // ================================================
        async function loadNotification() {
            if (!notificationId || notificationId === 0) {
                document.getElementById('loading').innerHTML = `
                    <div class="text-center py-4 text-danger">
                        <i class="fas fa-exclamation-circle fa-2x d-block mb-2"></i>
                        <h5>Invalid Notification ID</h5>
                        <a href="/platform/notifications/index.php" class="btn btn-primary">Back to Notifications</a>
                    </div>
                `;
                return;
            }

            try {
                document.getElementById('loading').style.display = 'block';
                document.getElementById('viewContent').style.display = 'none';

                const response = await fetch(`${API_BASE}/index.php?endpoint=notifications/view&id=${notificationId}`, {
                    headers: getHeaders()
                });
                const result = await response.json();

                if (result.success && result.data) {
                    const data = result.data;

                    document.getElementById('notifSubject').textContent = data.subject || 'N/A';
                    document.getElementById('notifEvent').textContent = data.event_name || 'N/A';
                    document.getElementById('notifCategory').textContent = data.category || 'N/A';
                    document.getElementById('notifPriority').textContent = data.priority || 'N/A';
                    document.getElementById('notifCreated').textContent = formatDateTime(data.created_at);
                    document.getElementById('notifSent').textContent = data.sent_at ? formatDateTime(data.sent_at) : 'Not sent';
                    document.getElementById('notifDelivered').textContent = data.delivered_at ? formatDateTime(data.delivered_at) : 'Not delivered';
                    document.getElementById('notifChannels').textContent = data.channels || 'N/A';
                    document.getElementById('notifBody').textContent = data.body || data.message || 'No message';

                    document.getElementById('statusBadge').innerHTML = getStatusBadge(data.status);

                    // Deliveries
                    const deliveries = data.deliveries || [];
                    document.getElementById('deliveryCount').textContent = deliveries.length;

                    if (deliveries.length === 0) {
                        document.getElementById('deliveriesList').innerHTML = `
                            <div class="text-center py-3 text-muted">
                                <i class="fas fa-inbox fa-2x d-block mb-2 opacity-50"></i>
                                No delivery records
                            </div>
                        `;
                    } else {
                        document.getElementById('deliveriesList').innerHTML = deliveries.map(d => {
                            const icon = getChannelIcon(d.channel_name);
                            const channelClass = getChannelClass(d.channel_name);
                            const statusBadge = getStatusBadge(d.status);
                            const recipient = d.recipient_name || d.recipient_email || d.recipient_phone || 'Unknown';

                            return `
                                <div class="delivery-item">
                                    <div class="delivery-icon ${channelClass}">
                                        <i class="fas ${icon}"></i>
                                    </div>
                                    <div style="flex:1;min-width:0;">
                                        <div style="font-weight:500;font-size:14px;">${d.channel_name || 'Unknown'}</div>
                                        <div style="font-size:12px;color:#6c757d;">To: ${recipient}</div>
                                        ${d.status_message ? `<div style="font-size:12px;color:#6c757d;">${d.status_message}</div>` : ''}
                                    </div>
                                    <div style="text-align:right;flex-shrink:0;margin-left:12px;">
                                        ${statusBadge}
                                        <div style="font-size:11px;color:#6c757d;margin-top:2px;">${formatDateTime(d.created_at)}</div>
                                    </div>
                                </div>
                            `;
                        }).join('');
                    }

                    document.getElementById('loading').style.display = 'none';
                    document.getElementById('viewContent').style.display = 'block';

                } else {
                    document.getElementById('loading').innerHTML = `
                        <div class="text-center py-4 text-danger">
                            <i class="fas fa-exclamation-circle fa-2x d-block mb-2"></i>
                            <h5>Error</h5>
                            <p>${result.message || 'Notification not found'}</p>
                            <a href="/platform/notifications/index.php" class="btn btn-primary">Back to Notifications</a>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error loading notification:', error);
                document.getElementById('loading').innerHTML = `
                    <div class="text-center py-4 text-danger">
                        <i class="fas fa-exclamation-circle fa-2x d-block mb-2"></i>
                        <h5>Error</h5>
                        <p>Error loading notification: ${error.message}</p>
                        <a href="/platform/notifications/index.php" class="btn btn-primary">Back to Notifications</a>
                    </div>
                `;
            }
        }

        // ================================================
        // INITIALIZE
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();
            loadNotification();
        });
    </script>
</body>

</html>