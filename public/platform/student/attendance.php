<?php

/**
 * Student Attendance — read-only per-term day-grain and summary view
 * for a guardian, with an enterprise graphical summary.
 *
 * @package EduTrack
 * @subpackage Platform\Student
 * @version 1.1
 * @filepath public/platform/student/attendance.php
 *
 * WHAT THIS PAGE DOES:
 *   - Requires an authenticated guardian session.
 *   - Reads student_id from $_GET. Redirects to the guardian
 *     dashboard if missing or non-positive.
 *   - Calls guardian_can_view_student($studentId). On false,
 *     responds 404 (not 403).
 *   - Resolves the term: ?term_id=X if present and belonging to the
 *     tenant, otherwise the current term for the tenant.
 *   - Loads the term summary row from attendance_summaries, if any.
 *   - Loads the day-grain rows from student_attendance for that term,
 *     most recent first.
 *   - If no summary row exists, computes the four counts from the
 *     day-grain rows and labels the result "not yet verified".
 *   - Renders a Graphical Summary card with a donut and a horizontal
 *     bar, both driven by the same four values.
 *   - Renders the term summary card (present/absent/late/excused/
 *     total + coverage note + verification status), or an empty-state
 *     card if no summary exists yet.
 *   - Renders the day-grain table (date, status, minutes late, notes).
 *   - Provides links back to the student overview and the guardian
 *     dashboard.
 *
 * AUTHENTICATION MODEL:
 *   Session keys read (written by login.php v2.4):
 *     $_SESSION['logged_in']   = true
 *     $_SESSION['user_type']   = 'guardian'
 *     $_SESSION['guardian_id'] = the guardians.id
 *     $_SESSION['tenant_id']   = the tenant
 *     $_SESSION['school_id']   = the school
 *
 * THE 404-NOT-403 RULE:
 *   Same rule as /platform/student/index.php. When
 *   guardian_can_view_student($studentId) returns false, the page
 *   responds 404. A 403 would tell a probing user that the student
 *   exists but is not theirs.
 *
 * WHAT THIS PAGE DOES NOT DO:
 *   - It does not edit anything. No POST handlers, no CSRF, no audit.
 *   - It does not show the class teacher's marking UI.
 *   - It does not read students.guardian_* flat columns.
 *   - It does not touch any table other than students,
 *     student_attendance, attendance_summaries, academic_terms,
 *     academic_years.
 *
 * v1.1 changes (2026-10-04):
 *   - Added Graphical Summary card: Chart.js donut + horizontal bar,
 *     driven by the four counts and their percentages of the total.
 *   - Added fallback aggregate: when no attendance_summaries row
 *     exists for the current term, compute the four counts from the
 *     student_attendance rows and label the result "not yet
 *     verified". No schema change, no write.
 *   - Added three small CSS classes (.chart-card, .chart-row,
 *     .chart-wrap). No existing CSS rule changed.
 *   - Added the Chart.js CDN script (jsdelivr) and one inline
 *     initialiser. The library is only fetched when chart data
 *     exists.
 *   - Nothing else in the file changed. The read shape of the two
 *     existing queries is unchanged.
 *
 * LOCKED DECISIONS (2026-10-03 / 2026-10-04):
 *   Q1   The guardian is the login. This page is the guardian's
 *        read-only view of one student's attendance.
 *   Q6   Two portal surfaces: /platform/guardian/ and
 *        /platform/student/.
 *
 * DEPENDENCIES:
 *   - app/bootstrap.php
 *   - app/helpers/Permissions.php v1.2
 *   - Chart.js (cdn.jsdelivr.net)
 */

// ============================================
// Bootstrap
// ============================================
$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/app/bootstrap.php';
require_once $projectRoot . '/app/helpers/Permissions.php';

function h_g(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ============================================
// Guardian authentication
// ============================================
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/tenant/login.php');
    exit;
}

if (!is_guardian()) {
    if (function_exists('is_super_admin') && is_super_admin()) {
        header('Location: /platform/index.php');
        exit;
    }
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

$guardianId = (int)($_SESSION['guardian_id'] ?? 0);
$tenantId   = (int)($_SESSION['tenant_id']   ?? 0);
$schoolId   = (int)($_SESSION['school_id']   ?? 0);

if ($guardianId <= 0 || $tenantId <= 0) {
    session_destroy();
    header('Location: /platform/tenant/login.php');
    exit;
}

// ============================================
// student_id
// ============================================
$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

if ($studentId <= 0) {
    header('Location: /platform/guardian/index.php');
    exit;
}

// ============================================
// Access check — 404 on failure (not 403)
// ============================================
$allowed = guardian_can_view_student($studentId);
if (!$allowed) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Not found</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <style>
            body {
                font-family: system-ui, sans-serif;
                background: #f0f2f5;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .nf-card {
                background: #fff;
                border-radius: 16px;
                padding: 40px;
                max-width: 480px;
                text-align: center;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            }

            .nf-card i {
                font-size: 48px;
                color: #dc3545;
                margin-bottom: 16px;
                display: block;
            }

            .nf-card h1 {
                font-size: 22px;
                font-weight: 700;
                color: #1a1a2e;
                margin-bottom: 8px;
            }

            .nf-card p {
                color: #6c757d;
                font-size: 14px;
                margin: 0 0 16px;
            }

            .nf-card a {
                color: #0d6efd;
                text-decoration: none;
                font-weight: 600;
            }
        </style>
    </head>

    <body>
        <div class="nf-card">
            <i class="fas fa-user-slash"></i>
            <h1>Not found</h1>
            <p>The record you are looking for is not available.</p>
            <a href="/platform/guardian/index.php"><i class="fas fa-arrow-left me-1"></i> Back to dashboard</a>
        </div>
    </body>

    </html>
<?php
    exit;
}

$db = DatabaseHelper::getInstance();

// ============================================
// Load the student (minimal fields for the hero)
// ============================================
$student = $db->fetchOne(
    "SELECT s.id, s.tenant_id, s.student_number,
            s.first_name, s.middle_name, s.last_name, s.preferred_name
     FROM students s
     WHERE s.id = ? AND s.tenant_id = ? AND s.deleted_at IS NULL",
    [$studentId, $tenantId]
);

if (!$student) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Not found</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <style>
            body {
                font-family: system-ui, sans-serif;
                background: #f0f2f5;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .nf-card {
                background: #fff;
                border-radius: 16px;
                padding: 40px;
                max-width: 480px;
                text-align: center;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            }

            .nf-card i {
                font-size: 48px;
                color: #dc3545;
                margin-bottom: 16px;
                display: block;
            }

            .nf-card h1 {
                font-size: 22px;
                font-weight: 700;
                color: #1a1a2e;
                margin-bottom: 8px;
            }

            .nf-card p {
                color: #6c757d;
                font-size: 14px;
                margin: 0 0 16px;
            }

            .nf-card a {
                color: #0d6efd;
                text-decoration: none;
                font-weight: 600;
            }
        </style>
    </head>

    <body>
        <div class="nf-card">
            <i class="fas fa-user-slash"></i>
            <h1>Not found</h1>
            <p>The record you are looking for is not available.</p>
            <a href="/platform/guardian/index.php"><i class="fas fa-arrow-left me-1"></i> Back to dashboard</a>
        </div>
    </body>

    </html>
<?php
    exit;
}

// ============================================
// Resolve the term
// A1: current term by default; ?term_id=X overrides
// ============================================
$termId = isset($_GET['term_id']) ? (int)$_GET['term_id'] : 0;

$term = null;
if ($termId > 0) {
    $term = $db->fetchOne(
        "SELECT id, term_name, is_current, sort_order, academic_year_id
         FROM academic_terms
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$termId, $tenantId]
    );
}

if (!$term) {
    $term = $db->fetchOne(
        "SELECT id, term_name, is_current, sort_order, academic_year_id
         FROM academic_terms
         WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL
         ORDER BY is_current DESC, sort_order ASC, id ASC
         LIMIT 1",
        [$tenantId]
    );
}

$termId   = $term ? (int)$term['id'] : 0;
$termName = $term ? (string)$term['term_name'] : '';

// Resolve the academic year for the term, if any (best-effort; no failure if absent)
$yearName = '';
if ($term && !empty($term['academic_year_id'])) {
    try {
        $yr = $db->fetchOne(
            "SELECT id, year_name FROM academic_years
             WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [(int)$term['academic_year_id'], $tenantId]
        );
        if ($yr) $yearName = (string)$yr['year_name'];
    } catch (Exception $e) {
        $yearName = '';
    }
}

// ============================================
// Load the term summary row (if any)
// ============================================
$summary = null;
if ($termId > 0) {
    $summary = $db->fetchOne(
        "SELECT id, days_present, days_absent, days_late, days_excused,
                days_total, coverage_note, status, verified_at
         FROM attendance_summaries
         WHERE tenant_id = ? AND student_id = ? AND academic_term_id = ?
           AND deleted_at IS NULL
         ORDER BY id DESC
         LIMIT 1",
        [$tenantId, $studentId, $termId]
    );
}

// ============================================
// Load the day-grain rows for the term (most recent first)
// ============================================
$days = [];
if ($termId > 0) {
    $days = $db->fetchAll(
        "SELECT id, attendance_date, status, minutes_late, notes, source, marked_at
         FROM student_attendance
         WHERE tenant_id = ? AND student_id = ? AND academic_term_id = ?
           AND deleted_at IS NULL
         ORDER BY attendance_date DESC, id DESC
         LIMIT 500",
        [$tenantId, $studentId, $termId]
    );
}

// ============================================
// Graphical summary data
// (4b) recompute denominator from the four counts
// (5c) fall back to day-grain counts if no summary row exists
// ============================================
$hasChartData  = false;
$summarySource = 'none'; // 'verified' | 'unverified' | 'computed' | 'none'
$chartPresent  = 0;
$chartAbsent   = 0;
$chartLate     = 0;
$chartExcused  = 0;
$chartTotal    = 0;
$pctPresent    = 0.0;
$pctAbsent     = 0.0;
$pctLate       = 0.0;
$pctExcused    = 0.0;

if ($summary) {
    $chartPresent = (int)$summary['days_present'];
    $chartAbsent  = (int)$summary['days_absent'];
    $chartLate    = (int)$summary['days_late'];
    $chartExcused = (int)$summary['days_excused'];
    $summarySource = ((string)$summary['status'] === 'verified') ? 'verified' : 'unverified';
} elseif (!empty($days)) {
    foreach ($days as $d) {
        switch ((string)$d['status']) {
            case 'present':
                $chartPresent++;
                break;
            case 'absent':
                $chartAbsent++;
                break;
            case 'late':
                $chartLate++;
                break;
            case 'excused':
                $chartExcused++;
                break;
        }
    }
    $summarySource = 'computed';
}

$chartTotal = $chartPresent + $chartAbsent + $chartLate + $chartExcused;

if ($chartTotal > 0) {
    $hasChartData = true;
    $pctPresent = round(($chartPresent / $chartTotal) * 100, 1);
    $pctAbsent  = round(($chartAbsent  / $chartTotal) * 100, 1);
    $pctLate    = round(($chartLate    / $chartTotal) * 100, 1);
    $pctExcused = round(($chartExcused / $chartTotal) * 100, 1);
}

// ============================================
// Composed display values
// ============================================
$fullName = trim(
    ($student['first_name'] ?? '') . ' ' .
        ($student['middle_name'] ?? '') . ' ' .
        ($student['last_name'] ?? '')
);
$fullName = $fullName !== '' ? preg_replace('/\s+/', ' ', $fullName) : ('Student #' . $studentId);

$initials = strtoupper(
    substr((string)($student['first_name'] ?? ''), 0, 1) .
        substr((string)($student['last_name'] ?? ''), 0, 1)
);
if ($initials === '') $initials = 'ST';

$studentNumber = (string)($student['student_number'] ?? '');

$schoolName = (string)($_SESSION['school_name'] ?? '');
$schoolLogo = (string)($_SESSION['school_logo'] ?? '');

$pageTitle = 'Student Attendance - EduTrack';

function statusPill(string $status): string
{
    switch ($status) {
        case 'present':
            return 'green';
        case 'absent':
            return 'red';
        case 'late':
            return 'orange';
        case 'excused':
            return 'teal';
        default:
            return 'gray';
    }
}

function statusLabel(string $status): string
{
    return ucfirst($status);
}

function summaryStatusPill(string $status): string
{
    return $status === 'verified' ? 'green' : 'gray';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo h_g($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?php if ($schoolLogo !== ''): ?>
        <link rel="icon" href="<?php echo h_g($schoolLogo); ?>">
    <?php endif; ?>
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

        .top-bar {
            background: #fff;
            padding: 12px 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-bar .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .top-bar .brand img {
            max-height: 40px;
            max-width: 40px;
        }

        .top-bar .brand .brand-text h1 {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
            color: #1a1a2e;
        }

        .top-bar .brand .brand-text p {
            font-size: 12px;
            margin: 0;
            color: #6c757d;
        }

        .top-bar .actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .top-bar .actions .badge {
            background: #e3f0ff;
            color: #0d6efd;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .btn-outline-secondary {
            background: transparent;
            border: 1.5px solid #e9ecef;
            color: #6c757d;
            border-radius: 8px;
            padding: 6px 14px;
            font-size: 13px;
            text-decoration: none;
        }

        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: #ced4da;
            color: #495057;
        }

        .content-area {
            padding: 20px 20px 40px;
            max-width: 1100px;
            margin: 0 auto;
        }

        .student-hero {
            background: linear-gradient(135deg, #1a1a2e 0%, #2a2a4e 100%);
            color: #fff;
            border-radius: 14px;
            padding: 20px 26px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
        }

        .student-hero .sh-avatar {
            width: 62px;
            height: 62px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 24px;
            flex-shrink: 0;
        }

        .student-hero .sh-body {
            flex: 1;
            min-width: 0;
        }

        .student-hero .sh-body h2 {
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 2px;
        }

        .student-hero .sh-body .sn {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            opacity: 0.75;
        }

        .student-hero .sh-term {
            text-align: right;
            font-size: 12px;
            opacity: 0.85;
        }

        .student-hero .sh-term .tname {
            font-weight: 700;
            font-size: 14px;
            opacity: 1;
        }

        .card-custom {
            background: #fff;
            border-radius: 14px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .card-custom .card-header-custom {
            padding: 14px 20px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .card-custom .card-header-custom h6 {
            font-size: 14px;
            font-weight: 700;
            margin: 0;
            color: #1a1a2e;
        }

        .card-custom .card-body-custom {
            padding: 16px 20px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
        }

        .summary-cell {
            background: #fafbfc;
            border: 1px solid #eef0f3;
            border-radius: 10px;
            padding: 12px 14px;
            text-align: center;
        }

        .summary-cell .s-lbl {
            font-size: 11px;
            text-transform: uppercase;
            color: #6c757d;
            font-weight: 600;
            letter-spacing: 0.4px;
        }

        .summary-cell .s-val {
            font-size: 22px;
            font-weight: 700;
            color: #1a1a2e;
            font-family: 'Courier New', monospace;
            margin-top: 4px;
        }

        .summary-cell.green .s-val {
            color: #166534;
        }

        .summary-cell.red .s-val {
            color: #991b1b;
        }

        .summary-cell.orange .s-val {
            color: #c2410c;
        }

        .summary-cell.teal .s-val {
            color: #0d5c4a;
        }

        /* Graphical summary card */
        .chart-card {
            padding: 18px 20px 8px;
        }

        .chart-row {
            display: grid;
            grid-template-columns: minmax(240px, 1fr) minmax(240px, 1.4fr);
            gap: 20px;
            align-items: center;
        }

        @media (max-width: 720px) {
            .chart-row {
                grid-template-columns: 1fr;
            }
        }

        .chart-wrap {
            position: relative;
            width: 100%;
            min-height: 240px;
        }

        .chart-wrap.donut {
            max-width: 320px;
            margin: 0 auto;
        }

        .chart-legend {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 10px;
            font-size: 12px;
            color: #495057;
        }

        .chart-legend .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .chart-legend .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }

        .chart-legend .legend-dot.present {
            background: #16a34a;
        }

        .chart-legend .legend-dot.absent {
            background: #dc2626;
        }

        .chart-legend .legend-dot.late {
            background: #f59e0b;
        }

        .chart-legend .legend-dot.excused {
            background: #0ea5e9;
        }

        .chart-note {
            font-size: 11px;
            color: #6c757d;
            text-align: center;
            padding: 8px 0 12px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .data-table thead th {
            background: #f8f9fa;
            padding: 10px 14px;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            border-bottom: 1px solid #e9ecef;
            text-align: left;
            white-space: nowrap;
        }

        .data-table tbody td {
            padding: 10px 14px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .data-table tbody tr:last-child td {
            border-bottom: none;
        }

        .data-table tbody tr:hover {
            background: #fafbfc;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .pill.green {
            background: #d4edda;
            color: #155724;
        }

        .pill.red {
            background: #f8d7da;
            color: #721c24;
        }

        .pill.orange {
            background: #ffe8d9;
            color: #c2410c;
        }

        .pill.teal {
            background: #d1f2eb;
            color: #0d5c4a;
        }

        .pill.gray {
            background: #e9ecef;
            color: #495057;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 40px;
            opacity: 0.3;
            display: block;
            margin-bottom: 12px;
        }

        .empty-state h5 {
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 6px;
        }

        .empty-state p {
            font-size: 13px;
            margin: 0;
        }

        @media (max-width: 600px) {
            .content-area {
                padding: 14px 14px 30px;
            }

            .student-hero {
                padding: 16px 18px;
                gap: 14px;
            }

            .student-hero .sh-avatar {
                width: 52px;
                height: 52px;
                font-size: 20px;
            }

            .student-hero .sh-body h2 {
                font-size: 18px;
            }

            .student-hero .sh-term {
                text-align: left;
                width: 100%;
            }

            .data-table {
                font-size: 12px;
            }

            .data-table thead th {
                padding: 8px 10px;
                font-size: 10px;
            }

            .data-table tbody td {
                padding: 8px 10px;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="top-bar">
            <div class="brand">
                <?php if ($schoolLogo !== ''): ?>
                    <img src="<?php echo h_g($schoolLogo); ?>" alt="">
                <?php endif; ?>
                <div class="brand-text">
                    <h1><?php echo h_g($schoolName !== '' ? $schoolName : 'EduTrack'); ?></h1>
                    <p>Student Portal</p>
                </div>
            </div>
            <div class="actions">
                <span class="badge"><i class="fas fa-user-shield me-1"></i> Guardian</span>
                <a href="/platform/student/index.php?student_id=<?php echo (int)$studentId; ?>" class="btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Overview
                </a>
                <a href="/platform/guardian/index.php" class="btn-outline-secondary">
                    <i class="fas fa-home me-1"></i> Dashboard
                </a>
                <a href="/platform/logout.php" class="btn-outline-secondary">
                    <i class="fas fa-sign-out-alt me-1"></i> Logout
                </a>
            </div>
        </div>

        <div class="content-area">

            <div class="student-hero">
                <div class="sh-avatar"><?php echo h_g($initials); ?></div>
                <div class="sh-body">
                    <h2><?php echo h_g($fullName); ?></h2>
                    <div class="sn"><i class="fas fa-id-badge me-1"></i><?php echo h_g($studentNumber); ?></div>
                </div>
                <div class="sh-term">
                    <div class="tname"><?php echo h_g($termName !== '' ? $termName : 'No active term'); ?></div>
                    <?php if ($yearName !== ''): ?>
                        <div><?php echo h_g($yearName); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Graphical Summary -->
            <div class="card-custom">
                <div class="card-header-custom">
                    <h6><i class="fas fa-chart-pie me-2 text-primary"></i>Graphical Summary</h6>
                    <?php if ($summarySource === 'verified'): ?>
                        <span class="pill green"><i class="fas fa-check-circle"></i> Verified</span>
                    <?php elseif ($summarySource === 'unverified'): ?>
                        <span class="pill gray"><i class="fas fa-hourglass-half"></i> Not yet verified</span>
                    <?php elseif ($summarySource === 'computed'): ?>
                        <span class="pill gray"><i class="fas fa-calculator"></i> Computed from day records</span>
                    <?php endif; ?>
                </div>
                <div class="card-body-custom chart-card">
                    <?php if (!$hasChartData): ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-pie"></i>
                            <h5>No attendance data to chart</h5>
                            <p>The class teacher has not marked any attendance for this student in this term.</p>
                        </div>
                    <?php else: ?>
                        <div class="chart-row">
                            <div>
                                <div class="chart-wrap donut">
                                    <canvas id="attendanceDonut" aria-label="Attendance distribution by status" role="img"></canvas>
                                </div>
                                <div class="chart-legend">
                                    <span class="legend-item"><span class="legend-dot present"></span>Present <?php echo h_g((string)$pctPresent); ?>%</span>
                                    <span class="legend-item"><span class="legend-dot absent"></span>Absent <?php echo h_g((string)$pctAbsent); ?>%</span>
                                    <span class="legend-item"><span class="legend-dot late"></span>Late <?php echo h_g((string)$pctLate); ?>%</span>
                                    <span class="legend-item"><span class="legend-dot excused"></span>Excused <?php echo h_g((string)$pctExcused); ?>%</span>
                                </div>
                            </div>
                            <div>
                                <div class="chart-wrap">
                                    <canvas id="attendanceBar" aria-label="Attendance counts by status" role="img"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="chart-note">
                            <?php if ($summarySource === 'verified'): ?>
                                Based on the verified term summary (<?php echo h_g((string)$chartTotal); ?> recorded day(s)).
                            <?php elseif ($summarySource === 'unverified'): ?>
                                Based on the term summary that the class teacher has written but not yet verified (<?php echo h_g((string)$chartTotal); ?> recorded day(s)).
                            <?php elseif ($summarySource === 'computed'): ?>
                                Computed from the day records below; the class teacher has not yet verified this term (<?php echo h_g((string)$chartTotal); ?> recorded day(s)).
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card-custom">
                <div class="card-header-custom">
                    <h6><i class="fas fa-clipboard-check me-2 text-primary"></i>Term Summary</h6>
                    <?php if ($summary): ?>
                        <span class="pill <?php echo summaryStatusPill((string)$summary['status']); ?>">
                            <?php echo h_g(ucfirst((string)$summary['status'])); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="card-body-custom">
                    <?php if (!$summary): ?>
                        <div class="empty-state">
                            <i class="fas fa-hourglass-half"></i>
                            <h5>Summary not yet available</h5>
                            <p>The class teacher has not verified attendance for this term yet.
                                The day records below are still current.</p>
                        </div>
                    <?php else: ?>
                        <div class="summary-grid">
                            <div class="summary-cell green">
                                <div class="s-lbl">Present</div>
                                <div class="s-val"><?php echo h_g((string)(int)$summary['days_present']); ?></div>
                            </div>
                            <div class="summary-cell red">
                                <div class="s-lbl">Absent</div>
                                <div class="s-val"><?php echo h_g((string)(int)$summary['days_absent']); ?></div>
                            </div>
                            <div class="summary-cell orange">
                                <div class="s-lbl">Late</div>
                                <div class="s-val"><?php echo h_g((string)(int)$summary['days_late']); ?></div>
                            </div>
                            <div class="summary-cell teal">
                                <div class="s-lbl">Excused</div>
                                <div class="s-val"><?php echo h_g((string)(int)$summary['days_excused']); ?></div>
                            </div>
                            <div class="summary-cell">
                                <div class="s-lbl">Total recorded</div>
                                <div class="s-val"><?php echo h_g((string)(int)$summary['days_total']); ?></div>
                            </div>
                        </div>
                        <?php if (!empty($summary['coverage_note'])): ?>
                            <div style="margin-top:12px;padding:10px 14px;background:#fffbf0;border:1px solid #fde68a;border-radius:10px;font-size:12px;color:#92400e;">
                                <i class="fas fa-info-circle me-1"></i><?php echo h_g((string)$summary['coverage_note']); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($summary['verified_at'])): ?>
                            <div style="margin-top:12px;font-size:12px;color:#6c757d;">
                                <i class="fas fa-check-circle me-1"></i>Verified on
                                <?php
                                try {
                                    echo h_g((new DateTime((string)$summary['verified_at']))->format('M j, Y'));
                                } catch (Exception $e) {
                                    echo h_g((string)$summary['verified_at']);
                                }
                                ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card-custom">
                <div class="card-header-custom">
                    <h6><i class="fas fa-calendar-check me-2 text-primary"></i>Day Records</h6>
                    <span style="font-size:12px;color:#6c757d;"><?php echo h_g((string)count($days)); ?> record(s)</span>
                </div>
                <div class="card-body-custom" style="padding:0;">
                    <?php if (empty($days)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <h5>No attendance records yet</h5>
                            <p>The class teacher has not marked this student's attendance for this term.</p>
                        </div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width:150px;">Date</th>
                                    <th style="width:130px;">Status</th>
                                    <th style="width:120px;">Minutes late</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($days as $d):
                                    $st = (string)$d['status'];
                                ?>
                                    <tr>
                                        <td style="font-family:'Courier New',monospace;font-size:12px;">
                                            <?php
                                            try {
                                                echo h_g((new DateTime((string)$d['attendance_date']))->format('D, M j, Y'));
                                            } catch (Exception $e) {
                                                echo h_g((string)$d['attendance_date']);
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <span class="pill <?php echo statusPill($st); ?>">
                                                <?php echo h_g(statusLabel($st)); ?>
                                            </span>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php echo $d['minutes_late'] !== null && $d['minutes_late'] !== ''
                                                ? h_g((string)(int)$d['minutes_late'])
                                                : '—'; ?>
                                        </td>
                                        <td style="font-size:12px;color:#6c757d;">
                                            <?php echo !empty($d['notes']) ? h_g((string)$d['notes']) : '—'; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($hasChartData): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script>
            (function() {
                const counts = {
                    present: <?php echo (int)$chartPresent; ?>,
                    absent: <?php echo (int)$chartAbsent;  ?>,
                    late: <?php echo (int)$chartLate;    ?>,
                    excused: <?php echo (int)$chartExcused; ?>
                };
                const pct = {
                    present: <?php echo (float)$pctPresent; ?>,
                    absent: <?php echo (float)$pctAbsent;  ?>,
                    late: <?php echo (float)$pctLate;    ?>,
                    excused: <?php echo (float)$pctExcused; ?>
                };
                const colors = {
                    present: '#16a34a',
                    absent: '#dc2626',
                    late: '#f59e0b',
                    excused: '#0ea5e9'
                };
                const labels = ['Present', 'Absent', 'Late', 'Excused'];
                const values = [counts.present, counts.absent, counts.late, counts.excused];
                const bg = [colors.present, colors.absent, colors.late, colors.excused];

                const total = values.reduce(function(s, v) {
                    return s + v;
                }, 0);

                const donutEl = document.getElementById('attendanceDonut');
                if (donutEl && typeof Chart !== 'undefined') {
                    new Chart(donutEl, {
                        type: 'doughnut',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: values,
                                backgroundColor: bg,
                                borderColor: '#fff',
                                borderWidth: 2
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            cutout: '62%',
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(ctx) {
                                            const v = ctx.parsed || 0;
                                            const p = total > 0 ? Math.round((v / total) * 1000) / 10 : 0;
                                            return ctx.label + ': ' + v + ' (' + p + '%)';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }

                const barEl = document.getElementById('attendanceBar');
                if (barEl && typeof Chart !== 'undefined') {
                    new Chart(barEl, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Days recorded',
                                data: values,
                                backgroundColor: bg,
                                borderRadius: 6,
                                barThickness: 22
                            }]
                        },
                        options: {
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                x: {
                                    beginAtZero: true,
                                    ticks: {
                                        precision: 0
                                    }
                                },
                                y: {
                                    grid: {
                                        display: false
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(ctx) {
                                            const v = ctx.parsed.x || 0;
                                            const p = total > 0 ? Math.round((v / total) * 1000) / 10 : 0;
                                            return v + ' day(s) (' + p + '%)';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            })();
        </script>
    <?php endif; ?>
</body>

</html>