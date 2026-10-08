<?php

/**
 * StudentAdmissionRepository.php
 * Repository for student admission records
 * 
 * @package EduTrack
 * @subpackage Repositories\Student
 * @filepath app/repositories/Student/StudentAdmissionRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class StudentAdmissionRepository
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Create admission record
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO student_admission_records (
            student_person_id, tenant_id, admission_date, admission_type,
            admission_year, application_date, application_number, entry_level,
            entry_class, previous_school, transfer_reason, placement_test_score,
            interview_notes, admission_status, offer_letter_sent, acceptance_date,
            enrollment_date, notes, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $this->db->execute($sql, [
            $data['student_person_id'],
            $data['tenant_id'],
            $data['admission_date'],
            $data['admission_type'] ?? 'new',
            $data['admission_year'],
            $data['application_date'] ?? null,
            $data['application_number'] ?? null,
            $data['entry_level'] ?? null,
            $data['entry_class'] ?? null,
            $data['previous_school'] ?? null,
            $data['transfer_reason'] ?? null,
            $data['placement_test_score'] ?? null,
            $data['interview_notes'] ?? null,
            $data['admission_status'] ?? 'pending',
            $data['offer_letter_sent'] ?? 0,
            $data['acceptance_date'] ?? null,
            $data['enrollment_date'] ?? null,
            $data['notes'] ?? null,
            $data['created_by']
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Get admission records by student
     */
    public function getByStudentId(int $studentPersonId): array
    {
        $sql = "SELECT * FROM student_admission_records 
                WHERE student_person_id = ? AND deleted_at IS NULL
                ORDER BY admission_date DESC";
        return $this->db->fetchAll($sql, [$studentPersonId]);
    }

    /**
     * Get latest admission record
     */
    public function getLatestByStudentId(int $studentPersonId): ?array
    {
        $sql = "SELECT * FROM student_admission_records 
                WHERE student_person_id = ? AND deleted_at IS NULL
                ORDER BY admission_date DESC LIMIT 1";
        return $this->db->fetchOne($sql, [$studentPersonId]);
    }

    /**
     * Update admission record
     */
    public function update(int $id, array $data): bool
    {
        $updates = [];
        $params = [];

        $allowedFields = [
            'admission_date',
            'admission_type',
            'admission_year',
            'application_date',
            'application_number',
            'entry_level',
            'entry_class',
            'previous_school',
            'transfer_reason',
            'placement_test_score',
            'interview_notes',
            'admission_status',
            'offer_letter_sent',
            'acceptance_date',
            'enrollment_date',
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
        $sql = "UPDATE student_admission_records SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, $params);
    }

    /**
     * Delete admission record
     */
    public function delete(int $id): bool
    {
        $sql = "UPDATE student_admission_records SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }
}
