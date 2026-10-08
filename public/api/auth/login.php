<?php
/**
 * login.php
 * 
 * Test Login Endpoint - Returns JWT Token
 * 
 * @package EduTrack
 * @subpackage API
 * @version 1.0
 */

// ================================================================
// ERROR REPORTING
// ================================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ================================================================
// HEADERS
// ================================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ================================================================
// PATH DEFINITIONS - FIXED
// ================================================================

// Get the base path (project root)
// From: public/api/auth/login.php
// Go up 3 levels to reach project root
$basePath = dirname(__DIR__, 3);

// Define helper path
$helperPath = $basePath . '/app/helpers/';

// ================================================================
// INCLUDE REQUIRED FILES
// ================================================================

// Include JWTHelper with correct path
$jwtHelperFile = $helperPath . 'JWTHelper.php';
if (!file_exists($jwtHelperFile)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'JWTHelper.php not found at: ' . $jwtHelperFile,
        'base_path' => $basePath,
        'helper_path' => $helperPath
    ]);
    exit;
}
require_once $jwtHelperFile;

// Include DatabaseHelper
$dbHelperFile = $helperPath . 'DatabaseHelper.php';
if (!file_exists($dbHelperFile)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'DatabaseHelper.php not found at: ' . $dbHelperFile
    ]);
    exit;
}
require_once $dbHelperFile;

// ================================================================
// MAIN LOGIC
// ================================================================

$response = ['success' => false, 'message' => 'Invalid request'];

try {
    // Get request input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $username = isset($input['username']) ? trim($input['username']) : '';
    $password = isset($input['password']) ? trim($input['password']) : '';

    if (empty($username) || empty($password)) {
        throw new Exception('Username and password required', 400);
    }

    // ============================================================
    // FOR TESTING - Accept hardcoded credentials
    // ============================================================
    if ($username === 'admin' && $password === 'admin123') {
        $jwtHelper = new JWTHelper();
        $token = $jwtHelper->generateTestToken(1, 'admin', ['admin']);
        
        echo json_encode([
            'success' => true,
            'message' => 'Login successful (test account)',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => 1,
                    'username' => 'admin',
                    'email' => 'admin@school.com',
                    'roles' => ['admin']
                ]
            ]
        ]);
        exit;
    }

    // ============================================================
    // DATABASE AUTHENTICATION (if you have users table)
    // ============================================================
    $db = DatabaseHelper::getInstance();
    
    $user = $db->fetchOne("
        SELECT id, username, email, password_hash 
        FROM users 
        WHERE username = ? AND is_active = 1
    ", [$username]);

    if (!$user) {
        throw new Exception('Invalid credentials', 401);
    }

    // Verify password (assuming password_hash is used)
    if (!password_verify($password, $user['password_hash'])) {
        throw new Exception('Invalid credentials', 401);
    }

    $jwtHelper = new JWTHelper();
    $token = $jwtHelper->generateToken([
        'user_id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'roles' => ['admin']
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'data' => [
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email']
            ]
        ]
    ]);

} catch (Exception $e) {
    $code = $e->getCode() ?: 500;
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}