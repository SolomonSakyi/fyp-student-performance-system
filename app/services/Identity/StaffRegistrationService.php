<?php

/**
 * StaffRegistrationService.php
 * Service for complete staff registration with transaction support
 * 
 * @package EduTrack
 * @subpackage Services\Identity
 * @filepath app/services/Identity/StaffRegistrationService.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/repositories/Identity/PersonRepository.php';
require_once $projectRoot . 'app/repositories/Identity/StaffRepository.php';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class StaffRegistrationService
{
    private $personRepo;
    private $staffRepo;
    private $db;
    private $logger;

    public function __construct()
    {
        $this->personRepo = new PersonRepository();
        $this->staffRepo = new StaffRepository();
        $this->db = DatabaseHelper::getInstance();
        $this->logger = LoggerHelper::getInstance();
    }

    /**
     * Register a new staff member - Atomic Transaction
     */
    public function register(array $payload): array
    {
        $this->db->beginTransaction();

        try {
            // Validate payload
            $this->validatePayload($payload);

            // 1. Create Person
            $personData = $this->preparePersonData($payload);
            $person = $this->personRepo->create($personData);
            $personId = $person['id'];

            $this->logger->info('Person created successfully', [
                'person_id' => $personId,
                'person_number' => $person['person_number']
            ]);

            // 2. Create Staff Profile
            $staffData = $this->prepareStaffData($payload, $personId);
            $staff = $this->staffRepo->create($personId, $staffData);
            $staffId = $staff['id'];

            $this->logger->info('Staff profile created successfully', [
                'staff_id' => $staffId,
                'staff_number' => $staff['staff_number']
            ]);

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Staff registered successfully',
                'data' => [
                    'person_id' => $personId,
                    'person_number' => $person['person_number'],
                    'staff_id' => $staffId,
                    'staff_number' => $staff['staff_number']
                ]
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Staff registration failed: ' . $e->getMessage());
            $this->logger->error('Payload: ' . json_encode($payload));
            throw new Exception('Staff registration failed: ' . $e->getMessage());
        }
    }

    /**
     * Validate payload - FIXED: Proper validation
     */
    private function validatePayload(array $payload): void
    {
        $errors = [];

        // Check person exists
        if (empty($payload['person'])) {
            $errors[] = 'person is required';
        } else {
            if (empty($payload['person']['first_name'])) {
                $errors[] = 'first_name is required';
            }
            if (empty($payload['person']['last_name'])) {
                $errors[] = 'last_name is required';
            }
        }

        // Check staff exists
        if (empty($payload['staff'])) {
            $errors[] = 'staff is required';
        } else {
            if (empty($payload['staff']['staff_type'])) {
                $errors[] = 'staff_type is required';
            }
            if (empty($payload['staff']['employment_status'])) {
                $errors[] = 'employment_status is required';
            }
            // Support both employment_date and hire_date
            if (empty($payload['staff']['employment_date']) && empty($payload['staff']['hire_date'])) {
                $errors[] = 'employment_date is required';
            }
        }

        // Check tenant and school
        if (empty($payload['tenant_id'])) {
            $errors[] = 'tenant_id is required';
        }
        if (empty($payload['school_id'])) {
            $errors[] = 'school_id is required';
        }

        if (!empty($errors)) {
            throw new Exception('Missing required fields: ' . implode(', ', $errors));
        }
    }

    /**
     * Prepare person data
     */
    private function preparePersonData(array $payload): array
    {
        $p = $payload['person'];
        return [
            'first_name' => $p['first_name'] ?? '',
            'middle_name' => $p['middle_name'] ?? null,
            'last_name' => $p['last_name'] ?? '',
            'preferred_name' => $p['preferred_name'] ?? null,
            'previous_name' => $p['previous_name'] ?? null,
            'date_of_birth' => $p['date_of_birth'] ?? null,
            'place_of_birth' => $p['place_of_birth'] ?? null,
            'gender' => $p['gender'] ?? null,
            'nationality' => $p['nationality'] ?? null,
            'country_of_birth' => $p['country_of_birth'] ?? null,
            'region_of_birth' => $p['region_of_birth'] ?? null,
            'preferred_language' => $p['preferred_language'] ?? 'en',
            'person_type' => 'staff',
            'tenant_id' => $payload['tenant_id'] ?? 2,
            'school_id' => $payload['school_id'] ?? 1,
            'campus_id' => $payload['campus_id'] ?? null,
            'status' => 'active',
            'primary_phone' => $p['primary_phone'] ?? 'N/A',
            'primary_email' => $p['primary_email'] ?? 'no-email@example.com',
            'created_by' => $payload['created_by'] ?? null
        ];
    }

    /**
     * Prepare staff data - Supports both employment_date and hire_date
     */
    private function prepareStaffData(array $payload, int $personId): array
    {
        $s = $payload['staff'];

        // Support both employment_date and hire_date
        $employmentDate = $s['employment_date'] ?? $s['hire_date'] ?? date('Y-m-d');

        return [
            'person_id' => $personId,
            'staff_number' => $s['staff_number'] ?? null,
            'staff_category_id' => $s['staff_category_id'] ?? null,
            'staff_type' => $s['staff_type'] ?? 'teaching',
            'employment_status' => $s['employment_status'] ?? 'active',
            'employment_type' => $s['employment_type'] ?? 'permanent',
            'employment_date' => $employmentDate,
            'probation_start' => $s['probation_start'] ?? null,
            'probation_end' => $s['probation_end'] ?? null,
            'confirmation_date' => $s['confirmation_date'] ?? null,
            'contract_start' => $s['contract_start'] ?? null,
            'contract_end' => $s['contract_end'] ?? null,
            'department_id' => $s['department_id'] ?? null,
            'position_id' => $s['position_id'] ?? null,
            'job_title' => $s['job_title'] ?? null,
            'job_grade' => $s['job_grade'] ?? null,
            'supervisor_id' => $s['supervisor_id'] ?? null,
            'reporting_manager_id' => $s['reporting_manager_id'] ?? null,
            'profession' => $s['profession'] ?? null,
            'specialization' => $s['specialization'] ?? null,
            'professional_body' => $s['professional_body'] ?? null,
            'professional_registration_number' => $s['professional_registration_number'] ?? null,
            'professional_licence_number' => $s['professional_licence_number'] ?? null,
            'licence_issue_date' => $s['licence_issue_date'] ?? null,
            'licence_expiry_date' => $s['licence_expiry_date'] ?? null,
            'professional_status' => $s['professional_status'] ?? 'active',
            'years_of_experience' => $s['years_of_experience'] ?? 0,
            'work_location' => $s['work_location'] ?? null,
            'campus_id' => $s['campus_id'] ?? null,
            'is_class_teacher' => $s['is_class_teacher'] ?? 0,
            'teaching_specialization' => $s['teaching_specialization'] ?? null,
            'subjects_taught' => $s['subjects_taught'] ?? null,
            'levels_taught' => $s['levels_taught'] ?? null,
            'created_by' => $payload['created_by'] ?? null
        ];
    }

    /**
     * Get staff by ID
     */
    public function getStaff(int $staffId): array
    {
        return $this->staffRepo->getFullDetails($staffId);
    }

    /**
     * Get staff by person ID
     */
    public function getStaffByPersonId(int $personId): ?array
    {
        return $this->staffRepo->getByPersonId($personId);
    }

    /**
     * Get staff by staff number
     */
    public function getStaffByNumber(string $staffNumber): array
    {
        return $this->staffRepo->getByStaffNumber($staffNumber);
    }

    /**
     * Update staff
     */
    public function updateStaff(int $staffId, array $data): array
    {
        return $this->staffRepo->update($staffId, $data);
    }

    /**
     * Delete staff
     */
    public function deleteStaff(int $staffId, int $deletedBy = null): bool
    {
        return $this->staffRepo->delete($staffId, $deletedBy);
    }

    /**
     * Get staff qualifications
     */
    public function getQualifications(int $staffId): array
    {
        return $this->staffRepo->getQualifications($staffId);
    }

    /**
     * Get staff employment history
     */
    public function getEmploymentHistory(int $staffId): array
    {
        return $this->staffRepo->getEmploymentHistory($staffId);
    }

    /**
     * Add qualification
     */
    public function addQualification(int $staffId, array $data): array
    {
        return $this->staffRepo->addQualification($staffId, $data);
    }

    /**
     * Add employment history
     */
    public function addEmploymentHistory(int $staffId, array $data): array
    {
        return $this->staffRepo->addEmploymentHistory($staffId, $data);
    }
}
