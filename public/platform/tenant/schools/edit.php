<?php

/**
 * Edit School - Tenant Admin edits existing school
 * 
 * @package EduTrack
 * @subpackage Platform\Tenant\Schools
 * @version 1.0
 * @filepath public/platform/tenant/schools/edit.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Fourth file of the tenant-surface sweep. Three changes:
 *     - The inline <nav class="sidebar"> block is removed and
 *       replaced by an include of app/views/partials/sidebar.php.
 *       This file's inline sidebar carried the same nine items the
 *       partial carries, so the rendered sidebar is unchanged
 *       except for the header brand.
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Tenant' to 'Student 360 Tenant'.
 *     - The @version tag was unified to 1.0.
 *   Every other line of the file is byte-identical to the previous
 *   version (2.0). The @package tag remains 'EduTrack'.
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

// Super Admin should not access this page
if (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] === true) {
    header('Location: /platform/schools/edit.php?id=' . ($_GET['id'] ?? 0));
    exit;
}

$tenantId = $_SESSION['tenant_id'] ?? 0;
$schoolId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$tenantId || !$schoolId) {
    header('Location: /platform/tenant/schools/index.php');
    exit;
}

// =============================================
// PAGE SETUP
// =============================================
$pageTitle = 'Edit School - Student 360 Tenant';
$currentPage = 'schools';

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];

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
$db = DatabaseHelper::getInstance();

// =============================================
// GET TENANT NAME
// =============================================
$tenant = $db->fetchOne(
    "SELECT tenant_name FROM tenants WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '')",
    [$tenantId]
);
$tenantName = $tenant['tenant_name'] ?? 'My Organization';

// =============================================
// GET SCHOOL DETAILS (Tenant-scoped)
// =============================================
$school = $db->fetchOne(
    "SELECT * FROM schools
     WHERE id = ? AND tenant_id = ? AND (deleted_at IS NULL OR deleted_at = '')",
    [$schoolId, $tenantId]
);

if (!$school) {
    header('Location: /platform/tenant/schools/index.php?error=school_not_found');
    exit;
}

// Check if school is active - only active schools can be edited
if (strtolower($school['status'] ?? '') !== 'active') {
    $_SESSION['error_message'] = 'Only active schools can be edited. This school is ' . ucfirst($school['status'] ?? 'inactive') . '.';
    header('Location: /platform/tenant/schools/index.php');
    exit;
}

// =============================================
// SCHOOL TYPES
// =============================================
$schoolTypes = [
    'basic' => 'Basic School',
    'primary' => 'Primary School',
    'secondary' => 'Secondary School',
    'tertiary' => 'Tertiary Institution',
    'other' => 'Other'
];

// =============================================
// STATUSES
// =============================================
$statuses = [
    'active' => 'Active',
    'inactive' => 'Inactive',
    'pending' => 'Pending',
    'suspended' => 'Suspended'
];

// =============================================
// HANDLE FORM SUBMISSION
// =============================================
$errors = [];
$formData = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = $_POST;

    // Validate required fields
    if (empty($formData['school_name'])) {
        $errors[] = 'School name is required';
    }
    if (empty($formData['school_code'])) {
        $errors[] = 'School code is required';
    }
    if (empty($formData['school_type'])) {
        $errors[] = 'School type is required';
    }

    // Validate email format
    if (!empty($formData['email']) && !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address';
    }

    // Check if school_code already exists (excluding current school)
    if (!empty($formData['school_code'])) {
        $existingSchool = $db->getValue(
            "SELECT COUNT(*) FROM schools WHERE school_code = ? AND tenant_id = ? AND id != ? AND (deleted_at IS NULL OR deleted_at = '')",
            [$formData['school_code'], $tenantId, $schoolId]
        );
        if ($existingSchool > 0) {
            $errors[] = 'School code already exists. Please choose a different one.';
        }
    }

    // Check if email already exists (excluding current school)
    if (!empty($formData['email'])) {
        $existingEmail = $db->getValue(
            "SELECT COUNT(*) FROM schools WHERE email = ? AND tenant_id = ? AND id != ? AND (deleted_at IS NULL OR deleted_at = '')",
            [$formData['email'], $tenantId, $schoolId]
        );
        if ($existingEmail > 0) {
            $errors[] = 'Email already exists. Please use a different email.';
        }
    }

    if (empty($errors)) {
        try {
            // Update school
            $db->execute(
                "UPDATE schools SET 
                 school_name = ?,
                 school_code = ?,
                 school_type = ?,
                 email = ?,
                 phone = ?,
                 address = ?,
                 city = ?,
                 region = ?,
                 district = ?,
                 postal_address = ?,
                 website = ?,
                 status = ?,
                 updated_at = NOW()
                 WHERE id = ? AND tenant_id = ? AND (deleted_at IS NULL OR deleted_at = '')",
                [
                    trim($formData['school_name']),
                    trim($formData['school_code']),
                    $formData['school_type'] ?? 'basic',
                    trim($formData['email'] ?? ''),
                    trim($formData['phone'] ?? ''),
                    trim($formData['address'] ?? ''),
                    trim($formData['city'] ?? ''),
                    trim($formData['region'] ?? ''),
                    trim($formData['district'] ?? ''),
                    trim($formData['postal_address'] ?? ''),
                    trim($formData['website'] ?? ''),
                    $formData['status'] ?? 'active',
                    $schoolId,
                    $tenantId
                ]
            );

            $_SESSION['success_message'] = 'School updated successfully!';
            header('Location: /platform/tenant/schools/index.php');
            exit;
        } catch (Exception $e) {
            error_log('Update school error: ' . $e->getMessage());
            $errors[] = 'Error updating school: ' . $e->getMessage();
        }
    }

    // If there are errors, store them in session
    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
        $_SESSION['form_data'] = $formData;
        header('Location: /platform/tenant/schools/edit.php?id=' . $schoolId);
        exit;
    }
}

// Get form data from session if validation failed
$formData = $_SESSION['form_data'] ?? [];
$errors = $_SESSION['errors'] ?? [];
unset($_SESSION['form_data'], $_SESSION['errors']);

// Merge school data with form data for display
$displayData = array_merge($school, $formData);

// =============================================
// CURRENT USER
// =============================================
$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userFirstName = $_SESSION['first_name'] ?? 'User';
$userAvatar = strtoupper(substr($userFirstName, 0, 1));
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
        /* GLOBAL RESET */
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

        /* ================================================ */
        /* SIDEBAR */
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

        .btn-success {
            background: #28a745;
            border: none;
            color: #fff;
        }

        .btn-success:hover {
            background: #218838;
            color: #fff;
        }

        .btn-info {
            background: #17a2b8;
            border: none;
            color: #fff;
        }

        .btn-info:hover {
            background: #138496;
            color: #fff;
        }

        /* ================================================ */
        /* CARD */
        /* ================================================ */
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

        /* ================================================ */
        /* FORM */
        /* ================================================ */
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
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%23dc3545' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='12' cy='12' r='10'%3E%3C/circle%3E%3Cline x1='15' y1='9' x2='9' y2='15'%3E%3C/line%3E%3Cline x1='9' y1='9' x2='15' y2='15'%3E%3C/line%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 18px;
            padding-right: 40px;
            box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.1);
        }

        .invalid-feedback {
            display: block;
            font-size: 12px;
            color: #dc3545;
            margin-top: 4px;
        }

        .invalid-feedback i {
            margin-right: 4px;
        }

        .form-text {
            font-size: 11px;
            color: #6c757d;
            margin-top: 4px;
        }

        .mb-2 {
            margin-bottom: 10px;
        }

        .mb-3 {
            margin-bottom: 16px;
        }

        .mt-3 {
            margin-top: 16px;
        }

        .gap-2 {
            gap: 8px;
        }

        .d-flex {
            display: flex;
        }

        .flex-wrap {
            flex-wrap: wrap;
        }

        .justify-content-end {
            justify-content: flex-end;
        }

        hr {
            border: none;
            border-top: 1px solid #f0f2f5;
            margin: 16px 0;
        }

        /* ================================================ */
        /* ALERT */
        /* ================================================ */
        .alert-custom {
            border-radius: 12px;
            border: none;
            padding: 16px 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            display: flex;
            align-items: flex-start;
            gap: 14px;
            transition: all 0.3s ease;
            animation: slideDown 0.4s ease;
        }

        .alert-custom .alert-icon {
            font-size: 20px;
            flex-shrink: 0;
            margin-top: 2px;
            width: 28px;
            text-align: center;
        }

        .alert-custom .alert-content {
            flex: 1;
        }

        .alert-custom .alert-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 2px;
        }

        .alert-custom .alert-message {
            font-size: 13px;
            opacity: 0.9;
        }

        .alert-custom .alert-message ul {
            margin: 4px 0 0 0;
            padding-left: 18px;
        }

        .alert-custom .alert-message ul li {
            padding: 1px 0;
        }

        .alert-custom .btn-close-custom {
            background: none;
            border: none;
            color: inherit;
            opacity: 0.6;
            cursor: pointer;
            padding: 4px 8px;
            font-size: 18px;
            transition: opacity 0.2s;
            flex-shrink: 0;
            margin-top: -2px;
        }

        .alert-custom .btn-close-custom:hover {
            opacity: 1;
        }

        .alert-custom.alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border-left: 4px solid #dc3545;
        }

        @keyframes slideDown {
            0% {
                opacity: 0;
                transform: translateY(-10px);
            }

            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ================================================ */
        /* RESPONSIVE */
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
                font-size: 20px;
            }

            .top-bar .page-title p {
                font-size: 12px;
            }

            .top-bar .header-actions .btn {
                font-size: 12px;
                padding: 6px 12px;
            }

            .card-custom .card-header-custom {
                padding: 12px 16px;
            }

            .card-custom .card-body-custom {
                padding: 14px 16px;
            }

            .form-control,
            .form-select {
                font-size: 14px;
                padding: 8px 14px;
                height: 44px;
            }

            .form-label {
                font-size: 13px;
            }

            [class*="col-"] {
                padding-left: 6px;
                padding-right: 6px;
            }

            .row {
                margin: 0 -6px;
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

            .card-custom .card-body-custom {
                padding: 10px 12px;
            }

            .form-control,
            .form-select {
                font-size: 13px;
                padding: 6px 12px;
                height: 40px;
            }

            .btn {
                font-size: 12px;
                padding: 6px 14px;
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

            <!-- Sidebar (includes app/views/partials/sidebar.php) -->
            <?php include $projectRoot . '/app/views/partials/sidebar.php'; ?>

            <!-- Main Content -->
            <main class="main-content">
                <!-- Top Bar -->
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-edit me-2"></i>Edit School</h1>
                        <p>Update school information</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/schools/view.php?id=<?php echo $schoolId; ?>" class="btn btn-info">
                            <i class="fas fa-eye me-2"></i> View
                        </a>
                        <a href="/platform/tenant/schools/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back
                        </a>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer">
                    <?php if (!empty($errors)): ?>
                        <div class="alert-custom alert-danger">
                            <div class="alert-icon"><i class="fas fa-exclamation-circle"></i></div>
                            <div class="alert-content">
                                <div class="alert-title">Please fix the following errors:</div>
                                <div class="alert-message">
                                    <ul>
                                        <?php foreach ($errors as $error): ?>
                                            <li><?php echo htmlspecialchars($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                            <button class="btn-close-custom" onclick="this.closest('.alert-custom').remove()" aria-label="Close">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Edit Form -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-school me-2 text-primary"></i>School Information</h6>
                        <span class="text-muted small">All fields marked with <span class="text-danger">*</span> are required</span>
                    </div>
                    <div class="card-body-custom">
                        <form id="editForm" method="POST" action="/platform/tenant/schools/edit.php?id=<?php echo $schoolId; ?>" novalidate>
                            <input type="hidden" name="school_id" value="<?php echo $schoolId; ?>">

                            <!-- Basic Information -->
                            <h6 class="mb-3"><i class="fas fa-info-circle me-2 text-primary"></i>Basic Information</h6>
                            <div class="row">
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="schoolName">School Name <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="schoolName" name="school_name"
                                            placeholder="Enter school name"
                                            value="<?php echo htmlspecialchars($displayData['school_name'] ?? ''); ?>" required autofocus>
                                        <div class="invalid-feedback"><i class="fas fa-exclamation-circle"></i> School name is required</div>
                                    </div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="schoolCode">School Code <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="schoolCode" name="school_code"
                                            placeholder="e.g., SCH-001"
                                            value="<?php echo htmlspecialchars($displayData['school_code'] ?? ''); ?>" required>
                                        <div class="form-text">Unique code (2-20 characters, uppercase letters, numbers, hyphens)</div>
                                        <div class="invalid-feedback"><i class="fas fa-exclamation-circle"></i> School code is required</div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="schoolType">School Type <span class="required">*</span></label>
                                        <select class="form-select" id="schoolType" name="school_type" required>
                                            <option value="">Select School Type</option>
                                            <?php foreach ($schoolTypes as $key => $label): ?>
                                                <option value="<?php echo $key; ?>" <?php echo (isset($displayData['school_type']) && $displayData['school_type'] == $key) ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="invalid-feedback"><i class="fas fa-exclamation-circle"></i> Please select a school type</div>
                                    </div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="email">Email Address</label>
                                        <input type="email" class="form-control" id="email" name="email"
                                            placeholder="school@example.com"
                                            value="<?php echo htmlspecialchars($displayData['email'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="phone">Phone Number</label>
                                        <input type="tel" class="form-control" id="phone" name="phone"
                                            placeholder="+233 XX XXX XXX"
                                            value="<?php echo htmlspecialchars($displayData['phone'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="website">Website</label>
                                        <input type="url" class="form-control" id="website" name="website"
                                            placeholder="https://school.example.com"
                                            value="<?php echo htmlspecialchars($displayData['website'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="status">Status</label>
                                        <select class="form-select" id="status" name="status">
                                            <?php foreach ($statuses as $key => $label): ?>
                                                <option value="<?php echo $key; ?>" <?php echo (isset($displayData['status']) && strtolower($displayData['status']) == $key) ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">Changing status to inactive will disable this school</div>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <!-- Address Information -->
                            <h6 class="mb-3"><i class="fas fa-map-pin me-2 text-primary"></i>Address Information</h6>
                            <div class="row">
                                <div class="col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="address">Address</label>
                                        <input type="text" class="form-control" id="address" name="address"
                                            placeholder="Street address"
                                            value="<?php echo htmlspecialchars($displayData['address'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="city">City</label>
                                        <input type="text" class="form-control" id="city" name="city"
                                            placeholder="City"
                                            value="<?php echo htmlspecialchars($displayData['city'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="region">Region</label>
                                        <input type="text" class="form-control" id="region" name="region"
                                            placeholder="Region/State"
                                            value="<?php echo htmlspecialchars($displayData['region'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="district">District</label>
                                        <input type="text" class="form-control" id="district" name="district"
                                            placeholder="District"
                                            value="<?php echo htmlspecialchars($displayData['district'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="postalAddress">Postal Address</label>
                                        <input type="text" class="form-control" id="postalAddress" name="postal_address"
                                            placeholder="P.O. Box"
                                            value="<?php echo htmlspecialchars($displayData['postal_address'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <!-- Form Actions -->
                            <div class="d-flex gap-2 flex-wrap justify-content-end">
                                <a href="/platform/tenant/schools/index.php" class="btn btn-outline-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <i class="fas fa-save me-2"></i> Update School
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
                window.location.href = '/platform/logout.php';
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
        // REAL-TIME VALIDATION
        // ================================================
        function validateField(input) {
            const value = input.value.trim();
            const isRequired = input.hasAttribute('required');

            if (!isRequired) return;

            if (value === '') {
                input.classList.remove('is-valid');
                input.classList.add('is-invalid');
                return;
            }

            if (input.type === 'email' && value !== '') {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(value)) {
                    input.classList.remove('is-valid');
                    input.classList.add('is-invalid');
                    return;
                }
            }

            input.classList.remove('is-invalid');
            input.classList.add('is-valid');
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();

            const requiredInputs = document.querySelectorAll('input[required], select[required]');
            requiredInputs.forEach(input => {
                input.addEventListener('blur', function() {
                    validateField(this);
                });
                if (input.tagName === 'INPUT') {
                    input.addEventListener('input', function() {
                        if (this.value.trim().length > 0) {
                            this.classList.remove('is-invalid');
                            this.classList.add('is-valid');
                        } else {
                            this.classList.remove('is-valid');
                            this.classList.add('is-invalid');
                        }
                    });
                }
            });

            const emailInputs = document.querySelectorAll('input[type="email"]');
            emailInputs.forEach(input => {
                input.addEventListener('input', function() {
                    if (this.value.trim() === '') {
                        this.classList.remove('is-valid');
                        this.classList.remove('is-invalid');
                        return;
                    }
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (emailRegex.test(this.value.trim())) {
                        this.classList.remove('is-invalid');
                        this.classList.add('is-valid');
                    } else {
                        this.classList.remove('is-valid');
                        this.classList.add('is-invalid');
                    }
                });
            });

            // Form submit validation
            document.getElementById('editForm').addEventListener('submit', function(e) {
                let isValid = true;
                const requiredInputs = this.querySelectorAll('input[required], select[required]');

                requiredInputs.forEach(input => {
                    if (!validateField(input)) {
                        isValid = false;
                    }
                });

                if (!isValid) {
                    e.preventDefault();
                    const firstInvalid = this.querySelector('.is-invalid');
                    if (firstInvalid) {
                        firstInvalid.focus();
                        firstInvalid.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                    }
                }
            });
        });
    </script>
</body>

</html>