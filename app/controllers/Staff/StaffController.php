<?php

/**
 * StaffController.php
 * Controller for Staff management endpoints
 * 
 * @package EduTrack
 * @subpackage Controllers\Staff
 * @filepath app/controllers/Staff/StaffController.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/ResponseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/services/Tenant/TenantContext.php';
require_once $projectRoot . 'app/services/Staff/StaffService.php';
require_once $projectRoot . 'app/repositories/Staff/StaffQualificationRepository.php';
require_once $projectRoot . 'app/repositories/Staff/StaffCertificationRepository.php';
require_once $projectRoot . 'app/repositories/Staff/StaffTrainingRepository.php';
require_once $projectRoot . 'app/repositories/Staff/StaffEmploymentHistoryRepository.php';
require_once $projectRoot . 'app/repositories/Staff/StaffDocumentRepository.php';
require_once $projectRoot . 'app/repositories/Staff/StaffComplianceRepository.php';

class StaffController
{
    private $response;
    private $context;
    private $staffService;
    private $db;
    private $logger;
    private $authenticatedUserId;
    private $authenticatedTenantId;

    public function __construct()
    {
        $this->response = new ResponseHelper();
        $this->context = TenantContext::getInstance();
        $this->staffService = new StaffService();
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->authenticatedUserId = null;
        $this->authenticatedTenantId = null;
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
            $this->authenticatedTenantId = $_SESSION['tenant_id'] ?? null;
            return true;
        }

        // Check Authorization header
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';

        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
            $payload = $this->decodeJWT($token);
            if ($payload && isset($payload['user_id'])) {
                $this->authenticatedUserId = $payload['user_id'];
                $this->authenticatedTenantId = $payload['tenant_id'] ?? null;
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
                return null;
            }

            $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);

            if (isset($payload['exp']) && $payload['exp'] < time()) {
                return null;
            }

            return $payload;
        } catch (Exception $e) {
            $this->logger->error('JWT decode error: ' . $e->getMessage());
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
        return $this->context->getTenantId() ?? $this->authenticatedTenantId;
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
                'staff',
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

    // ============================================
    // STAFF REGISTRATION ENDPOINTS
    // ============================================

    /**
     * Register a new staff member
     * POST /api/platform/index.php?endpoint=staff&action=register
     */
    public function register(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();

            // Validate required fields
            $required = ['first_name', 'last_name', 'tenant_id', 'school_id', 'hire_date'];
            $missing = [];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $missing[] = $field;
                }
            }
            if (!empty($missing)) {
                $this->response->error('Missing required fields: ' . implode(', ', $missing), 400);
                return;
            }

            // Enforce tenant isolation
            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                if (empty($data['tenant_id'])) {
                    $data['tenant_id'] = $currentTenantId;
                } elseif ($data['tenant_id'] != $currentTenantId) {
                    $this->response->error('You do not have permission to register staff for this tenant', 403);
                    return;
                }
            }

            $data['created_by'] = $this->authenticatedUserId;

            $result = $this->staffService->registerStaff($data);

            if ($result['success']) {
                $this->logAudit('STAFF_REGISTERED', 'staff', $result['data']['id'] ?? null, [
                    'description' => 'Registered staff: ' . ($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''),
                    'staff_number' => $result['data']['staff_number'] ?? null
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::register error: ' . $e->getMessage());
            $this->response->error('Error registering staff: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get staff by ID
     * GET /api/platform/index.php?endpoint=staff&action=get&id={id}
     */
    public function getStaff(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $result = $this->staffService->getStaff($id);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 404);
            }
        } catch (Exception $e) {
            error_log('StaffController::getStaff error: ' . $e->getMessage());
            $this->response->error('Error fetching staff: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get staff by person ID
     * GET /api/platform/index.php?endpoint=staff&action=by_person&person_id={person_id}
     */
    public function getStaffByPerson(): void
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

            $result = $this->staffService->getStaffByPersonId($personId);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 404);
            }
        } catch (Exception $e) {
            error_log('StaffController::getStaffByPerson error: ' . $e->getMessage());
            $this->response->error('Error fetching staff: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get staff by staff number
     * GET /api/platform/index.php?endpoint=staff&action=by_number&number={staff_number}
     */
    public function getStaffByNumber(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $staffNumber = isset($_GET['number']) ? trim($_GET['number']) : '';
            if (empty($staffNumber)) {
                $this->response->error('Staff number required', 400);
                return;
            }

            $tenantId = $this->getTenantId();
            if (!$this->isPlatformAdmin()) {
                $tenantId = $tenantId ?: null;
            }

            $repo = new StaffRepository();
            $staff = $repo->getByStaffNumber($staffNumber, $tenantId);

            if ($staff) {
                $this->response->success($staff);
            } else {
                $this->response->error('Staff not found', 404);
            }
        } catch (Exception $e) {
            error_log('StaffController::getStaffByNumber error: ' . $e->getMessage());
            $this->response->error('Error fetching staff: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Search staff
     * GET /api/platform/index.php?endpoint=staff&action=search
     */
    public function search(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $status = isset($_GET['status']) ? trim($_GET['status']) : '';
            $employeeType = isset($_GET['employee_type']) ? trim($_GET['employee_type']) : '';
            $departmentId = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
            $positionId = isset($_GET['position_id']) ? (int)$_GET['position_id'] : 0;
            $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

            $filters = [
                'search' => $search,
                'employment_status' => $status,
                'employee_type' => $employeeType,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'school_id' => $schoolId
            ];

            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                $filters['tenant_id'] = $currentTenantId;
            }

            $result = $this->staffService->searchStaff($filters, $page, $limit);
            $this->response->success($result);
        } catch (Exception $e) {
            error_log('StaffController::search error: ' . $e->getMessage());
            $this->response->error('Error searching staff: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update staff
     * PUT /api/platform/index.php?endpoint=staff&action=update&id={id}
     */
    public function updateStaff(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();
            $result = $this->staffService->updateStaff($id, $data);

            if ($result['success']) {
                $this->logAudit('STAFF_UPDATED', 'staff', $id, [
                    'description' => 'Updated staff ID: ' . $id,
                    'updated_fields' => array_keys($data)
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::updateStaff error: ' . $e->getMessage());
            $this->response->error('Error updating staff: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update employment status
     * PUT /api/platform/index.php?endpoint=staff&action=update_status&id={id}
     */
    public function updateStatus(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();
            $status = $data['status'] ?? '';
            $notes = $data['notes'] ?? null;

            if (empty($status)) {
                $this->response->error('Status is required', 400);
                return;
            }

            $validStatuses = ['active', 'inactive', 'on_leave', 'suspended', 'terminated', 'resigned', 'retired'];
            if (!in_array($status, $validStatuses)) {
                $this->response->error('Invalid status. Valid values: ' . implode(', ', $validStatuses), 400);
                return;
            }

            $result = $this->staffService->updateEmploymentStatus($id, $status, $notes);

            if ($result['success']) {
                $this->logAudit('STAFF_STATUS_UPDATED', 'staff', $id, [
                    'description' => 'Updated staff status to: ' . $status
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::updateStatus error: ' . $e->getMessage());
            $this->response->error('Error updating status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete staff
     * DELETE /api/platform/index.php?endpoint=staff&action=delete&id={id}
     */
    public function deleteStaff(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $result = $this->staffService->deleteStaff($id);

            if ($result['success']) {
                $this->logAudit('STAFF_DELETED', 'staff', $id, [
                    'description' => 'Deleted staff ID: ' . $id
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::deleteStaff error: ' . $e->getMessage());
            $this->response->error('Error deleting staff: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get staff statistics
     * GET /api/platform/index.php?endpoint=staff&action=stats
     */
    public function getStats(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $tenantId = $this->getTenantId();
            if (!$this->isPlatformAdmin()) {
                $tenantId = $tenantId ?: null;
            }

            $stats = $this->staffService->getStats($tenantId);
            $this->response->success($stats);
        } catch (Exception $e) {
            error_log('StaffController::getStats error: ' . $e->getMessage());
            $this->response->error('Error fetching stats: ' . $e->getMessage(), 500);
        }
    }

    // ============================================
    // QUALIFICATIONS ENDPOINTS
    // ============================================

    /**
     * Add qualification to staff
     * POST /api/platform/index.php?endpoint=staff&action=add_qualification&id={id}
     */
    public function addQualification(int $staffId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            // Get staff person ID
            $staff = $this->staffService->getStaff($staffId);
            if (!$staff['success']) {
                $this->response->error('Staff not found', 404);
                return;
            }

            $data = $this->getRequestData();
            $data['staff_person_id'] = $staff['data']['person_id'];
            $data['tenant_id'] = $this->getTenantId() ?? 1;
            $data['created_by'] = $this->authenticatedUserId;

            $repo = new StaffQualificationRepository();
            $id = $repo->create($data);

            if ($id) {
                $this->logAudit('STAFF_QUALIFICATION_ADDED', 'staff_qualification', $id, [
                    'description' => 'Added qualification for staff ID: ' . $staffId
                ]);
                $this->response->success(['message' => 'Qualification added successfully', 'id' => $id]);
            } else {
                $this->response->error('Failed to add qualification', 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::addQualification error: ' . $e->getMessage());
            $this->response->error('Error adding qualification: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get staff qualifications
     * GET /api/platform/index.php?endpoint=staff&action=get_qualifications&id={id}
     */
    public function getQualifications(int $staffId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            // Get staff person ID
            $staff = $this->staffService->getStaff($staffId);
            if (!$staff['success']) {
                $this->response->error('Staff not found', 404);
                return;
            }

            $repo = new StaffQualificationRepository();
            $qualifications = $repo->getByStaffId($staff['data']['person_id']);
            $this->response->success($qualifications);
        } catch (Exception $e) {
            error_log('StaffController::getQualifications error: ' . $e->getMessage());
            $this->response->error('Error fetching qualifications: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Verify qualification
     * PUT /api/platform/index.php?endpoint=staff&action=verify_qualification&id={id}
     */
    public function verifyQualification(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $repo = new StaffQualificationRepository();
            $qualification = $repo->getById($id);
            if (!$qualification) {
                $this->response->error('Qualification not found', 404);
                return;
            }

            $result = $repo->verify($id, $this->authenticatedUserId);

            if ($result) {
                $this->logAudit('STAFF_QUALIFICATION_VERIFIED', 'staff_qualification', $id, [
                    'description' => 'Verified qualification ID: ' . $id
                ]);
                $this->response->success(['message' => 'Qualification verified successfully']);
            } else {
                $this->response->error('Failed to verify qualification', 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::verifyQualification error: ' . $e->getMessage());
            $this->response->error('Error verifying qualification: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete qualification
     * DELETE /api/platform/index.php?endpoint=staff&action=delete_qualification&id={id}
     */
    public function deleteQualification(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $repo = new StaffQualificationRepository();
            $qualification = $repo->getById($id);
            if (!$qualification) {
                $this->response->error('Qualification not found', 404);
                return;
            }

            $result = $repo->delete($id);

            if ($result) {
                $this->logAudit('STAFF_QUALIFICATION_DELETED', 'staff_qualification', $id, [
                    'description' => 'Deleted qualification ID: ' . $id
                ]);
                $this->response->success(['message' => 'Qualification deleted successfully']);
            } else {
                $this->response->error('Failed to delete qualification', 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::deleteQualification error: ' . $e->getMessage());
            $this->response->error('Error deleting qualification: ' . $e->getMessage(), 500);
        }
    }

    // ============================================
    // CERTIFICATIONS ENDPOINTS
    // ============================================

    /**
     * Add certification to staff
     * POST /api/platform/index.php?endpoint=staff&action=add_certification&id={id}
     */
    public function addCertification(int $staffId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $staff = $this->staffService->getStaff($staffId);
            if (!$staff['success']) {
                $this->response->error('Staff not found', 404);
                return;
            }

            $data = $this->getRequestData();
            $data['staff_person_id'] = $staff['data']['person_id'];
            $data['tenant_id'] = $this->getTenantId() ?? 1;
            $data['created_by'] = $this->authenticatedUserId;

            $repo = new StaffCertificationRepository();
            $id = $repo->create($data);

            if ($id) {
                $this->logAudit('STAFF_CERTIFICATION_ADDED', 'staff_certification', $id, [
                    'description' => 'Added certification for staff ID: ' . $staffId
                ]);
                $this->response->success(['message' => 'Certification added successfully', 'id' => $id]);
            } else {
                $this->response->error('Failed to add certification', 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::addCertification error: ' . $e->getMessage());
            $this->response->error('Error adding certification: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get staff certifications
     * GET /api/platform/index.php?endpoint=staff&action=get_certifications&id={id}
     */
    public function getCertifications(int $staffId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $staff = $this->staffService->getStaff($staffId);
            if (!$staff['success']) {
                $this->response->error('Staff not found', 404);
                return;
            }

            $repo = new StaffCertificationRepository();
            $certifications = $repo->getByStaffId($staff['data']['person_id']);
            $this->response->success($certifications);
        } catch (Exception $e) {
            error_log('StaffController::getCertifications error: ' . $e->getMessage());
            $this->response->error('Error fetching certifications: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Verify certification
     * PUT /api/platform/index.php?endpoint=staff&action=verify_certification&id={id}
     */
    public function verifyCertification(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $repo = new StaffCertificationRepository();
            $certification = $repo->getById($id);
            if (!$certification) {
                $this->response->error('Certification not found', 404);
                return;
            }

            $result = $repo->verify($id, $this->authenticatedUserId);

            if ($result) {
                $this->logAudit('STAFF_CERTIFICATION_VERIFIED', 'staff_certification', $id, [
                    'description' => 'Verified certification ID: ' . $id
                ]);
                $this->response->success(['message' => 'Certification verified successfully']);
            } else {
                $this->response->error('Failed to verify certification', 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::verifyCertification error: ' . $e->getMessage());
            $this->response->error('Error verifying certification: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete certification
     * DELETE /api/platform/index.php?endpoint=staff&action=delete_certification&id={id}
     */
    public function deleteCertification(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $repo = new StaffCertificationRepository();
            $certification = $repo->getById($id);
            if (!$certification) {
                $this->response->error('Certification not found', 404);
                return;
            }

            $result = $repo->delete($id);

            if ($result) {
                $this->logAudit('STAFF_CERTIFICATION_DELETED', 'staff_certification', $id, [
                    'description' => 'Deleted certification ID: ' . $id
                ]);
                $this->response->success(['message' => 'Certification deleted successfully']);
            } else {
                $this->response->error('Failed to delete certification', 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::deleteCertification error: ' . $e->getMessage());
            $this->response->error('Error deleting certification: ' . $e->getMessage(), 500);
        }
    }

    // ============================================
    // TRAINING/CPD ENDPOINTS
    // ============================================

    /**
     * Add training to staff
     * POST /api/platform/index.php?endpoint=staff&action=add_training&id={id}
     */
    public function addTraining(int $staffId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $staff = $this->staffService->getStaff($staffId);
            if (!$staff['success']) {
                $this->response->error('Staff not found', 404);
                return;
            }

            $data = $this->getRequestData();
            $data['staff_person_id'] = $staff['data']['person_id'];
            $data['tenant_id'] = $this->getTenantId() ?? 1;
            $data['created_by'] = $this->authenticatedUserId;

            $repo = new StaffTrainingRepository();
            $id = $repo->create($data);

            if ($id) {
                $this->logAudit('STAFF_TRAINING_ADDED', 'staff_training', $id, [
                    'description' => 'Added training for staff ID: ' . $staffId
                ]);
                $this->response->success(['message' => 'Training added successfully', 'id' => $id]);
            } else {
                $this->response->error('Failed to add training', 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::addTraining error: ' . $e->getMessage());
            $this->response->error('Error adding training: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get staff trainings
     * GET /api/platform/index.php?endpoint=staff&action=get_trainings&id={id}
     */
    public function getTrainings(int $staffId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $staff = $this->staffService->getStaff($staffId);
            if (!$staff['success']) {
                $this->response->error('Staff not found', 404);
                return;
            }

            $repo = new StaffTrainingRepository();
            $trainings = $repo->getByStaffId($staff['data']['person_id']);
            $this->response->success($trainings);
        } catch (Exception $e) {
            error_log('StaffController::getTrainings error: ' . $e->getMessage());
            $this->response->error('Error fetching trainings: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Verify training
     * PUT /api/platform/index.php?endpoint=staff&action=verify_training&id={id}
     */
    public function verifyTraining(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $repo = new StaffTrainingRepository();
            $training = $repo->getById($id);
            if (!$training) {
                $this->response->error('Training not found', 404);
                return;
            }

            $result = $repo->verify($id, $this->authenticatedUserId);

            if ($result) {
                $this->logAudit('STAFF_TRAINING_VERIFIED', 'staff_training', $id, [
                    'description' => 'Verified training ID: ' . $id
                ]);
                $this->response->success(['message' => 'Training verified successfully']);
            } else {
                $this->response->error('Failed to verify training', 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::verifyTraining error: ' . $e->getMessage());
            $this->response->error('Error verifying training: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete training
     * DELETE /api/platform/index.php?endpoint=staff&action=delete_training&id={id}
     */
    public function deleteTraining(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $repo = new StaffTrainingRepository();
            $training = $repo->getById($id);
            if (!$training) {
                $this->response->error('Training not found', 404);
                return;
            }

            $result = $repo->delete($id);

            if ($result) {
                $this->logAudit('STAFF_TRAINING_DELETED', 'staff_training', $id, [
                    'description' => 'Deleted training ID: ' . $id
                ]);
                $this->response->success(['message' => 'Training deleted successfully']);
            } else {
                $this->response->error('Failed to delete training', 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::deleteTraining error: ' . $e->getMessage());
            $this->response->error('Error deleting training: ' . $e->getMessage(), 500);
        }
    }

    // ============================================
    // EMPLOYMENT HISTORY ENDPOINTS
    // ============================================

    /**
     * Add employment history
     * POST /api/platform/index.php?endpoint=staff&action=add_employment_history&id={id}
     */
    public function addEmploymentHistory(int $staffId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $staff = $this->staffService->getStaff($staffId);
            if (!$staff['success']) {
                $this->response->error('Staff not found', 404);
                return;
            }

            $data = $this->getRequestData();
            $data['staff_person_id'] = $staff['data']['person_id'];
            $data['tenant_id'] = $this->getTenantId() ?? 1;
            $data['created_by'] = $this->authenticatedUserId;

            $repo = new StaffEmploymentHistoryRepository();
            $id = $repo->create($data);

            if ($id) {
                $this->logAudit('STAFF_EMPLOYMENT_HISTORY_ADDED', 'staff_employment_history', $id, [
                    'description' => 'Added employment history for staff ID: ' . $staffId
                ]);
                $this->response->success(['message' => 'Employment history added successfully', 'id' => $id]);
            } else {
                $this->response->error('Failed to add employment history', 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::addEmploymentHistory error: ' . $e->getMessage());
            $this->response->error('Error adding employment history: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get employment history
     * GET /api/platform/index.php?endpoint=staff&action=get_employment_history&id={id}
     */
    public function getEmploymentHistory(int $staffId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $staff = $this->staffService->getStaff($staffId);
            if (!$staff['success']) {
                $this->response->error('Staff not found', 404);
                return;
            }

            $repo = new StaffEmploymentHistoryRepository();
            $history = $repo->getByStaffId($staff['data']['person_id']);
            $this->response->success($history);
        } catch (Exception $e) {
            error_log('StaffController::getEmploymentHistory error: ' . $e->getMessage());
            $this->response->error('Error fetching employment history: ' . $e->getMessage(), 500);
        }
    }

    // ============================================
    // DOCUMENTS ENDPOINTS
    // ============================================

    /**
     * Add document to staff
     * POST /api/platform/index.php?endpoint=staff&action=add_document&id={id}
     */
    public function addDocument(int $staffId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $staff = $this->staffService->getStaff($staffId);
            if (!$staff['success']) {
                $this->response->error('Staff not found', 404);
                return;
            }

            $data = $this->getRequestData();
            $data['staff_person_id'] = $staff['data']['person_id'];
            $data['tenant_id'] = $this->getTenantId() ?? 1;
            $data['created_by'] = $this->authenticatedUserId;

            $repo = new StaffDocumentRepository();
            $id = $repo->create($data);

            if ($id) {
                $this->logAudit('STAFF_DOCUMENT_ADDED', 'staff_document', $id, [
                    'description' => 'Added document for staff ID: ' . $staffId
                ]);
                $this->response->success(['message' => 'Document added successfully', 'id' => $id]);
            } else {
                $this->response->error('Failed to add document', 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::addDocument error: ' . $e->getMessage());
            $this->response->error('Error adding document: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get staff documents
     * GET /api/platform/index.php?endpoint=staff&action=get_documents&id={id}
     */
    public function getDocuments(int $staffId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $staff = $this->staffService->getStaff($staffId);
            if (!$staff['success']) {
                $this->response->error('Staff not found', 404);
                return;
            }

            $repo = new StaffDocumentRepository();
            $documents = $repo->getByStaffId($staff['data']['person_id']);
            $this->response->success($documents);
        } catch (Exception $e) {
            error_log('StaffController::getDocuments error: ' . $e->getMessage());
            $this->response->error('Error fetching documents: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Verify document
     * PUT /api/platform/index.php?endpoint=staff&action=verify_document&id={id}
     */
    public function verifyDocument(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $repo = new StaffDocumentRepository();
            $document = $repo->getById($id);
            if (!$document) {
                $this->response->error('Document not found', 404);
                return;
            }

            $result = $repo->verify($id, $this->authenticatedUserId);

            if ($result) {
                $this->logAudit('STAFF_DOCUMENT_VERIFIED', 'staff_document', $id, [
                    'description' => 'Verified document ID: ' . $id
                ]);
                $this->response->success(['message' => 'Document verified successfully']);
            } else {
                $this->response->error('Failed to verify document', 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::verifyDocument error: ' . $e->getMessage());
            $this->response->error('Error verifying document: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete document
     * DELETE /api/platform/index.php?endpoint=staff&action=delete_document&id={id}
     */
    public function deleteDocument(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $repo = new StaffDocumentRepository();
            $document = $repo->getById($id);
            if (!$document) {
                $this->response->error('Document not found', 404);
                return;
            }

            $result = $repo->delete($id);

            if ($result) {
                $this->logAudit('STAFF_DOCUMENT_DELETED', 'staff_document', $id, [
                    'description' => 'Deleted document ID: ' . $id
                ]);
                $this->response->success(['message' => 'Document deleted successfully']);
            } else {
                $this->response->error('Failed to delete document', 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::deleteDocument error: ' . $e->getMessage());
            $this->response->error('Error deleting document: ' . $e->getMessage(), 500);
        }
    }

    // ============================================
    // COMPLIANCE ENDPOINTS
    // ============================================

    /**
     * Add compliance record
     * POST /api/platform/index.php?endpoint=staff&action=add_compliance&id={id}
     */
    public function addCompliance(int $staffId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $staff = $this->staffService->getStaff($staffId);
            if (!$staff['success']) {
                $this->response->error('Staff not found', 404);
                return;
            }

            $data = $this->getRequestData();
            $data['staff_person_id'] = $staff['data']['person_id'];
            $data['tenant_id'] = $this->getTenantId() ?? 1;
            $data['created_by'] = $this->authenticatedUserId;

            $repo = new StaffComplianceRepository();
            $id = $repo->create($data);

            if ($id) {
                $this->logAudit('STAFF_COMPLIANCE_ADDED', 'staff_compliance', $id, [
                    'description' => 'Added compliance record for staff ID: ' . $staffId
                ]);
                $this->response->success(['message' => 'Compliance record added successfully', 'id' => $id]);
            } else {
                $this->response->error('Failed to add compliance record', 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::addCompliance error: ' . $e->getMessage());
            $this->response->error('Error adding compliance record: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get staff compliance records
     * GET /api/platform/index.php?endpoint=staff&action=get_compliance&id={id}
     */
    public function getCompliance(int $staffId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $staff = $this->staffService->getStaff($staffId);
            if (!$staff['success']) {
                $this->response->error('Staff not found', 404);
                return;
            }

            $repo = new StaffComplianceRepository();
            $compliance = $repo->getByStaffId($staff['data']['person_id']);
            $this->response->success($compliance);
        } catch (Exception $e) {
            error_log('StaffController::getCompliance error: ' . $e->getMessage());
            $this->response->error('Error fetching compliance records: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update compliance status
     * PUT /api/platform/index.php?endpoint=staff&action=update_compliance_status&id={id}
     */
    public function updateComplianceStatus(int $id): void
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

            $validStatuses = ['pending', 'in_progress', 'completed', 'expired', 'exempt'];
            if (!in_array($status, $validStatuses)) {
                $this->response->error('Invalid status. Valid values: ' . implode(', ', $validStatuses), 400);
                return;
            }

            $repo = new StaffComplianceRepository();
            $compliance = $repo->getById($id);
            if (!$compliance) {
                $this->response->error('Compliance record not found', 404);
                return;
            }

            $result = $repo->updateStatus($id, $status);

            if ($result) {
                $this->logAudit('STAFF_COMPLIANCE_STATUS_UPDATED', 'staff_compliance', $id, [
                    'description' => 'Updated compliance status to: ' . $status
                ]);
                $this->response->success(['message' => 'Compliance status updated successfully']);
            } else {
                $this->response->error('Failed to update compliance status', 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::updateComplianceStatus error: ' . $e->getMessage());
            $this->response->error('Error updating compliance status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete compliance record
     * DELETE /api/platform/index.php?endpoint=staff&action=delete_compliance&id={id}
     */
    public function deleteCompliance(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $repo = new StaffComplianceRepository();
            $compliance = $repo->getById($id);
            if (!$compliance) {
                $this->response->error('Compliance record not found', 404);
                return;
            }

            $result = $repo->delete($id);

            if ($result) {
                $this->logAudit('STAFF_COMPLIANCE_DELETED', 'staff_compliance', $id, [
                    'description' => 'Deleted compliance record ID: ' . $id
                ]);
                $this->response->success(['message' => 'Compliance record deleted successfully']);
            } else {
                $this->response->error('Failed to delete compliance record', 400);
            }
        } catch (Exception $e) {
            error_log('StaffController::deleteCompliance error: ' . $e->getMessage());
            $this->response->error('Error deleting compliance record: ' . $e->getMessage(), 500);
        }
    }

    // ============================================
    // STAFF TYPES & LOOKUP
    // ============================================

    /**
     * Get employee types
     * GET /api/platform/index.php?endpoint=staff&action=employee_types
     */
    public function getEmployeeTypes(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $types = [
                ['code' => 'full_time', 'name' => 'Full Time', 'category' => 'permanent'],
                ['code' => 'part_time', 'name' => 'Part Time', 'category' => 'permanent'],
                ['code' => 'contract', 'name' => 'Contract', 'category' => 'temporary'],
                ['code' => 'casual', 'name' => 'Casual', 'category' => 'temporary'],
                ['code' => 'intern', 'name' => 'Intern', 'category' => 'temporary'],
                ['code' => 'volunteer', 'name' => 'Volunteer', 'category' => 'volunteer']
            ];

            $this->response->success($types);
        } catch (Exception $e) {
            error_log('StaffController::getEmployeeTypes error: ' . $e->getMessage());
            $this->response->error('Error fetching employee types: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get employment statuses
     * GET /api/platform/index.php?endpoint=staff&action=employment_statuses
     */
    public function getEmploymentStatuses(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $statuses = [
                ['code' => 'active', 'name' => 'Active', 'color' => 'success'],
                ['code' => 'inactive', 'name' => 'Inactive', 'color' => 'secondary'],
                ['code' => 'on_leave', 'name' => 'On Leave', 'color' => 'warning'],
                ['code' => 'suspended', 'name' => 'Suspended', 'color' => 'danger'],
                ['code' => 'terminated', 'name' => 'Terminated', 'color' => 'danger'],
                ['code' => 'resigned', 'name' => 'Resigned', 'color' => 'secondary'],
                ['code' => 'retired', 'name' => 'Retired', 'color' => 'secondary']
            ];

            $this->response->success($statuses);
        } catch (Exception $e) {
            error_log('StaffController::getEmploymentStatuses error: ' . $e->getMessage());
            $this->response->error('Error fetching employment statuses: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get qualification types
     * GET /api/platform/index.php?endpoint=staff&action=qualification_types
     */
    public function getQualificationTypes(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $types = [
                ['code' => 'academic', 'name' => 'Academic'],
                ['code' => 'professional', 'name' => 'Professional'],
                ['code' => 'vocational', 'name' => 'Vocational'],
                ['code' => 'other', 'name' => 'Other']
            ];

            $this->response->success($types);
        } catch (Exception $e) {
            error_log('StaffController::getQualificationTypes error: ' . $e->getMessage());
            $this->response->error('Error fetching qualification types: ' . $e->getMessage(), 500);
        }
    } 
        // ============================================
    // LIST STAFF - PAGINATED WITH FILTERS
    // ============================================
    /**
     * List staff with pagination and filters
     * GET /api/platform/index.php?endpoint=staff&action=list
     */
    public function listStaff(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            $offset = ($page - 1) * $limit;
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
            $campusId = isset($_GET['campus_id']) ? (int)$_GET['campus_id'] : 0;
            $status = isset($_GET['status']) ? trim($_GET['status']) : '';
            $staffCategoryId = isset($_GET['staff_category_id']) ? (int)$_GET['staff_category_id'] : 0;
            $departmentId = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;

            $params = [];
            $where = ["s.deleted_at IS NULL"];

            // Tenant isolation
            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                $where[] = "s.tenant_id = ?";
                $params[] = $currentTenantId;
            } elseif ($currentTenantId) {
                $where[] = "s.tenant_id = ?";
                $params[] = $currentTenantId;
            }

            if ($schoolId > 0) {
                $where[] = "s.school_id = ?";
                $params[] = $schoolId;
            }

            if ($campusId > 0) {
                $where[] = "s.campus_id = ?";
                $params[] = $campusId;
            }

            if ($staffCategoryId > 0) {
                $where[] = "s.staff_category_id = ?";
                $params[] = $staffCategoryId;
            }

            if ($departmentId > 0) {
                $where[] = "s.department_id = ?";
                $params[] = $departmentId;
            }

            if (!empty($status)) {
                $where[] = "s.is_active = ?";
                $params[] = ($status === 'active') ? 1 : 0;
            }

            if (!empty($search)) {
                $where[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR s.staff_number LIKE ? OR p.email LIKE ?)";
                $searchTerm = '%' . $search . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            $whereClause = implode(" AND ", $where);

            // Get total count
            $countSql = "SELECT COUNT(*) as total 
                         FROM staff s 
                         LEFT JOIN persons p ON s.person_id = p.id 
                         WHERE $whereClause";
            $countResult = $this->db->fetchOne($countSql, $params);
            $total = (int)($countResult['total'] ?? 0);

            // Get staff list
            $sql = "SELECT 
                        s.id,
                        s.uuid,
                        s.staff_number,
                        s.school_id,
                        s.campus_id,
                        s.tenant_id,
                        s.staff_category_id,
                        s.department_id,
                        s.designation_id,
                        s.employment_type_id,
                        s.staff_status_id,
                        s.joining_date,
                        s.confirmation_date,
                        s.is_active,
                        s.is_teaching_staff,
                        s.created_at,
                        s.platform_user_id,
                        p.id as person_id,
                        p.first_name,
                        p.middle_name,
                        p.last_name,
                        p.primary_phone,
                        p.secondary_phone,
                        p.email,
                        p.secondary_email,
                        p.gender,
                        p.date_of_birth,
                        p.address as person_address,
                        p.profile_photo,
                        sc.category_name as staff_category,
                        d.department_name,
                        des.designation_name,
                        et.employment_type_name,
                        ss.status_name as staff_status,
                        sch.school_name,
                        cmp.campus_name,
                        t.tenant_name
                    FROM staff s
                    LEFT JOIN persons p ON s.person_id = p.id
                    LEFT JOIN staff_categories sc ON s.staff_category_id = sc.id
                    LEFT JOIN departments d ON s.department_id = d.id
                    LEFT JOIN designations des ON s.designation_id = des.id
                    LEFT JOIN employment_types et ON s.employment_type_id = et.id
                    LEFT JOIN staff_statuses ss ON s.staff_status_id = ss.id
                    LEFT JOIN schools sch ON s.school_id = sch.id
                    LEFT JOIN campuses cmp ON s.campus_id = cmp.id
                    LEFT JOIN tenants t ON s.tenant_id = t.id
                    WHERE $whereClause
                    ORDER BY p.first_name ASC, p.last_name ASC
                    LIMIT ? OFFSET ?";

            $queryParams = array_merge($params, [$limit, $offset]);
            $staff = $this->db->fetchAll($sql, $queryParams);

            $this->response->success([
                'staff' => $staff,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
        } catch (Exception $e) {
            error_log('StaffController::listStaff error: ' . $e->getMessage());
            $this->response->error('Error fetching staff: ' . $e->getMessage(), 500);
        }
    }

    // ============================================
    // AUTOCOMPLETE STAFF
    // ============================================
    /**
     * Autocomplete staff for search
     * GET /api/platform/index.php?endpoint=staff&action=autocomplete
     */
    public function autocompleteStaff(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

            if (empty($search) || strlen($search) < 2) {
                $this->response->success([]);
                return;
            }

            $params = [];
            $where = ["s.deleted_at IS NULL"];

            // Tenant isolation
            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                $where[] = "s.tenant_id = ?";
                $params[] = $currentTenantId;
            } elseif ($currentTenantId) {
                $where[] = "s.tenant_id = ?";
                $params[] = $currentTenantId;
            }

            if ($schoolId > 0) {
                $where[] = "s.school_id = ?";
                $params[] = $schoolId;
            }

            $searchTerm = '%' . $search . '%';
            $where[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR s.staff_number LIKE ? OR p.email LIKE ?)";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;

            $whereClause = implode(" AND ", $where);

            $sql = "SELECT 
                        s.id,
                        s.staff_number,
                        s.school_id,
                        s.campus_id,
                        p.id as person_id,
                        p.first_name,
                        p.last_name,
                        p.email,
                        p.primary_phone,
                        CONCAT(p.first_name, ' ', p.last_name) as full_name,
                        sc.category_name as staff_category,
                        sch.school_name
                    FROM staff s
                    LEFT JOIN persons p ON s.person_id = p.id
                    LEFT JOIN staff_categories sc ON s.staff_category_id = sc.id
                    LEFT JOIN schools sch ON s.school_id = sch.id
                    WHERE $whereClause
                    ORDER BY p.first_name ASC
                    LIMIT ?";

            $params[] = $limit;
            $results = $this->db->fetchAll($sql, $params);

            $this->response->success($results);
        } catch (Exception $e) {
            error_log('StaffController::autocompleteStaff error: ' . $e->getMessage());
            $this->response->error('Error searching staff: ' . $e->getMessage(), 500);
        }
    }
}
