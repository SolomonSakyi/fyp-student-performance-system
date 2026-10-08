<?php
/**
 * auth.php
 * 
 * Authentication Handler
 */

function handleAuth($params, $method, $action)
{
    if ($method === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    $action = $action ?? 'login';

    switch ($action) {
        case 'login':
            handleLogin($params);
            break;

        case 'device':
            handleDeviceAuth($params);
            break;

        case 'verify':
            handleVerify($params);
            break;

        default:
            ApiResponse::error('Unknown auth action: ' . $action, 400);
    }
}

function handleLogin($params)
{
    $username = $params['username'] ?? null;
    $password = $params['password'] ?? null;

    if (!$username || !$password) {
        ApiResponse::validationError(['username' => 'Username required', 'password' => 'Password required']);
    }

    $db = DatabaseHelper::getInstance();

    $user = $db->fetchOne("
        SELECT id, username, email, user_type, is_active, password_hash
        FROM users 
        WHERE (username = ? OR email = ?) AND is_active = 1
    ", [$username, $username]);

    if (!$user) {
        ApiResponse::error('Invalid credentials', 401);
    }

    if (!password_verify($password, $user['password_hash'])) {
        ApiResponse::error('Invalid credentials', 401);
    }

    $token = bin2hex(random_bytes(32));

    $db->query("
        UPDATE users 
        SET api_token = ?, last_login = NOW() 
        WHERE id = ?
    ", [$token, $user['id']]);

    ApiResponse::success([
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'user_type' => $user['user_type'],
            'token' => $token
        ]
    ], 'Login successful');
}

function handleDeviceAuth($params)
{
    $apiKey = $params['api_key'] ?? null;
    $apiSecret = $params['api_secret'] ?? null;

    if (!$apiKey || !$apiSecret) {
        ApiResponse::validationError(['api_key' => 'API Key required', 'api_secret' => 'API Secret required']);
    }

    $db = DatabaseHelper::getInstance();

    $device = $db->fetchOne("
        SELECT id, device_name, device_serial, status, location, is_active
        FROM attendance_devices 
        WHERE api_key = ? AND api_secret = ? AND is_active = 1
    ", [$apiKey, $apiSecret]);

    if (!$device) {
        ApiResponse::error('Invalid device credentials', 401);
    }

    if ($device['status'] === 'inactive') {
        ApiResponse::error('Device is inactive', 403);
    }

    $db->query("
        UPDATE attendance_devices 
        SET last_connected = NOW(), status = 'online' 
        WHERE id = ?
    ", [$device['id']]);

    ApiResponse::success([
        'device' => [
            'id' => $device['id'],
            'name' => $device['device_name'],
            'serial' => $device['device_serial'],
            'status' => $device['status'],
            'location' => $device['location']
        ]
    ], 'Device authenticated successfully');
}

function handleVerify($params)
{
    $token = $params['token'] ?? null;

    if (!$token) {
        ApiResponse::validationError(['token' => 'Token required']);
    }

    $db = DatabaseHelper::getInstance();

    $user = $db->fetchOne("
        SELECT id, username, user_type, is_active
        FROM users 
        WHERE api_token = ? AND is_active = 1
    ", [$token]);

    if (!$user) {
        ApiResponse::unauthorized('Invalid or expired token');
    }

    ApiResponse::success([
        'user' => $user
    ], 'Token is valid');
}