<?php
/**
 * RateLimitMiddleware.php
 *
 * Rate Limiting Middleware
 * Prevents API abuse by limiting request frequency
 *
 * @package EduTrack
 * @subpackage Middleware
 * @version 1.0
 */

class RateLimitMiddleware
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
     * @var int Maximum requests per window
     */
    private $maxRequests = 100;

    /**
     * @var int Time window in seconds
     */
    private $windowSeconds = 60;

    /**
     * @var string Rate limit key
     */
    private $key;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->key = $this->generateKey();
    }

    /**
     * Handle rate limiting
     */
    public function handle(): bool
    {
        try {
            $count = $this->getRequestCount();

            if ($count >= $this->maxRequests) {
                $this->logger->warning('Rate limit exceeded', [
                    'key' => $this->key,
                    'count' => $count,
                    'max' => $this->maxRequests
                ]);
                return false;
            }

            $this->incrementRequestCount();

            return true;
        } catch (Exception $e) {
            $this->logger->error('Rate limit error: ' . $e->getMessage());
            return true; // Allow on error
        }
    }

    /**
     * Generate rate limit key
     */
    private function generateKey(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $userId = $_SESSION['user_id'] ?? 'guest';
        return md5("rate_limit_{$ip}_{$userId}");
    }

    /**
     * Get request count
     */
    private function getRequestCount(): int
    {
        try {
            $sql = "SELECT COUNT(*) as count FROM rate_limits 
                    WHERE `key` = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)";
            $result = $this->db->fetchOne($sql, [$this->key, $this->windowSeconds]);
            return (int)($result['count'] ?? 0);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Increment request count
     */
    private function incrementRequestCount(): void
    {
        try {
            $sql = "INSERT INTO rate_limits (uuid, `key`, ip_address, user_agent, created_at) 
                    VALUES (?, ?, ?, ?, NOW())";
            $this->db->query($sql, [
                $this->generateUuid(),
                $this->key,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            // Ignore insertion errors
        }
    }

    /**
     * Set max requests
     */
    public function setMaxRequests(int $max): self
    {
        $this->maxRequests = $max;
        return $this;
    }

    /**
     * Set window seconds
     */
    public function setWindowSeconds(int $seconds): self
    {
        $this->windowSeconds = $seconds;
        return $this;
    }

    /**
     * Generate UUID
     */
    private function generateUuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}