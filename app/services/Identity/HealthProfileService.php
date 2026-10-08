<?php

/**
 * HealthProfileService.php
 * Service for health profile management
 * 
 * @package EduTrack
 * @subpackage Services\Identity
 * @version 2.0
 * @filepath app/services/Identity/HealthProfileService.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class HealthProfileService
{
    private $db;
    private $logger;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
    }

    /**
     * Create health profile
     */
    public function create(array $data): array
    {
        try {
            if (empty($data['person_id'])) {
                return ['success' => false, 'message' => 'Person ID is required'];
            }

            // Check if profile already exists
            $existing = $this->getByPersonId($data['person_id']);
            if ($existing !== null && !empty($existing)) {
                return $this->update($existing['id'], $data);
            }

            $sql = "INSERT INTO health_profiles (
                person_id,
                blood_group,
                genotype,
                allergies,
                medical_conditions,
                chronic_conditions,
                current_medications,
                medical_alerts,
                disabilities,
                accessibility_requirements,
                dietary_restrictions,
                special_instructions,
                health_insurance_provider,
                health_insurance_number,
                nhis_number,
                primary_physician,
                medical_facility,
                emergency_medical_notes,
                classification,
                created_by,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $params = [
                $data['person_id'],
                $data['blood_group'] ?? null,
                $data['genotype'] ?? null,
                $data['allergies'] ?? null,
                $data['medical_conditions'] ?? null,
                $data['chronic_conditions'] ?? null,
                $data['current_medications'] ?? null,
                $data['medical_alerts'] ?? null,
                $data['disabilities'] ?? null,
                $data['accessibility_requirements'] ?? null,
                $data['dietary_restrictions'] ?? null,
                $data['special_instructions'] ?? null,
                $data['health_insurance_provider'] ?? null,
                $data['health_insurance_number'] ?? null,
                $data['nhis_number'] ?? null,
                $data['primary_physician'] ?? null,
                $data['medical_facility'] ?? null,
                $data['emergency_medical_notes'] ?? null,
                $data['classification'] ?? 'normal',
                $data['created_by'] ?? null
            ];

            $this->db->execute($sql, $params);
            $healthId = $this->db->lastInsertId();

            $this->logAudit('HEALTH_PROFILE_CREATED', 'health_profile', $healthId);

            return ['success' => true, 'data' => ['id' => $healthId]];
        } catch (Exception $e) {
            $this->logger->error('create error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update health profile
     */
    public function update(int $healthId, array $data): array
    {
        try {
            $updates = [];
            $params = [];

            $allowedFields = [
                'blood_group',
                'genotype',
                'allergies',
                'medical_conditions',
                'chronic_conditions',
                'current_medications',
                'medical_alerts',
                'disabilities',
                'accessibility_requirements',
                'dietary_restrictions',
                'special_instructions',
                'health_insurance_provider',
                'health_insurance_number',
                'nhis_number',
                'primary_physician',
                'medical_facility',
                'emergency_medical_notes',
                'classification'
            ];

            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($updates)) {
                return ['success' => false, 'message' => 'No fields to update'];
            }

            $params[] = $healthId;
            $sql = "UPDATE health_profiles SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ?";
            $this->db->execute($sql, $params);

            $this->logAudit('HEALTH_PROFILE_UPDATED', 'health_profile', $healthId);

            return ['success' => true, 'data' => ['id' => $healthId]];
        } catch (Exception $e) {
            $this->logger->error('update error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get health profile by person ID
     */
    public function getByPersonId(int $personId): ?array
    {
        try {
            $sql = "SELECT * FROM health_profiles WHERE person_id = ? AND deleted_at IS NULL";
            $result = $this->db->fetchOne($sql, [$personId]);

            // fetchOne might return false, null, or an array
            if ($result === false || $result === null || empty($result)) {
                return null;
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('getByPersonId error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get health profile by ID
     */
    public function getById(int $id): ?array
    {
        try {
            $sql = "SELECT * FROM health_profiles WHERE id = ? AND deleted_at IS NULL";
            $result = $this->db->fetchOne($sql, [$id]);

            if ($result === false || $result === null || empty($result)) {
                return null;
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('getById error: ' . $e->getMessage());
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
                'health',
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
