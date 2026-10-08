<?php
/**
 * GradingService.php
 * 
 * Enterprise Grading Service Layer
 * Handles all grading business logic
 * 
 * @package EduTrack
 * @subpackage Services\Grading
 * @version 2.0
 */

require_once __DIR__ . '/../../models/Grading/GradingModel.php';
require_once __DIR__ . '/../../helpers/DatabaseHelper.php';
require_once __DIR__ . '/../../helpers/LoggerHelper.php';

class GradingService
{
    private $gradingModel;
    private $db;
    private $logger;

    public function __construct()
    {
        $this->gradingModel = new GradingModel();
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
    }

    // ================================================================
    // GRADING SYSTEMS
    // ================================================================

    public function getGradingSystems(int $schoolId = 1, bool $onlyActive = true): array
    {
        try {
            $systems = $this->gradingModel->getGradingSystems($schoolId, $onlyActive);
            return ['success' => true, 'data' => $systems, 'total' => count($systems)];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function getGradingSystemById(int $id): array
    {
        try {
            $system = $this->gradingModel->getGradingSystemById($id);
            return $system ? ['success' => true, 'data' => $system] : ['success' => false, 'message' => 'System not found'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function createGradingSystem(array $data): array
    {
        try {
            $required = ['system_name', 'system_code'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => "Missing required field: $field"];
                }
            }
            $id = $this->gradingModel->createGradingSystem($data);
            return ['success' => true, 'message' => 'Grading system created successfully', 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateGradingSystem(int $id, array $data): array
    {
        try {
            $result = $this->gradingModel->updateGradingSystem($id, $data);
            return $result ? ['success' => true, 'message' => 'System updated successfully'] : ['success' => false, 'message' => 'Update failed'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteGradingSystem(int $id): array
    {
        try {
            $result = $this->gradingModel->deleteGradingSystem($id);
            return $result ? ['success' => true, 'message' => 'System deleted successfully'] : ['success' => false, 'message' => 'Delete failed'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function duplicateGradingSystem(int $id): array
    {
        try {
            $newId = $this->gradingModel->duplicateGradingSystem($id);
            return ['success' => true, 'message' => 'System duplicated successfully', 'id' => $newId];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // GRADING SCALES
    // ================================================================

    public function getGradingScales(int $gradingSystemId): array
    {
        try {
            $scales = $this->gradingModel->getGradingScales($gradingSystemId);
            return ['success' => true, 'data' => $scales];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function createGradingScale(array $data): array
    {
        try {
            $required = ['grading_system_id', 'grade_name', 'grade_code', 'min_score', 'max_score'];
            foreach ($required as $field) {
                if (!isset($data[$field])) {
                    return ['success' => false, 'message' => "Missing required field: $field"];
                }
            }
            if ($data['min_score'] >= $data['max_score']) {
                return ['success' => false, 'message' => 'Min score must be less than max score'];
            }
            $id = $this->gradingModel->createGradingScale($data);
            return ['success' => true, 'message' => 'Grading scale created successfully', 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateGradingScale(int $id, array $data): array
    {
        try {
            $result = $this->gradingModel->updateGradingScale($id, $data);
            return $result ? ['success' => true, 'message' => 'Scale updated successfully'] : ['success' => false, 'message' => 'Update failed'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteGradingScale(int $id): array
    {
        try {
            $result = $this->gradingModel->deleteGradingScale($id);
            return $result ? ['success' => true, 'message' => 'Scale deleted successfully'] : ['success' => false, 'message' => 'Delete failed'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getGradeFromPercentage(float $percentage, int $gradingSystemId): array
    {
        try {
            $grade = $this->gradingModel->getGradeByScore($percentage, $gradingSystemId);
            return $grade ? ['success' => true, 'data' => $grade] : ['success' => false, 'message' => 'No grade found'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // GRADE REMARKS
    // ================================================================

    public function getGradeRemarks(int $gradingSystemId, string $gradeCode = null): array
    {
        try {
            $remarks = $this->gradingModel->getGradeRemarks($gradingSystemId, $gradeCode);
            return ['success' => true, 'data' => $remarks];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function createGradeRemark(array $data): array
    {
        try {
            $required = ['grading_system_id', 'grade_code', 'remark_text'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => "Missing required field: $field"];
                }
            }
            $id = $this->gradingModel->createGradeRemark($data);
            return ['success' => true, 'message' => 'Remark created successfully', 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // GRADE ASSIGNMENTS
    // ================================================================

    public function getGradeAssignments(int $schoolId = 1): array
    {
        try {
            $assignments = $this->gradingModel->getGradeAssignments($schoolId);
            return ['success' => true, 'data' => $assignments];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function createGradeAssignment(array $data): array
    {
        try {
            $required = ['grading_system_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => "Missing required field: $field"];
                }
            }
            $id = $this->gradingModel->createGradeAssignment($data);
            return ['success' => true, 'message' => 'Assignment created successfully', 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteGradeAssignment(int $id): array
    {
        try {
            $result = $this->gradingModel->deleteGradeAssignment($id);
            return $result ? ['success' => true, 'message' => 'Assignment deleted'] : ['success' => false, 'message' => 'Delete failed'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getGradingSystemForClass(int $classSectionId, int $termId): array
    {
        try {
            $system = $this->gradingModel->getGradingSystemForClass($classSectionId, $termId);
            return $system ? ['success' => true, 'data' => $system] : ['success' => false, 'message' => 'No system assigned'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // SUBJECT CATEGORIES
    // ================================================================

    public function getSubjectCategories(int $schoolId = 1): array
    {
        try {
            $categories = $this->gradingModel->getSubjectCategories($schoolId);
            return ['success' => true, 'data' => $categories];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function createSubjectCategory(array $data): array
    {
        try {
            $required = ['category_name', 'category_code'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => "Missing required field: $field"];
                }
            }
            $id = $this->gradingModel->createSubjectCategory($data);
            return ['success' => true, 'message' => 'Category created successfully', 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateSubjectCategory(int $id, array $data): array
    {
        try {
            $result = $this->gradingModel->updateSubjectCategory($id, $data);
            return $result ? ['success' => true, 'message' => 'Category updated'] : ['success' => false, 'message' => 'Update failed'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteSubjectCategory(int $id): array
    {
        try {
            $result = $this->gradingModel->deleteSubjectCategory($id);
            return $result ? ['success' => true, 'message' => 'Category deleted'] : ['success' => false, 'message' => 'Delete failed'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function assignSubjectToCategory(array $data): array
    {
        try {
            $required = ['subject_id', 'category_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => "Missing required field: $field"];
                }
            }
            $id = $this->gradingModel->assignSubjectToCategory($data);
            return ['success' => true, 'message' => 'Subject assigned to category', 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function removeSubjectFromCategory(int $id): array
    {
        try {
            $result = $this->gradingModel->removeSubjectFromCategory($id);
            return $result ? ['success' => true, 'message' => 'Subject removed from category'] : ['success' => false, 'message' => 'Remove failed'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // AGGREGATE RULES
    // ================================================================

    public function getAggregateRules(int $schoolId = 1): array
    {
        try {
            $rules = $this->gradingModel->getAggregateRules($schoolId);
            return ['success' => true, 'data' => $rules];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function createAggregateRule(array $data): array
    {
        try {
            $required = ['rule_name', 'rule_code'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => "Missing required field: $field"];
                }
            }
            $id = $this->gradingModel->createAggregateRule($data);
            return ['success' => true, 'message' => 'Aggregate rule created', 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateAggregateRule(int $id, array $data): array
    {
        try {
            $result = $this->gradingModel->updateAggregateRule($id, $data);
            return $result ? ['success' => true, 'message' => 'Aggregate rule updated'] : ['success' => false, 'message' => 'Update failed'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteAggregateRule(int $id): array
    {
        try {
            $result = $this->gradingModel->deleteAggregateRule($id);
            return $result ? ['success' => true, 'message' => 'Aggregate rule deleted'] : ['success' => false, 'message' => 'Delete failed'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getAggregateRuleForClass(int $classSectionId, int $termId): array
    {
        try {
            $rule = $this->gradingModel->getAggregateRuleForClass($classSectionId, $termId);
            return $rule ? ['success' => true, 'data' => $rule] : ['success' => false, 'message' => 'No rule found'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // PROMOTION RULES
    // ================================================================

    public function getPromotionRules(int $schoolId = 1): array
    {
        try {
            $rules = $this->gradingModel->getPromotionRules($schoolId);
            return ['success' => true, 'data' => $rules];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function createPromotionRule(array $data): array
    {
        try {
            $required = ['rule_name', 'rule_code'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => "Missing required field: $field"];
                }
            }
            $id = $this->gradingModel->createPromotionRule($data);
            return ['success' => true, 'message' => 'Promotion rule created', 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updatePromotionRule(int $id, array $data): array
    {
        try {
            $result = $this->gradingModel->updatePromotionRule($id, $data);
            return $result ? ['success' => true, 'message' => 'Promotion rule updated'] : ['success' => false, 'message' => 'Update failed'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deletePromotionRule(int $id): array
    {
        try {
            $result = $this->gradingModel->deletePromotionRule($id);
            return $result ? ['success' => true, 'message' => 'Promotion rule deleted'] : ['success' => false, 'message' => 'Delete failed'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // GRADE CALCULATION
    // ================================================================

    public function calculateStudentGrades(int $studentId, int $termId): array
    {
        try {
            $result = $this->gradingModel->calculateStudentGrades($studentId, $termId);
            return $result ? ['success' => true, 'message' => 'Grades calculated'] : ['success' => false, 'message' => 'Calculation failed'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function calculateClassGrades(int $classSectionId, int $termId): array
    {
        try {
            $result = $this->gradingModel->calculateClassGrades($classSectionId, $termId);
            return ['success' => true, 'message' => "Calculated grades for {$result['calculated']} students", 'data' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getStudentGrades(int $studentId, int $termId): array
    {
        try {
            $grades = $this->gradingModel->getStudentGrades($studentId, $termId);
            return ['success' => true, 'data' => $grades];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // SAVE STUDENT GRADE - ADDED
    // ================================================================

    public function saveStudentGrade(array $data): array
    {
        try {
            $required = ['student_id', 'subject_id', 'academic_term_id', 'class_section_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => "Missing required field: $field"];
                }
            }

            $id = $this->gradingModel->saveStudentGrade($data);
            return ['success' => true, 'message' => 'Student grade saved successfully', 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // AGGREGATE CALCULATION
    // ================================================================

    public function calculateStudentAggregate(int $studentId, int $termId): array
    {
        try {
            $result = $this->gradingModel->calculateStudentAggregate($studentId, $termId);
            return $result ? ['success' => true, 'message' => 'Aggregate calculated'] : ['success' => false, 'message' => 'Calculation failed'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function calculateClassAggregates(int $classSectionId, int $termId): array
    {
        try {
            $result = $this->gradingModel->calculateClassAggregates($classSectionId, $termId);
            return ['success' => true, 'message' => "Calculated aggregates for {$result['calculated']} students", 'data' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getStudentAggregate(int $studentId, int $termId): array
    {
        try {
            $aggregate = $this->gradingModel->getStudentAggregate($studentId, $termId);
            return ['success' => true, 'data' => $aggregate];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // PROMOTION EVALUATION
    // ================================================================

    public function evaluatePromotion(int $studentId, int $yearId): array
    {
        try {
            $result = $this->gradingModel->evaluateStudentPromotion($studentId, $yearId);
            return $result ? ['success' => true, 'message' => 'Promotion evaluated'] : ['success' => false, 'message' => 'Evaluation failed'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function evaluateClassPromotions(int $classSectionId, int $yearId): array
    {
        try {
            $students = $this->db->fetchAll("SELECT id FROM students s JOIN student_enrollments se ON s.id = se.student_id WHERE se.class_section_id = ? AND se.academic_year_id = ? AND se.is_active = 1", [$classSectionId, $yearId]);
            $evaluated = 0;
            $errors = [];
            foreach ($students as $student) {
                try {
                    if ($this->gradingModel->evaluateStudentPromotion($student['id'], $yearId)) $evaluated++;
                } catch (Exception $e) {
                    $errors[] = "Student {$student['id']}: " . $e->getMessage();
                }
            }
            return ['success' => true, 'message' => "Evaluated {$evaluated} students", 'data' => ['evaluated' => $evaluated, 'total' => count($students), 'errors' => $errors]];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getPromotionDecisions(int $yearId, string $status = null): array
    {
        try {
            $sql = "SELECT pd.*, s.first_name, s.last_name, s.admission_number, fgl.level_name AS from_level, tgl.level_name AS to_level FROM promotion_decisions pd JOIN students s ON pd.student_id = s.id LEFT JOIN grade_levels fgl ON pd.from_grade_level_id = fgl.id LEFT JOIN grade_levels tgl ON pd.to_grade_level_id = tgl.id WHERE pd.academic_year_id = ? AND pd.deleted_at IS NULL";
            $params = [$yearId];
            if ($status) { $sql .= " AND pd.decision_type = ?"; $params[] = $status; }
            $decisions = $this->db->fetchAll($sql, $params);
            return ['success' => true, 'data' => $decisions];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function approvePromotion(int $id, array $data): array
    {
        try {
            $sql = "UPDATE promotion_decisions SET headteacher_approval = ?, headteacher_remarks = ?, approval_date = NOW(), updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, [$data['headteacher_approval'] ?? 1, $data['headteacher_remarks'] ?? null, $id]);
            return ['success' => true, 'message' => 'Promotion approved'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // REPORT CARD
    // ================================================================

    public function getReportCardData(int $studentId, int $termId): array
    {
        try {
            $data = $this->gradingModel->getReportCardData($studentId, $termId);
            return ['success' => true, 'data' => $data];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // GENERATE REPORT CARD - ADDED
    // ================================================================

    public function generateReportCard(int $studentId, int $termId): array
    {
        try {
            $reportData = $this->getReportCardData($studentId, $termId);
            if (!$reportData['success']) {
                return $reportData;
            }

            $data = $reportData['data'];
            $html = $this->generateReportCardHTML($data);

            return [
                'success' => true,
                'message' => 'Report card generated successfully',
                'data' => [
                    'html' => $html,
                    'student' => $data['student'] ?? null,
                    'grades' => $data['grades'] ?? []
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Generate report card HTML
     */
    private function generateReportCardHTML(array $data): string
    {
        $student = $data['student'] ?? [];
        $grades = $data['grades'] ?? [];
        $aggregate = $data['aggregate'] ?? [];
        $promotion = $data['promotion'] ?? [];

        $html = '<!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                .header { text-align: center; margin-bottom: 30px; }
                .header h1 { color: #1a3c6e; }
                .student-info { margin-bottom: 20px; border: 1px solid #ddd; padding: 15px; }
                .student-info table { width: 100%; }
                .student-info td { padding: 5px; }
                table { width: 100%; border-collapse: collapse; }
                th { background: #1a3c6e; color: #fff; padding: 8px; text-align: left; }
                td { padding: 8px; border-bottom: 1px solid #ddd; }
                .grade-pass { color: #28a745; font-weight: bold; }
                .grade-fail { color: #dc3545; font-weight: bold; }
                .aggregate { margin-top: 20px; border: 1px solid #ddd; padding: 15px; }
                .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #666; }
                .signatures { margin-top: 40px; display: flex; justify-content: space-around; }
                .signature { text-align: center; }
                .signature .line { border-top: 1px solid #000; width: 200px; margin-top: 30px; }
            </style>
        </head>
        <body>';

        $html .= '<div class="header">
            <h1>REPORT CARD</h1>
            <p>' . ($student['school_name'] ?? 'EduTrack School') . '</p>
            <p>Academic Year: ' . ($student['year_name'] ?? '') . ' | Term: ' . ($student['term_name'] ?? '') . '</p>
        </div>';

        $html .= '<div class="student-info">
            <table>
                <tr>
                    <td><strong>Name:</strong> ' . ($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '') . '</td>
                    <td><strong>Admission:</strong> ' . ($student['admission_number'] ?? '') . '</td>
                </tr>
                <tr>
                    <td><strong>Class:</strong> ' . ($student['level_name'] ?? '') . ' - ' . ($student['section_name'] ?? '') . '</td>
                    <td><strong>Term:</strong> ' . ($student['term_name'] ?? '') . '</td>
                </tr>
            </table>
        </div>';

        $html .= '<h3>Subject Performance</h3>
        <table>
            <thead>
                <tr>
                    <th>Subject</th>
                    <th>Score</th>
                    <th>Grade</th>
                    <th>Grade Point</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($grades as $grade) {
            $status = $grade['is_passing'] ? 'Pass' : 'Fail';
            $statusClass = $grade['is_passing'] ? 'grade-pass' : 'grade-fail';
            $html .= '<tr>
                <td>' . ($grade['subject_name'] ?? '') . '</td>
                <td>' . ($grade['raw_score'] ?? '-') . '</td>
                <td><strong>' . ($grade['grade_code'] ?? '-') . '</strong></td>
                <td>' . ($grade['grade_point'] ?? '-') . '</td>
                <td class="' . $statusClass . '">' . $status . '</td>
            </tr>';
        }

        $html .= '</tbody></table>';

        if ($aggregate) {
            $html .= '<div class="aggregate">
                <h3>Performance Summary</h3>
                <table>
                    <tr><td><strong>Average Score:</strong></td><td>' . ($aggregate['average_score'] ?? 0) . '%</td></tr>
                    <tr><td><strong>Average Grade Point:</strong></td><td>' . ($aggregate['average_grade_point'] ?? 0) . '</td></tr>
                    <tr><td><strong>Subjects Passed:</strong></td><td>' . ($aggregate['subjects_passed'] ?? 0) . '/' . ($aggregate['total_subjects_count'] ?? 0) . '</td></tr>
                    <tr><td><strong>Class Position:</strong></td><td>' . ($aggregate['class_position'] ?? 'N/A') . '</td></tr>
                </table>
            </div>';
        }

        if ($promotion) {
            $html .= '<div class="aggregate">
                <h3>Promotion Status</h3>
                <table>
                    <tr><td><strong>Decision:</strong></td><td>' . ucfirst($promotion['decision_type'] ?? '') . '</td></tr>
                    <tr><td><strong>Teacher Recommendation:</strong></td><td>' . ($promotion['teacher_recommendation'] ?? 'N/A') . '</td></tr>
                    <tr><td><strong>Headteacher Approval:</strong></td><td>' . ($promotion['headteacher_approval'] ? '✅ Approved' : '⏳ Pending') . '</td></tr>
                </table>
            </div>';
        }

        $html .= '<div class="signatures">
            <div class="signature">
                <div class="line"></div>
                <p>Class Teacher</p>
            </div>
            <div class="signature">
                <div class="line"></div>
                <p>Headteacher</p>
            </div>
            <div class="signature">
                <div class="line"></div>
                <p>Parent/Guardian</p>
            </div>
        </div>';

        $html .= '<div class="footer">
            <p>Generated on: ' . date('F j, Y') . '</p>
            <p>This report card is generated automatically by EduTrack System</p>
        </div>';

        $html .= '</body></html>';

        return $html;
    }
}