<?php
/**
 * staff.php
 * 
 * Staff Attendance Handler
 * Manages staff clock-in/clock-out and attendance
 * 
 * @package EduTrack
 * @subpackage API
 */

function handleStaff($params, $method, $action)
{
    // Authenticate user
    try {
        $user = ApiAuth::authenticateUser();
    } catch (Exception $e) {
        ApiResponse::unauthorized('Authentication required');
    }

    switch ($method) {
        case 'GET':
            if (!empty($params['staff_id'])) {
                handleGetStaffAttendance($params);
            } else {
                handleGetStaffList($params);
            }
            break;

        case 'POST':
            handleStaffCheckin($params);
            break;

        case 'PUT':
            handleStaffCheckout($params);
            break;

        default:
            ApiResponse::methodNotAllowed('Use GET, POST, or PUT');
    }
}

function handleGetStaffList($params)
{
    $db = DatabaseHelper::getInstance();
    $limit = (int)($params['limit'] ?? 100);
    $department = $params['department'] ?? null;

    $sql = "SELECT s.id, p.first_name, p.last_name, s.staff_number, s.designation, s.department
            FROM staff s
            JOIN people p ON s.person_id = p.id
            WHERE s.school_id = 1 AND s.is_active = 1";

    $paramsSql = [];

    if ($department) {
        $sql .= " AND s.department = ?";
        $paramsSql[] = $department;
    }

    $sql .= " LIMIT ?";
    $paramsSql[] = $limit;

    $staff = $db->fetchAll($sql, $paramsSql);

    ApiResponse::success([
        'staff' => $staff,
        'total' => count($staff)
    ]);
}

function handleGetStaffAttendance($params)
{
    if (empty($params['staff_id'])) {
        ApiResponse::validationError(['staff_id' => 'Staff ID is required']);
    }

    $db = DatabaseHelper::getInstance();

    $staffId = $params['staff_id'];
    $startDate = $params['start_date'] ?? null;
    $endDate = $params['end_date'] ?? null;
    $limit = (int)($params['limit'] ?? 50);

    $sql = "SELECT sa.*, 
                   astatus.status_name, astatus.category,
                   am.method_name,
                   ad.device_name,
                   al.location_name
            FROM staff_attendance sa
            LEFT JOIN attendance_statuses astatus ON sa.status_id = astatus.id
            LEFT JOIN attendance_methods am ON sa.method_id = am.id
            LEFT JOIN attendance_devices ad ON sa.device_id = ad.id
            LEFT JOIN attendance_locations al ON sa.location_id = al.id
            WHERE sa.staff_id = ? AND sa.is_active = 1";

    $paramsSql = [$staffId];

    if ($startDate) {
        $sql .= " AND sa.attendance_date >= ?";
        $paramsSql[] = $startDate;
    }

    if ($endDate) {
        $sql .= " AND sa.attendance_date <= ?";
        $paramsSql[] = $endDate;
    }

    $sql .= " ORDER BY sa.attendance_date DESC LIMIT ?";
    $paramsSql[] = $limit;

    $records = $db->fetchAll($sql, $paramsSql);

    // Get summary
    $summarySql = "SELECT 
                        COUNT(*) AS total_days,
                        SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) AS present_days,
                        SUM(CASE WHEN astatus.is_absent = 1 THEN 1 ELSE 0 END) AS absent_days,
                        SUM(CASE WHEN astatus.is_late = 1 THEN 1 ELSE 0 END) AS late_days,
                        ROUND(AVG(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0) * 100, 2) AS attendance_percentage,
                        SUM(sa.overtime_hours) AS total_overtime
                    FROM staff_attendance sa
                    JOIN attendance_statuses astatus ON sa.status_id = astatus.id
                    WHERE sa.staff_id = ? AND sa.is_active = 1";

    $summaryParams = [$staffId];

    if ($startDate) {
        $summarySql .= " AND sa.attendance_date >= ?";
        $summaryParams[] = $startDate;
    }

    if ($endDate) {
        $summarySql .= " AND sa.attendance_date <= ?";
        $summaryParams[] = $endDate;
    }

    $summary = $db->fetchOne($summarySql, $summaryParams);

    ApiResponse::success([
        'staff_id' => $staffId,
        'records' => $records,
        'total' => count($records),
        'summary' => $summary
    ]);
}

function handleStaffCheckin($params)
{
    if (empty($params['staff_id'])) {
        ApiResponse::validationError(['staff_id' => 'Staff ID is required']);
    }

    $db = DatabaseHelper::getInstance();
    $service = new AttendanceService();

    $staffId = $params['staff_id'];
    $date = $params['date'] ?? date('Y-m-d');
    $latitude = $params['latitude'] ?? null;
    $longitude = $params['longitude'] ?? null;
    $methodId = $params['method_id'] ?? 7;
    $deviceId = $params['device_id'] ?? null;
    $locationId = $params['location_id'] ?? null;

    // Check if already checked in today
    $existing = $db->fetchOne("
        SELECT id, check_in_time FROM staff_attendance 
        WHERE staff_id = ? AND attendance_date = ? AND is_active = 1
    ", [$staffId, $date]);

    if ($existing) {
        ApiResponse::success([
            'staff_id' => $staffId,
            'attendance_date' => $date,
            'check_in_time' => $existing['check_in_time'],
            'already_checked_in' => true
        ], 'Already checked in today');
    }

    // Get present status
    $status = $db->fetchOne("
        SELECT id FROM attendance_statuses 
        WHERE category = 'present' AND is_active = 1 
        LIMIT 1
    ");

    $statusId = $status['id'] ?? 1;

    // Check if staff should be marked late
    $isLate = 0;
    $lateMinutes = null;
    $lateThreshold = 15; // Default threshold

    if ($latitude && $longitude) {
        // Check if within geofence (optional)
    }

    $attendanceData = [
        'staff_id' => $staffId,
        'status_id' => $statusId,
        'method_id' => $methodId,
        'device_id' => $deviceId,
        'location_id' => $locationId,
        'attendance_date' => $date,
        'check_in_time' => date('Y-m-d H:i:s'),
        'check_in_latitude' => $latitude,
        'check_in_longitude' => $longitude,
        'is_late' => $isLate,
        'late_minutes' => $lateMinutes,
        'is_synced' => 1,
        'is_verified' => 1
    ];

    $attendanceId = $service->markStaffAttendance($attendanceData);

    if ($attendanceId['success']) {
        ApiResponse::success([
            'attendance_id' => $attendanceId['attendance_id'],
            'staff_id' => $staffId,
            'check_in_time' => date('Y-m-d H:i:s'),
            'is_late' => $isLate,
            'late_minutes' => $lateMinutes
        ], 'Staff check-in successful');
    } else {
        ApiResponse::error($attendanceId['message']);
    }
}

function handleStaffCheckout($params)
{
    if (empty($params['staff_id'])) {
        ApiResponse::validationError(['staff_id' => 'Staff ID is required']);
    }

    $db = DatabaseHelper::getInstance();

    $staffId = $params['staff_id'];
    $date = $params['date'] ?? date('Y-m-d');
    $latitude = $params['latitude'] ?? null;
    $longitude = $params['longitude'] ?? null;
    $overtimeHours = $params['overtime_hours'] ?? null;
    $notes = $params['notes'] ?? null;

    // Check if checked in today
    $attendance = $db->fetchOne("
        SELECT id, check_in_time FROM staff_attendance 
        WHERE staff_id = ? AND attendance_date = ? AND is_active = 1
    ", [$staffId, $date]);

    if (!$attendance) {
        ApiResponse::error('No check-in record found for today', 404);
    }

    // Update check-out
    $db->query("
        UPDATE staff_attendance 
        SET check_out_time = ?,
            check_out_latitude = ?,
            check_out_longitude = ?,
            overtime_hours = ?,
            notes = ?,
            updated_at = NOW()
        WHERE id = ?
    ", [
        date('Y-m-d H:i:s'),
        $latitude,
        $longitude,
        $overtimeHours,
        $notes,
        $attendance['id']
    ]);

    ApiResponse::success([
        'staff_id' => $staffId,
        'check_in_time' => $attendance['check_in_time'],
        'check_out_time' => date('Y-m-d H:i:s'),
        'overtime_hours' => $overtimeHours
    ], 'Staff check-out successful');
}