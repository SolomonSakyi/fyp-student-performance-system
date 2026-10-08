<?php

/**
 * StudentRegistrationService.php
 * Service for complete student registration with transaction support
 * 
 * @package EduTrack
 * @subpackage Services\Identity
 * @filepath app/services/Identity/StudentRegistrationService.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/repositories/Identity/PersonRepository.php';
require_once $projectRoot . 'app/repositories/Identity/StudentRepository.php';
require_once $projectRoot . 'app/repositories/Identity/GuardianRepository.php';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class StudentRegistrationService
{
    private $personRepo;
    private $studentRepo;
    private $guardianRepo;
    private $db;
    private $logger;

    public function __construct()
    {
        $this->personRepo = new PersonRepository();
        $this->studentRepo = new StudentRepository();
        $this->guardianRepo = new GuardianRepository();
        $this->db = DatabaseHelper::getInstance();
        $this->logger = LoggerHelper::getInstance();
    }

    /**
     * Register a new student - Atomic Transaction
     */
    public function register(array $payload): array
    {
        $this->db->beginTransaction();

        try {
            $this->validatePayload($payload);

            // 1. Create Person
            $personData = $this->preparePersonData($payload);
            $person = $this->personRepo->create($personData);
            $personId = $person['id'];

            $this->logger->info('Person created successfully', [
                'person_id' => $personId,
                'person_number' => $person['person_number']
            ]);

            // 2. Create Student Profile
            $studentData = $this->prepareStudentData($payload, $personId);
            $student = $this->studentRepo->create($personId, $studentData);
            $studentId = $student['id'];

            $this->logger->info('Student profile created successfully', [
                'student_id' => $studentId,
                'student_number' => $student['student_number']
            ]);

            // 3. Process Guardians
            $guardianIds = [];
            if (!empty($payload['guardians']) && is_array($payload['guardians'])) {
                foreach ($payload['guardians'] as $guardianData) {
                    $guardianId = $this->processGuardian($guardianData, $personId, $studentId);
                    $guardianIds[] = $guardianId;
                }
                $this->logger->info('Guardians processed', [
                    'guardian_count' => count($guardianIds),
                    'guardian_ids' => $guardianIds
                ]);
            }

            // 4. Create Admission Record
            if (!empty($payload['admission'])) {
                $this->createAdmissionRecord($studentId, $payload['admission']);
                $this->logger->info('Admission record created');
            }

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Student registered successfully',
                'data' => [
                    'person_id' => $personId,
                    'person_number' => $person['person_number'],
                    'student_id' => $studentId,
                    'student_number' => $student['student_number'],
                    'guardian_ids' => $guardianIds
                ]
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Student registration failed: ' . $e->getMessage());
            $this->logger->error('Payload: ' . json_encode($payload));
            throw new Exception('Student registration failed: ' . $e->getMessage());
        }
    }

    /**
     * Validate payload
     */
    private function validatePayload(array $payload): void
    {
        $required = ['person', 'student'];
        foreach ($required as $field) {
            if (empty($payload[$field])) {
                throw new Exception("Missing required field: {$field}");
            }
        }

        $personRequired = ['first_name', 'last_name'];
        foreach ($personRequired as $field) {
            if (empty($payload['person'][$field])) {
                throw new Exception("Missing required person field: {$field}");
            }
        }

        // Student required fields
        if (empty($payload['student']['student_status'])) {
            throw new Exception('student_status is required');
        }

        // Tenant and School context
        if (empty($payload['tenant_id']) || empty($payload['school_id'])) {
            throw new Exception('Tenant and School context are required');
        }

        // Validate email if provided
        if (
            !empty($payload['person']['primary_email']) &&
            !filter_var($payload['person']['primary_email'], FILTER_VALIDATE_EMAIL)
        ) {
            throw new Exception('Invalid email address');
        }

        // Validate date of birth if provided
        if (
            !empty($payload['person']['date_of_birth']) &&
            strtotime($payload['person']['date_of_birth']) > strtotime('now')
        ) {
            throw new Exception('Date of birth cannot be in the future');
        }
    }

    /**
     * Prepare person data - Matches your database schema
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
            'person_type' => 'student',
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
     * Prepare student data - Matches your actual table columns
     */
    private function prepareStudentData(array $payload, int $personId): array
    {
        $s = $payload['student'];
        return [
            'person_id' => $personId,
            'student_number' => $s['student_number'] ?? null,
            'school_id' => $payload['school_id'] ?? null,
            'campus_id' => $payload['campus_id'] ?? null,
            'level' => $s['level'] ?? null,
            'class' => $s['class'] ?? null,
            'programme' => $s['programme'] ?? null,
            'enrollment_status' => $s['enrollment_status'] ?? 'active',
            'student_status' => $s['student_status'] ?? 'active',
            'date_joined' => $s['date_joined'] ?? date('Y-m-d'),
            'entry_level' => $s['entry_level'] ?? null,
            'entry_class' => $s['entry_class'] ?? null,
            'created_by' => $payload['created_by'] ?? null
        ];
    }

    /**
     * Process guardian - Creates person, guardian profile, and relationship
     */
    private function processGuardian(array $guardianData, int $studentPersonId, int $studentId): int
    {
        // Check if guardian is an existing person
        if (!empty($guardianData['existing_person_id'])) {
            $personId = $guardianData['existing_person_id'];
        } else {
            // Create new person for guardian
            $personData = [
                'first_name' => $guardianData['first_name'] ?? '',
                'last_name' => $guardianData['last_name'] ?? '',
                'middle_name' => $guardianData['middle_name'] ?? null,
                'gender' => $guardianData['gender'] ?? null,
                'date_of_birth' => $guardianData['date_of_birth'] ?? null,
                'nationality' => $guardianData['nationality'] ?? null,
                'person_type' => 'guardian',
                'tenant_id' => $guardianData['tenant_id'] ?? 2,
                'school_id' => $guardianData['school_id'] ?? 1,
                'status' => 'active',
                'primary_phone' => $guardianData['phone'] ?? 'N/A',
                'primary_email' => $guardianData['email'] ?? 'no-email@example.com',
                'created_by' => $guardianData['created_by'] ?? null
            ];

            $person = $this->personRepo->create($personData);
            $personId = $person['id'];
        }

        // Create guardian profile
        $guardianProfile = $this->guardianRepo->create($personId, [
            'guardian_type' => $guardianData['guardian_type'] ?? 'parent',
            'created_by' => $guardianData['created_by'] ?? null
        ]);
        $guardianId = $guardianProfile['id'];

        // Create relationship
        $this->guardianRepo->createRelationship($studentId, $guardianId, [
            'relationship_type' => $guardianData['relationship_type'] ?? 'other',
            'is_primary_guardian' => $guardianData['is_primary_guardian'] ?? false,
            'is_emergency_contact' => $guardianData['is_emergency_contact'] ?? false,
            'notes' => $guardianData['notes'] ?? null,
            'created_by' => $guardianData['created_by'] ?? null
        ]);

        return $guardianId;
    }

    /**
     * Create admission record
     */
    private function createAdmissionRecord(int $studentId, array $admissionData): void
    {
        try {
            // Generate admission number if not provided
            if (empty($admissionData['admission_number'])) {
                $admissionData['admission_number'] = $this->generateAdmissionNumber();
            }

            $sql = "INSERT INTO admission_records (
                student_id, 
                admission_number, 
                admission_date, 
                admission_type,
                previous_school, 
                previous_class, 
                transfer_certificate_provided,
                entry_level, 
                entry_class, 
                enrollment_status, 
                created_by
            ) VALUES (
                :student_id, 
                :admission_number, 
                :admission_date, 
                :admission_type,
                :previous_school, 
                :previous_class, 
                :transfer_certificate_provided,
                :entry_level, 
                :entry_class, 
                :enrollment_status, 
                :created_by
            )";

            $params = [
                ':student_id' => $studentId,
                ':admission_number' => $admissionData['admission_number'],
                ':admission_date' => $admissionData['admission_date'] ?? date('Y-m-d'),
                ':admission_type' => $admissionData['admission_type'] ?? 'new_admission',
                ':previous_school' => $admissionData['previous_school'] ?? null,
                ':previous_class' => $admissionData['previous_class'] ?? null,
                ':transfer_certificate_provided' => $admissionData['transfer_certificate_provided'] ?? 0,
                ':entry_level' => $admissionData['entry_level'] ?? null,
                ':entry_class' => $admissionData['entry_class'] ?? null,
                ':enrollment_status' => $admissionData['enrollment_status'] ?? 'active',
                ':created_by' => $admissionData['created_by'] ?? null
            ];

            $this->db->execute($sql, $params);
        } catch (Exception $e) {
            $this->logger->warning('Admission record not created: ' . $e->getMessage());
        }
    }

    /**
     * Generate a unique admission number
     */
    private function generateAdmissionNumber(): string
    {
        $prefix = 'ADM';
        $year = date('Y');
        $maxNumber = $this->db->getValue(
            "SELECT MAX(CAST(SUBSTRING(admission_number, -6) AS UNSIGNED)) 
             FROM admission_records 
             WHERE admission_number LIKE ? AND deleted_at IS NULL",
            [$prefix . '-' . $year . '-%']
        );
        $nextId = ($maxNumber ?? 0) + 1;
        return $prefix . '-' . $year . '-' . str_pad($nextId, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Get a student by ID with all related data
     */
    public function getStudent(int $studentId): array
    {
        $student = $this->studentRepo->getFullDetails($studentId);
        if (empty($student)) {
            throw new Exception('Student not found');
        }

        // Get guardians
        $guardians = $this->guardianRepo->getStudentGuardians($studentId);

        return [
            'student' => $student,
            'guardians' => $guardians
        ];
    }

    /**
     * Get student by person ID
     */
    public function getStudentByPersonId(int $personId): ?array
    {
        return $this->studentRepo->getByPersonId($personId);
    }

    /**
     * Get student by student number
     */
    public function getStudentByNumber(string $studentNumber): array
    {
        return $this->studentRepo->getByStudentNumber($studentNumber);
    }

    /**
     * List students with pagination
     */
    public function listStudents(array $filters = [], int $page = 1, int $limit = 20): array
    {
        $students = $this->studentRepo->getAll($filters, $page, $limit);
        $total = $this->studentRepo->count($filters);

        return [
            'data' => $students,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($total / $limit)
        ];
    }

    /**
     * Update student
     */
    public function updateStudent(int $studentId, array $data): array
    {
        return $this->studentRepo->update($studentId, $data);
    }

    /**
     * Delete student
     */
    public function deleteStudent(int $studentId, int $deletedBy = null): bool
    {
        return $this->studentRepo->delete($studentId, $deletedBy);
    }

    /**
     * Add guardian to existing student
     */
    public function addGuardianToStudent(int $studentId, array $guardianData): int
    {
        $student = $this->studentRepo->getById($studentId);
        if (empty($student)) {
            throw new Exception('Student not found');
        }

        $personId = $guardianData['person_id'] ?? null;
        if (!$personId) {
            throw new Exception('Guardian person ID is required');
        }

        // Create relationship
        $relationship = $this->guardianRepo->createRelationship($studentId, $guardianData['guardian_id'], [
            'relationship_type' => $guardianData['relationship_type'] ?? 'other',
            'is_primary_guardian' => $guardianData['is_primary_guardian'] ?? false,
            'is_emergency_contact' => $guardianData['is_emergency_contact'] ?? false,
            'notes' => $guardianData['notes'] ?? null,
            'created_by' => $guardianData['created_by'] ?? null
        ]);

        return $relationship['id'];
    }

    /**
     * Remove guardian from student
     */
    public function removeGuardianFromStudent(int $relationshipId, int $deletedBy = null): bool
    {
        return $this->guardianRepo->deleteRelationship($relationshipId, $deletedBy);
    }
}
