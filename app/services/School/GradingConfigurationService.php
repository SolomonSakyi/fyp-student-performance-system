<?php

/**
 * GradingConfigurationService.php
 * Service for managing grading systems and grade scales
 * 
 * @package EduTrack
 * @subpackage Services\School
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/helpers/DatabaseHelper.php';
require_once dirname(__DIR__, 2) . '/services/Tenant/TenantContext.php';
require_once dirname(__DIR__, 2) . '/services/Authorization/AuthorizationService.php';

class GradingConfigurationService
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
     * Get all grading systems for a school
     */
    public function getGradingSystems(int $schoolId, ?int $levelId = null): array
    {
        if (!$this->auth->canAccessSchool($schoolId)) {
            throw new Exception('Unauthorized access to grading systems');
        }

        $sql = "SELECT 
                    gs.*,
                    COUNT(gs2.grade_scale_id) as scale_count
                FROM grading_systems gs
                LEFT JOIN grade_scales gs2 ON gs.id = gs2.grading_system_id AND gs2.deleted_at IS NULL
                WHERE gs.school_id = ? AND gs.deleted_at IS NULL";

        $params = [$schoolId];

        if ($levelId) {
            $sql .= " AND (gs.level_id = ? OR gs.level_id IS NULL)";
            $params[] = $levelId;
        }

        $sql .= " GROUP BY gs.id ORDER BY gs.is_default DESC, gs.system_name ASC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get a specific grading system with scales
     */
    public function getGradingSystem(int $systemId): ?array
    {
        $sql = "SELECT * FROM grading_systems WHERE id = ? AND deleted_at IS NULL";
        $system = $this->db->fetchOne($sql, [$systemId]);

        if (!$system) {
            return null;
        }

        // Verify authorization
        if (!$this->auth->canAccessSchool($system['school_id'])) {
            throw new Exception('Unauthorized access to grading system');
        }

        // Get grade scales
        $sql = "SELECT * FROM grade_scales 
                WHERE grading_system_id = ? AND deleted_at IS NULL 
                ORDER BY sort_order ASC";

        $system['scales'] = $this->db->fetchAll($sql, [$systemId]);

        return $system;
    }

    /**
     * Create a new grading system
     */
    public function createGradingSystem(int $schoolId, array $data): array
    {
        if (!$this->auth->canManageSchool($schoolId)) {
            throw new Exception('Unauthorized to manage grading systems');
        }

        $errors = $this->validateSystemData($data);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $this->db->beginTransaction();

        try {
            $uuid = $this->generateUUID();
            $tenantId = $this->context->getTenantId();

            $sql = "INSERT INTO grading_systems (
                        uuid, school_id, tenant_id, system_name, system_code,
                        system_type, description, is_default, is_active,
                        created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";

            $this->db->query($sql, [
                $uuid,
                $schoolId,
                $tenantId,
                $data['system_name'],
                $data['system_code'],
                $data['system_type'] ?? 'alphabet',
                $data['description'] ?? '',
                $data['is_default'] ?? 0,
            ]);

            $systemId = $this->db->lastInsertId();

            // Add grade scales if provided
            if (!empty($data['scales'])) {
                foreach ($data['scales'] as $scale) {
                    $this->addGradeScale($systemId, $scale);
                }
            }

            // If this is default, unset other defaults
            if ($data['is_default'] ?? 0) {
                $this->unsetDefaultSystems($schoolId, $systemId);
            }

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Grading system created successfully',
                'system_id' => $systemId
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update a grading system
     */
    public function updateGradingSystem(int $systemId, array $data): array
    {
        $system = $this->getGradingSystem($systemId);
        if (!$system) {
            return ['success' => false, 'errors' => ['Grading system not found']];
        }

        if (!$this->auth->canManageSchool($system['school_id'])) {
            throw new Exception('Unauthorized to manage grading systems');
        }

        $sql = "UPDATE grading_systems SET ";
        $updates = [];
        $params = [];

        $allowedFields = [
            'system_name',
            'system_code',
            'system_type',
            'description',
            'is_default',
            'is_active'
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
        $params[] = $systemId;

        $this->db->query($sql, $params);

        // If this is default, unset other defaults
        if (isset($data['is_default']) && $data['is_default']) {
            $this->unsetDefaultSystems($system['school_id'], $systemId);
        }

        return [
            'success' => true,
            'message' => 'Grading system updated successfully'
        ];
    }

    /**
     * Add a grade scale to a grading system
     */
    public function addGradeScale(int $systemId, array $data): array
    {
        $sql = "INSERT INTO grade_scales (
                    grading_system_id, grade, min_score, max_score,
                    grade_point, remark, color, sort_order, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $this->db->query($sql, [
            $systemId,
            $data['grade'],
            $data['min_score'] ?? 0,
            $data['max_score'] ?? 100,
            $data['grade_point'] ?? 0,
            $data['remark'] ?? '',
            $data['color'] ?? '#28a745',
            $data['sort_order'] ?? 0
        ]);

        return [
            'success' => true,
            'message' => 'Grade scale added successfully',
            'scale_id' => $this->db->lastInsertId()
        ];
    }

    /**
     * Update a grade scale
     */
    public function updateGradeScale(int $scaleId, array $data): array
    {
        $sql = "UPDATE grade_scales SET ";
        $updates = [];
        $params = [];

        $allowedFields = [
            'grade',
            'min_score',
            'max_score',
            'grade_point',
            'remark',
            'color',
            'sort_order'
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
        $sql .= ", updated_at = NOW() WHERE grade_scale_id = ? AND deleted_at IS NULL";
        $params[] = $scaleId;

        $this->db->query($sql, $params);

        return [
            'success' => true,
            'message' => 'Grade scale updated successfully'
        ];
    }

    /**
     * Remove a grade scale
     */
    public function removeGradeScale(int $scaleId): array
    {
        $sql = "UPDATE grade_scales SET deleted_at = NOW() WHERE grade_scale_id = ? AND deleted_at IS NULL";
        $this->db->query($sql, [$scaleId]);

        return [
            'success' => true,
            'message' => 'Grade scale removed successfully'
        ];
    }

    /**
     * Delete a grading system (soft delete)
     */
    public function deleteGradingSystem(int $systemId): array
    {
        $system = $this->getGradingSystem($systemId);
        if (!$system) {
            return ['success' => false, 'errors' => ['Grading system not found']];
        }

        // Check if system is in use
        $inUse = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM assessment_profiles WHERE default_grading_system_id = ? AND deleted_at IS NULL",
            [$systemId]
        );

        if (($inUse['count'] ?? 0) > 0) {
            return [
                'success' => false,
                'errors' => ['Grading system is in use and cannot be deleted']
            ];
        }

        $sql = "UPDATE grading_systems SET deleted_at = NOW(), is_active = 0 WHERE id = ? AND deleted_at IS NULL";
        $this->db->query($sql, [$systemId]);

        return [
            'success' => true,
            'message' => 'Grading system deleted successfully'
        ];
    }

    /**
     * Unset default systems
     */
    private function unsetDefaultSystems(int $schoolId, int $excludeSystemId): void
    {
        $sql = "UPDATE grading_systems 
                SET is_default = 0 
                WHERE school_id = ? AND id != ? AND deleted_at IS NULL";

        $this->db->query($sql, [$schoolId, $excludeSystemId]);
    }

    /**
     * Get grading statistics
     */
    public function getGradingStats(int $schoolId): array
    {
        $stats = $this->db->fetchOne(
            "SELECT 
                COUNT(*) as total_systems,
                SUM(CASE WHEN is_default = 1 THEN 1 ELSE 0 END) as default_systems,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_systems
            FROM grading_systems
            WHERE school_id = ? AND deleted_at IS NULL",
            [$schoolId]
        );

        $scaleStats = $this->db->fetchOne(
            "SELECT COUNT(*) as total_scales
            FROM grade_scales gs
            JOIN grading_systems g ON gs.grading_system_id = g.id
            WHERE g.school_id = ? AND gs.deleted_at IS NULL AND g.deleted_at IS NULL",
            [$schoolId]
        );

        return [
            'total_systems' => (int)($stats['total_systems'] ?? 0),
            'default_systems' => (int)($stats['default_systems'] ?? 0),
            'active_systems' => (int)($stats['active_systems'] ?? 0),
            'total_scales' => (int)($scaleStats['total_scales'] ?? 0)
        ];
    }

    /**
     * Validate system data
     */
    private function validateSystemData(array $data): array
    {
        $errors = [];

        if (empty($data['system_name'])) {
            $errors[] = 'System name is required';
        }

        if (empty($data['system_code'])) {
            $errors[] = 'System code is required';
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
