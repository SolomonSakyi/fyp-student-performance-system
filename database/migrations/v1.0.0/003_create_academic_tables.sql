-- ============================================
-- Academic Tables
-- Includes: Academic Years, Terms, Grade Levels, Subjects
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Academic Years Table
CREATE TABLE IF NOT EXISTS `academic_years` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `year_name` VARCHAR(50) NOT NULL,
    `year_code` VARCHAR(20) DEFAULT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `is_current` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('upcoming', 'current', 'completed', 'archived') NOT NULL DEFAULT 'upcoming',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_school_year_code` (`school_id`, `year_code`),
    CONSTRAINT `fk_ay_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Academic Terms Table
CREATE TABLE IF NOT EXISTS `academic_terms` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `academic_year_id` BIGINT UNSIGNED NOT NULL,
    `term_name` VARCHAR(50) NOT NULL,
    `term_number` TINYINT UNSIGNED NOT NULL,
    `term_code` VARCHAR(20) DEFAULT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `is_current` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('upcoming', 'current', 'completed', 'archived') NOT NULL DEFAULT 'upcoming',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_school_year_term` (`school_id`, `academic_year_id`, `term_number`),
    CONSTRAINT `fk_at_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_at_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grade Levels Table
CREATE TABLE IF NOT EXISTS `grade_levels` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `level_name` VARCHAR(50) NOT NULL,
    `level_code` VARCHAR(20) DEFAULT NULL,
    `section` VARCHAR(50) DEFAULT NULL,
    `promotion_order` INT NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_school_level_name` (`school_id`, `level_name`),
    CONSTRAINT `fk_gl_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subject Groups Table
CREATE TABLE IF NOT EXISTS `subject_groups` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `group_name` VARCHAR(100) NOT NULL,
    `group_code` VARCHAR(20) DEFAULT NULL,
    `group_type` ENUM('core', 'elective', 'vocational', 'custom') NOT NULL DEFAULT 'custom',
    `description` TEXT DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_school_group_name` (`school_id`, `group_name`),
    CONSTRAINT `fk_sg_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subject Categories Table
CREATE TABLE IF NOT EXISTS `subject_categories` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `category_name` VARCHAR(100) NOT NULL,
    `category_code` VARCHAR(20) DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_school_category_name` (`school_id`, `category_name`),
    CONSTRAINT `fk_sc_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subjects Table
CREATE TABLE IF NOT EXISTS `subjects` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `subject_name` VARCHAR(200) NOT NULL,
    `subject_code` VARCHAR(50) NOT NULL,
    `subject_type` ENUM('core', 'elective', 'vocational', 'optional') NOT NULL DEFAULT 'core',
    `is_core` TINYINT(1) NOT NULL DEFAULT 1,
    `is_elective` TINYINT(1) NOT NULL DEFAULT 0,
    `category` VARCHAR(50) DEFAULT NULL,
    `subject_group_id` BIGINT UNSIGNED DEFAULT NULL,
    `display_order` INT DEFAULT 0,
    `icon` VARCHAR(50) DEFAULT NULL,
    `color` VARCHAR(20) DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_school_subject_code` (`school_id`, `subject_code`),
    INDEX `idx_subject_type` (`subject_type`),
    INDEX `idx_is_core` (`is_core`),
    INDEX `idx_is_elective` (`is_elective`),
    CONSTRAINT `fk_subjects_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_subjects_group` FOREIGN KEY (`subject_group_id`) REFERENCES `subject_groups` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grade Level Subjects (Junction Table)
CREATE TABLE IF NOT EXISTS `grade_level_subjects` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `grade_level_id` BIGINT UNSIGNED NOT NULL,
    `subject_id` BIGINT UNSIGNED NOT NULL,
    `is_compulsory` TINYINT(1) NOT NULL DEFAULT 1,
    `is_examinable` TINYINT(1) NOT NULL DEFAULT 1,
    `display_order` INT DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_grade_level_subject` (`school_id`, `grade_level_id`, `subject_id`),
    CONSTRAINT `fk_gls_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_gls_grade_level` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_gls_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aggregate Calculation Configuration
CREATE TABLE IF NOT EXISTS `aggregate_calculation_config` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `grade_level_id` BIGINT UNSIGNED NOT NULL,
    `calculation_type` ENUM('bec', 'wasce', 'custom') NOT NULL DEFAULT 'bec',
    `core_subject_count` INT NOT NULL DEFAULT 4,
    `elective_subject_count` INT NOT NULL DEFAULT 2,
    `best_elective_count` INT NOT NULL DEFAULT 2,
    `aggregate_formula` VARCHAR(100) DEFAULT 'core + best_electives',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_grade_level` (`school_id`, `grade_level_id`),
    CONSTRAINT `fk_acc_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_acc_grade_level` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;