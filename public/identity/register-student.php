<?php

/**
 * Student Registration - Complete 10-Step Wizard
 * 
 * @package EduTrack
 * @subpackage Identity
 * @version 2.0
 * @filepath public/identity/register-student.php
 */

session_start();

// Check authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

// Get tenant and school context from session
$tenantId = $_SESSION['tenant_id'] ?? 0;
$schoolId = $_SESSION['school_id'] ?? 0;
$schoolName = $_SESSION['school_name'] ?? 'School';

// If no school context, redirect to school selection
if (!$schoolId) {
    header('Location: /platform/schools/select.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// Get user info
$userName = $_SESSION['first_name'] ?? 'Admin';
$userAvatar = substr($userName, 0, 1);

// Determine API base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$apiBase = $protocol . '://' . $host . '/api/platform';

$pageTitle = 'Register Student - EduTrack Platform';
$currentPage = 'identity';

// Get current step from URL (default: 1)
$currentStep = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$totalSteps = 10;

// Ensure step is within bounds
if ($currentStep < 1) $currentStep = 1;
if ($currentStep > $totalSteps) $currentStep = $totalSteps;

// Define step labels for the wizard
$steps = [
    1 => ['label' => 'Identity', 'icon' => 'fa-user'],
    2 => ['label' => 'Photo', 'icon' => 'fa-camera'],
    3 => ['label' => 'Parent/Guardian', 'icon' => 'fa-users'],
    4 => ['label' => 'Contact & Address', 'icon' => 'fa-address-book'],
    5 => ['label' => 'Health & Medical', 'icon' => 'fa-heartbeat'],
    6 => ['label' => 'Documents', 'icon' => 'fa-id-card'],
    7 => ['label' => 'Academic', 'icon' => 'fa-graduation-cap'],
    8 => ['label' => 'Admission', 'icon' => 'fa-clipboard-list'],
    9 => ['label' => 'Review', 'icon' => 'fa-check-circle'],
    10 => ['label' => 'Confirmation', 'icon' => 'fa-check-double']
];
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

        .sidebar .sidebar-header .school-badge {
            background: rgba(255, 255, 255, 0.1);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            color: #4facfe;
            display: inline-block;
            margin-top: 4px;
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

        /* ================================================ */
        /* STUDENT PHOTO - TOP RIGHT                       */
        /* ================================================ */
        .student-photo-container {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 16px;
        }

        .student-photo-wrapper {
            background: #fff;
            border-radius: 16px;
            padding: 12px 16px 10px 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            text-align: center;
        }

        .student-photo-preview {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #f0f2f5;
            border: 2px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            color: #adb5bd;
            overflow: hidden;
            margin: 0 auto;
        }

        .student-photo-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .student-photo-label {
            font-size: 10px;
            color: #6c757d;
            margin-top: 4px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ================================================ */
        /* WIZARD STEPS                                   */
        /* ================================================ */
        .wizard-container {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            overflow: hidden;
        }

        .wizard-steps {
            display: flex;
            background: #f8f9fa;
            padding: 16px 24px;
            border-bottom: 2px solid #e9ecef;
            overflow-x: auto;
            flex-wrap: nowrap;
            gap: 4px;
        }

        .wizard-step {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 500;
            color: #6c757d;
            white-space: nowrap;
            transition: all 0.3s;
            cursor: default;
            flex-shrink: 0;
        }

        .wizard-step .step-number {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            background: #e9ecef;
            color: #6c757d;
            transition: all 0.3s;
        }

        .wizard-step.active .step-number {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: #fff;
            box-shadow: 0 4px 12px rgba(79, 172, 254, 0.3);
        }

        .wizard-step.completed .step-number {
            background: #28a745;
            color: #fff;
        }

        .wizard-step.active {
            color: #1a1a2e;
        }

        .wizard-step.completed {
            color: #28a745;
        }

        .wizard-step .step-label {
            font-size: 12px;
        }

        .wizard-step .step-connector {
            color: #dee2e6;
            font-size: 12px;
            margin: 0 2px;
        }

        .wizard-content {
            padding: 32px;
        }

        .wizard-content .step-title {
            font-size: 20px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 4px;
        }

        .wizard-content .step-subtitle {
            color: #6c757d;
            font-size: 14px;
            margin-bottom: 24px;
        }

        .wizard-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 32px 24px;
            border-top: 1px solid #e9ecef;
            flex-wrap: wrap;
            gap: 10px;
        }

        .wizard-footer .btn {
            border-radius: 10px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 14px;
        }

        /* ================================================ */
        /* FORM ELEMENTS                                  */
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
            box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.1);
        }

        .form-text {
            font-size: 11px;
            color: #6c757d;
            margin-top: 4px;
        }

        .form-section {
            margin-bottom: 24px;
        }

        .form-section:last-child {
            margin-bottom: 0;
        }

        .form-section .section-title {
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

        .form-section .section-title i {
            color: #4facfe;
        }

        /* Photo Upload */
        .photo-upload-container {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .photo-preview {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: #f0f2f5;
            border: 2px dashed #ced4da;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            color: #adb5bd;
            overflow: hidden;
            flex-shrink: 0;
        }

        .photo-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .photo-upload-btn .btn {
            border-radius: 10px;
        }

        /* Search Results */
        .search-results {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            margin-top: 8px;
        }

        .search-results .list-group-item {
            cursor: pointer;
            border: none;
            border-bottom: 1px solid #f0f2f5;
        }

        .search-results .list-group-item:hover {
            background: #f8f9fa;
        }

        .search-results .list-group-item:last-child {
            border-bottom: none;
        }

        /* Review Grid */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 16px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            padding: 4px 0;
            border-bottom: 1px solid #f8f9fa;
        }

        .info-item .label {
            font-size: 11px;
            color: #6c757d;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .info-item .value {
            font-size: 14px;
            color: #1a1a2e;
            font-weight: 500;
        }

        /* Document Row */
        .document-row {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 10px;
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

            .sidebar .sidebar-header .school-badge {
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

            .wizard-steps {
                padding: 12px 16px;
            }

            .wizard-step .step-label {
                display: none;
            }

            .wizard-step .step-connector {
                display: none;
            }

            .wizard-content {
                padding: 20px;
            }

            .wizard-footer {
                padding: 12px 20px 16px;
            }

            .student-photo-wrapper {
                padding: 8px 12px;
            }

            .student-photo-preview {
                width: 56px;
                height: 56px;
                font-size: 24px;
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

            .sidebar .sidebar-header .school-badge {
                display: inline-block;
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

            .wizard-steps {
                padding: 10px 12px;
                gap: 2px;
            }

            .wizard-step {
                padding: 6px 10px;
                font-size: 11px;
            }

            .wizard-step .step-number {
                width: 24px;
                height: 24px;
                font-size: 10px;
            }

            .wizard-content {
                padding: 16px;
            }

            .wizard-content .step-title {
                font-size: 18px;
            }

            .wizard-footer {
                flex-direction: column;
                align-items: stretch;
                padding: 12px 16px 16px;
            }

            .wizard-footer .btn {
                width: 100%;
                justify-content: center;
            }

            .photo-upload-container {
                flex-direction: column;
                align-items: center;
            }

            .wizard-step .step-label {
                display: none;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .student-photo-container {
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

            .wizard-content {
                padding: 12px;
            }

            .wizard-content .step-title {
                font-size: 16px;
            }

            .wizard-content .step-subtitle {
                font-size: 13px;
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

            .photo-preview {
                width: 80px;
                height: 80px;
                font-size: 28px;
            }

            .student-photo-preview {
                width: 48px;
                height: 48px;
                font-size: 20px;
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
                    <small><?php echo htmlspecialchars($schoolName); ?></small>
                    <div class="school-badge"><i class="fas fa-building me-1"></i>School ID: <?php echo $schoolId; ?></div>
                </div>
                <div class="nav">
                    <div class="nav-label">Main</div>
                    <a class="nav-link" href="/platform/index.php">
                        <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                    </a>
                    <div class="nav-label mt-3">People</div>
                    <a class="nav-link" href="/platform/people/index.php">
                        <i class="fas fa-user-friends"></i> <span>People Directory</span>
                    </a>
                    <a class="nav-link" href="/identity/index.php">
                        <i class="fas fa-user-plus"></i> <span>Register</span>
                    </a>
                    <a class="nav-link active" href="/identity/register-student.php">
                        <i class="fas fa-user-graduate"></i> <span>Add Student</span>
                    </a>
                    <a class="nav-link" href="/identity/register-staff.php">
                        <i class="fas fa-user-tie"></i> <span>Add Staff</span>
                    </a>
                    <div class="nav-label mt-3">Institution</div>
                    <a class="nav-link" href="/platform/schools/index.php">
                        <i class="fas fa-school"></i> <span>Schools</span>
                    </a>
                    <a class="nav-link" href="/platform/campuses/index.php">
                        <i class="fas fa-map-marker-alt"></i> <span>Campuses</span>
                    </a>
                    <div class="nav-label mt-3">System</div>
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
                        <h1><i class="fas fa-user-graduate me-2"></i>Student Registration</h1>
                        <p>Register a new student for <?php echo htmlspecialchars($schoolName); ?></p>
                    </div>
                    <div class="header-actions">
                        <a href="/identity/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back
                        </a>
                    </div>
                </div>

                <!-- Student Photo - Top Right -->
                <div class="student-photo-container">
                    <div class="student-photo-wrapper">
                        <div class="student-photo-preview" id="headerPhotoPreview">
                            <i class="fas fa-user-circle"></i>
                        </div>
                        <div class="student-photo-label">Student Photo</div>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer"></div>

                <!-- Wizard Container -->
                <div class="wizard-container">
                    <!-- Wizard Steps -->
                    <div class="wizard-steps" id="wizardSteps">
                        <?php foreach ($steps as $num => $step):
                            $status = '';
                            if ($num == $currentStep) $status = 'active';
                            elseif ($num < $currentStep) $status = 'completed';
                        ?>
                            <div class="wizard-step <?php echo $status; ?>">
                                <span class="step-number">
                                    <?php if ($status == 'completed'): ?>
                                        <i class="fas fa-check"></i>
                                    <?php else: ?>
                                        <?php echo $num; ?>
                                    <?php endif; ?>
                                </span>
                                <span class="step-label"><?php echo $step['label']; ?></span>
                                <?php if ($num < $totalSteps): ?>
                                    <span class="step-connector"><i class="fas fa-chevron-right"></i></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Wizard Content -->
                    <div class="wizard-content" id="wizardContent">
                        <?php
                        // Include the appropriate step content
                        $stepFile = __DIR__ . '/includes/student-step-' . $currentStep . '.php';
                        if (file_exists($stepFile)) {
                            include $stepFile;
                        } else {
                            // Fallback: show a message
                            echo '<div class="text-center py-4">';
                            echo '<i class="fas fa-cog fa-spin fa-2x text-primary mb-3"></i>';
                            echo '<h5>Loading Step ' . $currentStep . '</h5>';
                            echo '<p class="text-muted">Please wait...</p>';
                            echo '</div>';
                        }
                        ?>
                    </div>

                    <!-- Wizard Footer -->
                    <div class="wizard-footer">
                        <div>
                            <span class="text-muted small">
                                Step <?php echo $currentStep; ?> of <?php echo $totalSteps; ?>
                            </span>
                            <span class="text-muted small ms-3">
                                <i class="fas fa-asterisk text-danger me-1" style="font-size:8px;"></i> Required fields
                            </span>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php if ($currentStep > 1): ?>
                                <a href="?step=<?php echo $currentStep - 1; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-2"></i> Previous
                                </a>
                            <?php endif; ?>

                            <?php if ($currentStep < $totalSteps): ?>
                                <button type="button" class="btn btn-primary" onclick="validateAndProceed(<?php echo $currentStep + 1; ?>)">
                                    Next Step <i class="fas fa-arrow-right ms-2"></i>
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-success" onclick="submitRegistration()">
                                    <i class="fas fa-check me-2"></i> Complete Registration
                                </button>
                            <?php endif; ?>
                        </div>
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
        const TOKEN = localStorage.getItem('token') || '';
        const TENANT_ID = <?php echo json_encode($tenantId); ?>;
        const SCHOOL_ID = <?php echo json_encode($schoolId); ?>;
        const CURRENT_STEP = <?php echo $currentStep; ?>;

        // Registration data store
        let registrationData = {};

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
                'X-Tenant-ID': TENANT_ID,
                'X-School-ID': SCHOOL_ID
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
        // UPDATE HEADER PHOTO
        // ================================================
        function updateHeaderPhoto(imageUrl) {
            const preview = document.getElementById('headerPhotoPreview');
            if (imageUrl) {
                preview.innerHTML = '<img src="' + imageUrl + '" alt="Student Photo">';
            } else {
                preview.innerHTML = '<i class="fas fa-user-circle"></i>';
            }
        }

        // ================================================
        // PHOTO PREVIEW
        // ================================================
        function previewPhoto(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('photoPreview');
                    preview.innerHTML = '<img src="' + e.target.result + '" alt="Student Photo">';
                    // Also update the header photo
                    updateHeaderPhoto(e.target.result);
                };
                reader.readAsDataURL(file);
            }
        }

        // ================================================
        // VALIDATE CURRENT STEP
        // ================================================
        function validateCurrentStep() {
            const form = document.getElementById('stepForm');
            if (!form) return true;

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
                showAlert('Please fill in all required fields before proceeding.', 'warning');
                // Scroll to first invalid field
                const firstInvalid = form.querySelector('.is-invalid');
                if (firstInvalid) {
                    firstInvalid.focus();
                    firstInvalid.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                }
            }

            return isValid;
        }

        // ================================================
        // VALIDATE AND PROCEED
        // ================================================
        function validateAndProceed(nextStep) {
            if (validateCurrentStep()) {
                // Collect form data from current step
                collectStepData(CURRENT_STEP);
                // Navigate to next step
                window.location.href = '?step=' + nextStep;
            }
        }

        // ================================================
        // COLLECT STEP DATA
        // ================================================
        function collectStepData(step) {
            const form = document.getElementById('stepForm');
            if (!form) return;

            const formData = new FormData(form);
            const data = {};

            formData.forEach((value, key) => {
                if (value.trim() !== '') {
                    data[key] = value.trim();
                }
            });

            // Store in the global registration data
            registrationData['step_' + step] = data;

            // Also store in session storage for persistence
            try {
                sessionStorage.setItem('student_registration_' + step, JSON.stringify(data));
            } catch (e) {
                console.warn('Could not save to session storage:', e);
            }
        }

        // ================================================
        // LOAD PREVIOUS STEP DATA
        // ================================================
        function loadPreviousStepData(step) {
            // Try to load from session storage
            try {
                const stored = sessionStorage.getItem('student_registration_' + step);
                if (stored) {
                    const data = JSON.parse(stored);
                    const form = document.getElementById('stepForm');
                    if (form) {
                        Object.keys(data).forEach(key => {
                            const field = form.querySelector('[name="' + key + '"]');
                            if (field) {
                                field.value = data[key];
                            }
                        });
                    }
                }
            } catch (e) {
                console.warn('Could not load from session storage:', e);
            }
        }

        // ================================================
        // SUBMIT REGISTRATION
        // ================================================
        async function submitRegistration() {
            if (!validateCurrentStep()) return;

            // Collect final step data
            collectStepData(CURRENT_STEP);

            // Build complete payload
            const payload = buildPayload();

            // Show loading state
            const btn = document.querySelector('.wizard-footer .btn-success');
            if (!btn) return;
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Registering...';

            try {
                const response = await fetch(API_BASE + '/index.php?endpoint=student&action=register', {
                    method: 'POST',
                    headers: getHeaders(),
                    body: JSON.stringify(payload)
                });

                const result = await response.json();

                btn.disabled = false;
                btn.innerHTML = originalText;

                if (result.success) {
                    showAlert('✅ Student registered successfully!', 'success');
                    // Clear session storage
                    for (let i = 1; i <= 10; i++) {
                        sessionStorage.removeItem('student_registration_' + i);
                    }
                    // Redirect to profile
                    setTimeout(() => {
                        window.location.href = '/identity/view.php?id=' + result.data.person_id;
                    }, 2000);
                } else {
                    showAlert('❌ ' + (result.message || 'Failed to register student'), 'danger');
                }
            } catch (error) {
                console.error('Registration error:', error);
                btn.disabled = false;
                btn.innerHTML = originalText;
                showAlert('Error registering student. Please try again.', 'danger');
            }
        }

        // ================================================
        // BUILD PAYLOAD
        // ================================================
        function buildPayload() {
            const payload = {
                tenant_id: TENANT_ID,
                school_id: SCHOOL_ID,
                person: {},
                student: {},
                guardian: {},
                health: {},
                documents: [],
                admission: {},
                academic: {}
            };

            // Collect all step data from session storage
            for (let i = 1; i <= 10; i++) {
                try {
                    const stored = sessionStorage.getItem('student_registration_' + i);
                    if (stored) {
                        const data = JSON.parse(stored);
                        // Merge based on step
                        switch (i) {
                            case 1: // Identity
                                Object.assign(payload.person, data);
                                break;
                            case 3: // Parent/Guardian
                                Object.assign(payload.guardian, data);
                                break;
                            case 4: // Contact & Address
                                Object.assign(payload.person, data);
                                break;
                            case 5: // Health & Medical
                                Object.assign(payload.health, data);
                                break;
                            case 6: // Documents
                                // Handle document rows
                                if (data.document_type) {
                                    const docTypes = Array.isArray(data.document_type) ? data.document_type : [data
                                        .document_type
                                    ];
                                    const docNumbers = Array.isArray(data.document_number) ? data.document_number : [data
                                        .document_number
                                    ];
                                    const docExpiries = Array.isArray(data.document_expiry) ? data.document_expiry : [data
                                        .document_expiry
                                    ];
                                    for (let d = 0; d < docTypes.length; d++) {
                                        if (docTypes[d]) {
                                            payload.documents.push({
                                                type: docTypes[d],
                                                number: docNumbers[d] || '',
                                                expiry: docExpiries[d] || ''
                                            });
                                        }
                                    }
                                }
                                break;
                            case 7: // Academic
                                Object.assign(payload.academic, data);
                                break;
                            case 8: // Admission
                                Object.assign(payload.admission, data);
                                break;
                            default:
                                // For other steps, merge into person
                                if (data) {
                                    Object.assign(payload.person, data);
                                }
                        }
                    }
                } catch (e) {
                    console.warn('Could not load step data for step ' + i, e);
                }
            }

            return payload;
        }

        // ================================================
        // SEARCH PARENT/GUARDIAN
        // ================================================
        function searchParent() {
            const searchTerm = document.getElementById('parentSearch')?.value?.trim();
            if (!searchTerm || searchTerm.length < 2) {
                showAlert('Please enter at least 2 characters to search.', 'warning');
                return;
            }

            const container = document.getElementById('searchResults');
            if (!container) return;

            container.style.display = 'block';
            container.innerHTML = `
                <div class="text-center py-3">
                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                    Searching...
                </div>
            `;

            fetch(API_BASE + '/index.php?endpoint=identity&action=search&q=' + encodeURIComponent(searchTerm), {
                    headers: getHeaders()
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success && result.data && result.data.length > 0) {
                        let html = `<div class="list-group">`;
                        result.data.forEach(person => {
                            html += `
                            <button type="button" class="list-group-item list-group-item-action" onclick="selectParent(${person.id}, '${person.first_name} ${person.last_name}')">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>${person.first_name} ${person.last_name}</strong>
                                        <div class="small text-muted">${person.person_number || ''} ${person.primary_phone || ''}</div>
                                    </div>
                                    <span class="badge bg-primary">${person.person_type || 'Person'}</span>
                                </div>
                            </button>
                        `;
                        });
                        html += `</div>`;
                        container.innerHTML = html;
                    } else {
                        container.innerHTML = `
                        <div class="text-center py-3 text-muted">
                            <i class="fas fa-info-circle me-2"></i> No results found. Try a different search or create a new parent/guardian.
                        </div>
                    `;
                    }
                })
                .catch(error => {
                    console.error('Search error:', error);
                    container.innerHTML = `
                    <div class="text-center py-3 text-danger">
                        <i class="fas fa-exclamation-circle me-2"></i> Error searching. Please try again.
                    </div>
                `;
                });
        }

        function selectParent(id, name) {
            document.getElementById('selectedParentId').value = id;
            document.getElementById('selectedParentName').value = name;
            const display = document.getElementById('selectedParentDisplay');
            if (display) {
                display.innerHTML = `
                    <i class="fas fa-check-circle text-success me-2"></i> ${name}
                    <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="clearParent()">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                display.style.color = '#1a1a2e';
            }
            const results = document.getElementById('searchResults');
            if (results) {
                results.style.display = 'none';
                results.innerHTML = '';
            }
            document.getElementById('parentSearch').value = '';
            showAlert('Parent/Guardian selected: ' + name, 'success');
        }

        function clearParent() {
            document.getElementById('selectedParentId').value = '';
            document.getElementById('selectedParentName').value = '';
            const display = document.getElementById('selectedParentDisplay');
            if (display) {
                display.innerHTML = 'No parent/guardian selected';
                display.style.color = '#6c757d';
            }
        }

        // ================================================
        // ADD GUARDIAN TO LIST
        // ================================================
        let guardians = [];

        function addGuardian() {
            const firstName = document.querySelector('[name="guardian_first_name"]')?.value?.trim();
            const lastName = document.querySelector('[name="guardian_last_name"]')?.value?.trim();
            const relationship = document.querySelector('[name="guardian_relationship_type"]')?.value;
            const phone = document.querySelector('[name="guardian_phone"]')?.value?.trim();

            if (!firstName || !lastName || !relationship) {
                showAlert('Please fill in First Name, Last Name, and Relationship Type.', 'warning');
                return;
            }

            const guardian = {
                id: 'g_' + Date.now(),
                first_name: firstName,
                last_name: lastName,
                relationship: relationship,
                phone: phone || 'N/A',
                is_primary: document.querySelector('[name="is_primary_guardian"]')?.checked || false,
                is_emergency: document.querySelector('[name="is_emergency_contact"]')?.checked || false
            };

            guardians.push(guardian);
            renderGuardianList();

            // Clear form
            document.querySelectorAll('[name^="guardian_"]').forEach(el => {
                if (el.type === 'checkbox') {
                    el.checked = false;
                } else {
                    el.value = '';
                }
            });

            showAlert('Guardian added successfully!', 'success');
        }

        function removeGuardian(id) {
            guardians = guardians.filter(g => g.id !== id);
            renderGuardianList();
        }

        function renderGuardianList() {
            const container = document.getElementById('guardianList');
            if (!container) return;

            if (guardians.length === 0) {
                container.innerHTML =
                    '<i class="fas fa-info-circle me-1"></i> No guardians added yet. Search and select or create a new guardian above.';
                return;
            }

            let html = '<div class="table-responsive"><table class="table table-sm table-hover">';
            html += '<thead><tr><th>Name</th><th>Relationship</th><th>Phone</th><th>Primary</th><th>Emergency</th><th></th></tr></thead><tbody>';

            guardians.forEach(g => {
                html += `<tr>
                    <td><strong>${g.first_name} ${g.last_name}</strong></td>
                    <td><span class="badge bg-info">${g.relationship}</span></td>
                    <td>${g.phone}</td>
                    <td>${g.is_primary ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'}</td>
                    <td>${g.is_emergency ? '<span class="badge bg-warning">Yes</span>' : '<span class="badge bg-secondary">No</span>'}</td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeGuardian('${g.id}')">
                            <i class="fas fa-times"></i>
                        </button>
                    </td>
                </tr>`;
            });

            html += '</tbody></table></div>';
            container.innerHTML = html;
        }

        // ================================================
        // ADD DOCUMENT ROW
        // ================================================
        function addDocumentRow() {
            const container = document.getElementById('documentsContainer');
            if (!container) return;

            const emptyMsg = container.querySelector('.text-muted');
            if (emptyMsg) emptyMsg.remove();

            const html = `
                <div class="document-row row g-2 mb-2">
                    <div class="col-md-3 col-12">
                        <select class="form-select form-select-sm" name="document_type[]">
                            <option value="">Select Type</option>
                            <option value="ghana_card">Ghana Card</option>
                            <option value="birth_certificate">Birth Certificate</option>
                            <option value="passport">Passport</option>
                            <option value="nhis">NHIS / Health Insurance</option>
                            <option value="school_id">School Identification</option>
                            <option value="previous_school_record">Previous School Record</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-3 col-12">
                        <input type="text" class="form-control form-control-sm" name="document_number[]" placeholder="Document Number">
                    </div>
                    <div class="col-md-2 col-12">
                        <input type="date" class="form-control form-control-sm" name="document_issue_date[]" placeholder="Issue Date">
                    </div>
                    <div class="col-md-2 col-12">
                        <input type="date" class="form-control form-control-sm" name="document_expiry[]" placeholder="Expiry Date">
                    </div>
                    <div class="col-md-1 col-12">
                        <input type="file" class="form-control form-control-sm" name="document_file[]" accept=".pdf,.jpg,.png">
                    </div>
                    <div class="col-md-1 col-12">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeDocumentRow(this)">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', html);
        }

        function removeDocumentRow(btn) {
            const row = btn.closest('.document-row');
            if (row) row.remove();

            const container = document.getElementById('documentsContainer');
            if (container && container.querySelectorAll('.document-row').length === 0) {
                container.innerHTML = `
                    <div class="text-muted text-center py-2">
                        <i class="fas fa-plus-circle me-1"></i>
                        Click "Add Document" to upload identity documents.
                    </div>
                `;
            }
        }

        // ================================================
        // TOGGLE ADMISSION TYPE
        // ================================================
        function toggleAdmissionType() {
            const type = document.getElementById('admissionType')?.value;
            const transferFields = document.getElementById('transferFields');
            if (type === 'transfer') {
                transferFields.style.display = 'block';
            } else {
                transferFields.style.display = 'none';
            }
        }

        // ================================================
        // INITIALIZE
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();

            // Load previous step data if available
            loadPreviousStepData(CURRENT_STEP);

            // Set up real-time validation
            const form = document.getElementById('stepForm');
            if (form) {
                const inputs = form.querySelectorAll('input, select, textarea');
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
            }

            // Set up photo preview
            const photoInput = document.getElementById('photoInput');
            if (photoInput) {
                photoInput.addEventListener('change', previewPhoto);
            }

            // Set up admission type toggle
            const admissionType = document.getElementById('admissionType');
            if (admissionType) {
                admissionType.addEventListener('change', toggleAdmissionType);
            }

            // Set up document add button
            const addDocBtn = document.getElementById('addDocumentBtn');
            if (addDocBtn) {
                addDocBtn.addEventListener('click', addDocumentRow);
            }

            // Set up parent search
            const searchBtn = document.getElementById('searchParentBtn');
            if (searchBtn) {
                searchBtn.addEventListener('click', searchParent);
            }
            const parentSearchInput = document.getElementById('parentSearch');
            if (parentSearchInput) {
                parentSearchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        searchParent();
                    }
                });
            }
        });
    </script>
</body>

</html>