<?php

/**
 * IdentityController.php
 * Controller for Identity/People management
 * 
 * @package EduTrack
 * @subpackage Controllers\Identity
 * @filepath app/controllers/Identity/IdentityController.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/ResponseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/services/Tenant/TenantContext.php';
require_once $projectRoot . 'app/services/Identity/PersonService.php';

class IdentityController
{
    private $response;
    private $context;
    private $personService;
    private $db;
    private $logger;

    public function __construct()
    {
        $this->response = new ResponseHelper();
        $this->context = TenantContext::getInstance();
        $this->personService = new PersonService();
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
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
            $userId = $this->getUserId();
            $sql = "INSERT INTO audit_logs (tenant_id, user_id, action_type, module, resource, resource_id, description, details, ip_address, user_agent, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $this->db->execute($sql, [
                $tenantId,
                $userId,
                $action,
                'identity',
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
     * List people with pagination and filters
     * GET /api/platform/index.php?endpoint=identity&action=list
     */
    public function listPeople(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $status = isset($_GET['status']) ? trim($_GET['status']) : '';
            $tenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
            $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
            $campusId = isset($_GET['campus_id']) ? (int)$_GET['campus_id'] : 0;
            $role = isset($_GET['role']) ? trim($_GET['role']) : '';
            $type = isset($_GET['type']) ? trim($_GET['type']) : '';

            $filters = [
                'search' => $search,
                'status' => $status,
                'role' => $role,
                'type' => $type
            ];

            // Enforce tenant isolation
            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                $filters['tenant_id'] = $currentTenantId;
            } elseif ($tenantId > 0 && $this->isPlatformAdmin()) {
                $filters['tenant_id'] = $tenantId;
            }

            if ($schoolId > 0) {
                $filters['school_id'] = $schoolId;
            }
            if ($campusId > 0) {
                $filters['campus_id'] = $campusId;
            }

            $result = $this->personService->search($filters, $page, $limit);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 500);
            }
        } catch (Exception $e) {
            error_log('listPeople error: ' . $e->getMessage());
            $this->response->error('Error listing people: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get a single person by ID
     * GET /api/platform/index.php?endpoint=identity&action=get&id={id}
     */
    public function getPerson(int $id): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $result = $this->personService->getById($id);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 404);
            }
        } catch (Exception $e) {
            error_log('getPerson error: ' . $e->getMessage());
            $this->response->error('Error fetching person: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create a new person
     * POST /api/platform/index.php?endpoint=identity&action=create
     */
    public function createPerson(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();

            // Enforce tenant isolation for creation
            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                if (empty($data['tenant_id'])) {
                    $data['tenant_id'] = $currentTenantId;
                } elseif ($data['tenant_id'] != $currentTenantId) {
                    $this->response->error('You do not have permission to create people for this tenant', 403);
                    return;
                }
            }

            $result = $this->personService->create($data);

            if ($result['success']) {
                $this->logAudit('CREATE', 'person', $result['data']['id'] ?? null, [
                    'description' => 'Created person: ' . ($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''),
                    'data' => $data
                ]);
                $this->response->success([
                    'message' => $result['message'],
                    'data' => $result['data']
                ]);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('createPerson error: ' . $e->getMessage());
            $this->response->error('Error creating person: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update a person
     * PUT /api/platform/index.php?endpoint=identity&action=update&id={id}
     */
    public function updatePerson(int $id): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();
            $result = $this->personService->update($id, $data);

            if ($result['success']) {
                $this->logAudit('UPDATE', 'person', $id, [
                    'description' => 'Updated person ID: ' . $id,
                    'updated_fields' => array_keys($data)
                ]);
                $this->response->success([
                    'message' => $result['message'],
                    'data' => $result['data']
                ]);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('updatePerson error: ' . $e->getMessage());
            $this->response->error('Error updating person: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a person (soft delete)
     * DELETE /api/platform/index.php?endpoint=identity&action=delete&id={id}
     */
    public function deletePerson(int $id): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $result = $this->personService->delete($id);

            if ($result['success']) {
                $this->logAudit('DELETE', 'person', $id, [
                    'description' => 'Deleted person ID: ' . $id
                ]);
                $this->response->success(['message' => $result['message']]);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('deletePerson error: ' . $e->getMessage());
            $this->response->error('Error deleting person: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Restore a soft-deleted person
     * POST /api/platform/index.php?endpoint=identity&action=restore&id={id}
     */
    public function restorePerson(int $id): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $result = $this->personService->restore($id);

            if ($result['success']) {
                $this->logAudit('RESTORE', 'person', $id, [
                    'description' => 'Restored person ID: ' . $id
                ]);
                $this->response->success([
                    'message' => $result['message'],
                    'data' => $result['data']
                ]);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('restorePerson error: ' . $e->getMessage());
            $this->response->error('Error restoring person: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get people stats
     * GET /api/platform/index.php?endpoint=identity&action=stats
     */
    public function getStats(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $db = DatabaseHelper::getInstance();
            $tenantId = $this->getTenantId();

            $params = [];
            $where = ["p.deleted_at IS NULL"];

            if ($tenantId && !$this->isPlatformAdmin()) {
                $where[] = "EXISTS (SELECT 1 FROM person_organizational_assignments poa WHERE poa.person_id = p.id AND poa.tenant_id = ? AND poa.deleted_at IS NULL)";
                $params[] = $tenantId;
            }

            $whereClause = "WHERE " . implode(" AND ", $where);

            $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN p.status = 'active' THEN 1 ELSE 0 END) as active,
                        SUM(CASE WHEN p.status = 'inactive' THEN 1 ELSE 0 END) as inactive,
                        SUM(CASE WHEN p.status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN p.status = 'suspended' THEN 1 ELSE 0 END) as suspended,
                        SUM(CASE WHEN p.status = 'archived' THEN 1 ELSE 0 END) as archived,
                        COUNT(DISTINCT p.person_type) as person_types,
                        SUM(CASE WHEN p.gender = 'male' THEN 1 ELSE 0 END) as male,
                        SUM(CASE WHEN p.gender = 'female' THEN 1 ELSE 0 END) as female
                    FROM people p
                    $whereClause";

            $result = $db->fetchOne($sql, $params);

            $this->response->success([
                'total' => (int)($result['total'] ?? 0),
                'active' => (int)($result['active'] ?? 0),
                'inactive' => (int)($result['inactive'] ?? 0),
                'pending' => (int)($result['pending'] ?? 0),
                'suspended' => (int)($result['suspended'] ?? 0),
                'archived' => (int)($result['archived'] ?? 0),
                'person_types' => (int)($result['person_types'] ?? 0),
                'male' => (int)($result['male'] ?? 0),
                'female' => (int)($result['female'] ?? 0)
            ]);
        } catch (Exception $e) {
            error_log('getStats error: ' . $e->getMessage());
            $this->response->error('Error fetching stats: ' . $e->getMessage(), 500);
        }
    }

    // ================================================
    // NEW METHODS FOR ROUTER
    // ================================================

    /**
     * Search people (autocomplete)
     * GET /api/platform/index.php?endpoint=identity&action=search
     */
    public function searchPeople(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $tenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
            $type = isset($_GET['type']) ? trim($_GET['type']) : '';

            if (strlen($search) < 2 && empty($type)) {
                $this->response->success([]);
                return;
            }

            $filters = [
                'search' => $search,
                'type' => $type
            ];

            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                $filters['tenant_id'] = $currentTenantId;
            } elseif ($tenantId > 0 && $this->isPlatformAdmin()) {
                $filters['tenant_id'] = $tenantId;
            }

            $result = $this->personService->search($filters, 1, $limit);

            if ($result['success']) {
                $this->response->success($result['data']['people'] ?? []);
            } else {
                $this->response->error($result['message'], 500);
            }
        } catch (Exception $e) {
            error_log('searchPeople error: ' . $e->getMessage());
            $this->response->error('Error searching people: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get people by tenant
     * GET /api/platform/index.php?endpoint=identity&action=by_tenant&tenant_id={tenant_id}
     */
    public function getPeopleByTenant(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $tenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
            $currentTenantId = $this->getTenantId();

            if ($tenantId > 0) {
                if (!$this->isPlatformAdmin() && $currentTenantId && $tenantId != $currentTenantId) {
                    $this->response->error('Access denied', 403);
                    return;
                }
            } elseif ($currentTenantId && !$this->isPlatformAdmin()) {
                $tenantId = $currentTenantId;
            }

            if (!$tenantId) {
                $this->response->error('Tenant ID is required', 400);
                return;
            }

            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            $status = isset($_GET['status']) ? trim($_GET['status']) : '';

            $filters = [
                'tenant_id' => $tenantId,
                'status' => $status
            ];

            $result = $this->personService->search($filters, 1, $limit);

            if ($result['success']) {
                $this->response->success($result['data']['people'] ?? []);
            } else {
                $this->response->error($result['message'], 500);
            }
        } catch (Exception $e) {
            error_log('getPeopleByTenant error: ' . $e->getMessage());
            $this->response->error('Error fetching people: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get person types
     * GET /api/platform/index.php?endpoint=identity&action=types
     */
    public function getPersonTypes(): void
    {
        try {
            $types = [
                ['code' => 'student', 'name' => 'Student', 'category' => 'academic'],
                ['code' => 'teacher', 'name' => 'Teacher', 'category' => 'academic'],
                ['code' => 'staff', 'name' => 'Staff', 'category' => 'employee'],
                ['code' => 'admin', 'name' => 'Administrator', 'category' => 'employee'],
                ['code' => 'parent', 'name' => 'Parent', 'category' => 'family'],
                ['code' => 'guardian', 'name' => 'Guardian', 'category' => 'family'],
                ['code' => 'alumni', 'name' => 'Alumni', 'category' => 'academic'],
                ['code' => 'visitor', 'name' => 'Visitor', 'category' => 'guest'],
                ['code' => 'contractor', 'name' => 'Contractor', 'category' => 'employee'],
                ['code' => 'volunteer', 'name' => 'Volunteer', 'category' => 'guest']
            ];

            $this->response->success($types);
        } catch (Exception $e) {
            error_log('getPersonTypes error: ' . $e->getMessage());
            $this->response->error('Error fetching person types: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Bulk create people
     * POST /api/platform/index.php?endpoint=identity&action=bulk_create
     */
    public function bulkCreatePeople(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();
            $people = $data['people'] ?? [];
            $tenantId = $data['tenant_id'] ?? null;

            if (empty($people) || !is_array($people)) {
                $this->response->error('People data is required', 400);
                return;
            }

            // Enforce tenant isolation
            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                if (empty($tenantId)) {
                    $tenantId = $currentTenantId;
                } elseif ($tenantId != $currentTenantId) {
                    $this->response->error('You do not have permission to create people for this tenant', 403);
                    return;
                }
            }

            $results = [];
            $successCount = 0;
            $failCount = 0;

            foreach ($people as $personData) {
                if (empty($tenantId) && !empty($personData['tenant_id'])) {
                    $personTenantId = $personData['tenant_id'];
                } else {
                    $personTenantId = $tenantId;
                }

                $personData['tenant_id'] = $personTenantId;

                $result = $this->personService->create($personData);
                if ($result['success']) {
                    $successCount++;
                    $results[] = $result['data'];
                } else {
                    $failCount++;
                    $results[] = ['error' => $result['message'], 'data' => $personData];
                }
            }

            $this->logAudit('BULK_CREATE', 'people', null, [
                'description' => 'Bulk created ' . $successCount . ' people',
                'success_count' => $successCount,
                'fail_count' => $failCount
            ]);

            $this->response->success([
                'message' => "Created $successCount people successfully" . ($failCount > 0 ? ", $failCount failed" : ""),
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'results' => $results
            ]);
        } catch (Exception $e) {
            error_log('bulkCreatePeople error: ' . $e->getMessage());
            $this->response->error('Error creating people: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update person status
     * PUT /api/platform/index.php?endpoint=identity&action=update_status&id={id}
     */
    public function updatePersonStatus(int $id): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();
            $status = $data['status'] ?? '';

            if (empty($status)) {
                $this->response->error('Status is required', 400);
                return;
            }

            $validStatuses = ['active', 'inactive', 'pending', 'suspended', 'archived'];
            if (!in_array($status, $validStatuses)) {
                $this->response->error('Invalid status. Valid values: ' . implode(', ', $validStatuses), 400);
                return;
            }

            $result = $this->personService->update($id, ['status' => $status]);

            if ($result['success']) {
                $this->logAudit('UPDATE_STATUS', 'person', $id, [
                    'description' => 'Updated person status to: ' . $status
                ]);
                $this->response->success([
                    'message' => $result['message'],
                    'data' => $result['data']
                ]);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('updatePersonStatus error: ' . $e->getMessage());
            $this->response->error('Error updating person status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get people with specific role
     * GET /api/platform/index.php?endpoint=identity&action=by_role&role={role}
     */
    public function getPeopleByRole(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $role = isset($_GET['role']) ? trim($_GET['role']) : '';
            $tenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

            if (empty($role)) {
                $this->response->error('Role is required', 400);
                return;
            }

            $filters = [
                'role' => $role
            ];

            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                $filters['tenant_id'] = $currentTenantId;
            } elseif ($tenantId > 0 && $this->isPlatformAdmin()) {
                $filters['tenant_id'] = $tenantId;
            }

            $result = $this->personService->search($filters, 1, $limit);

            if ($result['success']) {
                $this->response->success($result['data']['people'] ?? []);
            } else {
                $this->response->error($result['message'], 500);
            }
        } catch (Exception $e) {
            error_log('getPeopleByRole error: ' . $e->getMessage());
            $this->response->error('Error fetching people by role: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get people by school
     * GET /api/platform/index.php?endpoint=identity&action=by_school&school_id={school_id}
     */
    public function getPeopleBySchool(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            $role = isset($_GET['role']) ? trim($_GET['role']) : '';

            if (!$schoolId) {
                $this->response->error('School ID is required', 400);
                return;
            }

            $filters = [
                'school_id' => $schoolId,
                'role' => $role
            ];

            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                $filters['tenant_id'] = $currentTenantId;
            }

            $result = $this->personService->search($filters, 1, $limit);

            if ($result['success']) {
                $this->response->success($result['data']['people'] ?? []);
            } else {
                $this->response->error($result['message'], 500);
            }
        } catch (Exception $e) {
            error_log('getPeopleBySchool error: ' . $e->getMessage());
            $this->response->error('Error fetching people by school: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get people by campus
     * GET /api/platform/index.php?endpoint=identity&action=by_campus&campus_id={campus_id}
     */
    public function getPeopleByCampus(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $campusId = isset($_GET['campus_id']) ? (int)$_GET['campus_id'] : 0;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            $role = isset($_GET['role']) ? trim($_GET['role']) : '';

            if (!$campusId) {
                $this->response->error('Campus ID is required', 400);
                return;
            }

            $filters = [
                'campus_id' => $campusId,
                'role' => $role
            ];

            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                $filters['tenant_id'] = $currentTenantId;
            }

            $result = $this->personService->search($filters, 1, $limit);

            if ($result['success']) {
                $this->response->success($result['data']['people'] ?? []);
            } else {
                $this->response->error($result['message'], 500);
            }
        } catch (Exception $e) {
            error_log('getPeopleByCampus error: ' . $e->getMessage());
            $this->response->error('Error fetching people by campus: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get contact information for a person
     * GET /api/platform/index.php?endpoint=identity&action=contacts&id={id}
     */
    public function getPersonContacts(int $id): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $result = $this->personService->getById($id);

            if (!$result['success']) {
                $this->response->error($result['message'], 404);
                return;
            }

            $person = $result['data'];

            // Map to the correct column names from the people table
            $contacts = [
                'phone' => $person['primary_phone'] ?? null,
                'email' => $person['primary_email'] ?? null,
                'mobile' => $person['primary_phone'] ?? null,
                'work_phone' => $person['secondary_phone'] ?? null,
                'home_phone' => null,
                'emergency_contact' => $person['emergency_contact_name'] ?? null,
                'emergency_phone' => $person['emergency_contact_phone'] ?? null,
                'secondary_phone' => $person['secondary_phone'] ?? null,
                'secondary_email' => $person['secondary_email'] ?? null
            ];

            // Remove null values
            $contacts = array_filter($contacts, function ($value) {
                return $value !== null && $value !== '';
            });

            $this->response->success($contacts);
        } catch (Exception $e) {
            error_log('getPersonContacts error: ' . $e->getMessage());
            $this->response->error('Error fetching contacts: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get address information for a person
     * GET /api/platform/index.php?endpoint=identity&action=address&id={id}
     */
    public function getPersonAddress(int $id): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $result = $this->personService->getById($id);

            if (!$result['success']) {
                $this->response->error($result['message'], 404);
                return;
            }

            $person = $result['data'];

            $address = [
                'address_line_1' => null,
                'address_line_2' => null,
                'city' => null,
                'state' => null,
                'country' => $person['nationality'] ?? null,
                'postal_code' => null,
                'residential_address' => null,
                'digital_address' => $person['digital_address'] ?? null,
                'place_of_birth' => $person['place_of_birth'] ?? null
            ];

            // Remove null values
            $address = array_filter($address, function ($value) {
                return $value !== null && $value !== '';
            });

            $this->response->success($address);
        } catch (Exception $e) {
            error_log('getPersonAddress error: ' . $e->getMessage());
            $this->response->error('Error fetching address: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get roles for a person
     * GET /api/platform/index.php?endpoint=identity&action=roles&id={id}
     */
    public function getPersonRoles(int $id): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $db = DatabaseHelper::getInstance();
            $tenantId = $this->getTenantId();

            // First, check what columns exist in platform_roles
            $roleColumns = [];
            try {
                $colResult = $db->fetchAll("SHOW COLUMNS FROM platform_roles");
                foreach ($colResult as $col) {
                    $roleColumns[] = $col['Field'];
                }
            } catch (Exception $e) {
                error_log('Error getting platform_roles columns: ' . $e->getMessage());
            }

            // Check what columns exist in person_organizational_assignments
            $poaColumns = [];
            try {
                $colResult = $db->fetchAll("SHOW COLUMNS FROM person_organizational_assignments");
                foreach ($colResult as $col) {
                    $poaColumns[] = $col['Field'];
                }
            } catch (Exception $e) {
                error_log('Error getting poa columns: ' . $e->getMessage());
            }

            // Build the query dynamically based on available columns
            $selectFields = "pr.id";

            if (in_array('role_code', $roleColumns)) {
                $selectFields .= ", pr.role_code";
            } else {
                $selectFields .= ", pr.id as role_code";
            }

            if (in_array('role_name', $roleColumns)) {
                $selectFields .= ", pr.role_name";
            } else {
                $selectFields .= ", pr.id as role_name";
            }

            if (in_array('description', $roleColumns)) {
                $selectFields .= ", pr.description";
            } else {
                $selectFields .= ", '' as description";
            }

            if (in_array('level', $roleColumns)) {
                $selectFields .= ", pr.level";
            } else {
                $selectFields .= ", 0 as level";
            }

            $poaSelect = "poa.tenant_id, poa.school_id, poa.campus_id, poa.start_date, poa.end_date";

            if (in_array('is_primary', $poaColumns)) {
                $poaSelect .= ", poa.is_primary";
            } else {
                $poaSelect .= ", 0 as is_primary";
            }

            if (in_array('assigned_by', $poaColumns)) {
                $poaSelect .= ", poa.assigned_by";
            } else {
                $poaSelect .= ", NULL as assigned_by";
            }

            if (in_array('created_at', $poaColumns)) {
                $poaSelect .= ", poa.created_at as assigned_at";
            } else {
                $poaSelect .= ", NULL as assigned_at";
            }

            $params = [$id];
            $where = ["p.id = ?"];

            // Only add deleted_at condition if the column exists
            if (in_array('deleted_at', $roleColumns)) {
                $where[] = "pr.deleted_at IS NULL";
            }

            if (in_array('deleted_at', $poaColumns)) {
                $where[] = "poa.deleted_at IS NULL";
            }

            if ($tenantId && !$this->isPlatformAdmin()) {
                $where[] = "poa.tenant_id = ?";
                $params[] = $tenantId;
            }

            $whereClause = "WHERE " . implode(" AND ", $where);

            $sql = "SELECT 
                        $selectFields,
                        $poaSelect
                    FROM people p
                    JOIN person_organizational_assignments poa ON poa.person_id = p.id
                    JOIN platform_roles pr ON pr.id = poa.role_id
                    $whereClause
                    ORDER BY poa.is_primary DESC, poa.created_at DESC";

            error_log("getPersonRoles SQL: " . $sql);
            error_log("getPersonRoles params: " . json_encode($params));

            $roles = $db->fetchAll($sql, $params);

            $this->response->success($roles);
        } catch (Exception $e) {
            error_log('getPersonRoles error: ' . $e->getMessage());
            error_log('getPersonRoles trace: ' . $e->getTraceAsString());
            $this->response->error('Error fetching roles: ' . $e->getMessage(), 500);
        }
    }

    // ================================================
    // IDENTITY DOCUMENTS METHODS
    // ================================================

    /**
     * List documents with pagination and filters
     * GET /api/platform/index.php?endpoint=identity&action=documents
     */
    public function listDocuments(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $type = isset($_GET['type']) ? trim($_GET['type']) : '';
            $status = isset($_GET['status']) ? trim($_GET['status']) : '';
            $verificationStatus = isset($_GET['verification_status']) ? trim($_GET['verification_status']) : '';
            $tenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
            $personId = isset($_GET['person_id']) ? (int)$_GET['person_id'] : 0;

            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                $tenantId = $currentTenantId;
            }

            $filters = [
                'search' => $search,
                'type' => $type,
                'status' => $status,
                'verification_status' => $verificationStatus,
                'tenant_id' => $tenantId,
                'person_id' => $personId
            ];

            require_once $GLOBALS['projectRoot'] . 'app/services/Identity/IdentityDocumentService.php';
            $service = new IdentityDocumentService();
            $result = $service->search($filters, $page, $limit);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 500);
            }
        } catch (Exception $e) {
            error_log('listDocuments error: ' . $e->getMessage());
            $this->response->error('Error listing documents: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get a single document by ID
     * GET /api/platform/index.php?endpoint=identity&action=get_document&id={id}
     */
    public function getDocument(int $id): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            require_once $GLOBALS['projectRoot'] . 'app/services/Identity/IdentityDocumentService.php';
            $service = new IdentityDocumentService();
            $result = $service->getById($id);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 404);
            }
        } catch (Exception $e) {
            error_log('getDocument error: ' . $e->getMessage());
            $this->response->error('Error fetching document: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create a new identity document
     * POST /api/platform/index.php?endpoint=identity&action=create_document
     */
    public function createDocument(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();

            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                if (empty($data['tenant_id'])) {
                    $data['tenant_id'] = $currentTenantId;
                } elseif ($data['tenant_id'] != $currentTenantId) {
                    $this->response->error('You do not have permission to create documents for this tenant', 403);
                    return;
                }
            }

            $data['created_by'] = $this->getUserId();

            require_once $GLOBALS['projectRoot'] . 'app/services/Identity/IdentityDocumentService.php';
            $service = new IdentityDocumentService();
            $result = $service->create($data);

            if ($result['success']) {
                $this->logAudit('CREATE_DOCUMENT', 'identity_document', $result['data']['id'] ?? null, [
                    'description' => 'Created document: ' . ($data['document_type'] ?? '') . ' - ' . ($data['document_number'] ?? '')
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('createDocument error: ' . $e->getMessage());
            $this->response->error('Error creating document: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update a document
     * PUT /api/platform/index.php?endpoint=identity&action=update_document&id={id}
     */
    public function updateDocument(int $id): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();

            require_once $GLOBALS['projectRoot'] . 'app/services/Identity/IdentityDocumentService.php';
            $service = new IdentityDocumentService();
            $result = $service->update($id, $data);

            if ($result['success']) {
                $this->logAudit('UPDATE_DOCUMENT', 'identity_document', $id, [
                    'description' => 'Updated document ID: ' . $id,
                    'updated_fields' => array_keys($data)
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('updateDocument error: ' . $e->getMessage());
            $this->response->error('Error updating document: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a document (soft delete)
     * DELETE /api/platform/index.php?endpoint=identity&action=delete_document&id={id}
     */
    public function deleteDocument(int $id): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            require_once $GLOBALS['projectRoot'] . 'app/services/Identity/IdentityDocumentService.php';
            $service = new IdentityDocumentService();
            $result = $service->delete($id);

            if ($result['success']) {
                $this->logAudit('DELETE_DOCUMENT', 'identity_document', $id, [
                    'description' => 'Deleted document ID: ' . $id
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('deleteDocument error: ' . $e->getMessage());
            $this->response->error('Error deleting document: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Verify a document
     * POST /api/platform/index.php?endpoint=identity&action=verify_document&id={id}
     */
    public function verifyDocument(int $id): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $verifiedBy = $this->getUserId();

            require_once $GLOBALS['projectRoot'] . 'app/services/Identity/IdentityDocumentService.php';
            $service = new IdentityDocumentService();
            $result = $service->verify($id, $verifiedBy);

            if ($result['success']) {
                $this->logAudit('VERIFY_DOCUMENT', 'identity_document', $id, [
                    'description' => 'Verified document ID: ' . $id
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('verifyDocument error: ' . $e->getMessage());
            $this->response->error('Error verifying document: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Reject a document
     * POST /api/platform/index.php?endpoint=identity&action=reject_document&id={id}
     */
    public function rejectDocument(int $id): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $data = $this->getRequestData();
            $rejectedBy = $this->getUserId();
            $reason = $data['reason'] ?? 'Document rejected';

            require_once $GLOBALS['projectRoot'] . 'app/services/Identity/IdentityDocumentService.php';
            $service = new IdentityDocumentService();
            $result = $service->reject($id, $rejectedBy);

            if ($result['success']) {
                $this->logAudit('REJECT_DOCUMENT', 'identity_document', $id, [
                    'description' => 'Rejected document ID: ' . $id,
                    'reason' => $reason
                ]);
                $this->response->success($result);
            } else {
                $this->response->error($result['message'], 400);
            }
        } catch (Exception $e) {
            error_log('rejectDocument error: ' . $e->getMessage());
            $this->response->error('Error rejecting document: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get document statistics
     * GET /api/platform/index.php?endpoint=identity&action=doc_stats
     */
    public function getDocumentStats(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $tenantId = $this->getTenantId();

            if (!$this->isPlatformAdmin()) {
                $tenantId = $tenantId ?: null;
            } else {
                $tenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : null;
            }

            require_once $GLOBALS['projectRoot'] . 'app/services/Identity/IdentityDocumentService.php';
            $service = new IdentityDocumentService();
            $result = $service->getStats($tenantId);

            if ($result['success']) {
                $this->response->success($result['data']);
            } else {
                $this->response->error($result['message'], 500);
            }
        } catch (Exception $e) {
            error_log('getDocumentStats error: ' . $e->getMessage());
            $this->response->error('Error fetching document stats: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get document types
     * GET /api/platform/index.php?endpoint=identity&action=doc_types
     */
    public function getDocumentTypes(): void
    {
        try {
            $types = [
                ['code' => 'national_id', 'name' => 'National ID', 'category' => 'government'],
                ['code' => 'passport', 'name' => 'Passport', 'category' => 'government'],
                ['code' => 'drivers_license', 'name' => "Driver's License", 'category' => 'government'],
                ['code' => 'voters_card', 'name' => "Voter's Card", 'category' => 'government'],
                ['code' => 'ssnit', 'name' => 'SSNIT', 'category' => 'government'],
                ['code' => 'tin', 'name' => 'Tax Identification Number', 'category' => 'government'],
                ['code' => 'birth_certificate', 'name' => 'Birth Certificate', 'category' => 'government'],
                ['code' => 'marriage_certificate', 'name' => 'Marriage Certificate', 'category' => 'government'],
                ['code' => 'utility_bill', 'name' => 'Utility Bill', 'category' => 'address_proof'],
                ['code' => 'bank_statement', 'name' => 'Bank Statement', 'category' => 'address_proof'],
                ['code' => 'employment_letter', 'name' => 'Employment Letter', 'category' => 'employment'],
                ['code' => 'student_id', 'name' => 'Student ID', 'category' => 'education'],
                ['code' => 'professional_certificate', 'name' => 'Professional Certificate', 'category' => 'education'],
                ['code' => 'business_registration', 'name' => 'Business Registration', 'category' => 'business'],
                ['code' => 'tax_clearance', 'name' => 'Tax Clearance Certificate', 'category' => 'business']
            ];

            $this->response->success($types);
        } catch (Exception $e) {
            error_log('getDocumentTypes error: ' . $e->getMessage());
            $this->response->error('Error fetching document types: ' . $e->getMessage(), 500);
        }
    }
}
