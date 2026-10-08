<?php

/**
 * Database Migration System - EduTrack
 * 
 * This system manages database schema versions and migrations.
 *
 * @package EduTrack
 * @subpackage Migrations
 * @filepath migrations/migration.php
 * @version 2.0
 */

// Define project root path
define('PROJECT_ROOT', dirname(__DIR__));
define('MIGRATIONS_PATH', __DIR__ . '/versions');

// Load configuration
require_once PROJECT_ROOT . '/config/config.php';
require_once PROJECT_ROOT . '/app/helpers/DatabaseHelper.php';

class DatabaseMigration
{
    private $db;
    private $migrationTable = 'migrations';
    private $migrationPath;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->migrationPath = MIGRATIONS_PATH;
        $this->ensureMigrationTable();
    }

    private function ensureMigrationTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->migrationTable}` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `version` VARCHAR(50) NOT NULL UNIQUE,
            `description` VARCHAR(255) NOT NULL,
            `executed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `batch` INT NOT NULL,
            INDEX idx_version (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        try {
            $this->db->execute($sql);
        } catch (Exception $e) {
            // Table might already exist
        }
    }

    public function getCurrentVersion()
    {
        $result = $this->db->fetchOne(
            "SELECT version FROM `{$this->migrationTable}` ORDER BY id DESC LIMIT 1"
        );
        return $result ? $result['version'] : '0000_00_00_000000_initial';
    }

    public function getExecutedMigrations()
    {
        return $this->db->fetchAll(
            "SELECT version, description, executed_at, batch FROM `{$this->migrationTable}` ORDER BY id ASC"
        );
    }

    public function getMigrationFiles()
    {
        if (!is_dir($this->migrationPath)) {
            mkdir($this->migrationPath, 0777, true);
            return [];
        }

        $files = glob($this->migrationPath . '/*.php');
        $migrations = [];

        foreach ($files as $file) {
            $filename = basename($file);
            // Format: 2024_01_01_000000_description.php
            if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_(.+)\.php$/', $filename, $matches)) {
                $migrations[] = [
                    'version' => $matches[1],
                    'description' => str_replace('_', ' ', $matches[2]),
                    'file' => $filename,
                    'path' => $file
                ];
            }
        }

        sort($migrations);
        return $migrations;
    }

    public function getPendingMigrations()
    {
        $all = $this->getMigrationFiles();
        $executed = $this->getExecutedMigrations();
        $executedVersions = array_column($executed, 'version');

        $pending = [];
        foreach ($all as $migration) {
            if (!in_array($migration['version'], $executedVersions)) {
                $pending[] = $migration;
            }
        }

        return $pending;
    }

    public function runMigration($migrationFile, $description)
    {
        try {
            $maxBatch = $this->db->getValue("SELECT MAX(batch) FROM `{$this->migrationTable}`");
            $batch = ($maxBatch ?? 0) + 1;

            // Extract version from filename
            $filename = basename($migrationFile);
            preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $filename, $matches);
            $version = $matches[1] ?? pathinfo($migrationFile, PATHINFO_FILENAME);

            // Include the migration file
            require_once $this->migrationPath . '/' . $migrationFile;

            // Build class name from version
            $cleanVersion = str_replace('_', '', $version);
            $className = 'Migration' . $cleanVersion;

            if (class_exists($className)) {
                $migration = new $className();
                $migration->up($this->db);
            } else {
                throw new Exception("Migration class not found: " . $className . " (from file: " . $migrationFile . ")");
            }

            $this->db->insert(
                "INSERT INTO `{$this->migrationTable}` (version, description, batch) VALUES (?, ?, ?)",
                [$version, $description, $batch]
            );

            return ['success' => true, 'message' => "Migration {$version} executed successfully"];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function migrate()
    {
        $pending = $this->getPendingMigrations();
        $results = [];

        if (empty($pending)) {
            return ['success' => true, 'message' => 'No pending migrations', 'results' => []];
        }

        $this->db->beginTransaction();

        try {
            foreach ($pending as $migration) {
                echo "Running: " . $migration['version'] . " - " . $migration['description'] . "\n";
                $result = $this->runMigration($migration['file'], $migration['description']);
                $results[] = $result;

                if (!$result['success']) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'Migration failed: ' . $result['message'], 'results' => $results];
                }
            }

            $this->db->commit();
            return ['success' => true, 'message' => count($pending) . ' migrations executed successfully', 'results' => $results];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Migration failed: ' . $e->getMessage(), 'results' => $results];
        }
    }

    public function rollback()
    {
        $maxBatch = $this->db->getValue("SELECT MAX(batch) FROM `{$this->migrationTable}`");

        if (!$maxBatch) {
            return ['success' => false, 'message' => 'No migrations to rollback'];
        }

        $migrations = $this->db->fetchAll(
            "SELECT version, description FROM `{$this->migrationTable}` WHERE batch = ? ORDER BY id DESC",
            [$maxBatch]
        );

        if (empty($migrations)) {
            return ['success' => false, 'message' => 'No migrations to rollback'];
        }

        $this->db->beginTransaction();

        try {
            foreach ($migrations as $migration) {
                $migrationFile = glob($this->migrationPath . '/' . $migration['version'] . '_*.php');
                if (!empty($migrationFile)) {
                    require_once $migrationFile[0];
                    $cleanVersion = str_replace('_', '', $migration['version']);
                    $className = 'Migration' . $cleanVersion;
                    if (class_exists($className)) {
                        $migrationObj = new $className();
                        $migrationObj->down($this->db);
                    }
                }

                $this->db->execute(
                    "DELETE FROM `{$this->migrationTable}` WHERE version = ?",
                    [$migration['version']]
                );
            }

            $this->db->commit();
            return ['success' => true, 'message' => count($migrations) . ' migrations rolled back'];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Rollback failed: ' . $e->getMessage()];
        }
    }

    public function createMigration($description)
    {
        if (!is_dir($this->migrationPath)) {
            mkdir($this->migrationPath, 0777, true);
        }

        $timestamp = date('Y_m_d_His');
        $version = $timestamp;
        $filename = $version . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($description)) . '.php';
        $filepath = $this->migrationPath . '/' . $filename;

        $template = <<<PHP
<?php
/**
 * Migration: {$description}
 * 
 * @package EduTrack
 * @subpackage Migrations
 * @version 2.0
 */

class Migration{$timestamp} implements MigrationInterface
{
    public function up(\$db)
    {
        // Add your migration code here
        // Example: \$db->execute("ALTER TABLE your_table ADD COLUMN new_column VARCHAR(100)");
    }
    
    public function down(\$db)
    {
        // Add rollback code here
        // Example: \$db->execute("ALTER TABLE your_table DROP COLUMN new_column");
    }
}

interface MigrationInterface
{
    public function up(\$db);
    public function down(\$db);
}
PHP;

        file_put_contents($filepath, $template);

        return [
            'success' => true,
            'file' => $filename,
            'path' => $filepath,
            'version' => $version
        ];
    }
}
