<?php

/**
 * Notifications AJAX endpoints — JSON responses only.
 *
 * @package EduTrack
 * @filepath public/platform/tenant/notifications-ajax.php
 * @version 1.1 (S20: bootstrap + CSRF on state-changing actions)
 *
 * Actions:
 *   ?action=unread_count        (read-only, no CSRF)
 *   ?action=recent&limit=10     (read-only, no CSRF)
 *   ?action=mark_read&id=X      (state-changing, CSRF required)
 *   ?action=mark_all_read       (state-changing, CSRF required)
 *   ?action=delete&id=X         (state-changing, CSRF required)
 *
 * Session S17g decisions:
 *   NS9A  standalone AJAX file
 *   NS11A users can soft-delete their own in-app notifications
 *
 * Session S20 decisions:
 *   SH2A  per-session CSRF token; state-changing actions require it
 *   SH3A  central Security.php helper, loaded via app/bootstrap.php
 *   SH4A  hardened session started inside bootstrap
 *   SH10A generic error messages; real error logged
 */

// ------------------------------------------------------------------
// Bootstrap: hardened session, headers, HTTPS, DB, CSRF helpers.
// ------------------------------------------------------------------
$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/app/bootstrap.php';

// Every response from this endpoint is JSON.
header('Content-Type: application/json; charset=utf-8');

// ------------------------------------------------------------------
// Auth guard (JSON error path, not redirect)
// ------------------------------------------------------------------
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tenantId = current_tenant_id();
$userId   = current_user_id();
$isSuper  = is_super_admin();

if ($tenantId <= 0 || $userId <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No tenant context'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = $GLOBALS['db'] ?? null;
if (!$db) {
    // bootstrap.php already handled a failed connection with a JSON error.
    // If we reach here without $db, treat it as a server error.
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

// ------------------------------------------------------------------
// JSON response helper
// ------------------------------------------------------------------
function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * CSRF guard for state-changing actions. Reads the token from the
 * X-CSRF-Token header (AJAX) or the _csrf POST field, and returns a
 * JSON error if it does not match the session token.
 */
function verify_csrf_json(): void
{
    $sent     = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['_csrf'] ?? '';
    if ($expected === '' || $sent === '' || !hash_equals($expected, (string)$sent)) {
        respond(['ok' => false, 'error' => 'Invalid CSRF token'], 403);
    }
}

try {
    // ------------------------------------------------
    // unread_count (read-only, no CSRF)
    // ------------------------------------------------
    if ($action === 'unread_count') {
        $count = (int)($db->getValue(
            "SELECT COUNT(*) FROM notifications
              WHERE user_id = ?
                AND tenant_id = ?
                AND is_read = 0
                AND deleted_at IS NULL
                AND recipient_role = 'staff'",
            [$userId, $tenantId]
        ) ?? 0);
        respond(['ok' => true, 'count' => $count]);
    }

    // ------------------------------------------------
    // recent (read-only, no CSRF)
    // ------------------------------------------------
    if ($action === 'recent') {
        $limit = (int)($_GET['limit'] ?? 10);
        if ($limit < 1)   $limit = 10;
        if ($limit > 50)  $limit = 50;

        $rows = $db->fetchAll(
            "SELECT id, title, message, type, link, is_read, created_at
               FROM notifications
              WHERE user_id = ?
                AND tenant_id = ?
                AND deleted_at IS NULL
                AND recipient_role = 'staff'
              ORDER BY created_at DESC, id DESC
              LIMIT $limit",
            [$userId, $tenantId]
        );

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id'         => (int)$r['id'],
                'title'      => (string)$r['title'],
                'message'    => (string)$r['message'],
                'type'       => (string)$r['type'],
                'link'       => (string)($r['link'] ?? ''),
                'is_read'    => (int)$r['is_read'] === 1,
                'created_at' => (string)$r['created_at'],
            ];
        }
        respond(['ok' => true, 'items' => $items]);
    }

    // ------------------------------------------------
    // mark_read (state-changing, CSRF required)
    // ------------------------------------------------
    if ($action === 'mark_read') {
        verify_csrf_json();
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) respond(['ok' => false, 'error' => 'Missing id'], 400);

        $db->execute(
            "UPDATE notifications
                SET is_read = 1, read_at = NOW()
              WHERE id = ? AND user_id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$id, $userId, $tenantId]
        );
        respond(['ok' => true]);
    }

    // ------------------------------------------------
    // mark_all_read (state-changing, CSRF required)
    // ------------------------------------------------
    if ($action === 'mark_all_read') {
        verify_csrf_json();
        $db->execute(
            "UPDATE notifications
                SET is_read = 1, read_at = NOW()
              WHERE user_id = ? AND tenant_id = ? AND is_read = 0 AND deleted_at IS NULL",
            [$userId, $tenantId]
        );
        respond(['ok' => true]);
    }

    // ------------------------------------------------
    // delete (state-changing, CSRF required)
    // ------------------------------------------------
    if ($action === 'delete') {
        verify_csrf_json();
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) respond(['ok' => false, 'error' => 'Missing id'], 400);

        $db->execute(
            "UPDATE notifications
                SET deleted_at = NOW()
              WHERE id = ? AND user_id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$id, $userId, $tenantId]
        );
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Unknown action'], 400);
} catch (Exception $e) {
    error_log('notifications-ajax error: ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'Server error'], 500);
}
