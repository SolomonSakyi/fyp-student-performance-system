<?php

/**
 * SchoolConfigurationService.php
 * Central service for managing school configuration
 * 
 * @package EduTrack
 * @subpackage Services\School
 * @version 1.2
 *
 * @filepath app/services/School/SchoolConfigurationService.php
 *
 * v1.2 changes (2026-10-05) [ITEM-23]:
 *   - getConfigHealth() no longer queries four tables that do not
 *     exist. The previous version ran four SELECT COUNT(*) queries
 *     against school_levels, school_subjects, assessment_profiles,
 *     and grading_systems. SHOW TABLES LIKE confirmed that none of
 *     those four tables exists in edutrack_db. Each query raised
 *     SQLSTATE[42S02] at parse time, and the method's try/catch (if
 *     it had one) or its caller's wrapper swallowed the exception
 *     silently, producing an empty or partial health report.
 *
 *     The four counts now read live tables:
 *       school_levels      -> academic_levels   (school_id scope)
 *       school_subjects    -> subjects          (school_id scope)
 *       assessment_profiles-> assessment_schemes(tenant_id scope)
 *       grading_systems    -> grading_scales    (tenant_id scope)
 *
 *     academic_levels and subjects carry a nullable school_id, so
 *     the school-scoped filter is preserved. assessment_schemes and
 *     grading_scales do not carry school_id at all; they are scoped
 *     by their NOT NULL tenant_id, read from
 *     $this->context->getTenantId().
 *
 *   - Each of the four counts is now wrapped in try/catch. On any
 *     error, the section's count is set to null and its status to
 *     'unknown'. The overall percentage computation is unchanged;
 *     only sections with status 'complete' count toward it.
 *
 *   - Nothing else changed. Every other method is byte-identical to
 *     v1.1.
 *
 * v1.1 changes (2026-10-05) [ITEM-11b-3] [ITEM-11-3]:
 *   - logConfigurationChange() now supplies the uuid column in its
 *     INSERT into settings_audit_log. The column is VARCHAR(36)
 *     NOT NULL with a unique key and no default; the previous INSERT
 *     omitted it, which caused either a strict-mode failure or an
 *     empty-string substitution that the unique key rejected on the
 *     second insert. The uuid is generated with
 *     bin2hex(random_bytes(16)), the same pattern used in
 *     SettingsService::logAudit() and PlatformAuditLog::log().
 *
 *   - [ITEM-11-3] Convention note: this writer uses the `section`
 *     column with the literal value 'school_config'. It is a
 *     school-configuration writer, distinct from settings-level
 *     writers, which use `setting_group`. The settings_audit_log
 *     table carries both columns by design. A reader must decide
 *     which convention its rows belong to before filtering.
 *
 *   - Nothing else changed.
 */

require_once dirname(__DIR__, 2) . '/helpers/DatabaseHelper.php';
require_once dirname(__DIR__, 2) . '/services/Tenant/TenantContext.php';
require_once dirname(__DIR__, 2) . '/services/Authorization/AuthorizationService.php';

class SchoolConfigurationService
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
     * Get school configuration by school ID
     */
    public function getSchoolConfig(int $schoolId): ?array
    {
        // Verify authorization
        if (!$this->auth->canAccessSchool($schoolId)) {
            throw new Exception('Unauthorized access to school configuration');
        }

        $sql = "SELECT * FROM school_settings WHERE school_id = ? AND deleted_at IS NULL";
        $config = $this->db->fetchOne($sql, [$schoolId]);

        if (!$config) {
            return $this->getDefaultConfig($schoolId);
        }

        // Decode JSON fields if stored as JSON
        if (isset($config['setting_value']) && $this->isJson($config['setting_value'])) {
            $config['setting_value'] = json_decode($config['setting_value'], true);
        }

        return $config;
    }

    /**
     * Get default school configuration
     */
    private function getDefaultConfig(int $schoolId): array
    {
        return [
            'school_id' => $schoolId,
            'school_name' => '',
            'legal_name' => '',
            'short_name' => '',
            'motto' => 'Excellence in Education',
            'school_type' => 'Basic School',
            'curriculum' => 'Ghana Basic Education',
            'timezone' => 'UTC',
            'default_language' => 'en',
            'currency' => 'GHS',
            'academic_year_start_month' => 9,
            'academic_year_end_month' => 6,
            'enable_assessment' => true,
            'enable_attendance' => true,
            'enable_finance' => true,
            'enable_reports' => true,
            'enable_notifications' => true,
            'enable_student_portal' => true,
            'config_status' => 'draft',
            'config_version' => 1
        ];
    }

    /**
     * Update school configuration
     */
    public function updateSchoolConfig(int $schoolId, array $data): array
    {
        // Verify authorization
        if (!$this->auth->canManageSchool($schoolId)) {
            throw new Exception('Unauthorized to modify school configuration');
        }

        // Validate data
        $errors = $this->validateConfig($data);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // Start transaction
        $this->db->beginTransaction();

        try {
            // Check if config exists
            $existing = $this->db->fetchOne(
                "SELECT setting_id FROM school_settings WHERE school_id = ? AND deleted_at IS NULL",
                [$schoolId]
            );

            if ($existing) {
                // Update existing config
                $sql = "UPDATE school_settings SET ";
                $updates = [];
                $params = [];

                foreach ($data as $key => $value) {
                    if ($key !== 'school_id' && $key !== 'setting_id') {
                        $updates[] = "setting_value = ?";
                        $params[] = is_array($value) ? json_encode($value) : $value;
                    }
                }

                $sql .= implode(', ', $updates);
                $sql .= ", updated_at = NOW() WHERE school_id = ? AND deleted_at IS NULL";
                $params[] = $schoolId;

                $this->db->query($sql, $params);
            } else {
                // Insert new config
                $sql = "INSERT INTO school_settings (school_id, setting_key, setting_value, setting_type, is_system, created_at) 
                        VALUES (?, ?, ?, 'string', 0, NOW())";

                // Store as key-value pairs for flexibility
                foreach ($data as $key => $value) {
                    if ($key !== 'school_id') {
                        $val = is_array($value) ? json_encode($value) : $value;
                        $this->db->query($sql, [$schoolId, $key, $val]);
                    }
                }
            }

            // Log the change
            $this->logConfigurationChange($schoolId, 'updated', $data);

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'School configuration updated successfully'
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Validate configuration data
     */
    private function validateConfig(array $data): array
    {
        $errors = [];

        if (isset($data['school_name']) && empty($data['school_name'])) {
            $errors[] = 'School name is required';
        }

        if (isset($data['school_type']) && empty($data['school_type'])) {
            $errors[] = 'School type is required';
        }

        if (isset($data['academic_year_start_month'])) {
            $month = (int)$data['academic_year_start_month'];
            if ($month < 1 || $month > 12) {
                $errors[] = 'Academic year start month must be between 1 and 12';
            }
        }

        if (isset($data['academic_year_end_month'])) {
            $month = (int)$data['academic_year_end_month'];
            if ($month < 1 || $month > 12) {
                $errors[] = 'Academic year end month must be between 1 and 12';
            }
        }

        return $errors;
    }

    /**
     * Log configuration change
     *
     * [v1.1 ITEM-11b-3] The INSERT now supplies the uuid column.
     * The column is VARCHAR(36) NOT NULL with a unique key and no
     * default; the previous INSERT omitted it, which caused either a
     * strict-mode failure or an empty-string substitution that the
     * unique key rejected on the second insert.
     *
     * [v1.1 ITEM-11-3] This is a school-configuration writer. It uses
     * the `section` column with the literal value 'school_config'.
     * Settings-level writers use `setting_group`. Both columns exist
     * on settings_audit_log by design; a reader must decide which
     * convention its rows belong to before filtering.
     */
    private function logConfigurationChange(int $schoolId, string $action, array $data): void
    {
        $userId = $this->context->getUserId();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $sql = "INSERT INTO settings_audit_log 
                (uuid, school_id, user_id, section, setting_key, old_value, new_value, action, ip_address, created_at)
                VALUES (?, ?, ?, 'school_config', 'general', NULL, ?, ?, ?, NOW())";

        $this->db->query($sql, [
            bin2hex(random_bytes(16)),
            $schoolId,
            $userId,
            json_encode($data),
            $action,
            $ip
        ]);
    }

    /**
     * Check if string is JSON
     */
    private function isJson(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Get configuration health status
     *
     * [v1.2 ITEM-23] The four count queries now read live tables:
     *   academic_levels   (school_id scope)
     *   subjects          (school_id scope)
     *   assessment_schemes(tenant_id scope)
     *   grading_scales    (tenant_id scope)
     * Each count is wrapped in try/catch. On error, the section's
     * count is null and status is 'unknown'. The overall percentage
     * counts only sections whose status is 'complete'.
     */
    public function getConfigHealth(int $schoolId): array
    {
        $health = [
            'overall' => 0,
            'sections' => []
        ];

        // Check school profile
        $profile = $this->getSchoolConfig($schoolId);
        $health['sections']['profile'] = [
            'status' => !empty($profile['school_name']) ? 'complete' : 'incomplete',
            'message' => !empty($profile['school_name']) ? 'School profile configured' : 'School name is required'
        ];

        // [v1.2 ITEM-23] The tenant id is read once for the two
        // tables that are tenant-scoped rather than school-scoped.
        $tenantId = $this->context->getTenantId();

        // Check levels (academic_levels, scoped by school_id)
        $levels = null;
        $levelsCount = null;
        try {
            $levels = $this->db->fetchAll(
                "SELECT COUNT(*) as count FROM academic_levels WHERE school_id = ? AND deleted_at IS NULL",
                [$schoolId]
            );
            $levelsCount = (int)($levels[0]['count'] ?? 0);
        } catch (Throwable $e) {
            error_log('SchoolConfigurationService::getConfigHealth levels error: ' . $e->getMessage());
            $levelsCount = null;
        }
        if ($levelsCount === null) {
            $health['sections']['levels'] = [
                'status' => 'unknown',
                'message' => 'Could not determine academic level count'
            ];
        } else {
            $health['sections']['levels'] = [
                'status' => $levelsCount > 0 ? 'complete' : 'incomplete',
                'message' => $levelsCount > 0 ? 'Academic levels configured' : 'No academic levels defined'
            ];
        }

        // Check subjects (subjects, scoped by school_id)
        $subjects = null;
        $subjectsCount = null;
        try {
            $subjects = $this->db->fetchAll(
                "SELECT COUNT(*) as count FROM subjects WHERE school_id = ? AND deleted_at IS NULL",
                [$schoolId]
            );
            $subjectsCount = (int)($subjects[0]['count'] ?? 0);
        } catch (Throwable $e) {
            error_log('SchoolConfigurationService::getConfigHealth subjects error: ' . $e->getMessage());
            $subjectsCount = null;
        }
        if ($subjectsCount === null) {
            $health['sections']['subjects'] = [
                'status' => 'unknown',
                'message' => 'Could not determine subject count'
            ];
        } else {
            $health['sections']['subjects'] = [
                'status' => $subjectsCount > 0 ? 'complete' : 'incomplete',
                'message' => $subjectsCount > 0 ? 'Subjects configured' : 'No subjects defined'
            ];
        }

        // Check assessment schemes (assessment_schemes, scoped by tenant_id)
        $profiles = null;
        $profilesCount = null;
        try {
            $profiles = $this->db->fetchAll(
                "SELECT COUNT(*) as count FROM assessment_schemes WHERE tenant_id = ? AND deleted_at IS NULL",
                [$tenantId]
            );
            $profilesCount = (int)($profiles[0]['count'] ?? 0);
        } catch (Throwable $e) {
            error_log('SchoolConfigurationService::getConfigHealth assessment error: ' . $e->getMessage());
            $profilesCount = null;
        }
        if ($profilesCount === null) {
            $health['sections']['assessment'] = [
                'status' => 'unknown',
                'message' => 'Could not determine assessment scheme count'
            ];
        } else {
            $health['sections']['assessment'] = [
                'status' => $profilesCount > 0 ? 'complete' : 'incomplete',
                'message' => $profilesCount > 0 ? 'Assessment schemes configured' : 'No assessment schemes defined'
            ];
        }

        // Check grading scales (grading_scales, scoped by tenant_id)
        $grading = null;
        $gradingCount = null;
        try {
            $grading = $this->db->fetchAll(
                "SELECT COUNT(*) as count FROM grading_scales WHERE tenant_id = ? AND deleted_at IS NULL",
                [$tenantId]
            );
            $gradingCount = (int)($grading[0]['count'] ?? 0);
        } catch (Throwable $e) {
            error_log('SchoolConfigurationService::getConfigHealth grading error: ' . $e->getMessage());
            $gradingCount = null;
        }
        if ($gradingCount === null) {
            $health['sections']['grading'] = [
                'status' => 'unknown',
                'message' => 'Could not determine grading scale count'
            ];
        } else {
            $health['sections']['grading'] = [
                'status' => $gradingCount > 0 ? 'complete' : 'incomplete',
                'message' => $gradingCount > 0 ? 'Grading scales configured' : 'No grading scales defined'
            ];
        }

        // Calculate overall health
        $complete = 0;
        $total = count($health['sections']);
        foreach ($health['sections'] as $section) {
            if ($section['status'] === 'complete') {
                $complete++;
            }
        }
        $health['overall'] = round(($complete / $total) * 100);

        return $health;
    }
}
