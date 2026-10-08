<?php

/**
 * Grade Calculation Repository
 * Handles database operations for grade_calculations table
 */

class GradeCalculationRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Get grade calculation by student and term
     */
    public function findByStudentAndTerm($studentId, $academicYearId, $academicTermId)
    {
        $sql = "
            SELECT * FROM grade_calculations 
            WHERE student_id = ? 
              AND academic_year_id = ? 
              AND academic_term_id = ?
        ";
        return $this->db->fetchOne($sql, [$studentId, $academicYearId, $academicTermId]);
    }

    /**
     * Get grade calculations for a class
     */
    public function findByClassSection($classSectionId, $academicYearId, $academicTermId)
    {
        $sql = "
            SELECT * FROM grade_calculations 
            WHERE class_section_id = ? 
              AND academic_year_id = ? 
              AND academic_term_id = ?
              AND is_calculated = 1
            ORDER BY average DESC
        ";
        return $this->db->fetchAll($sql, [$classSectionId, $academicYearId, $academicTermId]);
    }

    /**
     * Save grade calculation
     */
    public function save($data)
    {
        // Check if record exists
        $sql = "
            SELECT id FROM grade_calculations 
            WHERE student_id = ? 
              AND academic_year_id = ? 
              AND academic_term_id = ?
        ";
        $exists = $this->db->fetchOne($sql, [
            $data['student_id'],
            $data['academic_year_id'],
            $data['academic_term_id']
        ]);

        if ($exists) {
            // Update existing record
            $sql = "
                UPDATE grade_calculations 
                SET 
                    class_section_id = ?,
                    ca_total = ?,
                    exam_total = ?,
                    total_score = ?,
                    average = ?,
                    grade = ?,
                    class_position = ?,
                    class_size = ?,
                    promotion_status = ?,
                    is_calculated = ?,
                    calculated_date = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ";
            $this->db->query($sql, [
                $data['class_section_id'],
                $data['ca_total'] ?? 0,
                $data['exam_total'] ?? 0,
                $data['total_score'] ?? 0,
                $data['average'] ?? 0,
                $data['grade'] ?? null,
                $data['class_position'] ?? null,
                $data['class_size'] ?? null,
                $data['promotion_status'] ?? null,
                $data['is_calculated'] ?? 1,
                $exists['id']
            ]);
            return $exists['id'];
        } else {
            // Insert new record
            $sql = "
                INSERT INTO grade_calculations (
                    uuid,
                    school_id,
                    student_id,
                    academic_year_id,
                    academic_term_id,
                    class_section_id,
                    ca_total,
                    exam_total,
                    total_score,
                    average,
                    grade,
                    class_position,
                    class_size,
                    promotion_status,
                    is_calculated,
                    calculated_date,
                    created_at,
                    updated_at
                ) VALUES (
                    UUID(),
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW(),
                    NOW(),
                    NOW()
                )
            ";
            $this->db->query($sql, [
                $data['school_id'],
                $data['student_id'],
                $data['academic_year_id'],
                $data['academic_term_id'],
                $data['class_section_id'],
                $data['ca_total'] ?? 0,
                $data['exam_total'] ?? 0,
                $data['total_score'] ?? 0,
                $data['average'] ?? 0,
                $data['grade'] ?? null,
                $data['class_position'] ?? null,
                $data['class_size'] ?? null,
                $data['promotion_status'] ?? null,
                $data['is_calculated'] ?? 1
            ]);
            return $this->db->lastInsertId();
        }
    }

    /**
     * Update class positions
     */
    public function updateClassPositions($classSectionId, $academicYearId, $academicTermId)
    {
        // Get rankings
        $sql = "
            SELECT 
                student_id,
                average,
                RANK() OVER (ORDER BY average DESC) AS position,
                COUNT(*) OVER () AS class_size
            FROM grade_calculations
            WHERE class_section_id = ?
              AND academic_year_id = ?
              AND academic_term_id = ?
              AND is_calculated = 1
              AND average IS NOT NULL
        ";
        $results = $this->db->fetchAll($sql, [$classSectionId, $academicYearId, $academicTermId]);

        foreach ($results as $row) {
            $updateSql = "
                UPDATE grade_calculations
                SET class_position = ?,
                    class_size = ?
                WHERE student_id = ?
                  AND academic_year_id = ?
                  AND academic_term_id = ?
                  AND class_section_id = ?
            ";
            $this->db->query($updateSql, [
                $row['position'],
                $row['class_size'],
                $row['student_id'],
                $academicYearId,
                $academicTermId,
                $classSectionId
            ]);
        }

        return $results;
    }
}