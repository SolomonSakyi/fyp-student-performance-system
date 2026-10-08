<?php

/**
 * Notification Provider Manager - Manages all providers
 */

class NotificationProviderManager
{
    private $db;
    private $logger;
    private $providers = [];
    private $channelProviders = [];

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->loadProviders();
    }

    /**
     * Load all providers from database
     */
    private function loadProviders(): void
    {
        $providers = $this->db->fetchAll("
            SELECT p.*, c.channel_code 
            FROM notification_providers p
            JOIN notification_channels c ON p.channel_id = c.id
            WHERE p.is_active = 1 AND p.deleted_at IS NULL
            ORDER BY p.priority ASC
        ");

        foreach ($providers as $provider) {
            $gateway = $this->createProviderInstance($provider);
            if ($gateway && $gateway->isConfigured()) {
                $this->providers[$provider['id']] = [
                    'provider' => $provider,
                    'gateway' => $gateway
                ];

                if (!isset($this->channelProviders[$provider['channel_code']])) {
                    $this->channelProviders[$provider['channel_code']] = [];
                }
                $this->channelProviders[$provider['channel_code']][] = $provider['id'];
            }
        }
    }

    /**
     * Create provider instance
     */
    private function createProviderInstance(array $provider): ?NotificationProviderInterface
    {
        $config = json_decode($provider['config'] ?? '{}', true);
        $config['provider_id'] = $provider['id'];
        $config['provider_code'] = $provider['provider_code'];

        $className = 'EduTrack\\Providers\\' . ucfirst($provider['provider_code']) . 'Provider';

        if (class_exists($className)) {
            $gateway = new $className();
            $gateway->initialize($config);
            return $gateway;
        }

        return null;
    }

    /**
     * Get provider by ID
     */
    public function getProvider(int $providerId): ?array
    {
        return $this->providers[$providerId] ?? null;
    }

    /**
     * Get provider by code
     */
    public function getProviderByCode(string $code): ?array
    {
        foreach ($this->providers as $provider) {
            if ($provider['provider']['provider_code'] === $code) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * Get providers for channel
     */
    public function getProvidersForChannel(string $channelCode): array
    {
        $result = [];
        if (isset($this->channelProviders[$channelCode])) {
            foreach ($this->channelProviders[$channelCode] as $providerId) {
                if (isset($this->providers[$providerId])) {
                    $result[] = $this->providers[$providerId];
                }
            }
        }
        return $result;
    }

    /**
     * Get default provider for channel
     */
    public function getDefaultProviderForChannel(string $channelCode): ?array
    {
        $providers = $this->getProvidersForChannel($channelCode);
        foreach ($providers as $provider) {
            if ($provider['provider']['is_default']) {
                return $provider;
            }
        }
        return $providers[0] ?? null;
    }

    /**
     * Send via provider
     */
    public function send(int $providerId, array $data): array
    {
        $provider = $this->getProvider($providerId);
        if (!$provider) {
            return ['success' => false, 'message' => 'Provider not found'];
        }

        // Check rate limits
        $rateCheck = $this->checkRateLimit($providerId);
        if (!$rateCheck['allowed']) {
            return ['success' => false, 'message' => 'Rate limit exceeded: ' . $rateCheck['message']];
        }

        try {
            $result = $provider['gateway']->send($data);

            // Record usage
            if ($result['success']) {
                $this->recordUsage($providerId, $data);
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('Provider send failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Check rate limit
     */
    private function checkRateLimit(int $providerId): array
    {
        // Check if provider has rate limits
        $provider = $this->getProvider($providerId);
        if (!$provider) {
            return ['allowed' => false, 'message' => 'Provider not found'];
        }

        $limits = $provider['provider'];
        $rateLimitPerMinute = $limits['rate_limit_per_minute'] ?? 60;
        $rateLimitPerHour = $limits['rate_limit_per_hour'] ?? 3600;
        $rateLimitPerDay = $limits['rate_limit_per_day'] ?? 10000;

        // Check usage in last minute
        $minuteUsage = $this->db->fetchOne("
            SELECT COUNT(*) as count FROM notification_deliveries 
            WHERE provider_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ", [$providerId]);

        if (($minuteUsage['count'] ?? 0) >= $rateLimitPerMinute) {
            return ['allowed' => false, 'message' => 'Per-minute rate limit exceeded'];
        }

        // Check usage in last hour
        $hourUsage = $this->db->fetchOne("
            SELECT COUNT(*) as count FROM notification_deliveries 
            WHERE provider_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ", [$providerId]);

        if (($hourUsage['count'] ?? 0) >= $rateLimitPerHour) {
            return ['allowed' => false, 'message' => 'Per-hour rate limit exceeded'];
        }

        // Check usage today
        $dayUsage = $this->db->fetchOne("
            SELECT COUNT(*) as count FROM notification_deliveries 
            WHERE provider_id = ? AND DATE(created_at) = CURDATE()
        ", [$providerId]);

        if (($dayUsage['count'] ?? 0) >= $rateLimitPerDay) {
            return ['allowed' => false, 'message' => 'Per-day rate limit exceeded'];
        }

        return ['allowed' => true];
    }

    /**
     * Record usage
     */
    private function recordUsage(int $providerId, array $data): void
    {
        // Update usage statistics
        $channel = $this->db->fetchOne("
            SELECT c.id FROM notification_channels c
            JOIN notification_providers p ON c.id = p.channel_id
            WHERE p.id = ?
        ", [$providerId]);

        if ($channel) {
            $this->db->query("
                INSERT INTO notification_usage (date, channel_id, provider_id, total_sent, created_at)
                VALUES (CURDATE(), ?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE total_sent = total_sent + 1
            ", [$channel['id'], $providerId]);
        }
    }

    /**
     * Validate provider configuration
     */
    public function validateProvider(int $providerId): array
    {
        $provider = $this->getProvider($providerId);
        if (!$provider) {
            return ['success' => false, 'message' => 'Provider not found'];
        }

        try {
            $balance = $provider['gateway']->getBalance();
            return ['success' => true, 'data' => $balance];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get all providers with status
     */
    public function getAllProviders(): array
    {
        $result = [];
        foreach ($this->providers as $provider) {
            $result[] = [
                'provider' => $provider['provider'],
                'is_configured' => $provider['gateway']->isConfigured(),
                'supported_channels' => $provider['gateway']->getSupportedChannels()
            ];
        }
        return $result;
    }
}
