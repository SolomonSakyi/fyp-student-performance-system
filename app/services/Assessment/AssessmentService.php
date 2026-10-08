<?php
/**
 * AssessmentService.php
 *
 * Enterprise Assessment Service Layer
 * Handles all assessment business logic
 *
 * @package EduTrack
 * @subpackage Services\Assessment
 * @version 3.0
 */

require_once __DIR__ . '/../../models/Assessment/AssessmentModel.php';
require_once __DIR__ . '/../../helpers/DatabaseHelper.php';
require_once __DIR__ . '/../../helpers/LoggerHelper.php';

class AssessmentService
{
    /**
     * @var AssessmentModel Assessment model instance
     */
    private $assessmentModel;

    /**
     * @var DatabaseHelper Database instance
     */
    private $db;

    /**
     * @var LoggerHelper Logger instance
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->assessmentModel = new AssessmentModel();
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
    }

    // ==============================================================
    // ASSESSMENT TYPES
    // ==============================================================

    public function getAssessmentTypes(int $schoolId = 1): array
    {
        try {
            $types = $this->assessmentModel->getAssessmentTypes($schoolId);
            return ['success' => true, 'data' => $types];
        } catch (Exception $e) {
            $this->logger->error('Failed to get assessment types: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function getAssessmentTypeById(int $id): array
    {
        try {
            $type = $this->assessmentModel->getAssessmentTypeById($id);
            if (!$type) {
                return ['success' => false, 'message' => 'Assessment type not found'];
            }
            return ['success' => true, 'data' => $type];
        } catch (Exception $e) {
            $this->logger->error('Failed to get assessment type: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function createAssessmentType(array $data): array
    {
        try {
            // Auto-generate code if not provided
            if (empty($data['assessment_code'])) {
                $categoryMap = [
                    'continuous_assessment' => 'CA',
                    'examination' => 'EXAM',
                    'practical' => 'PRAC',
                    'project' => 'PROJ',
                    'oral' => 'ORAL',
                    'portfolio' => 'PORT'
                ];
                $data['assessment_code'] = $categoryMap[$data['assessment_category'] ?? 'continuous_assessment'] ?? 'ASM';
            }

            $id = $this->assessmentModel->createAssessmentType($data);
            if (!$id) {
                return ['success' => false, 'message' => 'Failed to create assessment type'];
            }

            return ['success' => true, 'message' => 'Assessment type created successfully', 'id' => $id];
        } catch (Exception $e) {
            $this->logger->error('Failed to create assessment type: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateAssessmentType(int $id, array $data): array
    {
        try {
            $result = $this->assessmentModel->updateAssessmentType($id, $data);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to update assessment type'];
            }
            return ['success' => true, 'message' => 'Assessment type updated successfully'];
        } catch (Exception $e) {
            $this->logger->error('Failed to update assessment type: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteAssessmentType(int $id): array
    {
        try {
            $result = $this->assessmentModel->deleteAssessmentType($id);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to delete assessment type'];
            }
            return ['success' => true, 'message' => 'Assessment type deleted successfully'];
        } catch (Exception $e) {
            $this->logger->error('Failed to delete assessment type: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ==============================================================
    // ASSESSMENT COMPONENTS
    // ==============================================================

    public function getAssessmentComponents(int $assessmentTypeId): array
    {
        try {
            $components = $this->assessmentModel->getAssessmentComponents($assessmentTypeId);
            return ['success' => true, 'data' => $components];
        } catch (Exception $e) {
            $this->logger->error('Failed to get assessment components: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    // ==============================================================
    // SUBJECT ASSESSMENTS
    // ==============================================================

    public function getSubjectAssessments(int $classSectionId, int $termId): array
    {
        try {
            $assessments = $this->assessmentModel->getSubjectAssessments($classSectionId, $termId);
            return ['success' => true, 'data' => $assessments];
        } catch (Exception $e) {
            $this->logger->error('Failed to get subject assessments: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    // ==============================================================
    // ASSESSMENT MARKS
    // ==============================================================

    public function saveMarks(array $marksData): array
    {
        try {
            $successCount = 0;
            $failedCount = 0;
            $errors = [];

            foreach ($marksData as $subjectAssessmentId => $studentMarks) {
                foreach ($studentMarks as $studentId => $score) {
                    if ($score === '' || $score === null) {
                        continue;
                    }

                    $data = [
                        'subject_assessment_id' => $subjectAssessmentId,
                        'student_id' => $studentId,
                        'score' => (float)$score,
                        'entered_by' => $_SESSION['user_id'] ?? null
                    ];

                    // Get max score for this subject assessment
                    $sa = $this->assessmentModel->getSubjectAssessmentById($subjectAssessmentId);
                    if ($sa && isset($sa['max_score'])) {
                        $data['max_score'] = $sa['max_score'];
                        $data['percentage'] = $sa['max_score'] > 0 ? round(($data['score'] / $sa['max_score']) * 100, 2) : 0;
                    }

                    // Get grade from percentage
                    if (isset($data['percentage'])) {
                        $grade = $this->assessmentModel->getGradeFromPercentage($data['percentage']);
                        if ($grade) {
                            $data['grade_letter'] = $grade['grade_letter'];
                            $data['grade_point'] = $grade['grade_point'];
                        }
                    }

                    $result = $this->assessmentModel->saveAssessmentMarks($data);
                    if ($result) {
                        $successCount++;
                    } else {
                        $failedCount++;
                        $errors[] = "Failed to save marks for student {$studentId} in subject assessment {$subjectAssessmentId}";
                    }
                }
            }

            return [
                'success' => true,
                'message' => "Saved {$successCount} marks, {$failedCount} failed",
                'data' => ['saved' => $successCount, 'failed' => $failedCount, 'errors' => $errors]
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to save marks: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getClassMarks(int $classSectionId, int $termId): array
    {
        try {
            $marks = $this->assessmentModel->getClassMarks($classSectionId, $termId);
            return ['success' => true, 'data' => $marks];
        } catch (Exception $e) {
            $this->logger->error('Failed to get class marks: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    // ==============================================================
    // ASSESSMENT RESULTS
    // ==============================================================

    public function calculateResults(int $studentId, int $termId): array
    {
        try {
            $result = $this->assessmentModel->calculateAssessmentResults($studentId, $termId);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to calculate results'];
            }
            return ['success' => true, 'message' => 'Results calculated successfully'];
        } catch (Exception $e) {
            $this->logger->error('Failed to calculate results: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function calculateClassResults(int $classSectionId, int $termId): array
    {
        try {
            // Get all students in the class
            $students = $this->db->fetchAll(
                "SELECT id FROM students s 
                 JOIN student_enrollments se ON s.id = se.student_id 
                 WHERE se.class_section_id = ? AND se.academic_term_id = ? 
                 AND s.is_active = 1",
                [$classSectionId, $termId]
            );

            if (empty($students)) {
                return ['success' => false, 'message' => 'No students found in this class'];
            }

            $successCount = 0;
            $failedCount = 0;

            foreach ($students as $student) {
                $result = $this->assessmentModel->calculateAssessmentResults($student['id'], $termId);
                if ($result) {
                    $successCount++;
                } else {
                    $failedCount++;
                }
            }

            return [
                'success' => true,
                'message' => "Calculated results for {$successCount} students, {$failedCount} failed",
                'data' => ['processed' => $successCount, 'failed' => $failedCount]
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to calculate class results: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getStudentResults(int $studentId, int $termId): array
    {
        try {
            $results = $this->assessmentModel->getStudentResults($studentId, $termId);
            return ['success' => true, 'data' => $results];
        } catch (Exception $e) {
            $this->logger->error('Failed to get student results: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function publishResults(int $studentId, int $termId): array
    {
        try {
            $result = $this->assessmentModel->publishResults($studentId, $termId);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to publish results'];
            }
            return ['success' => true, 'message' => 'Results published successfully'];
        } catch (Exception $e) {
            $this->logger->error('Failed to publish results: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function publishClassResults(int $classSectionId, int $termId): array
    {
        try {
            $students = $this->db->fetchAll(
                "SELECT id FROM students s 
                 JOIN student_enrollments se ON s.id = se.student_id 
                 WHERE se.class_section_id = ? AND se.academic_term_id = ? 
                 AND s.is_active = 1",
                [$classSectionId, $termId]
            );

            if (empty($students)) {
                return ['success' => false, 'message' => 'No students found in this class'];
            }

            $successCount = 0;

            foreach ($students as $student) {
                $result = $this->assessmentModel->publishResults($student['id'], $termId);
                if ($result) {
                    $successCount++;
                }
            }

            return [
                'success' => true,
                'message' => "Published results for {$successCount} students",
                'data' => ['published' => $successCount]
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to publish class results: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ==============================================================
    // STUDENT ACADEMIC HISTORY
    // ==============================================================

    public function getStudentAcademicHistory(int $studentId): array
    {
        try {
            $history = $this->db->fetchAll(
                "SELECT ar.*, s.subject_name, s.subject_code,
                        gl.level_name, cs.section_name,
                        ay.year_name, at.term_name,
                        ar.academic_term_id, ar.total_score, ar.total_grade
                 FROM assessment_results ar
                 JOIN subjects s ON ar.subject_id = s.id
                 JOIN class_sections cs ON ar.class_section_id = cs.id
                 JOIN grade_levels gl ON cs.grade_level_id = gl.id
                 JOIN academic_years ay ON ar.academic_term_id = ay.id
                 JOIN academic_terms at ON ar.academic_term_id = at.id
                 WHERE ar.student_id = ? AND ar.is_active = 1
                 ORDER BY ay.year_name DESC, at.term_number DESC, s.subject_name",
                [$studentId]
            );

            return ['success' => true, 'data' => $history];
        } catch (Exception $e) {
            $this->logger->error('Failed to get student academic history: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }
}