-- ============================================
-- Platform Tables
-- Includes: People, Students, Staff
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- People Table (Single Source of Truth)
CREATE TABLE IF NOT EXISTS `people` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `first_name` VARCHAR(100) NOT NULL,
    `middle_name` VARCHAR(100) DEFAULT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `date_of_birth` DATE DEFAULT NULL,
    `gender` ENUM('male', 'female', 'other') NOT NULL DEFAULT 'male',
    `primary_email` VARCHAR(100) DEFAULT NULL,
    `primary_phone` VARCHAR(20) DEFAULT NULL,
    `secondary_phone` VARCHAR(20) DEFAULT NULL,
    `address` TEXT DEFAULT NULL,
    `city` VARCHAR(100) DEFAULT NULL,
    `state` VARCHAR(100) DEFAULT NULL,
    `country` VARCHAR(100) DEFAULT 'Ghana',
    `nationality` VARCHAR(100) DEFAULT 'Ghanaian',
    `profile_picture` VARCHAR(255) DEFAULT NULL,
    `bio` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    INDEX `idx_people_email` (`primary_email`),
    INDEX `idx_people_phone` (`primary_phone`),
    INDEX `idx_people_name` (`first_name`, `last_name`),
    CONSTRAINT `fk_people_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Students Table
CREATE TABLE IF NOT EXISTS `students` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `person_id` BIGINT UNSIGNED NOT NULL,
    `admission_number` VARCHAR(50) NOT NULL,
    `student_id` VARCHAR(50) DEFAULT NULL,
    `class_section_id` BIGINT UNSIGNED DEFAULT NULL,
    `academic_year_id` BIGINT UNSIGNED DEFAULT NULL,
    `current_term_id` BIGINT UNSIGNED DEFAULT NULL,
    `enrollment_date` DATE NOT NULL,
    `graduation_date` DATE DEFAULT NULL,
    `enrollment_status` ENUM('active', 'graduated', 'transferred', 'suspended', 'expelled', 'withdrawn') NOT NULL DEFAULT 'active',
    `parent_guardian_name` VARCHAR(200) DEFAULT NULL,
    `parent_guardian_phone` VARCHAR(20) DEFAULT NULL,
    `parent_guardian_email` VARCHAR(100) DEFAULT NULL,
    `parent_guardian_address` TEXT DEFAULT NULL,
    `emergency_contact_name` VARCHAR(200) DEFAULT NULL,
    `emergency_contact_phone` VARCHAR(20) DEFAULT NULL,
    `emergency_contact_relation` VARCHAR(50) DEFAULT NULL,
    `medical_conditions` TEXT DEFAULT NULL,
    `allergies` TEXT DEFAULT NULL,
    `special_needs` TEXT DEFAULT NULL,
    `biometric_id` VARCHAR(100) DEFAULT NULL,
    `id_card_number` VARCHAR(50) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_admission_number` (`admission_number`),
    UNIQUE KEY `uq_student_id` (`student_id`),
    UNIQUE KEY `uq_student_biometric` (`biometric_id`),
    UNIQUE KEY `uq_student_id_card` (`id_card_number`),
    INDEX `idx_student_status` (`enrollment_status`),
    CONSTRAINT `fk_students_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_students_person` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_students_class` FOREIGN KEY (`class_section_id`) REFERENCES `class_sections` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_students_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staff Table
CREATE TABLE IF NOT EXISTS `staff` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `person_id` BIGINT UNSIGNED NOT NULL,
    `staff_number` VARCHAR(50) NOT NULL,
    `employee_id` VARCHAR(50) DEFAULT NULL,
    `department` VARCHAR(100) DEFAULT NULL,
    `designation` VARCHAR(100) DEFAULT NULL,
    `staff_type` ENUM('teaching', 'non-teaching', 'admin', 'support') NOT NULL DEFAULT 'teaching',
    `is_teaching_staff` TINYINT(1) NOT NULL DEFAULT 1,
    `hire_date` DATE NOT NULL,
    `termination_date` DATE DEFAULT NULL,
    `employment_status` ENUM('active', 'on_leave', 'suspended', 'terminated', 'retired') NOT NULL DEFAULT 'active',
    `qualifications` TEXT DEFAULT NULL,
    `specialization` VARCHAR(200) DEFAULT NULL,
    `bank_name` VARCHAR(100) DEFAULT NULL,
    `bank_account_number` VARCHAR(50) DEFAULT NULL,
    `bank_account_name` VARCHAR(200) DEFAULT NULL,
    `emergency_contact_name` VARCHAR(200) DEFAULT NULL,
    `emergency_contact_phone` VARCHAR(20) DEFAULT NULL,
    `emergency_contact_relation` VARCHAR(50) DEFAULT NULL,
    `biometric_id` VARCHAR(100) DEFAULT NULL,
    `id_card_number` VARCHAR(50) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_staff_number` (`staff_number`),
    UNIQUE KEY `uq_employee_id` (`employee_id`),
    UNIQUE KEY `uq_staff_biometric` (`biometric_id`),
    UNIQUE KEY `uq_staff_id_card` (`id_card_number`),
    INDEX `idx_staff_type` (`staff_type`),
    CONSTRAINT `fk_staff_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_staff_person` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;