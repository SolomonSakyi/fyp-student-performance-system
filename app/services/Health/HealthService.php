<?php

/**
 * HealthService.php
 * Service for health profile management operations
 * 
 * @package EduTrack
 * @subpackage Services\Health
 * @filepath app/services/Health/HealthService.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/ResponseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/services/Tenant/TenantContext.php';
require_once $projectRoot . 'app/repositories/Health/HealthProfileRepository.php';

class HealthService
{
    private $db;
    private $response;
    private $logger;
    private $context;
    private $healthProfileRepo;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->response = new ResponseHelper();
        $this->logger = new LoggerHelper();
        $this->context = TenantContext::getInstance();
        $this->healthProfileRepo = new HealthProfileRepository();
    }

    private function getTenantId(): ?int
    {
        return $this->context->getTenantId();
    }

    private function getUserId(): ?int
    {
        return $this->context->getUserId();
    }

    private function isPlatformAdmin(): bool
    {
        return $this->context->isPlatformAdmin();
    }

    private function logAudit(string $action, string $resource, ?int $resourceId = null, array $details = null): void
    {
        try {
            $tenantId = $this->getTenantId();
            $userId = $this->getUserId();
            $sql = "INSERT INTO audit_logs (tenant_id, user_id, action_type, module, resource, resource_id, description, details, ip_address, user_agent, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $this->db->execute($sql, [
                $tenantId,
                $userId,
                $action,
                'health',
                $resource,
                $resourceId,
                $details['description'] ?? $action . ' on ' . $resource,
                $details ? json_encode($details) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            // Silently fail for audit logging
        }
    }

    /**
     * Create a health profile
     */
    public function createHealthProfile(array $data): array
    {
        try {
            // Validate required fields
            $required = ['person_id', 'tenant_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required'];
                }
            }

            // Ensure created_by is set
            if (empty($data['created_by'])) {
                $data['created_by'] = $this->getUserId();
            }

            // Log the data being inserted for debugging
            error_log('HealthService::createHealthProfile - Creating profile for person_id: ' . $data['person_id']);
            error_log('HealthService::createHealthProfile - Data: ' . json_encode($data));

            // Create the profile
            $profileId = $this->healthProfileRepo->create($data);

            if (!$profileId) {
                error_log('HealthService::createHealthProfile - Failed to create profile, no ID returned');
                return ['success' => false, 'message' => 'Failed to create health profile'];
            }

            error_log('HealthService::createHealthProfile - Profile created with ID: ' . $profileId);

            // Get the created profile
            $profile = $this->healthProfileRepo->getById($profileId);

            if (!$profile) {
                error_log('HealthService::createHealthProfile - Created profile but could not retrieve it');
                return [
                    'success' => true,
                    'message' => 'Health profile created successfully but could not retrieve details',
                    'data' => ['id' => $profileId]
                ];
            }

            $this->logAudit('HEALTH_PROFILE_CREATED', 'health_profile', $profileId, [
                'description' => 'Created health profile for person: ' . ($data['person_id'] ?? '')
            ]);

            return [
                'success' => true,
                'message' => 'Health profile created successfully',
                'data' => $profile
            ];
        } catch (Exception $e) {
            error_log('HealthService::createHealthProfile error: ' . $e->getMessage());
            error_log('HealthService::createHealthProfile trace: ' . $e->getTraceAsString());
            $this->logger->error('HealthService::createHealthProfile error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error creating health profile: ' . $e->getMessage()];
        }
    }

    /**
     * Get a health profile by ID
     */
    public function getHealthProfile(int $id): array
    {
        try {
            $profile = $this->healthProfileRepo->getById($id);

            if (!$profile) {
                return ['success' => false, 'message' => 'Health profile not found'];
            }

            // Enforce tenant isolation
            $tenantId = $this->getTenantId();
            if ($tenantId && !$this->isPlatformAdmin()) {
                if ($profile['tenant_id'] != $tenantId) {
                    return ['success' => false, 'message' => 'Access denied'];
                }
            }

            return ['success' => true, 'data' => $profile];
        } catch (Exception $e) {
            $this->logger->error('HealthService::getHealthProfile error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching health profile: ' . $e->getMessage()];
        }
    }

    /**
     * Get health profile by person ID (single)
     */
    public function getHealthProfileByPerson(int $personId): array
    {
        try {
            $profile = $this->healthProfileRepo->getByPersonId($personId);

            if (!$profile) {
                return ['success' => false, 'message' => 'Health profile not found for this person'];
            }

            // Enforce tenant isolation
            $tenantId = $this->getTenantId();
            if ($tenantId && !$this->isPlatformAdmin()) {
                if ($profile['tenant_id'] != $tenantId) {
                    return ['success' => false, 'message' => 'Access denied'];
                }
            }

            return ['success' => true, 'data' => $profile];
        } catch (Exception $e) {
            $this->logger->error('HealthService::getHealthProfileByPerson error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching health profile: ' . $e->getMessage()];
        }
    }

    /**
     * Get health profiles by person ID (all)
     */
    public function getHealthProfilesByPerson(int $personId): array
    {
        try {
            $profiles = $this->healthProfileRepo->getByPersonIdAll($personId);

            // Enforce tenant isolation
            $tenantId = $this->getTenantId();
            if ($tenantId && !$this->isPlatformAdmin()) {
                $filteredProfiles = [];
                foreach ($profiles as $profile) {
                    if ($profile['tenant_id'] == $tenantId) {
                        $filteredProfiles[] = $profile;
                    }
                }
                $profiles = $filteredProfiles;
            }

            return ['success' => true, 'data' => $profiles];
        } catch (Exception $e) {
            $this->logger->error('HealthService::getHealthProfilesByPerson error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching health profiles: ' . $e->getMessage()];
        }
    }

    /**
     * Update a health profile
     */
    public function updateHealthProfile(int $id, array $data): array
    {
        try {
            // Check if profile exists
            $profile = $this->healthProfileRepo->getById($id);
            if (!$profile) {
                return ['success' => false, 'message' => 'Health profile not found'];
            }

            // Enforce tenant isolation
            $tenantId = $this->getTenantId();
            if ($tenantId && !$this->isPlatformAdmin()) {
                if ($profile['tenant_id'] != $tenantId) {
                    return ['success' => false, 'message' => 'Access denied'];
                }
            }

            // Set updated_by
            if (empty($data['updated_by'])) {
                $data['updated_by'] = $this->getUserId();
            }

            // Update the profile
            $result = $this->healthProfileRepo->update($id, $data);

            if (!$result) {
                return ['success' => false, 'message' => 'Failed to update health profile'];
            }

            // Get updated profile
            $updatedProfile = $this->healthProfileRepo->getById($id);

            $this->logAudit('HEALTH_PROFILE_UPDATED', 'health_profile', $id, [
                'description' => 'Updated health profile'
            ]);

            return [
                'success' => true,
                'message' => 'Health profile updated successfully',
                'data' => $updatedProfile
            ];
        } catch (Exception $e) {
            $this->logger->error('HealthService::updateHealthProfile error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error updating health profile: ' . $e->getMessage()];
        }
    }

    /**
     * Delete a health profile (soft delete)
     */
    public function deleteHealthProfile(int $id): array
    {
        try {
            // Check if profile exists
            $profile = $this->healthProfileRepo->getById($id);
            if (!$profile) {
                return ['success' => false, 'message' => 'Health profile not found'];
            }

            // Enforce tenant isolation
            $tenantId = $this->getTenantId();
            if ($tenantId && !$this->isPlatformAdmin()) {
                if ($profile['tenant_id'] != $tenantId) {
                    return ['success' => false, 'message' => 'Access denied'];
                }
            }

            $result = $this->healthProfileRepo->delete($id);

            if (!$result) {
                return ['success' => false, 'message' => 'Failed to delete health profile'];
            }

            $this->logAudit('HEALTH_PROFILE_DELETED', 'health_profile', $id, [
                'description' => 'Deleted health profile'
            ]);

            return ['success' => true, 'message' => 'Health profile deleted successfully'];
        } catch (Exception $e) {
            $this->logger->error('HealthService::deleteHealthProfile error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error deleting health profile: ' . $e->getMessage()];
        }
    }

    /**
     * List health profiles with pagination and filters
     */
    public function listHealthProfiles(array $filters, int $page = 1, int $limit = 20): array
    {
        try {
            // Enforce tenant isolation
            $tenantId = $this->getTenantId();
            if ($tenantId && !$this->isPlatformAdmin()) {
                $filters['tenant_id'] = $tenantId;
            }

            $result = $this->healthProfileRepo->list($filters, $page, $limit);

            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            $this->logger->error('HealthService::listHealthProfiles error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error listing health profiles: ' . $e->getMessage()];
        }
    }

    /**
     * Get health statistics
     */
    public function getHealthStats(?int $tenantId = null): array
    {
        try {
            $stats = $this->healthProfileRepo->getStats($tenantId);

            return [
                'total' => (int)($stats['total'] ?? 0),
                'verified' => (int)($stats['verified'] ?? 0),
                'unverified' => (int)($stats['unverified'] ?? 0),
                'pregnant' => (int)($stats['pregnant'] ?? 0),
                'smokers' => (int)($stats['smokers'] ?? 0),
                'alcohol_consumers' => (int)($stats['alcohol_consumers'] ?? 0),
                'insurance_providers' => (int)($stats['insurance_providers'] ?? 0),
                'physicians' => (int)($stats['physicians'] ?? 0),
                'blood_groups' => (int)($stats['blood_groups'] ?? 0)
            ];
        } catch (Exception $e) {
            $this->logger->error('HealthService::getHealthStats error: ' . $e->getMessage());
            return [
                'total' => 0,
                'verified' => 0,
                'unverified' => 0,
                'pregnant' => 0,
                'smokers' => 0,
                'alcohol_consumers' => 0,
                'insurance_providers' => 0,
                'physicians' => 0,
                'blood_groups' => 0
            ];
        }
    }

    /**
     * Search health profiles
     */
    public function searchHealthProfiles(array $filters, int $limit = 10): array
    {
        try {
            // Enforce tenant isolation
            $tenantId = $this->getTenantId();
            if ($tenantId && !$this->isPlatformAdmin()) {
                $filters['tenant_id'] = $tenantId;
            }

            $results = $this->healthProfileRepo->search($filters, $limit);

            return ['success' => true, 'data' => $results];
        } catch (Exception $e) {
            $this->logger->error('HealthService::searchHealthProfiles error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error searching health profiles: ' . $e->getMessage()];
        }
    }

    /**
     * Update health profile status
     */
    public function updateHealthProfileStatus(int $id, string $status): array
    {
        try {
            // Check if profile exists
            $profile = $this->healthProfileRepo->getById($id);
            if (!$profile) {
                return ['success' => false, 'message' => 'Health profile not found'];
            }

            // Enforce tenant isolation
            $tenantId = $this->getTenantId();
            if ($tenantId && !$this->isPlatformAdmin()) {
                if ($profile['tenant_id'] != $tenantId) {
                    return ['success' => false, 'message' => 'Access denied'];
                }
            }

            $result = $this->healthProfileRepo->updateStatus($id, $status);

            if (!$result) {
                return ['success' => false, 'message' => 'Failed to update health profile status'];
            }

            $updatedProfile = $this->healthProfileRepo->getById($id);

            $this->logAudit('HEALTH_PROFILE_STATUS_UPDATED', 'health_profile', $id, [
                'description' => 'Updated health profile status to: ' . $status
            ]);

            return [
                'success' => true,
                'message' => 'Health profile status updated successfully',
                'data' => $updatedProfile
            ];
        } catch (Exception $e) {
            $this->logger->error('HealthService::updateHealthProfileStatus error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error updating health profile status: ' . $e->getMessage()];
        }
    }

    /**
     * Export health profiles to CSV
     */
    public function exportHealthProfiles(array $filters): array
    {
        try {
            // Enforce tenant isolation
            $tenantId = $this->getTenantId();
            if ($tenantId && !$this->isPlatformAdmin()) {
                $filters['tenant_id'] = $tenantId;
            }

            $data = $this->healthProfileRepo->export($filters);

            return ['success' => true, 'data' => $data];
        } catch (Exception $e) {
            $this->logger->error('HealthService::exportHealthProfiles error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error exporting health profiles: ' . $e->getMessage()];
        }
    }

    /**
     * Get health profile by UUID
     */
    public function getHealthProfileByUuid(string $uuid): array
    {
        try {
            $profile = $this->healthProfileRepo->getByUuid($uuid);

            if (!$profile) {
                return ['success' => false, 'message' => 'Health profile not found'];
            }

            // Enforce tenant isolation
            $tenantId = $this->getTenantId();
            if ($tenantId && !$this->isPlatformAdmin()) {
                if ($profile['tenant_id'] != $tenantId) {
                    return ['success' => false, 'message' => 'Access denied'];
                }
            }

            return ['success' => true, 'data' => $profile];
        } catch (Exception $e) {
            $this->logger->error('HealthService::getHealthProfileByUuid error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching health profile: ' . $e->getMessage()];
        }
    }

    /**
     * Get health counts by status
     */
    public function getHealthCountsByStatus(?int $tenantId = null): array
    {
        try {
            $counts = $this->healthProfileRepo->getCountsByStatus($tenantId);
            return ['success' => true, 'data' => $counts];
        } catch (Exception $e) {
            $this->logger->error('HealthService::getHealthCountsByStatus error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching health counts: ' . $e->getMessage()];
        }
    }

    /**
     * Get health summary for dashboard
     */
    public function getHealthSummary(?int $tenantId = null): array
    {
        try {
            $summary = $this->healthProfileRepo->getSummary($tenantId);
            return ['success' => true, 'data' => $summary];
        } catch (Exception $e) {
            $this->logger->error('HealthService::getHealthSummary error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching health summary: ' . $e->getMessage()];
        }
    }

    /**
     * Get health profiles by blood group
     */
    public function getHealthByBloodGroup(string $bloodGroup, ?int $tenantId = null): array
    {
        try {
            $data = $this->healthProfileRepo->getByBloodGroup($bloodGroup, $tenantId);
            return ['success' => true, 'data' => $data];
        } catch (Exception $e) {
            $this->logger->error('HealthService::getHealthByBloodGroup error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching health profiles by blood group: ' . $e->getMessage()];
        }
    }

    /**
     * Get health profiles with medical conditions
     */
    public function getWithMedicalConditions(?int $tenantId = null): array
    {
        try {
            $data = $this->healthProfileRepo->getWithMedicalConditions($tenantId);
            return ['success' => true, 'data' => $data];
        } catch (Exception $e) {
            $this->logger->error('HealthService::getWithMedicalConditions error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching health profiles: ' . $e->getMessage()];
        }
    }

    /**
     * Get health profiles with allergies
     */
    public function getWithAllergies(?int $tenantId = null): array
    {
        try {
            $data = $this->healthProfileRepo->getWithAllergies($tenantId);
            return ['success' => true, 'data' => $data];
        } catch (Exception $e) {
            $this->logger->error('HealthService::getWithAllergies error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching health profiles: ' . $e->getMessage()];
        }
    }

    /**
     * Get health profiles by date range
     */
    public function getHealthByDateRange(string $startDate, string $endDate, ?int $tenantId = null): array
    {
        try {
            $data = $this->healthProfileRepo->getByDateRange($startDate, $endDate, $tenantId);
            return ['success' => true, 'data' => $data];
        } catch (Exception $e) {
            $this->logger->error('HealthService::getHealthByDateRange error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching health profiles by date range: ' . $e->getMessage()];
        }
    }

    /**
     * Check if person has health profile
     */
    public function personHasHealthProfile(int $personId, ?int $tenantId = null): array
    {
        try {
            $hasProfile = $this->healthProfileRepo->personHasProfile($personId, $tenantId);
            return ['success' => true, 'data' => ['has_profile' => $hasProfile]];
        } catch (Exception $e) {
            $this->logger->error('HealthService::personHasHealthProfile error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error checking health profile: ' . $e->getMessage()];
        }
    }
}
