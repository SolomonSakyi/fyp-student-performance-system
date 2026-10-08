<?php

/**
 * PasswordService - Password management, validation, and security
 * @package EduTrack
 * @filepath app/services/PasswordService.php
 */

class PasswordService
{
    private static $config = null;
    private static $db = null;

    /**
     * Get database instance
     */
    private static function getDb()
    {
        if (self::$db === null) {
            $projectRoot = dirname(__DIR__, 2);
            require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
            self::$db = DatabaseHelper::getInstance();
        }
        return self::$db;
    }

    /**
     * Get configuration
     */
    private static function getConfig()
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $projectRoot = dirname(__DIR__, 2);
        $configPath = $projectRoot . '/config/auth.php';

        if (file_exists($configPath)) {
            self::$config = include $configPath;
        } else {
            self::$config = [
                'password' => [
                    'min_length' => 8,
                    'require_uppercase' => true,
                    'require_lowercase' => true,
                    'require_number' => true,
                    'require_special' => true,
                    'history_count' => 5,
                    'expiry_days' => 90,
                    'algorithm' => PASSWORD_BCRYPT,
                    'cost' => 12,
                ]
            ];
        }

        return self::$config;
    }

    /**
     * Validate password strength
     * @param string $password The password to validate
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validate($password)
    {
        $config = self::getConfig();
        $errors = [];

        $minLength = $config['password']['min_length'] ?? 8;
        if (strlen($password) < $minLength) {
            $errors[] = "Password must be at least {$minLength} characters long.";
        }

        if ($config['password']['require_uppercase'] ?? true) {
            if (!preg_match('/[A-Z]/', $password)) {
                $errors[] = "Password must contain at least one uppercase letter.";
            }
        }

        if ($config['password']['require_lowercase'] ?? true) {
            if (!preg_match('/[a-z]/', $password)) {
                $errors[] = "Password must contain at least one lowercase letter.";
            }
        }

        if ($config['password']['require_number'] ?? true) {
            if (!preg_match('/[0-9]/', $password)) {
                $errors[] = "Password must contain at least one number.";
            }
        }

        if ($config['password']['require_special'] ?? true) {
            if (!preg_match('/[^A-Za-z0-9]/', $password)) {
                $errors[] = "Password must contain at least one special character.";
            }
        }

        // Check for common passwords
        $commonPasswords = [
            'password',
            '123456',
            '12345678',
            'qwerty',
            'abc123',
            'monkey',
            'letmein',
            'dragon',
            '111111',
            'master',
            'sunshine',
            'iloveyou',
            'admin',
            'welcome',
            'password1'
        ];
        if (in_array(strtolower($password), $commonPasswords)) {
            $errors[] = "Password is too common. Please choose a more secure password.";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Hash a password
     */
    public static function hash($password)
    {
        $config = self::getConfig();
        $algorithm = $config['password']['algorithm'] ?? PASSWORD_BCRYPT;
        $cost = $config['password']['cost'] ?? 12;

        return password_hash($password, $algorithm, ['cost' => $cost]);
    }

    /**
     * Verify a password against its hash
     */
    public static function verify($password, $hash)
    {
        return password_verify($password, $hash);
    }

    /**
     * Check if password needs rehashing
     */
    public static function needsRehash($hash)
    {
        $config = self::getConfig();
        $algorithm = $config['password']['algorithm'] ?? PASSWORD_BCRYPT;
        $cost = $config['password']['cost'] ?? 12;

        return password_needs_rehash($hash, $algorithm, ['cost' => $cost]);
    }

    /**
     * Check if password is expired
     */
    public static function isExpired($userId)
    {
        $db = self::getDb();
        $config = self::getConfig();
        $expiryDays = $config['password']['expiry_days'] ?? 90;

        $user = $db->fetchOne(
            "SELECT password_changed_at, must_change_password 
             FROM platform_users 
             WHERE id = ? AND deleted_at IS NULL",
            [$userId]
        );

        if (!$user) {
            return true;
        }

        // If forced to change, it's expired
        if ($user['must_change_password'] == 1) {
            return true;
        }

        // If no password change date, assume it needs changing
        if (empty($user['password_changed_at'])) {
            return true;
        }

        // Check if password is older than expiry days
        $changedAt = strtotime($user['password_changed_at']);
        $expiresAt = strtotime("+{$expiryDays} days", $changedAt);

        return time() > $expiresAt;
    }

    /**
     * Update password for a user
     */
    public static function update($userId, $newPassword, $forceChange = false)
    {
        $db = self::getDb();

        // Validate password strength
        $validation = self::validate($newPassword);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => 'Password validation failed.',
                'errors' => $validation['errors']
            ];
        }

        // Check password history
        $historyCheck = self::checkHistory($userId, $newPassword);
        if (!$historyCheck['valid']) {
            return $historyCheck;
        }

        // Hash the new password
        $hash = self::hash($newPassword);

        // Update the user's password
        $db->execute(
            "UPDATE platform_users 
             SET password_hash = ?, 
                 password_changed_at = NOW(),
                 must_change_password = ?,
                 login_attempts = 0
             WHERE id = ?",
            [$hash, $forceChange ? 1 : 0, $userId]
        );

        // Add to password history
        self::addHistory($userId, $hash);

        return [
            'success' => true,
            'message' => 'Password updated successfully.'
        ];
    }

    /**
     * Force password change on next login
     */
    public static function forceChange($userId)
    {
        $db = self::getDb();

        $db->execute(
            "UPDATE platform_users SET must_change_password = 1 WHERE id = ?",
            [$userId]
        );

        return [
            'success' => true,
            'message' => 'User will be prompted to change password on next login.'
        ];
    }

    /**
     * Check password history for reuse
     */
    private static function checkHistory($userId, $newPassword)
    {
        $db = self::getDb();
        $config = self::getConfig();
        $historyCount = $config['password']['history_count'] ?? 5;

        // Get last N passwords
        $history = $db->fetchAll(
            "SELECT password_hash 
             FROM platform_password_history 
             WHERE user_id = ? 
             ORDER BY created_at DESC 
             LIMIT ?",
            [$userId, $historyCount]
        );

        foreach ($history as $entry) {
            if (self::verify($newPassword, $entry['password_hash'])) {
                return [
                    'valid' => false,
                    'success' => false,
                    'message' => 'Password has been used recently. Please choose a different password.',
                    'errors' => ['Password cannot be reused.']
                ];
            }
        }

        return ['valid' => true];
    }

    /**
     * Add password to history
     */
    private static function addHistory($userId, $hash)
    {
        $db = self::getDb();

        $db->execute(
            "INSERT INTO platform_password_history (user_id, password_hash, created_at) 
             VALUES (?, ?, NOW())",
            [$userId, $hash]
        );

        // Cleanup old history entries beyond the limit
        $config = self::getConfig();
        $historyCount = $config['password']['history_count'] ?? 5;

        $db->execute(
            "DELETE FROM platform_password_history 
             WHERE user_id = ? 
             AND id NOT IN (
                 SELECT id FROM (
                     SELECT id FROM platform_password_history 
                     WHERE user_id = ? 
                     ORDER BY created_at DESC 
                     LIMIT ?
                 ) AS keep
             )",
            [$userId, $userId, $historyCount]
        );
    }

    /**
     * Generate a secure random password
     */
    public static function generate($length = 12)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=';
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $password;
    }

    /**
     * Get password strength score (0-100)
     */
    public static function getStrengthScore($password)
    {
        $score = 0;

        // Length
        $length = strlen($password);
        if ($length >= 8) $score += 20;
        if ($length >= 12) $score += 10;
        if ($length >= 16) $score += 10;

        // Character types
        if (preg_match('/[a-z]/', $password)) $score += 10;
        if (preg_match('/[A-Z]/', $password)) $score += 10;
        if (preg_match('/[0-9]/', $password)) $score += 10;
        if (preg_match('/[^A-Za-z0-9]/', $password)) $score += 10;

        // Variety
        $uniqueChars = count(array_unique(str_split($password)));
        if ($uniqueChars > 5) $score += 10;
        if ($uniqueChars > 8) $score += 10;

        // Common patterns check
        $commonPatterns = ['123', 'abc', 'qwerty', 'password', 'admin', 'letmein'];
        $hasCommon = false;
        foreach ($commonPatterns as $pattern) {
            if (stripos($password, $pattern) !== false) {
                $hasCommon = true;
                break;
            }
        }
        if (!$hasCommon) $score += 10;

        return min($score, 100);
    }

    /**
     * Get strength label
     */
    public static function getStrengthLabel($score)
    {
        if ($score < 30) return ['label' => 'Weak', 'class' => 'danger'];
        if ($score < 50) return ['label' => 'Fair', 'class' => 'warning'];
        if ($score < 70) return ['label' => 'Good', 'class' => 'info'];
        if ($score < 85) return ['label' => 'Strong', 'class' => 'primary'];
        return ['label' => 'Very Strong', 'class' => 'success'];
    }
}
