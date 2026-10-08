<?php

/**
 * Academic Subjects — Catalog CRUD (universal + per-level)
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Academic
 * @version 1.0
 * @filepath public/platform/tenant/academic/subjects.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Academic subjects file of the academic-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'academic_subjects'
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
 * Decisions locked in (Session S8):
 *   1C  Universal subjects (level NULL) and per-level subjects both allowed
 *   2A  Subject types: Core, Elective, Co-curricular, Optional (fixed list)
 *   3C  Unique per (tenant_id, subject_code); name uniqueness in PHP
 *   4   subject_code is required
 *   5A  Info banner for orphan (universal) subjects, no forced binding
 *   6A  Refuse delete, list dependents
 *   7A  Keep current field set
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
$userId       = current_user_id();
$currentUser  = $_SESSION['user_name'] ?? 'Admin';
$userAvatar   = substr($currentUser, 0, 1);
$isSuperAdmin = is_super_admin();
$pageTitle    = 'Academic Subjects - Student 360 Platform';
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
/**
 * Count dependencies blocking a subject deletion.
 */
function subjectDependencies($db, int $tenantId, int $subjectId): array
{
    $total = 0;
    $labels = [];
    $checks = [
        'curriculum'          => "SELECT COUNT(*) AS c FROM curriculum              WHERE tenant_id = ? AND subject_id = ? AND deleted_at IS NULL",
        'class subjects'      => "SELECT COUNT(*) AS c FROM class_subjects          WHERE tenant_id = ? AND subject_id = ? AND deleted_at IS NULL",
        'results'             => "SELECT COUNT(*) AS c FROM results                 WHERE tenant_id = ? AND subject_id = ? AND deleted_at IS NULL",
        'teacher assignments' => "SELECT COUNT(*) AS c FROM teacher_class_assignments WHERE tenant_id = ? AND subject_id = ? AND deleted_at IS NULL",
    ];
    foreach ($checks as $label => $sql) {
        try {
            $row = $db->fetchOne($sql, [$tenantId, $subjectId]);
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
// Subject types — fixed list (Decision 2A)
$SUBJECT_TYPES = ['Core', 'Elective', 'Co-curricular', 'Optional'];
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
            $name   = trim((string)($_POST['subject_name'] ?? ''));
            $code   = trim((string)($_POST['subject_code'] ?? ''));
            $type   = trim((string)($_POST['subject_type'] ?? ''));
            $levelId = (int)($_POST['academic_level_id'] ?? 0);
            $desc   = trim((string)($_POST['description'] ?? ''));
            $active = !empty($_POST['is_active']) ? 1 : 0;
            if ($name === '') $errors[] = 'Subject name is required.';
            if (mb_strlen($name) > 100) $errors[] = 'Subject name must be 100 characters or less.';
            if ($code === '') $errors[] = 'Subject code is required.';
            if (mb_strlen($code) > 20) $errors[] = 'Subject code must be 20 characters or less.';
            if ($type !== '' && !in_array($type, $SUBJECT_TYPES, true)) {
                $errors[] = 'Invalid subject type.';
            }
            if ($desc !== '' && mb_strlen($desc) > 2000) $errors[] = 'Description must be 2000 characters or less.';
            // Level optional — if provided, must belong to tenant
            if ($levelId > 0) {
                $lvl = $db->fetchOne(
                    "SELECT id FROM academic_levels WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$levelId, $tenantId]
                );
                if (!$lvl) $errors[] = 'Selected academic level not found.';
            } else {
                $levelId = 0;
            }
            // Unique code within tenant
            if (empty($errors)) {
                $dupe = $db->fetchOne(
                    "SELECT id FROM subjects
                     WHERE tenant_id = ? AND subject_code = ? AND deleted_at IS NULL",
                    [$tenantId, $code]
                );
                if ($dupe) $errors[] = 'A subject with code "' . $code . '" already exists.';
            }
            // Unique name within scope (universal OR same level)
            if (empty($errors)) {
                if ($levelId > 0) {
                    $dupe = $db->fetchOne(
                        "SELECT id FROM subjects
                         WHERE tenant_id = ? AND subject_name = ? AND academic_level_id = ? AND deleted_at IS NULL",
                        [$tenantId, $name, $levelId]
                    );
                } else {
                    $dupe = $db->fetchOne(
                        "SELECT id FROM subjects
                         WHERE tenant_id = ? AND subject_name = ? AND academic_level_id IS NULL AND deleted_at IS NULL",
                        [$tenantId, $name]
                    );
                }
                if ($dupe) {
                    $scope = $levelId > 0 ? 'this level' : 'the universal catalog';
                    $errors[] = 'A subject named "' . $name . '" already exists in ' . $scope . '.';
                }
            }
            if (empty($errors)) {
                $db->beginTransaction();
                $newId = $db->insert(
                    "INSERT INTO subjects
                        (tenant_id, school_id, academic_level_id, subject_name, subject_code,
                         subject_type, description, is_active, created_at, updated_at)
                     VALUES (?, NULL, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        $tenantId,
                        $levelId > 0 ? $levelId : null,
                        $name,
                        $code,
                        $type !== '' ? $type : null,
                        $desc !== '' ? $desc : null,
                        $active,
                    ]
                );
                writeAudit(
                    $db,
                    $tenantId,
                    $userId,
                    'academic.subject.created',
                    'subjects',
                    (int)$newId,
                    [
                        'subject_name' => $name,
                        'subject_code' => $code,
                        'subject_type' => $type,
                        'level_id' => $levelId,
                        'is_active' => $active
                    ]
                );
                $db->commit();
                $_SESSION['success'] = 'Subject "' . $name . '" created.';
                header('Location: /platform/tenant/academic/subjects.php');
                exit;
            }
            $_SESSION['errors']    = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: /platform/tenant/academic/subjects.php?action=new');
            exit;
        }
        // ---------- UPDATE ----------
        if ($action === 'update') {
            $id     = (int)($_POST['id'] ?? 0);
            $name   = trim((string)($_POST['subject_name'] ?? ''));
            $code   = trim((string)($_POST['subject_code'] ?? ''));
            $type   = trim((string)($_POST['subject_type'] ?? ''));
            $levelId = (int)($_POST['academic_level_id'] ?? 0);
            $desc   = trim((string)($_POST['description'] ?? ''));
            $active = !empty($_POST['is_active']) ? 1 : 0;
            $subject = $db->fetchOne(
                "SELECT * FROM subjects WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$subject) $errors[] = 'Subject not found.';
            if ($name === '') $errors[] = 'Subject name is required.';
            if (mb_strlen($name) > 100) $errors[] = 'Subject name must be 100 characters or less.';
            if ($code === '') $errors[] = 'Subject code is required.';
            if (mb_strlen($code) > 20) $errors[] = 'Subject code must be 20 characters or less.';
            if ($type !== '' && !in_array($type, $SUBJECT_TYPES, true)) {
                $errors[] = 'Invalid subject type.';
            }
            if ($levelId > 0) {
                $lvl = $db->fetchOne(
                    "SELECT id FROM academic_levels WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$levelId, $tenantId]
                );
                if (!$lvl) $errors[] = 'Selected academic level not found.';
            } else {
                $levelId = 0;
            }
            // Unique code, excluding self
            if (empty($errors)) {
                $dupe = $db->fetchOne(
                    "SELECT id FROM subjects
                     WHERE tenant_id = ? AND subject_code = ? AND deleted_at IS NULL AND id != ?",
                    [$tenantId, $code, $id]
                );
                if ($dupe) $errors[] = 'Another subject with code "' . $code . '" already exists.';
            }
            // Unique name within scope, excluding self
            if (empty($errors)) {
                if ($levelId > 0) {
                    $dupe = $db->fetchOne(
                        "SELECT id FROM subjects
                         WHERE tenant_id = ? AND subject_name = ? AND academic_level_id = ? AND deleted_at IS NULL AND id != ?",
                        [$tenantId, $name, $levelId, $id]
                    );
                } else {
                    $dupe = $db->fetchOne(
                        "SELECT id FROM subjects
                         WHERE tenant_id = ? AND subject_name = ? AND academic_level_id IS NULL AND deleted_at IS NULL AND id != ?",
                        [$tenantId, $name, $id]
                    );
                }
                if ($dupe) {
                    $scope = $levelId > 0 ? 'this level' : 'the universal catalog';
                    $errors[] = 'Another subject named "' . $name . '" already exists in ' . $scope . '.';
                }
            }
            if (empty($errors) && $subject) {
                $db->beginTransaction();
                $db->execute(
                    "UPDATE subjects
                        SET subject_name = ?,
                            subject_code = ?,
                            subject_type = ?,
                            academic_level_id = ?,
                            description = ?,
                            is_active = ?,
                            updated_at = NOW()
                      WHERE id = ? AND tenant_id = ?",
                    [
                        $name,
                        $code,
                        $type !== '' ? $type : null,
                        $levelId > 0 ? $levelId : null,
                        $desc !== '' ? $desc : null,
                        $active,
                        $id,
                        $tenantId,
                    ]
                );
                writeAudit(
                    $db,
                    $tenantId,
                    $userId,
                    'academic.subject.updated',
                    'subjects',
                    $id,
                    [
                        'subject_name' => $name,
                        'subject_code' => $code,
                        'subject_type' => $type,
                        'level_id' => $levelId,
                        'is_active' => $active
                    ]
                );
                $db->commit();
                $_SESSION['success'] = 'Subject updated.';
                header('Location: /platform/tenant/academic/subjects.php');
                exit;
            }
            $_SESSION['errors']    = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: /platform/tenant/academic/subjects.php?action=edit&id=' . $id);
            exit;
        }
        // ---------- TOGGLE ACTIVE ----------
        if ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            $subject = $db->fetchOne(
                "SELECT * FROM subjects WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$subject) throw new Exception('Subject not found.');
            $newActive = ((int)$subject['is_active']) === 1 ? 0 : 1;
            $db->beginTransaction();
            $db->execute(
                "UPDATE subjects SET is_active = ?, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$newActive, $id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.subject.toggled_active',
                'subjects',
                $id,
                ['is_active' => $newActive]
            );
            $db->commit();
            $_SESSION['success'] = '"' . $subject['subject_name'] . '" is now ' .
                ($newActive ? 'active' : 'inactive') . '.';
            header('Location: /platform/tenant/academic/subjects.php');
            exit;
        }
        // ---------- DELETE ----------
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $subject = $db->fetchOne(
                "SELECT * FROM subjects WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$subject) throw new Exception('Subject not found.');
            $deps = subjectDependencies($db, $tenantId, $id);
            if ($deps['total'] > 0) {
                throw new Exception(
                    'Cannot delete "' . $subject['subject_name'] . '". It is referenced by: ' .
                        implode(', ', $deps['labels']) .
                        '. Remove those references first, or deactivate the subject instead.'
                );
            }
            $db->beginTransaction();
            $db->execute(
                "UPDATE subjects SET deleted_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.subject.deleted',
                'subjects',
                $id,
                ['subject_name' => $subject['subject_name'], 'subject_code' => $subject['subject_code']]
            );
            $db->commit();
            $_SESSION['success'] = 'Subject "' . $subject['subject_name'] . '" deleted.';
            header('Location: /platform/tenant/academic/subjects.php');
            exit;
        }
        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Academic subjects action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/academic/subjects.php');
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
// All levels
$levels = $db->fetchAll(
    "SELECT id, level_name, level_code FROM academic_levels
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY sort_order ASC, level_name ASC",
    [$tenantId]
);
// Universal subjects (no level)
$universalSubjects = $db->fetchAll(
    "SELECT * FROM subjects
     WHERE tenant_id = ? AND academic_level_id IS NULL AND deleted_at IS NULL
     ORDER BY subject_name ASC",
    [$tenantId]
);
// Per-level subjects grouped by level
$subjectsByLevel = [];
foreach ($levels as $lv) {
    $subjectsByLevel[(int)$lv['id']] = $db->fetchAll(
        "SELECT * FROM subjects
         WHERE tenant_id = ? AND academic_level_id = ? AND deleted_at IS NULL
         ORDER BY subject_name ASC",
        [$tenantId, (int)$lv['id']]
    );
}
// For edit
$editSubject = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && !empty($_GET['id'])) {
    $editSubject = $db->fetchOne(
        "SELECT * FROM subjects WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [(int)$_GET['id'], $tenantId]
    );
}
$showNewModal = (isset($_GET['action']) && $_GET['action'] === 'new') ||
    ($useForm && (($formData['action'] ?? '') === 'create'));
// New modal context (relocated out of the modal markup for clarity)
$newLevelId = (int)($_GET['level_id'] ?? 0);
// Tenant name
$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}
function v(string $key, $default = ''): string
{
    global $formData, $useForm, $editSubject;
    if ($useForm && array_key_exists($key, $formData)) {
        return h($formData[$key]);
    }
    if ($editSubject && array_key_exists($key, $editSubject)) {
        return h($editSubject[$key]);
    }
    return h($default);
}
$typeBadgeClass = function ($type) {
    switch ($type) {
        case 'Core':
            return 'type-core';
        case 'Elective':
            return 'type-elective';
        case 'Co-curricular':
            return 'type-cocurricular';
        case 'Optional':
            return 'type-optional';
        default:
            return 'type-none';
    }
};
// Catalog total, computed once
$catalogTotal = count($universalSubjects);
foreach ($subjectsByLevel as $arr) $catalogTotal += count($arr);
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

        .subject-section {
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

        .subject-section .section-header {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            background: linear-gradient(135deg, #f8fafc 0%, #eef6ff 100%);
        }

        .subject-section .section-header .section-title {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .subject-section .section-header .section-title h6 {
            font-weight: 700;
            margin: 0;
            font-size: 16px;
            color: #1a1a2e;
        }

        .subject-section .section-body {
            padding: 0;
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

        .subject-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .subject-table thead th {
            background: #f8f9fa;
            padding: 12px 20px;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            border-bottom: 1px solid #e9ecef;
            text-align: left;
            white-space: nowrap;
        }

        .subject-table tbody td {
            padding: 12px 20px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .subject-table tbody tr:last-child td {
            border-bottom: none;
        }

        .subject-table tbody tr:hover {
            background: #fafbfc;
        }

        .subject-name {
            font-weight: 600;
            color: #1a1a2e;
            font-size: 14px;
        }

        .subject-desc {
            font-size: 12px;
            color: #6c757d;
            margin-top: 2px;
        }

        .subject-code {
            display: inline-block;
            background: #e3f0ff;
            color: #0d6efd;
            padding: 2px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            font-family: 'Courier New', monospace;
        }

        .type-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        .type-core {
            background: #d4edda;
            color: #155724;
        }

        .type-elective {
            background: #cce5ff;
            color: #004085;
        }

        .type-cocurricular {
            background: #fff3cd;
            color: #856404;
        }

        .type-optional {
            background: #e9ecef;
            color: #495057;
        }

        .type-none {
            background: #f1f3f5;
            color: #adb5bd;
            font-style: italic;
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

        .empty-subjects {
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

            .subject-section .section-header {
                flex-direction: column;
                align-items: stretch;
            }

            .subject-table {
                font-size: 12px;
            }

            .subject-table thead th {
                padding: 8px 10px;
                font-size: 10px;
            }

            .subject-table tbody td {
                padding: 8px 10px;
            }

            .action-group {
                justify-content: flex-start;
            }

            .info-banner {
                flex-direction: column;
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
                        <h1><i class="fas fa-book me-2"></i>Subjects</h1>
                        <p>Manage the subject catalog for your institution</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/academic/subjects.php?action=new" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i> Add Subject
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
                                <?php echo h((string)$catalogTotal); ?> subject(s) in catalog
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>Universal + per-<?php echo h(strtolower($labelLevel)); ?>
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
                <?php if (!empty($universalSubjects)): ?>
                    <div class="info-banner">
                        <div class="ib-icon"><i class="fas fa-info-circle"></i></div>
                        <div class="ib-body">
                            <div class="ib-title">Universal Subjects</div>
                            <div class="ib-text">
                                The subjects listed under <strong>Universal Subjects</strong> are not scoped to a
                                specific <?php echo h(strtolower($labelLevel)); ?>. They are usable in any
                                <?php echo h(strtolower($labelClass)); ?> across all <?php echo h(strtolower($labelLevel)); ?>s.
                                If you want a subject to be specific to one <?php echo h(strtolower($labelLevel)); ?>,
                                edit it and choose a <?php echo h(strtolower($labelLevel)); ?>, or create a new
                                level-specific subject.
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="subject-section">
                    <div class="section-header">
                        <div class="section-title">
                            <i class="fas fa-globe" style="font-size:18px;color:#4facfe;"></i>
                            <h6>Universal Subjects</h6>
                            <span class="pill purple">
                                <i class="fas fa-infinity"></i>usable at any <?php echo h(strtolower($labelLevel)); ?>
                            </span>
                            <span class="pill <?php echo count($universalSubjects) > 0 ? 'blue' : 'gray'; ?>">
                                <i class="fas fa-book"></i><?php echo h((string)count($universalSubjects)); ?> subject(s)
                            </span>
                        </div>
                        <a href="/platform/tenant/academic/subjects.php?action=new" class="btn btn-outline-primary">
                            <i class="fas fa-plus"></i> Add Subject
                        </a>
                    </div>
                    <div class="section-body">
                        <?php if (empty($universalSubjects)): ?>
                            <div class="empty-subjects">
                                No universal subjects yet.
                            </div>
                        <?php else: ?>
                            <table class="subject-table">
                                <thead>
                                    <tr>
                                        <th style="min-width:200px;">Subject</th>
                                        <th>Code</th>
                                        <th>Type</th>
                                        <th style="text-align:center;">Status</th>
                                        <th style="text-align:right;min-width:200px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($universalSubjects as $sub):
                                        $isActive = ((int)$sub['is_active']) === 1;
                                        $typeClass = $typeBadgeClass($sub['subject_type'] ?? '');
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="subject-name"><?php echo h($sub['subject_name']); ?></div>
                                                <?php if (!empty($sub['description'])): ?>
                                                    <div class="subject-desc"><?php echo h($sub['description']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="subject-code"><?php echo h($sub['subject_code']); ?></span>
                                            </td>
                                            <td>
                                                <?php if (!empty($sub['subject_type'])): ?>
                                                    <span class="type-badge <?php echo $typeClass; ?>"><?php echo h($sub['subject_type']); ?></span>
                                                <?php else: ?>
                                                    <span class="type-badge type-none">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:center;">
                                                <?php if ($isActive): ?>
                                                    <span class="pill green"><i class="fas fa-check"></i>Active</span>
                                                <?php else: ?>
                                                    <span class="pill gray"><i class="fas fa-times"></i>Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-group">
                                                    <a href="/platform/tenant/academic/subjects.php?action=edit&id=<?php echo (int)$sub['id']; ?>"
                                                        class="btn btn-outline-primary">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </a>
                                                    <form method="POST" style="display:inline-block;"
                                                        onsubmit="return confirm('<?php echo $isActive ? 'Deactivate' : 'Activate'; ?> &quot;<?php echo h(addslashes($sub['subject_name'])); ?>&quot;?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="toggle_active">
                                                        <input type="hidden" name="id" value="<?php echo (int)$sub['id']; ?>">
                                                        <button type="submit" class="btn btn-outline-warning">
                                                            <i class="fas fa-<?php echo $isActive ? 'pause' : 'play'; ?>"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" style="display:inline-block;"
                                                        onsubmit="return confirm('Delete &quot;<?php echo h(addslashes($sub['subject_name'])); ?>&quot;?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int)$sub['id']; ?>">
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
                        <?php endif; ?>
                    </div>
                </div>
                <?php foreach ($levels as $lv):
                    $lvId = (int)$lv['id'];
                    $subs = $subjectsByLevel[$lvId] ?? [];
                ?>
                    <div class="subject-section">
                        <div class="section-header">
                            <div class="section-title">
                                <i class="fas fa-layer-group" style="font-size:18px;color:#4facfe;"></i>
                                <h6><?php echo h($lv['level_name']); ?></h6>
                                <?php if (!empty($lv['level_code'])): ?>
                                    <span class="pill blue"><?php echo h($lv['level_code']); ?></span>
                                <?php endif; ?>
                                <span class="pill <?php echo count($subs) > 0 ? 'blue' : 'gray'; ?>">
                                    <i class="fas fa-book"></i><?php echo h((string)count($subs)); ?> subject(s)
                                </span>
                            </div>
                            <a href="/platform/tenant/academic/subjects.php?action=new&level_id=<?php echo $lvId; ?>"
                                class="btn btn-outline-primary">
                                <i class="fas fa-plus"></i> Add Level-Specific Subject
                            </a>
                        </div>
                        <div class="section-body">
                            <?php if (empty($subs)): ?>
                                <div class="empty-subjects">
                                    No subjects scoped to this <?php echo h(strtolower($labelLevel)); ?> yet.
                                </div>
                            <?php else: ?>
                                <table class="subject-table">
                                    <thead>
                                        <tr>
                                            <th style="min-width:200px;">Subject</th>
                                            <th>Code</th>
                                            <th>Type</th>
                                            <th style="text-align:center;">Status</th>
                                            <th style="text-align:right;min-width:200px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($subs as $sub):
                                            $isActive = ((int)$sub['is_active']) === 1;
                                            $typeClass = $typeBadgeClass($sub['subject_type'] ?? '');
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="subject-name"><?php echo h($sub['subject_name']); ?></div>
                                                    <?php if (!empty($sub['description'])): ?>
                                                        <div class="subject-desc"><?php echo h($sub['description']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="subject-code"><?php echo h($sub['subject_code']); ?></span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($sub['subject_type'])): ?>
                                                        <span class="type-badge <?php echo $typeClass; ?>"><?php echo h($sub['subject_type']); ?></span>
                                                    <?php else: ?>
                                                        <span class="type-badge type-none">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="text-align:center;">
                                                    <?php if ($isActive): ?>
                                                        <span class="pill green"><i class="fas fa-check"></i>Active</span>
                                                    <?php else: ?>
                                                        <span class="pill gray"><i class="fas fa-times"></i>Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="action-group">
                                                        <a href="/platform/tenant/academic/subjects.php?action=edit&id=<?php echo (int)$sub['id']; ?>"
                                                            class="btn btn-outline-primary">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </a>
                                                        <form method="POST" style="display:inline-block;"
                                                            onsubmit="return confirm('<?php echo $isActive ? 'Deactivate' : 'Activate'; ?> &quot;<?php echo h(addslashes($sub['subject_name'])); ?>&quot;?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="toggle_active">
                                                            <input type="hidden" name="id" value="<?php echo (int)$sub['id']; ?>">
                                                            <button type="submit" class="btn btn-outline-warning">
                                                                <i class="fas fa-<?php echo $isActive ? 'pause' : 'play'; ?>"></i>
                                                            </button>
                                                        </form>
                                                        <form method="POST" style="display:inline-block;"
                                                            onsubmit="return confirm('Delete &quot;<?php echo h(addslashes($sub['subject_name'])); ?>&quot;?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="id" value="<?php echo (int)$sub['id']; ?>">
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
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </main>
        </div>
    </div>
    <div class="modal fade<?php echo $showNewModal ? ' show' : ''; ?>"
        id="newSubjectModal" tabindex="-1"
        <?php echo $showNewModal ? 'style="display:block;background:rgba(0,0,0,0.4);"' : ''; ?>>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/academic/subjects.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5><i class="fas fa-book text-primary me-2"></i>Add Subject</h5>
                        <a href="/platform/tenant/academic/subjects.php" class="btn-close"></a>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label" for="new_subject_name">Subject Name <span class="required">*</span></label>
                                <input type="text" class="form-control" id="new_subject_name" name="subject_name"
                                    maxlength="100" required
                                    placeholder="e.g. Mathematics"
                                    value="<?php echo ($useForm && ($formData['action'] ?? '') === 'create') ? v('subject_name') : ''; ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="new_subject_code">Code <span class="required">*</span></label>
                                <input type="text" class="form-control" id="new_subject_code" name="subject_code"
                                    maxlength="20" required
                                    placeholder="e.g. MATH"
                                    style="font-family:'Courier New', monospace; text-transform:uppercase;"
                                    value="<?php echo ($useForm && ($formData['action'] ?? '') === 'create') ? v('subject_code') : ''; ?>">
                                <div class="form-text">Unique across your institution.</div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="new_subject_type">Type</label>
                                <select class="form-select" id="new_subject_type" name="subject_type">
                                    <option value="">Select type...</option>
                                    <?php foreach ($SUBJECT_TYPES as $st): ?>
                                        <option value="<?php echo h($st); ?>"
                                            <?php echo (($useForm && ($formData['action'] ?? '') === 'create') && ($formData['subject_type'] ?? '') === $st) ? 'selected' : ''; ?>>
                                            <?php echo h($st); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="new_subject_level"><?php echo h($labelLevel); ?> Scope</label>
                                <select class="form-select" id="new_subject_level" name="academic_level_id">
                                    <option value="">Universal (usable at any <?php echo h(strtolower($labelLevel)); ?>)</option>
                                    <?php foreach ($levels as $lv): ?>
                                        <option value="<?php echo (int)$lv['id']; ?>"
                                            <?php echo ($newLevelId === (int)$lv['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($lv['level_name']); ?>
                                            <?php if (!empty($lv['level_code'])): ?> (<?php echo h($lv['level_code']); ?>)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Leave blank to make it universal.</div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="new_subject_description">Description</label>
                            <textarea class="form-control" id="new_subject_description" name="description"
                                maxlength="2000" rows="3"
                                placeholder="Optional notes"><?php echo ($useForm && ($formData['action'] ?? '') === 'create') ? v('description') : ''; ?></textarea>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="new_subject_active" name="is_active" value="1" checked>
                            <label class="form-check-label" for="new_subject_active">Active</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="/platform/tenant/academic/subjects.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php if ($editSubject): ?>
        <div class="modal fade show" id="editSubjectModal" tabindex="-1"
            style="display:block;background:rgba(0,0,0,0.4);">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" action="/platform/tenant/academic/subjects.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?php echo (int)$editSubject['id']; ?>">
                        <div class="modal-header">
                            <h5><i class="fas fa-edit text-primary me-2"></i>Edit Subject</h5>
                            <a href="/platform/tenant/academic/subjects.php" class="btn-close"></a>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label" for="edit_subject_name">Subject Name <span class="required">*</span></label>
                                    <input type="text" class="form-control" id="edit_subject_name" name="subject_name"
                                        maxlength="100" required
                                        value="<?php echo h($editSubject['subject_name']); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="edit_subject_code">Code <span class="required">*</span></label>
                                    <input type="text" class="form-control" id="edit_subject_code" name="subject_code"
                                        maxlength="20" required
                                        style="font-family:'Courier New', monospace; text-transform:uppercase;"
                                        value="<?php echo h($editSubject['subject_code']); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="edit_subject_type">Type</label>
                                    <select class="form-select" id="edit_subject_type" name="subject_type">
                                        <option value="">Select type...</option>
                                        <?php foreach ($SUBJECT_TYPES as $st): ?>
                                            <option value="<?php echo h($st); ?>"
                                                <?php echo ($editSubject['subject_type'] === $st) ? 'selected' : ''; ?>>
                                                <?php echo h($st); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="edit_subject_level"><?php echo h($labelLevel); ?> Scope</label>
                                    <select class="form-select" id="edit_subject_level" name="academic_level_id">
                                        <option value=""
                                            <?php echo (empty($editSubject['academic_level_id'])) ? 'selected' : ''; ?>>
                                            Universal (usable at any <?php echo h(strtolower($labelLevel)); ?>)
                                        </option>
                                        <?php foreach ($levels as $lv): ?>
                                            <option value="<?php echo (int)$lv['id']; ?>"
                                                <?php echo ((int)$editSubject['academic_level_id'] === (int)$lv['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($lv['level_name']); ?>
                                                <?php if (!empty($lv['level_code'])): ?> (<?php echo h($lv['level_code']); ?>)<?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">
                                        Change this to move the subject between universal and a specific
                                        <?php echo h(strtolower($labelLevel)); ?>.
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="edit_subject_description">Description</label>
                                <textarea class="form-control" id="edit_subject_description" name="description"
                                    maxlength="2000" rows="3"><?php echo h($editSubject['description']); ?></textarea>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="edit_subject_active" name="is_active" value="1"
                                    <?php echo ((int)$editSubject['is_active'] === 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="edit_subject_active">Active</label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <a href="/platform/tenant/academic/subjects.php" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
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
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('action') === 'new') {
                const modalEl = document.getElementById('newSubjectModal');
                if (modalEl && !modalEl.classList.contains('show')) {
                    const modal = new bootstrap.Modal(modalEl);
                    modal.show();
                }
            }
        })();
        // Auto-uppercase subject code inputs
        document.querySelectorAll('input[name="subject_code"]').forEach(el => {
            el.addEventListener('input', function() {
                const pos = this.selectionStart;
                this.value = this.value.toUpperCase();
                this.setSelectionRange(pos, pos);
            });
        });
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                let valid = true;
                let firstErr = null;
                this.querySelectorAll('[required]').forEach(f => {
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
        document.querySelectorAll('.form-control, .form-select').forEach(el => {
            el.addEventListener('input', function() {
                this.classList.remove('field-error');
            });
            el.addEventListener('change', function() {
                this.classList.remove('field-error');
            });
        });
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