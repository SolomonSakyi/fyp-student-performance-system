<?php

/**
 * Settings / Currency tab body.
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Settings
 * @version 1.1
 * @filepath public/platform/tenant/settings/currency.php
 *
 * v1.1 change (2026-10-05):
 *   The currency symbol field is now bound to the currency code
 *   select. When the user changes the code, a small script sets the
 *   symbol field to the canonical symbol for that code. On page
 *   load, if the symbol field is empty and the code is in the
 *   canonical map, the symbol is pre-filled; a stored custom symbol
 *   is left alone. The behaviour is "always sync": any manual edit
 *   of the symbol is overwritten the next time the code changes.
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
 * - Loads the currency settings by calling
 *   SettingsService::getSettings('currency', $tenantId).
 * - Renders a form with the six keys the controller accepts.
 * - On save, calls
 *   SettingsService::saveSettings($payload, 'currency', $tenantId, $userId).
 * - Binds the symbol field to the code select via a small script.
 *
 * WHAT THIS FILE DOES NOT DO:
 * - It does not render the shell chrome; the shell does.
 * - It does not implement any other tab; each tab is its own file.
 * - It does not make any HTTP call.
 *
 * FIELD TYPES (per the accepted rule):
 * - currency_code       : select
 * - currency_position   : select
 * - thousand_separator  : select
 * - decimal_separator   : select
 * - currency_symbol     : text
 * - decimal_places      : number
 */

// =============================================
// GUARD: must be included by the shell
// =============================================
if (!isset($db) || !isset($tenantId)) {
    http_response_code(500);
    echo '<div class="alert alert-danger">Currency tab loaded out of context.</div>';
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
$currencyKeys = [
    'currency_code',
    'currency_symbol',
    'currency_position',
    'decimal_places',
    'thousand_separator',
    'decimal_separator',
];

$flashError = null;
$flashSuccess = null;

// =============================================
// HANDLE POST (save) — direct service call
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_currency') {
    csrf_tab_verify();
    try {
        $payload = [];
        foreach ($currencyKeys as $k) {
            if (array_key_exists($k, $_POST)) {
                $payload[$k] = trim((string)$_POST[$k]);
            }
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $svc = new SettingsService();
        $svc->saveSettings($payload, 'currency', (int)$tenantId, $userId);
        $flashSuccess = 'Currency settings saved.';
    } catch (Throwable $e) {
        error_log('currency.php save error: ' . $e->getMessage());
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
    $data = $svc->getSettings('currency', (int)$tenantId);
    if (!is_array($data)) {
        $data = [];
    }
    foreach ($currencyKeys as $k) {
        $values[$k] = isset($data[$k]) ? (string)$data[$k] : '';
    }
} catch (Throwable $e) {
    error_log('currency.php load error: ' . $e->getMessage());
    $loadError = 'Could not load currency settings: ' . $e->getMessage();
    foreach ($currencyKeys as $k) {
        $values[$k] = '';
    }
}

// =============================================
// DEFAULTS FOR EMPTY VALUES
// =============================================
$defaults = [
    'currency_code'      => 'GHS',
    'currency_symbol'    => 'GH₵',
    'currency_position'  => 'before',
    'decimal_places'     => '2',
    'thousand_separator' => ',',
    'decimal_separator'  => '.',
];
foreach ($defaults as $k => $v) {
    if (!isset($values[$k]) || $values[$k] === '') {
        $values[$k] = $v;
    }
}

// =============================================
// SELECT OPTIONS
// =============================================
$currencyCodeOptions = [
    'GHS' => 'GHS — Ghana Cedi',
    'NGN' => 'NGN — Nigerian Naira',
    'KES' => 'KES — Kenyan Shilling',
    'ZAR' => 'ZAR — South African Rand',
    'USD' => 'USD — US Dollar',
    'EUR' => 'EUR — Euro',
    'GBP' => 'GBP — British Pound',
    'XOF' => 'XOF — West African CFA Franc',
];

$currencyPositionOptions = [
    'before' => 'Before amount (e.g. GH₵ 1,234.56)',
    'after'  => 'After amount (e.g. 1,234.56 GH₵)',
];

$thousandSeparatorOptions = [
    ',' => 'Comma (,)',
    '.' => 'Dot (.)',
    ' ' => 'Space ( )',
    "'" => 'Apostrophe (\')',
    ''  => 'None',
];

$decimalSeparatorOptions = [
    '.' => 'Dot (.)',
    ',' => 'Comma (,)',
];

// =============================================
// FIELD DEFINITIONS
// =============================================
$fields = [
    'currency_code' => [
        'label'   => 'Currency Code',
        'type'    => 'select',
        'options' => $currencyCodeOptions,
        'help'    => 'The ISO 4217 currency code for this tenant.',
    ],
    'currency_symbol' => [
        'label'       => 'Currency Symbol',
        'type'        => 'text',
        'placeholder' => 'GH₵',
        'help'        => 'Auto-updates when you change the currency code. You can still edit it manually.',
    ],
    'currency_position' => [
        'label'   => 'Symbol Position',
        'type'    => 'select',
        'options' => $currencyPositionOptions,
        'help'    => 'Whether the symbol is placed before or after the amount.',
    ],
    'decimal_places' => [
        'label' => 'Decimal Places',
        'type'  => 'number',
        'help'  => 'How many digits after the decimal point. Usually 2.',
    ],
    'thousand_separator' => [
        'label'   => 'Thousand Separator',
        'type'    => 'select',
        'options' => $thousandSeparatorOptions,
        'help'    => 'The character used between groups of three digits.',
    ],
    'decimal_separator' => [
        'label'   => 'Decimal Separator',
        'type'    => 'select',
        'options' => $decimalSeparatorOptions,
        'help'    => 'The character used between the whole and fractional parts.',
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

    <form method="POST" action="/platform/tenant/settings/index.php?tab=currency" autocomplete="off">
        <?php echo csrf_tab_field(); ?>
        <input type="hidden" name="action" value="save_currency">
        <div style="background:#fff;border-radius:14px;border:1px solid rgba(0,0,0,0.03);box-shadow:0 2px 8px rgba(0,0,0,0.04);margin-bottom:20px;overflow:hidden;">
            <div style="padding:16px 24px;border-bottom:1px solid #f0f2f5;background:linear-gradient(135deg,#f8fafc 0%,#eef6ff 100%);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <h6 style="font-size:14px;font-weight:700;margin:0;color:#1a1a2e;">
                    <i class="fas fa-coins me-2 text-primary"></i>Currency Settings
                </h6>
                <span style="font-size:12px;color:#6c757d;"><?php echo count($currencyKeys); ?> keys</span>
            </div>
            <div style="padding:20px 24px;">
                <?php foreach ($fields as $key => $meta):
                    $value = $values[$key] ?? '';
                    $type  = $meta['type'];
                    $id    = 'cur_' . $key;
                ?>
                    <div class="cur-row">
                        <div>
                            <label for="<?php echo h_tab($id); ?>" class="cur-lbl">
                                <?php echo h_tab($meta['label']); ?>
                            </label>
                            <?php if (!empty($meta['help'])): ?>
                                <div class="cur-help"><?php echo h_tab($meta['help']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if ($type === 'select'):
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
                            <?php elseif ($type === 'number'): ?>
                                <input type="number"
                                    min="0" max="4"
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
                <a href="/platform/tenant/settings/index.php?tab=currency" class="btn btn-outline-secondary">
                    <i class="fas fa-undo me-1"></i> Reset
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i> Save Currency
                </button>
            </div>
        </div>
    </form>
</div>
<style>
    .cur-row {
        padding: 12px 0;
        border-bottom: 1px solid #f0f2f5;
        display: grid;
        grid-template-columns: minmax(200px, 1fr) minmax(260px, 2fr);
        gap: 16px;
        align-items: center;
    }

    .cur-row:last-child {
        border-bottom: none;
    }

    .cur-lbl {
        font-weight: 600;
        font-size: 13px;
        color: #1a1a2e;
        margin: 0;
        display: block;
        cursor: pointer;
    }

    .cur-help {
        font-size: 11px;
        color: #6c757d;
        margin-top: 2px;
    }

    @media (max-width: 700px) {
        .cur-row {
            grid-template-columns: 1fr;
            gap: 6px;
        }
    }
</style>
<script>
    /*
     * [v1.1] Bind the currency symbol field to the currency code select.
     *
     * On change of the code: set the symbol field to the canonical
     * symbol for that code, if the code is in the map. If the code is
     * not in the map (a custom stored value), leave the symbol field
     * alone.
     *
     * On DOMContentLoaded: if the symbol field is empty AND the code
     * is in the map, prefill the symbol. A stored custom symbol is
     * left alone.
     *
     * Behaviour is "always sync": any manual edit of the symbol is
     * overwritten the next time the code changes. That matches the
     * one-to-one binding the user asked for.
     */
    (function() {
        var symbolMap = {
            'GHS': 'GH₵',
            'NGN': '₦',
            'KES': 'KSh',
            'ZAR': 'R',
            'USD': '$',
            'EUR': '€',
            'GBP': '£',
            'XOF': 'CFA'
        };

        var codeSelect = document.getElementById('cur_currency_code');
        var symbolInput = document.getElementById('cur_currency_symbol');

        if (!codeSelect || !symbolInput) {
            return;
        }

        function syncSymbol() {
            var code = (codeSelect.value || '').toString().trim().toUpperCase();
            if (Object.prototype.hasOwnProperty.call(symbolMap, code)) {
                symbolInput.value = symbolMap[code];
                // Notify any future listener on the symbol field.
                symbolInput.dispatchEvent(new Event('change', {
                    bubbles: true
                }));
            }
        }

        codeSelect.addEventListener('change', syncSymbol);

        document.addEventListener('DOMContentLoaded', function() {
            if ((symbolInput.value || '').toString().trim() === '') {
                syncSymbol();
            }
        });

        // Also run immediately, in case DOMContentLoaded already fired.
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            if ((symbolInput.value || '').toString().trim() === '') {
                syncSymbol();
            }
        }
    })();
</script>