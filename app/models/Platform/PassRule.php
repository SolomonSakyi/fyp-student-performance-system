<?php

/**
 * PassRule.php - Pass Rule Model
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class PassRule extends BaseModel
{
    protected $table = 'pass_rules';

    protected $fillable = [
        'uuid',
        'school_id',
        'rule_name',
        'rule_code',
        'description',
        'pass_threshold',
        'applicable_levels',
        'applicable_subjects',
        'subject_count_required',
        'status',
        'created_by',
        'updated_by'
    ];

    // ============================================================
    // STATUS CONSTANTS
    // ============================================================
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_ARCHIVED = 'archived';

    // ============================================================
    // CRUD METHODS
    // ============================================================

    /**
     * Get all pass rules for a school
     */
    public function getBySchool(int $schoolId): array
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE school_id = ? AND deleted_at IS NULL
                ORDER BY status = 'active' DESC, rule_name ASC";

        return $this->rawFetch($sql, [$schoolId]);
    }

    /**
     * Get pass rule by ID
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = ? AND deleted_at IS NULL LIMIT 1";
        $result = $this->rawFetch($sql, [$id]);
        return !empty($result) ? $result[0] : null;
    }

    /**
     * Get active pass rules
     */
    public function getActiveBySchool(int $schoolId): array
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE school_id = ? AND status = 'active' AND deleted_at IS NULL
                ORDER BY rule_name ASC";

        return $this->rawFetch($sql, [$schoolId]);
    }

    /**
     * Create a pass rule
     */
    public function create(array $data): ?int
    {
        $data = array_intersect_key($data, array_flip($this->fillable));

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";
        $stmt = $this->db->prepare($sql);

        if ($stmt->execute(array_values($data))) {
            return (int)$this->db->lastInsertId();
        }

        return null;
    }

    /**
     * Update a pass rule
     */
    public function update(int $id, array $data): bool
    {
        $data = array_intersect_key($data, array_flip($this->fillable));

        if (empty($data)) {
            return false;
        }

        $set = [];
        foreach (array_keys($data) as $key) {
            $set[] = "{$key} = ?";
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $set) . " WHERE id = ?";
        $params = array_values($data);
        $params[] = $id;

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Soft delete a pass rule
     */
    public function softDelete(int $id): bool
    {
        $sql = "UPDATE {$this->table} SET status = 'archived', deleted_at = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$id]);
    }

    /**
     * Check if a student passes based on scores
     */
    public function checkPass(array $scores, array $rule): bool
    {
        $passThreshold = (float)($rule['pass_threshold'] ?? 50);
        $requiredCount = (int)($rule['subject_count_required'] ?? 5);

        // Get subjects that have scores
        $subjectScores = array_filter($scores, function ($score) {
            return isset($score['score']) && $score['score'] !== null;
        });

        // Check if student has enough subjects
        if (count($subjectScores) < $requiredCount) {
            return false;
        }

        // Check if all subjects meet the threshold
        foreach ($subjectScores as $score) {
            if ((float)$score['score'] < $passThreshold) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get default values
     */
    public function getDefaults(): array
    {
        return [
            'pass_threshold' => 50.00,
            'subject_count_required' => 5,
            'status' => self::STATUS_ACTIVE
        ];
    }

    /**
     * Validate rule data
     */
    public function validate(array $data): array
    {
        $errors = [];

        if (empty($data['rule_name'])) {
            $errors[] = 'Rule name is required';
        }

        if (empty($data['rule_code'])) {
            $errors[] = 'Rule code is required';
        }

        if (isset($data['pass_threshold']) && ($data['pass_threshold'] < 0 || $data['pass_threshold'] > 100)) {
            $errors[] = 'Pass threshold must be between 0 and 100';
        }

        if (isset($data['subject_count_required']) && $data['subject_count_required'] < 1) {
            $errors[] = 'Subject count required must be at least 1';
        }

        return $errors;
    }
}
