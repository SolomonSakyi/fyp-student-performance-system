<?php
/**
 * attendance.php
 * 
 * Mobile Attendance Handler
 * Returns attendance records with pagination and filtering
 * 
 * @package EduTrack
 * @subpackage API
 */

function handleMobileAttendance($params, $method, $action)
{
    // Authenticate user
    $user = MobileAuth::authenticate();

    // Rate limit
    MobileAuth::rateLimit('mobile_attendance');

    $db = DatabaseHelper::getInstance();
    $service = new AttendanceService();

    // Parse pagination parameters
    $page = max(1, (int)($params['page'] ?? 1));
    $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // Parse filter parameters
    $studentId = $params['student_id'] ?? null;
    $termId = $params['term_id'] ?? null;
    $statusId = $params['status_id'] ?? null;
    $startDate = $params['start_date'] ?? null;
    $endDate = $params['end_date'] ?? null;
    $search = $params['search'] ?? null;

    // Get student ID if user is a student
    if (!$studentId && $user['user_type'] === 'student') {
        $student = $db->fetchOne("
            SELECT id FROM students WHERE user_id = ? AND is_active = 1
        ", [$user['id']]);
        $studentId = $student['id'] ?? null;
    }

    if (!$studentId) {
        MobileApiResponse::validationError(['student_id' => 'Student ID is required']);
    }

    // Build base query
    $sql = "SELECT sa.*, 
                   ases.session_date, ases.session_type,
                   astatus.status_name, astatus.category, astatus.color,
                   am.method_name,
                   ad.device_name,
                   al.location_name
            FROM student_attendance sa
            JOIN attendance_sessions ases ON sa.session_id = ases.id
            LEFT JOIN attendance_statuses astatus ON sa.status_id = astatus.id
            LEFT JOIN attendance_methods am ON sa.method_id = am.id
            LEFT JOIN attendance_devices ad ON sa.device_id = ad.id
            LEFT JOIN attendance_locations al ON sa.location_id = al.id
            WHERE sa.student_id = ? AND sa.is_active = 1";

    $countSql = "SELECT COUNT(*) as total FROM student_attendance sa 
                 JOIN attendance_sessions ases ON sa.session_id = ases.id
                 WHERE sa.student_id = ? AND sa.is_active = 1";

    $params = [$studentId];
    $countParams = [$studentId];

    // Apply filters
    if ($termId) {
        $sql .= " AND ases.academic_term_id = ?";
        $countSql .= " AND ases.academic_term_id = ?";
        $params[] = $termId;
        $countParams[] = $termId;
    }

    if ($statusId) {
        $sql .= " AND sa.status_id = ?";
        $countSql .= " AND sa.status_id = ?";
        $params[] = $statusId;
        $countParams[] = $statusId;
    }

    if ($startDate) {
        $sql .= " AND ases.session_date >= ?";
        $countSql .= " AND ases.session_date >= ?";
        $params[] = $startDate;
        $countParams[] = $startDate;
    }

    if ($endDate) {
        $sql .= " AND ases.session_date <= ?";
        $countSql .= " AND ases.session_date <= ?";
        $params[] = $endDate;
        $countParams[] = $endDate;
    }

    if ($search) {
        $sql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.admission_number LIKE ?)";
        $countSql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.admission_number LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $countParams[] = $searchTerm;
        $countParams[] = $searchTerm;
        $countParams[] = $searchTerm;
    }

    // Get total count
    $totalResult = $db->fetchOne($countSql, $countParams);
    $total = $totalResult['total'] ?? 0;

    // Apply sorting and pagination
    $sql .= " ORDER BY ases.session_date DESC, sa.check_in_time DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $records = $db->fetchAll($sql, $params);

    // Get summary statistics
    $summarySql = "SELECT 
                        COUNT(DISTINCT ases.id) AS total_sessions,
                        SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) AS present,
                        SUM(CASE WHEN astatus.is_absent = 1 THEN 1 ELSE 0 END) AS absent,
                        SUM(CASE WHEN astatus.is_late = 1 THEN 1 ELSE 0 END) AS late,
                        SUM(CASE WHEN astatus.is_excused = 1 THEN 1 ELSE 0 END) AS excused,
                        ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) AS percentage
                    FROM student_attendance sa
                    JOIN attendance_sessions ases ON sa.session_id = ases.id
                    JOIN attendance_statuses astatus ON sa.status_id = astatus.id
                    WHERE sa.student_id = ? AND sa.is_active = 1";

    $summaryParams = [$studentId];

    if ($termId) {
        $summarySql .= " AND ases.academic_term_id = ?";
        $summaryParams[] = $termId;
    }

    if ($startDate) {
        $summarySql .= " AND ases.session_date >= ?";
        $summaryParams[] = $startDate;
    }

    if ($endDate) {
        $summarySql .= " AND ases.session_date <= ?";
        $summaryParams[] = $endDate;
    }

    $summary = $db->fetchOne($summarySql, $summaryParams) ?? [];

    MobileApiResponse::paginated(
        $records,
        $total,
        $page,
        $limit,
        'Attendance records retrieved successfully'
    );
}