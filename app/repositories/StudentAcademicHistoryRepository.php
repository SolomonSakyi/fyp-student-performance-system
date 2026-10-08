<?php

/**
 * Student Academic History Repository
 * Handles database operations for student_academic_history table
 */

class StudentAcademicHistoryRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Get academic history by student and term
     */
    public function findByStudentAndTerm($studentId, $academicYearId, $academicTermId)
    {
        $sql = "
            SELECT * FROM student_academic_history 
            WHERE student_id = ? 
              AND academic_year_id = ? 
              AND academic_term_id = ?
        ";
        return $this->db->fetchOne($sql, [$studentId, $academicYearId, $academicTermId]);
    }

    /**
     * Get academic history for a class
     */
    public function findByClassSection($classSectionId, $academicYearId, $academicTermId)
    {
        $sql = "
            SELECT * FROM student_academic_history 
            WHERE class_section_id = ? 
              AND academic_year_id = ? 
              AND academic_term_id = ?
              AND is_completed = 1
        ";
        return $this->db->fetchAll($sql, [$classSectionId, $academicYearId, $academicTermId]);
    }

    /**
     * Save academic history
     */
    public function save($data)
    {
        // Check if record exists
        $sql = "
            SELECT id FROM student_academic_history 
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
                UPDATE student_academic_history 
                SET 
                    class_section_id = ?,
                    total_marks = ?,
                    average = ?,
                    grade = ?,
                    class_position = ?,
                    class_size = ?,
                    attendance_days = ?,
                    total_school_days = ?,
                    attendance_percentage = ?,
                    personality_profile = ?,
                    conduct = ?,
                    attitude = ?,
                    teacher_remark = ?,
                    head_teacher_remark = ?,
                    promotion_status = ?,
                    next_class_section_id = ?,
                    is_completed = ?,
                    updated_at = NOW()
                WHERE id = ?
            ";
            $this->db->query($sql, [
                $data['class_section_id'],
                $data['total_marks'] ?? null,
                $data['average'] ?? null,
                $data['grade'] ?? null,
                $data['class_position'] ?? null,
                $data['class_size'] ?? null,
                $data['attendance_days'] ?? 0,
                $data['total_school_days'] ?? 0,
                $data['attendance_percentage'] ?? 0,
                $data['personality_profile'] ?? null,
                $data['conduct'] ?? null,
                $data['attitude'] ?? null,
                $data['teacher_remark'] ?? null,
                $data['head_teacher_remark'] ?? null,
                $data['promotion_status'] ?? null,
                $data['next_class_section_id'] ?? null,
                $data['is_completed'] ?? 1,
                $exists['id']
            ]);
            return $exists['id'];
        } else {
            // Insert new record
            $sql = "
                INSERT INTO student_academic_history (
                    uuid,
                    school_id,
                    student_id,
                    academic_year_id,
                    academic_term_id,
                    class_section_id,
                    total_marks,
                    average,
                    grade,
                    class_position,
                    class_size,
                    attendance_days,
                    total_school_days,
                    attendance_percentage,
                    personality_profile,
                    conduct,
                    attitude,
                    teacher_remark,
                    head_teacher_remark,
                    promotion_status,
                    next_class_section_id,
                    is_completed,
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
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
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
                $data['total_marks'] ?? null,
                $data['average'] ?? null,
                $data['grade'] ?? null,
                $data['class_position'] ?? null,
                $data['class_size'] ?? null,
                $data['attendance_days'] ?? 0,
                $data['total_school_days'] ?? 0,
                $data['attendance_percentage'] ?? 0,
                $data['personality_profile'] ?? null,
                $data['conduct'] ?? null,
                $data['attitude'] ?? null,
                $data['teacher_remark'] ?? null,
                $data['head_teacher_remark'] ?? null,
                $data['promotion_status'] ?? null,
                $data['next_class_section_id'] ?? null,
                $data['is_completed'] ?? 1
            ]);
            return $this->db->lastInsertId();
        }
    }
}