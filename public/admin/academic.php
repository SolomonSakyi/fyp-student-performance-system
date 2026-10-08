<?php
require_once __DIR__ . '/../../app/helpers/AdminHelper.php';
require_once __DIR__ . '/../../app/helpers/DatabaseHelper.php';
AdminHelper::requireLogin();
$db = DatabaseHelper::getInstance();

$action = $_GET['action'] ?? 'list';
$message = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
$editId = $_GET['edit'] ?? null;
$activeTab = $_GET['tab'] ?? 'years';

// ============================================
// HELPER FUNCTIONS
// ============================================
function generateLevelNames($terminology, $hasCreche, $hasNursery, $hasKindergarten, $hasPrimary, $hasJhs, $hasShs) {
    $levels = [];
    $order = 1;
    
    if ($hasCreche) {
        $levels[] = ['name' => 'Creche', 'code' => 'CR', 'order' => $order++, 'section' => 'Creche'];
    }
    if ($hasNursery) {
        $levels[] = ['name' => 'Nursery 1', 'code' => 'N1', 'order' => $order++, 'section' => 'Nursery'];
        $levels[] = ['name' => 'Nursery 2', 'code' => 'N2', 'order' => $order++, 'section' => 'Nursery'];
    }
    if ($hasKindergarten) {
        $levels[] = ['name' => 'K.G. 1', 'code' => 'KG1', 'order' => $order++, 'section' => 'Kindergarten'];
        $levels[] = ['name' => 'K.G. 2', 'code' => 'KG2', 'order' => $order++, 'section' => 'Kindergarten'];
    }
    if ($hasPrimary) {
        for ($i = 1; $i <= 6; $i++) {
            $levels[] = [
                'name' => $terminology . ' ' . $i, 
                'code' => substr($terminology, 0, 1) . $i, 
                'order' => $order++, 
                'section' => 'Primary'
            ];
        }
    }
    if ($hasJhs) {
        for ($i = 1; $i <= 3; $i++) {
            $levels[] = [
                'name' => $terminology . ' ' . ($i + 6), 
                'code' => substr($terminology, 0, 1) . ($i + 6), 
                'order' => $order++, 
                'section' => 'JHS'
            ];
        }
    }
    if ($hasShs) {
        for ($i = 1; $i <= 3; $i++) {
            $levels[] = ['name' => 'SHS ' . $i, 'code' => 'SHS' . $i, 'order' => $order++, 'section' => 'SHS'];
        }
    }
    return $levels;
}

function regenerateGradeLevels($db, $levelNames) {
    // Get existing grade levels
    $existingLevels = $db->fetchAll("SELECT * FROM grade_levels WHERE school_id = 1 ORDER BY promotion_order");
    
    // Track which levels have been updated
    $updatedIds = [];
    
    // For each new level, find matching existing or create new
    foreach ($levelNames as $newLevel) {
        // Try to find matching level by promotion_order first (more reliable)
        $existing = $db->fetchOne("
            SELECT * FROM grade_levels 
            WHERE promotion_order = ? AND school_id = 1
        ", [$newLevel['order']]);
        
        if ($existing) {
            // Update existing level
            $sql = "UPDATE grade_levels SET 
                level_name = ?,
                level_code = ?,
                section = ?,
                is_active = 1,
                updated_at = NOW()
                WHERE id = ? AND school_id = 1";
            $db->query($sql, [$newLevel['name'], $newLevel['code'], $newLevel['section'], $existing['id']]);
            $updatedIds[] = $existing['id'];
        } else {
            // Try to find by name match (for previously renamed levels)
            $existingByName = $db->fetchOne("
                SELECT id FROM grade_levels 
                WHERE level_name = ? AND school_id = 1
            ", [$newLevel['name']]);
            
            if ($existingByName) {
                // Update existing level by name
                $sql = "UPDATE grade_levels SET 
                    level_code = ?,
                    promotion_order = ?,
                    section = ?,
                    is_active = 1,
                    updated_at = NOW()
                    WHERE id = ? AND school_id = 1";
                $db->query($sql, [$newLevel['code'], $newLevel['order'], $newLevel['section'], $existingByName['id']]);
                $updatedIds[] = $existingByName['id'];
            } else {
                // Create new level
                $sql = "INSERT INTO grade_levels (uuid, school_id, level_name, level_code, promotion_order, section, is_active) 
                    VALUES (UUID(), 1, ?, ?, ?, ?, 1)";
                $db->query($sql, [$newLevel['name'], $newLevel['code'], $newLevel['order'], $newLevel['section']]);
                $updatedIds[] = $db->lastInsertId();
            }
        }
    }
    
    // Deactivate or delete old levels that are no longer needed
    foreach ($existingLevels as $oldLevel) {
        if (!in_array($oldLevel['id'], $updatedIds)) {
            // Check if this level is being used in class sections
            $used = $db->fetchOne("
                SELECT COUNT(*) as count FROM class_sections 
                WHERE grade_level_id = ? AND is_active = 1
            ", [$oldLevel['id']]);
            
            if ($used && $used['count'] > 0) {
                // Mark as inactive instead of deleting
                $db->query("UPDATE grade_levels SET is_active = 0 WHERE id = ? AND school_id = 1", [$oldLevel['id']]);
            } else {
                // Safe to delete (no active class sections using it)
                $db->query("DELETE FROM grade_levels WHERE id = ? AND school_id = 1", [$oldLevel['id']]);
            }
        }
    }
}

// ============================================
// SYNC CLASS NAMES WITH TERMINOLOGY
// ============================================
function syncClassNamesWithTerminology($db, $terminology) {
    // Get all active grade levels
    $gradeLevels = $db->fetchAll("
        SELECT id, level_name, promotion_order 
        FROM grade_levels 
        WHERE school_id = 1 AND is_active = 1 
        ORDER BY promotion_order ASC
    ");
    
    // Get current academic year
    $currentYear = $db->fetchOne("SELECT id FROM academic_years WHERE is_current = 1 AND school_id = 1");
    $yearId = $currentYear ? $currentYear['id'] : 1;
    
    $updated = 0;
    $created = 0;
    
    foreach ($gradeLevels as $level) {
        // Generate section name based on terminology
        $sectionName = $level['level_name'] . 'A';
        
        // Check if class exists
        $existing = $db->fetchOne("
            SELECT id, section_name FROM class_sections 
            WHERE grade_level_id = ? AND academic_year_id = ? AND school_id = 1
        ", [$level['id'], $yearId]);
        
        if ($existing) {
            // Update existing class name if different
            if ($existing['section_name'] !== $sectionName) {
                $db->query("
                    UPDATE class_sections 
                    SET section_name = ? 
                    WHERE id = ?
                ", [$sectionName, $existing['id']]);
                $updated++;
            }
        } else {
            // Create default class
            $db->query("
                INSERT INTO class_sections (
                    uuid, school_id, grade_level_id, academic_year_id,
                    section_code, section_name, is_active
                ) VALUES (UUID(), 1, ?, ?, 'A', ?, 1)
            ", [$level['id'], $yearId, $sectionName]);
            $created++;
        }
    }
    
    return ['updated' => $updated, 'created' => $created];
}

// ============================================
// SCHOOL GRADE CONFIGURATION
// ============================================
if (isset($_POST['save_grade_config'])) {
    $terminology = $_POST['terminology'] ?? 'Basic';
    $startLevel = $_POST['start_level'] ?? 'Nursery 1';
    $hasCreche = isset($_POST['has_creche']) ? 1 : 0;
    $hasNursery = isset($_POST['has_nursery']) ? 1 : 0;
    $hasKindergarten = isset($_POST['has_kindergarten']) ? 1 : 0;
    $hasPrimary = isset($_POST['has_primary']) ? 1 : 0;
    $hasJhs = isset($_POST['has_jhs']) ? 1 : 0;
    $hasShs = isset($_POST['has_shs']) ? 1 : 0;
    
    try {
        // Update schools table
        $sql = "UPDATE schools SET 
            grade_level_terminology = ?,
            start_from = ?,
            has_creche = ?,
            has_nursery = ?,
            has_kindergarten = ?,
            has_primary = ?,
            has_jhs = ?,
            has_shs = ?
            WHERE id = 1";
        $db->query($sql, [$terminology, $startLevel, $hasCreche, $hasNursery, $hasKindergarten, $hasPrimary, $hasJhs, $hasShs]);
        
        // Generate level names based on configuration
        $levelNames = generateLevelNames($terminology, $hasCreche, $hasNursery, $hasKindergarten, $hasPrimary, $hasJhs, $hasShs);
        
        // Update or insert into school_grade_config
        $check = $db->fetchOne("SELECT id FROM school_grade_config WHERE school_id = 1");
        if ($check) {
            $sql = "UPDATE school_grade_config SET terminology = ?, start_level = ?, level_names = ? WHERE school_id = 1";
            $db->query($sql, [$terminology, $startLevel, json_encode($levelNames)]);
        } else {
            $sql = "INSERT INTO school_grade_config (uuid, school_id, terminology, start_level, level_names) VALUES (UUID(), 1, ?, ?, ?)";
            $db->query($sql, [$terminology, $startLevel, json_encode($levelNames)]);
        }
        
        // Regenerate grade_levels table based on new configuration
        regenerateGradeLevels($db, $levelNames);
        
        // Sync class names with new terminology
        $syncResult = syncClassNamesWithTerminology($db, $terminology);
        
        header('Location: /admin/academic.php?tab=gradelevels&msg=Grade configuration updated! ' . $syncResult['updated'] . ' classes updated, ' . $syncResult['created'] . ' classes created.');
        exit;
    } catch (Exception $e) {
        header('Location: /admin/academic.php?tab=gradelevels&error=' . urlencode($e->getMessage()));
        exit;
    }
}

// ============================================
// ACADEMIC YEARS HANDLING
// ============================================
if (isset($_POST['save_year'])) {
    $yearName = trim($_POST['year_name'] ?? '');
    $yearCode = trim($_POST['year_code'] ?? '');
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $editId = $_POST['edit_id'] ?? null;
    
    if ($yearName && $yearCode && $startDate && $endDate) {
        try {
            if ($editId) {
                $sql = "UPDATE academic_years SET year_name = ?, year_code = ?, start_date = ?, end_date = ?, updated_at = NOW() WHERE id = ? AND school_id = 1";
                $db->query($sql, [$yearName, $yearCode, $startDate, $endDate, $editId]);
                $msg = 'Academic year updated successfully!';
            } else {
                $sql = "INSERT INTO academic_years (uuid, school_id, year_name, year_code, start_date, end_date, status, is_active) VALUES (UUID(), 1, ?, ?, ?, ?, 'Upcoming', 1)";
                $db->query($sql, [$yearName, $yearCode, $startDate, $endDate]);
                $msg = 'Academic year added successfully!';
            }
            header('Location: /admin/academic.php?tab=years&msg=' . urlencode($msg));
            exit;
        } catch (Exception $e) {
            header('Location: /admin/academic.php?tab=years&error=' . urlencode($e->getMessage()));
            exit;
        }
    } else {
        header('Location: /admin/academic.php?tab=years&error=Please fill in all fields.');
        exit;
    }
}

if (isset($_GET['set_current_year']) && $_GET['set_current_year']) {
    try {
        $yearId = $_GET['set_current_year'];
        $db->query("UPDATE academic_years SET is_current = 0 WHERE school_id = 1");
        $db->query("UPDATE academic_years SET is_current = 1, status = 'Current' WHERE id = ? AND school_id = 1", [$yearId]);
        header('Location: /admin/academic.php?tab=years&msg=Current year set successfully!');
        exit;
    } catch (Exception $e) {
        header('Location: /admin/academic.php?tab=years&error=' . urlencode($e->getMessage()));
        exit;
    }
}

if (isset($_GET['toggle_year'])) {
    try {
        $yearId = $_GET['toggle_year'];
        $current = $db->fetchOne("SELECT is_active FROM academic_years WHERE id = ?", [$yearId]);
        $newStatus = $current['is_active'] ? 0 : 1;
        $db->query("UPDATE academic_years SET is_active = ? WHERE id = ?", [$newStatus, $yearId]);
        header('Location: /admin/academic.php?tab=years&msg=Status updated successfully!');
        exit;
    } catch (Exception $e) {
        header('Location: /admin/academic.php?tab=years&error=' . urlencode($e->getMessage()));
        exit;
    }
}

// ============================================
// ACADEMIC TERMS HANDLING
// ============================================
if (isset($_POST['save_term'])) {
    $termName = trim($_POST['term_name'] ?? '');
    $termNumber = $_POST['term_number'] ?? 0;
    $yearId = $_POST['academic_year_id'] ?? 0;
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $editId = $_POST['edit_id'] ?? null;
    
    if ($termName && $termNumber && $yearId && $startDate && $endDate) {
        try {
            if ($editId) {
                $sql = "UPDATE academic_terms SET term_name = ?, term_number = ?, academic_year_id = ?, start_date = ?, end_date = ?, updated_at = NOW() WHERE id = ? AND school_id = 1";
                $db->query($sql, [$termName, $termNumber, $yearId, $startDate, $endDate, $editId]);
                $msg = 'Term updated successfully!';
            } else {
                $sql = "INSERT INTO academic_terms (uuid, school_id, academic_year_id, term_name, term_number, start_date, end_date, status, is_active) VALUES (UUID(), 1, ?, ?, ?, ?, ?, 'Upcoming', 1)";
                $db->query($sql, [$yearId, $termName, $termNumber, $startDate, $endDate]);
                $msg = 'Term added successfully!';
            }
            header('Location: /admin/academic.php?tab=terms&msg=' . urlencode($msg));
            exit;
        } catch (Exception $e) {
            header('Location: /admin/academic.php?tab=terms&error=' . urlencode($e->getMessage()));
            exit;
        }
    } else {
        header('Location: /admin/academic.php?tab=terms&error=Please fill in all fields.');
        exit;
    }
}

if (isset($_GET['set_current_term']) && $_GET['set_current_term']) {
    try {
        $termId = $_GET['set_current_term'];
        $db->query("UPDATE academic_terms SET is_current = 0 WHERE school_id = 1");
        $db->query("UPDATE academic_terms SET is_current = 1, status = 'Current' WHERE id = ? AND school_id = 1", [$termId]);
        header('Location: /admin/academic.php?tab=terms&msg=Current term set successfully!');
        exit;
    } catch (Exception $e) {
        header('Location: /admin/academic.php?tab=terms&error=' . urlencode($e->getMessage()));
        exit;
    }
}

if (isset($_GET['toggle_term'])) {
    try {
        $termId = $_GET['toggle_term'];
        $current = $db->fetchOne("SELECT is_active FROM academic_terms WHERE id = ?", [$termId]);
        $newStatus = $current['is_active'] ? 0 : 1;
        $db->query("UPDATE academic_terms SET is_active = ? WHERE id = ?", [$newStatus, $termId]);
        header('Location: /admin/academic.php?tab=terms&msg=Status updated successfully!');
        exit;
    } catch (Exception $e) {
        header('Location: /admin/academic.php?tab=terms&error=' . urlencode($e->getMessage()));
        exit;
    }
}

// ============================================
// GRADE LEVELS HANDLING (Manual Add/Edit)
// ============================================
if (isset($_POST['save_grade_level'])) {
    $levelName = trim($_POST['level_name'] ?? '');
    $levelCode = trim($_POST['level_code'] ?? '');
    $promotionOrder = $_POST['promotion_order'] ?? 0;
    $section = $_POST['section'] ?? 'Primary';
    $editId = $_POST['edit_id'] ?? null;
    
    if ($levelName && $promotionOrder > 0) {
        try {
            if ($editId) {
                $sql = "UPDATE grade_levels SET level_name = ?, level_code = ?, promotion_order = ?, section = ?, updated_at = NOW() WHERE id = ? AND school_id = 1";
                $db->query($sql, [$levelName, $levelCode, $promotionOrder, $section, $editId]);
                $msg = 'Grade level updated successfully!';
            } else {
                $sql = "INSERT INTO grade_levels (uuid, school_id, level_name, level_code, promotion_order, section, is_active) VALUES (UUID(), 1, ?, ?, ?, ?, 1)";
                $db->query($sql, [$levelName, $levelCode, $promotionOrder, $section]);
                $msg = 'Grade level added successfully!';
            }
            header('Location: /admin/academic.php?tab=gradelevels&msg=' . urlencode($msg));
            exit;
        } catch (Exception $e) {
            header('Location: /admin/academic.php?tab=gradelevels&error=' . urlencode($e->getMessage()));
            exit;
        }
    } else {
        header('Location: /admin/academic.php?tab=gradelevels&error=Please fill in all fields.');
        exit;
    }
}

if (isset($_GET['toggle_gradelevel'])) {
    try {
        $levelId = $_GET['toggle_gradelevel'];
        $current = $db->fetchOne("SELECT is_active FROM grade_levels WHERE id = ?", [$levelId]);
        $newStatus = $current['is_active'] ? 0 : 1;
        $db->query("UPDATE grade_levels SET is_active = ? WHERE id = ?", [$newStatus, $levelId]);
        header('Location: /admin/academic.php?tab=gradelevels&msg=Status updated successfully!');
        exit;
    } catch (Exception $e) {
        header('Location: /admin/academic.php?tab=gradelevels&error=' . urlencode($e->getMessage()));
        exit;
    }
}

// ============================================
// GET DATA
// ============================================
$years = $db->fetchAll("SELECT * FROM academic_years WHERE school_id = 1 ORDER BY id DESC");
$terms = $db->fetchAll("
    SELECT t.*, y.year_name 
    FROM academic_terms t
    JOIN academic_years y ON t.academic_year_id = y.id
    WHERE t.school_id = 1 
    ORDER BY y.id DESC, t.term_number ASC
");
$gradeLevels = $db->fetchAll("SELECT * FROM grade_levels WHERE school_id = 1 ORDER BY promotion_order ASC");
$yearsForDropdown = $db->fetchAll("SELECT id, year_name FROM academic_years WHERE school_id = 1 AND is_active = 1 ORDER BY id DESC");
$config = $db->fetchOne("SELECT * FROM schools WHERE id = 1");

$editItem = null;
if ($editId) {
    if ($activeTab === 'years') {
        $editItem = $db->fetchOne("SELECT * FROM academic_years WHERE id = ? AND school_id = 1", [$editId]);
    } elseif ($activeTab === 'terms') {
        $editItem = $db->fetchOne("SELECT * FROM academic_terms WHERE id = ? AND school_id = 1", [$editId]);
    } elseif ($activeTab === 'gradelevels') {
        $editItem = $db->fetchOne("SELECT * FROM grade_levels WHERE id = ? AND school_id = 1", [$editId]);
    }
}

$previewLevels = generateLevelNames(
    $config['grade_level_terminology'] ?? 'Basic',
    $config['has_creche'] ?? 0,
    $config['has_nursery'] ?? 1,
    $config['has_kindergarten'] ?? 1,
    $config['has_primary'] ?? 1,
    $config['has_jhs'] ?? 1,
    $config['has_shs'] ?? 1
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Management - Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            padding: 0;
        }
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 220px;
            height: 100%;
            background: #1a3c6e;
            color: #fff;
            padding: 20px 0;
            overflow-y: auto;
        }
        .sidebar .logo { text-align: center; padding: 10px 0 20px; border-bottom: 1px solid #2a4c8e; font-size: 18px; font-weight: bold; }
        .sidebar .logo span { color: #f59e0b; }
        .sidebar .nav-item {
            display: block;
            padding: 12px 25px;
            color: #cbd5e1;
            text-decoration: none;
            font-size: 14px;
            transition: 0.3s;
        }
        .sidebar .nav-item:hover, .sidebar .nav-item.active { background: #2a4c8e; color: #fff; }
        .sidebar .nav-item .icon { margin-right: 10px; }
        .main-content { margin-left: 220px; padding: 20px; }
        .header {
            background: #fff;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .header h1 { color: #1a3c6e; font-size: 22px; }
        .btn {
            display: inline-block;
            padding: 8px 18px;
            background: #1a3c6e;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 13px;
            transition: 0.3s;
        }
        .btn:hover { background: #2a4c8e; }
        .btn-green { background: #16a34a; }
        .btn-green:hover { background: #15803d; }
        .btn-red { background: #dc2626; }
        .btn-red:hover { background: #b91c1c; }
        .btn-small { padding: 3px 8px; font-size: 11px; }
        .content-card {
            background: #fff;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .content-card h3 {
            color: #1a3c6e;
            margin-bottom: 10px;
            border-bottom: 2px solid #1a3c6e;
            padding-bottom: 8px;
            font-size: 16px;
        }
        .table-container { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        table th {
            background: #1a3c6e;
            color: #fff;
            padding: 8px 10px;
            text-align: left;
        }
        table td {
            padding: 8px 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        table tr:hover { background: #f8fafc; }
        .badge {
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
        }
        .badge.active { background: #d4edda; color: #155724; }
        .badge.inactive { background: #f8d7da; color: #721c24; }
        .badge.current { background: #fff3cd; color: #856404; }
        .badge.upcoming { background: #cce5ff; color: #004085; }
        .badge.completed { background: #d4edda; color: #155724; }
        .badge.archived { background: #e2e3e5; color: #383d41; }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            overflow-y: auto;
        }
        .modal.show { display: block; }
        .modal-content {
            background: #fff;
            padding: 25px;
            border-radius: 10px;
            max-width: 500px;
            width: 90%;
            margin: 30px auto;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-content h2 { color: #1a3c6e; margin-bottom: 15px; font-size: 20px; }
        .modal-content .close-modal { float: right; font-size: 24px; cursor: pointer; color: #999; }
        .modal-content .close-modal:hover { color: #333; }
        .form-group { margin-bottom: 12px; }
        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 4px;
            color: #333;
            font-size: 13px;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 13px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .btn-row {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .btn-row .btn { flex: 1; text-align: center; }
        .message {
            padding: 8px 15px;
            border-radius: 5px;
            margin-bottom: 12px;
            font-size: 14px;
        }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .no-data { text-align: center; color: #999; padding: 20px; }
        .action-group { display: flex; gap: 5px; flex-wrap: wrap; }
        .config-section {
            background: #f0f7ff;
            border: 2px solid #1a3c6e;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .config-section h3 { color: #1a3c6e; margin-bottom: 10px; }
        .config-section .preview-box {
            background: #fff;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #ddd;
            margin: 10px 0;
        }
        .config-section .preview-box .level-tag {
            display: inline-block;
            background: #e8f0fe;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 12px;
            margin: 2px;
        }
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 15px 0;
        }
        .checkbox-group .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: normal;
            cursor: pointer;
        }
        .checkbox-group .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .tabs {
            display: flex;
            gap: 5px;
            background: #fff;
            border-radius: 10px;
            padding: 5px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .tabs .tab {
            padding: 10px 25px;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            color: #666;
            font-size: 14px;
            font-weight: bold;
            transition: 0.3s;
        }
        .tabs .tab:hover { background: #f0f0f0; }
        .tabs .tab.active { background: #1a3c6e; color: #fff; }
        .terminology-badge {
            background: #1a3c6e;
            color: #fff;
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 12px;
            display: inline-block;
        }
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .main-content { margin-left: 0; }
            .form-row { grid-template-columns: 1fr; }
            .tabs { flex-wrap: wrap; }
            .tabs .tab { flex: 1; text-align: center; padding: 8px; font-size: 12px; }
            .checkbox-group { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="header">
            <div>
                <h1>📅 Academic Management</h1>
                <p style="color: #666; font-size: 13px;">Manage academic years, terms, and grade levels</p>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="message success">✅ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Tabs -->
        <div class="tabs">
            <a href="/admin/academic.php?tab=years" class="tab <?php echo $activeTab === 'years' ? 'active' : ''; ?>">📅 Years</a>
            <a href="/admin/academic.php?tab=terms" class="tab <?php echo $activeTab === 'terms' ? 'active' : ''; ?>">📋 Terms</a>
            <a href="/admin/academic.php?tab=gradelevels" class="tab <?php echo $activeTab === 'gradelevels' ? 'active' : ''; ?>">📊 Grade Levels</a>
        </div>
        
        <!-- ========================================== -->
        <!-- GRADE LEVELS TAB (With Configuration) -->
        <!-- ========================================== -->
        <?php if ($activeTab === 'gradelevels'): ?>
        
        <!-- Grade Configuration Section -->
        <div class="config-section">
            <h3>⚙️ Grade Level Configuration</h3>
            <p style="color: #666; font-size: 13px; margin-bottom: 15px;">
                Configure how grade levels are named. This will also update class names automatically.
                Current terminology: <span class="terminology-badge"><?php echo htmlspecialchars($config['grade_level_terminology'] ?? 'Basic'); ?></span>
            </p>
            
            <form method="POST" action="">
                <input type="hidden" name="save_grade_config" value="1">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="terminology">Level Terminology</label>
                        <select id="terminology" name="terminology">
                            <option value="Basic" <?php echo ($config['grade_level_terminology'] ?? 'Basic') == 'Basic' ? 'selected' : ''; ?>>Basic (Basic 1, Basic 2, etc.)</option>
                            <option value="Grade" <?php echo ($config['grade_level_terminology'] ?? 'Basic') == 'Grade' ? 'selected' : ''; ?>>Grade (Grade 1, Grade 2, etc.)</option>
                            <option value="Level" <?php echo ($config['grade_level_terminology'] ?? 'Basic') == 'Level' ? 'selected' : ''; ?>>Level (Level 1, Level 2, etc.)</option>
                            <option value="Class" <?php echo ($config['grade_level_terminology'] ?? 'Basic') == 'Class' ? 'selected' : ''; ?>>Class (Class 1, Class 2, etc.)</option>
                            <option value="Standard" <?php echo ($config['grade_level_terminology'] ?? 'Basic') == 'Standard' ? 'selected' : ''; ?>>Standard (Standard 1, Standard 2, etc.)</option>
                        </select>
                        <small style="color: #666;">This will automatically update all class names</small>
                    </div>
                    <div class="form-group">
                        <label for="start_level">Starting Level</label>
                        <select id="start_level" name="start_level">
                            <option value="Creche" <?php echo ($config['start_from'] ?? 'Nursery 1') == 'Creche' ? 'selected' : ''; ?>>Creche</option>
                            <option value="Nursery 1" <?php echo ($config['start_from'] ?? 'Nursery 1') == 'Nursery 1' ? 'selected' : ''; ?>>Nursery 1</option>
                            <option value="Kindergarten 1" <?php echo ($config['start_from'] ?? 'Nursery 1') == 'Kindergarten 1' ? 'selected' : ''; ?>>K.G. 1</option>
                            <option value="Grade 1" <?php echo ($config['start_from'] ?? 'Nursery 1') == 'Grade 1' ? 'selected' : ''; ?>>Grade/Basic 1</option>
                        </select>
                    </div>
                </div>
                
                <div class="checkbox-group">
                    <label class="checkbox-item">
                        <input type="checkbox" name="has_creche" value="1" <?php echo ($config['has_creche'] ?? 0) ? 'checked' : ''; ?>> 
                        Creche
                    </label>
                    <label class="checkbox-item">
                        <input type="checkbox" name="has_nursery" value="1" <?php echo ($config['has_nursery'] ?? 1) ? 'checked' : ''; ?>> 
                        Nursery (Nursery 1 & 2)
                    </label>
                    <label class="checkbox-item">
                        <input type="checkbox" name="has_kindergarten" value="1" <?php echo ($config['has_kindergarten'] ?? 1) ? 'checked' : ''; ?>> 
                        Kindergarten (K.G. 1 & 2)
                    </label>
                    <label class="checkbox-item">
                        <input type="checkbox" name="has_primary" value="1" <?php echo ($config['has_primary'] ?? 1) ? 'checked' : ''; ?>> 
                        Primary (1-6)
                    </label>
                    <label class="checkbox-item">
                        <input type="checkbox" name="has_jhs" value="1" <?php echo ($config['has_jhs'] ?? 1) ? 'checked' : ''; ?>> 
                        JHS (1-3)
                    </label>
                    <label class="checkbox-item">
                        <input type="checkbox" name="has_shs" value="1" <?php echo ($config['has_shs'] ?? 1) ? 'checked' : ''; ?>> 
                        SHS (1-3)
                    </label>
                </div>
                
                <div class="preview-box">
                    <p style="font-size: 13px; color: #666; margin-bottom: 5px;"><strong>Preview:</strong></p>
                    <div>
                        <?php foreach ($previewLevels as $level): ?>
                            <span class="level-tag"><?php echo $level['name']; ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-green">💾 Update Grade Configuration</button>
                <span style="color: #666; font-size: 12px; margin-left: 10px;">This will regenerate all grade levels and update class names</span>
            </form>
        </div>
        
        <!-- Grade Levels List -->
        <div class="content-card">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 15px;">
                <h3 style="border-bottom: none; padding-bottom: 0; margin-bottom: 0;">📊 Grade Levels</h3>
                <button class="btn btn-green" onclick="openModal('gradelevel')">➕ Add Grade Level</button>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Level Name</th>
                            <th>Code</th>
                            <th>Section</th>
                            <th>Promotion Order</th>
                            <th>Active</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($gradeLevels)): ?>
                            <?php foreach ($gradeLevels as $level): ?>
                                <tr>
                                    <td><?php echo $level['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($level['level_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($level['level_code']); ?></td>
                                    <td><span class="badge active"><?php echo $level['section']; ?></span></td>
                                    <td><?php echo $level['promotion_order']; ?></td>
                                    <td><span class="badge <?php echo $level['is_active'] ? 'active' : 'inactive'; ?>">
                                        <?php echo $level['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span></td>
                                    <td>
                                        <div class="action-group">
                                            <a href="/admin/academic.php?edit=<?php echo $level['id']; ?>&tab=gradelevels" class="btn btn-small">✏️</a>
                                            <a href="/admin/academic.php?toggle_gradelevel=<?php echo $level['id']; ?>&tab=gradelevels" class="btn <?php echo $level['is_active'] ? 'btn-red' : 'btn-green'; ?> btn-small" onclick="return confirm('Toggle status?')">
                                                <?php echo $level['is_active'] ? '🔴' : '🟢'; ?>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="no-data">No grade levels found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Add/Edit Grade Level Modal -->
        <div class="modal <?php echo ($action === 'add' || $editId) ? 'show' : ''; ?>" id="gradelevelModal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('gradelevelModal')">&times;</span>
                <h2><?php echo $editId ? '✏️ Edit Grade Level' : '➕ Add Grade Level'; ?></h2>
                <form method="POST" action="">
                    <input type="hidden" name="save_grade_level" value="1">
                    <input type="hidden" name="edit_id" value="<?php echo $editId; ?>">
                    
                    <div class="form-group">
                        <label for="level_name">Level Name *</label>
                        <input type="text" id="level_name" name="level_name" value="<?php echo htmlspecialchars($editItem['level_name'] ?? ''); ?>" placeholder="Basic 1" required>
                    </div>
                    <div class="form-group">
                        <label for="level_code">Level Code</label>
                        <input type="text" id="level_code" name="level_code" value="<?php echo htmlspecialchars($editItem['level_code'] ?? ''); ?>" placeholder="B1">
                    </div>
                    <div class="form-group">
                        <label for="section">Section *</label>
                        <select id="section" name="section" required>
                            <option value="Creche" <?php echo ($editItem['section'] ?? '') == 'Creche' ? 'selected' : ''; ?>>Creche</option>
                            <option value="Nursery" <?php echo ($editItem['section'] ?? '') == 'Nursery' ? 'selected' : ''; ?>>Nursery</option>
                            <option value="Kindergarten" <?php echo ($editItem['section'] ?? '') == 'Kindergarten' ? 'selected' : ''; ?>>Kindergarten</option>
                            <option value="Primary" <?php echo ($editItem['section'] ?? '') == 'Primary' ? 'selected' : ''; ?>>Primary</option>
                            <option value="JHS" <?php echo ($editItem['section'] ?? '') == 'JHS' ? 'selected' : ''; ?>>JHS</option>
                            <option value="SHS" <?php echo ($editItem['section'] ?? '') == 'SHS' ? 'selected' : ''; ?>>SHS</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="promotion_order">Promotion Order *</label>
                        <input type="number" id="promotion_order" name="promotion_order" value="<?php echo htmlspecialchars($editItem['promotion_order'] ?? ''); ?>" placeholder="1" min="1" required>
                        <small style="color: #666;">Lower number = lower grade level</small>
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-green">💾 <?php echo $editId ? 'Update' : 'Save'; ?></button>
                        <button type="button" class="btn" onclick="closeModal('gradelevelModal')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- ========================================== -->
        <!-- ACADEMIC YEARS TAB -->
        <!-- ========================================== -->
        <?php if ($activeTab === 'years'): ?>
        <div class="content-card">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 15px;">
                <h3 style="border-bottom: none; padding-bottom: 0; margin-bottom: 0;">📅 Academic Years</h3>
                <button class="btn btn-green" onclick="openModal('year')">➕ Add Year</button>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Year Name</th>
                            <th>Code</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Status</th>
                            <th>Current</th>
                            <th>Active</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($years)): ?>
                            <?php foreach ($years as $year): ?>
                                <tr>
                                    <td><?php echo $year['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($year['year_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($year['year_code']); ?></td>
                                    <td><?php echo date('d M Y', strtotime($year['start_date'])); ?></td>
                                    <td><?php echo date('d M Y', strtotime($year['end_date'])); ?></td>
                                    <td><span class="badge <?php echo strtolower($year['status']); ?>"><?php echo $year['status']; ?></span></td>
                                    <td><?php echo $year['is_current'] ? '✅' : ''; ?></td>
                                    <td><span class="badge <?php echo $year['is_active'] ? 'active' : 'inactive'; ?>">
                                        <?php echo $year['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span></td>
                                    <td>
                                        <div class="action-group">
                                            <?php if (!$year['is_current'] && $year['is_active']): ?>
                                                <a href="/admin/academic.php?set_current_year=<?php echo $year['id']; ?>&tab=years" class="btn btn-green btn-small">Set Current</a>
                                            <?php endif; ?>
                                            <a href="/admin/academic.php?edit=<?php echo $year['id']; ?>&tab=years" class="btn btn-small">✏️</a>
                                            <a href="/admin/academic.php?toggle_year=<?php echo $year['id']; ?>&tab=years" class="btn <?php echo $year['is_active'] ? 'btn-red' : 'btn-green'; ?> btn-small" onclick="return confirm('Toggle status?')">
                                                <?php echo $year['is_active'] ? '🔴' : '🟢'; ?>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="no-data">No academic years found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Add/Edit Year Modal -->
        <div class="modal <?php echo ($action === 'add' || $editId) ? 'show' : ''; ?>" id="yearModal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('yearModal')">&times;</span>
                <h2><?php echo $editId ? '✏️ Edit Year' : '➕ Add Academic Year'; ?></h2>
                <form method="POST" action="">
                    <input type="hidden" name="save_year" value="1">
                    <input type="hidden" name="edit_id" value="<?php echo $editId; ?>">
                    
                    <div class="form-group">
                        <label for="year_name">Year Name *</label>
                        <input type="text" id="year_name" name="year_name" value="<?php echo htmlspecialchars($editItem['year_name'] ?? ''); ?>" placeholder="2026/2027" required>
                    </div>
                    <div class="form-group">
                        <label for="year_code">Year Code *</label>
                        <input type="text" id="year_code" name="year_code" value="<?php echo htmlspecialchars($editItem['year_code'] ?? ''); ?>" placeholder="AY2627" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="start_date">Start Date *</label>
                            <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($editItem['start_date'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="end_date">End Date *</label>
                            <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($editItem['end_date'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-green">💾 <?php echo $editId ? 'Update' : 'Save'; ?></button>
                        <button type="button" class="btn" onclick="closeModal('yearModal')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- ========================================== -->
        <!-- ACADEMIC TERMS TAB -->
        <!-- ========================================== -->
        <?php if ($activeTab === 'terms'): ?>
        <div class="content-card">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 15px;">
                <h3 style="border-bottom: none; padding-bottom: 0; margin-bottom: 0;">📋 Academic Terms</h3>
                <button class="btn btn-green" onclick="openModal('term')">➕ Add Term</button>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Term Name</th>
                            <th>#</th>
                            <th>Year</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Status</th>
                            <th>Current</th>
                            <th>Active</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($terms)): ?>
                            <?php foreach ($terms as $term): ?>
                                <tr>
                                    <td><?php echo $term['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($term['term_name']); ?></strong></td>
                                    <td><?php echo $term['term_number']; ?></td>
                                    <td><?php echo htmlspecialchars($term['year_name']); ?></td>
                                    <td><?php echo date('d M Y', strtotime($term['start_date'])); ?></td>
                                    <td><?php echo date('d M Y', strtotime($term['end_date'])); ?></td>
                                    <td><span class="badge <?php echo strtolower($term['status']); ?>"><?php echo $term['status']; ?></span></td>
                                    <td><?php echo $term['is_current'] ? '✅' : ''; ?></td>
                                    <td><span class="badge <?php echo $term['is_active'] ? 'active' : 'inactive'; ?>">
                                        <?php echo $term['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span></td>
                                    <td>
                                        <div class="action-group">
                                            <?php if (!$term['is_current'] && $term['is_active']): ?>
                                                <a href="/admin/academic.php?set_current_term=<?php echo $term['id']; ?>&tab=terms" class="btn btn-green btn-small">Set Current</a>
                                            <?php endif; ?>
                                            <a href="/admin/academic.php?edit=<?php echo $term['id']; ?>&tab=terms" class="btn btn-small">✏️</a>
                                            <a href="/admin/academic.php?toggle_term=<?php echo $term['id']; ?>&tab=terms" class="btn <?php echo $term['is_active'] ? 'btn-red' : 'btn-green'; ?> btn-small" onclick="return confirm('Toggle status?')">
                                                <?php echo $term['is_active'] ? '🔴' : '🟢'; ?>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="10" class="no-data">No academic terms found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Add/Edit Term Modal -->
        <div class="modal <?php echo ($action === 'add' || $editId) ? 'show' : ''; ?>" id="termModal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('termModal')">&times;</span>
                <h2><?php echo $editId ? '✏️ Edit Term' : '➕ Add Academic Term'; ?></h2>
                <form method="POST" action="">
                    <input type="hidden" name="save_term" value="1">
                    <input type="hidden" name="edit_id" value="<?php echo $editId; ?>">
                    
                    <div class="form-group">
                        <label for="term_name">Term Name *</label>
                        <input type="text" id="term_name" name="term_name" value="<?php echo htmlspecialchars($editItem['term_name'] ?? ''); ?>" placeholder="First Term" required>
                    </div>
                    <div class="form-group">
                        <label for="term_number">Term Number *</label>
                        <select id="term_number" name="term_number" required>
                            <option value="">Select</option>
                            <option value="1" <?php echo ($editItem['term_number'] ?? '') == 1 ? 'selected' : ''; ?>>1st Term</option>
                            <option value="2" <?php echo ($editItem['term_number'] ?? '') == 2 ? 'selected' : ''; ?>>2nd Term</option>
                            <option value="3" <?php echo ($editItem['term_number'] ?? '') == 3 ? 'selected' : ''; ?>>3rd Term</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="academic_year_id">Academic Year *</label>
                        <select id="academic_year_id" name="academic_year_id" required>
                            <option value="">Select</option>
                            <?php foreach ($yearsForDropdown as $year): ?>
                                <option value="<?php echo $year['id']; ?>" <?php echo ($editItem['academic_year_id'] ?? '') == $year['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['year_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="start_date">Start Date *</label>
                            <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($editItem['start_date'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="end_date">End Date *</label>
                            <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($editItem['end_date'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-green">💾 <?php echo $editId ? 'Update' : 'Save'; ?></button>
                        <button type="button" class="btn" onclick="closeModal('termModal')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        function openModal(type) {
            const modalMap = {
                'year': 'yearModal',
                'term': 'termModal',
                'gradelevel': 'gradelevelModal'
            };
            document.getElementById(modalMap[type]).classList.add('show');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }
        
        <?php if ($action === 'add' || $editId): ?>
            <?php if ($activeTab === 'years'): ?>
                document.getElementById('yearModal').classList.add('show');
            <?php elseif ($activeTab === 'terms'): ?>
                document.getElementById('termModal').classList.add('show');
            <?php elseif ($activeTab === 'gradelevels'): ?>
                document.getElementById('gradelevelModal').classList.add('show');
            <?php endif; ?>
        <?php endif; ?>
        
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(function(modal) {
                if (event.target == modal) {
                    modal.classList.remove('show');
                }
            });
        }
    </script>
</body>
</html>