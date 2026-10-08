<?php

/**
 * Attendance Term Setup — academic calendar builder
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Students
 * @version 1.0
 * @filepath public/platform/tenant/students/attendance-setup.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Students attendance-setup file of the students-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'students_attendance_setup'
 *       to 'students' so the partial marks Students active and
 *       renders the student sub-menu on this page — consistent
 *       with every other students file.
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
 * Session S17c-1 decisions:
 *   BC1  month-grid GUI, click-to-toggle days
 *   BC2  weekends auto-marked as non-school
 *   BC3  bulk actions: holiday, mid-term break, all-school, clear, reset
 *   BC4  attendance_term_calendar (single table)
 *   BC5  regenerate adds missing rows, preserves existing ones
 *   Week Monday–Friday only
 *
 * The admin sets the term's start/end dates here, generates the calendar,
 * and toggles exceptions. The marking page (attendance.php) reads this
 * table to know which days count as school days.
 *
 * Session S20 decisions:
 *   SH2A  per-session CSRF token; all POSTs carry it
 *   SH3A  central Security.php helper, loaded via app/bootstrap.php
 *   SH4A  hardened session started inside bootstrap
 *   SH5C  h() on every echoed value
 *   SH6B  tenant_id scoping and deleted_at IS NULL on every query
 *   SH10A generic error messages; real error logged
 */

// ============================================
// S20 — Bootstrap (session, CSRF, headers, HTTPS, DB)
// ============================================
$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/app/bootstrap.php';

require_tenant();

$tenantId     = current_tenant_id();
$schoolId     = (int)($_SESSION['school_id'] ?? 0);
$userId       = current_user_id();
$currentUser  = $_SESSION['user_name'] ?? 'Admin';
$userAvatar   = substr($currentUser, 0, 1);
$isSuperAdmin = is_super_admin();

$pageTitle   = 'Attendance Setup - Student 360 Platform';
$currentPage = 'students';

// ============================================
// HELPERS
// ============================================
function uuidv4(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

// h() is defined in Security.php; do not redeclare it here.

function writeAudit($db, int $tenantId, int $userId, string $action, string $resourceType, ?int $resourceId, array $details): void
{
    try {
        $db->insert(
            "INSERT INTO audit_logs (user_id, tenant_id, action, resource_type, resource_id, details, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $userId ?: null,
                $tenantId,
                $action,
                $resourceType,
                $resourceId,
                json_encode($details, JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]
        );
    } catch (Exception $e) {
        error_log('audit_logs write failed: ' . $e->getMessage());
    }
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

/**
 * Generate calendar rows for a term between two dates.
 * Preserves existing rows (BC5). Uses INSERT with a check; only new dates
 * get created. Weekends (Sat/Sun) are marked as 'weekend' and is_school_day=0.
 * Weekdays default to school_day.
 */
function generateCalendar($db, int $tenantId, int $userId, int $yearId, int $termId, string $startDate, string $endDate): array
{
    $start = new DateTime($startDate);
    $end   = new DateTime($endDate);
    if ($start > $end) {
        throw new Exception('End date must be on or after start date.');
    }

    $existing = $db->fetchAll(
        "SELECT calendar_date FROM attendance_term_calendar
         WHERE tenant_id = ? AND academic_year_id = ? AND academic_term_id = ?
           AND deleted_at IS NULL",
        [$tenantId, $yearId, $termId]
    );
    $have = [];
    foreach ($existing as $e) $have[(string)$e['calendar_date']] = true;

    $added = 0;
    $skipped = 0;
    $cursor = clone $start;
    while ($cursor <= $end) {
        $d = $cursor->format('Y-m-d');
        if (isset($have[$d])) {
            $skipped++;
            $cursor->modify('+1 day');
            continue;
        }
        $dow = (int)$cursor->format('N'); // 1=Mon .. 7=Sun
        $isWeekend = ($dow >= 6);
        $kind   = $isWeekend ? 'weekend' : 'school_day';
        $isSch  = $isWeekend ? 0 : 1;

        $db->insert(
            "INSERT INTO attendance_term_calendar
                (uuid, tenant_id, academic_year_id, academic_term_id,
                 calendar_date, is_school_day, day_kind, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                uuidv4(),
                $tenantId,
                $yearId,
                $termId,
                $d,
                $isSch,
                $kind,
                $userId ?: null,
            ]
        );
        $added++;
        $cursor->modify('+1 day');
    }

    return ['added' => $added, 'skipped' => $skipped];
}

// ============================================
// ACTIONS (POST)
// ============================================
$errors   = [];
$formData = [];
$action   = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();          // S20 SH2A — all POSTs must carry the session CSRF token
    $formData = $_POST;

    try {
        // ---------------- SAVE TERM DATES + GENERATE ----------------
        if ($action === 'save_and_generate') {
            $termId  = (int)($_POST['academic_term_id'] ?? 0);
            $yearId  = (int)($_POST['academic_year_id'] ?? 0);
            $start   = trim((string)($_POST['start_date'] ?? ''));
            $end     = trim((string)($_POST['end_date'] ?? ''));

            if ($termId <= 0) throw new Exception('Term is required.');
            if ($yearId <= 0) throw new Exception('Academic year is required.');
            if ($start === '' || !strtotime($start)) throw new Exception('A valid start date is required.');
            if ($end   === '' || !strtotime($end))   throw new Exception('A valid end date is required.');
            if (strtotime($end) < strtotime($start)) throw new Exception('End date must be on or after start date.');

            // Confirm the term belongs to this tenant and this year
            $term = $db->fetchOne(
                "SELECT id, academic_year_id FROM academic_terms
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$termId, $tenantId]
            );
            if (!$term) throw new Exception('Term not found.');
            if ((int)$term['academic_year_id'] !== $yearId) {
                throw new Exception('Term does not belong to the selected academic year.');
            }

            $db->beginTransaction();

            // Persist dates onto the term itself
            $db->execute(
                "UPDATE academic_terms
                    SET start_date = ?, end_date = ?, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$start, $end, $termId, $tenantId]
            );

            $res = generateCalendar($db, $tenantId, $userId, $yearId, $termId, $start, $end);

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.attendance_calendar.generated',
                'attendance_term_calendar',
                $termId,
                [
                    'term_id' => $termId,
                    'year_id' => $yearId,
                    'start'   => $start,
                    'end'     => $end,
                    'added'   => $res['added'],
                    'skipped' => $res['skipped'],
                ]
            );

            $db->commit();
            $_SESSION['success'] = sprintf(
                'Term dates saved. Calendar generated: %d day(s) added, %d already present.',
                $res['added'],
                $res['skipped']
            );
            header('Location: /platform/tenant/students/attendance-setup.php'
                . '?year_id=' . $yearId . '&term_id=' . $termId);
            exit;
        }

        // ---------------- TOGGLE A SINGLE DAY ----------------
        if ($action === 'toggle_day') {
            $rowId = (int)($_POST['id'] ?? 0);
            $row = $db->fetchOne(
                "SELECT * FROM attendance_term_calendar
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$rowId, $tenantId]
            );
            if (!$row) throw new Exception('Calendar day not found.');

            $newSchool = ((int)$row['is_school_day'] === 1) ? 0 : 1;
            $newKind   = $newSchool === 1 ? 'school_day' : 'holiday';

            $db->beginTransaction();
            $db->execute(
                "UPDATE attendance_term_calendar
                    SET is_school_day = ?, day_kind = ?, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$newSchool, $newKind, $rowId, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.attendance_calendar.toggled',
                'attendance_term_calendar',
                $rowId,
                [
                    'date'          => $row['calendar_date'],
                    'was_school'    => (int)$row['is_school_day'],
                    'now_school'    => $newSchool,
                ]
            );
            $db->commit();

            $_SESSION['success'] = 'Calendar day updated.';
            header('Location: /platform/tenant/students/attendance-setup.php'
                . '?year_id=' . (int)$row['academic_year_id']
                . '&term_id='  . (int)$row['academic_term_id']);
            exit;
        }

        // ---------------- SET REASON FOR A SINGLE DAY ----------------
        if ($action === 'set_day') {
            $rowId  = (int)($_POST['id'] ?? 0);
            $kind   = trim((string)($_POST['day_kind'] ?? 'school_day'));
            $reason = trim((string)($_POST['reason'] ?? ''));

            $allowedKinds = ['school_day', 'weekend', 'holiday', 'mid_term_break', 'exam_day', 'other'];
            if (!in_array($kind, $allowedKinds, true)) throw new Exception('Invalid day kind.');

            $row = $db->fetchOne(
                "SELECT * FROM attendance_term_calendar
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$rowId, $tenantId]
            );
            if (!$row) throw new Exception('Calendar day not found.');

            // school_day / exam_day keep is_school_day=1; all others 0
            $isSchool = in_array($kind, ['school_day', 'exam_day'], true) ? 1 : 0;

            $db->beginTransaction();
            $db->execute(
                "UPDATE attendance_term_calendar
                    SET is_school_day = ?, day_kind = ?, reason = ?, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [
                    $isSchool,
                    $kind,
                    $reason !== '' ? $reason : null,
                    $rowId,
                    $tenantId,
                ]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.attendance_calendar.set_day',
                'attendance_term_calendar',
                $rowId,
                [
                    'date'      => $row['calendar_date'],
                    'day_kind'  => $kind,
                    'reason'    => $reason,
                    'is_school' => $isSchool,
                ]
            );
            $db->commit();

            $_SESSION['success'] = 'Calendar day updated.';
            header('Location: /platform/tenant/students/attendance-setup.php'
                . '?year_id=' . (int)$row['academic_year_id']
                . '&term_id='  . (int)$row['academic_term_id']);
            exit;
        }

        // ---------------- BULK RANGE ----------------
        if ($action === 'bulk_range') {
            $yearId = (int)($_POST['academic_year_id'] ?? 0);
            $termId = (int)($_POST['academic_term_id'] ?? 0);
            $from   = trim((string)($_POST['range_from'] ?? ''));
            $to     = trim((string)($_POST['range_to'] ?? ''));
            $mode   = trim((string)($_POST['range_mode'] ?? 'holiday'));
            $reason = trim((string)($_POST['range_reason'] ?? ''));

            if ($yearId <= 0 || $termId <= 0) throw new Exception('Term context is required.');
            if ($from === '' || $to === '' || strtotime($from) === false || strtotime($to) === false) {
                throw new Exception('Both range dates are required.');
            }
            if (strtotime($to) < strtotime($from)) throw new Exception('Range end must be on or after range start.');

            $allowedModes = ['school_day', 'holiday', 'mid_term_break', 'exam_day', 'clear'];
            if (!in_array($mode, $allowedModes, true)) throw new Exception('Invalid range mode.');

            $db->beginTransaction();

            $sqlBase = "UPDATE attendance_term_calendar
                           SET is_school_day = ?, day_kind = ?, reason = ?, updated_at = NOW()
                         WHERE tenant_id = ? AND academic_year_id = ? AND academic_term_id = ?
                           AND calendar_date BETWEEN ? AND ?
                           AND deleted_at IS NULL";

            if ($mode === 'clear') {
                // "Clear" restores the auto-generated default for each date:
                // weekends → weekend / non-school; weekdays → school_day.
                $rows = $db->fetchAll(
                    "SELECT id, calendar_date FROM attendance_term_calendar
                     WHERE tenant_id = ? AND academic_year_id = ? AND academic_term_id = ?
                       AND calendar_date BETWEEN ? AND ?
                       AND deleted_at IS NULL",
                    [$tenantId, $yearId, $termId, $from, $to]
                );
                foreach ($rows as $r) {
                    $dow = (int)(new DateTime($r['calendar_date']))->format('N');
                    $isWeekend = ($dow >= 6);
                    $db->execute(
                        "UPDATE attendance_term_calendar
                            SET is_school_day = ?, day_kind = ?, reason = NULL, updated_at = NOW()
                          WHERE id = ? AND tenant_id = ?",
                        [
                            $isWeekend ? 0 : 1,
                            $isWeekend ? 'weekend' : 'school_day',
                            (int)$r['id'],
                            $tenantId
                        ]
                    );
                }
            } else {
                $isSchool = in_array($mode, ['school_day', 'exam_day'], true) ? 1 : 0;
                $db->execute(
                    $sqlBase,
                    [
                        $isSchool,
                        $mode,
                        $reason !== '' ? $reason : null,
                        $tenantId,
                        $yearId,
                        $termId,
                        $from,
                        $to,
                    ]
                );
            }

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.attendance_calendar.bulk_range',
                'attendance_term_calendar',
                $termId,
                [
                    'term_id' => $termId,
                    'from' => $from,
                    'to' => $to,
                    'mode' => $mode,
                    'reason' => $reason,
                ]
            );
            $db->commit();

            $_SESSION['success'] = 'Bulk range applied.';
            header('Location: /platform/tenant/students/attendance-setup.php'
                . '?year_id=' . $yearId . '&term_id=' . $termId);
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Attendance setup action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/students/attendance-setup.php');
        exit;
    }
}

// ============================================
// FLASH
// ============================================
if (isset($_SESSION['errors'])) {
    $errors = $_SESSION['errors'];
    unset($_SESSION['errors']);
}
$useForm = false;
if (isset($_SESSION['form_data'])) {
    $formData = $_SESSION['form_data'];
    $useForm  = true;
    unset($_SESSION['form_data']);
}
$successMessage = null;
if (isset($_SESSION['success'])) {
    $successMessage = $_SESSION['success'];
    unset($_SESSION['success']);
}

// ============================================
// LOAD PAGE DATA
// ============================================
$settings      = loadActiveSettings($db, $tenantId);
$labelAcademic = $settings['label_academic_structure'] ?? 'Academic Year';
$labelTerm     = $settings['label_term'] ?? 'Term';

$filterYear = (int)($_GET['year_id'] ?? 0);
$filterTerm = (int)($_GET['term_id'] ?? 0);

$years = $db->fetchAll(
    "SELECT id, year_name, is_current FROM academic_years
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY is_current DESC, start_date DESC, id DESC",
    [$tenantId]
);

// If no year chosen, default to current year
if ($filterYear <= 0) {
    foreach ($years as $y) {
        if ((int)$y['is_current'] === 1) {
            $filterYear = (int)$y['id'];
            break;
        }
    }
    if ($filterYear <= 0 && !empty($years)) $filterYear = (int)$years[0]['id'];
}

$terms = [];
$selectedTerm = null;
if ($filterYear > 0) {
    $terms = $db->fetchAll(
        "SELECT id, term_name, start_date, end_date, is_current, sort_order
         FROM academic_terms
         WHERE tenant_id = ? AND academic_year_id = ? AND is_active = 1 AND deleted_at IS NULL
         ORDER BY is_current DESC, sort_order ASC, id ASC",
        [$tenantId, $filterYear]
    );

    // Default term: current term if any, else first
    if ($filterTerm <= 0) {
        foreach ($terms as $t) {
            if ((int)$t['is_current'] === 1) {
                $filterTerm = (int)$t['id'];
                break;
            }
        }
        if ($filterTerm <= 0 && !empty($terms)) $filterTerm = (int)$terms[0]['id'];
    }
}

if ($filterTerm > 0) {
    foreach ($terms as $t) {
        if ((int)$t['id'] === $filterTerm) {
            $selectedTerm = $t;
            break;
        }
    }
}

// Load calendar rows for the selected term (in one shot, grouped by month)
$calendarByDate = [];
$calendarStats = ['school' => 0, 'weekend' => 0, 'holiday' => 0, 'break' => 0, 'exam' => 0, 'other' => 0];
if ($selectedTerm) {
    $rows = $db->fetchAll(
        "SELECT * FROM attendance_term_calendar
         WHERE tenant_id = ? AND academic_year_id = ? AND academic_term_id = ?
           AND deleted_at IS NULL
         ORDER BY calendar_date ASC",
        [$tenantId, $filterYear, $filterTerm]
    );
    foreach ($rows as $r) {
        $calendarByDate[(string)$r['calendar_date']] = $r;
        if ((int)$r['is_school_day'] === 1) {
            $calendarStats['school']++;
            if ($r['day_kind'] === 'exam_day') $calendarStats['exam']++;
        } else {
            switch ($r['day_kind']) {
                case 'weekend':
                    $calendarStats['weekend']++;
                    break;
                case 'holiday':
                    $calendarStats['holiday']++;
                    break;
                case 'mid_term_break':
                    $calendarStats['break']++;
                    break;
                case 'other':
                    $calendarStats['other']++;
                    break;
                default:
                    $calendarStats['weekend']++;
                    break;
            }
        }
    }
}

$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

/** Render one month grid. */
function renderMonth(string $ym, array $calendarByDate): string
{
    $start = new DateTime($ym . '-01');
    $end   = new DateTime($ym . '-01');
    $end->modify('last day of this month');

    $firstDow = (int)$start->format('N'); // 1 = Monday
    $daysIn   = (int)$end->format('j');

    $html  = '<table class="cal-table"><thead><tr>';
    foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $lbl) {
        $html .= '<th>' . $lbl . '</th>';
    }
    $html .= '</tr></thead><tbody><tr>';

    // Leading blanks to align first day at Monday
    for ($i = 1; $i < $firstDow; $i++) {
        $html .= '<td class="cal-empty"></td>';
    }

    $col = $firstDow;
    for ($d = 1; $d <= $daysIn; $d++) {
        $date = sprintf('%s-%02d', $ym, $d);
        $row  = $calendarByDate[$date] ?? null;

        $classes = ['cal-day'];
        $dataAttrs = '';
        $title = $date;

        if ($row) {
            $kind = $row['day_kind'];
            $isSch = (int)$row['is_school_day'] === 1;
            $dataAttrs = ' data-id="' . (int)$row['id'] . '"'
                . ' data-date="' . h($date) . '"'
                . ' data-kind="' . h($kind) . '"'
                . ' data-school="' . $isSch . '"';
            switch ($kind) {
                case 'school_day':
                    $classes[] = 'cal-school';
                    break;
                case 'exam_day':
                    $classes[] = 'cal-exam';
                    break;
                case 'weekend':
                    $classes[] = 'cal-weekend';
                    break;
                case 'holiday':
                    $classes[] = 'cal-holiday';
                    break;
                case 'mid_term_break':
                    $classes[] = 'cal-break';
                    break;
                default:
                    $classes[] = 'cal-other';
                    break;
            }
            if (!empty($row['reason'])) $title = $date . ' — ' . $row['reason'];
        } else {
            $classes[] = 'cal-missing';
        }

        $html .= '<td class="' . implode(' ', $classes) . '"' . $dataAttrs . ' title="' . h($title) . '">'
            . '<span class="cal-num">' . $d . '</span>'
            . '</td>';

        if ($col === 7) {
            $html .= '</tr><tr>';
            $col = 1;
        } else {
            $col++;
        }
    }

    // Trailing blanks
    if ($col !== 1) {
        while ($col <= 7) {
            $html .= '<td class="cal-empty"></td>';
            $col++;
        }
        $html .= '</tr>';
    } else {
        // Remove the extra <tr> we added on the last week
        $html = preg_replace('/<tr>$/', '', $html);
    }

    $html .= '</tbody></table>';
    return $html;
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

        .btn-outline-primary {
            background: transparent;
            border: 2px solid #4facfe;
            color: #4facfe;
            border-radius: 10px;
            padding: 6px 14px;
            font-weight: 500;
            font-size: 12px;
        }

        .btn-outline-primary:hover {
            background: #4facfe;
            color: #fff;
        }

        .btn-outline-warning {
            background: transparent;
            border: 2px solid #ffc107;
            color: #856404;
            border-radius: 10px;
            padding: 6px 14px;
            font-weight: 500;
            font-size: 12px;
        }

        .btn-outline-warning:hover {
            background: #ffc107;
            color: #1a1a2e;
        }

        .btn-outline-danger {
            background: transparent;
            border: 2px solid #dc3545;
            color: #dc3545;
            border-radius: 10px;
            padding: 6px 14px;
            font-weight: 500;
            font-size: 12px;
        }

        .btn-outline-danger:hover {
            background: #dc3545;
            color: #fff;
        }

        .btn-outline-info {
            background: transparent;
            border: 2px solid #0891b2;
            color: #0e7490;
            border-radius: 10px;
            padding: 6px 14px;
            font-weight: 500;
            font-size: 12px;
        }

        .btn-outline-info:hover {
            background: #0891b2;
            color: #fff;
        }

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
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
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

        .info-banner {
            background: linear-gradient(135deg, #eef6ff 0%, #e3f0ff 100%);
            border: 2px solid #4facfe;
            border-radius: 14px;
            padding: 16px 24px;
            margin-bottom: 24px;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }

        .info-banner .ib-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(79, 172, 254, 0.15);
            color: #0d6efd;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .info-banner .ib-body {
            flex: 1;
        }

        .info-banner .ib-title {
            font-weight: 700;
            font-size: 14px;
            color: #0d6efd;
            margin-bottom: 2px;
        }

        .info-banner .ib-text {
            font-size: 13px;
            color: #495057;
        }

        .panel {
            background: #fff;
            border-radius: 16px;
            padding: 18px 24px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            margin-bottom: 20px;
            width: 100%;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        .panel .panel-title {
            font-weight: 700;
            font-size: 15px;
            color: #1a1a2e;
            margin-bottom: 8px;
        }

        .panel .panel-hint {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 12px;
        }

        .filter-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filter-row .fg {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 180px;
            flex: 1;
        }

        .filter-row .fg label {
            font-size: 12px;
            font-weight: 500;
            color: #1a1a2e;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-top: 6px;
        }

        .stat-cell {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px 14px;
        }

        .stat-cell .stat-num {
            font-weight: 700;
            font-size: 18px;
            color: #1a1a2e;
        }

        .stat-cell .stat-lbl {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-cell.ok {
            background: #ecfdf5;
        }

        .stat-cell.wknd {
            background: #f1f3f5;
        }

        .stat-cell.bad {
            background: #fef2f2;
        }

        .stat-cell.warn {
            background: #fffbeb;
        }

        .cal-months {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 16px;
            margin-top: 12px;
        }

        .cal-month {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e9ecef;
            padding: 12px;
        }

        .cal-month h6 {
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
            margin: 0 0 8px;
            text-align: center;
        }

        .cal-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            table-layout: fixed;
        }

        .cal-table th {
            color: #6c757d;
            font-weight: 600;
            font-size: 10px;
            text-transform: uppercase;
            padding: 4px 0;
            text-align: center;
        }

        .cal-table td {
            text-align: center;
            padding: 0;
            height: 36px;
        }

        .cal-day {
            position: relative;
            cursor: pointer;
            border-radius: 8px;
            transition: transform 0.1s;
        }

        .cal-day:hover {
            transform: scale(1.05);
        }

        .cal-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 12px;
        }

        .cal-school .cal-num {
            background: #dcfce7;
            color: #166534;
        }

        .cal-exam .cal-num {
            background: #dbeafe;
            color: #1e40af;
        }

        .cal-weekend .cal-num {
            background: #f1f3f5;
            color: #6c757d;
        }

        .cal-holiday .cal-num {
            background: #fee2e2;
            color: #991b1b;
        }

        .cal-break .cal-num {
            background: #fef3c7;
            color: #92400e;
        }

        .cal-other .cal-num {
            background: #e9d5ff;
            color: #6b21a8;
        }

        .cal-missing .cal-num {
            background: #fff;
            color: #adb5bd;
            border: 1px dashed #dee2e6;
        }

        .legend {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 12px;
            font-size: 12px;
        }

        .legend .lg-item {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #495057;
        }

        .legend .lg-swatch {
            display: inline-block;
            width: 16px;
            height: 16px;
            border-radius: 4px;
        }

        .alert-pro {
            border-radius: 14px;
            border: none;
            padding: 18px 20px 18px 22px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        .alert-pro::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            width: 5px;
        }

        .alert-pro .alert-pro-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .alert-pro .alert-pro-content {
            flex: 1;
            padding-top: 2px;
        }

        .alert-pro .alert-pro-title {
            font-weight: 700;
            font-size: 15px;
            margin-bottom: 4px;
        }

        .alert-pro .alert-pro-list {
            margin: 0;
            padding-left: 20px;
            font-size: 13px;
            line-height: 1.7;
        }

        .alert-pro .alert-pro-close {
            background: transparent;
            border: none;
            color: inherit;
            opacity: 0.5;
            font-size: 18px;
            cursor: pointer;
            padding: 0;
            width: 28px;
            height: 28px;
            border-radius: 6px;
        }

        .alert-pro .alert-pro-close:hover {
            opacity: 1;
            background: rgba(0, 0, 0, 0.06);
        }

        .alert-pro.alert-pro-error {
            background: linear-gradient(135deg, #fff5f5 0%, #ffeaea 100%);
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .alert-pro.alert-pro-error::before {
            background: linear-gradient(180deg, #ef4444, #dc2626);
        }

        .alert-pro.alert-pro-error .alert-pro-icon {
            background: rgba(239, 68, 68, 0.12);
            color: #dc2626;
        }

        .alert-pro.alert-pro-success {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .alert-pro.alert-pro-success::before {
            background: linear-gradient(180deg, #22c55e, #16a34a);
        }

        .alert-pro.alert-pro-success .alert-pro-icon {
            background: rgba(34, 197, 94, 0.15);
            color: #16a34a;
        }

        .form-label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            margin-bottom: 4px;
            display: block;
        }

        .form-control,
        .form-select {
            border-radius: 10px;
            padding: 8px 12px;
            border: 2px solid #e9ecef;
            font-size: 13px;
            width: 100%;
            display: block;
            background: #fff;
            color: #1a1a2e;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
            height: 40px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 4px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .modal-content {
            border-radius: 16px;
            border: none;
        }

        .modal-header {
            border-bottom: 1px solid #f0f2f5;
            padding: 20px 24px;
        }

        .modal-header h5 {
            font-weight: 700;
            font-size: 17px;
            color: #1a1a2e;
            margin: 0;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            border-top: 1px solid #f0f2f5;
            padding: 16px 24px;
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

            .filter-row {
                flex-direction: column;
            }

            .filter-row .fg {
                width: 100%;
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
                        <h1><i class="fas fa-calendar-alt me-2"></i>Attendance Setup</h1>
                        <p>Set term boundaries, generate the calendar, mark holidays and mid-term breaks</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/students/attendance.php" class="btn btn-outline-secondary">
                            <i class="fas fa-calendar-check me-2"></i> Go to Attendance
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo h($tenantName); ?></div>
                            <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                <?php if ($selectedTerm): ?>
                                    <?php echo h($selectedTerm['term_name']); ?> ·
                                    <?php echo (int)$calendarStats['school']; ?> school day(s) ·
                                    <?php echo (int)($calendarStats['holiday'] + $calendarStats['break']); ?> non-school exception(s)
                                <?php else: ?>
                                    Select a year and term to configure.
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>Mon–Fri school week
                    </span>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert-pro alert-pro-error" id="serverErrorBox">
                        <div class="alert-pro-icon"><i class="fas fa-times-circle"></i></div>
                        <div class="alert-pro-content">
                            <div class="alert-pro-title">Could not complete the request</div>
                            <ul class="alert-pro-list">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo h($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <button type="button" class="alert-pro-close" onclick="document.getElementById('serverErrorBox').remove()" aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>

                <?php if ($successMessage): ?>
                    <div class="alert-pro alert-pro-success" id="serverSuccessBox">
                        <div class="alert-pro-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="alert-pro-content">
                            <div class="alert-pro-title">Success!</div>
                            <div style="font-size:13px;"><?php echo h($successMessage); ?></div>
                        </div>
                        <button type="button" class="alert-pro-close" onclick="document.getElementById('serverSuccessBox').remove()" aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>

                <div class="info-banner">
                    <div class="ib-icon"><i class="fas fa-info-circle"></i></div>
                    <div class="ib-body">
                        <div class="ib-title">Why this page exists</div>
                        <div class="ib-text">
                            Attendance only makes sense against a term calendar. Set the term's start and end
                            date once, and the system will generate every day in between — marking
                            Monday–Friday as school days and weekends as non-school. Then click any day
                            to toggle it: mark public holidays, mid-term breaks, exam days, or anything else.
                            The attendance page and the promotion run both read this calendar.
                        </div>
                    </div>
                </div>

                <!-- Filter: year & term -->
                <div class="panel">
                    <div class="panel-title"><i class="fas fa-filter text-primary me-2"></i>Select Year & Term</div>
                    <form method="GET" action="/platform/tenant/students/attendance-setup.php" class="filter-row">
                        <div class="fg">
                            <label><?php echo h($labelAcademic); ?></label>
                            <select name="year_id" class="form-select" onchange="this.form.submit()">
                                <?php foreach ($years as $y): ?>
                                    <option value="<?php echo (int)$y['id']; ?>" <?php echo $filterYear === (int)$y['id'] ? 'selected' : ''; ?>>
                                        <?php echo h($y['year_name']); ?>
                                        <?php if ((int)$y['is_current'] === 1): ?> ★<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fg">
                            <label><?php echo h($labelTerm); ?></label>
                            <select name="term_id" class="form-select" onchange="this.form.submit()">
                                <?php foreach ($terms as $t): ?>
                                    <option value="<?php echo (int)$t['id']; ?>" <?php echo $filterTerm === (int)$t['id'] ? 'selected' : ''; ?>>
                                        <?php echo h($t['term_name']); ?>
                                        <?php if ((int)$t['is_current'] === 1): ?> ★<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>

                <?php if ($selectedTerm): ?>

                    <!-- Term dates + generate -->
                    <div class="panel">
                        <div class="panel-title"><i class="fas fa-calendar-plus text-primary me-2"></i>Term Dates & Calendar Generation</div>
                        <div class="panel-hint">
                            Set the term's first and last day. Clicking <strong>Save & Generate</strong> writes
                            the dates onto the term, then adds any missing days to the calendar. Existing
                            exceptions are preserved.
                        </div>
                        <form method="POST" action="/platform/tenant/students/attendance-setup.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_and_generate">
                            <input type="hidden" name="academic_year_id" value="<?php echo (int)$filterYear; ?>">
                            <input type="hidden" name="academic_term_id" value="<?php echo (int)$filterTerm; ?>">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label" for="startDate">Term start date</label>
                                    <input type="date" class="form-control" id="startDate" name="start_date"
                                        value="<?php echo h($selectedTerm['start_date']); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="endDate">Term end date</label>
                                    <input type="date" class="form-control" id="endDate" name="end_date"
                                        value="<?php echo h($selectedTerm['end_date']); ?>" required>
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-magic me-1"></i> Save & Generate Calendar
                                    </button>
                                </div>
                            </div>
                        </form>

                        <hr style="margin: 20px 0;">

                        <div class="stat-grid">
                            <div class="stat-cell ok">
                                <div class="stat-num"><?php echo (int)$calendarStats['school']; ?></div>
                                <div class="stat-lbl">School days</div>
                            </div>
                            <div class="stat-cell wknd">
                                <div class="stat-num"><?php echo (int)$calendarStats['weekend']; ?></div>
                                <div class="stat-lbl">Weekend days</div>
                            </div>
                            <div class="stat-cell bad">
                                <div class="stat-num"><?php echo (int)$calendarStats['holiday']; ?></div>
                                <div class="stat-lbl">Public holidays</div>
                            </div>
                            <div class="stat-cell warn">
                                <div class="stat-num"><?php echo (int)$calendarStats['break']; ?></div>
                                <div class="stat-lbl">Mid-term breaks</div>
                            </div>
                            <div class="stat-cell">
                                <div class="stat-num"><?php echo (int)$calendarStats['exam']; ?></div>
                                <div class="stat-lbl">Exam days</div>
                            </div>
                        </div>
                    </div>

                    <!-- Bulk actions -->
                    <div class="panel">
                        <div class="panel-title"><i class="fas fa-layer-group text-primary me-2"></i>Bulk Actions</div>
                        <div class="panel-hint">
                            Apply a change to a continuous range of dates. Useful for mid-term breaks, exam weeks,
                            or clearing a range back to defaults.
                        </div>
                        <form method="POST" action="/platform/tenant/students/attendance-setup.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="bulk_range">
                            <input type="hidden" name="academic_year_id" value="<?php echo (int)$filterYear; ?>">
                            <input type="hidden" name="academic_term_id" value="<?php echo (int)$filterTerm; ?>">
                            <div class="row g-3">
                                <div class="col-md-2">
                                    <label class="form-label">From</label>
                                    <input type="date" class="form-control" name="range_from" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">To</label>
                                    <input type="date" class="form-control" name="range_to" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Action</label>
                                    <select class="form-select" name="range_mode" required>
                                        <option value="holiday" selected>Mark as public holiday</option>
                                        <option value="mid_term_break">Mark as mid-term break</option>
                                        <option value="exam_day">Mark as exam day</option>
                                        <option value="school_day">Mark as normal school day</option>
                                        <option value="clear">Clear — restore automatic defaults</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Reason (optional)</label>
                                    <input type="text" class="form-control" name="range_reason" maxlength="100"
                                        placeholder="e.g. Independence Day">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-outline-primary w-100">
                                        Apply
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Calendar grid -->
                    <?php if (empty($calendarByDate)): ?>
                        <div class="panel">
                            <div class="panel-title"><i class="fas fa-calendar text-primary me-2"></i>Calendar</div>
                            <div class="panel-hint">
                                No calendar exists for this term yet. Set the term's start and end dates
                                above and click <strong>Save & Generate Calendar</strong>.
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="panel">
                            <div class="panel-title"><i class="fas fa-calendar text-primary me-2"></i>Calendar</div>
                            <div class="panel-hint">
                                Click any day to toggle between <em>school day</em> and <em>non-school day</em>.
                                Double-click to set an exact kind. Use the bulk actions above for ranges and holidays.
                            </div>

                            <?php
                            // Enumerate every month that contains at least one calendar day
                            $months = [];
                            foreach ($calendarByDate as $date => $row) {
                                $ym = substr($date, 0, 7);
                                if (!isset($months[$ym])) {
                                    $months[$ym] = $ym;
                                }
                            }
                            ksort($months);
                            ?>

                            <div class="cal-months">
                                <?php foreach ($months as $ym):
                                    $label = (new DateTime($ym . '-01'))->format('F Y');
                                ?>
                                    <div class="cal-month">
                                        <h6><?php echo h($label); ?></h6>
                                        <?php echo renderMonth($ym, $calendarByDate); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="legend">
                                <div class="lg-item"><span class="lg-swatch" style="background:#dcfce7;"></span> School day</div>
                                <div class="lg-item"><span class="lg-swatch" style="background:#dbeafe;"></span> Exam day</div>
                                <div class="lg-item"><span class="lg-swatch" style="background:#f1f3f5;"></span> Weekend</div>
                                <div class="lg-item"><span class="lg-swatch" style="background:#fee2e2;"></span> Public holiday</div>
                                <div class="lg-item"><span class="lg-swatch" style="background:#fef3c7;"></span> Mid-term break</div>
                                <div class="lg-item"><span class="lg-swatch" style="background:#e9d5ff;"></span> Other</div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- DAY EDIT MODAL -->
    <div class="modal fade" id="dayModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/students/attendance-setup.php" id="dayForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="set_day">
                    <input type="hidden" name="id" id="dayId" value="">
                    <div class="modal-header">
                        <h5><i class="fas fa-calendar-day text-primary me-2"></i>Set Day — <span id="dayDateLabel"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="dayKind">Day type</label>
                            <select class="form-select" id="dayKind" name="day_kind" required>
                                <option value="school_day">School day</option>
                                <option value="exam_day">Exam day</option>
                                <option value="holiday">Public holiday</option>
                                <option value="mid_term_break">Mid-term break</option>
                                <option value="other">Other (specify below)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="dayReason">Reason (optional)</label>
                            <input type="text" class="form-control" id="dayReason" name="reason" maxlength="100"
                                placeholder="e.g. Independence Day">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save</button>
                    </div>
                </form>
            </div>
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

        // Calendar day interactions
        (function() {
            const modalEl = document.getElementById('dayModal');
            const modal = new bootstrap.Modal(modalEl);

            // Single click toggles school/non-school immediately (BC1).
            // Double click (or long-press on touch) opens the modal to choose a specific kind.
            document.querySelectorAll('.cal-day[data-id]').forEach(el => {
                let lastClick = 0;
                el.addEventListener('click', function(ev) {
                    const now = Date.now();
                    const dbl = (now - lastClick) < 350;
                    lastClick = now;
                    if (dbl) {
                        ev.preventDefault();
                        // Open the modal
                        document.getElementById('dayId').value = el.getAttribute('data-id');
                        document.getElementById('dayDateLabel').textContent = el.getAttribute('data-date');
                        const k = el.getAttribute('data-kind') || 'school_day';
                        document.getElementById('dayKind').value = k;
                        document.getElementById('dayReason').value = el.getAttribute('title') ?
                            (el.getAttribute('title').split('—')[1] || '').trim() : '';
                        modal.show();
                    }
                });
            });

            // Double-click also triggers modal (fallback for browsers that don't fire our custom double)
            document.querySelectorAll('.cal-day[data-id]').forEach(el => {
                el.addEventListener('dblclick', function(ev) {
                    ev.preventDefault();
                    document.getElementById('dayId').value = el.getAttribute('data-id');
                    document.getElementById('dayDateLabel').textContent = el.getAttribute('data-date');
                    const k = el.getAttribute('data-kind') || 'school_day';
                    document.getElementById('dayKind').value = k;
                    document.getElementById('dayReason').value = '';
                    modal.show();
                });
            });
        })();

        const successBox = document.getElementById('serverSuccessBox');
        if (successBox) {
            setTimeout(() => {
                successBox.style.transition = 'opacity 0.3s ease';
                successBox.style.opacity = '0';
                setTimeout(() => successBox.remove(), 300);
            }, 6000);
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