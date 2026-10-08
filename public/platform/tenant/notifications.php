<?php

/**
 * Notifications — Tenant Admin / School Admin notifications + outbound guardian log
 *
 * @package EduTrack
 * @subpackage Platform\Tenant
 * @version 2.6  (S20 bootstrap + CSRF; v2.5 guardian-request view; v2.6 reply)
 * @filepath public/platform/tenant/notifications.php
 *
 * v2.6 change (2026-10-07) [REPLY TO REQUEST]:
 *   The guardian-request modal added in v2.5 gains a reply form. One
 *   new POST action, 'reply_request', is added inside the existing
 *   CSRF-gated POST block, after the 'broadcast' branch. It is
 *   tenant-scoped, transaction-wrapped, and does four things in
 *   order:
 *     - Reads the guardian_requests row scoped to tenant_id, refuses
 *       if it does not exist or if the guardian has cancelled it.
 *     - Writes resolution_note, status (in_review | resolved |
 *       rejected), resolved_at (set to NOW() only when the new
 *       status is 'resolved'), and updated_at.
 *     - Writes one audit_logs row with action
 *       'guardian.request.replied', resource_type 'guardian_requests',
 *       and a details payload naming the old status, the new status,
 *       and a truncated excerpt of the reply.
 *     - Resolves the guardian's platform_users.id by
 *       platform_users.guardian_id = guardians.id (the mapping
 *       confirmed by SHOW CREATE TABLE on 2026-10-07), then fans out
 *       one in-app notification to that guardian via
 *       NotificationHelper::notify() with type 'info', priority
 *       'normal', the reply text as the message, and a link to
 *       /platform/guardian/requests.php. If the guardian user row is
 *       absent, the reply is still committed and the miss is
 *       error_log()ed.
 *   The modal's read-only footer is replaced by a form that carries
 *   the CSRF field, the request id, the reply textarea, and the
 *   status dropdown. A page-local helper writeAudit_t is added near
 *   the top of the file. Every other line of the file is
 *   byte-identical to v2.5.
 *
 * v2.5 change (2026-10-07) [GUARDIAN REQUEST VIEW]:
 *   The 'View Details' link on a notification whose related_type is
 *   'guardian_requests' now opens a Bootstrap modal that renders the
 *   linked guardian request instead of navigating to the tenant
 *   notifications list. One batched read against guardian_requests
 *   (joined to guardians, students, classes; scoped by tenant_id and
 *   deleted_at IS NULL) is made per page load, and only when the
 *   page holds at least one guardian-request notification. The modal
 *   is read-only: it renders the request type, subject, body, status
 *   pill, the guardian's full name and contact, the student's name
 *   and class when applicable, the submitted date, and any
 *   resolution note. Non-guardian-request notifications keep the
 *   existing 'View Details' <a> link unchanged. Every other line of
 *   the file is byte-identical to v2.4.
 *
 * Session S17g decisions:
 *   NS1A  additive schema, kept message/link names
 *   NS2A  extended type enum
 *   NS3C  recipient_role enum
 *   NS10A tab switch: My notifications / Sent to guardians
 *   NS11A soft-delete your own
 *
 * Session S17g-2 additions:
 *   P7  Broadcast announcement button + modal (covers PTA + mid-term break)
 *
 * Session S20 decisions:
 *   SH2A  per-session CSRF token; all state-changing actions require it
 *   SH3A  central Security.php helper, loaded via app/bootstrap.php
 *   SH4A  hardened session started inside bootstrap
 *   SH5C  h() on every echoed value
 *   SH6B  tenant_id scoping and deleted_at IS NULL on every query
 *   SH10A generic error messages; real error logged
 *
 * Role model (from login.php):
 *   - Platform Admin  → role_id = 2, is_super_admin = true  → lands on /platform/index.php
 *   - Tenant Admin    → is_super_admin = false              → lands on /platform/tenant/dashboard.php
 *   - School Admin    → is_super_admin = false              → lands on /platform/tenant/dashboard.php
 *
 * The platform admin is redirected away by the guard below, so any user
 * who reaches this page is a tenant-scoped user and may broadcast.
 */

// =============================================
// S20 — Bootstrap (session, CSRF, headers, HTTPS, DB)
// =============================================
$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/app/bootstrap.php';

require_tenant();

// Platform Admin never sees this page.
if (is_super_admin()) {
    header('Location: /platform/index.php');
    exit;
}

$userId       = current_user_id();
$tenantId     = current_tenant_id();
$isSuperAdmin = false; // tenant page: super admin already redirected away

// h() is defined in Security.php; do not redeclare it here.

// [v2.6] page-local audit helper. Same shape as the guardian file's
// writeAudit_g, named _t to make the origin explicit.
function writeAudit_t($db, int $tenantId, int $userId, string $action, string $resourceType, ?int $resourceId, array $details): void
{
    try {
        $db->insert(
            "INSERT INTO audit_logs (user_id, tenant_id, action, resource_type, resource_id, details, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $userId ?: null,
                $tenantId,
                $action,
                $resourceType,
                $resourceId,
                json_encode($details, JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]
        );
    } catch (Exception $e) {
        error_log('tenant notifications audit_logs write failed: ' . $e->getMessage());
    }
}

// =============================================
// TAB + FILTERS
// =============================================
$tab = trim((string)($_GET['tab'] ?? 'mine'));
if (!in_array($tab, ['mine', 'guardians'], true)) $tab = 'mine';

$filterType     = trim((string)($_GET['type'] ?? ''));
$filterPriority = trim((string)($_GET['priority'] ?? ''));
$filterUnread   = !empty($_GET['unread']);

$allowedTypes = [
    'approved',
    'rejected',
    'info',
    'system',
    'discipline',
    'behaviour',
    'attendance',
    'finance',
    'academic',
    'promotion',
    'biometric',
    'registration',
    'credentials',
    'reminder',
    'result',
    'escalation'
];
if (!in_array($filterType, $allowedTypes, true)) $filterType = '';

$allowedPriorities = ['low', 'normal', 'high', 'urgent'];
if (!in_array($filterPriority, $allowedPriorities, true)) $filterPriority = '';

// =============================================
// STATE-CHANGING ACTIONS (POST + CSRF)
// =============================================
$errors   = [];
$successMessage = null;

// All state-changing actions are POST now, guarded by CSRF.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = trim((string)($_POST['action'] ?? ''));

    // ---------- MARK ALL READ ----------
    if ($action === 'mark_all_read') {
        try {
            if ($tab === 'guardians') {
                // Mark all guardian-role rows read for the tenant (admin-scoped view)
                $db->execute(
                    "UPDATE notifications
                        SET is_read = 1, read_at = NOW()
                      WHERE tenant_id = ?
                        AND recipient_role = 'guardian'
                        AND is_read = 0
                        AND deleted_at IS NULL",
                    [$tenantId]
                );
            } else {
                $db->execute(
                    "UPDATE notifications
                        SET is_read = 1, read_at = NOW()
                      WHERE user_id = ?
                        AND tenant_id = ?
                        AND is_read = 0
                        AND deleted_at IS NULL",
                    [$userId, $tenantId]
                );
            }
        } catch (Exception $e) {
            error_log('notifications mark_all_read error: ' . $e->getMessage());
        }
        header('Location: /platform/tenant/notifications.php?tab=' . urlencode($tab));
        exit;
    }

    // ---------- MARK ONE READ ----------
    if ($action === 'mark_read') {
        $notificationId = (int)($_POST['id'] ?? 0);
        if ($notificationId > 0) {
            try {
                $db->execute(
                    "UPDATE notifications
                        SET is_read = 1, read_at = NOW()
                      WHERE id = ? AND user_id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$notificationId, $userId, $tenantId]
                );
            } catch (Exception $e) {
                error_log('notifications mark_read error: ' . $e->getMessage());
            }
        }
        header('Location: /platform/tenant/notifications.php?tab=' . urlencode($tab));
        exit;
    }

    // ---------- SOFT DELETE ----------
    if ($action === 'delete') {
        $notificationId = (int)($_POST['id'] ?? 0);
        if ($notificationId > 0) {
            try {
                $db->execute(
                    "UPDATE notifications
                        SET deleted_at = NOW()
                      WHERE id = ? AND user_id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$notificationId, $userId, $tenantId]
                );
            } catch (Exception $e) {
                error_log('notifications delete error: ' . $e->getMessage());
            }
        }
        header('Location: /platform/tenant/notifications.php?tab=' . urlencode($tab));
        exit;
    }

    // ---------- P7 — BROADCAST ANNOUNCEMENT ----------
    if ($action === 'broadcast') {
        try {
            if ($tenantId <= 0) {
                throw new Exception('Broadcast requires a tenant context.');
            }

            $bTitle    = trim((string)($_POST['broadcast_title'] ?? ''));
            $bMessage  = trim((string)($_POST['broadcast_message'] ?? ''));
            $bAudience = trim((string)($_POST['broadcast_audience'] ?? 'guardians'));
            $bPriority = trim((string)($_POST['broadcast_priority'] ?? 'normal'));

            if ($bTitle === '')   throw new Exception('Title is required.');
            if ($bMessage === '') throw new Exception('Message is required.');
            if (!in_array($bAudience, ['guardians', 'staff', 'both'], true)) $bAudience = 'guardians';
            if (!in_array($bPriority, ['low', 'normal', 'high', 'urgent'], true)) $bPriority = 'normal';

            require_once $projectRoot . '/app/helpers/NotificationHelper.php';

            $staffIds    = [];
            $guardianIds = [];

            if ($bAudience === 'staff' || $bAudience === 'both') {
                $staffIds = NotificationHelper::allStaffForTenant($db, $tenantId);
            }
            if ($bAudience === 'guardians' || $bAudience === 'both') {
                $guardianIds = NotificationHelper::allGuardiansForTenant($db, $tenantId);
            }

            if (!$staffIds && !$guardianIds) {
                throw new Exception('No recipients found for the selected audience.');
            }

            $n = NotificationHelper::notify($db, $tenantId, [
                'recipients'   => ['staff' => $staffIds, 'guardians' => $guardianIds],
                'type'         => 'reminder',
                'priority'     => $bPriority,
                'title'        => $bTitle,
                'message'      => $bMessage,
                'link'         => '',
                'related_type' => 'announcement',
                'related_id'   => null,
                'created_by'   => $userId,
            ]);

            $_SESSION['success'] = 'Broadcast sent to ' . $n . ' recipient(s).';
        } catch (Exception $e) {
            error_log('notifications broadcast error: ' . $e->getMessage());
            $_SESSION['errors'] = [$e->getMessage()];
        }
        header('Location: /platform/tenant/notifications.php?tab=' . urlencode($tab));
        exit;
    }

    // ---------- v2.6 REPLY TO GUARDIAN REQUEST ----------
    if ($action === 'reply_request') {
        try {
            $requestId = (int)($_POST['request_id'] ?? 0);
            $note      = trim((string)($_POST['resolution_note'] ?? ''));
            $newStatus = trim((string)($_POST['new_status'] ?? ''));

            if ($requestId <= 0)  throw new Exception('Request not found.');
            if ($note === '')     throw new Exception('A reply message is required.');
            if (!in_array($newStatus, ['in_review', 'resolved', 'rejected'], true)) {
                throw new Exception('Please choose a valid status.');
            }

            $db->beginTransaction();

            $req = $db->fetchOne(
                "SELECT id, guardian_id, status
                   FROM guardian_requests
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL
                  LIMIT 1",
                [$requestId, $tenantId]
            );
            if (!$req) {
                throw new Exception('Request not found.');
            }
            if ((string)$req['status'] === 'cancelled') {
                throw new Exception('This request was cancelled by the guardian.');
            }

            $db->execute(
                "UPDATE guardian_requests
                    SET status = ?,
                        resolution_note = ?,
                        resolved_at = CASE WHEN ? = 'resolved' THEN NOW() ELSE resolved_at END,
                        updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$newStatus, $note, $newStatus, $requestId, $tenantId]
            );

            writeAudit_t($db, $tenantId, $userId, 'guardian.request.replied', 'guardian_requests', $requestId, [
                'guardian_id'   => (int)$req['guardian_id'],
                'old_status'    => (string)$req['status'],
                'new_status'    => $newStatus,
                'note_excerpt'  => mb_substr($note, 0, 200),
            ]);

            // Resolve the guardian's platform_users.id — mapping:
            // platform_users.guardian_id = guardians.id
            $guardianUserId = 0;
            try {
                $guRow = $db->fetchOne(
                    "SELECT id FROM platform_users
                      WHERE guardian_id = ?
                        AND tenant_id = ?
                        AND user_type = 'guardian'
                        AND is_active = 1
                        AND deleted_at IS NULL
                      LIMIT 1",
                    [(int)$req['guardian_id'], $tenantId]
                );
                if ($guRow && !empty($guRow['id'])) {
                    $guardianUserId = (int)$guRow['id'];
                }
            } catch (Exception $e) {
                error_log('notifications reply_request guardian-user lookup failed: ' . $e->getMessage());
            }

            if ($guardianUserId > 0) {
                require_once $projectRoot . '/app/helpers/NotificationHelper.php';
                NotificationHelper::notify($db, $tenantId, [
                    'recipients'   => ['guardians' => [$guardianUserId]],
                    'type'         => 'info',
                    'priority'     => 'normal',
                    'title'        => 'Reply to your request',
                    'message'      => $note,
                    'link'         => '/platform/guardian/requests.php',
                    'related_type' => 'guardian_requests',
                    'related_id'   => $requestId,
                    'created_by'   => $userId,
                ]);
            } else {
                error_log('notifications reply_request: no platform_users row for guardian_id=' . (int)$req['guardian_id']);
            }

            $db->commit();
            $_SESSION['success'] = 'Reply sent to the guardian.';
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('notifications reply_request error: ' . $e->getMessage());
            $_SESSION['errors'] = [$e->getMessage()];
        }
        header('Location: /platform/tenant/notifications.php?tab=' . urlencode($tab));
        exit;
    }

    // Unknown POST action.
    $_SESSION['errors'] = ['Unknown action.'];
    header('Location: /platform/tenant/notifications.php?tab=' . urlencode($tab));
    exit;
}

// =============================================
// FLASH
// =============================================
if (isset($_SESSION['success'])) {
    $successMessage = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['errors'])) {
    $errors = $_SESSION['errors'];
    unset($_SESSION['errors']);
}

// =============================================
// GET NOTIFICATIONS
// =============================================
$notifications = [];
$unreadCount   = 0;

try {
    $where  = ["tenant_id = ?", "deleted_at IS NULL"];
    $params = [$tenantId];

    if ($tab === 'guardians') {
        $where[] = "recipient_role = 'guardian'";
    } else {
        $where[]  = "user_id = ?";
        $params[] = $userId;
        $where[]  = "recipient_role = 'staff'";
    }

    if ($filterType !== '') {
        $where[] = "type = ?";
        $params[] = $filterType;
    }
    if ($filterPriority !== '') {
        $where[] = "priority = ?";
        $params[] = $filterPriority;
    }
    if ($filterUnread) {
        $where[] = "is_read = 0";
    }

    $whereSql = implode(' AND ', $where);

    $notifications = $db->fetchAll(
        "SELECT * FROM notifications
          WHERE $whereSql
          ORDER BY created_at DESC, id DESC
          LIMIT 100",
        $params
    );

    $unreadCount = (int)($db->getValue(
        "SELECT COUNT(*) FROM notifications
          WHERE user_id = ?
            AND tenant_id = ?
            AND is_read = 0
            AND deleted_at IS NULL
            AND recipient_role = 'staff'",
        [$userId, $tenantId]
    ) ?? 0);
} catch (Exception $e) {
    error_log('Notifications error: ' . $e->getMessage());
    $notifications = [];
    $unreadCount   = 0;
}

// =============================================
// [v2.5] Linked guardian_requests for related notifications
// =============================================
$linkedRequests = [];
$greqIds = [];
foreach ($notifications as $__n) {
    $rt  = (string)($__n['related_type'] ?? '');
    $rid = (int)($__n['related_id'] ?? 0);
    if ($rt === 'guardian_requests' && $rid > 0) {
        $greqIds[$rid] = true;
    }
}
$greqIds = array_keys($greqIds);
if (!empty($greqIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($greqIds), '?'));
        $greqRows = $db->fetchAll(
            "SELECT gr.id, gr.request_type, gr.subject, gr.body,
                    gr.status, gr.resolution_note, gr.resolved_at, gr.created_at,
                    g.first_name  AS guardian_first_name,
                    g.middle_name AS guardian_middle_name,
                    g.last_name   AS guardian_last_name,
                    g.email       AS guardian_email,
                    g.primary_phone AS guardian_phone,
                    s.student_number AS student_number,
                    s.first_name  AS student_first_name,
                    s.middle_name AS student_middle_name,
                    s.last_name   AS student_last_name,
                    c.class_name  AS student_class_name
               FROM guardian_requests gr
               LEFT JOIN guardians g ON g.id = gr.guardian_id AND g.deleted_at IS NULL
               LEFT JOIN students  s ON s.id = gr.student_id  AND s.deleted_at IS NULL
               LEFT JOIN classes   c ON c.id = s.class_id     AND c.deleted_at IS NULL
              WHERE gr.tenant_id = ?
                AND gr.deleted_at IS NULL
                AND gr.id IN ($placeholders)",
            array_merge([$tenantId], $greqIds)
        );
        foreach ($greqRows as $__r) {
            $linkedRequests[(int)$__r['id']] = $__r;
        }
    } catch (Exception $e) {
        error_log('notifications linked guardian_requests read error: ' . $e->getMessage());
        $linkedRequests = [];
    }
}

// =============================================
// PAGE SETUP
// =============================================
$currentUser   = $_SESSION['user_name'] ?? 'Admin';
$userFirstName = $_SESSION['first_name'] ?? 'User';
$userAvatar    = strtoupper(substr($userFirstName, 0, 1));
$tenantName    = $_SESSION['tenant_name'] ?? 'My Organization';
$pageTitle     = 'Notifications - EduTrack Tenant';
$currentPage   = 'notifications';

function notifLink(array $overrides): string
{
    $base = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($base[$k]);
        else             $base[$k] = $v;
    }
    return '/platform/tenant/notifications.php?' . http_build_query($base);
}
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
        * {
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

        .sidebar-toggle {
            display: none;
            position: fixed;
            top: 14px;
            left: 14px;
            z-index: 1001;
            background: #1a1a2e;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 22px;
            cursor: pointer;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.2);
        }

        .sidebar-toggle:hover {
            background: #2a2a4e;
        }

        .sidebar {
            min-height: 100vh;
            background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
            color: #fff;
            position: fixed;
            width: 260px;
            left: 0;
            top: 0;
            z-index: 1000;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            overflow-y: auto;
            padding: 0;
        }

        .sidebar .sidebar-header {
            padding: 25px 24px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .sidebar .sidebar-header h4 {
            font-weight: 700;
            font-size: 20px;
            margin: 0;
        }

        .sidebar .sidebar-header h4 i {
            color: #4facfe;
        }

        .sidebar .sidebar-header small {
            color: rgba(255, 255, 255, 0.4);
            font-size: 12px;
        }

        .sidebar .nav {
            padding: 16px 12px;
        }

        .sidebar .nav-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.3);
            padding: 0 12px 8px;
            font-weight: 600;
        }

        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.6);
            padding: 10px 16px;
            border-radius: 10px;
            margin: 2px 0;
            transition: all 0.3s;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            text-decoration: none;
        }

        .sidebar .nav-link:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
        }

        .sidebar .nav-link.active {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: #fff;
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.3);
        }

        .sidebar .nav-link i {
            width: 22px;
            text-align: center;
            margin-right: 12px;
            font-size: 15px;
        }

        .sidebar .sidebar-footer {
            position: absolute;
            bottom: 0;
            width: 100%;
            padding: 20px 24px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(0, 0, 0, 0.2);
        }

        .sidebar .sidebar-footer .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar .sidebar-footer .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            color: #fff;
            flex-shrink: 0;
        }

        .sidebar .sidebar-footer .user-name {
            font-weight: 600;
            font-size: 14px;
        }

        .sidebar .sidebar-footer .user-role {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.4);
        }

        .sidebar .sidebar-footer .logout-btn {
            color: rgba(255, 255, 255, 0.4);
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            font-size: 14px;
        }

        .sidebar .sidebar-footer .logout-btn:hover {
            color: #ff6b6b;
        }

        .main-content {
            margin-left: 260px;
            padding: 24px 32px 40px;
            background: #f0f2f5;
            min-height: 100vh;
            width: calc(100% - 260px);
            max-width: 100%;
            overflow-x: hidden;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 0 20px 0;
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-bar .page-title h1 {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2e;
            margin: 0;
        }

        .top-bar .page-title h1 i {
            color: #4facfe;
        }

        .top-bar .page-title p {
            color: #6c757d;
            margin: 0;
            font-size: 14px;
        }

        .top-bar .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .top-bar .header-actions .btn {
            border-radius: 10px;
            padding: 8px 18px;
            font-weight: 500;
            font-size: 14px;
        }

        .top-bar .header-actions .btn i {
            margin-right: 6px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border: none;
            color: #fff;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 172, 254, 0.4);
            color: #fff;
        }

        .btn-outline-secondary {
            background: transparent;
            border: 2px solid #e9ecef;
            color: #6c757d;
        }

        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: #ced4da;
        }

        .tabs-nav {
            display: flex;
            gap: 4px;
            margin-bottom: 16px;
        }

        .tabs-nav .tab-btn {
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 600;
            border: none;
            background: #fff;
            color: #6c757d;
            border-radius: 10px;
            cursor: pointer;
            text-decoration: none;
        }

        .tabs-nav .tab-btn:hover {
            color: #1a1a2e;
        }

        .tabs-nav .tab-btn.active {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: #fff;
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.3);
        }

        .filters-bar {
            background: #fff;
            border-radius: 14px;
            padding: 14px 18px;
            margin-bottom: 16px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .filters-bar .fg {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 140px;
            flex: 1;
        }

        .filters-bar .fg label {
            font-size: 12px;
            font-weight: 500;
            color: #1a1a2e;
        }

        .filters-bar .form-select,
        .filters-bar .form-control {
            height: 36px;
            font-size: 13px;
            border-radius: 8px;
            border: 1.5px solid #e9ecef;
        }

        .notification-item {
            background: #fff;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 10px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.3s;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }

        .notification-item:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border-color: #4facfe;
        }

        .notification-item.unread {
            border-left: 4px solid #4facfe;
            background: #f8fbff;
        }

        .notification-item .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: #fff;
            flex-shrink: 0;
            background: #4facfe;
        }

        .notification-item .notification-icon.approved {
            background: #28a745;
        }

        .notification-item .notification-icon.rejected {
            background: #dc3545;
        }

        .notification-item .notification-icon.urgent {
            background: #dc3545;
        }

        .notification-item .notification-icon.info {
            background: #4facfe;
        }

        .notification-item .notification-content {
            flex: 1;
        }

        .notification-item .notification-content .notification-title {
            font-weight: 600;
            font-size: 15px;
            color: #1a1a2e;
        }

        .notification-item .notification-content .notification-message {
            font-size: 13px;
            color: #6c757d;
            margin-top: 2px;
        }

        .notification-item .notification-content .notification-time {
            font-size: 11px;
            color: #adb5bd;
            margin-top: 4px;
        }

        .notification-item .notification-badge {
            background: #4facfe;
            color: #fff;
            font-size: 10px;
            padding: 2px 10px;
            border-radius: 12px;
            flex-shrink: 0;
            font-weight: 600;
            margin-left: 6px;
        }

        .notification-item .notification-link {
            color: #4facfe;
            text-decoration: none;
            font-weight: 500;
            font-size: 13px;
        }

        .notification-item .notification-link:hover {
            text-decoration: underline;
        }

        .notification-item .row-actions {
            display: flex;
            gap: 4px;
            flex-shrink: 0;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .pill.gray {
            background: #e9ecef;
            color: #495057;
        }

        .pill.blue {
            background: #cce5ff;
            color: #004085;
        }

        .pill.red {
            background: #f8d7da;
            color: #721c24;
        }

        .pill.orange {
            background: #ffe8d9;
            color: #c2410c;
        }

        .pill.green {
            background: #d4edda;
            color: #155724;
        }

        .card-custom {
            background: #fff;
            border-radius: 14px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            width: 100%;
        }

        .card-custom .card-header-custom {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .card-custom .card-header-custom h6 {
            font-weight: 600;
            margin: 0;
            font-size: 15px;
            color: #1a1a2e;
        }

        .card-custom .card-body-custom {
            padding: 16px 24px;
        }

        .alert-pro {
            border-radius: 14px;
            border: none;
            padding: 18px 20px 18px 22px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }

        .alert-pro::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            width: 5px;
        }

        .alert-pro .alert-pro-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .alert-pro .alert-pro-content {
            flex: 1;
            padding-top: 2px;
        }

        .alert-pro .alert-pro-title {
            font-weight: 700;
            font-size: 15px;
            margin-bottom: 4px;
        }

        .alert-pro .alert-pro-list {
            margin: 0;
            padding-left: 20px;
            font-size: 13px;
            line-height: 1.7;
        }

        .alert-pro .alert-pro-close {
            background: transparent;
            border: none;
            color: inherit;
            opacity: 0.5;
            font-size: 18px;
            cursor: pointer;
            padding: 0;
            width: 28px;
            height: 28px;
            border-radius: 6px;
        }

        .alert-pro .alert-pro-close:hover {
            opacity: 1;
            background: rgba(0, 0, 0, 0.06);
        }

        .alert-pro.alert-pro-error {
            background: linear-gradient(135deg, #fff5f5 0%, #ffeaea 100%);
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .alert-pro.alert-pro-error::before {
            background: linear-gradient(180deg, #ef4444, #dc2626);
        }

        .alert-pro.alert-pro-error .alert-pro-icon {
            background: rgba(239, 68, 68, 0.12);
            color: #dc2626;
        }

        .alert-pro.alert-pro-success {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .alert-pro.alert-pro-success::before {
            background: linear-gradient(180deg, #22c55e, #16a34a);
        }

        .alert-pro.alert-pro-success .alert-pro-icon {
            background: rgba(34, 197, 94, 0.15);
            color: #16a34a;
        }

        .modal-content {
            border-radius: 16px;
            border: none;
        }

        .modal-header {
            border-bottom: 1px solid #f0f2f5;
            padding: 20px 24px;
        }

        .modal-header h5 {
            font-weight: 700;
            font-size: 17px;
            color: #1a1a2e;
            margin: 0;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            border-top: 1px solid #f0f2f5;
            padding: 16px 24px;
        }

        .form-label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            margin-bottom: 4px;
            display: block;
        }

        .form-control,
        .form-select {
            border-radius: 10px;
            padding: 8px 12px;
            border: 2px solid #e9ecef;
            font-size: 13px;
            width: 100%;
            display: block;
            background: #fff;
            color: #1a1a2e;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
            height: 40px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 4px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        textarea.form-control {
            height: auto;
            min-height: 100px;
        }

        /* [v2.5] Guardian-request modal body */
        .greq-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .greq-subject {
            font-weight: 700;
            font-size: 18px;
            color: #1a1a2e;
            word-break: break-word;
            line-height: 1.3;
        }

        .greq-kv {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }

        .greq-kv tbody tr td {
            padding: 8px 12px;
            border-bottom: 1px solid #f0f2f5;
            vertical-align: top;
            font-size: 13px;
            color: #1a1a2e;
        }

        .greq-kv tbody tr:last-child td {
            border-bottom: none;
        }

        .greq-kv tbody tr td.greq-kv-lbl {
            width: 170px;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.4px;
            color: #4a6b8a;
            background: #f5f8fb;
            border-right: 1px solid #e9ecef;
        }

        .greq-kv tbody tr td.greq-kv-val {
            font-size: 13px;
            color: #1a1a2e;
        }

        .greq-section {
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #4a6b8a;
            margin: 6px 0 6px;
        }

        .greq-body {
            font-size: 13px;
            color: #495057;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
            margin-bottom: 16px;
            background: #fafbfc;
            border: 1px solid #eef0f3;
            border-radius: 10px;
            padding: 12px 14px;
        }

        .greq-resolution {
            font-size: 13px;
            color: #166534;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            padding: 10px 14px;
            line-height: 1.55;
            margin-bottom: 16px;
        }

        /* [v2.6] Reply form inside the guardian-request modal */
        .greq-reply-form {
            border-top: 1px solid #f0f2f5;
            padding-top: 16px;
            margin-top: 8px;
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 72px;
                overflow: hidden;
            }

            .sidebar .sidebar-header h4 {
                font-size: 0;
            }

            .sidebar .sidebar-header h4 i {
                font-size: 24px;
            }

            .sidebar .sidebar-header small {
                display: none;
            }

            .sidebar .nav-link span {
                display: none;
            }

            .sidebar .nav-link i {
                margin-right: 0;
                font-size: 18px;
            }

            .sidebar .nav-link {
                text-align: center;
                padding: 12px;
                justify-content: center;
            }

            .sidebar .nav-label {
                display: none;
            }

            .sidebar .sidebar-footer .user-info span {
                display: none;
            }

            .sidebar .sidebar-footer .user-info {
                justify-content: center;
            }

            .sidebar .sidebar-footer .logout-btn span {
                display: none;
            }

            .main-content {
                margin-left: 72px;
                width: calc(100% - 72px);
                padding: 20px;
            }

            .sidebar-toggle {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .sidebar-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
                width: 280px;
                position: fixed;
                z-index: 1000;
                top: 0;
                left: 0;
                height: 100vh;
                overflow-y: auto;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .sidebar .sidebar-header h4 {
                font-size: 20px;
            }

            .sidebar .sidebar-header small {
                display: block;
            }

            .sidebar .nav-link span {
                display: inline;
            }

            .sidebar .nav-link i {
                margin-right: 12px;
                font-size: 15px;
            }

            .sidebar .nav-link {
                text-align: left;
                padding: 10px 16px;
                justify-content: flex-start;
            }

            .sidebar .nav-label {
                display: block;
            }

            .sidebar .sidebar-footer .user-info span {
                display: inline;
            }

            .sidebar .sidebar-footer .user-info {
                justify-content: flex-start;
            }

            .sidebar .sidebar-footer .logout-btn span {
                display: inline;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 16px;
                padding-top: 70px;
            }

            .top-bar .page-title h1 {
                font-size: 20px;
            }

            .top-bar .page-title p {
                font-size: 12px;
            }

            .top-bar .header-actions .btn {
                font-size: 12px;
                padding: 6px 12px;
            }

            .notification-item {
                flex-wrap: wrap;
            }

            .card-custom .card-header-custom,
            .card-custom .card-body-custom {
                padding: 12px 16px;
            }

            .greq-kv tbody tr td.greq-kv-lbl {
                width: 120px;
                font-size: 10px;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 10px 12px 20px;
                padding-top: 65px;
            }

            .top-bar .page-title h1 {
                font-size: 18px;
            }

            .top-bar .page-title p {
                font-size: 11px;
            }

            .top-bar .header-actions .btn {
                font-size: 11px;
                padding: 4px 10px;
            }

            .notification-item {
                padding: 12px 14px;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>

            <nav class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <h4><i class="fas fa-graduation-cap me-2"></i>EduTrack</h4>
                    <small><?php echo h($tenantName); ?></small>
                </div>
                <div class="nav">
                    <div class="nav-label">Main</div>
                    <a class="nav-link" href="/platform/tenant/dashboard.php"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a>
                    <a class="nav-link" href="/platform/tenant/schools/index.php"><i class="fas fa-school"></i> <span>Schools</span></a>

                    <div class="nav-label mt-3">People</div>
                    <a class="nav-link" href="/platform/tenant/staff/index.php"><i class="fas fa-user-tie"></i> <span>Staff</span></a>
                    <a class="nav-link" href="/platform/tenant/students/index.php"><i class="fas fa-user-graduate"></i> <span>Students</span></a>

                    <div class="nav-label mt-3">System</div>
                    <a class="nav-link active" href="/platform/tenant/notifications.php"><i class="fas fa-bell"></i> <span>Notifications</span>
                        <?php if ($unreadCount > 0): ?>
                            <span class="badge bg-danger ms-1"><?php echo (int)$unreadCount; ?></span>
                        <?php endif; ?>
                    </a>
                    <a class="nav-link" href="/platform/tenant/settings/index.php"><i class="fas fa-cog"></i> <span>Settings</span></a>
                    <a class="nav-link" href="/platform/logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
                </div>
                <div class="sidebar-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="user-info">
                            <div class="user-avatar" id="userAvatar"><?php echo h($userAvatar); ?></div>
                            <div>
                                <div class="user-name" id="userName"><?php echo h($currentUser); ?></div>
                                <div class="user-role" id="userRole">Tenant Administrator</div>
                            </div>
                        </div>
                        <button class="logout-btn" onclick="logout()" title="Logout"><i class="fas fa-sign-out-alt"></i></button>
                    </div>
                </div>
            </nav>

            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-bell me-2"></i>Notifications</h1>
                        <p>Stay updated with your requests and system updates</p>
                    </div>
                    <div class="header-actions">
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#broadcastModal">
                            <i class="fas fa-bullhorn me-2"></i> Broadcast
                        </button>
                        <?php if ($unreadCount > 0): ?>
                            <form method="POST" action="/platform/tenant/notifications.php" style="display:inline-block;"
                                onsubmit="return confirm('Mark all notifications as read?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="mark_all_read">
                                <input type="hidden" name="tab" value="<?php echo h($tab); ?>">
                                <button type="submit" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-check-double me-2"></i> Mark All as Read
                                </button>
                            </form>
                        <?php endif; ?>
                        <button class="btn btn-outline-secondary" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt me-2"></i> Refresh
                        </button>
                    </div>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert-pro alert-pro-error" id="serverErrorBox">
                        <div class="alert-pro-icon"><i class="fas fa-times-circle"></i></div>
                        <div class="alert-pro-content">
                            <div class="alert-pro-title">Could not complete the request</div>
                            <ul class="alert-pro-list">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo h($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <button type="button" class="alert-pro-close" onclick="document.getElementById('serverErrorBox').remove()" aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>

                <?php if ($successMessage): ?>
                    <div class="alert-pro alert-pro-success" id="serverSuccessBox">
                        <div class="alert-pro-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="alert-pro-content">
                            <div class="alert-pro-title">Success!</div>
                            <div style="font-size:13px;"><?php echo h($successMessage); ?></div>
                        </div>
                        <button type="button" class="alert-pro-close" onclick="document.getElementById('serverSuccessBox').remove()" aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>

                <div class="tabs-nav">
                    <a href="<?php echo h(notifLink(['tab' => 'mine', 'unread' => null])); ?>"
                        class="tab-btn <?php echo $tab === 'mine' ? 'active' : ''; ?>">
                        <i class="fas fa-user me-1"></i> My notifications
                        <?php if ($unreadCount > 0): ?>
                            <span class="badge bg-danger ms-1"><?php echo (int)$unreadCount; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?php echo h(notifLink(['tab' => 'guardians', 'unread' => null])); ?>"
                        class="tab-btn <?php echo $tab === 'guardians' ? 'active' : ''; ?>">
                        <i class="fas fa-users me-1"></i> Sent to guardians
                    </a>
                </div>

                <form method="GET" action="/platform/tenant/notifications.php" class="filters-bar">
                    <input type="hidden" name="tab" value="<?php echo h($tab); ?>">
                    <div class="fg">
                        <label>Type</label>
                        <select name="type" class="form-select" onchange="this.form.submit()">
                            <option value="">All</option>
                            <?php foreach ($allowedTypes as $t): ?>
                                <option value="<?php echo h($t); ?>" <?php echo $filterType === $t ? 'selected' : ''; ?>>
                                    <?php echo h(ucfirst($t)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Priority</label>
                        <select name="priority" class="form-select" onchange="this.form.submit()">
                            <option value="">All</option>
                            <?php foreach ($allowedPriorities as $p): ?>
                                <option value="<?php echo h($p); ?>" <?php echo $filterPriority === $p ? 'selected' : ''; ?>>
                                    <?php echo h(ucfirst($p)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg" style="flex:0 0 auto;">
                        <label>&nbsp;</label>
                        <div class="form-check" style="height:36px; display:flex; align-items:center;">
                            <input class="form-check-input" type="checkbox" name="unread" value="1"
                                id="filterUnread" <?php echo $filterUnread ? 'checked' : ''; ?>
                                onchange="this.form.submit()">
                            <label class="form-check-label ms-2" for="filterUnread" style="font-size:13px;">Unread only</label>
                        </div>
                    </div>
                    <div class="fg" style="flex:0 0 auto;">
                        <label>&nbsp;</label>
                        <a href="/platform/tenant/notifications.php?tab=<?php echo h($tab); ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
                    </div>
                </form>

                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6>
                            <i class="fas fa-bell me-2 text-primary"></i>
                            <?php echo $tab === 'guardians' ? 'Sent to guardians' : 'All Notifications'; ?>
                        </h6>
                        <span class="text-muted small">
                            <?php if ($unreadCount > 0 && $tab === 'mine'): ?>
                                <span class="badge bg-danger"><?php echo (int)$unreadCount; ?> unread</span>
                            <?php endif; ?>
                            <?php echo count($notifications); ?> shown
                        </span>
                    </div>
                    <div class="card-body-custom">
                        <?php if (empty($notifications)): ?>
                            <div style="text-align:center;padding:40px 0;color:#6c757d;">
                                <i class="fas fa-bell-slash" style="font-size:48px;opacity:0.3;display:block;margin-bottom:16px;"></i>
                                <h5 style="font-weight:600;color:#1a1a2e;">No Notifications</h5>
                                <p style="margin:0;">You're all caught up! 🎉</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($notifications as $notification):
                                $nid     = (int)$notification['id'];
                                $isRead  = (int)$notification['is_read'] === 1;
                                $type    = (string)$notification['type'];
                                $prio    = (string)($notification['priority'] ?? 'normal');
                                $role    = (string)($notification['recipient_role'] ?? 'staff');
                                $iconCls = in_array($type, ['approved', 'rejected', 'info'], true) ? $type : 'info';
                                if ($prio === 'urgent') $iconCls = 'urgent';
                                $icon = 'fa-info-circle';
                                if ($type === 'approved')      $icon = 'fa-check-circle';
                                elseif ($type === 'rejected')  $icon = 'fa-times-circle';
                                elseif ($type === 'discipline') $icon = 'fa-gavel';
                                elseif ($type === 'behaviour') $icon = 'fa-star-half-alt';
                                elseif ($type === 'finance')   $icon = 'fa-money-bill-wave';
                                elseif ($type === 'result')    $icon = 'fa-file-alt';
                                elseif ($type === 'promotion') $icon = 'fa-level-up-alt';
                                elseif ($type === 'attendance') $icon = 'fa-calendar-check';
                                elseif ($type === 'reminder')  $icon = 'fa-clock';
                                elseif ($type === 'biometric') $icon = 'fa-fingerprint';
                                elseif ($type === 'registration') $icon = 'fa-user-plus';
                                elseif ($type === 'credentials')  $icon = 'fa-key';
                                elseif ($type === 'escalation')   $icon = 'fa-exclamation-circle';
                                if ($prio === 'urgent') $icon = 'fa-exclamation-triangle';

                                $rt = (string)($notification['related_type'] ?? '');
                                $rid = (int)($notification['related_id'] ?? 0);
                                $linked = ($rt === 'guardian_requests' && $rid > 0 && isset($linkedRequests[$rid]))
                                    ? $linkedRequests[$rid] : null;
                            ?>
                                <div class="notification-item <?php echo $isRead ? '' : 'unread'; ?>">
                                    <div class="notification-icon <?php echo h($iconCls); ?>">
                                        <i class="fas <?php echo h($icon); ?>"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title">
                                            <?php echo h($notification['title']); ?>
                                            <?php if (!$isRead): ?>
                                                <span class="notification-badge">New</span>
                                            <?php endif; ?>
                                            <?php if ($tab === 'guardians'): ?>
                                                <span class="pill gray ms-2"><?php echo h(ucfirst($role)); ?></span>
                                            <?php endif; ?>
                                            <?php if ($prio !== 'normal'): ?>
                                                <span class="pill <?php echo $prio === 'urgent' ? 'red' : 'orange'; ?> ms-2">
                                                    <?php echo h(ucfirst($prio)); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="notification-message">
                                            <?php echo h($notification['message']); ?>
                                        </div>
                                        <div class="notification-time">
                                            <i class="fas fa-clock me-1"></i>
                                            <?php echo h(date('M d, Y h:i A', strtotime($notification['created_at']))); ?>
                                            <span class="ms-2" style="color:#adb5bd;">· <?php echo h(ucfirst($type)); ?></span>
                                        </div>
                                        <?php if ($linked !== null): ?>
                                            <button type="button"
                                                class="notification-link mt-1 d-inline-block"
                                                style="background:none;border:0;padding:0;cursor:pointer;"
                                                data-bs-toggle="modal"
                                                data-bs-target="#greq<?php echo $nid; ?>">
                                                View Details <i class="fas fa-arrow-right ms-1"></i>
                                            </button>
                                        <?php elseif (!empty($notification['link'])): ?>
                                            <a href="<?php echo h($notification['link']); ?>" class="notification-link mt-1 d-inline-block">
                                                View Details <i class="fas fa-arrow-right ms-1"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="row-actions">
                                        <?php if (!$isRead): ?>
                                            <form method="POST" action="/platform/tenant/notifications.php" style="display:inline-block;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="mark_read">
                                                <input type="hidden" name="id" value="<?php echo $nid; ?>">
                                                <input type="hidden" name="tab" value="<?php echo h($tab); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary" title="Mark as read">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($tab === 'mine'): ?>
                                            <form method="POST" action="/platform/tenant/notifications.php" style="display:inline-block;"
                                                onsubmit="return confirm('Delete this notification?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $nid; ?>">
                                                <input type="hidden" name="tab" value="<?php echo h($tab); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- [v2.5/v2.6] Guardian-request modals — one per linked notification.
         v2.6 adds a reply form to each modal. -->
    <?php if (!empty($linkedRequests)):
        $greqTypeLabels = [
            'general'       => 'General enquiry',
            'record_update' => 'Student record update',
            'transcript'    => 'Student transcript request',
            'payment'       => 'Fee or payment enquiry',
            'medical'       => 'Medical or health note',
            'other'         => 'Other',
        ];
        $greqStatusPill = [
            'open'      => 'blue',
            'in_review' => 'orange',
            'resolved'  => 'green',
            'rejected'  => 'red',
            'cancelled' => 'gray',
        ];
        $greqStatusLabel = [
            'open'      => 'Open',
            'in_review' => 'In review',
            'resolved'  => 'Resolved',
            'rejected'  => 'Rejected',
            'cancelled' => 'Cancelled',
        ];
        foreach ($notifications as $notification):
            $nid = (int)$notification['id'];
            $rt  = (string)($notification['related_type'] ?? '');
            $rid = (int)($notification['related_id'] ?? 0);
            if ($rt !== 'guardian_requests' || $rid <= 0 || !isset($linkedRequests[$rid])) continue;
            $greq = $linkedRequests[$rid];

            $gName = trim(
                ($greq['guardian_first_name']  ?? '') . ' ' .
                    ($greq['guardian_middle_name'] ?? '') . ' ' .
                    ($greq['guardian_last_name']   ?? '')
            );
            $gName = $gName !== '' ? preg_replace('/\s+/', ' ', $gName) : '—';

            $sName = '';
            if (!empty($greq['student_first_name']) || !empty($greq['student_last_name'])) {
                $sName = trim(
                    ($greq['student_first_name']  ?? '') . ' ' .
                        ($greq['student_middle_name'] ?? '') . ' ' .
                        ($greq['student_last_name']   ?? '')
                );
                $sName = $sName !== '' ? preg_replace('/\s+/', ' ', $sName) : '';
            }
            if (!empty($greq['student_class_name'])) {
                $sName = $sName !== '' ? ($sName . ' (' . $greq['student_class_name'] . ')') : $greq['student_class_name'];
            }
            if ($sName === '') $sName = '—';

            $typeKey = (string)($greq['request_type'] ?? '');
            $typeLbl = $greqTypeLabels[$typeKey] ?? ucfirst($typeKey);

            $statusKey = (string)($greq['status'] ?? 'open');
            $sPill  = $greqStatusPill[$statusKey]  ?? 'gray';
            $sLabel = $greqStatusLabel[$statusKey] ?? ucfirst($statusKey);

            $contact = [];
            if (!empty($greq['guardian_email'])) $contact[] = (string)$greq['guardian_email'];
            if (!empty($greq['guardian_phone'])) $contact[] = (string)$greq['guardian_phone'];
            $contactStr = $contact ? implode(' · ', $contact) : '—';
    ?>
            <div class="modal fade" id="greq<?php echo $nid; ?>" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5><i class="fas fa-file-signature text-primary me-2"></i>Guardian Request</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="greq-head">
                                <div class="greq-subject"><?php echo h($greq['subject']); ?></div>
                                <span class="pill <?php echo h($sPill); ?>">
                                    <i class="fas fa-circle" style="font-size:6px;"></i>
                                    <?php echo h($sLabel); ?>
                                </span>
                            </div>

                            <table class="greq-kv">
                                <tbody>
                                    <tr>
                                        <td class="greq-kv-lbl">Request type</td>
                                        <td class="greq-kv-val"><?php echo h($typeLbl); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="greq-kv-lbl">Requested by</td>
                                        <td class="greq-kv-val"><?php echo h($gName); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="greq-kv-lbl">Contact</td>
                                        <td class="greq-kv-val"><?php echo h($contactStr); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="greq-kv-lbl">About student</td>
                                        <td class="greq-kv-val"><?php echo h($sName); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="greq-kv-lbl">Submitted</td>
                                        <td class="greq-kv-val"><?php echo h(date('M d, Y h:i A', strtotime((string)$greq['created_at']))); ?></td>
                                    </tr>
                                    <?php if (!empty($greq['resolved_at'])): ?>
                                        <tr>
                                            <td class="greq-kv-lbl">Resolved</td>
                                            <td class="greq-kv-val"><?php echo h(date('M d, Y h:i A', strtotime((string)$greq['resolved_at']))); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>

                            <div class="greq-section">Description</div>
                            <div class="greq-body"><?php echo nl2br(h((string)$greq['body'])); ?></div>

                            <?php if (!empty($greq['resolution_note'])): ?>
                                <div class="greq-section">Current reply</div>
                                <div class="greq-resolution"><?php echo h((string)$greq['resolution_note']); ?></div>
                            <?php endif; ?>

                            <?php if ($statusKey === 'cancelled'): ?>
                                <div class="greq-section">Reply</div>
                                <div style="font-size:12px;color:#6c757d;padding:6px 0;">
                                    This request was cancelled by the guardian. Replies are not available.
                                </div>
                            <?php else: ?>
                                <form method="POST" action="/platform/tenant/notifications.php" class="greq-reply-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reply_request">
                                    <input type="hidden" name="request_id" value="<?php echo (int)$greq['id']; ?>">

                                    <div class="greq-section">Reply to guardian</div>
                                    <div class="mb-3">
                                        <label class="form-label" for="greq_note_<?php echo $nid; ?>">Response</label>
                                        <textarea class="form-control" id="greq_note_<?php echo $nid; ?>"
                                            name="resolution_note" rows="4" required
                                            placeholder="Write your response to the guardian"></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="greq_status_<?php echo $nid; ?>">Set status</label>
                                        <select class="form-select" id="greq_status_<?php echo $nid; ?>" name="new_status" required>
                                            <option value="in_review" <?php echo $statusKey === 'in_review' ? 'selected' : ''; ?>>In review</option>
                                            <option value="resolved" <?php echo $statusKey === 'resolved'  ? 'selected' : ''; ?>>Resolved</option>
                                            <option value="rejected" <?php echo $statusKey === 'rejected'  ? 'selected' : ''; ?>>Rejected</option>
                                        </select>
                                    </div>
                                    <div style="display:flex;justify-content:flex-end;gap:8px;">
                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-paper-plane me-1"></i> Send reply
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
    <?php
        endforeach;
    endif;
    ?>

    <!-- P7 — Broadcast Announcement Modal -->
    <div class="modal fade" id="broadcastModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/notifications.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="broadcast">
                    <div class="modal-header">
                        <h5><i class="fas fa-bullhorn text-primary me-2"></i>Broadcast Announcement</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="b_title">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="b_title" name="broadcast_title" maxlength="150" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="b_message">Message <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="b_message" name="broadcast_message" rows="4" required></textarea>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="b_audience">Audience</label>
                                <select class="form-select" id="b_audience" name="broadcast_audience">
                                    <option value="guardians" selected>All guardians</option>
                                    <option value="staff">All staff</option>
                                    <option value="both">Both guardians and staff</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="b_priority">Priority</label>
                                <select class="form-select" id="b_priority" name="broadcast_priority">
                                    <option value="low">Low</option>
                                    <option value="normal" selected>Normal</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>
                        <p style="font-size:12px;color:#6c757d;margin-top:12px;">
                            Use this for PTA meeting notices, mid-term break announcements, and other school-wide messages.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane me-1"></i> Send Broadcast
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/notifications-bell.js" defer></script>
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.getElementById('sidebarToggle');
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                document.getElementById('sidebar').classList.remove('open');
            }
        });

        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '/platform/logout.php';
            }
        }

        function loadUserInfo() {
            const userStr = localStorage.getItem('user');
            if (userStr) {
                try {
                    const user = JSON.parse(userStr);
                    document.getElementById('userName').textContent = user.first_name || 'Admin';
                    document.getElementById('userAvatar').textContent = (user.first_name || 'A').charAt(0);
                    document.getElementById('userRole').textContent = (user.roles || ['Administrator'])[0];
                } catch (e) {}
            }
        }
        const successBox = document.getElementById('serverSuccessBox');
        if (successBox) {
            setTimeout(() => {
                successBox.style.transition = 'opacity 0.3s ease';
                successBox.style.opacity = '0';
                setTimeout(() => successBox.remove(), 300);
            }, 6000);
        }
        document.addEventListener('DOMContentLoaded', loadUserInfo);
    </script>
</body>

</html>