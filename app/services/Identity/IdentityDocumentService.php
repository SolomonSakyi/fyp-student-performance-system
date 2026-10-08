<?php

/**
 * IdentityDocumentService.php
 * Service for identity document operations
 * 
 * @package EduTrack
 * @subpackage Services\Identity
 * @filepath app/services/Identity/IdentityDocumentService.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/repositories/Identity/IdentityDocumentRepository.php';

class IdentityDocumentService
{
    private $documentRepo;

    public function __construct()
    {
        $this->documentRepo = new IdentityDocumentRepository();
    }

    public function create(array $data): array
    {
        try {
            if (empty($data['document_type']) || empty($data['document_number']) || empty($data['tenant_id'])) {
                return ['success' => false, 'message' => 'Document type, number, and tenant are required'];
            }

            $data['uid'] = bin2hex(random_bytes(16));

            if (empty($data['created_by'])) {
                $data['created_by'] = 1;
            }

            $id = $this->documentRepo->create($data);

            if (!$id) {
                return ['success' => false, 'message' => 'Failed to create document'];
            }

            $document = $this->documentRepo->getById($id);
            return ['success' => true, 'message' => 'Document created successfully', 'data' => $document];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error creating document: ' . $e->getMessage()];
        }
    }

    public function getById(int $id): array
    {
        try {
            $document = $this->documentRepo->getById($id);
            if (!$document) {
                return ['success' => false, 'message' => 'Document not found'];
            }
            return ['success' => true, 'data' => $document];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error fetching document: ' . $e->getMessage()];
        }
    }

    public function search(array $filters, int $page = 1, int $limit = 20): array
    {
        try {
            $result = $this->documentRepo->search($filters, $page, $limit);
            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error searching documents: ' . $e->getMessage()];
        }
    }

    public function update(int $id, array $data): array
    {
        try {
            $document = $this->documentRepo->getById($id);
            if (!$document) {
                return ['success' => false, 'message' => 'Document not found'];
            }

            $result = $this->documentRepo->update($id, $data);
            if (!$result) {
                return ['success' => false, 'message' => 'No changes made'];
            }

            $updated = $this->documentRepo->getById($id);
            return ['success' => true, 'message' => 'Document updated successfully', 'data' => $updated];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error updating document: ' . $e->getMessage()];
        }
    }

    public function verify(int $id, int $verifiedBy): array
    {
        try {
            $document = $this->documentRepo->getById($id);
            if (!$document) {
                return ['success' => false, 'message' => 'Document not found'];
            }

            $result = $this->documentRepo->verify($id, $verifiedBy);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to verify document'];
            }

            $updated = $this->documentRepo->getById($id);
            return ['success' => true, 'message' => 'Document verified successfully', 'data' => $updated];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error verifying document: ' . $e->getMessage()];
        }
    }

    public function reject(int $id, int $verifiedBy): array
    {
        try {
            $document = $this->documentRepo->getById($id);
            if (!$document) {
                return ['success' => false, 'message' => 'Document not found'];
            }

            $result = $this->documentRepo->reject($id, $verifiedBy);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to reject document'];
            }

            $updated = $this->documentRepo->getById($id);
            return ['success' => true, 'message' => 'Document rejected successfully', 'data' => $updated];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error rejecting document: ' . $e->getMessage()];
        }
    }

    public function delete(int $id): array
    {
        try {
            $document = $this->documentRepo->getById($id);
            if (!$document) {
                return ['success' => false, 'message' => 'Document not found'];
            }

            $result = $this->documentRepo->delete($id);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to delete document'];
            }

            return ['success' => true, 'message' => 'Document deleted successfully'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error deleting document: ' . $e->getMessage()];
        }
    }

    public function getStats(?int $tenantId = null): array
    {
        try {
            $stats = $this->documentRepo->getStats($tenantId);
            return ['success' => true, 'data' => $stats];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error fetching stats: ' . $e->getMessage()];
        }
    }
}
