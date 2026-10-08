<?php

/**
 * StaffRepository.php
 * Repository for staff data operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Staff
 * @filepath app/repositories/Staff/StaffRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class StaffRepository
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Get staff by person ID
     */
    public function getByPersonId(int $personId): ?array
    {
        $sql = "SELECT s.*,
                p.first_name, p.last_name, p.middle_name, p.email, p.primary_phone,
                p.date_of_birth, p.gender, p.nationality,
                sc.category_name as staff_category,
                d.department_name,
                des.designation_name,
                et.employment_type_name,
                ss.status_name as staff_status,
                sch.school_name,
                cmp.campus_name,
                pu.username as platform_username
                FROM staff s
                JOIN persons p ON s.person_id = p.id
                LEFT JOIN staff_categories sc ON s.staff_category_id = sc.id
                LEFT JOIN departments d ON s.department_id = d.id
                LEFT JOIN designations des ON s.designation_id = des.id
                LEFT JOIN employment_types et ON s.employment_type_id = et.id
                LEFT JOIN staff_statuses ss ON s.staff_status_id = ss.id
                LEFT JOIN schools sch ON s.school_id = sch.id
                LEFT JOIN campuses cmp ON s.campus_id = cmp.id
                LEFT JOIN platform_users pu ON s.platform_user_id = pu.id
                WHERE s.person_id = ? AND s.deleted_at IS NULL";
        $result = $this->db->fetchOne($sql, [$personId]);
        return $result ?: null;
    }

    /**
     * Get staff by ID
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT s.*,
                p.first_name, p.last_name, p.middle_name, p.email, p.primary_phone,
                p.date_of_birth, p.gender, p.nationality,
                sc.category_name as staff_category,
                d.department_name,
                des.designation_name,
                et.employment_type_name,
                ss.status_name as staff_status,
                sch.school_name,
                cmp.campus_name
                FROM staff s
                JOIN persons p ON s.person_id = p.id
                LEFT JOIN staff_categories sc ON s.staff_category_id = sc.id
                LEFT JOIN departments d ON s.department_id = d.id
                LEFT JOIN designations des ON s.designation_id = des.id
                LEFT JOIN employment_types et ON s.employment_type_id = et.id
                LEFT JOIN staff_statuses ss ON s.staff_status_id = ss.id
                LEFT JOIN schools sch ON s.school_id = sch.id
                LEFT JOIN campuses cmp ON s.campus_id = cmp.id
                WHERE s.id = ? AND s.deleted_at IS NULL";
        $result = $this->db->fetchOne($sql, [$id]);
        return $result ?: null;
    }

    /**
     * Get staff by staff number
     */
    public function getByStaffNumber(string $staffNumber, ?int $tenantId = null): ?array
    {
        $sql = "SELECT s.*, p.first_name, p.last_name 
                FROM staff s
                JOIN persons p ON s.person_id = p.id
                WHERE s.staff_number = ? AND s.deleted_at IS NULL";
        $params = [$staffNumber];
        if ($tenantId) {
            $sql .= " AND s.tenant_id = ?";
            $params[] = $tenantId;
        }
        $result = $this->db->fetchOne($sql, $params);
        return $result ?: null;
    }

    /**
     * Get staff statistics
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
                        SUM(CASE WHEN s.is_active = 1 THEN 1 ELSE 0 END) as active,
                        SUM(CASE WHEN s.is_active = 0 THEN 1 ELSE 0 END) as inactive,
                        SUM(CASE WHEN s.is_teaching_staff = 1 THEN 1 ELSE 0 END) as teaching,
                        SUM(CASE WHEN s.is_teaching_staff = 0 THEN 1 ELSE 0 END) as non_teaching,
                        SUM(CASE WHEN s.staff_status_id = (SELECT id FROM staff_statuses WHERE status_name = 'active') THEN 1 ELSE 0 END) as status_active,
                        SUM(CASE WHEN s.staff_status_id = (SELECT id FROM staff_statuses WHERE status_name = 'on_leave') THEN 1 ELSE 0 END) as on_leave,
                        SUM(CASE WHEN s.staff_status_id = (SELECT id FROM staff_statuses WHERE status_name = 'suspended') THEN 1 ELSE 0 END) as suspended,
                        SUM(CASE WHEN s.staff_status_id = (SELECT id FROM staff_statuses WHERE status_name = 'terminated') THEN 1 ELSE 0 END) as terminated
                    FROM staff s
                    $whereClause";

            $result = $this->db->fetchOne($sql, $params);

            if (!$result) {
                return [
                    'total' => 0,
                    'active' => 0,
                    'inactive' => 0,
                    'teaching' => 0,
                    'non_teaching' => 0,
                    'status_active' => 0,
                    'on_leave' => 0,
                    'suspended' => 0,
                    'terminated' => 0
                ];
            }

            return [
                'total' => (int)($result['total'] ?? 0),
                'active' => (int)($result['active'] ?? 0),
                'inactive' => (int)($result['inactive'] ?? 0),
                'teaching' => (int)($result['teaching'] ?? 0),
                'non_teaching' => (int)($result['non_teaching'] ?? 0),
                'status_active' => (int)($result['status_active'] ?? 0),
                'on_leave' => (int)($result['on_leave'] ?? 0),
                'suspended' => (int)($result['suspended'] ?? 0),
                'terminated' => (int)($result['terminated'] ?? 0)
            ];
        } catch (Exception $e) {
            error_log('StaffRepository::getStats error: ' . $e->getMessage());
            return [
                'total' => 0,
                'active' => 0,
                'inactive' => 0,
                'teaching' => 0,
                'non_teaching' => 0,
                'status_active' => 0,
                'on_leave' => 0,
                'suspended' => 0,
                'terminated' => 0
            ];
        }
    }
}
