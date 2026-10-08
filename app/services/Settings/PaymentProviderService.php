<?php

/**
 * PaymentProviderService.php
 * Service for managing payment providers with tenant isolation
 *
 * @package EduTrack
 * @subpackage Services\Settings
 * @version 2.1
 *
 * @filepath app/services/Settings/PaymentProviderService.php
 *
 * v2.1 change (2026-10-05) [ITEM-5]:
 *   The api_key and api_secret decrypt calls in getProvider() and
 *   getProviderByCode() are now wrapped in per-field try/catch. A
 *   failing decrypt leaves the corresponding _decrypted field null and
 *   logs the failure; the provider row is still returned. Prior
 *   behaviour: any single decryption failure propagated to the
 *   method-level catch, which returned null and blanked the whole
 *   provider record.
 *
 *   Nothing else changed.
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';
require_once $projectRoot . 'app/services/Security/EncryptionService.php';

class PaymentProviderService
{
    private $db;
    private $logger;
    private $encryption;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->encryption = EncryptionService::getInstance();
    }

    // ============================================================
    // PROVIDER CRUD OPERATIONS
    // ============================================================

    /**
     * Get all providers for a tenant
     */
    public function getProviders(?int $schoolId = null): array
    {
        try {
            $tenantId = $this->getTenantId();
            if (!$tenantId) {
                return ['success' => false, 'message' => 'No tenant context available'];
            }

            $sql = "SELECT id, provider_code, provider_name, provider_type, status, environment, 
                           description, is_default, created_at, updated_at,
                           SUBSTRING(api_key, 1, 10) as api_key_preview
                    FROM payment_providers 
                    WHERE tenant_id = ? AND deleted_at IS NULL 
                    ORDER BY is_default DESC, provider_name ASC";

            $providers = $this->db->fetchAll($sql, [$tenantId]);

            // Mask sensitive data
            foreach ($providers as &$provider) {
                unset($provider['api_key']);
                unset($provider['api_secret']);
                if (!isset($provider['api_key_preview'])) {
                    $provider['api_key_preview'] = '***';
                }
                // Parse additional_config if exists
                if (isset($provider['additional_config']) && is_string($provider['additional_config'])) {
                    $provider['additional_config'] = json_decode($provider['additional_config'], true);
                }
            }

            return [
                'success' => true,
                'data' => $providers
            ];
        } catch (Exception $e) {
            $this->logger->error('getProviders error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get a single provider by ID
     *
     * [v2.1 ITEM-5] The api_key and api_secret decrypts are now wrapped
     * in per-field try/catch. A failing decrypt leaves the corresponding
     * _decrypted field null and logs the failure; the provider row is
     * still returned.
     */
    public function getProvider(int $providerId): ?array
    {
        try {
            $tenantId = $this->getTenantId();
            if (!$tenantId) {
                return null;
            }

            $sql = "SELECT * FROM payment_providers 
                    WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL";
            $provider = $this->db->fetchOne($sql, [$providerId, $tenantId]);

            if ($provider) {
                // Decrypt sensitive data for display.
                // [v2.1 ITEM-5] Per-field try/catch.
                if (isset($provider['api_key'])) {
                    try {
                        $provider['api_key_decrypted'] = $this->encryption->decrypt($provider['api_key']);
                    } catch (Exception $e) {
                        $this->logger->error(
                            'PaymentProvider api_key decrypt failed for provider ' . $providerId . ': ' . $e->getMessage()
                        );
                        $provider['api_key_decrypted'] = null;
                    }
                }
                if (isset($provider['api_secret'])) {
                    try {
                        $provider['api_secret_decrypted'] = $this->encryption->decrypt($provider['api_secret']);
                    } catch (Exception $e) {
                        $this->logger->error(
                            'PaymentProvider api_secret decrypt failed for provider ' . $providerId . ': ' . $e->getMessage()
                        );
                        $provider['api_secret_decrypted'] = null;
                    }
                }

                // Parse additional_config
                if (isset($provider['additional_config']) && is_string($provider['additional_config'])) {
                    $provider['additional_config'] = json_decode($provider['additional_config'], true);
                }
            }

            return $provider;
        } catch (Exception $e) {
            $this->logger->error('getProvider error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get provider by code
     *
     * [v2.1 ITEM-5] Same per-field try/catch as getProvider().
     */
    public function getProviderByCode(string $code): ?array
    {
        try {
            $tenantId = $this->getTenantId();
            if (!$tenantId) {
                return null;
            }

            $sql = "SELECT * FROM payment_providers 
                    WHERE provider_code = ? AND tenant_id = ? AND deleted_at IS NULL";
            $provider = $this->db->fetchOne($sql, [$code, $tenantId]);

            if ($provider) {
                // Decrypt sensitive data for display.
                // [v2.1 ITEM-5] Per-field try/catch.
                if (isset($provider['api_key'])) {
                    try {
                        $provider['api_key_decrypted'] = $this->encryption->decrypt($provider['api_key']);
                    } catch (Exception $e) {
                        $this->logger->error(
                            'PaymentProvider api_key decrypt failed for code "' . $code . '": ' . $e->getMessage()
                        );
                        $provider['api_key_decrypted'] = null;
                    }
                }
                if (isset($provider['api_secret'])) {
                    try {
                        $provider['api_secret_decrypted'] = $this->encryption->decrypt($provider['api_secret']);
                    } catch (Exception $e) {
                        $this->logger->error(
                            'PaymentProvider api_secret decrypt failed for code "' . $code . '": ' . $e->getMessage()
                        );
                        $provider['api_secret_decrypted'] = null;
                    }
                }

                // Parse additional_config
                if (isset($provider['additional_config']) && is_string($provider['additional_config'])) {
                    $provider['additional_config'] = json_decode($provider['additional_config'], true);
                }
            }

            return $provider;
        } catch (Exception $e) {
            $this->logger->error('getProviderByCode error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Save provider (create or update)
     */
    public function saveProvider(array $data, ?int $providerId = null, ?int $tenantId = null, ?int $userId = null): int
    {
        try {
            if (!$tenantId) {
                $tenantId = $this->getTenantId();
            }
            if (!$tenantId) {
                throw new Exception('No tenant context available');
            }

            $encryptedKey = null;
            $encryptedSecret = null;

            // Encrypt sensitive data if provided
            if (!empty($data['api_key'])) {
                $encryptedKey = $this->encryption->encrypt($data['api_key']);
            }
            if (!empty($data['api_secret'])) {
                $encryptedSecret = $this->encryption->encrypt($data['api_secret']);
            }

            // Prepare additional_config
            $additionalConfig = null;
            if (isset($data['additional_config']) && is_array($data['additional_config'])) {
                $additionalConfig = json_encode($data['additional_config']);
            } elseif (isset($data['additional_config']) && is_string($data['additional_config'])) {
                $additionalConfig = $data['additional_config'];
            }

            if ($providerId) {
                // UPDATE
                $updates = [];
                $params = [];

                if (isset($data['provider_name'])) {
                    $updates[] = "provider_name = ?";
                    $params[] = $data['provider_name'];
                }
                if (isset($data['provider_type'])) {
                    $updates[] = "provider_type = ?";
                    $params[] = $data['provider_type'];
                }
                if (isset($data['environment'])) {
                    $updates[] = "environment = ?";
                    $params[] = $data['environment'];
                }
                if (isset($data['status'])) {
                    $updates[] = "status = ?";
                    $params[] = $data['status'];
                }
                if (isset($data['description'])) {
                    $updates[] = "description = ?";
                    $params[] = $data['description'];
                }
                if ($encryptedKey) {
                    $updates[] = "api_key = ?";
                    $params[] = $encryptedKey;
                }
                if ($encryptedSecret) {
                    $updates[] = "api_secret = ?";
                    $params[] = $encryptedSecret;
                }
                if ($additionalConfig) {
                    $updates[] = "additional_config = ?";
                    $params[] = $additionalConfig;
                }
                if (isset($data['is_default'])) {
                    $updates[] = "is_default = ?";
                    $params[] = (int)$data['is_default'];
                }

                if (empty($updates)) {
                    throw new Exception('No fields to update');
                }

                $updates[] = "updated_at = NOW()";
                $params[] = $providerId;
                $params[] = $tenantId;

                $sql = "UPDATE payment_providers SET " . implode(', ', $updates) . " 
                        WHERE id = ? AND tenant_id = ?";
                $this->db->execute($sql, $params);

                // If this is set as default, clear others
                if (isset($data['is_default']) && $data['is_default']) {
                    $this->db->execute(
                        "UPDATE payment_providers SET is_default = 0 
                         WHERE id != ? AND tenant_id = ?",
                        [$providerId, $tenantId]
                    );
                }

                $this->logAudit($tenantId, $userId, 'UPDATE', 'payment_provider', $providerId, [
                    'provider_name' => $data['provider_name'] ?? 'Unknown'
                ]);

                return $providerId;
            } else {
                // INSERT
                $sql = "INSERT INTO payment_providers 
                        (tenant_id, provider_code, provider_name, provider_type, 
                         api_key, api_secret, environment, status, description, 
                         additional_config, is_default, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

                $params = [
                    $tenantId,
                    $data['provider_code'],
                    $data['provider_name'],
                    $data['provider_type'],
                    $encryptedKey,
                    $encryptedSecret,
                    $data['environment'] ?? 'sandbox',
                    $data['status'] ?? 'pending',
                    $data['description'] ?? null,
                    $additionalConfig,
                    isset($data['is_default']) ? (int)$data['is_default'] : 0
                ];

                $this->db->execute($sql, $params);
                $newId = $this->db->lastInsertId();

                // If this is set as default, clear others
                if (isset($data['is_default']) && $data['is_default']) {
                    $this->db->execute(
                        "UPDATE payment_providers SET is_default = 0 
                         WHERE id != ? AND tenant_id = ?",
                        [$newId, $tenantId]
                    );
                }

                $this->logAudit($tenantId, $userId, 'CREATE', 'payment_provider', $newId, [
                    'provider_name' => $data['provider_name']
                ]);

                return $newId;
            }
        } catch (Exception $e) {
            $this->logger->error('saveProvider error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete provider (soft delete)
     */
    public function deleteProvider(int $providerId, ?int $tenantId = null): bool
    {
        try {
            if (!$tenantId) {
                $tenantId = $this->getTenantId();
            }
            if (!$tenantId) {
                return false;
            }

            $sql = "UPDATE payment_providers SET deleted_at = NOW() 
                    WHERE id = ? AND tenant_id = ?";
            $result = $this->db->execute($sql, [$providerId, $tenantId]);

            return $result > 0;
        } catch (Exception $e) {
            $this->logger->error('deleteProvider error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Test provider connection
     */
    public function testProvider(int $providerId, ?int $tenantId = null): array
    {
        try {
            if (!$tenantId) {
                $tenantId = $this->getTenantId();
            }
            if (!$tenantId) {
                return ['success' => false, 'message' => 'No tenant context available'];
            }

            $provider = $this->getProvider($providerId);
            if (!$provider) {
                return ['success' => false, 'message' => 'Provider not found'];
            }

            // Decrypt credentials
            $apiKey = $this->encryption->decrypt($provider['api_key']);
            $apiSecret = $this->encryption->decrypt($provider['api_secret']);

            // Simulate connection test based on provider type
            $providerType = $provider['provider_type'] ?? 'custom';

            // In production, you would make actual API calls here
            $testResult = $this->simulateConnectionTest($providerType, $apiKey, $apiSecret);

            // Log the test
            $this->logAudit($tenantId, null, 'TEST', 'payment_provider', $providerId, [
                'provider_name' => $provider['provider_name'],
                'test_result' => $testResult['success'] ? 'success' : 'failed'
            ]);

            return $testResult;
        } catch (Exception $e) {
            $this->logger->error('testProvider error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ============================================================
    // HUBTEL SPECIFIC METHODS
    // ============================================================

    /**
     * Save Hubtel configuration
     */
    public function saveHubtelConfig(array $data, int $tenantId, int $userId): bool
    {
        try {
            // Check if Hubtel provider exists
            $existing = $this->getProviderByCode('hubtel');

            $providerData = [
                'provider_code' => 'hubtel',
                'provider_name' => 'Hubtel',
                'provider_type' => 'mobile_money',
                'api_key' => $data['client_id'],
                'api_secret' => $data['client_secret'],
                'environment' => $data['environment'] ?? 'sandbox',
                'status' => $data['status'] ?? 'active',
                'description' => 'Hubtel payment gateway for mobile money and card payments',
                'additional_config' => [
                    'methods' => [
                        'momo' => $data['methods']['momo'] ?? true,
                        'card' => $data['methods']['card'] ?? true,
                        'bank' => $data['methods']['bank'] ?? false,
                        'qr' => $data['methods']['qr'] ?? false
                    ],
                    'networks' => [
                        'mtn' => $data['networks']['mtn'] ?? true,
                        'vodafone' => $data['networks']['vodafone'] ?? true,
                        'tigo' => $data['networks']['tigo'] ?? true,
                        'airtel' => $data['networks']['airtel'] ?? false
                    ]
                ]
            ];

            $providerId = $existing ? $existing['id'] : null;
            $this->saveProvider($providerData, $providerId, $tenantId, $userId);

            return true;
        } catch (Exception $e) {
            $this->logger->error('saveHubtelConfig error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Test Hubtel connection
     */
    public function testHubtelConnection(array $data): array
    {
        try {
            $clientId = $data['client_id'] ?? '';
            $clientSecret = $data['client_secret'] ?? '';
            $environment = $data['environment'] ?? 'sandbox';

            if (empty($clientId) || empty($clientSecret)) {
                return [
                    'success' => false,
                    'message' => 'Client ID and Client Secret are required'
                ];
            }

            // Simulate Hubtel API test
            $mockResponse = [
                'success' => true,
                'message' => 'Hubtel connection test successful',
                'environment' => $environment,
                'response_time' => rand(100, 300) . 'ms',
                'api_version' => 'v1',
                'client_id' => substr($clientId, 0, 8) . '****'
            ];

            // Log the test
            $this->logAudit($this->getTenantId(), null, 'TEST', 'hubtel', null, [
                'environment' => $environment,
                'success' => true
            ]);

            return $mockResponse;
        } catch (Exception $e) {
            $this->logger->error('testHubtelConnection error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // ============================================================
    // BANK SPECIFIC METHODS
    // ============================================================

    /**
     * Save Bank configuration
     */
    public function saveBankConfig(array $data, int $tenantId, int $userId): bool
    {
        try {
            // Check if Bank provider exists
            $existing = $this->getProviderByCode('bank');

            $providerData = [
                'provider_code' => 'bank',
                'provider_name' => 'Bank API',
                'provider_type' => 'bank_transfer',
                'api_key' => $data['api_key'],
                'api_secret' => $data['api_secret'],
                'environment' => 'production',
                'status' => $data['status'] ?? 'pending',
                'description' => 'Bank API integration for direct bank transfers',
                'additional_config' => [
                    'bank_name' => $data['bank_name'] ?? 'ecobank',
                    'api_version' => $data['api_version'] ?? 'v2',
                    'base_url' => $data['base_url'] ?? '',
                    'account_number' => $data['account_number'] ?? '',
                    'account_name' => $data['account_name'] ?? '',
                    'branch_code' => $data['branch_code'] ?? '',
                    'currency' => $data['currency'] ?? 'GHS',
                    'transaction_types' => [
                        'transfer' => $data['transaction_types']['transfer'] ?? true,
                        'direct_debit' => $data['transaction_types']['direct_debit'] ?? false,
                        'cheque' => $data['transaction_types']['cheque'] ?? false,
                        'wire' => $data['transaction_types']['wire'] ?? false
                    ]
                ]
            ];

            $providerId = $existing ? $existing['id'] : null;
            $this->saveProvider($providerData, $providerId, $tenantId, $userId);

            return true;
        } catch (Exception $e) {
            $this->logger->error('saveBankConfig error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Test Bank connection
     */
    public function testBankConnection(array $data): array
    {
        try {
            $apiKey = $data['api_key'] ?? '';
            $apiSecret = $data['api_secret'] ?? '';
            $testAccount = $data['test_account'] ?? '';

            if (empty($apiKey) || empty($apiSecret)) {
                return [
                    'success' => false,
                    'message' => 'API Key and API Secret are required'
                ];
            }

            // Simulate Bank API test
            $mockResponse = [
                'success' => true,
                'message' => 'Bank API connection test successful',
                'response_time' => rand(100, 300) . 'ms',
                'api_version' => 'v2',
                'test_account' => $testAccount ?: 'N/A',
                'status' => 'connected'
            ];

            // Log the test
            $this->logAudit($this->getTenantId(), null, 'TEST', 'bank', null, [
                'test_account' => $testAccount,
                'success' => true
            ]);

            return $mockResponse;
        } catch (Exception $e) {
            $this->logger->error('testBankConnection error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // ============================================================
    // WEBHOOK MANAGEMENT
    // ============================================================

    /**
     * Get webhooks
     */
    public function getWebhooks(?int $tenantId = null): array
    {
        try {
            if (!$tenantId) {
                $tenantId = $this->getTenantId();
            }
            if (!$tenantId) {
                return [];
            }

            $sql = "SELECT w.*, p.provider_name 
                    FROM payment_webhooks w
                    LEFT JOIN payment_providers p ON w.provider_id = p.id
                    WHERE w.tenant_id = ? AND w.deleted_at IS NULL 
                    ORDER BY w.created_at DESC";

            return $this->db->fetchAll($sql, [$tenantId]);
        } catch (Exception $e) {
            $this->logger->error('getWebhooks error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Create webhook
     */
    public function createWebhook(array $data, int $tenantId, int $userId): bool
    {
        try {
            $sql = "INSERT INTO payment_webhooks 
                    (tenant_id, event_name, webhook_url, provider_id, secret_key, 
                     status, retry_count, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

            $this->db->execute($sql, [
                $tenantId,
                $data['event_name'],
                $data['webhook_url'],
                $data['provider_id'],
                $data['secret_key'] ?? null,
                $data['status'] ?? 'active',
                $data['retry_count'] ?? 3
            ]);

            $this->logAudit($tenantId, $userId, 'CREATE', 'webhook', $this->db->lastInsertId(), [
                'event_name' => $data['event_name']
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('createWebhook error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update webhook
     */
    public function updateWebhook(array $data, int $tenantId, int $userId): bool
    {
        try {
            $updates = [];
            $params = [];

            if (isset($data['event_name'])) {
                $updates[] = "event_name = ?";
                $params[] = $data['event_name'];
            }
            if (isset($data['webhook_url'])) {
                $updates[] = "webhook_url = ?";
                $params[] = $data['webhook_url'];
            }
            if (isset($data['provider_id'])) {
                $updates[] = "provider_id = ?";
                $params[] = $data['provider_id'];
            }
            if (isset($data['secret_key'])) {
                $updates[] = "secret_key = ?";
                $params[] = $data['secret_key'];
            }
            if (isset($data['status'])) {
                $updates[] = "status = ?";
                $params[] = $data['status'];
            }
            if (isset($data['retry_count'])) {
                $updates[] = "retry_count = ?";
                $params[] = $data['retry_count'];
            }

            if (empty($updates)) {
                return false;
            }

            $updates[] = "updated_at = NOW()";
            $params[] = $data['id'];
            $params[] = $tenantId;

            $sql = "UPDATE payment_webhooks SET " . implode(', ', $updates) . " 
                    WHERE id = ? AND tenant_id = ?";
            $this->db->execute($sql, $params);

            $this->logAudit($tenantId, $userId, 'UPDATE', 'webhook', $data['id'], [
                'event_name' => $data['event_name'] ?? 'Unknown'
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('updateWebhook error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete webhook
     */
    public function deleteWebhook(int $webhookId, int $tenantId): bool
    {
        try {
            $sql = "UPDATE payment_webhooks SET deleted_at = NOW() 
                    WHERE id = ? AND tenant_id = ?";
            return $this->db->execute($sql, [$webhookId, $tenantId]) > 0;
        } catch (Exception $e) {
            $this->logger->error('deleteWebhook error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Test webhook
     */
    public function testWebhook(int $webhookId, int $tenantId): array
    {
        try {
            $sql = "SELECT * FROM payment_webhooks WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL";
            $webhook = $this->db->fetchOne($sql, [$webhookId, $tenantId]);

            if (!$webhook) {
                return ['success' => false, 'error' => 'Webhook not found'];
            }

            // Simulate webhook test
            $payload = [
                'test' => true,
                'event' => $webhook['event_name'],
                'timestamp' => date('Y-m-d H:i:s'),
                'data' => [
                    'id' => 'test_' . uniqid(),
                    'status' => 'success',
                    'amount' => 10.00,
                    'currency' => 'GHS'
                ]
            ];

            $mockResponse = [
                'success' => true,
                'message' => 'Webhook test sent successfully',
                'payload' => $payload,
                'webhook_url' => $webhook['webhook_url']
            ];

            // Log the test
            $this->logAudit($tenantId, null, 'TEST', 'webhook', $webhookId, [
                'event_name' => $webhook['event_name'],
                'success' => true
            ]);

            return $mockResponse;
        } catch (Exception $e) {
            $this->logger->error('testWebhook error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ============================================================
    // HELPER METHODS
    // ============================================================

    private function getTenantId(): ?int
    {
        try {
            $context = TenantContext::getInstance();
            return $context->getTenantId();
        } catch (Exception $e) {
            return $_SESSION['tenant_id'] ?? null;
        }
    }

    private function simulateConnectionTest(string $type, string $apiKey, string $apiSecret): array
    {
        $types = ['mobile_money', 'card', 'bank_transfer', 'multi_currency', 'custom'];

        if (in_array($type, $types) && !empty($apiKey) && !empty($apiSecret)) {
            return [
                'success' => true,
                'message' => 'Connection test successful for ' . $type,
                'provider_type' => $type,
                'response_time' => rand(100, 300) . 'ms'
            ];
        }

        return [
            'success' => false,
            'message' => 'Invalid credentials or provider type'
        ];
    }

    private function logAudit(?int $tenantId, ?int $userId, string $action, string $resource, $resourceId, ?array $details = null): void
    {
        try {
            if (!$tenantId) {
                $tenantId = $this->getTenantId();
            }
            if (!$tenantId) {
                return;
            }

            $sql = "INSERT INTO payment_audit_logs 
                    (tenant_id, user_id, action_type, resource_type, resource_id, details, ip_address, user_agent) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

            $this->db->execute($sql, [
                $tenantId,
                $userId,
                $action,
                $resource,
                $resourceId ? (string)$resourceId : null,
                $details ? json_encode($details) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            // Silently fail for audit logging
        }
    }
}
