<?php

/**
 * PersonRepository.php
 * Repository for person CRUD operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Identity
 * @filepath app/repositories/Identity/PersonRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/UuidHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class PersonRepository
{
    private $db;
    private $uuidHelper;
    private $logger;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->uuidHelper = new UuidHelper();
        $this->logger = LoggerHelper::getInstance();
    }

    public function generatePersonNumber(): string
    {
        $prefix = 'PER';
        $year = date('Y');
        $maxNumber = $this->db->getValue(
            "SELECT MAX(CAST(SUBSTRING(person_number, -6) AS UNSIGNED)) 
             FROM people 
             WHERE person_number LIKE ? AND deleted_at IS NULL",
            [$prefix . '-' . $year . '-%']
        );
        $nextId = ($maxNumber ?? 0) + 1;
        return $prefix . '-' . $year . '-' . str_pad($nextId, 6, '0', STR_PAD_LEFT);
    }

    public function personNumberExists(string $personNumber): bool
    {
        return (bool) $this->db->getValue(
            "SELECT COUNT(*) FROM people WHERE person_number = ? AND deleted_at IS NULL",
            [$personNumber]
        );
    }

    public function create(array $data): array
    {
        try {
            if (empty($data['person_number'])) {
                $data['person_number'] = $this->generatePersonNumber();
            }

            if ($this->personNumberExists($data['person_number'])) {
                throw new Exception('Person number already exists: ' . $data['person_number']);
            }

            $data['uuid'] = $this->uuidHelper->generate();

            $tenantId = $data['tenant_id'] ?? 2;
            $schoolId = $data['school_id'] ?? 1;
            $primaryPhone = $data['primary_phone'] ?? 'N/A';
            $primaryEmail = $data['primary_email'] ?? 'no-email@example.com';

            $this->validateTenantAndSchool($tenantId, $schoolId);

            $sql = "INSERT INTO people (
                uuid, school_id, first_name, middle_name, last_name, 
                preferred_name, previous_name, gender, date_of_birth,
                person_type, status, place_of_birth, nationality, 
                country_of_birth, region_of_birth, preferred_language,
                primary_phone, primary_email, tenant_id, person_number,
                is_active, created_by
            ) VALUES (
                :uuid, :school_id, :first_name, :middle_name, :last_name,
                :preferred_name, :previous_name, :gender, :date_of_birth,
                :person_type, :status, :place_of_birth, :nationality,
                :country_of_birth, :region_of_birth, :preferred_language,
                :primary_phone, :primary_email, :tenant_id, :person_number,
                :is_active, :created_by
            )";

            $params = [
                ':uuid' => $data['uuid'],
                ':school_id' => $schoolId,
                ':first_name' => $data['first_name'] ?? '',
                ':middle_name' => $data['middle_name'] ?? null,
                ':last_name' => $data['last_name'] ?? '',
                ':preferred_name' => $data['preferred_name'] ?? null,
                ':previous_name' => $data['previous_name'] ?? null,
                ':gender' => $data['gender'] ?? null,
                ':date_of_birth' => $data['date_of_birth'] ?? null,
                ':person_type' => $data['person_type'] ?? 'student',
                ':status' => $data['status'] ?? 'pending',
                ':place_of_birth' => $data['place_of_birth'] ?? null,
                ':nationality' => $data['nationality'] ?? null,
                ':country_of_birth' => $data['country_of_birth'] ?? null,
                ':region_of_birth' => $data['region_of_birth'] ?? null,
                ':preferred_language' => $data['preferred_language'] ?? 'en',
                ':primary_phone' => $primaryPhone,
                ':primary_email' => $primaryEmail,
                ':tenant_id' => $tenantId,
                ':person_number' => $data['person_number'],
                ':is_active' => 1,
                ':created_by' => $data['created_by'] ?? null
            ];

            $result = $this->db->execute($sql, $params);

            if (!$result) {
                throw new Exception('Database insert failed');
            }

            $personId = (int) $this->db->lastInsertId();

            if ($personId <= 0) {
                throw new Exception('Failed to create person - no ID returned');
            }

            $person = $this->getById($personId);

            if (empty($person)) {
                throw new Exception('Failed to retrieve created person');
            }

            return $person;
        } catch (Exception $e) {
            $this->logger->error('PersonRepository::create error: ' . $e->getMessage());
            throw $e;
        }
    }

    private function validateTenantAndSchool(int $tenantId, int $schoolId): void
    {
        $tenant = $this->db->fetchOne(
            "SELECT id FROM tenants WHERE id = ? AND deleted_at IS NULL",
            [$tenantId]
        );
        if (!$tenant) {
            throw new Exception('Tenant not found with ID: ' . $tenantId);
        }

        $school = $this->db->fetchOne(
            "SELECT id FROM schools WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$schoolId, $tenantId]
        );
        if (!$school) {
            throw new Exception('School not found with ID: ' . $schoolId);
        }
    }

    public function getById(int $personId): ?array
    {
        $sql = "SELECT * FROM people WHERE id = :id AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [':id' => $personId]);
    }

    public function getByUuid(string $uuid): ?array
    {
        $sql = "SELECT * FROM people WHERE uuid = :uuid AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [':uuid' => $uuid]);
    }

    public function update(int $personId, array $data): array
    {
        $fields = [];
        $params = [':id' => $personId];
        $allowedFields = [
            'first_name',
            'middle_name',
            'last_name',
            'preferred_name',
            'previous_name',
            'gender',
            'date_of_birth',
            'nationality',
            'country_of_birth',
            'region_of_birth',
            'preferred_language',
            'primary_phone',
            'primary_email',
            'status',
            'person_type'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($fields)) {
            throw new Exception('No fields to update');
        }

        $sql = "UPDATE people SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $this->db->execute($sql, $params);

        return $this->getById($personId);
    }

    public function delete(int $personId, int $deletedBy = null): bool
    {
        $sql = "UPDATE people SET deleted_at = NOW(), updated_by = :updated_by WHERE id = :id";
        return $this->db->execute($sql, [':id' => $personId, ':updated_by' => $deletedBy]);
    }

    public function restore(int $personId): bool
    {
        $sql = "UPDATE people SET deleted_at = NULL WHERE id = :id";
        return $this->db->execute($sql, [':id' => $personId]);
    }
}
