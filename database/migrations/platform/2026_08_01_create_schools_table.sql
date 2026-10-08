-- ================================================
-- SCHOOLS TABLE MIGRATION
-- ================================================
-- This migration creates the schools table and
-- establishes the tenant -> school relationship
-- ================================================

DROP TABLE IF EXISTS `schools`;

CREATE TABLE `schools` (
    -- Primary Key
    `school_id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    
    -- Tenant Relationship
    `tenant_id` BIGINT(20) UNSIGNED NOT NULL,
    
    -- School Identifiers
    `school_number` VARCHAR(50) NOT NULL,
    `school_code` VARCHAR(20) NOT NULL,
    `school_name` VARCHAR(200) NOT NULL,
    `legal_name` VARCHAR(200) DEFAULT NULL,
    `short_name` VARCHAR(50) DEFAULT NULL,
    
    -- School Classification
    `school_type` VARCHAR(50) NOT NULL DEFAULT 'private',
    `institution_level` VARCHAR(50) DEFAULT NULL,
    `registration_number` VARCHAR(100) DEFAULT NULL,
    
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
    
    -- Branding
    `logo_url` VARCHAR(500) DEFAULT NULL,
    `logo_path` VARCHAR(500) DEFAULT NULL,
    `favicon_url` VARCHAR(500) DEFAULT NULL,
    `primary_color` VARCHAR(7) DEFAULT '#4facfe',
    `secondary_color` VARCHAR(7) DEFAULT '#00f2fe',
    
    -- Status
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    
    -- Audit
    `created_by` BIGINT(20) UNSIGNED DEFAULT NULL,
    `updated_by` BIGINT(20) UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    
    -- Primary Key
    PRIMARY KEY (`school_id`),
    
    -- Unique Keys
    UNIQUE KEY `uk_schools_uuid` (`uuid`),
    UNIQUE KEY `uk_schools_tenant_code` (`tenant_id`, `school_code`),
    UNIQUE KEY `uk_schools_tenant_number` (`tenant_id`, `school_number`),
    
    -- Foreign Keys
    CONSTRAINT `fk_schools_tenant_id` 
        FOREIGN KEY (`tenant_id`) 
        REFERENCES `tenants` (`id`) 
        ON DELETE RESTRICT 
        ON UPDATE RESTRICT,
    
    CONSTRAINT `fk_schools_country_id` 
        FOREIGN KEY (`country_id`) 
        REFERENCES `countries` (`id`) 
        ON DELETE SET NULL 
        ON UPDATE CASCADE,
    
    CONSTRAINT `fk_schools_created_by` 
        FOREIGN KEY (`created_by`) 
        REFERENCES `platform_users` (`id`) 
        ON DELETE SET NULL 
        ON UPDATE CASCADE,
    
    CONSTRAINT `fk_schools_updated_by` 
        FOREIGN KEY (`updated_by`) 
        REFERENCES `platform_users` (`id`) 
        ON DELETE SET NULL 
        ON UPDATE CASCADE,
    
    -- Indexes
    INDEX `idx_schools_tenant_id` (`tenant_id`),
    INDEX `idx_schools_school_number` (`school_number`),
    INDEX `idx_schools_school_code` (`school_code`),
    INDEX `idx_schools_school_name` (`school_name`),
    INDEX `idx_schools_status` (`status`),
    INDEX `idx_schools_school_type` (`school_type`),
    INDEX `idx_schools_institution_level` (`institution_level`),
    INDEX `idx_schools_country_id` (`country_id`),
    INDEX `idx_schools_created_at` (`created_at`)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================
-- CONSTRAINT REGISTER - schools
-- ================================================
-- 1. fk_schools_tenant_id: Ensures every school belongs to a valid tenant
-- 2. fk_schools_country_id: Ensures country reference is valid
-- 3. fk_schools_created_by: Tracks who created the school
-- 4. fk_schools_updated_by: Tracks who last updated the school
-- ================================================

-- ================================================
-- INDEX REGISTER - schools
-- ================================================
-- 1. idx_schools_tenant_id: Optimizes tenant-based queries
-- 2. idx_schools_school_number: Fast lookup by school number
-- 3. idx_schools_school_code: Fast lookup by school code
-- 4. idx_schools_school_name: Optimizes search by name
-- 5. idx_schools_status: Optimizes status filtering
-- 6. idx_schools_school_type: Optimizes type-based filtering
-- 7. idx_schools_institution_level: Optimizes level-based filtering
-- 8. idx_schools_country_id: Optimizes country-based filtering
-- 9. idx_schools_created_at: Optimizes date-based sorting
-- ================================================