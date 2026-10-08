<?php

/**
 * Save School Terminology
 * Simple endpoint to save terminology selection
 * @package EduTrack
 * @subpackage Platform\Schools\Settings
 * @version 1.0
 */

session_start();

// Set headers
header('Content-Type: application/json');

// Get parameters
$schoolId = isset($_POST['school_id']) ? (int)$_POST['school_id'] : 0;
$terminology = isset($_POST['terminology']) ? $_POST['terminology'] : 'basic';

if ($schoolId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid school ID']);
    exit;
}

// Validate terminology
$allowed = ['basic', 'class', 'grade', 'custom'];
if (!in_array($terminology, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Invalid terminology type']);
    exit;
}

// Save to session (temporary storage until database is ready)
$_SESSION['school_terminology_' . $schoolId] = $terminology;

echo json_encode(['success' => true, 'message' => 'Terminology updated successfully']);
