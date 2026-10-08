<?php
/**
 * TenantService.php
 *
 * Tenant Management Service
 *
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 1.0
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../../models/Platform/Tenant.php';
require_once __DIR__ . '/../../models/Platform/School.php';
require_once __DIR__ . '/../../models/Platform/Campus.php';
require_once __DIR__ . '/../../models/Platform/TenantDomain.php';
require_once __DIR__ . '/../../models/Platform/TenantSubscription.php';
require_once __DIR__ . '/../../models/Platform/SubscriptionPlan.php';
require_once __DIR__ . '/../../models/Platform/PlatformAuditLog.php';
require_once __DIR__ . '/../../models/Platform/PlatformUser.php';

class TenantService extends BaseService
{
    /**
     * @var Tenant Tenant model
     */
    private $tenantModel;

    /**
     * @var School School model
     */
    private $schoolModel;

    /**
     * @var Campus Campus model
     */
    private $campusModel;

    /**
     * @var TenantDomain Domain model
     */
    private $domainModel;

    /**
     * @var TenantSubscription Subscription model
     */
    private $subscriptionModel;

    /**
     * @var SubscriptionPlan Plan model
     */
    private $planModel;

    /**
     * @var PlatformAuditLog Audit log model
     */
    private $auditModel;

    /**
     * @var PlatformUser User model
     */
    private $userModel;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->tenantModel = new Tenant();
        $this->schoolModel = new School();
        $this->campusModel = new Campus();
        $this->domainModel = new TenantDomain();
        $this->subscriptionModel = new TenantSubscription();
        $this->planModel = new SubscriptionPlan();
        $this->auditModel = new PlatformAuditLog();
        $this->userModel = new PlatformUser();
    }

    // ============================================================
    // TENANT CRUD OPERATIONS
    // ============================================================

    /**
     * Register a new tenant
     */
    public function registerTenant(array $data): array
    {
        try {
            // Validate required fields
            $required = ['tenant_name', 'tenant_code', 'legal_name', 'email', 'phone'];
            $this->validateRequired($data, $required);

            // Check if tenant code already exists
            $existing = $this->tenantModel->findByCode($data['tenant_code']);
            if ($existing) {
                return $this->error('Tenant code already exists');
            }

            // Get default plan (Free Trial)
            $plan = $this->planModel->findByCode('free_trial');
            if (!$plan) {
                // If no free trial, get the cheapest plan
                $plans = $this->planModel->getActivePlans();
                if (empty($plans)) {
                    return $this->error('No subscription plans available');
                }
                $plan = $plans[0];
            }

            // Begin transaction
            $this->beginTransaction();

            // Create tenant
            $tenantId = $this->tenantModel->create([
                'tenant_name' => $data['tenant_name'],
                'tenant_code' => $data['tenant_code'],
                'legal_name' => $data['legal_name'],
                'institution_type' => $data['institution_type'] ?? null,
                'country_id' => $data['country_id'] ?? null,
                'region' => $data['region'] ?? null,
                'district' => $data['district'] ?? null,
                'city' => $data['city'] ?? null,
                'digital_address' => $data['digital_address'] ?? null,
                'postal_address' => $data['postal_address'] ?? null,
                'phone' => $data['phone'],
                'email' => $data['email'],
                'website' => $data['website'] ?? null,
                'logo_url' => $data['logo_url'] ?? null,
                'language' => $data['language'] ?? 'en',
                'timezone' => $data['timezone'] ?? 'UTC',
                'currency' => $data['currency'] ?? 'GHS',
                'date_format' => $data['date_format'] ?? 'Y-m-d',
                'academic_calendar_type' => $data['academic_calendar_type'] ?? 'semester',
                'status' => Tenant::STATUS_PENDING,
                'max_students' => $plan['max_students'] ?? 0,
                'max_staff' => $plan['max_staff'] ?? 0,
                'max_campuses' => $plan['max_campuses'] ?? 1,
                'max_storage_mb' => $plan['max_storage_mb'] ?? 10240,
                'max_api_calls' => $plan['max_api_calls'] ?? 10000,
                'max_ai_requests' => $plan['max_ai_requests'] ?? 100,
                'created_by' => $data['created_by'] ?? null
            ]);

            if (!$tenantId) {
                $this->rollback();
                return $this->error('Failed to create tenant');
            }

            // Create default school
            $schoolId = $this->schoolModel->create([
                'tenant_id' => $tenantId,
                'school_name' => $data['school_name'] ?? $data['tenant_name'] . ' School',
                'school_code' => $data['school_code'] ?? strtoupper(substr($data['tenant_name'], 0, 6)),
                'school_type' => $data['school_type'] ?? null,
                'country_id' => $data['country_id'] ?? null,
                'region' => $data['region'] ?? null,
                'district' => $data['district'] ?? null,
                'city' => $data['city'] ?? null,
                'digital_address' => $data['digital_address'] ?? null,
                'postal_address' => $data['postal_address'] ?? null,
                'phone' => $data['phone'],
                'email' => $data['email'],
                'website' => $data['website'] ?? null,
                'principal_name' => $data['principal_name'] ?? null,
                'principal_phone' => $data['principal_phone'] ?? null,
                'principal_email' => $data['principal_email'] ?? null,
                'status' => School::STATUS_PENDING,
                'created_by' => $data['created_by'] ?? null
            ]);

            if (!$schoolId) {
                $this->rollback();
                return $this->error('Failed to create school');
            }

            // Create default campus
            $campusId = $this->campusModel->create([
                'school_id' => $schoolId,
                'campus_name' => $data['campus_name'] ?? 'Main Campus',
                'campus_code' => $data['campus_code'] ?? 'MAIN',
                'address' => $data['postal_address'] ?? null,
                'status' => Campus::STATUS_ACTIVE,
                'capacity' => $plan['max_students'] ?? 0,
                'created_by' => $data['created_by'] ?? null
            ]);

            if (!$campusId) {
                $this->rollback();
                return $this->error('Failed to create campus');
            }

            // Create subscription
            $subscriptionNumber = $this->generateCode('SUB-', 'tenant_subscriptions', 'subscription_number');
            
            $subscriptionId = $this->subscriptionModel->create([
                'tenant_id' => $tenantId,
                'plan_id' => $plan['id'],
                'subscription_number' => $subscriptionNumber,
                'start_date' => date('Y-m-d'),
                'end_date' => date('Y-m-d', strtotime('+30 days')),
                'renewal_date' => date('Y-m-d', strtotime('+30 days')),
                'status' => TenantSubscription::STATUS_ACTIVE,
                'is_auto_renew' => 1,
                'price' => $plan['price'] ?? 0,
                'currency' => $plan['currency'] ?? 'GHS',
                'max_students' => $plan['max_students'] ?? 0,
                'max_staff' => $plan['max_staff'] ?? 0,
                'max_campuses' => $plan['max_campuses'] ?? 1,
                'max_storage_mb' => $plan['max_storage_mb'] ?? 10240,
                'max_api_calls' => $plan['max_api_calls'] ?? 10000,
                'max_ai_requests' => $plan['max_ai_requests'] ?? 100,
                'created_by' => $data['created_by'] ?? null
            ]);

            if (!$subscriptionId) {
                $this->rollback();
                return $this->error('Failed to create subscription');
            }

            // Log audit
            $this->auditModel->log([
                'user_id' => $data['created_by'] ?? null,
                'tenant_id' => $tenantId,
                'action_type' => 'CREATE',
                'module' => 'Tenant',
                'resource' => 'tenant',
                'resource_id' => $tenantId,
                'new_data' => json_encode(['tenant_name' => $data['tenant_name'], 'tenant_code' => $data['tenant_code']]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            $this->commit();

            $this->logInfo("Tenant registered successfully", ['tenant_id' => $tenantId, 'tenant_code' => $data['tenant_code']]);

            return $this->success('Tenant registered successfully', [
                'tenant_id' => $tenantId,
                'tenant_code' => $data['tenant_code'],
                'tenant_name' => $data['tenant_name'],
                'school_id' => $schoolId,
                'campus_id' => $campusId,
                'subscription_id' => $subscriptionId
            ]);

        } catch (Exception $e) {
            $this->rollback();
            $this->logError('Failed to register tenant: ' . $e->getMessage());
            return $this->error('Failed to register tenant: ' . $e->getMessage());
        }
    }

    /**
     * Get all tenants with pagination
     */
    public function getTenants(int $page = 1, int $limit = 50, string $search = ''): array
    {
        try {
            $offset = ($page - 1) * $limit;
            $params = [];
            $where = "is_active = 1";
            
            if ($search) {
                $where .= " AND (tenant_name LIKE ? OR tenant_code LIKE ? OR legal_name LIKE ? OR email LIKE ?)";
                $searchTerm = '%' . $search . '%';
                $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
            }
            
            $sql = "SELECT t.*, 
                           ts.id as subscription_id,
                           ts.subscription_number,
                           ts.end_date,
                           ts.status as subscription_status,
                           sp.plan_name,
                           sp.plan_code,
                           COUNT(DISTINCT s.id) as school_count,
                           COUNT(DISTINCT c.id) as campus_count
                    FROM tenants t
                    LEFT JOIN tenant_subscriptions ts ON t.id = ts.tenant_id AND ts.is_active = 1
                    LEFT JOIN subscription_plans sp ON ts.plan_id = sp.id
                    LEFT JOIN schools s ON t.id = s.tenant_id AND s.is_active = 1
                    LEFT JOIN campuses c ON s.id = c.school_id AND c.is_active = 1
                    WHERE {$where}
                    GROUP BY t.id
                    ORDER BY t.created_at DESC
                    LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $tenants = $this->db->fetchAll($sql, $params);
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM tenants WHERE {$where}";
            $countResult = $this->db->fetchOne($countSql, array_slice($params, 0, -2));
            
            return $this->success('Tenants retrieved successfully', [
                'tenants' => $tenants,
                'total' => (int)($countResult['total'] ?? 0),
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil(($countResult['total'] ?? 0) / $limit)
            ]);
        } catch (Exception $e) {
            $this->logError('Failed to get tenants: ' . $e->getMessage());
            return $this->error('Failed to get tenants: ' . $e->getMessage());
        }
    }

    /**
     * Get tenant by ID with full details
     */
    public function getTenant(int $tenantId): array
    {
        try {
            $tenant = $this->tenantModel->getWithSubscription($tenantId);
            if (!$tenant) {
                return $this->error('Tenant not found');
            }
            
            // Get schools
            $schools = $this->tenantModel->getSchools($tenantId);
            
            // Get campuses for each school
            foreach ($schools as &$school) {
                $school['campuses'] = $this->campusModel->getBySchool($school['id']);
            }
            
            // Get modules
            $modules = $this->tenantModel->getModules($tenantId);
            
            // Get domains
            $domains = $this->domainModel->getByTenant($tenantId);
            
            $tenant['schools'] = $schools;
            $tenant['modules'] = $modules;
            $tenant['domains'] = $domains;
            
            return $this->success('Tenant retrieved successfully', $tenant);
        } catch (Exception $e) {
            $this->logError('Failed to get tenant: ' . $e->getMessage());
            return $this->error('Failed to get tenant: ' . $e->getMessage());
        }
    }

    /**
     * Update tenant
     */
    public function updateTenant(int $tenantId, array $data): array
    {
        try {
            $tenant = $this->tenantModel->find($tenantId);
            if (!$tenant) {
                return $this->error('Tenant not found');
            }
            
            // Filter allowed fields
            $allowed = ['tenant_name', 'legal_name', 'institution_type', 'country_id', 
                       'region', 'district', 'city', 'digital_address', 'postal_address',
                       'phone', 'email', 'website', 'logo_url', 'language', 'timezone',
                       'currency', 'date_format', 'academic_calendar_type'];
            
            $updateData = array_intersect_key($data, array_flip($allowed));
            
            if (empty($updateData)) {
                return $this->error('No valid fields to update');
            }
            
            $result = $this->tenantModel->update($tenantId, $updateData);
            if (!$result) {
                return $this->error('Failed to update tenant');
            }
            
            // Log audit
            $this->auditModel->log([
                'user_id' => $_SESSION['user_id'] ?? null,
                'tenant_id' => $tenantId,
                'action_type' => 'UPDATE',
                'module' => 'Tenant',
                'resource' => 'tenant',
                'resource_id' => $tenantId,
                'old_data' => json_encode($tenant),
                'new_data' => json_encode($updateData),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
            $this->logInfo("Tenant updated", ['tenant_id' => $tenantId]);
            
            return $this->success('Tenant updated successfully');
        } catch (Exception $e) {
            $this->logError('Failed to update tenant: ' . $e->getMessage());
            return $this->error('Failed to update tenant: ' . $e->getMessage());
        }
    }

    /**
     * Approve tenant
     */
    public function approveTenant(int $tenantId, int $approvedBy): array
    {
        try {
            $tenant = $this->tenantModel->find($tenantId);
            if (!$tenant) {
                return $this->error('Tenant not found');
            }
            
            if ($tenant['status'] !== Tenant::STATUS_PENDING) {
                return $this->error('Tenant is not pending approval');
            }
            
            $result = $this->tenantModel->approve($tenantId, $approvedBy);
            if (!$result) {
                return $this->error('Failed to approve tenant');
            }
            
            // Log audit
            $this->auditModel->log([
                'user_id' => $approvedBy,
                'tenant_id' => $tenantId,
                'action_type' => 'APPROVE',
                'module' => 'Tenant',
                'resource' => 'tenant',
                'resource_id' => $tenantId,
                'new_data' => json_encode(['status' => Tenant::STATUS_ACTIVE, 'approved_by' => $approvedBy]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
            $this->logInfo("Tenant approved", ['tenant_id' => $tenantId, 'approved_by' => $approvedBy]);
            
            return $this->success('Tenant approved successfully');
        } catch (Exception $e) {
            $this->logError('Failed to approve tenant: ' . $e->getMessage());
            return $this->error('Failed to approve tenant: ' . $e->getMessage());
        }
    }

    /**
     * Suspend tenant
     */
    public function suspendTenant(int $tenantId, int $suspendedBy, string $reason = ''): array
    {
        try {
            $tenant = $this->tenantModel->find($tenantId);
            if (!$tenant) {
                return $this->error('Tenant not found');
            }
            
            if ($tenant['status'] === Tenant::STATUS_SUSPENDED) {
                return $this->error('Tenant is already suspended');
            }
            
            if ($tenant['status'] === Tenant::STATUS_DELETED) {
                return $this->error('Cannot suspend a deleted tenant');
            }
            
            $result = $this->tenantModel->suspend($tenantId, $suspendedBy, $reason);
            if (!$result) {
                return $this->error('Failed to suspend tenant');
            }
            
            // Log audit
            $this->auditModel->log([
                'user_id' => $suspendedBy,
                'tenant_id' => $tenantId,
                'action_type' => 'SUSPEND',
                'module' => 'Tenant',
                'resource' => 'tenant',
                'resource_id' => $tenantId,
                'new_data' => json_encode(['status' => Tenant::STATUS_SUSPENDED, 'reason' => $reason]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
            $this->logInfo("Tenant suspended", ['tenant_id' => $tenantId, 'suspended_by' => $suspendedBy]);
            
            return $this->success('Tenant suspended successfully');
        } catch (Exception $e) {
            $this->logError('Failed to suspend tenant: ' . $e->getMessage());
            return $this->error('Failed to suspend tenant: ' . $e->getMessage());
        }
    }

    /**
     * Reactivate tenant
     */
    public function reactivateTenant(int $tenantId, int $reactivatedBy): array
    {
        try {
            $tenant = $this->tenantModel->find($tenantId);
            if (!$tenant) {
                return $this->error('Tenant not found');
            }
            
            if ($tenant['status'] !== Tenant::STATUS_SUSPENDED) {
                return $this->error('Only suspended tenants can be reactivated');
            }
            
            $result = $this->tenantModel->reactivate($tenantId, $reactivatedBy);
            if (!$result) {
                return $this->error('Failed to reactivate tenant');
            }
            
            // Log audit
            $this->auditModel->log([
                'user_id' => $reactivatedBy,
                'tenant_id' => $tenantId,
                'action_type' => 'REACTIVATE',
                'module' => 'Tenant',
                'resource' => 'tenant',
                'resource_id' => $tenantId,
                'new_data' => json_encode(['status' => Tenant::STATUS_ACTIVE]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
            $this->logInfo("Tenant reactivated", ['tenant_id' => $tenantId, 'reactivated_by' => $reactivatedBy]);
            
            return $this->success('Tenant reactivated successfully');
        } catch (Exception $e) {
            $this->logError('Failed to reactivate tenant: ' . $e->getMessage());
            return $this->error('Failed to reactivate tenant: ' . $e->getMessage());
        }
    }

    /**
     * Delete tenant (soft delete)
     */
    public function deleteTenant(int $tenantId): array
    {
        try {
            $tenant = $this->tenantModel->find($tenantId);
            if (!$tenant) {
                return $this->error('Tenant not found');
            }
            
            if ($tenant['status'] === Tenant::STATUS_DELETED) {
                return $this->error('Tenant is already deleted');
            }
            
            // Soft delete the tenant
            $result = $this->tenantModel->delete($tenantId);
            if (!$result) {
                return $this->error('Failed to delete tenant');
            }
            
            // Also update status
            $this->tenantModel->update($tenantId, ['status' => Tenant::STATUS_DELETED]);
            
            // Log audit
            $this->auditModel->log([
                'user_id' => $_SESSION['user_id'] ?? null,
                'tenant_id' => $tenantId,
                'action_type' => 'DELETE',
                'module' => 'Tenant',
                'resource' => 'tenant',
                'resource_id' => $tenantId,
                'old_data' => json_encode($tenant),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
            $this->logInfo("Tenant deleted", ['tenant_id' => $tenantId]);
            
            return $this->success('Tenant deleted successfully');
        } catch (Exception $e) {
            $this->logError('Failed to delete tenant: ' . $e->getMessage());
            return $this->error('Failed to delete tenant: ' . $e->getMessage());
        }
    }

    // ============================================================
    // TENANT STATISTICS
    // ============================================================

    /**
     * Get tenant statistics
     */
    public function getTenantStats(): array
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_tenants,
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_tenants,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_tenants,
                        SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_tenants,
                        SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_tenants,
                        SUM(max_students) as total_student_capacity,
                        SUM(max_staff) as total_staff_capacity,
                        SUM(max_storage_mb) as total_storage_mb
                    FROM tenants 
                    WHERE is_active = 1";
            
            $stats = $this->db->fetchOne($sql);
            
            // Get monthly growth
            $growth = $this->db->fetchAll(
                "SELECT 
                    DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(*) as count
                FROM tenants 
                WHERE is_active = 1
                AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ORDER BY month DESC
                LIMIT 12"
            );
            
            $stats['monthly_growth'] = $growth;
            
            return $this->success('Tenant statistics retrieved', $stats);
        } catch (Exception $e) {
            $this->logError('Failed to get tenant stats: ' . $e->getMessage());
            return $this->error('Failed to get tenant statistics');
        }
    }

    /**
     * Get tenant dashboard data
     */
    public function getTenantDashboard(int $tenantId): array
    {
        try {
            $tenant = $this->tenantModel->getWithSubscription($tenantId);
            if (!$tenant) {
                return $this->error('Tenant not found');
            }
            
            // Get schools count
            $schools = $this->tenantModel->getSchools($tenantId);
            
            // Get modules
            $modules = $this->tenantModel->getModules($tenantId);
            
            // Get usage stats
            $usage = $this->tenantModel->getUsageStats($tenantId);
            
            // Get subscription details
            $subscription = $this->subscriptionModel->getActiveForTenant($tenantId);
            
            return $this->success('Tenant dashboard data retrieved', [
                'tenant' => $tenant,
                'schools' => $schools,
                'school_count' => count($schools),
                'modules' => $modules,
                'usage' => $usage,
                'subscription' => $subscription
            ]);
        } catch (Exception $e) {
            $this->logError('Failed to get tenant dashboard: ' . $e->getMessage());
            return $this->error('Failed to get tenant dashboard');
        }
    }
}