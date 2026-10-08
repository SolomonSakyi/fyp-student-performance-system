<?php

/**
 * Migration Runner - CLI Interface
 * 
 * Usage:
 *   php migrate.php                    - Run all pending migrations
 *   php migrate.php --create "Name"   - Create a new migration
 *   php migrate.php --rollback         - Rollback last batch
 *   php migrate.php --status           - Show migration status
 *   php migrate.php --all             - Show all migrations
 *
 * @package EduTrack
 * @filepath migrate.php
 * @version 2.0
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include migration system
require_once __DIR__ . '/migrations/migration.php';

$migration = new DatabaseMigration();

// Parse command line arguments
$args = array_slice($argv, 1);
$command = $args[0] ?? 'migrate';

switch ($command) {
    case '--create':
    case '-c':
        if (empty($args[1])) {
            echo "Error: Please provide a migration description\n";
            echo "Usage: php migrate.php --create \"Add users table\"\n";
            exit(1);
        }
        $result = $migration->createMigration($args[1]);
        if ($result['success']) {
            echo "Migration created successfully!\n";
            echo "   File: " . $result['file'] . "\n";
            echo "   Path: " . $result['path'] . "\n";
            echo "   Version: " . $result['version'] . "\n";
        } else {
            echo "Failed to create migration: " . $result['message'] . "\n";
        }
        break;

    case '--rollback':
    case '-r':
        echo "Rolling back last batch...\n";
        $result = $migration->rollback();
        if ($result['success']) {
            echo $result['message'] . "\n";
        } else {
            echo $result['message'] . "\n";
        }
        break;

    case '--status':
    case '-s':
        showStatus($migration);
        break;

    case '--all':
    case '-a':
        showAllMigrations($migration);
        break;

    case 'migrate':
    default:
        echo "Running migrations...\n";
        $result = $migration->migrate();
        if ($result['success']) {
            echo $result['message'] . "\n";
            if (!empty($result['results'])) {
                foreach ($result['results'] as $r) {
                    echo "   - " . $r['message'] . "\n";
                }
            }
        } else {
            echo $result['message'] . "\n";
        }
        break;
}

function showStatus($migration)
{
    $executed = $migration->getExecutedMigrations();
    $pending = $migration->getPendingMigrations();

    echo "\n========================================\n";
    echo "       MIGRATION STATUS\n";
    echo "========================================\n";

    echo "\nExecuted Migrations (" . count($executed) . "):\n";
    if (empty($executed)) {
        echo "   No migrations executed yet\n";
    } else {
        foreach ($executed as $m) {
            echo "   [DONE] " . $m['version'] . " - " . $m['description'] . " (Batch: " . $m['batch'] . ")\n";
        }
    }

    echo "\nPending Migrations (" . count($pending) . "):\n";
    if (empty($pending)) {
        echo "   Database is up to date!\n";
    } else {
        foreach ($pending as $m) {
            echo "   [PENDING] " . $m['version'] . " - " . $m['description'] . "\n";
        }
    }

    echo "\n========================================\n";
}

function showAllMigrations($migration)
{
    $files = $migration->getMigrationFiles();
    $executed = $migration->getExecutedMigrations();
    $executedVersions = array_column($executed, 'version');

    echo "\n========================================\n";
    echo "       ALL MIGRATION FILES\n";
    echo "========================================\n";

    if (empty($files)) {
        echo "   No migration files found.\n";
    } else {
        foreach ($files as $file) {
            $status = in_array($file['version'], $executedVersions) ? '[DONE]' : '[PENDING]';
            echo "   " . $status . " " . $file['version'] . " - " . $file['description'] . "\n";
        }
    }

    echo "\n========================================\n";
}
