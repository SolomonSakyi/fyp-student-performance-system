<?php
/**
 * index.php
 *
 * Grading API Endpoint
 *
 * @package EduTrack
 * @subpackage API
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Define paths - from api/grading to project root
$basePath = dirname(__DIR__, 2) . '/';

// Load required files
require_once $basePath . 'app/helpers/DatabaseHelper.php';
require_once $basePath . 'app/helpers/LoggerHelper.php';
require_once $basePath . 'app/models/Assessment/AssessmentModel.php';
require_once $basePath . 'app/services/Grading/GradingService.php';

try {
    $gradingService = new GradingService();
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $action = $_GET['action'] ?? '';

    if (empty($action)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing action parameter',
            'available_actions' => [
                'scales' => 'GET - List all grading scales',
                'scale' => 'GET - Get a specific scale (id required)',
                'create-scale' => 'POST - Create a grading scale',
                'update-scale' => 'POST - Update a grading scale (id required)',
                'delete-scale' => 'POST - Delete a grading scale (id required)',
                'set-default-scale' => 'POST - Set a scale as default (id required)',
                'schemes' => 'GET - List all grading schemes',
                'scheme' => 'GET - Get a specific scheme (id required)',
                'create-scheme' => 'POST - Create a grading scheme',
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

    switch ($action) {
        // SCALES
        case 'scales':
            $result = $gradingService->getAllGradingScales();
            echo json_encode($result);
            break;

        case 'scale':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('Scale ID required', 400);
            $result = $gradingService->getGradingScaleById((int)$id);
            echo json_encode($result);
            break;

        case 'create-scale':
            $result = $gradingService->createGradingScale($input);
            echo json_encode($result);
            break;

        case 'update-scale':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('Scale ID required', 400);
            $result = $gradingService->updateGradingScale((int)$id, $input);
            echo json_encode($result);
            break;

        case 'delete-scale':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('Scale ID required', 400);
            $result = $gradingService->deleteGradingScale((int)$id);
            echo json_encode($result);
            break;

        case 'set-default-scale':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('Scale ID required', 400);
            $result = $gradingService->setDefaultGradingScale((int)$id);
            echo json_encode($result);
            break;

        // SCHEMES
        case 'schemes':
            $result = $gradingService->getAllGradingSchemes();
            echo json_encode($result);
            break;

        case 'scheme':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('Scheme ID required', 400);
            $result = $gradingService->getGradingSchemeById((int)$id);
            echo json_encode($result);
            break;

        case 'create-scheme':
            $result = $gradingService->createGradingScheme($input);
            echo json_encode($result);
            break;

        case 'update-scheme':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('Scheme ID required', 400);
            $result = $gradingService->updateGradingScheme((int)$id, $input);
            echo json_encode($result);
            break;

        case 'delete-scheme':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('Scheme ID required', 400);
            $result = $gradingService->deleteGradingScheme((int)$id);
            echo json_encode($result);
            break;

        // DETAILS
        case 'details':
            $schemeId = $_GET['scheme_id'] ?? null;
            if (!$schemeId) throw new Exception('Scheme ID required', 400);
            $result = $gradingService->getGradingSchemeDetails((int)$schemeId);
            echo json_encode($result);
            break;

        case 'create-detail':
            $result = $gradingService->createGradingSchemeDetail($input);
            echo json_encode($result);
            break;

        case 'update-detail':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('Detail ID required', 400);
            $result = $gradingService->updateGradingSchemeDetail((int)$id, $input);
            echo json_encode($result);
            break;

        case 'delete-detail':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('Detail ID required', 400);
            $result = $gradingService->deleteGradingSchemeDetail((int)$id);
            echo json_encode($result);
            break;

        // PREVIEW
        case 'preview':
            $score = $_GET['score'] ?? null;
            $schemeId = $_GET['scheme_id'] ?? null;
            if ($score === null || !$schemeId) throw new Exception('Score and Scheme ID required', 400);
            $result = $gradingService->previewGrade((float)$score, (int)$schemeId);
            echo json_encode($result);
            break;

        // GPA
        case 'gpa':
            $studentId = $_GET['student_id'] ?? null;
            $termId = $_GET['term_id'] ?? null;
            if (!$studentId || !$termId) throw new Exception('Student ID and Term ID required', 400);
            $result = $gradingService->calculateStudentGPA((int)$studentId, (int)$termId);
            echo json_encode($result);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "Invalid action: {$action}"
            ]);
    }
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>