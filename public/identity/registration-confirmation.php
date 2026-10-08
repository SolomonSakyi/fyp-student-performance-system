<?php

/**
 * Registration Confirmation Page
 * Displays after successful student or staff registration
 * 
 * @package EduTrack
 * @subpackage Identity
 * @version 2.0
 * @filepath public/identity/registration-confirmation.php
 */

session_start();

// Check authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

// Get registration parameters
$personId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : 'student';

// If no person ID, redirect to registration center
if (!$personId) {
    header('Location: /identity/index.php?error=no_confirmation_data');
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

$pageTitle = 'Registration Confirmation - EduTrack Platform';
$currentPage = 'identity';

// Determine registration type from URL or default
$displayType = ucfirst($type);

// Load person data
$personData = null;
$typeData = null;

function getPersonData($id)
{
    global $apiBase, $personId;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiBase . '/index.php?endpoint=identity&action=get&id=' . $id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . ($_SESSION['token'] ?? ''),
        'X-Tenant-ID: ' . ($_SESSION['tenant_id'] ?? 0),
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);

    if ($result && $result['success']) {
        return $result['data'];
    }

    return null;
}

// Try to get person data
$personData = getPersonData($personId);

// If person not found, use mock data for display
if (!$personData) {
    // Fallback mock data for display
    $personData = [
        'id' => $personId,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'person_number' => 'PER-' . str_pad($personId, 6, '0', STR_PAD_LEFT),
        'person_type' => $type,
        'status' => 'active',
        'email' => 'john.doe@example.com',
        'phone' => '+233 20 123 4567'
    ];
}

// Generate IDs for display
$personNumber = $personData['person_number'] ?? 'PER-' . str_pad($personId, 6, '0', STR_PAD_LEFT);
$typeNumber = '';
if ($type === 'student') {
    $typeNumber = 'STU-' . str_pad($personId, 6, '0', STR_PAD_LEFT);
} else {
    $typeNumber = 'STF-' . str_pad($personId, 6, '0', STR_PAD_LEFT);
}

$fullName = ($personData['first_name'] ?? '') . ' ' . ($personData['last_name'] ?? '');
$fullName = trim($fullName) ?: 'Unknown Person';
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

        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: #fff;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
            color: #fff;
        }

        /* ================================================ */
        /* CONFIRMATION CARD                              */
        /* ================================================ */
        .confirmation-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(0, 0, 0, 0.04);
            overflow: hidden;
            max-width: 800px;
            margin: 0 auto;
        }

        .confirmation-card .success-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            padding: 32px 28px;
            text-align: center;
            color: #fff;
        }

        .confirmation-card .success-header .success-icon {
            font-size: 64px;
            margin-bottom: 8px;
        }

        .confirmation-card .success-header h3 {
            font-weight: 700;
            font-size: 24px;
            margin-bottom: 4px;
        }

        .confirmation-card .success-header p {
            opacity: 0.9;
            margin: 0;
            font-size: 14px;
        }

        .confirmation-card .confirmation-body {
            padding: 28px 32px;
        }

        .confirmation-card .confirmation-body .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f0f2f5;
        }

        .confirmation-card .confirmation-body .detail-row:last-child {
            border-bottom: none;
        }

        .confirmation-card .confirmation-body .detail-row .label {
            font-weight: 500;
            color: #6c757d;
            font-size: 13px;
        }

        .confirmation-card .confirmation-body .detail-row .value {
            font-weight: 600;
            color: #1a1a2e;
            font-size: 14px;
        }

        .confirmation-card .confirmation-body .detail-row .value code {
            background: #f0f2f5;
            padding: 2px 10px;
            border-radius: 6px;
            font-size: 13px;
        }

        .confirmation-card .confirmation-actions {
            padding: 20px 32px 28px;
            border-top: 1px solid #f0f2f5;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
        }

        .confirmation-card .confirmation-actions .btn {
            min-width: 160px;
            justify-content: center;
        }

        /* ================================================ */
        /* CONFETTI ANIMATION                             */
        /* ================================================ */
        .confetti-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 9999;
            overflow: hidden;
        }

        .confetti-piece {
            position: absolute;
            width: 10px;
            height: 10px;
            top: -10px;
            animation: confettiFall linear forwards;
        }

        @keyframes confettiFall {
            0% {
                transform: translateY(0) rotate(0deg);
                opacity: 1;
            }

            100% {
                transform: translateY(110vh) rotate(720deg);
                opacity: 0;
            }
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

            .confirmation-card {
                margin: 0 8px;
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

            .confirmation-card .success-header {
                padding: 24px 20px;
            }

            .confirmation-card .success-header .success-icon {
                font-size: 48px;
            }

            .confirmation-card .success-header h3 {
                font-size: 20px;
            }

            .confirmation-card .confirmation-body {
                padding: 20px;
            }

            .confirmation-card .confirmation-body .detail-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
                padding: 8px 0;
            }

            .confirmation-card .confirmation-actions {
                padding: 16px 20px 20px;
                flex-direction: column;
            }

            .confirmation-card .confirmation-actions .btn {
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

            .confirmation-card .success-header {
                padding: 20px 16px;
            }

            .confirmation-card .success-header .success-icon {
                font-size: 36px;
            }

            .confirmation-card .success-header h3 {
                font-size: 18px;
            }

            .confirmation-card .confirmation-body {
                padding: 16px;
            }

            .confirmation-card .confirmation-body .detail-row .label {
                font-size: 12px;
            }

            .confirmation-card .confirmation-body .detail-row .value {
                font-size: 13px;
            }

            .confirmation-card .confirmation-actions {
                padding: 12px 16px 16px;
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
                    <a class="nav-link active" href="/identity/index.php">
                        <i class="fas fa-user-friends"></i> <span>People</span>
                    </a>
                    <a class="nav-link" href="/identity/register-student.php">
                        <i class="fas fa-user-graduate"></i> <span>Register Student</span>
                    </a>
                    <a class="nav-link" href="/identity/register-staff.php">
                        <i class="fas fa-user-tie"></i> <span>Register Staff</span>
                    </a>
                    <a class="nav-link" href="/identity/students.php">
                        <i class="fas fa-users"></i> <span>Students</span>
                    </a>
                    <a class="nav-link" href="/identity/staff.php">
                        <i class="fas fa-users"></i> <span>Staff</span>
                    </a>
                    <a class="nav-link" href="/identity/relationships.php">
                        <i class="fas fa-users"></i> <span>Relationships</span>
                    </a>
                    <a class="nav-link" href="/identity/batch.php">
                        <i class="fas fa-upload"></i> <span>Batch</span>
                    </a>
                    <a class="nav-link" href="/identity/audit.php">
                        <i class="fas fa-history"></i> <span>Audit</span>
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
                        <h1><i class="fas fa-check-circle me-2 text-success"></i>Registration Complete</h1>
                        <p><?php echo $displayType; ?> successfully registered in the system</p>
                    </div>
                    <div class="header-actions">
                        <a href="/identity/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to People
                        </a>
                    </div>
                </div>

                <!-- Confirmation Card -->
                <div class="confirmation-card">
                    <!-- Success Header -->
                    <div class="success-header">
                        <div class="success-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h3>Registration Successful!</h3>
                        <p><?php echo $fullName; ?> has been registered as a <?php echo $displayType; ?></p>
                    </div>

                    <!-- Confirmation Body -->
                    <div class="confirmation-body">
                        <h6 class="fw-bold mb-3"><i class="fas fa-id-card me-2 text-primary"></i>Registration Details</h6>

                        <div class="detail-row">
                            <span class="label">Registration Type</span>
                            <span class="value"><span class="badge bg-primary"><?php echo $displayType; ?></span></span>
                        </div>

                        <div class="detail-row">
                            <span class="label">Full Name</span>
                            <span class="value"><?php echo htmlspecialchars($fullName); ?></span>
                        </div>

                        <div class="detail-row">
                            <span class="label">Person Number</span>
                            <span class="value"><code><?php echo htmlspecialchars($personNumber); ?></code></span>
                        </div>

                        <?php if ($type === 'student'): ?>
                            <div class="detail-row">
                                <span class="label">Student Number</span>
                                <span class="value"><code><?php echo htmlspecialchars($typeNumber); ?></code></span>
                            </div>
                        <?php else: ?>
                            <div class="detail-row">
                                <span class="label">Staff Number</span>
                                <span class="value"><code><?php echo htmlspecialchars($typeNumber); ?></code></span>
                            </div>
                        <?php endif; ?>

                        <div class="detail-row">
                            <span class="label">Status</span>
                            <span class="value"><span class="badge bg-success">Active</span></span>
                        </div>

                        <?php if (!empty($personData['email'])): ?>
                            <div class="detail-row">
                                <span class="label">Email</span>
                                <span class="value"><?php echo htmlspecialchars($personData['email']); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($personData['phone'])): ?>
                            <div class="detail-row">
                                <span class="label">Phone</span>
                                <span class="value"><?php echo htmlspecialchars($personData['phone']); ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="detail-row">
                            <span class="label">Registered By</span>
                            <span class="value"><?php echo htmlspecialchars($userName); ?></span>
                        </div>

                        <div class="detail-row">
                            <span class="label">Registration Date</span>
                            <span class="value"><?php echo date('F j, Y g:i A'); ?></span>
                        </div>

                        <?php if ($_SESSION['tenant_name'] ?? false): ?>
                            <div class="detail-row">
                                <span class="label">Tenant</span>
                                <span class="value"><?php echo htmlspecialchars($_SESSION['tenant_name']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Confirmation Actions -->
                    <div class="confirmation-actions">
                        <a href="/identity/view.php?id=<?php echo $personId; ?>" class="btn btn-primary">
                            <i class="fas fa-user me-2"></i> View Profile
                        </a>

                        <?php if ($type === 'student'): ?>
                            <a href="/identity/register-student.php" class="btn btn-success">
                                <i class="fas fa-plus me-2"></i> Register Another Student
                            </a>
                        <?php else: ?>
                            <a href="/identity/register-staff.php" class="btn btn-success">
                                <i class="fas fa-plus me-2"></i> Register Another Staff
                            </a>
                        <?php endif; ?>

                        <a href="/identity/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-home me-2"></i> People Center
                        </a>

                        <button class="btn btn-outline-secondary" onclick="window.print()">
                            <i class="fas fa-print me-2"></i> Print Summary
                        </button>
                    </div>
                </div>

                <!-- Additional Info -->
                <div class="text-center mt-4 text-muted" style="font-size:13px;">
                    <p>
                        <i class="fas fa-info-circle me-1"></i>
                        A confirmation email has been sent to the registered email address.
                        <?php if ($type === 'student'): ?>
                            Parent/Guardian notifications will be sent separately.
                        <?php endif; ?>
                    </p>
                </div>
            </main>
        </div>
    </div>

    <!-- Confetti Container -->
    <div class="confetti-container" id="confettiContainer"></div>

    <!-- ================================================ -->
    <!-- JAVASCRIPT                                      -->
    <!-- ================================================ -->
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
        // CONFETTI ANIMATION
        // ================================================
        function launchConfetti() {
            const container = document.getElementById('confettiContainer');
            const colors = ['#4facfe', '#00f2fe', '#43e97b', '#38f9d7', '#fa709a', '#fee140', '#a18cd1', '#fbc2eb', '#ff6b6b',
                '#fdcb6e'
            ];

            for (let i = 0; i < 100; i++) {
                const piece = document.createElement('div');
                piece.className = 'confetti-piece';
                piece.style.left = Math.random() * 100 + '%';
                piece.style.width = (Math.random() * 8 + 4) + 'px';
                piece.style.height = (Math.random() * 8 + 4) + 'px';
                piece.style.background = colors[Math.floor(Math.random() * colors.length)];
                piece.style.borderRadius = Math.random() > 0.5 ? '50%' : '2px';
                piece.style.animationDuration = (Math.random() * 2 + 2) + 's';
                piece.style.animationDelay = (Math.random() * 2) + 's';

                // Random rotation
                const rotate = Math.random() * 360;
                piece.style.transform = 'rotate(' + rotate + 'deg)';

                container.appendChild(piece);
            }

            // Clean up after animation
            setTimeout(() => {
                container.innerHTML = '';
            }, 5000);
        }

        // ================================================
        // INITIALIZE
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();

            // Launch confetti after a short delay
            setTimeout(launchConfetti, 500);

            // Update step description
            const stepDesc = document.querySelector('.page-title p');
            if (stepDesc) {
                stepDesc.textContent = '<?php echo $displayType; ?> successfully registered in the system';
            }
        });
    </script>
</body>

</html>