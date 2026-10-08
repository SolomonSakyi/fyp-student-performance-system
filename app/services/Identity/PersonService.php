<?php

/**
 * PersonService.php
 * Service for person operations
 * 
 * @package EduTrack
 * @subpackage Services\Identity
 * @filepath app/services/Identity/PersonService.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/repositories/Identity/PersonRepository.php';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class PersonService
{
    private $personRepo;
    private $db;
    private $logger;

    public function __construct()
    {
        $this->personRepo = new PersonRepository();
        $this->db = DatabaseHelper::getInstance();
        // Fix: Use getInstance() instead of new LoggerHelper()
        $this->logger = LoggerHelper::getInstance();
    }

    /**
     * Create a new person
     */
    public function createPerson(array $data): array
    {
        try {
            return $this->personRepo->create($data);
        } catch (Exception $e) {
            $this->logger->error('PersonService::createPerson error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get person by ID
     */
    public function getPerson(int $personId): array
    {
        return $this->personRepo->getById($personId);
    }

    /**
     * Get person by UUID
     */
    public function getPersonByUuid(string $uuid): array
    {
        return $this->personRepo->getByUuid($uuid);
    }

    /**
     * Update person
     */
    public function updatePerson(int $personId, array $data): array
    {
        try {
            return $this->personRepo->update($personId, $data);
        } catch (Exception $e) {
            $this->logger->error('PersonService::updatePerson error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete person
     */
    public function deletePerson(int $personId, int $deletedBy = null): bool
    {
        try {
            return $this->personRepo->delete($personId, $deletedBy);
        } catch (Exception $e) {
            $this->logger->error('PersonService::deletePerson error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Search people
     */
    public function searchPeople(string $query, array $filters = [], int $limit = 20): array
    {
        return $this->personRepo->search($query, $filters, $limit);
    }
}
