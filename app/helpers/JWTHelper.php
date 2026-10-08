```php
<?php

/**
 * JwtHelper.php
 * JWT Authentication Helper
 * 
 * @package EduTrack
 * @subpackage Helpers
 * @version 1.0
 */

class JwtHelper
{
    private $secret;
    private $algorithm;
    private $expiry;

    public function __construct()
    {
        // In production, load from environment or config
        $this->secret = 'edutrack_enterprise_secret_key_2026';
        $this->algorithm = 'HS256';
        $this->expiry = 3600 * 24 * 7; // 7 days
    }

    /**
     * Generate JWT token
     */
    public function generateToken(array $payload): string
    {
        $header = $this->base64UrlEncode(json_encode([
            'typ' => 'JWT',
            'alg' => $this->algorithm
        ]));

        // Add standard claims
        $payload['iat'] = time();
        $payload['exp'] = time() + $this->expiry;

        $payload = $this->base64UrlEncode(json_encode($payload));
        $signature = $this->base64UrlEncode($this->sign($header . '.' . $payload));

        return $header . '.' . $payload . '.' . $signature;
    }

    /**
     * Validate JWT token
     */
    public function validateToken(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        list($header, $payload, $signature) = $parts;

        // Verify signature
        $expectedSignature = $this->base64UrlEncode($this->sign($header . '.' . $payload));
        if ($signature !== $expectedSignature) {
            return null;
        }

        // Decode payload
        $data = json_decode($this->base64UrlDecode($payload), true);

        if (!$data) {
            return null;
        }

        // Check expiration
        if (isset($data['exp']) && $data['exp'] < time()) {
            return null;
        }

        return $data;
    }

    /**
     * Sign the token
     */
    private function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->secret, true);
    }

    /**
     * Base64 URL encode
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL decode
     */
    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Refresh token
     */
    public function refreshToken(string $token): ?string
    {
        $payload = $this->validateToken($token);
        if (!$payload) {
            return null;
        }

        // Remove exp and iat before regenerating
        unset($payload['exp']);
        unset($payload['iat']);

        return $this->generateToken($payload);
    }

    /**
     * Get user ID from token
     */
    public function getUserId(string $token): ?int
    {
        $payload = $this->validateToken($token);
        if (!$payload) {
            return null;
        }
        return $payload['user_id'] ?? null;
    }

    /**
     * Get tenant ID from token
     */
    public function getTenantId(string $token): ?int
    {
        $payload = $this->validateToken($token);
        if (!$payload) {
            return null;
        }
        return $payload['tenant_id'] ?? null;
    }
}
