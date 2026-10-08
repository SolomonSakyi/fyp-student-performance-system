<?php

/**
 * RemarkRule.php - Remark Rule Model
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class RemarkRule extends BaseModel
{
    protected $table = 'remark_rules';

    protected $fillable = [
        'uuid',
        'school_id',
        'rule_name',
        'rule_code',
        'description',
        'min_score',
        'max_score',
        'remark_text',
        'remark_type',
        'sort_order',
        'status',
        'created_by',
        'updated_by'
    ];

    // ============================================================
    // REMARK TYPES
    // ============================================================
    const TYPE_ACADEMIC = 'academic';
    const TYPE_BEHAVIORAL = 'behavioral';
    const TYPE_GENERAL = 'general';

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
     * Get all remark rules for a school
     */
    public function getBySchool(int $schoolId): array
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE school_id = ? AND deleted_at IS NULL
                ORDER BY sort_order ASC, min_score ASC";

        return $this->rawFetch($sql, [$schoolId]);
    }

    /**
     * Get remark rule by ID
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = ? AND deleted_at IS NULL LIMIT 1";
        $result = $this->rawFetch($sql, [$id]);
        return !empty($result) ? $result[0] : null;
    }

    /**
     * Get active remark rules
     */
    public function getActiveBySchool(int $schoolId): array
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE school_id = ? AND status = 'active' AND deleted_at IS NULL
                ORDER BY sort_order ASC, min_score ASC";

        return $this->rawFetch($sql, [$schoolId]);
    }

    /**
     * Create a remark rule
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
     * Update a remark rule
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
     * Soft delete a remark rule
     */
    public function softDelete(int $id): bool
    {
        $sql = "UPDATE {$this->table} SET status = 'archived', deleted_at = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$id]);
    }

    // ============================================================
    // REMARK GENERATION METHODS
    // ============================================================

    /**
     * Get remark for a score
     */
    public function getRemarkForScore(int $schoolId, float $score): ?string
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE school_id = ? 
                AND min_score <= ? AND max_score >= ?
                AND status = 'active'
                AND deleted_at IS NULL
                ORDER BY sort_order ASC
                LIMIT 1";

        $result = $this->rawFetch($sql, [$schoolId, $score, $score]);
        return !empty($result) ? $result[0]['remark_text'] : null;
    }

    /**
     * Get all remarks for a school
     */
    public function getAllRemarks(int $schoolId): array
    {
        return $this->getBySchool($schoolId);
    }

    /**
     * Get default values
     */
    public function getDefaults(): array
    {
        return [
            'min_score' => 0,
            'max_score' => 100,
            'remark_type' => self::TYPE_ACADEMIC,
            'sort_order' => 0,
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

        if (empty($data['remark_text'])) {
            $errors[] = 'Remark text is required';
        }

        if (isset($data['min_score']) && isset($data['max_score'])) {
            if ($data['min_score'] > $data['max_score']) {
                $errors[] = 'Min score cannot be greater than max score';
            }
            if ($data['min_score'] < 0 || $data['max_score'] > 100) {
                $errors[] = 'Scores must be between 0 and 100';
            }
        }

        return $errors;
    }
}
