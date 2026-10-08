<?php

/**
 * Student Behaviour — read-only per-term conduct view for a guardian.
 *
 * @package EduTrack
 * @subpackage Platform\Student
 * @version 1.0
 * @filepath public/platform/student/behaviour.php
 *
 * WHAT THIS PAGE DOES:
 *   - Requires an authenticated guardian session.
 *   - Reads student_id from $_GET. Redirects to the guardian
 *     dashboard if missing or non-positive.
 *   - Calls guardian_can_view_student($studentId). On false,
 *     responds 404 (not 403).
 *   - Resolves the term filter: ?term_id=X if present and belonging
 *     to the tenant, otherwise "all terms". Matches the discipline
 *     page's (1b) pattern.
 *   - Loads the behaviour rows from student_behaviour, most recent
 *     first.
 *   - Renders the term summary tiles (rows count, finalised count,
 *     pass count, fail count).
 *   - Renders the behaviour list, one card per term, showing: term
 *     name and year, final score, final grade, pass/fail, status,
 *     and the four breakdown numbers (incidents minor/moderate/major,
 *     attendance deduction, checklist bonus).
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
 *   - It does not show the override mechanism (override_score,
 *     override_reason, overridden_by, overridden_at).
 *   - It does not show the checklist ratings (punctuality, respect,
 *     participation) as raw 1–5 values. Only the aggregate
 *     checklist_bonus appears.
 *   - It does not show the staff-only `notes` or `finalised_by`
 *     fields.
 *   - It does not read students.guardian_* flat columns.
 *   - It does not touch any table other than students,
 *     student_behaviour, academic_terms, academic_years.
 *
 * LOCKED DECISIONS (2026-10-03 / 2026-10-04):
 *   Q1   The guardian is the login. This page is the guardian's
 *        read-only view of one student's conduct record.
 *   Q6   Two portal surfaces: /platform/guardian/ and
 *        /platform/student/.
 *
 * PAGE DECISIONS:
 *   (1b) All rows, most recent first, with ?term_id override.
 *   (2b) Show term, final score, final grade, pass/fail, status,
 *        and the four breakdown numbers.
 *   (3a) No chart. A small set of per-term rows is a table.
 *   (4a) Override invisible. The guardian sees only the resulting
 *        final_score and final_grade.
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
// Load the behaviour rows
// (2b) final_score, final_grade, is_pass, status, breakdown
// (4a) override columns are NOT selected
// ============================================
$where  = [
    'b.tenant_id = ?',
    'b.student_id = ?',
    'b.deleted_at IS NULL',
];
$params = [$tenantId, $studentId];

if ($termId > 0) {
    $where[]  = 'b.academic_term_id = ?';
    $params[] = $termId;
}

$rows = $db->fetchAll(
    "SELECT b.id, b.academic_year_id, b.academic_term_id,
            b.incidents_minor, b.incidents_moderate, b.incidents_major,
            b.discipline_deduction,
            b.absence_count, b.late_count, b.attendance_deduction,
            b.checklist_bonus,
            b.computed_score, b.computed_grade,
            b.final_score, b.final_grade, b.is_pass,
            b.status, b.generated_at, b.finalised_at,
            t.term_name, t.is_current AS term_is_current,
            ay.year_name
     FROM student_behaviour b
     LEFT JOIN academic_terms t ON t.id = b.academic_term_id
     LEFT JOIN academic_years ay ON ay.id = b.academic_year_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY b.academic_year_id DESC, b.academic_term_id DESC, b.id DESC
     LIMIT 500",
    $params
);

// ============================================
// Aggregate counts for the summary tiles
// ============================================
$totalRows      = 0;
$countDraft     = 0;
$countFinalised = 0;
$countPass      = 0;
$countFail      = 0;

foreach ($rows as $r) {
    $totalRows++;
    if ((string)$r['status'] === 'finalised') {
        $countFinalised++;
    } else {
        $countDraft++;
    }
    if ((int)$r['is_pass'] === 1) {
        $countPass++;
    } else {
        $countFail++;
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

$pageTitle = 'Student Behaviour - EduTrack';

function conductPill(string $grade): string
{
    return [
        'Excellent' => 'green',
        'Very Good' => 'teal',
        'Good'      => 'blue',
        'Fair'      => 'orange',
        'Poor'      => 'red',
    ][$grade] ?? 'gray';
}

function behaviourStatusPill(string $st): string
{
    return ['draft' => 'orange', 'finalised' => 'green'][$st] ?? 'gray';
}

function behaviourStatusLabel(string $st): string
{
    return ucfirst($st);
}

function fmt2($v): string
{
    return number_format((float)$v, 2);
}

function prettyDate(?string $sqlDate): string
{
    if (!$sqlDate) return '—';
    try {
        return (new DateTime($sqlDate))->format('M j, Y');
    } catch (Exception $e) {
        return (string)$sqlDate;
    }
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

        .behaviour-list {
            display: flex;
            flex-direction: column;
        }

        .behaviour-card {
            padding: 18px 22px;
            border-bottom: 1px solid #f0f2f5;
        }

        .behaviour-card:last-child {
            border-bottom: none;
        }

        .behaviour-card .bc-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .behaviour-card .bc-title {
            font-weight: 700;
            font-size: 16px;
            color: #1a1a2e;
        }

        .behaviour-card .bc-subtitle {
            font-size: 12px;
            color: #6c757d;
            margin-top: 2px;
        }

        .behaviour-card .bc-head-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .behaviour-card .bc-score {
            font-family: 'Courier New', monospace;
            font-weight: 800;
            font-size: 28px;
            color: #1a1a2e;
            line-height: 1;
        }

        .behaviour-card .bc-score-max {
            font-size: 12px;
            color: #6c757d;
            font-weight: 500;
            font-family: 'Inter', sans-serif;
        }

        .behaviour-card .bc-body {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
        }

        .bc-cell {
            background: #fafbfc;
            border: 1px solid #eef0f3;
            border-radius: 10px;
            padding: 10px 12px;
        }

        .bc-cell .bc-lbl {
            font-size: 10px;
            text-transform: uppercase;
            color: #6c757d;
            font-weight: 600;
            letter-spacing: 0.4px;
        }

        .bc-cell .bc-val {
            font-size: 14px;
            font-weight: 600;
            color: #1a1a2e;
            font-family: 'Courier New', monospace;
            margin-top: 2px;
        }

        .bc-cell .bc-val.small {
            font-family: 'Inter', sans-serif;
            font-size: 12px;
            font-weight: 500;
            color: #495057;
        }

        .bc-cell.incidents .bc-val {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: flex-start;
        }

        .bc-cell.incidents .bc-val .inc {
            display: inline-block;
            padding: 1px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-family: 'Inter', sans-serif;
        }

        .bc-cell.incidents .inc.minor {
            background: #cce5ff;
            color: #004085;
        }

        .bc-cell.incidents .inc.moderate {
            background: #ffe8d9;
            color: #c2410c;
        }

        .bc-cell.incidents .inc.major {
            background: #f8d7da;
            color: #721c24;
        }

        .bc-note {
            margin-top: 12px;
            font-size: 12px;
            color: #6c757d;
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

            .behaviour-card .bc-score {
                font-size: 22px;
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
                    <div class="tname">Behaviour</div>
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
                            <?php echo h_g($termName); ?> — <?php echo h_g((string)$totalRows); ?> record(s)
                        <?php else: ?>
                            All terms — <?php echo h_g((string)$totalRows); ?> record(s)
                        <?php endif; ?>
                    </div>
                </div>
                <div class="fs-actions">
                    <?php if ($termName !== ''): ?>
                        <a href="/platform/student/behaviour.php?student_id=<?php echo (int)$studentId; ?>">
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
                            <div class="s-lbl">Records</div>
                            <div class="s-val"><?php echo h_g((string)$totalRows); ?></div>
                        </div>
                        <div class="summary-cell orange">
                            <div class="s-lbl">Draft</div>
                            <div class="s-val"><?php echo h_g((string)$countDraft); ?></div>
                        </div>
                        <div class="summary-cell green">
                            <div class="s-lbl">Finalised</div>
                            <div class="s-val"><?php echo h_g((string)$countFinalised); ?></div>
                        </div>
                        <div class="summary-cell green">
                            <div class="s-lbl">Pass</div>
                            <div class="s-val"><?php echo h_g((string)$countPass); ?></div>
                        </div>
                        <div class="summary-cell red">
                            <div class="s-lbl">Fail</div>
                            <div class="s-val"><?php echo h_g((string)$countFail); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-custom">
                <div class="card-header-custom">
                    <h6><i class="fas fa-star-half-alt me-2 text-primary"></i>Conduct Records</h6>
                    <span style="font-size:12px;color:#6c757d;"><?php echo h_g((string)$totalRows); ?> record(s)</span>
                </div>
                <div class="card-body-custom" style="padding:0;">
                    <?php if (empty($rows)): ?>
                        <div class="empty-state">
                            <i class="fas fa-star-half-alt"></i>
                            <h5>No behaviour records yet</h5>
                            <p>
                                <?php if ($termName !== ''): ?>
                                    The class teacher has not generated a behaviour record for this student in <?php echo h_g($termName); ?>.
                                <?php else: ?>
                                    The class teacher has not generated a behaviour record for this student yet.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="behaviour-list">
                            <?php foreach ($rows as $r):
                                $termNm   = (string)($r['term_name'] ?? '');
                                $yearNm   = (string)($r['year_name'] ?? '');
                                $isCur    = (int)($r['term_is_current'] ?? 0) === 1;
                                $final    = (float)$r['final_score'];
                                $grade    = (string)$r['final_grade'];
                                $st       = (string)$r['status'];
                                $isPass   = (int)$r['is_pass'] === 1;
                                $incMin   = (int)$r['incidents_minor'];
                                $incMod   = (int)$r['incidents_moderate'];
                                $incMaj   = (int)$r['incidents_major'];
                                $discDed  = (float)$r['discipline_deduction'];
                                $absCnt   = (int)$r['absence_count'];
                                $lateCnt  = (int)$r['late_count'];
                                $attDed   = (float)$r['attendance_deduction'];
                                $chkBonus = (float)$r['checklist_bonus'];
                            ?>
                                <div class="behaviour-card">
                                    <div class="bc-head">
                                        <div>
                                            <div class="bc-title">
                                                <?php echo h_g($termNm !== '' ? $termNm : 'Term #' . (int)$r['academic_term_id']); ?>
                                                <?php if ($isCur): ?>
                                                    <span class="pill blue" style="margin-left:6px;font-size:10px;">Current</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="bc-subtitle">
                                                <?php if ($yearNm !== ''): ?><?php echo h_g($yearNm); ?> · <?php endif; ?>
                                            Generated <?php echo h_g(prettyDate($r['generated_at'])); ?>
                                            <?php if ((string)$st === 'finalised' && !empty($r['finalised_at'])): ?>
                                                · Finalised <?php echo h_g(prettyDate($r['finalised_at'])); ?>
                                            <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="bc-head-right">
                                            <div>
                                                <span class="bc-score"><?php echo h_g(fmt2($final)); ?></span>
                                                <span class="bc-score-max">/ 100</span>
                                            </div>
                                            <span class="pill <?php echo conductPill($grade); ?>">
                                                <?php echo h_g($grade); ?>
                                            </span>
                                            <?php if ($isPass): ?>
                                                <span class="pill green">Pass</span>
                                            <?php else: ?>
                                                <span class="pill red">Fail</span>
                                            <?php endif; ?>
                                            <span class="pill <?php echo behaviourStatusPill($st); ?>">
                                                <?php echo h_g(behaviourStatusLabel($st)); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="bc-body">
                                        <div class="bc-cell incidents">
                                            <div class="bc-lbl">Incidents</div>
                                            <div class="bc-val">
                                                <span class="inc minor" title="Minor"><?php echo h_g((string)$incMin); ?></span>
                                                <span class="inc moderate" title="Moderate"><?php echo h_g((string)$incMod); ?></span>
                                                <span class="inc major" title="Major"><?php echo h_g((string)$incMaj); ?></span>
                                            </div>
                                        </div>
                                        <div class="bc-cell">
                                            <div class="bc-lbl">Discipline deduction</div>
                                            <div class="bc-val">−<?php echo h_g(fmt2($discDed)); ?></div>
                                        </div>
                                        <div class="bc-cell">
                                            <div class="bc-lbl">Absences · Lates</div>
                                            <div class="bc-val small">
                                                <?php echo h_g((string)$absCnt); ?> abs · <?php echo h_g((string)$lateCnt); ?> late
                                            </div>
                                        </div>
                                        <div class="bc-cell">
                                            <div class="bc-lbl">Attendance deduction</div>
                                            <div class="bc-val">−<?php echo h_g(fmt2($attDed)); ?></div>
                                        </div>
                                        <div class="bc-cell">
                                            <div class="bc-lbl">Checklist bonus</div>
                                            <div class="bc-val">+<?php echo h_g(fmt2($chkBonus)); ?></div>
                                        </div>
                                    </div>
                                    <?php if ((string)$st === 'draft'): ?>
                                        <div class="bc-note">
                                            <i class="fas fa-hourglass-half me-1"></i>
                                            This record is in draft. The class teacher may still change it before finalising.
                                        </div>
                                    <?php endif; ?>
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