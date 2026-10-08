<?php

/**
 * School.php
 * School Model
 * 
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class School extends BaseModel
{
    protected $table = 'schools';

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get schools by tenant ID with filters and pagination
     */
    public function getByTenantWithFilters(int $tenantId, array $filters = [], int $page = 1, int $limit = 20): array
    {
        $sql = "SELECT s.*, 
                       COUNT(DISTINCT c.id) as campus_count
                FROM schools s
                LEFT JOIN campuses c ON s.id = c.school_id AND c.deleted_at IS NULL
                WHERE s.tenant_id = ? AND s.deleted_at IS NULL";
        $params = [$tenantId];

        // Apply filters
        if (!empty($filters['search'])) {
            $sql .= " AND (s.school_name LIKE ? OR s.school_code LIKE ? OR s.legal_name LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        if (!empty($filters['status'])) {
            $sql .= " AND s.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['school_type'])) {
            $sql .= " AND s.school_type = ?";
            $params[] = $filters['school_type'];
        }

        $sql .= " GROUP BY s.id ORDER BY s.school_name ASC";

        // Get total count for pagination
        $countSql = str_replace(
            "s.*, COUNT(DISTINCT c.id) as campus_count",
            "COUNT(DISTINCT s.id) as total",
            $sql
        );
        $countResult = $this->rawFetchOne($countSql, $params);
        $total = (int)($countResult['total'] ?? 0);

        // Apply pagination
        $offset = ($page - 1) * $limit;
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $data = $this->rawFetch($sql, $params);

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => $total > 0 ? ceil($total / $limit) : 1
        ];
    }

    /**
     * Get schools by tenant ID with campus count
     */
    public function getByTenant(int $tenantId, bool $withCampuses = false): array
    {
        if ($withCampuses) {
            $sql = "SELECT s.*, 
                           COUNT(DISTINCT c.id) as campus_count
                    FROM schools s
                    LEFT JOIN campuses c ON s.id = c.school_id AND c.deleted_at IS NULL
                    WHERE s.tenant_id = ? AND s.deleted_at IS NULL
                    GROUP BY s.id
                    ORDER BY s.school_name ASC";
        } else {
            $sql = "SELECT * FROM schools 
                    WHERE tenant_id = ? AND deleted_at IS NULL
                    ORDER BY school_name ASC";
        }

        return $this->rawFetch($sql, [$tenantId]);
    }

    /**
     * Get active schools by tenant with campus count
     */
    public function getActiveByTenant(int $tenantId): array
    {
        $sql = "SELECT s.*, 
                       COUNT(DISTINCT c.id) as campus_count
                FROM schools s
                LEFT JOIN campuses c ON s.id = c.school_id AND c.deleted_at IS NULL
                WHERE s.tenant_id = ? AND s.deleted_at IS NULL AND s.status = 'active'
                GROUP BY s.id
                ORDER BY s.school_name ASC";

        return $this->rawFetch($sql, [$tenantId]);
    }

    /**
     * Get campus count for a school
     */
    public function getCampusCount(int $schoolId): int
    {
        $sql = "SELECT COUNT(*) as count FROM campuses WHERE school_id = ? AND deleted_at IS NULL";
        $result = $this->rawFetchOne($sql, [$schoolId]);
        return (int)($result['count'] ?? 0);
    }

    /**
     * Get school by ID with campus count
     */
    public function getById(int $schoolId): ?array
    {
        $sql = "SELECT s.*, 
                       COUNT(DISTINCT c.id) as campus_count
                FROM schools s
                LEFT JOIN campuses c ON s.id = c.school_id AND c.deleted_at IS NULL
                WHERE s.id = ? AND s.deleted_at IS NULL
                GROUP BY s.id";

        return $this->rawFetchOne($sql, [$schoolId]);
    }

    /**
     * Get school by code
     */
    public function getByCode(string $schoolCode, int $tenantId): ?array
    {
        $sql = "SELECT * FROM schools 
                WHERE school_code = ? AND tenant_id = ? AND deleted_at IS NULL";

        return $this->rawFetchOne($sql, [$schoolCode, $tenantId]);
    }

    /**
     * Get schools by status
     */
    public function getByStatus(int $tenantId, string $status): array
    {
        $sql = "SELECT s.*, 
                       COUNT(DISTINCT c.id) as campus_count
                FROM schools s
                LEFT JOIN campuses c ON s.id = c.school_id AND c.deleted_at IS NULL
                WHERE s.tenant_id = ? AND s.status = ? AND s.deleted_at IS NULL
                GROUP BY s.id
                ORDER BY s.school_name ASC";

        return $this->rawFetch($sql, [$tenantId, $status]);
    }

    /**
     * Get school statistics
     */
    public function getStats(int $tenantId): array
    {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended,
                    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
                    SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived
                FROM schools
                WHERE tenant_id = ? AND deleted_at IS NULL";

        $result = $this->rawFetchOne($sql, [$tenantId]);

        return [
            'total' => (int)($result['total'] ?? 0),
            'active' => (int)($result['active'] ?? 0),
            'pending' => (int)($result['pending'] ?? 0),
            'suspended' => (int)($result['suspended'] ?? 0),
            'closed' => (int)($result['closed'] ?? 0),
            'archived' => (int)($result['archived'] ?? 0)
        ];
    }

    /**
     * Search schools
     */
    public function search(int $tenantId, string $search): array
    {
        $sql = "SELECT s.*, 
                       COUNT(DISTINCT c.id) as campus_count
                FROM schools s
                LEFT JOIN campuses c ON s.id = c.school_id AND c.deleted_at IS NULL
                WHERE s.tenant_id = ? 
                  AND s.deleted_at IS NULL
                  AND (s.school_name LIKE ? 
                       OR s.school_code LIKE ? 
                       OR s.legal_name LIKE ?)
                GROUP BY s.id
                ORDER BY s.school_name ASC";

        $searchTerm = '%' . $search . '%';
        return $this->rawFetch($sql, [$tenantId, $searchTerm, $searchTerm, $searchTerm]);
    }

    /**
     * Create a new school
     */
    public function create(array $data): ?int
    {
        $sql = "INSERT INTO schools (
                    uuid, tenant_id, school_name, legal_name, short_name,
                    school_code, registration_number, school_type, institution_level,
                    email, phone, secondary_phone, website,
                    address_line1, address_line2, city, district, region,
                    country_id, digital_address, postal_code,
                    primary_color, secondary_color,
                    status, is_active, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())";

        $result = $this->db->query($sql, [
            $data['uuid'] ?? $this->generateUuid(),
            $data['tenant_id'],
            $data['school_name'],
            $data['legal_name'] ?? null,
            $data['short_name'] ?? null,
            $data['school_code'],
            $data['registration_number'] ?? null,
            $data['school_type'] ?? null,
            $data['institution_level'] ?? null,
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
            $data['primary_color'] ?? '#4facfe',
            $data['secondary_color'] ?? '#00f2fe',
            $data['status'] ?? 'pending',
            $data['created_by'] ?? null
        ]);

        return $result ? (int)$this->db->lastInsertId() : null;
    }

    /**
     * Update a school
     */
    public function update(int $schoolId, array $data): bool
    {
        $sql = "UPDATE schools SET ";
        $updates = [];
        $params = [];

        $allowedFields = [
            'school_name',
            'legal_name',
            'short_name',
            'school_code',
            'registration_number',
            'school_type',
            'institution_level',
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
            'primary_color',
            'secondary_color',
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
        $params[] = $schoolId;

        return $this->db->query($sql, $params);
    }

    /**
     * Delete a school (soft delete)
     */
    public function delete(int $schoolId): bool
    {
        $sql = "UPDATE schools SET deleted_at = NOW(), status = 'archived' WHERE id = ? AND deleted_at IS NULL";
        return $this->db->query($sql, [$schoolId]);
    }

    /**
     * Change school status
     */
    public function changeStatus(int $schoolId, string $status): bool
    {
        $sql = "UPDATE schools SET status = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->query($sql, [$status, $schoolId]);
    }

    /**
     * Check if school code exists
     */
    public function codeExists(string $schoolCode, int $tenantId, ?int $excludeId = null): bool
    {
        $sql = "SELECT id FROM schools 
                WHERE school_code = ? AND tenant_id = ? AND deleted_at IS NULL";
        $params = [$schoolCode, $tenantId];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $result = $this->rawFetchOne($sql, $params);
        return $result !== null;
    }

    /**
     * Get all school types
     */
    public function getSchoolTypes(): array
    {
        return [
            'Basic School' => 'Basic School',
            'International School' => 'International School',
            'Montessori School' => 'Montessori School',
            'Preparatory School' => 'Preparatory School',
            'Senior High School' => 'Senior High School',
            'Technical School' => 'Technical School',
            'Vocational School' => 'Vocational School',
            'Special School' => 'Special School',
            'Other' => 'Other'
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
            'suspended' => 'Suspended',
            'closed' => 'Closed',
            'archived' => 'Archived'
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
