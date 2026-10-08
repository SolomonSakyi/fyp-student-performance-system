<?php

/**
 * Student Discipline — incidents, suspensions, review workflow
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Students
 * @version 1.0
 * @filepath public/platform/tenant/students/discipline.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Students discipline file of the students-surface sweep. Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'students_discipline' to 'students'
 *       so the partial marks Students active and renders the
 *       student sub-menu on this page — consistent with every
 *       other students file.
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
 * Session S17d decisions:
 *   DS1A  one row per incident per student
 *   DS2B  editable discipline_categories (seeded on install)
 *   DS3A  severity: minor / moderate / major
 *   DS4A  action_taken is free text
 *   DS5B  suspension_start + suspension_end, nullable
 *   DS6A  any staff may log
 *   DS7B  status: recorded → reviewed → resolved
 *   DS8A  free soft-delete, audited
 *   DS9A  flat list grouped by year → class, with filters
 *   DS10A page lives in students module
 *
 * Session S17g-2 decisions:
 *   P2 producer wired — class teacher, head teachers, guardian-on-major
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

$pageTitle   = 'Discipline - Student 360 Platform';
$currentPage = 'students';

// ============================================
// AJAX: LIVE STUDENT SEARCH (read-only, no CSRF)
// ============================================
if (isset($_GET['ajax_search_students'])) {
    header('Content-Type: application/json; charset=utf-8');

    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $like = '%' . $q . '%';

    try {
        $rows = $db->fetchAll(
            "SELECT s.id, s.student_number,
                    s.first_name, s.middle_name, s.last_name, s.preferred_name
             FROM students s
             WHERE s.tenant_id = ?
               AND s.deleted_at IS NULL
               AND s.is_active = 1
               AND (
                    s.student_number LIKE ?
                 OR s.first_name LIKE ?
                 OR s.middle_name LIKE ?
                 OR s.last_name LIKE ?
                 OR s.preferred_name LIKE ?
                 OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?
                 OR CONCAT(s.first_name, ' ', s.middle_name, ' ', s.last_name) LIKE ?
               )
             ORDER BY s.first_name ASC, s.last_name ASC
             LIMIT 20",
            [
                $tenantId,
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
            ]
        );

        $items = [];
        foreach ($rows as $r) {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            if ($name === '') $name = 'Student #' . (int)$r['id'];
            $items[] = [
                'id'    => (int)$r['id'],
                'name'  => $name,
                'num'   => (string)$r['student_number'],
                'label' => $name . ' — ' . (string)$r['student_number'],
            ];
        }

        echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log('discipline ajax_search error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
    }
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

function currentYear($db, int $tenantId): ?array
{
    return $db->fetchOne(
        "SELECT * FROM academic_years
         WHERE tenant_id = ? AND is_current = 1 AND deleted_at IS NULL
         ORDER BY id DESC LIMIT 1",
        [$tenantId]
    ) ?: null;
}

function severityPill(string $sev): string
{
    return ['minor' => 'blue', 'moderate' => 'orange', 'major' => 'red'][$sev] ?? 'gray';
}
function statusPill(string $st): string
{
    return ['recorded' => 'orange', 'reviewed' => 'blue', 'resolved' => 'green'][$st] ?? 'gray';
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
        // ---------------- CREATE ----------------
        if ($action === 'create') {
            $studentId      = (int)($_POST['student_id'] ?? 0);
            $yearId         = (int)($_POST['academic_year_id'] ?? 0);
            $termId         = (int)($_POST['academic_term_id'] ?? 0);
            $categoryId     = (int)($_POST['category_id'] ?? 0);
            $severity       = trim((string)($_POST['severity'] ?? 'minor'));
            $incidentDate   = trim((string)($_POST['incident_date'] ?? ''));
            $incidentTime   = trim((string)($_POST['incident_time'] ?? ''));
            $location       = trim((string)($_POST['location'] ?? ''));
            $description    = trim((string)($_POST['description'] ?? ''));
            $actionTaken    = trim((string)($_POST['action_taken'] ?? ''));
            $suspStart      = trim((string)($_POST['suspension_start'] ?? ''));
            $suspEnd        = trim((string)($_POST['suspension_end'] ?? ''));
            $guardianNot    = !empty($_POST['guardian_notified']) ? 1 : 0;
            $notes          = trim((string)($_POST['notes'] ?? ''));

            if ($studentId <= 0) throw new Exception('Student is required.');
            if ($yearId <= 0)    throw new Exception('Academic year is required.');
            if ($categoryId <= 0) throw new Exception('Category is required.');
            if ($incidentDate === '' || !strtotime($incidentDate)) throw new Exception('A valid incident date is required.');
            if (!in_array($severity, ['minor', 'moderate', 'major'], true)) $severity = 'minor';
            if ($suspStart !== '' && $suspEnd !== '' && strtotime($suspEnd) < strtotime($suspStart)) {
                throw new Exception('Suspension end must be on or after suspension start.');
            }

            $stu = $db->fetchOne(
                "SELECT id, first_name, middle_name, last_name FROM students WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$studentId, $tenantId]
            );
            if (!$stu) throw new Exception('Student not found.');

            $cat = $db->fetchOne(
                "SELECT id, category_name FROM discipline_categories WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$categoryId, $tenantId]
            );
            if (!$cat) throw new Exception('Category not found.');

            $yr = $db->fetchOne(
                "SELECT id FROM academic_years WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$yearId, $tenantId]
            );
            if (!$yr) throw new Exception('Academic year not found.');

            if ($termId > 0) {
                $t = $db->fetchOne(
                    "SELECT id FROM academic_terms WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$termId, $tenantId]
                );
                if (!$t) $termId = 0;
            }

            $offeringRow = $db->fetchOne(
                "SELECT class_offering_id FROM enrollments
                 WHERE tenant_id = ? AND student_id = ? AND academic_year_id = ?
                   AND status = 'active' AND deleted_at IS NULL
                 ORDER BY id ASC LIMIT 1",
                [$tenantId, $studentId, $yearId]
            );
            $offeringId = $offeringRow ? (int)$offeringRow['class_offering_id'] : null;

            $db->beginTransaction();

            $newId = (int)$db->insert(
                "INSERT INTO student_discipline
                    (uuid, tenant_id, student_id, class_offering_id,
                     academic_year_id, academic_term_id,
                     category_id, severity, incident_date, incident_time, location,
                     description, action_taken, suspension_start, suspension_end,
                     reported_by, status, guardian_notified, guardian_notified_at,
                     notes, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'recorded', ?, ?, ?, ?, NOW(), NOW())",
                [
                    uuidv4(),
                    $tenantId,
                    $studentId,
                    $offeringId,
                    $yearId,
                    $termId > 0 ? $termId : null,
                    $categoryId,
                    $severity,
                    $incidentDate,
                    $incidentTime !== '' ? $incidentTime : null,
                    $location !== '' ? $location : null,
                    $description !== '' ? $description : null,
                    $actionTaken !== '' ? $actionTaken : null,
                    $suspStart !== '' ? $suspStart : null,
                    $suspEnd   !== '' ? $suspEnd   : null,
                    $userId ?: null,
                    $guardianNot,
                    $guardianNot ? date('Y-m-d H:i:s') : null,
                    $notes !== '' ? $notes : null,
                    $userId ?: null,
                ]
            );

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.discipline.created',
                'student_discipline',
                $newId,
                [
                    'student_id'  => $studentId,
                    'category_id' => $categoryId,
                    'severity'    => $severity,
                    'incident_date' => $incidentDate,
                    'suspended'   => ($suspStart !== '' && $suspEnd !== '') ? 1 : 0,
                ]
            );

            // ---------------------------------------------------------------
            // P2 — Notify class teacher + head teachers on every incident.
            //      Notify guardian only for MAJOR incidents (PR2C).
            // ---------------------------------------------------------------
            try {
                require_once $projectRoot . '/app/helpers/NotificationHelper.php';

                $stuName = trim(($stu['first_name'] ?? '') . ' ' . ($stu['middle_name'] ?? '') . ' ' . ($stu['last_name'] ?? ''));
                if ($stuName === '') $stuName = 'Student #' . $studentId;

                $catName = $cat['category_name'] ?? 'incident';

                $staffIds = [];
                $t = NotificationHelper::classTeacherForStudent($db, $tenantId, $studentId, $yearId, $termId);
                if ($t) $staffIds[] = $t;
                foreach (NotificationHelper::headTeachers($db, $tenantId) as $hid) $staffIds[] = $hid;
                $staffIds = array_values(array_unique(array_filter($staffIds)));

                $guardianIds = [];
                if ($severity === 'major') {
                    $g = NotificationHelper::guardianForStudent($db, $tenantId, $studentId);
                    if ($g) $guardianIds[] = $g;
                }

                NotificationHelper::notify($db, $tenantId, [
                    'recipients'   => ['staff' => $staffIds, 'guardians' => $guardianIds],
                    'type'         => 'discipline',
                    'priority'     => $severity === 'major' ? 'high' : 'normal',
                    'title'        => 'New ' . ucfirst($severity) . ' discipline incident',
                    'message'      => $catName . ' recorded for ' . $stuName . ' on ' . $incidentDate . '.',
                    'link'         => '/platform/tenant/students/discipline.php?action=edit&id=' . $newId,
                    'related_type' => 'student_discipline',
                    'related_id'   => $newId,
                    'created_by'   => $userId,
                ]);
            } catch (Exception $notifyEx) {
                error_log('P2 notify failed: ' . $notifyEx->getMessage());
            }

            $db->commit();

            $_SESSION['success'] = 'Incident recorded.';
            header('Location: /platform/tenant/students/discipline.php');
            exit;
        }

        // ---------------- UPDATE ----------------
        if ($action === 'update') {
            $id             = (int)($_POST['id'] ?? 0);
            $categoryId     = (int)($_POST['category_id'] ?? 0);
            $severity       = trim((string)($_POST['severity'] ?? 'minor'));
            $incidentDate   = trim((string)($_POST['incident_date'] ?? ''));
            $incidentTime   = trim((string)($_POST['incident_time'] ?? ''));
            $location       = trim((string)($_POST['location'] ?? ''));
            $description    = trim((string)($_POST['description'] ?? ''));
            $actionTaken    = trim((string)($_POST['action_taken'] ?? ''));
            $suspStart      = trim((string)($_POST['suspension_start'] ?? ''));
            $suspEnd        = trim((string)($_POST['suspension_end'] ?? ''));
            $guardianNot    = !empty($_POST['guardian_notified']) ? 1 : 0;
            $notes          = trim((string)($_POST['notes'] ?? ''));

            if ($id <= 0) throw new Exception('Incident id is required.');
            if ($categoryId <= 0) throw new Exception('Category is required.');
            if ($incidentDate === '' || !strtotime($incidentDate)) throw new Exception('A valid incident date is required.');
            if (!in_array($severity, ['minor', 'moderate', 'major'], true)) $severity = 'minor';
            if ($suspStart !== '' && $suspEnd !== '' && strtotime($suspEnd) < strtotime($suspStart)) {
                throw new Exception('Suspension end must be on or after suspension start.');
            }

            $row = $db->fetchOne(
                "SELECT * FROM student_discipline WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Incident not found.');

            $cat = $db->fetchOne(
                "SELECT id FROM discipline_categories WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$categoryId, $tenantId]
            );
            if (!$cat) throw new Exception('Category not found.');

            $db->beginTransaction();

            $gnStamp = $row['guardian_notified_at'];
            if ($guardianNot === 1 && (int)$row['guardian_notified'] === 0) {
                $gnStamp = date('Y-m-d H:i:s');
            } elseif ($guardianNot === 0) {
                $gnStamp = null;
            }

            $db->execute(
                "UPDATE student_discipline
                    SET category_id = ?, severity = ?,
                        incident_date = ?, incident_time = ?, location = ?,
                        description = ?, action_taken = ?,
                        suspension_start = ?, suspension_end = ?,
                        guardian_notified = ?, guardian_notified_at = ?,
                        notes = ?, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [
                    $categoryId,
                    $severity,
                    $incidentDate,
                    $incidentTime !== '' ? $incidentTime : null,
                    $location !== '' ? $location : null,
                    $description !== '' ? $description : null,
                    $actionTaken !== '' ? $actionTaken : null,
                    $suspStart !== '' ? $suspStart : null,
                    $suspEnd   !== '' ? $suspEnd   : null,
                    $guardianNot,
                    $gnStamp,
                    $notes !== '' ? $notes : null,
                    $id,
                    $tenantId,
                ]
            );

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.discipline.updated',
                'student_discipline',
                $id,
                ['severity' => $severity, 'category_id' => $categoryId, 'incident_date' => $incidentDate]
            );
            $db->commit();

            $_SESSION['success'] = 'Incident updated.';
            header('Location: /platform/tenant/students/discipline.php');
            exit;
        }

        // ---------------- REVIEW ----------------
        if ($action === 'review') {
            $id = (int)($_POST['id'] ?? 0);
            $row = $db->fetchOne(
                "SELECT * FROM student_discipline WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Incident not found.');
            if ($row['status'] !== 'recorded') {
                $_SESSION['success'] = 'Incident is already reviewed or resolved.';
                header('Location: /platform/tenant/students/discipline.php');
                exit;
            }

            $db->beginTransaction();
            $db->execute(
                "UPDATE student_discipline
                    SET status = 'reviewed', reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$userId ?: null, $id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.discipline.reviewed',
                'student_discipline',
                $id,
                ['previous_status' => 'recorded']
            );
            $db->commit();

            $_SESSION['success'] = 'Incident marked reviewed.';
            header('Location: /platform/tenant/students/discipline.php');
            exit;
        }

        // ---------------- RESOLVE ----------------
        if ($action === 'resolve') {
            $id   = (int)($_POST['id'] ?? 0);
            $note = trim((string)($_POST['resolution_notes'] ?? ''));

            $row = $db->fetchOne(
                "SELECT * FROM student_discipline WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Incident not found.');
            if ($row['status'] === 'resolved') {
                $_SESSION['success'] = 'Incident is already resolved.';
                header('Location: /platform/tenant/students/discipline.php');
                exit;
            }

            $db->beginTransaction();
            $db->execute(
                "UPDATE student_discipline
                    SET status = 'resolved', resolved_by = ?, resolved_at = NOW(),
                        resolution_notes = ?, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$userId ?: null, $note !== '' ? $note : null, $id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.discipline.resolved',
                'student_discipline',
                $id,
                ['previous_status' => $row['status'], 'notes' => $note]
            );
            $db->commit();

            $_SESSION['success'] = 'Incident resolved.';
            header('Location: /platform/tenant/students/discipline.php');
            exit;
        }

        // ---------------- DELETE ----------------
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $row = $db->fetchOne(
                "SELECT * FROM student_discipline WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Incident not found.');

            $db->beginTransaction();
            $db->execute(
                "UPDATE student_discipline SET deleted_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.discipline.deleted',
                'student_discipline',
                $id,
                ['student_id' => (int)$row['student_id'], 'severity' => $row['severity']]
            );
            $db->commit();

            $_SESSION['success'] = 'Incident deleted.';
            header('Location: /platform/tenant/students/discipline.php');
            exit;
        }

        // ---------------- CATEGORY (quick create) ----------------
        if ($action === 'create_category') {
            $name     = trim((string)($_POST['category_name'] ?? ''));
            $code     = trim((string)($_POST['category_code'] ?? ''));
            $default  = trim((string)($_POST['default_severity'] ?? 'minor'));

            if ($name === '') throw new Exception('Category name is required.');
            if (!in_array($default, ['minor', 'moderate', 'major'], true)) $default = 'minor';

            $dup = $db->fetchOne(
                "SELECT id FROM discipline_categories
                 WHERE tenant_id = ? AND category_name = ? AND deleted_at IS NULL",
                [$tenantId, $name]
            );
            if ($dup) throw new Exception('A category with that name already exists.');

            $db->beginTransaction();
            $newId = (int)$db->insert(
                "INSERT INTO discipline_categories
                    (uuid, tenant_id, category_name, category_code, default_severity,
                     sort_order, is_active, is_system, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 500, 1, 0, ?, NOW(), NOW())",
                [
                    uuidv4(),
                    $tenantId,
                    $name,
                    $code !== '' ? $code : null,
                    $default,
                    $userId ?: null,
                ]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.discipline.category_created',
                'discipline_categories',
                $newId,
                ['name' => $name, 'default_severity' => $default]
            );
            $db->commit();

            $_SESSION['success'] = 'Category created.';
            header('Location: /platform/tenant/students/discipline.php');
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Discipline action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/students/discipline.php');
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

$filterYear      = (int)($_GET['year_id'] ?? 0);
$filterClass     = (int)($_GET['class_id'] ?? 0);
$filterStudent   = (int)($_GET['student_id'] ?? 0);
$filterCategory  = (int)($_GET['category_id'] ?? 0);
$filterSeverity  = trim((string)($_GET['severity'] ?? ''));
$filterStatus    = trim((string)($_GET['status'] ?? ''));
$filterFrom      = trim((string)($_GET['from_date'] ?? ''));
$filterTo        = trim((string)($_GET['to_date'] ?? ''));

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
if ($filterYear <= 0 && !empty($years)) $filterYear = (int)$years[0]['id'];

$classes = $db->fetchAll(
    "SELECT c.id, c.class_name, c.class_code,
            al.level_name, al.sort_order AS level_sort
     FROM classes c
     LEFT JOIN academic_levels al ON c.school_id = al.school_id
     WHERE c.tenant_id = ? AND c.deleted_at IS NULL
     ORDER BY al.sort_order ASC, c.class_name ASC",
    [$tenantId]
);

$categories = $db->fetchAll(
    "SELECT * FROM discipline_categories
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY sort_order ASC, category_name ASC",
    [$tenantId]
);

$filterStudentLabel = '';
if ($filterStudent > 0) {
    $fs = $db->fetchOne(
        "SELECT first_name, middle_name, last_name, student_number
         FROM students WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$filterStudent, $tenantId]
    );
    if ($fs) {
        $nm = trim(($fs['first_name'] ?? '') . ' ' . ($fs['middle_name'] ?? '') . ' ' . ($fs['last_name'] ?? ''));
        $filterStudentLabel = $nm . ' — ' . (string)$fs['student_number'];
    }
}

$where = ["d.tenant_id = ?", "d.deleted_at IS NULL"];
$params = [$tenantId];

if ($filterYear > 0) {
    $where[] = "d.academic_year_id = ?";
    $params[] = $filterYear;
}
if ($filterStudent > 0) {
    $where[] = "d.student_id = ?";
    $params[] = $filterStudent;
}
if ($filterCategory > 0) {
    $where[] = "d.category_id = ?";
    $params[] = $filterCategory;
}
if (in_array($filterSeverity, ['minor', 'moderate', 'major'], true)) {
    $where[] = "d.severity = ?";
    $params[] = $filterSeverity;
}
if (in_array($filterStatus, ['recorded', 'reviewed', 'resolved'], true)) {
    $where[] = "d.status = ?";
    $params[] = $filterStatus;
}
if ($filterFrom !== '' && strtotime($filterFrom)) {
    $where[] = "d.incident_date >= ?";
    $params[] = $filterFrom;
}
if ($filterTo !== '' && strtotime($filterTo)) {
    $where[] = "d.incident_date <= ?";
    $params[] = $filterTo;
}
if ($filterClass > 0) {
    $where[] = "EXISTS (
        SELECT 1 FROM class_offerings co
        WHERE co.id = d.class_offering_id
          AND co.class_id = ?
          AND co.deleted_at IS NULL
    )";
    $params[] = $filterClass;
}
$whereClause = implode(' AND ', $where);

$incidents = $db->fetchAll(
    "SELECT d.*,
            s.student_number, s.first_name, s.middle_name, s.last_name, s.preferred_name,
            c.category_name, c.category_code,
            al.level_name, c2.class_name, c2.class_code,
            ay.year_name, t.term_name
     FROM student_discipline d
     JOIN students s ON d.student_id = s.id
     LEFT JOIN discipline_categories c ON d.category_id = c.id
     LEFT JOIN class_offerings co ON d.class_offering_id = co.id
     LEFT JOIN classes c2 ON co.class_id = c2.id
     LEFT JOIN academic_levels al ON co.academic_level_id = al.id
     LEFT JOIN academic_years ay ON d.academic_year_id = ay.id
     LEFT JOIN academic_terms t ON d.academic_term_id = t.id
     WHERE $whereClause
     ORDER BY d.incident_date DESC, d.id DESC
     LIMIT 500",
    $params
);

$byLevel = [];
foreach ($incidents as $r) {
    $key = $r['level_name'] ?: 'No level';
    if (!isset($byLevel[$key])) {
        $byLevel[$key] = ['level_name' => $key, 'classes' => []];
    }
    $ckey = $r['class_name'] ?: 'No class';
    if (!isset($byLevel[$key]['classes'][$ckey])) {
        $byLevel[$key]['classes'][$ckey] = ['class_name' => $ckey, 'class_code' => $r['class_code'] ?? '', 'items' => []];
    }
    $byLevel[$key]['classes'][$ckey]['items'][] = $r;
}

$totalIncidents = count($incidents);
$byStatus = ['recorded' => 0, 'reviewed' => 0, 'resolved' => 0];
$bySeverity = ['minor' => 0, 'moderate' => 0, 'major' => 0];
$withSuspension = 0;
foreach ($incidents as $r) {
    if (isset($byStatus[$r['status']])) $byStatus[$r['status']]++;
    if (isset($bySeverity[$r['severity']])) $bySeverity[$r['severity']]++;
    if ($r['suspension_start'] !== null && $r['suspension_end'] !== null) $withSuspension++;
}

$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

$editRow = null;
if (($_GET['action'] ?? '') === 'edit' && !empty($_GET['id'])) {
    $editRow = $db->fetchOne(
        "SELECT * FROM student_discipline
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [(int)$_GET['id'], $tenantId]
    );
}
$showNew = (($_GET['action'] ?? '') === 'new');
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
            max-width: 1300px;
            margin-left: auto;
            margin-right: auto;
        }

        .filters-bar .fg {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 150px;
            flex: 1;
        }

        .filters-bar .fg label {
            font-size: 12px;
            font-weight: 500;
            color: #1a1a2e;
        }

        .filters-bar .form-select,
        .filters-bar .form-control {
            height: 38px;
            font-size: 13px;
            border-radius: 8px;
            border: 1.5px solid #e9ecef;
        }

        .student-search-wrap {
            position: relative;
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

        .student-search-clear {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #adb5bd;
            cursor: pointer;
            border: none;
            background: transparent;
            font-size: 14px;
            padding: 0;
        }

        .student-search-clear:hover {
            color: #dc3545;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
            max-width: 1300px;
            margin-left: auto;
            margin-right: auto;
        }

        .stat-cell {
            background: #fff;
            border-radius: 12px;
            padding: 12px 16px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .stat-cell .stat-num {
            font-weight: 700;
            font-size: 20px;
            color: #1a1a2e;
        }

        .stat-cell .stat-lbl {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .level-section {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
            overflow: hidden;
            width: 100%;
            max-width: 1300px;
            margin-left: auto;
            margin-right: auto;
        }

        .level-section .level-header {
            padding: 14px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            background: linear-gradient(135deg, #f8fafc 0%, #eef6ff 100%);
        }

        .level-section .level-header .level-title {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .level-section .level-header .level-title h5 {
            font-weight: 700;
            margin: 0;
            font-size: 17px;
            color: #1a1a2e;
        }

        .class-block {
            border-bottom: 1px solid #f0f2f5;
        }

        .class-block:last-child {
            border-bottom: none;
        }

        .class-block .class-bar {
            padding: 10px 24px;
            background: #fafbfc;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            border-bottom: 1px solid #f0f2f5;
        }

        .class-block .class-bar h6 {
            font-weight: 600;
            margin: 0;
            font-size: 14px;
            color: #1a1a2e;
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

        textarea.form-control {
            height: auto;
            min-height: 80px;
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

            .filters-bar {
                flex-direction: column;
            }

            .filters-bar .fg {
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
        <div class="row g-0">
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>

            <!-- Sidebar (includes app/views/partials/sidebar.php) -->
            <?php include $projectRoot . '/app/views/partials/sidebar.php'; ?>

            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-gavel me-2"></i>Discipline</h1>
                        <p>Record and review student behaviour incidents</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/students/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Students
                        </a>
                        <a href="/platform/tenant/students/discipline.php?action=new" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i> Record Incident
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo h($tenantName); ?></div>
                            <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                <?php echo (int)$totalIncidents; ?> incident(s) match the current filters ·
                                <?php echo (int)$withSuspension; ?> involve a suspension
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>Reviewed feeds behaviour
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
                        <div class="ib-title">How discipline works</div>
                        <div class="ib-text">
                            Every incident is logged against one student on one date. It moves through three states:
                            <strong>recorded</strong> (just logged), <strong>reviewed</strong> (checked by a senior staff member),
                            and <strong>resolved</strong> (closed, with resolution notes). Only <em>reviewed</em> and
                            <em>resolved</em> incidents will feed the behaviour module and, ultimately, the promotion run.
                            Categories are editable — use the button in the filter bar to add your own.
                        </div>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-cell">
                        <div class="stat-num"><?php echo (int)$totalIncidents; ?></div>
                        <div class="stat-lbl">Incidents</div>
                    </div>
                    <div class="stat-cell">
                        <div class="stat-num"><?php echo (int)$byStatus['recorded']; ?></div>
                        <div class="stat-lbl">Recorded</div>
                    </div>
                    <div class="stat-cell">
                        <div class="stat-num"><?php echo (int)$byStatus['reviewed']; ?></div>
                        <div class="stat-lbl">Reviewed</div>
                    </div>
                    <div class="stat-cell">
                        <div class="stat-num"><?php echo (int)$byStatus['resolved']; ?></div>
                        <div class="stat-lbl">Resolved</div>
                    </div>
                    <div class="stat-cell">
                        <div class="stat-num"><?php echo (int)$bySeverity['major']; ?></div>
                        <div class="stat-lbl">Major</div>
                    </div>
                    <div class="stat-cell">
                        <div class="stat-num"><?php echo (int)$withSuspension; ?></div>
                        <div class="stat-lbl">Suspensions</div>
                    </div>
                </div>

                <form method="GET" action="/platform/tenant/students/discipline.php" class="filters-bar" id="disciplineFilterForm">
                    <input type="hidden" name="student_id" id="filterStudentId" value="<?php echo (int)$filterStudent; ?>">

                    <div class="fg">
                        <label><i class="fas fa-filter me-1"></i><?php echo h($labelAcademic); ?></label>
                        <select name="year_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">All</option>
                            <?php foreach ($years as $y): ?>
                                <option value="<?php echo (int)$y['id']; ?>" <?php echo $filterYear === (int)$y['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($y['year_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label><?php echo h($labelClass); ?></label>
                        <select name="class_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">All</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>" <?php echo $filterClass === (int)$c['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($c['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="fg">
                        <label>Student</label>
                        <div class="student-search-wrap">
                            <input type="text" class="form-control" id="filterStudentSearch"
                                autocomplete="off" placeholder="Type name or student number…"
                                value="<?php echo h($filterStudentLabel); ?>">
                            <button type="button" class="student-search-clear" id="filterStudentClear"
                                title="Clear" style="<?php echo $filterStudent > 0 ? '' : 'display:none;'; ?>">
                                <i class="fas fa-times"></i>
                            </button>
                            <div class="student-search-results" id="filterStudentResults"></div>
                        </div>
                    </div>

                    <div class="fg">
                        <label>Category</label>
                        <select name="category_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">All</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo (int)$cat['id']; ?>" <?php echo $filterCategory === (int)$cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($cat['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Severity</label>
                        <select name="severity" class="form-select" onchange="this.form.submit()">
                            <option value="">All</option>
                            <option value="minor" <?php echo $filterSeverity === 'minor'    ? 'selected' : ''; ?>>Minor</option>
                            <option value="moderate" <?php echo $filterSeverity === 'moderate' ? 'selected' : ''; ?>>Moderate</option>
                            <option value="major" <?php echo $filterSeverity === 'major'    ? 'selected' : ''; ?>>Major</option>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Status</label>
                        <select name="status" class="form-select" onchange="this.form.submit()">
                            <option value="">All</option>
                            <option value="recorded" <?php echo $filterStatus === 'recorded' ? 'selected' : ''; ?>>Recorded</option>
                            <option value="reviewed" <?php echo $filterStatus === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                            <option value="resolved" <?php echo $filterStatus === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                        </select>
                    </div>
                    <div class="fg">
                        <label>From</label>
                        <input type="date" name="from_date" class="form-control" value="<?php echo h($filterFrom); ?>">
                    </div>
                    <div class="fg">
                        <label>To</label>
                        <input type="date" name="to_date" class="form-control" value="<?php echo h($filterTo); ?>">
                    </div>
                    <div class="fg" style="flex:0 0 auto;">
                        <button type="submit" class="btn btn-outline-primary btn-sm">Apply</button>
                    </div>
                    <div class="fg" style="flex:0 0 auto;">
                        <a href="/platform/tenant/students/discipline.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                    </div>
                    <div class="fg" style="flex:0 0 auto;">
                        <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#categoryModal">
                            <i class="fas fa-plus me-1"></i> Add category
                        </button>
                    </div>
                </form>

                <?php if (empty($byLevel)): ?>
                    <div class="level-section">
                        <div class="empty-state">
                            <i class="fas fa-gavel"></i>
                            <h5>No incidents match the current filters</h5>
                            <p>Click <strong>Record Incident</strong> above to log the first one.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($byLevel as $levelGroup): ?>
                        <div class="level-section">
                            <div class="level-header">
                                <div class="level-title">
                                    <i class="fas fa-layer-group" style="font-size:18px;color:#4facfe;"></i>
                                    <h5><?php echo h($levelGroup['level_name']); ?></h5>
                                    <?php
                                    $lCount = 0;
                                    foreach ($levelGroup['classes'] as $cg) $lCount += count($cg['items']);
                                    ?>
                                    <span class="pill blue"><?php echo $lCount; ?> incident(s)</span>
                                </div>
                            </div>
                            <?php foreach ($levelGroup['classes'] as $classGroup): ?>
                                <div class="class-block">
                                    <div class="class-bar">
                                        <i class="fas fa-door-open" style="color:#4facfe;"></i>
                                        <h6><?php echo h($classGroup['class_name']); ?></h6>
                                        <?php if (!empty($classGroup['class_code'])): ?>
                                            <span class="pill blue"><?php echo h($classGroup['class_code']); ?></span>
                                        <?php endif; ?>
                                        <span class="pill gray"><?php echo count($classGroup['items']); ?></span>
                                    </div>
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th style="width:110px;">Date</th>
                                                <th>Student</th>
                                                <th style="width:180px;">Category</th>
                                                <th style="width:100px;text-align:center;">Severity</th>
                                                <th style="width:160px;">Suspension</th>
                                                <th style="width:110px;text-align:center;">Status</th>
                                                <th style="text-align:right;min-width:220px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($classGroup['items'] as $r):
                                                $rid = (int)$r['id'];
                                                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                                                if ($name === '') $name = 'Student #' . (int)$r['student_id'];
                                                $suspensionText = '';
                                                if ($r['suspension_start'] !== null && $r['suspension_end'] !== null) {
                                                    $suspensionText = $r['suspension_start'] . ' → ' . $r['suspension_end'];
                                                }
                                            ?>
                                                <tr>
                                                    <td>
                                                        <span style="font-family:'Courier New',monospace;font-size:12px;">
                                                            <?php echo h($r['incident_date']); ?>
                                                        </span>
                                                        <?php if (!empty($r['incident_time'])): ?>
                                                            <div style="font-size:11px;color:#6c757d;">
                                                                <?php echo h(substr((string)$r['incident_time'], 0, 5)); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div style="font-weight:600;color:#1a1a2e;"><?php echo h($name); ?></div>
                                                        <div style="font-size:11px;color:#6c757d;font-family:'Courier New',monospace;">
                                                            <?php echo h($r['student_number']); ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="pill purple"><?php echo h($r['category_name'] ?: '—'); ?></span>
                                                        <?php if (!empty($r['location'])): ?>
                                                            <div style="font-size:11px;color:#6c757d;margin-top:2px;">
                                                                <i class="fas fa-map-marker-alt me-1"></i><?php echo h($r['location']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <span class="pill <?php echo severityPill($r['severity']); ?>">
                                                            <?php echo h(ucfirst($r['severity'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($suspensionText !== ''): ?>
                                                            <span class="pill red"><?php echo h($suspensionText); ?></span>
                                                        <?php else: ?>
                                                            <span style="color:#adb5bd;">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <span class="pill <?php echo statusPill($r['status']); ?>">
                                                            <?php echo h(ucfirst($r['status'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex justify-content-end gap-1 flex-wrap">
                                                            <a href="/platform/tenant/students/discipline.php?action=edit&id=<?php echo $rid; ?>"
                                                                class="btn btn-outline-primary btn-sm" title="Edit">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                            <?php if ($r['status'] === 'recorded'): ?>
                                                                <form method="POST" style="display:inline-block;"
                                                                    onsubmit="return confirm('Mark this incident as reviewed?');">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="review">
                                                                    <input type="hidden" name="id" value="<?php echo $rid; ?>">
                                                                    <button type="submit" class="btn btn-outline-info btn-sm" title="Mark reviewed">
                                                                        <i class="fas fa-search"></i>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <?php if ($r['status'] !== 'resolved'): ?>
                                                                <button type="button"
                                                                    class="btn btn-outline-success btn-sm js-resolve"
                                                                    data-id="<?php echo $rid; ?>"
                                                                    data-name="<?php echo h($name); ?>"
                                                                    title="Resolve">
                                                                    <i class="fas fa-check"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                            <form method="POST" style="display:inline-block;"
                                                                onsubmit="return confirm('Delete this incident? This soft-deletes it.');">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="id" value="<?php echo $rid; ?>">
                                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <?php
    $modal = $editRow ?: null;
    $isEdit = (bool)$editRow;
    $showModal = $isEdit || $showNew;

    $editStudentLabel = '';
    if ($isEdit) {
        $editStu = $db->fetchOne(
            "SELECT first_name, middle_name, last_name, student_number
             FROM students WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [(int)$editRow['student_id'], $tenantId]
        );
        if ($editStu) {
            $nmEdit = trim(($editStu['first_name'] ?? '') . ' ' . ($editStu['middle_name'] ?? '') . ' ' . ($editStu['last_name'] ?? ''));
            $editStudentLabel = $nmEdit . ' — ' . (string)$editStu['student_number'];
        }
    }
    ?>
    <div class="modal fade<?php echo $showModal ? ' show' : ''; ?>" id="incidentModal" tabindex="-1"
        style="<?php echo $showModal ? 'display:block;background:rgba(0,0,0,0.4);' : ''; ?>">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/students/discipline.php" id="incidentForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?php echo $isEdit ? 'update' : 'create'; ?>">
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
                    <?php endif; ?>
                    <div class="modal-header">
                        <h5>
                            <i class="fas fa-gavel text-primary me-2"></i>
                            <?php echo $isEdit ? 'Edit Incident' : 'Record Incident'; ?>
                        </h5>
                        <a href="/platform/tenant/students/discipline.php" class="btn-close"></a>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <?php if (!$isEdit): ?>
                                <div class="col-md-6">
                                    <label class="form-label" for="m_student_search">Student <span class="text-danger">*</span></label>
                                    <div class="student-search-wrap">
                                        <input type="text"
                                            class="form-control"
                                            id="m_student_search"
                                            autocomplete="off"
                                            placeholder="Type at least 2 letters…"
                                            required>
                                        <input type="hidden" name="student_id" id="m_student_id" value="">
                                        <div class="student-search-results" id="m_student_results"></div>
                                    </div>
                                    <div style="font-size:11px;color:#6c757d;margin-top:4px;">
                                        Search by name or student number.
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="m_year"><?php echo h($labelAcademic); ?> <span class="text-danger">*</span></label>
                                    <select class="form-select" id="m_year" name="academic_year_id" required>
                                        <?php foreach ($years as $y): ?>
                                            <option value="<?php echo (int)$y['id']; ?>" <?php echo $filterYear === (int)$y['id'] ? 'selected' : ''; ?>>
                                                <?php echo h($y['year_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php else: ?>
                                <div class="col-md-6">
                                    <label class="form-label">Student</label>
                                    <input type="text" class="form-control" readonly
                                        value="<?php echo h($editStudentLabel); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo h($labelAcademic); ?></label>
                                    <input type="text" class="form-control" readonly
                                        value="<?php
                                                $yrName = '';
                                                foreach ($years as $y) {
                                                    if ((int)$y['id'] === (int)$editRow['academic_year_id']) {
                                                        $yrName = $y['year_name'];
                                                        break;
                                                    }
                                                }
                                                echo h($yrName);
                                                ?>">
                                </div>
                            <?php endif; ?>

                            <div class="col-md-6">
                                <label class="form-label" for="m_term"><?php echo h($labelTerm); ?></label>
                                <select class="form-select" id="m_term" name="academic_term_id">
                                    <option value="0">(None)</option>
                                    <?php foreach ($terms as $t): ?>
                                        <option value="<?php echo (int)$t['id']; ?>"
                                            <?php
                                            if ($isEdit) echo ((int)$editRow['academic_term_id'] === (int)$t['id']) ? 'selected' : '';
                                            else echo ((int)$t['is_current'] === 1) ? 'selected' : '';
                                            ?>>
                                            <?php echo h($t['term_name']); ?>
                                            <?php if ((int)$t['is_current'] === 1): ?> ★<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="m_category">Category <span class="text-danger">*</span></label>
                                <select class="form-select" id="m_category" name="category_id" required>
                                    <option value="">— Pick a category —</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo (int)$cat['id']; ?>"
                                            data-default-severity="<?php echo h($cat['default_severity']); ?>"
                                            <?php echo ($isEdit && (int)$editRow['category_id'] === (int)$cat['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($cat['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="m_severity">Severity <span class="text-danger">*</span></label>
                                <select class="form-select" id="m_severity" name="severity" required>
                                    <option value="minor" <?php echo $isEdit && $editRow['severity'] === 'minor'    ? 'selected' : ''; ?>>Minor</option>
                                    <option value="moderate" <?php echo $isEdit && $editRow['severity'] === 'moderate' ? 'selected' : ''; ?>>Moderate</option>
                                    <option value="major" <?php echo $isEdit && $editRow['severity'] === 'major'    ? 'selected' : ''; ?>>Major</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="m_date">Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="m_date" name="incident_date" required
                                    value="<?php echo $isEdit ? h($editRow['incident_date']) : h(date('Y-m-d')); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="m_time">Time</label>
                                <input type="time" class="form-control" id="m_time" name="incident_time"
                                    value="<?php echo $isEdit && !empty($editRow['incident_time']) ? h(substr($editRow['incident_time'], 0, 5)) : ''; ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="m_location">Location</label>
                                <input type="text" class="form-control" id="m_location" name="location" maxlength="120"
                                    value="<?php echo $isEdit ? h($editRow['location']) : ''; ?>"
                                    placeholder="e.g. Classroom, playground">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="m_susp_start">Suspension start</label>
                                <input type="date" class="form-control" id="m_susp_start" name="suspension_start"
                                    value="<?php echo $isEdit ? h($editRow['suspension_start']) : ''; ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="m_susp_end">Suspension end</label>
                                <input type="date" class="form-control" id="m_susp_end" name="suspension_end"
                                    value="<?php echo $isEdit ? h($editRow['suspension_end']) : ''; ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="m_description">Description</label>
                                <textarea class="form-control" id="m_description" name="description"
                                    placeholder="What happened"><?php echo $isEdit ? h($editRow['description']) : ''; ?></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="m_action">Action taken</label>
                                <textarea class="form-control" id="m_action" name="action_taken"
                                    placeholder="e.g. Verbal warning, parent contacted"><?php echo $isEdit ? h($editRow['action_taken']) : ''; ?></textarea>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="m_guardian" name="guardian_notified" value="1"
                                        <?php echo $isEdit && (int)$editRow['guardian_notified'] === 1 ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="m_guardian">
                                        Guardian has been notified
                                    </label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="m_notes">Internal notes</label>
                                <textarea class="form-control" id="m_notes" name="notes"
                                    placeholder="Visible only to staff"><?php echo $isEdit ? h($editRow['notes']) : ''; ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="/platform/tenant/students/discipline.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> <?php echo $isEdit ? 'Save Changes' : 'Record Incident'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="categoryModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/students/discipline.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_category">
                    <div class="modal-header">
                        <h5><i class="fas fa-plus-circle text-primary me-2"></i>Add Discipline Category</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="cat_name">Category name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="cat_name" name="category_name" maxlength="100" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="cat_code">Short code</label>
                            <input type="text" class="form-control" id="cat_code" name="category_code" maxlength="30">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="cat_sev">Default severity</label>
                            <select class="form-select" id="cat_sev" name="default_severity">
                                <option value="minor" selected>Minor</option>
                                <option value="moderate">Moderate</option>
                                <option value="major">Major</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Add Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="resolveModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/students/discipline.php" id="resolveForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="resolve">
                    <input type="hidden" name="id" id="resolveId" value="">
                    <div class="modal-header">
                        <h5><i class="fas fa-check-circle text-success me-2"></i>Resolve Incident</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p style="font-size:13px;color:#495057;">
                            Marking this incident as resolved for <strong id="resolveName">the student</strong>.
                        </p>
                        <div class="mb-3">
                            <label class="form-label" for="resNotes">Resolution notes</label>
                            <textarea class="form-control" id="resNotes" name="resolution_notes"
                                placeholder="How was this resolved?"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i> Resolve</button>
                    </div>
                </form>
            </div>
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

        <?php if ($showModal): ?>
                (function() {
                    const el = document.getElementById('incidentModal');
                    if (el && el.classList.contains('show')) {
                        // Already visible via inline style; no JS needed
                    }
                })();
        <?php endif; ?>

            (function() {
                const cat = document.getElementById('m_category');
                const sev = document.getElementById('m_severity');
                if (!cat || !sev) return;
                cat.addEventListener('change', function() {
                    const opt = this.options[this.selectedIndex];
                    const def = opt.getAttribute('data-default-severity');
                    if (def) sev.value = def;
                });
            })();

        (function() {
            const el = document.getElementById('resolveModal');
            const modal = new bootstrap.Modal(el);
            document.querySelectorAll('.js-resolve').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.getElementById('resolveId').value = this.getAttribute('data-id') || '';
                    document.getElementById('resolveName').textContent = this.getAttribute('data-name') || 'the student';
                    document.getElementById('resNotes').value = '';
                    modal.show();
                });
            });
        })();

        function escapeHtml(s) {
            if (s === null || s === undefined) return '';
            return String(s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function makeStudentSearch(opts) {
            const input = document.getElementById(opts.inputId);
            const resultsEl = document.getElementById(opts.resultsId);
            const hiddenInput = opts.hiddenId ? document.getElementById(opts.hiddenId) : null;
            if (!input || !resultsEl) return null;

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
                    `<div class="ssr-item" data-idx="${i}" data-id="${it.id}" data-label="${escapeHtml(it.label)}">
                        <div class="ssr-name">${escapeHtml(it.name)}</div>
                        <div class="ssr-num">${escapeHtml(it.num)}</div>
                    </div>`
                ).join('');
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
                input.value = item.label;
                if (hiddenInput) hiddenInput.value = item.id;
                close();
                if (typeof opts.onPick === 'function') opts.onPick(item);
            }

            function fetchResults(q) {
                if (q === lastQuery) return;
                lastQuery = q;
                if (abortCtrl) abortCtrl.abort();
                abortCtrl = new AbortController();
                const myToken = ++reqToken;
                fetch('/platform/tenant/students/discipline.php?ajax_search_students=1&q=' + encodeURIComponent(q), {
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
                if (hiddenInput) hiddenInput.value = '';
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
            return {
                setValue(label, id) {
                    input.value = label || '';
                    if (hiddenInput) hiddenInput.value = id || '';
                }
            };
        }

        (function() {
            const form = document.getElementById('disciplineFilterForm');
            const hiddenId = document.getElementById('filterStudentId');
            const clearBtn = document.getElementById('filterStudentClear');
            if (!form || !hiddenId) return;
            makeStudentSearch({
                inputId: 'filterStudentSearch',
                resultsId: 'filterStudentResults',
                onPick: function(item) {
                    hiddenId.value = item.id;
                    form.submit();
                }
            });
            if (clearBtn) {
                clearBtn.addEventListener('click', function() {
                    document.getElementById('filterStudentSearch').value = '';
                    hiddenId.value = '0';
                    form.submit();
                });
            }
        })();

        (function() {
            const searchInput = document.getElementById('m_student_search');
            const hiddenInput = document.getElementById('m_student_id');
            if (!searchInput || !hiddenInput) return;
            makeStudentSearch({
                inputId: 'm_student_search',
                resultsId: 'm_student_results',
                hiddenId: 'm_student_id'
            });
        })();

        (function() {
            const form = document.getElementById('incidentForm');
            const hid = document.getElementById('m_student_id');
            if (!form || !hid) return;
            form.addEventListener('submit', function(e) {
                if (hid && hid.value === '') {
                    e.preventDefault();
                    alert('Please pick a student from the search results.');
                    document.getElementById('m_student_search').focus();
                }
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