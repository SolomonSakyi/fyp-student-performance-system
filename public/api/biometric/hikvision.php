<?php

/**
 * Hikvision biometric event push endpoint
 *
 * @package EduTrack
 * @subpackage Public\Api\Biometric
 * @version 1.0
 * @filepath public/api/biometric/hikvision.php
 *
 * Session S17c-2b.
 *
 * This endpoint accepts HTTP POST from Hikvision devices configured with an
 * "HTTP Listening Host" URL. Devices send JSON or XML bodies with fields like
 * employeeNoString, eventTime, eventType, cardNo, name, major, minor.
 *
 * Authentication (B6):
 *   - URL credential: http://USER:PASS@host/api/biometric/hikvision.php
 *     The device embeds the credential in its configured URL. We read it from
 *     PHP_AUTH_USER / PHP_AUTH_PW, or from the URL if the server strips them.
 *   - Optional IP allowlist: if a matching device has allowed_ips set, the
 *     request IP must be in the list.
 *
 * Responses:
 *   200 OK with a short body on success
 *   401 on missing/invalid credentials
 *   403 on IP rejection
 *   400 on empty body
 *
 * We write every accepted event into biometric_events. Duplicate events
 * (same tenant, device, user no, timestamp, type) are ignored by the unique
 * index. No attendance row is created here — the operator processes events
 * on the Biometric Events page, which performs the derivation.
 */

// ============================================
// BOOTSTRAP
// ============================================
$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/config/config.php';
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';

function apiJson(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================
// READ BODY
// ============================================
$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') {
    apiJson(400, ['ok' => false, 'error' => 'Empty body.']);
}

// Parse payload: JSON first, then XML
$payload = null;
$decoded = json_decode($raw, true);
if (is_array($decoded)) {
    $payload = $decoded;
} else {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw);
    if ($xml !== false) {
        $payload = json_decode(json_encode($xml), true);
    }
}

if (!is_array($payload) || empty($payload)) {
    apiJson(400, ['ok' => false, 'error' => 'Unrecognised payload format.']);
}

// Some Hikvision firmware wraps the event in an "EventNotificationAlert" or
// "AcsEvent" node. Flatten one level if needed.
foreach (['EventNotificationAlert', 'AcsEvent', 'AccessControllerEvent'] as $wrap) {
    if (isset($payload[$wrap]) && is_array($payload[$wrap])) {
        $payload = array_merge($payload, $payload[$wrap]);
    }
}

// ============================================
// AUTH — URL credential
// ============================================
$user = $_SERVER['PHP_AUTH_USER'] ?? '';
$pass = $_SERVER['PHP_AUTH_PW']   ?? '';

if ($user === '' && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/^Basic\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
        $decodedAuth = base64_decode($m[1], true);
        if ($decodedAuth !== false && strpos($decodedAuth, ':') !== false) {
            list($u, $p) = explode(':', $decodedAuth, 2);
            $user = $u;
            $pass = $p;
        }
    }
}

if ($user === '') {
    header('WWW-Authenticate: Basic realm="EduTrack Biometric"');
    apiJson(401, ['ok' => false, 'error' => 'Credentials required.']);
}

// ============================================
// MATCH DEVICE
// ============================================
$db = DatabaseHelper::getInstance();

$device = $db->fetchOne(
    "SELECT id, tenant_id, device_name, push_user, push_pass_hash, allowed_ips
     FROM biometric_devices
     WHERE push_user = ? AND push_enabled = 1 AND is_active = 1 AND deleted_at IS NULL
     ORDER BY id ASC LIMIT 1",
    [$user]
);

if (!$device) {
    apiJson(401, ['ok' => false, 'error' => 'Unknown push user.']);
}
if (empty($device['push_pass_hash']) || !password_verify($pass, $device['push_pass_hash'])) {
    apiJson(401, ['ok' => false, 'error' => 'Bad credentials.']);
}

// IP allowlist
if (!empty($device['allowed_ips'])) {
    $allowed = array_filter(array_map('trim', explode(',', (string)$device['allowed_ips'])));
    $remote  = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!empty($allowed) && !in_array($remote, $allowed, true)) {
        apiJson(403, ['ok' => false, 'error' => 'Source IP not allowed.']);
    }
}

$tenantId = (int)$device['tenant_id'];
$deviceId = (int)$device['id'];

// ============================================
// EXTRACT EVENT FIELDS
// ============================================
function pick(array $p, array $keys, $default = null)
{
    foreach ($keys as $k) {
        if (isset($p[$k]) && $p[$k] !== '') return $p[$k];
    }
    return $default;
}

$userNo = (string)pick($payload, ['employeeNoString', 'employeeNo', 'employee_no', 'user_id', 'userID', 'cardNo', 'card_no'], '');
$cardNo = (string)pick($payload, ['cardNo', 'card_no'], '');
$dtRaw  = (string)pick($payload, ['eventTime', 'dateTime', 'time', 'event_time'], date('Y-m-d H:i:s'));

$major  = (string)pick($payload, ['majorEventType', 'major', 'eventMajorType'], '');
$minor  = (string)pick($payload, ['subEventType', 'minor', 'eventSubType'], '');
$evt    = strtolower((string)pick($payload, ['eventType', 'event_type', 'eventName'], ''));

$eventType = 'unknown';
if ($evt !== '') {
    if (strpos($evt, 'in') !== false || strpos($evt, 'enter') !== false) $eventType = 'check_in';
    elseif (strpos($evt, 'out') !== false || strpos($evt, 'exit') !== false) $eventType = 'check_out';
    elseif (strpos($evt, 'fail') !== false) $eventType = 'verify_fail';
}
// Fall back on major/minor codes for Hikvision's access-control events
if ($eventType === 'unknown') {
    // major 5 (access control), minor 0x4b0 = successful, 0x4b1 = failed
    if ($major === '5' || $major === 'AccessControl') {
        if ($minor === '1200' || $minor === '4b0') $eventType = 'check_in';
        if ($minor === '1201' || $minor === '4b1') $eventType = 'verify_fail';
    }
}

if ($userNo === '') {
    apiJson(400, ['ok' => false, 'error' => 'No device user number in payload.']);
}

$ts = strtotime($dtRaw);
if ($ts === false) {
    apiJson(400, ['ok' => false, 'error' => 'Unparseable event time: ' . $dtRaw]);
}
$ts = date('Y-m-d H:i:s', $ts);

// ============================================
// STORE EVENT
// ============================================
function uuid(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

try {
    $db->insert(
        "INSERT INTO biometric_events
            (uuid, tenant_id, device_id, device_user_no, card_no,
             student_id, event_time, event_type, direction,
             is_processed, raw_payload, source, created_at)
         VALUES (?, ?, ?, ?, ?, NULL, ?, ?, 'unknown', 0, ?, 'push', NOW())",
        [
            uuid(),
            $tenantId,
            $deviceId,
            $userNo,
            $cardNo !== '' ? $cardNo : null,
            $ts,
            $eventType,
            $raw,
        ]
    );

    // Touch the device's last_event_at
    $db->execute(
        "UPDATE biometric_devices SET last_event_at = NOW() WHERE id = ?",
        [$deviceId]
    );

    apiJson(200, ['ok' => true, 'event_type' => $eventType, 'user' => $userNo, 'time' => $ts]);
} catch (Exception $e) {
    // Unique index violation → duplicate, still a success from the device's view
    if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), '1062') !== false) {
        apiJson(200, ['ok' => true, 'note' => 'Duplicate event ignored.']);
    }
    error_log('Hikvision push insert failed: ' . $e->getMessage());
    apiJson(500, ['ok' => false, 'error' => 'Could not store event.']);
}
