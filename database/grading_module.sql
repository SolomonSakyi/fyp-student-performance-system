-- ================================================================
-- DATABASE: student_performance_system
-- MODULE: Enterprise Configurable Grading & Aggregate Engine
-- VERSION: 1.0
-- ================================================================

USE student_performance_system;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- 1. GRADING SYSTEMS
-- Master table for grading systems
-- ================================================================
DROP TABLE IF EXISTS grading_systems;
CREATE TABLE grading_systems (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    system_name VARCHAR(100) NOT NULL COMMENT 'e.g., Alphabet, Numeric, WAEC, Custom',
    system_code VARCHAR(20) NOT NULL COMMENT 'ALPHA, NUM, WAEC, CUSTOM',
    system_type ENUM('alphabet','numeric','waec','custom') NOT NULL DEFAULT 'custom',
    description TEXT,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gs_uuid (uuid),
    UNIQUE KEY uq_gs_code (school_id, system_code),
    CONSTRAINT fk_gs_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_gs_school_active (school_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 2. GRADING SCALES
-- Grade scales with boundaries
-- ================================================================
DROP TABLE IF EXISTS grading_scales;
CREATE TABLE grading_scales (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    grading_system_id BIGINT UNSIGNED NOT NULL,
    grade_name VARCHAR(50) NOT NULL COMMENT 'e.g., A, B+, 1, A1, Outstanding',
    grade_code VARCHAR(10) NOT NULL COMMENT 'A, B, 1, A1, O',
    grade_letter VARCHAR(5) DEFAULT NULL,
    min_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    max_score DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    grade_point DECIMAL(3,2) DEFAULT NULL,
    is_passing TINYINT(1) NOT NULL DEFAULT 1,
    is_distinction TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    color VARCHAR(7) DEFAULT '#6c757d',
    description TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gsc_uuid (uuid),
    UNIQUE KEY uq_gsc_system_code (grading_system_id, grade_code),
    CONSTRAINT fk_gsc_system FOREIGN KEY (grading_system_id) REFERENCES grading_systems(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_gsc_system_active (grading_system_id, is_active),
    INDEX idx_gsc_score (min_score, max_score),
    INDEX idx_gsc_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 3. GRADE REMARKS
-- Configurable remarks for grades
-- ================================================================
DROP TABLE IF EXISTS grade_remarks;
CREATE TABLE grade_remarks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    grading_system_id BIGINT UNSIGNED NOT NULL,
    grade_code VARCHAR(10) NOT NULL,
    remark_type ENUM('class_teacher','headteacher','parent','student','general') NOT NULL DEFAULT 'general',
    remark_text TEXT NOT NULL,
    language VARCHAR(10) DEFAULT 'en',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gr_uuid (uuid),
    UNIQUE KEY uq_gr_system_grade_remark (grading_system_id, grade_code, remark_type, language),
    CONSTRAINT fk_gr_system FOREIGN KEY (grading_system_id) REFERENCES grading_systems(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_gr_system_active (grading_system_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 4. GRADE ASSIGNMENTS
-- Assign grading systems to schools, levels, classes, terms
-- ================================================================
DROP TABLE IF EXISTS grade_assignments;
CREATE TABLE grade_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    grading_system_id BIGINT UNSIGNED NOT NULL,
    grade_level_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = applies to all levels',
    class_section_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = applies to all classes',
    academic_term_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = applies to all terms',
    academic_year_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = applies to all years',
    assignment_type ENUM('school','level','class','term','year','default') NOT NULL DEFAULT 'default',
    priority INT NOT NULL DEFAULT 0 COMMENT 'Higher priority overrides lower',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ga_uuid (uuid),
    UNIQUE KEY uq_ga_assignment (school_id, grading_system_id, grade_level_id, class_section_id, academic_term_id, academic_year_id),
    CONSTRAINT fk_ga_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ga_system FOREIGN KEY (grading_system_id) REFERENCES grading_systems(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ga_level FOREIGN KEY (grade_level_id) REFERENCES grade_levels(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_ga_class FOREIGN KEY (class_section_id) REFERENCES class_sections(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_ga_term FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_ga_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_ga_school_active (school_id, is_active),
    INDEX idx_ga_level (grade_level_id),
    INDEX idx_ga_class (class_section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 5. SUBJECT CATEGORIES
-- Configurable subject categories
-- ================================================================
DROP TABLE IF EXISTS subject_categories;
CREATE TABLE subject_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    category_name VARCHAR(50) NOT NULL COMMENT 'Core, Elective, Vocational, etc.',
    category_code VARCHAR(20) NOT NULL,
    category_type ENUM('core','elective','optional','vocational','practical','language','stem','arts','religious','other') NOT NULL DEFAULT 'other',
    is_compulsory TINYINT(1) NOT NULL DEFAULT 0,
    count_in_aggregate TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    description TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sc_uuid (uuid),
    UNIQUE KEY uq_sc_code (school_id, category_code),
    CONSTRAINT fk_sc_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_sc_school_active (school_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 6. SUBJECT CATEGORY ASSIGNMENTS
-- Assign subjects to categories
-- ================================================================
DROP TABLE IF EXISTS subject_category_assignments;
CREATE TABLE subject_category_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    grade_level_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = applies to all levels',
    is_compulsory TINYINT(1) NOT NULL DEFAULT 0,
    count_in_aggregate TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sca_uuid (uuid),
    UNIQUE KEY uq_sca_subject_category_level (subject_id, category_id, grade_level_id),
    CONSTRAINT fk_sca_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sca_category FOREIGN KEY (category_id) REFERENCES subject_categories(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sca_level FOREIGN KEY (grade_level_id) REFERENCES grade_levels(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_sca_subject (subject_id),
    INDEX idx_sca_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 7. AGGREGATE RULES
-- Configurable aggregate calculation rules
-- ================================================================
DROP TABLE IF EXISTS aggregate_rules;
CREATE TABLE aggregate_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    grade_level_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = applies to all levels',
    academic_term_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = applies to all terms',
    rule_name VARCHAR(100) NOT NULL,
    rule_code VARCHAR(20) NOT NULL,
    calculation_type ENUM('raw_score','grade_point','weighted','custom') NOT NULL DEFAULT 'raw_score',
    core_subjects_count INT UNSIGNED DEFAULT 0 COMMENT 'Number of core subjects to count',
    elective_subjects_count INT UNSIGNED DEFAULT 0 COMMENT 'Number of electives to count',
    best_elective_selection ENUM('highest_score','highest_grade','custom') NOT NULL DEFAULT 'highest_score',
    total_subjects_count INT UNSIGNED DEFAULT 0 COMMENT 'Total subjects to count',
    pass_mark DECIMAL(5,2) DEFAULT 50.00,
    distinction_mark DECIMAL(5,2) DEFAULT 80.00,
    aggregate_format VARCHAR(50) DEFAULT '{score}/{total}',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ar_uuid (uuid),
    UNIQUE KEY uq_ar_code (school_id, rule_code),
    CONSTRAINT fk_ar_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ar_level FOREIGN KEY (grade_level_id) REFERENCES grade_levels(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_ar_term FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_ar_school_active (school_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 8. PROMOTION RULES
-- Configurable promotion rules
-- ================================================================
DROP TABLE IF EXISTS promotion_rules;
CREATE TABLE promotion_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    grade_level_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = applies to all levels',
    academic_year_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = applies to all years',
    rule_name VARCHAR(100) NOT NULL,
    rule_code VARCHAR(20) NOT NULL,
    min_average_score DECIMAL(5,2) DEFAULT 50.00,
    min_aggregate_score DECIMAL(5,2) DEFAULT 0,
    min_attendance_percentage DECIMAL(5,2) DEFAULT 75.00,
    min_behaviour_score DECIMAL(5,2) DEFAULT 50.00,
    compulsory_subjects_passed INT UNSIGNED DEFAULT 0 COMMENT 'Number of compulsory subjects to pass',
    total_subjects_passed INT UNSIGNED DEFAULT 0 COMMENT 'Total subjects to pass',
    max_failures_allowed INT UNSIGNED DEFAULT 2,
    promotion_status_passed ENUM('promoted','conditional','probation','repeat','graduated','transferred') NOT NULL DEFAULT 'promoted',
    promotion_status_failed ENUM('repeat','probation','not_promoted','transferred') NOT NULL DEFAULT 'repeat',
    conditional_promotion_conditions TEXT COMMENT 'JSON encoded conditions',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pr_uuid (uuid),
    UNIQUE KEY uq_pr_code (school_id, rule_code),
    CONSTRAINT fk_pr_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pr_level FOREIGN KEY (grade_level_id) REFERENCES grade_levels(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_pr_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_pr_school_active (school_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 9. STUDENT GRADES
-- Calculated grades for students
-- ================================================================
DROP TABLE IF EXISTS student_grades;
CREATE TABLE student_grades (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    academic_term_id BIGINT UNSIGNED NOT NULL,
    class_section_id BIGINT UNSIGNED NOT NULL,
    raw_score DECIMAL(5,2) DEFAULT NULL,
    grade_code VARCHAR(10) DEFAULT NULL,
    grade_point DECIMAL(3,2) DEFAULT NULL,
    is_passing TINYINT(1) NOT NULL DEFAULT 1,
    is_distinction TINYINT(1) NOT NULL DEFAULT 0,
    remarks TEXT,
    is_calculated TINYINT(1) NOT NULL DEFAULT 0,
    calculated_date TIMESTAMP NULL DEFAULT NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    published_date TIMESTAMP NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sg_uuid (uuid),
    UNIQUE KEY uq_sg_student_subject_term (student_id, subject_id, academic_term_id),
    CONSTRAINT fk_sg_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sg_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sg_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sg_term FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sg_class FOREIGN KEY (class_section_id) REFERENCES class_sections(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_sg_student (student_id),
    INDEX idx_sg_subject (subject_id),
    INDEX idx_sg_term (academic_term_id),
    INDEX idx_sg_published (is_published)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 10. STUDENT AGGREGATES
-- Calculated aggregates for students
-- ================================================================
DROP TABLE IF EXISTS student_aggregates;
CREATE TABLE student_aggregates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    academic_term_id BIGINT UNSIGNED NOT NULL,
    class_section_id BIGINT UNSIGNED NOT NULL,
    aggregate_rule_id BIGINT UNSIGNED DEFAULT NULL,
    total_raw_score DECIMAL(8,2) DEFAULT NULL,
    total_grade_points DECIMAL(8,2) DEFAULT NULL,
    average_score DECIMAL(5,2) DEFAULT NULL,
    average_grade_point DECIMAL(5,2) DEFAULT NULL,
    core_subjects_count INT UNSIGNED DEFAULT 0,
    elective_subjects_count INT UNSIGNED DEFAULT 0,
    total_subjects_count INT UNSIGNED DEFAULT 0,
    subjects_passed INT UNSIGNED DEFAULT 0,
    subjects_failed INT UNSIGNED DEFAULT 0,
    class_position INT UNSIGNED DEFAULT NULL,
    class_total INT UNSIGNED DEFAULT NULL,
    aggregate_score VARCHAR(50) DEFAULT NULL,
    aggregate_grade VARCHAR(10) DEFAULT NULL,
    promotion_status VARCHAR(50) DEFAULT NULL,
    is_calculated TINYINT(1) NOT NULL DEFAULT 0,
    calculated_date TIMESTAMP NULL DEFAULT NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    published_date TIMESTAMP NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sa_uuid (uuid),
    UNIQUE KEY uq_sa_student_term (student_id, academic_term_id),
    CONSTRAINT fk_sa_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sa_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sa_term FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sa_class FOREIGN KEY (class_section_id) REFERENCES class_sections(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sa_rule FOREIGN KEY (aggregate_rule_id) REFERENCES aggregate_rules(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_sa_student (student_id),
    INDEX idx_sa_term (academic_term_id),
    INDEX idx_sa_class (class_section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 11. PROMOTION DECISIONS
-- Final promotion decisions
-- ================================================================
DROP TABLE IF EXISTS promotion_decisions;
CREATE TABLE promotion_decisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    from_grade_level_id BIGINT UNSIGNED NOT NULL,
    to_grade_level_id BIGINT UNSIGNED DEFAULT NULL,
    promotion_rule_id BIGINT UNSIGNED DEFAULT NULL,
    aggregate_id BIGINT UNSIGNED DEFAULT NULL,
    decision_type ENUM('promoted','conditional','probation','repeat','graduated','transferred','not_promoted') NOT NULL DEFAULT 'promoted',
    average_score DECIMAL(5,2) DEFAULT NULL,
    attendance_percentage DECIMAL(5,2) DEFAULT NULL,
    behaviour_score DECIMAL(5,2) DEFAULT NULL,
    subjects_passed INT UNSIGNED DEFAULT 0,
    subjects_failed INT UNSIGNED DEFAULT 0,
    total_subjects INT UNSIGNED DEFAULT 0,
    teacher_recommendation TEXT,
    headteacher_approval TINYINT(1) NOT NULL DEFAULT 0,
    headteacher_remarks TEXT,
    approval_date TIMESTAMP NULL DEFAULT NULL,
    certificate_number VARCHAR(50) DEFAULT NULL,
    graduation_date DATE DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pd_uuid (uuid),
    UNIQUE KEY uq_pd_student_year (student_id, academic_year_id),
    CONSTRAINT fk_pd_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pd_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pd_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pd_from_level FOREIGN KEY (from_grade_level_id) REFERENCES grade_levels(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_pd_to_level FOREIGN KEY (to_grade_level_id) REFERENCES grade_levels(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_pd_rule FOREIGN KEY (promotion_rule_id) REFERENCES promotion_rules(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_pd_aggregate FOREIGN KEY (aggregate_id) REFERENCES student_aggregates(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_pd_student (student_id),
    INDEX idx_pd_year (academic_year_id),
    INDEX idx_pd_decision (decision_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 12. GRADING AUDIT LOGS
-- Audit trail for grading operations
-- ================================================================
DROP TABLE IF EXISTS grading_audit_logs;
CREATE TABLE grading_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    table_name VARCHAR(50) NOT NULL,
    record_id BIGINT UNSIGNED NOT NULL,
    action_type ENUM('INSERT','UPDATE','DELETE','CALCULATE','PUBLISH','UNPUBLISH','ASSIGN') NOT NULL,
    old_data JSON DEFAULT NULL,
    new_data JSON DEFAULT NULL,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gal_uuid (uuid),
    INDEX idx_gal_table_record (table_name, record_id),
    INDEX idx_gal_user (user_id),
    INDEX idx_gal_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 13. GRADING VIEWS
-- ================================================================

-- View: Student Grade Summary
CREATE OR REPLACE VIEW vw_student_grade_summary AS
SELECT 
    s.id AS student_id,
    s.first_name,
    s.last_name,
    s.admission_number,
    gl.level_name,
    cs.section_name,
    at.term_name,
    ay.year_name,
    sg.subject_id,
    sub.subject_name,
    sub.subject_code,
    sg.raw_score,
    sg.grade_code,
    sg.grade_point,
    sg.is_passing,
    sg.is_distinction,
    sg.remarks,
    sg.is_published
FROM student_grades sg
JOIN students s ON sg.student_id = s.id
JOIN class_sections cs ON sg.class_section_id = cs.id
JOIN grade_levels gl ON cs.grade_level_id = gl.id
JOIN academic_terms at ON sg.academic_term_id = at.id
JOIN academic_years ay ON at.academic_year_id = ay.id
JOIN subjects sub ON sg.subject_id = sub.id
WHERE sg.is_active = 1;

-- View: Student Aggregate Summary
CREATE OR REPLACE VIEW vw_student_aggregate_summary AS
SELECT 
    sa.id AS aggregate_id,
    s.id AS student_id,
    s.first_name,
    s.last_name,
    s.admission_number,
    gl.level_name,
    cs.section_name,
    at.term_name,
    ay.year_name,
    sa.total_raw_score,
    sa.total_grade_points,
    sa.average_score,
    sa.average_grade_point,
    sa.core_subjects_count,
    sa.elective_subjects_count,
    sa.total_subjects_count,
    sa.subjects_passed,
    sa.subjects_failed,
    sa.class_position,
    sa.class_total,
    sa.aggregate_score,
    sa.aggregate_grade,
    sa.promotion_status,
    sa.is_published
FROM student_aggregates sa
JOIN students s ON sa.student_id = s.id
JOIN class_sections cs ON sa.class_section_id = cs.id
JOIN grade_levels gl ON cs.grade_level_id = gl.id
JOIN academic_terms at ON sa.academic_term_id = at.id
JOIN academic_years ay ON at.academic_year_id = ay.id
WHERE sa.is_active = 1;

-- View: Promotion Status
CREATE OR REPLACE VIEW vw_promotion_status AS
SELECT 
    pd.id AS promotion_id,
    s.id AS student_id,
    s.first_name,
    s.last_name,
    s.admission_number,
    fgl.level_name AS from_level,
    tgl.level_name AS to_level,
    pd.decision_type,
    pd.average_score,
    pd.attendance_percentage,
    pd.behaviour_score,
    pd.subjects_passed,
    pd.subjects_failed,
    pd.total_subjects,
    pd.teacher_recommendation,
    pd.headteacher_approval,
    pd.headteacher_remarks,
    pd.approval_date,
    ay.year_name
FROM promotion_decisions pd
JOIN students s ON pd.student_id = s.id
JOIN grade_levels fgl ON pd.from_grade_level_id = fgl.id
LEFT JOIN grade_levels tgl ON pd.to_grade_level_id = tgl.id
JOIN academic_years ay ON pd.academic_year_id = ay.id
WHERE pd.is_active = 1;

-- ================================================================
-- 14. STORED PROCEDURES
-- ================================================================

DELIMITER //

-- Procedure: Calculate Student Grades
CREATE OR REPLACE PROCEDURE sp_calculate_student_grades(
    IN p_student_id BIGINT,
    IN p_term_id BIGINT
)
BEGIN
    DECLARE v_school_id BIGINT;
    DECLARE v_class_id BIGINT;
    DECLARE v_done INT DEFAULT FALSE;
    DECLARE v_subject_id BIGINT;
    DECLARE v_raw_score DECIMAL(5,2);
    DECLARE v_grade_code VARCHAR(10);
    DECLARE v_grade_point DECIMAL(3,2);
    DECLARE v_is_passing TINYINT;
    DECLARE v_is_distinction TINYINT;
    
    -- Get student's school and class
    SELECT school_id, class_section_id INTO v_school_id, v_class_id
    FROM students s
    JOIN student_enrollments se ON s.id = se.student_id
    WHERE s.id = p_student_id AND se.academic_term_id = p_term_id AND se.is_active = 1;
    
    -- Delete existing grades
    DELETE FROM student_grades WHERE student_id = p_student_id AND academic_term_id = p_term_id;
    
    -- Get subjects for this class
    DECLARE subject_cursor CURSOR FOR
        SELECT DISTINCT sa.subject_id
        FROM subject_assessments sa
        WHERE sa.class_section_id = v_class_id AND sa.academic_term_id = p_term_id AND sa.is_active = 1;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = TRUE;
    
    OPEN subject_cursor;
    
    read_loop: LOOP
        FETCH subject_cursor INTO v_subject_id;
        IF v_done THEN
            LEAVE read_loop;
        END IF;
        
        -- Get raw score from assessment results
        SELECT total_score INTO v_raw_score
        FROM assessment_results
        WHERE student_id = p_student_id 
        AND subject_id = v_subject_id 
        AND academic_term_id = p_term_id
        AND is_active = 1;
        
        -- If no score found, skip
        IF v_raw_score IS NOT NULL THEN
            -- Get grade from grading system
            SELECT grade_code, grade_point, is_passing, is_distinction
            INTO v_grade_code, v_grade_point, v_is_passing, v_is_distinction
            FROM vw_student_grading_assignment
            WHERE school_id = v_school_id 
            AND grade_level_id = (SELECT grade_level_id FROM class_sections WHERE id = v_class_id)
            AND v_raw_score BETWEEN min_score AND max_score
            LIMIT 1;
            
            -- Insert grade
            INSERT INTO student_grades (
                uuid, school_id, student_id, subject_id,
                academic_term_id, class_section_id,
                raw_score, grade_code, grade_point,
                is_passing, is_distinction,
                is_calculated, calculated_date, is_active
            ) VALUES (
                UUID(), v_school_id, p_student_id, v_subject_id,
                p_term_id, v_class_id,
                v_raw_score, v_grade_code, v_grade_point,
                COALESCE(v_is_passing, 1), COALESCE(v_is_distinction, 0),
                1, NOW(), 1
            );
        END IF;
    END LOOP;
    
    CLOSE subject_cursor;
END //

-- Procedure: Calculate Student Aggregate
CREATE OR REPLACE PROCEDURE sp_calculate_student_aggregate(
    IN p_student_id BIGINT,
    IN p_term_id BIGINT
)
BEGIN
    DECLARE v_school_id BIGINT;
    DECLARE v_class_id BIGINT;
    DECLARE v_rule_id BIGINT;
    DECLARE v_core_count INT DEFAULT 0;
    DECLARE v_elective_count INT DEFAULT 0;
    DECLARE v_total_raw DECIMAL(8,2) DEFAULT 0;
    DECLARE v_total_gp DECIMAL(8,2) DEFAULT 0;
    DECLARE v_subjects_passed INT DEFAULT 0;
    DECLARE v_subjects_failed INT DEFAULT 0;
    DECLARE v_total_subjects INT DEFAULT 0;
    DECLARE v_core_subjects INT DEFAULT 0;
    DECLARE v_elective_subjects INT DEFAULT 0;
    
    -- Get student info
    SELECT s.school_id, se.class_section_id 
    INTO v_school_id, v_class_id
    FROM students s
    JOIN student_enrollments se ON s.id = se.student_id
    WHERE s.id = p_student_id AND se.academic_term_id = p_term_id AND se.is_active = 1;
    
    -- Get aggregate rule
    SELECT id INTO v_rule_id
    FROM aggregate_rules
    WHERE school_id = v_school_id 
    AND (grade_level_id = (SELECT grade_level_id FROM class_sections WHERE id = v_class_id) OR grade_level_id IS NULL)
    AND (academic_term_id = p_term_id OR academic_term_id IS NULL)
    AND is_active = 1
    ORDER BY grade_level_id DESC, academic_term_id DESC, priority DESC
    LIMIT 1;
    
    -- Delete existing aggregate
    DELETE FROM student_aggregates WHERE student_id = p_student_id AND academic_term_id = p_term_id;
    
    -- Get core subjects count from rule
    SELECT core_subjects_count, elective_subjects_count 
    INTO v_core_count, v_elective_count
    FROM aggregate_rules WHERE id = v_rule_id;
    
    -- Calculate core subjects
    SELECT 
        COUNT(*) INTO v_core_subjects,
        COALESCE(SUM(raw_score), 0) INTO v_total_raw,
        COALESCE(SUM(grade_point), 0) INTO v_total_gp    FROM student_grades
    WHERE student_id = p_student_id 
    AND academic_term_id = p_term_id
    AND is_active = 1
    AND subject_id IN (
        SELECT subject_id FROM subject_category_assignments
        WHERE category_id IN (SELECT id FROM subject_categories WHERE category_type = 'core')
        AND is_active = 1
    );
    
    -- Get elective subjects (top N by score)
    -- This is simplified - in production, use a more sophisticated selection
    
    -- Count totals
    SELECT 
        COUNT(*) INTO v_total_subjects,
        SUM(CASE WHEN is_passing = 1 THEN 1 ELSE 0 END) INTO v_subjects_passed,
        SUM(CASE WHEN is_passing = 0 THEN 1 ELSE 0 END) INTO v_subjects_failed
    FROM student_grades
    WHERE student_id = p_student_id 
    AND academic_term_id = p_term_id
    AND is_active = 1;
    
    -- Calculate average
    SET v_total_raw = COALESCE(v_total_raw, 0);
    SET v_total_gp = COALESCE(v_total_gp, 0);
    
    -- Insert aggregate
    INSERT INTO student_aggregates (
        uuid, school_id, student_id, academic_term_id,
        class_section_id, aggregate_rule_id,
        total_raw_score, total_grade_points,
        average_score, average_grade_point,
        core_subjects_count, elective_subjects_count,
        total_subjects_count, subjects_passed, subjects_failed,
        is_calculated, calculated_date, is_active
    ) VALUES (
        UUID(), v_school_id, p_student_id, p_term_id,
        v_class_id, v_rule_id,
        v_total_raw, v_total_gp,
        ROUND(v_total_raw / NULLIF(v_total_subjects, 0), 2),
        ROUND(v_total_gp / NULLIF(v_total_subjects, 0), 2),
        v_core_subjects, v_elective_subjects,
        v_total_subjects, v_subjects_passed, v_subjects_failed,
        1, NOW(), 1
    );
    
END //

-- Procedure: Evaluate Promotion
CREATE OR REPLACE PROCEDURE sp_evaluate_promotion(
    IN p_student_id BIGINT,
    IN p_year_id BIGINT
)
BEGIN
    DECLARE v_school_id BIGINT;
    DECLARE v_from_level_id BIGINT;
    DECLARE v_to_level_id BIGINT;
    DECLARE v_rule_id BIGINT;
    DECLARE v_avg_score DECIMAL(5,2);
    DECLARE v_attendance DECIMAL(5,2);
    DECLARE v_behaviour DECIMAL(5,2);
    DECLARE v_passed INT DEFAULT 0;
    DECLARE v_failed INT DEFAULT 0;
    DECLARE v_total INT DEFAULT 0;
    DECLARE v_decision VARCHAR(50);
    
    -- Get student info
    SELECT s.school_id, gl.id
    INTO v_school_id, v_from_level_id
    FROM students s
    JOIN student_enrollments se ON s.id = se.student_id
    JOIN class_sections cs ON se.class_section_id = cs.id
    JOIN grade_levels gl ON cs.grade_level_id = gl.id
    WHERE s.id = p_student_id AND se.academic_year_id = p_year_id AND se.is_active = 1;
    
    -- Get promotion rule
    SELECT id INTO v_rule_id
    FROM promotion_rules
    WHERE school_id = v_school_id 
    AND (grade_level_id = v_from_level_id OR grade_level_id IS NULL)
    AND (academic_year_id = p_year_id OR academic_year_id IS NULL)
    AND is_active = 1
    ORDER BY grade_level_id DESC, academic_year_id DESC
    LIMIT 1;
    
    -- Get aggregate data
    SELECT 
        average_score,
        subjects_passed,
        subjects_failed,
        total_subjects_count
    INTO v_avg_score, v_passed, v_failed, v_total
    FROM student_aggregates
    WHERE student_id = p_student_id 
    AND academic_term_id IN (SELECT id FROM academic_terms WHERE academic_year_id = p_year_id)
    AND is_active = 1
    ORDER BY academic_term_id DESC
    LIMIT 1;
    
    -- Get attendance
    SELECT attendance_percentage INTO v_attendance
    FROM attendance_summary
    WHERE student_id = p_student_id AND academic_year_id = p_year_id;
    
    -- Determine next level
    SELECT id INTO v_to_level_id
    FROM grade_levels
    WHERE promotion_order = (SELECT promotion_order + 1 FROM grade_levels WHERE id = v_from_level_id)
    AND school_id = v_school_id;
    
    -- Determine decision
    IF v_avg_score >= 60 AND v_passed >= (v_total * 0.8) AND v_attendance >= 75 THEN
        SET v_decision = 'promoted';
    ELSEIF v_avg_score >= 50 AND v_passed >= (v_total * 0.7) AND v_attendance >= 70 THEN
        SET v_decision = 'conditional';
    ELSEIF v_avg_score >= 40 AND v_passed >= (v_total * 0.5) THEN
        SET v_decision = 'probation';
    ELSE
        SET v_decision = 'repeat';
    END IF;
    
    -- Delete existing promotion decision
    DELETE FROM promotion_decisions WHERE student_id = p_student_id AND academic_year_id = p_year_id;
    
    -- Insert promotion decision
    INSERT INTO promotion_decisions (
        uuid, school_id, student_id, academic_year_id,
        from_grade_level_id, to_grade_level_id,
        promotion_rule_id, aggregate_id,
        decision_type, average_score,
        attendance_percentage,
        subjects_passed, subjects_failed, total_subjects,
        is_active
    ) VALUES (
        UUID(), v_school_id, p_student_id, p_year_id,
        v_from_level_id, v_to_level_id,
        v_rule_id, NULL,
        v_decision, v_avg_score,
        v_attendance,
        v_passed, v_failed, v_total,
        1
    );
    
END //

DELIMITER ;

-- ================================================================
-- 15. TRIGGERS
-- ================================================================

-- Trigger: Auto-calculate grades when marks are saved
DROP TRIGGER IF EXISTS trg_marks_after_insert;
DELIMITER //
CREATE TRIGGER trg_marks_after_insert
AFTER INSERT ON assessment_marks
FOR EACH ROW
BEGIN
    -- This would trigger grade calculation
    -- In production, use a queue or scheduled job
END //
DELIMITER ;

-- Trigger: Audit grading changes
DROP TRIGGER IF EXISTS trg_grading_audit_insert;
DELIMITER //
CREATE TRIGGER trg_grading_audit_insert
AFTER INSERT ON grading_systems
FOR EACH ROW
BEGIN
    INSERT INTO grading_audit_logs (
        uuid, school_id, table_name, record_id,
        action_type, new_data, created_at
    ) VALUES (
        UUID(), NEW.school_id, 'grading_systems', NEW.id,
        'INSERT', JSON_OBJECT(
            'system_name', NEW.system_name,
            'system_code', NEW.system_code,
            'system_type', NEW.system_type
        ), NOW()
    );
END //
DELIMITER ;

-- ================================================================
-- 16. SAMPLE DATA
-- ================================================================

-- Alphabet Grading System
INSERT INTO grading_systems (uuid, school_id, system_name, system_code, system_type, is_default) VALUES
(UUID(), 1, 'Alphabet Grading', 'ALPHA', 'alphabet', 1);

-- Alphabet Grades
INSERT INTO grading_scales (uuid, grading_system_id, grade_name, grade_code, min_score, max_score, grade_point, is_passing, is_distinction, sort_order, color) VALUES
(UUID(), 1, 'A', 'A', 80.00, 100.00, 4.00, 1, 1, 1, '#28a745'),
(UUID(), 1, 'B+', 'B+', 75.00, 79.99, 3.50, 1, 0, 2, '#5cb85c'),
(UUID(), 1, 'B', 'B', 70.00, 74.99, 3.00, 1, 0, 3, '#8bc34a'),
(UUID(), 1, 'C+', 'C+', 65.00, 69.99, 2.50, 1, 0, 4, '#ffc107'),
(UUID(), 1, 'C', 'C', 60.00, 64.99, 2.00, 1, 0, 5, '#ff9800'),
(UUID(), 1, 'D', 'D', 50.00, 59.99, 1.00, 1, 0, 6, '#ff5722'),
(UUID(), 1, 'E', 'E', 40.00, 49.99, 0.50, 0, 0, 7, '#f44336'),
(UUID(), 1, 'F', 'F', 0.00, 39.99, 0.00, 0, 0, 8, '#d32f2f');

-- Alphabet Remarks
INSERT INTO grade_remarks (uuid, grading_system_id, grade_code, remark_type, remark_text) VALUES
(UUID(), 1, 'A', 'general', 'Excellent performance. Outstanding work.'),
(UUID(), 1, 'B+', 'general', 'Very good performance. Keep up the good work.'),
(UUID(), 1, 'B', 'general', 'Good performance. Shows understanding.'),
(UUID(), 1, 'C+', 'general', 'Satisfactory performance. Could improve further.'),
(UUID(), 1, 'C', 'general', 'Average performance. Needs more effort.'),
(UUID(), 1, 'D', 'general', 'Below average. Requires additional support.'),
(UUID(), 1, 'E', 'general', 'Poor performance. Immediate intervention needed.'),
(UUID(), 1, 'F', 'general', 'Failing. Requires urgent attention.');

-- Numeric Grading System
INSERT INTO grading_systems (uuid, school_id, system_name, system_code, system_type) VALUES
(UUID(), 1, 'Numeric Grading', 'NUM', 'numeric');

-- Numeric Grades
INSERT INTO grading_scales (uuid, grading_system_id, grade_name, grade_code, min_score, max_score, grade_point, is_passing, is_distinction, sort_order, color) VALUES
(UUID(), 2, '1', '1', 90.00, 100.00, 4.00, 1, 1, 1, '#28a745'),
(UUID(), 2, '2', '2', 80.00, 89.99, 3.50, 1, 0, 2, '#5cb85c'),
(UUID(), 2, '3', '3', 70.00, 79.99, 3.00, 1, 0, 3, '#8bc34a'),
(UUID(), 2, '4', '4', 60.00, 69.99, 2.00, 1, 0, 4, '#ffc107'),
(UUID(), 2, '5', '5', 50.00, 59.99, 1.00, 1, 0, 5, '#ff9800'),
(UUID(), 2, '6', '6', 40.00, 49.99, 0.50, 0, 0, 6, '#ff5722'),
(UUID(), 2, '7', '7', 30.00, 39.99, 0.00, 0, 0, 7, '#f44336'),
(UUID(), 2, '8', '8', 20.00, 29.99, 0.00, 0, 0, 8, '#d32f2f'),
(UUID(), 2, '9', '9', 0.00, 19.99, 0.00, 0, 0, 9, '#b71c1c');

-- WAEC Grading System
INSERT INTO grading_systems (uuid, school_id, system_name, system_code, system_type) VALUES
(UUID(), 1, 'WAEC Grading', 'WAEC', 'waec');

-- WAEC Grades
INSERT INTO grading_scales (uuid, grading_system_id, grade_name, grade_code, min_score, max_score, grade_point, is_passing, is_distinction, sort_order, color) VALUES
(UUID(), 3, 'A1', 'A1', 80.00, 100.00, 4.00, 1, 1, 1, '#28a745'),
(UUID(), 3, 'B2', 'B2', 70.00, 79.99, 3.50, 1, 0, 2, '#5cb85c'),
(UUID(), 3, 'B3', 'B3', 65.00, 69.99, 3.00, 1, 0, 3, '#8bc34a'),
(UUID(), 3, 'C4', 'C4', 60.00, 64.99, 2.50, 1, 0, 4, '#ffc107'),
(UUID(), 3, 'C5', 'C5', 55.00, 59.99, 2.00, 1, 0, 5, '#ff9800'),
(UUID(), 3, 'C6', 'C6', 50.00, 54.99, 1.50, 1, 0, 6, '#ff5722'),
(UUID(), 3, 'D7', 'D7', 40.00, 49.99, 0.50, 0, 0, 7, '#f44336'),
(UUID(), 3, 'E8', 'E8', 30.00, 39.99, 0.00, 0, 0, 8, '#d32f2f'),
(UUID(), 3, 'F9', 'F9', 0.00, 29.99, 0.00, 0, 0, 9, '#b71c1c');

-- Custom Grading System
INSERT INTO grading_systems (uuid, school_id, system_name, system_code, system_type) VALUES
(UUID(), 1, 'Custom Grading', 'CUSTOM', 'custom');

-- Custom Grades
INSERT INTO grading_scales (uuid, grading_system_id, grade_name, grade_code, min_score, max_score, grade_point, is_passing, is_distinction, sort_order, color) VALUES
(UUID(), 4, 'Outstanding', 'OUT', 90.00, 100.00, 4.00, 1, 1, 1, '#28a745'),
(UUID(), 4, 'Excellent', 'EXC', 80.00, 89.99, 3.50, 1, 0, 2, '#5cb85c'),
(UUID(), 4, 'Very Good', 'VG', 70.00, 79.99, 3.00, 1, 0, 3, '#8bc34a'),
(UUID(), 4, 'Good', 'GD', 60.00, 69.99, 2.00, 1, 0, 4, '#ffc107'),
(UUID(), 4, 'Average', 'AVG', 50.00, 59.99, 1.00, 1, 0, 5, '#ff9800'),
(UUID(), 4, 'Needs Improvement', 'NI', 40.00, 49.99, 0.50, 0, 0, 6, '#ff5722'),
(UUID(), 4, 'Fail', 'FL', 0.00, 39.99, 0.00, 0, 0, 7, '#d32f2f');

-- Subject Categories
INSERT INTO subject_categories (uuid, school_id, category_name, category_code, category_type, is_compulsory, count_in_aggregate) VALUES
(UUID(), 1, 'Core Subjects', 'CORE', 'core', 1, 1),
(UUID(), 1, 'Elective Subjects', 'ELECT', 'elective', 0, 1),
(UUID(), 1, 'Vocational Subjects', 'VOC', 'vocational', 0, 0),
(UUID(), 1, 'Practical Subjects', 'PRAC', 'practical', 0, 0),
(UUID(), 1, 'Languages', 'LANG', 'language', 0, 1),
(UUID(), 1, 'STEM Subjects', 'STEM', 'stem', 0, 1),
(UUID(), 1, 'Creative Arts', 'ARTS', 'arts', 0, 0),
(UUID(), 1, 'Religious Studies', 'REL', 'religious', 0, 0);

-- Aggregate Rules
INSERT INTO aggregate_rules (uuid, school_id, rule_name, rule_code, core_subjects_count, elective_subjects_count, best_elective_selection, pass_mark) VALUES
(UUID(), 1, 'Standard Aggregate', 'STD_AGG', 4, 2, 'highest_score', 50.00);

-- Promotion Rules
INSERT INTO promotion_rules (uuid, school_id, rule_name, rule_code, min_average_score, min_attendance_percentage, max_failures_allowed) VALUES
(UUID(), 1, 'Standard Promotion', 'STD_PROMO', 50.00, 75.00, 2);

SET FOREIGN_KEY_CHECKS = 1;

-- ================================================================
-- 17. VERIFY TABLES
-- ================================================================

SHOW TABLES LIKE 'grading%';
SHOW TABLES LIKE 'grade%';
SHOW TABLES LIKE 'subject_categories%';
SHOW TABLES LIKE 'aggregate%';
SHOW TABLES LIKE 'promotion%';
SHOW TABLES LIKE 'student_grades%';
SHOW TABLES LIKE 'student_aggregates%';

SELECT '✅ Grading Module Tables Created Successfully!' AS Status;