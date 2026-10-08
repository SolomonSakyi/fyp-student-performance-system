<?php

/**
 * SubscriptionService.php
 * Service for managing subscriptions with tenant isolation
 * 
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 2.0
 * 
 * @filepath app/services/Platform/SubscriptionService.php
 */

// Only start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class SubscriptionService
{
    /**
     * Database instance
     * @var DatabaseHelper
     */
    private $db;

    /**
     * Logger instance
     * @var LoggerHelper
     */
    private $logger;

    /**
     * Tenant context
     * @var TenantContext
     */
    private $context;

    /**
     * Constructor
     */
    public function __construct()
    {
        require_once dirname(__DIR__, 2) . '/helpers/DatabaseHelper.php';
        require_once dirname(__DIR__, 2) . '/helpers/LoggerHelper.php';
        require_once dirname(__DIR__, 2) . '/services/Tenant/TenantContext.php';

        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->context = TenantContext::getInstance();
    }

    /**
     * Get all subscription plans
     * 
     * @param bool $includeInactive Include inactive plans
     * @return array List of plans
     */
    public function getPlans($includeInactive = false): array
    {
        try {
            $sql = "SELECT * FROM subscription_plans WHERE deleted_at IS NULL";

            if (!$includeInactive) {
                $sql .= " AND is_active = 1";
            }

            $sql .= " ORDER BY sort_order ASC, price ASC";

            $plans = $this->db->fetchAll($sql);

            // Format data
            foreach ($plans as &$plan) {
                $plan['price_formatted'] = number_format($plan['price'] ?? 0, 2);
                $plan['features_list'] = !empty($plan['features']) ? json_decode($plan['features'], true) : [];
                $plan['is_popular'] = ($plan['is_popular'] ?? 0) == 1;
                $plan['is_trial'] = ($plan['is_trial'] ?? 0) == 1;

                // Map duration_type to billing_cycle for frontend compatibility
                $plan['billing_cycle'] = $plan['duration_type'] ?? 'monthly';
                $plan['billing_cycle_label'] = $this->getBillingCycleLabel($plan['billing_cycle']);

                if (isset($plan['max_storage_mb'])) {
                    $plan['max_storage_gb'] = round($plan['max_storage_mb'] / 1024, 1);
                }
            }

            return $plans;
        } catch (Exception $e) {
            $this->logger->error('getPlans error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get billing cycle label
     */
    private function getBillingCycleLabel($type): string
    {
        $labels = [
            'trial' => 'Trial',
            'monthly' => 'Monthly',
            'quarterly' => 'Quarterly',
            'annual' => 'Annual',
            'enterprise' => 'Enterprise',
            'custom' => 'Custom',
            'free' => 'Free'
        ];
        return $labels[$type] ?? ucfirst($type);
    }

    /**
     * Get a single subscription plan by ID
     * 
     * @param int $planId Plan ID
     * @return array|null Plan data or null
     */
    public function getPlan(int $planId): ?array
    {
        try {
            $sql = "SELECT * FROM subscription_plans 
                    WHERE id = ? AND deleted_at IS NULL";

            $plan = $this->db->fetchOne($sql, [$planId]);

            if ($plan) {
                $plan['price_formatted'] = number_format($plan['price'] ?? 0, 2);
                $plan['features_list'] = !empty($plan['features']) ? json_decode($plan['features'], true) : [];
                $plan['is_popular'] = ($plan['is_popular'] ?? 0) == 1;
                $plan['is_trial'] = ($plan['is_trial'] ?? 0) == 1;

                if (isset($plan['max_storage_mb'])) {
                    $plan['max_storage_gb'] = round($plan['max_storage_mb'] / 1024, 1);
                }
            }

            return $plan;
        } catch (Exception $e) {
            $this->logger->error('getPlan error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a subscription plan
     * 
     * @param array $data Plan data
     * @return array Result with success flag
     */
    public function createPlan(array $data): array
    {
        try {
            // Validate required fields
            $required = ['plan_name', 'plan_code', 'price'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => "Missing required field: $field"];
                }
            }

            // Validate price
            if ($data['price'] < 0) {
                return ['success' => false, 'message' => 'Price cannot be negative'];
            }

            // Check if plan code already exists
            if ($this->planCodeExists($data['plan_code'])) {
                return ['success' => false, 'message' => 'Plan code already exists'];
            }

            // Encode features as JSON if provided
            $features = isset($data['features']) && is_array($data['features'])
                ? json_encode($data['features'])
                : null;

            $sql = "INSERT INTO subscription_plans (
                plan_name,
                plan_code,
                description,
                price,
                billing_cycle,
                features,
                max_students,
                max_staff,
                max_campuses,
                max_schools,
                max_storage_mb,
                max_api_calls,
                max_ai_requests,
                sms_balance,
                email_balance,
                is_trial,
                trial_days,
                grace_period_days,
                is_popular,
                status,
                sort_order,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)";

            $result = $this->db->execute($sql, [
                $data['plan_name'],
                $data['plan_code'],
                $data['description'] ?? '',
                $data['price'],
                $data['billing_cycle'] ?? 'monthly',
                $features,
                $data['max_students'] ?? 0,
                $data['max_staff'] ?? 0,
                $data['max_campuses'] ?? 1,
                $data['max_schools'] ?? 1,
                $data['max_storage_mb'] ?? 1024,
                $data['max_api_calls'] ?? 1000,
                $data['max_ai_requests'] ?? 10,
                $data['sms_balance'] ?? 0,
                $data['email_balance'] ?? 0,
                $data['is_trial'] ?? 0,
                $data['trial_days'] ?? 0,
                $data['grace_period_days'] ?? 7,
                $data['is_popular'] ?? 0,
                $data['status'] ?? 'active',
                $data['sort_order'] ?? 0
            ]);

            if (!$result) {
                return ['success' => false, 'message' => 'Failed to create plan'];
            }

            return [
                'success' => true,
                'message' => 'Plan created successfully',
                'data' => ['plan_id' => $this->db->lastInsertId()]
            ];
        } catch (Exception $e) {
            $this->logger->error('createPlan error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update a subscription plan
     * 
     * @param int $planId Plan ID
     * @param array $data Plan data
     * @return array Result with success flag
     */
    public function updatePlan(int $planId, array $data): array
    {
        try {
            // Check if plan exists
            $plan = $this->getPlan($planId);
            if (!$plan) {
                return ['success' => false, 'message' => 'Plan not found'];
            }

            $fields = [];
            $params = [];

            $allowedFields = [
                'plan_name',
                'plan_code',
                'description',
                'price',
                'billing_cycle',
                'max_students',
                'max_staff',
                'max_campuses',
                'max_schools',
                'max_storage_mb',
                'max_api_calls',
                'max_ai_requests',
                'sms_balance',
                'email_balance',
                'is_trial',
                'trial_days',
                'grace_period_days',
                'is_popular',
                'status',
                'sort_order'
            ];

            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $fields[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            // Handle features separately
            if (isset($data['features'])) {
                $fields[] = "features = ?";
                $params[] = is_array($data['features']) ? json_encode($data['features']) : $data['features'];
            }

            if (empty($fields)) {
                return ['success' => false, 'message' => 'No fields to update'];
            }

            $params[] = $planId;

            $sql = "UPDATE subscription_plans 
                    SET " . implode(', ', $fields) . ", updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND deleted_at IS NULL";

            $result = $this->db->execute($sql, $params);

            return [
                'success' => $result,
                'message' => $result ? 'Plan updated successfully' : 'Failed to update plan'
            ];
        } catch (Exception $e) {
            $this->logger->error('updatePlan error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete a subscription plan (soft delete)
     * 
     * @param int $planId Plan ID
     * @return array Result with success flag
     */
    public function deletePlan(int $planId): array
    {
        try {
            // Check if plan exists
            $plan = $this->getPlan($planId);
            if (!$plan) {
                return ['success' => false, 'message' => 'Plan not found'];
            }

            $sql = "UPDATE subscription_plans 
                    SET deleted_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND deleted_at IS NULL";

            $result = $this->db->execute($sql, [$planId]);

            return [
                'success' => $result,
                'message' => $result ? 'Plan deleted successfully' : 'Failed to delete plan'
            ];
        } catch (Exception $e) {
            $this->logger->error('deletePlan error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Check if plan code exists
     * 
     * @param string $code Plan code
     * @return bool True if exists
     */
    private function planCodeExists(string $code): bool
    {
        $sql = "SELECT id FROM subscription_plans WHERE plan_code = ? AND deleted_at IS NULL";
        $result = $this->db->fetchOne($sql, [$code]);
        return (bool) $result;
    }
}
