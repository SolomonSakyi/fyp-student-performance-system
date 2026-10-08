<?php
/**
 * student.php
 * 
 * Student Attendance Handler
 */

function handleStudent($params, $method, $action)
{
    if ($method !== 'GET') {
        ApiResponse::methodNotAllowed('Use GET method');
    }

    try {
        $user = ApiAuth::authenticateUser();
    } catch (Exception $e) {
        ApiResponse::unauthorized('Authentication required');
    }

    if (empty($params['student_id'])) {
        ApiResponse::validationError(['student_id' => 'Student ID is required']);
    }

    $service = new AttendanceService();

    $studentId = $params['student_id'];
    $termId = $params['term_id'] ?? null;
    $limit = (int)($params['limit'] ?? 50);

    $filters = [
        'term_id' => $termId,
        'start_date' => $params['start_date'] ?? null,
        'end_date' => $params['end_date'] ?? null
    ];

    $records = $service->getStudentAttendance($studentId, $filters);
    $summary = $termId ? $service->getStudentSummary($studentId, $termId) : null;

    ApiResponse::success([
        'student_id' => $studentId,
        'records' => array_slice($records, 0, $limit),
        'total' => count($records),
        'summary' => $summary
    ]);
}