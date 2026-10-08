<?php

/**
 * Biometric Device Users — map device user numbers to students
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Students
 * @version 1.0
 * @filepath public/platform/tenant/students/biometric-users.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Students biometric-users file of the students-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'students_biometric_users'
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
 *   previous version (1.3). The @package tag remains 'EduTrack'.
 *
 * Session S17c-2a decisions:
 *   B4  auto-match by students.student_number when device user number
 *       equals our student_number
 *   BD5 three mapping routes: manual UI, CSV import, auto-match
 *
 * Scope extension (S17c-3):
 *   SC1 (a) scope types: whole school, class, class offering
 *   SC2 three numbering strategies; default = student_number
 *   SC3 skip silently if already mapped; count in skipped
 *   SC4 tabbed Add Mapping modal (Single / By Scope)
 *   SC5 scope is by active enrollments for the chosen year
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

$pageTitle   = 'Biometric Device Users - Student 360 Platform';
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

function getDevice($db, int $tenantId, int $deviceId): ?array
{
    if ($deviceId <= 0) return null;
    return $db->fetchOne(
        "SELECT * FROM biometric_devices
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$deviceId, $tenantId]
    ) ?: null;
}

/** Return the set of active students in a scope. Scope: school | class | offering. */
function studentsInScope($db, int $tenantId, string $scope, int $yearId, int $classId, int $offeringId): array
{
    $sql = "SELECT DISTINCT s.id AS student_id, s.student_number,
                   s.first_name, s.middle_name, s.last_name
            FROM students s";
    $params = [$tenantId];

    if ($scope === 'offering') {
        if ($offeringId <= 0) throw new Exception('Offering is required.');
        $sql .= " JOIN enrollments e ON e.student_id = s.id
                  WHERE s.tenant_id = ? AND s.deleted_at IS NULL
                    AND e.tenant_id = ? AND e.class_offering_id = ?
                    AND e.status = 'active' AND e.deleted_at IS NULL";
        $params[] = $tenantId;
        $params[] = $offeringId;
    } elseif ($scope === 'class') {
        if ($classId <= 0) throw new Exception('Class is required.');
        if ($yearId  <= 0) throw new Exception('Year is required.');
        $sql .= " JOIN enrollments e ON e.student_id = s.id
                  JOIN class_offerings co ON e.class_offering_id = co.id
                  WHERE s.tenant_id = ? AND s.deleted_at IS NULL
                    AND e.tenant_id = ? AND e.academic_year_id = ?
                    AND e.status = 'active' AND e.deleted_at IS NULL
                    AND co.class_id = ? AND co.deleted_at IS NULL";
        $params[] = $tenantId;
        $params[] = $yearId;
        $params[] = $classId;
    } elseif ($scope === 'school') {
        if ($yearId <= 0)  throw new Exception('Year is required.');
        $sql .= " JOIN enrollments e ON e.student_id = s.id
                  WHERE s.tenant_id = ? AND s.deleted_at IS NULL
                    AND e.tenant_id = ? AND e.academic_year_id = ?
                    AND e.status = 'active' AND e.deleted_at IS NULL";
        $params[] = $tenantId;
        $params[] = $yearId;
    } else {
        throw new Exception('Invalid scope.');
    }

    $sql .= " ORDER BY s.first_name ASC, s.last_name ASC";
    return $db->fetchAll($sql, $params);
}

function currentMaxDeviceUserNo($db, int $tenantId, ?int $deviceId): int
{
    $row = $db->fetchOne(
        "SELECT MAX(CAST(device_user_no AS UNSIGNED)) AS m
         FROM biometric_device_users
         WHERE tenant_id = ?
           AND " . ($deviceId === null ? "device_id IS NULL" : "device_id = ?") . "
           AND deleted_at IS NULL
           AND device_user_no REGEXP '^[0-9]+$'",
        $deviceId === null ? [$tenantId] : [$tenantId, $deviceId]
    );
    return (int)($row['m'] ?? 0);
}

function parseCsvMappings(string $path): array
{
    if (!is_readable($path)) throw new Exception('CSV file is not readable.');
    $fh = fopen($path, 'r');
    if (!$fh) throw new Exception('Could not open CSV.');

    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);

    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        throw new Exception('CSV is empty.');
    }

    $norm = array_map(fn($v) => strtolower(trim((string)$v)), $header);
    $aliases = [
        'device_user_no' => ['device_user_no', 'device user no', 'employee_no', 'employee no', 'employee', 'user_no', 'user no', 'id'],
        'student_number' => ['student_number', 'student number', 'admission_number', 'admission number', 'adm', 'adm_no', 'adm no'],
        'card_no'        => ['card_no', 'card no', 'card', 'card_number', 'card number'],
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
    if (!isset($map['device_user_no']) || !isset($map['student_number'])) {
        fclose($fh);
        throw new Exception('CSV must include columns for device_user_no and student_number.');
    }

    $rows = [];
    $n = 1;
    while (($line = fgetcsv($fh)) !== false) {
        $n++;
        if (count($line) === 1 && trim((string)$line[0]) === '') continue;
        $rows[] = [
            'row'            => $n,
            'device_user_no' => trim((string)($line[$map['device_user_no']] ?? '')),
            'student_number' => trim((string)($line[$map['student_number']] ?? '')),
            'card_no'        => isset($map['card_no']) ? trim((string)($line[$map['card_no']] ?? '')) : '',
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
        // ---------------- CREATE MANUAL MAPPING ----------------
        if ($action === 'create') {
            $deviceId     = (int)($_POST['device_id'] ?? 0);
            $studentId    = (int)($_POST['student_id'] ?? 0);
            $deviceUserNo = trim((string)($_POST['device_user_no'] ?? ''));
            $cardNo       = trim((string)($_POST['card_no'] ?? ''));
            $notes        = trim((string)($_POST['notes'] ?? ''));

            if ($deviceId <= 0) $deviceId = null;
            if ($studentId <= 0) throw new Exception('Student is required.');
            if ($deviceUserNo === '') throw new Exception('Device user number is required.');

            $stu = $db->fetchOne(
                "SELECT id FROM students WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$studentId, $tenantId]
            );
            if (!$stu) throw new Exception('Student not found.');

            if ($deviceId !== null) {
                $dev = getDevice($db, $tenantId, $deviceId);
                if (!$dev) throw new Exception('Device not found.');
            }

            $db->beginTransaction();
            $dup = $db->fetchOne(
                "SELECT id FROM biometric_device_users
                 WHERE tenant_id = ?
                   AND " . ($deviceId === null ? 'device_id IS NULL' : 'device_id = ?') . "
                   AND device_user_no = ?
                   AND deleted_at IS NULL
                 FOR UPDATE",
                $deviceId === null
                    ? [$tenantId, $deviceUserNo]
                    : [$tenantId, $deviceId, $deviceUserNo]
            );
            if ($dup) throw new Exception('That device user number is already mapped.');

            $newId = (int)$db->insert(
                "INSERT INTO biometric_device_users
                    (uuid, tenant_id, device_id, student_id, device_user_no, card_no,
                     match_source, is_active, notes, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'manual', 1, ?, ?, NOW(), NOW())",
                [
                    uuidv4(),
                    $tenantId,
                    $deviceId,
                    $studentId,
                    $deviceUserNo,
                    $cardNo !== '' ? $cardNo : null,
                    $notes !== '' ? $notes : null,
                    $userId ?: null,
                ]
            );

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.biometric_user.created',
                'biometric_device_users',
                $newId,
                ['device_id' => $deviceId, 'student_id' => $studentId, 'device_user_no' => $deviceUserNo]
            );
            $db->commit();

            $_SESSION['success'] = 'Mapping added.';
            header('Location: /platform/tenant/students/biometric-users.php' . ($deviceId ? '?device_id=' . $deviceId : ''));
            exit;
        }

        // ---------------- BULK MAP BY SCOPE ----------------
        if ($action === 'bulk_map') {
            $deviceId   = (int)($_POST['device_id'] ?? 0);
            $yearId     = (int)($_POST['year_id'] ?? 0);
            $scope      = trim((string)($_POST['scope'] ?? 'school'));
            $classId    = (int)($_POST['class_id'] ?? 0);
            $offeringId = (int)($_POST['offering_id'] ?? 0);
            $strategy   = trim((string)($_POST['strategy'] ?? 'student_number'));
            $baseNumber = (int)($_POST['base_number'] ?? 1001);

            if (!in_array($scope, ['school', 'class', 'offering'], true)) {
                throw new Exception('Invalid scope.');
            }
            if (!in_array($strategy, ['student_number', 'auto_number', 'blank'], true)) {
                throw new Exception('Invalid strategy.');
            }
            if ($strategy === 'auto_number' && $baseNumber <= 0) {
                throw new Exception('Base number must be a positive integer.');
            }

            $deviceIdOrNull = $deviceId > 0 ? $deviceId : null;
            if ($deviceIdOrNull !== null) {
                $dev = getDevice($db, $tenantId, $deviceIdOrNull);
                if (!$dev) throw new Exception('Device not found.');
            }

            $students = studentsInScope($db, $tenantId, $scope, $yearId, $classId, $offeringId);
            if (empty($students)) {
                throw new Exception('No students match the selected scope.');
            }

            $db->beginTransaction();

            $existingRows = $db->fetchAll(
                "SELECT student_id, device_user_no
                 FROM biometric_device_users
                 WHERE tenant_id = ?
                   AND " . ($deviceIdOrNull === null ? 'device_id IS NULL' : 'device_id = ?') . "
                   AND deleted_at IS NULL",
                $deviceIdOrNull === null ? [$tenantId] : [$tenantId, $deviceIdOrNull]
            );
            $existingByStudent = [];
            $existingUserNos   = [];
            foreach ($existingRows as $r) {
                $existingByStudent[(int)$r['student_id']] = (int)$r['device_user_no'];
                $existingUserNos[(string)$r['device_user_no']] = true;
            }

            $nextNumber = currentMaxDeviceUserNo($db, $tenantId, $deviceIdOrNull) + 1;
            if ($strategy === 'auto_number' && $nextNumber < $baseNumber) {
                $nextNumber = $baseNumber;
            }

            $added = 0;
            $skipped = 0;

            foreach ($students as $s) {
                $sid = (int)$s['student_id'];
                if (isset($existingByStudent[$sid])) {
                    $skipped++;
                    continue;
                }

                $dun = '';
                if ($strategy === 'student_number') {
                    $dun = trim((string)$s['student_number']);
                    if ($dun === '') {
                        $skipped++;
                        continue;
                    }
                } elseif ($strategy === 'auto_number') {
                    while (isset($existingUserNos[(string)$nextNumber])) {
                        $nextNumber++;
                    }
                    $dun = (string)$nextNumber;
                    $nextNumber++;
                } else {
                    $dun = '';
                }

                if ($dun !== '' && isset($existingUserNos[$dun])) {
                    $skipped++;
                    continue;
                }

                $db->insert(
                    "INSERT INTO biometric_device_users
                        (uuid, tenant_id, device_id, student_id, device_user_no,
                         match_source, is_active, created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 'manual', 1, ?, NOW(), NOW())",
                    [
                        uuidv4(),
                        $tenantId,
                        $deviceIdOrNull,
                        $sid,
                        $dun !== '' ? $dun : null,
                        $userId ?: null,
                    ]
                );
                if ($dun !== '') $existingUserNos[$dun] = true;
                $existingByStudent[$sid] = 1;
                $added++;
            }

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.biometric_user.bulk_mapped',
                'biometric_device_users',
                $deviceIdOrNull,
                [
                    'device_id'   => $deviceIdOrNull,
                    'scope'       => $scope,
                    'year_id'     => $yearId,
                    'class_id'    => $classId,
                    'offering_id' => $offeringId,
                    'strategy'    => $strategy,
                    'added'       => $added,
                    'skipped'     => $skipped,
                ]
            );
            $db->commit();

            $_SESSION['success'] = sprintf('Bulk mapping complete: %d added, %d skipped.', $added, $skipped);
            header('Location: /platform/tenant/students/biometric-users.php' . ($deviceIdOrNull ? '?device_id=' . $deviceIdOrNull : ''));
            exit;
        }

        // ---------------- DELETE ----------------
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $row = $db->fetchOne(
                "SELECT * FROM biometric_device_users
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Mapping not found.');

            $db->beginTransaction();
            $db->execute(
                "UPDATE biometric_device_users SET deleted_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.biometric_user.deleted',
                'biometric_device_users',
                $id,
                ['student_id' => (int)$row['student_id'], 'device_user_no' => $row['device_user_no']]
            );
            $db->commit();

            $_SESSION['success'] = 'Mapping removed.';
            header('Location: /platform/tenant/students/biometric-users.php' . (!empty($row['device_id']) ? '?device_id=' . (int)$row['device_id'] : ''));
            exit;
        }

        // ---------------- AUTO-MATCH BY student_number ----------------
        if ($action === 'auto_match') {
            $deviceId = (int)($_POST['device_id'] ?? 0);

            $db->beginTransaction();
            $students = $db->fetchAll(
                "SELECT s.id, s.student_number
                 FROM students s
                 WHERE s.tenant_id = ? AND s.is_active = 1 AND s.deleted_at IS NULL
                   AND s.student_number IS NOT NULL AND s.student_number <> ''",
                [$tenantId]
            );

            $added = 0;
            $skipped = 0;
            foreach ($students as $st) {
                $sid = (int)$st['id'];
                $num = trim((string)$st['student_number']);
                if ($num === '') {
                    $skipped++;
                    continue;
                }

                $dupe = $db->fetchOne(
                    "SELECT id FROM biometric_device_users
                     WHERE tenant_id = ? AND student_id = ?
                       AND " . ($deviceId > 0 ? 'device_id = ?' : 'device_id IS NULL') . "
                       AND deleted_at IS NULL LIMIT 1",
                    $deviceId > 0 ? [$tenantId, $sid, $deviceId] : [$tenantId, $sid]
                );
                if ($dupe) {
                    $skipped++;
                    continue;
                }

                $dupNum = $db->fetchOne(
                    "SELECT id FROM biometric_device_users
                     WHERE tenant_id = ? AND device_user_no = ?
                       AND " . ($deviceId > 0 ? 'device_id = ?' : 'device_id IS NULL') . "
                       AND deleted_at IS NULL LIMIT 1",
                    $deviceId > 0 ? [$tenantId, $num, $deviceId] : [$tenantId, $num]
                );
                if ($dupNum) {
                    $skipped++;
                    continue;
                }

                $db->insert(
                    "INSERT INTO biometric_device_users
                        (uuid, tenant_id, device_id, student_id, device_user_no,
                         match_source, is_active, created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 'auto', 1, ?, NOW(), NOW())",
                    [uuidv4(), $tenantId, $deviceId > 0 ? $deviceId : null, $sid, $num, $userId ?: null]
                );
                $added++;
            }

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.biometric_user.auto_matched',
                'biometric_device_users',
                $deviceId,
                ['device_id' => $deviceId, 'added' => $added, 'skipped' => $skipped]
            );
            $db->commit();

            $_SESSION['success'] = sprintf('Auto-match complete: %d added, %d skipped.', $added, $skipped);
            header('Location: /platform/tenant/students/biometric-users.php' . ($deviceId > 0 ? '?device_id=' . $deviceId : ''));
            exit;
        }

        // ---------------- CSV IMPORT ----------------
        if ($action === 'import_csv') {
            $deviceId = (int)($_POST['device_id'] ?? 0);

            if (!isset($_FILES['mapping_file']) || $_FILES['mapping_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Please choose a CSV file to upload.');
            }
            $file = $_FILES['mapping_file'];
            $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'txt'], true)) {
                throw new Exception('Only .csv files are accepted for mapping import.');
            }

            $rows = parseCsvMappings($file['tmp_name']);

            $db->beginTransaction();
            $added = 0;
            $skipped = 0;
            $fixList = [];

            foreach ($rows as $r) {
                $rowNo = (int)$r['row'];
                $num   = trim((string)$r['student_number']);
                $dun   = trim((string)$r['device_user_no']);
                $card  = trim((string)$r['card_no']);

                if ($dun === '') {
                    $fixList[] = ['row' => $rowNo, 'reason' => 'Missing device_user_no'];
                    $skipped++;
                    continue;
                }
                if ($num === '') {
                    $fixList[] = ['row' => $rowNo, 'reason' => 'Missing student_number'];
                    $skipped++;
                    continue;
                }

                $stu = $db->fetchOne(
                    "SELECT id FROM students
                     WHERE tenant_id = ? AND student_number = ? AND deleted_at IS NULL",
                    [$tenantId, $num]
                );
                if (!$stu) {
                    $fixList[] = ['row' => $rowNo, 'reason' => 'No student with student_number ' . $num];
                    $skipped++;
                    continue;
                }
                $sid = (int)$stu['id'];

                $dup = $db->fetchOne(
                    "SELECT id FROM biometric_device_users
                     WHERE tenant_id = ? AND device_user_no = ?
                       AND " . ($deviceId > 0 ? 'device_id = ?' : 'device_id IS NULL') . "
                       AND deleted_at IS NULL LIMIT 1",
                    $deviceId > 0 ? [$tenantId, $dun, $deviceId] : [$tenantId, $dun]
                );
                if ($dup) {
                    $skipped++;
                    continue;
                }

                $dup2 = $db->fetchOne(
                    "SELECT id FROM biometric_device_users
                     WHERE tenant_id = ? AND student_id = ?
                       AND " . ($deviceId > 0 ? 'device_id = ?' : 'device_id IS NULL') . "
                       AND deleted_at IS NULL LIMIT 1",
                    $deviceId > 0 ? [$tenantId, $sid, $deviceId] : [$tenantId, $sid]
                );
                if ($dup2) {
                    $skipped++;
                    continue;
                }

                $db->insert(
                    "INSERT INTO biometric_device_users
                        (uuid, tenant_id, device_id, student_id, device_user_no, card_no,
                         match_source, is_active, created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, 'csv', 1, ?, NOW(), NOW())",
                    [
                        uuidv4(),
                        $tenantId,
                        $deviceId > 0 ? $deviceId : null,
                        $sid,
                        $dun,
                        $card !== '' ? $card : null,
                        $userId ?: null,
                    ]
                );
                $added++;
            }

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.biometric_user.csv_imported',
                'biometric_device_users',
                $deviceId,
                ['device_id' => $deviceId, 'added' => $added, 'skipped' => $skipped, 'errors' => count($fixList)]
            );
            $db->commit();

            $_SESSION['import_summary_users'] = [
                'device_id' => $deviceId,
                'added'     => $added,
                'skipped'   => $skipped,
                'fix_list'  => $fixList,
            ];
            $_SESSION['success'] = sprintf('Import complete: %d added, %d skipped, %d error(s).', $added, $skipped, count($fixList));
            header('Location: /platform/tenant/students/biometric-users.php' . ($deviceId > 0 ? '?device_id=' . $deviceId : ''));
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Biometric users action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/students/biometric-users.php');
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
if (isset($_SESSION['import_summary_users'])) {
    $importSummary = $_SESSION['import_summary_users'];
    unset($_SESSION['import_summary_users']);
}

// ============================================
// LOAD PAGE DATA
// ============================================
$settings      = loadActiveSettings($db, $tenantId);
$labelAcademic = $settings['label_academic_structure'] ?? 'Academic Year';
$labelClass    = $settings['label_class'] ?? 'Class';

$selectedDeviceId = (int)($_GET['device_id'] ?? 0);
$selectedDevice   = $selectedDeviceId > 0 ? getDevice($db, $tenantId, $selectedDeviceId) : null;

$devices = $db->fetchAll(
    "SELECT id, device_name, device_model, location
     FROM biometric_devices
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY device_name ASC",
    [$tenantId]
);

$whereDevice = $selectedDeviceId > 0
    ? "u.device_id = " . $selectedDeviceId
    : "(u.device_id IS NULL)";

$mappings = $db->fetchAll(
    "SELECT u.*, s.student_number, s.first_name, s.middle_name, s.last_name, s.preferred_name,
            d.device_name
     FROM biometric_device_users u
     JOIN students s ON u.student_id = s.id
     LEFT JOIN biometric_devices d ON u.device_id = d.id
     WHERE u.tenant_id = ? AND $whereDevice AND u.deleted_at IS NULL
     ORDER BY s.first_name ASC, s.last_name ASC",
    [$tenantId]
);

$studentsList = $db->fetchAll(
    "SELECT id, student_number, first_name, middle_name, last_name, preferred_name
     FROM students
     WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL
     ORDER BY first_name ASC, last_name ASC",
    [$tenantId]
);

$years = $db->fetchAll(
    "SELECT id, year_name, is_current FROM academic_years
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY is_current DESC, start_date DESC, id DESC",
    [$tenantId]
);
$defaultYearId = 0;
foreach ($years as $y) {
    if ((int)$y['is_current'] === 1) {
        $defaultYearId = (int)$y['id'];
        break;
    }
}
if ($defaultYearId === 0 && !empty($years)) $defaultYearId = (int)$years[0]['id'];

$classes = $db->fetchAll(
    "SELECT c.id, c.class_name, c.class_code,
            al.level_name, al.sort_order AS level_sort
     FROM classes c
     LEFT JOIN academic_levels al ON c.school_id = al.school_id
     WHERE c.tenant_id = ? AND c.deleted_at IS NULL
     ORDER BY al.sort_order ASC, c.class_name ASC",
    [$tenantId]
);

$offeringRows = $db->fetchAll(
    "SELECT co.id, co.academic_year_id,
            al.level_name, al.sort_order AS level_sort,
            c.class_name, c.class_code,
            s.stream_name
     FROM class_offerings co
     LEFT JOIN academic_levels al ON co.academic_level_id = al.id
     LEFT JOIN classes c ON co.class_id = c.id
     LEFT JOIN streams s ON co.stream_id = s.id
     WHERE co.tenant_id = ? AND co.deleted_at IS NULL
     ORDER BY al.sort_order ASC, c.class_name ASC, s.stream_name ASC",
    [$tenantId]
);

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

        .table-wrap {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            overflow: hidden;
            width: 100%;
            max-width: 1200px;
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
            padding: 10px 18px;
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
            padding: 12px 18px;
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

        .import-summary {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            padding: 18px 24px;
            margin-bottom: 20px;
            width: 100%;
            max-width: 1200px;
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

        .nav-tabs {
            border-bottom: 1px solid #f0f2f5;
            margin-bottom: 16px;
        }

        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 500;
            padding: 10px 16px;
            border-radius: 10px 10px 0 0;
        }

        .nav-tabs .nav-link.active {
            color: #0d6efd;
            border-bottom: 2px solid #4facfe;
            background: transparent;
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
                        <h1><i class="fas fa-user-tag me-2"></i>Device Users</h1>
                        <p>Link students to their device user numbers so events map correctly</p>
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
                                <?php echo (int)count($mappings); ?> mapping(s)
                                <?php if ($selectedDevice): ?> for <?php echo h($selectedDevice['device_name']); ?><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>Single, scope, or CSV
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
                        <h6><i class="fas fa-file-import text-primary me-2"></i>Last import</h6>
                        <div class="is-grid">
                            <div class="is-cell">
                                <div class="is-num"><?php echo (int)$importSummary['added']; ?></div>
                                <div class="is-lbl">Added</div>
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
                            <div style="font-weight:600;font-size:12px;color:#991b1b;margin-bottom:6px;">
                                <i class="fas fa-list me-1"></i>Fix list
                            </div>
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
                        <div class="ib-title">What this page does</div>
                        <div class="ib-text">
                            The device identifies a student by its own number ("employee no"). Each number
                            must be mapped to a student. Use <strong>Add Mapping</strong> for one student, or
                            the <strong>By Scope</strong> tab to map a whole school, class or offering in one pass.
                            <strong>Auto-match</strong> links every student whose admission number is not yet
                            mapped. <strong>Import CSV</strong> handles pre-numbered device user lists.
                        </div>
                    </div>
                </div>

                <!-- Device picker + action buttons -->
                <div class="panel">
                    <div class="panel-title"><i class="fas fa-filter text-primary me-2"></i>Choose Device</div>
                    <form method="GET" action="/platform/tenant/students/biometric-users.php">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6">
                                <label class="form-label" for="deviceSelect">Device</label>
                                <select name="device_id" class="form-select" id="deviceSelect" onchange="this.form.submit()">
                                    <option value="0" <?php echo $selectedDeviceId === 0 ? 'selected' : ''; ?>>— Unassigned / shared pool —</option>
                                    <?php foreach ($devices as $d): ?>
                                        <option value="<?php echo (int)$d['id']; ?>" <?php echo $selectedDeviceId === (int)$d['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($d['device_name']); ?>
                                            <?php if (!empty($d['location'])): ?> — <?php echo h($d['location']); ?><?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 d-flex gap-2 flex-wrap">
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMappingModal">
                                    <i class="fas fa-plus me-1"></i> Add Mapping
                                </button>
                                <form method="POST" style="display:inline-block;"
                                    onsubmit="return confirm('Auto-match every active student whose admission number is not yet mapped?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="auto_match">
                                    <input type="hidden" name="device_id" value="<?php echo (int)$selectedDeviceId; ?>">
                                    <button type="submit" class="btn btn-outline-warning">
                                        <i class="fas fa-magic me-1"></i> Auto-match
                                    </button>
                                </form>
                                <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#importModal">
                                    <i class="fas fa-file-upload me-1"></i> Import CSV
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <?php if (empty($mappings)): ?>
                    <div class="table-wrap">
                        <div class="empty-state">
                            <i class="fas fa-user-tag"></i>
                            <h5>No mappings for this scope yet</h5>
                            <p>Use Add Mapping, Auto-match, or Import CSV above to link students to device user numbers.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th style="width:130px;">Admission #</th>
                                    <th style="width:150px;">Device user no.</th>
                                    <th style="width:120px;">Card</th>
                                    <th style="width:110px;text-align:center;">Source</th>
                                    <th style="width:140px;">Device</th>
                                    <th style="width:80px;text-align:right;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mappings as $m):
                                    $name = trim(($m['first_name'] ?? '') . ' ' . ($m['middle_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
                                    if ($name === '') $name = 'Student #' . (int)$m['student_id'];
                                    $srcPill = ['manual' => 'blue', 'auto' => 'orange', 'csv' => 'green'][$m['match_source']] ?? 'gray';
                                ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight:600;color:#1a1a2e;"><?php echo h($name); ?></div>
                                            <?php if (!empty($m['preferred_name'])): ?>
                                                <div style="font-size:11px;color:#6c757d;">Prefers: <?php echo h($m['preferred_name']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><span style="font-family:'Courier New',monospace;font-size:12px;color:#0d6efd;"><?php echo h($m['student_number']); ?></span></td>
                                        <td><span style="font-family:'Courier New',monospace;font-weight:600;"><?php echo h($m['device_user_no']); ?></span></td>
                                        <td><?php echo h($m['card_no'] ?: '—'); ?></td>
                                        <td style="text-align:center;"><span class="pill <?php echo $srcPill; ?>"><?php echo h(ucfirst($m['match_source'])); ?></span></td>
                                        <td><?php echo h($m['device_name'] ?: 'Unassigned'); ?></td>
                                        <td style="text-align:right;">
                                            <form method="POST" style="display:inline-block;"
                                                onsubmit="return confirm('Remove this mapping?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm">
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

    <!-- ADD MAPPING MODAL (tabbed: single / by scope) -->
    <div class="modal fade" id="addMappingModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5><i class="fas fa-user-plus text-primary me-2"></i>Add Device User Mapping</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tabSingleBtn" data-bs-toggle="tab"
                                data-bs-target="#tabSingle" type="button" role="tab">
                                <i class="fas fa-user me-1"></i> Single student
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tabScopeBtn" data-bs-toggle="tab"
                                data-bs-target="#tabScope" type="button" role="tab">
                                <i class="fas fa-users me-1"></i> By scope
                            </button>
                        </li>
                    </ul>
                    <div class="tab-content">
                        <!-- SINGLE STUDENT TAB -->
                        <div class="tab-pane fade show active" id="tabSingle" role="tabpanel">
                            <form method="POST" action="/platform/tenant/students/biometric-users.php" id="singleMappingForm">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="create">
                                <input type="hidden" name="device_id" value="<?php echo (int)$selectedDeviceId; ?>">
                                <div class="mb-3">
                                    <label class="form-label" for="addStudent">Student <span class="text-danger">*</span></label>
                                    <select class="form-select" id="addStudent" name="student_id" required>
                                        <option value="">— Pick a student —</option>
                                        <?php foreach ($studentsList as $s):
                                            $nm = trim(($s['first_name'] ?? '') . ' ' . ($s['middle_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
                                            if ($nm === '') $nm = 'Student #' . (int)$s['id'];
                                        ?>
                                            <option value="<?php echo (int)$s['id']; ?>">
                                                <?php echo h($nm); ?> — <?php echo h($s['student_number']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="addDeviceUserNo">Device user number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="addDeviceUserNo" name="device_user_no" maxlength="60" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="addCardNo">Card number (optional)</label>
                                    <input type="text" class="form-control" id="addCardNo" name="card_no" maxlength="60">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="addNotes">Notes (optional)</label>
                                    <input type="text" class="form-control" id="addNotes" name="notes" maxlength="500">
                                </div>
                                <div class="d-flex justify-content-end">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Mapping</button>
                                </div>
                            </form>
                        </div>

                        <!-- BY SCOPE TAB -->
                        <div class="tab-pane fade" id="tabScope" role="tabpanel">
                            <form method="POST" action="/platform/tenant/students/biometric-users.php" id="scopeMappingForm">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="bulk_map">
                                <input type="hidden" name="device_id" value="<?php echo (int)$selectedDeviceId; ?>">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label" for="scopeYear"><?php echo h($labelAcademic); ?></label>
                                        <select class="form-select" id="scopeYear" name="year_id">
                                            <?php foreach ($years as $y): ?>
                                                <option value="<?php echo (int)$y['id']; ?>" <?php echo $defaultYearId === (int)$y['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h($y['year_name']); ?>
                                                    <?php if ((int)$y['is_current'] === 1): ?> ★<?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="scopeType">Scope</label>
                                        <select class="form-select" id="scopeType" name="scope">
                                            <option value="school">Whole school</option>
                                            <option value="class">A class</option>
                                            <option value="offering">A class offering</option>
                                        </select>
                                    </div>

                                    <div class="col-md-6" id="scopeClassWrap" style="display:none;">
                                        <label class="form-label" for="scopeClass"><?php echo h($labelClass); ?></label>
                                        <select class="form-select" id="scopeClass" name="class_id">
                                            <option value="0">— Pick a class —</option>
                                            <?php foreach ($classes as $c): ?>
                                                <option value="<?php echo (int)$c['id']; ?>">
                                                    <?php echo h($c['class_name']); ?>
                                                    <?php if (!empty($c['level_name'])): ?> — <?php echo h($c['level_name']); ?><?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-6" id="scopeOfferingWrap" style="display:none;">
                                        <label class="form-label" for="scopeOffering">Class offering</label>
                                        <select class="form-select" id="scopeOffering" name="offering_id">
                                            <option value="0">— Pick an offering —</option>
                                            <?php foreach ($offeringRows as $o):
                                                $label = trim(($o['class_name'] ?? '') . (($o['stream_name'] ?? '') ? ' / ' . $o['stream_name'] : '') . ' — ' . ($o['level_name'] ?? ''));
                                            ?>
                                                <option value="<?php echo (int)$o['id']; ?>"><?php echo h($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-12">
                                        <hr style="margin:8px 0;">
                                        <div style="font-weight:600;font-size:13px;color:#1a1a2e;margin-bottom:6px;">
                                            Device user number strategy
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <select class="form-select" id="scopeStrategy" name="strategy">
                                            <option value="student_number" selected>Use the student's admission number</option>
                                            <option value="auto_number">Auto-number sequentially</option>
                                            <option value="blank">Leave blank (fill later)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6" id="scopeBaseWrap" style="display:none;">
                                        <label class="form-label" for="scopeBase">Base number</label>
                                        <input type="number" class="form-control" id="scopeBase" name="base_number" value="1001" min="1">
                                        <div style="font-size:11px;color:#6c757d;margin-top:4px;">
                                            Only used when no numbers exist yet. New numbers continue from the highest in use.
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:10px;padding:10px 14px;font-size:12px;color:#495057;">
                                            <i class="fas fa-info-circle text-primary me-1"></i>
                                            Students already mapped for this device are skipped. The result reports how many were added and skipped.
                                        </div>
                                    </div>

                                    <div class="col-12 d-flex justify-content-end">
                                        <button type="submit" class="btn btn-primary"
                                            onclick="return confirm('Apply bulk mapping for this scope?');">
                                            <i class="fas fa-layer-group me-1"></i> Bulk Map
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- IMPORT MODAL -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/students/biometric-users.php" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="import_csv">
                    <input type="hidden" name="device_id" value="<?php echo (int)$selectedDeviceId; ?>">
                    <div class="modal-header">
                        <h5><i class="fas fa-file-upload text-primary me-2"></i>Import Mappings (CSV)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="mappingFile">CSV file <span class="text-danger">*</span></label>
                            <input type="file" class="form-control" id="mappingFile" name="mapping_file" accept=".csv,.txt" required>
                            <div style="font-size:11px;color:#6c757d;margin-top:6px;">
                                Required columns: <code>device_user_no</code>, <code>student_number</code>.
                                Optional: <code>card_no</code>.
                            </div>
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

        // Scope form: dynamic visibility (handles class instead of level)
        (function() {
            const scopeSel = document.getElementById('scopeType');
            const classWrap = document.getElementById('scopeClassWrap');
            const offeringWrap = document.getElementById('scopeOfferingWrap');
            const stratSel = document.getElementById('scopeStrategy');
            const baseWrap = document.getElementById('scopeBaseWrap');

            function sync() {
                const s = scopeSel ? scopeSel.value : 'school';
                if (classWrap) classWrap.style.display = (s === 'class') ? '' : 'none';
                if (offeringWrap) offeringWrap.style.display = (s === 'offering') ? '' : 'none';

                const strat = stratSel ? stratSel.value : 'student_number';
                if (baseWrap) baseWrap.style.display = (strat === 'auto_number') ? '' : 'none';
            }
            if (scopeSel) scopeSel.addEventListener('change', sync);
            if (stratSel) stratSel.addEventListener('change', sync);
            sync();
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