<?php

/**
 * StaffController.php
 * Controller for staff registration and management API endpoints
 * 
 * @package EduTrack
 * @subpackage Controllers\Identity
 * @filepath app/controllers/Identity/StaffController.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/services/Identity/StaffRegistrationService.php';
require_once $projectRoot . 'app/helpers/ResponseHelper.php';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class StaffController
{
    private $registrationService;
    private $response;
    private $db;

    public function __construct()
    {
        $this->registrationService = new StaffRegistrationService();
        $this->response = new ResponseHelper();
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Register a new staff member
     * POST /api/platform/index.php?endpoint=staff&action=register
     */
    public function register(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!$input) {
                $this->response->error('Invalid request body. Please provide valid JSON.', 400);
                return;
            }

            // ============================================
            // VALIDATION - FIXED
            // ============================================
            $errors = [];

            // Person validation
            if (empty($input['person'])) {
                $errors[] = 'person object is required';
            } else {
                if (empty($input['person']['first_name'])) {
                    $errors[] = 'first_name is required';
                }
                if (empty($input['person']['last_name'])) {
                    $errors[] = 'last_name is required';
                }
                if (empty($input['person']['primary_phone'])) {
                    $errors[] = 'primary_phone is required';
                }
                if (empty($input['person']['primary_email'])) {
                    $errors[] = 'primary_email is required';
                }
            }

            // Staff validation - Support both employment_date and hire_date
            if (empty($input['staff'])) {
                $errors[] = 'staff object is required';
            } else {
                if (empty($input['staff']['staff_type'])) {
                    $errors[] = 'staff_type is required';
                }
                if (empty($input['staff']['employment_status'])) {
                    $errors[] = 'employment_status is required';
                }
                // Support both employment_date and hire_date
                $hasDate = !empty($input['staff']['employment_date']) || !empty($input['staff']['hire_date']);
                if (!$hasDate) {
                    $errors[] = 'employment_date is required';
                }
            }

            // Tenant and School
            if (empty($input['tenant_id'])) {
                $errors[] = 'tenant_id is required';
            }
            if (empty($input['school_id'])) {
                $errors[] = 'school_id is required';
            }

            if (!empty($errors)) {
                $this->response->error('Missing required fields: ' . implode(', ', $errors), 400);
                return;
            }

            // Set created_by from authenticated user if available
            if (!isset($input['created_by']) && isset($_SESSION['user_id'])) {
                $input['created_by'] = $_SESSION['user_id'];
            }

            // Set tenant and school from headers if not provided
            if (!isset($input['tenant_id']) && isset($_SERVER['HTTP_X_TENANT_ID'])) {
                $input['tenant_id'] = (int)$_SERVER['HTTP_X_TENANT_ID'];
            }

            if (!isset($input['school_id']) && isset($_SERVER['HTTP_X_SCHOOL_ID'])) {
                $input['school_id'] = (int)$_SERVER['HTTP_X_SCHOOL_ID'];
            }

            $result = $this->registrationService->register($input);

            $this->response->success($result['data'], $result['message']);
        } catch (Exception $e) {
            error_log('Staff registration error: ' . $e->getMessage());
            error_log('Staff registration trace: ' . $e->getTraceAsString());
            $this->response->error($e->getMessage(), 400);
        }
    }

    /**
     * Get staff by ID
     * GET /api/platform/index.php?endpoint=staff&action=get&id={id}
     */
    public function getStaff(int $id): void
    {
        try {
            if ($id <= 0) {
                $this->response->error('Staff ID required', 400);
                return;
            }

            $staff = $this->registrationService->getStaff($id);
            $this->response->success($staff);
        } catch (Exception $e) {
            $this->response->error($e->getMessage(), 404);
        }
    }

    /**
     * Get staff by person ID
     * GET /api/platform/index.php?endpoint=staff&action=by_person&person_id={person_id}
     */
    public function getStaffByPerson(): void
    {
        try {
            $personId = isset($_GET['person_id']) ? (int)$_GET['person_id'] : 0;

            if ($personId <= 0) {
                $this->response->error('Person ID required', 400);
                return;
            }

            $staff = $this->registrationService->getStaffByPersonId($personId);

            if (!$staff) {
                $this->response->error('Staff not found for this person', 404);
                return;
            }

            $this->response->success($staff);
        } catch (Exception $e) {
            $this->response->error($e->getMessage(), 404);
        }
    }

    /**
     * Get staff by staff number
     * GET /api/platform/index.php?endpoint=staff&action=by_number&staff_number={staff_number}
     */
    public function getStaffByNumber(): void
    {
        try {
            $staffNumber = isset($_GET['staff_number']) ? trim($_GET['staff_number']) : '';

            if (empty($staffNumber)) {
                $this->response->error('Staff number required', 400);
                return;
            }

            $staff = $this->registrationService->getStaffByNumber($staffNumber);
            $this->response->success($staff);
        } catch (Exception $e) {
            $this->response->error($e->getMessage(), 404);
        }
    }

    /**
     * Search staff
     * GET /api/platform/index.php?endpoint=staff&action=search
     */
    public function search(): void
    {
        try {
            $query = isset($_GET['q']) ? trim($_GET['q']) : '';
            $status = isset($_GET['status']) ? trim($_GET['status']) : '';
            $category = isset($_GET['category']) ? (int)$_GET['category'] : 0;
            $department = isset($_GET['department']) ? (int)$_GET['department'] : 0;
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

            // Get tenant and school context
            $tenantId = isset($_SERVER['HTTP_X_TENANT_ID']) ? (int)$_SERVER['HTTP_X_TENANT_ID'] : (isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0);
            $schoolId = isset($_SERVER['HTTP_X_SCHOOL_ID']) ? (int)$_SERVER['HTTP_X_SCHOOL_ID'] : (isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0);

            $filters = [
                'search' => $query,
                'status' => $status,
                'category_id' => $category,
                'department_id' => $department,
                'tenant_id' => $tenantId,
                'school_id' => $schoolId
            ];

            $results = $this->searchStaff($filters, $page, $limit);
            $this->response->success($results);
        } catch (Exception $e) {
            $this->response->error($e->getMessage(), 400);
        }
    }

    /**
     * Get staff statistics
     * GET /api/platform/index.php?endpoint=staff&action=stats
     */
    public function getStats(): void
    {
        try {
            $tenantId = isset($_SERVER['HTTP_X_TENANT_ID']) ? (int)$_SERVER['HTTP_X_TENANT_ID'] : (isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0);
            $schoolId = isset($_SERVER['HTTP_X_SCHOOL_ID']) ? (int)$_SERVER['HTTP_X_SCHOOL_ID'] : (isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0);

            $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN sp.employment_status = 'active' THEN 1 ELSE 0 END) as active,
                        SUM(CASE WHEN sp.employment_status = 'on_leave' THEN 1 ELSE 0 END) as on_leave,
                        SUM(CASE WHEN sp.employment_status = 'suspended' THEN 1 ELSE 0 END) as suspended,
                        SUM(CASE WHEN sp.employment_status = 'terminated' THEN 1 ELSE 0 END) as terminated,
                        SUM(CASE WHEN sp.employment_status = 'resigned' THEN 1 ELSE 0 END) as resigned,
                        SUM(CASE WHEN sp.employment_status = 'retired' THEN 1 ELSE 0 END) as retired,
                        SUM(CASE WHEN sp.staff_type = 'teaching' THEN 1 ELSE 0 END) as teaching,
                        SUM(CASE WHEN sp.staff_type = 'non_teaching' THEN 1 ELSE 0 END) as non_teaching,
                        SUM(CASE WHEN sp.staff_type = 'administrative' THEN 1 ELSE 0 END) as administrative
                    FROM staff_profiles sp
                    INNER JOIN people p ON sp.person_id = p.id
                    WHERE sp.deleted_at IS NULL 
                    AND p.deleted_at IS NULL";

            $params = [];

            if ($tenantId > 0) {
                $sql .= " AND p.tenant_id = ?";
                $params[] = $tenantId;
            }

            if ($schoolId > 0) {
                $sql .= " AND p.school_id = ?";
                $params[] = $schoolId;
            }

            $stats = $this->db->fetchOne($sql, $params);

            // Get category breakdown
            $categorySql = "SELECT 
                                sc.category_name,
                                COUNT(*) as count
                            FROM staff_profiles sp
                            INNER JOIN people p ON sp.person_id = p.id
                            LEFT JOIN staff_categories sc ON sp.staff_category_id = sc.id
                            WHERE sp.deleted_at IS NULL 
                            AND p.deleted_at IS NULL";

            $categoryParams = [];

            if ($tenantId > 0) {
                $categorySql .= " AND p.tenant_id = ?";
                $categoryParams[] = $tenantId;
            }

            if ($schoolId > 0) {
                $categorySql .= " AND p.school_id = ?";
                $categoryParams[] = $schoolId;
            }

            $categorySql .= " GROUP BY sc.category_name";
            $categories = $this->db->fetchAll($categorySql, $categoryParams);

            $this->response->success([
                'overview' => $stats,
                'categories' => $categories
            ]);
        } catch (Exception $e) {
            $this->response->error($e->getMessage(), 400);
        }
    }

    /**
     * Update staff
     * PUT /api/platform/index.php?endpoint=staff&action=update&id={id}
     */
    public function updateStaff(int $id): void
    {
        try {
            if ($id <= 0) {
                $this->response->error('Staff ID required', 400);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            if (!$input) {
                $this->response->error('Invalid request body. Please provide valid JSON.', 400);
                return;
            }

            $staff = $this->registrationService->updateStaff($id, $input);
            $this->response->success($staff, 'Staff updated successfully');
        } catch (Exception $e) {
            $this->response->error($e->getMessage(), 400);
        }
    }

    /**
     * Update staff status
     * PUT /api/platform/index.php?endpoint=staff&action=update_status&id={id}
     */
    public function updateStatus(int $id): void
    {
        try {
            if ($id <= 0) {
                $this->response->error('Staff ID required', 400);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            if (!$input || empty($input['employment_status'])) {
                $this->response->error('employment_status is required', 400);
                return;
            }

            $staff = $this->registrationService->updateStaff($id, [
                'employment_status' => $input['employment_status']
            ]);

            $this->response->success($staff, 'Staff status updated successfully');
        } catch (Exception $e) {
            $this->response->error($e->getMessage(), 400);
        }
    }

    /**
     * Delete staff
     * DELETE /api/platform/index.php?endpoint=staff&action=delete&id={id}
     */
    public function deleteStaff(int $id): void
    {
        try {
            if ($id <= 0) {
                $this->response->error('Staff ID required', 400);
                return;
            }

            $deletedBy = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            $result = $this->registrationService->deleteStaff($id, $deletedBy);

            if ($result) {
                $this->response->success(null, 'Staff deleted successfully');
            } else {
                $this->response->error('Failed to delete staff', 400);
            }
        } catch (Exception $e) {
            $this->response->error($e->getMessage(), 400);
        }
    }

    /**
     * Search staff - helper method
     */
    private function searchStaff(array $filters, int $page, int $limit): array
    {
        $sql = "SELECT 
                    sp.*, 
                    p.id as person_id,
                    p.first_name, p.last_name, p.middle_name,
                    p.person_number,
                    p.primary_email,
                    p.primary_phone,
                    CONCAT(p.first_name, ' ', p.last_name) as full_name,
                    sc.category_name as staff_category_name,
                    d.department_name,
                    po.position_name
                FROM staff_profiles sp
                INNER JOIN people p ON sp.person_id = p.id
                LEFT JOIN staff_categories sc ON sp.staff_category_id = sc.id
                LEFT JOIN departments d ON sp.department_id = d.id
                LEFT JOIN staff_positions po ON sp.position_id = po.id
                WHERE sp.deleted_at IS NULL 
                AND p.deleted_at IS NULL";

        $params = [];

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $sql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR 
                        p.person_number LIKE ? OR sp.staff_number LIKE ? OR
                        p.primary_email LIKE ? OR p.primary_phone LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        if (!empty($filters['status'])) {
            $sql .= " AND sp.employment_status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['category_id'])) {
            $sql .= " AND sp.staff_category_id = ?";
            $params[] = $filters['category_id'];
        }

        if (!empty($filters['department_id'])) {
            $sql .= " AND sp.department_id = ?";
            $params[] = $filters['department_id'];
        }

        if (!empty($filters['tenant_id'])) {
            $sql .= " AND p.tenant_id = ?";
            $params[] = $filters['tenant_id'];
        }

        if (!empty($filters['school_id'])) {
            $sql .= " AND p.school_id = ?";
            $params[] = $filters['school_id'];
        }

        // Count total
        $countSql = str_replace(
            "SELECT 
                    sp.*, 
                    p.id as person_id,
                    p.first_name, p.last_name, p.middle_name,
                    p.person_number,
                    p.primary_email,
                    p.primary_phone,
                    CONCAT(p.first_name, ' ', p.last_name) as full_name,
                    sc.category_name as staff_category_name,
                    d.department_name,
                    po.position_name",
            "SELECT COUNT(*) as total",
            $sql
        );

        $countResult = $this->db->fetchOne($countSql, $params);
        $total = (int)($countResult['total'] ?? 0);

        // Pagination
        $offset = ($page - 1) * $limit;
        $sql .= " ORDER BY sp.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $staff = $this->db->fetchAll($sql, $params);

        return [
            'data' => $staff,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($total / $limit)
        ];
    }
}
