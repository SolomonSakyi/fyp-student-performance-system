<?php

/**
 * TenantModule.php
 *
 * Tenant Module Model for Platform Management
 * Manages which modules are enabled for each tenant
 *
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 2.0
 */

// Get project root path - works from app/models/Platform/
$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/models/Platform/BaseModel.php';

class TenantModule extends BaseModel
{
    /**
     * @var string Table name
     */
    protected string $table = 'tenant_modules';

    /**
     * @var array Fillable fields
     */
    protected array $fillable = [
        'uuid',
        'tenant_id',
        'module_code',
        'module_name',
        'description',
        'is_enabled',
        'settings',
        'created_by'
    ];

    /**
     * Get all modules for a tenant
     */
    public function getByTenant(int $tenantId): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE tenant_id = ? ORDER BY module_name";
        $modules = $this->rawFetch($sql, [$tenantId]);
        
        foreach ($modules as &$module) {
            $module['settings'] = json_decode($module['settings'] ?? '{}', true);
        }
        
        return $modules;
    }

    /**
     * Get enabled modules for a tenant
     */
    public function getEnabledByTenant(int $tenantId): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE tenant_id = ? AND is_enabled = 1 ORDER BY module_name";
        $modules = $this->rawFetch($sql, [$tenantId]);
        
        foreach ($modules as &$module) {
            $module['settings'] = json_decode($module['settings'] ?? '{}', true);
        }
        
        return $modules;
    }

    /**
     * Get module by code for a tenant
     */
    public function getByCode(int $tenantId, string $code): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE tenant_id = ? AND module_code = ? LIMIT 1";
        $module = $this->rawFetchOne($sql, [$tenantId, $code]);
        
        if ($module) {
            $module['settings'] = json_decode($module['settings'] ?? '{}', true);
        }
        
        return $module;
    }

    /**
     * Check if module is enabled for a tenant
     */
    public function isEnabled(int $tenantId, string $code): bool
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} 
                WHERE tenant_id = ? AND module_code = ? AND is_enabled = 1";
        
        $result = $this->rawFetchOne($sql, [$tenantId, $code]);
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Enable module for a tenant
     */
    public function enable(int $tenantId, string $code): bool
    {
        try {
            $sql = "UPDATE {$this->table} SET is_enabled = 1 
                    WHERE tenant_id = ? AND module_code = ?";
            $this->rawQuery($sql, [$tenantId, $code]);
            return true;
        } catch (Exception $e) {
            $this->logger->error('enable module error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Disable module for a tenant
     */
    public function disable(int $tenantId, string $code): bool
    {
        try {
            $sql = "UPDATE {$this->table} SET is_enabled = 0 
                    WHERE tenant_id = ? AND module_code = ?";
            $this->rawQuery($sql, [$tenantId, $code]);
            return true;
        } catch (Exception $e) {
            $this->logger->error('disable module error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update module settings for a tenant
     */
    public function updateSettings(int $tenantId, string $code, array $settings): bool
    {
        try {
            $settingsJson = json_encode($settings);
            $sql = "UPDATE {$this->table} SET settings = ? 
                    WHERE tenant_id = ? AND module_code = ?";
            $this->rawQuery($sql, [$settingsJson, $tenantId, $code]);
            return true;
        } catch (Exception $e) {
            $this->logger->error('updateSettings error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all available modules (for reference)
     */
    public function getAvailableModules(): array
    {
        return [
            'school_management' => 'School Management',
            'campus_management' => 'Campus Management',
            'student_management' => 'Student Management',
            'staff_management' => 'Staff Management',
            'academics' => 'Academics & Assessments',
            'attendance' => 'Attendance Module',
            'finance' => 'Finance Module',
            'analytics' => 'Analytics & Reporting',
            'ai_assistant' => 'AI Assistant',
            'library' => 'Library Management',
            'hostel' => 'Hostel Management',
            'transport' => 'Transport Management',
            'canteen' => 'Canteen Management',
            'health' => 'Health & Medical',
            'sports' => 'Sports Management',
            'alumni' => 'Alumni Management'
        ];
    }

    /**
     * Batch enable/disable modules for a tenant
     */
    public function batchUpdate(int $tenantId, array $moduleStatuses): bool
    {
        try {
            $this->beginTransaction();
            
            foreach ($moduleStatuses as $code => $enabled) {
                $sql = "UPDATE {$this->table} SET is_enabled = ? 
                        WHERE tenant_id = ? AND module_code = ?";
                $this->rawQuery($sql, [$enabled ? 1 : 0, $tenantId, $code]);
            }
            
            $this->commit();
            return true;
        } catch (Exception $e) {
            $this->rollBack();
            $this->logger->error('batchUpdate error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get module statistics for a tenant
     */
    public function getStats(int $tenantId): array
    {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN is_enabled = 1 THEN 1 ELSE 0 END) as enabled
                FROM {$this->table}
                WHERE tenant_id = ?";
        
        $result = $this->rawFetchOne($sql, [$tenantId]);
        return $result ?? ['total' => 0, 'enabled' => 0];
    }

    /**
     * Initialize default modules for a tenant
     */
    public function initializeDefaults(int $tenantId, array $modules = []): array
    {
        $defaultModules = $modules ?: [
            'school_management' => 'School Management',
            'student_management' => 'Student Management',
            'staff_management' => 'Staff Management',
            'academics' => 'Academics & Assessments'
        ];
        
        $results = [];
        foreach ($defaultModules as $code => $name) {
            // Check if module already exists
            $existing = $this->getByCode($tenantId, $code);
            if (!$existing) {
                $id = $this->create([
                    'tenant_id' => $tenantId,
                    'module_code' => $code,
                    'module_name' => $name,
                    'is_enabled' => 1,
                    'settings' => json_encode([])
                ]);
                $results[$code] = $id ? true : false;
            } else {
                $results[$code] = true;
            }
        }
        
        return $results;
    }

    /**
     * Sync modules for a tenant (add missing, remove extras)
     */
    public function syncModules(int $tenantId, array $moduleCodes): array
    {
        try {
            $this->beginTransaction();
            
            $currentModules = $this->getByTenant($tenantId);
            $currentCodes = array_column($currentModules, 'module_code');
            
            // Modules to add
            $toAdd = array_diff($moduleCodes, $currentCodes);
            $added = [];
            foreach ($toAdd as $code) {
                $available = $this->getAvailableModules();
                if (isset($available[$code])) {
                    $id = $this->create([
                        'tenant_id' => $tenantId,
                        'module_code' => $code,
                        'module_name' => $available[$code],
                        'is_enabled' => 1,
                        'settings' => json_encode([])
                    ]);
                    if ($id) {
                        $added[] = $code;
                    }
                }
            }
            
            // Modules to remove
            $toRemove = array_diff($currentCodes, $moduleCodes);
            $removed = [];
            foreach ($toRemove as $code) {
                $sql = "DELETE FROM {$this->table} WHERE tenant_id = ? AND module_code = ?";
                if ($this->rawQuery($sql, [$tenantId, $code])) {
                    $removed[] = $code;
                }
            }
            
            $this->commit();
            
            return [
                'added' => $added,
                'removed' => $removed,
                'total' => count($moduleCodes)
            ];
        } catch (Exception $e) {
            $this->rollBack();
            $this->logger->error('syncModules error: ' . $e->getMessage());
            return ['added' => [], 'removed' => [], 'total' => 0];
        }
    }

    /**
     * Get disabled modules for a tenant
     */
    public function getDisabledByTenant(int $tenantId): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE tenant_id = ? AND is_enabled = 0 ORDER BY module_name";
        return $this->rawFetch($sql, [$tenantId]);
    }

    /**
     * Count enabled modules for a tenant
     */
    public function countEnabled(int $tenantId): int
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} WHERE tenant_id = ? AND is_enabled = 1";
        $result = $this->rawFetchOne($sql, [$tenantId]);
        return (int)($result['count'] ?? 0);
    }

    /**
     * Get module usage statistics across all tenants
     */
    public function getGlobalStats(): array
    {
        $sql = "SELECT 
                    module_code,
                    module_name,
                    COUNT(*) as total_tenants,
                    SUM(CASE WHEN is_enabled = 1 THEN 1 ELSE 0 END) as enabled_tenants,
                    ROUND(SUM(CASE WHEN is_enabled = 1 THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as adoption_rate
                FROM {$this->table}
                GROUP BY module_code, module_name
                ORDER BY adoption_rate DESC";
        
        return $this->rawFetch($sql);
    }

    /**
     * Get modules by subscription plan
     */
    public function getByPlan(int $planId): array
    {
        $sql = "SELECT 
                    sp.features as plan_features
                FROM subscription_plans sp
                WHERE sp.id = ? AND sp.deleted_at IS NULL";
        
        $result = $this->rawFetchOne($sql, [$planId]);
        if (!$result) {
            return [];
        }
        
        $features = json_decode($result['plan_features'] ?? '{}', true);
        $moduleCodes = array_keys($features);
        
        if (empty($moduleCodes)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($moduleCodes), '?'));
        $sql = "SELECT * FROM {$this->table} WHERE module_code IN ({$placeholders}) ORDER BY module_name";
        
        return $this->rawFetch($sql, $moduleCodes);
    }
}