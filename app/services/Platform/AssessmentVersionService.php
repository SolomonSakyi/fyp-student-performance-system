<?php

/**
 * AssessmentVersionService.php
 * Assessment Configuration Versioning Service
 * 
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/AssessmentConfigVersion.php';
require_once dirname(__DIR__, 2) . '/models/Platform/AssessmentConfigAuditLog.php';
require_once dirname(__DIR__, 2) . '/models/Platform/AssessmentProfile.php';

class AssessmentVersionService
{
    private $versionModel;
    private $auditModel;
    private $profileModel;

    public function __construct()
    {
        $this->versionModel = new AssessmentConfigVersion();
        $this->auditModel = new AssessmentConfigAuditLog();
        $this->profileModel = new AssessmentProfile();
    }

    /**
     * Create a new version
     */
    public function createVersion(int $profileId, array $data, int $userId): array
    {
        try {
            // Get current version number
            $currentVersion = $this->versionModel->getLatestVersion($profileId);
            $nextVersion = $currentVersion ? $currentVersion['version_number'] + 1 : 1;

            $data['profile_id'] = $profileId;
            $data['version_number'] = $nextVersion;
            $data['status'] = 'draft';
            $data['created_by'] = $userId;
            $data['updated_by'] = $userId;

            $versionId = $this->versionModel->create($data);

            if (!$versionId) {
                return ['success' => false, 'message' => 'Failed to create version'];
            }

            // Audit log
            $this->auditModel->log([
                'profile_id' => $profileId,
                'version_id' => $versionId,
                'action' => 'version_created',
                'new_value' => "Version $nextVersion created",
                'changed_by' => $userId
            ]);

            return [
                'success' => true,
                'message' => "Version $nextVersion created successfully",
                'data' => ['id' => $versionId, 'version_number' => $nextVersion]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Publish a version
     */
    public function publishVersion(int $versionId, int $userId): array
    {
        try {
            $version = $this->versionModel->find($versionId);
            if (!$version) {
                return ['success' => false, 'message' => 'Version not found'];
            }

            // Check if already published
            if ($version['status'] === 'published') {
                return ['success' => false, 'message' => 'Version is already published'];
            }

            // Check if profile is locked
            $profile = $this->profileModel->find($version['profile_id']);
            if ($profile && $profile['status'] === 'locked') {
                return ['success' => false, 'message' => 'Cannot publish version for locked profile'];
            }

            // Update version status
            $result = $this->versionModel->update($versionId, [
                'status' => 'published',
                'published_by' => $userId,
                'published_at' => date('Y-m-d H:i:s'),
                'updated_by' => $userId
            ]);

            if (!$result) {
                return ['success' => false, 'message' => 'Failed to publish version'];
            }

            // Set this as current version
            $this->versionModel->setCurrent($versionId);

            // Audit log
            $this->auditModel->log([
                'profile_id' => $version['profile_id'],
                'version_id' => $versionId,
                'action' => 'version_published',
                'new_value' => "Version {$version['version_number']} published",
                'changed_by' => $userId
            ]);

            return [
                'success' => true,
                'message' => "Version {$version['version_number']} published successfully"
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Compare two versions
     */
    public function compareVersions(int $versionId1, int $versionId2): array
    {
        try {
            $v1 = $this->versionModel->getFullVersion($versionId1);
            $v2 = $this->versionModel->getFullVersion($versionId2);

            if (!$v1 || !$v2) {
                return ['success' => false, 'message' => 'Version not found'];
            }

            $diff = $this->calculateDiff($v1['configuration_data'], $v2['configuration_data']);

            return [
                'success' => true,
                'data' => [
                    'version1' => [
                        'id' => $v1['id'],
                        'version_number' => $v1['version_number'],
                        'name' => $v1['version_name'],
                        'status' => $v1['status']
                    ],
                    'version2' => [
                        'id' => $v2['id'],
                        'version_number' => $v2['version_number'],
                        'name' => $v2['version_name'],
                        'status' => $v2['status']
                    ],
                    'differences' => $diff
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Calculate differences between two configurations
     */
    private function calculateDiff(array $config1, array $config2): array
    {
        $diff = [];

        // Compare each key
        foreach (array_keys($config1) as $key) {
            if (!isset($config2[$key])) {
                $diff[] = [
                    'key' => $key,
                    'type' => 'removed',
                    'value' => $config1[$key]
                ];
            } elseif ($config1[$key] !== $config2[$key]) {
                $diff[] = [
                    'key' => $key,
                    'type' => 'changed',
                    'old_value' => $config1[$key],
                    'new_value' => $config2[$key]
                ];
            }
        }

        // Find added keys
        foreach (array_keys($config2) as $key) {
            if (!isset($config1[$key])) {
                $diff[] = [
                    'key' => $key,
                    'type' => 'added',
                    'value' => $config2[$key]
                ];
            }
        }

        return $diff;
    }

    /**
     * Get version history
     */
    public function getVersionHistory(int $profileId, int $limit = 20): array
    {
        try {
            $versions = $this->versionModel->getVersionsByProfile($profileId, $limit);

            // Add audit logs for each version
            foreach ($versions as &$version) {
                $version['audit_logs'] = $this->auditModel->getByVersion($version['id'], 5);
            }

            return [
                'success' => true,
                'data' => $versions
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Lock a version
     */
    public function lockVersion(int $versionId, int $userId): array
    {
        try {
            $version = $this->versionModel->find($versionId);
            if (!$version) {
                return ['success' => false, 'message' => 'Version not found'];
            }

            // Check if results exist for this version
            // This would need to check assessment_results table
            // For now, allow locking

            $result = $this->versionModel->update($versionId, [
                'status' => 'locked',
                'updated_by' => $userId
            ]);

            if (!$result) {
                return ['success' => false, 'message' => 'Failed to lock version'];
            }

            // Also lock the profile
            $this->profileModel->update($version['profile_id'], [
                'status' => 'locked',
                'updated_by' => $userId
            ]);

            // Audit log
            $this->auditModel->log([
                'profile_id' => $version['profile_id'],
                'version_id' => $versionId,
                'action' => 'version_locked',
                'new_value' => "Version {$version['version_number']} locked",
                'changed_by' => $userId
            ]);

            return [
                'success' => true,
                'message' => "Version {$version['version_number']} locked successfully"
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
