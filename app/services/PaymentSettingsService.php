<?php

/**
 * Payment Settings Service - Enterprise Payment Configuration
 */

require_once __DIR__ . '/../helpers/DatabaseHelper.php';
require_once __DIR__ . '/../helpers/LoggerHelper.php';
require_once __DIR__ . '/../helpers/EncryptionHelper.php';

class PaymentSettingsService
{
    private $db;
    private $logger;
    private $encryption;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->encryption = new EncryptionHelper();
    }

    /**
     * Get all providers with their status
     */
    public function getProviders(): array
    {
        $providers = $this->db->fetchAll("
            SELECT p.*, pt.type_name as provider_type,
                   COUNT(DISTINCT pm.id) as method_count,
                   (SELECT COUNT(*) FROM payment_provider_credentials pc 
                    WHERE pc.provider_id = p.id AND pc.is_active = 1) as credential_count,
                   (SELECT status FROM payment_provider_health ph 
                    WHERE ph.provider_id = p.id ORDER BY ph.last_check_at DESC LIMIT 1) as health_status
            FROM payment_providers p
            JOIN payment_provider_types pt ON p.provider_type_id = pt.id
            LEFT JOIN payment_provider_methods pm ON p.id = pm.provider_id AND pm.deleted_at IS NULL
            WHERE p.deleted_at IS NULL
            GROUP BY p.id
            ORDER BY p.is_default DESC, p.priority ASC, p.provider_name ASC
        ");

        return $providers;
    }

    /**
     * Get provider details
     */
    public function getProvider(int $providerId): ?array
    {
        return $this->db->fetchOne("
            SELECT p.*, pt.type_name as provider_type,
                   (SELECT COUNT(*) FROM payment_provider_methods pm 
                    WHERE pm.provider_id = p.id AND pm.deleted_at IS NULL) as method_count
            FROM payment_providers p
            JOIN payment_provider_types pt ON p.provider_type_id = pt.id
            WHERE p.id = ? AND p.deleted_at IS NULL
        ", [$providerId]);
    }

    /**
     * Get provider by code
     */
    public function getProviderByCode(string $code): ?array
    {
        return $this->db->fetchOne("
            SELECT * FROM payment_providers 
            WHERE provider_code = ? AND deleted_at IS NULL
        ", [$code]);
    }

    /**
     * Create provider
     */
    public function createProvider(array $data): array
    {
        try {
            $this->db->beginTransaction();

            // Check if provider code exists
            $existing = $this->db->fetchOne("
                SELECT id FROM payment_providers WHERE provider_code = ?
            ", [$data['provider_code']]);

            if ($existing) {
                throw new Exception('Provider code already exists');
            }

            $uuid = $this->generateUuid();

            $this->db->query("
                INSERT INTO payment_providers (
                    uuid, provider_code, provider_name, provider_type_id,
                    description, is_active, is_default, priority,
                    supports_sandbox, supports_production, supports_refund,
                    supports_partial_refund, supports_webhook, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ", [
                $uuid,
                $data['provider_code'],
                $data['provider_name'],
                $data['provider_type_id'],
                $data['description'] ?? null,
                $data['is_active'] ?? 1,
                $data['is_default'] ?? 0,
                $data['priority'] ?? 0,
                $data['supports_sandbox'] ?? 1,
                $data['supports_production'] ?? 1,
                $data['supports_refund'] ?? 0,
                $data['supports_partial_refund'] ?? 0,
                $data['supports_webhook'] ?? 1,
                $data['created_by'] ?? 1
            ]);

            $providerId = $this->db->lastInsertId();

            // Create audit log
            $this->createAuditLog($providerId, 'CREATE', $data);

            $this->db->commit();

            return [
                'success' => true,
                'data' => ['id' => $providerId],
                'message' => 'Provider created successfully'
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            $this->logger->error('Failed to create provider: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update provider
     */
    public function updateProvider(int $providerId, array $data): array
    {
        try {
            $this->db->beginTransaction();

            $fields = [];
            $params = [];

            $allowed = [
                'provider_name',
                'description',
                'is_active',
                'is_default',
                'priority',
                'supports_sandbox',
                'supports_production',
                'supports_refund',
                'supports_partial_refund',
                'supports_webhook'
            ];

            foreach ($allowed as $field) {
                if (isset($data[$field])) {
                    $fields[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($fields)) {
                throw new Exception('No fields to update');
            }

            $params[] = $providerId;
            $this->db->query("
                UPDATE payment_providers 
                SET " . implode(', ', $fields) . ", updated_at = NOW()
                WHERE id = ?
            ", $params);

            // Create audit log
            $this->createAuditLog($providerId, 'UPDATE', $data);

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Provider updated successfully'
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete provider (soft delete)
     */
    public function deleteProvider(int $providerId): array
    {
        try {
            // Check if provider is in use
            $inUse = $this->db->fetchOne("
                SELECT COUNT(*) as count FROM payment_transactions 
                WHERE provider_id = ? AND deleted_at IS NULL
            ", [$providerId]);

            if (($inUse['count'] ?? 0) > 0) {
                return ['success' => false, 'message' => 'Cannot delete provider with existing transactions'];
            }

            $this->db->query("
                UPDATE payment_providers SET deleted_at = NOW() WHERE id = ?
            ", [$providerId]);

            $this->createAuditLog($providerId, 'DELETE', ['reason' => 'Provider deleted']);

            return ['success' => true, 'message' => 'Provider deleted successfully'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get provider credentials
     */
    public function getCredentials(int $providerId, string $environment = 'production'): ?array
    {
        $credentials = $this->db->fetchOne("
            SELECT * FROM payment_provider_credentials 
            WHERE provider_id = ? AND environment = ? AND deleted_at IS NULL
        ", [$providerId, $environment]);

        if ($credentials) {
            // Decrypt sensitive fields
            $sensitiveFields = [
                'client_secret',
                'api_key',
                'api_secret',
                'access_token',
                'refresh_token',
                'encryption_key',
                'public_key',
                'private_key',
                'webhook_secret',
                'additional_config'
            ];

            foreach ($sensitiveFields as $field) {
                if (!empty($credentials[$field])) {
                    $credentials[$field] = $this->encryption->decrypt($credentials[$field]);
                }
            }
        }

        return $credentials;
    }

    /**
     * Save provider credentials
     */
    public function saveCredentials(int $providerId, array $data): array
    {
        try {
            $this->db->beginTransaction();

            // Check if credentials exist
            $existing = $this->db->fetchOne("
                SELECT id FROM payment_provider_credentials 
                WHERE provider_id = ? AND environment = ?
            ", [$providerId, $data['environment'] ?? 'production']);

            $sensitiveFields = [
                'client_secret',
                'api_key',
                'api_secret',
                'access_token',
                'refresh_token',
                'encryption_key',
                'public_key',
                'private_key',
                'webhook_secret',
                'additional_config'
            ];

            // Encrypt sensitive fields
            foreach ($sensitiveFields as $field) {
                if (!empty($data[$field])) {
                    $data[$field] = $this->encryption->encrypt($data[$field]);
                }
            }

            $uuid = $this->generateUuid();

            if ($existing) {
                // Update
                $fields = [];
                $params = [];

                $allowed = [
                    'client_id',
                    'client_secret',
                    'api_key',
                    'api_secret',
                    'access_token',
                    'refresh_token',
                    'encryption_key',
                    'account_number',
                    'merchant_id',
                    'terminal_id',
                    'account_merchant_number',
                    'public_key',
                    'private_key',
                    'webhook_secret',
                    'additional_config',
                    'is_active',
                    'is_default'
                ];

                foreach ($allowed as $field) {
                    if (isset($data[$field])) {
                        $fields[] = "$field = ?";
                        $params[] = $data[$field];
                    }
                }

                if (!empty($fields)) {
                    $params[] = $existing['id'];
                    $this->db->query("
                        UPDATE payment_provider_credentials 
                        SET " . implode(', ', $fields) . ", updated_at = NOW()
                        WHERE id = ?
                    ", $params);
                }
            } else {
                // Insert
                $this->db->query("
                    INSERT INTO payment_provider_credentials (
                        uuid, provider_id, environment, client_id, client_secret,
                        api_key, api_secret, access_token, refresh_token,
                        encryption_key, account_number, merchant_id, terminal_id,
                        account_merchant_number, public_key, private_key,
                        webhook_secret, additional_config, is_active, is_default,
                        created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ", [
                    $uuid,
                    $providerId,
                    $data['environment'] ?? 'production',
                    $data['client_id'] ?? null,
                    $data['client_secret'] ?? null,
                    $data['api_key'] ?? null,
                    $data['api_secret'] ?? null,
                    $data['access_token'] ?? null,
                    $data['refresh_token'] ?? null,
                    $data['encryption_key'] ?? null,
                    $data['account_number'] ?? null,
                    $data['merchant_id'] ?? null,
                    $data['terminal_id'] ?? null,
                    $data['account_merchant_number'] ?? null,
                    $data['public_key'] ?? null,
                    $data['private_key'] ?? null,
                    $data['webhook_secret'] ?? null,
                    $data['additional_config'] ?? null,
                    $data['is_active'] ?? 1,
                    $data['is_default'] ?? 0,
                    $data['created_by'] ?? 1
                ]);
            }

            $this->createAuditLog($providerId, 'CREDENTIALS_UPDATED', ['environment' => $data['environment'] ?? 'production']);

            $this->db->commit();

            return ['success' => true, 'message' => 'Credentials saved successfully'];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Test provider connection
     */
    public function testConnection(int $providerId, string $environment = 'production'): array
    {
        try {
            $credentials = $this->getCredentials($providerId, $environment);
            if (!$credentials) {
                return ['success' => false, 'message' => 'Credentials not configured'];
            }

            $provider = $this->getProvider($providerId);
            if (!$provider) {
                return ['success' => false, 'message' => 'Provider not found'];
            }

            // Get endpoints for this provider
            $endpoints = $this->db->fetchAll("
                SELECT * FROM payment_provider_endpoints 
                WHERE provider_id = ? AND environment = ? AND is_active = 1
            ", [$providerId, $environment]);

            if (empty($endpoints)) {
                return ['success' => false, 'message' => 'No endpoints configured for this environment'];
            }

            // Test each endpoint
            $results = [];
            $allSuccess = true;

            foreach ($endpoints as $endpoint) {
                $result = $this->testEndpoint($endpoint, $credentials);
                $results[] = [
                    'endpoint' => $endpoint['endpoint_name'],
                    'success' => $result['success'],
                    'message' => $result['message']
                ];
                if (!$result['success']) {
                    $allSuccess = false;
                }
            }

            // Update health status
            $this->updateProviderHealth($providerId, $allSuccess);

            return [
                'success' => $allSuccess,
                'data' => [
                    'provider' => $provider['provider_name'],
                    'environment' => $environment,
                    'results' => $results,
                    'tested_at' => date('Y-m-d H:i:s')
                ],
                'message' => $allSuccess ? 'All endpoints tested successfully' : 'Some endpoints failed'
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Test a single endpoint
     */
    private function testEndpoint(array $endpoint, array $credentials): array
    {
        // This would make an actual API call to test the endpoint
        // For now, simulate based on endpoint type
        try {
            $url = $endpoint['url'];
            $method = $endpoint['method'];

            // Prepare headers
            $headers = json_decode($endpoint['headers'] ?? '{}', true);
            $headers['Content-Type'] = 'application/json';

            // Add authentication headers based on available credentials
            if (!empty($credentials['api_key'])) {
                $headers['Authorization'] = 'Bearer ' . $credentials['api_key'];
            } elseif (!empty($credentials['client_id']) && !empty($credentials['client_secret'])) {
                $headers['Authorization'] = 'Basic ' . base64_encode($credentials['client_id'] . ':' . $credentials['client_secret']);
            }

            // Simulate API call
            // In production, use curl or guzzle
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $endpoint['timeout'] ?? 30);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->formatHeaders($headers));

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['test' => true]));
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return ['success' => false, 'message' => 'Connection error: ' . $error];
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                return ['success' => true, 'message' => 'HTTP ' . $httpCode . ' - OK'];
            } else {
                return ['success' => false, 'message' => 'HTTP ' . $httpCode . ' - ' . substr($response, 0, 100)];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update provider health
     */
    private function updateProviderHealth(int $providerId, bool $success): void
    {
        $status = $success ? 'OPERATIONAL' : 'DOWN';

        $this->db->query("
            INSERT INTO payment_provider_health (
                uuid, provider_id, status, last_check_at,
                last_success_at, last_failure_at,
                consecutive_failures, updated_at
            ) VALUES (
                ?, ?, ?, NOW(),
                CASE WHEN ? THEN NOW() ELSE NULL END,
                CASE WHEN ? THEN NULL ELSE NOW() END,
                CASE WHEN ? THEN 0 ELSE consecutive_failures + 1 END,
                NOW()
            ) ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                last_check_at = NOW(),
                last_success_at = VALUES(last_success_at),
                last_failure_at = VALUES(last_failure_at),
                consecutive_failures = VALUES(consecutive_failures),
                updated_at = NOW()
        ", [
            $this->generateUuid(),
            $providerId,
            $status,
            $success,
            !$success,
            $success
        ]);
    }

    /**
     * Get provider health
     */
    public function getProviderHealth(int $providerId): ?array
    {
        return $this->db->fetchOne("
            SELECT * FROM payment_provider_health 
            WHERE provider_id = ? 
            ORDER BY last_check_at DESC LIMIT 1
        ", [$providerId]);
    }

    /**
     * Get webhook configuration
     */
    public function getWebhookConfig(int $providerId): ?array
    {
        return $this->db->fetchOne("
            SELECT * FROM payment_provider_webhooks 
            WHERE provider_id = ? AND deleted_at IS NULL
        ", [$providerId]);
    }

    /**
     * Save webhook configuration
     */
    public function saveWebhookConfig(int $providerId, array $data): array
    {
        try {
            $existing = $this->db->fetchOne("
                SELECT id FROM payment_provider_webhooks 
                WHERE provider_id = ?
            ", [$providerId]);

            $webhookSecret = isset($data['webhook_secret']) ?
                $this->encryption->encrypt($data['webhook_secret']) : null;

            if ($existing) {
                $this->db->query("
                    UPDATE payment_provider_webhooks 
                    SET webhook_url = ?,
                        webhook_secret = ?,
                        webhook_events = ?,
                        is_active = ?,
                        signature_header = ?,
                        timeout = ?,
                        retry_count = ?,
                        retry_delay = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ", [
                    $data['webhook_url'],
                    $webhookSecret,
                    json_encode($data['webhook_events'] ?? []),
                    $data['is_active'] ?? 1,
                    $data['signature_header'] ?? 'x-webhook-signature',
                    $data['timeout'] ?? 30,
                    $data['retry_count'] ?? 3,
                    $data['retry_delay'] ?? 5,
                    $existing['id']
                ]);
            } else {
                $this->db->query("
                    INSERT INTO payment_provider_webhooks (
                        uuid, provider_id, webhook_url, webhook_secret,
                        webhook_events, is_active, signature_header,
                        timeout, retry_count, retry_delay, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ", [
                    $this->generateUuid(),
                    $providerId,
                    $data['webhook_url'],
                    $webhookSecret,
                    json_encode($data['webhook_events'] ?? []),
                    $data['is_active'] ?? 1,
                    $data['signature_header'] ?? 'x-webhook-signature',
                    $data['timeout'] ?? 30,
                    $data['retry_count'] ?? 3,
                    $data['retry_delay'] ?? 5,
                    $data['created_by'] ?? 1
                ]);
            }

            $this->createAuditLog($providerId, 'WEBHOOK_UPDATED', ['url' => $data['webhook_url']]);

            return ['success' => true, 'message' => 'Webhook configuration saved'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get reconciliation settings
     */
    public function getReconciliationSettings(int $providerId): ?array
    {
        return $this->db->fetchOne("
            SELECT * FROM payment_reconciliation_settings 
            WHERE provider_id = ? AND deleted_at IS NULL
        ", [$providerId]);
    }

    /**
     * Save reconciliation settings
     */
    public function saveReconciliationSettings(int $providerId, array $data): array
    {
        try {
            $existing = $this->db->fetchOne("
                SELECT id FROM payment_reconciliation_settings 
                WHERE provider_id = ?
            ", [$providerId]);

            if ($existing) {
                $this->db->query("
                    UPDATE payment_reconciliation_settings 
                    SET reconciliation_mode = ?,
                        polling_interval_minutes = ?,
                        polling_window_hours = ?,
                        auto_reconcile = ?,
                        tolerance_amount = ?,
                        tolerance_percentage = ?,
                        require_manual_approval = ?,
                        reconciliation_schedule = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ", [
                    $data['reconciliation_mode'] ?? 'HYBRID',
                    $data['polling_interval_minutes'] ?? 15,
                    $data['polling_window_hours'] ?? 24,
                    $data['auto_reconcile'] ?? 1,
                    $data['tolerance_amount'] ?? 0.00,
                    $data['tolerance_percentage'] ?? 0.00,
                    $data['require_manual_approval'] ?? 0,
                    $data['reconciliation_schedule'] ?? '*/15 * * * *',
                    $existing['id']
                ]);
            } else {
                $this->db->query("
                    INSERT INTO payment_reconciliation_settings (
                        uuid, provider_id, reconciliation_mode,
                        polling_interval_minutes, polling_window_hours,
                        auto_reconcile, tolerance_amount, tolerance_percentage,
                        require_manual_approval, reconciliation_schedule,
                        created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ", [
                    $this->generateUuid(),
                    $providerId,
                    $data['reconciliation_mode'] ?? 'HYBRID',
                    $data['polling_interval_minutes'] ?? 15,
                    $data['polling_window_hours'] ?? 24,
                    $data['auto_reconcile'] ?? 1,
                    $data['tolerance_amount'] ?? 0.00,
                    $data['tolerance_percentage'] ?? 0.00,
                    $data['require_manual_approval'] ?? 0,
                    $data['reconciliation_schedule'] ?? '*/15 * * * *',
                    $data['created_by'] ?? 1
                ]);
            }

            $this->createAuditLog($providerId, 'RECONCILIATION_UPDATED', $data);

            return ['success' => true, 'message' => 'Reconciliation settings saved'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get security settings
     */
    public function getSecuritySettings(): ?array
    {
        return $this->db->fetchOne("
            SELECT * FROM payment_security_settings 
            WHERE deleted_at IS NULL LIMIT 1
        ");
    }

    /**
     * Save security settings
     */
    public function saveSecuritySettings(array $data): array
    {
        try {
            $existing = $this->db->fetchOne("
                SELECT id FROM payment_security_settings LIMIT 1
            ");

            if ($existing) {
                $this->db->query("
                    UPDATE payment_security_settings 
                    SET require_server_verification = ?,
                        require_webhook_verification = ?,
                        enable_idempotency = ?,
                        enable_duplicate_protection = ?,
                        enable_payment_audit = ?,
                        enable_reconciliation = ?,
                        require_https = ?,
                        mask_credentials = ?,
                        restrict_credential_access = ?,
                        log_provider_metadata = ?,
                        ip_whitelist = ?,
                        allowed_ips = ?,
                        webhook_ip_whitelist = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ", [
                    $data['require_server_verification'] ?? 1,
                    $data['require_webhook_verification'] ?? 1,
                    $data['enable_idempotency'] ?? 1,
                    $data['enable_duplicate_protection'] ?? 1,
                    $data['enable_payment_audit'] ?? 1,
                    $data['enable_reconciliation'] ?? 1,
                    $data['require_https'] ?? 1,
                    $data['mask_credentials'] ?? 1,
                    $data['restrict_credential_access'] ?? 1,
                    $data['log_provider_metadata'] ?? 1,
                    json_encode($data['ip_whitelist'] ?? []),
                    json_encode($data['allowed_ips'] ?? []),
                    json_encode($data['webhook_ip_whitelist'] ?? []),
                    $existing['id']
                ]);
            } else {
                $this->db->query("
                    INSERT INTO payment_security_settings (
                        uuid, require_server_verification, require_webhook_verification,
                        enable_idempotency, enable_duplicate_protection,
                        enable_payment_audit, enable_reconciliation,
                        require_https, mask_credentials, restrict_credential_access,
                        log_provider_metadata, ip_whitelist, allowed_ips,
                        webhook_ip_whitelist, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ", [
                    $this->generateUuid(),
                    $data['require_server_verification'] ?? 1,
                    $data['require_webhook_verification'] ?? 1,
                    $data['enable_idempotency'] ?? 1,
                    $data['enable_duplicate_protection'] ?? 1,
                    $data['enable_payment_audit'] ?? 1,
                    $data['enable_reconciliation'] ?? 1,
                    $data['require_https'] ?? 1,
                    $data['mask_credentials'] ?? 1,
                    $data['restrict_credential_access'] ?? 1,
                    $data['log_provider_metadata'] ?? 1,
                    json_encode($data['ip_whitelist'] ?? []),
                    json_encode($data['allowed_ips'] ?? []),
                    json_encode($data['webhook_ip_whitelist'] ?? [])
                ]);
            }

            return ['success' => true, 'message' => 'Security settings saved'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get currency settings
     */
    public function getCurrencySettings(): array
    {
        return $this->db->fetchAll("
            SELECT * FROM payment_currency_settings 
            WHERE deleted_at IS NULL
            ORDER BY is_default DESC, currency_code ASC
        ");
    }

    /**
     * Save currency settings
     */
    public function saveCurrencySettings(array $data): array
    {
        try {
            $this->db->beginTransaction();

            // If setting default, clear other defaults
            if (isset($data['is_default']) && $data['is_default']) {
                $this->db->query("
                    UPDATE payment_currency_settings SET is_default = 0 WHERE id != ?
                ", [$data['id']]);
            }

            $this->db->query("
                UPDATE payment_currency_settings 
                SET is_active = ?,
                    is_default = ?,
                    exchange_rate = ?,
                    exchange_rate_updated_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ", [
                $data['is_active'] ?? 1,
                $data['is_default'] ?? 0,
                $data['exchange_rate'] ?? 1.0,
                $data['id']
            ]);

            $this->db->commit();

            return ['success' => true, 'message' => 'Currency settings saved'];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get transaction settings
     */
    public function getTransactionSettings(): ?array
    {
        return $this->db->fetchOne("
            SELECT * FROM payment_transaction_settings 
            WHERE deleted_at IS NULL LIMIT 1
        ");
    }

    /**
     * Save transaction settings
     */
    public function saveTransactionSettings(array $data): array
    {
        try {
            $existing = $this->db->fetchOne("
                SELECT id FROM payment_transaction_settings LIMIT 1
            ");

            if ($existing) {
                $this->db->query("
                    UPDATE payment_transaction_settings 
                    SET min_amount = ?,
                        max_amount = ?,
                        payment_expiry_minutes = ?,
                        pending_timeout_minutes = ?,
                        webhook_retry_limit = ?,
                        verification_retry_limit = ?,
                        reconciliation_interval_minutes = ?,
                        allow_refund = ?,
                        allow_partial_refund = ?,
                        refund_timeout_days = ?,
                        require_approval_for_refund = ?,
                        auto_void_after_days = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ", [
                    $data['min_amount'] ?? 0.00,
                    $data['max_amount'] ?? 999999.99,
                    $data['payment_expiry_minutes'] ?? 30,
                    $data['pending_timeout_minutes'] ?? 30,
                    $data['webhook_retry_limit'] ?? 3,
                    $data['verification_retry_limit'] ?? 5,
                    $data['reconciliation_interval_minutes'] ?? 15,
                    $data['allow_refund'] ?? 1,
                    $data['allow_partial_refund'] ?? 1,
                    $data['refund_timeout_days'] ?? 30,
                    $data['require_approval_for_refund'] ?? 1,
                    $data['auto_void_after_days'] ?? 7,
                    $existing['id']
                ]);
            } else {
                $this->db->query("
                    INSERT INTO payment_transaction_settings (
                        uuid, min_amount, max_amount,
                        payment_expiry_minutes, pending_timeout_minutes,
                        webhook_retry_limit, verification_retry_limit,
                        reconciliation_interval_minutes,
                        allow_refund, allow_partial_refund,
                        refund_timeout_days, require_approval_for_refund,
                        auto_void_after_days, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ", [
                    $this->generateUuid(),
                    $data['min_amount'] ?? 0.00,
                    $data['max_amount'] ?? 999999.99,
                    $data['payment_expiry_minutes'] ?? 30,
                    $data['pending_timeout_minutes'] ?? 30,
                    $data['webhook_retry_limit'] ?? 3,
                    $data['verification_retry_limit'] ?? 5,
                    $data['reconciliation_interval_minutes'] ?? 15,
                    $data['allow_refund'] ?? 1,
                    $data['allow_partial_refund'] ?? 1,
                    $data['refund_timeout_days'] ?? 30,
                    $data['require_approval_for_refund'] ?? 1,
                    $data['auto_void_after_days'] ?? 7
                ]);
            }

            return ['success' => true, 'message' => 'Transaction settings saved'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Create audit log
     */
    private function createAuditLog(int $providerId, string $action, array $details): void
    {
        $this->db->query("
            INSERT INTO payment_provider_audit_logs (
                uuid, provider_id, action, action_details, ip_address, user_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ", [
            $this->generateUuid(),
            $providerId,
            $action,
            json_encode($details),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SESSION['user_id'] ?? 1
        ]);
    }

    /**
     * Format headers for curl
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $key => $value) {
            $formatted[] = "$key: $value";
        }
        return $formatted;
    }

    /**
     * Generate UUID
     */
    private function generateUuid(): string
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
