<?php

/**
 * Settings / Appearance tab body.
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Settings
 * @version 1.0
 * @filepath public/platform/tenant/settings/appearance.php
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
 * - Loads the appearance settings by calling
 *   SettingsService::getSettings('appearance', $tenantId).
 * - Renders a form with the eleven keys the controller accepts.
 * - On save, calls
 *   SettingsService::saveSettings($payload, 'appearance', $tenantId, $userId).
 *
 * WHAT THIS FILE DOES NOT DO:
 * - It does not render the shell chrome; the shell does.
 * - It does not implement any other tab; each tab is its own file.
 * - It does not make any HTTP call.
 * - It does not live-preview the theme. The values are saved and
 *   applied by the shell on the next render.
 *
 * FIELD TYPES (per the accepted rule):
 * - *_color         : color picker
 * - *_url           : URL input
 * - *_theme,
 *   font_family,
 *   button_style    : select
 * - border_radius   : text (CSS measurement, unit included)
 */

// =============================================
// GUARD: must be included by the shell
// =============================================
if (!isset($db) || !isset($tenantId)) {
    http_response_code(500);
    echo '<div class="alert alert-danger">Appearance tab loaded out of context.</div>';
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
$appearanceKeys = [
    'primary_color',
    'secondary_color',
    'accent_color',
    'sidebar_theme',
    'header_theme',
    'logo_url',
    'favicon_url',
    'login_background_url',
    'font_family',
    'border_radius',
    'button_style',
];

$flashError = null;
$flashSuccess = null;

// =============================================
// HANDLE POST (save) — direct service call
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_appearance') {
    csrf_tab_verify();
    try {
        $payload = [];
        foreach ($appearanceKeys as $k) {
            if (array_key_exists($k, $_POST)) {
                $payload[$k] = trim((string)$_POST[$k]);
            }
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $svc = new SettingsService();
        $svc->saveSettings($payload, 'appearance', (int)$tenantId, $userId);
        $flashSuccess = 'Appearance settings saved.';
    } catch (Throwable $e) {
        error_log('appearance.php save error: ' . $e->getMessage());
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
    $data = $svc->getSettings('appearance', (int)$tenantId);
    if (!is_array($data)) {
        $data = [];
    }
    foreach ($appearanceKeys as $k) {
        $values[$k] = isset($data[$k]) ? (string)$data[$k] : '';
    }
} catch (Throwable $e) {
    error_log('appearance.php load error: ' . $e->getMessage());
    $loadError = 'Could not load appearance settings: ' . $e->getMessage();
    foreach ($appearanceKeys as $k) {
        $values[$k] = '';
    }
}

// =============================================
// DEFAULTS FOR EMPTY VALUES
// =============================================
$defaults = [
    'primary_color'         => '#4facfe',
    'secondary_color'       => '#00f2fe',
    'accent_color'          => '#1a1a2e',
    'sidebar_theme'         => 'dark',
    'header_theme'          => 'light',
    'logo_url'              => '',
    'favicon_url'           => '',
    'login_background_url'  => '',
    'font_family'           => 'Inter',
    'border_radius'         => '8px',
    'button_style'          => 'gradient',
];
foreach ($defaults as $k => $v) {
    if (!isset($values[$k]) || $values[$k] === '') {
        $values[$k] = $v;
    }
}

// =============================================
// SELECT OPTIONS
// =============================================
$themeOptions = [
    'light' => 'Light',
    'dark'  => 'Dark',
    'auto'  => 'Auto (match system)',
];

$fontFamilyOptions = [
    'Inter'             => 'Inter (default)',
    'system-ui'         => 'System UI',
    'Roboto'            => 'Roboto',
    'Open Sans'         => 'Open Sans',
    'Lato'              => 'Lato',
    'Georgia, serif'    => 'Georgia (serif)',
    'Courier New, monospace' => 'Courier New (monospace)',
];

$buttonStyleOptions = [
    'solid'    => 'Solid',
    'outline'  => 'Outline',
    'gradient' => 'Gradient (default)',
];

// =============================================
// FIELD DEFINITIONS
// =============================================
$fields = [
    'primary_color' => [
        'label' => 'Primary Colour',
        'type'  => 'color',
        'help'  => 'The main brand colour across the tenant UI.',
    ],
    'secondary_color' => [
        'label' => 'Secondary Colour',
        'type'  => 'color',
        'help'  => 'The complementary brand colour for gradients and highlights.',
    ],
    'accent_color' => [
        'label' => 'Accent Colour',
        'type'  => 'color',
        'help'  => 'Used for headings, dividers and dark-section backgrounds.',
    ],
    'sidebar_theme' => [
        'label'   => 'Sidebar Theme',
        'type'    => 'select',
        'options' => $themeOptions,
        'help'    => 'The default colour scheme of the left sidebar.',
    ],
    'header_theme' => [
        'label'   => 'Header Theme',
        'type'    => 'select',
        'options' => $themeOptions,
        'help'    => 'The default colour scheme of the top bar.',
    ],
    'logo_url' => [
        'label'       => 'Logo URL',
        'type'        => 'url',
        'placeholder' => '/uploads/logos/tenant1.png',
        'help'        => 'Absolute URL or root-relative path to the logo image.',
    ],
    'favicon_url' => [
        'label'       => 'Favicon URL',
        'type'        => 'url',
        'placeholder' => '/uploads/logos/favicon.png',
        'help'        => 'Absolute URL or root-relative path to the favicon.',
    ],
    'login_background_url' => [
        'label'       => 'Login Background URL',
        'type'        => 'url',
        'placeholder' => '/uploads/logos/login-bg.jpg',
        'help'        => 'Background image on the tenant login page.',
    ],
    'font_family' => [
        'label'   => 'Font Family',
        'type'    => 'select',
        'options' => $fontFamilyOptions,
        'help'    => 'The base typeface across the tenant UI.',
    ],
    'border_radius' => [
        'label'       => 'Border Radius',
        'type'        => 'text',
        'placeholder' => '8px',
        'help'        => 'CSS border-radius value, including the unit (e.g. 8px, 0.5rem, 12px).',
    ],
    'button_style' => [
        'label'   => 'Button Style',
        'type'    => 'select',
        'options' => $buttonStyleOptions,
        'help'    => 'The visual treatment of primary buttons.',
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

    <form method="POST" action="/platform/tenant/settings/index.php?tab=appearance" autocomplete="off">
        <?php echo csrf_tab_field(); ?>
        <input type="hidden" name="action" value="save_appearance">
        <div style="background:#fff;border-radius:14px;border:1px solid rgba(0,0,0,0.03);box-shadow:0 2px 8px rgba(0,0,0,0.04);margin-bottom:20px;overflow:hidden;">
            <div style="padding:16px 24px;border-bottom:1px solid #f0f2f5;background:linear-gradient(135deg,#f8fafc 0%,#eef6ff 100%);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <h6 style="font-size:14px;font-weight:700;margin:0;color:#1a1a2e;">
                    <i class="fas fa-palette me-2 text-primary"></i>Appearance Settings
                </h6>
                <span style="font-size:12px;color:#6c757d;"><?php echo count($appearanceKeys); ?> keys</span>
            </div>
            <div style="padding:20px 24px;">
                <?php foreach ($fields as $key => $meta):
                    $value = $values[$key] ?? '';
                    $type  = $meta['type'];
                    $id    = 'app_' . $key;
                ?>
                    <div class="app-row">
                        <div>
                            <label for="<?php echo h_tab($id); ?>" class="app-lbl">
                                <?php echo h_tab($meta['label']); ?>
                            </label>
                            <?php if (!empty($meta['help'])): ?>
                                <div class="app-help"><?php echo h_tab($meta['help']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if ($type === 'color'):
                                // The value may be a 7-char hex (#RRGGBB)
                                // or an empty string. When it is not a
                                // valid hex, fall back to a safe default
                                // for the picker, and expose the raw
                                // value in a text input alongside.
                                $hex = preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : '#000000';
                            ?>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <input type="color"
                                        id="<?php echo h_tab($id); ?>_picker"
                                        value="<?php echo h_tab($hex); ?>"
                                        style="width:48px;height:38px;border:2px solid #e9ecef;border-radius:8px;padding:2px;cursor:pointer;"
                                        oninput="document.getElementById('<?php echo h_tab($id); ?>').value = this.value;">
                                    <input type="text"
                                        id="<?php echo h_tab($id); ?>"
                                        name="<?php echo h_tab($key); ?>"
                                        value="<?php echo h_tab($value); ?>"
                                        class="form-control"
                                        style="border:2px solid #e9ecef;border-radius:8px;padding:6px 10px;font-size:13px;height:38px;"
                                        placeholder="#4facfe"
                                        autocomplete="off">
                                </div>
                            <?php elseif ($type === 'select'):
                                $options = $meta['options'];
                                $currentInList = false;
                                foreach ($options as $optVal => $optLabel) {
                                    if ((string)$optVal === (string)$value) {
                                        $currentInList = true;
                                        break;
                                    }
                                }
                            ?>
                                <select id="<?php echo h_tab($id); ?>"
                                    name="<?php echo h_tab($key); ?>"
                                    class="form-select"
                                    style="border:2px solid #e9ecef;border-radius:8px;padding:6px 10px;font-size:13px;height:38px;">
                                    <?php foreach ($options as $optVal => $optLabel): ?>
                                        <option value="<?php echo h_tab($optVal); ?>"
                                            <?php echo ((string)$value === (string)$optVal) ? 'selected' : ''; ?>>
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
                <a href="/platform/tenant/settings/index.php?tab=appearance" class="btn btn-outline-secondary">
                    <i class="fas fa-undo me-1"></i> Reset
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i> Save Appearance
                </button>
            </div>
        </div>
    </form>
</div>
<style>
    .app-row {
        padding: 12px 0;
        border-bottom: 1px solid #f0f2f5;
        display: grid;
        grid-template-columns: minmax(200px, 1fr) minmax(260px, 2fr);
        gap: 16px;
        align-items: center;
    }

    .app-row:last-child {
        border-bottom: none;
    }

    .app-lbl {
        font-weight: 600;
        font-size: 13px;
        color: #1a1a2e;
        margin: 0;
        display: block;
        cursor: pointer;
    }

    .app-help {
        font-size: 11px;
        color: #6c757d;
        margin-top: 2px;
    }

    @media (max-width: 700px) {
        .app-row {
            grid-template-columns: 1fr;
            gap: 6px;
        }
    }
</style>