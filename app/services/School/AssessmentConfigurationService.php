<?php

/**
 * AssessmentConfigurationService.php
 * Service for managing assessment profiles and components
 * 
 * @package EduTrack
 * @subpackage Services\School
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/helpers/DatabaseHelper.php';
require_once dirname(__DIR__, 2) . '/services/Tenant/TenantContext.php';
require_once dirname(__DIR__, 2) . '/services/Authorization/AuthorizationService.php';

class AssessmentConfigurationService
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
     * Get all assessment profiles for a school
     */
    public function getAssessmentProfiles(int $schoolId, ?int $levelId = null): array
    {
        if (!$this->auth->canAccessSchool($schoolId)) {
            throw new Exception('Unauthorized access to assessment profiles');
        }

        $sql = "SELECT 
                    ap.*,
                    COUNT(DISTINCT pc.component_id) as component_count,
                    SUM(pc.weight) as total_weight
                FROM assessment_profiles ap
                LEFT JOIN profile_components pc ON ap.id = pc.profile_id AND pc.deleted_at IS NULL
                WHERE ap.school_id = ? AND ap.deleted_at IS NULL";

        $params = [$schoolId];

        if ($levelId) {
            $sql .= " AND (ap.level_id = ? OR ap.level_id IS NULL)";
            $params[] = $levelId;
        }

        $sql .= " GROUP BY ap.id ORDER BY ap.is_default DESC, ap.profile_name ASC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get a specific assessment profile with components
     */
    public function getAssessmentProfile(int $profileId): ?array
    {
        $sql = "SELECT * FROM assessment_profiles WHERE id = ? AND deleted_at IS NULL";
        $profile = $this->db->fetchOne($sql, [$profileId]);

        if (!$profile) {
            return null;
        }

        // Verify authorization
        if (!$this->auth->canAccessSchool($profile['school_id'])) {
            throw new Exception('Unauthorized access to assessment profile');
        }

        // Get components
        $sql = "SELECT 
                    pc.*,
                    ac.component_name,
                    ac.component_code,
                    ac.component_type,
                    ac.default_weight as component_default_weight
                FROM profile_components pc
                JOIN assessment_components ac ON pc.component_id = ac.id
                WHERE pc.profile_id = ? AND pc.deleted_at IS NULL
                ORDER BY pc.sequence ASC";

        $profile['components'] = $this->db->fetchAll($sql, [$profileId]);

        // Calculate total weight
        $profile['total_weight'] = 0;
        foreach ($profile['components'] as $component) {
            $profile['total_weight'] += $component['weight'];
        }

        return $profile;
    }

    /**
     * Create a new assessment profile
     */
    public function createAssessmentProfile(int $schoolId, array $data): array
    {
        if (!$this->auth->canManageSchool($schoolId)) {
            throw new Exception('Unauthorized to manage assessment profiles');
        }

        $errors = $this->validateProfileData($data);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $this->db->beginTransaction();

        try {
            // Generate UUID
            $uuid = $this->generateUUID();

            // Insert profile
            $sql = "INSERT INTO assessment_profiles (
                        uuid, school_id, tenant_id, profile_name, profile_code,
                        description, applicable_levels, status, is_default,
                        created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $tenantId = $this->context->getTenantId();
            $userId = $this->context->getUserId();

            $this->db->query($sql, [
                $uuid,
                $schoolId,
                $tenantId,
                $data['profile_name'],
                $data['profile_code'],
                $data['description'] ?? '',
                $data['applicable_levels'] ?? null,
                $data['status'] ?? 'draft',
                $data['is_default'] ?? 0,
                $userId
            ]);

            $profileId = $this->db->lastInsertId();

            // Add components if provided
            if (!empty($data['components'])) {
                foreach ($data['components'] as $component) {
                    $this->addComponentToProfile($profileId, $component);
                }
            }

            // If this is default, unset other defaults
            if ($data['is_default'] ?? 0) {
                $this->unsetDefaultProfiles($schoolId, $profileId);
            }

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Assessment profile created successfully',
                'profile_id' => $profileId
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update an assessment profile
     */
    public function updateAssessmentProfile(int $profileId, array $data): array
    {
        $profile = $this->getAssessmentProfile($profileId);
        if (!$profile) {
            return ['success' => false, 'errors' => ['Profile not found']];
        }

        if (!$this->auth->canManageSchool($profile['school_id'])) {
            throw new Exception('Unauthorized to manage assessment profiles');
        }

        $sql = "UPDATE assessment_profiles SET ";
        $updates = [];
        $params = [];

        $allowedFields = [
            'profile_name',
            'profile_code',
            'description',
            'applicable_levels',
            'status',
            'is_default'
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($updates)) {
            return ['success' => false, 'errors' => ['No fields to update']];
        }

        $sql .= implode(', ', $updates);
        $sql .= ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        $params[] = $profileId;

        $this->db->query($sql, $params);

        // If this is default, unset other defaults
        if (isset($data['is_default']) && $data['is_default']) {
            $this->unsetDefaultProfiles($profile['school_id'], $profileId);
        }

        return [
            'success' => true,
            'message' => 'Assessment profile updated successfully'
        ];
    }

    /**
     * Add a component to a profile
     */
    public function addComponentToProfile(int $profileId, array $data): array
    {
        $profile = $this->getAssessmentProfile($profileId);
        if (!$profile) {
            return ['success' => false, 'errors' => ['Profile not found']];
        }

        // Check if component exists
        if (isset($data['component_id'])) {
            $component = $this->getComponent($data['component_id']);
            if (!$component) {
                return ['success' => false, 'errors' => ['Component not found']];
            }
            $componentId = $data['component_id'];
        } else {
            // Create new component
            $result = $this->createComponent($profile['school_id'], $data);
            if (!$result['success']) {
                return $result;
            }
            $componentId = $result['component_id'];
        }

        // Check if already assigned
        $existing = $this->db->fetchOne(
            "SELECT id FROM profile_components WHERE profile_id = ? AND component_id = ? AND deleted_at IS NULL",
            [$profileId, $componentId]
        );

        if ($existing) {
            return [
                'success' => false,
                'errors' => ['Component already assigned to this profile']
            ];
        }

        // Insert profile component
        $sql = "INSERT INTO profile_components (
                    profile_id, component_id, sequence, max_score, weight,
                    is_required, counts_towards_total, subject_id, is_active, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";

        $this->db->query($sql, [
            $profileId,
            $componentId,
            $data['sequence'] ?? 0,
            $data['max_score'] ?? 100,
            $data['weight'] ?? 0,
            $data['is_required'] ?? 1,
            $data['counts_towards_total'] ?? 1,
            $data['subject_id'] ?? null
        ]);

        return [
            'success' => true,
            'message' => 'Component added to profile successfully'
        ];
    }

    /**
     * Remove a component from a profile
     */
    public function removeComponentFromProfile(int $profileId, int $componentId): array
    {
        $sql = "UPDATE profile_components 
                SET deleted_at = NOW(), is_active = 0
                WHERE profile_id = ? AND component_id = ? AND deleted_at IS NULL";

        $this->db->query($sql, [$profileId, $componentId]);

        return [
            'success' => true,
            'message' => 'Component removed from profile successfully'
        ];
    }

    /**
     * Get all assessment components for a school
     */
    public function getComponents(int $schoolId): array
    {
        if (!$this->auth->canAccessSchool($schoolId)) {
            throw new Exception('Unauthorized access to assessment components');
        }

        $sql = "SELECT * FROM assessment_components 
                WHERE school_id = ? AND deleted_at IS NULL 
                ORDER BY display_order ASC, component_name ASC";

        return $this->db->fetchAll($sql, [$schoolId]);
    }

    /**
     * Get a specific component
     */
    public function getComponent(int $componentId): ?array
    {
        $sql = "SELECT * FROM assessment_components WHERE id = ? AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$componentId]);
    }

    /**
     * Create a new assessment component
     */
    public function createComponent(int $schoolId, array $data): array
    {
        if (!$this->auth->canManageSchool($schoolId)) {
            throw new Exception('Unauthorized to manage assessment components');
        }

        $errors = $this->validateComponentData($data);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $uuid = $this->generateUUID();
        $tenantId = $this->context->getTenantId();

        $sql = "INSERT INTO assessment_components (
                    uuid, school_id, tenant_id, component_name, component_code,
                    component_type, default_weight, max_score, description,
                    display_order, is_active, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";

        $this->db->query($sql, [
            $uuid,
            $schoolId,
            $tenantId,
            $data['component_name'],
            $data['component_code'] ?? null,
            $data['component_type'] ?? 'custom',
            $data['default_weight'] ?? 0,
            $data['max_score'] ?? 100,
            $data['description'] ?? '',
            $data['display_order'] ?? 0
        ]);

        $componentId = $this->db->lastInsertId();

        return [
            'success' => true,
            'message' => 'Component created successfully',
            'component_id' => $componentId
        ];
    }

    /**
     * Update a component
     */
    public function updateComponent(int $componentId, array $data): array
    {
        $component = $this->getComponent($componentId);
        if (!$component) {
            return ['success' => false, 'errors' => ['Component not found']];
        }

        if (!$this->auth->canManageSchool($component['school_id'])) {
            throw new Exception('Unauthorized to manage assessment components');
        }

        $sql = "UPDATE assessment_components SET ";
        $updates = [];
        $params = [];

        $allowedFields = [
            'component_name',
            'component_code',
            'component_type',
            'default_weight',
            'max_score',
            'description',
            'display_order',
            'is_active'
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($updates)) {
            return ['success' => false, 'errors' => ['No fields to update']];
        }

        $sql .= implode(', ', $updates);
        $sql .= ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        $params[] = $componentId;

        $this->db->query($sql, $params);

        return [
            'success' => true,
            'message' => 'Component updated successfully'
        ];
    }

    /**
     * Delete a component (soft delete)
     */
    public function deleteComponent(int $componentId): array
    {
        $component = $this->getComponent($componentId);
        if (!$component) {
            return ['success' => false, 'errors' => ['Component not found']];
        }

        // Check if component is in use
        $inUse = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM profile_components WHERE component_id = ? AND deleted_at IS NULL",
            [$componentId]
        );

        if (($inUse['count'] ?? 0) > 0) {
            return [
                'success' => false,
                'errors' => ['Component is in use and cannot be deleted']
            ];
        }

        $sql = "UPDATE assessment_components SET deleted_at = NOW(), is_active = 0 WHERE id = ? AND deleted_at IS NULL";
        $this->db->query($sql, [$componentId]);

        return [
            'success' => true,
            'message' => 'Component deleted successfully'
        ];
    }

    /**
     * Validate profile weight (ensure total = 100)
     */
    public function validateProfileWeights(int $profileId): array
    {
        $sql = "SELECT SUM(weight) as total_weight FROM profile_components 
                WHERE profile_id = ? AND deleted_at IS NULL";

        $result = $this->db->fetchOne($sql, [$profileId]);
        $totalWeight = (float)($result['total_weight'] ?? 0);

        $isValid = abs($totalWeight - 100) < 0.01;

        // Update profile validation status
        $sql = "UPDATE assessment_profiles 
                SET total_weight = ?, is_weight_validated = ? 
                WHERE id = ? AND deleted_at IS NULL";

        $this->db->query($sql, [$totalWeight, $isValid ? 1 : 0, $profileId]);

        return [
            'total_weight' => $totalWeight,
            'is_valid' => $isValid,
            'message' => $isValid ? 'Profile weights are valid (100%)' : 'Profile weights total ' . $totalWeight . '% (expected 100%)'
        ];
    }

    /**
     * Unset default profiles
     */
    private function unsetDefaultProfiles(int $schoolId, int $excludeProfileId): void
    {
        $sql = "UPDATE assessment_profiles 
                SET is_default = 0 
                WHERE school_id = ? AND id != ? AND deleted_at IS NULL";

        $this->db->query($sql, [$schoolId, $excludeProfileId]);
    }

    /**
     * Get assessment statistics
     */
    public function getAssessmentStats(int $schoolId): array
    {
        $stats = $this->db->fetchOne(
            "SELECT 
                COUNT(*) as total_profiles,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published_profiles,
                SUM(CASE WHEN is_default = 1 THEN 1 ELSE 0 END) as default_profiles,
                SUM(CASE WHEN is_weight_validated = 1 THEN 1 ELSE 0 END) as validated_profiles
            FROM assessment_profiles
            WHERE school_id = ? AND deleted_at IS NULL",
            [$schoolId]
        );

        $componentStats = $this->db->fetchOne(
            "SELECT COUNT(*) as total_components
            FROM assessment_components
            WHERE school_id = ? AND deleted_at IS NULL",
            [$schoolId]
        );

        return [
            'total_profiles' => (int)($stats['total_profiles'] ?? 0),
            'published_profiles' => (int)($stats['published_profiles'] ?? 0),
            'default_profiles' => (int)($stats['default_profiles'] ?? 0),
            'validated_profiles' => (int)($stats['validated_profiles'] ?? 0),
            'total_components' => (int)($componentStats['total_components'] ?? 0)
        ];
    }

    /**
     * Validate profile data
     */
    private function validateProfileData(array $data): array
    {
        $errors = [];

        if (empty($data['profile_name'])) {
            $errors[] = 'Profile name is required';
        }

        if (empty($data['profile_code'])) {
            $errors[] = 'Profile code is required';
        }

        return $errors;
    }

    /**
     * Validate component data
     */
    private function validateComponentData(array $data): array
    {
        $errors = [];

        if (empty($data['component_name'])) {
            $errors[] = 'Component name is required';
        }

        if (isset($data['max_score']) && $data['max_score'] <= 0) {
            $errors[] = 'Max score must be greater than 0';
        }

        if (isset($data['default_weight']) && $data['default_weight'] < 0) {
            $errors[] = 'Weight cannot be negative';
        }

        return $errors;
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
            mt_rand(0, 0xffff) | 0x4000,
            mt_rand(0, 0x3ffff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
