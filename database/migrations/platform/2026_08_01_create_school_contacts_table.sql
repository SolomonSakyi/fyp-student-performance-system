-- ================================================
-- SCHOOL_CONTACTS TABLE MIGRATION
-- ================================================
-- This migration creates the school contacts table
-- for multiple contact entries per school
-- ================================================

CREATE TABLE IF NOT EXISTS `school_contacts` (
    `contact_id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT(20) UNSIGNED NOT NULL,
    `contact_type` VARCHAR(50) NOT NULL,
    `name` VARCHAR(200) DEFAULT NULL,
    `email` VARCHAR(200) DEFAULT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `position` VARCHAR(100) DEFAULT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` INT(11) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
    
    PRIMARY KEY (`contact_id`),
    UNIQUE KEY `uk_school_contacts_uuid` (`uuid`),
    
    CONSTRAINT `fk_school_contacts_school_id` 
        FOREIGN KEY (`school_id`) 
        REFERENCES `schools` (`school_id`) 
        ON DELETE CASCADE 
        ON UPDATE CASCADE,
    
    INDEX `idx_school_contacts_school_id` (`school_id`),
    INDEX `idx_school_contacts_contact_type` (`contact_type`),
    INDEX `idx_school_contacts_is_primary` (`is_primary`)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;