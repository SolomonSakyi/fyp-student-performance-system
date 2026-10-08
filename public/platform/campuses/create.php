<?php

/**
 * Create Campus - Super Admin
 *
 * @package EduTrack
 * @subpackage Platform\Campuses
 * @filepath public/platform/campuses/create.php
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

$pageTitle = 'Create Campus - Super Admin';
$currentPage = 'campuses';

$userName = $_SESSION['user_name'] ?? 'Super Admin';
$userInitial = strtoupper(substr($_SESSION['first_name'] ?? 'S', 0, 1));

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// Get all tenants for dropdown
$tenants = $db->fetchAll("SELECT id, tenant_name FROM tenants WHERE deleted_at IS NULL ORDER BY tenant_name");

// Get schools (will be filtered by tenant via AJAX)
$schools = [];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tenantId = (int)$_POST['tenant_id'] ?? 0;
    $schoolId = (int)$_POST['school_id'] ?? 0;
    $campusName = trim($_POST['campus_name'] ?? '');
    $campusCode = trim($_POST['campus_code'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $status = $_POST['status'] ?? 'active';

    // Validate
    if (empty($tenantId) || $tenantId <= 0) {
        $error = 'Please select a tenant.';
    } elseif (empty($schoolId) || $schoolId <= 0) {
        $error = 'Please select a school.';
    } elseif (empty($campusName)) {
        $error = 'Campus name is required.';
    } elseif (empty($campusCode)) {
        $error = 'Campus code is required.';
    } else {
        // Check if campus code exists
        $exists = $db->getValue("SELECT COUNT(*) FROM campuses WHERE campus_code = ? AND deleted_at IS NULL", [$campusCode]);
        if ($exists > 0) {
            $error = 'Campus code already exists. Please choose a different code.';
        } else {
            try {
                $db->insert(
                    "INSERT INTO campuses (uuid, tenant_id, school_id, campus_name, campus_code, address, status) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $db->generateUuid(),
                        $tenantId,
                        $schoolId,
                        $campusName,
                        strtoupper($campusCode),
                        $address,
                        $status
                    ]
                );

                $_SESSION['success'] = 'Campus "' . $campusName . '" created successfully!';
                header('Location: /platform/campuses/index.php');
                exit;
            } catch (Exception $e) {
                $error = 'Error creating campus: ' . $e->getMessage();
            }
        }
    }
}
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
        /* Copy styles from schools/create.php */
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
            max-width: 800px;
            margin: 0 auto;
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
            padding: 24px 20px;
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

            .card-custom {
                max-width: 100%;
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
                padding: 16px 12px;
            }

            .form-control,
            .form-select {
                font-size: 12px;
                padding: 6px 10px;
                height: 36px;
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
                    <h4><i class="fas fa-map-marker-alt me-2"></i>Create Campus</h4>
                    <div class="topbar-actions">
                        <span class="badge bg-danger text-white me-2 d-none d-md-inline-block">
                            <i class="fas fa-crown me-1"></i> Super Admin
                        </span>
                        <a href="/platform/campuses/index.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-arrow-left me-1"></i> Back to Campuses
                        </a>
                    </div>
                </div>

                <div class="content-area">
                    <div class="page-header">
                        <h2><i class="fas fa-plus-circle me-2 text-primary"></i>Create New Campus</h2>
                        <span class="text-muted small">Fill in the details below to create a new campus</span>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-map-marker-alt me-2 text-primary"></i> Campus Information</h6>
                            <span class="text-muted small">Fields marked with <span class="text-danger">*</span> are required</span>
                        </div>
                        <div class="card-body-custom">
                            <form method="POST" action="" id="campusForm">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="tenant_id">Tenant <span class="required">*</span></label>
                                        <select class="form-select" id="tenant_id" name="tenant_id" required>
                                            <option value="">Select Tenant...</option>
                                            <?php foreach ($tenants as $tenant): ?>
                                                <option value="<?php echo $tenant['id']; ?>" <?php echo (isset($_POST['tenant_id']) && $_POST['tenant_id'] == $tenant['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($tenant['tenant_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="school_id">School <span class="required">*</span></label>
                                        <select class="form-select" id="school_id" name="school_id" required>
                                            <option value="">Select School...</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="campus_name">Campus Name <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="campus_name" name="campus_name"
                                            placeholder="Enter campus name" value="<?php echo htmlspecialchars($_POST['campus_name'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="campus_code">Campus Code <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="campus_code" name="campus_code"
                                            placeholder="e.g., CMP001" value="<?php echo htmlspecialchars(strtoupper($_POST['campus_code'] ?? '')); ?>" required>
                                        <small class="text-muted">Unique identifier (auto-uppercase)</small>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="status">Status</label>
                                        <select class="form-select" id="status" name="status">
                                            <option value="active" <?php echo (isset($_POST['status']) && $_POST['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo (isset($_POST['status']) && $_POST['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label" for="address">Address</label>
                                        <input type="text" class="form-control" id="address" name="address"
                                            placeholder="Street address" value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>">
                                    </div>
                                </div>

                                <hr class="my-3">

                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i> Create Campus
                                    </button>
                                    <a href="/platform/campuses/index.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times me-2"></i> Cancel
                                    </a>
                                </div>
                            </form>
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
                window.location.href = '/platform/logout.php';
            }
        }

        // Auto-uppercase campus code
        document.getElementById('campus_code').addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });

        // Load schools when tenant changes
        document.getElementById('tenant_id').addEventListener('change', function() {
            const tenantId = this.value;
            const schoolSelect = document.getElementById('school_id');

            // Clear schools
            schoolSelect.innerHTML = '<option value="">Select School...</option>';

            if (tenantId) {
                // Show loading
                schoolSelect.innerHTML = '<option value="">Loading schools...</option>';

                fetch('/platform/campuses/get_schools.php?tenant_id=' + tenantId)
                    .then(response => response.json())
                    .then(data => {
                        schoolSelect.innerHTML = '<option value="">Select School...</option>';
                        data.forEach(school => {
                            const option = document.createElement('option');
                            option.value = school.id;
                            option.textContent = school.school_name;
                            schoolSelect.appendChild(option);
                        });
                    })
                    .catch(error => {
                        console.error('Error fetching schools:', error);
                        schoolSelect.innerHTML = '<option value="">Error loading schools</option>';
                    });
            }
        });
    </script>
</body>

</html>