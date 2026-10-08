<?php

/**
 * PreviewCalculationService.php
 * Preview Calculation Service - Test grading configurations before publishing
 * 
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/AssessmentProfile.php';
require_once dirname(__DIR__, 2) . '/models/Platform/GradingSystem.php';
require_once dirname(__DIR__, 2) . '/models/Platform/AggregationRule.php';
require_once dirname(__DIR__, 2) . '/models/Platform/AssessmentSubjectRule.php';

class PreviewCalculationService
{
    private $assessmentProfileModel;
    private $gradingSystemModel;
    private $aggregationRuleModel;
    private $subjectRuleModel;

    public function __construct()
    {
        $this->assessmentProfileModel = new AssessmentProfile();
        $this->gradingSystemModel = new GradingSystem();
        $this->aggregationRuleModel = new AggregationRule();
        $this->subjectRuleModel = new AssessmentSubjectRule();
    }

    /**
     * Preview calculation for a single student
     */
    public function previewStudent(int $profileId, array $studentData): array
    {
        $profile = $this->assessmentProfileModel->getFullProfile($profileId);
        if (!$profile) {
            return ['success' => false, 'message' => 'Profile not found'];
        }

        $results = [];
        $allGradePoints = [];
        $coreGradePoints = [];
        $electiveGradePoints = [];

        // Process each subject
        foreach ($studentData['subjects'] as $subjectId => $subjectScores) {
            $subjectResult = $this->calculateSubject($profile, $subjectId, $subjectScores);
            $results[] = $subjectResult;

            if ($subjectResult['grade_point'] !== null) {
                $allGradePoints[] = $subjectResult['grade_point'];

                // Check if core or elective
                $classification = $this->subjectRuleModel->getClassification($profileId, $subjectId);
                if ($classification === 'CORE') {
                    $coreGradePoints[] = $subjectResult['grade_point'];
                } elseif ($classification === 'ELECTIVE') {
                    $electiveGradePoints[] = $subjectResult['grade_point'];
                }
            }
        }

        // Calculate aggregate
        $aggregateResult = $this->calculateAggregate($profile, $coreGradePoints, $electiveGradePoints);

        // Calculate rank (for preview purposes)
        $rankResult = $this->calculateRank($profile, $allGradePoints);

        // Get pass/fail status
        $passResult = $this->checkPass($profile, $allGradePoints, $coreGradePoints);

        // Generate remark
        $remark = $this->generateRemark($profile, $aggregateResult['score'] ?? 0);

        return [
            'success' => true,
            'data' => [
                'student' => $studentData['name'] ?? 'Sample Student',
                'subjects' => $results,
                'aggregate' => $aggregateResult,
                'rank' => $rankResult,
                'pass_status' => $passResult,
                'remark' => $remark,
                'profile' => [
                    'name' => $profile['profile_name'],
                    'components' => $profile['components'],
                    'grading_system' => $profile['grading_system']
                ]
            ]
        ];
    }

    /**
     * Preview bulk calculation for multiple students
     */
    public function previewBulk(int $profileId, array $students): array
    {
        $results = [];
        foreach ($students as $student) {
            $result = $this->previewStudent($profileId, $student);
            if ($result['success']) {
                $results[] = $result['data'];
            }
        }

        // Calculate class averages
        $classAverages = $this->calculateClassAverages($results);

        return [
            'success' => true,
            'data' => [
                'students' => $results,
                'class_averages' => $classAverages
            ]
        ];
    }

    /**
     * Calculate a single subject
     */
    private function calculateSubject($profile, int $subjectId, array $scores): array
    {
        $rawTotal = 0;
        $weightedTotal = 0;
        $totalWeight = 0;
        $componentDetails = [];

        foreach ($profile['components'] as $component) {
            $score = $scores[$component['id']] ?? 0;
            $maxScore = $component['max_score'] ?? 100;
            $weight = (float)($component['weight'] ?? 0);

            // Validate score
            if ($score > $maxScore) {
                $score = $maxScore;
            }
            if ($score < 0) {
                $score = 0;
            }

            $percentage = $maxScore > 0 ? ($score / $maxScore) * 100 : 0;
            $weightedScore = $score * ($weight / 100);

            $componentDetails[] = [
                'component_id' => $component['id'],
                'component_name' => $component['component_name'],
                'score' => $score,
                'max_score' => $maxScore,
                'weight' => $weight,
                'percentage' => $percentage,
                'weighted_score' => $weightedScore
            ];

            $rawTotal += $score;
            $weightedTotal += $weightedScore;
            $totalWeight += $weight;
        }

        $finalScore = $totalWeight > 0 ? ($weightedTotal / ($totalWeight / 100)) : 0;

        // Apply rounding
        if ($profile['rounding_rule']) {
            $finalScore = $this->roundScore($finalScore, $profile['rounding_rule']);
        }

        // Get grade
        $gradeData = null;
        if ($profile['grading_system']) {
            $gradeData = $this->getGrade($profile['grading_system']['id'], $finalScore);
        }

        return [
            'subject_id' => $subjectId,
            'raw_total' => $rawTotal,
            'weighted_total' => $weightedTotal,
            'final_score' => $finalScore,
            'grade' => $gradeData['grade'] ?? null,
            'grade_point' => $gradeData['grade_point'] ?? null,
            'grade_name' => $gradeData['grade_name'] ?? null,
            'is_pass' => $gradeData['is_pass'] ?? false,
            'components' => $componentDetails
        ];
    }

    /**
     * Get grade from score
     */
    private function getGrade(int $systemId, float $score): array
    {
        $grade = $this->gradingSystemModel->getGradeByScore($systemId, $score);

        if (!$grade) {
            return [
                'grade' => null,
                'grade_point' => null,
                'grade_name' => null,
                'is_pass' => false
            ];
        }

        return [
            'grade' => $grade['grade_code'],
            'grade_point' => (float)$grade['grade_point'],
            'grade_name' => $grade['grade_name'],
            'is_pass' => (bool)$grade['is_pass']
        ];
    }

    /**
     * Calculate aggregate
     */
    private function calculateAggregate($profile, array $coreGrades, array $electiveGrades): array
    {
        $aggregationRule = $profile['aggregation_rule'];
        if (!$aggregationRule) {
            return ['score' => null, 'method' => 'none', 'details' => []];
        }

        $coreCount = (int)($aggregationRule['core_count'] ?? 0);
        $electiveCount = (int)($aggregationRule['elective_count'] ?? 0);
        $selectionMethod = $aggregationRule['elective_selection'] ?? 'best';

        // Sort grades (lower is better for most grading systems)
        sort($coreGrades);

        // Take top N core subjects
        $selectedCore = array_slice($coreGrades, 0, $coreCount);

        // Handle electives
        $selectedElectives = [];
        if ($electiveCount > 0 && !empty($electiveGrades)) {
            sort($electiveGrades);
            if ($selectionMethod === 'best') {
                $selectedElectives = array_slice($electiveGrades, 0, $electiveCount);
            } else {
                $selectedElectives = $electiveGrades;
            }
        }

        // Combine all grades
        $allGrades = array_merge($selectedCore, $selectedElectives);

        // Calculate aggregate based on method
        $method = $aggregationRule['aggregation_method'] ?? 'sum';
        $score = 0;

        switch ($method) {
            case 'average':
                $score = count($allGrades) > 0 ? array_sum($allGrades) / count($allGrades) : 0;
                break;
            case 'sum':
            default:
                $score = array_sum($allGrades);
                break;
        }

        return [
            'score' => $this->roundScore($score, $profile['rounding_rule'] ?? ['aggregate_decimals' => 0, 'rounding_method' => 'half_up']),
            'method' => $method,
            'core_selected' => count($selectedCore),
            'elective_selected' => count($selectedElectives),
            'details' => [
                'core_grades' => $selectedCore,
                'elective_grades' => $selectedElectives,
                'all_grades' => $allGrades
            ]
        ];
    }

    /**
     * Calculate rank (preview only)
     */
    private function calculateRank($profile, array $gradePoints): array
    {
        // For preview, we only calculate relative rank
        $total = count($gradePoints);
        if ($total === 0) {
            return ['position' => null, 'total' => 0, 'percentage' => null];
        }

        // Sort and find position
        $sorted = $gradePoints;
        sort($sorted);

        $position = 1;
        foreach ($sorted as $point) {
            if ($point < end($gradePoints)) {
                $position++;
            }
        }

        return [
            'position' => $position,
            'total' => $total,
            'percentage' => round(($position / $total) * 100, 1)
        ];
    }

    /**
     * Check pass/fail
     */
    private function checkPass($profile, array $allGrades, array $coreGrades): array
    {
        $passRule = $profile['pass_rule'];
        if (!$passRule) {
            return ['passed' => null, 'message' => 'No pass rule defined'];
        }

        $threshold = (float)($passRule['pass_threshold'] ?? 50);
        $subjectLevel = $passRule['subject_level'] ?? 'overall';
        $minSubjects = (int)($passRule['min_subjects_pass'] ?? 0);
        $coreRequired = (bool)($passRule['core_subjects_required'] ?? false);

        $passed = true;
        $messages = [];

        // Check overall
        if ($subjectLevel === 'overall' || $subjectLevel === 'all') {
            $avgGrade = count($allGrades) > 0 ? array_sum($allGrades) / count($allGrades) : 0;
            if ($avgGrade < $threshold) {
                $passed = false;
                $messages[] = "Average grade ($avgGrade) below threshold ($threshold)";
            }
        }

        // Check core subjects
        if ($coreRequired && !empty($coreGrades)) {
            $failedCore = array_filter($coreGrades, function ($g) use ($threshold) {
                return $g > $threshold; // For grade points, higher means worse
            });
            if (count($failedCore) > 0) {
                $passed = false;
                $messages[] = "Failed core subjects: " . count($failedCore);
            }
        }

        // Check minimum subjects
        if ($minSubjects > 0 && count($allGrades) < $minSubjects) {
            $passed = false;
            $messages[] = "Insufficient subjects: " . count($allGrades) . " of $minSubjects required";
        }

        return [
            'passed' => $passed,
            'messages' => $messages,
            'threshold' => $threshold,
            'average_grade' => count($allGrades) > 0 ? array_sum($allGrades) / count($allGrades) : null
        ];
    }

    /**
     * Generate remark
     */
    private function generateRemark($profile, float $score): string
    {
        $remarkRules = $profile['remark_rules'] ?? [];
        foreach ($remarkRules as $rule) {
            if ($score >= $rule['min_score'] && $score <= $rule['max_score']) {
                return $rule['remark_text'];
            }
        }
        return 'No remark available for this score range.';
    }

    /**
     * Round score based on rules
     */
    private function roundScore(float $score, array $rules): float
    {
        $decimals = $rules['aggregate_decimals'] ?? 0;
        $method = $rules['rounding_method'] ?? 'half_up';

        switch ($method) {
            case 'half_down':
                return round($score - 0.0001, $decimals);
            case 'ceil':
                return ceil($score * pow(10, $decimals)) / pow(10, $decimals);
            case 'floor':
                return floor($score * pow(10, $decimals)) / pow(10, $decimals);
            case 'half_up':
            default:
                return round($score, $decimals);
        }
    }

    /**
     * Calculate class averages
     */
    private function calculateClassAverages(array $results): array
    {
        if (empty($results)) {
            return [];
        }

        $scores = [];
        foreach ($results as $student) {
            if (isset($student['aggregate']['score'])) {
                $scores[] = $student['aggregate']['score'];
            }
        }

        return [
            'average' => count($scores) > 0 ? array_sum($scores) / count($scores) : 0,
            'min' => count($scores) > 0 ? min($scores) : 0,
            'max' => count($scores) > 0 ? max($scores) : 0,
            'count' => count($scores)
        ];
    }
}
