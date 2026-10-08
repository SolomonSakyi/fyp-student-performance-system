<?php

/**
 * PaymentProvider.php
 * Payment Provider Model with Multi-Tenant Isolation
 * 
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 1.0
 * 
 * @filepath app/models/Platform/PaymentProvider.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class PaymentProvider
{
    /**
     * Database instance
     * @var DatabaseHelper
     */
    private $db;

    /**
     * Table name
     * @var string
     */
    private $table = 'payment_provider_credentials';

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Get all providers by tenant
     * 
     * @param int $tenantId Tenant ID
     * @param int|null $schoolId School ID (optional)
     * @return array Providers
     */
    public function getByTenant(int $tenantId, ?int $schoolId = null): array
    {
        $params = [$tenantId];
        $sql = "SELECT * FROM {$this->table} WHERE tenant_id = ? AND deleted_at IS NULL";

        if ($schoolId) {
            $sql .= " AND (school_id = ? OR school_id IS NULL)";
            $params[] = $schoolId;
        }

        $sql .= " ORDER BY is_default DESC, provider_name ASC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get a single provider by ID
     * 
     * @param int $id Provider ID
     * @param int $tenantId Tenant ID
     * @return array|null Provider or null
     */
    public function getById(int $id, int $tenantId): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$id, $tenantId]);
    }

    /**
     * Get provider by code
     * 
     * @param string $code Provider code
     * @param int $tenantId Tenant ID
     * @param int|null $schoolId School ID (optional)
     * @return array|null Provider or null
     */
    public function getByCode(string $code, int $tenantId, ?int $schoolId = null): ?array
    {
        $params = [$tenantId, $code];
        $sql = "SELECT * FROM {$this->table} WHERE tenant_id = ? AND provider_code = ? AND deleted_at IS NULL";

        if ($schoolId) {
            $sql .= " AND (school_id = ? OR school_id IS NULL)";
            $params[] = $schoolId;
        }

        $sql .= " LIMIT 1";

        return $this->db->fetchOne($sql, $params);
    }

    /**
     * Create a new provider
     * 
     * @param array $data Provider data
     * @return int Inserted ID
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO {$this->table} (
                    tenant_id, school_id, provider_code, provider_name, provider_type,
                    api_key_encrypted, api_secret_encrypted, webhook_secret_encrypted,
                    additional_config, environment, status, is_default, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $this->db->execute($sql, [
            $data['tenant_id'],
            $data['school_id'] ?? null,
            $data['provider_code'],
            $data['provider_name'],
            $data['provider_type'],
            $data['api_key_encrypted'] ?? null,
            $data['api_secret_encrypted'] ?? null,
            $data['webhook_secret_encrypted'] ?? null,
            $data['additional_config'] ?? null,
            $data['environment'] ?? 'sandbox',
            $data['status'] ?? 'pending',
            isset($data['is_default']) ? (int)$data['is_default'] : 0,
            $data['created_by']
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Update a provider
     * 
     * @param int $id Provider ID
     * @param array $data Provider data
     * @return bool Success
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        $updatable = [
            'provider_name',
            'provider_type',
            'api_key_encrypted',
            'api_secret_encrypted',
            'webhook_secret_encrypted',
            'additional_config',
            'environment',
            'status',
            'is_default'
        ];

        foreach ($updatable as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . ", updated_at = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NULL";

        return $this->db->execute($sql, $params);
    }

    /**
     * Delete a provider (soft delete)
     * 
     * @param int $id Provider ID
     * @return bool Success
     */
    public function delete(int $id): bool
    {
        $sql = "UPDATE {$this->table} SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?";
        return $this->db->execute($sql, [$id]);
    }

    /**
     * Get provider with decrypted credentials
     * 
     * @param int $id Provider ID
     * @param int $tenantId Tenant ID
     * @return array|null Provider with decrypted data or null
     */
    public function getWithDecrypted(int $id, int $tenantId): ?array
    {
        return $this->getById($id, $tenantId);
    }

    /**
     * Check if provider exists
     * 
     * @param int $id Provider ID
     * @param int $tenantId Tenant ID
     * @return bool True if exists
     */
    public function exists(int $id, int $tenantId): bool
    {
        $sql = "SELECT id FROM {$this->table} WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL";
        $result = $this->db->fetchOne($sql, [$id, $tenantId]);
        return (bool) $result;
    }

    /**
     * Get default provider
     * 
     * @param int $tenantId Tenant ID
     * @param int|null $schoolId School ID (optional)
     * @return array|null Default provider or null
     */
    public function getDefault(int $tenantId, ?int $schoolId = null): ?array
    {
        $params = [$tenantId];
        $sql = "SELECT * FROM {$this->table} WHERE tenant_id = ? AND is_default = 1 AND deleted_at IS NULL";

        if ($schoolId) {
            $sql .= " AND (school_id = ? OR school_id IS NULL)";
            $params[] = $schoolId;
        }

        $sql .= " LIMIT 1";

        return $this->db->fetchOne($sql, $params);
    }

    /**
     * Unset all defaults
     * 
     * @param int $tenantId Tenant ID
     * @param int $excludeId Provider ID to exclude
     * @param int|null $schoolId School ID (optional)
     * @return bool Success
     */
    public function unsetDefaults(int $tenantId, int $excludeId, ?int $schoolId = null): bool
    {
        $params = [$tenantId, $excludeId];
        $sql = "UPDATE {$this->table} SET is_default = 0 WHERE tenant_id = ? AND id != ? AND deleted_at IS NULL";

        if ($schoolId) {
            $sql .= " AND school_id = ?";
            $params[] = $schoolId;
        } else {
            $sql .= " AND school_id IS NULL";
        }

        return $this->db->execute($sql, $params);
    }

    /**
     * Get providers by status
     * 
     * @param int $tenantId Tenant ID
     * @param string $status Status
     * @return array Providers
     */
    public function getByStatus(int $tenantId, string $status): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE tenant_id = ? AND status = ? AND deleted_at IS NULL ORDER BY provider_name ASC";
        return $this->db->fetchAll($sql, [$tenantId, $status]);
    }

    /**
     * Update provider test result
     * 
     * @param int $id Provider ID
     * @param string $result Test result ('success' or 'failed')
     * @param string|null $error Error message
     * @return bool Success
     */
    public function updateTestResult(int $id, string $result, ?string $error = null): bool
    {
        $sql = "UPDATE {$this->table} 
                SET last_tested_at = CURRENT_TIMESTAMP,
                    last_test_result = ?,
                    last_test_error = ?
                WHERE id = ? AND deleted_at IS NULL";

        return $this->db->execute($sql, [$result, $error, $id]);
    }
}
