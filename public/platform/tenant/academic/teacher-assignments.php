<?php

/**
 * Academic Teacher Assignments — Staff ↔ Offering ↔ Subject pivots
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Academic
 * @version 1.0
 * @filepath public/platform/tenant/academic/teacher-assignments.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Academic teacher-assignments file of the academic-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'academic_teacher_assignments'
 *       to 'academic' so the partial marks Academic active and
 *       renders the academic sub-menu on this page — consistent
 *       with every other academic file.
 *     - A v1.0 [SWEEP] entry was added above this docblock, and
 *       @version was reduced from 1.1 to 1.0 per Decision X-3.
 *     - The inline <nav class="sidebar" id="sidebar"> block is
 *       removed and replaced by an include of
 *       app/views/partials/sidebar.php.
 *     - The CSS rule .nav-subgroup-label is added to this file's
 *       <style> block, matching every other academic file.
 *   Every other line of the file is byte-identical to the
 *   previous version (1.1). The @package tag remains 'EduTrack'.
 *
 * v1.1 (S20: bootstrap + CSRF on all POST handlers including JS-built forms)
 *
 * Decisions locked in (Session S12):
 *   D1  staff_id + class_offering_id + academic_year_id + role required;
 *       subject_id conditional (required for non-class_teacher roles, NULL for class_teacher);
 *       term_id, is_primary, is_active, notes optional
 *   D2  unique per (tenant, class_offering_id, subject_id, staff_id, role) — PHP-enforced
 *   D3  no feature gate
 *   D4  no orphans
 *   D5  auto-fill term_id with current term if not provided
 *   D6  derive academic_year_id from the offering (read-only)
 *   D7  allow free soft-delete (audited), no delete guard
 *   D8  offering-centric grouping + staff filter bar
 *   D9  multi-select modal inside offering
 *   D10 enforce one active class_teacher per offering
 *   D11 enforce one active primary subject_teacher per (offering, subject)
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

$pageTitle   = 'Teacher Assignments - Student 360 Platform';
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
                co.stream_id, co.status,
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

function getOfferingSubjects($db, int $tenantId, int $offeringId): array
{
    return $db->fetchAll(
        "SELECT cs.subject_id AS id,
                s.subject_name, s.subject_code,
                cs.is_core
         FROM class_subjects cs
         LEFT JOIN subjects s ON cs.subject_id = s.id
         WHERE cs.tenant_id = ?
           AND cs.class_offering_id = ?
           AND cs.is_active = 1
           AND cs.deleted_at IS NULL
         ORDER BY s.subject_name ASC",
        [$tenantId, $offeringId]
    );
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
            $staffId    = (int)($_POST['staff_id'] ?? 0);
            $role       = trim((string)($_POST['role'] ?? 'subject_teacher'));
            $isPrimary  = !empty($_POST['is_primary']) ? 1 : 0;
            $termId     = (int)($_POST['academic_term_id'] ?? 0);
            $notes      = trim((string)($_POST['notes'] ?? ''));

            $allowedRoles = ['class_teacher', 'subject_teacher', 'assistant', 'special_education'];
            if (!in_array($role, $allowedRoles, true)) {
                throw new Exception('Invalid role.');
            }

            $offering = getOffering($db, $tenantId, $offeringId);
            if (!$offering) {
                throw new Exception('Class offering not found.');
            }

            $staff = $db->fetchOne(
                "SELECT s.id, s.staff_number, p.first_name, p.last_name, p.middle_name, p.preferred_name
                 FROM staff s
                 LEFT JOIN persons p ON s.person_id = p.id
                 WHERE s.id = ? AND s.tenant_id = ? AND s.deleted_at IS NULL",
                [$staffId, $tenantId]
            );
            if (!$staff) {
                throw new Exception('Staff member not found.');
            }

            if ($termId <= 0) {
                $curTerm = getCurrentTerm($db, $tenantId);
                $termId  = $curTerm ?: 0;
            }
            if ($termId > 0) {
                $term = $db->fetchOne(
                    "SELECT id FROM academic_terms
                     WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$termId, $tenantId]
                );
                if (!$term) $termId = 0;
            }

            $subjectIdsIn = is_array($_POST['subject_ids'] ?? null) ? $_POST['subject_ids'] : [];
            $subjectIds   = array_values(array_unique(array_filter(array_map('intval', $subjectIdsIn))));

            if ($role === 'class_teacher') {
                $subjectIds = [null];
            } else {
                if (empty($subjectIds)) {
                    throw new Exception('Please select at least one subject for this role.');
                }
            }

            if ($role !== 'class_teacher') {
                $offeringSubjects = getOfferingSubjects($db, $tenantId, $offeringId);
                $allowedSubjectIds = array_map(function ($r) {
                    return (int)$r['id'];
                }, $offeringSubjects);
                foreach ($subjectIds as $sid) {
                    if (!in_array($sid, $allowedSubjectIds, true)) {
                        throw new Exception('Subject #' . $sid . ' is not taught in this offering.');
                    }
                }
            }

            $yearId = (int)$offering['academic_year_id'];
            if ($yearId <= 0) {
                throw new Exception('This offering has no academic year set.');
            }

            $db->beginTransaction();

            $addedCount   = 0;
            $updatedCount = 0;
            $skippedCount = 0;

            foreach ($subjectIds as $sid) {
                $sidOrNull = ($sid === null) ? null : (int)$sid;

                if ($role === 'class_teacher') {
                    $existingClassTeacher = $db->fetchOne(
                        "SELECT id, staff_id FROM teacher_class_assignments
                         WHERE tenant_id = ?
                           AND class_offering_id = ?
                           AND role = 'class_teacher'
                           AND is_active = 1
                           AND deleted_at IS NULL
                           AND staff_id != ?
                         FOR UPDATE",
                        [$tenantId, $offeringId, $staffId]
                    );
                    if ($existingClassTeacher) {
                        throw new Exception(
                            'This offering already has an active class teacher. ' .
                                'Deactivate the existing one first.'
                        );
                    }
                }

                if ($role === 'subject_teacher' && $isPrimary === 1 && $sidOrNull !== null) {
                    $existingPrimary = $db->fetchOne(
                        "SELECT id FROM teacher_class_assignments
                         WHERE tenant_id = ?
                           AND class_offering_id = ?
                           AND subject_id = ?
                           AND role = 'subject_teacher'
                           AND is_primary = 1
                           AND is_active = 1
                           AND deleted_at IS NULL
                           AND staff_id != ?
                         FOR UPDATE",
                        [$tenantId, $offeringId, $sidOrNull, $staffId]
                    );
                    if ($existingPrimary) {
                        throw new Exception(
                            'A primary subject teacher is already set for this subject. ' .
                                'Deactivate that assignment first, or uncheck "Primary".'
                        );
                    }
                }

                if ($sidOrNull === null) {
                    $existing = $db->fetchOne(
                        "SELECT id, deleted_at, is_active FROM teacher_class_assignments
                         WHERE tenant_id = ?
                           AND class_offering_id = ?
                           AND subject_id IS NULL
                           AND staff_id = ?
                           AND role = ?
                         FOR UPDATE",
                        [$tenantId, $offeringId, $staffId, $role]
                    );
                } else {
                    $existing = $db->fetchOne(
                        "SELECT id, deleted_at, is_active FROM teacher_class_assignments
                         WHERE tenant_id = ?
                           AND class_offering_id = ?
                           AND subject_id = ?
                           AND staff_id = ?
                           AND role = ?
                         FOR UPDATE",
                        [$tenantId, $offeringId, $sidOrNull, $staffId, $role]
                    );
                }

                if ($existing) {
                    if (empty($existing['deleted_at']) && (int)$existing['is_active'] === 1) {
                        $skippedCount++;
                        continue;
                    }
                    $db->execute(
                        "UPDATE teacher_class_assignments
                            SET is_primary = ?, academic_term_id = ?, notes = ?,
                                is_active = 1, deleted_at = NULL, updated_at = NOW()
                          WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                        [
                            $isPrimary,
                            $termId > 0 ? $termId : null,
                            $notes !== '' ? $notes : null,
                            (int)$existing['id'],
                            $tenantId,
                        ]
                    );
                    $updatedCount++;
                } else {
                    $db->insert(
                        "INSERT INTO teacher_class_assignments
                            (uuid, tenant_id, staff_id, class_offering_id, subject_id, role,
                             academic_year_id, academic_term_id, is_primary, is_active,
                             notes, created_by, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), NOW())",
                        [
                            uuidv4(),
                            $tenantId,
                            $staffId,
                            $offeringId,
                            $sidOrNull,
                            $role,
                            $yearId,
                            $termId > 0 ? $termId : null,
                            $isPrimary,
                            $notes !== '' ? $notes : null,
                            $userId ?: null,
                        ]
                    );
                    $addedCount++;
                }
            }

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.teacher_assignment.bulk_saved',
                'teacher_class_assignments',
                $offeringId,
                [
                    'offering_id' => $offeringId,
                    'staff_id'    => $staffId,
                    'role'        => $role,
                    'added'       => $addedCount,
                    'updated'     => $updatedCount,
                    'skipped'     => $skippedCount,
                ]
            );

            $db->commit();
            $_SESSION['success'] = sprintf(
                'Saved. %d added, %d updated, %d skipped.',
                $addedCount,
                $updatedCount,
                $skippedCount
            );
            header('Location: /platform/tenant/academic/teacher-assignments.php');
            exit;
        }

        // ---------- TOGGLE ACTIVE ----------
        if ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            $row = $db->fetchOne(
                "SELECT * FROM teacher_class_assignments
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Assignment not found.');

            $newActive = ((int)$row['is_active']) === 1 ? 0 : 1;

            $db->beginTransaction();

            if ($newActive === 1) {
                if ($row['role'] === 'class_teacher') {
                    $conflict = $db->fetchOne(
                        "SELECT id FROM teacher_class_assignments
                         WHERE tenant_id = ?
                           AND class_offering_id = ?
                           AND role = 'class_teacher'
                           AND is_active = 1
                           AND deleted_at IS NULL
                           AND id != ?
                         FOR UPDATE",
                        [$tenantId, (int)$row['class_offering_id'], $id]
                    );
                    if ($conflict) {
                        throw new Exception('Another active class teacher already exists for this offering.');
                    }
                }
                if ($row['role'] === 'subject_teacher' && (int)$row['is_primary'] === 1 && $row['subject_id'] !== null) {
                    $conflict = $db->fetchOne(
                        "SELECT id FROM teacher_class_assignments
                         WHERE tenant_id = ?
                           AND class_offering_id = ?
                           AND subject_id = ?
                           AND role = 'subject_teacher'
                           AND is_primary = 1
                           AND is_active = 1
                           AND deleted_at IS NULL
                           AND id != ?
                         FOR UPDATE",
                        [$tenantId, (int)$row['class_offering_id'], (int)$row['subject_id'], $id]
                    );
                    if ($conflict) {
                        throw new Exception('Another active primary subject teacher already exists for this subject.');
                    }
                }
            }

            $db->execute(
                "UPDATE teacher_class_assignments SET is_active = ?, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$newActive, $id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.teacher_assignment.toggled_active',
                'teacher_class_assignments',
                $id,
                ['is_active' => $newActive]
            );
            $db->commit();

            $_SESSION['success'] = 'Assignment is now ' . ($newActive ? 'active' : 'inactive') . '.';
            header('Location: /platform/tenant/academic/teacher-assignments.php');
            exit;
        }

        // ---------- UPDATE ----------
        if ($action === 'update') {
            $id        = (int)($_POST['id'] ?? 0);
            $isPrimary = !empty($_POST['is_primary']) ? 1 : 0;
            $termId    = (int)($_POST['academic_term_id'] ?? 0);
            $notes     = trim((string)($_POST['notes'] ?? ''));

            $row = $db->fetchOne(
                "SELECT * FROM teacher_class_assignments
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Assignment not found.');

            if ($termId > 0) {
                $term = $db->fetchOne(
                    "SELECT id FROM academic_terms WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$termId, $tenantId]
                );
                if (!$term) $termId = 0;
            }

            $db->beginTransaction();

            if (
                $isPrimary === 1
                && $row['role'] === 'subject_teacher'
                && $row['subject_id'] !== null
            ) {
                $conflict = $db->fetchOne(
                    "SELECT id FROM teacher_class_assignments
                     WHERE tenant_id = ?
                       AND class_offering_id = ?
                       AND subject_id = ?
                       AND role = 'subject_teacher'
                       AND is_primary = 1
                       AND is_active = 1
                       AND deleted_at IS NULL
                       AND id != ?
                     FOR UPDATE",
                    [$tenantId, (int)$row['class_offering_id'], (int)$row['subject_id'], $id]
                );
                if ($conflict) {
                    throw new Exception('Another primary subject teacher is already set for this subject.');
                }
            }

            $db->execute(
                "UPDATE teacher_class_assignments
                    SET is_primary = ?, academic_term_id = ?, notes = ?, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [
                    $isPrimary,
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
                'academic.teacher_assignment.updated',
                'teacher_class_assignments',
                $id,
                ['is_primary' => $isPrimary, 'term_id' => $termId]
            );
            $db->commit();

            $_SESSION['success'] = 'Assignment updated.';
            header('Location: /platform/tenant/academic/teacher-assignments.php');
            exit;
        }

        // ---------- DELETE ----------
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $row = $db->fetchOne(
                "SELECT * FROM teacher_class_assignments
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Assignment not found.');

            $db->beginTransaction();
            $db->execute(
                "UPDATE teacher_class_assignments
                    SET deleted_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.teacher_assignment.deleted',
                'teacher_class_assignments',
                $id,
                [
                    'offering_id' => (int)$row['class_offering_id'],
                    'staff_id'    => (int)$row['staff_id'],
                    'role'        => $row['role'],
                    'subject_id'  => $row['subject_id'] !== null ? (int)$row['subject_id'] : null,
                ]
            );
            $db->commit();

            $_SESSION['success'] = 'Assignment removed.';
            header('Location: /platform/tenant/academic/teacher-assignments.php');
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Academic teacher-assignments action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/academic/teacher-assignments.php');
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
$staffList = $db->fetchAll(
    "SELECT s.id, s.staff_number,
            p.first_name, p.middle_name, p.last_name, p.preferred_name
     FROM staff s
     LEFT JOIN persons p ON s.person_id = p.id
     WHERE s.tenant_id = ? AND s.is_active = 1 AND s.deleted_at IS NULL
     ORDER BY p.first_name ASC, p.last_name ASC",
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
$filterStaff   = (int)($_GET['staff_id'] ?? 0);
$filterRole    = trim((string)($_GET['role'] ?? ''));

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
if ($filterStaff > 0) {
    $where[] = "EXISTS (SELECT 1 FROM teacher_class_assignments tca2
                        WHERE tca2.class_offering_id = co.id
                          AND tca2.tenant_id = co.tenant_id
                          AND tca2.staff_id = ?
                          AND tca2.deleted_at IS NULL)";
    $params[] = $filterStaff;
}
if (in_array($filterRole, ['class_teacher', 'subject_teacher', 'assistant', 'special_education'], true)) {
    $where[] = "EXISTS (SELECT 1 FROM teacher_class_assignments tca3
                        WHERE tca3.class_offering_id = co.id
                          AND tca3.tenant_id = co.tenant_id
                          AND tca3.role = ?
                          AND tca3.deleted_at IS NULL)";
    $params[] = $filterRole;
}
$whereClause = implode(' AND ', $where);

$offerings = $db->fetchAll(
    "SELECT co.id, co.status,
            co.academic_year_id, co.academic_level_id, co.class_id, co.stream_id,
            ay.year_name,
            al.level_name, al.level_code, al.sort_order AS level_sort,
            c.class_name, c.class_code,
            s.stream_name,
            (SELECT COUNT(*) FROM teacher_class_assignments tca
             WHERE tca.tenant_id = co.tenant_id
               AND tca.class_offering_id = co.id
               AND tca.is_active = 1
               AND tca.deleted_at IS NULL) AS active_assignment_count
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

$assignmentsByOffering = [];
$classTeacherByOffering = [];
$subjectPrimaryMap = [];
if (!empty($offerings)) {
    $offeringIds = array_map(function ($o) {
        return (int)$o['id'];
    }, $offerings);
    $in = implode(',', array_fill(0, count($offeringIds), '?'));
    $rows = $db->fetchAll(
        "SELECT tca.id, tca.class_offering_id, tca.staff_id, tca.subject_id, tca.role,
                tca.academic_year_id, tca.academic_term_id, tca.is_primary, tca.is_active,
                tca.notes,
                s.subject_name, s.subject_code,
                st.staff_number,
                p.first_name, p.middle_name, p.last_name, p.preferred_name,
                t.term_name
         FROM teacher_class_assignments tca
         LEFT JOIN subjects s ON tca.subject_id = s.id
         LEFT JOIN staff st ON tca.staff_id = st.id
         LEFT JOIN persons p ON st.person_id = p.id
         LEFT JOIN academic_terms t ON tca.academic_term_id = t.id
         WHERE tca.tenant_id = ?
           AND tca.class_offering_id IN ($in)
           AND tca.deleted_at IS NULL
         ORDER BY tca.role ASC, s.subject_name ASC, p.first_name ASC",
        array_merge([$tenantId], $offeringIds)
    );
    foreach ($rows as $r) {
        $oid = (int)$r['class_offering_id'];
        if (!isset($assignmentsByOffering[$oid])) $assignmentsByOffering[$oid] = [];
        $assignmentsByOffering[$oid][] = $r;

        if ($r['role'] === 'class_teacher' && (int)$r['is_active'] === 1) {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            $classTeacherByOffering[$oid] = $name !== '' ? $name : ('Staff #' . (int)$r['staff_id']);
        }
        if ($r['role'] === 'subject_teacher' && (int)$r['is_primary'] === 1 && (int)$r['is_active'] === 1 && $r['subject_id'] !== null) {
            $subjectPrimaryMap[$oid . ':' . (int)$r['subject_id']] = (int)$r['id'];
        }
    }
}

$offeringSubjects = [];
foreach ($offerings as $o) {
    $oid = (int)$o['id'];
    $offeringSubjects[$oid] = getOfferingSubjects($db, $tenantId, $oid);
}

$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

function staffDisplay($row): string
{
    $pref = trim((string)($row['preferred_name'] ?? ''));
    if ($pref !== '') return $pref;
    $full = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    $full = preg_replace('/\s+/', ' ', $full);
    return $full !== '' ? $full : ('Staff #' . (int)($row['staff_id'] ?? 0));
}

$totalOfferings = count($offerings);
$totalActiveAssignments = 0;
foreach ($offerings as $o) $totalActiveAssignments += (int)$o['active_assignment_count'];

// Pre-compute JSON maps for the modal, with safe fallbacks.
$assignmentsJson = json_encode($assignmentsByOffering, JSON_UNESCAPED_UNICODE);
if (!is_string($assignmentsJson) || $assignmentsJson === '') {
    $assignmentsJson = '{}';
}
$offeringSubjectsJson = json_encode($offeringSubjects, JSON_UNESCAPED_UNICODE);
if (!is_string($offeringSubjectsJson) || $offeringSubjectsJson === '') {
    $offeringSubjectsJson = '{}';
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
            max-width: 720px;
        }

        .subject-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 6px;
            max-height: 280px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 8px;
            background: #fafbfc;
        }

        .subject-row {
            display: grid;
            grid-template-columns: 28px 1fr;
            gap: 10px;
            align-items: center;
            padding: 8px 10px;
            border-radius: 8px;
            transition: background 0.15s;
        }

        .subject-row:hover {
            background: #fff;
        }

        .subject-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .subject-title {
            font-size: 13px;
            font-weight: 500;
            color: #1a1a2e;
        }

        .subject-code {
            display: inline-block;
            background: #e3f0ff;
            color: #0d6efd;
            padding: 1px 8px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 600;
            font-family: 'Courier New', monospace;
            margin-left: 6px;
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
        }

        .existing-item .actions .btn {
            padding: 3px 8px;
            font-size: 11px;
            border-radius: 6px;
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
                        <h1><i class="fas fa-chalkboard-teacher me-2"></i>Teacher Assignments</h1>
                        <p>Assign teachers to offerings and subjects</p>
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
                                <?php echo h((string)$totalActiveAssignments); ?> active assignment(s)
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>Class teacher + subject teachers
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
                        <div class="ib-title">Two kinds of teaching roles</div>
                        <div class="ib-text">
                            <strong>Class teacher</strong> — one per offering, has no specific subject.
                            <strong>Subject teacher</strong> — one per subject, optionally flagged as the "Primary" teacher for that subject.
                            You can also mark a teacher as an <strong>Assistant</strong> or <strong>Special Education</strong> teacher for a subject.
                        </div>
                    </div>
                </div>

                <form method="GET" action="/platform/tenant/academic/teacher-assignments.php" class="filters-bar">
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
                        <label>Staff</label>
                        <select name="staff_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">All</option>
                            <?php foreach ($staffList as $st): ?>
                                <option value="<?php echo (int)$st['id']; ?>" <?php echo $filterStaff === (int)$st['id'] ? 'selected' : ''; ?>>
                                    <?php echo h(staffDisplay($st)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Role</label>
                        <select name="role" class="form-select" onchange="this.form.submit()">
                            <option value="">All</option>
                            <option value="class_teacher" <?php echo $filterRole === 'class_teacher'     ? 'selected' : ''; ?>>Class Teacher</option>
                            <option value="subject_teacher" <?php echo $filterRole === 'subject_teacher'   ? 'selected' : ''; ?>>Subject Teacher</option>
                            <option value="assistant" <?php echo $filterRole === 'assistant'         ? 'selected' : ''; ?>>Assistant</option>
                            <option value="special_education" <?php echo $filterRole === 'special_education' ? 'selected' : ''; ?>>Special Education</option>
                        </select>
                    </div>
                    <div class="filter-group" style="flex:0 0 auto;">
                        <a href="/platform/tenant/academic/teacher-assignments.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>

                <?php if (empty($byYear)): ?>
                    <div class="year-section">
                        <div class="empty-state">
                            <i class="fas fa-door-open"></i>
                            <h5>No class offerings yet</h5>
                            <p>Teacher assignments live inside class offerings. Create an offering first.</p>
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
                                    $asgCount = 0;
                                    foreach ($yearGroup['levels'] as $lg) {
                                        foreach ($lg['items'] as $oi) {
                                            $coCount++;
                                            $asgCount += (int)$oi['active_assignment_count'];
                                        }
                                    }
                                    ?>
                                    <span class="pill blue"><i class="fas fa-door-open"></i><?php echo h((string)$coCount); ?> offering(s)</span>
                                    <span class="pill purple"><i class="fas fa-chalkboard-teacher"></i><?php echo h((string)$asgCount); ?> assignment(s)</span>
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
                                                <th style="text-align:center;">Class Teacher</th>
                                                <th style="text-align:center;">Assignments</th>
                                                <th style="text-align:right;min-width:200px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($levelGroup['items'] as $o):
                                                $oid = (int)$o['id'];
                                                $ctName = $classTeacherByOffering[$oid] ?? null;
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
                                                        <?php if ($ctName): ?>
                                                            <span class="pill green">
                                                                <i class="fas fa-user-check"></i><?php echo h($ctName); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="pill gray">
                                                                <i class="fas fa-user-slash"></i>Not set
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <?php $cnt = (int)$o['active_assignment_count']; ?>
                                                        <span class="pill <?php echo $cnt > 0 ? 'purple' : 'gray'; ?>">
                                                            <i class="fas fa-chalkboard-teacher"></i><?php echo h((string)$cnt); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex justify-content-end gap-2 flex-wrap">
                                                            <button type="button"
                                                                class="btn btn-outline-primary js-manage-teachers"
                                                                data-offering-id="<?php echo $oid; ?>"
                                                                data-offering-label="<?php echo h(($o['class_name'] ?? '') . (($o['stream_name'] ?? '') ? ' / ' . $o['stream_name'] : '') . ' · ' . ($o['year_name'] ?? '') . ' · ' . ($o['level_name'] ?? '')); ?>"
                                                                data-year-id="<?php echo (int)$o['academic_year_id']; ?>">
                                                                <i class="fas fa-tasks"></i> Manage Teachers
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

    <div class="modal fade" id="manageTeachersModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="POST" id="bulkSaveForm" action="/platform/tenant/academic/teacher-assignments.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="bulk_save">
                    <input type="hidden" name="class_offering_id" id="modalOfferingId" value="">
                    <div class="modal-header">
                        <h5>
                            <i class="fas fa-chalkboard-teacher text-primary me-2"></i>
                            Manage Teachers
                            <small class="text-muted d-block" id="modalOfferingLabel" style="font-size:12px;font-weight:400;margin-top:2px;"></small>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="addStaffId">Staff Member <span class="required">*</span></label>
                                <select class="form-select" id="addStaffId" name="staff_id" required>
                                    <option value="">Select staff...</option>
                                    <?php foreach ($staffList as $st): ?>
                                        <option value="<?php echo (int)$st['id']; ?>">
                                            <?php echo h(staffDisplay($st)); ?>
                                            <?php if (!empty($st['staff_number'])): ?> (<?php echo h($st['staff_number']); ?>)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="addRole">Role <span class="required">*</span></label>
                                <select class="form-select" id="addRole" name="role" required>
                                    <option value="class_teacher">Class Teacher</option>
                                    <option value="subject_teacher" selected>Subject Teacher</option>
                                    <option value="assistant">Assistant</option>
                                    <option value="special_education">Special Education</option>
                                </select>
                            </div>
                        </div>

                        <div class="row" id="subjectPickerWrap">
                            <div class="col-12 mb-3">
                                <label class="form-label">Subjects <span class="required" id="subjectsRequiredStar">*</span></label>
                                <div class="subject-grid" id="subjectGrid"></div>
                                <div class="form-text" id="subjectPickerHint">Only subjects already in this offering's class_subjects appear here.</div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="addTermId"><?php echo h($labelTerm); ?></label>
                                <select class="form-select" id="addTermId" name="academic_term_id">
                                    <option value="0">(Use current term if any)</option>
                                    <?php foreach ($terms as $t): ?>
                                        <option value="<?php echo (int)$t['id']; ?>" <?php echo ((int)$t['id'] === $currentTermId) ? 'selected' : ''; ?>>
                                            <?php echo h($t['term_name']); ?>
                                            <?php if ((int)$t['is_current'] === 1): ?> ★<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="addIsPrimary" name="is_primary" value="1">
                                    <label class="form-check-label" for="addIsPrimary">Primary teacher for this subject</label>
                                    <div class="form-text">Ignored for Class Teacher role.</div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="addNotes">Notes</label>
                            <input type="text" class="form-control" id="addNotes" name="notes" maxlength="500" placeholder="Optional">
                        </div>

                        <div class="existing-panel" id="existingPanel">
                            <h6><i class="fas fa-list-check text-primary"></i>Existing assignments for this offering</h6>
                            <div class="existing-list" id="existingList"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Add Assignment(s)</button>
                    </div>
                </form>

                <form method="POST" id="singleActionForm" action="/platform/tenant/academic/teacher-assignments.php" style="display:none;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" id="singleActionName" value="">
                    <input type="hidden" name="id" id="singleActionId" value="">
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var ASSIGNMENTS_BY_OFFERING = <?php echo $assignmentsJson; ?>;
        if (typeof ASSIGNMENTS_BY_OFFERING !== 'object' || ASSIGNMENTS_BY_OFFERING === null) {
            ASSIGNMENTS_BY_OFFERING = {};
        }
        var OFFERING_SUBJECTS = <?php echo $offeringSubjectsJson; ?>;
        if (typeof OFFERING_SUBJECTS !== 'object' || OFFERING_SUBJECTS === null) {
            OFFERING_SUBJECTS = {};
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
            const modalEl = document.getElementById('manageTeachersModal');
            const modal = new bootstrap.Modal(modalEl);
            const offeringIdInput = document.getElementById('modalOfferingId');
            const offeringLabelEl = document.getElementById('modalOfferingLabel');
            const roleSel = document.getElementById('addRole');
            const subjectWrap = document.getElementById('subjectPickerWrap');
            const subjectGrid = document.getElementById('subjectGrid');
            const subjectsStar = document.getElementById('subjectsRequiredStar');
            const subjectHint = document.getElementById('subjectPickerHint');
            const existingPanel = document.getElementById('existingPanel');
            const existingListEl = document.getElementById('existingList');
            const primaryCheckbox = document.getElementById('addIsPrimary');
            const staffSel = document.getElementById('addStaffId');
            const termSel = document.getElementById('addTermId');
            const notesInput = document.getElementById('addNotes');
            const singleForm = document.getElementById('singleActionForm');
            const singleActionName = document.getElementById('singleActionName');
            const singleActionId = document.getElementById('singleActionId');

            let currentOfferingId = 0;

            function escapeHtml(s) {
                if (s === null || s === undefined) return '';
                return String(s)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function renderSubjectGrid() {
                const list = OFFERING_SUBJECTS[currentOfferingId] || [];
                subjectGrid.innerHTML = '';
                if (!list.length) {
                    subjectGrid.innerHTML = '<div class="p-3 text-muted">No subjects are set for this offering yet. Add subjects under <strong>Class Subjects</strong> first.</div>';
                    return;
                }
                list.forEach(function(s) {
                    const row = document.createElement('label');
                    row.className = 'subject-row';
                    row.innerHTML = '' +
                        '<input type="checkbox" name="subject_ids[]" value="' + parseInt(s.id, 10) + '">' +
                        '<span class="subject-title">' + escapeHtml(s.subject_name || '') + '<span class="subject-code">' + escapeHtml(s.subject_code || '') + '</span></span>';
                    subjectGrid.appendChild(row);
                });
            }

            function renderExistingPanel() {
                const rows = ASSIGNMENTS_BY_OFFERING[currentOfferingId] || [];
                if (!rows.length) {
                    existingPanel.style.display = 'none';
                    existingListEl.innerHTML = '';
                    return;
                }
                existingPanel.style.display = 'block';
                existingListEl.innerHTML = '';
                rows.forEach(function(r) {
                    const act = parseInt(r.is_active, 10) === 1;
                    const isPrimary = parseInt(r.is_primary, 10) === 1;
                    const roleLabel = ({
                        'class_teacher': 'Class Teacher',
                        'subject_teacher': 'Subject Teacher',
                        'assistant': 'Assistant',
                        'special_education': 'Special Education'
                    })[r.role] || r.role;
                    const subj = r.subject_name ?
                        (escapeHtml(r.subject_name) + ' <span class="subject-code">' + escapeHtml(r.subject_code || '') + '</span>') :
                        '<em>no subject</em>';
                    const name = r.preferred_name || ((r.first_name || '') + ' ' + (r.last_name || '')).trim();
                    const term = r.term_name ? (' · Term: ' + escapeHtml(r.term_name)) : '';
                    const primaryTag = isPrimary ? ' · <span class="pill green"><i class="fas fa-star"></i>Primary</span>' : '';
                    const activeTag = act ?
                        '<span class="pill green"><i class="fas fa-check"></i>Active</span>' :
                        '<span class="pill gray"><i class="fas fa-times"></i>Inactive</span>';
                    const item = document.createElement('div');
                    item.className = 'existing-item';
                    item.innerHTML = '' +
                        '<div class="info">' +
                        '<div class="title">' + escapeHtml(name || ('Staff #' + r.staff_id)) + '<span class="pill blue" style="margin-left:6px;">' + escapeHtml(roleLabel) + '</span></div>' +
                        '<div class="meta">' + subj + term + primaryTag + ' · ' + activeTag + '</div>' +
                        '</div>' +
                        '<div class="actions">' +
                        '<button type="button" class="btn btn-outline-warning js-toggle-existing" data-id="' + parseInt(r.id, 10) + '" title="' + (act ? 'Deactivate' : 'Activate') + '">' +
                        '<i class="fas fa-' + (act ? 'pause' : 'play') + '"></i>' +
                        '</button>' +
                        '<button type="button" class="btn btn-outline-danger js-delete-existing" data-id="' + parseInt(r.id, 10) + '" title="Remove">' +
                        '<i class="fas fa-trash"></i>' +
                        '</button>' +
                        '</div>';
                    existingListEl.appendChild(item);
                });
            }

            function syncRoleUI() {
                const role = roleSel.value;
                if (role === 'class_teacher') {
                    subjectWrap.style.display = 'none';
                    subjectsStar.style.display = 'none';
                    primaryCheckbox.checked = false;
                    primaryCheckbox.disabled = true;
                } else {
                    subjectWrap.style.display = '';
                    subjectsStar.style.display = '';
                    primaryCheckbox.disabled = false;
                }
            }
            roleSel.addEventListener('change', syncRoleUI);

            document.querySelectorAll('.js-manage-teachers').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    currentOfferingId = parseInt(this.getAttribute('data-offering-id'), 10);
                    offeringIdInput.value = currentOfferingId;
                    offeringLabelEl.textContent = this.getAttribute('data-offering-label') || '';

                    staffSel.value = '';
                    roleSel.value = 'subject_teacher';
                    termSel.value = CURRENT_TERM_ID ? String(CURRENT_TERM_ID) : '0';
                    primaryCheckbox.checked = false;
                    primaryCheckbox.disabled = false;
                    notesInput.value = '';

                    renderSubjectGrid();
                    renderExistingPanel();
                    syncRoleUI();
                    modal.show();
                });
            });

            document.addEventListener('click', function(e) {
                const tgl = e.target.closest('.js-toggle-existing');
                if (tgl) {
                    if (!confirm('Toggle active status for this assignment?')) return;
                    singleActionName.value = 'toggle_active';
                    singleActionId.value = tgl.getAttribute('data-id');
                    singleForm.submit();
                    return;
                }
                const del = e.target.closest('.js-delete-existing');
                if (del) {
                    if (!confirm('Remove this teacher assignment?')) return;
                    singleActionName.value = 'delete';
                    singleActionId.value = del.getAttribute('data-id');
                    singleForm.submit();
                }
            });

            document.getElementById('bulkSaveForm').addEventListener('submit', function(e) {
                const role = roleSel.value;
                if (!staffSel.value) {
                    e.preventDefault();
                    alert('Please choose a staff member.');
                    return;
                }
                if (role !== 'class_teacher') {
                    const checked = this.querySelectorAll('input[name="subject_ids[]"]:checked').length;
                    if (checked === 0) {
                        e.preventDefault();
                        alert('Please select at least one subject.');
                        return;
                    }
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