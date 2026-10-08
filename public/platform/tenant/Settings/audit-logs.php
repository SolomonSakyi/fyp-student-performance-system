<?php

/**
 * Settings / Audit Logs tab body.
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Settings
 * @version 1.0
 * @filepath public/platform/tenant/settings/audit-logs.php
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
 * - Reads the most recent settings_audit_log rows for the current
 *   tenant, 50 per page, ordered by id DESC.
 * - Renders them as a table with columns: ID, When, Action, Group,
 *   Key, Old -> New, By, IP.
 * - Renders Previous / Next pagination links.
 * - Renders an Export CSV button that links to
 *   ?endpoint=settings&action=export_audit_logs. That endpoint is a
 *   browser-initiated download; the session cookie travels normally,
 *   so no loopback is involved.
 *
 * WHAT THIS FILE DOES NOT DO:
 * - It does not render the shell chrome; the shell does.
 * - It does not implement any other tab; each tab is its own file.
 * - It does not write to settings_audit_log or any other table. It
 *   is read-only.
 * - It does not filter by school_id or campus_id. Those columns exist
 *   on the table but the audit UI is tenant-scoped.
 *
 * DDL (from earlier this session):
 *   settings_audit_log(id, uuid, tenant_id, school_id, campus_id,
 *     user_id, setting_group, section, setting_key, action,
 *     old_value, new_value, ip_address, user_agent, created_at)
 *   Both `setting_group` and `section` exist by design. This file
 *   reads `setting_group` for the "Group" column and does not read
 *   `section`; a row that was written with `section` set and
 *   `setting_group` NULL will show "-" in the Group column.
 */

// =============================================
// GUARD: must be included by the shell
// =============================================
if (!isset($db) || !isset($tenantId)) {
    http_response_code(500);
    echo '<div class="alert alert-danger">Audit Logs tab loaded out of context.</div>';
    return;
}

// =============================================
// LOCAL HELPERS
// =============================================
if (!function_exists('h_tab')) {
    function h_tab($s)
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// =============================================
// PAGINATION
// =============================================
$perPage = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}
$offset = ($page - 1) * $perPage;

// =============================================
// LOAD ROWS
// =============================================
$rows = [];
$total = 0;
$loadError = null;

try {
    $countRow = $db->fetchOne(
        "SELECT COUNT(*) AS c FROM settings_audit_log WHERE tenant_id = ?",
        [(int)$tenantId]
    );
    $total = (int)($countRow['c'] ?? 0);
} catch (Throwable $e) {
    error_log('audit-logs.php count error: ' . $e->getMessage());
    $loadError = 'Could not count audit rows: ' . $e->getMessage();
}

if ($loadError === null) {
    try {
        $rows = $db->fetchAll(
            "SELECT id, user_id, setting_group, section, setting_key, action,
                    old_value, new_value, ip_address, user_agent, created_at
             FROM settings_audit_log
             WHERE tenant_id = ?
             ORDER BY id DESC
             LIMIT " . (int)$perPage . " OFFSET " . (int)$offset,
            [(int)$tenantId]
        );
        if (!is_array($rows)) {
            $rows = [];
        }
    } catch (Throwable $e) {
        error_log('audit-logs.php list error: ' . $e->getMessage());
        $loadError = 'Could not load audit rows: ' . $e->getMessage();
        $rows = [];
    }
}

$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
$prevPage = $page > 1 ? $page - 1 : null;
$nextPage = $page < $totalPages ? $page + 1 : null;

// =============================================
// DERIVE A SHORT PREVIEW OF OLD / NEW
// =============================================
if (!function_exists('audit_short_value')) {
    function audit_short_value($raw, int $limit = 60): string
    {
        $s = (string)$raw;
        if ($s === '') {
            return '';
        }
        // Values are JSON-encoded by the writer. Try to decode and
        // pull a short scalar; fall back to the raw string.
        $decoded = json_decode($s, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (is_array($decoded)) {
                if (array_key_exists('value', $decoded)) {
                    $s = is_scalar($decoded['value'])
                        ? (string)$decoded['value']
                        : json_encode($decoded['value']);
                } else {
                    $s = json_encode($decoded);
                }
            } elseif (is_scalar($decoded)) {
                $s = (string)$decoded;
            }
        }
        if (strlen($s) > $limit) {
            $s = substr($s, 0, $limit) . '…';
        }
        return $s;
    }
}
?>
<div>
    <?php if ($loadError): ?>
        <div class="alert-pro error">
            <div class="ap-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="ap-body">
                <div class="ap-title">Load failed</div>
                <div class="ap-text"><?php echo h_tab($loadError); ?></div>
            </div>
        </div>
    <?php endif; ?>

    <div style="background:#fff;border-radius:14px;border:1px solid rgba(0,0,0,0.03);box-shadow:0 2px 8px rgba(0,0,0,0.04);margin-bottom:20px;overflow:hidden;">
        <div style="padding:16px 24px;border-bottom:1px solid #f0f2f5;background:linear-gradient(135deg,#f8fafc 0%,#eef6ff 100%);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <h6 style="font-size:14px;font-weight:700;margin:0;color:#1a1a2e;">
                <i class="fas fa-history me-2 text-primary"></i>Settings Audit Log
            </h6>
            <div style="display:flex;align-items:center;gap:10px;">
                <span style="font-size:12px;color:#6c757d;"><?php echo (int)$total; ?> row(s)</span>
                <a href="/api/platform/index.php?endpoint=settings&action=export_audit_logs"
                    class="btn btn-outline-secondary btn-sm"
                    style="border:2px solid #e9ecef;border-radius:8px;padding:4px 12px;font-size:12px;text-decoration:none;color:#6c757d;">
                    <i class="fas fa-download me-1"></i> Export CSV
                </a>
            </div>
        </div>

        <?php if (empty($rows)): ?>
            <div style="padding:40px 24px;text-align:center;color:#6c757d;font-size:13px;">
                No audit rows yet for this tenant.
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="background:#f8f9fa;color:#6c757d;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">
                            <th style="padding:10px 12px;text-align:left;white-space:nowrap;">ID</th>
                            <th style="padding:10px 12px;text-align:left;white-space:nowrap;">When</th>
                            <th style="padding:10px 12px;text-align:left;">Action</th>
                            <th style="padding:10px 12px;text-align:left;">Group</th>
                            <th style="padding:10px 12px;text-align:left;">Key</th>
                            <th style="padding:10px 12px;text-align:left;">Old → New</th>
                            <th style="padding:10px 12px;text-align:center;white-space:nowrap;">By</th>
                            <th style="padding:10px 12px;text-align:left;">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r):
                            $oldPreview = audit_short_value($r['old_value'] ?? '');
                            $newPreview = audit_short_value($r['new_value'] ?? '');
                            $group = (string)($r['setting_group'] ?? '');
                            if ($group === '') {
                                $section = (string)($r['section'] ?? '');
                                $group = $section !== '' ? $section . ' (section)' : '';
                            }
                        ?>
                            <tr style="border-top:1px solid #f0f2f5;">
                                <td style="padding:9px 12px;font-family:'Courier New',monospace;color:#6c757d;white-space:nowrap;">
                                    #<?php echo h_tab((string)($r['id'] ?? '')); ?>
                                </td>
                                <td style="padding:9px 12px;color:#6c757d;white-space:nowrap;">
                                    <?php echo h_tab((string)($r['created_at'] ?? '')); ?>
                                </td>
                                <td style="padding:9px 12px;color:#1a1a2e;">
                                    <?php echo h_tab((string)($r['action'] ?? '')); ?>
                                </td>
                                <td style="padding:9px 12px;color:#1a1a2e;">
                                    <?php echo $group !== '' ? h_tab($group) : '—'; ?>
                                </td>
                                <td style="padding:9px 12px;font-family:'Courier New',monospace;color:#0d6efd;">
                                    <?php echo h_tab((string)($r['setting_key'] ?? '—')); ?>
                                </td>
                                <td style="padding:9px 12px;color:#495057;">
                                    <?php if ($oldPreview === '' && $newPreview === ''): ?>
                                        —
                                    <?php else: ?>
                                        <span style="color:#991b1b;"><?php echo h_tab($oldPreview !== '' ? $oldPreview : '∅'); ?></span>
                                        <span style="color:#6c757d;"> → </span>
                                        <span style="color:#166534;"><?php echo h_tab($newPreview !== '' ? $newPreview : '∅'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:9px 12px;text-align:center;color:#6c757d;">
                                    <?php echo $r['user_id'] !== null ? h_tab((string)$r['user_id']) : '—'; ?>
                                </td>
                                <td style="padding:9px 12px;font-family:'Courier New',monospace;color:#6c757d;">
                                    <?php echo h_tab((string)($r['ip_address'] ?? '—')); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div style="padding:14px 24px;border-top:1px solid #f0f2f5;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                    <span style="font-size:12px;color:#6c757d;">
                        Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?>
                    </span>
                    <div style="display:flex;gap:8px;">
                        <?php if ($prevPage !== null): ?>
                            <a href="/platform/tenant/settings/index.php?tab=audit-logs&page=<?php echo (int)$prevPage; ?>"
                                class="btn btn-outline-secondary btn-sm"
                                style="border:2px solid #e9ecef;border-radius:8px;padding:4px 12px;font-size:12px;text-decoration:none;color:#6c757d;">
                                <i class="fas fa-chevron-left me-1"></i> Previous
                            </a>
                        <?php else: ?>
                            <span class="btn btn-outline-secondary btn-sm disabled"
                                style="border:2px solid #f0f2f5;border-radius:8px;padding:4px 12px;font-size:12px;color:#ced4da;">
                                <i class="fas fa-chevron-left me-1"></i> Previous
                            </span>
                        <?php endif; ?>
                        <?php if ($nextPage !== null): ?>
                            <a href="/platform/tenant/settings/index.php?tab=audit-logs&page=<?php echo (int)$nextPage; ?>"
                                class="btn btn-outline-secondary btn-sm"
                                style="border:2px solid #e9ecef;border-radius:8px;padding:4px 12px;font-size:12px;text-decoration:none;color:#6c757d;">
                                Next <i class="fas fa-chevron-right ms-1"></i>
                            </a>
                        <?php else: ?>
                            <span class="btn btn-outline-secondary btn-sm disabled"
                                style="border:2px solid #f0f2f5;border-radius:8px;padding:4px 12px;font-size:12px;color:#ced4da;">
                                Next <i class="fas fa-chevron-right ms-1"></i>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>