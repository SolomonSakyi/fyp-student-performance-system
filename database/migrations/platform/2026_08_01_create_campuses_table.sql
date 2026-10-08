-- ================================================
-- CAMPUSES TABLE MIGRATION
-- ================================================
-- This migration creates the campuses table and
-- establishes the school -> campus relationship
-- ================================================

DROP TABLE IF EXISTS `campuses`;

CREATE TABLE `campuses` (
    -- Primary Key
    `campus_id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    
    -- Relationships
    `tenant_id` BIGINT(20) UNSIGNED NOT NULL,
    `school_id` BIGINT(20) UNSIGNED NOT NULL,
    
    -- Campus Identifiers
    `campus_number` VARCHAR(50) NOT NULL,
    `campus_code` VARCHAR(20) NOT NULL,
    `campus_name` VARCHAR(200) NOT NULL,
    `short_name` VARCHAR(50) DEFAULT NULL,
    
    -- Campus Classification
    `campus_type` VARCHAR(50) NOT NULL DEFAULT 'main',
    
    -- Contact Information
    `email` VARCHAR(200) DEFAULT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `secondary_phone` VARCHAR(20) DEFAULT NULL,
    `website` VARCHAR(200) DEFAULT NULL,
    
    -- Address Information
    `address_line1` VARCHAR(200) DEFAULT NULL,
    `address_line2` VARCHAR(200) DEFAULT NULL,
    `city` VARCHAR(100) DEFAULT NULL,
    `district` VARCHAR(100) DEFAULT NULL,
    `region` VARCHAR(100) DEFAULT NULL,
    `country_id` INT(11) UNSIGNED DEFAULT NULL,
    `postal_code` VARCHAR(20) DEFAULT NULL,
    `digital_address` VARCHAR(50) DEFAULT NULL,
    `latitude` DECIMAL(10, 8) DEFAULT NULL,
    `longitude` DECIMAL(11, 8) DEFAULT NULL,
    
    -- Location Specifics
    `timezone` VARCHAR(50) DEFAULT NULL,
    `operating_hours` TEXT DEFAULT NULL,
    
    -- Branding
    `logo_url` VARCHAR(500) DEFAULT NULL,
    `logo_path` VARCHAR(500) DEFAULT NULL,
    
    -- Status
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    
    -- Audit
    `created_by` BIGINT(20) UNSIGNED DEFAULT NULL,
    `updated_by` BIGINT(20) UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    
    -- Primary Key
    PRIMARY KEY (`campus_id`),
    
    -- Unique Keys
    UNIQUE KEY `uk_campuses_uuid` (`uuid`),
    UNIQUE KEY `uk_campuses_school_code` (`school_id`, `campus_code`),
    UNIQUE KEY `uk_campuses_school_number` (`school_id`, `campus_number`),
    
    -- Foreign Keys
    CONSTRAINT `fk_campuses_tenant_id` 
        FOREIGN KEY (`tenant_id`) 
        REFERENCES `tenants` (`id`) 
        ON DELETE RESTRICT 
        ON UPDATE RESTRICT,
    
    CONSTRAINT `fk_campuses_school_id` 
        FOREIGN KEY (`school_id`) 
        REFERENCES `schools` (`school_id`) 
        ON DELETE RESTRICT 
        ON UPDATE RESTRICT,
    
    CONSTRAINT `fk_campuses_country_id` 
        FOREIGN KEY (`country_id`) 
        REFERENCES `countries` (`id`) 
        ON DELETE SET NULL 
        ON UPDATE CASCADE,
    
    CONSTRAINT `fk_campuses_created_by` 
        FOREIGN KEY (`created_by`) 
        REFERENCES `platform_users` (`id`) 
        ON DELETE SET NULL 
        ON UPDATE CASCADE,
    
    CONSTRAINT `fk_campuses_updated_by` 
        FOREIGN KEY (`updated_by`) 
        REFERENCES `platform_users` (`id`) 
        ON DELETE SET NULL 
        ON UPDATE CASCADE,
    
    -- Indexes
    INDEX `idx_campuses_tenant_id` (`tenant_id`),
    INDEX `idx_campuses_school_id` (`school_id`),
    INDEX `idx_campuses_campus_number` (`campus_number`),
    INDEX `idx_campuses_campus_code` (`campus_code`),
    INDEX `idx_campuses_campus_name` (`campus_name`),
    INDEX `idx_campuses_status` (`status`),
    INDEX `idx_campuses_campus_type` (`campus_type`),
    INDEX `idx_campuses_country_id` (`country_id`),
    INDEX `idx_campuses_created_at` (`created_at`)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================
-- CONSTRAINT REGISTER - campuses
-- ================================================
-- 1. fk_campuses_tenant_id: Ensures every campus belongs to a valid tenant
-- 2. fk_campuses_school_id: Ensures every campus belongs to a valid school
-- 3. fk_campuses_country_id: Ensures country reference is valid
-- 4. fk_campuses_created_by: Tracks who created the campus
-- 5. fk_campuses_updated_by: Tracks who last updated the campus
-- ================================================

-- ================================================
-- INDEX REGISTER - campuses
-- ================================================
-- 1. idx_campuses_tenant_id: Optimizes tenant-based queries
-- 2. idx_campuses_school_id: Optimizes school-based queries
-- 3. idx_campuses_campus_number: Fast lookup by campus number
-- 4. idx_campuses_campus_code: Fast lookup by campus code
-- 5. idx_campuses_campus_name: Optimizes search by name
-- 6. idx_campuses_status: Optimizes status filtering
-- 7. idx_campuses_campus_type: Optimizes type-based filtering
-- 8. idx_campuses_country_id: Optimizes country-based filtering
-- 9. idx_campuses_created_at: Optimizes date-based sorting
-- ================================================