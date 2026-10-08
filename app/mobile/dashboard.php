<?php
/**
 * dashboard.php
 * 
 * Mobile Dashboard Handler
 * Returns attendance summary and statistics for mobile app
 * 
 * @package EduTrack
 * @subpackage API
 */

function handleMobileDashboard($params, $method, $action)
{
    if ($method !== 'GET') {
        MobileApiResponse::methodNotAllowed('Use GET method');
    }

    // Authenticate user
    $user = MobileAuth::authenticate();

    // Rate limit
    MobileAuth::rateLimit('mobile_dashboard');

    $db = DatabaseHelper::getInstance();
    $service = new AttendanceService();

    // Get user ID from request or use authenticated user
    $userId = $params['user_id'] ?? $user['id'];
    $studentId = $params['student_id'] ?? null;
    $termId = $params['term_id'] ?? 1;

    // Get student ID if user is a student
    if (!$studentId && $user['user_type'] === 'student') {
        $student = $db->fetchOne("
            SELECT id FROM students WHERE user_id = ? AND is_active = 1
        ", [$userId]);
        $studentId = $student['id'] ?? null;
    }

    // If student_id provided, get student-specific data
    $studentData = null;
    if ($studentId) {
        $studentData = $db->fetchOne("
            SELECT s.id, s.first_name, s.last_name, s.admission_number,
                   gl.level_name, cs.section_name
            FROM students s
            LEFT JOIN student_enrollments se ON s.id = se.student_id AND se.is_active = 1
            LEFT JOIN class_sections cs ON se.class_section_id = cs.id
            LEFT JOIN grade_levels gl ON cs.grade_level_id = gl.id
            WHERE s.id = ? AND s.is_active = 1
        ", [$studentId]);
    }

    // Get today's attendance summary
    $todaySummary = $service->getTodaySummary();

    // Get student statistics if student_id provided
    $studentStats = null;
    if ($studentId && $termId) {
        $studentStats = $service->getStudentStatistics($studentId, $termId);
    }

    // Get recent attendance (last 7 days)
    $recentAttendance = [];
    if ($studentId) {
        $recentAttendance = $db->fetchAll("
            SELECT sa.*, 
                   ases.session_date, ases.session_type,
                   astatus.status_name, astatus.color
            FROM student_attendance sa
            JOIN attendance_sessions ases ON sa.session_id = ases.id
            JOIN attendance_statuses astatus ON sa.status_id = astatus.id
            WHERE sa.student_id = ? 
            AND sa.is_active = 1
            AND ases.session_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            ORDER BY ases.session_date DESC
        ", [$studentId]);
    }

    // Get weekly attendance trend
    $weeklyTrend = $db->fetchAll("
        SELECT 
            DAYNAME(ases.session_date) AS day_name,
            DATE(ases.session_date) AS session_date,
            COUNT(DISTINCT sa.id) AS attendance_count
        FROM attendance_sessions ases
        LEFT JOIN student_attendance sa ON sa.session_id = ases.id
        WHERE ases.session_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        AND ases.is_active = 1
        GROUP BY DATE(ases.session_date)
        ORDER BY ases.session_date ASC
    ");

    // Get unread notification count
    $unreadCount = 0;
    if ($studentId) {
        $unread = $db->fetchOne("
            SELECT COUNT(*) as count FROM attendance_notifications 
            WHERE student_id = ? AND is_read = 0 AND is_active = 1
        ", [$studentId]);
        $unreadCount = $unread['count'] ?? 0;
    }

    MobileApiResponse::success([
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'user_type' => $user['user_type']
        ],
        'student' => $studentData,
        'today' => [
            'total_expected' => (int)($todaySummary['total_expected'] ?? 0),
            'total_present' => (int)($todaySummary['total_present'] ?? 0),
            'total_absent' => (int)(($todaySummary['total_expected'] ?? 0) - ($todaySummary['total_present'] ?? 0)),
            'attendance_rate' => $todaySummary['total_expected'] > 0 
                ? round(($todaySummary['total_present'] / $todaySummary['total_expected']) * 100, 1) 
                : 0
        ],
        'student_stats' => $studentStats ? [
            'total_sessions' => (int)($studentStats['total_sessions'] ?? 0),
            'present' => (int)($studentStats['present'] ?? 0),
            'absent' => (int)($studentStats['absent'] ?? 0),
            'late' => (int)($studentStats['late'] ?? 0),
            'excused' => (int)($studentStats['excused'] ?? 0),
            'percentage' => (float)($studentStats['percentage'] ?? 0)
        ] : null,
        'recent_attendance' => $recentAttendance,
        'weekly_trend' => $weeklyTrend,
        'notifications' => [
            'unread_count' => $unreadCount
        ]
    ]);
}