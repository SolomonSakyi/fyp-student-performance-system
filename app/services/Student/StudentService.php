<?php

/**
 * StudentService.php
 * Service for student management operations
 * 
 * @package EduTrack
 * @subpackage Services\Student
 * @filepath app/services/Student/StudentService.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/repositories/Student/StudentRepository.php';
require_once $projectRoot . 'app/repositories/Student/GuardianRepository.php';
require_once $projectRoot . 'app/repositories/Student/StudentAdmissionRepository.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class StudentService
{
    private $studentRepo;
    private $guardianRepo;
    private $admissionRepo;
    private $logger;

    public function __construct()
    {
        $this->studentRepo = new StudentRepository();
        $this->guardianRepo = new GuardianRepository();
        $this->admissionRepo = new StudentAdmissionRepository();
        $this->logger = new LoggerHelper();
    }

    /**
     * Register a student (creates person + student profile)
     */
    public function registerStudent(array $data): array
    {
        try {
            $this->logger->info('Registering student', ['data' => $data]);

            // Validate required fields
            $required = ['first_name', 'last_name', 'tenant_id', 'school_id'];
            $missing = [];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $missing[] = $field;
                }
            }
            if (!empty($missing)) {
                return ['success' => false, 'message' => 'Missing required fields: ' . implode(', ', $missing)];
            }

            // Start transaction
            $db = DatabaseHelper::getInstance();
            $db->beginTransaction();

            // Create person
            $personId = $this->createPerson($data);
            if (!$personId) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Failed to create person'];
            }

            // Generate student number
            $studentNumber = $this->studentRepo->generateStudentNumber($data['tenant_id'], $data['school_id']);
            if (!$studentNumber) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Failed to generate student number'];
            }

            // Create student profile
            $studentData = [
                'person_id' => $personId,
                'tenant_id' => $data['tenant_id'],
                'school_id' => $data['school_id'],
                'campus_id' => $data['campus_id'] ?? null,
                'student_number' => $studentNumber,
                'admission_date' => $data['admission_date'] ?? date('Y-m-d'),
                'admission_type' => $data['admission_type'] ?? 'new',
                'enrollment_status' => $data['enrollment_status'] ?? 'active',
                'enrollment_date' => $data['enrollment_date'] ?? date('Y-m-d'),
                'academic_year_id' => $data['academic_year_id'] ?? null,
                'class_id' => $data['class_id'] ?? null,
                'grade_level_id' => $data['grade_level_id'] ?? null,
                'program_id' => $data['program_id'] ?? null,
                'is_boarder' => $data['is_boarder'] ?? 0,
                'house_id' => $data['house_id'] ?? null,
                'previous_school' => $data['previous_school'] ?? null,
                'transfer_reason' => $data['transfer_reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? 1
            ];

            $studentProfileId = $this->studentRepo->create($studentData);
            if (!$studentProfileId) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Failed to create student profile'];
            }

            // Create admission record
            $admissionData = [
                'student_person_id' => $personId,
                'tenant_id' => $data['tenant_id'],
                'admission_date' => $data['admission_date'] ?? date('Y-m-d'),
                'admission_type' => $data['admission_type'] ?? 'new',
                'admission_year' => $data['admission_year'] ?? date('Y'),
                'application_date' => $data['application_date'] ?? null,
                'application_number' => $data['application_number'] ?? null,
                'entry_level' => $data['entry_level'] ?? null,
                'entry_class' => $data['entry_class'] ?? null,
                'previous_school' => $data['previous_school'] ?? null,
                'transfer_reason' => $data['transfer_reason'] ?? null,
                'placement_test_score' => $data['placement_test_score'] ?? null,
                'interview_notes' => $data['interview_notes'] ?? null,
                'admission_status' => $data['admission_status'] ?? 'enrolled',
                'offer_letter_sent' => $data['offer_letter_sent'] ?? 0,
                'acceptance_date' => $data['acceptance_date'] ?? null,
                'enrollment_date' => $data['enrollment_date'] ?? date('Y-m-d'),
                'notes' => $data['admission_notes'] ?? null,
                'created_by' => $data['created_by'] ?? 1
            ];

            $this->admissionRepo->create($admissionData);

            // Link guardians if provided
            if (!empty($data['guardians'])) {
                foreach ($data['guardians'] as $guardian) {
                    $this->linkGuardian($personId, $guardian, $data['tenant_id'], $data['created_by'] ?? 1);
                }
            }

            $db->commit();

            // Get the complete student record
            $student = $this->studentRepo->getByPersonId($personId);

            return [
                'success' => true,
                'message' => 'Student registered successfully',
                'data' => $student
            ];
        } catch (Exception $e) {
            $this->logger->error('StudentService::registerStudent error: ' . $e->getMessage());
            if (isset($db)) $db->rollBack();
            return ['success' => false, 'message' => 'Error registering student: ' . $e->getMessage()];
        }
    }

    /**
     * Create person from student data
     */
    private function createPerson(array $data): ?int
    {
        $db = DatabaseHelper::getInstance();
        $uuid = bin2hex(random_bytes(16));

        $sql = "INSERT INTO people (
            uuid, tenant_id, school_id, first_name, middle_name, last_name,
            preferred_name, gender, date_of_birth, place_of_birth, nationality,
            primary_phone, primary_email, person_type, status, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $db->execute($sql, [
            $uuid,
            $data['tenant_id'],
            $data['school_id'],
            $data['first_name'],
            $data['middle_name'] ?? null,
            $data['last_name'],
            $data['preferred_name'] ?? null,
            $data['gender'] ?? null,
            $data['date_of_birth'] ?? null,
            $data['place_of_birth'] ?? null,
            $data['nationality'] ?? null,
            $data['primary_phone'] ?? null,
            $data['primary_email'] ?? null,
            'student',
            $data['status'] ?? 'active',
            $data['created_by'] ?? 1
        ]);

        return $db->lastInsertId();
    }

    /**
     * Link a guardian to a student
     */
    public function linkGuardian(int $studentPersonId, array $guardianData, int $tenantId, int $createdBy): array
    {
        try {
            // Check if guardian person already exists
            $guardianPersonId = null;
            if (!empty($guardianData['person_id'])) {
                $guardianPersonId = $guardianData['person_id'];
            } elseif (!empty($guardianData['email'])) {
                $db = DatabaseHelper::getInstance();
                $existing = $db->fetchOne("SELECT id FROM people WHERE primary_email = ? AND deleted_at IS NULL", [$guardianData['email']]);
                if ($existing) {
                    $guardianPersonId = $existing['id'];
                }
            }

            // Create guardian person if not exists
            if (!$guardianPersonId) {
                $db = DatabaseHelper::getInstance();
                $uuid = bin2hex(random_bytes(16));

                $sql = "INSERT INTO people (
                    uuid, tenant_id, school_id, first_name, last_name,
                    primary_phone, primary_email, person_type, status, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

                $db->execute($sql, [
                    $uuid,
                    $tenantId,
                    $guardianData['school_id'] ?? null,
                    $guardianData['first_name'] ?? '',
                    $guardianData['last_name'] ?? '',
                    $guardianData['phone'] ?? null,
                    $guardianData['email'] ?? null,
                    'guardian',
                    $guardianData['status'] ?? 'active',
                    $createdBy
                ]);

                $guardianPersonId = $db->lastInsertId();
            }

            // Create guardian profile
            $guardianProfileData = [
                'person_id' => $guardianPersonId,
                'tenant_id' => $tenantId,
                'relationship_type' => $guardianData['relationship_type'] ?? 'guardian',
                'is_primary' => $guardianData['is_primary'] ?? 0,
                'is_financial_responsible' => $guardianData['is_financial_responsible'] ?? 0,
                'is_emergency_contact' => $guardianData['is_emergency_contact'] ?? 1,
                'is_pickup_authorized' => $guardianData['is_pickup_authorized'] ?? 1,
                'is_communication_authorized' => $guardianData['is_communication_authorized'] ?? 1,
                'can_collect_student' => $guardianData['can_collect_student'] ?? 1,
                'priority_order' => $guardianData['priority_order'] ?? 0,
                'notes' => $guardianData['notes'] ?? null,
                'created_by' => $createdBy
            ];

            $this->guardianRepo->create($guardianProfileData);

            // Create relationship
            $relationshipData = [
                'student_person_id' => $studentPersonId,
                'guardian_person_id' => $guardianPersonId,
                'tenant_id' => $tenantId,
                'relationship_type' => $guardianData['relationship_type'] ?? 'guardian',
                'is_primary' => $guardianData['is_primary'] ?? 0,
                'is_financial_responsible' => $guardianData['is_financial_responsible'] ?? 0,
                'is_emergency_contact' => $guardianData['is_emergency_contact'] ?? 1,
                'is_pickup_authorized' => $guardianData['is_pickup_authorized'] ?? 1,
                'is_communication_authorized' => $guardianData['is_communication_authorized'] ?? 1,
                'can_collect_student' => $guardianData['can_collect_student'] ?? 1,
                'priority_order' => $guardianData['priority_order'] ?? 0,
                'notes' => $guardianData['relationship_notes'] ?? null,
                'created_by' => $createdBy
            ];

            $relationshipId = $this->guardianRepo->createRelationship($relationshipData);

            return [
                'success' => true,
                'data' => [
                    'guardian_person_id' => $guardianPersonId,
                    'relationship_id' => $relationshipId
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('linkGuardian error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error linking guardian: ' . $e->getMessage()];
        }
    }

    /**
     * Get student by ID
     */
    public function getStudent(int $id): array
    {
        try {
            $student = $this->studentRepo->getById($id);
            if (!$student) {
                return ['success' => false, 'message' => 'Student not found'];
            }

            // Get guardians
            $student['guardians'] = $this->guardianRepo->getByStudentId($student['person_id']);

            // Get emergency contacts
            $student['emergency_contacts'] = $this->guardianRepo->getEmergencyContacts($student['person_id']);

            // Get admission records
            $student['admissions'] = $this->admissionRepo->getByStudentId($student['person_id']);

            return ['success' => true, 'data' => $student];
        } catch (Exception $e) {
            $this->logger->error('getStudent error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching student: ' . $e->getMessage()];
        }
    }

    /**
     * Get student by person ID
     */
    public function getStudentByPersonId(int $personId): array
    {
        try {
            $student = $this->studentRepo->getByPersonId($personId);
            if (!$student) {
                return ['success' => false, 'message' => 'Student not found'];
            }

            $student['guardians'] = $this->guardianRepo->getByStudentId($personId);
            $student['emergency_contacts'] = $this->guardianRepo->getEmergencyContacts($personId);
            $student['admissions'] = $this->admissionRepo->getByStudentId($personId);

            return ['success' => true, 'data' => $student];
        } catch (Exception $e) {
            $this->logger->error('getStudentByPersonId error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching student: ' . $e->getMessage()];
        }
    }

    /**
     * Search students
     */
    public function searchStudents(array $filters, int $page = 1, int $limit = 20): array
    {
        try {
            return $this->studentRepo->search($filters, $page, $limit);
        } catch (Exception $e) {
            $this->logger->error('searchStudents error: ' . $e->getMessage());
            return ['students' => [], 'total' => 0, 'total_pages' => 0, 'current_page' => $page, 'per_page' => $limit];
        }
    }

    /**
     * Update student profile
     */
    public function updateStudent(int $id, array $data): array
    {
        try {
            $student = $this->studentRepo->getById($id);
            if (!$student) {
                return ['success' => false, 'message' => 'Student not found'];
            }

            $result = $this->studentRepo->update($id, $data);
            if (!$result) {
                return ['success' => false, 'message' => 'No changes made'];
            }

            $updated = $this->studentRepo->getById($id);
            return ['success' => true, 'message' => 'Student updated successfully', 'data' => $updated];
        } catch (Exception $e) {
            $this->logger->error('updateStudent error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error updating student: ' . $e->getMessage()];
        }
    }

    /**
     * Update enrollment status
     */
    public function updateEnrollmentStatus(int $id, string $status, ?string $notes = null): array
    {
        try {
            $student = $this->studentRepo->getById($id);
            if (!$student) {
                return ['success' => false, 'message' => 'Student not found'];
            }

            $result = $this->studentRepo->updateStatus($id, $status, $notes);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to update status'];
            }

            $updated = $this->studentRepo->getById($id);
            return ['success' => true, 'message' => 'Enrollment status updated', 'data' => $updated];
        } catch (Exception $e) {
            $this->logger->error('updateEnrollmentStatus error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error updating status: ' . $e->getMessage()];
        }
    }

    /**
     * Delete student
     */
    public function deleteStudent(int $id): array
    {
        try {
            $student = $this->studentRepo->getById($id);
            if (!$student) {
                return ['success' => false, 'message' => 'Student not found'];
            }

            $result = $this->studentRepo->delete($id);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to delete student'];
            }

            return ['success' => true, 'message' => 'Student deleted successfully'];
        } catch (Exception $e) {
            $this->logger->error('deleteStudent error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error deleting student: ' . $e->getMessage()];
        }
    }

    /**
     * Get student statistics
     */
    public function getStats(?int $tenantId = null): array
    {
        try {
            return $this->studentRepo->getStats($tenantId);
        } catch (Exception $e) {
            $this->logger->error('getStats error: ' . $e->getMessage());
            return [
                'total' => 0,
                'active' => 0,
                'inactive' => 0,
                'graduated' => 0,
                'transferred' => 0,
                'withdrawn' => 0,
                'suspended' => 0,
                'expelled' => 0,
                'boarders' => 0,
                'new_admissions' => 0
            ];
        }
    }

    /**
     * Add emergency contact
     */
    public function addEmergencyContact(int $studentPersonId, array $data): array
    {
        try {
            $data['student_person_id'] = $studentPersonId;
            $id = $this->guardianRepo->createEmergencyContact($data);
            return ['success' => true, 'message' => 'Emergency contact added', 'id' => $id];
        } catch (Exception $e) {
            $this->logger->error('addEmergencyContact error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error adding emergency contact: ' . $e->getMessage()];
        }
    }
}
