<?php

/**
 * DocumentService.php
 * Service for document management
 * 
 * @package EduTrack
 * @subpackage Services\Identity
 * @version 2.0
 * @filepath app/services/Identity/DocumentService.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class DocumentService
{
    private $db;
    private $logger;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
    }

    /**
     * Create document
     */
    public function create(array $data): array
    {
        try {
            if (empty($data['person_id']) || empty($data['document_type'])) {
                return ['success' => false, 'message' => 'Person ID and document type are required'];
            }

            // Get document type ID
            $typeId = $this->getDocumentTypeId($data['document_type']);
            if (!$typeId) {
                return ['success' => false, 'message' => 'Invalid document type'];
            }

            $sql = "INSERT INTO person_documents (
                person_id,
                document_type_id,
                document_number,
                document_name,
                issuing_authority,
                issuing_country,
                issue_date,
                expiry_date,
                verification_status,
                document_url,
                file_name,
                file_size,
                mime_type,
                notes,
                created_by,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $params = [
                $data['person_id'],
                $typeId,
                $data['document_number'] ?? null,
                $data['document_name'] ?? null,
                $data['issuing_authority'] ?? null,
                $data['issuing_country'] ?? null,
                $data['issue_date'] ?? null,
                $data['expiry_date'] ?? null,
                $data['verification_status'] ?? 'pending',
                $data['document_url'] ?? null,
                $data['file_name'] ?? null,
                $data['file_size'] ?? null,
                $data['mime_type'] ?? null,
                $data['notes'] ?? null,
                $data['created_by'] ?? null
            ];

            $this->db->execute($sql, $params);
            $documentId = $this->db->lastInsertId();

            $this->logAudit('DOCUMENT_CREATED', 'document', $documentId);

            return ['success' => true, 'data' => ['id' => $documentId]];
        } catch (Exception $e) {
            $this->logger->error('create error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get document type ID by code or name
     */
    private function getDocumentTypeId(string $type): ?int
    {
        $sql = "SELECT id FROM document_types WHERE type_code = ? OR type_name = ? AND is_active = 1";
        $result = $this->db->fetchOne($sql, [$type, $type]);
        return $result['id'] ?? null;
    }

    /**
     * Log audit event
     */
    private function logAudit(string $action, string $resource, ?int $resourceId = null): void
    {
        try {
            $sql = "INSERT INTO audit_logs (
                tenant_id,
                user_id,
                action_type,
                module,
                resource,
                resource_id,
                description,
                ip_address,
                user_agent,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $this->db->execute($sql, [
                $_SESSION['tenant_id'] ?? null,
                $_SESSION['user_id'] ?? null,
                $action,
                'documents',
                $resource,
                $resourceId,
                $action . ' on ' . $resource,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            $this->logger->error('Audit log error: ' . $e->getMessage());
        }
    }
}
