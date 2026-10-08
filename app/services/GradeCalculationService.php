<?php

/**
 * GradeCalculationService.php
 * Grade Calculation Service
 * 
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/GradingSystem.php';
require_once dirname(__DIR__, 2) . '/models/Platform/AssessmentProfile.php';

class GradeCalculationService
{
    private $gradingSystemModel;
    private $assessmentProfileModel;

    public function __construct()
    {
        $this->gradingSystemModel = new GradingSystem();
        $this->assessmentProfileModel = new AssessmentProfile();
    }

    /**
     * Calculate grade from score
     */
    public function calculateGrade(int $systemId, float $score): array
    {
        $grade = $this->gradingSystemModel->getGradeByScore($systemId, $score);

        if (!$grade) {
            return [
                'grade' => null,
                'grade_point' => null,
                'grade_name' => null,
                'remark' => 'No grade found',
                'is_pass' => false
            ];
        }

        return [
            'grade' => $grade['grade_code'],
            'grade_point' => (float)$grade['grade_point'],
            'grade_name' => $grade['grade_name'],
            'remark' => $grade['remark'],
            'is_pass' => (bool)$grade['is_pass']
        ];
    }

    /**
     * Calculate weighted score
     */
    public function calculateWeightedScore(array $scores, array $weights): float
    {
        $totalWeighted = 0;
        $totalWeight = 0;

        foreach ($scores as $componentId => $score) {
            $weight = $weights[$componentId] ?? 0;
            $totalWeighted += $score * ($weight / 100);
            $totalWeight += $weight;
        }

        return $totalWeight > 0 ? ($totalWeighted / ($totalWeight / 100)) : 0;
    }

    /**
     * Calculate percentage
     */
    public function calculatePercentage(float $score, float $maxScore): float
    {
        return $maxScore > 0 ? ($score / $maxScore) * 100 : 0;
    }

    /**
     * Round score based on rules
     */
    public function roundScore(float $score, array $roundingRules): float
    {
        $decimals = $roundingRules['raw_score_decimals'] ?? 2;
        $method = $roundingRules['rounding_method'] ?? 'half_up';

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
     * Calculate subject total
     */
    public function calculateSubjectTotal(array $componentScores, array $components): array
    {
        $rawTotal = 0;
        $weightedTotal = 0;
        $totalWeight = 0;
        $details = [];

        foreach ($components as $component) {
            $score = $componentScores[$component['id']] ?? 0;
            $maxScore = $component['max_score'] ?? 100;
            $weight = (float)($component['weight'] ?? 0);

            $percentage = $this->calculatePercentage($score, $maxScore);
            $weightedScore = $score * ($weight / 100);

            $details[] = [
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

        return [
            'raw_total' => $rawTotal,
            'weighted_total' => $weightedTotal,
            'percentage' => $totalWeight > 0 ? ($weightedTotal / ($totalWeight / 100)) : 0,
            'details' => $details
        ];
    }

    /**
     * Calculate class average
     */
    public function calculateClassAverage(array $studentScores): array
    {
        if (empty($studentScores)) {
            return ['average' => 0, 'min' => 0, 'max' => 0, 'count' => 0];
        }

        $scores = array_column($studentScores, 'score');
        return [
            'average' => array_sum($scores) / count($scores),
            'min' => min($scores),
            'max' => max($scores),
            'count' => count($scores)
        ];
    }
}
