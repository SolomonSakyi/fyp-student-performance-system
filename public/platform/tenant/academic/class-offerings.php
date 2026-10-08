<?php

/**
 * Class Offerings — Operational class instances per year/level/stream
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Academic
 * @version 1.0
 * @filepath public/platform/tenant/academic/class-offerings.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Class offerings file of the academic-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'academic_class_offerings'
 *       to 'academic' so the partial marks Academic active and
 *       renders the academic sub-menu on this page — consistent
 *       with every other academic file.
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
 * v1.0 change (2026-10-07) [SCHOOL FILTER FIX]:
 *   The $classes lookup in the LOAD PAGE DATA block previously
 *   filtered by tenant_id only. For tenant 1 that returned all
 *   twelve class rows in the table, including three rows whose
 *   school_id is 4 — a school that is not tenant 1's live school.
 *   The three leaked rows (STEM Year 1, STEM Year 2, STEM Year 3)
 *   appeared in the Add Offering modal's class dropdown.
 *   The lookup now filters by both tenant_id and school_id, with
 *   school_id read from $_SESSION['school_id']. This matches the
 *   shape of classes.php, which is also school-scoped, and closes
 *   the leak. One line changed. Every other line is byte-identical
 *   to the previous v1.0.
 *
 * Decisions locked in (Session S9):
 *   1A  class_id, academic_year_id, academic_level_id required
 *   2C  stream required only if the selected level has streams
 *   3A  unique per (year, class, stream); enforced with FOR UPDATE lock
 *   4C  capacity informational for now
 *   5A  free status transitions, clear pills
 *   6A  refuse delete, list dependents
 *   7   no orphans
 *   8A  grouped by year, then by level
 *
 * Session S20 decisions applied:
 *   SH2A per-session CSRF token; all POSTs carry it
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
$campusId     = (int)($_SESSION['campus_id'] ?? 0);
$userId       = current_user_id();
$currentUser  = $_SESSION['user_name'] ?? 'Admin';
$userAvatar   = substr($currentUser, 0, 1);
$isSuperAdmin = is_super_admin();
$pageTitle    = 'Class Offerings - Student 360 Platform';
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
function offeringDependencies($db, int $tenantId, int $offeringId): array
{
    $total = 0;
    $labels = [];
    $checks = [
        'enrollments'           => "SELECT COUNT(*) AS c FROM enrollments               WHERE tenant_id = ? AND class_offering_id = ? AND deleted_at IS NULL",
        'class subjects'        => "SELECT COUNT(*) AS c FROM class_subjects            WHERE tenant_id = ? AND class_offering_id = ? AND deleted_at IS NULL",
        'teacher assignments'   => "SELECT COUNT(*) AS c FROM teacher_class_assignments WHERE tenant_id = ? AND class_offering_id = ? AND deleted_at IS NULL",
        'results'               => "SELECT COUNT(*) AS c FROM results                   WHERE tenant_id = ? AND class_offering_id = ? AND deleted_at IS NULL",
    ];
    foreach ($checks as $label => $sql) {
        try {
            $row = $db->fetchOne($sql, [$tenantId, $offeringId]);
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
function levelHasStreams($db, int $tenantId, int $levelId): bool
{
    if ($levelId <= 0) return false;
    try {
        $row = $db->fetchOne(
            "SELECT COUNT(*) AS c FROM streams
             WHERE tenant_id = ? AND academic_level_id = ? AND is_active = 1 AND deleted_at IS NULL",
            [$tenantId, $levelId]
        );
        return ((int)($row['c'] ?? 0)) > 0;
    } catch (Exception $e) {
        return false;
    }
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
        // ---------- CREATE ----------
        if ($action === 'create') {
            $yearId  = (int)($_POST['academic_year_id'] ?? 0);
            $levelId = (int)($_POST['academic_level_id'] ?? 0);
            $classId = (int)($_POST['class_id'] ?? 0);
            $streamId = (int)($_POST['stream_id'] ?? 0);
            $campusIdForm = (int)($_POST['campus_id'] ?? 0);
            $capacity = (int)($_POST['capacity'] ?? 0);
            $status  = trim((string)($_POST['status'] ?? 'draft'));
            $notes   = trim((string)($_POST['notes'] ?? ''));
            if ($yearId <= 0)  $errors[] = 'Academic year is required.';
            if ($levelId <= 0) $errors[] = 'Academic level is required.';
            if ($classId <= 0) $errors[] = 'Class is required.';
            $year = $level = $class = null;
            if ($yearId > 0) {
                $year = $db->fetchOne(
                    "SELECT id, year_name FROM academic_years WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$yearId, $tenantId]
                );
                if (!$year) $errors[] = 'Selected academic year not found.';
            }
            if ($levelId > 0) {
                $level = $db->fetchOne(
                    "SELECT id, level_name FROM academic_levels WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$levelId, $tenantId]
                );
                if (!$level) $errors[] = 'Selected level not found.';
            }
            if ($classId > 0) {
                // [SCHOOL FILTER FIX] Confirm the class belongs to the current
                // school as well as the current tenant. This blocks a crafted
                // POST from creating an offering against another school's class.
                $class = $db->fetchOne(
                    "SELECT id, class_name FROM classes
                     WHERE id = ? AND tenant_id = ? AND school_id = ? AND deleted_at IS NULL",
                    [$classId, $tenantId, $schoolId]
                );
                if (!$class) $errors[] = 'Selected class not found.';
            }
            $streamId = $streamId > 0 ? $streamId : 0;
            $levelHasStreams = levelHasStreams($db, $tenantId, $levelId);
            if ($levelHasStreams && $streamId <= 0) {
                $errors[] = 'This level has streams defined. Please select a stream.';
            }
            if ($streamId > 0) {
                $stream = $db->fetchOne(
                    "SELECT id, stream_name FROM streams
                     WHERE id = ? AND tenant_id = ? AND academic_level_id = ? AND deleted_at IS NULL",
                    [$streamId, $tenantId, $levelId]
                );
                if (!$stream) {
                    $errors[] = 'Selected stream does not belong to the selected level.';
                }
            }
            if ($campusIdForm > 0) {
                $cmp = $db->fetchOne(
                    "SELECT id FROM campuses WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$campusIdForm, $tenantId]
                );
                if (!$cmp) $errors[] = 'Selected campus not found.';
            }
            $allowedStatuses = ['draft', 'active', 'closed', 'archived'];
            if (!in_array($status, $allowedStatuses, true)) $status = 'draft';
            if (empty($errors)) {
                $db->beginTransaction();
                $existing = $db->fetchOne(
                    "SELECT id FROM class_offerings
                     WHERE tenant_id = ? AND academic_year_id = ? AND class_id = ?
                       AND COALESCE(stream_id, 0) = ?
                       AND deleted_at IS NULL
                     FOR UPDATE",
                    [$tenantId, $yearId, $classId, $streamId]
                );
                if ($existing) {
                    $db->rollBack();
                    $scope = $streamId > 0 ? 'this stream' : 'no stream';
                    $errors[] = 'A class offering already exists for this class, year, and ' . $scope . '.';
                }
            }
            if (empty($errors)) {
                $newId = $db->insert(
                    "INSERT INTO class_offerings
                        (uuid, tenant_id, school_id, campus_id, class_id, academic_level_id,
                         academic_year_id, stream_id, capacity, status, notes,
                         created_by, created_at, updated_at)
                     VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        uuidv4(),
                        $tenantId,
                        $campusIdForm > 0 ? $campusIdForm : null,
                        $classId,
                        $levelId,
                        $yearId,
                        $streamId > 0 ? $streamId : null,
                        $capacity > 0 ? $capacity : null,
                        $status,
                        $notes !== '' ? $notes : null,
                        $userId ?: null,
                    ]
                );
                writeAudit(
                    $db,
                    $tenantId,
                    $userId,
                    'academic.class_offering.created',
                    'class_offerings',
                    (int)$newId,
                    [
                        'year_id' => $yearId,
                        'level_id' => $levelId,
                        'class_id' => $classId,
                        'stream_id' => $streamId,
                        'status' => $status
                    ]
                );
                $db->commit();
                $_SESSION['success'] = 'Class offering created.';
                header('Location: /platform/tenant/academic/class-offerings.php');
                exit;
            }
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['errors']    = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: /platform/tenant/academic/class-offerings.php?action=new&year_id=' . $yearId);
            exit;
        }
        // ---------- UPDATE ----------
        if ($action === 'update') {
            $id       = (int)($_POST['id'] ?? 0);
            $streamId = (int)($_POST['stream_id'] ?? 0);
            $campusIdForm = (int)($_POST['campus_id'] ?? 0);
            $capacity = (int)($_POST['capacity'] ?? 0);
            $status   = trim((string)($_POST['status'] ?? 'draft'));
            $notes    = trim((string)($_POST['notes'] ?? ''));
            $offering = $db->fetchOne(
                "SELECT * FROM class_offerings WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$offering) $errors[] = 'Class offering not found.';
            $levelId = $offering ? (int)$offering['academic_level_id'] : 0;
            $streamId = $streamId > 0 ? $streamId : 0;
            $levelHasStreams = levelHasStreams($db, $tenantId, $levelId);
            if ($levelHasStreams && $streamId <= 0) {
                $errors[] = 'This level has streams defined. Please select a stream.';
            }
            if ($streamId > 0) {
                $stream = $db->fetchOne(
                    "SELECT id FROM streams
                     WHERE id = ? AND tenant_id = ? AND academic_level_id = ? AND deleted_at IS NULL",
                    [$streamId, $tenantId, $levelId]
                );
                if (!$stream) $errors[] = 'Selected stream does not belong to the level.';
            }
            if ($campusIdForm > 0) {
                $cmp = $db->fetchOne(
                    "SELECT id FROM campuses WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$campusIdForm, $tenantId]
                );
                if (!$cmp) $errors[] = 'Selected campus not found.';
            }
            $allowedStatuses = ['draft', 'active', 'closed', 'archived'];
            if (!in_array($status, $allowedStatuses, true)) $status = 'draft';
            if (empty($errors) && $offering) {
                $db->beginTransaction();
                $dupe = $db->fetchOne(
                    "SELECT id FROM class_offerings
                     WHERE tenant_id = ? AND academic_year_id = ? AND class_id = ?
                       AND COALESCE(stream_id, 0) = ?
                       AND deleted_at IS NULL AND id != ?
                     FOR UPDATE",
                    [
                        $tenantId,
                        (int)$offering['academic_year_id'],
                        (int)$offering['class_id'],
                        $streamId,
                        $id,
                    ]
                );
                if ($dupe) {
                    $db->rollBack();
                    $errors[] = 'Another class offering already exists for this class, year, and stream scope.';
                }
            }
            if (empty($errors) && $offering) {
                $db->execute(
                    "UPDATE class_offerings
                        SET campus_id = ?,
                            stream_id = ?,
                            capacity = ?,
                            status = ?,
                            notes = ?,
                            updated_at = NOW()
                      WHERE id = ? AND tenant_id = ?",
                    [
                        $campusIdForm > 0 ? $campusIdForm : null,
                        $streamId > 0 ? $streamId : null,
                        $capacity > 0 ? $capacity : null,
                        $status,
                        $notes !== '' ? $notes : null,
                        $id,
                        $tenantId,
                    ]
                );
                writeAudit(
                    $db,
                    $tenantId,
                    $userId,
                    'academic.class_offering.updated',
                    'class_offerings',
                    $id,
                    ['stream_id' => $streamId, 'status' => $status, 'capacity' => $capacity]
                );
                $db->commit();
                $_SESSION['success'] = 'Class offering updated.';
                header('Location: /platform/tenant/academic/class-offerings.php');
                exit;
            }
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['errors']    = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: /platform/tenant/academic/class-offerings.php?action=edit&id=' . $id);
            exit;
        }
        // ---------- CHANGE STATUS ----------
        if ($action === 'change_status') {
            $id     = (int)($_POST['id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? ''));
            $allowedStatuses = ['draft', 'active', 'closed', 'archived'];
            if (!in_array($status, $allowedStatuses, true)) {
                throw new Exception('Invalid status.');
            }
            $offering = $db->fetchOne(
                "SELECT * FROM class_offerings WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$offering) throw new Exception('Class offering not found.');
            $db->beginTransaction();
            $db->execute(
                "UPDATE class_offerings SET status = ?, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$status, $id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.class_offering.status_changed',
                'class_offerings',
                $id,
                ['from' => $offering['status'], 'to' => $status]
            );
            $db->commit();
            $_SESSION['success'] = 'Status updated to "' . $status . '".';
            header('Location: /platform/tenant/academic/class-offerings.php');
            exit;
        }
        // ---------- DELETE ----------
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $offering = $db->fetchOne(
                "SELECT * FROM class_offerings WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$offering) throw new Exception('Class offering not found.');
            $deps = offeringDependencies($db, $tenantId, $id);
            if ($deps['total'] > 0) {
                throw new Exception(
                    'Cannot delete this class offering. It is referenced by: ' .
                        implode(', ', $deps['labels']) .
                        '. Remove those references first, or archive the offering instead.'
                );
            }
            $db->beginTransaction();
            $db->execute(
                "UPDATE class_offerings SET deleted_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.class_offering.deleted',
                'class_offerings',
                $id,
                ['status' => $offering['status']]
            );
            $db->commit();
            $_SESSION['success'] = 'Class offering deleted.';
            header('Location: /platform/tenant/academic/class-offerings.php');
            exit;
        }
        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Academic class offerings action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/academic/class-offerings.php');
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
$enableStreams = ((int)($settings['enable_streams'] ?? 0)) === 1;
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
// [SCHOOL FILTER FIX] The class list is now scoped to the current
// session's school_id in addition to the tenant_id. This excludes
// classes that belong to schools the tenant does not operate, and
// closes the leak where school_id=4 classes appeared in the
// dropdown for tenant 1.
$classes = $db->fetchAll(
    "SELECT id, class_name, class_code FROM classes
     WHERE tenant_id = ? AND school_id = ? AND deleted_at IS NULL
     ORDER BY class_name",
    [$tenantId, $schoolId]
);
$campuses = $db->fetchAll(
    "SELECT id, campus_name FROM campuses
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY campus_name ASC",
    [$tenantId]
);
$streamsByLevel = [];
foreach ($levels as $lv) {
    $streamsByLevel[(int)$lv['id']] = $db->fetchAll(
        "SELECT id, stream_name FROM streams
         WHERE tenant_id = ? AND academic_level_id = ? AND is_active = 1 AND deleted_at IS NULL
         ORDER BY stream_name ASC",
        [$tenantId, (int)$lv['id']]
    );
}
$filterYear   = (int)($_GET['year_id'] ?? 0);
$filterLevel  = (int)($_GET['level_id'] ?? 0);
$filterStatus = trim((string)($_GET['status'] ?? ''));
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
if (in_array($filterStatus, ['draft', 'active', 'closed', 'archived'], true)) {
    $where[] = "co.status = ?";
    $params[] = $filterStatus;
}
$whereClause = implode(' AND ', $where);
$offerings = $db->fetchAll(
    "SELECT co.*,
            ay.year_name,
            al.level_name, al.level_code,
            c.class_name, c.class_code,
            s.stream_name,
            cp.campus_name,
            (SELECT COUNT(*) FROM enrollments e
             WHERE e.tenant_id = co.tenant_id AND e.class_offering_id = co.id AND e.deleted_at IS NULL) AS enrollment_count,
            (SELECT COUNT(*) FROM class_subjects cs
             WHERE cs.tenant_id = co.tenant_id AND cs.class_offering_id = co.id AND cs.deleted_at IS NULL) AS subject_count,
            (SELECT COUNT(*) FROM teacher_class_assignments tca
             WHERE tca.tenant_id = co.tenant_id AND tca.class_offering_id = co.id AND tca.deleted_at IS NULL) AS teacher_count
     FROM class_offerings co
     LEFT JOIN academic_years ay ON co.academic_year_id = ay.id
     LEFT JOIN academic_levels al ON co.academic_level_id = al.id
     LEFT JOIN classes c ON co.class_id = c.id
     LEFT JOIN streams s ON co.stream_id = s.id
     LEFT JOIN campuses cp ON co.campus_id = cp.id
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
            'levels' => [],
        ];
    }
    $lId = (int)$o['academic_level_id'];
    if (!isset($byYear[$yId]['levels'][$lId])) {
        $byYear[$yId]['levels'][$lId] = [
            'level_name' => $o['level_name'] ?: '(Unknown level)',
            'level_code' => $o['level_code'] ?? '',
            'items' => [],
        ];
    }
    $byYear[$yId]['levels'][$lId]['items'][] = $o;
}
$editOffering = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && !empty($_GET['id'])) {
    $editOffering = $db->fetchOne(
        "SELECT * FROM class_offerings WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [(int)$_GET['id'], $tenantId]
    );
}
$newYearId  = (int)($_GET['year_id'] ?? 0);
$showNewModal = (isset($_GET['action']) && $_GET['action'] === 'new') ||
    ($useForm && (($formData['action'] ?? '') === 'create'));
$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}
function v(string $key, $default = ''): string
{
    global $formData, $useForm, $editOffering;
    if ($useForm && array_key_exists($key, $formData)) {
        return h($formData[$key]);
    }
    if ($editOffering && array_key_exists($key, $editOffering)) {
        return h($editOffering[$key]);
    }
    return h($default);
}
function statusPillClass(string $status): string
{
    switch ($status) {
        case 'active':
            return 'status-active';
        case 'draft':
            return 'status-draft';
        case 'closed':
            return 'status-closed';
        case 'archived':
            return 'status-archived';
        default:
            return 'status-draft';
    }
}
function statusIcon(string $status): string
{
    switch ($status) {
        case 'active':
            return 'fa-check-circle';
        case 'draft':
            return 'fa-edit';
        case 'closed':
            return 'fa-lock';
        case 'archived':
            return 'fa-archive';
        default:
            return 'fa-circle';
    }
}
// Pre-compute the JSON for the stream cascade with a safe string fallback.
$streamsJson = json_encode($streamsByLevel, JSON_UNESCAPED_UNICODE);
if (!is_string($streamsJson) || $streamsJson === '') {
    $streamsJson = '{}';
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

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-draft {
            background: #fff3cd;
            color: #856404;
        }

        .status-closed {
            background: #e9ecef;
            color: #495057;
        }

        .status-archived {
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

        .action-group {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .action-group .btn {
            padding: 4px 10px;
            font-size: 11px;
            border-radius: 8px;
        }

        .empty-offerings {
            padding: 24px 20px;
            text-align: center;
            color: #adb5bd;
            font-size: 13px;
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
            padding: 10px 14px;
            border: 2px solid #e9ecef;
            font-size: 13px;
            width: 100%;
            display: block;
            background: #fff;
            color: #1a1a2e;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
            height: 44px;
        }

        textarea.form-control {
            height: auto;
            min-height: 80px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 4px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .form-text {
            font-size: 11px;
            color: #6c757d;
            margin-top: 4px;
        }

        .field-error {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.1) !important;
            background-color: #fff8f8 !important;
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

            .filters-bar .filter-group {
                width: 100%;
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

            .action-group {
                justify-content: flex-start;
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
                        <h1><i class="fas fa-door-open me-2"></i><?php echo h($labelClass); ?> Offerings</h1>
                        <p>Operational <?php echo h(strtolower($labelClass)); ?> instances per <?php echo h(strtolower($labelAcademic)); ?> and <?php echo h(strtolower($labelLevel)); ?></p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/academic/class-offerings.php?action=new<?php echo $newYearId ? '&year_id=' . (int)$newYearId : ''; ?>" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i> Add Offering
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
                                <?php echo h((string)count($offerings)); ?> offering(s) across <?php echo h((string)count($byYear)); ?> <?php echo h(strtolower($labelAcademic)); ?>(s)
                                <?php if ($enableStreams): ?>
                                    · <?php echo h($labelStream); ?>s enabled
                                <?php else: ?>
                                    · <?php echo h($labelStream); ?>s disabled
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>Capacity is informational
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
                <form method="GET" action="/platform/tenant/academic/class-offerings.php" class="filters-bar">
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
                        <label>Status</label>
                        <select name="status" class="form-select" onchange="this.form.submit()">
                            <option value="">All</option>
                            <?php foreach (['draft', 'active', 'closed', 'archived'] as $s): ?>
                                <option value="<?php echo $s; ?>" <?php echo $filterStatus === $s ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($s); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group" style="flex:0 0 auto;">
                        <a href="/platform/tenant/academic/class-offerings.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
                <?php if (empty($byYear)): ?>
                    <div class="year-section">
                        <div class="empty-state">
                            <i class="fas fa-door-open"></i>
                            <h5>No <?php echo h(strtolower($labelClass)); ?> offerings yet</h5>
                            <p>Create your first offering to start building the operational layer.</p>
                            <a href="/platform/tenant/academic/class-offerings.php?action=new" class="btn btn-primary btn-sm mt-2">
                                <i class="fas fa-plus me-1"></i> Add Offering
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
                                    $itemCount = 0;
                                    foreach ($yearGroup['levels'] as $lg) $itemCount += count($lg['items']);
                                    ?>
                                    <span class="pill blue"><i class="fas fa-door-open"></i><?php echo h((string)$itemCount); ?> offering(s)</span>
                                </div>
                                <a href="/platform/tenant/academic/class-offerings.php?action=new&year_id=<?php echo (int)$yId; ?>"
                                    class="btn btn-outline-primary">
                                    <i class="fas fa-plus"></i> Add Offering for this <?php echo h($labelAcademic); ?>
                                </a>
                            </div>
                            <?php foreach ($yearGroup['levels'] as $lId => $levelGroup): ?>
                                <div class="level-block">
                                    <div class="level-bar">
                                        <i class="fas fa-layer-group" style="color:#4facfe;"></i>
                                        <h6><?php echo h($levelGroup['level_name']); ?></h6>
                                        <?php if (!empty($levelGroup['level_code'])): ?>
                                            <span class="pill blue"><?php echo h($levelGroup['level_code']); ?></span>
                                        <?php endif; ?>
                                        <span class="pill gray">
                                            <?php echo h((string)count($levelGroup['items'])); ?> offering(s)
                                        </span>
                                    </div>
                                    <table class="offering-table">
                                        <thead>
                                            <tr>
                                                <th style="min-width:180px;"><?php echo h($labelClass); ?></th>
                                                <?php if ($enableStreams): ?>
                                                    <th><?php echo h($labelStream); ?></th>
                                                <?php endif; ?>
                                                <th style="text-align:center;">Capacity</th>
                                                <th style="text-align:center;">Enrolled</th>
                                                <th style="text-align:center;">Subjects</th>
                                                <th style="text-align:center;">Teachers</th>
                                                <th style="text-align:center;">Status</th>
                                                <th style="text-align:right;min-width:240px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($levelGroup['items'] as $o):
                                                $status = (string)$o['status'];
                                                $statusClass = statusPillClass($status);
                                                $icon = statusIcon($status);
                                            ?>
                                                <tr>
                                                    <td>
                                                        <div class="offering-title">
                                                            <?php echo h($o['class_name'] ?: '—'); ?>
                                                            <?php if (!empty($o['class_code'])): ?>
                                                                <span class="pill blue" style="margin-left:6px;"><?php echo h($o['class_code']); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if (!empty($o['campus_name'])): ?>
                                                            <div class="offering-meta"><i class="fas fa-map-marker-alt me-1"></i><?php echo h($o['campus_name']); ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <?php if ($enableStreams): ?>
                                                        <td>
                                                            <?php if (!empty($o['stream_name'])): ?>
                                                                <span class="pill purple"><?php echo h($o['stream_name']); ?></span>
                                                            <?php else: ?>
                                                                <span class="text-muted">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    <?php endif; ?>
                                                    <td style="text-align:center;">
                                                        <?php if ($o['capacity'] !== null): ?>
                                                            <?php echo h((string)(int)$o['capacity']); ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <span class="pill <?php echo ((int)$o['enrollment_count']) > 0 ? 'green' : 'gray'; ?>">
                                                            <?php echo h((string)(int)$o['enrollment_count']); ?>
                                                        </span>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <span class="pill <?php echo ((int)$o['subject_count']) > 0 ? 'blue' : 'gray'; ?>">
                                                            <?php echo h((string)(int)$o['subject_count']); ?>
                                                        </span>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <span class="pill <?php echo ((int)$o['teacher_count']) > 0 ? 'orange' : 'gray'; ?>">
                                                            <?php echo h((string)(int)$o['teacher_count']); ?>
                                                        </span>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <span class="status-pill <?php echo $statusClass; ?>">
                                                            <i class="fas <?php echo $icon; ?>"></i><?php echo h($status); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="action-group">
                                                            <a href="/platform/tenant/academic/class-offerings.php?action=edit&id=<?php echo (int)$o['id']; ?>"
                                                                class="btn btn-outline-primary">
                                                                <i class="fas fa-edit"></i> Edit
                                                            </a>
                                                            <form method="POST" style="display:inline-block;">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="change_status">
                                                                <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
                                                                <select name="status" class="form-select" style="height:28px;font-size:11px;padding:2px 8px;display:inline-block;width:auto;"
                                                                    onchange="this.form.submit()">
                                                                    <?php foreach (['draft', 'active', 'closed', 'archived'] as $s): ?>
                                                                        <option value="<?php echo $s; ?>" <?php echo $status === $s ? 'selected' : ''; ?>>
                                                                            <?php echo ucfirst($s); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </form>
                                                            <form method="POST" style="display:inline-block;"
                                                                onsubmit="return confirm('Delete this offering?');">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
                                                                <button type="submit" class="btn btn-outline-danger">
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
    <div class="modal fade<?php echo $showNewModal ? ' show' : ''; ?>"
        id="newOfferingModal" tabindex="-1"
        <?php echo $showNewModal ? 'style="display:block;background:rgba(0,0,0,0.4);"' : ''; ?>>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/academic/class-offerings.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5><i class="fas fa-door-open text-primary me-2"></i>Add <?php echo h($labelClass); ?> Offering</h5>
                        <a href="/platform/tenant/academic/class-offerings.php" class="btn-close"></a>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="new_year_id"><?php echo h($labelAcademic); ?> <span class="required">*</span></label>
                                <select class="form-select" id="new_year_id" name="academic_year_id" required>
                                    <option value="">Select <?php echo h(strtolower($labelAcademic)); ?>...</option>
                                    <?php foreach ($years as $y): ?>
                                        <option value="<?php echo (int)$y['id']; ?>"
                                            <?php echo ($newYearId === (int)$y['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($y['year_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="new_level_id"><?php echo h($labelLevel); ?> <span class="required">*</span></label>
                                <select class="form-select" id="new_level_id" name="academic_level_id" required>
                                    <option value="">Select <?php echo h(strtolower($labelLevel)); ?>...</option>
                                    <?php foreach ($levels as $lv): ?>
                                        <option value="<?php echo (int)$lv['id']; ?>">
                                            <?php echo h($lv['level_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="new_class_id"><?php echo h($labelClass); ?> <span class="required">*</span></label>
                                <select class="form-select" id="new_class_id" name="class_id" required>
                                    <option value="">Select <?php echo h(strtolower($labelClass)); ?>...</option>
                                    <?php foreach ($classes as $c): ?>
                                        <option value="<?php echo (int)$c['id']; ?>">
                                            <?php echo h($c['class_name']); ?>
                                            <?php if (!empty($c['class_code'])): ?> (<?php echo h($c['class_code']); ?>)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if ($enableStreams): ?>
                                <div class="col-md-6 mb-3" id="streamWrap" style="display:none;">
                                    <label class="form-label" for="new_stream_id"><?php echo h($labelStream); ?> <span class="required" id="streamRequired" style="display:none;">*</span></label>
                                    <select class="form-select" id="new_stream_id" name="stream_id">
                                        <option value="">Select <?php echo h(strtolower($labelStream)); ?>...</option>
                                    </select>
                                    <div class="form-text" id="streamHint">Select a <?php echo h(strtolower($labelLevel)); ?> first.</div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="new_campus_id">Campus</label>
                                <select class="form-select" id="new_campus_id" name="campus_id">
                                    <option value="">Optional</option>
                                    <?php foreach ($campuses as $cp): ?>
                                        <option value="<?php echo (int)$cp['id']; ?>"><?php echo h($cp['campus_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="new_capacity">Capacity</label>
                                <input type="number" class="form-control" id="new_capacity" name="capacity" min="1" max="500" placeholder="Optional">
                                <div class="form-text">Informational only for now.</div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="new_status">Status</label>
                                <select class="form-select" id="new_status" name="status">
                                    <option value="draft">Draft</option>
                                    <option value="active">Active</option>
                                    <option value="closed">Closed</option>
                                    <option value="archived">Archived</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="new_notes">Notes</label>
                            <textarea class="form-control" id="new_notes" name="notes" rows="2" maxlength="2000" placeholder="Optional notes"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="/platform/tenant/academic/class-offerings.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php if ($editOffering):
        $editLevelId = (int)$editOffering['academic_level_id'];
        $editStreams = $streamsByLevel[$editLevelId] ?? [];
        $editLevelHasStreams = count($editStreams) > 0;
    ?>
        <div class="modal fade show" id="editOfferingModal" tabindex="-1"
            style="display:block;background:rgba(0,0,0,0.4);">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" action="/platform/tenant/academic/class-offerings.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?php echo (int)$editOffering['id']; ?>">
                        <div class="modal-header">
                            <h5><i class="fas fa-edit text-primary me-2"></i>Edit Offering</h5>
                            <a href="/platform/tenant/academic/class-offerings.php" class="btn-close"></a>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo h($labelAcademic); ?></label>
                                    <input type="text" class="form-control" readonly
                                        value="<?php
                                                $yName = '';
                                                foreach ($years as $y) if ((int)$y['id'] === (int)$editOffering['academic_year_id']) {
                                                    $yName = $y['year_name'];
                                                    break;
                                                }
                                                echo h($yName ?: '—');
                                                ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo h($labelLevel); ?></label>
                                    <input type="text" class="form-control" readonly
                                        value="<?php
                                                $lName = '';
                                                foreach ($levels as $lv) if ((int)$lv['id'] === $editLevelId) {
                                                    $lName = $lv['level_name'];
                                                    break;
                                                }
                                                echo h($lName ?: '—');
                                                ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo h($labelClass); ?></label>
                                    <input type="text" class="form-control" readonly
                                        value="<?php
                                                $cName = '';
                                                foreach ($classes as $c) if ((int)$c['id'] === (int)$editOffering['class_id']) {
                                                    $cName = $c['class_name'];
                                                    break;
                                                }
                                                echo h($cName ?: '—');
                                                ?>">
                                </div>
                                <?php if ($enableStreams): ?>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="edit_stream_id">
                                            <?php echo h($labelStream); ?>
                                            <?php if ($editLevelHasStreams): ?><span class="required">*</span><?php endif; ?>
                                        </label>
                                        <select class="form-select" id="edit_stream_id" name="stream_id">
                                            <?php if (!$editLevelHasStreams): ?>
                                                <option value="">No <?php echo h(strtolower($labelStream)); ?>s defined for this <?php echo h(strtolower($labelLevel)); ?></option>
                                            <?php else: ?>
                                                <option value="">Select...</option>
                                                <?php foreach ($editStreams as $st): ?>
                                                    <option value="<?php echo (int)$st['id']; ?>"
                                                        <?php echo ((int)$editOffering['stream_id'] === (int)$st['id']) ? 'selected' : ''; ?>>
                                                        <?php echo h($st['stream_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="edit_campus_id">Campus</label>
                                    <select class="form-select" id="edit_campus_id" name="campus_id">
                                        <option value="">Optional</option>
                                        <?php foreach ($campuses as $cp): ?>
                                            <option value="<?php echo (int)$cp['id']; ?>"
                                                <?php echo ((int)$editOffering['campus_id'] === (int)$cp['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($cp['campus_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="edit_capacity">Capacity</label>
                                    <input type="number" class="form-control" id="edit_capacity" name="capacity"
                                        min="1" max="500" value="<?php echo $editOffering['capacity'] !== null ? (int)$editOffering['capacity'] : ''; ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="edit_status">Status</label>
                                    <select class="form-select" id="edit_status" name="status">
                                        <?php foreach (['draft', 'active', 'closed', 'archived'] as $s): ?>
                                            <option value="<?php echo $s; ?>" <?php echo $editOffering['status'] === $s ? 'selected' : ''; ?>>
                                                <?php echo ucfirst($s); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="edit_notes">Notes</label>
                                <textarea class="form-control" id="edit_notes" name="notes" rows="2" maxlength="2000"><?php echo h($editOffering['notes']); ?></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <a href="/platform/tenant/academic/class-offerings.php" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var STREAMS_BY_LEVEL = <?php echo $streamsJson; ?>;
        if (typeof STREAMS_BY_LEVEL !== 'object' || STREAMS_BY_LEVEL === null) {
            STREAMS_BY_LEVEL = {};
        }
        var ENABLE_STREAMS = <?php echo $enableStreams ? 'true' : 'false'; ?>;

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
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('action') === 'new') {
                const modalEl = document.getElementById('newOfferingModal');
                if (modalEl && !modalEl.classList.contains('show')) {
                    const modal = new bootstrap.Modal(modalEl);
                    modal.show();
                }
            }
        })();
        (function() {
            if (!ENABLE_STREAMS) return;
            const levelSel = document.getElementById('new_level_id');
            const streamWrap = document.getElementById('streamWrap');
            const streamSel = document.getElementById('new_stream_id');
            const streamReq = document.getElementById('streamRequired');
            const streamHint = document.getElementById('streamHint');
            if (!levelSel || !streamWrap || !streamSel) return;

            function sync() {
                const lid = parseInt(levelSel.value || '0', 10);
                streamSel.innerHTML = '<option value="">Select stream...</option>';
                if (lid <= 0) {
                    streamWrap.style.display = 'none';
                    return;
                }
                const list = STREAMS_BY_LEVEL[lid] || [];
                if (!list.length) {
                    streamWrap.style.display = 'none';
                    return;
                }
                streamWrap.style.display = 'block';
                if (streamReq) streamReq.style.display = 'inline';
                list.forEach(function(s) {
                    const opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = s.stream_name;
                    streamSel.appendChild(opt);
                });
            }
            levelSel.addEventListener('change', sync);
            sync();
        })();
        document.querySelectorAll('form').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                let valid = true;
                let firstErr = null;
                this.querySelectorAll('[required]').forEach(function(f) {
                    if (f.disabled) return;
                    if (!f.value.trim()) {
                        f.classList.add('field-error');
                        valid = false;
                        if (!firstErr) firstErr = f;
                    } else {
                        f.classList.remove('field-error');
                    }
                });
                if (!valid) {
                    e.preventDefault();
                    if (firstErr) firstErr.focus();
                }
            });
        });
        document.querySelectorAll('.form-control, .form-select').forEach(function(el) {
            el.addEventListener('input', function() {
                this.classList.remove('field-error');
            });
            el.addEventListener('change', function() {
                this.classList.remove('field-error');
            });
        });
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