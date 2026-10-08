<?php

/**
 * Platform API Entry Point
 * All API endpoints for the platform with Multi-Tenant Isolation
 *
 * @package EduTrack
 * @subpackage API\Platform
 * @version 2.4
 *
 * v2.1 change (2026-09-30):
 *   Added seven blocks to the settings case for the seven tab
 *   actions that previously had no block and returned the
 *   fallback "Settings endpoint not found": general, security,
 *   currency, integrations, email, advanced, domains.
 *
 * v2.2 change (2026-09-30):
 *   Added a TenantContext::restoreFromSession() call after the
 *   require block and before the router.
 *
 * v2.3 change (2026-10-02):
 *   Added an email_test block to the settings case. The Email
 *   tab's Test Connection button calls ?endpoint=settings/email/test,
 *   which the router does not dispatch. The new block dispatches
 *   action=email_test to SettingsController::testEmailConnection().
 *
 *   Nothing else changed.
 *
 * v2.4 change (2026-10-05) [ITEMS 1-4]:
 *   Added two blocks to the settings case:
 *     action === 'clear_cache'       -> SettingsController::clearCache()
 *     action === 'optimize_database' -> SettingsController::optimizeDatabase()
 *   Both are POST-only. Both are side-effecting. Both are called
 *   from public/platform/tenant/settings/advanced.php (File 3 of
 *   the settings-surface milestone).
 *
 *   The Settings endpoint's fallback "available_actions" list was
 *   extended with 'clear_cache' and 'optimize_database'. Every
 *   other block, case, and helper in this file is byte-identical
 *   to v2.3.
 *
 * RECONSTRUCTION NOTE (2026-10-03):
 *   This file was pasted from disk during the EduTrack55 session and
 *   the paste was cut inside the users case, at the update action's
 *   role-resolution loop. The remainder of the users case and every
 *   case from tenants through default, plus the outer catch, are
 *   reproduced from the EduTrack53 session log, in which they were
 *   read in full. Per the version history above, none of those cases
 *   was edited in v2.1, v2.2, or v2.3. The settings case here
 *   contains the v2.1 seven blocks, the v2.2 change (which was
 *   outside the case), and the v2.3 email_test block. Spot-check the
 *   cases from tenants onward against the on-disk file. The docblock
 *   and the email_test block are the only intentional differences
 *   from the file on disk at the start of the v2.3 round.
 */

// =============================================
// ERROR HANDLING - MUST BE FIRST
// =============================================

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

function apiErrorHandler($errno, $errstr, $errfile, $errline)
{
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $errstr,
        'code' => $errno
    ]);
    exit;
}

function apiExceptionHandler($exception)
{
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Exception: ' . $exception->getMessage()
    ]);
    exit;
}

set_error_handler('apiErrorHandler');
set_exception_handler('apiExceptionHandler');

// =============================================
// SESSION MANAGEMENT
// =============================================

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// =============================================
// HEADERS
// =============================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Tenant-ID, X-School-ID, X-Campus-ID');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}

// =============================================
// PROJECT ROOT
// =============================================

$projectRoot = dirname(__DIR__, 3) . '/';

// =============================================
// LOAD REQUIRED FILES
// =============================================

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/helpers/ResponseHelper.php';
require_once $projectRoot . 'app/middleware/TenantContextMiddleware.php';
require_once $projectRoot . 'app/controllers/Platform/AuthController.php';
require_once $projectRoot . 'app/controllers/Platform/SettingsController.php';
require_once $projectRoot . 'app/controllers/Platform/AuditLogController.php';
require_once $projectRoot . 'app/controllers/Platform/DomainController.php';
require_once $projectRoot . 'app/controllers/Platform/SubscriptionController.php';
require_once $projectRoot . 'app/services/Platform/SchoolSettingsService.php';
require_once $projectRoot . 'app/services/Platform/SettingsService.php';
require_once $projectRoot . 'app/services/Settings/PaymentProviderService.php';
require_once $projectRoot . 'app/services/Tenant/TenantContext.php';
require_once $projectRoot . 'app/services/Authorization/AuthorizationService.php';

// =============================================
// RESTORE TENANT CONTEXT FOR THIS REQUEST
// =============================================

try {
    TenantContext::getInstance()->restoreFromSession();
} catch (Throwable $e) {
    error_log('API: TenantContext::restoreFromSession failed - ' . $e->getMessage());
}

// =============================================
// HELPER FUNCTIONS
// =============================================

function getRequestData()
{
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?: [];
}

function jsonResponse($data, $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

function getAuthToken()
{
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        return $matches[1];
    }
    return $_SESSION['auth_token'] ?? '';
}

// =============================================
// GET REQUEST PATH
// =============================================

$method = $_SERVER['REQUEST_METHOD'];
$endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : '';
$action = isset($_GET['action']) ? $_GET['action'] : '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$pathParts = isset($_GET['path']) ? explode('/', trim($_GET['path'], '/')) : [];
$subAction = isset($pathParts[2]) ? $pathParts[2] : '';
$subSubAction = isset($pathParts[3]) ? $pathParts[3] : '';
$paramId = isset($pathParts[4]) ? $pathParts[4] : '';

// =============================================
// DATABASE HELPER
// =============================================

function getDBConnection()
{
    try {
        return DatabaseHelper::getInstance();
    } catch (Exception $e) {
        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $dbname = $_ENV['DB_NAME'] ?? 'student_performance_system';
        $username = $_ENV['DB_USER'] ?? 'root';
        $password = $_ENV['DB_PASSWORD'] ?? '';
        return new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
}

function encryptData($data)
{
    return base64_encode($data);
}

function decryptData($data)
{
    return base64_decode($data);
}

function logAudit($tenantId, $action, $resource, $resourceId, $details = null)
{
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("INSERT INTO payment_audit_logs (tenant_id, user_id, action_type, resource_type, resource_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $tenantId,
            $_SESSION['user_id'] ?? null,
            $action,
            $resource,
            $resourceId,
            $details ? json_encode($details) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        // Silently fail for audit logging
    }
}

function simulateConnectionTest($type, $apiKey, $apiSecret)
{
    $types = ['mobile_money', 'card', 'bank_transfer', 'multi_currency', 'custom'];
    if (in_array($type, $types) && !empty($apiKey) && !empty($apiSecret)) {
        return [
            'success' => true,
            'message' => 'Connection test successful for ' . $type,
            'provider_type' => $type,
            'response_time' => rand(100, 300) . 'ms'
        ];
    }
    return [
        'success' => false,
        'message' => 'Invalid credentials or provider type'
    ];
}

// =============================================
// AUTHENTICATION FUNCTION
// =============================================

function authenticateRequest($endpointName = 'API')
{
    $isAuthenticated = false;
    $userId = null;

    // 1. Check session (for browser requests)
    if (isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        $isAuthenticated = true;
        $userId = $_SESSION['user_id'];
        error_log($endpointName . ' - Authenticated via session: user_id=' . $userId);
        return ['authenticated' => true, 'user_id' => $userId];
    }

    // 2. Check Authorization header (for API tokens)
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = $matches[1];
        error_log($endpointName . ' - Token received: ' . substr($token, 0, 30) . '...');

        try {
            $db = getDBConnection();
            $result = $db->fetchOne(
                "SELECT user_id FROM api_tokens 
                 WHERE token = ? AND expires_at > NOW() AND is_revoked = 0",
                [$token]
            );
            if ($result) {
                $isAuthenticated = true;
                $userId = $result['user_id'];
                $_SESSION['user_id'] = $userId;
                $_SESSION['logged_in'] = true;
                error_log($endpointName . ' - Authenticated via token: user_id=' . $userId);

                // Update last_used_at
                $db->execute(
                    "UPDATE api_tokens SET last_used_at = NOW() WHERE token = ?",
                    [$token]
                );
                return ['authenticated' => true, 'user_id' => $userId];
            } else {
                error_log($endpointName . ' - Token not found or expired');
            }
        } catch (Exception $e) {
            error_log($endpointName . ' - Token validation error: ' . $e->getMessage());
        }
    }

    error_log($endpointName . ' - Authentication failed');
    return ['authenticated' => false, 'user_id' => null];
}

// =============================================
// ROUTER - MAIN SWITCH
// =============================================

try {
    switch ($endpoint) {
        // ============================================
        // AUTH ENDPOINTS
        // ============================================
        case 'auth':
            $auth = new AuthController();

            if ($action === 'login') {
                if ($method === 'POST') {
                    $auth->login();
                } else {
                    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                }
            } elseif ($action === 'logout') {
                if ($method === 'POST') {
                    $auth->logout();
                    jsonResponse(['success' => true, 'message' => 'Logged out successfully']);
                } else {
                    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                }
            } elseif ($action === 'refresh') {
                if ($method === 'POST') {
                    $auth->refreshToken();
                } else {
                    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                }
            } elseif ($action === 'me') {
                if ($method === 'GET') {
                    $auth->getCurrentUser();
                } else {
                    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                }
            } elseif ($action === 'change-password') {
                if ($method === 'POST') {
                    $auth->changePassword();
                } else {
                    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                }
            } elseif ($action === 'forgot-password') {
                if ($method === 'POST') {
                    $auth->forgotPassword();
                } else {
                    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                }
            } elseif ($action === 'reset-password') {
                if ($method === 'POST') {
                    $auth->resetPassword();
                } else {
                    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                }
            } else {
                jsonResponse([
                    'success' => false,
                    'message' => 'Invalid auth action',
                    'available_actions' => ['login', 'logout', 'refresh', 'me', 'change-password', 'forgot-password', 'reset-password']
                ], 404);
            }
            break;

        // ============================================
        // ROLES ENDPOINTS
        // ============================================
        case 'roles':
            if ($method === 'GET') {
                try {
                    $db = getDBConnection();

                    $columns = [];
                    try {
                        $colResult = $db->fetchAll("SHOW COLUMNS FROM platform_roles");
                        foreach ($colResult as $col) {
                            $columns[] = $col['Field'];
                        }
                    } catch (Exception $e) {
                        error_log('Error getting columns: ' . $e->getMessage());
                    }

                    $selectFields = 'id';

                    if (in_array('role_code', $columns)) {
                        $selectFields .= ', role_code as code';
                    } elseif (in_array('code', $columns)) {
                        $selectFields .= ', code';
                    } else {
                        $selectFields .= ', id as code';
                    }

                    if (in_array('role_name', $columns)) {
                        $selectFields .= ', role_name as name';
                    } elseif (in_array('name', $columns)) {
                        $selectFields .= ', name';
                    } elseif (in_array('role', $columns)) {
                        $selectFields .= ', role as name';
                    } else {
                        $selectFields .= ', id as name';
                    }

                    if (in_array('description', $columns)) {
                        $selectFields .= ', description';
                    }

                    if (in_array('level', $columns)) {
                        $selectFields .= ', level';
                    }

                    $whereClause = '';
                    if (in_array('deleted_at', $columns)) {
                        $whereClause = "WHERE deleted_at IS NULL";
                    }

                    $orderBy = "ORDER BY id ASC";
                    if (in_array('level', $columns)) {
                        $orderBy = "ORDER BY level DESC";
                    }

                    $sql = "SELECT $selectFields FROM platform_roles $whereClause $orderBy";
                    $roles = $db->fetchAll($sql);

                    foreach ($roles as &$role) {
                        if (!isset($role['code']) || empty($role['code'])) {
                            $role['code'] = (string)$role['id'];
                        }
                        if (!isset($role['name']) || empty($role['name'])) {
                            $role['name'] = $role['code'];
                        }
                    }

                    jsonResponse(['success' => true, 'data' => $roles]);
                } catch (Exception $e) {
                    error_log('Roles API error: ' . $e->getMessage());
                    jsonResponse(['success' => false, 'message' => 'Error fetching roles: ' . $e->getMessage()], 500);
                }
            } else {
                jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
            }
            break;

        // ============================================
        // USERS ENDPOINTS
        // ============================================
        case 'users':
            try {
                $db = getDBConnection();

                if ($action === 'stats') {
                    if ($method === 'GET') {
                        try {
                            $total = $db->fetchOne("SELECT COUNT(*) as count FROM platform_users WHERE deleted_at IS NULL");
                            $active = $db->fetchOne("SELECT COUNT(*) as count FROM platform_users WHERE is_active = 1 AND deleted_at IS NULL");
                            $inactive = $db->fetchOne("SELECT COUNT(*) as count FROM platform_users WHERE is_active = 0 AND deleted_at IS NULL");

                            jsonResponse([
                                'success' => true,
                                'data' => [
                                    'total' => (int)($total['count'] ?? 0),
                                    'active' => (int)($active['count'] ?? 0),
                                    'inactive' => (int)($inactive['count'] ?? 0)
                                ]
                            ]);
                        } catch (Exception $e) {
                            error_log('Stats error: ' . $e->getMessage());
                            jsonResponse(['success' => false, 'message' => 'Error fetching stats: ' . $e->getMessage()], 500);
                        }
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                    }
                    break;
                }

                if ($action === 'check') {
                    if ($method === 'POST') {
                        $data = getRequestData();
                        $email = $data['email'] ?? '';
                        $username = $data['username'] ?? '';
                        $excludedId = isset($data['exclude_id']) ? (int)$data['exclude_id'] : 0;

                        $emailExists = false;
                        $usernameExists = false;

                        if (!empty($email)) {
                            $sql = "SELECT id FROM platform_users WHERE email = ? AND deleted_at IS NULL";
                            $params = [$email];
                            if ($excludedId > 0) {
                                $sql .= " AND id != ?";
                                $params[] = $excludedId;
                            }
                            $result = $db->fetchOne($sql, $params);
                            $emailExists = (bool)$result;
                        }

                        if (!empty($username)) {
                            $sql = "SELECT id FROM platform_users WHERE username = ? AND deleted_at IS NULL";
                            $params = [$username];
                            if ($excludedId > 0) {
                                $sql .= " AND id != ?";
                                $params[] = $excludedId;
                            }
                            $result = $db->fetchOne($sql, $params);
                            $usernameExists = (bool)$result;
                        }

                        jsonResponse([
                            'success' => true,
                            'data' => [
                                'email_exists' => $emailExists,
                                'username_exists' => $usernameExists
                            ]
                        ]);
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                    }
                    break;
                }

                if ($action === 'autocomplete') {
                    if ($method === 'GET') {
                        try {
                            $searchTerm = isset($_GET['search']) ? '%' . trim($_GET['search']) . '%' : '%';
                            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

                            $sql = "SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.tenant_id
                                    FROM platform_users u
                                    WHERE u.deleted_at IS NULL
                                    AND (u.username LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)
                                    ORDER BY u.username ASC
                                    LIMIT ?";
                            $users = $db->fetchAll($sql, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]);

                            foreach ($users as &$user) {
                                $roles = $db->fetchAll("SELECT pr.role_code FROM platform_user_roles pur JOIN platform_roles pr ON pur.role_id = pr.id WHERE pur.user_id = ?", [$user['id']]);
                                $user['roles'] = array_column($roles, 'role_code');
                            }

                            jsonResponse(['success' => true, 'data' => $users]);
                        } catch (Exception $e) {
                            error_log('Autocomplete error: ' . $e->getMessage());
                            jsonResponse(['success' => false, 'message' => 'Error performing search: ' . $e->getMessage()], 500);
                        }
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed for autocomplete'], 405);
                    }
                    break;
                }

                if ($action === 'list' || $action === '') {
                    if ($method === 'GET') {
                        try {
                            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
                            $offset = ($page - 1) * $limit;

                            $where = ["u.deleted_at IS NULL"];
                            $params = [];

                            if (isset($_GET['search']) && !empty($_GET['search'])) {
                                $search = '%' . trim($_GET['search']) . '%';
                                $where[] = "(u.username LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
                                $params[] = $search;
                                $params[] = $search;
                                $params[] = $search;
                                $params[] = $search;
                            }

                            if (isset($_GET['status']) && !empty($_GET['status'])) {
                                $where[] = "u.is_active = ?";
                                $params[] = ($_GET['status'] === 'active') ? 1 : 0;
                            }

                            if (isset($_GET['tenant_id']) && !empty($_GET['tenant_id'])) {
                                $where[] = "u.tenant_id = ?";
                                $params[] = (int)$_GET['tenant_id'];
                            }

                            if (isset($_GET['role']) && !empty($_GET['role'])) {
                                $where[] = "EXISTS (SELECT 1 FROM platform_user_roles pur JOIN platform_roles pr ON pur.role_id = pr.id WHERE pur.user_id = u.id AND pr.role_code = ?)";
                                $params[] = $_GET['role'];
                            }

                            $whereClause = implode(' AND ', $where);

                            $countSql = "SELECT COUNT(*) as total FROM platform_users u WHERE $whereClause";
                            $countResult = $db->fetchOne($countSql, $params);
                            $total = (int)($countResult['total'] ?? 0);

                            $sql = "SELECT u.*, t.legal_name as tenant_name
                                    FROM platform_users u
                                    LEFT JOIN tenants t ON u.tenant_id = t.id
                                    WHERE $whereClause
                                    ORDER BY u.created_at DESC
                                    LIMIT ? OFFSET ?";
                            $params[] = $limit;
                            $params[] = $offset;

                            $users = $db->fetchAll($sql, $params);

                            foreach ($users as &$user) {
                                $roles = $db->fetchAll("SELECT pr.role_code FROM platform_user_roles pur JOIN platform_roles pr ON pur.role_id = pr.id WHERE pur.user_id = ?", [$user['id']]);
                                $user['roles'] = array_column($roles, 'role_code');
                                unset($user['password_hash']);
                            }

                            jsonResponse([
                                'success' => true,
                                'data' => [
                                    'users' => $users,
                                    'pagination' => [
                                        'page' => $page,
                                        'limit' => $limit,
                                        'total' => $total,
                                        'pages' => ceil($total / $limit)
                                    ]
                                ]
                            ]);
                        } catch (Exception $e) {
                            error_log('List users error: ' . $e->getMessage());
                            jsonResponse(['success' => false, 'message' => 'Error fetching users: ' . $e->getMessage()], 500);
                        }
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                    }
                    break;
                }

                if ($action === 'get' && $id > 0) {
                    if ($method === 'GET') {
                        try {
                            $sql = "SELECT u.*, t.legal_name as tenant_name
                                    FROM platform_users u
                                    LEFT JOIN tenants t ON u.tenant_id = t.id
                                    WHERE u.id = ? AND u.deleted_at IS NULL";
                            $user = $db->fetchOne($sql, [$id]);

                            if ($user) {
                                $roles = $db->fetchAll("SELECT pr.role_code FROM platform_user_roles pur JOIN platform_roles pr ON pur.role_id = pr.id WHERE pur.user_id = ?", [$id]);
                                $user['roles'] = array_column($roles, 'role_code');
                                unset($user['password_hash']);
                                jsonResponse(['success' => true, 'data' => $user]);
                            } else {
                                jsonResponse(['success' => false, 'message' => 'User not found'], 404);
                            }
                        } catch (Exception $e) {
                            error_log('Get user error: ' . $e->getMessage());
                            jsonResponse(['success' => false, 'message' => 'Error fetching user: ' . $e->getMessage()], 500);
                        }
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                    }
                    break;
                }

                if ($action === 'update_status' && $id > 0) {
                    if ($method === 'PUT' || $method === 'POST') {
                        $data = getRequestData();
                        $isActive = isset($data['is_active']) ? (int)$data['is_active'] : null;

                        if ($isActive === null) {
                            jsonResponse(['success' => false, 'message' => 'is_active is required'], 400);
                            break;
                        }

                        try {
                            $sql = "UPDATE platform_users SET is_active = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
                            $db->execute($sql, [$isActive, $id]);
                            jsonResponse(['success' => true, 'message' => 'User status updated successfully']);
                        } catch (Exception $e) {
                            error_log('Update status error: ' . $e->getMessage());
                            jsonResponse(['success' => false, 'message' => 'Error updating status: ' . $e->getMessage()], 500);
                        }
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                    }
                    break;
                }

                if ($action === 'delete' && $id > 0) {
                    if ($method === 'DELETE' || $method === 'POST') {
                        try {
                            $sql = "UPDATE platform_users SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
                            $db->execute($sql, [$id]);
                            jsonResponse(['success' => true, 'message' => 'User deleted successfully']);
                        } catch (Exception $e) {
                            error_log('Delete user error: ' . $e->getMessage());
                            jsonResponse(['success' => false, 'message' => 'Error deleting user: ' . $e->getMessage()], 500);
                        }
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                    }
                    break;
                }

                if ($action === 'create' || $action === 'register') {
                    if ($method === 'POST') {
                        try {
                            $data = getRequestData();

                            $required = ['first_name', 'last_name', 'username', 'email', 'password', 'role'];
                            $missing = [];
                            foreach ($required as $field) {
                                if (empty($data[$field])) {
                                    $missing[] = ucfirst(str_replace('_', ' ', $field));
                                }
                            }

                            if (!empty($missing)) {
                                jsonResponse(['success' => false, 'message' => 'Required fields missing: ' . implode(', ', $missing)], 400);
                                break;
                            }

                            $existing = $db->fetchOne("SELECT id FROM platform_users WHERE username = ? AND deleted_at IS NULL", [$data['username']]);
                            if ($existing) {
                                jsonResponse(['success' => false, 'message' => 'Username already exists'], 400);
                                break;
                            }

                            $existing = $db->fetchOne("SELECT id FROM platform_users WHERE email = ? AND deleted_at IS NULL", [$data['email']]);
                            if ($existing) {
                                jsonResponse(['success' => false, 'message' => 'Email already exists'], 400);
                                break;
                            }

                            if (strlen($data['password']) < 8) {
                                jsonResponse(['success' => false, 'message' => 'Password must be at least 8 characters'], 400);
                                break;
                            }

                            $role = null;
                            $roleValue = $data['role'];
                            $possibleColumns = ['role_code', 'code', 'name', 'role_name', 'role'];

                            foreach ($possibleColumns as $col) {
                                try {
                                    $testSql = "SELECT id FROM platform_roles WHERE $col = ? LIMIT 1";
                                    $testResult = $db->fetchOne($testSql, [$roleValue]);
                                    if ($testResult) {
                                        $role = $testResult;
                                        break;
                                    }
                                } catch (Exception $e) {
                                    continue;
                                }
                            }

                            if (!$role) {
                                try {
                                    $fallbackSql = "SELECT id FROM platform_roles WHERE role_code = ? OR code = ? OR name = ? OR role_name = ? OR role = ? OR description LIKE ? LIMIT 1";
                                    $fallbackParams = [$roleValue, $roleValue, $roleValue, $roleValue, $roleValue, '%' . $roleValue . '%'];
                                    $role = $db->fetchOne($fallbackSql, $fallbackParams);
                                } catch (Exception $e) {
                                }
                            }

                            if (!$role) {
                                try {
                                    $defaultRole = $db->fetchOne("SELECT id FROM platform_roles LIMIT 1");
                                    if ($defaultRole) {
                                        $role = $defaultRole;
                                    } else {
                                        jsonResponse(['success' => false, 'message' => 'No roles found in the system. Please create a role first.'], 400);
                                        break;
                                    }
                                } catch (Exception $e) {
                                    jsonResponse(['success' => false, 'message' => 'Error finding role: ' . $e->getMessage()], 500);
                                    break;
                                }
                            }

                            if (!$role) {
                                jsonResponse(['success' => false, 'message' => 'Role not found. Please create the role first.'], 400);
                                break;
                            }

                            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
                            $uuid = bin2hex(random_bytes(16));

                            $userSql = "INSERT INTO platform_users (uuid, username, email, password_hash, first_name, last_name, phone, tenant_id, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
                            $userParams = [
                                $uuid,
                                $data['username'],
                                $data['email'],
                                $hashedPassword,
                                $data['first_name'],
                                $data['last_name'],
                                $data['phone'] ?? '',
                                !empty($data['tenant_id']) ? (int)$data['tenant_id'] : null
                            ];

                            $result = $db->execute($userSql, $userParams);

                            if ($result) {
                                $userId = $db->lastInsertId();
                                if ($userId) {
                                    $roleSql = "INSERT INTO platform_user_roles (user_id, role_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())";
                                    $roleParams = [$userId, $role['id'], $_SESSION['user_id'] ?? null];
                                    $db->execute($roleSql, $roleParams);

                                    jsonResponse(['success' => true, 'message' => 'User created successfully', 'data' => ['id' => $userId]]);
                                } else {
                                    jsonResponse(['success' => false, 'message' => 'Failed to get user ID after creation'], 500);
                                }
                            } else {
                                jsonResponse(['success' => false, 'message' => 'Failed to create user'], 500);
                            }
                        } catch (Exception $e) {
                            error_log('Create user error: ' . $e->getMessage());
                            jsonResponse(['success' => false, 'message' => 'Error creating user: ' . $e->getMessage()], 500);
                        }
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST for creating users.'], 405);
                    }
                    break;
                }

                if ($action === 'update' && $id > 0) {
                    if ($method === 'PUT' || $method === 'POST') {
                        try {
                            $data = getRequestData();
                            $user = $db->fetchOne("SELECT id FROM platform_users WHERE id = ? AND deleted_at IS NULL", [$id]);
                            if (!$user) {
                                jsonResponse(['success' => false, 'message' => 'User not found'], 404);
                                break;
                            }

                            $updateFields = [];
                            $updateParams = [];

                            if (isset($data['first_name'])) {
                                $updateFields[] = "first_name = ?";
                                $updateParams[] = $data['first_name'];
                            }

                            if (isset($data['last_name'])) {
                                $updateFields[] = "last_name = ?";
                                $updateParams[] = $data['last_name'];
                            }

                            if (isset($data['email'])) {
                                $existing = $db->fetchOne("SELECT id FROM platform_users WHERE email = ? AND id != ? AND deleted_at IS NULL", [$data['email'], $id]);
                                if ($existing) {
                                    jsonResponse(['success' => false, 'message' => 'Email already exists'], 400);
                                    break;
                                }
                                $updateFields[] = "email = ?";
                                $updateParams[] = $data['email'];
                            }

                            if (isset($data['phone'])) {
                                $updateFields[] = "phone = ?";
                                $updateParams[] = $data['phone'];
                            }

                            if (isset($data['is_active'])) {
                                $updateFields[] = "is_active = ?";
                                $updateParams[] = (int)$data['is_active'];
                            }

                            if (isset($data['tenant_id'])) {
                                $updateFields[] = "tenant_id = ?";
                                $updateParams[] = !empty($data['tenant_id']) ? (int)$data['tenant_id'] : null;
                            }

                            if (isset($data['password']) && !empty($data['password'])) {
                                $updateFields[] = "password_hash = ?";
                                $updateParams[] = password_hash($data['password'], PASSWORD_DEFAULT);
                            }

                            $updateFields[] = "updated_at = NOW()";

                            if (empty($updateFields)) {
                                jsonResponse(['success' => false, 'message' => 'No fields to update'], 400);
                                break;
                            }

                            $updateSql = "UPDATE platform_users SET " . implode(", ", $updateFields) . " WHERE id = ?";
                            $updateParams[] = $id;
                            $db->execute($updateSql, $updateParams);

                            if (isset($data['role']) && !empty($data['role'])) {
                                $role = null;
                                $roleValue = $data['role'];
                                $possibleColumns = ['role_code', 'code', 'name', 'role_name', 'role'];

                                foreach ($possibleColumns as $col) {
                                    try {
                                        $testSql = "SELECT id FROM platform_roles WHERE $col = ? LIMIT 1";
                                        $testResult = $db->fetchOne($testSql, [$roleValue]);
                                        if ($testResult) {
                                            $role = $testResult;
                                            break;
                                        }
                                    } catch (Exception $e) {
                                        continue;
                                    }
                                }

                                if (!$role) {
                                    try {
                                        $fallbackSql = "SELECT id FROM platform_roles WHERE role_code = ? OR code = ? OR name = ? OR role_name = ? OR role = ? OR description LIKE ? LIMIT 1";
                                        $fallbackParams = [$roleValue, $roleValue, $roleValue, $roleValue, $roleValue, '%' . $roleValue . '%'];
                                        $role = $db->fetchOne($fallbackSql, $fallbackParams);
                                    } catch (Exception $e) {
                                    }
                                }

                                if ($role) {
                                    $db->execute("DELETE FROM platform_user_roles WHERE user_id = ?", [$id]);
                                    $db->execute("INSERT INTO platform_user_roles (user_id, role_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())", [
                                        $id,
                                        $role['id'],
                                        $_SESSION['user_id'] ?? null
                                    ]);
                                }
                            }

                            jsonResponse(['success' => true, 'message' => 'User updated successfully']);
                        } catch (Exception $e) {
                            error_log('Update user error: ' . $e->getMessage());
                            jsonResponse(['success' => false, 'message' => 'Error updating user: ' . $e->getMessage()], 500);
                        }
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                    }
                    break;
                }

                jsonResponse([
                    'success' => false,
                    'message' => 'User endpoint not found: ' . $action,
                    'available_actions' => [
                        'list',
                        'get',
                        'create',
                        'register',
                        'update',
                        'delete',
                        'stats',
                        'check',
                        'autocomplete',
                        'update_status'
                    ]
                ], 404);
            } catch (Exception $e) {
                error_log('Users API error: ' . $e->getMessage());
                jsonResponse(['success' => false, 'message' => 'Users API error: ' . $e->getMessage()], 500);
            }
            break;

        // ============================================
        // TENANTS ENDPOINTS
        // ============================================
        case 'tenants':
            try {
                require_once $projectRoot . 'app/controllers/Platform/TenantController.php';
                $controller = new TenantController();
                $action = isset($_GET['action']) ? $_GET['action'] : '';
                $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

                error_log("Tenants API: action=$action, id=$id, method=$method");

                switch ($action) {
                    case 'list':
                        if ($method === 'GET') {
                            $controller->getTenants();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'get':
                        if ($method === 'GET' && $id > 0) {
                            $controller->getTenant($id);
                        } elseif ($method === 'GET') {
                            jsonResponse(['success' => false, 'message' => 'Tenant ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'create':
                    case 'register':
                        if ($method === 'POST') {
                            $controller->createTenant();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'update':
                        if (($method === 'PUT' || $method === 'POST') && $id > 0) {
                            $controller->updateTenant($id);
                        } elseif ($method === 'PUT' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Tenant ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'check_email':
                        if ($method === 'POST') {
                            $controller->checkEmail();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'update_status':
                        if (($method === 'PUT' || $method === 'POST') && $id > 0) {
                            $controller->updateTenantStatus($id);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);
                        }
                        break;
                    case 'delete':
                        if (($method === 'DELETE' || $method === 'POST') && $id > 0) {
                            $controller->deleteTenant($id);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);
                        }
                        break;
                    case 'autocomplete':
                        if ($method === 'GET') {
                            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
                            if (strlen($search) < 2) {
                                jsonResponse(['success' => true, 'data' => []]);
                                break;
                            }

                            require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
                            $db = DatabaseHelper::getInstance();

                            $tenants = $db->fetchAll(
                                "SELECT id, tenant_name, tenant_code, status 
                                 FROM tenants 
                                 WHERE deleted_at IS NULL 
                                 AND (tenant_name LIKE ? OR tenant_code LIKE ?)
                                 AND status = 'active'
                                 ORDER BY tenant_name ASC 
                                 LIMIT 10",
                                ['%' . $search . '%', '%' . $search . '%']
                            );

                            jsonResponse(['success' => true, 'data' => $tenants]);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    default:
                        jsonResponse([
                            'success' => false,
                            'message' => 'Tenant endpoint not found: ' . $action,
                            'available_actions' => ['list', 'get', 'update', 'register', 'check_email', 'update_status', 'delete', 'autocomplete']
                        ], 404);
                }
                break;
            } catch (Exception $e) {
                error_log('Tenants API error: ' . $e->getMessage());
                jsonResponse(['success' => false, 'message' => 'Tenants API error: ' . $e->getMessage()], 500);
            }
            break;

        // ============================================
        // SCHOOLS ENDPOINTS
        // ============================================
        case 'schools':
            try {
                require_once $projectRoot . 'app/services/Platform/SchoolService.php';

                if (!class_exists('SchoolService')) {
                    jsonResponse(['success' => false, 'message' => 'SchoolService not found'], 500);
                    break;
                }

                $schoolService = new SchoolService();
                $action = isset($_GET['action']) ? $_GET['action'] : '';
                $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

                error_log("Schools API: action=$action, id=$id, method=$method");

                if ($action === 'autocomplete') {
                    if ($method === 'GET') {
                        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
                        if (strlen($search) < 2) {
                            jsonResponse(['success' => true, 'data' => []]);
                            break;
                        }

                        require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
                        $db = DatabaseHelper::getInstance();

                        $schools = $db->fetchAll(
                            "SELECT s.id, s.school_name, s.school_code, s.status, t.tenant_name 
                             FROM schools s
                             LEFT JOIN tenants t ON s.tenant_id = t.id
                             WHERE s.deleted_at IS NULL 
                             AND (s.school_name LIKE ? OR s.school_code LIKE ?)
                             ORDER BY s.school_name ASC 
                             LIMIT 10",
                            ['%' . $search . '%', '%' . $search . '%']
                        );

                        jsonResponse(['success' => true, 'data' => $schools]);
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed for autocomplete'], 405);
                    }
                    break;
                }

                if ($action === 'list' || $action === '') {
                    if ($method === 'GET') {
                        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
                        $filters = [
                            'search' => isset($_GET['search']) ? trim($_GET['search']) : '',
                            'status' => isset($_GET['status']) ? trim($_GET['status']) : '',
                            'school_type' => isset($_GET['school_type']) ? trim($_GET['school_type']) : ''
                        ];

                        $result = $schoolService->getSchools($page, $limit, $filters);
                        jsonResponse(['success' => true, 'data' => $result]);
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                    }
                    break;
                }

                if ($action === 'get' && $id > 0) {
                    if ($method === 'GET') {
                        $school = $schoolService->getSchool($id);
                        if ($school) {
                            jsonResponse(['success' => true, 'data' => $school]);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'School not found'], 404);
                        }
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                    }
                    break;
                }

                if ($action === 'update_status' && $id > 0) {
                    if ($method === 'PUT' || $method === 'POST') {
                        $data = getRequestData();
                        $status = $data['status'] ?? '';

                        if (empty($status)) {
                            jsonResponse(['success' => false, 'message' => 'Status is required'], 400);
                            break;
                        }

                        $db = getDBConnection();
                        $sql = "UPDATE schools SET status = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
                        $result = $db->execute($sql, [$status, $id]);

                        if ($result) {
                            jsonResponse(['success' => true, 'message' => 'School status updated successfully']);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Failed to update school status'], 500);
                        }
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                    }
                    break;
                }

                if ($action === 'stats') {
                    if ($method === 'GET') {
                        $stats = $schoolService->getSchoolStats();
                        jsonResponse(['success' => true, 'data' => $stats]);
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                    }
                    break;
                }

                if ($action === 'types') {
                    if ($method === 'GET') {
                        $types = $schoolService->getSchoolTypes();
                        jsonResponse(['success' => true, 'data' => $types]);
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                    }
                    break;
                }

                if ($action === 'statuses') {
                    if ($method === 'GET') {
                        $statuses = $schoolService->getStatuses();
                        jsonResponse(['success' => true, 'data' => $statuses]);
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                    }
                    break;
                }

                jsonResponse([
                    'success' => false,
                    'message' => 'Schools endpoint not found: ' . $action,
                    'available_actions' => ['list', 'get', 'update_status', 'stats', 'types', 'statuses', 'autocomplete']
                ], 404);
            } catch (Exception $e) {
                error_log('Schools API error: ' . $e->getMessage());
                jsonResponse(['success' => false, 'message' => 'Schools API error: ' . $e->getMessage()], 500);
            }
            break;

        // ============================================
        // CAMPUSES ENDPOINTS
        // ============================================
        case 'campuses':
            try {
                $db = getDBConnection();
                $tenantId = $_SESSION['tenant_id'] ?? 0;
                $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

                if ($action === 'list' || $action === '') {
                    if ($method === 'GET') {
                        $sql = "SELECT c.*, s.school_name 
                                FROM campuses c
                                LEFT JOIN schools s ON c.school_id = s.id
                                WHERE (c.tenant_id = 0 OR c.tenant_id = ?) AND (c.deleted_at IS NULL OR c.deleted_at = '')";
                        $params = [$tenantId];

                        if ($schoolId > 0) {
                            $sql .= " AND c.school_id = ?";
                            $params[] = $schoolId;
                        }

                        $sql .= " ORDER BY c.campus_name ASC";

                        $campuses = $db->fetchAll($sql, $params);

                        jsonResponse([
                            'success' => true,
                            'data' => $campuses,
                            'total' => count($campuses)
                        ]);
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                    }
                    break;
                }

                if ($action === 'get' && $id > 0) {
                    if ($method === 'GET') {
                        $sql = "SELECT c.*, s.school_name 
                                FROM campuses c
                                LEFT JOIN schools s ON c.school_id = s.id
                                WHERE c.id = ? AND (c.tenant_id = 0 OR c.tenant_id = ?) AND (c.deleted_at IS NULL OR c.deleted_at = '')";
                        $campus = $db->fetchOne($sql, [$id, $tenantId]);

                        if ($campus) {
                            jsonResponse(['success' => true, 'data' => $campus]);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Campus not found'], 404);
                        }
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                    }
                    break;
                }

                jsonResponse([
                    'success' => false,
                    'message' => 'Campuses endpoint not found: ' . $action,
                    'available_actions' => ['list', 'get']
                ], 404);
            } catch (Exception $e) {
                error_log('Campuses API error: ' . $e->getMessage());
                jsonResponse(['success' => false, 'message' => 'Campuses API error: ' . $e->getMessage()], 500);
            }
            break;

        // ============================================
        // SETTINGS ENDPOINTS
        // ============================================
        case 'settings':
            try {
                require_once $projectRoot . 'app/controllers/Platform/SettingsController.php';
                require_once $projectRoot . 'app/services/Platform/SchoolSettingsService.php';
                require_once $projectRoot . 'app/services/Platform/SettingsService.php';
                require_once $projectRoot . 'app/services/Settings/PaymentProviderService.php';
                require_once $projectRoot . 'app/services/Tenant/TenantContext.php';
                require_once $projectRoot . 'app/services/Authorization/AuthorizationService.php';

                $controller = new SettingsController();
                $action = isset($_GET['action']) ? $_GET['action'] : '';
                $subAction = isset($pathParts[2]) ? $pathParts[2] : '';
                $subSubAction = isset($pathParts[3]) ? $pathParts[3] : '';
                $paramId = isset($pathParts[4]) ? $pathParts[4] : '';

                error_log("Settings API: action=$action, subAction=$subAction, subSubAction=$subSubAction, paramId=$paramId, method=$method");

                // PAYMENT SETTINGS ENDPOINTS
                if ($subAction === 'payments' && $subSubAction === 'providers' && $method === 'GET') {
                    $controller->getPaymentProviders();
                    break;
                }
                if ($subAction === 'payments' && $subSubAction === 'provider' && $method === 'POST') {
                    $controller->createPaymentProvider();
                    break;
                }
                if ($subAction === 'payments' && $subSubAction === 'provider' && !empty($paramId) && $method === 'GET') {
                    $controller->getPaymentProvider($paramId);
                    break;
                }
                if ($subAction === 'payments' && $subSubAction === 'provider' && !empty($paramId) && ($method === 'PUT' || $method === 'POST')) {
                    $controller->updatePaymentProvider($paramId);
                    break;
                }
                if ($subAction === 'payments' && $subSubAction === 'provider' && !empty($paramId) && ($method === 'DELETE' || $method === 'POST')) {
                    $controller->deletePaymentProvider($paramId);
                    break;
                }
                if ($subAction === 'payments' && $subSubAction === 'provider' && !empty($paramId) && $method === 'POST') {
                    $controller->testPaymentProvider($paramId);
                    break;
                }
                if ($subAction === 'payments' && $subSubAction === 'hubtel' && $method === 'GET') {
                    $controller->getHubtelConfig();
                    break;
                }
                if ($subAction === 'payments' && $subSubAction === 'hubtel' && $subSubAction === 'test' && $method === 'POST') {
                    $controller->testHubtelConnection();
                    break;
                }
                if ($subAction === 'payments' && $subSubAction === 'bank' && $method === 'GET') {
                    $controller->getBankConfig();
                    break;
                }
                if ($subAction === 'payments' && $subSubAction === 'bank' && $subSubAction === 'test' && $method === 'POST') {
                    $controller->testBankConnection();
                    break;
                }

                // AUDIT LOGS via settings
                if ($subAction === 'audit-logs' && $method === 'GET') {
                    require_once $projectRoot . 'app/controllers/Platform/AuditLogController.php';
                    $auditController = new AuditLogController();

                    if (isset($_GET['stats']) && $_GET['stats'] === 'true') {
                        $auditController->getStats();
                    } elseif (isset($_GET['export']) && $_GET['export'] === 'true') {
                        $auditController->exportLogs();
                    } elseif (isset($_GET['clean']) && isset($_GET['days'])) {
                        $auditController->cleanLogs((int)$_GET['days']);
                    } elseif (isset($_GET['id'])) {
                        $auditController->getLog((int)$_GET['id']);
                    } else {
                        $auditController->getLogs();
                    }
                    break;
                }

                // SCHOOL SETTINGS
                if ($action === 'get_school') {
                    if ($method === 'POST' || $method === 'GET') {
                        $data = getRequestData();
                        $schoolId = isset($data['school_id']) ? (int)$data['school_id'] : (isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0);

                        if (!$schoolId) {
                            jsonResponse(['success' => false, 'message' => 'school_id required'], 400);
                            break;
                        }

                        $service = new SchoolSettingsService();
                        $result = $service->getSchoolSettings($schoolId);
                        jsonResponse($result);
                        break;
                    }
                }

                if ($action === 'save_school') {
                    if ($method === 'POST') {
                        $data = getRequestData();
                        $schoolId = isset($data['school_id']) ? (int)$data['school_id'] : 0;

                        if (!$schoolId) {
                            jsonResponse(['success' => false, 'message' => 'school_id required'], 400);
                            break;
                        }

                        $settings = $data['settings'] ?? [];
                        if (empty($settings)) {
                            jsonResponse(['success' => false, 'message' => 'No settings provided'], 400);
                            break;
                        }

                        $service = new SchoolSettingsService();
                        $userId = $_SESSION['user_id'] ?? 0;
                        $result = $service->bulkUpdateSchoolSettings($schoolId, $settings, $userId);
                        jsonResponse($result);
                        break;
                    }
                }

                if ($action === 'reset_school') {
                    if ($method === 'POST') {
                        $data = getRequestData();
                        $schoolId = isset($data['school_id']) ? (int)$data['school_id'] : 0;

                        if (!$schoolId) {
                            jsonResponse(['success' => false, 'message' => 'school_id required'], 400);
                            break;
                        }

                        $service = new SchoolSettingsService();
                        $userId = $_SESSION['user_id'] ?? 0;
                        $result = $service->initializeSchoolSettings($schoolId, $userId);
                        jsonResponse($result);
                        break;
                    }
                }

                if ($action === 'get_campus') {
                    if ($method === 'POST' || $method === 'GET') {
                        $data = getRequestData();
                        $campusId = isset($data['campus_id']) ? (int)$data['campus_id'] : (isset($_GET['campus_id']) ? (int)$_GET['campus_id'] : 0);

                        if (!$campusId) {
                            jsonResponse(['success' => false, 'message' => 'campus_id required'], 400);
                            break;
                        }

                        $service = new SchoolSettingsService();
                        $result = $service->getCampusSettings($campusId);
                        jsonResponse($result);
                        break;
                    }
                }

                if ($action === 'save_campus') {
                    if ($method === 'POST') {
                        $data = getRequestData();
                        $campusId = isset($data['campus_id']) ? (int)$data['campus_id'] : 0;

                        if (!$campusId) {
                            jsonResponse(['success' => false, 'message' => 'campus_id required'], 400);
                            break;
                        }

                        $settings = $data['settings'] ?? [];
                        if (empty($settings)) {
                            jsonResponse(['success' => false, 'message' => 'No settings provided'], 400);
                            break;
                        }

                        $service = new SchoolSettingsService();
                        $userId = $_SESSION['user_id'] ?? 0;
                        $result = $service->bulkUpdateCampusSettings($campusId, $settings, $userId);
                        jsonResponse($result);
                        break;
                    }
                }

                // ============================================
                // [v2.1] TENANT-LEVEL SETTINGS TABS
                // ============================================
                // Seven blocks for the seven tab bodies that were
                // previously 404ing at the fallback below. Each
                // dispatches by HTTP method to the matching
                // SettingsController method.
                // ============================================

                if ($action === 'general') {
                    if ($method === 'GET') {
                        $controller->getGeneralSettings();
                        break;
                    } elseif ($method === 'POST') {
                        $controller->updateGeneralSettings();
                        break;
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        break;
                    }
                }

                if ($action === 'security') {
                    if ($method === 'GET') {
                        $controller->getSecuritySettings();
                        break;
                    } elseif ($method === 'POST') {
                        $controller->updateSecuritySettings();
                        break;
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        break;
                    }
                }

                if ($action === 'currency') {
                    if ($method === 'GET') {
                        $controller->getCurrencySettings();
                        break;
                    } elseif ($method === 'POST') {
                        $controller->updateCurrencySettings();
                        break;
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        break;
                    }
                }

                if ($action === 'integrations') {
                    if ($method === 'GET') {
                        $controller->getIntegrationSettings();
                        break;
                    } elseif ($method === 'POST') {
                        $controller->updateIntegrationSettings();
                        break;
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        break;
                    }
                }

                if ($action === 'email') {
                    if ($method === 'GET') {
                        $controller->getEmailSettings();
                        break;
                    } elseif ($method === 'POST') {
                        $controller->updateEmailSettings();
                        break;
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        break;
                    }
                }

                if ($action === 'advanced') {
                    if ($method === 'GET') {
                        $controller->getAdvancedSettings();
                        break;
                    } elseif ($method === 'POST') {
                        $controller->updateAdvancedSettings();
                        break;
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        break;
                    }
                }

                if ($action === 'domains') {
                    if ($method === 'GET') {
                        $controller->getDomainSettings();
                        break;
                    } elseif ($method === 'POST') {
                        $controller->updateDomainSettings();
                        break;
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        break;
                    }
                }

                if ($action === 'appearance') {
                    if ($method === 'GET') {
                        $controller->getAppearanceSettings();
                        break;
                    } elseif ($method === 'POST') {
                        $controller->updateAppearanceSettings();
                        break;
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        break;
                    }
                }

                // ============================================
                // [v2.3] EMAIL TEST CONNECTION
                // ============================================
                // The Email tab's Test Connection button previously
                // called ?endpoint=settings/email/test, which the
                // outer switch read as a single endpoint string with
                // a slash and did not dispatch. This block handles
                // the corrected URL ?endpoint=settings&action=email_test
                // and calls SettingsController::testEmailConnection().
                // ============================================
                if ($action === 'email_test') {
                    if ($method === 'POST') {
                        $controller->testEmailConnection();
                        break;
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        break;
                    }
                }

                // ============================================
                // [v2.4] CLEAR CACHE
                // ============================================
                // Dispatched by the Advanced tab's Clear Cache button
                // in public/platform/tenant/settings/advanced.php.
                // POST-only. Delegates to
                // SettingsController::clearCache().
                // ============================================
                if ($action === 'clear_cache') {
                    if ($method === 'POST') {
                        $controller->clearCache();
                        break;
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        break;
                    }
                }

                // ============================================
                // [v2.4] OPTIMIZE DATABASE
                // ============================================
                // Dispatched by the Advanced tab's Optimize Database
                // button in public/platform/tenant/settings/advanced.php.
                // POST-only. Delegates to
                // SettingsController::optimizeDatabase().
                // ============================================
                if ($action === 'optimize_database') {
                    if ($method === 'POST') {
                        $controller->optimizeDatabase();
                        break;
                    } else {
                        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        break;
                    }
                }

                jsonResponse([
                    'success' => false,
                    'message' => 'Settings endpoint not found',
                    'available_actions' => ['general', 'security', 'currency', 'integrations', 'email', 'advanced', 'appearance', 'domains', 'email_test', 'clear_cache', 'optimize_database', 'get_school', 'save_school', 'reset_school', 'get_campus', 'save_campus', 'payments']
                ], 404);
            } catch (Exception $e) {
                error_log('Settings API error: ' . $e->getMessage());
                jsonResponse(['success' => false, 'message' => 'Settings API error: ' . $e->getMessage()], 500);
            }
            break;

        // ============================================
        // MONITORING ENDPOINTS
        // ============================================
        case 'monitoring':
            try {
                $db = getDBConnection();

                if (isset($_GET['monthly_growth']) && $_GET['monthly_growth'] === 'true') {
                    $months = isset($_GET['months']) ? (int)$_GET['months'] : 12;

                    $sql = "SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count
                            FROM tenants WHERE deleted_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)
                            GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month ASC";
                    $data = $db->fetchAll($sql, [$months]);

                    $result = [];
                    $currentDate = new DateTime();
                    $currentDate->modify('-' . ($months - 1) . ' months');

                    $existingData = [];
                    foreach ($data as $item) {
                        $existingData[$item['month']] = (int)$item['count'];
                    }

                    for ($i = 0; $i < $months; $i++) {
                        $monthKey = $currentDate->format('Y-m');
                        $result[] = ['month' => $currentDate->format('M Y'), 'count' => $existingData[$monthKey] ?? 0];
                        $currentDate->modify('+1 month');
                    }

                    jsonResponse(['success' => true, 'data' => $result]);
                    break;
                }

                if (isset($_GET['revenue']) && $_GET['revenue'] === 'true') {
                    try {
                        $sql = "SELECT sp.plan_name, COALESCE(SUM(ts.amount), 0) as revenue
                                FROM subscription_plans sp
                                LEFT JOIN tenant_subscriptions ts ON sp.id = ts.plan_id AND ts.deleted_at IS NULL
                                WHERE sp.deleted_at IS NULL
                                GROUP BY sp.id, sp.plan_name ORDER BY revenue DESC LIMIT 10";
                        $data = $db->fetchAll($sql);
                    } catch (Exception $e) {
                        $data = [];
                    }

                    if (empty($data)) {
                        $data = [
                            ['plan_name' => 'Free Plan', 'revenue' => 0],
                            ['plan_name' => 'Basic Plan', 'revenue' => 0],
                            ['plan_name' => 'Standard Plan', 'revenue' => 0],
                            ['plan_name' => 'Premium Plan', 'revenue' => 0]
                        ];
                    }

                    jsonResponse(['success' => true, 'data' => $data]);
                    break;
                }

                if (isset($_GET['top_tenants']) && $_GET['top_tenants'] === 'true') {
                    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;

                    try {
                        $sql = "SELECT t.id, t.tenant_name, t.legal_name,
                                       COALESCE(t.tenant_name, t.legal_name, 'Unnamed') as tenant_name,
                                       COUNT(DISTINCT u.id) as users,
                                       COUNT(DISTINCT ts.id) as subscriptions,
                                       COALESCE(SUM(ts.amount), 0) as revenue
                                FROM tenants t
                                LEFT JOIN platform_users u ON t.id = u.tenant_id AND u.deleted_at IS NULL
                                LEFT JOIN tenant_subscriptions ts ON t.id = ts.tenant_id AND ts.deleted_at IS NULL
                                WHERE t.deleted_at IS NULL
                                GROUP BY t.id, t.tenant_name, t.legal_name
                                ORDER BY revenue DESC LIMIT ?";
                        $data = $db->fetchAll($sql, [$limit]);
                    } catch (Exception $e) {
                        $data = [];
                    }

                    if (empty($data)) {
                        $data = [
                            ['id' => 1, 'tenant_name' => 'Demo Tenant', 'users' => 10, 'subscriptions' => 2, 'revenue' => 199.00],
                            ['id' => 2, 'tenant_name' => 'Test School', 'users' => 5, 'subscriptions' => 1, 'revenue' => 79.00]
                        ];
                    }

                    jsonResponse(['success' => true, 'data' => $data]);
                    break;
                }

                // Default stats - using COUNT and COALESCE without 'amount' column
                $totalTenants = $db->fetchOne("SELECT COUNT(*) as count FROM tenants WHERE deleted_at IS NULL");
                $totalUsers = $db->fetchOne("SELECT COUNT(*) as count FROM platform_users WHERE deleted_at IS NULL");
                $activeSubscriptions = $db->fetchOne("SELECT COUNT(*) as count FROM tenant_subscriptions WHERE status = 'active' AND deleted_at IS NULL");

                jsonResponse([
                    'success' => true,
                    'data' => [
                        'total_tenants' => (int)($totalTenants['count'] ?? 0),
                        'total_users' => (int)($totalUsers['count'] ?? 0),
                        'active_subscriptions' => (int)($activeSubscriptions['count'] ?? 0)
                    ]
                ]);
            } catch (Exception $e) {
                error_log('Monitoring API error: ' . $e->getMessage());
                jsonResponse(['success' => false, 'message' => 'Monitoring API error: ' . $e->getMessage()], 500);
            }
            break;

        // ============================================
        // SUBSCRIPTIONS ENDPOINTS
        // ============================================
        case 'subscriptions':
            try {
                require_once $projectRoot . 'app/controllers/Platform/SubscriptionController.php';
                $controller = new SubscriptionController();
                $action = isset($_GET['action']) ? $_GET['action'] : '';
                $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

                error_log("Subscriptions API: action=$action, id=$id, method=$method");

                switch ($action) {
                    case 'list':
                    case 'plans':
                    case '':
                        if ($method === 'GET') {
                            $controller->getSubscriptionPlans();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'get_plan':
                        if ($method === 'GET' && $id > 0) {
                            $controller->getSubscriptionPlan($id);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'create_plan':
                        if ($method === 'POST') {
                            $controller->createPlan();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'update_plan':
                        if (($method === 'PUT' || $method === 'POST') && $id > 0) {
                            $controller->updatePlan($id);
                        } elseif ($method === 'PUT' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Plan ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'delete_plan':
                        if (($method === 'DELETE' || $method === 'POST') && $id > 0) {
                            $controller->deletePlan($id);
                        } elseif ($method === 'DELETE' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Plan ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'tenant_subscriptions':
                        if ($method === 'GET') {
                            $controller->getTenantSubscriptions();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'assign_plan':
                        if ($method === 'POST') {
                            $controller->assignPlanToTenant();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    default:
                        jsonResponse([
                            'success' => false,
                            'message' => 'Subscription endpoint not found: ' . $action,
                            'available_actions' => ['list', 'plans', 'get_plan', 'create_plan', 'update_plan', 'delete_plan', 'tenant_subscriptions', 'assign_plan']
                        ], 404);
                }
            } catch (Exception $e) {
                error_log('Subscriptions API error: ' . $e->getMessage());
                jsonResponse(['success' => false, 'message' => 'Subscriptions API error: ' . $e->getMessage()], 500);
            }
            break;

        // ============================================
        // DOMAINS ENDPOINTS
        // ============================================
        case 'domains':
            try {
                require_once $projectRoot . 'app/controllers/Platform/DomainController.php';
                $controller = new DomainController();
                $action = isset($_GET['action']) ? $_GET['action'] : '';
                $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

                error_log("Domains API: action=$action, id=$id, method=$method");

                if ($method === 'POST' && empty($action)) {
                    $controller->createDomain();
                    break;
                }

                switch ($action) {
                    case 'list':
                    case '':
                        if ($method === 'GET') {
                            $controller->getDomains();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'stats':
                        if ($method === 'GET') {
                            $controller->getStats();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'get':
                        if ($method === 'GET' && $id > 0) {
                            $controller->getDomain($id);
                        } elseif ($method === 'GET') {
                            jsonResponse(['success' => false, 'message' => 'Domain ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'create':
                        if ($method === 'POST') {
                            $controller->createDomain();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'update':
                        if (($method === 'PUT' || $method === 'POST') && $id > 0) {
                            $controller->updateDomain($id);
                        } elseif ($method === 'PUT' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Domain ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'activate':
                        if (($method === 'PUT' || $method === 'POST') && $id > 0) {
                            $controller->activateDomain($id);
                        } elseif ($method === 'PUT' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Domain ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'deactivate':
                        if (($method === 'PUT' || $method === 'POST') && $id > 0) {
                            $controller->deactivateDomain($id);
                        } elseif ($method === 'PUT' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Domain ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'verify':
                        if (($method === 'POST' || $method === 'PUT') && $id > 0) {
                            $controller->verifyDomain($id);
                        } elseif ($method === 'POST' || $method === 'PUT') {
                            jsonResponse(['success' => false, 'message' => 'Domain ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'delete':
                        if (($method === 'DELETE' || $method === 'POST') && $id > 0) {
                            $controller->deleteDomain($id);
                        } elseif ($method === 'DELETE' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Domain ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'check':
                        if ($method === 'GET') {
                            $controller->checkDomainAvailability();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'by-tenant':
                        if ($method === 'GET') {
                            $controller->getDomainsByTenant();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'autocomplete':
                        if ($method === 'GET') {
                            $controller->autocompleteDomains();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    default:
                        jsonResponse([
                            'success' => false,
                            'message' => 'Domain endpoint not found: ' . $action,
                            'available_actions' => ['list', 'stats', 'get', 'create', 'update', 'activate', 'deactivate', 'verify', 'delete', 'check', 'by-tenant', 'autocomplete']
                        ], 404);
                }
            } catch (Exception $e) {
                error_log('Domains API error: ' . $e->getMessage());
                jsonResponse(['success' => false, 'message' => 'Domains API error: ' . $e->getMessage()], 500);
            }
            break;

        // ============================================
        // AUDIT LOGS ENDPOINTS
        // ============================================
        case 'audit-logs':
            try {
                require_once $projectRoot . 'app/controllers/Platform/AuditLogController.php';
                $controller = new AuditLogController();

                if (isset($_GET['stats']) && $_GET['stats'] === 'true') {
                    $controller->getStats();
                } elseif (isset($_GET['export']) && $_GET['export'] === 'true') {
                    $controller->exportLogs();
                } elseif (isset($_GET['clean']) && isset($_GET['days'])) {
                    $controller->cleanLogs((int)$_GET['days']);
                } elseif (isset($_GET['id'])) {
                    $controller->getLog((int)$_GET['id']);
                } else {
                    $controller->getLogs();
                }
            } catch (Exception $e) {
                error_log('Audit logs API error: ' . $e->getMessage());
                jsonResponse(['success' => false, 'message' => 'Audit logs API error: ' . $e->getMessage()], 500);
            }
            break;

        // ============================================
        // PAYMENTS ENDPOINTS
        // ============================================
        case 'payments':
            try {
                $db = getDBConnection();
                $action = isset($_GET['action']) ? $_GET['action'] : '';
                $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

                error_log("Payments API: action=$action, id=$id, method=$method");

                // GET /payments/stats - Get payment statistics
                if ($action === 'stats' && $method === 'GET') {
                    try {
                        $totalRevenue = $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM payment_transactions WHERE status = 'SUCCESS'");
                        $successful = $db->fetchOne("SELECT COUNT(*) as count FROM payment_transactions WHERE status = 'SUCCESS'");
                        $pending = $db->fetchOne("SELECT COUNT(*) as count FROM payment_transactions WHERE status IN ('PENDING', 'PROCESSING')");
                        $failed = $db->fetchOne("SELECT COUNT(*) as count FROM payment_transactions WHERE status IN ('FAILED', 'CANCELLED', 'EXPIRED')");
                        $outstanding = $db->fetchOne("SELECT COUNT(*) as count FROM payment_transactions WHERE status = 'PENDING'");
                        $refunds = $db->fetchOne("SELECT COUNT(*) as count FROM payment_transactions WHERE status = 'REFUNDED'");

                        jsonResponse([
                            'success' => true,
                            'data' => [
                                'total_revenue' => (float)($totalRevenue['total'] ?? 0),
                                'successful' => (int)($successful['count'] ?? 0),
                                'pending' => (int)($pending['count'] ?? 0),
                                'failed' => (int)($failed['count'] ?? 0),
                                'outstanding_invoices' => (int)($outstanding['count'] ?? 0),
                                'active_subscriptions' => 0,
                                'expired_subscriptions' => 0,
                                'refunds' => (int)($refunds['count'] ?? 0)
                            ]
                        ]);
                    } catch (Exception $e) {
                        error_log('Stats error: ' . $e->getMessage());
                        jsonResponse([
                            'success' => true,
                            'data' => [
                                'total_revenue' => 0,
                                'successful' => 0,
                                'pending' => 0,
                                'failed' => 0,
                                'outstanding_invoices' => 0,
                                'active_subscriptions' => 0,
                                'expired_subscriptions' => 0,
                                'refunds' => 0
                            ]
                        ]);
                    }
                    break;
                }

                // GET /payments/get/{id}
                if ($action === 'get' && $id > 0 && $method === 'GET') {
                    try {
                        $sql = "SELECT * FROM payment_transactions WHERE id = ?";
                        $transaction = $db->fetchOne($sql, [$id]);

                        if (!$transaction) {
                            jsonResponse(['success' => false, 'message' => 'Transaction not found'], 404);
                            break;
                        }

                        jsonResponse(['success' => true, 'data' => $transaction]);
                    } catch (Exception $e) {
                        error_log('Get transaction error: ' . $e->getMessage());
                        jsonResponse(['success' => false, 'message' => 'Error fetching transaction: ' . $e->getMessage()], 500);
                    }
                    break;
                }

                // GET /payments/transactions - Get all transactions
                if (($action === 'transactions' || $action === 'list' || $action === '') && $method === 'GET') {
                    try {
                        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
                        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
                        $status = isset($_GET['status']) ? trim($_GET['status']) : '';
                        $provider = isset($_GET['provider']) ? trim($_GET['provider']) : '';
                        $tenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;

                        $params = [];
                        $where = ["1=1"];

                        if (!empty($search)) {
                            $where[] = "(internal_reference LIKE ? OR provider_transaction_id LIKE ? OR tenant_name LIKE ? OR description LIKE ? OR customer_name LIKE ?)";
                            $searchTerm = "%$search%";
                            $params[] = $searchTerm;
                            $params[] = $searchTerm;
                            $params[] = $searchTerm;
                            $params[] = $searchTerm;
                            $params[] = $searchTerm;
                        }

                        if (!empty($status)) {
                            $where[] = "status = ?";
                            $params[] = $status;
                        }

                        if (!empty($provider)) {
                            $where[] = "provider = ?";
                            $params[] = $provider;
                        }

                        if ($tenantId > 0) {
                            $where[] = "tenant_id = ?";
                            $params[] = $tenantId;
                        }

                        $whereClause = "WHERE " . implode(" AND ", $where);
                        $offset = ($page - 1) * $limit;

                        $countSql = "SELECT COUNT(*) as total FROM payment_transactions $whereClause";
                        $countResult = $db->fetchOne($countSql, $params);
                        $total = (int)($countResult['total'] ?? 0);
                        $totalPages = $total > 0 ? ceil($total / $limit) : 0;

                        $sql = "SELECT * FROM payment_transactions 
                                $whereClause 
                                ORDER BY created_at DESC 
                                LIMIT ? OFFSET ?";
                        $params[] = $limit;
                        $params[] = $offset;

                        $transactions = $db->fetchAll($sql, $params);

                        jsonResponse([
                            'success' => true,
                            'data' => [
                                'transactions' => $transactions,
                                'total' => $total,
                                'total_pages' => $totalPages,
                                'current_page' => $page,
                                'per_page' => $limit
                            ]
                        ]);
                    } catch (Exception $e) {
                        error_log('Transactions error: ' . $e->getMessage());
                        jsonResponse(['success' => false, 'message' => 'Error fetching transactions: ' . $e->getMessage()], 500);
                    }
                    break;
                }

                // GET /payments/autocomplete
                if ($action === 'autocomplete' && $method === 'GET') {
                    try {
                        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
                        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

                        if (empty($search) || strlen($search) < 2) {
                            jsonResponse(['success' => true, 'data' => []]);
                            break;
                        }

                        $params = [];
                        $where = ["1=1"];
                        $where[] = "(internal_reference LIKE ? OR provider_transaction_id LIKE ? OR tenant_name LIKE ? OR customer_name LIKE ?)";
                        $searchTerm = "%$search%";
                        $params[] = $searchTerm;
                        $params[] = $searchTerm;
                        $params[] = $searchTerm;
                        $params[] = $searchTerm;

                        $whereClause = "WHERE " . implode(" AND ", $where);

                        $sql = "SELECT id, internal_reference, provider_transaction_id, tenant_name, amount, status 
                                FROM payment_transactions 
                                $whereClause 
                                ORDER BY created_at DESC 
                                LIMIT ?";
                        $params[] = $limit;

                        $results = $db->fetchAll($sql, $params);

                        $formatted = [];
                        foreach ($results as $row) {
                            $formatted[] = [
                                'id' => $row['id'],
                                'value' => $row['internal_reference'] ?? $row['provider_transaction_id'],
                                'internal_reference' => $row['internal_reference'],
                                'tenant_name' => $row['tenant_name'],
                                'amount' => $row['amount'],
                                'status' => $row['status']
                            ];
                        }

                        jsonResponse(['success' => true, 'data' => $formatted]);
                    } catch (Exception $e) {
                        error_log('Autocomplete error: ' . $e->getMessage());
                        jsonResponse(['success' => true, 'data' => []]);
                    }
                    break;
                }

                // POST /payments/verify/{id}
                if ($action === 'verify' && $id > 0 && ($method === 'POST' || $method === 'PUT')) {
                    try {
                        $db->execute(
                            "UPDATE payment_transactions SET status = 'SUCCESS', verification_status = 'VERIFIED', completed_at = NOW(), updated_at = NOW() WHERE id = ?",
                            [$id]
                        );
                        jsonResponse(['success' => true, 'message' => 'Transaction verified successfully']);
                    } catch (Exception $e) {
                        jsonResponse(['success' => false, 'message' => 'Error verifying transaction: ' . $e->getMessage()], 500);
                    }
                    break;
                }

                // POST /payments/refund/{id}
                if ($action === 'refund' && $id > 0 && ($method === 'POST' || $method === 'PUT')) {
                    try {
                        $data = json_decode(file_get_contents('php://input'), true);
                        $amount = $data['amount'] ?? null;

                        $db->execute(
                            "UPDATE payment_transactions SET status = 'REFUNDED', updated_at = NOW() WHERE id = ?",
                            [$id]
                        );
                        jsonResponse(['success' => true, 'message' => 'Refund processed successfully']);
                    } catch (Exception $e) {
                        jsonResponse(['success' => false, 'message' => 'Error processing refund: ' . $e->getMessage()], 500);
                    }
                    break;
                }

                // POST /payments/reconcile
                if ($action === 'reconcile' && $method === 'POST') {
                    try {
                        $stmt = $db->prepare("UPDATE payment_transactions SET status = 'SUCCESS', reconciliation_status = 'RECONCILED', updated_at = NOW() WHERE status IN ('PENDING', 'PROCESSING')");
                        $stmt->execute();
                        $processed = $stmt->rowCount();

                        jsonResponse([
                            'success' => true,
                            'message' => 'Reconciliation completed',
                            'data' => ['processed' => $processed]
                        ]);
                    } catch (Exception $e) {
                        jsonResponse(['success' => false, 'message' => 'Error running reconciliation: ' . $e->getMessage()], 500);
                    }
                    break;
                }

                // Default
                jsonResponse([
                    'success' => false,
                    'message' => 'Payment endpoint not found: ' . $action,
                    'available_actions' => ['stats', 'transactions', 'list', 'get', 'verify', 'refund', 'reconcile', 'autocomplete']
                ], 404);
            } catch (Exception $e) {
                error_log('Payments API error: ' . $e->getMessage());
                error_log('Payments API trace: ' . $e->getTraceAsString());
                jsonResponse(['success' => false, 'message' => 'Payments API error: ' . $e->getMessage()], 500);
            }
            break;

        // ============================================
        // IDENTITY ENDPOINTS
        // ============================================
        case 'identity':
            try {
                require_once $projectRoot . 'app/controllers/Identity/IdentityController.php';
                $controller = new IdentityController();
                $action = isset($_GET['action']) ? $_GET['action'] : '';
                $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

                error_log("Identity API: action=$action, id=$id, method=$method");

                switch ($action) {
                    // PEOPLE ENDPOINTS
                    case 'list':
                    case '':
                        if ($method === 'GET') {
                            $controller->listPeople();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'get':
                        if ($method === 'GET' && $id > 0) {
                            $controller->getPerson($id);
                        } elseif ($method === 'GET') {
                            jsonResponse(['success' => false, 'message' => 'Person ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'create':
                        if ($method === 'POST') {
                            $controller->createPerson();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST for creating people.'], 405);
                        }
                        break;
                    case 'update':
                        if (($method === 'PUT' || $method === 'POST') && $id > 0) {
                            $controller->updatePerson($id);
                        } elseif ($method === 'PUT' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Person ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use PUT or POST for updating people.'], 405);
                        }
                        break;
                    case 'delete':
                        if (($method === 'DELETE' || $method === 'POST') && $id > 0) {
                            $controller->deletePerson($id);
                        } elseif ($method === 'DELETE' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Person ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use DELETE or POST for deleting people.'], 405);
                        }
                        break;
                    case 'restore':
                        if (($method === 'POST' || $method === 'PUT') && $id > 0) {
                            $controller->restorePerson($id);
                        } elseif ($method === 'POST' || $method === 'PUT') {
                            jsonResponse(['success' => false, 'message' => 'Person ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST or PUT for restoring people.'], 405);
                        }
                        break;
                    case 'stats':
                        if ($method === 'GET') {
                            $controller->getStats();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'search':
                        if ($method === 'GET') {
                            $controller->searchPeople();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'by_tenant':
                        if ($method === 'GET') {
                            $controller->getPeopleByTenant();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'types':
                        if ($method === 'GET') {
                            $controller->getPersonTypes();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'bulk_create':
                        if ($method === 'POST') {
                            $controller->bulkCreatePeople();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST for bulk creating people.'], 405);
                        }
                        break;
                    case 'update_status':
                        if (($method === 'PUT' || $method === 'POST') && $id > 0) {
                            $controller->updatePersonStatus($id);
                        } elseif ($method === 'PUT' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Person ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use PUT or POST for updating status.'], 405);
                        }
                        break;
                    case 'by_role':
                        if ($method === 'GET') {
                            $controller->getPeopleByRole();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'by_school':
                        if ($method === 'GET') {
                            $controller->getPeopleBySchool();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'by_campus':
                        if ($method === 'GET') {
                            $controller->getPeopleByCampus();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'contacts':
                        if ($method === 'GET' && $id > 0) {
                            $controller->getPersonContacts($id);
                        } elseif ($method === 'GET') {
                            jsonResponse(['success' => false, 'message' => 'Person ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'address':
                        if ($method === 'GET' && $id > 0) {
                            $controller->getPersonAddress($id);
                        } elseif ($method === 'GET') {
                            jsonResponse(['success' => false, 'message' => 'Person ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'roles':
                        if ($method === 'GET' && $id > 0) {
                            $controller->getPersonRoles($id);
                        } elseif ($method === 'GET') {
                            jsonResponse(['success' => false, 'message' => 'Person ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;

                    // IDENTITY DOCUMENTS ENDPOINTS
                    case 'documents':
                    case 'docs':
                        if ($method === 'GET') {
                            $controller->listDocuments();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'get_document':
                        if ($method === 'GET' && $id > 0) {
                            $controller->getDocument($id);
                        } elseif ($method === 'GET') {
                            jsonResponse(['success' => false, 'message' => 'Document ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'create_document':
                        if ($method === 'POST') {
                            $controller->createDocument();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST for creating documents.'], 405);
                        }
                        break;
                    case 'update_document':
                        if (($method === 'PUT' || $method === 'POST') && $id > 0) {
                            $controller->updateDocument($id);
                        } elseif ($method === 'PUT' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Document ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use PUT or POST for updating documents.'], 405);
                        }
                        break;
                    case 'delete_document':
                        if (($method === 'DELETE' || $method === 'POST') && $id > 0) {
                            $controller->deleteDocument($id);
                        } elseif ($method === 'DELETE' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Document ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use DELETE or POST for deleting documents.'], 405);
                        }
                        break;
                    case 'verify_document':
                        if (($method === 'POST' || $method === 'PUT') && $id > 0) {
                            $controller->verifyDocument($id);
                        } elseif ($method === 'POST' || $method === 'PUT') {
                            jsonResponse(['success' => false, 'message' => 'Document ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST or PUT for verifying documents.'], 405);
                        }
                        break;
                    case 'reject_document':
                        if (($method === 'POST' || $method === 'PUT') && $id > 0) {
                            $controller->rejectDocument($id);
                        } elseif ($method === 'POST' || $method === 'PUT') {
                            jsonResponse(['success' => false, 'message' => 'Document ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST or PUT for rejecting documents.'], 405);
                        }
                        break;
                    case 'doc_stats':
                        if ($method === 'GET') {
                            $controller->getDocumentStats();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'doc_types':
                        if ($method === 'GET') {
                            $controller->getDocumentTypes();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'student_register':
                    case 'register_student':
                        if ($method === 'POST') {
                            try {
                                $controllerPath = $projectRoot . 'app/controllers/Identity/StudentController.php';
                                if (!file_exists($controllerPath)) {
                                    throw new Exception('StudentController.php not found at: ' . $controllerPath);
                                }
                                require_once $controllerPath;
                                $controller = new StudentController();
                                $controller->register();
                            } catch (Exception $e) {
                                error_log('Student registration error: ' . $e->getMessage());
                                error_log('Student registration trace: ' . $e->getTraceAsString());
                                jsonResponse([
                                    'success' => false,
                                    'message' => 'Student registration error: ' . $e->getMessage()
                                ], 500);
                            }
                        } else {
                            jsonResponse([
                                'success' => false,
                                'message' => 'Method not allowed. Use POST for student registration.'
                            ], 405);
                        }
                        break;

                    default:
                        jsonResponse([
                            'success' => false,
                            'message' => 'Identity endpoint not found: ' . $action,
                            'available_actions' => [
                                'list',
                                'get',
                                'create',
                                'update',
                                'delete',
                                'restore',
                                'stats',
                                'search',
                                'by_tenant',
                                'types',
                                'bulk_create',
                                'update_status',
                                'by_role',
                                'by_school',
                                'by_campus',
                                'contacts',
                                'address',
                                'roles',
                                'documents',
                                'docs',
                                'get_document',
                                'create_document',
                                'update_document',
                                'delete_document',
                                'verify_document',
                                'reject_document',
                                'doc_stats',
                                'doc_types',
                                'student_register',
                                'register_student'
                            ]
                        ], 404);
                        break;
                }
            } catch (Exception $e) {
                error_log('Identity API error: ' . $e->getMessage());
                error_log('Identity API trace: ' . $e->getTraceAsString());
                jsonResponse(['success' => false, 'message' => 'Identity API error: ' . $e->getMessage()], 500);
            }
            break;

        // ============================================
        // STUDENT ENDPOINTS (singular)
        // ============================================
        case 'student':
            try {
                // Authenticate
                $authResult = authenticateRequest('Student API');
                if (!$authResult['authenticated']) {
                    jsonResponse(['success' => false, 'message' => 'Authentication required'], 401);
                    break;
                }

                require_once $projectRoot . 'app/controllers/Student/StudentController.php';
                $controller = new StudentController();
                $action = isset($_GET['action']) ? $_GET['action'] : '';
                $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

                error_log("Student API: action=$action, id=$id, method=$method");

                switch ($action) {
                        // ... existing student actions ...
                }
            } catch (Exception $e) {
                error_log('Student API error: ' . $e->getMessage());
                jsonResponse(['success' => false, 'message' => 'Student API error: ' . $e->getMessage()], 500);
            }
            break;

        // ============================================
        // STUDENTS ENDPOINTS (PLURAL - for listing/management)
        // ============================================
        case 'students':
            try {
                // Authenticate
                $authResult = authenticateRequest('Students API');
                if (!$authResult['authenticated']) {
                    jsonResponse(['success' => false, 'message' => 'Authentication required'], 401);
                    break;
                }

                require_once $projectRoot . 'app/controllers/Student/StudentController.php';
                $controller = new StudentController();
                $action = isset($_GET['action']) ? $_GET['action'] : '';
                $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

                error_log("Students API: action=$action, id=$id, method=$method");

                switch ($action) {
                    case 'list':
                    case '':
                        if ($method === 'GET') {
                            $controller->listStudents();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'autocomplete':
                        if ($method === 'GET') {
                            $controller->autocompleteStudents();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed for autocomplete'], 405);
                        }
                        break;
                    case 'get':
                        if ($method === 'GET' && $id > 0) {
                            $controller->getStudent($id);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Student ID required'], 400);
                        }
                        break;
                    case 'by_person':
                        if ($method === 'GET') {
                            $controller->getStudentByPerson();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'stats':
                        if ($method === 'GET') {
                            $controller->getStats();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'search':
                        if ($method === 'GET') {
                            $controller->search();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'guardians':
                        if ($method === 'GET' && $id > 0) {
                            $controller->getGuardians($id);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Student ID required'], 400);
                        }
                        break;
                    default:
                        jsonResponse([
                            'success' => false,
                            'message' => 'Students endpoint not found: ' . $action,
                            'available_actions' => [
                                'list',
                                'autocomplete',
                                'get',
                                'by_person',
                                'stats',
                                'search',
                                'guardians'
                            ]
                        ], 404);
                }
            } catch (Exception $e) {
                error_log('Students API error: ' . $e->getMessage());
                jsonResponse(['success' => false, 'message' => 'Students API error: ' . $e->getMessage()], 500);
            }
            break;

        // ============================================
        // GUARDIAN ENDPOINTS
        // ============================================
        case 'guardian':
            try {
                require_once $projectRoot . 'app/controllers/Student/GuardianController.php';
                $controller = new GuardianController();
                $action = isset($_GET['action']) ? $_GET['action'] : '';
                $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

                error_log("Guardian API: action=$action, id=$id, method=$method");

                switch ($action) {
                    case 'create_profile':
                        if ($method === 'POST') {
                            $controller->createGuardianProfile();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST for creating guardian profiles.'], 405);
                        }
                        break;
                    case 'get_profile':
                        if ($method === 'GET') {
                            $controller->getGuardianProfile();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'link':
                        if ($method === 'POST') {
                            $controller->linkGuardian();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST for linking guardians.'], 405);
                        }
                        break;
                    case 'bulk_link':
                        if ($method === 'POST') {
                            $controller->bulkLinkGuardians();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST for bulk linking.'], 405);
                        }
                        break;
                    case 'for_student':
                        if ($method === 'GET') {
                            $controller->getGuardiansForStudent();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'for_guardian':
                        if ($method === 'GET') {
                            $controller->getStudentsForGuardian();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'update_relationship':
                        if (($method === 'PUT' || $method === 'POST') && $id > 0) {
                            $controller->updateRelationship($id);
                        } elseif ($method === 'PUT' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Relationship ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use PUT or POST for updating relationships.'], 405);
                        }
                        break;
                    case 'delete_relationship':
                        if (($method === 'DELETE' || $method === 'POST') && $id > 0) {
                            $controller->deleteRelationship($id);
                        } elseif ($method === 'DELETE' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Relationship ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use DELETE or POST for deleting relationships.'], 405);
                        }
                        break;
                    case 'set_primary':
                        if ($method === 'POST') {
                            $controller->setPrimaryGuardian();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST for setting primary guardian.'], 405);
                        }
                        break;
                    case 'add_emergency_contact':
                        if ($method === 'POST') {
                            $controller->addEmergencyContact();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST for adding emergency contacts.'], 405);
                        }
                        break;
                    case 'update_emergency_contact':
                        if (($method === 'PUT' || $method === 'POST') && $id > 0) {
                            $controller->updateEmergencyContact($id);
                        } elseif ($method === 'PUT' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Emergency contact ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use PUT or POST for updating emergency contacts.'], 405);
                        }
                        break;
                    case 'delete_emergency_contact':
                        if (($method === 'DELETE' || $method === 'POST') && $id > 0) {
                            $controller->deleteEmergencyContact($id);
                        } elseif ($method === 'DELETE' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Emergency contact ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use DELETE or POST for deleting emergency contacts.'], 405);
                        }
                        break;
                    case 'get_emergency_contacts':
                        if ($method === 'GET') {
                            $controller->getEmergencyContacts();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'stats':
                        if ($method === 'GET') {
                            $controller->getStats();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'types':
                        if ($method === 'GET') {
                            $controller->getGuardianTypes();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'responsibilities':
                        if ($method === 'GET') {
                            $controller->getGuardianResponsibilities();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'check':
                        if ($method === 'GET') {
                            $controller->checkGuardian();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    default:
                        jsonResponse([
                            'success' => false,
                            'message' => 'Guardian endpoint not found: ' . $action,
                            'available_actions' => [
                                'create_profile',
                                'get_profile',
                                'link',
                                'bulk_link',
                                'for_student',
                                'for_guardian',
                                'update_relationship',
                                'delete_relationship',
                                'set_primary',
                                'add_emergency_contact',
                                'update_emergency_contact',
                                'delete_emergency_contact',
                                'get_emergency_contacts',
                                'stats',
                                'types',
                                'responsibilities',
                                'check'
                            ]
                        ], 404);
                        break;
                }
            } catch (Exception $e) {
                error_log('Guardian API error: ' . $e->getMessage());
                error_log('Guardian API trace: ' . $e->getTraceAsString());
                jsonResponse(['success' => false, 'message' => 'Guardian API error: ' . $e->getMessage()], 500);
            }
            break;

        // ============================================
        // STAFF ENDPOINTS
        // ============================================
        case 'staff':
            try {
                // Authenticate
                $authResult = authenticateRequest('Staff API');
                if (!$authResult['authenticated']) {
                    jsonResponse(['success' => false, 'message' => 'Authentication required'], 401);
                    break;
                }

                require_once $projectRoot . 'app/controllers/Staff/StaffController.php';
                $controller = new StaffController();
                $action = isset($_GET['action']) ? $_GET['action'] : '';
                $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

                error_log("Staff API: action=$action, id=$id, method=$method");

                switch ($action) {
                    case 'list':
                        if ($method === 'GET') {
                            $controller->listStaff();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'autocomplete':
                        if ($method === 'GET') {
                            $controller->autocompleteStaff();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed for autocomplete'], 405);
                        }
                        break;
                    case 'register':
                        if ($method === 'POST') {
                            $controller->register();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST for registration.'], 405);
                        }
                        break;
                    case 'get':
                        if ($method === 'GET' && $id > 0) {
                            $controller->getStaff($id);
                        } elseif ($method === 'GET') {
                            jsonResponse(['success' => false, 'message' => 'Staff ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'by_person':
                        if ($method === 'GET') {
                            $controller->getStaffByPerson();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'by_number':
                        if ($method === 'GET') {
                            $controller->getStaffByNumber();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'search':
                        if ($method === 'GET') {
                            $controller->search();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'stats':
                        if ($method === 'GET') {
                            $controller->getStats();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'update':
                        if (($method === 'PUT' || $method === 'POST') && $id > 0) {
                            $controller->updateStaff($id);
                        } elseif ($method === 'PUT' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Staff ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use PUT or POST.'], 405);
                        }
                        break;
                    case 'update_status':
                        if (($method === 'PUT' || $method === 'POST') && $id > 0) {
                            $controller->updateStatus($id);
                        } elseif ($method === 'PUT' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Staff ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use PUT or POST.'], 405);
                        }
                        break;
                    case 'delete':
                        if (($method === 'DELETE' || $method === 'POST') && $id > 0) {
                            $controller->deleteStaff($id);
                        } elseif ($method === 'DELETE' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Staff ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use DELETE or POST.'], 405);
                        }
                        break;
                    case 'add_qualification':
                        if ($method === 'POST' && $id > 0) {
                            $controller->addQualification($id);
                        } elseif ($method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Staff ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST.'], 405);
                        }
                        break;
                    case 'get_qualifications':
                        if ($method === 'GET' && $id > 0) {
                            $controller->getQualifications($id);
                        } elseif ($method === 'GET') {
                            jsonResponse(['success' => false, 'message' => 'Staff ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'verify_qualification':
                        if (($method === 'PUT' || $method === 'POST') && $id > 0) {
                            $controller->verifyQualification($id);
                        } elseif ($method === 'PUT' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Qualification ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use PUT or POST.'], 405);
                        }
                        break;
                    case 'delete_qualification':
                        if (($method === 'DELETE' || $method === 'POST') && $id > 0) {
                            $controller->deleteQualification($id);
                        } elseif ($method === 'DELETE' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Qualification ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use DELETE or POST.'], 405);
                        }
                        break;
                    case 'add_certification':
                        if ($method === 'POST' && $id > 0) {
                            $controller->addCertification($id);
                        } elseif ($method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Staff ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST.'], 405);
                        }
                        break;
                    case 'get_certifications':
                        if ($method === 'GET' && $id > 0) {
                            $controller->getCertifications($id);
                        } elseif ($method === 'GET') {
                            jsonResponse(['success' => false, 'message' => 'Staff ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'verify_certification':
                        if (($method === 'PUT' || $method === 'POST') && $id > 0) {
                            $controller->verifyCertification($id);
                        } elseif ($method === 'PUT' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Certification ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use PUT or POST.'], 405);
                        }
                        break;
                    case 'delete_certification':
                        if (($method === 'DELETE' || $method === 'POST') && $id > 0) {
                            $controller->deleteCertification($id);
                        } elseif ($method === 'DELETE' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Certification ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use DELETE or POST.'], 405);
                        }
                        break;
                    case 'add_training':
                        if ($method === 'POST' && $id > 0) {
                            $controller->addTraining($id);
                        } elseif ($method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Staff ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST.'], 405);
                        }
                        break;
                    case 'get_trainings':
                        if ($method === 'GET' && $id > 0) {
                            $controller->getTrainings($id);
                        } elseif ($method === 'GET') {
                            jsonResponse(['success' => false, 'message' => 'Staff ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'verify_training':
                        if (($method === 'PUT' || $method === 'POST') && $id > 0) {
                            $controller->verifyTraining($id);
                        } elseif ($method === 'PUT' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Training ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use PUT or POST.'], 405);
                        }
                        break;
                    case 'delete_training':
                        if (($method === 'DELETE' || $method === 'POST') && $id > 0) {
                            $controller->deleteTraining($id);
                        } elseif ($method === 'DELETE' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Training ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use DELETE or POST.'], 405);
                        }
                        break;
                    case 'add_employment_history':
                        if ($method === 'POST' && $id > 0) {
                            $controller->addEmploymentHistory($id);
                        } elseif ($method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Staff ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST.'], 405);
                        }
                        break;
                    case 'get_employment_history':
                        if ($method === 'GET' && $id > 0) {
                            $controller->getEmploymentHistory($id);
                        } elseif ($method === 'GET') {
                            jsonResponse(['success' => false, 'message' => 'Staff ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'add_document':
                        if ($method === 'POST' && $id > 0) {
                            $controller->addDocument($id);
                        } elseif ($method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Staff ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST.'], 405);
                        }
                        break;
                    case 'get_documents':
                        if ($method === 'GET' && $id > 0) {
                            $controller->getDocuments($id);
                        } elseif ($method === 'GET') {
                            jsonResponse(['success' => false, 'message' => 'Staff ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'verify_document':
                        if (($method === 'PUT' || $method === 'POST') && $id > 0) {
                            $controller->verifyDocument($id);
                        } elseif ($method === 'PUT' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Document ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use PUT or POST.'], 405);
                        }
                        break;
                    case 'delete_document':
                        if (($method === 'DELETE' || $method === 'POST') && $id > 0) {
                            $controller->deleteDocument($id);
                        } elseif ($method === 'DELETE' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Document ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use DELETE or POST.'], 405);
                        }
                        break;
                    case 'add_compliance':
                        if ($method === 'POST' && $id > 0) {
                            $controller->addCompliance($id);
                        } elseif ($method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Staff ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST.'], 405);
                        }
                        break;
                    case 'get_compliance':
                        if ($method === 'GET' && $id > 0) {
                            $controller->getCompliance($id);
                        } elseif ($method === 'GET') {
                            jsonResponse(['success' => false, 'message' => 'Staff ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'update_compliance_status':
                        if (($method === 'PUT' || $method === 'POST') && $id > 0) {
                            $controller->updateComplianceStatus($id);
                        } elseif ($method === 'PUT' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Compliance ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use PUT or POST.'], 405);
                        }
                        break;
                    case 'delete_compliance':
                        if (($method === 'DELETE' || $method === 'POST') && $id > 0) {
                            $controller->deleteCompliance($id);
                        } elseif ($method === 'DELETE' || $method === 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Compliance ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed. Use DELETE or POST.'], 405);
                        }
                        break;
                    case 'employee_types':
                        if ($method === 'GET') {
                            $controller->getEmployeeTypes();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'employment_statuses':
                        if ($method === 'GET') {
                            $controller->getEmploymentStatuses();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'qualification_types':
                        if ($method === 'GET') {
                            $controller->getQualificationTypes();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    default:
                        jsonResponse([
                            'success' => false,
                            'message' => 'Staff endpoint not found: ' . $action,
                            'available_actions' => [
                                'list',
                                'autocomplete',
                                'register',
                                'get',
                                'by_person',
                                'by_number',
                                'search',
                                'stats',
                                'update',
                                'update_status',
                                'delete',
                                'add_qualification',
                                'get_qualifications',
                                'verify_qualification',
                                'delete_qualification',
                                'add_certification',
                                'get_certifications',
                                'verify_certification',
                                'delete_certification',
                                'add_training',
                                'get_trainings',
                                'verify_training',
                                'delete_training',
                                'add_employment_history',
                                'get_employment_history',
                                'add_document',
                                'get_documents',
                                'verify_document',
                                'delete_document',
                                'add_compliance',
                                'get_compliance',
                                'update_compliance_status',
                                'delete_compliance',
                                'employee_types',
                                'employment_statuses',
                                'qualification_types'
                            ]
                        ], 404);
                        break;
                }
            } catch (Exception $e) {
                error_log('Staff API error: ' . $e->getMessage());
                error_log('Staff API trace: ' . $e->getTraceAsString());
                jsonResponse(['success' => false, 'message' => 'Staff API error: ' . $e->getMessage()], 500);
            }
            break;

        // =============================================
        // HEALTH ENDPOINTS
        // =============================================
        case 'health':
            try {
                require_once $projectRoot . 'app/controllers/Health/HealthController.php';
                $controller = new HealthController();
                $action = isset($_GET['action']) ? $_GET['action'] : '';
                $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

                error_log("Health API: action=$action, id=$id, method=$method");

                switch ($action) {
                    case 'list':
                    case '':
                        if ($method == 'GET') {
                            $controller->listHealthProfiles();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'get':
                        if ($method == 'GET' && $id > 0) {
                            $controller->getHealthProfile($id);
                        } elseif ($method == 'GET') {
                            jsonResponse(['success' => false, 'message' => 'Health profile ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'create':
                        if ($method == 'POST') {
                            $controller->createHealthProfile();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'update':
                        if (($method == 'PUT' || $method == 'POST') && $id > 0) {
                            $controller->updateHealthProfile($id);
                        } elseif ($method == 'PUT' || $method == 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Health profile ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'delete':
                        if (($method == 'DELETE' || $method == 'POST') && $id > 0) {
                            $controller->deleteHealthProfile($id);
                        } elseif ($method == 'DELETE' || $method == 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Health profile ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'by_person':
                        if ($method == 'GET') {
                            $controller->getHealthProfilesByPerson();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'stats':
                        if ($method == 'GET') {
                            $controller->getHealthStats();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'search':
                        if ($method == 'GET') {
                            $controller->searchHealthProfiles();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'types':
                        if ($method == 'GET') {
                            $controller->getHealthTypes();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'statuses':
                        if ($method == 'GET') {
                            $controller->getHealthStatuses();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'bulk_create':
                        if ($method == 'POST') {
                            $controller->bulkCreateHealthProfiles();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'update_status':
                        if (($method == 'PUT' || $method == 'POST') && $id > 0) {
                            $controller->updateHealthProfileStatus($id);
                        } elseif ($method == 'PUT' || $method == 'POST') {
                            jsonResponse(['success' => false, 'message' => 'Health profile ID required'], 400);
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'by_uuid':
                        if ($method == 'GET') {
                            $controller->getHealthProfileByUuid();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'counts_by_status':
                        if ($method == 'GET') {
                            $controller->getHealthCountsByStatus();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'summary':
                        if ($method == 'GET') {
                            $controller->getHealthSummary();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    case 'export':
                        if ($method == 'GET') {
                            $controller->exportHealthProfiles();
                        } else {
                            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
                        }
                        break;
                    default:
                        jsonResponse([
                            'success' => false,
                            'message' => 'Health endpoint not found: ' . $action,
                            'available_actions' => [
                                'list',
                                'get',
                                'create',
                                'update',
                                'delete',
                                'by_person',
                                'stats',
                                'search',
                                'types',
                                'statuses',
                                'bulk_create',
                                'update_status',
                                'by_uuid',
                                'counts_by_status',
                                'summary',
                                'export'
                            ]
                        ], 404);
                        break;
                }
            } catch (Exception $e) {
                error_log('Health API error: ' . $e->getMessage());
                jsonResponse(['success' => false, 'message' => 'Health API error: ' . $e->getMessage()], 500);
            }
            break;

        // ============================================
        // DEFAULT - 404
        // ============================================
        default:
            jsonResponse([
                'success' => false,
                'message' => 'Endpoint not found: ' . $endpoint,
                'available_endpoints' => [
                    'auth',
                    'users',
                    'tenants',
                    'schools',
                    'campuses',
                    'settings',
                    'monitoring',
                    'subscriptions',
                    'domains',
                    'audit-logs',
                    'payments',
                    'identity',
                    'student',
                    'students',
                    'guardian',
                    'staff',
                    'health'
                ]
            ], 404);
            break;
    }
} catch (Exception $e) {
    error_log('API Router error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    jsonResponse([
        'success' => false,
        'message' => 'API Router error: ' . $e->getMessage()
    ], 500);
}
