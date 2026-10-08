<?php

/**
 * SubscriptionController.php
 * Controller for managing subscription plans and tenant subscriptions
 * 
 * @package EduTrack
 * @subpackage Controllers\Platform
 * @version 2.0
 * @filepath app/controllers/Platform/SubscriptionController.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/helpers/ResponseHelper.php';
require_once $projectRoot . 'app/services/Tenant/TenantContext.php';

class SubscriptionController
{
    private $db;
    private $logger;
    private $response;
    private $context;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->response = new ResponseHelper();
        $this->context = TenantContext::getInstance();
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

    private function getRoles(): array
    {
        return $this->context->getRoles();
    }

    /**
     * Check if a column exists in a table
     */
    private function columnExists(string $table, string $column): bool
    {
        try {
            $result = $this->db->fetchOne("SHOW COLUMNS FROM $table LIKE ?", [$column]);
            return (bool)$result;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if user has admin access
     */
    private function hasAdminAccess(): bool
    {
        $userId = $this->getUserId();
        $roles = $this->getRoles();

        // Debug logging
        error_log('=== hasAdminAccess check ===');
        error_log('  user_id: ' . ($userId ?? 'null'));
        error_log('  roles: ' . json_encode($roles));
        error_log('  isPlatformAdmin(): ' . ($this->isPlatformAdmin() ? 'true' : 'false'));

        // Check multiple conditions
        $isAdmin = (
            $this->isPlatformAdmin() ||
            $userId == 1 ||
            in_array('platform_super_admin', $roles) ||
            in_array('platform_admin', $roles) ||
            in_array('admin', $roles) ||
            ($_SESSION['is_platform_admin'] ?? false) === true
        );

        error_log('  hasAdminAccess result: ' . ($isAdmin ? 'true' : 'false'));
        error_log('=== hasAdminAccess complete ===');

        return $isAdmin;
    }

    /**
     * Get subscription plans with flexible column support
     * GET /api/platform/index.php?endpoint=subscriptions&action=list
     * GET /api/platform/index.php?endpoint=subscriptions&action=plans
     */
    public function getSubscriptionPlans(): void
    {
        try {
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $status = isset($_GET['status']) ? trim($_GET['status']) : '';

            // Check which columns exist
            $hasStatus = $this->columnExists('subscription_plans', 'status');
            $hasDeletedAt = $this->columnExists('subscription_plans', 'deleted_at');
            $hasBillingCycle = $this->columnExists('subscription_plans', 'billing_cycle');
            $hasFeatures = $this->columnExists('subscription_plans', 'features');

            $params = [];
            $where = [];

            if ($hasDeletedAt) {
                $where[] = "deleted_at IS NULL";
            }

            if (!empty($search)) {
                $where[] = "(plan_name LIKE ? OR description LIKE ? OR plan_code LIKE ?)";
                $searchTerm = "%$search%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            if ($hasStatus && !empty($status)) {
                $where[] = "status = ?";
                $params[] = $status;
            }

            $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
            $offset = ($page - 1) * $limit;

            // Build select fields dynamically
            $selectFields = "id, plan_code, plan_name, description, price, created_at";

            if ($hasStatus) {
                $selectFields .= ", status";
            }
            if ($hasBillingCycle) {
                $selectFields .= ", billing_cycle";
            }
            if ($hasFeatures) {
                $selectFields .= ", features";
            }

            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM subscription_plans $whereClause";
            $countResult = $this->db->fetchOne($countSql, $params);
            $total = (int)($countResult['total'] ?? 0);
            $totalPages = $total > 0 ? ceil($total / $limit) : 0;

            // Get plans
            $sql = "SELECT $selectFields FROM subscription_plans 
                    $whereClause 
                    ORDER BY price ASC, plan_name ASC
                    LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $plans = $this->db->fetchAll($sql, $params);

            // Normalize plan data for frontend
            foreach ($plans as &$plan) {
                // Set is_active based on status or default
                if ($hasStatus) {
                    $plan['is_active'] = ($plan['status'] === 'active') ? 1 : 0;
                } else {
                    $plan['is_active'] = 1;
                    $plan['status'] = 'active';
                }

                // Set billing_cycle if not present
                if (!isset($plan['billing_cycle']) || empty($plan['billing_cycle'])) {
                    $plan['billing_cycle'] = 'monthly';
                }

                // Parse features if JSON string
                if (isset($plan['features']) && is_string($plan['features'])) {
                    $plan['features'] = json_decode($plan['features'], true);
                }

                // Get tenant count for this plan
                $tenantCount = $this->db->fetchOne(
                    "SELECT COUNT(*) as count FROM tenant_subscriptions WHERE plan_id = ? AND deleted_at IS NULL",
                    [$plan['id']]
                );
                $plan['tenant_count'] = (int)($tenantCount['count'] ?? 0);
            }

            $this->response->success([
                'data' => $plans,
                'total' => $total,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'per_page' => $limit
            ]);
        } catch (Exception $e) {
            error_log('getSubscriptionPlans error: ' . $e->getMessage());
            $this->response->error('Error fetching subscription plans: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Alias for getSubscriptionPlans
     */
    public function getPlans(): void
    {
        $this->getSubscriptionPlans();
    }

    /**
     * Get a single subscription plan by ID
     * GET /api/platform/index.php?endpoint=subscriptions&action=get_plan&id={id}
     */
    public function getSubscriptionPlan(int $id): void
    {
        try {
            $sql = "SELECT * FROM subscription_plans WHERE id = ? AND deleted_at IS NULL";
            $plan = $this->db->fetchOne($sql, [$id]);

            if (!$plan) {
                $this->response->error('Subscription plan not found', 404);
                return;
            }

            // Normalize data
            $plan['is_active'] = ($plan['status'] === 'active') ? 1 : 0;

            if (isset($plan['features']) && is_string($plan['features'])) {
                $plan['features'] = json_decode($plan['features'], true);
            }

            // Get tenant count
            $tenantCount = $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM tenant_subscriptions WHERE plan_id = ? AND deleted_at IS NULL",
                [$id]
            );
            $plan['tenant_count'] = (int)($tenantCount['count'] ?? 0);

            $this->response->success($plan);
        } catch (Exception $e) {
            error_log('getSubscriptionPlan error: ' . $e->getMessage());
            $this->response->error('Error fetching subscription plan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create a new subscription plan
     * POST /api/platform/index.php?endpoint=subscriptions&action=create_plan
     */
    public function createPlan(): void
    {
        try {
            error_log('=== createPlan called ===');

            // Check if user has admin access
            if (!$this->hasAdminAccess()) {
                error_log('createPlan: Access denied - not admin');
                $this->response->error(
                    'Only platform admins can create subscription plans. Your roles: ' . json_encode($this->getRoles()),
                    403
                );
                return;
            }

            $userId = $this->getUserId();
            $data = json_decode(file_get_contents('php://input'), true);

            error_log('createPlan: Request data: ' . json_encode($data));

            if (!$data) {
                $this->response->error('Invalid request data. Please provide valid JSON.', 400);
                return;
            }

            // Validate required fields
            $required = ['plan_name', 'plan_code', 'price'];
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

            // Validate price
            if (!is_numeric($data['price']) || $data['price'] < 0) {
                $this->response->error('Price must be a valid number greater than or equal to 0', 400);
                return;
            }

            // Check if plan code already exists
            $existing = $this->db->fetchOne(
                "SELECT id FROM subscription_plans WHERE plan_code = ? AND deleted_at IS NULL",
                [$data['plan_code']]
            );
            if ($existing) {
                $this->response->error('Plan code "' . $data['plan_code'] . '" already exists. Please use a unique code.', 400);
                return;
            }

            // Check which columns exist
            $hasStatus = $this->columnExists('subscription_plans', 'status');
            $hasBillingCycle = $this->columnExists('subscription_plans', 'billing_cycle');
            $hasFeatures = $this->columnExists('subscription_plans', 'features');
            $hasMaxUsers = $this->columnExists('subscription_plans', 'max_users');
            $hasMaxStorage = $this->columnExists('subscription_plans', 'max_storage');
            $hasMaxSchools = $this->columnExists('subscription_plans', 'max_schools');
            $hasMaxCampuses = $this->columnExists('subscription_plans', 'max_campuses');

            $uuid = bin2hex(random_bytes(16));

            // Build insert query dynamically
            $fields = ['uuid', 'plan_code', 'plan_name', 'description', 'price'];
            $placeholders = ['?', '?', '?', '?', '?'];
            $params = [
                $uuid,
                $data['plan_code'],
                $data['plan_name'],
                $data['description'] ?? null,
                (float)$data['price']
            ];

            if ($hasStatus) {
                $fields[] = 'status';
                $placeholders[] = '?';
                $params[] = $data['status'] ?? 'active';
            }

            if ($hasBillingCycle) {
                $fields[] = 'billing_cycle';
                $placeholders[] = '?';
                $params[] = $data['billing_cycle'] ?? 'monthly';
            }

            if ($hasFeatures && !empty($data['features'])) {
                $fields[] = 'features';
                $placeholders[] = '?';
                $params[] = json_encode($data['features']);
            }

            if ($hasMaxUsers) {
                $fields[] = 'max_users';
                $placeholders[] = '?';
                $params[] = (int)($data['max_users'] ?? 0);
            }

            if ($hasMaxStorage) {
                $fields[] = 'max_storage';
                $placeholders[] = '?';
                $params[] = (int)($data['max_storage'] ?? 0);
            }

            if ($hasMaxSchools) {
                $fields[] = 'max_schools';
                $placeholders[] = '?';
                $params[] = (int)($data['max_schools'] ?? 0);
            }

            if ($hasMaxCampuses) {
                $fields[] = 'max_campuses';
                $placeholders[] = '?';
                $params[] = (int)($data['max_campuses'] ?? 0);
            }

            $fields[] = 'created_at';
            $placeholders[] = 'NOW()';

            $sql = "INSERT INTO subscription_plans (" . implode(', ', $fields) . ") 
                    VALUES (" . implode(', ', $placeholders) . ")";

            error_log('createPlan: SQL: ' . $sql);
            error_log('createPlan: Params: ' . json_encode($params));

            $result = $this->db->execute($sql, $params);

            if (!$result) {
                $this->response->error('Failed to create subscription plan. Please try again.', 500);
                return;
            }

            $planId = $this->db->lastInsertId();

            $this->logger->info('Subscription plan created', [
                'plan_id' => $planId,
                'plan_name' => $data['plan_name'],
                'plan_code' => $data['plan_code'],
                'created_by' => $userId
            ]);

            $this->response->success([
                'message' => 'Subscription plan created successfully',
                'data' => [
                    'id' => $planId,
                    'plan_name' => $data['plan_name'],
                    'plan_code' => $data['plan_code'],
                    'price' => $data['price'],
                    'billing_cycle' => $data['billing_cycle'] ?? 'monthly',
                    'status' => $data['status'] ?? 'active'
                ]
            ]);
        } catch (Exception $e) {
            error_log('createPlan error: ' . $e->getMessage());
            error_log('createPlan trace: ' . $e->getTraceAsString());
            $this->response->error('Error creating subscription plan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update a subscription plan
     * PUT /api/platform/index.php?endpoint=subscriptions&action=update_plan&id={id}
     */
    public function updatePlan(int $id): void
    {
        try {
            error_log('=== updatePlan called ===');

            // Check if user has admin access
            if (!$this->hasAdminAccess()) {
                error_log('updatePlan: Access denied - not admin');
                $this->response->error('Only platform admins can update subscription plans', 403);
                return;
            }

            $userId = $this->getUserId();
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data) {
                $this->response->error('Invalid request data', 400);
                return;
            }

            // Check if plan exists
            $plan = $this->db->fetchOne("SELECT id FROM subscription_plans WHERE id = ? AND deleted_at IS NULL", [$id]);
            if (!$plan) {
                $this->response->error('Subscription plan not found', 404);
                return;
            }

            // Check which columns exist
            $hasStatus = $this->columnExists('subscription_plans', 'status');

            $updates = [];
            $params = [];

            // Map is_active to status
            if (isset($data['is_active'])) {
                if ($hasStatus) {
                    $status = $data['is_active'] ? 'active' : 'inactive';
                    $updates[] = "status = ?";
                    $params[] = $status;
                }
            }

            $allowedFields = ['plan_name', 'description', 'price', 'billing_cycle', 'features', 'max_users', 'max_storage', 'max_schools', 'max_campuses', 'status'];
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    if ($field === 'features' && is_array($data[$field])) {
                        $updates[] = "$field = ?";
                        $params[] = json_encode($data[$field]);
                    } elseif ($field === 'price' && is_numeric($data[$field])) {
                        $updates[] = "$field = ?";
                        $params[] = (float)$data[$field];
                    } elseif (in_array($field, ['max_users', 'max_storage', 'max_schools', 'max_campuses']) && is_numeric($data[$field])) {
                        $updates[] = "$field = ?";
                        $params[] = (int)$data[$field];
                    } else {
                        $updates[] = "$field = ?";
                        $params[] = $data[$field];
                    }
                }
            }

            if (empty($updates)) {
                $this->response->error('No fields to update', 400);
                return;
            }

            $updates[] = "updated_at = NOW()";
            $params[] = $id;

            $sql = "UPDATE subscription_plans SET " . implode(", ", $updates) . " WHERE id = ? AND deleted_at IS NULL";
            $this->db->execute($sql, $params);

            $this->logger->info('Subscription plan updated', [
                'plan_id' => $id,
                'updated_by' => $userId
            ]);

            $this->response->success(['message' => 'Subscription plan updated successfully']);
        } catch (Exception $e) {
            error_log('updatePlan error: ' . $e->getMessage());
            $this->response->error('Error updating subscription plan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a subscription plan (soft delete)
     * DELETE /api/platform/index.php?endpoint=subscriptions&action=delete_plan&id={id}
     */
    public function deletePlan(int $id): void
    {
        try {
            error_log('=== deletePlan called ===');

            // Check if user has admin access
            if (!$this->hasAdminAccess()) {
                error_log('deletePlan: Access denied - not admin');
                $this->response->error('Only platform admins can delete subscription plans', 403);
                return;
            }

            $userId = $this->getUserId();

            // Check if plan exists
            $plan = $this->db->fetchOne("SELECT id FROM subscription_plans WHERE id = ? AND deleted_at IS NULL", [$id]);
            if (!$plan) {
                $this->response->error('Subscription plan not found', 404);
                return;
            }

            // Check if plan is in use
            $inUse = $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM tenant_subscriptions WHERE plan_id = ? AND deleted_at IS NULL",
                [$id]
            );
            if ((int)($inUse['count'] ?? 0) > 0) {
                $this->response->error('Cannot delete plan that is assigned to ' . $inUse['count'] . ' tenant(s). Please reassign tenants first.', 400);
                return;
            }

            $sql = "UPDATE subscription_plans SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
            $this->db->execute($sql, [$id]);

            $this->logger->info('Subscription plan deleted', [
                'plan_id' => $id,
                'deleted_by' => $userId
            ]);

            $this->response->success(['message' => 'Subscription plan deleted successfully']);
        } catch (Exception $e) {
            error_log('deletePlan error: ' . $e->getMessage());
            $this->response->error('Error deleting subscription plan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get tenant subscriptions
     * GET /api/platform/index.php?endpoint=subscriptions&action=tenant_subscriptions
     */
    public function getTenantSubscriptions(): void
    {
        try {
            $tenantId = $this->getTenantId();
            $userId = $this->getUserId();

            if (!$userId) {
                $this->response->error('Authentication required', 401);
                return;
            }

            $params = [];
            $where = ["ts.deleted_at IS NULL"];

            if (!$this->isPlatformAdmin() && $tenantId) {
                $where[] = "ts.tenant_id = ?";
                $params[] = $tenantId;
            } elseif (isset($_GET['tenant_id']) && $this->isPlatformAdmin()) {
                $where[] = "ts.tenant_id = ?";
                $params[] = (int)$_GET['tenant_id'];
            } elseif (!$this->isPlatformAdmin()) {
                $this->response->error('Access denied. Please specify a tenant.', 403);
                return;
            }

            $whereClause = "WHERE " . implode(" AND ", $where);

            $sql = "SELECT ts.*, 
                           sp.plan_name, sp.plan_code, sp.price, sp.billing_cycle,
                           t.tenant_name, t.legal_name
                    FROM tenant_subscriptions ts
                    LEFT JOIN subscription_plans sp ON ts.plan_id = sp.id
                    LEFT JOIN tenants t ON ts.tenant_id = t.id
                    $whereClause
                    ORDER BY ts.created_at DESC";

            $subscriptions = $this->db->fetchAll($sql, $params);

            $this->response->success($subscriptions);
        } catch (Exception $e) {
            error_log('getTenantSubscriptions error: ' . $e->getMessage());
            $this->response->error('Error fetching tenant subscriptions: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Assign plan to tenant
     * POST /api/platform/index.php?endpoint=subscriptions&action=assign_plan
     */
    public function assignPlanToTenant(): void
    {
        try {
            error_log('=== assignPlanToTenant called ===');

            // Check if user has admin access
            if (!$this->hasAdminAccess()) {
                error_log('assignPlanToTenant: Access denied - not admin');
                $this->response->error('Only platform admins can assign plans to tenants', 403);
                return;
            }

            $data = json_decode(file_get_contents('php://input'), true);

            if (empty($data['tenant_id']) || empty($data['plan_id'])) {
                $this->response->error('tenant_id and plan_id are required', 400);
                return;
            }

            // Check if tenant exists
            $tenant = $this->db->fetchOne("SELECT id, tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$data['tenant_id']]);
            if (!$tenant) {
                $this->response->error('Tenant not found', 404);
                return;
            }

            // Check if plan exists
            $plan = $this->db->fetchOne("SELECT id, plan_name FROM subscription_plans WHERE id = ? AND deleted_at IS NULL", [$data['plan_id']]);
            if (!$plan) {
                $this->response->error('Subscription plan not found', 404);
                return;
            }

            // Check if tenant already has a subscription
            $existing = $this->db->fetchOne(
                "SELECT id FROM tenant_subscriptions WHERE tenant_id = ? AND deleted_at IS NULL",
                [$data['tenant_id']]
            );

            $startDate = date('Y-m-d H:i:s');
            $endDate = null;
            if (isset($data['billing_cycle']) && $data['billing_cycle'] !== 'lifetime') {
                $endDate = date('Y-m-d H:i:s', strtotime('+1 year'));
            }

            if ($existing) {
                // Update existing subscription
                $sql = "UPDATE tenant_subscriptions SET 
                            plan_id = ?, 
                            start_date = ?, 
                            end_date = ?, 
                            status = ?,
                            updated_at = NOW()
                        WHERE tenant_id = ? AND deleted_at IS NULL";
                $params = [
                    $data['plan_id'],
                    $startDate,
                    $endDate,
                    $data['status'] ?? 'active',
                    $data['tenant_id']
                ];
            } else {
                // Create new subscription
                $sql = "INSERT INTO tenant_subscriptions (
                            tenant_id, plan_id, start_date, end_date, status, created_at
                        ) VALUES (?, ?, ?, ?, ?, NOW())";
                $params = [
                    $data['tenant_id'],
                    $data['plan_id'],
                    $startDate,
                    $endDate,
                    $data['status'] ?? 'active'
                ];
            }

            $this->db->execute($sql, $params);

            $this->logger->info('Plan assigned to tenant', [
                'tenant_id' => $data['tenant_id'],
                'tenant_name' => $tenant['tenant_name'],
                'plan_id' => $data['plan_id'],
                'plan_name' => $plan['plan_name']
            ]);

            $this->response->success([
                'message' => 'Plan "' . $plan['plan_name'] . '" assigned to "' . $tenant['tenant_name'] . '" successfully'
            ]);
        } catch (Exception $e) {
            error_log('assignPlanToTenant error: ' . $e->getMessage());
            $this->response->error('Error assigning plan: ' . $e->getMessage(), 500);
        }
    }
}
