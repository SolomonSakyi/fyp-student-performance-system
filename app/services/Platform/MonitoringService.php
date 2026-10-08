<?php

/**
 * MonitoringService.php
 *
 * Monitoring Service for Platform Management
 *
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 2.0
 */

$projectRoot = dirname(__DIR__, 3) . '/';

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/models/Platform/Monitoring.php';
require_once $projectRoot . 'app/models/Platform/Tenant.php';
require_once $projectRoot . 'app/models/Platform/PlatformAuditLog.php';

class MonitoringService
{
    private $db;
    private $logger;
    private $monitoringModel;
    private $tenantModel;
    private $auditModel;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->monitoringModel = new Monitoring();
        $this->tenantModel = new Tenant();
        $this->auditModel = new PlatformAuditLog();
    }

    /**
     * Get platform dashboard data
     */
    public function getDashboardData(): array
    {
        try {
            $stats = $this->monitoringModel->getPlatformStats();
            $trends = $this->monitoringModel->getDailyTrends(30);
            $health = $this->monitoringModel->getSystemHealth();
            $server = $this->monitoringModel->getServerMetrics();
            $revenue = $this->monitoringModel->getRevenueOverview();
            $alerts = $this->monitoringModel->getSecurityAlerts(10);
            $topTenants = $this->monitoringModel->getTopTenants('students', 5);

            return [
                'success' => true,
                'data' => [
                    'stats' => $stats,
                    'trends' => $trends,
                    'health' => $health,
                    'server' => $server,
                    'revenue' => $revenue,
                    'alerts' => $alerts,
                    'top_tenants' => $topTenants,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('getDashboardData error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get platform statistics
     */
    public function getPlatformStats(): array
    {
        return $this->monitoringModel->getPlatformStats();
    }

    /**
     * Get daily trends
     */
    public function getDailyTrends(int $days = 30): array
    {
        return $this->monitoringModel->getDailyTrends($days);
    }

    /**
     * Get monthly growth
     */
    public function getMonthlyGrowth(int $months = 12): array
    {
        return $this->monitoringModel->getMonthlyGrowth($months);
    }

    /**
     * Get tenant usage
     */
    public function getTenantUsage(int $tenantId, string $dateFrom = null, string $dateTo = null): array
    {
        $usage = $this->monitoringModel->getTenantUsage($tenantId, $dateFrom, $dateTo);
        return ['success' => true, 'data' => $usage];
    }

    /**
     * Get top tenants by usage
     */
    public function getTopTenants(string $metric = 'students', int $limit = 10): array
    {
        return $this->monitoringModel->getTopTenants($metric, $limit);
    }

    /**
     * Get system health
     */
    public function getSystemHealth(): array
    {
        return $this->monitoringModel->getSystemHealth();
    }

    /**
     * Get security alerts
     */
    public function getSecurityAlerts(int $limit = 20): array
    {
        return $this->monitoringModel->getSecurityAlerts($limit);
    }

    /**
     * Get revenue overview
     */
    public function getRevenueOverview(): array
    {
        return $this->monitoringModel->getRevenueOverview();
    }

    /**
     * Get monthly revenue
     */
    public function getMonthlyRevenue(int $months = 12): array
    {
        return $this->monitoringModel->getMonthlyRevenue($months);
    }

    /**
     * Get server metrics
     */
    public function getServerMetrics(): array
    {
        return $this->monitoringModel->getServerMetrics();
    }

    /**
     * Record daily usage (cron job)
     */
    public function recordDailyUsage(): array
    {
        try {
            $recorded = $this->monitoringModel->recordDailyUsage();

            // Log audit
            $this->auditModel->log([
                'user_id' => null,
                'action_type' => 'USAGE_RECORDED',
                'module' => 'Monitoring',
                'resource' => 'usage',
                'resource_id' => 0,
                'new_data' => json_encode(['tenants_recorded' => $recorded]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            return [
                'success' => true,
                'message' => "Recorded daily usage for {$recorded} tenants",
                'data' => ['recorded' => $recorded]
            ];
        } catch (Exception $e) {
            $this->logger->error('recordDailyUsage error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get real-time monitoring data (for dashboard refresh)
     */
    public function getRealtimeData(): array
    {
        try {
            // Get current metrics
            $server = $this->monitoringModel->getServerMetrics();

            // Get recent activity (last 5 minutes)
            $activitySql = "SELECT 
                                COUNT(*) as total_actions,
                                COUNT(DISTINCT user_id) as active_users,
                                COUNT(DISTINCT tenant_id) as active_tenants
                            FROM platform_audit_logs 
                            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
            $activity = $this->db->fetchOne($activitySql);

            // Get recent logins
            $logins = $this->db->fetchOne(
                "SELECT COUNT(*) as logins FROM platform_audit_logs 
                 WHERE action_type = 'LOGIN_SUCCESS' 
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
            );

            return [
                'success' => true,
                'data' => [
                    'server' => $server,
                    'activity' => $activity ?? ['total_actions' => 0, 'active_users' => 0, 'active_tenants' => 0],
                    'recent_logins' => $logins['logins'] ?? 0,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('getRealtimeData error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get tenant-specific monitoring
     */
    public function getTenantMonitoring(int $tenantId): array
    {
        try {
            // Get tenant info
            $tenant = $this->tenantModel->find($tenantId);
            if (!$tenant) {
                return ['success' => false, 'message' => 'Tenant not found'];
            }

            // Get usage for last 30 days
            $usage = $this->monitoringModel->getTenantUsage($tenantId, date('Y-m-d', strtotime('-30 days')));

            // Get recent activity
            $activity = $this->db->fetchAll(
                "SELECT * FROM platform_audit_logs 
                 WHERE tenant_id = ? 
                 ORDER BY created_at DESC 
                 LIMIT 20",
                [$tenantId]
            );

            // Get counts
            $counts = [
                'students' => $this->db->fetchOne("SELECT COUNT(*) as count FROM students WHERE tenant_id = ? AND is_active = 1", [$tenantId])['count'] ?? 0,
                'staff' => $this->db->fetchOne("SELECT COUNT(*) as count FROM staff WHERE tenant_id = ? AND is_active = 1", [$tenantId])['count'] ?? 0,
                'schools' => $this->db->fetchOne("SELECT COUNT(*) as count FROM schools WHERE tenant_id = ? AND is_active = 1", [$tenantId])['count'] ?? 0,
                'campuses' => $this->db->fetchOne("SELECT COUNT(*) as count FROM campuses WHERE tenant_id = ? AND is_active = 1", [$tenantId])['count'] ?? 0
            ];

            // Get subscription info
            $subscription = $this->db->fetchOne(
                "SELECT ts.*, sp.plan_name, sp.price 
                 FROM tenant_subscriptions ts 
                 JOIN subscription_plans sp ON ts.plan_id = sp.id 
                 WHERE ts.tenant_id = ? AND ts.deleted_at IS NULL 
                 ORDER BY ts.created_at DESC LIMIT 1",
                [$tenantId]
            );

            return [
                'success' => true,
                'data' => [
                    'tenant' => $tenant,
                    'usage' => $usage,
                    'activity' => $activity,
                    'counts' => $counts,
                    'subscription' => $subscription
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('getTenantMonitoring error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
