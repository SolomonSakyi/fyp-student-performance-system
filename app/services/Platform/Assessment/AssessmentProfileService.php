<?php

/**
 * AssessmentProfileService.php
 * Assessment Profile Service - Business Logic
 * @package EduTrack
 * @subpackage Services\Platform\Assessment
 * @version 1.0
 */

require_once dirname(__DIR__, 3) . '/models/Platform/AssessmentProfile.php';
require_once dirname(__DIR__, 3) . '/services/Platform/BaseService.php';

class AssessmentProfileService extends BaseService
{
    private $profileModel;

    public function __construct()
    {
        parent::__construct();
        $this->profileModel = new AssessmentProfile();
    }

    /**
     * Get all profiles for a school
     */
    public function getProfiles(int $schoolId, ?string $status = null): array
    {
        try {
            if ($status) {
                $profiles = $this->profileModel->getBySchoolAndStatus($schoolId, $status);
            } else {
                $profiles = $this->profileModel->getBySchool($schoolId);
            }

            return [
                'success' => true,
                'data' => $profiles
            ];
        } catch (Exception $e) {
            $this->logger->error('getProfiles error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get a single profile with all related data
     */
    public function getProfile(int $profileId): array
    {
        try {
            $profile = $this->profileModel->getWithComponents($profileId);
            if (!$profile) {
                return ['success' => false, 'message' => 'Profile not found'];
            }

            return [
                'success' => true,
                'data' => $profile
            ];
        } catch (Exception $e) {
            $this->logger->error('getProfile error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Create a new assessment profile
     */
    public function createProfile(array $data): array
    {
        try {
            $this->beginTransaction();

            // Validate
            $errors = $this->validateProfile($data);
            if (!empty($errors)) {
                $this->rollback();
                return ['success' => false, 'errors' => $errors];
            }

            // Generate UUID
            $data['uuid'] = $this->generateUUID();

            // Set defaults
            $data['status'] = $data['status'] ?? AssessmentProfile::STATUS_DRAFT;
            $data['is_default'] = $data['is_default'] ?? 0;

            // Create profile
            $profileId = $this->profileModel->create($data);

            if (!$profileId) {
                $this->rollback();
                return ['success' => false, 'errors' => ['Failed to create profile']];
            }

            $this->commit();

            return [
                'success' => true,
                'message' => 'Assessment profile created successfully',
                'data' => ['profile_id' => $profileId]
            ];
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('createProfile error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update an assessment profile
     */
    public function updateProfile(int $profileId, array $data): array
    {
        try {
            $this->beginTransaction();

            $profile = $this->profileModel->getById($profileId);
            if (!$profile) {
                $this->rollback();
                return ['success' => false, 'message' => 'Profile not found'];
            }

            // Check if locked
            if ($profile['status'] === AssessmentProfile::STATUS_LOCKED) {
                $this->rollback();
                return ['success' => false, 'message' => 'Profile is locked and cannot be modified'];
            }

            // Validate
            $errors = $this->validateProfile($data, true);
            if (!empty($errors)) {
                $this->rollback();
                return ['success' => false, 'errors' => $errors];
            }

            $updated = $this->profileModel->update($profileId, $data);

            if (!$updated) {
                $this->rollback();
                return ['success' => false, 'errors' => ['Failed to update profile']];
            }

            $this->commit();

            return [
                'success' => true,
                'message' => 'Assessment profile updated successfully'
            ];
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('updateProfile error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete an assessment profile
     */
    public function deleteProfile(int $profileId): array
    {
        try {
            $this->beginTransaction();

            $profile = $this->profileModel->getById($profileId);
            if (!$profile) {
                $this->rollback();
                return ['success' => false, 'message' => 'Profile not found'];
            }

            // Check if in use
            if ($this->profileModel->isInUse($profileId)) {
                $this->rollback();
                return ['success' => false, 'message' => 'Profile is in use and cannot be deleted'];
            }

            // Soft delete
            $deleted = $this->profileModel->softDelete($profileId);

            if (!$deleted) {
                $this->rollback();
                return ['success' => false, 'errors' => ['Failed to delete profile']];
            }

            $this->commit();

            return ['success' => true, 'message' => 'Profile deleted successfully'];
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('deleteProfile error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Change profile status
     */
    public function changeStatus(int $profileId, string $status): array
    {
        $allowedStatuses = ['draft', 'active', 'archived', 'locked'];
        if (!in_array($status, $allowedStatuses)) {
            return ['success' => false, 'message' => 'Invalid status'];
        }

        $profile = $this->profileModel->getById($profileId);
        if (!$profile) {
            return ['success' => false, 'message' => 'Profile not found'];
        }

        $updated = $this->profileModel->update($profileId, ['status' => $status]);

        if (!$updated) {
            return ['success' => false, 'message' => 'Failed to update status'];
        }

        return [
            'success' => true,
            'message' => 'Status updated successfully',
            'data' => ['profile_id' => $profileId, 'status' => $status]
        ];
    }

    /**
     * Lock a profile
     */
    public function lockProfile(int $profileId): array
    {
        $profile = $this->profileModel->getById($profileId);
        if (!$profile) {
            return ['success' => false, 'message' => 'Profile not found'];
        }

        $updated = $this->profileModel->lock($profileId);

        if (!$updated) {
            return ['success' => false, 'message' => 'Failed to lock profile'];
        }

        return [
            'success' => true,
            'message' => 'Profile locked successfully'
        ];
    }

    /**
     * Unlock a profile
     */
    public function unlockProfile(int $profileId): array
    {
        $profile = $this->profileModel->getById($profileId);
        if (!$profile) {
            return ['success' => false, 'message' => 'Profile not found'];
        }

        $updated = $this->profileModel->unlock($profileId);

        if (!$updated) {
            return ['success' => false, 'message' => 'Failed to unlock profile'];
        }

        return [
            'success' => true,
            'message' => 'Profile unlocked successfully'
        ];
    }

    /**
     * Duplicate a profile
     */
    public function duplicateProfile(int $profileId, string $newName): array
    {
        try {
            $this->beginTransaction();

            $profile = $this->profileModel->getWithComponents($profileId);
            if (!$profile) {
                $this->rollback();
                return ['success' => false, 'message' => 'Profile not found'];
            }

            // Create new profile data
            $newProfileData = [
                'uuid' => $this->generateUUID(),
                'school_id' => $profile['school_id'],
                'profile_name' => $newName,
                'profile_code' => $profile['profile_code'] . '_copy',
                'description' => $profile['description'] . ' (Duplicate)',
                'applicable_levels' => $profile['applicable_levels'],
                'status' => AssessmentProfile::STATUS_DRAFT,
                'is_default' => 0
            ];

            $newProfileId = $this->profileModel->create($newProfileData);

            if (!$newProfileId) {
                $this->rollback();
                return ['success' => false, 'message' => 'Failed to duplicate profile'];
            }

            // Copy components
            if (isset($profile['components'])) {
                foreach ($profile['components'] as $component) {
                    $sql = "INSERT INTO assessment_profile_components 
                            (profile_id, component_id, weight, sort_order)
                            VALUES (?, ?, ?, ?)";
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute([
                        $newProfileId,
                        $component['component_id'],
                        $component['weight'] ?? 0,
                        $component['sort_order'] ?? 0
                    ]);
                }
            }

            $this->commit();

            return [
                'success' => true,
                'message' => 'Profile duplicated successfully',
                'data' => ['profile_id' => $newProfileId]
            ];
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('duplicateProfile error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Validate profile data
     */
    private function validateProfile(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        if (!$isUpdate || isset($data['profile_name'])) {
            if (empty($data['profile_name'])) {
                $errors[] = 'Profile name is required';
            }
        }

        if (!$isUpdate || isset($data['profile_code'])) {
            if (empty($data['profile_code'])) {
                $errors[] = 'Profile code is required';
            }
            if (!preg_match('/^[A-Z0-9\-_]{2,20}$/', $data['profile_code'])) {
                $errors[] = 'Profile code must be 2-20 characters and contain only uppercase letters, numbers, hyphens, and underscores';
            }
        }

        if (!$isUpdate || isset($data['school_id'])) {
            if (empty($data['school_id'])) {
                $errors[] = 'School ID is required';
            }
        }

        if (!$isUpdate || isset($data['applicable_levels'])) {
            if (empty($data['applicable_levels'])) {
                $errors[] = 'Applicable levels are required';
            }
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
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
