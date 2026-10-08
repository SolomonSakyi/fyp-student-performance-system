<?php

/**
 * StudentRepository.php
 * Repository for student data operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Student
 * @filepath app/repositories/Student/StudentRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class StudentRepository
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Get student by person ID
     */
    public function getByPersonId(int $personId): ?array
    {
        $sql = "SELECT s.*,
                sch.school_name,
                cmp.campus_name,
                cls.class_name,
                cls.class_code
                FROM students s
                LEFT JOIN schools sch ON s.school_id = sch.id
                LEFT JOIN campuses cmp ON s.campus_id = cmp.id
                LEFT JOIN classes cls ON s.class_id = cls.id
                WHERE s.person_id = ? AND s.deleted_at IS NULL";
        $result = $this->db->fetchOne($sql, [$personId]);
        return $result ?: null;
    }

    /**
     * Get student by ID
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT s.*,
                sch.school_name,
                cmp.campus_name,
                cls.class_name,
                cls.class_code
                FROM students s
                LEFT JOIN schools sch ON s.school_id = sch.id
                LEFT JOIN campuses cmp ON s.campus_id = cmp.id
                LEFT JOIN classes cls ON s.class_id = cls.id
                WHERE s.id = ? AND s.deleted_at IS NULL";
        $result = $this->db->fetchOne($sql, [$id]);
        return $result ?: null;
    }

    /**
     * Get student by student number
     */
    public function getByStudentNumber(string $studentNumber, ?int $tenantId = null): ?array
    {
        $sql = "SELECT * FROM students WHERE student_number = ? AND deleted_at IS NULL";
        $params = [$studentNumber];
        if ($tenantId) {
            $sql .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }
        $result = $this->db->fetchOne($sql, $params);
        return $result ?: null;
    }

    /**
     * Get student statistics
     */
    public function getStats(?int $tenantId = null): array
    {
        try {
            $params = [];
            $where = ["s.deleted_at IS NULL"];

            if ($tenantId) {
                $where[] = "s.tenant_id = ?";
                $params[] = $tenantId;
            }

            $whereClause = "WHERE " . implode(" AND ", $where);

            $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN s.enrollment_status = 'Enrolled' THEN 1 ELSE 0 END) as enrolled,
                        SUM(CASE WHEN s.enrollment_status = 'Active' THEN 1 ELSE 0 END) as active,
                        SUM(CASE WHEN s.enrollment_status = 'Suspended' THEN 1 ELSE 0 END) as suspended,
                        SUM(CASE WHEN s.enrollment_status = 'Graduated' THEN 1 ELSE 0 END) as graduated,
                        SUM(CASE WHEN s.enrollment_status = 'Withdrawn' THEN 1 ELSE 0 END) as withdrawn,
                        SUM(CASE WHEN s.enrollment_status = 'Transferred' THEN 1 ELSE 0 END) as transferred,
                        SUM(CASE WHEN s.is_active = 1 THEN 1 ELSE 0 END) as is_active_count,
                        SUM(CASE WHEN s.gender = 'Male' THEN 1 ELSE 0 END) as male,
                        SUM(CASE WHEN s.gender = 'Female' THEN 1 ELSE 0 END) as female
                    FROM students s
                    $whereClause";

            $result = $this->db->fetchOne($sql, $params);

            if (!$result) {
                return [
                    'total' => 0,
                    'enrolled' => 0,
                    'active' => 0,
                    'suspended' => 0,
                    'graduated' => 0,
                    'withdrawn' => 0,
                    'transferred' => 0,
                    'is_active_count' => 0,
                    'male' => 0,
                    'female' => 0
                ];
            }

            return [
                'total' => (int)($result['total'] ?? 0),
                'enrolled' => (int)($result['enrolled'] ?? 0),
                'active' => (int)($result['active'] ?? 0),
                'suspended' => (int)($result['suspended'] ?? 0),
                'graduated' => (int)($result['graduated'] ?? 0),
                'withdrawn' => (int)($result['withdrawn'] ?? 0),
                'transferred' => (int)($result['transferred'] ?? 0),
                'is_active_count' => (int)($result['is_active_count'] ?? 0),
                'male' => (int)($result['male'] ?? 0),
                'female' => (int)($result['female'] ?? 0)
            ];
        } catch (Exception $e) {
            error_log('StudentRepository::getStats error: ' . $e->getMessage());
            return [
                'total' => 0,
                'enrolled' => 0,
                'active' => 0,
                'suspended' => 0,
                'graduated' => 0,
                'withdrawn' => 0,
                'transferred' => 0,
                'is_active_count' => 0,
                'male' => 0,
                'female' => 0
            ];
        }
    }
}
