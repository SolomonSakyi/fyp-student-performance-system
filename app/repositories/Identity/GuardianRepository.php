<?php

/**
 * GuardianRepository.php
 * Repository for guardian profile operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Identity
 * @filepath app/repositories/Identity/GuardianRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class GuardianRepository
{
    private $db;
    private $logger;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = LoggerHelper::getInstance();
    }

    /**
     * Generate a unique guardian number
     */
    public function generateGuardianNumber(): string
    {
        $prefix = 'GUA';
        $year = date('Y');
        $maxNumber = $this->db->getValue(
            "SELECT MAX(CAST(SUBSTRING(guardian_number, -6) AS UNSIGNED)) 
             FROM guardian_profiles 
             WHERE guardian_number LIKE ? AND deleted_at IS NULL",
            [$prefix . '-' . $year . '-%']
        );
        $nextId = ($maxNumber ?? 0) + 1;
        return $prefix . '-' . $year . '-' . str_pad($nextId, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Create guardian profile
     */
    public function create(int $personId, array $data): array
    {
        try {
            if (empty($data['guardian_number'])) {
                $data['guardian_number'] = $this->generateGuardianNumber();
            }

            $existing = $this->getByPersonId($personId);
            if ($existing) {
                throw new Exception('Guardian profile already exists for this person');
            }

            // Check what columns exist in guardian_profiles
            // For now, use minimal columns
            $sql = "INSERT INTO guardian_profiles (
                person_id, 
                guardian_number,
                guardian_type,
                created_by
            ) VALUES (
                :person_id, 
                :guardian_number,
                :guardian_type,
                :created_by
            )";

            $params = [
                ':person_id' => $personId,
                ':guardian_number' => $data['guardian_number'],
                ':guardian_type' => $data['guardian_type'] ?? 'parent',
                ':created_by' => $data['created_by'] ?? null
            ];

            $this->db->execute($sql, $params);
            $guardianId = $this->db->lastInsertId();

            return $this->getById($guardianId);
        } catch (Exception $e) {
            $this->logger->error('GuardianRepository::create error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get guardian profile by ID
     */
    public function getById(int $guardianId): array
    {
        $sql = "SELECT * FROM guardian_profiles WHERE id = :id AND deleted_at IS NULL";
        $result = $this->db->fetchOne($sql, [':id' => $guardianId]);

        if (!$result) {
            throw new Exception('Guardian profile not found');
        }

        return $result;
    }

    /**
     * Get guardian profile by person ID
     */
    public function getByPersonId(int $personId): ?array
    {
        $sql = "SELECT * FROM guardian_profiles WHERE person_id = :person_id AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [':person_id' => $personId]);
    }

    /**
     * Create student-guardian relationship
     */
    public function createRelationship(int $studentId, int $guardianId, array $data): array
    {
        try {
            $sql = "INSERT INTO student_guardian_relationships (
                student_id, guardian_id, relationship_type,
                is_primary_guardian, is_emergency_contact,
                notes, created_by
            ) VALUES (
                :student_id, :guardian_id, :relationship_type,
                :is_primary_guardian, :is_emergency_contact,
                :notes, :created_by
            )";

            $params = [
                ':student_id' => $studentId,
                ':guardian_id' => $guardianId,
                ':relationship_type' => $data['relationship_type'] ?? 'other',
                ':is_primary_guardian' => $data['is_primary_guardian'] ?? 0,
                ':is_emergency_contact' => $data['is_emergency_contact'] ?? 0,
                ':notes' => $data['notes'] ?? null,
                ':created_by' => $data['created_by'] ?? null
            ];

            $this->db->execute($sql, $params);
            $relationshipId = $this->db->lastInsertId();

            $sql = "SELECT * FROM student_guardian_relationships WHERE id = :id";
            return $this->db->fetchOne($sql, [':id' => $relationshipId]);
        } catch (Exception $e) {
            $this->logger->error('GuardianRepository::createRelationship error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get guardians for a student
     */
    public function getStudentGuardians(int $studentId): array
    {
        $sql = "SELECT 
                    sgr.*,
                    p.id as person_id,
                    p.first_name, p.last_name, p.middle_name,
                    p.person_number
                FROM student_guardian_relationships sgr
                INNER JOIN guardian_profiles gp ON sgr.guardian_id = gp.id
                INNER JOIN people p ON gp.person_id = p.id
                WHERE sgr.student_id = :student_id 
                AND sgr.deleted_at IS NULL 
                AND gp.deleted_at IS NULL
                AND p.deleted_at IS NULL
                ORDER BY sgr.is_primary_guardian DESC";

        return $this->db->fetchAll($sql, [':student_id' => $studentId]);
    }
}
