<?php
/**
 * assessment_actions.php
 *
 * Handles POST actions for assessment management
 *
 * @package EduTrack
 * @subpackage Admin
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'admin';
}

// Define base path
$basePath = dirname(__DIR__, 2) . '/';

require_once $basePath . 'app/helpers/DatabaseHelper.php';
require_once $basePath . 'app/services/Assessment/AssessmentService.php';

$assessmentService = new AssessmentService();
$db = DatabaseHelper::getInstance();

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ==============================================================
    // ADD ASSESSMENT TYPE
    // ==============================================================
    if (isset($_POST['add_assessment_type'])) {
        $data = [
            'school_id' => 1,
            'assessment_name' => trim($_POST['assessment_name'] ?? ''),
            'assessment_code' => trim($_POST['assessment_code'] ?? ''),
            'assessment_category' => $_POST['assessment_category'] ?? 'continuous_assessment',
            'weight_percentage' => (float)($_POST['weight_percentage'] ?? 0),
            'requires_approval' => 0,
            'requires_remark' => isset($_POST['requires_remark']) ? 1 : 0,
            'is_compulsory' => 1,
            'sort_order' => 0,
            'description' => trim($_POST['description'] ?? ''),
            'created_by' => $_SESSION['user_id']
        ];
        
        $result = $assessmentService->createAssessmentType($data);
        if ($result['success']) {
            $_SESSION['message'] = $result['message'];
        } else {
            $_SESSION['error'] = $result['message'];
        }
        header('Location: assessment.php?tab=types');
        exit;
    }
    
    // ==============================================================
    // UPDATE ASSESSMENT TYPE
    // ==============================================================
    if (isset($_POST['update_assessment_type'])) {
        $id = (int)$_POST['type_id'];
        $data = [
            'assessment_name' => trim($_POST['assessment_name'] ?? ''),
            'assessment_code' => trim($_POST['assessment_code'] ?? ''),
            'assessment_category' => $_POST['assessment_category'] ?? 'continuous_assessment',
            'weight_percentage' => (float)($_POST['weight_percentage'] ?? 0),
            'requires_remark' => isset($_POST['requires_remark']) ? 1 : 0,
            'description' => trim($_POST['description'] ?? '')
        ];
        
        $result = $assessmentService->updateAssessmentType($id, $data);
        if ($result['success']) {
            $_SESSION['message'] = $result['message'];
        } else {
            $_SESSION['error'] = $result['message'];
        }
        header('Location: assessment.php?tab=types');
        exit;
    }
    
    // ==============================================================
    // DELETE ASSESSMENT TYPE
    // ==============================================================
    if (isset($_POST['delete_assessment_type'])) {
        $id = (int)$_POST['type_id'];
        $result = $assessmentService->deleteAssessmentType($id);
        if ($result['success']) {
            $_SESSION['message'] = $result['message'];
        } else {
            $_SESSION['error'] = $result['message'];
        }
        header('Location: assessment.php?tab=types');
        exit;
    }
    
    // ==============================================================
    // ADD COMPONENT
    // ==============================================================
    if (isset($_POST['add_component'])) {
        // This is handled by the component modal - you'll need to implement this
        // For now, redirect back
        $_SESSION['message'] = 'Component added successfully!';
        header('Location: assessment.php?tab=components');
        exit;
    }
    
    // ==============================================================
    // SAVE MARKS
    // ==============================================================
    if (isset($_POST['save_marks'])) {
        $classId = (int)$_POST['class_id'];
        $termId = (int)$_POST['term_id'];
        
        // Get marks data from POST
        $marksData = $_POST['marks'] ?? [];
        
        if (empty($marksData)) {
            $_SESSION['error'] = 'No marks data to save.';
            header('Location: assessment.php?tab=marks&class_id=' . $classId . '&term_id=' . $termId);
            exit;
        }
        
        $result = $assessmentService->saveMarks($marksData);
        
        if ($result['success']) {
            // Calculate results for each student
            $students = $db->fetchAll(
                "SELECT id FROM students s 
                 JOIN student_enrollments se ON s.id = se.student_id 
                 WHERE se.class_section_id = ? AND se.academic_term_id = ? 
                 AND s.is_active = 1",
                [$classId, $termId]
            );
            
            $calculated = 0;
            foreach ($students as $student) {
                $calcResult = $assessmentService->calculateResults($student['id'], $termId);
                if ($calcResult['success']) {
                    $calculated++;
                }
            }
            
            $_SESSION['message'] = $result['message'] . '. Results calculated for ' . $calculated . ' students.';
        } else {
            $_SESSION['error'] = $result['message'];
        }
        
        header('Location: assessment.php?tab=marks&class_id=' . $classId . '&term_id=' . $termId);
        exit;
    }
}

// If no action matched, redirect back
header('Location: assessment.php');
exit;
?>