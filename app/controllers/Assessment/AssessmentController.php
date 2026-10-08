<?php
/**
 * AssessmentController.php
 * 
 * Enterprise Assessment Controller
 * Handles HTTP requests for assessment operations
 * 
 * @package EduTrack
 * @subpackage Controllers\Assessment
 */

require_once __DIR__ . '/../../services/Assessment/AssessmentService.php';
require_once __DIR__ . '/../../services/Assessment/IntelligenceService.php';
require_once __DIR__ . '/../../helpers/AdminHelper.php';

class AssessmentController
{
    /**
     * @var AssessmentService Assessment service instance
     */
    private $assessmentService;

    /**
     * @var IntelligenceService Intelligence service instance
     */
    private $intelligenceService;

    /**
     * @var DatabaseHelper Database instance
     */
    private $db;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->assessmentService = new AssessmentService();
        $this->intelligenceService = new IntelligenceService();
        $this->db = DatabaseHelper::getInstance();
    }

    // ================================================================
    // ASSESSMENT TYPES
    // ================================================================

    /**
     * List assessment types
     */
    public function listAssessmentTypes(): void
    {
        $result = $this->assessmentService->getAssessmentTypes();
        $this->renderJson($result);
    }

    /**
     * Create assessment type
     */
    public function createAssessmentType(): void
    {
        $data = $this->getRequestData();
        $result = $this->assessmentService->createAssessmentType($data);
        $this->renderJson($result);
    }

    // ================================================================
    // GRADING
    // ================================================================

    /**
     * List grading scales
     */
    public function listGradingScales(): void
    {
        $result = $this->assessmentService->getGradingScales();
        $this->renderJson($result);
    }

    /**
     * Create grading scale
     */
    public function createGradingScale(): void
    {
        $data = $this->getRequestData();
        $result = $this->assessmentService->createGradingScale($data);
        $this->renderJson($result);
    }

    // ================================================================
    // MARKS
    // ================================================================

    /**
     * Save marks
     */
    public function saveMarks(): void
    {
        $data = $this->getRequestData();
        $result = $this->assessmentService->saveMarks($data);
        $this->renderJson($result);
    }

    /**
     * Bulk save marks
     */
    public function bulkSaveMarks(): void
    {
        $data = $this->getRequestData();
        $marks = $data['marks'] ?? [];
        $result = $this->assessmentService->bulkSaveMarks($marks);
        $this->renderJson($result);
    }

    /**
     * Get student marks
     */
    public function getStudentMarks(): void
    {
        $studentId = $_GET['student_id'] ?? null;
        $termId = $_GET['term_id'] ?? null;

        if (!$studentId || !$termId) {
            $this->renderJson(['success' => false, 'message' => 'Student ID and Term ID required']);
            return;
        }

        $result = $this->assessmentService->getStudentMarks((int)$studentId, (int)$termId);
        $this->renderJson($result);
    }

    /**
     * Get class marks
     */
    public function getClassMarks(): void
    {
        $classId = $_GET['class_id'] ?? null;
        $termId = $_GET['term_id'] ?? null;

        if (!$classId || !$termId) {
            $this->renderJson(['success' => false, 'message' => 'Class ID and Term ID required']);
            return;
        }

        $result = $this->assessmentService->getClassMarks((int)$classId, (int)$termId);
        $this->renderJson($result);
    }

    // ================================================================
    // RESULTS
    // ================================================================

    /**
     * Calculate results
     */
    public function calculateResults(): void
    {
        $data = $this->getRequestData();
        $studentId = $data['student_id'] ?? null;
        $termId = $data['term_id'] ?? null;

        if (!$studentId || !$termId) {
            $this->renderJson(['success' => false, 'message' => 'Student ID and Term ID required']);
            return;
        }

        $result = $this->assessmentService->calculateResults((int)$studentId, (int)$termId);
        $this->renderJson($result);
    }

    /**
     * Get student results
     */
    public function getStudentResults(): void
    {
        $studentId = $_GET['student_id'] ?? null;
        $termId = $_GET['term_id'] ?? null;

        if (!$studentId || !$termId) {
            $this->renderJson(['success' => false, 'message' => 'Student ID and Term ID required']);
            return;
        }

        $result = $this->assessmentService->getStudentResults((int)$studentId, (int)$termId);
        $this->renderJson($result);
    }

    /**
     * Publish results
     */
    public function publishResults(): void
    {
        $data = $this->getRequestData();
        $studentId = $data['student_id'] ?? null;
        $termId = $data['term_id'] ?? null;

        if (!$studentId || !$termId) {
            $this->renderJson(['success' => false, 'message' => 'Student ID and Term ID required']);
            return;
        }

        $result = $this->assessmentService->publishResults((int)$studentId, (int)$termId);
        $this->renderJson($result);
    }

    // ================================================================
    // GPA
    // ================================================================

    /**
     * Calculate GPA
     */
    public function calculateGPA(): void
    {
        $data = $this->getRequestData();
        $studentId = $data['student_id'] ?? null;
        $termId = $data['term_id'] ?? null;

        if (!$studentId || !$termId) {
            $this->renderJson(['success' => false, 'message' => 'Student ID and Term ID required']);
            return;
        }

        $result = $this->assessmentService->calculateGPA((int)$studentId, (int)$termId);
        $this->renderJson($result);
    }

    /**
     * Get student GPA
     */
    public function getStudentGPA(): void
    {
        $studentId = $_GET['student_id'] ?? null;
        $termId = $_GET['term_id'] ?? null;

        if (!$studentId || !$termId) {
            $this->renderJson(['success' => false, 'message' => 'Student ID and Term ID required']);
            return;
        }

        $result = $this->assessmentService->getStudentGPA((int)$studentId, (int)$termId);
        $this->renderJson($result);
    }

    /**
     * Get cumulative GPA
     */
    public function getCumulativeGPA(): void
    {
        $studentId = $_GET['student_id'] ?? null;

        if (!$studentId) {
            $this->renderJson(['success' => false, 'message' => 'Student ID required']);
            return;
        }

        $result = $this->assessmentService->getCumulativeGPA((int)$studentId);
        $this->renderJson($result);
    }

    // ================================================================
    // INTELLIGENCE
    // ================================================================

    /**
     * Generate personality summary
     */
    public function generatePersonalitySummary(): void
    {
        $data = $this->getRequestData();
        $studentId = $data['student_id'] ?? null;
        $termId = $data['term_id'] ?? null;

        if (!$studentId || !$termId) {
            $this->renderJson(['success' => false, 'message' => 'Student ID and Term ID required']);
            return;
        }

        $result = $this->intelligenceService->generatePersonalitySummary((int)$studentId, (int)$termId);
        $this->renderJson($result);
    }

    /**
     * Get personality summary
     */
    public function getPersonalitySummary(): void
    {
        $studentId = $_GET['student_id'] ?? null;
        $termId = $_GET['term_id'] ?? null;

        if (!$studentId || !$termId) {
            $this->renderJson(['success' => false, 'message' => 'Student ID and Term ID required']);
            return;
        }

        $result = $this->intelligenceService->getPersonalitySummary((int)$studentId, (int)$termId);
        $this->renderJson($result);
    }

    /**
     * Detect risk
     */
    public function detectRisk(): void
    {
        $data = $this->getRequestData();
        $studentId = $data['student_id'] ?? null;
        $termId = $data['term_id'] ?? null;

        if (!$studentId || !$termId) {
            $this->renderJson(['success' => false, 'message' => 'Student ID and Term ID required']);
            return;
        }

        $result = $this->intelligenceService->detectRisk((int)$studentId, (int)$termId);
        $this->renderJson($result);
    }

    /**
     * Get at-risk students
     */
    public function getAtRiskStudents(): void
    {
        $termId = $_GET['term_id'] ?? null;
        $riskLevel = $_GET['risk_level'] ?? null;

        if (!$termId) {
            $this->renderJson(['success' => false, 'message' => 'Term ID required']);
            return;
        }

        $result = $this->intelligenceService->getAtRiskStudents((int)$termId, $riskLevel);
        $this->renderJson($result);
    }

    /**
     * Evaluate promotion
     */
    public function evaluatePromotion(): void
    {
        $data = $this->getRequestData();
        $studentId = $data['student_id'] ?? null;
        $yearId = $data['year_id'] ?? null;

        if (!$studentId || !$yearId) {
            $this->renderJson(['success' => false, 'message' => 'Student ID and Year ID required']);
            return;
        }

        $result = $this->intelligenceService->evaluatePromotion((int)$studentId, (int)$yearId);
        $this->renderJson($result);
    }

    /**
     * Get promotion list
     */
    public function getPromotionList(): void
    {
        $yearId = $_GET['year_id'] ?? null;
        $promotionType = $_GET['promotion_type'] ?? null;

        if (!$yearId) {
            $this->renderJson(['success' => false, 'message' => 'Year ID required']);
            return;
        }

        $result = $this->intelligenceService->getPromotionList((int)$yearId, $promotionType);
        $this->renderJson($result);
    }

    // ================================================================
    // CLASS PERFORMANCE
    // ================================================================

    /**
     * Get class performance
     */
    public function getClassPerformance(): void
    {
        $classId = $_GET['class_id'] ?? null;
        $termId = $_GET['term_id'] ?? null;

        if (!$classId || !$termId) {
            $this->renderJson(['success' => false, 'message' => 'Class ID and Term ID required']);
            return;
        }

        $result = $this->assessmentService->getClassPerformance((int)$classId, (int)$termId);
        $this->renderJson($result);
    }

    // ================================================================
    // HELPER METHODS
    // ================================================================

    /**
     * Get request data
     */
    private function getRequestData(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);
        return $input ?: [];
    }

    /**
     * Render JSON response
     */
    private function renderJson(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}