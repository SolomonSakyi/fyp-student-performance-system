<?php
/**
 * checkout.php
 * 
 * Student Check-out Handler
 */

function handleCheckout($params, $method, $action)
{
    if ($method !== 'POST') {
        ApiResponse::methodNotAllowed('Use POST method');
    }

    ApiAuth::rateLimit('checkout');

    try {
        $user = ApiAuth::authenticateUser();
    } catch (Exception $e) {
        ApiResponse::unauthorized('Authentication required');
    }

    if (empty($params['student_id'])) {
        ApiResponse::validationError(['student_id' => 'Student ID is required']);
    }

    $db = DatabaseHelper::getInstance();

    $studentId = $params['student_id'];
    $sessionId = $params['session_id'] ?? null;

    if (!$sessionId) {
        $session = $db->fetchOne("
            SELECT id FROM attendance_sessions 
            WHERE session_date = CURDATE() 
            AND is_completed = 0 
            AND is_active = 1
            LIMIT 1
        ");

        if (!$session) {
            ApiResponse::error('No active session found', 404);
        }

        $sessionId = $session['id'];
    }

    $attendance = $db->fetchOne("
        SELECT id, student_id, check_in_time 
        FROM student_attendance 
        WHERE student_id = ? AND session_id = ? AND is_active = 1
    ", [$studentId, $sessionId]);

    if (!$attendance) {
        ApiResponse::error('No attendance record found for this student', 404);
    }

    $db->query("
        UPDATE student_attendance 
        SET check_out_time = ?, updated_at = NOW() 
        WHERE id = ?
    ", [date('Y-m-d H:i:s'), $attendance['id']]);

    ApiResponse::success([
        'student_id' => $studentId,
        'session_id' => $sessionId,
        'check_in_time' => $attendance['check_in_time'],
        'check_out_time' => date('Y-m-d H:i:s')
    ], 'Check-out recorded successfully');
}