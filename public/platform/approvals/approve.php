<?php

/**
 * Approve/Reject Request Handler with Notifications
 *
 * @package EduTrack
 * @subpackage Platform\Approvals
 * @version 1.0
 * @filepath public/platform/approvals/approve.php
 *
 * v1.0 change (2026-10-08) [SWEEP + CSRF]:
 *   Platform-approvals sweep. Three changes:
 *
 *   - CSRF gate. A verify_csrf() call is added at the top of the
 *     POST handling, guarding this state-changing endpoint. Prior
 *     to this change the handler trusted $_POST completely: any
 *     page that could post a form to this endpoint on behalf of a
 *     logged-in super admin could approve or reject requests. The
 *     verify_csrf() call matches the project convention used by
 *     the guardian requests.php v1.1.2, the tenant notifications.php
 *     v2.6, and the tenant academic files. The two JS-built forms
 *     on public/platform/approvals/index.php now carry the
 *     csrf_token field this call reads.
 *
 *   - Flash-message typo. The success message previously read
 *     ucfirst($requestType) . ' request ' . $action . 'd successfully!',
 *     which printed "approve d" and "reject d" instead of "approved"
 *     and "rejected". The message now reads correctly.
 *
 *   - This v1.0 dockblock paragraph was added above the previous
 *     bare two-line comment. The @package, @subpackage, @version,
 *     and @filepath tags are now present.
 *
 *   The auth guards ($_SESSION['logged_in'] and
 *   $_SESSION['is_super_admin']), the config.php require, the
 *   DatabaseHelper require, the six table reads, the four write
 *   paths, the transaction wrapping, the UUID generation, the
 *   notification insert, the activity-log insert, and every other
 *   line are byte-identical to the file as pasted.
 *
 *   Class names, table names, database name, and file paths remain
 *   EduTrack per X-1. The @package line stays 'EduTrack'.
 */

// =============================================
// SESSION START
// =============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =============================================
// AUTHENTICATION CHECK - Super Admin Only
// =============================================
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

if (!isset($_SESSION['is_super_admin']) || $_SESSION['is_super_admin'] !== true) {
    header('Location: /platform/tenant/dashboard.php');
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
// CSRF GATE
// =============================================
if (function_exists('verify_csrf')) {
    verify_csrf();
} elseif (class_exists('Security')) {
    Security::verifyCsrf();
}

// =============================================
// GET POST DATA
// =============================================
$requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$requestType = isset($_POST['request_type']) ? trim($_POST['request_type']) : '';
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$rejectionReason = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : '';

// =============================================
// VALIDATE INPUT
// =============================================
if (!$requestId || !$requestType || !$action) {
    $_SESSION['approval_error'] = 'Invalid request data.';
    header('Location: /platform/approvals/index.php');
    exit;
}

if (!in_array($requestType, ['school', 'campus'])) {
    $_SESSION['approval_error'] = 'Invalid request type.';
    header('Location: /platform/approvals/index.php');
    exit;
}

if (!in_array($action, ['approve', 'reject'])) {
    $_SESSION['approval_error'] = 'Invalid action.';
    header('Location: /platform/approvals/index.php');
    exit;
}

if ($action === 'reject' && empty($rejectionReason)) {
    $_SESSION['approval_error'] = 'Please provide a reason for rejection.';
    header('Location: /platform/approvals/index.php');
    exit;
}

// =============================================
// GET USER ID
// =============================================
$reviewedBy = $_SESSION['user_id'] ?? 0;

// =============================================
// CHECK TABLE COLUMNS
// =============================================
$tableName = $requestType === 'school' ? 'school_requests' : 'campus_requests';

try {
    $columns = $db->fetchAll("SHOW COLUMNS FROM $tableName");
} catch (Exception $e) {
    $_SESSION['approval_error'] = 'Table not found: ' . $tableName;
    header('Location: /platform/approvals/index.php');
    exit;
}

$columnNames = array_column($columns, 'Field');
$hasDeletedAt = in_array('deleted_at', $columnNames);
$hasReviewNotes = in_array('review_notes', $columnNames);

try {
    // =============================================
    // GET REQUEST DATA
    // =============================================
    $sql = "SELECT * FROM $tableName WHERE id = ?";
    if ($hasDeletedAt) {
        $sql .= " AND (deleted_at IS NULL OR deleted_at = '')";
    }
    $request = $db->fetchOne($sql, [$requestId]);

    if (!$request) {
        $_SESSION['approval_error'] = 'Request not found.';
        header('Location: /platform/approvals/index.php');
        exit;
    }

    if ($request['status'] !== 'pending') {
        $_SESSION['approval_error'] = 'This request has already been processed.';
        header('Location: /platform/approvals/index.php');
        exit;
    }

    // =============================================
    // BEGIN TRANSACTION
    // =============================================
    $transactionStarted = false;
    try {
        $db->beginTransaction();
        $transactionStarted = true;
    } catch (Exception $e) {
        error_log('Transaction start error: ' . $e->getMessage());
    }

    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    $notes = $action === 'reject' ? $rejectionReason : ($_POST['approval_notes'] ?? '');

    // =============================================
    // UPDATE REQUEST STATUS
    // =============================================
    $updateFields = ["status = ?", "reviewed_by = ?", "reviewed_at = NOW()"];
    $updateParams = [$newStatus, $reviewedBy];

    if ($hasReviewNotes) {
        $updateFields[] = "review_notes = ?";
        $updateParams[] = $notes;
    }

    $updateSql = "UPDATE $tableName SET " . implode(", ", $updateFields) . " WHERE id = ?";
    $updateParams[] = $requestId;

    if ($hasDeletedAt) {
        $updateSql .= " AND (deleted_at IS NULL OR deleted_at = '')";
    }

    $result = $db->execute($updateSql, $updateParams);

    if (!$result) {
        throw new Exception('Failed to update request status.');
    }

    // =============================================
    // IF APPROVED, CREATE THE RESOURCE
    // =============================================
    $createdResourceId = 0;
    $resourceName = '';

    if ($action === 'approve') {
        // Generate UUID
        $uuid = $db->generateUuid();

        if ($requestType === 'school') {
            // Check what columns exist in schools table
            $schoolColumns = $db->fetchAll("SHOW COLUMNS FROM schools");
            $schoolColumnNames = array_column($schoolColumns, 'Field');

            // Build insert based on available columns
            $insertFields = ['uuid', 'tenant_id', 'school_name', 'school_code', 'status', 'created_at', 'updated_at'];
            $params = [
                $uuid,
                $request['tenant_id'],
                $request['school_name'],
                $request['school_code'],
                'active'
            ];
            $placeholders = ['?', '?', '?', '?', '?', 'NOW()', 'NOW()'];

            if (in_array('school_type', $schoolColumnNames) && !empty($request['school_type'])) {
                $insertFields[] = 'school_type';
                $placeholders[] = '?';
                $params[] = $request['school_type'];
            }

            if (in_array('email', $schoolColumnNames) && !empty($request['email'])) {
                $insertFields[] = 'email';
                $placeholders[] = '?';
                $params[] = $request['email'];
            }

            if (in_array('phone', $schoolColumnNames) && !empty($request['phone'])) {
                $insertFields[] = 'phone';
                $placeholders[] = '?';
                $params[] = $request['phone'];
            }

            if (in_array('address', $schoolColumnNames) && !empty($request['address'])) {
                $insertFields[] = 'address';
                $placeholders[] = '?';
                $params[] = $request['address'];
            }

            $schoolSql = "INSERT INTO schools (" . implode(", ", $insertFields) . ") 
                          VALUES (" . implode(", ", $placeholders) . ")";

            $createdResourceId = $db->insert($schoolSql, $params);

            if (!$createdResourceId) {
                throw new Exception('Failed to create school.');
            }
            $resourceName = $request['school_name'];
        } else {
            // Create the campus
            $campusColumns = $db->fetchAll("SHOW COLUMNS FROM campuses");
            $campusColumnNames = array_column($campusColumns, 'Field');

            $insertFields = ['uuid', 'tenant_id', 'school_id', 'campus_name', 'campus_code', 'status', 'created_at', 'updated_at'];
            $params = [
                $uuid,
                $request['tenant_id'],
                $request['school_id'],
                $request['campus_name'],
                $request['campus_code'],
                'active'
            ];
            $placeholders = ['?', '?', '?', '?', '?', '?', 'NOW()', 'NOW()'];

            if (in_array('email', $campusColumnNames) && !empty($request['email'])) {
                $insertFields[] = 'email';
                $placeholders[] = '?';
                $params[] = $request['email'];
            }

            if (in_array('phone', $campusColumnNames) && !empty($request['phone'])) {
                $insertFields[] = 'phone';
                $placeholders[] = '?';
                $params[] = $request['phone'];
            }

            if (in_array('address', $campusColumnNames) && !empty($request['address'])) {
                $insertFields[] = 'address';
                $placeholders[] = '?';
                $params[] = $request['address'];
            }

            $campusSql = "INSERT INTO campuses (" . implode(", ", $insertFields) . ") 
                          VALUES (" . implode(", ", $placeholders) . ")";

            $createdResourceId = $db->insert($campusSql, $params);

            if (!$createdResourceId) {
                throw new Exception('Failed to create campus.');
            }
            $resourceName = $request['campus_name'];
        }
    }

    // =============================================
    // CREATE NOTIFICATION FOR TENANT ADMIN
    // =============================================
    try {
        $notificationType = $action === 'approve' ? 'approved' : 'rejected';
        $requestName = $requestType === 'school' ? $request['school_name'] : $request['campus_name'];
        $notificationMessage = $action === 'approve'
            ? 'Your ' . $requestType . ' request for "' . $resourceName . '" has been approved! 🎉'
            : 'Your ' . $requestType . ' request for "' . $requestName . '" was rejected. Reason: ' . $rejectionReason;

        $link = $requestType === 'school'
            ? '/platform/tenant/schools/view.php?id=' . $createdResourceId
            : '/platform/tenant/campuses/view.php?id=' . $createdResourceId;

        // Check if notifications table exists
        $notifTableExists = $db->getValue(
            "SELECT COUNT(*) FROM information_schema.tables 
             WHERE table_schema = ? AND table_name = 'notifications'",
            [DB_NAME]
        );

        if ($notifTableExists > 0) {
            $notificationSql = "INSERT INTO notifications (
                user_id, tenant_id, type, title, message, link, is_read, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())";

            $db->execute($notificationSql, [
                $request['requested_by'],
                $request['tenant_id'],
                $notificationType,
                ucfirst($requestType) . ' Request ' . ucfirst($notificationType),
                $notificationMessage,
                $link
            ]);
        }
    } catch (Exception $e) {
        error_log('Notification error: ' . $e->getMessage());
    }

    // =============================================
    // LOG ACTIVITY
    // =============================================
    try {
        $logTableExists = $db->getValue(
            "SELECT COUNT(*) FROM information_schema.tables 
             WHERE table_schema = ? AND table_name = 'approval_activity_log'",
            [DB_NAME]
        );

        if ($logTableExists > 0) {
            $logSql = "INSERT INTO approval_activity_log (
                request_type, request_id, action, actor_id, notes, created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())";

            $db->execute($logSql, [
                $requestType,
                $requestId,
                $action,
                $reviewedBy,
                $notes
            ]);
        }
    } catch (Exception $e) {
        error_log('Activity log error: ' . $e->getMessage());
    }

    // =============================================
    // COMMIT TRANSACTION
    // =============================================
    if ($transactionStarted) {
        try {
            $db->commit();
        } catch (Exception $e) {
            error_log('Commit error: ' . $e->getMessage());
        }
    }

    $verb = $action === 'approve' ? 'approved' : 'rejected';
    $_SESSION['approval_success'] = ucfirst($requestType) . ' request ' . $verb . ' successfully!';
    header('Location: /platform/approvals/index.php');
    exit;
} catch (Exception $e) {
    // Only rollback if transaction was started
    if (isset($transactionStarted) && $transactionStarted) {
        try {
            $db->rollBack();
        } catch (Exception $rollbackError) {
            error_log('Rollback error: ' . $rollbackError->getMessage());
        }
    }
    error_log('Approval error: ' . $e->getMessage());
    error_log('Approval trace: ' . $e->getTraceAsString());
    $_SESSION['approval_error'] = 'Error processing request: ' . $e->getMessage();
    header('Location: /platform/approvals/index.php');
    exit;
}
