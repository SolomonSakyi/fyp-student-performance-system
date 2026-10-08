<?php

/**
 * LevelNamingService.php
 * Service for managing school level naming conventions
 * 
 * @package EduTrack
 * @subpackage Services\School
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/helpers/DatabaseHelper.php';
require_once dirname(__DIR__, 2) . '/services/Tenant/TenantContext.php';
require_once dirname(__DIR__, 2) . '/services/Authorization/AuthorizationService.php';

class LevelNamingService
{
    private $db;
    private $context;
    private $auth;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->context = TenantContext::getInstance();
        $this->auth = new AuthorizationService();
    }

    /**
     * Get all levels for a school with display names
     */
    public function getSchoolLevels(int $schoolId): array
    {
        if (!$this->auth->canAccessSchool($schoolId)) {
            throw new Exception('Unauthorized access to school levels');
        }

        $sql = "SELECT 
                    sl.*,
                    ldn.display_name,
                    ldn.naming_convention,
                    ldn.prefix,
                    ldn.suffix,
                    ldn.custom_format,
                    ldn.example,
                    al.level_name as internal_level_name,
                    al.short_name as internal_short_name,
                    al.sort_order
                FROM school_levels sl
                JOIN academic_levels al ON sl.level_id = al.level_id
                LEFT JOIN level_display_names ldn ON sl.school_id = ldn.school_id AND sl.level_code = ldn.level_code
                WHERE sl.school_id = ? AND sl.deleted_at IS NULL
                ORDER BY al.sort_order ASC";

        return $this->db->fetchAll($sql, [$schoolId]);
    }

    /**
     * Get a specific level by ID
     */
    public function getLevel(int $levelId): ?array
    {
        $sql = "SELECT * FROM school_levels WHERE id = ? AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$levelId]);
    }

    /**
     * Add a level to a school
     */
    public function addLevel(int $schoolId, array $data): array
    {
        if (!$this->auth->canManageSchool($schoolId)) {
            throw new Exception('Unauthorized to manage school levels');
        }

        $errors = $this->validateLevelData($data);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $this->db->beginTransaction();

        try {
            // Insert school level
            $sql = "INSERT INTO school_levels 
                    (school_id, level_code, level_name, display_name, short_name, sequence, 
                     academic_stage, age_range_from, age_range_to, is_enabled, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $this->db->query($sql, [
                $schoolId,
                $data['level_code'],
                $data['level_name'],
                $data['display_name'] ?? $data['level_name'],
                $data['short_name'] ?? '',
                $data['sequence'] ?? 0,
                $data['academic_stage'] ?? '',
                $data['age_range_from'] ?? null,
                $data['age_range_to'] ?? null,
                $data['is_enabled'] ?? 1,
                $data['is_active'] ?? 1
            ]);

            $levelId = $this->db->lastInsertId();

            // Insert display name
            $sql = "INSERT INTO level_display_names 
                    (school_id, level_code, display_name, naming_convention, prefix, suffix, custom_format, example, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)";

            $this->db->query($sql, [
                $schoolId,
                $data['level_code'],
                $data['display_name'] ?? $data['level_name'],
                $data['naming_convention'] ?? 'basic',
                $data['prefix'] ?? '',
                $data['suffix'] ?? '',
                $data['custom_format'] ?? '',
                $data['example'] ?? ''
            ]);

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Level added successfully',
                'level_id' => $levelId
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update level display name
     */
    public function updateLevelDisplayName(int $schoolId, string $levelCode, array $data): array
    {
        if (!$this->auth->canManageSchool($schoolId)) {
            throw new Exception('Unauthorized to manage school levels');
        }

        $sql = "UPDATE level_display_names SET 
                    display_name = ?,
                    naming_convention = ?,
                    prefix = ?,
                    suffix = ?,
                    custom_format = ?,
                    example = ?,
                    is_active = ?,
                    updated_at = NOW()
                WHERE school_id = ? AND level_code = ? AND deleted_at IS NULL";

        $this->db->query($sql, [
            $data['display_name'],
            $data['naming_convention'] ?? 'basic',
            $data['prefix'] ?? '',
            $data['suffix'] ?? '',
            $data['custom_format'] ?? '',
            $data['example'] ?? '',
            $data['is_active'] ?? 1,
            $schoolId,
            $levelCode
        ]);

        return [
            'success' => true,
            'message' => 'Level display name updated successfully'
        ];
    }

    /**
     * Get level display name for a specific level
     */
    public function getLevelDisplayName(int $schoolId, string $levelCode): ?string
    {
        $sql = "SELECT display_name FROM level_display_names 
                WHERE school_id = ? AND level_code = ? AND is_active = 1 AND deleted_at IS NULL";

        $result = $this->db->fetchOne($sql, [$schoolId, $levelCode]);

        if ($result) {
            return $result['display_name'];
        }

        // Fallback: get from school_levels
        $sql = "SELECT display_name FROM school_levels 
                WHERE school_id = ? AND level_code = ? AND deleted_at IS NULL";

        $result = $this->db->fetchOne($sql, [$schoolId, $levelCode]);
        return $result['display_name'] ?? $levelCode;
    }

    /**
     * Remove a level from a school (soft delete)
     */
    public function removeLevel(int $schoolId, string $levelCode): array
    {
        if (!$this->auth->canManageSchool($schoolId)) {
            throw new Exception('Unauthorized to manage school levels');
        }

        // Check if level has any subjects assigned
        $subjects = $this->db->fetchAll(
            "SELECT COUNT(*) as count FROM subject_level_assignments sla
             JOIN school_levels sl ON sla.level_id = sl.id
             WHERE sl.school_id = ? AND sl.level_code = ? AND sla.deleted_at IS NULL",
            [$schoolId, $levelCode]
        );

        if (($subjects[0]['count'] ?? 0) > 0) {
            return [
                'success' => false,
                'message' => 'Cannot remove level with assigned subjects'
            ];
        }

        // Soft delete level
        $sql = "UPDATE school_levels SET deleted_at = NOW(), is_active = 0 
                WHERE school_id = ? AND level_code = ? AND deleted_at IS NULL";
        $this->db->query($sql, [$schoolId, $levelCode]);

        return [
            'success' => true,
            'message' => 'Level removed successfully'
        ];
    }

    /**
     * Validate level data
     */
    private function validateLevelData(array $data): array
    {
        $errors = [];

        if (empty($data['level_code'])) {
            $errors[] = 'Level code is required';
        }

        if (empty($data['level_name'])) {
            $errors[] = 'Level name is required';
        }

        if (isset($data['sequence']) && !is_numeric($data['sequence'])) {
            $errors[] = 'Sequence must be a number';
        }

        return $errors;
    }

    /**
     * Get available academic levels (system levels)
     */
    public function getAvailableLevels(): array
    {
        $sql = "SELECT * FROM academic_levels WHERE deleted_at IS NULL ORDER BY sort_order ASC";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get school level statistics
     */
    public function getLevelStats(int $schoolId): array
    {
        $stats = $this->db->fetchOne(
            "SELECT 
                COUNT(*) as total_levels,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_levels,
                SUM(CASE WHEN is_enabled = 1 THEN 1 ELSE 0 END) as enabled_levels
            FROM school_levels
            WHERE school_id = ? AND deleted_at IS NULL",
            [$schoolId]
        );

        return [
            'total' => (int)($stats['total_levels'] ?? 0),
            'active' => (int)($stats['active_levels'] ?? 0),
            'enabled' => (int)($stats['enabled_levels'] ?? 0)
        ];
    }
}