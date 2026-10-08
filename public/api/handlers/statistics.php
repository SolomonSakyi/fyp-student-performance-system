<?php
/**
 * statistics.php
 * 
 * Statistics Handler
 */

function handleStatistics($params, $method, $action)
{
    if ($method !== 'GET') {
        ApiResponse::methodNotAllowed('Use GET method');
    }

    try {
        $user = ApiAuth::authenticateUser();
    } catch (Exception $e) {
        ApiResponse::unauthorized('Authentication required');
    }

    $service = new AttendanceService();

    $filters = [
        'term_id' => $params['term_id'] ?? null,
        'class_id' => $params['class_id'] ?? null,
        'start_date' => $params['start_date'] ?? null,
        'end_date' => $params['end_date'] ?? null
    ];

    $stats = $service->getStatistics($filters);
    $today = $service->getTodaySummary();

    ApiResponse::success([
        'overall' => $stats,
        'today' => $today,
        'filters' => $filters
    ]);
}