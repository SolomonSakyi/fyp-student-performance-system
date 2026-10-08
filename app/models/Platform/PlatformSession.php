<?php

/**
 * PlatformSession.php
 * Platform Session Model
 * 
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class PlatformSession extends BaseModel
{
    // Use a different property name to avoid conflict
    protected $sessionTable = 'platform_sessions';

    public function __construct()
    {
        parent::__construct();
        // Set the table name in parent
        $this->table = 'platform_sessions';
    }

    /**
     * Get the table name
     */
    public function getTableName(): string
    {
        return 'platform_sessions';
    }

    /**
     * Create a new session
     */
    public function create(array $data): ?int
    {
        $sql = "INSERT INTO platform_sessions (
                    user_id, session_token, refresh_token, ip_address,
                    user_agent, browser, os, device, expiry_time,
                    is_active, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";

        $result = $this->db->query($sql, [
            $data['user_id'],
            $data['session_token'],
            $data['refresh_token'],
            $data['ip_address'] ?? null,
            $data['user_agent'] ?? null,
            $data['browser'] ?? null,
            $data['os'] ?? null,
            $data['device'] ?? null,
            $data['expiry_time']
        ]);

        return $result ? (int)$this->db->lastInsertId() : null;
    }

    /**
     * Find session by token
     */
    public function findByToken(string $token): ?array
    {
        $sql = "SELECT * FROM platform_sessions 
                WHERE session_token = ? AND is_active = 1 
                AND (expiry_time IS NULL OR expiry_time > NOW())";

        return $this->db->fetchOne($sql, [$token]);
    }

    /**
     * Find session by refresh token
     */
    public function findByRefreshToken(string $refreshToken): ?array
    {
        $sql = "SELECT * FROM platform_sessions 
                WHERE refresh_token = ? AND is_active = 1 
                AND (expiry_time IS NULL OR expiry_time > NOW())";

        return $this->db->fetchOne($sql, [$refreshToken]);
    }

    /**
     * Update session
     */
    public function update(int $id, array $data): bool
    {
        $sql = "UPDATE platform_sessions SET ";
        $updates = [];
        $params = [];

        $allowedFields = [
            'session_token',
            'refresh_token',
            'expiry_time',
            'is_active'
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($updates)) {
            return false;
        }

        $sql .= implode(', ', $updates);
        $sql .= ", updated_at = NOW() WHERE id = ?";
        $params[] = $id;

        return $this->db->query($sql, $params);
    }

    /**
     * Invalidate session by token
     */
    public function invalidateByToken(string $token): bool
    {
        $sql = "UPDATE platform_sessions 
                SET is_active = 0, updated_at = NOW() 
                WHERE session_token = ?";

        return $this->db->query($sql, [$token]);
    }

    /**
     * Invalidate all sessions for a user
     */
    public function invalidateAllByUser(int $userId): bool
    {
        $sql = "UPDATE platform_sessions 
                SET is_active = 0, updated_at = NOW() 
                WHERE user_id = ? AND is_active = 1";

        return $this->db->query($sql, [$userId]);
    }

    /**
     * Get active sessions for a user
     */
    public function getActiveSessions(int $userId): array
    {
        $sql = "SELECT * FROM platform_sessions 
                WHERE user_id = ? AND is_active = 1 
                AND (expiry_time IS NULL OR expiry_time > NOW())
                ORDER BY created_at DESC";

        return $this->db->fetchAll($sql, [$userId]);
    }

    /**
     * Clean expired sessions
     */
    public function cleanExpired(): int
    {
        $sql = "UPDATE platform_sessions 
                SET is_active = 0, updated_at = NOW() 
                WHERE expiry_time < NOW() AND is_active = 1";

        $this->db->query($sql);
        return (int)$this->db->lastInsertId();
    }
}
