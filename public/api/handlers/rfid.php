<?php
/**
 * rfid.php
 * 
 * RFID Card Check-in Handler
 * Supports both Device Auth and User Auth
 * 
 * @package EduTrack
 * @subpackage API
 */

function handleRfid($params, $method, $action)
{
    if ($method !== 'POST') {
        ApiResponse::methodNotAllowed('Use POST method');
    }

    ApiAuth::rateLimit('rfid');

    // First check for user token in params or headers
    $token = $params['token'] ?? null;
    $headers = getallheaders();
    $headerToken = $headers['Authorization'] ?? null;
    $headerToken = str_replace('Bearer ', '', $headerToken);
    
    $user = null;
    $device = null;
    $authType = null;
    
    // Try User Authentication first (since you're passing token)
    try {
        if ($token || $headerToken) {
            // Temporarily set token for authentication
            if ($token) {
                $_GET['token'] = $token;
            } elseif ($headerToken) {
                $_GET['token'] = $headerToken;
            }
            $user = ApiAuth::authenticateUser();
            $authType = 'user';
        }
    } catch (Exception $e) {
        // User auth failed, try device auth
        try {
            $device = ApiAuth::authenticateDevice();
            $authType = 'device';
        } catch (Exception $e2) {
            ApiResponse::unauthorized('Authentication required. Use token parameter or X-API-Key header.');
        }
    }

    if (empty($params['uid'])) {
        ApiResponse::validationError(['uid' => 'Card UID is required']);
    }

    $db = DatabaseHelper::getInstance();

    $uid = $params['uid'];
    $schoolId = $params['school_id'] ?? 1;
    $checkType = $params['check_type'] ?? 'in';

    // Find RFID card
    $card = $db->fetchOne("
        SELECT r.*, s.id as student_id, s.first_name, s.last_name, s.admission_number
        FROM attendance_rfid_cards r
        LEFT JOIN students s ON r.student_id = s.id
        WHERE r.uid = ? AND r.is_active = 1 AND r.is_blocked = 0
    ", [$uid]);

    if (!$card) {
        ApiResponse::error('Invalid or blocked RFID card', 404);
    }

    if (!$card['student_id']) {
        ApiResponse::error('RFID card not assigned to any student', 404);
    }

    // Update last used
    $db->query("
        UPDATE attendance_rfid_cards 
        SET last_used = NOW() 
        WHERE id = ?
    ", [$card['id']]);

    // Get or create session
    $date = $params['date'] ?? date('Y-m-d');
    $type = $params['session_type'] ?? 'morning';
    $classId = $params['class_id'] ?? 1;

    $session = $db->fetchOne("
        SELECT id FROM attendance_sessions 
        WHERE class_section_id = ? 
        AND session_date = ? 
        AND session_type = ? 
        AND is_completed = 0 
        AND is_active = 1
    ", [$classId, $date, $type]);

    if (!$session) {
        $db->query("
            INSERT INTO attendance_sessions (
                uuid, school_id, class_section_id,
                session_date, session_type, session_code, total_expected, is_active
            ) VALUES (
                UUID(), ?, ?,
                ?, ?, CONCAT('RFID_', ?, '_', REPLACE(?, '-', '')), 1, 1
            )
        ", [
            $schoolId,
            $classId,
            $date,
            $type,
            $classId,
            $date
        ]);

        $sessionId = $db->lastInsertId();
    } else {
        $sessionId = $session['id'];
    }

    // Get present status
    $status = $db->fetchOne("
        SELECT id FROM attendance_statuses 
        WHERE category = 'present' AND is_active = 1 
        LIMIT 1
    ");

    $statusId = $status['id'] ?? 1;

    // Check if already recorded
    $existing = $db->fetchOne("
        SELECT id FROM student_attendance 
        WHERE session_id = ? AND student_id = ? AND is_active = 1
    ", [$sessionId, $card['student_id']]);

    if ($existing) {
        ApiResponse::success([
            'student' => [
                'id' => $card['student_id'],
                'name' => $card['first_name'] . ' ' . $card['last_name'],
                'admission' => $card['admission_number']
            ],
            'session_id' => $sessionId,
            'already_recorded' => true,
            'card_uid' => $uid,
            'auth_type' => $authType
        ], 'Already checked in');
    }

    // Get RFID method
    $methodRow = $db->fetchOne("
        SELECT id FROM attendance_methods 
        WHERE method_code = 'RFID' AND is_active = 1 
        LIMIT 1
    ");

    $methodId = $methodRow['id'] ?? 3;

    // Record attendance
    $db->query("
        INSERT INTO student_attendance (
            uuid, school_id, session_id, student_id, status_id, method_id,
            check_in_time, is_synced, is_verified, is_active
        ) VALUES (
            UUID(), ?, ?, ?, ?, ?,
            ?, 1, 1, 1
        )
    ", [
        $schoolId,
        $sessionId,
        $card['student_id'],
        $statusId,
        $methodId,
        date('Y-m-d H:i:s')
    ]);

    $attendanceId = $db->lastInsertId();

    // Update session counts
    $db->query("
        UPDATE attendance_sessions 
        SET total_present = total_present + 1 
        WHERE id = ?
    ", [$sessionId]);

    ApiResponse::success([
        'student' => [
            'id' => $card['student_id'],
            'name' => $card['first_name'] . ' ' . $card['last_name'],
            'admission' => $card['admission_number']
        ],
        'session_id' => $sessionId,
        'attendance_id' => $attendanceId,
        'card_uid' => $uid,
        'check_in_time' => date('Y-m-d H:i:s'),
        'method' => 'RFID Card',
        'auth_type' => $authType
    ], 'Attendance recorded via RFID');
}