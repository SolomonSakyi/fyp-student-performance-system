<?php

/**
 * PersonDocumentRepository.php
 * Repository for person document operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Identity
 * @filepath app/repositories/Identity/PersonDocumentRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class PersonDocumentRepository
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Create a new document for a person
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO person_documents (
            person_id, document_type_id, document_number, issuing_country,
            issuing_authority, issue_date, expiry_date, storage_reference,
            verification_status, verified_by, verified_at, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $params = [
            $data['person_id'],
            $data['document_type_id'],
            $data['document_number'] ?? null,
            $data['issuing_country'] ?? null,
            $data['issuing_authority'] ?? null,
            $data['issue_date'] ?? null,
            $data['expiry_date'] ?? null,
            $data['storage_reference'],
            $data['verification_status'] ?? 'pending',
            $data['verified_by'] ?? null,
            $data['verified_at'] ?? null
        ];

        $this->db->execute($sql, $params);
        return $this->db->lastInsertId();
    }

    /**
     * Get documents by person ID
     */
    public function getByPersonId(int $personId): array
    {
        $sql = "SELECT pd.*, dt.type_code, dt.type_name, dt.requires_verification
                FROM person_documents pd
                INNER JOIN document_types dt ON pd.document_type_id = dt.id
                WHERE pd.person_id = ? AND pd.deleted_at IS NULL
                ORDER BY pd.verification_status ASC, pd.created_at DESC";
        return $this->db->fetchAll($sql, [$personId]);
    }

    /**
     * Get document by ID
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT pd.*, dt.type_code, dt.type_name, dt.requires_verification
                FROM person_documents pd
                INNER JOIN document_types dt ON pd.document_type_id = dt.id
                WHERE pd.id = ? AND pd.deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$id]);
    }

    /**
     * Get document by storage reference
     */
    public function getByStorageReference(string $storageReference): ?array
    {
        $sql = "SELECT pd.*, dt.type_code, dt.type_name
                FROM person_documents pd
                INNER JOIN document_types dt ON pd.document_type_id = dt.id
                WHERE pd.storage_reference = ? AND pd.deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$storageReference]);
    }

    /**
     * Get documents by document number
     */
    public function getByDocumentNumber(string $documentNumber): array
    {
        $sql = "SELECT pd.*, p.id as person_id, p.first_name, p.last_name, p.person_number
                FROM person_documents pd
                INNER JOIN people p ON pd.person_id = p.id
                WHERE pd.document_number = ? AND pd.deleted_at IS NULL AND p.deleted_at IS NULL";
        return $this->db->fetchAll($sql, [$documentNumber]);
    }

    /**
     * Get expired documents
     */
    public function getExpired(int $tenantId): array
    {
        $sql = "SELECT pd.*, p.id as person_id, p.first_name, p.last_name, p.person_number
                FROM person_documents pd
                INNER JOIN people p ON pd.person_id = p.id
                INNER JOIN person_organizational_assignments poa ON p.id = poa.person_id
                WHERE pd.expiry_date < CURDATE() 
                AND pd.deleted_at IS NULL 
                AND p.deleted_at IS NULL 
                AND poa.tenant_id = ? 
                AND poa.deleted_at IS NULL
                ORDER BY pd.expiry_date ASC";
        return $this->db->fetchAll($sql, [$tenantId]);
    }

    /**
     * Get documents expiring soon (within 30 days)
     */
    public function getExpiringSoon(int $tenantId, int $days = 30): array
    {
        $sql = "SELECT pd.*, p.id as person_id, p.first_name, p.last_name, p.person_number
                FROM person_documents pd
                INNER JOIN people p ON pd.person_id = p.id
                INNER JOIN person_organizational_assignments poa ON p.id = poa.person_id
                WHERE pd.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
                AND pd.deleted_at IS NULL 
                AND p.deleted_at IS NULL 
                AND poa.tenant_id = ? 
                AND poa.deleted_at IS NULL
                ORDER BY pd.expiry_date ASC";
        return $this->db->fetchAll($sql, [$days, $tenantId]);
    }

    /**
     * Update a document
     */
    public function update(int $id, array $data): bool
    {
        $updates = [];
        $params = [];

        $allowedFields = [
            'document_type_id',
            'document_number',
            'issuing_country',
            'issuing_authority',
            'issue_date',
            'expiry_date',
            'storage_reference',
            'verification_status',
            'verified_by',
            'verified_at'
        ];

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
        $sql = "UPDATE person_documents SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, $params);
    }

    /**
     * Verify a document
     */
    public function verify(int $id, int $verifiedBy): bool
    {
        $sql = "UPDATE person_documents SET 
                verification_status = 'verified', 
                verified_by = ?, 
                verified_at = NOW(), 
                updated_at = NOW() 
                WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$verifiedBy, $id]);
    }

    /**
     * Mark document as failed verification
     */
    public function markFailed(int $id, int $verifiedBy): bool
    {
        $sql = "UPDATE person_documents SET 
                verification_status = 'failed', 
                verified_by = ?, 
                verified_at = NOW(), 
                updated_at = NOW() 
                WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$verifiedBy, $id]);
    }

    /**
     * Mark document as expired
     */
    public function markExpired(int $id): bool
    {
        $sql = "UPDATE person_documents SET 
                verification_status = 'expired', 
                updated_at = NOW() 
                WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }

    /**
     * Soft delete a document
     */
    public function delete(int $id): bool
    {
        $sql = "UPDATE person_documents SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }

    /**
     * Check if person has a document with the same number
     */
    public function existsForPerson(int $personId, string $documentNumber, ?int $excludeId = null): bool
    {
        $sql = "SELECT id FROM person_documents WHERE person_id = ? AND document_number = ? AND deleted_at IS NULL";
        $params = [$personId, $documentNumber];
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        $result = $this->db->fetchOne($sql, $params);
        return (bool)$result;
    }

    /**
     * Update expired documents status
     */
    public function updateExpiredStatus(): int
    {
        $sql = "UPDATE person_documents SET 
                verification_status = 'expired', 
                updated_at = NOW() 
                WHERE expiry_date < CURDATE() 
                AND verification_status != 'expired' 
                AND deleted_at IS NULL";
        $this->db->execute($sql);
        return $this->db->rowCount();
    }
}
