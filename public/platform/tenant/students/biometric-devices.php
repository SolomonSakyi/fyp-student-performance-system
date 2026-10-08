<?php

/**
 * Biometric Devices — register and manage attendance terminals
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Students
 * @version 1.0
 * @filepath public/platform/tenant/students/biometric-devices.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Students biometric-devices file of the students-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'students_biometric_devices'
 *       to 'students' so the partial marks Students active and
 *       renders the student sub-menu on this page — consistent
 *       with every other students file.
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
 * Session S17c-2a decisions:
 *   B2  both live push and CSV accepted
 *   B6  URL credential + optional IP allowlist
 *   Design target: Hikvision ISAPI generic event push.
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

$pageTitle   = 'Biometric Devices - Student 360 Platform';
$currentPage = 'students';

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

function getDevice($db, int $tenantId, int $deviceId): ?array
{
    if ($deviceId <= 0) return null;
    return $db->fetchOne(
        "SELECT * FROM biometric_devices
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$deviceId, $tenantId]
    ) ?: null;
}

/** Base URL for the push endpoint the device will call. */
function pushEndpointBase(): string
{
    $scheme = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') $scheme = 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'admin.edutrack.local';
    return $scheme . '://' . $host . '/api/biometric/hikvision.php';
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
            $name        = trim((string)($_POST['device_name'] ?? ''));
            $model       = trim((string)($_POST['device_model'] ?? ''));
            $serial      = trim((string)($_POST['serial_number'] ?? ''));
            $ip          = trim((string)($_POST['ip_address'] ?? ''));
            $port        = (int)($_POST['http_port'] ?? 80);
            $location    = trim((string)($_POST['location'] ?? ''));
            $direction   = trim((string)($_POST['direction'] ?? 'in'));
            $notes       = trim((string)($_POST['notes'] ?? ''));
            $pushUser    = trim((string)($_POST['push_user'] ?? ''));
            $pushPass    = trim((string)($_POST['push_pass'] ?? ''));
            $allowedIps  = trim((string)($_POST['allowed_ips'] ?? ''));
            $isActive    = !empty($_POST['is_active']) ? 1 : 0;

            if ($name === '') throw new Exception('Device name is required.');
            if (!in_array($direction, ['in', 'out', 'both'], true)) $direction = 'in';
            if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new Exception('IP address is not valid.');
            }
            if ($port <= 0 || $port > 65535) $port = 80;

            $db->beginTransaction();
            $newId = (int)$db->insert(
                "INSERT INTO biometric_devices
                    (uuid, tenant_id, school_id, campus_id,
                     device_name, device_model, serial_number, ip_address, http_port,
                     location, direction, push_enabled, push_user, push_pass_hash,
                     allowed_ips, is_active, notes,
                     created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    uuidv4(),
                    $tenantId,
                    $schoolId ?: null,
                    null,
                    $name,
                    $model !== '' ? $model : null,
                    $serial !== '' ? $serial : null,
                    $ip !== '' ? $ip : null,
                    $port,
                    $location !== '' ? $location : null,
                    $direction,
                    $pushUser !== '' ? 1 : 0,
                    $pushUser !== '' ? $pushUser : null,
                    $pushPass !== '' ? password_hash($pushPass, PASSWORD_DEFAULT) : null,
                    $allowedIps !== '' ? $allowedIps : null,
                    $isActive,
                    $notes !== '' ? $notes : null,
                    $userId ?: null,
                ]
            );

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.biometric_device.created',
                'biometric_devices',
                $newId,
                ['name' => $name, 'model' => $model, 'serial' => $serial, 'direction' => $direction]
            );
            $db->commit();

            $_SESSION['success'] = 'Device registered.';
            header('Location: /platform/tenant/students/biometric-devices.php');
            exit;
        }

        // ---------------- UPDATE ----------------
        if ($action === 'update') {
            $id          = (int)($_POST['id'] ?? 0);
            $name        = trim((string)($_POST['device_name'] ?? ''));
            $model       = trim((string)($_POST['device_model'] ?? ''));
            $serial      = trim((string)($_POST['serial_number'] ?? ''));
            $ip          = trim((string)($_POST['ip_address'] ?? ''));
            $port        = (int)($_POST['http_port'] ?? 80);
            $location    = trim((string)($_POST['location'] ?? ''));
            $direction   = trim((string)($_POST['direction'] ?? 'in'));
            $notes       = trim((string)($_POST['notes'] ?? ''));
            $pushUser    = trim((string)($_POST['push_user'] ?? ''));
            $pushPass    = trim((string)($_POST['push_pass'] ?? ''));
            $allowedIps  = trim((string)($_POST['allowed_ips'] ?? ''));
            $isActive    = !empty($_POST['is_active']) ? 1 : 0;

            if ($name === '') throw new Exception('Device name is required.');
            if (!in_array($direction, ['in', 'out', 'both'], true)) $direction = 'in';
            if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new Exception('IP address is not valid.');
            }
            if ($port <= 0 || $port > 65535) $port = 80;

            $dev = getDevice($db, $tenantId, $id);
            if (!$dev) throw new Exception('Device not found.');

            $db->beginTransaction();

            // If pushPass is empty on update, keep the stored hash.
            $passClause = '';
            $params = [
                $name,
                $model !== '' ? $model : null,
                $serial !== '' ? $serial : null,
                $ip !== '' ? $ip : null,
                $port,
                $location !== '' ? $location : null,
                $direction,
                $pushUser !== '' ? 1 : 0,
                $pushUser !== '' ? $pushUser : null,
            ];
            if ($pushPass !== '') {
                $passClause = ", push_pass_hash = ?";
                $params[] = password_hash($pushPass, PASSWORD_DEFAULT);
            }
            $params[] = $allowedIps !== '' ? $allowedIps : null;
            $params[] = $isActive;
            $params[] = $notes !== '' ? $notes : null;
            $params[] = $id;
            $params[] = $tenantId;

            $db->execute(
                "UPDATE biometric_devices
                    SET device_name = ?, device_model = ?, serial_number = ?,
                        ip_address = ?, http_port = ?, location = ?, direction = ?,
                        push_enabled = ?, push_user = ?{$passClause},
                        allowed_ips = ?, is_active = ?, notes = ?,
                        updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                $params
            );

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.biometric_device.updated',
                'biometric_devices',
                $id,
                ['name' => $name, 'direction' => $direction, 'is_active' => $isActive]
            );
            $db->commit();

            $_SESSION['success'] = 'Device updated.';
            header('Location: /platform/tenant/students/biometric-devices.php');
            exit;
        }

        // ---------------- DELETE (soft) ----------------
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $dev = getDevice($db, $tenantId, $id);
            if (!$dev) throw new Exception('Device not found.');

            $db->beginTransaction();
            $db->execute(
                "UPDATE biometric_devices SET deleted_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.biometric_device.deleted',
                'biometric_devices',
                $id,
                ['name' => $dev['device_name']]
            );
            $db->commit();

            $_SESSION['success'] = 'Device removed.';
            header('Location: /platform/tenant/students/biometric-devices.php');
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Biometric devices action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/students/biometric-devices.php');
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

$devices = $db->fetchAll(
    "SELECT d.*,
            (SELECT COUNT(*) FROM biometric_device_users u
             WHERE u.tenant_id = d.tenant_id AND u.device_id = d.id
               AND u.deleted_at IS NULL) AS mapped_users,
            (SELECT COUNT(*) FROM biometric_events e
             WHERE e.tenant_id = d.tenant_id AND e.device_id = d.id) AS total_events
     FROM biometric_devices d
     WHERE d.tenant_id = ? AND d.deleted_at IS NULL
     ORDER BY d.device_name ASC",
    [$tenantId]
);

$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

$endpointBase = pushEndpointBase();
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

        .info-banner {
            background: linear-gradient(135deg, #eef6ff 0%, #e3f0ff 100%);
            border: 2px solid #4facfe;
            border-radius: 14px;
            padding: 16px 24px;
            margin-bottom: 24px;
            max-width: 1200px;
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

        .table-wrap {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            overflow: hidden;
            width: 100%;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
            margin-bottom: 24px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .data-table thead th {
            background: #f8f9fa;
            padding: 10px 18px;
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
            padding: 12px 18px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .data-table tbody tr:last-child td {
            border-bottom: none;
        }

        .data-table tbody tr:hover {
            background: #fafbfc;
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

        .push-endpoint {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 14px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            word-break: break-all;
            color: #0d6efd;
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
                        <h1><i class="fas fa-server me-2"></i>Biometric Devices</h1>
                        <p>Register attendance terminals and configure their event push</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/students/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Students
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo h($tenantName); ?></div>
                            <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                <?php echo count($devices); ?> device(s) registered
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>ISAPI · CSV fallback
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
                        <div class="ib-title">About biometric devices</div>
                        <div class="ib-text">
                            Register each physical attendance terminal here. Two integration paths are supported:
                            <strong>live push</strong> — the device POSTs events to the endpoint below the moment a
                            student authenticates — and <strong>CSV export</strong> — you download a log from the
                            device or its management software and upload it. The push endpoint is designed for
                            Hikvision ISAPI-compatible devices, which covers the Value Series and most modern
                            face/fingerprint/card terminals.
                        </div>
                    </div>
                </div>

                <!-- Endpoint + add button -->
                <div class="table-wrap" style="padding:16px 24px;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
                        <div style="flex:1;min-width:280px;">
                            <div style="font-weight:700;font-size:14px;color:#1a1a2e;margin-bottom:6px;">
                                Push endpoint URL
                            </div>
                            <div class="push-endpoint"><?php echo h($endpointBase); ?></div>
                            <div style="font-size:12px;color:#6c757d;margin-top:6px;">
                                Configure this URL on the device's <em>HTTP Listening Host</em> page, or the
                                equivalent setting in its management software.
                            </div>
                        </div>
                        <div>
                            <button type="button" class="btn btn-primary" id="addDeviceBtn">
                                <i class="fas fa-plus me-1"></i> Add Device
                            </button>
                        </div>
                    </div>
                </div>

                <?php if (empty($devices)): ?>
                    <div class="table-wrap">
                        <div class="empty-state">
                            <i class="fas fa-server"></i>
                            <h5>No devices registered yet</h5>
                            <p>Register your first attendance terminal to begin receiving events.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Device</th>
                                    <th style="width:140px;">Model / Serial</th>
                                    <th style="width:150px;">Network</th>
                                    <th style="width:110px;text-align:center;">Direction</th>
                                    <th style="width:100px;text-align:center;">Push</th>
                                    <th style="width:100px;text-align:center;">Active</th>
                                    <th style="width:180px;text-align:center;">Stats</th>
                                    <th style="width:200px;text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($devices as $d):
                                    $id = (int)$d['id'];
                                ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight:600;color:#1a1a2e;"><?php echo h($d['device_name']); ?></div>
                                            <?php if (!empty($d['location'])): ?>
                                                <div style="font-size:11px;color:#6c757d;">
                                                    <i class="fas fa-map-marker-alt me-1"></i><?php echo h($d['location']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-size:12px;color:#1a1a2e;"><?php echo h($d['device_model'] ?: '—'); ?></div>
                                            <div style="font-size:11px;color:#6c757d;font-family:'Courier New',monospace;">
                                                <?php echo h($d['serial_number'] ?: ''); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-family:'Courier New',monospace;font-size:12px;color:#0d6efd;">
                                                <?php echo h($d['ip_address'] ?: '—'); ?>
                                                <?php if (!empty($d['http_port'])): ?>:<?php echo (int)$d['http_port']; ?><?php endif; ?>
                                            </div>
                                            <?php if (!empty($d['allowed_ips'])): ?>
                                                <div style="font-size:11px;color:#6c757d;">IP allowlist set</div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <span class="pill <?php echo $d['direction'] === 'in' ? 'green' : ($d['direction'] === 'out' ? 'orange' : 'purple'); ?>">
                                                <?php echo h(ucfirst($d['direction'])); ?>
                                            </span>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ((int)$d['push_enabled'] === 1): ?>
                                                <span class="pill green"><i class="fas fa-bolt"></i>On</span>
                                            <?php else: ?>
                                                <span class="pill gray">Off</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ((int)$d['is_active'] === 1): ?>
                                                <span class="pill green">Active</span>
                                            <?php else: ?>
                                                <span class="pill gray">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <span class="pill blue"><?php echo (int)$d['mapped_users']; ?> mapped</span>
                                            <span class="pill purple"><?php echo (int)$d['total_events']; ?> events</span>
                                        </td>
                                        <td>
                                            <div class="d-flex justify-content-end gap-2 flex-wrap">
                                                <a href="/platform/tenant/students/biometric-users.php?device_id=<?php echo $id; ?>"
                                                    class="btn btn-outline-primary">
                                                    <i class="fas fa-users"></i> Mappings
                                                </a>
                                                <button type="button"
                                                    class="btn btn-outline-secondary js-edit-device"
                                                    data-device='<?php echo h(json_encode([
                                                                        'id' => $id,
                                                                        'device_name' => $d['device_name'],
                                                                        'device_model' => $d['device_model'],
                                                                        'serial_number' => $d['serial_number'],
                                                                        'ip_address' => $d['ip_address'],
                                                                        'http_port' => $d['http_port'],
                                                                        'location' => $d['location'],
                                                                        'direction' => $d['direction'],
                                                                        'push_user' => $d['push_user'],
                                                                        'allowed_ips' => $d['allowed_ips'],
                                                                        'is_active' => (int)$d['is_active'],
                                                                        'notes' => $d['notes'],
                                                                    ], JSON_UNESCAPED_UNICODE)); ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" style="display:inline-block;"
                                                      onsubmit="return confirm(' Remove this device?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo $id; ?>">
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
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- CREATE MODAL -->
    <div class="modal fade" id="createDeviceModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/students/biometric-devices.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5><i class="fas fa-server text-primary me-2"></i>Register New Device</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="c_name">Device name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="c_name" name="device_name" required maxlength="100"
                                    placeholder="e.g. Main Gate Reader">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="c_location">Location</label>
                                <input type="text" class="form-control" id="c_location" name="location" maxlength="120"
                                    placeholder="e.g. Primary block entrance">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="c_model">Model</label>
                                <input type="text" class="form-control" id="c_model" name="device_model" maxlength="60"
                                    placeholder="e.g. DS-K1T341AMF">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="c_serial">Serial number</label>
                                <input type="text" class="form-control" id="c_serial" name="serial_number" maxlength="60">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="c_direction">Direction</label>
                                <select class="form-select" id="c_direction" name="direction">
                                    <option value="in" selected>In (arrival)</option>
                                    <option value="out">Out (departure)</option>
                                    <option value="both">Both</option>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label" for="c_ip">IP address</label>
                                <input type="text" class="form-control" id="c_ip" name="ip_address" maxlength="45"
                                    placeholder="e.g. 192.168.1.108">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="c_port">HTTP port</label>
                                <input type="number" class="form-control" id="c_port" name="http_port" value="80" min="1" max="65535">
                            </div>

                            <div class="col-12">
                                <hr style="margin:8px 0;">
                                <div style="font-weight:600;font-size:13px;color:#1a1a2e;margin-bottom:4px;">
                                    Push credentials (URL-based)
                                </div>
                                <div style="font-size:12px;color:#6c757d;margin-bottom:8px;">
                                    Configure the device with these credentials in its HTTP Listening Host URL:
                                    <code>http://USER:PASS@<?php echo h(parse_url($endpointBase, PHP_URL_HOST) ?: 'admin.edutrack.local'); ?>/api/biometric/hikvision.php</code>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="c_push_user">Push user</label>
                                <input type="text" class="form-control" id="c_push_user" name="push_user" maxlength="60"
                                    placeholder="e.g. edutrack">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="c_push_pass">Push password</label>
                                <input type="text" class="form-control" id="c_push_pass" name="push_pass" maxlength="120"
                                    placeholder="Leave blank for no auth">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="c_allowed">Allowed IPs</label>
                                <input type="text" class="form-control" id="c_allowed" name="allowed_ips" maxlength="255"
                                    placeholder="Comma-separated, optional">
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="c_notes">Notes</label>
                                <input type="text" class="form-control" id="c_notes" name="notes" maxlength="500">
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="c_active" name="is_active" value="1" checked>
                                    <label class="form-check-label" for="c_active">Active</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Device</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- EDIT MODAL -->
    <div class="modal fade" id="editDeviceModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/students/biometric-devices.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="e_id" value="">
                    <div class="modal-header">
                        <h5><i class="fas fa-edit text-primary me-2"></i>Edit Device</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="e_name">Device name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="e_name" name="device_name" required maxlength="100">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="e_location">Location</label>
                                <input type="text" class="form-control" id="e_location" name="location" maxlength="120">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="e_model">Model</label>
                                <input type="text" class="form-control" id="e_model" name="device_model" maxlength="60">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="e_serial">Serial number</label>
                                <input type="text" class="form-control" id="e_serial" name="serial_number" maxlength="60">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="e_direction">Direction</label>
                                <select class="form-select" id="e_direction" name="direction">
                                    <option value="in">In (arrival)</option>
                                    <option value="out">Out (departure)</option>
                                    <option value="both">Both</option>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label" for="e_ip">IP address</label>
                                <input type="text" class="form-control" id="e_ip" name="ip_address" maxlength="45">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="e_port">HTTP port</label>
                                <input type="number" class="form-control" id="e_port" name="http_port" min="1" max="65535">
                            </div>

                            <div class="col-12">
                                <hr style="margin:8px 0;">
                                <div style="font-weight:600;font-size:13px;color:#1a1a2e;margin-bottom:4px;">
                                    Push credentials (URL-based)
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="e_push_user">Push user</label>
                                <input type="text" class="form-control" id="e_push_user" name="push_user" maxlength="60">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="e_push_pass">New push password</label>
                                <input type="text" class="form-control" id="e_push_pass" name="push_pass" maxlength="120"
                                    placeholder="Leave blank to keep current">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="e_allowed">Allowed IPs</label>
                                <input type="text" class="form-control" id="e_allowed" name="allowed_ips" maxlength="255">
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="e_notes">Notes</label>
                                <input type="text" class="form-control" id="e_notes" name="notes" maxlength="500">
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="e_active" name="is_active" value="1">
                                    <label class="form-check-label" for="e_active">Active</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Changes</button>
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

        // Create modal
        (function() {
            const el = document.getElementById('createDeviceModal');
            const modal = new bootstrap.Modal(el);
            document.getElementById('addDeviceBtn').addEventListener('click', function() {
                modal.show();
            });
        })();

        // Edit modal
        (function() {
            const el = document.getElementById('editDeviceModal');
            const modal = new bootstrap.Modal(el);
            document.querySelectorAll('.js-edit-device').forEach(btn => {
                btn.addEventListener('click', function() {
                    const d = JSON.parse(this.getAttribute('data-device'));
                    document.getElementById('e_id').value = d.id || '';
                    document.getElementById('e_name').value = d.device_name || '';
                    document.getElementById('e_location').value = d.location || '';
                    document.getElementById('e_model').value = d.device_model || '';
                    document.getElementById('e_serial').value = d.serial_number || '';
                    document.getElementById('e_ip').value = d.ip_address || '';
                    document.getElementById('e_port').value = d.http_port || 80;
                    document.getElementById('e_direction').value = d.direction || 'in';
                    document.getElementById('e_push_user').value = d.push_user || '';
                    document.getElementById('e_push_pass').value = '';
                    document.getElementById('e_allowed').value = d.allowed_ips || '';
                    document.getElementById('e_notes').value = d.notes || '';
                    document.getElementById('e_active').checked = (d.is_active === 1 || d.is_active === '1');
                    modal.show();
                });
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