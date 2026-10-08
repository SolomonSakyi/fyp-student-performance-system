<?php

/**
 * PersonAssignmentRepository.php
 * Repository for person organizational assignments
 * 
 * @package EduTrack
 * @subpackage Repositories\Identity
 * @filepath app/repositories/Identity/PersonAssignmentRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class PersonAssignmentRepository
{
    private $db;
    private $logger;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
    }

    /**
     * Create a person assignment
     */
    public function create(array $data): int
    {
        try {
            // Validate tenant_id exists if provided
            if (!empty($data['tenant_id'])) {
                $tenantCheck = $this->db->fetchOne("SELECT id FROM tenants WHERE id = ?", [$data['tenant_id']]);
                if (!$tenantCheck) {
                    $this->logger->warning('Invalid tenant_id in assignment: ' . $data['tenant_id']);
                    return 0; // Return 0 to indicate failure
                }
            }

            $sql = "INSERT INTO person_organizational_assignments (
                person_id,
                tenant_id,
                school_id,
                campus_id,
                department_id,
                role_id,
                assignment_type,
                start_date,
                end_date,
                status,
                is_primary,
                assigned_by,
                assigned_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $params = [
                $data['person_id'],
                $data['tenant_id'] ?? null,
                $data['school_id'] ?? null,
                $data['campus_id'] ?? null,
                $data['department_id'] ?? null,
                $data['role_id'] ?? null,
                $data['assignment_type'] ?? 'primary',
                $data['start_date'] ?? date('Y-m-d'),
                $data['end_date'] ?? null,
                $data['status'] ?? 'active',
                $data['is_primary'] ?? 1,
                $data['assigned_by'] ?? null
            ];

            $this->db->execute($sql, $params);
            return $this->db->lastInsertId();
        } catch (Exception $e) {
            $this->logger->error('PersonAssignmentRepository::create error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get assignments by person ID
     */
    public function getByPersonId(int $personId): array
    {
        $sql = "SELECT poa.*, 
                t.tenant_name, 
                s.school_name, 
                c.campus_name, 
                pr.role_code, 
                pr.role_name
                FROM person_organizational_assignments poa
                LEFT JOIN tenants t ON poa.tenant_id = t.id
                LEFT JOIN schools s ON poa.school_id = s.id
                LEFT JOIN campuses c ON poa.campus_id = c.id
                LEFT JOIN platform_roles pr ON poa.role_id = pr.id
                WHERE poa.person_id = ? AND poa.deleted_at IS NULL
                ORDER BY poa.is_primary DESC, poa.created_at DESC";
        return $this->db->fetchAll($sql, [$personId]);
    }

    /**
     * Get active assignments by person ID
     */
    public function getActiveByPersonId(int $personId): array
    {
        $sql = "SELECT poa.* FROM person_organizational_assignments poa
                WHERE poa.person_id = ? AND poa.status = 'active' AND poa.deleted_at IS NULL";
        return $this->db->fetchAll($sql, [$personId]);
    }

    /**
     * Get assignment by ID
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT * FROM person_organizational_assignments WHERE id = ? AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$id]);
    }

    /**
     * Update assignment
     */
    public function update(int $assignmentId, array $data): bool
    {
        try {
            $updates = [];
            $params = [];

            $allowedFields = [
                'tenant_id',
                'school_id',
                'campus_id',
                'department_id',
                'role_id',
                'assignment_type',
                'start_date',
                'end_date',
                'status',
                'is_primary'
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

            $params[] = $assignmentId;
            $sql = "UPDATE person_organizational_assignments SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
            return $this->db->execute($sql, $params);
        } catch (Exception $e) {
            $this->logger->error('PersonAssignmentRepository::update error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update assignment status
     */
    public function updateStatus(int $assignmentId, string $status): bool
    {
        $sql = "UPDATE person_organizational_assignments SET status = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$status, $assignmentId]);
    }

    /**
     * Delete assignment (soft delete)
     */
    public function delete(int $assignmentId): bool
    {
        $sql = "UPDATE person_organizational_assignments SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$assignmentId]);
    }

    /**
     * Delete all assignments for a person
     */
    public function deleteByPersonId(int $personId): bool
    {
        $sql = "UPDATE person_organizational_assignments SET deleted_at = NOW() WHERE person_id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$personId]);
    }

    /**
     * Restore assignments for a person
     */
    public function restoreByPersonId(int $personId): bool
    {
        $sql = "UPDATE person_organizational_assignments SET deleted_at = NULL WHERE person_id = ? AND deleted_at IS NOT NULL";
        return $this->db->execute($sql, [$personId]);
    }

    /**
     * Validate organizational hierarchy
     */
    public function validateHierarchy(int $tenantId, int $schoolId, ?int $campusId = null): bool
    {
        try {
            // Validate tenant exists
            $tenant = $this->db->fetchOne("SELECT id FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
            if (!$tenant) {
                return false;
            }

            // Validate school belongs to tenant
            $school = $this->db->fetchOne("SELECT id FROM schools WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL", [$schoolId, $tenantId]);
            if (!$school) {
                return false;
            }

            // Validate campus belongs to school if provided
            if ($campusId) {
                $campus = $this->db->fetchOne("SELECT id FROM campuses WHERE id = ? AND school_id = ? AND deleted_at IS NULL", [$campusId, $schoolId]);
                if (!$campus) {
                    return false;
                }
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error('validateHierarchy error: ' . $e->getMessage());
            return false;
        }
    }
}
