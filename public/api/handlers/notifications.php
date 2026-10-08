<?php
/**
 * notifications.php
 * 
 * Notifications Handler
 * Manages attendance notifications
 * 
 * @package EduTrack
 * @subpackage API
 */

function handleNotifications($params, $method, $action)
{
    // Authenticate user
    try {
        $user = ApiAuth::authenticateUser();
    } catch (Exception $e) {
        ApiResponse::unauthorized('Authentication required');
    }

    switch ($method) {
        case 'GET':
            handleGetNotifications($params);
            break;

        case 'POST':
            handleSendNotification($params);
            break;

        case 'PUT':
            handleMarkRead($params);
            break;

        case 'DELETE':
            handleDeleteNotification($params);
            break;

        default:
            ApiResponse::methodNotAllowed('Use GET, POST, PUT, or DELETE');
    }
}

function handleGetNotifications($params)
{
    $studentId = $params['student_id'] ?? null;
    $staffId = $params['staff_id'] ?? null;
    $limit = (int)($params['limit'] ?? 50);
    $offset = (int)($params['offset'] ?? 0);
    $type = $params['type'] ?? null;
    $unreadOnly = isset($params['unread']) && $params['unread'] == 1;

    if (!$studentId && !$staffId) {
        ApiResponse::validationError(['student_id' => 'Student ID or Staff ID is required']);
    }

    $db = DatabaseHelper::getInstance();

    $sql = "SELECT * FROM attendance_notifications WHERE ";
    $paramsSql = [];

    if ($studentId) {
        $sql .= "student_id = ?";
        $paramsSql[] = $studentId;
    } else {
        $sql .= "staff_id = ?";
        $paramsSql[] = $staffId;
    }

    $sql .= " AND is_active = 1";

    if ($type) {
        $sql .= " AND notification_type = ?";
        $paramsSql[] = $type;
    }

    if ($unreadOnly) {
        $sql .= " AND is_read = 0";
    }

    $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $paramsSql[] = $limit;
    $paramsSql[] = $offset;

    $notifications = $db->fetchAll($sql, $paramsSql);

    // Get unread count
    $countSql = "SELECT COUNT(*) as total FROM attendance_notifications WHERE ";
    if ($studentId) {
        $countSql .= "student_id = ?";
    } else {
        $countSql .= "staff_id = ?";
    }
    $countSql .= " AND is_read = 0 AND is_active = 1";

    $unreadCount = $db->fetchOne($countSql, [$studentId ?: $staffId]);

    ApiResponse::success([
        'notifications' => $notifications,
        'unread_count' => $unreadCount['total'] ?? 0,
        'total' => count($notifications),
        'limit' => $limit,
        'offset' => $offset
    ]);
}

function handleSendNotification($params)
{
    $studentId = $params['student_id'] ?? null;
    $staffId = $params['staff_id'] ?? null;
    $message = $params['message'] ?? null;
    $subject = $params['subject'] ?? 'Attendance Notification';
    $type = $params['notification_type'] ?? 'custom';
    $channel = $params['channel'] ?? 'push';
    $referenceType = $params['reference_type'] ?? null;
    $referenceId = $params['reference_id'] ?? null;

    if (!$studentId && !$staffId) {
        ApiResponse::validationError(['student_id' => 'Student ID or Staff ID is required']);
    }

    if (!$message) {
        ApiResponse::validationError(['message' => 'Message is required']);
    }

    $db = DatabaseHelper::getInstance();

    $db->query("
        INSERT INTO attendance_notifications (
            uuid, school_id, student_id, staff_id,
            notification_type, channel, subject, message,
            reference_type, reference_id, is_active
        ) VALUES (
            UUID(), 1, ?, ?,
            ?, ?, ?, ?,
            ?, ?, 1
        )
    ", [
        $studentId,
        $staffId,
        $type,
        $channel,
        $subject,
        $message,
        $referenceType,
        $referenceId
    ]);

    $notificationId = $db->lastInsertId();

    ApiResponse::success([
        'notification_id' => $notificationId,
        'student_id' => $studentId,
        'staff_id' => $staffId
    ], 'Notification sent successfully');
}

function handleMarkRead($params)
{
    $notificationId = $params['notification_id'] ?? null;

    if (!$notificationId) {
        ApiResponse::validationError(['notification_id' => 'Notification ID is required']);
    }

    $db = DatabaseHelper::getInstance();

    $db->query("
        UPDATE attendance_notifications 
        SET is_read = 1, read_date = NOW() 
        WHERE id = ?
    ", [$notificationId]);

    ApiResponse::success([], 'Notification marked as read');
}

function handleDeleteNotification($params)
{
    $notificationId = $params['notification_id'] ?? null;

    if (!$notificationId) {
        ApiResponse::validationError(['notification_id' => 'Notification ID is required']);
    }

    $db = DatabaseHelper::getInstance();

    $db->query("
        UPDATE attendance_notifications 
        SET is_active = 0, updated_at = NOW() 
        WHERE id = ?
    ", [$notificationId]);

    ApiResponse::success([], 'Notification deleted successfully');
}