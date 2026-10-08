<?php

/**
 * View Staff - Read-only profile view for a single staff member.
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Staff
 * @version 1.0
 * @filepath public/platform/tenant/staff/view.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   View Staff file of the tenant-surface sweep. Three changes:
 *     - A docblock was added. The file previously carried no
 *       @version, @package, or @filepath tag. The new docblock
 *       carries them.
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - The inline <nav class="sidebar" id="sidebar"> block is
 *       removed and replaced by an include of
 *       app/views/partials/sidebar.php. This file's inline
 *       sidebar carried six items (Dashboard; Schools, Campuses;
 *       Staff, Students; Settings) and no Subscription,
 *       Notifications, or Logout nav link. The partial carries
 *       all nine. After the refactor, this page renders the
 *       Subscription, Notifications, and Logout items as well.
 *       The inline sidebar's Settings link pointed at
 *       /platform/tenant/settings.php; the partial's Settings
 *       link points at /platform/tenant/settings/index.php.
 *   The Provision User / Manage User button, the Digital
 *   Signature card, and every one of the twelve profile cards is
 *   preserved unchanged. Every other line of the file is
 *   byte-identical to the pre-sweep version. The @package tag is
 *   'EduTrack'.
 */

// ERROR REPORTING
// ============================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// SESSION & AUTHENTICATION
// ============================================
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] != true) {
    header('Location: /platform/tenant/login.php');
    exit;
}

// ORGANIZATIONAL CONTEXT
$tenantId = $_SESSION['tenant_id'] ?? 0;
$schoolId = $_SESSION['school_id'] ?? 0;
$campusId = $_SESSION['campus_id'] ?? 0;
$userId = $_SESSION['user_id'] ?? 0;
$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userAvatar = substr($currentUser, 0, 1);
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;

if (!$tenantId) {
    $_SESSION['errors'] = ['No tenant context found.'];
    header('Location: /platform/tenants/select.php');
    exit;
}

// STAFF ID
$staffId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($staffId <= 0) {
    $_SESSION['errors'] = ['Invalid staff ID.'];
    header('Location: /platform/tenant/staff/index.php');
    exit;
}

$pageTitle = 'View Staff - Student 360 Platform';
$currentPage = 'staff';

// DATABASE & CONFIGURATION
$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/config/config.php';
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';

$db = DatabaseHelper::getInstance();

// LOAD STAFF RECORD
$staff = $db->fetchOne(
    "SELECT s.*, p.first_name, p.middle_name, p.last_name, p.preferred_name,
            p.date_of_birth, p.gender, p.nationality, p.religion, p.marital_status, p.home_town,
            p.primary_phone, p.secondary_phone, p.email, p.secondary_email, p.work_email, p.work_phone,
            p.address, p.town_city, p.district, p.gps_address, p.post_address, p.region,
            p.id AS person_id, p.profile_photo_url,
            sc.category_name AS staff_category_name,
            st.type_name AS staff_type_name,
            et.employment_type_name, et.is_contract,
            ss.status_name AS staff_status_name,
            d.department_name,
            sch.school_name
     FROM staff s
     LEFT JOIN persons p ON s.person_id = p.id
     LEFT JOIN staff_categories sc ON s.staff_category_id = sc.id
     LEFT JOIN staff_types st ON s.staff_type_id = st.id
     LEFT JOIN employment_types et ON s.employment_type_id = et.id
     LEFT JOIN staff_statuses ss ON s.staff_status_id = ss.id
     LEFT JOIN departments d ON s.department_id = d.id
     LEFT JOIN schools sch ON s.school_id = sch.id
     WHERE s.id = ? AND s.tenant_id = ? AND s.deleted_at IS NULL",
    [$staffId, $tenantId]
);

if (!$staff) {
    $_SESSION['errors'] = ['Staff record not found.'];
    header('Location: /platform/tenant/staff/index.php');
    exit;
}

$personId = (int)$staff['person_id'];

// LOAD RELATED RECORDS
$emergencyContacts = $db->fetchAll(
    "SELECT * FROM staff_emergency_contacts WHERE staff_id = ? AND deleted_at IS NULL ORDER BY sort_order ASC, id ASC",
    [$staffId]
);

$qualifications = $db->fetchAll(
    "SELECT * FROM staff_qualifications WHERE staff_id = ? AND deleted_at IS NULL ORDER BY id ASC",
    [$staffId]
);

$identifications = $db->fetchAll(
    "SELECT * FROM staff_identifications WHERE staff_id = ? AND deleted_at IS NULL ORDER BY is_primary DESC, id ASC",
    [$staffId]
);

$licenses = $db->fetchAll(
    "SELECT * FROM staff_licenses WHERE staff_id = ? AND deleted_at IS NULL ORDER BY id ASC",
    [$staffId]
);

$academicAssignments = $db->fetchAll(
    "SELECT a.*, ay.year_name, at.term_name
     FROM staff_academic_assignments a
     LEFT JOIN academic_years ay ON a.academic_year_id = ay.id
     LEFT JOIN academic_terms at ON a.academic_term_id = at.id
     WHERE a.staff_id = ? AND a.deleted_at IS NULL
     ORDER BY a.is_primary DESC, a.id ASC",
    [$staffId]
);

$biometrics = $db->fetchAll(
    "SELECT * FROM staff_biometric_data WHERE staff_id = ? AND deleted_at IS NULL ORDER BY is_primary DESC, id ASC",
    [$staffId]
);

$biographic = $db->fetchOne(
    "SELECT * FROM staff_biographic_data WHERE staff_id = ? AND deleted_at IS NULL LIMIT 1",
    [$staffId]
);
if (!$biographic) $biographic = [];

$signature = $db->fetchOne(
    "SELECT * FROM staff_signatures WHERE staff_id = ? AND deleted_at IS NULL ORDER BY is_primary DESC, id DESC LIMIT 1",
    [$staffId]
);

// Check genotype column
$hasGenotypeColumn = false;
try {
    $colCheck = $db->fetchOne("SHOW COLUMNS FROM staff_biographic_data LIKE 'genotype'");
    $hasGenotypeColumn = !empty($colCheck);
} catch (Exception $e) {
    $hasGenotypeColumn = false;
}

// MANAGER
$manager = null;
if (!empty($staff['reporting_manager_id'])) {
    $manager = $db->fetchOne(
        "SELECT s.id, p.first_name, p.last_name, s.staff_number FROM staff s LEFT JOIN persons p ON s.person_id = p.id WHERE s.id = ? AND s.deleted_at IS NULL",
        [$staff['reporting_manager_id']]
    );
}

// PLATFORM USER
$platformUser = null;
if (!empty($staff['platform_user_id'])) {
    $platformUser = $db->fetchOne(
        "SELECT id, first_name, last_name, username FROM platform_users WHERE id = ? AND deleted_at IS NULL",
        [$staff['platform_user_id']]
    );
}

// LOOKUP MAPS for assignment display
$classesMap = [];
foreach ($db->fetchAll("SELECT id, class_name FROM classes WHERE tenant_id = ?", [$tenantId]) as $c) {
    $classesMap[$c['id']] = $c['class_name'];
}
$subjectsMap = [];
foreach ($db->fetchAll("SELECT id, subject_name FROM subjects WHERE tenant_id = ?", [$tenantId]) as $s) {
    $subjectsMap[$s['id']] = $s['subject_name'];
}
$streamsMap = [];
foreach ($db->fetchAll("SELECT id, stream_name FROM streams WHERE tenant_id = ?", [$tenantId]) as $s) {
    $streamsMap[$s['id']] = $s['stream_name'];
}

// ORG CONTEXT
$tenantName = '';
$tenant = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
if ($tenant) {
    $tenantName = $tenant['tenant_name'] ?? 'Tenant #' . $tenantId;
}

$schoolName = $staff['school_name'] ?? '';

// HELPERS
function h($v)
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function showVal($v, $fallback = '—')
{
    $s = trim((string)($v ?? ''));
    return $s === '' ? '<span class="text-muted fst-italic">' . $fallback . '</span>' : h($s);
}
function fmtDate($d)
{
    if (empty($d) || $d === '0000-00-00') return '<span class="text-muted fst-italic">—</span>';
    $ts = strtotime($d);
    return $ts ? date('M d, Y', $ts) : h($d);
}
function fmtDateTime($d)
{
    if (empty($d) || $d === '0000-00-00 00:00:00') return '<span class="text-muted fst-italic">—</span>';
    $ts = strtotime($d);
    return $ts ? date('M d, Y H:i', $ts) : h($d);
}
function parseCsvNames($csv, $map)
{
    if (empty($csv)) return [];
    $ids = array_filter(array_map('intval', explode(',', $csv)));
    $out = [];
    foreach ($ids as $id) {
        if (isset($map[$id])) $out[] = $map[$id];
    }
    return $out;
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
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
            font-family: 'Inter', sans-serif;
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
            color: #fff;
        }

        .main-content {
            margin-left: 260px;
            width: calc(100% - 260px);
            padding: 20px;
            max-width: 100%;
            overflow-x: hidden;
        }

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

        .btn-primary {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border: none;
            color: #fff;
            border-radius: 10px;
            padding: 10px 24px;
            font-weight: 600;
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
            border-radius: 10px;
            padding: 10px 24px;
            font-weight: 500;
        }

        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: #ced4da;
        }

        .logout-btn {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            border-radius: 8px;
            padding: 6px 12px;
            cursor: pointer;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .staff-hero {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border-radius: 20px;
            padding: 30px;
            color: #fff;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 24px;
            flex-wrap: wrap;
            box-shadow: 0 12px 40px rgba(79, 172, 254, 0.25);
            max-width: 1100px;
            margin-left: auto;
            margin-right: auto;
            position: relative;
            overflow: hidden;
        }

        .staff-hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 400px;
            height: 400px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
        }

        .staff-hero .avatar-lg {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
            border: 3px solid rgba(255, 255, 255, 0.4);
            z-index: 1;
            overflow: hidden;
        }

        .staff-hero .hero-info {
            flex: 1;
            z-index: 1;
            min-width: 240px;
        }

        .staff-hero .hero-info h2 {
            font-size: 26px;
            font-weight: 800;
            margin: 0 0 4px 0;
            letter-spacing: -0.5px;
        }

        .staff-hero .hero-info .staff-num {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 8px;
        }

        .staff-hero .hero-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .staff-hero .hero-badge {
            background: rgba(255, 255, 255, 0.18);
            color: #fff;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            backdrop-filter: blur(10px);
        }

        .staff-hero .hero-badge.status-active {
            background: rgba(34, 197, 94, 0.35);
        }

        .staff-hero .hero-badge.status-inactive {
            background: rgba(239, 68, 68, 0.35);
        }

        .staff-hero .hero-badge.teaching {
            background: rgba(255, 255, 255, 0.25);
        }

        .card-custom {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
            overflow: hidden;
            width: 100%;
            max-width: 1100px;
            margin-left: auto;
            margin-right: auto;
        }

        .card-custom .card-header-custom {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        .data-field {
            padding: 8px 0;
        }

        .data-field .data-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            margin-bottom: 4px;
        }

        .data-field .data-value {
            font-size: 14px;
            color: #1a1a2e;
            font-weight: 500;
            word-break: break-word;
        }

        .data-field .data-value a {
            color: #0d6efd;
            text-decoration: none;
        }

        .data-field .data-value a:hover {
            text-decoration: underline;
        }

        .badge-soft {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge-soft.blue {
            background: #e3f0ff;
            color: #0d6efd;
        }

        .badge-soft.green {
            background: #dcfce7;
            color: #16a34a;
        }

        .badge-soft.amber {
            background: #fef3c7;
            color: #d97706;
        }

        .badge-soft.red {
            background: #fee2e2;
            color: #dc2626;
        }

        .badge-soft.gray {
            background: #f3f4f6;
            color: #4b5563;
        }

        .badge-soft.purple {
            background: #ede9fe;
            color: #7c3aed;
        }

        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: #9ca3af;
        }

        .empty-state i {
            font-size: 36px;
            margin-bottom: 8px;
            opacity: 0.5;
        }

        .empty-state p {
            margin: 0;
            font-size: 13px;
        }

        .signature-preview {
            border: 2px dashed #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            background: #f9fafb;
            text-align: center;
        }

        .signature-preview img {
            max-width: 100%;
            max-height: 150px;
        }

        .assignment-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            border-left: 4px solid #4facfe;
        }

        .assignment-card:last-child {
            margin-bottom: 0;
        }

        .assignment-card .assign-title {
            font-weight: 600;
            font-size: 14px;
            color: #1a1a2e;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .assignment-card .assign-meta {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 10px;
        }

        .assignment-card .assign-tags {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .tag {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 14px;
            font-size: 11px;
            font-weight: 500;
            background: #e3f0ff;
            color: #0d6efd;
        }

        .tag.subject {
            background: #f3e8ff;
            color: #7c3aed;
        }

        .tag.stream {
            background: #fef3c7;
            color: #d97706;
        }

        .tag.discipline {
            background: #fce7f3;
            color: #be185d;
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 72px;
                overflow: hidden;
            }

            .sidebar .sidebar-header h4 {
                font-size: 0;
            }

            .sidebar .nav-link span {
                display: none;
            }

            .sidebar .nav-link i {
                margin-right: 0;
            }

            .sidebar .nav-link {
                justify-content: center;
            }

            .main-content {
                margin-left: 72px;
                width: calc(100% - 72px);
            }

            .sidebar-toggle {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .sidebar-toggle {
                display: inline;
            }

            .sidebar {
                transform: translateX(-100%);
                width: 260px;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .sidebar .nav-link span {
                display: inline;
            }

            .sidebar .nav-link i {
                margin-right: 12px;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 16px;
                padding-top: 70px;
            }

            .staff-hero {
                padding: 20px;
            }

            .staff-hero .avatar-lg {
                width: 70px;
                height: 70px;
                font-size: 28px;
            }

            .staff-hero .hero-info h2 {
                font-size: 20px;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar"><i class="fas fa-bars"></i></button>

            <!-- Sidebar (includes app/views/partials/sidebar.php) -->
            <?php include $projectRoot . '/app/views/partials/sidebar.php'; ?>

            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-user-circle me-2"></i>Staff Profile</h1>
                        <p>Complete profile view</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/staff/edit.php?id=<?php echo $staffId; ?>" class="btn btn-primary"><i class="fas fa-edit me-2"></i>Edit</a>
                        <a href="/platform/tenant/staff/provision-user.php?id=<?php echo $staffId; ?>"
                            class="btn <?php echo $platformUser ? 'btn-outline-secondary' : 'btn-primary'; ?>">
                            <i class="fas <?php echo $platformUser ? 'fa-user-cog' : 'fa-user-plus'; ?> me-2"></i>
                            <?php echo $platformUser ? 'Manage User' : 'Provision User'; ?>
                        </a>
                        <a href="/platform/tenant/staff/index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Back to Staff</a>
                    </div>
                </div>

                <!-- STAFF HERO -->
                <div class="staff-hero">
                    <div class="avatar-lg">
                        <?php
                        $photoUrl = trim((string)($staff['profile_photo_url'] ?? ''));
                        $hasPhoto = false;
                        $resolvedPhoto = '';
                        if ($photoUrl !== '') {
                            if (preg_match('#^https?://#i', $photoUrl)) {
                                $resolvedPhoto = $photoUrl;
                                $hasPhoto = true;
                            } elseif (strpos($photoUrl, '/') === 0) {
                                $resolvedPhoto = $photoUrl;
                                $hasPhoto = true;
                            } else {
                                if (strpos($photoUrl, 'uploads/') === 0) {
                                    $resolvedPhoto = '/' . $photoUrl;
                                } else {
                                    $resolvedPhoto = '/uploads/' . ltrim($photoUrl, '/');
                                }
                                $hasPhoto = true;
                            }

                            if (strpos($resolvedPhoto, 'http') !== 0) {
                                $fsPath = $projectRoot . '/public' . $resolvedPhoto;
                                if (!is_file($fsPath)) {
                                    $hasPhoto = false;
                                }
                            }
                        }
                        $initials = strtoupper(substr($staff['first_name'] ?? '?', 0, 1) . substr($staff['last_name'] ?? '', 0, 1));
                        ?>
                        <?php if ($hasPhoto): ?>
                            <img src="<?php echo h($resolvedPhoto); ?>"
                                alt="<?php echo h(trim(($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''))); ?>"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                style="width:100%;height:100%;border-radius:50%;object-fit:cover;">
                            <span style="display:none; width:100%;height:100%;align-items:center;justify-content:center;">
                                <?php echo $initials; ?>
                            </span>
                        <?php else: ?>
                            <?php echo $initials; ?>
                        <?php endif; ?>
                    </div>
                    <div class="hero-info">
                        <h2><?php echo h(trim(($staff['first_name'] ?? '') . ' ' . ($staff['middle_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''))); ?></h2>
                        <div class="staff-num"><i class="fas fa-id-badge me-1"></i> <?php echo h($staff['staff_number'] ?? '—'); ?></div>
                        <div class="hero-badges">
                            <?php if (!empty($staff['staff_category_name'])): ?>
                                <span class="hero-badge"><i class="fas fa-layer-group me-1"></i><?php echo h($staff['staff_category_name']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($staff['staff_type_name'])): ?>
                                <span class="hero-badge"><?php echo h($staff['staff_type_name']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($staff['job_title'])): ?>
                                <span class="hero-badge"><i class="fas fa-briefcase me-1"></i><?php echo h($staff['job_title']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($staff['is_teaching_staff'])): ?>
                                <span class="hero-badge teaching"><i class="fas fa-chalkboard-teacher me-1"></i>Teaching Staff</span>
                            <?php endif; ?>
                            <span class="hero-badge <?php echo (!empty($staff['is_active']) ? 'status-active' : 'status-inactive'); ?>">
                                <i class="fas fa-circle me-1" style="font-size:8px;"></i>
                                <?php echo !empty($staff['is_active']) ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- PERSONAL INFORMATION -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-user me-2 text-primary"></i>Personal Information</h6>
                    </div>
                    <div class="card-body-custom">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">First Name</div>
                                    <div class="data-value"><?php echo showVal($staff['first_name']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Middle Name</div>
                                    <div class="data-value"><?php echo showVal($staff['middle_name']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Last Name</div>
                                    <div class="data-value"><?php echo showVal($staff['last_name']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Preferred Name</div>
                                    <div class="data-value"><?php echo showVal($staff['preferred_name']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Date of Birth</div>
                                    <div class="data-value"><?php echo fmtDate($staff['date_of_birth']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Gender</div>
                                    <div class="data-value"><?php echo showVal($staff['gender']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Nationality</div>
                                    <div class="data-value"><?php echo showVal($staff['nationality']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Religion</div>
                                    <div class="data-value"><?php echo showVal($staff['religion']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Marital Status</div>
                                    <div class="data-value"><?php echo showVal($staff['marital_status']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Home Town</div>
                                    <div class="data-value"><?php echo showVal($staff['home_town']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CONTACT INFORMATION -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-address-book me-2 text-primary"></i>Contact Information</h6>
                    </div>
                    <div class="card-body-custom">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Primary Phone</div>
                                    <div class="data-value">
                                        <?php if (!empty($staff['primary_phone'])): ?>
                                            <a href="tel:<?php echo h($staff['primary_phone']); ?>"><?php echo h($staff['primary_phone']); ?></a>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">—</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Secondary Phone</div>
                                    <div class="data-value">
                                        <?php if (!empty($staff['secondary_phone'])): ?>
                                            <a href="tel:<?php echo h($staff['secondary_phone']); ?>"><?php echo h($staff['secondary_phone']); ?></a>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">—</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Work Phone</div>
                                    <div class="data-value">
                                        <?php if (!empty($staff['work_phone'])): ?>
                                            <a href="tel:<?php echo h($staff['work_phone']); ?>"><?php echo h($staff['work_phone']); ?></a>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">—</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Personal Email</div>
                                    <div class="data-value">
                                        <?php if (!empty($staff['email'])): ?>
                                            <a href="mailto:<?php echo h($staff['email']); ?>"><?php echo h($staff['email']); ?></a>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">—</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Secondary Email</div>
                                    <div class="data-value">
                                        <?php if (!empty($staff['secondary_email'])): ?>
                                            <a href="mailto:<?php echo h($staff['secondary_email']); ?>"><?php echo h($staff['secondary_email']); ?></a>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">—</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Work Email</div>
                                    <div class="data-value">
                                        <?php if (!empty($staff['work_email'])): ?>
                                            <a href="mailto:<?php echo h($staff['work_email']); ?>"><?php echo h($staff['work_email']); ?></a>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">—</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="data-field">
                                    <div class="data-label">Residential Address</div>
                                    <div class="data-value"><?php echo showVal($staff['address']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="data-field">
                                    <div class="data-label">GPS Address</div>
                                    <div class="data-value"><?php echo showVal($staff['gps_address']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="data-field">
                                    <div class="data-label">Postal Address</div>
                                    <div class="data-value"><?php echo showVal($staff['post_address']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="data-field">
                                    <div class="data-label">Town/City</div>
                                    <div class="data-value"><?php echo showVal($staff['town_city']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="data-field">
                                    <div class="data-label">Municipal/District</div>
                                    <div class="data-value"><?php echo showVal($staff['district']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="data-field">
                                    <div class="data-label">Region</div>
                                    <div class="data-value"><?php echo showVal($staff['region']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- EMPLOYMENT INFORMATION -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-briefcase me-2 text-primary"></i>Employment Information</h6>
                    </div>
                    <div class="card-body-custom">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Staff Category</div>
                                    <div class="data-value"><?php echo showVal($staff['staff_category_name']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Staff Type</div>
                                    <div class="data-value"><?php echo showVal($staff['staff_type_name']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Employment Type</div>
                                    <div class="data-value">
                                        <?php echo showVal($staff['employment_type_name']); ?>
                                        <?php if (!empty($staff['is_contract'])): ?>
                                            <span class="badge-soft amber ms-1">Contract</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Staff Status</div>
                                    <div class="data-value">
                                        <?php if (!empty($staff['staff_status_name'])): ?>
                                            <span class="badge-soft blue"><?php echo h($staff['staff_status_name']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">—</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Department</div>
                                    <div class="data-value"><?php echo showVal($staff['department_name']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Job Title</div>
                                    <div class="data-value"><?php echo showVal($staff['job_title']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Reporting Manager</div>
                                    <div class="data-value">
                                        <?php if ($manager): ?>
                                            <a href="/platform/tenant/staff/view.php?id=<?php echo (int)$manager['id']; ?>">
                                                <?php echo h(trim(($manager['first_name'] ?? '') . ' ' . ($manager['last_name'] ?? ''))); ?>
                                            </a>
                                            <small class="text-muted d-block"><?php echo h($manager['staff_number']); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">—</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Work Location</div>
                                    <div class="data-value"><?php echo showVal($staff['work_location']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Max Teaching Hours</div>
                                    <div class="data-value"><?php echo !empty($staff['max_teaching_hours']) ? h($staff['max_teaching_hours']) . ' / week' : '<span class="text-muted fst-italic">—</span>'; ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Hire Date</div>
                                    <div class="data-value"><?php echo fmtDate($staff['hire_date']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Probation Start</div>
                                    <div class="data-value"><?php echo fmtDate($staff['probation_start_date'] ?? null); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Probation End</div>
                                    <div class="data-value"><?php echo fmtDate($staff['probation_end_date'] ?? null); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Confirmation Date</div>
                                    <div class="data-value"><?php echo fmtDate($staff['confirmation_date']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Contract Start</div>
                                    <div class="data-value"><?php echo fmtDate($staff['contract_start_date']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Contract End</div>
                                    <div class="data-value"><?php echo fmtDate($staff['contract_end_date']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="data-field">
                                    <div class="data-label">Platform User</div>
                                    <div class="data-value">
                                        <?php if ($platformUser): ?>
                                            <?php echo h(trim(($platformUser['first_name'] ?? '') . ' ' . ($platformUser['last_name'] ?? ''))); ?>
                                            <small class="text-muted d-block">@<?php echo h($platformUser['username']); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">—</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="data-field">
                                    <div class="data-label">Specialties</div>
                                    <div class="data-value"><?php echo showVal($staff['specialties']); ?></div>
                                </div>
                            </div>
                            <?php if (!empty($staff['notes'])): ?>
                                <div class="col-md-12">
                                    <div class="data-field">
                                        <div class="data-label">Notes</div>
                                        <div class="data-value"><?php echo nl2br(h($staff['notes'])); ?></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- EMERGENCY CONTACTS -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-phone-alt me-2 text-primary"></i>Emergency Contacts</h6>
                        <span class="badge-soft gray"><?php echo count($emergencyContacts); ?> contact<?php echo count($emergencyContacts) == 1 ? '' : 's'; ?></span>
                    </div>
                    <div class="card-body-custom">
                        <?php if (empty($emergencyContacts)): ?>
                            <div class="empty-state">
                                <i class="fas fa-phone-slash"></i>
                                <p>No emergency contacts on file</p>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($emergencyContacts as $c): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="assignment-card" style="<?php echo !empty($c['is_primary']) ? 'border-left-color: #22c55e;' : 'border-left-color: #9ca3af;'; ?>">
                                            <div class="assign-title">
                                                <i class="fas fa-user-circle"></i>
                                                <?php echo h($c['contact_name']); ?>
                                                <?php if (!empty($c['is_primary'])): ?>
                                                    <span class="badge-soft green">Primary</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="assign-meta"><i class="fas fa-heart me-1"></i><?php echo h($c['relationship']); ?></div>
                                            <div class="data-field mb-1">
                                                <div class="data-label">Phone</div>
                                                <div class="data-value">
                                                    <?php if (!empty($c['primary_phone'])): ?>
                                                        <a href="tel:<?php echo h($c['primary_phone']); ?>"><?php echo h($c['primary_phone']); ?></a>
                                                    <?php endif; ?>
                                                    <?php if (!empty($c['secondary_phone'])): ?>
                                                        <br><small><a href="tel:<?php echo h($c['secondary_phone']); ?>"><?php echo h($c['secondary_phone']); ?></a></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if (!empty($c['email'])): ?>
                                                <div class="data-field mb-1">
                                                    <div class="data-label">Email</div>
                                                    <div class="data-value"><a href="mailto:<?php echo h($c['email']); ?>"><?php echo h($c['email']); ?></a></div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($c['address'])): ?>
                                                <div class="data-field">
                                                    <div class="data-label">Address</div>
                                                    <div class="data-value"><?php echo h($c['address']); ?></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- IDENTIFICATION DOCUMENTS -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-id-card me-2 text-primary"></i>Identification Documents</h6>
                        <span class="badge-soft gray"><?php echo count($identifications); ?> document<?php echo count($identifications) == 1 ? '' : 's'; ?></span>
                    </div>
                    <div class="card-body-custom">
                        <?php if (empty($identifications)): ?>
                            <div class="empty-state">
                                <i class="fas fa-id-card"></i>
                                <p>No identification documents on file</p>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($identifications as $ident): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="assignment-card" style="<?php echo !empty($ident['is_primary']) ? 'border-left-color: #22c55e;' : 'border-left-color: #4facfe;'; ?>">
                                            <div class="assign-title">
                                                <?php echo h($ident['identification_type']); ?>
                                                <?php if (!empty($ident['is_primary'])): ?>
                                                    <span class="badge-soft green">Primary</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="data-field mb-1">
                                                <div class="data-label">Number</div>
                                                <div class="data-value"><?php echo h($ident['identification_number']); ?></div>
                                            </div>
                                            <?php if (!empty($ident['issuing_authority'])): ?>
                                                <div class="data-field mb-1">
                                                    <div class="data-label">Issuing Authority</div>
                                                    <div class="data-value"><?php echo h($ident['issuing_authority']); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($ident['expiration_date'])): ?>
                                                <div class="data-field">
                                                    <div class="data-label">Expiration</div>
                                                    <div class="data-value"><?php echo fmtDate($ident['expiration_date']); ?></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- QUALIFICATIONS -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-graduation-cap me-2 text-primary"></i>Qualifications</h6>
                        <span class="badge-soft gray"><?php echo count($qualifications); ?> qualification<?php echo count($qualifications) == 1 ? '' : 's'; ?></span>
                    </div>
                    <div class="card-body-custom">
                        <?php if (empty($qualifications)): ?>
                            <div class="empty-state">
                                <i class="fas fa-graduation-cap"></i>
                                <p>No qualifications on file</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($qualifications as $q): ?>
                                <div class="assignment-card mb-3">
                                    <div class="assign-title">
                                        <i class="fas fa-certificate"></i>
                                        <?php echo h($q['qualification_name']); ?>
                                        <?php if (!empty($q['qualification_level'])): ?>
                                            <span class="badge-soft blue"><?php echo h($q['qualification_level']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="row">
                                        <?php if (!empty($q['major_field'])): ?>
                                            <div class="col-md-4">
                                                <div class="data-field">
                                                    <div class="data-label">Major</div>
                                                    <div class="data-value"><?php echo h($q['major_field']); ?></div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <div class="col-md-4">
                                            <div class="data-field">
                                                <div class="data-label">Institution</div>
                                                <div class="data-value"><?php echo h($q['institution_name']); ?></div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="data-field">
                                                <div class="data-label">Year</div>
                                                <div class="data-value"><?php echo h($q['year_of_graduation']); ?></div>
                                            </div>
                                        </div>
                                        <?php if (!empty($q['country'])): ?>
                                            <div class="col-md-4">
                                                <div class="data-field">
                                                    <div class="data-label">Country</div>
                                                    <div class="data-value"><?php echo h($q['country']); ?></div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- LICENSES -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-certificate me-2 text-primary"></i>Licenses & Certifications</h6>
                        <span class="badge-soft gray"><?php echo count($licenses); ?> license<?php echo count($licenses) == 1 ? '' : 's'; ?></span>
                    </div>
                    <div class="card-body-custom">
                        <?php if (empty($licenses)): ?>
                            <div class="empty-state">
                                <i class="fas fa-certificate"></i>
                                <p>No licenses or certifications on file</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($licenses as $l): ?>
                                <div class="assignment-card mb-3" style="border-left-color: #7c3aed;">
                                    <div class="assign-title">
                                        <i class="fas fa-award"></i>
                                        <?php echo h($l['license_name']); ?>
                                        <?php if (!empty($l['license_type'])): ?>
                                            <span class="badge-soft purple"><?php echo h($l['license_type']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="data-field">
                                                <div class="data-label">License Number</div>
                                                <div class="data-value"><?php echo h($l['license_number']); ?></div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="data-field">
                                                <div class="data-label">Issuing Authority</div>
                                                <div class="data-value"><?php echo h($l['issuing_authority']); ?></div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="data-field">
                                                <div class="data-label">Issue Date</div>
                                                <div class="data-value"><?php echo fmtDate($l['issue_date']); ?></div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="data-field">
                                                <div class="data-label">Expiration Date</div>
                                                <div class="data-value"><?php echo fmtDate($l['expiration_date']); ?></div>
                                            </div>
                                        </div>
                                        <?php if (!empty($l['endorsements'])): ?>
                                            <div class="col-md-8">
                                                <div class="data-field">
                                                    <div class="data-label">Endorsements</div>
                                                    <div class="data-value"><?php echo h($l['endorsements']); ?></div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ACADEMIC ASSIGNMENTS -->
                <?php if (!empty($staff['is_teaching_staff'])): ?>
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-chalkboard-teacher me-2 text-primary"></i>Academic Assignments</h6>
                            <span class="badge-soft gray"><?php echo count($academicAssignments); ?> assignment<?php echo count($academicAssignments) == 1 ? '' : 's'; ?></span>
                        </div>
                        <div class="card-body-custom">
                            <?php if (empty($academicAssignments)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-chalkboard"></i>
                                    <p>No academic assignments on file</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($academicAssignments as $a):
                                    $typeLabel = 'Assignment';
                                    if ($a['assignment_type'] === 'class_teacher') $typeLabel = 'Class Teacher';
                                    elseif ($a['assignment_type'] === 'subject_teacher') $typeLabel = 'Subject Teacher';
                                    elseif ($a['assignment_type'] === 'special_education') $typeLabel = 'Special Education';

                                    $classNames = parseCsvNames($a['class_ids'] ?? '', $classesMap);
                                    $subjectNames = parseCsvNames($a['subject_ids'] ?? '', $subjectsMap);
                                    $streamNames = parseCsvNames($a['stream_ids'] ?? '', $streamsMap);
                                ?>
                                    <div class="assignment-card mb-3">
                                        <div class="assign-title">
                                            <i class="fas fa-book"></i>
                                            <?php echo h($typeLabel); ?>
                                            <?php if (!empty($a['is_primary'])): ?>
                                                <span class="badge-soft green">Primary</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="assign-meta">
                                            <i class="fas fa-calendar me-1"></i>
                                            <?php echo h($a['year_name'] ?? '—'); ?>
                                            <?php if (!empty($a['term_name'])): ?>
                                                &nbsp;·&nbsp;<i class="fas fa-calendar-alt me-1"></i><?php echo h($a['term_name']); ?>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($a['assignment_type'] === 'special_education' && (!empty($a['discipline_type']) || !empty($a['discipline_name']))): ?>
                                            <div class="mb-2">
                                                <div class="data-label" style="font-size:10px;font-weight:600;text-transform:uppercase;color:#6c757d;margin-bottom:4px;">Discipline</div>
                                                <div>
                                                    <?php if (!empty($a['discipline_type'])): ?>
                                                        <span class="tag discipline"><?php echo h($a['discipline_type']); ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($a['discipline_name'])): ?>
                                                        <span class="tag discipline"><?php echo h($a['discipline_name']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($subjectNames)): ?>
                                            <div class="mb-2">
                                                <div class="data-label" style="font-size:10px;font-weight:600;text-transform:uppercase;color:#6c757d;margin-bottom:4px;">Subjects</div>
                                                <div class="assign-tags">
                                                    <?php foreach ($subjectNames as $sn): ?>
                                                        <span class="tag subject"><?php echo h($sn); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($classNames)): ?>
                                            <div class="mb-2">
                                                <div class="data-label" style="font-size:10px;font-weight:600;text-transform:uppercase;color:#6c757d;margin-bottom:4px;">Classes</div>
                                                <div class="assign-tags">
                                                    <?php foreach ($classNames as $cn): ?>
                                                        <span class="tag"><?php echo h($cn); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($streamNames)): ?>
                                            <div>
                                                <div class="data-label" style="font-size:10px;font-weight:600;text-transform:uppercase;color:#6c757d;margin-bottom:4px;">Streams</div>
                                                <div class="assign-tags">
                                                    <?php foreach ($streamNames as $stn): ?>
                                                        <span class="tag stream"><?php echo h($stn); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- BIOMETRIC DATA -->
                <?php if (!empty($biometrics)): ?>
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-fingerprint me-2 text-primary"></i>Biometric Data</h6>
                            <span class="badge-soft gray"><?php echo count($biometrics); ?> record<?php echo count($biometrics) == 1 ? '' : 's'; ?></span>
                        </div>
                        <div class="card-body-custom">
                            <?php foreach ($biometrics as $b):
                                $typeLabel = ucfirst(str_replace('_', ' ', $b['biometric_type'] ?? ''));
                            ?>
                                <div class="assignment-card mb-3" style="border-left-color: #0891b2;">
                                    <div class="assign-title">
                                        <i class="fas fa-fingerprint"></i>
                                        <?php echo h($typeLabel); ?>
                                        <?php if (!empty($b['is_primary'])): ?>
                                            <span class="badge-soft green">Primary</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="row">
                                        <?php if (!empty($b['finger_position'])): ?>
                                            <div class="col-md-4">
                                                <div class="data-field">
                                                    <div class="data-label">Finger Position</div>
                                                    <div class="data-value"><?php echo h(ucfirst(str_replace('_', ' ', $b['finger_position']))); ?></div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <div class="col-md-4">
                                            <div class="data-field">
                                                <div class="data-label">Template Format</div>
                                                <div class="data-value"><?php echo showVal($b['template_format']); ?></div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="data-field">
                                                <div class="data-label">Quality Score</div>
                                                <div class="data-value"><?php echo !empty($b['quality_score']) ? h($b['quality_score']) . ' / 100' : '<span class="text-muted fst-italic">—</span>'; ?></div>
                                            </div>
                                        </div>
                                        <?php if (!empty($b['notes'])): ?>
                                            <div class="col-md-12">
                                                <div class="data-field">
                                                    <div class="data-label">Notes</div>
                                                    <div class="data-value"><?php echo h($b['notes']); ?></div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- MEDICAL INFORMATION -->
                <?php
                $hasMedicalData = !empty($biographic['blood_type'])
                    || ($hasGenotypeColumn && !empty($biographic['genotype']))
                    || !empty($biographic['distinguishing_marks'])
                    || !empty($biographic['allergies'])
                    || !empty($biographic['medical_conditions'])
                    || !empty($biographic['emergency_medical_notes']);
                ?>
                <?php if ($hasMedicalData): ?>
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-heartbeat me-2 text-primary"></i>Medical Information</h6>
                        </div>
                        <div class="card-body-custom">
                            <div class="row">
                                <?php if (!empty($biographic['blood_type'])): ?>
                                    <div class="col-md-3">
                                        <div class="data-field">
                                            <div class="data-label">Blood Group</div>
                                            <div class="data-value"><span class="badge-soft red"><?php echo h($biographic['blood_type']); ?></span></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($hasGenotypeColumn && !empty($biographic['genotype'])): ?>
                                    <div class="col-md-3">
                                        <div class="data-field">
                                            <div class="data-label">Genotype</div>
                                            <div class="data-value"><span class="badge-soft purple"><?php echo h($biographic['genotype']); ?></span></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($biographic['distinguishing_marks'])): ?>
                                    <div class="col-md-6">
                                        <div class="data-field">
                                            <div class="data-label">Distinguishing Marks</div>
                                            <div class="data-value"><?php echo h($biographic['distinguishing_marks']); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($biographic['allergies'])): ?>
                                    <div class="col-md-6">
                                        <div class="data-field">
                                            <div class="data-label">Allergies</div>
                                            <div class="data-value"><?php echo nl2br(h($biographic['allergies'])); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($biographic['medical_conditions'])): ?>
                                    <div class="col-md-6">
                                        <div class="data-field">
                                            <div class="data-label">Medical Conditions</div>
                                            <div class="data-value"><?php echo nl2br(h($biographic['medical_conditions'])); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($biographic['emergency_medical_notes'])): ?>
                                    <div class="col-md-12">
                                        <div class="data-field">
                                            <div class="data-label">Emergency Medical Notes</div>
                                            <div class="data-value"><?php echo nl2br(h($biographic['emergency_medical_notes'])); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- DIGITAL SIGNATURE -->
                <?php if ($signature && !empty($signature['signature_data'])): ?>
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-signature me-2 text-primary"></i>Digital Signature</h6>
                        </div>
                        <div class="card-body-custom">
                            <div class="signature-preview">
                                <?php
                                $sigData = (string)$signature['signature_data'];
                                $sigSrc = '';
                                if (preg_match('#^https?://#i', $sigData)) {
                                    $sigSrc = $sigData;
                                } elseif (strpos($sigData, '/') === 0) {
                                    $sigSrc = $sigData;
                                } elseif (strpos($sigData, 'data:image') === 0) {
                                    $sigSrc = $sigData;
                                } else {
                                    $sigSrc = 'data:image/png;base64,' . $sigData;
                                }
                                ?>
                                <img src="<?php echo h($sigSrc); ?>" alt="Signature">
                            </div>
                            <?php if (!empty($signature['signed_at'])): ?>
                                <div class="data-field" style="margin-top:12px;text-align:center;">
                                    <div class="data-label">Signed At</div>
                                    <div class="data-value"><?php echo fmtDateTime($signature['signed_at']); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-signature me-2 text-primary"></i>Digital Signature</h6>
                        </div>
                        <div class="card-body-custom">
                            <div class="empty-state">
                                <i class="fas fa-signature"></i>
                                <p>No digital signature on file</p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
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
            if (window.innerWidth <= 768 && sidebar.classList.contains('open') && !sidebar.contains(event.target) && !toggle.contains(event.target)) {
                sidebar.classList.remove('open');
            }
        });

        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '/platform/tenant/logout.php';
            }
        }
        // Password eye toggle — same pattern as Email tab (item 13).
        document.querySelectorAll('.js-toggle-pw').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = this.getAttribute('data-target');
                var el = document.getElementById(id);
                if (!el) return;
                var icon = this.querySelector('i');
                if (el.type === 'password') {
                    el.type = 'text';
                    if (icon) {
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    }
                } else {
                    el.type = 'password';
                    if (icon) {
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                }
            });
        });
    </script>
</body>

</html>