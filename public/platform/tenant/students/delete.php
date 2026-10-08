<?php

/**
 * Delete Student - Soft delete a student
 *
 * @package EduTrack
 * @subpackage Tenant\Students
 * @version 1.0
 * @filepath public/tenant/students/delete.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Students delete file of the students-surface sweep. Unlike
 *   the other files in the sweep, this file renders no HTML. It
 *   is a pure POST handler: it reads student_id and confirm from
 *   $_POST, soft-deletes the student row, sets a session flash,
 *   and redirects. It carries no inline sidebar and no $pageTitle.
 *   Two of the sweep's three changes therefore do not apply:
 *     - The sidebar include has no inline <nav> block to replace.
 *     - The brand rename has no user-facing $pageTitle string.
 *   The one change that applies is the version unification:
 *   @version 2.0 becomes @version 1.0. Every other line of the
 *   file is byte-identical to the previous version (2.0). The
 *   @package tag remains 'EduTrack' — it names the codebase
 *   package, not the product.
 *
 * Notes on facts not in the sweep's scope (recorded, not fixed):
 *   - @filepath reads 'public/tenant/students/delete.php' — the
 *     actual path is 'public/platform/tenant/students/delete.php'.
 *   - @subpackage reads 'Tenant\Students' — sibling files read
 *     'Platform\Tenant\Students'.
 *   - Redirect targets read '/tenant/students/index.php' and
 *     '/platform/students/delete.php' — the actual paths carry
 *     the '/platform' prefix.
 */

// =============================================
// SESSION START
// =============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =============================================
// AUTHENTICATION CHECK
// =============================================
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/tenant/login.php');
    exit;
}

// Check if user is Super Admin - redirect to platform
if (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] === true) {
    header('Location: /platform/students/delete.php');
    exit;
}

// Get tenant ID from session
$tenantId = $_SESSION['tenant_id'] ?? 0;

if (!$tenantId) {
    header('Location: /platform/tenant/select.php');
    exit;
}

// =============================================
// LOAD CONFIG AND DATABASE HELPER
// =============================================
$projectRoot = dirname(__DIR__, 3);

if (file_exists($projectRoot . '/config/config.php')) {
    require_once $projectRoot . '/config/config.php';
} else {
    die('config.php not found!');
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// =============================================
// HANDLE DELETE REQUEST
// =============================================
$studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
$confirm = isset($_POST['confirm']) && $_POST['confirm'] === 'yes';

if (!$studentId) {
    $_SESSION['error'] = 'Invalid student ID';
    header('Location: /tenant/students/index.php');
    exit;
}

// Get student details
$student = $db->fetchOne(
    "SELECT id, first_name, last_name, student_number
     FROM students
     WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
    [$studentId, $tenantId]
);

if (!$student) {
    $_SESSION['error'] = 'Student not found';
    header('Location: /tenant/students/index.php');
    exit;
}

// Handle confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $confirm) {
    try {
        $result = $db->execute(
            "UPDATE students SET deleted_at = NOW() WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$studentId, $tenantId]
        );

        if ($result) {
            $_SESSION['success'] = 'Student "' . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . '" deleted successfully!';
            header('Location: /tenant/students/index.php');
            exit;
        } else {
            throw new Exception('Failed to delete student');
        }
    } catch (Exception $e) {
        error_log('Delete student error: ' . $e->getMessage());
        $_SESSION['error'] = 'Error deleting student: ' . $e->getMessage();
        header('Location: /tenant/students/index.php');
        exit;
    }
}

// If not confirmed, redirect back with error
$_SESSION['error'] = 'Delete confirmation required';
header('Location: /tenant/students/index.php');
exit;
