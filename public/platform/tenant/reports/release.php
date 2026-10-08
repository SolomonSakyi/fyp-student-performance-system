<?php

/**
 * Tenant Report Release Detail — per-student outcomes of one release.
 *
 * @package Student 360
 * @subpackage Platform\Tenant\Reports
 * @version 1.1
 * @filepath public/platform/tenant/reports/release.php
 *
 * v1.1 change (2026-10-07) [JS SYNTAX FIX]:
 *   - Replaced `const el;` with `let el;` inside loadUserInfo(). The
 *     former is invalid JavaScript and breaks the whole script block.
 *     No other line changes.
 *
 * WHAT THIS PAGE DOES:
 *   - Requires a tenant session (require_tenant()).
 *   - Reads id from $_GET — the report_releases.id.
 *   - Loads the release header row for the current tenant.
 *   - Loads the per-student rows from report_release_students,
 *     joined to students, ordered by roll number then name.
 *   - Counts notifications written for this release, grouped by
 *     recipient_role and channel, from the notifications table
 *     where related_type = 'report_release' and related_id = the
 *     release id.
 *   - Renders a summary header, a channel breakdown, and a
 *     per-student table with status pills and skip reasons.
 *
 * WHAT THIS PAGE DOES NOT DO:
 *   - It does not edit anything. No POST, no CSRF, no audit.
 *   - It does not re-release. That is index.php's release actions.
 *   - It does not send notifications.
 *
 * LOCKED DECISIONS:
 *   - Tenant scoping on every query: tenant_id = ?.
 *   - Soft deletes: deleted_at IS NULL where the table carries it.
 *   - Read-only.
 */

$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/app/bootstrap.php';
require_tenant();

$tenantId     = current_tenant_id();
$schoolId     = (int)($_SESSION['school_id'] ?? 0);
$userId       = current_user_id();
$currentUser  = $_SESSION['user_name'] ?? 'Admin';
$userAvatar   = substr($currentUser, 0, 1);
$isSuperAdmin = is_super_admin();

$currentPage = 'reports';

$db = DatabaseHelper::getInstance();

// ============================================
// Resolve the release id
// ============================================
$releaseId = (int)($_GET['id'] ?? 0);
if ($releaseId <= 0) {
    header('Location: /platform/tenant/reports/index.php');
    exit;
}

$release = $db->fetchOne(
    "SELECT rr.id, rr.uuid, rr.tenant_id, rr.school_id,
            rr.academic_year_id, rr.academic_term_id,
            rr.class_id, rr.class_offering_id,
            rr.report_kind, rr.channels_used, rr.status,
            rr.total_students, rr.total_sent, rr.total_skipped, rr.total_failed,
            rr.notes, rr.released_by, rr.released_at, rr.completed_at,
            rr.created_at,
            c.class_name, c.class_code,
            ay.year_name,
            at.term_name,
            s.stream_name,
            p.first_name AS released_by_first,
            p.last_name  AS released_by_last
       FROM report_releases rr
       LEFT JOIN classes c ON c.id = rr.class_id
       LEFT JOIN academic_years ay ON ay.id = rr.academic_year_id
       LEFT JOIN academic_terms at ON at.id = rr.academic_term_id
       LEFT JOIN class_offerings co ON co.id = rr.class_offering_id
       LEFT JOIN streams s ON s.id = co.stream_id
       LEFT JOIN platform_users pu ON pu.id = rr.released_by
       LEFT JOIN persons p ON p.id = pu.person_id
      WHERE rr.id = ? AND rr.tenant_id = ? AND rr.deleted_at IS NULL
      LIMIT 1",
    [$releaseId, $tenantId]
);

if (!$release) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Not found</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <style>body{font-family:system-ui,sans-serif;background:#f0f2f5;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}.nf-card{background:#fff;border-radius:16px;padding:40px;max-width:480px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.08)}.nf-card i{font-size:48px;color:#dc3545;margin-bottom:16px;display:block}.nf-card h1{font-size:22px;font-weight:700;color:#1a1a2e;margin-bottom:8px}.nf-card p{color:#6c757d;font-size:14px;margin:0 0 16px}.nf-card a{color:#0d6efd;text-decoration:none;font-weight:600}</style>
    </head>
    <body>
        <div class="nf-card">
            <i class="fas fa-user-slash"></i>
            <h1>Not found</h1>
            <p>The release record is not available in this tenant.</p>
            <a href="/platform/tenant/reports/index.php"><i class="fas fa-arrow-left me-1"></i> Back to Reports</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================
// Load per-student outcomes
// ============================================
$studentRows = $db->fetchAll(
    "SELECT rrs.id, rrs.release_id, rrs.student_id,
            rrs.status, rrs.skipped_reason,
            rrs.recipients_count, rrs.notifications_written,
            rrs.sent_at,
            st.student_number,
            st.first_name, st.middle_name, st.last_name, st.prefer
ed_name,
            e.roll_number
       FROM report_release_students rrs
       JOIN students st ON st.id = rrs.student_id AND st.deleted_at IS NULL
       LEFT JOIN enrollments e
            ON e.tenant_id = rrs.tenant_id
           AND e.student_id = rrs.student_id
           AND e.class_offering_id = ?
           AND e.status = 'active'
           AND e.deleted_at IS NULL
      WHERE rrs.tenant_id = ?
        AND rrs.release_id = ?
        AND rrs.deleted_at IS NULL
      ORDER BY
            CASE WHEN e.roll_number IS NULL OR e.roll_number = '' THEN 1 ELSE 0 END,
            CAST(e.roll_number AS UNSIGNED) ASC,
            st.first_name ASC, st.last_name ASC",
    [
        (int)($release['class_offering_id'] ?? 0),
        $tenantId,
        $releaseId,
    ]
);

// ============================================
// Channel breakdown from notifications
// ============================================
$channelRows = [];
try {
    $channelRows = $db->fetchAll(
        "SELECT channel, COUNT(*) AS c
           FROM notifications
          WHERE tenant_id = ?
            AND related_type = 'report_release'
            AND related_id = ?
            AND deleted_at IS NULL
          GROUP BY channel",
        [$tenantId, $releaseId]
    );
} catch (Exception $e) {
    $channelRows = [];
}
$channelBreakdown = ['inapp' => 0, 'email' => 0, 'sms' => 0];
$totalNotifications = 0;
foreach ($channelRows as $cr) {
    $ch = (string)$cr['channel'];
    $count = (int)$cr['c'];
    if (array_key_exists($ch, $channelBreakdown)) {
        $channelBreakdown[$ch] = $count;
    }
    $totalNotifications += $count;
}

// ============================================
// Tenant name
// ============================================
$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

// ============================================
// Small helpers
// ============================================
function studentDisplay(array $s): string
{
    $pref = trim((string)($s['preferred_name'] ?? ''));
    if ($pref !== '') return $pref;
    $full = trim(
        ($s['first_name'] ?? '') . ' ' .
        ($s['middle_name'] ?? '') . ' ' .
        ($s['last_name'] ?? '')
    );
    $full = preg_replace('/\s+/', ' ', $full);
    return $full !== '' ? $full : ('Student #' . (int)($s['student_id'] ?? 0));
}

function releaseStatusPill(string $s): string
{
    $map = [
        'pending'    => 'gray',
        'processing' => 'blue',
        'completed'  => 'green',
        'partial'    => 'orange',
        'failed'     => 'red',
        'sent'       => 'green',
        'skipped'    => 'gray',
    ];
    return $map[$s] ?? 'gray';
}

$pageTitle = 'Release #' . (int)$release['id'] . ' - Student 360 Platform';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="<?php echo h(csrf_token()); ?>">
    <title><?php echo h($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { margin: 0; padding: 0; overflow-x: hidden !important; width: 100%; max-width: 100%; background: #f0f2f5; font-family: 'Inter', sans-serif; font-size: 14px; line-height: 1.6; color: #1a1a2e; }
        .container-fluid { padding: 0; margin: 0; width: 100%; max-width: 100%; overflow-x: hidden; }
        .row { margin: 0; width: 100%; max-width: 100%; }
        [class*="col-"] { padding-left: 12px; padding-right: 12px; }

        .sidebar-toggle { display: none; position: fixed; top: 14px; left: 14px; z-index: 1001; background: #1a1a2e; color: #fff; border: none; border-radius: 10px; padding: 10px 14px; font-size: 22px; cursor: pointer; box-shadow: 0 2px 15px rgba(0,0,0,0.2); }
        .sidebar-toggle:hover { background: #2a2a4e; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%); color: #fff; position: fixed; width: 260px; left: 0; top: 0; z-index: 1000; transition: transform 0.3s ease; overflow-y: auto; padding: 0; }
        .sidebar .sidebar-header { padding: 25px 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .sidebar .sidebar-header h4 { font-weight: 700; font-size: 20px; margin: 0; }
        .sidebar .sidebar-header h4 i { color: #4facfe; }
        .sidebar .sidebar-header small { color: rgba(255,255,255,0.4); font-size: 12px; }
        .sidebar .nav { padding: 16px 12px; }
        .sidebar .nav-label { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: rgba(255,255,255,0.3); padding: 0 12px 8px; font-weight: 600; }
        .sidebar .nav-link { color: rgba(255,255,255,0.6); padding: 10px 16px; border-radius: 10px; margin: 2px 0; transition: all 0.3s; font-size: 14px; font-weight: 500; display: flex; align-items: center; text-decoration: none; }
        .sidebar .nav-link:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .sidebar .nav-link.active { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: #fff; box-shadow: 0 4px 15px rgba(79,172,254,0.3); }
        .sidebar .nav-link i { width: 22px; text-align: center; margin-right: 12px; font-size: 15px; }
        .sidebar .nav-sub { padding-left: 24px; }
        .sidebar .nav-sub .nav-link { font-size: 13px; padding: 8px 14px; color: rgba(255,255,255,0.55); }
        .sidebar .nav-sub .nav-link.active { background: rgba(79,172,254,0.18); color: #fff; box-shadow: none; }
        .sidebar .nav-subgroup-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: rgba(255,255,255,0.35); padding: 8px 14px 2px; }
        .sidebar .sidebar-footer { position: absolute; bottom: 0; width: 100%; padding: 20px 24px; border-top: 1px solid rgba(255,255,255,0.08); background: rgba(0,0,0,0.2); }
        .sidebar .sidebar-footer .user-info { display: flex; align-items: center; gap: 12px; }
        .sidebar .sidebar-footer .user-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #4facfe, #00f2fe); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px; color: #fff; flex-shrink: 0; }
        .sidebar .sidebar-footer .user-name { font-weight: 600; font-size: 14px; }
        .sidebar .sidebar-footer .user-role { font-size: 11px; color: rgba(255,255,255,0.4); }
        .sidebar .sidebar-footer .logout-btn { color: rgba(255,255,255,0.4); background: none; border: none; padding: 0; cursor: pointer; font-size: 14px; }
        .sidebar .sidebar-footer .logout-btn:hover { color: #ff6b6b; }

        .main-content { margin-left: 260px; padding: 24px 32px 40px; background: #f0f2f5; min-height: 100vh; width: calc(100% - 260px); max-width: 100%; overflow-x: hidden; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; padding: 0 0 24px 0; flex-wrap: wrap; gap: 10px; }
        .top-bar .page-title h1 { font-size: 28px; font-weight: 800; color: #1a1a2e; margin: 0; letter-spacing: -0.5px; }
        .top-bar .page-title h1 i { color: #4facfe; }
        .top-bar .page-title p { color: #6c757d; margin: 0; font-size: 14px; }
        .top-bar .header-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .top-bar .header-actions .btn { border-radius: 12px; padding: 8px 20px; font-weight: 500; font-size: 13px; }

        .btn-outline-secondary { background: transparent; border: 2px solid #e9ecef; color: #6c757d; }
        .btn-outline-secondary:hover { background: #f8f9fa; border-color: #ced4da; color: #495057; }

        .tenant-banner { background: #fff; border-radius: 14px; padding: 16px 24px; margin-bottom: 20px; border: 2px solid #4facfe; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; max-width: 1200px; margin-left: auto; margin-right: auto; }
        .tenant-banner .tenant-info { display: flex; align-items: center; gap: 12px; }
        .tenant-banner .tenant-info i { font-size: 24px; color: #4facfe; }
        .tenant-banner .tenant-info .tenant-name { font-weight: 600; font-size: 16px; color: #1a1a2e; }
        .tenant-banner .tenant-badge { background: #e3f0ff; color: #0d6efd; padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: 500; }

        .card-custom { background: #fff; border-radius: 16px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); border: 1px solid rgba(0,0,0,0.03); margin-bottom: 24px; overflow: hidden; width: 100%; max-width: 1200px; margin-left: auto; margin-right: auto; }
        .card-custom .card-header-custom { padding: 16px 24px; border-bottom: 1px solid #f0f2f5; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        .card-custom .card-header-custom h6 { font-size: 15px; font-weight: 700; margin: 0; color: #1a1a2e; display: flex; align-items: center; }
        .card-custom .card-body-custom { padding: 20px 24px; }

        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
        .summary-cell { background: #fafbfc; border: 1px solid #eef0f3; border-radius: 10px; padding: 12px 14px; text-align: center; }
        .summary-cell .s-lbl { font-size: 11px; text-transform: uppercase; color: #6c757d; font-weight: 600; letter-spacing: 0.4px; }
        .summary-cell .s-val { font-size: 24px; font-weight: 700; color: #1a1a2e; font-family: 'Courier New', monospace; margin-top: 4px; }
        .summary-cell.green .s-val { color: #166534; }
        .summary-cell.red .s-val { color: #991b1b; }
        .summary-cell.orange .s-val { color: #c2410c; }
        .summary-cell.blue .s-val { color: #004085; }
        .summary-cell.gray .s-val { color: #495057; }

        table.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.data-table thead th { background: #f8f9fa; padding: 10px 14px; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6c757d; border-bottom: 1px solid #e9ecef; text-align: left; white-space: nowrap; }
        table.data-table tbody td { padding: 10px 14px; vertical-align: middle; border-bottom: 1px solid #f0f2f5; }
        table.data-table tbody tr:last-child td { border-bottom: none; }
        table.data-table tbody tr:hover { background: #fafbfc; }

        .pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .pill.green { background: #d4edda; color: #155724; }
        .pill.gray { background: #e9ecef; color: #495057; }
        .pill.blue { background: #cce5ff; color: #004085; }
        .pill.purple { background: #e8d5f5; color: #6f42c1; }
        .pill.orange { background: #ffe8d9; color: #c2410c; }
        .pill.red { background: #f8d7da; color: #721c24; }
        .pill.teal { background: #d1f2eb; color: #0d5c4a; }

        .empty-state { text-align: center; padding: 60px 20px; color: #6c757d; }
        .empty-state i { font-size: 48px; opacity: 0.3; display: block; margin-bottom: 16px; }
        .empty-state h5 { font-weight: 600; color: #1a1a2e; margin-bottom: 8px; }

        .kv-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px 24px; font-size: 13px; }
        .kv-row { display: flex; gap: 12px; padding: 6px 0; border-bottom: 1px dashed #eef0f3; }
        .kv-row:last-child { border-bottom: none; }
        .kv-row .kv-lbl { width: 130px; flex-shrink: 0; color: #6c757d; font-weight: 500; font-size: 12px; text-transform: uppercase; letter-spacing: 0.3px; }
        .kv-row .kv-val { color: #1a1a2e; font-weight: 500; word-break: break-word; }

        @media (max-width: 992px) {
            .sidebar { width: 72px; overflow: hidden; }
            .sidebar .sidebar-header h4 { font-size: 0; }
            .sidebar .sidebar-header h4 i { font-size: 24px; }
            .sidebar .sidebar-header small { display: none; }
            .sidebar .nav-link span { display: none; }
            .sidebar .nav-link i { margin-right: 0; font-size: 18px; }
            .sidebar .nav-link { text-align: center; padding: 12px; justify-content: center; }
            .sidebar .nav-label { display: none; }
            .sidebar .nav-sub { display: none; }
            .sidebar .sidebar-footer .user-info span { display: none; }
            .sidebar .sidebar-footer .user-info { justify-content: center; }
            .main-content { margin-left: 72px; width: calc(100% - 72px); padding: 20px; }
            .sidebar-toggle { display: none; }
        }
        @media (max-width: 768px) {
            .sidebar-toggle { display: block; }
            .sidebar { transform: translateX(-100%); width: 280px; position: fixed; z-index: 1000; top: 0; left: 0; height: 100vh; overflow-y: auto; }
            .sidebar.open { transform: translateX(0); }
            .sidebar .sidebar-header h4 { font-size: 20px; }
            .sidebar .sidebar-header small { display: block; }
            .sidebar .nav-link span { display: inline; }
            .sidebar .nav-link i { margin-right: 12px; font-size: 15px; }
            .sidebar .nav-link { text-align: left; padding: 10px 16px; justify-content: flex-start; }
            .sidebar .nav-label { display: block; }
            .sidebar .nav-sub { display: block; }
            .sidebar .sidebar-footer .user-info span { display: inline; }
            .sidebar .sidebar-footer .user-info { justify-content: flex-start; }
            .main-content { margin-left: 0; width: 100%; padding: 16px; padding-top: 70px; }
            .top-bar .page-title h1 { font-size: 22px; }
            .kv-row { flex-direction: column; gap: 2px; }
            .kv-row .kv-lbl { width: auto; }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>

            <?php include $projectRoot . '/app/views/partials/sidebar.php'; ?>

            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-paper-plane me-2"></i>Release #<?php echo (int)$release['id']; ?></h1>
                        <p>
                            <?php echo h((string)($release['class_name'] ?? '—')); ?>
                            <?php if (!empty($release['stream_name'])): ?>
                                · <?php echo h((string)$release['stream_name']); ?>
                            <?php endif; ?>
                            · <?php echo h((string)($release['year_name'] ?? '—')); ?>
                            · <?php echo h((string)($release['term_name'] ?? '—')); ?>
                        </p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/reports/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Reports
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo h($tenantName); ?></div>
                            <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                Released <?php echo h((string)($release['released_at'] ?? $release['created_at'])); ?>
                                <?php
                                $releasedBy = trim(
                                    (string)($release['released_by_first'] ?? '') . ' ' .
                                    (string)($release['released_by_last'] ?? '')
                                );
                                ?>
                                <?php if ($releasedBy !== ''): ?>
                                    by <?php echo h($releasedBy); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div>
                        <span class="pill <?php echo releaseStatusPill((string)$release['status']); ?>">
                            <?php echo h(ucfirst((string)$release['status'])); ?>
                        </span>
                    </div>
                </div>

                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-info-circle me-2 text-primary"></i>Release header</h6>
                    </div>
                    <div class="card-body-custom">
                        <div class="kv-grid">
                            <div class="kv-row">
                                <div class="kv-lbl">Release ID</div>
                                <div class="kv-val">#<?php echo (int)$release['id']; ?></div>
                            </div>
                            <div class="kv-row">
                                <div class="kv-lbl">UUID</div>
                                <div class="kv-val" style="font-family:'Courier New',monospace;font-size:11px;"><?php echo h((string)$release['uuid']); ?></div>
                            </div>
                            <div class="kv-row">
                                <div class="kv-lbl">Report kind</div>
                                <div class="kv-val"><?php echo h(ucfirst(str_replace('_', ' ', (string)$release['report_kind']))); ?></div>
                            </div>
                            <div class="kv-row">
                                <div class="kv-lbl">Channels</div>
                                <div class="kv-val">
                                    <?php foreach (explode(',', (string)$release['channels_used']) as $ch): ?>
                                        <span class="pill blue" style="margin-right:4px;"><?php echo h($ch); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="kv-row">
                                <div class="kv-lbl">Year</div>
                                <div class="kv-val"><?php echo h((string)($release['year_name'] ?? '—')); ?></div>
                            </div>
                            <div class="kv-row">
                                <div class="kv-lbl">Term</div>
                                <div class="kv-val"><?php echo h((string)($release['term_name'] ?? '—')); ?></div>
                            </div>
                            <div class="kv-row">
                                <div class="kv-lbl">Class</div>
                                <div class="kv-val">
                                    <?php echo h((string)($release['class_name'] ?? '—')); ?>
                                    <?php if (!empty($release['class_code'])): ?>
                                        · <?php echo h((string)$release['class_code']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="kv-row">
                                <div class="kv-lbl">Released at</div>
                                <div class="kv-val"><?php echo h((string)($release['released_at'] ?? '—')); ?></div>
                            </div>
                            <div class="kv-row">
                                <div class="kv-lbl">Completed at</div>
                                <div class="kv-val"><?php echo h((string)($release['completed_at'] ?? '—')); ?></div>
                            </div>
                            <?php if (!empty($release['notes'])): ?>
                                <div class="kv-row">
                                    <div class="kv-lbl">Notes</div>
                                    <div class="kv-val"><?php echo h((string)$release['notes']); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-chart-bar me-2 text-primary"></i>Outcome summary</h6>
                    </div>
                    <div class="card-body-custom">
                        <div class="summary-grid">
                            <div class="summary-cell blue">
                                <div class="s-lbl">Students</div>
                                <div class="s-val"><?php echo (int)$release['total_students']; ?></div>
                            </div>
                            <div class="summary-cell green">
                                <div class="s-lbl">Notifications sent</div>
                                <div class="s-val"><?php echo (int)$release['total_sent']; ?></div>
                            </div>
                            <div class="summary-cell gray">
                                <div class="s-lbl">Skipped</div>
                                <div class="s-val"><?php echo (int)$release['total_skipped']; ?></div>
                            </div>
                            <div class="summary-cell red">
                                <div class="s-lbl">Failed</div>
                                <div class="s-val"><?php echo (int)$release['total_failed']; ?></div>
                            </div>
                        </div>

                        <div style="margin-top:16px;padding-top:16px;border-top:1px dashed #eef0f3;">
                            <div style="font-size:12px;text-transform:uppercase;letter-spacing:0.4px;color:#6c757d;font-weight:600;margin-bottom:8px;">
                                Channel breakdown
                            </div>
                            <div class="summary-grid">
                                <div class="summary-cell purple">
                                    <div class="s-lbl">In-app</div>
                                    <div class="s-val"><?php echo (int)$channelBreakdown['inapp']; ?></div>
                                </div>
                                <div class="summary-cell orange">
                                    <div class="s-lbl">Email</div>
                                    <div class="s-val"><?php echo (int)$channelBreakdown['email']; ?></div>
                                </div>
                                <div class="summary-cell blue">
                                    <div class="s-lbl">SMS</div>
                                    <div class="s-val"><?php echo (int)$channelBreakdown['sms']; ?></div>
                                </div>
                                <div class="summary-cell green">
                                    <div class="s-lbl">Total</div>
                                    <div class="s-val"><?php echo (int)$totalNotifications; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-users me-2 text-primary"></i>Per-student outcomes</h6>
                        <span style="font-size:12px;color:#6c757d;"><?php echo count($studentRows); ?> student(s)</span>
                    </div>
                    <?php if (empty($studentRows)): ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <h5>No per-student rows</h5>
                            <p>This release has no student rows recorded.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th style="width:60px;">#</th>
                                        <th style="min-width:150px;">Student #</th>
                                        <th style="min-width:220px;">Name</th>
                                        <th style="width:110px;text-align:center;">Roll #</th>
                                        <th style="text-align:center;">Status</th>
                                        <th style="text-align:center;">Guardians</th>
                                        <th style="text-align:center;">Notifications</th>
                                        <th style="min-width:200px;">Skip reason</th>
                                        <th style="min-width:170px;">Sent at</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i = 0;
                                    foreach ($studentRows as $s):
                                        $i++;
                                        $stStatus = (string)$s['status'];
                                        $recipients = (int)$s['recipients_count'];
                                        $sentCount  = (int)$s['notifications_written'];
                                        $skipReason = trim((string)($s['skipped_reason'] ?? ''));
                                    ?>
                                        <tr>
                                            <td><?php echo h((string)$i); ?></td>
                                            <td style="font-family:'Courier New',monospace;font-size:12px;color:#0d6efd;">
                                                <?php echo h((string)$s['student_number']); ?>
                                            </td>
                                            <td>
                                                <div style="font-weight:600;color:#1a1a2e;"><?php echo h(studentDisplay($s)); ?></div>
                                            </td>
                                            <td style="text-align:center;">
                                                <?php echo h((string)($s['roll_number'] ?? '—')); ?>
                                            </td>
                                            <td style="text-align:center;">
                                                <span class="pill <?php echo releaseStatusPill($stStatus); ?>">
                                                    <?php echo h(ucfirst($stStatus)); ?>
                                                </span>
                                            </td>
                                            <td style="text-align:center;font-weight:600;"><?php echo $recipients; ?></td>
                                            <td style="text-align:center;font-weight:600;color:<?php echo $sentCount > 0 ? '#166534' : '#991b1b'; ?>;">
                                                <?php echo $sentCount; ?>
                                            </td>
                                            <td style="font-size:12px;color:#6c757d;">
                                                <?php echo $skipReason !== '' ? h($skipReason) : '—'; ?>
                                            </td>
                                            <td style="font-size:12px;color:#6c757d;font-family:'Courier New',monospace;">
                                                <?php echo h((string)($s['sent_at'] ?? '—')); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.getElementById('sidebarToggle');
            if (window.innerWidth <= 768 && sidebar && toggle) {
                if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 768 && sidebar) sidebar.classList.remove('open');
        });
        function logout() {
            if (confirm('Are you sure you want to logout?')) window.location.href = '/platform/tenant/logout.php';
        }
        function loadUserInfo() {
            const userStr = localStorage.getItem('user');
            if (userStr) {
                try {
                    const user = JSON.parse(userStr);
                    let el;
                    if ((el = document.getElementById('userName')))   el.textContent = user.first_name || 'Admin';
                    if ((el = document.getElementById('userAvatar'))) el.textContent = (user.first_name || 'A').charAt(0);
                    if ((el = document.getElementById('userRole')))   el.textContent = (user.roles || ['Administrator'])[0];
                } catch (e) {}
            }
        }
        document.addEventListener('DOMContentLoaded', loadUserInfo);
    </script>
</body>

</html>