<?php

/**
 * GuardianService.php
 * Service for guardian/relationship management
 * 
 * @package EduTrack
 * @subpackage Services\Student
 * @filepath app/services/Student/GuardianService.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/repositories/Student/GuardianRepository.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class GuardianService
{
    private $guardianRepo;
    private $logger;

    public function __construct()
    {
        $this->guardianRepo = new GuardianRepository();
        $this->logger = new LoggerHelper();
    }

    /**
     * Create a guardian profile
     */
    public function createGuardianProfile(array $data): array
    {
        try {
            // Validate required fields
            if (empty($data['person_id']) || empty($data['tenant_id']) || empty($data['relationship_type'])) {
                return ['success' => false, 'message' => 'Person ID, tenant ID, and relationship type are required'];
            }

            // Check if guardian profile already exists
            $existing = $this->guardianRepo->getGuardianProfile($data['person_id']);
            if ($existing) {
                return ['success' => false, 'message' => 'Guardian profile already exists for this person'];
            }

            $id = $this->guardianRepo->createGuardianProfile($data);

            return [
                'success' => true,
                'message' => 'Guardian profile created successfully',
                'data' => ['id' => $id]
            ];
        } catch (Exception $e) {
            $this->logger->error('createGuardianProfile error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error creating guardian profile: ' . $e->getMessage()];
        }
    }

    /**
     * Get guardian profile
     */
    public function getGuardianProfile(int $personId): array
    {
        try {
            $profile = $this->guardianRepo->getGuardianProfile($personId);
            if (!$profile) {
                return ['success' => false, 'message' => 'Guardian profile not found'];
            }

            // Get students linked to this guardian
            $profile['students'] = $this->guardianRepo->getStudentsByGuardian($personId);

            return ['success' => true, 'data' => $profile];
        } catch (Exception $e) {
            $this->logger->error('getGuardianProfile error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching guardian profile: ' . $e->getMessage()];
        }
    }

    /**
     * Link guardian to student
     */
    public function linkGuardianToStudent(int $studentPersonId, int $guardianPersonId, array $data): array
    {
        try {
            // Check if relationship already exists
            $existing = $this->guardianRepo->getRelationship($studentPersonId, $guardianPersonId);
            if ($existing) {
                return ['success' => false, 'message' => 'Relationship already exists'];
            }

            // Validate guardian exists
            $guardian = $this->guardianRepo->getGuardianProfile($guardianPersonId);
            if (!$guardian) {
                // Create guardian profile if it doesn't exist
                $profileData = [
                    'person_id' => $guardianPersonId,
                    'tenant_id' => $data['tenant_id'],
                    'relationship_type' => $data['relationship_type'] ?? 'guardian',
                    'created_by' => $data['created_by'] ?? 1
                ];
                $this->guardianRepo->createGuardianProfile($profileData);
            }

            $relationshipData = [
                'student_person_id' => $studentPersonId,
                'guardian_person_id' => $guardianPersonId,
                'tenant_id' => $data['tenant_id'],
                'relationship_type' => $data['relationship_type'] ?? 'guardian',
                'is_primary' => $data['is_primary'] ?? 0,
                'is_financial_responsible' => $data['is_financial_responsible'] ?? 0,
                'is_emergency_contact' => $data['is_emergency_contact'] ?? 1,
                'is_pickup_authorized' => $data['is_pickup_authorized'] ?? 1,
                'is_communication_authorized' => $data['is_communication_authorized'] ?? 1,
                'can_collect_student' => $data['can_collect_student'] ?? 1,
                'priority_order' => $data['priority_order'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? 1
            ];

            $id = $this->guardianRepo->createRelationship($relationshipData);

            // If this is primary, unset others
            if ($data['is_primary'] ?? 0) {
                $this->guardianRepo->setPrimaryGuardian($studentPersonId, $guardianPersonId);
            }

            return [
                'success' => true,
                'message' => 'Guardian linked successfully',
                'data' => ['relationship_id' => $id]
            ];
        } catch (Exception $e) {
            $this->logger->error('linkGuardianToStudent error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error linking guardian: ' . $e->getMessage()];
        }
    }

    /**
     * Update relationship
     */
    public function updateRelationship(int $relationshipId, array $data): array
    {
        try {
            $relationship = $this->guardianRepo->getRelationshipById($relationshipId);
            if (!$relationship) {
                return ['success' => false, 'message' => 'Relationship not found'];
            }

            $result = $this->guardianRepo->updateRelationship($relationshipId, $data);
            if (!$result) {
                return ['success' => false, 'message' => 'No changes made'];
            }

            // If this is being set as primary, unset others
            if (isset($data['is_primary']) && $data['is_primary']) {
                $this->guardianRepo->setPrimaryGuardian(
                    $relationship['student_person_id'],
                    $relationship['guardian_person_id']
                );
            }

            $updated = $this->guardianRepo->getRelationshipById($relationshipId);
            return [
                'success' => true,
                'message' => 'Relationship updated successfully',
                'data' => $updated
            ];
        } catch (Exception $e) {
            $this->logger->error('updateRelationship error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error updating relationship: ' . $e->getMessage()];
        }
    }

    /**
     * Delete relationship (unlink guardian)
     */
    public function deleteRelationship(int $relationshipId): array
    {
        try {
            $relationship = $this->guardianRepo->getRelationshipById($relationshipId);
            if (!$relationship) {
                return ['success' => false, 'message' => 'Relationship not found'];
            }

            // Don't allow deleting if it's the only guardian
            $guardians = $this->guardianRepo->getGuardiansByStudent($relationship['student_person_id']);
            if (count($guardians) <= 1) {
                return ['success' => false, 'message' => 'Student must have at least one guardian'];
            }

            $result = $this->guardianRepo->deleteRelationship($relationshipId);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to delete relationship'];
            }

            return ['success' => true, 'message' => 'Guardian unlinked successfully'];
        } catch (Exception $e) {
            $this->logger->error('deleteRelationship error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error deleting relationship: ' . $e->getMessage()];
        }
    }

    /**
     * Get guardians for a student
     */
    public function getGuardiansForStudent(int $studentPersonId): array
    {
        try {
            $guardians = $this->guardianRepo->getGuardiansByStudent($studentPersonId);
            return ['success' => true, 'data' => $guardians];
        } catch (Exception $e) {
            $this->logger->error('getGuardiansForStudent error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching guardians: ' . $e->getMessage()];
        }
    }

    /**
     * Get students for a guardian
     */
    public function getStudentsForGuardian(int $guardianPersonId): array
    {
        try {
            $students = $this->guardianRepo->getStudentsByGuardian($guardianPersonId);
            return ['success' => true, 'data' => $students];
        } catch (Exception $e) {
            $this->logger->error('getStudentsForGuardian error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching students: ' . $e->getMessage()];
        }
    }

    /**
     * Set primary guardian
     */
    public function setPrimaryGuardian(int $studentPersonId, int $guardianPersonId): array
    {
        try {
            $result = $this->guardianRepo->setPrimaryGuardian($studentPersonId, $guardianPersonId);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to set primary guardian'];
            }

            return ['success' => true, 'message' => 'Primary guardian set successfully'];
        } catch (Exception $e) {
            $this->logger->error('setPrimaryGuardian error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error setting primary guardian: ' . $e->getMessage()];
        }
    }

    /**
     * Add emergency contact
     */
    public function addEmergencyContact(array $data): array
    {
        try {
            if (empty($data['student_person_id']) || empty($data['contact_name']) || empty($data['phone'])) {
                return ['success' => false, 'message' => 'Student ID, contact name, and phone are required'];
            }

            $id = $this->guardianRepo->createEmergencyContact($data);

            return [
                'success' => true,
                'message' => 'Emergency contact added successfully',
                'data' => ['id' => $id]
            ];
        } catch (Exception $e) {
            $this->logger->error('addEmergencyContact error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error adding emergency contact: ' . $e->getMessage()];
        }
    }

    /**
     * Update emergency contact
     */
    public function updateEmergencyContact(int $id, array $data): array
    {
        try {
            $contact = $this->guardianRepo->getEmergencyContactById($id);
            if (!$contact) {
                return ['success' => false, 'message' => 'Emergency contact not found'];
            }

            $result = $this->guardianRepo->updateEmergencyContact($id, $data);
            if (!$result) {
                return ['success' => false, 'message' => 'No changes made'];
            }

            $updated = $this->guardianRepo->getEmergencyContactById($id);
            return [
                'success' => true,
                'message' => 'Emergency contact updated successfully',
                'data' => $updated
            ];
        } catch (Exception $e) {
            $this->logger->error('updateEmergencyContact error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error updating emergency contact: ' . $e->getMessage()];
        }
    }

    /**
     * Delete emergency contact
     */
    public function deleteEmergencyContact(int $id): array
    {
        try {
            $contact = $this->guardianRepo->getEmergencyContactById($id);
            if (!$contact) {
                return ['success' => false, 'message' => 'Emergency contact not found'];
            }

            $result = $this->guardianRepo->deleteEmergencyContact($id);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to delete emergency contact'];
            }

            return ['success' => true, 'message' => 'Emergency contact deleted successfully'];
        } catch (Exception $e) {
            $this->logger->error('deleteEmergencyContact error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error deleting emergency contact: ' . $e->getMessage()];
        }
    }

    /**
     * Get emergency contacts for a student
     */
    public function getEmergencyContactsForStudent(int $studentPersonId): array
    {
        try {
            $contacts = $this->guardianRepo->getEmergencyContacts($studentPersonId);
            return ['success' => true, 'data' => $contacts];
        } catch (Exception $e) {
            $this->logger->error('getEmergencyContactsForStudent error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching emergency contacts: ' . $e->getMessage()];
        }
    }

    /**
     * Get guardian statistics
     */
    public function getStats(?int $tenantId = null): array
    {
        try {
            return $this->guardianRepo->getGuardianStats($tenantId);
        } catch (Exception $e) {
            $this->logger->error('getStats error: ' . $e->getMessage());
            return [
                'total' => 0,
                'primary_guardians' => 0,
                'financial_responsible' => 0,
                'emergency_contacts' => 0,
                'pickup_authorized' => 0,
                'fathers' => 0,
                'mothers' => 0,
                'other_guardians' => 0
            ];
        }
    }
}
