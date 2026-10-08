<?php

/**
 * AuditLogController.php
 * Controller for managing audit logs
 * 
 * @package EduTrack
 * @subpackage Controllers\Platform
 * @version 2.0
 * @filepath app/controllers/Platform/AuditLogController.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/helpers/ResponseHelper.php';
require_once $projectRoot . 'app/services/Tenant/TenantContext.php';

class AuditLogController
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

    private function isPlatformAdmin(): bool
    {
        return $this->context->isPlatformAdmin();
    }

    /**
     * Log an audit entry
     */
    public static function log(
        string $action,
        string $module,
        string $resource,
        ?string $resourceId = null,
        ?string $description = null,
        ?array $details = null
    ): bool {
        try {
            $db = DatabaseHelper::getInstance();
            $context = TenantContext::getInstance();

            $sql = "INSERT INTO audit_logs 
                    (tenant_id, user_id, username, email, action_type, module, 
                     resource, resource_id, description, details, ip_address, user_agent) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $tenantId = $context->getTenantId();
            $userId = $context->getUserId();

            // Get user info if available
            $username = null;
            $email = null;
            if ($userId) {
                $user = $db->fetchOne(
                    "SELECT username, email FROM platform_users WHERE id = ?",
                    [$userId]
                );
                if ($user) {
                    $username = $user['username'] ?? null;
                    $email = $user['email'] ?? null;
                }
            }

            return $db->execute($sql, [
                $tenantId,
                $userId,
                $username,
                $email,
                $action,
                $module,
                $resource,
                $resourceId,
                $description,
                $details ? json_encode($details) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            error_log('Audit log error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get audit logs with pagination and filters
     * GET /api/platform/index.php?endpoint=audit-logs
     */
    public function getLogs(): void
    {
        try {
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $action = isset($_GET['action']) ? trim($_GET['action']) : '';
            $module = isset($_GET['module']) ? trim($_GET['module']) : '';
            $tenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
            $dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
            $dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

            $params = [];
            $where = [];

            // Tenant isolation
            $currentTenantId = $this->getTenantId();
            if ($currentTenantId && !$this->isPlatformAdmin()) {
                $where[] = "tenant_id = ?";
                $params[] = $currentTenantId;
            }

            if (!empty($search)) {
                $where[] = "(description LIKE ? OR resource LIKE ? OR username LIKE ? OR email LIKE ?)";
                $searchTerm = "%$search%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            if (!empty($action)) {
                $where[] = "action_type = ?";
                $params[] = $action;
            }

            if (!empty($module)) {
                $where[] = "module = ?";
                $params[] = $module;
            }

            if ($tenantId > 0) {
                $where[] = "tenant_id = ?";
                $params[] = $tenantId;
            }

            if (!empty($dateFrom)) {
                $where[] = "DATE(created_at) >= ?";
                $params[] = $dateFrom;
            }

            if (!empty($dateTo)) {
                $where[] = "DATE(created_at) <= ?";
                $params[] = $dateTo;
            }

            $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
            $offset = ($page - 1) * $limit;

            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM audit_logs $whereClause";
            $countResult = $this->db->fetchOne($countSql, $params);
            $total = (int)($countResult['total'] ?? 0);
            $totalPages = $total > 0 ? ceil($total / $limit) : 0;

            // Get logs
            $sql = "SELECT * FROM audit_logs 
                    $whereClause 
                    ORDER BY created_at DESC 
                    LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $logs = $this->db->fetchAll($sql, $params);

            $this->response->success([
                'logs' => $logs,
                'total' => $total,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'per_page' => $limit
            ]);
        } catch (Exception $e) {
            error_log('getLogs error: ' . $e->getMessage());
            $this->response->error('Error fetching logs: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get a single audit log
     * GET /api/platform/index.php?endpoint=audit-logs&id={id}
     */
    public function getLog(int $id): void
    {
        try {
            $params = [$id];
            $tenantId = $this->getTenantId();

            $sql = "SELECT * FROM audit_logs WHERE id = ?";
            if ($tenantId && !$this->isPlatformAdmin()) {
                $sql .= " AND tenant_id = ?";
                $params[] = $tenantId;
            }

            $log = $this->db->fetchOne($sql, $params);

            if (!$log) {
                $this->response->error('Log not found', 404);
                return;
            }

            $this->response->success($log);
        } catch (Exception $e) {
            error_log('getLog error: ' . $e->getMessage());
            $this->response->error('Error fetching log: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get audit log stats
     * GET /api/platform/index.php?endpoint=audit-logs&stats=true
     */
    public function getStats(): void
    {
        try {
            $tenantId = $this->getTenantId();
            $params = [];

            $where = "1=1";
            if ($tenantId && !$this->isPlatformAdmin()) {
                $where = "tenant_id = ?";
                $params[] = $tenantId;
            }

            $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today,
                        COUNT(DISTINCT user_id) as unique_users,
                        MAX(created_at) as last_activity
                    FROM audit_logs 
                    WHERE $where";

            $result = $this->db->fetchOne($sql, $params);

            $this->response->success([
                'total' => (int)($result['total'] ?? 0),
                'today' => (int)($result['today'] ?? 0),
                'unique_users' => (int)($result['unique_users'] ?? 0),
                'last_activity' => $result['last_activity'] ?? null
            ]);
        } catch (Exception $e) {
            error_log('getStats error: ' . $e->getMessage());
            $this->response->error('Error fetching stats: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Clean old logs
     * DELETE /api/platform/index.php?endpoint=audit-logs&clean={days}
     */
    public function cleanLogs(int $days): void
    {
        try {
            if (!$this->isPlatformAdmin()) {
                $this->response->error('Only platform admins can clean logs', 403);
                return;
            }

            $sql = "DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
            $result = $this->db->execute($sql, [$days]);
            $deleted = $result ? $result : 0;

            $this->response->success([
                'message' => "$deleted old logs deleted",
                'deleted' => $deleted
            ]);
        } catch (Exception $e) {
            error_log('cleanLogs error: ' . $e->getMessage());
            $this->response->error('Error cleaning logs: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Export logs as CSV
     * GET /api/platform/index.php?endpoint=audit-logs&export=true
     */
    public function exportLogs(): void
    {
        try {
            $tenantId = $this->getTenantId();
            $params = [];

            $where = "1=1";
            if ($tenantId && !$this->isPlatformAdmin()) {
                $where = "tenant_id = ?";
                $params[] = $tenantId;
            }

            // Apply filters if provided
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $action = isset($_GET['action']) ? trim($_GET['action']) : '';
            $module = isset($_GET['module']) ? trim($_GET['module']) : '';
            $dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
            $dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

            if (!empty($search)) {
                $where .= " AND (description LIKE ? OR resource LIKE ? OR username LIKE ? OR email LIKE ?)";
                $searchTerm = "%$search%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            if (!empty($action)) {
                $where .= " AND action_type = ?";
                $params[] = $action;
            }

            if (!empty($module)) {
                $where .= " AND module = ?";
                $params[] = $module;
            }

            if (!empty($dateFrom)) {
                $where .= " AND DATE(created_at) >= ?";
                $params[] = $dateFrom;
            }

            if (!empty($dateTo)) {
                $where .= " AND DATE(created_at) <= ?";
                $params[] = $dateTo;
            }

            $sql = "SELECT * FROM audit_logs WHERE $where ORDER BY created_at DESC";
            $logs = $this->db->fetchAll($sql, $params);

            // Set headers for CSV download
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d') . '.csv"');

            $output = fopen('php://output', 'w');
            fputcsv($output, ['ID', 'Date', 'User', 'Action', 'Module', 'Resource', 'Resource ID', 'Description', 'IP Address', 'User Agent', 'Tenant ID']);

            foreach ($logs as $log) {
                fputcsv($output, [
                    $log['id'] ?? '',
                    $log['created_at'] ?? '',
                    $log['username'] ?? 'System',
                    $log['action_type'] ?? '',
                    $log['module'] ?? '',
                    $log['resource'] ?? '',
                    $log['resource_id'] ?? '',
                    $log['description'] ?? '',
                    $log['ip_address'] ?? '',
                    $log['user_agent'] ?? '',
                    $log['tenant_id'] ?? ''
                ]);
            }

            fclose($output);
            exit;
        } catch (Exception $e) {
            error_log('exportLogs error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error exporting logs: ' . $e->getMessage()]);
            exit;
        }
    }
}
