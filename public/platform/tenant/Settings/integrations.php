<?php

/**
 * Settings / Integrations tab body.
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Settings
 * @version 2.0
 * @filepath public/platform/tenant/settings/integrations.php
 *
 * v2.0 change (2026-10-05):
 *   The HTTP loopback is removed. The previous versions made a cURL
 *   call to /api/platform/index.php to reach the controller, which
 *   in turn called the service. That loopback caused four defects
 *   in sequence — URL parsing, TLS trust against the local
 *   self-signed certificate, a session file lock deadlock, and a
 *   session-context split that produced "No tenant context
 *   available". All four were consequences of leaving the request
 *   and coming back in. This version calls SettingsService
 *   directly. The service returns arrays, not JSON. The tenant id
 *   and user id the service needs are passed in explicitly from
 *   the shell's $_SESSION, so the service never reads the session
 *   context and never throws the tenant-context error.
 *
 * v1.3 change (2026-10-05):
 *   session_write_close() before the cURL call, to release the
 *   session file lock. (Superseded by v2.0.)
 *
 * v1.2 change (2026-10-05):
 *   Skipped certificate verification for the same-origin loopback
 *   call. (Superseded by v2.0.)
 *
 * v1.1 change (2026-10-05):
 *   Absolute $apiBase instead of a relative path. (Superseded by
 *   v2.0.)
 *
 * This file is INCLUDED by settings/index.php. It does not
 * re-bootstrap, does not re-check auth, and does not open its own
 * database connection. It uses the following variables already in
 * scope from the shell:
 *   $db          DatabaseHelper instance
 *   $tenantId    int
 *   $currentUser string
 *   $userAvatar  string
 *   h()          escape wrapper
 *
 * WHAT THIS FILE DOES:
 * - Loads the integrations settings by calling
 *   SettingsService::getSettings('integrations', $tenantId).
 * - Renders a form with the ten keys the controller accepts.
 * - On POST, saves the ten keys by calling
 *   SettingsService::saveSettings($payload, 'integrations', $tenantId, $userId).
 *
 * WHAT THIS FILE DOES NOT DO:
 * - It does not render the shell chrome; the shell does.
 * - It does not implement any other tab; each tab is its own file.
 * - It does not make any HTTP call.
 * - It does not touch $_SESSION (it reads $_SESSION['user_id'] once,
 *   for the save path's audit column).
 *
 * FIELD TYPES (per the accepted rule):
 * - *_id, *_sender_id  : text
 * - *_key, *_secret_key: password with eye toggle
 * - *_provider         : select
 * - default            : text
 *
 * RECORD REFERENCE: EduTrack56.pdf item 1 (integrations.php load
 * function, nested-vs-flat key mismatch). This file is the
 * integrations tab body whose missing load function item 1 names.
 */
// =============================================
// GUARD: must be included by the shell
// =============================================
if (!isset($db) || !isset($tenantId)) {
    http_response_code(500);
    echo '<div class="alert alert-danger">Integrations tab loaded out of context.</div>';
    return;
}

// =============================================
// DEPENDENCIES
// =============================================
// The shell's $projectRoot is in scope. If not, derive it.
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

/**
 * CSRF token: prefer Security.php helpers if present, otherwise use
 * a local pair bound to the PHP session.
 */
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
$integrationKeys = [
    'google_analytics_id',
    'facebook_pixel_id',
    'recaptcha_site_key',
    'recaptcha_secret_key',
    'map_api_key',
    'sms_provider',
    'sms_api_key',
    'sms_sender_id',
    'email_provider',
    'email_api_key',
];

$flashError = null;
$flashSuccess = null;

// =============================================
// HANDLE POST (save) — direct service call
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_integrations') {
    csrf_tab_verify();
    try {
        $payload = [];
        foreach ($integrationKeys as $k) {
            if (array_key_exists($k, $_POST)) {
                $payload[$k] = trim((string)$_POST[$k]);
            }
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $service = new SettingsService();
        $service->saveSettings($payload, 'integrations', (int)$tenantId, $userId);
        $flashSuccess = 'Integration settings saved.';
    } catch (Throwable $e) {
        error_log('integrations.php save error: ' . $e->getMessage());
        $flashError = 'Could not save: ' . $e->getMessage();
    }
}

// =============================================
// HANDLE GET (load) — direct service call
// =============================================
$values = [];
$loadError = null;
try {
    $service = new SettingsService();
    $data = $service->getSettings('integrations', (int)$tenantId);
    if (!is_array($data)) {
        $data = [];
    }
    foreach ($integrationKeys as $k) {
        $values[$k] = isset($data[$k]) ? (string)$data[$k] : '';
    }
} catch (Throwable $e) {
    error_log('integrations.php load error: ' . $e->getMessage());
    $loadError = 'Could not load integration settings: ' . $e->getMessage();
    foreach ($integrationKeys as $k) {
        $values[$k] = '';
    }
}

// =============================================
// FIELD DEFINITIONS (label, type, options, placeholder, help)
// =============================================
$fields = [
    'google_analytics_id' => [
        'label' => 'Google Analytics ID',
        'type'  => 'text',
        'placeholder' => 'G-XXXXXXXXXX or UA-XXXXXXXX-X',
        'help'  => 'Measurement ID for Google Analytics 4.',
    ],
    'facebook_pixel_id' => [
        'label' => 'Facebook Pixel ID',
        'type'  => 'text',
        'placeholder' => '1234567890123456',
        'help'  => 'Facebook Pixel identifier for conversion tracking.',
    ],
    'recaptcha_site_key' => [
        'label' => 'reCAPTCHA Site Key',
        'type'  => 'password',
        'placeholder' => '',
        'help'  => 'Public site key issued by Google reCAPTCHA.',
    ],
    'recaptcha_secret_key' => [
        'label' => 'reCAPTCHA Secret Key',
        'type'  => 'password',
        'placeholder' => '',
        'help'  => 'Private secret key. Stored encrypted.',
    ],
    'map_api_key' => [
        'label' => 'Map API Key',
        'type'  => 'password',
        'placeholder' => '',
        'help'  => 'Google Maps or equivalent tile-provider key.',
    ],
    'sms_provider' => [
        'label' => 'SMS Provider',
        'type'  => 'select',
        'options' => [
            '' => '— Not set —',
            'none' => 'None',
            'twilio' => 'Twilio',
            'africastalking' => "Africa's Talking",
            'hubtel' => 'Hubtel',
            'other' => 'Other',
        ],
        'help' => 'The SMS gateway used for OTP and alerts.',
    ],
    'sms_api_key' => [
        'label' => 'SMS API Key',
        'type'  => 'password',
        'placeholder' => '',
        'help'  => 'API key for the selected SMS provider. Stored encrypted.',
    ],
    'sms_sender_id' => [
        'label' => 'SMS Sender ID',
        'type'  => 'text',
        'placeholder' => 'EDUTRACK',
        'help'  => 'The alphanumeric sender ID shown on outbound SMS.',
    ],
    'email_provider' => [
        'label' => 'Email Provider',
        'type'  => 'select',
        'options' => [
            '' => '— Not set —',
            'none' => 'None',
            'smtp' => 'SMTP',
            'sendgrid' => 'SendGrid',
            'mailgun' => 'Mailgun',
            'ses' => 'Amazon SES',
            'other' => 'Other',
        ],
        'help' => 'The transactional email gateway used for notifications.',
    ],
    'email_api_key' => [
        'label' => 'Email API Key',
        'type'  => 'password',
        'placeholder' => '',
        'help'  => 'API key for the selected email provider. Stored encrypted.',
    ],
];
?>
<div>
    <?php if ($flashError): ?>
        <div class="alert-pro error">
            <div class="ap-icon"><i class="fas fa-times-circle"></i></div>
            <div class="ap-body">
                <div class="ap-title">Could not save</div>
                <div class="ap-text"><?php echo h_tab($flashError); ?></div>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($flashSuccess): ?>
        <div class="alert-pro success">
            <div class="ap-icon"><i class="fas fa-check-circle"></i></div>
            <div class="ap-body">
                <div class="ap-title">Saved</div>
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
    <form method="POST" action="/platform/tenant/settings/index.php?tab=integrations" autocomplete="off">
        <?php echo csrf_tab_field(); ?>
        <input type="hidden" name="action" value="save_integrations">
        <div class="card-custom" style="background:#fff;border-radius:14px;border:1px solid rgba(0,0,0,0.03);box-shadow:0 2px 8px rgba(0,0,0,0.04);margin-bottom:20px;overflow:hidden;">
            <div style="padding:16px 24px;border-bottom:1px solid #f0f2f5;background:linear-gradient(135deg,#f8fafc 0%,#eef6ff 100%);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <h6 style="font-size:14px;font-weight:700;margin:0;color:#1a1a2e;">
                    <i class="fas fa-plug me-2 text-primary"></i>Integration Settings
                </h6>
                <span style="font-size:12px;color:#6c757d;"><?php echo count($integrationKeys); ?> keys</span>
            </div>
            <div style="padding:20px 24px;">
                <?php foreach ($fields as $key => $meta):
                    $value = $values[$key] ?? '';
                    $type  = $meta['type'];
                    $id    = 'intg_' . $key;
                ?>
                    <div style="padding:12px 0;border-bottom:1px solid #f0f2f5;display:grid;grid-template-columns:minmax(180px,1fr) minmax(240px,2fr);gap:16px;align-items:center;">
                        <div>
                            <label for="<?php echo h_tab($id); ?>" style="font-weight:600;font-size:13px;color:#1a1a2e;margin:0;display:block;">
                                <?php echo h_tab($meta['label']); ?>
                            </label>
                            <?php if (!empty($meta['help'])): ?>
                                <div style="font-size:11px;color:#6c757d;margin-top:2px;"><?php echo h_tab($meta['help']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if ($type === 'password'): ?>
                                <div style="display:flex;gap:6px;align-items:center;">
                                    <input type="password"
                                        id="<?php echo h_tab($id); ?>"
                                        name="<?php echo h_tab($key); ?>"
                                        value="<?php echo h_tab($value); ?>"
                                        class="form-control"
                                        style="border:2px solid #e9ecef;border-radius:8px;padding:6px 10px;font-size:13px;height:38px;"
                                        autocomplete="new-password">
                                    <button type="button"
                                        class="btn btn-outline-secondary btn-sm js-toggle-pw"
                                        data-target="<?php echo h_tab($id); ?>"
                                        title="Show / hide">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            <?php elseif ($type === 'select'): ?>
                                <select id="<?php echo h_tab($id); ?>"
                                    name="<?php echo h_tab($key); ?>"
                                    class="form-select"
                                    style="border:2px solid #e9ecef;border-radius:8px;padding:6px 10px;font-size:13px;height:38px;">
                                    <?php foreach ($meta['options'] as $optVal => $optLabel): ?>
                                        <option value="<?php echo h_tab($optVal); ?>"
                                            <?php echo ((string)$value === (string)$optVal) ? 'selected' : ''; ?>>
                                            <?php echo h_tab($optLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
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
                <a href="/platform/tenant/settings/index.php?tab=integrations" class="btn btn-outline-secondary">
                    <i class="fas fa-undo me-1"></i> Reset
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i> Save Integrations
                </button>
            </div>
        </div>
    </form>
</div>
<script>
    (function() {
        document.querySelectorAll('.js-toggle-pw').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = this.getAttribute('data-target');
                var el = document.getElementById(id);
                if (!el) return;
                var icon = this.querySelector('i');
                if (el.type === 'password') {
                    el.type = 'text';
                    if (icon) {
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    }
                } else {
                    el.type = 'password';
                    if (icon) {
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                }
            });
        });
    })();
</script>