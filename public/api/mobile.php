<?php
/**
 * mobile.php
 * 
 * Mobile App API Router
 * Mobile-optimized endpoints with pagination and filtering
 * 
 * @package EduTrack
 * @subpackage API
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define base path
define('BASE_PATH', __DIR__);

// Include required files
require_once __DIR__ . '/../../app/helpers/DatabaseHelper.php';
require_once __DIR__ . '/../../app/helpers/LoggerHelper.php';
require_once __DIR__ . '/../../app/services/Attendance/AttendanceService.php';

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-API-Secret, X-Device-ID');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ================================================================
// MOBILE API RESPONSE HELPER
// ================================================================

class MobileApiResponse
{
    public static function success($data = null, $message = 'Success', $code = 200)
    {
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'status' => 'success',
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '2.0.0'
        ]);
        exit;
    }

    public static function error($message, $code = 400, $errors = null)
    {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'status' => 'error',
            'code' => $code,
            'message' => $message,
            'errors' => $errors,
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '2.0.0'
        ]);
        exit;
    }

    public static function unauthorized($message = 'Unauthorized')
    {
        self::error($message, 401);
    }

    public static function notFound($message = 'Resource not found')
    {
        self::error($message, 404);
    }

    public static function methodNotAllowed($message = 'Method not allowed')
    {
        self::error($message, 405);
    }

    public static function validationError($errors)
    {
        self::error('Validation failed', 400, $errors);
    }

    public static function paginated($data, $total, $page, $limit, $message = 'Success')
    {
        $totalPages = ceil($total / $limit);
        
        self::success([
            'items' => $data,
            'pagination' => [
                'total' => (int)$total,
                'per_page' => (int)$limit,
                'current_page' => (int)$page,
                'total_pages' => (int)$totalPages,
                'has_next' => $page < $totalPages,
                'has_previous' => $page > 1
            ]
        ], $message);
    }
}

// ================================================================
// MOBILE AUTHENTICATION
// ================================================================

class MobileAuth
{
    private static $db;

    public static function init()
    {
        self::$db = DatabaseHelper::getInstance();
    }

    public static function authenticate()
    {
        self::init();

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $token = $headers['Authorization'] ?? $_GET['token'] ?? null;

        if (!$token) {
            MobileApiResponse::unauthorized('Missing authentication token');
        }

        $token = str_replace('Bearer ', '', $token);

        $user = self::$db->fetchOne("
            SELECT id, username, user_type, is_active 
            FROM users 
            WHERE api_token = ? AND is_active = 1
        ", [$token]);

        if (!$user) {
            MobileApiResponse::unauthorized('Invalid or expired token');
        }

        return $user;
    }

    public static function rateLimit($key, $limit = 100, $window = 60)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $cacheKey = 'rate_limit_' . md5($ip . '_' . $key);
        
        $now = time();
        $windowStart = $now - $window;
        
        if (!isset($_SESSION[$cacheKey])) {
            $_SESSION[$cacheKey] = [];
        }
        
        $_SESSION[$cacheKey] = array_filter($_SESSION[$cacheKey], function($timestamp) use ($windowStart) {
            return $timestamp >= $windowStart;
        });
        
        if (count($_SESSION[$cacheKey]) >= $limit) {
            MobileApiResponse::error('Rate limit exceeded. Please try again later.', 429);
        }
        
        $_SESSION[$cacheKey][] = $now;
        return true;
    }
}

// ================================================================
// ROUTING
// ================================================================

// Get the request path
$requestUri = $_SERVER['REQUEST_URI'];
$scriptName = $_SERVER['SCRIPT_NAME'];
$path = str_replace($scriptName, '', $requestUri);
$path = ltrim($path, '/');
$path = explode('?', $path)[0];

if (empty($path)) {
    $path = 'dashboard';
}

$segments = explode('/', $path);
$endpoint = $segments[0] ?? 'dashboard';
$action = $segments[1] ?? null;

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$query = $_GET;

$params = $method === 'GET' ? $query : ($input ?: []);

// ================================================================
// ROUTE HANDLERS
// ================================================================

try {
    // Check if handler file exists
    $handlerFile = __DIR__ . '/mobile/' . $endpoint . '.php';
    
    if (file_exists($handlerFile)) {
        require_once $handlerFile;
        $functionName = 'handleMobile' . ucfirst($endpoint);
        if (function_exists($functionName)) {
            $functionName($params, $method, $action);
            exit;
        }
    }
    
    // Fallback to inline handlers
    switch ($endpoint) {
        case 'dashboard':
            handleMobileDashboardInline($params, $method, $action);
            break;

        case 'attendance':
            handleMobileAttendanceInline($params, $method, $action);
            break;

        case 'checkin':
            handleMobileCheckinInline($params, $method, $action);
            break;

        case 'notifications':
            handleMobileNotificationsInline($params, $method, $action);
            break;

        case 'profile':
            handleMobileProfileInline($params, $method, $action);
            break;

        case 'push':
            handleMobilePushInline($params, $method, $action);
            break;

        case 'sync':
            handleMobileSyncInline($params, $method, $action);
            break;

        default:
            MobileApiResponse::notFound('Mobile endpoint not found: ' . $endpoint);
    }
} catch (Exception $e) {
    MobileApiResponse::error($e->getMessage(), 500);
}

// ================================================================
// INLINE HANDLER: Dashboard
// ================================================================

function handleMobileDashboardInline($params, $method, $action)
{
    if ($method !== 'GET') {
        MobileApiResponse::methodNotAllowed('Use GET method');
    }

    $user = MobileAuth::authenticate();
    MobileAuth::rateLimit('mobile_dashboard');

    $db = DatabaseHelper::getInstance();
    $service = new AttendanceService();

    $studentId = $params['student_id'] ?? null;
    $termId = $params['term_id'] ?? 1;

    // Get student ID if user is a student
    if (!$studentId && isset($user['user_type']) && $user['user_type'] === 'student') {
        $student = $db->fetchOne("
            SELECT id FROM students WHERE user_id = ? AND is_active = 1
        ", [$user['id']]);
        $studentId = $student['id'] ?? null;
    }

    // Get today's summary
    $todaySummary = $service->getTodaySummary();

    // Get student statistics
    $studentStats = null;
    if ($studentId && $termId) {
        $studentStats = $service->getStudentStatistics($studentId, $termId);
    }

    // Get unread notification count
    $unreadCount = 0;
    if ($studentId) {
        $unread = $db->fetchOne("
            SELECT COUNT(*) as count FROM attendance_notifications 
            WHERE student_id = ? AND is_read = 0 AND is_active = 1
        ", [$studentId]);
        $unreadCount = $unread['count'] ?? 0;
    }

    MobileApiResponse::success([
        'today' => [
            'total_expected' => (int)($todaySummary['total_expected'] ?? 0),
            'total_present' => (int)($todaySummary['total_present'] ?? 0),
            'attendance_rate' => $todaySummary['total_expected'] > 0 
                ? round(($todaySummary['total_present'] / $todaySummary['total_expected']) * 100, 1) 
                : 0
        ],
        'student_stats' => $studentStats ? [
            'total_sessions' => (int)($studentStats['total_sessions'] ?? 0),
            'present' => (int)($studentStats['present'] ?? 0),
            'absent' => (int)($studentStats['absent'] ?? 0),
            'late' => (int)($studentStats['late'] ?? 0),
            'excused' => (int)($studentStats['excused'] ?? 0),
            'percentage' => (float)($studentStats['percentage'] ?? 0)
        ] : null,
        'notifications' => [
            'unread_count' => $unreadCount
        ]
    ]);
}

// ================================================================
// INLINE HANDLER: Push Notifications
// ================================================================

function handleMobilePushInline($params, $method, $action)
{
    $user = MobileAuth::authenticate();
    MobileAuth::rateLimit('mobile_push');

    $db = DatabaseHelper::getInstance();

    switch ($method) {
        case 'POST':
            handleRegisterTokenInline($params, $db, $user);
            break;

        case 'DELETE':
            handleUnregisterTokenInline($params, $db, $user);
            break;

        case 'PUT':
            handleUpdatePreferencesInline($params, $db, $user);
            break;

        default:
            MobileApiResponse::methodNotAllowed('Use POST, PUT, or DELETE');
    }
}

function handleRegisterTokenInline($params, $db, $user)
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
        // Check if devices table exists
        $tableCheck = $db->fetchOne("SHOW TABLES LIKE 'devices'");
        if (!$tableCheck) {
            MobileApiResponse::error('Devices table not found. Please run the database migration.', 500);
        }

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

        MobileApiResponse::success([
            'device_token' => $deviceToken,
            'device_type' => $deviceType,
            'registered' => true
        ], 'Device registered successfully');

    } catch (Exception $e) {
        MobileApiResponse::error('Failed to register device: ' . $e->getMessage(), 500);
    }
}

function handleUnregisterTokenInline($params, $db, $user)
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

function handleUpdatePreferencesInline($params, $db, $user)
{
    $preferences = $params['preferences'] ?? [];

    if (empty($preferences)) {
        MobileApiResponse::validationError(['preferences' => 'Preferences are required']);
    }

    try {
        // Check if user_notification_preferences table exists
        $tableCheck = $db->fetchOne("SHOW TABLES LIKE 'user_notification_preferences'");
        if (!$tableCheck) {
            MobileApiResponse::error('Preferences table not found. Please run the database migration.', 500);
        }

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

// ================================================================
// INLINE HANDLER: Attendance (Placeholder)
// ================================================================

function handleMobileAttendanceInline($params, $method, $action)
{
    if ($method !== 'GET') {
        MobileApiResponse::methodNotAllowed('Use GET method');
    }

    $user = MobileAuth::authenticate();
    MobileAuth::rateLimit('mobile_attendance');

    $db = DatabaseHelper::getInstance();
    $service = new AttendanceService();

    $studentId = $params['student_id'] ?? null;
    $page = max(1, (int)($params['page'] ?? 1));
    $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // Get student ID if user is a student
    if (!$studentId && isset($user['user_type']) && $user['user_type'] === 'student') {
        $student = $db->fetchOne("
            SELECT id FROM students WHERE user_id = ? AND is_active = 1
        ", [$user['id']]);
        $studentId = $student['id'] ?? null;
    }

    if (!$studentId) {
        MobileApiResponse::validationError(['student_id' => 'Student ID is required']);
    }

    // Get attendance records
    $records = $db->fetchAll("
        SELECT sa.*, 
               ases.session_date, ases.session_type,
               astatus.status_name, astatus.category, astatus.color,
               am.method_name
        FROM student_attendance sa
        JOIN attendance_sessions ases ON sa.session_id = ases.id
        LEFT JOIN attendance_statuses astatus ON sa.status_id = astatus.id
        LEFT JOIN attendance_methods am ON sa.method_id = am.id
        WHERE sa.student_id = ? AND sa.is_active = 1
        ORDER BY ases.session_date DESC
        LIMIT ? OFFSET ?
    ", [$studentId, $limit, $offset]);

    // Get total count
    $totalResult = $db->fetchOne("
        SELECT COUNT(*) as total 
        FROM student_attendance 
        WHERE student_id = ? AND is_active = 1
    ", [$studentId]);
    $total = $totalResult['total'] ?? 0;

    MobileApiResponse::paginated($records, $total, $page, $limit);
}

// ================================================================
// INLINE HANDLER: Checkin (Placeholder)
// ================================================================

function handleMobileCheckinInline($params, $method, $action)
{
    if ($method !== 'POST') {
        MobileApiResponse::methodNotAllowed('Use POST method');
    }

    $user = MobileAuth::authenticate();
    MobileAuth::rateLimit('mobile_checkin');

    $service = new AttendanceService();

    $required = ['student_id', 'class_id'];
    foreach ($required as $field) {
        if (empty($params[$field])) {
            MobileApiResponse::validationError([$field => 'Field is required']);
        }
    }

    $result = $service->markStudentAttendance([
        'student_id' => $params['student_id'],
        'class_id' => $params['class_id'],
        'date' => $params['date'] ?? date('Y-m-d'),
        'session_type' => $params['session_type'] ?? 'morning',
        'status' => $params['status'] ?? 'P',
        'method_id' => 8,
        'device_id' => $params['device_id'] ?? null,
        'check_in_time' => $params['check_in_time'] ?? date('Y-m-d H:i:s'),
        'is_late' => $params['is_late'] ?? 0,
        'late_minutes' => $params['late_minutes'] ?? null,
        'is_excused' => $params['is_excused'] ?? 0,
        'excused_reason' => $params['excused_reason'] ?? null,
        'notes' => $params['notes'] ?? null,
        'is_synced' => 1
    ]);

    if ($result['success']) {
        MobileApiResponse::success([
            'attendance_id' => $result['attendance_id'],
            'session_id' => $result['session_id'],
            'student_id' => $params['student_id'],
            'check_in_time' => date('Y-m-d H:i:s')
        ], $result['message']);
    } else {
        MobileApiResponse::error($result['message']);
    }
}

// ================================================================
// INLINE HANDLER: Notifications (Placeholder)
// ================================================================

function handleMobileNotificationsInline($params, $method, $action)
{
    if ($method !== 'GET') {
        MobileApiResponse::methodNotAllowed('Use GET method');
    }

    $user = MobileAuth::authenticate();
    MobileAuth::rateLimit('mobile_notifications');

    $db = DatabaseHelper::getInstance();

    $studentId = $params['student_id'] ?? null;
    $page = max(1, (int)($params['page'] ?? 1));
    $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    if (!$studentId && isset($user['user_type']) && $user['user_type'] === 'student') {
        $student = $db->fetchOne("
            SELECT id FROM students WHERE user_id = ? AND is_active = 1
        ", [$user['id']]);
        $studentId = $student['id'] ?? null;
    }

    if (!$studentId) {
        MobileApiResponse::validationError(['student_id' => 'Student ID is required']);
    }

    $notifications = $db->fetchAll("
        SELECT * FROM attendance_notifications 
        WHERE student_id = ? AND is_active = 1
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ", [$studentId, $limit, $offset]);

    $totalResult = $db->fetchOne("
        SELECT COUNT(*) as total 
        FROM attendance_notifications 
        WHERE student_id = ? AND is_active = 1
    ", [$studentId]);
    $total = $totalResult['total'] ?? 0;

    // Get unread count
    $unreadResult = $db->fetchOne("
        SELECT COUNT(*) as count 
        FROM attendance_notifications 
        WHERE student_id = ? AND is_read = 0 AND is_active = 1
    ", [$studentId]);
    $unreadCount = $unreadResult['count'] ?? 0;

    MobileApiResponse::paginated($notifications, $total, $page, $limit);
}

// ================================================================
// INLINE HANDLER: Profile (Placeholder)
// ================================================================

function handleMobileProfileInline($params, $method, $action)
{
    if ($method !== 'GET' && $method !== 'PUT') {
        MobileApiResponse::methodNotAllowed('Use GET or PUT');
    }

    $user = MobileAuth::authenticate();
    MobileAuth::rateLimit('mobile_profile');

    $db = DatabaseHelper::getInstance();

    if ($method === 'GET') {
        $userData = $db->fetchOne("
            SELECT id, username, email, user_type, is_active
            FROM users WHERE id = ?
        ", [$user['id']]);

        MobileApiResponse::success($userData);
    } else {
        // PUT - Update profile
        $updateFields = [];
        $updateData = [];
        $allowedFields = ['email', 'phone'];

        foreach ($allowedFields as $field) {
            if (isset($params[$field])) {
                $updateFields[] = "$field = ?";
                $updateData[] = $params[$field];
            }
        }

        if (!empty($updateFields)) {
            $updateData[] = $user['id'];
            $db->query("
                UPDATE users 
                SET " . implode(', ', $updateFields) . " 
                WHERE id = ?
            ", $updateData);
        }

        MobileApiResponse::success([], 'Profile updated successfully');
    }
}

// ================================================================
// INLINE HANDLER: Sync (Placeholder)
// ================================================================

function handleMobileSyncInline($params, $method, $action)
{
    if ($method !== 'POST') {
        MobileApiResponse::methodNotAllowed('Use POST method');
    }

    $user = MobileAuth::authenticate();
    MobileAuth::rateLimit('mobile_sync');

    $offlineData = $params['attendance_data'] ?? [];

    if (empty($offlineData)) {
        MobileApiResponse::validationError(['attendance_data' => 'No data to sync']);
    }

    $service = new AttendanceService();
    $result = $service->syncOfflineAttendance($offlineData);

    MobileApiResponse::success([
        'synced' => $result['synced'],
        'failed' => $result['failed'],
        'errors' => $result['errors'],
        'total' => count($offlineData)
    ], 'Sync completed');
}