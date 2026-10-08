<?php

/**
 * Bootstrap Initialization
 *
 * @package EduTrack
 * @subpackage Bootstrap
 * @filepath bootstrap/init.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('CONFIG_PATH', ROOT_PATH . '/config');

// Set default timezone
date_default_timezone_set('Africa/Accra');

// Load helpers
require_once APP_PATH . '/helpers/DatabaseHelper.php';
require_once APP_PATH . '/services/Tenant/TenantContext.php';
require_once APP_PATH . '/helpers/TenantContextHelper.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_log('EduTrack Initialized: ' . date('Y-m-d H:i:s'));
