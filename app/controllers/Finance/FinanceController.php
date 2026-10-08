<?php
/**
 * FinanceController.php
 *
 * Enterprise Finance Controller
 * Handles HTTP requests for finance operations
 *
 * @package EduTrack
 * @subpackage Controllers\Finance
 * @version 1.0
 */

require_once __DIR__ . '/../../services/Finance/FinanceService.php';
require_once __DIR__ . '/../../helpers/JWTHelper.php';
require_once __DIR__ . '/../../helpers/LoggerHelper.php';

class FinanceController
{
    /**
     * @var FinanceService Finance service instance
     */
    private $financeService;

    /**
     * @var LoggerHelper Logger instance
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->financeService = new FinanceService();
        $this->logger = new LoggerHelper();
    }

    /**
     * Get all fee categories
     */
    public function getCategories(): void
    {
        try {
            $result = $this->financeService->getFeeCategories();
            $this->renderJson($result);
        } catch (Exception $e) {
            $this->renderJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Create fee category
     */
    public function createCategory(): void
    {
        try {
            $data = $this->getRequestData();
            $result = $this->financeService->createFeeCategory($data);
            $this->renderJson($result);
        } catch (Exception $e) {
            $this->renderJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Update fee category
     */
    public function updateCategory(): void
    {
        try {
            $id = $_GET['id'] ?? null;
            if (!$id) {
                $this->renderJson(['success' => false, 'message' => 'Category ID required'], 400);
                return;
            }
            $data = $this->getRequestData();
            $result = $this->financeService->updateFeeCategory((int)$id, $data);
            $this->renderJson($result);
        } catch (Exception $e) {
            $this->renderJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete fee category
     */
    public function deleteCategory(): void
    {
        try {
            $id = $_GET['id'] ?? null;
            if (!$id) {
                $this->renderJson(['success' => false, 'message' => 'Category ID required'], 400);
                return;
            }
            $result = $this->financeService->deleteFeeCategory((int)$id);
            $this->renderJson($result);
        } catch (Exception $e) {
            $this->renderJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get fee structures
     */
    public function getStructures(): void
    {
        try {
            $result = $this->financeService->getFeeStructures();
            $this->renderJson($result);
        } catch (Exception $e) {
            $this->renderJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Create fee structure
     */
    public function createStructure(): void
    {
        try {
            $data = $this->getRequestData();
            $result = $this->financeService->createFeeStructure($data);
            $this->renderJson($result);
        } catch (Exception $e) {
            $this->renderJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Generate bills
     */
    public function generateBills(): void
    {
        try {
            $data = $this->getRequestData();
            $result = $this->financeService->generateBills($data);
            $this->renderJson($result);
        } catch (Exception $e) {
            $this->renderJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get student bills
     */
    public function getStudentBills(): void
    {
        try {
            $studentId = $_GET['student_id'] ?? null;
            $termId = $_GET['term_id'] ?? null;
            if (!$studentId) {
                $this->renderJson(['success' => false, 'message' => 'Student ID required'], 400);
                return;
            }
            $result = $this->financeService->getStudentBills((int)$studentId, $termId ? (int)$termId : null);
            $this->renderJson($result);
        } catch (Exception $e) {
            $this->renderJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get bill by ID
     */
    public function getBill(): void
    {
        try {
            $id = $_GET['id'] ?? null;
            if (!$id) {
                $this->renderJson(['success' => false, 'message' => 'Bill ID required'], 400);
                return;
            }
            $result = $this->financeService->getBillById((int)$id);
            $this->renderJson($result);
        } catch (Exception $e) {
            $this->renderJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Record payment
     */
    public function recordPayment(): void
    {
        try {
            $data = $this->getRequestData();
            $result = $this->financeService->recordPayment($data);
            $this->renderJson($result);
        } catch (Exception $e) {
            $this->renderJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get payments
     */
    public function getPayments(): void
    {
        try {
            $studentId = $_GET['student_id'] ?? null;
            $result = $this->financeService->getPayments($studentId ? (int)$studentId : null);
            $this->renderJson($result);
        } catch (Exception $e) {
            $this->renderJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get statistics
     */
    public function getStatistics(): void
    {
        try {
            $result = $this->financeService->getStatistics();
            $this->renderJson($result);
        } catch (Exception $e) {
            $this->renderJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get students with arrears
     */
    public function getArrears(): void
    {
        try {
            $result = $this->financeService->getStudentsWithArrears();
            $this->renderJson($result);
        } catch (Exception $e) {
            $this->renderJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Forward arrears
     */
    public function forwardArrears(): void
    {
        try {
            $data = $this->getRequestData();
            $fromTermId = $data['from_term_id'] ?? null;
            $toTermId = $data['to_term_id'] ?? null;
            if (!$fromTermId || !$toTermId) {
                $this->renderJson(['success' => false, 'message' => 'From and To term IDs required'], 400);
                return;
            }
            $result = $this->financeService->forwardArrears((int)$fromTermId, (int)$toTermId);
            $this->renderJson($result);
        } catch (Exception $e) {
            $this->renderJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get discounts
     */
    public function getDiscounts(): void
    {
        try {
            $result = $this->financeService->getDiscounts();
            $this->renderJson($result);
        } catch (Exception $e) {
            $this->renderJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Validate discount code
     */
    public function validateDiscount(): void
    {
        try {
            $code = $_GET['code'] ?? null;
            if (!$code) {
                $this->renderJson(['success' => false, 'message' => 'Discount code required'], 400);
                return;
            }
            $result = $this->financeService->validateDiscountCode($code);
            $this->renderJson($result);
        } catch (Exception $e) {
            $this->renderJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get notifications
     */
    public function getNotifications(): void
    {
        try {
            $result = $this->financeService->getNotifications();
            $this->renderJson($result);
        } catch (Exception $e) {
            $this->renderJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Mark notification read
     */
    public function markNotificationRead(): void
    {
        try {
            $id = $_GET['id'] ?? null;
            if (!$id) {
                $this->renderJson(['success' => false, 'message' => 'Notification ID required'], 400);
                return;
            }
            $result = $this->financeService->markNotificationRead((int)$id);
            $this->renderJson($result);
        } catch (Exception $e) {
            $this->renderJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get payment methods
     */
    public function getPaymentMethods(): void
    {
        try {
            $result = $this->financeService->getPaymentMethods();
            $this->renderJson($result);
        } catch (Exception $e) {
            $this->renderJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ================================================================
    // HELPER METHODS
    // ================================================================

    /**
     * Get request data
     */
    private function getRequestData(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if ($input) {
            return $input;
        }
        return $_POST;
    }

    /**
     * Render JSON response
     */
    private function renderJson(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}