<?php

/**
 * GuardianRepository.php
 * Repository for guardian/relationship operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Student
 * @filepath app/repositories/Student/GuardianRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class GuardianRepository
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Create a guardian profile
     */
    public function createGuardianProfile(array $data): int
    {
        $sql = "INSERT INTO guardian_profiles (
            person_id, tenant_id, relationship_type, is_primary,
            is_financial_responsible, is_emergency_contact, is_pickup_authorized,
            is_communication_authorized, can_collect_student, priority_order, notes,
            created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $this->db->execute($sql, [
            $data['person_id'],
            $data['tenant_id'],
            $data['relationship_type'],
            $data['is_primary'] ?? 0,
            $data['is_financial_responsible'] ?? 0,
            $data['is_emergency_contact'] ?? 1,
            $data['is_pickup_authorized'] ?? 1,
            $data['is_communication_authorized'] ?? 1,
            $data['can_collect_student'] ?? 1,
            $data['priority_order'] ?? 0,
            $data['notes'] ?? null,
            $data['created_by']
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Get guardian profile by person ID
     */
    public function getGuardianProfile(int $personId): ?array
    {
        $sql = "SELECT gp.*,
                p.first_name, p.last_name, p.middle_name, p.primary_email, p.primary_phone,
                p.date_of_birth, p.gender, p.nationality
                FROM guardian_profiles gp
                JOIN people p ON gp.person_id = p.id
                WHERE gp.person_id = ? AND gp.deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$personId]);
    }

    /**
     * Get all guardians for a student
     */
    public function getGuardiansByStudent(int $studentPersonId): array
    {
        $sql = "SELECT 
                    sgr.id as relationship_id,
                    sgr.relationship_type,
                    sgr.is_primary,
                    sgr.is_financial_responsible,
                    sgr.is_emergency_contact,
                    sgr.is_pickup_authorized,
                    sgr.is_communication_authorized,
                    sgr.can_collect_student,
                    sgr.priority_order,
                    sgr.notes as relationship_notes,
                    sgr.created_at as relationship_created_at,
                    p.id as person_id,
                    p.first_name,
                    p.last_name,
                    p.middle_name,
                    p.primary_email,
                    p.primary_phone,
                    p.date_of_birth,
                    p.gender,
                    p.nationality,
                    gp.is_primary as profile_is_primary,
                    gp.is_financial_responsible as profile_is_financial_responsible,
                    gp.is_emergency_contact as profile_is_emergency_contact,
                    gp.is_pickup_authorized as profile_is_pickup_authorized,
                    gp.is_communication_authorized as profile_is_communication_authorized,
                    gp.can_collect_student as profile_can_collect_student,
                    gp.notes as profile_notes
                FROM student_guardian_relationships sgr
                JOIN people p ON sgr.guardian_person_id = p.id
                LEFT JOIN guardian_profiles gp ON p.id = gp.person_id AND gp.deleted_at IS NULL
                WHERE sgr.student_person_id = ? AND sgr.deleted_at IS NULL
                ORDER BY sgr.is_primary DESC, sgr.priority_order ASC, sgr.created_at ASC";
        return $this->db->fetchAll($sql, [$studentPersonId]);
    }

    /**
     * Get a specific student-guardian relationship
     */
    public function getRelationship(int $studentPersonId, int $guardianPersonId): ?array
    {
        $sql = "SELECT * FROM student_guardian_relationships 
                WHERE student_person_id = ? AND guardian_person_id = ? AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$studentPersonId, $guardianPersonId]);
    }

    /**
     * Get relationship by ID
     */
    public function getRelationshipById(int $relationshipId): ?array
    {
        $sql = "SELECT * FROM student_guardian_relationships WHERE id = ? AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$relationshipId]);
    }

    /**
     * Create student-guardian relationship
     */
    public function createRelationship(array $data): int
    {
        $sql = "INSERT INTO student_guardian_relationships (
            student_person_id, guardian_person_id, tenant_id, relationship_type,
            is_primary, is_financial_responsible, is_emergency_contact,
            is_pickup_authorized, is_communication_authorized, can_collect_student,
            priority_order, notes, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $this->db->execute($sql, [
            $data['student_person_id'],
            $data['guardian_person_id'],
            $data['tenant_id'],
            $data['relationship_type'],
            $data['is_primary'] ?? 0,
            $data['is_financial_responsible'] ?? 0,
            $data['is_emergency_contact'] ?? 1,
            $data['is_pickup_authorized'] ?? 1,
            $data['is_communication_authorized'] ?? 1,
            $data['can_collect_student'] ?? 1,
            $data['priority_order'] ?? 0,
            $data['notes'] ?? null,
            $data['created_by']
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Update relationship
     */
    public function updateRelationship(int $id, array $data): bool
    {
        $updates = [];
        $params = [];

        $allowedFields = [
            'relationship_type',
            'is_primary',
            'is_financial_responsible',
            'is_emergency_contact',
            'is_pickup_authorized',
            'is_communication_authorized',
            'can_collect_student',
            'priority_order',
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
        $sql = "UPDATE student_guardian_relationships SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, $params);
    }

    /**
     * Delete relationship (soft delete)
     */
    public function deleteRelationship(int $id): bool
    {
        $sql = "UPDATE student_guardian_relationships SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }

    /**
     * Get primary guardian for a student
     */
    public function getPrimaryGuardian(int $studentPersonId): ?array
    {
        $sql = "SELECT 
                    sgr.*,
                    p.first_name, p.last_name, p.middle_name, p.primary_email, p.primary_phone
                FROM student_guardian_relationships sgr
                JOIN people p ON sgr.guardian_person_id = p.id
                WHERE sgr.student_person_id = ? AND sgr.is_primary = 1 AND sgr.deleted_at IS NULL
                LIMIT 1";
        return $this->db->fetchOne($sql, [$studentPersonId]);
    }

    /**
     * Get financial responsible guardians
     */
    public function getFinancialResponsibleGuardians(int $studentPersonId): array
    {
        $sql = "SELECT 
                    sgr.*,
                    p.first_name, p.last_name, p.primary_email, p.primary_phone
                FROM student_guardian_relationships sgr
                JOIN people p ON sgr.guardian_person_id = p.id
                WHERE sgr.student_person_id = ? AND sgr.is_financial_responsible = 1 AND sgr.deleted_at IS NULL
                ORDER BY sgr.is_primary DESC, sgr.priority_order ASC";
        return $this->db->fetchAll($sql, [$studentPersonId]);
    }

    /**
     * Get pickup authorized guardians
     */
    public function getPickupAuthorizedGuardians(int $studentPersonId): array
    {
        $sql = "SELECT 
                    sgr.*,
                    p.first_name, p.last_name, p.primary_email, p.primary_phone
                FROM student_guardian_relationships sgr
                JOIN people p ON sgr.guardian_person_id = p.id
                WHERE sgr.student_person_id = ? AND sgr.is_pickup_authorized = 1 AND sgr.deleted_at IS NULL
                ORDER BY sgr.is_primary DESC, sgr.priority_order ASC";
        return $this->db->fetchAll($sql, [$studentPersonId]);
    }

    /**
     * Get emergency contacts (including non-guardian emergency contacts)
     */
    public function getEmergencyContacts(int $studentPersonId): array
    {
        // Get guardian emergency contacts
        $guardianSql = "SELECT 
                            'guardian' as contact_type,
                            sgr.id as relationship_id,
                            NULL as emergency_contact_id,
                            CONCAT(p.first_name, ' ', p.last_name) as contact_name,
                            sgr.relationship_type as relationship,
                            p.primary_phone as phone,
                            p.primary_email as email,
                            sgr.is_emergency_contact as is_emergency_contact,
                            sgr.is_primary,
                            sgr.priority_order,
                            sgr.notes
                        FROM student_guardian_relationships sgr
                        JOIN people p ON sgr.guardian_person_id = p.id
                        WHERE sgr.student_person_id = ? AND sgr.is_emergency_contact = 1 AND sgr.deleted_at IS NULL";

        // Get non-guardian emergency contacts
        $emergencySql = "SELECT 
                            'emergency' as contact_type,
                            NULL as relationship_id,
                            sec.id as emergency_contact_id,
                            sec.contact_name,
                            sec.relationship,
                            sec.phone,
                            sec.email,
                            sec.is_primary as is_emergency_contact,
                            sec.is_primary,
                            sec.priority_order,
                            sec.notes
                        FROM student_emergency_contacts sec
                        WHERE sec.student_person_id = ? AND sec.deleted_at IS NULL";

        $guardians = $this->db->fetchAll($guardianSql, [$studentPersonId]);
        $emergencies = $this->db->fetchAll($emergencySql, [$studentPersonId]);

        $all = array_merge($guardians, $emergencies);

        // Sort by is_primary DESC, priority_order ASC
        usort($all, function ($a, $b) {
            if ($a['is_primary'] != $b['is_primary']) {
                return $b['is_primary'] - $a['is_primary'];
            }
            return ($a['priority_order'] ?? 999) - ($b['priority_order'] ?? 999);
        });

        return $all;
    }

    /**
     * Create emergency contact
     */
    public function createEmergencyContact(array $data): int
    {
        $sql = "INSERT INTO student_emergency_contacts (
            student_person_id, tenant_id, contact_name, relationship,
            phone, phone_secondary, email, address, priority_order,
            is_primary, notes, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $this->db->execute($sql, [
            $data['student_person_id'],
            $data['tenant_id'],
            $data['contact_name'],
            $data['relationship'],
            $data['phone'],
            $data['phone_secondary'] ?? null,
            $data['email'] ?? null,
            $data['address'] ?? null,
            $data['priority_order'] ?? 0,
            $data['is_primary'] ?? 0,
            $data['notes'] ?? null,
            $data['created_by']
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Update emergency contact
     */
    public function updateEmergencyContact(int $id, array $data): bool
    {
        $updates = [];
        $params = [];

        $allowedFields = [
            'contact_name',
            'relationship',
            'phone',
            'phone_secondary',
            'email',
            'address',
            'priority_order',
            'is_primary',
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
        $sql = "UPDATE student_emergency_contacts SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, $params);
    }

    /**
     * Delete emergency contact
     */
    public function deleteEmergencyContact(int $id): bool
    {
        $sql = "UPDATE student_emergency_contacts SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }

    /**
     * Get emergency contact by ID
     */
    public function getEmergencyContactById(int $id): ?array
    {
        $sql = "SELECT * FROM student_emergency_contacts WHERE id = ? AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$id]);
    }

    /**
     * Get students for a guardian
     */
    public function getStudentsByGuardian(int $guardianPersonId): array
    {
        $sql = "SELECT 
                    sgr.*,
                    p.id as student_person_id,
                    p.first_name,
                    p.last_name,
                    p.middle_name,
                    p.primary_email,
                    p.primary_phone,
                    p.date_of_birth,
                    p.gender,
                    sp.student_number,
                    sp.enrollment_status,
                    sp.school_id,
                    s.school_name
                FROM student_guardian_relationships sgr
                JOIN people p ON sgr.student_person_id = p.id
                LEFT JOIN student_profiles sp ON p.id = sp.person_id AND sp.deleted_at IS NULL
                LEFT JOIN schools s ON sp.school_id = s.id
                WHERE sgr.guardian_person_id = ? AND sgr.deleted_at IS NULL
                ORDER BY sgr.is_primary DESC, sgr.priority_order ASC";
        return $this->db->fetchAll($sql, [$guardianPersonId]);
    }

    /**
     * Set primary guardian (unset others)
     */
    public function setPrimaryGuardian(int $studentPersonId, int $guardianPersonId): bool
    {
        try {
            $this->db->beginTransaction();

            // Unset all primary for this student
            $this->db->execute(
                "UPDATE student_guardian_relationships SET is_primary = 0, updated_at = NOW() 
                 WHERE student_person_id = ? AND deleted_at IS NULL",
                [$studentPersonId]
            );

            // Set this one as primary
            $this->db->execute(
                "UPDATE student_guardian_relationships SET is_primary = 1, updated_at = NOW() 
                 WHERE student_person_id = ? AND guardian_person_id = ? AND deleted_at IS NULL",
                [$studentPersonId, $guardianPersonId]
            );

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Get guardian statistics
     */
    public function getGuardianStats(?int $tenantId = null): array
    {
        $params = [];
        $where = ["deleted_at IS NULL"];

        if ($tenantId) {
            $where[] = "tenant_id = ?";
            $params[] = $tenantId;
        }

        $whereClause = "WHERE " . implode(" AND ", $where);

        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN is_primary = 1 THEN 1 ELSE 0 END) as primary_guardians,
                    SUM(CASE WHEN is_financial_responsible = 1 THEN 1 ELSE 0 END) as financial_responsible,
                    SUM(CASE WHEN is_emergency_contact = 1 THEN 1 ELSE 0 END) as emergency_contacts,
                    SUM(CASE WHEN is_pickup_authorized = 1 THEN 1 ELSE 0 END) as pickup_authorized,
                    SUM(CASE WHEN relationship_type = 'father' THEN 1 ELSE 0 END) as fathers,
                    SUM(CASE WHEN relationship_type = 'mother' THEN 1 ELSE 0 END) as mothers,
                    SUM(CASE WHEN relationship_type = 'guardian' THEN 1 ELSE 0 END) as other_guardians
                FROM guardian_profiles
                $whereClause";

        return $this->db->fetchOne($sql, $params);
    }
}
