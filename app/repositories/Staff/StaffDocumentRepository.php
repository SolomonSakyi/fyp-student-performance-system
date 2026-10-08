<?php

/**
 * StaffDocumentRepository.php
 * Repository for staff document operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Staff
 * @filepath app/repositories/Staff/StaffDocumentRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class StaffDocumentRepository
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO staff_documents (
            staff_person_id, tenant_id, document_type, document_name,
            description, document_path, document_size, mime_type,
            is_required, is_verified, expiry_date, notes, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $this->db->execute($sql, [
            $data['staff_person_id'],
            $data['tenant_id'],
            $data['document_type'],
            $data['document_name'],
            $data['description'] ?? null,
            $data['document_path'],
            $data['document_size'] ?? null,
            $data['mime_type'] ?? null,
            $data['is_required'] ?? 0,
            $data['is_verified'] ?? 0,
            $data['expiry_date'] ?? null,
            $data['notes'] ?? null,
            $data['created_by']
        ]);

        return $this->db->lastInsertId();
    }

    public function getByStaffId(int $staffPersonId): array
    {
        $sql = "SELECT * FROM staff_documents 
                WHERE staff_person_id = ? AND deleted_at IS NULL
                ORDER BY created_at DESC";
        return $this->db->fetchAll($sql, [$staffPersonId]);
    }

    public function getById(int $id): ?array
    {
        $sql = "SELECT * FROM staff_documents WHERE id = ? AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function update(int $id, array $data): bool
    {
        $updates = [];
        $params = [];

        $allowedFields = [
            'document_type',
            'document_name',
            'description',
            'document_path',
            'document_size',
            'mime_type',
            'is_required',
            'is_verified',
            'expiry_date',
            'notes'
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
        $sql = "UPDATE staff_documents SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, $params);
    }

    public function verify(int $id, int $verifiedBy): bool
    {
        $sql = "UPDATE staff_documents SET is_verified = 1, verified_by = ?, verified_at = NOW(), updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$verifiedBy, $id]);
    }

    public function delete(int $id): bool
    {
        $sql = "UPDATE staff_documents SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }

    /**
     * Delete all documents for a staff person
     */
    public function deleteByStaffId(int $staffPersonId): bool
    {
        $sql = "UPDATE staff_documents SET deleted_at = NOW() WHERE staff_person_id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$staffPersonId]);
    }
}
