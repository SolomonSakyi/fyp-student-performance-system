<?php

/**
 * Student Profile - Read-only view of a single student
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Students
 * @version 1.0
 * @filepath public/platform/tenant/students/view.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Students view file of the students-surface sweep. Four changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - A v1.0 [SWEEP] entry was added above this docblock.
 *     - The inline <nav class="sidebar" id="sidebar"> block is
 *       removed and replaced by an include of
 *       app/views/partials/sidebar.php. The partial carries the
 *       top-level items and — when $currentPage is 'students' —
 *       the student sub-menu.
 *     - The CSS rules that style .nav-sub,
 *       .nav-sub .nav-link, .nav-sub .nav-link.active, and
 *       .nav-subgroup-label are added to this file's <style>
 *       block so the partial's sub-menu renders with correct
 *       indentation and active-state styling.
 *   Every other line of the file is byte-identical to the
 *   previous version (1.0). The @package tag remains 'EduTrack'.
 *
 * Reads ONLY from tables that exist post-migration:
 *   students, student_emergency_contacts, student_documents,
 *   classes, streams, academic_years, academic_terms, campuses,
 *   admission_types, persons
 *
 * Sections rendered (mirror of create.php):
 *   1. Student Information
 *   2. Academic Placement
 *   3. Parent / Guardian
 *   4. Emergency Contacts
 *   5. Medical / Welfare
 *   6. School Services
 *   7. Documents
 */

// ============================================
// SESSION & AUTH
// ============================================
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/tenant/login.php');
    exit;
}

$tenantId     = $_SESSION['tenant_id'] ?? 0;
$schoolId     = $_SESSION['school_id'] ?? 0;
$campusId     = $_SESSION['campus_id'] ?? 0;
$userId       = $_SESSION['user_id'] ?? 0;
$currentUser  = $_SESSION['user_name'] ?? 'Admin';
$userAvatar   = substr($currentUser, 0, 1);
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;

if (!$tenantId) {
    $_SESSION['errors'] = ['No tenant context found.'];
    header('Location: /platform/tenants/select.php');
    exit;
}

// ============================================
// STUDENT ID
// ============================================
$studentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($studentId <= 0) {
    $_SESSION['errors'] = ['Invalid student ID.'];
    header('Location: /platform/tenant/students/index.php');
    exit;
}

$pageTitle   = 'Student Profile - Student 360 Platform';
$currentPage = 'students';

// ============================================
// DATABASE
// ============================================
$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/config/config.php';
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// ============================================
// LOAD STUDENT
// ============================================
$student = $db->fetchOne(
    "SELECT s.*,
            c.class_name, c.class_code,
            st.stream_name,
            ay.year_name  AS academic_year_name,
            at.term_name  AS academic_term_name,
            cp.campus_name
     FROM students s
     LEFT JOIN classes        c  ON s.class_id          = c.id
     LEFT JOIN streams        st ON s.stream_id         = st.id
     LEFT JOIN academic_years ay ON s.academic_year_id  = ay.id
     LEFT JOIN academic_terms at ON s.academic_term_id  = at.id
     LEFT JOIN campuses       cp ON s.campus_id         = cp.id
     WHERE s.id = ? AND s.tenant_id = ? AND s.deleted_at IS NULL",
    [$studentId, $tenantId]
);

if (!$student) {
    $_SESSION['errors'] = ['Student record not found.'];
    header('Location: /platform/tenant/students/index.php');
    exit;
}

// ============================================
// LINKED GUARDIAN PERSON (if any)
// ============================================
$linkedPerson = null;
if (!empty($student['guardian_person_id'])) {
    $linkedPerson = $db->fetchOne(
        "SELECT id, first_name, middle_name, last_name, preferred_name,
                email, primary_phone, profile_photo_url
         FROM persons
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [(int)$student['guardian_person_id'], $tenantId]
    );
}

// ============================================
// EMERGENCY CONTACTS
// ============================================
$emergencyContacts = $db->fetchAll(
    "SELECT * FROM student_emergency_contacts
     WHERE student_id = ? AND deleted_at IS NULL
     ORDER BY is_primary DESC, sort_order ASC, id ASC",
    [$studentId]
);

// ============================================
// DOCUMENTS
// ============================================
$documents = $db->fetchAll(
    "SELECT * FROM student_documents
     WHERE student_id = ? AND deleted_at IS NULL
     ORDER BY is_primary DESC, id ASC",
    [$studentId]
);

// ============================================
// HELPERS
// ============================================
function initialsFor(?string $first, ?string $last): string
{
    $i = strtoupper(mb_substr((string)$first, 0, 1) . mb_substr((string)$last, 0, 1));
    return $i !== '' ? $i : 'ST';
}
function h($v): string
{
    return htmlspecialchars((string)($v ?? ''));
}
function dash($v): string
{
    return ($v === null || $v === '' || $v === false) ? '<span class="text-muted">—</span>' : h($v);
}
function yesNo($v): string
{
    return ((int)$v) === 1
        ? '<span class="badge-yes"><i class="fas fa-check"></i> Yes</span>'
        : '<span class="badge-no"><i class="fas fa-times"></i> No</span>';
}
function humanBytes($bytes): string
{
    $bytes = (int)$bytes;
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 2) . ' MB';
}

$fullName    = trim(preg_replace(
    '/\s+/',
    ' ',
    ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? '') . ' ' . ($student['last_name'] ?? '')
));
$displayName = !empty($student['preferred_name']) ? $student['preferred_name'] : $fullName;
if ($displayName === '') $displayName = 'Student #' . $studentId;
$initials    = initialsFor($student['first_name'] ?? '', $student['last_name'] ?? '');
$isActive    = ((int)($student['is_active'] ?? 0)) === 1;
$statusClass = $isActive ? 'active' : 'inactive';
$statusLabel = $isActive ? 'Active' : 'Inactive';

$genderVal   = $student['gender'] ?? '';
$genderClass = 'other';
if ($genderVal === 'Male') $genderClass = 'male';
elseif ($genderVal === 'Female') $genderClass = 'female';

// ============================================
// TENANT / SCHOOL NAMES
// ============================================
$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

$schoolName = '';
if ($schoolId > 0) {
    try {
        $s = $db->fetchOne("SELECT school_name FROM schools WHERE id = ? AND deleted_at IS NULL", [$schoolId]);
        if ($s) $schoolName = $s['school_name'] ?? '';
    } catch (Exception $e) {
        $schoolName = '';
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

        /* Sidebar */
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

        /* [SWEEP] Sub-menu styles for the partial's student sub-menu. */
        .sidebar .nav-sub {
            padding-left: 24px;
        }

        .sidebar .nav-sub .nav-link {
            font-size: 13px;
            padding: 8px 14px;
            color: rgba(255, 255, 255, 0.55);
        }

        .sidebar .nav-sub .nav-link.active {
            background: rgba(79, 172, 254, 0.18);
            color: #fff;
            box-shadow: none;
        }

        .sidebar .nav-subgroup-label {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: rgba(255, 255, 255, 0.35);
            padding: 8px 14px 2px;
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

        /* Main */
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

        /* Banner */
        .tenant-banner {
            background: #fff;
            border-radius: 14px;
            padding: 16px 24px;
            margin-bottom: 20px;
            border: 2px solid #4facfe;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .tenant-banner .tenant-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .tenant-banner .tenant-info i {
            font-size: 24px;
            color: #4facfe;
        }

        .tenant-banner .tenant-info .tenant-name {
            font-weight: 600;
            font-size: 16px;
            color: #1a1a2e;
        }

        .tenant-banner .tenant-badge {
            background: #e3f0ff;
            color: #0d6efd;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        /* Profile hero */
        .profile-hero {
            background: #fff;
            border-radius: 16px;
            padding: 24px 28px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            display: flex;
            gap: 24px;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            max-width: 1100px;
            margin-left: auto;
            margin-right: auto;
        }

        .profile-avatar-wrap {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 40px;
            color: #fff;
            flex-shrink: 0;
            overflow: hidden;
            border: 4px solid #e3f0ff;
        }

        .profile-avatar-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .profile-meta {
            flex: 1;
            min-width: 240px;
        }

        .profile-name {
            font-size: 26px;
            font-weight: 800;
            color: #1a1a2e;
            margin: 0 0 4px;
            letter-spacing: -0.5px;
        }

        .profile-sub {
            color: #6c757d;
            font-size: 13px;
            margin-bottom: 6px;
        }

        .profile-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }

        .badge-pill {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .badge-pill.active {
            background: #d4edda;
            color: #155724;
        }

        .badge-pill.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-pill.male {
            background: #cce5ff;
            color: #004085;
        }

        .badge-pill.female {
            background: #fce4ec;
            color: #880e4f;
        }

        .badge-pill.other {
            background: #e9ecef;
            color: #495057;
        }

        .badge-pill.info {
            background: #e3f0ff;
            color: #0d6efd;
        }

        .badge-pill.warn {
            background: #fff3cd;
            color: #856404;
        }

        /* Cards */
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
            display: flex;
            align-items: center;
        }

        .card-custom .card-body-custom {
            padding: 20px 24px;
        }

        .section-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            margin-right: 10px;
            flex-shrink: 0;
        }

        /* Data table inside cards */
        .data-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px 28px;
        }

        .data-grid.three {
            grid-template-columns: repeat(3, 1fr);
        }

        .data-item {
            min-width: 0;
        }

        .data-item .data-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #adb5bd;
            font-weight: 600;
            margin-bottom: 3px;
        }

        .data-item .data-value {
            font-size: 14px;
            color: #1a1a2e;
            word-break: break-word;
            font-weight: 500;
        }

        .data-item .data-value.long {
            white-space: pre-wrap;
        }

        /* Emergency contacts / documents tables */
        .mini-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .mini-table thead th {
            background: #f8f9fa;
            padding: 10px 14px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            color: #6c757d;
            text-align: left;
            border-bottom: 2px solid #e9ecef;
            white-space: nowrap;
        }

        .mini-table tbody td {
            padding: 10px 14px;
            border-bottom: 1px solid #f0f2f5;
            vertical-align: top;
        }

        .mini-table tbody tr:last-child td {
            border-bottom: none;
        }

        .mini-table tbody tr:hover {
            background: #fafbfc;
        }

        .badge-yes {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #d4edda;
            color: #155724;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-no {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #f1f3f5;
            color: #6c757d;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-doc-type {
            display: inline-block;
            background: #e3f0ff;
            color: #0d6efd;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        /* Linked person card */
        .linked-person {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #f0f7ff;
            border: 2px solid #4facfe;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 16px;
        }

        .linked-person .lp-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #fff;
            font-size: 16px;
            flex-shrink: 0;
            overflow: hidden;
        }

        .linked-person .lp-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .linked-person .lp-name {
            font-weight: 600;
            font-size: 14px;
        }

        .linked-person .lp-meta {
            font-size: 11px;
            color: #6c757d;
        }

        .service-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #d4edda;
            color: #155724;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin: 0 6px 6px 0;
        }

        .service-chip.off {
            background: #f1f3f5;
            color: #adb5bd;
            text-decoration: line-through;
        }

        .empty-inline {
            padding: 20px;
            text-align: center;
            color: #adb5bd;
            font-size: 13px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 1px dashed #dee2e6;
        }

        /* Responsive */
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

            .sidebar .nav-sub {
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

            .data-grid,
            .data-grid.three {
                grid-template-columns: repeat(2, 1fr);
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

            .sidebar .nav-sub {
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
                font-size: 22px;
            }

            .top-bar .page-title p {
                font-size: 12px;
            }

            .top-bar .header-actions .btn {
                font-size: 12px;
                padding: 6px 12px;
            }

            .tenant-banner {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }

            .profile-hero {
                flex-direction: column;
                text-align: center;
            }

            .profile-badges {
                justify-content: center;
            }

            .data-grid,
            .data-grid.three {
                grid-template-columns: 1fr;
            }

            .mini-table {
                font-size: 12px;
            }

            .mini-table thead th {
                padding: 8px 10px;
                font-size: 9px;
            }

            .mini-table tbody td {
                padding: 8px 10px;
            }
        }

        /* Print */
        @media print {

            .sidebar,
            .sidebar-toggle,
            .top-bar .header-actions,
            .tenant-banner,
            .no-print {
                display: none !important;
            }

            .main-content {
                margin: 0;
                padding: 0;
                width: 100%;
            }

            body {
                background: #fff;
                font-size: 12px;
            }

            .card-custom,
            .profile-hero {
                box-shadow: none;
                border: 1px solid #dee2e6;
                max-width: 100%;
                page-break-inside: avoid;
            }

            .card-custom {
                margin-bottom: 12px;
            }

            .profile-hero {
                margin-bottom: 12px;
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

            <!-- Sidebar (includes app/views/partials/sidebar.php) -->
            <?php include $projectRoot . '/app/views/partials/sidebar.php'; ?>

            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-user-graduate me-2"></i>Student Profile</h1>
                        <p>Full profile for <strong><?php echo h($displayName); ?></strong></p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/students/edit.php?id=<?php echo (int)$studentId; ?>" class="btn btn-primary">
                            <i class="fas fa-edit me-2"></i> Edit Student
                        </a>
                        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                            <i class="fas fa-print me-2"></i> Print
                        </button>
                        <a href="/platform/tenant/students/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Students
                        </a>
                    </div>
                </div>

                <div class="tenant-banner no-print">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo h($tenantName); ?></div>
                            <?php if ($schoolName !== ''): ?>
                                <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                    <i class="fas fa-school me-1"></i> <?php echo h($schoolName); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="tenant-badge"><i class="fas fa-id-badge me-1"></i> <?php echo h($student['student_number'] ?? 'N/A'); ?></div>
                </div>

                <!-- ============================================
                     PROFILE HERO
                ============================================ -->
                <div class="profile-hero">
                    <div class="profile-avatar-wrap">
                        <?php if (!empty($student['profile_photo_url'])): ?>
                            <img src="<?php echo h($student['profile_photo_url']); ?>" alt="<?php echo h($displayName); ?>"
                                onerror="this.style.display='none';this.parentNode.textContent='<?php echo h($initials); ?>';">
                        <?php else: ?>
                            <?php echo h($initials); ?>
                        <?php endif; ?>
                    </div>
                    <div class="profile-meta">
                        <h2 class="profile-name"><?php echo h($displayName); ?></h2>
                        <div class="profile-sub">
                            <?php if ($fullName !== $displayName): ?>
                                <i class="fas fa-user me-1"></i> <?php echo h($fullName); ?> &nbsp;·&nbsp;
                            <?php endif; ?>
                            <i class="fas fa-id-badge me-1"></i> <?php echo h($student['student_number'] ?? 'N/A'); ?>
                        </div>
                        <div class="profile-badges">
                            <span class="badge-pill <?php echo $statusClass; ?>">
                                <i class="fas fa-circle" style="font-size:7px;"></i>
                                <?php echo $statusLabel; ?>
                            </span>
                            <?php if ($genderVal !== ''): ?>
                                <span class="badge-pill <?php echo $genderClass; ?>">
                                    <i class="fas fa-<?php echo $genderVal === 'Male' ? 'male' : ($genderVal === 'Female' ? 'female' : 'genderless'); ?>"></i>
                                    <?php echo h($genderVal); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($student['class_name'])): ?>
                                <span class="badge-pill info">
                                    <i class="fas fa-book"></i> <?php echo h($student['class_name']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($student['enrollment_status'])): ?>
                                <span class="badge-pill warn">
                                    <i class="fas fa-info-circle"></i> <?php echo h($student['enrollment_status']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ============================================
                     SECTION 1 — STUDENT INFORMATION
                ============================================ -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><span class="section-number">1</span>Student Information</h6>
                        <small class="text-muted">Personal and contact details</small>
                    </div>
                    <div class="card-body-custom">
                        <div class="data-grid three">
                            <div class="data-item">
                                <div class="data-label">First Name</div>
                                <div class="data-value"><?php echo dash($student['first_name'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Middle Name</div>
                                <div class="data-value"><?php echo dash($student['middle_name'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Last Name</div>
                                <div class="data-value"><?php echo dash($student['last_name'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Preferred Name</div>
                                <div class="data-value"><?php echo dash($student['preferred_name'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Gender</div>
                                <div class="data-value"><?php echo dash($student['gender'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Date of Birth</div>
                                <div class="data-value">
                                    <?php
                                    if (!empty($student['date_of_birth'])) {
                                        echo h(date('M d, Y', strtotime($student['date_of_birth'])));
                                    } else {
                                        echo '<span class="text-muted">—</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Place of Birth</div>
                                <div class="data-value"><?php echo dash($student['place_of_birth'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Nationality</div>
                                <div class="data-value"><?php echo dash($student['nationality'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Religion</div>
                                <div class="data-value"><?php echo dash($student['religion'] ?? ''); ?></div>
                            </div>
                        </div>

                        <hr style="margin: 20px 0 18px; border: none; border-top: 1px dashed #e9ecef;">

                        <div class="data-grid three">
                            <div class="data-item">
                                <div class="data-label">Primary Phone</div>
                                <div class="data-value"><?php echo dash($student['primary_phone'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Secondary Phone</div>
                                <div class="data-value"><?php echo dash($student['secondary_phone'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Email</div>
                                <div class="data-value">
                                    <?php if (!empty($student['email'])): ?>
                                        <a href="mailto:<?php echo h($student['email']); ?>"><?php echo h($student['email']); ?></a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="data-item" style="grid-column: 1 / -1;">
                                <div class="data-label">Residential Address</div>
                                <div class="data-value long"><?php echo dash($student['address'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Town / City</div>
                                <div class="data-value"><?php echo dash($student['town_city'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">District</div>
                                <div class="data-value"><?php echo dash($student['district'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Region</div>
                                <div class="data-value"><?php echo dash($student['region'] ?? ''); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================
                     SECTION 2 — ACADEMIC PLACEMENT
                ============================================ -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><span class="section-number">2</span>Academic Placement</h6>
                        <small class="text-muted">Year, class and admission details</small>
                    </div>
                    <div class="card-body-custom">
                        <div class="data-grid three">
                            <div class="data-item">
                                <div class="data-label">Academic Year</div>
                                <div class="data-value"><?php echo dash($student['academic_year_name'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Academic Term</div>
                                <div class="data-value"><?php echo dash($student['academic_term_name'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Campus</div>
                                <div class="data-value"><?php echo dash($student['campus_name'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Academic Level / Class</div>
                                <div class="data-value">
                                    <?php if (!empty($student['class_name'])): ?>
                                        <?php echo h($student['class_name']); ?>
                                        <?php if (!empty($student['class_code'])): ?>
                                            <span class="text-muted">(<?php echo h($student['class_code']); ?>)</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Stream</div>
                                <div class="data-value"><?php echo dash($student['stream_name'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Admission Date</div>
                                <div class="data-value">
                                    <?php
                                    if (!empty($student['admission_date'])) {
                                        echo h(date('M d, Y', strtotime($student['admission_date'])));
                                    } else {
                                        echo '<span class="text-muted">—</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Admission Type</div>
                                <div class="data-value"><?php echo dash($student['admission_type'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Enrollment Date</div>
                                <div class="data-value">
                                    <?php
                                    if (!empty($student['enrollment_date'])) {
                                        echo h(date('M d, Y', strtotime($student['enrollment_date'])));
                                    } else {
                                        echo '<span class="text-muted">—</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Enrollment Status</div>
                                <div class="data-value"><?php echo dash($student['enrollment_status'] ?? ''); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================
                     SECTION 3 — PARENT / GUARDIAN
                ============================================ -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><span class="section-number">3</span>Parent / Guardian</h6>
                        <small class="text-muted">Primary guardian and portal preferences</small>
                    </div>
                    <div class="card-body-custom">

                        <?php if ($linkedPerson): ?>
                            <?php
                            $lpFull = trim(preg_replace(
                                '/\s+/',
                                ' ',
                                ($linkedPerson['first_name'] ?? '') . ' ' . ($linkedPerson['middle_name'] ?? '') . ' ' . ($linkedPerson['last_name'] ?? '')
                            ));
                            $lpInit = initialsFor($linkedPerson['first_name'] ?? '', $linkedPerson['last_name'] ?? '');
                            ?>
                            <div class="linked-person">
                                <div class="lp-avatar">
                                    <?php if (!empty($linkedPerson['profile_photo_url'])): ?>
                                        <img src="<?php echo h($linkedPerson['profile_photo_url']); ?>" alt=""
                                            onerror="this.style.display='none';this.parentNode.textContent='<?php echo h($lpInit); ?>';">
                                    <?php else: ?>
                                        <?php echo h($lpInit); ?>
                                    <?php endif; ?>
                                </div>
                                <div style="flex:1;min-width:0;">
                                    <div class="lp-name"><?php echo h($lpFull !== '' ? $lpFull : 'Person #' . $linkedPerson['id']); ?></div>
                                    <div class="lp-meta">
                                        <?php if (!empty($linkedPerson['email'])): ?>
                                            <i class="fas fa-envelope me-1"></i><?php echo h($linkedPerson['email']); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($linkedPerson['primary_phone'])): ?>
                                            &nbsp;·&nbsp; <i class="fas fa-phone me-1"></i><?php echo h($linkedPerson['primary_phone']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="badge-pill info"><i class="fas fa-link"></i> Linked</span>
                            </div>
                        <?php endif; ?>

                        <div class="data-grid three">
                            <div class="data-item">
                                <div class="data-label">Guardian Name</div>
                                <div class="data-value"><?php echo dash($student['guardian_name'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Relationship</div>
                                <div class="data-value"><?php echo dash($student['guardian_relationship'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Primary Guardian</div>
                                <div class="data-value"><?php echo yesNo($student['guardian_is_primary'] ?? 0); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Guardian Phone</div>
                                <div class="data-value"><?php echo dash($student['guardian_phone'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Alternate Phone</div>
                                <div class="data-value"><?php echo dash($student['guardian_alt_phone'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Guardian Email</div>
                                <div class="data-value">
                                    <?php if (!empty($student['guardian_email'])): ?>
                                        <a href="mailto:<?php echo h($student['guardian_email']); ?>"><?php echo h($student['guardian_email']); ?></a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="data-item" style="grid-column: 1 / -1;">
                                <div class="data-label">Guardian Address</div>
                                <div class="data-value long"><?php echo dash($student['guardian_address'] ?? ''); ?></div>
                            </div>
                        </div>

                        <hr style="margin: 20px 0 18px; border: none; border-top: 1px dashed #e9ecef;">

                        <div class="data-grid three">
                            <div class="data-item">
                                <div class="data-label">Portal Access</div>
                                <div class="data-value"><?php echo yesNo($student['guardian_portal_access'] ?? 0); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">SMS Notifications</div>
                                <div class="data-value"><?php echo yesNo($student['guardian_sms'] ?? 0); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Email Notifications</div>
                                <div class="data-value"><?php echo yesNo($student['guardian_email_notify'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================
                     SECTION 4 — EMERGENCY CONTACTS
                ============================================ -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><span class="section-number">4</span>Emergency Contacts</h6>
                        <small class="text-muted"><?php echo count($emergencyContacts); ?> contact(s) on file</small>
                    </div>
                    <div class="card-body-custom" style="padding: 0;">
                        <?php if (empty($emergencyContacts)): ?>
                            <div style="padding: 24px;">
                                <div class="empty-inline">
                                    <i class="fas fa-phone-slash me-1"></i> No emergency contacts recorded.
                                </div>
                            </div>
                        <?php else: ?>
                            <table class="mini-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Relationship</th>
                                        <th>Primary Phone</th>
                                        <th>Alternate</th>
                                        <th>Email</th>
                                        <th>Address</th>
                                        <th>Primary</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($emergencyContacts as $ec): ?>
                                        <tr>
                                            <td><strong><?php echo h($ec['contact_name'] ?? ''); ?></strong></td>
                                            <td><?php echo dash($ec['relationship'] ?? ''); ?></td>
                                            <td><?php echo dash($ec['primary_phone'] ?? ''); ?></td>
                                            <td><?php echo dash($ec['secondary_phone'] ?? ''); ?></td>
                                            <td>
                                                <?php if (!empty($ec['email'])): ?>
                                                    <a href="mailto:<?php echo h($ec['email']); ?>"><?php echo h($ec['email']); ?></a>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo dash($ec['address'] ?? ''); ?></td>
                                            <td><?php echo yesNo($ec['is_primary'] ?? 0); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ============================================
                     SECTION 5 — MEDICAL / WELFARE
                ============================================ -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><span class="section-number">5</span>Medical / Welfare</h6>
                        <small class="text-muted">Health and wellbeing details</small>
                    </div>
                    <div class="card-body-custom">
                        <div class="data-grid three">
                            <div class="data-item">
                                <div class="data-label">Blood Group</div>
                                <div class="data-value"><?php echo dash($student['blood_group'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Genotype</div>
                                <div class="data-value"><?php echo dash($student['genotype'] ?? ''); ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Allergies</div>
                                <div class="data-value"><?php echo dash($student['allergies'] ?? ''); ?></div>
                            </div>
                            <div class="data-item" style="grid-column: 1 / -1;">
                                <div class="data-label">Medical Conditions</div>
                                <div class="data-value long"><?php echo dash($student['medical_conditions'] ?? ''); ?></div>
                            </div>
                            <div class="data-item" style="grid-column: 1 / -1;">
                                <div class="data-label">Special Needs</div>
                                <div class="data-value long"><?php echo dash($student['special_needs'] ?? ''); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================
                     SECTION 6 — SCHOOL SERVICES
                ============================================ -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><span class="section-number">6</span>School Services</h6>
                        <small class="text-muted">Services the student is enrolled in</small>
                    </div>
                    <div class="card-body-custom">
                        <div class="service-chip <?php echo ((int)($student['sports'] ?? 0)) === 1 ? '' : 'off'; ?>">
                            <i class="fas fa-futbol"></i> Sports
                        </div>
                        <div class="service-chip <?php echo ((int)($student['canteen'] ?? 0)) === 1 ? '' : 'off'; ?>">
                            <i class="fas fa-utensils"></i> Canteen
                        </div>
                        <div class="service-chip <?php echo ((int)($student['medical_service'] ?? 0)) === 1 ? '' : 'off'; ?>">
                            <i class="fas fa-briefcase-medical"></i> Medical
                        </div>
                        <div class="service-chip <?php echo ((int)($student['transport'] ?? 0)) === 1 ? '' : 'off'; ?>">
                            <i class="fas fa-bus"></i> Transport
                        </div>
                    </div>
                </div>

                <!-- ============================================
                     SECTION 7 — DOCUMENTS
                ============================================ -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><span class="section-number">7</span>Documents</h6>
                        <small class="text-muted"><?php echo count($documents); ?> document(s) on file</small>
                    </div>
                    <div class="card-body-custom" style="padding: 0;">
                        <?php if (empty($documents)): ?>
                            <div style="padding: 24px;">
                                <div class="empty-inline">
                                    <i class="fas fa-folder-open me-1"></i> No documents uploaded.
                                </div>
                            </div>
                        <?php else: ?>
                            <table class="mini-table">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Title</th>
                                        <th>File</th>
                                        <th>Size</th>
                                        <th>Uploaded</th>
                                        <th>Primary</th>
                                        <th style="text-align:right;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($documents as $doc): ?>
                                        <tr>
                                            <td>
                                                <?php if (!empty($doc['document_type'])): ?>
                                                    <span class="badge-doc-type"><?php echo h($doc['document_type']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo dash($doc['title'] ?? ''); ?></td>
                                            <td>
                                                <i class="fas fa-file-<?php echo (strpos((string)($doc['mime_type'] ?? ''), 'pdf') !== false) ? 'pdf' : 'image'; ?> me-1 text-muted"></i>
                                                <?php echo h($doc['original_name'] ?? basename((string)($doc['file_path'] ?? ''))); ?>
                                            </td>
                                            <td><?php echo h(humanBytes($doc['file_size'] ?? 0)); ?></td>
                                            <td>
                                                <?php
                                                if (!empty($doc['created_at'])) {
                                                    echo h(date('M d, Y', strtotime($doc['created_at'])));
                                                } else {
                                                    echo '<span class="text-muted">—</span>';
                                                }
                                                ?>
                                            </td>
                                            <td><?php echo yesNo($doc['is_primary'] ?? 0); ?></td>
                                            <td style="text-align:right;">
                                                <?php if (!empty($doc['file_path'])): ?>
                                                    <a href="<?php echo h($doc['file_path']); ?>" target="_blank"
                                                        class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-download"></i> Open
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ============================================
                     FOOTER META
                ============================================ -->
                <div class="card-custom no-print">
                    <div class="card-body-custom" style="text-align:center;color:#6c757d;font-size:12px;">
                        <div>
                            <i class="fas fa-clock me-1"></i>
                            Created:
                            <?php echo !empty($student['created_at']) ? h(date('M d, Y H:i', strtotime($student['created_at']))) : '—'; ?>
                            &nbsp;·&nbsp;
                            <i class="fas fa-sync me-1"></i>
                            Last updated:
                            <?php echo !empty($student['updated_at']) ? h(date('M d, Y H:i', strtotime($student['updated_at']))) : '—'; ?>
                        </div>
                        <div style="margin-top:6px;font-size:11px;">
                            UUID: <code><?php echo h($student['uuid'] ?? ''); ?></code>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

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
            if (window.innerWidth > 768) document.getElementById('sidebar').classList.remove('open');
        });

        function logout() {
            if (confirm('Are you sure you want to logout?')) window.location.href = '/platform/tenant/logout.php';
        }

        function loadUserInfo() {
            const userStr = localStorage.getItem('user');
            if (userStr) {
                try {
                    const user = JSON.parse(userStr);
                    document.getElementById('userName').textContent = user.first_name || 'Admin';
                    document.getElementById('userAvatar').textContent = (user.first_name || 'A').charAt(0);
                    document.getElementById('userRole').textContent = (user.roles || ['Administrator'])[0];
                } catch (e) {}
            }
        }
        document.addEventListener('DOMContentLoaded', loadUserInfo);
    </script>
</body>

</html>