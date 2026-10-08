<?php
/**
 * results.php
 *
 * API Endpoint for Assessment Results
 *
 * @package EduTrack
 * @subpackage API
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Define base path
$basePath = dirname(__DIR__, 2) . '/';

require_once $basePath . 'app/helpers/DatabaseHelper.php';
require_once $basePath . 'app/services/Assessment/AssessmentService.php';

$assessmentService = new AssessmentService();
$db = DatabaseHelper::getInstance();

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'calculate':
            $classId = $_GET['class_id'] ?? null;
            $termId = $_GET['term_id'] ?? null;
            
            if (!$classId || !$termId) {
                throw new Exception('Class ID and Term ID required');
            }
            
            $result = $assessmentService->calculateClassResults((int)$classId, (int)$termId);
            echo json_encode($result);
            break;
            
        case 'publish':
            $classId = $_GET['class_id'] ?? null;
            $termId = $_GET['term_id'] ?? null;
            
            if (!$classId || !$termId) {
                throw new Exception('Class ID and Term ID required');
            }
            
            $result = $assessmentService->publishClassResults((int)$classId, (int)$termId);
            echo json_encode($result);
            break;
            
        case 'export':
            $classId = $_GET['class_id'] ?? null;
            $termId = $_GET['term_id'] ?? null;
            
            if (!$classId || !$termId) {
                throw new Exception('Class ID and Term ID required');
            }
            
            // Get results data
            $results = $db->fetchAll(
                "SELECT s.first_name, s.last_name, s.admission_number,
                        sub.subject_name, sub.subject_code,
                        ar.total_score, ar.total_grade, ar.grade_point,
                        ar.continuous_assessment_score, ar.exam_score,
                        ar.is_published
                 FROM assessment_results ar
                 JOIN students s ON ar.student_id = s.id
                 JOIN subjects sub ON ar.subject_id = sub.id
                 WHERE ar.class_section_id = ? AND ar.academic_term_id = ?
                 AND ar.is_active = 1
                 ORDER BY s.first_name, sub.subject_name",
                [$classId, $termId]
            );
            
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="results_' . $classId . '_' . $termId . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Student Name', 'Admission No', 'Subject', 'CA Score', 'Exam Score', 'Total Score', 'Grade', 'Status']);
            
            foreach ($results as $row) {
                fputcsv($output, [
                    $row['first_name'] . ' ' . $row['last_name'],
                    $row['admission_number'],
                    $row['subject_code'],
                    $row['continuous_assessment_score'] ?? '-',
                    $row['exam_score'] ?? '-',
                    $row['total_score'] ?? '-',
                    $row['total_grade'] ?? '-',
                    $row['is_published'] ? 'Published' : 'Draft'
                ]);
            }
            
            fclose($output);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>