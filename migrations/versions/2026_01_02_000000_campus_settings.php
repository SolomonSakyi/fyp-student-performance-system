<?php

/**
 * Migration: campus_settings table
 * 
 * @package EduTrack
 * @subpackage Migrations
 * @version 2.0
 */

// The class name MUST match: Migration + version (without underscores)
class Migration20260102000000 implements MigrationInterface
{
    public function up($db)
    {
        // Check if campus_settings table already exists
        $exists = $db->getValue("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'campus_settings'");

        if ($exists > 0) {
            echo "✅ campus_settings table already exists, skipping creation\n";
        } else {
            $sql = "
            CREATE TABLE IF NOT EXISTS campus_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                campus_id INT NOT NULL UNIQUE,
                campus_phone VARCHAR(20),
                campus_email VARCHAR(100),
                campus_website VARCHAR(255),
                principal_name VARCHAR(100),
                vice_principal_name VARCHAR(100),
                school_hours_start TIME,
                school_hours_end TIME,
                timezone VARCHAR(50) DEFAULT 'Africa/Accra',
                language VARCHAR(20) DEFAULT 'en',
                currency VARCHAR(10) DEFAULT 'GHS',
                academic_year_start DATE,
                academic_year_end DATE,
                term_system ENUM('Term', 'Semester', 'Quarter') DEFAULT 'Term',
                terms_per_year INT DEFAULT 3,
                max_students_per_class INT DEFAULT 40,
                use_guardian_portal TINYINT(1) DEFAULT 1,
                enable_online_registration TINYINT(1) DEFAULT 1,
                enable_parent_app TINYINT(1) DEFAULT 1,
                google_maps_api_key VARCHAR(255),
                gps_coordinates VARCHAR(100),
                logo_url VARCHAR(255),
                favicon_url VARCHAR(255),
                header_color VARCHAR(7) DEFAULT '#1a1a2e',
                footer_color VARCHAR(7) DEFAULT '#1a1a2e',
                accent_color VARCHAR(7) DEFAULT '#4facfe',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ";
            $db->execute($sql);
            echo "✅ campus_settings table created\n";
        }
    }

    public function down($db)
    {
        // Don't drop the table if it has data
        echo "⚠️ Skipping rollback to prevent data loss\n";
    }
}

// Migration interface
interface MigrationInterface
{
    public function up($db);
    public function down($db);
}
