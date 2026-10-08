<?php

/**
 * PersonAddressService.php
 * Service for person address management operations
 * 
 * @package EduTrack
 * @subpackage Services\Identity
 * @filepath app/services/Identity/PersonAddressService.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/ResponseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/services/Tenant/TenantContext.php';
require_once $projectRoot . 'app/repositories/Identity/PersonRepository.php';
require_once $projectRoot . 'app/repositories/Identity/PersonAddressRepository.php';
require_once $projectRoot . 'app/repositories/Identity/PersonAssignmentRepository.php';

class PersonAddressService
{
    private $db;
    private $response;
    private $logger;
    private $context;
    private $personRepo;
    private $addressRepo;
    private $assignmentRepo;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->response = new ResponseHelper();
        $this->logger = new LoggerHelper();
        $this->context = TenantContext::getInstance();
        $this->personRepo = new PersonRepository();
        $this->addressRepo = new PersonAddressRepository();
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
     * Add an address to a person
     */
    public function addAddress(int $personId, array $data): array
    {
        try {
            $person = $this->personRepo->getById($personId);
            if (!$person) {
                return ['success' => false, 'message' => 'Person not found'];
            }

            if (!$this->validatePersonAccess($personId)) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            if (empty($data['address_type_id']) || empty($data['address_line_1'])) {
                return ['success' => false, 'message' => 'Address type and address line are required'];
            }

            // If this is primary, unset other primary addresses
            if (!empty($data['is_primary'])) {
                $this->addressRepo->setPrimary($personId, 0);
            }

            $addressData = [
                'person_id' => $personId,
                'address_type_id' => $data['address_type_id'],
                'address_line_1' => $data['address_line_1'],
                'address_line_2' => $data['address_line_2'] ?? null,
                'city' => $data['city'] ?? null,
                'district' => $data['district'] ?? null,
                'region' => $data['region'] ?? null,
                'state_province' => $data['state_province'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'country' => $data['country'] ?? 'Ghana',
                'digital_address' => $data['digital_address'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'is_primary' => $data['is_primary'] ?? 0
            ];

            $addressId = $this->addressRepo->create($addressData);

            if (!empty($data['is_primary'])) {
                $this->addressRepo->setPrimary($personId, $addressId);
            }

            $this->logAudit('ADDRESS_ADDED', 'person_address', $addressId, [
                'person_id' => $personId,
                'address_line_1' => $data['address_line_1']
            ]);

            $address = $this->addressRepo->getById($addressId);
            return ['success' => true, 'message' => 'Address added successfully', 'data' => $address];
        } catch (Exception $e) {
            $this->logger->error('PersonAddressService::addAddress error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error adding address: ' . $e->getMessage()];
        }
    }

    /**
     * Get all addresses for a person
     */
    public function getAddresses(int $personId): array
    {
        try {
            $person = $this->personRepo->getById($personId);
            if (!$person) {
                return ['success' => false, 'message' => 'Person not found'];
            }

            if (!$this->validatePersonAccess($personId)) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            $addresses = $this->addressRepo->getByPersonId($personId);
            return ['success' => true, 'data' => $addresses];
        } catch (Exception $e) {
            $this->logger->error('PersonAddressService::getAddresses error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching addresses: ' . $e->getMessage()];
        }
    }

    /**
     * Get a single address by ID
     */
    public function getAddress(int $addressId): array
    {
        try {
            $address = $this->addressRepo->getById($addressId);
            if (!$address) {
                return ['success' => false, 'message' => 'Address not found'];
            }

            if (!$this->validatePersonAccess($address['person_id'])) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            return ['success' => true, 'data' => $address];
        } catch (Exception $e) {
            $this->logger->error('PersonAddressService::getAddress error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching address: ' . $e->getMessage()];
        }
    }

    /**
     * Update an address
     */
    public function updateAddress(int $addressId, array $data): array
    {
        try {
            $address = $this->addressRepo->getById($addressId);
            if (!$address) {
                return ['success' => false, 'message' => 'Address not found'];
            }

            if (!$this->validatePersonAccess($address['person_id'])) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            // If setting as primary, unset other primary addresses
            if (!empty($data['is_primary'])) {
                $this->addressRepo->setPrimary($address['person_id'], $addressId);
            }

            $result = $this->addressRepo->update($addressId, $data);

            if (!$result) {
                return ['success' => false, 'message' => 'No changes made'];
            }

            $this->logAudit('ADDRESS_UPDATED', 'person_address', $addressId, array_keys($data));

            $updatedAddress = $this->addressRepo->getById($addressId);
            return ['success' => true, 'message' => 'Address updated successfully', 'data' => $updatedAddress];
        } catch (Exception $e) {
            $this->logger->error('PersonAddressService::updateAddress error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error updating address: ' . $e->getMessage()];
        }
    }

    /**
     * Set an address as primary
     */
    public function setPrimaryAddress(int $addressId): array
    {
        try {
            $address = $this->addressRepo->getById($addressId);
            if (!$address) {
                return ['success' => false, 'message' => 'Address not found'];
            }

            if (!$this->validatePersonAccess($address['person_id'])) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            $this->addressRepo->setPrimary($address['person_id'], $addressId);

            $this->logAudit('ADDRESS_PRIMARY', 'person_address', $addressId);

            $updatedAddress = $this->addressRepo->getById($addressId);
            return ['success' => true, 'message' => 'Primary address updated', 'data' => $updatedAddress];
        } catch (Exception $e) {
            $this->logger->error('PersonAddressService::setPrimaryAddress error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error setting primary address: ' . $e->getMessage()];
        }
    }

    /**
     * Delete an address (soft delete)
     */
    public function deleteAddress(int $addressId): array
    {
        try {
            $address = $this->addressRepo->getById($addressId);
            if (!$address) {
                return ['success' => false, 'message' => 'Address not found'];
            }

            if (!$this->validatePersonAccess($address['person_id'])) {
                return ['success' => false, 'message' => 'Access denied'];
            }

            $result = $this->addressRepo->delete($addressId);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to delete address'];
            }

            $this->logAudit('ADDRESS_DELETED', 'person_address', $addressId);

            return ['success' => true, 'message' => 'Address deleted successfully'];
        } catch (Exception $e) {
            $this->logger->error('PersonAddressService::deleteAddress error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error deleting address: ' . $e->getMessage()];
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
