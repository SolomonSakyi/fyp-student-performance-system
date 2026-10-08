<?php

/**
 * SystemSetting.php
 * System Settings Model for Platform Management
 * 
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 2.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class SystemSetting extends BaseModel
{
    /**
     * @var string Table name - NO TYPE HINT
     */
    protected $table = 'platform_system_settings';

    /**
     * @var array Fillable fields
     */
    protected $fillable = [
        'setting_key',
        'setting_value',
        'setting_group',
        'description',
        'is_encrypted',
        'is_public'
    ];

    /**
     * @var array Hidden fields
     */
    protected $hidden = [];

    /**
     * Get setting by key
     */
    public function getByKey(string $key): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE setting_key = ? AND deleted_at IS NULL LIMIT 1";
        return $this->rawFetchOne($sql, [$key]);
    }

    /**
     * Get settings by group
     */
    public function getByGroup(string $group): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE setting_group = ? AND deleted_at IS NULL ORDER BY setting_key";
        return $this->rawFetch($sql, [$group]);
    }

    /**
     * Get all settings grouped
     */
    public function getAllGrouped(): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE deleted_at IS NULL ORDER BY setting_group, setting_key";
        $results = $this->rawFetch($sql);

        $grouped = [];
        foreach ($results as $setting) {
            $group = $setting['setting_group'] ?? 'general';
            if (!isset($grouped[$group])) {
                $grouped[$group] = [];
            }

            // Decrypt if needed
            if ($setting['is_encrypted'] == 1 && !empty($setting['setting_value'])) {
                $setting['setting_value'] = $this->decrypt($setting['setting_value']);
            }

            $grouped[$group][$setting['setting_key']] = $setting['setting_value'];
        }
        return $grouped;
    }

    /**
     * Set a setting value
     */
    public function set(string $key, $value, string $group = 'general', string $description = '', bool $encrypted = false): bool
    {
        try {
            if ($encrypted) {
                $value = $this->encrypt($value);
            }

            $existing = $this->getByKey($key);
            if ($existing) {
                return $this->update($existing['id'], [
                    'setting_value' => $value,
                    'setting_group' => $group,
                    'description' => $description,
                    'is_encrypted' => $encrypted ? 1 : 0
                ]);
            }

            return $this->create([
                'setting_key' => $key,
                'setting_value' => $value,
                'setting_group' => $group,
                'description' => $description,
                'is_encrypted' => $encrypted ? 1 : 0
            ]) != null;
        } catch (Exception $e) {
            $this->logger->error('set error: ' . $e->getMessage(), ['key' => $key]);
            return false;
        }
    }

    /**
     * Get a setting value
     */
    public function get(string $key, $default = null)
    {
        $setting = $this->getByKey($key);
        if (!$setting) {
            return $default;
        }

        $value = $setting['setting_value'];
        if ($setting['is_encrypted'] == 1 && !empty($value)) {
            $value = $this->decrypt($value);
        }
        return $value;
    }

    /**
     * Delete a setting
     */
    public function deleteByKey(string $key): bool
    {
        $setting = $this->getByKey($key);
        if (!$setting) {
            return false;
        }
        return $this->delete($setting['id']);
    }

    /**
     * Get settings as key-value array
     */
    public function getAsArray(string $group = null): array
    {
        $sql = "SELECT setting_key, setting_value, is_encrypted FROM {$this->table}";
        $params = [];

        if ($group) {
            $sql .= " WHERE setting_group = ?";
            $params[] = $group;
        }
        $sql .= " AND deleted_at IS NULL";

        $results = $this->rawFetch($sql, $params);
        $settings = [];

        foreach ($results as $setting) {
            $value = $setting['setting_value'];
            if ($setting['is_encrypted'] == 1 && !empty($value)) {
                $value = $this->decrypt($value);
            }
            // Try to decode JSON
            $decoded = json_decode($value, true);
            $settings[$setting['setting_key']] = $decoded !== null ? $decoded : $value;
        }

        return $settings;
    }

    /**
     * Get default settings
     */
    public function getDefaults(): array
    {
        return [
            // General Settings
            'platform_name' => 'EduTrack',
            'platform_url' => 'https://edutrack.com',
            'platform_email' => 'info@edutrack.com',
            'platform_phone' => '+233-XXX-XXX-XXX',
            'default_language' => 'en',
            'default_timezone' => 'Africa/Accra',
            'default_currency' => 'GHS',
            'date_format' => 'DD/MM/YYYY',
            'time_format' => '24h',

            // Security Settings
            'password_min_length' => 8,
            'password_require_uppercase' => 1,
            'password_require_lowercase' => 1,
            'password_require_number' => 1,
            'password_require_special' => 1,
            'session_timeout_minutes' => 60,
            'max_login_attempts' => 5,
            'lockout_duration_minutes' => 15,
            'jwt_expiry_hours' => 24,
            'refresh_token_expiry_days' => 7,
            'rate_limit_per_minute' => 100,
            'rate_limit_per_hour' => 1000,

            // Payment Settings
            'currency_code' => 'GHS',
            'currency_symbol' => '₵',
            'tax_rate' => 0,
            'tax_name' => 'VAT',
            'payment_providers' => '["mtn_momo", "telecel_cash", "hubtel", "paystack", "flutterwave", "bank_transfer"]',
            'default_payment_provider' => 'hubtel',
            'enable_invoice_generation' => 1,
            'invoice_prefix' => 'INV-',
            'invoice_due_days' => 30,

            // Email Settings
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_encryption' => 'tls',
            'from_email' => 'noreply@edutrack.com',
            'from_name' => 'EduTrack Platform',

            // SMS Settings
            'sms_provider' => '',
            'sms_api_key' => '',
            'sms_sender_id' => 'EduTrack',
            'sms_enabled' => 0,

            // Backup Settings
            'backup_enabled' => 1,
            'backup_frequency' => 'daily',
            'backup_time' => '02:00:00',
            'backup_retention_days' => 30,
            'backup_location' => 'local',

            // Maintenance Settings
            'maintenance_mode' => 0,
            'maintenance_message' => 'System is under maintenance. Please check back later.',
            'debug_mode' => 0,
            'log_level' => 'error'
        ];
    }

    /**
     * Initialize default settings
     */
    public function initializeDefaults(): int
    {
        $defaults = $this->getDefaults();
        $count = 0;

        foreach ($defaults as $key => $value) {
            $existing = $this->getByKey($key);
            if (!$existing) {
                $group = $this->getGroupForSetting($key);
                $encrypted = $this->isEncryptedSetting($key);
                $this->set($key, $value, $group, '', $encrypted);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get group for a setting key
     */
    private function getGroupForSetting(string $key): string
    {
        $groups = [
            'platform_name' => 'general',
            'platform_url' => 'general',
            'platform_email' => 'general',
            'platform_phone' => 'general',
            'default_language' => 'general',
            'default_timezone' => 'general',
            'default_currency' => 'general',
            'date_format' => 'general',
            'time_format' => 'general',

            'password_min_length' => 'security',
            'password_require_uppercase' => 'security',
            'password_require_lowercase' => 'security',
            'password_require_number' => 'security',
            'password_require_special' => 'security',
            'session_timeout_minutes' => 'security',
            'max_login_attempts' => 'security',
            'lockout_duration_minutes' => 'security',
            'jwt_expiry_hours' => 'security',
            'refresh_token_expiry_days' => 'security',
            'rate_limit_per_minute' => 'security',
            'rate_limit_per_hour' => 'security',

            'currency_code' => 'payment',
            'currency_symbol' => 'payment',
            'tax_rate' => 'payment',
            'tax_name' => 'payment',
            'payment_providers' => 'payment',
            'default_payment_provider' => 'payment',
            'enable_invoice_generation' => 'payment',
            'invoice_prefix' => 'payment',
            'invoice_due_days' => 'payment',

            'smtp_host' => 'email',
            'smtp_port' => 'email',
            'smtp_username' => 'email',
            'smtp_password' => 'email',
            'smtp_encryption' => 'email',
            'from_email' => 'email',
            'from_name' => 'email',

            'sms_provider' => 'sms',
            'sms_api_key' => 'sms',
            'sms_sender_id' => 'sms',
            'sms_enabled' => 'sms',

            'backup_enabled' => 'backup',
            'backup_frequency' => 'backup',
            'backup_time' => 'backup',
            'backup_retention_days' => 'backup',
            'backup_location' => 'backup',

            'maintenance_mode' => 'maintenance',
            'maintenance_message' => 'maintenance',
            'debug_mode' => 'maintenance',
            'log_level' => 'maintenance'
        ];

        return $groups[$key] ?? 'general';
    }

    /**
     * Check if setting should be encrypted
     */
    private function isEncryptedSetting(string $key): bool
    {
        $encrypted = [
            'smtp_password',
            'sms_api_key',
            'payment_provider_secret',
            'recaptcha_secret'
        ];
        return in_array($key, $encrypted);
    }

    /**
     * Simple encryption (use real encryption in production)
     */
    private function encrypt(string $value): string
    {
        // In production, use openssl_encrypt with proper key
        return base64_encode($value);
    }

    /**
     * Simple decryption (use real decryption in production)
     */
    private function decrypt(string $value): string
    {
        // In production, use openssl_decrypt with proper key
        return base64_decode($value);
    }
}
