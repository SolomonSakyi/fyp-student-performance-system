<?php

/**
 * Academic Dashboard — read-only aggregation across the academic module
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Academic
 * @version 1.0
 * @filepath public/platform/tenant/academic/dashboard.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Academic dashboard file of the academic-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'academic_dashboard'
 *       to 'academic' so the partial marks Academic active and
 *       renders the academic sub-menu on this page — consistent
 *       with how the students surface uses $currentPage.
 *     - A v1.0 [SWEEP] entry was added above this docblock.
 *     - The inline <nav class="sidebar" id="sidebar"> block is
 *       removed and replaced by an include of
 *       app/views/partials/sidebar.php.
 *     - The CSS rule .nav-subgroup-label is added to this file's
 *       <style> block. The .nav-sub, .nav-sub .nav-link, and
 *       .nav-sub .nav-link.active rules, and the two responsive
 *       rules for .nav-sub, were already present in this file and
 *       are preserved unchanged.
 *   Every other line of the file is byte-identical to the
 *   previous version (1.1). The @package tag remains 'EduTrack'.
 *
 * Session S19 decisions:
 *   DB1A  page lives at academic/dashboard.php
 *   DB2A  academic-module scope only
 *   DB3C  year + term filter, default current
 *   DB4B  8 summary tiles (finance gated, biometric shown)
 *   DB5B  pure-CSS sparklines for attendance + discipline over last 6 weeks
 *   DB6A  5 module cards with last 5 records each
 *   DB7A  alert banner at the top
 *   DB8B  finance + biometric gated on is_finance
 *   DB9A  print via @media print
 *   DB10A server-rendered, manual refresh
 *   DB11A sidebar entry at top of Academic section
 *
 * This page is READ-ONLY. No POST handlers, no transactions, no audit rows.
 * Every query is scoped by tenant_id and deleted_at IS NULL.
 *
 * Session S20 decisions applied:
 *   SH2A  no POST handlers on this page (read-only), so no CSRF token issue
 *   SH3A  central Security.php helper, loaded via app/bootstrap.php
 *   SH4A  hardened session started inside bootstrap
 *   SH5C  h() on every echoed value
 *   SH6B  tenant_id scoping and deleted_at IS NULL on every query
 *         — biometric_events is an append-only log with no deleted_at
 *           column, so it is deliberately excluded from this rule
 */

// ============================================
// S20 — Bootstrap (session, CSRF, headers, HTTPS, DB)
// ============================================
$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/app/bootstrap.php';
require_tenant();

$tenantId     = current_tenant_id();
$userId       = current_user_id();
$currentUser  = $_SESSION['user_name'] ?? 'Admin';
$userAvatar   = substr($currentUser, 0, 1);
$isSuperAdmin = is_super_admin();
$isFinance    = $_SESSION['is_finance'] ?? false;
$canSeeFinance = $isFinance || $isSuperAdmin;

$pageTitle   = 'Academic Dashboard - Student 360 Platform';
$currentPage = 'academic';

$db = DatabaseHelper::getInstance();

// ============================================
// HELPERS
// ============================================
// h() is defined in Security.php; do not redeclare it here.

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
function money($amount, string $currency): string
{
    return $currency . ' ' . number_format((float)$amount, 2);
}
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

function last6WeekStarts(): array
{
    $weeks = [];
    $monday = strtotime('monday this week');
    for ($i = 5; $i >= 0; $i--) {
        $weeks[] = [
            'start' => date('Y-m-d', $monday - ($i * 7 * 86400)),
            'end'   => date('Y-m-d', $monday - ($i * 7 * 86400) + (6 * 86400)),
            'label' => date('M d', $monday - ($i * 7 * 86400)),
        ];
    }
    return $weeks;
}

// ============================================
// LOAD PAGE DATA
// ============================================
$settings      = loadActiveSettings($db, $tenantId);
$labelAcademic = $settings['label_academic_structure'] ?? 'Academic Year';
$labelTerm     = $settings['label_term'] ?? 'Term';
$currency      = 'GHS';
if (is_array($settings) && !empty($settings['currency']))          $currency = (string)$settings['currency'];
elseif (is_array($settings) && !empty($settings['currency_code'])) $currency = (string)$settings['currency_code'];

$filterYear = (int)($_GET['year_id'] ?? 0);
$filterTerm = (int)($_GET['term_id'] ?? 0);

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

if ($filterYear <= 0) {
    foreach ($years as $y) {
        if ((int)$y['is_current'] === 1) {
            $filterYear = (int)$y['id'];
            break;
        }
    }
    if ($filterYear <= 0 && !empty($years)) $filterYear = (int)$years[0]['id'];
}
if ($filterTerm <= 0) {
    foreach ($terms as $t) {
        if ((int)$t['is_current'] === 1) {
            $filterTerm = (int)$t['id'];
            break;
        }
    }
    if ($filterTerm <= 0 && !empty($terms)) $filterTerm = (int)$terms[0]['id'];
}

$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

// ============================================
// TILE 1-2: Students
// ============================================
$totalStudents = (int)($db->getValue(
    "SELECT COUNT(*) FROM students WHERE tenant_id = ? AND deleted_at IS NULL",
    [$tenantId]
) ?? 0);

$activeStudents = (int)($db->getValue(
    "SELECT COUNT(*) FROM students WHERE tenant_id = ? AND deleted_at IS NULL AND is_active = 1",
    [$tenantId]
) ?? 0);

// ============================================
// TILE 3: Attendance rate (this term)
// ============================================
$attendanceRate = null;
$attPresent = $attAbsent = $attLate = 0;
try {
    $w = ["tenant_id = ?", "deleted_at IS NULL"];
    $p = [$tenantId];
    if ($filterTerm > 0) {
        $w[] = "academic_term_id = ?";
        $p[] = $filterTerm;
    }
    $row = $db->fetchOne(
        "SELECT
            SUM(CASE WHEN LOWER(status) IN ('present','present_full','p') THEN 1 ELSE 0 END) AS c_present,
            SUM(CASE WHEN LOWER(status) IN ('absent','unexcused','absent_unexcused') THEN 1 ELSE 0 END) AS c_absent,
            SUM(CASE WHEN LOWER(status) IN ('late','tardy') THEN 1 ELSE 0 END) AS c_late
         FROM student_attendance
         WHERE " . implode(' AND ', $w),
        $p
    );
    $attPresent = (int)($row['c_present'] ?? 0);
    $attAbsent  = (int)($row['c_absent']  ?? 0);
    $attLate    = (int)($row['c_late']    ?? 0);
    $totalRec   = $attPresent + $attAbsent + $attLate;
    if ($totalRec > 0) $attendanceRate = round(($attPresent / $totalRec) * 100, 1);
} catch (Exception $e) {
}

// ============================================
// TILE 4: Discipline incidents (this term)
// ============================================
$incidentsThisTerm = 0;
$majorIncidents    = 0;
try {
    $w = ["tenant_id = ?", "deleted_at IS NULL"];
    $p = [$tenantId];
    if ($filterTerm > 0) {
        $w[] = "academic_term_id = ?";
        $p[] = $filterTerm;
    }
    if ($filterYear > 0) {
        $w[] = "academic_year_id = ?";
        $p[] = $filterYear;
    }
    $row = $db->fetchOne(
        "SELECT COUNT(*) AS c,
                SUM(CASE WHEN severity = 'major' THEN 1 ELSE 0 END) AS major_c
         FROM student_discipline
         WHERE " . implode(' AND ', $w),
        $p
    );
    $incidentsThisTerm = (int)($row['c'] ?? 0);
    $majorIncidents    = (int)($row['major_c'] ?? 0);
} catch (Exception $e) {
}

// ============================================
// TILE 5: Conduct pass rate (this term)
// ============================================
$conductPassRate = null;
$conductPass = $conductFail = $conductTotal = 0;
try {
    $w = ["tenant_id = ?", "deleted_at IS NULL", "status = 'finalised'"];
    $p = [$tenantId];
    if ($filterTerm > 0) {
        $w[] = "academic_term_id = ?";
        $p[] = $filterTerm;
    }
    if ($filterYear > 0) {
        $w[] = "academic_year_id = ?";
        $p[] = $filterYear;
    }
    $row = $db->fetchOne(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN is_pass = 1 THEN 1 ELSE 0 END) AS pass_c,
                SUM(CASE WHEN is_pass = 0 THEN 1 ELSE 0 END) AS fail_c
         FROM student_behaviour
         WHERE " . implode(' AND ', $w),
        $p
    );
    $conductTotal = (int)($row['total']  ?? 0);
    $conductPass  = (int)($row['pass_c'] ?? 0);
    $conductFail  = (int)($row['fail_c'] ?? 0);
    if ($conductTotal > 0) $conductPassRate = round(($conductPass / $conductTotal) * 100, 1);
} catch (Exception $e) {
}

// ============================================
// TILE 6: Outstanding fees (finance only)
// ============================================
$outstandingFees = 0.0;
if ($canSeeFinance) {
    try {
        $w = ["tenant_id = ?", "deleted_at IS NULL"];
        $p = [$tenantId];
        if ($filterYear > 0) {
            $w[] = "academic_year_id = ?";
            $p[] = $filterYear;
        }
        if ($filterTerm > 0) {
            $w[] = "academic_term_id = ?";
            $p[] = $filterTerm;
        }
        $charged = (float)($db->getValue(
            "SELECT COALESCE(SUM(amount),0) FROM student_fee_charges WHERE " . implode(' AND ', $w),
            $p
        ) ?? 0);
        $paid = (float)($db->getValue(
            "SELECT COALESCE(SUM(amount),0) FROM student_fee_payments WHERE " . implode(' AND ', $w),
            $p
        ) ?? 0);
        $outstandingFees = max(0, $charged - $paid);
    } catch (Exception $e) {
        $outstandingFees = 0.0;
    }
}

// ============================================
// TILE 7: Biometric registered %
// ============================================
$bioRegisteredPct = null;
try {
    $registered = (int)($db->getValue(
        "SELECT COUNT(DISTINCT student_id) FROM student_biometric_registrations
         WHERE tenant_id = ? AND deleted_at IS NULL",
        [$tenantId]
    ) ?? 0);
    if ($totalStudents > 0) {
        $bioRegisteredPct = round(($registered / $totalStudents) * 100, 1);
    }
} catch (Exception $e) {
    $bioRegisteredPct = null;
}

// ============================================
// TILE 8: Unread notifications
// ============================================
$unreadNotifications = 0;
try {
    $unreadNotifications = (int)($db->getValue(
        "SELECT COUNT(*) FROM notifications
         WHERE user_id = ? AND tenant_id = ? AND is_read = 0
           AND deleted_at IS NULL AND recipient_role = 'staff'",
        [$userId, $tenantId]
    ) ?? 0);
} catch (Exception $e) {
    $unreadNotifications = 0;
}

// ============================================
// ALERT BANNER (DB7A)
// ============================================
$alerts = [];
try {
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $w = [
        "tenant_id = ?",
        "deleted_at IS NULL",
        "attendance_date >= ?",
        "LOWER(status) IN ('absent','unexcused','absent_unexcused')"
    ];
    $p = [$tenantId, $weekStart];
    $c = (int)($db->getValue("SELECT COUNT(*) FROM student_attendance WHERE " . implode(' AND ', $w), $p) ?? 0);
    if ($c > 0) $alerts[] = [
        'type' => 'attendance',
        'text' => $c . ' unexcused absence(s) this week.',
        'link' => '/platform/tenant/students/attendance.php'
    ];
} catch (Exception $e) {
}

if ($majorIncidents > 0) {
    $alerts[] = [
        'type' => 'discipline',
        'text' => $majorIncidents . ' major discipline incident(s) this term.',
        'link' => '/platform/tenant/students/discipline.php?severity=major'
    ];
}

if ($conductFail > 0) {
    $alerts[] = [
        'type' => 'behaviour',
        'text' => $conductFail . ' student(s) failing conduct this term.',
        'link' => '/platform/tenant/students/behaviour.php?status=finalised'
    ];
}

if ($canSeeFinance && $outstandingFees > 0) {
    $alerts[] = [
        'type' => 'finance',
        'text' => 'Outstanding fees: ' . money($outstandingFees, $currency) . '.',
        'link' => '/platform/tenant/students/financial-records.php'
    ];
}

if ($bioRegisteredPct !== null && $bioRegisteredPct < 80 && $totalStudents > 0) {
    $alerts[] = [
        'type' => 'biometric',
        'text' => 'Only ' . number_format($bioRegisteredPct, 1) . '% of students are biometrically registered.',
        'link' => '/platform/tenant/students/biometric-registration.php'
    ];
}

// ============================================
// SPARKLINES (DB5B)
// ============================================
$weeks = last6WeekStarts();
$attendanceSeries = [];
$disciplineSeries = [];
foreach ($weeks as $wk) {
    $rate = null;
    try {
        $row = $db->fetchOne(
            "SELECT
                SUM(CASE WHEN LOWER(status) IN ('present','present_full','p') THEN 1 ELSE 0 END) AS c_present,
                COUNT(*) AS c_total
             FROM student_attendance
             WHERE tenant_id = ? AND deleted_at IS NULL
               AND attendance_date BETWEEN ? AND ?",
            [$tenantId, $wk['start'], $wk['end']]
        );
        $tot = (int)($row['c_total'] ?? 0);
        $prs = (int)($row['c_present'] ?? 0);
        if ($tot > 0) $rate = round(($prs / $tot) * 100, 1);
    } catch (Exception $e) {
    }
    $attendanceSeries[] = ['label' => $wk['label'], 'value' => $rate];

    $cnt = 0;
    try {
        $cnt = (int)($db->getValue(
            "SELECT COUNT(*) FROM student_discipline
             WHERE tenant_id = ? AND deleted_at IS NULL
               AND incident_date BETWEEN ? AND ?",
            [$tenantId, $wk['start'], $wk['end']]
        ) ?? 0);
    } catch (Exception $e) {
    }
    $disciplineSeries[] = ['label' => $wk['label'], 'value' => $cnt];
}

$discMax = 1;
foreach ($disciplineSeries as $d) {
    if ($d['value'] > $discMax) $discMax = $d['value'];
}

// ============================================
// MODULE CARDS (DB6A)
// ============================================
$recentAttendance = [];
$recentDiscipline = [];
$recentBehaviour  = [];
$recentPayments   = [];
$recentBiometric  = [];

try {
    $w = ["a.tenant_id = ?", "a.deleted_at IS NULL"];
    $p = [$tenantId];
    if ($filterTerm > 0) {
        $w[] = "a.academic_term_id = ?";
        $p[] = $filterTerm;
    }
    $recentAttendance = $db->fetchAll(
        "SELECT a.*, s.first_name, s.middle_name, s.last_name, s.student_number
         FROM student_attendance a
         JOIN students s ON a.student_id = s.id
         WHERE " . implode(' AND ', $w) . "
         ORDER BY a.attendance_date DESC, a.id DESC LIMIT 5",
        $p
    );
} catch (Exception $e) {
}

try {
    $w = ["d.tenant_id = ?", "d.deleted_at IS NULL"];
    $p = [$tenantId];
    if ($filterTerm > 0) {
        $w[] = "d.academic_term_id = ?";
        $p[] = $filterTerm;
    }
    $recentDiscipline = $db->fetchAll(
        "SELECT d.*, s.first_name, s.middle_name, s.last_name, s.student_number,
                c.category_name
         FROM student_discipline d
         JOIN students s ON d.student_id = s.id
         LEFT JOIN discipline_categories c ON d.category_id = c.id
         WHERE " . implode(' AND ', $w) . "
         ORDER BY d.incident_date DESC, d.id DESC LIMIT 5",
        $p
    );
} catch (Exception $e) {
}

try {
    $w = ["b.tenant_id = ?", "b.deleted_at IS NULL"];
    $p = [$tenantId];
    if ($filterTerm > 0) {
        $w[] = "b.academic_term_id = ?";
        $p[] = $filterTerm;
    }
    $recentBehaviour = $db->fetchAll(
        "SELECT b.*, s.first_name, s.middle_name, s.last_name, s.student_number,
                t.term_name
         FROM student_behaviour b
         JOIN students s ON b.student_id = s.id
         LEFT JOIN academic_terms t ON b.academic_term_id = t.id
         WHERE " . implode(' AND ', $w) . "
         ORDER BY b.updated_at DESC, b.id DESC LIMIT 5",
        $p
    );
} catch (Exception $e) {
}

if ($canSeeFinance) {
    try {
        $w = ["p.tenant_id = ?", "p.deleted_at IS NULL"];
        $p = [$tenantId];
        if ($filterTerm > 0) {
            $w[] = "p.academic_term_id = ?";
            $p[] = $filterTerm;
        }
        $recentPayments = $db->fetchAll(
            "SELECT p.*, s.first_name, s.middle_name, s.last_name, s.student_number
             FROM student_fee_payments p
             JOIN students s ON p.student_id = s.id
             WHERE " . implode(' AND ', $w) . "
             ORDER BY p.paid_at DESC, p.id DESC LIMIT 5",
            $p
        );
    } catch (Exception $e) {
    }
}

try {
    // biometric_events is an append-only log with no deleted_at column.
    $recentBiometric = $db->fetchAll(
        "SELECT e.*, s.first_name, s.middle_name, s.last_name, s.student_number
         FROM biometric_events e
         JOIN students s ON e.student_id = s.id
         WHERE e.tenant_id = ?
         ORDER BY e.occurred_at DESC, e.id DESC LIMIT 5",
        [$tenantId]
    );
} catch (Exception $e) {
}

// ============================================
// Current year/term labels for the header
// ============================================
$currentYearLabel = '';
$currentTermLabel = '';
foreach ($years as $y) {
    if ((int)$y['id'] === $filterYear) {
        $currentYearLabel = $y['year_name'];
        break;
    }
}
foreach ($terms as $t) {
    if ((int)$t['id'] === $filterTerm) {
        $currentTermLabel = $t['term_name'];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="<?php echo h(csrf_token()); ?>">
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

        .filter-bar {
            background: #fff;
            border-radius: 14px;
            padding: 14px 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .filter-bar .fg {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 160px;
            flex: 1;
        }

        .filter-bar .fg label {
            font-size: 12px;
            font-weight: 500;
            color: #1a1a2e;
        }

        .filter-bar .form-select {
            height: 38px;
            font-size: 13px;
            border-radius: 8px;
            border: 1.5px solid #e9ecef;
        }

        .alert-banner {
            background: #fff;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 20px;
            border-left: 4px solid #ffc107;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .alert-banner h6 {
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 8px;
            color: #1a1a2e;
        }

        .alert-banner ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .alert-banner li {
            padding: 4px 0;
            font-size: 13px;
            color: #495057;
        }

        .alert-banner li i {
            width: 20px;
            text-align: center;
            margin-right: 6px;
        }

        .alert-banner a {
            color: #4facfe;
            text-decoration: none;
        }

        .alert-banner a:hover {
            text-decoration: underline;
        }

        .tiles-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 24px;
        }

        .tile {
            background: #fff;
            border-radius: 14px;
            padding: 16px 18px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            display: flex;
            align-items: center;
            gap: 14px;
            transition: all 0.3s ease;
        }

        .tile:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .tile .t-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .tile .t-icon.blue {
            background: #e3f0ff;
            color: #0d6efd;
        }

        .tile .t-icon.green {
            background: #d4edda;
            color: #28a745;
        }

        .tile .t-icon.orange {
            background: #ffe8d9;
            color: #fd7e14;
        }

        .tile .t-icon.purple {
            background: #e8d5f5;
            color: #6f42c1;
        }

        .tile .t-icon.teal {
            background: #d0f0f0;
            color: #20c997;
        }

        .tile .t-icon.pink {
            background: #fce4ec;
            color: #e83e8c;
        }

        .tile .t-icon.red {
            background: #f8d7da;
            color: #dc3545;
        }

        .tile .t-body {
            flex: 1;
            min-width: 0;
        }

        .tile .t-num {
            font-size: 22px;
            font-weight: 700;
            color: #1a1a2e;
            line-height: 1.2;
        }

        .tile .t-lbl {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .tile .t-sub {
            font-size: 11px;
            color: #adb5bd;
            margin-top: 2px;
        }

        .spark-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 24px;
        }

        .spark-card {
            background: #fff;
            border-radius: 14px;
            padding: 16px 20px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .spark-card h6 {
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 12px;
            color: #1a1a2e;
        }

        .spark-bars {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            height: 100px;
        }

        .spark-col {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            height: 100%;
        }

        .spark-col .bar {
            width: 100%;
            border-radius: 4px 4px 0 0;
            background: linear-gradient(180deg, #4facfe, #00f2fe);
            min-height: 2px;
            transition: height 0.6s;
        }

        .spark-col .bar.discipline {
            background: linear-gradient(180deg, #ef4444, #dc2626);
        }

        .spark-col .bar.empty {
            background: #e9ecef;
        }

        .spark-col .lbl {
            font-size: 10px;
            color: #6c757d;
            white-space: nowrap;
        }

        .spark-col .val {
            font-size: 10px;
            font-weight: 600;
            color: #1a1a2e;
        }

        .cards-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }

        .module-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            overflow: hidden;
        }

        .module-card .mc-head {
            padding: 14px 20px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #f8fafc, #eef6ff);
        }

        .module-card .mc-head h6 {
            font-weight: 700;
            font-size: 14px;
            margin: 0;
            color: #1a1a2e;
        }

        .module-card .mc-head a {
            font-size: 12px;
            color: #4facfe;
            text-decoration: none;
        }

        .module-card .mc-head a:hover {
            text-decoration: underline;
        }

        .module-card .mc-body {
            padding: 6px 0;
        }

        .module-card .mc-empty {
            padding: 28px 16px;
            text-align: center;
            color: #adb5bd;
            font-size: 13px;
        }

        .mini-row {
            padding: 8px 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid #f8f9fa;
            font-size: 12px;
        }

        .mini-row:last-child {
            border-bottom: none;
        }

        .mini-row .mr-name {
            flex: 1;
            font-weight: 600;
            color: #1a1a2e;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .mini-row .mr-meta {
            font-size: 11px;
            color: #6c757d;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 9px;
            border-radius: 20px;
            font-size: 10px;
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

            .tile,
            .module-card,
            .spark-card,
            .alert-banner {
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

            .tiles-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .cards-grid {
                grid-template-columns: 1fr;
            }

            .spark-grid {
                grid-template-columns: 1fr;
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

            .tiles-grid {
                grid-template-columns: 1fr 1fr;
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

            .tiles-grid {
                grid-template-columns: 1fr;
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
                        <h1><i class="fas fa-chart-line me-2"></i>Academic Dashboard</h1>
                        <p>State of the academic module at a glance</p>
                    </div>
                    <div class="header-actions">
                        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                            <i class="fas fa-print me-2"></i> Print
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt me-2"></i> Refresh
                        </button>
                    </div>
                </div>

                <!-- Filter bar -->
                <form method="GET" action="/platform/tenant/academic/dashboard.php" class="filter-bar">
                    <div class="fg">
                        <label><?php echo h($labelAcademic); ?></label>
                        <select name="year_id" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($years as $y): ?>
                                <option value="<?php echo (int)$y['id']; ?>" <?php echo $filterYear === (int)$y['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($y['year_name']); ?><?php if ((int)$y['is_current'] === 1) echo ' ★'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label><?php echo h($labelTerm); ?></label>
                        <select name="term_id" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($terms as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>" <?php echo $filterTerm === (int)$t['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($t['term_name']); ?><?php if ((int)$t['is_current'] === 1) echo ' ★'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg" style="flex:0 0 auto;">
                        <label>&nbsp;</label>
                        <a href="/platform/tenant/academic/dashboard.php" class="btn btn-outline-secondary btn-sm">Reset to current</a>
                    </div>
                </form>

                <!-- Alert banner -->
                <?php if (!empty($alerts)): ?>
                    <div class="alert-banner">
                        <h6><i class="fas fa-exclamation-triangle text-warning me-2"></i>Needs attention</h6>
                        <ul>
                            <?php foreach ($alerts as $a): ?>
                                <li>
                                    <i class="fas <?php
                                                    echo $a['type'] === 'attendance'  ? 'fa-calendar-times text-orange' : ($a['type'] === 'discipline' ? 'fa-gavel text-red' : ($a['type'] === 'behaviour'  ? 'fa-star-half-alt text-purple' : ($a['type'] === 'finance'    ? 'fa-money-bill-wave text-green' : 'fa-fingerprint text-blue')));
                                                    ?>"></i>
                                    <a href="<?php echo h($a['link']); ?>"><?php echo h($a['text']); ?></a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Tiles -->
                <div class="tiles-grid">
                    <div class="tile">
                        <div class="t-icon blue"><i class="fas fa-user-graduate"></i></div>
                        <div class="t-body">
                            <div class="t-num"><?php echo h(number_format($totalStudents)); ?></div>
                            <div class="t-lbl">Students</div>
                            <div class="t-sub"><?php echo h(number_format($activeStudents)); ?> active</div>
                        </div>
                    </div>
                    <div class="tile">
                        <div class="t-icon teal"><i class="fas fa-user-check"></i></div>
                        <div class="t-body">
                            <div class="t-num"><?php echo $attendanceRate !== null ? h(number_format($attendanceRate, 1) . '%') : '—'; ?></div>
                            <div class="t-lbl">Attendance rate</div>
                            <div class="t-sub"><?php echo h($currentTermLabel ?: 'current term'); ?></div>
                        </div>
                    </div>
                    <div class="tile">
                        <div class="t-icon <?php echo $majorIncidents > 0 ? 'red' : 'orange'; ?>"><i class="fas fa-gavel"></i></div>
                        <div class="t-body">
                            <div class="t-num"><?php echo h(number_format($incidentsThisTerm)); ?></div>
                            <div class="t-lbl">Incidents</div>
                            <div class="t-sub"><?php echo h(number_format($majorIncidents)); ?> major</div>
                        </div>
                    </div>
                    <div class="tile">
                        <div class="t-icon <?php echo $conductPassRate !== null && $conductPassRate >= 80 ? 'green' : ($conductPassRate !== null && $conductPassRate < 60 ? 'red' : 'purple'); ?>"><i class="fas fa-star-half-alt"></i></div>
                        <div class="t-body">
                            <div class="t-num"><?php echo $conductPassRate !== null ? h(number_format($conductPassRate, 1) . '%') : '—'; ?></div>
                            <div class="t-lbl">Conduct pass</div>
                            <div class="t-sub"><?php echo h(number_format($conductPass)); ?> pass · <?php echo h(number_format($conductFail)); ?> fail</div>
                        </div>
                    </div>

                    <?php if ($canSeeFinance): ?>
                        <div class="tile">
                            <div class="t-icon <?php echo $outstandingFees > 0 ? 'red' : 'green'; ?>"><i class="fas fa-money-bill-wave"></i></div>
                            <div class="t-body">
                                <div class="t-num" style="font-size:16px;"><?php echo h(money($outstandingFees, $currency)); ?></div>
                                <div class="t-lbl">Outstanding fees</div>
                                <div class="t-sub"><?php echo h($currentTermLabel ?: 'current term'); ?></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="tile">
                        <div class="t-icon <?php echo $bioRegisteredPct !== null && $bioRegisteredPct >= 80 ? 'green' : 'orange'; ?>"><i class="fas fa-fingerprint"></i></div>
                        <div class="t-body">
                            <div class="t-num"><?php echo $bioRegisteredPct !== null ? h(number_format($bioRegisteredPct, 1) . '%') : '—'; ?></div>
                            <div class="t-lbl">Biometric</div>
                            <div class="t-sub">students registered</div>
                        </div>
                    </div>

                    <div class="tile">
                        <div class="t-icon <?php echo $unreadNotifications > 0 ? 'blue' : 'gray'; ?>"><i class="fas fa-bell"></i></div>
                        <div class="t-body">
                            <div class="t-num"><?php echo h(number_format($unreadNotifications)); ?></div>
                            <div class="t-lbl">Unread</div>
                            <div class="t-sub">notifications</div>
                        </div>
                    </div>

                    <div class="tile">
                        <div class="t-icon blue"><i class="fas fa-building"></i></div>
                        <div class="t-body">
                            <div class="t-num"><?php echo h($currentYearLabel ?: '—'); ?></div>
                            <div class="t-lbl">Academic year</div>
                            <div class="t-sub"><?php echo h($currentTermLabel ?: 'term not set'); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Sparklines -->
                <div class="spark-grid">
                    <div class="spark-card">
                        <h6><i class="fas fa-calendar-check me-2 text-teal"></i>Attendance rate — last 6 weeks</h6>
                        <div class="spark-bars">
                            <?php foreach ($attendanceSeries as $s):
                                $height = $s['value'] !== null ? max(2, ($s['value'] / 100) * 90) : 2;
                                $empty = $s['value'] === null;
                            ?>
                                <div class="spark-col">
                                    <div class="val"><?php echo $s['value'] !== null ? h(number_format($s['value'], 0) . '%') : '—'; ?></div>
                                    <div class="bar <?php echo $empty ? 'empty' : ''; ?>" style="height: <?php echo (int)$height; ?>px;"></div>
                                    <div class="lbl"><?php echo h($s['label']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="spark-card">
                        <h6><i class="fas fa-gavel me-2 text-danger"></i>Discipline incidents — last 6 weeks</h6>
                        <div class="spark-bars">
                            <?php foreach ($disciplineSeries as $s):
                                $height = max(2, ($s['value'] / $discMax) * 90);
                                $empty = $s['value'] === 0;
                            ?>
                                <div class="spark-col">
                                    <div class="val"><?php echo h((string)(int)$s['value']); ?></div>
                                    <div class="bar discipline <?php echo $empty ? 'empty' : ''; ?>" style="height: <?php echo (int)$height; ?>px;"></div>
                                    <div class="lbl"><?php echo h($s['label']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Module cards -->
                <div class="cards-grid">
                    <!-- Attendance -->
                    <div class="module-card">
                        <div class="mc-head">
                            <h6><i class="fas fa-calendar-check me-2 text-teal"></i>Recent attendance</h6>
                            <a href="/platform/tenant/students/attendance.php">View all <i class="fas fa-arrow-right ms-1"></i></a>
                        </div>
                        <div class="mc-body">
                            <?php if (empty($recentAttendance)): ?>
                                <div class="mc-empty">No attendance records this term.</div>
                                <?php else: foreach ($recentAttendance as $a):
                                    $name = trim(($a['first_name'] ?? '') . ' ' . ($a['middle_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
                                    if ($name === '') $name = 'Student #' . (int)$a['student_id'];
                                    $st = strtolower((string)($a['status'] ?? ''));
                                    $pillClass = in_array($st, ['present', 'present_full', 'p'], true) ? 'green'
                                        : (in_array($st, ['absent', 'unexcused', 'absent_unexcused'], true) ? 'red'
                                            : (in_array($st, ['late', 'tardy'], true) ? 'orange' : 'gray'));
                                ?>
                                    <div class="mini-row">
                                        <div class="mr-name"><?php echo h($name); ?></div>
                                        <div class="mr-meta"><?php echo h((string)($a['attendance_date'] ?? '')); ?></div>
                                        <span class="pill <?php echo $pillClass; ?>"><?php echo h(ucfirst($st)); ?></span>
                                    </div>
                            <?php endforeach;
                            endif; ?>
                        </div>
                    </div>

                    <!-- Discipline -->
                    <div class="module-card">
                        <div class="mc-head">
                            <h6><i class="fas fa-gavel me-2 text-danger"></i>Recent incidents</h6>
                            <a href="/platform/tenant/students/discipline.php">View all <i class="fas fa-arrow-right ms-1"></i></a>
                        </div>
                        <div class="mc-body">
                            <?php if (empty($recentDiscipline)): ?>
                                <div class="mc-empty">No discipline incidents this term.</div>
                                <?php else: foreach ($recentDiscipline as $d):
                                    $name = trim(($d['first_name'] ?? '') . ' ' . ($d['middle_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
                                    if ($name === '') $name = 'Student #' . (int)$d['student_id'];
                                ?>
                                    <div class="mini-row">
                                        <div class="mr-name"><?php echo h($name); ?></div>
                                        <div class="mr-meta"><?php echo h($d['category_name'] ?: 'incident'); ?> · <?php echo h((string)($d['incident_date'] ?? '')); ?></div>
                                        <span class="pill <?php echo severityPill((string)$d['severity']); ?>"><?php echo h(ucfirst((string)$d['severity'])); ?></span>
                                    </div>
                            <?php endforeach;
                            endif; ?>
                        </div>
                    </div>

                    <!-- Behaviour -->
                    <div class="module-card">
                        <div class="mc-head">
                            <h6><i class="fas fa-star-half-alt me-2 text-purple"></i>Recent conduct</h6>
                            <a href="/platform/tenant/students/behaviour.php">View all <i class="fas fa-arrow-right ms-1"></i></a>
                        </div>
                        <div class="mc-body">
                            <?php if (empty($recentBehaviour)): ?>
                                <div class="mc-empty">No behaviour records this term.</div>
                                <?php else: foreach ($recentBehaviour as $b):
                                    $name = trim(($b['first_name'] ?? '') . ' ' . ($b['middle_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
                                    if ($name === '') $name = 'Student #' . (int)$b['student_id'];
                                ?>
                                    <div class="mini-row">
                                        <div class="mr-name"><?php echo h($name); ?></div>
                                        <div class="mr-meta"><?php echo h(number_format((float)$b['final_score'], 1)); ?> · <?php echo h((string)($b['term_name'] ?? '')); ?></div>
                                        <span class="pill <?php echo conductPill((string)$b['final_grade']); ?>"><?php echo h((string)$b['final_grade']); ?></span>
                                    </div>
                            <?php endforeach;
                            endif; ?>
                        </div>
                    </div>

                    <!-- Finance (gated) -->
                    <?php if ($canSeeFinance): ?>
                        <div class="module-card">
                            <div class="mc-head">
                                <h6><i class="fas fa-money-bill-wave me-2 text-success"></i>Recent payments</h6>
                                <a href="/platform/tenant/students/financial-records.php">View all <i class="fas fa-arrow-right ms-1"></i></a>
                            </div>
                            <div class="mc-body">
                                <?php if (empty($recentPayments)): ?>
                                    <div class="mc-empty">No payments recorded this term.</div>
                                    <?php else: foreach ($recentPayments as $p):
                                        $name = trim(($p['first_name'] ?? '') . ' ' . ($p['middle_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
                                        if ($name === '') $name = 'Student #' . (int)$p['student_id'];
                                    ?>
                                        <div class="mini-row">
                                            <div class="mr-name"><?php echo h($name); ?></div>
                                            <div class="mr-meta"><?php echo h((string)($p['paid_at'] ?? '')); ?></div>
                                            <span class="pill green"><?php echo h(money($p['amount'], $currency)); ?></span>
                                        </div>
                                <?php endforeach;
                                endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Biometric (gated) -->
                    <?php if ($canSeeFinance): ?>
                        <div class="module-card">
                            <div class="mc-head">
                                <h6><i class="fas fa-fingerprint me-2 text-primary"></i>Recent biometric events</h6>
                                <a href="/platform/tenant/students/biometric-events.php">View all <i class="fas fa-arrow-right ms-1"></i></a>
                            </div>
                            <div class="mc-body">
                                <?php if (empty($recentBiometric)): ?>
                                    <div class="mc-empty">No biometric events recorded.</div>
                                    <?php else: foreach ($recentBiometric as $e):
                                        $name = trim(($e['first_name'] ?? '') . ' ' . ($e['middle_name'] ?? '') . ' ' . ($e['last_name'] ?? ''));
                                        if ($name === '') $name = 'Student #' . (int)$e['student_id'];
                                    ?>
                                        <div class="mini-row">
                                            <div class="mr-name"><?php echo h($name); ?></div>
                                            <div class="mr-meta"><?php echo h(date('M d, H:i', strtotime((string)$e['occurred_at']))); ?></div>
                                            <span class="pill blue"><?php echo h(ucfirst((string)($e['event_type'] ?? 'event'))); ?></span>
                                        </div>
                                <?php endforeach;
                                endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

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