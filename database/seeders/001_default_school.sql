-- ============================================
-- Default School Seeder
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Insert Default School
INSERT INTO `schools` (
    `uuid`, `school_name`, `school_code`, `school_type`, `level`,
    `grade_level_terminology`, `start_from`,
    `has_creche`, `has_nursery`, `has_kindergarten`, `has_primary`, `has_jhs`, `has_shs`,
    `address`, `city`, `state`, `country`, `phone`, `email`,
    `is_active`
) VALUES (
    UUID(), 'EduTrack Demo School', 'EDU001', 'private', 'combined',
    'Basic', 'Nursery 1',
    0, 1, 1, 1, 1, 0,
    '123 Education Street', 'Accra', 'Greater Accra', 'Ghana',
    '+233-123-456-789', 'info@edutrackdemo.edu.gh',
    1
);

-- Insert School Grade Configuration
INSERT INTO `school_grade_config` (`uuid`, `school_id`, `terminology`, `start_level`, `level_names`)
SELECT UUID(), id, 'Basic', 'Nursery 1', 
JSON_ARRAY(
    JSON_OBJECT('name', 'Nursery 1', 'code', 'N1', 'order', 1, 'section', 'Nursery'),
    JSON_OBJECT('name', 'Nursery 2', 'code', 'N2', 'order', 2, 'section', 'Nursery'),
    JSON_OBJECT('name', 'K.G. 1', 'code', 'KG1', 'order', 3, 'section', 'Kindergarten'),
    JSON_OBJECT('name', 'K.G. 2', 'code', 'KG2', 'order', 4, 'section', 'Kindergarten'),
    JSON_OBJECT('name', 'Basic 1', 'code', 'B1', 'order', 5, 'section', 'Primary'),
    JSON_OBJECT('name', 'Basic 2', 'code', 'B2', 'order', 6, 'section', 'Primary'),
    JSON_OBJECT('name', 'Basic 3', 'code', 'B3', 'order', 7, 'section', 'Primary'),
    JSON_OBJECT('name', 'Basic 4', 'code', 'B4', 'order', 8, 'section', 'Primary'),
    JSON_OBJECT('name', 'Basic 5', 'code', 'B5', 'order', 9, 'section', 'Primary'),
    JSON_OBJECT('name', 'Basic 6', 'code', 'B6', 'order', 10, 'section', 'Primary'),
    JSON_OBJECT('name', 'Basic 7', 'code', 'B7', 'order', 11, 'section', 'JHS'),
    JSON_OBJECT('name', 'Basic 8', 'code', 'B8', 'order', 12, 'section', 'JHS'),
    JSON_OBJECT('name', 'Basic 9', 'code', 'B9', 'order', 13, 'section', 'JHS')
)
FROM `schools` LIMIT 1;

SET FOREIGN_KEY_CHECKS = 1;