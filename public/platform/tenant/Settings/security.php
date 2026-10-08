<?php

/**
 * Settings / Security tab body.
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Settings
 * @version 1.0
 * @filepath public/platform/tenant/settings/security.php
 *
 * This file is INCLUDED by settings/index.php. It does not
 * re-bootstrap, does not re-check auth, and does not open its own
 * database connection. It uses the variables already in scope from
 * the shell:
 *   $db          DatabaseHelper instance
 *   $tenantId    int
 *   $currentUser string
 *   $userAvatar  string
 *   h()          escape wrapper (defined by the shell)
 *
 * WHAT THIS FILE DOES:
 * - Loads the security settings by calling
 *   SettingsService::getSecuritySettings($tenantId).
 * - Renders a form with the twenty keys the controller accepts.
 * - On save, calls
 *   SettingsService::saveSecuritySettings($payload, $tenantId, null, $userId).
 *
 * WHAT THIS FILE DOES NOT DO:
 * - It does not render the shell chrome; the shell does.
 * - It does not implement any other tab; each tab is its own file.
 * - It does not make any HTTP call.
 *
 * FIELD TYPES (per the accepted rule):
 * - *_enabled, require_*, require_2fa, require_mfa : checkbox
 * - all other keys                                   : number, except
 * - ip_whitelist, ip_blacklist                       : text (comma-separated IPs)
 */

// =============================================
// GUARD: must be included by the shell
// =============================================
if (!isset($db) || !isset($tenantId)) {
    http_response_code(500);
    echo '<div class="alert alert-danger">Security tab loaded out of context.</div>';
    return;
}

// =============================================
// DEPENDENCIES
// =============================================
if (!isset($projectRoot) || $projectRoot === '') {
    $projectRoot = dirname(__DIR__, 4);
}
require_once $projectRoot . '/app/services/Platform/SettingsService.php';

// =============================================
// LOCAL HELPERS
// =============================================
if (!function_exists('h_tab')) {
    function h_tab($s)
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('csrf_tab_field')) {
    function csrf_tab_field()
    {
        if (function_exists('csrf_field')) {
            return csrf_field();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
        }
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) . '">';
    }
}

if (!function_exists('csrf_tab_verify')) {
    function csrf_tab_verify()
    {
        if (function_exists('verify_csrf')) {
            verify_csrf();
            return;
        }
        $sent = $_POST['csrf_token'] ?? '';
        $stored = $_SESSION['csrf_token'] ?? '';
        if (!$sent || !$stored || !hash_equals($stored, $sent)) {
            http_response_code(403);
            die('Invalid CSRF token.');
        }
    }
}

// =============================================
// KEY LIST
// =============================================
$securityKeys = [
    'min_password_length',
    'require_uppercase',
    'require_lowercase',
    'require_numbers',
    'require_special_chars',
    'password_expiry_days',
    'prevent_password_reuse_count',
    'session_timeout_minutes',
    'max_concurrent_sessions',
    'require_2fa',
    'require_mfa',
    'max_login_attempts',
    'lockout_duration_minutes',
    'login_throttle_seconds',
    'ip_whitelist_enabled',
    'ip_whitelist',
    'ip_blacklist_enabled',
    'ip_blacklist',
    'audit_logging_enabled',
    'audit_retention_days',
];

$checkboxKeys = [
    'require_uppercase',
    'require_lowercase',
    'require_numbers',
    'require_special_chars',
    'require_2fa',
    'require_mfa',
    'ip_whitelist_enabled',
    'ip_blacklist_enabled',
    'audit_logging_enabled',
];

$flashError = null;
$flashSuccess = null;

// =============================================
// HANDLE POST (save) — direct service call
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_security') {
    csrf_tab_verify();
    try {
        $payload = [];
        foreach ($securityKeys as $k) {
            if (in_array($k, $checkboxKeys, true)) {
                $payload[$k] = isset($_POST[$k]) && $_POST[$k] !== '' ? '1' : '0';
            } elseif (array_key_exists($k, $_POST)) {
                $payload[$k] = trim((string)$_POST[$k]);
            }
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $svc = new SettingsService();
        $svc->saveSecuritySettings($payload, (int)$tenantId, null, $userId);
        $flashSuccess = 'Security settings saved.';
    } catch (Throwable $e) {
        error_log('security.php save error: ' . $e->getMessage());
        $flashError = 'Could not save: ' . $e->getMessage();
    }
}

// =============================================
// HANDLE GET (load) — direct service call
// =============================================
$values = [];
$loadError = null;
try {
    $svc = new SettingsService();
    $data = $svc->getSecuritySettings((int)$tenantId);
    if (!is_array($data)) {
        $data = [];
    }
    foreach ($securityKeys as $k) {
        $values[$k] = isset($data[$k]) ? (string)$data[$k] : '';
    }
} catch (Throwable $e) {
    error_log('security.php load error: ' . $e->getMessage());
    $loadError = 'Could not load security settings: ' . $e->getMessage();
    foreach ($securityKeys as $k) {
        $values[$k] = '';
    }
}

// =============================================
// DEFAULTS FOR EMPTY VALUES
// =============================================
$defaults = [
    'min_password_length'         => '8',
    'require_uppercase'           => '1',
    'require_lowercase'           => '1',
    'require_numbers'             => '1',
    'require_special_chars'       => '1',
    'password_expiry_days'        => '90',
    'prevent_password_reuse_count' => '5',
    'session_timeout_minutes'     => '60',
    'max_concurrent_sessions'     => '3',
    'require_2fa'                 => '0',
    'require_mfa'                 => '0',
    'max_login_attempts'          => '5',
    'lockout_duration_minutes'    => '30',
    'login_throttle_seconds'      => '60',
    'ip_whitelist_enabled'        => '0',
    'ip_whitelist'                => '',
    'ip_blacklist_enabled'        => '0',
    'ip_blacklist'                => '',
    'audit_logging_enabled'       => '1',
    'audit_retention_days'        => '90',
];
foreach ($defaults as $k => $v) {
    if (!isset($values[$k]) || $values[$k] === '') {
        $values[$k] = $v;
    }
}

// =============================================
// FIELD DEFINITIONS
// =============================================
$fields = [
    'min_password_length' => [
        'label' => 'Minimum Password Length',
        'type'  => 'number',
        'help'  => 'Minimum number of characters for a new password.',
    ],
    'require_uppercase' => [
        'label' => 'Require Uppercase',
        'type'  => 'checkbox',
        'help'  => 'Passwords must contain at least one uppercase letter.',
    ],
    'require_lowercase' => [
        'label' => 'Require Lowercase',
        'type'  => 'checkbox',
        'help'  => 'Passwords must contain at least one lowercase letter.',
    ],
    'require_numbers' => [
        'label' => 'Require Numbers',
        'type'  => 'checkbox',
        'help'  => 'Passwords must contain at least one digit.',
    ],
    'require_special_chars' => [
        'label' => 'Require Special Characters',
        'type'  => 'checkbox',
        'help'  => 'Passwords must contain at least one non-alphanumeric character.',
    ],
    'password_expiry_days' => [
        'label' => 'Password Expiry (days)',
        'type'  => 'number',
        'help'  => 'Force a password change every N days. 0 disables expiry.',
    ],
    'prevent_password_reuse_count' => [
        'label' => 'Prevent Password Reuse',
        'type'  => 'number',
        'help'  => 'How many previous passwords are blocked from reuse.',
    ],
    'session_timeout_minutes' => [
        'label' => 'Session Timeout (minutes)',
        'type'  => 'number',
        'help'  => 'Idle minutes before a session is closed.',
    ],
    'max_concurrent_sessions' => [
        'label' => 'Max Concurrent Sessions',
        'type'  => 'number',
        'help'  => 'How many devices a user may be logged in on at once.',
    ],
    'require_2fa' => [
        'label' => 'Require 2FA',
        'type'  => 'checkbox',
        'help'  => 'Require a second factor for every staff login.',
    ],
    'require_mfa' => [
        'label' => 'Require MFA',
        'type'  => 'checkbox',
        'help'  => 'Require multi-factor authentication where configured.',
    ],
    'max_login_attempts' => [
        'label' => 'Max Login Attempts',
        'type'  => 'number',
        'help'  => 'Failed attempts before the account is locked.',
    ],
    'lockout_duration_minutes' => [
        'label' => 'Lockout Duration (minutes)',
        'type'  => 'number',
        'help'  => 'How long a lockout lasts before the account can be retried.',
    ],
    'login_throttle_seconds' => [
        'label' => 'Login Throttle (seconds)',
        'type'  => 'number',
        'help'  => 'Minimum gap between consecutive login attempts.',
    ],
    'ip_whitelist_enabled' => [
        'label' => 'IP Whitelist Enabled',
        'type'  => 'checkbox',
        'help'  => 'Only allow logins from the whitelist below.',
    ],
    'ip_whitelist' => [
        'label'       => 'IP Whitelist',
        'type'        => 'text',
        'placeholder' => '203.0.113.5, 198.51.100.0/24',
        'help'        => 'Comma-separated IPs or CIDR ranges.',
    ],
    'ip_blacklist_enabled' => [
        'label' => 'IP Blacklist Enabled',
        'type'  => 'checkbox',
        'help'  => 'Reject logins from the blacklist below.',
    ],
    'ip_blacklist' => [
        'label'       => 'IP Blacklist',
        'type'        => 'text',
        'placeholder' => '203.0.113.5, 198.51.100.0/24',
        'help'        => 'Comma-separated IPs or CIDR ranges.',
    ],
    'audit_logging_enabled' => [
        'label' => 'Audit Logging Enabled',
        'type'  => 'checkbox',
        'help'  => 'Write a row to the audit log for every settings change.',
    ],
    'audit_retention_days' => [
        'label' => 'Audit Retention (days)',
        'type'  => 'number',
        'help'  => 'How many days of audit log to keep.',
    ],
];
?>
<div>
    <?php if ($flashError): ?>
        <div class="alert-pro error">
            <div class="ap-icon"><i class="fas fa-times-circle"></i></div>
            <div class="ap-body">
                <div class="ap-title">Could not complete the request</div>
                <div class="ap-text"><?php echo h_tab($flashError); ?></div>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($flashSuccess): ?>
        <div class="alert-pro success">
            <div class="ap-icon"><i class="fas fa-check-circle"></i></div>
            <div class="ap-body">
                <div class="ap-title">Done</div>
                <div class="ap-text"><?php echo h_tab($flashSuccess); ?></div>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($loadError): ?>
        <div class="alert-pro error">
            <div class="ap-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="ap-body">
                <div class="ap-title">Load failed</div>
                <div class="ap-text"><?php echo h_tab($loadError); ?></div>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" action="/platform/tenant/settings/index.php?tab=security" autocomplete="off">
        <?php echo csrf_tab_field(); ?>
        <input type="hidden" name="action" value="save_security">
        <div style="background:#fff;border-radius:14px;border:1px solid rgba(0,0,0,0.03);box-shadow:0 2px 8px rgba(0,0,0,0.04);margin-bottom:20px;overflow:hidden;">
            <div style="padding:16px 24px;border-bottom:1px solid #f0f2f5;background:linear-gradient(135deg,#f8fafc 0%,#eef6ff 100%);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <h6 style="font-size:14px;font-weight:700;margin:0;color:#1a1a2e;">
                    <i class="fas fa-shield-alt me-2 text-primary"></i>Security Settings
                </h6>
                <span style="font-size:12px;color:#6c757d;"><?php echo count($securityKeys); ?> keys</span>
            </div>
            <div style="padding:20px 24px;">
                <?php foreach ($fields as $key => $meta):
                    $value = $values[$key] ?? '';
                    $type  = $meta['type'];
                    $id    = 'sec_' . $key;
                ?>
                    <div class="sec-row">
                        <div>
                            <label for="<?php echo h_tab($id); ?>" class="sec-lbl">
                                <?php echo h_tab($meta['label']); ?>
                            </label>
                            <?php if (!empty($meta['help'])): ?>
                                <div class="sec-help"><?php echo h_tab($meta['help']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if ($type === 'checkbox'): ?>
                                <input type="checkbox"
                                    id="<?php echo h_tab($id); ?>"
                                    name="<?php echo h_tab($key); ?>"
                                    value="1"
                                    <?php echo $value === '1' ? 'checked' : ''; ?>>
                            <?php elseif ($type === 'number'): ?>
                                <input type="number"
                                    min="0"
                                    id="<?php echo h_tab($id); ?>"
                                    name="<?php echo h_tab($key); ?>"
                                    value="<?php echo h_tab($value); ?>"
                                    class="form-control"
                                    style="border:2px solid #e9ecef;border-radius:8px;padding:6px 10px;font-size:13px;height:38px;">
                            <?php else: ?>
                                <input type="text"
                                    id="<?php echo h_tab($id); ?>"
                                    name="<?php echo h_tab($key); ?>"
                                    value="<?php echo h_tab($value); ?>"
                                    class="form-control"
                                    style="border:2px solid #e9ecef;border-radius:8px;padding:6px 10px;font-size:13px;height:38px;"
                                    placeholder="<?php echo h_tab($meta['placeholder'] ?? ''); ?>"
                                    autocomplete="off">
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="padding:16px 24px;border-top:1px solid #f0f2f5;display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;">
                <a href="/platform/tenant/settings/index.php?tab=security" class="btn btn-outline-secondary">
                    <i class="fas fa-undo me-1"></i> Reset
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i> Save Security
                </button>
            </div>
        </div>
    </form>
</div>
<style>
    .sec-row {
        padding: 12px 0;
        border-bottom: 1px solid #f0f2f5;
        display: grid;
        grid-template-columns: minmax(220px, 1fr) minmax(220px, 2fr);
        gap: 16px;
        align-items: center;
    }

    .sec-row:last-child {
        border-bottom: none;
    }

    .sec-lbl {
        font-weight: 600;
        font-size: 13px;
        color: #1a1a2e;
        margin: 0;
        display: block;
        cursor: pointer;
    }

    .sec-help {
        font-size: 11px;
        color: #6c757d;
        margin-top: 2px;
    }

    .sec-row input[type="checkbox"] {
        width: 20px;
        height: 20px;
        cursor: pointer;
    }

    @media (max-width: 700px) {
        .sec-row {
            grid-template-columns: 1fr;
            gap: 6px;
        }
    }
</style>