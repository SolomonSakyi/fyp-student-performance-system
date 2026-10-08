<?php

/**
 * StudentRepository.php
 * Repository for student profile operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Identity
 * @filepath app/repositories/Identity/StudentRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class StudentRepository
{
    private $db;
    private $logger;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = LoggerHelper::getInstance();
    }

    /**
     * Generate a unique student number
     */
    public function generateStudentNumber(): string
    {
        $prefix = 'STU';
        $year = date('Y');
        $maxNumber = $this->db->getValue(
            "SELECT MAX(CAST(SUBSTRING(student_number, -6) AS UNSIGNED)) 
             FROM student_profiles 
             WHERE student_number LIKE ? AND deleted_at IS NULL",
            [$prefix . '-' . $year . '-%']
        );
        $nextId = ($maxNumber ?? 0) + 1;
        return $prefix . '-' . $year . '-' . str_pad($nextId, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Check if student number exists
     */
    public function studentNumberExists(string $studentNumber): bool
    {
        return (bool) $this->db->getValue(
            "SELECT COUNT(*) FROM student_profiles WHERE student_number = ? AND deleted_at IS NULL",
            [$studentNumber]
        );
    }

    /**
     * Create student profile - Matches your actual table structure
     */
    public function create(int $personId, array $data): array
    {
        try {
            // Generate student number if not provided
            if (empty($data['student_number'])) {
                $data['student_number'] = $this->generateStudentNumber();
            }

            // Validate uniqueness
            if ($this->studentNumberExists($data['student_number'])) {
                throw new Exception('Student number already exists: ' . $data['student_number']);
            }

            // Check if student profile already exists
            $existing = $this->getByPersonId($personId);
            if ($existing) {
                throw new Exception('Student profile already exists for this person');
            }

            // Build SQL with only columns that exist in your table
            $sql = "INSERT INTO student_profiles (
                person_id, 
                student_number,
                school_id,
                campus_id,
                level,
                class,
                programme,
                enrollment_status,
                student_status,
                date_joined,
                entry_level,
                entry_class,
                created_by
            ) VALUES (
                :person_id, 
                :student_number,
                :school_id,
                :campus_id,
                :level,
                :class,
                :programme,
                :enrollment_status,
                :student_status,
                :date_joined,
                :entry_level,
                :entry_class,
                :created_by
            )";

            $params = [
                ':person_id' => $personId,
                ':student_number' => $data['student_number'],
                ':school_id' => $data['school_id'] ?? null,
                ':campus_id' => $data['campus_id'] ?? null,
                ':level' => $data['level'] ?? null,
                ':class' => $data['class'] ?? null,
                ':programme' => $data['programme'] ?? null,
                ':enrollment_status' => $data['enrollment_status'] ?? 'active',
                ':student_status' => $data['student_status'] ?? 'active',
                ':date_joined' => $data['date_joined'] ?? date('Y-m-d'),
                ':entry_level' => $data['entry_level'] ?? null,
                ':entry_class' => $data['entry_class'] ?? null,
                ':created_by' => $data['created_by'] ?? null
            ];

            $this->logger->debug('Student create SQL: ' . $sql);
            $this->logger->debug('Student create params: ' . json_encode($params));

            $result = $this->db->execute($sql, $params);

            if (!$result) {
                // Get the last error
                $errorInfo = $this->db->getConnection()->errorInfo();
                throw new Exception('Database insert failed for student profile: ' . ($errorInfo[2] ?? 'Unknown error'));
            }

            $studentId = (int) $this->db->lastInsertId();

            if ($studentId <= 0) {
                throw new Exception('Failed to create student profile - no ID returned');
            }

            // Get the created student profile
            $student = $this->getById($studentId);

            if (empty($student)) {
                throw new Exception('Failed to retrieve created student profile');
            }

            return $student;
        } catch (Exception $e) {
            $this->logger->error('StudentRepository::create error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get student profile by ID
     */
    public function getById(int $studentId): ?array
    {
        $sql = "SELECT * FROM student_profiles WHERE id = :id AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [':id' => $studentId]);
    }

    /**
     * Get student profile by person ID
     */
    public function getByPersonId(int $personId): ?array
    {
        $sql = "SELECT * FROM student_profiles WHERE person_id = :person_id AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [':person_id' => $personId]);
    }

    /**
     * Get student profile by student number
     */
    public function getByStudentNumber(string $studentNumber): ?array
    {
        $sql = "SELECT * FROM student_profiles WHERE student_number = :student_number AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [':student_number' => $studentNumber]);
    }

    /**
     * Update student profile
     */
    public function update(int $studentId, array $data): array
    {
        $fields = [];
        $params = [':id' => $studentId];
        $allowedFields = [
            'level',
            'class',
            'stream',
            'programme',
            'curriculum',
            'enrollment_status',
            'student_status',
            'date_joined',
            'entry_level',
            'entry_class',
            'school_id',
            'campus_id'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($fields)) {
            throw new Exception('No fields to update');
        }

        $sql = "UPDATE student_profiles SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $this->db->execute($sql, $params);

        return $this->getById($studentId);
    }

    /**
     * Delete student profile
     */
    public function delete(int $studentId, int $deletedBy = null): bool
    {
        $sql = "UPDATE student_profiles SET deleted_at = NOW(), updated_by = :updated_by WHERE id = :id";
        return $this->db->execute($sql, [':id' => $studentId, ':updated_by' => $deletedBy]);
    }

    /**
     * Get student with person details
     */
    public function getFullDetails(int $studentId): array
    {
        $sql = "SELECT 
                    sp.*, 
                    p.id as person_id,
                    p.first_name, p.last_name, p.middle_name,
                    p.person_number, p.uuid,
                    CONCAT(p.first_name, ' ', p.last_name) as full_name
                FROM student_profiles sp
                INNER JOIN people p ON sp.person_id = p.id
                WHERE sp.id = :id AND sp.deleted_at IS NULL AND p.deleted_at IS NULL";

        $result = $this->db->fetchOne($sql, [':id' => $studentId]);

        if (!$result) {
            throw new Exception('Student details not found');
        }

        return $result;
    }
}
