<?php

/**
 * Get Schools by Tenant - AJAX endpoint
 *
 * @package EduTrack
 * @subpackage Platform\Campuses
 * @filepath public/platform/campuses/get_schools.php
 * @version 2.0
 */

$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/config/config.php';

session_start();

// Check authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$tenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;

if ($tenantId <= 0) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

$schools = $db->fetchAll(
    "SELECT id, school_name FROM schools WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY school_name",
    [$tenantId]
);

header('Content-Type: application/json');
echo json_encode($schools);
exit;
