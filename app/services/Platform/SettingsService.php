<?php

/**
 * SettingsService.php
 * Tenant-Level Settings Service with Multi-Tenant Isolation
 * 
 * This service handles tenant-level settings (not school-level).
 * For school and campus settings, use SchoolSettingsService.
 * 
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 2.4
 * 
 * @filepath app/services/Platform/SettingsService.php
 *
 * v2.4 change (2026-10-05) [ITEM-5] [ITEM-11b] [ITEM-11c]:
 *   - getSettings() no longer blanks the whole group when a single
 *     encrypted value fails to decrypt. The decrypt call is now wrapped
 *     in a per-row try/catch; a failing value is skipped, the key and
 *     the exception message are logged, and the loop continues. Prior
 *     behaviour: any single decryption failure propagated to the
 *     method-level catch, which returned [] and blanked every setting
 *     in the group. getSecuritySettings() is not changed: it reads a
 *     single row from tenant_security_settings and does not decrypt per
 *     row.
 *
 *   - logAudit() now supplies the uuid column in its INSERT into
 *     settings_audit_log. The column is VARCHAR(36) NOT NULL with a
 *     unique key and no default; the previous INSERT omitted it, which
 *     caused either a strict-mode failure ("Field 'uuid' doesn't have a
 *     default value") or an empty-string substitution that the unique
 *     key rejected on the second insert. The uuid is generated with
 *     bin2hex(random_bytes(16)), the same pattern used in
 *     saveSettings() and saveSecuritySettings() since v2.2.
 *
 *   - school_id and campus_id in the same INSERT are left NULL.
 *     logAudit() has no school or campus context at call time; the
 *     columns are nullable; and extending the method's signature is a
 *     separate scope. This is by design, not an oversight.
 *
 *   - The writer continues to use setting_group. The
 *     settings_audit_log table also carries a section column; the
 *     second writer that uses section is not in this file and is not
 *     changed by this patch. [ITEM-11] remains open pending that writer.
 *
 * v2.1 change (2026-10-02):
 *   getSetting() coerces the return of DatabaseHelper::fetchOne()
 *   from false to null. fetchOne() returns false when no row is
 *   found; the method declared ?array; PHP threw a TypeError the
 *   first time saveSettings() called getSetting() for a key that
 *   did not yet exist.
 *
 * v2.2 change (2026-10-02):
 *   saveSettings() and saveSecuritySettings() now supply a uuid in
 *   their INSERT statements. The uuid columns on tenant_settings and
 *   tenant_security_settings are VARCHAR(36) NOT NULL with a unique
 *   key, and have no default. The INSERTs omitted the column, MySQL
 *   substituted the empty string, and the unique key rejected the
 *   second insert.
 *
 *   The uuid is generated with bin2hex(random_bytes(16)), the same
 *   pattern used in api/platform/index.php for platform_users.uuid.
 *   It produces a 32-character hex string that fits the VARCHAR(36)
 *   column.
 *
 *   Nothing else changed.
 */

$projectRoot = dirname(__DIR__, 3) . '/';

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/services/Tenant/TenantContext.php';
require_once $projectRoot . 'app/services/Security/EncryptionService.php';
require_once $projectRoot . 'app/services/Security/SchoolOwnershipValidator.php';

class SettingsService
{
    /**
     * Database instance
     * @var DatabaseHelper
     */
    private $db;

    /**
     * Logger instance
     * @var LoggerHelper
     */
    private $logger;

    /**
     * Tenant context
     * @var TenantContext
     */
    private $context;

    /**
     * Encryption service
     * @var EncryptionService
     */
    private $encryptionService;

    /**
     * Ownership validator
     * @var SchoolOwnershipValidator
     */
    private $validator;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->context = TenantContext::getInstance();
        $this->encryptionService = EncryptionService::getInstance();
        $this->validator = new SchoolOwnershipValidator();
    }

    // ============================================================
    // TENANT SETTINGS
    // ============================================================

    /**
     * Get tenant settings
     *
     * [v2.4 ITEM-5] Each encrypted value is decrypted inside a per-row
     * try/catch. A single decryption failure no longer blanks the whole
     * group: the failing row is skipped, the key and the exception are
     * logged, and the loop continues.
     *
     * @param string $group Setting group
     * @param int|null $tenantId Tenant ID (defaults to current)
     * @return array Settings
     */
    public function getSettings(string $group, ?int $tenantId = null): array
    {
        if (!$tenantId) {
            $tenantId = $this->context->getTenantId();
        }

        if (!$tenantId) {
            throw new Exception('No tenant context available', 401);
        }

        // Check permission
        if (!$this->context->hasPermission('settings.view')) {
            throw new Exception('Permission denied', 403);
        }

        try {
            $sql = "SELECT * FROM tenant_settings 
                    WHERE tenant_id = ? AND setting_group = ? AND deleted_at IS NULL";
            $results = $this->db->fetchAll($sql, [$tenantId, $group]);

            $settings = [];
            foreach ($results as $row) {
                $value = $row['setting_value'];
                if ($row['is_encrypted'] == 1) {
                    // [v2.4 ITEM-5] Per-row try/catch. A single bad ciphertext
                    // must not blank the whole group. Skip the failing key,
                    // log it, continue.
                    try {
                        $value = $this->encryptionService->decrypt($value);
                    } catch (Exception $e) {
                        $this->logger->error(
                            'Settings decrypt failed for group "' . $group . '", key "' . $row['setting_key'] . '": ' . $e->getMessage()
                        );
                        continue;
                    }
                }
                $settings[$row['setting_key']] = $value;
            }

            return $settings;
        } catch (Exception $e) {
            $this->logger->error('getSettings error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Save tenant settings
     * 
     * @param array $settings Settings data
     * @param string $group Setting group
     * @param int|null $tenantId Tenant ID (defaults to current)
     * @param int|null $userId User ID (defaults to current)
     * @return bool Success
     */
    public function saveSettings(array $settings, string $group, ?int $tenantId = null, ?int $userId = null): bool
    {
        if (!$tenantId) {
            $tenantId = $this->context->getTenantId();
        }

        if (!$userId) {
            $userId = $this->context->getUserId();
        }

        if (!$tenantId || !$userId) {
            throw new Exception('Authentication required', 401);
        }

        // Check permission
        if (!$this->context->hasPermission('settings.edit')) {
            throw new Exception('Permission denied', 403);
        }

        try {
            $this->db->beginTransaction();

            foreach ($settings as $key => $value) {
                // Check if this setting should be encrypted
                $isEncrypted = $this->isEncryptedSetting($key);
                $storeValue = $isEncrypted ? $this->encryptionService->encrypt($value) : $value;

                // Check if setting exists
                $existing = $this->getSetting($tenantId, $key, $group);

                if ($existing) {
                    // Update
                    $sql = "UPDATE tenant_settings 
                            SET setting_value = ?, 
                                is_encrypted = ?,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE tenant_id = ? AND setting_key = ? AND setting_group = ? AND deleted_at IS NULL";
                    $this->db->execute($sql, [
                        $storeValue,
                        $isEncrypted ? 1 : 0,
                        $tenantId,
                        $key,
                        $group
                    ]);
                } else {
                    // Insert
                    $sql = "INSERT INTO tenant_settings 
                            (uuid, tenant_id, setting_key, setting_value, setting_group, is_encrypted, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $this->db->execute($sql, [
                        bin2hex(random_bytes(16)),
                        $tenantId,
                        $key,
                        $storeValue,
                        $group,
                        $isEncrypted ? 1 : 0,
                        $userId
                    ]);
                }

                // Log audit
                $this->logAudit($tenantId, $userId, $key, $group, null, $value);
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logger->error('saveSettings error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get a single tenant setting
     * 
     * @param int $tenantId Tenant ID
     * @param string $key Setting key
     * @param string $group Setting group
     * @return array|null Setting or null
     */
    private function getSetting(int $tenantId, string $key, string $group): ?array
    {
        $sql = "SELECT * FROM tenant_settings 
                WHERE tenant_id = ? AND setting_key = ? AND setting_group = ? AND deleted_at IS NULL";
        $result = $this->db->fetchOne($sql, [$tenantId, $key, $group]);
        return $result === false ? null : $result;
    }

    /**
     * Check if setting should be encrypted
     * 
     * @param string $key Setting key
     * @return bool True if encrypted
     */
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
            'exchange_api_key',
            'smtp_password'
        ];

        foreach ($encrypted as $pattern) {
            if (stripos($key, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Log audit
     *
     * [v2.4 ITEM-11b] The INSERT now supplies the uuid column. The
     * column is VARCHAR(36) NOT NULL with a unique key and no default;
     * the previous INSERT omitted it, which caused either a strict-mode
     * failure or an empty-string substitution that the unique key
     * rejected on the second insert. The uuid is generated with
     * bin2hex(random_bytes(16)), the same pattern used in saveSettings()
     * and saveSecuritySettings() since v2.2.
     *
     * [v2.4 ITEM-11c] school_id and campus_id are left NULL. logAudit()
     * has no school or campus context at call time; the columns are
     * nullable; and extending the method's signature is a separate
     * scope. This is by design, not an oversight.
     *
     * [ITEM-11] The writer continues to use setting_group. The
     * settings_audit_log table also carries a section column; the
     * second writer that uses section is not in this file and is not
     * changed by this patch.
     */
    private function logAudit(int $tenantId, int $userId, string $key, string $group, $oldValue, $newValue): void
    {
        try {
            $sql = "INSERT INTO settings_audit_log 
                    (uuid, tenant_id, user_id, setting_group, setting_key, action, old_value, new_value, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, ?, 'update', ?, ?, ?, ?)";

            $this->db->execute($sql, [
                bin2hex(random_bytes(16)),
                $tenantId,
                $userId,
                $group,
                $key,
                json_encode(['value' => $oldValue]),
                json_encode(['value' => $newValue]),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            // Log but don't fail the main operation
            $this->logger->error('logAudit error: ' . $e->getMessage());
        }
    }

    // ============================================================
    // SECURITY SETTINGS
    // ============================================================

    /**
     * Get security settings
     * 
     * @param int|null $tenantId Tenant ID
     * @param int|null $schoolId School ID (optional)
     * @return array Security settings
     */
    public function getSecuritySettings(?int $tenantId = null, ?int $schoolId = null): array
    {
        if (!$tenantId) {
            $tenantId = $this->context->getTenantId();
        }

        if (!$tenantId) {
            throw new Exception('No tenant context available', 401);
        }

        if (!$this->context->hasPermission('settings.view')) {
            throw new Exception('Permission denied', 403);
        }

        try {
            $sql = "SELECT * FROM tenant_security_settings WHERE tenant_id = ? AND deleted_at IS NULL";
            $settings = $this->db->fetchOne($sql, [$tenantId]);

            if ($settings) {
                return $settings;
            }

            // Return defaults
            return [
                'tenant_id' => $tenantId,
                'min_password_length' => 8,
                'require_uppercase' => 1,
                'require_lowercase' => 1,
                'require_numbers' => 1,
                'require_special_chars' => 1,
                'password_expiry_days' => 90,
                'prevent_password_reuse_count' => 5,
                'session_timeout_minutes' => 60,
                'max_concurrent_sessions' => 3,
                'require_2fa' => 0,
                'require_mfa' => 0,
                'max_login_attempts' => 5,
                'lockout_duration_minutes' => 30,
                'login_throttle_seconds' => 60,
                'ip_whitelist_enabled' => 0,
                'ip_whitelist' => null,
                'ip_blacklist_enabled' => 0,
                'ip_blacklist' => null,
                'audit_logging_enabled' => 1,
                'audit_retention_days' => 90
            ];
        } catch (Exception $e) {
            $this->logger->error('getSecuritySettings error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Save security settings
     * 
     * @param array $settings Security settings
     * @param int|null $tenantId Tenant ID
     * @param int|null $schoolId School ID (optional)
     * @param int|null $userId User ID
     * @return bool Success
     */
    public function saveSecuritySettings(array $settings, ?int $tenantId = null, ?int $schoolId = null, ?int $userId = null): bool
    {
        if (!$tenantId) {
            $tenantId = $this->context->getTenantId();
        }

        if (!$userId) {
            $userId = $this->context->getUserId();
        }

        if (!$tenantId || !$userId) {
            throw new Exception('Authentication required', 401);
        }

        if (!$this->context->hasPermission('settings.edit')) {
            throw new Exception('Permission denied', 403);
        }

        try {
            // Check if security settings exist
            $sql = "SELECT id FROM tenant_security_settings WHERE tenant_id = ? AND deleted_at IS NULL";
            $existing = $this->db->fetchOne($sql, [$tenantId]);

            if ($existing) {
                // Update
                $sql = "UPDATE tenant_security_settings SET
                            min_password_length = ?,
                            require_uppercase = ?,
                            require_lowercase = ?,
                            require_numbers = ?,
                            require_special_chars = ?,
                            password_expiry_days = ?,
                            prevent_password_reuse_count = ?,
                            session_timeout_minutes = ?,
                            max_concurrent_sessions = ?,
                            require_2fa = ?,
                            require_mfa = ?,
                            max_login_attempts = ?,
                            lockout_duration_minutes = ?,
                            login_throttle_seconds = ?,
                            ip_whitelist_enabled = ?,
                            ip_whitelist = ?,
                            ip_blacklist_enabled = ?,
                            ip_blacklist = ?,
                            audit_logging_enabled = ?,
                            audit_retention_days = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE tenant_id = ? AND deleted_at IS NULL";

                return $this->db->execute($sql, [
                    $settings['min_password_length'] ?? 8,
                    $settings['require_uppercase'] ?? 1,
                    $settings['require_lowercase'] ?? 1,
                    $settings['require_numbers'] ?? 1,
                    $settings['require_special_chars'] ?? 1,
                    $settings['password_expiry_days'] ?? 90,
                    $settings['prevent_password_reuse_count'] ?? 5,
                    $settings['session_timeout_minutes'] ?? 60,
                    $settings['max_concurrent_sessions'] ?? 3,
                    $settings['require_2fa'] ?? 0,
                    $settings['require_mfa'] ?? 0,
                    $settings['max_login_attempts'] ?? 5,
                    $settings['lockout_duration_minutes'] ?? 30,
                    $settings['login_throttle_seconds'] ?? 60,
                    $settings['ip_whitelist_enabled'] ?? 0,
                    $settings['ip_whitelist'] ?? null,
                    $settings['ip_blacklist_enabled'] ?? 0,
                    $settings['ip_blacklist'] ?? null,
                    $settings['audit_logging_enabled'] ?? 1,
                    $settings['audit_retention_days'] ?? 90,
                    $tenantId
                ]);
            } else {
                // Insert
                $sql = "INSERT INTO tenant_security_settings SET
                            uuid = ?,
                            tenant_id = ?,
                            min_password_length = ?,
                            require_uppercase = ?,
                            require_lowercase = ?,
                            require_numbers = ?,
                            require_special_chars = ?,
                            password_expiry_days = ?,
                            prevent_password_reuse_count = ?,
                            session_timeout_minutes = ?,
                            max_concurrent_sessions = ?,
                            require_2fa = ?,
                            require_mfa = ?,
                            max_login_attempts = ?,
                            lockout_duration_minutes = ?,
                            login_throttle_seconds = ?,
                            ip_whitelist_enabled = ?,
                            ip_whitelist = ?,
                            ip_blacklist_enabled = ?,
                            ip_blacklist = ?,
                            audit_logging_enabled = ?,
                            audit_retention_days = ?,
                            created_by = ?";

                return $this->db->execute($sql, [
                    bin2hex(random_bytes(16)),
                    $tenantId,
                    $settings['min_password_length'] ?? 8,
                    $settings['require_uppercase'] ?? 1,
                    $settings['require_lowercase'] ?? 1,
                    $settings['require_numbers'] ?? 1,
                    $settings['require_special_chars'] ?? 1,
                    $settings['password_expiry_days'] ?? 90,
                    $settings['prevent_password_reuse_count'] ?? 5,
                    $settings['session_timeout_minutes'] ?? 60,
                    $settings['max_concurrent_sessions'] ?? 3,
                    $settings['require_2fa'] ?? 0,
                    $settings['require_mfa'] ?? 0,
                    $settings['max_login_attempts'] ?? 5,
                    $settings['lockout_duration_minutes'] ?? 30,
                    $settings['login_throttle_seconds'] ?? 60,
                    $settings['ip_whitelist_enabled'] ?? 0,
                    $settings['ip_whitelist'] ?? null,
                    $settings['ip_blacklist_enabled'] ?? 0,
                    $settings['ip_blacklist'] ?? null,
                    $settings['audit_logging_enabled'] ?? 1,
                    $settings['audit_retention_days'] ?? 90,
                    $userId
                ]);
            }
        } catch (Exception $e) {
            $this->logger->error('saveSecuritySettings error: ' . $e->getMessage());
            throw $e;
        }
    }
}
