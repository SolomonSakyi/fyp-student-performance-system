<?php

/**
 * Promotion Service
 * Handles student promotion/demotion logic
 */

class PromotionService
{
    private $db;
    private $logger;

    public function __construct($db, $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Determine promotion status for a student
     */
    public function determinePromotion($studentId, $classSectionId, $average)
    {
        // Get current grade level from class section
        $gradeLevelId = $this->getGradeLevelId($classSectionId);
        
        if (!$gradeLevelId) {
            return $this->getDefaultPromotionResult();
        }

        // Get promotion rules for this grade level
        $promotionRule = $this->getPromotionRule($gradeLevelId);

        if (!$promotionRule) {
            return $this->getDefaultPromotionResult();
        }

        // Determine if student meets promotion threshold
        $threshold = $promotionRule['promotion_threshold'];
        $nextGradeLevelId = $promotionRule['to_grade_level_id'];

        if ($average >= $threshold) {
            // Student is promoted
            $nextClassSectionId = $this->getNextClassSectionId($studentId, $nextGradeLevelId);
            return [
                'promotion_status' => 'Promoted',
                'next_class_section_id' => $nextClassSectionId
            ];
        } else {
            // Student repeats
            return [
                'promotion_status' => 'Repeated',
                'next_class_section_id' => null
            ];
        }
    }

    /**
     * Get grade level ID from class section
     */
    private function getGradeLevelId($classSectionId)
    {
        $sql = "SELECT grade_level_id FROM class_sections WHERE id = ?";
        $result = $this->db->fetchOne($sql, [$classSectionId]);
        return $result['grade_level_id'] ?? null;
    }

    /**
     * Get promotion rule for a grade level
     */
    private function getPromotionRule($gradeLevelId)
    {
        $sql = "
            SELECT 
                promotion_threshold,
                to_grade_level_id
            FROM promotion_rules
            WHERE from_grade_level_id = ?
              AND is_active = 1
            LIMIT 1
        ";
        return $this->db->fetchOne($sql, [$gradeLevelId]);
    }

    /**
     * Get next class section ID for a student
     */
    private function getNextClassSectionId($studentId, $nextGradeLevelId)
    {
        // Find a class section for the next grade level
        $sql = "
            SELECT cs.id
            FROM class_sections cs
            JOIN student_enrollments se ON se.academic_year_id = cs.academic_year_id
            WHERE cs.grade_level_id = ?
              AND se.student_id = ?
              AND se.is_active = 1
            LIMIT 1
        ";
        $result = $this->db->fetchOne($sql, [$nextGradeLevelId, $studentId]);
        return $result['id'] ?? null;
    }

    /**
     * Get default promotion result
     */
    private function getDefaultPromotionResult()
    {
        return [
            'promotion_status' => null,
            'next_class_section_id' => null
        ];
    }
}
