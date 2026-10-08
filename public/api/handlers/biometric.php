<?php
/**
 * biometric.php
 * 
 * Biometric Device Check-in Handler
 * Supports fingerprint, facial recognition devices
 * 
 * @package EduTrack
 * @subpackage API
 */

function handleBiometric($params, $method, $action)
{
    if ($method !== 'POST') {
        ApiResponse::methodNotAllowed('Use POST method');
    }

    ApiAuth::rateLimit('biometric');

    // Authenticate device
    try {
        $device = ApiAuth::authenticateDevice();
    } catch (Exception $e) {
        ApiResponse::unauthorized('Device authentication failed');
    }

    if (empty($params['identifier'])) {
        ApiResponse::validationError(['identifier' => 'Identifier is required']);
    }

    $db = DatabaseHelper::getInstance();

    $identifier = $params['identifier'];
    $schoolId = $params['school_id'] ?? 1;
    $checkType = $params['check_type'] ?? 'in';
    $confidence = $params['confidence'] ?? 100;

    // Log device activity
    $db->query("
        INSERT INTO attendance_device_logs (
            uuid, school_id, device_id, log_timestamp, log_type,
            identifier, raw_data, match_status, is_active
        ) VALUES (
            UUID(), ?, ?, NOW(), ?,
            ?, ?, 'pending', 1
        )
    ", [
        $schoolId,
        $device['id'],
        $checkType,
        $identifier,
        $params['raw_data'] ?? null
    ]);

    $logId = $db->lastInsertId();

    // Find student by identifier
    $student = $db->fetchOne("
        SELECT id, first_name, last_name, admission_number
        FROM students 
        WHERE (admission_number = ? OR id = ?) 
        AND school_id = ? 
        AND is_active = 1
        LIMIT 1
    ", [$identifier, $identifier, $schoolId]);

    if (!$student) {
        // Try RFID card
        $card = $db->fetchOne("
            SELECT student_id FROM attendance_rfid_cards 
            WHERE uid = ? AND is_active = 1 AND is_blocked = 0
        ", [$identifier]);

        if ($card) {
            $student = $db->fetchOne("
                SELECT id, first_name, last_name, admission_number
                FROM students 
                WHERE id = ? AND is_active = 1
            ", [$card['student_id']]);
        }
    }

    if (!$student) {
        // Update log as unmatched
        $db->query("
            UPDATE attendance_device_logs 
            SET match_status = 'unmatched', processed = 1, processed_date = NOW()
            WHERE id = ?
        ", [$logId]);

        ApiResponse::error('Student not found or not enrolled', 404);
    }

    // Update log with match
    $db->query("
        UPDATE attendance_device_logs 
        SET match_status = 'matched', 
            matched_student_id = ?, 
            confidence_score = ?,
            processed = 1, 
            processed_date = NOW()
        WHERE id = ?
    ", [$student['id'], $confidence, $logId]);

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
                ?, ?, CONCAT('BIO_', ?, '_', REPLACE(?, '-', '')), 1, 1
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
            'device' => $device['device_name'] ?? 'Biometric Device'
        ], 'Already checked in');
    }

    // Get biometric method
    $method = $db->fetchOne("
        SELECT id FROM attendance_methods 
        WHERE method_code = 'FP' AND is_active = 1 
        LIMIT 1
    ");

    $methodId = $method['id'] ?? 1;

    // Record attendance
    $db->query("
        INSERT INTO student_attendance (
            uuid, school_id, session_id, student_id, status_id, method_id, device_id,
            check_in_time, is_synced, is_verified, is_active
        ) VALUES (
            UUID(), ?, ?, ?, ?, ?, ?,
            ?, 1, 1, 1
        )
    ", [
        $schoolId,
        $sessionId,
        $student['id'],
        $statusId,
        $methodId,
        $device['id'],
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
        'device' => $device['device_name'] ?? 'Biometric Device',
        'device_type' => $device['device_type'] ?? 'fingerprint',
        'confidence_score' => $confidence,
        'check_in_time' => date('Y-m-d H:i:s'),
        'method' => 'Biometric'
    ], 'Attendance recorded successfully via biometric');
}