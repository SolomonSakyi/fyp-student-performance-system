<?php

/**
 * PersonCategoryRepository.php
 * Repository for person category assignment operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Identity
 * @filepath app/repositories/Identity/PersonCategoryRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class PersonCategoryRepository
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Create a new category assignment for a person
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO person_category_assignments (
            person_id, category_id, start_date, end_date, status, created_at
        ) VALUES (?, ?, ?, ?, ?, NOW())";

        $params = [
            $data['person_id'],
            $data['category_id'],
            $data['start_date'] ?? date('Y-m-d'),
            $data['end_date'] ?? null,
            $data['status'] ?? 'active'
        ];

        $this->db->execute($sql, $params);
        return $this->db->lastInsertId();
    }

    /**
     * Get category assignments by person ID
     */
    public function getByPersonId(int $personId): array
    {
        $sql = "SELECT pca.*, pc.category_code, pc.category_name
                FROM person_category_assignments pca
                INNER JOIN person_categories pc ON pca.category_id = pc.id
                WHERE pca.person_id = ? AND pca.deleted_at IS NULL
                ORDER BY pca.status DESC, pca.start_date DESC";
        return $this->db->fetchAll($sql, [$personId]);
    }

    /**
     * Get active category assignments by person ID
     */
    public function getActiveByPersonId(int $personId): array
    {
        $sql = "SELECT pca.*, pc.category_code, pc.category_name
                FROM person_category_assignments pca
                INNER JOIN person_categories pc ON pca.category_id = pc.id
                WHERE pca.person_id = ? AND pca.status = 'active' AND pca.deleted_at IS NULL
                ORDER BY pca.start_date DESC";
        return $this->db->fetchAll($sql, [$personId]);
    }

    /**
     * Get assignment by ID
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT pca.*, pc.category_code, pc.category_name
                FROM person_category_assignments pca
                INNER JOIN person_categories pc ON pca.category_id = pc.id
                WHERE pca.id = ? AND pca.deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$id]);
    }

    /**
     * Get people by category
     */
    public function getByCategory(int $categoryId, int $tenantId): array
    {
        $sql = "SELECT p.*, pca.start_date, pca.status as assignment_status
                FROM people p
                INNER JOIN person_category_assignments pca ON p.id = pca.person_id
                INNER JOIN person_organizational_assignments poa ON p.id = poa.person_id
                WHERE pca.category_id = ? 
                AND pca.deleted_at IS NULL 
                AND p.deleted_at IS NULL 
                AND poa.tenant_id = ? 
                AND poa.deleted_at IS NULL
                ORDER BY p.last_name, p.first_name";
        return $this->db->fetchAll($sql, [$categoryId, $tenantId]);
    }

    /**
     * Update a category assignment
     */
    public function update(int $id, array $data): bool
    {
        $updates = [];
        $params = [];

        $allowedFields = ['category_id', 'start_date', 'end_date', 'status'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($updates)) {
            return false;
        }

        $params[] = $id;
        $sql = "UPDATE person_category_assignments SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, $params);
    }

    /**
     * End a category assignment
     */
    public function endAssignment(int $id): bool
    {
        $sql = "UPDATE person_category_assignments SET 
                status = 'inactive', 
                end_date = NOW(), 
                updated_at = NOW() 
                WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }

    /**
     * Soft delete a category assignment
     */
    public function delete(int $id): bool
    {
        $sql = "UPDATE person_category_assignments SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }

    /**
     * Check if person has a category assignment
     */
    public function existsForPerson(int $personId, int $categoryId, ?int $excludeId = null): bool
    {
        $sql = "SELECT id FROM person_category_assignments 
                WHERE person_id = ? AND category_id = ? AND deleted_at IS NULL";
        $params = [$personId, $categoryId];
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        $result = $this->db->fetchOne($sql, $params);
        return (bool)$result;
    }

    /**
     * Get category assignments expiring soon
     */
    public function getExpiringSoon(int $tenantId, int $days = 30): array
    {
        $sql = "SELECT pca.*, pc.category_code, pc.category_name,
                       p.id as person_id, p.first_name, p.last_name, p.person_number
                FROM person_category_assignments pca
                INNER JOIN person_categories pc ON pca.category_id = pc.id
                INNER JOIN people p ON pca.person_id = p.id
                INNER JOIN person_organizational_assignments poa ON p.id = poa.person_id
                WHERE pca.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
                AND pca.deleted_at IS NULL 
                AND p.deleted_at IS NULL 
                AND poa.tenant_id = ? 
                AND poa.deleted_at IS NULL
                ORDER BY pca.end_date ASC";
        return $this->db->fetchAll($sql, [$days, $tenantId]);
    }
}
