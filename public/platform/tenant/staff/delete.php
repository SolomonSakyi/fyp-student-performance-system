<?php

/**
 * Delete Staff - Soft delete a staff member
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Staff
 * @version 1.0
 * @filepath public/platform/tenant/staff/delete.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Staff delete file of the tenant-surface sweep. Unlike the
 *   other files in the sweep, this file renders no HTML. It is a
 *   pure POST handler: it reads the staff id from $_GET, verifies
 *   the staff member belongs to the current tenant, soft-deletes
 *   the staff row and every dependent row (person, emergency
 *   contacts, qualifications, licenses, academic assignments,
 *   payroll, onboarding), writes an audit log entry, sets a
 *   session flash, and redirects. It carries no inline sidebar
 *   and no $pageTitle. Two of the sweep's three changes therefore
 *   do not apply:
 *     - The sidebar include has no inline <nav> block to replace.
 *     - The brand rename has no user-facing $pageTitle string.
 *   The one change that applies is the version unification:
 *   @version 2.0 becomes @version 1.0. Every other line of the
 *   file is byte-identical to the previous version (2.0). The
 *   @package tag remains 'EduTrack' — it names the codebase
 *   package, not the product.
 */

// ==============================================
// SESSION & AUTHENTICATION
// ==============================================
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/tenant/login.php');
    exit;
}

// ==============================================
// ORGANIZATIONAL CONTEXT
// ==============================================
$tenantId = $_SESSION['tenant_id'] ?? 0;
$userId = $_SESSION['user_id'] ?? 0;

if (!$tenantId) {
    header('Location: /platform/tenants/select.php');
    exit;
}

// ==============================================
// DATABASE & CONFIGURATION
// ==============================================
$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// ==============================================
// GET STAFF ID
// ==============================================
$staffId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($staffId <= 0) {
    $_SESSION['errors'] = ['Invalid staff ID.'];
    header('Location: /platform/tenant/staff/index.php');
    exit;
}

// ==============================================
// CHECK STAFF EXISTS AND BELONGS TO TENANT
// ==============================================
$staff = $db->fetchOne(
    "SELECT id, staff_number, person_id FROM staff 
     WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
    [$staffId, $tenantId]
);

if (!$staff) {
    $_SESSION['errors'] = ['Staff not found or you do not have permission to delete this staff member.'];
    header('Location: /platform/tenant/staff/index.php');
    exit;
}

// ==============================================
// PERFORM SOFT DELETE
// ==============================================
try {
    $db->beginTransaction();

    // Soft delete staff record
    $db->execute(
        "UPDATE staff SET deleted_at = NOW(), updated_at = NOW() WHERE id = ? AND tenant_id = ?",
        [$staffId, $tenantId]
    );

    // Soft delete person record
    $db->execute(
        "UPDATE persons SET deleted_at = NOW() WHERE id = ?",
        [$staff['person_id']]
    );

    // Soft delete emergency contacts
    $db->execute(
        "UPDATE staff_emergency_contacts SET deleted_at = NOW() WHERE staff_id = ?",
        [$staffId]
    );

    // Soft delete qualifications
    $db->execute(
        "UPDATE staff_qualifications SET deleted_at = NOW() WHERE staff_id = ?",
        [$staffId]
    );

    // Soft delete licenses
    $db->execute(
        "UPDATE staff_licenses SET deleted_at = NOW() WHERE staff_id = ?",
        [$staffId]
    );

    // Soft delete academic assignments
    $db->execute(
        "UPDATE staff_academic_assignments SET deleted_at = NOW() WHERE staff_id = ?",
        [$staffId]
    );

    // Soft delete payroll
    $db->execute(
        "UPDATE staff_payroll SET deleted_at = NOW() WHERE staff_id = ?",
        [$staffId]
    );

    // Soft delete onboarding
    $db->execute(
        "UPDATE staff_onboarding SET deleted_at = NOW() WHERE staff_id = ?",
        [$staffId]
    );

    // Audit log
    try {
        $db->execute(
            "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, tenant_id, details, created_at)
             VALUES (?, 'staff_deleted', 'staff', ?, ?, ?, NOW())",
            [
                $userId,
                $staffId,
                $tenantId,
                json_encode([
                    'staff_number' => $staff['staff_number'],
                    'staff_id' => $staffId
                ])
            ]
        );
    } catch (Exception $e) {
        error_log('Audit log error: ' . $e->getMessage());
    }

    $db->commit();

    $_SESSION['success'] = 'Staff member #' . htmlspecialchars($staff['staff_number']) . ' deleted successfully.';
    header('Location: /platform/tenant/staff/index.php');
    exit;
} catch (Exception $e) {
    $db->rollBack();
    error_log('Staff delete error: ' . $e->getMessage());
    $_SESSION['errors'] = ['Error deleting staff: ' . $e->getMessage()];
    header('Location: /platform/tenant/staff/index.php');
    exit;
}
