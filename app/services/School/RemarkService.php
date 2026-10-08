<?php

/**
 * RemarkService.php
 * Service for managing automatic remark rules
 * 
 * @package EduTrack
 * @subpackage Services\School
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/helpers/DatabaseHelper.php';
require_once dirname(__DIR__, 2) . '/services/Tenant/TenantContext.php';
require_once dirname(__DIR__, 2) . '/services/Authorization/AuthorizationService.php';

class RemarkService
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
     * Get all remark rules for a school
     */
    public function getRemarkRules(int $schoolId, ?int $levelId = null): array
    {
        if (!$this->auth->canAccessSchool($schoolId)) {
            throw new Exception('Unauthorized access to remark rules');
        }

        $sql = "SELECT * FROM remark_rules 
                WHERE school_id = ? AND deleted_at IS NULL";

        $params = [$schoolId];

        if ($levelId) {
            $sql .= " AND (level_id = ? OR level_id IS NULL)";
            $params[] = $levelId;
        }

        $sql .= " ORDER BY priority ASC, min_score DESC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get remark rules by condition type
     */
    public function getRemarkRulesByType(int $schoolId, string $conditionType): array
    {
        if (!$this->auth->canAccessSchool($schoolId)) {
            throw new Exception('Unauthorized access to remark rules');
        }

        $sql = "SELECT * FROM remark_rules 
                WHERE school_id = ? AND condition_type = ? AND deleted_at IS NULL
                ORDER BY priority ASC, min_score DESC";

        return $this->db->fetchAll($sql, [$schoolId, $conditionType]);
    }

    /**
     * Get a specific remark rule
     */
    public function getRemarkRule(int $ruleId): ?array
    {
        $sql = "SELECT * FROM remark_rules WHERE id = ? AND deleted_at IS NULL";
        $rule = $this->db->fetchOne($sql, [$ruleId]);

        if (!$rule) {
            return null;
        }

        // Verify authorization
        if (!$this->auth->canAccessSchool($rule['school_id'])) {
            throw new Exception('Unauthorized access to remark rule');
        }

        return $rule;
    }

    /**
     * Create a new remark rule
     */
    public function createRemarkRule(int $schoolId, array $data): array
    {
        if (!$this->auth->canManageSchool($schoolId)) {
            throw new Exception('Unauthorized to manage remark rules');
        }

        $errors = $this->validateRuleData($data);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $uuid = $this->generateUUID();
        $userId = $this->context->getUserId();

        $sql = "INSERT INTO remark_rules (
                    uuid, school_id, rule_code, rule_name, remark_text,
                    min_score, max_score, condition_type, priority,
                    is_default, is_active, status, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $this->db->query($sql, [
            $uuid,
            $schoolId,
            $data['rule_code'],
            $data['rule_name'],
            $data['remark_text'],
            $data['min_score'] ?? null,
            $data['max_score'] ?? null,
            $data['condition_type'] ?? 'academic',
            $data['priority'] ?? 0,
            $data['is_default'] ?? 0,
            $data['is_active'] ?? 1,
            $data['status'] ?? 'draft',
            $userId
        ]);

        $ruleId = $this->db->lastInsertId();

        return [
            'success' => true,
            'message' => 'Remark rule created successfully',
            'rule_id' => $ruleId
        ];
    }

    /**
     * Update a remark rule
     */
    public function updateRemarkRule(int $ruleId, array $data): array
    {
        $rule = $this->getRemarkRule($ruleId);
        if (!$rule) {
            return ['success' => false, 'errors' => ['Remark rule not found']];
        }

        if (!$this->auth->canManageSchool($rule['school_id'])) {
            throw new Exception('Unauthorized to manage remark rules');
        }

        $sql = "UPDATE remark_rules SET ";
        $updates = [];
        $params = [];

        $allowedFields = [
            'rule_code',
            'rule_name',
            'remark_text',
            'min_score',
            'max_score',
            'condition_type',
            'priority',
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

        return [
            'success' => true,
            'message' => 'Remark rule updated successfully'
        ];
    }

    /**
     * Delete a remark rule (soft delete)
     */
    public function deleteRemarkRule(int $ruleId): array
    {
        $rule = $this->getRemarkRule($ruleId);
        if (!$rule) {
            return ['success' => false, 'errors' => ['Remark rule not found']];
        }

        $sql = "UPDATE remark_rules SET deleted_at = NOW(), is_active = 0 
                WHERE id = ? AND deleted_at IS NULL";

        $this->db->query($sql, [$ruleId]);

        return [
            'success' => true,
            'message' => 'Remark rule deleted successfully'
        ];
    }

    /**
     * Generate remark based on score
     */
    public function generateRemark(int $schoolId, float $score, ?int $levelId = null): string
    {
        $params = [$schoolId];
        $sql = "SELECT * FROM remark_rules 
                WHERE school_id = ? AND is_active = 1 AND status = 'published' AND deleted_at IS NULL";

        if ($levelId) {
            $sql .= " AND (level_id = ? OR level_id IS NULL)";
            $params[] = $levelId;
        }

        $sql .= " AND min_score <= ? AND max_score >= ?";
        $params[] = $score;
        $params[] = $score;

        $sql .= " ORDER BY priority ASC LIMIT 1";

        $rule = $this->db->fetchOne($sql, $params);

        return $rule ? $rule['remark_text'] : 'Performance recorded';
    }

    /**
     * Generate remark with context (subject/overall)
     */
    public function generateContextualRemark(int $schoolId, float $score, string $context, ?int $levelId = null): array
    {
        $remark = $this->generateRemark($schoolId, $score, $levelId);

        return [
            'score' => $score,
            'remark' => $remark,
            'context' => $context,
            'level_id' => $levelId,
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Get remark statistics
     */
    public function getRemarkStats(int $schoolId): array
    {
        $stats = $this->db->fetchOne(
            "SELECT 
                COUNT(*) as total_rules,
                SUM(CASE WHEN condition_type = 'academic' THEN 1 ELSE 0 END) as academic_rules,
                SUM(CASE WHEN condition_type = 'behavior' THEN 1 ELSE 0 END) as behavior_rules,
                SUM(CASE WHEN condition_type = 'attendance' THEN 1 ELSE 0 END) as attendance_rules,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_rules
            FROM remark_rules
            WHERE school_id = ? AND deleted_at IS NULL",
            [$schoolId]
        );

        return [
            'total_rules' => (int)($stats['total_rules'] ?? 0),
            'academic_rules' => (int)($stats['academic_rules'] ?? 0),
            'behavior_rules' => (int)($stats['behavior_rules'] ?? 0),
            'attendance_rules' => (int)($stats['attendance_rules'] ?? 0),
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

        if (empty($data['remark_text'])) {
            $errors[] = 'Remark text is required';
        }

        if (isset($data['min_score']) && isset($data['max_score'])) {
            if ($data['min_score'] > $data['max_score']) {
                $errors[] = 'Minimum score cannot be greater than maximum score';
            }
        }

        if (isset($data['condition_type']) && !in_array($data['condition_type'], ['academic', 'behavior', 'attendance', 'promotion', 'general'])) {
            $errors[] = 'Invalid condition type';
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
