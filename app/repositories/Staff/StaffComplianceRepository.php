<?php

/**
 * StaffComplianceRepository.php
 * Repository for staff compliance operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Staff
 * @filepath app/repositories/Staff/StaffComplianceRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class StaffComplianceRepository
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO staff_compliance (
            staff_person_id, tenant_id, compliance_type, compliance_name,
            description, requirement_date, completed_date, expiry_date,
            status, is_mandatory, evidence_path, evidence_name, notes,
            created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $this->db->execute($sql, [
            $data['staff_person_id'],
            $data['tenant_id'],
            $data['compliance_type'],
            $data['compliance_name'],
            $data['description'] ?? null,
            $data['requirement_date'],
            $data['completed_date'] ?? null,
            $data['expiry_date'] ?? null,
            $data['status'] ?? 'pending',
            $data['is_mandatory'] ?? 1,
            $data['evidence_path'] ?? null,
            $data['evidence_name'] ?? null,
            $data['notes'] ?? null,
            $data['created_by']
        ]);

        return $this->db->lastInsertId();
    }

    public function getByStaffId(int $staffPersonId): array
    {
        $sql = "SELECT * FROM staff_compliance 
                WHERE staff_person_id = ? AND deleted_at IS NULL
                ORDER BY requirement_date DESC";
        return $this->db->fetchAll($sql, [$staffPersonId]);
    }

    public function getById(int $id): ?array
    {
        $sql = "SELECT * FROM staff_compliance WHERE id = ? AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function getPending(int $staffPersonId): array
    {
        $sql = "SELECT * FROM staff_compliance 
                WHERE staff_person_id = ? AND status IN ('pending', 'in_progress') AND deleted_at IS NULL
                ORDER BY requirement_date ASC";
        return $this->db->fetchAll($sql, [$staffPersonId]);
    }

    public function getExpired(int $staffPersonId): array
    {
        $sql = "SELECT * FROM staff_compliance 
                WHERE staff_person_id = ? AND expiry_date IS NOT NULL AND expiry_date < CURDATE() AND deleted_at IS NULL
                ORDER BY expiry_date ASC";
        return $this->db->fetchAll($sql, [$staffPersonId]);
    }

    public function update(int $id, array $data): bool
    {
        $updates = [];
        $params = [];

        $allowedFields = [
            'compliance_type',
            'compliance_name',
            'description',
            'requirement_date',
            'completed_date',
            'expiry_date',
            'status',
            'is_mandatory',
            'evidence_path',
            'evidence_name',
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
        $sql = "UPDATE staff_compliance SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, $params);
    }

    public function updateStatus(int $id, string $status): bool
    {
        $sql = "UPDATE staff_compliance SET status = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$status, $id]);
    }

    public function verify(int $id, int $verifiedBy): bool
    {
        $sql = "UPDATE staff_compliance SET verified_by = ?, verified_at = NOW(), updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$verifiedBy, $id]);
    }

    public function delete(int $id): bool
    {
        $sql = "UPDATE staff_compliance SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }

    /**
     * Delete all compliance records for a staff person
     */
    public function deleteByStaffId(int $staffPersonId): bool
    {
        $sql = "UPDATE staff_compliance SET deleted_at = NOW() WHERE staff_person_id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$staffPersonId]);
    }
}
