<?php
// ============================================================
// Security configuration
// File: config/security.php
// ============================================================
// The encryption key must be exactly 64 hex characters
// (32 bytes) as required by EncryptionService::isValidKey().
// It must never change. If it changes, every value that was
// encrypted with the old key becomes unrecoverable.
//
// Keep this file out of any public web path. It is at
// /config/security.php, which the .htaccess blocks for
// direct web access.
// ============================================================

return [
    'encryption_key' => 'PASTE_YOUR_64_CHARACTER_HEX_KEY_HERE'
];
