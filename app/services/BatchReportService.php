<?php

/**
 * Batch Report Service
 * Generates report cards for all students in a class
 */

require_once __DIR__ . '/ReportCardService.php';
require_once __DIR__ . '/../helpers/DatabaseHelper.php';
require_once __DIR__ . '/../helpers/LoggerHelper.php';

class BatchReportService
{
    private $db;
    private $logger;
    private $reportCardService;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->reportCardService = new ReportCardService();
    }

    /**
     * Generate report cards for all students in a class
     */
    public function generateClassReports($classSectionId, $academicYearId, $academicTermId)
    {
        $this->logger->info('Generating batch reports for class: ' . $classSectionId);

        // Get all students in the class
        $students = $this->getClassStudents($classSectionId, $academicYearId, $academicTermId);

        if (empty($students)) {
            return [
                'success' => false,
                'message' => 'No students found in this class.',
                'students' => []
            ];
        }

        $reports = [];
        foreach ($students as $student) {
            try {
                // Get the actual student_id from the database
                $studentId = $student['student_id'];
                
                // Check if data exists for this student
                $checkSql = "
                    SELECT id FROM student_grade_summary 
                    WHERE student_id = ? 
                      AND academic_year_id = ? 
                      AND academic_term_id = ?
                ";
                $exists = $this->db->fetchOne($checkSql, [$studentId, $academicYearId, $academicTermId]);
                
                if (!$exists) {
                    // No data for this student, skip
                    $reports[] = [
                        'student_id' => $studentId,
                        'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                        'success' => false,
                        'error' => 'No academic data found for this student.'
                    ];
                    continue;
                }
                
                $html = $this->reportCardService->generateHTML(
                    $studentId,
                    $academicYearId,
                    $academicTermId
                );

                $reports[] = [
                    'student_id' => $studentId,
                    'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                    'html' => $html,
                    'success' => true
                ];
            } catch (Exception $e) {
                $reports[] = [
                    'student_id' => $studentId ?? null,
                    'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        return [
            'success' => true,
            'class_section_id' => $classSectionId,
            'total_students' => count($students),
            'generated' => count(array_filter($reports, function($r) { return $r['success']; })),
            'failed' => count(array_filter($reports, function($r) { return !$r['success']; })),
            'reports' => $reports
        ];
    }

    /**
     * Generate a combined HTML page with all report cards
     */
    public function generateCombinedHTML($classSectionId, $academicYearId, $academicTermId)
    {
        $result = $this->generateClassReports($classSectionId, $academicYearId, $academicTermId);

        if (!$result['success']) {
            return '<h3>Error: ' . $result['message'] . '</h3>';
        }

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Class Report Cards</title>
    <style>
        @media print {
            .page-break { page-break-after: always; }
            .no-print { display: none; }
        }
        body { font-family: Arial, sans-serif; }
        .header { text-align: center; padding: 20px; border-bottom: 2px solid #000; margin-bottom: 20px; }
        .controls { text-align: center; padding: 20px; background: #f5f5f5; border-radius: 5px; margin-bottom: 20px; }
        .controls button { padding: 10px 20px; margin: 0 10px; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; }
        .btn-print { background: #2563eb; color: #fff; }
        .btn-print:hover { background: #1d4ed8; }
        .btn-download { background: #16a34a; color: #fff; }
        .btn-download:hover { background: #15803d; }
        .stats { text-align: center; font-size: 14px; margin: 10px 0; color: #555; }
        .page-break { page-break-after: always; border-bottom: 2px dashed #ccc; padding-bottom: 20px; margin-bottom: 20px; }
        .error-item { color: #dc2626; font-weight: bold; }
    </style>
</head>
<body>
    <div class="controls no-print">
        <h2>📄 Class Report Cards</h2>
        <p>Class: ' . $this->getClassName($classSectionId) . '</p>
        <div class="stats">
            Total: ' . $result['total_students'] . ' | Generated: ' . $result['generated'] . ' | Failed: ' . $result['failed'] . '
        </div>
        <button class="btn-print" onclick="window.print()">🖨️ Print All</button>
        <button class="btn-download" onclick="downloadAll()">📥 Download All (ZIP)</button>
        <hr>
    </div>';

        foreach ($result['reports'] as $report) {
            if ($report['success']) {
                $html .= '<div class="report-container">';
                $html .= $report['html'];
                $html .= '</div>';
                $html .= '<div class="page-break"></div>';
            } else {
                $html .= '<div class="error-item">❌ ' . htmlspecialchars($report['student_name']) . ': ' . htmlspecialchars($report['error']) . '</div>';
            }
        }

        $html .= '
    <script>
        function downloadAll() {
            alert("ZIP download coming soon! For now, use Print > Save as PDF.");
        }
    </script>
</body>
</html>';

        return $html;
    }

    /**
     * Get students in a class
     */
    private function getClassStudents($classSectionId, $academicYearId, $academicTermId)
    {
        try {
            // First try student_enrollments
            $sql = "
                SELECT DISTINCT 
                    s.id AS student_id,
                    s.first_name,
                    s.last_name,
                    s.admission_number
                FROM student_enrollments se
                JOIN students s ON se.student_id = s.id
                WHERE se.class_section_id = ?
                  AND se.academic_year_id = ?
                  AND se.academic_term_id = ?
                  AND se.enrollment_status = 'Active'
                  AND se.is_active = 1
                ORDER BY s.first_name, s.last_name
            ";

            $students = $this->db->fetchAll($sql, [$classSectionId, $academicYearId, $academicTermId]);
            
            if (!empty($students)) {
                return $students;
            }
        } catch (Exception $e) {
            $this->logger->warning('Could not fetch from student_enrollments: ' . $e->getMessage());
        }
        
        // Fallback: get students from grade_summary
        try {
            $sql = "
                SELECT DISTINCT 
                    s.id AS student_id,
                    s.first_name,
                    s.last_name,
                    s.admission_number
                FROM student_grade_summary sgs
                JOIN students s ON sgs.student_id = s.id
                WHERE sgs.class_section_id = ?
                  AND sgs.academic_year_id = ?
                  AND sgs.academic_term_id = ?
                  AND sgs.is_active = 1
                ORDER BY s.first_name, s.last_name
            ";

            $students = $this->db->fetchAll($sql, [$classSectionId, $academicYearId, $academicTermId]);
            
            if (!empty($students)) {
                return $students;
            }
        } catch (Exception $e) {
            $this->logger->warning('Could not fetch from student_grade_summary: ' . $e->getMessage());
        }
        
        // Final fallback: get all students
        $sql = "
            SELECT 
                s.id AS student_id,
                s.first_name,
                s.last_name,
                s.admission_number
            FROM students s
            WHERE s.school_id = 1
              AND s.is_active = 1
            ORDER BY s.first_name, s.last_name
            LIMIT 20
        ";

        return $this->db->fetchAll($sql, []);
    }

    /**
     * Get class name
     */
    private function getClassName($classSectionId)
    {
        $sql = "SELECT section_name FROM class_sections WHERE id = ?";
        $result = $this->db->fetchOne($sql, [$classSectionId]);
        return $result['section_name'] ?? 'Unknown Class';
    }
}