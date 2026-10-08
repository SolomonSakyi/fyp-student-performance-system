<?php

/**
 * SchoolConfigurationRepository.php
 * Data access layer for school configuration
 * 
 * @package EduTrack
 * @subpackage Repositories
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/helpers/DatabaseHelper.php';

class SchoolConfigurationRepository
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Get all configurations for a school
     */
    public function getSchoolConfigs(int $schoolId): array
    {
        $sql = "SELECT * FROM school_settings 
                WHERE school_id = ? AND deleted_at IS NULL";
        return $this->db->fetchAll($sql, [$schoolId]);
    }

    /**
     * Get a specific configuration by key
     */
    public function getConfigValue(int $schoolId, string $key): ?string
    {
        $sql = "SELECT setting_value FROM school_settings 
                WHERE school_id = ? AND setting_key = ? AND deleted_at IS NULL";
        $result = $this->db->fetchOne($sql, [$schoolId, $key]);
        return $result ? $result['setting_value'] : null;
    }

    /**
     * Set a configuration value
     */
    public function setConfigValue(int $schoolId, string $key, $value): bool
    {
        $value = is_array($value) ? json_encode($value) : $value;

        $sql = "INSERT INTO school_settings (school_id, setting_key, setting_value, setting_type, created_at)
                VALUES (?, ?, ?, 'string', NOW())
                ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()";

        return $this->db->query($sql, [$schoolId, $key, $value, $value]);
    }

    /**
     * Get school levels with display names
     */
    public function getSchoolLevels(int $schoolId): array
    {
        $sql = "SELECT 
                    sl.*,
                    ldn.display_name,
                    ldn.naming_convention,
                    ldn.prefix,
                    ldn.suffix,
                    ldn.custom_format,
                    ldn.example
                FROM school_levels sl
                LEFT JOIN level_display_names ldn ON sl.school_id = ldn.school_id AND sl.level_code = ldn.level_code
                WHERE sl.school_id = ? AND sl.deleted_at IS NULL
                ORDER BY sl.sequence ASC";

        return $this->db->fetchAll($sql, [$schoolId]);
    }

    /**
     * Get subjects with discipline and level assignments
     */
    public function getSchoolSubjectsWithLevels(int $schoolId): array
    {
        $sql = "SELECT 
                    ss.*,
                    sd.discipline_name,
                    GROUP_CONCAT(
                        CONCAT(sla.level_id, ':', sla.is_core, ':', sla.is_elective)
                        SEPARATOR '|'
                    ) as level_assignments
                FROM school_subjects ss
                LEFT JOIN school_disciplines sd ON ss.discipline_id = sd.id
                LEFT JOIN subject_level_assignments sla ON ss.id = sla.subject_id
                WHERE ss.school_id = ? AND ss.deleted_at IS NULL
                GROUP BY ss.id
                ORDER BY ss.display_order ASC";

        return $this->db->fetchAll($sql, [$schoolId]);
    }

    /**
     * Get assessment profile with components
     */
    public function getAssessmentProfileWithComponents(int $profileId): ?array
    {
        $sql = "SELECT * FROM assessment_profiles WHERE id = ? AND deleted_at IS NULL";
        $profile = $this->db->fetchOne($sql, [$profileId]);

        if (!$profile) {
            return null;
        }

        $sql = "SELECT 
                    pc.*,
                    ac.component_name,
                    ac.component_code,
                    ac.component_type
                FROM profile_components pc
                JOIN assessment_components ac ON pc.component_id = ac.id
                WHERE pc.profile_id = ? AND pc.deleted_at IS NULL
                ORDER BY pc.sequence ASC";

        $profile['components'] = $this->db->fetchAll($sql, [$profileId]);

        return $profile;
    }

    /**
     * Get grading system with grade scales
     */
    public function getGradingSystemWithScales(int $systemId): ?array
    {
        $sql = "SELECT * FROM grading_systems WHERE id = ? AND deleted_at IS NULL";
        $system = $this->db->fetchOne($sql, [$systemId]);

        if (!$system) {
            return null;
        }

        $sql = "SELECT * FROM grade_scales 
                WHERE grading_system_id = ? AND deleted_at IS NULL 
                ORDER BY sort_order ASC";

        $system['scales'] = $this->db->fetchAll($sql, [$systemId]);

        return $system;
    }

    /**
     * Check if configuration is complete
     */
    public function isConfigurationComplete(int $schoolId): bool
    {
        $checks = [
            'levels' => $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM school_levels WHERE school_id = ? AND deleted_at IS NULL",
                [$schoolId]
            ),
            'subjects' => $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM school_subjects WHERE school_id = ? AND deleted_at IS NULL",
                [$schoolId]
            ),
            'assessment_profiles' => $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM assessment_profiles WHERE school_id = ? AND deleted_at IS NULL",
                [$schoolId]
            ),
            'grading_systems' => $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM grading_systems WHERE school_id = ? AND deleted_at IS NULL",
                [$schoolId]
            ),
            'aggregation_rules' => $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM aggregation_rules WHERE school_id = ? AND deleted_at IS NULL",
                [$schoolId]
            )
        ];

        return ($checks['levels']['count'] ?? 0) > 0 &&
            ($checks['subjects']['count'] ?? 0) > 0 &&
            ($checks['assessment_profiles']['count'] ?? 0) > 0 &&
            ($checks['grading_systems']['count'] ?? 0) > 0 &&
            ($checks['aggregation_rules']['count'] ?? 0) > 0;
    }
}
