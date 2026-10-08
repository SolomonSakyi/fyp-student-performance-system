<?php

/**
 * SchoolOwnershipValidator.php
 * Validates ownership of schools, campuses, and settings
 * 
 * This service ensures that all operations are performed within
 * the correct tenant/school/campus context.
 * 
 * @package EduTrack
 * @subpackage Services\Security
 * @version 2.1
 * 
 * @filepath app/services/Security/SchoolOwnershipValidator.php
 *
 * v2.1 change (2026-10-05) [ITEM-40]:
 *   Every call to $this->context->isPlatformAdmin() has been
 *   renamed to $this->context->isSuperAdmin(). The TenantContext
 *   class (v2.2) does not define isPlatformAdmin(); it defines
 *   isSuperAdmin(), which returns the same boolean the guards
 *   here are looking for. The old name was never defined on the
 *   class, so every guard that reached this call raised a fatal
 *   "Call to undefined method" error. Six call sites were renamed:
 *   validateSchoolAccess(), validateCampusAccess(),
 *   validateSettingAccess(), canModifySettings(),
 *   canViewSettings(), canDeleteSettings(). No method body other
 *   than the guard line changed. The TenantContext class is not
 *   touched.
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/services/Tenant/TenantContext.php';

class SchoolOwnershipValidator
{
    /**
     * Database instance
     * @var DatabaseHelper
     */
    private $db;

    /**
     * Tenant context
     * @var TenantContext
     */
    private $context;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->context = TenantContext::getInstance();
    }

    // ============================================================
    // SCHOOL VALIDATION
    // ============================================================

    /**
     * Validate that a school belongs to the current tenant
     * 
     * @param int $schoolId School ID
     * @return bool True if valid, false otherwise
     */
    public function validateSchoolAccess(int $schoolId): bool
    {
        // Platform admin can access any school
        if ($this->context->isSuperAdmin()) {
            return true;
        }

        $tenantId = $this->context->getTenantId();
        if (!$tenantId) {
            return false;
        }

        try {
            $sql = "SELECT id FROM schools 
                    WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL";
            $result = $this->db->fetchOne($sql, [$schoolId, $tenantId]);
            return (bool) $result;
        } catch (Exception $e) {
            error_log('SchoolOwnershipValidator::validateSchoolAccess error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get tenant ID for a school
     * 
     * @param int $schoolId School ID
     * @return int|null Tenant ID or null
     */
    public function getSchoolTenantId(int $schoolId): ?int
    {
        try {
            $sql = "SELECT tenant_id FROM schools WHERE id = ? AND deleted_at IS NULL";
            $result = $this->db->fetchOne($sql, [$schoolId]);
            return $result ? (int) $result['tenant_id'] : null;
        } catch (Exception $e) {
            error_log('SchoolOwnershipValidator::getSchoolTenantId error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Validate that a school is active
     * 
     * @param int $schoolId School ID
     * @return bool True if active, false otherwise
     */
    public function validateSchoolActive(int $schoolId): bool
    {
        try {
            $sql = "SELECT id FROM schools 
                    WHERE id = ? AND status = 'active' AND deleted_at IS NULL";
            $result = $this->db->fetchOne($sql, [$schoolId]);
            return (bool) $result;
        } catch (Exception $e) {
            error_log('SchoolOwnershipValidator::validateSchoolActive error: ' . $e->getMessage());
            return false;
        }
    }

    // ============================================================
    // CAMPUS VALIDATION
    // ============================================================

    /**
     * Validate that a campus belongs to the current tenant
     * 
     * @param int $campusId Campus ID
     * @return bool True if valid, false otherwise
     */
    public function validateCampusAccess(int $campusId): bool
    {
        // Platform admin can access any campus
        if ($this->context->isSuperAdmin()) {
            return true;
        }

        $tenantId = $this->context->getTenantId();
        if (!$tenantId) {
            return false;
        }

        try {
            $sql = "SELECT c.id 
                    FROM campuses c
                    JOIN schools s ON c.school_id = s.id
                    WHERE c.id = ? AND s.tenant_id = ? 
                    AND c.deleted_at IS NULL AND s.deleted_at IS NULL";
            $result = $this->db->fetchOne($sql, [$campusId, $tenantId]);
            return (bool) $result;
        } catch (Exception $e) {
            error_log('SchoolOwnershipValidator::validateCampusAccess error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get tenant ID for a campus
     * 
     * @param int $campusId Campus ID
     * @return int|null Tenant ID or null
     */
    public function getCampusTenantId(int $campusId): ?int
    {
        try {
            $sql = "SELECT s.tenant_id 
                    FROM campuses c
                    JOIN schools s ON c.school_id = s.id
                    WHERE c.id = ? AND c.deleted_at IS NULL AND s.deleted_at IS NULL";
            $result = $this->db->fetchOne($sql, [$campusId]);
            return $result ? (int) $result['tenant_id'] : null;
        } catch (Exception $e) {
            error_log('SchoolOwnershipValidator::getCampusTenantId error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Validate that a campus is active
     * 
     * @param int $campusId Campus ID
     * @return bool True if active, false otherwise
     */
    public function validateCampusActive(int $campusId): bool
    {
        try {
            $sql = "SELECT id FROM campuses 
                    WHERE id = ? AND status = 'active' AND deleted_at IS NULL";
            $result = $this->db->fetchOne($sql, [$campusId]);
            return (bool) $result;
        } catch (Exception $e) {
            error_log('SchoolOwnershipValidator::validateCampusActive error: ' . $e->getMessage());
            return false;
        }
    }

    // ============================================================
    // SETTING VALIDATION
    // ============================================================

    /**
     * Validate that a setting belongs to the current tenant
     * 
     * @param int $settingId Setting ID
     * @param string $table Table name (school_settings or campus_settings)
     * @return bool True if valid, false otherwise
     */
    public function validateSettingAccess(int $settingId, string $table): bool
    {
        // Platform admin can access any setting
        if ($this->context->isSuperAdmin()) {
            return true;
        }

        $tenantId = $this->context->getTenantId();
        if (!$tenantId) {
            return false;
        }

        try {
            if ($table === 'school_settings') {
                $sql = "SELECT ss.id 
                        FROM school_settings ss
                        JOIN schools s ON ss.school_id = s.id
                        WHERE ss.id = ? AND s.tenant_id = ? 
                        AND ss.deleted_at IS NULL AND s.deleted_at IS NULL";
            } elseif ($table === 'campus_settings') {
                $sql = "SELECT cs.id 
                        FROM campus_settings cs
                        JOIN campuses c ON cs.campus_id = c.id
                        JOIN schools s ON c.school_id = s.id
                        WHERE cs.id = ? AND s.tenant_id = ? 
                        AND cs.deleted_at IS NULL AND c.deleted_at IS NULL AND s.deleted_at IS NULL";
            } else {
                return false;
            }

            $result = $this->db->fetchOne($sql, [$settingId, $tenantId]);
            return (bool) $result;
        } catch (Exception $e) {
            error_log('SchoolOwnershipValidator::validateSettingAccess error: ' . $e->getMessage());
            return false;
        }
    }

    // ============================================================
    // PERMISSION VALIDATION
    // ============================================================

    /**
     * Check if user can modify settings for a school
     * 
     * @param int $schoolId School ID
     * @return bool True if can modify, false otherwise
     */
    public function canModifySettings(int $schoolId): bool
    {
        // Platform admin can modify any
        if ($this->context->isSuperAdmin()) {
            return true;
        }

        // Tenant admin can modify schools in their tenant
        if ($this->context->hasRole('TENANT_ADMIN')) {
            return $this->validateSchoolAccess($schoolId);
        }

        // School admin can modify their own school
        if ($this->context->hasRole('SCHOOL_ADMIN')) {
            $userSchoolId = $this->context->getSchoolId();
            return $userSchoolId === $schoolId;
        }

        // Check if user has edit permission
        if ($this->context->hasPermission('settings.edit')) {
            return $this->validateSchoolAccess($schoolId);
        }

        return false;
    }

    /**
     * Check if user can view settings for a school
     * 
     * @param int $schoolId School ID
     * @return bool True if can view, false otherwise
     */
    public function canViewSettings(int $schoolId): bool
    {
        // Platform admin can view any
        if ($this->context->isSuperAdmin()) {
            return true;
        }

        // Check if user has view permission
        if ($this->context->hasPermission('settings.view')) {
            return $this->validateSchoolAccess($schoolId);
        }

        return false;
    }

    /**
     * Check if user can delete settings for a school
     * 
     * @param int $schoolId School ID
     * @return bool True if can delete, false otherwise
     */
    public function canDeleteSettings(int $schoolId): bool
    {
        // Platform admin can delete any
        if ($this->context->isSuperAdmin()) {
            return true;
        }

        // Check if user has delete permission
        if ($this->context->hasPermission('settings.delete')) {
            return $this->validateSchoolAccess($schoolId);
        }

        return false;
    }

    // ============================================================
    // TENANT VALIDATION
    // ============================================================

    /**
     * Validate that a tenant exists and is active
     * 
     * @param int $tenantId Tenant ID
     * @return bool True if valid, false otherwise
     */
    public function validateTenantAccess(int $tenantId): bool
    {
        if ($this->context->isSuperAdmin()) {
            return true;
        }

        $currentTenantId = $this->context->getTenantId();
        if ($currentTenantId !== $tenantId) {
            return false;
        }

        try {
            $sql = "SELECT id FROM tenants 
                    WHERE id = ? AND status = 'active' AND deleted_at IS NULL";
            $result = $this->db->fetchOne($sql, [$tenantId]);
            return (bool) $result;
        } catch (Exception $e) {
            error_log('SchoolOwnershipValidator::validateTenantAccess error: ' . $e->getMessage());
            return false;
        }
    }

    // ============================================================
    // COMPREHENSIVE VALIDATION
    // ============================================================

    /**
     * Validate full ownership chain: Tenant -> School -> Campus
     * 
     * @param int $campusId Campus ID
     * @return array Ownership chain or error
     */
    public function validateOwnershipChain(int $campusId): array
    {
        try {
            $sql = "SELECT 
                        c.id as campus_id,
                        c.school_id,
                        s.tenant_id,
                        t.id as tenant_id,
                        t.status as tenant_status
                    FROM campuses c
                    JOIN schools s ON c.school_id = s.id
                    JOIN tenants t ON s.tenant_id = t.id
                    WHERE c.id = ? AND c.deleted_at IS NULL
                    AND s.deleted_at IS NULL AND t.deleted_at IS NULL";

            $result = $this->db->fetchOne($sql, [$campusId]);

            if (!$result) {
                return ['valid' => false, 'error' => 'Campus not found'];
            }

            // Check tenant status
            if ($result['tenant_status'] !== 'active') {
                return ['valid' => false, 'error' => 'Tenant is not active'];
            }

            return [
                'valid' => true,
                'data' => [
                    'campus_id' => $result['campus_id'],
                    'school_id' => $result['school_id'],
                    'tenant_id' => $result['tenant_id']
                ]
            ];
        } catch (Exception $e) {
            error_log('SchoolOwnershipValidator::validateOwnershipChain error: ' . $e->getMessage());
            return ['valid' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get school ID from campus ID
     * 
     * @param int $campusId Campus ID
     * @return int|null School ID or null
     */
    public function getSchoolIdFromCampus(int $campusId): ?int
    {
        try {
            $sql = "SELECT school_id FROM campuses WHERE id = ? AND deleted_at IS NULL";
            $result = $this->db->fetchOne($sql, [$campusId]);
            return $result ? (int) $result['school_id'] : null;
        } catch (Exception $e) {
            error_log('SchoolOwnershipValidator::getSchoolIdFromCampus error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get campuses for a school
     * 
     * @param int $schoolId School ID
     * @return array Campuses
     */
    public function getCampusesForSchool(int $schoolId): array
    {
        try {
            $sql = "SELECT id, campus_name, campus_code, status 
                    FROM campuses 
                    WHERE school_id = ? AND deleted_at IS NULL
                    ORDER BY campus_name ASC";
            return $this->db->fetchAll($sql, [$schoolId]);
        } catch (Exception $e) {
            error_log('SchoolOwnershipValidator::getCampusesForSchool error: ' . $e->getMessage());
            return [];
        }
    }
}
