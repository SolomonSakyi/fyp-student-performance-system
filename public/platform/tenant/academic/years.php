<?php

/**
 * Academic Years — CRUD for a tenant's academic years
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Academic
 * @version 1.0
 * @filepath public/platform/tenant/academic/years.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Academic years file of the academic-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'academic_years'
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
 * Decisions locked in (Session S4):
 *   1A  Unique (tenant_id, year_name)
 *   2B  Hard block on date overlap per tenant
 *   3A  Set as Current does NOT cascade to terms
 *   4A  Delete refuses if terms exist; error lists the terms
 *   5B  year_name is locked when the year is is_current = 1
 *
 * Tenant isolation enforced on every query.
 * All writes transactional. All changes audited.
 *
 * Session S20 decisions applied:
 *   SH2A per-session CSRF token; all POSTs carry it
 *   SH3A central Security.php helper, loaded via app/bootstrap.php
 *   SH4A hardened session started inside bootstrap
 *   SH5C h() on every echoed value
 *   SH6B tenant_id scoping and deleted_at IS NULL on every query
 *   SH10A generic error messages on the catch; real error logged
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

$pageTitle   = 'Academic Years - Student 360 Platform';
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

/**
 * Load the tenant's active academic settings (for labels + defaults).
 */
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
 * Check whether any non-deleted year for this tenant overlaps the given date
 * range. $excludeId excludes a specific year (for edit).
 * Returns the overlapping row or null.
 */
function findOverlappingYear($db, int $tenantId, string $start, string $end, ?int $excludeId = null): ?array
{
    $sql = "SELECT id, year_name, start_date, end_date
            FROM academic_years
            WHERE tenant_id = ? AND deleted_at IS NULL
              AND (start_date <= ? AND end_date >= ?)";
    $params = [$tenantId, $end, $start];

    if ($excludeId !== null) {
        $sql .= " AND id != ?";
        $params[] = $excludeId;
    }
    $sql .= " LIMIT 1";

    $row = $db->fetchOne($sql, $params);
    return $row ?: null;
}

/**
 * Count non-deleted terms bound to a year.
 */
function countTermsForYear($db, int $tenantId, int $yearId): int
{
    try {
        $row = $db->fetchOne(
            "SELECT COUNT(*) AS c
             FROM academic_terms
             WHERE tenant_id = ? AND academic_year_id = ? AND deleted_at IS NULL",
            [$tenantId, $yearId]
        );
        return (int)($row['c'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Fetch list of terms for a year (for the delete-refusal error message).
 */
function listTermsForYear($db, int $tenantId, int $yearId): array
{
    try {
        return $db->fetchAll(
            "SELECT id, term_name
             FROM academic_terms
             WHERE tenant_id = ? AND academic_year_id = ? AND deleted_at IS NULL
             ORDER BY sort_order ASC, id ASC",
            [$tenantId, $yearId]
        );
    } catch (Exception $e) {
        return [];
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
            $name  = trim((string)($_POST['year_name'] ?? ''));
            $start = trim((string)($_POST['start_date'] ?? ''));
            $end   = trim((string)($_POST['end_date'] ?? ''));
            $active  = !empty($_POST['is_active']) ? 1 : 0;
            $current = !empty($_POST['is_current']) ? 1 : 0;

            if ($name === '')         $errors[] = 'Year name is required.';
            if (mb_strlen($name) > 50) $errors[] = 'Year name must be 50 characters or less.';
            if ($start === '')        $errors[] = 'Start date is required.';
            if ($end === '')          $errors[] = 'End date is required.';
            if ($start !== '' && $end !== '' && strtotime($end) <= strtotime($start)) {
                $errors[] = 'End date must be after start date.';
            }

            if (empty($errors)) {
                // Duplicate name check (unique index is authoritative, but this
                // gives a friendly error message instead of a SQL exception)
                $dupe = $db->fetchOne(
                    "SELECT id FROM academic_years
                     WHERE tenant_id = ? AND year_name = ? AND deleted_at IS NULL",
                    [$tenantId, $name]
                );
                if ($dupe) $errors[] = 'A year named "' . $name . '" already exists.';

                // Overlap check
                $overlap = findOverlappingYear($db, $tenantId, $start, $end);
                if ($overlap) {
                    $errors[] = 'Date range overlaps with existing year "' . $overlap['year_name'] .
                        '" (' . $overlap['start_date'] . ' to ' . $overlap['end_date'] . ').';
                }
            }

            if (empty($errors)) {
                $db->beginTransaction();

                if ($current === 1) {
                    // Clear is_current on all other years for this tenant
                    $db->execute(
                        "UPDATE academic_years
                            SET is_current = 0, updated_at = NOW()
                          WHERE tenant_id = ? AND is_current = 1 AND deleted_at IS NULL",
                        [$tenantId]
                    );
                }

                $newId = $db->insert(
                    "INSERT INTO academic_years
                        (tenant_id, school_id, year_name, start_date, end_date,
                         is_active, is_current, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        $tenantId,
                        $schoolId ?: null,
                        $name,
                        $start,
                        $end,
                        $active,
                        $current,
                    ]
                );

                writeAudit(
                    $db,
                    $tenantId,
                    $userId,
                    'academic.year.created',
                    'academic_years',
                    (int)$newId,
                    [
                        'year_name' => $name,
                        'start_date' => $start,
                        'end_date' => $end,
                        'is_active' => $active,
                        'is_current' => $current
                    ]
                );

                $db->commit();
                $_SESSION['success'] = 'Academic year "' . $name . '" created.';
                header('Location: /platform/tenant/academic/years.php');
                exit;
            }

            $_SESSION['errors']    = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: /platform/tenant/academic/years.php?action=new');
            exit;
        }

        // ---------- UPDATE ----------
        if ($action === 'update') {
            $id    = (int)($_POST['id'] ?? 0);
            $start = trim((string)($_POST['start_date'] ?? ''));
            $end   = trim((string)($_POST['end_date'] ?? ''));
            $active  = !empty($_POST['is_active']) ? 1 : 0;
            $current = !empty($_POST['is_current']) ? 1 : 0;

            if ($id <= 0) $errors[] = 'Invalid year ID.';

            $year = $db->fetchOne(
                "SELECT * FROM academic_years
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$year) $errors[] = 'Year not found.';

            if ($start === '') $errors[] = 'Start date is required.';
            if ($end === '')   $errors[] = 'End date is required.';
            if ($start !== '' && $end !== '' && strtotime($end) <= strtotime($start)) {
                $errors[] = 'End date must be after start date.';
            }

            // Year name locked when is_current = 1 (Decision 5B)
            // We simply ignore any submitted year_name and keep the existing one.

            if (empty($errors) && $year) {
                $overlap = findOverlappingYear($db, $tenantId, $start, $end, $id);
                if ($overlap) {
                    $errors[] = 'Date range overlaps with existing year "' . $overlap['year_name'] .
                        '" (' . $overlap['start_date'] . ' to ' . $overlap['end_date'] . ').';
                }
            }

            if (empty($errors) && $year) {
                $db->beginTransaction();

                if ($current === 1 && (int)$year['is_current'] !== 1) {
                    $db->execute(
                        "UPDATE academic_years
                            SET is_current = 0, updated_at = NOW()
                          WHERE tenant_id = ? AND is_current = 1 AND deleted_at IS NULL
                            AND id != ?",
                        [$tenantId, $id]
                    );
                }

                // Keep year_name from the existing row (locked when current)
                $db->execute(
                    "UPDATE academic_years
                        SET year_name = ?,
                            start_date = ?,
                            end_date = ?,
                            is_active = ?,
                            is_current = ?,
                            updated_at = NOW()
                      WHERE id = ? AND tenant_id = ?",
                    [
                        $year['year_name'],           // unchanged (locked)
                        $start,
                        $end,
                        $active,
                        $current,
                        $id,
                        $tenantId,
                    ]
                );

                writeAudit(
                    $db,
                    $tenantId,
                    $userId,
                    'academic.year.updated',
                    'academic_years',
                    $id,
                    [
                        'start_date' => $start,
                        'end_date' => $end,
                        'is_active' => $active,
                        'is_current' => $current
                    ]
                );

                $db->commit();
                $_SESSION['success'] = 'Academic year updated.';
                header('Location: /platform/tenant/academic/years.php');
                exit;
            }

            $_SESSION['errors']    = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: /platform/tenant/academic/years.php?action=edit&id=' . $id);
            exit;
        }

        // ---------- SET AS CURRENT ----------
        if ($action === 'set_current') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid year ID.');

            $year = $db->fetchOne(
                "SELECT * FROM academic_years
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$year) throw new Exception('Year not found.');

            $db->beginTransaction();

            $db->execute(
                "UPDATE academic_years
                    SET is_current = 0, updated_at = NOW()
                  WHERE tenant_id = ? AND is_current = 1 AND deleted_at IS NULL",
                [$tenantId]
            );
            $db->execute(
                "UPDATE academic_years
                    SET is_current = 1, is_active = 1, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.year.set_current',
                'academic_years',
                $id,
                ['year_name' => $year['year_name']]
            );

            $db->commit();
            $_SESSION['success'] = '"' . $year['year_name'] . '" is now the current academic year.';
            header('Location: /platform/tenant/academic/years.php');
            exit;
        }

        // ---------- TOGGLE ACTIVE ----------
        if ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid year ID.');

            $year = $db->fetchOne(
                "SELECT * FROM academic_years
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$year) throw new Exception('Year not found.');

            $newActive = ((int)$year['is_active']) === 1 ? 0 : 1;

            $db->beginTransaction();
            $db->execute(
                "UPDATE academic_years SET is_active = ?, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$newActive, $id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.year.toggled_active',
                'academic_years',
                $id,
                ['is_active' => $newActive]
            );
            $db->commit();

            $_SESSION['success'] = '"' . $year['year_name'] . '" is now ' . ($newActive ? 'active' : 'inactive') . '.';
            header('Location: /platform/tenant/academic/years.php');
            exit;
        }

        // ---------- DELETE (soft) ----------
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid year ID.');

            $year = $db->fetchOne(
                "SELECT * FROM academic_years
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$year) throw new Exception('Year not found.');

            if ((int)$year['is_current'] === 1) {
                throw new Exception('Cannot delete the current academic year. Set another year as current first.');
            }

            $termCount = countTermsForYear($db, $tenantId, $id);
            if ($termCount > 0) {
                $terms = listTermsForYear($db, $tenantId, $id);
                $names = array_map(fn($t) => $t['term_name'], $terms);
                throw new Exception(
                    'Cannot delete "' . $year['year_name'] . '". It has ' . $termCount .
                        ' term(s) bound to it: ' . implode(', ', $names) .
                        '. Remove or reassign those terms first, or deactivate the year instead.'
                );
            }

            $db->beginTransaction();
            $db->execute(
                "UPDATE academic_years SET deleted_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.year.deleted',
                'academic_years',
                $id,
                ['year_name' => $year['year_name']]
            );
            $db->commit();

            $_SESSION['success'] = 'Academic year "' . $year['year_name'] . '" deleted.';
            header('Location: /platform/tenant/academic/years.php');
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Academic years action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/academic/years.php');
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
$settings = loadActiveSettings($db, $tenantId);
$labelAcademic = $settings['label_academic_structure'] ?? 'Academic Year';
$labelTerm     = $settings['label_term'] ?? 'Term';

// Years list, with term counts
$years = $db->fetchAll(
    "SELECT ay.*,
            (SELECT COUNT(*) FROM academic_terms t
             WHERE t.tenant_id = ay.tenant_id
               AND t.academic_year_id = ay.id
               AND t.deleted_at IS NULL) AS term_count
     FROM academic_years ay
     WHERE ay.tenant_id = ? AND ay.deleted_at IS NULL
     ORDER BY ay.start_date DESC, ay.id DESC",
    [$tenantId]
);

// For the edit modal (if ?action=edit&id=)
$editYear = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && !empty($_GET['id'])) {
    $editYear = $db->fetchOne(
        "SELECT * FROM academic_years
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [(int)$_GET['id'], $tenantId]
    );
}

$showNewModal  = (isset($_GET['action']) && $_GET['action'] === 'new') || ($useForm && (($formData['action'] ?? '') === 'create'));
$showEditModal = $editYear !== null || ($useForm && (($formData['action'] ?? '') === 'update'));

// Tenant name
$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

// Value helper for forms
function v(string $key, $default = ''): string
{
    global $formData, $useForm, $editYear;
    if ($useForm && array_key_exists($key, $formData)) {
        return h($formData[$key]);
    }
    if ($editYear && array_key_exists($key, $editYear)) {
        return h($editYear[$key]);
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

        .btn-outline-success {
            background: transparent;
            border: 2px solid #28a745;
            color: #28a745;
            border-radius: 10px;
            padding: 6px 14px;
            font-weight: 500;
            font-size: 12px;
        }

        .btn-outline-success:hover {
            background: #28a745;
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

        .year-name {
            font-weight: 700;
            font-size: 14px;
            color: #1a1a2e;
        }

        .year-dates {
            font-size: 11px;
            color: #6c757d;
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
            background: #fff3cd;
            color: #856404;
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
                        <h1><i class="fas fa-calendar-alt me-2"></i><?php echo h($labelAcademic); ?>s</h1>
                        <p>Manage the academic years for your institution</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/academic/years.php?action=new" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i> Add <?php echo h($labelAcademic); ?>
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
                                <?php echo count($years); ?> <?php echo h(strtolower($labelAcademic)); ?>(s) on file
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-book me-1"></i><?php echo h($labelTerm); ?>s bound per year
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
                                <th style="min-width:180px;"><?php echo h($labelAcademic); ?></th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th style="text-align:center;"><?php echo h($labelTerm); ?>s</th>
                                <th style="text-align:center;">Status</th>
                                <th style="text-align:right;min-width:260px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($years)): ?>
                                <tr>
                                    <td colspan="6" class="empty-state">
                                        <i class="fas fa-calendar-plus"></i>
                                        <h5>No <?php echo h($labelAcademic); ?>s yet</h5>
                                        <p>Create your first <?php echo h(strtolower($labelAcademic)); ?> to get started.</p>
                                        <a href="/platform/tenant/academic/years.php?action=new" class="btn btn-primary btn-sm mt-2">
                                            <i class="fas fa-plus me-1"></i> Add <?php echo h($labelAcademic); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($years as $y): ?>
                                    <?php
                                    $isCurrent = ((int)$y['is_current']) === 1;
                                    $isActive  = ((int)$y['is_active']) === 1;
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="year-name"><?php echo h($y['year_name']); ?></div>
                                            <?php if (!empty($y['uuid'])): ?>
                                                <div class="year-dates">ID: <?php echo (int)$y['id']; ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo h(date('M d, Y', strtotime($y['start_date']))); ?>
                                        </td>
                                        <td>
                                            <?php echo h(date('M d, Y', strtotime($y['end_date']))); ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php
                                            $tc = (int)$y['term_count'];
                                            if ($tc > 0) {
                                                echo '<span class="pill blue"><i class="fas fa-book"></i>' . h((string)$tc) . '</span>';
                                            } else {
                                                echo '<span class="pill gray"><i class="fas fa-book"></i>0</span>';
                                            }
                                            ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ($isCurrent): ?>
                                                <span class="pill green"><i class="fas fa-star"></i>Current</span>
                                            <?php elseif ($isActive): ?>
                                                <span class="pill blue"><i class="fas fa-check"></i>Active</span>
                                            <?php else: ?>
                                                <span class="pill gray"><i class="fas fa-times"></i>Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-group">
                                                <a href="/platform/tenant/academic/years.php?action=edit&id=<?php echo (int)$y['id']; ?>"
                                                    class="btn btn-outline-primary" title="Edit">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>

                                                <?php if (!$isCurrent): ?>
                                                    <form method="POST" style="display:inline-block;"
                                                        onsubmit="return confirm('Set &quot;<?php echo h(addslashes($y['year_name'])); ?>&quot; as the current academic year?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="set_current">
                                                        <input type="hidden" name="id" value="<?php echo (int)$y['id']; ?>">
                                                        <button type="submit" class="btn btn-outline-success" title="Set as Current">
                                                            <i class="fas fa-star"></i> Set Current
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <form method="POST" style="display:inline-block;"
                                                    onsubmit="return confirm('<?php echo $isActive ? 'Deactivate' : 'Activate'; ?> &quot;<?php echo h(addslashes($y['year_name'])); ?>&quot;?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="toggle_active">
                                                    <input type="hidden" name="id" value="<?php echo (int)$y['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-warning" title="<?php echo $isActive ? 'Deactivate' : 'Activate'; ?>">
                                                        <i class="fas fa-<?php echo $isActive ? 'pause' : 'play'; ?>"></i>
                                                        <?php echo $isActive ? 'Deactivate' : 'Activate'; ?>
                                                    </button>
                                                </form>

                                                <?php if (!$isCurrent): ?>
                                                    <form method="POST" style="display:inline-block;"
                                                        onsubmit="return confirm('Delete &quot;<?php echo h(addslashes($y['year_name'])); ?>&quot;? This cannot be undone.');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int)$y['id']; ?>">
                                                        <button type="submit" class="btn btn-outline-danger" title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
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

    <!-- ============================================================
         CREATE MODAL
    ============================================================ -->
    <div class="modal fade<?php echo $showNewModal && empty($editYear) ? ' show' : ''; ?>"
        id="newYearModal" tabindex="-1"
        <?php echo $showNewModal && empty($editYear) ? 'style="display:block;background:rgba(0,0,0,0.4);"' : ''; ?>>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/academic/years.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5><i class="fas fa-calendar-plus text-primary me-2"></i>Add <?php echo h($labelAcademic); ?></h5>
                        <a href="/platform/tenant/academic/years.php" class="btn-close"></a>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="new_year_name">Year Name <span class="required">*</span></label>
                            <input type="text" class="form-control" id="new_year_name" name="year_name"
                                maxlength="50" placeholder="e.g. 2026/2027" required
                                value="<?php echo ($useForm && ($formData['action'] ?? '') === 'create') ? v('year_name') : ''; ?>">
                            <div class="form-text">Must be unique within your institution.</div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="new_start_date">Start Date <span class="required">*</span></label>
                                <input type="date" class="form-control" id="new_start_date" name="start_date" required
                                    value="<?php echo ($useForm && ($formData['action'] ?? '') === 'create') ? v('start_date') : ''; ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="new_end_date">End Date <span class="required">*</span></label>
                                <input type="date" class="form-control" id="new_end_date" name="end_date" required
                                    value="<?php echo ($useForm && ($formData['action'] ?? '') === 'create') ? v('end_date') : ''; ?>">
                            </div>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="new_is_active" name="is_active" value="1" checked>
                            <label class="form-check-label" for="new_is_active">Active</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="new_is_current" name="is_current" value="1">
                            <label class="form-check-label" for="new_is_current">Set as current <?php echo h($labelAcademic); ?></label>
                            <div class="form-text">Only one year can be current at a time.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="/platform/tenant/academic/years.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ============================================================
         EDIT MODAL
    ============================================================ -->
    <?php if ($editYear): ?>
        <div class="modal fade show" id="editYearModal" tabindex="-1"
            style="display:block;background:rgba(0,0,0,0.4);">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" action="/platform/tenant/academic/years.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?php echo (int)$editYear['id']; ?>">
                        <div class="modal-header">
                            <h5><i class="fas fa-edit text-primary me-2"></i>Edit <?php echo h($labelAcademic); ?></h5>
                            <a href="/platform/tenant/academic/years.php" class="btn-close"></a>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Year Name</label>
                                <input type="text" class="form-control"
                                    value="<?php echo h($editYear['year_name']); ?>"
                                    readonly>
                                <div class="form-text">
                                    <?php if ((int)$editYear['is_current'] === 1): ?>
                                        <i class="fas fa-lock text-warning me-1"></i>
                                        Locked while this is the current year. Create a new year and set it as current to unlock.
                                    <?php else: ?>
                                        Year name cannot be changed after creation. Create a new year if the name is wrong.
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="edit_start_date">Start Date <span class="required">*</span></label>
                                    <input type="date" class="form-control" id="edit_start_date" name="start_date" required
                                        value="<?php echo h($editYear['start_date']); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="edit_end_date">End Date <span class="required">*</span></label>
                                    <input type="date" class="form-control" id="edit_end_date" name="end_date" required
                                        value="<?php echo h($editYear['end_date']); ?>">
                                </div>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active" value="1"
                                    <?php echo ((int)$editYear['is_active'] === 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="edit_is_active">Active</label>
                            </div>
                            <?php if ((int)$editYear['is_current'] !== 1): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="edit_is_current" name="is_current" value="1">
                                    <label class="form-check-label" for="edit_is_current">
                                        Set as current <?php echo h($labelAcademic); ?>
                                    </label>
                                    <div class="form-text">Only one year can be current at a time.</div>
                                </div>
                            <?php else: ?>
                                <input type="hidden" name="is_current" value="1">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" checked disabled>
                                    <label class="form-check-label text-muted">
                                        Currently active year (already set)
                                    </label>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <a href="/platform/tenant/academic/years.php" class="btn btn-outline-secondary">Cancel</a>
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

        // Toggle new modal via URL parameter
        (function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('action') === 'new') {
                const modalEl = document.getElementById('newYearModal');
                if (modalEl && !modalEl.classList.contains('show')) {
                    const modal = new bootstrap.Modal(modalEl);
                    modal.show();
                }
            }
        })();

        // Client-side validation
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

                // End > Start
                const s = this.querySelector('input[name="start_date"]');
                const en = this.querySelector('input[name="end_date"]');
                if (s && en && s.value && en.value) {
                    if (new Date(en.value) <= new Date(s.value)) {
                        en.classList.add('field-error');
                        valid = false;
                        if (!firstErr) firstErr = en;
                        alert('End date must be after start date.');
                    }
                }

                if (!valid) {
                    e.preventDefault();
                    if (firstErr) firstErr.focus();
                }
            });
        });

        document.querySelectorAll('.form-control').forEach(el => {
            el.addEventListener('input', function() {
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