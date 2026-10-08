<?php

/**
 * Student Results — read-only per-term results view for a guardian,
 * with year/term filters and print/PDF actions.
 *
 * @package EduTrack
 * @subpackage Platform\Student
 * @version 1.1
 * @filepath public/platform/student/results.php
 *
 * WHAT THIS PAGE DOES:
 *   - Requires an authenticated guardian session.
 *   - Reads student_id from $_GET. Redirects to the guardian
 *     dashboard if missing or non-positive.
 *   - Calls guardian_can_view_student($studentId). On false,
 *     responds 404 (not 403).
 *   - Reads optional year_id and term_id from $_GET as filters.
 *   - Defaults the year to the current academic year (is_current = 1),
 *     falling back to the newest year by id. Defaults the term to
 *     "all terms of the selected year".
 *   - Loads the results rows for the student, joined to subjects,
 *     academic_terms and academic_years, filtered to status IN
 *     ('calculated', 'published'), and optionally filtered by year
 *     and term.
 *   - Renders a filter strip (year select, term select, reset link).
 *   - Renders a Print button and a Save-as-PDF button. Both call
 *     window.print(); the browser print dialog handles both.
 *   - Renders the results grouped by term, most recent first, with
 *     per-term summary tiles and a per-subject table.
 *   - Provides links back to the student overview and the guardian
 *     dashboard.
 *
 * AUTHENTICATION MODEL:
 *   Session keys read (written by login.php v2.4):
 *     $_SESSION['logged_in']   = true
 *     $_SESSION['user_type']   = 'guardian'
 *     $_SESSION['guardian_id'] = the guardians.id
 *     $_SESSION['user_id']     = the platform_users.id
 *     $_SESSION['tenant_id']   = the tenant
 *     $_SESSION['school_id']   = the school
 *
 * THE 404-NOT-403 RULE:
 *   Same rule as /platform/student/index.php.
 *
 * WHAT THIS PAGE DOES NOT DO:
 *   - It does not edit anything. No POST handlers, no CSRF, no audit.
 *   - It does not show draft, blocked, or archived results.
 *   - It does not compute position in class.
 *   - It does not compute a B.E.C.E. aggregate.
 *   - It does not generate a server-side PDF. The PDF button relies
 *     on the browser's "Save as PDF" printer. A server-side PDF is a
 *     separate milestone.
 *   - It does not touch any table other than students, results,
 *     subjects, academic_terms, academic_years.
 *
 * LOCKED DECISIONS (2026-10-03 / 2026-10-04):
 *   Q1   The guardian is the login.
 *   Q6   Two portal surfaces: /platform/guardian/ and
 *        /platform/student/.
 *
 * PAGE DECISIONS:
 *   (1a) Two <select> dropdowns (year, term), submit on change,
 *        server-side filter.
 *   (2c) Default to the current year, all terms of that year.
 *   (3a) Print button calls window.print() with a @media print block.
 *   (4a) PDF button calls window.print(); the browser saves as PDF.
 *   The previous decisions remain: (1c) group by term, (2b) show
 *   status IN ('calculated', 'published'), (3b) subject/raw/final/
 *   symbol/label/pass/status/remark columns, (4a-old) no chart.
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
$userId     = (int)($_SESSION['user_id']     ?? 0);
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
// Load the year list for the filter dropdown
// ============================================
$years = $db->fetchAll(
    "SELECT id, year_name, is_current
     FROM academic_years
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY is_current DESC, start_date DESC, id DESC",
    [$tenantId]
);

// ============================================
// Resolve the filter values
// (2c) Default: current year, all terms of that year
// ============================================
$filterYear = (int)($_GET['year_id'] ?? 0);
$filterTerm = (int)($_GET['term_id'] ?? 0);

if ($filterYear <= 0) {
    // Try is_current = 1 first.
    foreach ($years as $y) {
        if ((int)($y['is_current'] ?? 0) === 1) {
            $filterYear = (int)$y['id'];
            break;
        }
    }
    // Fall back to newest year by id (already ordered).
    if ($filterYear <= 0 && !empty($years)) {
        $filterYear = (int)$years[0]['id'];
    }
}

// Term list is scoped to the selected year when a year is selected.
$terms = [];
if ($filterYear > 0) {
    $terms = $db->fetchAll(
        "SELECT id, term_name, is_current, sort_order, academic_year_id
         FROM academic_terms
         WHERE tenant_id = ? AND deleted_at IS NULL
           AND academic_year_id = ?
         ORDER BY is_current DESC, sort_order ASC, id ASC",
        [$tenantId, $filterYear]
    );
} else {
    $terms = $db->fetchAll(
        "SELECT id, term_name, is_current, sort_order, academic_year_id
         FROM academic_terms
         WHERE tenant_id = ? AND deleted_at IS NULL
         ORDER BY is_current DESC, sort_order ASC, id ASC",
        [$tenantId]
    );
}

// If the caller asked for a term that does not belong to the resolved
// year, treat the term as "All terms of the year" for safety.
if ($filterTerm > 0) {
    $okTerm = false;
    foreach ($terms as $t) {
        if ((int)$t['id'] === $filterTerm) {
            $okTerm = true;
            break;
        }
    }
    if (!$okTerm) {
        $filterTerm = 0;
    }
}

// ============================================
// Load the results, joined to subjects, terms, years
// (2b) Only status IN ('calculated', 'published')
// (1c) All terms of the selected year, grouped by term
// ============================================
$where  = [
    'r.tenant_id = ?',
    'r.student_id = ?',
    'r.deleted_at IS NULL',
    "r.status IN ('calculated', 'published')",
];
$params = [$tenantId, $studentId];

if ($filterYear > 0) {
    $where[]  = 'r.academic_year_id = ?';
    $params[] = $filterYear;
}
if ($filterTerm > 0) {
    $where[]  = 'r.academic_term_id = ?';
    $params[] = $filterTerm;
}

$rows = $db->fetchAll(
    "SELECT r.id, r.academic_year_id, r.academic_term_id, r.subject_id,
            r.raw_score, r.final_score, r.grade_symbol, r.grade_label,
            r.remark, r.is_pass, r.status, r.published_at, r.calculated_at,
            s.subject_name, s.subject_code,
            t.term_name, t.sort_order AS term_sort,
            ay.year_name
     FROM results r
     LEFT JOIN subjects s ON s.id = r.subject_id AND s.deleted_at IS NULL
     LEFT JOIN academic_terms t ON t.id = r.academic_term_id AND t.deleted_at IS NULL
     LEFT JOIN academic_years ay ON ay.id = r.academic_year_id AND ay.deleted_at IS NULL
     WHERE " . implode(' AND ', $where) . "
     ORDER BY r.academic_year_id DESC, t.sort_order DESC, t.id DESC,
              s.subject_name ASC, r.id ASC",
    $params
);

// ============================================
// Group rows by term
// ============================================
$byTerm = [];
foreach ($rows as $row) {
    $key = (int)$row['academic_term_id'];
    if (!isset($byTerm[$key])) {
        $byTerm[$key] = [
            'term_id'      => $key,
            'term_name'    => (string)($row['term_name'] ?? ''),
            'year_id'      => (int)$row['academic_year_id'],
            'year_name'    => (string)($row['year_name'] ?? ''),
            'term_sort'    => (int)($row['term_sort'] ?? 0),
            'rows'         => [],
            'subjects'     => 0,
            'passed'       => 0,
            'failed'       => 0,
            'score_sum'    => 0.0,
            'score_count'  => 0,
        ];
    }
    $byTerm[$key]['rows'][] = $row;

    $byTerm[$key]['subjects']++;
    if ((int)$row['is_pass'] === 1) {
        $byTerm[$key]['passed']++;
    } else {
        $byTerm[$key]['failed']++;
    }
    $score = $row['final_score'] !== null
        ? (float)$row['final_score']
        : ($row['raw_score'] !== null ? (float)$row['raw_score'] : null);
    if ($score !== null) {
        $byTerm[$key]['score_sum'] += $score;
        $byTerm[$key]['score_count']++;
    }
}

foreach ($byTerm as $key => $term) {
    $byTerm[$key]['average'] = $term['score_count'] > 0
        ? round($term['score_sum'] / $term['score_count'], 2)
        : null;
}

$totalRows     = count($rows);
$totalTerms    = count($byTerm);
$totalPassed   = 0;
$totalFailed   = 0;
foreach ($byTerm as $term) {
    $totalPassed += $term['passed'];
    $totalFailed += $term['failed'];
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

// Labels for the filter strip
$filterYearLabel = '';
foreach ($years as $y) {
    if ((int)$y['id'] === $filterYear) {
        $filterYearLabel = (string)$y['year_name'];
        break;
    }
}
$filterTermLabel = '';
foreach ($terms as $t) {
    if ((int)$t['id'] === $filterTerm) {
        $filterTermLabel = (string)$t['term_name'];
        break;
    }
}

$pageTitle = 'Student Results - EduTrack';

function resultStatusPill(string $st): string
{
    return [
        'calculated' => 'teal',
        'published'  => 'green',
        'draft'      => 'orange',
        'blocked'    => 'red',
        'archived'   => 'gray',
    ][$st] ?? 'gray';
}
function resultStatusLabel(string $st): string
{
    return ucfirst($st);
}
function fmtScore($v): string
{
    if ($v === null || $v === '') return '—';
    return number_format((float)$v, 2);
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

        .btn-outline-primary {
            background: transparent;
            border: 1.5px solid #4facfe;
            color: #0d6efd;
            border-radius: 8px;
            padding: 6px 14px;
            font-size: 13px;
            text-decoration: none;
        }

        .btn-outline-primary:hover {
            background: #eef6ff;
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

        .filter-strip {
            background: #fff;
            border-radius: 14px;
            padding: 14px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .filter-strip .fs-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
            min-width: 180px;
        }

        .filter-strip .fs-group label {
            font-size: 12px;
            font-weight: 600;
            color: #1a1a2e;
        }

        .filter-strip .fs-group select {
            height: 38px;
            font-size: 13px;
            border-radius: 8px;
            border: 1.5px solid #e9ecef;
            padding: 4px 10px;
            background: #fff;
            color: #1a1a2e;
        }

        .filter-strip .fs-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex: 0 0 auto;
        }

        .overview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .overview-cell {
            background: #fff;
            border-radius: 12px;
            padding: 12px 16px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            text-align: center;
        }

        .overview-cell .oc-lbl {
            font-size: 11px;
            text-transform: uppercase;
            color: #6c757d;
            font-weight: 600;
            letter-spacing: 0.4px;
        }

        .overview-cell .oc-val {
            font-size: 22px;
            font-weight: 700;
            color: #1a1a2e;
            font-family: 'Courier New', monospace;
            margin-top: 4px;
        }

        .overview-cell.green .oc-val {
            color: #166534;
        }

        .overview-cell.red .oc-val {
            color: #991b1b;
        }

        .overview-cell.blue .oc-val {
            color: #004085;
        }

        .overview-cell.purple .oc-val {
            color: #6f42c1;
        }

        .term-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .term-card .tc-head {
            padding: 14px 20px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            background: linear-gradient(135deg, #f8fafc 0%, #eef6ff 100%);
        }

        .term-card .tc-head .tc-title {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .term-card .tc-head .tc-title h5 {
            font-weight: 700;
            font-size: 16px;
            color: #1a1a2e;
            margin: 0;
        }

        .term-card .tc-head .tc-title .tc-year {
            font-size: 12px;
            color: #6c757d;
        }

        .term-card .tc-head .tc-summary {
            display: flex;
            gap: 14px;
            align-items: center;
            font-size: 12px;
            color: #495057;
        }

        .term-card .tc-head .tc-summary strong {
            font-family: 'Courier New', monospace;
            color: #1a1a2e;
            font-size: 14px;
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
            padding: 60px 20px;
            color: #6c757d;
            background: #fff;
            border-radius: 14px;
            border: 1px solid rgba(0, 0, 0, 0.04);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .empty-state i {
            font-size: 48px;
            opacity: 0.3;
            display: block;
            margin-bottom: 14px;
        }

        .empty-state h5 {
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 6px;
            font-size: 15px;
        }

        .empty-state p {
            font-size: 13px;
            margin: 0;
        }

        /* ---- Print styles ---- */
        @media print {

            .top-bar,
            .filter-strip,
            .sh-term .tfilt,
            .no-print {
                display: none !important;
            }

            .content-area {
                padding: 0;
                max-width: 100%;
            }

            .student-hero {
                background: #fff !important;
                color: #1a1a2e !important;
                border: 1px solid #dee2e6 !important;
                box-shadow: none !important;
                padding: 12px 16px !important;
                margin-bottom: 12px !important;
            }

            .student-hero .sh-avatar {
                background: #eef6ff !important;
                color: #0d6efd !important;
                width: 44px !important;
                height: 44px !important;
                font-size: 16px !important;
            }

            .student-hero .sh-body h2 {
                font-size: 16px !important;
            }

            .term-card,
            .overview-cell {
                box-shadow: none !important;
                border: 1px solid #dee2e6 !important;
                page-break-inside: avoid;
                margin-bottom: 10px !important;
            }

            .term-card .tc-head {
                background: #fff !important;
                border-bottom: 1px solid #dee2e6 !important;
            }

            body {
                background: #fff !important;
            }
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

            .term-card .tc-head {
                flex-direction: column;
                align-items: flex-start;
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

            .filter-strip {
                flex-direction: column;
            }

            .filter-strip .fs-group {
                width: 100%;
            }

            .filter-strip .fs-actions {
                width: 100%;
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
                <button type="button" class="btn-outline-primary" onclick="printResults()">
                    <i class="fas fa-print me-1"></i> Print
                </button>
                <button type="button" class="btn-outline-primary" onclick="printResults()">
                    <i class="fas fa-file-pdf me-1"></i> Save as PDF
                </button>
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
                    <div class="tname">Results</div>
                    <div class="tfilt">
                        <?php echo h_g((string)$totalTerms); ?> term<?php echo $totalTerms === 1 ? '' : 's'; ?>
                        <?php if ($filterYearLabel !== ''): ?>
                            · <?php echo h_g($filterYearLabel); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <form method="GET" action="/platform/student/results.php" class="filter-strip no-print" id="resultsFilterForm">
                <input type="hidden" name="student_id" value="<?php echo (int)$studentId; ?>">

                <div class="fs-group">
                    <label for="filterYear"><i class="fas fa-calendar-alt me-1"></i>Academic year</label>
                    <select name="year_id" id="filterYear" onchange="this.form.submit()">
                        <option value="0" <?php echo $filterYear === 0 ? 'selected' : ''; ?>>All years</option>
                        <?php foreach ($years as $y): ?>
                            <option value="<?php echo (int)$y['id']; ?>" <?php echo $filterYear === (int)$y['id'] ? 'selected' : ''; ?>>
                                <?php echo h_g($y['year_name']); ?>
                                <?php if ((int)($y['is_current'] ?? 0) === 1): ?> ★<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="fs-group">
                    <label for="filterTerm"><i class="fas fa-clock me-1"></i>Term</label>
                    <select name="term_id" id="filterTerm" onchange="this.form.submit()">
                        <option value="0" <?php echo $filterTerm === 0 ? 'selected' : ''; ?>>All terms</option>
                        <?php foreach ($terms as $t): ?>
                            <option value="<?php echo (int)$t['id']; ?>" <?php echo $filterTerm === (int)$t['id'] ? 'selected' : ''; ?>>
                                <?php echo h_g($t['term_name']); ?>
                                <?php if ((int)($t['is_current'] ?? 0) === 1): ?> ★<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="fs-actions">
                    <a href="/platform/student/results.php?student_id=<?php echo (int)$studentId; ?>" class="btn-outline-secondary">
                        <i class="fas fa-times me-1"></i> Reset
                    </a>
                </div>
            </form>

            <?php if (empty($byTerm)): ?>
                <div class="empty-state">
                    <i class="fas fa-poll"></i>
                    <h5>No results for the selected filter</h5>
                    <p>No calculated or published results are available for this student in the selected year and term. Try a different year, or reset the filter.</p>
                </div>
            <?php else: ?>

                <div class="overview-grid">
                    <div class="overview-cell blue">
                        <div class="oc-lbl">Subjects scored</div>
                        <div class="oc-val"><?php echo h_g((string)$totalRows); ?></div>
                    </div>
                    <div class="overview-cell purple">
                        <div class="oc-lbl">Terms</div>
                        <div class="oc-val"><?php echo h_g((string)$totalTerms); ?></div>
                    </div>
                    <div class="overview-cell green">
                        <div class="oc-lbl">Passed</div>
                        <div class="oc-val"><?php echo h_g((string)$totalPassed); ?></div>
                    </div>
                    <div class="overview-cell red">
                        <div class="oc-lbl">Failed</div>
                        <div class="oc-val"><?php echo h_g((string)$totalFailed); ?></div>
                    </div>
                </div>

                <?php foreach ($byTerm as $term):
                    $rowsT = $term['rows'];
                    $termName = $term['term_name'] !== '' ? $term['term_name'] : 'Term #' . (int)$term['term_id'];
                    $yearName = $term['year_name'];
                ?>
                    <div class="term-card">
                        <div class="tc-head">
                            <div class="tc-title">
                                <i class="fas fa-clipboard-list" style="font-size:18px;color:#4facfe;"></i>
                                <h5><?php echo h_g($termName); ?></h5>
                                <?php if ($yearName !== ''): ?>
                                    <span class="tc-year"><?php echo h_g($yearName); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="tc-summary">
                                <span><i class="fas fa-book me-1"></i><strong><?php echo h_g((string)$term['subjects']); ?></strong> subjects</span>
                                <span class="pill green"><i class="fas fa-check"></i><?php echo h_g((string)$term['passed']); ?> pass</span>
                                <?php if ($term['failed'] > 0): ?>
                                    <span class="pill red"><i class="fas fa-times"></i><?php echo h_g((string)$term['failed']); ?> fail</span>
                                <?php endif; ?>
                                <?php if ($term['average'] !== null): ?>
                                    <span>Average <strong><?php echo h_g(number_format((float)$term['average'], 2)); ?></strong></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="min-width:200px;">Subject</th>
                                    <th style="width:110px;text-align:center;">Raw</th>
                                    <th style="width:110px;text-align:center;">Final</th>
                                    <th style="width:110px;text-align:center;">Grade</th>
                                    <th style="min-width:160px;">Remark</th>
                                    <th style="width:90px;text-align:center;">Result</th>
                                    <th style="width:110px;text-align:center;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rowsT as $r):
                                    $subName = (string)($r['subject_name'] ?? '');
                                    $subCode = (string)($r['subject_code'] ?? '');
                                    if ($subName === '') $subName = 'Subject #' . (int)$r['subject_id'];
                                    $symbol = (string)($r['grade_symbol'] ?? '');
                                    $label = (string)($r['grade_label'] ?? '');
                                    $remark = (string)($r['remark'] ?? '');
                                    $isPass = (int)$r['is_pass'] === 1;
                                    $st = (string)$r['status'];
                                ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight:600;color:#1a1a2e;"><?php echo h_g($subName); ?></div>
                                            <?php if ($subCode !== ''): ?>
                                                <div style="font-size:11px;color:#0d6efd;font-family:'Courier New',monospace;"><?php echo h_g($subCode); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center;font-family:'Courier New',monospace;"><?php echo h_g(fmtScore($r['raw_score'])); ?></td>
                                        <td style="text-align:center;font-family:'Courier New',monospace;font-weight:700;"><?php echo h_g(fmtScore($r['final_score'])); ?></td>
                                        <td style="text-align:center;">
                                            <?php if ($symbol !== ''): ?>
                                                <span class="pill <?php echo $isPass ? 'green' : 'red'; ?>"><?php echo h_g($symbol); ?></span>
                                                <?php if ($label !== ''): ?>
                                                    <div style="font-size:10px;color:#6c757d;margin-top:2px;"><?php echo h_g($label); ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color:#adb5bd;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:12px;color:#6c757d;">
                                            <?php echo $remark !== '' ? h_g($remark) : '—'; ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ($isPass): ?>
                                                <span class="pill green">Pass</span>
                                            <?php else: ?>
                                                <span class="pill red">Fail</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <span class="pill <?php echo resultStatusPill($st); ?>">
                                                <?php echo h_g(resultStatusLabel($st)); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>

            <?php endif; ?>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function printResults() {
            window.print();
        }
    </script>
</body>

</html>