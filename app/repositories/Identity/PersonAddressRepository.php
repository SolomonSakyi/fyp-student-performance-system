<?php

/**
 * PersonAddressRepository.php
 * Repository for person address operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Identity
 * @filepath app/repositories/Identity/PersonAddressRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class PersonAddressRepository
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Create a new address for a person
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO person_addresses (
            person_id, address_type_id, address_line_1, address_line_2,
            city, district, region, state_province, postal_code,
            country, digital_address, latitude, longitude, is_primary,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $params = [
            $data['person_id'],
            $data['address_type_id'],
            $data['address_line_1'],
            $data['address_line_2'] ?? null,
            $data['city'] ?? null,
            $data['district'] ?? null,
            $data['region'] ?? null,
            $data['state_province'] ?? null,
            $data['postal_code'] ?? null,
            $data['country'] ?? 'Ghana',
            $data['digital_address'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['is_primary'] ?? 0
        ];

        $this->db->execute($sql, $params);
        return $this->db->lastInsertId();
    }

    /**
     * Get addresses by person ID
     */
    public function getByPersonId(int $personId): array
    {
        $sql = "SELECT pa.*, at.type_code, at.type_name 
                FROM person_addresses pa
                INNER JOIN address_types at ON pa.address_type_id = at.id
                WHERE pa.person_id = ? AND pa.deleted_at IS NULL
                ORDER BY pa.is_primary DESC, at.sort_order ASC";
        return $this->db->fetchAll($sql, [$personId]);
    }

    /**
     * Get address by ID
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT pa.*, at.type_code, at.type_name 
                FROM person_addresses pa
                INNER JOIN address_types at ON pa.address_type_id = at.id
                WHERE pa.id = ? AND pa.deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$id]);
    }

    /**
     * Get primary address by person ID
     */
    public function getPrimaryByPersonId(int $personId): ?array
    {
        $sql = "SELECT pa.*, at.type_code, at.type_name 
                FROM person_addresses pa
                INNER JOIN address_types at ON pa.address_type_id = at.id
                WHERE pa.person_id = ? AND pa.is_primary = 1 AND pa.deleted_at IS NULL
                LIMIT 1";
        return $this->db->fetchOne($sql, [$personId]);
    }

    /**
     * Update an address
     */
    public function update(int $id, array $data): bool
    {
        $updates = [];
        $params = [];

        $allowedFields = [
            'address_type_id',
            'address_line_1',
            'address_line_2',
            'city',
            'district',
            'region',
            'state_province',
            'postal_code',
            'country',
            'digital_address',
            'latitude',
            'longitude',
            'is_primary'
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

        $params[] = $id;
        $sql = "UPDATE person_addresses SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, $params);
    }

    /**
     * Set an address as primary (unset others for the same person)
     */
    public function setPrimary(int $personId, int $addressId): bool
    {
        // Unset all primary for this person
        $sql = "UPDATE person_addresses SET is_primary = 0, updated_at = NOW() WHERE person_id = ? AND deleted_at IS NULL";
        $this->db->execute($sql, [$personId]);

        // Set this address as primary
        $sql = "UPDATE person_addresses SET is_primary = 1, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$addressId]);
    }

    /**
     * Soft delete an address
     */
    public function delete(int $id): bool
    {
        $sql = "UPDATE person_addresses SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }

    /**
     * Get addresses by region
     */
    public function getByRegion(string $region, int $tenantId): array
    {
        $sql = "SELECT pa.*, p.id as person_id, p.first_name, p.last_name, p.person_number
                FROM person_addresses pa
                INNER JOIN people p ON pa.person_id = p.id
                INNER JOIN person_organizational_assignments poa ON p.id = poa.person_id
                WHERE pa.region = ? 
                AND poa.tenant_id = ? 
                AND poa.deleted_at IS NULL 
                AND pa.deleted_at IS NULL 
                AND p.deleted_at IS NULL
                LIMIT 100";
        return $this->db->fetchAll($sql, [$region, $tenantId]);
    }

    /**
     * Get addresses by country
     */
    public function getByCountry(string $country, int $tenantId): array
    {
        $sql = "SELECT pa.*, p.id as person_id, p.first_name, p.last_name, p.person_number
                FROM person_addresses pa
                INNER JOIN people p ON pa.person_id = p.id
                INNER JOIN person_organizational_assignments poa ON p.id = poa.person_id
                WHERE pa.country = ? 
                AND poa.tenant_id = ? 
                AND poa.deleted_at IS NULL 
                AND pa.deleted_at IS NULL 
                AND p.deleted_at IS NULL
                LIMIT 100";
        return $this->db->fetchAll($sql, [$country, $tenantId]);
    }
}
