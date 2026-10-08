<?php
/**
 * IntelligenceService.php
 * 
 * Enterprise Academic Intelligence Service
 * Handles AI-driven intelligence operations
 * 
 * @package EduTrack
 * @subpackage Services\Assessment
 * @version 2.0
 */

require_once __DIR__ . '/../../models/Assessment/IntelligenceModel.php';
require_once __DIR__ . '/../../models/Assessment/AssessmentModel.php';
require_once __DIR__ . '/../../helpers/DatabaseHelper.php';
require_once __DIR__ . '/../../helpers/LoggerHelper.php';

class IntelligenceService
{
    /**
     * @var IntelligenceModel Intelligence model instance
     */
    private $intelligenceModel;

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
        $this->intelligenceModel = new IntelligenceModel();
        $this->assessmentModel = new AssessmentModel();
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
    }

    // ================================================================
    // INTELLIGENCE RULES
    // ================================================================

    /**
     * Get all intelligence rules
     */
    public function getIntelligenceRules(int $schoolId = 1): array
    {
        try {
            $rules = $this->intelligenceModel->getIntelligenceRules($schoolId);
            return ['success' => true, 'data' => $rules];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Create intelligence rule
     */
    public function createIntelligenceRule(array $data): array
    {
        try {
            $required = ['rule_name', 'rule_code', 'rule_type', 'condition_value', 'output_template'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => "Missing required field: $field"];
                }
            }

            $id = $this->intelligenceModel->createIntelligenceRule($data);
            return ['success' => true, 'message' => 'Rule created successfully', 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // PERSONALITY SUMMARY GENERATION
    // ================================================================

    /**
     * Generate personality summary for a student
     */
    public function generatePersonalitySummary(int $studentId, int $termId): array
    {
        try {
            // Get student results
            $results = $this->assessmentModel->getStudentResults($studentId, $termId);
            
            if (empty($results)) {
                return ['success' => false, 'message' => 'No results found for this student'];
            }

            // Get intelligence rules for strengths and weaknesses
            $strengthRules = $this->intelligenceModel->getRulesByType('strength');
            $weaknessRules = $this->intelligenceModel->getRulesByType('weakness');

            $strengths = [];
            $weaknesses = [];
            $totalScore = 0;
            $subjectScores = [];

            foreach ($results as $result) {
                $subjectScores[$result['subject_name']] = $result['total_score'];
                $totalScore += $result['total_score'];

                // Check strengths
                foreach ($strengthRules as $rule) {
                    $condition = json_decode($rule['condition_value'], true);
                    if ($this->evaluateCondition((float)$result['total_score'], $condition)) {
                        $strengths[] = str_replace('{subject}', $result['subject_name'], $rule['output_template']);
                    }
                }

                // Check weaknesses
                foreach ($weaknessRules as $rule) {
                    $condition = json_decode($rule['condition_value'], true);
                    if ($this->evaluateCondition((float)$result['total_score'], $condition)) {
                        $weaknesses[] = str_replace('{subject}', $result['subject_name'], $rule['output_template']);
                    }
                }
            }

            // Calculate average
            $averageScore = count($results) > 0 ? round($totalScore / count($results), 2) : 0;

            // Generate summary
            $summary = $this->generateSummaryText($averageScore, $strengths, $weaknesses);

            // Determine motivation and confidence levels
            $motivationLevel = $this->determineMotivationLevel($averageScore);
            $confidenceLevel = $this->determineConfidenceLevel($averageScore);

            // Determine personality type
            $personalityType = $this->determinePersonalityType($averageScore, $subjectScores);

            // Save personality summary
            $data = [
                'student_id' => $studentId,
                'academic_term_id' => $termId,
                'personality_type' => $personalityType,
                'strengths' => implode("\n", array_slice($strengths, 0, 5)),
                'weaknesses' => implode("\n", array_slice($weaknesses, 0, 3)),
                'learning_style' => $this->determineLearningStyle($averageScore),
                'motivation_level' => $motivationLevel,
                'confidence_level' => $confidenceLevel,
                'social_skills' => $this->determineSocialSkills($averageScore),
                'summary' => $summary
            ];

            $id = $this->intelligenceModel->savePersonalitySummary($data);

            return [
                'success' => true,
                'message' => 'Personality summary generated successfully',
                'id' => $id,
                'data' => $data
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to generate personality summary: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get personality summary
     */
    public function getPersonalitySummary(int $studentId, int $termId): array
    {
        try {
            $summary = $this->intelligenceModel->getPersonalitySummary($studentId, $termId);
            return ['success' => true, 'data' => $summary];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // BEHAVIOUR SUMMARY GENERATION
    // ================================================================

    /**
     * Generate behaviour summary for a student
     */
    public function generateBehaviourSummary(int $studentId, int $termId): array
    {
        try {
            // Get student attendance and behaviour data
            $attendance = $this->db->fetchOne("
                SELECT attendance_percentage FROM attendance_summary 
                WHERE student_id = ? AND term_id = ?
            ", [$studentId, $termId]);

            $attendanceScore = (float)($attendance['attendance_percentage'] ?? 0);

            // Get behaviour scores (simplified - would come from behaviour module)
            $behaviourScore = $this->calculateBehaviourScore($studentId, $termId);

            // Generate grades and remarks
            $behaviourGrade = $this->getBehaviourGrade($behaviourScore);

            $summary = $this->generateBehaviourSummaryText($behaviourScore, $attendanceScore);

            $data = [
                'student_id' => $studentId,
                'academic_term_id' => $termId,
                'behaviour_score' => $behaviourScore,
                'behaviour_grade' => $behaviourGrade,
                'attendance_score' => $attendanceScore,
                'punctuality_score' => min($attendanceScore + 5, 100),
                'participation_score' => $behaviourScore,
                'cooperation_score' => min($behaviourScore + 5, 100),
                'responsibility_score' => min($behaviourScore + 10, 100),
                'behaviour_summary' => $summary,
                'class_teacher_remark' => $this->generateClassTeacherRemark($behaviourScore, $attendanceScore),
                'headteacher_remark' => $this->generateHeadteacherRemark($behaviourScore, $attendanceScore)
            ];

            $id = $this->intelligenceModel->saveBehaviourSummary($data);

            return [
                'success' => true,
                'message' => 'Behaviour summary generated successfully',
                'id' => $id,
                'data' => $data
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to generate behaviour summary: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get behaviour summary
     */
    public function getBehaviourSummary(int $studentId, int $termId): array
    {
        try {
            $summary = $this->intelligenceModel->getBehaviourSummary($studentId, $termId);
            return ['success' => true, 'data' => $summary];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // RISK DETECTION
    // ================================================================

    /**
     * Detect academic risk for a student
     */
    public function detectRisk(int $studentId, int $termId): array
    {
        try {
            // Get student results
            $results = $this->assessmentModel->getStudentResults($studentId, $termId);
            
            if (empty($results)) {
                return ['success' => false, 'message' => 'No results found for this student'];
            }

            // Calculate risk indicators
            $totalSubjects = count($results);
            $failingSubjects = 0;
            $avgScore = 0;

            foreach ($results as $result) {
                $score = (float)($result['total_score'] ?? 0);
                $avgScore += $score;

                if ($score < 50) {
                    $failingSubjects++;
                }
            }

            $avgScore = $totalSubjects > 0 ? round($avgScore / $totalSubjects, 2) : 0;

            // Check for consecutive failures (simplified - would check historical data)
            $consecutiveFailures = $failingSubjects > 0 ? min($failingSubjects, 5) : 0;

            // Determine risk level
            $riskLevel = $this->determineRiskLevel($avgScore, $failingSubjects, $totalSubjects);

            // Generate recommendation
            $recommendation = $this->generateRiskRecommendation($riskLevel, $failingSubjects);

            // Get attendance
            $attendance = $this->db->fetchOne("
                SELECT attendance_percentage FROM attendance_summary 
                WHERE student_id = ? AND term_id = ?
            ", [$studentId, $termId]);

            $attendancePercentage = (float)($attendance['attendance_percentage'] ?? 0);

            $data = [
                'student_id' => $studentId,
                'academic_term_id' => $termId,
                'risk_level' => $riskLevel,
                'risk_score' => $this->calculateRiskScore($avgScore, $failingSubjects),
                'academic_performance' => $avgScore,
                'attendance_percentage' => $attendancePercentage,
                'behaviour_score' => 0,
                'subjects_failing' => $failingSubjects,
                'consecutive_failures' => $consecutiveFailures,
                'declining_trend' => $this->detectDecliningTrend($studentId),
                'alert_reason' => $this->generateAlertReason($riskLevel, $failingSubjects),
                'recommendation' => $recommendation,
                'intervention_required' => $riskLevel !== 'low' ? 1 : 0,
                'intervention_type' => $this->determineInterventionType($riskLevel)
            ];

            $id = $this->intelligenceModel->saveRiskProfile($data);

            return [
                'success' => true,
                'message' => 'Risk profile generated successfully',
                'id' => $id,
                'data' => $data
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to detect risk: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get risk profile
     */
    public function getRiskProfile(int $studentId, int $termId): array
    {
        try {
            $profile = $this->intelligenceModel->getRiskProfile($studentId, $termId);
            return ['success' => true, 'data' => $profile];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get at-risk students
     * 
     * @param int|null $termId Term ID (optional - uses current term if not provided)
     * @param string|null $riskLevel Risk level filter (optional)
     * @return array
     */
    public function getAtRiskStudents($termId = null, $riskLevel = null): array
    {
        try {
            // Convert to int if provided
            $termId = $termId !== null && !empty($termId) ? (int)$termId : null;

            // If no term ID provided, get the current term
            if ($termId === null) {
                $currentTerm = $this->db->fetchOne("
                    SELECT id FROM academic_terms 
                    WHERE is_current = 1 AND is_active = 1 
                    LIMIT 1
                ");
                $termId = isset($currentTerm['id']) ? (int)$currentTerm['id'] : null;
            }

            // If still no term, return empty result
            if ($termId === null) {
                return [
                    'success' => true, 
                    'data' => [], 
                    'message' => 'No active term found. Please configure academic terms.'
                ];
            }

            $students = $this->intelligenceModel->getAtRiskStudents($termId, $riskLevel);
            
            return [
                'success' => true,
                'data' => $students,
                'total' => count($students),
                'term_id' => $termId
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to get at-risk students: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ];
        }
    }

    // ================================================================
    // PROMOTION EVALUATION
    // ================================================================

    /**
     * Evaluate promotion for a student
     */
    public function evaluatePromotion(int $studentId, int $yearId): array
    {
        try {
            // Get student results for the year
            $results = $this->db->fetchAll("
                SELECT ar.*, s.subject_name
                FROM assessment_results ar
                JOIN subjects s ON ar.subject_id = s.id
                WHERE ar.student_id = ? 
                AND ar.academic_term_id IN (SELECT id FROM academic_terms WHERE academic_year_id = ?)
                AND ar.is_active = 1
            ", [$studentId, $yearId]);

            if (empty($results)) {
                return ['success' => false, 'message' => 'No results found for this student'];
            }

            $totalSubjects = count($results);
            $passingSubjects = 0;
            $failingSubjects = 0;
            $totalScore = 0;

            foreach ($results as $result) {
                $score = (float)($result['total_score'] ?? 0);
                $totalScore += $score;
                if ($score >= 50) {
                    $passingSubjects++;
                } else {
                    $failingSubjects++;
                }
            }

            $averageScore = $totalSubjects > 0 ? round($totalScore / $totalSubjects, 2) : 0;
            $attendance = $this->db->fetchOne("
                SELECT attendance_percentage FROM attendance_summary 
                WHERE student_id = ? AND academic_year_id = ?
            ", [$studentId, $yearId]);

            $attendancePercentage = (float)($attendance['attendance_percentage'] ?? 0);

            // Determine promotion type
            $promotionType = $this->determinePromotionType($averageScore, $failingSubjects, $totalSubjects, $attendancePercentage);

            // Get student's current grade level
            $currentGrade = $this->db->fetchOne("
                SELECT gl.id, gl.level_name 
                FROM students s
                JOIN student_enrollments se ON s.id = se.student_id
                JOIN class_sections cs ON se.class_section_id = cs.id
                JOIN grade_levels gl ON cs.grade_level_id = gl.id
                WHERE s.id = ? AND se.is_active = 1
            ", [$studentId]);

            $fromGradeId = isset($currentGrade['id']) ? (int)$currentGrade['id'] : null;

            // Determine next grade level
            $toGradeId = null;
            if ($promotionType === 'promoted' || $promotionType === 'promoted_with_conditions') {
                if ($fromGradeId !== null) {
                    $nextGrade = $this->db->fetchOne("
                        SELECT id FROM grade_levels 
                        WHERE promotion_order = (SELECT promotion_order + 1 FROM grade_levels WHERE id = ?)
                    ", [$fromGradeId]);
                    $toGradeId = isset($nextGrade['id']) ? (int)$nextGrade['id'] : null;
                }
            }

            $data = [
                'student_id' => $studentId,
                'academic_year_id' => $yearId,
                'from_grade_level_id' => $fromGradeId,
                'to_grade_level_id' => $toGradeId,
                'promotion_type' => $promotionType,
                'average_score' => $averageScore,
                'passing_subjects' => $passingSubjects,
                'failing_subjects' => $failingSubjects,
                'total_subjects' => $totalSubjects,
                'attendance_percentage' => $attendancePercentage,
                'eligibility_criteria_met' => $promotionType === 'promoted' ? 1 : 0,
                'teacher_recommendation' => $this->generateTeacherRecommendation($promotionType, $averageScore),
                'headteacher_approval' => 0
            ];

            $id = $this->intelligenceModel->savePromotionEvaluation($data);

            return [
                'success' => true,
                'message' => 'Promotion evaluation completed',
                'id' => $id,
                'data' => $data
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to evaluate promotion: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get promotion evaluation
     */
    public function getPromotionEvaluation(int $studentId, int $yearId): array
    {
        try {
            $evaluation = $this->intelligenceModel->getPromotionEvaluation($studentId, $yearId);
            return ['success' => true, 'data' => $evaluation];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get promotion list
     */
    public function getPromotionList(int $yearId, $promotionType = null): array
    {
        try {
            $list = $this->intelligenceModel->getPromotionList($yearId, $promotionType);
            return ['success' => true, 'data' => $list];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Calculate GPA for a student
     */
    public function calculateGPA(int $studentId, int $termId): array
    {
        try {
            $gpa = $this->intelligenceModel->calculateGPA($studentId, $termId);
            return ['success' => true, 'data' => $gpa];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get student GPA
     */
    public function getStudentGPA(int $studentId, int $termId): array
    {
        try {
            $gpa = $this->intelligenceModel->getStudentGPA($studentId, $termId);
            return ['success' => true, 'data' => $gpa];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get cumulative GPA
     */
    public function getCumulativeGPA(int $studentId): array
    {
        try {
            $cgpa = $this->intelligenceModel->getCumulativeGPA($studentId);
            return ['success' => true, 'data' => $cgpa];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // HELPER METHODS
    // ================================================================

    /**
     * Evaluate a condition
     */
    private function evaluateCondition(float $value, array $condition): bool
    {
        $operator = $condition['operator'] ?? '>=';
        $threshold = isset($condition['threshold']) ? (float)$condition['threshold'] : 0;

        switch ($operator) {
            case '>=':
                return $value >= $threshold;
            case '>':
                return $value > $threshold;
            case '<':
                return $value < $threshold;
            case '<=':
                return $value <= $threshold;
            case '==':
                return $value == $threshold;
            case '!=':
                return $value != $threshold;
            default:
                return false;
        }
    }

    /**
     * Generate summary text
     */
    private function generateSummaryText(float $average, array $strengths, array $weaknesses): string
    {
        $summary = "This student is performing at an average of {$average}%. ";
        
        if (!empty($strengths)) {
            $summary .= "Strengths: " . implode('; ', array_slice($strengths, 0, 3)) . ". ";
        }
        
        if (!empty($weaknesses)) {
            $summary .= "Areas needing improvement: " . implode('; ', array_slice($weaknesses, 0, 2)) . ". ";
        }

        if ($average >= 80) {
            $summary .= "Overall, this student is performing excellently and should be encouraged to continue their good work.";
        } elseif ($average >= 70) {
            $summary .= "Overall, this student is performing well and shows good potential.";
        } elseif ($average >= 60) {
            $summary .= "Overall, this student is performing satisfactorily but could improve with more effort.";
        } else {
            $summary .= "Overall, this student needs significant improvement and additional support.";
        }

        return $summary;
    }

    /**
     * Determine motivation level
     */
    private function determineMotivationLevel(float $average): string
    {
        if ($average >= 80) return 'high';
        if ($average >= 60) return 'medium';
        return 'low';
    }

    /**
     * Determine confidence level
     */
    private function determineConfidenceLevel(float $average): string
    {
        if ($average >= 85) return 'high';
        if ($average >= 65) return 'medium';
        return 'low';
    }

    /**
     * Determine personality type
     */
    private function determinePersonalityType(float $average, array $subjectScores): string
    {
        if ($average >= 80) return 'High Achiever';
        if ($average >= 60) return 'Consistent Performer';
        return 'Needs Support';
    }

    /**
     * Determine learning style
     */
    private function determineLearningStyle(float $average): string
    {
        if ($average >= 75) return 'Visual/Auditory Learner';
        if ($average >= 50) return 'Kinesthetic/Tactile Learner';
        return 'Needs Individual Support';
    }

    /**
     * Determine social skills
     */
    private function determineSocialSkills(float $average): string
    {
        if ($average >= 80) return 'excellent';
        if ($average >= 60) return 'good';
        return 'average';
    }

    /**
     * Calculate behaviour score (simplified)
     */
    private function calculateBehaviourScore(int $studentId, int $termId): float
    {
        // In production, this would come from behaviour module
        return 75.00;
    }

    /**
     * Get behaviour grade
     */
    private function getBehaviourGrade(float $score): string
    {
        if ($score >= 85) return 'A';
        if ($score >= 70) return 'B';
        if ($score >= 55) return 'C';
        if ($score >= 40) return 'D';
        return 'F';
    }

    /**
     * Get attendance grade
     */
    private function getAttendanceGrade(float $score): string
    {
        if ($score >= 90) return 'Excellent';
        if ($score >= 75) return 'Good';
        if ($score >= 60) return 'Fair';
        return 'Poor';
    }

    /**
     * Generate behaviour summary text
     */
    private function generateBehaviourSummaryText(float $behaviour, float $attendance): string
    {
        if ($behaviour >= 80 && $attendance >= 90) {
            return "Excellent behaviour and attendance. A model student.";
        } elseif ($behaviour >= 70 && $attendance >= 75) {
            return "Good behaviour and attendance. Continues to show positive growth.";
        } elseif ($behaviour >= 60 && $attendance >= 60) {
            return "Satisfactory behaviour and attendance. Some areas need improvement.";
        } else {
            return "Behaviour and attendance need significant improvement. Please monitor closely.";
        }
    }

    /**
     * Generate class teacher remark
     */
    private function generateClassTeacherRemark(float $behaviour, float $attendance): string
    {
        if ($behaviour >= 80 && $attendance >= 90) {
            return "An exemplary student who demonstrates excellent conduct and regular attendance. Keep up the good work!";
        } elseif ($behaviour >= 70 && $attendance >= 75) {
            return "Shows good conduct and satisfactory attendance. Continues to make positive progress.";
        } else {
            return "Needs improvement in behaviour and/or attendance. Please ensure regular attendance and proper conduct.";
        }
    }

    /**
     * Generate headteacher remark
     */
    private function generateHeadteacherRemark(float $behaviour, float $attendance): string
    {
        if ($behaviour >= 80 && $attendance >= 90) {
            return "A well-disciplined student with excellent attendance. Deserves commendation for outstanding conduct.";
        } elseif ($behaviour >= 70 && $attendance >= 75) {
            return "Satisfactory conduct and attendance. Encouraged to maintain this standard.";
        } else {
            return "Needs immediate attention regarding behaviour and/or attendance. Parental involvement recommended.";
        }
    }

    /**
     * Determine risk level
     */
    private function determineRiskLevel(float $average, int $failing, int $total): string
    {
        if ($average >= 70 && $failing == 0) return 'low';
        if ($average >= 50 && $failing <= 1) return 'moderate';
        if ($average >= 40 && $failing <= 3) return 'high';
        return 'critical';
    }

    /**
     * Calculate risk score
     */
    private function calculateRiskScore(float $average, int $failing): float
    {
        $score = 0;
        if ($average < 60) $score += 25;
        if ($average < 50) $score += 25;
        if ($average < 40) $score += 25;
        $score += min($failing * 10, 25);
        return min($score, 100);
    }

    /**
     * Detect declining trend (simplified)
     */
    private function detectDecliningTrend(int $studentId): int
    {
        // In production, compare historical performance
        return 0;
    }

    /**
     * Generate alert reason
     */
    private function generateAlertReason(string $riskLevel, int $failing): string
    {
        if ($riskLevel === 'critical') {
            return "Student is at critical risk with {$failing} failing subjects. Immediate intervention required.";
        }
        if ($riskLevel === 'high') {
            return "Student is at high risk with {$failing} failing subjects. Intervention recommended.";
        }
        if ($riskLevel === 'moderate') {
            return "Student shows moderate risk with some failing subjects. Monitor progress closely.";
        }
        return "Student is performing well with no significant risks.";
    }

    /**
     * Generate risk recommendation
     */
    private function generateRiskRecommendation(string $riskLevel, int $failing): string
    {
        if ($riskLevel === 'critical') {
            return "Urgent intervention required. Schedule parent-teacher meeting immediately. Consider academic support programs.";
        }
        if ($riskLevel === 'high') {
            return "Intervention recommended. Contact parents and develop improvement plan.";
        }
        if ($riskLevel === 'moderate') {
            return "Monitor progress closely. Consider additional support in failing subjects.";
        }
        return "Student is on track. Continue regular monitoring and encouragement.";
    }

    /**
     * Determine intervention type
     */
    private function determineInterventionType(string $riskLevel): ?string
    {
        if ($riskLevel === 'critical') return 'urgent_parent_meeting';
        if ($riskLevel === 'high') return 'academic_support';
        if ($riskLevel === 'moderate') return 'monitoring';
        return null;
    }

    /**
     * Determine promotion type
     */
    private function determinePromotionType(float $average, int $failing, int $total, float $attendance): string
    {
        $passingSubjects = $total - $failing;
        $passRate = $total > 0 ? ($passingSubjects / $total) * 100 : 0;

        if ($average >= 60 && $passRate >= 80 && $attendance >= 75) {
            return 'promoted';
        } elseif ($average >= 50 && $passRate >= 70 && $attendance >= 70) {
            return 'promoted_with_conditions';
        } elseif ($average < 40 || $passRate < 50 || $attendance < 60) {
            return 'not_promoted';
        } elseif ($average >= 40 && $passRate >= 50) {
            return 'retained';
        } else {
            return 'not_promoted';
        }
    }

    /**
     * Generate teacher recommendation
     */
    private function generateTeacherRecommendation(string $promotionType, float $average): string
    {
        if ($promotionType === 'promoted') {
            return "Student meets all promotion requirements. Recommended for promotion to next grade.";
        } elseif ($promotionType === 'promoted_with_conditions') {
            return "Student partially meets promotion requirements. Recommended for promotion with conditions: must improve in failing subjects.";
        } elseif ($promotionType === 'not_promoted') {
            return "Student does not meet promotion requirements. Recommended for retention.";
        } else {
            return "Student's promotion status requires further review.";
        }
    }
}