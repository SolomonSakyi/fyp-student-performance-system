<?php

/**
 * AssessmentConfigurationResolver.php
 * Resolves effective configuration based on context
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 1.0
 */

class AssessmentConfigurationResolver
{
    private $assessmentService;
    private $terminologyService;

    public function __construct()
    {
        $this->assessmentService = new AssessmentConfigurationService();
        $this->terminologyService = new AcademicTerminologyService();
    }

    /**
     * Resolve effective configuration for a given context
     */
    public function resolve(array $context): array
    {
        // Validate required context
        $required = ['tenant_id', 'school_id', 'level_code'];
        foreach ($required as $field) {
            if (!isset($context[$field])) {
                return ['error' => "Missing required context: {$field}"];
            }
        }

        // Resolve in order of precedence
        $config = [];

        // 1. Platform default
        $config = $this->getPlatformDefault();

        // 2. Tenant override
        if (isset($context['tenant_id'])) {
            $tenantConfig = $this->getTenantConfig($context['tenant_id']);
            if ($tenantConfig) {
                $config = $this->mergeConfig($config, $tenantConfig);
            }
        }

        // 3. School override
        $schoolConfig = $this->getSchoolConfig($context['school_id']);
        if ($schoolConfig) {
            $config = $this->mergeConfig($config, $schoolConfig);
        }

        // 4. Campus override
        if (isset($context['campus_id'])) {
            $campusConfig = $this->getCampusConfig($context['campus_id']);
            if ($campusConfig) {
                $config = $this->mergeConfig($config, $campusConfig);
            }
        }

        // 5. Academic Year override
        if (isset($context['academic_year_id'])) {
            $yearConfig = $this->getAcademicYearConfig($context['academic_year_id']);
            if ($yearConfig) {
                $config = $this->mergeConfig($config, $yearConfig);
            }
        }

        // 6. Term override
        if (isset($context['term_id'])) {
            $termConfig = $this->getTermConfig($context['term_id']);
            if ($termConfig) {
                $config = $this->mergeConfig($config, $termConfig);
            }
        }

        // 7. Academic Profile override (highest precedence)
        if (isset($context['profile_id'])) {
            $profileConfig = $this->getProfileConfig($context['profile_id']);
            if ($profileConfig) {
                $config = $this->mergeConfig($config, $profileConfig);
            }
        }

        // 8. Level-specific override
        if (isset($context['level_code'])) {
            $levelConfig = $this->getLevelConfig($context['school_id'], $context['level_code']);
            if ($levelConfig) {
                $config = $this->mergeConfig($config, $levelConfig);
            }
        }

        // 9. Subject-specific override (if applicable)
        if (isset($context['subject_id'])) {
            $subjectConfig = $this->getSubjectConfig($context['subject_id']);
            if ($subjectConfig) {
                $config = $this->mergeConfig($config, $subjectConfig);
            }
        }

        // Resolve display names using terminology service
        $config['display_level'] = $this->terminologyService->getLevelDisplayName(
            $context['school_id'],
            $context['level_code']
        );

        return $config;
    }

    /**
     * Get platform default configuration
     */
    private function getPlatformDefault(): array
    {
        return [
            'assessment_model' => '30_70',
            'total_weight' => 100,
            'components' => [
                ['name' => 'Class Assessment', 'weight' => 30],
                ['name' => 'Examination', 'weight' => 70]
            ],
            'grading_type' => 'alphabetical',
            'core_count' => 4,
            'elective_count' => 2,
            'aggregation_method' => 'sum_grade_points'
        ];
    }

    /**
     * Get tenant configuration
     */
    private function getTenantConfig(int $tenantId): ?array
    {
        // Implement tenant config retrieval
        return null;
    }

    /**
     * Get school configuration
     */
    private function getSchoolConfig(int $schoolId): ?array
    {
        // Get school's default assessment profile
        $sql = "SELECT * FROM assessment_profiles 
                WHERE school_id = ? AND is_default = 1 AND status = 'active'
                LIMIT 1";
        $stmt = DatabaseHelper::getInstance()->getConnection()->prepare($sql);
        $stmt->execute([$schoolId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            return [
                'profile_id' => $result['profile_id'],
                'assessment_model' => $result['assessment_model'],
                'total_weight' => $result['total_weight']
            ];
        }

        return null;
    }

    /**
     * Get campus configuration
     */
    private function getCampusConfig(int $campusId): ?array
    {
        // Implement campus config retrieval
        return null;
    }

    /**
     * Get academic year configuration
     */
    private function getAcademicYearConfig(int $yearId): ?array
    {
        // Implement academic year config retrieval
        return null;
    }

    /**
     * Get term configuration
     */
    private function getTermConfig(int $termId): ?array
    {
        // Implement term config retrieval
        return null;
    }

    /**
     * Get profile configuration
     */
    private function getProfileConfig(int $profileId): ?array
    {
        // Get specific assessment profile
        $sql = "SELECT * FROM assessment_profiles WHERE profile_id = ?";
        $stmt = DatabaseHelper::getInstance()->getConnection()->prepare($sql);
        $stmt->execute([$profileId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get level configuration
     */
    private function getLevelConfig(int $schoolId, string $levelCode): ?array
    {
        // Get level-specific assessment profile
        $sql = "SELECT * FROM assessment_profiles 
                WHERE school_id = ? 
                AND (applicable_levels LIKE ? OR applicable_levels IS NULL)
                AND status = 'active'
                ORDER BY is_default DESC
                LIMIT 1";

        $searchPattern = '%' . $levelCode . '%';
        $stmt = DatabaseHelper::getInstance()->getConnection()->prepare($sql);
        $stmt->execute([$schoolId, $searchPattern]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get subject configuration
     */
    private function getSubjectConfig(int $subjectId): ?array
    {
        // Implement subject-specific config retrieval
        return null;
    }

    /**
     * Merge configuration arrays
     */
    private function mergeConfig(array $base, array $override): array
    {
        // Simple array merge
        return array_merge($base, $override);
    }
}
