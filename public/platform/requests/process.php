<?php

/**
 * Process Requests - Super Admin
 * Approve or reject school/campus requests
 *
 * @package EduTrack
 * @subpackage Platform\Requests
 * @filepath public/platform/requests/process.php
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
    header('Location: /platform/requests/index.php');
    exit;
}

$type = $_POST['type'] ?? '';
$id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';
$reviewNotes = trim($_POST['review_notes'] ?? '');

if (empty($type) || $id <= 0 || empty($action)) {
    $_SESSION['error'] = 'Invalid request data.';
    header('Location: /platform/requests/index.php');
    exit;
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

$userId = $_SESSION['user_id'] ?? 0;

try {
    if ($type === 'school') {
        // Get the request
        $request = $db->fetchOne("SELECT * FROM school_requests WHERE id = ? AND status = 'pending'", [$id]);
        if (!$request) {
            $_SESSION['error'] = 'Request not found or already processed.';
            header('Location: /platform/requests/index.php');
            exit;
        }

        if ($action === 'approve') {
            // Create the school
            $schoolId = $db->insert(
                "INSERT INTO schools (uuid, tenant_id, school_name, school_code, email, phone, address, status) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'active')",
                [
                    $db->generateUuid(),
                    $request['tenant_id'],
                    $request['school_name'],
                    $request['school_code'],
                    $request['email'],
                    $request['phone'],
                    $request['address']
                ]
            );

            if ($schoolId) {
                // Update request status
                $db->execute(
                    "UPDATE school_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_notes = ? WHERE id = ?",
                    [$userId, $reviewNotes, $id]
                );
                $_SESSION['success'] = 'School "' . $request['school_name'] . '" created successfully!';
            } else {
                $_SESSION['error'] = 'Failed to create school.';
            }
        } elseif ($action === 'reject') {
            // Update request status
            $db->execute(
                "UPDATE school_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_notes = ? WHERE id = ?",
                [$userId, $reviewNotes, $id]
            );
            $_SESSION['success'] = 'School request "' . $request['school_name'] . '" rejected.';
        } else {
            $_SESSION['error'] = 'Invalid action.';
        }
    } elseif ($type === 'campus') {
        // Get the request
        $request = $db->fetchOne("SELECT * FROM campus_requests WHERE id = ? AND status = 'pending'", [$id]);
        if (!$request) {
            $_SESSION['error'] = 'Request not found or already processed.';
            header('Location: /platform/requests/index.php');
            exit;
        }

        if ($action === 'approve') {
            // Create the campus
            $campusId = $db->insert(
                "INSERT INTO campuses (uuid, tenant_id, school_id, campus_name, campus_code, address, status) 
                 VALUES (?, ?, ?, ?, ?, ?, 'active')",
                [
                    $db->generateUuid(),
                    $request['tenant_id'],
                    $request['school_id'],
                    $request['campus_name'],
                    $request['campus_code'],
                    $request['address']
                ]
            );

            if ($campusId) {
                // Update request status
                $db->execute(
                    "UPDATE campus_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_notes = ? WHERE id = ?",
                    [$userId, $reviewNotes, $id]
                );
                $_SESSION['success'] = 'Campus "' . $request['campus_name'] . '" created successfully!';
            } else {
                $_SESSION['error'] = 'Failed to create campus.';
            }
        } elseif ($action === 'reject') {
            // Update request status
            $db->execute(
                "UPDATE campus_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_notes = ? WHERE id = ?",
                [$userId, $reviewNotes, $id]
            );
            $_SESSION['success'] = 'Campus request "' . $request['campus_name'] . '" rejected.';
        } else {
            $_SESSION['error'] = 'Invalid action.';
        }
    } else {
        $_SESSION['error'] = 'Invalid request type.';
    }
} catch (Exception $e) {
    $_SESSION['error'] = 'Error processing request: ' . $e->getMessage();
}

header('Location: /platform/requests/index.php');
exit;
