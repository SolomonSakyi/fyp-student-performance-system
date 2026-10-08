<?php

/**
 * AssessmentProfile.php
 * Assessment Profile Model
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/helpers/DatabaseHelper.php';

class AssessmentProfile
{
    protected $table = 'assessment_profiles';
    protected $db;

    // Status constants
    const STATUS_DRAFT = 'draft';
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_ARCHIVED = 'archived';
    const STATUS_LOCKED = 'locked';

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Create a new assessment profile
     */
    public function create(array $data): ?int
    {
        $data['uuid'] = $data['uuid'] ?? $this->generateUUID();
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        $sql = "INSERT INTO {$this->table} (
            uuid, school_id, profile_name, profile_code, description,
            applicable_levels, assessment_model, grading_system_id,
            aggregation_rule_id, ranking_rule_id, pass_rule_id,
            remark_rule_id, total_weight, is_active, is_locked,
            is_default, status, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            $data['uuid'],
            $data['school_id'],
            $data['profile_name'],
            $data['profile_code'],
            $data['description'] ?? null,
            $data['applicable_levels'] ?? null,
            $data['assessment_model'] ?? '30_70',
            $data['grading_system_id'] ?? null,
            $data['aggregation_rule_id'] ?? null,
            $data['ranking_rule_id'] ?? null,
            $data['pass_rule_id'] ?? null,
            $data['remark_rule_id'] ?? null,
            $data['total_weight'] ?? 100,
            $data['is_active'] ?? 1,
            $data['is_locked'] ?? 0,
            $data['is_default'] ?? 0,
            $data['status'] ?? self::STATUS_DRAFT,
            $data['created_by'] ?? null,
            $data['created_at'],
            $data['updated_at']
        ]);

        return (int)$this->db->getConnection()->lastInsertId();
    }

    /**
     * Get profile by ID
     */
    public function getById(int $profileId): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE profile_id = ? AND deleted_at IS NULL";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$profileId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get profiles by school
     */
    public function getBySchool(int $schoolId, ?string $status = null): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE school_id = ? AND deleted_at IS NULL";
        $params = [$schoolId];

        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY is_default DESC, profile_name ASC";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get active profiles by school
     */
    public function getActiveBySchool(int $schoolId): array
    {
        return $this->getBySchool($schoolId, self::STATUS_ACTIVE);
    }

    /**
     * Get default profile by school
     */
    public function getDefault(int $schoolId): ?array
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE school_id = ? AND is_default = 1 AND deleted_at IS NULL 
                LIMIT 1";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$schoolId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Update profile
     */
    public function update(int $profileId, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        $allowedFields = [
            'profile_name',
            'profile_code',
            'description',
            'applicable_levels',
            'assessment_model',
            'grading_system_id',
            'aggregation_rule_id',
            'ranking_rule_id',
            'pass_rule_id',
            'remark_rule_id',
            'total_weight',
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
            return false;
        }

        $sets[] = "updated_at = ?";
        $params[] = $data['updated_at'];
        $params[] = $profileId;

        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets) . " WHERE profile_id = ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Soft delete profile
     */
    public function softDelete(int $profileId): bool
    {
        $sql = "UPDATE {$this->table} SET deleted_at = NOW() WHERE profile_id = ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        return $stmt->execute([$profileId]);
    }

    /**
     * Check if profile is in use
     */
    public function isInUse(int $profileId): bool
    {
        $sql = "SELECT COUNT(*) as count FROM assessment_results WHERE profile_id = ? AND deleted_at IS NULL";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$profileId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return isset($result['count']) && $result['count'] > 0;
    }

    /**
     * Get full profile with all related data
     */
    public function getFullProfile(int $profileId): ?array
    {
        $profile = $this->getById($profileId);
        if (!$profile) {
            return null;
        }

        // Get components with weights
        $sql = "SELECT pc.*, c.component_name, c.component_code, c.max_score 
                FROM assessment_profile_components pc
                JOIN assessment_components c ON pc.component_id = c.component_id
                WHERE pc.profile_id = ? AND c.deleted_at IS NULL
                ORDER BY pc.sort_order ASC";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$profileId]);
        $components = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get grading system with scales
        $gradingSystem = null;
        if ($profile['grading_system_id']) {
            $sql = "SELECT * FROM grading_systems WHERE grading_system_id = ? AND deleted_at IS NULL";
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([$profile['grading_system_id']]);
            $gradingSystem = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($gradingSystem) {
                $sql = "SELECT * FROM grade_scales WHERE grading_system_id = ? ORDER BY sort_order ASC";
                $stmt = $this->db->getConnection()->prepare($sql);
                $stmt->execute([$gradingSystem['grading_system_id']]);
                $gradingSystem['scales'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        // Get aggregation rule
        $aggregationRule = null;
        if ($profile['aggregation_rule_id']) {
            $sql = "SELECT * FROM aggregation_rules WHERE aggregation_rule_id = ? AND deleted_at IS NULL";
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([$profile['aggregation_rule_id']]);
            $aggregationRule = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        return [
            'profile' => $profile,
            'components' => $components,
            'grading_system' => $gradingSystem,
            'aggregation_rule' => $aggregationRule
        ];
    }

    /**
     * Generate UUID
     */
    protected function generateUUID(): string
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
