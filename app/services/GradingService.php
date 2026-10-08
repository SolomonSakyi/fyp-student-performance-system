<?php

/**
 * Grading Service
 * Handles grade assignment based on grading schemes
 */

class GradingService
{
    private $db;
    private $logger;

    public function __construct($db, $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Assign grade based on average score and class level
     */
    public function assignGrade($average, $classSectionId)
    {
        // Get grade level for this class section
        $gradeLevelId = $this->getGradeLevelId($classSectionId);

        if (!$gradeLevelId) {
            $this->logger->warning('No grade level found for class section ID: ' . $classSectionId);
            return $this->getDefaultGrade();
        }

        // Get grading scheme for this grade level
        $schemeId = $this->getGradingSchemeId($gradeLevelId);

        if (!$schemeId) {
            $this->logger->warning('No grading scheme found for grade level ID: ' . $gradeLevelId);
            return $this->getDefaultGrade();
        }

        // Find grade from grading scheme details
        $grade = $this->findGrade($schemeId, $average);

        return $grade;
    }

    /**
     * Get grade level ID from class section
     */
    private function getGradeLevelId($classSectionId)
    {
        $sql = "
            SELECT grade_level_id 
            FROM class_sections 
            WHERE id = ?
        ";
        $result = $this->db->fetchOne($sql, [$classSectionId]);
        return $result['grade_level_id'] ?? null;
    }

    /**
     * Get grading scheme ID for a grade level
     */
    private function getGradingSchemeId($gradeLevelId)
    {
        $sql = "
            SELECT gs.id
            FROM grading_schemes gs
            JOIN grade_levels gl ON gs.section = gl.section
            WHERE gl.id = ?
              AND gs.is_active = 1
              AND gs.section = gl.section
            LIMIT 1
        ";
        $result = $this->db->fetchOne($sql, [$gradeLevelId]);

        // If no specific scheme found, get default scheme
        if (!$result) {
            $sql = "SELECT id FROM grading_schemes WHERE is_default = 1 AND is_active = 1 LIMIT 1";
            $result = $this->db->fetchOne($sql);
        }

        return $result['id'] ?? null;
    }

    /**
     * Find grade from grading scheme
     */
    private function findGrade($schemeId, $average)
    {
        $sql = "
            SELECT 
                grade_name,
                grade_code,
                grade_value,
                is_pass
            FROM grading_scheme_details
            WHERE grading_scheme_id = ?
              AND ? BETWEEN minimum_percentage AND maximum_percentage
              AND is_active = 1
            LIMIT 1
        ";
        $result = $this->db->fetchOne($sql, [$schemeId, $average]);

        if ($result) {
            return [
                'grade_name' => $result['grade_name'],
                'grade_code' => $result['grade_code'],
                'grade_value' => $result['grade_value'],
                'is_pass' => $result['is_pass']
            ];
        }

        return $this->getDefaultGrade();
    }

    /**
     * Get default grade
     */
    private function getDefaultGrade()
    {
        return [
            'grade_name' => 'Not Graded',
            'grade_code' => 'NG',
            'grade_value' => null,
            'is_pass' => false
        ];
    }
}