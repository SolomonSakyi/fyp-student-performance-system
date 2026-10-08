<?php

/**
 * Student Record — read-only, per-student historical view
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Students
 * @version 1.0
 * @filepath public/platform/tenant/students/record.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Students record file of the students-surface sweep. Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - A v1.0 [SWEEP] entry was added above this docblock.
 *     - $currentPage changed from 'students_record' to 'students'
 *       so the partial marks Students active and renders the
 *       student sub-menu on this page — consistent with every
 *       other students file.
 *     - The inline <nav class="sidebar" id="sidebar"> block is
 *       removed and replaced by an include of
 *       app/views/partials/sidebar.php.
 *     - The CSS rule .nav-subgroup-label is added to this file's
 *       <style> block. The .nav-sub, .nav-sub .nav-link, and
 *       .nav-sub .nav-link.active rules, and the two responsive
 *       rules for .nav-sub, were already present in this file and
 *       are preserved unchanged.
 *   Every other line of the file is byte-identical to the
 *   previous version (1.0). The @package tag remains 'EduTrack'.
 *
 * Session S17h decisions:
 *   HR1A  page name: record.php
 *   HR2B  any staff may view; finance figures only for finance-flagged users
 *   HR3B  current year default, toggle to include previous years
 *   HR4A  single scrollable page with stacked cards
 *   HR5B  unified timeline at the bottom, ~20 most recent events
 *   HR6C  every available module contributes (results deferred to S17 re-visit)
 *   HR7C  richer summary tiles (finance tiles gated on is_finance)
 *   HR8A  full behaviour + discipline listings, with filters
 *   HR9B  finance: tiles + per-term mini-table + last 5 payments
 *   HR10A attendance: tiles + last 5 absences
 *   HR11A native print via @media print
 *   HR12B direct link only from students/index.php (for v1)
 *
 * This page is READ-ONLY. It contains no POST handlers and writes no audit
 * rows. Every query is scoped by tenant_id and deleted_at IS NULL.
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

$tenantId     = (int)($_SESSION['tenant_id'] ?? 0);
$userId       = (int)($_SESSION['user_id'] ?? 0);
$currentUser  = $_SESSION['user_name'] ?? 'Admin';
$userAvatar   = substr($currentUser, 0, 1);
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;
$isFinance    = $_SESSION['is_finance'] ?? false;
$canSeeFinance = $isFinance || $isSuperAdmin;

if ($tenantId <= 0) {
    $_SESSION['errors'] = ['No tenant context found.'];
    header('Location: /platform/tenants/select.php');
    exit;
}

$pageTitle   = 'Student Record - Student 360 Platform';
$currentPage = 'students';

// ============================================
// DATABASE
// ============================================
$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/config/config.php';
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// ============================================
// HELPERS
// ============================================
function h($v): string
{
    return htmlspecialchars((string)($v ?? ''));
}

function loadActiveSettings($db, int $tenantId): ?array
{
    $row = $db->fetchOne(
        "SELECT * FROM academic_settings
         WHERE tenant_id = ? AND status = 'active' AND deleted_at IS NULL
         ORDER BY version DESC, id DESC LIMIT 1",
        [$tenantId]
    );
    return $row ?: null;
}
function currentYearId($db, int $tenantId): int
{
    $row = $db->fetchOne(
        "SELECT id FROM academic_years
         WHERE tenant_id = ? AND is_current = 1 AND deleted_at IS NULL
         ORDER BY id DESC LIMIT 1",
        [$tenantId]
    );
    return $row ? (int)$row['id'] : 0;
}
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
function severityPill(string $sev): string
{
    return ['minor' => 'blue', 'moderate' => 'orange', 'major' => 'red'][$sev] ?? 'gray';
}
function disciplineStatusPill(string $st): string
{
    return ['recorded' => 'orange', 'reviewed' => 'blue', 'resolved' => 'green'][$st] ?? 'gray';
}
function behaviourStatusPill(string $st): string
{
    return ['draft' => 'orange', 'finalised' => 'green'][$st] ?? 'gray';
}
function methodLabel(string $m): string
{
    return [
        'cash'          => 'Cash',
        'bank_transfer' => 'Bank transfer',
        'cheque'        => 'Cheque',
        'mobile_money'  => 'Mobile money',
        'other'         => 'Other',
    ][$m] ?? ucfirst($m);
}
function money($amount, string $currency): string
{
    return $currency . ' ' . number_format((float)$amount, 2);
}

/**
 * Format a relative time from an SQL datetime.
 */
function timeAgo(string $sqlDt): string
{
    $t = strtotime($sqlDt);
    if (!$t) return $sqlDt;
    $diff = time() - $t;
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' h ago';
    if ($diff < 604800) return floor($diff / 86400) . ' d ago';
    return date('M d, Y', $t);
}

/**
 * Merge multiple event arrays into a single timeline, sort by date desc, cap N.
 * Each event must be: ['at' => 'YYYY-MM-DD', 'when_iso' => 'YYYY-MM-DD HH:MM:SS',
 *                     'type' => 'discipline|finance|behaviour|attendance|biometric|enrollment',
 *                     'title' => '...', 'message' => '...', 'link' => '...' ]
 */
function buildTimeline(array $sets, int $limit = 20): array
{
    $all = [];
    foreach ($sets as $set) foreach ($set as $e) $all[] = $e;
    usort($all, function ($a, $b) {
        $ta = strtotime($a['when_iso'] ?? $a['at'] ?? '1970-01-01');
        $tb = strtotime($b['when_iso'] ?? $b['at'] ?? '1970-01-01');
        return $tb <=> $ta;
    });
    return array_slice($all, 0, $limit);
}

// ============================================
// LOAD PAGE DATA
// ============================================
$settings       = loadActiveSettings($db, $tenantId);
$labelAcademic  = $settings['label_academic_structure'] ?? 'Academic Year';
$labelTerm      = $settings['label_term'] ?? 'Term';
$labelClass     = $settings['label_class'] ?? 'Class';
$currency       = 'GHS';
if (is_array($settings) && !empty($settings['currency'])) {
    $currency = (string)$settings['currency'];
} elseif (is_array($settings) && !empty($settings['currency_code'])) {
    $currency = (string)$settings['currency_code'];
}

$studentId    = (int)($_GET['student_id'] ?? 0);
$showAllYears = !empty($_GET['show_all_years']);

// Current year id
$currentYearId = currentYearId($db, $tenantId);

// Years + terms for filters
$years = $db->fetchAll(
    "SELECT id, year_name, is_current FROM academic_years
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY is_current DESC, start_date DESC, id DESC",
    [$tenantId]
);
$terms = $db->fetchAll(
    "SELECT id, term_name, is_current, sort_order FROM academic_terms
     WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL
     ORDER BY is_current DESC, sort_order ASC, id ASC",
    [$tenantId]
);

// Student
$student = null;
if ($studentId > 0) {
    $student = $db->fetchOne(
        "SELECT s.*, c.class_name, c.class_code,
                al.level_name, al.level_code,
                st.stream_name,
                ay.year_name AS admission_year_name
         FROM students s
         LEFT JOIN classes c ON s.class_id = c.id
         LEFT JOIN academic_levels al ON c.school_id = al.school_id
         LEFT JOIN streams st ON s.stream_id = st.id
         LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
         WHERE s.id = ? AND s.tenant_id = ? AND s.deleted_at IS NULL",
        [$studentId, $tenantId]
    );
}

// Tenant name (for the sidebar)
$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

// ============================================
// DATA LOADING — only when a student is selected
// ============================================
$discipline     = [];
$behaviour      = [];
$charges        = [];
$payments       = [];
$attendance     = [];
$lastAbsences   = [];
$biometric      = null;
$lastBioEvent   = null;
$enrollments    = [];

$summary = [
    'incidents_ytd'      => 0,
    'conduct_score'      => null,
    'conduct_grade'      => null,
    'conduct_status'     => null,
    'attendance_present' => 0,
    'attendance_absent'  => 0,
    'attendance_late'    => 0,
    'attendance_pct'     => null,
    'total_charged'      => 0,
    'total_paid'         => 0,
    'balance'            => 0,
    'bio_registered'     => false,
];

$timeline = [];

if ($student) {
    // ---------------------------------------------
    // Discipline
    // ---------------------------------------------
    $dWhere  = ["d.tenant_id = ?", "d.student_id = ?", "d.deleted_at IS NULL"];
    $dParams = [$tenantId, $studentId];
    if (!$showAllYears && $currentYearId > 0) {
        $dWhere[]  = "d.academic_year_id = ?";
        $dParams[] = $currentYearId;
    }
    $discipline = $db->fetchAll(
        "SELECT d.*, c.category_name, c.category_code, ay.year_name, t.term_name
         FROM student_discipline d
         LEFT JOIN discipline_categories c ON d.category_id = c.id
         LEFT JOIN academic_years ay ON d.academic_year_id = ay.id
         LEFT JOIN academic_terms t ON d.academic_term_id = t.id
         WHERE " . implode(' AND ', $dWhere) . "
         ORDER BY d.incident_date DESC, d.id DESC
         LIMIT 100",
        $dParams
    );
    foreach ($discipline as $r) {
        if ($showAllYears || (int)$r['academic_year_id'] === $currentYearId) {
            $summary['incidents_ytd']++;
        }
    }

    // ---------------------------------------------
    // Behaviour
    // ---------------------------------------------
    $bWhere  = ["b.tenant_id = ?", "b.student_id = ?", "b.deleted_at IS NULL"];
    $bParams = [$tenantId, $studentId];
    if (!$showAllYears && $currentYearId > 0) {
        $bWhere[]  = "b.academic_year_id = ?";
        $bParams[] = $currentYearId;
    }
    $behaviour = $db->fetchAll(
        "SELECT b.*, ay.year_name, t.term_name
         FROM student_behaviour b
         LEFT JOIN academic_years ay ON b.academic_year_id = ay.id
         LEFT JOIN academic_terms t ON b.academic_term_id = t.id
         WHERE " . implode(' AND ', $bWhere) . "
         ORDER BY b.academic_year_id DESC, b.academic_term_id DESC, b.id DESC
         LIMIT 50",
        $bParams
    );
    // Current-year conduct summary: take the latest finalised row (or latest row if none finalised)
    $pick = null;
    foreach ($behaviour as $b) {
        if ($b['status'] === 'finalised') {
            $pick = $b;
            break;
        }
    }
    if (!$pick && !empty($behaviour)) $pick = $behaviour[0];
    if ($pick) {
        $summary['conduct_score']  = (float)$pick['final_score'];
        $summary['conduct_grade']  = (string)$pick['final_grade'];
        $summary['conduct_status'] = (string)$pick['status'];
    }

    // ---------------------------------------------
    // Finance (only if allowed)
    // ---------------------------------------------
    if ($canSeeFinance) {
        $cWhere  = ["ch.tenant_id = ?", "ch.student_id = ?", "ch.deleted_at IS NULL"];
        $cParams = [$tenantId, $studentId];
        if (!$showAllYears && $currentYearId > 0) {
            $cWhere[]  = "ch.academic_year_id = ?";
            $cParams[] = $currentYearId;
        }
        $charges = $db->fetchAll(
            "SELECT ch.*, fc.category_name, ay.year_name, t.term_name
             FROM student_fee_charges ch
             LEFT JOIN fee_categories fc ON ch.category_id = fc.id
             LEFT JOIN academic_years ay ON ch.academic_year_id = ay.id
             LEFT JOIN academic_terms t ON ch.academic_term_id = t.id
             WHERE " . implode(' AND ', $cWhere) . "
             ORDER BY ch.academic_year_id DESC, ch.academic_term_id DESC, ch.id DESC
             LIMIT 100",
            $cParams
        );
        $pWhere  = ["p.tenant_id = ?", "p.student_id = ?", "p.deleted_at IS NULL"];
        $pParams = [$tenantId, $studentId];
        if (!$showAllYears && $currentYearId > 0) {
            $pWhere[]  = "p.academic_year_id = ?";
            $pParams[] = $currentYearId;
        }
        $payments = $db->fetchAll(
            "SELECT p.*, ay.year_name, t.term_name
             FROM student_fee_payments p
             LEFT JOIN academic_years ay ON p.academic_year_id = ay.id
             LEFT JOIN academic_terms t ON p.academic_term_id = t.id
             WHERE " . implode(' AND ', $pWhere) . "
             ORDER BY p.paid_at DESC, p.id DESC
             LIMIT 100",
            $pParams
        );
        foreach ($charges as $c)  $summary['total_charged'] += (float)$c['amount'];
        foreach ($payments as $p) $summary['total_paid']    += (float)$p['amount'];
        $summary['balance'] = $summary['total_charged'] - $summary['total_paid'];
    }

    // ---------------------------------------------
    // Attendance (best-effort — table may not exist yet)
    // ---------------------------------------------
    try {
        $aWhere  = ["tenant_id = ?", "student_id = ?", "deleted_at IS NULL"];
        $aParams = [$tenantId, $studentId];
        if (!$showAllYears && $currentYearId > 0) {
            // Prefer academic_year_id filter if the column exists; otherwise skip.
            // We attempt to add the filter and catch failure silently.
            $aWhere[]  = "academic_year_id = ?";
            $aParams[] = $currentYearId;
        }
        $where = implode(' AND ', $aWhere);
        $attendance = $db->fetchAll(
            "SELECT * FROM student_attendance WHERE $where ORDER BY attendance_date DESC LIMIT 100",
            $aParams
        );
        foreach ($attendance as $a) {
            $st = strtolower((string)($a['status'] ?? ''));
            if (in_array($st, ['present', 'present_full', 'p'], true))    $summary['attendance_present']++;
            elseif (in_array($st, ['absent', 'unexcused', 'absent_unexcused'], true)) $summary['attendance_absent']++;
            elseif (in_array($st, ['late', 'tardy'], true))              $summary['attendance_late']++;
        }
        $totalRecorded = $summary['attendance_present'] + $summary['attendance_absent'] + $summary['attendance_late'];
        if ($totalRecorded > 0) {
            $summary['attendance_pct'] = round(($summary['attendance_present'] / $totalRecorded) * 100, 1);
        }
        // Last 5 absences (unexcused + absent + late)
        foreach ($attendance as $a) {
            $st = strtolower((string)($a['status'] ?? ''));
            if (in_array($st, ['absent', 'unexcused', 'absent_unexcused', 'late', 'tardy'], true)) {
                $lastAbsences[] = $a;
                if (count($lastAbsences) >= 5) break;
            }
        }
    } catch (Exception $e) {
        $attendance = [];
        $lastAbsences = [];
    }

    // ---------------------------------------------
    // Biometric (best-effort)
    // ---------------------------------------------
    try {
        $biometric = $db->fetchOne(
            "SELECT * FROM student_biometric_registrations
             WHERE tenant_id = ? AND student_id = ? AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
            [$tenantId, $studentId]
        );
        if ($biometric && !empty($biometric['id'])) {
            $summary['bio_registered'] = true;
        }
        $lastBioEvent = $db->fetchOne(
            "SELECT * FROM biometric_events
             WHERE tenant_id = ? AND student_id = ?
             ORDER BY occurred_at DESC, id DESC LIMIT 1",
            [$tenantId, $studentId]
        );
    } catch (Exception $e) {
        $biometric = null;
        $lastBioEvent = null;
    }

    // ---------------------------------------------
    // Enrollment history (best-effort)
    // ---------------------------------------------
    try {
        $enrollments = $db->fetchAll(
            "SELECT e.*, co.academic_year_id, co.class_id,
                    ay.year_name, c.class_name, c.class_code,
                    al.level_name
             FROM enrollments e
             LEFT JOIN class_offerings co ON e.class_offering_id = co.id
             LEFT JOIN academic_years ay ON e.academic_year_id = ay.id
             LEFT JOIN classes c ON co.class_id = c.id
             LEFT JOIN academic_levels al ON co.academic_level_id = al.id
             WHERE e.tenant_id = ? AND e.student_id = ? AND e.deleted_at IS NULL
             ORDER BY e.enrolled_at DESC, e.id DESC
             LIMIT 20",
            [$tenantId, $studentId]
        );
    } catch (Exception $e) {
        $enrollments = [];
    }

    // ---------------------------------------------
    // Timeline: gather events from every module
    // ---------------------------------------------
    $discEvents = [];
    foreach ($discipline as $r) {
        $discEvents[] = [
            'at'       => $r['incident_date'],
            'when_iso' => $r['incident_date'] . ' 12:00:00',
            'type'     => 'discipline',
            'title'    => 'Discipline — ' . ucfirst($r['severity']) . ': ' . ($r['category_name'] ?: 'incident'),
            'message'  => ($r['description'] ?: '') . ' · Status: ' . ucfirst($r['status']),
            'link'     => '/platform/tenant/students/discipline.php?action=edit&id=' . (int)$r['id'],
        ];
    }
    $behavEvents = [];
    foreach ($behaviour as $r) {
        $when = $r['finalised_at'] ?: $r['generated_at'] ?: $r['created_at'];
        $behavEvents[] = [
            'at'       => substr((string)$when, 0, 10),
            'when_iso' => (string)$when,
            'type'     => 'behaviour',
            'title'    => 'Conduct — ' . number_format((float)$r['final_score'], 1) . ' (' . $r['final_grade'] . ')',
            'message'  => ($r['term_name'] ?: '') . ' · ' . ucfirst($r['status']) . ' · ' . ((int)$r['is_pass'] === 1 ? 'Pass' : 'Fail'),
            'link'     => '/platform/tenant/students/behaviour.php?student_id=' . $studentId . '&term_id=' . (int)$r['academic_term_id'],
        ];
    }
    $financeEvents = [];
    if ($canSeeFinance) {
        foreach ($charges as $r) {
            $financeEvents[] = [
                'at'       => substr((string)$r['created_at'], 0, 10),
                'when_iso' => (string)$r['created_at'],
                'type'     => 'finance',
                'title'    => 'Charge — ' . money($r['amount'], $currency),
                'message'  => ($r['category_name'] ?: 'Fee') . (($r['description'] ?? '') !== '' ? ' · ' . $r['description'] : ''),
                'link'     => '/platform/tenant/students/financial-records.php?student_id=' . $studentId . '&tab=charges',
            ];
        }
        foreach ($payments as $r) {
            $financeEvents[] = [
                'at'       => $r['paid_at'],
                'when_iso' => $r['paid_at'] . ' 12:00:00',
                'type'     => 'finance',
                'title'    => 'Payment — ' . money($r['amount'], $currency),
                'message'  => methodLabel((string)$r['method']) . ($r['receipt_number'] ? ' · Receipt ' . $r['receipt_number'] : ''),
                'link'     => '/platform/tenant/students/financial-records.php?student_id=' . $studentId . '&tab=payments',
            ];
        }
    }
    $attEvents = [];
    foreach ($attendance as $r) {
        $st = strtolower((string)($r['status'] ?? ''));
        if (in_array($st, ['absent', 'unexcused', 'absent_unexcused', 'late', 'tardy'], true)) {
            $attEvents[] = [
                'at'       => $r['attendance_date'],
                'when_iso' => $r['attendance_date'] . ' 08:00:00',
                'type'     => 'attendance',
                'title'    => 'Attendance — ' . ucfirst($st),
                'message'  => $r['notes'] ?? '',
                'link'     => '/platform/tenant/students/attendance.php?student_id=' . $studentId,
            ];
        }
    }
    $bioEvents = [];
    if ($lastBioEvent) {
        $bioEvents[] = [
            'at'       => substr((string)($lastBioEvent['occurred_at'] ?? ''), 0, 10),
            'when_iso' => (string)($lastBioEvent['occurred_at'] ?? ''),
            'type'     => 'biometric',
            'title'    => 'Biometric — ' . ucfirst((string)($lastBioEvent['event_type'] ?? 'event')),
            'message'  => (string)($lastBioEvent['device_name'] ?? ''),
            'link'     => '/platform/tenant/students/biometric-events.php?student_id=' . $studentId,
        ];
    }
    $enrEvents = [];
    foreach ($enrollments as $r) {
        $when = $r['enrolled_at'] ?? $r['created_at'];
        $enrEvents[] = [
            'at'       => substr((string)$when, 0, 10),
            'when_iso' => (string)$when,
            'type'     => 'enrollment',
            'title'    => 'Enrolled — ' . ($r['class_name'] ?: 'class') . (!empty($r['year_name']) ? ' (' . $r['year_name'] . ')' : ''),
            'message'  => 'Status: ' . ucfirst((string)$r['status']),
            'link'     => '/platform/tenant/students/enrollments.php?student_id=' . $studentId,
        ];
    }

    $timeline = buildTimeline([$discEvents, $behavEvents, $financeEvents, $attEvents, $bioEvents, $enrEvents], 20);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo h($pageTitle); ?></title>
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

        .student-banner {
            background: #fff;
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 20px;
            border: 2px solid #4facfe;
            display: flex;
            align-items: center;
            gap: 18px;
            max-width: 1300px;
            margin-left: auto;
            margin-right: auto;
            flex-wrap: wrap;
        }

        .student-banner .avatar {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            flex-shrink: 0;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 700;
            font-size: 24px;
        }

        .student-banner .bio {
            flex: 1;
            min-width: 200px;
        }

        .student-banner .bio h2 {
            font-weight: 700;
            font-size: 22px;
            margin: 0;
            color: #1a1a2e;
        }

        .student-banner .bio .meta {
            font-size: 12px;
            color: #6c757d;
            font-family: 'Courier New', monospace;
            margin-top: 4px;
        }

        .student-banner .badges {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
            max-width: 1300px;
            margin-left: auto;
            margin-right: auto;
        }

        .summary-cell {
            background: #fff;
            border-radius: 12px;
            padding: 14px 18px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .summary-cell .s-lbl {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .summary-cell .s-val {
            font-weight: 700;
            font-size: 20px;
            color: #1a1a2e;
            font-family: 'Courier New', monospace;
            margin-top: 4px;
        }

        .summary-cell.positive .s-val {
            color: #166534;
        }

        .summary-cell.negative .s-val {
            color: #991b1b;
        }

        .summary-cell.neutral .s-val {
            color: #0d6efd;
        }

        .record-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            margin-bottom: 20px;
            overflow: hidden;
            max-width: 1300px;
            margin-left: auto;
            margin-right: auto;
        }

        .record-card .card-head {
            padding: 14px 22px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            background: linear-gradient(135deg, #f8fafc 0%, #eef6ff 100%);
        }

        .record-card .card-head h5 {
            font-weight: 700;
            margin: 0;
            font-size: 16px;
            color: #1a1a2e;
        }

        .record-card .card-body {
            padding: 0;
        }

        .record-card .empty-card {
            padding: 40px 20px;
            text-align: center;
            color: #6c757d;
        }

        .record-card .empty-card i {
            font-size: 40px;
            opacity: 0.3;
            display: block;
            margin-bottom: 12px;
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

        .pill.gray {
            background: #e9ecef;
            color: #495057;
        }

        .pill.blue {
            background: #cce5ff;
            color: #004085;
        }

        .pill.purple {
            background: #e8d5f5;
            color: #6f42c1;
        }

        .pill.orange {
            background: #ffe8d9;
            color: #c2410c;
        }

        .pill.red {
            background: #f8d7da;
            color: #721c24;
        }

        .pill.teal {
            background: #d1f2eb;
            color: #0d5c4a;
        }

        .timeline {
            position: relative;
            padding: 18px 22px 22px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 34px;
            top: 24px;
            bottom: 24px;
            width: 2px;
            background: #e9ecef;
        }

        .tl-item {
            position: relative;
            padding: 6px 0 14px 44px;
        }

        .tl-item .tl-icon {
            position: absolute;
            left: 12px;
            top: 6px;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 12px;
            box-shadow: 0 0 0 4px #fff;
        }

        .tl-item .tl-icon.discipline {
            background: #dc3545;
        }

        .tl-item .tl-icon.behaviour {
            background: #6f42c1;
        }

        .tl-item .tl-icon.finance {
            background: #28a745;
        }

        .tl-item .tl-icon.attendance {
            background: #fd7e14;
        }

        .tl-item .tl-icon.biometric {
            background: #0d6efd;
        }

        .tl-item .tl-icon.enrollment {
            background: #0dcaf0;
        }

        .tl-item .tl-title {
            font-weight: 600;
            font-size: 13px;
            color: #1a1a2e;
        }

        .tl-item .tl-msg {
            font-size: 12px;
            color: #6c757d;
            margin-top: 2px;
        }

        .tl-item .tl-when {
            font-size: 11px;
            color: #adb5bd;
            margin-top: 2px;
        }

        .search-panel {
            background: #fff;
            border-radius: 14px;
            padding: 24px;
            max-width: 620px;
            margin: 40px auto;
            text-align: center;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(0, 0, 0, 0.03);
        }

        .search-panel .search-avatar {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: #eef6ff;
            color: #4facfe;
            margin: 0 auto 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .search-panel h4 {
            font-weight: 700;
            font-size: 18px;
            margin-bottom: 4px;
        }

        .search-panel p {
            color: #6c757d;
            font-size: 13px;
            margin-bottom: 16px;
        }

        .student-search-wrap {
            position: relative;
            text-align: left;
        }

        .student-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 1200;
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            max-height: 260px;
            overflow-y: auto;
            display: none;
            margin-top: 4px;
        }

        .student-search-results.open {
            display: block;
        }

        .student-search-results .ssr-item {
            padding: 8px 12px;
            cursor: pointer;
            font-size: 13px;
            border-bottom: 1px solid #f6f2f5;
        }

        .student-search-results .ssr-item:last-child {
            border-bottom: none;
        }

        .student-search-results .ssr-item:hover,
        .student-search-results .ssr-item.active {
            background: #eef6ff;
        }

        .student-search-results .ssr-item .ssr-name {
            font-weight: 600;
            color: #1a1a2e;
        }

        .student-search-results .ssr-item .ssr-num {
            font-size: 11px;
            color: #6c757d;
            font-family: 'Courier New', monospace;
        }

        .student-search-results .ssr-empty {
            padding: 12px;
            font-size: 12px;
            color: #6c757d;
            text-align: center;
        }

        /* Print styles */
        @media print {

            .sidebar,
            .sidebar-toggle,
            .top-bar .header-actions,
            #et-bell-wrap,
            .no-print {
                display: none !important;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 0;
                background: #fff;
            }

            .record-card,
            .student-banner,
            .summary-grid {
                box-shadow: none !important;
                border: 1px solid #dee2e6 !important;
                page-break-inside: avoid;
            }

            body {
                background: #fff;
            }
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
        <div class="row g-0">
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>

            <!-- Sidebar (includes app/views/partials/sidebar.php) -->
            <?php include $projectRoot . '/app/views/partials/sidebar.php'; ?>

            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-id-card me-2"></i>Student Record</h1>
                        <p>Complete history — bio, academics, behaviour, attendance, finance</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/students/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Students
                        </a>
                        <?php if ($student): ?>
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                                <i class="fas fa-print me-2"></i> Print
                            </button>
                            <a href="/platform/tenant/students/record.php?show_all_years=<?php echo $showAllYears ? '0' : '1'; ?>&student_id=<?php echo (int)$studentId; ?>"
                                class="btn btn-outline-secondary">
                                <i class="fas fa-history me-2"></i> <?php echo $showAllYears ? 'Current year only' : 'Show previous years'; ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!$student): ?>
                    <!-- Pick a student -->
                    <div class="search-panel">
                        <div class="search-avatar"><i class="fas fa-search"></i></div>
                        <h4>Pick a student</h4>
                        <p>Search by name or student number to open their record.</p>
                        <div class="student-search-wrap">
                            <input type="text" class="form-control" id="studentSearchInput"
                                autocomplete="off" placeholder="Type name or student number…">
                            <div class="student-search-results" id="studentSearchResults"></div>
                        </div>
                    </div>
                <?php else: ?>
                    <?php
                    $fullName = trim(($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
                    if ($fullName === '') $fullName = 'Student #' . $studentId;
                    $initials = strtoupper(substr($student['first_name'] ?: 'S', 0, 1) . substr($student['last_name'] ?: '', 0, 1));
                    $statusLabel = (string)($student['enrollment_status'] ?? '');
                    if ($statusLabel === '') $statusLabel = ((int)($student['is_active'] ?? 1) === 1 ? 'Active' : 'Inactive');
                    ?>

                    <!-- Student banner -->
                    <div class="student-banner">
                        <div class="avatar"><?php echo h($initials ?: 'ST'); ?></div>
                        <div class="bio">
                            <h2><?php echo h($fullName); ?></h2>
                            <div class="meta">
                                <?php echo h($student['student_number'] ?? ''); ?>
                                <?php if (!empty($student['class_name'])): ?>
                                    · <?php echo h($student['class_name']); ?>
                                    <?php if (!empty($student['class_code'])): ?> (<?php echo h($student['class_code']); ?>)<?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (!empty($student['stream_name'])): ?>
                                        · Stream <?php echo h($student['stream_name']); ?>
                                    <?php endif; ?>
                            </div>
                            <div class="badges mt-2">
                                <?php
                                $statusClass = 'green';
                                if (in_array($statusLabel, ['Inactive', 'Suspended', 'Transferred'], true)) $statusClass = 'red';
                                elseif ($statusLabel === 'Graduated') $statusClass = 'blue';
                                ?>
                                <span class="pill <?php echo $statusClass; ?>"><?php echo h($statusLabel); ?></span>
                                <?php if (!empty($student['gender'])): ?>
                                    <span class="pill gray"><?php echo h($student['gender']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($student['date_of_birth'])): ?>
                                    <span class="pill gray">
                                        <i class="fas fa-birthday-cake"></i>
                                        <?php echo h(date('M d, Y', strtotime($student['date_of_birth']))); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-end" style="font-size:12px;color:#6c757d;">
                            <?php if (!empty($student['guardian_name'])): ?>
                                <div><i class="fas fa-user-shield me-1"></i><?php echo h($student['guardian_name']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($student['guardian_phone'])): ?>
                                <div><i class="fas fa-phone me-1"></i><?php echo h($student['guardian_phone']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($student['admission_date'])): ?>
                                <div><i class="fas fa-calendar-plus me-1"></i>Admitted <?php echo h(date('M d, Y', strtotime($student['admission_date']))); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Summary tiles -->
                    <div class="summary-grid">
                        <div class="summary-cell neutral">
                            <div class="s-lbl"><i class="fas fa-gavel"></i> Incidents <?php echo $showAllYears ? '(all)' : 'YTD'; ?></div>
                            <div class="s-val"><?php echo (int)$summary['incidents_ytd']; ?></div>
                        </div>
                        <div class="summary-cell <?php
                                                    $g = $summary['conduct_grade'];
                                                    if ($g === 'Excellent' || $g === 'Very Good') echo 'positive';
                                                    elseif ($g === 'Poor') echo 'negative';
                                                    else echo 'neutral';
                                                    ?>">
                            <div class="s-lbl"><i class="fas fa-star-half-alt"></i> Conduct</div>
                            <div class="s-val">
                                <?php echo $summary['conduct_score'] !== null
                                    ? number_format((float)$summary['conduct_score'], 1)
                                    : '—'; ?>
                            </div>
                            <?php if ($summary['conduct_grade']): ?>
                                <div style="font-size:11px;color:#6c757d;margin-top:2px;">
                                    <?php echo h($summary['conduct_grade']); ?>
                                    · <?php echo h(ucfirst((string)$summary['conduct_status'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="summary-cell neutral">
                            <div class="s-lbl"><i class="fas fa-calendar-check"></i> Attendance</div>
                            <div class="s-val">
                                <?php echo $summary['attendance_pct'] !== null
                                    ? number_format((float)$summary['attendance_pct'], 1) . '%'
                                    : '—'; ?>
                            </div>
                            <div style="font-size:11px;color:#6c757d;margin-top:2px;">
                                <?php echo (int)$summary['attendance_present']; ?> P ·
                                <?php echo (int)$summary['attendance_absent']; ?> A ·
                                <?php echo (int)$summary['attendance_late']; ?> L
                            </div>
                        </div>
                        <div class="summary-cell <?php echo $summary['bio_registered'] ? 'positive' : 'negative'; ?>">
                            <div class="s-lbl"><i class="fas fa-fingerprint"></i> Biometric</div>
                            <div class="s-val" style="font-size:16px;">
                                <?php echo $summary['bio_registered'] ? 'Registered' : 'Not registered'; ?>
                            </div>
                        </div>
                        <?php if ($canSeeFinance): ?>
                            <div class="summary-cell neutral">
                                <div class="s-lbl"><i class="fas fa-file-invoice-dollar"></i> Charged</div>
                                <div class="s-val"><?php echo h(money($summary['total_charged'], $currency)); ?></div>
                            </div>
                            <div class="summary-cell positive">
                                <div class="s-lbl"><i class="fas fa-receipt"></i> Paid</div>
                                <div class="s-val"><?php echo h(money($summary['total_paid'], $currency)); ?></div>
                            </div>
                            <div class="summary-cell <?php echo $summary['balance'] <= 0 ? 'positive' : 'negative'; ?>">
                                <div class="s-lbl"><i class="fas fa-balance-scale"></i> Balance</div>
                                <div class="s-val"><?php echo h(money($summary['balance'], $currency)); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Behaviour card -->
                    <div class="record-card">
                        <div class="card-head">
                            <h5><i class="fas fa-star-half-alt me-2 text-primary"></i>Behaviour / Conduct</h5>
                            <span class="pill gray"><?php echo count($behaviour); ?> record(s)</span>
                        </div>
                        <div class="card-body">
                            <?php if (empty($behaviour)): ?>
                                <div class="empty-card">
                                    <i class="fas fa-star-half-alt"></i>
                                    <div>No behaviour records yet.</div>
                                </div>
                            <?php else: ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Term</th>
                                            <th style="text-align:center;">Score</th>
                                            <th style="text-align:center;">Grade</th>
                                            <th style="text-align:center;">Status</th>
                                            <th style="text-align:center;">Pass/Fail</th>
                                            <th style="text-align:right;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($behaviour as $b): ?>
                                            <tr>
                                                <td>
                                                    <div style="font-weight:600;"><?php echo h($b['term_name'] ?: '—'); ?></div>
                                                    <div style="font-size:11px;color:#6c757d;"><?php echo h($b['year_name'] ?: ''); ?></div>
                                                </td>
                                                <td style="text-align:center;font-family:'Courier New',monospace;font-weight:700;">
                                                    <?php echo number_format((float)$b['final_score'], 1); ?>
                                                </td>
                                                <td style="text-align:center;">
                                                    <span class="pill <?php echo conductPill((string)$b['final_grade']); ?>">
                                                        <?php echo h($b['final_grade']); ?>
                                                    </span>
                                                </td>
                                                <td style="text-align:center;">
                                                    <span class="pill <?php echo behaviourStatusPill((string)$b['status']); ?>">
                                                        <?php echo h(ucfirst($b['status'])); ?>
                                                    </span>
                                                </td>
                                                <td style="text-align:center;">
                                                    <?php if ((int)$b['is_pass'] === 1): ?>
                                                        <span class="pill green">Pass</span>
                                                    <?php else: ?>
                                                        <span class="pill red">Fail</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="text-align:right;">
                                                    <a href="/platform/tenant/students/behaviour.php?term_id=<?php echo (int)$b['academic_term_id']; ?>&student_id=<?php echo (int)$studentId; ?>"
                                                        class="btn btn-outline-primary btn-sm" title="Open in Behaviour">
                                                        <i class="fas fa-external-link-alt"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Discipline card -->
                    <div class="record-card">
                        <div class="card-head">
                            <h5><i class="fas fa-gavel me-2 text-primary"></i>Discipline</h5>
                            <span class="pill gray"><?php echo count($discipline); ?> incident(s)</span>
                        </div>
                        <div class="card-body">
                            <?php if (empty($discipline)): ?>
                                <div class="empty-card">
                                    <i class="fas fa-gavel"></i>
                                    <div>No discipline incidents recorded.</div>
                                </div>
                            <?php else: ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th style="width:110px;">Date</th>
                                            <th>Category</th>
                                            <th style="text-align:center;">Severity</th>
                                            <th style="text-align:center;">Status</th>
                                            <th>Action</th>
                                            <th style="text-align:right;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($discipline as $d): ?>
                                            <tr>
                                                <td style="font-family:'Courier New',monospace;font-size:12px;">
                                                    <?php echo h($d['incident_date']); ?>
                                                </td>
                                                <td>
                                                    <span class="pill purple"><?php echo h($d['category_name'] ?: '—'); ?></span>
                                                </td>
                                                <td style="text-align:center;">
                                                    <span class="pill <?php echo severityPill((string)$d['severity']); ?>">
                                                        <?php echo h(ucfirst($d['severity'])); ?>
                                                    </span>
                                                </td>
                                                <td style="text-align:center;">
                                                    <span class="pill <?php echo disciplineStatusPill((string)$d['status']); ?>">
                                                        <?php echo h(ucfirst($d['status'])); ?>
                                                    </span>
                                                </td>
                                                <td style="font-size:12px;color:#6c757d;">
                                                    <?php echo h($d['action_taken'] ?: ($d['description'] ?: '—')); ?>
                                                </td>
                                                <td style="text-align:right;">
                                                    <a href="/platform/tenant/students/discipline.php?action=edit&id=<?php echo (int)$d['id']; ?>"
                                                        class="btn btn-outline-primary btn-sm" title="Open in Discipline">
                                                        <i class="fas fa-external-link-alt"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Attendance card -->
                    <div class="record-card">
                        <div class="card-head">
                            <h5><i class="fas fa-calendar-check me-2 text-primary"></i>Attendance</h5>
                            <a href="/platform/tenant/students/attendance.php?student_id=<?php echo (int)$studentId; ?>"
                                class="btn btn-outline-secondary btn-sm no-print">
                                <i class="fas fa-external-link-alt me-1"></i> Full log
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($attendance)): ?>
                                <div class="empty-card">
                                    <i class="fas fa-calendar-check"></i>
                                    <div>No attendance records available.</div>
                                </div>
                            <?php else: ?>
                                <div style="padding:14px 22px; border-bottom:1px solid #f0f2f5;
                                            display:flex; gap:24px; flex-wrap:wrap;">
                                    <div>
                                        <div style="font-size:11px;color:#6c757d;text-transform:uppercase;">Present</div>
                                        <div style="font-weight:700;font-size:18px;font-family:'Courier New',monospace;">
                                            <?php echo (int)$summary['attendance_present']; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div style="font-size:11px;color:#6c757d;text-transform:uppercase;">Absent</div>
                                        <div style="font-weight:700;font-size:18px;font-family:'Courier New',monospace;color:#991b1b;">
                                            <?php echo (int)$summary['attendance_absent']; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div style="font-size:11px;color:#6c757d;text-transform:uppercase;">Late</div>
                                        <div style="font-weight:700;font-size:18px;font-family:'Courier New',monospace;color:#c2410c;">
                                            <?php echo (int)$summary['attendance_late']; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div style="font-size:11px;color:#6c757d;text-transform:uppercase;">Rate</div>
                                        <div style="font-weight:700;font-size:18px;font-family:'Courier New',monospace;color:#166534;">
                                            <?php echo $summary['attendance_pct'] !== null
                                                ? number_format((float)$summary['attendance_pct'], 1) . '%'
                                                : '—'; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php if (!empty($lastAbsences)): ?>
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th style="width:140px;">Date</th>
                                                <th>Status</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($lastAbsences as $a): ?>
                                                <tr>
                                                    <td style="font-family:'Courier New',monospace;font-size:12px;">
                                                        <?php echo h($a['attendance_date']); ?>
                                                    </td>
                                                    <td>
                                                        <span class="pill <?php echo strtolower((string)$a['status']) === 'late' ? 'orange' : 'red'; ?>">
                                                            <?php echo h(ucfirst($a['status'])); ?>
                                                        </span>
                                                    </td>
                                                    <td style="font-size:12px;color:#6c757d;"><?php echo h($a['notes'] ?? '—'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Finance card (only if allowed) -->
                    <?php if ($canSeeFinance): ?>
                        <div class="record-card">
                            <div class="card-head">
                                <h5><i class="fas fa-money-bill-wave me-2 text-primary"></i>Finance</h5>
                                <a href="/platform/tenant/students/financial-records.php?student_id=<?php echo (int)$studentId; ?>"
                                    class="btn btn-outline-secondary btn-sm no-print">
                                    <i class="fas fa-external-link-alt me-1"></i> Full ledger
                                </a>
                            </div>
                            <div class="card-body">
                                <?php if (empty($charges) && empty($payments)): ?>
                                    <div class="empty-card">
                                        <i class="fas fa-money-bill-wave"></i>
                                        <div>No finance activity yet.</div>
                                    </div>
                                <?php else: ?>
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th style="width:140px;">Year / Term</th>
                                                <th style="text-align:right;">Charged</th>
                                                <th style="text-align:right;">Paid</th>
                                                <th style="text-align:right;">Balance</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            // Build per-term mini-table
                                            $perTerm = [];
                                            foreach ($charges as $c) {
                                                $key = ($c['year_name'] ?: '—') . '||' . ($c['term_name'] ?: '—');
                                                if (!isset($perTerm[$key])) $perTerm[$key] = ['charged' => 0, 'paid' => 0];
                                                $perTerm[$key]['charged'] += (float)$c['amount'];
                                            }
                                            foreach ($payments as $p) {
                                                $key = ($p['year_name'] ?: '—') . '||' . ($p['term_name'] ?: '—');
                                                if (!isset($perTerm[$key])) $perTerm[$key] = ['charged' => 0, 'paid' => 0];
                                                $perTerm[$key]['paid'] += (float)$p['amount'];
                                            }
                                            foreach ($perTerm as $key => $vals):
                                                list($yName, $tName) = explode('||', $key);
                                                $rowBal = $vals['charged'] - $vals['paid'];
                                            ?>
                                                <tr>
                                                    <td>
                                                        <div style="font-weight:600;"><?php echo h($tName); ?></div>
                                                        <div style="font-size:11px;color:#6c757d;"><?php echo h($yName); ?></div>
                                                    </td>
                                                    <td style="text-align:right;font-family:'Courier New',monospace;">
                                                        <?php echo h(money($vals['charged'], $currency)); ?>
                                                    </td>
                                                    <td style="text-align:right;font-family:'Courier New',monospace;">
                                                        <?php echo h(money($vals['paid'], $currency)); ?>
                                                    </td>
                                                    <td style="text-align:right;font-family:'Courier New',monospace;color:<?php echo $rowBal <= 0 ? '#166534' : '#991b1b'; ?>;font-weight:700;">
                                                        <?php echo h(money($rowBal, $currency)); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <?php if (!empty($payments)): ?>
                                        <div style="padding:16px 22px; border-top:1px solid #f0f2f5;">
                                            <h6 style="font-weight:700; font-size:13px; margin-bottom:8px;">Last 5 payments</h6>
                                            <table class="data-table">
                                                <thead>
                                                    <tr>
                                                        <th style="width:110px;">Paid on</th>
                                                        <th style="width:120px;">Method</th>
                                                        <th>Receipt</th>
                                                        <th style="text-align:right;">Amount</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach (array_slice($payments, 0, 5) as $p): ?>
                                                        <tr>
                                                            <td style="font-family:'Courier New',monospace;font-size:12px;">
                                                                <?php echo h($p['paid_at']); ?>
                                                            </td>
                                                            <td><span class="pill blue"><?php echo h(methodLabel((string)$p['method'])); ?></span></td>
                                                            <td style="font-size:12px;font-family:'Courier New',monospace;">
                                                                <?php echo h($p['receipt_number'] ?: '—'); ?>
                                                            </td>
                                                            <td style="text-align:right;font-family:'Courier New',monospace;font-weight:700;">
                                                                <?php echo h(money($p['amount'], $currency)); ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Biometric card -->
                    <div class="record-card">
                        <div class="card-head">
                            <h5><i class="fas fa-fingerprint me-2 text-primary"></i>Biometric</h5>
                            <a href="/platform/tenant/students/biometric-registration.php?student_id=<?php echo (int)$studentId; ?>"
                                class="btn btn-outline-secondary btn-sm no-print">
                                <i class="fas fa-external-link-alt me-1"></i> Manage
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (!$biometric && !$lastBioEvent): ?>
                                <div class="empty-card">
                                    <i class="fas fa-fingerprint"></i>
                                    <div>No biometric data on file.</div>
                                </div>
                            <?php else: ?>
                                <div style="padding:16px 22px; display:flex; gap:32px; flex-wrap:wrap;">
                                    <div>
                                        <div style="font-size:11px;color:#6c757d;text-transform:uppercase;">Status</div>
                                        <div style="font-weight:600;">
                                            <?php if ($summary['bio_registered']): ?>
                                                <span class="pill green">Registered</span>
                                            <?php else: ?>
                                                <span class="pill red">Not registered</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($lastBioEvent): ?>
                                        <div>
                                            <div style="font-size:11px;color:#6c757d;text-transform:uppercase;">Last event</div>
                                            <div style="font-weight:600;">
                                                <?php echo h(ucfirst((string)($lastBioEvent['event_type'] ?? 'event'))); ?>
                                            </div>
                                            <div style="font-size:11px;color:#6c757d;">
                                                <?php echo h(date('M d, Y h:i A', strtotime((string)$lastBioEvent['occurred_at']))); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Timeline card -->
                    <div class="record-card">
                        <div class="card-head">
                            <h5><i class="fas fa-stream me-2 text-primary"></i>Timeline</h5>
                            <span class="pill gray"><?php echo count($timeline); ?> event(s)</span>
                        </div>
                        <div class="card-body">
                            <?php if (empty($timeline)): ?>
                                <div class="empty-card">
                                    <i class="fas fa-stream"></i>
                                    <div>No timeline events yet.</div>
                                </div>
                            <?php else: ?>
                                <div class="timeline">
                                    <?php foreach ($timeline as $ev):
                                        $t = $ev['type'];
                                        $icon = 'fa-circle';
                                        if ($t === 'discipline')  $icon = 'fa-gavel';
                                        elseif ($t === 'behaviour') $icon = 'fa-star-half-alt';
                                        elseif ($t === 'finance')   $icon = 'fa-money-bill-wave';
                                        elseif ($t === 'attendance') $icon = 'fa-calendar-check';
                                        elseif ($t === 'biometric') $icon = 'fa-fingerprint';
                                        elseif ($t === 'enrollment') $icon = 'fa-user-plus';
                                    ?>
                                        <div class="tl-item">
                                            <div class="tl-icon <?php echo h($t); ?>"><i class="fas <?php echo h($icon); ?>"></i></div>
                                            <div class="tl-title"><?php echo h($ev['title']); ?></div>
                                            <?php if (!empty($ev['message'])): ?>
                                                <div class="tl-msg"><?php echo h($ev['message']); ?></div>
                                            <?php endif; ?>
                                            <div class="tl-when">
                                                <i class="fas fa-clock me-1"></i>
                                                <?php echo h(timeAgo((string)$ev['when_iso'])); ?>
                                                <?php if (!empty($ev['link'])): ?>
                                                    · <a href="<?php echo h($ev['link']); ?>">Open</a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/notifications-bell.js" defer></script>
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
            if (confirm('Are you sure you want to logout?')) window.location.href = '/platform/logout.php';
        }

        // Student search (only on the picker view)
        (function() {
            const input = document.getElementById('studentSearchInput');
            const resultsEl = document.getElementById('studentSearchResults');
            if (!input || !resultsEl) return;

            function escapeHtml(s) {
                if (s === null || s === undefined) return '';
                return String(s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }
            let lastQuery = '',
                lastItems = [],
                activeIdx = -1,
                debounce = null,
                abortCtrl = null,
                reqToken = 0;

            function close() {
                resultsEl.classList.remove('open');
                resultsEl.innerHTML = '';
                activeIdx = -1;
            }

            function open(items) {
                lastItems = items;
                if (!items.length) {
                    resultsEl.innerHTML = '<div class="ssr-empty">No students found</div>';
                    resultsEl.classList.add('open');
                    activeIdx = -1;
                    return;
                }
                resultsEl.innerHTML = items.map((it, i) =>
                    `<div class="ssr-item" data-idx="${i}" data-id="${it.id}">
                        <div class="ssr-name">${escapeHtml(it.name)}</div>
                        <div class="ssr-num">${escapeHtml(it.num)}</div>
                    </div>`).join('');
                resultsEl.classList.add('open');
                activeIdx = -1;
            }

            function highlight() {
                resultsEl.querySelectorAll('.ssr-item').forEach(el => el.classList.remove('active'));
                if (activeIdx >= 0) {
                    const el = resultsEl.querySelector(`.ssr-item[data-idx="${activeIdx}"]`);
                    if (el) {
                        el.classList.add('active');
                        el.scrollIntoView({
                            block: 'nearest'
                        });
                    }
                }
            }

            function pick(item) {
                window.location.href = '/platform/tenant/students/record.php?student_id=' + encodeURIComponent(item.id);
            }

            function fetchResults(q) {
                if (q === lastQuery) return;
                lastQuery = q;
                if (abortCtrl) abortCtrl.abort();
                abortCtrl = new AbortController();
                const myToken = ++reqToken;
                fetch('/platform/tenant/students/record.php?ajax_search_students=1&q=' + encodeURIComponent(q), {
                        signal: abortCtrl.signal,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (myToken !== reqToken) return;
                        if (!data || !data.ok) {
                            close();
                            return;
                        }
                        open(data.items || []);
                    })
                    .catch(err => {
                        if (err && err.name === 'AbortError') return;
                        close();
                    });
            }
            input.addEventListener('input', function() {
                const q = this.value.trim();
                if (debounce) clearTimeout(debounce);
                if (q.length < 2) {
                    close();
                    return;
                }
                debounce = setTimeout(() => fetchResults(q), 250);
            });
            input.addEventListener('keydown', function(e) {
                if (!resultsEl.classList.contains('open')) return;
                const count = lastItems.length;
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIdx = (activeIdx + 1) % count;
                    highlight();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIdx = (activeIdx - 1 + count) % count;
                    highlight();
                } else if (e.key === 'Enter') {
                    if (activeIdx >= 0 && lastItems[activeIdx]) {
                        e.preventDefault();
                        pick(lastItems[activeIdx]);
                    }
                } else if (e.key === 'Escape') {
                    close();
                }
            });
            resultsEl.addEventListener('mousedown', function(e) {
                const item = e.target.closest('.ssr-item');
                if (!item) return;
                e.preventDefault();
                const idx = parseInt(item.getAttribute('data-idx'), 10);
                if (lastItems[idx]) pick(lastItems[idx]);
            });
            document.addEventListener('click', function(e) {
                if (!input.contains(e.target) && !resultsEl.contains(e.target)) close();
            });
        })();

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