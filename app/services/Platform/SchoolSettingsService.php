<?php

/**
 * SchoolSettingsService.php
 * School & Campus Settings Service with Multi-Tenant Isolation
 * 
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 2.2
 * 
 * @filepath app/services/Platform/SchoolSettingsService.php
 *
 * v2.2 change (2026-10-05) [ITEM-24]:
 *   Added bulkUpdateCampusSettings(). The api/platform router's
 *   settings/save_campus action calls
 *   $service->bulkUpdateCampusSettings($campusId, $settings, $userId),
 *   which did not exist. The call raised a fatal
 *   Error: Call to undefined method
 *   SchoolSettingsService::bulkUpdateCampusSettings() on every
 *   save_campus request. The new method mirrors the existing
 *   bulkUpdateSchoolSettings() shape: it loops over the settings
 *   array, calls setCampusSetting() for each key, and returns
 *   ['success' => bool, 'updated' => array, 'failed' => array].
 *
 * v2.1 change (2026-10-05) [ITEM-5]:
 *   The per-row decrypt loops in getSchoolSettings(),
 *   getSchoolSettingsByCategory(), getCampusSettings() and
 *   searchSchoolSettings() now wrap each decrypt call in a per-row
 *   try/catch. A failing value is skipped, the school/campus id and
 *   the key are logged, and the loop continues. Prior behaviour: any
 *   single decryption failure propagated to the method-level catch
 *   and blanked the entire returned list.
 *
 *   getSchoolSetting()'s single-row decrypt is wrapped the same way
 *   and returns the method's existing "not found" shape on failure
 *   instead of letting the exception reach the method-level catch.
 *
 *   Nothing else changed.
 */

$projectRoot = dirname(__DIR__, 3) . '/';

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/models/Platform/SchoolSetting.php';
require_once $projectRoot . 'app/models/Platform/CampusSetting.php';
require_once $projectRoot . 'app/models/Platform/School.php';
require_once $projectRoot . 'app/models/Platform/Campus.php';
require_once $projectRoot . 'app/models/Platform/PlatformAuditLog.php';
require_once $projectRoot . 'app/services/Tenant/TenantContext.php';
require_once $projectRoot . 'app/services/Security/SchoolOwnershipValidator.php';
require_once $projectRoot . 'app/services/Security/EncryptionService.php';

class SchoolSettingsService
{
    private $db;
    private $logger;
    private $schoolSettingModel;
    private $campusSettingModel;
    private $schoolModel;
    private $campusModel;
    private $auditModel;
    private $ownershipValidator;
    private $encryptionService;
    private $context;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->schoolSettingModel = new SchoolSetting();
        $this->campusSettingModel = new CampusSetting();
        $this->schoolModel = new School();
        $this->campusModel = new Campus();
        $this->auditModel = new PlatformAuditLog();
        $this->ownershipValidator = new SchoolOwnershipValidator();
        $this->encryptionService = EncryptionService::getInstance();
        $this->context = TenantContext::getInstance();
    }

    // ============================================================
    // SCHOOL SETTINGS
    // ============================================================

    /**
     * Get all school settings with tenant validation
     *
     * [v2.1 ITEM-5] Per-row try/catch around each decrypt. A failing
     * value is skipped, logged, and the loop continues.
     */
    public function getSchoolSettings(int $schoolId): array
    {
        if (!$this->ownershipValidator->validateSchoolAccess($schoolId)) {
            return [
                'success' => false,
                'message' => 'Access denied: School not found or does not belong to your tenant',
                'code' => 'SCHOOL_ACCESS_DENIED'
            ];
        }

        try {
            $settings = $this->schoolSettingModel->getSettingsBySchool($schoolId);
            $categories = $this->schoolSettingModel->getCategoryCounts($schoolId);

            foreach ($settings as &$setting) {
                if ($setting['is_encrypted'] == 1) {
                    try {
                        $setting['setting_value'] = $this->encryptionService->decrypt($setting['setting_value']);
                    } catch (Exception $e) {
                        $this->logger->error(
                            'SchoolSettings decrypt failed for school ' . $schoolId
                                . ', key "' . ($setting['setting_key'] ?? '?') . '": ' . $e->getMessage()
                        );
                        unset($setting);
                    }
                }
            }
            unset($setting);

            return [
                'success' => true,
                'data' => [
                    'settings' => $settings,
                    'categories' => $categories,
                    'tenant_id' => $this->context->getTenantId(),
                    'school_id' => $schoolId
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('getSchoolSettings error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get school settings by category with tenant validation
     *
     * [v2.1 ITEM-5] Per-row try/catch around each decrypt.
     */
    public function getSchoolSettingsByCategory(int $schoolId, string $category): array
    {
        if (!$this->ownershipValidator->validateSchoolAccess($schoolId)) {
            return [
                'success' => false,
                'message' => 'Access denied: School not found or does not belong to your tenant',
                'code' => 'SCHOOL_ACCESS_DENIED'
            ];
        }

        try {
            $settings = $this->schoolSettingModel->getSettingsBySchoolAndGroup($schoolId, $category);

            foreach ($settings as &$setting) {
                if ($setting['is_encrypted'] == 1) {
                    try {
                        $setting['setting_value'] = $this->encryptionService->decrypt($setting['setting_value']);
                    } catch (Exception $e) {
                        $this->logger->error(
                            'SchoolSettings decrypt failed for school ' . $schoolId
                                . ', category "' . $category . '", key "' . ($setting['setting_key'] ?? '?') . '": ' . $e->getMessage()
                        );
                        unset($setting);
                    }
                }
            }
            unset($setting);

            return [
                'success' => true,
                'data' => $settings,
                'tenant_id' => $this->context->getTenantId()
            ];
        } catch (Exception $e) {
            $this->logger->error('getSchoolSettingsByCategory error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get a single school setting with tenant validation
     *
     * [v2.1 ITEM-5] The single-row decrypt is wrapped in try/catch;
     * a failure returns the method's existing "not found" shape.
     */
    public function getSchoolSetting(int $schoolId, string $key): array
    {
        if (!$this->ownershipValidator->validateSchoolAccess($schoolId)) {
            return [
                'success' => false,
                'message' => 'Access denied: School not found or does not belong to your tenant',
                'code' => 'SCHOOL_ACCESS_DENIED'
            ];
        }

        try {
            $setting = $this->schoolSettingModel->getSetting($schoolId, $key);

            if (!$setting) {
                $defaults = $this->schoolSettingModel->getDefaultSettings();
                if (isset($defaults[$key])) {
                    return [
                        'success' => true,
                        'data' => [
                            'key' => $key,
                            'value' => $defaults[$key]['value'],
                            'type' => 'string',
                            'group' => $defaults[$key]['group'] ?? 'general',
                            'is_default' => true,
                            'tenant_id' => $this->context->getTenantId()
                        ]
                    ];
                }
                return ['success' => false, 'message' => 'Setting not found'];
            }

            $value = $setting['setting_value'];
            if ($setting['is_encrypted'] == 1) {
                try {
                    $value = $this->encryptionService->decrypt($value);
                } catch (Exception $e) {
                    $this->logger->error(
                        'SchoolSetting decrypt failed for school ' . $schoolId
                            . ', key "' . $key . '": ' . $e->getMessage()
                    );
                    return ['success' => false, 'message' => 'Setting not found (decryption failed).'];
                }
            }

            return [
                'success' => true,
                'data' => [
                    'key' => $key,
                    'value' => $value,
                    'type' => $setting['setting_type'],
                    'group' => 'general',
                    'is_encrypted' => $setting['is_encrypted'] == 1,
                    'is_system' => $setting['is_system'] == 1,
                    'tenant_id' => $this->context->getTenantId()
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('getSchoolSetting error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update a school setting with tenant validation and encryption
     */
    public function updateSchoolSetting(int $schoolId, string $key, $value, int $userId): array
    {
        if (!$this->ownershipValidator->canModifySettings($schoolId)) {
            return [
                'success' => false,
                'message' => 'Access denied: You do not have permission to modify these settings',
                'code' => 'SETTINGS_UPDATE_DENIED'
            ];
        }

        if (!$this->ownershipValidator->validateSchoolAccess($schoolId)) {
            return [
                'success' => false,
                'message' => 'School not found or access denied',
                'code' => 'SCHOOL_NOT_FOUND'
            ];
        }

        try {
            $oldSetting = $this->schoolSettingModel->getSetting($schoolId, $key);
            $oldValue = $oldSetting ? $oldSetting['setting_value'] : null;

            $group = $this->getGroupForSetting($key);
            $isEncrypted = $this->isEncryptedSetting($key);
            $storedValue = $isEncrypted ? $this->encryptionService->encrypt($value) : $value;

            $result = $this->schoolSettingModel->setSetting(
                $schoolId,
                $key,
                $storedValue,
                $group,
                $userId,
                $isEncrypted
            );

            if (!$result) {
                return ['success' => false, 'message' => 'Failed to update setting'];
            }

            try {
                $this->auditModel->log([
                    'user_id' => $userId,
                    'tenant_id' => $this->context->getTenantId(),
                    'action_type' => 'SCHOOL_SETTING_UPDATE',
                    'module' => 'SchoolSettings',
                    'resource' => 'school_setting',
                    'resource_id' => (string)$schoolId,
                    'old_data' => ['key' => $key, 'value' => $oldValue],
                    'new_data' => ['key' => $key, 'value' => $value, 'encrypted' => $isEncrypted],
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            } catch (Exception $e) {
                error_log('Audit log error: ' . $e->getMessage());
            }

            return [
                'success' => true,
                'message' => 'Setting updated successfully',
                'data' => ['encrypted' => $isEncrypted]
            ];
        } catch (Exception $e) {
            $this->logger->error('updateSchoolSetting error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Bulk update school settings with tenant validation
     */
    public function bulkUpdateSchoolSettings(int $schoolId, array $settings, int $userId): array
    {
        if (!$this->ownershipValidator->canModifySettings($schoolId)) {
            return [
                'success' => false,
                'message' => 'Access denied: You do not have permission to modify these settings',
                'code' => 'SETTINGS_UPDATE_DENIED'
            ];
        }

        try {
            $this->db->beginTransaction();

            $updated = [];
            $failed = [];

            foreach ($settings as $key => $value) {
                $group = $this->getGroupForSetting($key);
                $oldSetting = $this->schoolSettingModel->getSetting($schoolId, $key);
                $oldValue = $oldSetting ? $oldSetting['setting_value'] : null;

                $isEncrypted = $this->isEncryptedSetting($key);
                $storedValue = $isEncrypted ? $this->encryptionService->encrypt($value) : $value;

                if ($this->schoolSettingModel->setSetting($schoolId, $key, $storedValue, $group, $userId, $isEncrypted)) {
                    $updated[] = $key;
                } else {
                    $failed[] = $key;
                }
            }

            $this->db->commit();

            return [
                'success' => empty($failed),
                'message' => empty($failed) ? 'All settings updated successfully' : 'Some settings failed to update',
                'data' => [
                    'updated' => $updated,
                    'failed' => $failed
                ]
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logger->error('bulkUpdateSchoolSettings error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Reset school setting to default with tenant validation
     */
    public function resetSchoolSetting(int $schoolId, string $key, int $userId): array
    {
        if (!$this->ownershipValidator->canModifySettings($schoolId)) {
            return [
                'success' => false,
                'message' => 'Access denied: You do not have permission to modify these settings',
                'code' => 'SETTINGS_UPDATE_DENIED'
            ];
        }

        try {
            $defaults = $this->schoolSettingModel->getDefaultSettings();

            if (!isset($defaults[$key])) {
                return ['success' => false, 'message' => 'No default value for this setting'];
            }

            $defaultValue = $defaults[$key]['value'];
            $group = $defaults[$key]['group'] ?? 'general';

            $oldSetting = $this->schoolSettingModel->getSetting($schoolId, $key);
            $oldValue = $oldSetting ? $oldSetting['setting_value'] : null;

            $result = $this->schoolSettingModel->setSetting($schoolId, $key, $defaultValue, $group, $userId, false);

            if (!$result) {
                return ['success' => false, 'message' => 'Failed to reset setting'];
            }

            return [
                'success' => true,
                'message' => 'Setting reset to default'
            ];
        } catch (Exception $e) {
            $this->logger->error('resetSchoolSetting error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get school setting categories
     */
    public function getSchoolSettingCategories(int $schoolId): array
    {
        if (!$this->ownershipValidator->validateSchoolAccess($schoolId)) {
            return [
                'success' => false,
                'message' => 'Access denied: School not found or does not belong to your tenant',
                'code' => 'SCHOOL_ACCESS_DENIED'
            ];
        }

        try {
            $categories = $this->schoolSettingModel->getCategoryCounts($schoolId);

            return [
                'success' => true,
                'data' => $categories,
                'tenant_id' => $this->context->getTenantId()
            ];
        } catch (Exception $e) {
            $this->logger->error('getSchoolSettingCategories error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Initialize default school settings
     */
    public function initializeSchoolSettings(int $schoolId, int $userId): array
    {
        if (!$this->ownershipValidator->canModifySettings($schoolId)) {
            return [
                'success' => false,
                'message' => 'Access denied: You do not have permission to initialize settings',
                'code' => 'SETTINGS_INIT_DENIED'
            ];
        }

        try {
            $count = $this->schoolSettingModel->initializeDefaults($schoolId, $userId);

            return [
                'success' => true,
                'message' => "Initialized {$count} school settings",
                'data' => ['count' => $count]
            ];
        } catch (Exception $e) {
            $this->logger->error('initializeSchoolSettings error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ============================================================
    // CAMPUS SETTINGS (with inheritance)
    // ============================================================

    /**
     * Get campus settings with inheritance and tenant validation
     *
     * [v2.1 ITEM-5] Per-row try/catch around each decrypt of the
     * effective_value field.
     */
    public function getCampusSettings(int $campusId): array
    {
        if (!$this->ownershipValidator->validateCampusAccess($campusId)) {
            return [
                'success' => false,
                'message' => 'Access denied: Campus not found or does not belong to your tenant',
                'code' => 'CAMPUS_ACCESS_DENIED'
            ];
        }

        try {
            $campus = $this->campusModel->find($campusId);
            if (!$campus) {
                return ['success' => false, 'message' => 'Campus not found'];
            }

            $settings = $this->campusSettingModel->getSettingsWithInheritance($campusId, $campus['school_id']);
            $categories = $this->campusSettingModel->getCategoryCounts($campusId);

            foreach ($settings as &$setting) {
                if ($setting['is_encrypted'] == 1) {
                    try {
                        $setting['effective_value'] = $this->encryptionService->decrypt($setting['effective_value']);
                    } catch (Exception $e) {
                        $this->logger->error(
                            'CampusSettings decrypt failed for campus ' . $campusId
                                . ', key "' . ($setting['setting_key'] ?? '?') . '": ' . $e->getMessage()
                        );
                        unset($setting);
                    }
                }
            }
            unset($setting);

            return [
                'success' => true,
                'data' => [
                    'settings' => $settings,
                    'categories' => $categories,
                    'campus' => $campus,
                    'school_id' => $campus['school_id'],
                    'tenant_id' => $this->context->getTenantId()
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('getCampusSettings error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get effective campus setting (with inheritance) and tenant validation
     */
    public function getEffectiveCampusSetting(int $campusId, string $key): array
    {
        if (!$this->ownershipValidator->validateCampusAccess($campusId)) {
            return [
                'success' => false,
                'message' => 'Access denied: Campus not found or does not belong to your tenant',
                'code' => 'CAMPUS_ACCESS_DENIED'
            ];
        }

        try {
            $campus = $this->campusModel->find($campusId);
            if (!$campus) {
                return ['success' => false, 'message' => 'Campus not found'];
            }

            $effective = $this->campusSettingModel->getEffectiveSetting($campusId, $campus['school_id'], $key);

            if (!$effective) {
                return ['success' => false, 'message' => 'Setting not found'];
            }

            if ($effective['is_encrypted'] == 1) {
                $effective['value'] = $this->encryptionService->decrypt($effective['value']);
            }

            return [
                'success' => true,
                'data' => $effective,
                'tenant_id' => $this->context->getTenantId()
            ];
        } catch (Exception $e) {
            $this->logger->error('getEffectiveCampusSetting error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Set campus setting (override) with tenant validation
     */
    public function setCampusSetting(int $campusId, string $key, $value, int $userId): array
    {
        if (!$this->ownershipValidator->validateCampusAccess($campusId)) {
            return [
                'success' => false,
                'message' => 'Access denied: Campus not found or does not belong to your tenant',
                'code' => 'CAMPUS_ACCESS_DENIED'
            ];
        }

        $campus = $this->campusModel->find($campusId);
        if (!$campus) {
            return ['success' => false, 'message' => 'Campus not found'];
        }

        if (!$this->ownershipValidator->canModifySettings($campus['school_id'])) {
            return [
                'success' => false,
                'message' => 'Permission denied: You cannot modify settings for this campus',
                'code' => 'CAMPUS_SETTINGS_DENIED'
            ];
        }

        try {
            $oldSetting = $this->campusSettingModel->getCampusSetting($campusId, $key);
            $oldValue = $oldSetting ? $oldSetting['setting_value'] : null;

            $group = $this->getGroupForSetting($key);
            $isEncrypted = $this->isEncryptedSetting($key);
            $storedValue = $isEncrypted ? $this->encryptionService->encrypt($value) : $value;

            $result = $this->campusSettingModel->setCampusSetting($campusId, $key, $storedValue, $group, $userId, $isEncrypted);

            if (!$result) {
                return ['success' => false, 'message' => 'Failed to update campus setting'];
            }

            return [
                'success' => true,
                'message' => 'Campus setting updated successfully',
                'data' => ['encrypted' => $isEncrypted]
            ];
        } catch (Exception $e) {
            $this->logger->error('setCampusSetting error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Bulk update campus settings with tenant validation
     *
     * [v2.2 ITEM-24] This method is the missing endpoint that the
     * api/platform router's settings/save_campus action called.
     * Shape matches bulkUpdateSchoolSettings(): iterate the settings
     * array, call setCampusSetting() for each key, return the updated
     * and failed lists.
     *
     * @param int   $campusId
     * @param array $settings   key => value
     * @param int   $userId
     * @return array
     */
    public function bulkUpdateCampusSettings(int $campusId, array $settings, int $userId): array
    {
        if (!$this->ownershipValidator->validateCampusAccess($campusId)) {
            return [
                'success' => false,
                'message' => 'Access denied: Campus not found or does not belong to your tenant',
                'code' => 'CAMPUS_ACCESS_DENIED'
            ];
        }

        $campus = $this->campusModel->find($campusId);
        if (!$campus) {
            return ['success' => false, 'message' => 'Campus not found'];
        }

        if (!$this->ownershipValidator->canModifySettings($campus['school_id'])) {
            return [
                'success' => false,
                'message' => 'Permission denied: You cannot modify settings for this campus',
                'code' => 'CAMPUS_SETTINGS_DENIED'
            ];
        }

        if (empty($settings)) {
            return ['success' => false, 'message' => 'No settings provided'];
        }

        try {
            $this->db->beginTransaction();

            $updated = [];
            $failed = [];

            foreach ($settings as $key => $value) {
                $key = trim((string)$key);
                if ($key === '') {
                    continue;
                }

                $group = $this->getGroupForSetting($key);
                $isEncrypted = $this->isEncryptedSetting($key);
                $storedValue = $isEncrypted ? $this->encryptionService->encrypt($value) : $value;

                $ok = $this->campusSettingModel->setCampusSetting(
                    $campusId,
                    $key,
                    $storedValue,
                    $group,
                    $userId,
                    $isEncrypted
                );

                if ($ok) {
                    $updated[] = $key;
                } else {
                    $failed[] = $key;
                }
            }

            $this->db->commit();

            return [
                'success' => empty($failed),
                'message' => empty($failed)
                    ? 'All campus settings updated successfully'
                    : 'Some campus settings failed to update',
                'data' => [
                    'updated' => $updated,
                    'failed'  => $failed
                ]
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logger->error('bulkUpdateCampusSettings error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Remove campus override (revert to school default) with tenant validation
     */
    public function removeCampusOverride(int $campusId, string $key, int $userId): array
    {
        if (!$this->ownershipValidator->validateCampusAccess($campusId)) {
            return [
                'success' => false,
                'message' => 'Access denied: Campus not found or does not belong to your tenant',
                'code' => 'CAMPUS_ACCESS_DENIED'
            ];
        }

        $campus = $this->campusModel->find($campusId);
        if (!$campus) {
            return ['success' => false, 'message' => 'Campus not found'];
        }

        if (!$this->ownershipValidator->canModifySettings($campus['school_id'])) {
            return [
                'success' => false,
                'message' => 'Permission denied: You cannot modify settings for this campus',
                'code' => 'CAMPUS_SETTINGS_DENIED'
            ];
        }

        try {
            $oldSetting = $this->campusSettingModel->getCampusSetting($campusId, $key);
            $oldValue = $oldSetting ? $oldSetting['setting_value'] : null;

            $result = $this->campusSettingModel->removeOverride($campusId, $key);

            if (!$result) {
                return ['success' => false, 'message' => 'Failed to remove override'];
            }

            return [
                'success' => true,
                'message' => 'Campus override removed, using school default'
            ];
        } catch (Exception $e) {
            $this->logger->error('removeCampusOverride error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get campus setting categories with tenant validation
     */
    public function getCampusSettingCategories(int $campusId): array
    {
        if (!$this->ownershipValidator->validateCampusAccess($campusId)) {
            return [
                'success' => false,
                'message' => 'Access denied: Campus not found or does not belong to your tenant',
                'code' => 'CAMPUS_ACCESS_DENIED'
            ];
        }

        try {
            $categories = $this->campusSettingModel->getCategoryCounts($campusId);

            return [
                'success' => true,
                'data' => $categories,
                'tenant_id' => $this->context->getTenantId()
            ];
        } catch (Exception $e) {
            $this->logger->error('getCampusSettingCategories error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ============================================================
    // SEARCH
    // ============================================================

    /**
     * Search school settings with tenant validation
     *
     * [v2.1 ITEM-5] Per-row try/catch around each decrypt.
     */
    public function searchSchoolSettings(int $schoolId, string $query): array
    {
        if (!$this->ownershipValidator->validateSchoolAccess($schoolId)) {
            return [
                'success' => false,
                'message' => 'Access denied: School not found or does not belong to your tenant',
                'code' => 'SCHOOL_ACCESS_DENIED'
            ];
        }

        try {
            $results = $this->schoolSettingModel->searchSettings($schoolId, $query);

            foreach ($results as &$result) {
                if ($result['is_encrypted'] == 1) {
                    try {
                        $result['setting_value'] = $this->encryptionService->decrypt($result['setting_value']);
                    } catch (Exception $e) {
                        $this->logger->error(
                            'SchoolSettings search decrypt failed for school ' . $schoolId
                                . ', key "' . ($result['setting_key'] ?? '?') . '": ' . $e->getMessage()
                        );
                        unset($result);
                    }
                }
            }
            unset($result);

            return [
                'success' => true,
                'data' => $results,
                'total' => count($results),
                'tenant_id' => $this->context->getTenantId()
            ];
        } catch (Exception $e) {
            $this->logger->error('searchSchoolSettings error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private function getGroupForSetting(string $key): string
    {
        $groups = [
            'school_name' => 'profile',
            'school_legal_name' => 'profile',
            'school_short_name' => 'profile',
            'school_code' => 'profile',
            'school_type' => 'profile',
            'school_email' => 'profile',
            'school_phone' => 'profile',
            'school_website' => 'profile',
            'school_description' => 'profile',
            'brand_primary_color' => 'branding',
            'brand_secondary_color' => 'branding',
            'brand_accent_color' => 'branding',
            'brand_motto' => 'branding',
            'brand_slogan' => 'branding',
            'regional_country' => 'regional',
            'regional_currency' => 'regional',
            'regional_currency_symbol' => 'regional',
            'regional_timezone' => 'regional',
            'regional_language' => 'regional',
            'regional_date_format' => 'regional',
            'academic_calendar_type' => 'academic',
            'academic_working_days' => 'academic',
            'grading_system' => 'assessment',
            'pass_mark' => 'assessment',
            'report_position_display' => 'reports',
            'report_grade_display' => 'reports',
            'report_attendance_display' => 'reports',
            'portal_enabled' => 'portal',
            'portal_results_visibility' => 'portal',
            'sms_enabled' => 'communication',
            'email_enabled' => 'communication',
            'push_notifications_enabled' => 'communication',
            'security_two_factor_auth' => 'security',
            'security_audit_logging' => 'security',
            'finance_default_currency' => 'finance',
            'finance_tax_config' => 'finance',
            'integrations_hubtel_connected' => 'integrations',
            'integrations_biometric_connected' => 'integrations',
            'campus_default_timezone' => 'campuses',
            'campus_default_operating_hours' => 'campuses'
        ];
        return $groups[$key] ?? 'general';
    }

    private function isEncryptedSetting(string $key): bool
    {
        $encrypted = [
            'api_key',
            'api_secret',
            'client_secret',
            'webhook_secret',
            'sms_api_key',
            'payment_gateway_key',
            'private_key',
            'password',
            'token'
        ];
        foreach ($encrypted as $pattern) {
            if (stripos($key, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    public function getSettingGroups(): array
    {
        return SchoolSetting::CATEGORIES ?? [
            'general',
            'profile',
            'branding',
            'regional',
            'academic',
            'assessment',
            'reports',
            'portal',
            'communication',
            'security',
            'finance',
            'integrations',
            'campuses'
        ];
    }

    public function getCampusSettingGroups(): array
    {
        return CampusSetting::CAMPUS_CATEGORIES ?? [
            'general',
            'profile',
            'contact',
            'location',
            'branding',
            'operations',
            'attendance',
            'reports',
            'notifications'
        ];
    }

    public function getDefaultSettings(): array
    {
        return $this->schoolSettingModel->getDefaultSettings();
    }
}
