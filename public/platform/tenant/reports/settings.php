<?php

/**
 * Tenant Report Settings — school-side Report header configuration.
 *
 * @package Student 360
 * @subpackage Platform\Tenant\Reports
 * @version 1.3
 * @filepath public/platform/tenant/reports/settings.php
 *
 * v1.3 change (2026-10-07) [UPLOAD + CACHE FIX]:
 *   - The logo path field becomes an upload field plus a fallback text
 *     field. Uploads are saved to /uploads/schools/{school_id}/ and the
 *     resulting path is written to report.logo_path. The text field
 *     remains for pasting an external URL.
 *   - Cache-Control headers are sent so a POST-redirect-GET does not
 *     serve a stale form on the next navigation.
 *   - The read try/catch logs the exception instead of silently
 *     swallowing it, so a query failure cannot masquerade as "no rows".
 *   - The five existing keys are unchanged.
 *
 * v1.2 change (2026-10-07) [JS SYNTAX FIX]:
 *   - Replaced `const el;` with `let el;` inside loadUserInfo().
 *
 * v1.1 change (2026-10-07) [REPORT LOGO PATH]:
 *   - The settings page gains the fifth key, report.logo_path.
 *
 * WHAT THIS PAGE DOES:
 *   - Requires a tenant session (require_tenant()).
 *   - Requires a school context ($_SESSION['school_id'] > 0).
 *   - Reads five report-display keys from school_settings group 'report'.
 *   - Renders a form for editing those five values.
 *   - On POST, upserts each of the five keys into school_settings.
 *   - On POST with a logo file, saves the file under
 *     /uploads/schools/{school_id}/ and writes the resulting path.
 *   - Writes one audit_logs row with action = 'report.settings.saved'.
 *
 * WHAT THIS PAGE DOES NOT DO:
 *   - It does not render the report card. That is view.php.
 *   - It does not manage school name, address, or head teacher name
 *     and signature. Those live on the schools table.
 *
 * LOCKED DECISIONS:
 *   - Tenant scoping on every query: tenant_id = ?.
 *   - Soft deletes: deleted_at IS NULL.
 *   - UUIDs: uuidv4() on every INSERT.
 *   - CSRF: verify_csrf() on POST.
 *   - Upload path convention: /uploads/schools/{school_id}/.
 *   - Upload overwrites any existing file with the same extension.
 *   - Audit: report.settings.saved.
 */

$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/app/bootstrap.php';
require_tenant();

// [v1.3] Cache-Control: force a fresh fetch on every request so a
// POST-redirect-GET does not serve a stale form on navigation.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$tenantId     = current_tenant_id();
$schoolId     = (int)($_SESSION['school_id'] ?? 0);
$userId       = current_user_id();
$currentUser  = $_SESSION['user_name'] ?? 'Admin';
$userAvatar   = substr($currentUser, 0, 1);
$isSuperAdmin = is_super_admin();

$pageTitle   = 'Report Settings - Student 360 Platform';
$currentPage = 'reports';

$db = DatabaseHelper::getInstance();

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

function writeAudit($db, int $tenantId, int $userId, string $action, string $resourceType, ?int $resourceId, array $details): void
{
    try {
        $db->insert(
            "INSERT INTO audit_logs (user_id, tenant_id, school_id, action, resource_type, resource_id, details, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $userId ?: null,
                $tenantId,
                (int)($_SESSION['school_id'] ?? 0) ?: null,
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

function upsertReportSetting($db, int $tenantId, int $schoolId, int $userId, string $key, string $value): void
{
    $existing = $db->fetchOne(
        "SELECT id FROM school_settings
          WHERE tenant_id = ? AND school_id = ? AND setting_key = ?
            AND deleted_at IS NULL
          LIMIT 1",
        [$tenantId, $schoolId, $key]
    );

    if ($existing) {
        $db->execute(
            "UPDATE school_settings
                SET setting_value = ?, setting_group = 'report',
                    updated_by = ?, updated_at = NOW()
              WHERE id = ? AND tenant_id = ? AND school_id = ?",
            [$value, $userId ?: null, (int)$existing['id'], $tenantId, $schoolId]
        );
        return;
    }

    $db->insert(
        "INSERT INTO school_settings
            (uuid, school_id, tenant_id, setting_key, setting_value,
             setting_group, is_encrypted, created_by, updated_by,
             created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 'report', 0, ?, ?, NOW(), NOW())",
        [
            uuidv4(),
            $schoolId,
            $tenantId,
            $key,
            $value,
            $userId ?: null,
            $userId ?: null,
        ]
    );
}

/**
 * [v1.3] Save an uploaded logo file for the current school.
 * Returns the web path on success, null on failure. On success the
 * path is written by the caller to report.logo_path.
 * Extension is whitelisted. Size cap is 2 MB. Overwrites any existing
 * file with the same extension.
 */
function saveUploadedLogo(int $schoolId, array $file, string $projectRoot): ?string
{
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }
    if (!empty($file['error']) && (int)$file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return null;
    }

    $orig = (string)($file['name'] ?? 'logo');
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        return null;
    }
    if ($ext === 'jpeg') $ext = 'jpg';

    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = (string)finfo_file($fi, $file['tmp_name']);
            finfo_close($fi);
        }
    }
    $allowedMime = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
    if ($mime !== '' && !in_array($mime, $allowedMime, true)) {
        return null;
    }

    $relDir = '/uploads/schools/' . $schoolId;
    $absDir = $projectRoot . DIRECTORY_SEPARATOR . 'public'
        . DIRECTORY_SEPARATOR . 'uploads'
        . DIRECTORY_SEPARATOR . 'schools'
        . DIRECTORY_SEPARATOR . $schoolId;

    if (!is_dir($absDir)) {
        if (!@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            error_log('report logo upload: cannot create ' . $absDir);
            return null;
        }
    }

    $destName = 'logo.' . $ext;
    $absDest  = $absDir . DIRECTORY_SEPARATOR . $destName;
    $relDest  = $relDir . '/' . $destName;

    if (!@move_uploaded_file($file['tmp_name'], $absDest)) {
        error_log('report logo upload: move_uploaded_file failed to ' . $absDest);
        return null;
    }

    return $relDest;
}

// ============================================
// SCHOOL CONTEXT
// ============================================
if ($schoolId <= 0) {
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title>No school context</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <style>
            body {
                font-family: system-ui, sans-serif;
                background: #f0f2f5;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .nf-card {
                background: #fff;
                border-radius: 16px;
                padding: 40px;
                max-width: 520px;
                text-align: center;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            }

            .nf-card i {
                font-size: 48px;
                color: #4facfe;
                margin-bottom: 16px;
                display: block;
            }

            .nf-card h1 {
                font-size: 22px;
                font-weight: 700;
                color: #1a1a2e;
                margin-bottom: 8px;
            }

            .nf-card p {
                color: #6c757d;
                font-size: 14px;
                margin: 0 0 16px;
            }

            .nf-card a {
                color: #0d6efd;
                text-decoration: none;
                font-weight: 600;
            }
        </style>
    </head>

    <body>
        <div class="nf-card">
            <i class="fas fa-school"></i>
            <h1>No school selected</h1>
            <p>Report settings are scoped to a school. Sign in with a school context to continue.</p>
            <a href="/platform/tenant/reports/index.php"><i class="fas fa-arrow-left me-1"></i> Back to Reports</a>
        </div>
    </body>

    </html>
<?php
    exit;
}

$school = $db->fetchOne(
    "SELECT id, school_name, address
       FROM schools
      WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL
      LIMIT 1",
    [$schoolId, $tenantId]
);
if (!$school) {
    header('Location: /platform/tenant/reports/index.php');
    exit;
}

// ============================================
// POST — save the five keys (logo may arrive as a file)
// ============================================
$errors   = [];
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $formData = $_POST;

    $header1  = trim((string)($_POST['report_header_line_1'] ?? ''));
    $header2  = trim((string)($_POST['report_header_line_2'] ?? ''));
    $header3  = trim((string)($_POST['report_header_line_3'] ?? ''));
    $motto    = trim((string)($_POST['report_motto']         ?? ''));
    $logoText = trim((string)($_POST['report_logo_path']     ?? ''));

    if (mb_strlen($header1) > 200) $errors[] = 'Header line 1 must be 200 characters or less.';
    if (mb_strlen($header2) > 200) $errors[] = 'Header line 2 must be 200 characters or less.';
    if (mb_strlen($header3) > 200) $errors[] = 'Header line 3 must be 200 characters or less.';
    if (mb_strlen($motto)   > 255) $errors[] = 'Motto must be 255 characters or less.';
    if (mb_strlen($logoText) > 500) $errors[] = 'Logo path must be 500 characters or less.';
    if ($logoText !== '' && strpos($logoText, '://') === false && $logoText[0] !== '/') {
        $errors[] = 'Logo path must be an absolute URL or a path starting with /.';
    }

    // Upload wins over the text field when a file is present.
    $logoFinal = $logoText;
    if (!empty($_FILES['report_logo_file']['name'])) {
        $uploaded = saveUploadedLogo($schoolId, $_FILES['report_logo_file'], $projectRoot);
        if ($uploaded === null) {
            $errors[] = 'Logo upload failed. Use a PNG, JPG, GIF, or WebP under 2 MB.';
        } else {
            $logoFinal = $uploaded;
        }
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            upsertReportSetting($db, $tenantId, $schoolId, $userId, 'report.header_line_1', $header1);
            upsertReportSetting($db, $tenantId, $schoolId, $userId, 'report.header_line_2', $header2);
            upsertReportSetting($db, $tenantId, $schoolId, $userId, 'report.header_line_3', $header3);
            upsertReportSetting($db, $tenantId, $schoolId, $userId, 'report.motto',         $motto);
            upsertReportSetting($db, $tenantId, $schoolId, $userId, 'report.logo_path',     $logoFinal);

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'report.settings.saved',
                'school_settings',
                $schoolId,
                [
                    'school_id' => $schoolId,
                    'keys'      => [
                        'report.header_line_1' => $header1,
                        'report.header_line_2' => $header2,
                        'report.header_line_3' => $header3,
                        'report.motto'         => $motto,
                        'report.logo_path'     => $logoFinal,
                    ],
                ]
            );

            $db->commit();
            $_SESSION['success'] = 'Report header settings saved.';
            header('Location: /platform/tenant/reports/settings.php');
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('report settings save error: ' . $e->getMessage());
            $errors[] = 'Failed to save. Please try again.';
            $_SESSION['errors']    = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: /platform/tenant/reports/settings.php');
            exit;
        }
    } else {
        $_SESSION['errors']    = $errors;
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/reports/settings.php');
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
// LOAD CURRENT VALUES
// ============================================
$currentValues = [
    'report.header_line_1' => '',
    'report.header_line_2' => '',
    'report.header_line_3' => '',
    'report.motto'         => '',
    'report.logo_path'     => '',
];
try {
    $rows = $db->fetchAll(
        "SELECT setting_key, setting_value
           FROM school_settings
          WHERE tenant_id = ? AND school_id = ?
            AND setting_group = 'report'
            AND setting_key IN ('report.header_line_1','report.header_line_2','report.header_line_3','report.motto','report.logo_path')
            AND deleted_at IS NULL",
        [$tenantId, $schoolId]
    );
    foreach ($rows as $r) {
        $k = (string)$r['setting_key'];
        if (array_key_exists($k, $currentValues)) {
            $currentValues[$k] = (string)($r['setting_value'] ?? '');
        }
    }
} catch (Exception $e) {
    // [v1.3] Log the failure instead of silently swallowing it.
    error_log('report settings read failed: ' . $e->getMessage());
}

function formVal(string $key, string $fallback = ''): string
{
    global $formData, $useForm, $currentValues;
    if ($useForm && array_key_exists($key, $formData)) {
        return (string)$formData[$key];
    }
    if (array_key_exists($key, $currentValues)) {
        return (string)$currentValues[$key];
    }
    return $fallback;
}

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
            color: #495057;
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
            font-size: 15px;
            font-weight: 700;
            margin: 0;
            color: #1a1a2e;
            display: flex;
            align-items: center;
        }

        .card-custom .card-body-custom {
            padding: 20px 24px;
        }

        .form-label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            margin-bottom: 4px;
            display: block;
        }

        .form-control {
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

        .form-control:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 4px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .form-text {
            font-size: 11px;
            color: #6c757d;
            margin-top: 4px;
        }

        .upload-zone {
            border: 2px dashed #ced4da;
            border-radius: 12px;
            padding: 16px;
            background: #fafbfc;
            transition: border-color 0.2s, background 0.2s;
        }

        .upload-zone:hover {
            border-color: #4facfe;
            background: #f5faff;
        }

        .upload-zone input[type="file"] {
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

        .preview-card {
            background: #fff;
            border: 1px solid #ced4da;
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 20px;
            font-family: 'Inter', Arial, sans-serif;
        }

        .preview-card .pc-title {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .preview-card .pc-school {
            text-align: center;
            border-bottom: 2px solid #1a2a3a;
            padding-bottom: 8px;
        }

        .preview-card .pc-school h1 {
            font-size: 16px;
            font-weight: 800;
            margin: 0 0 4px;
            color: #1a2a3a;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .preview-card .pc-school .pc-line {
            font-size: 11px;
            color: #4a6b8a;
            margin: 2px 0;
        }

        .preview-card .pc-school .pc-motto {
            font-size: 10.5px;
            color: #6c757d;
            font-style: italic;
            margin-top: 4px;
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
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>

            <?php include $projectRoot . '/app/views/partials/sidebar.php'; ?>

            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-cog me-2"></i>Report Settings</h1>
                        <p>Configure the report card header for <?php echo h($school['school_name']); ?></p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/reports/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Reports
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo h($tenantName); ?></div>
                            <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                <?php echo h($school['school_name']); ?>
                                <?php if (!empty($school['address'])): ?>
                                    · <?php echo h(mb_substr($school['address'], 0, 80)); ?><?php echo mb_strlen($school['address']) > 80 ? '…' : ''; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>Header overrides
                    </span>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert-pro alert-pro-error" id="serverErrorBox">
                        <div class="alert-pro-icon"><i class="fas fa-times-circle"></i></div>
                        <div class="alert-pro-content">
                            <div class="alert-pro-title">Could not save</div>
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
                        <div class="ib-title">Five header override strings</div>
                        <div class="ib-text">
                            These five fields override the report card header in
                            <code>view.php</code> and <code>print-class.php</code>. Leave a field blank to fall back to
                            <code>schools.school_name</code>, <code>schools.address</code>, and the logo chain
                            (<code>schools.logo_path</code> → <code>tenants.logo</code>). The head teacher name
                            and signature are managed on the school record, not here.
                        </div>
                    </div>
                </div>

                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-heading me-2 text-primary"></i>Header preview</h6>
                    </div>
                    <div class="card-body-custom">
                        <div class="preview-card">
                            <div class="pc-title">Live preview</div>
                            <div class="pc-school">
                                <h1 id="previewLine1"><?php echo h(formVal('report_header_line_1', $school['school_name'])); ?></h1>
                                <p class="pc-line" id="previewLine2"><?php echo h(formVal('report_header_line_2', 'P.O. Box —')); ?></p>
                                <p class="pc-line" id="previewLine3"><?php echo h(formVal('report_header_line_3', '—')); ?></p>
                                <div class="pc-motto" id="previewMotto" style="<?php echo formVal('report_motto') === '' ? 'display:none;' : ''; ?>"><?php echo h(formVal('report_motto')); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <form method="POST" action="/platform/tenant/reports/settings.php" id="settingsForm" enctype="multipart/form-data">
                    <?= csrf_field() ?>

                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-align-center me-2 text-primary"></i>Header lines</h6>
                        </div>
                        <div class="card-body-custom">
                            <div class="mb-3">
                                <label class="form-label" for="report_header_line_1">Line 1 — school name</label>
                                <input type="text" class="form-control" id="report_header_line_1"
                                    name="report_header_line_1" maxlength="200"
                                    placeholder="<?php echo h($school['school_name']); ?>"
                                    value="<?php echo h(formVal('report_header_line_1')); ?>">
                                <div class="form-text">Leave blank to fall back to <strong><?php echo h($school['school_name']); ?></strong>.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="report_header_line_2">Line 2 — address line</label>
                                <input type="text" class="form-control" id="report_header_line_2"
                                    name="report_header_line_2" maxlength="200"
                                    placeholder="P.O. Box —"
                                    value="<?php echo h(formVal('report_header_line_2')); ?>">
                                <div class="form-text">For example: <em>P.O. Box 1234, Accra</em>.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="report_header_line_3">Line 3 — address line</label>
                                <input type="text" class="form-control" id="report_header_line_3"
                                    name="report_header_line_3" maxlength="200"
                                    placeholder="—"
                                    value="<?php echo h(formVal('report_header_line_3')); ?>">
                                <div class="form-text">For example: <em>Tel: +233 30 000 0000 · info@school.edu.gh</em>.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="report_motto">Motto</label>
                                <input type="text" class="form-control" id="report_motto"
                                    name="report_motto" maxlength="255"
                                    placeholder="Optional motto"
                                    value="<?php echo h(formVal('report_motto')); ?>">
                                <div class="form-text">Optional. Renders as a small italic line under the address lines.</div>
                            </div>
                        </div>
                    </div>

                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-image me-2 text-primary"></i>School logo</h6>
                        </div>
                        <div class="card-body-custom">
                            <div class="mb-3">
                                <label class="form-label" for="report_logo_file">Upload a logo file</label>
                                <div class="upload-zone">
                                    <input type="file" class="form-control" id="report_logo_file"
                                        name="report_logo_file" accept="image/png,image/jpeg,image/gif,image/webp">
                                    <div class="form-text">
                                        PNG, JPG, GIF, or WebP. Maximum 2 MB.
                                        On save, the file lands at
                                        <code>/uploads/schools/<?php echo (int)$schoolId; ?>/logo.&lt;ext&gt;</code>
                                        and becomes the report header logo.
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="report_logo_path">Or paste a logo path / URL</label>
                                <input type="text" class="form-control" id="report_logo_path"
                                    name="report_logo_path" maxlength="500"
                                    placeholder="/uploads/schools/<?php echo (int)$schoolId; ?>/logo.png"
                                    value="<?php echo h(formVal('report_logo_path')); ?>">
                                <div class="form-text">
                                    Optional. Absolute URL (<code>https://...</code>) or a server path (<code>/uploads/...</code>).
                                    An uploaded file wins over this field. Leave both blank to fall through
                                    to <code>schools.logo_path</code>, then <code>tenants.logo</code>.
                                </div>
                            </div>

                            <div style="margin-top:8px;padding:8px 10px;background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;">
                                <div style="font-size:11px;color:#6c757d;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:4px;">Current override</div>
                                <img id="report_logo_preview" src="<?php echo h(formVal('report_logo_path')); ?>" alt=""
                                    style="max-height:60px;max-width:160px;display:<?php echo formVal('report_logo_path') !== '' ? 'block' : 'none'; ?>;"
                                    onerror="this.style.display='none';">
                                <span id="report_logo_preview_empty" style="font-size:12px;color:#adb5bd;display:<?php echo formVal('report_logo_path') !== '' ? 'none' : 'block'; ?>;">
                                    No override — the report falls back to the school or tenant logo.
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="card-custom">
                        <div class="card-body-custom">
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <i class="fas fa-save me-2"></i> Save header settings
                                </button>
                                <a href="/platform/tenant/reports/settings.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i> Reset
                                </a>
                                <div style="flex:1;min-width:200px;font-size:12px;color:#6c757d;">
                                    Saving writes five keys to <code>school_settings</code> and one audit row.
                                    An uploaded logo replaces <code>/uploads/schools/<?php echo (int)$schoolId; ?>/logo.*</code>.
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

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
            if (window.innerWidth <= 768 && sidebar && toggle) {
                if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 768 && sidebar) sidebar.classList.remove('open');
        });

        function logout() {
            if (confirm('Are you sure you want to logout?')) window.location.href = '/platform/tenant/logout.php';
        }

        (function() {
            const l1 = document.getElementById('report_header_line_1');
            const l2 = document.getElementById('report_header_line_2');
            const l3 = document.getElementById('report_header_line_3');
            const mt = document.getElementById('report_motto');
            const p1 = document.getElementById('previewLine1');
            const p2 = document.getElementById('previewLine2');
            const p3 = document.getElementById('previewLine3');
            const pm = document.getElementById('previewMotto');

            const l1Fallback = <?php echo json_encode($school['school_name']); ?>;
            const l2Fallback = 'P.O. Box —';
            const l3Fallback = '—';

            function sync() {
                p1.textContent = l1.value.trim() !== '' ? l1.value : l1Fallback;
                p2.textContent = l2.value.trim() !== '' ? l2.value : l2Fallback;
                p3.textContent = l3.value.trim() !== '' ? l3.value : l3Fallback;
                const motto = mt.value.trim();
                pm.textContent = motto;
                pm.style.display = motto === '' ? 'none' : '';
            }
            l1.addEventListener('input', sync);
            l2.addEventListener('input', sync);
            l3.addEventListener('input', sync);
            mt.addEventListener('input', sync);
        })();

        (function() {
            const li = document.getElementById('report_logo_path');
            const pv = document.getElementById('report_logo_preview');
            const em = document.getElementById('report_logo_preview_empty');
            if (!li || !pv || !em) return;

            function syncLogo() {
                const v = li.value.trim();
                if (v === '') {
                    pv.style.display = 'none';
                    em.textContent = 'No override — the report falls back to the school or tenant logo.';
                    em.style.display = 'block';
                    return;
                }
                pv.src = v;
                pv.style.display = 'block';
                em.style.display = 'none';
            }
            li.addEventListener('input', syncLogo);
            pv.addEventListener('error', function() {
                pv.style.display = 'none';
                em.textContent = 'The path does not resolve to an image.';
                em.style.display = 'block';
            });
        })();

        document.getElementById('settingsForm').addEventListener('submit', function() {
            const btn = document.getElementById('submitBtn');
            if (btn && !btn.disabled) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Saving...';
            }
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
                    let el;
                    if ((el = document.getElementById('userName'))) el.textContent = user.first_name || 'Admin';
                    if ((el = document.getElementById('userAvatar'))) el.textContent = (user.first_name || 'A').charAt(0);
                    if ((el = document.getElementById('userRole'))) el.textContent = (user.roles || ['Administrator'])[0];
                } catch (e) {}
            }
        }
        document.addEventListener('DOMContentLoaded', loadUserInfo);
    </script>
</body>

</html>