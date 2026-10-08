<?php

/**
 * BaseModel.php - Complete Working Version
 * All methods are debugged and working
 */

abstract class BaseModel
{
    protected $table = '';
    protected $fillable = [];
    protected $hidden = ['password_hash'];
    protected $db;
    protected $logger;

    public function __construct()
    {
        $projectRoot = dirname(__DIR__, 3) . '/';
        require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
        require_once $projectRoot . 'app/helpers/LoggerHelper.php';

        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
    }

    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Create a new record
     */
    public function create(array $data): ?int
    {
        try {
            $filteredData = [];
            foreach ($this->fillable as $field) {
                if (array_key_exists($field, $data)) {
                    $filteredData[$field] = $data[$field];
                }
            }
            if (empty($filteredData)) {
                return null;
            }
            $fields = array_keys($filteredData);
            $placeholders = array_fill(0, count($fields), '?');
            $sql = "INSERT INTO {$this->table} (" . implode(', ', $fields) . ") 
                    VALUES (" . implode(', ', $placeholders) . ")";
            $this->db->query($sql, array_values($filteredData));
            return $this->db->lastInsertId();
        } catch (Exception $e) {
            error_log('create error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Find a record by ID
     */
    public function find(int $id): ?array
    {
        try {
            $sql = "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1";
            $result = $this->db->fetchOne($sql, [$id]);

            if ($result && !empty($this->hidden)) {
                foreach ($this->hidden as $field) {
                    unset($result[$field]);
                }
            }
            return $result;
        } catch (Exception $e) {
            error_log('find error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Find a record by field
     */
    public function findBy(string $field, $value): ?array
    {
        try {
            $sql = "SELECT * FROM {$this->table} WHERE {$field} = ? LIMIT 1";
            $result = $this->db->fetchOne($sql, [$value]);

            if ($result && !empty($this->hidden)) {
                foreach ($this->hidden as $field) {
                    unset($result[$field]);
                }
            }
            return $result;
        } catch (Exception $e) {
            error_log('findBy error: ' . $e->getMessage() . ' - Field: ' . $field . ' - Value: ' . $value);
            return null;
        }
    }

    /**
     * Get all records
     */
    public function getAll(array $orderBy = ['created_at' => 'DESC']): array
    {
        try {
            $orderClause = [];
            foreach ($orderBy as $field => $direction) {
                $orderClause[] = "{$field} {$direction}";
            }
            $orderClause = implode(', ', $orderClause);
            $sql = "SELECT * FROM {$this->table} ORDER BY {$orderClause}";
            $results = $this->db->fetchAll($sql);

            if (!empty($this->hidden)) {
                foreach ($results as &$result) {
                    foreach ($this->hidden as $field) {
                        unset($result[$field]);
                    }
                }
            }
            return $results;
        } catch (Exception $e) {
            error_log('getAll error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Update a record
     */
    public function update(int $id, array $data): bool
    {
        try {
            $filteredData = [];
            foreach ($this->fillable as $field) {
                if (array_key_exists($field, $data)) {
                    $filteredData[$field] = $data[$field];
                }
            }
            if (empty($filteredData)) {
                return false;
            }
            $sets = [];
            foreach (array_keys($filteredData) as $field) {
                $sets[] = "{$field} = ?";
            }
            $sets = implode(', ', $sets);
            $sql = "UPDATE {$this->table} SET {$sets} WHERE id = ?";
            $params = array_values($filteredData);
            $params[] = $id;
            $this->db->query($sql, $params);
            return true;
        } catch (Exception $e) {
            error_log('update error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Soft delete a record
     */
    public function delete(int $id): bool
    {
        try {
            // Check if deleted_at column exists
            $columns = $this->db->fetchAll("SHOW COLUMNS FROM {$this->table}");
            $hasDeletedAt = false;
            foreach ($columns as $col) {
                if ($col['Field'] === 'deleted_at') {
                    $hasDeletedAt = true;
                    break;
                }
            }

            if ($hasDeletedAt) {
                $sql = "UPDATE {$this->table} SET deleted_at = NOW() WHERE id = ?";
            } else {
                $sql = "DELETE FROM {$this->table} WHERE id = ?";
            }
            $this->db->query($sql, [$id]);
            return true;
        } catch (Exception $e) {
            error_log('delete error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Hard delete a record
     */
    public function hardDelete(int $id): bool
    {
        try {
            $sql = "DELETE FROM {$this->table} WHERE id = ?";
            $this->db->query($sql, [$id]);
            return true;
        } catch (Exception $e) {
            error_log('hardDelete error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a record exists
     */
    public function exists(int $id): bool
    {
        try {
            $sql = "SELECT COUNT(*) as count FROM {$this->table} WHERE id = ?";
            $result = $this->db->fetchOne($sql, [$id]);
            return ($result['count'] ?? 0) > 0;
        } catch (Exception $e) {
            error_log('exists error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch raw results
     */
    public function rawFetch(string $sql, array $params = []): array
    {
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Fetch one raw result
     */
    public function rawFetchOne(string $sql, array $params = []): ?array
    {
        return $this->db->fetchOne($sql, $params);
    }

    /**
     * Begin transaction
     */
    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit(): void
    {
        $this->db->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollBack(): void
    {
        $this->db->rollBack();
    }
}
