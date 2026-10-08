<?php
/**
 * Delete Campus - Super Admin
 *
 * @package EduTrack
 * @subpackage Platform\Campuses
 * @filepath public/platform/campuses/delete.php
 * @version 2.0
 */

$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/config/config.php';

session_start();

// Check authentication and super admin role
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/tenant/login.php');
    exit;
}

$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;
if (!$isSuperAdmin) {
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /platform/campuses/index.php');
    exit;
}

$campusId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($campusId <= 0) {
    $_SESSION['error'] = 'Invalid campus ID.';
    header('Location: /platform/campuses/index.php');
    exit;
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

$campus = $db->fetchOne(
    "SELECT campus_name FROM campuses WHERE id = ? AND deleted_at IS NULL",
    [$campusId]
);

if (!$campus) {
    $_SESSION['error'] = 'Campus not found.';
    header('Location: /platform/campuses/index.php');
    exit;
}

try {
    $db->execute(
        "UPDATE campuses SET deleted_at = NOW() WHERE id = ?",
        [$campusId]
    );
    $_SESSION['success'] = 'Campus "' . $campus['campus_name'] . '" deleted successfully.';
} catch (Exception $e) {
    $_SESSION['error'] = 'Error deleting campus: ' . $e->getMessage();
}

header('Location: /platform/campuses/index.php');
exit;