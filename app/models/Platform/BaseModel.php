<?php

/**
 * BaseModel.php
 * Base Model Class for all platform models
 * 
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 2.0
 * 
 * @filepath app/models/Platform/BaseModel.php
 */

class BaseModel
{
    /**
     * Database instance
     * @var DatabaseHelper
     */
    protected $db;

    /**
     * Table name
     * @var string
     */
    protected $table;

    /**
     * Fillable fields
     * @var array
     */
    protected $fillable = [];

    /**
     * Primary key
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    // ============================================================
    // CRUD OPERATIONS
    // ============================================================

    /**
     * Find record by ID
     * 
     * @param int $id Record ID
     * @return array|null Record or null
     */
    public function find(int $id): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ? AND deleted_at IS NULL";
        return $this->rawFetchOne($sql, [$id]);
    }

    /**
     * Get all records
     * 
     * @param string $orderBy Order by clause
     * @param string $direction Order direction
     * @return array Records
     */
    public function all(string $orderBy = 'id', string $direction = 'ASC'): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE deleted_at IS NULL ORDER BY {$orderBy} {$direction}";
        return $this->rawFetch($sql);
    }

    /**
     * Get records with pagination
     * 
     * @param int $limit Limit
     * @param int $offset Offset
     * @param string $orderBy Order by clause
     * @param string $direction Order direction
     * @return array Records
     */
    public function paginate(int $limit = 20, int $offset = 0, string $orderBy = 'id', string $direction = 'ASC'): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE deleted_at IS NULL ORDER BY {$orderBy} {$direction} LIMIT ? OFFSET ?";
        return $this->rawFetch($sql, [$limit, $offset]);
    }

    /**
     * Create a new record
     * 
     * @param array $data Data to insert
     * @return int|bool Insert ID or false
     */
    public function create(array $data)
    {
        $fields = [];
        $values = [];
        $placeholders = [];

        foreach ($this->fillable as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = $field;
                $values[] = $data[$field];
                $placeholders[] = '?';
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "INSERT INTO {$this->table} (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";

        if ($this->db->query($sql, $values)) {
            return $this->db->lastInsertId();
        }

        return false;
    }

    /**
     * Update a record
     * 
     * @param int $id Record ID
     * @param array $data Data to update
     * @return bool Success
     */
    public function update(int $id, array $data): bool
    {
        $set = [];
        $values = [];

        foreach ($this->fillable as $field) {
            if (array_key_exists($field, $data)) {
                $set[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($set)) {
            return false;
        }

        $values[] = $id;
        $sql = "UPDATE {$this->table} SET " . implode(', ', $set) . " WHERE {$this->primaryKey} = ? AND deleted_at IS NULL";

        return $this->db->query($sql, $values);
    }

    /**
     * Soft delete a record
     * 
     * @param int $id Record ID
     * @return bool Success
     */
    public function delete(int $id): bool
    {
        $sql = "UPDATE {$this->table} SET deleted_at = NOW() WHERE {$this->primaryKey} = ? AND deleted_at IS NULL";
        return $this->db->query($sql, [$id]);
    }

    /**
     * Hard delete a record
     * 
     * @param int $id Record ID
     * @return bool Success
     */
    public function forceDelete(int $id): bool
    {
        $sql = "DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?";
        return $this->db->query($sql, [$id]);
    }

    /**
     * Restore a soft deleted record
     * 
     * @param int $id Record ID
     * @return bool Success
     */
    public function restore(int $id): bool
    {
        $sql = "UPDATE {$this->table} SET deleted_at = NULL WHERE {$this->primaryKey} = ?";
        return $this->db->query($sql, [$id]);
    }

    // ============================================================
    // QUERY METHODS
    // ============================================================

    /**
     * Get records by field
     * 
     * @param string $field Field name
     * @param mixed $value Field value
     * @return array Records
     */
    public function getByField(string $field, $value): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE {$field} = ? AND deleted_at IS NULL";
        return $this->rawFetch($sql, [$value]);
    }

    /**
     * Get record by field (single)
     * 
     * @param string $field Field name
     * @param mixed $value Field value
     * @return array|null Record or null
     */
    public function getOneByField(string $field, $value): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE {$field} = ? AND deleted_at IS NULL LIMIT 1";
        return $this->rawFetchOne($sql, [$value]);
    }

    /**
     * Count records
     * 
     * @param string $where Optional WHERE clause
     * @param array $params Parameters
     * @return int Count
     */
    public function count(string $where = '', array $params = []): int
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} WHERE deleted_at IS NULL";
        if (!empty($where)) {
            $sql .= " AND " . $where;
        }
        $result = $this->rawFetchOne($sql, $params);
        return $result ? (int)$result['count'] : 0;
    }

    /**
     * Check if record exists
     * 
     * @param string $field Field name
     * @param mixed $value Field value
     * @return bool True if exists
     */
    public function exists(string $field, $value): bool
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} WHERE {$field} = ? AND deleted_at IS NULL";
        $result = $this->rawFetchOne($sql, [$value]);
        return $result ? (int)$result['count'] > 0 : false;
    }

    // ============================================================
    // RAW QUERY HELPERS
    // ============================================================

    /**
     * Raw fetch all
     * 
     * @param string $sql SQL query
     * @param array $params Parameters
     * @return array Results
     */
    protected function rawFetch(string $sql, array $params = []): array
    {
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Raw fetch one
     * 
     * @param string $sql SQL query
     * @param array $params Parameters
     * @return array|null Result or null
     */
    protected function rawFetchOne(string $sql, array $params = []): ?array
    {
        return $this->db->fetchOne($sql, $params);
    }

    /**
     * Execute query
     * 
     * @param string $sql SQL query
     * @param array $params Parameters
     * @return bool Success
     */
    protected function rawExecute(string $sql, array $params = []): bool
    {
        return $this->db->query($sql, $params);
    }

    // ============================================================
    // TRANSACTION METHODS
    // ============================================================

    /**
     * Begin transaction
     */
    public function beginTransaction(): bool
    {
        return $this->db->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit(): bool
    {
        return $this->db->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollBack(): bool
    {
        return $this->db->rollBack();
    }

    // ============================================================
    // UTILITY METHODS
    // ============================================================

    /**
     * Get table name
     * 
     * @return string Table name
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get fillable fields
     * 
     * @return array Fillable fields
     */
    public function getFillable(): array
    {
        return $this->fillable;
    }

    /**
     * Get primary key
     * 
     * @return string Primary key
     */
    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    /**
     * Sanitize input data
     * 
     * @param array $data Data to sanitize
     * @return array Sanitized data
     */
    protected function sanitize(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $sanitized[$key] = trim(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }

    /**
     * Validate required fields
     * 
     * @param array $data Data to validate
     * @param array $required Required fields
     * @return bool True if valid
     */
    protected function validateRequired(array $data, array $required): bool
    {
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Convert to array (for responses)
     * 
     * @param array $data Data to convert
     * @return array Converted data
     */
    public function toArray(array $data): array
    {
        // Remove sensitive fields
        $sensitive = ['password', 'password_hash', 'api_secret', 'client_secret'];
        foreach ($sensitive as $field) {
            if (isset($data[$field])) {
                unset($data[$field]);
            }
        }
        return $data;
    }

    /**
     * Format for API response
     * 
     * @param array $data Data to format
     * @return array Formatted data
     */
    public function formatResponse(array $data): array
    {
        return [
            'success' => true,
            'data' => $this->toArray($data),
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Format error response
     * 
     * @param string $message Error message
     * @param int $code Error code
     * @return array Error response
     */
    public function formatError(string $message, int $code = 400): array
    {
        return [
            'success' => false,
            'message' => $message,
            'code' => $code,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}
