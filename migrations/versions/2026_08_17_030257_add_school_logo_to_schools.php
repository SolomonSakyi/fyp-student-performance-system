<?php
/**
 * Migration: add_school_logo_to_schools
 * 
 * @package EduTrack
 * @subpackage Migrations
 * @version 2.0
 */

class Migration20260817030257 implements MigrationInterface
{
    public function up($db)
    {
        // Check if column exists
        $columnExists = $db->getValue("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'schools' AND column_name = 'school_logo'");
        
        if ($columnExists == 0) {
            $db->execute("ALTER TABLE schools ADD COLUMN school_logo VARCHAR(255) NULL");
            echo "✅ Added school_logo column to schools table\n";
        } else {
            echo "ℹ️ school_logo column already exists\n";
        }
    }
    
    public function down($db)
    {
        $db->execute("ALTER TABLE schools DROP COLUMN school_logo");
        echo "✅ Removed school_logo column from schools table\n";
    }
}

// MigrationInterface is already defined in the migration system
// Do not redeclare it here