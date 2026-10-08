-- ================================================
-- SCHOOL_LEVELS TABLE MIGRATION
-- ================================================
-- This migration creates the school-levels junction
-- table for many-to-many relationship between
-- schools and institution levels
-- ================================================

-- First, check if institution_levels table exists
-- If not, create it

CREATE TABLE IF NOT EXISTS `institution_levels` (
    `level_id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `level_name` VARCHAR(50) NOT NULL,
    `level_code` VARCHAR(20) NOT NULL,
    `level_short` VARCHAR(10) DEFAULT NULL,
    `sort_order` INT(11) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `description` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
    
    PRIMARY KEY (`level_id`),
    UNIQUE KEY `uk_institution_levels_uuid` (`uuid`),
    UNIQUE KEY `uk_institution_levels_code` (`level_code`),
    UNIQUE KEY `uk_institution_levels_name` (`level_name`),
    INDEX `idx_institution_levels_sort_order` (`sort_order`),
    INDEX `idx_institution_levels_is_active` (`is_active`)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default institution levels
INSERT INTO `institution_levels` (`uuid`, `level_name`, `level_code`, `level_short`, `sort_order`) VALUES
    (UUID(), 'Creche', 'CRECHE', 'CR', 1),
    (UUID(), 'Nursery', 'NURSERY', 'NR', 2),
    (UUID(), 'Preschool', 'PRESCHOOL', 'PS', 3),
    (UUID(), 'Kindergarten', 'KINDERGARTEN', 'KG', 4),
    (UUID(), 'Primary', 'PRIMARY', 'PR', 5),
    (UUID(), 'Junior High School', 'JHS', 'JHS', 6),
    (UUID(), 'Senior High School', 'SHS', 'SHS', 7),
    (UUID(), 'Secondary', 'SECONDARY', 'SEC', 8),
    (UUID(), 'Tertiary', 'TERTIARY', 'TER', 9);

-- Create school_levels junction table
CREATE TABLE IF NOT EXISTS `school_levels` (
    `school_level_id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `school_id` BIGINT(20) UNSIGNED NOT NULL,
    `level_id` INT(11) UNSIGNED NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
    
    PRIMARY KEY (`school_level_id`),
    UNIQUE KEY `uk_school_levels_school_level` (`school_id`, `level_id`),
    
    CONSTRAINT `fk_school_levels_school_id` 
        FOREIGN KEY (`school_id`) 
        REFERENCES `schools` (`school_id`) 
        ON DELETE CASCADE 
        ON UPDATE CASCADE,
    
    CONSTRAINT `fk_school_levels_level_id` 
        FOREIGN KEY (`level_id`) 
        REFERENCES `institution_levels` (`level_id`) 
        ON DELETE RESTRICT 
        ON UPDATE CASCADE,
    
    INDEX `idx_school_levels_school_id` (`school_id`),
    INDEX `idx_school_levels_level_id` (`level_id`),
    INDEX `idx_school_levels_is_active` (`is_active`)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;