<?php

/**
 * Encryption Helper - Secure credential encryption
 */

class EncryptionHelper
{
    private $key;
    private $cipher = 'AES-256-CBC';

    public function __construct()
    {
        // Use environment variable or config for encryption key
        $this->key = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : $this->generateKey();
    }

    /**
     * Encrypt a value
     */
    public function encrypt(string $value): string
    {
        if (empty($value)) {
            return '';
        }

        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($this->cipher));
        $encrypted = openssl_encrypt($value, $this->cipher, $this->key, 0, $iv);

        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a value
     */
    public function decrypt(string $encrypted): string
    {
        if (empty($encrypted)) {
            return '';
        }

        $data = base64_decode($encrypted);
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = substr($data, 0, $ivLength);
        $encryptedData = substr($data, $ivLength);

        return openssl_decrypt($encryptedData, $this->cipher, $this->key, 0, $iv);
    }

    /**
     * Generate encryption key
     */
    private function generateKey(): string
    {
        // In production, this should be set in environment
        return base64_encode(openssl_random_pseudo_bytes(32));
    }

    /**
     * Mask sensitive data for display
     */
    public function mask(string $value, int $visible = 4): string
    {
        if (empty($value)) {
            return '';
        }

        $length = strlen($value);
        if ($length <= $visible * 2) {
            return str_repeat('•', $length);
        }

        $start = substr($value, 0, $visible);
        $end = substr($value, -$visible);
        $masked = str_repeat('•', $length - ($visible * 2));

        return $start . $masked . $end;
    }
}
