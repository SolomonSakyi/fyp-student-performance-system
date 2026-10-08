<?php

/**
 * Payment Service - Core payment orchestration
 */

require_once dirname(__DIR__) . '/interfaces/PaymentGatewayInterface.php';

class PaymentService
{
    private $db;
    private $logger;
    private $gateways = [];
    private $defaultGateway;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->loadGateways();
    }

    /**
     * Load all configured payment gateways
     */
    private function loadGateways(): void
    {
        $providers = $this->db->fetchAll("
            SELECT * FROM payment_providers 
            WHERE is_active = 1 AND deleted_at IS NULL
        ");

        foreach ($providers as $provider) {
            $credentials = $this->db->fetchOne("
                SELECT * FROM payment_provider_credentials 
                WHERE provider_id = ? AND is_active = 1 AND deleted_at IS NULL
            ", [$provider['id']]);

            if ($credentials) {
                $gateway = $this->createGatewayInstance($provider, $credentials);
                if ($gateway) {
                    $this->gateways[$provider['provider_code']] = $gateway;
                    if ($provider['is_default']) {
                        $this->defaultGateway = $provider['provider_code'];
                    }
                }
            }
        }
    }

    /**
     * Create gateway instance
     */
    private function createGatewayInstance(array $provider, array $credentials): ?PaymentGatewayInterface
    {
        $className = 'EduTrack\\Gateways\\' . ucfirst($provider['provider_code']) . 'Gateway';

        if (class_exists($className)) {
            $gateway = new $className();
            $gateway->initialize([
                'api_key' => $this->decrypt($credentials['api_key']),
                'api_secret' => $this->decrypt($credentials['api_secret']),
                'encryption_key' => $this->decrypt($credentials['encryption_key']),
                'public_key' => $this->decrypt($credentials['public_key']),
                'private_key' => $this->decrypt($credentials['private_key']),
                'webhook_secret' => $this->decrypt($credentials['webhook_secret']),
                'merchant_id' => $credentials['merchant_id'],
                'environment' => $credentials['environment'],
                'base_url' => $credentials['base_url'],
                'callback_url' => $credentials['callback_url']
            ]);
            return $gateway;
        }

        return null;
    }

    /**
     * Get gateway by code
     */
    public function getGateway(string $providerCode): ?PaymentGatewayInterface
    {
        return $this->gateways[$providerCode] ?? null;
    }

    /**
     * Get default gateway
     */
    public function getDefaultGateway(): ?PaymentGatewayInterface
    {
        if ($this->defaultGateway && isset($this->gateways[$this->defaultGateway])) {
            return $this->gateways[$this->defaultGateway];
        }
        return !empty($this->gateways) ? reset($this->gateways) : null;
    }

    /**
     * Create a payment
     */
    public function createPayment(array $data): array
    {
        try {
            $this->db->beginTransaction();

            // Validate required fields
            $required = ['tenant_id', 'invoice_id', 'amount', 'currency', 'payment_method'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }

            // Check idempotency
            if (isset($data['idempotency_key'])) {
                $existing = $this->checkIdempotency($data['idempotency_key']);
                if ($existing) {
                    return ['success' => true, 'data' => $existing, 'idempotent' => true];
                }
            }

            // Get provider
            $providerCode = $data['provider_code'] ?? null;
            $gateway = $providerCode ? $this->getGateway($providerCode) : $this->getDefaultGateway();

            if (!$gateway) {
                throw new Exception('No payment gateway available');
            }

            // Get provider details
            $provider = $this->db->fetchOne("
                SELECT * FROM payment_providers 
                WHERE provider_code = ? AND is_active = 1
            ", [$gateway->getProviderCode()]);

            // Get payment method
            $method = $this->db->fetchOne("
                SELECT * FROM payment_provider_methods 
                WHERE provider_id = ? AND method_code = ? AND is_active = 1
            ", [$provider['id'], $data['payment_method']]);

            if (!$method) {
                throw new Exception('Payment method not supported by this provider');
            }

            // Generate internal reference
            $internalRef = $this->generateReference();

            // Get customer details
            $tenant = $this->db->fetchOne("SELECT * FROM tenants WHERE id = ?", [$data['tenant_id']]);
            $invoice = $this->db->fetchOne("SELECT * FROM tenant_invoices WHERE id = ?", [$data['invoice_id']]);

            // Prepare payment data
            $paymentData = [
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'reference' => $internalRef,
                'customer_name' => $tenant['tenant_name'] ?? '',
                'customer_email' => $tenant['email'] ?? '',
                'customer_phone' => $data['customer_phone'] ?? $tenant['phone'] ?? '',
                'description' => $data['description'] ?? 'Payment for invoice #' . $data['invoice_id'],
                'callback_url' => $data['callback_url'] ?? null,
                'webhook_url' => $data['webhook_url'] ?? null,
                'metadata' => [
                    'tenant_id' => $data['tenant_id'],
                    'invoice_id' => $data['invoice_id'],
                    'internal_reference' => $internalRef
                ]
            ];

            // Create payment transaction
            $transactionId = $this->createTransaction([
                'tenant_id' => $data['tenant_id'],
                'invoice_id' => $data['invoice_id'],
                'provider_id' => $provider['id'],
                'provider_method_id' => $method['id'],
                'internal_reference' => $internalRef,
                'payment_method' => $data['payment_method'],
                'currency' => $data['currency'],
                'amount' => $data['amount'],
                'customer_name' => $paymentData['customer_name'],
                'customer_phone' => $paymentData['customer_phone'],
                'customer_email' => $paymentData['customer_email'],
                'status' => 'CREATED',
                'initiated_at' => date('Y-m-d H:i:s')
            ]);

            $paymentData['transaction_id'] = $transactionId;

            // Initiate payment with gateway
            $result = $gateway->createPayment($paymentData);

            if (!$result['success']) {
                $this->updateTransactionStatus($transactionId, 'FAILED', $result['message'] ?? 'Payment initiation failed');
                throw new Exception($result['message'] ?? 'Payment initiation failed');
            }

            // Update transaction with provider details
            $this->updateTransactionWithProvider($transactionId, [
                'provider_transaction_id' => $result['provider_transaction_id'] ?? null,
                'merchant_transaction_reference' => $result['merchant_reference'] ?? null,
                'status' => 'PENDING',
                'provider_response_code' => $result['response_code'] ?? null,
                'provider_response_message' => $result['message'] ?? null,
                'provider_response_raw' => json_encode($result['raw'] ?? []),
                'processed_at' => date('Y-m-d H:i:s')
            ]);

            // Store idempotency key
            if (isset($data['idempotency_key'])) {
                $this->storeIdempotencyKey($data['idempotency_key'], $transactionId, $data);
            }

            // Create event log
            $this->createTransactionEvent($transactionId, 'CREATED', null, 'CREATED');

            $this->db->commit();

            return [
                'success' => true,
                'data' => [
                    'transaction_id' => $transactionId,
                    'internal_reference' => $internalRef,
                    'provider_transaction_id' => $result['provider_transaction_id'] ?? null,
                    'redirect_url' => $result['redirect_url'] ?? null,
                    'payment_url' => $result['payment_url'] ?? null,
                    'status' => 'PENDING'
                ]
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            $this->logger->error('Payment creation failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Verify a payment
     */
    public function verifyPayment(string $referenceOrId): array
    {
        try {
            // Find transaction
            $transaction = $this->db->fetchOne("
                SELECT * FROM payment_transactions 
                WHERE internal_reference = ? OR provider_transaction_id = ?
            ", [$referenceOrId, $referenceOrId]);

            if (!$transaction) {
                return ['success' => false, 'message' => 'Transaction not found'];
            }

            // Get gateway
            $provider = $this->db->fetchOne("SELECT * FROM payment_providers WHERE id = ?", [$transaction['provider_id']]);
            $gateway = $this->getGateway($provider['provider_code']);

            if (!$gateway) {
                return ['success' => false, 'message' => 'Payment gateway not available'];
            }

            // Verify with provider
            $result = $gateway->verifyPayment($transaction['provider_transaction_id']);

            // Update transaction based on verification
            return $this->processVerificationResult($transaction, $result);
        } catch (Exception $e) {
            $this->logger->error('Payment verification failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Process webhook
     */
    public function processWebhook(string $providerCode, array $payload, array $headers): array
    {
        try {
            // Get provider
            $provider = $this->db->fetchOne("
                SELECT * FROM payment_providers 
                WHERE provider_code = ? AND is_active = 1
            ", [$providerCode]);

            if (!$provider) {
                return ['success' => false, 'message' => 'Provider not found'];
            }

            // Get gateway
            $gateway = $this->getGateway($providerCode);
            if (!$gateway) {
                return ['success' => false, 'message' => 'Gateway not available'];
            }

            // Validate webhook signature
            $signature = $headers['x-hubtel-signature'] ?? $headers['x-paystack-signature'] ??
                $headers['x-flutterwave-signature'] ?? $headers['signature'] ?? '';

            if (!$gateway->validateWebhookSignature($payload, $signature)) {
                $this->logWebhook($provider['id'], $payload, $headers, false);
                return ['success' => false, 'message' => 'Invalid webhook signature'];
            }

            // Store raw webhook
            $webhookId = $this->storeWebhook($provider['id'], $payload, $headers);

            // Check for duplicate webhook
            $duplicate = $this->isDuplicateWebhook($provider['id'], $payload);
            if ($duplicate) {
                return ['success' => true, 'message' => 'Duplicate webhook ignored', 'duplicate' => true];
            }

            // Process webhook
            $result = $gateway->handleWebhook($payload, $headers);

            if (!$result['success']) {
                $this->updateWebhookStatus($webhookId, false);
                return ['success' => false, 'message' => $result['message'] ?? 'Webhook processing failed'];
            }

            // Find or create transaction
            $transaction = $this->findTransactionByWebhook($result['data']);

            if (!$transaction) {
                // Create new transaction if not found
                $transaction = $this->createTransactionFromWebhook($result['data']);
            }

            // Process verification
            $verificationResult = $this->processVerificationResult($transaction, $result);

            // Update webhook status
            $this->updateWebhookStatus($webhookId, true, $transaction['id']);

            // Create event log
            $this->createTransactionEvent(
                $transaction['id'],
                'WEBHOOK_RECEIVED',
                $transaction['status'],
                $verificationResult['data']['status'] ?? $transaction['status']
            );

            return array_merge(['webhook_id' => $webhookId], $verificationResult);
        } catch (Exception $e) {
            $this->logger->error('Webhook processing failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Process verification result
     */
    private function processVerificationResult(array $transaction, array $result): array
    {
        if (!$result['success']) {
            $this->updateTransactionStatus($transaction['id'], 'FAILED', $result['message'] ?? 'Verification failed');
            return ['success' => false, 'message' => $result['message'] ?? 'Verification failed'];
        }

        $data = $result['data'];
        $newStatus = $data['status'] ?? 'FAILED';

        // Update transaction
        $this->updateTransaction($transaction['id'], [
            'status' => $newStatus,
            'verification_status' => 'VERIFIED',
            'provider_response_code' => $data['response_code'] ?? null,
            'provider_response_message' => $data['message'] ?? null,
            'provider_response_raw' => json_encode($data['raw'] ?? []),
            'completed_at' => in_array($newStatus, ['SUCCESS', 'FAILED', 'CANCELLED']) ? date('Y-m-d H:i:s') : null
        ]);

        // Create event log
        $this->createTransactionEvent(
            $transaction['id'],
            'VERIFIED',
            $transaction['status'],
            $newStatus
        );

        // If payment is successful, update invoice and subscription
        if ($newStatus === 'SUCCESS') {
            $this->handleSuccessfulPayment($transaction['id']);
        }

        // If payment failed, handle failure
        if (in_array($newStatus, ['FAILED', 'CANCELLED', 'EXPIRED'])) {
            $this->handleFailedPayment($transaction['id']);
        }

        return [
            'success' => true,
            'data' => [
                'transaction_id' => $transaction['id'],
                'status' => $newStatus,
                'provider_transaction_id' => $transaction['provider_transaction_id']
            ]
        ];
    }

    /**
     * Handle successful payment
     */
    private function handleSuccessfulPayment(int $transactionId): void
    {
        $transaction = $this->db->fetchOne("SELECT * FROM payment_transactions WHERE id = ?", [$transactionId]);

        if (!$transaction) return;

        // Update invoice
        if ($transaction['invoice_id']) {
            $this->db->query("
                UPDATE tenant_invoices 
                SET payment_status = 'PAID', 
                    payment_transaction_id = ?,
                    updated_at = NOW()
                WHERE id = ?
            ", [$transactionId, $transaction['invoice_id']]);
        }

        // Update subscription
        if ($transaction['subscription_id']) {
            $subscription = $this->db->fetchOne("SELECT * FROM tenant_subscriptions WHERE id = ?", [$transaction['subscription_id']]);

            if ($subscription) {
                // Calculate new dates based on billing cycle
                $plan = $this->db->fetchOne("SELECT billing_cycle FROM subscription_plans WHERE id = ?", [$subscription['plan_id']]);
                $gracePeriod = 7; // Default grace period

                $newEndDate = null;
                switch ($plan['billing_cycle'] ?? 'monthly') {
                    case 'monthly':
                        $newEndDate = date('Y-m-d H:i:s', strtotime('+1 month'));
                        break;
                    case 'quarterly':
                        $newEndDate = date('Y-m-d H:i:s', strtotime('+3 months'));
                        break;
                    case 'semiannual':
                        $newEndDate = date('Y-m-d H:i:s', strtotime('+6 months'));
                        break;
                    case 'annual':
                        $newEndDate = date('Y-m-d H:i:s', strtotime('+1 year'));
                        break;
                    case 'lifetime':
                        $newEndDate = null;
                        break;
                    default:
                        $newEndDate = date('Y-m-d H:i:s', strtotime('+1 month'));
                }

                $this->db->query("
                    UPDATE tenant_subscriptions 
                    SET status = 'ACTIVE',
                        payment_status = 'ACTIVE',
                        last_payment_id = ?,
                        start_date = NOW(),
                        end_date = ?,
                        grace_period_days = ?,
                        grace_period_ends_at = DATE_ADD(NOW(), INTERVAL ? DAY),
                        updated_at = NOW()
                    WHERE id = ?
                ", [$transactionId, $newEndDate, $gracePeriod, $gracePeriod, $transaction['subscription_id']]);

                // Update tenant license
                $this->updateTenantLicense($transaction['tenant_id'], $newEndDate);
            }
        }

        // Update tenant
        if ($transaction['tenant_id']) {
            $this->db->query("
                UPDATE tenants 
                SET status = 'active',
                    license_status = 'ACTIVE',
                    updated_at = NOW()
                WHERE id = ?
            ", [$transaction['tenant_id']]);
        }

        // Create audit log
        $this->createAuditLog('payment_success', 'Payment completed successfully', $transactionId);
    }

    /**
     * Handle failed payment
     */
    private function handleFailedPayment(int $transactionId): void
    {
        $transaction = $this->db->fetchOne("SELECT * FROM payment_transactions WHERE id = ?", [$transactionId]);

        if (!$transaction) return;

        // Update invoice
        if ($transaction['invoice_id']) {
            $this->db->query("
                UPDATE tenant_invoices 
                SET payment_status = 'UNPAID',
                    updated_at = NOW()
                WHERE id = ?
            ", [$transaction['invoice_id']]);
        }

        // Update subscription
        if ($transaction['subscription_id']) {
            $this->db->query("
                UPDATE tenant_subscriptions 
                SET payment_status = 'PAST_DUE',
                    updated_at = NOW()
                WHERE id = ?
            ", [$transaction['subscription_id']]);
        }

        // Create audit log
        $this->createAuditLog('payment_failed', 'Payment failed: ' . ($transaction['provider_response_message'] ?? 'Unknown error'), $transactionId);
    }

    /**
     * Update tenant license
     */
    private function updateTenantLicense(int $tenantId, ?string $expiryDate): void
    {
        $licenseKey = $this->generateLicenseKey($tenantId);

        $this->db->query("
            UPDATE tenants 
            SET license_status = 'ACTIVE',
                license_expires_at = ?,
                license_key = ?,
                updated_at = NOW()
            WHERE id = ?
        ", [$expiryDate, $licenseKey, $tenantId]);
    }

    /**
     * Generate license key
     */
    private function generateLicenseKey(int $tenantId): string
    {
        return 'ET-' . strtoupper(substr(md5($tenantId . time() . uniqid()), 0, 16));
    }

    /**
     * Create transaction
     */
    private function createTransaction(array $data): int
    {
        $uuid = $this->generateUuid();

        $sql = "INSERT INTO payment_transactions (
            uuid, tenant_id, invoice_id, subscription_id, provider_id, provider_method_id,
            internal_reference, payment_method, currency, amount, 
            customer_name, customer_phone, customer_email,
            status, initiated_at, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $this->db->query($sql, [
            $uuid,
            $data['tenant_id'] ?? null,
            $data['invoice_id'] ?? null,
            $data['subscription_id'] ?? null,
            $data['provider_id'] ?? null,
            $data['provider_method_id'] ?? null,
            $data['internal_reference'],
            $data['payment_method'] ?? null,
            $data['currency'] ?? 'GHS',
            $data['amount'],
            $data['customer_name'] ?? null,
            $data['customer_phone'] ?? null,
            $data['customer_email'] ?? null,
            $data['status'] ?? 'CREATED',
            $data['initiated_at'] ?? date('Y-m-d H:i:s'),
            $data['created_by'] ?? 1
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Update transaction status
     */
    private function updateTransactionStatus(int $transactionId, string $status, ?string $message = null): void
    {
        $this->db->query("
            UPDATE payment_transactions 
            SET status = ?,
                provider_response_message = ?,
                failed_at = CASE WHEN ? IN ('FAILED', 'CANCELLED', 'EXPIRED') THEN NOW() ELSE failed_at END,
                updated_at = NOW()
            WHERE id = ?
        ", [$status, $message, $status, $transactionId]);
    }

    /**
     * Update transaction with provider details
     */
    private function updateTransactionWithProvider(int $transactionId, array $data): void
    {
        $fields = [];
        $params = [];

        foreach ($data as $key => $value) {
            if ($key !== 'id') {
                $fields[] = "$key = ?";
                $params[] = $value;
            }
        }

        if (!empty($fields)) {
            $params[] = $transactionId;
            $this->db->query("
                UPDATE payment_transactions 
                SET " . implode(', ', $fields) . ", updated_at = NOW()
                WHERE id = ?
            ", $params);
        }
    }

    /**
     * Update transaction
     */
    private function updateTransaction(int $transactionId, array $data): void
    {
        $fields = [];
        $params = [];

        foreach ($data as $key => $value) {
            $fields[] = "$key = ?";
            $params[] = $value;
        }

        if (!empty($fields)) {
            $params[] = $transactionId;
            $this->db->query("
                UPDATE payment_transactions 
                SET " . implode(', ', $fields) . ", updated_at = NOW()
                WHERE id = ?
            ", $params);
        }
    }

    /**
     * Create transaction event
     */
    private function createTransactionEvent(int $transactionId, string $eventType, ?string $fromStatus, ?string $toStatus): void
    {
        $this->db->query("
            INSERT INTO payment_transaction_events (
                transaction_id, event_type, from_status, to_status, created_at
            ) VALUES (?, ?, ?, ?, NOW())
        ", [$transactionId, $eventType, $fromStatus, $toStatus]);
    }

    /**
     * Generate reference
     */
    private function generateReference(): string
    {
        return 'ET-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 12));
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

    /**
     * Check idempotency
     */
    private function checkIdempotency(string $key): ?array
    {
        $result = $this->db->fetchOne("
            SELECT t.* FROM payment_idempotency_keys ik
            JOIN payment_transactions t ON ik.transaction_id = t.id
            WHERE ik.idempotency_key = ? AND ik.expires_at > NOW()
        ", [$key]);

        return $result;
    }

    /**
     * Store idempotency key
     */
    private function storeIdempotencyKey(string $key, int $transactionId, array $data): void
    {
        $this->db->query("
            INSERT INTO payment_idempotency_keys (
                idempotency_key, transaction_id, resource_type, resource_id, 
                payload_hash, expires_at, created_at
            ) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())
        ", [
            $key,
            $transactionId,
            $data['resource_type'] ?? 'payment',
            $data['resource_id'] ?? null,
            md5(json_encode($data))
        ]);
    }

    /**
     * Store webhook
     */
    private function storeWebhook(int $providerId, array $payload, array $headers): int
    {
        $uuid = $this->generateUuid();

        $this->db->query("
            INSERT INTO payment_webhooks (
                uuid, provider_id, webhook_id, event_type, payload, headers, signature, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ", [
            $uuid,
            $providerId,
            $payload['event_id'] ?? $payload['id'] ?? null,
            $payload['event_type'] ?? $payload['type'] ?? null,
            json_encode($payload),
            json_encode($headers),
            $headers['x-hubtel-signature'] ?? $headers['x-paystack-signature'] ??
                $headers['x-flutterwave-signature'] ?? null
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Update webhook status
     */
    private function updateWebhookStatus(int $webhookId, bool $processed, ?int $transactionId = null): void
    {
        $this->db->query("
            UPDATE payment_webhooks 
            SET is_processed = ?,
                is_valid = ?,
                processed_at = NOW()
            WHERE id = ?
        ", [$processed ? 1 : 0, $processed ? 1 : 0, $webhookId]);
    }

    /**
     * Log webhook
     */
    private function logWebhook(int $providerId, array $payload, array $headers, bool $isValid): void
    {
        $this->db->query("
            INSERT INTO payment_webhooks (
                provider_id, payload, headers, is_valid, created_at
            ) VALUES (?, ?, ?, ?, NOW())
        ", [$providerId, json_encode($payload), json_encode($headers), $isValid ? 1 : 0]);
    }

    /**
     * Check duplicate webhook
     */
    private function isDuplicateWebhook(int $providerId, array $payload): bool
    {
        $webhookId = $payload['event_id'] ?? $payload['id'] ?? null;
        if (!$webhookId) return false;

        $result = $this->db->fetchOne("
            SELECT id FROM payment_webhooks 
            WHERE provider_id = ? AND webhook_id = ?
        ", [$providerId, $webhookId]);

        return $result !== false;
    }

    /**
     * Find transaction by webhook
     */
    private function findTransactionByWebhook(array $data): ?array
    {
        $reference = $data['reference'] ?? $data['transaction_reference'] ??
            $data['internal_reference'] ?? null;

        if ($reference) {
            return $this->db->fetchOne("
                SELECT * FROM payment_transactions 
                WHERE internal_reference = ? OR provider_transaction_id = ?
            ", [$reference, $reference]);
        }

        return null;
    }

    /**
     * Create transaction from webhook
     */
    private function createTransactionFromWebhook(array $data): ?array
    {
        // Implementation depends on webhook data structure
        return null;
    }

    /**
     * Create audit log
     */
    private function createAuditLog(string $action, string $description, ?int $transactionId = null): void
    {
        $this->db->query("
            INSERT INTO platform_audit_logs (
                action_type, module, resource, resource_id, description, created_at
            ) VALUES (?, 'payment', 'payment_transaction', ?, ?, NOW())
        ", [$action, $transactionId, $description]);
    }

    /**
     * Simple encryption/decryption (replace with proper encryption in production)
     */
    private function encrypt(string $value): string
    {
        return base64_encode($value);
    }

    private function decrypt(string $value): string
    {
        return base64_decode($value);
    }
}
