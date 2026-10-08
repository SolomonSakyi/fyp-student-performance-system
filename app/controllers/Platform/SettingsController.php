<?php

/**
 * SettingsController.php
 * Platform Settings Controller — dispatches every settings tab action.
 *
 * @package EduTrack
 * @subpackage Controllers\Platform
 * @version 2.6
 *
 * @filepath app/controllers/Platform/SettingsController.php
 *
 * v2.6 changes (2026-10-05):
 * - clearCache(), optimizeDatabase() and testEmailConnection() are
 *   now thin wrappers over the new SettingsMaintenanceService. The
 *   heavy lifting (filesystem walk, OPTIMIZE TABLE loop, SMTP
 *   socket + STARTTLS handshake) moved to the service so the tenant
 *   settings tab bodies can call it directly without an HTTP
 *   loopback. Every method's public signature, HTTP shape and
 *   response body is unchanged. The private
 *   removeDirectoryRecursive() helper moved to the service and is
 *   removed from this file.
 * - A new property $maintenanceService and one constructor line
 *   wire the service.
 * - Every other method is byte-identical to v2.5.
 *
 * v2.5 changes (2026-10-05) [ITEM-25]:
 * - testEmailConnection() now performs the STARTTLS handshake when
 *   smtp_encryption is 'tls'; opens an ssl:// socket with peer
 *   verification when 'ssl'. (Superseded by v2.6 for the body, but
 *   the behaviour is preserved through the service.)
 *
 * v2.4 changes (2026-10-05) [ITEMS 1-4]:
 * - Added clearCache() and optimizeDatabase().
 *
 * v2.3 changes (previous session):
 * - Tab-action methods for General, Security, Currency, Integrations,
 *   Appearance, Email, Advanced, Domains, plus the email_test and
 *   audit-log actions.
 */
$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/helpers/ResponseHelper.php';
require_once $projectRoot . 'app/services/Platform/SettingsService.php';
require_once $projectRoot . 'app/services/Platform/SchoolSettingsService.php';
require_once $projectRoot . 'app/services/Platform/SettingsMaintenanceService.php';

class SettingsController
{
    /**
     * @var DatabaseHelper
     */
    private $db;

    /**
     * @var LoggerHelper
     */
    private $logger;

    /**
     * @var SettingsService
     */
    private $tenantSettingsService;

    /**
     * @var SchoolSettingsService
     */
    private $schoolSettingsService;

    /**
     * @var SettingsMaintenanceService
     */
    private $maintenanceService;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->tenantSettingsService = new SettingsService();
        $this->schoolSettingsService = new SchoolSettingsService();
        $this->maintenanceService = new SettingsMaintenanceService();
    }

    // ============================================================
    // GENERAL SETTINGS
    // ============================================================
    public function getGeneralSettings(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $settings = $this->tenantSettingsService->getSettings('general', $tenantId);
            ResponseHelper::success($settings);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function updateGeneralSettings(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $userId   = $this->currentUserId();
            $allowedKeys = [
                'platform_name',
                'platform_url',
                'platform_email',
                'platform_phone',
                'platform_address',
                'timezone',
                'date_format',
                'time_format',
                'default_language',
                'maintenance_mode',
            ];
            $data = $this->filterPayload($allowedKeys);
            $this->tenantSettingsService->saveSettings($data, 'general', $tenantId, $userId);
            ResponseHelper::success(['saved' => true]);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    // ============================================================
    // SECURITY SETTINGS
    // ============================================================
    public function getSecuritySettings(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $settings = $this->tenantSettingsService->getSecuritySettings($tenantId);
            ResponseHelper::success($settings);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function updateSecuritySettings(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $userId   = $this->currentUserId();
            $allowedKeys = [
                'min_password_length',
                'require_uppercase',
                'require_lowercase',
                'require_numbers',
                'require_special_chars',
                'password_expiry_days',
                'prevent_password_reuse_count',
                'session_timeout_minutes',
                'max_concurrent_sessions',
                'require_2fa',
                'require_mfa',
                'max_login_attempts',
                'lockout_duration_minutes',
                'login_throttle_seconds',
                'ip_whitelist_enabled',
                'ip_whitelist',
                'ip_blacklist_enabled',
                'ip_blacklist',
                'audit_logging_enabled',
                'audit_retention_days',
            ];
            $data = $this->filterPayload($allowedKeys);
            $this->tenantSettingsService->saveSecuritySettings($data, $tenantId, null, $userId);
            ResponseHelper::success(['saved' => true]);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    // ============================================================
    // CURRENCY SETTINGS
    // ============================================================
    public function getCurrencySettings(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $settings = $this->tenantSettingsService->getSettings('currency', $tenantId);
            ResponseHelper::success($settings);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function updateCurrencySettings(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $userId   = $this->currentUserId();
            $allowedKeys = [
                'currency_code',
                'currency_symbol',
                'currency_position',
                'decimal_places',
                'thousand_separator',
                'decimal_separator',
            ];
            $data = $this->filterPayload($allowedKeys);
            $this->tenantSettingsService->saveSettings($data, 'currency', $tenantId, $userId);
            ResponseHelper::success(['saved' => true]);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    // ============================================================
    // INTEGRATIONS SETTINGS
    // ============================================================
    public function getIntegrationSettings(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $settings = $this->tenantSettingsService->getSettings('integrations', $tenantId);
            ResponseHelper::success($settings);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function updateIntegrationSettings(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $userId   = $this->currentUserId();
            $allowedKeys = [
                'google_analytics_id',
                'facebook_pixel_id',
                'recaptcha_site_key',
                'recaptcha_secret_key',
                'map_api_key',
                'sms_provider',
                'sms_api_key',
                'sms_sender_id',
                'email_provider',
                'email_api_key',
            ];
            $data = $this->filterPayload($allowedKeys);
            $this->tenantSettingsService->saveSettings($data, 'integrations', $tenantId, $userId);
            ResponseHelper::success(['saved' => true]);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    // ============================================================
    // APPEARANCE SETTINGS
    // ============================================================
    public function getAppearanceSettings(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $settings = $this->tenantSettingsService->getSettings('appearance', $tenantId);
            ResponseHelper::success($settings);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function updateAppearanceSettings(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $userId   = $this->currentUserId();
            $allowedKeys = [
                'primary_color',
                'secondary_color',
                'accent_color',
                'sidebar_theme',
                'header_theme',
                'logo_url',
                'favicon_url',
                'login_background_url',
                'font_family',
                'border_radius',
                'button_style',
            ];
            $data = $this->filterPayload($allowedKeys);
            $this->tenantSettingsService->saveSettings($data, 'appearance', $tenantId, $userId);
            ResponseHelper::success(['saved' => true]);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    // ============================================================
    // EMAIL SETTINGS
    // ============================================================
    public function getEmailSettings(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $settings = $this->tenantSettingsService->getSettings('email', $tenantId);
            ResponseHelper::success($settings);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function updateEmailSettings(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $userId   = $this->currentUserId();
            $allowedKeys = [
                'smtp_host',
                'smtp_port',
                'smtp_username',
                'smtp_password',
                'smtp_encryption',
                'from_email',
                'from_name',
                'reply_to_email',
            ];
            $data = $this->filterPayload($allowedKeys);
            $this->tenantSettingsService->saveSettings($data, 'email', $tenantId, $userId);
            ResponseHelper::success(['saved' => true]);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    /**
     * [v2.6] Test the SMTP connection.
     *
     * Thin wrapper over SettingsMaintenanceService::testEmailConnection.
     * Loads the tenant's email settings, passes them to the service,
     * and returns the service's verdict as a JSON response.
     */
    public function testEmailConnection(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $settings = $this->tenantSettingsService->getSettings('email', $tenantId);
            $result = $this->maintenanceService->testEmailConnection(is_array($settings) ? $settings : []);
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    // ============================================================
    // ADVANCED SETTINGS
    // ============================================================
    public function getAdvancedSettings(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $settings = $this->tenantSettingsService->getSettings('advanced', $tenantId);
            ResponseHelper::success($settings);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function updateAdvancedSettings(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $userId   = $this->currentUserId();
            $allowedKeys = [
                'cache_enabled',
                'cache_ttl',
                'debug_mode',
                'log_level',
                'api_rate_limit',
                'max_upload_size',
                'allowed_file_types',
                'session_lifetime',
                'cookie_secure',
                'cookie_httponly',
            ];
            $data = $this->filterPayload($allowedKeys);
            $this->tenantSettingsService->saveSettings($data, 'advanced', $tenantId, $userId);
            ResponseHelper::success(['saved' => true]);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    // ============================================================
    // CLEAR CACHE  [v2.6 — thin wrapper]
    // ============================================================
    public function clearCache(): void
    {
        try {
            $result = $this->maintenanceService->clearCache();
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    // ============================================================
    // OPTIMIZE DATABASE  [v2.6 — thin wrapper]
    // ============================================================
    public function optimizeDatabase(): void
    {
        try {
            $result = $this->maintenanceService->optimizeDatabase();
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    // ============================================================
    // DOMAIN SETTINGS
    // ============================================================
    public function getDomainSettings(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $settings = $this->tenantSettingsService->getSettings('domains', $tenantId);
            ResponseHelper::success($settings);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function updateDomainSettings(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $userId   = $this->currentUserId();
            $allowedKeys = [
                'primary_domain',
                'custom_domain',
                'subdomain',
                'domain_verified',
                'ssl_enabled',
            ];
            $data = $this->filterPayload($allowedKeys);
            $this->tenantSettingsService->saveSettings($data, 'domains', $tenantId, $userId);
            ResponseHelper::success(['saved' => true]);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function getDomainsByTenant(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $rows = $this->db->fetchAll(
                "SELECT id, domain_name, is_primary, is_verified, ssl_enabled, created_at
                 FROM tenant_domains
                 WHERE tenant_id = ? AND deleted_at IS NULL
                 ORDER BY is_primary DESC, id ASC",
                [$tenantId]
            );
            ResponseHelper::success($rows);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    // ============================================================
    // SCHOOL SETTINGS
    // ============================================================
    public function getSchoolSettings(): void
    {
        try {
            $schoolId = (int)($_GET['school_id'] ?? 0);
            if ($schoolId <= 0) {
                ResponseHelper::error('school_id required');
                return;
            }
            $result = $this->schoolSettingsService->getSchoolSettings($schoolId);
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function bulkUpdateSchoolSettings(): void
    {
        try {
            $schoolId = (int)($_POST['school_id'] ?? 0);
            $userId   = $this->currentUserId();
            $settings = $_POST['settings'] ?? [];
            if ($schoolId <= 0) {
                ResponseHelper::error('school_id required');
                return;
            }
            if (!is_array($settings) || empty($settings)) {
                ResponseHelper::error('No settings provided');
                return;
            }
            $result = $this->schoolSettingsService->bulkUpdateSchoolSettings($schoolId, $settings, $userId);
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    // ============================================================
    // CAMPUS SETTINGS
    // ============================================================
    public function getCampusSettings(): void
    {
        try {
            $campusId = (int)($_GET['campus_id'] ?? 0);
            if ($campusId <= 0) {
                ResponseHelper::error('campus_id required');
                return;
            }
            $result = $this->schoolSettingsService->getCampusSettings($campusId);
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function setCampusSetting(): void
    {
        try {
            $campusId = (int)($_POST['campus_id'] ?? 0);
            $key      = trim((string)($_POST['setting_key'] ?? ''));
            $value    = $_POST['setting_value'] ?? '';
            $userId   = $this->currentUserId();
            if ($campusId <= 0 || $key === '') {
                ResponseHelper::error('campus_id and setting_key required');
                return;
            }
            $result = $this->schoolSettingsService->setCampusSetting($campusId, $key, $value, $userId);
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function removeCampusOverride(): void
    {
        try {
            $campusId = (int)($_POST['campus_id'] ?? 0);
            $key      = trim((string)($_POST['setting_key'] ?? ''));
            $userId   = $this->currentUserId();
            if ($campusId <= 0 || $key === '') {
                ResponseHelper::error('campus_id and setting_key required');
                return;
            }
            $result = $this->schoolSettingsService->removeCampusOverride($campusId, $key, $userId);
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    // ============================================================
    // PAYMENT PROVIDERS
    // ============================================================
    public function getPaymentProviders(): void
    {
        try {
            $providerService = new PaymentProviderService();
            $result = $providerService->getProviders();
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function getPaymentProvider(): void
    {
        try {
            $providerId = (int)($_GET['provider_id'] ?? 0);
            if ($providerId <= 0) {
                ResponseHelper::error('provider_id required');
                return;
            }
            $providerService = new PaymentProviderService();
            $result = $providerService->getProvider($providerId);
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function savePaymentProvider(): void
    {
        try {
            $providerService = new PaymentProviderService();
            $data = $_POST;
            $result = $providerService->saveProvider($data);
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function updatePaymentProvider(): void
    {
        try {
            $providerId = (int)($_POST['provider_id'] ?? 0);
            if ($providerId <= 0) {
                ResponseHelper::error('provider_id required');
                return;
            }
            $providerService = new PaymentProviderService();
            $data = $_POST;
            $result = $providerService->saveProvider($data, $providerId);
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function deletePaymentProvider(): void
    {
        try {
            $providerId = (int)($_POST['provider_id'] ?? 0);
            if ($providerId <= 0) {
                ResponseHelper::error('provider_id required');
                return;
            }
            $providerService = new PaymentProviderService();
            $result = $providerService->deleteProvider($providerId);
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function testPaymentProvider(): void
    {
        try {
            $providerId = (int)($_POST['provider_id'] ?? 0);
            if ($providerId <= 0) {
                ResponseHelper::error('provider_id required');
                return;
            }
            $providerService = new PaymentProviderService();
            $result = $providerService->testProvider($providerId);
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    // ============================================================
    // HUBTEL + BANK
    // ============================================================
    public function getHubtelConfig(): void
    {
        try {
            $providerService = new PaymentProviderService();
            $result = $providerService->getProviderByCode('hubtel');
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function saveHubtelConfig(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $userId   = $this->currentUserId();
            $providerService = new PaymentProviderService();
            $result = $providerService->saveHubtelConfig($_POST, $tenantId, $userId);
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function testHubtelConnection(): void
    {
        try {
            $providerService = new PaymentProviderService();
            $result = $providerService->testHubtelConnection($_POST);
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function getBankConfig(): void
    {
        try {
            $providerService = new PaymentProviderService();
            $result = $providerService->getProviderByCode('bank');
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function saveBankConfig(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $userId   = $this->currentUserId();
            $providerService = new PaymentProviderService();
            $result = $providerService->saveBankConfig($_POST, $tenantId, $userId);
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function testBankConnection(): void
    {
        try {
            $providerService = new PaymentProviderService();
            $result = $providerService->testBankConnection($_POST);
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    // ============================================================
    // WEBHOOKS
    // ============================================================
    public function getWebhooks(): void
    {
        try {
            $providerService = new PaymentProviderService();
            $result = $providerService->getWebhooks();
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function createWebhook(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $userId   = $this->currentUserId();
            $providerService = new PaymentProviderService();
            $result = $providerService->createWebhook($_POST, $tenantId, $userId);
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function updateWebhook(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $userId   = $this->currentUserId();
            $providerService = new PaymentProviderService();
            $result = $providerService->updateWebhook($_POST, $tenantId, $userId);
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function deleteWebhook(): void
    {
        try {
            $webhookId = (int)($_POST['webhook_id'] ?? 0);
            $tenantId  = $this->currentTenantId();
            if ($webhookId <= 0) {
                ResponseHelper::error('webhook_id required');
                return;
            }
            $providerService = new PaymentProviderService();
            $result = $providerService->deleteWebhook($webhookId, $tenantId);
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function testWebhook(): void
    {
        try {
            $webhookId = (int)($_POST['webhook_id'] ?? 0);
            $tenantId  = $this->currentTenantId();
            if ($webhookId <= 0) {
                ResponseHelper::error('webhook_id required');
                return;
            }
            $providerService = new PaymentProviderService();
            $result = $providerService->testWebhook($webhookId, $tenantId);
            ResponseHelper::success($result);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    // ============================================================
    // SETTINGS AUDIT LOGS
    // ============================================================
    public function getSettingsAuditLogs(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $limit = (int)($_GET['limit'] ?? 100);
            if ($limit <= 0 || $limit > 1000) $limit = 100;
            $rows = $this->db->fetchAll(
                "SELECT id, action, setting_group, setting_key,
                        old_value, new_value, ip_address, user_agent, created_at
                 FROM settings_audit_log
                 WHERE tenant_id = ?
                 ORDER BY id DESC
                 LIMIT " . $limit,
                [$tenantId]
            );
            ResponseHelper::success($rows);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    public function exportSettingsAuditLogs(): void
    {
        try {
            $tenantId = $this->currentTenantId();
            $rows = $this->db->fetchAll(
                "SELECT id, action, setting_group, setting_key,
                        old_value, new_value, ip_address, user_agent, created_at
                 FROM settings_audit_log
                 WHERE tenant_id = ?
                 ORDER BY id DESC",
                [$tenantId]
            );
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="settings-audit-' . $tenantId . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'action', 'setting_group', 'setting_key', 'old_value', 'new_value', 'ip_address', 'user_agent', 'created_at']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['id'],
                    $r['action'],
                    $r['setting_group'],
                    $r['setting_key'],
                    $r['old_value'],
                    $r['new_value'],
                    $r['ip_address'],
                    $r['user_agent'],
                    $r['created_at'],
                ]);
            }
            fclose($out);
            exit;
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    // ============================================================
    // INTERNAL HELPERS
    // ============================================================
    private function currentTenantId(): int
    {
        return (int)($_SESSION['tenant_id'] ?? 0);
    }

    private function currentUserId(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    private function filterPayload(array $allowedKeys): array
    {
        $out = [];
        foreach ($allowedKeys as $k) {
            if (array_key_exists($k, $_POST)) {
                $out[$k] = $_POST[$k];
            }
        }
        return $out;
    }
}
