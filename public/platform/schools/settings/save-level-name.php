<?php

/**
 * Save School Level Custom Name
 * Simple endpoint to save custom level name
 * @package EduTrack
 * @subpackage Platform\Schools\Settings
 * @version 1.0
 */

session_start();

// Set headers
header('Content-Type: application/json');

// Get parameters
$schoolId = isset($_POST['school_id']) ? (int)$_POST['school_id'] : 0;
$levelCode = isset($_POST['level_code']) ? $_POST['level_code'] : '';
$customName = isset($_POST['custom_name']) ? trim($_POST['custom_name']) : '';

if ($schoolId <= 0 || empty($levelCode) || empty($customName)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Save to session (temporary storage until database is ready)
$key = 'school_level_name_' . $schoolId . '_' . $levelCode;
$_SESSION[$key] = $customName;

echo json_encode(['success' => true, 'message' => 'Level name updated successfully']);
