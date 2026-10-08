<?php

/**
 * Select/Change Campus Context - Super Admin
 *
 * @package EduTrack
 * @subpackage Platform\Campuses
 * @filepath public/platform/campuses/select.php
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

$campusId = isset($_POST['campus_id']) ? (int)$_POST['campus_id'] : 0;
if ($campusId <= 0) {
    $_SESSION['error'] = 'Invalid campus ID.';
    header('Location: /platform/campuses/index.php');
    exit;
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// Get campus details
$campus = $db->fetchOne(
    "SELECT c.*, s.school_name, t.tenant_name 
     FROM campuses c 
     LEFT JOIN schools s ON c.school_id = s.id 
     LEFT JOIN tenants t ON s.tenant_id = t.id 
     WHERE c.id = ? AND c.deleted_at IS NULL",
    [$campusId]
);

if (!$campus) {
    $_SESSION['error'] = 'Campus not found.';
    header('Location: /platform/campuses/index.php');
    exit;
}

// Set session variables for campus context
$_SESSION['selected_campus_id'] = $campus['id'];
$_SESSION['selected_campus_name'] = $campus['campus_name'];
$_SESSION['selected_campus_code'] = $campus['campus_code'];
$_SESSION['selected_school_id'] = $campus['school_id'];
$_SESSION['selected_school_name'] = $campus['school_name'];
$_SESSION['selected_tenant_id'] = $campus['tenant_id'];
$_SESSION['selected_tenant_name'] = $campus['tenant_name'];

// Determine redirect URL
$redirect = isset($_POST['redirect']) ? $_POST['redirect'] : '/platform/campuses/view.php?id=' . $campusId;

$_SESSION['success'] = 'Switched to campus: ' . $campus['campus_name'];

header('Location: ' . $redirect);
exit;
