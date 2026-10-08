<?php

/**
 * SubscriptionService.php - Simplified Fixed Version
 */

$basePath = dirname(__DIR__, 3) . '/';
require_once $basePath . 'app/services/Platform/BaseService.php';
require_once $basePath . 'app/models/Platform/SubscriptionPlan.php';
require_once $basePath . 'app/models/Platform/TenantSubscription.php';

class SubscriptionService extends BaseService
{
    private $planModel;
    private $subscriptionModel;

    public function __construct()
    {
        parent::__construct();
        $this->planModel = new SubscriptionPlan();
        $this->subscriptionModel = new TenantSubscription();
    }

    /**
     * Get all subscription plans - SIMPLIFIED
     */
    public function getPlans(bool $activeOnly = true): array
    {
        try {
            if ($activeOnly) {
                $plans = $this->planModel->getActive();
            } else {
                $sql = "SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order, price ASC";
                $plans = $this->db->fetchAll($sql);
            }

            return $this->success('Plans retrieved successfully', $plans);
        } catch (Exception $e) {
            $this->logError('Failed to get plans: ' . $e->getMessage());
            return $this->error('Failed to get plans: ' . $e->getMessage());
        }
    }

    /**
     * Get plan by ID
     */
    public function getPlan(int $planId): array
    {
        try {
            $plan = $this->planModel->find($planId);
            if (!$plan) {
                return $this->error('Plan not found');
            }
            return $this->success('Plan retrieved successfully', $plan);
        } catch (Exception $e) {
            $this->logError('Failed to get plan: ' . $e->getMessage());
            return $this->error('Failed to get plan');
        }
    }

    /**
     * Create a subscription plan
     */
    public function createPlan(array $data): array
    {
        try {
            $required = ['plan_name', 'plan_code', 'price'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return $this->error("{$field} is required");
                }
            }

            $existing = $this->planModel->findByCode($data['plan_code']);
            if ($existing) {
                return $this->error('Plan code already exists');
            }

            $planId = $this->planModel->create($data);
            if (!$planId) {
                return $this->error('Failed to create subscription plan');
            }

            return $this->success('Subscription plan created successfully', ['plan_id' => $planId]);
        } catch (Exception $e) {
            $this->logError('Failed to create plan: ' . $e->getMessage());
            return $this->error('Failed to create plan: ' . $e->getMessage());
        }
    }
}
