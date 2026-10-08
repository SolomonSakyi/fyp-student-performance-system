<?php
/**
 * FinanceService.php
 *
 * Enterprise Finance Service Layer - Complete Implementation
 *
 * @package EduTrack
 * @subpackage Services\Finance
 * @version 2.0
 */

// ============================================================
// PATH CONFIGURATION
// ============================================================
$projectRoot = dirname(__DIR__, 3) . '/';

if (!file_exists($projectRoot . 'app/models/Finance/FinanceModel.php')) {
    $projectRoot = 'C:/Users/Almighty/Documents/FYP_Student_Performance_System_0.1/';
}

require_once $projectRoot . 'app/models/Finance/FinanceModel.php';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class FinanceService
{
    private $model;
    private $db;
    private $logger;

    public function __construct()
    {
        $this->model = new FinanceModel();
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
    }

    // ===================================================
    // STATISTICS
    // ===================================================

    public function getStatistics(int $schoolId = 1): array
    {
        try {
            $stats = $this->model->getStatistics($schoolId);
            return ['success' => true, 'data' => $stats];
        } catch (Exception $e) {
            $this->logger->error('Failed to get statistics: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    // ===================================================
    // STUDENT SEARCH
    // ===================================================

    public function searchStudents(string $query, string $searchMode = 'id', int $schoolId = 1): array
    {
        try {
            $query = trim($query);
            if (strlen($query) < 1) {
                return ['success' => false, 'message' => 'Please enter at least 1 character to search', 'data' => []];
            }
            
            if (!in_array($searchMode, ['id', 'name'])) {
                $searchMode = 'id';
            }
            
            $students = $this->model->searchStudents($query, $schoolId, $searchMode);
            
            return [
                'success' => true, 
                'data' => $students, 
                'search_mode' => $searchMode,
                'query' => $query,
                'count' => count($students)
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to search students: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function getStudentById(int $studentId): array
    {
        try {
            $student = $this->model->getStudentById($studentId);
            if (!$student) {
                return ['success' => false, 'message' => 'Student not found'];
            }
            return ['success' => true, 'data' => $student];
        } catch (Exception $e) {
            $this->logger->error('Failed to get student: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ===================================================
    // FEE CATEGORIES
    // ===================================================

    public function getFeeCategories(int $schoolId = 1): array
    {
        try {
            $categories = $this->model->getFeeCategories($schoolId);
            return ['success' => true, 'data' => $categories];
        } catch (Exception $e) {
            $this->logger->error('Failed to get fee categories: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function createFeeCategory(array $data): array
    {
        try {
            $this->validateFeeCategory($data);
            $id = $this->model->createFeeCategory($data);
            return ['success' => true, 'message' => 'Fee category created successfully', 'id' => $id];
        } catch (Exception $e) {
            $this->logger->error('Failed to create fee category: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateFeeCategory(int $id, array $data): array
    {
        try {
            $this->validateFeeCategory($data, true);
            $this->model->updateFeeCategory($id, $data);
            return ['success' => true, 'message' => 'Fee category updated successfully'];
        } catch (Exception $e) {
            $this->logger->error('Failed to update fee category: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteFeeCategory(int $id): array
    {
        try {
            $this->model->deleteFeeCategory($id);
            return ['success' => true, 'message' => 'Fee category deleted successfully'];
        } catch (Exception $e) {
            $this->logger->error('Failed to delete fee category: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ===================================================
    // FEE STRUCTURES
    // ===================================================

    public function getFeeStructures(int $schoolId = 1): array
    {
        try {
            $structures = $this->model->getFeeStructures($schoolId);
            return ['success' => true, 'data' => $structures];
        } catch (Exception $e) {
            $this->logger->error('Failed to get fee structures: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function createFeeStructure(array $data): array
    {
        try {
            $this->validateFeeStructure($data);
            $id = $this->model->createFeeStructure($data);
            return ['success' => true, 'message' => 'Fee structure created successfully', 'id' => $id];
        } catch (Exception $e) {
            $this->logger->error('Failed to create fee structure: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateFeeStructure(int $id, array $data): array
    {
        try {
            $this->validateFeeStructure($data, true);
            $this->model->updateFeeStructure($id, $data);
            return ['success' => true, 'message' => 'Fee structure updated successfully'];
        } catch (Exception $e) {
            $this->logger->error('Failed to update fee structure: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteFeeStructure(int $id): array
    {
        try {
            $this->model->deleteFeeStructure($id);
            return ['success' => true, 'message' => 'Fee structure deleted successfully'];
        } catch (Exception $e) {
            $this->logger->error('Failed to delete fee structure: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ===================================================
    // BILLS
    // ===================================================

    public function getBills(int $schoolId = 1, int $limit = 100): array
    {
        try {
            $bills = $this->model->getBills($schoolId, $limit);
            return ['success' => true, 'data' => $bills];
        } catch (Exception $e) {
            $this->logger->error('Failed to get bills: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function getStudentBills(int $studentId): array
    {
        try {
            $bills = $this->model->getStudentBills($studentId);
            return ['success' => true, 'data' => $bills];
        } catch (Exception $e) {
            $this->logger->error('Failed to get student bills: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function getBillById(int $id): array
    {
        try {
            $bill = $this->model->getBillById($id);
            if (!$bill) {
                return ['success' => false, 'message' => 'Bill not found'];
            }
            return ['success' => true, 'data' => $bill];
        } catch (Exception $e) {
            $this->logger->error('Failed to get bill: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function generateBills(array $params): array
    {
        try {
            $result = $this->model->generateBills($params);
            return [
                'success' => true,
                'message' => "Generated {$result['generated']} bills, skipped {$result['skipped']}",
                'data' => $result
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to generate bills: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function forwardArrears(int $fromTermId, int $toTermId): array
    {
        try {
            $result = $this->model->forwardArrears($fromTermId, $toTermId);
            return [
                'success' => true,
                'message' => "Forwarded {$result['forwarded']} bills to next term",
                'data' => $result
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to forward arrears: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ===================================================
    // PAYMENTS
    // ===================================================

    public function getPayments(int $schoolId = 1, int $limit = 100): array
    {
        try {
            $payments = $this->model->getPayments($schoolId, $limit);
            return ['success' => true, 'data' => $payments];
        } catch (Exception $e) {
            $this->logger->error('Failed to get payments: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function getPaymentMethods(int $schoolId = 1): array
    {
        try {
            $methods = $this->model->getPaymentMethods($schoolId);
            return ['success' => true, 'data' => $methods];
        } catch (Exception $e) {
            $this->logger->error('Failed to get payment methods: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function recordPayment(array $data): array
    {
        try {
            $this->validatePayment($data);
            
            $this->db->beginTransaction();

            $settings = $this->model->getSettings();
            $receiptPrefix = $settings['receipt_prefix'] ?? 'REC-';
            $data['receipt_number'] = $this->model->generateReceiptNumber($receiptPrefix);

            $paymentId = $this->model->createPayment($data);
            if (!$paymentId) {
                throw new Exception('Failed to record payment');
            }

            if (!empty($data['student_bill_id'])) {
                $this->model->updateBillBalance($data['student_bill_id'], $data['amount']);
            }

            $this->db->commit();
            
            $this->logger->info("Payment recorded: {$data['receipt_number']} - Amount: {$data['amount']}");

            return [
                'success' => true,
                'message' => 'Payment recorded successfully',
                'receipt_number' => $data['receipt_number'],
                'payment_id' => $paymentId
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Failed to record payment: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function voidPayment(int $id): array
    {
        try {
            $payment = $this->model->getPaymentById($id);
            if (!$payment) {
                return ['success' => false, 'message' => 'Payment not found'];
            }

            $this->db->beginTransaction();
            $this->model->voidPayment($id);
            $this->db->commit();

            $this->logger->info("Payment voided: ID $id - Amount: {$payment['amount']}");
            return ['success' => true, 'message' => 'Payment voided successfully'];
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Failed to void payment: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ===================================================
    // DISCOUNTS
    // ===================================================

    public function getDiscounts(int $schoolId = 1): array
    {
        try {
            $discounts = $this->model->getDiscounts($schoolId);
            return ['success' => true, 'data' => $discounts];
        } catch (Exception $e) {
            $this->logger->error('Failed to get discounts: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function createDiscount(array $data): array
    {
        try {
            $this->validateDiscount($data);
            $id = $this->model->createDiscount($data);
            return ['success' => true, 'message' => 'Discount created successfully', 'id' => $id];
        } catch (Exception $e) {
            $this->logger->error('Failed to create discount: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ===================================================
    // SETTINGS
    // ===================================================

    public function getSettings(): array
    {
        try {
            $settings = $this->model->getSettings();
            return ['success' => true, 'data' => $settings];
        } catch (Exception $e) {
            $this->logger->error('Failed to get settings: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function saveSettings(array $settings): array
    {
        try {
            $this->validateSettings($settings);
            $this->model->saveSettings($settings);
            return ['success' => true, 'message' => 'Settings saved successfully'];
        } catch (Exception $e) {
            $this->logger->error('Failed to save settings: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ===================================================
    // VALIDATION METHODS
    // ===================================================

    private function validateFeeCategory(array $data, bool $isUpdate = false): void
    {
        if (!$isUpdate || isset($data['category_name'])) {
            if (empty($data['category_name']) || strlen($data['category_name']) > 100) {
                throw new Exception('Category name must be between 1 and 100 characters');
            }
        }

        if (isset($data['category_code']) && strlen($data['category_code']) > 20) {
            throw new Exception('Category code must not exceed 20 characters');
        }

        if (isset($data['category_type']) && !in_array($data['category_type'], ['tuition', 'levy', 'sports', 'medical', 'canteen', 'transport', 'pta', 'maintenance', 'custom'])) {
            throw new Exception('Invalid category type');
        }
    }

    private function validateFeeStructure(array $data, bool $isUpdate = false): void
    {
        if (!$isUpdate) {
            $required = ['grade_level_id', 'fee_category_id', 'academic_year_id', 'academic_term_id', 'amount'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
        }

        if (isset($data['amount']) && $data['amount'] <= 0) {
            throw new Exception('Amount must be greater than zero');
        }
    }

    private function validatePayment(array $data): void
    {
        $required = ['student_id', 'amount', 'payment_method_id'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }

        if ($data['amount'] <= 0) {
            throw new Exception('Amount must be greater than zero');
        }

        $student = $this->db->fetchOne("SELECT id FROM students WHERE id = ? AND is_active = 1", [$data['student_id']]);
        if (!$student) {
            throw new Exception('Student not found or inactive');
        }

        $method = $this->db->fetchOne("SELECT id FROM payment_methods WHERE id = ? AND is_active = 1", [$data['payment_method_id']]);
        if (!$method) {
            throw new Exception('Payment method not found or inactive');
        }

        if (isset($data['student_bill_id']) && $data['student_bill_id'] > 0) {
            $bill = $this->model->getBillById($data['student_bill_id']);
            if (!$bill) {
                throw new Exception('Bill not found');
            }
            if ($bill['bill_status'] === 'paid') {
                throw new Exception('Bill is already paid');
            }
            if ($data['amount'] > $bill['balance_due']) {
                throw new Exception('Payment amount exceeds bill balance');
            }
        }
    }

    private function validateDiscount(array $data): void
    {
        $required = ['discount_name', 'discount_type', 'discount_value', 'start_date', 'end_date'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }

        if ($data['discount_value'] <= 0) {
            throw new Exception('Discount value must be greater than zero');
        }

        if (!in_array($data['discount_type'], ['percentage', 'fixed'])) {
            throw new Exception('Invalid discount type');
        }

        if (strtotime($data['end_date']) < strtotime($data['start_date'])) {
            throw new Exception('End date must be after start date');
        }
    }

    private function validateSettings(array $settings): void
    {
        if (isset($settings['invoice_prefix'])) {
            $prefix = trim($settings['invoice_prefix']);
            if (empty($prefix) || strlen($prefix) > 10) {
                throw new Exception('Invoice prefix must be 1-10 characters');
            }
        }

        if (isset($settings['receipt_prefix'])) {
            $prefix = trim($settings['receipt_prefix']);
            if (empty($prefix) || strlen($prefix) > 10) {
                throw new Exception('Receipt prefix must be 1-10 characters');
            }
        }

        if (isset($settings['late_fee_days'])) {
            $days = (int)$settings['late_fee_days'];
            if ($days < 1 || $days > 365) {
                throw new Exception('Late fee days must be between 1 and 365');
            }
        }

        if (isset($settings['late_fee_percentage'])) {
            $percentage = (float)$settings['late_fee_percentage'];
            if ($percentage < 0 || $percentage > 100) {
                throw new Exception('Late fee percentage must be between 0 and 100');
            }
        }

        if (isset($settings['max_installments'])) {
            $installments = (int)$settings['max_installments'];
            if ($installments < 1 || $installments > 12) {
                throw new Exception('Max installments must be between 1 and 12');
            }
        }

        if (isset($settings['due_days'])) {
            $days = (int)$settings['due_days'];
            if ($days < 1 || $days > 365) {
                throw new Exception('Due days must be between 1 and 365');
            }
        }
    }
}