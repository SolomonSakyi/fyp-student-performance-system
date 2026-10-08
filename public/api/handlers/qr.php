<?php
/**
 * qr.php
 * 
 * QR Code Check-in Handler
 */

function handleQr($params, $method, $action)
{
    if ($method !== 'POST') {
        ApiResponse::methodNotAllowed('Use POST method');
    }

    // Simple authentication
    $token = $params['token'] ?? null;
    if (!$token) {
        $token = $_GET['token'] ?? null;
    }
    
    if (!$token) {
        ApiResponse::unauthorized('Missing authentication token');
    }

    // Verify token
    $db = DatabaseHelper::getInstance();
    $user = $db->fetchOne("
        SELECT id, username, user_type 
        FROM users 
        WHERE api_token = ? AND is_active = 1
    ", [$token]);

    if (!$user) {
        ApiResponse::unauthorized('Invalid or expired token');
    }

    if (empty($params['qr_code'])) {
        ApiResponse::validationError(['qr_code' => 'QR code is required']);
    }

    $qrCode = $params['qr_code'];
    $schoolId = $params['school_id'] ?? 1;
    $classId = $params['class_id'] ?? 1;
    $date = $params['date'] ?? date('Y-m-d');
    $type = $params['session_type'] ?? 'morning';

    // Validate QR code
    $qr = $db->fetchOne("
        SELECT * FROM attendance_qr_codes 
        WHERE qr_code = ? AND is_active = 1 AND is_used = 0 
        AND expires_at > NOW() 
        LIMIT 1
    ", [$qrCode]);

    if (!$qr) {
        ApiResponse::error('Invalid or expired QR code', 404);
    }

    if (!$qr['student_id']) {
        ApiResponse::error('QR code not assigned to a student', 404);
    }

    // Mark QR as used
    $db->query("
        UPDATE attendance_qr_codes 
        SET is_used = 1, used_at = NOW() 
        WHERE id = ?
    ", [$qr['id']]);

    // Get student info
    $student = $db->fetchOne("
        SELECT id, first_name, last_name, admission_number
        FROM students 
        WHERE id = ? AND is_active = 1
    ", [$qr['student_id']]);

    if (!$student) {
        ApiResponse::error('Student not found', 404);
    }

    // Get or create session
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
                ?, ?, CONCAT('QR_', ?, '_', REPLACE(?, '-', '')), 1, 1
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
    ", [$sessionId, $student['id']]);

    if ($existing) {
        ApiResponse::success([
            'student' => $student,
            'session_id' => $sessionId,
            'already_recorded' => true,
            'qr_code' => $qrCode
        ], 'Already checked in');
    }

    // Get QR method
    $methodRow = $db->fetchOne("
        SELECT id FROM attendance_methods 
        WHERE method_code = 'QR' AND is_active = 1 
        LIMIT 1
    ");

    $methodId = $methodRow['id'] ?? 5;

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
        $student['id'],
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
        'student' => $student,
        'session_id' => $sessionId,
        'attendance_id' => $attendanceId,
        'qr_code' => $qrCode,
        'check_in_time' => date('Y-m-d H:i:s'),
        'method' => 'QR Code'
    ], 'Attendance recorded via QR code');
}