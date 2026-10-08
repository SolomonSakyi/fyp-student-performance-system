<?php

/**
 * EncryptionService.php
 * Handles encryption/decryption of sensitive data using AES-256-CBC
 * 
 * @package EduTrack
 * @subpackage Services\Security
 * @version 2.0
 * 
 * @filepath app/services/Security/EncryptionService.php
 */

class EncryptionService
{
    /**
     * Singleton instance
     * @var self|null
     */
    private static $instance = null;

    /**
     * Encryption key
     * @var string
     */
    private $key;

    /**
     * Cipher method
     * @var string
     */
    private $cipher = 'AES-256-CBC';

    /**
     * Hash algorithm for key derivation
     * @var string
     */
    private $hashAlgo = 'sha256';

    /**
     * Private constructor (singleton)
     */
    private function __construct()
    {
        $this->key = $this->getEncryptionKey();
    }

    /**
     * Get singleton instance
     * 
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ============================================================
    // ENCRYPTION / DECRYPTION
    // ============================================================

    /**
     * Encrypt data
     * 
     * @param string $data Data to encrypt
     * @return string Encrypted data (base64 encoded)
     */
    public function encrypt(string $data): string
    {
        if (empty($data)) {
            return '';
        }

        try {
            // Generate random IV
            $ivLength = openssl_cipher_iv_length($this->cipher);
            $iv = openssl_random_pseudo_bytes($ivLength);

            // Encrypt
            $encrypted = openssl_encrypt(
                $data,
                $this->cipher,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($encrypted === false) {
                throw new RuntimeException('Encryption failed');
            }

            // Combine IV + encrypted data and base64 encode
            $combined = $iv . $encrypted;
            return base64_encode($combined);
        } catch (Exception $e) {
            error_log('EncryptionService::encrypt error: ' . $e->getMessage());
            throw new RuntimeException('Encryption failed: ' . $e->getMessage());
        }
    }

    /**
     * Decrypt data
     * 
     * @param string $encryptedData Encrypted data (base64 encoded)
     * @return string Decrypted data
     */
    public function decrypt(string $encryptedData): string
    {
        if (empty($encryptedData)) {
            return '';
        }

        try {
            // Decode from base64
            $decoded = base64_decode($encryptedData, true);

            if ($decoded === false) {
                throw new RuntimeException('Invalid base64 encoding');
            }

            // Extract IV
            $ivLength = openssl_cipher_iv_length($this->cipher);
            $iv = substr($decoded, 0, $ivLength);
            $encrypted = substr($decoded, $ivLength);

            // Decrypt
            $decrypted = openssl_decrypt(
                $encrypted,
                $this->cipher,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($decrypted === false) {
                throw new RuntimeException('Decryption failed');
            }

            return $decrypted;
        } catch (Exception $e) {
            error_log('EncryptionService::decrypt error: ' . $e->getMessage());
            throw new RuntimeException('Decryption failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if data appears to be encrypted
     * 
     * @param string $data Data to check
     * @return bool True if data appears encrypted
     */
    public function isEncrypted(string $data): bool
    {
        if (empty($data)) {
            return false;
        }

        try {
            $decoded = base64_decode($data, true);
            if ($decoded === false) {
                return false;
            }
            $ivLength = openssl_cipher_iv_length($this->cipher);
            return strlen($decoded) > $ivLength;
        } catch (Exception $e) {
            return false;
        }
    }

    // ============================================================
    // KEY MANAGEMENT
    // ============================================================

    /**
     * Get encryption key from secure source
     * 
     * @return string Encryption key
     */
    private function getEncryptionKey(): string
    {
        // Check environment variable first
        $key = getenv('ENCRYPTION_KEY');
        if ($key && $this->isValidKey($key)) {
            return $key;
        }

        // Check config file
        $configFile = dirname(__DIR__, 3) . '/config/security.php';
        if (file_exists($configFile)) {
            $config = include $configFile;
            if (isset($config['encryption_key']) && $this->isValidKey($config['encryption_key'])) {
                return $config['encryption_key'];
            }
        }

        // Check .env file
        $envFile = dirname(__DIR__, 3) . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, 'ENCRYPTION_KEY=') === 0) {
                    $key = substr($line, strlen('ENCRYPTION_KEY='));
                    if ($this->isValidKey($key)) {
                        return $key;
                    }
                }
            }
        }

        // Generate a key (for development only)
        // In production, ENCRYPTION_KEY environment variable MUST be set
        $generatedKey = $this->generateKey();
        error_log('WARNING: Using generated encryption key. Set ENCRYPTION_KEY in environment for production.');
        return $generatedKey;
    }

    /**
     * Validate encryption key
     * 
     * @param string $key Key to validate
     * @return bool True if key is valid
     */
    private function isValidKey(string $key): bool
    {
        // AES-256 requires 32 bytes (256 bits)
        return strlen($key) >= 32;
    }

    /**
     * Generate a random encryption key
     * 
     * @return string Random key
     */
    private function generateKey(): string
    {
        return bin2hex(random_bytes(32)); // 64 hex characters = 32 bytes
    }

    // ============================================================
    // UTILITY METHODS
    // ============================================================

    /**
     * Get encryption status
     * 
     * @return array Status information
     */
    public function getStatus(): array
    {
        $keySource = 'unknown';
        if (getenv('ENCRYPTION_KEY')) {
            $keySource = 'environment';
        } elseif (file_exists(dirname(__DIR__, 3) . '/config/security.php')) {
            $keySource = 'config';
        } elseif (file_exists(dirname(__DIR__, 3) . '/.env')) {
            $keySource = 'env_file';
        } else {
            $keySource = 'generated';
        }

        return [
            'cipher' => $this->cipher,
            'key_exists' => !empty($this->key),
            'key_source' => $keySource,
            'is_production' => getenv('APP_ENV') === 'production',
            'key_length' => strlen($this->key) . ' bytes'
        ];
    }

    /**
     * Test encryption/decryption
     * 
     * @param string $testData Test data
     * @return bool True if test passes
     */
    public function testEncryption(string $testData = 'test-data-123'): bool
    {
        try {
            $encrypted = $this->encrypt($testData);
            $decrypted = $this->decrypt($encrypted);
            return $decrypted === $testData;
        } catch (Exception $e) {
            error_log('EncryptionService::testEncryption failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Re-encrypt data with new key
     * 
     * @param string $encryptedData Currently encrypted data
     * @param string $oldKey Old encryption key
     * @param string $newKey New encryption key
     * @return string Re-encrypted data
     */
    public function reEncrypt(string $encryptedData, string $oldKey, string $newKey): string
    {
        $oldService = new self();
        $oldService->key = $oldKey;

        $decrypted = $oldService->decrypt($encryptedData);
        $this->key = $newKey;
        return $this->encrypt($decrypted);
    }
}

// ============================================================
// COMPATIBILITY FUNCTIONS (for backward compatibility)
// ============================================================

/**
 * Legacy encryption function (deprecated)
 * 
 * @deprecated Use EncryptionService::getInstance()->encrypt() instead
 */
function encrypt_data($data)
{
    error_log('Deprecated: encrypt_data() called. Use EncryptionService instead.');
    return EncryptionService::getInstance()->encrypt($data);
}

/**
 * Legacy decryption function (deprecated)
 * 
 * @deprecated Use EncryptionService::getInstance()->decrypt() instead
 */
function decrypt_data($data)
{
    error_log('Deprecated: decrypt_data() called. Use EncryptionService instead.');
    return EncryptionService::getInstance()->decrypt($data);
}
