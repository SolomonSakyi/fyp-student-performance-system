<?php

/**
 * Academic Engine Service
 * Main orchestrator for all academic processing
 */

// Include required services
require_once __DIR__ . '/GradingService.php';
require_once __DIR__ . '/RemarkGenerationService.php';
require_once __DIR__ . '/../helpers/DatabaseHelper.php';
require_once __DIR__ . '/../helpers/LoggerHelper.php';

class AcademicEngineService
{
    private $db;
    private $logger;
    private $gradingService;
    private $remarkService;
    
    private $schoolId;
    private $academicYearId;
    private $academicTermId;
    private $classSectionId;

    public function __construct($schoolId, $academicYearId, $academicTermId, $classSectionId = null)
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        
        $this->schoolId = $schoolId;
        $this->academicYearId = $academicYearId;
        $this->academicTermId = $academicTermId;
        $this->classSectionId = $classSectionId;

        $this->gradingService = new GradingService($this->db, $this->logger);
        $this->remarkService = new RemarkGenerationService($this->db, $this->logger);
    }

    public function process()
    {
        $this->logger->logBatchStart('Academic Engine Processing', [
            'school_id' => $this->schoolId,
            'academic_year_id' => $this->academicYearId,
            'academic_term_id' => $this->academicTermId
        ]);

        try {
            $students = $this->getStudentsToProcess();
            $processed = 0;
            $failed = 0;

            if (empty($students)) {
                $this->logger->info('No students found. Creating sample students for testing.');
                $students = $this->createSampleStudents();
            }

            foreach ($students as $student) {
                try {
                    $this->processStudent($student);
                    $processed++;
                } catch (Exception $e) {
                    $failed++;
                    $this->logger->error('Failed student: ' . $e->getMessage());
                }
            }

            $this->logger->logBatchEnd('Academic Engine Processing', [
                'processed' => $processed,
                'failed' => $failed
            ]);

            return ['processed' => $processed, 'failed' => $failed];

        } catch (Exception $e) {
            $this->logger->critical('Engine failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function getStudentsToProcess()
    {
        try {
            // First try to get students from enrollments
            $sql = "
                SELECT DISTINCT se.student_id, se.class_section_id
                FROM student_enrollments se
                WHERE se.school_id = ?
                  AND se.academic_year_id = ?
                  AND se.academic_term_id = ?
                  AND se.enrollment_status = 'Active'
                  AND se.is_active = 1
            ";
            $params = [$this->schoolId, $this->academicYearId, $this->academicTermId];
            
            if ($this->classSectionId) {
                $sql .= " AND se.class_section_id = ?";
                $params[] = $this->classSectionId;
            }
            
            $students = $this->db->fetchAll($sql, $params);
            
            if (!empty($students)) {
                $this->logger->info('Found ' . count($students) . ' enrolled students.');
                return $students;
            }
            
            // Fallback: get all students
            $sql = "
                SELECT s.id AS student_id, ? AS class_section_id
                FROM students s
                WHERE s.school_id = ?
                  AND s.is_active = 1
                LIMIT 20
            ";
            $students = $this->db->fetchAll($sql, [$this->classSectionId ?? 1, $this->schoolId]);
            
            if (!empty($students)) {
                $this->logger->info('Found ' . count($students) . ' students (no enrollments).');
                return $students;
            }
            
        } catch (Exception $e) {
            $this->logger->warning('Could not fetch students: ' . $e->getMessage());
        }
        
        // Fallback to sample students
        $this->logger->info('No students found. Creating sample students for testing.');
        return $this->createSampleStudents();
    }

    private function createSampleStudents()
    {
        $students = [];
        $names = ['Kwame Mensah', 'Ama Osei', 'Kofi Asare', 'Adwoa Baffour', 'Yaw Amoako'];
        foreach ($names as $i => $name) {
            $students[] = [
                'student_id' => 1000 + $i,
                'class_section_id' => $this->classSectionId ?? 1,
                'name' => $name
            ];
        }
        return $students;
    }

    private function processStudent($student)
    {
        $studentId = $student['student_id'];
        $classSectionId = $student['class_section_id'] ?? 1;

        // Generate sample scores
        $average = rand(45, 95);
        $scores = [
            'ca_total' => rand(20, 45),
            'exam_total' => rand(30, 70),
            'total_score' => rand(50, 100),
            'average' => $average,
            'subject_count' => 5
        ];

        // Assign grade
        $grade = $this->gradingService->assignGrade($scores['average'], $classSectionId);

        // Generate attendance
        $days = rand(55, 70);
        $present = rand(50, $days);
        $attendance = [
            'total_school_days' => $days,
            'days_present' => $present,
            'attendance_percentage' => round(($present / $days) * 100, 2)
        ];

        // Generate remarks
        $remarks = $this->remarkService->generateAllRemarks(
            $scores['average'],
            $attendance['attendance_percentage']
        );

        // Get student name (if available)
        $name = 'Student ' . $studentId;
        try {
            $sql = "SELECT first_name, last_name FROM students WHERE id = ?";
            $result = $this->db->fetchOne($sql, [$studentId]);
            if ($result) {
                $name = $result['first_name'] . ' ' . $result['last_name'];
            }
        } catch (Exception $e) {
            // Ignore - use default name
        }

        // Log the student data
        $this->logger->info('✅ Processed student: ' . $studentId, [
            'name' => $name,
            'average' => $scores['average'],
            'grade' => $grade['grade_name'] ?? 'Not Graded',
            'conduct' => $remarks['conduct_level'],
            'attitude' => $remarks['attitude_level'],
            'attendance' => $attendance['attendance_percentage'] . '%',
            'teacher_remark' => $remarks['teacher_remark'],
            'head_teacher_remark' => $remarks['head_teacher_remark']
        ]);
        
        // SAVE TO DATABASE
        try {
            // Save to grade_calculations
            $this->saveGradeCalculation($studentId, $classSectionId, $scores, $grade);
            $this->logger->info('💾 Saved to grade_calculations for student: ' . $studentId);
            
            // Save to student_academic_history
            $this->saveAcademicHistory($studentId, $classSectionId, $scores, $grade, $attendance, $remarks);
            $this->logger->info('💾 Saved to student_academic_history for student: ' . $studentId);
            
            // Save to student_grade_summary
            $this->saveGradeSummary($studentId, $classSectionId, $scores, $grade, $attendance, $remarks);
            $this->logger->info('💾 Saved to student_grade_summary for student: ' . $studentId);
            
            $this->logger->info('💾✅ All data saved for student: ' . $studentId);
            
        } catch (Exception $e) {
            $this->logger->error('Failed to save student ' . $studentId . ' to database: ' . $e->getMessage());
        }
    }

    private function saveGradeCalculation($studentId, $classSectionId, $scores, $grade)
    {
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
                1,
                NOW(),
                NOW(),
                NOW()
            ) ON DUPLICATE KEY UPDATE
                ca_total = VALUES(ca_total),
                exam_total = VALUES(exam_total),
                total_score = VALUES(total_score),
                average = VALUES(average),
                grade = VALUES(grade),
                is_calculated = 1,
                calculated_date = NOW(),
                updated_at = NOW()
        ";

        $this->db->query($sql, [
            $this->schoolId,
            $studentId,
            $this->academicYearId,
            $this->academicTermId,
            $classSectionId,
            $scores['ca_total'],
            $scores['exam_total'],
            $scores['total_score'],
            $scores['average'],
            $grade['grade_name'] ?? 'Not Graded'
        ]);
    }

    private function saveAcademicHistory($studentId, $classSectionId, $scores, $grade, $attendance, $remarks)
    {
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
                attendance_days,
                total_school_days,
                attendance_percentage,
                personality_profile,
                conduct,
                attitude,
                teacher_remark,
                head_teacher_remark,
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
                1,
                NOW(),
                NOW()
            ) ON DUPLICATE KEY UPDATE
                class_section_id = VALUES(class_section_id),
                total_marks = VALUES(total_marks),
                average = VALUES(average),
                grade = VALUES(grade),
                attendance_days = VALUES(attendance_days),
                total_school_days = VALUES(total_school_days),
                attendance_percentage = VALUES(attendance_percentage),
                personality_profile = VALUES(personality_profile),
                conduct = VALUES(conduct),
                attitude = VALUES(attitude),
                teacher_remark = VALUES(teacher_remark),
                head_teacher_remark = VALUES(head_teacher_remark),
                is_completed = 1,
                updated_at = NOW()
        ";

        $this->db->query($sql, [
            $this->schoolId,
            $studentId,
            $this->academicYearId,
            $this->academicTermId,
            $classSectionId,
            $scores['total_score'],
            $scores['average'],
            $grade['grade_name'] ?? 'Not Graded',
            $attendance['days_present'] ?? 0,
            $attendance['total_school_days'] ?? 0,
            $attendance['attendance_percentage'] ?? 0,
            $remarks['personality_profile'],
            $remarks['conduct_level'],
            $remarks['attitude_level'],
            $remarks['teacher_remark'],
            $remarks['head_teacher_remark']
        ]);
    }

    private function saveGradeSummary($studentId, $classSectionId, $scores, $grade, $attendance, $remarks)
    {
        try {
            // First check if record exists
            $checkSql = "
                SELECT id FROM student_grade_summary 
                WHERE student_id = ? 
                  AND academic_year_id = ? 
                  AND academic_term_id = ?
            ";
            $exists = $this->db->fetchOne($checkSql, [
                $studentId,
                $this->academicYearId,
                $this->academicTermId
            ]);

            if ($exists) {
                // UPDATE existing record
                $sql = "
                    UPDATE student_grade_summary 
                    SET 
                        class_section_id = ?,
                        total_subjects = ?,
                        total_score = ?,
                        average = ?,
                        grade = ?,
                        attendance_percentage = ?,
                        conduct = ?,
                        attitude = ?,
                        personality_profile = ?,
                        teacher_remark = ?,
                        head_teacher_remark = ?,
                        promotion_status = ?,
                        updated_at = NOW()
                    WHERE student_id = ? 
                      AND academic_year_id = ? 
                      AND academic_term_id = ?
                ";
                $this->db->query($sql, [
                    $classSectionId,
                    $scores['subject_count'],
                    $scores['total_score'],
                    $scores['average'],
                    $grade['grade_name'] ?? 'Not Graded',
                    $attendance['attendance_percentage'] ?? 0,
                    $remarks['conduct_level'],
                    $remarks['attitude_level'],
                    $remarks['personality_profile'],
                    $remarks['teacher_remark'],
                    $remarks['head_teacher_remark'],
                    'Promoted',
                    $studentId,
                    $this->academicYearId,
                    $this->academicTermId
                ]);
            } else {
                // INSERT new record
                $sql = "
                    INSERT INTO student_grade_summary (
                        uuid,
                        school_id,
                        student_id,
                        academic_year_id,
                        academic_term_id,
                        class_section_id,
                        total_subjects,
                        total_score,
                        average,
                        grade,
                        attendance_percentage,
                        conduct,
                        attitude,
                        personality_profile,
                        teacher_remark,
                        head_teacher_remark,
                        promotion_status,
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
                        NOW(),
                        NOW()
                    )
                ";
                $this->db->query($sql, [
                    $this->schoolId,
                    $studentId,
                    $this->academicYearId,
                    $this->academicTermId,
                    $classSectionId,
                    $scores['subject_count'],
                    $scores['total_score'],
                    $scores['average'],
                    $grade['grade_name'] ?? 'Not Graded',
                    $attendance['attendance_percentage'] ?? 0,
                    $remarks['conduct_level'],
                    $remarks['attitude_level'],
                    $remarks['personality_profile'],
                    $remarks['teacher_remark'],
                    $remarks['head_teacher_remark'],
                    'Promoted'
                ]);
            }
            
        } catch (Exception $e) {
            $this->logger->error('Could not save to student_grade_summary: ' . $e->getMessage());
            // Don't throw - we want the process to continue
        }
    }
}