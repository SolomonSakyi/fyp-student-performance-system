<?php

/**
 * Save School Level Status
 * Simple endpoint to toggle level active status
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
$isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

if ($schoolId <= 0 || empty($levelCode)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Save to session (temporary storage until database is ready)
$key = 'school_level_' . $schoolId . '_' . $levelCode;
$_SESSION[$key] = $isActive;

echo json_encode(['success' => true, 'message' => 'Level updated successfully']);
