<?php

/**
 * PlatformUser.php
 * Platform User Model
 * 
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 2.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class PlatformUser extends BaseModel
{
    protected $table = 'platform_users';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'username',
        'email',
        'password_hash',
        'first_name',
        'last_name',
        'phone',
        'is_active',
        'last_login',
        'failed_attempts',
        'is_locked',
        'lockout_until'
    ];

    protected $hidden = ['password_hash'];

    public function findByUsername(string $username): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE username = ? AND deleted_at IS NULL LIMIT 1";
        return $this->rawFetchOne($sql, [$username]);
    }

    public function findByEmail(string $email): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE email = ? AND deleted_at IS NULL LIMIT 1";
        return $this->rawFetchOne($sql, [$email]);
    }

    public function getRoles(int $userId): array
    {
        $sql = "SELECT r.* FROM platform_roles r 
                JOIN platform_user_roles pur ON r.id = pur.role_id 
                WHERE pur.user_id = ? AND r.deleted_at IS NULL";
        return $this->rawFetch($sql, [$userId]);
    }

    public function assignRole(int $userId, int $roleId, ?int $grantedBy = null): bool
    {
        try {
            $sql = "INSERT INTO platform_user_roles (user_id, role_id, granted_by, granted_at) 
                    VALUES (?, ?, ?, NOW())";
            $this->db->query($sql, [$userId, $roleId, $grantedBy]);
            return true;
        } catch (Exception $e) {
            $this->logger->error('assignRole error: ' . $e->getMessage());
            return false;
        }
    }

    public function removeAllRoles(int $userId): bool
    {
        try {
            $sql = "DELETE FROM platform_user_roles WHERE user_id = ?";
            $this->db->query($sql, [$userId]);
            return true;
        } catch (Exception $e) {
            $this->logger->error('removeAllRoles error: ' . $e->getMessage());
            return false;
        }
    }

    public function getActive(): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE is_active = 1 AND deleted_at IS NULL ORDER BY id DESC";
        return $this->rawFetch($sql);
    }

    public function getByTenant(int $tenantId): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY id DESC";
        return $this->rawFetch($sql, [$tenantId]);
    }

    public function getByRole(string $roleCode): array
    {
        $sql = "SELECT u.* FROM {$this->table} u
                JOIN platform_user_roles pur ON u.id = pur.user_id
                JOIN platform_roles r ON pur.role_id = r.id
                WHERE r.code = ? AND u.deleted_at IS NULL
                ORDER BY u.id DESC";
        return $this->rawFetch($sql, [$roleCode]);
    }
}
