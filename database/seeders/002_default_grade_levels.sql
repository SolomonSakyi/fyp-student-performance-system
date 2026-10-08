-- ============================================
-- Default Grade Levels Seeder
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO `grade_levels` (`uuid`, `school_id`, `level_name`, `level_code`, `section`, `promotion_order`, `is_active`)
SELECT UUID(), id, 'Nursery 1', 'N1', 'Nursery', 1, 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Nursery 2', 'N2', 'Nursery', 2, 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'K.G. 1', 'KG1', 'Kindergarten', 3, 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'K.G. 2', 'KG2', 'Kindergarten', 4, 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Basic 1', 'B1', 'Primary', 5, 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Basic 2', 'B2', 'Primary', 6, 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Basic 3', 'B3', 'Primary', 7, 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Basic 4', 'B4', 'Primary', 8, 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Basic 5', 'B5', 'Primary', 9, 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Basic 6', 'B6', 'Primary', 10, 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Basic 7', 'B7', 'JHS', 11, 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Basic 8', 'B8', 'JHS', 12, 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Basic 9', 'B9', 'JHS', 13, 1 FROM `schools` LIMIT 1;

SET FOREIGN_KEY_CHECKS = 1;