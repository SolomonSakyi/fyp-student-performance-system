<?php

/**
 * Database Configuration File
 * Load environment variables and return database connection settings
 */

// Load .env file manually (in production, use a library like vlucas/phpdotenv)
$env = parse_ini_file(__DIR__ . '/../../.env');

return [
    'driver' => 'mysql',
    'host' => $env['DB_HOST'] ?? 'localhost',
    'port' => $env['DB_PORT'] ?? '3306',
    'database' => $env['DB_DATABASE'] ?? 'student_performance_system',
    'username' => $env['DB_USERNAME'] ?? 'root',
    'password' => $env['DB_PASSWORD'] ?? '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
];