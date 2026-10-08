<?php

/**
 * Student Score Repository
 * Handles database operations for student_scores table
 */

class StudentScoreRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Get scores for a student by term
     */
    public function getScoresByStudentAndTerm($studentId, $academicYearId, $academicTermId)
    {
        $sql = "
            SELECT 
                ss.*,
                acs.subject_id,
                acs.max_score,
                acs.pass_score,
                a.assessment_type_id,
                at.assessment_category
            FROM student_scores ss
            JOIN assessment_subjects acs ON ss.assessment_subject_id = acs.id
            JOIN assessments a ON acs.assessment_id = a.id
            JOIN assessment_types at ON a.assessment_type_id = at.id
            WHERE ss.student_id = ?
              AND a.academic_year_id = ?
              AND a.academic_term_id = ?
              AND ss.is_active = 1
        ";
        return $this->db->fetchAll($sql, [$studentId, $academicYearId, $academicTermId]);
    }

    /**
     * Calculate total scores for a student
     */
    public function calculateTotals($studentId, $academicYearId, $academicTermId)
    {
        $sql = "
            SELECT 
                SUM(CASE WHEN at.assessment_category = 'ca' THEN ss.score_obtained ELSE 0 END) AS ca_total,
                SUM(CASE WHEN at.assessment_category = 'exam' THEN ss.score_obtained ELSE 0 END) AS exam_total,
                SUM(ss.score_obtained) AS total_score,
                AVG(ss.score_obtained) AS average,
                COUNT(DISTINCT acs.subject_id) AS subject_count
            FROM student_scores ss
            JOIN assessment_subjects acs ON ss.assessment_subject_id = acs.id
            JOIN assessments a ON acs.assessment_id = a.id
            JOIN assessment_types at ON a.assessment_type_id = at.id
            WHERE ss.student_id = ?
              AND a.academic_year_id = ?
              AND a.academic_term_id = ?
              AND ss.is_active = 1
              AND ss.score_obtained IS NOT NULL
        ";
        return $this->db->fetchOne($sql, [$studentId, $academicYearId, $academicTermId]);
    }

    /**
     * Save or update student score
     */
    public function save($data)
    {
        // Check if record exists
        $sql = "
            SELECT id FROM student_scores 
            WHERE assessment_subject_id = ? 
              AND student_id = ?
        ";
        $exists = $this->db->fetchOne($sql, [
            $data['assessment_subject_id'],
            $data['student_id']
        ]);

        if ($exists) {
            // Update existing record
            $sql = "
                UPDATE student_scores 
                SET 
                    score_obtained = ?,
                    percentage_score = ?,
                    grade = ?,
                    is_absent = ?,
                    is_excused = ?,
                    updated_at = NOW()
                WHERE id = ?
            ";
            $this->db->query($sql, [
                $data['score_obtained'] ?? null,
                $data['percentage_score'] ?? null,
                $data['grade'] ?? null,
                $data['is_absent'] ?? 0,
                $data['is_excused'] ?? 0,
                $exists['id']
            ]);
            return $exists['id'];
        } else {
            // Insert new record
            $sql = "
                INSERT INTO student_scores (
                    uuid,
                    school_id,
                    assessment_subject_id,
                    student_id,
                    score_obtained,
                    percentage_score,
                    grade,
                    is_absent,
                    is_excused,
                    entered_by_staff_id,
                    entered_date,
                    is_verified,
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
                    NOW(),
                    0,
                    NOW(),
                    NOW()
                )
            ";
            $this->db->query($sql, [
                $data['school_id'],
                $data['assessment_subject_id'],
                $data['student_id'],
                $data['score_obtained'] ?? null,
                $data['percentage_score'] ?? null,
                $data['grade'] ?? null,
                $data['is_absent'] ?? 0,
                $data['is_excused'] ?? 0,
                $data['entered_by_staff_id'] ?? null
            ]);
            return $this->db->lastInsertId();
        }
    }
}