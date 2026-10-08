<?php

/**
 * Campus.php
 * Campus Model
 * 
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class Campus extends BaseModel
{
    protected $table = 'campuses';

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get campuses by school ID
     */
    public function getBySchool(int $schoolId): array
    {
        $sql = "SELECT * FROM campuses 
                WHERE school_id = ? AND deleted_at IS NULL
                ORDER BY campus_name ASC";

        return $this->rawFetch($sql, [$schoolId]);
    }

    /**
     * Get campuses by tenant ID (across all schools)
     */
    public function getByTenant(int $tenantId): array
    {
        $sql = "SELECT c.*, s.school_name, s.school_code
                FROM campuses c
                JOIN schools s ON c.school_id = s.id
                WHERE s.tenant_id = ? AND c.deleted_at IS NULL AND s.deleted_at IS NULL
                ORDER BY s.school_name ASC, c.campus_name ASC";

        return $this->rawFetch($sql, [$tenantId]);
    }

    /**
     * Get active campuses by school
     */
    public function getActiveBySchool(int $schoolId): array
    {
        $sql = "SELECT * FROM campuses 
                WHERE school_id = ? AND status = 'active' AND deleted_at IS NULL
                ORDER BY campus_name ASC";

        return $this->rawFetch($sql, [$schoolId]);
    }

    /**
     * Get campus by ID with school info
     */
    public function getById(int $campusId): ?array
    {
        $sql = "SELECT c.*, s.school_name, s.school_code, s.tenant_id
                FROM campuses c
                JOIN schools s ON c.school_id = s.id
                WHERE c.id = ? AND c.deleted_at IS NULL";

        return $this->rawFetchOne($sql, [$campusId]);
    }

    /**
     * Get campus by code
     */
    public function getByCode(string $campusCode, int $schoolId): ?array
    {
        $sql = "SELECT * FROM campuses 
                WHERE campus_code = ? AND school_id = ? AND deleted_at IS NULL";

        return $this->rawFetchOne($sql, [$campusCode, $schoolId]);
    }

    /**
     * Get campus statistics for a school
     */
    public function getStats(int $schoolId): array
    {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive,
                    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed
                FROM campuses
                WHERE school_id = ? AND deleted_at IS NULL";

        $result = $this->rawFetchOne($sql, [$schoolId]);

        return [
            'total' => (int)($result['total'] ?? 0),
            'active' => (int)($result['active'] ?? 0),
            'pending' => (int)($result['pending'] ?? 0),
            'inactive' => (int)($result['inactive'] ?? 0),
            'closed' => (int)($result['closed'] ?? 0)
        ];
    }

    /**
     * Search campuses
     */
    public function search(int $tenantId, string $search): array
    {
        $sql = "SELECT c.*, s.school_name, s.school_code
                FROM campuses c
                JOIN schools s ON c.school_id = s.id
                WHERE s.tenant_id = ? 
                  AND c.deleted_at IS NULL AND s.deleted_at IS NULL
                  AND (c.campus_name LIKE ? 
                       OR c.campus_code LIKE ? 
                       OR c.address LIKE ?)
                ORDER BY s.school_name ASC, c.campus_name ASC";

        $searchTerm = '%' . $search . '%';
        return $this->rawFetch($sql, [$tenantId, $searchTerm, $searchTerm, $searchTerm]);
    }

    /**
     * Create a new campus
     */
    public function create(array $data): ?int
    {
        $sql = "INSERT INTO campuses (
                    uuid, school_id, campus_name, short_name,
                    campus_code, campus_number, campus_type,
                    email, phone, secondary_phone, website,
                    address_line1, address_line2, city, district, region,
                    country_id, digital_address, postal_code,
                    timezone, status, is_active, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())";

        $result = $this->db->query($sql, [
            $data['uuid'] ?? $this->generateUuid(),
            $data['school_id'],
            $data['campus_name'],
            $data['short_name'] ?? null,
            $data['campus_code'],
            $data['campus_number'] ?? null,
            $data['campus_type'] ?? 'branch',
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['secondary_phone'] ?? null,
            $data['website'] ?? null,
            $data['address_line1'] ?? null,
            $data['address_line2'] ?? null,
            $data['city'] ?? null,
            $data['district'] ?? null,
            $data['region'] ?? null,
            $data['country_id'] ?? null,
            $data['digital_address'] ?? null,
            $data['postal_code'] ?? null,
            $data['timezone'] ?? 'UTC',
            $data['status'] ?? 'pending',
            $data['created_by'] ?? null
        ]);

        return $result ? (int)$this->db->lastInsertId() : null;
    }

    /**
     * Update a campus
     */
    public function update(int $campusId, array $data): bool
    {
        $sql = "UPDATE campuses SET ";
        $updates = [];
        $params = [];

        $allowedFields = [
            'campus_name',
            'short_name',
            'campus_code',
            'campus_number',
            'campus_type',
            'email',
            'phone',
            'secondary_phone',
            'website',
            'address_line1',
            'address_line2',
            'city',
            'district',
            'region',
            'country_id',
            'digital_address',
            'postal_code',
            'timezone',
            'status'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($updates)) {
            return false;
        }

        $sql .= implode(', ', $updates);
        $sql .= ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        $params[] = $campusId;

        return $this->db->query($sql, $params);
    }

    /**
     * Delete a campus (soft delete)
     */
    public function delete(int $campusId): bool
    {
        $sql = "UPDATE campuses SET deleted_at = NOW(), status = 'closed' WHERE id = ? AND deleted_at IS NULL";
        return $this->db->query($sql, [$campusId]);
    }

    /**
     * Change campus status
     */
    public function changeStatus(int $campusId, string $status): bool
    {
        $sql = "UPDATE campuses SET status = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->query($sql, [$status, $campusId]);
    }

    /**
     * Check if campus code exists
     */
    public function codeExists(string $campusCode, int $schoolId, ?int $excludeId = null): bool
    {
        $sql = "SELECT id FROM campuses 
                WHERE campus_code = ? AND school_id = ? AND deleted_at IS NULL";
        $params = [$campusCode, $schoolId];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $result = $this->rawFetchOne($sql, $params);
        return $result !== null;
    }

    /**
     * Get all campus types
     */
    public function getCampusTypes(): array
    {
        return [
            'main' => 'Main Campus',
            'branch' => 'Branch Campus',
            'satellite' => 'Satellite Campus',
            'virtual' => 'Virtual Campus'
        ];
    }

    /**
     * Get all statuses
     */
    public function getStatuses(): array
    {
        return [
            'active' => 'Active',
            'pending' => 'Pending',
            'inactive' => 'Inactive',
            'closed' => 'Closed'
        ];
    }

    /**
     * Generate UUID
     */
    protected function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff) | 0x4000,
            mt_rand(0, 0x3ffff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
