<?php

/**
 * SessionHelper - Simple session management
 *
 * @package EduTrack
 * @filepath app/helpers/SessionHelper.php
 */
class SessionHelper
{
    public static function start()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function set($key, $value)
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function get($key, $default = null)
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function has($key)
    {
        self::start();
        return isset($_SESSION[$key]);
    }

    public static function remove($key)
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function destroy()
    {
        self::start();
        $_SESSION = array();
        session_destroy();
    }

    public static function isLoggedIn()
    {
        return self::get('logged_in', false) === true;
    }

    public static function isSuperAdmin()
    {
        return self::get('is_super_admin', false) === true;
    }

    public static function getUserId()
    {
        return self::get('user_id');
    }

    public static function getTenantId()
    {
        return self::get('tenant_id');
    }
}
