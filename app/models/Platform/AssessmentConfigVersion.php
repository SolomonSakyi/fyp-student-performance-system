<?php

/**
 * AssessmentConfigVersion.php
 * Assessment Configuration Version Model
 * 
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class AssessmentConfigVersion extends BaseModel
{
    protected $table = 'assessment_config_versions';

    protected $fillable = [
        'profile_id',
        'version_number',
        'version_name',
        'status',
        'effective_from',
        'effective_to',
        'academic_year',
        'term',
        'configuration_data',
        'is_current',
        'published_by',
        'published_at',
        'created_by',
        'updated_by'
    ];

    const STATUS_DRAFT = 'draft';
    const STATUS_PUBLISHED = 'published';
    const STATUS_ARCHIVED = 'archived';
    const STATUS_LOCKED = 'locked';

    /**
     * Get versions by profile
     */
    public function getVersionsByProfile(int $profileId, int $limit = 20): array
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE profile_id = ? AND deleted_at IS NULL 
                ORDER BY version_number DESC
                LIMIT ?";
        return $this->rawFetch($sql, [$profileId, $limit]);
    }

    /**
     * Get latest version
     */
    public function getLatestVersion(int $profileId): ?array
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE profile_id = ? AND deleted_at IS NULL 
                ORDER BY version_number DESC
                LIMIT 1";
        return $this->rawFetchOne($sql, [$profileId]);
    }

    /**
     * Get current version
     */
    public function getCurrentVersion(int $profileId): ?array
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE profile_id = ? AND is_current = 1 AND deleted_at IS NULL 
                LIMIT 1";
        return $this->rawFetchOne($sql, [$profileId]);
    }

    /**
     * Set version as current
     */
    public function setCurrent(int $versionId): bool
    {
        try {
            $this->db->beginTransaction();

            // Get the version
            $version = $this->find($versionId);
            if (!$version) {
                $this->db->rollBack();
                return false;
            }

            // Remove current flag from all versions of this profile
            $sql = "UPDATE {$this->table} SET is_current = 0 WHERE profile_id = ?";
            $this->db->query($sql, [$version['profile_id']]);

            // Set this version as current
            $sql = "UPDATE {$this->table} SET is_current = 1 WHERE id = ?";
            $result = $this->db->query($sql, [$versionId]);

            $this->db->commit();
            return $result;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log('setCurrent error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get version with full configuration
     */
    public function getFullVersion(int $versionId): ?array
    {
        $version = $this->find($versionId);
        if (!$version) {
            return null;
        }

        // Decode configuration data
        $version['configuration_data'] = json_decode($version['configuration_data'], true);

        return $version;
    }

    /**
     * Create version from profile
     */
    public function createFromProfile(int $profileId, int $userId): ?int
    {
        $profile = $this->getProfileConfiguration($profileId);
        if (!$profile) {
            return null;
        }

        $currentVersion = $this->getLatestVersion($profileId);
        $nextVersion = $currentVersion ? $currentVersion['version_number'] + 1 : 1;

        return $this->create([
            'profile_id' => $profileId,
            'version_number' => $nextVersion,
            'version_name' => "Version {$nextVersion}",
            'status' => self::STATUS_DRAFT,
            'configuration_data' => json_encode($profile),
            'created_by' => $userId,
            'updated_by' => $userId
        ]);
    }

    /**
     * Get profile configuration
     */
    private function getProfileConfiguration(int $profileId): ?array
    {
        // This would need to fetch all configuration data for the profile
        // For now, return a placeholder
        return [
            'profile_id' => $profileId,
            'components' => [],
            'grading_system' => null,
            'aggregation_rule' => null,
            'ranking_rule' => null,
            'pass_rule' => null,
            'remark_rules' => [],
            'rounding_rule' => null
        ];
    }

    /**
     * Get valid statuses
     */
    public function getValidStatuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_PUBLISHED => 'Published',
            self::STATUS_ARCHIVED => 'Archived',
            self::STATUS_LOCKED => 'Locked'
        ];
    }
}
