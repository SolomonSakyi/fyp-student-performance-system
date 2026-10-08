<?php

/**
 * Academic Result Locks — Admin review page for result scope locks.
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Academic
 * @version 1.0
 * @filepath public/platform/tenant/academic/result-locks.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Academic result-locks file of the academic-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'academic_result_locks'
 *       to 'academic' so the partial marks Academic active and
 *       renders the academic sub-menu on this page — consistent
 *       with every other academic file.
 *     - A v1.0 [SWEEP] entry was added above this docblock, and
 *       @version was reduced from 1.2 to 1.0 per Decision X-3.
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
 * v1.2 (removed the non-existent `teacher_id` column from the
 *              isTeacherOnOffering() query, which raised SQLSTATE[42S22]
 *              and silently returned false for every caller, bypassing
 *              the RL3 admin-only guard. The staff_id comparison is
 *              retained unchanged; the semantic question of whether
 *              $userId (a platform_users.id) matches staff.id is a
 *              separate decision tracked elsewhere.)
 *
 * Purpose (from RL4):
 *   The dedicated admin page referenced by results.php for bulk review of
 *   result locks. Lists active and historical locks per (offering, subject,
 *   term) and allows an admin to unlock a scope with a reason.
 *
 * Decisions this page implements:
 *   RL1 (Q1 = 1a) teacher is a staff member linked to the offering via
 *       teacher_class_assignments; the lock UI is admin-only, meaning the
 *       user is NOT a teacher on the offering.
 *   RL2 (Q2 = 2c) two kinds of lock exist:
 *       - row-level: results.status = 'published'
 *       - scope-level: an active row in result_locks
 *       This page reviews and manages the scope-level kind.
 *   RL3 (Q3 = 3b) only an admin may unlock, and unlock requires a non-empty
 *       reason and writes an audit entry.
 *   RL4 (Q4 = 4a + 4b) Lock / Unlock buttons live on results.php; this page
 *       is the bulk review surface.
 *   RL6 (Q6 = 6b) result_locks schema, including the option (b) generated
 *       column active_scope_key, unique key uq_lock_active.
 *
 * Schema this page reads:
 *   result_locks: id, uuid, tenant_id, class_offering_id, subject_id,
 *     academic_term_id, locked_at, locked_by, lock_reason, unlocked_at,
 *     unlocked_by, unlock_reason, created_at, updated_at, deleted_at,
 *     active_scope_key (STORED GENERATED; NULL once unlocked or deleted).
 *   class_offerings, classes, streams, academic_levels, academic_years,
 *     academic_terms, subjects, platform_users — for display joins only.
 *   All scoped by tenant_id and deleted_at IS NULL where the table carries
 *   that column.
 *
 * Session S20 decisions applied:
 *   SH2A per-session CSRF token; POST unlock carries it.
 *   SH3A central Security.php helper, loaded via app/bootstrap.php.
 *   SH4A hardened session started inside bootstrap.
 *   SH5C h() on every echoed value.
 *   SH6B tenant_id scoping and deleted_at IS NULL on every query.
 */

// ============================================
// S20 — Bootstrap
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

$pageTitle   = 'Result Locks - Student 360 Platform';
$currentPage = 'academic';

$db = DatabaseHelper::getInstance();

// ============================================
// HELPERS — page-local
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

function isTeacherOnOffering($db, int $tenantId, int $userId, int $offeringId): bool
{
    if ($userId <= 0 || $offeringId <= 0) return false;
    try {
        $row = $db->fetchOne(
            "SELECT id FROM teacher_class_assignments
              WHERE tenant_id = ?
                AND class_offering_id = ?
                AND staff_id = ?
                AND deleted_at IS NULL
              LIMIT 1",
            [$tenantId, $offeringId, $userId]
        );
        return $row !== false && $row !== null;
    } catch (Exception $e) {
        return false;
    }
}

function requireAdminForLock($db, int $tenantId, int $userId, int $offeringId, bool $isSuperAdmin): void
{
    if ($isSuperAdmin) return;
    if (isTeacherOnOffering($db, $tenantId, $userId, $offeringId)) {
        throw new Exception('Teachers cannot lock or unlock results for their own offerings.');
    }
}

function unlockScope($db, int $tenantId, int $userId, int $offeringId, int $subjectId, int $termId, string $reason): int
{
    $db->execute(
        "UPDATE result_locks
            SET unlocked_at = NOW(),
                unlocked_by = ?,
                unlock_reason = ?,
                updated_at = NOW()
          WHERE tenant_id = ?
            AND class_offering_id = ?
            AND subject_id = ?
            AND academic_term_id = ?
            AND unlocked_at IS NULL
            AND deleted_at IS NULL",
        [$userId ?: null, $reason, $tenantId, $offeringId, $subjectId, $termId]
    );
    return 1;
}

function fmtUserName($first, $last, $fallback = '—')
{
    $f = trim((string)$first);
    $l = trim((string)$last);
    $n = trim($f . ' ' . $l);
    return $n !== '' ? $n : $fallback;
}

function statusPillForLock($lock): array
{
    if (!empty($lock['deleted_at'])) {
        return ['gray', 'Deleted'];
    }
    if (!empty($lock['unlocked_at'])) {
        return ['orange', 'Released'];
    }
    return ['red', 'Active'];
}

// ============================================
// ACTIONS (POST)
// ============================================
$errors   = [];
$formData = [];
$action   = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $formData = $_POST;

    try {
        // ---------------- UNLOCK ----------------
        if ($action === 'unlock') {
            $lockId = (int)($_POST['lock_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));

            if ($lockId <= 0) {
                throw new Exception('Lock not selected.');
            }
            if ($reason === '') {
                throw new Exception('A reason is required to unlock a scope.');
            }

            $lock = $db->fetchOne(
                "SELECT * FROM result_locks
                  WHERE id = ? AND tenant_id = ?
                    AND unlocked_at IS NULL
                    AND deleted_at IS NULL",
                [$lockId, $tenantId]
            );
            if (!$lock) {
                throw new Exception('Active lock not found.');
            }

            $offeringId = (int)$lock['class_offering_id'];
            $subjectId  = (int)$lock['subject_id'];
            $termId     = (int)$lock['academic_term_id'];

            requireAdminForLock($db, $tenantId, $userId, $offeringId, $isSuperAdmin);

            $db->beginTransaction();
            unlockScope($db, $tenantId, $userId, $offeringId, $subjectId, $termId, $reason);

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.result.unlocked',
                'results',
                $offeringId,
                [
                    'offering_id' => $offeringId,
                    'subject_id'  => $subjectId,
                    'term_id'     => $termId,
                    'lock_id'     => $lockId,
                    'reason'      => $reason,
                    'source'      => 'result-locks.php',
                ]
            );

            $db->commit();
            $_SESSION['success'] = 'Scope unlocked.';
            header('Location: /platform/tenant/academic/result-locks.php');
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('result-locks action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/academic/result-locks.php');
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
$successMessage = null;
if (isset($_SESSION['success'])) {
    $successMessage = $_SESSION['success'];
    unset($_SESSION['success']);
}

// ============================================
// LOAD PAGE DATA
// ============================================
$settings      = null;
try {
    $settings = $db->fetchOne(
        "SELECT * FROM academic_settings
         WHERE tenant_id = ? AND status = 'active' AND deleted_at IS NULL
         ORDER BY version DESC, id DESC LIMIT 1",
        [$tenantId]
    );
} catch (Exception $e) {
    $settings = null;
}
$labelAcademic = $settings['label_academic_structure'] ?? 'Academic Year';
$labelLevel    = $settings['label_level'] ?? 'Level';
$labelClass    = $settings['label_class'] ?? 'Class';
$labelStream   = $settings['label_stream'] ?? 'Stream';
$labelTerm     = $settings['label_term'] ?? 'Term';

$filterStatus = trim((string)($_GET['status'] ?? 'active'));  // active | history | all

// [FIX v1.1] The previous version of this query selected a non-existent
// column `co.class_offering_id AS _unused_offering_placeholder`. That column
// does not exist on class_offerings (its primary key is `id`), which caused
// SQLSTATE[42S22]. The column is removed. Everything else in the query is
// unchanged.
$where = ["rl.tenant_id = ?"];
$params = [$tenantId];

if ($filterStatus === 'active') {
    $where[] = "rl.unlocked_at IS NULL";
    $where[] = "rl.deleted_at IS NULL";
} elseif ($filterStatus === 'history') {
    $where[] = "(rl.unlocked_at IS NOT NULL OR rl.deleted_at IS NOT NULL)";
}
// 'all' adds no filter beyond tenant

$whereClause = implode(' AND ', $where);

$locks = $db->fetchAll(
    "SELECT rl.*,
            c.class_name,
            c.class_code,
            s.stream_name,
            al.level_name,
            ay.year_name,
            sub.subject_name,
            sub.subject_code,
            at.term_name,
            locked_user.first_name          AS locked_by_first,
            locked_user.last_name           AS locked_by_last,
            unlocked_user.first_name        AS unlocked_by_first,
            unlocked_user.last_name         AS unlocked_by_last
     FROM result_locks rl
     LEFT JOIN class_offerings co  ON co.id  = rl.class_offering_id
     LEFT JOIN classes c           ON c.id   = co.class_id
     LEFT JOIN streams s           ON s.id   = co.stream_id
     LEFT JOIN academic_levels al  ON al.id  = co.academic_level_id
     LEFT JOIN academic_years ay   ON ay.id  = co.academic_year_id
     LEFT JOIN subjects sub        ON sub.id = rl.subject_id
     LEFT JOIN academic_terms at   ON at.id  = rl.academic_term_id
     LEFT JOIN platform_users locked_user
        ON locked_user.id = rl.locked_by
     LEFT JOIN platform_users unlocked_user
        ON unlocked_user.id = rl.unlocked_by
     WHERE $whereClause
     ORDER BY
        CASE WHEN rl.unlocked_at IS NULL AND rl.deleted_at IS NULL THEN 0 ELSE 1 END,
        rl.locked_at DESC, rl.id DESC",
    $params
);

$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

$counts = ['active' => 0, 'history' => 0, 'all' => 0];
try {
    $row = $db->fetchOne(
        "SELECT
            SUM(CASE WHEN unlocked_at IS NULL AND deleted_at IS NULL THEN 1 ELSE 0 END) AS c_active,
            SUM(CASE WHEN unlocked_at IS NOT NULL OR deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS c_history,
            COUNT(*) AS c_all
         FROM result_locks
         WHERE tenant_id = ?",
        [$tenantId]
    );
    $counts['active']  = (int)($row['c_active']  ?? 0);
    $counts['history'] = (int)($row['c_history'] ?? 0);
    $counts['all']     = (int)($row['c_all']     ?? 0);
} catch (Exception $e) {
    // table may not exist yet; counts remain zero
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
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        .filters-bar .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 200px;
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
            max-width: 1200px;
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

        .lock-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .lock-table thead th {
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

        .lock-table tbody td {
            padding: 10px 14px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .lock-table tbody tr:last-child td {
            border-bottom: none;
        }

        .lock-table tbody tr:hover {
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

            .lock-table {
                font-size: 12px;
            }

            .lock-table thead th {
                padding: 8px 10px;
                font-size: 10px;
            }

            .lock-table tbody td {
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
                        <h1><i class="fas fa-lock me-2"></i>Result Locks</h1>
                        <p>Bulk review and management of result scope locks</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/academic/results.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Results
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo h($tenantName); ?></div>
                            <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                <?php echo h((string)$counts['active']); ?> active ·
                                <?php echo h((string)$counts['history']); ?> released ·
                                <?php echo h((string)$counts['all']); ?> total
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>Admin view
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

                <form method="GET" action="/platform/tenant/academic/result-locks.php" class="filters-bar">
                    <div class="filter-group">
                        <label><i class="fas fa-filter me-1"></i>Status</label>
                        <select name="status" class="form-select" onchange="this.form.submit()">
                            <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active only</option>
                            <option value="history" <?php echo $filterStatus === 'history' ? 'selected' : ''; ?>>Released / deleted</option>
                            <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All</option>
                        </select>
                    </div>
                </form>

                <div class="year-section">
                    <div class="year-header">
                        <div class="year-title">
                            <i class="fas fa-list" style="font-size:20px;color:#4facfe;"></i>
                            <h5>Lock records</h5>
                            <span class="pill blue"><i class="fas fa-database"></i><?php echo h((string)count($locks)); ?> row(s)</span>
                        </div>
                    </div>

                    <?php if (empty($locks)): ?>
                        <div class="empty-state">
                            <i class="fas fa-lock-open"></i>
                            <h5>No lock records match this filter</h5>
                            <p>
                                <?php if ($filterStatus === 'active'): ?>
                                    No scopes are currently locked.
                                <?php else: ?>
                                    Nothing to show for this filter.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="lock-table">
                                <thead>
                                    <tr>
                                        <th style="width:70px;">ID</th>
                                        <th style="min-width:170px;">Offering</th>
                                        <th style="min-width:150px;">Subject</th>
                                        <th style="min-width:140px;">Term</th>
                                        <th style="min-width:170px;">Locked</th>
                                        <th style="min-width:170px;">Unlocked</th>
                                        <th style="min-width:110px;text-align:center;">Status</th>
                                        <th style="text-align:right;min-width:140px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($locks as $lock):
                                        $lid = (int)$lock['id'];
                                        [$pillClass, $pillLabel] = statusPillForLock($lock);
                                        $isActive = empty($lock['deleted_at']) && empty($lock['unlocked_at']);
                                    ?>
                                        <tr>
                                            <td style="font-family:'Courier New',monospace;font-size:12px;color:#6c757d;">
                                                #<?php echo h((string)$lid); ?>
                                            </td>
                                            <td>
                                                <div style="font-weight:600;color:#1a1a2e;">
                                                    <?php echo h($lock['class_name'] ?: '—'); ?>
                                                    <?php if (!empty($lock['class_code'])): ?>
                                                        <span class="pill blue" style="margin-left:6px;"><?php echo h($lock['class_code']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div style="font-size:11px;color:#6c757d;margin-top:2px;">
                                                    <?php echo h($lock['level_name'] ?: '—'); ?>
                                                    <?php if (!empty($lock['stream_name'])): ?>
                                                        · <?php echo h($lock['stream_name']); ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($lock['year_name'])): ?>
                                                        · <?php echo h($lock['year_name']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div style="font-weight:600;color:#1a1a2e;">
                                                    <?php echo h($lock['subject_name'] ?: ('Subject #' . (int)$lock['subject_id'])); ?>
                                                </div>
                                                <?php if (!empty($lock['subject_code'])): ?>
                                                    <div style="font-size:11px;color:#0d6efd;font-family:'Courier New',monospace;">
                                                        <?php echo h($lock['subject_code']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo h($lock['term_name'] ?: ('Term #' . (int)$lock['academic_term_id'])); ?></td>
                                            <td>
                                                <?php if (!empty($lock['locked_at'])): ?>
                                                    <div style="font-weight:500;color:#1a1a2e;">
                                                        <?php echo h(date('Y-m-d H:i', strtotime((string)$lock['locked_at']))); ?>
                                                    </div>
                                                    <div style="font-size:11px;color:#6c757d;">
                                                        by <?php echo h(fmtUserName($lock['locked_by_first'] ?? '', $lock['locked_by_last'] ?? '')); ?>
                                                    </div>
                                                    <?php if (!empty($lock['lock_reason'])): ?>
                                                        <div style="font-size:11px;color:#6c757d;margin-top:2px;font-style:italic;">
                                                            <?php echo h($lock['lock_reason']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($lock['unlocked_at'])): ?>
                                                    <div style="font-weight:500;color:#1a1a2e;">
                                                        <?php echo h(date('Y-m-d H:i', strtotime((string)$lock['unlocked_at']))); ?>
                                                    </div>
                                                    <div style="font-size:11px;color:#6c757d;">
                                                        by <?php echo h(fmtUserName($lock['unlocked_by_first'] ?? '', $lock['unlocked_by_last'] ?? '')); ?>
                                                    </div>
                                                    <?php if (!empty($lock['unlock_reason'])): ?>
                                                        <div style="font-size:11px;color:#6c757d;margin-top:2px;font-style:italic;">
                                                            <?php echo h($lock['unlock_reason']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:center;">
                                                <span class="pill <?php echo h($pillClass); ?>"><?php echo h($pillLabel); ?></span>
                                            </td>
                                            <td style="text-align:right;">
                                                <?php if ($isActive): ?>
                                                    <button type="button"
                                                        class="btn btn-outline-warning btn-sm js-unlock-btn"
                                                        data-lock-id="<?php echo h((string)$lid); ?>"
                                                        data-scope-label="<?php echo h(($lock['class_name'] ?: '—') . ' · ' . ($lock['subject_name'] ?: ('Subject #' . (int)$lock['subject_id'])) . ' · ' . ($lock['term_name'] ?: ('Term #' . (int)$lock['academic_term_id']))); ?>">
                                                        <i class="fas fa-unlock me-1"></i> Unlock
                                                    </button>
                                                <?php else: ?>
                                                    <span style="color:#adb5bd;font-size:12px;">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- UNLOCK MODAL -->
    <div class="modal fade" id="unlockModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/academic/result-locks.php" id="unlockForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="unlock">
                    <input type="hidden" name="lock_id" id="unlockLockId" value="">
                    <div class="modal-header">
                        <h5><i class="fas fa-unlock text-warning me-2"></i>Unlock this scope</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p style="font-size:13px;color:#495057;">
                            Unlocking allows score entry, import, and delete for this offering,
                            subject and term again. Provide a reason for the audit log.
                        </p>
                        <div id="unlockScopeLabel" style="font-weight:600;font-size:13px;color:#1a1a2e;margin-bottom:10px;"></div>
                        <label class="form-label" for="unlockReason">Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="unlockReason" name="reason" rows="3" maxlength="255" required
                            placeholder="e.g. Corrections approved by head of department"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-outline-warning">
                            <i class="fas fa-unlock me-1"></i> Unlock scope
                        </button>
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

        (function() {
            const unlockModalEl = document.getElementById('unlockModal');
            const unlockForm = document.getElementById('unlockForm');
            const unlockLockId = document.getElementById('unlockLockId');
            const unlockScopeLbl = document.getElementById('unlockScopeLabel');
            if (!unlockModalEl || !unlockForm) return;

            const modal = new bootstrap.Modal(unlockModalEl);

            document.querySelectorAll('.js-unlock-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const id = this.getAttribute('data-lock-id') || '';
                    const label = this.getAttribute('data-scope-label') || '';
                    if (unlockLockId) unlockLockId.value = id;
                    if (unlockScopeLbl) unlockScopeLbl.textContent = label;
                    const reasonEl = document.getElementById('unlockReason');
                    if (reasonEl) reasonEl.value = '';
                    modal.show();
                });
            });

            unlockForm.addEventListener('submit', function(e) {
                const reason = document.getElementById('unlockReason').value.trim();
                if (reason === '') {
                    e.preventDefault();
                    alert('A reason is required to unlock a scope.');
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