<?php

/**
 * Student Attendance — class-scoped day-grain capture and per-term summaries
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Students
 * @version 1.0
 * @filepath public/platform/tenant/students/attendance.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Students attendance file of the students-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'students_attendance'
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
 *   previous version (2.1). The @package tag remains 'EduTrack'.
 *
 * Session S17c-3 decisions:
 *   A1A  class_offering_id remains the storage key; class is the view
 *   A2A  one class teacher owns the whole class (all streams)
 *   A3   class teacher resolved from teacher_class_assignments where role='class_teacher'
 *   A4   admins always allowed
 *
 * Earlier decisions retained:
 *   AT1A day-grain records
 *   AT3A statuses: present, absent, late, excused
 *   AT5C rows lock once the term-level verification is performed
 *   AT6A recorded → verified
 *   AT7A no records for a term → needs_approval (surfaced to S18)
 *   AT8B biometric flag is informational on the grid
 *   AT10A attendance_summaries recomputed on read
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

$pageTitle   = 'Attendance - Student 360 Platform';
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

function currentYear($db, int $tenantId): ?array
{
    return $db->fetchOne(
        "SELECT * FROM academic_years
         WHERE tenant_id = ? AND is_current = 1 AND deleted_at IS NULL
         ORDER BY id DESC LIMIT 1",
        [$tenantId]
    ) ?: null;
}

function currentTerm($db, int $tenantId): ?array
{
    return $db->fetchOne(
        "SELECT * FROM academic_terms
         WHERE tenant_id = ? AND is_current = 1 AND is_active = 1 AND deleted_at IS NULL
         ORDER BY id DESC LIMIT 1",
        [$tenantId]
    ) ?: null;
}

function offeringsOfClass($db, int $tenantId, int $classId, int $yearId): array
{
    if ($classId <= 0 || $yearId <= 0) return [];
    return $db->fetchAll(
        "SELECT co.id, co.academic_level_id, co.class_id, co.stream_id,
                co.academic_year_id,
                s.stream_name
         FROM class_offerings co
         LEFT JOIN streams s ON co.stream_id = s.id
         WHERE co.tenant_id = ?
           AND co.class_id = ?
           AND co.academic_year_id = ?
           AND co.deleted_at IS NULL
         ORDER BY co.id ASC",
        [$tenantId, $classId, $yearId]
    );
}

function classTermIsLocked($db, int $tenantId, array $offeringIds, int $termId): bool
{
    if (empty($offeringIds)) return false;
    $in = implode(',', array_fill(0, count($offeringIds), '?'));
    $row = $db->fetchOne(
        "SELECT COUNT(DISTINCT class_offering_id) AS v
         FROM attendance_summaries
         WHERE tenant_id = ?
           AND class_offering_id IN ($in)
           AND academic_term_id = ?
           AND status = 'verified'
           AND deleted_at IS NULL",
        array_merge([$tenantId], $offeringIds, [$termId])
    );
    $verifiedCount = (int)($row['v'] ?? 0);
    return $verifiedCount === count($offeringIds);
}

function classTeachers($db, int $tenantId, array $offeringIds): array
{
    if (empty($offeringIds)) return [];
    $in = implode(',', array_fill(0, count($offeringIds), '?'));
    $rows = $db->fetchAll(
        "SELECT DISTINCT p.first_name, p.middle_name, p.last_name, p.preferred_name
         FROM teacher_class_assignments tca
         JOIN staff st ON tca.staff_id = st.id
         JOIN persons p ON st.person_id = p.id
         WHERE tca.tenant_id = ?
           AND tca.class_offering_id IN ($in)
           AND tca.role = 'class_teacher'
           AND tca.is_active = 1
           AND tca.deleted_at IS NULL
         ORDER BY p.first_name ASC",
        array_merge([$tenantId], $offeringIds)
    );
    $out = [];
    foreach ($rows as $r) {
        $nm = trim(($r['preferred_name'] ?? '') !== '' ? $r['preferred_name'] : ($r['first_name'] . ' ' . $r['last_name']));
        if ($nm !== '') $out[] = $nm;
    }
    return $out;
}

function canMarkClass($db, int $tenantId, int $userId, array $offeringIds, bool $isSuperAdmin): bool
{
    if ($isSuperAdmin) return true;
    $staffId = $_SESSION['staff_id'] ?? 0;
    if ($staffId <= 0 || empty($offeringIds)) {
        // No staff_id context: treat admins as allowed (A4). The stricter
        // class-teacher check applies when staff_id is present.
        return true;
    }
    $in = implode(',', array_fill(0, count($offeringIds), '?'));
    $row = $db->fetchOne(
        "SELECT id FROM teacher_class_assignments
         WHERE tenant_id = ?
           AND staff_id = ?
           AND class_offering_id IN ($in)
           AND role = 'class_teacher'
           AND is_active = 1
           AND deleted_at IS NULL
         LIMIT 1",
        array_merge([$tenantId, $staffId], $offeringIds)
    );
    return (bool)$row;
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
        // ---------------- SAVE DAY ----------------
        if ($action === 'save_day') {
            $classId    = (int)($_POST['class_id'] ?? 0);
            $yearId     = (int)($_POST['academic_year_id'] ?? 0);
            $termId     = (int)($_POST['academic_term_id'] ?? 0);
            $date       = trim((string)($_POST['attendance_date'] ?? ''));

            if ($classId <= 0) throw new Exception('Class is required.');
            if ($yearId <= 0)  throw new Exception('Academic year is required.');
            if ($termId <= 0)  throw new Exception('Academic term is required.');
            if ($date === '' || !strtotime($date)) throw new Exception('A valid date is required.');

            $offerings = offeringsOfClass($db, $tenantId, $classId, $yearId);
            if (empty($offerings)) throw new Exception('No active offerings exist for this class in the selected year.');
            $offeringIds = array_map(fn($o) => (int)$o['id'], $offerings);

            if (classTermIsLocked($db, $tenantId, $offeringIds, $termId)) {
                throw new Exception('Attendance for this class and term has been verified and is locked.');
            }

            if (!canMarkClass($db, $tenantId, $userId, $offeringIds, $isSuperAdmin)) {
                throw new Exception('You are not the class teacher for this class.');
            }

            $statuses = is_array($_POST['statuses'] ?? null) ? $_POST['statuses'] : [];
            $notesIn  = is_array($_POST['notes'] ?? null) ? $_POST['notes'] : [];
            $lateIn   = is_array($_POST['minutes_late'] ?? null) ? $_POST['minutes_late'] : [];
            $sourceIn = is_array($_POST['source'] ?? null) ? $_POST['source'] : [];

            $allowed = ['present', 'absent', 'late', 'excused'];

            $students = $db->fetchAll(
                "SELECT DISTINCT e.student_id, e.class_offering_id, e.id AS enrollment_id
                 FROM enrollments e
                 JOIN class_offerings co ON e.class_offering_id = co.id
                 WHERE e.tenant_id = ?
                   AND co.class_id = ?
                   AND e.academic_year_id = ?
                   AND e.status = 'active'
                   AND e.deleted_at IS NULL
                 ORDER BY e.student_id ASC, e.class_offering_id ASC",
                [$tenantId, $classId, $yearId]
            );
            if (empty($students)) throw new Exception('No active students exist for this class.');

            $studentOffering = [];
            foreach ($students as $s) {
                $sid = (int)$s['student_id'];
                if (!isset($studentOffering[$sid])) {
                    $studentOffering[$sid] = (int)$s['class_offering_id'];
                }
            }

            $db->beginTransaction();
            $saved = 0;

            foreach ($studentOffering as $sid => $offId) {
                $status = $statuses[$sid] ?? null;
                if ($status === null || $status === '') continue;
                if (!in_array($status, $allowed, true)) throw new Exception('Invalid attendance status.');

                $notes   = trim((string)($notesIn[$sid] ?? ''));
                $minutes = isset($lateIn[$sid]) && $lateIn[$sid] !== '' ? (int)$lateIn[$sid] : null;
                $src     = $sourceIn[$sid] ?? 'manual';
                if (!in_array($src, ['manual', 'biometric', 'device'], true)) $src = 'manual';

                $existing = $db->fetchOne(
                    "SELECT id FROM student_attendance
                     WHERE tenant_id = ? AND student_id = ?
                       AND attendance_date = ?
                       AND deleted_at IS NULL
                     FOR UPDATE",
                    [$tenantId, $sid, $date]
                );

                if ($existing) {
                    $db->execute(
                        "UPDATE student_attendance
                            SET status = ?, minutes_late = ?, notes = ?, source = ?,
                                class_offering_id = ?, academic_year_id = ?, academic_term_id = ?,
                                marked_by = ?, marked_at = NOW(), updated_at = NOW()
                          WHERE id = ? AND tenant_id = ?",
                        [
                            $status,
                            $status === 'late' ? $minutes : null,
                            $notes !== '' ? $notes : null,
                            $src,
                            $offId,
                            $yearId,
                            $termId,
                            $userId ?: null,
                            (int)$existing['id'],
                            $tenantId,
                        ]
                    );
                } else {
                    $db->insert(
                        "INSERT INTO student_attendance
                            (uuid, tenant_id, student_id, class_offering_id,
                             academic_year_id, academic_term_id,
                             attendance_date, status, minutes_late, notes, source,
                             marked_by, marked_at, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())",
                        [
                            uuidv4(),
                            $tenantId,
                            $sid,
                            $offId,
                            $yearId,
                            $termId,
                            $date,
                            $status,
                            $status === 'late' ? $minutes : null,
                            $notes !== '' ? $notes : null,
                            $src,
                            $userId ?: null,
                        ]
                    );
                }
                $saved++;
            }

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.attendance.saved',
                'student_attendance',
                $classId,
                [
                    'class_id' => $classId,
                    'term_id'  => $termId,
                    'date'     => $date,
                    'saved'    => $saved,
                ]
            );
            $db->commit();

            $_SESSION['success'] = sprintf('Attendance saved for %s. %d student(s).', $date, $saved);
            header('Location: /platform/tenant/students/attendance.php'
                . '?class_id=' . $classId
                . '&year_id='  . $yearId
                . '&term_id='  . $termId
                . '&date='     . urlencode($date));
            exit;
        }

        // ---------------- VERIFY TERM ----------------
        if ($action === 'verify_term') {
            $classId = (int)($_POST['class_id'] ?? 0);
            $yearId  = (int)($_POST['academic_year_id'] ?? 0);
            $termId  = (int)($_POST['academic_term_id'] ?? 0);

            if ($classId <= 0 || $yearId <= 0 || $termId <= 0) {
                throw new Exception('Class, year and term are required.');
            }

            $offerings = offeringsOfClass($db, $tenantId, $classId, $yearId);
            if (empty($offerings)) throw new Exception('No active offerings exist for this class.');
            $offeringIds = array_map(fn($o) => (int)$o['id'], $offerings);

            if (!canMarkClass($db, $tenantId, $userId, $offeringIds, $isSuperAdmin)) {
                throw new Exception('You are not the class teacher for this class.');
            }

            $db->beginTransaction();
            $verified = 0;

            foreach ($offeringIds as $offId) {
                $students = $db->fetchAll(
                    "SELECT student_id FROM enrollments
                     WHERE tenant_id = ? AND class_offering_id = ?
                       AND academic_year_id = ?
                       AND status = 'active' AND deleted_at IS NULL",
                    [$tenantId, $offId, $yearId]
                );

                foreach ($students as $st) {
                    $sid = (int)$st['student_id'];

                    $agg = $db->fetchOne(
                        "SELECT
                            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS p,
                            SUM(CASE WHEN status = 'absent'  THEN 1 ELSE 0 END) AS a,
                            SUM(CASE WHEN status = 'late'    THEN 1 ELSE 0 END) AS l,
                            SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) AS x,
                            COUNT(*) AS n
                         FROM student_attendance
                         WHERE tenant_id = ? AND student_id = ?
                           AND class_offering_id = ?
                           AND academic_term_id = ?
                           AND deleted_at IS NULL",
                        [$tenantId, $sid, $offId, $termId]
                    );

                    $p = (int)($agg['p'] ?? 0);
                    $a = (int)($agg['a'] ?? 0);
                    $l = (int)($agg['l'] ?? 0);
                    $x = (int)($agg['x'] ?? 0);
                    $n = (int)($agg['n'] ?? 0);
                    $coverageNote = $n === 0 ? 'No attendance records exist for this term.' : null;

                    $existing = $db->fetchOne(
                        "SELECT id FROM attendance_summaries
                         WHERE tenant_id = ? AND student_id = ? AND class_offering_id = ?
                           AND academic_term_id = ? AND deleted_at IS NULL
                         FOR UPDATE",
                        [$tenantId, $sid, $offId, $termId]
                    );

                    if ($existing) {
                        $db->execute(
                            "UPDATE attendance_summaries
                                SET days_present = ?, days_absent = ?, days_late = ?, days_excused = ?,
                                    days_total = ?, coverage_note = ?,
                                    status = 'verified', verified_at = NOW(), verified_by = ?,
                                    updated_at = NOW()
                              WHERE id = ? AND tenant_id = ?",
                            [$p, $a, $l, $x, $n, $coverageNote, $userId ?: null, (int)$existing['id'], $tenantId]
                        );
                    } else {
                        $db->insert(
                            "INSERT INTO attendance_summaries
                                (uuid, tenant_id, student_id, class_offering_id,
                                 academic_year_id, academic_term_id,
                                 days_present, days_absent, days_late, days_excused, days_total,
                                 coverage_note, status, verified_at, verified_by,
                                 created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'verified', NOW(), ?, NOW(), NOW())",
                            [
                                uuidv4(),
                                $tenantId,
                                $sid,
                                $offId,
                                $yearId,
                                $termId,
                                $p,
                                $a,
                                $l,
                                $x,
                                $n,
                                $coverageNote,
                                $userId ?: null,
                            ]
                        );
                    }
                    $verified++;
                }
            }

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.attendance.term_verified',
                'attendance_summaries',
                $classId,
                ['class_id' => $classId, 'term_id' => $termId, 'summaries' => $verified]
            );
            $db->commit();

            $_SESSION['success'] = sprintf('Attendance verified for %d student record(s). This class + term is now locked.', $verified);
            header('Location: /platform/tenant/students/attendance.php'
                . '?class_id=' . $classId . '&year_id=' . $yearId . '&term_id=' . $termId);
            exit;
        }

        // ---------------- UNVERIFY ----------------
        if ($action === 'unverify_term') {
            $classId = (int)($_POST['class_id'] ?? 0);
            $yearId  = (int)($_POST['academic_year_id'] ?? 0);
            $termId  = (int)($_POST['academic_term_id'] ?? 0);

            if ($classId <= 0 || $yearId <= 0 || $termId <= 0) {
                throw new Exception('Class, year and term are required.');
            }

            $offerings = offeringsOfClass($db, $tenantId, $classId, $yearId);
            $offeringIds = array_map(fn($o) => (int)$o['id'], $offerings);
            if (empty($offeringIds)) throw new Exception('No active offerings exist for this class.');

            $in = implode(',', array_fill(0, count($offeringIds), '?'));

            $db->beginTransaction();
            $db->execute(
                "UPDATE attendance_summaries
                    SET status = 'unverified', verified_at = NULL, verified_by = NULL, updated_at = NOW()
                  WHERE tenant_id = ?
                    AND class_offering_id IN ($in)
                    AND academic_term_id = ?
                    AND deleted_at IS NULL",
                array_merge([$tenantId], $offeringIds, [$termId])
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.attendance.term_unverified',
                'attendance_summaries',
                $classId,
                ['class_id' => $classId, 'term_id' => $termId]
            );
            $db->commit();

            $_SESSION['success'] = 'Verification removed. This class + term is editable again.';
            header('Location: /platform/tenant/students/attendance.php'
                . '?class_id=' . $classId . '&year_id=' . $yearId . '&term_id=' . $termId);
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Attendance action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/students/attendance.php');
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

// ============================================
// LOAD PAGE DATA
// ============================================
$settings      = loadActiveSettings($db, $tenantId);
$labelAcademic = $settings['label_academic_structure'] ?? 'Academic Year';
$labelLevel    = $settings['label_level'] ?? 'Level';
$labelClass    = $settings['label_class'] ?? 'Class';
$labelTerm     = $settings['label_term'] ?? 'Term';

$filterYear    = (int)($_GET['year_id'] ?? 0);
$filterTerm    = (int)($_GET['term_id'] ?? 0);
$filterClassId = (int)($_GET['class_id'] ?? 0);
$filterDate    = trim((string)($_GET['date'] ?? date('Y-m-d')));

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
    $cy = currentYear($db, $tenantId);
    if ($cy) $filterYear = (int)$cy['id'];
}
if ($filterTerm <= 0) {
    $ct = currentTerm($db, $tenantId);
    if ($ct) $filterTerm = (int)$ct['id'];
}

// Classes grouped by level for the picker
$classRows = $db->fetchAll(
    "SELECT c.id, c.class_name, c.class_code,
            al.id AS level_id, al.level_name, al.level_code, al.sort_order AS level_sort
     FROM classes c
     LEFT JOIN academic_levels al ON c.school_id = al.school_id
     WHERE c.tenant_id = ? AND c.deleted_at IS NULL
     ORDER BY al.sort_order ASC, c.class_name ASC",
    [$tenantId]
);

if (empty($classRows)) {
    $classRows = $db->fetchAll(
        "SELECT id, class_name, class_code, NULL AS level_id, NULL AS level_name,
                NULL AS level_code, 0 AS level_sort
         FROM classes
         WHERE tenant_id = ? AND deleted_at IS NULL
         ORDER BY class_name ASC",
        [$tenantId]
    );
}

$classesByLevel = [];
foreach ($classRows as $c) {
    $key = $c['level_id'] !== null ? (int)$c['level_id'] : 0;
    $lbl = $c['level_name'] ?: 'Classes';
    if (!isset($classesByLevel[$key])) {
        $classesByLevel[$key] = ['label' => $lbl, 'items' => []];
    }
    $classesByLevel[$key]['items'][] = $c;
}

$selectedClass = null;
if ($filterClassId > 0) {
    $selectedClass = $db->fetchOne(
        "SELECT id, class_name, class_code FROM classes
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$filterClassId, $tenantId]
    );
}

$offerings = [];
$isLocked  = false;
$teachers  = [];
$gridStudents = [];
$gridRecords  = [];
$termSummary  = [];

if ($selectedClass && $filterYear > 0) {
    $offerings = offeringsOfClass($db, $tenantId, $filterClassId, $filterYear);
    $offeringIds = array_map(fn($o) => (int)$o['id'], $offerings);

    if (!empty($offeringIds)) {
        $isLocked = classTermIsLocked($db, $tenantId, $offeringIds, $filterTerm);
        $teachers = classTeachers($db, $tenantId, $offeringIds);

        $offeringIdsSql = implode(',', array_map('intval', $offeringIds));

        // Grid students: every active enrolment of this class for the year.
        // The biometric subquery references s.id (students.id), not s.student_id.
        $gridStudents = $db->fetchAll(
            "SELECT DISTINCT e.student_id,
                    s.first_name, s.middle_name, s.last_name, s.preferred_name, s.student_number,
                    (SELECT e2.roll_number FROM enrollments e2
                     WHERE e2.tenant_id = e.tenant_id
                       AND e2.student_id = e.student_id
                       AND e2.academic_year_id = e.academic_year_id
                       AND e2.class_offering_id IN ($offeringIdsSql)
                       AND e2.status = 'active' AND e2.deleted_at IS NULL
                     LIMIT 1) AS roll_number,
                    (SELECT sbr.status FROM student_biometric_registrations sbr
                     WHERE sbr.tenant_id = e.tenant_id
                       AND sbr.student_id = s.id
                       AND sbr.academic_year_id = e.academic_year_id
                       AND sbr.academic_term_id = ?
                       AND sbr.deleted_at IS NULL
                     LIMIT 1) AS biometric_status
             FROM enrollments e
             JOIN students s ON e.student_id = s.id
             WHERE e.tenant_id = ?
               AND e.academic_year_id = ?
               AND e.class_offering_id IN ($offeringIdsSql)
               AND e.status = 'active'
               AND e.deleted_at IS NULL
             ORDER BY s.first_name ASC, s.last_name ASC",
            [$filterTerm, $tenantId, $filterYear]
        );

        // Existing records for the chosen date
        if (!empty($gridStudents)) {
            $studentIds = array_map(fn($r) => (int)$r['student_id'], $gridStudents);
            $inStu = implode(',', array_fill(0, count($studentIds), '?'));
            $rows = $db->fetchAll(
                "SELECT * FROM student_attendance
                 WHERE tenant_id = ?
                   AND attendance_date = ?
                   AND student_id IN ($inStu)
                   AND deleted_at IS NULL",
                array_merge([$tenantId, $filterDate], $studentIds)
            );
            foreach ($rows as $r) $gridRecords[(int)$r['student_id']] = $r;
        }

        // Term summary per student
        foreach ($gridStudents as $gs) {
            $sid = (int)$gs['student_id'];
            $agg = $db->fetchOne(
                "SELECT
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS p,
                    SUM(CASE WHEN status = 'absent'  THEN 1 ELSE 0 END) AS a,
                    SUM(CASE WHEN status = 'late'    THEN 1 ELSE 0 END) AS l,
                    SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) AS x,
                    COUNT(*) AS n
                 FROM student_attendance
                 WHERE tenant_id = ? AND student_id = ?
                   AND class_offering_id IN ($offeringIdsSql)
                   AND academic_term_id = ?
                   AND deleted_at IS NULL",
                [$tenantId, $sid, $filterTerm]
            );
            $termSummary[$sid] = [
                'present' => (int)($agg['p'] ?? 0),
                'absent'  => (int)($agg['a'] ?? 0),
                'late'    => (int)($agg['l'] ?? 0),
                'excused' => (int)($agg['x'] ?? 0),
                'total'   => (int)($agg['n'] ?? 0),
            ];
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

$totalStudents = count($gridStudents);
$markedToday   = count($gridRecords);
$unmarkedToday = $totalStudents - $markedToday;
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

        .btn-outline-success {
            background: transparent;
            border: 2px solid #16a34a;
            color: #166534;
            border-radius: 10px;
            padding: 6px 14px;
            font-weight: 500;
            font-size: 12px;
        }

        .btn-outline-success:hover {
            background: #16a34a;
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

        .info-banner.warn {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border-color: #fbbf24;
        }

        .info-banner.warn .ib-icon {
            background: rgba(251, 191, 36, 0.18);
            color: #b45309;
        }

        .info-banner.warn .ib-title {
            color: #92400e;
        }

        .filters-bar {
            background: #fff;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        .filters-bar .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
            min-width: 180px;
        }

        .filters-bar .filter-group label {
            font-weight: 500;
            font-size: 12px;
            color: #1a1a2e;
            white-space: nowrap;
        }

        .filters-bar .form-select,
        .filters-bar .form-control {
            height: 38px;
            font-size: 13px;
            border-radius: 8px;
            border: 1.5px solid #e9ecef;
        }

        .grid-wrap {
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

        .grid-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .grid-table thead th {
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

        .grid-table tbody td {
            padding: 10px 14px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .grid-table tbody tr:last-child td {
            border-bottom: none;
        }

        .grid-table tbody tr:hover {
            background: #fafbfc;
        }

        .status-group {
            display: inline-flex;
            gap: 4px;
        }

        .status-group label {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            border: 2px solid #e9ecef;
            background: #fff;
            color: #495057;
            transition: all 0.15s;
        }

        .status-group input {
            display: none;
        }

        .status-group label:has(input:checked) {
            color: #fff;
        }

        .status-group label.present:has(input:checked) {
            background: #16a34a;
            border-color: #16a34a;
        }

        .status-group label.absent:has(input:checked) {
            background: #dc2626;
            border-color: #dc2626;
        }

        .status-group label.late:has(input:checked) {
            background: #f59e0b;
            border-color: #f59e0b;
        }

        .status-group label.excused:has(input:checked) {
            background: #0ea5e9;
            border-color: #0ea5e9;
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

        .pill.orange {
            background: #ffe8d9;
            color: #c2410c;
        }

        .pill.teal {
            background: #d1f2eb;
            color: #0d5c4a;
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

            .grid-table {
                font-size: 12px;
            }

            .grid-table thead th {
                padding: 8px 10px;
                font-size: 10px;
            }

            .grid-table tbody td {
                padding: 8px 10px;
            }

            .filters-bar {
                flex-direction: column;
            }

            .filters-bar .filter-group {
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
                        <h1><i class="fas fa-calendar-check me-2"></i>Attendance</h1>
                        <p>Class-based day-grain capture and per-term summaries</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/students/attendance-setup.php" class="btn btn-outline-secondary">
                            <i class="fas fa-cog me-2"></i> Attendance Setup
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo h($tenantName); ?></div>
                            <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                <?php if ($selectedClass && !empty($gridStudents)): ?>
                                    <?php echo h($selectedClass['class_name']); ?> ·
                                    <?php echo (int)$totalStudents; ?> student(s) ·
                                    <?php echo (int)$markedToday; ?> marked on <?php echo h($filterDate); ?> ·
                                    <?php echo (int)$unmarkedToday; ?> pending
                                <?php else: ?>
                                    Pick a year, term and class to begin.
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>Class-based · Mon–Fri
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
                        <div class="ib-title">Class-based attendance</div>
                        <div class="ib-text">
                            Pick a year, term and <strong>class</strong>. The grid shows every actively-enrolled
                            student of that class, across whatever streams the class has for the year.
                            Mark <strong>Present</strong>, <strong>Absent</strong>, <strong>Late</strong> or
                            <strong>Excused</strong> and save. The class teacher or an admin may mark.
                            Verify term to lock the entire class at once.
                        </div>
                    </div>
                </div>

                <!-- Filter bar -->
                <form method="GET" action="/platform/tenant/students/attendance.php" class="filters-bar">
                    <div class="filter-group">
                        <label><i class="fas fa-filter me-1"></i><?php echo h($labelAcademic); ?></label>
                        <select name="year_id" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($years as $y): ?>
                                <option value="<?php echo (int)$y['id']; ?>" <?php echo $filterYear === (int)$y['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($y['year_name']); ?>
                                    <?php if ((int)$y['is_current'] === 1): ?> ★<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
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
                    <div class="filter-group" style="min-width:240px;">
                        <label><?php echo h($labelClass); ?></label>
                        <select name="class_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">— Pick a class —</option>
                            <?php foreach ($classesByLevel as $group): ?>
                                <optgroup label="<?php echo h($group['label']); ?>">
                                    <?php foreach ($group['items'] as $c): ?>
                                        <option value="<?php echo (int)$c['id']; ?>" <?php echo $filterClassId === (int)$c['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($c['class_name']); ?>
                                            <?php if (!empty($c['class_code'])): ?> (<?php echo h($c['class_code']); ?>)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group" style="min-width:180px;">
                        <label>Date</label>
                        <input type="date" name="date" class="form-control"
                            value="<?php echo h($filterDate); ?>"
                            onchange="this.form.submit()">
                    </div>
                    <div class="filter-group" style="flex:0 0 auto;">
                        <a href="/platform/tenant/students/attendance.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Reset
                        </a>
                    </div>
                </form>

                <?php if (!$selectedClass): ?>
                    <div class="grid-wrap">
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <h5>Pick a class to begin</h5>
                            <p>Choose a year, term and class above.</p>
                        </div>
                    </div>
                <?php elseif (empty($gridStudents)): ?>
                    <div class="grid-wrap">
                        <div class="empty-state">
                            <i class="fas fa-user-slash"></i>
                            <h5>No enrolled students</h5>
                            <p>This class has no active enrollments for the selected year.</p>
                            <a href="/platform/tenant/academic/enrollments.php" class="btn btn-primary btn-sm mt-2">
                                <i class="fas fa-user-plus me-1"></i> Go to Enrollments
                            </a>
                        </div>
                    </div>
                <?php else: ?>

                    <?php if (!empty($teachers)): ?>
                        <div class="info-banner">
                            <div class="ib-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                            <div class="ib-body">
                                <div class="ib-title">Class teacher(s)</div>
                                <div class="ib-text"><?php echo h(implode(', ', $teachers)); ?></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($isLocked): ?>
                        <div class="info-banner warn">
                            <div class="ib-icon"><i class="fas fa-lock"></i></div>
                            <div class="ib-body">
                                <div class="ib-title">This class + term is locked</div>
                                <div class="ib-text">
                                    Attendance has been verified for this term. To make corrections,
                                    click <strong>Unverify term</strong> below.
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="grid-wrap">
                        <form method="POST" action="/platform/tenant/students/attendance.php" id="attendanceForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_day">
                            <input type="hidden" name="class_id" value="<?php echo (int)$filterClassId; ?>">
                            <input type="hidden" name="academic_year_id" value="<?php echo (int)$filterYear; ?>">
                            <input type="hidden" name="academic_term_id" value="<?php echo (int)$filterTerm; ?>">
                            <input type="hidden" name="attendance_date" value="<?php echo h($filterDate); ?>">

                            <table class="grid-table">
                                <thead>
                                    <tr>
                                        <th style="width:60px;">#</th>
                                        <th>Student</th>
                                        <th style="width:110px;">Roll #</th>
                                        <th style="width:120px;">Admission #</th>
                                        <th style="width:120px;text-align:center;">Biometric</th>
                                        <th style="min-width:340px;">Status</th>
                                        <th style="width:90px;">Min. late</th>
                                        <th style="width:180px;">Notes</th>
                                        <th style="width:200px;text-align:center;">Term totals</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i = 0;
                                    foreach ($gridStudents as $gs):
                                        $i++;
                                        $sid = (int)$gs['student_id'];
                                        $rec = $gridRecords[$sid] ?? null;
                                        $curStatus = $rec['status'] ?? '';
                                        $curMins   = $rec['minutes_late'] ?? '';
                                        $curNotes  = $rec['notes'] ?? '';
                                        $sum = $termSummary[$sid] ?? ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'total' => 0];

                                        $name = trim(($gs['first_name'] ?? '') . ' ' . ($gs['middle_name'] ?? '') . ' ' . ($gs['last_name'] ?? ''));
                                        if ($name === '') $name = 'Student #' . $sid;

                                        $bioStatus = $gs['biometric_status'] ?? null;
                                    ?>
                                        <tr>
                                            <td><?php echo $i; ?></td>
                                            <td>
                                                <div style="font-weight:600;color:#1a1a2e;"><?php echo h($name); ?></div>
                                                <?php if (!empty($gs['preferred_name'])): ?>
                                                    <div style="font-size:11px;color:#6c757d;">Prefers: <?php echo h($gs['preferred_name']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo h($gs['roll_number']); ?></td>
                                            <td>
                                                <span style="font-family:'Courier New',monospace;font-size:12px;color:#0d6efd;">
                                                    <?php echo h($gs['student_number']); ?>
                                                </span>
                                            </td>
                                            <td style="text-align:center;">
                                                <?php if ($bioStatus === 'verified'): ?>
                                                    <span class="pill green" title="Biometric registration verified"><i class="fas fa-fingerprint"></i></span>
                                                <?php elseif ($bioStatus === 'exempted'): ?>
                                                    <span class="pill teal" title="Exempted from biometric registration"><i class="fas fa-shield-alt"></i></span>
                                                <?php else: ?>
                                                    <span class="pill orange" title="Biometric registration not yet verified"><i class="fas fa-exclamation-circle"></i></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="status-group">
                                                    <label class="present">
                                                        <input type="radio" name="statuses[<?php echo $sid; ?>]" value="present"
                                                            <?php echo $curStatus === 'present' ? 'checked' : ''; ?>
                                                            <?php echo $isLocked ? 'disabled' : ''; ?>>
                                                        <span>Present</span>
                                                    </label>
                                                    <label class="absent">
                                                        <input type="radio" name="statuses[<?php echo $sid; ?>]" value="absent"
                                                            <?php echo $curStatus === 'absent' ? 'checked' : ''; ?>
                                                            <?php echo $isLocked ? 'disabled' : ''; ?>>
                                                        <span>Absent</span>
                                                    </label>
                                                    <label class="late">
                                                        <input type="radio" name="statuses[<?php echo $sid; ?>]" value="late"
                                                            <?php echo $curStatus === 'late' ? 'checked' : ''; ?>
                                                            <?php echo $isLocked ? 'disabled' : ''; ?>>
                                                        <span>Late</span>
                                                    </label>
                                                    <label class="excused">
                                                        <input type="radio" name="statuses[<?php echo $sid; ?>]" value="excused"
                                                            <?php echo $curStatus === 'excused' ? 'checked' : ''; ?>
                                                            <?php echo $isLocked ? 'disabled' : ''; ?>>
                                                        <span>Excused</span>
                                                    </label>
                                                </div>
                                            </td>
                                            <td>
                                                <input type="number" min="0" max="600" class="form-control"
                                                    style="height:30px;padding:2px 6px;font-size:12px;"
                                                    name="minutes_late[<?php echo $sid; ?>]"
                                                    value="<?php echo $curMins !== '' ? (int)$curMins : ''; ?>"
                                                    placeholder="0"
                                                    <?php echo $isLocked ? 'readonly' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="text" maxlength="255" class="form-control"
                                                    style="height:30px;padding:2px 6px;font-size:12px;"
                                                    name="notes[<?php echo $sid; ?>]"
                                                    value="<?php echo h($curNotes); ?>"
                                                    placeholder="Optional"
                                                    <?php echo $isLocked ? 'readonly' : ''; ?>>
                                                <input type="hidden" name="source[<?php echo $sid; ?>]" value="manual">
                                            </td>
                                            <td style="text-align:center;">
                                                <div style="font-size:11px;color:#6c757d;">
                                                    <strong><?php echo (int)$sum['total']; ?></strong> recorded ·
                                                    P <strong><?php echo (int)$sum['present']; ?></strong> ·
                                                    A <strong><?php echo (int)$sum['absent']; ?></strong> ·
                                                    L <strong><?php echo (int)$sum['late']; ?></strong> ·
                                                    X <strong><?php echo (int)$sum['excused']; ?></strong>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <?php if (!$isLocked): ?>
                                <div style="padding:16px 24px;border-top:1px solid #f0f2f5;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                                    <div class="d-flex gap-2 flex-wrap">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="markAllPresent">
                                            <i class="fas fa-check me-1"></i> Mark all present
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="clearAll">
                                            <i class="fas fa-eraser me-1"></i> Clear all
                                        </button>
                                    </div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Save attendance for <?php echo h($filterDate); ?>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>

                    <div class="grid-wrap" style="padding:16px 24px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                            <div>
                                <div style="font-weight:700;font-size:14px;color:#1a1a2e;">
                                    <?php echo $isLocked ? 'Term verified and locked' : 'Verify term to lock and freeze the summary'; ?>
                                </div>
                                <div style="font-size:12px;color:#6c757d;">
                                    Verifying recomputes each student's term summary and locks every stream of
                                    this class for the selected term. The promotion run reads verified summaries.
                                </div>
                            </div>
                            <?php if ($isLocked): ?>
                                <form method="POST" action="/platform/tenant/students/attendance.php"
                                    onsubmit="return confirm('Remove verification? This will allow editing again.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="unverify_term">
                                    <input type="hidden" name="class_id" value="<?php echo (int)$filterClassId; ?>">
                                    <input type="hidden" name="academic_year_id" value="<?php echo (int)$filterYear; ?>">
                                    <input type="hidden" name="academic_term_id" value="<?php echo (int)$filterTerm; ?>">
                                    <button type="submit" class="btn btn-outline-warning">
                                        <i class="fas fa-unlock me-1"></i> Unverify term
                                    </button>
                                </form>
                            <?php else: ?>
                                <form method="POST" action="/platform/tenant/students/attendance.php"
                                    onsubmit="return confirm('Verify attendance for this class + term? This locks further edits.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="verify_term">
                                    <input type="hidden" name="class_id" value="<?php echo (int)$filterClassId; ?>">
                                    <input type="hidden" name="academic_year_id" value="<?php echo (int)$filterYear; ?>">
                                    <input type="hidden" name="academic_term_id" value="<?php echo (int)$filterTerm; ?>">
                                    <button type="submit" class="btn btn-outline-success">
                                        <i class="fas fa-lock me-1"></i> Verify term
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
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

        (function() {
            const markAll = document.getElementById('markAllPresent');
            const clearAll = document.getElementById('clearAll');
            if (markAll) {
                markAll.addEventListener('click', function() {
                    document.querySelectorAll('input[type="radio"][value="present"]').forEach(el => {
                        if (!el.disabled) el.checked = true;
                    });
                });
            }
            if (clearAll) {
                clearAll.addEventListener('click', function() {
                    document.querySelectorAll('input[type="radio"]').forEach(el => {
                        if (!el.disabled) el.checked = false;
                    });
                });
            }
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