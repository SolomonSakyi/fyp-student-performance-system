-- ============================================
-- Class and Enrollment Tables
-- Includes: Class Sections, Teacher Assignments, Enrollments
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Class Sections Table
CREATE TABLE IF NOT EXISTS `class_sections` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `grade_level_id` BIGINT UNSIGNED NOT NULL,
    `academic_year_id` BIGINT UNSIGNED NOT NULL,
    `section_name` VARCHAR(100) NOT NULL,
    `section_code` VARCHAR(20) NOT NULL,
    `class_teacher_id` BIGINT UNSIGNED DEFAULT NULL,
    `capacity` INT NOT NULL DEFAULT 40,
    `room_number` VARCHAR(20) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_school_level_year_section` (`school_id`, `grade_level_id`, `academic_year_id`, `section_code`),
    CONSTRAINT `fk_cs_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_cs_grade_level` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_cs_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_cs_class_teacher` FOREIGN KEY (`class_teacher_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Class Teacher Assignments
CREATE TABLE IF NOT EXISTS `class_teacher_assignments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `staff_id` BIGINT UNSIGNED NOT NULL,
    `class_section_id` BIGINT UNSIGNED NOT NULL,
    `academic_year_id` BIGINT UNSIGNED NOT NULL,
    `assignment_type` ENUM('primary', 'assistant', 'temporary') NOT NULL DEFAULT 'primary',
    `assigned_date` DATE NOT NULL,
    `end_date` DATE DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_staff_class_year` (`staff_id`, `class_section_id`, `academic_year_id`),
    CONSTRAINT `fk_cta_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_cta_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_cta_class` FOREIGN KEY (`class_section_id`) REFERENCES `class_sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_cta_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subject Teacher Assignments
CREATE TABLE IF NOT EXISTS `subject_teacher_assignments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `staff_id` BIGINT UNSIGNED NOT NULL,
    `class_section_id` BIGINT UNSIGNED NOT NULL,
    `subject_id` BIGINT UNSIGNED NOT NULL,
    `academic_year_id` BIGINT UNSIGNED NOT NULL,
    `academic_term_id` BIGINT UNSIGNED NOT NULL,
    `assigned_date` DATE NOT NULL,
    `end_date` DATE DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_staff_class_subject_year` (`staff_id`, `class_section_id`, `subject_id`, `academic_year_id`),
    CONSTRAINT `fk_sta_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sta_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sta_class` FOREIGN KEY (`class_section_id`) REFERENCES `class_sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sta_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sta_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sta_term` FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Student Enrollments Table
CREATE TABLE IF NOT EXISTS `student_enrollments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `student_id` BIGINT UNSIGNED NOT NULL,
    `class_section_id` BIGINT UNSIGNED NOT NULL,
    `academic_year_id` BIGINT UNSIGNED NOT NULL,
    `academic_term_id` BIGINT UNSIGNED DEFAULT NULL,
    `enrollment_date` DATE NOT NULL,
    `withdrawal_date` DATE DEFAULT NULL,
    `enrollment_status` ENUM('active', 'withdrawn', 'transferred', 'graduated', 'suspended') NOT NULL DEFAULT 'active',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_student_class_year_term` (`student_id`, `class_section_id`, `academic_year_id`, `academic_term_id`),
    CONSTRAINT `fk_se_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_se_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_se_class` FOREIGN KEY (`class_section_id`) REFERENCES `class_sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_se_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_se_term` FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;