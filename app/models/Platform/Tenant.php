<?php

/**
 * Tenant.php
 * Tenant Model
 * 
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 2.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class Tenant extends BaseModel
{
    protected $table = 'tenants';

    protected $fillable = [
        'tenant_name',
        'tenant_code',
        'legal_name',
        'email',
        'phone',
        'website',
        'region',
        'district',
        'postal_address',
        'institution_type',
        'status',
        'is_active'
    ];

    public function findByCode(string $code): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE tenant_code = ? AND deleted_at IS NULL LIMIT 1";
        return $this->rawFetchOne($sql, [$code]);
    }

    public function getActive(): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE is_active = 1 AND deleted_at IS NULL ORDER BY tenant_name";
        return $this->rawFetch($sql);
    }

    public function getByStatus(string $status): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE status = ? AND deleted_at IS NULL ORDER BY tenant_name";
        return $this->rawFetch($sql, [$status]);
    }
}
