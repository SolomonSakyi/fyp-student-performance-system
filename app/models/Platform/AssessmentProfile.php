<?php

/**
 * AssessmentProfile.php - Assessment Profile Model
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/helpers/DatabaseHelper.php';

class AssessmentProfile
{
    protected $table = 'assessment_profiles';
    protected $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    public function create(array $data): ?int
    {
        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES ({$placeholders})";
        $stmt = $this->db->prepare($sql);
        if ($stmt->execute(array_values($data))) {
            return (int)$this->db->lastInsertId();
        }
        return null;
    }

    public function find(int $id): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = ? AND deleted_at IS NULL LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function getAll(): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE deleted_at IS NULL ORDER BY is_default DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getBySchool(int $schoolId): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE school_id = ? AND deleted_at IS NULL ORDER BY is_default DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$schoolId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function update(int $id, array $data): bool
    {
        $sets = [];
        foreach (array_keys($data) as $key) {
            $sets[] = "{$key} = ?";
        }
        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets) . " WHERE id = ?";
        $params = array_values($data);
        $params[] = $id;
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $sql = "UPDATE {$this->table} SET deleted_at = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$id]);
    }
}
