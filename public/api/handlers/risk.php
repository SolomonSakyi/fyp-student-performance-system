<?php
/**
 * risk.php
 * 
 * Risk Analysis Handler
 * Identifies students at risk based on attendance patterns
 * 
 * @package EduTrack
 * @subpackage API
 */

function handleRisk($params, $method, $action)
{
    if ($method !== 'GET') {
        ApiResponse::methodNotAllowed('Use GET method');
    }

    // Authenticate user
    try {
        $user = ApiAuth::authenticateUser();
    } catch (Exception $e) {
        ApiResponse::unauthorized('Authentication required');
    }

    $classId = $params['class_id'] ?? null;
    $threshold = (float)($params['threshold'] ?? 75);
    $limit = (int)($params['limit'] ?? 100);
    $riskLevel = $params['risk_level'] ?? null;

    $db = DatabaseHelper::getInstance();

    try {
        // Build query - using session_date instead of academic_term_id
        $sql = "SELECT 
                    s.id, s.first_name, s.last_name, s.admission_number,
                    gl.level_name, cs.section_name,
                    COUNT(DISTINCT ases.id) AS total_sessions,
                    SUM(CASE WHEN astatus.is_absent = 1 THEN 1 ELSE 0 END) AS absences,
                    SUM(CASE WHEN astatus.is_late = 1 THEN 1 ELSE 0 END) AS lates,
                    ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) AS percentage,
                    CASE 
                        WHEN ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) >= 80 THEN 'Low'
                        WHEN ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) >= 60 THEN 'Moderate'
                        WHEN ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) >= 40 THEN 'High'
                        ELSE 'Critical'
                    END AS risk_level,
                    CASE 
                        WHEN ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) >= 80 THEN 'Good standing - no intervention needed'
                        WHEN ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) >= 60 THEN 'Monitor attendance - inform parents'
                        WHEN ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) >= 40 THEN 'Intervention required - parent meeting recommended'
                        ELSE 'Urgent intervention required - immediate action needed'
                    END AS recommendation
                FROM students s
                LEFT JOIN student_attendance sa ON s.id = sa.student_id AND sa.is_active = 1
                LEFT JOIN attendance_sessions ases ON sa.session_id = ases.id
                LEFT JOIN class_sections cs ON ases.class_section_id = cs.id
                LEFT JOIN grade_levels gl ON cs.grade_level_id = gl.id
                LEFT JOIN attendance_statuses astatus ON sa.status_id = astatus.id
                WHERE s.is_active = 1";

        $paramsSql = [];

        if ($classId) {
            $sql .= " AND cs.id = ?";
            $paramsSql[] = $classId;
        }

        $sql .= " GROUP BY s.id, cs.id, gl.id";

        // Apply filters
        if ($riskLevel) {
            $sql .= " HAVING risk_level = ?";
            $paramsSql[] = $riskLevel;
        } else {
            $sql .= " HAVING percentage < ?";
            $paramsSql[] = $threshold;
        }

        $sql .= " ORDER BY percentage ASC LIMIT ?";
        $paramsSql[] = $limit;

        $students = $db->fetchAll($sql, $paramsSql);

        // Get summary statistics
        $summarySql = "SELECT 
                            COUNT(DISTINCT s.id) AS total_students,
                            SUM(CASE WHEN astatus.is_absent = 1 THEN 1 ELSE 0 END) AS total_absences,
                            SUM(CASE WHEN astatus.is_late = 1 THEN 1 ELSE 0 END) AS total_lates,
                            ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) AS avg_attendance
                        FROM students s
                        LEFT JOIN student_attendance sa ON s.id = sa.student_id AND sa.is_active = 1
                        LEFT JOIN attendance_sessions ases ON sa.session_id = ases.id
                        LEFT JOIN attendance_statuses astatus ON sa.status_id = astatus.id
                        WHERE s.is_active = 1";

        $summaryParams = [];

        if ($classId) {
            $summarySql .= " AND ases.class_section_id = ?";
            $summaryParams[] = $classId;
        }

        $summary = $db->fetchOne($summarySql, $summaryParams);

        ApiResponse::success([
            'data' => $students,
            'summary' => [
                'total_at_risk' => count($students),
                'total_students' => $summary['total_students'] ?? 0,
                'total_absences' => $summary['total_absences'] ?? 0,
                'total_lates' => $summary['total_lates'] ?? 0,
                'avg_attendance' => $summary['avg_attendance'] ?? 0
            ],
            'filters' => [
                'class_id' => $classId,
                'threshold' => $threshold,
                'risk_level' => $riskLevel,
                'limit' => $limit
            ]
        ]);

    } catch (Exception $e) {
        ApiResponse::success([
            'data' => [],
            'summary' => [
                'total_at_risk' => 0,
                'total_students' => 0,
                'total_absences' => 0,
                'total_lates' => 0,
                'avg_attendance' => 0
            ],
            'filters' => [
                'class_id' => $classId,
                'threshold' => $threshold,
                'risk_level' => $riskLevel,
                'limit' => $limit
            ],
            'error' => $e->getMessage()
        ]);
    }
}