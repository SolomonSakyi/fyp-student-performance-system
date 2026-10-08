<?php

/**
 * SchoolLevel.php
 * School Level Model - Junction between schools and institution levels
 * 
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class SchoolLevel extends BaseModel
{
    protected $table = 'school_levels';

    protected $fillable = [
        'school_id',
        'level_id',
        'is_active'
    ];

    /**
     * Get levels by school
     */
    public function getLevelsBySchool(int $schoolId): array
    {
        $sql = "SELECT l.*, sl.is_active, sl.school_level_id 
                FROM institution_levels l
                JOIN school_levels sl ON l.level_id = sl.level_id
                WHERE sl.school_id = ? AND sl.is_active = 1
                ORDER BY l.sort_order ASC";
        return $this->rawFetch($sql, [$schoolId]);
    }

    /**
     * Get all levels (including inactive) for a school
     */
    public function getAllLevelsBySchool(int $schoolId): array
    {
        $sql = "SELECT l.*, sl.is_active, sl.school_level_id 
                FROM institution_levels l
                JOIN school_levels sl ON l.level_id = sl.level_id
                WHERE sl.school_id = ?
                ORDER BY l.sort_order ASC";
        return $this->rawFetch($sql, [$schoolId]);
    }

    /**
     * Check if school has a specific level
     */
    public function schoolHasLevel(int $schoolId, int $levelId): bool
    {
        $sql = "SELECT 1 FROM school_levels 
                WHERE school_id = ? AND level_id = ? AND is_active = 1";
        $result = $this->db->fetchOne($sql, [$schoolId, $levelId]);
        return (bool)$result;
    }

    /**
     * Assign level to school
     */
    public function assignLevel(int $schoolId, int $levelId): bool
    {
        // Check if already exists
        $sql = "SELECT 1 FROM school_levels WHERE school_id = ? AND level_id = ?";
        $result = $this->db->fetchOne($sql, [$schoolId, $levelId]);

        if ($result) {
            // Update to active
            $sql = "UPDATE school_levels SET is_active = 1, updated_at = NOW() 
                    WHERE school_id = ? AND level_id = ?";
            return $this->db->query($sql, [$schoolId, $levelId]);
        }

        $sql = "INSERT INTO school_levels (school_id, level_id, is_active) VALUES (?, ?, 1)";
        return $this->db->query($sql, [$schoolId, $levelId]);
    }

    /**
     * Remove level from school
     */
    public function removeLevel(int $schoolId, int $levelId): bool
    {
        // Soft delete - set inactive
        $sql = "UPDATE school_levels SET is_active = 0, updated_at = NOW() 
                WHERE school_id = ? AND level_id = ?";
        return $this->db->query($sql, [$schoolId, $levelId]);
    }

    /**
     * Bulk assign levels to school
     */
    public function bulkAssignLevels(int $schoolId, array $levelIds): bool
    {
        try {
            $this->db->beginTransaction();

            // First, deactivate all levels for this school
            $sql = "UPDATE school_levels SET is_active = 0, updated_at = NOW() 
                    WHERE school_id = ?";
            $this->db->query($sql, [$schoolId]);

            // Then activate selected levels
            foreach ($levelIds as $levelId) {
                // Check if exists
                $sql = "SELECT 1 FROM school_levels WHERE school_id = ? AND level_id = ?";
                $result = $this->db->fetchOne($sql, [$schoolId, $levelId]);

                if ($result) {
                    // Update to active
                    $sql = "UPDATE school_levels SET is_active = 1, updated_at = NOW() 
                            WHERE school_id = ? AND level_id = ?";
                    $this->db->query($sql, [$schoolId, $levelId]);
                } else {
                    // Insert new
                    $sql = "INSERT INTO school_levels (school_id, level_id, is_active) VALUES (?, ?, 1)";
                    $this->db->query($sql, [$schoolId, $levelId]);
                }
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Get active level count for a school
     */
    public function getLevelCount(int $schoolId): int
    {
        $sql = "SELECT COUNT(*) as count FROM school_levels 
                WHERE school_id = ? AND is_active = 1";
        $result = $this->db->fetchOne($sql, [$schoolId]);
        return (int)($result['count'] ?? 0);
    }
}
