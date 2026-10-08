<?php

/**
 * StaffEmploymentHistoryRepository.php
 * Repository for staff employment history operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Staff
 * @filepath app/repositories/Staff/StaffEmploymentHistoryRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class StaffEmploymentHistoryRepository
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO staff_employment_history (
            staff_person_id, tenant_id, start_date, end_date,
            position_id, department_id, job_title, employer_name,
            employer_address, employer_phone, supervisor_name,
            supervisor_contact, reason_for_leaving, is_current,
            notes, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $this->db->execute($sql, [
            $data['staff_person_id'],
            $data['tenant_id'],
            $data['start_date'],
            $data['end_date'] ?? null,
            $data['position_id'] ?? null,
            $data['department_id'] ?? null,
            $data['job_title'],
            $data['employer_name'],
            $data['employer_address'] ?? null,
            $data['employer_phone'] ?? null,
            $data['supervisor_name'] ?? null,
            $data['supervisor_contact'] ?? null,
            $data['reason_for_leaving'] ?? null,
            $data['is_current'] ?? 0,
            $data['notes'] ?? null,
            $data['created_by']
        ]);

        return $this->db->lastInsertId();
    }

    public function getByStaffId(int $staffPersonId): array
    {
        $sql = "SELECT * FROM staff_employment_history 
                WHERE staff_person_id = ? AND deleted_at IS NULL
                ORDER BY start_date DESC";
        return $this->db->fetchAll($sql, [$staffPersonId]);
    }

    public function getById(int $id): ?array
    {
        $sql = "SELECT * FROM staff_employment_history WHERE id = ? AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function getCurrent(int $staffPersonId): ?array
    {
        $sql = "SELECT * FROM staff_employment_history 
                WHERE staff_person_id = ? AND is_current = 1 AND deleted_at IS NULL
                LIMIT 1";
        return $this->db->fetchOne($sql, [$staffPersonId]);
    }

    public function update(int $id, array $data): bool
    {
        $updates = [];
        $params = [];

        $allowedFields = [
            'start_date',
            'end_date',
            'position_id',
            'department_id',
            'job_title',
            'employer_name',
            'employer_address',
            'employer_phone',
            'supervisor_name',
            'supervisor_contact',
            'reason_for_leaving',
            'is_current',
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
        $sql = "UPDATE staff_employment_history SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, $params);
    }

    public function delete(int $id): bool
    {
        $sql = "UPDATE staff_employment_history SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }

    /**
     * Delete all employment history for a staff person
     */
    public function deleteByStaffId(int $staffPersonId): bool
    {
        $sql = "UPDATE staff_employment_history SET deleted_at = NOW() WHERE staff_person_id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$staffPersonId]);
    }
}
