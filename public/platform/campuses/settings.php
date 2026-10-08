<?php

/**
 * Campus Settings - Super Admin
 * Configure campus-specific settings
 *
 * @package EduTrack
 * @subpackage Platform\Campuses
 * @filepath public/platform/campuses/settings.php
 * @version 2.0
 */

$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/config/config.php';

session_start();

// Check authentication and super admin role
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/tenant/login.php');
    exit;
}

$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;
if (!$isSuperAdmin) {
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

$campusId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($campusId <= 0) {
    $_SESSION['error'] = 'Invalid campus ID.';
    header('Location: /platform/campuses/index.php');
    exit;
}

$pageTitle = 'Campus Settings - Super Admin';
$currentPage = 'campuses';

$userName = $_SESSION['user_name'] ?? 'Super Admin';
$userInitial = strtoupper(substr($_SESSION['first_name'] ?? 'S', 0, 1));

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// Get campus details with tenant and school info
$campus = $db->fetchOne(
    "SELECT c.*, s.school_name, t.tenant_name 
     FROM campuses c 
     LEFT JOIN schools s ON c.school_id = s.id 
     LEFT JOIN tenants t ON s.tenant_id = t.id 
     WHERE c.id = ? AND c.deleted_at IS NULL",
    [$campusId]
);

if (!$campus) {
    $_SESSION['error'] = 'Campus not found.';
    header('Location: /platform/campuses/index.php');
    exit;
}

// Check if campus_settings table exists - create if not
$tableExists = $db->getValue(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'campus_settings'",
    [DB_NAME]
);

if (!$tableExists) {
    $db->execute("
        CREATE TABLE IF NOT EXISTS campus_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            campus_id INT NOT NULL UNIQUE,
            campus_phone VARCHAR(20),
            campus_email VARCHAR(100),
            campus_website VARCHAR(255),
            principal_name VARCHAR(100),
            vice_principal_name VARCHAR(100),
            school_hours_start TIME,
            school_hours_end TIME,
            timezone VARCHAR(50) DEFAULT 'Africa/Accra',
            language VARCHAR(20) DEFAULT 'en',
            currency VARCHAR(10) DEFAULT 'GHS',
            academic_year_start DATE,
            academic_year_end DATE,
            term_system ENUM('Term', 'Semester', 'Quarter') DEFAULT 'Term',
            terms_per_year INT DEFAULT 3,
            max_students_per_class INT DEFAULT 40,
            use_guardian_portal TINYINT(1) DEFAULT 1,
            enable_online_registration TINYINT(1) DEFAULT 1,
            enable_parent_app TINYINT(1) DEFAULT 1,
            google_maps_api_key VARCHAR(255),
            gps_coordinates VARCHAR(100),
            logo_url VARCHAR(255),
            favicon_url VARCHAR(255),
            header_color VARCHAR(7) DEFAULT '#1a1a2e',
            footer_color VARCHAR(7) DEFAULT '#1a1a2e',
            accent_color VARCHAR(7) DEFAULT '#4facfe',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "<!-- Campus settings table created -->";
}

// Get or create campus settings
$settings = $db->fetchOne(
    "SELECT * FROM campus_settings WHERE campus_id = ?",
    [$campusId]
);

if (!$settings) {
    // Create default settings
    $db->insert(
        "INSERT INTO campus_settings (campus_id, timezone, language, currency, term_system, terms_per_year, max_students_per_class) 
         VALUES (?, 'Africa/Accra', 'en', 'GHS', 'Term', 3, 40)",
        [$campusId]
    );
    $settings = $db->fetchOne(
        "SELECT * FROM campus_settings WHERE campus_id = ?",
        [$campusId]
    );
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // General Settings
    $campusPhone = trim($_POST['campus_phone'] ?? '');
    $campusEmail = trim($_POST['campus_email'] ?? '');
    $campusWebsite = trim($_POST['campus_website'] ?? '');
    $principalName = trim($_POST['principal_name'] ?? '');
    $vicePrincipalName = trim($_POST['vice_principal_name'] ?? '');
    $schoolHoursStart = trim($_POST['school_hours_start'] ?? '');
    $schoolHoursEnd = trim($_POST['school_hours_end'] ?? '');
    $timezone = trim($_POST['timezone'] ?? 'Africa/Accra');
    $language = trim($_POST['language'] ?? 'en');
    $currency = trim($_POST['currency'] ?? 'GHS');

    // Academic Settings
    $academicYearStart = trim($_POST['academic_year_start'] ?? '');
    $academicYearEnd = trim($_POST['academic_year_end'] ?? '');
    $termSystem = trim($_POST['term_system'] ?? 'Term');
    $termsPerYear = (int)($_POST['terms_per_year'] ?? 3);
    $maxStudentsPerClass = (int)($_POST['max_students_per_class'] ?? 40);

    // Feature Toggles
    $useGuardianPortal = isset($_POST['use_guardian_portal']) ? 1 : 0;
    $enableOnlineRegistration = isset($_POST['enable_online_registration']) ? 1 : 0;
    $enableParentApp = isset($_POST['enable_parent_app']) ? 1 : 0;

    // Branding
    $googleMapsApiKey = trim($_POST['google_maps_api_key'] ?? '');
    $gpsCoordinates = trim($_POST['gps_coordinates'] ?? '');
    $logoUrl = trim($_POST['logo_url'] ?? '');
    $faviconUrl = trim($_POST['favicon_url'] ?? '');
    $headerColor = trim($_POST['header_color'] ?? '#1a1a2e');
    $footerColor = trim($_POST['footer_color'] ?? '#1a1a2e');
    $accentColor = trim($_POST['accent_color'] ?? '#4facfe');

    try {
        $db->execute(
            "UPDATE campus_settings SET 
                campus_phone = ?,
                campus_email = ?,
                campus_website = ?,
                principal_name = ?,
                vice_principal_name = ?,
                school_hours_start = ?,
                school_hours_end = ?,
                timezone = ?,
                language = ?,
                currency = ?,
                academic_year_start = ?,
                academic_year_end = ?,
                term_system = ?,
                terms_per_year = ?,
                max_students_per_class = ?,
                use_guardian_portal = ?,
                enable_online_registration = ?,
                enable_parent_app = ?,
                google_maps_api_key = ?,
                gps_coordinates = ?,
                logo_url = ?,
                favicon_url = ?,
                header_color = ?,
                footer_color = ?,
                accent_color = ?,
                updated_at = NOW()
             WHERE campus_id = ?",
            [
                $campusPhone,
                $campusEmail,
                $campusWebsite,
                $principalName,
                $vicePrincipalName,
                $schoolHoursStart,
                $schoolHoursEnd,
                $timezone,
                $language,
                $currency,
                $academicYearStart,
                $academicYearEnd,
                $termSystem,
                $termsPerYear,
                $maxStudentsPerClass,
                $useGuardianPortal,
                $enableOnlineRegistration,
                $enableParentApp,
                $googleMapsApiKey,
                $gpsCoordinates,
                $logoUrl,
                $faviconUrl,
                $headerColor,
                $footerColor,
                $accentColor,
                $campusId
            ]
        );

        $_SESSION['success'] = 'Campus settings updated successfully!';

        // Refresh settings
        $settings = $db->fetchOne(
            "SELECT * FROM campus_settings WHERE campus_id = ?",
            [$campusId]
        );

        $success = 'Settings saved successfully!';
    } catch (Exception $e) {
        $error = 'Error saving settings: ' . $e->getMessage();
    }
}

// Timezones
$timezones = [
    'Africa/Accra' => 'GMT (Accra)',
    'Africa/Lagos' => 'WAT (Lagos)',
    'Africa/Johannesburg' => 'SAST (Johannesburg)',
    'Africa/Nairobi' => 'EAT (Nairobi)',
    'Africa/Cairo' => 'EET (Cairo)',
    'Europe/London' => 'GMT (London)',
    'Europe/Paris' => 'CET (Paris)',
    'America/New_York' => 'EST (New York)',
    'America/Los_Angeles' => 'PST (Los Angeles)',
    'Asia/Dubai' => 'GST (Dubai)',
    'Asia/Singapore' => 'SGT (Singapore)',
    'Australia/Sydney' => 'AEST (Sydney)',
];

// Currencies
$currencies = [
    'GHS' => 'Ghana Cedis (GHS)',
    'NGN' => 'Nigerian Naira (NGN)',
    'KES' => 'Kenyan Shilling (KES)',
    'ZAR' => 'South African Rand (ZAR)',
    'EGP' => 'Egyptian Pound (EGP)',
    'USD' => 'US Dollar (USD)',
    'EUR' => 'Euro (EUR)',
    'GBP' => 'British Pound (GBP)',
];

// Languages
$languages = [
    'en' => 'English',
    'fr' => 'French',
    'es' => 'Spanish',
    'pt' => 'Portuguese',
    'ar' => 'Arabic',
    'sw' => 'Swahili',
    'ha' => 'Hausa',
    'yo' => 'Yoruba',
    'ig' => 'Igbo',
    'tw' => 'Twi',
];

// Term Systems
$termSystems = ['Term', 'Semester', 'Quarter'];

$statusBadge = [
    'active' => 'active',
    'inactive' => 'inactive'
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Include Color Picker -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/spectrum/1.8.1/spectrum.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #f0f2f5;
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
            padding-left: 8px;
            padding-right: 8px;
        }

        .sidebar {
            background: #1a1a2e !important;
            min-height: 100vh;
            position: fixed;
            width: 230px;
            left: 0;
            top: 0;
            z-index: 1000;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        .sidebar .sidebar-header {
            padding: 20px 18px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .sidebar .sidebar-header h4 {
            font-weight: 700;
            font-size: 18px;
            color: #fff;
            margin: 0;
        }

        .sidebar .sidebar-header h4 i {
            color: #4facfe;
        }

        .sidebar .sidebar-header small {
            color: rgba(255, 255, 255, 0.4);
            font-size: 11px;
        }

        .sidebar .nav {
            padding: 12px 10px;
        }

        .sidebar .nav-label {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.3);
            padding: 0 10px 6px;
            font-weight: 600;
            margin-top: 6px;
        }

        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.6);
            padding: 8px 14px;
            border-radius: 8px;
            margin: 1px 0;
            transition: all 0.3s;
            font-size: 13px;
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
            width: 20px;
            text-align: center;
            margin-right: 10px;
            font-size: 14px;
            flex-shrink: 0;
        }

        .sidebar .nav-link span {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar .sidebar-footer {
            position: absolute;
            bottom: 0;
            width: 100%;
            padding: 14px 18px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(0, 0, 0, 0.2);
        }

        .sidebar .sidebar-footer .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .sidebar .sidebar-footer .user-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 13px;
            color: #fff;
            flex-shrink: 0;
        }

        .sidebar .sidebar-footer .user-name {
            font-weight: 600;
            font-size: 13px;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar .sidebar-footer .user-role {
            font-size: 10px;
            color: rgba(255, 255, 255, 0.4);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar .sidebar-footer .logout-btn {
            color: rgba(255, 255, 255, 0.4);
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            font-size: 14px;
            flex-shrink: 0;
        }

        .sidebar .sidebar-footer .logout-btn:hover {
            color: #ff6b6b;
        }

        .main-content {
            margin-left: 230px;
            min-height: 100vh;
            width: calc(100% - 230px);
            max-width: 100%;
            overflow-x: hidden;
        }

        .topbar {
            background: #fff;
            padding: 10px 20px;
            border-bottom: 1px solid #e9ecef;
            position: sticky;
            top: 0;
            z-index: 999;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .topbar h4 {
            font-weight: 600;
            color: #1a1a2e;
            margin: 0;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .topbar h4 i {
            color: #4facfe;
        }

        .topbar .topbar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .topbar .topbar-actions .btn {
            font-size: 12px;
            padding: 5px 12px;
            white-space: nowrap;
        }

        .content-area {
            padding: 16px 20px 30px;
            max-width: 100%;
            overflow-x: hidden;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .page-header h2 {
            font-weight: 700;
            color: #1a1a2e;
            margin: 0;
        }

        .card-custom {
            background: #fff;
            border: none;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(0, 0, 0, 0.03);
            overflow: hidden;
            width: 100%;
            margin-bottom: 20px;
        }

        .card-custom .card-header-custom {
            padding: 14px 20px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            background: #f8f9fa;
        }

        .card-custom .card-header-custom h6 {
            font-weight: 600;
            margin: 0;
            font-size: 14px;
            color: #1a1a2e;
        }

        .card-custom .card-body-custom {
            padding: 20px;
        }

        .form-label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            margin-bottom: 4px;
            display: block;
        }

        .form-control,
        .form-select {
            border-radius: 8px;
            padding: 8px 12px;
            border: 1.5px solid #e9ecef;
            font-size: 13px;
            width: 100%;
            display: block;
            background: #fff;
            color: #1a1a2e;
            transition: all 0.3s;
            height: 40px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .form-control-sm {
            height: 36px;
            font-size: 12px;
        }

        .form-check-input:checked {
            background-color: #4facfe;
            border-color: #4facfe;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border: none;
            color: #fff;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.4);
            color: #fff;
        }

        .btn-outline-secondary {
            background: transparent;
            border: 1.5px solid #e9ecef;
            color: #6c757d;
        }

        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: #ced4da;
        }

        .alert {
            border-radius: 10px;
            border: none;
            font-size: 13px;
        }

        .settings-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .color-picker-wrapper {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .color-preview {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            border: 2px solid #e9ecef;
            flex-shrink: 0;
        }

        .color-picker-input {
            width: 80px;
            padding: 4px;
            border: 1px solid #e9ecef;
            border-radius: 6px;
        }

        .status-badge {
            font-size: 10px;
            padding: 2px 10px;
            border-radius: 20px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }

        .status-badge.active {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .sidebar-toggle {
            display: none;
            position: fixed;
            top: 8px;
            left: 8px;
            z-index: 1001;
            background: #1a1a2e;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 18px;
            cursor: pointer;
        }

        .campus-info-banner {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 20px;
            border-left: 4px solid #4facfe;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .campus-info-banner .campus-name {
            font-weight: 600;
            font-size: 16px;
            color: #1a1a2e;
        }

        .campus-info-banner .campus-meta {
            font-size: 13px;
            color: #6c757d;
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 60px;
                overflow: hidden;
            }

            .sidebar .sidebar-header h4 {
                font-size: 0;
            }

            .sidebar .sidebar-header h4 i {
                font-size: 20px;
            }

            .sidebar .sidebar-header small {
                display: none;
            }

            .sidebar .nav-link span {
                display: none;
            }

            .sidebar .nav-link i {
                margin-right: 0;
                font-size: 16px;
            }

            .sidebar .nav-link {
                text-align: center;
                padding: 10px;
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
                margin-left: 60px;
                width: calc(100% - 60px);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 260px;
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
                font-size: 18px;
            }

            .sidebar .sidebar-header small {
                display: block;
            }

            .sidebar .nav-link span {
                display: inline;
            }

            .sidebar .nav-link i {
                margin-right: 10px;
                font-size: 14px;
            }

            .sidebar .nav-link {
                text-align: left;
                padding: 8px 14px;
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
            }

            .topbar {
                padding: 8px 14px;
            }

            .topbar h4 {
                font-size: 16px;
            }

            .content-area {
                padding: 10px 12px;
            }

            .sidebar-toggle {
                display: block !important;
            }

            .settings-section {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .topbar {
                flex-direction: column;
                align-items: stretch;
                gap: 6px;
            }

            .topbar .topbar-actions {
                justify-content: flex-start;
            }

            .topbar .topbar-actions .btn {
                font-size: 11px;
                padding: 4px 10px;
            }

            .content-area {
                padding: 8px 8px;
            }

            .card-custom .card-body-custom {
                padding: 12px;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>

            <!-- Sidebar -->
            <nav class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <h4><i class="fas fa-graduation-cap me-2"></i>EduTrack</h4>
                    <small>Super Admin</small>
                </div>
                <div class="nav">
                    <div class="nav-label">Main</div>
                    <a class="nav-link" href="/platform/index.php"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
                    <div class="nav-label mt-2">Management</div>
                    <a class="nav-link" href="/platform/tenants/index.php"><i class="fas fa-building"></i><span>Tenants</span></a>
                    <a class="nav-link" href="/platform/schools/index.php"><i class="fas fa-school"></i><span>Schools</span></a>
                    <a class="nav-link active" href="/platform/campuses/index.php"><i class="fas fa-map-marker-alt"></i><span>Campuses</span></a>
                    <div class="nav-label mt-2">Users</div>
                    <a class="nav-link" href="/platform/users/index.php"><i class="fas fa-users"></i><span>Users</span></a>
                    <div class="nav-label mt-2">System</div>
                    <a class="nav-link" href="/platform/audit/index.php"><i class="fas fa-history"></i><span>Audit</span></a>
                    <a class="nav-link" href="/platform/settings/index.php"><i class="fas fa-cog"></i><span>Settings</span></a>
                </div>
                <div class="sidebar-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="user-info">
                            <div class="user-avatar"><?php echo $userInitial; ?></div>
                            <div>
                                <div class="user-name"><?php echo htmlspecialchars($userName); ?></div>
                                <div class="user-role">Super Admin</div>
                            </div>
                        </div>
                        <button class="logout-btn" onclick="logout()" title="Logout"><i class="fas fa-sign-out-alt"></i></button>
                    </div>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="main-content">
                <div class="topbar">
                    <h4><i class="fas fa-cog me-2"></i>Campus Settings</h4>
                    <div class="topbar-actions">
                        <span class="badge bg-danger text-white me-2 d-none d-md-inline-block">
                            <i class="fas fa-crown me-1"></i> Super Admin
                        </span>
                        <a href="/platform/campuses/view.php?id=<?php echo $campusId; ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-arrow-left me-1"></i> Back to Campus
                        </a>
                    </div>
                </div>

                <div class="content-area">
                    <div class="page-header">
                        <h2><i class="fas fa-sliders-h me-2 text-primary"></i>Campus Settings</h2>
                        <span class="text-muted small">Configure campus-specific settings</span>
                    </div>

                    <!-- Campus Info Banner -->
                    <div class="campus-info-banner">
                        <div>
                            <span class="campus-name"><i class="fas fa-map-marker-alt me-2 text-primary"></i><?php echo htmlspecialchars($campus['campus_name']); ?></span>
                            <span class="campus-meta ms-3"><i class="fas fa-school me-1"></i><?php echo htmlspecialchars($campus['school_name'] ?? 'N/A'); ?></span>
                            <span class="campus-meta ms-3"><i class="fas fa-building me-1"></i><?php echo htmlspecialchars($campus['tenant_name'] ?? 'N/A'); ?></span>
                        </div>
                        <span class="status-badge <?php echo $statusBadge[$campus['status']] ?? 'inactive'; ?>">
                            <?php echo ucfirst($campus['status']); ?>
                        </span>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="settings-section">
                            <!-- General Settings -->
                            <div class="card-custom">
                                <div class="card-header-custom">
                                    <h6><i class="fas fa-info-circle me-2 text-primary"></i> General Settings</h6>
                                </div>
                                <div class="card-body-custom">
                                    <div class="mb-3">
                                        <label class="form-label" for="campus_phone">Campus Phone</label>
                                        <input type="tel" class="form-control" id="campus_phone" name="campus_phone"
                                            value="<?php echo htmlspecialchars($settings['campus_phone'] ?? ''); ?>"
                                            placeholder="+233 24 123 4567">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="campus_email">Campus Email</label>
                                        <input type="email" class="form-control" id="campus_email" name="campus_email"
                                            value="<?php echo htmlspecialchars($settings['campus_email'] ?? ''); ?>"
                                            placeholder="campus@school.edu.gh">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="campus_website">Website</label>
                                        <input type="url" class="form-control" id="campus_website" name="campus_website"
                                            value="<?php echo htmlspecialchars($settings['campus_website'] ?? ''); ?>"
                                            placeholder="https://campus.school.edu.gh">
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label" for="principal_name">Principal Name</label>
                                            <input type="text" class="form-control" id="principal_name" name="principal_name"
                                                value="<?php echo htmlspecialchars($settings['principal_name'] ?? ''); ?>"
                                                placeholder="Full name">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label" for="vice_principal_name">Vice Principal Name</label>
                                            <input type="text" class="form-control" id="vice_principal_name" name="vice_principal_name"
                                                value="<?php echo htmlspecialchars($settings['vice_principal_name'] ?? ''); ?>"
                                                placeholder="Full name">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label" for="school_hours_start">School Start Time</label>
                                            <input type="time" class="form-control" id="school_hours_start" name="school_hours_start"
                                                value="<?php echo htmlspecialchars($settings['school_hours_start'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label" for="school_hours_end">School End Time</label>
                                            <input type="time" class="form-control" id="school_hours_end" name="school_hours_end"
                                                value="<?php echo htmlspecialchars($settings['school_hours_end'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Academic Settings -->
                            <div class="card-custom">
                                <div class="card-header-custom">
                                    <h6><i class="fas fa-graduation-cap me-2 text-primary"></i> Academic Settings</h6>
                                </div>
                                <div class="card-body-custom">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label" for="academic_year_start">Academic Year Start</label>
                                            <input type="date" class="form-control" id="academic_year_start" name="academic_year_start"
                                                value="<?php echo htmlspecialchars($settings['academic_year_start'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label" for="academic_year_end">Academic Year End</label>
                                            <input type="date" class="form-control" id="academic_year_end" name="academic_year_end"
                                                value="<?php echo htmlspecialchars($settings['academic_year_end'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label" for="term_system">Term System</label>
                                            <select class="form-select" id="term_system" name="term_system">
                                                <?php foreach ($termSystems as $system): ?>
                                                    <option value="<?php echo $system; ?>" <?php echo ($settings['term_system'] ?? 'Term') == $system ? 'selected' : ''; ?>>
                                                        <?php echo $system; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label" for="terms_per_year">Terms per Year</label>
                                            <input type="number" class="form-control" id="terms_per_year" name="terms_per_year"
                                                value="<?php echo $settings['terms_per_year'] ?? 3; ?>" min="1" max="6">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label" for="max_students_per_class">Max Students/Class</label>
                                            <input type="number" class="form-control" id="max_students_per_class" name="max_students_per_class"
                                                value="<?php echo $settings['max_students_per_class'] ?? 40; ?>" min="1" max="100">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Regional Settings -->
                            <div class="card-custom">
                                <div class="card-header-custom">
                                    <h6><i class="fas fa-globe me-2 text-primary"></i> Regional Settings</h6>
                                </div>
                                <div class="card-body-custom">
                                    <div class="mb-3">
                                        <label class="form-label" for="timezone">Timezone</label>
                                        <select class="form-select" id="timezone" name="timezone">
                                            <?php foreach ($timezones as $key => $label): ?>
                                                <option value="<?php echo $key; ?>" <?php echo ($settings['timezone'] ?? 'Africa/Accra') == $key ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label" for="language">Default Language</label>
                                            <select class="form-select" id="language" name="language">
                                                <?php foreach ($languages as $key => $label): ?>
                                                    <option value="<?php echo $key; ?>" <?php echo ($settings['language'] ?? 'en') == $key ? 'selected' : ''; ?>>
                                                        <?php echo $label; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label" for="currency">Currency</label>
                                            <select class="form-select" id="currency" name="currency">
                                                <?php foreach ($currencies as $key => $label): ?>
                                                    <option value="<?php echo $key; ?>" <?php echo ($settings['currency'] ?? 'GHS') == $key ? 'selected' : ''; ?>>
                                                        <?php echo $label; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Feature Toggles -->
                            <div class="card-custom">
                                <div class="card-header-custom">
                                    <h6><i class="fas fa-toggle-on me-2 text-primary"></i> Feature Toggles</h6>
                                </div>
                                <div class="card-body-custom">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" id="use_guardian_portal" name="use_guardian_portal"
                                            <?php echo ($settings['use_guardian_portal'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="use_guardian_portal">
                                            Enable Guardian Portal
                                            <span class="text-muted d-block small">Allow parents/guardians to access student information</span>
                                        </label>
                                    </div>
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" id="enable_online_registration" name="enable_online_registration"
                                            <?php echo ($settings['enable_online_registration'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="enable_online_registration">
                                            Enable Online Registration
                                            <span class="text-muted d-block small">Allow new student registrations online</span>
                                        </label>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="enable_parent_app" name="enable_parent_app"
                                            <?php echo ($settings['enable_parent_app'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="enable_parent_app">
                                            Enable Parent Mobile App
                                            <span class="text-muted d-block small">Allow parents to use mobile app</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Location & Branding -->
                            <div class="card-custom">
                                <div class="card-header-custom">
                                    <h6><i class="fas fa-map-pin me-2 text-primary"></i> Location & Branding</h6>
                                </div>
                                <div class="card-body-custom">
                                    <div class="mb-3">
                                        <label class="form-label" for="google_maps_api_key">Google Maps API Key</label>
                                        <input type="text" class="form-control" id="google_maps_api_key" name="google_maps_api_key"
                                            value="<?php echo htmlspecialchars($settings['google_maps_api_key'] ?? ''); ?>"
                                            placeholder="AIzaSy...">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="gps_coordinates">GPS Coordinates</label>
                                        <input type="text" class="form-control" id="gps_coordinates" name="gps_coordinates"
                                            value="<?php echo htmlspecialchars($settings['gps_coordinates'] ?? ''); ?>"
                                            placeholder="6.12345, -0.12345">
                                    </div>
                                    <hr>
                                    <div class="mb-3">
                                        <label class="form-label" for="logo_url">Logo URL</label>
                                        <input type="url" class="form-control" id="logo_url" name="logo_url"
                                            value="<?php echo htmlspecialchars($settings['logo_url'] ?? ''); ?>"
                                            placeholder="https://...">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="favicon_url">Favicon URL</label>
                                        <input type="url" class="form-control" id="favicon_url" name="favicon_url"
                                            value="<?php echo htmlspecialchars($settings['favicon_url'] ?? ''); ?>"
                                            placeholder="https://...">
                                    </div>
                                    <div class="row">
                                        <div class="col-md-4 mb-2">
                                            <label class="form-label">Header Color</label>
                                            <div class="color-picker-wrapper">
                                                <input type="color" class="color-picker-input" id="header_color" name="header_color"
                                                    value="<?php echo htmlspecialchars($settings['header_color'] ?? '#1a1a2e'); ?>">
                                                <div class="color-preview" style="background-color: <?php echo htmlspecialchars($settings['header_color'] ?? '#1a1a2e'); ?>;"></div>
                                            </div>
                                        </div>
                                        <div class="col-md-4 mb-2">
                                            <label class="form-label">Footer Color</label>
                                            <div class="color-picker-wrapper">
                                                <input type="color" class="color-picker-input" id="footer_color" name="footer_color"
                                                    value="<?php echo htmlspecialchars($settings['footer_color'] ?? '#1a1a2e'); ?>">
                                                <div class="color-preview" style="background-color: <?php echo htmlspecialchars($settings['footer_color'] ?? '#1a1a2e'); ?>;"></div>
                                            </div>
                                        </div>
                                        <div class="col-md-4 mb-2">
                                            <label class="form-label">Accent Color</label>
                                            <div class="color-picker-wrapper">
                                                <input type="color" class="color-picker-input" id="accent_color" name="accent_color"
                                                    value="<?php echo htmlspecialchars($settings['accent_color'] ?? '#4facfe'); ?>">
                                                <div class="color-preview" style="background-color: <?php echo htmlspecialchars($settings['accent_color'] ?? '#4facfe'); ?>;"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Save Button -->
                        <div class="mt-3 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i> Save Settings
                            </button>
                            <a href="/platform/campuses/view.php?id=<?php echo $campusId; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i> Cancel
                            </a>
                        </div>
                    </form>
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

        // Color picker preview updates
        document.querySelectorAll('.color-picker-input').forEach(function(input) {
            input.addEventListener('input', function() {
                const preview = this.closest('.color-picker-wrapper').querySelector('.color-preview');
                if (preview) {
                    preview.style.backgroundColor = this.value;
                }
            });
        });

        // Preview logo URL changes
        document.getElementById('logo_url').addEventListener('change', function() {
            const url = this.value;
            if (url) {
                // You could add a live preview here
                console.log('Logo URL changed:', url);
            }
        });
    </script>
</body>

</html>