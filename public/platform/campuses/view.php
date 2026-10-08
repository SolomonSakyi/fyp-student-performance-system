<?php

/**
 * View Campus - Super Admin
 *
 * @package EduTrack
 * @subpackage Platform\Campuses
 * @filepath public/platform/campuses/view.php
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

$pageTitle = 'View Campus - Super Admin';
$currentPage = 'campuses';

$userName = $_SESSION['user_name'] ?? 'Super Admin';
$userInitial = strtoupper(substr($_SESSION['first_name'] ?? 'S', 0, 1));

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// Get campus details
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
    <style>
        /* Copy styles from schools/view.php */
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

        .profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 16px 20px;
            background: #fff;
            border-radius: 10px;
            margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(0, 0, 0, 0.03);
            flex-wrap: wrap;
        }

        .profile-header .profile-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 28px;
            color: #fff;
            flex-shrink: 0;
        }

        .profile-header .profile-info .name {
            font-size: 20px;
            font-weight: 700;
            color: #1a1a2e;
        }

        .profile-header .profile-info .code {
            font-size: 13px;
            color: #6c757d;
        }

        .profile-header .profile-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-left: auto;
        }

        .detail-row {
            display: flex;
            padding: 6px 0;
            border-bottom: 1px solid #f0f2f5;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-row .label {
            font-weight: 500;
            color: #6c757d;
            width: 160px;
            flex-shrink: 0;
        }

        .detail-row .value {
            flex: 1;
            color: #1a1a2e;
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

        .btn-outline-danger {
            background: transparent;
            border: 1.5px solid #dc3545;
            color: #dc3545;
        }

        .btn-outline-danger:hover {
            background: #dc3545;
            color: #fff;
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

            .profile-header {
                flex-direction: column;
                align-items: flex-start;
                text-align: center;
            }

            .profile-header .profile-actions {
                margin-left: 0;
                width: 100%;
                justify-content: center;
            }

            .detail-row {
                flex-direction: column;
            }

            .detail-row .label {
                width: 100%;
                margin-bottom: 2px;
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

            .profile-header .profile-icon {
                width: 48px;
                height: 48px;
                font-size: 20px;
            }

            .profile-header .profile-info .name {
                font-size: 16px;
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
                <a href="/platform/campuses/settings.php?id=<?php echo $campusId; ?>" class="btn btn-outline-info btn-sm">
                    <i class="fas fa-cog me-1"></i> Settings
                </a>
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
                    <h4><i class="fas fa-map-marker-alt me-2"></i>Campus Details</h4>
                    <div class="topbar-actions">
                        <span class="badge bg-danger text-white me-2 d-none d-md-inline-block">
                            <i class="fas fa-crown me-1"></i> Super Admin
                        </span>
                        <a href="/platform/campuses/index.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-arrow-left me-1"></i> Back
                        </a>
                        <a href="/platform/campuses/edit.php?id=<?php echo $campusId; ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-edit me-1"></i> Edit
                        </a>
                    </div>
                </div>

                <div class="content-area">
                    <!-- Profile Header -->
                    <div class="profile-header">
                        <div class="profile-icon">
                            <?php echo strtoupper(substr($campus['campus_name'], 0, 1)); ?>
                        </div>
                        <div class="profile-info">
                            <div class="name"><?php echo htmlspecialchars($campus['campus_name']); ?></div>
                            <div class="code">
                                <i class="fas fa-tag me-1"></i> <?php echo htmlspecialchars($campus['campus_code']); ?>
                                <span class="status-badge <?php echo $statusBadge[$campus['status']] ?? 'inactive'; ?>">
                                    <?php echo ucfirst($campus['status']); ?>
                                </span>
                            </div>
                            <div class="code">
                                <i class="fas fa-school me-1"></i> <?php echo htmlspecialchars($campus['school_name'] ?? 'N/A'); ?>
                            </div>
                            <div class="code">
                                <i class="fas fa-building me-1"></i> <?php echo htmlspecialchars($campus['tenant_name'] ?? 'N/A'); ?>
                            </div>
                        </div>
                        <div class="profile-actions">
                            <button class="btn btn-outline-danger btn-sm" onclick="confirmDelete(<?php echo $campusId; ?>, '<?php echo addslashes($campus['campus_name']); ?>')">
                                <i class="fas fa-trash me-1"></i> Delete
                            </button>
                        </div>
                    </div>

                    <!-- Campus Details -->
                    <div class="row g-2 g-md-3">
                        <div class="col-md-6">
                            <div class="card-custom">
                                <div class="card-header-custom">
                                    <h6><i class="fas fa-info-circle me-1 text-primary"></i> Campus Information</h6>
                                </div>
                                <div class="card-body-custom">
                                    <div class="detail-row">
                                        <span class="label">Campus Name</span>
                                        <span class="value"><?php echo htmlspecialchars($campus['campus_name']); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="label">Campus Code</span>
                                        <span class="value"><?php echo htmlspecialchars($campus['campus_code']); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="label">School</span>
                                        <span class="value"><?php echo htmlspecialchars($campus['school_name'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="label">Tenant</span>
                                        <span class="value"><?php echo htmlspecialchars($campus['tenant_name'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="label">Status</span>
                                        <span class="value">
                                            <span class="status-badge <?php echo $statusBadge[$campus['status']] ?? 'inactive'; ?>">
                                                <?php echo ucfirst($campus['status']); ?>
                                            </span>
                                        </span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="label">Created</span>
                                        <span class="value"><?php echo date('F d, Y h:i A', strtotime($campus['created_at'])); ?></span>
                                    </div>
                                    <?php if ($campus['updated_at'] && $campus['updated_at'] != $campus['created_at']): ?>
                                        <div class="detail-row">
                                            <span class="label">Last Updated</span>
                                            <span class="value"><?php echo date('F d, Y h:i A', strtotime($campus['updated_at'])); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card-custom">
                                <div class="card-header-custom">
                                    <h6><i class="fas fa-address-card me-1 text-primary"></i> Contact Details</h6>
                                </div>
                                <div class="card-body-custom">
                                    <?php if (!empty($campus['address'])): ?>
                                        <div class="detail-row">
                                            <span class="label">Address</span>
                                            <span class="value"><?php echo htmlspecialchars($campus['address']); ?></span>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-muted text-center py-3">
                                            <i class="fas fa-info-circle me-1"></i> No address information available
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle text-danger me-2"></i>Delete Campus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete <strong id="deleteName"></strong>?</p>
                    <p class="text-muted small"><i class="fas fa-info-circle me-1"></i> This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" action="/platform/campuses/delete.php" id="deleteForm">
                        <input type="hidden" name="id" id="deleteId">
                        <button type="submit" class="btn btn-danger">Delete</button>
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

        let deleteModal = null;

        function confirmDelete(id, name) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteName').textContent = name;
            if (!deleteModal) {
                deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            }
            deleteModal.show();
        }
    </script>
</body>

</html>