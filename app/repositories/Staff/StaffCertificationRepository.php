<?php

/**
 * StaffCertificationRepository.php
 * Repository for staff certification operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Staff
 * @filepath app/repositories/Staff/StaffCertificationRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class StaffCertificationRepository
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO staff_certifications (
            staff_person_id, tenant_id, certification_name, issuing_authority,
            certification_number, issue_date, expiry_date, renewal_date,
            certification_level, is_active, is_verified, document_path,
            document_name, notes, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $this->db->execute($sql, [
            $data['staff_person_id'],
            $data['tenant_id'],
            $data['certification_name'],
            $data['issuing_authority'],
            $data['certification_number'],
            $data['issue_date'],
            $data['expiry_date'] ?? null,
            $data['renewal_date'] ?? null,
            $data['certification_level'] ?? null,
            $data['is_active'] ?? 1,
            $data['is_verified'] ?? 0,
            $data['document_path'] ?? null,
            $data['document_name'] ?? null,
            $data['notes'] ?? null,
            $data['created_by']
        ]);

        return $this->db->lastInsertId();
    }

    public function getByStaffId(int $staffPersonId): array
    {
        $sql = "SELECT * FROM staff_certifications 
                WHERE staff_person_id = ? AND deleted_at IS NULL
                ORDER BY issue_date DESC";
        return $this->db->fetchAll($sql, [$staffPersonId]);
    }

    public function getById(int $id): ?array
    {
        $sql = "SELECT * FROM staff_certifications WHERE id = ? AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function getExpiringSoon(int $days = 30): array
    {
        $sql = "SELECT sc.*, p.first_name, p.last_name 
                FROM staff_certifications sc
                JOIN people p ON sc.staff_person_id = p.id
                WHERE sc.expiry_date IS NOT NULL 
                AND sc.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
                AND sc.is_active = 1 AND sc.deleted_at IS NULL
                ORDER BY sc.expiry_date ASC";
        return $this->db->fetchAll($sql, [$days]);
    }

    public function update(int $id, array $data): bool
    {
        $updates = [];
        $params = [];

        $allowedFields = [
            'certification_name',
            'issuing_authority',
            'certification_number',
            'issue_date',
            'expiry_date',
            'renewal_date',
            'certification_level',
            'is_active',
            'is_verified',
            'document_path',
            'document_name',
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
        $sql = "UPDATE staff_certifications SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, $params);
    }

    public function verify(int $id, int $verifiedBy): bool
    {
        $sql = "UPDATE staff_certifications SET is_verified = 1, verified_by = ?, verified_at = NOW(), updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$verifiedBy, $id]);
    }

    public function delete(int $id): bool
    {
        $sql = "UPDATE staff_certifications SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }

    /**
     * Delete all certifications for a staff person
     */
    public function deleteByStaffId(int $staffPersonId): bool
    {
        $sql = "UPDATE staff_certifications SET deleted_at = NOW() WHERE staff_person_id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$staffPersonId]);
    }
}
