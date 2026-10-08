<?php

/**
 * GuardianService.php
 * Service for guardian management
 * 
 * @package EduTrack
 * @subpackage Services\Identity
 * @version 2.0
 * @filepath app/services/Identity/GuardianService.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class GuardianService
{
    private $db;
    private $logger;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
    }

    /**
     * Create guardian profile
     */
    public function createProfile(array $data): array
    {
        try {
            if (empty($data['person_id'])) {
                return ['success' => false, 'message' => 'Person ID is required'];
            }

            $sql = "INSERT INTO guardian_profiles (
                person_id,
                is_primary_guardian,
                is_emergency_contact,
                is_financial_responsible,
                is_academic_contact,
                is_pickup_authorized,
                is_medical_authorized,
                communication_allowed,
                preferred_communication,
                notes,
                created_by,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $params = [
                $data['person_id'],
                $data['is_primary_guardian'] ?? 0,
                $data['is_emergency_contact'] ?? 1,
                $data['is_financial_responsible'] ?? 0,
                $data['is_academic_contact'] ?? 0,
                $data['is_pickup_authorized'] ?? 0,
                $data['is_medical_authorized'] ?? 0,
                $data['communication_allowed'] ?? 1,
                $data['preferred_communication'] ?? null,
                $data['notes'] ?? null,
                $data['created_by'] ?? null
            ];

            $this->db->execute($sql, $params);
            $guardianId = $this->db->lastInsertId();

            $this->logAudit('GUARDIAN_PROFILE_CREATED', 'guardian_profile', $guardianId);

            return ['success' => true, 'data' => ['id' => $guardianId]];
        } catch (Exception $e) {
            $this->logger->error('createProfile error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Link guardian to student
     */
    public function linkToStudent(int $studentId, int $guardianId, array $data): array
    {
        try {
            $sql = "INSERT INTO student_guardian_relationships (
                student_id,
                guardian_id,
                relationship_type_id,
                is_primary,
                is_emergency_contact,
                is_financial_responsible,
                is_academic_contact,
                is_pickup_authorized,
                is_medical_authorized,
                communication_allowed,
                start_date,
                notes,
                created_by,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $params = [
                $studentId,
                $guardianId,
                $data['relationship_type_id'] ?? null,
                $data['is_primary'] ?? 0,
                $data['is_emergency_contact'] ?? 1,
                $data['is_financial_responsible'] ?? 0,
                $data['is_academic_contact'] ?? 0,
                $data['is_pickup_authorized'] ?? 0,
                $data['is_medical_authorized'] ?? 0,
                $data['communication_allowed'] ?? 1,
                $data['start_date'] ?? date('Y-m-d'),
                $data['notes'] ?? null,
                $data['created_by'] ?? null
            ];

            $this->db->execute($sql, $params);
            $relationshipId = $this->db->lastInsertId();

            $this->logAudit('GUARDIAN_LINKED', 'student_guardian', $relationshipId);

            return ['success' => true, 'data' => ['id' => $relationshipId]];
        } catch (Exception $e) {
            $this->logger->error('linkToStudent error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get guardian by person ID
     */
    public function getByPersonId(int $personId): ?array
    {
        try {
            $sql = "SELECT * FROM guardian_profiles WHERE person_id = ? AND deleted_at IS NULL";
            $result = $this->db->fetchOne($sql, [$personId]);
            return $result ?: null;
        } catch (Exception $e) {
            $this->logger->error('getByPersonId error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Log audit event
     */
    private function logAudit(string $action, string $resource, ?int $resourceId = null): void
    {
        try {
            $sql = "INSERT INTO audit_logs (
                tenant_id,
                user_id,
                action_type,
                module,
                resource,
                resource_id,
                description,
                ip_address,
                user_agent,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $this->db->execute($sql, [
                $_SESSION['tenant_id'] ?? null,
                $_SESSION['user_id'] ?? null,
                $action,
                'guardian',
                $resource,
                $resourceId,
                $action . ' on ' . $resource,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            $this->logger->error('Audit log error: ' . $e->getMessage());
        }
    }
}
