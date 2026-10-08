<?php

/**
 * People Management - Relationships (Parent/Guardian Management)
 * 
 * @package EduTrack
 * @subpackage Identity
 * @version 2.0
 * @filepath public/identity/relationships.php
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

$pageTitle = 'Relationships - EduTrack Platform';
$currentPage = 'relationships';

// Get action and parameters
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$personId = isset($_GET['person_id']) ? (int)$_GET['person_id'] : 0;
$relationshipId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
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
        /* TABLE CARD                                     */
        /* ================================================ */
        .table-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            overflow: hidden;
        }

        .table-card .table-header {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .table-card .table-header h6 {
            font-weight: 600;
            margin: 0;
            font-size: 15px;
            color: #1a1a2e;
        }

        .table-card .table-body {
            padding: 0;
            overflow-x: auto;
        }

        .table-card .table-body table {
            margin: 0;
            width: 100%;
        }

        .table-card .table-body table th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            padding: 12px 16px;
            border-bottom: 2px solid #f0f2f5;
            white-space: nowrap;
        }

        .table-card .table-body table td {
            padding: 12px 16px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .table-card .table-body table tr:last-child td {
            border-bottom: none;
        }

        .table-card .table-body table tr:hover td {
            background: #f8f9fa;
        }

        /* ================================================ */
        /* CARD - Person Info                             */
        /* ================================================ */
        .person-card {
            background: #fff;
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .person-card .avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
            color: #fff;
            flex-shrink: 0;
        }

        .person-card .info {
            flex: 1;
        }

        .person-card .info .name {
            font-weight: 600;
            font-size: 16px;
            color: #1a1a2e;
        }

        .person-card .info .details {
            font-size: 13px;
            color: #6c757d;
        }

        /* ================================================ */
        /* RELATIONSHIP BADGE                             */
        /* ================================================ */
        .relationship-badge {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }

        .relationship-badge.parent {
            background: #cce5ff;
            color: #004085;
        }

        .relationship-badge.guardian {
            background: #fff3cd;
            color: #856404;
        }

        .relationship-badge.sibling {
            background: #d4edda;
            color: #155724;
        }

        .relationship-badge.emergency {
            background: #f8d7da;
            color: #721c24;
        }

        .relationship-badge.other {
            background: #e2e3e5;
            color: #383d41;
        }

        .badge-primary-role {
            background: #4facfe;
            color: #fff;
            font-size: 10px;
            padding: 2px 10px;
            border-radius: 12px;
            font-weight: 600;
        }

        /* ================================================ */
        /* EMPTY STATE                                    */
        /* ================================================ */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state .empty-icon {
            font-size: 56px;
            color: #dee2e6;
            margin-bottom: 16px;
        }

        .empty-state h5 {
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 8px;
        }

        .empty-state p {
            color: #6c757d;
            margin-bottom: 16px;
        }

        /* ================================================ */
        /* MODAL OVERRIDES                                */
        /* ================================================ */
        .modal-content {
            border-radius: 16px;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        }

        .modal-header {
            border-bottom: 1px solid #f0f2f5;
            padding: 20px 24px;
        }

        .modal-header .modal-title {
            font-weight: 600;
            font-size: 18px;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            border-top: 1px solid #f0f2f5;
            padding: 16px 24px;
        }

        .form-control,
        .form-select {
            border-radius: 10px;
            padding: 10px 14px;
            border: 2px solid #e9ecef;
            font-size: 13px;
            width: 100%;
            background: #fff;
            color: #1a1a2e;
            transition: all 0.3s;
            height: 44px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 4px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .form-label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            margin-bottom: 4px;
            display: block;
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

            .person-card {
                padding: 16px;
            }

            .table-card .table-body table {
                font-size: 13px;
            }

            .table-card .table-body table th,
            .table-card .table-body table td {
                padding: 8px 12px;
            }

            .modal-body {
                padding: 16px;
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

            .person-card {
                flex-direction: column;
                text-align: center;
            }

            .table-card .table-header {
                padding: 12px 14px;
            }

            .table-card .table-body table th,
            .table-card .table-body table td {
                padding: 6px 10px;
                font-size: 12px;
            }

            .relationship-badge {
                font-size: 10px;
                padding: 2px 8px;
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
                    <a class="nav-link active" href="/identity/relationships.php">
                        <i class="fas fa-users"></i> <span>Relationships</span>
                    </a>
                    <a class="nav-link" href="/identity/documents/index.php">
                        <i class="fas fa-id-card"></i> <span>Documents</span>
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
                        <h1><i class="fas fa-users me-2"></i>Relationships</h1>
                        <p>Manage relationships between people (students, parents, guardians)</p>
                    </div>
                    <div class="header-actions">
                        <a href="/identity/relationships.php?action=add" class="btn btn-primary" id="addRelationshipBtn">
                            <i class="fas fa-plus me-2"></i> Add Relationship
                        </a>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer"></div>

                <!-- Person Context (if viewing by person) -->
                <?php if ($personId > 0): ?>
                    <div class="person-card" id="personContext">
                        <div class="avatar" id="contextAvatar">S</div>
                        <div class="info">
                            <div class="name" id="contextName">Loading...</div>
                            <div class="details" id="contextDetails">Person ID: <?php echo $personId; ?></div>
                        </div>
                        <div>
                            <a href="/identity/view.php?id=<?php echo $personId; ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-external-link-alt me-1"></i> View Profile
                            </a>
                            <a href="/identity/relationships.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-times me-1"></i> Clear Filter
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Relationships Table -->
                <div class="table-card">
                    <div class="table-header">
                        <h6><i class="fas fa-list me-2 text-primary"></i>Relationships</h6>
                        <div class="table-actions">
                            <span class="text-muted small" id="relationshipCount">0 relationships</span>
                        </div>
                    </div>
                    <div class="table-body" id="relationshipsTableContainer">
                        <!-- Table will be rendered by JavaScript -->
                        <div class="text-center py-4 text-muted">
                            <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                            Loading relationships...
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- ================================================ -->
    <!-- ADD/EDIT RELATIONSHIP MODAL                     -->
    <!-- ================================================ -->
    <div class="modal fade" id="relationshipModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Relationship</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="relationshipForm" novalidate>
                        <input type="hidden" name="id" id="editId" value="0">

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Person <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                                        <input type="text" class="form-control" id="personSearch" placeholder="Search for person...">
                                        <input type="hidden" id="personId" name="person_id">
                                        <button class="btn btn-outline-secondary" type="button" id="clearPersonBtn">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                    <div id="personSearchResults" class="list-group mt-1" style="display:none;max-height:200px;overflow-y:auto;"></div>
                                    <div id="selectedPersonDisplay" class="mt-2" style="display:none;">
                                        <div class="d-flex align-items-center gap-2 p-2 bg-light rounded">
                                            <div class="avatar-sm" style="width:32px;height:32px;border-radius:50%;background:#4facfe;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;">A</div>
                                            <span id="selectedPersonName">Person Name</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Related Person <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                                        <input type="text" class="form-control" id="relatedPersonSearch" placeholder="Search for person...">
                                        <input type="hidden" id="relatedPersonId" name="related_person_id">
                                        <button class="btn btn-outline-secondary" type="button" id="clearRelatedPersonBtn">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                    <div id="relatedPersonSearchResults" class="list-group mt-1" style="display:none;max-height:200px;overflow-y:auto;"></div>
                                    <div id="selectedRelatedPersonDisplay" class="mt-2" style="display:none;">
                                        <div class="d-flex align-items-center gap-2 p-2 bg-light rounded">
                                            <div class="avatar-sm" style="width:32px;height:32px;border-radius:50%;background:#4facfe;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;">B</div>
                                            <span id="selectedRelatedPersonName">Person Name</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Relationship Type <span class="text-danger">*</span></label>
                                    <select class="form-select" id="relationshipType" name="relationship_type" required>
                                        <option value="">Select Type</option>
                                        <option value="parent">Parent</option>
                                        <option value="guardian">Guardian</option>
                                        <option value="sibling">Sibling</option>
                                        <option value="emergency_contact">Emergency Contact</option>
                                        <option value="spouse">Spouse</option>
                                        <option value="child">Child</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" id="relationshipStatus" name="status">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="pending">Pending</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Is Primary</label>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="isPrimary" name="is_primary" value="1">
                                        <label class="form-check-label" for="isPrimary">
                                            This is the primary relationship (e.g., primary parent/guardian)
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Additional notes about this relationship"></textarea>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveRelationshipBtn">
                        <i class="fas fa-save me-2"></i> Save Relationship
                    </button>
                </div>
            </div>
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
        const PERSON_ID = <?php echo $personId; ?>;
        const RELATIONSHIP_ID = <?php echo $relationshipId; ?>;

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
        // FORMAT HELPERS
        // ================================================
        function getFullName(person) {
            let name = person.first_name || '';
            if (person.middle_name) name += ' ' + person.middle_name;
            if (person.last_name) name += ' ' + person.last_name;
            return name.trim() || 'Unknown';
        }

        function getInitials(name) {
            if (!name) return '?';
            const parts = name.split(' ');
            if (parts.length >= 2) {
                return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
            }
            return name.charAt(0).toUpperCase();
        }

        function getRelationshipBadge(type) {
            const labels = {
                'parent': 'Parent',
                'guardian': 'Guardian',
                'sibling': 'Sibling',
                'emergency_contact': 'Emergency Contact',
                'spouse': 'Spouse',
                'child': 'Child',
                'other': 'Other'
            };
            const classes = {
                'parent': 'parent',
                'guardian': 'guardian',
                'sibling': 'sibling',
                'emergency_contact': 'emergency',
                'spouse': 'other',
                'child': 'other',
                'other': 'other'
            };
            return `<span class="relationship-badge ${classes[type] || 'other'}">${labels[type] || type}</span>`;
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
        // RELATIONSHIPS CONTROLLER
        // ================================================
        const RelationshipsController = {
            state: {
                relationships: [],
                person: null,
                personId: PERSON_ID,
                isLoading: false
            },

            init: function() {
                loadUserInfo();
                if (this.state.personId > 0) {
                    this.loadPerson();
                }
                this.loadRelationships();
                this.bindEvents();
            },

            loadPerson: function() {
                fetch(API_BASE + '/index.php?endpoint=identity&action=get&id=' + this.state.personId, {
                        headers: getHeaders()
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success && result.data) {
                            this.state.person = result.data;
                            this.renderPersonContext();
                        }
                    })
                    .catch(error => console.error('Error loading person:', error));
            },

            renderPersonContext: function() {
                const person = this.state.person;
                if (!person) return;

                document.getElementById('contextAvatar').textContent = getInitials(getFullName(person));
                document.getElementById('contextName').textContent = getFullName(person);
                document.getElementById('contextDetails').textContent =
                    `${person.person_type || 'Person'} • ${person.person_number || 'N/A'}`;
            },

            loadRelationships: function() {
                this.state.isLoading = true;
                const container = document.getElementById('relationshipsTableContainer');

                // For now, use placeholder data since the relationships API endpoint may not exist yet
                // In production, this would call: API_BASE + '/index.php?endpoint=relationships&action=list'
                // For demo purposes, show empty state with add button

                setTimeout(() => {
                    container.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-users"></i></div>
                            <h5>No Relationships Found</h5>
                            <p>Create relationships between people (students, parents, guardians, etc.)</p>
                            <button class="btn btn-primary" onclick="RelationshipsController.openAddModal()">
                                <i class="fas fa-plus me-2"></i> Add Relationship
                            </button>
                        </div>
                    `;
                    document.getElementById('relationshipCount').textContent = '0 relationships';
                    this.state.isLoading = false;
                }, 500);
            },

            bindEvents: function() {
                // Add relationship button
                document.getElementById('addRelationshipBtn').addEventListener('click', function(e) {
                    e.preventDefault();
                    RelationshipsController.openAddModal();
                });

                // Save relationship
                document.getElementById('saveRelationshipBtn').addEventListener('click', function() {
                    RelationshipsController.saveRelationship();
                });

                // Person search
                setupPersonSearch('personSearch', 'personSearchResults', 'personId', 'selectedPersonDisplay',
                    'selectedPersonName');
                setupPersonSearch('relatedPersonSearch', 'relatedPersonSearchResults', 'relatedPersonId',
                    'selectedRelatedPersonDisplay', 'selectedRelatedPersonName');

                // Clear buttons
                document.getElementById('clearPersonBtn').addEventListener('click', function() {
                    document.getElementById('personSearch').value = '';
                    document.getElementById('personSearchResults').style.display = 'none';
                    document.getElementById('selectedPersonDisplay').style.display = 'none';
                    document.getElementById('personId').value = '';
                });

                document.getElementById('clearRelatedPersonBtn').addEventListener('click', function() {
                    document.getElementById('relatedPersonSearch').value = '';
                    document.getElementById('relatedPersonSearchResults').style.display = 'none';
                    document.getElementById('selectedRelatedPersonDisplay').style.display = 'none';
                    document.getElementById('relatedPersonId').value = '';
                });
            },

            openAddModal: function(personId = null) {
                document.getElementById('modalTitle').textContent = 'Add Relationship';
                document.getElementById('editId').value = '0';
                document.getElementById('relationshipForm').reset();

                // If personId is provided, set the person
                if (personId) {
                    document.getElementById('personId').value = personId;
                    // Load and display person name
                    fetch(API_BASE + '/index.php?endpoint=identity&action=get&id=' + personId, {
                            headers: getHeaders()
                        })
                        .then(response => response.json())
                        .then(result => {
                            if (result.success && result.data) {
                                const person = result.data;
                                document.getElementById('personSearch').value = getFullName(person);
                                document.getElementById('selectedPersonName').textContent = getFullName(person);
                                document.getElementById('selectedPersonDisplay').style.display = 'block';
                            }
                        })
                        .catch(error => console.error('Error loading person:', error));
                }

                const modal = new bootstrap.Modal(document.getElementById('relationshipModal'));
                modal.show();
            },

            saveRelationship: function() {
                const form = document.getElementById('relationshipForm');
                const personId = document.getElementById('personId').value;
                const relatedPersonId = document.getElementById('relatedPersonId').value;
                const relationshipType = document.getElementById('relationshipType').value;
                const isPrimary = document.getElementById('isPrimary').checked ? 1 : 0;

                // Validate
                if (!personId || !relatedPersonId || !relationshipType) {
                    showAlert('Please fill in all required fields', 'danger');
                    return;
                }

                if (personId === relatedPersonId) {
                    showAlert('A person cannot be related to themselves', 'danger');
                    return;
                }

                const data = {
                    person_id: parseInt(personId),
                    related_person_id: parseInt(relatedPersonId),
                    relationship_type: relationshipType,
                    status: document.getElementById('relationshipStatus').value,
                    is_primary: isPrimary,
                    notes: document.getElementById('notes').value
                };

                const btn = document.getElementById('saveRelationshipBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Saving...';

                // In production, this would POST to the relationships API
                // For now, simulate success
                setTimeout(() => {
                    showAlert('Relationship created successfully!', 'success');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-save me-2"></i> Save Relationship';

                    const modal = bootstrap.Modal.getInstance(document.getElementById('relationshipModal'));
                    modal.hide();

                    this.loadRelationships();
                }, 1500);
            },

            deleteRelationship: function(id) {
                if (!confirm('Are you sure you want to delete this relationship?')) return;

                // In production, this would DELETE from the relationships API
                showAlert('Relationship deleted successfully', 'success');
                this.loadRelationships();
            }
        };

        // ================================================
        // PERSON SEARCH HELPER
        // ================================================
        function setupPersonSearch(inputId, resultsId, hiddenId, displayId, displayNameId) {
            const input = document.getElementById(inputId);
            const results = document.getElementById(resultsId);
            const hidden = document.getElementById(hiddenId);
            const display = document.getElementById(displayId);
            const displayName = document.getElementById(displayNameId);

            let timeout = null;

            input.addEventListener('input', function() {
                clearTimeout(timeout);
                const query = this.value.trim();

                if (query.length < 2) {
                    results.style.display = 'none';
                    return;
                }

                timeout = setTimeout(() => {
                    fetch(API_BASE + '/index.php?endpoint=identity&action=search&search=' + encodeURIComponent(
                            query), {
                            headers: getHeaders()
                        })
                        .then(response => response.json())
                        .then(result => {
                            if (result.success && result.data && result.data.length > 0) {
                                results.innerHTML = result.data.map(person => `
                                    <a href="#" class="list-group-item list-group-item-action" 
                                       data-id="${person.id}" 
                                       data-name="${getFullName(person)}"
                                       data-person='${JSON.stringify(person)}'>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong>${getFullName(person)}</strong>
                                                ${person.person_number ? `<br><small class="text-muted">${person.person_number}</small>` : ''}
                                            </div>
                                            <span class="badge bg-secondary">${person.person_type || 'Person'}</span>
                                        </div>
                                    </a>
                                `).join('');

                                // Add click handlers
                                results.querySelectorAll('.list-group-item').forEach(item => {
                                    item.addEventListener('click', function(e) {
                                        e.preventDefault();
                                        const id = this.dataset.id;
                                        const name = this.dataset.name;
                                        hidden.value = id;
                                        input.value = name;
                                        displayName.textContent = name;
                                        display.style.display = 'block';
                                        results.style.display = 'none';
                                    });
                                });

                                results.style.display = 'block';
                            } else {
                                results.innerHTML = `
                                    <div class="list-group-item text-muted text-center">No people found</div>
                                `;
                                results.style.display = 'block';
                            }
                        })
                        .catch(error => {
                            console.error('Error searching people:', error);
                            results.innerHTML = `
                                <div class="list-group-item text-danger text-center">Error searching</div>
                            `;
                            results.style.display = 'block';
                        });
                }, 300);
            });

            // Close results on blur
            input.addEventListener('blur', function() {
                setTimeout(() => {
                    results.style.display = 'none';
                }, 300);
            });
        }

        // ================================================
        // INITIALIZE
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            RelationshipsController.init();

            // Handle URL params for adding relationship with person
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('action') === 'add' && urlParams.get('person_id')) {
                RelationshipsController.openAddModal(parseInt(urlParams.get('person_id')));
            }
        });
    </script>
</body>

</html>