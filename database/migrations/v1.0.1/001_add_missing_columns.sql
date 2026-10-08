-- ============================================
-- Migration v1.0.1
-- Add missing columns and improvements
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Add index to students table for faster lookups
ALTER TABLE `students` ADD INDEX `idx_admission_status` (`admission_number`, `enrollment_status`);

-- Add index to staff table for faster lookups
ALTER TABLE `staff` ADD INDEX `idx_staff_status` (`staff_number`, `employment_status`);

-- Add is_current field to academic_terms if missing
ALTER TABLE `academic_terms` ADD COLUMN IF NOT EXISTS `is_current` TINYINT(1) NOT NULL DEFAULT 0 AFTER `end_date`;

-- Add index for is_current on academic_terms
ALTER TABLE `academic_terms` ADD INDEX `idx_is_current` (`is_current`);

-- Add index for is_current on academic_years
ALTER TABLE `academic_years` ADD INDEX `idx_is_current` (`is_current`);

-- Add student_academic_history table for tracking academic progression
CREATE TABLE IF NOT EXISTS `student_academic_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `student_id` BIGINT UNSIGNED NOT NULL,
    `academic_year_id` BIGINT UNSIGNED NOT NULL,
    `academic_term_id` BIGINT UNSIGNED NOT NULL,
    `class_section_id` BIGINT UNSIGNED NOT NULL,
    `average_score` DECIMAL(5,2) DEFAULT NULL,
    `aggregate_score` INT DEFAULT NULL,
    `total_subjects_passed` INT DEFAULT NULL,
    `total_subjects_failed` INT DEFAULT NULL,
    `promotion_status` ENUM('promoted', 'retained', 'transferred', 'graduated', 'suspended', 'pending') NOT NULL DEFAULT 'pending',
    `remarks` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_student_year_term` (`student_id`, `academic_year_id`, `academic_term_id`),
    CONSTRAINT `fk_sah_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sah_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sah_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sah_term` FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sah_class` FOREIGN KEY (`class_section_id`) REFERENCES `class_sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;