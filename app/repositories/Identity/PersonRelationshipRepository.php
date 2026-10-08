<?php

/**
 * PersonRelationshipRepository.php
 * Repository for person-to-person relationship operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Identity
 * @filepath app/repositories/Identity/PersonRelationshipRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class PersonRelationshipRepository
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Create a new relationship between two people
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO person_relationships (
            person_id, related_person_id, relationship_type_id,
            is_primary, start_date, end_date, status, notes, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $params = [
            $data['person_id'],
            $data['related_person_id'],
            $data['relationship_type_id'],
            $data['is_primary'] ?? 0,
            $data['start_date'] ?? null,
            $data['end_date'] ?? null,
            $data['status'] ?? 'active',
            $data['notes'] ?? null
        ];

        $this->db->execute($sql, $params);
        return $this->db->lastInsertId();
    }

    /**
     * Get relationships by person ID
     */
    public function getByPersonId(int $personId): array
    {
        $sql = "SELECT pr.*, 
                       rt.type_code, rt.type_name, rt.is_reciprocal,
                       p.first_name as related_first_name,
                       p.middle_name as related_middle_name,
                       p.last_name as related_last_name,
                       p.person_number as related_person_number,
                       p.uuid as related_uuid
                FROM person_relationships pr
                INNER JOIN relationship_types rt ON pr.relationship_type_id = rt.id
                INNER JOIN people p ON pr.related_person_id = p.id
                WHERE pr.person_id = ? AND pr.deleted_at IS NULL
                ORDER BY pr.status DESC, pr.start_date DESC";
        return $this->db->fetchAll($sql, [$personId]);
    }

    /**
     * Get relationships by related person ID
     */
    public function getByRelatedPersonId(int $relatedPersonId): array
    {
        $sql = "SELECT pr.*, 
                       rt.type_code, rt.type_name, rt.is_reciprocal,
                       p.first_name as person_first_name,
                       p.middle_name as person_middle_name,
                       p.last_name as person_last_name,
                       p.person_number as person_person_number,
                       p.uuid as person_uuid
                FROM person_relationships pr
                INNER JOIN relationship_types rt ON pr.relationship_type_id = rt.id
                INNER JOIN people p ON pr.person_id = p.id
                WHERE pr.related_person_id = ? AND pr.deleted_at IS NULL
                ORDER BY pr.status DESC, pr.start_date DESC";
        return $this->db->fetchAll($sql, [$relatedPersonId]);
    }

    /**
     * Get relationship by ID
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT pr.*, 
                       rt.type_code, rt.type_name, rt.is_reciprocal,
                       p1.first_name as person_first_name,
                       p1.middle_name as person_middle_name,
                       p1.last_name as person_last_name,
                       p1.person_number as person_person_number,
                       p2.first_name as related_first_name,
                       p2.middle_name as related_middle_name,
                       p2.last_name as related_last_name,
                       p2.person_number as related_person_number
                FROM person_relationships pr
                INNER JOIN relationship_types rt ON pr.relationship_type_id = rt.id
                INNER JOIN people p1 ON pr.person_id = p1.id
                INNER JOIN people p2 ON pr.related_person_id = p2.id
                WHERE pr.id = ? AND pr.deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$id]);
    }

    /**
     * Get active relationships by person ID
     */
    public function getActiveByPersonId(int $personId): array
    {
        $sql = "SELECT pr.*, 
                       rt.type_code, rt.type_name, rt.is_reciprocal,
                       p.first_name as related_first_name,
                       p.middle_name as related_middle_name,
                       p.last_name as related_last_name,
                       p.person_number as related_person_number
                FROM person_relationships pr
                INNER JOIN relationship_types rt ON pr.relationship_type_id = rt.id
                INNER JOIN people p ON pr.related_person_id = p.id
                WHERE pr.person_id = ? AND pr.status = 'active' AND pr.deleted_at IS NULL
                ORDER BY rt.sort_order ASC";
        return $this->db->fetchAll($sql, [$personId]);
    }

    /**
     * Get relationships between two people
     */
    public function getBetweenPeople(int $personId, int $relatedPersonId): array
    {
        $sql = "SELECT pr.*, rt.type_code, rt.type_name, rt.is_reciprocal
                FROM person_relationships pr
                INNER JOIN relationship_types rt ON pr.relationship_type_id = rt.id
                WHERE pr.person_id = ? AND pr.related_person_id = ? AND pr.deleted_at IS NULL
                ORDER BY pr.created_at DESC";
        return $this->db->fetchAll($sql, [$personId, $relatedPersonId]);
    }

    /**
     * Update a relationship
     */
    public function update(int $id, array $data): bool
    {
        $updates = [];
        $params = [];

        $allowedFields = ['relationship_type_id', 'is_primary', 'start_date', 'end_date', 'status', 'notes'];

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
        $sql = "UPDATE person_relationships SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, $params);
    }

    /**
     * End a relationship (soft end, not delete)
     */
    public function endRelationship(int $id, ?string $reason = null): bool
    {
        $sql = "UPDATE person_relationships SET 
                status = 'ended', 
                end_date = NOW(), 
                updated_at = NOW() 
                WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }

    /**
     * Reactivate an ended relationship
     */
    public function reactivate(int $id): bool
    {
        $sql = "UPDATE person_relationships SET 
                status = 'active', 
                end_date = NULL, 
                updated_at = NOW() 
                WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }

    /**
     * Soft delete a relationship
     */
    public function delete(int $id): bool
    {
        $sql = "UPDATE person_relationships SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }

    /**
     * Check if relationship already exists between two people
     */
    public function existsBetweenPeople(int $personId, int $relatedPersonId, ?int $excludeId = null): bool
    {
        $sql = "SELECT id FROM person_relationships 
                WHERE person_id = ? AND related_person_id = ? AND deleted_at IS NULL";
        $params = [$personId, $relatedPersonId];
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        $result = $this->db->fetchOne($sql, $params);
        return (bool)$result;
    }

    /**
     * Get relationships by type
     */
    public function getByType(int $relationshipTypeId, int $tenantId): array
    {
        $sql = "SELECT pr.*, 
                       p1.first_name as person_first_name,
                       p1.last_name as person_last_name,
                       p2.first_name as related_first_name,
                       p2.last_name as related_last_name
                FROM person_relationships pr
                INNER JOIN people p1 ON pr.person_id = p1.id
                INNER JOIN people p2 ON pr.related_person_id = p2.id
                INNER JOIN person_organizational_assignments poa ON p1.id = poa.person_id
                WHERE pr.relationship_type_id = ? 
                AND pr.deleted_at IS NULL 
                AND p1.deleted_at IS NULL 
                AND p2.deleted_at IS NULL 
                AND poa.tenant_id = ? 
                AND poa.deleted_at IS NULL
                ORDER BY pr.created_at DESC";
        return $this->db->fetchAll($sql, [$relationshipTypeId, $tenantId]);
    }

    /**
     * Get all relationships for a tenant
     */
    public function getByTenant(int $tenantId, int $limit = 100): array
    {
        $sql = "SELECT pr.*, 
                       p1.first_name as person_first_name,
                       p1.last_name as person_last_name,
                       p2.first_name as related_first_name,
                       p2.last_name as related_last_name,
                       rt.type_name
                FROM person_relationships pr
                INNER JOIN people p1 ON pr.person_id = p1.id
                INNER JOIN people p2 ON pr.related_person_id = p2.id
                INNER JOIN relationship_types rt ON pr.relationship_type_id = rt.id
                INNER JOIN person_organizational_assignments poa ON p1.id = poa.person_id
                WHERE poa.tenant_id = ? 
                AND pr.deleted_at IS NULL 
                AND p1.deleted_at IS NULL 
                AND p2.deleted_at IS NULL 
                AND poa.deleted_at IS NULL
                ORDER BY pr.created_at DESC
                LIMIT ?";
        return $this->db->fetchAll($sql, [$tenantId, $limit]);
    }

    /**
     * Get reciprocal relationship
     */
    public function getReciprocal(int $relationshipId): ?array
    {
        $relationship = $this->getById($relationshipId);
        if (!$relationship || !$relationship['is_reciprocal']) {
            return null;
        }

        $sql = "SELECT pr.*, rt.type_code, rt.type_name
                FROM person_relationships pr
                INNER JOIN relationship_types rt ON pr.relationship_type_id = rt.id
                WHERE pr.person_id = ? AND pr.related_person_id = ? 
                AND pr.deleted_at IS NULL
                AND rt.is_reciprocal = 1
                LIMIT 1";
        return $this->db->fetchOne($sql, [
            $relationship['related_person_id'],
            $relationship['person_id']
        ]);
    }
}
