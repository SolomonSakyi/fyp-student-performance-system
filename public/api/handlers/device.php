<?php
/**
 * device.php
 * 
 * Device Management Handler
 * Manages biometric and RFID devices
 * 
 * @package EduTrack
 * @subpackage API
 */

function handleDevice($params, $method, $action)
{
    // Authenticate user (admin only for device management)
    try {
        $user = ApiAuth::authenticateUser();
    } catch (Exception $e) {
        ApiResponse::unauthorized('Authentication required');
    }

    // Check if user is admin
    if ($user['user_type'] !== 'admin') {
        ApiResponse::error('Admin access required', 403);
    }

    switch ($method) {
        case 'GET':
            if (!empty($params['device_id'])) {
                handleGetDevice($params);
            } else {
                handleGetDevices($params);
            }
            break;

        case 'POST':
            handleRegisterDevice($params);
            break;

        case 'PUT':
            handleUpdateDevice($params);
            break;

        case 'DELETE':
            handleDeleteDevice($params);
            break;

        default:
            ApiResponse::methodNotAllowed('Use GET, POST, PUT, or DELETE');
    }
}

function handleGetDevices($params)
{
    $db = DatabaseHelper::getInstance();
    $status = $params['status'] ?? null;
    $type = $params['type'] ?? null;
    $limit = (int)($params['limit'] ?? 100);

    $sql = "SELECT d.*, dt.type_name, dt.category
            FROM attendance_devices d
            LEFT JOIN attendance_device_types dt ON d.device_type_id = dt.id
            WHERE d.school_id = 1 AND d.is_active = 1";

    $paramsSql = [];

    if ($status) {
        $sql .= " AND d.status = ?";
        $paramsSql[] = $status;
    }

    if ($type) {
        $sql .= " AND dt.category = ?";
        $paramsSql[] = $type;
    }

    $sql .= " ORDER BY d.device_name LIMIT ?";
    $paramsSql[] = $limit;

    $devices = $db->fetchAll($sql, $paramsSql);

    ApiResponse::success([
        'devices' => $devices,
        'total' => count($devices),
        'online_count' => count(array_filter($devices, function($d) { return $d['status'] === 'online'; })),
        'offline_count' => count(array_filter($devices, function($d) { return $d['status'] === 'offline'; }))
    ]);
}

function handleGetDevice($params)
{
    $deviceId = $params['device_id'] ?? 0;

    if (!$deviceId) {
        ApiResponse::validationError(['device_id' => 'Device ID is required']);
    }

    $db = DatabaseHelper::getInstance();

    $device = $db->fetchOne("
        SELECT d.*, dt.type_name, dt.category
        FROM attendance_devices d
        LEFT JOIN attendance_device_types dt ON d.device_type_id = dt.id
        WHERE d.id = ? AND d.is_active = 1
    ", [$deviceId]);

    if (!$device) {
        ApiResponse::error('Device not found', 404);
    }

    ApiResponse::success($device);
}

function handleRegisterDevice($params)
{
    $required = ['device_name', 'device_serial', 'device_type_id'];
    foreach ($required as $field) {
        if (empty($params[$field])) {
            ApiResponse::validationError([$field => 'Field is required']);
        }
    }

    $service = new AttendanceService();

    $result = $service->registerDevice([
        'device_name' => $params['device_name'],
        'device_serial' => $params['device_serial'],
        'device_type_id' => $params['device_type_id'],
        'device_code' => $params['device_code'] ?? $params['device_serial'],
        'location' => $params['location'] ?? null,
        'ip_address' => $params['ip_address'] ?? null,
        'port_number' => $params['port_number'] ?? null,
        'school_id' => 1
    ]);

    if ($result['success']) {
        ApiResponse::success([
            'device_id' => $result['device_id'],
            'api_key' => $result['api_key'] ?? null,
            'api_secret' => $result['api_secret'] ?? null
        ], $result['message']);
    } else {
        ApiResponse::error($result['message']);
    }
}

function handleUpdateDevice($params)
{
    if (empty($params['device_id'])) {
        ApiResponse::validationError(['device_id' => 'Device ID is required']);
    }

    $db = DatabaseHelper::getInstance();

    $deviceId = $params['device_id'];

    // Check if device exists
    $existing = $db->fetchOne("SELECT id FROM attendance_devices WHERE id = ? AND is_active = 1", [$deviceId]);
    if (!$existing) {
        ApiResponse::error('Device not found', 404);
    }

    $updateFields = [];
    $updateValues = [];

    $allowedFields = ['device_name', 'location', 'ip_address', 'port_number', 'status'];
    foreach ($allowedFields as $field) {
        if (isset($params[$field])) {
            $updateFields[] = "$field = ?";
            $updateValues[] = $params[$field];
        }
    }

    if (empty($updateFields)) {
        ApiResponse::error('No fields to update', 400);
    }

    $updateValues[] = $deviceId;
    $sql = "UPDATE attendance_devices SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE id = ?";
    $db->query($sql, $updateValues);

    ApiResponse::success([], 'Device updated successfully');
}

function handleDeleteDevice($params)
{
    if (empty($params['device_id'])) {
        ApiResponse::validationError(['device_id' => 'Device ID is required']);
    }

    $db = DatabaseHelper::getInstance();

    $deviceId = $params['device_id'];

    $db->query("
        UPDATE attendance_devices 
        SET is_active = 0, updated_at = NOW() 
        WHERE id = ?
    ", [$deviceId]);

    ApiResponse::success([], 'Device deleted successfully');
}