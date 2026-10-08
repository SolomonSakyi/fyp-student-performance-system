<?php
/**
 * push.php
 * 
 * Push Notification Handler
 * Manages push notification preferences and tokens
 * 
 * @package EduTrack
 * @subpackage API
 */

function handleMobilePush($params, $method, $action)
{
    // Authenticate user
    $user = MobileAuth::authenticate();

    // Rate limit
    MobileAuth::rateLimit('mobile_push');

    $db = DatabaseHelper::getInstance();
    
    // Check if NotificationService exists
    if (class_exists('NotificationService')) {
        $notificationService = new NotificationService();
    } else {
        // Create a simple fallback
        $notificationService = new stdClass();
        $notificationService->sendPushNotification = function($studentId, $title, $message, $data = []) {
            return true;
        };
    }

    switch ($method) {
        case 'POST':
            handleRegisterToken($params, $db, $user, $notificationService);
            break;

        case 'DELETE':
            handleUnregisterToken($params, $db, $user);
            break;

        case 'PUT':
            handleUpdatePreferences($params, $db, $user);
            break;

        default:
            MobileApiResponse::methodNotAllowed('Use POST, PUT, or DELETE');
    }
}

function handleRegisterToken($params, $db, $user, $notificationService)
{
    $deviceToken = $params['device_token'] ?? null;
    $deviceType = $params['device_type'] ?? 'android';
    $deviceName = $params['device_name'] ?? null;
    $appVersion = $params['app_version'] ?? null;
    $osVersion = $params['os_version'] ?? null;

    if (!$deviceToken) {
        MobileApiResponse::validationError(['device_token' => 'Device token is required']);
    }

    try {
        // Check if device already registered
        $existing = $db->fetchOne("
            SELECT id FROM devices WHERE device_token = ? AND user_id = ?
        ", [$deviceToken, $user['id']]);

        if ($existing) {
            $db->query("
                UPDATE devices 
                SET device_type = ?, device_name = ?, app_version = ?, 
                    os_version = ?, last_active = NOW(), is_active = 1
                WHERE id = ?
            ", [$deviceType, $deviceName, $appVersion, $osVersion, $existing['id']]);
        } else {
            $db->query("
                INSERT INTO devices (uuid, user_id, device_token, device_type, device_name, app_version, os_version, is_active)
                VALUES (UUID(), ?, ?, ?, ?, ?, ?, 1)
            ", [$user['id'], $deviceToken, $deviceType, $deviceName, $appVersion, $osVersion]);
        }

        // Send test notification (only if NotificationService is available)
        if (is_object($notificationService) && method_exists($notificationService, 'sendPushNotification')) {
            $notificationService->sendPushNotification(
                $user['id'],
                '✅ Device Registered',
                'Your device has been successfully registered for notifications.',
                [
                    'type' => 'device_registered',
                    'user_id' => $user['id']
                ]
            );
        }

        MobileApiResponse::success([
            'device_token' => $deviceToken,
            'device_type' => $deviceType,
            'registered' => true
        ], 'Device registered successfully');

    } catch (Exception $e) {
        MobileApiResponse::error('Failed to register device: ' . $e->getMessage(), 500);
    }
}

function handleUnregisterToken($params, $db, $user)
{
    $deviceToken = $params['device_token'] ?? null;

    if (!$deviceToken) {
        MobileApiResponse::validationError(['device_token' => 'Device token is required']);
    }

    try {
        $db->query("
            UPDATE devices 
            SET is_active = 0, updated_at = NOW() 
            WHERE device_token = ? AND user_id = ?
        ", [$deviceToken, $user['id']]);

        MobileApiResponse::success([], 'Device unregistered successfully');

    } catch (Exception $e) {
        MobileApiResponse::error('Failed to unregister device: ' . $e->getMessage(), 500);
    }
}

function handleUpdatePreferences($params, $db, $user)
{
    $preferences = $params['preferences'] ?? [];

    if (empty($preferences)) {
        MobileApiResponse::validationError(['preferences' => 'Preferences are required']);
    }

    try {
        // Update notification preferences
        $allowedTypes = ['attendance', 'reminder', 'alert', 'report'];
        foreach ($allowedTypes as $type) {
            if (isset($preferences[$type])) {
                $enabled = $preferences[$type] ? 1 : 0;
                $db->query("
                    INSERT INTO user_notification_preferences (user_id, notification_type, enabled)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE enabled = ?, updated_at = NOW()
                ", [$user['id'], $type, $enabled, $enabled]);
            }
        }

        MobileApiResponse::success([
            'preferences' => $preferences
        ], 'Preferences updated successfully');

    } catch (Exception $e) {
        MobileApiResponse::error('Failed to update preferences: ' . $e->getMessage(), 500);
    }
}