<?php

/**
 * Academic Class Progression — the ladder of classes per school.
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Academic
 * @version 1.0
 * @filepath public/platform/tenant/academic/class-progression.php
 *
 * v1.0 change (2026-10-07) [CLASS PROGRESSION]:
 *   New module. Manages the class-to-class promotion ladder for the
 *   current school. It reads and writes two columns on `classes`:
 *     - next_class_id  int(11) DEFAULT NULL, FK to classes(id)
 *                      ON DELETE SET NULL
 *     - is_terminal    tinyint(1) NOT NULL DEFAULT 0
 *
 *   Decisions locked:
 *     - The ladder lives inside `classes` (self-referencing pointer).
 *     - Terminal rung is expressed by is_terminal = 1 on the last class.
 *       When a class is terminal, its next_class_id is forced to NULL.
 *     - Order is the pointer chain. No promotion_order column.
 *     - The editor is scoped to the current session's school_id — the
 *       same Option A scoping used by academic/classes.php.
 *     - Single predecessor: at any time, one class may be the
 *       next_class_id target of at most one other class. A chain
 *       therefore cannot merge. The dropdown filters out already-used
 *       targets and self.
 *     - The ladder may be multi-chain: a school may run a Primary
 *       track, a JHS track, and a STEM track side by side. Each is its
 *       own chain, each ends in an is_terminal = 1 class.
 *     - No backfill. Existing classes carry next_class_id = NULL and
 *       is_terminal = 0 until the admin sets them here.
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

$pageTitle   = 'Class Progression - Student 360 Platform';
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
 * Load every non-deleted class for the tenant + the current school.
 * Returns rows keyed by id with the columns the editor needs.
 */
function loadClassesForSchool($db, int $tenantId, int $schoolId): array
{
    if ($schoolId <= 0) return [];
    $rows = $db->fetchAll(
        "SELECT c.id, c.class_name, c.class_code, c.academic_level_id,
                c.next_class_id, c.is_terminal, c.is_active,
                al.level_name, al.level_code
           FROM classes c
           LEFT JOIN academic_levels al ON al.id = c.academic_level_id AND al.deleted_at IS NULL
          WHERE c.tenant_id = ? AND c.school_id = ? AND c.deleted_at IS NULL
          ORDER BY al.sort_order ASC, c.class_name ASC, c.id ASC",
        [$tenantId, $schoolId]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['id']] = $r;
    }
    return $out;
}

/**
 * Compute the inverse map: for each class id, the id of the class whose
 * next_class_id points at it. Enforces single predecessor naturally —
 * if two rows point at the same target, the last one wins here, but
 * the save action refuses to create that state in the first place.
 */
function computePredecessors(array $classes): array
{
    $pred = [];
    foreach ($classes as $cid => $c) {
        $nid = $c['next_class_id'] !== null ? (int)$c['next_class_id'] : 0;
        if ($nid > 0) {
            $pred[$nid] = $cid;
        }
    }
    return $pred;
}

/**
 * Walk the chain starting from a root class. Returns an ordered list of
 * class ids. Stops when it reaches a class whose next_class_id is NULL
 * or whose id is already in the visited set (cycle guard).
 */
function walkChain(array $classes, int $rootId): array
{
    $chain = [];
    $visited = [];
    $cur = $rootId;
    while ($cur > 0 && isset($classes[$cur]) && !isset($visited[$cur])) {
        $visited[$cur] = true;
        $chain[] = $cur;
        $nid = $classes[$cur]['next_class_id'] !== null ? (int)$classes[$cur]['next_class_id'] : 0;
        $cur = $nid;
    }
    return $chain;
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
        // ---------- SAVE LADDER ----------
        if ($action === 'save_ladder') {
            if ($schoolId <= 0) {
                throw new Exception('No school context. Please select a school first.');
            }

            $classes = loadClassesForSchool($db, $tenantId, $schoolId);
            if (empty($classes)) {
                throw new Exception('No classes exist for this school.');
            }

            $nextIn    = is_array($_POST['next_class_id'] ?? null) ? $_POST['next_class_id'] : [];
            $termIn    = is_array($_POST['is_terminal']   ?? null) ? $_POST['is_terminal']   : [];

            // Normalize: for each class in scope, resolve the intended
            // next_class_id and is_terminal.
            $next  = [];
            $term  = [];
            foreach ($classes as $cid => $c) {
                $nid = isset($nextIn[$cid]) ? (int)$nextIn[$cid] : 0;
                $isT = !empty($termIn[$cid]) ? 1 : 0;

                // Terminal forces next_class_id to NULL.
                if ($isT === 1) {
                    $nid = 0;
                }

                // Next cannot be self.
                if ($nid === $cid) {
                    throw new Exception('"' . $c['class_name'] . '" cannot point at itself.');
                }

                // Next must be a class in the same tenant + school, not soft-deleted.
                if ($nid > 0 && !isset($classes[$nid])) {
                    throw new Exception('Selected next class for "' . $c['class_name'] . '" is not available in this school.');
                }

                $next[$cid] = $nid;
                $term[$cid] = $isT;
            }

            // Single predecessor: no class may be the target of more than one class.
            $seen = [];
            foreach ($next as $cid => $nid) {
                if ($nid <= 0) continue;
                if (isset($seen[$nid])) {
                    throw new Exception(
                        'Two classes both point at "' . $classes[$nid]['class_name'] .
                            '". Only one predecessor is allowed.'
                    );
                }
                $seen[$nid] = $cid;
            }

            // Cycle guard: walking from every root must not revisit a class.
            // We check every class, not only roots, so an isolated cycle is caught.
            foreach ($classes as $cid => $c) {
                $visited = [];
                $cur = $cid;
                while ($cur > 0 && isset($classes[$cur])) {
                    if (isset($visited[$cur])) {
                        throw new Exception('The ladder would contain a cycle through "' . $classes[$cur]['class_name'] . '".');
                    }
                    $visited[$cur] = true;
                    $cur = $next[$cur] ?? 0;
                }
            }

            // A class with is_terminal = 1 must not be the target of anything.
            foreach ($next as $cid => $nid) {
                if ($nid > 0 && $term[$nid] === 1) {
                    throw new Exception('"' . $classes[$nid]['class_name'] . '" is terminal and cannot be a next class.');
                }
            }

            $db->beginTransaction();

            $changed = 0;
            foreach ($classes as $cid => $c) {
                $oldNext = $c['next_class_id'] !== null ? (int)$c['next_class_id'] : 0;
                $oldTerm = (int)$c['is_terminal'];
                if ($oldNext === $next[$cid] && $oldTerm === $term[$cid]) {
                    continue;
                }
                $db->execute(
                    "UPDATE classes
                        SET next_class_id = ?,
                            is_terminal   = ?,
                            updated_at    = NOW()
                      WHERE id = ? AND tenant_id = ? AND school_id = ? AND deleted_at IS NULL",
                    [
                        $next[$cid] > 0 ? $next[$cid] : null,
                        $term[$cid],
                        $cid,
                        $tenantId,
                        $schoolId,
                    ]
                );
                $changed++;
            }

            if ($changed > 0) {
                writeAudit(
                    $db,
                    $tenantId,
                    $userId,
                    'academic.class_progression.updated',
                    'classes',
                    null,
                    [
                        'school_id' => $schoolId,
                        'changed'   => $changed,
                        'total'     => count($classes),
                    ]
                );
            }

            $db->commit();

            $_SESSION['success'] = $changed === 0
                ? 'No changes to save.'
                : ('Ladder saved. ' . $changed . ' class row' . ($changed === 1 ? '' : 's') . ' updated.');
            header('Location: /platform/tenant/academic/class-progression.php');
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Academic class progression action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/academic/class-progression.php');
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
$labelLevel    = $settings['label_level'] ?? 'Level';
$labelClass    = $settings['label_class'] ?? 'Class';

$classes = loadClassesForSchool($db, $tenantId, $schoolId);

// Predecessors map — used to identify roots.
$predecessors = computePredecessors($classes);

// Roots: classes that no other class points at. Includes any class whose
// predecessor is not in scope (should not happen given the save guard,
// but the walk is defensive).
$roots = [];
foreach ($classes as $cid => $c) {
    if (!isset($predecessors[$cid])) {
        $roots[] = $cid;
    }
}

// Chains from roots. Each chain is an ordered list of class ids.
$chains = [];
foreach ($roots as $rid) {
    $chain = walkChain($classes, $rid);
    if (!empty($chain)) $chains[] = $chain;
}

// Unlinked: classes with no next_class_id, not terminal, and not part of any chain.
$inChain = [];
foreach ($chains as $chain) {
    foreach ($chain as $cid) $inChain[$cid] = true;
}
$unlinked = [];
foreach ($classes as $cid => $c) {
    $nid = $c['next_class_id'] !== null ? (int)$c['next_class_id'] : 0;
    $isT = (int)$c['is_terminal'];
    if ($nid === 0 && $isT === 0 && !isset($inChain[$cid])) {
        $unlinked[] = $cid;
    }
}

// Used targets — for the dropdown filter (single predecessor rule).
$usedTargets = [];
foreach ($classes as $cid => $c) {
    $nid = $c['next_class_id'] !== null ? (int)$c['next_class_id'] : 0;
    if ($nid > 0) $usedTargets[$nid] = true;
}

// Tenant name
$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

// School row
$school = null;
if ($schoolId > 0) {
    $school = $db->fetchOne(
        "SELECT id, school_name FROM schools WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$schoolId, $tenantId]
    ) ?: null;
}

// Form helper (consistent with classes.php)
function v(string $key, $default = ''): string
{
    global $formData, $useForm;
    if ($useForm && array_key_exists($key, $formData)) {
        return h($formData[$key]);
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

        .ladder-card {
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

        .ladder-card .ladder-header {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            background: linear-gradient(135deg, #f8fafc 0%, #eef6ff 100%);
        }

        .ladder-card .ladder-header .ladder-title {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .ladder-card .ladder-header .ladder-title h6 {
            font-weight: 700;
            margin: 0;
            font-size: 16px;
            color: #1a1a2e;
        }

        .ladder-card .ladder-body {
            padding: 18px 24px;
        }

        .chain-block {
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 14px;
            background: #fff;
        }

        .chain-block .chain-label {
            font-size: 11px;
            font-weight: 700;
            color: #4a6b8a;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 8px;
        }

        .chain-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            padding: 6px 0;
        }

        .chain-arrow {
            color: #adb5bd;
            font-size: 14px;
        }

        .chain-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #eef3f7;
            color: #1a2a3a;
        }

        .chain-chip.terminal {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .chain-chip.root {
            background: #e3f0ff;
            color: #0d6efd;
        }

        .ladder-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .ladder-table thead th {
            background: #f8f9fa;
            padding: 10px 16px;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            border-bottom: 1px solid #e9ecef;
            text-align: left;
            white-space: nowrap;
        }

        .ladder-table tbody td {
            padding: 10px 16px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .ladder-table tbody tr:last-child td {
            border-bottom: none;
        }

        .ladder-table tbody tr:hover {
            background: #fafbfc;
        }

        .ladder-table .form-select,
        .ladder-table .form-control {
            height: 36px;
            font-size: 13px;
            border-radius: 8px;
        }

        .class-name {
            font-weight: 600;
            color: #1a1a2e;
            font-size: 14px;
        }

        .class-meta {
            font-size: 11px;
            color: #6c757d;
            margin-top: 2px;
        }

        .class-code {
            display: inline-block;
            background: #e3f0ff;
            color: #0d6efd;
            padding: 2px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            font-family: 'Courier New', monospace;
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
                        <h1><i class="fas fa-sitemap me-2"></i>Class Progression</h1>
                        <p>Set the ladder — which <?php echo h(strtolower($labelClass)); ?> a student moves into after each one</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/academic/classes.php" class="btn btn-outline-secondary">
                            <i class="fas fa-door-closed me-2"></i> Back to <?php echo h($labelClass); ?>es
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo h($tenantName); ?></div>
                            <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                <?php if ($school): ?>
                                    <?php echo h($school['school_name']); ?> ·
                                <?php endif; ?>
                                <?php echo h((string)count($classes)); ?> <?php echo h(strtolower($labelClass)); ?>(es) ·
                                <?php echo h((string)count($chains)); ?> chain(s) ·
                                <?php echo h((string)count($unlinked)); ?> unlinked
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-school me-1"></i>Scoped to this school
                    </span>
                </div>

                <?php if (!$school): ?>
                    <div class="alert-pro alert-pro-error">
                        <div class="alert-pro-icon"><i class="fas fa-exclamation-triangle"></i></div>
                        <div class="alert-pro-content">
                            <div class="alert-pro-title">No school context</div>
                            <div style="font-size:13px;">The current session does not carry a school. Please select a school first.</div>
                        </div>
                    </div>
                <?php endif; ?>

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
                        <div class="ib-title">How the ladder works</div>
                        <div class="ib-text">
                            Each row is one <?php echo h(strtolower($labelClass)); ?>. Pick the next <?php echo h(strtolower($labelClass)); ?>
                            in the dropdown, or tick <strong>Terminal</strong> if this is the final rung (the student reaches
                            <em>Completed / Graduated</em> after it). A <?php echo h(strtolower($labelClass)); ?> cannot point at
                            itself, two <?php echo h(strtolower($labelClass)); ?>es cannot point at the same target, and no chain may loop.
                        </div>
                    </div>
                </div>

                <?php if (empty($classes)): ?>
                    <div class="ladder-card">
                        <div class="empty-state">
                            <i class="fas fa-door-closed"></i>
                            <h5>No <?php echo h(strtolower($labelClass)); ?>es yet</h5>
                            <p>Create <?php echo h(strtolower($labelClass)); ?>es first, then return here to set their ladder.</p>
                            <a href="/platform/tenant/academic/classes.php?action=new" class="btn btn-primary btn-sm mt-2">
                                <i class="fas fa-plus me-1"></i> Add <?php echo h($labelClass); ?>
                            </a>
                        </div>
                    </div>
                <?php else: ?>

                    <?php if (!empty($chains)): ?>
                        <div class="ladder-card">
                            <div class="ladder-header">
                                <div class="ladder-title">
                                    <i class="fas fa-sitemap" style="font-size:18px;color:#4facfe;"></i>
                                    <h6>Current chains</h6>
                                </div>
                            </div>
                            <div class="ladder-body">
                                <?php foreach ($chains as $chain):
                                    $lastIdx = count($chain) - 1;
                                ?>
                                    <div class="chain-block">
                                        <div class="chain-label">
                                            Chain of <?php echo h((string)count($chain)); ?>
                                            <?php echo h(strtolower($labelClass)); ?><?php echo count($chain) === 1 ? '' : 'es'; ?>
                                        </div>
                                        <div class="chain-row">
                                            <?php foreach ($chain as $i => $cid):
                                                $c = $classes[$cid];
                                                $isTerminal = (int)$c['is_terminal'] === 1;
                                                $isRoot = $i === 0;
                                                $cls = 'chain-chip';
                                                if ($isTerminal) $cls .= ' terminal';
                                                elseif ($isRoot) $cls .= ' root';
                                            ?>
                                                <span class="<?php echo $cls; ?>">
                                                    <?php if ($isTerminal): ?>
                                                        <i class="fas fa-flag-checkered"></i>
                                                    <?php elseif ($isRoot): ?>
                                                        <i class="fas fa-play"></i>
                                                    <?php endif; ?>
                                                    <?php echo h($c['class_name']); ?>
                                                </span>
                                                <?php if ($i < $lastIdx): ?>
                                                    <span class="chain-arrow"><i class="fas fa-arrow-right"></i></span>
                                                <?php else: ?>
                                                    <?php if ($isTerminal): ?>
                                                        <span class="chain-arrow"><i class="fas fa-arrow-right"></i></span>
                                                        <span class="chain-chip terminal"><i class="fas fa-graduation-cap"></i>Completed / Graduated</span>
                                                    <?php else: ?>
                                                        <span class="chain-arrow" title="Not linked yet"><i class="fas fa-hourglass-half"></i></span>
                                                        <span class="pill orange"><i class="fas fa-exclamation-triangle"></i>Open end</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="/platform/tenant/academic/class-progression.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_ladder">

                        <div class="ladder-card">
                            <div class="ladder-header">
                                <div class="ladder-title">
                                    <i class="fas fa-list-ol" style="font-size:18px;color:#4facfe;"></i>
                                    <h6>Edit the ladder</h6>
                                </div>
                            </div>
                            <div class="ladder-body" style="padding: 0;">
                                <table class="ladder-table">
                                    <thead>
                                        <tr>
                                            <th style="min-width:220px;"><?php echo h($labelClass); ?></th>
                                            <th style="min-width:200px;">Next <?php echo h($labelClass); ?></th>
                                            <th style="width:130px;text-align:center;">Terminal</th>
                                            <th style="width:120px;text-align:right;">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($classes as $cid => $c):
                                            $nid = $c['next_class_id'] !== null ? (int)$c['next_class_id'] : 0;
                                            $isTerminal = (int)$c['is_terminal'] === 1;
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="class-name"><?php echo h($c['class_name']); ?></div>
                                                    <div class="class-meta">
                                                        <?php if (!empty($c['level_name'])): ?>
                                                            <?php echo h($c['level_name']); ?> ·
                                                        <?php endif; ?>
                                                        <span class="class-code"><?php echo h($c['class_code']); ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <select name="next_class_id[<?php echo (int)$cid; ?>]" class="form-select">
                                                        <option value="0" <?php echo $nid === 0 ? 'selected' : ''; ?>>— none —</option>
                                                        <?php foreach ($classes as $targetId => $t):
                                                            if ((int)$targetId === (int)$cid) continue;               // no self
                                                            if (isset($usedTargets[$targetId]) && (int)$targetId !== $nid) continue;  // single predecessor
                                                        ?>
                                                            <option value="<?php echo (int)$targetId; ?>" <?php echo $nid === (int)$targetId ? 'selected' : ''; ?>>
                                                                <?php echo h($t['class_name']); ?>
                                                                <?php if (!empty($t['level_name'])): ?> (<?php echo h($t['level_name']); ?>)<?php endif; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td style="text-align:center;">
                                                    <input type="checkbox"
                                                        name="is_terminal[<?php echo (int)$cid; ?>]"
                                                        value="1"
                                                        <?php echo $isTerminal ? 'checked' : ''; ?>
                                                        onchange="toggleTerminal(this)">
                                                </td>
                                                <td style="text-align:right;">
                                                    <?php if ($isTerminal): ?>
                                                        <span class="pill green"><i class="fas fa-flag-checkered"></i>Terminal</span>
                                                    <?php elseif ($nid > 0): ?>
                                                        <span class="pill blue"><i class="fas fa-arrow-right"></i>Linked</span>
                                                    <?php else: ?>
                                                        <span class="pill gray"><i class="fas fa-minus"></i>Unlinked</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="ladder-body" style="border-top: 1px solid #f0f2f5; display: flex; justify-content: flex-end; gap: 10px;">
                                <a href="/platform/tenant/academic/class-progression.php" class="btn btn-outline-secondary">Reset</a>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Save Ladder</button>
                            </div>
                        </div>
                    </form>

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

        // When a Terminal checkbox is ticked, the row's next-class dropdown
        // is disabled and reset to "— none —". When unticked, it re-enables.
        function toggleTerminal(checkbox) {
            const row = checkbox.closest('tr');
            if (!row) return;
            const sel = row.querySelector('select[name^="next_class_id"]');
            if (!sel) return;
            if (checkbox.checked) {
                sel.value = '0';
                sel.disabled = true;
            } else {
                sel.disabled = false;
            }
        }

        // Initialize once on load: disable dropdowns where Terminal is already ticked.
        document.querySelectorAll('input[name^="is_terminal"]').forEach(function(cb) {
            if (cb.checked) toggleTerminal(cb);
        });

        // Success box auto-dismiss
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