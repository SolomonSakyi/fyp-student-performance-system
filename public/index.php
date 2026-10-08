<?php

/**
 * Main Entry Point - EduTrack Platform
 * 
 * @package EduTrack
 * @filepath public/index.php
 * @version 2.0
 */

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define project root path
define('PROJECT_ROOT', dirname(__DIR__));

// Load configuration
if (file_exists(PROJECT_ROOT . '/config/config.php')) {
    require_once PROJECT_ROOT . '/config/config.php';
}

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// =============================================
// GET REQUEST PATH
// =============================================
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$path = trim($path, '/');

// =============================================
// CHECK IF REQUEST IS FOR STATIC ASSETS
// =============================================
$staticExtensions = ['css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'ico', 'svg', 'woff', 'woff2', 'ttf', 'eot', 'pdf', 'zip', 'json', 'xml'];
$pathInfo = pathinfo($path);
if (isset($pathInfo['extension']) && in_array(strtolower($pathInfo['extension']), $staticExtensions)) {
    $filePath = __DIR__ . '/' . $path;
    if (file_exists($filePath)) {
        return false; // Let Apache serve the file
    }
}

// =============================================
// ALLOW PUBLIC ACCESS TO SPECIFIC PATHS
// =============================================
$publicPaths = [
    'platform/tenant/login.php',
    'platform/tenant/login',
    'platform/login.php',
    'platform/login',
    'api',
    'health',
    'health-check',
    'install'
];

$isPublicPath = false;
foreach ($publicPaths as $publicPath) {
    if (strpos($path, $publicPath) === 0) {
        $isPublicPath = true;
        break;
    }
}

// =============================================
// ROUTE THE REQUEST
// =============================================

// CASE 1: Public paths - no authentication required
if ($isPublicPath) {
    // If it's a PHP file, include and execute it
    $filePath = __DIR__ . '/' . $path . '.php';
    if (file_exists($filePath)) {
        require_once $filePath;
        exit;
    }
    // Check if it's a directory with index.php
    $dirPath = __DIR__ . '/' . $path . '/index.php';
    if (file_exists($dirPath)) {
        require_once $dirPath;
        exit;
    }
    // If the file doesn't exist, show 404
    http_response_code(404);
    include __DIR__ . '/404.php';
    exit;
}

// CASE 2: User is logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] == true) {

    // Check if user is Super Admin
    $isSuperAdmin = isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] == true;

    // Check if user has a tenant
    $hasTenant = isset($_SESSION['tenant_id']) && $_SESSION['tenant_id'] > 0;

    // =============================================
    // TENANT ADMIN ROUTES
    // =============================================
    if (strpos($path, 'platform/tenant') === 0) {
        // Super Admin can access tenant pages (for support/testing)
        // Tenant Admin can access their own tenant pages
        if ($isSuperAdmin || $hasTenant) {
            // Try to serve the file
            $filePath = __DIR__ . '/' . $path . '.php';
            if (file_exists($filePath)) {
                require_once $filePath;
                exit;
            }
            $dirPath = __DIR__ . '/' . $path . '/index.php';
            if (file_exists($dirPath)) {
                require_once $dirPath;
                exit;
            }
        }
    }

    // =============================================
    // PLATFORM ADMIN ROUTES (Super Admin Only)
    // =============================================
    if (strpos($path, 'platform') === 0 && !strpos($path, 'platform/tenant')) {
        // Only Super Admin can access platform admin pages
        if ($isSuperAdmin) {
            $filePath = __DIR__ . '/' . $path . '.php';
            if (file_exists($filePath)) {
                require_once $filePath;
                exit;
            }
            $dirPath = __DIR__ . '/' . $path . '/index.php';
            if (file_exists($dirPath)) {
                require_once $dirPath;
                exit;
            }
        } else {
            // Tenant Admin trying to access platform admin - redirect to tenant dashboard
            header('Location: /platform/tenant/dashboard.php');
            exit;
        }
    }

    // =============================================
    // ROOT - Redirect based on role
    // =============================================
    if ($path == '' || $path == 'index.php') {
        if ($isSuperAdmin) {
            header('Location: /platform/index.php');
        } elseif ($hasTenant) {
            header('Location: /platform/tenant/dashboard.php');
        } else {
            // Logged in but no tenant - go to tenant selection (only for Super Admin)
            if ($isSuperAdmin) {
                header('Location: /platform/tenants/select.php');
            } else {
                // Tenant Admin without tenant - logout
                session_destroy();
                header('Location: /platform/tenant/login.php?error=no_tenant');
            }
        }
        exit;
    }

    // =============================================
    // API ROUTES
    // =============================================
    if (strpos($path, 'api') === 0) {
        $apiPath = __DIR__ . '/' . $path . '.php';
        if (file_exists($apiPath)) {
            require_once $apiPath;
            exit;
        }
        // Try API directory
        $apiDirPath = __DIR__ . '/' . $path . '/index.php';
        if (file_exists($apiDirPath)) {
            require_once $apiDirPath;
            exit;
        }
    }
}

// =============================================
// NOT LOGGED IN - Redirect to login
// =============================================
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] != true) {
    // If trying to access platform admin, redirect to platform login
    if (strpos($path, 'platform') === 0) {
        header('Location: /platform/tenant/login.php');
        exit;
    }
    // Otherwise redirect to tenant login
    header('Location: /platform/tenant/login.php');
    exit;
}

// =============================================
// 404 - Page Not Found
// =============================================
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found | EduTrack</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .error-container {
            text-align: center;
            padding: 40px;
            max-width: 600px;
        }

        .error-container .error-icon {
            font-size: 80px;
            color: #4facfe;
            margin-bottom: 20px;
        }

        .error-container h1 {
            font-size: 72px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 10px;
        }

        .error-container h2 {
            font-size: 24px;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 15px;
        }

        .error-container p {
            color: #6c757d;
            font-size: 16px;
            margin-bottom: 25px;
        }

        .error-container .btn-primary {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border: none;
            padding: 10px 30px;
            font-weight: 500;
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            display: inline-block;
        }

        .error-container .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.4);
            color: #fff;
        }
    </style>
</head>

<body>
    <div class="error-container">
        <div class="error-icon"><i class="fas fa-search"></i></div>
        <h1>404</h1>
        <h2>Page Not Found</h2>
        <p>The requested URL <strong><?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?></strong> was not found on this server.</p>
        <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] == true): ?>
            <?php if (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] == true): ?>
                <a href="/platform/index.php" class="btn-primary"><i class="fas fa-arrow-left me-2"></i>Return to Dashboard</a>
            <?php else: ?>
                <a href="/platform/tenant/dashboard.php" class="btn-primary"><i class="fas fa-arrow-left me-2"></i>Return to Dashboard</a>
            <?php endif; ?>
        <?php else: ?>
            <a href="/platform/tenant/login.php" class="btn-primary"><i class="fas fa-arrow-left me-2"></i>Return to Login</a>
        <?php endif; ?>
    </div>
</body>

</html>
<?php
exit;
