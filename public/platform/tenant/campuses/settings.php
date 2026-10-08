<?php

/**
 * Campus Settings - read-and-edit page for campus-level settings.
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Campuses
 * @version 1.0
 * @filepath public/platform/tenant/campuses/settings.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Tenth file of the tenant-surface sweep. Two changes:
 *     - The inline <nav class="sidebar" id="sidebar"> block is
 *       removed and replaced by an include of
 *       app/views/partials/sidebar.php. This file's inline
 *       sidebar carried eight items (it omitted Subscription);
 *       the partial carries all nine. After the refactor, this
 *       page renders the Subscription item as well.
 *     - The $pageTitle now carries the 'Student 360 Tenant'
 *       suffix, matching the other tenant-surface pages.
 *   Every other line of the file is byte-identical to the
 *   pre-sweep version. The @package tag remains 'EduTrack'.
 *
 * WHAT THIS PAGE DOES:
 * - Requires an authenticated tenant session.
 * - Reads campus_id from $_GET.
 * - Loads the campus row (tenant-scoped, deleted_at IS NULL).
 * - Loads the campus settings through SchoolSettingsService::getCampusSettings().
 * - Groups settings by their setting_group.
 * - Renders each setting as a row: key (read-only), value (editable),
 *   group, source (campus override or school default).
 * - Provides two POST actions:
 *     save_settings   - updates each changed key via setCampusSetting()
 *     remove_override - removes a single campus override (revert to school default)
 * - Uses CSRF via csrf_field()/verify_csrf() and h() for all echoed values.
 *
 * WHAT THIS PAGE DOES NOT DO:
 * - It does not manage the campus record itself (that is edit.php).
 * - It does not enumerate campus setting keys: the page renders whatever
 *   rows exist for the campus, grouped by their stored setting_group.
 * - It does not touch any table other than campuses, schools, and
 *   campus_settings_kv (through the service and model).
 *
 * ITEM 10 (from the carried-over list):
 *   Before this file, no consumer page existed for CampusSetting.php.
 *   The model and SchoolSettingsService were live, the API router
 *   dispatched get_campus/save_campus, but no UI read or wrote them.
 *   This file is that consumer page.
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
    header('Location: /platform/campuses/index.php');
    exit;
}

$tenantId = $_SESSION['tenant_id'] ?? 0;
if (!$tenantId) {
    header('Location: /platform/tenant/login.php?error=no_tenant');
    exit;
}

// =============================================
// BOOTSTRAP
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

require_once $projectRoot . '/app/services/Platform/SchoolSettingsService.php';
require_once $projectRoot . '/app/models/Platform/CampusSetting.php';

$db = DatabaseHelper::getInstance();

// =============================================
// LOCAL HELPERS
// =============================================

if (!function_exists('h')) {
    function h($s)
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field()
    {
        $token = $_SESSION['csrf_token'] ?? '';
        return '<input type="hidden" name="csrf_token" value="' . h($token) . '">';
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf()
    {
        $sent = $_POST['csrf_token'] ?? '';
        $stored = $_SESSION['csrf_token'] ?? '';
        if (!$sent || !$stored || !hash_equals($stored, $sent)) {
            http_response_code(403);
            die('Invalid CSRF token.');
        }
    }
}

// =============================================
// CAMPUS ID
// =============================================
$campusId = (int)($_GET['campus_id'] ?? 0);
if ($campusId <= 0) {
    $_SESSION['error'] = 'Campus not specified.';
    header('Location: /platform/tenant/campuses/index.php');
    exit;
}

// =============================================
// LOAD CAMPUS (tenant-scoped)
// =============================================
$campus = $db->fetchOne(
    "SELECT c.*, s.school_name, s.tenant_id AS school_tenant_id
       FROM campuses c
       JOIN schools s ON c.school_id = s.id
      WHERE c.id = ? AND c.tenant_id = ? AND c.deleted_at IS NULL",
    [$campusId, $tenantId]
);

if (!$campus) {
    $_SESSION['error'] = 'Campus not found.';
    header('Location: /platform/tenant/campuses/index.php');
    exit;
}

// =============================================
// POST ACTIONS
// =============================================
$errors = [];
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    try {
        $service = new SchoolSettingsService();
        $userId = (int)($_SESSION['user_id'] ?? 0);

        if ($action === 'remove_override') {
            $key = trim((string)($_POST['setting_key'] ?? ''));
            if ($key === '') {
                throw new Exception('Setting key is required.');
            }
            $result = $service->removeCampusOverride($campusId, $key, $userId);
            if (!empty($result['success'])) {
                $_SESSION['success'] = 'Override removed. The school default now applies.';
            } else {
                $_SESSION['error'] = $result['message'] ?? 'Failed to remove override.';
            }
            header('Location: /platform/tenant/campuses/settings.php?campus_id=' . $campusId);
            exit;
        }

        if ($action === 'save_settings') {
            $settings = $_POST['settings'] ?? [];
            if (!is_array($settings) || empty($settings)) {
                throw new Exception('No settings submitted.');
            }

            $updated = [];
            $failed = [];

            foreach ($settings as $key => $value) {
                $key = trim((string)$key);
                if ($key === '') {
                    continue;
                }
                $result = $service->setCampusSetting($campusId, $key, $value, $userId);
                if (!empty($result['success'])) {
                    $updated[] = $key;
                } else {
                    $failed[] = $key;
                }
            }

            if (empty($failed)) {
                $_SESSION['success'] = 'Settings saved. ' . count($updated) . ' value(s) updated.';
            } else {
                $_SESSION['success'] = 'Settings saved. ' . count($updated) . ' updated, ' . count($failed) . ' failed.';
            }
            header('Location: /platform/tenant/campuses/settings.php?campus_id=' . $campusId);
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: /platform/tenant/campuses/settings.php?campus_id=' . $campusId);
        exit;
    }
}

// =============================================
// FLASH
// =============================================
if (isset($_SESSION['error'])) {
    $errors[] = $_SESSION['error'];
    unset($_SESSION['error']);
}
if (isset($_SESSION['success'])) {
    $successMessage = $_SESSION['success'];
    unset($_SESSION['success']);
}

// =============================================
// LOAD SETTINGS
// =============================================
$settingsResult = null;
$settingsByGroup = [];
$categories = CampusSetting::CAMPUS_CATEGORIES;
$schoolId = (int)$campus['school_id'];

try {
    $service = new SchoolSettingsService();
    $settingsResult = $service->getCampusSettings($campusId);

    if (!empty($settingsResult['success']) && !empty($settingsResult['data']['settings'])) {
        foreach ($settingsResult['data']['settings'] as $row) {
            $group = $row['setting_group'] ?? 'general';
            if (!isset($settingsByGroup[$group])) {
                $settingsByGroup[$group] = [];
            }
            $settingsByGroup[$group][] = $row;
        }
    }
} catch (Exception $e) {
    $errors[] = 'Could not load settings: ' . $e->getMessage();
}

// =============================================
// CURRENT USER + TENANT
// =============================================
$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userFirstName = $_SESSION['first_name'] ?? 'User';
$userAvatar = strtoupper(substr($userFirstName, 0, 1));
$tenantName = $_SESSION['tenant_name'] ?? 'My Organization';

$pageTitle = 'Campus Settings - ' . $campus['campus_name'] . ' - Student 360 Tenant';
$currentPage = 'campuses';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="<?php echo h($_SESSION['csrf_token'] ?? ''); ?>">
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

        .campus-banner {
            background: #fff;
            border-radius: 14px;
            padding: 18px 24px;
            margin-bottom: 20px;
            border: 2px solid #4facfe;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .campus-banner .cb-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .campus-banner .cb-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .campus-banner .cb-name {
            font-weight: 600;
            font-size: 16px;
            color: #1a1a2e;
        }

        .campus-banner .cb-meta {
            font-size: 12px;
            color: #6c757d;
            margin-top: 2px;
        }

        .campus-banner .cb-badge {
            background: #e3f0ff;
            color: #0d6efd;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .alert-pro {
            border-radius: 14px;
            border: none;
            padding: 18px 20px 18px 22px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: flex-start;
            gap: 16px;
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
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .alert-pro .ap-body {
            flex: 1;
            padding-top: 2px;
        }

        .alert-pro .ap-title {
            font-weight: 700;
            font-size: 15px;
            margin-bottom: 4px;
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

        .settings-section {
            background: #fff;
            border-radius: 14px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .settings-section .ss-head {
            padding: 14px 20px;
            border-bottom: 1px solid #f0f2f5;
            background: linear-gradient(135deg, #f8fafc 0%, #eef6ff 100%);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .settings-section .ss-head h6 {
            font-size: 14px;
            font-weight: 700;
            margin: 0;
            color: #1a1a2e;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .settings-section .ss-head .ss-count {
            font-size: 12px;
            color: #6c757d;
        }

        .settings-section .ss-body {
            padding: 8px 20px 16px;
        }

        .setting-row {
            display: grid;
            grid-template-columns: minmax(180px, 1fr) minmax(240px, 2fr) auto;
            gap: 12px;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f0f2f5;
        }

        .setting-row:last-child {
            border-bottom: none;
        }

        .setting-row .sr-key {
            font-weight: 600;
            font-size: 13px;
            color: #1a1a2e;
            font-family: 'Courier New', monospace;
        }

        .setting-row .sr-meta {
            font-size: 11px;
            color: #6c757d;
            margin-top: 2px;
        }

        .setting-row .sr-input {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .setting-row .sr-input input,
        .setting-row .sr-input textarea {
            width: 100%;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 13px;
            height: 38px;
        }

        .setting-row .sr-input textarea {
            height: auto;
            min-height: 60px;
            resize: vertical;
        }

        .setting-row .sr-input input:focus,
        .setting-row .sr-input textarea:focus {
            border-color: #4facfe;
            outline: none;
            box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.1);
        }

        .setting-row .sr-actions {
            display: flex;
            gap: 6px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .pill.green {
            background: #d4edda;
            color: #155724;
        }

        .pill.blue {
            background: #cce5ff;
            color: #004085;
        }

        .pill.gray {
            background: #e9ecef;
            color: #495057;
        }

        .pill.purple {
            background: #e8d5f5;
            color: #6f42c1;
        }

        .pill.orange {
            background: #ffe8d9;
            color: #c2410c;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
            background: #fff;
            border-radius: 14px;
            border: 1px solid rgba(0, 0, 0, 0.03);
        }

        .empty-state i {
            font-size: 48px;
            opacity: 0.3;
            display: block;
            margin-bottom: 16px;
        }

        .empty-state h5 {
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 8px;
        }

        .sticky-save {
            position: sticky;
            bottom: 20px;
            z-index: 20;
            background: #fff;
            border-radius: 12px;
            padding: 14px 20px;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.08);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
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

            .setting-row {
                grid-template-columns: 1fr;
                gap: 6px;
            }

            .setting-row .sr-actions {
                justify-content: flex-start;
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

            <!-- Sidebar (includes app/views/partials/sidebar.php) -->
            <?php include $projectRoot . '/app/views/partials/sidebar.php'; ?>

            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-cog me-2"></i>Campus Settings</h1>
                        <p>Read and edit settings for <?php echo h($campus['campus_name']); ?></p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/campuses/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Campuses
                        </a>
                        <a href="/platform/tenant/campuses/edit.php?id=<?php echo (int)$campusId; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-edit me-2"></i> Edit Campus
                        </a>
                    </div>
                </div>

                <div class="campus-banner">
                    <div class="cb-info">
                        <div class="cb-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <div>
                            <div class="cb-name"><?php echo h($campus['campus_name']); ?></div>
                            <div class="cb-meta">
                                <?php if (!empty($campus['campus_code'])): ?>
                                    <i class="fas fa-tag"></i> <?php echo h($campus['campus_code']); ?> ·
                                <?php endif; ?>
                                <i class="fas fa-school"></i> <?php echo h($campus['school_name']); ?>
                                <?php if (!empty($campus['city'])): ?>
                                    · <i class="fas fa-city"></i> <?php echo h($campus['city']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <span class="cb-badge"><i class="fas fa-info-circle me-1"></i><?php echo count($settingsByGroup); ?> group(s)</span>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert-pro error">
                        <div class="ap-icon"><i class="fas fa-times-circle"></i></div>
                        <div class="ap-body">
                            <div class="ap-title">Could not complete the request</div>
                            <?php foreach ($errors as $err): ?>
                                <div class="ap-text"><?php echo h($err); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($successMessage): ?>
                    <div class="alert-pro success">
                        <div class="ap-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="ap-body">
                            <div class="ap-title">Success!</div>
                            <div class="ap-text"><?php echo h($successMessage); ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (empty($settingsByGroup)): ?>
                    <div class="empty-state">
                        <i class="fas fa-cog"></i>
                        <h5>No campus settings exist yet</h5>
                        <p>There are no rows in <code>campus_settings_kv</code> for this campus. The campus inherits every setting from its school.</p>
                        <a href="/platform/tenant/campuses/index.php" class="btn btn-primary mt-3"><i class="fas fa-arrow-left me-2"></i> Back to Campuses</a>
                    </div>
                <?php else: ?>
                    <form method="POST" action="/platform/tenant/campuses/settings.php?campus_id=<?php echo (int)$campusId; ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="save_settings">

                        <?php foreach ($settingsByGroup as $groupKey => $groupRows):
                            $groupLabel = $categories[$groupKey] ?? ucfirst($groupKey);
                        ?>
                            <div class="settings-section">
                                <div class="ss-head">
                                    <h6><i class="fas fa-folder me-1 text-primary"></i><?php echo h($groupLabel); ?></h6>
                                    <span class="ss-count"><?php echo count($groupRows); ?> setting(s)</span>
                                </div>
                                <div class="ss-body">
                                    <?php foreach ($groupRows as $row):
                                        $key = (string)($row['setting_key'] ?? '');
                                        $value = (string)($row['effective_value'] ?? $row['setting_value'] ?? '');
                                        $source = (string)($row['source'] ?? 'campus');
                                        $isEncrypted = !empty($row['is_encrypted']);
                                    ?>
                                        <div class="setting-row">
                                            <div>
                                                <div class="sr-key"><?php echo h($key); ?></div>
                                                <div class="sr-meta">
                                                    <?php if ($source === 'campus'): ?>
                                                        <span class="pill purple"><i class="fas fa-map-marker-alt"></i> Campus override</span>
                                                    <?php else: ?>
                                                        <span class="pill gray"><i class="fas fa-school"></i> School default</span>
                                                    <?php endif; ?>
                                                    <?php if ($isEncrypted): ?>
                                                        <span class="pill orange"><i class="fas fa-lock"></i> Encrypted</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="sr-input">
                                                <input type="text"
                                                    name="settings[<?php echo h($key); ?>]"
                                                    value="<?php echo h($value); ?>"
                                                    autocomplete="off">
                                            </div>
                                            <div class="sr-actions">
                                                <?php if ($source === 'campus'): ?>
                                                    <button type="button"
                                                        class="btn btn-outline-secondary btn-sm js-remove-override"
                                                        data-key="<?php echo h($key); ?>"
                                                        title="Remove campus override"
                                                        onclick="removeOverride('<?php echo h($key); ?>');">
                                                        <i class="fas fa-undo"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div class="sticky-save">
                            <a href="/platform/tenant/campuses/settings.php?campus_id=<?php echo (int)$campusId; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-1"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i> Save Settings
                            </button>
                        </div>
                    </form>

                    <form id="removeOverrideForm" method="POST" action="/platform/tenant/campuses/settings.php?campus_id=<?php echo (int)$campusId; ?>" style="display:none;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="remove_override">
                        <input type="hidden" name="setting_key" id="removeOverrideKey" value="">
                    </form>
                <?php endif; ?>
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

        function removeOverride(key) {
            if (!confirm('Remove the campus override for "' + key + '"?\n\nThe school default will apply after this action.')) {
                return;
            }
            document.getElementById('removeOverrideKey').value = key;
            document.getElementById('removeOverrideForm').submit();
        }
    </script>
</body>

</html>