<?php

/**
 * Settings / Domains tab body.
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Settings
 * @version 1.1
 * @filepath public/platform/tenant/settings/domains.php
 *
 * v1.1 change (2026-10-05):
 *   The tenant_domains list block's SELECT was rewritten against the
 *   live table DDL. The previous version selected a column named
 *   ssl_enabled, which does not exist on the live table. MySQL
 *   raised SQLSTATE[42S22] Column not found 1054, and the list block
 *   fell through to the "Could not load the tenant_domains list"
 *   note. The live table's 12 columns are:
 *     id, tenant_id, domain_name, is_primary, is_custom,
 *     is_verified, verification_token, is_active, created_at,
 *     updated_at, deleted_at, school_id.
 *   The SELECT now reads five of those: id, domain_name,
 *   is_primary, is_custom, is_verified, is_active, created_at.
 *   The list table's columns changed from
 *     Domain | Primary | Verified | SSL | Created
 *   to
 *     Domain | Type | Verified | Active | Created.
 *   There is no SSL column because the live table has no SSL
 *   column. The tenant-level ssl_enabled setting is still written
 *   and read by the form section; it lives in tenant_settings, a
 *   separate table from tenant_domains. The form section is
 *   byte-identical to v1.0.
 *
 * v1.0 change (2026-10-05):
 *   Initial version. Two sections: the domain settings form (five
 *   keys in tenant_settings) and a read-only list of the tenant's
 *   rows in tenant_domains.
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
 * - Loads the five domain settings by calling
 *   SettingsService::getSettings('domains', $tenantId).
 * - Renders a form with the five keys the controller accepts.
 * - On save, calls
 *   SettingsService::saveSettings($payload, 'domains', $tenantId, $userId).
 * - Loads and renders a read-only list of this tenant's rows from
 *   tenant_domains (domain_name, is_primary, is_custom, is_verified,
 *   is_active, created_at).
 *
 * WHAT THIS FILE DOES NOT DO:
 * - It does not render the shell chrome; the shell does.
 * - It does not implement any other tab; each tab is its own file.
 * - It does not make any HTTP call.
 * - It does not create, edit or delete rows in tenant_domains. The
 *   domain list is read-only here; the platform-side domain admin
 *   surface owns the CRUD.
 *
 * FIELD TYPES (per the accepted rule):
 * - domain_verified : checkbox
 * - ssl_enabled     : checkbox
 * - everything else : text
 */

// =============================================
// GUARD: must be included by the shell
// =============================================
if (!isset($db) || !isset($tenantId)) {
    http_response_code(500);
    echo '<div class="alert alert-danger">Domains tab loaded out of context.</div>';
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
$domainKeys = [
    'primary_domain',
    'custom_domain',
    'subdomain',
    'domain_verified',
    'ssl_enabled',
];

$checkboxKeys = [
    'domain_verified',
    'ssl_enabled',
];

$flashError = null;
$flashSuccess = null;

// =============================================
// HANDLE POST (save) — direct service call
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_domains') {
    csrf_tab_verify();
    try {
        $payload = [];
        foreach ($domainKeys as $k) {
            if (in_array($k, $checkboxKeys, true)) {
                $payload[$k] = isset($_POST[$k]) && $_POST[$k] !== '' ? '1' : '0';
            } elseif (array_key_exists($k, $_POST)) {
                $payload[$k] = trim((string)$_POST[$k]);
            }
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $svc = new SettingsService();
        $svc->saveSettings($payload, 'domains', (int)$tenantId, $userId);
        $flashSuccess = 'Domain settings saved.';
    } catch (Throwable $e) {
        error_log('domains.php save error: ' . $e->getMessage());
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
    $data = $svc->getSettings('domains', (int)$tenantId);
    if (!is_array($data)) {
        $data = [];
    }
    foreach ($domainKeys as $k) {
        $values[$k] = isset($data[$k]) ? (string)$data[$k] : '';
    }
} catch (Throwable $e) {
    error_log('domains.php load error: ' . $e->getMessage());
    $loadError = 'Could not load domain settings: ' . $e->getMessage();
    foreach ($domainKeys as $k) {
        $values[$k] = '';
    }
}

// =============================================
// DEFAULTS FOR EMPTY VALUES
// =============================================
$defaults = [
    'primary_domain'  => '',
    'custom_domain'   => '',
    'subdomain'       => '',
    'domain_verified' => '0',
    'ssl_enabled'     => '0',
];
foreach ($defaults as $k => $v) {
    if (!isset($values[$k]) || $values[$k] === '') {
        $values[$k] = $v;
    }
}

// =============================================
// LOAD TENANT DOMAINS LIST (best-effort)
// =============================================
// [v1.1] SELECT rewritten against the live tenant_domains DDL.
// Columns read: id, domain_name, is_primary, is_custom,
// is_verified, is_active, created_at. The column ssl_enabled does
// not exist on tenant_domains and is not selected.
$domainList = [];
$domainListError = null;
try {
    $domainList = $db->fetchAll(
        "SELECT id, domain_name, is_primary, is_custom, is_verified, is_active, created_at
         FROM tenant_domains
         WHERE tenant_id = ? AND deleted_at IS NULL
         ORDER BY is_primary DESC, id ASC",
        [(int)$tenantId]
    );
    if (!is_array($domainList)) {
        $domainList = [];
    }
} catch (Throwable $e) {
    error_log('domains.php tenant_domains load error: ' . $e->getMessage());
    $domainListError = 'Could not load the tenant_domains list: ' . $e->getMessage();
    $domainList = [];
}

// =============================================
// FIELD DEFINITIONS
// =============================================
$fields = [
    'primary_domain' => [
        'label'       => 'Primary Domain',
        'type'        => 'text',
        'placeholder' => 'school.example.com',
        'help'        => 'The canonical hostname this tenant is served from.',
    ],
    'custom_domain' => [
        'label'       => 'Custom Domain',
        'type'        => 'text',
        'placeholder' => 'portal.myschool.edu.gh',
        'help'        => 'A user-provided domain, if the tenant has one.',
    ],
    'subdomain' => [
        'label'       => 'Subdomain',
        'type'        => 'text',
        'placeholder' => 'myschool',
        'help'        => 'The subdomain under the platform base domain.',
    ],
    'domain_verified' => [
        'label' => 'Domain Verified',
        'type'  => 'checkbox',
        'help'  => 'Set when the domain ownership check has passed.',
    ],
    'ssl_enabled' => [
        'label' => 'SSL Enabled',
        'type'  => 'checkbox',
        'help'  => 'Serve this tenant over HTTPS. Stored in tenant_settings (this form), not in tenant_domains.',
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

    <form method="POST" action="/platform/tenant/settings/index.php?tab=domains" autocomplete="off">
        <?php echo csrf_tab_field(); ?>
        <input type="hidden" name="action" value="save_domains">
        <div style="background:#fff;border-radius:14px;border:1px solid rgba(0,0,0,0.03);box-shadow:0 2px 8px rgba(0,0,0,0.04);margin-bottom:20px;overflow:hidden;">
            <div style="padding:16px 24px;border-bottom:1px solid #f0f2f5;background:linear-gradient(135deg,#f8fafc 0%,#eef6ff 100%);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <h6 style="font-size:14px;font-weight:700;margin:0;color:#1a1a2e;">
                    <i class="fas fa-globe me-2 text-primary"></i>Domain Settings
                </h6>
                <span style="font-size:12px;color:#6c757d;"><?php echo count($domainKeys); ?> keys</span>
            </div>
            <div style="padding:20px 24px;">
                <?php foreach ($fields as $key => $meta):
                    $value = $values[$key] ?? '';
                    $type  = $meta['type'];
                    $id    = 'dom_' . $key;
                ?>
                    <div class="dom-row">
                        <div>
                            <label for="<?php echo h_tab($id); ?>" class="dom-lbl">
                                <?php echo h_tab($meta['label']); ?>
                            </label>
                            <?php if (!empty($meta['help'])): ?>
                                <div class="dom-help"><?php echo h_tab($meta['help']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if ($type === 'checkbox'): ?>
                                <input type="checkbox"
                                    id="<?php echo h_tab($id); ?>"
                                    name="<?php echo h_tab($key); ?>"
                                    value="1"
                                    <?php echo $value === '1' ? 'checked' : ''; ?>>
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
                <a href="/platform/tenant/settings/index.php?tab=domains" class="btn btn-outline-secondary">
                    <i class="fas fa-undo me-1"></i> Reset
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i> Save Domains
                </button>
            </div>
        </div>
    </form>

    <div style="background:#fff;border-radius:14px;border:1px solid rgba(0,0,0,0.03);box-shadow:0 2px 8px rgba(0,0,0,0.04);margin-bottom:20px;overflow:hidden;">
        <div style="padding:16px 24px;border-bottom:1px solid #f0f2f5;background:linear-gradient(135deg,#f8fafc 0%,#eef6ff 100%);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <h6 style="font-size:14px;font-weight:700;margin:0;color:#1a1a2e;">
                <i class="fas fa-list me-2 text-primary"></i>Registered Domains
            </h6>
            <span style="font-size:12px;color:#6c757d;"><?php echo count($domainList); ?> row(s)</span>
        </div>
        <div style="padding:0;">
            <?php if ($domainListError): ?>
                <div style="padding:16px 24px;font-size:13px;color:#991b1b;">
                    <?php echo h_tab($domainListError); ?>
                </div>
            <?php elseif (empty($domainList)): ?>
                <div style="padding:24px;text-align:center;color:#6c757d;font-size:13px;">
                    No rows in <code>tenant_domains</code> for this tenant.
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;">
                        <thead>
                            <tr style="background:#f8f9fa;color:#6c757d;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">
                                <th style="padding:10px 16px;text-align:left;">Domain</th>
                                <th style="padding:10px 16px;text-align:center;">Type</th>
                                <th style="padding:10px 16px;text-align:center;">Verified</th>
                                <th style="padding:10px 16px;text-align:center;">Active</th>
                                <th style="padding:10px 16px;text-align:left;">Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($domainList as $row):
                                $isPrimary = !empty($row['is_primary']);
                                $isCustom  = !empty($row['is_custom']);
                                if ($isPrimary) {
                                    $typeLabel = 'Primary';
                                    $typeColor = '#166534';
                                } elseif ($isCustom) {
                                    $typeLabel = 'Custom';
                                    $typeColor = '#0d6efd';
                                } else {
                                    $typeLabel = '—';
                                    $typeColor = '#adb5bd';
                                }
                            ?>
                                <tr style="border-top:1px solid #f0f2f5;">
                                    <td style="padding:10px 16px;font-family:'Courier New',monospace;color:#1a1a2e;">
                                        <?php echo h_tab((string)($row['domain_name'] ?? '—')); ?>
                                    </td>
                                    <td style="padding:10px 16px;text-align:center;color:<?php echo $typeColor; ?>;">
                                        <?php echo h_tab($typeLabel); ?>
                                    </td>
                                    <td style="padding:10px 16px;text-align:center;">
                                        <?php echo !empty($row['is_verified']) ? '<span style="color:#166534;">●</span>' : '<span style="color:#adb5bd;">—</span>'; ?>
                                    </td>
                                    <td style="padding:10px 16px;text-align:center;">
                                        <?php echo !empty($row['is_active']) ? '<span style="color:#166534;">●</span>' : '<span style="color:#adb5bd;">—</span>'; ?>
                                    </td>
                                    <td style="padding:10px 16px;color:#6c757d;">
                                        <?php echo h_tab((string)($row['created_at'] ?? '—')); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<style>
    .dom-row {
        padding: 12px 0;
        border-bottom: 1px solid #f0f2f5;
        display: grid;
        grid-template-columns: minmax(200px, 1fr) minmax(260px, 2fr);
        gap: 16px;
        align-items: center;
    }

    .dom-row:last-child {
        border-bottom: none;
    }

    .dom-lbl {
        font-weight: 600;
        font-size: 13px;
        color: #1a1a2e;
        margin: 0;
        display: block;
        cursor: pointer;
    }

    .dom-help {
        font-size: 11px;
        color: #6c757d;
        margin-top: 2px;
    }

    .dom-row input[type="checkbox"] {
        width: 20px;
        height: 20px;
        cursor: pointer;
    }

    @media (max-width: 700px) {
        .dom-row {
            grid-template-columns: 1fr;
            gap: 6px;
        }
    }
</style>