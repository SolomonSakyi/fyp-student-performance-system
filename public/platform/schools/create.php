<?php

/**
 * Create School - Super Admin
 *
 * @package EduTrack
 * @subpackage Platform\Schools
 * @filepath public/platform/schools/create.php
 * @version 2.1
 *
 * v2.1 change (2026-10-08) [SWEEP X-1 + SIDEBAR + CSRF]:
 *   Platform-schools sweep, X-1 in full, plus sidebar reconciliation,
 *   plus the CSRF gate on the create POST.
 *
 *   X-1: the visible "EduTrack" brand heading that this page carried
 *   in its own inline sidebar is superseded by the sidebar
 *   reconciliation below, which replaces the entire inline sidebar
 *   with the shared partial. The $pageTitle is realigned to the
 *   "Student 360 Platform" suffix convention used across the swept
 *   platform pages.
 *
 *   Sidebar: the inline sidebar this page carried — 7 items across
 *   4 groups (Main: Dashboard; Management: Tenants, Schools,
 *   Campuses; Users: Users; System: Audit, Settings), brand heading
 *   "EduTrack", subtitle "Super Admin", width 230px — is replaced
 *   by a single require_once of the shared partial at
 *   app/views/partials/platform-sidebar.php v1.0. That partial
 *   carries the canonical platform-root sidebar shape: 11 items
 *   across 4 groups (Main, Institution, Management, System),
 *   brand heading "Student 360", subtitle "Platform Administration".
 *
 *   The page's .sidebar and .main-content width rules are changed
 *   from 230px to 260px, and the 992px breakpoint from 60px to
 *   72px, so the page's CSS matches the shape of every other swept
 *   platform-root page. The page's top-bar markup was realigned from
 *   the local .topbar / .topbar-actions classes to the canonical
 *   .top-bar / .page-title / .header-actions classes, and the CSS
 *   block updated to match.
 *
 *   $currentPage stays 'schools' so the Schools item in the
 *   canonical partial is marked active. $pendingApprovals = 0 is
 *   set before the partial. $userFirstName is set and $userAvatar
 *   is derived from it, matching the other swept pages.
 *
 *   CSRF gate. verify_csrf() is called at the top of the POST
 *   handling, and a hidden csrf_token input is added inside the
 *   create form. Prior to this change the create POST wrote one row
 *   into the schools table with no CSRF check. The verify_csrf()
 *   call matches the project convention used by the guardian
 *   requests.php v1.1.2, the tenant notifications.php v2.6, the
 *   approvals/ files, the tenants/ files, and schools/index.php +
 *   schools/view.php.
 *
 *   The page's auth guards are unchanged: logged_in redirects to
 *   /platform/tenant/login.php, is_super_admin redirects to
 *   /platform/tenant/dashboard.php. The single SELECT on tenants
 *   and the single SELECT used to check school_code uniqueness are
 *   unchanged. The INSERT INTO schools is unchanged. The form
 *   markup, the school_code auto-uppercase JS, and logout() are
 *   unchanged.
 *
 *   Class names, table names, database name, and file paths remain
 *   EduTrack per X-1. The @package line stays 'EduTrack'.
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

$pageTitle = 'Create School - Student 360 Platform';
$currentPage = 'schools';

$userName = $_SESSION['user_name'] ?? 'Super Admin';
$userFirstName = $_SESSION['first_name'] ?? 'Super';
$userAvatar = strtoupper(substr($userFirstName, 0, 1));
$userInitial = $userAvatar;

// Variables read by the platform sidebar partial
$currentUser = $userName;
$pendingApprovals = 0;

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

require_once $projectRoot . '/app/views/partials/platform-sidebar.php';

// CSRF token for the create form
$csrfToken = '';
if (function_exists('csrf_token')) {
    $csrfToken = csrf_token();
} elseif (class_exists('Security') && method_exists('Security', 'csrfToken')) {
    $csrfToken = Security::csrfToken();
}

// Get all tenants for dropdown
$tenants = $db->fetchAll("SELECT id, tenant_name FROM tenants WHERE deleted_at IS NULL ORDER BY tenant_name");

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // =============================================
    // CSRF GATE
    // =============================================
    if (function_exists('verify_csrf')) {
        verify_csrf();
    } elseif (class_exists('Security')) {
        Security::verifyCsrf();
    }

    $tenantId = (int)$_POST['tenant_id'] ?? 0;
    $schoolName = trim($_POST['school_name'] ?? '');
    $schoolCode = trim($_POST['school_code'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $status = $_POST['status'] ?? 'active';

    // Validate
    if (empty($tenantId) || $tenantId <= 0) {
        $error = 'Please select a tenant.';
    } elseif (empty($schoolName)) {
        $error = 'School name is required.';
    } elseif (empty($schoolCode)) {
        $error = 'School code is required.';
    } else {
        // Check if school code exists
        $exists = $db->getValue("SELECT COUNT(*) FROM schools WHERE school_code = ? AND deleted_at IS NULL", [$schoolCode]);
        if ($exists > 0) {
            $error = 'School code already exists. Please choose a different code.';
        } else {
            try {
                $db->insert(
                    "INSERT INTO schools (uuid, tenant_id, school_name, school_code, email, phone, address, status) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $db->generateUuid(),
                        $tenantId,
                        $schoolName,
                        strtoupper($schoolCode),
                        $email,
                        $phone,
                        $address,
                        $status
                    ]
                );

                $_SESSION['success'] = 'School "' . $schoolName . '" created successfully!';
                header('Location: /platform/schools/index.php');
                exit;
            } catch (Exception $e) {
                $error = 'Error creating school: ' . $e->getMessage();
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
            width: 260px;
            left: 0;
            top: 0;
            z-index: 1000;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        .sidebar .sidebar-header {
            padding: 25px 24px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .sidebar .sidebar-header h4 {
            font-weight: 700;
            font-size: 20px;
            color: #fff;
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
            margin-top: 8px;
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
            padding: 20px 24px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(0, 0, 0, 0.2);
        }

        .sidebar .sidebar-footer .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
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
            font-size: 14px;
            color: #fff;
            flex-shrink: 0;
        }

        .sidebar .sidebar-footer .user-name {
            font-weight: 600;
            font-size: 14px;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar .sidebar-footer .user-role {
            font-size: 11px;
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
            margin-left: 260px;
            min-height: 100vh;
            width: calc(100% - 260px);
            max-width: 100%;
            overflow-x: hidden;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 20px;
            border-bottom: 1px solid #e9ecef;
            background: #fff;
            position: sticky;
            top: 0;
            z-index: 999;
            flex-wrap: wrap;
            gap: 8px;
        }

        .top-bar .page-title h1 {
            font-size: 20px;
            font-weight: 700;
            color: #1a1a2e;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .top-bar .page-title h1 i {
            color: #4facfe;
        }

        .top-bar .page-title p {
            color: #6c757d;
            margin: 0;
            font-size: 13px;
        }

        .top-bar .header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .top-bar .header-actions .btn {
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
        }

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

            .main-content {
                margin-left: 72px;
                width: calc(100% - 72px);
            }
        }

        @media (max-width: 768px) {
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

            .main-content {
                margin-left: 0;
                width: 100%;
            }

            .top-bar {
                padding: 8px 14px;
            }

            .top-bar .page-title h1 {
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
            .top-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 6px;
            }

            .top-bar .header-actions {
                justify-content: flex-start;
            }

            .top-bar .header-actions .btn {
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
            <?php require_once $projectRoot . '/app/views/partials/platform-sidebar.php'; ?>

            <!-- Main Content -->
            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-school me-2"></i>Create School</h1>
                        <p>Create a new school on the platform</p>
                    </div>
                    <div class="header-actions">
                        <span class="badge bg-danger text-white d-none d-md-inline-block">
                            <i class="fas fa-crown me-1"></i> Super Admin
                        </span>
                        <a href="/platform/schools/index.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-arrow-left me-1"></i> Back to Schools
                        </a>
                    </div>
                </div>

                <div class="content-area">
                    <div class="page-header">
                        <h2><i class="fas fa-plus-circle me-2 text-primary"></i>Create New School</h2>
                        <span class="text-muted small">Fill in the details below to create a new school</span>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-school me-2 text-primary"></i> School Information</h6>
                            <span class="text-muted small">Fields marked with <span class="text-danger">*</span> are required</span>
                        </div>
                        <div class="card-body-custom">
                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
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
                                        <label class="form-label" for="school_name">School Name <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="school_name" name="school_name"
                                            placeholder="Enter school name" value="<?php echo htmlspecialchars($_POST['school_name'] ?? ''); ?>" required>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="school_code">School Code <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="school_code" name="school_code"
                                            placeholder="e.g., SCH001" value="<?php echo htmlspecialchars(strtoupper($_POST['school_code'] ?? '')); ?>" required>
                                        <small class="text-muted">Unique identifier (auto-uppercase)</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="email">Email Address</label>
                                        <input type="email" class="form-control" id="email" name="email"
                                            placeholder="school@domain.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="phone">Phone Number</label>
                                        <input type="tel" class="form-control" id="phone" name="phone"
                                            placeholder="+233 24 123 4567" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                                    </div>
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
                                        <i class="fas fa-save me-2"></i> Create School
                                    </button>
                                    <a href="/platform/schools/index.php" class="btn btn-outline-secondary">
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
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '/platform/logout.php';
            }
        }

        // Auto-uppercase school code
        document.getElementById('school_code').addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    </script>
</body>

</html>