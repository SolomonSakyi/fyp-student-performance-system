<?php

/**
 * Create Domain - Register a new domain for a tenant
 *
 * @package EduTrack
 * @subpackage Platform\Domains
 * @version 2.0
 * @filepath public/platform/domains/create.php
 */

session_start();

// ============================================================
// AUTHENTICATION CHECK
// ============================================================
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

// Check if user is Super Admin (role_id = 2)
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;
if (!$isSuperAdmin) {
    // If not super admin, redirect to tenant dashboard
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

$pageTitle = 'Add Domain - EduTrack Platform';
$currentPage = 'domains';

$userName = $_SESSION['user_name'] ?? 'Admin';
$userInitial = strtoupper(substr($userName, 0, 1));

$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// ============================================================
// GET TENANTS FOR DROPDOWN
// ============================================================
$tenants = $db->fetchAll("
    SELECT id, tenant_name, tenant_code, status 
    FROM tenants 
    WHERE deleted_at IS NULL 
    ORDER BY tenant_name
");

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$errors = [];
$formData = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = $_POST;

    // Validate required fields
    $required = ['tenant_id', 'domain_name'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
        }
    }

    // Validate domain name format
    if (!empty($_POST['domain_name'])) {
        $domain = trim($_POST['domain_name']);
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#^www\.#', '', $domain);
        $domain = rtrim($domain, '/');

        if (!preg_match('/^[a-z0-9\-\.]+\.[a-z]{2,}$/i', $domain)) {
            $errors[] = 'Please enter a valid domain name (e.g., school.edutrack.com)';
        }

        // Check if domain already exists
        $existing = $db->getValue(
            "SELECT COUNT(*) FROM domains WHERE domain_name = ? AND deleted_at IS NULL",
            [$domain]
        );

        if ($existing > 0) {
            $errors[] = 'This domain is already registered.';
        }

        $formData['domain_name'] = $domain;
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            $domainData = [
                'tenant_id' => (int)$_POST['tenant_id'],
                'domain_name' => $formData['domain_name'],
                'domain_type' => $_POST['domain_type'] ?? 'secondary',
                'is_primary' => isset($_POST['is_primary']) ? 1 : 0,
                'is_verified' => isset($_POST['is_verified']) ? 1 : 0,
                'status' => isset($_POST['is_verified']) ? 'verified' : 'pending',
                'ssl_enabled' => isset($_POST['ssl_enabled']) ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $fields = [];
            $values = [];
            $params = [];

            foreach ($domainData as $field => $value) {
                $fields[] = $field;
                $values[] = '?';
                $params[] = $value;
            }

            $sql = "INSERT INTO domains (" . implode(', ', $fields) . ") 
                    VALUES (" . implode(', ', $values) . ")";

            $domainId = $db->insert($sql, $params);

            if (!$domainId) {
                throw new Exception('Failed to create domain.');
            }

            $db->commit();

            $_SESSION['success'] = 'Domain ' . htmlspecialchars($formData['domain_name']) . ' added successfully!';
            header('Location: /platform/domains/index.php');
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Error creating domain: ' . $e->getMessage();
            error_log('Domain creation error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

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

        .form-control.is-invalid,
        .form-select.is-invalid {
            border-color: #dc3545;
            box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1);
        }

        .form-text {
            font-size: 11px;
            color: #6c757d;
            margin-top: 4px;
        }

        .alert {
            border-radius: 10px;
            border: none;
            font-size: 13px;
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

            .content-area {
                padding: 12px 14px;
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

            .card-custom .card-header-custom {
                padding: 8px 10px;
            }

            .card-custom .card-header-custom h6 {
                font-size: 12px;
            }

            .card-custom .card-body-custom {
                padding: 8px 10px;
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

            <nav class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <h4><i class="fas fa-graduation-cap me-2"></i>EduTrack</h4>
                    <small>Platform Admin</small>
                </div>
                <div class="nav">
                    <div class="nav-label">Main</div>
                    <a class="nav-link" href="/platform/index.php"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
                    <div class="nav-label mt-2">Management</div>
                    <a class="nav-link" href="/platform/tenants/index.php"><i class="fas fa-building"></i><span>Tenants</span></a>
                    <a class="nav-link active" href="/platform/domains/index.php"><i class="fas fa-globe"></i><span>Domains</span></a>
                    <a class="nav-link" href="/platform/schools/index.php"><i class="fas fa-school"></i><span>Schools</span></a>
                    <a class="nav-link" href="/platform/campuses/index.php"><i class="fas fa-map-marker-alt"></i><span>Campuses</span></a>
                    <div class="nav-label mt-2">Users</div>
                    <a class="nav-link" href="/platform/users/index.php"><i class="fas fa-users"></i><span>Users</span></a>
                    <div class="nav-label mt-2">System</div>
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

            <main class="main-content">
                <div class="topbar">
                    <h4><i class="fas fa-globe me-2"></i>Add Domain</h4>
                    <div class="topbar-actions">
                        <a href="/platform/domains/index.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-arrow-left me-1"></i> Back to Domains
                        </a>
                    </div>
                </div>

                <div class="content-area">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <strong>Please fix the following errors:</strong>
                            <ul class="mb-0 mt-1">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="fas fa-check-circle me-2"></i> <?php echo $_SESSION['success']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['success']); ?>
                    <?php endif; ?>

                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-globe me-1 text-primary"></i> Domain Registration</h6>
                            <span class="text-muted small">Register a new domain for a tenant</span>
                        </div>
                        <div class="card-body-custom">
                            <form method="POST" action="" id="domainForm" novalidate>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="tenant_id">Tenant <span class="required">*</span></label>
                                        <select class="form-select <?php echo (isset($errors) && empty($_POST['tenant_id'])) ? 'is-invalid' : ''; ?>" id="tenant_id" name="tenant_id" required>
                                            <option value="">Select Tenant...</option>
                                            <?php foreach ($tenants as $tenant): ?>
                                                <option value="<?php echo $tenant['id']; ?>" <?php echo (isset($_POST['tenant_id']) && $_POST['tenant_id'] == $tenant['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($tenant['tenant_name']); ?> (<?php echo htmlspecialchars($tenant['tenant_code']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">The tenant this domain belongs to</div>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="domain_name">Domain Name <span class="required">*</span></label>
                                        <input type="text" class="form-control <?php echo (isset($errors) && empty($_POST['domain_name'])) ? 'is-invalid' : ''; ?>" id="domain_name" name="domain_name" placeholder="school.edutrack.com" value="<?php echo htmlspecialchars($_POST['domain_name'] ?? ''); ?>" required>
                                        <div class="form-text">Enter the full domain name (e.g., school.edutrack.com)</div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="domain_type">Domain Type</label>
                                        <select class="form-select" id="domain_type" name="domain_type">
                                            <option value="secondary" <?php echo (isset($_POST['domain_type']) && $_POST['domain_type'] == 'secondary') ? 'selected' : ''; ?>>Secondary Domain</option>
                                            <option value="primary" <?php echo (isset($_POST['domain_type']) && $_POST['domain_type'] == 'primary') ? 'selected' : ''; ?>>Primary Domain</option>
                                        </select>
                                        <div class="form-text">Primary = main domain for this tenant</div>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <div class="form-check mt-4">
                                            <input type="checkbox" class="form-check-input" id="is_primary" name="is_primary" value="1" <?php echo (isset($_POST['is_primary']) && $_POST['is_primary'] == 1) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="is_primary">
                                                <i class="fas fa-star text-warning me-1"></i> Set as Primary Domain
                                            </label>
                                        </div>
                                        <div class="form-check mt-2">
                                            <input type="checkbox" class="form-check-input" id="is_verified" name="is_verified" value="1" <?php echo (isset($_POST['is_verified']) && $_POST['is_verified'] == 1) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="is_verified">
                                                <i class="fas fa-check-circle text-success me-1"></i> Mark as Verified
                                            </label>
                                        </div>
                                        <div class="form-check mt-2">
                                            <input type="checkbox" class="form-check-input" id="ssl_enabled" name="ssl_enabled" value="1" <?php echo (isset($_POST['ssl_enabled']) && $_POST['ssl_enabled'] == 1) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="ssl_enabled">
                                                <i class="fas fa-lock text-primary me-1"></i> Enable SSL
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Register Domain</button>
                                    <a href="/platform/domains/index.php" class="btn btn-outline-secondary ms-2"><i class="fas fa-times me-2"></i> Cancel</a>
                                </div>

                            </form>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">
    </script>
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

        // Auto-format domain name
        document.getElementById('domain_name').addEventListener('blur', function() {
            let domain = this.value.trim();
            domain = domain.replace(/^https?:\/\//, '');
            domain = domain.replace(/^www\./, '');
            domain = domain.replace(/\/$/, '');
            this.value = domain;
        });

        // Form validation
        document.getElementById('domainForm').addEventListener('submit', function(e) {
            let valid = true;
            document.querySelectorAll('[required]').forEach(function(el) {
                if (!el.value.trim()) {
                    el.classList.add('is-invalid');
                    valid = false;
                } else {
                    el.classList.remove('is-invalid');
                }
            });
            if (!valid) {
                e.preventDefault();
                const firstError = document.querySelector('.is-invalid');
                if (firstError) {
                    firstError.focus();
                }
            }
        });

        document.querySelectorAll('.form-control, .form-select').forEach(function(el) {
            el.addEventListener('input', function() {
                if (this.value.trim()) {
                    this.classList.remove('is-invalid');
                }
            });
            el.addEventListener('change', function() {
                if (this.value.trim()) {
                    this.classList.remove('is-invalid');
                }
            });
        });
    </script>
</body>

</html>