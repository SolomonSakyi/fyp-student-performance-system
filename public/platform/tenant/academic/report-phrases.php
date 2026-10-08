<?php

/**
 * Academic Report Phrases — CRUD for the report card phrase bank.
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Academic
 * @version 1.1
 * @filepath public/platform/tenant/academic/report-phrases.php
 *
 * v1.1 change (2026-10-07) [JS SYNTAX FIX]:
 *   - Replaced `const el;` with `let el;` inside loadUserInfo(). The
 *     former is invalid JavaScript and breaks the whole script block,
 *     including the create and edit modal bindings. No other line
 *     changes.
 *
 * WHAT THIS PAGE DOES:
 *   - Requires a tenant session (require_tenant()).
 *   - Lists report_phrases rows for the current tenant with
 *     filters by category, band, and active/inactive status.
 *   - Provides create, edit, toggle-active, and delete actions.
 *   - Every write is audited under report_phrases.* actions.
 *
 * WHAT THIS PAGE DOES NOT DO:
 *   - It does not read or write the report card. That is
 *     /platform/tenant/reports/view.php.
 *   - It does not run PhraseGenerator.
 *   - It does not manage the report card header or release channels.
 *
 * TABLE SHAPE (report_phrases, live DDL 2026-10-07):
 *   id, uuid varchar(36) NOT NULL, tenant_id,
 *   category enum('profile','conduct','attitude','teacher_remark','head_teacher_remark'),
 *   band varchar(32), text text NOT NULL, weight int NOT NULL DEFAULT 100,
 *   is_active tinyint(1) NOT NULL DEFAULT 1,
 *   created_by, created_at, updated_at, deleted_at.
 *
 * BANDS IN USE (from PhraseGenerator.php v1.0):
 *   critical, poor, fair, good, very_good, excellent.
 *
 * SELECTOR THAT CONSUMES THESE ROWS (PhraseGenerator::selectPhrase):
 *   WHERE tenant_id = ? AND category = ? AND band = ?
 *     AND is_active = 1 AND deleted_at IS NULL
 *   ORDER BY weight DESC, id ASC LIMIT 1
 *
 * LOCKED DECISIONS:
 *   - Tenant scoping on every query: tenant_id = ?.
 *   - Soft deletes: deleted_at IS NULL.
 *   - UUIDs: uuidv4() on every INSERT.
 *   - CSRF: verify_csrf() on every POST.
 */

$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/app/bootstrap.php';
require_tenant();

$tenantId     = current_tenant_id();
$schoolId     = (int)($_SESSION['school_id'] ?? 0);
$userId       = current_user_id();
$currentUser  = $_SESSION['user_name'] ?? 'Admin';
$userAvatar   = substr($currentUser, 0, 1);
$isSuperAdmin = is_super_admin();

$pageTitle   = 'Report Phrases - Student 360 Platform';
$currentPage = 'academic';

$db = DatabaseHelper::getInstance();

// ============================================
// CONSTANTS
// ============================================
const RP_CATEGORIES = ['profile', 'conduct', 'attitude', 'teacher_remark', 'head_teacher_remark'];
const RP_BANDS      = ['critical', 'poor', 'fair', 'good', 'very_good', 'excellent'];

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

function categoryLabel(string $c): string
{
    return [
        'profile'             => 'Profile',
        'conduct'             => 'Conduct',
        'attitude'            => 'Attitude',
        'teacher_remark'      => 'Teacher\'s Remark',
        'head_teacher_remark' => 'Head Teacher\'s Remark',
    ][$c] ?? $c;
}

function bandLabel(string $b): string
{
    return [
        'critical'  => 'Critical',
        'poor'      => 'Poor',
        'fair'      => 'Fair',
        'good'      => 'Good',
        'very_good' => 'Very Good',
        'excellent' => 'Excellent',
    ][$b] ?? $b;
}

function bandPill(string $b): string
{
    return [
        'critical'  => 'red',
        'poor'      => 'orange',
        'fair'      => 'gray',
        'good'      => 'blue',
        'very_good' => 'teal',
        'excellent' => 'green',
    ][$b] ?? 'gray';
}

// ============================================
// POST ACTIONS
// ============================================
$errors   = [];
$formData = [];
$action   = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $formData = $_POST;

    try {
        // ---------- CREATE ----------
        if ($action === 'create') {
            $category = (string)($_POST['category'] ?? '');
            $band     = (string)($_POST['band']     ?? '');
            $text     = trim((string)($_POST['text']   ?? ''));
            $weight   = (int)($_POST['weight']   ?? 100);
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if (!in_array($category, RP_CATEGORIES, true)) $errors[] = 'Invalid category.';
            if (!in_array($band, RP_BANDS, true))         $errors[] = 'Invalid band.';
            if ($text === '')                             $errors[] = 'Phrase text is required.';
            if (mb_strlen($text) > 5000)                  $errors[] = 'Phrase text is too long.';
            if ($weight < 0 || $weight > 9999)            $errors[] = 'Weight must be between 0 and 9999.';

            if (empty($errors)) {
                $db->beginTransaction();
                $newId = (int)$db->insert(
                    "INSERT INTO report_phrases
                        (uuid, tenant_id, category, band, text, weight, is_active,
                         created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        uuidv4(),
                        $tenantId,
                        $category,
                        $band,
                        $text,
                        $weight,
                        $isActive,
                        $userId ?: null,
                    ]
                );
                writeAudit(
                    $db,
                    $tenantId,
                    $userId,
                    'academic.report_phrase.created',
                    'report_phrases',
                    $newId,
                    ['category' => $category, 'band' => $band, 'weight' => $weight]
                );
                $db->commit();
                $_SESSION['success'] = 'Report phrase created.';
                header('Location: /platform/tenant/academic/report-phrases.php');
                exit;
            } else {
                $_SESSION['errors']    = $errors;
                $_SESSION['form_data'] = $_POST;
                header('Location: /platform/tenant/academic/report-phrases.php');
                exit;
            }
        }

        // ---------- UPDATE ----------
        if ($action === 'update') {
            $id       = (int)($_POST['id'] ?? 0);
            $category = (string)($_POST['category'] ?? '');
            $band     = (string)($_POST['band']     ?? '');
            $text     = trim((string)($_POST['text']   ?? ''));
            $weight   = (int)($_POST['weight']   ?? 100);
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            $row = $db->fetchOne(
                "SELECT id FROM report_phrases
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Phrase not found.');

            if (!in_array($category, RP_CATEGORIES, true)) $errors[] = 'Invalid category.';
            if (!in_array($band, RP_BANDS, true))         $errors[] = 'Invalid band.';
            if ($text === '')                             $errors[] = 'Phrase text is required.';
            if ($weight < 0 || $weight > 9999)            $errors[] = 'Weight must be between 0 and 9999.';

            if (empty($errors)) {
                $db->beginTransaction();
                $db->execute(
                    "UPDATE report_phrases
                        SET category = ?, band = ?, text = ?, weight = ?,
                            is_active = ?, updated_at = NOW()
                      WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$category, $band, $text, $weight, $isActive, $id, $tenantId]
                );
                writeAudit(
                    $db,
                    $tenantId,
                    $userId,
                    'academic.report_phrase.updated',
                    'report_phrases',
                    $id,
                    ['category' => $category, 'band' => $band, 'weight' => $weight]
                );
                $db->commit();
                $_SESSION['success'] = 'Report phrase updated.';
                header('Location: /platform/tenant/academic/report-phrases.php');
                exit;
            } else {
                $_SESSION['errors']    = $errors;
                $_SESSION['form_data'] = $_POST;
                header('Location: /platform/tenant/academic/report-phrases.php');
                exit;
            }
        }

        // ---------- TOGGLE ACTIVE ----------
        if ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            $row = $db->fetchOne(
                "SELECT id, is_active FROM report_phrases
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Phrase not found.');
            $new = ((int)$row['is_active']) === 1 ? 0 : 1;

            $db->beginTransaction();
            $db->execute(
                "UPDATE report_phrases SET is_active = ?, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$new, $id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.report_phrase.toggled_active',
                'report_phrases',
                $id,
                ['is_active' => $new]
            );
            $db->commit();
            $_SESSION['success'] = 'Phrase is now ' . ($new ? 'active' : 'inactive') . '.';
            header('Location: /platform/tenant/academic/report-phrases.php');
            exit;
        }

        // ---------- DELETE ----------
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $row = $db->fetchOne(
                "SELECT id FROM report_phrases
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Phrase not found.');

            $db->beginTransaction();
            $db->execute(
                "UPDATE report_phrases SET deleted_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.report_phrase.deleted',
                'report_phrases',
                $id,
                []
            );
            $db->commit();
            $_SESSION['success'] = 'Phrase deleted.';
            header('Location: /platform/tenant/academic/report-phrases.php');
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('report-phrases action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/academic/report-phrases.php');
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
$filterCategory = trim((string)($_GET['category'] ?? ''));
$filterBand     = trim((string)($_GET['band']     ?? ''));
$filterStatus   = trim((string)($_GET['status']   ?? 'active'));

$where  = ["rp.tenant_id = ?", "rp.deleted_at IS NULL"];
$params = [$tenantId];
if (in_array($filterCategory, RP_CATEGORIES, true)) {
    $where[]  = "rp.category = ?";
    $params[] = $filterCategory;
}
if (in_array($filterBand, RP_BANDS, true)) {
    $where[]  = "rp.band = ?";
    $params[] = $filterBand;
}
if ($filterStatus === 'active') {
    $where[] = "rp.is_active = 1";
} elseif ($filterStatus === 'inactive') {
    $where[] = "rp.is_active = 0";
}

$rows = $db->fetchAll(
    "SELECT rp.id, rp.uuid, rp.category, rp.band, rp.text, rp.weight,
            rp.is_active, rp.created_at, rp.updated_at
       FROM report_phrases rp
      WHERE " . implode(' AND ', $where) . "
      ORDER BY rp.category ASC, rp.band ASC, rp.weight DESC, rp.id ASC
      LIMIT 500",
    $params
);

$counts = [
    'total'    => 0,
    'active'   => 0,
    'inactive' => 0,
    'by_category' => array_fill_keys(RP_CATEGORIES, 0),
    'by_band'     => array_fill_keys(RP_BANDS, 0),
];
try {
    $cnt = $db->fetchOne(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_cnt
          FROM report_phrases
          WHERE tenant_id = ? AND deleted_at IS NULL",
        [$tenantId]
    );
    if ($cnt) {
        $counts['total']    = (int)$cnt['total'];
        $counts['active']   = (int)$cnt['active_cnt'];
        $counts['inactive'] = $counts['total'] - $counts['active'];
    }
    $catRows = $db->fetchAll(
        "SELECT category, COUNT(*) AS c FROM report_phrases
          WHERE tenant_id = ? AND deleted_at IS NULL
          GROUP BY category",
        [$tenantId]
    );
    foreach ($catRows as $cr) {
        $k = (string)$cr['category'];
        if (isset($counts['by_category'][$k])) $counts['by_category'][$k] = (int)$cr['c'];
    }
    $bandRows = $db->fetchAll(
        "SELECT band, COUNT(*) AS c FROM report_phrases
          WHERE tenant_id = ? AND deleted_at IS NULL
          GROUP BY band",
        [$tenantId]
    );
    foreach ($bandRows as $br) {
        $k = (string)$br['band'];
        if (isset($counts['by_band'][$k])) $counts['by_band'][$k] = (int)$br['c'];
    }
} catch (Exception $e) {
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

        .filters-bar {
            background: #fff;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        .filters-bar .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
            min-width: 160px;
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
            padding: 4px 10px;
        }

        .card-custom {
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
            font-size: 14px;
            font-weight: 700;
            margin: 0;
            color: #1a1a2e;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
        }

        .summary-cell {
            background: #fafbfc;
            border: 1px solid #eef0f3;
            border-radius: 10px;
            padding: 12px 14px;
            text-align: center;
        }

        .summary-cell .s-lbl {
            font-size: 11px;
            text-transform: uppercase;
            color: #6c757d;
            font-weight: 600;
            letter-spacing: 0.4px;
        }

        .summary-cell .s-val {
            font-size: 22px;
            font-weight: 700;
            color: #1a1a2e;
            font-family: 'Courier New', monospace;
            margin-top: 4px;
        }

        .summary-cell.blue .s-val {
            color: #004085;
        }

        .summary-cell.green .s-val {
            color: #166534;
        }

        .summary-cell.gray .s-val {
            color: #495057;
        }

        table.data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        table.data-table thead th {
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

        table.data-table tbody td {
            padding: 10px 14px;
            vertical-align: top;
            border-bottom: 1px solid #f0f2f5;
        }

        table.data-table tbody tr:last-child td {
            border-bottom: none;
        }

        table.data-table tbody tr:hover {
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

        .phrase-text {
            max-width: 520px;
            font-size: 12.5px;
            color: #1a1a2e;
            line-height: 1.5;
        }

        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: flex-end;
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
                        <h1><i class="fas fa-quote-left me-2"></i>Report Phrases</h1>
                        <p>Manage the phrase bank used by the report card</p>
                    </div>
                    <div class="header-actions">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createPhraseModal">
                            <i class="fas fa-plus me-2"></i> New phrase
                        </button>
                        <a href="/platform/tenant/reports/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-file-alt me-2"></i> Reports hub
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
                                <?php echo $counts['total']; ?> phrase(s) ·
                                <?php echo $counts['active']; ?> active ·
                                <?php echo $counts['inactive']; ?> inactive
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>Consumed by PhraseGenerator
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
                        <div class="ib-title">How the phrase bank works</div>
                        <div class="ib-text">
                            For each (<strong>category</strong>, <strong>band</strong>) pair, PhraseGenerator picks
                            the <strong>highest-weight active phrase</strong> as the string the report card renders.
                            Setting a phrase to weight 100 makes it the default; higher weights override; weight 0 is
                            last in line. Deactivating a phrase removes it from selection but preserves it for audit.
                            The six bands map to the composite score thresholds the generator computes.
                        </div>
                    </div>
                </div>

                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-chart-bar me-2 text-primary"></i>Coverage</h6>
                    </div>
                    <div class="card-body-custom">
                        <div class="summary-grid">
                            <div class="summary-cell blue">
                                <div class="s-lbl">Total</div>
                                <div class="s-val"><?php echo (int)$counts['total']; ?></div>
                            </div>
                            <div class="summary-cell green">
                                <div class="s-lbl">Active</div>
                                <div class="s-val"><?php echo (int)$counts['active']; ?></div>
                            </div>
                            <div class="summary-cell gray">
                                <div class="s-lbl">Inactive</div>
                                <div class="s-val"><?php echo (int)$counts['inactive']; ?></div>
                            </div>
                        </div>
                        <div style="margin-top:16px;padding-top:16px;border-top:1px dashed #eef0f3;">
                            <div style="font-size:12px;text-transform:uppercase;letter-spacing:0.4px;color:#6c757d;font-weight:600;margin-bottom:8px;">
                                Per category
                            </div>
                            <div class="summary-grid">
                                <?php foreach (RP_CATEGORIES as $c): ?>
                                    <div class="summary-cell">
                                        <div class="s-lbl"><?php echo h(categoryLabel($c)); ?></div>
                                        <div class="s-val"><?php echo (int)$counts['by_category'][$c]; ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div style="margin-top:16px;padding-top:16px;border-top:1px dashed #eef0f3;">
                            <div style="font-size:12px;text-transform:uppercase;letter-spacing:0.4px;color:#6c757d;font-weight:600;margin-bottom:8px;">
                                Per band
                            </div>
                            <div class="summary-grid">
                                <?php foreach (RP_BANDS as $b): ?>
                                    <div class="summary-cell">
                                        <div class="s-lbl"><?php echo h(bandLabel($b)); ?></div>
                                        <div class="s-val"><?php echo (int)$counts['by_band'][$b]; ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <form method="GET" action="/platform/tenant/academic/report-phrases.php" class="filters-bar">
                    <div class="filter-group">
                        <label><i class="fas fa-filter me-1"></i>Category</label>
                        <select name="category" class="form-select" onchange="this.form.submit()">
                            <option value="">All categories</option>
                            <?php foreach (RP_CATEGORIES as $c): ?>
                                <option value="<?php echo h($c); ?>" <?php echo $filterCategory === $c ? 'selected' : ''; ?>>
                                    <?php echo h(categoryLabel($c)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Band</label>
                        <select name="band" class="form-select" onchange="this.form.submit()">
                            <option value="">All bands</option>
                            <?php foreach (RP_BANDS as $b): ?>
                                <option value="<?php echo h($b); ?>" <?php echo $filterBand === $b ? 'selected' : ''; ?>>
                                    <?php echo h(bandLabel($b)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status" class="form-select" onchange="this.form.submit()">
                            <option value="active" <?php echo $filterStatus === 'active'   ? 'selected' : ''; ?>>Active only</option>
                            <option value="inactive" <?php echo $filterStatus === 'inactive' ? 'selected' : ''; ?>>Inactive only</option>
                            <option value="all" <?php echo $filterStatus === 'all'      ? 'selected' : ''; ?>>All</option>
                        </select>
                    </div>
                    <div class="filter-group" style="flex:0 0 auto;">
                        <a href="/platform/tenant/academic/report-phrases.php" class="btn-outline-secondary">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>

                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-list me-2 text-primary"></i>Phrase bank</h6>
                        <span style="font-size:12px;color:#6c757d;"><?php echo count($rows); ?> row(s)</span>
                    </div>
                    <?php if (empty($rows)): ?>
                        <div class="empty-state">
                            <i class="fas fa-quote-left"></i>
                            <h5>No phrases match this filter</h5>
                            <p>Adjust the filters, or create a new phrase.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th style="width:70px;">ID</th>
                                        <th style="min-width:150px;">Category</th>
                                        <th style="min-width:120px;">Band</th>
                                        <th style="min-width:380px;">Text</th>
                                        <th style="width:90px;text-align:center;">Weight</th>
                                        <th style="width:110px;text-align:center;">Status</th>
                                        <th style="text-align:right;min-width:220px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $r):
                                        $rid = (int)$r['id'];
                                    ?>
                                        <tr>
                                            <td style="font-family:'Courier New',monospace;font-size:12px;color:#6c757d;">
                                                #<?php echo $rid; ?>
                                            </td>
                                            <td>
                                                <span class="pill blue"><?php echo h(categoryLabel((string)$r['category'])); ?></span>
                                            </td>
                                            <td>
                                                <span class="pill <?php echo bandPill((string)$r['band']); ?>">
                                                    <?php echo h(bandLabel((string)$r['band'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="phrase-text">
                                                    <?php echo h((string)$r['text']); ?>
                                                </div>
                                            </td>
                                            <td style="text-align:center;font-family:'Courier New',monospace;font-weight:700;">
                                                <?php echo (int)$r['weight']; ?>
                                            </td>
                                            <td style="text-align:center;">
                                                <?php if ((int)$r['is_active'] === 1): ?>
                                                    <span class="pill green"><i class="fas fa-check"></i>Active</span>
                                                <?php else: ?>
                                                    <span class="pill gray"><i class="fas fa-times"></i>Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button type="button"
                                                        class="btn-outline-primary js-edit-phrase"
                                                        data-id="<?php echo $rid; ?>"
                                                        data-category="<?php echo h((string)$r['category']); ?>"
                                                        data-band="<?php echo h((string)$r['band']); ?>"
                                                        data-text="<?php echo h((string)$r['text']); ?>"
                                                        data-weight="<?php echo (int)$r['weight']; ?>"
                                                        data-active="<?php echo (int)$r['is_active']; ?>">
                                                        <i class="fas fa-edit me-1"></i> Edit
                                                    </button>
                                                    <form method="POST" action="/platform/tenant/academic/report-phrases.php" style="display:inline-block;margin:0;"
                                                        onsubmit="return confirm('Toggle active state for this phrase?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="toggle_active">
                                                        <input type="hidden" name="id" value="<?php echo $rid; ?>">
                                                        <button type="submit" class="btn-outline-warning">
                                                            <i class="fas fa-power-off me-1"></i> Toggle
                                                        </button>
                                                    </form>
                                                    <form method="POST" action="/platform/tenant/academic/report-phrases.php" style="display:inline-block;margin:0;"
                                                        onsubmit="return confirm('Delete this phrase? It will be soft-deleted and no longer selected by the generator.');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo $rid; ?>">
                                                        <button type="submit" class="btn-outline-danger">
                                                            <i class="fas fa-trash me-1"></i>
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
                </div>

            </main>
        </div>
    </div>

    <!-- CREATE MODAL -->
    <div class="modal fade" id="createPhraseModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/academic/report-phrases.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5><i class="fas fa-plus-circle text-primary me-2"></i>New report phrase</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="createCategory">Category</label>
                                <select class="form-select" id="createCategory" name="category" required>
                                    <?php foreach (RP_CATEGORIES as $c): ?>
                                        <option value="<?php echo h($c); ?>"><?php echo h(categoryLabel($c)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="createBand">Band</label>
                                <select class="form-select" id="createBand" name="band" required>
                                    <?php foreach (RP_BANDS as $b): ?>
                                        <option value="<?php echo h($b); ?>"><?php echo h(bandLabel($b)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="createText">Phrase text</label>
                            <textarea class="form-control" id="createText" name="text" rows="3" maxlength="5000" required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="createWeight">Weight</label>
                                <input type="number" class="form-control" id="createWeight" name="weight" min="0" max="9999" value="100">
                                <div class="form-text">Higher weight wins when multiple active phrases exist for the same (category, band).</div>
                            </div>
                            <div class="col-md-6 mb-3 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="createActive" name="is_active" value="1" checked>
                                    <label class="form-check-label" for="createActive">Active</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Create</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- EDIT MODAL -->
    <div class="modal fade" id="editPhraseModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/academic/report-phrases.php" id="editPhraseForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="editId" value="">
                    <div class="modal-header">
                        <h5><i class="fas fa-edit text-primary me-2"></i>Edit report phrase</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="editCategory">Category</label>
                                <select class="form-select" id="editCategory" name="category" required>
                                    <?php foreach (RP_CATEGORIES as $c): ?>
                                        <option value="<?php echo h($c); ?>"><?php echo h(categoryLabel($c)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="editBand">Band</label>
                                <select class="form-select" id="editBand" name="band" required>
                                    <?php foreach (RP_BANDS as $b): ?>
                                        <option value="<?php echo h($b); ?>"><?php echo h(bandLabel($b)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="editText">Phrase text</label>
                            <textarea class="form-control" id="editText" name="text" rows="3" maxlength="5000" required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="editWeight">Weight</label>
                                <input type="number" class="form-control" id="editWeight" name="weight" min="0" max="9999" required>
                            </div>
                            <div class="col-md-6 mb-3 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="editActive" name="is_active" value="1">
                                    <label class="form-check-label" for="editActive">Active</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Save changes</button>
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
            const modalEl = document.getElementById('editPhraseModal');
            if (!modalEl) return;
            const modal = new bootstrap.Modal(modalEl);
            const idEl = document.getElementById('editId');
            const catEl = document.getElementById('editCategory');
            const bandEl = document.getElementById('editBand');
            const textEl = document.getElementById('editText');
            const weightEl = document.getElementById('editWeight');
            const activeEl = document.getElementById('editActive');

            document.querySelectorAll('.js-edit-phrase').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    idEl.value = this.getAttribute('data-id') || '';
                    catEl.value = this.getAttribute('data-category') || '';
                    bandEl.value = this.getAttribute('data-band') || '';
                    textEl.value = this.getAttribute('data-text') || '';
                    weightEl.value = this.getAttribute('data-weight') || '100';
                    activeEl.checked = (this.getAttribute('data-active') || '0') === '1';
                    modal.show();
                });
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