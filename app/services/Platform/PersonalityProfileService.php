<?php

/**
 * PersonalityProfileService.php
 * Personality Profile Generation Service
 * 
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/PersonalityProfileRule.php';

class PersonalityProfileService
{
    private $ruleModel;

    public function __construct()
    {
        $this->ruleModel = new PersonalityProfileRule();
    }

    /**
     * Generate personality profile for a student
     */
    public function generateProfile(int $profileId, array $studentData): array
    {
        $rules = $this->ruleModel->getActiveRules($profileId);

        $profileParts = [];
        $factors = $this->analyzeFactors($studentData);

        // Generate each part of the profile
        foreach ($rules as $rule) {
            $condition = json_decode($rule['condition'], true);
            $template = $rule['template'];

            if ($this->matchesCondition($factors, $condition)) {
                $profileParts[] = $this->fillTemplate($template, $factors, $studentData);
            }
        }

        // Combine parts into a coherent profile
        $fullProfile = $this->combineProfile($profileParts);

        return [
            'success' => true,
            'data' => [
                'profile' => $fullProfile,
                'parts' => $profileParts,
                'factors' => $factors
            ]
        ];
    }

    /**
     * Analyze student factors
     */
    private function analyzeFactors(array $studentData): array
    {
        $factors = [];

        // Academic performance
        $grades = $studentData['grades'] ?? [];
        $subjects = $studentData['subjects'] ?? [];

        // Calculate averages
        $totalScore = 0;
        $subjectCount = count($grades);
        foreach ($grades as $subjectId => $score) {
            $totalScore += $score;
        }
        $averageScore = $subjectCount > 0 ? $totalScore / $subjectCount : 0;

        // Find strengths and weaknesses
        $strengths = [];
        $weaknesses = [];
        foreach ($grades as $subjectId => $score) {
            $subjectName = $subjects[$subjectId] ?? "Subject $subjectId";
            if ($score >= 70) {
                $strengths[] = ['name' => $subjectName, 'score' => $score];
            } elseif ($score < 50) {
                $weaknesses[] = ['name' => $subjectName, 'score' => $score];
            }
        }

        // Sort strengths and weaknesses
        usort($strengths, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        usort($weaknesses, function ($a, $b) {
            return $a['score'] <=> $b['score'];
        });

        $factors = [
            'average_score' => $averageScore,
            'top_subject' => $strengths[0]['name'] ?? null,
            'bottom_subject' => $weaknesses[0]['name'] ?? null,
            'strength_count' => count($strengths),
            'weakness_count' => count($weaknesses),
            'performance_level' => $this->getPerformanceLevel($averageScore),
            'attendance' => $studentData['attendance'] ?? 0,
            'consistency' => $studentData['consistency'] ?? 0,
            'improvement_trend' => $studentData['improvement_trend'] ?? 'stable',
            'strengths' => $strengths,
            'weaknesses' => $weaknesses
        ];

        return $factors;
    }

    /**
     * Get performance level
     */
    private function getPerformanceLevel(float $score): string
    {
        if ($score >= 80) return 'excellent';
        if ($score >= 70) return 'very_good';
        if ($score >= 60) return 'good';
        if ($score >= 50) return 'satisfactory';
        return 'needs_improvement';
    }

    /**
     * Check if factors match condition
     */
    private function matchesCondition(array $factors, array $condition): bool
    {
        foreach ($condition as $key => $value) {
            if (!isset($factors[$key])) {
                return false;
            }

            if (is_array($value)) {
                if (isset($value['min']) && $factors[$key] < $value['min']) {
                    return false;
                }
                if (isset($value['max']) && $factors[$key] > $value['max']) {
                    return false;
                }
            } elseif ($value !== $factors[$key]) {
                return false;
            }
        }
        return true;
    }

    /**
     * Fill template with data
     */
    private function fillTemplate(string $template, array $factors, array $studentData): string
    {
        $placeholders = [
            '{name}' => $studentData['name'] ?? 'Student',
            '{gender}' => $studentData['gender'] ?? 'they',
            '{top_subject}' => $factors['top_subject'] ?? 'academics',
            '{bottom_subject}' => $factors['bottom_subject'] ?? 'some subjects',
            '{average_score}' => round($factors['average_score'], 1),
            '{performance_level}' => ucfirst(str_replace('_', ' ', $factors['performance_level'])),
            '{strength_count}' => $factors['strength_count'],
            '{weakness_count}' => $factors['weakness_count']
        ];

        foreach ($placeholders as $key => $value) {
            $template = str_replace($key, $value, $template);
        }

        return $template;
    }

    /**
     * Combine profile parts
     */
    private function combineProfile(array $parts): string
    {
        if (empty($parts)) {
            return 'Student profile generation is pending.';
        }

        // Ensure proper capitalization and punctuation
        $fullProfile = '';
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;

            // Capitalize first letter
            $part = ucfirst($part);

            // Ensure ends with period
            if (substr($part, -1) !== '.') {
                $part .= '.';
            }

            $fullProfile .= ' ' . $part;
        }

        return trim($fullProfile);
    }

    /**
     * Preview personality profile
     */
    public function previewProfile(int $profileId, array $sampleData): array
    {
        return $this->generateProfile($profileId, $sampleData);
    }

    /**
     * Batch generate personality profiles
     */
    public function batchGenerate(int $profileId, array $students): array
    {
        $results = [];
        foreach ($students as $student) {
            $results[] = $this->generateProfile($profileId, $student);
        }
        return $results;
    }
}
