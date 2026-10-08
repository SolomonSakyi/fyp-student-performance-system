<?php

/**
 * PersonRelationshipService.php
 * Service for person-to-person relationship management operations
 * 
 * @package EduTrack
 * @subpackage Services\Identity
 * @filepath app/services/Identity/PersonRelationshipService.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/ResponseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/services/Tenant/TenantContext.php';
require_once $projectRoot . 'app/repositories/Identity/PersonRepository.php';
require_once $projectRoot . 'app/repositories/Identity/PersonRelationshipRepository.php';
require_once $projectRoot . 'app/repositories/Identity/PersonAssignmentRepository.php';

class PersonRelationshipService
{
    private $db;
    private $response;
    private $logger;
    private $context;
    private $personRepo;
    private $relationshipRepo;
    private $assignmentRepo;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->response = new ResponseHelper();
        $this->logger = new LoggerHelper();
        $this->context = TenantContext::getInstance();
        $this->personRepo = new PersonRepository();
        $this->relationshipRepo = new PersonRelationshipRepository();
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
     * Validate that two people belong to the same tenant context
     */
    private function validateSameTenant(int $personId1, int $personId2): bool
    {
        $tenantId = $this->getTenantId();
        if (!$tenantId || $this->isPlatformAdmin()) {
            return true;
        }

        $assignments1 = $this->assignmentRepo->getByPersonId($personId1);
        $assignments2 = $this->assignmentRepo->getByPersonId($personId2);

        $tenant1 = null;
        $tenant2 = null;

        foreach ($assignments1 as $assignment) {
            if ($assignment['status'] === 'active') {
                $tenant1 = $assignment['tenant_id'];
                break;
            }
        }

        foreach ($assignments2 as $assignment) {
            if ($assignment['status'] === 'active') {
                $tenant2 = $assignment['tenant_id'];
                break;
            }
        }

        return $tenant1 === $tenant2;
    }

    /**
     * Create a relationship between two people
     */
    public function createRelationship(array $data): array
    {
        try {
            if (empty($data['person_id']) || empty($data['related_person_id']) || empty($data['relationship_type_id'])) {
                return ['success' => false, 'message' => 'Person ID, related person ID, and relationship type are required'];
            }

            if ($data['person_id'] == $data['related_person_id']) {
                return ['success' => false, 'message' => 'A person cannot be related to themselves'];
            }

            $person = $this->personRepo->getById($data['person_id']);
            if (!$person) {
                return ['success' => false, 'message' => 'Person not found'];
            }

            $relatedPerson = $this->personRepo->getById($data['related_person_id']);
            if (!$relatedPerson) {
                return ['success' => false, 'message' => 'Related person not found'];
            }

            if (!$this->validatePersonAccess($data['person_id'])) {
                return ['success' => false, 'message' => 'Access denied to person'];
            }

            if (!$this->validatePersonAccess($data['related_person_id'])) {
                return ['success' => false, 'message' => 'Access denied to related person'];
            }

            // Validate both people are in the same tenant context
            if (!$this->validateSameTenant($data['person_id'], $data['related_person_id'])) {
                return ['success' => false, 'message' => 'People must belong to the same tenant context'];
            }

            // Check if relationship already exists
            if ($this->relationshipRepo->existsBetweenPeople($data['person_id'], $data['related_person_id'])) {
                return ['success' => false, 'message' => 'Relationship already exists between these people'];
            }

            $relationshipData = [
                'person_id' => $data['person_id'],
                'related_person_id' => $data['related_person_id'],
                'relationship_type_id' => $data['relationship_type_id'],
                'is_primary' => $data['is_primary'] ?? 0,
                'start_date' => $data['start_date'] ?? date('Y-m-d'),
                'end_date' => $data['end_date'] ?? null,
                'status' => $data['status'] ?? 'active',
                'notes' => $data['notes'] ?? null
            ];

            $relationshipId = $this->relationshipRepo->create($relationshipData);

            $this->logAudit('RELATIONSHIP_CREATED', 'person_relationship', $relationshipId, [
                'person_id' => $data['person_id'],
                'related_person_id' => $data['related_person_id']
            ]);

            $relationship = $this->relationshipRepo->getById($relationshipId);
            return ['success' => true, 'message' => 'Relationship created successfully', 'data' => $relationship];
        } catch (Exception $e) {
            $this->logger->error('PersonRelationshipService::createRelationship error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error creating relationship: ' . $e->getMessage()];
        }
    }

    /**
     * Get all relationships for a person
     */
    public function getRelationships(int $personId): array
    {
        try {
            $person = $this->personRepo->getById($personId);
            if (!$person) {
                return ['success' => false, 'message' => 'Person not found'];
            }

            if (!$this->validatePersonAccess($personId)) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            $relationships = $this->relationshipRepo->getByPersonId($personId);
            return ['success' => true, 'data' => $relationships];
        } catch (Exception $e) {
            $this->logger->error('PersonRelationshipService::getRelationships error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching relationships: ' . $e->getMessage()];
        }
    }

    /**
     * Get a single relationship by ID
     */
    public function getRelationship(int $relationshipId): array
    {
        try {
            $relationship = $this->relationshipRepo->getById($relationshipId);
            if (!$relationship) {
                return ['success' => false, 'message' => 'Relationship not found'];
            }

            if (!$this->validatePersonAccess($relationship['person_id'])) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            return ['success' => true, 'data' => $relationship];
        } catch (Exception $e) {
            $this->logger->error('PersonRelationshipService::getRelationship error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching relationship: ' . $e->getMessage()];
        }
    }

    /**
     * Update a relationship
     */
    public function updateRelationship(int $relationshipId, array $data): array
    {
        try {
            $relationship = $this->relationshipRepo->getById($relationshipId);
            if (!$relationship) {
                return ['success' => false, 'message' => 'Relationship not found'];
            }

            if (!$this->validatePersonAccess($relationship['person_id'])) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            $result = $this->relationshipRepo->update($relationshipId, $data);

            if (!$result) {
                return ['success' => false, 'message' => 'No changes made'];
            }

            $this->logAudit('RELATIONSHIP_UPDATED', 'person_relationship', $relationshipId, array_keys($data));

            $updatedRelationship = $this->relationshipRepo->getById($relationshipId);
            return ['success' => true, 'message' => 'Relationship updated successfully', 'data' => $updatedRelationship];
        } catch (Exception $e) {
            $this->logger->error('PersonRelationshipService::updateRelationship error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error updating relationship: ' . $e->getMessage()];
        }
    }

    /**
     * End a relationship
     */
    public function endRelationship(int $relationshipId): array
    {
        try {
            $relationship = $this->relationshipRepo->getById($relationshipId);
            if (!$relationship) {
                return ['success' => false, 'message' => 'Relationship not found'];
            }

            if (!$this->validatePersonAccess($relationship['person_id'])) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            $result = $this->relationshipRepo->endRelationship($relationshipId);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to end relationship'];
            }

            $this->logAudit('RELATIONSHIP_ENDED', 'person_relationship', $relationshipId);

            $updatedRelationship = $this->relationshipRepo->getById($relationshipId);
            return ['success' => true, 'message' => 'Relationship ended successfully', 'data' => $updatedRelationship];
        } catch (Exception $e) {
            $this->logger->error('PersonRelationshipService::endRelationship error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error ending relationship: ' . $e->getMessage()];
        }
    }

    /**
     * Reactivate an ended relationship
     */
    public function reactivateRelationship(int $relationshipId): array
    {
        try {
            $relationship = $this->relationshipRepo->getById($relationshipId);
            if (!$relationship) {
                return ['success' => false, 'message' => 'Relationship not found'];
            }

            if (!$this->validatePersonAccess($relationship['person_id'])) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            $result = $this->relationshipRepo->reactivate($relationshipId);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to reactivate relationship'];
            }

            $this->logAudit('RELATIONSHIP_REACTIVATED', 'person_relationship', $relationshipId);

            $updatedRelationship = $this->relationshipRepo->getById($relationshipId);
            return ['success' => true, 'message' => 'Relationship reactivated successfully', 'data' => $updatedRelationship];
        } catch (Exception $e) {
            $this->logger->error('PersonRelationshipService::reactivateRelationship error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error reactivating relationship: ' . $e->getMessage()];
        }
    }

    /**
     * Delete a relationship (soft delete)
     */
    public function deleteRelationship(int $relationshipId): array
    {
        try {
            $relationship = $this->relationshipRepo->getById($relationshipId);
            if (!$relationship) {
                return ['success' => false, 'message' => 'Relationship not found'];
            }

            if (!$this->validatePersonAccess($relationship['person_id'])) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            $result = $this->relationshipRepo->delete($relationshipId);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to delete relationship'];
            }

            $this->logAudit('RELATIONSHIP_DELETED', 'person_relationship', $relationshipId);

            return ['success' => true, 'message' => 'Relationship deleted successfully'];
        } catch (Exception $e) {
            $this->logger->error('PersonRelationshipService::deleteRelationship error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error deleting relationship: ' . $e->getMessage()];
        }
    }

    /**
     * Get all relationships for a tenant
     */
    public function getTenantRelationships(int $limit = 100): array
    {
        try {
            $tenantId = $this->getTenantId();
            if (!$tenantId) {
                return ['success' => false, 'message' => 'Tenant context required'];
            }

            $relationships = $this->relationshipRepo->getByTenant($tenantId, $limit);
            return ['success' => true, 'data' => $relationships];
        } catch (Exception $e) {
            $this->logger->error('PersonRelationshipService::getTenantRelationships error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching tenant relationships: ' . $e->getMessage()];
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
