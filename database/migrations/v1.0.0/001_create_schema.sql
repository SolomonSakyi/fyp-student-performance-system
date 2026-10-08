-- ============================================
-- EduTrack Database Schema v1.0.0
-- Created: 2026-07-19
-- Description: Initial database schema
-- ============================================

-- Set character set and collation
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- Drop tables if they exist (in reverse order)
-- ============================================
DROP TABLE IF EXISTS `student_scores`;
DROP TABLE IF EXISTS `assessments`;
DROP TABLE IF EXISTS `assessment_types`;
DROP TABLE IF EXISTS `student_enrollments`;
DROP TABLE IF EXISTS `class_sections`;
DROP TABLE IF EXISTS `grade_level_subjects`;
DROP TABLE IF EXISTS `grade_levels`;
DROP TABLE IF EXISTS `subjects`;
DROP TABLE IF EXISTS `subject_categories`;
DROP TABLE IF EXISTS `subject_groups`;
DROP TABLE IF EXISTS `academic_terms`;
DROP TABLE IF EXISTS `academic_years`;
DROP TABLE IF EXISTS `class_teacher_assignments`;
DROP TABLE IF EXISTS `subject_teacher_assignments`;
DROP TABLE IF EXISTS `staff`;
DROP TABLE IF EXISTS `students`;
DROP TABLE IF EXISTS `people`;
DROP TABLE IF EXISTS `school_grade_config`;
DROP TABLE IF EXISTS `schools`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- Create tables
-- ============================================

-- 1. Schools Table
CREATE TABLE IF NOT EXISTS `schools` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_name` VARCHAR(200) NOT NULL,
    `school_code` VARCHAR(50) NOT NULL UNIQUE,
    `school_type` ENUM('public', 'private', 'international', 'religious') NOT NULL DEFAULT 'private',
    `level` ENUM('primary', 'secondary', 'combined', 'tertiary', 'other') NOT NULL DEFAULT 'primary',
    `grade_level_terminology` VARCHAR(50) NOT NULL DEFAULT 'Basic',
    `start_from` VARCHAR(50) NOT NULL DEFAULT 'Nursery 1',
    `has_creche` TINYINT(1) NOT NULL DEFAULT 0,
    `has_nursery` TINYINT(1) NOT NULL DEFAULT 1,
    `has_kindergarten` TINYINT(1) NOT NULL DEFAULT 1,
    `has_primary` TINYINT(1) NOT NULL DEFAULT 1,
    `has_jhs` TINYINT(1) NOT NULL DEFAULT 1,
    `has_shs` TINYINT(1) NOT NULL DEFAULT 0,
    `address` TEXT DEFAULT NULL,
    `city` VARCHAR(100) DEFAULT NULL,
    `state` VARCHAR(100) DEFAULT NULL,
    `country` VARCHAR(100) DEFAULT 'Ghana',
    `postal_code` VARCHAR(20) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `website` VARCHAR(200) DEFAULT NULL,
    `logo` VARCHAR(255) DEFAULT NULL,
    `academic_year_start` DATE DEFAULT NULL,
    `academic_year_end` DATE DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_school_code` (`school_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. School Grade Configuration Table
CREATE TABLE IF NOT EXISTS `school_grade_config` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `terminology` VARCHAR(50) NOT NULL DEFAULT 'Basic',
    `start_level` VARCHAR(50) NOT NULL DEFAULT 'Nursery 1',
    `level_names` JSON NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_school` (`school_id`),
    CONSTRAINT `fk_sgc_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Users Table
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `person_id` BIGINT UNSIGNED DEFAULT NULL,
    `username` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'teacher', 'student', 'parent', 'accountant', 'librarian', 'super_admin') NOT NULL DEFAULT 'teacher',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_username` (`username`),
    UNIQUE KEY `uq_email` (`email`),
    CONSTRAINT `fk_users_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_users_person` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;