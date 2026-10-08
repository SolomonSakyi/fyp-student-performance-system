<?php
/**
 * config.php
 * 
 * API Configuration for Attendance Module
 * 
 * @package EduTrack
 * @subpackage API
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-API-Secret, X-Device-ID');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include required files
require_once __DIR__ . '/../../app/helpers/DatabaseHelper.php';
require_once __DIR__ . '/../../app/helpers/LoggerHelper.php';
require_once __DIR__ . '/../../app/services/Attendance/AttendanceService.php';

// API Configuration
define('API_VERSION', '1.0.0');
define('API_NAME', 'EduTrack Attendance API');

// Rate limiting settings
define('RATE_LIMIT', 100);
define('RATE_LIMIT_WINDOW', 60);

// Response codes
define('HTTP_OK', 200);
define('HTTP_CREATED', 201);
define('HTTP_BAD_REQUEST', 400);
define('HTTP_UNAUTHORIZED', 401);
define('HTTP_FORBIDDEN', 403);
define('HTTP_NOT_FOUND', 404);
define('HTTP_METHOD_NOT_ALLOWED', 405);
define('HTTP_TOO_MANY_REQUESTS', 429);
define('HTTP_INTERNAL_SERVER_ERROR', 500);

/**
 * API Response Helper
 */
class ApiResponse
{
    public static function success($data = null, $message = 'Success', $code = HTTP_OK)
    {
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'status' => 'success',
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => API_VERSION
        ]);
        exit;
    }

    public static function error($message, $code = HTTP_BAD_REQUEST, $errors = null)
    {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'status' => 'error',
            'code' => $code,
            'message' => $message,
            'errors' => $errors,
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => API_VERSION
        ]);
        exit;
    }

    public static function unauthorized($message = 'Unauthorized')
    {
        self::error($message, HTTP_UNAUTHORIZED);
    }

    public static function notFound($message = 'Resource not found')
    {
        self::error($message, HTTP_NOT_FOUND);
    }

    public static function methodNotAllowed($message = 'Method not allowed')
    {
        self::error($message, HTTP_METHOD_NOT_ALLOWED);
    }

    public static function validationError($errors)
    {
        self::error('Validation failed', HTTP_BAD_REQUEST, $errors);
    }
}

/**
 * API Authentication Helper
 */
class ApiAuth
{
    private static $db;
    private static $service;

    public static function init()
    {
        self::$db = DatabaseHelper::getInstance();
        self::$service = new AttendanceService();
    }

    /**
     * Authenticate using API Key/Secret (for devices)
     */
    public static function authenticateDevice()
    {
        self::init();

        $headers = getallheaders();
        $apiKey = $headers['X-API-Key'] ?? $_GET['api_key'] ?? null;
        $apiSecret = $headers['X-API-Secret'] ?? $_GET['api_secret'] ?? null;

        if (!$apiKey || !$apiSecret) {
            ApiResponse::unauthorized('Missing API credentials');
        }

        $device = self::$db->fetchOne("
            SELECT id, device_name, status, is_active 
            FROM attendance_devices 
            WHERE api_key = ? AND api_secret = ? AND is_active = 1
        ", [$apiKey, $apiSecret]);

        if (!$device) {
            ApiResponse::unauthorized('Invalid API credentials');
        }

        if ($device['status'] === 'inactive') {
            ApiResponse::error('Device is inactive', HTTP_FORBIDDEN);
        }

        // Update device status
        self::$db->query("
            UPDATE attendance_devices 
            SET last_connected = NOW(), status = 'online' 
            WHERE id = ?
        ", [$device['id']]);

        return $device;
    }

    /**
     * Authenticate using JWT Token (for mobile app users)
     */
    public static function authenticateUser()
    {
        $headers = getallheaders();
        $token = $headers['Authorization'] ?? $_GET['token'] ?? null;

        if (!$token) {
            ApiResponse::unauthorized('Missing authentication token');
        }

        // Remove 'Bearer ' prefix if present
        $token = str_replace('Bearer ', '', $token);

        // For now, simple validation - in production use JWT
        $user = self::$db->fetchOne("
            SELECT id, username, user_type, is_active 
            FROM users 
            WHERE api_token = ? AND is_active = 1
        ", [$token]);

        if (!$user) {
            ApiResponse::unauthorized('Invalid token');
        }

        return $user;
    }

    /**
     * Rate limiting
     */
    public static function rateLimit($key, $limit = RATE_LIMIT, $window = RATE_LIMIT_WINDOW)
    {
        $ip = $_SERVER['REMOTE_ADDR'];
        $cacheKey = 'rate_limit_' . md5($ip . '_' . $key);
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $now = time();
        $windowStart = $now - $window;
        
        if (!isset($_SESSION[$cacheKey])) {
            $_SESSION[$cacheKey] = [];
        }
        
        $_SESSION[$cacheKey] = array_filter($_SESSION[$cacheKey], function($timestamp) use ($windowStart) {
            return $timestamp >= $windowStart;
        });
        
        if (count($_SESSION[$cacheKey]) >= $limit) {
            ApiResponse::error('Rate limit exceeded. Please try again later.', HTTP_TOO_MANY_REQUESTS);
        }
        
        $_SESSION[$cacheKey][] = $now;
        return true;
    }
}

// Initialize session for rate limiting
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}