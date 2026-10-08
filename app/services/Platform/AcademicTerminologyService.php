<?php

/**
 * AcademicTerminologyService.php
 * Central service for resolving school-specific academic terminology
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/helpers/DatabaseHelper.php';

class AcademicTerminologyService
{
    private $db;
    private $cache = [];

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Get school's effective terminology profile
     */
    public function getSchoolTerminology(int $schoolId): array
    {
        $cacheKey = "school_terminology_{$schoolId}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // Get the active/default terminology profile
        $sql = "SELECT * FROM school_academic_profiles 
                WHERE school_id = ? AND is_active = 1 AND is_default = 1 
                LIMIT 1";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$schoolId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$profile) {
            // Create default terminology profile
            $profile = $this->createDefaultTerminologyProfile($schoolId);
        }

        $this->cache[$cacheKey] = $profile;
        return $profile;
    }

    /**
     * Get stage display name
     */
    public function getStageDisplayName(int $schoolId, string $stageCode): string
    {
        $terminology = $this->getSchoolTerminology($schoolId);
        $stageMapping = $this->getSchoolStageMapping($schoolId);

        if (isset($stageMapping[$stageCode])) {
            return $stageMapping[$stageCode]['display_name'] ?? $this->getDefaultStageName($stageCode);
        }

        return $this->getDefaultStageName($stageCode);
    }

    /**
     * Get level display name
     */
    public function getLevelDisplayName(int $schoolId, string $levelCode): string
    {
        $terminology = $this->getSchoolTerminology($schoolId);
        $levelMapping = $this->getSchoolLevelMapping($schoolId);

        if (isset($levelMapping[$levelCode])) {
            return $levelMapping[$levelCode]['display_name'] ?? $this->getDefaultLevelName($levelCode);
        }

        return $this->getDefaultLevelName($levelCode);
    }

    /**
     * Get level short name
     */
    public function getLevelShortName(int $schoolId, string $levelCode): string
    {
        $levelMapping = $this->getSchoolLevelMapping($schoolId);

        if (isset($levelMapping[$levelCode])) {
            return $levelMapping[$levelCode]['short_name'] ?? $this->getDefaultLevelShortName($levelCode);
        }

        return $this->getDefaultLevelShortName($levelCode);
    }

    /**
     * Get full academic hierarchy for a school
     */
    public function getAcademicHierarchy(int $schoolId): array
    {
        $sql = "SELECT 
                    s.stage_code,
                    s.stage_name,
                    l.level_code,
                    l.level_name as canonical_name,
                    sl.display_name,
                    sl.short_name,
                    sl.sort_order,
                    sl.is_active
                FROM academic_stages s
                JOIN academic_levels l ON s.stage_id = l.stage_id
                LEFT JOIN school_academic_levels sl ON l.level_id = sl.level_id AND sl.school_id = ?
                WHERE l.is_active = 1
                ORDER BY s.sort_order, l.sort_order";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$schoolId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update school terminology
     */
    public function updateSchoolTerminology(int $schoolId, string $terminologyType): array
    {
        $allowedTypes = ['basic', 'class', 'grade', 'custom'];
        if (!in_array($terminologyType, $allowedTypes)) {
            return ['success' => false, 'message' => 'Invalid terminology type'];
        }

        // Update or create profile
        $sql = "UPDATE school_academic_profiles 
                SET terminology_type = ?, updated_at = NOW() 
                WHERE school_id = ? AND is_default = 1";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$terminologyType, $schoolId]);

        // Clear cache
        unset($this->cache["school_terminology_{$schoolId}"]);

        return ['success' => true, 'message' => 'Terminology updated successfully'];
    }

    /**
     * Get effective terminology for school
     */
    public function getEffectiveTerminology(int $schoolId, ?int $campusId = null): array
    {
        $terminology = $this->getSchoolTerminology($schoolId);

        // If campus has override, use it
        if ($campusId) {
            $campusTerminology = $this->getCampusTerminology($campusId);
            if ($campusTerminology) {
                return $campusTerminology;
            }
        }

        return $terminology;
    }

    /**
     * Get school stage mapping
     */
    private function getSchoolStageMapping(int $schoolId): array
    {
        $cacheKey = "school_stage_mapping_{$schoolId}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $sql = "SELECT 
                    s.stage_code,
                    sl.display_name as stage_display_name
                FROM school_academic_levels sl
                JOIN academic_levels l ON sl.level_id = l.level_id
                JOIN academic_stages s ON l.stage_id = s.stage_id
                WHERE sl.school_id = ?
                GROUP BY s.stage_code";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$schoolId]);
        $result = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->cache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Get school level mapping
     */
    private function getSchoolLevelMapping(int $schoolId): array
    {
        $cacheKey = "school_level_mapping_{$schoolId}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $sql = "SELECT 
                    l.level_code,
                    sl.display_name,
                    sl.short_name
                FROM school_academic_levels sl
                JOIN academic_levels l ON sl.level_id = l.level_id
                WHERE sl.school_id = ?";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$schoolId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $mapping = [];
        foreach ($results as $row) {
            $mapping[$row['level_code']] = [
                'display_name' => $row['display_name'],
                'short_name' => $row['short_name']
            ];
        }

        $this->cache[$cacheKey] = $mapping;
        return $mapping;
    }

    /**
     * Create default terminology profile
     */
    private function createDefaultTerminologyProfile(int $schoolId): array
    {
        $uuid = $this->generateUUID();

        $sql = "INSERT INTO school_academic_profiles (
                    uuid, school_id, profile_name, profile_code, 
                    terminology_type, is_active, is_default, created_at
                ) VALUES (?, ?, 'Default Terminology', 'DEFAULT-TERM', 'basic', 1, 1, NOW())";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$uuid, $schoolId]);
        $profileId = $this->db->getConnection()->lastInsertId();

        // Map all canonical levels with default display names
        $sql = "INSERT INTO school_academic_levels (
                    uuid, school_id, level_id, profile_id, display_name, 
                    short_name, is_active, sort_order, created_at
                )
                SELECT 
                    UUID(), ?, level_id, ?, level_name, short_name, 1, sort_order, NOW()
                FROM academic_levels";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$schoolId, $profileId]);

        // Return the created profile
        return $this->getSchoolTerminology($schoolId);
    }

    /**
     * Get default stage name
     */
    private function getDefaultStageName(string $stageCode): string
    {
        $stages = [
            'CRECHE' => 'Creche',
            'NURSERY' => 'Nursery',
            'KINDERGARTEN' => 'Kindergarten',
            'PRIMARY_LEVEL' => 'Primary Level',
            'JHS' => 'J.H.S.'
        ];
        return $stages[$stageCode] ?? $stageCode;
    }

    /**
     * Get default level name
     */
    private function getDefaultLevelName(string $levelCode): string
    {
        $sql = "SELECT level_name FROM academic_levels WHERE level_code = ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$levelCode]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['level_name'] ?? $levelCode;
    }

    /**
     * Get default level short name
     */
    private function getDefaultLevelShortName(string $levelCode): string
    {
        $sql = "SELECT short_name FROM academic_levels WHERE level_code = ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$levelCode]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['short_name'] ?? substr($levelCode, 0, 3);
    }

    /**
     * Get campus terminology override
     */
    private function getCampusTerminology(int $campusId): ?array
    {
        // Implement campus-specific terminology override
        return null;
    }

    /**
     * Generate UUID
     */
    private function generateUUID(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
