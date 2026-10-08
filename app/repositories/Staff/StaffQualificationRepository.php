<?php

/**
 * StaffQualificationRepository.php
 * Repository for staff qualification operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Staff
 * @filepath app/repositories/Staff/StaffQualificationRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class StaffQualificationRepository
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO staff_qualifications (
            staff_person_id, tenant_id, qualification_type, qualification_name,
            institution, field_of_study, qualification_level, year_awarded,
            completion_date, graduation_date, grade, certificate_number,
            document_path, document_name, is_verified, notes, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $this->db->execute($sql, [
            $data['staff_person_id'],
            $data['tenant_id'],
            $data['qualification_type'] ?? 'academic',
            $data['qualification_name'],
            $data['institution'],
            $data['field_of_study'] ?? null,
            $data['qualification_level'] ?? null,
            $data['year_awarded'] ?? null,
            $data['completion_date'] ?? null,
            $data['graduation_date'] ?? null,
            $data['grade'] ?? null,
            $data['certificate_number'] ?? null,
            $data['document_path'] ?? null,
            $data['document_name'] ?? null,
            $data['is_verified'] ?? 0,
            $data['notes'] ?? null,
            $data['created_by']
        ]);

        return $this->db->lastInsertId();
    }

    public function getByStaffId(int $staffPersonId): array
    {
        $sql = "SELECT * FROM staff_qualifications 
                WHERE staff_person_id = ? AND deleted_at IS NULL
                ORDER BY year_awarded DESC, created_at DESC";
        return $this->db->fetchAll($sql, [$staffPersonId]);
    }

    public function getById(int $id): ?array
    {
        $sql = "SELECT * FROM staff_qualifications WHERE id = ? AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function update(int $id, array $data): bool
    {
        $updates = [];
        $params = [];

        $allowedFields = [
            'qualification_type',
            'qualification_name',
            'institution',
            'field_of_study',
            'qualification_level',
            'year_awarded',
            'completion_date',
            'graduation_date',
            'grade',
            'certificate_number',
            'document_path',
            'document_name',
            'is_verified',
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
        $sql = "UPDATE staff_qualifications SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, $params);
    }

    public function verify(int $id, int $verifiedBy): bool
    {
        $sql = "UPDATE staff_qualifications SET is_verified = 1, verified_by = ?, verified_at = NOW(), updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$verifiedBy, $id]);
    }

    public function delete(int $id): bool
    {
        $sql = "UPDATE staff_qualifications SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }
    
    /**
     * Delete all qualifications for a staff person
     */
    public function deleteByStaffId(int $staffPersonId): bool
    {
        $sql = "UPDATE staff_qualifications SET deleted_at = NOW() WHERE staff_person_id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$staffPersonId]);
    }
}
