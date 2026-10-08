<?php
/**
 * Fee Service - Enterprise Fee Management
 * Handles auto-forwarding, credits, and notifications
 */
class FeeService
{
    private $db;
    private $logger;
    
    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
    }
    
    /**
     * Auto-forward arrears when term changes
     */
    public function autoForwardArrears($oldTermId, $newTermId)
    {
        try {
            $this->db->beginTransaction();
            
            // Get term info
            $oldTerm = $this->db->fetchOne("SELECT * FROM academic_terms WHERE id = ?", [$oldTermId]);
            $newTerm = $this->db->fetchOne("SELECT * FROM academic_terms WHERE id = ?", [$newTermId]);
            
            if (!$oldTerm || !$newTerm) {
                throw new Exception('Term not found');
            }
            
            // Get all outstanding bills
            $outstandingBills = $this->db->fetchAll("
                SELECT sb.*, s.first_name, s.last_name, s.email, s.primary_phone
                FROM student_bills sb
                JOIN students s ON sb.student_id = s.id
                WHERE sb.school_id = 1 
                AND sb.is_active = 1 
                AND sb.bill_status NOT IN ('paid', 'cancelled', 'void')
                AND sb.balance_due > 0
                AND sb.academic_term_id = ?
            ", [$oldTermId]);
            
            $forwarded = 0;
            $credits = 0;
            
            // Handle credits (advance payments)
            $creditsData = $this->db->fetchAll("
                SELECT * FROM student_credits 
                WHERE school_id = 1 
                AND is_active = 1 
                AND balance > 0
                AND academic_term_id = ?
            ", [$oldTermId]);
            
            foreach ($creditsData as $credit) {
                // Apply credit to outstanding bills first
                if ($credit['balance'] > 0) {
                    $this->applyCreditToBills($credit['student_id'], $credit['balance'], $newTermId);
                    $credits++;
                }
            }
            
            // Forward arrears
            foreach ($outstandingBills as $bill) {
                // Check if already forwarded
                $existing = $this->db->fetchOne("
                    SELECT id FROM student_bills 
                    WHERE original_bill_id = ? AND is_arrears = 1 AND is_active = 1
                ", [$bill['id']]);
                
                if (!$existing) {
                    $this->forwardBillToNextTerm($bill, $newTerm);
                    $forwarded++;
                    
                    // Send notification
                    $this->sendArrearsNotification($bill, $oldTerm, $newTerm);
                }
            }
            
            // Update student fee summaries
            $this->updateAllStudentSummaries();
            
            $this->db->commit();
            
            return [
                'success' => true,
                'forwarded' => $forwarded,
                'credits_applied' => $credits,
                'message' => "Auto-forwarded $forwarded bills and applied $credits credits"
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            $this->logger->error('Auto-forward failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Forward a single bill to the next term
     */
    private function forwardBillToNextTerm($bill, $newTerm)
    {
        $arrearsBillNumber = 'ARR-' . date('Ymd') . '-' . str_pad($bill['student_id'], 6, '0', STR_PAD_LEFT) . '-' . time();
        
        // Check if student has credits to apply
        $credit = $this->db->fetchOne("
            SELECT * FROM student_credits 
            WHERE student_id = ? 
            AND is_active = 1 
            AND balance > 0
            ORDER BY created_at ASC
            LIMIT 1
        ", [$bill['student_id']]);
        
        $balanceToForward = $bill['balance_due'];
        $creditApplied = 0;
        
        // Apply credit if available
        if ($credit) {
            $creditApplied = min($credit['balance'], $balanceToForward);
            $balanceToForward -= $creditApplied;
            
            // Update credit
            $this->db->query("UPDATE student_credits SET 
                used_amount = used_amount + ?,
                balance = balance - ?,
                updated_at = NOW()
                WHERE id = ?", 
                [$creditApplied, $creditApplied, $credit['id']]);
        }
        
        if ($balanceToForward > 0) {
            // Insert arrears bill
            $sql = "INSERT INTO student_bills (
                uuid, school_id, student_id, academic_year_id, academic_term_id,
                bill_number, bill_date, due_date, subtotal, total_amount,
                amount_paid, balance_due, currency, bill_status,
                is_arrears, original_bill_id, arrears_from_term_id, arrears_from_year_id,
                forwarded_to_term_id, forwarded_to_year_id, forwarded_date,
                auto_forwarded, total_with_arrears, is_active
            ) VALUES (
                UUID(), 1, ?, ?, ?,
                ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY),
                ?, ?, 0, ?, 'GHS', 'issued',
                1, ?, ?, ?,
                ?, ?, CURDATE(),
                1, ?, 1
            )";
            
            $this->db->query($sql, [
                $bill['student_id'],
                $bill['academic_year_id'],
                $newTerm['id'],
                $arrearsBillNumber,
                $balanceToForward,
                $balanceToForward,
                $balanceToForward,
                $bill['id'],
                $bill['academic_term_id'],
                $bill['academic_year_id'],
                $newTerm['id'],
                $newTerm['academic_year_id'],
                $balanceToForward
            ]);
            
            $newBillId = $this->db->lastInsertId();
            
            // Log the forwarding
            $this->db->query("INSERT INTO arrears_log (
                uuid, school_id, student_id, bill_id, amount,
                from_academic_year_id, from_academic_term_id,
                to_academic_year_id, to_academic_term_id,
                forwarded_date, notes
            ) VALUES (
                UUID(), 1, ?, ?, ?,
                ?, ?,
                ?, ?,
                CURDATE(), ?
            )", [
                $bill['student_id'],
                $bill['id'],
                $balanceToForward,
                $bill['academic_year_id'],
                $bill['academic_term_id'],
                $newTerm['academic_year_id'],
                $newTerm['id'],
                'Auto-forwarded: ' . ($creditApplied > 0 ? "Credit of GHS $creditApplied applied. " : "") . "Remaining balance forwarded."
            ]);
            
            // Send notification for arrears
            $this->sendArrearsNotification($bill, null, $newTerm, $balanceToForward);
        }
        
        // Update original bill
        $this->db->query("UPDATE student_bills SET 
            forwarded_to_term_id = ?,
            forwarded_to_year_id = ?,
            forwarded_date = CURDATE(),
            auto_forwarded = 1,
            updated_at = NOW()
            WHERE id = ?", 
            [$newTerm['id'], $newTerm['academic_year_id'], $bill['id']]);
    }
    
    /**
     * Apply credit to bills
     */
    private function applyCreditToBills($studentId, $creditAmount, $newTermId)
    {
        $outstandingBills = $this->db->fetchAll("
            SELECT * FROM student_bills 
            WHERE student_id = ? 
            AND is_active = 1 
            AND bill_status NOT IN ('paid', 'cancelled', 'void')
            AND balance_due > 0
            ORDER BY due_date ASC
        ", [$studentId]);
        
        $remainingCredit = $creditAmount;
        
        foreach ($outstandingBills as $bill) {
            if ($remainingCredit <= 0) break;
            
            $applyAmount = min($remainingCredit, $bill['balance_due']);
            $newBalance = $bill['balance_due'] - $applyAmount;
            $status = $newBalance <= 0 ? 'paid' : 'partially_paid';
            
            $this->db->query("UPDATE student_bills SET 
                amount_paid = amount_paid + ?,
                balance_due = ?,
                bill_status = ?,
                credit_amount = credit_amount + ?,
                updated_at = NOW()
                WHERE id = ?", 
                [$applyAmount, $newBalance, $status, $applyAmount, $bill['id']]);
            
            $remainingCredit -= $applyAmount;
            
            // Log credit application
            $this->db->query("INSERT INTO arrears_log (
                uuid, school_id, student_id, bill_id, amount,
                from_academic_year_id, from_academic_term_id,
                to_academic_year_id, to_academic_term_id,
                forwarded_date, notes
            ) VALUES (
                UUID(), 1, ?, ?, ?,
                ?, ?,
                ?, ?,
                CURDATE(), ?
            )", [
                $studentId,
                $bill['id'],
                $applyAmount,
                $bill['academic_year_id'],
                $bill['academic_term_id'],
                $bill['academic_year_id'],
                $bill['academic_term_id'],
                "Credit applied to bill #{$bill['bill_number']}"
            ]);
        }
    }
    
    /**
     * Send arrears notification
     */
    private function sendArrearsNotification($bill, $oldTerm, $newTerm, $amount = null)
    {
        $student = $this->db->fetchOne("SELECT * FROM students WHERE id = ?", [$bill['student_id']]);
        $amount = $amount ?: $bill['balance_due'];
        
        $this->createNotification(
            $bill['student_id'],
            $bill['id'],
            'arrears_forwarded',
            'Arrears Forwarded: ' . $bill['bill_number'],
            "Dear {$student['first_name']} {$student['last_name']},\n\n" .
            "Your unpaid balance of GHS " . number_format($amount, 2) . 
            " from " . ($oldTerm ? $oldTerm['term_name'] : 'previous term') . 
            " has been automatically forwarded to " . $newTerm['term_name'] . ".\n\n" .
            "This amount will be added to your current term bill.\n\n" .
            "Please make payment arrangements.\n\nThank you,\nChurch of Christ International School"
        );
    }
    
    /**
     * Create a notification
     */
    public function createNotification($studentId, $billId, $type, $subject, $message)
    {
        $sql = "INSERT INTO finance_notifications (
            uuid, school_id, student_id, bill_id, notification_type,
            subject, message, sent_via, created_at
        ) VALUES (
            UUID(), 1, ?, ?, ?,
            ?, ?, 'in_app', NOW()
        )";
        
        $this->db->query($sql, [$studentId, $billId, $type, $subject, $message]);
        return $this->db->lastInsertId();
    }
    
    /**
     * Send payment confirmation notification
     */
    public function sendPaymentConfirmation($paymentId, $billId)
    {
        $payment = $this->db->fetchOne("
            SELECT p.*, pm.method_name, s.first_name, s.last_name, s.email
            FROM payments p
            JOIN payment_methods pm ON p.payment_method_id = pm.id
            JOIN students s ON p.student_id = s.id
            WHERE p.id = ?
        ", [$paymentId]);
        
        $bill = $this->db->fetchOne("SELECT * FROM student_bills WHERE id = ?", [$billId]);
        
        if ($payment) {
            $subject = "Payment Confirmation: " . $payment['payment_number'];
            $message = "Dear {$payment['first_name']} {$payment['last_name']},\n\n" .
                "We have received your payment of GHS " . number_format($payment['amount'], 2) . 
                " for " . ($bill ? $bill['bill_number'] : 'bill') . ".\n\n" .
                "Payment Details:\n" .
                "- Receipt Number: " . $payment['receipt_number'] . "\n" .
                "- Date: " . date('d M Y', strtotime($payment['payment_date'])) . "\n" .
                "- Method: " . $payment['method_name'] . "\n\n" .
                "Thank you for your prompt payment.\n\n" .
                "Church of Christ International School";
            
            $this->createNotification(
                $payment['student_id'],
                $billId,
                'payment_confirmation',
                $subject,
                $message
            );
            
            return true;
        }
        return false;
    }
    
    /**
     * Send overdue reminder
     */
    public function sendOverdueReminder($billId)
    {
        $bill = $this->db->fetchOne("
            SELECT sb.*, s.first_name, s.last_name, s.email, s.primary_phone
            FROM student_bills sb
            JOIN students s ON sb.student_id = s.id
            WHERE sb.id = ?
        ", [$billId]);
        
        if ($bill && $bill['bill_status'] === 'overdue') {
            $daysOverdue = floor((time() - strtotime($bill['due_date'])) / (60 * 60 * 24));
            
            $subject = "OVERDUE: " . $bill['bill_number'] . " Payment Required";
            $message = "Dear {$bill['first_name']} {$bill['last_name']},\n\n" .
                "Your payment for " . $bill['bill_number'] . " of GHS " . number_format($bill['balance_due'], 2) . 
                " is now OVERDUE by " . $daysOverdue . " days.\n\n" .
                "The total amount due is GHS " . number_format($bill['balance_due'], 2) . 
                " including late fees.\n\n" .
                "Please make immediate payment to avoid further charges.\n\n" .
                "Thank you,\nChurch of Christ International School";
            
            $this->createNotification(
                $bill['student_id'],
                $billId,
                'overdue',
                $subject,
                $message
            );
            
            return true;
        }
        return false;
    }
    
    /**
     * Update all student fee summaries
     */
    private function updateAllStudentSummaries()
    {
        $students = $this->db->fetchAll("SELECT id FROM students WHERE school_id = 1 AND is_active = 1");
        
        foreach ($students as $student) {
            $sql = "SELECT 
                sb.academic_year_id,
                sb.academic_term_id,
                SUM(sb.total_amount) AS total_billed,
                SUM(sb.amount_paid) AS total_paid,
                SUM(sb.balance_due) AS total_balance,
                SUM(CASE WHEN sb.is_arrears = 1 THEN sb.balance_due ELSE 0 END) AS total_arrears,
                SUM(CASE WHEN sb.is_credit = 1 THEN sb.credit_amount ELSE 0 END) AS total_credits
            FROM student_bills sb
            WHERE sb.student_id = ?
            AND sb.is_active = 1
            GROUP BY sb.academic_year_id, sb.academic_term_id";
            
            $summaries = $this->db->fetchAll($sql, [$student['id']]);
            
            foreach ($summaries as $summary) {
                $existing = $this->db->fetchOne("
                    SELECT id FROM student_fee_summary 
                    WHERE student_id = ? AND academic_year_id = ? AND academic_term_id = ?
                ", [$student['id'], $summary['academic_year_id'], $summary['academic_term_id']]);
                
                if ($existing) {
                    $this->db->query("UPDATE student_fee_summary SET 
                        total_billed = ?,
                        total_paid = ?,
                        total_balance = ?,
                        total_arrears = ?,
                        is_cleared = CASE WHEN ? <= 0 THEN 1 ELSE 0 END,
                        cleared_date = CASE WHEN ? <= 0 THEN CURDATE() ELSE NULL END,
                        last_updated = NOW()
                        WHERE id = ?",
                        [$summary['total_billed'], $summary['total_paid'], 
                         $summary['total_balance'], $summary['total_arrears'],
                         $summary['total_balance'], $summary['total_balance'], $existing['id']]);
                }
            }
        }
    }
    
    /**
     * Check and send overdue notifications (cron job)
     */
    public function checkOverdueNotifications()
    {
        $overdueBills = $this->db->fetchAll("
            SELECT sb.*, s.first_name, s.last_name, s.email
            FROM student_bills sb
            JOIN students s ON sb.student_id = s.id
            WHERE sb.school_id = 1 
            AND sb.is_active = 1 
            AND sb.bill_status = 'overdue'
            AND sb.balance_due > 0
            AND DATE_ADD(sb.updated_at, INTERVAL 1 DAY) <= NOW()
        ");
        
        $sent = 0;
        foreach ($overdueBills as $bill) {
            $existing = $this->db->fetchOne("
                SELECT id FROM finance_notifications 
                WHERE bill_id = ? AND notification_type = 'overdue' 
                AND DATE(sent_date) = CURDATE()
            ", [$bill['id']]);
            
            if (!$existing) {
                $this->sendOverdueReminder($bill['id']);
                $sent++;
            }
        }
        
        return ['sent' => $sent, 'total' => count($overdueBills)];
    }
    
    /**
     * Handle term change - called when admin changes current term
     */
    public function handleTermChange($newTermId)
    {
        // Get current term before change
        $currentTerm = $this->db->fetchOne("SELECT id FROM academic_terms WHERE is_current = 1 AND school_id = 1");
        
        if ($currentTerm && $currentTerm['id'] != $newTermId) {
            // Auto-forward arrears
            $result = $this->autoForwardArrears($currentTerm['id'], $newTermId);
            
            // Log the action
            $this->logger->info('Term changed - auto-forward executed', [
                'from_term' => $currentTerm['id'],
                'to_term' => $newTermId,
                'result' => $result
            ]);
            
            return $result;
        }
        
        return ['success' => true, 'message' => 'No term change detected'];
    }
}