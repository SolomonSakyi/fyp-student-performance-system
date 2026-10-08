<?php

/**
 * PersonContactRepository.php
 * Repository for person contact operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Identity
 * @filepath app/repositories/Identity/PersonContactRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class PersonContactRepository
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Create a new contact for a person
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO person_contacts (
            person_id, contact_type_id, contact_value, is_primary, 
            is_verified, verification_date, notes, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

        $params = [
            $data['person_id'],
            $data['contact_type_id'],
            $data['contact_value'],
            $data['is_primary'] ?? 0,
            $data['is_verified'] ?? 0,
            $data['verification_date'] ?? null,
            $data['notes'] ?? null
        ];

        $this->db->execute($sql, $params);
        return $this->db->lastInsertId();
    }

    /**
     * Get contacts by person ID
     */
    public function getByPersonId(int $personId): array
    {
        $sql = "SELECT pc.*, ct.type_code, ct.type_name 
                FROM person_contacts pc
                INNER JOIN contact_types ct ON pc.contact_type_id = ct.id
                WHERE pc.person_id = ? AND pc.deleted_at IS NULL
                ORDER BY pc.is_primary DESC, ct.sort_order ASC";
        return $this->db->fetchAll($sql, [$personId]);
    }

    /**
     * Get contact by ID
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT pc.*, ct.type_code, ct.type_name 
                FROM person_contacts pc
                INNER JOIN contact_types ct ON pc.contact_type_id = ct.id
                WHERE pc.id = ? AND pc.deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$id]);
    }

    /**
     * Get primary contact by person ID and type
     */
    public function getPrimaryByPersonId(int $personId): ?array
    {
        $sql = "SELECT pc.*, ct.type_code, ct.type_name 
                FROM person_contacts pc
                INNER JOIN contact_types ct ON pc.contact_type_id = ct.id
                WHERE pc.person_id = ? AND pc.is_primary = 1 AND pc.deleted_at IS NULL
                ORDER BY ct.sort_order ASC LIMIT 1";
        return $this->db->fetchOne($sql, [$personId]);
    }

    /**
     * Update a contact
     */
    public function update(int $id, array $data): bool
    {
        $updates = [];
        $params = [];

        $allowedFields = ['contact_type_id', 'contact_value', 'is_primary', 'is_verified', 'verification_date', 'notes'];

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
        $sql = "UPDATE person_contacts SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, $params);
    }

    /**
     * Set a contact as primary (unset others for the same person)
     */
    public function setPrimary(int $personId, int $contactId): bool
    {
        // Unset all primary for this person
        $sql = "UPDATE person_contacts SET is_primary = 0, updated_at = NOW() WHERE person_id = ? AND deleted_at IS NULL";
        $this->db->execute($sql, [$personId]);

        // Set this contact as primary
        $sql = "UPDATE person_contacts SET is_primary = 1, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$contactId]);
    }

    /**
     * Verify a contact
     */
    public function verify(int $id): bool
    {
        $sql = "UPDATE person_contacts SET is_verified = 1, verification_date = NOW(), updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }

    /**
     * Soft delete a contact
     */
    public function delete(int $id): bool
    {
        $sql = "UPDATE person_contacts SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }

    /**
     * Check if person has a contact with the same value
     */
    public function existsForPerson(int $personId, string $value, ?int $excludeId = null): bool
    {
        $sql = "SELECT id FROM person_contacts WHERE person_id = ? AND contact_value = ? AND deleted_at IS NULL";
        $params = [$personId, $value];
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        $result = $this->db->fetchOne($sql, $params);
        return (bool)$result;
    }

    /**
     * Search contacts by value across a tenant
     */
    public function searchByValue(string $value, int $tenantId, ?int $schoolId = null): array
    {
        $params = [$value, $tenantId];
        $sql = "SELECT pc.*, p.id as person_id, p.first_name, p.last_name, p.person_number
                FROM person_contacts pc
                INNER JOIN people p ON pc.person_id = p.id
                INNER JOIN person_organizational_assignments poa ON p.id = poa.person_id
                WHERE pc.contact_value LIKE ? 
                AND poa.tenant_id = ? 
                AND poa.deleted_at IS NULL 
                AND pc.deleted_at IS NULL 
                AND p.deleted_at IS NULL";

        if ($schoolId) {
            $sql .= " AND poa.school_id = ?";
            $params[] = $schoolId;
        }

        $sql .= " GROUP BY pc.id ORDER BY pc.is_primary DESC, pc.created_at DESC LIMIT 20";
        return $this->db->fetchAll($sql, $params);
    }
}
