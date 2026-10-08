<?php

/**
 * StaffRepository.php
 * Repository for staff profile operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Identity
 * @filepath app/repositories/Identity/StaffRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/UuidHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class StaffRepository
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

    /**
     * Generate a unique staff number
     */
    public function generateStaffNumber(): string
    {
        $prefix = 'STF';
        $year = date('Y');
        $maxNumber = $this->db->getValue(
            "SELECT MAX(CAST(SUBSTRING(staff_number, -6) AS UNSIGNED)) 
             FROM staff_profiles 
             WHERE staff_number LIKE ? AND deleted_at IS NULL",
            [$prefix . '-' . $year . '-%']
        );
        $nextId = ($maxNumber ?? 0) + 1;
        return $prefix . '-' . $year . '-' . str_pad($nextId, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Check if staff number exists
     */
    public function staffNumberExists(string $staffNumber): bool
    {
        return (bool) $this->db->getValue(
            "SELECT COUNT(*) FROM staff_profiles WHERE staff_number = ? AND deleted_at IS NULL",
            [$staffNumber]
        );
    }

    /**
     * Create staff profile
     */
    public function create(int $personId, array $data): array
    {
        try {
            // Generate staff number if not provided
            if (empty($data['staff_number'])) {
                $data['staff_number'] = $this->generateStaffNumber();
            }

            // Validate uniqueness
            if ($this->staffNumberExists($data['staff_number'])) {
                throw new Exception('Staff number already exists: ' . $data['staff_number']);
            }

            // Generate UUID
            $data['staff_uuid'] = $this->uuidHelper->generate();

            // Check if staff profile already exists
            $existing = $this->getByPersonId($personId);
            if ($existing) {
                throw new Exception('Staff profile already exists for this person');
            }

            // Build SQL with only columns that exist
            $sql = "INSERT INTO staff_profiles (
                person_id, 
                staff_number,
                staff_uuid,
                staff_type,
                employment_status,
                employment_type,
                employment_date,
                job_title,
                profession,
                years_of_experience,
                created_by
            ) VALUES (
                :person_id, 
                :staff_number,
                :staff_uuid,
                :staff_type,
                :employment_status,
                :employment_type,
                :employment_date,
                :job_title,
                :profession,
                :years_of_experience,
                :created_by
            )";

            $params = [
                ':person_id' => $personId,
                ':staff_number' => $data['staff_number'],
                ':staff_uuid' => $data['staff_uuid'],
                ':staff_type' => $data['staff_type'] ?? 'teaching',
                ':employment_status' => $data['employment_status'] ?? 'active',
                ':employment_type' => $data['employment_type'] ?? 'permanent',
                ':employment_date' => $data['employment_date'] ?? date('Y-m-d'),
                ':job_title' => $data['job_title'] ?? null,
                ':profession' => $data['profession'] ?? null,
                ':years_of_experience' => $data['years_of_experience'] ?? 0,
                ':created_by' => $data['created_by'] ?? null
            ];

            // Try to add optional fields if they exist in the table
            $optionalFields = [];

            if ($this->columnExists('staff_profiles', 'staff_category_id')) {
                $optionalFields[] = 'staff_category_id = :staff_category_id';
                $params[':staff_category_id'] = $data['staff_category_id'] ?? null;
            }

            if ($this->columnExists('staff_profiles', 'probation_start')) {
                $optionalFields[] = 'probation_start = :probation_start';
                $params[':probation_start'] = $data['probation_start'] ?? null;
            }

            if ($this->columnExists('staff_profiles', 'probation_end')) {
                $optionalFields[] = 'probation_end = :probation_end';
                $params[':probation_end'] = $data['probation_end'] ?? null;
            }

            if ($this->columnExists('staff_profiles', 'confirmation_date')) {
                $optionalFields[] = 'confirmation_date = :confirmation_date';
                $params[':confirmation_date'] = $data['confirmation_date'] ?? null;
            }

            if ($this->columnExists('staff_profiles', 'contract_start')) {
                $optionalFields[] = 'contract_start = :contract_start';
                $params[':contract_start'] = $data['contract_start'] ?? null;
            }

            if ($this->columnExists('staff_profiles', 'contract_end')) {
                $optionalFields[] = 'contract_end = :contract_end';
                $params[':contract_end'] = $data['contract_end'] ?? null;
            }

            if ($this->columnExists('staff_profiles', 'department_id')) {
                $optionalFields[] = 'department_id = :department_id';
                $params[':department_id'] = $data['department_id'] ?? null;
            }

            if ($this->columnExists('staff_profiles', 'position_id')) {
                $optionalFields[] = 'position_id = :position_id';
                $params[':position_id'] = $data['position_id'] ?? null;
            }

            if ($this->columnExists('staff_profiles', 'job_grade')) {
                $optionalFields[] = 'job_grade = :job_grade';
                $params[':job_grade'] = $data['job_grade'] ?? null;
            }

            if ($this->columnExists('staff_profiles', 'campus_id')) {
                $optionalFields[] = 'campus_id = :campus_id';
                $params[':campus_id'] = $data['campus_id'] ?? null;
            }

            // If there are optional fields, rebuild SQL
            if (!empty($optionalFields)) {
                $sql = "INSERT INTO staff_profiles (
                    person_id, 
                    staff_number,
                    staff_uuid,
                    staff_type,
                    employment_status,
                    employment_type,
                    employment_date,
                    job_title,
                    profession,
                    years_of_experience,
                    " . implode(', ', array_keys($optionalFields)) . ",
                    created_by
                ) VALUES (
                    :person_id, 
                    :staff_number,
                    :staff_uuid,
                    :staff_type,
                    :employment_status,
                    :employment_type,
                    :employment_date,
                    :job_title,
                    :profession,
                    :years_of_experience,
                    " . implode(', ', array_keys($optionalFields)) . ",
                    :created_by
                )";
            }

            $this->logger->debug('Staff create SQL: ' . $sql);
            $this->logger->debug('Staff create params: ' . json_encode($params));

            $result = $this->db->execute($sql, $params);

            if (!$result) {
                $errorInfo = $this->db->getConnection()->errorInfo();
                throw new Exception('Database insert failed for staff profile: ' . ($errorInfo[2] ?? 'Unknown error'));
            }

            $staffId = (int) $this->db->lastInsertId();

            if ($staffId <= 0) {
                throw new Exception('Failed to create staff profile - no ID returned');
            }

            // Get the created staff profile
            $staff = $this->getById($staffId);

            if (empty($staff)) {
                throw new Exception('Failed to retrieve created staff profile');
            }

            return $staff;
        } catch (Exception $e) {
            $this->logger->error('StaffRepository::create error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check if a column exists in a table
     */
    private function columnExists(string $table, string $column): bool
    {
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM information_schema.columns 
             WHERE table_name = ? AND column_name = ? AND table_schema = DATABASE()",
            [$table, $column]
        );
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Get staff profile by ID
     */
    public function getById(int $staffId): ?array
    {
        $sql = "SELECT * FROM staff_profiles WHERE id = :id AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [':id' => $staffId]);
    }

    /**
     * Get staff profile by person ID
     */
    public function getByPersonId(int $personId): ?array
    {
        $sql = "SELECT * FROM staff_profiles WHERE person_id = :person_id AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [':person_id' => $personId]);
    }

    /**
     * Get staff profile by staff number
     */
    public function getByStaffNumber(string $staffNumber): ?array
    {
        $sql = "SELECT * FROM staff_profiles WHERE staff_number = :staff_number AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [':staff_number' => $staffNumber]);
    }

    /**
     * Update staff profile
     */
    public function update(int $staffId, array $data): array
    {
        $fields = [];
        $params = [':id' => $staffId];
        $allowedFields = [
            'staff_category_id',
            'staff_type',
            'employment_status',
            'employment_type',
            'employment_date',
            'probation_start',
            'probation_end',
            'confirmation_date',
            'contract_start',
            'contract_end',
            'department_id',
            'position_id',
            'job_title',
            'job_grade',
            'profession',
            'years_of_experience',
            'work_location',
            'campus_id'
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

        $sql = "UPDATE staff_profiles SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $this->db->execute($sql, $params);

        return $this->getById($staffId);
    }

    /**
     * Delete staff profile
     */
    public function delete(int $staffId, int $deletedBy = null): bool
    {
        $sql = "UPDATE staff_profiles SET deleted_at = NOW(), updated_by = :updated_by WHERE id = :id";
        return $this->db->execute($sql, [':id' => $staffId, ':updated_by' => $deletedBy]);
    }

    /**
     * Get staff with person details
     */
    public function getFullDetails(int $staffId): array
    {
        $sql = "SELECT 
                    sp.*, 
                    p.id as person_id,
                    p.first_name, p.last_name, p.middle_name,
                    p.person_number, p.uuid,
                    CONCAT(p.first_name, ' ', p.last_name) as full_name
                FROM staff_profiles sp
                INNER JOIN people p ON sp.person_id = p.id
                WHERE sp.id = :id AND sp.deleted_at IS NULL AND p.deleted_at IS NULL";

        $result = $this->db->fetchOne($sql, [':id' => $staffId]);

        if (!$result) {
            throw new Exception('Staff details not found');
        }

        return $result;
    }

    /**
     * Add qualification to staff
     */
    public function addQualification(int $staffId, array $data): array
    {
        $sql = "INSERT INTO staff_qualifications (
            staff_id, qualification_type, qualification_name, institution,
            field_of_study, country, start_date, completion_date,
            graduation_year, grade_class, certificate_number,
            verification_status, notes, created_by
        ) VALUES (
            :staff_id, :qualification_type, :qualification_name, :institution,
            :field_of_study, :country, :start_date, :completion_date,
            :graduation_year, :grade_class, :certificate_number,
            :verification_status, :notes, :created_by
        )";

        $params = [
            ':staff_id' => $staffId,
            ':qualification_type' => $data['qualification_type'] ?? 'other',
            ':qualification_name' => $data['qualification_name'] ?? '',
            ':institution' => $data['institution'] ?? '',
            ':field_of_study' => $data['field_of_study'] ?? null,
            ':country' => $data['country'] ?? null,
            ':start_date' => $data['start_date'] ?? null,
            ':completion_date' => $data['completion_date'] ?? null,
            ':graduation_year' => $data['graduation_year'] ?? null,
            ':grade_class' => $data['grade_class'] ?? null,
            ':certificate_number' => $data['certificate_number'] ?? null,
            ':verification_status' => $data['verification_status'] ?? 'pending',
            ':notes' => $data['notes'] ?? null,
            ':created_by' => $data['created_by'] ?? null
        ];

        $this->db->execute($sql, $params);
        $qualificationId = $this->db->lastInsertId();

        $sql = "SELECT * FROM staff_qualifications WHERE id = :id";
        return $this->db->fetchOne($sql, [':id' => $qualificationId]);
    }

    /**
     * Get staff qualifications
     */
    public function getQualifications(int $staffId): array
    {
        $sql = "SELECT * FROM staff_qualifications WHERE staff_id = :staff_id AND deleted_at IS NULL ORDER BY completion_date DESC";
        return $this->db->fetchAll($sql, [':staff_id' => $staffId]);
    }

    /**
     * Add employment history
     */
    public function addEmploymentHistory(int $staffId, array $data): array
    {
        $sql = "INSERT INTO staff_employment_history (
            staff_id, employer, position, department, location,
            start_date, end_date, responsibilities, reason_for_leaving,
            reference_contact, is_current, created_by
        ) VALUES (
            :staff_id, :employer, :position, :department, :location,
            :start_date, :end_date, :responsibilities, :reason_for_leaving,
            :reference_contact, :is_current, :created_by
        )";

        $params = [
            ':staff_id' => $staffId,
            ':employer' => $data['employer'] ?? '',
            ':position' => $data['position'] ?? '',
            ':department' => $data['department'] ?? null,
            ':location' => $data['location'] ?? null,
            ':start_date' => $data['start_date'] ?? null,
            ':end_date' => $data['end_date'] ?? null,
            ':responsibilities' => $data['responsibilities'] ?? null,
            ':reason_for_leaving' => $data['reason_for_leaving'] ?? null,
            ':reference_contact' => $data['reference_contact'] ?? null,
            ':is_current' => $data['is_current'] ?? 0,
            ':created_by' => $data['created_by'] ?? null
        ];

        $this->db->execute($sql, $params);
        $historyId = $this->db->lastInsertId();

        $sql = "SELECT * FROM staff_employment_history WHERE id = :id";
        return $this->db->fetchOne($sql, [':id' => $historyId]);
    }

    /**
     * Get staff employment history
     */
    public function getEmploymentHistory(int $staffId): array
    {
        $sql = "SELECT * FROM staff_employment_history WHERE staff_id = :staff_id AND deleted_at IS NULL ORDER BY start_date DESC";
        return $this->db->fetchAll($sql, [':staff_id' => $staffId]);
    }
}
