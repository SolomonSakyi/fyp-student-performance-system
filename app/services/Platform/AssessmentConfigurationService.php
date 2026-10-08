<?php

/**
 * AssessmentConfigurationService.php
 * Central service for assessment configuration management
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/helpers/DatabaseHelper.php';
require_once __DIR__ . '/AcademicTerminologyService.php';

class AssessmentConfigurationService
{
    private $db;
    private $terminologyService;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->terminologyService = new AcademicTerminologyService();
    }

    /**
     * Get effective assessment configuration for a school/level
     */
    public function getEffectiveConfiguration(int $schoolId, string $levelCode): array
    {
        // Get the academic level ID
        $levelId = $this->getLevelIdByCode($levelCode);
        if (!$levelId) {
            return ['error' => 'Invalid level code'];
        }

        // Find the assessment profile for this level
        $sql = "SELECT p.*, v.config_data 
                FROM assessment_profiles p
                LEFT JOIN assessment_config_versions v 
                    ON p.profile_id = v.profile_id AND v.is_published = 1
                WHERE p.school_id = ? 
                AND (p.applicable_levels LIKE ? OR p.applicable_levels IS NULL)
                AND p.status = 'active'
                ORDER BY p.is_default DESC
                LIMIT 1";

        $searchPattern = '%' . $levelCode . '%';
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$schoolId, $searchPattern]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$profile) {
            // Return default configuration
            return $this->getDefaultConfiguration($schoolId);
        }

        // Load components
        $components = $this->getProfileComponents($profile['profile_id']);

        // Load grading system
        $gradingSystem = $this->getGradingSystem($profile['grading_system_id']);

        // Load aggregation rule
        $aggregationRule = $this->getAggregationRule($profile['aggregation_rule_id']);

        return [
            'profile' => $profile,
            'components' => $components,
            'grading_system' => $gradingSystem,
            'aggregation_rule' => $aggregationRule
        ];
    }

    /**
     * Get default configuration
     */
    public function getDefaultConfiguration(int $schoolId): array
    {
        return [
            'profile' => [
                'profile_id' => 0,
                'profile_name' => 'Default Configuration',
                'assessment_model' => '30_70',
                'total_weight' => 100
            ],
            'components' => [
                ['component_name' => 'Class Assessment', 'weight' => 30],
                ['component_name' => 'Examination', 'weight' => 70]
            ],
            'grading_system' => $this->getDefaultGradingSystem($schoolId),
            'aggregation_rule' => null
        ];
    }

    /**
     * Get profile components
     */
    private function getProfileComponents(int $profileId): array
    {
        $sql = "SELECT pc.*, c.component_name, c.component_code, c.max_score 
                FROM assessment_profile_components pc
                JOIN assessment_components c ON pc.component_id = c.component_id
                WHERE pc.profile_id = ?
                ORDER BY pc.sort_order ASC";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$profileId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get grading system
     */
    private function getGradingSystem(?int $systemId): ?array
    {
        if (!$systemId) {
            return null;
        }

        $sql = "SELECT * FROM grading_systems WHERE grading_system_id = ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$systemId]);
        $system = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($system) {
            $system['scales'] = $this->getGradeScales($systemId);
        }

        return $system;
    }

    /**
     * Get grade scales
     */
    private function getGradeScales(int $systemId): array
    {
        $sql = "SELECT * FROM grade_scales WHERE grading_system_id = ? ORDER BY sort_order ASC";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$systemId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get default grading system
     */
    private function getDefaultGradingSystem(int $schoolId): ?array
    {
        $sql = "SELECT * FROM grading_systems 
                WHERE school_id = ? AND is_default = 1 
                LIMIT 1";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$schoolId]);
        $system = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($system) {
            $system['scales'] = $this->getGradeScales($system['grading_system_id']);
        }

        return $system;
    }

    /**
     * Get aggregation rule
     */
    private function getAggregationRule(?int $ruleId): ?array
    {
        if (!$ruleId) {
            return null;
        }

        $sql = "SELECT * FROM aggregation_rules WHERE aggregation_rule_id = ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$ruleId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get level ID by code
     */
    private function getLevelIdByCode(string $levelCode): ?int
    {
        $sql = "SELECT level_id FROM academic_levels WHERE level_code = ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$levelCode]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['level_id'] ?? null;
    }

    /**
     * Create assessment profile
     */
    public function createProfile(array $data): array
    {
        $uuid = $this->generateUUID();

        $sql = "INSERT INTO assessment_profiles (
                    uuid, school_id, profile_name, profile_code, description,
                    applicable_stages, applicable_levels, assessment_model,
                    total_weight, grading_system_id, aggregation_rule_id,
                    ranking_rule_id, pass_rule_id, remark_rule_id,
                    is_active, is_locked, is_default, status,
                    created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            $uuid,
            $data['school_id'],
            $data['profile_name'],
            $data['profile_code'],
            $data['description'] ?? null,
            $data['applicable_stages'] ?? null,
            $data['applicable_levels'] ?? null,
            $data['assessment_model'] ?? '30_70',
            $data['total_weight'] ?? 100,
            $data['grading_system_id'] ?? null,
            $data['aggregation_rule_id'] ?? null,
            $data['ranking_rule_id'] ?? null,
            $data['pass_rule_id'] ?? null,
            $data['remark_rule_id'] ?? null,
            $data['is_active'] ?? 1,
            0,
            $data['is_default'] ?? 0,
            $data['status'] ?? 'draft',
            $data['created_by'] ?? null
        ]);

        $profileId = $this->db->getConnection()->lastInsertId();

        return ['success' => true, 'profile_id' => $profileId];
    }

    /**
     * Update assessment profile
     */
    public function updateProfile(int $profileId, array $data): array
    {
        $allowedFields = [
            'profile_name',
            'profile_code',
            'description',
            'applicable_stages',
            'applicable_levels',
            'assessment_model',
            'total_weight',
            'grading_system_id',
            'aggregation_rule_id',
            'ranking_rule_id',
            'pass_rule_id',
            'remark_rule_id',
            'is_active',
            'is_locked',
            'is_default',
            'status'
        ];

        $sets = [];
        $params = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $sets[] = "{$key} = ?";
                $params[] = $value;
            }
        }

        if (empty($sets)) {
            return ['success' => false, 'message' => 'No fields to update'];
        }

        $params[] = $profileId;
        $sets[] = "updated_at = NOW()";

        $sql = "UPDATE assessment_profiles SET " . implode(', ', $sets) . " WHERE profile_id = ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);

        return ['success' => true];
    }

    /**
     * Create configuration version
     */
    public function createVersion(int $profileId, array $data): array
    {
        $versionNumber = $this->getNextVersionNumber($profileId);
        $uuid = $this->generateUUID();

        $sql = "INSERT INTO assessment_config_versions (
                    uuid, profile_id, version_number, version_name,
                    description, config_data, is_published, is_locked,
                    created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            $uuid,
            $profileId,
            $versionNumber,
            $data['version_name'] ?? "Version {$versionNumber}",
            $data['description'] ?? null,
            $data['config_data'] ?? null,
            0,
            0,
            $data['created_by'] ?? null
        ]);

        $versionId = $this->db->getConnection()->lastInsertId();

        return ['success' => true, 'version_id' => $versionId, 'version_number' => $versionNumber];
    }

    /**
     * Publish configuration version
     */
    public function publishVersion(int $versionId, int $userId): array
    {
        $sql = "UPDATE assessment_config_versions 
                SET is_published = 1, published_by = ?, published_at = NOW()
                WHERE version_id = ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$userId, $versionId]);

        return ['success' => true, 'message' => 'Version published successfully'];
    }

    /**
     * Lock configuration version
     */
    public function lockVersion(int $versionId, int $userId): array
    {
        $sql = "UPDATE assessment_config_versions 
                SET is_locked = 1, locked_by = ?, locked_at = NOW()
                WHERE version_id = ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$userId, $versionId]);

        return ['success' => true, 'message' => 'Version locked successfully'];
    }

    /**
     * Get next version number
     */
    private function getNextVersionNumber(int $profileId): int
    {
        $sql = "SELECT MAX(version_number) as max_version FROM assessment_config_versions WHERE profile_id = ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$profileId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($result['max_version'] ?? 0) + 1;
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
