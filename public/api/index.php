<?php

/**
 * Platform API Entry Point
 * All API endpoints for the platform
 *
 * @package EduTrack
 * @subpackage API\Platform
 * @version 2.0
 */

// Start session for web login
session_start();

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================
// HANDLE PATH FROM .HTACCESS
// ============================================================
// Get the request path - the .htaccess passes it as 'path' parameter
$path = isset($_GET['path']) ? $_GET['path'] : '';

// If path is empty, try to get from REQUEST_URI
if (empty($path)) {
    $requestUrl = $_SERVER['REQUEST_URI'];
    $parsedUrl = parse_url($requestUrl);
    $path = ltrim($parsedUrl['path'], '/');
}

// Remove 'api/' from the beginning if present
if (strpos($path, 'api/') === 0) {
    $path = substr($path, 4);
}

// Log for debugging
error_log("API Request - path: " . $path);
error_log("API Request - GET: " . json_encode($_GET));
error_log("API Request - METHOD: " . $_SERVER['REQUEST_METHOD']);

// ============================================================
// DETERMINE ENDPOINT
// ============================================================
// Try to get endpoint from path
$endpoint = '';
$pathParts = explode('/', $path);

// Check if this is a school-settings request (routes handled separately)
if (isset($pathParts[0]) && $pathParts[0] === 'school-settings') {
    // This will be handled by the router in AssessmentRoutes.php
    // We just set the endpoint and let the router handle it
    $endpoint = 'school-settings';
} else {
    // Default endpoint from GET or path
    $endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : (isset($pathParts[0]) ? $pathParts[0] : '');
}

// If endpoint is empty, try 'test' as default
if (empty($endpoint)) {
    $endpoint = 'test';
}

error_log("Final endpoint: " . $endpoint);

// Get query parameters
$queryParams = $_GET;

// ============================================================
// PROJECT ROOT
// ============================================================
$projectRoot = dirname(__DIR__, 2) . '/';

// ============================================================
// HELPER FUNCTIONS
// ============================================================

function authenticate()
{
    global $projectRoot;

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = '';
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
    }

    if (empty($token)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required', 'timestamp' => date('Y-m-d H:i:s')]);
        exit;
    }

    try {
        require_once $projectRoot . 'app/helpers/JwtHelper.php';
        $jwt = new JwtHelper();
        $payload = $jwt->validateToken($token);
        if (!$payload || !isset($payload['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid token', 'timestamp' => date('Y-m-d H:i:s')]);
            exit;
        }
        return $payload;
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid token: ' . $e->getMessage(), 'timestamp' => date('Y-m-d H:i:s')]);
        exit;
    }
}

function getRequestData()
{
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!$data) {
        $data = $_POST;
    }
    return $data;
}

function jsonResponse($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ============================================================
// ROUTER FOR SCHOOL SETTINGS
// ============================================================
// Load router and assessment routes for school-settings
if ($endpoint === 'school-settings') {
    try {
        require_once $projectRoot . 'app/routes/Router.php';
        require_once $projectRoot . 'app/routes/Platform/AssessmentRoutes.php';

        $router = new Router();
        AssessmentRoutes::register($router);

        // Dispatch the request
        $router->dispatch($_SERVER['REQUEST_METHOD'], '/' . $path);
        exit;
    } catch (Exception $e) {
        error_log('School Settings Router error: ' . $e->getMessage());
        jsonResponse([
            'success' => false,
            'message' => 'School Settings API error: ' . $e->getMessage()
        ], 500);
    }
}

// ============================================================
// ROUTER
// ============================================================

error_log("API Router: endpoint=" . $endpoint . ", method=" . $_SERVER['REQUEST_METHOD']);

switch ($endpoint) {

    // ============================================================
    // TEST ENDPOINT
    // ============================================================
    case 'test':
        jsonResponse([
            'success' => true,
            'message' => 'API is working!',
            'endpoint' => $endpoint,
            'path' => $path,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        break;

    // ============================================================
    // AUTH - LOGIN
    // ============================================================
    case 'login':
        $data = getRequestData();

        if (empty($data['username']) || empty($data['password'])) {
            jsonResponse(['success' => false, 'message' => 'Username and password required', 'timestamp' => date('Y-m-d H:i:s')], 400);
        }

        try {
            require_once $projectRoot . 'app/helpers/JwtHelper.php';
            require_once $projectRoot . 'app/models/Platform/PlatformUser.php';
            require_once $projectRoot . 'app/models/Platform/PlatformAuditLog.php';

            $userModel = new PlatformUser();
            $auditModel = new PlatformAuditLog();

            $user = $userModel->findByUsername($data['username']);
            if (!$user) {
                $user = $userModel->findByEmail($data['username']);
            }

            if (!$user) {
                $auditModel->log([
                    'user_id' => null,
                    'action_type' => 'LOGIN_FAILED',
                    'module' => 'Auth',
                    'resource' => 'login',
                    'resource_id' => 0,
                    'new_data' => json_encode(['username' => $data['username']]),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
                jsonResponse(['success' => false, 'message' => 'Invalid credentials', 'timestamp' => date('Y-m-d H:i:s')], 401);
            }

            if (!$user['is_active']) {
                jsonResponse(['success' => false, 'message' => 'Account is inactive', 'timestamp' => date('Y-m-d H:i:s')], 401);
            }

            if (!password_verify($data['password'], $user['password_hash'])) {
                $auditModel->log([
                    'user_id' => $user['id'],
                    'action_type' => 'LOGIN_FAILED',
                    'module' => 'Auth',
                    'resource' => 'login',
                    'resource_id' => $user['id'],
                    'new_data' => json_encode(['username' => $data['username']]),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
                jsonResponse(['success' => false, 'message' => 'Invalid credentials', 'timestamp' => date('Y-m-d H:i:s')], 401);
            }

            $roles = $userModel->getRoles($user['id']);
            $roleCodes = array_column($roles, 'code');

            $jwt = new JwtHelper();
            $token = $jwt->generateToken([
                'user_id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'roles' => $roleCodes
            ]);

            $userModel->update($user['id'], ['last_login' => date('Y-m-d H:i:s')]);

            $auditModel->log([
                'user_id' => $user['id'],
                'action_type' => 'LOGIN_SUCCESS',
                'module' => 'Auth',
                'resource' => 'login',
                'resource_id' => $user['id'],
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['token'] = $token;
            $_SESSION['roles'] = $roleCodes;
            $_SESSION['logged_in'] = true;

            jsonResponse([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'token' => $token,
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'email' => $user['email'],
                        'first_name' => $user['first_name'],
                        'last_name' => $user['last_name'],
                        'roles' => $roleCodes
                    ]
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            error_log('Login error: ' . $e->getMessage());
            jsonResponse(['success' => false, 'message' => 'Login failed: ' . $e->getMessage(), 'timestamp' => date('Y-m-d H:i:s')], 500);
        }
        break;

    // ============================================================
    // AUTH - LOGOUT
    // ============================================================
    case 'logout':
        $_SESSION = array();
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
        jsonResponse(['success' => true, 'message' => 'Logged out successfully', 'timestamp' => date('Y-m-d H:i:s')]);
        break;

    // ============================================================
    // USERS
    // ============================================================
    case 'users':
        $payload = authenticate();
        try {
            require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
            $db = DatabaseHelper::getInstance();
            $method = $_SERVER['REQUEST_METHOD'];

            // GET - List users or get single user
            if ($method == 'GET') {
                // Stats
                if (isset($queryParams['stats']) && $queryParams['stats'] == 'true') {
                    try {
                        $total = $db->fetchOne("SELECT COUNT(*) as total FROM platform_users WHERE deleted_at IS NULL");
                        $active = $db->fetchOne("SELECT COUNT(*) as active FROM platform_users WHERE is_active = 1 AND deleted_at IS NULL");
                        $pending = $db->fetchOne("SELECT COUNT(*) as pending FROM platform_users WHERE is_active = 0 AND deleted_at IS NULL");

                        echo json_encode([
                            'success' => true,
                            'data' => [
                                'total' => (int)($total['total'] ?? 0),
                                'active' => (int)($active['active'] ?? 0),
                                'pending' => (int)($pending['pending'] ?? 0),
                                'admins' => 0
                            ]
                        ]);
                        exit;
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                        exit;
                    }
                }

                // Get single user
                if (isset($queryParams['id']) && is_numeric($queryParams['id'])) {
                    $userId = (int)$queryParams['id'];
                    $user = $db->fetchOne("SELECT * FROM platform_users WHERE id = ? AND deleted_at IS NULL", [$userId]);
                    if (!$user) {
                        echo json_encode(['success' => false, 'message' => 'User not found']);
                        exit;
                    }

                    // Get roles
                    $roles = $db->fetchAll("SELECT r.role_code FROM platform_roles r
                                        JOIN platform_user_roles ur ON r.id = ur.role_id
                                        WHERE ur.user_id = ?", [$userId]);
                    $user['roles'] = array_column($roles, 'role_code');
                    unset($user['password_hash']);
                    echo json_encode(['success' => true, 'data' => $user]);
                    exit;
                }

                // List users
                $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 1;
                $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 20;
                $offset = ($page - 1) * $limit;

                // Get users - NO tenant_id
                $sql = "SELECT * FROM platform_users WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT ? OFFSET ?";
                $users = $db->fetchAll($sql, [$limit, $offset]);

                // Get total count
                $countResult = $db->fetchOne("SELECT COUNT(*) as total FROM platform_users WHERE deleted_at IS NULL");
                $total = (int)($countResult['total'] ?? 0);

                // Get roles for each user
                foreach ($users as &$user) {
                    $roles = $db->fetchAll("SELECT r.role_code FROM platform_roles r
                                        JOIN platform_user_roles ur ON r.id = ur.role_id
                                        WHERE ur.user_id = ?", [$user['id']]);
                    $user['roles'] = array_column($roles, 'role_code');
                    unset($user['password_hash']);
                }

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'users' => $users,
                        'total' => $total,
                        'page' => $page,
                        'per_page' => $limit,
                        'total_pages' => max(1, ceil($total / $limit))
                    ]
                ]);
                exit;
            }

            // POST - Create user
            if ($method == 'POST') {
                $data = getRequestData();

                // Log the incoming data
                error_log('=== CREATE USER ===');
                error_log('Data: ' . json_encode($data));

                // Validate required fields
                $required = ['first_name', 'last_name', 'username', 'email', 'password', 'role'];
                foreach ($required as $field) {
                    if (empty($data[$field])) {
                        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
                        exit;
                    }
                }

                // Check username
                $existing = $db->fetchOne("SELECT id FROM platform_users WHERE username = ? AND deleted_at IS NULL", [$data['username']]);
                if ($existing) {
                    echo json_encode(['success' => false, 'message' => 'Username already exists']);
                    exit;
                }

                // Check email
                $existing = $db->fetchOne("SELECT id FROM platform_users WHERE email = ? AND deleted_at IS NULL", [$data['email']]);
                if ($existing) {
                    echo json_encode(['success' => false, 'message' => 'Email already exists']);
                    exit;
                }

                // Find role
                $role = $db->fetchOne("SELECT id FROM platform_roles WHERE role_code = ?", [$data['role']]);
                if (!$role) {
                    echo json_encode(['success' => false, 'message' => 'Invalid role: ' . $data['role']]);
                    exit;
                }

                // Generate UUID
                $uuid = sprintf(
                    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff) | 0x4000,
                    mt_rand(0, 0x3ffff) | 0x8000,
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff)
                );

                $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);

                // Insert user - NO tenant_id
                $sql = "INSERT INTO platform_users (
                uuid, username, email, password_hash, first_name, last_name, phone,
                is_active, created_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

                $db->query($sql, [
                    $uuid,
                    $data['username'],
                    $data['email'],
                    $passwordHash,
                    $data['first_name'],
                    $data['last_name'],
                    $data['phone'] ?? null,
                    $data['is_active'] ?? 1,
                    $payload['user_id'] ?? 1
                ]);

                $userId = $db->lastInsertId();

                // Assign role
                $db->query("INSERT INTO platform_user_roles (user_id, role_id) VALUES (?, ?)", [$userId, $role['id']]);

                error_log('User created with ID: ' . $userId);

                echo json_encode([
                    'success' => true,
                    'message' => 'User created successfully',
                    'data' => ['id' => $userId]
                ]);
                exit;
            }

            // PUT - Update user
            if ($method == 'PUT') {
                $userId = isset($queryParams['id']) && is_numeric($queryParams['id']) ? (int)$queryParams['id'] : null;
                if (!$userId) {
                    echo json_encode(['success' => false, 'message' => 'User ID required']);
                    exit;
                }

                // Handle activate/deactivate
                if (isset($queryParams['action'])) {
                    if ($queryParams['action'] == 'activate') {
                        $db->query("UPDATE platform_users SET is_active = 1, updated_at = NOW() WHERE id = ?", [$userId]);
                        echo json_encode(['success' => true, 'message' => 'User activated']);
                        exit;
                    } elseif ($queryParams['action'] == 'deactivate') {
                        $db->query("UPDATE platform_users SET is_active = 0, updated_at = NOW() WHERE id = ?", [$userId]);
                        echo json_encode(['success' => true, 'message' => 'User deactivated']);
                        exit;
                    }
                }

                $user = $db->fetchOne("SELECT * FROM platform_users WHERE id = ? AND deleted_at IS NULL", [$userId]);
                if (!$user) {
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                    exit;
                }

                $data = getRequestData();
                $updateFields = [];
                $params = [];

                $allowedFields = ['first_name', 'last_name', 'username', 'email', 'phone', 'is_active'];
                foreach ($allowedFields as $field) {
                    if (isset($data[$field])) {
                        $updateFields[] = "$field = ?";
                        $params[] = $data[$field];
                    }
                }

                if (isset($data['password']) && !empty($data['password'])) {
                    $updateFields[] = "password_hash = ?";
                    $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
                }

                if (empty($updateFields)) {
                    echo json_encode(['success' => false, 'message' => 'No fields to update']);
                    exit;
                }

                $params[] = $userId;
                $db->query("UPDATE platform_users SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE id = ?", $params);

                // Update role
                if (isset($data['role']) && !empty($data['role'])) {
                    $role = $db->fetchOne("SELECT id FROM platform_roles WHERE role_code = ?", [$data['role']]);
                    if ($role) {
                        $db->query("DELETE FROM platform_user_roles WHERE user_id = ?", [$userId]);
                        $db->query("INSERT INTO platform_user_roles (user_id, role_id) VALUES (?, ?)", [$userId, $role['id']]);
                    }
                }

                echo json_encode(['success' => true, 'message' => 'User updated successfully']);
                exit;
            }

            // DELETE
            if ($method == 'DELETE') {
                $userId = isset($queryParams['id']) && is_numeric($queryParams['id']) ? (int)$queryParams['id'] : null;
                if (!$userId) {
                    echo json_encode(['success' => false, 'message' => 'User ID required']);
                    exit;
                }

                // Soft delete
                $db->query("UPDATE platform_users SET deleted_at = NOW() WHERE id = ?", [$userId]);
                echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
                exit;
            }

            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        } catch (Exception $e) {
            error_log('Users error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to process users: ' . $e->getMessage()]);
            exit;
        }
        break;

    // ============================================================
    // TENANTS
    // ============================================================
    case 'tenants':
        $payload = authenticate();
        try {
            require_once $projectRoot . 'app/models/Platform/Tenant.php';
            $tenantModel = new Tenant();
            $method = $_SERVER['REQUEST_METHOD'];

            if ($method == 'GET') {
                // Check for stats
                if (isset($queryParams['stats']) && $queryParams['stats'] == 'true') {
                    try {
                        $db = DatabaseHelper::getInstance();
                        $total = $db->fetchOne("SELECT COUNT(*) as total FROM tenants WHERE deleted_at IS NULL");
                        $active = $db->fetchOne("SELECT COUNT(*) as active FROM tenants WHERE status = 'active' AND deleted_at IS NULL");

                        echo json_encode([
                            'success' => true,
                            'data' => [
                                'total' => $total['total'] ?? 0,
                                'active' => $active['active'] ?? 0,
                                'pending' => 0,
                                'suspended' => 0
                            ]
                        ]);
                        exit;
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                        exit;
                    }
                }

                // Get single tenant
                if (isset($queryParams['id']) && is_numeric($queryParams['id'])) {
                    $tenant = $tenantModel->find((int)$queryParams['id']);
                    if ($tenant) {
                        echo json_encode(['success' => true, 'data' => $tenant]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Tenant not found']);
                    }
                    exit;
                }

                // List tenants - DIRECT DATABASE QUERY (bypassing TenantService)
                try {
                    $db = DatabaseHelper::getInstance();

                    // Build query
                    $sql = "SELECT * FROM tenants WHERE deleted_at IS NULL";
                    $params = [];

                    // Apply filters
                    if (isset($queryParams['search']) && !empty($queryParams['search'])) {
                        $sql .= " AND (tenant_name LIKE ? OR legal_name LIKE ? OR email LIKE ?)";
                        $search = '%' . $queryParams['search'] . '%';
                        $params[] = $search;
                        $params[] = $search;
                        $params[] = $search;
                    }

                    if (isset($queryParams['status']) && !empty($queryParams['status'])) {
                        $sql .= " AND status = ?";
                        $params[] = $queryParams['status'];
                    }

                    $sql .= " ORDER BY tenant_name ASC";

                    // Get total count
                    $countSql = str_replace("SELECT *", "SELECT COUNT(*) as total", $sql);
                    $countResult = $db->fetchOne($countSql, $params);
                    $total = $countResult['total'] ?? 0;

                    // Apply pagination
                    $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 1;
                    $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 100;
                    $offset = ($page - 1) * $limit;
                    $sql .= " LIMIT ? OFFSET ?";
                    $params[] = $limit;
                    $params[] = $offset;

                    $tenants = $db->fetchAll($sql, $params);

                    echo json_encode([
                        'success' => true,
                        'data' => $tenants,
                        'total' => $total,
                        'page' => $page,
                        'per_page' => $limit,
                        'total_pages' => ceil($total / $limit)
                    ]);
                    exit;
                } catch (Exception $e) {
                    error_log('Tenants query error: ' . $e->getMessage());
                    echo json_encode([
                        'success' => false,
                        'message' => 'Database error: ' . $e->getMessage()
                    ]);
                    exit;
                }
            }

            // POST - Create tenant
            if ($method == 'POST') {
                $data = getRequestData();
                $data['created_by'] = $payload['user_id'] ?? 1;
                $result = $tenantModel->create($data);
                echo json_encode($result);
                exit;
            }

            // PUT - Update tenant
            if ($method == 'PUT') {
                $tenantId = isset($queryParams['id']) && is_numeric($queryParams['id']) ? (int)$queryParams['id'] : null;
                if (!$tenantId) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Tenant ID required']);
                    exit;
                }
                $data = getRequestData();
                $result = $tenantModel->update($tenantId, $data);
                echo json_encode($result);
                exit;
            }

            // DELETE - Delete tenant
            if ($method == 'DELETE') {
                $tenantId = isset($queryParams['id']) && is_numeric($queryParams['id']) ? (int)$queryParams['id'] : null;
                if (!$tenantId) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Tenant ID required']);
                    exit;
                }
                $result = $tenantModel->delete($tenantId);
                echo json_encode($result);
                exit;
            }

            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        } catch (Exception $e) {
            error_log('Tenants error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to process tenants: ' . $e->getMessage()]);
            exit;
        }
        break;

    // ============================================================
    // SUBSCRIPTIONS - PLANS
    // ============================================================
    case 'subscriptions/plans':
        $payload = authenticate();
        try {
            require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
            $db = DatabaseHelper::getInstance();
            $method = $_SERVER['REQUEST_METHOD'];

            // GET - List plans or get single plan
            if ($method == 'GET') {
                // Check for stats
                if (isset($queryParams['stats']) && $queryParams['stats'] == 'true') {
                    try {
                        $total = $db->fetchOne("SELECT COUNT(*) as total FROM subscription_plans WHERE deleted_at IS NULL");
                        $active = $db->fetchOne("SELECT COUNT(*) as active FROM subscription_plans WHERE status = 'active' AND deleted_at IS NULL");
                        $tenants = $db->fetchOne("SELECT COUNT(DISTINCT tenant_id) as tenants FROM tenant_subscriptions WHERE status = 'active'");
                        $revenue = $db->fetchOne("SELECT SUM(price) as revenue FROM subscription_plans WHERE status = 'active' AND deleted_at IS NULL");

                        echo json_encode([
                            'success' => true,
                            'data' => [
                                'total' => (int)($total['total'] ?? 0),
                                'active' => (int)($active['active'] ?? 0),
                                'tenants' => (int)($tenants['tenants'] ?? 0),
                                'monthly_revenue' => (float)($revenue['revenue'] ?? 0)
                            ]
                        ]);
                        exit;
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                        exit;
                    }
                }

                // Get single plan
                if (isset($queryParams['id']) && is_numeric($queryParams['id'])) {
                    $planId = (int)$queryParams['id'];
                    $plan = $db->fetchOne("SELECT * FROM subscription_plans WHERE id = ? AND deleted_at IS NULL", [$planId]);
                    if (!$plan) {
                        echo json_encode(['success' => false, 'message' => 'Plan not found']);
                        exit;
                    }
                    echo json_encode(['success' => true, 'data' => $plan]);
                    exit;
                }

                // List plans
                $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 1;
                $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 20;
                $offset = ($page - 1) * $limit;

                $sql = "SELECT * FROM subscription_plans WHERE deleted_at IS NULL ORDER BY price ASC, plan_name ASC LIMIT ? OFFSET ?";
                $plans = $db->fetchAll($sql, [$limit, $offset]);

                $countResult = $db->fetchOne("SELECT COUNT(*) as total FROM subscription_plans WHERE deleted_at IS NULL");
                $total = (int)($countResult['total'] ?? 0);

                // Get tenant count for each plan
                foreach ($plans as &$plan) {
                    $count = $db->fetchOne("SELECT COUNT(*) as count FROM tenant_subscriptions WHERE plan_id = ? AND status = 'active'", [$plan['id']]);
                    $plan['tenant_count'] = (int)($count['count'] ?? 0);
                }

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'plans' => $plans,
                        'total' => $total,
                        'page' => $page,
                        'per_page' => $limit,
                        'total_pages' => max(1, ceil($total / $limit))
                    ]
                ]);
                exit;
            }

            // POST - Create plan
            if ($method == 'POST') {
                $data = getRequestData();

                // Validate required fields
                $required = ['plan_name', 'plan_code', 'price', 'billing_cycle'];
                foreach ($required as $field) {
                    if (empty($data[$field])) {
                        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
                        exit;
                    }
                }

                // Check if plan code exists
                $existing = $db->fetchOne("SELECT id FROM subscription_plans WHERE plan_code = ? AND deleted_at IS NULL", [$data['plan_code']]);
                if ($existing) {
                    echo json_encode(['success' => false, 'message' => 'Plan code already exists']);
                    exit;
                }

                // Insert plan
                $sql = "INSERT INTO subscription_plans (
                plan_name, plan_code, description, price, billing_cycle, status,
                max_students, max_staff, max_campuses, max_schools,
                max_storage_mb, max_api_calls, max_ai_requests, sms_balance,
                created_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

                $db->query($sql, [
                    $data['plan_name'],
                    $data['plan_code'],
                    $data['description'] ?? null,
                    $data['price'],
                    $data['billing_cycle'],
                    $data['status'] ?? 'active',
                    $data['max_students'] ?? 0,
                    $data['max_staff'] ?? 0,
                    $data['max_campuses'] ?? 1,
                    $data['max_schools'] ?? 1,
                    $data['max_storage_mb'] ?? 1024,
                    $data['max_api_calls'] ?? 1000,
                    $data['max_ai_requests'] ?? 10,
                    $data['sms_balance'] ?? 0,
                    $payload['user_id'] ?? 1
                ]);

                $planId = $db->lastInsertId();

                echo json_encode([
                    'success' => true,
                    'message' => 'Plan created successfully',
                    'data' => ['id' => $planId]
                ]);
                exit;
            }

            // PUT - Update plan
            if ($method == 'PUT') {
                $planId = isset($queryParams['id']) && is_numeric($queryParams['id']) ? (int)$queryParams['id'] : null;
                if (!$planId) {
                    echo json_encode(['success' => false, 'message' => 'Plan ID required']);
                    exit;
                }

                // Handle activate/deactivate
                if (isset($queryParams['action'])) {
                    if ($queryParams['action'] == 'activate') {
                        $db->query("UPDATE subscription_plans SET status = 'active', updated_at = NOW() WHERE id = ?", [$planId]);
                        echo json_encode(['success' => true, 'message' => 'Plan activated']);
                        exit;
                    } elseif ($queryParams['action'] == 'deactivate') {
                        $db->query("UPDATE subscription_plans SET status = 'inactive', updated_at = NOW() WHERE id = ?", [$planId]);
                        echo json_encode(['success' => true, 'message' => 'Plan deactivated']);
                        exit;
                    }
                }

                $plan = $db->fetchOne("SELECT * FROM subscription_plans WHERE id = ? AND deleted_at IS NULL", [$planId]);
                if (!$plan) {
                    echo json_encode(['success' => false, 'message' => 'Plan not found']);
                    exit;
                }

                $data = getRequestData();
                $updateFields = [];
                $params = [];

                $allowedFields = [
                    'plan_name',
                    'plan_code',
                    'description',
                    'price',
                    'billing_cycle',
                    'status',
                    'max_students',
                    'max_staff',
                    'max_campuses',
                    'max_schools',
                    'max_storage_mb',
                    'max_api_calls',
                    'max_ai_requests',
                    'sms_balance'
                ];
                foreach ($allowedFields as $field) {
                    if (isset($data[$field])) {
                        $updateFields[] = "$field = ?";
                        $params[] = $data[$field];
                    }
                }

                if (empty($updateFields)) {
                    echo json_encode(['success' => false, 'message' => 'No fields to update']);
                    exit;
                }

                $params[] = $planId;
                $db->query("UPDATE subscription_plans SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE id = ?", $params);

                echo json_encode(['success' => true, 'message' => 'Plan updated successfully']);
                exit;
            }

            // DELETE - Soft delete plan
            if ($method == 'DELETE') {
                $planId = isset($queryParams['id']) && is_numeric($queryParams['id']) ? (int)$queryParams['id'] : null;
                if (!$planId) {
                    echo json_encode(['success' => false, 'message' => 'Plan ID required']);
                    exit;
                }

                // Check if plan is in use
                $inUse = $db->fetchOne("SELECT COUNT(*) as count FROM tenant_subscriptions WHERE plan_id = ? AND status = 'active'", [$planId]);
                if ((int)($inUse['count'] ?? 0) > 0) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete plan that is currently in use by active subscriptions']);
                    exit;
                }

                $db->query("UPDATE subscription_plans SET deleted_at = NOW() WHERE id = ?", [$planId]);
                echo json_encode(['success' => true, 'message' => 'Plan deleted successfully']);
                exit;
            }

            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        } catch (Exception $e) {
            error_log('Subscriptions plans error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to process subscription plans: ' . $e->getMessage()]);
            exit;
        }
        break;

    // ============================================================
    // SUBSCRIPTIONS - ASSIGN PLAN TO TENANT
    // ============================================================
    case 'subscriptions/assign':
        $payload = authenticate();
        try {
            require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
            $db = DatabaseHelper::getInstance();
            $method = $_SERVER['REQUEST_METHOD'];

            if ($method == 'POST') {
                $data = getRequestData();

                // Validate required fields
                if (empty($data['tenant_id']) || empty($data['plan_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Tenant ID and Plan ID are required']);
                    exit;
                }

                // Check if tenant exists
                $tenant = $db->fetchOne("SELECT id FROM tenants WHERE id = ? AND deleted_at IS NULL", [$data['tenant_id']]);
                if (!$tenant) {
                    echo json_encode(['success' => false, 'message' => 'Tenant not found']);
                    exit;
                }

                // Check if plan exists
                $plan = $db->fetchOne("SELECT id FROM subscription_plans WHERE id = ? AND deleted_at IS NULL", [$data['plan_id']]);
                if (!$plan) {
                    echo json_encode(['success' => false, 'message' => 'Plan not found']);
                    exit;
                }

                // Check if tenant already has an active subscription
                $existing = $db->fetchOne("SELECT id FROM tenant_subscriptions WHERE tenant_id = ? AND status = 'active'", [$data['tenant_id']]);
                if ($existing) {
                    echo json_encode(['success' => false, 'message' => 'Tenant already has an active subscription']);
                    exit;
                }

                // Create subscription
                $startDate = date('Y-m-d H:i:s');
                $endDate = null;

                // Set end date based on billing cycle
                $planDetails = $db->fetchOne("SELECT billing_cycle FROM subscription_plans WHERE id = ?", [$data['plan_id']]);
                if ($planDetails) {
                    switch ($planDetails['billing_cycle']) {
                        case 'monthly':
                            $endDate = date('Y-m-d H:i:s', strtotime('+1 month'));
                            break;
                        case 'quarterly':
                            $endDate = date('Y-m-d H:i:s', strtotime('+3 months'));
                            break;
                        case 'semiannual':
                            $endDate = date('Y-m-d H:i:s', strtotime('+6 months'));
                            break;
                        case 'annual':
                            $endDate = date('Y-m-d H:i:s', strtotime('+1 year'));
                            break;
                        case 'lifetime':
                            $endDate = null;
                            break;
                        default:
                            $endDate = date('Y-m-d H:i:s', strtotime('+1 month'));
                    }
                }

                $sql = "INSERT INTO tenant_subscriptions (
                tenant_id, plan_id, status, start_date, end_date,
                created_by, created_at, updated_at
            ) VALUES (?, ?, 'active', ?, ?, ?, NOW(), NOW())";

                $db->query($sql, [
                    $data['tenant_id'],
                    $data['plan_id'],
                    $startDate,
                    $endDate,
                    $payload['user_id'] ?? 1
                ]);

                $subscriptionId = $db->lastInsertId();

                echo json_encode([
                    'success' => true,
                    'message' => 'Subscription assigned successfully',
                    'data' => ['id' => $subscriptionId]
                ]);
                exit;
            }

            if ($method == 'GET') {
                $tenantId = isset($queryParams['tenant_id']) && is_numeric($queryParams['tenant_id']) ? (int)$queryParams['tenant_id'] : null;
                if (!$tenantId) {
                    echo json_encode(['success' => false, 'message' => 'Tenant ID required']);
                    exit;
                }

                $subscription = $db->fetchOne("
                SELECT ts.*, sp.plan_name, sp.plan_code, sp.price, sp.billing_cycle
                FROM tenant_subscriptions ts
                JOIN subscription_plans sp ON ts.plan_id = sp.id
                WHERE ts.tenant_id = ? AND ts.status = 'active'
            ", [$tenantId]);

                echo json_encode(['success' => true, 'data' => $subscription]);
                exit;
            }

            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        } catch (Exception $e) {
            error_log('Subscriptions assign error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to assign subscription: ' . $e->getMessage()]);
            exit;
        }
        break;

    // ============================================================
    // MONITORING / DASHBOARD
    // ============================================================
    case 'monitoring':
    case 'dashboard':
        $payload = authenticate();
        try {
            require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
            $db = DatabaseHelper::getInstance();
            $method = $_SERVER['REQUEST_METHOD'];

            if ($method == 'GET') {
                // Get monthly growth data
                if (isset($queryParams['monthly_growth']) && $queryParams['monthly_growth'] == 'true') {
                    $months = isset($queryParams['months']) ? (int)$queryParams['months'] : 12;

                    $sql = "SELECT
                            DATE_FORMAT(created_at, '%b %Y') as month,
                            COUNT(*) as count
                        FROM tenants
                        WHERE deleted_at IS NULL
                        AND created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)
                        GROUP BY YEAR(created_at), MONTH(created_at)
                        ORDER BY YEAR(created_at) ASC, MONTH(created_at) ASC";

                    $data = $db->fetchAll($sql, [$months]);

                    // Fill in missing months
                    $result = [];
                    $currentDate = new DateTime();
                    $currentDate->modify('-' . ($months - 1) . ' months');

                    $dataMap = [];
                    foreach ($data as $item) {
                        $dataMap[$item['month']] = (int)$item['count'];
                    }

                    for ($i = 0; $i < $months; $i++) {
                        $monthLabel = $currentDate->format('M Y');
                        $result[] = [
                            'month' => $monthLabel,
                            'count' => $dataMap[$monthLabel] ?? 0
                        ];
                        $currentDate->modify('+1 month');
                    }

                    echo json_encode(['success' => true, 'data' => $result]);
                    exit;
                }

                // Get revenue distribution
                if (isset($queryParams['revenue']) && $queryParams['revenue'] == 'true') {
                    $sql = "SELECT
                            sp.plan_name,
                            SUM(sp.price) as revenue
                        FROM tenant_subscriptions ts
                        JOIN subscription_plans sp ON ts.plan_id = sp.id
                        WHERE ts.status = 'active'
                        GROUP BY ts.plan_id
                        ORDER BY revenue DESC";

                    $data = $db->fetchAll($sql);
                    echo json_encode(['success' => true, 'data' => $data]);
                    exit;
                }

                // Get top tenants
                if (isset($queryParams['top_tenants']) && $queryParams['top_tenants'] == 'true') {
                    $metric = $queryParams['metric'] ?? 'revenue';
                    $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 10;

                    $sql = "SELECT
                            t.id,
                            t.tenant_name,
                            COUNT(DISTINCT u.id) as users,
                            COUNT(DISTINCT ts.id) as subscriptions,
                            COALESCE(SUM(sp.price), 0) as revenue
                        FROM tenants t
                        LEFT JOIN platform_users u ON u.tenant_id = t.id AND u.deleted_at IS NULL
                        LEFT JOIN tenant_subscriptions ts ON ts.tenant_id = t.id AND ts.status = 'active'
                        LEFT JOIN subscription_plans sp ON ts.plan_id = sp.id
                        WHERE t.deleted_at IS NULL
                        GROUP BY t.id
                        ORDER BY revenue DESC
                        LIMIT ?";

                    $data = $db->fetchAll($sql, [$limit]);
                    echo json_encode(['success' => true, 'data' => $data]);
                    exit;
                }

                // Get main dashboard stats
                $totalTenants = $db->fetchOne("SELECT COUNT(*) as total FROM tenants WHERE deleted_at IS NULL");
                $totalUsers = $db->fetchOne("SELECT COUNT(*) as total FROM platform_users WHERE deleted_at IS NULL");
                $activeSubscriptions = $db->fetchOne("SELECT COUNT(*) as total FROM tenant_subscriptions WHERE status = 'active'");
                $monthlyRevenue = $db->fetchOne("SELECT COALESCE(SUM(sp.price), 0) as revenue
                                            FROM tenant_subscriptions ts
                                            JOIN subscription_plans sp ON ts.plan_id = sp.id
                                            WHERE ts.status = 'active'");

                // Calculate growth (compare with last month)
                $lastMonthTenants = $db->fetchOne("SELECT COUNT(*) as total FROM tenants WHERE deleted_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)");
                $lastMonthUsers = $db->fetchOne("SELECT COUNT(*) as total FROM platform_users WHERE deleted_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)");
                $lastMonthRevenue = $db->fetchOne("SELECT COALESCE(SUM(sp.price), 0) as revenue
                                              FROM tenant_subscriptions ts
                                              JOIN subscription_plans sp ON ts.plan_id = sp.id
                                              WHERE ts.status = 'active' AND ts.created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)");

                $tenantGrowth = $lastMonthTenants['total'] > 0 ? round((($totalTenants['total'] - $lastMonthTenants['total']) / $lastMonthTenants['total']) * 100, 1) : 0;
                $userGrowth = $lastMonthUsers['total'] > 0 ? round((($totalUsers['total'] - $lastMonthUsers['total']) / $lastMonthUsers['total']) * 100, 1) : 0;
                $revenueGrowth = $lastMonthRevenue['revenue'] > 0 ? round((($monthlyRevenue['revenue'] - $lastMonthRevenue['revenue']) / $lastMonthRevenue['revenue']) * 100, 1) : 0;

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'total_tenants' => (int)($totalTenants['total'] ?? 0),
                        'total_users' => (int)($totalUsers['total'] ?? 0),
                        'active_subscriptions' => (int)($activeSubscriptions['total'] ?? 0),
                        'monthly_revenue' => (float)($monthlyRevenue['revenue'] ?? 0),
                        'tenant_growth' => $tenantGrowth,
                        'user_growth' => $userGrowth,
                        'revenue_growth' => $revenueGrowth
                    ]
                ]);
                exit;
            }

            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        } catch (Exception $e) {
            error_log('Monitoring error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to get monitoring data: ' . $e->getMessage()]);
            exit;
        }
        break;

    // ============================================================
    // AUDIT LOGS
    // ============================================================
    case 'audit-logs':
        $payload = authenticate();
        try {
            require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
            $db = DatabaseHelper::getInstance();
            $method = $_SERVER['REQUEST_METHOD'];

            // GET - List logs or get single log
            if ($method == 'GET') {
                // Check for stats
                if (isset($queryParams['stats']) && $queryParams['stats'] == 'true') {
                    try {
                        $total = $db->fetchOne("SELECT COUNT(*) as total FROM platform_audit_logs");
                        $today = $db->fetchOne("SELECT COUNT(*) as today FROM platform_audit_logs WHERE DATE(created_at) = CURDATE()");
                        $uniqueUsers = $db->fetchOne("SELECT COUNT(DISTINCT user_id) as users FROM platform_audit_logs WHERE user_id IS NOT NULL");
                        $lastActivity = $db->fetchOne("SELECT created_at FROM platform_audit_logs ORDER BY created_at DESC LIMIT 1");

                        echo json_encode([
                            'success' => true,
                            'data' => [
                                'total' => (int)($total['total'] ?? 0),
                                'today' => (int)($today['today'] ?? 0),
                                'unique_users' => (int)($uniqueUsers['users'] ?? 0),
                                'last_activity' => $lastActivity['created_at'] ?? null
                            ]
                        ]);
                        exit;
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                        exit;
                    }
                }

                // Get single log
                if (isset($queryParams['id']) && is_numeric($queryParams['id'])) {
                    $logId = (int)$queryParams['id'];
                    $log = $db->fetchOne("SELECT * FROM platform_audit_logs WHERE id = ?", [$logId]);
                    if (!$log) {
                        echo json_encode(['success' => false, 'message' => 'Log not found']);
                        exit;
                    }
                    echo json_encode(['success' => true, 'data' => $log]);
                    exit;
                }

                // Export logs
                if (isset($queryParams['export']) && $queryParams['export'] == 'true') {
                    // Build query
                    $sql = "SELECT * FROM platform_audit_logs ORDER BY created_at DESC";
                    $params = [];

                    if (isset($queryParams['search']) && !empty($queryParams['search'])) {
                        $search = '%' . $queryParams['search'] . '%';
                        $sql = "SELECT * FROM platform_audit_logs WHERE
                            (description LIKE ? OR resource LIKE ? OR action_type LIKE ? OR module LIKE ?)
                            ORDER BY created_at DESC";
                        $params = [$search, $search, $search, $search];
                    }

                    if (isset($queryParams['action']) && !empty($queryParams['action'])) {
                        $sql = str_replace("ORDER BY", "AND action_type = ? ORDER BY", $sql);
                        $params[] = $queryParams['action'];
                    }

                    if (isset($queryParams['module']) && !empty($queryParams['module'])) {
                        $sql = str_replace("ORDER BY", "AND module = ? ORDER BY", $sql);
                        $params[] = $queryParams['module'];
                    }

                    if (isset($queryParams['tenant_id']) && !empty($queryParams['tenant_id'])) {
                        $sql = str_replace("ORDER BY", "AND tenant_id = ? ORDER BY", $sql);
                        $params[] = $queryParams['tenant_id'];
                    }

                    if (isset($queryParams['date_from']) && !empty($queryParams['date_from'])) {
                        $sql = str_replace("ORDER BY", "AND DATE(created_at) >= ? ORDER BY", $sql);
                        $params[] = $queryParams['date_from'];
                    }

                    if (isset($queryParams['date_to']) && !empty($queryParams['date_to'])) {
                        $sql = str_replace("ORDER BY", "AND DATE(created_at) <= ? ORDER BY", $sql);
                        $params[] = $queryParams['date_to'];
                    }

                    $logs = $db->fetchAll($sql, $params);

                    // Generate CSV
                    $filename = 'audit_logs_' . date('Y-m-d') . '.csv';
                    header('Content-Type: text/csv');
                    header('Content-Disposition: attachment; filename="' . $filename . '"');

                    $output = fopen('php://output', 'w');
                    fputcsv($output, ['ID', 'Time', 'User ID', 'Action', 'Module', 'Resource', 'Resource ID', 'Description', 'IP Address', 'Tenant ID']);

                    foreach ($logs as $log) {
                        fputcsv($output, [
                            $log['id'],
                            $log['created_at'],
                            $log['user_id'],
                            $log['action_type'],
                            $log['module'],
                            $log['resource'],
                            $log['resource_id'],
                            $log['description'],
                            $log['ip_address'],
                            $log['tenant_id']
                        ]);
                    }
                    fclose($output);
                    exit;
                }

                // List logs with filters
                $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 1;
                $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 20;
                $offset = ($page - 1) * $limit;

                $sql = "SELECT * FROM platform_audit_logs";
                $countSql = "SELECT COUNT(*) as total FROM platform_audit_logs";
                $params = [];
                $where = [];

                if (isset($queryParams['search']) && !empty($queryParams['search'])) {
                    $search = '%' . $queryParams['search'] . '%';
                    $where[] = "(description LIKE ? OR resource LIKE ? OR action_type LIKE ? OR module LIKE ?)";
                    $params[] = $search;
                    $params[] = $search;
                    $params[] = $search;
                    $params[] = $search;
                }

                if (isset($queryParams['action']) && !empty($queryParams['action'])) {
                    $where[] = "action_type = ?";
                    $params[] = $queryParams['action'];
                }

                if (isset($queryParams['module']) && !empty($queryParams['module'])) {
                    $where[] = "module = ?";
                    $params[] = $queryParams['module'];
                }

                if (isset($queryParams['tenant_id']) && !empty($queryParams['tenant_id'])) {
                    $where[] = "tenant_id = ?";
                    $params[] = $queryParams['tenant_id'];
                }

                if (isset($queryParams['date_from']) && !empty($queryParams['date_from'])) {
                    $where[] = "DATE(created_at) >= ?";
                    $params[] = $queryParams['date_from'];
                }

                if (isset($queryParams['date_to']) && !empty($queryParams['date_to'])) {
                    $where[] = "DATE(created_at) <= ?";
                    $params[] = $queryParams['date_to'];
                }

                if (!empty($where)) {
                    $sql .= " WHERE " . implode(" AND ", $where);
                    $countSql .= " WHERE " . implode(" AND ", $where);
                }

                $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
                $params[] = $limit;
                $params[] = $offset;

                $countResult = $db->fetchOne($countSql, array_slice($params, 0, count($params) - 2));
                $total = (int)($countResult['total'] ?? 0);

                $logs = $db->fetchAll($sql, $params);

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'logs' => $logs,
                        'total' => $total,
                        'page' => $page,
                        'per_page' => $limit,
                        'total_pages' => max(1, ceil($total / $limit))
                    ]
                ]);
                exit;
            }

            // DELETE - Clean old logs
            if ($method == 'DELETE') {
                if (isset($queryParams['clean']) && is_numeric($queryParams['clean'])) {
                    $days = (int)$queryParams['clean'];
                    $sql = "DELETE FROM platform_audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
                    $db->query($sql, [$days]);
                    $deleted = $db->rowCount();

                    echo json_encode([
                        'success' => true,
                        'message' => "Deleted $deleted old logs",
                        'data' => ['deleted' => $deleted]
                    ]);
                    exit;
                }

                echo json_encode(['success' => false, 'message' => 'Invalid request']);
                exit;
            }

            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        } catch (Exception $e) {
            error_log('Audit logs error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to process audit logs: ' . $e->getMessage()]);
            exit;
        }
        break;

    // ============================================================
    // PAYMENTS - STATS
    // ============================================================
    case 'payments/stats':
        $payload = authenticate();
        try {
            require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
            $db = DatabaseHelper::getInstance();

            $totalRevenue = $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM payment_transactions WHERE status = 'SUCCESS'");
            $successful = $db->fetchOne("SELECT COUNT(*) as count FROM payment_transactions WHERE status = 'SUCCESS'");
            $pending = $db->fetchOne("SELECT COUNT(*) as count FROM payment_transactions WHERE status IN ('CREATED', 'PENDING', 'PROCESSING')");
            $failed = $db->fetchOne("SELECT COUNT(*) as count FROM payment_transactions WHERE status IN ('FAILED', 'CANCELLED', 'EXPIRED')");
            $outstandingInvoices = $db->fetchOne("SELECT COUNT(*) as count FROM tenant_invoices WHERE payment_status = 'UNPAID'");
            $activeSubscriptions = $db->fetchOne("SELECT COUNT(*) as count FROM tenant_subscriptions WHERE status = 'ACTIVE'");
            $expiredSubscriptions = $db->fetchOne("SELECT COUNT(*) as count FROM tenant_subscriptions WHERE status = 'EXPIRED'");
            $refunds = $db->fetchOne("SELECT COUNT(*) as count FROM payment_refunds WHERE status = 'COMPLETED'");

            echo json_encode([
                'success' => true,
                'data' => [
                    'total_revenue' => (float)($totalRevenue['total'] ?? 0),
                    'successful' => (int)($successful['count'] ?? 0),
                    'pending' => (int)($pending['count'] ?? 0),
                    'failed' => (int)($failed['count'] ?? 0),
                    'outstanding_invoices' => (int)($outstandingInvoices['count'] ?? 0),
                    'active_subscriptions' => (int)($activeSubscriptions['count'] ?? 0),
                    'expired_subscriptions' => (int)($expiredSubscriptions['count'] ?? 0),
                    'refunds' => (int)($refunds['count'] ?? 0)
                ]
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        break;

    // ============================================================
    // PAYMENTS - TRANSACTIONS LIST
    // ============================================================
    case 'payments/transactions':
        $payload = authenticate();
        try {
            require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
            $db = DatabaseHelper::getInstance();

            $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 1;
            $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 20;
            $offset = ($page - 1) * $limit;

            $sql = "SELECT t.*, p.provider_name, tn.tenant_name
                FROM payment_transactions t
                LEFT JOIN payment_providers p ON t.provider_id = p.id
                LEFT JOIN tenants tn ON t.tenant_id = tn.id
                WHERE t.deleted_at IS NULL";
            $countSql = "SELECT COUNT(*) as total FROM payment_transactions WHERE deleted_at IS NULL";
            $params = [];
            $countParams = [];

            if (isset($queryParams['search']) && !empty($queryParams['search'])) {
                $search = '%' . $queryParams['search'] . '%';
                $sql .= " AND (t.internal_reference LIKE ? OR t.provider_transaction_id LIKE ? OR tn.tenant_name LIKE ?)";
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
                $countSql .= " AND (internal_reference LIKE ? OR provider_transaction_id LIKE ?)";
                $countParams[] = $search;
                $countParams[] = $search;
            }

            if (isset($queryParams['status']) && !empty($queryParams['status'])) {
                $sql .= " AND t.status = ?";
                $params[] = $queryParams['status'];
                $countSql .= " AND status = ?";
                $countParams[] = $queryParams['status'];
            }

            if (isset($queryParams['provider']) && !empty($queryParams['provider'])) {
                $sql .= " AND p.provider_code = ?";
                $params[] = $queryParams['provider'];
                $countSql .= " AND provider_id IN (SELECT id FROM payment_providers WHERE provider_code = ?)";
                $countParams[] = $queryParams['provider'];
            }

            if (isset($queryParams['tenant_id']) && !empty($queryParams['tenant_id'])) {
                $sql .= " AND t.tenant_id = ?";
                $params[] = $queryParams['tenant_id'];
                $countSql .= " AND tenant_id = ?";
                $countParams[] = $queryParams['tenant_id'];
            }

            $sql .= " ORDER BY t.created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $countResult = $db->fetchOne($countSql, $countParams);
            $total = (int)($countResult['total'] ?? 0);

            $transactions = $db->fetchAll($sql, $params);

            echo json_encode([
                'success' => true,
                'data' => [
                    'transactions' => $transactions,
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $limit,
                    'total_pages' => max(1, ceil($total / $limit))
                ]
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        break;

    // ============================================================
    // PAYMENTS - VERIFY TRANSACTION
    // ============================================================
    case 'payments/verify':
        $payload = authenticate();
        try {
            require_once $projectRoot . 'app/services/PaymentService.php';
            $paymentService = new PaymentService();

            $transactionId = isset($queryParams['id']) ? (int)$queryParams['id'] : null;
            if (!$transactionId) {
                echo json_encode(['success' => false, 'message' => 'Transaction ID required']);
                exit;
            }

            $result = $paymentService->verifyPayment($transactionId);
            echo json_encode($result);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        break;

    // ============================================================
    // PAYMENTS - WEBHOOK
    // ============================================================
    case 'payments/webhooks':
        // This endpoint receives webhook calls from payment providers
        $providerCode = $queryParams['provider'] ?? '';
        if (empty($providerCode)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Provider code required']);
            exit;
        }

        try {
            require_once $projectRoot . 'app/services/PaymentService.php';
            $paymentService = new PaymentService();

            $payload = getRequestData();
            $headers = getallheaders();

            $result = $paymentService->processWebhook($providerCode, $payload, $headers);

            if ($result['success']) {
                http_response_code(200);
                echo json_encode(['status' => 'success']);
            } else {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => $result['message']]);
            }
            exit;
        } catch (Exception $e) {
            error_log('Webhook error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
        break;

    // ============================================================
    // DEFAULT - 404
    // ============================================================
    default:
        jsonResponse([
            'success' => false,
            'message' => 'Endpoint not found: ' . $endpoint,
            'path' => $path,
            'timestamp' => date('Y-m-d H:i:s')
        ], 404);
        break;
}
