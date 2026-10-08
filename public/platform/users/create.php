<?php

/**
 * Create User - Super Admin
 *
 * @package EduTrack
 * @subpackage Platform\Users
 * @filepath public/platform/users/create.php
 * @version 1.0
 *
 * v1.0 change (2026-10-07) [SWEEP X-1 + SIDEBAR]:
 *   Platform-users sweep, X-1 in full, plus sidebar reconciliation.
 *
 *   X-1: one visible "EduTrack" string (the sidebar brand heading)
 *   is superseded by the sidebar reconciliation below, which
 *   replaces the entire inline sidebar with the shared partial.
 *   The $pageTitle is realigned to the "Student 360 Platform"
 *   suffix convention used across the swept platform pages.
 *
 *   Sidebar: the inline sidebar this page carried — 7 items across
 *   4 groups (Main, Management, Users, System), brand heading
 *   "EduTrack", width 230px — is replaced by a single require_once
 *   of the shared partial at
 *   app/views/partials/platform-sidebar.php v1.0. That partial
 *   carries the canonical platform-root sidebar shape: 11 items
 *   across 4 groups (Main, Institution, Management, System), folder
 *   paths for Tenants and Settings, no Register Tenant item, plus
 *   the mobile toggle, the sidebar footer, and the toggleSidebar()
 *   JS.
 *
 *   $currentPage = 'users' is preserved; it matches the canonical
 *   partial's Users key, so the canonical sidebar's Users item is
 *   marked active on this page. $pendingApprovals = 0,
 *   $currentUser, and $userAvatar are set explicitly before the
 *   partial is required.
 *
 *   The inline toggleSidebar() function that this page carried in
 *   its own <script> block is removed, because the partial now
 *   provides it. The page's click-outside handler, resize handler,
 *   and logout() are kept — they are page-scoped and do not
 *   conflict with the partial.
 *
 *   The page's server-side session auth, its nine validation
 *   branches, its two uniqueness checks, its password_hash() call,
 *   its platform_users INSERT (which deliberately omits uuid and
 *   relies on the before_insert_platform_users trigger, per the
 *   file's own comment), its platform_user_roles INSERT loop, its
 *   post-insert uuid read, its error_log() call, and its success
 *   redirect are all byte-identical to the previous version.
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

$pageTitle = 'Create User - Student 360 Platform';
$currentPage = 'users';

$userName = $_SESSION['user_name'] ?? 'Super Admin';
$userInitial = strtoupper(substr($_SESSION['first_name'] ?? 'S', 0, 1));

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
require_once $projectRoot . '/app/views/partials/platform-sidebar.php';
$db = DatabaseHelper::getInstance();

// Variables read by the sidebar partial
$currentUser = $userName;
$userAvatar = $userInitial;
$pendingApprovals = 0;

// Get all tenants for dropdown
$tenants = $db->fetchAll("SELECT id, tenant_name FROM tenants WHERE deleted_at IS NULL ORDER BY tenant_name");

// Get all roles
$roles = $db->fetchAll("SELECT id, role_name FROM roles ORDER BY role_name");

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tenantId = (int)$_POST['tenant_id'] ?? 0;
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $selectedRoles = $_POST['roles'] ?? [];

    // Validate
    if (empty($tenantId) || $tenantId <= 0) {
        $error = 'Please select a tenant.';
    } elseif (empty($username)) {
        $error = 'Username is required.';
    } elseif (empty($email)) {
        $error = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (empty($password) || strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif (empty($firstName)) {
        $error = 'First name is required.';
    } elseif (empty($lastName)) {
        $error = 'Last name is required.';
    } elseif (empty($selectedRoles)) {
        $error = 'Please select at least one role.';
    } else {
        // Check if username exists
        $exists = $db->getValue("SELECT COUNT(*) FROM platform_users WHERE username = ? AND deleted_at IS NULL", [$username]);
        if ($exists > 0) {
            $error = 'Username already exists. Please choose a different username.';
        } else {
            // Check if email exists
            $exists = $db->getValue("SELECT COUNT(*) FROM platform_users WHERE email = ? AND deleted_at IS NULL", [$email]);
            if ($exists > 0) {
                $error = 'Email already exists. Please use a different email.';
            } else {
                try {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                    // ============================================================
                    // FIX: UUID is REMOVED from the INSERT statement.
                    // The database trigger (before_insert_platform_users) will 
                    // automatically generate a UUID when the column is NULL.
                    // ============================================================

                    $userId = $db->insert(
                        "INSERT INTO platform_users 
                         (tenant_id, username, email, password_hash, first_name, last_name, phone, is_active) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $tenantId,
                            $username,
                            $email,
                            $passwordHash,
                            $firstName,
                            $lastName,
                            $phone,
                            $isActive
                        ]
                    );

                    // Assign roles
                    foreach ($selectedRoles as $roleId) {
                        $db->insert(
                            "INSERT INTO platform_user_roles (user_id, role_id) VALUES (?, ?)",
                            [$userId, (int)$roleId]
                        );
                    }

                    // Get the UUID that was generated by the trigger
                    $newUser = $db->fetchOne(
                        "SELECT uuid FROM platform_users WHERE id = ? AND deleted_at IS NULL",
                        [$userId]
                    );

                    error_log("User created successfully: ID={$userId}, Username={$username}, UUID=" . ($newUser['uuid'] ?? 'generated_by_trigger'));

                    $_SESSION['success'] = 'User "' . $firstName . ' ' . $lastName . '" created successfully!';
                    header('Location: /platform/users/index.php');
                    exit;
                } catch (Exception $e) {
                    $error = 'Error creating user: ' . $e->getMessage();
                }
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

        .role-checkbox-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .role-checkbox-group .form-check {
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            margin: 0;
            transition: all 0.2s;
        }

        .role-checkbox-group .form-check:hover {
            background: #e3f0ff;
            border-color: #4facfe;
        }

        .role-checkbox-group .form-check-input:checked {
            background-color: #4facfe;
            border-color: #4facfe;
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

            .role-checkbox-group {
                grid-template-columns: 1fr;
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
            <?php require_once $projectRoot . '/app/views/partials/platform-sidebar.php'; ?>

            <!-- Main Content -->
            <main class="main-content">
                <div class="topbar">
                    <h4><i class="fas fa-user-plus me-2"></i>Create User</h4>
                    <div class="topbar-actions">
                        <span class="badge bg-danger text-white me-2 d-none d-md-inline-block">
                            <i class="fas fa-crown me-1"></i> Super Admin
                        </span>
                        <a href="/platform/users/index.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-arrow-left me-1"></i> Back to Users
                        </a>
                    </div>
                </div>

                <div class="content-area">
                    <div class="page-header">
                        <h2><i class="fas fa-plus-circle me-2 text-primary"></i>Create New User</h2>
                        <span class="text-muted small">Fill in the details below to create a new platform user</span>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-user me-2 text-primary"></i> User Information</h6>
                            <span class="text-muted small">Fields marked with <span class="text-danger">*</span> are required</span>
                        </div>
                        <div class="card-body-custom">
                            <form method="POST" action="">
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
                                        <label class="form-label" for="username">Username <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="username" name="username"
                                            placeholder="Enter username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="email">Email <span class="required">*</span></label>
                                        <input type="email" class="form-control" id="email" name="email"
                                            placeholder="user@email.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="password">Password <span class="required">*</span></label>
                                        <input type="password" class="form-control" id="password" name="password"
                                            placeholder="Min 6 characters" required>
                                        <small class="text-muted">Password must be at least 6 characters</small>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="first_name">First Name <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="first_name" name="first_name"
                                            placeholder="Enter first name" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="last_name">Last Name <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="last_name" name="last_name"
                                            placeholder="Enter last name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="phone">Phone</label>
                                        <input type="tel" class="form-control" id="phone" name="phone"
                                            placeholder="+233 24 123 4567" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6 mb-3 d-flex align-items-center">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1" checked>
                                            <label class="form-check-label" for="is_active">
                                                <i class="fas fa-check-circle text-success me-1"></i> Active
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <hr class="my-3">

                                <div class="mb-3">
                                    <label class="form-label">Roles <span class="required">*</span></label>
                                    <div class="role-checkbox-group">
                                        <?php foreach ($roles as $role): ?>
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input" id="role_<?php echo $role['id']; ?>"
                                                    name="roles[]" value="<?php echo $role['id']; ?>">
                                                <label class="form-check-label" for="role_<?php echo $role['id']; ?>">
                                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <small class="text-muted">Select at least one role</small>
                                </div>

                                <hr class="my-3">

                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i> Create User
                                    </button>
                                    <a href="/platform/users/index.php" class="btn btn-outline-secondary">
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
        // toggleSidebar() is provided by the partial at
        // app/views/partials/platform-sidebar.php.

        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.getElementById('sidebarToggle');
            if (!sidebar || !toggle) return;
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });

        window.addEventListener('resize', function() {
            const sb = document.getElementById('sidebar');
            if (sb && window.innerWidth > 768) {
                sb.classList.remove('open');
            }
        });

        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '/platform/logout.php';
            }
        }
    </script>
</body>

</html>