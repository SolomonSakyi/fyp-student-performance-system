<?php

/**
 * BaseService.php
 * Base Service for all Platform Services
 * 
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 2.0
 */

abstract class BaseService
{
    /**
     * @var DatabaseHelper Database instance
     */
    protected $db;

    /**
     * @var LoggerHelper Logger instance
     */
    protected $logger;

    /**
     * @var PlatformAuditLog Audit log model
     */
    protected $auditModel;

    /**
     * @var bool Transaction flag
     */
    protected $inTransaction = false;

    /**
     * Constructor - uses absolute path to avoid path issues
     */
    public function __construct()
    {
        // Use absolute path directly to your project root
        $projectRoot = 'C:/Users/Almighty/Documents/FYP_Student_Performance_System_0.1/';

        require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
        require_once $projectRoot . 'app/helpers/LoggerHelper.php';
        require_once $projectRoot . 'app/models/Platform/PlatformAuditLog.php';

        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->auditModel = new PlatformAuditLog();
    }

    /**
     * Log an audit entry
     */
    protected function logAudit(array $data): void
    {
        try {
            if (!isset($data['ip_address'])) {
                $data['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? null;
            }
            if (!isset($data['user_agent'])) {
                $data['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? null;
            }
            $this->auditModel->log($data);
        } catch (Exception $e) {
            $this->logger->error('logAudit error: ' . $e->getMessage());
        }
    }

    /**
     * Begin a transaction
     */
    protected function beginTransaction(): void
    {
        if (!$this->inTransaction) {
            $this->db->beginTransaction();
            $this->inTransaction = true;
        }
    }

    /**
     * Commit a transaction
     */
    protected function commit(): void
    {
        if ($this->inTransaction) {
            $this->db->commit();
            $this->inTransaction = false;
        }
    }

    /**
     * Rollback a transaction
     */
    protected function rollback(): void
    {
        if ($this->inTransaction) {
            $this->db->rollback();
            $this->inTransaction = false;
        }
    }

    /**
     * Validate required fields
     */
    protected function validateRequired(array $data, array $required): ?string
    {
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }
        return null;
    }

    /**
     * Generate a unique code
     */
    protected function generateCode(string $prefix, int $length = 8): string
    {
        $code = strtoupper($prefix);
        $code .= strtoupper(substr(uniqid(), -$length));
        return $code;
    }

    /**
     * Get current user ID from session
     */
    protected function getCurrentUserId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get current tenant ID from session
     */
    protected function getCurrentTenantId(): ?int
    {
        return $_SESSION['tenant_id'] ?? null;
    }

    /**
     * Sanitize input data
     */
    protected function sanitize(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $sanitized[$key] = htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }

    /**
     * Check if a value is unique in a table
     */
    protected function isUnique(string $table, string $column, string $value, ?int $excludeId = null): bool
    {
        $sql = "SELECT 1 FROM {$table} WHERE {$column} = ?";
        $params = [$value];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $result = $this->db->fetchOne($sql, $params);
        return !(bool)$result;
    }

    /**
     * Get tenant ID from a resource
     */
    protected function getTenantIdFromResource(string $table, int $resourceId): ?int
    {
        $sql = "SELECT tenant_id FROM {$table} WHERE id = ?";
        $result = $this->db->fetchOne($sql, [$resourceId]);
        return $result ? (int)$result['tenant_id'] : null;
    }

    /**
     * Verify tenant ownership of a resource
     */
    protected function verifyTenantOwnership(string $table, int $resourceId, int $tenantId): bool
    {
        $sql = "SELECT 1 FROM {$table} WHERE id = ? AND tenant_id = ?";
        $result = $this->db->fetchOne($sql, [$resourceId, $tenantId]);
        return (bool)$result;
    }
}
