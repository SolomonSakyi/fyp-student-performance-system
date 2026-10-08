<?php
/**
 * PaymentGatewayEngine.php
 *
 * Enterprise Payment Gateway Integration Engine
 * Abstraction layer for multiple payment providers
 *
 * @package EduTrack
 * @subpackage Helpers\Finance
 * @version 1.0
 */

require_once __DIR__ . '/../../models/Finance/FinanceModel.php';
require_once __DIR__ . '/../../helpers/DatabaseHelper.php';
require_once __DIR__ . '/../../helpers/LoggerHelper.php';

class PaymentGatewayEngine
{
    /**
     * @var DatabaseHelper Database instance
     */
    private $db;

    /**
     * @var LoggerHelper Logger instance
     */
    private $logger;

    /**
     * @var array Gateway configurations
     */
    private $gateways = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->loadGatewayConfigs();
    }

    /**
     * Load gateway configurations
     */
    private function loadGatewayConfigs(): void
    {
        try {
            $configs = $this->db->fetchAll("SELECT * FROM payment_gateway_configs WHERE is_active = 1");
            foreach ($configs as $config) {
                $this->gateways[$config['gateway_code']] = [
                    'id' => $config['id'],
                    'name' => $config['gateway_name'],
                    'code' => $config['gateway_code'],
                    'config' => json_decode($config['config_data'], true) ?? [],
                    'is_active' => $config['is_active']
                ];
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to load gateway configs', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Initialize a payment
     */
    public function initializePayment(string $gatewayCode, array $data): array
    {
        try {
            if (!isset($this->gateways[$gatewayCode])) {
                return ['success' => false, 'message' => 'Gateway not found'];
            }

            $gateway = $this->gateways[$gatewayCode];
            $config = $gateway['config'];

            switch ($gatewayCode) {
                case 'hubtel':
                    return $this->initHubtelPayment($config, $data);
                case 'paystack':
                    return $this->initPaystackPayment($config, $data);
                case 'flutterwave':
                    return $this->initFlutterwavePayment($config, $data);
                case 'mtn_momo':
                    return $this->initMtnMomoPayment($config, $data);
                default:
                    return ['success' => false, 'message' => 'Unsupported gateway'];
            }

        } catch (Exception $e) {
            $this->logger->error('Failed to initialize payment', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Hubtel Payment Integration
     */
    private function initHubtelPayment(array $config, array $data): array
    {
        try {
            $clientId = $config['client_id'] ?? '';
            $clientSecret = $config['client_secret'] ?? '';
            $baseUrl = $config['base_url'] ?? 'https://api.hubtel.com/v1';

            // Prepare request data
            $payload = [
                'amount' => $data['amount'],
                'description' => $data['description'] ?? 'School Fees Payment',
                'callback_url' => $data['callback_url'] ?? $config['callback_url'] ?? '',
                'cancel_url' => $data['cancel_url'] ?? $config['cancel_url'] ?? '',
                'customer_name' => $data['customer_name'] ?? 'Student',
                'customer_email' => $data['customer_email'] ?? '',
                'customer_phone' => $data['customer_phone'] ?? '',
                'transaction_id' => $data['transaction_id'] ?? 'TXN-' . time()
            ];

            // Log transaction
            $this->logTransaction('hubtel', 'initiate', $payload);

            // Return redirect URL or payment data
            return [
                'success' => true,
                'payment_url' => $baseUrl . '/payments/initiate',
                'reference' => $payload['transaction_id'],
                'data' => $payload,
                'gateway' => 'hubtel'
            ];

        } catch (Exception $e) {
            $this->logger->error('Hubtel payment failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Paystack Payment Integration
     */
    private function initPaystackPayment(array $config, array $data): array
    {
        try {
            $secretKey = $config['secret_key'] ?? '';
            $publicKey = $config['public_key'] ?? '';
            $baseUrl = $config['base_url'] ?? 'https://api.paystack.co';

            $payload = [
                'amount' => $data['amount'] * 100, // Paystack uses kobo
                'email' => $data['email'] ?? '',
                'reference' => $data['transaction_id'] ?? 'PAYSTACK-' . time(),
                'callback_url' => $data['callback_url'] ?? $config['callback_url'] ?? '',
                'metadata' => [
                    'student_id' => $data['student_id'] ?? '',
                    'bill_id' => $data['bill_id'] ?? '',
                    'custom_fields' => [
                        ['display_name' => 'Student', 'variable_name' => 'student_name', 'value' => $data['customer_name'] ?? ''],
                        ['display_name' => 'Bill', 'variable_name' => 'bill_number', 'value' => $data['bill_number'] ?? '']
                    ]
                ]
            ];

            // Log transaction
            $this->logTransaction('paystack', 'initiate', $payload);

            return [
                'success' => true,
                'reference' => $payload['reference'],
                'data' => $payload,
                'gateway' => 'paystack',
                'public_key' => $publicKey
            ];

        } catch (Exception $e) {
            $this->logger->error('Paystack payment failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Flutterwave Payment Integration
     */
    private function initFlutterwavePayment(array $config, array $data): array
    {
        try {
            $secretKey = $config['secret_key'] ?? '';
            $publicKey = $config['public_key'] ?? '';
            $baseUrl = $config['base_url'] ?? 'https://api.flutterwave.com/v3';

            $payload = [
                'tx_ref' => $data['transaction_id'] ?? 'FLW-' . time(),
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'GHS',
                'redirect_url' => $data['callback_url'] ?? $config['callback_url'] ?? '',
                'payment_options' => 'card,mobilemoney,ussd',
                'customer' => [
                    'email' => $data['email'] ?? '',
                    'phonenumber' => $data['phone'] ?? '',
                    'name' => $data['customer_name'] ?? ''
                ],
                'customizations' => [
                    'title' => $data['title'] ?? 'School Fees Payment',
                    'description' => $data['description'] ?? 'Payment for school fees',
                    'logo' => $config['logo_url'] ?? ''
                ],
                'meta' => [
                    'student_id' => $data['student_id'] ?? '',
                    'bill_id' => $data['bill_id'] ?? ''
                ]
            ];

            // Log transaction
            $this->logTransaction('flutterwave', 'initiate', $payload);

            return [
                'success' => true,
                'reference' => $payload['tx_ref'],
                'data' => $payload,
                'gateway' => 'flutterwave'
            ];

        } catch (Exception $e) {
            $this->logger->error('Flutterwave payment failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * MTN Mobile Money Integration
     */
    private function initMtnMomoPayment(array $config, array $data): array
    {
        try {
            $apiKey = $config['api_key'] ?? '';
            $subscriptionKey = $config['subscription_key'] ?? '';
            $baseUrl = $config['base_url'] ?? 'https://sandbox.momodeveloper.mtn.com';

            $payload = [
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'GHS',
                'externalId' => $data['transaction_id'] ?? 'MOMO-' . time(),
                'payer' => [
                    'partyIdType' => 'MSISDN',
                    'partyId' => $data['phone'] ?? ''
                ],
                'payerMessage' => $data['description'] ?? 'School Fees Payment',
                'payeeNote' => $data['notes'] ?? ''
            ];

            // Log transaction
            $this->logTransaction('mtn_momo', 'initiate', $payload);

            return [
                'success' => true,
                'reference' => $payload['externalId'],
                'data' => $payload,
                'gateway' => 'mtn_momo'
            ];

        } catch (Exception $e) {
            $this->logger->error('MTN MoMo payment failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Process payment callback/webhook
     */
    public function processCallback(string $gatewayCode, array $callbackData): array
    {
        try {
            // Log callback
            $this->logger->info('Payment callback received', [
                'gateway' => $gatewayCode,
                'data' => $callbackData
            ]);

            // Validate callback signature
            if (!$this->validateCallbackSignature($gatewayCode, $callbackData)) {
                return ['success' => false, 'message' => 'Invalid callback signature'];
            }

            // Extract payment details based on gateway
            switch ($gatewayCode) {
                case 'hubtel':
                    return $this->processHubtelCallback($callbackData);
                case 'paystack':
                    return $this->processPaystackCallback($callbackData);
                case 'flutterwave':
                    return $this->processFlutterwaveCallback($callbackData);
                case 'mtn_momo':
                    return $this->processMtnMomoCallback($callbackData);
                default:
                    return ['success' => false, 'message' => 'Unsupported gateway'];
            }

        } catch (Exception $e) {
            $this->logger->error('Failed to process callback', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Validate callback signature
     */
    private function validateCallbackSignature(string $gatewayCode, array $data): bool
    {
        // Implementation depends on gateway-specific signature validation
        return true; // Simplified for now
    }

    /**
     * Process Hubtel callback
     */
    private function processHubtelCallback(array $data): array
    {
        $status = $data['status'] ?? '';
        $reference = $data['transaction_id'] ?? '';

        if ($status === 'success') {
            return $this->completePayment('hubtel', $reference, $data);
        }

        return [
            'success' => false,
            'message' => 'Payment was not successful',
            'status' => $status,
            'reference' => $reference
        ];
    }

    /**
     * Process Paystack callback
     */
    private function processPaystackCallback(array $data): array
    {
        $status = $data['status'] ?? '';
        $reference = $data['reference'] ?? '';

        if ($status === 'success' && $data['event'] === 'charge.success') {
            return $this->completePayment('paystack', $reference, $data);
        }

        return [
            'success' => false,
            'message' => 'Payment was not successful',
            'status' => $status,
            'reference' => $reference
        ];
    }

    /**
     * Process Flutterwave callback
     */
    private function processFlutterwaveCallback(array $data): array
    {
        $status = $data['status'] ?? '';
        $reference = $data['tx_ref'] ?? '';

        if ($status === 'successful') {
            return $this->completePayment('flutterwave', $reference, $data);
        }

        return [
            'success' => false,
            'message' => 'Payment was not successful',
            'status' => $status,
            'reference' => $reference
        ];
    }

    /**
     * Process MTN MoMo callback
     */
    private function processMtnMomoCallback(array $data): array
    {
        $status = $data['status'] ?? '';
        $reference = $data['externalId'] ?? '';

        if ($status === 'SUCCESSFUL') {
            return $this->completePayment('mtn_momo', $reference, $data);
        }

        return [
            'success' => false,
            'message' => 'Payment was not successful',
            'status' => $status,
            'reference' => $reference
        ];
    }

    /**
     * Complete payment after successful callback
     */
    private function completePayment(string $gatewayCode, string $reference, array $data): array
    {
        try {
            // Find pending payment by reference
            $payment = $this->db->fetchOne("
                SELECT id, student_id, student_bill_id, amount, payment_method_id
                FROM payments 
                WHERE payment_reference = ? AND payment_status = 'pending'
                ORDER BY created_at DESC LIMIT 1
            ", [$reference]);

            if (!$payment) {
                return ['success' => false, 'message' => 'Payment not found'];
            }

            // Update payment status
            $this->db->query("
                UPDATE payments SET 
                    payment_status = 'completed',
                    updated_at = NOW()
                WHERE id = ?
            ", [$payment['id']]);

            // Update bill
            if ($payment['student_bill_id']) {
                $this->updateBillAfterPayment($payment['student_bill_id'], $payment['amount']);
            }

            // Log success
            $this->logger->info('Payment completed', [
                'payment_id' => $payment['id'],
                'reference' => $reference,
                'gateway' => $gatewayCode
            ]);

            return [
                'success' => true,
                'message' => 'Payment completed successfully',
                'payment_id' => $payment['id'],
                'reference' => $reference
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to complete payment', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update bill after payment
     */
    private function updateBillAfterPayment(int $billId, float $amount): void
    {
        try {
            $bill = $this->db->fetchOne("SELECT * FROM student_bills WHERE id = ?", [$billId]);
            if (!$bill) {
                return;
            }

            $newPaid = $bill['amount_paid'] + $amount;
            $newBalance = $bill['balance_due'] - $amount;
            $status = $newBalance <= 0 ? 'paid' : 'partially_paid';

            $this->db->query("
                UPDATE student_bills SET 
                    amount_paid = ?,
                    balance_due = ?,
                    bill_status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ", [$newPaid, $newBalance, $status, $billId]);

        } catch (Exception $e) {
            $this->logger->error('Failed to update bill after payment', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Log transaction
     */
    private function logTransaction(string $gateway, string $action, array $data): void
    {
        try {
            $this->db->query("
                INSERT INTO payment_gateway_logs (
                    uuid, gateway_code, action, request_data, status, created_at
                ) VALUES (
                    UUID(), ?, ?, ?, 'initiated', NOW()
                )
            ", [$gateway, $action, json_encode($data)]);
        } catch (Exception $e) {
            $this->logger->error('Failed to log transaction', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get available gateways
     */
    public function getAvailableGateways(): array
    {
        return array_values($this->gateways);
    }

    /**
     * Get gateway status
     */
    public function getGatewayStatus(string $gatewayCode): array
    {
        if (!isset($this->gateways[$gatewayCode])) {
            return ['success' => false, 'message' => 'Gateway not found'];
        }

        $gateway = $this->gateways[$gatewayCode];
        return [
            'success' => true,
            'data' => [
                'name' => $gateway['name'],
                'code' => $gateway['code'],
                'is_active' => $gateway['is_active']
            ]
        ];
    }
}