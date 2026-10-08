<?php

/**
 * HealthController.php
 * Controller for Health profile management endpoints
 * 
 * @package EduTrack
 * @subpackage Controllers\Health
 * @filepath app/controllers/Health/HealthController.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/ResponseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/services/Tenant/TenantContext.php';
require_once $projectRoot . 'app/services/Health/HealthService.php';

class HealthController
{
    private $response;
    private $context;
    private $healthService;
    private $db;
    private $logger;
    private $authenticatedUserId;

    public function __construct()
    {
        $this->response = new ResponseHelper();
        $this->context = TenantContext::getInstance();
        $this->healthService = new HealthService();
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->authenticatedUserId = null;
        $this->authenticate();
    }

    private function authenticate(): bool
    {
        if (isset($_SESSION['user_id'])) {
            $this->authenticatedUserId = $_SESSION['user_id'];
            return true;
        }

        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';

        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
            $parts = explode('.', $token);
            if (count($parts) === 3) {
                $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
                if ($payload && isset($payload['user_id'])) {
                    $this->authenticatedUserId = $payload['user_id'];
                    $_SESSION['user_id'] = $payload['user_id'];
                    $_SESSION['tenant_id'] = $payload['tenant_id'] ?? null;
                    return true;
                }
            }
        }

        return false;
    }

    private function isAuthenticated(): bool
    {
        return $this->authenticatedUserId !== null;
    }

    private function getTenantId(): ?int
    {
        return $this->context->getTenantId();
    }

    private function isPlatformAdmin(): bool
    {
        return $this->context->isPlatformAdmin();
    }

    private function getRequestData(): array
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return [];
        }
        return json_decode($input, true) ?? [];
    }

    private function logAudit(string $action, string $resource, ?int $resourceId = null, array $details = null): void
    {
        try {
            $tenantId = $this->getTenantId();
            $userId = $this->authenticatedUserId;
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
     * POST /api/platform/index.php?endpoint=health&action=create
     */
    public function createHealthProfile(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();

            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                if (empty($data['tenant_id'])) {
                    $data['tenant_id'] = $currentTenantId;
                } elseif ($data['tenant_id'] != $currentTenantId) {
                    $this->response->error('You do not have permission to create health profiles for this tenant', 403);
                    return;
                }
            }

            $data['created_by'] = $this->authenticatedUserId;

            $result = $this->healthService->createHealthProfile($data);

            if ($result['success']) {
                $this->logAudit('HEALTH_PROFILE_CREATED', 'health_profile', $result['data']['id'] ?? null, [
                    'description' => 'Created health profile for person ID: ' . ($data['person_id'] ?? '')
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('createHealthProfile error: ' . $e->getMessage());
            $this->response->error('Error creating health profile: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get a single health profile by ID
     * GET /api/platform/index.php?endpoint=health&action=get&id={id}
     */
    public function getHealthProfile(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $result = $this->healthService->getHealthProfile($id);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 404);
            }
        } catch (Exception $e) {
            error_log('getHealthProfile error: ' . $e->getMessage());
            $this->response->error('Error fetching health profile: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get health profile by person ID
     * GET /api/platform/index.php?endpoint=health&action=by_person&person_id={person_id}
     */
    public function getHealthProfileByPerson(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $personId = isset($_GET['person_id']) ? (int)$_GET['person_id'] : 0;
            if (!$personId) {
                $this->response->error('person_id is required', 400);
                return;
            }

            $result = $this->healthService->getHealthProfileByPerson($personId);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 404);
            }
        } catch (Exception $e) {
            error_log('getHealthProfileByPerson error: ' . $e->getMessage());
            $this->response->error('Error fetching health profile: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get health profiles for a specific person (all)
     * GET /api/platform/index.php?endpoint=health&action=by_person_all&person_id={person_id}
     */
    public function getHealthProfilesByPerson(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $personId = isset($_GET['person_id']) ? (int)$_GET['person_id'] : 0;
            if (!$personId) {
                $this->response->error('person_id is required', 400);
                return;
            }

            $result = $this->healthService->getHealthProfilesByPerson($personId);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 404);
            }
        } catch (Exception $e) {
            error_log('getHealthProfilesByPerson error: ' . $e->getMessage());
            $this->response->error('Error fetching health profiles: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update a health profile
     * PUT /api/platform/index.php?endpoint=health&action=update&id={id}
     */
    public function updateHealthProfile(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();

            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                // Verify the health profile belongs to this tenant
                $profile = $this->healthService->getHealthProfile($id);
                if ($profile['success']) {
                    $tenantId = $profile['data']['tenant_id'] ?? null;
                    if ($tenantId && $tenantId != $currentTenantId) {
                        $this->response->error('You do not have permission to update this health profile', 403);
                        return;
                    }
                }
            }

            $data['updated_by'] = $this->authenticatedUserId;

            $result = $this->healthService->updateHealthProfile($id, $data);

            if ($result['success']) {
                $this->logAudit('HEALTH_PROFILE_UPDATED', 'health_profile', $id, [
                    'description' => 'Updated health profile ID: ' . $id
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('updateHealthProfile error: ' . $e->getMessage());
            $this->response->error('Error updating health profile: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a health profile (soft delete)
     * DELETE /api/platform/index.php?endpoint=health&action=delete&id={id}
     */
    public function deleteHealthProfile(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                $profile = $this->healthService->getHealthProfile($id);
                if ($profile['success']) {
                    $tenantId = $profile['data']['tenant_id'] ?? null;
                    if ($tenantId && $tenantId != $currentTenantId) {
                        $this->response->error('You do not have permission to delete this health profile', 403);
                        return;
                    }
                }
            }

            $result = $this->healthService->deleteHealthProfile($id);

            if ($result['success']) {
                $this->logAudit('HEALTH_PROFILE_DELETED', 'health_profile', $id, [
                    'description' => 'Deleted health profile ID: ' . $id
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('deleteHealthProfile error: ' . $e->getMessage());
            $this->response->error('Error deleting health profile: ' . $e->getMessage(), 500);
        }
    }

    /**
     * List health profiles with pagination and filters
     * GET /api/platform/index.php?endpoint=health&action=list
     */
    public function listHealthProfiles(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            $personId = isset($_GET['person_id']) ? (int)$_GET['person_id'] : 0;
            $status = isset($_GET['status']) ? trim($_GET['status']) : '';
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $bloodGroup = isset($_GET['blood_group']) ? trim($_GET['blood_group']) : '';

            $filters = [
                'status' => $status,
                'search' => $search,
                'blood_group' => $bloodGroup
            ];

            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                $filters['tenant_id'] = $currentTenantId;
            }

            if ($personId > 0) {
                $filters['person_id'] = $personId;
            }

            $result = $this->healthService->listHealthProfiles($filters, $page, $limit);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 500);
            }
        } catch (Exception $e) {
            error_log('listHealthProfiles error: ' . $e->getMessage());
            $this->response->error('Error listing health profiles: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get health profile statistics
     * GET /api/platform/index.php?endpoint=health&action=stats
     */
    public function getHealthStats(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $tenantId = $this->getTenantId();
            $stats = $this->healthService->getHealthStats($tenantId);

            $this->response->success($stats);
        } catch (Exception $e) {
            error_log('getHealthStats error: ' . $e->getMessage());
            $this->response->error('Error fetching health stats: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Search health profiles (autocomplete)
     * GET /api/platform/index.php?endpoint=health&action=search
     */
    public function searchHealthProfiles(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

            if (strlen($search) < 2) {
                $this->response->success([]);
                return;
            }

            $filters = ['search' => $search];
            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                $filters['tenant_id'] = $currentTenantId;
            }

            $result = $this->healthService->searchHealthProfiles($filters, $limit);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 500);
            }
        } catch (Exception $e) {
            error_log('searchHealthProfiles error: ' . $e->getMessage());
            $this->response->error('Error searching health profiles: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get health profile types (PUBLIC - No authentication required)
     * GET /api/platform/index.php?endpoint=health&action=types
     */
    public function getHealthTypes(): void
    {
        try {
            // Public endpoint - no authentication required for types
            $types = [
                ['code' => 'general', 'name' => 'General Health', 'category' => 'health'],
                ['code' => 'medical', 'name' => 'Medical History', 'category' => 'health'],
                ['code' => 'allergy', 'name' => 'Allergies', 'category' => 'health'],
                ['code' => 'medication', 'name' => 'Medications', 'category' => 'health'],
                ['code' => 'immunization', 'name' => 'Immunizations', 'category' => 'health'],
                ['code' => 'mental', 'name' => 'Mental Health', 'category' => 'health'],
                ['code' => 'dental', 'name' => 'Dental Records', 'category' => 'health'],
                ['code' => 'vision', 'name' => 'Vision Records', 'category' => 'health'],
                ['code' => 'emergency', 'name' => 'Emergency Contact', 'category' => 'health'],
                ['code' => 'insurance', 'name' => 'Insurance Information', 'category' => 'health']
            ];

            $this->response->success($types);
        } catch (Exception $e) {
            error_log('getHealthTypes error: ' . $e->getMessage());
            $this->response->error('Error fetching health types: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get health statuses (PUBLIC - No authentication required)
     * GET /api/platform/index.php?endpoint=health&action=statuses
     */
    public function getHealthStatuses(): void
    {
        try {
            // Public endpoint - no authentication required for statuses
            $statuses = [
                ['code' => 'active', 'name' => 'Active', 'color' => 'green'],
                ['code' => 'inactive', 'name' => 'Inactive', 'color' => 'gray'],
                ['code' => 'pending', 'name' => 'Pending', 'color' => 'yellow'],
                ['code' => 'completed', 'name' => 'Completed', 'color' => 'blue'],
                ['code' => 'cancelled', 'name' => 'Cancelled', 'color' => 'red'],
                ['code' => 'expired', 'name' => 'Expired', 'color' => 'orange']
            ];

            $this->response->success($statuses);
        } catch (Exception $e) {
            error_log('getHealthStatuses error: ' . $e->getMessage());
            $this->response->error('Error fetching health statuses: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Bulk create health profiles
     * POST /api/platform/index.php?endpoint=health&action=bulk_create
     */
    public function bulkCreateHealthProfiles(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();
            $profiles = $data['profiles'] ?? [];
            $tenantId = $data['tenant_id'] ?? 0;

            if (empty($profiles) || !is_array($profiles)) {
                $this->response->error('Health profiles data is required', 400);
                return;
            }

            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                if (empty($tenantId)) {
                    $tenantId = $currentTenantId;
                } elseif ($tenantId != $currentTenantId) {
                    $this->response->error('You do not have permission to create health profiles for this tenant', 403);
                    return;
                }
            }

            $results = [];
            $successCount = 0;
            $failCount = 0;

            foreach ($profiles as $profileData) {
                $profileTenantId = $tenantId ?: ($profileData['tenant_id'] ?? null);
                if (!$profileTenantId && $currentTenantId) {
                    $profileTenantId = $currentTenantId;
                }
                $profileData['tenant_id'] = $profileTenantId;
                $profileData['created_by'] = $this->authenticatedUserId;

                $result = $this->healthService->createHealthProfile($profileData);
                if ($result['success']) {
                    $successCount++;
                    $results[] = $result['data'];
                } else {
                    $failCount++;
                    $results[] = ['error' => $result['message'], 'data' => $profileData];
                }
            }

            $this->logAudit('HEALTH_BULK_CREATE', 'health_profile', null, [
                'description' => 'Bulk created ' . $successCount . ' health profiles',
                'success_count' => $successCount,
                'fail_count' => $failCount
            ]);

            $this->response->success([
                'message' => "Created $successCount health profiles successfully" . ($failCount > 0 ? ", $failCount failed" : ""),
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'results' => $results
            ]);
        } catch (Exception $e) {
            error_log('bulkCreateHealthProfiles error: ' . $e->getMessage());
            $this->response->error('Error creating health profiles: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update health profile status
     * PUT /api/platform/index.php?endpoint=health&action=update_status&id={id}
     */
    public function updateHealthProfileStatus(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();
            $status = $data['status'] ?? '';

            if (empty($status)) {
                $this->response->error('Status is required', 400);
                return;
            }

            $validStatuses = ['active', 'inactive', 'pending', 'completed', 'cancelled', 'expired'];
            if (!in_array($status, $validStatuses)) {
                $this->response->error('Invalid status. Valid values: ' . implode(', ', $validStatuses), 400);
                return;
            }

            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                $profile = $this->healthService->getHealthProfile($id);
                if ($profile['success']) {
                    $tenantId = $profile['data']['tenant_id'] ?? null;
                    if ($tenantId && $tenantId != $currentTenantId) {
                        $this->response->error('You do not have permission to update this health profile', 403);
                        return;
                    }
                }
            }

            $result = $this->healthService->updateHealthProfileStatus($id, $status);

            if ($result['success']) {
                $this->logAudit('HEALTH_PROFILE_STATUS_UPDATED', 'health_profile', $id, [
                    'description' => 'Updated health profile status to: ' . $status
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('updateHealthProfileStatus error: ' . $e->getMessage());
            $this->response->error('Error updating health profile status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Export health profiles to CSV
     * GET /api/platform/index.php?endpoint=health&action=export
     */
    public function exportHealthProfiles(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $filters = [];
            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                $filters['tenant_id'] = $currentTenantId;
            }

            $result = $this->healthService->exportHealthProfiles($filters);

            if ($result['success']) {
                $data = $result['data'];

                // Set CSV headers
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="health_profiles_export_' . date('Y-m-d') . '.csv"');

                $output = fopen('php://output', 'w');

                // Add CSV headers
                if (!empty($data)) {
                    fputcsv($output, array_keys($data[0]));

                    // Add data rows
                    foreach ($data as $row) {
                        fputcsv($output, $row);
                    }
                }

                fclose($output);
                exit;
            } else {
                $this->response->error($result['message'], 500);
            }
        } catch (Exception $e) {
            error_log('exportHealthProfiles error: ' . $e->getMessage());
            $this->response->error('Error exporting health profiles: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get health profile by UUID
     * GET /api/platform/index.php?endpoint=health&action=by_uuid&uuid={uuid}
     */
    public function getHealthProfileByUuid(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $uuid = isset($_GET['uuid']) ? trim($_GET['uuid']) : '';
            if (empty($uuid)) {
                $this->response->error('uuid is required', 400);
                return;
            }

            $result = $this->healthService->getHealthProfileByUuid($uuid);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 404);
            }
        } catch (Exception $e) {
            error_log('getHealthProfileByUuid error: ' . $e->getMessage());
            $this->response->error('Error fetching health profile: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get health profile counts by status
     * GET /api/platform/index.php?endpoint=health&action=counts_by_status
     */
    public function getHealthCountsByStatus(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $tenantId = $this->getTenantId();
            $result = $this->healthService->getHealthCountsByStatus($tenantId);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 500);
            }
        } catch (Exception $e) {
            error_log('getHealthCountsByStatus error: ' . $e->getMessage());
            $this->response->error('Error fetching health counts: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get health profile summary for dashboard
     * GET /api/platform/index.php?endpoint=health&action=summary
     */
    public function getHealthSummary(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $tenantId = $this->getTenantId();
            $result = $this->healthService->getHealthSummary($tenantId);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 500);
            }
        } catch (Exception $e) {
            error_log('getHealthSummary error: ' . $e->getMessage());
            $this->response->error('Error fetching health summary: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get health profiles by blood group
     * GET /api/platform/index.php?endpoint=health&action=by_blood_group&blood_group={blood_group}
     */
    public function getHealthByBloodGroup(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $bloodGroup = isset($_GET['blood_group']) ? trim($_GET['blood_group']) : '';
            if (empty($bloodGroup)) {
                $this->response->error('blood_group is required', 400);
                return;
            }

            $tenantId = $this->getTenantId();
            $result = $this->healthService->getHealthByBloodGroup($bloodGroup, $tenantId);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 500);
            }
        } catch (Exception $e) {
            error_log('getHealthByBloodGroup error: ' . $e->getMessage());
            $this->response->error('Error fetching health profiles by blood group: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get health profiles with medical conditions
     * GET /api/platform/index.php?endpoint=health&action=with_medical_conditions
     */
    public function getWithMedicalConditions(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $tenantId = $this->getTenantId();
            $result = $this->healthService->getWithMedicalConditions($tenantId);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 500);
            }
        } catch (Exception $e) {
            error_log('getWithMedicalConditions error: ' . $e->getMessage());
            $this->response->error('Error fetching health profiles: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get health profiles with allergies
     * GET /api/platform/index.php?endpoint=health&action=with_allergies
     */
    public function getWithAllergies(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $tenantId = $this->getTenantId();
            $result = $this->healthService->getWithAllergies($tenantId);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 500);
            }
        } catch (Exception $e) {
            error_log('getWithAllergies error: ' . $e->getMessage());
            $this->response->error('Error fetching health profiles: ' . $e->getMessage(), 500);
        }
    }
}
