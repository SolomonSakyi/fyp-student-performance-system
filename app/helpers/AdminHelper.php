<?php
/**
 * Admin Helper
 * Handles admin session and authentication
 */
class AdminHelper
{
    /**
     * Start session if not already started
     */
    public static function start()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Check if admin is logged in
     */
    public static function isLoggedIn()
    {
        self::start();
        return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
    }

    /**
     * Require login - redirect to login page if not logged in
     */
    public static function requireLogin()
    {
        self::start();
        if (!self::isLoggedIn()) {
            header('Location: /admin/login.php');
            exit;
        }
    }

    /**
     * Login admin user
     */
    public static function login($username)
    {
        self::start();
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_login_time'] = time();
    }

    /**
     * Logout admin user
     */
    public static function logout()
    {
        self::start();
        session_destroy();
        header('Location: /admin/login.php');
        exit;
    }

    /**
     * Get admin username
     */
    public static function getUsername()
    {
        self::start();
        return $_SESSION['admin_username'] ?? 'Admin';
    }
}