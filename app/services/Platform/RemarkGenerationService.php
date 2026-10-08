<?php

/**
 * RemarkGenerationService.php
 * Remark Generation Service
 * 
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/RemarkRule.php';

class RemarkGenerationService
{
    private $remarkRuleModel;

    public function __construct()
    {
        $this->remarkRuleModel = new RemarkRule();
    }

    /**
     * Generate remark for a student
     */
    public function generateRemark(int $profileId, float $score, array $context = []): string
    {
        $remark = $this->remarkRuleModel->getRemarkByScore($profileId, $score);

        if (!$remark) {
            return 'No remark available.';
        }

        // Replace placeholders with context
        $placeholders = [
            '{name}' => $context['name'] ?? 'Student',
            '{subject}' => $context['subject'] ?? '',
            '{grade}' => $context['grade'] ?? '',
            '{score}' => $score,
            '{threshold}' => $context['threshold'] ?? ''
        ];

        foreach ($placeholders as $key => $value) {
            $remark = str_replace($key, $value, $remark);
        }

        return $remark;
    }

    /**
     * Generate subject-specific remark
     */
    public function generateSubjectRemark(int $profileId, string $subjectName, float $score, float $classAverage): string
    {
        $remark = $this->remarkRuleModel->getRemarkByScore($profileId, $score);

        if (!$remark) {
            return "No remark available for {$subjectName}.";
        }

        $context = [
            '{subject}' => $subjectName,
            '{score}' => $score,
            '{average}' => $classAverage,
            '{difference}' => $score - $classAverage
        ];

        foreach ($context as $key => $value) {
            $remark = str_replace($key, $value, $remark);
        }

        return $remark;
    }

    /**
     * Generate overall performance remark
     */
    public function generateOverallRemark(int $profileId, float $average, int $position, int $totalStudents): string
    {
        $remark = $this->remarkRuleModel->getRemarkByScore($profileId, $average);

        if (!$remark) {
            return 'No overall remark available.';
        }

        $context = [
            '{position}' => $position,
            '{total}' => $totalStudents,
            '{percentage}' => round(($position / $totalStudents) * 100, 1)
        ];

        foreach ($context as $key => $value) {
            $remark = str_replace($key, $value, $remark);
        }

        return $remark;
    }

    /**
     * Get all remarks for a score range
     */
    public function getRemarksForRange(int $profileId, float $minScore, float $maxScore): array
    {
        return $this->remarkRuleModel->getByScoreRange($profileId, $minScore, $maxScore);
    }
}
