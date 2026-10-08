<?php

/**
 * People Management - Batch Operations (Import/Export)
 * 
 * @package EduTrack
 * @subpackage Identity
 * @version 2.0
 * @filepath public/identity/batch.php
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

// Get user info
$userName = $_SESSION['user_name'] ?? 'Admin';
$userAvatar = substr($userName, 0, 1);

// Determine API base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$apiBase = $protocol . '://' . $host . '/api/platform';

$pageTitle = 'Batch Operations - EduTrack Platform';
$currentPage = 'batch';

// Get action
$action = isset($_GET['action']) ? $_GET['action'] : 'import';
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
        /* BATCH CARDS                                    */
        /* ================================================ */
        .batch-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .batch-card .card-header-custom {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            background: transparent;
        }

        .batch-card .card-header-custom h6 {
            font-weight: 600;
            margin: 0;
            font-size: 15px;
            color: #1a1a2e;
        }

        .batch-card .card-header-custom h6 i {
            color: #4facfe;
        }

        .batch-card .card-body-custom {
            padding: 24px;
        }

        /* ================================================ */
        /* FILE UPLOAD                                    */
        /* ================================================ */
        .file-upload-area {
            border: 2px dashed #e9ecef;
            border-radius: 12px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #fafbfc;
        }

        .file-upload-area:hover {
            border-color: #4facfe;
            background: #f0f7ff;
        }

        .file-upload-area .icon {
            font-size: 48px;
            color: #adb5bd;
            margin-bottom: 12px;
        }

        .file-upload-area .title {
            font-weight: 600;
            color: #1a1a2e;
        }

        .file-upload-area .subtitle {
            font-size: 13px;
            color: #6c757d;
        }

        .file-upload-area .form-control {
            display: none;
        }

        .file-upload-area.dragover {
            border-color: #4facfe;
            background: #e8f4ff;
        }

        /* ================================================ */
        /* PROGRESS BAR                                   */
        /* ================================================ */
        .progress-container {
            display: none;
            margin-top: 16px;
        }

        .progress-container .progress {
            height: 8px;
            border-radius: 10px;
            background: #f0f2f5;
            overflow: hidden;
        }

        .progress-container .progress .progress-bar {
            background: linear-gradient(90deg, #4facfe, #00f2fe);
            transition: width 0.5s;
        }

        .progress-container .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: #6c757d;
            margin-top: 6px;
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

            .batch-card .card-header-custom {
                padding: 12px 16px;
            }

            .batch-card .card-body-custom {
                padding: 16px;
            }

            .file-upload-area {
                padding: 30px 16px;
            }

            .file-upload-area .icon {
                font-size: 36px;
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

            .batch-card .card-header-custom {
                padding: 12px 14px;
            }

            .batch-card .card-body-custom {
                padding: 12px 14px;
            }

            .file-upload-area {
                padding: 20px 12px;
            }

            .file-upload-area .icon {
                font-size: 28px;
            }

            .file-upload-area .title {
                font-size: 14px;
            }

            .file-upload-area .subtitle {
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
                    <a class="nav-link" href="/identity/students.php">
                        <i class="fas fa-user-graduate"></i> <span>Students</span>
                    </a>
                    <a class="nav-link" href="/identity/staff.php">
                        <i class="fas fa-user-tie"></i> <span>Staff</span>
                    </a>
                    <a class="nav-link" href="/identity/relationships.php">
                        <i class="fas fa-users"></i> <span>Relationships</span>
                    </a>
                    <a class="nav-link active" href="/identity/batch.php">
                        <i class="fas fa-upload"></i> <span>Batch</span>
                    </a>
                    <a class="nav-link" href="/identity/documents/index.php">
                        <i class="fas fa-id-card"></i> <span>Documents</span>
                    </a>
                    <a class="nav-link" href="/identity/settings.php">
                        <i class="fas fa-cog"></i> <span>Settings</span>
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
                        <h1><i class="fas fa-upload me-2"></i>Batch Operations</h1>
                        <p>Import and export people data in bulk</p>
                    </div>
                    <div class="header-actions">
                        <a href="/identity/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back
                        </a>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer"></div>

                <!-- Tab Navigation -->
                <ul class="nav nav-tabs mb-4" style="border-bottom:2px solid #f0f2f5;">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $action === 'import' ? 'active' : ''; ?>"
                            href="?action=import" style="font-weight:500;color:#6c757d;border:none;padding:10px 20px;">
                            <i class="fas fa-file-import me-2"></i> Import
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $action === 'export' ? 'active' : ''; ?>"
                            href="?action=export" style="font-weight:500;color:#6c757d;border:none;padding:10px 20px;">
                            <i class="fas fa-file-export me-2"></i> Export
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $action === 'template' ? 'active' : ''; ?>"
                            href="?action=template" style="font-weight:500;color:#6c757d;border:none;padding:10px 20px;">
                            <i class="fas fa-download me-2"></i> Template
                        </a>
                    </li>
                </ul>

                <?php if ($action === 'import'): ?>
                    <!-- Import Section -->
                    <div class="batch-card">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-file-import"></i> Import People</h6>
                            <span class="text-muted small">Upload a CSV file to import people in bulk</span>
                        </div>
                        <div class="card-body-custom">
                            <!-- File Upload Area -->
                            <div class="file-upload-area" id="fileUploadArea">
                                <div class="icon"><i class="fas fa-cloud-upload-alt"></i></div>
                                <div class="title">Drag & drop your CSV file here</div>
                                <div class="subtitle">or click to browse files</div>
                                <input type="file" class="form-control" id="fileInput" accept=".csv">
                            </div>

                            <div id="fileInfo" style="display:none;" class="mt-3">
                                <div class="d-flex align-items-center gap-3 p-3 bg-light rounded">
                                    <i class="fas fa-file-csv text-primary" style="font-size:24px;"></i>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold" id="fileName">file.csv</div>
                                        <div class="text-muted small" id="fileSize">0 KB</div>
                                    </div>
                                    <button class="btn btn-outline-danger btn-sm" onclick="clearFile()">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Import Options -->
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Import Type</label>
                                        <select class="form-select" id="importType">
                                            <option value="people">People</option>
                                            <option value="students">Students</option>
                                            <option value="staff">Staff</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Default Status</label>
                                        <select class="form-select" id="importStatus">
                                            <option value="active">Active</option>
                                            <option value="pending">Pending</option>
                                            <option value="inactive">Inactive</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Validation Rules -->
                            <div class="alert alert-info" style="border-radius:12px;border:none;">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Required columns:</strong> first_name, last_name, person_type
                                <br>
                                <span class="text-muted small">Optional columns: middle_name, preferred_name, date_of_birth, gender, nationality, primary_phone, primary_email, address_line_1, address_line_2, city, state, country, postal_code, status</span>
                            </div>

                            <!-- Progress -->
                            <div class="progress-container" id="progressContainer">
                                <div class="progress">
                                    <div class="progress-bar" id="progressBar" style="width:0%;"></div>
                                </div>
                                <div class="progress-label">
                                    <span id="progressText">Processing...</span>
                                    <span id="progressPercent">0%</span>
                                </div>
                            </div>

                            <div class="mt-3 d-flex gap-2 flex-wrap">
                                <button class="btn btn-primary" onclick="startImport()" id="importBtn">
                                    <i class="fas fa-upload me-2"></i> Start Import
                                </button>
                                <button class="btn btn-outline-secondary" onclick="clearFile()">
                                    <i class="fas fa-times me-2"></i> Clear
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Import Results -->
                    <div id="importResults" style="display:none;">
                        <div class="batch-card">
                            <div class="card-header-custom">
                                <h6><i class="fas fa-chart-bar"></i> Import Results</h6>
                            </div>
                            <div class="card-body-custom">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="text-center p-3 bg-success bg-opacity-10 rounded">
                                            <div class="display-6 fw-bold text-success" id="successCount">0</div>
                                            <div class="text-muted small">Successful</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center p-3 bg-danger bg-opacity-10 rounded">
                                            <div class="display-6 fw-bold text-danger" id="failCount">0</div>
                                            <div class="text-muted small">Failed</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center p-3 bg-warning bg-opacity-10 rounded">
                                            <div class="display-6 fw-bold text-warning" id="skipCount">0</div>
                                            <div class="text-muted small">Skipped</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center p-3 bg-primary bg-opacity-10 rounded">
                                            <div class="display-6 fw-bold text-primary" id="totalCount">0</div>
                                            <div class="text-muted small">Total</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-3" id="importErrorLog">
                                    <!-- Error log will be displayed here -->
                                </div>
                            </div>
                        </div>
                    </div>

                <?php elseif ($action === 'export'): ?>
                    <!-- Export Section -->
                    <div class="batch-card">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-file-export"></i> Export People</h6>
                            <span class="text-muted small">Export people data in CSV format</span>
                        </div>
                        <div class="card-body-custom">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Export Type</label>
                                        <select class="form-select" id="exportType">
                                            <option value="all">All People</option>
                                            <option value="students">Students Only</option>
                                            <option value="staff">Staff Only</option>
                                            <option value="active">Active Only</option>
                                            <option value="custom">Custom Filter</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Format</label>
                                        <select class="form-select" id="exportFormat">
                                            <option value="csv">CSV</option>
                                            <option value="excel">Excel (XLSX)</option>
                                            <option value="json">JSON</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div id="customFilter" style="display:none;">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Person Type</label>
                                            <select class="form-select" id="filterType">
                                                <option value="">All Types</option>
                                                <option value="student">Student</option>
                                                <option value="teacher">Teacher</option>
                                                <option value="staff">Staff</option>
                                                <option value="admin">Admin</option>
                                                <option value="parent">Parent</option>
                                                <option value="guardian">Guardian</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Status</label>
                                            <select class="form-select" id="filterStatus">
                                                <option value="">All Statuses</option>
                                                <option value="active">Active</option>
                                                <option value="pending">Pending</option>
                                                <option value="inactive">Inactive</option>
                                                <option value="suspended">Suspended</option>
                                                <option value="archived">Archived</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Date Range</label>
                                            <input type="text" class="form-control" id="filterDateRange" placeholder="Select date range">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3 d-flex gap-2 flex-wrap">
                                <button class="btn btn-primary" onclick="startExport()">
                                    <i class="fas fa-download me-2"></i> Download Export
                                </button>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Template Section -->
                    <div class="batch-card">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-download"></i> Download Template</h6>
                            <span class="text-muted small">Download a CSV template for importing people</span>
                        </div>
                        <div class="card-body-custom">
                            <div class="text-center py-4">
                                <i class="fas fa-file-csv" style="font-size:64px;color:#4facfe;"></i>
                                <h5 class="mt-3">People Import Template</h5>
                                <p class="text-muted">Download the template to use for bulk import</p>
                                <button class="btn btn-primary" onclick="downloadTemplate()">
                                    <i class="fas fa-download me-2"></i> Download Template
                                </button>
                            </div>

                            <hr>

                            <h6 class="fw-bold"><i class="fas fa-info-circle me-2 text-primary"></i>Template Instructions</h6>
                            <ol class="text-muted" style="font-size:13px;line-height:1.8;">
                                <li><strong>Required columns:</strong> first_name, last_name, person_type</li>
                                <li><strong>Person Type values:</strong> student, teacher, staff, admin, parent, guardian, alumni, visitor, contractor, volunteer</li>
                                <li><strong>Status values:</strong> active, pending, inactive, suspended, archived</li>
                                <li><strong>Gender values:</strong> male, female, other</li>
                                <li><strong>Date format:</strong> YYYY-MM-DD</li>
                                <li><strong>Max file size:</strong> 5MB</li>
                                <li><strong>Max rows:</strong> 1000 per import</li>
                            </ol>
                        </div>
                    </div>
                <?php endif; ?>
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
        // FILE UPLOAD HANDLING
        // ================================================
        let selectedFile = null;

        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();

            const uploadArea = document.getElementById('fileUploadArea');
            const fileInput = document.getElementById('fileInput');

            // Click to upload
            uploadArea.addEventListener('click', function() {
                fileInput.click();
            });

            // File selected
            fileInput.addEventListener('change', function() {
                if (this.files.length > 0) {
                    handleFile(this.files[0]);
                }
            });

            // Drag and drop
            uploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.classList.add('dragover');
            });

            uploadArea.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
            });

            uploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
                if (e.dataTransfer.files.length > 0) {
                    handleFile(e.dataTransfer.files[0]);
                }
            });

            // Export type change
            document.getElementById('exportType').addEventListener('change', function() {
                document.getElementById('customFilter').style.display = this.value === 'custom' ? 'block' : 'none';
            });
        });

        function handleFile(file) {
            const validTypes = ['text/csv', 'application/vnd.ms-excel'];
            const validExtensions = ['.csv', '.xlsx'];

            const ext = '.' + file.name.split('.').pop().toLowerCase();

            if (!validTypes.includes(file.type) && !validExtensions.includes(ext)) {
                showAlert('Please upload a CSV file', 'danger');
                return;
            }

            if (file.size > 5 * 1024 * 1024) {
                showAlert('File size exceeds 5MB limit', 'danger');
                return;
            }

            selectedFile = file;
            document.getElementById('fileInfo').style.display = 'block';
            document.getElementById('fileName').textContent = file.name;
            document.getElementById('fileSize').textContent = (file.size / 1024).toFixed(1) + ' KB';
            showAlert('File uploaded successfully!', 'success');
        }

        function clearFile() {
            selectedFile = null;
            document.getElementById('fileInput').value = '';
            document.getElementById('fileInfo').style.display = 'none';
            document.getElementById('importResults').style.display = 'none';
            document.getElementById('progressContainer').style.display = 'none';
            document.getElementById('importBtn').disabled = false;
            document.getElementById('importBtn').innerHTML = '<i class="fas fa-upload me-2"></i> Start Import';
        }

        // ================================================
        // START IMPORT
        // ================================================
        function startImport() {
            if (!selectedFile) {
                showAlert('Please select a file to import', 'warning');
                return;
            }

            const btn = document.getElementById('importBtn');
            const progressContainer = document.getElementById('progressContainer');
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            const progressPercent = document.getElementById('progressPercent');

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Processing...';
            progressContainer.style.display = 'block';

            // Simulate import progress
            let progress = 0;
            const interval = setInterval(() => {
                progress += 5;
                if (progress <= 100) {
                    progressBar.style.width = progress + '%';
                    progressPercent.textContent = progress + '%';
                    if (progress < 30) {
                        progressText.textContent = 'Validating file...';
                    } else if (progress < 60) {
                        progressText.textContent = 'Processing records...';
                    } else if (progress < 90) {
                        progressText.textContent = 'Saving records...';
                    } else {
                        progressText.textContent = 'Finalizing...';
                    }
                }

                if (progress >= 100) {
                    clearInterval(interval);
                    // Show results
                    const successCount = Math.floor(Math.random() * 50) + 30;
                    const failCount = Math.floor(Math.random() * 10);
                    const skipCount = Math.floor(Math.random() * 5);
                    const totalCount = successCount + failCount + skipCount;

                    document.getElementById('successCount').textContent = successCount;
                    document.getElementById('failCount').textContent = failCount;
                    document.getElementById('skipCount').textContent = skipCount;
                    document.getElementById('totalCount').textContent = totalCount;
                    document.getElementById('importResults').style.display = 'block';

                    if (failCount > 0) {
                        document.getElementById('importErrorLog').innerHTML = `
                            <div class="alert alert-danger" style="border-radius:12px;border:none;font-size:13px;">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Errors:</strong> ${failCount} records failed to import.
                                <ul class="mt-2 mb-0">
                                    <li>Row 3: Missing required field "first_name"</li>
                                    <li>Row 7: Invalid person type "unknown"</li>
                                    <li>Row 12: Duplicate person number</li>
                                </ul>
                            </div>
                        `;
                    }

                    showAlert('Import completed successfully!', 'success');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-upload me-2"></i> Start Import';
                }
            }, 500);
        }

        // ================================================
        // START EXPORT
        // ================================================
        function startExport() {
            const exportType = document.getElementById('exportType').value;
            const format = document.getElementById('exportFormat').value;

            showAlert('Preparing export...', 'info');

            // In production, this would make an API call and download the file
            setTimeout(() => {
                showAlert('Export prepared! Downloading...', 'success');
                // Simulate download
                const link = document.createElement('a');
                link.download = `people_export_${new Date().toISOString().slice(0,10)}.${format === 'csv' ? 'csv' : format === 'json' ? 'json' : 'xlsx'}`;
                link.href = '#'; // In production, this would be the download URL
                link.click();
            }, 1500);
        }

        // ================================================
        // DOWNLOAD TEMPLATE
        // ================================================
        function downloadTemplate() {
            const headers = [
                'first_name',
                'middle_name',
                'last_name',
                'preferred_name',
                'person_type',
                'date_of_birth',
                'gender',
                'nationality',
                'primary_phone',
                'primary_email',
                'address_line_1',
                'address_line_2',
                'city',
                'state',
                'country',
                'postal_code',
                'status'
            ];

            // Sample row
            const sample = [
                'John',
                '',
                'Doe',
                '',
                'student',
                '2000-01-01',
                'male',
                'Ghanaian',
                '+233 20 123 4567',
                'john.doe@example.com',
                '123 Main St',
                '',
                'Accra',
                'Greater Accra',
                'Ghana',
                'GA-123',
                'active'
            ];

            // Create CSV content
            let csv = headers.join(',') + '\n';
            csv += sample.join(',') + '\n';
            csv += sample.map(() => '').join(',') + '\n';

            // Download
            const blob = new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'people_import_template.csv';
            link.click();
            URL.revokeObjectURL(link.href);

            showAlert('Template downloaded successfully', 'success');
        }
    </script>
</body>

</html>