<?php

/**
 * PersonLifecycleService.php
 * Service for person lifecycle and status management
 * 
 * @package EduTrack
 * @subpackage Services\Identity
 * @filepath app/services/Identity/PersonLifecycleService.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/ResponseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/services/Tenant/TenantContext.php';
require_once $projectRoot . 'app/repositories/Identity/PersonRepository.php';
require_once $projectRoot . 'app/repositories/Identity/PersonAssignmentRepository.php';

class PersonLifecycleService
{
    private $db;
    private $response;
    private $logger;
    private $context;
    private $personRepo;
    private $assignmentRepo;

    // Valid status transitions
    private $validTransitions = [
        'pending' => ['active', 'inactive', 'suspended'],
        'active' => ['inactive', 'suspended', 'archived'],
        'inactive' => ['active', 'archived'],
        'suspended' => ['active', 'inactive', 'archived'],
        'archived' => [] // Archived cannot be changed directly (use restore)
    ];

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->response = new ResponseHelper();
        $this->logger = new LoggerHelper();
        $this->context = TenantContext::getInstance();
        $this->personRepo = new PersonRepository();
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
     * Get valid status transitions for a person
     */
    public function getValidTransitions(int $personId): array
    {
        try {
            $person = $this->personRepo->getById($personId);
            if (!$person) {
                return ['success' => false, 'message' => 'Person not found'];
            }

            if (!$this->validatePersonAccess($personId)) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            $currentStatus = $person['status'];
            $transitions = $this->validTransitions[$currentStatus] ?? [];

            return ['success' => true, 'data' => [
                'current_status' => $currentStatus,
                'available_transitions' => $transitions
            ]];
        } catch (Exception $e) {
            $this->logger->error('PersonLifecycleService::getValidTransitions error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error getting transitions: ' . $e->getMessage()];
        }
    }

    /**
     * Change person status
     */
    public function changeStatus(int $personId, string $newStatus, ?string $reason = null): array
    {
        try {
            $person = $this->personRepo->getById($personId);
            if (!$person) {
                return ['success' => false, 'message' => 'Person not found'];
            }

            if (!$this->validatePersonAccess($personId)) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            $currentStatus = $person['status'];

            // Check if transition is valid
            if ($currentStatus === 'archived' && $newStatus !== 'archived') {
                return ['success' => false, 'message' => 'Archived persons must be restored before changing status'];
            }

            $validTransitions = $this->validTransitions[$currentStatus] ?? [];
            if ($newStatus !== $currentStatus && !in_array($newStatus, $validTransitions)) {
                return ['success' => false, 'message' => "Invalid status transition from '$currentStatus' to '$newStatus'"];
            }

            // If status is same, just return success
            if ($newStatus === $currentStatus) {
                return ['success' => true, 'message' => 'Status is already set to ' . $newStatus];
            }

            // Start transaction
            $this->db->beginTransaction();

            // Update person status
            $result = $this->personRepo->update($personId, ['status' => $newStatus]);
            if (!$result) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Failed to update status'];
            }

            // Record status history
            $sql = "INSERT INTO person_status_history (person_id, old_status, new_status, reason, changed_by, changed_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())";
            $this->db->execute($sql, [
                $personId,
                $currentStatus,
                $newStatus,
                $reason,
                $this->getUserId()
            ]);

            $this->db->commit();

            $this->logAudit('STATUS_CHANGED', 'person', $personId, [
                'old_status' => $currentStatus,
                'new_status' => $newStatus,
                'reason' => $reason
            ]);

            $updatedPerson = $this->personRepo->getById($personId);
            return ['success' => true, 'message' => 'Status updated successfully', 'data' => $updatedPerson];
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logger->error('PersonLifecycleService::changeStatus error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error changing status: ' . $e->getMessage()];
        }
    }

    /**
     * Activate a person
     */
    public function activate(int $personId, ?string $reason = null): array
    {
        return $this->changeStatus($personId, 'active', $reason);
    }

    /**
     * Inactivate a person
     */
    public function inactivate(int $personId, ?string $reason = null): array
    {
        return $this->changeStatus($personId, 'inactive', $reason);
    }

    /**
     * Suspend a person
     */
    public function suspend(int $personId, ?string $reason = null): array
    {
        return $this->changeStatus($personId, 'suspended', $reason);
    }

    /**
     * Archive a person
     */
    public function archive(int $personId, ?string $reason = null): array
    {
        // Check for active assignments before archiving
        $activeAssignments = $this->assignmentRepo->getActiveByPersonId($personId);
        if (!empty($activeAssignments)) {
            return ['success' => false, 'message' => 'Cannot archive person with active assignments. Please end all assignments first.'];
        }

        return $this->changeStatus($personId, 'archived', $reason);
    }

    /**
     * Get status history for a person
     */
    public function getStatusHistory(int $personId): array
    {
        try {
            $person = $this->personRepo->getById($personId);
            if (!$person) {
                return ['success' => false, 'message' => 'Person not found'];
            }

            if (!$this->validatePersonAccess($personId)) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            $sql = "SELECT psh.*, 
                           u.username as changed_by_username,
                           u.first_name as changed_by_first_name,
                           u.last_name as changed_by_last_name
                    FROM person_status_history psh
                    LEFT JOIN platform_users u ON psh.changed_by = u.id
                    WHERE psh.person_id = ?
                    ORDER BY psh.changed_at DESC";

            $history = $this->db->fetchAll($sql, [$personId]);
            return ['success' => true, 'data' => $history];
        } catch (Exception $e) {
            $this->logger->error('PersonLifecycleService::getStatusHistory error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching status history: ' . $e->getMessage()];
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
