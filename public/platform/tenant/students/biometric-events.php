<?php

/**
 * Biometric Events — log, import, and derive attendance
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Students
 * @version 1.0
 * @filepath public/platform/tenant/students/biometric-events.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Students biometric-events file of the students-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'students_biometric_events'
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
 *   previous version (1.2). The @package tag remains 'EduTrack'.
 *
 * Session S17c-2b decisions:
 *   BE1C  separate CSV import + separate process + "process all unprocessed"
 *   BE2C  unique index at insert + upsert at derivation
 *
 * Derivation rules:
 *   - For each event with is_processed = 0:
 *       - Resolve the student via biometric_device_users.
 *       - No mapping → marked processed with a note.
 *       - check_in → upsert student_attendance 'present' for the day,
 *         unless a manual attendance entry already exists.
 *       - verify_fail / check_out → marked processed, no attendance.
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

$pageTitle   = 'Biometric Events - Student 360 Platform';
$currentPage = 'students';

// ============================================
// SAMPLE CSV DOWNLOAD (GET handler, read-only)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download_sample_csv') {
    $sample = "Employee No.,Name,Card No.,Date,Time,Device,Event\n";
    $sample .= "1001,Example Student A,,2026-09-28,07:32:10,Main Gate Reader,Check-in\n";
    $sample .= "1002,Example Student B,,2026-09-28,07:45:00,Main Gate Reader,Check-in\n";
    $sample .= "1003,Example Student C,,2026-09-28,08:05:15,Main Gate Reader,Check-in\n";
    $sample .= "1001,Example Student A,,2026-09-28,15:12:00,Main Gate Reader,Check-out\n";
    $sample .= "# Replace the 'Employee No.' values with the device user numbers you have mapped on the Device Users page.\n";
    $sample .= "# Delete these comment lines before importing. Column order does not matter — headers are matched by name.\n";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sample_biometric_events.csv"');
    header('Content-Length: ' . strlen($sample));
    echo $sample;
    exit;
}

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

function parseEventCsv(string $path): array
{
    if (!is_readable($path)) throw new Exception('CSV is not readable.');
    $fh = fopen($path, 'r');
    if (!$fh) throw new Exception('Could not open CSV.');

    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);

    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        throw new Exception('CSV is empty.');
    }

    $norm = array_map(fn($v) => strtolower(preg_replace('/\s+/', ' ', trim((string)$v))), $header);

    $aliases = [
        'device_user_no' => ['employee no.', 'employee no', 'employee_no', 'device user no', 'user no', 'user_no'],
        'card_no'        => ['card no.', 'card no', 'card_no', 'card number'],
        'name'           => ['name', 'person name'],
        'date'           => ['date', 'event date'],
        'time'           => ['time', 'event time'],
        'datetime'       => ['date time', 'datetime', 'event time (date+time)', 'time (utc)'],
        'device_name'    => ['device', 'device name', 'terminal'],
        'event'          => ['event', 'event type', 'event description'],
    ];
    $map = [];
    foreach ($aliases as $field => $list) {
        foreach ($norm as $i => $col) {
            if (in_array($col, $list, true)) {
                $map[$field] = $i;
                break;
            }
        }
    }
    if (!isset($map['device_user_no'])) {
        fclose($fh);
        throw new Exception('CSV is missing a device user number column (e.g. "Employee No.").');
    }
    if (!isset($map['datetime']) && !(isset($map['date']) && isset($map['time']))) {
        fclose($fh);
        throw new Exception('CSV must include a Date+Time column, or separate Date and Time columns.');
    }

    $rows = [];
    $n = 1;
    while (($line = fgetcsv($fh)) !== false) {
        $n++;
        if (count($line) === 1 && trim((string)$line[0]) === '') continue;
        // Skip comment lines starting with '#'
        if (isset($line[0]) && substr(trim((string)$line[0]), 0, 1) === '#') continue;

        $userNo = trim((string)($line[$map['device_user_no']] ?? ''));
        if ($userNo === '') continue;

        $card   = isset($map['card_no'])     ? trim((string)($line[$map['card_no']] ?? ''))     : '';
        $name   = isset($map['name'])        ? trim((string)($line[$map['name']] ?? ''))        : '';
        $dev    = isset($map['device_name']) ? trim((string)($line[$map['device_name']] ?? '')) : '';

        if (isset($map['datetime'])) {
            $dtRaw = trim((string)($line[$map['datetime']] ?? ''));
            $dt = str_replace('T', ' ', $dtRaw);
        } else {
            $d = trim((string)($line[$map['date']] ?? ''));
            $t = trim((string)($line[$map['time']] ?? ''));
            $dt = trim($d . ' ' . $t);
        }

        $evtRaw = isset($map['event']) ? strtolower(trim((string)($line[$map['event']] ?? ''))) : '';
        $eventType = 'unknown';
        if (strpos($evtRaw, 'check-in') !== false || strpos($evtRaw, 'check in') !== false || strpos($evtRaw, 'entry') !== false) {
            $eventType = 'check_in';
        } elseif (strpos($evtRaw, 'check-out') !== false || strpos($evtRaw, 'check out') !== false || strpos($evtRaw, 'exit') !== false) {
            $eventType = 'check_out';
        } elseif (strpos($evtRaw, 'fail') !== false || strpos($evtRaw, 'denied') !== false) {
            $eventType = 'verify_fail';
        }

        $rows[] = [
            'row'            => $n,
            'device_user_no' => $userNo,
            'card_no'        => $card,
            'student_name'   => $name,
            'device_name'    => $dev,
            'event_time_raw' => $dt,
            'event_type'     => $eventType,
        ];
    }
    fclose($fh);
    return $rows;
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
        // ---------------- CSV IMPORT ----------------
        if ($action === 'import_csv') {
            $deviceId = (int)($_POST['device_id'] ?? 0);
            $deviceIdOrNull = $deviceId > 0 ? $deviceId : null;

            if (!isset($_FILES['events_file']) || $_FILES['events_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Please choose a CSV file to upload.');
            }
            $file = $_FILES['events_file'];
            $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'txt'], true)) {
                throw new Exception('Only .csv files are accepted for event import.');
            }

            $rows = parseEventCsv($file['tmp_name']);

            $db->beginTransaction();

            $inserted = 0;
            $skipped  = 0;
            $fixList  = [];

            foreach ($rows as $r) {
                $rowNo   = (int)$r['row'];
                $userNo  = trim((string)$r['device_user_no']);
                $cardNo  = trim((string)$r['card_no']);
                $dtRaw   = trim((string)$r['event_time_raw']);
                $evtType = $r['event_type'];

                if ($dtRaw === '' || strtotime($dtRaw) === false) {
                    $fixList[] = ['row' => $rowNo, 'reason' => 'Missing or unparseable event time: "' . $dtRaw . '"'];
                    $skipped++;
                    continue;
                }
                $ts = date('Y-m-d H:i:s', strtotime($dtRaw));

                try {
                    $db->insert(
                        "INSERT INTO biometric_events
                            (uuid, tenant_id, device_id, device_user_no, card_no,
                             student_id, event_time, event_type, direction,
                             is_processed, source, created_at)
                         VALUES (?, ?, ?, ?, ?, NULL, ?, ?, 'unknown', 0, 'csv', NOW())",
                        [
                            uuidv4(),
                            $tenantId,
                            $deviceIdOrNull,
                            $userNo,
                            $cardNo !== '' ? $cardNo : null,
                            $ts,
                            $evtType,
                        ]
                    );
                    $inserted++;
                } catch (Exception $e) {
                    $skipped++;
                }
            }

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.biometric_event.csv_imported',
                'biometric_events',
                $deviceIdOrNull,
                ['device_id' => $deviceIdOrNull, 'inserted' => $inserted, 'skipped' => $skipped, 'errors' => count($fixList)]
            );
            $db->commit();

            $_SESSION['import_summary_events'] = [
                'device_id' => $deviceIdOrNull,
                'inserted'  => $inserted,
                'skipped'   => $skipped,
                'fix_list'  => $fixList,
            ];
            $_SESSION['success'] = sprintf('Import complete: %d event(s) added, %d skipped.', $inserted, $skipped);
            header('Location: /platform/tenant/students/biometric-events.php' . ($deviceIdOrNull ? '?device_id=' . $deviceIdOrNull : ''));
            exit;
        }

        // ---------------- PROCESS ----------------
        if ($action === 'process' || $action === 'process_all') {
            $onlyId = 0;
            if ($action === 'process') {
                $onlyId = (int)($_POST['id'] ?? 0);
                if ($onlyId <= 0) throw new Exception('Event id is required.');
            }

            if ($onlyId > 0) {
                $events = $db->fetchAll(
                    "SELECT * FROM biometric_events
                     WHERE id = ? AND tenant_id = ? LIMIT 1",
                    [$onlyId, $tenantId]
                );
            } else {
                $events = $db->fetchAll(
                    "SELECT * FROM biometric_events
                     WHERE tenant_id = ? AND is_processed = 0
                     ORDER BY event_time ASC, id ASC
                     LIMIT 500",
                    [$tenantId]
                );
            }

            if (empty($events)) {
                $_SESSION['success'] = 'No events to process.';
                header('Location: /platform/tenant/students/biometric-events.php' . (!empty($_POST['device_id']) ? '?device_id=' . (int)$_POST['device_id'] : ''));
                exit;
            }

            $db->beginTransaction();

            $processed = 0;
            $unmapped  = 0;
            $recorded  = 0;

            foreach ($events as $ev) {
                $evId    = (int)$ev['id'];
                $userNo  = (string)$ev['device_user_no'];
                $devId   = $ev['device_id'] !== null ? (int)$ev['device_id'] : null;
                $ts      = (string)$ev['event_time'];
                $evtType = (string)$ev['event_type'];

                $mapping = null;
                if ($devId !== null) {
                    $mapping = $db->fetchOne(
                        "SELECT student_id FROM biometric_device_users
                         WHERE tenant_id = ? AND device_user_no = ?
                           AND (device_id = ? OR device_id IS NULL)
                           AND deleted_at IS NULL
                         ORDER BY CASE WHEN device_id = ? THEN 0 ELSE 1 END ASC
                         LIMIT 1",
                        [$tenantId, $userNo, $devId, $devId]
                    );
                } else {
                    $mapping = $db->fetchOne(
                        "SELECT student_id FROM biometric_device_users
                         WHERE tenant_id = ? AND device_user_no = ?
                           AND deleted_at IS NULL
                         LIMIT 1",
                        [$tenantId, $userNo]
                    );
                }

                if (!$mapping) {
                    $db->execute(
                        "UPDATE biometric_events
                            SET is_processed = 1, processed_at = NOW(), student_id = NULL,
                                process_note = CONCAT('No device-user mapping for number ', ?)
                          WHERE id = ? AND tenant_id = ?",
                        [$userNo, $evId, $tenantId]
                    );
                    $unmapped++;
                    $processed++;
                    continue;
                }

                $sid = (int)$mapping['student_id'];

                $db->execute(
                    "UPDATE biometric_events SET student_id = ? WHERE id = ? AND tenant_id = ?",
                    [$sid, $evId, $tenantId]
                );

                $contributes = in_array($evtType, ['check_in'], true);

                if (!$contributes) {
                    $db->execute(
                        "UPDATE biometric_events
                            SET is_processed = 1, processed_at = NOW(),
                                process_note = 'Event type does not contribute to attendance'
                          WHERE id = ? AND tenant_id = ?",
                        [$evId, $tenantId]
                    );
                    $processed++;
                    continue;
                }

                $dateOnly = date('Y-m-d', strtotime($ts));
                $enr = $db->fetchOne(
                    "SELECT e.class_offering_id, e.academic_year_id, e.academic_term_id
                     FROM enrollments e
                     WHERE e.tenant_id = ?
                       AND e.student_id = ?
                       AND e.status = 'active'
                       AND e.deleted_at IS NULL
                     ORDER BY e.id ASC LIMIT 1",
                    [$tenantId, $sid]
                );

                if (!$enr) {
                    $db->execute(
                        "UPDATE biometric_events
                            SET is_processed = 1, processed_at = NOW(),
                                process_note = 'Student has no active enrollment'
                          WHERE id = ? AND tenant_id = ?",
                        [$evId, $tenantId]
                    );
                    $processed++;
                    continue;
                }

                $offId  = (int)$enr['class_offering_id'];
                $yearId = (int)$enr['academic_year_id'];
                $termId = $enr['academic_term_id'] !== null ? (int)$enr['academic_term_id'] : 0;

                if ($termId <= 0) {
                    $db->execute(
                        "UPDATE biometric_events
                            SET is_processed = 1, processed_at = NOW(),
                                process_note = 'Enrollment has no term; cannot derive attendance'
                          WHERE id = ? AND tenant_id = ?",
                        [$evId, $tenantId]
                    );
                    $processed++;
                    continue;
                }

                $existing = $db->fetchOne(
                    "SELECT id FROM student_attendance
                     WHERE tenant_id = ? AND student_id = ?
                       AND attendance_date = ?
                       AND deleted_at IS NULL
                     FOR UPDATE",
                    [$tenantId, $sid, $dateOnly]
                );

                if ($existing) {
                    $db->execute(
                        "UPDATE biometric_events
                            SET is_processed = 1, processed_at = NOW(),
                                process_note = 'Attendance already recorded for this date'
                          WHERE id = ? AND tenant_id = ?",
                        [$evId, $tenantId]
                    );
                    $processed++;
                    continue;
                }

                $db->insert(
                    "INSERT INTO student_attendance
                        (uuid, tenant_id, student_id, class_offering_id,
                         academic_year_id, academic_term_id,
                         attendance_date, status, source, marked_by, marked_at,
                         created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'present', 'biometric', NULL, NOW(), NOW(), NOW())",
                    [
                        uuidv4(),
                        $tenantId,
                        $sid,
                        $offId,
                        $yearId,
                        $termId,
                        $dateOnly,
                    ]
                );
                $recorded++;

                $db->execute(
                    "UPDATE biometric_events
                        SET is_processed = 1, processed_at = NOW(),
                            process_note = 'Recorded as present'
                      WHERE id = ? AND tenant_id = ?",
                    [$evId, $tenantId]
                );
                $processed++;
            }

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.biometric_event.processed',
                'biometric_events',
                null,
                ['processed' => $processed, 'unmapped' => $unmapped, 'recorded' => $recorded]
            );
            $db->commit();

            $_SESSION['success'] = sprintf(
                'Processed %d event(s): %d new attendance record(s), %d unmapped.',
                $processed,
                $recorded,
                $unmapped
            );
            header('Location: /platform/tenant/students/biometric-events.php' . (!empty($_POST['device_id']) ? '?device_id=' . (int)$_POST['device_id'] : ''));
            exit;
        }

        // ---------------- DELETE EVENT ----------------
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $row = $db->fetchOne(
                "SELECT * FROM biometric_events WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Event not found.');

            $db->beginTransaction();
            $db->execute("DELETE FROM biometric_events WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.biometric_event.deleted',
                'biometric_events',
                $id,
                ['device_user_no' => $row['device_user_no'], 'event_time' => $row['event_time']]
            );
            $db->commit();

            $_SESSION['success'] = 'Event deleted.';
            header('Location: /platform/tenant/students/biometric-events.php' . (!empty($row['device_id']) ? '?device_id=' . (int)$row['device_id'] : ''));
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Biometric events action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/students/biometric-events.php');
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
    $useForm = true;
    unset($_SESSION['form_data']);
}
$successMessage = null;
if (isset($_SESSION['success'])) {
    $successMessage = $_SESSION['success'];
    unset($_SESSION['success']);
}
$importSummary = null;
if (isset($_SESSION['import_summary_events'])) {
    $importSummary = $_SESSION['import_summary_events'];
    unset($_SESSION['import_summary_events']);
}

// ============================================
// LOAD PAGE DATA
// ============================================
$settings      = loadActiveSettings($db, $tenantId);
$labelAcademic = $settings['label_academic_structure'] ?? 'Academic Year';

$filterDeviceId    = (int)($_GET['device_id'] ?? 0);
$filterProcessed   = trim((string)($_GET['processed'] ?? ''));
$filterFromDate    = trim((string)($_GET['from_date'] ?? ''));
$filterToDate      = trim((string)($_GET['to_date'] ?? ''));

$devices = $db->fetchAll(
    "SELECT id, device_name, device_model, location
     FROM biometric_devices
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY device_name ASC",
    [$tenantId]
);

$where = ["e.tenant_id = ?"];
$params = [$tenantId];

if ($filterDeviceId > 0) {
    $where[] = "e.device_id = ?";
    $params[] = $filterDeviceId;
}
if ($filterProcessed === 'yes') {
    $where[] = "e.is_processed = 1";
}
if ($filterProcessed === 'no') {
    $where[] = "e.is_processed = 0";
}
if ($filterFromDate !== '' && strtotime($filterFromDate)) {
    $where[] = "e.event_time >= ?";
    $params[] = date('Y-m-d 00:00:00', strtotime($filterFromDate));
}
if ($filterToDate !== '' && strtotime($filterToDate)) {
    $where[] = "e.event_time <= ?";
    $params[] = date('Y-m-d 23:59:59', strtotime($filterToDate));
}
$whereClause = implode(' AND ', $where);

$events = $db->fetchAll(
    "SELECT e.*, d.device_name,
            s.student_number, s.first_name, s.middle_name, s.last_name
     FROM biometric_events e
     LEFT JOIN biometric_devices d ON e.device_id = d.id
     LEFT JOIN students s ON e.student_id = s.id
     WHERE $whereClause
     ORDER BY e.event_time DESC, e.id DESC
     LIMIT 500",
    $params
);

$unprocessedTotal = 0;
$r = $db->fetchOne(
    "SELECT COUNT(*) AS c FROM biometric_events
     WHERE tenant_id = ? AND is_processed = 0",
    [$tenantId]
);
$unprocessedTotal = (int)($r['c'] ?? 0);

$totalEvents = 0;
try {
    $r = $db->fetchOne("SELECT COUNT(*) AS c FROM biometric_events WHERE tenant_id = ?", [$tenantId]);
    $totalEvents = (int)($r['c'] ?? 0);
} catch (Exception $e) {
}

$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
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
            max-width: 1300px;
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
            max-width: 1300px;
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
            max-width: 1300px;
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
            min-width: 160px;
            flex: 1;
        }

        .filter-row .fg label {
            font-size: 12px;
            font-weight: 500;
            color: #1a1a2e;
        }

        .table-wrap {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            overflow: hidden;
            width: 100%;
            max-width: 1300px;
            margin-left: auto;
            margin-right: auto;
            margin-bottom: 24px;
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
            max-width: 1300px;
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

        .import-summary {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            padding: 18px 24px;
            margin-bottom: 20px;
            width: 100%;
            max-width: 1300px;
            margin-left: auto;
            margin-right: auto;
        }

        .import-summary h6 {
            font-weight: 700;
            font-size: 14px;
            margin: 0 0 10px;
        }

        .import-summary .is-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 12px;
        }

        .import-summary .is-cell {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px 14px;
        }

        .import-summary .is-cell .is-num {
            font-weight: 700;
            font-size: 18px;
            color: #1a1a2e;
        }

        .import-summary .is-cell .is-lbl {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .import-summary .fix-list {
            max-height: 240px;
            overflow-y: auto;
            border: 1px solid #f0f2f5;
            border-radius: 10px;
        }

        .import-summary .fix-list table {
            width: 100%;
            font-size: 12px;
            border-collapse: collapse;
        }

        .import-summary .fix-list th {
            background: #f8f9fa;
            padding: 6px 10px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            color: #6c757d;
            letter-spacing: 0.5px;
        }

        .import-summary .fix-list td {
            padding: 6px 10px;
            border-top: 1px solid #f0f2f5;
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

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 48px;
            opacity: 0.3;
            display: block;
            margin-bottom: 16px;
        }

        .empty-state h5 {
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 8px;
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
                        <h1><i class="fas fa-stream me-2"></i>Biometric Events</h1>
                        <p>Raw events from devices and imports — process them into attendance</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/students/biometric-devices.php" class="btn btn-outline-secondary">
                            <i class="fas fa-server me-2"></i> Manage Devices
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo h($tenantName); ?></div>
                            <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                <?php echo (int)$totalEvents; ?> event(s) total ·
                                <?php echo (int)$unprocessedTotal; ?> unprocessed
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>Push endpoint ready
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

                <?php if ($importSummary): ?>
                    <div class="import-summary">
                        <h6><i class="fas fa-file-import text-primary me-2"></i>Last event import</h6>
                        <div class="is-grid">
                            <div class="is-cell">
                                <div class="is-num"><?php echo (int)$importSummary['inserted']; ?></div>
                                <div class="is-lbl">Inserted</div>
                            </div>
                            <div class="is-cell">
                                <div class="is-num"><?php echo (int)$importSummary['skipped']; ?></div>
                                <div class="is-lbl">Skipped</div>
                            </div>
                            <div class="is-cell">
                                <div class="is-num"><?php echo count($importSummary['fix_list']); ?></div>
                                <div class="is-lbl">Errors</div>
                            </div>
                        </div>
                        <?php if (!empty($importSummary['fix_list'])): ?>
                            <div class="fix-list">
                                <table>
                                    <thead>
                                        <tr>
                                            <th style="width:70px;">Row</th>
                                            <th>Reason</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($importSummary['fix_list'] as $fx): ?>
                                            <tr>
                                                <td><?php echo (int)$fx['row']; ?></td>
                                                <td><?php echo h($fx['reason']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="info-banner">
                    <div class="ib-icon"><i class="fas fa-info-circle"></i></div>
                    <div class="ib-body">
                        <div class="ib-title">How this works</div>
                        <div class="ib-text">
                            Raw events land here from two sources: the <strong>device push endpoint</strong>
                            at <code>/api/biometric/hikvision.php</code>, and <strong>CSV import</strong> of a
                            device export. Events do not affect attendance until you click
                            <strong>Process unprocessed</strong>. Processing resolves each event's student via
                            Device Users, then upserts a <em>present</em> record for that day, unless a manual
                            attendance entry already exists.
                        </div>
                    </div>
                </div>

                <!-- Filters and actions -->
                <div class="panel">
                    <div class="panel-title"><i class="fas fa-filter text-primary me-2"></i>Filters & Actions</div>
                    <form method="GET" action="/platform/tenant/students/biometric-events.php" class="filter-row">
                        <div class="fg">
                            <label>Device</label>
                            <select name="device_id" class="form-select" onchange="this.form.submit()">
                                <option value="0">All devices</option>
                                <?php foreach ($devices as $d): ?>
                                    <option value="<?php echo (int)$d['id']; ?>" <?php echo $filterDeviceId === (int)$d['id'] ? 'selected' : ''; ?>>
                                        <?php echo h($d['device_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fg">
                            <label>Status</label>
                            <select name="processed" class="form-select" onchange="this.form.submit()">
                                <option value="">All</option>
                                <option value="no" <?php echo $filterProcessed === 'no'  ? 'selected' : ''; ?>>Unprocessed only</option>
                                <option value="yes" <?php echo $filterProcessed === 'yes' ? 'selected' : ''; ?>>Processed only</option>
                            </select>
                        </div>
                        <div class="fg">
                            <label>From</label>
                            <input type="date" name="from_date" class="form-control" value="<?php echo h($filterFromDate); ?>">
                        </div>
                        <div class="fg">
                            <label>To</label>
                            <input type="date" name="to_date" class="form-control" value="<?php echo h($filterToDate); ?>">
                        </div>
                        <div class="fg" style="flex:0 0 auto;">
                            <button type="submit" class="btn btn-outline-primary">Apply</button>
                        </div>
                        <div class="fg" style="flex:0 0 auto;">
                            <a href="/platform/tenant/students/biometric-events.php" class="btn btn-outline-secondary">Reset</a>
                        </div>
                    </form>

                    <hr style="margin:14px 0;">

                    <div class="d-flex gap-2 flex-wrap">
                        <form method="POST" style="display:inline-block;"
                            onsubmit="return confirm('Process all unprocessed events? This will create attendance where mappings exist.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="process_all">
                            <input type="hidden" name="device_id" value="<?php echo (int)$filterDeviceId; ?>">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-play me-1"></i> Process unprocessed (<?php echo (int)$unprocessedTotal; ?>)
                            </button>
                        </form>
                        <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#importModal">
                            <i class="fas fa-file-upload me-1"></i> Import CSV of events
                        </button>
                        <a href="/platform/tenant/students/biometric-events.php?action=download_sample_csv" class="btn btn-outline-secondary">
                            <i class="fas fa-download me-1"></i> Download sample CSV
                        </a>
                    </div>
                </div>

                <?php if (empty($events)): ?>
                    <div class="table-wrap">
                        <div class="empty-state">
                            <i class="fas fa-stream"></i>
                            <h5>No events match the current filters</h5>
                            <p>Events appear here once the device push endpoint receives them, or once you import a CSV.</p>
                            <a href="/platform/tenant/students/biometric-events.php?action=download_sample_csv" class="btn btn-outline-secondary btn-sm mt-2">
                                <i class="fas fa-download me-1"></i> Download sample CSV
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width:150px;">Event time</th>
                                    <th style="width:120px;">User no.</th>
                                    <th>Student</th>
                                    <th style="width:130px;">Card</th>
                                    <th style="width:110px;">Type</th>
                                    <th style="width:130px;">Device</th>
                                    <th style="width:120px;text-align:center;">Status</th>
                                    <th style="width:220px;">Note</th>
                                    <th style="width:140px;text-align:right;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($events as $ev):
                                    $evId  = (int)$ev['id'];
                                    $procd = (int)$ev['is_processed'] === 1;
                                    $typePill = ['check_in' => 'green', 'check_out' => 'blue', 'verify_fail' => 'red', 'unknown' => 'gray'][$ev['event_type']] ?? 'gray';
                                    $studentName = '';
                                    if ($ev['student_id'] !== null) {
                                        $studentName = trim(($ev['first_name'] ?? '') . ' ' . ($ev['middle_name'] ?? '') . ' ' . ($ev['last_name'] ?? ''));
                                        if ($studentName === '') $studentName = 'Student #' . (int)$ev['student_id'];
                                    }
                                ?>
                                    <tr>
                                        <td><span style="font-family:'Courier New',monospace;font-size:12px;"><?php echo h($ev['event_time']); ?></span></td>
                                        <td><span style="font-family:'Courier New',monospace;font-weight:600;"><?php echo h($ev['device_user_no']); ?></span></td>
                                        <td>
                                            <?php if ($studentName !== ''): ?>
                                                <div style="font-weight:600;"><?php echo h($studentName); ?></div>
                                                <div style="font-size:11px;color:#6c757d;"><?php echo h($ev['student_number']); ?></div>
                                            <?php else: ?>
                                                <span style="color:#6c757d;font-style:italic;">unmapped</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo h($ev['card_no'] ?: '—'); ?></td>
                                        <td><span class="pill <?php echo $typePill; ?>"><?php echo h(str_replace('_', ' ', $ev['event_type'])); ?></span></td>
                                        <td><?php echo h($ev['device_name'] ?: '—'); ?></td>
                                        <td style="text-align:center;">
                                            <?php if ($procd): ?>
                                                <span class="pill green">Processed</span>
                                            <?php else: ?>
                                                <span class="pill orange">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span style="font-size:12px;color:#6c757d;"><?php echo h($ev['process_note'] ?? ''); ?></span></td>
                                        <td style="text-align:right;">
                                            <?php if (!$procd): ?>
                                                <form method="POST" style="display:inline-block;"
                                                    onsubmit="return confirm('Process this event now?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="process">
                                                    <input type="hidden" name="id" value="<?php echo $evId; ?>">
                                                    <input type="hidden" name="device_id" value="<?php echo (int)$filterDeviceId; ?>">
                                                    <button type="submit" class="btn btn-outline-primary btn-sm" title="Process">
                                                        <i class="fas fa-play"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" style="display:inline-block;"
                                                onsubmit="return confirm('Delete this event? This cannot be undone.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $evId; ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- IMPORT MODAL -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/students/biometric-events.php" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="import_csv">
                    <input type="hidden" name="device_id" value="<?php echo (int)$filterDeviceId; ?>">
                    <div class="modal-header">
                        <h5><i class="fas fa-file-upload text-primary me-2"></i>Import Event CSV</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="eventsFile">CSV file <span class="text-danger">*</span></label>
                            <input type="file" class="form-control" id="eventsFile" name="events_file" accept=".csv,.txt" required>
                            <div style="font-size:11px;color:#6c757d;margin-top:6px;">
                                Standard Hikvision export columns are recognised:
                                <code>Employee No.</code>, <code>Name</code>, <code>Card No.</code>,
                                <code>Date</code>, <code>Time</code>, <code>Event</code>.
                                Lines starting with <code>#</code> are treated as comments and skipped.
                                Rows already present (same user, time, and type) are skipped.
                            </div>
                        </div>
                        <div>
                            <a href="/platform/tenant/students/biometric-events.php?action=download_sample_csv" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-download me-1"></i> Download sample CSV
                            </a>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-upload me-1"></i> Import</button>
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