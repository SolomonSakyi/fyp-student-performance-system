<?php
/**
 * Finance API Endpoint
 *
 * @package EduTrack
 * @subpackage API
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================
// PATH CONFIGURATION
// ============================================================
$basePath = dirname(__DIR__, 3) . '/';

if (!file_exists($basePath . 'app/helpers/DatabaseHelper.php')) {
    $basePath = 'C:/Users/Almighty/Documents/FYP_Student_Performance_System_0.1/';
}

require_once $basePath . 'app/helpers/DatabaseHelper.php';
require_once $basePath . 'app/services/Finance/FinanceService.php';
require_once $basePath . 'app/helpers/LoggerHelper.php';

$financeService = new FinanceService();
$db = DatabaseHelper::getInstance();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $result = ['success' => false, 'message' => 'Invalid action'];

    switch ($action) {
        case 'search-students':
            $query = $_GET['query'] ?? $input['query'] ?? '';
            $searchMode = $_GET['search_mode'] ?? $input['search_mode'] ?? 'id';
            
            if (strlen($query) < 1) {
                throw new Exception('Please enter at least 1 character to search', 400);
            }
            
            if (!in_array($searchMode, ['id', 'name'])) {
                $searchMode = 'id';
            }
            
            $result = $financeService->searchStudents($query, $searchMode);
            break;

        case 'get-student':
            $studentId = $_GET['student_id'] ?? $input['student_id'] ?? null;
            if (!$studentId) {
                throw new Exception('Student ID required', 400);
            }
            $result = $financeService->getStudentById((int)$studentId);
            break;

        case 'student-bills':
            $studentId = $_GET['student_id'] ?? $input['student_id'] ?? null;
            if (!$studentId) {
                throw new Exception('Student ID required', 400);
            }
            $result = $financeService->getStudentBills((int)$studentId);
            break;

        case 'statistics':
            $result = $financeService->getStatistics();
            break;

        case 'categories':
            $result = $financeService->getFeeCategories();
            break;

        case 'create-category':
            $result = $financeService->createFeeCategory($input);
            break;

        case 'structures':
            $result = $financeService->getFeeStructures();
            break;

        case 'create-structure':
            $result = $financeService->createFeeStructure($input);
            break;

        case 'bills':
            $limit = $_GET['limit'] ?? 100;
            $result = $financeService->getBills(1, (int)$limit);
            break;

        case 'generate-bills':
            $result = $financeService->generateBills($input);
            break;

        case 'forward-arrears':
            $fromTermId = $_GET['from_term_id'] ?? $input['from_term_id'] ?? null;
            $toTermId = $_GET['to_term_id'] ?? $input['to_term_id'] ?? null;
            if (!$fromTermId || !$toTermId) {
                throw new Exception('From term and to term required', 400);
            }
            $result = $financeService->forwardArrears((int)$fromTermId, (int)$toTermId);
            break;

        case 'payments':
            $limit = $_GET['limit'] ?? 100;
            $result = $financeService->getPayments(1, (int)$limit);
            break;

        case 'payment-methods':
            $result = $financeService->getPaymentMethods();
            break;

        case 'record-payment':
            $result = $financeService->recordPayment($input);
            break;

        case 'discounts':
            $result = $financeService->getDiscounts();
            break;

        case 'create-discount':
            $result = $financeService->createDiscount($input);
            break;

        case 'settings':
            $result = $financeService->getSettings();
            break;

        case 'save-settings':
            $result = $financeService->saveSettings($input);
            break;

        default:
            http_response_code(400);
            $result = [
                'success' => false,
                'message' => 'Invalid action: ' . $action,
                'available_actions' => [
                    'search-students', 'get-student', 'student-bills',
                    'statistics',
                    'categories', 'create-category',
                    'structures', 'create-structure',
                    'bills', 'generate-bills', 'forward-arrears',
                    'payments', 'payment-methods', 'record-payment',
                    'discounts', 'create-discount',
                    'settings', 'save-settings'
                ]
            ];
    }

    echo json_encode($result);

} catch (Exception $e) {
    $code = $e->getCode() ?: 500;
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}