<?php

/**
 * GuardianController.php
 * Controller for Guardian/Relationship management endpoints
 * 
 * @package EduTrack
 * @subpackage Controllers\Student
 * @filepath app/controllers/Student/GuardianController.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/ResponseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/services/Tenant/TenantContext.php';
require_once $projectRoot . 'app/services/Student/GuardianService.php';

class GuardianController
{
    private $response;
    private $context;
    private $guardianService;
    private $db;
    private $logger;
    private $authenticatedUserId;

    public function __construct()
    {
        $this->response = new ResponseHelper();
        $this->context = TenantContext::getInstance();
        $this->guardianService = new GuardianService();
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->authenticatedUserId = null;

        // Authenticate on construction
        $this->authenticate();
    }

    /**
     * Authenticate user from session or JWT token
     */
    private function authenticate(): bool
    {
        // Check session first
        if (isset($_SESSION['user_id'])) {
            $this->authenticatedUserId = $_SESSION['user_id'];
            return true;
        }

        // Check Authorization header
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';

        error_log('GuardianController - Auth Header: ' . substr($authHeader, 0, 50) . '...');

        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
            $payload = $this->decodeJWT($token);
            if ($payload && isset($payload['user_id'])) {
                $this->authenticatedUserId = $payload['user_id'];
                // Also set session for subsequent requests
                $_SESSION['user_id'] = $payload['user_id'];
                $_SESSION['tenant_id'] = $payload['tenant_id'] ?? null;
                return true;
            }
        }

        return false;
    }

    /**
     * Decode JWT token to get payload
     */
    private function decodeJWT(string $token): ?array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                error_log('GuardianController - Invalid JWT format: ' . count($parts) . ' parts');
                return null;
            }

            // Decode the payload (second part)
            $payload = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]));
            $payload = json_decode($payload, true);

            if (!$payload) {
                error_log('GuardianController - Failed to decode JWT payload');
                return null;
            }

            // Check if token is expired
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                error_log('GuardianController - Token expired: ' . $payload['exp'] . ' < ' . time());
                return null;
            }

            error_log('GuardianController - JWT decoded successfully, user_id: ' . ($payload['user_id'] ?? 'null'));
            return $payload;
        } catch (Exception $e) {
            error_log('GuardianController - JWT decode error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if user is authenticated
     */
    private function isAuthenticated(): bool
    {
        return $this->authenticatedUserId !== null;
    }

    /**
     * Get the current tenant ID with isolation
     */
    private function getTenantId(): ?int
    {
        return $this->context->getTenantId();
    }

    /**
     * Check if user is platform admin
     */
    private function isPlatformAdmin(): bool
    {
        return $this->context->isPlatformAdmin();
    }

    /**
     * Get request data from JSON body
     */
    private function getRequestData(): array
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return [];
        }
        return json_decode($input, true) ?? [];
    }

    /**
     * Log audit action
     */
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
                'guardian',
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

    // ================================================
    // GUARDIAN PROFILE ENDPOINTS
    // ================================================

    /**
     * Create guardian profile
     * POST /api/platform/index.php?endpoint=guardian&action=create_profile
     */
    public function createGuardianProfile(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                error_log('GuardianController::createGuardianProfile - Authentication failed');
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();

            // Validate required fields
            if (empty($data['person_id']) || empty($data['tenant_id']) || empty($data['relationship_type'])) {
                $this->response->error('person_id, tenant_id, and relationship_type are required', 400);
                return;
            }

            // Enforce tenant isolation
            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                if (empty($data['tenant_id'])) {
                    $data['tenant_id'] = $currentTenantId;
                } elseif ($data['tenant_id'] != $currentTenantId) {
                    $this->response->error('You do not have permission to create guardians for this tenant', 403);
                    return;
                }
            }

            $data['created_by'] = $this->authenticatedUserId;

            $result = $this->guardianService->createGuardianProfile($data);

            if ($result['success']) {
                $this->logAudit('GUARDIAN_PROFILE_CREATED', 'guardian', $result['data']['id'] ?? null, [
                    'description' => 'Created guardian profile for person ID: ' . ($data['person_id'] ?? '')
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('GuardianController::createGuardianProfile error: ' . $e->getMessage());
            $this->response->error('Error creating guardian profile: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get guardian profile
     * GET /api/platform/index.php?endpoint=guardian&action=get_profile&person_id={person_id}
     */
    public function getGuardianProfile(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $personId = isset($_GET['person_id']) ? (int)$_GET['person_id'] : 0;
            if (!$personId) {
                $this->response->error('Person ID required', 400);
                return;
            }

            $result = $this->guardianService->getGuardianProfile($personId);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 404);
            }
        } catch (Exception $e) {
            error_log('GuardianController::getGuardianProfile error: ' . $e->getMessage());
            $this->response->error('Error fetching guardian profile: ' . $e->getMessage(), 500);
        }
    }

    // ================================================
    // GUARDIAN-STUDENT RELATIONSHIP ENDPOINTS
    // ================================================

    /**
     * Link guardian to student
     * POST /api/platform/index.php?endpoint=guardian&action=link
     */
    public function linkGuardian(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();

            if (empty($data['student_person_id']) || empty($data['guardian_person_id'])) {
                $this->response->error('Student person ID and guardian person ID are required', 400);
                return;
            }

            // Enforce tenant isolation
            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                if (empty($data['tenant_id'])) {
                    $data['tenant_id'] = $currentTenantId;
                } elseif ($data['tenant_id'] != $currentTenantId) {
                    $this->response->error('You do not have permission to link guardians for this tenant', 403);
                    return;
                }
            }

            $data['created_by'] = $this->authenticatedUserId;

            $result = $this->guardianService->linkGuardianToStudent(
                $data['student_person_id'],
                $data['guardian_person_id'],
                $data
            );

            if ($result['success']) {
                $this->logAudit('GUARDIAN_LINKED', 'guardian_relationship', $result['data']['relationship_id'] ?? null, [
                    'description' => 'Linked guardian ' . $data['guardian_person_id'] . ' to student ' . $data['student_person_id']
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('GuardianController::linkGuardian error: ' . $e->getMessage());
            $this->response->error('Error linking guardian: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get guardians for a student
     * GET /api/platform/index.php?endpoint=guardian&action=for_student&student_person_id={student_person_id}
     */
    public function getGuardiansForStudent(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $studentPersonId = isset($_GET['student_person_id']) ? (int)$_GET['student_person_id'] : 0;
            if (!$studentPersonId) {
                $this->response->error('Student person ID required', 400);
                return;
            }

            $result = $this->guardianService->getGuardiansForStudent($studentPersonId);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 404);
            }
        } catch (Exception $e) {
            error_log('GuardianController::getGuardiansForStudent error: ' . $e->getMessage());
            $this->response->error('Error fetching guardians: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get students for a guardian
     * GET /api/platform/index.php?endpoint=guardian&action=for_guardian&guardian_person_id={guardian_person_id}
     */
    public function getStudentsForGuardian(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $guardianPersonId = isset($_GET['guardian_person_id']) ? (int)$_GET['guardian_person_id'] : 0;
            if (!$guardianPersonId) {
                $this->response->error('Guardian person ID required', 400);
                return;
            }

            $result = $this->guardianService->getStudentsForGuardian($guardianPersonId);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 404);
            }
        } catch (Exception $e) {
            error_log('GuardianController::getStudentsForGuardian error: ' . $e->getMessage());
            $this->response->error('Error fetching students: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update relationship
     * PUT /api/platform/index.php?endpoint=guardian&action=update_relationship&id={id}
     */
    public function updateRelationship(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();
            $result = $this->guardianService->updateRelationship($id, $data);

            if ($result['success']) {
                $this->logAudit('GUARDIAN_RELATIONSHIP_UPDATED', 'guardian_relationship', $id, [
                    'description' => 'Updated relationship ID: ' . $id,
                    'updated_fields' => array_keys($data)
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('GuardianController::updateRelationship error: ' . $e->getMessage());
            $this->response->error('Error updating relationship: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete relationship (unlink guardian)
     * DELETE /api/platform/index.php?endpoint=guardian&action=delete_relationship&id={id}
     */
    public function deleteRelationship(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $result = $this->guardianService->deleteRelationship($id);

            if ($result['success']) {
                $this->logAudit('GUARDIAN_RELATIONSHIP_DELETED', 'guardian_relationship', $id, [
                    'description' => 'Deleted relationship ID: ' . $id
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('GuardianController::deleteRelationship error: ' . $e->getMessage());
            $this->response->error('Error deleting relationship: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Set primary guardian
     * POST /api/platform/index.php?endpoint=guardian&action=set_primary
     */
    public function setPrimaryGuardian(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();

            if (empty($data['student_person_id']) || empty($data['guardian_person_id'])) {
                $this->response->error('Student person ID and guardian person ID are required', 400);
                return;
            }

            $result = $this->guardianService->setPrimaryGuardian(
                $data['student_person_id'],
                $data['guardian_person_id']
            );

            if ($result['success']) {
                $this->logAudit('PRIMARY_GUARDIAN_SET', 'guardian', null, [
                    'description' => 'Set primary guardian ' . $data['guardian_person_id'] . ' for student ' . $data['student_person_id']
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('GuardianController::setPrimaryGuardian error: ' . $e->getMessage());
            $this->response->error('Error setting primary guardian: ' . $e->getMessage(), 500);
        }
    }

    // ================================================
    // EMERGENCY CONTACT ENDPOINTS
    // ================================================

    /**
     * Add emergency contact
     * POST /api/platform/index.php?endpoint=guardian&action=add_emergency_contact
     */
    public function addEmergencyContact(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();

            if (empty($data['student_person_id'])) {
                $this->response->error('Student person ID is required', 400);
                return;
            }

            if (empty($data['contact_name']) || empty($data['phone'])) {
                $this->response->error('Contact name and phone are required', 400);
                return;
            }

            // Enforce tenant isolation
            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                if (empty($data['tenant_id'])) {
                    $data['tenant_id'] = $currentTenantId;
                } elseif ($data['tenant_id'] != $currentTenantId) {
                    $this->response->error('You do not have permission to add emergency contacts for this tenant', 403);
                    return;
                }
            }

            $data['created_by'] = $this->authenticatedUserId;

            $result = $this->guardianService->addEmergencyContact($data);

            if ($result['success']) {
                $this->logAudit('EMERGENCY_CONTACT_ADDED', 'emergency_contact', $result['data']['id'] ?? null, [
                    'description' => 'Added emergency contact for student ' . $data['student_person_id']
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('GuardianController::addEmergencyContact error: ' . $e->getMessage());
            $this->response->error('Error adding emergency contact: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get emergency contacts for a student
     * GET /api/platform/index.php?endpoint=guardian&action=get_emergency_contacts&student_person_id={student_person_id}
     */
    public function getEmergencyContacts(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $studentPersonId = isset($_GET['student_person_id']) ? (int)$_GET['student_person_id'] : 0;
            if (!$studentPersonId) {
                $this->response->error('Student person ID required', 400);
                return;
            }

            $result = $this->guardianService->getEmergencyContactsForStudent($studentPersonId);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 404);
            }
        } catch (Exception $e) {
            error_log('GuardianController::getEmergencyContacts error: ' . $e->getMessage());
            $this->response->error('Error fetching emergency contacts: ' . $e->getMessage(), 500);
        }
    }

    // ================================================
    // STATISTICS ENDPOINTS
    // ================================================

    /**
     * Get guardian statistics
     * GET /api/platform/index.php?endpoint=guardian&action=stats
     */
    public function getStats(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                error_log('Guardian stats - Authentication failed');
                $this->response->error('Authentication required', 401);
                return;
            }

            error_log('Guardian stats - Authenticated user: ' . $this->authenticatedUserId);

            $tenantId = $this->getTenantId();
            if (!$this->isPlatformAdmin()) {
                $tenantId = $tenantId ?: null;
            }

            $stats = $this->guardianService->getStats($tenantId);
            $this->response->success($stats);
        } catch (Exception $e) {
            error_log('GuardianController::getStats error: ' . $e->getMessage());
            $this->response->error('Error fetching stats: ' . $e->getMessage(), 500);
        }
    }

    // ================================================
    // LOOKUP ENDPOINTS
    // ================================================

    /**
     * Get guardian types (relationship types)
     * GET /api/platform/index.php?endpoint=guardian&action=types
     */
    public function getGuardianTypes(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $types = [
                ['code' => 'father', 'name' => 'Father', 'category' => 'parent'],
                ['code' => 'mother', 'name' => 'Mother', 'category' => 'parent'],
                ['code' => 'stepfather', 'name' => 'Stepfather', 'category' => 'parent'],
                ['code' => 'stepmother', 'name' => 'Stepmother', 'category' => 'parent'],
                ['code' => 'guardian', 'name' => 'Guardian', 'category' => 'legal'],
                ['code' => 'aunt', 'name' => 'Aunt', 'category' => 'family'],
                ['code' => 'uncle', 'name' => 'Uncle', 'category' => 'family'],
                ['code' => 'grandparent', 'name' => 'Grandparent', 'category' => 'family'],
                ['code' => 'sibling', 'name' => 'Sibling', 'category' => 'family'],
                ['code' => 'other', 'name' => 'Other', 'category' => 'other']
            ];

            $this->response->success($types);
        } catch (Exception $e) {
            error_log('GuardianController::getGuardianTypes error: ' . $e->getMessage());
            $this->response->error('Error fetching guardian types: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get guardian responsibilities
     * GET /api/platform/index.php?endpoint=guardian&action=responsibilities
     */
    public function getGuardianResponsibilities(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $responsibilities = [
                ['code' => 'financial', 'name' => 'Financial Responsibility', 'description' => 'Responsible for financial obligations'],
                ['code' => 'emergency', 'name' => 'Emergency Contact', 'description' => 'Contact in case of emergency'],
                ['code' => 'pickup', 'name' => 'Pickup Authorization', 'description' => 'Authorized to pick up student'],
                ['code' => 'communication', 'name' => 'Communication Authorization', 'description' => 'Authorized to receive communications'],
                ['code' => 'academic', 'name' => 'Academic Support', 'description' => 'Supports academic activities'],
                ['code' => 'medical', 'name' => 'Medical Authorization', 'description' => 'Authorized for medical decisions']
            ];

            $this->response->success($responsibilities);
        } catch (Exception $e) {
            error_log('GuardianController::getGuardianResponsibilities error: ' . $e->getMessage());
            $this->response->error('Error fetching responsibilities: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Check if a person is a guardian
     * GET /api/platform/index.php?endpoint=guardian&action=check&person_id={person_id}
     */
    public function checkGuardian(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $personId = isset($_GET['person_id']) ? (int)$_GET['person_id'] : 0;
            if (!$personId) {
                $this->response->error('Person ID required', 400);
                return;
            }

            $result = $this->guardianService->getGuardianProfile($personId);

            $this->response->success([
                'is_guardian' => $result['success'],
                'profile' => $result['success'] ? $result['data'] : null
            ]);
        } catch (Exception $e) {
            error_log('GuardianController::checkGuardian error: ' . $e->getMessage());
            $this->response->error('Error checking guardian status: ' . $e->getMessage(), 500);
        }
    }

    // ================================================
    // BULK OPERATIONS
    // ================================================

    /**
     * Bulk link guardians to students
     * POST /api/platform/index.php?endpoint=guardian&action=bulk_link
     */
    public function bulkLinkGuardians(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();

            if (empty($data['relationships']) || !is_array($data['relationships'])) {
                $this->response->error('Relationships array is required', 400);
                return;
            }

            // Enforce tenant isolation
            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                if (empty($data['tenant_id'])) {
                    $data['tenant_id'] = $currentTenantId;
                } elseif ($data['tenant_id'] != $currentTenantId) {
                    $this->response->error('You do not have permission to link guardians for this tenant', 403);
                    return;
                }
            }

            $results = [];
            $successCount = 0;
            $failCount = 0;

            foreach ($data['relationships'] as $relationship) {
                $relationship['tenant_id'] = $data['tenant_id'] ?? $currentTenantId;
                $relationship['created_by'] = $this->authenticatedUserId;

                $result = $this->guardianService->linkGuardianToStudent(
                    $relationship['student_person_id'],
                    $relationship['guardian_person_id'],
                    $relationship
                );

                if ($result['success']) {
                    $successCount++;
                    $results[] = $result['data'];
                } else {
                    $failCount++;
                    $results[] = ['error' => $result['message'], 'data' => $relationship];
                }
            }

            $this->logAudit('BULK_GUARDIAN_LINK', 'guardian_relationship', null, [
                'description' => 'Bulk linked ' . $successCount . ' guardians',
                'success_count' => $successCount,
                'fail_count' => $failCount
            ]);

            $this->response->success([
                'message' => "Linked $successCount guardians successfully" . ($failCount > 0 ? ", $failCount failed" : ""),
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'results' => $results
            ]);
        } catch (Exception $e) {
            error_log('GuardianController::bulkLinkGuardians error: ' . $e->getMessage());
            $this->response->error('Error bulk linking guardians: ' . $e->getMessage(), 500);
        }
    }
}
