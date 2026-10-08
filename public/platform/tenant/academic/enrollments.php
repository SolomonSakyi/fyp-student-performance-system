<?php

/**
 * Academic Enrollments — Students in class offerings
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Academic
 * @version 1.0
 * @filepath public/platform/tenant/academic/enrollments.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Academic enrollments file of the academic-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'academic_enrollments'
 *       to 'academic' so the partial marks Academic active and
 *       renders the academic sub-menu on this page — consistent
 *       with every other academic file.
 *     - A v1.0 [SWEEP] entry was added above this docblock, and
 *       @version was reduced from 1.3 to 1.0 per Decision X-3.
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
 * Decisions locked in (Session S13):
 *   D1  student_id + class_offering_id + enrolled_at + status required;
 *       academic_year_id derived from offering;
 *       term_id, roll_number, left_at, notes optional
 *   D2  unique per (tenant, student_id, class_offering_id) for active rows
 *       via FOR UPDATE
 *   D3  no feature gate
 *   D4  no orphans
 *   D5  enforce one active enrollment per (tenant, student, academic_year)
 *   D6  hard-block capacity, with force=1 override option
 *   D7  refuse delete if results exist
 *   D8  offering-centric grouping + student filter
 *   D9  multi-select student picker inside offering modal
 *   D10 auto-assign next sequential roll number if blank
 *   D11 unique roll number per (tenant, offering) for active rows
 *   D12 full sync of students.current_enrollment_id and students.class_id
 *   D13 create new enrollment row on re-enrollment (preserve history)
 *   D14 auto-fill term with current term if not provided
 *
 * Session S20 decisions applied:
 *   SH2A per-session CSRF token; all POSTs carry it (including JS-built forms)
 *   SH3A central Security.php helper, loaded via app/bootstrap.php
 *   SH4A hardened session started inside bootstrap
 *   SH5C h() on every echoed value
 *   SH6B tenant_id scoping and deleted_at IS NULL on every query
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

$pageTitle   = 'Enrollments - Student 360 Platform';
$currentPage = 'academic';

// ============================================
// HELPERS
// ============================================
// h() is defined in Security.php; do not redeclare it here.

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

function getOffering($db, int $tenantId, int $offeringId): ?array
{
    if ($offeringId <= 0) return null;
    return $db->fetchOne(
        "SELECT co.id, co.tenant_id, co.class_id, co.academic_level_id, co.academic_year_id,
                co.stream_id, co.status, co.capacity,
                c.class_name, c.class_code,
                al.level_name, al.level_code,
                ay.year_name,
                s.stream_name
         FROM class_offerings co
         LEFT JOIN classes c ON co.class_id = c.id
         LEFT JOIN academic_levels al ON co.academic_level_id = al.id
         LEFT JOIN academic_years ay ON co.academic_year_id = ay.id
         LEFT JOIN streams s ON co.stream_id = s.id
         WHERE co.id = ? AND co.tenant_id = ? AND co.deleted_at IS NULL",
        [$offeringId, $tenantId]
    ) ?: null;
}

function getCurrentTerm($db, int $tenantId): ?int
{
    try {
        $row = $db->fetchOne(
            "SELECT id FROM academic_terms
             WHERE tenant_id = ? AND is_current = 1 AND is_active = 1 AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
            [$tenantId]
        );
        return $row ? (int)$row['id'] : null;
    } catch (Exception $e) {
        return null;
    }
}

function activeEnrollmentCount($db, int $tenantId, int $offeringId): int
{
    $row = $db->fetchOne(
        "SELECT COUNT(*) AS c FROM enrollments
         WHERE tenant_id = ? AND class_offering_id = ?
           AND status = 'active' AND deleted_at IS NULL",
        [$tenantId, $offeringId]
    );
    return (int)($row['c'] ?? 0);
}

function enrollmentDependencies($db, int $tenantId, int $enrollmentId): array
{
    $total = 0;
    $labels = [];

    $checks = [
        'results' => "SELECT COUNT(*) AS c FROM results
                      WHERE tenant_id = ? AND enrollment_id = ? AND deleted_at IS NULL",
    ];

    foreach ($checks as $label => $sql) {
        try {
            $row = $db->fetchOne($sql, [$tenantId, $enrollmentId]);
            $c = (int)($row['c'] ?? 0);
            if ($c > 0) {
                $total += $c;
                $labels[] = $c . ' ' . $label;
            }
        } catch (Exception $e) {
            // table not yet present
        }
    }

    return ['total' => $total, 'labels' => $labels];
}

function syncStudentPointers($db, int $tenantId, int $studentId, ?int $activeEnrollmentId = null): void
{
    if ($studentId <= 0) return;

    if ($activeEnrollmentId !== null && $activeEnrollmentId > 0) {
        $row = $db->fetchOne(
            "SELECT e.id, co.class_id
             FROM enrollments e
             JOIN class_offerings co ON e.class_offering_id = co.id
             WHERE e.id = ? AND e.tenant_id = ? AND e.deleted_at IS NULL
               AND e.status = 'active'
             LIMIT 1",
            [$activeEnrollmentId, $tenantId]
        );
        if ($row) {
            $db->execute(
                "UPDATE students
                    SET current_enrollment_id = ?, class_id = ?, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [(int)$row['id'], (int)$row['class_id'], $studentId, $tenantId]
            );
            return;
        }
    }

    $fallback = $db->fetchOne(
        "SELECT e.id, co.class_id
         FROM enrollments e
         JOIN class_offerings co ON e.class_offering_id = co.id
         WHERE e.tenant_id = ? AND e.student_id = ?
           AND e.status = 'active' AND e.deleted_at IS NULL
         ORDER BY e.enrolled_at DESC, e.id DESC
         LIMIT 1",
        [$tenantId, $studentId]
    );

    if ($fallback) {
        $db->execute(
            "UPDATE students
                SET current_enrollment_id = ?, class_id = ?, updated_at = NOW()
              WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [(int)$fallback['id'], (int)$fallback['class_id'], $studentId, $tenantId]
        );
    } else {
        $db->execute(
            "UPDATE students
                SET current_enrollment_id = NULL, updated_at = NOW()
              WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$studentId, $tenantId]
        );
    }
}

function nextRollNumber($db, int $tenantId, int $offeringId): int
{
    $row = $db->fetchOne(
        "SELECT MAX(CAST(roll_number AS UNSIGNED)) AS m
         FROM enrollments
         WHERE tenant_id = ? AND class_offering_id = ?
           AND roll_number IS NOT NULL AND roll_number <> ''",
        [$tenantId, $offeringId]
    );
    $m = (int)($row['m'] ?? 0);
    return $m + 1;
}

// ============================================
// ACTIONS (POST)
// ============================================
$errors   = [];
$formData = [];
$action   = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf(); // S20 SH2A — all POSTs must carry the session CSRF token

    $formData = $_POST;

    try {
        // ---------- BULK SAVE ----------
        if ($action === 'bulk_save') {
            $offeringId = (int)($_POST['class_offering_id'] ?? 0);
            $termId     = (int)($_POST['academic_term_id'] ?? 0);
            $enrolledAt = trim((string)($_POST['enrolled_at'] ?? ''));
            $status     = trim((string)($_POST['status'] ?? 'active'));
            $notes      = trim((string)($_POST['notes'] ?? ''));
            $force      = !empty($_POST['force']) ? 1 : 0;

            $offering = getOffering($db, $tenantId, $offeringId);
            if (!$offering) throw new Exception('Class offering not found.');

            $allowedStatuses = ['active', 'completed', 'transferred', 'withdrawn', 'suspended'];
            if (!in_array($status, $allowedStatuses, true)) {
                throw new Exception('Invalid status.');
            }

            if ($enrolledAt === '') {
                $enrolledAt = date('Y-m-d');
            } elseif (!strtotime($enrolledAt)) {
                throw new Exception('Invalid enrollment date.');
            }

            $yearId = (int)$offering['academic_year_id'];
            if ($yearId <= 0) throw new Exception('This offering has no academic year set.');

            if ($termId <= 0) {
                $termId = getCurrentTerm($db, $tenantId) ?: 0;
            }
            if ($termId > 0) {
                $term = $db->fetchOne(
                    "SELECT id FROM academic_terms
                     WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$termId, $tenantId]
                );
                if (!$term) $termId = 0;
            }

            $studentIdsIn = is_array($_POST['student_ids'] ?? null) ? $_POST['student_ids'] : [];
            $studentIds   = array_values(array_unique(array_filter(array_map('intval', $studentIdsIn))));
            if (empty($studentIds)) {
                throw new Exception('Please select at least one student.');
            }

            $rollOverrides = is_array($_POST['roll_numbers'] ?? null) ? $_POST['roll_numbers'] : [];

            $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
            $studentRows = $db->fetchAll(
                "SELECT s.id, s.first_name, s.middle_name, s.last_name, s.preferred_name
                 FROM students s
                 WHERE s.tenant_id = ? AND s.deleted_at IS NULL
                   AND s.id IN ($placeholders)",
                array_merge([$tenantId], $studentIds)
            );
            $validStudentIds = [];
            foreach ($studentRows as $sr) $validStudentIds[(int)$sr['id']] = $sr;

            foreach ($studentIds as $sid) {
                if (!isset($validStudentIds[$sid])) {
                    throw new Exception('Student #' . $sid . ' not found.');
                }
            }

            $db->beginTransaction();

            $activeCount = activeEnrollmentCount($db, $tenantId, $offeringId);
            $capacity    = $offering['capacity'] !== null ? (int)$offering['capacity'] : null;
            $addingCount = count($studentIds);

            if ($capacity !== null && $capacity > 0) {
                $wouldBe = $activeCount + $addingCount;
                if ($wouldBe > $capacity && !$force) {
                    throw new Exception(
                        'Capacity exceeded: the offering has ' . $capacity . ' seat(s), '
                            . $activeCount . ' already taken, and you are adding ' . $addingCount . '. '
                            . 'Either reduce the selection, raise capacity, or re-submit with "Force enrollment".'
                    );
                }
                if ($wouldBe > $capacity && $force) {
                    $notes = trim('OVERRIDE (over capacity): ' . $notes);
                }
            }

            $addedCount   = 0;
            $updatedCount = 0;
            $skippedCount = 0;

            $nextRoll = nextRollNumber($db, $tenantId, $offeringId);

            foreach ($studentIds as $sid) {
                $existing = $db->fetchOne(
                    "SELECT id, status, deleted_at FROM enrollments
                     WHERE tenant_id = ? AND student_id = ? AND class_offering_id = ?
                     FOR UPDATE",
                    [$tenantId, $sid, $offeringId]
                );

                if ($existing && empty($existing['deleted_at']) && $existing['status'] === 'active') {
                    $skippedCount++;
                    continue;
                }

                $conflictYear = $db->fetchOne(
                    "SELECT e.id, c.class_name, ay.year_name
                     FROM enrollments e
                     JOIN class_offerings co ON e.class_offering_id = co.id
                     LEFT JOIN classes c ON co.class_id = c.id
                     LEFT JOIN academic_years ay ON e.academic_year_id = ay.id
                     WHERE e.tenant_id = ? AND e.student_id = ?
                       AND e.academic_year_id = ?
                       AND e.status = 'active' AND e.deleted_at IS NULL
                     FOR UPDATE",
                    [$tenantId, $sid, $yearId]
                );
                if ($conflictYear) {
                    $studentName = trim(($validStudentIds[$sid]['first_name'] ?? '') . ' ' . ($validStudentIds[$sid]['last_name'] ?? ''));
                    throw new Exception(
                        'Student "' . $studentName . '" is already actively enrolled in '
                            . ($conflictYear['class_name'] ?? 'another offering') . ' for '
                            . ($conflictYear['year_name'] ?? 'this year') . '. '
                            . 'Withdraw or complete that enrollment first.'
                    );
                }

                $roll = '';
                if (isset($rollOverrides[$sid]) && trim((string)$rollOverrides[$sid]) !== '') {
                    $roll = trim((string)$rollOverrides[$sid]);
                } else {
                    $roll = (string)$nextRoll;
                    $nextRoll++;
                }

                if ($roll !== '') {
                    $dupRoll = $db->fetchOne(
                        "SELECT id FROM enrollments
                         WHERE tenant_id = ? AND class_offering_id = ?
                           AND roll_number = ?
                           AND status = 'active' AND deleted_at IS NULL
                         FOR UPDATE",
                        [$tenantId, $offeringId, $roll]
                    );
                    if ($dupRoll) {
                        throw new Exception(
                            'Roll number "' . $roll . '" is already taken in this offering. '
                                . 'Enter a different roll number or leave the field blank for auto-assignment.'
                        );
                    }
                }

                $newId = $db->insert(
                    "INSERT INTO enrollments
                        (uuid, tenant_id, student_id, class_offering_id, academic_year_id,
                         academic_term_id, roll_number, status, enrolled_at, left_at,
                         notes, created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        uuidv4(),
                        $tenantId,
                        $sid,
                        $offeringId,
                        $yearId,
                        $termId > 0 ? $termId : null,
                        $roll !== '' ? $roll : null,
                        $status,
                        $enrolledAt,
                        in_array($status, ['completed', 'transferred', 'withdrawn'], true) ? $enrolledAt : null,
                        $notes !== '' ? $notes : null,
                        $userId ?: null,
                    ]
                );

                syncStudentPointers($db, $tenantId, $sid, $status === 'active' ? (int)$newId : null);

                $addedCount++;
            }

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.enrollment.bulk_created',
                'enrollments',
                $offeringId,
                [
                    'offering_id' => $offeringId,
                    'added'       => $addedCount,
                    'updated'     => $updatedCount,
                    'skipped'     => $skippedCount,
                    'forced'      => $force ? 1 : 0,
                    'students'    => $studentIds,
                ]
            );

            $db->commit();
            $_SESSION['success'] = sprintf(
                'Enrolled. %d added, %d skipped.',
                $addedCount,
                $skippedCount
            );
            header('Location: /platform/tenant/academic/enrollments.php');
            exit;
        }

        // ---------- CHANGE STATUS ----------
        if ($action === 'change_status') {
            $id     = (int)($_POST['id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? ''));

            $allowedStatuses = ['active', 'completed', 'transferred', 'withdrawn', 'suspended'];
            if (!in_array($status, $allowedStatuses, true)) {
                throw new Exception('Invalid status.');
            }

            $row = $db->fetchOne(
                "SELECT * FROM enrollments WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Enrollment not found.');

            $db->beginTransaction();

            $leftAt = $row['left_at'];
            if (in_array($status, ['completed', 'transferred', 'withdrawn'], true) && empty($leftAt)) {
                $leftAt = date('Y-m-d');
            }
            if ($status === 'active') {
                $leftAt = null;
            }

            $db->execute(
                "UPDATE enrollments
                    SET status = ?, left_at = ?, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$status, $leftAt ?: null, $id, $tenantId]
            );

            syncStudentPointers($db, $tenantId, (int)$row['student_id']);

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.enrollment.status_changed',
                'enrollments',
                $id,
                [
                    'from'   => $row['status'],
                    'to'     => $status,
                    'student_id' => (int)$row['student_id'],
                    'offering_id' => (int)$row['class_offering_id'],
                ]
            );

            $db->commit();
            $_SESSION['success'] = 'Enrollment status updated to "' . $status . '".';
            header('Location: /platform/tenant/academic/enrollments.php');
            exit;
        }

        // ---------- UPDATE ----------
        if ($action === 'update') {
            $id         = (int)($_POST['id'] ?? 0);
            $rollNumber = trim((string)($_POST['roll_number'] ?? ''));
            $termId     = (int)($_POST['academic_term_id'] ?? 0);
            $notes      = trim((string)($_POST['notes'] ?? ''));

            $row = $db->fetchOne(
                "SELECT * FROM enrollments WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Enrollment not found.');

            if ($termId > 0) {
                $term = $db->fetchOne(
                    "SELECT id FROM academic_terms WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$termId, $tenantId]
                );
                if (!$term) $termId = 0;
            }

            $db->beginTransaction();

            if ($rollNumber !== '' && $row['status'] === 'active') {
                $dupRoll = $db->fetchOne(
                    "SELECT id FROM enrollments
                     WHERE tenant_id = ? AND class_offering_id = ?
                       AND roll_number = ?
                       AND status = 'active' AND deleted_at IS NULL
                       AND id != ?
                     FOR UPDATE",
                    [$tenantId, (int)$row['class_offering_id'], $rollNumber, $id]
                );
                if ($dupRoll) {
                    throw new Exception('Roll number "' . $rollNumber . '" is already taken in this offering.');
                }
            }

            $db->execute(
                "UPDATE enrollments
                    SET roll_number = ?,
                        academic_term_id = ?,
                        notes = ?,
                        updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [
                    $rollNumber !== '' ? $rollNumber : null,
                    $termId > 0 ? $termId : null,
                    $notes !== '' ? $notes : null,
                    $id,
                    $tenantId,
                ]
            );

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.enrollment.updated',
                'enrollments',
                $id,
                ['roll_number' => $rollNumber, 'term_id' => $termId]
            );

            $db->commit();
            $_SESSION['success'] = 'Enrollment updated.';
            header('Location: /platform/tenant/academic/enrollments.php');
            exit;
        }

        // ---------- DELETE ----------
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $row = $db->fetchOne(
                "SELECT * FROM enrollments WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Enrollment not found.');

            $deps = enrollmentDependencies($db, $tenantId, $id);
            if ($deps['total'] > 0) {
                throw new Exception(
                    'Cannot delete this enrollment. It is referenced by: ' .
                        implode(', ', $deps['labels']) .
                        '. Remove those references first, or set the enrollment status instead.'
                );
            }

            $db->beginTransaction();
            $db->execute(
                "UPDATE enrollments SET deleted_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );

            syncStudentPointers($db, $tenantId, (int)$row['student_id']);

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.enrollment.deleted',
                'enrollments',
                $id,
                [
                    'student_id'  => (int)$row['student_id'],
                    'offering_id' => (int)$row['class_offering_id'],
                ]
            );
            $db->commit();

            $_SESSION['success'] = 'Enrollment deleted.';
            header('Location: /platform/tenant/academic/enrollments.php');
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Academic enrollments action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/academic/enrollments.php');
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
$labelLevel    = $settings['label_level'] ?? 'Level';
$labelClass    = $settings['label_class'] ?? 'Class';
$labelStream   = $settings['label_stream'] ?? 'Stream';
$labelTerm     = $settings['label_term'] ?? 'Term';

$years = $db->fetchAll(
    "SELECT id, year_name FROM academic_years
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY start_date DESC, id DESC",
    [$tenantId]
);
$levels = $db->fetchAll(
    "SELECT id, level_name, level_code FROM academic_levels
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY sort_order ASC, level_name ASC",
    [$tenantId]
);
$classes = $db->fetchAll(
    "SELECT id, class_name, class_code FROM classes
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY class_name ASC",
    [$tenantId]
);
$studentsList = $db->fetchAll(
    "SELECT s.id, s.student_number,
            s.first_name, s.middle_name, s.last_name, s.preferred_name
     FROM students s
     WHERE s.tenant_id = ? AND s.is_active = 1 AND s.deleted_at IS NULL
     ORDER BY s.first_name ASC, s.last_name ASC",
    [$tenantId]
);
$terms = $db->fetchAll(
    "SELECT id, term_name, is_current FROM academic_terms
     WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL
     ORDER BY is_current DESC, sort_order ASC, id ASC",
    [$tenantId]
);
$currentTermId = getCurrentTerm($db, $tenantId) ?: 0;

$filterYear    = (int)($_GET['year_id'] ?? 0);
$filterLevel   = (int)($_GET['level_id'] ?? 0);
$filterStudent = (int)($_GET['student_id'] ?? 0);
$filterStatus  = trim((string)($_GET['status'] ?? ''));

$where = ["co.tenant_id = ?", "co.deleted_at IS NULL"];
$params = [$tenantId];
if ($filterYear > 0) {
    $where[] = "co.academic_year_id = ?";
    $params[] = $filterYear;
}
if ($filterLevel > 0) {
    $where[] = "co.academic_level_id = ?";
    $params[] = $filterLevel;
}
if ($filterStudent > 0) {
    $where[] = "EXISTS (SELECT 1 FROM enrollments e2
                        WHERE e2.class_offering_id = co.id
                          AND e2.tenant_id = co.tenant_id
                          AND e2.student_id = ?
                          AND e2.deleted_at IS NULL)";
    $params[] = $filterStudent;
}
if (in_array($filterStatus, ['active', 'completed', 'transferred', 'withdrawn', 'suspended'], true)) {
    $where[] = "EXISTS (SELECT 1 FROM enrollments e3
                        WHERE e3.class_offering_id = co.id
                          AND e3.tenant_id = co.tenant_id
                          AND e3.status = ?
                          AND e3.deleted_at IS NULL)";
    $params[] = $filterStatus;
}
$whereClause = implode(' AND ', $where);

$offerings = $db->fetchAll(
    "SELECT co.id, co.status, co.capacity,
            co.academic_year_id, co.academic_level_id, co.class_id, co.stream_id,
            ay.year_name,
            al.level_name, al.level_code, al.sort_order AS level_sort,
            c.class_name, c.class_code,
            s.stream_name,
            (SELECT COUNT(*) FROM enrollments e
             WHERE e.tenant_id = co.tenant_id
               AND e.class_offering_id = co.id
               AND e.status = 'active' AND e.deleted_at IS NULL) AS active_count,
            (SELECT COUNT(*) FROM enrollments e
             WHERE e.tenant_id = co.tenant_id
               AND e.class_offering_id = co.id
               AND e.deleted_at IS NULL) AS total_count
     FROM class_offerings co
     LEFT JOIN academic_years ay ON co.academic_year_id = ay.id
     LEFT JOIN academic_levels al ON co.academic_level_id = al.id
     LEFT JOIN classes c ON co.class_id = c.id
     LEFT JOIN streams s ON co.stream_id = s.id
     WHERE $whereClause
     ORDER BY ay.start_date DESC, al.sort_order ASC, c.class_name ASC, s.stream_name ASC",
    $params
);

$byYear = [];
foreach ($offerings as $o) {
    $yId = (int)$o['academic_year_id'];
    if (!isset($byYear[$yId])) {
        $byYear[$yId] = [
            'year_name' => $o['year_name'] ?: '(Unknown year)',
            'levels'    => [],
        ];
    }
    $lId = (int)$o['academic_level_id'];
    if (!isset($byYear[$yId]['levels'][$lId])) {
        $byYear[$yId]['levels'][$lId] = [
            'level_name' => $o['level_name'] ?: '(Unknown level)',
            'level_code' => $o['level_code'] ?? '',
            'items'      => [],
        ];
    }
    $byYear[$yId]['levels'][$lId]['items'][] = $o;
}

$enrollmentsByOffering = [];
$enrolledStudentIdsByOffering = [];
if (!empty($offerings)) {
    $offeringIds = array_map(function ($o) {
        return (int)$o['id'];
    }, $offerings);
    $in = implode(',', array_fill(0, count($offeringIds), '?'));
    $rows = $db->fetchAll(
        "SELECT e.id, e.class_offering_id, e.student_id, e.roll_number, e.status,
                e.academic_term_id, e.enrolled_at, e.left_at, e.notes,
                s.student_number,
                s.first_name, s.middle_name, s.last_name, s.preferred_name,
                t.term_name
         FROM enrollments e
         LEFT JOIN students s ON e.student_id = s.id
         LEFT JOIN academic_terms t ON e.academic_term_id = t.id
         WHERE e.tenant_id = ?
           AND e.class_offering_id IN ($in)
           AND e.deleted_at IS NULL
         ORDER BY
            CASE e.status WHEN 'active' THEN 0 ELSE 1 END,
            CAST(e.roll_number AS UNSIGNED) ASC,
            s.first_name ASC",
        array_merge([$tenantId], $offeringIds)
    );
    foreach ($rows as $r) {
        $oid = (int)$r['class_offering_id'];
        if (!isset($enrollmentsByOffering[$oid])) $enrollmentsByOffering[$oid] = [];
        $enrollmentsByOffering[$oid][] = $r;
        if ($r['status'] === 'active') {
            if (!isset($enrolledStudentIdsByOffering[$oid])) $enrolledStudentIdsByOffering[$oid] = [];
            $enrolledStudentIdsByOffering[$oid][] = (int)$r['student_id'];
        }
    }
}

$enrolledByYear = [];
$rows = $db->fetchAll(
    "SELECT e.academic_year_id AS yid, e.student_id
     FROM enrollments e
     WHERE e.tenant_id = ? AND e.status = 'active' AND e.deleted_at IS NULL",
    [$tenantId]
);
foreach ($rows as $r) {
    $yid = (int)$r['yid'];
    if (!isset($enrolledByYear[$yid])) $enrolledByYear[$yid] = [];
    $enrolledByYear[$yid][(int)$r['student_id']] = true;
}

$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

function studentDisplay($row): string
{
    $pref = trim((string)($row['preferred_name'] ?? ''));
    if ($pref !== '') return $pref;
    $full = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    $full = preg_replace('/\s+/', ' ', $full);
    return $full !== '' ? $full : ('Student #' . (int)($row['id'] ?? 0));
}

$totalOfferings = count($offerings);
$totalActiveEnrollments = 0;
foreach ($offerings as $o) $totalActiveEnrollments += (int)$o['active_count'];

// Pre-compute JSON maps for the modal, with safe fallbacks.
$enrollmentsByOfferingJson = json_encode($enrollmentsByOffering, JSON_UNESCAPED_UNICODE);
if (!is_string($enrollmentsByOfferingJson) || $enrollmentsByOfferingJson === '') {
    $enrollmentsByOfferingJson = '{}';
}
$enrolledStudentIdsJson = json_encode($enrolledStudentIdsByOffering, JSON_UNESCAPED_UNICODE);
if (!is_string($enrolledStudentIdsJson) || $enrolledStudentIdsJson === '') {
    $enrolledStudentIdsJson = '{}';
}
$enrolledByYearJson = json_encode($enrolledByYear, JSON_UNESCAPED_UNICODE);
if (!is_string($enrolledByYearJson) || $enrolledByYearJson === '') {
    $enrolledByYearJson = '{}';
}
$studentsListJson = json_encode($studentsList, JSON_UNESCAPED_UNICODE);
if (!is_string($studentsListJson) || $studentsListJson === '') {
    $studentsListJson = '[]';
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
            max-width: 1100px;
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
            max-width: 1100px;
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
            align-items: center;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            max-width: 1100px;
            margin-left: auto;
            margin-right: auto;
        }

        .filters-bar .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            min-width: 150px;
        }

        .filters-bar .filter-group label {
            font-weight: 500;
            font-size: 12px;
            color: #1a1a2e;
            white-space: nowrap;
        }

        .filters-bar .form-select {
            height: 38px;
            font-size: 13px;
            border-radius: 8px;
            border: 1.5px solid #e9ecef;
        }

        .year-section {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
            overflow: hidden;
            width: 100%;
            max-width: 1100px;
            margin-left: auto;
            margin-right: auto;
        }

        .year-section .year-header {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            background: linear-gradient(135deg, #f8fafc 0%, #eef6ff 100%);
        }

        .year-section .year-header .year-title {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .year-section .year-header .year-title h5 {
            font-weight: 700;
            margin: 0;
            font-size: 18px;
            color: #1a1a2e;
        }

        .level-block {
            border-bottom: 1px solid #f0f2f5;
        }

        .level-block:last-child {
            border-bottom: none;
        }

        .level-block .level-bar {
            padding: 10px 24px;
            background: #fafbfc;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            border-bottom: 1px solid #f0f2f5;
        }

        .level-block .level-bar h6 {
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

        .offering-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .offering-table thead th {
            background: #f8f9fa;
            padding: 10px 20px;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            border-bottom: 1px solid #e9ecef;
            text-align: left;
            white-space: nowrap;
        }

        .offering-table tbody td {
            padding: 12px 20px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .offering-table tbody tr:last-child td {
            border-bottom: none;
        }

        .offering-table tbody tr:hover {
            background: #fafbfc;
        }

        .offering-title {
            font-weight: 600;
            color: #1a1a2e;
            font-size: 14px;
        }

        .offering-meta {
            font-size: 11px;
            color: #6c757d;
            margin-top: 2px;
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
            max-width: 1100px;
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

        .form-label .required {
            color: #dc3545;
            margin-left: 2px;
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

        .field-error {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.1) !important;
            background-color: #fff8f8 !important;
        }

        .modal-lg {
            max-width: 800px;
        }

        .student-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 6px;
            max-height: 320px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 8px;
            background: #fafbfc;
        }

        .student-row {
            display: grid;
            grid-template-columns: 28px 1fr 120px;
            gap: 10px;
            align-items: center;
            padding: 8px 10px;
            border-radius: 8px;
            transition: background 0.15s;
        }

        .student-row:hover {
            background: #fff;
        }

        .student-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .student-name {
            font-size: 13px;
            font-weight: 500;
            color: #1a1a2e;
        }

        .student-meta {
            font-size: 11px;
            color: #6c757d;
        }

        .student-row .roll-input input {
            height: 32px;
            padding: 4px 8px;
            font-size: 12px;
            text-align: center;
        }

        .student-row.locked-row {
            opacity: 0.6;
            background: #f1f3f5;
        }

        .student-row.locked-row .student-name::after {
            content: ' (already enrolled this year)';
            color: #b02a37;
            font-style: italic;
            font-weight: 400;
            font-size: 11px;
            margin-left: 4px;
        }

        .existing-panel {
            margin-top: 18px;
            border-top: 1px solid #f0f2f5;
            padding-top: 14px;
        }

        .existing-panel h6 {
            font-weight: 700;
            font-size: 13px;
            color: #1a1a2e;
            margin: 0 0 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .existing-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .existing-item {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            align-items: center;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 8px 12px;
        }

        .existing-item .info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .existing-item .info .title {
            font-weight: 600;
            font-size: 13px;
            color: #1a1a2e;
        }

        .existing-item .info .meta {
            font-size: 11px;
            color: #6c757d;
        }

        .existing-item .actions {
            display: flex;
            gap: 4px;
            align-items: center;
        }

        .existing-item .actions .btn {
            padding: 3px 8px;
            font-size: 11px;
            border-radius: 6px;
        }

        .existing-item .actions .status-select {
            height: 30px;
            padding: 3px 8px;
            font-size: 11px;
            border-radius: 6px;
            width: auto;
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

            .offering-table {
                font-size: 12px;
            }

            .offering-table thead th {
                padding: 8px 10px;
                font-size: 10px;
            }

            .offering-table tbody td {
                padding: 8px 10px;
            }

            .filters-bar {
                flex-direction: column;
            }

            .filters-bar .filter-group {
                width: 100%;
            }

            .student-row {
                grid-template-columns: 28px 1fr;
                grid-template-areas: "chk name" ". roll";
            }

            .student-row .chk {
                grid-area: chk;
            }

            .student-row .name-wrap {
                grid-area: name;
            }

            .student-row .roll-input {
                grid-area: roll;
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
                        <h1><i class="fas fa-user-plus me-2"></i>Enrollments</h1>
                        <p>Enroll students into class offerings</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/academic/class-offerings.php" class="btn btn-outline-secondary">
                            <i class="fas fa-door-open me-2"></i> Manage Offerings
                        </a>
                        <a href="/platform/tenant/academic/settings.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Settings
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo h($tenantName); ?></div>
                            <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                <?php echo h((string)$totalOfferings); ?> offering(s) ·
                                <?php echo h((string)$totalActiveEnrollments); ?> active enrollment(s)
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>Capacity enforced
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
                        <div class="ib-title">One active enrollment per student per year</div>
                        <div class="ib-text">
                            A student cannot be actively enrolled in two offerings within the same
                            <?php echo h(strtolower($labelAcademic)); ?>. If you need to move them, first set their
                            current enrollment to <em>Withdrawn</em> or <em>Transferred</em>, then enroll them in the new offering.
                            Capacity is hard-blocked; use "Force enrollment" on the offering when you truly need to over-enroll.
                        </div>
                    </div>
                </div>

                <form method="GET" action="/platform/tenant/academic/enrollments.php" class="filters-bar">
                    <div class="filter-group">
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
                    <div class="filter-group">
                        <label><?php echo h($labelLevel); ?></label>
                        <select name="level_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">All</option>
                            <?php foreach ($levels as $lv): ?>
                                <option value="<?php echo (int)$lv['id']; ?>" <?php echo $filterLevel === (int)$lv['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($lv['level_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Student</label>
                        <select name="student_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">All</option>
                            <?php foreach ($studentsList as $st): ?>
                                <option value="<?php echo (int)$st['id']; ?>" <?php echo $filterStudent === (int)$st['id'] ? 'selected' : ''; ?>>
                                    <?php echo h(studentDisplay($st)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status" class="form-select" onchange="this.form.submit()">
                            <option value="">All</option>
                            <?php foreach (['active', 'completed', 'transferred', 'withdrawn', 'suspended'] as $s): ?>
                                <option value="<?php echo $s; ?>" <?php echo $filterStatus === $s ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($s); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group" style="flex:0 0 auto;">
                        <a href="/platform/tenant/academic/enrollments.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>

                <?php if (empty($byYear)): ?>
                    <div class="year-section">
                        <div class="empty-state">
                            <i class="fas fa-door-open"></i>
                            <h5>No class offerings yet</h5>
                            <p>Enrollments live inside class offerings. Create an offering first.</p>
                            <a href="/platform/tenant/academic/class-offerings.php?action=new" class="btn btn-primary btn-sm mt-2">
                                <i class="fas fa-plus me-1"></i> Create Offering
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($byYear as $yId => $yearGroup): ?>
                        <div class="year-section">
                            <div class="year-header">
                                <div class="year-title">
                                    <i class="fas fa-calendar-alt" style="font-size:20px;color:#4facfe;"></i>
                                    <h5><?php echo h($yearGroup['year_name']); ?></h5>
                                    <?php
                                    $coCount = 0;
                                    $enrCount = 0;
                                    foreach ($yearGroup['levels'] as $lg) {
                                        foreach ($lg['items'] as $oi) {
                                            $coCount++;
                                            $enrCount += (int)$oi['active_count'];
                                        }
                                    }
                                    ?>
                                    <span class="pill blue"><i class="fas fa-door-open"></i><?php echo h((string)$coCount); ?> offering(s)</span>
                                    <span class="pill purple"><i class="fas fa-user-plus"></i><?php echo h((string)$enrCount); ?> active enrollment(s)</span>
                                </div>
                            </div>

                            <?php foreach ($yearGroup['levels'] as $lId => $levelGroup): ?>
                                <div class="level-block">
                                    <div class="level-bar">
                                        <i class="fas fa-layer-group" style="color:#4facfe;"></i>
                                        <h6><?php echo h($levelGroup['level_name']); ?></h6>
                                        <?php if (!empty($levelGroup['level_code'])): ?>
                                            <span class="pill blue"><?php echo h($levelGroup['level_code']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <table class="offering-table">
                                        <thead>
                                            <tr>
                                                <th style="min-width:220px;"><?php echo h($labelClass); ?> Offering</th>
                                                <th style="text-align:center;">Capacity</th>
                                                <th style="text-align:center;">Enrolled</th>
                                                <th style="text-align:right;min-width:200px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($levelGroup['items'] as $o):
                                                $oid    = (int)$o['id'];
                                                $active = (int)$o['active_count'];
                                                $cap    = $o['capacity'] !== null ? (int)$o['capacity'] : null;
                                                $full   = ($cap !== null && $cap > 0 && $active >= $cap);
                                            ?>
                                                <tr>
                                                    <td>
                                                        <div class="offering-title">
                                                            <?php echo h($o['class_name'] ?: '—'); ?>
                                                            <?php if (!empty($o['class_code'])): ?>
                                                                <span class="pill blue" style="margin-left:6px;"><?php echo h($o['class_code']); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if (!empty($o['stream_name'])): ?>
                                                            <div class="offering-meta">
                                                                <i class="fas fa-code-branch me-1"></i>
                                                                <?php echo h($labelStream); ?>: <?php echo h($o['stream_name']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <?php if ($cap !== null && $cap > 0): ?>
                                                            <span class="pill <?php echo $full ? 'red' : 'gray'; ?>">
                                                                <i class="fas fa-users"></i><?php echo h((string)$cap); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="pill gray"><i class="fas fa-infinity"></i>No limit</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <span class="pill <?php echo $active > 0 ? 'purple' : 'gray'; ?>">
                                                            <i class="fas fa-user-plus"></i><?php echo h((string)$active); ?>
                                                        </span>
                                                        <?php if ($full): ?>
                                                            <span class="pill red" style="margin-left:6px;">
                                                                <i class="fas fa-exclamation-circle"></i>Full
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex justify-content-end gap-2 flex-wrap">
                                                            <button type="button"
                                                                class="btn btn-outline-primary js-manage-enrollments"
                                                                data-offering-id="<?php echo $oid; ?>"
                                                                data-offering-label="<?php echo h(($o['class_name'] ?? '') . (($o['stream_name'] ?? '') ? ' / ' . $o['stream_name'] : '') . ' · ' . ($o['year_name'] ?? '') . ' · ' . ($o['level_name'] ?? '')); ?>"
                                                                data-year-id="<?php echo (int)$o['academic_year_id']; ?>"
                                                                data-capacity="<?php echo $cap !== null ? $cap : ''; ?>">
                                                                <i class="fas fa-tasks"></i> Manage Students
                                                            </button>
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

    <div class="modal fade" id="manageEnrollmentsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="POST" id="bulkEnrollForm" action="/platform/tenant/academic/enrollments.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="bulk_save">
                    <input type="hidden" name="class_offering_id" id="modalOfferingId" value="">
                    <input type="hidden" name="force" id="modalForceFlag" value="0">
                    <div class="modal-header">
                        <h5>
                            <i class="fas fa-user-plus text-primary me-2"></i>
                            Manage Students
                            <small class="text-muted d-block" id="modalOfferingLabel" style="font-size:12px;font-weight:400;margin-top:2px;"></small>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="addEnrolledAt">Enrolled At <span class="required">*</span></label>
                                <input type="date" class="form-control" id="addEnrolledAt" name="enrolled_at"
                                    value="<?php echo h(date('Y-m-d')); ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="addEnrollStatus">Status <span class="required">*</span></label>
                                <select class="form-select" id="addEnrollStatus" name="status" required>
                                    <option value="active" selected>Active</option>
                                    <option value="completed">Completed</option>
                                    <option value="transferred">Transferred</option>
                                    <option value="withdrawn">Withdrawn</option>
                                    <option value="suspended">Suspended</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="addEnrollTerm"><?php echo h($labelTerm); ?></label>
                                <select class="form-select" id="addEnrollTerm" name="academic_term_id">
                                    <option value="0">(Use current term if any)</option>
                                    <?php foreach ($terms as $t): ?>
                                        <option value="<?php echo (int)$t['id']; ?>" <?php echo ((int)$t['id'] === $currentTermId) ? 'selected' : ''; ?>>
                                            <?php echo h($t['term_name']); ?>
                                            <?php if ((int)$t['is_current'] === 1): ?> ★<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                <label class="form-label m-0">Students to enroll <span class="required">*</span></label>
                                <div class="d-flex gap-2">
                                    <input type="text" class="form-control form-control-sm" id="studentSearchInput" placeholder="Search students..." style="max-width:220px;">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="checkAllBtn">Check all</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="uncheckAllBtn">Uncheck all</button>
                                </div>
                            </div>
                            <div class="student-grid" id="studentGrid"></div>
                            <div class="form-text">
                                Leave roll number blank to auto-assign the next sequential number.
                                Students already enrolled in another offering in the same <?php echo h(strtolower($labelAcademic)); ?> are marked and locked.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="addEnrollNotes">Notes</label>
                            <input type="text" class="form-control" id="addEnrollNotes" name="notes" maxlength="500" placeholder="Optional">
                        </div>

                        <div class="form-check" id="forceWrap" style="display:none;">
                            <input class="form-check-input" type="checkbox" id="forceCheckbox" value="1">
                            <label class="form-check-label" for="forceCheckbox">
                                Force enrollment even if over capacity
                            </label>
                        </div>

                        <div class="existing-panel" id="existingPanel">
                            <h6><i class="fas fa-list-check text-primary"></i>Existing enrollments for this offering</h6>
                            <div class="existing-list" id="existingList"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Enroll Selected</button>
                    </div>
                </form>

                <form method="POST" id="singleActionForm" action="/platform/tenant/academic/enrollments.php" style="display:none;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" id="singleActionName" value="">
                    <input type="hidden" name="id" id="singleActionId" value="">
                    <input type="hidden" name="status" id="singleActionStatus" value="">
                </form>
                <form method="POST" id="updateRowForm" action="/platform/tenant/academic/enrollments.php" style="display:none;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="updateRowId" value="">
                    <input type="hidden" name="roll_number" id="updateRowRoll" value="">
                    <input type="hidden" name="academic_term_id" id="updateRowTerm" value="">
                    <input type="hidden" name="notes" id="updateRowNotes" value="">
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var ENROLLMENTS_BY_OFFERING = <?php echo $enrollmentsByOfferingJson; ?>;
        if (typeof ENROLLMENTS_BY_OFFERING !== 'object' || ENROLLMENTS_BY_OFFERING === null) {
            ENROLLMENTS_BY_OFFERING = {};
        }
        var ENROLLED_STUDENTS_BY_OFF = <?php echo $enrolledStudentIdsJson; ?>;
        if (typeof ENROLLED_STUDENTS_BY_OFF !== 'object' || ENROLLED_STUDENTS_BY_OFF === null) {
            ENROLLED_STUDENTS_BY_OFF = {};
        }
        var ENROLLED_BY_YEAR = <?php echo $enrolledByYearJson; ?>;
        if (typeof ENROLLED_BY_YEAR !== 'object' || ENROLLED_BY_YEAR === null) {
            ENROLLED_BY_YEAR = {};
        }
        var STUDENTS_LIST = <?php echo $studentsListJson; ?>;
        if (!Array.isArray(STUDENTS_LIST)) {
            STUDENTS_LIST = [];
        }
        var CURRENT_TERM_ID = <?php echo (int)$currentTermId; ?>;

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
            const modalEl = document.getElementById('manageEnrollmentsModal');
            const modal = new bootstrap.Modal(modalEl);
            const offeringIdIn = document.getElementById('modalOfferingId');
            const offeringLbl = document.getElementById('modalOfferingLabel');
            const gridEl = document.getElementById('studentGrid');
            const searchInput = document.getElementById('studentSearchInput');
            const checkAllBtn = document.getElementById('checkAllBtn');
            const uncheckAllBtn = document.getElementById('uncheckAllBtn');
            const existingPanel = document.getElementById('existingPanel');
            const existingList = document.getElementById('existingList');
            const forceWrap = document.getElementById('forceWrap');
            const forceCheckbox = document.getElementById('forceCheckbox');
            const forceFlag = document.getElementById('modalForceFlag');
            const enrolledAtIn = document.getElementById('addEnrolledAt');
            const statusIn = document.getElementById('addEnrollStatus');
            const termIn = document.getElementById('addEnrollTerm');
            const notesIn = document.getElementById('addEnrollNotes');

            const singleForm = document.getElementById('singleActionForm');
            const singleName = document.getElementById('singleActionName');
            const singleId = document.getElementById('singleActionId');
            const singleStatus = document.getElementById('singleActionStatus');

            let currentOfferingId = 0;
            let currentOfferingCap = null;
            let currentYearId = 0;

            function escapeHtml(s) {
                if (s === null || s === undefined) return '';
                return String(s)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function fullName(s) {
                const pref = (s.preferred_name || '').trim();
                if (pref) return pref;
                const parts = [s.first_name || '', s.middle_name || '', s.last_name || ''];
                return parts.join(' ').replace(/\s+/g, ' ').trim() || ('Student #' + s.id);
            }

            function renderStudents() {
                const enrolledSet = new Set(ENROLLED_STUDENTS_BY_OFF[currentOfferingId] || []);
                const enrolledByYearThisYear = ENROLLED_BY_YEAR[currentYearId] || {};

                gridEl.innerHTML = '';
                if (!STUDENTS_LIST.length) {
                    gridEl.innerHTML = '<div class="p-3 text-muted">No students are registered yet.</div>';
                    return;
                }

                STUDENTS_LIST.forEach(function(s) {
                    const sid = parseInt(s.id, 10);
                    const alreadyInThis = enrolledSet.has(sid);
                    const inOtherThisYear = !alreadyInThis && enrolledByYearThisYear[sid];

                    if (alreadyInThis) return;

                    const locked = !!inOtherThisYear;

                    const row = document.createElement('label');
                    row.className = 'student-row' + (locked ? ' locked-row' : '');
                    row.dataset.studentId = sid;
                    row.dataset.nameLower = (fullName(s) + ' ' + (s.student_number || '')).toLowerCase();
                    row.innerHTML = '' +
                        '<div class="chk">' +
                        '<input type="checkbox" name="student_ids[]" value="' + sid + '"' + (locked ? ' disabled' : '') + '>' +
                        '</div>' +
                        '<div class="name-wrap">' +
                        '<div class="student-name">' + escapeHtml(fullName(s)) + '</div>' +
                        '<div class="student-meta">' + (s.student_number ? 'Adm: ' + escapeHtml(s.student_number) : '') + '</div>' +
                        '</div>' +
                        '<div class="roll-input">' +
                        '<input type="text" class="form-control form-control-sm" name="roll_numbers[' + sid + ']" placeholder="Roll #"' + (locked ? ' disabled' : '') + '>' +
                        '</div>';
                    gridEl.appendChild(row);
                });

                if (!gridEl.children.length) {
                    gridEl.innerHTML = '<div class="p-3 text-muted">Every student is already enrolled in this offering.</div>';
                }
            }

            function renderExisting() {
                const rows = ENROLLMENTS_BY_OFFERING[currentOfferingId] || [];
                if (!rows.length) {
                    existingPanel.style.display = 'none';
                    existingList.innerHTML = '';
                    return;
                }
                existingPanel.style.display = 'block';
                existingList.innerHTML = '';
                rows.forEach(function(r) {
                    const stPillClass = ({
                        'active': 'green',
                        'completed': 'blue',
                        'transferred': 'gray',
                        'withdrawn': 'orange',
                        'suspended': 'red'
                    })[r.status] || 'gray';
                    const roll = r.roll_number ? ('Roll: ' + escapeHtml(r.roll_number)) : 'No roll #';
                    const term = r.term_name ? (' · ' + escapeHtml(r.term_name)) : '';
                    const enrolledAt = r.enrolled_at ? (' · ' + escapeHtml(r.enrolled_at)) : '';
                    const item = document.createElement('div');
                    item.className = 'existing-item';
                    item.innerHTML = '' +
                        '<div class="info">' +
                        '<div class="title">' + escapeHtml(fullName(r)) + '</div>' +
                        '<div class="meta">' +
                        roll + term + enrolledAt +
                        ' · <span class="pill ' + stPillClass + '">' + escapeHtml(r.status) + '</span>' +
                        '</div>' +
                        '</div>' +
                        '<div class="actions">' +
                        '<select class="form-select status-select js-change-status" data-id="' + parseInt(r.id, 10) + '">' +
                        '<option value="active"' + (r.status === 'active' ? ' selected' : '') + '>Active</option>' +
                        '<option value="completed"' + (r.status === 'completed' ? ' selected' : '') + '>Completed</option>' +
                        '<option value="transferred"' + (r.status === 'transferred' ? ' selected' : '') + '>Transferred</option>' +
                        '<option value="withdrawn"' + (r.status === 'withdrawn' ? ' selected' : '') + '>Withdrawn</option>' +
                        '<option value="suspended"' + (r.status === 'suspended' ? ' selected' : '') + '>Suspended</option>' +
                        '</select>' +
                        '<button type="button" class="btn btn-outline-danger js-delete-existing" data-id="' + parseInt(r.id, 10) + '" title="Remove">' +
                        '<i class="fas fa-trash"></i>' +
                        '</button>' +
                        '</div>';
                    existingList.appendChild(item);
                });
            }

            function updateForceVisibility() {
                if (!currentOfferingCap || currentOfferingCap <= 0) {
                    forceWrap.style.display = 'none';
                    forceCheckbox.checked = false;
                    forceFlag.value = '0';
                    return;
                }
                forceWrap.style.display = 'block';
                forceFlag.value = forceCheckbox.checked ? '1' : '0';
            }
            forceCheckbox.addEventListener('change', updateForceVisibility);

            function applySearch() {
                const q = (searchInput.value || '').trim().toLowerCase();
                gridEl.querySelectorAll('.student-row').forEach(function(row) {
                    const n = row.dataset.nameLower || '';
                    row.style.display = (!q || n.indexOf(q) !== -1) ? '' : 'none';
                });
            }
            searchInput.addEventListener('input', applySearch);

            checkAllBtn.addEventListener('click', function() {
                gridEl.querySelectorAll('.student-row').forEach(function(row) {
                    if (row.style.display === 'none') return;
                    const cb = row.querySelector('input[name="student_ids[]"]');
                    if (cb && !cb.disabled) cb.checked = true;
                });
            });
            uncheckAllBtn.addEventListener('click', function() {
                gridEl.querySelectorAll('.student-row').forEach(function(row) {
                    if (row.style.display === 'none') return;
                    const cb = row.querySelector('input[name="student_ids[]"]');
                    if (cb && !cb.disabled) cb.checked = false;
                });
            });

            document.querySelectorAll('.js-manage-enrollments').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    currentOfferingId = parseInt(this.getAttribute('data-offering-id'), 10);
                    currentYearId = parseInt(this.getAttribute('data-year-id'), 10);
                    const capAttr = this.getAttribute('data-capacity');
                    currentOfferingCap = (capAttr === '' || capAttr === null) ? null : parseInt(capAttr, 10);

                    offeringIdIn.value = currentOfferingId;
                    offeringLbl.textContent = this.getAttribute('data-offering-label') || '';

                    enrolledAtIn.value = new Date().toISOString().slice(0, 10);
                    statusIn.value = 'active';
                    termIn.value = CURRENT_TERM_ID ? String(CURRENT_TERM_ID) : '0';
                    notesIn.value = '';
                    searchInput.value = '';
                    forceCheckbox.checked = false;
                    forceFlag.value = '0';

                    renderStudents();
                    renderExisting();
                    updateForceVisibility();
                    modal.show();
                });
            });

            document.addEventListener('change', function(e) {
                const sel = e.target.closest('.js-change-status');
                if (!sel) return;
                const newStatus = sel.value;
                if (!confirm('Change status to "' + newStatus + '"?')) {
                    renderExisting();
                    return;
                }
                singleName.value = 'change_status';
                singleId.value = sel.getAttribute('data-id');
                singleStatus.value = newStatus;
                singleForm.submit();
            });

            document.addEventListener('click', function(e) {
                const del = e.target.closest('.js-delete-existing');
                if (!del) return;
                if (!confirm('Delete this enrollment? This cannot be undone.')) return;
                singleName.value = 'delete';
                singleId.value = del.getAttribute('data-id');
                singleStatus.value = '';
                singleForm.submit();
            });

            document.getElementById('bulkEnrollForm').addEventListener('submit', function(e) {
                const checked = this.querySelectorAll('input[name="student_ids[]"]:checked:not(:disabled)').length;
                if (checked === 0) {
                    e.preventDefault();
                    alert('Please select at least one student.');
                }
            });
        })();

        const successBox = document.getElementById('serverSuccessBox');
        if (successBox) {
            setTimeout(function() {
                successBox.style.transition = 'opacity 0.3s ease';
                successBox.style.opacity = '0';
                setTimeout(function() {
                    successBox.remove();
                }, 300);
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