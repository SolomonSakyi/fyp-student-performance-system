<?php

/**
 * Guardian Notifications — read-only list of notifications addressed to
 * the logged-in guardian.
 *
 * @package EduTrack
 * @subpackage Platform\Guardian
 * @version 1.0
 * @filepath public/platform/guardian/notifications.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Guardian-surface sweep. Four changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack' to 'Student 360 Platform'.
 *     - @version confirmed at 1.0 per Decision X-3.
 *     - This v1.0 [SWEEP] docblock paragraph was added above the
 *       existing entries.
 *     - The top-bar action link set is normalized across the four
 *       guardian-portal files: Dashboard, Requests, Notifications,
 *       Profile, Logout, in that order. The current page renders
 *       as plain text rather than a link to itself.
 *   Every other line of the file is byte-identical to the previous
 *   v1.0. The guardian portal is a standalone surface (per locked
 *   decision Q6). It does not adopt the tenant sidebar partial
 *   and does not carry the .nav-subgroup-label CSS rule.
 *
 * WHAT THIS PAGE DOES:
 *   - Requires an authenticated guardian session.
 *   - Loads the notifications addressed to the logged-in guardian
 *     from the `notifications` table, filtered by:
 *         user_id        = $_SESSION['user_id']  (platform_users.id)
 *         recipient_role = 'guardian'
 *         tenant_id      = $_SESSION['tenant_id']
 *         deleted_at IS NULL
 *     Most recent first.
 *   - Renders the notifications as a list of cards. Each card shows
 *     the title, message, type, priority, channel, created-at, and
 *     an "Unread" marker when is_read = 0.
 *   - Renders an empty-state when the guardian has no notifications.
 *   - Provides a link back to the guardian dashboard and to logout.
 *
 * AUTHENTICATION MODEL:
 *   Session keys read (written by login.php v2.4):
 *     $_SESSION['logged_in']   = true
 *     $_SESSION['user_type']   = 'guardian'
 *     $_SESSION['user_id']     = platform_users.id  <-- key for this page
 *     $_SESSION['guardian_id'] = the guardians.id  (not used here)
 *     $_SESSION['tenant_id']   = the tenant
 *     $_SESSION['school_id']   = the school
 *
 *   The notifications table is keyed by user_id which holds the
 *   platform_users.id. The guardian's guardians.id is a different
 *   number and is not used to filter this table.
 *
 * WHAT THIS PAGE DOES NOT DO:
 *   - It does not edit anything. No POST handlers, no CSRF, no audit.
 *   - It does not mark notifications as read. That is a separate
 *     milestone (write path). The page renders the is_read flag but
 *     does not change it.
 *   - It does not show the guardian's own uuid, created_at, or
 *     created_by fields.
 *   - It does not show the tenant_id in the UI.
 *   - It does not touch any table other than notifications.
 *
 * LOCKED DECISIONS (2026-10-03 / 2026-10-04):
 *   Q1   The guardian is the login.
 *   Q6   Two portal surfaces: /platform/guardian/ and
 *        /platform/student/.
 *
 * PAGE DECISIONS:
 *   (1a) Notifications addressed to the logged-in guardian.
 *   (2a) Read-only. No mark-as-read.
 *   (3a) All notifications, most recent first, no filter.
 *
 * DEPENDENCIES:
 *   - app/bootstrap.php
 *   - app/helpers/Permissions.php v1.2
 */

// ============================================
// Bootstrap
// ============================================
$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/app/bootstrap.php';
require_once $projectRoot . '/app/helpers/Permissions.php';

function h_g(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ============================================
// Guardian authentication
// ============================================
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/tenant/login.php');
    exit;
}

if (!is_guardian()) {
    if (function_exists('is_super_admin') && is_super_admin()) {
        header('Location: /platform/index.php');
        exit;
    }
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

$guardianId = (int)($_SESSION['guardian_id'] ?? 0);
$userId     = (int)($_SESSION['user_id']     ?? 0);
$tenantId   = (int)($_SESSION['tenant_id']   ?? 0);
$schoolId   = (int)($_SESSION['school_id']   ?? 0);

if ($guardianId <= 0 || $tenantId <= 0 || $userId <= 0) {
    session_destroy();
    header('Location: /platform/tenant/login.php');
    exit;
}

$db = DatabaseHelper::getInstance();

// ============================================
// Load the notifications
// ============================================
$notifications = $db->fetchAll(
    "SELECT id, tenant_id, type, priority, channel, recipient_role,
            title, message, link, related_type, related_id,
            is_read, created_at, read_at
     FROM notifications
     WHERE user_id = ?
       AND recipient_role = 'guardian'
       AND tenant_id = ?
       AND deleted_at IS NULL
     ORDER BY created_at DESC, id DESC
     LIMIT 200",
    [$userId, $tenantId]
);

// ============================================
// Aggregate counts
// ============================================
$totalCount  = 0;
$unreadCount = 0;
foreach ($notifications as $n) {
    $totalCount++;
    if ((int)$n['is_read'] === 0) {
        $unreadCount++;
    }
}

// ============================================
// Display helpers
// ============================================
function notifPriorityPill(string $priority): string
{
    return [
        'low'    => 'gray',
        'normal' => 'blue',
        'high'   => 'orange',
        'urgent' => 'red',
    ][$priority] ?? 'gray';
}
function notifPriorityLabel(string $priority): string
{
    return ucfirst($priority);
}

function notifTypeIcon(string $type): string
{
    $map = [
        'behaviour'  => 'fa-star-half-alt',
        'discipline' => 'fa-gavel',
        'finance'    => 'fa-money-bill-wave',
        'reminder'   => 'fa-bell',
        'attendance' => 'fa-calendar-check',
        'academic'   => 'fa-poll',
        'result'     => 'fa-poll',
        'health'     => 'fa-heartbeat',
        'welcome'    => 'fa-hand-sparkles',
        'system'     => 'fa-cog',
    ];
    return $map[$type] ?? 'fa-info-circle';
}

function notifTypeColor(string $type): string
{
    $map = [
        'behaviour'  => '#6f42c1',
        'discipline' => '#dc3545',
        'finance'    => '#28a745',
        'reminder'   => '#f59e0b',
        'attendance' => '#fd7e14',
        'academic'   => '#0d6efd',
        'result'     => '#0d6efd',
        'health'     => '#e83e8c',
        'welcome'    => '#0ea5e9',
        'system'     => '#495057',
    ];
    return $map[$type] ?? '#0d6efd';
}

function notifChannelLabel(string $ch): string
{
    return [
        'inapp' => 'In-app',
        'email' => 'Email',
        'sms'   => 'SMS',
    ][$ch] ?? ucfirst($ch);
}

function timeAgo(?string $sqlDate): string
{
    if (!$sqlDate) return '';
    $t = strtotime($sqlDate);
    if (!$t) return (string)$sqlDate;
    $diff = time() - $t;
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' h ago';
    if ($diff < 604800) return floor($diff / 86400) . ' d ago';
    return date('M j, Y', $t);
}

$schoolName = (string)($_SESSION['school_name'] ?? '');
$schoolLogo = (string)($_SESSION['school_logo'] ?? '');

$pageTitle = 'Notifications - Student 360 Platform';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo h_g($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?php if ($schoolLogo !== ''): ?>
        <link rel="icon" href="<?php echo h_g($schoolLogo); ?>">
    <?php endif; ?>
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            overflow-x: hidden !important;
            width: 100%;
            max-width: 100%;
            background: #f0f2f5;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            line-height: 1.6;
            color: #1a1a2e;
        }

        .container-fluid {
            padding: 0;
            margin: 0;
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
        }

        .row {
            margin: 0;
            width: 100%;
            max-width: 100%;
        }

        [class*="col-"] {
            padding-left: 12px;
            padding-right: 12px;
        }

        .top-bar {
            background: #fff;
            padding: 12px 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-bar .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .top-bar .brand img {
            max-height: 40px;
            max-width: 40px;
        }

        .top-bar .brand .brand-text h1 {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
            color: #1a1a2e;
        }

        .top-bar .brand .brand-text p {
            font-size: 12px;
            margin: 0;
            color: #6c757d;
        }

        .top-bar .actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .top-bar .actions .badge {
            background: #e3f0ff;
            color: #0d6efd;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .top-bar .actions .badge.badge-unread {
            background: #dc3545;
            color: #fff;
        }

        .btn-outline-secondary {
            background: transparent;
            border: 1.5px solid #e9ecef;
            color: #6c757d;
            border-radius: 8px;
            padding: 6px 14px;
            font-size: 13px;
            text-decoration: none;
        }

        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: #ced4da;
            color: #495057;
        }

        .btn-outline-secondary.is-current {
            background: #e3f0ff;
            border-color: #4facfe;
            color: #0d6efd;
            font-weight: 600;
            cursor: default;
        }

        .content-area {
            padding: 20px 20px 40px;
            max-width: 900px;
            margin: 0 auto;
        }

        .hero {
            background: linear-gradient(135deg, #1a1a2e 0%, #2a2a4e 100%);
            color: #fff;
            border-radius: 14px;
            padding: 22px 26px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
        }

        .hero .hero-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }

        .hero .hero-body {
            flex: 1;
            min-width: 0;
        }

        .hero .hero-body h2 {
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 2px;
        }

        .hero .hero-body p {
            font-size: 12px;
            opacity: 0.75;
            margin: 0;
        }

        .hero .hero-counts {
            display: flex;
            gap: 20px;
            text-align: right;
        }

        .hero .hero-counts .hc {
            text-align: center;
        }

        .hero .hero-counts .hc-num {
            font-weight: 800;
            font-size: 22px;
            line-height: 1;
        }

        .hero .hero-counts .hc-lbl {
            font-size: 10px;
            opacity: 0.7;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }

        .notif-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .notif-card {
            background: #fff;
            border: 1px solid rgba(0, 0, 0, 0.04);
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            padding: 16px 20px;
            display: grid;
            grid-template-columns: 44px 1fr auto;
            gap: 14px;
            align-items: flex-start;
            position: relative;
        }

        .notif-card.unread {
            border-left: 4px solid #4facfe;
        }

        .notif-card.unread::after {
            content: '';
            position: absolute;
            top: 14px;
            right: 14px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #dc3545;
        }

        .notif-card .nc-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .notif-card .nc-body {
            min-width: 0;
        }

        .notif-card .nc-head {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 4px;
        }

        .notif-card .nc-title {
            font-weight: 700;
            font-size: 14px;
            color: #1a1a2e;
            word-break: break-word;
        }

        .notif-card .nc-msg {
            font-size: 13px;
            color: #495057;
            margin: 4px 0 8px;
            word-break: break-word;
        }

        .notif-card .nc-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            font-size: 11px;
            color: #6c757d;
        }

        .notif-card .nc-meta span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .notif-card .nc-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #0d6efd;
            text-decoration: none;
            margin-top: 6px;
        }

        .notif-card .nc-link:hover {
            text-decoration: underline;
        }

        .notif-card .nc-side {
            display: flex;
            flex-direction: column;
            gap: 4px;
            align-items: flex-end;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }

        .pill.green {
            background: #d4edda;
            color: #155724;
        }

        .pill.red {
            background: #f8d7da;
            color: #721c24;
        }

        .pill.orange {
            background: #ffe8d9;
            color: #c2410c;
        }

        .pill.blue {
            background: #cce5ff;
            color: #004085;
        }

        .pill.teal {
            background: #d1f2eb;
            color: #0d5c4a;
        }

        .pill.gray {
            background: #e9ecef;
            color: #495057;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
            background: #fff;
            border-radius: 14px;
            border: 1px solid rgba(0, 0, 0, 0.04);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .empty-state i {
            font-size: 48px;
            opacity: 0.3;
            display: block;
            margin-bottom: 14px;
        }

        .empty-state h5 {
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 6px;
            font-size: 15px;
        }

        .empty-state p {
            font-size: 13px;
            margin: 0;
        }

        @media (max-width: 600px) {
            .content-area {
                padding: 14px 14px 30px;
            }

            .hero {
                padding: 16px 18px;
                gap: 14px;
            }

            .hero .hero-icon {
                width: 46px;
                height: 46px;
                font-size: 20px;
            }

            .hero .hero-body h2 {
                font-size: 17px;
            }

            .hero .hero-counts {
                width: 100%;
                justify-content: flex-start;
            }

            .notif-card {
                grid-template-columns: 1fr;
                padding: 14px 16px;
            }

            .notif-card .nc-icon {
                width: 36px;
                height: 36px;
                font-size: 15px;
            }

            .notif-card .nc-side {
                flex-direction: row;
                justify-content: flex-start;
                align-items: center;
            }

            .notif-card.unread::after {
                top: 12px;
                right: 12px;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="top-bar">
            <div class="brand">
                <?php if ($schoolLogo !== ''): ?>
                    <img src="<?php echo h_g($schoolLogo); ?>" alt="">
                <?php endif; ?>
                <div class="brand-text">
                    <h1><?php echo h_g($schoolName !== '' ? $schoolName : 'Student 360'); ?></h1>
                    <p>Guardian Portal</p>
                </div>
            </div>
            <div class="actions">
                <span class="badge"><i class="fas fa-user-shield me-1"></i> Guardian</span>
                <?php if ($unreadCount > 0): ?>
                    <span class="badge badge-unread">
                        <i class="fas fa-bell me-1"></i> <?php echo h_g((string)$unreadCount); ?> unread
                    </span>
                <?php endif; ?>
                <a href="/platform/guardian/index.php" class="btn-outline-secondary">
                    <i class="fas fa-home me-1"></i> Dashboard
                </a>
                <a href="/platform/guardian/requests.php" class="btn-outline-secondary">
                    <i class="fas fa-file-signature me-1"></i> Requests
                </a>
                <span class="btn-outline-secondary is-current">
                    <i class="fas fa-bell me-1"></i> Notifications
                </span>
                <a href="/platform/guardian/profile.php" class="btn-outline-secondary">
                    <i class="fas fa-user-cog me-1"></i> Profile
                </a>
                <a href="/platform/logout.php" class="btn-outline-secondary">
                    <i class="fas fa-sign-out-alt me-1"></i> Logout
                </a>
            </div>
        </div>

        <div class="content-area">

            <div class="hero">
                <div class="hero-icon"><i class="fas fa-bell"></i></div>
                <div class="hero-body">
                    <h2>Notifications</h2>
                    <p>Messages addressed to you from the school</p>
                </div>
                <div class="hero-counts">
                    <div class="hc">
                        <div class="hc-num"><?php echo h_g((string)$totalCount); ?></div>
                        <div class="hc-lbl">Total</div>
                    </div>
                    <div class="hc">
                        <div class="hc-num" style="color:<?php echo $unreadCount > 0 ? '#fbbf24' : '#ffffff'; ?>">
                            <?php echo h_g((string)$unreadCount); ?>
                        </div>
                        <div class="hc-lbl">Unread</div>
                    </div>
                </div>
            </div>

            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <i class="fas fa-bell-slash"></i>
                    <h5>No notifications</h5>
                    <p>You have no notifications from the school at this time.</p>
                </div>
            <?php else: ?>
                <div class="notif-list">
                    <?php foreach ($notifications as $n):
                        $isUnread = (int)$n['is_read'] === 0;
                        $priority = (string)$n['priority'];
                        $type     = (string)$n['type'];
                        $channel  = (string)$n['channel'];
                        $icon     = notifTypeIcon($type);
                        $iconBg   = notifTypeColor($type);
                        $createdAt = (string)$n['created_at'];
                        $link     = (string)($n['link'] ?? '');
                    ?>
                        <div class="notif-card <?php echo $isUnread ? 'unread' : ''; ?>">
                            <div class="nc-icon" style="background:<?php echo h_g($iconBg); ?>;">
                                <i class="fas <?php echo h_g($icon); ?>"></i>
                            </div>
                            <div class="nc-body">
                                <div class="nc-head">
                                    <span class="nc-title"><?php echo h_g((string)$n['title']); ?></span>
                                    <span class="pill <?php echo notifPriorityPill($priority); ?>">
                                        <?php echo h_g(notifPriorityLabel($priority)); ?>
                                    </span>
                                    <span class="pill gray"><?php echo h_g(ucfirst($type)); ?></span>
                                    <span class="pill teal"><?php echo h_g(notifChannelLabel($channel)); ?></span>
                                </div>
                                <div class="nc-msg"><?php echo nl2br(h_g((string)$n['message'])); ?></div>
                                <div class="nc-meta">
                                    <span><i class="fas fa-clock"></i><?php echo h_g(timeAgo($createdAt)); ?></span>
                                    <?php if ($isUnread): ?>
                                        <span style="color:#0d6efd;font-weight:600;">
                                            <i class="fas fa-circle" style="font-size:6px;"></i> Unread
                                        </span>
                                    <?php else: ?>
                                        <span><i class="fas fa-check"></i> Read</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($link !== ''): ?>
                                    <a href="<?php echo h_g($link); ?>" class="nc-link">
                                        <i class="fas fa-arrow-right"></i> Open
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="nc-side">
                                <?php if ($isUnread): ?>
                                    <span class="pill blue"><i class="fas fa-bell"></i>New</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>