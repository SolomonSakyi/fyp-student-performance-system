<?php

/**
 * Subscription Service - Handles subscription limits and enforcement
 * 
 * @package EduTrack
 * @subpackage Services
 * @filepath app/services/SubscriptionService.php
 * @version 2.0
 */

class SubscriptionService
{
    private $db;
    private $tenantId;
    private $subscription;
    private $plan;

    public function __construct($tenantId = null)
    {
        $this->db = DatabaseHelper::getInstance();
        if ($tenantId) {
            $this->tenantId = $tenantId;
            $this->loadSubscription();
        }
    }

    /**
     * Load the tenant's current subscription
     */
    private function loadSubscription()
    {
        // Get active subscription
        $this->subscription = $this->db->fetchOne(
            "SELECT ts.*, sp.* 
             FROM tenant_subscriptions ts
             JOIN subscription_plans sp ON ts.plan_id = sp.id
             WHERE ts.tenant_id = ? 
             AND ts.status IN ('active', 'trial')
             AND (ts.deleted_at IS NULL OR ts.deleted_at = '')
             ORDER BY ts.created_at DESC 
             LIMIT 1",
            [$this->tenantId]
        );

        if (!$this->subscription) {
            // No active subscription - assign free plan
            $this->assignFreePlan();
            $this->loadSubscription();
        }

        $this->plan = $this->subscription;
    }

    /**
     * Assign free plan to tenant
     */
    private function assignFreePlan()
    {
        $plan = $this->db->fetchOne(
            "SELECT id FROM subscription_plans WHERE plan_code = 'FREE' AND (deleted_at IS NULL OR deleted_at = '')"
        );

        if (!$plan) {
            // Insert free plan if it doesn't exist
            $this->db->execute(
                "
                INSERT INTO subscription_plans (
                    plan_name, plan_code, description, price, currency, billing_cycle,
                    max_schools, max_campuses, max_staff, max_students,
                    max_storage_mb, max_api_calls, max_ai_requests,
                    sms_balance, email_balance,
                    is_active, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())",
                [
                    'Free',
                    'FREE',
                    'Free plan for small schools',
                    0,
                    'GHS',
                    'monthly',
                    1,
                    1,
                    10,
                    50,
                    100,
                    1000,
                    10,
                    0,
                    100
                ]
            );
            $plan = $this->db->fetchOne(
                "SELECT id FROM subscription_plans WHERE plan_code = 'FREE' AND (deleted_at IS NULL OR deleted_at = '')"
            );
        }

        if ($plan) {
            $this->db->execute(
                "INSERT INTO tenant_subscriptions (
                    tenant_id, plan_id, start_date, status, created_at, updated_at
                ) VALUES (?, ?, CURDATE(), 'active', NOW(), NOW())",
                [$this->tenantId, $plan['id']]
            );
        }
    }

    /**
     * Check if tenant can create a resource
     * 
     * @param string $resourceType 'schools', 'campuses', 'staff', 'students'
     * @param int $count The count to check against (default 1)
     * @return array ['allowed' => bool, 'message' => string, 'current' => int, 'max' => int]
     */
    public function canCreate($resourceType, $count = 1)
    {
        $current = $this->getCurrentUsage($resourceType);
        $max = $this->getMaxLimit($resourceType);

        // 0 means unlimited
        if ($max == 0) {
            return [
                'allowed' => true,
                'message' => 'Unlimited',
                'current' => $current,
                'max' => $max,
                'remaining' => -1
            ];
        }

        $remaining = $max - $current;
        $allowed = ($remaining >= $count);

        return [
            'allowed' => $allowed,
            'message' => $allowed ? 'Within limit' : "Exceeds limit. Max: $max, Current: $current",
            'current' => $current,
            'max' => $max,
            'remaining' => $remaining
        ];
    }

    /**
     * Get current usage for a resource type
     */
    public function getCurrentUsage($resourceType)
    {
        switch ($resourceType) {
            case 'schools':
                return $this->db->getValue(
                    "SELECT COUNT(*) FROM schools WHERE tenant_id = ? AND deleted_at IS NULL",
                    [$this->tenantId]
                ) ?? 0;
            case 'campuses':
                return $this->db->getValue(
                    "SELECT COUNT(*) FROM campuses c 
                     JOIN schools s ON c.school_id = s.id 
                     WHERE s.tenant_id = ? AND c.deleted_at IS NULL",
                    [$this->tenantId]
                ) ?? 0;
            case 'staff':
                return $this->db->getValue(
                    "SELECT COUNT(*) FROM staff WHERE tenant_id = ? AND deleted_at IS NULL",
                    [$this->tenantId]
                ) ?? 0;
            case 'students':
                return $this->db->getValue(
                    "SELECT COUNT(*) FROM students WHERE tenant_id = ? AND deleted_at IS NULL",
                    [$this->tenantId]
                ) ?? 0;
            case 'storage_mb':
                // Calculate from actual storage usage
                return $this->db->getValue(
                    "SELECT COALESCE(SUM(file_size_mb), 0) FROM storage_files WHERE tenant_id = ? AND deleted_at IS NULL",
                    [$this->tenantId]
                ) ?? 0;
            default:
                return 0;
        }
    }

    /**
     * Get max limit for a resource type from the plan
     */
    public function getMaxLimit($resourceType)
    {
        if (!$this->plan) {
            return 0;
        }

        $map = [
            'schools' => 'max_schools',
            'campuses' => 'max_campuses',
            'staff' => 'max_staff',
            'students' => 'max_students',
            'storage_mb' => 'max_storage_mb',
            'api_calls' => 'max_api_calls',
            'ai_requests' => 'max_ai_requests'
        ];

        $field = $map[$resourceType] ?? null;
        if (!$field) {
            return 0;
        }

        return (int)($this->plan[$field] ?? 0);
    }

    /**
     * Increment usage after resource creation
     */
    public function incrementUsage($resourceType)
    {
        $map = [
            'schools' => 'schools_used',
            'campuses' => 'campuses_used',
            'staff' => 'staff_used',
            'students' => 'students_used'
        ];

        $field = $map[$resourceType] ?? null;
        if ($field) {
            $this->db->execute(
                "UPDATE tenant_subscriptions SET $field = $field + 1, updated_at = NOW() 
                 WHERE tenant_id = ? AND status IN ('active', 'trial') AND (deleted_at IS NULL OR deleted_at = '')",
                [$this->tenantId]
            );
        }

        // Log the action
        $this->logUsage($resourceType, 'increment');
    }

    /**
     * Decrement usage after resource deletion
     */
    public function decrementUsage($resourceType)
    {
        $map = [
            'schools' => 'schools_used',
            'campuses' => 'campuses_used',
            'staff' => 'staff_used',
            'students' => 'students_used'
        ];

        $field = $map[$resourceType] ?? null;
        if ($field) {
            $this->db->execute(
                "UPDATE tenant_subscriptions SET $field = GREATEST($field - 1, 0), updated_at = NOW() 
                 WHERE tenant_id = ? AND status IN ('active', 'trial') AND (deleted_at IS NULL OR deleted_at = '')",
                [$this->tenantId]
            );
        }

        $this->logUsage($resourceType, 'decrement');
    }

    /**
     * Log usage for auditing
     */
    private function logUsage($resourceType, $action)
    {
        $this->db->execute(
            "INSERT INTO subscription_usage_logs (tenant_id, action_type, created_at) 
             VALUES (?, ?, NOW())",
            [$this->tenantId, $resourceType . '_' . $action]
        );
    }

    /**
     * Check if tenant has reached warning threshold (80%)
     */
    public function isNearLimit($resourceType)
    {
        $result = $this->canCreate($resourceType, 1);
        if ($result['max'] == 0) {
            return false;
        }
        if ($result['remaining'] <= 0) {
            return false;
        }
        $percentageUsed = (($result['current'] / $result['max']) * 100);
        return $percentageUsed >= 80;
    }

    /**
     * Get subscription status for display
     */
    public function getSubscriptionStatus()
    {
        if (!$this->subscription) {
            return [
                'status' => 'none',
                'plan_name' => 'No Plan',
                'expires' => null
            ];
        }

        return [
            'status' => $this->subscription['status'],
            'plan_name' => $this->subscription['plan_name'],
            'plan_code' => $this->subscription['plan_code'],
            'expires' => $this->subscription['end_date'],
            'start_date' => $this->subscription['start_date']
        ];
    }

    /**
     * Get all usage stats for a tenant
     */
    public function getUsageStats()
    {
        $stats = [];
        $resourceTypes = ['schools', 'campuses', 'staff', 'students'];

        foreach ($resourceTypes as $type) {
            $current = $this->getCurrentUsage($type);
            $max = $this->getMaxLimit($type);
            $stats[$type] = [
                'current' => $current,
                'max' => $max,
                'percentage' => $max > 0 ? round(($current / $max) * 100, 1) : 0,
                'remaining' => $max > 0 ? $max - $current : -1
            ];
        }

        return $stats;
    }

    /**
     * Get all tenants and their subscription status (for Super Admin)
     */
    public static function getAllTenantSubscriptions()
    {
        $db = DatabaseHelper::getInstance();
        return $db->fetchAll("
            SELECT 
                t.id as tenant_id,
                t.tenant_name,
                t.tenant_code,
                ts.plan_id,
                sp.plan_name,
                sp.plan_code,
                ts.status as subscription_status,
                ts.start_date,
                ts.end_date,
                ts.schools_used,
                ts.campuses_used,
                ts.staff_used,
                ts.students_used,
                sp.max_schools,
                sp.max_campuses,
                sp.max_staff,
                sp.max_students
            FROM tenants t
            LEFT JOIN tenant_subscriptions ts ON t.id = ts.tenant_id AND (ts.deleted_at IS NULL OR ts.deleted_at = '')
            LEFT JOIN subscription_plans sp ON ts.plan_id = sp.id
            WHERE t.deleted_at IS NULL
            ORDER BY t.tenant_name ASC
        ");
    }
}
