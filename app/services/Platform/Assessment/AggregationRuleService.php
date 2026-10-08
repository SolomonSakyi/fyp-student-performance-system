<?php

/**
 * AggregationRuleService.php
 * Aggregation Rule Service - Business Logic
 * @package EduTrack
 * @subpackage Services\Platform\Assessment
 * @version 1.0
 */

require_once dirname(__DIR__, 3) . '/models/Platform/AggregationRule.php';
require_once dirname(__DIR__, 3) . '/services/Platform/BaseService.php';

class AggregationRuleService extends BaseService
{
    private $aggregationModel;

    public function __construct()
    {
        parent::__construct();
        $this->aggregationModel = new AggregationRule();
    }

    /**
     * Get all aggregation rules for a school
     */
    public function getAggregationRules(int $schoolId, ?string $status = null): array
    {
        try {
            if ($status) {
                $rules = $this->aggregationModel->getBySchoolAndStatus($schoolId, $status);
            } else {
                $rules = $this->aggregationModel->getBySchool($schoolId);
            }

            return [
                'success' => true,
                'data' => $rules
            ];
        } catch (Exception $e) {
            $this->logger->error('getAggregationRules error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get a specific aggregation rule
     */
    public function getAggregationRule(int $ruleId): array
    {
        try {
            $rule = $this->aggregationModel->getById($ruleId);
            if (!$rule) {
                return ['success' => false, 'message' => 'Aggregation rule not found'];
            }

            return [
                'success' => true,
                'data' => $rule
            ];
        } catch (Exception $e) {
            $this->logger->error('getAggregationRule error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Create a new aggregation rule
     */
    public function createAggregationRule(array $data): array
    {
        try {
            $this->beginTransaction();

            // Validate
            $errors = $this->aggregationModel->validate($data);
            if (!empty($errors)) {
                $this->rollback();
                return ['success' => false, 'errors' => $errors];
            }

            // Generate UUID
            $data['uuid'] = $this->generateUUID();

            // Set defaults
            $defaults = $this->aggregationModel->getDefaults();
            foreach ($defaults as $key => $value) {
                if (!isset($data[$key])) {
                    $data[$key] = $value;
                }
            }

            // Create rule
            $ruleId = $this->aggregationModel->create($data);

            if (!$ruleId) {
                $this->rollback();
                return ['success' => false, 'errors' => ['Failed to create aggregation rule']];
            }

            $this->commit();

            return [
                'success' => true,
                'message' => 'Aggregation rule created successfully',
                'data' => ['aggregation_rule_id' => $ruleId]
            ];
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('createAggregationRule error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update an aggregation rule
     */
    public function updateAggregationRule(int $ruleId, array $data): array
    {
        try {
            $this->beginTransaction();

            $rule = $this->aggregationModel->getById($ruleId);
            if (!$rule) {
                $this->rollback();
                return ['success' => false, 'message' => 'Aggregation rule not found'];
            }

            $errors = $this->aggregationModel->validate($data);
            if (!empty($errors)) {
                $this->rollback();
                return ['success' => false, 'errors' => $errors];
            }

            $updated = $this->aggregationModel->update($ruleId, $data);

            if (!$updated) {
                $this->rollback();
                return ['success' => false, 'errors' => ['Failed to update aggregation rule']];
            }

            $this->commit();

            return [
                'success' => true,
                'message' => 'Aggregation rule updated successfully'
            ];
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('updateAggregationRule error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete an aggregation rule
     */
    public function deleteAggregationRule(int $ruleId): array
    {
        try {
            $this->beginTransaction();

            $rule = $this->aggregationModel->getById($ruleId);
            if (!$rule) {
                $this->rollback();
                return ['success' => false, 'message' => 'Aggregation rule not found'];
            }

            // Check if in use
            if ($this->isInUse($ruleId)) {
                $this->rollback();
                return ['success' => false, 'message' => 'Aggregation rule is in use and cannot be deleted'];
            }

            $deleted = $this->aggregationModel->softDelete($ruleId);

            if (!$deleted) {
                $this->rollback();
                return ['success' => false, 'errors' => ['Failed to delete aggregation rule']];
            }

            $this->commit();

            return ['success' => true, 'message' => 'Aggregation rule deleted successfully'];
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('deleteAggregationRule error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Calculate aggregate from scores
     */
    public function calculateAggregate(int $ruleId, array $scores): array
    {
        try {
            $rule = $this->aggregationModel->getById($ruleId);
            if (!$rule) {
                return ['success' => false, 'message' => 'Aggregation rule not found'];
            }

            $aggregate = $this->aggregationModel->calculateAggregate($scores, $rule);

            return [
                'success' => true,
                'data' => [
                    'aggregate' => $aggregate,
                    'rule' => $rule
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('calculateAggregate error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Check if aggregation rule is in use
     */
    private function isInUse(int $ruleId): bool
    {
        $sql = "SELECT 1 FROM assessment_profiles 
                WHERE aggregation_rule_id = ? AND deleted_at IS NULL LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$ruleId]);
        return (bool)$stmt->fetch();
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
