<?php

/**
 * Settings / Email tab body.
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Settings
 * @version 2.0
 * @filepath public/platform/tenant/settings/email.php
 *
 * v2.0 change (2026-10-05):
 *   The HTTP loopback is removed. The tab body now calls
 *   SettingsService for the eight email keys and
 *   SettingsMaintenanceService::testEmailConnection($settings) for
 *   the Test Connection button. The previous version made cURL
 *   calls to /api/platform/index.php, which caused the same class
 *   of transport errors that integrations.php went through.
 *   Direct service calls close all of them at once.
 *
 *   The Test Connection button reads the SMTP settings from the
 *   database each time, so it tests the values that are saved, not
 *   the values in the form. The button is a diagnostic, not a save.
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
 * - Loads the email settings by calling
 *   SettingsService::getSettings('email', $tenantId).
 * - Renders a form with the eight keys the controller accepts.
 * - On save, calls
 *   SettingsService::saveSettings($payload, 'email', $tenantId, $userId).
 * - Renders a Test Connection button that calls
 *   SettingsMaintenanceService::testEmailConnection($settings) and
 *   displays the result inline.
 *
 * WHAT THIS FILE DOES NOT DO:
 * - It does not render the shell chrome; the shell does.
 * - It does not implement any other tab; each tab is its own file.
 * - It does not make any HTTP call.
 *
 * ITEM 13 (from the carried-over list):
 * The Email tab was named as having two accessibility defects:
 *   (a) a <label> not associated with its form field;
 *   (b) a password field without an eye toggle.
 * This file is written with both defects prevented from the start:
 * every <label> carries for="<id>" and matches its input's id="<id>";
 * and the smtp_password input is paired with a js-toggle-pw button
 * that swaps its type between password and text.
 *
 * FIELD TYPES (per the accepted rule):
 * - smtp_host        : text
 * - smtp_port        : number
 * - smtp_username    : text
 * - smtp_password    : password with eye toggle
 * - smtp_encryption  : select
 * - from_email       : email
 * - from_name        : text
 * - reply_to_email   : email
 */

// =============================================
// GUARD: must be included by the shell
// =============================================
if (!isset($db) || !isset($tenantId)) {
    http_response_code(500);
    echo '<div class="alert alert-danger">Email tab loaded out of context.</div>';
    return;
}

// =============================================
// DEPENDENCIES
// =============================================
if (!isset($projectRoot) || $projectRoot === '') {
    $projectRoot = dirname(__DIR__, 4);
}
require_once $projectRoot . '/app/services/Platform/SettingsService.php';
require_once $projectRoot . '/app/services/Platform/SettingsMaintenanceService.php';

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
$emailKeys = [
    'smtp_host',
    'smtp_port',
    'smtp_username',
    'smtp_password',
    'smtp_encryption',
    'from_email',
    'from_name',
    'reply_to_email',
];

$flashError = null;
$flashSuccess = null;
$testResult = null;

// =============================================
// HANDLE POST (save / test) — direct service calls
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_email') {
        csrf_tab_verify();
        try {
            $payload = [];
            foreach ($emailKeys as $k) {
                if (array_key_exists($k, $_POST)) {
                    $payload[$k] = trim((string)$_POST[$k]);
                }
            }
            $userId = (int)($_SESSION['user_id'] ?? 0);
            $svc = new SettingsService();
            $svc->saveSettings($payload, 'email', (int)$tenantId, $userId);
            $flashSuccess = 'Email settings saved.';
        } catch (Throwable $e) {
            error_log('email.php save error: ' . $e->getMessage());
            $flashError = 'Could not save: ' . $e->getMessage();
        }
    } elseif ($action === 'test_email') {
        csrf_tab_verify();
        try {
            // Read the saved settings so the test uses the values
            // in the database, not the values in the form.
            $svc = new SettingsService();
            $settings = $svc->getSettings('email', (int)$tenantId);
            if (!is_array($settings)) {
                $settings = [];
            }
            $maint = new SettingsMaintenanceService();
            $result = $maint->testEmailConnection($settings);
            $testResult = ['ok' => true, 'body' => $result];
            $flashSuccess = 'SMTP connection test succeeded.';
        } catch (Throwable $e) {
            error_log('email.php test_email error: ' . $e->getMessage());
            $testResult = ['ok' => false, 'body' => $e->getMessage()];
            $flashError = 'SMTP connection test failed: ' . $e->getMessage();
        }
    }
}

// =============================================
// HANDLE GET (load) — direct service call
// =============================================
$values = [];
$loadError = null;
try {
    $svc = new SettingsService();
    $data = $svc->getSettings('email', (int)$tenantId);
    if (!is_array($data)) {
        $data = [];
    }
    foreach ($emailKeys as $k) {
        $values[$k] = isset($data[$k]) ? (string)$data[$k] : '';
    }
} catch (Throwable $e) {
    error_log('email.php load error: ' . $e->getMessage());
    $loadError = 'Could not load email settings: ' . $e->getMessage();
    foreach ($emailKeys as $k) {
        $values[$k] = '';
    }
}

// =============================================
// DEFAULTS FOR EMPTY VALUES
// =============================================
$defaults = [
    'smtp_host'       => '',
    'smtp_port'       => '587',
    'smtp_username'   => '',
    'smtp_password'   => '',
    'smtp_encryption' => 'tls',
    'from_email'      => '',
    'from_name'       => '',
    'reply_to_email'  => '',
];
foreach ($defaults as $k => $v) {
    if (!isset($values[$k]) || $values[$k] === '') {
        $values[$k] = $v;
    }
}

// =============================================
// FIELD DEFINITIONS (label, type, options, placeholder, help)
// =============================================
$fields = [
    'smtp_host' => [
        'label'       => 'SMTP Host',
        'type'        => 'text',
        'placeholder' => 'smtp.example.com',
        'help'        => 'The hostname of your outgoing mail server.',
    ],
    'smtp_port' => [
        'label'       => 'SMTP Port',
        'type'        => 'number',
        'placeholder' => '587',
        'help'        => 'Common values: 587 (TLS), 465 (SSL), 25 (plain).',
    ],
    'smtp_username' => [
        'label'       => 'SMTP Username',
        'type'        => 'text',
        'placeholder' => 'user@example.com',
        'help'        => 'The username your SMTP server expects.',
    ],
    'smtp_password' => [
        'label'       => 'SMTP Password',
        'type'        => 'password',
        'placeholder' => '',
        'help'        => 'Stored encrypted. Use the eye icon to reveal it.',
    ],
    'smtp_encryption' => [
        'label'   => 'SMTP Encryption',
        'type'    => 'select',
        'options' => [
            ''      => '— Not set —',
            'none'  => 'None',
            'tls'   => 'TLS',
            'ssl'   => 'SSL',
        ],
        'help'    => 'TLS is recommended for port 587; SSL for port 465.',
    ],
    'from_email' => [
        'label'       => 'From Email',
        'type'        => 'email',
        'placeholder' => 'no-reply@example.com',
        'help'        => 'The address outbound mail is sent from.',
    ],
    'from_name' => [
        'label'       => 'From Name',
        'type'        => 'text',
        'placeholder' => 'EduTrack Notifications',
        'help'        => 'The display name on outbound mail.',
    ],
    'reply_to_email' => [
        'label'       => 'Reply-To Email',
        'type'        => 'email',
        'placeholder' => 'support@example.com',
        'help'        => 'Where replies to your outbound mail should go.',
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

    <form method="POST" action="/platform/tenant/settings/index.php?tab=email" autocomplete="off">
        <?php echo csrf_tab_field(); ?>
        <input type="hidden" name="action" value="save_email">
        <div style="background:#fff;border-radius:14px;border:1px solid rgba(0,0,0,0.03);box-shadow:0 2px 8px rgba(0,0,0,0.04);margin-bottom:20px;overflow:hidden;">
            <div style="padding:16px 24px;border-bottom:1px solid #f0f2f5;background:linear-gradient(135deg,#f8fafc 0%,#eef6ff 100%);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <h6 style="font-size:14px;font-weight:700;margin:0;color:#1a1a2e;">
                    <i class="fas fa-envelope me-2 text-primary"></i>Email / SMTP Settings
                </h6>
                <span style="font-size:12px;color:#6c757d;"><?php echo count($emailKeys); ?> keys</span>
            </div>
            <div style="padding:20px 24px;">
                <?php foreach ($fields as $key => $meta):
                    $value = $values[$key] ?? '';
                    $type  = $meta['type'];
                    $id    = 'email_' . $key;
                ?>
                    <div class="email-row">
                        <div>
                            <label for="<?php echo h_tab($id); ?>" class="email-lbl">
                                <?php echo h_tab($meta['label']); ?>
                            </label>
                            <?php if (!empty($meta['help'])): ?>
                                <div class="email-help"><?php echo h_tab($meta['help']); ?></div>
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
                                        title="Show / hide password"
                                        aria-label="Show or hide password">
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
                            <?php elseif ($type === 'number'): ?>
                                <input type="number"
                                    id="<?php echo h_tab($id); ?>"
                                    name="<?php echo h_tab($key); ?>"
                                    value="<?php echo h_tab($value); ?>"
                                    min="1" max="65535"
                                    class="form-control"
                                    style="border:2px solid #e9ecef;border-radius:8px;padding:6px 10px;font-size:13px;height:38px;"
                                    placeholder="<?php echo h_tab($meta['placeholder'] ?? ''); ?>">
                            <?php elseif ($type === 'email'): ?>
                                <input type="email"
                                    id="<?php echo h_tab($id); ?>"
                                    name="<?php echo h_tab($key); ?>"
                                    value="<?php echo h_tab($value); ?>"
                                    class="form-control"
                                    style="border:2px solid #e9ecef;border-radius:8px;padding:6px 10px;font-size:13px;height:38px;"
                                    placeholder="<?php echo h_tab($meta['placeholder'] ?? ''); ?>"
                                    autocomplete="off">
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
                <a href="/platform/tenant/settings/index.php?tab=email" class="btn btn-outline-secondary">
                    <i class="fas fa-undo me-1"></i> Reset
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i> Save Email
                </button>
            </div>
        </div>
    </form>

    <div style="background:#fff;border-radius:14px;border:1px solid rgba(0,0,0,0.03);box-shadow:0 2px 8px rgba(0,0,0,0.04);margin-bottom:20px;overflow:hidden;">
        <div style="padding:16px 24px;border-bottom:1px solid #f0f2f5;background:linear-gradient(135deg,#f8fafc 0%,#eef6ff 100%);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <h6 style="font-size:14px;font-weight:700;margin:0;color:#1a1a2e;">
                <i class="fas fa-plug me-2 text-primary"></i>Test SMTP Connection
            </h6>
        </div>
        <div style="padding:20px 24px;">
            <div style="font-size:13px;color:#6c757d;margin-bottom:12px;">
                Attempts a TCP connection to <code>smtp_host:smtp_port</code> using the values currently saved.
                Save your changes first if you edited the host or port.
            </div>
            <form method="POST" action="/platform/tenant/settings/index.php?tab=email" style="margin:0;">
                <?php echo csrf_tab_field(); ?>
                <input type="hidden" name="action" value="test_email">
                <button type="submit" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-vial me-1"></i> Test Connection
                </button>
            </form>
            <?php if ($testResult !== null): ?>
                <div style="margin-top:12px;font-size:13px;<?php echo $testResult['ok'] ? 'color:#166534;' : 'color:#991b1b;'; ?>">
                    <?php
                    if (is_array($testResult['body'])) {
                        echo h_tab(json_encode($testResult['body'], JSON_UNESCAPED_UNICODE));
                    } else {
                        echo h_tab((string)$testResult['body']);
                    }
                    ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<style>
    .email-row {
        padding: 12px 0;
        border-bottom: 1px solid #f0f2f5;
        display: grid;
        grid-template-columns: minmax(200px, 1fr) minmax(260px, 2fr);
        gap: 16px;
        align-items: center;
    }

    .email-row:last-child {
        border-bottom: none;
    }

    .email-lbl {
        font-weight: 600;
        font-size: 13px;
        color: #1a1a2e;
        margin: 0;
        display: block;
        cursor: pointer;
    }

    .email-help {
        font-size: 11px;
        color: #6c757d;
        margin-top: 2px;
    }

    @media (max-width: 700px) {
        .email-row {
            grid-template-columns: 1fr;
            gap: 6px;
        }
    }
</style>
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