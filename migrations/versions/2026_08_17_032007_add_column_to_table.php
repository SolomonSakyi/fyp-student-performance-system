<?php
/**
 * Migration: Add_column_to_table
 * 
 * @package EduTrack
 * @subpackage Migrations
 * @version 2.0
 */

class Migration2026_08_17_032007 implements MigrationInterface
{
    public function up($db)
    {
        // Add your migration code here
        // Example: $db->execute("ALTER TABLE your_table ADD COLUMN new_column VARCHAR(100)");
    }
    
    public function down($db)
    {
        // Add rollback code here
        // Example: $db->execute("ALTER TABLE your_table DROP COLUMN new_column");
    }
}

interface MigrationInterface
{
    public function up($db);
    public function down($db);
}