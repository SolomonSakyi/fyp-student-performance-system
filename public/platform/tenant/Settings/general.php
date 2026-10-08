<?php

/**
 * Settings / General tab body.
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Settings
 * @version 1.0
 * @filepath public/platform/tenant/settings/general.php
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
 * - Loads the general settings by calling
 *   SettingsService::getSettings('general', $tenantId).
 * - Renders a form with the ten keys the controller accepts.
 * - On save, calls
 *   SettingsService::saveSettings($payload, 'general', $tenantId, $userId).
 *
 * WHAT THIS FILE DOES NOT DO:
 * - It does not render the shell chrome; the shell does.
 * - It does not implement any other tab; each tab is its own file.
 * - It does not make any HTTP call.
 *
 * FIELD TYPES (per the accepted rule):
 * - platform_url    : URL input
 * - platform_email  : email input
 * - timezone        : select
 * - date_format     : select
 * - time_format     : select
 * - default_language: select
 * - maintenance_mode: checkbox
 * - everything else : text
 *
 * NOTE ON THE SELECT LISTS:
 * The timezone, date_format, time_format and default_language selects
 * carry a bounded list of common values. If the current value is not
 * in the list, the select gains a "custom" option carrying the
 * current value. That way no stored value is ever silently dropped.
 */

// =============================================
// GUARD: must be included by the shell
// =============================================
if (!isset($db) || !isset($tenantId)) {
    http_response_code(500);
    echo '<div class="alert alert-danger">General tab loaded out of context.</div>';
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
$generalKeys = [
    'platform_name',
    'platform_url',
    'platform_email',
    'platform_phone',
    'platform_address',
    'timezone',
    'date_format',
    'time_format',
    'default_language',
    'maintenance_mode',
];

$flashError = null;
$flashSuccess = null;

// =============================================
// HANDLE POST (save) — direct service call
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_general') {
    csrf_tab_verify();
    try {
        $payload = [];
        foreach ($generalKeys as $k) {
            if ($k === 'maintenance_mode') {
                // Checkbox: present = '1', absent = '0'.
                $payload[$k] = isset($_POST[$k]) && $_POST[$k] !== '' ? '1' : '0';
            } elseif (array_key_exists($k, $_POST)) {
                $payload[$k] = trim((string)$_POST[$k]);
            }
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $svc = new SettingsService();
        $svc->saveSettings($payload, 'general', (int)$tenantId, $userId);
        $flashSuccess = 'General settings saved.';
    } catch (Throwable $e) {
        error_log('general.php save error: ' . $e->getMessage());
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
    $data = $svc->getSettings('general', (int)$tenantId);
    if (!is_array($data)) {
        $data = [];
    }
    foreach ($generalKeys as $k) {
        $values[$k] = isset($data[$k]) ? (string)$data[$k] : '';
    }
} catch (Throwable $e) {
    error_log('general.php load error: ' . $e->getMessage());
    $loadError = 'Could not load general settings: ' . $e->getMessage();
    foreach ($generalKeys as $k) {
        $values[$k] = '';
    }
}

// =============================================
// DEFAULTS FOR EMPTY VALUES
// =============================================
$defaults = [
    'platform_name'      => '',
    'platform_url'       => '',
    'platform_email'     => '',
    'platform_phone'     => '',
    'platform_address'   => '',
    'timezone'           => 'Africa/Accra',
    'date_format'        => 'Y-m-d',
    'time_format'        => 'H:i',
    'default_language'   => 'en',
    'maintenance_mode'   => '0',
];
foreach ($defaults as $k => $v) {
    if (!isset($values[$k]) || $values[$k] === '') {
        $values[$k] = $v;
    }
}

// =============================================
// SELECT OPTIONS
// =============================================
$timezoneOptions = [
    'Africa/Accra',
    'Africa/Lagos',
    'Africa/Nairobi',
    'Africa/Johannesburg',
    'Africa/Cairo',
    'Europe/London',
    'Europe/Paris',
    'America/New_York',
    'America/Chicago',
    'America/Los_Angeles',
    'Asia/Dubai',
    'UTC',
];

$dateFormatOptions = [
    'Y-m-d',
    'd/m/Y',
    'm/d/Y',
    'd M Y',
    'M j, Y',
];

$timeFormatOptions = [
    'H:i',
    'h:i A',
];

$languageOptions = [
    'en' => 'English',
    'fr' => 'French',
    'es' => 'Spanish',
    'pt' => 'Portuguese',
    'ar' => 'Arabic',
    'sw' => 'Swahili',
];

// =============================================
// FIELD DEFINITIONS
// =============================================
$fields = [
    'platform_name' => [
        'label'       => 'Platform Name',
        'type'        => 'text',
        'placeholder' => 'EduTrack',
        'help'        => 'Shown in the browser title and the sidebar brand.',
    ],
    'platform_url' => [
        'label'       => 'Platform URL',
        'type'        => 'url',
        'placeholder' => 'https://example.com',
        'help'        => 'The canonical public URL of this installation.',
    ],
    'platform_email' => [
        'label'       => 'Platform Email',
        'type'        => 'email',
        'placeholder' => 'hello@example.com',
        'help'        => 'The contact address shown on outbound notifications.',
    ],
    'platform_phone' => [
        'label'       => 'Platform Phone',
        'type'        => 'text',
        'placeholder' => '+233 20 000 0000',
        'help'        => 'The support phone number shown on outbound notifications.',
    ],
    'platform_address' => [
        'label'       => 'Platform Address',
        'type'        => 'text',
        'placeholder' => 'P.O. Box ...',
        'help'        => 'The physical or postal address of the organization.',
    ],
    'timezone' => [
        'label'   => 'Timezone',
        'type'    => 'select',
        'options' => $timezoneOptions,
        'help'    => 'Used for timestamps on reports, notifications and audit logs.',
    ],
    'date_format' => [
        'label'   => 'Date Format',
        'type'    => 'select',
        'options' => $dateFormatOptions,
        'help'    => 'How dates are displayed across the platform.',
    ],
    'time_format' => [
        'label'   => 'Time Format',
        'type'    => 'select',
        'options' => $timeFormatOptions,
        'help'    => '12-hour or 24-hour clock.',
    ],
    'default_language' => [
        'label'   => 'Default Language',
        'type'    => 'select',
        'options' => $languageOptions,
        'help'    => 'The default UI language for new users.',
    ],
    'maintenance_mode' => [
        'label' => 'Maintenance Mode',
        'type'  => 'checkbox',
        'help'  => 'When on, non-admin users see a maintenance notice instead of the app.',
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

    <form method="POST" action="/platform/tenant/settings/index.php?tab=general" autocomplete="off">
        <?php echo csrf_tab_field(); ?>
        <input type="hidden" name="action" value="save_general">
        <div style="background:#fff;border-radius:14px;border:1px solid rgba(0,0,0,0.03);box-shadow:0 2px 8px rgba(0,0,0,0.04);margin-bottom:20px;overflow:hidden;">
            <div style="padding:16px 24px;border-bottom:1px solid #f0f2f5;background:linear-gradient(135deg,#f8fafc 0%,#eef6ff 100%);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <h6 style="font-size:14px;font-weight:700;margin:0;color:#1a1a2e;">
                    <i class="fas fa-sliders-h me-2 text-primary"></i>General Settings
                </h6>
                <span style="font-size:12px;color:#6c757d;"><?php echo count($generalKeys); ?> keys</span>
            </div>
            <div style="padding:20px 24px;">
                <?php foreach ($fields as $key => $meta):
                    $value = $values[$key] ?? '';
                    $type  = $meta['type'];
                    $id    = 'gen_' . $key;
                ?>
                    <div class="gen-row">
                        <div>
                            <label for="<?php echo h_tab($id); ?>" class="gen-lbl">
                                <?php echo h_tab($meta['label']); ?>
                            </label>
                            <?php if (!empty($meta['help'])): ?>
                                <div class="gen-help"><?php echo h_tab($meta['help']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if ($type === 'checkbox'): ?>
                                <input type="checkbox"
                                    id="<?php echo h_tab($id); ?>"
                                    name="<?php echo h_tab($key); ?>"
                                    value="1"
                                    <?php echo $value === '1' ? 'checked' : ''; ?>>
                            <?php elseif ($type === 'select'):
                                $options = $meta['options'];
                                $isAssoc = false;
                                foreach ($options as $k2 => $v2) {
                                    if (!is_int($k2)) {
                                        $isAssoc = true;
                                        break;
                                    }
                                }
                                $currentInList = false;
                                foreach ($options as $k2 => $v2) {
                                    $optVal = $isAssoc ? (string)$k2 : (string)$v2;
                                    if ($optVal === (string)$value) {
                                        $currentInList = true;
                                        break;
                                    }
                                }
                            ?>
                                <select id="<?php echo h_tab($id); ?>"
                                    name="<?php echo h_tab($key); ?>"
                                    class="form-select"
                                    style="border:2px solid #e9ecef;border-radius:8px;padding:6px 10px;font-size:13px;height:38px;">
                                    <?php foreach ($options as $k2 => $v2):
                                        $optVal = $isAssoc ? (string)$k2 : (string)$v2;
                                        $optLabel = $isAssoc ? (string)$v2 : (string)$v2;
                                    ?>
                                        <option value="<?php echo h_tab($optVal); ?>"
                                            <?php echo ((string)$value === $optVal) ? 'selected' : ''; ?>>
                                            <?php echo h_tab($optLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if (!$currentInList && $value !== ''): ?>
                                        <option value="<?php echo h_tab($value); ?>" selected>
                                            <?php echo h_tab($value); ?> (custom)
                                        </option>
                                    <?php endif; ?>
                                </select>
                            <?php else: ?>
                                <input type="<?php echo h_tab($type); ?>"
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
                <a href="/platform/tenant/settings/index.php?tab=general" class="btn btn-outline-secondary">
                    <i class="fas fa-undo me-1"></i> Reset
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i> Save General
                </button>
            </div>
        </div>
    </form>
</div>
<style>
    .gen-row {
        padding: 12px 0;
        border-bottom: 1px solid #f0f2f5;
        display: grid;
        grid-template-columns: minmax(200px, 1fr) minmax(260px, 2fr);
        gap: 16px;
        align-items: center;
    }

    .gen-row:last-child {
        border-bottom: none;
    }

    .gen-lbl {
        font-weight: 600;
        font-size: 13px;
        color: #1a1a2e;
        margin: 0;
        display: block;
        cursor: pointer;
    }

    .gen-help {
        font-size: 11px;
        color: #6c757d;
        margin-top: 2px;
    }

    .gen-row input[type="checkbox"] {
        width: 20px;
        height: 20px;
        cursor: pointer;
    }

    @media (max-width: 700px) {
        .gen-row {
            grid-template-columns: 1fr;
            gap: 6px;
        }
    }
</style>