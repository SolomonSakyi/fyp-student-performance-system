<?php
/**
 * SchoolSetting.php
 * School Setting Model with Multi-Tenant Isolation
 * 
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 2.0
 * 
 * @filepath app/models/Platform/SchoolSetting.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class SchoolSetting
{
    private $db;
    private $table = 'school_settings';
    
    // Category constants
    const CATEGORIES = [
        'general' => 'General',
        'academic' => 'Academic',
        'branding' => 'Branding',
        'regional' => 'Regional',
        'assessment' => 'Assessment',
        'reports' => 'Reports',
        'portal' => 'Portal',
        'communication' => 'Communication',
        'security' => 'Security',
        'finance' => 'Finance',
        'integrations' => 'Integrations',
        'campuses' => 'Campuses'
    ];
    
    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }
    
    /**
     * Get settings by school with tenant isolation
     */
    public function getSettingsBySchool(int $schoolId): array
    {
        $sql = "SELECT ss.* 
                FROM {$this->table} ss
                JOIN schools s ON ss.school_id = s.id
                WHERE ss.school_id = ? 
                AND ss.deleted_at IS NULL 
                AND s.deleted_at IS NULL
                ORDER BY ss.setting_key ASC";
        return $this->db->fetchAll($sql, [$schoolId]);
    }
    
    /**
     * Get settings by school and group with tenant isolation
     */
    public function getSettingsBySchoolAndGroup(int $schoolId, string $group): array
    {
        $sql = "SELECT ss.* 
                FROM {$this->table} ss
                JOIN schools s ON ss.school_id = s.id
                WHERE ss.school_id = ? 
                AND ss.setting_group = ?
                AND ss.deleted_at IS NULL 
                AND s.deleted_at IS NULL
                ORDER BY ss.setting_key ASC";
        return $this->db->fetchAll($sql, [$schoolId, $group]);
    }
    
    /**
     * Get a single setting with tenant isolation
     */
    public function getSetting(int $schoolId, string $key): ?array
    {
        $sql = "SELECT ss.* 
                FROM {$this->table} ss
                JOIN schools s ON ss.school_id = s.id
                WHERE ss.school_id = ? 
                AND ss.setting_key = ?
                AND ss.deleted_at IS NULL 
                AND s.deleted_at IS NULL
                LIMIT 1";
        return $this->db->fetchOne($sql, [$schoolId, $key]);
    }
    
    /**
     * Set a setting with tenant isolation
     */
    public function setSetting(
        int $schoolId, 
        string $key, 
        $value, 
        string $group = 'general', 
        int $userId = null, 
        bool $isEncrypted = false
    ): bool {
        // Verify tenant isolation
        $tenantId = $this->getSchoolTenantId($schoolId);
        if (!$tenantId) {
            return false;
        }
        
        // Check if setting exists
        $existing = $this->getSetting($schoolId, $key);
        
        if ($existing) {
            // Update
            $sql = "UPDATE {$this->table} 
                    SET setting_value = ?, 
                        setting_group = ?,
                        is_encrypted = ?,
                        updated_by = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE school_id = ? 
                    AND setting_key = ?
                    AND deleted_at IS NULL";
            return $this->db->execute($sql, [
                $value, 
                $group, 
                $isEncrypted ? 1 : 0, 
                $userId, 
                $schoolId, 
                $key
            ]);
        } else {
            // Insert with tenant_id from school
            $sql = "INSERT INTO {$this->table} 
                    (school_id, tenant_id, setting_key, setting_value, setting_group, is_encrypted, created_by, created_at) 
                    VALUES (?, (SELECT tenant_id FROM schools WHERE id = ?), ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)";
            return $this->db->execute($sql, [
                $schoolId, 
                $schoolId, 
                $key, 
                $value, 
                $group, 
                $isEncrypted ? 1 : 0, 
                $userId
            ]);
        }
    }
    
    /**
     * Get tenant ID for a school
     */
    private function getSchoolTenantId(int $schoolId): ?int
    {
        $sql = "SELECT tenant_id FROM schools WHERE id = ? AND deleted_at IS NULL";
        $result = $this->db->fetchOne($sql, [$schoolId]);
        return $result ? (int) $result['tenant_id'] : null;
    }
    
    /**
     * Delete a setting (soft delete with tenant isolation)
     */
    public function deleteSetting(int $schoolId, string $key): bool
    {
        $sql = "UPDATE {$this->table} 
                SET deleted_at = CURRENT_TIMESTAMP
                WHERE school_id = ? 
                AND setting_key = ?
                AND deleted_at IS NULL";
        return $this->db->execute($sql, [$schoolId, $key]);
    }
    
    /**
     * Get category counts with tenant isolation
     */
    public function getCategoryCounts(int $schoolId): array
    {
        $sql = "SELECT 
                    setting_group,
                    COUNT(*) as count
                FROM {$this->table}
                WHERE school_id = ?
                AND deleted_at IS NULL
                GROUP BY setting_group
                ORDER BY setting_group ASC";
        return $this->db->fetchAll($sql, [$schoolId]);
    }
    
    /**
     * Search settings with tenant isolation
     */
    public function searchSettings(int $schoolId, string $query): array
    {
        $search = '%' . $query . '%';
        $sql = "SELECT ss.* 
                FROM {$this->table} ss
                JOIN schools s ON ss.school_id = s.id
                WHERE ss.school_id = ? 
                AND ss.deleted_at IS NULL 
                AND s.deleted_at IS NULL
                AND (ss.setting_key LIKE ? OR ss.setting_value LIKE ?)
                ORDER BY ss.setting_key ASC";
        return $this->db->fetchAll($sql, [$schoolId, $search, $search]);
    }
    
    /**
     * Get default settings (for initialization)
     */
    public function getDefaultSettings(): array
    {
        return [
            // General
            'school_name' => ['value' => '', 'type' => 'string', 'group' => 'general'],
            'school_code' => ['value' => '', 'type' => 'string', 'group' => 'general'],
            'school_motto' => ['value' => '', 'type' => 'string', 'group' => 'general'],
            'school_address' => ['value' => '', 'type' => 'text', 'group' => 'general'],
            'school_phone' => ['value' => '', 'type' => 'string', 'group' => 'general'],
            'school_email' => ['value' => '', 'type' => 'email', 'group' => 'general'],
            
            // Academic
            'academic_year_start' => ['value' => '2024-01-01', 'type' => 'date', 'group' => 'academic'],
            'academic_year_end' => ['value' => '2024-12-31', 'type' => 'date', 'group' => 'academic'],
            'term_duration' => ['value' => '12', 'type' => 'number', 'group' => 'academic'],
            'working_days' => ['value' => 'mon,tue,wed,thu,fri', 'type' => 'string', 'group' => 'academic'],
            
            // Branding
            'brand_primary_color' => ['value' => '#4facfe', 'type' => 'string', 'group' => 'branding'],
            'brand_secondary_color' => ['value' => '#00f2fe', 'type' => 'string', 'group' => 'branding'],
            'brand_logo_url' => ['value' => '', 'type' => 'string', 'group' => 'branding'],
            
            // Regional
            'regional_country' => ['value' => 'Ghana', 'type' => 'string', 'group' => 'regional'],
            'regional_currency' => ['value' => 'GHS', 'type' => 'string', 'group' => 'regional'],
            'regional_timezone' => ['value' => 'Africa/Accra', 'type' => 'string', 'group' => 'regional'],
            'regional_language' => ['value' => 'en', 'type' => 'string', 'group' => 'regional'],
            
            // Assessment
            'grading_system' => ['value' => 'percentage', 'type' => 'string', 'group' => 'assessment'],
            'pass_mark' => ['value' => '50', 'type' => 'number', 'group' => 'assessment'],
            
            // Reports
            'report_position_display' => ['value' => 'numeric', 'type' => 'string', 'group' => 'reports'],
            'report_grade_display' => ['value' => 'letter', 'type' => 'string', 'group' => 'reports'],
            
            // Portal
            'portal_enabled' => ['value' => '1', 'type' => 'boolean', 'group' => 'portal'],
            'portal_results_visibility' => ['value' => 'logged_in', 'type' => 'string', 'group' => 'portal'],
            
            // Communication
            'sms_enabled' => ['value' => '1', 'type' => 'boolean', 'group' => 'communication'],
            'email_enabled' => ['value' => '1', 'type' => 'boolean', 'group' => 'communication'],
            
            // Security
            'security_two_factor_auth' => ['value' => '0', 'type' => 'boolean', 'group' => 'security'],
            'security_audit_logging' => ['value' => '1', 'type' => 'boolean', 'group' => 'security'],
            
            // Finance
            'finance_default_currency' => ['value' => 'GHS', 'type' => 'string', 'group' => 'finance'],
            'finance_tax_config' => ['value' => '{"rate":0,"type":"none"}', 'type' => 'json', 'group' => 'finance'],
        ];
    }
    
    /**
     * Initialize default settings for a school
     */
    public function initializeDefaults(int $schoolId, int $userId): int
    {
        $defaults = $this->getDefaultSettings();
        $count = 0;
        
        foreach ($defaults as $key => $config) {
            $existing = $this->getSetting($schoolId, $key);
            if (!$existing) {
                $this->setSetting(
                    $schoolId, 
                    $key, 
                    $config['value'], 
                    $config['group'], 
                    $userId,
                    false
                );
                $count++;
            }
        }
        
        return $count;
    }
}