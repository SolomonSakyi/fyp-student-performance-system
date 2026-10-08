<?php
/**
 * grading.php
 *
 * API Endpoint for Grading Operations
 *
 * @package EduTrack
 * @subpackage API
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

// Define base path
define('BASE_PATH', __DIR__ . '/../../');

// Autoloader function (if not using composer)
spl_autoload_register(function ($class) {
    $prefix = '';
    $base_dir = BASE_PATH . 'app/';
    
    $file = $base_dir . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
        return true;
    }
    return false;
});

require_once BASE_PATH . 'app/helpers/DatabaseHelper.php';
require_once BASE_PATH . 'app/services/Assessment/GradingService.php';
require_once BASE_PATH . 'app/helpers/LoggerHelper.php';

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

$response = ['success' => false, 'message' => 'Invalid request'];

try {
    // Initialize services
    $gradingService = new GradingService();
    
    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    // Get action from GET or POST
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    // If no action is provided, return available actions
    if (empty($action)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing action parameter',
            'available_actions' => [
                'scales' => 'GET - List all grading scales',
                'scale' => 'GET - Get a specific scale (id required)',
                'create-scale' => 'POST - Create a new grading scale',
                'update-scale' => 'POST - Update a grading scale (id required)',
                'delete-scale' => 'POST - Delete a grading scale (id required)',
                'set-default-scale' => 'POST - Set a scale as default (id required)',
                'schemes' => 'GET - List all grading schemes',
                'scheme' => 'GET - Get a specific scheme (id required)',
                'create-scheme' => 'POST - Create a new grading scheme',
                'update-scheme' => 'POST - Update a grading scheme (id required)',
                'delete-scheme' => 'POST - Delete a grading scheme (id required)',
                'details' => 'GET - Get scheme details (scheme_id required)',
                'create-detail' => 'POST - Create a scheme detail',
                'update-detail' => 'POST - Update a scheme detail (id required)',
                'delete-detail' => 'POST - Delete a scheme detail (id required)',
                'preview' => 'GET - Preview grade calculation (score, scheme_id required)',
                'gpa' => 'GET - Calculate student GPA (student_id, term_id required)'
            ]
        ]);
        exit;
    }

    // Process actions
    switch ($action) {
        // ===== SCALES =====
        case 'scales':
            $result = $gradingService->getAllGradingScales();
            echo json_encode($result);
            break;

        case 'scale':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                throw new Exception('Scale ID required', 400);
            }
            $result = $gradingService->getGradingScaleById((int)$id);
            echo json_encode($result);
            break;

        case 'create-scale':
            $result = $gradingService->createGradingScale($input);
            echo json_encode($result);
            break;

        case 'update-scale':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                throw new Exception('Scale ID required', 400);
            }
            $result = $gradingService->updateGradingScale((int)$id, $input);
            echo json_encode($result);
            break;

        case 'delete-scale':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                throw new Exception('Scale ID required', 400);
            }
            $result = $gradingService->deleteGradingScale((int)$id);
            echo json_encode($result);
            break;

        case 'set-default-scale':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                throw new Exception('Scale ID required', 400);
            }
            $result = $gradingService->setDefaultGradingScale((int)$id);
            echo json_encode($result);
            break;

        // ===== SCHEMES =====
        case 'schemes':
            $result = $gradingService->getAllGradingSchemes();
            echo json_encode($result);
            break;

        case 'scheme':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                throw new Exception('Scheme ID required', 400);
            }
            $result = $gradingService->getGradingSchemeById((int)$id);
            echo json_encode($result);
            break;

        case 'create-scheme':
            $result = $gradingService->createGradingScheme($input);
            echo json_encode($result);
            break;

        case 'update-scheme':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                throw new Exception('Scheme ID required', 400);
            }
            $result = $gradingService->updateGradingScheme((int)$id, $input);
            echo json_encode($result);
            break;

        case 'delete-scheme':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                throw new Exception('Scheme ID required', 400);
            }
            $result = $gradingService->deleteGradingScheme((int)$id);
            echo json_encode($result);
            break;

        // ===== DETAILS =====
        case 'details':
            $schemeId = $_GET['scheme_id'] ?? null;
            if (!$schemeId) {
                throw new Exception('Scheme ID required', 400);
            }
            $result = $gradingService->getGradingSchemeDetails((int)$schemeId);
            echo json_encode($result);
            break;

        case 'create-detail':
            $result = $gradingService->createGradingSchemeDetail($input);
            echo json_encode($result);
            break;

        case 'update-detail':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                throw new Exception('Detail ID required', 400);
            }
            $result = $gradingService->updateGradingSchemeDetail((int)$id, $input);
            echo json_encode($result);
            break;

        case 'delete-detail':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                throw new Exception('Detail ID required', 400);
            }
            $result = $gradingService->deleteGradingSchemeDetail((int)$id);
            echo json_encode($result);
            break;

        // ===== PREVIEW =====
        case 'preview':
            $score = $_GET['score'] ?? null;
            $schemeId = $_GET['scheme_id'] ?? null;
            if ($score === null || !$schemeId) {
                throw new Exception('Score and Scheme ID required', 400);
            }
            $result = $gradingService->previewGrade((float)$score, (int)$schemeId);
            echo json_encode($result);
            break;

        // ===== GPA =====
        case 'gpa':
            $studentId = $_GET['student_id'] ?? null;
            $termId = $_GET['term_id'] ?? null;
            if (!$studentId || !$termId) {
                throw new Exception('Student ID and Term ID required', 400);
            }
            $result = $gradingService->calculateStudentGPA((int)$studentId, (int)$termId);
            echo json_encode($result);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "Invalid action: {$action}",
                'available_actions' => [
                    'scales', 'scale', 'create-scale', 'update-scale', 'delete-scale', 'set-default-scale',
                    'schemes', 'scheme', 'create-scheme', 'update-scheme', 'delete-scheme',
                    'details', 'create-detail', 'update-detail', 'delete-detail',
                    'preview', 'gpa'
                ]
            ]);
    }
} catch (Exception $e) {
    $code = $e->getCode() ?: 500;
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'code' => $code,
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
?>