<?php

/**
 * Configuration File - EduTrack Platform
 *
 * @package EduTrack
 * @filepath config/config.php
 * @version 2.1
 *
 * v2.1 change (2026-10-08) [RAILWAY DEPLOY]:
 *   DB_* constants, APP_ENV, and PLATFORM_DOMAIN now read from
 *   environment variables, with the local XAMPP values as fallbacks.
 *   This is required so the same file works locally and on Railway
 *   (or any PaaS) without editing. The pattern $_ENV[...] ?? getenv(...)
 *   checks both sources because php.ini's variables_order may expose
 *   the variable in only one of them.
 */

// Database Configuration
define('DB_HOST', $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'edutrack_db');
define('DB_USER', $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '');

// Application Configuration
define('APP_NAME', 'EduTrack');
define('APP_VERSION', '2.0');
define('APP_ENV', $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'development');

// Platform Domain — read by TenantContextMiddleware
define('PLATFORM_DOMAIN', $_ENV['PLATFORM_DOMAIN'] ?? getenv('PLATFORM_DOMAIN') ?: 'admin.edutrack.local');

// Path Configuration
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
define('APP_PATH', BASE_PATH . '/app');
define('VIEWS_PATH', BASE_PATH . '/views');
define('STORAGE_PATH', BASE_PATH . '/storage');

// Session Configuration - Only set if session is not active
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 3600);
    ini_set('session.cookie_httponly', 1);
}

// Timezone
date_default_timezone_set('Africa/Accra');

// Error Reporting
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}
