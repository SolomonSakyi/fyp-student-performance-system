-- ============================================
-- Migration v1.0.2
-- Add teacher-specific fields
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Add teacher-specific fields to staff table
ALTER TABLE `staff` 
ADD COLUMN IF NOT EXISTS `specialization` VARCHAR(200) DEFAULT NULL AFTER `qualifications`,
ADD COLUMN IF NOT EXISTS `years_of_experience` INT DEFAULT 0 AFTER `specialization`,
ADD COLUMN IF NOT EXISTS `teacher_license_number` VARCHAR(50) DEFAULT NULL AFTER `years_of_experience`,
ADD COLUMN IF NOT EXISTS `license_expiry_date` DATE DEFAULT NULL AFTER `teacher_license_number`;

-- Add index for teacher license number
ALTER TABLE `staff` ADD INDEX `idx_teacher_license` (`teacher_license_number`);

-- Create teacher_class_schedule table
CREATE TABLE IF NOT EXISTS `teacher_class_schedule` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `staff_id` BIGINT UNSIGNED NOT NULL,
    `class_section_id` BIGINT UNSIGNED NOT NULL,
    `subject_id` BIGINT UNSIGNED NOT NULL,
    `academic_term_id` BIGINT UNSIGNED NOT NULL,
    `day_of_week` ENUM('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday') NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `room_number` VARCHAR(20) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_teacher_schedule` (`staff_id`, `day_of_week`, `start_time`, `end_time`),
    CONSTRAINT `fk_tcs_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_tcs_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_tcs_class` FOREIGN KEY (`class_section_id`) REFERENCES `class_sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_tcs_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_tcs_term` FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;