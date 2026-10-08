<?php

/**
 * JWTService.php
 * JWT token generation and validation service
 * 
 * @package EduTrack
 * @subpackage Services\Auth
 * @version 2.0
 * @filepath app/services/Auth/JWTService.php
 */

class JWTService
{
    private string $secret;
    private string $algo;
    private int $expiry;

    public function __construct()
    {
        // Use a secure secret from environment or generate one
        $this->secret = $_ENV['JWT_SECRET'] ?? 'edutrack_super_secret_key_change_me_12345';
        $this->algo = 'HS256';
        $this->expiry = (int)($_ENV['JWT_EXPIRY'] ?? 86400); // 24 hours default
    }

    /**
     * Generate a JWT token
     */
    public function generateToken(array $payload): string
    {
        $header = $this->base64UrlEncode(json_encode([
            'typ' => 'JWT',
            'alg' => $this->algo
        ]));

        $payload['iat'] = time();
        $payload['exp'] = time() + $this->expiry;
        $payload['iss'] = 'edutrack-platform';

        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        $signature = $this->signature($header . '.' . $payloadEncoded);

        return $header . '.' . $payloadEncoded . '.' . $signature;
    }

    /**
     * Validate and decode a JWT token
     */
    public function validateToken(string $token): ?array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            list($headerEncoded, $payloadEncoded, $signature) = $parts;

            // Verify signature
            $expectedSignature = $this->signature($headerEncoded . '.' . $payloadEncoded);
            if (!hash_equals($expectedSignature, $signature)) {
                return null;
            }

            $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);

            if (!$payload) {
                return null;
            }

            // Check expiration
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                return null;
            }

            return [
                'success' => true,
                'data' => $payload
            ];
        } catch (Exception $e) {
            error_log('JWT validation error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get user ID from token
     */
    public function getUserIdFromToken(string $token): ?int
    {
        $result = $this->validateToken($token);
        if ($result && isset($result['data']['user_id'])) {
            return (int)$result['data']['user_id'];
        }
        return null;
    }

    /**
     * Get tenant ID from token
     */
    public function getTenantIdFromToken(string $token): ?int
    {
        $result = $this->validateToken($token);
        if ($result && isset($result['data']['tenant_id'])) {
            return (int)$result['data']['tenant_id'];
        }
        return null;
    }

    /**
     * Get roles from token
     */
    public function getRolesFromToken(string $token): array
    {
        $result = $this->validateToken($token);
        if ($result && isset($result['data']['roles'])) {
            return (array)$result['data']['roles'];
        }
        return [];
    }

    /**
     * Refresh token (extend expiration)
     */
    public function refreshToken(string $token): ?string
    {
        $result = $this->validateToken($token);
        if (!$result) {
            return null;
        }

        $payload = $result['data'];
        unset($payload['iat']);
        unset($payload['exp']);

        return $this->generateToken($payload);
    }

    /**
     * Generate signature
     */
    private function signature(string $data): string
    {
        return $this->base64UrlEncode(
            hash_hmac('sha256', $data, $this->secret, true)
        );
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
}
