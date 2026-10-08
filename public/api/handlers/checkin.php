<?php
/**
 * checkin.php
 * 
 * Student Check-in Handler
 */

function handleCheckin($params, $method, $action)
{
    if ($method !== 'POST') {
        ApiResponse::methodNotAllowed('Use POST method');
    }

    ApiAuth::rateLimit('checkin');

    // Try user auth first, then device auth
    try {
        $user = ApiAuth::authenticateUser();
    } catch (Exception $e) {
        try {
            $device = ApiAuth::authenticateDevice();
        } catch (Exception $e2) {
            ApiResponse::unauthorized('Authentication required');
        }
    }

    $required = ['student_id', 'class_id'];
    foreach ($required as $field) {
        if (empty($params[$field])) {
            ApiResponse::validationError([$field => 'Field is required']);
        }
    }

    $service = new AttendanceService();

    $result = $service->markStudentAttendance([
        'student_id' => $params['student_id'],
        'class_id' => $params['class_id'],
        'date' => $params['date'] ?? date('Y-m-d'),
        'session_type' => $params['session_type'] ?? 'morning',
        'status' => $params['status'] ?? 'P',
        'method_id' => $params['method_id'] ?? 8,
        'device_id' => $params['device_id'] ?? null,
        'location_id' => $params['location_id'] ?? null,
        'check_in_time' => $params['check_in_time'] ?? date('Y-m-d H:i:s'),
        'is_late' => $params['is_late'] ?? 0,
        'late_minutes' => $params['late_minutes'] ?? null,
        'is_excused' => $params['is_excused'] ?? 0,
        'excused_reason' => $params['excused_reason'] ?? null,
        'notes' => $params['notes'] ?? null,
        'is_synced' => 1
    ]);

    // Check if result has the data we need
    if ($result['success']) {
        $responseData = [
            'student_id' => $params['student_id'],
            'check_in_time' => date('Y-m-d H:i:s')
        ];
        
        // Only add these if they exist
        if (isset($result['attendance_id'])) {
            $responseData['attendance_id'] = $result['attendance_id'];
        }
        if (isset($result['session_id'])) {
            $responseData['session_id'] = $result['session_id'];
        }
        if (isset($result['updated'])) {
            $responseData['updated'] = $result['updated'];
        }

        ApiResponse::success($responseData, $result['message']);
    } else {
        ApiResponse::error($result['message']);
    }
}