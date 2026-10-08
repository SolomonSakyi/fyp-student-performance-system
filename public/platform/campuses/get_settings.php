<?php

/**
 * Get Campus Settings - API endpoint
 *
 * @package EduTrack
 * @subpackage Platform\Campuses
 * @filepath public/platform/campuses/get_settings.php
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

$campusId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($campusId <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid campus ID']);
    exit;
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// Get campus settings
$settings = $db->fetchOne(
    "SELECT * FROM campus_settings WHERE campus_id = ?",
    [$campusId]
);

if (!$settings) {
    // Return default settings
    $settings = [
        'campus_id' => $campusId,
        'timezone' => 'Africa/Accra',
        'language' => 'en',
        'currency' => 'GHS',
        'term_system' => 'Term',
        'terms_per_year' => 3,
        'max_students_per_class' => 40,
        'use_guardian_portal' => 1,
        'enable_online_registration' => 1,
        'enable_parent_app' => 1,
    ];
}

header('Content-Type: application/json');
echo json_encode($settings);
exit;
