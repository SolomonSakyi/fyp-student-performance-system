<?php

/**
 * CampusSetting.php
 * Campus Setting Model with Multi-Tenant Isolation
 *
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 2.1
 *
 * @filepath app/models/Platform/CampusSetting.php
 *
 * v2.1 change (2026-09-30):
 *   The model now reads and writes campus_settings_kv, not
 *   campus_settings. The 30-column campus_settings table is
 *   live and is read and written by
 *   public/platform/campuses/settings.php, a super-admin page
 *   that self-installs it on first visit. The two shapes cannot
 *   coexist in one table. Option D created the key-value table
 *   under the new name campus_settings_kv and left the 30-column
 *   table untouched. This edit points the model at the new
 *   table. Nothing else in the model changes.
 *
 *   The change is one line: private $table.
 *
 *   No consumer page exists for this model today. The model is
 *   used by SchoolSettingsService, which is used by
 *   SettingsController, which is used by the api/platform
 *   settings case. No tenant-side campus settings page reads it.
 *   That is a fact about the surface, not about this model.
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class CampusSetting
{
    private $db;

    // [OPTION-D] Points at campus_settings_kv, not campus_settings.
    // The 30-column campus_settings table belongs to the
    // super-admin page public/platform/campuses/settings.php
    // and is not read by this model.
    private $table = 'campus_settings_kv';

    // Campus-specific categories
    const CAMPUS_CATEGORIES = [
        'general' => 'General',
        'academic' => 'Academic',
        'operations' => 'Operations',
        'staff' => 'Staff',
        'facilities' => 'Facilities'
    ];

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Get campus settings with tenant isolation
     */
    public function getCampusSettings(int $campusId): array
    {
        $sql = "SELECT cs.* 
                FROM {$this->table} cs
                JOIN campuses c ON cs.campus_id = c.id
                WHERE cs.campus_id = ? 
                AND cs.deleted_at IS NULL 
                AND c.deleted_at IS NULL
                ORDER BY cs.setting_key ASC";
        return $this->db->fetchAll($sql, [$campusId]);
    }

    /**
     * Get settings with inheritance from school
     */
    public function getSettingsWithInheritance(int $campusId, int $schoolId): array
    {
        // Get campus-specific settings
        $campusSettings = $this->getCampusSettings($campusId);

        // Get school default settings
        $schoolSettingModel = new SchoolSetting();
        $schoolSettings = $schoolSettingModel->getSettingsBySchool($schoolId);

        // Merge with campus overrides taking precedence
        $merged = [];

        // Start with school settings
        foreach ($schoolSettings as $setting) {
            $merged[$setting['setting_key']] = [
                'value' => $setting['setting_value'],
                'group' => $setting['setting_group'] ?? 'general',
                'source' => 'school',
                'is_encrypted' => $setting['is_encrypted'] ?? 0
            ];
        }

        // Override with campus settings
        foreach ($campusSettings as $setting) {
            $merged[$setting['setting_key']] = [
                'value' => $setting['setting_value'],
                'group' => $setting['setting_group'] ?? 'general',
                'source' => 'campus',
                'is_encrypted' => $setting['is_encrypted'] ?? 0
            ];
        }

        return $merged;
    }

    /**
     * Get a single campus setting with tenant isolation
     */
    public function getCampusSetting(int $campusId, string $key): ?array
    {
        $sql = "SELECT cs.* 
                FROM {$this->table} cs
                JOIN campuses c ON cs.campus_id = c.id
                WHERE cs.campus_id = ? 
                AND cs.setting_key = ?
                AND cs.deleted_at IS NULL 
                AND c.deleted_at IS NULL
                LIMIT 1";
        return $this->db->fetchOne($sql, [$campusId, $key]);
    }

    /**
     * Get effective setting (with inheritance)
     */
    public function getEffectiveSetting(int $campusId, int $schoolId, string $key): ?array
    {
        // Check campus first
        $campusSetting = $this->getCampusSetting($campusId, $key);
        if ($campusSetting) {
            return [
                'value' => $campusSetting['setting_value'],
                'source' => 'campus',
                'is_encrypted' => $campusSetting['is_encrypted'] ?? 0
            ];
        }

        // Fall back to school
        $schoolSettingModel = new SchoolSetting();
        $schoolSetting = $schoolSettingModel->getSetting($schoolId, $key);
        if ($schoolSetting) {
            return [
                'value' => $schoolSetting['setting_value'],
                'source' => 'school',
                'is_encrypted' => $schoolSetting['is_encrypted'] ?? 0
            ];
        }

        return null;
    }

    /**
     * Set a campus setting with tenant isolation
     */
    public function setCampusSetting(
        int $campusId,
        string $key,
        $value,
        string $group = 'general',
        int $userId = null,
        bool $isEncrypted = false
    ): bool {
        // Verify tenant isolation
        $tenantId = $this->getCampusTenantId($campusId);
        if (!$tenantId) {
            return false;
        }

        $existing = $this->getCampusSetting($campusId, $key);

        if ($existing) {
            $sql = "UPDATE {$this->table} 
                    SET setting_value = ?, 
                        setting_group = ?,
                        is_encrypted = ?,
                        updated_by = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE campus_id = ? 
                    AND setting_key = ?
                    AND deleted_at IS NULL";
            return $this->db->execute($sql, [
                $value,
                $group,
                $isEncrypted ? 1 : 0,
                $userId,
                $campusId,
                $key
            ]);
        } else {
            $sql = "INSERT INTO {$this->table} 
                    (campus_id, school_id, tenant_id, setting_key, setting_value, setting_group, is_encrypted, created_by, created_at) 
                    VALUES (?, 
                        (SELECT school_id FROM campuses WHERE id = ?), 
                        (SELECT tenant_id FROM schools WHERE id = (SELECT school_id FROM campuses WHERE id = ?)), 
                        ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)";
            return $this->db->execute($sql, [
                $campusId,
                $campusId,
                $campusId,
                $key,
                $value,
                $group,
                $isEncrypted ? 1 : 0,
                $userId
            ]);
        }
    }

    /**
     * Get tenant ID for a campus
     */
    private function getCampusTenantId(int $campusId): ?int
    {
        $sql = "SELECT s.tenant_id 
                FROM campuses c
                JOIN schools s ON c.school_id = s.id
                WHERE c.id = ? AND c.deleted_at IS NULL AND s.deleted_at IS NULL";
        $result = $this->db->fetchOne($sql, [$campusId]);
        return $result ? (int) $result['tenant_id'] : null;
    }

    /**
     * Remove campus override (revert to school default)
     */
    public function removeOverride(int $campusId, string $key): bool
    {
        $sql = "UPDATE {$this->table} 
                SET deleted_at = CURRENT_TIMESTAMP
                WHERE campus_id = ? 
                AND setting_key = ?
                AND deleted_at IS NULL";
        return $this->db->execute($sql, [$campusId, $key]);
    }

    /**
     * Get category counts with tenant isolation
     */
    public function getCategoryCounts(int $campusId): array
    {
        $sql = "SELECT 
                    setting_group,
                    COUNT(*) as count
                FROM {$this->table}
                WHERE campus_id = ?
                AND deleted_at IS NULL
                GROUP BY setting_group
                ORDER BY setting_group ASC";
        return $this->db->fetchAll($sql, [$campusId]);
    }
}
