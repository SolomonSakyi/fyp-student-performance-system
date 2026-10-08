<?php

/**
 * People Management - Settings
 * 
 * @package EduTrack
 * @subpackage Identity
 * @version 2.0
 * @filepath public/identity/settings.php
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

$pageTitle = 'Identity Settings - EduTrack Platform';
$currentPage = 'identity_settings';
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
        /* SETTINGS CARDS                                 */
        /* ================================================ */
        .settings-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .settings-card .card-header-custom {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            background: transparent;
        }

        .settings-card .card-header-custom h6 {
            font-weight: 600;
            margin: 0;
            font-size: 15px;
            color: #1a1a2e;
        }

        .settings-card .card-header-custom h6 i {
            color: #4facfe;
        }

        .settings-card .card-body-custom {
            padding: 24px;
        }

        /* ================================================ */
        /* SETTING ITEMS                                  */
        /* ================================================ */
        .setting-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f8f9fa;
            gap: 16px;
        }

        .setting-item:last-child {
            border-bottom: none;
        }

        .setting-item .setting-info {
            flex: 1;
        }

        .setting-item .setting-info .label {
            font-weight: 500;
            font-size: 14px;
            color: #1a1a2e;
        }

        .setting-item .setting-info .description {
            font-size: 13px;
            color: #6c757d;
            margin-top: 2px;
        }

        .setting-item .setting-control .form-control,
        .setting-item .setting-control .form-select {
            border-radius: 10px;
            padding: 8px 14px;
            border: 2px solid #e9ecef;
            font-size: 13px;
            height: 40px;
            min-width: 200px;
        }

        .setting-item .setting-control .form-control:focus,
        .setting-item .setting-control .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 4px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .setting-item .setting-control .form-check {
            padding-left: 0;
        }

        .setting-item .setting-control .form-check-input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .setting-item .setting-control .form-check-input:checked {
            background-color: #4facfe;
            border-color: #4facfe;
        }

        /* ================================================ */
        /* TAG/LIST ITEMS                                 */
        /* ================================================ */
        .tag-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f0f2f5;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 13px;
            margin: 3px 4px;
        }

        .tag-item .remove-tag {
            cursor: pointer;
            color: #dc3545;
            font-size: 12px;
            margin-left: 4px;
        }

        .tag-item .remove-tag:hover {
            color: #bd2130;
        }

        /* ================================================ */
        /* EMPTY STATE                                    */
        /* ================================================ */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }

        .empty-state .empty-icon {
            font-size: 40px;
            color: #dee2e6;
            margin-bottom: 12px;
        }

        .empty-state h6 {
            font-weight: 600;
            color: #1a1a2e;
        }

        .empty-state p {
            color: #6c757d;
            font-size: 13px;
            margin-bottom: 0;
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

            .settings-card .card-header-custom {
                padding: 12px 16px;
            }

            .settings-card .card-body-custom {
                padding: 16px;
            }

            .setting-item {
                flex-direction: column;
                align-items: stretch;
            }

            .setting-item .setting-control .form-control,
            .setting-item .setting-control .form-select {
                min-width: auto;
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

            .settings-card .card-header-custom {
                padding: 12px 14px;
            }

            .settings-card .card-body-custom {
                padding: 12px 14px;
            }

            .setting-item .setting-info .label {
                font-size: 13px;
            }

            .setting-item .setting-info .description {
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
                    <a class="nav-link" href="/identity/documents/index.php">
                        <i class="fas fa-id-card"></i> <span>Documents</span>
                    </a>
                    <a class="nav-link active" href="/identity/settings.php">
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
                        <h1><i class="fas fa-cog me-2"></i>Identity Settings</h1>
                        <p>Configure person types, document types, and other identity settings</p>
                    </div>
                    <div class="header-actions">
                        <button class="btn btn-outline-secondary" onclick="refreshSettings()">
                            <i class="fas fa-sync-alt me-2"></i> Refresh
                        </button>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer"></div>

                <!-- Person Types Settings -->
                <div class="settings-card">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-tags"></i> Person Types</h6>
                        <span class="text-muted small">Configure the types of people in the system</span>
                    </div>
                    <div class="card-body-custom">
                        <div id="personTypesContainer">
                            <div class="d-flex flex-wrap gap-1" id="personTypesList">
                                <span class="tag-item">Student <span class="remove-tag" onclick="removePersonType('student')">&times;</span></span>
                                <span class="tag-item">Teacher <span class="remove-tag" onclick="removePersonType('teacher')">&times;</span></span>
                                <span class="tag-item">Staff <span class="remove-tag" onclick="removePersonType('staff')">&times;</span></span>
                                <span class="tag-item">Administrator <span class="remove-tag" onclick="removePersonType('admin')">&times;</span></span>
                                <span class="tag-item">Parent <span class="remove-tag" onclick="removePersonType('parent')">&times;</span></span>
                                <span class="tag-item">Guardian <span class="remove-tag" onclick="removePersonType('guardian')">&times;</span></span>
                                <span class="tag-item">Alumni <span class="remove-tag" onclick="removePersonType('alumni')">&times;</span></span>
                                <span class="tag-item">Visitor <span class="remove-tag" onclick="removePersonType('visitor')">&times;</span></span>
                                <span class="tag-item">Contractor <span class="remove-tag" onclick="removePersonType('contractor')">&times;</span></span>
                                <span class="tag-item">Volunteer <span class="remove-tag" onclick="removePersonType('volunteer')">&times;</span></span>
                            </div>
                            <div class="mt-3">
                                <div class="input-group" style="max-width:400px;">
                                    <input type="text" class="form-control" id="newPersonType" placeholder="Enter new person type...">
                                    <button class="btn btn-primary" onclick="addPersonType()">
                                        <i class="fas fa-plus"></i> Add
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Document Types Settings -->
                <div class="settings-card">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-file-alt"></i> Document Types</h6>
                        <span class="text-muted small">Configure the types of identity documents</span>
                    </div>
                    <div class="card-body-custom">
                        <div id="documentTypesContainer">
                            <div class="d-flex flex-wrap gap-1" id="documentTypesList">
                                <span class="tag-item">Ghana Card <span class="remove-tag" onclick="removeDocumentType('ghana_card')">&times;</span></span>
                                <span class="tag-item">Passport <span class="remove-tag" onclick="removeDocumentType('passport')">&times;</span></span>
                                <span class="tag-item">Birth Certificate <span class="remove-tag" onclick="removeDocumentType('birth_certificate')">&times;</span></span>
                                <span class="tag-item">National ID <span class="remove-tag" onclick="removeDocumentType('national_id')">&times;</span></span>
                                <span class="tag-item">Staff ID <span class="remove-tag" onclick="removeDocumentType('staff_id')">&times;</span></span>
                                <span class="tag-item">Student ID <span class="remove-tag" onclick="removeDocumentType('student_id')">&times;</span></span>
                                <span class="tag-item">Driver's License <span class="remove-tag" onclick="removeDocumentType('drivers_license')">&times;</span></span>
                                <span class="tag-item">Voter ID <span class="remove-tag" onclick="removeDocumentType('voter_id')">&times;</span></span>
                            </div>
                            <div class="mt-3">
                                <div class="input-group" style="max-width:400px;">
                                    <input type="text" class="form-control" id="newDocumentType" placeholder="Enter new document type...">
                                    <button class="btn btn-primary" onclick="addDocumentType()">
                                        <i class="fas fa-plus"></i> Add
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Relationship Types Settings -->
                <div class="settings-card">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-users"></i> Relationship Types</h6>
                        <span class="text-muted small">Configure relationship types between people</span>
                    </div>
                    <div class="card-body-custom">
                        <div id="relationshipTypesContainer">
                            <div class="d-flex flex-wrap gap-1" id="relationshipTypesList">
                                <span class="tag-item">Parent <span class="remove-tag" onclick="removeRelationshipType('parent')">&times;</span></span>
                                <span class="tag-item">Guardian <span class="remove-tag" onclick="removeRelationshipType('guardian')">&times;</span></span>
                                <span class="tag-item">Sibling <span class="remove-tag" onclick="removeRelationshipType('sibling')">&times;</span></span>
                                <span class="tag-item">Emergency Contact <span class="remove-tag" onclick="removeRelationshipType('emergency_contact')">&times;</span></span>
                                <span class="tag-item">Spouse <span class="remove-tag" onclick="removeRelationshipType('spouse')">&times;</span></span>
                                <span class="tag-item">Child <span class="remove-tag" onclick="removeRelationshipType('child')">&times;</span></span>
                                <span class="tag-item">Other <span class="remove-tag" onclick="removeRelationshipType('other')">&times;</span></span>
                            </div>
                            <div class="mt-3">
                                <div class="input-group" style="max-width:400px;">
                                    <input type="text" class="form-control" id="newRelationshipType" placeholder="Enter new relationship type...">
                                    <button class="btn btn-primary" onclick="addRelationshipType()">
                                        <i class="fas fa-plus"></i> Add
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- General Settings -->
                <div class="settings-card">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-sliders-h"></i> General Settings</h6>
                        <span class="text-muted small">Configure general identity management settings</span>
                    </div>
                    <div class="card-body-custom">
                        <div class="setting-item">
                            <div class="setting-info">
                                <div class="label">Auto-generate Person Numbers</div>
                                <div class="description">Automatically generate unique person numbers for new people</div>
                            </div>
                            <div class="setting-control">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="autoGenerateNumbers" checked>
                                </div>
                            </div>
                        </div>
                        <div class="setting-item">
                            <div class="setting-info">
                                <div class="label">Require Person Number</div>
                                <div class="description">Make person number a required field</div>
                            </div>
                            <div class="setting-control">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="requirePersonNumber" checked>
                                </div>
                            </div>
                        </div>
                        <div class="setting-item">
                            <div class="setting-info">
                                <div class="label">Person Number Format</div>
                                <div class="description">Format for auto-generated person numbers</div>
                            </div>
                            <div class="setting-control">
                                <select class="form-select" id="personNumberFormat">
                                    <option value="P-YYYY-####">P-YYYY-####</option>
                                    <option value="P-YY-####">P-YY-####</option>
                                    <option value="YYYY-####">YYYY-####</option>
                                    <option value="P#######">P#######</option>
                                </select>
                            </div>
                        </div>
                        <div class="setting-item">
                            <div class="setting-info">
                                <div class="label">Default Person Status</div>
                                <div class="description">Default status for newly created people</div>
                            </div>
                            <div class="setting-control">
                                <select class="form-select" id="defaultStatus">
                                    <option value="active">Active</option>
                                    <option value="pending">Pending</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Save Button -->
                <div class="d-flex justify-content-end gap-2">
                    <button class="btn btn-outline-secondary" onclick="resetSettings()">
                        <i class="fas fa-undo me-2"></i> Reset
                    </button>
                    <button class="btn btn-primary" onclick="saveSettings()">
                        <i class="fas fa-save me-2"></i> Save Settings
                    </button>
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
        // PERSON TYPES
        // ================================================
        function addPersonType() {
            const input = document.getElementById('newPersonType');
            const value = input.value.trim();
            if (!value) return;

            const list = document.getElementById('personTypesList');
            const tag = document.createElement('span');
            tag.className = 'tag-item';
            tag.innerHTML = `${value} <span class="remove-tag" onclick="this.parentElement.remove()">&times;</span>`;
            list.appendChild(tag);
            input.value = '';
            showAlert('Person type added successfully', 'success');
        }

        function removePersonType(type) {
            const items = document.querySelectorAll('#personTypesList .tag-item');
            items.forEach(item => {
                if (item.textContent.trim().toLowerCase() === type.toLowerCase()) {
                    item.remove();
                }
            });
            showAlert('Person type removed', 'info');
        }

        // ================================================
        // DOCUMENT TYPES
        // ================================================
        function addDocumentType() {
            const input = document.getElementById('newDocumentType');
            const value = input.value.trim();
            if (!value) return;

            const list = document.getElementById('documentTypesList');
            const tag = document.createElement('span');
            tag.className = 'tag-item';
            tag.innerHTML = `${value} <span class="remove-tag" onclick="this.parentElement.remove()">&times;</span>`;
            list.appendChild(tag);
            input.value = '';
            showAlert('Document type added successfully', 'success');
        }

        function removeDocumentType(type) {
            const items = document.querySelectorAll('#documentTypesList .tag-item');
            items.forEach(item => {
                if (item.textContent.trim().toLowerCase() === type.toLowerCase()) {
                    item.remove();
                }
            });
            showAlert('Document type removed', 'info');
        }

        // ================================================
        // RELATIONSHIP TYPES
        // ================================================
        function addRelationshipType() {
            const input = document.getElementById('newRelationshipType');
            const value = input.value.trim();
            if (!value) return;

            const list = document.getElementById('relationshipTypesList');
            const tag = document.createElement('span');
            tag.className = 'tag-item';
            tag.innerHTML = `${value} <span class="remove-tag" onclick="this.parentElement.remove()">&times;</span>`;
            list.appendChild(tag);
            input.value = '';
            showAlert('Relationship type added successfully', 'success');
        }

        function removeRelationshipType(type) {
            const items = document.querySelectorAll('#relationshipTypesList .tag-item');
            items.forEach(item => {
                if (item.textContent.trim().toLowerCase() === type.toLowerCase()) {
                    item.remove();
                }
            });
            showAlert('Relationship type removed', 'info');
        }

        // ================================================
        // SAVE SETTINGS
        // ================================================
        function saveSettings() {
            const btn = event ? event.target : document.querySelector('.btn-primary');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Saving...';
            }

            // Collect settings data
            const settings = {
                auto_generate_numbers: document.getElementById('autoGenerateNumbers').checked ? 1 : 0,
                require_person_number: document.getElementById('requirePersonNumber').checked ? 1 : 0,
                person_number_format: document.getElementById('personNumberFormat').value,
                default_status: document.getElementById('defaultStatus').value,
                person_types: getTagsList('personTypesList'),
                document_types: getTagsList('documentTypesList'),
                relationship_types: getTagsList('relationshipTypesList')
            };

            // In production, this would POST to the settings API
            setTimeout(() => {
                showAlert('Settings saved successfully!', 'success');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-save me-2"></i> Save Settings';
                }
            }, 1000);
        }

        // ================================================
        // RESET SETTINGS
        // ================================================
        function resetSettings() {
            if (!confirm('Reset all settings to defaults?')) return;

            // Reload the page to reset
            window.location.reload();
        }

        // ================================================
        // REFRESH SETTINGS
        // ================================================
        function refreshSettings() {
            showAlert('Settings refreshed', 'info');
        }

        // ================================================
        // GET TAGS LIST
        // ================================================
        function getTagsList(containerId) {
            const container = document.getElementById(containerId);
            const tags = container.querySelectorAll('.tag-item');
            const items = [];
            tags.forEach(tag => {
                const text = tag.textContent.trim();
                // Remove the × character and any whitespace
                const clean = text.replace(/×$/, '').trim();
                if (clean) items.push(clean);
            });
            return items;
        }

        // ================================================
        // INITIALIZE
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();

            // Enter key support for inputs
            document.getElementById('newPersonType').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    addPersonType();
                }
            });

            document.getElementById('newDocumentType').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    addDocumentType();
                }
            });

            document.getElementById('newRelationshipType').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    addRelationshipType();
                }
            });
        });
    </script>
</body>

</html>