<?php

/**
 * Monitoring.php
 *
 * Monitoring Model for Platform Management
 * Tracks system metrics and usage statistics
 *
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 2.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class Monitoring extends BaseModel
{
    /**
     * @var string Table name for usage statistics
     */
    protected string $table = 'tenant_usage_statistics';

    /**
     * @var array Fillable fields
     */
    protected array $fillable = [
        'tenant_id',
        'date',
        'students_count',
        'staff_count',
        'campuses_count',
        'schools_count',
        'storage_used_mb',
        'bandwidth_used_mb',
        'api_calls',
        'ai_requests',
        'login_count',
        'active_users'
    ];

    /**
     * Get platform-wide statistics for today
     */
    public function getPlatformStats(): array
    {
        try {
            // Tenant statistics
            $tenantSql = "SELECT 
                            COUNT(*) as total_tenants,
                            SUM(CASE WHEN status = 'active' AND is_active = 1 THEN 1 ELSE 0 END) as active_tenants,
                            SUM(CASE WHEN status = 'suspended' OR is_active = 0 THEN 1 ELSE 0 END) as inactive_tenants,
                            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_tenants,
                            SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_tenants
                         FROM tenants WHERE deleted_at IS NULL";
            $tenants = $this->rawFetchOne($tenantSql);

            // Today's usage
            $today = date('Y-m-d');
            $usageSql = "SELECT 
                            SUM(students_count) as total_students,
                            SUM(staff_count) as total_staff,
                            SUM(campuses_count) as total_campuses,
                            SUM(schools_count) as total_schools,
                            SUM(storage_used_mb) as total_storage_mb,
                            SUM(bandwidth_used_mb) as total_bandwidth_mb,
                            SUM(api_calls) as total_api_calls,
                            SUM(ai_requests) as total_ai_requests,
                            SUM(login_count) as total_logins,
                            SUM(active_users) as total_active_users
                         FROM {$this->table} 
                         WHERE date = ?";
            $usage = $this->rawFetchOne($usageSql, [$today]);

            // Get active tenants count from usage
            $activeSql = "SELECT COUNT(DISTINCT tenant_id) as active_tenants_today 
                         FROM {$this->table} 
                         WHERE date = ? AND login_count > 0";
            $activeToday = $this->rawFetchOne($activeSql, [$today]);

            return [
                'tenants' => $tenants ?? ['total_tenants' => 0, 'active_tenants' => 0, 'inactive_tenants' => 0, 'pending_tenants' => 0, 'expired_tenants' => 0],
                'usage' => $usage ?? ['total_students' => 0, 'total_staff' => 0, 'total_campuses' => 0, 'total_schools' => 0, 'total_storage_mb' => 0, 'total_bandwidth_mb' => 0, 'total_api_calls' => 0, 'total_ai_requests' => 0, 'total_logins' => 0, 'total_active_users' => 0],
                'active_tenants_today' => $activeToday['active_tenants_today'] ?? 0
            ];
        } catch (Exception $e) {
            $this->logger->error('getPlatformStats error: ' . $e->getMessage());
            return [
                'tenants' => ['total_tenants' => 0, 'active_tenants' => 0, 'inactive_tenants' => 0, 'pending_tenants' => 0, 'expired_tenants' => 0],
                'usage' => ['total_students' => 0, 'total_staff' => 0, 'total_campuses' => 0, 'total_schools' => 0, 'total_storage_mb' => 0, 'total_bandwidth_mb' => 0, 'total_api_calls' => 0, 'total_ai_requests' => 0, 'total_logins' => 0, 'total_active_users' => 0],
                'active_tenants_today' => 0
            ];
        }
    }

    /**
     * Get tenant usage statistics
     */
    public function getTenantUsage(int $tenantId, string $dateFrom = null, string $dateTo = null): array
    {
        try {
            $params = [$tenantId];
            $where = "tenant_id = ?";

            if ($dateFrom) {
                $where .= " AND date >= ?";
                $params[] = $dateFrom;
            }
            if ($dateTo) {
                $where .= " AND date <= ?";
                $params[] = $dateTo;
            }

            $sql = "SELECT * FROM {$this->table} 
                    WHERE {$where} 
                    ORDER BY date DESC 
                    LIMIT 30";

            return $this->rawFetch($sql, $params);
        } catch (Exception $e) {
            $this->logger->error('getTenantUsage error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get daily usage trends for the last N days
     */
    public function getDailyTrends(int $days = 30): array
    {
        try {
            $sql = "SELECT 
                        date,
                        SUM(students_count) as students,
                        SUM(staff_count) as staff,
                        SUM(campuses_count) as campuses,
                        SUM(schools_count) as schools,
                        SUM(storage_used_mb) as storage_mb,
                        SUM(bandwidth_used_mb) as bandwidth_mb,
                        SUM(api_calls) as api_calls,
                        SUM(ai_requests) as ai_requests,
                        SUM(login_count) as logins,
                        SUM(active_users) as active_users,
                        COUNT(DISTINCT tenant_id) as active_tenants
                    FROM {$this->table} 
                    WHERE date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                    GROUP BY date
                    ORDER BY date ASC";

            return $this->rawFetch($sql, [$days]);
        } catch (Exception $e) {
            $this->logger->error('getDailyTrends error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get monthly growth data
     */
    public function getMonthlyGrowth(int $months = 12): array
    {
        try {
            $sql = "SELECT 
                        DATE_FORMAT(date, '%Y-%m') as month,
                        SUM(students_count) as students,
                        SUM(staff_count) as staff,
                        SUM(campuses_count) as campuses,
                        SUM(schools_count) as schools,
                        SUM(storage_used_mb) as storage_mb,
                        SUM(bandwidth_used_mb) as bandwidth_mb,
                        SUM(api_calls) as api_calls,
                        SUM(login_count) as logins,
                        COUNT(DISTINCT tenant_id) as tenants
                    FROM {$this->table} 
                    WHERE date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                    GROUP BY DATE_FORMAT(date, '%Y-%m')
                    ORDER BY month ASC";

            return $this->rawFetch($sql, [$months]);
        } catch (Exception $e) {
            $this->logger->error('getMonthlyGrowth error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get top tenants by usage
     */
    public function getTopTenants(string $metric = 'students', int $limit = 10): array
    {
        try {
            $validMetrics = ['students', 'staff', 'storage_used_mb', 'bandwidth_used_mb', 'api_calls', 'login_count'];
            if (!in_array($metric, $validMetrics)) {
                $metric = 'students';
            }

            $sql = "SELECT 
                        t.id,
                        t.tenant_name,
                        t.tenant_code,
                        SUM(u.{$metric}) as total_usage
                    FROM {$this->table} u
                    JOIN tenants t ON u.tenant_id = t.id
                    WHERE u.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    AND t.deleted_at IS NULL
                    GROUP BY t.id
                    ORDER BY total_usage DESC
                    LIMIT ?";

            return $this->rawFetch($sql, [$limit]);
        } catch (Exception $e) {
            $this->logger->error('getTopTenants error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get system health metrics
     */
    public function getSystemHealth(): array
    {
        try {
            // Database connection test
            $dbHealth = $this->db->ping() ? 'healthy' : 'unhealthy';

            // Check last backup
            $backupSql = "SELECT MAX(created_at) as last_backup FROM platform_audit_logs 
                         WHERE action_type = 'BACKUP_COMPLETED'";
            $backup = $this->rawFetchOne($backupSql);
            $lastBackup = $backup['last_backup'] ?? null;

            // Check for recent errors
            $errorSql = "SELECT COUNT(*) as errors FROM platform_audit_logs 
                        WHERE action_type LIKE '%ERROR%' 
                        AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
            $errors = $this->rawFetchOne($errorSql);

            // Check queue (if any)
            // This would check for pending jobs in a real implementation

            return [
                'database' => $dbHealth,
                'last_backup' => $lastBackup,
                'recent_errors' => $errors['errors'] ?? 0,
                'status' => $dbHealth === 'healthy' ? 'operational' : 'degraded'
            ];
        } catch (Exception $e) {
            $this->logger->error('getSystemHealth error: ' . $e->getMessage());
            return [
                'database' => 'unhealthy',
                'last_backup' => null,
                'recent_errors' => 1,
                'status' => 'degraded'
            ];
        }
    }

    /**
     * Get security alerts
     */
    public function getSecurityAlerts(int $limit = 20): array
    {
        try {
            $sql = "SELECT 
                        id,
                        action_type,
                        module,
                        resource,
                        resource_id,
                        ip_address,
                        created_at,
                        user_id,
                        (SELECT username FROM platform_users WHERE id = user_id) as username
                    FROM platform_audit_logs 
                    WHERE action_type IN ('LOGIN_FAILED', 'LOGIN_LOCKOUT', 'UNAUTHORIZED_ACCESS', 'SUSPICIOUS_ACTIVITY', 'SECURITY_ALERT')
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    ORDER BY created_at DESC
                    LIMIT ?";

            return $this->rawFetch($sql, [$limit]);
        } catch (Exception $e) {
            $this->logger->error('getSecurityAlerts error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get revenue overview
     */
    public function getRevenueOverview(): array
    {
        try {
            // Get subscription revenue - using tenant_subscriptions and subscription_plans
            $sql = "SELECT 
                        SUM(sp.price) as total_revenue,
                        COUNT(DISTINCT ts.tenant_id) as paying_tenants,
                        AVG(sp.price) as average_revenue
                    FROM tenant_subscriptions ts
                    JOIN subscription_plans sp ON ts.plan_id = sp.id
                    WHERE ts.status = 'active' 
                    AND ts.deleted_at IS NULL
                    AND sp.price > 0";

            $revenue = $this->rawFetchOne($sql);

            // Get free trial tenants
            $trialSql = "SELECT COUNT(DISTINCT tenant_id) as trial_tenants 
                        FROM tenant_subscriptions 
                        WHERE status = 'trial' 
                        AND deleted_at IS NULL";
            $trial = $this->rawFetchOne($trialSql);

            return [
                'total_revenue' => $revenue['total_revenue'] ?? 0,
                'paying_tenants' => $revenue['paying_tenants'] ?? 0,
                'average_revenue' => $revenue['average_revenue'] ?? 0,
                'trial_tenants' => $trial['trial_tenants'] ?? 0
            ];
        } catch (Exception $e) {
            $this->logger->error('getRevenueOverview error: ' . $e->getMessage());
            return [
                'total_revenue' => 0,
                'paying_tenants' => 0,
                'average_revenue' => 0,
                'trial_tenants' => 0
            ];
        }
    }

    /**
     * Get monthly revenue trend
     */
    public function getMonthlyRevenue(int $months = 12): array
    {
        try {
            $sql = "SELECT 
                        DATE_FORMAT(ts.created_at, '%Y-%m') as month,
                        SUM(sp.price) as revenue,
                        COUNT(DISTINCT ts.tenant_id) as new_subscriptions
                    FROM tenant_subscriptions ts
                    JOIN subscription_plans sp ON ts.plan_id = sp.id
                    WHERE ts.created_at >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                    AND ts.deleted_at IS NULL
                    AND sp.price > 0
                    GROUP BY DATE_FORMAT(ts.created_at, '%Y-%m')
                    ORDER BY month ASC";

            return $this->rawFetch($sql, [$months]);
        } catch (Exception $e) {
            $this->logger->error('getMonthlyRevenue error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Record daily usage statistics for all tenants
     */
    public function recordDailyUsage(): int
    {
        try {
            $today = date('Y-m-d');

            // Get all tenants
            $tenants = $this->rawFetch("SELECT id FROM tenants WHERE deleted_at IS NULL AND is_active = 1");
            $recorded = 0;

            foreach ($tenants as $tenant) {
                $tenantId = $tenant['id'];

                // Check if already recorded for today
                $exists = $this->rawFetchOne(
                    "SELECT COUNT(*) as count FROM {$this->table} WHERE tenant_id = ? AND date = ?",
                    [$tenantId, $today]
                );

                if ($exists['count'] > 0) {
                    continue;
                }

                // Get counts for this tenant
                $counts = $this->getTenantCounts($tenantId);

                // Insert record
                $result = $this->create([
                    'tenant_id' => $tenantId,
                    'date' => $today,
                    'students_count' => $counts['students'] ?? 0,
                    'staff_count' => $counts['staff'] ?? 0,
                    'campuses_count' => $counts['campuses'] ?? 0,
                    'schools_count' => $counts['schools'] ?? 0,
                    'storage_used_mb' => $counts['storage_mb'] ?? 0,
                    'bandwidth_used_mb' => $counts['bandwidth_mb'] ?? 0,
                    'api_calls' => $counts['api_calls'] ?? 0,
                    'ai_requests' => $counts['ai_requests'] ?? 0,
                    'login_count' => $counts['logins'] ?? 0,
                    'active_users' => $counts['active_users'] ?? 0
                ]);

                if ($result) {
                    $recorded++;
                }
            }

            return $recorded;
        } catch (Exception $e) {
            $this->logger->error('recordDailyUsage error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get counts for a tenant (helper method)
     */
    private function getTenantCounts(int $tenantId): array
    {
        try {
            $counts = [];

            // Students count
            $result = $this->rawFetchOne(
                "SELECT COUNT(*) as count FROM students WHERE tenant_id = ? AND is_active = 1",
                [$tenantId]
            );
            $counts['students'] = $result['count'] ?? 0;

            // Staff count
            $result = $this->rawFetchOne(
                "SELECT COUNT(*) as count FROM staff WHERE tenant_id = ? AND is_active = 1",
                [$tenantId]
            );
            $counts['staff'] = $result['count'] ?? 0;

            // Campuses count
            $result = $this->rawFetchOne(
                "SELECT COUNT(*) as count FROM campuses WHERE tenant_id = ? AND is_active = 1",
                [$tenantId]
            );
            $counts['campuses'] = $result['count'] ?? 0;

            // Schools count
            $result = $this->rawFetchOne(
                "SELECT COUNT(*) as count FROM schools WHERE tenant_id = ? AND is_active = 1",
                [$tenantId]
            );
            $counts['schools'] = $result['count'] ?? 0;

            // Storage usage - from tenant_storage_usage
            $result = $this->rawFetchOne(
                "SELECT SUM(total_size_mb) as total FROM tenant_storage_usage WHERE tenant_id = ?",
                [$tenantId]
            );
            $counts['storage_mb'] = $result['total'] ?? 0;

            // Bandwidth usage - from tenant_usage_statistics
            $result = $this->rawFetchOne(
                "SELECT SUM(bandwidth_used_mb) as total FROM {$this->table} WHERE tenant_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
                [$tenantId]
            );
            $counts['bandwidth_mb'] = $result['total'] ?? 0;

            // API calls - from audit logs
            $result = $this->rawFetchOne(
                "SELECT COUNT(*) as count FROM platform_audit_logs WHERE tenant_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                [$tenantId]
            );
            $counts['api_calls'] = $result['count'] ?? 0;

            // AI requests
            $counts['ai_requests'] = 0; // Would be tracked separately

            // Logins - from platform_sessions
            $result = $this->rawFetchOne(
                "SELECT COUNT(DISTINCT user_id) as count FROM platform_sessions 
                 WHERE tenant_id = ? AND login_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                [$tenantId]
            );
            $counts['logins'] = $result['count'] ?? 0;

            // Active users - users with recent activity
            $result = $this->rawFetchOne(
                "SELECT COUNT(DISTINCT user_id) as count FROM platform_audit_logs 
                 WHERE tenant_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
                [$tenantId]
            );
            $counts['active_users'] = $result['count'] ?? 0;

            return $counts;
        } catch (Exception $e) {
            $this->logger->error('getTenantCounts error: ' . $e->getMessage());
            return [
                'students' => 0,
                'staff' => 0,
                'campuses' => 0,
                'schools' => 0,
                'storage_mb' => 0,
                'bandwidth_mb' => 0,
                'api_calls' => 0,
                'ai_requests' => 0,
                'logins' => 0,
                'active_users' => 0
            ];
        }
    }

    /**
     * Get server metrics (CPU, Memory, Disk)
     */
    public function getServerMetrics(): array
    {
        try {
            // CPU Usage
            $cpu = $this->getCpuUsage();

            // Memory Usage
            $memory = $this->getMemoryUsage();

            // Disk Usage
            $disk = $this->getDiskUsage();

            // Uptime
            $uptime = $this->getUptime();

            return [
                'cpu' => $cpu,
                'memory' => $memory,
                'disk' => $disk,
                'uptime' => $uptime,
                'timestamp' => date('Y-m-d H:i:s')
            ];
        } catch (Exception $e) {
            $this->logger->error('getServerMetrics error: ' . $e->getMessage());
            return [
                'cpu' => ['usage' => 0, 'cores' => 1],
                'memory' => ['used' => 0, 'total' => 0, 'free' => 0, 'usage_percent' => 0],
                'disk' => ['used' => 0, 'total' => 0, 'free' => 0, 'usage_percent' => 0],
                'uptime' => 0,
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }

    /**
     * Get CPU usage
     */
    private function getCpuUsage(): array
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $cores = $this->getCpuCores();
            return [
                'usage' => min(100, round(($load[0] / $cores) * 100, 1)),
                'cores' => $cores,
                'load_1min' => $load[0] ?? 0,
                'load_5min' => $load[1] ?? 0,
                'load_15min' => $load[2] ?? 0
            ];
        }
        return ['usage' => 0, 'cores' => 1, 'load_1min' => 0, 'load_5min' => 0, 'load_15min' => 0];
    }

    /**
     * Get number of CPU cores
     */
    private function getCpuCores(): int
    {
        if (function_exists('shell_exec')) {
            $cores = shell_exec('nproc 2>/dev/null');
            if ($cores !== null && is_numeric(trim($cores))) {
                return (int)trim($cores);
            }
        }
        return 1;
    }

    /**
     * Get memory usage
     */
    private function getMemoryUsage(): array
    {
        if (function_exists('shell_exec')) {
            $output = shell_exec('free -m 2>/dev/null');
            if ($output) {
                $lines = explode("\n", $output);
                if (isset($lines[1])) {
                    $parts = preg_split('/\s+/', $lines[1]);
                    if (count($parts) >= 4) {
                        $total = (int)$parts[1];
                        $used = (int)$parts[2];
                        $free = (int)$parts[3];
                        return [
                            'total' => $total,
                            'used' => $used,
                            'free' => $free,
                            'usage_percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0
                        ];
                    }
                }
            }
        }
        return ['total' => 0, 'used' => 0, 'free' => 0, 'usage_percent' => 0];
    }

    /**
     * Get disk usage
     */
    private function getDiskUsage(): array
    {
        $path = dirname(__DIR__, 3);
        $total = disk_total_space($path) ?? 0;
        $free = disk_free_space($path) ?? 0;
        $used = $total - $free;

        return [
            'total' => round($total / (1024 * 1024 * 1024), 2), // GB
            'used' => round($used / (1024 * 1024 * 1024), 2), // GB
            'free' => round($free / (1024 * 1024 * 1024), 2), // GB
            'usage_percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0
        ];
    }

    /**
     * Get system uptime
     */
    private function getUptime(): int
    {
        if (function_exists('shell_exec')) {
            $uptime = shell_exec('cat /proc/uptime 2>/dev/null');
            if ($uptime) {
                $parts = explode(' ', trim($uptime));
                if (isset($parts[0])) {
                    return (int)floor((float)$parts[0]);
                }
            }
        }
        return 0;
    }
}
