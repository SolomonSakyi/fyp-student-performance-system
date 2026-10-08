-- ================================================
-- SCHOOL_SETTINGS TABLE MIGRATION
-- ================================================
-- This migration creates the school settings table
-- for configurable school-level settings
-- ================================================

CREATE TABLE IF NOT EXISTS `school_settings` (
    `setting_id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT(20) UNSIGNED NOT NULL,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT DEFAULT NULL,
    `setting_type` VARCHAR(20) NOT NULL DEFAULT 'string',
    `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0,
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
    
    PRIMARY KEY (`setting_id`),
    UNIQUE KEY `uk_school_settings_uuid` (`uuid`),
    UNIQUE KEY `uk_school_settings_school_key` (`school_id`, `setting_key`),
    
    CONSTRAINT `fk_school_settings_school_id` 
        FOREIGN KEY (`school_id`) 
        REFERENCES `schools` (`school_id`) 
        ON DELETE CASCADE 
        ON UPDATE CASCADE,
    
    INDEX `idx_school_settings_school_id` (`school_id`),
    INDEX `idx_school_settings_setting_key` (`setting_key`),
    INDEX `idx_school_settings_is_system` (`is_system`)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default school settings
INSERT INTO `school_settings` (`uuid`, `school_id`, `setting_key`, `setting_value`, `setting_type`, `is_system`) VALUES
    (UUID(), 1, 'default_language', 'en', 'string', 1),
    (UUID(), 1, 'default_timezone', 'Africa/Accra', 'string', 1),
    (UUID(), 1, 'date_format', 'Y-m-d', 'string', 1),
    (UUID(), 1, 'currency', 'GHS', 'string', 1),
    (UUID(), 1, 'academic_calendar_type', 'semester', 'string', 1);