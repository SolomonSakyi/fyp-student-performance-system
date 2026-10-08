<?php

/**
 * HealthProfileRepository.php
 * Repository for health profile data operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Health
 * @filepath app/repositories/Health/HealthProfileRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class HealthProfileRepository
{
    private $db;
    private $logger;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
    }

    /**
     * Create a health profile
     */
    public function create(array $data): ?int
    {
        try {
            // Validate that person_id exists
            $personCheck = $this->db->fetchOne("SELECT id FROM people WHERE id = ? AND deleted_at IS NULL", [$data['person_id']]);
            if (!$personCheck) {
                error_log('HealthProfileRepository::create - Person ID ' . $data['person_id'] . ' not found');
                return null;
            }

            // Validate that tenant_id exists
            $tenantCheck = $this->db->fetchOne("SELECT id FROM tenants WHERE id = ? AND deleted_at IS NULL", [$data['tenant_id']]);
            if (!$tenantCheck) {
                error_log('HealthProfileRepository::create - Tenant ID ' . $data['tenant_id'] . ' not found');
                return null;
            }

            $sql = "INSERT INTO health_profiles (
                person_id, tenant_id, blood_group, genotype, height_cm, weight_kg, bmi,
                allergies, medical_conditions, current_medications, disabilities,
                accessibility_requirements, dietary_restrictions, mental_health_conditions,
                is_pregnant, is_smoker, is_alcohol_consumer, emergency_medical_notes,
                primary_care_physician, physician_phone, physician_address,
                health_insurance_provider, health_insurance_number, nhis_number,
                insurance_expiry_date, insurance_notes, medical_alerts,
                privacy_level, is_verified, notes, created_by, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )";

            $params = [
                $data['person_id'],
                $data['tenant_id'],
                $data['blood_group'] ?? null,
                $data['genotype'] ?? null,
                $data['height_cm'] ?? null,
                $data['weight_kg'] ?? null,
                $data['bmi'] ?? null,
                $data['allergies'] ?? null,
                $data['medical_conditions'] ?? null,
                $data['current_medications'] ?? null,
                $data['disabilities'] ?? null,
                $data['accessibility_requirements'] ?? null,
                $data['dietary_restrictions'] ?? null,
                $data['mental_health_conditions'] ?? null,
                $data['is_pregnant'] ?? 0,
                $data['is_smoker'] ?? 0,
                $data['is_alcohol_consumer'] ?? 0,
                $data['emergency_medical_notes'] ?? null,
                $data['primary_care_physician'] ?? null,
                $data['physician_phone'] ?? null,
                $data['physician_address'] ?? null,
                $data['health_insurance_provider'] ?? null,
                $data['health_insurance_number'] ?? null,
                $data['nhis_number'] ?? null,
                $data['insurance_expiry_date'] ?? null,
                $data['insurance_notes'] ?? null,
                $data['medical_alerts'] ?? null,
                $data['privacy_level'] ?? 'staff_only',
                $data['is_verified'] ?? 0,
                $data['notes'] ?? null,
                $data['created_by'] ?? null
            ];

            error_log('HealthProfileRepository::create - Executing SQL');
            error_log('HealthProfileRepository::create - Params: ' . json_encode($params));

            $result = $this->db->execute($sql, $params);

            error_log('HealthProfileRepository::create - Execute result: ' . ($result ? 'true' : 'false'));

            if (!$result) {
                error_log('HealthProfileRepository::create - Execute returned false');
                return null;
            }

            $id = (int)$this->db->lastInsertId();

            error_log('HealthProfileRepository::create - Last insert ID: ' . $id);

            return $id > 0 ? $id : null;
        } catch (Exception $e) {
            error_log('HealthProfileRepository::create - Exception: ' . $e->getMessage());
            error_log('HealthProfileRepository::create - Trace: ' . $e->getTraceAsString());
            $this->logger->error('HealthProfileRepository::create error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get health profile by ID
     */
    public function getById(int $id): ?array
    {
        try {
            $sql = "SELECT hp.*, 
                    p.first_name, p.last_name, p.person_number,
                    t.tenant_name,
                    u.first_name as created_by_first, u.last_name as created_by_last
                    FROM health_profiles hp
                    LEFT JOIN people p ON hp.person_id = p.id AND p.deleted_at IS NULL
                    LEFT JOIN tenants t ON hp.tenant_id = t.id AND t.deleted_at IS NULL
                    LEFT JOIN platform_users u ON hp.created_by = u.id AND u.deleted_at IS NULL
                    WHERE hp.id = ? AND hp.deleted_at IS NULL";

            $result = $this->db->fetchOne($sql, [$id]);
            return $result ?: null;
        } catch (Exception $e) {
            $this->logger->error('HealthProfileRepository::getById error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get health profile by person ID (single)
     */
    public function getByPersonId(int $personId): ?array
    {
        try {
            $sql = "SELECT hp.*, 
                    p.first_name, p.last_name, p.person_number,
                    t.tenant_name
                    FROM health_profiles hp
                    LEFT JOIN people p ON hp.person_id = p.id AND p.deleted_at IS NULL
                    LEFT JOIN tenants t ON hp.tenant_id = t.id AND t.deleted_at IS NULL
                    WHERE hp.person_id = ? AND hp.deleted_at IS NULL
                    ORDER BY hp.created_at DESC
                    LIMIT 1";

            $result = $this->db->fetchOne($sql, [$personId]);
            return $result ?: null;
        } catch (Exception $e) {
            $this->logger->error('HealthProfileRepository::getByPersonId error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get health profiles by person ID (all)
     */
    public function getByPersonIdAll(int $personId): array
    {
        try {
            $sql = "SELECT hp.*, 
                    p.first_name, p.last_name, p.person_number,
                    t.tenant_name
                    FROM health_profiles hp
                    LEFT JOIN people p ON hp.person_id = p.id AND p.deleted_at IS NULL
                    LEFT JOIN tenants t ON hp.tenant_id = t.id AND t.deleted_at IS NULL
                    WHERE hp.person_id = ? AND hp.deleted_at IS NULL
                    ORDER BY hp.created_at DESC";

            return $this->db->fetchAll($sql, [$personId]);
        } catch (Exception $e) {
            $this->logger->error('HealthProfileRepository::getByPersonIdAll error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get health profile by UUID
     */
    public function getByUuid(string $uuid): ?array
    {
        try {
            $sql = "SELECT hp.*, 
                    p.first_name, p.last_name, p.person_number,
                    t.tenant_name
                    FROM health_profiles hp
                    LEFT JOIN people p ON hp.person_id = p.id AND p.deleted_at IS NULL
                    LEFT JOIN tenants t ON hp.tenant_id = t.id AND t.deleted_at IS NULL
                    WHERE hp.uuid = ? AND hp.deleted_at IS NULL";

            $result = $this->db->fetchOne($sql, [$uuid]);
            return $result ?: null;
        } catch (Exception $e) {
            $this->logger->error('HealthProfileRepository::getByUuid error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Update a health profile
     */
    public function update(int $id, array $data): bool
    {
        try {
            $updates = [];
            $params = [];

            $allowedFields = [
                'blood_group',
                'genotype',
                'height_cm',
                'weight_kg',
                'bmi',
                'allergies',
                'medical_conditions',
                'current_medications',
                'disabilities',
                'accessibility_requirements',
                'dietary_restrictions',
                'mental_health_conditions',
                'is_pregnant',
                'is_smoker',
                'is_alcohol_consumer',
                'emergency_medical_notes',
                'primary_care_physician',
                'physician_phone',
                'physician_address',
                'health_insurance_provider',
                'health_insurance_number',
                'nhis_number',
                'insurance_expiry_date',
                'insurance_notes',
                'medical_alerts',
                'privacy_level',
                'is_verified',
                'notes',
                'updated_by'
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($updates)) {
                return true;
            }

            $params[] = $id;
            $sql = "UPDATE health_profiles SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";

            error_log('HealthProfileRepository::update - SQL: ' . $sql);
            error_log('HealthProfileRepository::update - Params: ' . json_encode($params));

            return $this->db->execute($sql, $params);
        } catch (Exception $e) {
            error_log('HealthProfileRepository::update error: ' . $e->getMessage());
            $this->logger->error('HealthProfileRepository::update error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a health profile (soft delete)
     */
    public function delete(int $id): bool
    {
        try {
            $sql = "UPDATE health_profiles SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
            return $this->db->execute($sql, [$id]);
        } catch (Exception $e) {
            $this->logger->error('HealthProfileRepository::delete error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update health profile status
     */
    public function updateStatus(int $id, string $status): bool
    {
        try {
            $validStatuses = ['active', 'inactive', 'pending', 'completed', 'cancelled', 'expired'];
            if (!in_array($status, $validStatuses)) {
                error_log('HealthProfileRepository::updateStatus - Invalid status: ' . $status);
                return false;
            }

            $sql = "UPDATE health_profiles SET status = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
            return $this->db->execute($sql, [$status, $id]);
        } catch (Exception $e) {
            $this->logger->error('HealthProfileRepository::updateStatus error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * List health profiles with pagination and filters
     */
    public function list(array $filters, int $page = 1, int $limit = 20): array
    {
        try {
            $params = [];
            $where = ["hp.deleted_at IS NULL"];

            if (!empty($filters['person_id'])) {
                $where[] = "hp.person_id = ?";
                $params[] = (int)$filters['person_id'];
            }

            if (!empty($filters['tenant_id'])) {
                $where[] = "hp.tenant_id = ?";
                $params[] = (int)$filters['tenant_id'];
            }

            if (!empty($filters['status'])) {
                $where[] = "hp.status = ?";
                $params[] = $filters['status'];
            }

            if (!empty($filters['blood_group'])) {
                $where[] = "hp.blood_group = ?";
                $params[] = $filters['blood_group'];
            }

            if (!empty($filters['search'])) {
                $search = "%" . trim($filters['search']) . "%";
                $where[] = "(hp.allergies LIKE ? OR hp.medical_conditions LIKE ? OR hp.current_medications LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ?)";
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
            }

            $whereClause = "WHERE " . implode(" AND ", $where);
            $offset = ($page - 1) * $limit;

            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM health_profiles hp " . $whereClause;
            $countResult = $this->db->fetchOne($countSql, $params);
            $total = (int)($countResult['total'] ?? 0);

            // Get records
            $sql = "SELECT hp.*, 
                    p.first_name, p.last_name, p.person_number,
                    t.tenant_name
                    FROM health_profiles hp
                    LEFT JOIN people p ON hp.person_id = p.id AND p.deleted_at IS NULL
                    LEFT JOIN tenants t ON hp.tenant_id = t.id AND t.deleted_at IS NULL
                    " . $whereClause . "
                    ORDER BY hp.created_at DESC
                    LIMIT ? OFFSET ?";

            $params[] = $limit;
            $params[] = $offset;
            $records = $this->db->fetchAll($sql, $params);

            return [
                'records' => $records,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($total / $limit)
            ];
        } catch (Exception $e) {
            $this->logger->error('HealthProfileRepository::list error: ' . $e->getMessage());
            return [
                'records' => [],
                'total' => 0,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => 0
            ];
        }
    }

    /**
     * Get health statistics
     */
    public function getStats(?int $tenantId = null): array
    {
        try {
            $params = [];
            $where = ["deleted_at IS NULL"];

            if ($tenantId) {
                $where[] = "tenant_id = ?";
                $params[] = $tenantId;
            }

            $whereClause = "WHERE " . implode(" AND ", $where);

            $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) as verified,
                    SUM(CASE WHEN is_verified = 0 THEN 1 ELSE 0 END) as unverified,
                    SUM(CASE WHEN is_pregnant = 1 THEN 1 ELSE 0 END) as pregnant,
                    SUM(CASE WHEN is_smoker = 1 THEN 1 ELSE 0 END) as smokers,
                    SUM(CASE WHEN is_alcohol_consumer = 1 THEN 1 ELSE 0 END) as alcohol_consumers,
                    COUNT(DISTINCT health_insurance_provider) as insurance_providers,
                    COUNT(DISTINCT primary_care_physician) as physicians,
                    COUNT(DISTINCT blood_group) as blood_groups
                    FROM health_profiles
                    " . $whereClause;

            $result = $this->db->fetchOne($sql, $params);
            return $result ?: [];
        } catch (Exception $e) {
            $this->logger->error('HealthProfileRepository::getStats error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get counts by status
     */
    public function getCountsByStatus(?int $tenantId = null): array
    {
        try {
            $params = [];
            $where = ["deleted_at IS NULL"];

            if ($tenantId) {
                $where[] = "tenant_id = ?";
                $params[] = $tenantId;
            }

            $whereClause = "WHERE " . implode(" AND ", $where);

            $sql = "SELECT status, COUNT(*) as count
                    FROM health_profiles
                    " . $whereClause . "
                    GROUP BY status
                    ORDER BY count DESC";

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            $this->logger->error('HealthProfileRepository::getCountsByStatus error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get counts by blood group
     */
    public function getCountsByBloodGroup(?int $tenantId = null): array
    {
        try {
            $params = [];
            $where = ["deleted_at IS NULL", "blood_group IS NOT NULL"];

            if ($tenantId) {
                $where[] = "tenant_id = ?";
                $params[] = $tenantId;
            }

            $whereClause = "WHERE " . implode(" AND ", $where);

            $sql = "SELECT blood_group, COUNT(*) as count
                    FROM health_profiles
                    " . $whereClause . "
                    GROUP BY blood_group
                    ORDER BY count DESC";

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            $this->logger->error('HealthProfileRepository::getCountsByBloodGroup error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get health summary for dashboard
     */
    public function getSummary(?int $tenantId = null): array
    {
        try {
            $params = [];
            $where = ["deleted_at IS NULL"];

            if ($tenantId) {
                $where[] = "tenant_id = ?";
                $params[] = $tenantId;
            }

            $whereClause = "WHERE " . implode(" AND ", $where);

            $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) as verified,
                    SUM(CASE WHEN is_verified = 0 THEN 1 ELSE 0 END) as unverified,
                    COUNT(DISTINCT person_id) as unique_people,
                    COUNT(DISTINCT health_insurance_provider) as insurance_providers,
                    COUNT(DISTINCT primary_care_physician) as physicians,
                    COUNT(DISTINCT blood_group) as blood_groups,
                    SUM(CASE WHEN is_pregnant = 1 THEN 1 ELSE 0 END) as pregnant,
                    SUM(CASE WHEN is_smoker = 1 THEN 1 ELSE 0 END) as smokers,
                    SUM(CASE WHEN is_alcohol_consumer = 1 THEN 1 ELSE 0 END) as alcohol_consumers
                    FROM health_profiles
                    " . $whereClause;

            return $this->db->fetchOne($sql, $params) ?: [];
        } catch (Exception $e) {
            $this->logger->error('HealthProfileRepository::getSummary error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Search health profiles
     */
    public function search(array $filters, int $limit = 10): array
    {
        try {
            $params = [];
            $where = ["hp.deleted_at IS NULL"];

            if (!empty($filters['search'])) {
                $search = "%" . trim($filters['search']) . "%";
                $where[] = "(hp.allergies LIKE ? OR hp.medical_conditions LIKE ? OR hp.current_medications LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ?)";
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
            }

            if (!empty($filters['tenant_id'])) {
                $where[] = "hp.tenant_id = ?";
                $params[] = $filters['tenant_id'];
            }

            $whereClause = "WHERE " . implode(" AND ", $where);
            $params[] = $limit;

            $sql = "SELECT hp.id, hp.person_id, hp.blood_group, hp.genotype, hp.medical_conditions, hp.allergies,
                    p.first_name, p.last_name, p.person_number
                    FROM health_profiles hp
                    LEFT JOIN people p ON hp.person_id = p.id AND p.deleted_at IS NULL
                    " . $whereClause . "
                    ORDER BY hp.created_at DESC
                    LIMIT ?";

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            $this->logger->error('HealthProfileRepository::search error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Export health profiles
     */
    public function export(array $filters): array
    {
        try {
            $params = [];
            $where = ["hp.deleted_at IS NULL"];

            if (!empty($filters['tenant_id'])) {
                $where[] = "hp.tenant_id = ?";
                $params[] = $filters['tenant_id'];
            }

            if (!empty($filters['status'])) {
                $where[] = "hp.status = ?";
                $params[] = $filters['status'];
            }

            $whereClause = "WHERE " . implode(" AND ", $where);

            $sql = "SELECT hp.*, 
                    p.first_name, p.last_name, p.person_number,
                    t.tenant_name
                    FROM health_profiles hp
                    LEFT JOIN people p ON hp.person_id = p.id AND p.deleted_at IS NULL
                    LEFT JOIN tenants t ON hp.tenant_id = t.id AND t.deleted_at IS NULL
                    " . $whereClause . "
                    ORDER BY hp.created_at DESC";

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            $this->logger->error('HealthProfileRepository::export error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get health profiles by blood group
     */
    public function getByBloodGroup(string $bloodGroup, ?int $tenantId = null): array
    {
        try {
            $params = [];
            $where = ["deleted_at IS NULL", "blood_group = ?"];
            $params[] = $bloodGroup;

            if ($tenantId) {
                $where[] = "tenant_id = ?";
                $params[] = $tenantId;
            }

            $whereClause = "WHERE " . implode(" AND ", $where);

            $sql = "SELECT hp.*, 
                    p.first_name, p.last_name, p.person_number,
                    t.tenant_name
                    FROM health_profiles hp
                    LEFT JOIN people p ON hp.person_id = p.id AND p.deleted_at IS NULL
                    LEFT JOIN tenants t ON hp.tenant_id = t.id AND t.deleted_at IS NULL
                    " . $whereClause . "
                    ORDER BY hp.created_at DESC";

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            $this->logger->error('HealthProfileRepository::getByBloodGroup error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get health profiles with medical conditions
     */
    public function getWithMedicalConditions(?int $tenantId = null): array
    {
        try {
            $params = [];
            $where = ["deleted_at IS NULL", "medical_conditions IS NOT NULL", "medical_conditions != ''"];

            if ($tenantId) {
                $where[] = "tenant_id = ?";
                $params[] = $tenantId;
            }

            $whereClause = "WHERE " . implode(" AND ", $where);

            $sql = "SELECT hp.*, 
                    p.first_name, p.last_name, p.person_number,
                    t.tenant_name
                    FROM health_profiles hp
                    LEFT JOIN people p ON hp.person_id = p.id AND p.deleted_at IS NULL
                    LEFT JOIN tenants t ON hp.tenant_id = t.id AND t.deleted_at IS NULL
                    " . $whereClause . "
                    ORDER BY hp.created_at DESC";

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            $this->logger->error('HealthProfileRepository::getWithMedicalConditions error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get health profiles with allergies
     */
    public function getWithAllergies(?int $tenantId = null): array
    {
        try {
            $params = [];
            $where = ["deleted_at IS NULL", "allergies IS NOT NULL", "allergies != ''"];

            if ($tenantId) {
                $where[] = "tenant_id = ?";
                $params[] = $tenantId;
            }

            $whereClause = "WHERE " . implode(" AND ", $where);

            $sql = "SELECT hp.*, 
                    p.first_name, p.last_name, p.person_number,
                    t.tenant_name
                    FROM health_profiles hp
                    LEFT JOIN people p ON hp.person_id = p.id AND p.deleted_at IS NULL
                    LEFT JOIN tenants t ON hp.tenant_id = t.id AND t.deleted_at IS NULL
                    " . $whereClause . "
                    ORDER BY hp.created_at DESC";

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            $this->logger->error('HealthProfileRepository::getWithAllergies error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get health profiles by date range
     */
    public function getByDateRange(string $startDate, string $endDate, ?int $tenantId = null): array
    {
        try {
            $params = [];
            $where = ["deleted_at IS NULL", "created_at BETWEEN ? AND ?"];
            $params[] = $startDate;
            $params[] = $endDate;

            if ($tenantId) {
                $where[] = "tenant_id = ?";
                $params[] = $tenantId;
            }

            $whereClause = "WHERE " . implode(" AND ", $where);

            $sql = "SELECT hp.*, 
                    p.first_name, p.last_name, p.person_number,
                    t.tenant_name
                    FROM health_profiles hp
                    LEFT JOIN people p ON hp.person_id = p.id AND p.deleted_at IS NULL
                    LEFT JOIN tenants t ON hp.tenant_id = t.id AND t.deleted_at IS NULL
                    " . $whereClause . "
                    ORDER BY hp.created_at DESC";

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            $this->logger->error('HealthProfileRepository::getByDateRange error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if a person already has a health profile
     */
    public function personHasProfile(int $personId, ?int $tenantId = null): bool
    {
        try {
            $params = [];
            $where = ["deleted_at IS NULL", "person_id = ?"];
            $params[] = $personId;

            if ($tenantId) {
                $where[] = "tenant_id = ?";
                $params[] = $tenantId;
            }

            $whereClause = "WHERE " . implode(" AND ", $where);
            $sql = "SELECT id FROM health_profiles " . $whereClause . " LIMIT 1";
            $result = $this->db->fetchOne($sql, $params);
            return (bool)$result;
        } catch (Exception $e) {
            $this->logger->error('HealthProfileRepository::personHasProfile error: ' . $e->getMessage());
            return false;
        }
    }
}
