<?php

/**
 * AggregationService.php
 * Service for managing aggregation rules
 * 
 * @package EduTrack
 * @subpackage Services\School
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/helpers/DatabaseHelper.php';
require_once dirname(__DIR__, 2) . '/services/Tenant/TenantContext.php';
require_once dirname(__DIR__, 2) . '/services/Authorization/AuthorizationService.php';

class AggregationService
{
    private $db;
    private $context;
    private $auth;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->context = TenantContext::getInstance();
        $this->auth = new AuthorizationService();
    }

    /**
     * Get all aggregation rules for a school
     */
    public function getAggregationRules(int $schoolId, ?int $levelId = null): array
    {
        if (!$this->auth->canAccessSchool($schoolId)) {
            throw new Exception('Unauthorized access to aggregation rules');
        }

        $sql = "SELECT * FROM aggregation_rules 
                WHERE school_id = ? AND deleted_at IS NULL";

        $params = [$schoolId];

        if ($levelId) {
            $sql .= " AND (level_id = ? OR level_id IS NULL)";
            $params[] = $levelId;
        }

        $sql .= " ORDER BY is_default DESC, rule_name ASC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get a specific aggregation rule
     */
    public function getAggregationRule(int $ruleId): ?array
    {
        $sql = "SELECT * FROM aggregation_rules WHERE aggregation_rule_id = ? AND deleted_at IS NULL";
        $rule = $this->db->fetchOne($sql, [$ruleId]);

        if (!$rule) {
            return null;
        }

        // Verify authorization
        if (!$this->auth->canAccessSchool($rule['school_id'])) {
            throw new Exception('Unauthorized access to aggregation rule');
        }

        return $rule;
    }

    /**
     * Create a new aggregation rule
     */
    public function createAggregationRule(int $schoolId, array $data): array
    {
        if (!$this->auth->canManageSchool($schoolId)) {
            throw new Exception('Unauthorized to manage aggregation rules');
        }

        $errors = $this->validateRuleData($data);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $uuid = $this->generateUUID();
        $tenantId = $this->context->getTenantId();
        $userId = $this->context->getUserId();

        $sql = "INSERT INTO aggregation_rules (
                    uuid, school_id, tenant_id, rule_name, rule_code,
                    description, core_count, elective_count, core_selection,
                    elective_selection, aggregation_method, tie_break_method,
                    include_passed_only, minimum_subjects_required, status,
                    created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $this->db->query($sql, [
            $uuid,
            $schoolId,
            $tenantId,
            $data['rule_name'],
            $data['rule_code'],
            $data['description'] ?? '',
            $data['core_count'] ?? 4,
            $data['elective_count'] ?? 2,
            $data['core_selection'] ?? 'mandatory',
            $data['elective_selection'] ?? 'best',
            $data['aggregation_method'] ?? 'sum',
            $data['tie_break_method'] ?? 'highest_raw',
            $data['include_passed_only'] ?? 1,
            $data['minimum_subjects_required'] ?? 6,
            $data['status'] ?? 'active',
            $userId
        ]);

        $ruleId = $this->db->lastInsertId();

        return [
            'success' => true,
            'message' => 'Aggregation rule created successfully',
            'rule_id' => $ruleId
        ];
    }

    /**
     * Update an aggregation rule
     */
    public function updateAggregationRule(int $ruleId, array $data): array
    {
        $rule = $this->getAggregationRule($ruleId);
        if (!$rule) {
            return ['success' => false, 'errors' => ['Aggregation rule not found']];
        }

        if (!$this->auth->canManageSchool($rule['school_id'])) {
            throw new Exception('Unauthorized to manage aggregation rules');
        }

        $sql = "UPDATE aggregation_rules SET ";
        $updates = [];
        $params = [];

        $allowedFields = [
            'rule_name',
            'rule_code',
            'description',
            'core_count',
            'elective_count',
            'core_selection',
            'elective_selection',
            'aggregation_method',
            'tie_break_method',
            'include_passed_only',
            'minimum_subjects_required',
            'status'
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($updates)) {
            return ['success' => false, 'errors' => ['No fields to update']];
        }

        $sql .= implode(', ', $updates);
        $sql .= ", updated_at = NOW() WHERE aggregation_rule_id = ? AND deleted_at IS NULL";
        $params[] = $ruleId;

        $this->db->query($sql, $params);

        return [
            'success' => true,
            'message' => 'Aggregation rule updated successfully'
        ];
    }

    /**
     * Delete an aggregation rule (soft delete)
     */
    public function deleteAggregationRule(int $ruleId): array
    {
        $rule = $this->getAggregationRule($ruleId);
        if (!$rule) {
            return ['success' => false, 'errors' => ['Aggregation rule not found']];
        }

        $sql = "UPDATE aggregation_rules SET deleted_at = NOW(), status = 'archived' 
                WHERE aggregation_rule_id = ? AND deleted_at IS NULL";

        $this->db->query($sql, [$ruleId]);

        return [
            'success' => true,
            'message' => 'Aggregation rule deleted successfully'
        ];
    }

    /**
     * Calculate aggregate for a student
     */
    public function calculateAggregate(int $studentId, int $ruleId): array
    {
        $rule = $this->getAggregationRule($ruleId);
        if (!$rule) {
            return ['success' => false, 'errors' => ['Aggregation rule not found']];
        }

        // Get student's subjects and scores
        $scores = $this->db->fetchAll(
            "SELECT 
                ss.id as subject_id,
                ss.subject_name,
                ss.is_core,
                ss.is_elective,
                am.score,
                am.grade,
                am.grade_point
            FROM assessment_marks am
            JOIN school_subjects ss ON am.subject_id = ss.id
            WHERE am.student_id = ? AND am.deleted_at IS NULL",
            [$studentId]
        );

        // Separate core and elective subjects
        $coreSubjects = [];
        $electiveSubjects = [];

        foreach ($scores as $score) {
            if ($score['is_core']) {
                $coreSubjects[] = $score;
            } else {
                $electiveSubjects[] = $score;
            }
        }

        // Sort electives by grade point (descending)
        usort($electiveSubjects, function ($a, $b) {
            return $b['grade_point'] - $a['grade_point'];
        });

        // Select best electives
        $selectedElectives = array_slice($electiveSubjects, 0, $rule['elective_count']);

        // Combine core + selected electives
        $allSubjects = array_merge($coreSubjects, $selectedElectives);

        // Calculate aggregate
        $totalPoints = 0;
        $subjectCount = count($allSubjects);

        foreach ($allSubjects as $subject) {
            $totalPoints += $subject['grade_point'];
        }

        $aggregate = $subjectCount > 0 ? round($totalPoints / $subjectCount, 2) : 0;

        return [
            'success' => true,
            'aggregate' => $aggregate,
            'total_points' => $totalPoints,
            'subject_count' => $subjectCount,
            'core_subjects' => $coreSubjects,
            'selected_electives' => $selectedElectives,
            'all_subjects' => $allSubjects
        ];
    }

    /**
     * Get aggregation statistics
     */
    public function getAggregationStats(int $schoolId): array
    {
        $stats = $this->db->fetchOne(
            "SELECT 
                COUNT(*) as total_rules,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_rules
            FROM aggregation_rules
            WHERE school_id = ? AND deleted_at IS NULL",
            [$schoolId]
        );

        return [
            'total_rules' => (int)($stats['total_rules'] ?? 0),
            'active_rules' => (int)($stats['active_rules'] ?? 0)
        ];
    }

    /**
     * Validate rule data
     */
    private function validateRuleData(array $data): array
    {
        $errors = [];

        if (empty($data['rule_name'])) {
            $errors[] = 'Rule name is required';
        }

        if (empty($data['rule_code'])) {
            $errors[] = 'Rule code is required';
        }

        if (isset($data['core_count']) && $data['core_count'] < 0) {
            $errors[] = 'Core count cannot be negative';
        }

        if (isset($data['elective_count']) && $data['elective_count'] < 0) {
            $errors[] = 'Elective count cannot be negative';
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
            mt_rand(0, 0xffff) | 0x4000,
            mt_rand(0, 0x3ffff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
