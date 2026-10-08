<?php

/**
 * PersonDocumentService.php
 * Service for person document management operations
 * 
 * @package EduTrack
 * @subpackage Services\Identity
 * @filepath app/services/Identity/PersonDocumentService.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/ResponseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/services/Tenant/TenantContext.php';
require_once $projectRoot . 'app/repositories/Identity/PersonRepository.php';
require_once $projectRoot . 'app/repositories/Identity/PersonDocumentRepository.php';
require_once $projectRoot . 'app/repositories/Identity/PersonAssignmentRepository.php';

class PersonDocumentService
{
    private $db;
    private $response;
    private $logger;
    private $context;
    private $personRepo;
    private $documentRepo;
    private $assignmentRepo;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->response = new ResponseHelper();
        $this->logger = new LoggerHelper();
        $this->context = TenantContext::getInstance();
        $this->personRepo = new PersonRepository();
        $this->documentRepo = new PersonDocumentRepository();
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
     * Add a document to a person
     */
    public function addDocument(int $personId, array $data): array
    {
        try {
            $person = $this->personRepo->getById($personId);
            if (!$person) {
                return ['success' => false, 'message' => 'Person not found'];
            }

            if (!$this->validatePersonAccess($personId)) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            if (empty($data['document_type_id']) || empty($data['storage_reference'])) {
                return ['success' => false, 'message' => 'Document type and storage reference are required'];
            }

            // Check for duplicate document number if provided
            if (!empty($data['document_number'])) {
                if ($this->documentRepo->existsForPerson($personId, $data['document_number'])) {
                    return ['success' => false, 'message' => 'Document number already exists for this person'];
                }
            }

            $documentData = [
                'person_id' => $personId,
                'document_type_id' => $data['document_type_id'],
                'document_number' => $data['document_number'] ?? null,
                'issuing_country' => $data['issuing_country'] ?? null,
                'issuing_authority' => $data['issuing_authority'] ?? null,
                'issue_date' => $data['issue_date'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'storage_reference' => $data['storage_reference'],
                'verification_status' => $data['verification_status'] ?? 'pending',
                'verified_by' => $data['verified_by'] ?? null,
                'verified_at' => $data['verified_at'] ?? null
            ];

            $documentId = $this->documentRepo->create($documentData);

            $this->logAudit('DOCUMENT_ADDED', 'person_document', $documentId, [
                'person_id' => $personId,
                'document_type_id' => $data['document_type_id']
            ]);

            $document = $this->documentRepo->getById($documentId);
            return ['success' => true, 'message' => 'Document added successfully', 'data' => $document];
        } catch (Exception $e) {
            $this->logger->error('PersonDocumentService::addDocument error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error adding document: ' . $e->getMessage()];
        }
    }

    /**
     * Get all documents for a person
     */
    public function getDocuments(int $personId): array
    {
        try {
            $person = $this->personRepo->getById($personId);
            if (!$person) {
                return ['success' => false, 'message' => 'Person not found'];
            }

            if (!$this->validatePersonAccess($personId)) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            $documents = $this->documentRepo->getByPersonId($personId);

            // Check for expired documents
            foreach ($documents as &$doc) {
                if ($doc['expiry_date'] && $doc['expiry_date'] < date('Y-m-d')) {
                    $doc['is_expired'] = true;
                } else {
                    $doc['is_expired'] = false;
                }
            }

            return ['success' => true, 'data' => $documents];
        } catch (Exception $e) {
            $this->logger->error('PersonDocumentService::getDocuments error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching documents: ' . $e->getMessage()];
        }
    }

    /**
     * Get a single document by ID
     */
    public function getDocument(int $documentId): array
    {
        try {
            $document = $this->documentRepo->getById($documentId);
            if (!$document) {
                return ['success' => false, 'message' => 'Document not found'];
            }

            if (!$this->validatePersonAccess($document['person_id'])) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            return ['success' => true, 'data' => $document];
        } catch (Exception $e) {
            $this->logger->error('PersonDocumentService::getDocument error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching document: ' . $e->getMessage()];
        }
    }

    /**
     * Update a document
     */
    public function updateDocument(int $documentId, array $data): array
    {
        try {
            $document = $this->documentRepo->getById($documentId);
            if (!$document) {
                return ['success' => false, 'message' => 'Document not found'];
            }

            if (!$this->validatePersonAccess($document['person_id'])) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            // Check for duplicate document number if changing
            if (!empty($data['document_number']) && $data['document_number'] != $document['document_number']) {
                if ($this->documentRepo->existsForPerson($document['person_id'], $data['document_number'], $documentId)) {
                    return ['success' => false, 'message' => 'Document number already exists for this person'];
                }
            }

            $result = $this->documentRepo->update($documentId, $data);

            if (!$result) {
                return ['success' => false, 'message' => 'No changes made'];
            }

            $this->logAudit('DOCUMENT_UPDATED', 'person_document', $documentId, array_keys($data));

            $updatedDocument = $this->documentRepo->getById($documentId);
            return ['success' => true, 'message' => 'Document updated successfully', 'data' => $updatedDocument];
        } catch (Exception $e) {
            $this->logger->error('PersonDocumentService::updateDocument error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error updating document: ' . $e->getMessage()];
        }
    }

    /**
     * Verify a document
     */
    public function verifyDocument(int $documentId): array
    {
        try {
            $document = $this->documentRepo->getById($documentId);
            if (!$document) {
                return ['success' => false, 'message' => 'Document not found'];
            }

            if (!$this->validatePersonAccess($document['person_id'])) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            $userId = $this->getUserId();
            if (!$userId) {
                return ['success' => false, 'message' => 'User not authenticated'];
            }

            $result = $this->documentRepo->verify($documentId, $userId);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to verify document'];
            }

            $this->logAudit('DOCUMENT_VERIFIED', 'person_document', $documentId);

            $updatedDocument = $this->documentRepo->getById($documentId);
            return ['success' => true, 'message' => 'Document verified successfully', 'data' => $updatedDocument];
        } catch (Exception $e) {
            $this->logger->error('PersonDocumentService::verifyDocument error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error verifying document: ' . $e->getMessage()];
        }
    }

    /**
     * Mark document as failed verification
     */
    public function failDocument(int $documentId): array
    {
        try {
            $document = $this->documentRepo->getById($documentId);
            if (!$document) {
                return ['success' => false, 'message' => 'Document not found'];
            }

            if (!$this->validatePersonAccess($document['person_id'])) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            $userId = $this->getUserId();
            if (!$userId) {
                return ['success' => false, 'message' => 'User not authenticated'];
            }

            $result = $this->documentRepo->markFailed($documentId, $userId);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to mark document as failed'];
            }

            $this->logAudit('DOCUMENT_FAILED', 'person_document', $documentId);

            $updatedDocument = $this->documentRepo->getById($documentId);
            return ['success' => true, 'message' => 'Document marked as failed', 'data' => $updatedDocument];
        } catch (Exception $e) {
            $this->logger->error('PersonDocumentService::failDocument error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error marking document as failed: ' . $e->getMessage()];
        }
    }

    /**
     * Delete a document (soft delete)
     */
    public function deleteDocument(int $documentId): array
    {
        try {
            $document = $this->documentRepo->getById($documentId);
            if (!$document) {
                return ['success' => false, 'message' => 'Document not found'];
            }

            if (!$this->validatePersonAccess($document['person_id'])) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            $result = $this->documentRepo->delete($documentId);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to delete document'];
            }

            $this->logAudit('DOCUMENT_DELETED', 'person_document', $documentId);

            return ['success' => true, 'message' => 'Document deleted successfully'];
        } catch (Exception $e) {
            $this->logger->error('PersonDocumentService::deleteDocument error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error deleting document: ' . $e->getMessage()];
        }
    }

    /**
     * Get expired documents for a tenant
     */
    public function getExpiredDocuments(): array
    {
        try {
            $tenantId = $this->getTenantId();
            if (!$tenantId) {
                return ['success' => false, 'message' => 'Tenant context required'];
            }

            // Update expired status first
            $this->documentRepo->updateExpiredStatus();

            $documents = $this->documentRepo->getExpired($tenantId);
            return ['success' => true, 'data' => $documents];
        } catch (Exception $e) {
            $this->logger->error('PersonDocumentService::getExpiredDocuments error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching expired documents: ' . $e->getMessage()];
        }
    }

    /**
     * Get documents expiring soon
     */
    public function getExpiringDocuments(int $days = 30): array
    {
        try {
            $tenantId = $this->getTenantId();
            if (!$tenantId) {
                return ['success' => false, 'message' => 'Tenant context required'];
            }

            $documents = $this->documentRepo->getExpiringSoon($tenantId, $days);
            return ['success' => true, 'data' => $documents];
        } catch (Exception $e) {
            $this->logger->error('PersonDocumentService::getExpiringDocuments error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching expiring documents: ' . $e->getMessage()];
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
