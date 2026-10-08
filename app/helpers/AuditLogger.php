<?php

/**
 * AuditLogger.php
 * Helper for logging audit trails
 * 
 * @package EduTrack
 * @subpackage Helpers
 * @version 2.0
 * @filepath app/helpers/AuditLogger.php
 */

class AuditLogger
{
    private $db;
    private static $instance = null;

    private function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Log an audit entry
     */
    public function log(
        string $action,
        string $module,
        ?int $tenantId = null,
        ?int $userId = null,
        ?string $username = null,
        ?string $email = null,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $resource = null,
        ?string $resourceId = null,
        ?string $description = null,
        $oldData = null,
        $newData = null
    ): bool {
        try {
            // Get tenant ID if not provided
            if ($tenantId === null) {
                $tenantId = $_SESSION['tenant_id'] ?? null;
            }

            // Get user info if not provided
            if ($userId === null) {
                $userId = $_SESSION['user_id'] ?? null;
            }
            if ($username === null) {
                $username = $_SESSION['username'] ?? null;
            }
            if ($email === null) {
                $email = $_SESSION['email'] ?? null;
            }
            if ($firstName === null) {
                $firstName = $_SESSION['first_name'] ?? null;
            }
            if ($lastName === null) {
                $lastName = $_SESSION['last_name'] ?? null;
            }

            $sql = "INSERT INTO audit_logs 
                    (tenant_id, user_id, username, email, first_name, last_name, 
                     action_type, module, resource, resource_id, description, 
                     old_data, new_data, ip_address, user_agent, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $params = [
                $tenantId,
                $userId,
                $username,
                $email,
                $firstName,
                $lastName,
                $action,
                $module,
                $resource,
                $resourceId,
                $description,
                $oldData ? json_encode($oldData) : null,
                $newData ? json_encode($newData) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ];

            return $this->db->execute($sql, $params) > 0;
        } catch (Exception $e) {
            // Silently fail for audit logging
            error_log('AuditLogger error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Log a CREATE action
     */
    public function logCreate(string $module, string $resource, ?string $resourceId = null, ?string $description = null, $newData = null): bool
    {
        return $this->log('create', $module, null, null, null, null, null, null, $resource, $resourceId, $description, null, $newData);
    }

    /**
     * Log an UPDATE action
     */
    public function logUpdate(string $module, string $resource, ?string $resourceId = null, ?string $description = null, $oldData = null, $newData = null): bool
    {
        return $this->log('update', $module, null, null, null, null, null, null, $resource, $resourceId, $description, $oldData, $newData);
    }

    /**
     * Log a DELETE action
     */
    public function logDelete(string $module, string $resource, ?string $resourceId = null, ?string $description = null, $oldData = null): bool
    {
        return $this->log('delete', $module, null, null, null, null, null, null, $resource, $resourceId, $description, $oldData, null);
    }

    /**
     * Log a LOGIN action
     */
    public function logLogin(?string $username = null, ?int $userId = null): bool
    {
        return $this->log('login', 'auth', null, $userId, $username, null, null, null, 'User Login', null, 'User logged in successfully', null, null);
    }

    /**
     * Log a LOGOUT action
     */
    public function logLogout(?string $username = null, ?int $userId = null): bool
    {
        return $this->log('logout', 'auth', null, $userId, $username, null, null, null, 'User Logout', null, 'User logged out', null, null);
    }
}
