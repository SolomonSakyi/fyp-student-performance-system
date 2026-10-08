-- ================================================
-- CAMPUS_SETTINGS TABLE MIGRATION
-- ================================================
-- This migration creates the campus settings table
-- for configurable campus-level settings
-- ================================================

CREATE TABLE IF NOT EXISTS `campus_settings` (
    `setting_id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `campus_id` BIGINT(20) UNSIGNED NOT NULL,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT DEFAULT NULL,
    `setting_type` VARCHAR(20) NOT NULL DEFAULT 'string',
    `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0,
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
    
    PRIMARY KEY (`setting_id`),
    UNIQUE KEY `uk_campus_settings_uuid` (`uuid`),
    UNIQUE KEY `uk_campus_settings_campus_key` (`campus_id`, `setting_key`),
    
    CONSTRAINT `fk_campus_settings_campus_id` 
        FOREIGN KEY (`campus_id`) 
        REFERENCES `campuses` (`campus_id`) 
        ON DELETE CASCADE 
        ON UPDATE CASCADE,
    
    INDEX `idx_campus_settings_campus_id` (`campus_id`),
    INDEX `idx_campus_settings_setting_key` (`setting_key`),
    INDEX `idx_campus_settings_is_system` (`is_system`)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;