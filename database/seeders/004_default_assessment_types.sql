-- ============================================
-- Default Assessment Components and Configs
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- 1. Default Assessment Components
-- ============================================
INSERT INTO `assessment_components` (
    `uuid`, `school_id`, `component_name`, `component_code`, `component_type`, 
    `default_weight`, `max_score`, `display_order`, `is_active`
)
SELECT UUID(), id, 'Continuous Assessment 1', 'CA1', 'ca', 10.00, 100.00, 1, 1
FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Continuous Assessment 2', 'CA2', 'ca', 10.00, 100.00, 2, 1
FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Continuous Assessment 3', 'CA3', 'ca', 10.00, 100.00, 3, 1
FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'End of Term Examination', 'EOT', 'exam', 70.00, 100.00, 4, 1
FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Mid-Term Examination', 'MID', 'exam', 30.00, 100.00, 5, 1
FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Project Work', 'PROJ', 'project', 20.00, 100.00, 6, 1
FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Practical Assessment', 'PRAC', 'practical', 20.00, 100.00, 7, 1
FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Class Quiz', 'QUIZ', 'quiz', 5.00, 20.00, 8, 1
FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Homework Assignment', 'HW', 'homework', 5.00, 20.00, 9, 1
FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Class Assignment', 'ASSIGN', 'assignment', 10.00, 100.00, 10, 1
FROM `schools` LIMIT 1
UNION ALL
SELECT UUID(), id, 'Class Test', 'TEST', 'test', 10.00, 50.00, 11, 1
FROM `schools` LIMIT 1;

-- ============================================
-- 2. Default Assessment Config (Standard - 30% CA, 70% Exam)
-- ============================================
INSERT INTO `assessment_configs` (
    `uuid`, `school_id`, `grade_level_id`, `config_name`, `config_code`, 
    `description`, `is_default`, `is_active`
)
SELECT UUID(), s.id, NULL, 'Standard Assessment Config', 'STANDARD',
    '30% Continuous Assessment, 70% End of Term Examination', 1, 1
FROM `schools` s LIMIT 1;

-- ============================================
-- 3. Alternative Assessment Config (50% CA, 50% Exam)
-- ============================================
INSERT INTO `assessment_configs` (
    `uuid`, `school_id`, `grade_level_id`, `config_name`, `config_code`, 
    `description`, `is_default`, `is_active`
)
SELECT UUID(), s.id, NULL, 'Balanced Assessment Config', 'BALANCED',
    '50% Continuous Assessment, 50% End of Term Examination', 0, 1
FROM `schools` s LIMIT 1;

-- ============================================
-- 4. Link Components to Standard Config (30% CA, 70% Exam)
-- ============================================
INSERT INTO `config_components` (
    `uuid`, `school_id`, `config_id`, `component_id`, 
    `weight_percentage`, `is_required`, `display_order`, `is_active`
)
SELECT 
    UUID(), 
    s.id, 
    (SELECT id FROM assessment_configs WHERE config_code = 'STANDARD' AND school_id = s.id LIMIT 1),
    ac.id,
    CASE 
        WHEN ac.component_code IN ('CA1', 'CA2', 'CA3') THEN 10.00
        WHEN ac.component_code = 'EOT' THEN 70.00
        WHEN ac.component_code IN ('PROJ', 'PRAC') THEN 0.00  -- Not used in this config
        WHEN ac.component_code IN ('QUIZ', 'HW', 'ASSIGN', 'TEST') THEN 0.00
        ELSE 0.00
    END,
    CASE 
        WHEN ac.component_code IN ('CA1', 'CA2', 'CA3', 'EOT') THEN 1
        ELSE 0
    END,
    CASE 
        WHEN ac.component_code = 'CA1' THEN 1
        WHEN ac.component_code = 'CA2' THEN 2
        WHEN ac.component_code = 'CA3' THEN 3
        WHEN ac.component_code = 'EOT' THEN 4
        ELSE 99
    END,
    1
FROM `schools` s
CROSS JOIN `assessment_components` ac
WHERE ac.school_id = s.id
AND ac.component_code IN ('CA1', 'CA2', 'CA3', 'EOT');

-- ============================================
-- 5. Link Components to Balanced Config (50% CA, 50% Exam)
-- ============================================
INSERT INTO `config_components` (
    `uuid`, `school_id`, `config_id`, `component_id`, 
    `weight_percentage`, `is_required`, `display_order`, `is_active`
)
SELECT 
    UUID(), 
    s.id, 
    (SELECT id FROM assessment_configs WHERE config_code = 'BALANCED' AND school_id = s.id LIMIT 1),
    ac.id,
    CASE 
        WHEN ac.component_code = 'CA1' THEN 16.67
        WHEN ac.component_code = 'CA2' THEN 16.67
        WHEN ac.component_code = 'CA3' THEN 16.67
        WHEN ac.component_code = 'EOT' THEN 50.00
        ELSE 0.00
    END,
    CASE 
        WHEN ac.component_code IN ('CA1', 'CA2', 'CA3', 'EOT') THEN 1
        ELSE 0
    END,
    CASE 
        WHEN ac.component_code = 'CA1' THEN 1
        WHEN ac.component_code = 'CA2' THEN 2
        WHEN ac.component_code = 'CA3' THEN 3
        WHEN ac.component_code = 'EOT' THEN 4
        ELSE 99
    END,
    1
FROM `schools` s
CROSS JOIN `assessment_components` ac
WHERE ac.school_id = s.id
AND ac.component_code IN ('CA1', 'CA2', 'CA3', 'EOT');

SET FOREIGN_KEY_CHECKS = 1;