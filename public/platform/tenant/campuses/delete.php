<?php

/**
 * Delete Campus - Soft delete a campus
 * 
 * @package EduTrack
 * @subpackage Platform\Tenant\Campuses
 * @version 1.0
 * @filepath public/platform/tenant/campuses/delete.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Ninth file of the tenant-surface sweep. Unlike the other
 *   files in the sweep, this file renders no HTML. It is a pure
 *   POST handler: it reads campus_id and confirm from $_POST,
 *   soft-deletes the campus row, sets a session flash, and
 *   redirects. It carries no inline sidebar and no $pageTitle.
 *   Two of the sweep's three changes therefore do not apply:
 *     - The sidebar include has no inline <nav> block to replace.
 *     - The brand rename has no user-facing $pageTitle string.
 *   The one change that applies is the version unification:
 *   @version 2.0 becomes @version 1.0. Every other line of the
 *   file is byte-identical to the previous version (2.0). The
 *   @package tag remains 'EduTrack' — it names the codebase
 *   package, not the product.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/tenant/login.php');
    exit;
}

if (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] === true) {
    header('Location: /platform/campuses/delete.php');
    exit;
}

$tenantId = $_SESSION['tenant_id'] ?? 0;
if (!$tenantId) {
    header('Location: /platform/tenant/login.php?error=no_tenant');
    exit;
}

$projectRoot = dirname(__DIR__, 4);
if (file_exists($projectRoot . '/config/config.php')) {
    require_once $projectRoot . '/config/config.php';
} else {
    die('config.php not found!');
}
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

$campusId = isset($_POST['campus_id']) ? (int)$_POST['campus_id'] : 0;
$confirm = isset($_POST['confirm']) && $_POST['confirm'] === 'yes';

if (!$campusId) {
    $_SESSION['error'] = 'Invalid campus ID';
    header('Location: /platform/tenant/campuses/index.php');
    exit;
}

$campus = $db->fetchOne(
    "SELECT id, campus_name FROM campuses WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
    [$campusId, $tenantId]
);

if (!$campus) {
    $_SESSION['error'] = 'Campus not found';
    header('Location: /platform/tenant/campuses/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $confirm) {
    try {
        $result = $db->execute(
            "UPDATE campuses SET deleted_at = NOW() WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$campusId, $tenantId]
        );

        if ($result) {
            $_SESSION['success'] = 'Campus "' . htmlspecialchars($campus['campus_name']) . '" deleted successfully!';
            header('Location: /platform/tenant/campuses/index.php');
            exit;
        } else {
            throw new Exception('Failed to delete campus');
        }
    } catch (Exception $e) {
        error_log('Delete campus error: ' . $e->getMessage());
        $_SESSION['error'] = 'Error deleting campus: ' . $e->getMessage();
        header('Location: /platform/tenant/campuses/index.php');
        exit;
    }
}

$_SESSION['error'] = 'Delete confirmation required';
header('Location: /platform/tenant/campuses/index.php');
exit;
