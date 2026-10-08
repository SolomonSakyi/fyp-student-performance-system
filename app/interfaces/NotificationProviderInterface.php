<?php

/**
 * Notification Provider Interface
 * All notification providers must implement this interface
 */

namespace EduTrack\Interfaces;

interface NotificationProviderInterface
{
    /**
     * Initialize the provider with credentials
     */
    public function initialize(array $config): void;

    /**
     * Send a notification
     * @param array $data - Contains: to, subject, body, html, recipient_name, reference
     * @return array - Contains: success, message_id, message, raw
     */
    public function send(array $data): array;

    /**
     * Get delivery status
     */
    public function getStatus(string $messageId): array;

    /**
     * Handle webhook/callback
     */
    public function handleWebhook(array $payload, array $headers): array;

    /**
     * Validate webhook signature
     */
    public function validateWebhookSignature(array $payload, string $signature): bool;

    /**
     * Get provider name
     */
    public function getProviderName(): string;

    /**
     * Get provider code
     */
    public function getProviderCode(): string;

    /**
     * Get supported channels
     */
    public function getSupportedChannels(): array;

    /**
     * Check if provider is configured
     */
    public function isConfigured(): bool;

    /**
     * Check account balance
     */
    public function getBalance(): array;

    /**
     * Get rate limit info
     */
    public function getRateLimits(): array;
}
