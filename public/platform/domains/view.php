<?php

/**
 * View Domain - Display domain details
 * 
 * @package EduTrack
 * @subpackage Platform\Domains
 * @version 2.0
 * @filepath public/platform/domains/view.php
 */

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

// Check if user is Super Admin (role_id = 2)
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;
if (!$isSuperAdmin) {
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

$pageTitle = 'View Domain - EduTrack Platform';
$currentPage = 'domains';

$userName = $_SESSION['user_name'] ?? 'Admin';
$userInitial = strtoupper(substr($userName, 0, 1));

// Get domain ID from URL
$domainId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($domainId <= 0) {
    header('Location: /platform/domains/index.php');
    exit;
}

$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// ============================================================
// GET DOMAIN DATA
// ============================================================
$domain = $db->fetchOne("
    SELECT d.*, t.tenant_name 
    FROM domains d
    LEFT JOIN tenants t ON d.tenant_id = t.id
    WHERE d.id = ? AND d.deleted_at IS NULL
", [$domainId]);

if (!$domain) {
    header('Location: /platform/domains/index.php');
    exit;
}

// Get server IP for DNS
$serverIp = $_SERVER['SERVER_ADDR'] ?? '192.168.1.1';

// Handle DNS Update
$dnsUpdateMessage = '';
$dnsUpdateType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_dns') {
    $newDnsIp = trim($_POST['dns_ip'] ?? '');

    if (!empty($newDnsIp)) {
        try {
            $db->execute(
                "UPDATE domains SET dns_ip = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL",
                [$newDnsIp, $domainId]
            );
            $dnsUpdateMessage = 'DNS IP updated successfully!';
            $dnsUpdateType = 'success';
            // Refresh domain data
            $domain = $db->fetchOne("
                SELECT d.*, t.tenant_name 
                FROM domains d
                LEFT JOIN tenants t ON d.tenant_id = t.id
                WHERE d.id = ? AND d.deleted_at IS NULL
            ", [$domainId]);
        } catch (Exception $e) {
            $dnsUpdateMessage = 'Error updating DNS IP: ' . $e->getMessage();
            $dnsUpdateType = 'danger';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
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

        .main-content {
            margin-left: 260px;
            padding: 24px 32px 40px;
            background: #f0f2f5;
            min-height: 100vh;
            width: calc(100% - 260px);
            max-width: 100%;
            overflow-x: hidden;
        }

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

        .top-bar .header-actions .btn i {
            margin-right: 6px;
        }

        .card-custom {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
            overflow: hidden;
            width: 100%;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
        }

        .card-custom .card-header-custom {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: transparent;
            flex-wrap: wrap;
            gap: 8px;
        }

        .card-custom .card-header-custom h6 {
            font-weight: 600;
            margin: 0;
            font-size: 15px;
            color: #1a1a2e;
        }

        .card-custom .card-body-custom {
            padding: 20px 24px;
        }

        .detail-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid #f0f2f5;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 500;
            color: #6c757d;
            width: 160px;
            flex-shrink: 0;
            font-size: 13px;
        }

        .detail-value {
            font-weight: 500;
            color: #1a1a2e;
            font-size: 14px;
        }

        .badge-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-status.active {
            background: #d4edda;
            color: #155724;
        }

        .badge-status.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-status.pending {
            background: #fff3cd;
            color: #856404;
        }

        .badge-status.verified {
            background: #d4edda;
            color: #155724;
        }

        .badge-status.failed {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-primary {
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            display: inline-block;
            background: #e8d5f5;
            color: #6f42c1;
        }

        .badge-ssl {
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-ssl.enabled {
            background: #d4edda;
            color: #155724;
        }

        .badge-ssl.disabled {
            background: #f8d7da;
            color: #721c24;
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

        .btn-success {
            background: #28a745;
            border: none;
            color: #fff;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
            color: #fff;
        }

        .btn-warning {
            background: #ffc107;
            border: none;
            color: #1a1a2e;
        }

        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 193, 7, 0.4);
            color: #1a1a2e;
        }

        .btn-danger {
            background: #dc3545;
            border: none;
            color: #fff;
        }

        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4);
            color: #fff;
        }

        .btn-sm {
            padding: 4px 12px;
            font-size: 12px;
            border-radius: 8px;
        }

        .alert {
            border-radius: 10px;
            border: none;
            font-size: 13px;
        }

        .form-control-sm {
            height: 32px;
            font-size: 13px;
            padding: 4px 8px;
            border-radius: 6px;
            border: 2px solid #e9ecef;
            width: 180px;
            display: inline-block;
        }

        .form-control-sm:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .form-control-sm[readonly] {
            background-color: #f8f9fa;
            cursor: not-allowed;
        }

        .dns-edit-actions {
            display: none;
            margin-top: 10px;
            gap: 8px;
        }

        .dns-edit-actions.show {
            display: flex;
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

            .detail-row {
                flex-direction: column;
            }

            .detail-label {
                width: 100%;
                margin-bottom: 4px;
            }

            .form-control-sm {
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

            .top-bar .header-actions .btn {
                font-size: 11px;
                padding: 4px 10px;
            }

            .card-custom .card-header-custom {
                padding: 12px 16px;
            }

            .card-custom .card-body-custom {
                padding: 14px 16px;
            }

            .detail-label {
                font-size: 12px;
            }

            .detail-value {
                font-size: 13px;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>

            <nav class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <h4><i class="fas fa-graduation-cap me-2"></i>EduTrack</h4>
                    <small>Platform Administration</small>
                </div>
                <div class="nav">
                    <div class="nav-label">Main</div>
                    <a class="nav-link" href="/platform/index.php"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a>
                    <a class="nav-link" href="/platform/tenants/index.php"><i class="fas fa-building"></i> <span>Tenants</span></a>
                    <a class="nav-link" href="/platform/users/index.php"><i class="fas fa-users"></i> <span>Users</span></a>

                    <div class="nav-label mt-3">Institution</div>
                    <a class="nav-link" href="/platform/schools/index.php"><i class="fas fa-school"></i> <span>Schools</span></a>
                    <a class="nav-link" href="/platform/campuses/index.php"><i class="fas fa-map-marker-alt"></i> <span>Campuses</span></a>

                    <div class="nav-label mt-3">Management</div>
                    <a class="nav-link active" href="/platform/domains/index.php"><i class="fas fa-globe"></i> <span>Domains</span></a>
                    <a class="nav-link" href="/platform/subscriptions/index.php"><i class="fas fa-crown"></i> <span>Subscriptions</span></a>

                    <div class="nav-label mt-3">System</div>
                    <a class="nav-link" href="/platform/audit/index.php"><i class="fas fa-history"></i> <span>Audit Logs</span></a>
                    <a class="nav-link" href="/platform/monitoring/index.php"><i class="fas fa-chart-line"></i> <span>Monitoring</span></a>
                    <a class="nav-link" href="/platform/settings/index.php"><i class="fas fa-cog"></i> <span>Settings</span></a>
                </div>
                <div class="sidebar-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="user-info">
                            <div class="user-avatar" id="userAvatar"><?php echo $userInitial; ?></div>
                            <div>
                                <div class="user-name" id="userName"><?php echo htmlspecialchars($userName); ?></div>
                                <div class="user-role">Super Administrator</div>
                            </div>
                        </div>
                        <button class="logout-btn" onclick="logout()" title="Logout">
                            <i class="fas fa-sign-out-alt"></i>
                        </button>
                    </div>
                </div>
            </nav>

            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-globe me-2"></i>Domain Details</h1>
                        <p>View domain configuration and status</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/domains/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Domains
                        </a>
                        <a href="/platform/domains/edit.php?id=<?php echo $domainId; ?>" class="btn btn-primary">
                            <i class="fas fa-edit me-2"></i> Edit Domain
                        </a>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer">
                    <?php if (!empty($dnsUpdateMessage)): ?>
                        <div class="alert alert-<?php echo $dnsUpdateType; ?> alert-dismissible fade show" role="alert">
                            <i class="fas <?php echo $dnsUpdateType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> me-2"></i>
                            <?php echo htmlspecialchars($dnsUpdateMessage); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Domain Information -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-info-circle me-2 text-primary"></i>Domain Information</h6>
                        <span class="badge-status <?php echo $domain['status']; ?>"><?php echo ucfirst($domain['status']); ?></span>
                    </div>
                    <div class="card-body-custom">
                        <?php
                        $primaryBadge = $domain['is_primary'] ? '<span class="badge-primary">Primary</span>' : 'No';
                        $sslBadge = $domain['ssl_enabled'] ? '<span class="badge-ssl enabled">SSL Enabled</span>' : '<span class="badge-ssl disabled">SSL Disabled</span>';
                        $tenantName = $domain['tenant_name'] ?? 'Tenant #' . $domain['tenant_id'];
                        $verifiedBadge = $domain['is_verified'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>';
                        ?>
                        <div class="detail-row">
                            <div class="detail-label">Domain Name</div>
                            <div class="detail-value"><strong><?php echo htmlspecialchars($domain['domain_name']); ?></strong></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Tenant</div>
                            <div class="detail-value"><?php echo htmlspecialchars($tenantName); ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Status</div>
                            <div class="detail-value"><span class="badge-status <?php echo $domain['status']; ?>"><?php echo ucfirst($domain['status']); ?></span></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Primary Domain</div>
                            <div class="detail-value"><?php echo $primaryBadge; ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">SSL</div>
                            <div class="detail-value"><?php echo $sslBadge; ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Domain Type</div>
                            <div class="detail-value"><?php echo ucfirst($domain['domain_type'] ?? 'secondary'); ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Verified</div>
                            <div class="detail-value"><?php echo $verifiedBadge; ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Created</div>
                            <div class="detail-value"><?php echo date('M d, Y H:i:s', strtotime($domain['created_at'])); ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Last Updated</div>
                            <div class="detail-value"><?php echo date('M d, Y H:i:s', strtotime($domain['updated_at'])); ?></div>
                        </div>
                    </div>
                </div>

                <!-- DNS Configuration -->
                <div class="card-custom" id="dnsCard">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-server me-2 text-primary"></i>DNS Configuration</h6>
                        <div>
                            <button class="btn btn-sm btn-outline-primary" id="editDnsBtn" onclick="toggleDnsEdit()">
                                <i class="fas fa-edit me-1"></i> Edit DNS
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" id="cancelDnsBtn" onclick="toggleDnsEdit()" style="display:none;">
                                <i class="fas fa-times me-1"></i> Cancel
                            </button>
                        </div>
                    </div>
                    <div class="card-body-custom">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>DNS Records Required:</strong> Configure the following DNS records for this domain at your domain registrar. The IP address can be edited below.
                        </div>
                        <form method="POST" action="" id="dnsForm">
                            <input type="hidden" name="action" value="update_dns">
                            <div class="table-responsive">
                                <table class="table table-bordered" style="font-size:13px;">
                                    <thead>
                                        <tr style="background:#f8f9fa;">
                                            <th>Type</th>
                                            <th>Name</th>
                                            <th>Value</th>
                                            <th>TTL</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><span class="badge bg-primary">A</span></td>
                                            <td>@ or <?php echo htmlspecialchars($domain['domain_name']); ?></td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" id="dnsIpAddress"
                                                    name="dns_ip" value="<?php echo htmlspecialchars($domain['dns_ip'] ?? $serverIp); ?>"
                                                    readonly>
                                            </td>
                                            <td>3600</td>
                                        </tr>
                                        <tr>
                                            <td><span class="badge bg-primary">A</span></td>
                                            <td>www</td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" id="dnsIpAddress2"
                                                    value="<?php echo htmlspecialchars($domain['dns_ip'] ?? $serverIp); ?>"
                                                    readonly>
                                            </td>
                                            <td>3600</td>
                                        </tr>
                                        <tr>
                                            <td><span class="badge bg-success">CNAME</span></td>
                                            <td>*.<?php echo htmlspecialchars($domain['domain_name']); ?></td>
                                            <td><code><?php echo htmlspecialchars($domain['domain_name']); ?></code></td>
                                            <td>3600</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div id="dnsEditActions" class="dns-edit-actions">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="fas fa-save me-1"></i> Save DNS Settings
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetDns()">
                                    <i class="fas fa-undo me-1"></i> Reset
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Actions -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-cog me-2 text-primary"></i>Actions</h6>
                    </div>
                    <div class="card-body-custom">
                        <div class="d-flex gap-2 flex-wrap">
                            <?php if ($domain['status'] !== 'verified'): ?>
                                <button class="btn btn-success" onclick="verifyDomain(<?php echo $domainId; ?>)">
                                    <i class="fas fa-check-circle me-2"></i> Verify Domain
                                </button>
                            <?php endif; ?>
                            <?php if ($domain['status'] === 'active' || $domain['status'] === 'verified'): ?>
                                <button class="btn btn-warning" onclick="toggleDomainStatus(<?php echo $domainId; ?>, 'deactivate')">
                                    <i class="fas fa-pause me-2"></i> Deactivate
                                </button>
                            <?php elseif ($domain['status'] === 'inactive'): ?>
                                <button class="btn btn-success" onclick="toggleDomainStatus(<?php echo $domainId; ?>, 'activate')">
                                    <i class="fas fa-play me-2"></i> Activate
                                </button>
                            <?php endif; ?>
                            <a href="/platform/domains/edit.php?id=<?php echo $domainId; ?>" class="btn btn-primary">
                                <i class="fas fa-edit me-2"></i> Edit Domain
                            </a>
                            <button class="btn btn-danger" onclick="deleteDomain(<?php echo $domainId; ?>)">
                                <i class="fas fa-trash me-2"></i> Delete Domain
                            </button>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
                window.location.href = '/platform/login.php';
            }
        }

        // ================================================
        // DNS EDIT TOGGLE
        // ================================================
        function toggleDnsEdit() {
            const editBtn = document.getElementById('editDnsBtn');
            const cancelBtn = document.getElementById('cancelDnsBtn');
            const actions = document.getElementById('dnsEditActions');
            const ip1 = document.getElementById('dnsIpAddress');
            const ip2 = document.getElementById('dnsIpAddress2');

            if (ip1.hasAttribute('readonly')) {
                // Enable editing
                ip1.removeAttribute('readonly');
                ip2.removeAttribute('readonly');
                ip1.style.borderColor = '#4facfe';
                ip2.style.borderColor = '#4facfe';
                editBtn.style.display = 'none';
                cancelBtn.style.display = 'inline-block';
                actions.classList.add('show');
                showAlert('DNS fields are now editable. Click Save to update.', 'info');
            } else {
                // Cancel editing
                ip1.setAttribute('readonly', true);
                ip2.setAttribute('readonly', true);
                ip1.style.borderColor = '#e9ecef';
                ip2.style.borderColor = '#e9ecef';
                editBtn.style.display = 'inline-block';
                cancelBtn.style.display = 'none';
                actions.classList.remove('show');
                // Reset to original values
                const originalIp = '<?php echo htmlspecialchars($domain['dns_ip'] ?? $serverIp); ?>';
                ip1.value = originalIp;
                ip2.value = originalIp;
                hideAlert();
            }
        }

        // ================================================
        // RESET DNS
        // ================================================
        function resetDns() {
            const defaultIp = '<?php echo $_SERVER['SERVER_ADDR'] ?? '192.168.1.1'; ?>';
            document.getElementById('dnsIpAddress').value = defaultIp;
            document.getElementById('dnsIpAddress2').value = defaultIp;
            showAlert('DNS reset to default IP', 'info');
        }

        // ================================================
        // SHOW/HIDE ALERT
        // ================================================
        function showAlert(message, type) {
            const container = document.getElementById('alertContainer');
            const colors = {
                success: 'alert-success',
                danger: 'alert-danger',
                warning: 'alert-warning',
                info: 'alert-info'
            };
            const icons = {
                success: 'fa-check-circle',
                danger: 'fa-exclamation-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            };
            container.innerHTML = `
                <div class="alert ${colors[type] || 'alert-info'} alert-dismissible fade show" role="alert" style="border-radius:12px;border:none;box-shadow:0 4px 20px rgba(0,0,0,0.06);">
                    <i class="fas ${icons[type] || 'fa-info-circle'} me-2"></i>
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

        function hideAlert() {
            document.getElementById('alertContainer').innerHTML = '';
        }

        // ================================================
        // VERIFY DOMAIN
        // ================================================
        function verifyDomain(id) {
            if (!confirm('Verify this domain? This will check DNS records and SSL certificate.')) return;

            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying...';

            fetch('/api/platform/index.php?endpoint=domains&action=verify&id=' + id, {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + (localStorage.getItem('token') || ''),
                        'Content-Type': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showAlert('Domain verified successfully!', 'success');
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        showAlert('✗ ' + (result.message || 'Domain verification failed'), 'danger');
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }
                })
                .catch(error => {
                    showAlert('Error verifying domain: ' + error.message, 'danger');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
        }

        // ================================================
        // TOGGLE DOMAIN STATUS
        // ================================================
        function toggleDomainStatus(id, action) {
            const actionText = action === 'activate' ? 'activate' : 'deactivate';
            if (!confirm(`Are you sure you want to ${actionText} this domain?`)) return;

            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';

            fetch('/api/platform/index.php?endpoint=domains&action=' + action + '&id=' + id, {
                    method: 'PUT',
                    headers: {
                        'Authorization': 'Bearer ' + (localStorage.getItem('token') || ''),
                        'Content-Type': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showAlert(`Domain ${actionText}d successfully!`, 'success');
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        showAlert('✗ ' + (result.message || `Failed to ${actionText} domain`), 'danger');
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }
                })
                .catch(error => {
                    showAlert('Error: ' + error.message, 'danger');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
        }

        // ================================================
        // DELETE DOMAIN
        // ================================================
        function deleteDomain(id) {
            if (!confirm('Are you sure you want to delete this domain? This action cannot be undone.')) return;
            if (!confirm('Really? This will permanently remove the domain.')) return;

            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Deleting...';

            fetch('/api/platform/index.php?endpoint=domains&action=delete&id=' + id, {
                    method: 'DELETE',
                    headers: {
                        'Authorization': 'Bearer ' + (localStorage.getItem('token') || ''),
                        'Content-Type': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showAlert('Domain deleted successfully', 'success');
                        setTimeout(() => {
                            window.location.href = '/platform/domains/index.php';
                        }, 1500);
                    } else {
                        showAlert('✗ ' + (result.message || 'Failed to delete domain'), 'danger');
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }
                })
                .catch(error => {
                    showAlert('Error deleting domain: ' + error.message, 'danger');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
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
                } catch (e) {
                    console.error('Error parsing user:', e);
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();
        });
    </script>
</body>

</html>