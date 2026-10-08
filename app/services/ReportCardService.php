<?php

/**
 * Report Card Service
 * Generates professional report cards matching COCIS format
 */

require_once __DIR__ . '/../helpers/DatabaseHelper.php';
require_once __DIR__ . '/../helpers/LoggerHelper.php';

class ReportCardService
{
    private $db;
    private $logger;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
    }

    public function getReportCardData($studentId, $academicYearId, $academicTermId)
    {
        $sql = "
            SELECT 
                sgs.*,
                sah.height_cm,
                sah.weight_kg,
                sah.club_society,
                sah.favorite_color,
                sah.next_term_start_date,
                NULL AS vacation_date,
                sah.report_date,
                sah.teacher_name,
                sah.principal_name,
                sah.teacher_signature,
                sah.principal_signature,
                sah.attention_rating,
                sah.honesty_rating,
                sah.neatness_rating,
                sah.politeness_rating,
                sah.punctuality_rating,
                sah.self_control_rating,
                sah.obedience_rating,
                sah.reliability_rating,
                sah.responsibility_rating,
                sah.relationship_rating,
                sah.handling_tools_rating,
                sah.drawing_painting_rating,
                sah.handwriting_rating,
                sah.public_speaking_rating,
                sah.speech_fluency_rating,
                sah.sports_games_rating,
                s.admission_number,
                s.first_name,
                s.last_name,
                s.date_of_birth,
                s.gender,
                gl.level_name,
                cs.section_name,
                cs.section_code,
                ay.year_name,
                at.term_name,
                at.term_number,
                sch.school_name,
                sch.school_code
            FROM student_grade_summary sgs
            LEFT JOIN student_academic_history sah ON sgs.student_id = sah.student_id 
                AND sgs.academic_year_id = sah.academic_year_id 
                AND sgs.academic_term_id = sah.academic_term_id
            JOIN students s ON sgs.student_id = s.id
            JOIN class_sections cs ON sgs.class_section_id = cs.id
            JOIN grade_levels gl ON cs.grade_level_id = gl.id
            JOIN academic_years ay ON sgs.academic_year_id = ay.id
            JOIN academic_terms at ON sgs.academic_term_id = at.id
            JOIN schools sch ON sgs.school_id = sch.id
            WHERE sgs.student_id = ?
              AND sgs.academic_year_id = ?
              AND sgs.academic_term_id = ?
              AND sgs.is_active = 1
        ";

        $result = $this->db->fetchOne($sql, [$studentId, $academicYearId, $academicTermId]);

        if (!$result) {
            throw new Exception('Report card data not found for student ID: ' . $studentId);
        }

        $subjects = $this->getStudentSubjects($studentId, $academicYearId, $academicTermId);

        // Calculate grade count
        $gradeCount = $this->calculateGradeCount($subjects);

        return [
            'student' => $result,
            'subjects' => $subjects,
            'gradeCount' => $gradeCount
        ];
    }

    private function getStudentSubjects($studentId, $academicYearId, $academicTermId)
    {
        $sql = "
            SELECT 
                sub.subject_name,
                sub.subject_code,
                gc.ca_total,
                gc.exam_total,
                gc.total_score,
                gc.average,
                gc.grade,
                gc.class_position,
                gc.class_size
            FROM grade_calculations gc
            JOIN class_sections cs ON gc.class_section_id = cs.id
            JOIN grade_level_subjects gls ON cs.grade_level_id = gls.grade_level_id
            JOIN subjects sub ON gls.subject_id = sub.id
            WHERE gc.student_id = ?
              AND gc.academic_year_id = ?
              AND gc.academic_term_id = ?
              AND gc.is_calculated = 1
        ";

        return $this->db->fetchAll($sql, [$studentId, $academicYearId, $academicTermId]);
    }

    private function calculateGradeCount($subjects)
    {
        $counts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0, 'F' => 0];
        foreach ($subjects as $subject) {
            $grade = $subject['grade'] ?? '';
            if (isset($counts[$grade])) {
                $counts[$grade]++;
            }
        }
        return $counts;
    }

    private function calculateAge($dob)
    {
        if (!$dob) return 'N/A';
        $birthDate = new DateTime($dob);
        $today = new DateTime('today');
        $age = $birthDate->diff($today);
        return $age->y . 'yrs';
    }

    private function getSubjectRemark($score)
    {
        if ($score >= 80) return 'EXCELLENT';
        if ($score >= 66) return 'VERY GOOD';
        if ($score >= 46) return 'AVERAGE';
        if ($score >= 36) return 'PASS';
        return 'LOW AVERAGE';
    }

    private function getPositionOrdinal($position)
    {
        if (!$position) return '-';
        $suffixes = ['th', 'st', 'nd', 'rd'];
        $value = $position % 100;
        if ($value >= 11 && $value <= 13) {
            return $position . 'th';
        }
        return $position . ($suffixes[$position % 10] ?? 'th');
    }

    public function generateHTML($studentId, $academicYearId, $academicTermId)
    {
        $data = $this->getReportCardData($studentId, $academicYearId, $academicTermId);
        $student = $data['student'];
        $subjects = $data['subjects'];
        $gradeCount = $data['gradeCount'];

        return $this->buildReportCardHTML($student, $subjects, $gradeCount);
    }

    private function buildReportCardHTML($student, $subjects, $gradeCount)
    {
        $age = $this->calculateAge($student['date_of_birth'] ?? null);
        $totalObtainable = count($subjects) * 100;
        $totalObtained = $student['total_score'] ?? 0;
        $attendanceDays = $student['attendance_days'] ?? 0;
        $totalSchoolDays = $student['total_school_days'] ?? 0;
        $absentDays = $totalSchoolDays - $attendanceDays;
        $average = $student['average'] ?? 0;
        $overallGrade = $student['grade'] ?? '-';
        $position = $this->getPositionOrdinal($student['class_position'] ?? null);

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Card - <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></title>
    <style>
        @page {
            margin: 15px 20px;
            size: A4 portrait;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 11px;
            padding: 10px;
            background: #fff;
        }
        
        .report-card {
            max-width: 1000px;
            margin: 0 auto;
            border: 2px solid #000;
            padding: 20px 25px;
            font-family: 'Times New Roman', Times, serif;
        }
        
        /* HEADER */
        .header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 6px;
            margin-bottom: 8px;
        }
        
        .header .ges {
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 1px;
        }
        
        .header .school-name {
            font-size: 18px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .header .school-address {
            font-size: 10px;
            font-style: italic;
        }
        
        .header .report-title {
            font-size: 14px;
            font-weight: bold;
            text-decoration: underline;
            margin-top: 3px;
            letter-spacing: 0.5px;
        }
        
        /* STUDENT INFO - Single row */
        .student-info {
            display: grid;
            grid-template-columns: 1fr 0.6fr 1fr 0.5fr 0.8fr 1fr;
            gap: 2px 3px;
            padding: 3px 0;
            margin-bottom: 2px;
            font-size: 10px;
            font-weight: bold;
        }
        
        .student-info .info-item {
            display: flex;
            align-items: center;
        }
        
        .student-info .info-item .label {
            font-weight: bold;
            min-width: 30px;
        }
        
        .student-info .info-item .value {
            flex: 1;
            text-transform: uppercase;
            font-weight: normal;
            border-bottom: 1px dotted #000;
            padding-left: 3px;
        }
        
        /* ATTENDANCE - Single row */
        .attendance-row {
            display: grid;
            grid-template-columns: 0.8fr 0.8fr 0.7fr 0.6fr 0.8fr 0.7fr 0.8fr;
            gap: 2px 5px;
            padding: 3px 0;
            margin-bottom: 4px;
            font-size: 9.5px;
            font-weight: bold;
            background: #f5f5f5;
            border: 1px solid #ddd;
            padding: 4px 8px;
        }
        
        .attendance-row .att-item {
            display: flex;
            align-items: center;
        }
        
        .attendance-row .att-item .label {
            font-weight: bold;
            min-width: 35px;
            font-size: 8.5px;
        }
        
        .attendance-row .att-item .value {
            flex: 1;
            font-weight: normal;
        }
        
        /* PROFILE + POSITION */
        .profile-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            padding: 4px 0;
            margin-bottom: 4px;
            font-size: 10px;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
        }
        
        .profile-row .profile-text {
            font-style: italic;
            padding: 2px 0;
        }
        
        .profile-row .position-text {
            font-weight: bold;
            white-space: nowrap;
            padding: 2px 0;
        }
        
        /* SUBJECTS TABLE */
        .subjects-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
            margin: 4px 0;
        }
        
        .subjects-table th {
            background: #000;
            color: #fff;
            padding: 2px 3px;
            text-align: center;
            border: 1px solid #000;
            font-weight: bold;
            font-size: 8.5px;
        }
        
        .subjects-table td {
            padding: 2px 3px;
            border: 1px solid #000;
            text-align: center;
            font-size: 9px;
        }
        
        .subjects-table td.subject-name {
            text-align: left;
            padding-left: 5px;
            font-weight: bold;
        }
        
        .subjects-table td.remark-cell {
            text-align: left;
            padding-left: 4px;
        }
        
        /* PERFORMANCE SUMMARY */
        .performance-row {
            display: grid;
            grid-template-columns: 1fr 0.8fr 0.8fr 0.8fr;
            gap: 5px 15px;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 4px 0;
            margin: 3px 0;
            font-size: 10px;
            font-weight: bold;
        }
        
        .performance-row .perf-item {
            display: flex;
            align-items: center;
        }
        
        .performance-row .perf-item .label {
            font-weight: bold;
            min-width: 50px;
        }
        
        .performance-row .perf-item .value {
            flex: 1;
            font-weight: normal;
        }
        
        /* GRADE SCALE */
        .grade-scale {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr 1fr;
            gap: 3px 8px;
            padding: 3px 0;
            margin: 3px 0;
            font-size: 9px;
            border-bottom: 1px solid #000;
        }
        
        .grade-scale .gs-item {
            display: flex;
            gap: 2px;
        }
        
        .grade-scale .gs-item .range {
            font-weight: bold;
        }
        
        /* SUBJECT COUNT */
        .subject-count {
            font-size: 10px;
            font-weight: bold;
            padding: 2px 0;
            border-bottom: 1px solid #000;
            margin-bottom: 3px;
        }
        
        /* CONDUCT, ATTITUDE, REMARKS */
        .remarks-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2px 20px;
            padding: 4px 0;
            margin: 3px 0;
            border-bottom: 1px solid #000;
            font-size: 10px;
        }
        
        .remarks-section .remark-item {
            display: flex;
            flex-wrap: wrap;
        }
        
        .remarks-section .remark-item .label {
            font-weight: bold;
            min-width: 75px;
        }
        
        .remarks-section .remark-item .text {
            flex: 1;
        }
        
        /* SIGNATURES */
        .signatures-section {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 5px 15px;
            padding: 5px 0;
            margin: 3px 0;
            border-bottom: 1px solid #000;
            font-size: 10px;
        }
        
        .signatures-section .sig-item {
            text-align: center;
        }
        
        .signatures-section .sig-item .line {
            border-bottom: 1px solid #000;
            margin: 3px auto 2px;
            width: 140px;
        }
        
        /* TERM ANALYSIS */
        .term-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1.2fr 1.2fr;
            gap: 5px 20px;
            padding-top: 4px;
            font-size: 10px;
        }
        
        .term-row .term-item {
            display: flex;
            align-items: center;
        }
        
        .term-row .term-item .label {
            font-weight: bold;
            min-width: 55px;
        }
        
        .term-row .term-item .value {
            flex: 1;
        }
        
        .print-btn {
            background: #2563eb;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin: 15px 0;
            display: inline-block;
        }
        
        .print-btn:hover {
            background: #1d4ed8;
        }
        
        @media print {
            .print-btn { display: none; }
            body { padding: 0; }
            .report-card { border: none; padding: 10px 15px; }
            .attendance-row { background: none; border: 1px solid #000; }
        }
    </style>
</head>
<body>
    <div class="report-card">
        <!-- HEADER -->
        <div class="header">
            <div class="ges">GHANA EDUCATION SERVICE</div>
            <div class="school-name"><?php echo htmlspecialchars($student['school_name'] ?? 'CHURCH OF CHRIST INTERNATIONAL SCHOOL'); ?></div>
            <div class="school-address"><?php echo htmlspecialchars($student['school_code'] ?? 'P.O. BOX KS 4025, ADUM - KUMASI AHENSAN, ASOKWA MUNICIPAL'); ?></div>
            <div class="report-title"><?php echo htmlspecialchars($student['term_name'] ?? 'FIRST'); ?> TERM STUDENT'S PERFORMANCE REPORT</div>
        </div>

        <!-- STUDENT INFO - Single Row -->
        <div class="student-info">
            <div class="info-item"><span class="label">NAME:</span><span class="value"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></span></div>
            <div class="info-item"><span class="label">GENDER:</span><span class="value"><?php echo htmlspecialchars($student['gender'] ?? ''); ?></span></div>
            <div class="info-item"><span class="label">D.O.B:</span><span class="value"><?php echo htmlspecialchars($student['date_of_birth'] ?? ''); ?></span></div>
            <div class="info-item"><span class="label">AGE:</span><span class="value"><?php echo $age; ?></span></div>
            <div class="info-item"><span class="label">CODE:</span><span class="value"><?php echo htmlspecialchars($student['admission_number'] ?? ''); ?></span></div>
            <div class="info-item"><span class="label">CLASS:</span><span class="value"><?php echo htmlspecialchars($student['section_name'] ?? ''); ?></span></div>
        </div>

        <!-- ATTENDANCE - Single Row -->
        <div class="attendance-row">
            <div class="att-item"><span class="label">ROLL:</span><span class="value"><?php echo $student['class_size'] ?? 0; ?></span></div>
            <div class="att-item"><span class="label">TOTAL SCORE:</span><span class="value"><?php echo number_format($totalObtained, 0); ?></span></div>
            <div class="att-item"><span class="label">OUT OF:</span><span class="value"><?php echo $totalObtainable; ?></span></div>
            <div class="att-item"><span class="label">GRADE:</span><span class="value"><?php echo htmlspecialchars($overallGrade); ?></span></div>
            <div class="att-item"><span class="label">OPENED:</span><span class="value"><?php echo $totalSchoolDays; ?></span></div>
            <div class="att-item"><span class="label">PRESENT:</span><span class="value"><?php echo $attendanceDays; ?></span></div>
            <div class="att-item"><span class="label">ABSENT:</span><span class="value"><?php echo $absentDays; ?></span></div>
        </div>

        <!-- PROFILE + POSITION -->
        <div class="profile-row">
            <span class="profile-text"><?php echo htmlspecialchars($student['personality_profile'] ?? 'A gritty achiever, powering through challenges with sheer determination and a strong work ethic.'); ?></span>
            <span class="position-text">POSITION: <?php echo $position; ?></span>
        </div>

        <!-- SUBJECTS TABLE -->
        <table class="subjects-table">
            <thead>
                <tr>
                    <th width="28%">SUBJECT</th>
                    <th width="10%">CLASS SCORE</th>
                    <th width="10%">EXAM SCORE</th>
                    <th width="10%">TOTAL</th>
                    <th width="8%">GRADE</th>
                    <th width="10%">POSITION</th>
                    <th width="24%">REMARKS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subjects as $subject): 
                    $total = ($subject['ca_total'] ?? 0) + ($subject['exam_total'] ?? 0);
                    $remark = $this->getSubjectRemark($total);
                    $position = $this->getPositionOrdinal($subject['class_position'] ?? null);
                ?>
                <tr>
                    <td class="subject-name"><?php echo htmlspecialchars($subject['subject_name'] ?? ''); ?></td>
                    <td><?php echo number_format($subject['ca_total'] ?? 0, 0); ?></td>
                    <td><?php echo number_format($subject['exam_total'] ?? 0, 0); ?></td>
                    <td><?php echo number_format($total, 0); ?></td>
                    <td><?php echo htmlspecialchars($subject['grade'] ?? '-'); ?></td>
                    <td><?php echo $position; ?></td>
                    <td class="remark-cell"><?php echo $remark; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- PERFORMANCE SUMMARY -->
        <div class="performance-row">
            <div class="perf-item"><span class="label">TOTAL OBTAINED:</span><span class="value"><?php echo number_format($totalObtained, 0); ?></span></div>
            <div class="perf-item"><span class="label">AVERAGE:</span><span class="value"><?php echo number_format($average, 2); ?></span></div>
            <div class="perf-item"><span class="label">TOTAL GRADE:</span><span class="value"><?php echo htmlspecialchars($overallGrade); ?></span></div>
            <div class="perf-item"><span class="label">GRADE:</span><span class="value"><?php echo $this->getSubjectRemark($average); ?></span></div>
        </div>

        <!-- GRADE SCALE -->
        <div class="grade-scale">
            <span class="gs-item"><span class="range">80 - 100</span> (EXCELLENT)</span>
            <span class="gs-item"><span class="range">66 - 79</span> (VERY GOOD)</span>
            <span class="gs-item"><span class="range">46 - 65</span> (AVERAGE)</span>
            <span class="gs-item"><span class="range">36 - 45</span> (PASS)</span>
            <span class="gs-item"><span class="range">0 - 35</span> (LOW AVERAGE)</span>
        </div>

        <!-- SUBJECT COUNT -->
        <div class="subject-count">NO. OF SUBJECTS: <?php echo count($subjects); ?></div>

        <!-- CONDUCT, ATTITUDE, REMARKS -->
        <div class="remarks-section">
            <div class="remark-item"><span class="label">CONDUCT:</span> <span class="text"><?php echo htmlspecialchars($student['conduct'] ?? ''); ?></span></div>
            <div class="remark-item"><span class="label">ATTITUDE:</span> <span class="text"><?php echo htmlspecialchars($student['attitude'] ?? ''); ?></span></div>
            <div class="remark-item" style="grid-column: span 2;"><span class="label">TEACHER'S REMARK:</span> <span class="text"><?php echo htmlspecialchars($student['teacher_remark'] ?? ''); ?></span></div>
            <div class="remark-item" style="grid-column: span 2;"><span class="label">HEAD TEACHER'S REMARKS:</span> <span class="text"><?php echo htmlspecialchars($student['head_teacher_remark'] ?? ''); ?></span></div>
        </div>

        <!-- SIGNATURES -->
        <div class="signatures-section">
            <div class="sig-item">
                <div><strong>CLASS TEACHER'S NAME:</strong></div>
                <div><?php echo htmlspecialchars($student['teacher_name'] ?? '________________'); ?></div>
            </div>
            <div class="sig-item">
                <div><strong>HEAD TEACHER'S NAME:</strong></div>
                <div><?php echo htmlspecialchars($student['principal_name'] ?? '________________'); ?></div>
            </div>
            <div class="sig-item">
                <div><strong>HEAD TEACHER'S SIGNATURE:</strong></div>
                <div class="line"></div>
            </div>
        </div>

        <!-- TERM ANALYSIS -->
        <div class="term-row">
            <div class="term-item"><span class="label">YEAR:</span><span class="value"><?php echo htmlspecialchars($student['year_name'] ?? ''); ?></span></div>
            <div class="term-item"><span class="label">TERM:</span><span class="value"><?php echo htmlspecialchars($student['term_name'] ?? ''); ?></span></div>
            <div class="term-item"><span class="label">VACATION DATE:</span><span class="value"><?php echo htmlspecialchars($student['vacation_date'] ?? ''); ?></span></div>
            <div class="term-item"><span class="label">NEXT TERM BEGINS:</span><span class="value"><?php echo htmlspecialchars($student['next_term_start_date'] ?? ''); ?></span></div>
        </div>
    </div>

    <div style="text-align: center; margin-top: 10px;">
        <button class="print-btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
    </div>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    public function generatePDF($studentId, $academicYearId, $academicTermId)
    {
        $html = $this->generateHTML($studentId, $academicYearId, $academicTermId);
        
        $autoloadPath = __DIR__ . '/../vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
        }
        
        try {
            if (class_exists('Dompdf\Dompdf')) {
                $dompdf = new Dompdf\Dompdf();
                $dompdf->loadHtml($html);
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->render();
                return $dompdf->output();
            } else {
                throw new Exception('Dompdf class not found.');
            }
        } catch (Exception $e) {
            $this->logger->error('PDF generation failed: ' . $e->getMessage());
            return $this->convertHTMLToPrintablePDF($html);
        }
    }

    private function convertHTMLToPrintablePDF($html)
    {
        $printableHtml = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Report Card</title>
    <style>
        @media print { body { margin: 0; padding: 20px; } .no-print { display: none; } }
        body { font-family: Arial, sans-serif; padding: 20px; }
        .print-button { background: #2563eb; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; margin: 20px 0; }
        .print-button:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="no-print">
        <h2>📄 Report Card</h2>
        <button class="print-button" onclick="window.print()">🖨️ Print / Save as PDF</button>
        <p><em>Click the button above to print or save as PDF.</em></p>
        <hr>
    </div>
    ' . $html . '
    <div class="no-print" style="margin-top: 20px;">
        <hr>
        <button class="print-button" onclick="window.print()">🖨️ Print / Save as PDF</button>
    </div>
</body>
</html>';
        return $printableHtml;
    }

    public function savePDF($studentId, $academicYearId, $academicTermId, $filename = null)
    {
        $pdfContent = $this->generatePDF($studentId, $academicYearId, $academicTermId);
        
        if (!$filename) {
            $student = $this->db->fetchOne(
                "SELECT first_name, last_name FROM students WHERE id = ?",
                [$studentId]
            );
            $filename = 'Report_Card_' . $student['first_name'] . '_' . $student['last_name'] . '.pdf';
        }
        
        $reportDir = __DIR__ . '/../../public/reports';
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0777, true);
        }
        
        $filepath = $reportDir . '/' . $filename;
        file_put_contents($filepath, $pdfContent);
        
        return $filepath;
    }
    
}
