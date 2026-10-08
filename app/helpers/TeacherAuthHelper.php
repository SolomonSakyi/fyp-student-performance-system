<?php
/**
 * Teacher Authentication & Authorization Helper
 * Handles teacher login, session management, and permission checking
 */

class TeacherAuthHelper
{
    private static $db;
    
    /**
     * Start session if not already started
     */
    public static function startSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * Authenticate teacher with username and password
     */
    public static function login($username, $password)
    {
        self::startSession();
        
        $db = DatabaseHelper::getInstance();
        
        // Find teacher by username (staff_number or email)
        $teacher = $db->fetchOne("
            SELECT 
                st.id,
                st.staff_number,
                st.is_teaching_staff,
                st.is_active as staff_active,
                p.first_name,
                p.last_name,
                p.primary_email,
                p.primary_phone
            FROM staff st
            JOIN people p ON st.person_id = p.id
            WHERE (p.primary_email = ? OR st.staff_number = ?)
            AND st.school_id = 1
            AND st.is_active = 1
            AND st.is_teaching_staff = 1
        ", [$username, $username]);
        
        if (!$teacher) {
            return false;
        }
        
        // For now, accept any password (will implement proper hashing later)
        // In production: password_verify($password, $teacher['password_hash'])
        
        // Set session variables
        $_SESSION['teacher_logged_in'] = true;
        $_SESSION['teacher_id'] = $teacher['id'];
        $_SESSION['teacher_name'] = $teacher['first_name'] . ' ' . $teacher['last_name'];
        $_SESSION['teacher_staff_number'] = $teacher['staff_number'];
        $_SESSION['teacher_email'] = $teacher['primary_email'];
        $_SESSION['teacher_is_teaching'] = $teacher['is_teaching_staff'];
        
        // Load teacher's permissions
        self::loadTeacherPermissions($teacher['id']);
        
        return true;
    }
    
    /**
     * Load teacher's permissions into session
     */
    private static function loadTeacherPermissions($staffId)
    {
        $db = DatabaseHelper::getInstance();
        
        // Get current academic year and term
        $currentYear = $db->fetchOne("SELECT id FROM academic_years WHERE is_current = 1 AND school_id = 1");
        $currentTerm = $db->fetchOne("SELECT id FROM academic_terms WHERE is_current = 1 AND school_id = 1");
        
        $yearId = $currentYear['id'] ?? 1;
        $termId = $currentTerm['id'] ?? 1;
        
        // 1. Get classes where teacher is Class Teacher
        $classTeacherClasses = $db->fetchAll("
            SELECT 
                cta.class_section_id,
                cs.section_name,
                gl.level_name
            FROM class_teacher_assignments cta
            JOIN class_sections cs ON cta.class_section_id = cs.id
            JOIN grade_levels gl ON cs.grade_level_id = gl.id
            WHERE cta.staff_id = ?
            AND cta.academic_year_id = ?
            AND cta.is_active = 1
        ", [$staffId, $yearId]);
        
        $_SESSION['teacher_class_teacher_classes'] = $classTeacherClasses;
        
        // 2. Get subjects where teacher is Subject Teacher
        $subjectTeacherSubjects = $db->fetchAll("
            SELECT 
                sta.class_section_id,
                sta.subject_id,
                s.subject_name,
                cs.section_name,
                gl.level_name
            FROM subject_teacher_assignments sta
            JOIN class_sections cs ON sta.class_section_id = cs.id
            JOIN grade_levels gl ON cs.grade_level_id = gl.id
            JOIN subjects s ON sta.subject_id = s.id
            WHERE sta.staff_id = ?
            AND sta.academic_year_id = ?
            AND sta.academic_term_id = ?
            AND sta.is_active = 1
        ", [$staffId, $yearId, $termId]);
        
        $_SESSION['teacher_subject_teacher_subjects'] = $subjectTeacherSubjects;
        
        // 3. Get all classes where teacher has any role
        $allClasses = [];
        foreach ($classTeacherClasses as $class) {
            $allClasses[$class['class_section_id']] = [
                'class_id' => $class['class_section_id'],
                'section_name' => $class['section_name'],
                'level_name' => $class['level_name'],
                'role' => 'class_teacher'
            ];
        }
        
        foreach ($subjectTeacherSubjects as $subject) {
            $classId = $subject['class_section_id'];
            if (!isset($allClasses[$classId])) {
                $allClasses[$classId] = [
                    'class_id' => $classId,
                    'section_name' => $subject['section_name'],
                    'level_name' => $subject['level_name'],
                    'role' => 'subject_teacher'
                ];
            }
            $allClasses[$classId]['subjects'][] = [
                'subject_id' => $subject['subject_id'],
                'subject_name' => $subject['subject_name']
            ];
        }
        
        $_SESSION['teacher_classes'] = $allClasses;
    }
    
    /**
     * Check if teacher is logged in
     */
    public static function isLoggedIn()
    {
        self::startSession();
        return isset($_SESSION['teacher_logged_in']) && $_SESSION['teacher_logged_in'] === true;
    }
    
    /**
     * Require login - redirect if not logged in
     */
    public static function requireLogin()
    {
        self::startSession();
        if (!self::isLoggedIn()) {
            header('Location: /teacher/login.php');
            exit;
        }
    }
    
    /**
     * Logout teacher
     */
    public static function logout()
    {
        self::startSession();
        session_destroy();
        header('Location: /teacher/login.php');
        exit;
    }
    
    /**
     * Get current teacher ID
     */
    public static function getTeacherId()
    {
        self::startSession();
        return $_SESSION['teacher_id'] ?? null;
    }
    
    /**
     * Get current teacher name
     */
    public static function getTeacherName()
    {
        self::startSession();
        return $_SESSION['teacher_name'] ?? 'Teacher';
    }
    
    /**
     * Check if teacher is a Class Teacher for a specific class
     */
    public static function isClassTeacher($classSectionId)
    {
        self::startSession();
        if (!isset($_SESSION['teacher_class_teacher_classes'])) {
            return false;
        }
        foreach ($_SESSION['teacher_class_teacher_classes'] as $class) {
            if ($class['class_section_id'] == $classSectionId) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check if teacher is a Subject Teacher for a specific class and subject
     */
    public static function isSubjectTeacher($classSectionId, $subjectId)
    {
        self::startSession();
        if (!isset($_SESSION['teacher_subject_teacher_subjects'])) {
            return false;
        }
        foreach ($_SESSION['teacher_subject_teacher_subjects'] as $assignment) {
            if ($assignment['class_section_id'] == $classSectionId && 
                $assignment['subject_id'] == $subjectId) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get all classes this teacher has access to
     */
    public static function getTeacherClasses()
    {
        self::startSession();
        return $_SESSION['teacher_classes'] ?? [];
    }
    
    /**
     * Get all subjects this teacher teaches in a specific class
     */
    public static function getTeacherSubjectsForClass($classSectionId)
    {
        self::startSession();
        $classes = $_SESSION['teacher_classes'] ?? [];
        foreach ($classes as $class) {
            if ($class['class_id'] == $classSectionId) {
                return $class['subjects'] ?? [];
            }
        }
        return [];
    }
    
    /**
     * Check if assessment is locked (published)
     */
    public static function isAssessmentLocked($assessmentId)
    {
        $db = DatabaseHelper::getInstance();
        $result = $db->fetchOne("SELECT is_published FROM assessments WHERE id = ?", [$assessmentId]);
        return $result && $result['is_published'] == 1;
    }
    
    /**
     * Get students in a class (only for Class Teachers)
     */
    public static function getClassStudents($classSectionId, $academicYearId, $academicTermId)
    {
        if (!self::isClassTeacher($classSectionId)) {
            return null; // Access denied
        }
        
        $db = DatabaseHelper::getInstance();
        return $db->fetchAll("
            SELECT s.id, s.admission_number, s.first_name, s.last_name, s.gender
            FROM students s
            JOIN student_enrollments se ON s.id = se.student_id
            WHERE se.class_section_id = ? 
            AND se.academic_year_id = ? 
            AND se.academic_term_id = ?
            AND se.is_active = 1
            AND s.is_active = 1
            ORDER BY s.first_name, s.last_name
        ", [$classSectionId, $academicYearId, $academicTermId]);
    }
}
?>