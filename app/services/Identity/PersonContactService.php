<?php

/**
 * PersonContactService.php
 * Service for person contact management operations
 * 
 * @package EduTrack
 * @subpackage Services\Identity
 * @filepath app/services/Identity/PersonContactService.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/ResponseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/services/Tenant/TenantContext.php';
require_once $projectRoot . 'app/repositories/Identity/PersonRepository.php';
require_once $projectRoot . 'app/repositories/Identity/PersonContactRepository.php';
require_once $projectRoot . 'app/repositories/Identity/PersonAssignmentRepository.php';

class PersonContactService
{
    private $db;
    private $response;
    private $logger;
    private $context;
    private $personRepo;
    private $contactRepo;
    private $assignmentRepo;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->response = new ResponseHelper();
        $this->logger = new LoggerHelper();
        $this->context = TenantContext::getInstance();
        $this->personRepo = new PersonRepository();
        $this->contactRepo = new PersonContactRepository();
        $this->assignmentRepo = new PersonAssignmentRepository();
    }

    private function getTenantId(): ?int
    {
        return $this->context->getTenantId();
    }

    private function getUserId(): ?int
    {
        return $this->context->getUserId();
    }

    private function isPlatformAdmin(): bool
    {
        return $this->context->isPlatformAdmin();
    }

    /**
     * Validate that the user has access to the person
     */
    private function validatePersonAccess(int $personId): bool
    {
        $tenantId = $this->getTenantId();
        if (!$tenantId || $this->isPlatformAdmin()) {
            return true;
        }

        $assignments = $this->assignmentRepo->getByPersonId($personId);
        foreach ($assignments as $assignment) {
            if ($assignment['tenant_id'] == $tenantId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Add a contact to a person
     */
    public function addContact(int $personId, array $data): array
    {
        try {
            // Validate person exists
            $person = $this->personRepo->getById($personId);
            if (!$person) {
                return ['success' => false, 'message' => 'Person not found'];
            }

            // Validate access
            if (!$this->validatePersonAccess($personId)) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            // Validate required fields
            if (empty($data['contact_type_id']) || empty($data['contact_value'])) {
                return ['success' => false, 'message' => 'Contact type and value are required'];
            }

            // Check for duplicate contact for this person
            if ($this->contactRepo->existsForPerson($personId, $data['contact_value'])) {
                return ['success' => false, 'message' => 'This contact already exists for this person'];
            }

            // If this is primary, unset other primary contacts
            if (!empty($data['is_primary'])) {
                $this->contactRepo->setPrimary($personId, 0);
            }

            $contactData = [
                'person_id' => $personId,
                'contact_type_id' => $data['contact_type_id'],
                'contact_value' => $data['contact_value'],
                'is_primary' => $data['is_primary'] ?? 0,
                'is_verified' => $data['is_verified'] ?? 0,
                'verification_date' => $data['verification_date'] ?? null,
                'notes' => $data['notes'] ?? null
            ];

            $contactId = $this->contactRepo->create($contactData);

            // If this was set as primary, ensure it's the only primary
            if (!empty($data['is_primary'])) {
                $this->contactRepo->setPrimary($personId, $contactId);
            }

            $this->logAudit('CONTACT_ADDED', 'person_contact', $contactId, [
                'person_id' => $personId,
                'contact_value' => $data['contact_value']
            ]);

            $contact = $this->contactRepo->getById($contactId);
            return ['success' => true, 'message' => 'Contact added successfully', 'data' => $contact];
        } catch (Exception $e) {
            $this->logger->error('PersonContactService::addContact error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error adding contact: ' . $e->getMessage()];
        }
    }

    /**
     * Get all contacts for a person
     */
    public function getContacts(int $personId): array
    {
        try {
            $person = $this->personRepo->getById($personId);
            if (!$person) {
                return ['success' => false, 'message' => 'Person not found'];
            }

            if (!$this->validatePersonAccess($personId)) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            $contacts = $this->contactRepo->getByPersonId($personId);
            return ['success' => true, 'data' => $contacts];
        } catch (Exception $e) {
            $this->logger->error('PersonContactService::getContacts error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching contacts: ' . $e->getMessage()];
        }
    }

    /**
     * Get a single contact by ID
     */
    public function getContact(int $contactId): array
    {
        try {
            $contact = $this->contactRepo->getById($contactId);
            if (!$contact) {
                return ['success' => false, 'message' => 'Contact not found'];
            }

            if (!$this->validatePersonAccess($contact['person_id'])) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            return ['success' => true, 'data' => $contact];
        } catch (Exception $e) {
            $this->logger->error('PersonContactService::getContact error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching contact: ' . $e->getMessage()];
        }
    }

    /**
     * Update a contact
     */
    public function updateContact(int $contactId, array $data): array
    {
        try {
            $contact = $this->contactRepo->getById($contactId);
            if (!$contact) {
                return ['success' => false, 'message' => 'Contact not found'];
            }

            if (!$this->validatePersonAccess($contact['person_id'])) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            // Check for duplicate if contact value is changing
            if (!empty($data['contact_value']) && $data['contact_value'] != $contact['contact_value']) {
                if ($this->contactRepo->existsForPerson($contact['person_id'], $data['contact_value'], $contactId)) {
                    return ['success' => false, 'message' => 'This contact already exists for this person'];
                }
            }

            // If setting as primary, unset other primary contacts
            if (!empty($data['is_primary'])) {
                $this->contactRepo->setPrimary($contact['person_id'], $contactId);
            }

            $result = $this->contactRepo->update($contactId, $data);

            if (!$result) {
                return ['success' => false, 'message' => 'No changes made'];
            }

            $this->logAudit('CONTACT_UPDATED', 'person_contact', $contactId, array_keys($data));

            $updatedContact = $this->contactRepo->getById($contactId);
            return ['success' => true, 'message' => 'Contact updated successfully', 'data' => $updatedContact];
        } catch (Exception $e) {
            $this->logger->error('PersonContactService::updateContact error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error updating contact: ' . $e->getMessage()];
        }
    }

    /**
     * Verify a contact
     */
    public function verifyContact(int $contactId): array
    {
        try {
            $contact = $this->contactRepo->getById($contactId);
            if (!$contact) {
                return ['success' => false, 'message' => 'Contact not found'];
            }

            if (!$this->validatePersonAccess($contact['person_id'])) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            $result = $this->contactRepo->verify($contactId);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to verify contact'];
            }

            $this->logAudit('CONTACT_VERIFIED', 'person_contact', $contactId);

            $updatedContact = $this->contactRepo->getById($contactId);
            return ['success' => true, 'message' => 'Contact verified successfully', 'data' => $updatedContact];
        } catch (Exception $e) {
            $this->logger->error('PersonContactService::verifyContact error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error verifying contact: ' . $e->getMessage()];
        }
    }

    /**
     * Set a contact as primary
     */
    public function setPrimaryContact(int $contactId): array
    {
        try {
            $contact = $this->contactRepo->getById($contactId);
            if (!$contact) {
                return ['success' => false, 'message' => 'Contact not found'];
            }

            if (!$this->validatePersonAccess($contact['person_id'])) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            $this->contactRepo->setPrimary($contact['person_id'], $contactId);

            $this->logAudit('CONTACT_PRIMARY', 'person_contact', $contactId);

            $updatedContact = $this->contactRepo->getById($contactId);
            return ['success' => true, 'message' => 'Primary contact updated', 'data' => $updatedContact];
        } catch (Exception $e) {
            $this->logger->error('PersonContactService::setPrimaryContact error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error setting primary contact: ' . $e->getMessage()];
        }
    }

    /**
     * Delete a contact (soft delete)
     */
    public function deleteContact(int $contactId): array
    {
        try {
            $contact = $this->contactRepo->getById($contactId);
            if (!$contact) {
                return ['success' => false, 'message' => 'Contact not found'];
            }

            if (!$this->validatePersonAccess($contact['person_id'])) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            $result = $this->contactRepo->delete($contactId);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to delete contact'];
            }

            $this->logAudit('CONTACT_DELETED', 'person_contact', $contactId);

            return ['success' => true, 'message' => 'Contact deleted successfully'];
        } catch (Exception $e) {
            $this->logger->error('PersonContactService::deleteContact error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error deleting contact: ' . $e->getMessage()];
        }
    }

    /**
     * Search contacts by value
     */
    public function searchContacts(string $value): array
    {
        try {
            $tenantId = $this->getTenantId();
            if (!$tenantId) {
                return ['success' => false, 'message' => 'Tenant context required'];
            }

            $schoolId = null;
            // If not platform admin, restrict to current school context
            if (!$this->isPlatformAdmin()) {
                // School context would come from session or middleware
                $schoolId = $_SESSION['school_id'] ?? null;
            }

            $results = $this->contactRepo->searchByValue($value, $tenantId, $schoolId);

            return ['success' => true, 'data' => $results];
        } catch (Exception $e) {
            $this->logger->error('PersonContactService::searchContacts error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error searching contacts: ' . $e->getMessage()];
        }
    }

    /**
     * Log audit event
     */
    private function logAudit(string $action, string $resource, int $resourceId, ?array $details = null): void
    {
        try {
            $tenantId = $this->getTenantId();
            $userId = $this->getUserId();

            $sql = "INSERT INTO audit_logs (tenant_id, user_id, username, email, action_type, module, resource, resource_id, description, ip_address, user_agent, details, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $userInfo = $this->db->fetchOne(
                "SELECT username, email FROM platform_users WHERE id = ?",
                [$userId]
            );

            $this->db->execute($sql, [
                $tenantId,
                $userId,
                $userInfo['username'] ?? 'system',
                $userInfo['email'] ?? null,
                $action,
                'identity',
                $resource,
                (string)$resourceId,
                $action . ' on ' . $resource . ' #' . $resourceId,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $details ? json_encode($details) : null
            ]);
        } catch (Exception $e) {
            // Silently fail for audit
        }
    }
}
