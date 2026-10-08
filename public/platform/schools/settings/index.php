<?php

/**
 * School Settings - Simplified version
 * @package EduTrack
 * @subpackage Platform\Schools\Settings
 * @version 2.0
 * @filepath public/platform/schools/settings/index.php
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

// Get school ID from URL
$schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

// If no school ID, redirect to schools list
if (!$schoolId) {
    header('Location: /platform/schools/index.php?error=no_school_selected');
    exit;
}

// Get tenant ID from session
$tenantId = $_SESSION['tenant_id'] ?? 0;

// Load Database Helper
$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// Get school details
$school = $db->fetchOne(
    "SELECT s.*, t.tenant_name 
     FROM schools s 
     LEFT JOIN tenants t ON s.tenant_id = t.id 
     WHERE s.id = ? AND s.deleted_at IS NULL",
    [$schoolId]
);

// If school not found, redirect
if (!$school) {
    header('Location: /platform/schools/index.php?error=school_not_found');
    exit;
}

// Get active section
$activeSection = isset($_GET['section']) ? $_GET['section'] : 'general';

// Load school settings from database
$settings = [];
$settingsResult = $db->fetchAll(
    "SELECT setting_key, setting_value FROM school_settings WHERE school_id = ? AND deleted_at IS NULL",
    [$schoolId]
);
foreach ($settingsResult as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $userId = $_SESSION['user_id'] ?? 0;
    $action = $_POST['action'];

    if ($action === 'save_settings') {
        $settingData = [];
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'setting_') === 0) {
                $settingKey = substr($key, 8);
                if ($value === 'on') {
                    $value = 1;
                }
                $settingData[$settingKey] = $value;
            }
        }

        if (!empty($settingData)) {
            try {
                $db->beginTransaction();

                foreach ($settingData as $key => $value) {
                    // Check if setting exists
                    $exists = $db->getValue(
                        "SELECT COUNT(*) FROM school_settings WHERE school_id = ? AND setting_key = ? AND deleted_at IS NULL",
                        [$schoolId, $key]
                    );

                    if ($exists) {
                        $db->execute(
                            "UPDATE school_settings SET setting_value = ?, updated_at = NOW() WHERE school_id = ? AND setting_key = ?",
                            [$value, $schoolId, $key]
                        );
                    } else {
                        $db->execute(
                            "INSERT INTO school_settings (school_id, setting_key, setting_value, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())",
                            [$schoolId, $key, $value]
                        );
                    }
                }

                $db->commit();
                $message = 'Settings saved successfully!';
                $messageType = 'success';

                // Reload settings
                $settingsResult = $db->fetchAll(
                    "SELECT setting_key, setting_value FROM school_settings WHERE school_id = ? AND deleted_at IS NULL",
                    [$schoolId]
                );
                $settings = [];
                foreach ($settingsResult as $row) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Exception $e) {
                $db->rollBack();
                $message = 'Error saving settings: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'reset_settings') {
        try {
            $db->execute(
                "DELETE FROM school_settings WHERE school_id = ?",
                [$schoolId]
            );
            $message = 'Settings reset to defaults!';
            $messageType = 'success';
            $settings = [];
        } catch (Exception $e) {
            $message = 'Error resetting settings: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

$pageTitle = 'School Settings - ' . htmlspecialchars($school['school_name'] ?? 'School');
$currentPage = 'schools';

// Determine API base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$apiBase = $protocol . '://' . $host . '/api/platform';

// Sections definition
$sections = [
    'general' => ['label' => 'General', 'icon' => 'fa-sliders-h'],
    'academic' => ['label' => 'Academic', 'icon' => 'fa-graduation-cap'],
    'branding' => ['label' => 'Branding', 'icon' => 'fa-paint-brush'],
    'portal' => ['label' => 'Portal', 'icon' => 'fa-users'],
    'security' => ['label' => 'Security', 'icon' => 'fa-shield-alt'],
    'finance' => ['label' => 'Finance', 'icon' => 'fa-coins'],
];

// Settings definitions
$settingDefinitions = [
    'general' => [
        'school_name' => ['label' => 'School Name', 'type' => 'text', 'help' => 'Official name of the school'],
        'school_motto' => ['label' => 'School Motto', 'type' => 'text', 'help' => 'School motto or tagline'],
        'timezone' => ['label' => 'Default Timezone', 'type' => 'select', 'options' => ['UTC', 'Africa/Accra', 'Africa/Lagos', 'Africa/Nairobi']],
        'date_format' => ['label' => 'Date Format', 'type' => 'select', 'options' => ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd M Y']],
        'language' => ['label' => 'Default Language', 'type' => 'select', 'options' => ['en', 'fr', 'es', 'pt', 'ar']],
    ],
    'branding' => [
        'primary_color' => ['label' => 'Primary Color', 'type' => 'color', 'help' => 'Primary brand color (hex)'],
        'secondary_color' => ['label' => 'Secondary Color', 'type' => 'color', 'help' => 'Secondary brand color (hex)'],
        'accent_color' => ['label' => 'Accent Color', 'type' => 'color', 'help' => 'Accent brand color (hex)'],
    ],
    'academic' => [
        'term_structure' => ['label' => 'Term Structure', 'type' => 'select', 'options' => ['semester', 'trimester', 'quarter']],
        'grading_system' => ['label' => 'Grading System', 'type' => 'select', 'options' => ['percentage', 'letter', 'gpa']],
        'max_subjects_per_student' => ['label' => 'Max Subjects per Student', 'type' => 'number'],
        'allow_self_enrollment' => ['label' => 'Allow Self Enrollment', 'type' => 'checkbox'],
    ],
    'portal' => [
        'portal_enabled' => ['label' => 'Student Portal Enabled', 'type' => 'checkbox'],
        'portal_results_visibility' => ['label' => 'Results Visibility', 'type' => 'select', 'options' => ['all', 'current_term', 'current_year', 'none']],
        'portal_announcements' => ['label' => 'Enable Portal Announcements', 'type' => 'checkbox'],
    ],
    'security' => [
        'security_two_factor_auth' => ['label' => 'Two-Factor Authentication', 'type' => 'checkbox'],
        'security_audit_logging' => ['label' => 'Audit Logging Enabled', 'type' => 'checkbox'],
        'session_timeout' => ['label' => 'Session Timeout (minutes)', 'type' => 'number'],
    ],
    'finance' => [
        'finance_default_currency' => ['label' => 'Default Currency', 'type' => 'select', 'options' => ['GHS', 'USD', 'EUR', 'GBP', 'NGN', 'KES']],
        'finance_tax_rate' => ['label' => 'Tax Rate (%)', 'type' => 'number'],
        'finance_invoice_prefix' => ['label' => 'Invoice Prefix', 'type' => 'text'],
    ],
];

$currentSettings = $settingDefinitions[$activeSection] ?? [];

$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userAvatar = substr($currentUser, 0, 1);
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

        .btn-danger {
            background: #dc3545;
            border: none;
            color: #fff;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4);
            color: #fff;
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

        .form-text {
            font-size: 11px;
            color: #6c757d;
            margin-top: 4px;
        }

        .settings-container {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }

        .settings-sidebar {
            width: 220px;
            flex-shrink: 0;
        }

        .settings-content {
            flex: 1;
            min-width: 0;
        }

        .settings-nav {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            overflow: hidden;
        }

        .settings-nav .nav-item {
            border-bottom: 1px solid #f0f2f5;
        }

        .settings-nav .nav-item:last-child {
            border-bottom: none;
        }

        .settings-nav .nav-link {
            padding: 12px 16px;
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

        .settings-nav .nav-link:hover {
            background: #f8f9fa;
            color: #1a1a2e;
        }

        .settings-nav .nav-link.active {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: #fff;
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.3);
        }

        .settings-nav .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 14px;
        }

        .setting-item {
            display: flex;
            align-items: flex-start;
            padding: 12px 0;
            border-bottom: 1px solid #f8f9fa;
            gap: 16px;
        }

        .setting-item:last-child {
            border-bottom: none;
        }

        .setting-item .setting-label {
            flex: 0 0 180px;
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            padding-top: 4px;
        }

        .setting-item .setting-label small {
            font-weight: 400;
            color: #6c757d;
            font-size: 11px;
            display: block;
            margin-top: 2px;
        }

        .setting-item .setting-control {
            flex: 1;
            min-width: 0;
        }

        .setting-item .setting-control .form-control,
        .setting-item .setting-control .form-select {
            max-width: 400px;
        }

        .setting-item .setting-control .form-control.color-input {
            width: 60px;
            padding: 4px;
            height: 44px;
        }

        .setting-item .setting-status {
            flex: 0 0 80px;
            text-align: right;
            font-size: 12px;
            padding-top: 4px;
        }

        .setting-item .setting-status .badge {
            font-size: 10px;
            padding: 3px 10px;
        }

        .setting-item .setting-status .badge.bg-success {
            background: #2ecc71 !important;
        }

        .setting-item .setting-status .badge.bg-secondary {
            background: #95a5a6 !important;
        }

        .save-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            padding-top: 16px;
            border-top: 2px solid #f0f2f5;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .save-actions .btn {
            border-radius: 10px;
            padding: 10px 28px;
            font-weight: 600;
            font-size: 13px;
        }

        .school-info-bar {
            background: #fff;
            border-radius: 14px;
            padding: 16px 24px;
            margin-bottom: 24px;
            border: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .school-info-bar .school-details {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .school-info-bar .school-details .school-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 20px;
            flex-shrink: 0;
        }

        .school-info-bar .school-details .school-name {
            font-weight: 600;
            font-size: 18px;
            color: #1a1a2e;
        }

        .school-info-bar .school-details .school-code {
            font-size: 12px;
            color: #6c757d;
            background: #f0f2f5;
            padding: 2px 12px;
            border-radius: 20px;
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

            .settings-sidebar {
                width: 100%;
            }

            .settings-container {
                flex-direction: column;
            }

            .settings-nav {
                display: flex;
                flex-wrap: wrap;
            }

            .settings-nav .nav-item {
                border-bottom: none;
                border-right: 1px solid #f0f2f5;
            }

            .settings-nav .nav-link {
                padding: 10px 14px;
                font-size: 12px;
            }

            .setting-item .setting-label {
                flex: 0 0 140px;
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

            .settings-nav .nav-link {
                font-size: 12px;
                padding: 8px 12px;
            }

            .school-info-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }

            .setting-item {
                flex-direction: column;
                gap: 8px;
                padding: 12px 0;
            }

            .setting-item .setting-label {
                flex: auto;
                width: 100%;
            }

            .setting-item .setting-control .form-control,
            .setting-item .setting-control .form-select {
                max-width: 100%;
            }

            .setting-item .setting-status {
                flex: auto;
                width: 100%;
                text-align: left;
            }

            .save-actions {
                flex-direction: column;
            }

            .save-actions .btn {
                width: 100%;
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

            .card-custom .card-header-custom {
                padding: 12px 16px;
            }

            .card-custom .card-body-custom {
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
                    <a class="nav-link active" href="/platform/schools/index.php"><i class="fas fa-school"></i> <span>Schools</span></a>
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
                        <h1><i class="fas fa-cog me-2"></i>School Settings</h1>
                        <p>Configure settings for <?php echo htmlspecialchars($school['school_name'] ?? 'School'); ?></p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/schools/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Schools
                        </a>
                        <a href="/platform/schools/view.php?id=<?php echo $schoolId; ?>" class="btn btn-outline-info">
                            <i class="fas fa-eye me-2"></i> View School
                        </a>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer">
                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" style="border-radius:12px;border:none;box-shadow:0 4px 20px rgba(0,0,0,0.06);">
                            <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> me-2"></i>
                            <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- School Info Bar -->
                <div class="school-info-bar">
                    <div class="school-details">
                        <div class="school-icon"><i class="fas fa-school"></i></div>
                        <div>
                            <div class="school-name"><?php echo htmlspecialchars($school['school_name'] ?? 'School'); ?></div>
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <span class="school-code">ID: <?php echo $schoolId; ?></span>
                                <span class="school-code">Code: <?php echo htmlspecialchars($school['school_code'] ?? 'N/A'); ?></span>
                                <span class="school-code">Tenant: <?php echo htmlspecialchars($school['tenant_name'] ?? $tenantId); ?></span>
                            </div>
                        </div>
                    </div>
                    <span class="badge <?php echo ($school['status'] ?? 'active') === 'active' ? 'bg-success' : 'bg-warning'; ?>">
                        <?php echo ucfirst($school['status'] ?? 'Active'); ?>
                    </span>
                </div>

                <!-- Settings Container -->
                <div class="settings-container">
                    <!-- Sidebar Navigation -->
                    <div class="settings-sidebar">
                        <nav class="settings-nav">
                            <?php foreach ($sections as $key => $section): ?>
                                <div class="nav-item">
                                    <a class="nav-link <?php echo $activeSection == $key ? 'active' : ''; ?>"
                                        href="?school_id=<?php echo $schoolId; ?>&section=<?php echo $key; ?>">
                                        <i class="fas <?php echo $section['icon']; ?>"></i>
                                        <?php echo $section['label']; ?>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </nav>
                    </div>

                    <!-- Settings Content -->
                    <div class="settings-content">
                        <div class="card-custom">
                            <div class="card-header-custom">
                                <h6>
                                    <i class="fas <?php echo $sections[$activeSection]['icon'] ?? 'fa-cog'; ?> me-2"></i>
                                    <?php echo $sections[$activeSection]['label'] ?? 'Settings'; ?>
                                </h6>
                                <span class="badge bg-primary"><?php echo count($currentSettings); ?> settings</span>
                            </div>
                            <div class="card-body-custom">
                                <form method="POST" action="?school_id=<?php echo $schoolId; ?>&section=<?php echo $activeSection; ?>">
                                    <input type="hidden" name="action" value="save_settings">

                                    <?php if (empty($currentSettings)): ?>
                                        <div style="text-align:center; padding:40px 20px; color:#6c757d;">
                                            <i class="fas fa-cog" style="font-size:48px; opacity:0.3; display:block; margin-bottom:16px;"></i>
                                            <h5 style="font-weight:600; color:#1a1a2e;">No Settings Available</h5>
                                            <p>No settings available in this section.</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($currentSettings as $key => $def): ?>
                                            <?php
                                            $value = $settings[$key] ?? '';
                                            $hasValue = $value !== '' && $value !== null && $value !== false;
                                            $fieldName = 'setting_' . $key;
                                            ?>
                                            <div class="setting-item">
                                                <div class="setting-label">
                                                    <?php echo $def['label']; ?>
                                                    <small><?php echo $def['help'] ?? ''; ?></small>
                                                </div>
                                                <div class="setting-control">
                                                    <?php
                                                    switch ($def['type']):
                                                        case 'textarea':
                                                            echo '<textarea class="form-control" name="' . $fieldName . '" rows="3">' . htmlspecialchars($value) . '</textarea>';
                                                            break;
                                                        case 'select':
                                                            echo '<select class="form-select" name="' . $fieldName . '">';
                                                            foreach ($def['options'] as $option) {
                                                                $selected = $value == $option ? 'selected' : '';
                                                                echo '<option value="' . htmlspecialchars($option) . '" ' . $selected . '>' . htmlspecialchars($option) . '</option>';
                                                            }
                                                            echo '</select>';
                                                            break;
                                                        case 'checkbox':
                                                            $checked = $value ? 'checked' : '';
                                                            echo '<div class="form-check"><input class="form-check-input" type="checkbox" name="' . $fieldName . '" ' . $checked . ' value="on"></div>';
                                                            break;
                                                        case 'color':
                                                            echo '<input type="color" class="form-control color-input" name="' . $fieldName . '" value="' . htmlspecialchars($value ?: '#4facfe') . '">';
                                                            break;
                                                        case 'number':
                                                            echo '<input type="number" class="form-control" name="' . $fieldName . '" value="' . htmlspecialchars($value) . '" step="any">';
                                                            break;
                                                        case 'text':
                                                        default:
                                                            echo '<input type="text" class="form-control" name="' . $fieldName . '" value="' . htmlspecialchars($value) . '">';
                                                            break;
                                                    endswitch;
                                                    ?>
                                                </div>
                                                <div class="setting-status">
                                                    <span class="badge <?php echo $hasValue ? 'bg-success' : 'bg-secondary'; ?>">
                                                        <?php echo $hasValue ? 'Configured' : 'Not Set'; ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>

                                        <div class="save-actions">
                                            <button type="submit" name="action" value="reset_settings" class="btn btn-danger" onclick="return confirm('Are you sure you want to reset ALL settings to defaults?')">
                                                <i class="fas fa-undo me-2"></i> Reset to Defaults
                                            </button>
                                            <button type="submit" name="action" value="save_settings" class="btn btn-primary">
                                                <i class="fas fa-save me-2"></i> Save All Settings
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </div>
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
                localStorage.removeItem('token');
                localStorage.removeItem('user');
                window.location.href = '<?php echo $protocol; ?>://<?php echo $host; ?>/platform/login.php';
            }
        }

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

        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();
        });
    </script>
</body>

</html>