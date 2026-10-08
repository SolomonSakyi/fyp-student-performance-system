<?php
/**
 * FinanceModel.php
 *
 * Enterprise Finance Model - Complete Implementation
 * Based on actual database schema
 *
 * @package EduTrack
 * @subpackage Models\Finance
 * @version 2.0
 */

// ============================================================
// PATH CONFIGURATION
// ============================================================
$projectRoot = dirname(__DIR__, 3) . '/';

if (!file_exists($projectRoot . 'app/helpers/DatabaseHelper.php')) {
    $projectRoot = 'C:/Users/Almighty/Documents/FYP_Student_Performance_System_0.1/';
}

require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class FinanceModel
{
    private $db;
    private $logger;
    private $settingsCache = array();

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
    }

    // ===================================================
    // STATISTICS
    // ===================================================

    public function getStatistics(int $schoolId = 1): array
    {
        try {
            $totalBills = $this->db->fetchOne(
                "SELECT 
                    COUNT(*) as total_bills,
                    COALESCE(SUM(total_amount), 0) as total_amount,
                    COALESCE(SUM(amount_paid), 0) as total_paid,
                    COALESCE(SUM(balance_due), 0) as total_balance
                FROM student_bills 
                WHERE school_id = ? AND is_active = 1",
                array($schoolId)
            );

            $overdue = $this->db->fetchOne(
                "SELECT COUNT(*) as count 
                FROM student_bills 
                WHERE school_id = ? AND is_active = 1 
                AND bill_status NOT IN ('paid', 'cancelled') 
                AND due_date < CURDATE()",
                array($schoolId)
            );

            $monthlyPayments = $this->db->fetchOne(
                "SELECT COALESCE(SUM(amount), 0) as total 
                FROM payments 
                WHERE school_id = ? AND is_active = 1 
                AND MONTH(payment_date) = MONTH(CURDATE()) 
                AND YEAR(payment_date) = YEAR(CURDATE())",
                array($schoolId)
            );

            $studentsWithBills = $this->db->fetchOne(
                "SELECT COUNT(DISTINCT student_id) as count 
                FROM student_bills 
                WHERE school_id = ? AND is_active = 1",
                array($schoolId)
            );

            return array(
                'total_bills' => (int)($totalBills['total_bills'] ?? 0),
                'total_amount' => (float)($totalBills['total_amount'] ?? 0),
                'collected' => (float)($totalBills['total_paid'] ?? 0),
                'outstanding' => (float)($totalBills['total_balance'] ?? 0),
                'overdue_bills' => (int)($overdue['count'] ?? 0),
                'monthly_payments' => (float)($monthlyPayments['total'] ?? 0),
                'students_with_bills' => (int)($studentsWithBills['count'] ?? 0)
            );
        } catch (Exception $e) {
            $this->logger->error('Failed to get statistics: ' . $e->getMessage());
            return array(
                'total_bills' => 0,
                'total_amount' => 0,
                'collected' => 0,
                'outstanding' => 0,
                'overdue_bills' => 0,
                'monthly_payments' => 0,
                'students_with_bills' => 0
            );
        }
    }

    // ===================================================
    // STUDENT SEARCH - FIXED
    // ===================================================

    public function searchStudents(string $query, int $schoolId = 1, string $searchMode = 'id'): array
    {
        try {
            $query = trim($query);
            if (empty($query)) {
                return array();
            }

            $searchTerm = '%' . $query . '%';
            $intQuery = (int)$query;

            if ($searchMode === 'id') {
                // Search by Admission Number or ID
                $sql = "SELECT 
                            id, 
                            first_name, 
                            last_name, 
                            admission_number,
                            '' as level_name,
                            0 as outstanding_bills_count,
                            0 as total_balance
                        FROM students 
                        WHERE is_active = 1
                        AND (
                            admission_number LIKE ? 
                            OR id = ? 
                            OR admission_number = ?
                        )
                        ORDER BY first_name
                        LIMIT 20";
                
                $params = array($searchTerm, $intQuery, $query);
                
                // Debug: Log the query
                error_log("Search SQL: " . $sql);
                error_log("Search Params: " . print_r($params, true));
                
                $results = $this->db->fetchAll($sql, $params);
                
                error_log("Results found: " . count($results));
                
                return $results;
                
            } else {
                // Search by Name
                $sql = "SELECT 
                            id, 
                            first_name, 
                            last_name, 
                            admission_number,
                            '' as level_name,
                            0 as outstanding_bills_count,
                            0 as total_balance
                        FROM students 
                        WHERE is_active = 1
                        AND (
                            first_name LIKE ? 
                            OR last_name LIKE ? 
                            OR CONCAT(first_name, ' ', last_name) LIKE ?
                        )
                        ORDER BY first_name
                        LIMIT 20";
                
                $params = array($searchTerm, $searchTerm, $searchTerm);
                
                return $this->db->fetchAll($sql, $params);
            }
        } catch (Exception $e) {
            error_log('Search failed: ' . $e->getMessage());
            return array();
        }
    }

    public function getStudentById(int $studentId): ?array
    {
        try {
            $sql = "SELECT 
                        id, 
                        first_name, 
                        last_name, 
                        admission_number,
                        '' as level_name
                    FROM students 
                    WHERE id = ? AND is_active = 1";
            
            return $this->db->fetchOne($sql, array($studentId));
        } catch (Exception $e) {
            $this->logger->error('Failed to get student by ID: ' . $e->getMessage());
            return null;
        }
    }

    // ===================================================
    // FEE CATEGORIES - CRUD
    // ===================================================

    public function getFeeCategories(int $schoolId = 1, bool $activeOnly = true): array
    {
        try {
            $sql = "SELECT * FROM fee_categories WHERE school_id = ?";
            if ($activeOnly) {
                $sql .= " AND is_active = 1";
            }
            $sql .= " ORDER BY category_name";
            return $this->db->fetchAll($sql, array($schoolId));
        } catch (Exception $e) {
            $this->logger->error('Failed to get fee categories: ' . $e->getMessage());
            return array();
        }
    }

    public function getFeeCategoryById(int $id): ?array
    {
        try {
            return $this->db->fetchOne(
                "SELECT * FROM fee_categories WHERE id = ? AND is_active = 1",
                array($id)
            );
        } catch (Exception $e) {
            $this->logger->error('Failed to get fee category: ' . $e->getMessage());
            return null;
        }
    }

    public function createFeeCategory(array $data): ?int
    {
        try {
            $required = array('category_name');
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }

            $existing = $this->db->fetchOne(
                "SELECT id FROM fee_categories 
                WHERE school_id = ? AND category_name = ? AND is_active = 1",
                array($data['school_id'] ?? 1, $data['category_name'])
            );
            if ($existing) {
                throw new Exception('Fee category already exists');
            }

            $sql = "INSERT INTO fee_categories (
                uuid, school_id, category_name, category_code, 
                category_type, is_compulsory, is_active, created_at
            ) VALUES (
                UUID(), ?, ?, ?, ?, ?, 1, NOW()
            )";

            $this->db->query($sql, array(
                $data['school_id'] ?? 1,
                $data['category_name'],
                $data['category_code'] ?? strtoupper(substr($data['category_name'], 0, 10)),
                $data['category_type'] ?? 'custom',
                isset($data['is_compulsory']) ? (int)$data['is_compulsory'] : 1
            ));

            $id = $this->db->lastInsertId();
            $this->logAudit('fee_categories', $id, 'INSERT', null, $data);
            return $id;
        } catch (Exception $e) {
            $this->logger->error('Failed to create fee category: ' . $e->getMessage());
            throw $e;
        }
    }

    public function updateFeeCategory(int $id, array $data): bool
    {
        try {
            $oldData = $this->getFeeCategoryById($id);
            if (!$oldData) {
                throw new Exception('Fee category not found');
            }

            $allowedFields = array('category_name', 'category_code', 'category_type', 'is_compulsory', 'is_active');
            $fields = array();
            $params = array();

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $params[] = $id;
            $sql = "UPDATE fee_categories SET " . implode(', ', $fields) . " WHERE id = ?";
            $this->db->query($sql, $params);

            $this->logAudit('fee_categories', $id, 'UPDATE', $oldData, $data);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update fee category: ' . $e->getMessage());
            throw $e;
        }
    }

    public function deleteFeeCategory(int $id): bool
    {
        try {
            $oldData = $this->getFeeCategoryById($id);
            if (!$oldData) {
                throw new Exception('Fee category not found');
            }

            $inUse = $this->db->fetchOne(
                "SELECT id FROM fee_structures WHERE fee_category_id = ? AND is_active = 1",
                array($id)
            );
            if ($inUse) {
                throw new Exception('Cannot delete category as it is in use');
            }

            $sql = "UPDATE fee_categories SET is_active = 0 WHERE id = ?";
            $this->db->query($sql, array($id));

            $this->logAudit('fee_categories', $id, 'DELETE', $oldData, null);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to delete fee category: ' . $e->getMessage());
            throw $e;
        }
    }

    // ===================================================
    // FEE STRUCTURES - CRUD
    // ===================================================

    public function getFeeStructures(int $schoolId = 1, bool $activeOnly = true): array
    {
        try {
            $sql = "SELECT fs.*, 
                           gl.level_name, 
                           fc.category_name, 
                           ay.year_name, 
                           at.term_name
                    FROM fee_structures fs
                    JOIN grade_levels gl ON fs.grade_level_id = gl.id
                    JOIN fee_categories fc ON fs.fee_category_id = fc.id
                    JOIN academic_years ay ON fs.academic_year_id = ay.id
                    JOIN academic_terms at ON fs.academic_term_id = at.id
                    WHERE fs.school_id = ?";
            if ($activeOnly) {
                $sql .= " AND fs.is_active = 1";
            }
            $sql .= " ORDER BY gl.promotion_order, fc.category_name";
            return $this->db->fetchAll($sql, array($schoolId));
        } catch (Exception $e) {
            $this->logger->error('Failed to get fee structures: ' . $e->getMessage());
            return array();
        }
    }

    public function getFeeStructureById(int $id): ?array
    {
        try {
            return $this->db->fetchOne(
                "SELECT * FROM fee_structures WHERE id = ? AND is_active = 1",
                array($id)
            );
        } catch (Exception $e) {
            $this->logger->error('Failed to get fee structure: ' . $e->getMessage());
            return null;
        }
    }

    public function createFeeStructure(array $data): ?int
    {
        try {
            $required = array('grade_level_id', 'fee_category_id', 'academic_year_id', 'academic_term_id', 'amount');
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }

            if ($data['amount'] <= 0) {
                throw new Exception('Amount must be greater than zero');
            }

            $existing = $this->db->fetchOne(
                "SELECT id FROM fee_structures 
                WHERE school_id = ? AND grade_level_id = ? 
                AND fee_category_id = ? AND academic_year_id = ? 
                AND academic_term_id = ? AND is_active = 1",
                array(
                    $data['school_id'] ?? 1,
                    $data['grade_level_id'],
                    $data['fee_category_id'],
                    $data['academic_year_id'],
                    $data['academic_term_id']
                )
            );
            if ($existing) {
                throw new Exception('Fee structure already exists for this combination');
            }

            $sql = "INSERT INTO fee_structures (
                uuid, school_id, grade_level_id, fee_category_id,
                academic_year_id, academic_term_id, amount,
                is_active, created_at
            ) VALUES (
                UUID(), ?, ?, ?, ?, ?, ?, 1, NOW()
            )";

            $this->db->query($sql, array(
                $data['school_id'] ?? 1,
                $data['grade_level_id'],
                $data['fee_category_id'],
                $data['academic_year_id'],
                $data['academic_term_id'],
                $data['amount']
            ));

            $id = $this->db->lastInsertId();
            $this->logAudit('fee_structures', $id, 'INSERT', null, $data);
            return $id;
        } catch (Exception $e) {
            $this->logger->error('Failed to create fee structure: ' . $e->getMessage());
            throw $e;
        }
    }

    public function updateFeeStructure(int $id, array $data): bool
    {
        try {
            $oldData = $this->getFeeStructureById($id);
            if (!$oldData) {
                throw new Exception('Fee structure not found');
            }

            $allowedFields = array('amount', 'is_active');
            $fields = array();
            $params = array();

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $params[] = $id;
            $sql = "UPDATE fee_structures SET " . implode(', ', $fields) . " WHERE id = ?";
            $this->db->query($sql, $params);

            $this->logAudit('fee_structures', $id, 'UPDATE', $oldData, $data);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update fee structure: ' . $e->getMessage());
            throw $e;
        }
    }

    public function deleteFeeStructure(int $id): bool
    {
        try {
            $oldData = $this->getFeeStructureById($id);
            if (!$oldData) {
                throw new Exception('Fee structure not found');
            }

            $inUse = $this->db->fetchOne(
                "SELECT id FROM bill_items WHERE fee_structure_id = ? AND is_active = 1",
                array($id)
            );
            if ($inUse) {
                throw new Exception('Cannot delete structure as it is in use');
            }

            $sql = "UPDATE fee_structures SET is_active = 0 WHERE id = ?";
            $this->db->query($sql, array($id));

            $this->logAudit('fee_structures', $id, 'DELETE', $oldData, null);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to delete fee structure: ' . $e->getMessage());
            throw $e;
        }
    }

    // ===================================================
    // BILLS - CRUD
    // ===================================================

    public function getBills(int $schoolId = 1, int $limit = 100): array
    {
        try {
            $sql = "SELECT sb.*, 
                           s.first_name, s.last_name, s.admission_number,
                           ay.year_name, at.term_name,
                           gl.level_name
                    FROM student_bills sb
                    JOIN students s ON sb.student_id = s.id
                    JOIN academic_years ay ON sb.academic_year_id = ay.id
                    JOIN academic_terms at ON sb.academic_term_id = at.id
                    LEFT JOIN grade_levels gl ON s.current_grade_level_id = gl.id
                    WHERE sb.school_id = ? AND sb.is_active = 1
                    ORDER BY sb.created_at DESC
                    LIMIT ?";
            return $this->db->fetchAll($sql, array($schoolId, $limit));
        } catch (Exception $e) {
            $this->logger->error('Failed to get bills: ' . $e->getMessage());
            return array();
        }
    }

    public function getStudentBills(int $studentId): array
    {
        try {
            $sql = "SELECT sb.*, 
                           fc.category_name,
                           COALESCE(
                               (SELECT SUM(amount) FROM payments 
                                WHERE student_bill_id = sb.id AND is_active = 1), 
                               0
                           ) as total_paid
                    FROM student_bills sb
                    LEFT JOIN bill_items bi ON sb.id = bi.bill_id
                    LEFT JOIN fee_categories fc ON bi.fee_category_id = fc.id
                    WHERE sb.student_id = ? AND sb.is_active = 1
                    AND sb.bill_status NOT IN ('paid', 'cancelled', 'void')
                    GROUP BY sb.id
                    ORDER BY sb.due_date ASC";
            return $this->db->fetchAll($sql, array($studentId));
        } catch (Exception $e) {
            $this->logger->error('Failed to get student bills: ' . $e->getMessage());
            return array();
        }
    }

    public function getBillById(int $id): ?array
    {
        try {
            return $this->db->fetchOne(
                "SELECT sb.*, s.first_name, s.last_name, s.admission_number
                FROM student_bills sb
                JOIN students s ON sb.student_id = s.id
                WHERE sb.id = ? AND sb.is_active = 1",
                array($id)
            );
        } catch (Exception $e) {
            $this->logger->error('Failed to get bill: ' . $e->getMessage());
            return null;
        }
    }

    public function generateBills(array $params): array
    {
        $results = array('generated' => 0, 'skipped' => 0, 'errors' => array());
        
        try {
            if (empty($params['academic_year_id']) || empty($params['academic_term_id'])) {
                throw new Exception('Academic year and term are required');
            }

            $structures = $this->getFeeStructuresForBilling(
                $params['academic_year_id'],
                $params['academic_term_id'],
                $params['grade_level_id'] ?? null
            );

            if (empty($structures)) {
                throw new Exception('No fee structures found for the selected criteria');
            }

            $students = $this->getStudentsForBilling($params);
            if (empty($students)) {
                throw new Exception('No students found for the selected criteria');
            }

            $settings = $this->getSettings();
            $dueDays = isset($settings['due_days']) ? (int)$settings['due_days'] : 30;

            foreach ($students as $student) {
                try {
                    $existing = $this->db->fetchOne(
                        "SELECT id FROM student_bills 
                        WHERE student_id = ? AND academic_year_id = ? 
                        AND academic_term_id = ? AND is_active = 1",
                        array($student['id'], $params['academic_year_id'], $params['academic_term_id'])
                    );
                    
                    if ($existing) {
                        $results['skipped']++;
                        continue;
                    }

                    $totalAmount = 0;
                    $billItems = array();
                    foreach ($structures as $structure) {
                        if ($structure['grade_level_id'] == $student['current_grade_level_id']) {
                            $totalAmount += $structure['amount'];
                            $billItems[] = array(
                                'fee_category_id' => $structure['fee_category_id'],
                                'amount' => $structure['amount'],
                                'description' => $structure['category_name'],
                                'fee_structure_id' => $structure['id']
                            );
                        }
                    }

                    if ($totalAmount <= 0) {
                        $results['skipped']++;
                        continue;
                    }

                    $billNumber = $this->generateBillNumber();

                    $billId = $this->createBill(array(
                        'student_id' => $student['id'],
                        'academic_year_id' => $params['academic_year_id'],
                        'academic_term_id' => $params['academic_term_id'],
                        'bill_number' => $billNumber,
                        'total_amount' => $totalAmount,
                        'due_date' => date('Y-m-d', strtotime("+$dueDays days")),
                        'created_by' => $params['issued_by'] ?? 1,
                        'school_id' => 1
                    ));

                    if ($billId) {
                        foreach ($billItems as $item) {
                            $this->addBillItem($billId, $item);
                        }
                        $results['generated']++;
                    } else {
                        $results['errors'][] = "Failed to create bill for student: {$student['id']}";
                    }
                    
                } catch (Exception $e) {
                    $results['errors'][] = "Student {$student['id']}: " . $e->getMessage();
                }
            }
            
            return $results;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to generate bills: ' . $e->getMessage());
            throw $e;
        }
    }

    private function createBill(array $data): ?int
    {
        try {
            $sql = "INSERT INTO student_bills (
                uuid, school_id, student_id, academic_year_id, academic_term_id,
                bill_number, total_amount, amount_paid, balance_due,
                due_date, bill_status, is_active, created_by, created_at
            ) VALUES (
                UUID(), ?, ?, ?, ?, ?, ?, 0, ?, ?, 'issued', 1, ?, NOW()
            )";

            $this->db->query($sql, array(
                $data['school_id'] ?? 1,
                $data['student_id'],
                $data['academic_year_id'],
                $data['academic_term_id'],
                $data['bill_number'],
                $data['total_amount'],
                $data['total_amount'],
                $data['due_date'],
                $data['created_by'] ?? 1
            ));

            return $this->db->lastInsertId();
        } catch (Exception $e) {
            $this->logger->error('Failed to create bill: ' . $e->getMessage());
            throw $e;
        }
    }

    private function addBillItem(int $billId, array $item): bool
    {
        try {
            $sql = "INSERT INTO bill_items (
                bill_id, fee_category_id, fee_structure_id,
                amount, description, is_active, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, 1, NOW()
            )";

            $this->db->query($sql, array(
                $billId,
                $item['fee_category_id'],
                $item['fee_structure_id'] ?? null,
                $item['amount'],
                $item['description']
            ));

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to add bill item: ' . $e->getMessage());
            throw $e;
        }
    }

    public function updateBillBalance(int $billId, float $amount): bool
    {
        try {
            $bill = $this->getBillById($billId);
            if (!$bill) {
                throw new Exception('Bill not found');
            }

            $sql = "UPDATE student_bills 
                    SET 
                        amount_paid = amount_paid + ?,
                        balance_due = GREATEST(0, total_amount - amount_paid - ?),
                        bill_status = CASE 
                            WHEN total_amount - amount_paid - ? <= 0 THEN 'paid'
                            WHEN amount_paid + ? > 0 THEN 'partial'
                            ELSE 'issued'
                        END
                    WHERE id = ? AND is_active = 1";
            
            $this->db->query($sql, array($amount, $amount, $amount, $amount, $billId));
            
            $this->logAudit('student_bills', $billId, 'UPDATE', array('balance_due' => $bill['balance_due']), array('amount_paid_added' => $amount));
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update bill balance: ' . $e->getMessage());
            throw $e;
        }
    }

    public function forwardArrears(int $fromTermId, int $toTermId, int $schoolId = 1): array
    {
        $results = array('forwarded' => 0, 'skipped' => 0);
        
        try {
            $bills = $this->db->fetchAll(
                "SELECT sb.*, s.current_grade_level_id
                FROM student_bills sb
                JOIN students s ON sb.student_id = s.id
                WHERE sb.academic_term_id = ? 
                AND sb.school_id = ?
                AND sb.is_active = 1
                AND sb.bill_status NOT IN ('paid', 'cancelled')
                AND sb.balance_due > 0",
                array($fromTermId, $schoolId)
            );

            foreach ($bills as $bill) {
                try {
                    $existing = $this->db->fetchOne(
                        "SELECT id FROM student_bills 
                        WHERE student_id = ? AND academic_term_id = ? 
                        AND is_forwarded_from = ? AND is_active = 1",
                        array($bill['student_id'], $toTermId, $bill['id'])
                    );
                    
                    if ($existing) {
                        $results['skipped']++;
                        continue;
                    }

                    $existsInTerm = $this->db->fetchOne(
                        "SELECT id FROM student_bills 
                        WHERE student_id = ? AND academic_term_id = ? 
                        AND is_active = 1",
                        array($bill['student_id'], $toTermId)
                    );
                    
                    if ($existsInTerm) {
                        $this->db->query(
                            "UPDATE student_bills 
                            SET total_amount = total_amount + ?,
                                balance_due = balance_due + ?
                            WHERE id = ? AND is_active = 1",
                            array($bill['balance_due'], $bill['balance_due'], $existsInTerm['id'])
                        );
                        $results['forwarded']++;
                        continue;
                    }

                    $billNumber = $this->generateBillNumber();
                    
                    $sql = "INSERT INTO student_bills (
                        uuid, school_id, student_id, academic_year_id, academic_term_id,
                        bill_number, total_amount, amount_paid, balance_due,
                        due_date, bill_status, is_forwarded, is_forwarded_from,
                        is_active, created_by, created_at
                    ) VALUES (
                        UUID(), ?, ?, ?, ?, ?, ?, 0, ?,
                        DATE_ADD(NOW(), INTERVAL 30 DAY), 'issued', 1, ?,
                        1, ?, NOW()
                    )";
                    
                    $this->db->query($sql, array(
                        $schoolId,
                        $bill['student_id'],
                        $bill['academic_year_id'],
                        $toTermId,
                        $billNumber,
                        $bill['balance_due'],
                        $bill['balance_due'],
                        $bill['id'],
                        $bill['created_by'] ?? 1
                    ));
                    
                    $results['forwarded']++;
                    
                } catch (Exception $e) {
                    $this->logger->error('Failed to forward bill: ' . $e->getMessage());
                    $results['skipped']++;
                }
            }
            
            return $results;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to forward arrears: ' . $e->getMessage());
            throw $e;
        }
    }

    // ===================================================
    // PAYMENTS - CRUD
    // ===================================================

    public function getPayments(int $schoolId = 1, int $limit = 100): array
    {
        try {
            $sql = "SELECT p.*, 
                           s.first_name, s.last_name, s.admission_number,
                           sb.bill_number,
                           pm.method_name,
                           u.username as received_by_name
                    FROM payments p
                    JOIN students s ON p.student_id = s.id
                    LEFT JOIN student_bills sb ON p.student_bill_id = sb.id
                    LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
                    LEFT JOIN users u ON p.received_by = u.id
                    WHERE p.school_id = ? AND p.is_active = 1
                    ORDER BY p.created_at DESC
                    LIMIT ?";
            return $this->db->fetchAll($sql, array($schoolId, $limit));
        } catch (Exception $e) {
            $this->logger->error('Failed to get payments: ' . $e->getMessage());
            return array();
        }
    }

    public function getPaymentById(int $id): ?array
    {
        try {
            return $this->db->fetchOne(
                "SELECT p.*, s.first_name, s.last_name, s.admission_number
                FROM payments p
                JOIN students s ON p.student_id = s.id
                WHERE p.id = ? AND p.is_active = 1",
                array($id)
            );
        } catch (Exception $e) {
            $this->logger->error('Failed to get payment: ' . $e->getMessage());
            return null;
        }
    }

    public function createPayment(array $data): ?int
    {
        try {
            $required = array('student_id', 'amount', 'payment_method_id');
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }

            if ($data['amount'] <= 0) {
                throw new Exception('Amount must be greater than zero');
            }

            if (empty($data['receipt_number'])) {
                $settings = $this->getSettings();
                $prefix = $settings['receipt_prefix'] ?? 'REC-';
                $data['receipt_number'] = $this->generateReceiptNumber($prefix);
            }

            $sql = "INSERT INTO payments (
                uuid, school_id, student_id, student_bill_id,
                amount, payment_method_id, payment_date,
                payment_reference, receipt_number, payment_status,
                notes, received_by, is_active, created_at
            ) VALUES (
                UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW()
            )";
            
            $this->db->query($sql, array(
                $data['school_id'] ?? 1,
                $data['student_id'],
                $data['student_bill_id'] ?? null,
                $data['amount'],
                $data['payment_method_id'],
                $data['payment_date'] ?? date('Y-m-d'),
                $data['payment_reference'] ?? null,
                $data['receipt_number'],
                $data['payment_status'] ?? 'completed',
                $data['notes'] ?? null,
                $data['received_by'] ?? 1
            ));
            
            $id = $this->db->lastInsertId();
            $this->logAudit('payments', $id, 'INSERT', null, $data);
            return $id;
        } catch (Exception $e) {
            $this->logger->error('Failed to create payment: ' . $e->getMessage());
            throw $e;
        }
    }

    public function voidPayment(int $id): bool
    {
        try {
            $oldData = $this->getPaymentById($id);
            if (!$oldData) {
                throw new Exception('Payment not found');
            }

            if ($oldData['student_bill_id']) {
                $this->db->query(
                    "UPDATE student_bills 
                    SET amount_paid = GREATEST(0, amount_paid - ?),
                        balance_due = total_amount - GREATEST(0, amount_paid - ?)
                    WHERE id = ? AND is_active = 1",
                    array($oldData['amount'], $oldData['amount'], $oldData['student_bill_id'])
                );
            }

            $sql = "UPDATE payments SET is_active = 0, payment_status = 'voided' WHERE id = ?";
            $this->db->query($sql, array($id));

            $this->logAudit('payments', $id, 'UPDATE', $oldData, array('status' => 'voided'));
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to void payment: ' . $e->getMessage());
            throw $e;
        }
    }

    // ===================================================
    // PAYMENT METHODS - CRUD
    // ===================================================

    public function getPaymentMethods(int $schoolId = 1, bool $activeOnly = true): array
    {
        try {
            $sql = "SELECT * FROM payment_methods WHERE school_id = ?";
            if ($activeOnly) {
                $sql .= " AND is_active = 1";
            }
            $sql .= " ORDER BY method_name";
            return $this->db->fetchAll($sql, array($schoolId));
        } catch (Exception $e) {
            $this->logger->error('Failed to get payment methods: ' . $e->getMessage());
            return array();
        }
    }

    public function createPaymentMethod(array $data): ?int
    {
        try {
            if (empty($data['method_name'])) {
                throw new Exception('Method name is required');
            }

            $sql = "INSERT INTO payment_methods (
                uuid, school_id, method_name, method_code,
                is_active, created_at
            ) VALUES (
                UUID(), ?, ?, ?, 1, NOW()
            )";

            $this->db->query($sql, array(
                $data['school_id'] ?? 1,
                $data['method_name'],
                $data['method_code'] ?? strtoupper(substr($data['method_name'], 0, 10))
            ));

            return $this->db->lastInsertId();
        } catch (Exception $e) {
            $this->logger->error('Failed to create payment method: ' . $e->getMessage());
            throw $e;
        }
    }

    // ===================================================
    // DISCOUNTS - CRUD
    // ===================================================

    public function getDiscounts(int $schoolId = 1, bool $activeOnly = true): array
    {
        try {
            $sql = "SELECT * FROM fee_discounts WHERE school_id = ?";
            if ($activeOnly) {
                $sql .= " AND is_active = 1";
            }
            $sql .= " ORDER BY discount_name";
            return $this->db->fetchAll($sql, array($schoolId));
        } catch (Exception $e) {
            $this->logger->error('Failed to get discounts: ' . $e->getMessage());
            return array();
        }
    }

    public function createDiscount(array $data): ?int
    {
        try {
            $required = array('discount_name', 'discount_type', 'discount_value', 'start_date', 'end_date');
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }

            if ($data['discount_value'] <= 0) {
                throw new Exception('Discount value must be greater than zero');
            }

            $sql = "INSERT INTO fee_discounts (
                uuid, school_id, discount_name, discount_code,
                discount_type, discount_value, start_date, end_date,
                max_uses, uses_count, is_active, created_at
            ) VALUES (
                UUID(), ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, NOW()
            )";

            $this->db->query($sql, array(
                $data['school_id'] ?? 1,
                $data['discount_name'],
                $data['discount_code'] ?? strtoupper(substr($data['discount_name'], 0, 10)),
                $data['discount_type'],
                $data['discount_value'],
                $data['start_date'],
                $data['end_date'],
                $data['max_uses'] ?? null
            ));

            return $this->db->lastInsertId();
        } catch (Exception $e) {
            $this->logger->error('Failed to create discount: ' . $e->getMessage());
            throw $e;
        }
    }

    // ===================================================
    // SETTINGS
    // ===================================================

    public function getSettings(): array
    {
        try {
            if (!empty($this->settingsCache)) {
                return $this->settingsCache;
            }

            $settings = array();
            $rows = $this->db->fetchAll(
                "SELECT setting_key, setting_value 
                FROM finance_settings 
                WHERE is_active = 1"
            );
            
            foreach ($rows as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            
            $this->settingsCache = $settings;
            return $settings;
        } catch (Exception $e) {
            $this->logger->error('Failed to get settings: ' . $e->getMessage());
            return array();
        }
    }

    public function saveSettings(array $settings, int $schoolId = 1): bool
    {
        try {
            foreach ($settings as $key => $value) {
                $existing = $this->db->fetchOne(
                    "SELECT id FROM finance_settings WHERE setting_key = ? AND school_id = ?",
                    array($key, $schoolId)
                );
                
                if ($existing) {
                    $this->db->query(
                        "UPDATE finance_settings 
                        SET setting_value = ? 
                        WHERE setting_key = ? AND school_id = ?",
                        array($value, $key, $schoolId)
                    );
                } else {
                    $this->db->query(
                        "INSERT INTO finance_settings (uuid, school_id, setting_key, setting_value, setting_category, is_active)
                        VALUES (UUID(), ?, ?, ?, 'general', 1)",
                        array($schoolId, $key, $value)
                    );
                }
            }
            
            $this->settingsCache = array();
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to save settings: ' . $e->getMessage());
            throw $e;
        }
    }

    // ===================================================
    // HELPERS
    // ===================================================

    private function getFeeStructuresForBilling(int $academicYearId, int $academicTermId, ?int $gradeLevelId = null): array
    {
        $sql = "SELECT fs.*, fc.category_name 
                FROM fee_structures fs
                JOIN fee_categories fc ON fs.fee_category_id = fc.id
                WHERE fs.academic_year_id = ? 
                AND fs.academic_term_id = ?
                AND fs.is_active = 1
                AND fc.is_active = 1";
        $params = array($academicYearId, $academicTermId);

        if ($gradeLevelId) {
            $sql .= " AND fs.grade_level_id = ?";
            $params[] = $gradeLevelId;
        }

        $sql .= " ORDER BY fs.grade_level_id, fc.category_name";
        return $this->db->fetchAll($sql, $params);
    }

    private function getStudentsForBilling(array $params): array
    {
        $sql = "SELECT s.id, s.first_name, s.last_name, s.admission_number, 
                       s.current_grade_level_id
                FROM students s
                WHERE s.is_active = 1 
                AND s.current_grade_level_id IS NOT NULL";
        $queryParams = array();

        if (!empty($params['grade_level_id'])) {
            $sql .= " AND s.current_grade_level_id = ?";
            $queryParams[] = $params['grade_level_id'];
        }
        
        if (!empty($params['student_id'])) {
            $sql .= " AND s.id = ?";
            $queryParams[] = $params['student_id'];
        }

        $sql .= " ORDER BY s.first_name";
        return $this->db->fetchAll($sql, $queryParams);
    }

    public function generateBillNumber(): string
    {
        $year = date('Y');
        $count = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM student_bills WHERE YEAR(created_at) = ?",
            array($year)
        );
        $count = ($count['count'] ?? 0) + 1;
        return 'BILL-' . $year . '-' . str_pad($count, 6, '0', STR_PAD_LEFT);
    }

    public function generateReceiptNumber(string $prefix = 'REC-'): string
    {
        $year = date('Y');
        $count = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM payments WHERE YEAR(created_at) = ?",
            array($year)
        );
        $count = ($count['count'] ?? 0) + 1;
        return $prefix . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
    }

    private function logAudit(string $table, int $recordId, string $action, ?array $oldData = null, ?array $newData = null): void
    {
        try {
            $sql = "INSERT INTO audit_logs (
                uuid, table_name, record_id, action_type,
                old_data, new_data, user_id, ip_address, user_agent, created_at
            ) VALUES (
                UUID(), ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )";

            $this->db->query($sql, array(
                $table,
                $recordId,
                $action,
                $oldData ? json_encode($oldData) : null,
                $newData ? json_encode($newData) : null,
                $_SESSION['user_id'] ?? null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ));
        } catch (Exception $e) {
            $this->logger->error('Failed to log audit: ' . $e->getMessage());
        }
    }
}