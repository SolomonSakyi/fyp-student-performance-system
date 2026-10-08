<?php

/**
 * SubscriptionPlan.php
 * Subscription Plan Model
 * 
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 2.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class SubscriptionPlan extends BaseModel
{
    protected $table = 'subscription_plans';

    protected $fillable = [
        'plan_name',
        'plan_code',
        'description',
        'price',
        'currency',
        'billing_cycle',
        'features',
        'is_active',
        'sort_order'
    ];

    /**
     * Get active plans
     */
    public function getActive(): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE is_active = 1 AND deleted_at IS NULL ORDER BY sort_order ASC, price ASC";
        return $this->rawFetch($sql);
    }

    /**
     * Get all plans (including inactive)
     */
    public function getAll(string $orderBy = 'sort_order', string $order = 'ASC'): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE deleted_at IS NULL ORDER BY {$orderBy} {$order}";
        return $this->rawFetch($sql);
    }

    /**
     * Find plan by code
     */
    public function findByCode(string $code): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE plan_code = ? AND deleted_at IS NULL LIMIT 1";
        return $this->rawFetchOne($sql, [$code]);
    }

    /**
     * Get plan with features decoded
     */
    public function getWithFeatures(int $planId): ?array
    {
        $plan = $this->find($planId);
        if ($plan && !empty($plan['features'])) {
            $plan['features'] = json_decode($plan['features'], true);
        }
        return $plan;
    }
}
