<?php

/**
 * PromotionService.php
 * Service for managing student promotion rules
 * 
 * @package EduTrack
 * @subpackage Services\School
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/helpers/DatabaseHelper.php';
require_once dirname(__DIR__, 2) . '/services/Tenant/TenantContext.php';
require_once dirname(__DIR__, 2) . '/services/Authorization/AuthorizationService.php';

class PromotionService
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
     * Get all promotion rules for a school
     */
    public function getPromotionRules(int $schoolId, ?int $levelId = null): array
    {
        if (!$this->auth->canAccessSchool($schoolId)) {
            throw new Exception('Unauthorized access to promotion rules');
        }

        $sql = "SELECT * FROM promotion_rules 
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
     * Get a specific promotion rule
     */
    public function getPromotionRule(int $ruleId): ?array
    {
        $sql = "SELECT * FROM promotion_rules WHERE id = ? AND deleted_at IS NULL";
        $rule = $this->db->fetchOne($sql, [$ruleId]);

        if (!$rule) {
            return null;
        }

        // Verify authorization
        if (!$this->auth->canAccessSchool($rule['school_id'])) {
            throw new Exception('Unauthorized access to promotion rule');
        }

        return $rule;
    }

    /**
     * Create a new promotion rule
     */
    public function createPromotionRule(int $schoolId, array $data): array
    {
        if (!$this->auth->canManageSchool($schoolId)) {
            throw new Exception('Unauthorized to manage promotion rules');
        }

        $errors = $this->validateRuleData($data);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $uuid = $this->generateUUID();
        $userId = $this->context->getUserId();

        $sql = "INSERT INTO promotion_rules (
                    uuid, school_id, rule_code, rule_name, description,
                    min_average, min_passed_subjects, max_failed_subjects,
                    core_subjects_passed_required, elective_subjects_passed_required,
                    allow_conditional_promotion, conditional_min_average,
                    conditional_max_failed, promote_action, is_default,
                    is_active, status, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $this->db->query($sql, [
            $uuid,
            $schoolId,
            $data['rule_code'],
            $data['rule_name'],
            $data['description'] ?? '',
            $data['min_average'] ?? 50,
            $data['min_passed_subjects'] ?? 4,
            $data['max_failed_subjects'] ?? 2,
            $data['core_subjects_passed_required'] ?? 3,
            $data['elective_subjects_passed_required'] ?? 2,
            $data['allow_conditional_promotion'] ?? 0,
            $data['conditional_min_average'] ?? 45,
            $data['conditional_max_failed'] ?? 3,
            $data['promote_action'] ?? 'promote',
            $data['is_default'] ?? 0,
            $data['is_active'] ?? 1,
            $data['status'] ?? 'draft',
            $userId
        ]);

        $ruleId = $this->db->lastInsertId();

        // If this is default, unset other defaults
        if ($data['is_default'] ?? 0) {
            $this->unsetDefaultRules($schoolId, $ruleId);
        }

        return [
            'success' => true,
            'message' => 'Promotion rule created successfully',
            'rule_id' => $ruleId
        ];
    }

    /**
     * Update a promotion rule
     */
    public function updatePromotionRule(int $ruleId, array $data): array
    {
        $rule = $this->getPromotionRule($ruleId);
        if (!$rule) {
            return ['success' => false, 'errors' => ['Promotion rule not found']];
        }

        if (!$this->auth->canManageSchool($rule['school_id'])) {
            throw new Exception('Unauthorized to manage promotion rules');
        }

        $sql = "UPDATE promotion_rules SET ";
        $updates = [];
        $params = [];

        $allowedFields = [
            'rule_code',
            'rule_name',
            'description',
            'min_average',
            'min_passed_subjects',
            'max_failed_subjects',
            'core_subjects_passed_required',
            'elective_subjects_passed_required',
            'allow_conditional_promotion',
            'conditional_min_average',
            'conditional_max_failed',
            'promote_action',
            'is_default',
            'is_active',
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
        $sql .= ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        $params[] = $ruleId;

        $this->db->query($sql, $params);

        // If this is default, unset other defaults
        if (isset($data['is_default']) && $data['is_default']) {
            $this->unsetDefaultRules($rule['school_id'], $ruleId);
        }

        return [
            'success' => true,
            'message' => 'Promotion rule updated successfully'
        ];
    }

    /**
     * Delete a promotion rule (soft delete)
     */
    public function deletePromotionRule(int $ruleId): array
    {
        $rule = $this->getPromotionRule($ruleId);
        if (!$rule) {
            return ['success' => false, 'errors' => ['Promotion rule not found']];
        }

        $sql = "UPDATE promotion_rules SET deleted_at = NOW(), is_active = 0 
                WHERE id = ? AND deleted_at IS NULL";

        $this->db->query($sql, [$ruleId]);

        return [
            'success' => true,
            'message' => 'Promotion rule deleted successfully'
        ];
    }

    /**
     * Evaluate a student for promotion
     */
    public function evaluateStudentPromotion(int $studentId, int $ruleId): array
    {
        $rule = $this->getPromotionRule($ruleId);
        if (!$rule) {
            return ['success' => false, 'errors' => ['Promotion rule not found']];
        }

        // Get student's results
        $results = $this->db->fetchAll(
            "SELECT 
                am.subject_id,
                ss.subject_name,
                ss.is_core,
                ss.is_elective,
                am.score,
                am.grade,
                am.grade_point,
                am.is_passed
            FROM assessment_marks am
            JOIN school_subjects ss ON am.subject_id = ss.id
            WHERE am.student_id = ? AND am.deleted_at IS NULL",
            [$studentId]
        );

        // Calculate statistics
        $totalSubjects = count($results);
        $passedSubjects = 0;
        $failedSubjects = 0;
        $corePassed = 0;
        $coreFailed = 0;
        $electivePassed = 0;
        $electiveFailed = 0;
        $totalScore = 0;

        foreach ($results as $result) {
            $totalScore += $result['score'];
            if ($result['is_passed']) {
                $passedSubjects++;
                if ($result['is_core']) {
                    $corePassed++;
                } else {
                    $electivePassed++;
                }
            } else {
                $failedSubjects++;
                if ($result['is_core']) {
                    $coreFailed++;
                } else {
                    $electiveFailed++;
                }
            }
        }

        $average = $totalSubjects > 0 ? round($totalScore / $totalSubjects, 2) : 0;

        // Check promotion criteria
        $canPromote = true;
        $reasons = [];

        // Check minimum average
        if ($average < $rule['min_average']) {
            $canPromote = false;
            $reasons[] = "Average score ($average%) is below minimum required ({$rule['min_average']}%)";
        }

        // Check minimum passed subjects
        if ($passedSubjects < $rule['min_passed_subjects']) {
            $canPromote = false;
            $reasons[] = "Passed subjects ($passedSubjects) is below minimum required ({$rule['min_passed_subjects']})";
        }

        // Check maximum failed subjects
        if ($failedSubjects > $rule['max_failed_subjects']) {
            $canPromote = false;
            $reasons[] = "Failed subjects ($failedSubjects) exceeds maximum allowed ({$rule['max_failed_subjects']})";
        }

        // Check core subjects passed
        if ($corePassed < $rule['core_subjects_passed_required']) {
            $canPromote = false;
            $reasons[] = "Core subjects passed ($corePassed) is below minimum required ({$rule['core_subjects_passed_required']})";
        }

        // Check elective subjects passed
        if ($electivePassed < $rule['elective_subjects_passed_required']) {
            $canPromote = false;
            $reasons[] = "Elective subjects passed ($electivePassed) is below minimum required ({$rule['elective_subjects_passed_required']})";
        }

        // Check conditional promotion
        $canBeConditional = false;
        if ($rule['allow_conditional_promotion'] && !$canPromote) {
            if (
                $average >= $rule['conditional_min_average'] &&
                $failedSubjects <= $rule['conditional_max_failed']
            ) {
                $canBeConditional = true;
                $reasons = [];
                $reasons[] = "Student qualifies for conditional promotion";
            }
        }

        // Determine action
        if ($canPromote) {
            $action = 'promote';
            $status = 'promoted';
        } elseif ($canBeConditional) {
            $action = 'conditionally_promote';
            $status = 'conditional';
        } else {
            $action = 'repeat';
            $status = 'repeating';
        }

        return [
            'success' => true,
            'evaluation' => [
                'student_id' => $studentId,
                'rule_id' => $ruleId,
                'action' => $action,
                'status' => $status,
                'can_promote' => $canPromote,
                'can_be_conditional' => $canBeConditional,
                'reasons' => $reasons,
                'statistics' => [
                    'total_subjects' => $totalSubjects,
                    'passed_subjects' => $passedSubjects,
                    'failed_subjects' => $failedSubjects,
                    'core_passed' => $corePassed,
                    'core_failed' => $coreFailed,
                    'elective_passed' => $electivePassed,
                    'elective_failed' => $electiveFailed,
                    'average' => $average
                ]
            ]
        ];
    }

    /**
     * Get promotion statistics
     */
    public function getPromotionStats(int $schoolId): array
    {
        $stats = $this->db->fetchOne(
            "SELECT 
                COUNT(*) as total_rules,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_rules,
                SUM(CASE WHEN is_default = 1 THEN 1 ELSE 0 END) as default_rules
            FROM promotion_rules
            WHERE school_id = ? AND deleted_at IS NULL",
            [$schoolId]
        );

        return [
            'total_rules' => (int)($stats['total_rules'] ?? 0),
            'active_rules' => (int)($stats['active_rules'] ?? 0),
            'default_rules' => (int)($stats['default_rules'] ?? 0)
        ];
    }

    /**
     * Unset default rules
     */
    private function unsetDefaultRules(int $schoolId, int $excludeRuleId): void
    {
        $sql = "UPDATE promotion_rules 
                SET is_default = 0 
                WHERE school_id = ? AND id != ? AND deleted_at IS NULL";

        $this->db->query($sql, [$schoolId, $excludeRuleId]);
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

        if (isset($data['min_average']) && ($data['min_average'] < 0 || $data['min_average'] > 100)) {
            $errors[] = 'Minimum average must be between 0 and 100';
        }

        if (isset($data['min_passed_subjects']) && $data['min_passed_subjects'] < 0) {
            $errors[] = 'Minimum passed subjects cannot be negative';
        }

        if (isset($data['max_failed_subjects']) && $data['max_failed_subjects'] < 0) {
            $errors[] = 'Maximum failed subjects cannot be negative';
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
