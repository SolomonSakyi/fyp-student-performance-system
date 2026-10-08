<?php

/**
 * StaffService.php
 * Service for staff management operations
 * 
 * @package EduTrack
 * @subpackage Services\Staff
 * @filepath app/services/Staff/StaffService.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/repositories/Staff/StaffRepository.php';
require_once $projectRoot . 'app/repositories/Staff/StaffQualificationRepository.php';
require_once $projectRoot . 'app/repositories/Staff/StaffCertificationRepository.php';
require_once $projectRoot . 'app/repositories/Staff/StaffTrainingRepository.php';
require_once $projectRoot . 'app/repositories/Staff/StaffEmploymentHistoryRepository.php';
require_once $projectRoot . 'app/repositories/Staff/StaffDocumentRepository.php';
require_once $projectRoot . 'app/repositories/Staff/StaffComplianceRepository.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class StaffService
{
    private $staffRepo;
    private $qualificationRepo;
    private $certificationRepo;
    private $trainingRepo;
    private $employmentHistoryRepo;
    private $documentRepo;
    private $complianceRepo;
    private $logger;

    public function __construct()
    {
        $this->staffRepo = new StaffRepository();
        $this->qualificationRepo = new StaffQualificationRepository();
        $this->certificationRepo = new StaffCertificationRepository();
        $this->trainingRepo = new StaffTrainingRepository();
        $this->employmentHistoryRepo = new StaffEmploymentHistoryRepository();
        $this->documentRepo = new StaffDocumentRepository();
        $this->complianceRepo = new StaffComplianceRepository();
        $this->logger = new LoggerHelper();
    }

    /**
     * Register a staff member
     */
    public function registerStaff(array $data): array
    {
        try {
            $this->logger->info('Registering staff', ['data' => $data]);

            // Validate required fields
            $required = ['first_name', 'last_name', 'tenant_id', 'school_id', 'hire_date'];
            $missing = [];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $missing[] = $field;
                }
            }
            if (!empty($missing)) {
                return ['success' => false, 'message' => 'Missing required fields: ' . implode(', ', $missing)];
            }

            $db = DatabaseHelper::getInstance();
            $db->beginTransaction();

            // Create person
            $personId = $this->createPerson($data);
            if (!$personId) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Failed to create person'];
            }

            // Generate staff number
            $staffNumber = $this->staffRepo->generateStaffNumber($data['tenant_id'], $data['school_id']);
            if (!$staffNumber) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Failed to generate staff number'];
            }

            // Create staff profile
            $staffData = [
                'person_id' => $personId,
                'tenant_id' => $data['tenant_id'],
                'school_id' => $data['school_id'],
                'campus_id' => $data['campus_id'] ?? null,
                'staff_number' => $staffNumber,
                'employee_type' => $data['employee_type'] ?? 'full_time',
                'employment_status' => $data['employment_status'] ?? 'active',
                'hire_date' => $data['hire_date'],
                'start_date' => $data['start_date'] ?? $data['hire_date'],
                'end_date' => $data['end_date'] ?? null,
                'position_id' => $data['position_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'supervisor_id' => $data['supervisor_id'] ?? null,
                'job_title' => $data['job_title'] ?? null,
                'job_description' => $data['job_description'] ?? null,
                'work_location' => $data['work_location'] ?? null,
                'work_phone' => $data['work_phone'] ?? null,
                'work_email' => $data['work_email'] ?? null,
                'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
                'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
                'emergency_contact_relationship' => $data['emergency_contact_relationship'] ?? null,
                'salary_grade' => $data['salary_grade'] ?? null,
                'salary_amount' => $data['salary_amount'] ?? null,
                'currency' => $data['currency'] ?? 'GHS',
                'bank_name' => $data['bank_name'] ?? null,
                'bank_account' => $data['bank_account'] ?? null,
                'bank_branch' => $data['bank_branch'] ?? null,
                'is_active' => $data['is_active'] ?? 1,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? 1
            ];

            $staffProfileId = $this->staffRepo->create($staffData);
            if (!$staffProfileId) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Failed to create staff profile'];
            }

            // Add qualifications if provided
            if (!empty($data['qualifications'])) {
                foreach ($data['qualifications'] as $qualification) {
                    $qualification['staff_person_id'] = $personId;
                    $qualification['tenant_id'] = $data['tenant_id'];
                    $qualification['created_by'] = $data['created_by'] ?? 1;
                    $this->qualificationRepo->create($qualification);
                }
            }

            // Add certifications if provided
            if (!empty($data['certifications'])) {
                foreach ($data['certifications'] as $certification) {
                    $certification['staff_person_id'] = $personId;
                    $certification['tenant_id'] = $data['tenant_id'];
                    $certification['created_by'] = $data['created_by'] ?? 1;
                    $this->certificationRepo->create($certification);
                }
            }

            // Add training if provided
            if (!empty($data['trainings'])) {
                foreach ($data['trainings'] as $training) {
                    $training['staff_person_id'] = $personId;
                    $training['tenant_id'] = $data['tenant_id'];
                    $training['created_by'] = $data['created_by'] ?? 1;
                    $this->trainingRepo->create($training);
                }
            }

            $db->commit();

            $staff = $this->staffRepo->getByPersonId($personId);

            return [
                'success' => true,
                'message' => 'Staff registered successfully',
                'data' => $staff
            ];
        } catch (Exception $e) {
            $this->logger->error('StaffService::registerStaff error: ' . $e->getMessage());
            $this->logger->error('StaffService::registerStaff trace: ' . $e->getTraceAsString());
            if (isset($db)) $db->rollBack();
            return ['success' => false, 'message' => 'Error registering staff: ' . $e->getMessage()];
        }
    }

    /**
     * Create person from staff data
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

        try {
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
                'staff',
                $data['status'] ?? 'active',
                $data['created_by'] ?? 1
            ]);

            return $db->lastInsertId();
        } catch (Exception $e) {
            $this->logger->error('createPerson error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get staff by ID
     */
    public function getStaff(int $id): array
    {
        try {
            $staff = $this->staffRepo->getById($id);
            if (!$staff) {
                return ['success' => false, 'message' => 'Staff not found'];
            }

            // Get additional data
            $staff['qualifications'] = $this->qualificationRepo->getByStaffId($staff['person_id']);
            $staff['certifications'] = $this->certificationRepo->getByStaffId($staff['person_id']);
            $staff['trainings'] = $this->trainingRepo->getByStaffId($staff['person_id']);
            $staff['employment_history'] = $this->employmentHistoryRepo->getByStaffId($staff['person_id']);
            $staff['documents'] = $this->documentRepo->getByStaffId($staff['person_id']);
            $staff['compliance'] = $this->complianceRepo->getByStaffId($staff['person_id']);

            return ['success' => true, 'data' => $staff];
        } catch (Exception $e) {
            $this->logger->error('getStaff error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching staff: ' . $e->getMessage()];
        }
    }

    /**
     * Get staff by person ID
     */
    public function getStaffByPersonId(int $personId): array
    {
        try {
            $staff = $this->staffRepo->getByPersonId($personId);
            if (!$staff) {
                return ['success' => false, 'message' => 'Staff not found'];
            }

            $staff['qualifications'] = $this->qualificationRepo->getByStaffId($personId);
            $staff['certifications'] = $this->certificationRepo->getByStaffId($personId);
            $staff['trainings'] = $this->trainingRepo->getByStaffId($personId);
            $staff['employment_history'] = $this->employmentHistoryRepo->getByStaffId($personId);
            $staff['documents'] = $this->documentRepo->getByStaffId($personId);
            $staff['compliance'] = $this->complianceRepo->getByStaffId($personId);

            return ['success' => true, 'data' => $staff];
        } catch (Exception $e) {
            $this->logger->error('getStaffByPersonId error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching staff: ' . $e->getMessage()];
        }
    }

    /**
     * Search staff
     */
    public function searchStaff(array $filters, int $page = 1, int $limit = 20): array
    {
        try {
            return $this->staffRepo->search($filters, $page, $limit);
        } catch (Exception $e) {
            $this->logger->error('searchStaff error: ' . $e->getMessage());
            return ['staff' => [], 'total' => 0, 'total_pages' => 0, 'current_page' => $page, 'per_page' => $limit];
        }
    }

    /**
     * Update staff
     */
    public function updateStaff(int $id, array $data): array
    {
        try {
            $staff = $this->staffRepo->getById($id);
            if (!$staff) {
                return ['success' => false, 'message' => 'Staff not found'];
            }

            $result = $this->staffRepo->update($id, $data);
            if (!$result) {
                return ['success' => false, 'message' => 'No changes made'];
            }

            $updated = $this->staffRepo->getById($id);
            return ['success' => true, 'message' => 'Staff updated successfully', 'data' => $updated];
        } catch (Exception $e) {
            $this->logger->error('updateStaff error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error updating staff: ' . $e->getMessage()];
        }
    }

    /**
     * Update employment status
     */
    public function updateEmploymentStatus(int $id, string $status, ?string $notes = null): array
    {
        try {
            $staff = $this->staffRepo->getById($id);
            if (!$staff) {
                return ['success' => false, 'message' => 'Staff not found'];
            }

            $result = $this->staffRepo->updateStatus($id, $status, $notes);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to update status'];
            }

            $updated = $this->staffRepo->getById($id);
            return ['success' => true, 'message' => 'Employment status updated', 'data' => $updated];
        } catch (Exception $e) {
            $this->logger->error('updateEmploymentStatus error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error updating status: ' . $e->getMessage()];
        }
    }

    /**
     * Delete staff (soft delete)
     */
    public function deleteStaff(int $id): array
    {
        try {
            $staff = $this->staffRepo->getById($id);
            if (!$staff) {
                return ['success' => false, 'message' => 'Staff not found'];
            }

            $result = $this->staffRepo->delete($id);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to delete staff'];
            }

            // Also soft delete related records
            $this->qualificationRepo->deleteByStaffId($staff['person_id']);
            $this->certificationRepo->deleteByStaffId($staff['person_id']);
            $this->trainingRepo->deleteByStaffId($staff['person_id']);
            $this->employmentHistoryRepo->deleteByStaffId($staff['person_id']);
            $this->documentRepo->deleteByStaffId($staff['person_id']);
            $this->complianceRepo->deleteByStaffId($staff['person_id']);

            return ['success' => true, 'message' => 'Staff deleted successfully'];
        } catch (Exception $e) {
            $this->logger->error('deleteStaff error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error deleting staff: ' . $e->getMessage()];
        }
    }

    /**
     * Get staff statistics
     */
    public function getStats(?int $tenantId = null): array
    {
        try {
            return $this->staffRepo->getStats($tenantId);
        } catch (Exception $e) {
            $this->logger->error('getStats error: ' . $e->getMessage());
            return [
                'total' => 0,
                'active' => 0,
                'inactive' => 0,
                'on_leave' => 0,
                'suspended' => 0,
                'terminated' => 0,
                'resigned' => 0,
                'retired' => 0,
                'full_time' => 0,
                'part_time' => 0,
                'contract' => 0,
                'casual' => 0,
                'intern' => 0,
                'volunteer' => 0,
                'active_status' => 0
            ];
        }
    }

    /**
     * Get staff by department
     */
    public function getStaffByDepartment(int $departmentId): array
    {
        try {
            $staff = $this->staffRepo->getByDepartment($departmentId);
            return ['success' => true, 'data' => $staff];
        } catch (Exception $e) {
            $this->logger->error('getStaffByDepartment error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching staff by department: ' . $e->getMessage()];
        }
    }

    /**
     * Get staff by position
     */
    public function getStaffByPosition(int $positionId): array
    {
        try {
            $staff = $this->staffRepo->getByPosition($positionId);
            return ['success' => true, 'data' => $staff];
        } catch (Exception $e) {
            $this->logger->error('getStaffByPosition error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching staff by position: ' . $e->getMessage()];
        }
    }

    /**
     * Get staff by supervisor
     */
    public function getStaffBySupervisor(int $supervisorId): array
    {
        try {
            $staff = $this->staffRepo->getBySupervisor($supervisorId);
            return ['success' => true, 'data' => $staff];
        } catch (Exception $e) {
            $this->logger->error('getStaffBySupervisor error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching staff by supervisor: ' . $e->getMessage()];
        }
    }

    /**
     * Get staff by email
     */
    public function getStaffByEmail(string $email): array
    {
        try {
            $staff = $this->staffRepo->getByEmail($email);
            if (!$staff) {
                return ['success' => false, 'message' => 'Staff not found'];
            }
            return ['success' => true, 'data' => $staff];
        } catch (Exception $e) {
            $this->logger->error('getStaffByEmail error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching staff by email: ' . $e->getMessage()];
        }
    }

    /**
     * Get staff by phone
     */
    public function getStaffByPhone(string $phone): array
    {
        try {
            $staff = $this->staffRepo->getByPhone($phone);
            if (!$staff) {
                return ['success' => false, 'message' => 'Staff not found'];
            }
            return ['success' => true, 'data' => $staff];
        } catch (Exception $e) {
            $this->logger->error('getStaffByPhone error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching staff by phone: ' . $e->getMessage()];
        }
    }
}
