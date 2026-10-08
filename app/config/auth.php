<?php

/**
 * Authentication Configuration
 * @package EduTrack
 * @filepath config/auth.php
 */

return [
    // ============================================================
    // SESSION CONFIGURATION
    // ============================================================
    'session' => [
        'timeout' => 28800, // 8 hours in seconds
        'cookie_httponly' => true,
        'cookie_secure' => false, // Set to true for production with HTTPS
        'cookie_samesite' => 'Strict',
        'use_strict_mode' => true,
        'regenerate_on_login' => true,
    ],

    // ============================================================
    // PASSWORD CONFIGURATION
    // ============================================================
    'password' => [
        'min_length' => 8,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_number' => true,
        'require_special' => true,
        'history_count' => 5,
        'expiry_days' => 90,
        'algorithm' => PASSWORD_BCRYPT,
        'cost' => 12,
    ],

    // ============================================================
    // OTP CONFIGURATION
    // ============================================================
    'otp' => [
        'length' => 6,
        'expiry_seconds' => 900, // 15 minutes
        'max_attempts' => 5,
        'resend_cooldown' => 60, // 60 seconds
        'methods' => ['sms', 'email'],
        'default_method' => 'email',
    ],

    // ============================================================
    // TWO-FACTOR AUTHENTICATION
    // ============================================================
    '2fa' => [
        'enabled' => true,
        'mandatory_roles' => ['SUPER_ADMIN', 'TENANT_ADMIN'],
        'backup_codes_count' => 10,
        'code_length' => 6,
    ],

    // ============================================================
    // LOGIN SECURITY
    // ============================================================
    'login' => [
        'max_attempts' => 5,
        'lockout_duration' => 900, // 15 minutes in seconds
        'rate_limit' => 60, // Max attempts per minute
        'remember_me_days' => 30,
        'redirect_after_login' => '/platform/dashboard/index.php',
        'redirect_after_logout' => '/platform/login.php',
    ],

    // ============================================================
    // ACCOUNT SECURITY
    // ============================================================
    'account' => [
        'require_email_verification' => true,
        'require_phone_verification' => false,
        'require_2fa_for_new_devices' => true,
        'notify_on_new_device' => true,
        'notify_on_password_change' => true,
        'notify_on_email_change' => true,
    ],

    // ============================================================
    // TENANT DETECTION
    // ============================================================
    'tenant' => [
        'detection_method' => 'subdomain',
        'default_tenant_id' => null,
        'fallback_url' => '/platform/tenants/select.php',
    ],
];
