<?php

/**
 * Tenant Settings - shell page for the tenant settings subsystem.
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Settings
 * @version 1.1
 * @filepath public/platform/tenant/settings/index.php
 *
 * v1.1 change (2026-10-05):
 *   The shell now hydrates TenantContext for the current request,
 *   immediately after DatabaseHelper is loaded and before the tab
 *   body is included. The previous version did not, so every tab
 *   body that reads a service (integrations.php, advanced.php,
 *   email.php) hit "Permission denied" from
 *   SettingsService::getSettings() -> TenantContext::hasPermission(),
 *   because the context's userRoles property was empty even though
 *   the session carried role_names = ["Tenant Admin"]. One
 *   require_once and one restoreFromSession() call close that. The
 *   call is guarded by try/catch so a context failure does not
 *   break the shell.
 *
 * WHAT THIS PAGE DOES:
 * - Requires an authenticated tenant session.
 * - Renders the tenant chrome (sidebar, top bar, tenant banner).
 * - Renders a tab strip for the nine settings tabs the API
 *   supports: general, security, currency, integrations,
 *   appearance, email, advanced, domains, audit-logs.
 * - Reads the current tab from ?tab=X. Defaults to 'general' if
 *   that tab body exists, otherwise 'integrations'.
 * - Includes the tab body from settings/<tab>.php when the file
 *   exists. If the file does not exist, renders a "Tab not yet
 *   implemented" placeholder card.
 * - Hydrates TenantContext::getInstance()->restoreFromSession()
 *   before the include, so every tab body sees a populated context.
 *
 * WHAT THIS PAGE DOES NOT DO:
 * - It does not implement any tab's form. Each tab body is its own
 *   file; the shell only renders the tab strip and includes the
 *   body.
 * - It does not call the API itself. Each tab body is responsible
 *   for its own load and save requests.
 *
 * RECORD REFERENCES:
 * - The carried-over list in EduTrack56.pdf names integrations.php
 *   (item 1) and advanced.php (item 2). This shell is the parent
 *   page for both.
 */

// =============================================
// SESSION START
// =============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =============================================
// AUTHENTICATION CHECK
// =============================================
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/tenant/login.php');
    exit;
}

if (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] === true) {
    header('Location: /platform/index.php');
    exit;
}

$tenantId = $_SESSION['tenant_id'] ?? 0;
if (!$tenantId) {
    header('Location: /platform/tenant/login.php?error=no_tenant');
    exit;
}

// =============================================
// LOAD CONFIG AND DATABASE HELPER
// =============================================
$projectRoot = dirname(__DIR__, 4);

if (file_exists($projectRoot . '/config/config.php')) {
    require_once $projectRoot . '/config/config.php';
} else {
    die('config.php not found!');
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';

if (file_exists($projectRoot . '/app/helpers/Security.php')) {
    require_once $projectRoot . '/app/helpers/Security.php';
}

// [v1.1] Load the TenantContext class so the shell can hydrate it.
require_once $projectRoot . '/app/services/Tenant/TenantContext.php';

$db = DatabaseHelper::getInstance();

// =============================================
// HYDRATE TENANT CONTEXT FOR THIS REQUEST  [v1.1]
// =============================================
// The tab bodies read services that check
// TenantContext::hasPermission(). That method reads the singleton's
// userRoles property, which is populated only by
// restoreFromSession(). The previous version of this shell did not
// call it, so the context was empty and every service call threw
// "Permission denied" — even though the session itself carried
// role_names = ["Tenant Admin"]. The call is guarded so a failure
// here does not break the shell.
try {
    TenantContext::getInstance()->restoreFromSession();
} catch (Throwable $e) {
    error_log('settings/index.php: TenantContext::restoreFromSession failed - ' . $e->getMessage());
}

// =============================================
// HELPERS
// =============================================

if (!function_exists('h')) {
    function h($s)
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// =============================================
// TAB DEFINITIONS
// =============================================
// The nine tabs the settings subsystem supports. Each entry maps a
// key to a display label and an icon. The body file name is the key
// plus '.php'.
$tabs = [
    'general'      => ['label' => 'General',       'icon' => 'fas fa-sliders-h'],
    'security'     => ['label' => 'Security',      'icon' => 'fas fa-shield-alt'],
    'currency'     => ['label' => 'Currency',      'icon' => 'fas fa-coins'],
    'integrations' => ['label' => 'Integrations',  'icon' => 'fas fa-plug'],
    'appearance'   => ['label' => 'Appearance',    'icon' => 'fas fa-palette'],
    'email'        => ['label' => 'Email',         'icon' => 'fas fa-envelope'],
    'advanced'     => ['label' => 'Advanced',      'icon' => 'fas fa-cogs'],
    'domains'      => ['label' => 'Domains',       'icon' => 'fas fa-globe'],
    'audit-logs'   => ['label' => 'Audit Logs',    'icon' => 'fas fa-history'],
];

// =============================================
// RESOLVE THE CURRENT TAB
// =============================================
$requested = isset($_GET['tab']) ? (string)$_GET['tab'] : '';

// Only accept a tab that is in the definition list.
if ($requested === '' || !isset($tabs[$requested])) {
    $requested = '';
}

// If no tab was requested, pick a default: 'general' if its body
// exists, otherwise 'integrations'.
if ($requested === '') {
    if (file_exists(__DIR__ . '/general.php')) {
        $requested = 'general';
    } else {
        $requested = 'integrations';
    }
}

$currentTab = $requested;
$currentTabDef = $tabs[$currentTab];
$tabBodyPath = __DIR__ . '/' . $currentTab . '.php';
$tabBodyExists = file_exists($tabBodyPath);

// =============================================
// TENANT NAME FOR SIDEBAR
// =============================================
$tenantName = $_SESSION['tenant_name'] ?? 'My Organization';
if (!$tenantName || $tenantName === 'My Organization') {
    try {
        $t = $db->fetchOne(
            "SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL",
            [$tenantId]
        );
        if ($t && !empty($t['tenant_name'])) {
            $tenantName = $t['tenant_name'];
            $_SESSION['tenant_name'] = $tenantName;
        }
    } catch (Exception $e) {
        // Fall back to the session value already read.
    }
}

$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userFirstName = $_SESSION['first_name'] ?? 'User';
$userAvatar = strtoupper(substr($userFirstName, 0, 1));
$pageTitle = 'Settings - ' . $currentTabDef['label'] . ' - EduTrack Tenant';
$currentPage = 'settings';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo h($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            overflow-x: hidden;
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

        .tenant-banner {
            background: #fff;
            border-radius: 14px;
            padding: 16px 24px;
            margin-bottom: 20px;
            border: 2px solid #4facfe;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .tenant-banner .tb-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .tenant-banner .tb-icon {
            font-size: 24px;
            color: #4facfe;
        }

        .tenant-banner .tb-name {
            font-weight: 600;
            font-size: 16px;
            color: #1a1a2e;
        }

        .tenant-banner .tb-sub {
            font-size: 12px;
            color: #6c757d;
            margin-top: 2px;
        }

        .tenant-banner .tb-badge {
            background: #e3f0ff;
            color: #0d6efd;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .tab-strip {
            background: #fff;
            border-radius: 14px;
            padding: 6px;
            margin-bottom: 20px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }

        .tab-strip a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 10px;
            color: #6c757d;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .tab-strip a:hover {
            background: #f0f2f5;
            color: #1a1a2e;
        }

        .tab-strip a.active {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(79, 172, 254, 0.3);
        }

        .tab-strip a i {
            font-size: 13px;
        }

        .tab-body {
            min-height: 300px;
        }

        .placeholder-card {
            background: #fff;
            border-radius: 14px;
            padding: 60px 40px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            text-align: center;
        }

        .placeholder-card .pc-icon {
            font-size: 56px;
            color: #dee2e6;
            display: block;
            margin-bottom: 20px;
        }

        .placeholder-card h4 {
            font-size: 18px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 8px;
        }

        .placeholder-card p {
            color: #6c757d;
            font-size: 14px;
            max-width: 480px;
            margin: 0 auto 16px;
        }

        .placeholder-card code {
            background: #f0f2f5;
            padding: 2px 8px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            color: #0d6efd;
        }

        .alert-pro {
            border-radius: 14px;
            border: none;
            padding: 16px 20px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
        }

        .alert-pro::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            width: 5px;
        }

        .alert-pro .ap-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .alert-pro .ap-body {
            flex: 1;
            padding-top: 1px;
        }

        .alert-pro .ap-title {
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 2px;
        }

        .alert-pro .ap-text {
            font-size: 13px;
        }

        .alert-pro.error {
            background: linear-gradient(135deg, #fff5f5 0%, #ffeaea 100%);
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .alert-pro.error::before {
            background: linear-gradient(180deg, #ef4444, #dc2626);
        }

        .alert-pro.error .ap-icon {
            background: rgba(239, 68, 68, 0.12);
            color: #dc2626;
        }

        .alert-pro.success {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .alert-pro.success::before {
            background: linear-gradient(180deg, #22c55e, #16a34a);
        }

        .alert-pro.success .ap-icon {
            background: rgba(34, 197, 94, 0.15);
            color: #16a34a;
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

            .main-content {
                margin-left: 72px;
                width: calc(100% - 72px);
                padding: 20px;
            }
        }

        @media (max-width: 768px) {
            .sidebar-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
                width: 280px;
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

            .tab-strip a {
                padding: 6px 12px;
                font-size: 12px;
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
                    <small><?php echo h($tenantName); ?></small>
                </div>
                <div class="nav">
                    <div class="nav-label">Main</div>
                    <a class="nav-link" href="/platform/tenant/dashboard.php"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a>

                    <div class="nav-label mt-3">Institution</div>
                    <a class="nav-link" href="/platform/tenant/schools/index.php"><i class="fas fa-school"></i> <span>Schools</span></a>
                    <a class="nav-link" href="/platform/tenant/campuses/index.php"><i class="fas fa-map-marker-alt"></i> <span>Campuses</span></a>

                    <div class="nav-label mt-3">Management</div>
                    <a class="nav-link" href="/platform/tenant/staff/index.php"><i class="fas fa-user-tie"></i> <span>Staff</span></a>
                    <a class="nav-link" href="/platform/tenant/students/index.php"><i class="fas fa-user-graduate"></i> <span>Students</span></a>

                    <div class="nav-label mt-3">Academic</div>
                    <a class="nav-link" href="/platform/tenant/academic/settings.php"><i class="fas fa-sliders-h"></i> <span>Academic Settings</span></a>
                    <a class="nav-link" href="/platform/tenant/academic/results.php"><i class="fas fa-poll"></i> <span>Results</span></a>
                    <a class="nav-link" href="/platform/tenant/academic/result-locks.php"><i class="fas fa-lock"></i> <span>Result Locks</span></a>

                    <div class="nav-label mt-3">System</div>
                    <a class="nav-link active" href="/platform/tenant/settings/index.php"><i class="fas fa-cog"></i> <span>Settings</span></a>
                    <a class="nav-link" href="/platform/logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
                </div>
                <div class="sidebar-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="user-info">
                            <div class="user-avatar" id="userAvatar"><?php echo h($userAvatar); ?></div>
                            <div>
                                <div class="user-name" id="userName"><?php echo h($currentUser); ?></div>
                                <div class="user-role" id="userRole">Tenant Administrator</div>
                            </div>
                        </div>
                        <button class="logout-btn" onclick="logout()" title="Logout"><i class="fas fa-sign-out-alt"></i></button>
                    </div>
                </div>
            </nav>

            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-cog me-2"></i>Settings</h1>
                        <p>Manage your organization settings</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/dashboard.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tb-info">
                        <i class="fas fa-building tb-icon"></i>
                        <div>
                            <div class="tb-name"><?php echo h($tenantName); ?></div>
                            <div class="tb-sub">
                                <i class="fas fa-user-shield me-1"></i>Tenant settings ·
                                <i class="fas fa-clock ms-2 me-1"></i><?php echo h(date('M j, Y H:i')); ?>
                            </div>
                        </div>
                    </div>
                    <span class="tb-badge">
                        <i class="fas fa-<?php echo h(str_replace('fas fa-', '', $currentTabDef['icon'])); ?> me-1"></i>
                        <?php echo h($currentTabDef['label']); ?>
                    </span>
                </div>

                <div class="tab-strip">
                    <?php foreach ($tabs as $key => $def): ?>
                        <a href="/platform/tenant/settings/index.php?tab=<?php echo h($key); ?>"
                            class="<?php echo $key === $currentTab ? 'active' : ''; ?>">
                            <i class="<?php echo h($def['icon']); ?>"></i>
                            <?php echo h($def['label']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="tab-body">
                    <?php
                    if ($tabBodyExists) {
                        // Include the tab body. The included file has access to
                        // $db, $tenantId, $currentUser, $userAvatar, and h().
                        include $tabBodyPath;
                    } else {
                    ?>
                        <div class="placeholder-card">
                            <i class="fas fa-tools pc-icon"></i>
                            <h4>Tab not yet implemented</h4>
                            <p>
                                The <strong><?php echo h($currentTabDef['label']); ?></strong> tab has not been built yet.
                                Expected file:
                                <code>public/platform/tenant/settings/<?php echo h($currentTab); ?>.php</code>
                            </p>
                            <p>
                                The shell, the sidebar, and the tab strip are already in place.
                                When the tab body is added, it will render inside this shell.
                            </p>
                            <a href="/platform/tenant/settings/index.php?tab=integrations" class="btn btn-primary mt-3">
                                <i class="fas fa-plug me-2"></i> Go to Integrations
                            </a>
                        </div>
                    <?php
                    }
                    ?>
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
    </script>
</body>

</html>