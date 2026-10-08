<?php

/**
 * Settings / Advanced tab body.
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Settings
 * @version 2.0
 * @filepath public/platform/tenant/settings/advanced.php
 *
 * v2.0 change (2026-10-05):
 *   The HTTP loopback is removed. The tab body now calls
 *   SettingsService and SettingsMaintenanceService directly. The
 *   previous version made cURL calls to /api/platform/index.php
 *   for the ten form fields and for the two maintenance actions,
 *   which caused the same class of transport errors that
 *   integrations.php went through (URL parsing, TLS trust,
 *   session lock deadlock, session cookie split). Direct service
 *   calls close all of them at once.
 *
 *   The two maintenance buttons (Clear Cache, Optimize Database)
 *   now call SettingsMaintenanceService::clearCache() and
 *   ::optimizeDatabase() directly. The SettingsController still
 *   wraps those same methods for the API endpoints; this file
 *   bypasses the endpoints and calls the service. Both entry
 *   points share the same implementation.
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
 * - Loads the advanced settings by calling
 *   SettingsService::getSettings('advanced', $tenantId).
 * - Renders a form with the ten keys the controller accepts.
 * - On save, calls
 *   SettingsService::saveSettings($payload, 'advanced', $tenantId, $userId).
 * - Renders two maintenance buttons:
 *     Clear Cache        -> SettingsMaintenanceService::clearCache()
 *     Optimize Database  -> SettingsMaintenanceService::optimizeDatabase()
 *   Both actions are followed by a flash banner.
 *
 * WHAT THIS FILE DOES NOT DO:
 * - It does not render the shell chrome; the shell does.
 * - It does not implement any other tab; each tab is its own file.
 * - It does not make any HTTP call.
 * - It does not touch $_SESSION beyond reading $_SESSION['user_id']
 *   once, for the save path's audit column.
 *
 * FIELD TYPES (per the accepted rule):
 * - *_enabled        : checkbox
 * - *_ttl, *_lifetime, *_limit, *_size : number
 * - *_level          : select
 * - *_file_types     : text
 * - default          : text
 *
 * RECORD REFERENCES:
 *   EduTrack56.pdf item 2 (advanced.php load function + twelve-key mismatch).
 *   EduTrack56.pdf item 3 (Clear Cache button).
 *   EduTrack56.pdf item 4 (Optimize Database button).
 */

// =============================================
// GUARD: must be included by the shell
// =============================================
if (!isset($db) || !isset($tenantId)) {
    http_response_code(500);
    echo '<div class="alert alert-danger">Advanced tab loaded out of context.</div>';
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
$advancedKeys = [
    'cache_enabled',
    'cache_ttl',
    'debug_mode',
    'log_level',
    'api_rate_limit',
    'max_upload_size',
    'allowed_file_types',
    'session_lifetime',
    'cookie_secure',
    'cookie_httponly',
];

$flashError = null;
$flashSuccess = null;
$actionResult = null;

// =============================================
// HANDLE POST (save / actions) — direct service calls
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_advanced') {
        csrf_tab_verify();
        try {
            $payload = [];
            foreach ($advancedKeys as $k) {
                if (in_array($k, ['cache_enabled', 'debug_mode', 'cookie_secure', 'cookie_httponly'], true)) {
                    // Checkbox: present = '1', absent = '0'.
                    $payload[$k] = isset($_POST[$k]) && $_POST[$k] !== '' ? '1' : '0';
                } elseif (array_key_exists($k, $_POST)) {
                    $payload[$k] = trim((string)$_POST[$k]);
                }
            }
            $userId = (int)($_SESSION['user_id'] ?? 0);
            $svc = new SettingsService();
            $svc->saveSettings($payload, 'advanced', (int)$tenantId, $userId);
            $flashSuccess = 'Advanced settings saved.';
        } catch (Throwable $e) {
            error_log('advanced.php save error: ' . $e->getMessage());
            $flashError = 'Could not save: ' . $e->getMessage();
        }
    } elseif ($action === 'clear_cache') {
        csrf_tab_verify();
        try {
            $svc = new SettingsMaintenanceService();
            $result = $svc->clearCache();
            $actionResult = ['label' => 'Clear Cache', 'ok' => true, 'body' => $result];
            $flashSuccess = 'Cache cleared. ' . (int)($result['cleared'] ?? 0) . ' file(s) removed.';
            if (!empty($result['note'])) {
                $flashSuccess .= ' ' . $result['note'];
            }
        } catch (Throwable $e) {
            error_log('advanced.php clear_cache error: ' . $e->getMessage());
            $flashError = 'Could not clear cache: ' . $e->getMessage();
        }
    } elseif ($action === 'optimize_database') {
        csrf_tab_verify();
        try {
            $svc = new SettingsMaintenanceService();
            $result = $svc->optimizeDatabase();
            $actionResult = ['label' => 'Optimize Database', 'ok' => true, 'body' => $result];
            $opt = $result['optimized'] ?? [];
            $ok = 0;
            $fail = 0;
            foreach ($opt as $row) {
                if (!empty($row['ok'])) {
                    $ok++;
                } else {
                    $fail++;
                }
            }
            $flashSuccess = 'Database optimization complete. ' . $ok . ' table(s) optimized' . ($fail > 0 ? ', ' . $fail . ' failed' : '') . '.';
        } catch (Throwable $e) {
            error_log('advanced.php optimize_database error: ' . $e->getMessage());
            $flashError = 'Could not optimize database: ' . $e->getMessage();
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
    $data = $svc->getSettings('advanced', (int)$tenantId);
    if (!is_array($data)) {
        $data = [];
    }
    foreach ($advancedKeys as $k) {
        $values[$k] = isset($data[$k]) ? (string)$data[$k] : '';
    }
} catch (Throwable $e) {
    error_log('advanced.php load error: ' . $e->getMessage());
    $loadError = 'Could not load advanced settings: ' . $e->getMessage();
    foreach ($advancedKeys as $k) {
        $values[$k] = '';
    }
}

// =============================================
// DEFAULTS FOR EMPTY VALUES
// =============================================
$defaults = [
    'cache_enabled'      => '1',
    'cache_ttl'          => '3600',
    'debug_mode'         => '0',
    'log_level'          => 'warning',
    'api_rate_limit'     => '60',
    'max_upload_size'    => '10',
    'allowed_file_types' => 'jpg,jpeg,png,gif,pdf,csv,xlsx',
    'session_lifetime'   => '60',
    'cookie_secure'      => '0',
    'cookie_httponly'    => '1',
];
foreach ($defaults as $k => $v) {
    if (!isset($values[$k]) || $values[$k] === '') {
        $values[$k] = $v;
    }
}
?>
<div>
    <?php if ($flashError): ?>
        <div class="alert-pro error">
            <div class="ap-icon"><i class="fas fa-times-circle"></i></div>
            <div class="ap-body">
                <div class="ap-title">Action failed</div>
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

    <form method="POST" action="/platform/tenant/settings/index.php?tab=advanced" autocomplete="off">
        <?php echo csrf_tab_field(); ?>
        <input type="hidden" name="action" value="save_advanced">

        <div style="background:#fff;border-radius:14px;border:1px solid rgba(0,0,0,0.03);box-shadow:0 2px 8px rgba(0,0,0,0.04);margin-bottom:20px;overflow:hidden;">
            <div style="padding:16px 24px;border-bottom:1px solid #f0f2f5;background:linear-gradient(135deg,#f8fafc 0%,#eef6ff 100%);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <h6 style="font-size:14px;font-weight:700;margin:0;color:#1a1a2e;">
                    <i class="fas fa-cogs me-2 text-primary"></i>Advanced Settings
                </h6>
                <span style="font-size:12px;color:#6c757d;"><?php echo count($advancedKeys); ?> keys</span>
            </div>

            <div style="padding:20px 24px;">
                <div class="adv-row">
                    <div>
                        <label for="adv_cache_enabled" class="adv-lbl">Cache enabled</label>
                        <div class="adv-help">Turn the application cache on or off.</div>
                    </div>
                    <div>
                        <input type="checkbox" id="adv_cache_enabled" name="cache_enabled" value="1"
                            <?php echo $values['cache_enabled'] === '1' ? 'checked' : ''; ?>>
                    </div>
                </div>

                <div class="adv-row">
                    <div>
                        <label for="adv_cache_ttl" class="adv-lbl">Cache TTL</label>
                        <div class="adv-help">How long cache entries live, in seconds.</div>
                    </div>
                    <div>
                        <input type="number" min="0" id="adv_cache_ttl" name="cache_ttl"
                            value="<?php echo h_tab($values['cache_ttl']); ?>"
                            class="form-control" style="border:2px solid #e9ecef;border-radius:8px;padding:6px 10px;font-size:13px;height:38px;">
                    </div>
                </div>

                <div class="adv-row">
                    <div>
                        <label for="adv_debug_mode" class="adv-lbl">Debug mode</label>
                        <div class="adv-help">Show verbose errors in responses. Off in production.</div>
                    </div>
                    <div>
                        <input type="checkbox" id="adv_debug_mode" name="debug_mode" value="1"
                            <?php echo $values['debug_mode'] === '1' ? 'checked' : ''; ?>>
                    </div>
                </div>

                <div class="adv-row">
                    <div>
                        <label for="adv_log_level" class="adv-lbl">Log level</label>
                        <div class="adv-help">Minimum severity written to the application log.</div>
                    </div>
                    <div>
                        <select id="adv_log_level" name="log_level" class="form-select"
                            style="border:2px solid #e9ecef;border-radius:8px;padding:6px 10px;font-size:13px;height:38px;">
                            <?php foreach (['debug', 'info', 'warning', 'error', 'critical'] as $lvl): ?>
                                <option value="<?php echo h_tab($lvl); ?>"
                                    <?php echo strtolower($values['log_level']) === $lvl ? 'selected' : ''; ?>>
                                    <?php echo h_tab(ucfirst($lvl)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="adv-row">
                    <div>
                        <label for="adv_api_rate_limit" class="adv-lbl">API rate limit</label>
                        <div class="adv-help">Maximum API requests per minute per tenant.</div>
                    </div>
                    <div>
                        <input type="number" min="1" id="adv_api_rate_limit" name="api_rate_limit"
                            value="<?php echo h_tab($values['api_rate_limit']); ?>"
                            class="form-control" style="border:2px solid #e9ecef;border-radius:8px;padding:6px 10px;font-size:13px;height:38px;">
                    </div>
                </div>

                <div class="adv-row">
                    <div>
                        <label for="adv_max_upload_size" class="adv-lbl">Max upload size</label>
                        <div class="adv-help">Maximum upload size in megabytes.</div>
                    </div>
                    <div>
                        <input type="number" min="1" id="adv_max_upload_size" name="max_upload_size"
                            value="<?php echo h_tab($values['max_upload_size']); ?>"
                            class="form-control" style="border:2px solid #e9ecef;border-radius:8px;padding:6px 10px;font-size:13px;height:38px;">
                    </div>
                </div>

                <div class="adv-row">
                    <div>
                        <label for="adv_allowed_file_types" class="adv-lbl">Allowed file types</label>
                        <div class="adv-help">Comma-separated list of extensions, no dots.</div>
                    </div>
                    <div>
                        <input type="text" id="adv_allowed_file_types" name="allowed_file_types"
                            value="<?php echo h_tab($values['allowed_file_types']); ?>"
                            class="form-control" style="border:2px solid #e9ecef;border-radius:8px;padding:6px 10px;font-size:13px;height:38px;"
                            autocomplete="off">
                    </div>
                </div>

                <div class="adv-row">
                    <div>
                        <label for="adv_session_lifetime" class="adv-lbl">Session lifetime</label>
                        <div class="adv-help">Idle session lifetime in minutes.</div>
                    </div>
                    <div>
                        <input type="number" min="1" id="adv_session_lifetime" name="session_lifetime"
                            value="<?php echo h_tab($values['session_lifetime']); ?>"
                            class="form-control" style="border:2px solid #e9ecef;border-radius:8px;padding:6px 10px;font-size:13px;height:38px;">
                    </div>
                </div>

                <div class="adv-row">
                    <div>
                        <label for="adv_cookie_secure" class="adv-lbl">Cookie secure</label>
                        <div class="adv-help">Only send cookies over HTTPS.</div>
                    </div>
                    <div>
                        <input type="checkbox" id="adv_cookie_secure" name="cookie_secure" value="1"
                            <?php echo $values['cookie_secure'] === '1' ? 'checked' : ''; ?>>
                    </div>
                </div>

                <div class="adv-row" style="border-bottom:none;">
                    <div>
                        <label for="adv_cookie_httponly" class="adv-lbl">Cookie HTTP-only</label>
                        <div class="adv-help">Make cookies inaccessible to JavaScript.</div>
                    </div>
                    <div>
                        <input type="checkbox" id="adv_cookie_httponly" name="cookie_httponly" value="1"
                            <?php echo $values['cookie_httponly'] === '1' ? 'checked' : ''; ?>>
                    </div>
                </div>
            </div>

            <div style="padding:16px 24px;border-top:1px solid #f0f2f5;display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;">
                <a href="/platform/tenant/settings/index.php?tab=advanced" class="btn btn-outline-secondary">
                    <i class="fas fa-undo me-1"></i> Reset
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i> Save Advanced
                </button>
            </div>
        </div>
    </form>

    <!-- Maintenance actions: Clear Cache and Optimize Database -->
    <div style="background:#fff;border-radius:14px;border:1px solid rgba(0,0,0,0.03);box-shadow:0 2px 8px rgba(0,0,0,0.04);margin-bottom:20px;overflow:hidden;">
        <div style="padding:16px 24px;border-bottom:1px solid #f0f2f5;background:linear-gradient(135deg,#f8fafc 0%,#eef6ff 100%);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <h6 style="font-size:14px;font-weight:700;margin:0;color:#1a1a2e;">
                <i class="fas fa-tools me-2 text-primary"></i>Maintenance
            </h6>
        </div>
        <div style="padding:20px 24px;display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div style="border:1px solid #e9ecef;border-radius:12px;padding:16px 18px;">
                <div style="font-weight:600;font-size:14px;color:#1a1a2e;margin-bottom:4px;">
                    <i class="fas fa-broom me-1 text-warning"></i> Clear Cache
                </div>
                <div style="font-size:12px;color:#6c757d;margin-bottom:12px;">
                    Removes files under <code>storage/cache/</code> so the next request rebuilds them.
                </div>
                <form method="POST" action="/platform/tenant/settings/index.php?tab=advanced" style="margin:0;">
                    <?php echo csrf_tab_field(); ?>
                    <input type="hidden" name="action" value="clear_cache">
                    <button type="submit" class="btn btn-outline-secondary btn-sm"
                        onclick="return confirm('Clear cache? Cached files will be removed.');">
                        <i class="fas fa-broom me-1"></i> Clear Cache
                    </button>
                </form>
            </div>
            <div style="border:1px solid #e9ecef;border-radius:12px;padding:16px 18px;">
                <div style="font-weight:600;font-size:14px;color:#1a1a2e;margin-bottom:4px;">
                    <i class="fas fa-database me-1 text-primary"></i> Optimize Database
                </div>
                <div style="font-size:12px;color:#6c757d;margin-bottom:12px;">
                    Runs <code>OPTIMIZE TABLE</code> on the twelve core tables.
                </div>
                <form method="POST" action="/platform/tenant/settings/index.php?tab=advanced" style="margin:0;">
                    <?php echo csrf_tab_field(); ?>
                    <input type="hidden" name="action" value="optimize_database">
                    <button type="submit" class="btn btn-outline-secondary btn-sm"
                        onclick="return confirm('Optimize the database tables? This may take a moment.');">
                        <i class="fas fa-database me-1"></i> Optimize Database
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
    .adv-row {
        padding: 12px 0;
        border-bottom: 1px solid #f0f2f5;
        display: grid;
        grid-template-columns: minmax(200px, 1fr) minmax(240px, 2fr);
        gap: 16px;
        align-items: center;
    }

    .adv-lbl {
        font-weight: 600;
        font-size: 13px;
        color: #1a1a2e;
        margin: 0;
        display: block;
    }

    .adv-help {
        font-size: 11px;
        color: #6c757d;
        margin-top: 2px;
    }

    .adv-row input[type="checkbox"] {
        width: 20px;
        height: 20px;
        cursor: pointer;
    }

    @media (max-width: 700px) {
        .adv-row {
            grid-template-columns: 1fr;
            gap: 6px;
        }
    }
</style>