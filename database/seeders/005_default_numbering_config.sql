-- ============================================
-- Default Numbering Configuration Seeder
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO `numbering_config` (
    `uuid`, `school_id`, `entity_type`, `prefix`, `suffix`,
    `last_number`, `padding_length`, `padding_char`, `format_pattern`,
    `reset_frequency`, `description`, `is_active`
)
SELECT UUID(), id, 'student', 'STU/', NULL, 0, 6, '0', 'STU/{YEAR}/{NUMBER}', 'yearly', 'Student Admission Numbers', 1
FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'staff', 'STF/', NULL, 0, 6, '0', 'STF/{YEAR}/{NUMBER}', 'yearly', 'Staff Numbers', 1
FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'assessment', 'ASS/', NULL, 0, 8, '0', 'ASS/{YEAR}/{NUMBER}', 'yearly', 'Assessment Codes', 1
FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'invoice', 'INV/', NULL, 0, 8, '0', 'INV/{YEAR}/{NUMBER}', 'yearly', 'Invoice Numbers', 1
FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'receipt', 'RCP/', NULL, 0, 8, '0', 'RCP/{YEAR}/{NUMBER}', 'yearly', 'Receipt Numbers', 1
FROM `schools` LIMIT 1;

SET FOREIGN_KEY_CHECKS = 1;