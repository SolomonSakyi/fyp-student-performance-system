<?php

/**
 * StudentController.php
 * Controller for Student management endpoints
 * 
 * @package EduTrack
 * @subpackage Controllers\Student
 * @filepath app/controllers/Student/StudentController.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/ResponseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/services/Tenant/TenantContext.php';
require_once $projectRoot . 'app/services/Student/StudentService.php';

class StudentController
{
    private $response;
    private $context;
    private $studentService;
    private $db;
    private $logger;
    private $authenticatedUserId;

    public function __construct()
    {
        $this->response = new ResponseHelper();
        $this->context = TenantContext::getInstance();
        $this->studentService = new StudentService();
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->authenticatedUserId = null;
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

    /**
     * Check if user is authenticated
     */
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
                'student',
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
     * Register a new student
     * POST /api/platform/index.php?endpoint=student&action=register
     */
    public function register(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();

            // Enforce tenant isolation
            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                if (empty($data['tenant_id'])) {
                    $data['tenant_id'] = $currentTenantId;
                } elseif ($data['tenant_id'] != $currentTenantId) {
                    $this->response->error('You do not have permission to register students for this tenant', 403);
                    return;
                }
            }

            $data['created_by'] = $this->authenticatedUserId;

            $result = $this->studentService->registerStudent($data);

            if ($result['success']) {
                $this->logAudit('STUDENT_REGISTERED', 'student', $result['data']['id'] ?? null, [
                    'description' => 'Registered student: ' . ($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''),
                    'student_number' => $result['data']['student_number'] ?? null
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('StudentController::register error: ' . $e->getMessage());
            $this->response->error('Error registering student: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get student by ID
     * GET /api/platform/index.php?endpoint=student&action=get&id={id}
     */
    public function getStudent(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $result = $this->studentService->getStudent($id);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 404);
            }
        } catch (Exception $e) {
            error_log('StudentController::getStudent error: ' . $e->getMessage());
            $this->response->error('Error fetching student: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get student by person ID
     * GET /api/platform/index.php?endpoint=student&action=by_person&person_id={person_id}
     */
    public function getStudentByPerson(): void
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

            $result = $this->studentService->getStudentByPersonId($personId);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 404);
            }
        } catch (Exception $e) {
            error_log('StudentController::getStudentByPerson error: ' . $e->getMessage());
            $this->response->error('Error fetching student: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get student by student number
     * GET /api/platform/index.php?endpoint=student&action=by_number&number={student_number}
     */
    public function getStudentByNumber(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $studentNumber = isset($_GET['number']) ? trim($_GET['number']) : '';
            if (empty($studentNumber)) {
                $this->response->error('Student number required', 400);
                return;
            }

            $tenantId = $this->getTenantId();
            if (!$this->isPlatformAdmin()) {
                $tenantId = $tenantId ?: null;
            }

            // Need to inject studentRepo or use the service
            $studentRepo = new StudentRepository();
            $result = $studentRepo->getByStudentNumber($studentNumber, $tenantId);

            if ($result) {
                $this->response->success($result);
            } else {
                $this->response->error('Student not found', 404);
            }
        } catch (Exception $e) {
            error_log('StudentController::getStudentByNumber error: ' . $e->getMessage());
            $this->response->error('Error fetching student: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Search students
     * GET /api/platform/index.php?endpoint=student&action=search
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
            $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
            $classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
            $gradeLevelId = isset($_GET['grade_level_id']) ? (int)$_GET['grade_level_id'] : 0;

            $filters = [
                'search' => $search,
                'enrollment_status' => $status,
                'school_id' => $schoolId,
                'class_id' => $classId,
                'grade_level_id' => $gradeLevelId
            ];

            // Enforce tenant isolation
            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                $filters['tenant_id'] = $currentTenantId;
            }

            $result = $this->studentService->searchStudents($filters, $page, $limit);

            $this->response->success($result);
        } catch (Exception $e) {
            error_log('StudentController::search error: ' . $e->getMessage());
            $this->response->error('Error searching students: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get student statistics
     * GET /api/platform/index.php?endpoint=student&action=stats
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

            $stats = $this->studentService->getStats($tenantId);
            $this->response->success($stats);
        } catch (Exception $e) {
            error_log('StudentController::getStats error: ' . $e->getMessage());
            $this->response->error('Error fetching stats: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update student
     * PUT /api/platform/index.php?endpoint=student&action=update&id={id}
     */
    public function updateStudent(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();
            $result = $this->studentService->updateStudent($id, $data);

            if ($result['success']) {
                $this->logAudit('STUDENT_UPDATED', 'student', $id, [
                    'description' => 'Updated student ID: ' . $id,
                    'updated_fields' => array_keys($data)
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('StudentController::updateStudent error: ' . $e->getMessage());
            $this->response->error('Error updating student: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update enrollment status
     * PUT /api/platform/index.php?endpoint=student&action=update_status&id={id}
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

            $validStatuses = ['active', 'inactive', 'graduated', 'transferred', 'withdrawn', 'suspended', 'expelled'];
            if (!in_array($status, $validStatuses)) {
                $this->response->error('Invalid status. Valid values: ' . implode(', ', $validStatuses), 400);
                return;
            }

            $result = $this->studentService->updateEnrollmentStatus($id, $status, $notes);

            if ($result['success']) {
                $this->logAudit('STUDENT_STATUS_UPDATED', 'student', $id, [
                    'description' => 'Updated student status to: ' . $status
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('StudentController::updateStatus error: ' . $e->getMessage());
            $this->response->error('Error updating status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete student
     * DELETE /api/platform/index.php?endpoint=student&action=delete&id={id}
     */
    public function deleteStudent(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $result = $this->studentService->deleteStudent($id);

            if ($result['success']) {
                $this->logAudit('STUDENT_DELETED', 'student', $id, [
                    'description' => 'Deleted student ID: ' . $id
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('StudentController::deleteStudent error: ' . $e->getMessage());
            $this->response->error('Error deleting student: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Link guardian to student
     * POST /api/platform/index.php?endpoint=student&action=link_guardian&id={id}
     */
    public function linkGuardian(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $student = $this->studentService->getStudent($id);
            if (!$student['success']) {
                $this->response->error('Student not found', 404);
                return;
            }

            $data = $this->getRequestData();
            $data['created_by'] = $this->authenticatedUserId;

            $result = $this->studentService->linkGuardian(
                $student['data']['person_id'],
                $data,
                $student['data']['tenant_id'],
                $this->authenticatedUserId
            );

            if ($result['success']) {
                $this->logAudit('GUARDIAN_LINKED', 'student', $id, [
                    'description' => 'Linked guardian to student ID: ' . $id,
                    'guardian_id' => $result['data']['guardian_person_id'] ?? null
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('StudentController::linkGuardian error: ' . $e->getMessage());
            $this->response->error('Error linking guardian: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Add emergency contact
     * POST /api/platform/index.php?endpoint=student&action=add_emergency_contact&id={id}
     */
    public function addEmergencyContact(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $student = $this->studentService->getStudent($id);
            if (!$student['success']) {
                $this->response->error('Student not found', 404);
                return;
            }

            $data = $this->getRequestData();
            $data['tenant_id'] = $student['data']['tenant_id'];
            $data['created_by'] = $this->authenticatedUserId;

            $result = $this->studentService->addEmergencyContact(
                $student['data']['person_id'],
                $data
            );

            if ($result['success']) {
                $this->logAudit('EMERGENCY_CONTACT_ADDED', 'student', $id, [
                    'description' => 'Added emergency contact for student ID: ' . $id
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('StudentController::addEmergencyContact error: ' . $e->getMessage());
            $this->response->error('Error adding emergency contact: ' . $e->getMessage(), 500);
        }
    } 
     
        // ============================================
    // LIST STUDENTS - PAGINATED WITH FILTERS
    // ============================================
    /**
     * List students with pagination and filters
     * GET /api/platform/index.php?endpoint=student&action=list
     */
    public function listStudents(): void
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
            $classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
            $status = isset($_GET['status']) ? trim($_GET['status']) : '';
            $gradeLevelId = isset($_GET['grade_level_id']) ? (int)$_GET['grade_level_id'] : 0;

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

            if ($classId > 0) {
                $where[] = "s.class_id = ?";
                $params[] = $classId;
            }

            if ($gradeLevelId > 0) {
                $where[] = "s.grade_level_id = ?";
                $params[] = $gradeLevelId;
            }

            if (!empty($status)) {
                $where[] = "s.enrollment_status = ?";
                $params[] = $status;
            }

            if (!empty($search)) {
                $where[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.admission_number LIKE ? OR s.student_number LIKE ? OR s.email LIKE ?)";
                $searchTerm = '%' . $search . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            $whereClause = implode(" AND ", $where);

            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM students s WHERE $whereClause";
            $countResult = $this->db->fetchOne($countSql, $params);
            $total = (int)($countResult['total'] ?? 0);

            // Get students list
            $sql = "SELECT 
                        s.id,
                        s.uuid,
                        s.admission_number,
                        s.student_number,
                        s.first_name,
                        s.middle_name,
                        s.last_name,
                        s.date_of_birth,
                        s.gender,
                        s.primary_phone,
                        s.secondary_phone,
                        s.email,
                        s.address,
                        s.nationality,
                        s.religion,
                        s.place_of_birth,
                        s.enrollment_status,
                        s.is_active,
                        s.created_at,
                        s.school_id,
                        s.campus_id,
                        s.class_id,
                        s.tenant_id,
                        s.parent_name,
                        s.parent_phone,
                        s.parent_email,
                        s.profile_photo,
                        sch.school_name,
                        cmp.campus_name,
                        cls.class_name,
                        cls.class_code,
                        gl.grade_name as grade_level,
                        CONCAT(s.first_name, ' ', s.last_name) as full_name
                    FROM students s
                    LEFT JOIN schools sch ON s.school_id = sch.id
                    LEFT JOIN campuses cmp ON s.campus_id = cmp.id
                    LEFT JOIN classes cls ON s.class_id = cls.id
                    LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
                    WHERE $whereClause
                    ORDER BY s.first_name ASC, s.last_name ASC
                    LIMIT ? OFFSET ?";

            $queryParams = array_merge($params, [$limit, $offset]);
            $students = $this->db->fetchAll($sql, $queryParams);

            $this->response->success([
                'students' => $students,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
        } catch (Exception $e) {
            error_log('StudentController::listStudents error: ' . $e->getMessage());
            $this->response->error('Error fetching students: ' . $e->getMessage(), 500);
        }
    }

    // ============================================
    // AUTOCOMPLETE STUDENTS
    // ============================================
    /**
     * Autocomplete students for search
     * GET /api/platform/index.php?endpoint=student&action=autocomplete
     */
    public function autocompleteStudents(): void
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
            $where[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.admission_number LIKE ? OR s.student_number LIKE ? OR s.email LIKE ?)";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;

            $whereClause = implode(" AND ", $where);

            $sql = "SELECT 
                        s.id,
                        s.admission_number,
                        s.student_number,
                        s.first_name,
                        s.last_name,
                        s.email,
                        s.primary_phone,
                        s.school_id,
                        s.campus_id,
                        CONCAT(s.first_name, ' ', s.last_name) as full_name,
                        sch.school_name,
                        cls.class_name
                    FROM students s
                    LEFT JOIN schools sch ON s.school_id = sch.id
                    LEFT JOIN classes cls ON s.class_id = cls.id
                    WHERE $whereClause
                    ORDER BY s.first_name ASC
                    LIMIT ?";

            $params[] = $limit;
            $results = $this->db->fetchAll($sql, $params);

            $this->response->success($results);
        } catch (Exception $e) {
            error_log('StudentController::autocompleteStudents error: ' . $e->getMessage());
            $this->response->error('Error searching students: ' . $e->getMessage(), 500);
        }
    }
}
