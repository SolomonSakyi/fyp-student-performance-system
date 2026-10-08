-- ============================================
-- Attendance Tables
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Attendance Configuration
CREATE TABLE IF NOT EXISTS `attendance_config` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `start_time` TIME NOT NULL DEFAULT '08:00:00',
    `end_time` TIME NOT NULL DEFAULT '15:00:00',
    `late_threshold_minutes` INT NOT NULL DEFAULT 15,
    `half_day_threshold_hours` INT NOT NULL DEFAULT 4,
    `days_per_week` INT NOT NULL DEFAULT 5,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_school` (`school_id`),
    CONSTRAINT `fk_ac_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily Attendance
CREATE TABLE IF NOT EXISTS `daily_attendance` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `student_id` BIGINT UNSIGNED NOT NULL,
    `class_section_id` BIGINT UNSIGNED NOT NULL,
    `academic_year_id` BIGINT UNSIGNED NOT NULL,
    `academic_term_id` BIGINT UNSIGNED NOT NULL,
    `attendance_date` DATE NOT NULL,
    `status` ENUM('present', 'absent', 'late', 'excused', 'half_day', 'holiday') NOT NULL DEFAULT 'present',
    `check_in_time` TIME DEFAULT NULL,
    `check_out_time` TIME DEFAULT NULL,
    `reason_for_absence` TEXT DEFAULT NULL,
    `entered_by_staff_id` BIGINT UNSIGNED DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_student_date` (`student_id`, `attendance_date`),
    INDEX `idx_attendance_date` (`attendance_date`),
    INDEX `idx_attendance_status` (`status`),
    CONSTRAINT `fk_da_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_da_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_da_class` FOREIGN KEY (`class_section_id`) REFERENCES `class_sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_da_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_da_term` FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subject Attendance (For subject-specific attendance tracking)
CREATE TABLE IF NOT EXISTS `subject_attendance` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `student_id` BIGINT UNSIGNED NOT NULL,
    `class_section_id` BIGINT UNSIGNED NOT NULL,
    `subject_id` BIGINT UNSIGNED NOT NULL,
    `academic_year_id` BIGINT UNSIGNED NOT NULL,
    `academic_term_id` BIGINT UNSIGNED NOT NULL,
    `attendance_date` DATE NOT NULL,
    `status` ENUM('present', 'absent', 'late', 'excused') NOT NULL DEFAULT 'present',
    `entered_by_staff_id` BIGINT UNSIGNED DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_student_subject_date` (`student_id`, `subject_id`, `attendance_date`),
    CONSTRAINT `fk_sa_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sa_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sa_class` FOREIGN KEY (`class_section_id`) REFERENCES `class_sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sa_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sa_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sa_term` FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;