-- ============================================
-- Default Subjects Seeder
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Insert Subject Groups
INSERT INTO `subject_groups` (`uuid`, `school_id`, `group_name`, `group_code`, `group_type`, `sort_order`, `is_active`)
SELECT UUID(), id, 'Core Subjects', 'CORE', 'core', 1, 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Science Electives', 'SCI-ELEC', 'elective', 2, 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Arts Electives', 'ARTS-ELEC', 'elective', 3, 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Vocational Subjects', 'VOC', 'vocational', 4, 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Optional Subjects', 'OPT', 'custom', 5, 1 FROM `schools` LIMIT 1;

-- Insert Subject Categories
INSERT INTO `subject_categories` (`uuid`, `school_id`, `category_name`, `category_code`, `is_active`)
SELECT UUID(), id, 'Languages', 'LANG', 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Mathematics', 'MATH', 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Sciences', 'SCI', 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Social Studies', 'SOC', 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'ICT', 'ICT', 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Creative Arts', 'ARTS', 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Religious & Moral Education', 'RME', 1 FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Vocational', 'VOC', 1 FROM `schools` LIMIT 1;

-- Insert Core Subjects
INSERT INTO `subjects` (
    `uuid`, `school_id`, `subject_name`, `subject_code`, `subject_type`,
    `is_core`, `is_elective`, `category`, `subject_group_id`, `display_order`, `is_active`
)
SELECT UUID(), s.id, 'English Language', 'ENG', 'core', 1, 0, 'LANG', sg.id, 1, 1
FROM `schools` s
CROSS JOIN `subject_groups` sg
WHERE sg.group_code = 'CORE'
LIMIT 1
UNION ALL
SELECT UUID(), s.id, 'Mathematics', 'MATH', 'core', 1, 0, 'MATH', sg.id, 2, 1
FROM `schools` s
CROSS JOIN `subject_groups` sg
WHERE sg.group_code = 'CORE'
LIMIT 1
UNION ALL
SELECT UUID(), s.id, 'Integrated Science', 'SCI', 'core', 1, 0, 'SCI', sg.id, 3, 1
FROM `schools` s
CROSS JOIN `subject_groups` sg
WHERE sg.group_code = 'CORE'
LIMIT 1
UNION ALL
SELECT UUID(), s.id, 'Social Studies', 'SOC', 'core', 1, 0, 'SOC', sg.id, 4, 1
FROM `schools` s
CROSS JOIN `subject_groups` sg
WHERE sg.group_code = 'CORE'
LIMIT 1;

-- Insert Elective Subjects
INSERT INTO `subjects` (
    `uuid`, `school_id`, `subject_name`, `subject_code`, `subject_type`,
    `is_core`, `is_elective`, `category`, `subject_group_id`, `display_order`, `is_active`
)
SELECT UUID(), s.id, 'ICT', 'ICT', 'elective', 0, 1, 'ICT', sg.id, 5, 1
FROM `schools` s
CROSS JOIN `subject_groups` sg
WHERE sg.group_code = 'SCI-ELEC'
LIMIT 1
UNION ALL
SELECT UUID(), s.id, 'French Language', 'FRENCH', 'elective', 0, 1, 'LANG', sg.id, 6, 1
FROM `schools` s
CROSS JOIN `subject_groups` sg
WHERE sg.group_code = 'ARTS-ELEC'
LIMIT 1
UNION ALL
SELECT UUID(), s.id, 'Ghanaian Language (Twi)', 'TWI', 'elective', 0, 1, 'LANG', sg.id, 7, 1
FROM `schools` s
CROSS JOIN `subject_groups` sg
WHERE sg.group_code = 'ARTS-ELEC'
LIMIT 1
UNION ALL
SELECT UUID(), s.id, 'Ghanaian Language (Ga)', 'GA', 'elective', 0, 1, 'LANG', sg.id, 8, 1
FROM `schools` s
CROSS JOIN `subject_groups` sg
WHERE sg.group_code = 'ARTS-ELEC'
LIMIT 1
UNION ALL
SELECT UUID(), s.id, 'Religious & Moral Education', 'RME', 'elective', 0, 1, 'RME', sg.id, 9, 1
FROM `schools` s
CROSS JOIN `subject_groups` sg
WHERE sg.group_code = 'ARTS-ELEC'
LIMIT 1
UNION ALL
SELECT UUID(), s.id, 'Creative Arts', 'ARTS', 'elective', 0, 1, 'ARTS', sg.id, 10, 1
FROM `schools` s
CROSS JOIN `subject_groups` sg
WHERE sg.group_code = 'ARTS-ELEC'
LIMIT 1;

SET FOREIGN_KEY_CHECKS = 1;