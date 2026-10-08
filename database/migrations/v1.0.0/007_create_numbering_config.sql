-- ============================================
-- Numbering Configuration
-- For automatic number generation (student IDs, staff IDs, etc.)
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Numbering Configuration Table
CREATE TABLE IF NOT EXISTS `numbering_config` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `entity_type` ENUM('student', 'staff', 'assessment', 'invoice', 'receipt', 'payment', 'other') NOT NULL,
    `prefix` VARCHAR(20) DEFAULT NULL,
    `suffix` VARCHAR(20) DEFAULT NULL,
    `last_number` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `padding_length` INT NOT NULL DEFAULT 6,
    `padding_char` CHAR(1) NOT NULL DEFAULT '0',
    `format_pattern` VARCHAR(100) DEFAULT NULL,
    `reset_frequency` ENUM('never', 'daily', 'monthly', 'yearly') NOT NULL DEFAULT 'never',
    `last_reset_date` DATE DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    UNIQUE KEY `uq_school_entity` (`school_id`, `entity_type`),
    CONSTRAINT `fk_nc_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Numbering History (Audit Trail)
CREATE TABLE IF NOT EXISTS `numbering_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `school_id` BIGINT UNSIGNED NOT NULL,
    `numbering_config_id` BIGINT UNSIGNED NOT NULL,
    `generated_number` VARCHAR(100) NOT NULL,
    `entity_id` BIGINT UNSIGNED DEFAULT NULL,
    `generated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `generated_by` BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    INDEX `idx_generated_number` (`generated_number`),
    CONSTRAINT `fk_nh_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_nh_config` FOREIGN KEY (`numbering_config_id`) REFERENCES `numbering_config` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;