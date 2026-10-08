<?php

/**
 * TenantSubscription.php
 * Tenant Subscription Model
 * 
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 2.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class TenantSubscription extends BaseModel
{
    protected $table = 'tenant_subscriptions';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'plan_name',
        'price',
        'currency',
        'billing_cycle',
        'features',
        'start_date',
        'end_date',
        'status',
        'payment_method',
        'is_active',
        'last_renewed_at'
    ];

    /**
     * Get active subscription by tenant
     */
    public function getActiveByTenant(int $tenantId): ?array
    {
        $sql = "SELECT ts.*, sp.plan_name as plan_display_name, sp.description 
                FROM {$this->table} ts
                LEFT JOIN subscription_plans sp ON ts.plan_id = sp.id
                WHERE ts.tenant_id = ? 
                AND ts.status = 'active' 
                AND ts.is_active = 1 
                AND ts.deleted_at IS NULL
                ORDER BY ts.id DESC LIMIT 1";
        return $this->rawFetchOne($sql, [$tenantId]);
    }

    /**
     * Get subscription history by tenant
     */
    public function getHistoryByTenant(int $tenantId, int $limit = 10): array
    {
        $sql = "SELECT ts.*, sp.plan_name as plan_display_name, sp.description 
                FROM {$this->table} ts
                LEFT JOIN subscription_plans sp ON ts.plan_id = sp.id
                WHERE ts.tenant_id = ? 
                AND ts.deleted_at IS NULL
                ORDER BY ts.id DESC LIMIT ?";
        return $this->rawFetch($sql, [$tenantId, $limit]);
    }

    /**
     * Get all subscriptions by tenant
     */
    public function getAllByTenant(int $tenantId): array
    {
        $sql = "SELECT ts.*, sp.plan_name as plan_display_name, sp.description 
                FROM {$this->table} ts
                LEFT JOIN subscription_plans sp ON ts.plan_id = sp.id
                WHERE ts.tenant_id = ? 
                AND ts.deleted_at IS NULL
                ORDER BY ts.id DESC";
        return $this->rawFetch($sql, [$tenantId]);
    }

    /**
     * Get expired subscriptions
     */
    public function getExpired(): array
    {
        $sql = "SELECT ts.*, t.tenant_name 
                FROM {$this->table} ts
                JOIN tenants t ON ts.tenant_id = t.id
                WHERE ts.end_date < CURDATE() 
                AND ts.status = 'active'
                AND ts.deleted_at IS NULL";
        return $this->rawFetch($sql);
    }

    /**
     * Get subscriptions expiring soon (within next 30 days)
     */
    public function getExpiringSoon(int $days = 30): array
    {
        $sql = "SELECT ts.*, t.tenant_name 
                FROM {$this->table} ts
                JOIN tenants t ON ts.tenant_id = t.id
                WHERE ts.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
                AND ts.status = 'active'
                AND ts.deleted_at IS NULL";
        return $this->rawFetch($sql, [$days]);
    }

    /**
     * Get subscription statistics by status
     */
    public function getStatsByStatus(): array
    {
        $sql = "SELECT status, COUNT(*) as count, SUM(price) as total_revenue 
                FROM {$this->table} 
                WHERE deleted_at IS NULL 
                GROUP BY status";
        return $this->rawFetch($sql);
    }
}
