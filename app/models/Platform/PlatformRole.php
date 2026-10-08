<?php

/**
 * PlatformRole.php
 * Platform Role Model
 * 
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 2.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class PlatformRole extends BaseModel
{
    protected $table = 'platform_roles';

    protected $fillable = [
        'name',
        'code',
        'description',
        'is_system',
        'is_active'
    ];

    public function findByCode(string $code): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE code = ? AND deleted_at IS NULL LIMIT 1";
        return $this->rawFetchOne($sql, [$code]);
    }

    public function getActive(): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE is_active = 1 AND deleted_at IS NULL ORDER BY id";
        return $this->rawFetch($sql);
    }

    public function getAllRoles(): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE deleted_at IS NULL ORDER BY id";
        return $this->rawFetch($sql);
    }

    public function findByName(string $name): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE name = ? AND deleted_at IS NULL LIMIT 1";
        return $this->rawFetchOne($sql, [$name]);
    }
}
