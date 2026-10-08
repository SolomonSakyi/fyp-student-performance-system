<?php

/**
 * Application Configuration
 *
 * @package EduTrack
 * @subpackage Config
 * @version 1.0
 * @filepath config/app.php
 */

return [
    // Application Settings
    'app_name' => 'EduTrack Enterprise',
    'app_env' => $_ENV['APP_ENV'] ?? 'production',
    'app_debug' => $_ENV['APP_DEBUG'] ?? false,
    'app_url' => $_ENV['APP_URL'] ?? 'https://admin.edutrack.com',

    // Platform Domain
    'platform_domain' => $_ENV['PLATFORM_DOMAIN'] ?? 'admin.edutrack.com',

    // Database
    'database' => [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'name' => $_ENV['DB_NAME'] ?? 'edutrack',
        'user' => $_ENV['DB_USER'] ?? 'root',
        'pass' => $_ENV['DB_PASS'] ?? '',
        'port' => $_ENV['DB_PORT'] ?? 3306,
        'charset' => 'utf8mb4',
    ],

    // Security
    'security' => [
        'session_lifetime' => (int)($_ENV['SESSION_LIFETIME'] ?? 86400),
        'idle_timeout' => (int)($_ENV['IDLE_TIMEOUT'] ?? 1800),
        'rate_limit_login' => (int)($_ENV['RATE_LIMIT_LOGIN'] ?? 5),
        'rate_limit_window' => (int)($_ENV['RATE_LIMIT_WINDOW'] ?? 900),
        'csrf_token_lifetime' => 3600,
        'password_min_length' => 8,
        'password_require_uppercase' => true,
        'password_require_lowercase' => true,
        'password_require_number' => true,
    ],

    // Email
    'mail' => [
        'driver' => $_ENV['MAIL_DRIVER'] ?? 'smtp',
        'host' => $_ENV['MAIL_HOST'] ?? 'smtp.gmail.com',
        'port' => (int)($_ENV['MAIL_PORT'] ?? 587),
        'username' => $_ENV['MAIL_USERNAME'] ?? '',
        'password' => $_ENV['MAIL_PASSWORD'] ?? '',
        'encryption' => $_ENV['MAIL_ENCRYPTION'] ?? 'tls',
        'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@edutrack.com',
        'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'EduTrack Enterprise',
    ],
];
