<?php
/**
 * School Settings Dashboard
 * @package EduTrack
 * @subpackage Platform\Schools\Settings
 * @version 1.0
 */

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

$schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
if (!$schoolId) {
    $schoolId = $_SESSION['selected_school_id'] ?? 1;
}

$pageTitle = 'School Settings - EduTrack Platform';
$currentPage = 'schools';
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
        /* =============================================== */
        /* GLOBAL RESET */
        /* =============================================== */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            margin: 0; padding: 0;
            overflow-x: hidden !important;
            width: 100%; max-width: 100%;
            background: #f0f2f5;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 14px;
            line-height: 1.6;
            color: #1a1a2e;
        }
        .container-fluid { padding: 0; margin: 0; width: 100%; max-width: 100%; overflow-x: hidden; }
        .row { margin: 0; width: 100%; max-width: 100%; }
        [class*="col-"] { padding-left: 12px; padding-right: 12px; }

        /* =============================================== */
        /* SIDEBAR */
        /* =============================================== */
        .sidebar-toggle {
            display: none;
            position: fixed;
            top: 14px; left: 14px;
            z-index: 1001;
            background: #1a1a2e;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 22px;
            cursor: pointer;
            box-shadow: 0 2px 15px rgba(0,0,0,0.2);
        }
        .sidebar-toggle:hover { background: #2a2a4e; }
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
            color: #fff;
            position: fixed;
            width: 260px;
            left: 0; top: 0;
            z-index: 1000;
            box-shadow: 4px 0 20px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
            overflow-y: auto;
            padding: 0;
        }
        .sidebar .sidebar-header { padding: 25px 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .sidebar .sidebar-header h4 { font-weight: 700; font-size: 20px; margin: 0; color: #4facfe; }
        .sidebar .sidebar-header small { color: rgba(255,255,255,0.4); font-size: 12px; }
        .sidebar .nav { padding: 16px 12px; }
        .sidebar .nav-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255,255,255,0.3);
            padding: 0 12px 8px;
            font-weight: 600;
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.6);
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
        .sidebar .nav-link:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .sidebar .nav-link.active {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: #fff;
            box-shadow: 0 4px 15px rgba(79,172,254,0.3);
        }
        .sidebar .nav-link i { width: 22px; text-align: center; margin-right: 12px; font-size: 15px; }
        .sidebar .sidebar-footer {
            position: absolute;
            bottom: 0;
            width: 100%;
            padding: 20px 24px;
            border-top: 1px solid rgba(255,255,255,0.08);
            background: rgba(0,0,0,0.2);
        }
        .sidebar .sidebar-footer .user-info { display: flex; align-items: center; gap: 12px; }
        .sidebar .sidebar-footer .user-avatar {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center; justify-content: center;
            font-weight: 700; font-size: 16px;
            color: #fff;
            flex-shrink: 0;
        }
        .sidebar .sidebar-footer .user-name { font-weight: 600; font-size: 14px; }
        .sidebar .sidebar-footer .user-role { font-size: 11px; color: rgba(255,255,255,0.5); }
        .sidebar .sidebar-footer .logout-btn {
            background: rgba(255,255,255,0.1);
            border: none;
            color: #fff;
            padding: 8px 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .sidebar .sidebar-footer .logout-btn:hover { background: rgba(255,255,255,0.2); }

        /* =============================================== */
        /* MAIN CONTENT */
        /* =============================================== */
        .main-content {
            margin-left: 260px;
            padding: 24px 32px;
            min-height: 100vh;
            width: calc(100% - 260px);
        }
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 0 24px 0;
            flex-wrap: wrap;
            gap: 10px;
        }
        .top-bar .page-title h1 { font-size: 28px; font-weight: 800; color: #1a1a2e; margin: 0; letter-spacing: -0.5px; }
        .top-bar .page-title p { color: #6c757d; margin: 0; font-size: 14px; font-weight: 400; }
        .top-bar .header-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .top-bar .header-actions .btn {
            border-radius: 12px;
            padding: 8px 20px;
            font-weight: 500;
            font-size: 13px;
            transition: all 0.3s;
        }
        .top-bar .header-actions .btn i { margin-right: 6px; }

        /* =============================================== */
        /* SETTINGS LAYOUT */
        /* =============================================== */
        .settings-container {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }
        .settings-sidebar { width: 240px; flex-shrink: 0; }
        .settings-content { flex: 1; min-width: 0; }
        .settings-nav {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border: 1px solid rgba(0,0,0,0.03);
            overflow: hidden;
        }
        .settings-nav .nav-item { border-bottom: 1px solid #f0f2f5; }
        .settings-nav .nav-item:last-child { border-bottom: none; }
        .settings-nav .nav-link {
            padding: 12px 18px;
            color: #6c757d;
            font-weight: 500;
            font-size: 13px;
            border-radius: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s;
            text-decoration: none;
        }
        .settings-nav .nav-link:hover { background: #f8f9fa; color: #1a1a2e; }
        .settings-nav .nav-link.active {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: #fff;
            box-shadow: 0 4px 15px rgba(79,172,254,0.3);
        }
        .settings-nav .nav-link i { width: 20px; text-align: center; font-size: 14px; }
        .settings-nav .nav-link .badge { margin-left: auto; font-size: 10px; padding: 2px 8px; }
        .settings-nav .nav-link .badge.bg-primary { background: #4facfe !important; }

        /* =============================================== */
        /* CARDS */
        /* =============================================== */
        .card-custom {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
            border: 1px solid rgba(0,0,0,0.03);
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
        .card-custom .card-header-custom h6 { font-weight: 600; margin: 0; font-size: 15px; color: #1a1a2e; }
        .card-custom .card-header-custom h6 i { margin-right: 8px; }
        .card-custom .card-body-custom { padding: 20px 24px; }

        /* =============================================== */
        /* FORM ELEMENTS */
        /* =============================================== */
        .form-control, .form-select {
            border-radius: 10px;
            padding: 10px 14px;
            border: 2px solid #e9ecf;
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
        .form-control:focus, .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 4px rgba(79,172,254,0.1);
            outline: none;
        }
        .form-label { font-weight: 500; font-size: 13px; color: #1a1a2e; margin-bottom: 4px; }
        .form-text { font-size: 11px; color: #6c757d; margin-top: 4px; }
        .required { color: #dc3545; }

        /* =============================================== */
        /* BUTTONS */
        /* =============================================== */
        .btn {
            border-radius: 10px;
            padding: 8px 18px;
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
            box-shadow: 0 4px 20px rgba(79,172,254,0.3);
            transform: translateY(-1px);
            color: #fff;
        }
        .btn-outline-secondary {
            border: 2px solid #e9ecf;
            color: #6c757d;
            background: transparent;
        }
        .btn-outline-secondary:hover { background: #f8f9fa; border-color: #4facfe; color: #4facfe; }
        .btn-outline-primary {
            border: 2px solid #4facfe;
            color: #4facfe;
            background: transparent;
        }
        .btn-outline-primary:hover { background: #4facfe; color: #fff; }
        .btn-outline-success {
            border: 2px solid #28a745;
            color: #28a745;
            background: transparent;
        }
        .btn-outline-success:hover { background: #28a745; color: #fff; }
        .btn-outline-danger {
            border: 2px solid #dc3545;
            color: #dc3545;
            background: transparent;
        }
        .btn-outline-danger:hover { background: #dc3545; color: #fff; }
        .btn-outline-warning {
            border: 2px solid #ffc107;
            color: #ffc107;
            background: transparent;
        }
        .btn-outline-warning:hover { background: #ffc107; color: #fff; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }

        /* =============================================== */
        /* ALERTS */
        /* =============================================== */
        .alert-custom {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            padding: 14px 20px;
        }
        .alert-custom.alert-success { background: #d4edda; color: #155724; }
        .alert-custom.alert-danger { background: #f8d7da; color: #721c24; }
        .alert-custom.alert-warning { background: #fff3cd; color: #856404; }
        .alert-custom.alert-info { background: #cce5ff; color: #004085; }

        /* =============================================== */
        /* RESPONSIVE */
        /* =============================================== */
        @media (max-width: 992px) {
            .sidebar { width: 72px; overflow: hidden; }
            .sidebar .sidebar-header h4 { font-size: 0; }
            .sidebar .sidebar-header h4 i { font-size: 24px; }
            .sidebar .sidebar-header small { display: none; }
            .sidebar .nav-link span { display: none; }
            .sidebar .nav-link i { margin-right: 0; font-size: 18px; }
            .sidebar .nav-link { text-align: center; padding: 12px; justify-content: center; }
            .sidebar .nav-label { display: none; }
            .sidebar .sidebar-footer .user-info span { display: none; }
            .sidebar .sidebar-footer .user-info { justify-content: center; }
            .main-content { margin-left: 72px; width: calc(100% - 72px); padding: 20px; }
            .sidebar-toggle { display: none; }
            .settings-sidebar { width: 100%; }
            .settings-container { flex-direction: column; }
            .settings-nav { display: flex; flex-wrap: wrap; }
            .settings-nav .nav-item { border-bottom: none; border-right: 1px solid #f0f2f5; }
            .settings-nav .nav-link { padding: 10px 14px; font-size: 12px; }
        }
        @media (max-width: 768px) {
            .sidebar-toggle { display: block; }
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
                position: fixed;
                z-index: 1000;
                top: 0; left: 0;
                height: 100vh;
                overflow-y: auto;
            }
            .sidebar.open { transform: translateX(0); }
            .sidebar .sidebar-header h4 { font-size: 20px; }
            .sidebar .sidebar-header small { display: block; }
            .sidebar .nav-link span { display: inline; }
            .sidebar .nav-link i { margin-right: 12px; font-size: 15px; }
            .sidebar .nav-link { text-align: left; padding: 10px 16px; justify-content: flex-start; }
            .main-content { margin-left: 0; width: 100%; padding: 16px; }
            .top-bar .page-title h1 { font-size: 22px; }
            .settings-nav .nav-item { border-right: none; border-bottom: 1px solid #f0f2f5; }
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
                    <a class="nav-link" href="/platform/index.php"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
                    <a class="nav-link" href="/platform/tenants/index.php"><i class="fas fa-building"></i><span>Tenants</span></a>
                    <a class="nav-link" href="/platform/users/index.php"><i class="fas fa-users"></i><span>Users</span></a>
                    <div class="nav-label mt-3">Management</div>
                    <a class="nav-link" href="/platform/schools/index.php"><i class="fas fa-school"></i><span>Schools</span></a>
                    <a class="nav-link" href="/platform/subscriptions/index.php"><i class="fas fa-crown"></i><span>Subscriptions</span></a>
                    <div class="nav-label mt-3">Finance</div>
                    <a class="nav-link" href="/platform/payments/index.php"><i class="fas fa-credit-card"></i><span>Payments</span></a>
                    <div class="nav-label mt-3">System</div>
                    <a class="nav-link active" href="/platform/settings/index.php"><i class="fas fa-cog"></i><span>Settings</span></a>
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
                        <button class="logout-btn" onclick="logout()" title="Logout"><i class="fas fa-sign-out-alt"></i></button>
                    </div>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-school me-2"></i>School Settings</h1>
                        <p>Configure school-specific settings including assessment, grading, and academic rules</p>
                    </div>
                    <div class="header-actions">
                        <button class="btn btn-outline-secondary" onclick="refreshData()">
                            <i class="fas fa-sync-alt me-2"></i> Refresh
                        </button>
                    </div>
                </div>

                <div id="alertContainer"></div>

                <!-- Settings Container -->
                <div class="settings-container">
                    <!-- Settings Sidebar -->
                    <div class="settings-sidebar">
                        <nav class="settings-nav">
                            <div class="nav-item">
                                <a class="nav-link <?php echo $activeTab == 'overview' ? 'active' : ''; ?>" href="?tab=overview&school_id=<?php echo $schoolId; ?>">
                                    <i class="fas fa-home"></i> Overview
                                </a>
                            </div>
                            <div class="nav-item">
                                <a class="nav-link <?php echo $activeTab == 'profile' ? 'active' : ''; ?>" href="?tab=profile&school_id=<?php echo $schoolId; ?>">
                                    <i class="fas fa-building"></i> Profile
                                </a>
                            </div>
                            <div class="nav-item">
                                <a class="nav-link <?php echo $activeTab == 'branding' ? 'active' : ''; ?>" href="?tab=branding&school_id=<?php echo $schoolId; ?>">
                                    <i class="fas fa-palette"></i> Branding
                                </a>
                            </div>
                            <div class="nav-item">
                                <a class="nav-link <?php echo $activeTab == 'regional' ? 'active' : ''; ?>" href="?tab=regional&school_id=<?php echo $schoolId; ?>">
                                    <i class="fas fa-globe"></i> Regional
                                </a>
                            </div>
                            <!-- NEW ASSESSMENT TAB -->
                            <div class="nav-item">
                                <a class="nav-link <?php echo $activeTab == 'assessment' ? 'active' : ''; ?>" href="?tab=assessment&school_id=<?php echo $schoolId; ?>">
                                    <i class="fas fa-clipboard-check"></i> Assessment
                                </a>
                            </div>
                        </nav>
                    </div>

                    <!-- Settings Content -->
                    <div class="settings-content">
                        <?php
                        $tabFile = __DIR__ . '/' . $activeTab . '.php';
                        if (file_exists($tabFile)) {
                            include $tabFile;
                        } else {
                            echo '<div class="alert alert-warning">Tab file not found: ' . $activeTab . '.php</div>';
                        }
                        ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // =============================================
        // SIDEBAR TOGGLE
        // =============================================
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }

        document.addEventListener('click', function(event) {
            var sidebar = document.getElementById('sidebar');
            var toggle = document.getElementById('sidebarToggle');
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

        // =============================================
        // LOGOUT
        // =============================================
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                localStorage.removeItem('token');
                localStorage.removeItem('user');
                window.location.href = window.location.origin + '/platform/login.php';
            }
        }

        // =============================================
        // REFRESH DATA
        // =============================================
        function refreshData() {
            var btn = document.querySelector('.header-actions .btn-outline-secondary i');
            if (btn) {
                btn.className = 'fas fa-sync-alt fa-spin';
                setTimeout(function() {
                    btn.className = 'fas fa-sync-alt';
                    location.reload();
                }, 1000);
            }
        }

        // =============================================
        // SHOW ALERT
        // =============================================
        function showAlert(message, type) {
            type = type || 'info';
            var container = document.getElementById('alertContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'alertContainer';
                container.style.position = 'fixed';
                container.style.top = '20px';
                container.style.right = '20px';
                container.style.zIndex = '9999';
                container.style.maxWidth = '400px';
                document.body.appendChild(container);
            }

            var colors = {
                success: 'alert-success',
                danger: 'alert-danger',
                warning: 'alert-warning',
                info: 'alert-info'
            };
            var icons = {
                success: 'fa-check-circle',
                danger: 'fa-exclamation-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            };

            var alertDiv = document.createElement('div');
            alertDiv.className = 'alert ' + (colors[type] || 'alert-info') + ' alert-dismissible fade show alert-custom';
            alertDiv.style.borderRadius = '12px';
            alertDiv.style.boxShadow = '0 4px 20px rgba(0,0,0,0.15)';
            alertDiv.style.marginBottom = '10px';
            alertDiv.innerHTML =
                '<i class="fas ' + (icons[type] || 'fa-info-circle') + ' me-2"></i> ' +
                message +
                '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';

            container.appendChild(alertDiv);

            setTimeout(function() {
                if (alertDiv.parentNode) {
                    alertDiv.classList.remove('show');
                    setTimeout(function() {
                        if (alertDiv.parentNode) {
                            alertDiv.parentNode.removeChild(alertDiv);
                        }
                    }, 300);
                }
            }, 5000);
        }

        // =============================================
        // LOAD USER INFO
        // =============================================
        function loadUserInfo() {
            var userStr = localStorage.getItem('user');
            if (userStr) {
                try {
                    var user = JSON.parse(userStr);
                    document.getElementById('userName').textContent = user.first_name || 'Admin';
                    document.getElementById('userAvatar').textContent = (user.first_name || 'A').charAt(0);
                    document.getElementById('userRole').textContent = (user.roles || ['Administrator'])[0];
                } catch (e) {
                    console.error('Error parsing user:', e);
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>