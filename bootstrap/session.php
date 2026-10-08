<?php

/**
 * Session Bootstrap
 * Initializes session management on every request
 *
 * @package EduTrack
 * @subpackage Bootstrap
 * @version 1.0
 * @filepath bootstrap/session.php
 */

// Load required classes
require_once __DIR__ . '/../app/services/Auth/SessionManager.php';
require_once __DIR__ . '/../app/middleware/SessionMiddleware.php';

// Initialize session middleware
$sessionMiddleware = new SessionMiddleware();

// Handle the request
$valid = $sessionMiddleware->handle();

if (!$valid) {
    // Session middleware handles redirects
    exit;
}

// Store session middleware for global access
$GLOBALS['session'] = $sessionMiddleware;
$GLOBALS['session_manager'] = $sessionMiddleware->getSessionManager();
