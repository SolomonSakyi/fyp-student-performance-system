<?php

/**
 * Student Discipline — read-only per-term incident view for a guardian.
 *
 * @package EduTrack
 * @subpackage Platform\Student
 * @version 1.0
 * @filepath public/platform/student/discipline.php
 *
 * WHAT THIS PAGE DOES:
 *   - Requires an authenticated guardian session.
 *   - Reads student_id from $_GET. Redirects to the guardian
 *     dashboard if missing or non-positive.
 *   - Calls guardian_can_view_student($studentId). On false,
 *     responds 404 (not 403).
 *   - Resolves the term filter: ?term_id=X if present and belonging
 *     to the tenant, otherwise "all terms". Unlike the attendance
 *     page, the default here is all terms, because incidents are
 *     discrete events and the guardian benefits from seeing the full
 *     history at a glance.
 *   - Loads the incident rows from student_discipline, joined to
 *     discipline_categories for the category name.
 *   - Renders the term summary cards (total incidents, by severity,
 *     by status, suspensions).
 *   - Renders the incident list, most recent first, with date,
 *     category, severity, status, action taken, and resolution notes
 *     when the incident is resolved. The internal `notes` column is
 *     not shown.
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
 *   - It does not show the internal `notes` field (staff-only).
 *   - It does not show the recording staff member.
 *   - It does not read students.guardian_* flat columns.
 *   - It does not touch any table other than students,
 *     student_discipline, discipline_categories, academic_terms.
 *
 * LOCKED DECISIONS (2026-10-03 / 2026-10-04):
 *   Q1   The guardian is the login. This page is the guardian's
 *        read-only view of one student's discipline history.
 *   Q6   Two portal surfaces: /platform/guardian/ and
 *        /platform/student/.
 *
 * PAGE DECISIONS:
 *   (1b) All incidents, most recent first, with ?term_id override.
 *   (2b) Show date, category, severity, status, action taken, and
 *        resolution notes when resolved. Hide the internal `notes`
 *        field.
 *   (3a) No chart. The data is a small set of discrete events; a
 *        table is the right form.
 *
 * DEPENDENCIES:
 *   - app/bootstrap.php
 *   - app/helpers/Permissions.php v1.2
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
// Resolve the optional term filter
// (1b) all terms by default; ?term_id=X narrows
// ============================================
$termId = isset($_GET['term_id']) ? (int)$_GET['term_id'] : 0;
$term   = null;
if ($termId > 0) {
    $term = $db->fetchOne(
        "SELECT id, term_name, is_current, sort_order, academic_year_id
         FROM academic_terms
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$termId, $tenantId]
    );
    if (!$term) {
        $termId = 0;
    }
}
$termName = $term ? (string)$term['term_name'] : '';

// ============================================
// Load the incidents
// (2b) full row except the internal `notes` field
// ============================================
$where  = [
    'd.tenant_id = ?',
    'd.student_id = ?',
    'd.deleted_at IS NULL',
];
$params = [$tenantId, $studentId];

if ($termId > 0) {
    $where[]  = 'd.academic_term_id = ?';
    $params[] = $termId;
}

$incidents = $db->fetchAll(
    "SELECT d.id, d.academic_year_id, d.academic_term_id,
            d.category_id, d.severity,
            d.incident_date, d.incident_time, d.location,
            d.description, d.action_taken,
            d.suspension_start, d.suspension_end,
            d.reviewed_at, d.resolved_at, d.resolution_notes,
            d.status, d.guardian_notified, d.guardian_notified_at,
            d.created_at,
            c.category_name, c.category_code
     FROM student_discipline d
     LEFT JOIN discipline_categories c ON c.id = d.category_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY d.incident_date DESC, d.id DESC
     LIMIT 500",
    $params
);

// ============================================
// Aggregate counts for the summary tiles
// ============================================
$totalIncidents    = 0;
$countRecorded     = 0;
$countReviewed     = 0;
$countResolved     = 0;
$countMinor        = 0;
$countModerate     = 0;
$countMajor        = 0;
$countSuspended    = 0;
$countGuardianSent = 0;

foreach ($incidents as $inc) {
    $totalIncidents++;
    switch ((string)$inc['status']) {
        case 'recorded':
            $countRecorded++;
            break;
        case 'reviewed':
            $countReviewed++;
            break;
        case 'resolved':
            $countResolved++;
            break;
    }
    switch ((string)$inc['severity']) {
        case 'minor':
            $countMinor++;
            break;
        case 'moderate':
            $countModerate++;
            break;
        case 'major':
            $countMajor++;
            break;
    }
    if (!empty($inc['suspension_start']) && !empty($inc['suspension_end'])) {
        $countSuspended++;
    }
    if ((int)$inc['guardian_notified'] === 1) {
        $countGuardianSent++;
    }
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

$pageTitle = 'Student Discipline - EduTrack';

function severityPill(string $sev): string
{
    return ['minor' => 'blue', 'moderate' => 'orange', 'major' => 'red'][$sev] ?? 'gray';
}
function severityLabel(string $sev): string
{
    return ucfirst($sev);
}
function disciplineStatusPill(string $st): string
{
    return ['recorded' => 'orange', 'reviewed' => 'blue', 'resolved' => 'green'][$st] ?? 'gray';
}
function disciplineStatusLabel(string $st): string
{
    return ucfirst($st);
}

function prettyDate(?string $sqlDate): string
{
    if (!$sqlDate) return '—';
    try {
        return (new DateTime($sqlDate))->format('D, M j, Y');
    } catch (Exception $e) {
        return (string)$sqlDate;
    }
}

function prettyTime(?string $sqlTime): string
{
    if (!$sqlTime) return '';
    // time column comes back as HH:MM:SS
    $parts = explode(':', (string)$sqlTime);
    if (count($parts) < 2) return '';
    $h = (int)$parts[0];
    $m = (int)$parts[1];
    return sprintf('%02d:%02d', $h, $m);
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

        .student-hero .sh-term .tfilt {
            font-size: 11px;
            opacity: 0.8;
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

        .summary-cell.blue .s-val {
            color: #004085;
        }

        .summary-cell.orange .s-val {
            color: #c2410c;
        }

        .summary-cell.red .s-val {
            color: #991b1b;
        }

        .summary-cell.green .s-val {
            color: #166534;
        }

        .summary-cell.gray .s-val {
            color: #495057;
        }

        .incident-list {
            display: flex;
            flex-direction: column;
        }

        .incident-row {
            display: grid;
            grid-template-columns: 130px 1fr auto;
            gap: 16px;
            padding: 16px 20px;
            border-bottom: 1px solid #f0f2f5;
            align-items: flex-start;
        }

        .incident-row:last-child {
            border-bottom: none;
        }

        .incident-row .ir-date {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            color: #495057;
            line-height: 1.4;
        }

        .incident-row .ir-date .ir-weekday {
            color: #6c757d;
            font-size: 11px;
        }

        .incident-row .ir-body {
            min-width: 0;
        }

        .incident-row .ir-head {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 6px;
        }

        .incident-row .ir-category {
            font-weight: 700;
            font-size: 14px;
            color: #1a1a2e;
        }

        .incident-row .ir-meta {
            font-size: 12px;
            color: #6c757d;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 8px;
        }

        .incident-row .ir-meta span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .incident-row .ir-block {
            font-size: 13px;
            color: #1a1a2e;
            margin-bottom: 6px;
        }

        .incident-row .ir-block .ir-label {
            font-size: 11px;
            text-transform: uppercase;
            color: #6c757d;
            font-weight: 600;
            letter-spacing: 0.4px;
            display: block;
            margin-bottom: 2px;
        }

        .incident-row .ir-resolved {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            padding: 8px 12px;
            font-size: 12px;
            color: #166534;
            margin-top: 4px;
        }

        .incident-row .ir-resolved .ir-resolved-label {
            font-weight: 700;
            display: block;
            margin-bottom: 2px;
        }

        .incident-row .ir-side {
            display: flex;
            flex-direction: column;
            gap: 6px;
            align-items: flex-end;
        }

        .incident-row .ir-side .pill {
            font-size: 11px;
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

        .pill.blue {
            background: #cce5ff;
            color: #004085;
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

        .filter-strip {
            background: #fff;
            border-radius: 14px;
            padding: 12px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .filter-strip .fs-label {
            font-size: 12px;
            color: #6c757d;
            font-weight: 600;
        }

        .filter-strip .fs-note {
            font-size: 12px;
            color: #495057;
        }

        .filter-strip .fs-actions a {
            font-size: 12px;
            text-decoration: none;
            color: #0d6efd;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            background: #e3f0ff;
        }

        .filter-strip .fs-actions a:hover {
            background: #cce5ff;
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

            .incident-row {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .incident-row .ir-side {
                flex-direction: row;
                align-items: center;
                justify-content: flex-start;
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
                    <div class="tname">Discipline</div>
                    <div class="tfilt">
                        <?php echo $termName !== '' ? h_g($termName) : 'All terms'; ?>
                    </div>
                </div>
            </div>

            <div class="filter-strip">
                <div>
                    <div class="fs-label">Showing</div>
                    <div class="fs-note">
                        <?php if ($termName !== ''): ?>
                            <?php echo h_g($termName); ?> — <?php echo h_g((string)$totalIncidents); ?> incident(s)
                        <?php else: ?>
                            All terms — <?php echo h_g((string)$totalIncidents); ?> incident(s)
                        <?php endif; ?>
                    </div>
                </div>
                <div class="fs-actions">
                    <?php if ($termName !== ''): ?>
                        <a href="/platform/student/discipline.php?student_id=<?php echo (int)$studentId; ?>">
                            <i class="fas fa-times me-1"></i> Clear term filter
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card-custom">
                <div class="card-header-custom">
                    <h6><i class="fas fa-chart-bar me-2 text-primary"></i>Overview</h6>
                </div>
                <div class="card-body-custom">
                    <div class="summary-grid">
                        <div class="summary-cell blue">
                            <div class="s-lbl">Total incidents</div>
                            <div class="s-val"><?php echo h_g((string)$totalIncidents); ?></div>
                        </div>
                        <div class="summary-cell gray">
                            <div class="s-lbl">Recorded</div>
                            <div class="s-val"><?php echo h_g((string)$countRecorded); ?></div>
                        </div>
                        <div class="summary-cell blue">
                            <div class="s-lbl">Reviewed</div>
                            <div class="s-val"><?php echo h_g((string)$countReviewed); ?></div>
                        </div>
                        <div class="summary-cell green">
                            <div class="s-lbl">Resolved</div>
                            <div class="s-val"><?php echo h_g((string)$countResolved); ?></div>
                        </div>
                        <div class="summary-cell blue">
                            <div class="s-lbl">Minor</div>
                            <div class="s-val"><?php echo h_g((string)$countMinor); ?></div>
                        </div>
                        <div class="summary-cell orange">
                            <div class="s-lbl">Moderate</div>
                            <div class="s-val"><?php echo h_g((string)$countModerate); ?></div>
                        </div>
                        <div class="summary-cell red">
                            <div class="s-lbl">Major</div>
                            <div class="s-val"><?php echo h_g((string)$countMajor); ?></div>
                        </div>
                        <div class="summary-cell red">
                            <div class="s-lbl">Suspensions</div>
                            <div class="s-val"><?php echo h_g((string)$countSuspended); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-custom">
                <div class="card-header-custom">
                    <h6><i class="fas fa-gavel me-2 text-primary"></i>Incidents</h6>
                    <span style="font-size:12px;color:#6c757d;"><?php echo h_g((string)$totalIncidents); ?> record(s)</span>
                </div>
                <div class="card-body-custom" style="padding:0;">
                    <?php if (empty($incidents)): ?>
                        <div class="empty-state">
                            <i class="fas fa-shield-alt"></i>
                            <h5>No incidents recorded</h5>
                            <p>
                                <?php if ($termName !== ''): ?>
                                    No discipline incidents have been logged for this student in <?php echo h_g($termName); ?>.
                                <?php else: ?>
                                    No discipline incidents have been logged for this student.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="incident-list">
                            <?php foreach ($incidents as $inc):
                                $sev   = (string)$inc['severity'];
                                $st    = (string)$inc['status'];
                                $catNm = (string)($inc['category_name'] ?? '');
                                $catCd = (string)($inc['category_code'] ?? '');
                                $suspStart = (string)($inc['suspension_start'] ?? '');
                                $suspEnd   = (string)($inc['suspension_end'] ?? '');
                                $resNotes  = (string)($inc['resolution_notes'] ?? '');
                                $incTime   = prettyTime($inc['incident_time'] ?? '');
                                $incDate   = (string)($inc['incident_date'] ?? '');
                                $weekday   = '';
                                if ($incDate !== '') {
                                    try {
                                        $weekday = (new DateTime($incDate))->format('l');
                                    } catch (Exception $e) {
                                        $weekday = '';
                                    }
                                }
                            ?>
                                <div class="incident-row">
                                    <div class="ir-date">
                                        <div><?php echo h_g(prettyDate($incDate)); ?></div>
                                        <?php if ($weekday !== ''): ?>
                                            <div class="ir-weekday"><?php echo h_g($weekday); ?></div>
                                        <?php endif; ?>
                                        <?php if ($incTime !== ''): ?>
                                            <div class="ir-weekday"><i class="fas fa-clock me-1"></i><?php echo h_g($incTime); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ir-body">
                                        <div class="ir-head">
                                            <span class="ir-category"><?php echo h_g($catNm !== '' ? $catNm : 'Incident'); ?></span>
                                            <?php if ($catCd !== ''): ?>
                                                <span class="pill gray"><?php echo h_g($catCd); ?></span>
                                            <?php endif; ?>
                                            <span class="pill <?php echo severityPill($sev); ?>">
                                                <?php echo h_g(severityLabel($sev)); ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($inc['location'])): ?>
                                            <div class="ir-meta">
                                                <span><i class="fas fa-map-marker-alt"></i><?php echo h_g((string)$inc['location']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($inc['description'])): ?>
                                            <div class="ir-block">
                                                <span class="ir-label">What happened</span>
                                                <?php echo nl2br(h_g((string)$inc['description'])); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($inc['action_taken'])): ?>
                                            <div class="ir-block">
                                                <span class="ir-label">Action taken</span>
                                                <?php echo nl2br(h_g((string)$inc['action_taken'])); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($suspStart !== '' && $suspEnd !== ''): ?>
                                            <div class="ir-block">
                                                <span class="ir-label">Suspension</span>
                                                <span class="pill red"><i class="fas fa-ban me-1"></i>
                                                    <?php echo h_g(prettyDate($suspStart)); ?> →
                                                    <?php echo h_g(prettyDate($suspEnd)); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($st === 'resolved' && $resNotes !== ''): ?>
                                            <div class="ir-resolved">
                                                <span class="ir-resolved-label">
                                                    <i class="fas fa-check-circle me-1"></i>Resolution
                                                </span>
                                                <?php echo nl2br(h_g($resNotes)); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ir-side">
                                        <span class="pill <?php echo disciplineStatusPill($st); ?>">
                                            <?php echo h_g(disciplineStatusLabel($st)); ?>
                                        </span>
                                        <?php if ((int)$inc['guardian_notified'] === 1): ?>
                                            <span class="pill teal" title="Guardian was notified">
                                                <i class="fas fa-bell"></i> Notified
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>