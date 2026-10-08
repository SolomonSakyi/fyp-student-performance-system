<?php

/**
 * Payment Gateway Interface
 * All payment providers must implement this interface
 */

namespace EduTrack\Interfaces;

interface PaymentGatewayInterface
{
    /**
     * Initialize the gateway with credentials
     */
    public function initialize(array $config): void;

    /**
     * Create a payment request
     */
    public function createPayment(array $data): array;

    /**
     * Verify a payment transaction
     */
    public function verifyPayment(string $transactionId): array;

    /**
     * Get payment status
     */
    public function getPaymentStatus(string $transactionId): array;

    /**
     * Process refund
     */
    public function refundPayment(string $transactionId, float $amount, string $reason = ''): array;

    /**
     * Handle webhook request
     */
    public function handleWebhook(array $payload, array $headers): array;

    /**
     * Validate webhook signature
     */
    public function validateWebhookSignature(array $payload, string $signature): bool;

    /**
     * Get supported payment methods
     */
    public function getSupportedMethods(): array;

    /**
     * Get provider name
     */
    public function getProviderName(): string;

    /**
     * Get provider code
     */
    public function getProviderCode(): string;

    /**
     * Check if gateway is configured
     */
    public function isConfigured(): bool;
}
