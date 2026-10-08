<?php

/**
 * StaffTrainingRepository.php
 * Repository for staff training/CPD operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Staff
 * @filepath app/repositories/Staff/StaffTrainingRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class StaffTrainingRepository
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO staff_training (
            staff_person_id, tenant_id, training_name, provider,
            training_type, start_date, end_date, duration_hours,
            venue, certificate_issued, certificate_number, is_completed,
            is_verified, document_path, document_name, notes, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $this->db->execute($sql, [
            $data['staff_person_id'],
            $data['tenant_id'],
            $data['training_name'],
            $data['provider'],
            $data['training_type'] ?? null,
            $data['start_date'],
            $data['end_date'] ?? null,
            $data['duration_hours'] ?? null,
            $data['venue'] ?? null,
            $data['certificate_issued'] ?? 0,
            $data['certificate_number'] ?? null,
            $data['is_completed'] ?? 0,
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
        $sql = "SELECT * FROM staff_training 
                WHERE staff_person_id = ? AND deleted_at IS NULL
                ORDER BY start_date DESC";
        return $this->db->fetchAll($sql, [$staffPersonId]);
    }

    public function getById(int $id): ?array
    {
        $sql = "SELECT * FROM staff_training WHERE id = ? AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function update(int $id, array $data): bool
    {
        $updates = [];
        $params = [];

        $allowedFields = [
            'training_name',
            'provider',
            'training_type',
            'start_date',
            'end_date',
            'duration_hours',
            'venue',
            'certificate_issued',
            'certificate_number',
            'is_completed',
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
        $sql = "UPDATE staff_training SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, $params);
    }

    public function verify(int $id, int $verifiedBy): bool
    {
        $sql = "UPDATE staff_training SET is_verified = 1, verified_by = ?, verified_at = NOW(), updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$verifiedBy, $id]);
    }

    public function delete(int $id): bool
    {
        $sql = "UPDATE staff_training SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }

    /**
     * Delete all trainings for a staff person
     */
    public function deleteByStaffId(int $staffPersonId): bool
    {
        $sql = "UPDATE staff_training SET deleted_at = NOW() WHERE staff_person_id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$staffPersonId]);
    }
}
