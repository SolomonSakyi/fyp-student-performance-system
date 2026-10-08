-- ============================================
-- Flexible Assessment Tables
-- Allows admins to configure assessment components dynamically
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- 1. Assessment Configurations (Per School/Grade Level)
-- ============================================
CREATE TABLE IF NOT EXISTS `assessment_configs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `grade_level_id` BIGINT UNSIGNED NOT NULL,  -- NULL means applies to all levels
    `academic_year_id` BIGINT UNSIGNED DEFAULT NULL,  -- NULL means current year
    `config_name` VARCHAR(100) NOT NULL,
    `config_code` VARCHAR(50) DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_school_config` (`school_id`, `config_code`),
    INDEX `idx_config_grade` (`grade_level_id`),
    INDEX `idx_config_year` (`academic_year_id`),
    CONSTRAINT `fk_ac_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ac_grade_level` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ac_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. Assessment Components (Flexible)
-- ============================================
CREATE TABLE IF NOT EXISTS `assessment_components` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `component_name` VARCHAR(100) NOT NULL,
    `component_code` VARCHAR(50) DEFAULT NULL,
    `component_type` ENUM('ca', 'exam', 'practical', 'project', 'assignment', 'quiz', 'homework', 'test', 'custom') NOT NULL DEFAULT 'custom',
    `default_weight` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `max_score` DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    `description` TEXT DEFAULT NULL,
    `display_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_school_component` (`school_id`, `component_code`),
    INDEX `idx_component_type` (`component_type`),
    CONSTRAINT `fk_acomp_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. Configuration Components (Link configs to components with weights)
-- ============================================
CREATE TABLE IF NOT EXISTS `config_components` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `config_id` BIGINT UNSIGNED NOT NULL,
    `component_id` BIGINT UNSIGNED NOT NULL,
    `weight_percentage` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `is_required` TINYINT(1) NOT NULL DEFAULT 1,
    `display_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_config_component` (`config_id`, `component_id`),
    CONSTRAINT `fk_cc_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_cc_config` FOREIGN KEY (`config_id`) REFERENCES `assessment_configs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_cc_component` FOREIGN KEY (`component_id`) REFERENCES `assessment_components` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. Assessment Instances (Created by teachers)
-- ============================================
CREATE TABLE IF NOT EXISTS `assessments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `class_section_id` BIGINT UNSIGNED NOT NULL,
    `subject_id` BIGINT UNSIGNED NOT NULL,
    `config_id` BIGINT UNSIGNED DEFAULT NULL,  -- Which assessment config is being used
    `component_id` BIGINT UNSIGNED NOT NULL,  -- Which component this assessment is for
    `academic_year_id` BIGINT UNSIGNED NOT NULL,
    `academic_term_id` BIGINT UNSIGNED NOT NULL,
    `assessment_title` VARCHAR(200) NOT NULL,
    `assessment_code` VARCHAR(50) DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `max_score` DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    `pass_score` DECIMAL(5,2) DEFAULT NULL,
    `assessment_date` DATE NOT NULL,
    `start_time` TIME DEFAULT NULL,
    `end_time` TIME DEFAULT NULL,
    `duration_minutes` INT DEFAULT NULL,
    `venue` VARCHAR(200) DEFAULT NULL,
    `is_published` TINYINT(1) NOT NULL DEFAULT 0,
    `published_date` TIMESTAMP NULL DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `updated_by` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_school_code` (`school_id`, `assessment_code`),
    INDEX `idx_assessments_class_subject` (`class_section_id`, `subject_id`, `academic_term_id`),
    INDEX `idx_assessments_date` (`assessment_date`),
    INDEX `idx_assessments_published` (`is_published`),
    INDEX `idx_assessments_component` (`component_id`),
    INDEX `idx_assessments_config` (`config_id`),
    CONSTRAINT `fk_ass_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ass_class` FOREIGN KEY (`class_section_id`) REFERENCES `class_sections` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ass_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ass_config` FOREIGN KEY (`config_id`) REFERENCES `assessment_configs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_ass_component` FOREIGN KEY (`component_id`) REFERENCES `assessment_components` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ass_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ass_term` FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. Student Scores
-- ============================================
CREATE TABLE IF NOT EXISTS `student_scores` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `assessment_id` BIGINT UNSIGNED NOT NULL,
    `student_id` BIGINT UNSIGNED NOT NULL,
    `score_obtained` DECIMAL(5,2) DEFAULT NULL,
    `percentage_score` DECIMAL(5,2) DEFAULT NULL,
    `weighted_score` DECIMAL(5,2) DEFAULT NULL,  -- Score * component weight / 100
    `grade` VARCHAR(10) DEFAULT NULL,
    `is_absent` TINYINT(1) NOT NULL DEFAULT 0,
    `is_excused` TINYINT(1) NOT NULL DEFAULT 0,
    `entered_by_staff_id` BIGINT UNSIGNED DEFAULT NULL,
    `entered_date` TIMESTAMP NULL DEFAULT NULL,
    `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `verified_by` BIGINT UNSIGNED DEFAULT NULL,
    `verified_date` TIMESTAMP NULL DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_assessment_student` (`school_id`, `assessment_id`, `student_id`),
    INDEX `idx_student_scores_student` (`student_id`),
    INDEX `idx_student_scores_assessment` (`assessment_id`),
    INDEX `idx_student_scores_entered` (`entered_date`),
    CONSTRAINT `fk_ss_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ss_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ss_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. Grade Calculations (Aggregated results)
-- ============================================
CREATE TABLE IF NOT EXISTS `grade_calculations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `student_id` BIGINT UNSIGNED NOT NULL,
    `subject_id` BIGINT UNSIGNED NOT NULL,
    `academic_year_id` BIGINT UNSIGNED NOT NULL,
    `academic_term_id` BIGINT UNSIGNED NOT NULL,
    `total_score` DECIMAL(5,2) DEFAULT NULL,  -- Sum of all weighted scores
    `total_percentage` DECIMAL(5,2) DEFAULT NULL,  -- Percentage of total possible
    `grade` VARCHAR(10) DEFAULT NULL,
    `is_calculated` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_student_subject_term` (`student_id`, `subject_id`, `academic_year_id`, `academic_term_id`),
    CONSTRAINT `fk_gc_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_gc_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_gc_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_gc_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_gc_term` FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;