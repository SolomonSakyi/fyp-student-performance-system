<?php

/**
 * Academic Settings — Tenant's control panel for the Academic Module
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Academic
 * @version 1.0
 * @filepath public/platform/tenant/academic/settings.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Academic settings file of the academic-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'academic_settings'
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
 * v1.1 (S20: bootstrap + CSRF on POST handler)
 *
 * Save behaviour (Decision B — auto-archive + create, atomic):
 *   1. Begin transaction.
 *   2. Lock current active settings row for this tenant.
 *   3. Archive it: status='archived', effective_to = yesterday.
 *   4. Insert new row: version = old + 1, status='active',
 *      effective_from = today, effective_to = NULL.
 *   5. Write audit_logs entry with before/after diff.
 *   6. Commit. Any failure → rollback, no state change.
 *
 * Tenant isolation:
 *   - tenant_id is resolved from $_SESSION only.
 *   - Every query includes tenant_id in WHERE.
 *   - No query loads a row by ID alone.
 *
 * Fail-closed validation:
 *   - default_terms_per_year        1..12
 *   - default_max_students_per_class 1..200
 *   - default_promotion_threshold    0..100 or NULL
 *   - all labels are non-empty, max 50 chars
 *
 * Session S20 decisions applied:
 *   SH2A per-session CSRF token; the single POST handler carries it
 *   SH3A central Security.php helper, loaded via app/bootstrap.php
 *   SH4A hardened session started inside bootstrap
 *   SH5C h() on every echoed value
 *   SH6B tenant_id scoping and deleted_at IS NULL on every query
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
$campusId     = (int)($_SESSION['campus_id'] ?? 0);
$userId       = current_user_id();
$currentUser  = $_SESSION['user_name'] ?? 'Admin';
$userAvatar   = substr($currentUser, 0, 1);
$isSuperAdmin = is_super_admin();

$pageTitle   = 'Academic Settings - Student 360 Platform';
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

/**
 * Fetch the currently active academic_settings row for a tenant.
 * Returns null if none exists.
 */
function loadActiveSettings($db, int $tenantId): ?array
{
    $row = $db->fetchOne(
        "SELECT * FROM academic_settings
         WHERE tenant_id = ? AND status = 'active' AND deleted_at IS NULL
         ORDER BY version DESC, id DESC
         LIMIT 1",
        [$tenantId]
    );
    return $row ?: null;
}

/**
 * Fetch full version history for a tenant (all statuses, newest first).
 */
function loadVersionHistory($db, int $tenantId): array
{
    return $db->fetchAll(
        "SELECT id, version, status, effective_from, effective_to,
                created_at, updated_at
         FROM academic_settings
         WHERE tenant_id = ? AND deleted_at IS NULL
         ORDER BY version DESC, id DESC",
        [$tenantId]
    );
}

/**
 * Write an audit_logs entry, tenant-scoped.
 */
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

// ============================================
// HANDLE POST — save new version (Decision B)
// ============================================
$errors   = [];
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf(); // S20 SH2A — the single POST handler carries the session CSRF token

    $formData = $_POST;

    // ---- 1. Validate ----
    $labels = [
        'label_academic_structure' => 'Academic Structure Label',
        'label_level'              => 'Level Label',
        'label_class'              => 'Class Label',
        'label_stream'             => 'Stream Label',
        'label_term'               => 'Term Label',
    ];
    foreach ($labels as $field => $human) {
        $v = trim((string)($_POST[$field] ?? ''));
        if ($v === '') {
            $errors[] = $human . ' is required.';
        } elseif (mb_strlen($v) > 50) {
            $errors[] = $human . ' must be 50 characters or less.';
        }
    }

    $termSystem = (string)($_POST['default_term_system'] ?? 'Term');
    if (!in_array($termSystem, ['Term', 'Semester', 'Quarter'], true)) {
        $errors[] = 'Default term system is invalid.';
    }

    $termsPerYear = (int)($_POST['default_terms_per_year'] ?? 3);
    if ($termsPerYear < 1 || $termsPerYear > 12) {
        $errors[] = 'Terms per year must be between 1 and 12.';
    }

    $maxStudents = (int)($_POST['default_max_students_per_class'] ?? 40);
    if ($maxStudents < 1 || $maxStudents > 200) {
        $errors[] = 'Max students per class must be between 1 and 200.';
    }

    $thresholdRaw = trim((string)($_POST['default_promotion_threshold'] ?? ''));
    $threshold = null;
    if ($thresholdRaw !== '') {
        if (!is_numeric($thresholdRaw)) {
            $errors[] = 'Promotion threshold must be a number between 0 and 100.';
        } else {
            $threshold = (float)$thresholdRaw;
            if ($threshold < 0 || $threshold > 100) {
                $errors[] = 'Promotion threshold must be between 0 and 100.';
            }
        }
    }

    $notes = trim((string)($_POST['notes'] ?? ''));
    if (mb_strlen($notes) > 2000) {
        $errors[] = 'Notes must be 2000 characters or less.';
    }

    // ---- 2. If valid, save as new version (atomic) ----
    if (empty($errors)) {
        $db->beginTransaction();
        try {
            // Lock the current active row (if any) for this tenant
            $current = $db->fetchOne(
                "SELECT * FROM academic_settings
                 WHERE tenant_id = ? AND status = 'active' AND deleted_at IS NULL
                 ORDER BY version DESC, id DESC
                 LIMIT 1
                 FOR UPDATE",
                [$tenantId]
            );

            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $today     = date('Y-m-d');

            $newVersion = 1;
            $oldSnapshot = null;

            if ($current) {
                $newVersion = ((int)$current['version']) + 1;
                $oldSnapshot = $current;

                // Archive current — preserves full historical row
                $db->execute(
                    "UPDATE academic_settings
                        SET status = 'archived',
                            effective_to = ?,
                            updated_at = NOW()
                      WHERE id = ? AND tenant_id = ?",
                    [$yesterday, (int)$current['id'], $tenantId]
                );
            }

            // Insert new active row
            $newRow = [
                'uuid'                              => uuidv4(),
                'tenant_id'                         => $tenantId,
                'version'                           => $newVersion,
                'status'                            => 'active',
                'effective_from'                    => $today,
                'effective_to'                      => null,
                'label_academic_structure'          => trim((string)$_POST['label_academic_structure']),
                'label_level'                       => trim((string)$_POST['label_level']),
                'label_class'                       => trim((string)$_POST['label_class']),
                'label_stream'                      => trim((string)$_POST['label_stream']),
                'label_term'                        => trim((string)$_POST['label_term']),
                'enable_streams'                    => !empty($_POST['enable_streams']) ? 1 : 0,
                'enable_programs'                   => !empty($_POST['enable_programs']) ? 1 : 0,
                'enable_multiple_grading_scales'    => !empty($_POST['enable_multiple_grading_scales']) ? 1 : 0,
                'default_term_system'               => $termSystem,
                'default_terms_per_year'            => $termsPerYear,
                'default_max_students_per_class'    => $maxStudents,
                'default_promotion_threshold'       => $threshold,
                'default_require_manual_approval'   => !empty($_POST['default_require_manual_approval']) ? 1 : 0,
                'notes'                             => $notes !== '' ? $notes : null,
                'created_by'                        => $userId ?: null,
                'created_at'                        => date('Y-m-d H:i:s'),
                'updated_at'                        => date('Y-m-d H:i:s'),
            ];

            $fields = array_keys($newRow);
            $placeholders = array_fill(0, count($fields), '?');
            $newId = $db->insert(
                "INSERT INTO academic_settings (`" . implode('`,`', $fields) . "`) VALUES (" . implode(',', $placeholders) . ")",
                array_values($newRow)
            );

            // Audit entry
            $diff = [
                'previous_version' => $oldSnapshot ? (int)$oldSnapshot['version'] : null,
                'new_version'      => $newVersion,
                'changed_fields'   => [],
            ];
            $watched = [
                'label_academic_structure',
                'label_level',
                'label_class',
                'label_stream',
                'label_term',
                'enable_streams',
                'enable_programs',
                'enable_multiple_grading_scales',
                'default_term_system',
                'default_terms_per_year',
                'default_max_students_per_class',
                'default_promotion_threshold',
                'default_require_manual_approval',
                'notes',
            ];
            foreach ($watched as $f) {
                $oldV = $oldSnapshot[$f] ?? null;
                $newV = $newRow[$f] ?? null;
                if ((string)$oldV !== (string)$newV) {
                    $diff['changed_fields'][$f] = ['from' => $oldV, 'to' => $newV];
                }
            }

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.settings.version.created',
                'academic_settings',
                (int)$newId,
                $diff
            );

            $db->commit();

            $_SESSION['success'] = 'Academic settings saved. Version ' . $newVersion . ' is now active.';
            header('Location: /platform/tenant/academic/settings.php');
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Academic settings save error: ' . $e->getMessage());
            $errors[] = 'Failed to save academic settings. Please try again.';
            $_SESSION['errors']    = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: /platform/tenant/academic/settings.php');
            exit;
        }
    } else {
        // Validation failed — no DB changes
        $_SESSION['errors']    = $errors;
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/academic/settings.php');
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
// LOAD CURRENT STATE
// ============================================
$current = loadActiveSettings($db, $tenantId);

// If the tenant has never had settings (shouldn't happen because of the seed),
// fall back to a synthetic default so the form still renders.
if (!$current) {
    $current = [
        'id' => null,
        'version' => 0,
        'status' => 'none',
        'effective_from' => null,
        'effective_to' => null,
        'label_academic_structure' => 'Academic Year',
        'label_level' => 'Level',
        'label_class' => 'Class',
        'label_stream' => 'Stream',
        'label_term' => 'Term',
        'enable_streams' => 1,
        'enable_programs' => 0,
        'enable_multiple_grading_scales' => 1,
        'default_term_system' => 'Term',
        'default_terms_per_year' => 3,
        'default_max_students_per_class' => 40,
        'default_promotion_threshold' => null,
        'default_require_manual_approval' => 0,
        'notes' => null,
        'updated_at' => null,
    ];
}

$history = loadVersionHistory($db, $tenantId);

// Form values: prefer flash form_data, else current active row, else synthetic default
function v(string $key, $default = ''): string
{
    global $formData, $useForm, $current;
    if ($useForm && array_key_exists($key, $formData)) {
        return h($formData[$key]);
    }
    if (array_key_exists($key, $current)) {
        return h($current[$key]);
    }
    return h($default);
}
function chk(string $key, $defaultIfUnset = 0): bool
{
    global $formData, $useForm, $current;
    if ($useForm) {
        return !empty($formData[$key]);
    }
    return ((int)($current[$key] ?? $defaultIfUnset)) === 1;
}

// Current terminology — used in page header / breadcrumbs
$labelAcademic = $current['label_academic_structure'] ?: 'Academic Year';
$labelLevel    = $current['label_level'] ?: 'Level';
$labelClass    = $current['label_class'] ?: 'Class';
$labelStream   = $current['label_stream'] ?: 'Stream';
$labelTerm     = $current['label_term'] ?: 'Term';

// Tenant name
$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
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

        /* Sidebar */
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

        /* Main */
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

        /* Cards */
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

        .section-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            margin-right: 10px;
            flex-shrink: 0;
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

        .version-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #d4edda;
            color: #155724;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 8px;
        }

        .version-badge.draft {
            background: #fff3cd;
            color: #856404;
        }

        .version-badge.archived {
            background: #e9ecef;
            color: #495057;
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

        .validation-summary-pro {
            background: #fff5f5;
            border: 1px solid #fecaca;
            border-radius: 14px;
            padding: 18px 22px;
            margin-bottom: 24px;
            max-width: 1100px;
            margin-left: auto;
            margin-right: auto;
        }

        .validation-summary-pro .vs-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }

        .validation-summary-pro .vs-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(239, 68, 68, 0.12);
            color: #dc2626;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .validation-summary-pro .vs-title {
            font-weight: 700;
            color: #991b1b;
            font-size: 14px;
        }

        .validation-summary-pro ul {
            margin: 0;
            padding-left: 20px;
            color: #7f1d1d;
            font-size: 13px;
            line-height: 1.8;
        }

        .switch-row {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 14px 0;
            border-bottom: 1px dashed #e9ecef;
        }

        .switch-row:last-child {
            border-bottom: none;
        }

        .switch-row .form-check {
            padding-top: 2px;
        }

        .switch-row .form-check-input {
            width: 22px;
            height: 22px;
            margin: 0;
            cursor: pointer;
        }

        .switch-row .switch-body {
            flex: 1;
            min-width: 0;
        }

        .switch-row .switch-title {
            font-weight: 600;
            font-size: 13px;
            color: #1a1a2e;
            margin-bottom: 2px;
        }

        .switch-row .switch-desc {
            font-size: 12px;
            color: #6c757d;
        }

        /* Version history table */
        .history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .history-table thead th {
            background: #f8f9fa;
            padding: 10px 14px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            color: #6c757d;
            text-align: left;
            border-bottom: 2px solid #e9ecef;
            white-space: nowrap;
        }

        .history-table tbody td {
            padding: 10px 14px;
            border-bottom: 1px solid #f0f2f5;
            vertical-align: middle;
        }

        .history-table tbody tr:last-child td {
            border-bottom: none;
        }

        .history-table tbody tr:hover {
            background: #fafbfc;
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
                        <h1><i class="fas fa-sliders-h me-2"></i>Academic Settings</h1>
                        <p>Configure terminology, structure and defaults for your institution's academic module</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/dashboard.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo h($tenantName); ?></div>
                            <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                Active Version:
                                <strong><?php echo (int)$current['version'] ?: '—'; ?></strong>
                                <?php if ($current['effective_from']): ?>
                                    · Effective from <?php echo h(date('M d, Y', strtotime($current['effective_from']))); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div>
                        <span class="version-badge <?php echo $current['status'] === 'active' ? '' : ($current['status'] === 'draft' ? 'draft' : 'archived'); ?>">
                            <i class="fas fa-circle" style="font-size:7px;"></i>
                            <?php echo h(ucfirst($current['status'])); ?>
                        </span>
                    </div>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="validation-summary-pro" id="errorSummary">
                        <div class="vs-header">
                            <div class="vs-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <div class="vs-title">Please correct the following:</div>
                        </div>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo h($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($successMessage): ?>
                    <div class="alert-pro alert-pro-success" id="serverSuccessBox" style="max-width:1100px;margin-left:auto;margin-right:auto;">
                        <div class="alert-pro-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="alert-pro-content">
                            <div class="alert-pro-title">Success!</div>
                            <div style="font-size:13px;"><?php echo h($successMessage); ?></div>
                        </div>
                        <button type="button" class="alert-pro-close" onclick="document.getElementById('serverSuccessBox').remove()" aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>

                <form id="settingsForm" method="POST" action="/platform/tenant/academic/settings.php" novalidate>
                    <?= csrf_field() ?>

                    <!-- ===================================================
                         SECTION 1 — TERMINOLOGY
                    ==================================================== -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><span class="section-number">1</span>Terminology</h6>
                            <small class="text-muted">The labels used throughout your academic module</small>
                        </div>
                        <div class="card-body-custom">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="label_academic_structure">
                                        Academic Structure Label <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="label_academic_structure"
                                        name="label_academic_structure" maxlength="50"
                                        value="<?php echo v('label_academic_structure', 'Academic Year'); ?>" required>
                                    <div class="form-text">For example: <em>Academic Year</em>, <em>Session</em>, <em>Session Year</em>.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="label_term">
                                        Term Label <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="label_term"
                                        name="label_term" maxlength="50"
                                        value="<?php echo v('label_term', 'Term'); ?>" required>
                                    <div class="form-text">For example: <em>Term</em>, <em>Semester</em>, <em>Quarter</em>.</div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="label_level">
                                        Level Label <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="label_level"
                                        name="label_level" maxlength="50"
                                        value="<?php echo v('label_level', 'Level'); ?>" required>
                                    <div class="form-text">For example: <em>Level</em>, <em>Grade</em>, <em>Year</em>.</div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="label_class">
                                        Class Label <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="label_class"
                                        name="label_class" maxlength="50"
                                        value="<?php echo v('label_class', 'Class'); ?>" required>
                                    <div class="form-text">For example: <em>Class</em>, <em>Form</em>, <em>Room</em>.</div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="label_stream">
                                        Stream Label <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="label_stream"
                                        name="label_stream" maxlength="50"
                                        value="<?php echo v('label_stream', 'Stream'); ?>" required>
                                    <div class="form-text">For example: <em>Stream</em>, <em>Section</em>, <em>House</em>.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===================================================
                         SECTION 2 — FEATURE TOGGLES
                    ==================================================== -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><span class="section-number">2</span>Features</h6>
                            <small class="text-muted">Enable or disable optional structures</small>
                        </div>
                        <div class="card-body-custom">

                            <div class="switch-row">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="enable_streams"
                                        name="enable_streams" value="1" <?php echo chk('enable_streams') ? 'checked' : ''; ?>>
                                </div>
                                <div class="switch-body">
                                    <label for="enable_streams" class="switch-title">
                                        <?php echo h($labelStream); ?>s
                                    </label>
                                    <div class="switch-desc">
                                        Enable distinct <?php echo h(strtolower($labelStream)); ?>s (e.g. A, B, C) within each <?php echo h(strtolower($labelClass)); ?>.
                                        When disabled, all <?php echo h(strtolower($labelClass)); ?>es are treated as single units.
                                    </div>
                                </div>
                            </div>

                            <div class="switch-row">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="enable_programs"
                                        name="enable_programs" value="1" <?php echo chk('enable_programs') ? 'checked' : ''; ?>>
                                </div>
                                <div class="switch-body">
                                    <label for="enable_programs" class="switch-title">Programs</label>
                                    <div class="switch-desc">
                                        Enable a top-level program grouping above <?php echo h(strtolower($labelLevel)); ?>s.
                                        Useful for schools with multiple parallel programs (e.g. STEM, Arts, Vocational).
                                    </div>
                                </div>
                            </div>

                            <div class="switch-row">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="enable_multiple_grading_scales"
                                        name="enable_multiple_grading_scales" value="1"
                                        <?php echo chk('enable_multiple_grading_scales', 1) ? 'checked' : ''; ?>>
                                </div>
                                <div class="switch-body">
                                    <label for="enable_multiple_grading_scales" class="switch-title">Multiple Grading Scales</label>
                                    <div class="switch-desc">
                                        Allow different grading scales for different <?php echo h(strtolower($labelLevel)); ?>s
                                        (for example, Primary uses A/B/C while Junior High uses 1/2/3).
                                        When disabled, only one grading scale is allowed for the whole tenant.
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- ===================================================
                         SECTION 3 — OPERATIONAL DEFAULTS
                    ==================================================== -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><span class="section-number">3</span>Operational Defaults</h6>
                            <small class="text-muted">Applied when creating new academic records</small>
                        </div>
                        <div class="card-body-custom">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="default_term_system">
                                        Term System <span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="default_term_system" name="default_term_system" required>
                                        <option value="Term" <?php echo v('default_term_system') === 'Term'     ? 'selected' : ''; ?>>Term</option>
                                        <option value="Semester" <?php echo v('default_term_system') === 'Semester' ? 'selected' : ''; ?>>Semester</option>
                                        <option value="Quarter" <?php echo v('default_term_system') === 'Quarter'  ? 'selected' : ''; ?>>Quarter</option>
                                    </select>
                                    <div class="form-text">The default academic period structure for new academic years.</div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="default_terms_per_year">
                                        Default Terms per Year <span class="required">*</span>
                                    </label>
                                    <input type="number" class="form-control" id="default_terms_per_year"
                                        name="default_terms_per_year" min="1" max="12"
                                        value="<?php echo v('default_terms_per_year', '3'); ?>" required>
                                    <div class="form-text">Between 1 and 12.</div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="default_max_students_per_class">
                                        Max Students per <?php echo h($labelClass); ?> <span class="required">*</span>
                                    </label>
                                    <input type="number" class="form-control" id="default_max_students_per_class"
                                        name="default_max_students_per_class" min="1" max="200"
                                        value="<?php echo v('default_max_students_per_class', '40'); ?>" required>
                                    <div class="form-text">Between 1 and 200. Used as a soft limit for enrolment.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===================================================
                         SECTION 4 — PROMOTION DEFAULTS
                    ==================================================== -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><span class="section-number">4</span>Promotion Defaults</h6>
                            <small class="text-muted">Defaults for end-of-year promotion rules</small>
                        </div>
                        <div class="card-body-custom">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="default_promotion_threshold">
                                        Default Promotion Threshold (%)
                                    </label>
                                    <input type="number" class="form-control" id="default_promotion_threshold"
                                        name="default_promotion_threshold" step="0.01" min="0" max="100"
                                        value="<?php echo v('default_promotion_threshold'); ?>"
                                        placeholder="e.g. 50">
                                    <div class="form-text">Leave blank if the tenant has no default threshold. Between 0 and 100.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="form-check mt-4">
                                        <input class="form-check-input" type="checkbox"
                                            id="default_require_manual_approval"
                                            name="default_require_manual_approval" value="1"
                                            <?php echo chk('default_require_manual_approval') ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="default_require_manual_approval">
                                            Require manual approval for promotions by default
                                        </label>
                                    </div>
                                    <div class="form-text">
                                        When enabled, promotion recommendations must be reviewed and approved
                                        before students are moved to the next <?php echo h(strtolower($labelLevel)); ?>.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===================================================
                         SECTION 5 — NOTES
                    ==================================================== -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><span class="section-number">5</span>Notes</h6>
                            <small class="text-muted">Optional internal notes about this configuration</small>
                        </div>
                        <div class="card-body-custom">
                            <textarea class="form-control" id="notes" name="notes" rows="3"
                                placeholder="For example: 'Streams enabled at the start of the 2026/2027 academic year.'"><?php echo v('notes'); ?></textarea>
                        </div>
                    </div>

                    <!-- ===================================================
                         SECTION 6 — SAVE
                    ==================================================== -->
                    <div class="card-custom">
                        <div class="card-body-custom">
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <i class="fas fa-save me-2"></i> Save as New Version
                                </button>
                                <a href="/platform/tenant/academic/settings.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i> Reset
                                </a>
                                <div style="flex:1;min-width:200px;font-size:12px;color:#6c757d;">
                                    Saving will archive the current version and create version
                                    <strong><?php echo ((int)$current['version']) + 1; ?></strong> as active.
                                    Previous versions remain in history below.
                                </div>
                            </div>
                        </div>
                    </div>

                </form>

                <!-- ===================================================
                     SECTION 7 — VERSION HISTORY
                ==================================================== -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><span class="section-number">7</span>Version History</h6>
                        <small class="text-muted"><?php echo count($history); ?> version(s) on file</small>
                    </div>
                    <div class="card-body-custom" style="padding: 0;">
                        <?php if (empty($history)): ?>
                            <div style="padding: 24px;">
                                <div style="padding:20px;text-align:center;color:#adb5bd;font-size:13px;background:#f8f9fa;border-radius:10px;border:1px dashed #dee2e6;">
                                    <i class="fas fa-folder-open me-1"></i> No versions recorded yet.
                                </div>
                            </div>
                        <?php else: ?>
                            <table class="history-table">
                                <thead>
                                    <tr>
                                        <th>Version</th>
                                        <th>Status</th>
                                        <th>Effective From</th>
                                        <th>Effective To</th>
                                        <th>Created</th>
                                        <th>Last Updated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($history as $ver): ?>
                                        <tr>
                                            <td>
                                                <strong>v<?php echo (int)$ver['version']; ?></strong>
                                                <?php if ($ver['status'] === 'active'): ?>
                                                    <span class="version-badge" style="margin-left:6px;"><i class="fas fa-circle" style="font-size:6px;"></i> Current</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="version-badge <?php echo $ver['status'] === 'active' ? '' : ($ver['status'] === 'draft' ? 'draft' : 'archived'); ?>">
                                                    <?php echo h(ucfirst($ver['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo $ver['effective_from']
                                                    ? h(date('M d, Y', strtotime($ver['effective_from'])))
                                                    : '<span class="text-muted">—</span>'; ?>
                                            </td>
                                            <td>
                                                <?php echo $ver['effective_to']
                                                    ? h(date('M d, Y', strtotime($ver['effective_to'])))
                                                    : '<span class="text-muted">—</span>'; ?>
                                            </td>
                                            <td>
                                                <?php echo $ver['created_at']
                                                    ? h(date('M d, Y H:i', strtotime($ver['created_at'])))
                                                    : '<span class="text-muted">—</span>'; ?>
                                            </td>
                                            <td>
                                                <?php echo $ver['updated_at']
                                                    ? h(date('M d, Y H:i', strtotime($ver['updated_at'])))
                                                    : '<span class="text-muted">—</span>'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

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

        document.getElementById('settingsForm').addEventListener('submit', function(e) {
            let valid = true;
            let firstError = null;

            document.querySelectorAll('.field-error').forEach(el => el.classList.remove('field-error'));

            this.querySelectorAll('[required]').forEach(field => {
                if (field.disabled) return;
                if (!field.value || (field.tagName === 'SELECT' && field.value === '')) {
                    field.classList.add('field-error');
                    valid = false;
                    if (!firstError) firstError = field;
                }
            });

            if (!valid) {
                e.preventDefault();
                if (firstError) {
                    firstError.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                    setTimeout(() => firstError.focus({
                        preventScroll: true
                    }), 400);
                }
                return false;
            }

            const btn = document.getElementById('submitBtn');
            if (btn && !btn.disabled) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Saving...';
            }
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