<?php

/**
 * GradingSystemService.php
 * Grading System Service - Business Logic
 * @package EduTrack
 * @subpackage Services\Platform\Assessment
 * @version 1.0
 */

require_once dirname(__DIR__, 3) . '/models/Platform/GradingSystem.php';
require_once dirname(__DIR__, 3) . '/services/Platform/BaseService.php';

class GradingSystemService extends BaseService
{
    private $gradingModel;

    public function __construct()
    {
        parent::__construct();
        $this->gradingModel = new GradingSystem();
    }

    /**
     * Get all grading systems for a school
     */
    public function getGradingSystems(int $schoolId): array
    {
        try {
            $systems = $this->gradingModel->getBySchool($schoolId);
            return [
                'success' => true,
                'data' => $systems
            ];
        } catch (Exception $e) {
            $this->logger->error('getGradingSystems error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get a specific grading system with scales
     */
    public function getGradingSystem(int $systemId): array
    {
        try {
            $system = $this->gradingModel->getWithScales($systemId);
            if (!$system) {
                return ['success' => false, 'message' => 'Grading system not found'];
            }

            return [
                'success' => true,
                'data' => $system
            ];
        } catch (Exception $e) {
            $this->logger->error('getGradingSystem error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Create a new grading system
     */
    public function createGradingSystem(array $data): array
    {
        try {
            $this->beginTransaction();

            // Validate
            $errors = $this->validateSystem($data);
            if (!empty($errors)) {
                $this->rollback();
                return ['success' => false, 'errors' => $errors];
            }

            // Generate UUID
            $data['uuid'] = $this->generateUUID();

            // Set defaults
            $data['is_active'] = $data['is_active'] ?? 1;
            $data['is_default'] = $data['is_default'] ?? 0;

            // Create system
            $systemId = $this->gradingModel->create($data);

            if (!$systemId) {
                $this->rollback();
                return ['success' => false, 'errors' => ['Failed to create grading system']];
            }

            $this->commit();

            return [
                'success' => true,
                'message' => 'Grading system created successfully',
                'data' => ['grading_system_id' => $systemId]
            ];
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('createGradingSystem error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update a grading system
     */
    public function updateGradingSystem(int $systemId, array $data): array
    {
        try {
            $this->beginTransaction();

            $system = $this->gradingModel->getById($systemId);
            if (!$system) {
                $this->rollback();
                return ['success' => false, 'message' => 'Grading system not found'];
            }

            $errors = $this->validateSystem($data, true);
            if (!empty($errors)) {
                $this->rollback();
                return ['success' => false, 'errors' => $errors];
            }

            $updated = $this->gradingModel->update($systemId, $data);

            if (!$updated) {
                $this->rollback();
                return ['success' => false, 'errors' => ['Failed to update grading system']];
            }

            $this->commit();

            return [
                'success' => true,
                'message' => 'Grading system updated successfully'
            ];
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('updateGradingSystem error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete a grading system
     */
    public function deleteGradingSystem(int $systemId): array
    {
        try {
            $this->beginTransaction();

            $system = $this->gradingModel->getById($systemId);
            if (!$system) {
                $this->rollback();
                return ['success' => false, 'message' => 'Grading system not found'];
            }

            // Check if in use
            if ($this->isInUse($systemId)) {
                $this->rollback();
                return ['success' => false, 'message' => 'Grading system is in use and cannot be deleted'];
            }

            $deleted = $this->gradingModel->softDelete($systemId);

            if (!$deleted) {
                $this->rollback();
                return ['success' => false, 'errors' => ['Failed to delete grading system']];
            }

            $this->commit();

            return ['success' => true, 'message' => 'Grading system deleted successfully'];
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('deleteGradingSystem error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Set a grading system as default
     */
    public function setDefault(int $systemId, int $schoolId): array
    {
        $system = $this->gradingModel->getById($systemId);
        if (!$system) {
            return ['success' => false, 'message' => 'Grading system not found'];
        }

        $updated = $this->gradingModel->setAsDefault($systemId, $schoolId);

        if (!$updated) {
            return ['success' => false, 'message' => 'Failed to set as default'];
        }

        return [
            'success' => true,
            'message' => 'Default grading system updated successfully'
        ];
    }

    /**
     * Get grade scales for a system
     */
    public function getGradeScales(int $systemId): array
    {
        try {
            $scales = $this->gradingModel->getGradeScales($systemId);
            return [
                'success' => true,
                'data' => $scales
            ];
        } catch (Exception $e) {
            $this->logger->error('getGradeScales error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Add a grade scale
     */
    public function addGradeScale(array $data): array
    {
        try {
            $this->beginTransaction();

            // Validate
            if (empty($data['grading_system_id']) || empty($data['grade'])) {
                $this->rollback();
                return ['success' => false, 'errors' => ['Grading system ID and grade are required']];
            }

            $scaleId = $this->gradingModel->addGradeScale($data);

            if (!$scaleId) {
                $this->rollback();
                return ['success' => false, 'errors' => ['Failed to add grade scale']];
            }

            $this->commit();

            return [
                'success' => true,
                'message' => 'Grade scale added successfully',
                'data' => ['grade_scale_id' => $scaleId]
            ];
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('addGradeScale error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update a grade scale
     */
    public function updateGradeScale(int $scaleId, array $data): array
    {
        try {
            $this->beginTransaction();

            $updated = $this->gradingModel->updateGradeScale($scaleId, $data);

            if (!$updated) {
                $this->rollback();
                return ['success' => false, 'errors' => ['Failed to update grade scale']];
            }

            $this->commit();

            return [
                'success' => true,
                'message' => 'Grade scale updated successfully'
            ];
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('updateGradeScale error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete a grade scale
     */
    public function deleteGradeScale(int $scaleId): array
    {
        try {
            $this->beginTransaction();

            $deleted = $this->gradingModel->deleteGradeScale($scaleId);

            if (!$deleted) {
                $this->rollback();
                return ['success' => false, 'errors' => ['Failed to delete grade scale']];
            }

            $this->commit();

            return ['success' => true, 'message' => 'Grade scale deleted successfully'];
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('deleteGradeScale error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Calculate grade from score
     */
    public function calculateGrade(int $systemId, float $score): array
    {
        try {
            $system = $this->gradingModel->getById($systemId);
            if (!$system) {
                return ['success' => false, 'message' => 'Grading system not found'];
            }

            $grade = $this->gradingModel->getGradeForScore($systemId, $score);

            if (!$grade) {
                return ['success' => false, 'message' => 'No grade found for the given score'];
            }

            return [
                'success' => true,
                'data' => [
                    'grade' => $grade['grade'],
                    'grade_point' => $grade['grade_point'],
                    'remark' => $grade['remark'],
                    'color' => $grade['color']
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('calculateGrade error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Check if grading system is in use
     */
    private function isInUse(int $systemId): bool
    {
        $sql = "SELECT 1 FROM assessment_profiles 
                WHERE grading_system_id = ? AND deleted_at IS NULL LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$systemId]);
        return (bool)$stmt->fetch();
    }

    /**
     * Validate system data
     */
    private function validateSystem(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        if (!$isUpdate || isset($data['system_name'])) {
            if (empty($data['system_name'])) {
                $errors[] = 'System name is required';
            }
        }

        if (!$isUpdate || isset($data['system_code'])) {
            if (empty($data['system_code'])) {
                $errors[] = 'System code is required';
            }
        }

        if (!$isUpdate || isset($data['school_id'])) {
            if (empty($data['school_id'])) {
                $errors[] = 'School ID is required';
            }
        }

        return $errors;
    }

    /**
     * Generate UUID
     */
    private function generateUUID(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
