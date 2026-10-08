<?php

/**
 * AuditService.php
 *
 * Audit Service for Platform Management
 *
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 2.0
 */

$projectRoot = dirname(__DIR__, 3) . '/';

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/models/Platform/PlatformAuditLog.php';
require_once $projectRoot . 'app/models/Platform/PlatformUser.php';
require_once $projectRoot . 'app/models/Platform/Tenant.php';

class AuditService
{
    private $db;
    private $logger;
    private $auditModel;
    private $userModel;
    private $tenantModel;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->auditModel = new PlatformAuditLog();
        $this->userModel = new PlatformUser();
        $this->tenantModel = new Tenant();
    }

    /**
     * Log an action
     */
    public function log(array $data): array
    {
        try {
            // Get user agent details if not provided
            if (empty($data['user_agent']) && isset($_SERVER['HTTP_USER_AGENT'])) {
                $data['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
                $this->parseUserAgent($data);
            }

            // Get IP address if not provided
            if (empty($data['ip_address'])) {
                $data['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? null;
            }

            $logId = $this->auditModel->log($data);

            if ($logId) {
                return [
                    'success' => true,
                    'message' => 'Audit log created successfully',
                    'data' => ['log_id' => $logId]
                ];
            }

            return ['success' => false, 'message' => 'Failed to create audit log'];
        } catch (Exception $e) {
            $this->logger->error('log error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get audit logs with filters
     */
    public function getLogs(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        return $this->auditModel->getLogs($filters, $page, $perPage);
    }

    /**
     * Get audit log by ID
     */
    public function getLog(int $logId): array
    {
        $log = $this->auditModel->getLog($logId);
        if ($log) {
            return ['success' => true, 'data' => $log];
        }
        return ['success' => false, 'message' => 'Log not found'];
    }

    /**
     * Get action types
     */
    public function getActionTypes(): array
    {
        return ['success' => true, 'data' => $this->auditModel->getActionTypes()];
    }

    /**
     * Get modules
     */
    public function getModules(): array
    {
        return ['success' => true, 'data' => $this->auditModel->getModules()];
    }

    /**
     * Get audit statistics
     */
    public function getStats(array $filters = []): array
    {
        return $this->auditModel->getStats($filters);
    }

    /**
     * Export audit logs
     */
    public function export(array $filters = [], string $format = 'csv'): array
    {
        return $this->auditModel->export($filters, $format);
    }

    /**
     * Log tenant action
     */
    public function logTenantAction(int $tenantId, int $userId, string $action, array $oldData = null, array $newData = null): array
    {
        $tenant = $this->tenantModel->find($tenantId);
        return $this->log([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'action_type' => $action,
            'module' => 'Tenant',
            'resource' => 'tenant',
            'resource_id' => $tenantId,
            'old_data' => $oldData,
            'new_data' => $newData
        ]);
    }

    /**
     * Log user action
     */
    public function logUserAction(int $userId, int $actorId, string $action, array $oldData = null, array $newData = null): array
    {
        return $this->log([
            'user_id' => $actorId,
            'action_type' => $action,
            'module' => 'User',
            'resource' => 'user',
            'resource_id' => $userId,
            'old_data' => $oldData,
            'new_data' => $newData
        ]);
    }

    /**
     * Log school action
     */
    public function logSchoolAction(int $schoolId, int $userId, string $action, array $oldData = null, array $newData = null): array
    {
        return $this->log([
            'user_id' => $userId,
            'action_type' => $action,
            'module' => 'School',
            'resource' => 'school',
            'resource_id' => $schoolId,
            'old_data' => $oldData,
            'new_data' => $newData
        ]);
    }

    /**
     * Log campus action
     */
    public function logCampusAction(int $campusId, int $userId, string $action, array $oldData = null, array $newData = null): array
    {
        return $this->log([
            'user_id' => $userId,
            'action_type' => $action,
            'module' => 'Campus',
            'resource' => 'campus',
            'resource_id' => $campusId,
            'old_data' => $oldData,
            'new_data' => $newData
        ]);
    }

    /**
     * Log subscription action
     */
    public function logSubscriptionAction(int $subscriptionId, int $userId, string $action, array $oldData = null, array $newData = null): array
    {
        return $this->log([
            'user_id' => $userId,
            'action_type' => $action,
            'module' => 'Subscription',
            'resource' => 'subscription',
            'resource_id' => $subscriptionId,
            'old_data' => $oldData,
            'new_data' => $newData
        ]);
    }

    /**
     * Parse user agent and extract browser, OS, device
     */
    private function parseUserAgent(array &$data): void
    {
        if (empty($data['user_agent'])) {
            return;
        }

        $ua = $data['user_agent'];

        // Browser detection
        if (strpos($ua, 'Firefox') !== false) {
            $data['browser'] = 'Firefox';
        } elseif (strpos($ua, 'Chrome') !== false && strpos($ua, 'Edg') === false) {
            $data['browser'] = 'Chrome';
        } elseif (strpos($ua, 'Safari') !== false && strpos($ua, 'Chrome') === false) {
            $data['browser'] = 'Safari';
        } elseif (strpos($ua, 'Edg') !== false) {
            $data['browser'] = 'Edge';
        } elseif (strpos($ua, 'MSIE') !== false || strpos($ua, 'Trident') !== false) {
            $data['browser'] = 'Internet Explorer';
        } elseif (strpos($ua, 'Opera') !== false || strpos($ua, 'OPR') !== false) {
            $data['browser'] = 'Opera';
        } else {
            $data['browser'] = 'Unknown';
        }

        // OS detection
        if (strpos($ua, 'Windows') !== false) {
            $data['os'] = 'Windows';
        } elseif (strpos($ua, 'Mac OS X') !== false || strpos($ua, 'Macintosh') !== false) {
            $data['os'] = 'macOS';
        } elseif (strpos($ua, 'Linux') !== false && strpos($ua, 'Android') === false) {
            $data['os'] = 'Linux';
        } elseif (strpos($ua, 'Android') !== false) {
            $data['os'] = 'Android';
        } elseif (strpos($ua, 'iPhone') !== false || strpos($ua, 'iPad') !== false) {
            $data['os'] = 'iOS';
        } elseif (strpos($ua, 'Ubuntu') !== false) {
            $data['os'] = 'Ubuntu';
        } else {
            $data['os'] = 'Unknown';
        }

        // Device detection
        if (strpos($ua, 'Mobile') !== false || strpos($ua, 'Android') !== false || strpos($ua, 'iPhone') !== false) {
            $data['device'] = 'Mobile';
        } elseif (strpos($ua, 'iPad') !== false || strpos($ua, 'Tablet') !== false) {
            $data['device'] = 'Tablet';
        } else {
            $data['device'] = 'Desktop';
        }
    }

    /**
     * Clean old audit logs
     */
    public function cleanOldLogs(int $days = 90): array
    {
        try {
            $deleted = $this->auditModel->cleanOldLogs($days);
            return [
                'success' => true,
                'message' => "Cleaned {$deleted} audit logs older than {$days} days",
                'data' => ['deleted' => $deleted]
            ];
        } catch (Exception $e) {
            $this->logger->error('cleanOldLogs error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
