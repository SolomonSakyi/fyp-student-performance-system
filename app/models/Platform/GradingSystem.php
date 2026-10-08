<?php

/**
 * GradingSystem.php - Grading System Model
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class GradingSystem extends BaseModel
{
    protected $table = 'grading_systems';

    protected $fillable = [
        'uuid',
        'school_id',
        'system_name',
        'system_code',
        'system_type',
        'description',
        'is_default',
        'is_active'
    ];

    // ============================================================
    // SYSTEM TYPE CONSTANTS
    // ============================================================
    const TYPE_ALPHABET = 'alphabet';
    const TYPE_NUMBER = 'number';
    const TYPE_PERCENTAGE = 'percentage';
    const TYPE_GPA = 'gpa';
    const TYPE_CUSTOM = 'custom';

    // ============================================================
    // CRUD METHODS
    // ============================================================

    /**
     * Get all grading systems for a school
     */
    public function getBySchool(int $schoolId): array
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE school_id = ? AND is_active = 1
                ORDER BY is_default DESC, system_name ASC";

        return $this->rawFetch($sql, [$schoolId]);
    }

    /**
     * Get grading system by ID
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1";
        $result = $this->rawFetch($sql, [$id]);
        return !empty($result) ? $result[0] : null;
    }

    /**
     * Get default grading system
     */
    public function getDefault(int $schoolId): ?array
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE school_id = ? AND is_default = 1 AND is_active = 1
                LIMIT 1";

        $result = $this->rawFetch($sql, [$schoolId]);
        return !empty($result) ? $result[0] : null;
    }

    /**
     * Create a grading system
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
     * Update a grading system
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
     * Soft delete a grading system
     */
    public function softDelete(int $id): bool
    {
        $sql = "UPDATE {$this->table} SET is_active = 0, deleted_at = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$id]);
    }

    // ============================================================
    // GRADE SCALE METHODS
    // ============================================================

    /**
     * Get grade scales for this system
     */
    public function getGradeScales(int $systemId): array
    {
        $sql = "SELECT * FROM grade_scales
                WHERE grading_system_id = ?
                ORDER BY sort_order ASC";

        return $this->rawFetch($sql, [$systemId]);
    }

    /**
     * Get grade for a score
     */
    public function getGradeForScore(int $systemId, float $score): ?array
    {
        $sql = "SELECT * FROM grade_scales
                WHERE grading_system_id = ?
                AND min_score <= ? AND max_score >= ?
                LIMIT 1";

        $result = $this->rawFetch($sql, [$systemId, $score, $score]);
        return !empty($result) ? $result[0] : null;
    }

    /**
     * Add a grade scale
     */
    public function addGradeScale(array $data): ?int
    {
        $sql = "INSERT INTO grade_scales 
                (grading_system_id, grade, min_score, max_score, grade_point, remark, color, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->db->prepare($sql);
        if ($stmt->execute([
            $data['grading_system_id'],
            $data['grade'],
            $data['min_score'] ?? 0,
            $data['max_score'] ?? 100,
            $data['grade_point'] ?? 0,
            $data['remark'] ?? null,
            $data['color'] ?? '#28a745',
            $data['sort_order'] ?? 0
        ])) {
            return (int)$this->db->lastInsertId();
        }

        return null;
    }

    /**
     * Update a grade scale
     */
    public function updateGradeScale(int $id, array $data): bool
    {
        $allowed = ['grade', 'min_score', 'max_score', 'grade_point', 'remark', 'color', 'sort_order'];
        $data = array_intersect_key($data, array_flip($allowed));

        if (empty($data)) {
            return false;
        }

        $set = [];
        foreach (array_keys($data) as $key) {
            $set[] = "{$key} = ?";
        }

        $sql = "UPDATE grade_scales SET " . implode(', ', $set) . " WHERE grade_scale_id = ?";
        $params = array_values($data);
        $params[] = $id;

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Delete a grade scale
     */
    public function deleteGradeScale(int $id): bool
    {
        $sql = "DELETE FROM grade_scales WHERE grade_scale_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$id]);
    }

    /**
     * Set as default
     */
    public function setAsDefault(int $systemId, int $schoolId): bool
    {
        // Reset all defaults for this school
        $sql = "UPDATE {$this->table} SET is_default = 0 
                WHERE school_id = ?";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$schoolId]);

        // Set this as default
        return $this->update($systemId, ['is_default' => 1]);
    }

    /**
     * Get grading system with scales
     */
    public function getWithScales(int $systemId): ?array
    {
        $system = $this->getById($systemId);
        if (!$system) {
            return null;
        }

        $system['scales'] = $this->getGradeScales($systemId);
        return $system;
    }
}
