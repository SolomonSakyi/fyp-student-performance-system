<?php

/**
 * Requests Management - Super Admin
 * Approve or reject school and campus requests
 *
 * @package EduTrack
 * @subpackage Platform\Requests
 * @filepath public/platform/requests/index.php
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

$pageTitle = 'Requests - Super Admin';
$currentPage = 'requests';

$userName = $_SESSION['user_name'] ?? 'Super Admin';
$userInitial = strtoupper(substr($_SESSION['first_name'] ?? 'S', 0, 1));

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// Get all pending and recent requests
$schoolRequests = $db->fetchAll("
    SELECT sr.*, t.tenant_name, u.first_name as requested_by_name, u.last_name as requested_by_last
    FROM school_requests sr
    LEFT JOIN tenants t ON sr.tenant_id = t.id
    LEFT JOIN platform_users u ON sr.requested_by = u.id
    ORDER BY sr.requested_at DESC
");

$campusRequests = $db->fetchAll("
    SELECT cr.*, t.tenant_name, sc.school_name, u.first_name as requested_by_name, u.last_name as requested_by_last
    FROM campus_requests cr
    LEFT JOIN tenants t ON cr.tenant_id = t.id
    LEFT JOIN schools sc ON cr.school_id = sc.id
    LEFT JOIN platform_users u ON cr.requested_by = u.id
    ORDER BY cr.requested_at DESC
");

$pendingSchoolRequests = array_filter($schoolRequests, function ($r) {
    return $r['status'] === 'pending';
});
$pendingCampusRequests = array_filter($campusRequests, function ($r) {
    return $r['status'] === 'pending';
});

$totalPending = count($pendingSchoolRequests) + count($pendingCampusRequests);
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
            margin-bottom: 16px;
        }

        .card-custom .card-header-custom {
            padding: 12px 16px;
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
            font-size: 13px;
            color: #1a1a2e;
        }

        .card-custom .card-body-custom {
            padding: 0;
        }

        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            width: 100%;
        }

        .table {
            margin-bottom: 0;
            width: 100%;
            min-width: 700px;
        }

        .table th {
            font-weight: 600;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #6c757d;
            border-bottom: 2px solid #f0f2f5;
            padding: 10px 12px;
            white-space: nowrap;
            background: #f8f9fa;
        }

        .table td {
            vertical-align: middle;
            padding: 10px 12px;
            font-size: 12px;
            border-bottom: 1px solid #f0f2f5;
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

        .status-badge.pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-badge.approved {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .status-badge.cancelled {
            background: #e9ecef;
            color: #6c757d;
        }

        .btn-approve {
            background: #28a745;
            border: none;
            color: #fff;
        }

        .btn-approve:hover {
            background: #218838;
            color: #fff;
        }

        .btn-reject {
            background: #dc3545;
            border: none;
            color: #fff;
        }

        .btn-reject:hover {
            background: #c82333;
            color: #fff;
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

        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }

        .empty-state .icon {
            font-size: 48px;
            color: #adb5bd;
            margin-bottom: 10px;
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

            .page-header h2 {
                font-size: 18px;
            }

            .table th,
            .table td {
                padding: 6px 8px;
                font-size: 11px;
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
                    <a class="nav-link" href="/platform/campuses/index.php"><i class="fas fa-map-marker-alt"></i><span>Campuses</span></a>
                    <div class="nav-label mt-2">Platform Users</div>
                    <a class="nav-link" href="/platform/users/index.php"><i class="fas fa-users"></i><span>Users</span></a>
                    <div class="nav-label mt-2">Monitoring</div>
                    <a class="nav-link" href="/platform/staff/index.php"><i class="fas fa-user-tie"></i><span>Staff</span></a>
                    <a class="nav-link" href="/platform/students/index.php"><i class="fas fa-user-graduate"></i><span>Students</span></a>
                    <div class="nav-label mt-2">System</div>
                    <a class="nav-link active" href="/platform/requests/index.php"><i class="fas fa-paper-plane"></i><span>Requests</span></a>
                    <a class="nav-link" href="/platform/audit/index.php"><i class="fas fa-history"></i><span>Audit Logs</span></a>
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
                    <h4><i class="fas fa-paper-plane me-2"></i>Requests</h4>
                    <div class="topbar-actions">
                        <span class="badge bg-danger text-white me-2 d-none d-md-inline-block">
                            <i class="fas fa-crown me-1"></i> Super Admin
                        </span>
                        <?php if ($totalPending > 0): ?>
                            <span class="badge bg-warning text-dark">
                                <i class="fas fa-clock me-1"></i> <?php echo $totalPending; ?> Pending
                            </span>
                        <?php endif; ?>
                        <button class="btn btn-outline-secondary btn-sm" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt me-1"></i>
                        </button>
                    </div>
                </div>

                <div class="content-area">
                    <div class="page-header">
                        <div>
                            <h2><i class="fas fa-paper-plane me-2 text-primary"></i>Request Management</h2>
                            <span class="text-muted small">Approve or reject school and campus creation requests</span>
                        </div>
                        <span class="text-muted small"><?php echo $totalPending; ?> pending request(s)</span>
                    </div>

                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="fas fa-check-circle me-2"></i> <?php echo $_SESSION['success'];
                                                                        unset($_SESSION['success']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="fas fa-exclamation-circle me-2"></i> <?php echo $_SESSION['error'];
                                                                            unset($_SESSION['error']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- School Requests -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-school me-1 text-primary"></i> School Creation Requests</h6>
                            <span class="text-muted small"><?php echo count($schoolRequests); ?> request(s)</span>
                        </div>
                        <div class="card-body-custom">
                            <div class="table-container">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>School Name</th>
                                            <th>Code</th>
                                            <th>Tenant</th>
                                            <th>Requested By</th>
                                            <th>Status</th>
                                            <th>Requested</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($schoolRequests)): ?>
                                            <tr>
                                                <td colspan="7">
                                                    <div class="empty-state">
                                                        <div class="icon"><i class="fas fa-school"></i></div>
                                                        <h5>No school requests</h5>
                                                        <p>No school creation requests have been submitted.</p>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($schoolRequests as $request): ?>
                                                <tr>
                                                    <td class="fw-semibold"><?php echo htmlspecialchars($request['school_name']); ?></td>
                                                    <td><code><?php echo htmlspecialchars($request['school_code']); ?></code></td>
                                                    <td><?php echo htmlspecialchars($request['tenant_name'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars(($request['requested_by_name'] ?? '') . ' ' . ($request['requested_by_last'] ?? '')); ?></td>
                                                    <td>
                                                        <span class="status-badge <?php echo $request['status']; ?>">
                                                            <?php echo ucfirst($request['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('M d, Y', strtotime($request['requested_at'])); ?></td>
                                                    <td class="text-end">
                                                        <?php if ($request['status'] === 'pending'): ?>
                                                            <button class="btn btn-approve btn-sm" onclick="approveRequest('school', <?php echo $request['id']; ?>)">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                            <button class="btn btn-reject btn-sm" onclick="rejectRequest('school', <?php echo $request['id']; ?>)">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">Processed</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Campus Requests -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-map-marker-alt me-1 text-primary"></i> Campus Creation Requests</h6>
                            <span class="text-muted small"><?php echo count($campusRequests); ?> request(s)</span>
                        </div>
                        <div class="card-body-custom">
                            <div class="table-container">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Campus Name</th>
                                            <th>Code</th>
                                            <th>School</th>
                                            <th>Tenant</th>
                                            <th>Requested By</th>
                                            <th>Status</th>
                                            <th>Requested</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($campusRequests)): ?>
                                            <tr>
                                                <td colspan="8">
                                                    <div class="empty-state">
                                                        <div class="icon"><i class="fas fa-map-marker-alt"></i></div>
                                                        <h5>No campus requests</h5>
                                                        <p>No campus creation requests have been submitted.</p>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($campusRequests as $request): ?>
                                                <tr>
                                                    <td class="fw-semibold"><?php echo htmlspecialchars($request['campus_name']); ?></td>
                                                    <td><code><?php echo htmlspecialchars($request['campus_code']); ?></code></td>
                                                    <td><?php echo htmlspecialchars($request['school_name'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($request['tenant_name'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars(($request['requested_by_name'] ?? '') . ' ' . ($request['requested_by_last'] ?? '')); ?></td>
                                                    <td>
                                                        <span class="status-badge <?php echo $request['status']; ?>">
                                                            <?php echo ucfirst($request['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('M d, Y', strtotime($request['requested_at'])); ?></td>
                                                    <td class="text-end">
                                                        <?php if ($request['status'] === 'pending'): ?>
                                                            <button class="btn btn-approve btn-sm" onclick="approveRequest('campus', <?php echo $request['id']; ?>)">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                            <button class="btn btn-reject btn-sm" onclick="rejectRequest('campus', <?php echo $request['id']; ?>)">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">Processed</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Approve/Reject Modal -->
    <div class="modal fade" id="actionModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="actionModalTitle">Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="actionModalBody">Are you sure you want to perform this action?</p>
                    <div class="mb-3">
                        <label class="form-label" for="review_notes">Review Notes (Optional)</label>
                        <textarea class="form-control" id="review_notes" rows="3" placeholder="Add any notes about this decision..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form id="actionForm" method="POST" action="/platform/requests/process.php">
                        <input type="hidden" name="type" id="requestType">
                        <input type="hidden" name="id" id="requestId">
                        <input type="hidden" name="action" id="requestAction">
                        <input type="hidden" name="review_notes" id="requestReviewNotes">
                        <button type="submit" class="btn" id="actionSubmitBtn">Confirm</button>
                    </form>
                </div>
            </div>
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

        let actionModal = null;

        function approveRequest(type, id) {
            document.getElementById('requestType').value = type;
            document.getElementById('requestId').value = id;
            document.getElementById('requestAction').value = 'approve';
            document.getElementById('actionModalTitle').textContent = 'Approve Request';
            document.getElementById('actionModalBody').textContent = 'Are you sure you want to approve this request? This will create the new ' + type + '.';
            document.getElementById('actionSubmitBtn').className = 'btn btn-approve';
            document.getElementById('actionSubmitBtn').textContent = 'Approve';
            if (!actionModal) {
                actionModal = new bootstrap.Modal(document.getElementById('actionModal'));
            }
            actionModal.show();
        }

        function rejectRequest(type, id) {
            document.getElementById('requestType').value = type;
            document.getElementById('requestId').value = id;
            document.getElementById('requestAction').value = 'reject';
            document.getElementById('actionModalTitle').textContent = 'Reject Request';
            document.getElementById('actionModalBody').textContent = 'Are you sure you want to reject this request?';
            document.getElementById('actionSubmitBtn').className = 'btn btn-reject';
            document.getElementById('actionSubmitBtn').textContent = 'Reject';
            if (!actionModal) {
                actionModal = new bootstrap.Modal(document.getElementById('actionModal'));
            }
            actionModal.show();
        }

        // Update review notes before form submission
        document.getElementById('actionForm').addEventListener('submit', function(e) {
            document.getElementById('requestReviewNotes').value = document.getElementById('review_notes').value;
        });
    </script>
</body>

</html>