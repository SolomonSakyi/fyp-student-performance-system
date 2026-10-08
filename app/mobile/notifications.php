<?php
/**
 * notifications.php
 * 
 * Mobile Notifications Handler
 * Handles push notifications for mobile app
 * 
 * @package EduTrack
 * @subpackage API
 */

function handleMobileNotifications($params, $method, $action)
{
    // Authenticate user
    $user = MobileAuth::authenticate();

    // Rate limit
    MobileAuth::rateLimit('mobile_notifications');

    $db = DatabaseHelper::getInstance();
    $notificationService = new NotificationService();

    switch ($method) {
        case 'GET':
            handleGetNotifications($params, $db, $user);
            break;

        case 'POST':
            handleRegisterDevice($params, $db, $user);
            break;

        case 'PUT':
            handleMarkRead($params, $db);
            break;

        case 'DELETE':
            handleDeleteNotification($params, $db);
            break;

        default:
            MobileApiResponse::methodNotAllowed('Use GET, POST, PUT, or DELETE');
    }
}

function handleGetNotifications($params, $db, $user)
{
    $studentId = $params['student_id'] ?? null;
    $page = max(1, (int)($params['page'] ?? 1));
    $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $unreadOnly = isset($params['unread']) && $params['unread'] == 1;
    $type = $params['type'] ?? null;

    // Get student ID if user is a student
    if (!$studentId && $user['user_type'] === 'student') {
        $student = $db->fetchOne("
            SELECT id FROM students WHERE user_id = ? AND is_active = 1
        ", [$user['id']]);
        $studentId = $student['id'] ?? null;
    }

    if (!$studentId) {
        MobileApiResponse::validationError(['student_id' => 'Student ID is required']);
    }

    $sql = "SELECT * FROM attendance_notifications 
            WHERE student_id = ? AND is_active = 1";
    $paramsSql = [$studentId];

    if ($unreadOnly) {
        $sql .= " AND is_read = 0";
    }

    if ($type) {
        $sql .= " AND notification_type = ?";
        $paramsSql[] = $type;
    }

    // Get total count
    $countSql = str_replace("SELECT *", "SELECT COUNT(*) as total", $sql);
    $totalResult = $db->fetchOne($countSql, $paramsSql);
    $total = $totalResult['total'] ?? 0;

    $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $paramsSql[] = $limit;
    $paramsSql[] = $offset;

    $notifications = $db->fetchAll($sql, $paramsSql);

    // Get unread count
    $unreadResult = $db->fetchOne("
        SELECT COUNT(*) as count FROM attendance_notifications 
        WHERE student_id = ? AND is_read = 0 AND is_active = 1
    ", [$studentId]);
    $unreadCount = $unreadResult['count'] ?? 0;

    MobileApiResponse::paginated(
        $notifications,
        $total,
        $page,
        $limit,
        'Notifications retrieved successfully'
    );
}

function handleRegisterDevice($params, $db, $user)
{
    $deviceToken = $params['device_token'] ?? null;
    $deviceType = $params['device_type'] ?? 'android';
    $deviceName = $params['device_name'] ?? null;
    $appVersion = $params['app_version'] ?? null;

    if (!$deviceToken) {
        MobileApiResponse::validationError(['device_token' => 'Device token is required']);
    }

    // Check if device already registered
    $existing = $db->fetchOne("
        SELECT id FROM devices WHERE device_token = ? AND user_id = ?
    ", [$deviceToken, $user['id']]);

    if ($existing) {
        $db->query("
            UPDATE devices 
            SET device_type = ?, device_name = ?, app_version = ?, last_active = NOW(), is_active = 1
            WHERE id = ?
        ", [$deviceType, $deviceName, $appVersion, $existing['id']]);
    } else {
        $db->query("
            INSERT INTO devices (uuid, user_id, device_token, device_type, device_name, app_version, is_active)
            VALUES (UUID(), ?, ?, ?, ?, ?, 1)
        ", [$user['id'], $deviceToken, $deviceType, $deviceName, $appVersion]);
    }

    MobileApiResponse::success([
        'device_token' => $deviceToken,
        'device_type' => $deviceType,
        'registered' => true
    ], 'Device registered successfully');
}

function handleMarkRead($params, $db)
{
    $notificationId = $params['notification_id'] ?? null;

    if (!$notificationId) {
        MobileApiResponse::validationError(['notification_id' => 'Notification ID is required']);
    }

    $db->query("
        UPDATE attendance_notifications 
        SET is_read = 1, read_date = NOW() 
        WHERE id = ?
    ", [$notificationId]);

    MobileApiResponse::success([], 'Notification marked as read');
}

function handleDeleteNotification($params, $db)
{
    $notificationId = $params['notification_id'] ?? null;

    if (!$notificationId) {
        MobileApiResponse::validationError(['notification_id' => 'Notification ID is required']);
    }

    $db->query("
        UPDATE attendance_notifications 
        SET is_active = 0, updated_at = NOW() 
        WHERE id = ?
    ", [$notificationId]);

    MobileApiResponse::success([], 'Notification deleted successfully');
}