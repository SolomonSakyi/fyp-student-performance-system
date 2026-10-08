<?php

/**
 * Academic Levels — CRUD for the middle layer of the academic hierarchy
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Academic
 * @version 1.0
 * @filepath public/platform/tenant/academic/levels.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Academic levels file of the academic-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'academic_levels'
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
 * v1.0 change (2026-10-07) [CLASSES MODULE]:
 *   Each level row in the levels table gains a "Classes" button in
 *   its action-group. The button links to
 *   /platform/tenant/academic/classes.php?level_id=<id>, which
 *   opens the classes page pre-filtered to this level. One anchor
 *   is added per row; every other line of the file is unchanged.
 *
 * Decisions locked in (Session S6):
 *   1A unique per (tenant_id, level_name)
 *   2A optional unique code per (tenant_id, level_code)
 *   3C auto-suggest sort order, allow override
 *   4A refuse delete, list dependents
 *   5 school_id NULL is fine, levels are tenant-wide
 *   6A no action on existing rows
 *
 * Session S20 decisions applied:
 *   SH2A per-session CSRF token; all POSTs carry it
 *   SH3A central Security.php helper, loaded via app/bootstrap.php
 *   SH4A hardened session started inside bootstrap
 *   SH5C h() on every echoed value
 *   SH6B tenant_id scoping and deleted_at IS NULL on every query
 *
 * Note on error messages: as with terms.php, this page intentionally
 * surfaces the decision messages (dependency refusal) to the user.
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
$pageTitle    = 'Academic Levels - Student 360 Platform';
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
 * Count dependents on a level. Returns ['total' => N, 'labels' => [...]].
 * Tables are checked defensively so this works even before their modules
 * are built out.
 */
function levelDependencies($db, int $tenantId, int $levelId): array
{
    $total = 0;
    $labels = [];
    $checks = [
        'streams' => "SELECT COUNT(*) AS c FROM streams WHERE tenant_id = ? AND academic_level_id = ? AND deleted_at IS NULL",
        'subjects' => "SELECT COUNT(*) AS c FROM subjects WHERE tenant_id = ? AND academic_level_id = ? AND deleted_at IS NULL",
        'class offerings' => "SELECT COUNT(*) AS c FROM class_offerings WHERE tenant_id = ? AND academic_level_id = ? AND deleted_at IS NULL",
        'curricula' => "SELECT COUNT(*) AS c FROM curriculum WHERE tenant_id = ? AND academic_level_id = ? AND deleted_at IS NULL",
        'assessment schemes' => "SELECT COUNT(*) AS c FROM assessment_schemes WHERE tenant_id = ? AND academic_level_id = ? AND deleted_at IS NULL",
        'grading scales' => "SELECT COUNT(*) AS c FROM grading_scales WHERE tenant_id = ? AND academic_level_id = ? AND deleted_at IS NULL",
        'promotion rules' => "SELECT COUNT(*) AS c FROM promotion_rules WHERE tenant_id = ? AND academic_level_id = ? AND deleted_at IS NULL",
    ];
    foreach ($checks as $label => $sql) {
        try {
            $row = $db->fetchOne($sql, [$tenantId, $levelId]);
            $c = (int)($row['c'] ?? 0);
            if ($c > 0) {
                $total += $c;
                $labels[] = $c . ' ' . $label;
            }
        } catch (Exception $e) {
            // table not yet present — skip silently
        }
    }
    return ['total' => $total, 'labels' => $labels];
}
/**
 * Next available sort_order for this tenant (max + 10, or 10 if none).
 */
function nextSortOrder($db, int $tenantId): int
{
    $row = $db->fetchOne(
        "SELECT COALESCE(MAX(sort_order), 0) AS m
         FROM academic_levels
         WHERE tenant_id = ? AND deleted_at IS NULL",
        [$tenantId]
    );
    $m = (int)($row['m'] ?? 0);
    return $m > 0 ? $m + 10 : 10;
}
// ============================================
// ACTIONS (POST)
// ============================================
$errors = [];
$formData = [];
$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf(); // S20 SH2A — all POSTs must carry the session CSRF token
    $formData = $_POST;
    try {
        // ---------- CREATE ----------
        if ($action === 'create') {
            $name = trim((string)($_POST['level_name'] ?? ''));
            $code = trim((string)($_POST['level_code'] ?? ''));
            $sort = (int)($_POST['sort_order'] ?? 0);
            $desc = trim((string)($_POST['description'] ?? ''));
            $active = !empty($_POST['is_active']) ? 1 : 0;
            if ($name === '') $errors[] = 'Level name is required.';
            if (mb_strlen($name) > 100) $errors[] = 'Level name must be 100 characters or less.';
            if ($code !== '' && mb_strlen($code) > 30) $errors[] = 'Level code must be 30 characters or less.';
            if ($desc !== '' && mb_strlen($desc) > 2000) $errors[] = 'Description must be 2000 characters or less.';
            // Uniqueness on name
            if (empty($errors)) {
                $dupe = $db->fetchOne(
                    "SELECT id FROM academic_levels
                     WHERE tenant_id = ? AND level_name = ? AND deleted_at IS NULL",
                    [$tenantId, $name]
                );
                if ($dupe) $errors[] = 'A level named "' . $name . '" already exists.';
            }
            // Uniqueness on code (if provided)
            if (empty($errors) && $code !== '') {
                $dupe = $db->fetchOne(
                    "SELECT id FROM academic_levels
                     WHERE tenant_id = ? AND level_code = ? AND deleted_at IS NULL",
                    [$tenantId, $code]
                );
                if ($dupe) $errors[] = 'A level with code "' . $code . '" already exists.';
            }
            if (empty($errors)) {
                $db->beginTransaction();
                // If sort not specified (0), auto-assign
                if ($sort <= 0) {
                    $sort = nextSortOrder($db, $tenantId);
                }
                $newId = $db->insert(
                    "INSERT INTO academic_levels
                        (uuid, tenant_id, school_id, level_name, level_code,
                         sort_order, description, is_active,
                         created_at, updated_at)
                     VALUES (?, ?, NULL, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        uuidv4(),
                        $tenantId,
                        $name,
                        $code !== '' ? $code : null,
                        $sort,
                        $desc !== '' ? $desc : null,
                        $active,
                    ]
                );
                writeAudit(
                    $db,
                    $tenantId,
                    $userId,
                    'academic.level.created',
                    'academic_levels',
                    (int)$newId,
                    [
                        'level_name' => $name,
                        'level_code' => $code,
                        'sort_order' => $sort,
                        'is_active' => $active
                    ]
                );
                $db->commit();
                $_SESSION['success'] = 'Level "' . $name . '" created.';
                header('Location: /platform/tenant/academic/levels.php');
                exit;
            }
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: /platform/tenant/academic/levels.php?action=new');
            exit;
        }
        // ---------- UPDATE ----------
        if ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['level_name'] ?? ''));
            $code = trim((string)($_POST['level_code'] ?? ''));
            $sort = (int)($_POST['sort_order'] ?? 0);
            $desc = trim((string)($_POST['description'] ?? ''));
            $active = !empty($_POST['is_active']) ? 1 : 0;
            $level = $db->fetchOne(
                "SELECT * FROM academic_levels
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$level) $errors[] = 'Level not found.';
            if ($name === '') $errors[] = 'Level name is required.';
            if (mb_strlen($name) > 100) $errors[] = 'Level name must be 100 characters or less.';
            if ($code !== '' && mb_strlen($code) > 30) $errors[] = 'Level code must be 30 characters or less.';
            // Uniqueness on name (excluding self)
            if (empty($errors)) {
                $dupe = $db->fetchOne(
                    "SELECT id FROM academic_levels
                     WHERE tenant_id = ? AND level_name = ? AND deleted_at IS NULL AND id != ?",
                    [$tenantId, $name, $id]
                );
                if ($dupe) $errors[] = 'Another level named "' . $name . '" already exists.';
            }
            // Uniqueness on code (excluding self)
            if (empty($errors) && $code !== '') {
                $dupe = $db->fetchOne(
                    "SELECT id FROM academic_levels
                     WHERE tenant_id = ? AND level_code = ? AND deleted_at IS NULL AND id != ?",
                    [$tenantId, $code, $id]
                );
                if ($dupe) $errors[] = 'Another level with code "' . $code . '" already exists.';
            }
            if (empty($errors) && $level) {
                $db->beginTransaction();
                if ($sort <= 0) $sort = (int)$level['sort_order'];
                $db->execute(
                    "UPDATE academic_levels
                     SET level_name = ?,
                         level_code = ?,
                         sort_order = ?,
                         description = ?,
                         is_active = ?,
                         updated_at = NOW()
                     WHERE id = ? AND tenant_id = ?",
                    [
                        $name,
                        $code !== '' ? $code : null,
                        $sort,
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
                    'academic.level.updated',
                    'academic_levels',
                    $id,
                    [
                        'level_name' => $name,
                        'level_code' => $code,
                        'sort_order' => $sort,
                        'is_active' => $active
                    ]
                );
                $db->commit();
                $_SESSION['success'] = 'Level updated.';
                header('Location: /platform/tenant/academic/levels.php');
                exit;
            }
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: /platform/tenant/academic/levels.php?action=edit&id=' . $id);
            exit;
        }
        // ---------- TOGGLE ACTIVE ----------
        if ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            $level = $db->fetchOne(
                "SELECT * FROM academic_levels
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$level) throw new Exception('Level not found.');
            $newActive = ((int)$level['is_active']) === 1 ? 0 : 1;
            $db->beginTransaction();
            $db->execute(
                "UPDATE academic_levels SET is_active = ?, updated_at = NOW()
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$newActive, $id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.level.toggled_active',
                'academic_levels',
                $id,
                ['is_active' => $newActive]
            );
            $db->commit();
            $_SESSION['success'] = '"' . $level['level_name'] . '" is now ' . ($newActive ? 'active' : 'inactive') . '.';
            header('Location: /platform/tenant/academic/levels.php');
            exit;
        }
        // ---------- DELETE ----------
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $level = $db->fetchOne(
                "SELECT * FROM academic_levels
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$level) throw new Exception('Level not found.');
            $deps = levelDependencies($db, $tenantId, $id);
            if ($deps['total'] > 0) {
                throw new Exception(
                    'Cannot delete "' . $level['level_name'] . '". It is referenced by: ' .
                        implode(', ', $deps['labels']) .
                        '. Remove or reassign those references first, or deactivate the level instead.'
                );
            }
            $db->beginTransaction();
            $db->execute(
                "UPDATE academic_levels SET deleted_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.level.deleted',
                'academic_levels',
                $id,
                ['level_name' => $level['level_name']]
            );
            $db->commit();
            $_SESSION['success'] = 'Level "' . $level['level_name'] . '" deleted.';
            header('Location: /platform/tenant/academic/levels.php');
            exit;
        }
        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Academic levels action error: ' . $e->getMessage());
        $_SESSION['errors'] = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/academic/levels.php');
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
$settings = loadActiveSettings($db, $tenantId);
$labelAcademic = $settings['label_academic_structure'] ?? 'Academic Year';
$labelLevel = $settings['label_level'] ?? 'Level';
$labelClass = $settings['label_class'] ?? 'Class';
$labelStream = $settings['label_stream'] ?? 'Stream';
$labelTerm = $settings['label_term'] ?? 'Term';
// Levels list, with dependency counts in one pass
$levels = $db->fetchAll(
    "SELECT al.*,
            (SELECT COUNT(*) FROM streams s
             WHERE s.tenant_id = al.tenant_id AND s.academic_level_id = al.id AND s.deleted_at IS NULL) AS stream_count,
            (SELECT COUNT(*) FROM subjects sub
             WHERE sub.tenant_id = al.tenant_id AND sub.academic_level_id = al.id AND sub.deleted_at IS NULL) AS subject_count,
            (SELECT COUNT(*) FROM class_offerings co
             WHERE co.tenant_id = al.tenant_id AND co.academic_level_id = al.id AND co.deleted_at IS NULL) AS offering_count,
            (SELECT COUNT(*) FROM curriculum cur
             WHERE cur.tenant_id = al.tenant_id AND cur.academic_level_id = al.id AND cur.deleted_at IS NULL) AS curriculum_count
     FROM academic_levels al
     WHERE al.tenant_id = ? AND al.deleted_at IS NULL
     ORDER BY al.sort_order ASC, al.level_name ASC",
    [$tenantId]
);
// For edit modal
$editLevel = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && !empty($_GET['id'])) {
    $editLevel = $db->fetchOne(
        "SELECT * FROM academic_levels
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [(int)$_GET['id'], $tenantId]
    );
}
$showNewModal = (isset($_GET['action']) && $_GET['action'] === 'new') || ($useForm && (($formData['action'] ?? '') === 'create'));
// Suggested sort for new
$suggestedSort = nextSortOrder($db, $tenantId);
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
    global $formData, $useForm, $editLevel;
    if ($useForm && array_key_exists($key, $formData)) {
        return h($formData[$key]);
    }
    if ($editLevel && array_key_exists($key, $editLevel)) {
        return h($editLevel[$key]);
    }
    return h($default);
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

        .card-custom {
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

        .card-custom .card-header-custom {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .card-custom .card-header-custom h6 {
            font-weight: 600;
            margin: 0;
            font-size: 15px;
            color: #1a1a2e;
            display: flex;
            align-items: center;
        }

        .card-custom .card-body-custom {
            padding: 20px 24px;
        }

        .card-custom .card-body-custom.flush {
            padding: 0;
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
            min-height: 70px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 4px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .form-control[readonly] {
            background: #f8f9fa;
            cursor: not-allowed;
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

        .table-container {
            background: #fff;
            border-radius: 14px;
            padding: 0;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            width: 100%;
            max-width: 1100px;
            margin: 0 auto;
        }

        .table-container .table {
            margin: 0;
            font-size: 13px;
            width: 100%;
        }

        .table-container .table thead th {
            background: #f8f9fa;
            padding: 12px 16px;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            border-bottom: 2px solid #e9ecef;
            white-space: nowrap;
        }

        .table-container .table tbody td {
            padding: 12px 16px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .table-container .table tbody tr:hover {
            background: #f8f9fa;
        }

        .table-container .table tbody tr:last-child td {
            border-bottom: none;
        }

        .level-name {
            font-weight: 700;
            font-size: 14px;
            color: #1a1a2e;
        }

        .level-desc {
            font-size: 12px;
            color: #6c757d;
            margin-top: 2px;
        }

        .code-badge {
            display: inline-block;
            background: #e3f0ff;
            color: #0d6efd;
            padding: 2px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            font-family: monospace;
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

        .dep-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }

        .dep-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #f1f3f5;
            color: #495057;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 500;
        }

        .dep-chip.zero {
            background: transparent;
            color: #adb5bd;
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

            .tenant-banner {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }

            .table-container .table {
                font-size: 12px;
            }

            .table-container .table thead th {
                padding: 8px 10px;
                font-size: 10px;
            }

            .table-container .table tbody td {
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
                        <h1><i class="fas fa-layer-group me-2"></i><?php echo h($labelLevel); ?>s</h1>
                        <p>Define the academic <?php echo h(strtolower($labelLevel)); ?>s of your institution</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/academic/levels.php?action=new" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i> Add <?php echo h($labelLevel); ?>
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
                                <?php echo count($levels); ?> <?php echo h(strtolower($labelLevel)); ?>(s) defined
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>Tenant-wide, shared by all schools
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
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="min-width:200px;"><?php echo h($labelLevel); ?></th>
                                <th style="text-align:center;">Order</th>
                                <th>Referenced by</th>
                                <th style="text-align:center;">Status</th>
                                <th style="text-align:right;min-width:200px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($levels)): ?>
                                <tr>
                                    <td colspan="5" class="empty-state">
                                        <i class="fas fa-layer-group"></i>
                                        <h5>No <?php echo h($labelLevel); ?>s yet</h5>
                                        <p>Create your first <?php echo h(strtolower($labelLevel)); ?> to start building your academic structure.</p>
                                        <a href="/platform/tenant/academic/levels.php?action=new" class="btn btn-primary btn-sm mt-2">
                                            <i class="fas fa-plus me-1"></i> Add <?php echo h($labelLevel); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($levels as $lv): ?>
                                    <?php
                                    $isActive = ((int)$lv['is_active']) === 1;
                                    $streams = (int)($lv['stream_count'] ?? 0);
                                    $subjects = (int)($lv['subject_count'] ?? 0);
                                    $offerings = (int)($lv['offering_count'] ?? 0);
                                    $curricula = (int)($lv['curriculum_count'] ?? 0);
                                    $totalDeps = $streams + $subjects + $offerings + $curricula;
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="level-name"><?php echo h($lv['level_name']); ?></div>
                                            <?php if (!empty($lv['level_code'])): ?>
                                                <div style="margin-top:2px;">
                                                    <span class="code-badge"><?php echo h($lv['level_code']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($lv['description'])): ?>
                                                <div class="level-desc"><?php echo h($lv['description']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <span style="font-size:12px;color:#6c757d;font-weight:600;">
                                                #<?php echo h((string)(int)$lv['sort_order']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="dep-chips">
                                                <?php if ($streams > 0): ?>
                                                    <span class="dep-chip"><i class="fas fa-code-branch"></i><?php echo h((string)$streams); ?> <?php echo h(strtolower($labelStream)); ?>s</span>
                                                <?php endif; ?>
                                                <?php if ($subjects > 0): ?>
                                                    <span class="dep-chip"><i class="fas fa-book"></i><?php echo h((string)$subjects); ?> subjects</span>
                                                <?php endif; ?>
                                                <?php if ($offerings > 0): ?>
                                                    <span class="dep-chip"><i class="fas fa-door-open"></i><?php echo h((string)$offerings); ?> <?php echo h(strtolower($labelClass)); ?> offerings</span>
                                                <?php endif; ?>
                                                <?php if ($curricula > 0): ?>
                                                    <span class="dep-chip"><i class="fas fa-list"></i><?php echo h((string)$curricula); ?> curriculum</span>
                                                <?php endif; ?>
                                                <?php if ($totalDeps === 0): ?>
                                                    <span class="dep-chip zero"><i class="fas fa-link"></i>not referenced</span>
                                                <?php endif; ?>
                                            </div>
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
                                                <a href="/platform/tenant/academic/levels.php?action=edit&id=<?php echo (int)$lv['id']; ?>"
                                                    class="btn btn-outline-primary">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <!-- [CLASSES MODULE v1.0-2026-10-07] Manage Classes per level. -->
                                                <a href="/platform/tenant/academic/classes.php?level_id=<?php echo (int)$lv['id']; ?>"
                                                    class="btn btn-outline-primary"
                                                    title="Manage <?php echo h($labelClass); ?>es for this <?php echo h(strtolower($labelLevel)); ?>">
                                                    <i class="fas fa-door-closed"></i> Classes
                                                </a>
                                                <form method="POST" style="display:inline-block;"
                                                    onsubmit="return confirm('<?php echo $isActive ? 'Deactivate' : 'Activate'; ?> &quot;<?php echo h(addslashes($lv['level_name'])); ?>&quot;?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="toggle_active">
                                                    <input type="hidden" name="id" value="<?php echo (int)$lv['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-warning">
                                                        <i class="fas fa-<?php echo $isActive ? 'pause' : 'play'; ?>"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" style="display:inline-block;"
                                                    onsubmit="return confirm('Delete &quot;<?php echo h(addslashes($lv['level_name'])); ?>&quot;? This will be refused if it is referenced anywhere.');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo (int)$lv['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-danger">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </main>
        </div>
    </div>
    <div class="modal fade<?php echo $showNewModal ? ' show' : ''; ?>"
        id="newLevelModal" tabindex="-1"
        <?php echo $showNewModal ? 'style="display:block;background:rgba(0,0,0,0.4);"' : ''; ?>>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/academic/levels.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5><i class="fas fa-layer-group text-primary me-2"></i>Add <?php echo h($labelLevel); ?></h5>
                        <a href="/platform/tenant/academic/levels.php" class="btn-close"></a>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label" for="new_level_name"><?php echo h($labelLevel); ?> Name <span class="required">*</span></label>
                                <input type="text" class="form-control" id="new_level_name" name="level_name"
                                    maxlength="100" required
                                    placeholder="e.g. Primary"
                                    value="<?php echo ($useForm && ($formData['action'] ?? '') === 'create') ? v('level_name') : ''; ?>">
                                <div class="form-text">Must be unique within your institution.</div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="new_level_code">Code</label>
                                <input type="text" class="form-control" id="new_level_code" name="level_code"
                                    maxlength="30"
                                    placeholder="e.g. PRI"
                                    value="<?php echo ($useForm && ($formData['action'] ?? '') === 'create') ? v('level_code') : ''; ?>">
                                <div class="form-text">Optional. Must be unique.</div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="new_sort_order">Sort Order</label>
                            <input type="number" class="form-control" id="new_sort_order" name="sort_order"
                                value="<?php echo ($useForm && ($formData['action'] ?? '') === 'create') ? v('sort_order') : $suggestedSort; ?>">
                            <div class="form-text">Lower numbers appear first. Suggested: <?php echo (int)$suggestedSort; ?>.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="new_description">Description</label>
                            <textarea class="form-control" id="new_description" name="description"
                                maxlength="2000" rows="3"
                                placeholder="Optional notes about this level"><?php echo ($useForm && ($formData['action'] ?? '') === 'create') ? v('description') : ''; ?></textarea>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="new_level_active" name="is_active" value="1" checked>
                            <label class="form-check-label" for="new_level_active">Active</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="/platform/tenant/academic/levels.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php if ($editLevel): ?>
        <div class="modal fade show" id="editLevelModal" tabindex="-1"
            style="display:block;background:rgba(0,0,0,0.4);">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" action="/platform/tenant/academic/levels.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?php echo (int)$editLevel['id']; ?>">
                        <div class="modal-header">
                            <h5><i class="fas fa-edit text-primary me-2"></i>Edit <?php echo h($labelLevel); ?></h5>
                            <a href="/platform/tenant/academic/levels.php" class="btn-close"></a>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label" for="edit_level_name"><?php echo h($labelLevel); ?> Name <span class="required">*</span></label>
                                    <input type="text" class="form-control" id="edit_level_name" name="level_name"
                                        maxlength="100" required
                                        value="<?php echo h($editLevel['level_name']); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="edit_level_code">Code</label>
                                    <input type="text" class="form-control" id="edit_level_code" name="level_code"
                                        maxlength="30"
                                        value="<?php echo h($editLevel['level_code']); ?>">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="edit_sort_order">Sort Order</label>
                                <input type="number" class="form-control" id="edit_sort_order" name="sort_order"
                                    value="<?php echo (int)$editLevel['sort_order']; ?>">
                                <div class="form-text">Lower numbers appear first.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="edit_description">Description</label>
                                <textarea class="form-control" id="edit_description" name="description"
                                    maxlength="2000" rows="3"><?php echo h($editLevel['description']); ?></textarea>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="edit_level_active" name="is_active" value="1"
                                    <?php echo ((int)$editLevel['is_active'] === 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="edit_level_active">Active</label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <a href="/platform/tenant/academic/levels.php" class="btn btn-outline-secondary">Cancel</a>
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
                const modalEl = document.getElementById('newLevelModal');
                if (modalEl && !modalEl.classList.contains('show')) {
                    const modal = new bootstrap.Modal(modalEl);
                    modal.show();
                }
            }
        })();
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