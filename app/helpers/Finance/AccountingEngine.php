<?php
/**
 * AccountingEngine.php
 *
 * Enterprise Accounting Engine
 * Automatically posts financial events to the general ledger
 *
 * @package EduTrack
 * @subpackage Helpers\Finance
 * @version 1.0
 */

require_once __DIR__ . '/../../models/Finance/FinanceModel.php';
require_once __DIR__ . '/../../helpers/DatabaseHelper.php';
require_once __DIR__ . '/../../helpers/LoggerHelper.php';

class AccountingEngine
{
    /**
     * @var DatabaseHelper Database instance
     */
    private $db;

    /**
     * @var LoggerHelper Logger instance
     */
    private $logger;

    /**
     * @var array Account ID mapping
     */
    private $accountMap = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
        $this->loadAccountMap();
    }

    /**
     * Load account mapping
     */
    private function loadAccountMap(): void
    {
        try {
            $accounts = $this->db->fetchAll("SELECT id, account_code FROM chart_of_accounts WHERE is_active = 1");
            foreach ($accounts as $account) {
                $this->accountMap[$account['account_code']] = $account['id'];
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to load account map', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get account ID by code
     */
    private function getAccountId(string $accountCode): ?int
    {
        return $this->accountMap[$accountCode] ?? null;
    }

    /**
     * Post journal entry
     */
    public function postJournalEntry(array $data): bool
    {
        try {
            $required = ['journal_date', 'description', 'entries'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: {$field}");
                }
            }

            $this->db->beginTransaction();

            $journalNumber = 'JE-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

            $totalDebit = array_sum(array_column($data['entries'], 'debit'));
            $totalCredit = array_sum(array_column($data['entries'], 'credit'));

            if (abs($totalDebit - $totalCredit) > 0.01) {
                throw new Exception('Debit and Credit totals must balance');
            }

            $sql = "INSERT INTO journal_entries (
                        uuid, school_id, journal_number, journal_date,
                        description, reference_type, reference_id,
                        total_debit, total_credit, status,
                        created_by, created_at
                    ) VALUES (
                        UUID(), ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, 'posted',
                        ?, NOW()
                    )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $journalNumber,
                $data['journal_date'],
                $data['description'],
                $data['reference_type'] ?? null,
                $data['reference_id'] ?? null,
                $totalDebit,
                $totalCredit,
                $data['created_by'] ?? null
            ]);

            $journalId = $this->db->lastInsertId();

            // Post entries
            foreach ($data['entries'] as $entry) {
                $accountId = $this->getAccountId($entry['account_code']);
                if (!$accountId) {
                    throw new Exception("Account code not found: {$entry['account_code']}");
                }

                $sql = "INSERT INTO journal_entry_details (
                            uuid, journal_entry_id, account_id,
                            debit_amount, credit_amount,
                            description, created_at
                        ) VALUES (
                            UUID(), ?, ?,
                            ?, ?,
                            ?, NOW()
                        )";

                $this->db->query($sql, [
                    $journalId,
                    $accountId,
                    $entry['debit'] ?? 0,
                    $entry['credit'] ?? 0,
                    $entry['description'] ?? $data['description']
                ]);

                $this->updateGeneralLedger($accountId, $entry['debit'] ?? 0, $entry['credit'] ?? 0, $journalId, $data['journal_date']);
            }

            $this->db->commit();
            return true;

        } catch (Exception $e) {
            $this->db->rollback();
            $this->logger->error('Failed to post journal entry', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Update general ledger
     */
    private function updateGeneralLedger(int $accountId, float $debit, float $credit, int $journalId, string $date): void
    {
        try {
            $current = $this->db->fetchOne(
                "SELECT balance FROM general_ledger WHERE account_id = ? ORDER BY id DESC LIMIT 1",
                [$accountId]
            );

            $previousBalance = $current['balance'] ?? 0;
            $newBalance = $previousBalance + $debit - $credit;

            $sql = "INSERT INTO general_ledger (
                        uuid, account_id, journal_entry_id,
                        transaction_date, debit_amount, credit_amount,
                        balance, created_at
                    ) VALUES (
                        UUID(), ?, ?,
                        ?, ?, ?,
                        ?, NOW()
                    )";

            $this->db->query($sql, [
                $accountId,
                $journalId,
                $date,
                $debit,
                $credit,
                $newBalance
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to update general ledger', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Record bill generation
     */
    public function recordBillGenerated(int $billId, float $amount, int $studentId): bool
    {
        try {
            $bill = $this->db->fetchOne("SELECT * FROM student_bills WHERE id = ?", [$billId]);
            if (!$bill) {
                throw new Exception('Bill not found');
            }

            $entries = [
                [
                    'account_code' => '1300', // Accounts Receivable
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => "Bill #{$bill['bill_number']} generated"
                ],
                [
                    'account_code' => '4100', // Tuition Fees (or appropriate revenue account)
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => "Bill #{$bill['bill_number']} revenue"
                ]
            ];

            return $this->postJournalEntry([
                'school_id' => 1,
                'journal_date' => date('Y-m-d'),
                'description' => "Bill #{$bill['bill_number']} generated",
                'reference_type' => 'bill',
                'reference_id' => $billId,
                'entries' => $entries,
                'created_by' => $_SESSION['user_id'] ?? 1
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to record bill generation', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Record payment
     */
    public function recordPayment(int $paymentId): bool
    {
        try {
            $payment = $this->db->fetchOne("
                SELECT p.*, sb.bill_number, sb.student_id
                FROM payments p
                LEFT JOIN student_bills sb ON p.student_bill_id = sb.id
                WHERE p.id = ?
            ", [$paymentId]);

            if (!$payment) {
                throw new Exception('Payment not found');
            }

            $entries = [];

            $entries[] = [
                'account_code' => '1200', // Cash at Bank
                'debit' => $payment['amount'],
                'credit' => 0,
                'description' => "Payment #{$payment['payment_number']} received"
            ];

            $entries[] = [
                'account_code' => '1300', // Accounts Receivable
                'debit' => 0,
                'credit' => $payment['amount'],
                'description' => "Payment #{$payment['payment_number']} applied to bill"
            ];

            return $this->postJournalEntry([
                'school_id' => 1,
                'journal_date' => $payment['payment_date'],
                'description' => "Payment #{$payment['payment_number']} received",
                'reference_type' => 'payment',
                'reference_id' => $paymentId,
                'entries' => $entries,
                'created_by' => $_SESSION['user_id'] ?? 1
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to record payment', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Generate trial balance
     */
    public function generateTrialBalance(string $asAtDate): array
    {
        try {
            $sql = "SELECT 
                        ca.account_code,
                        ca.account_name,
                        COALESCE(SUM(gl.debit_amount), 0) AS total_debit,
                        COALESCE(SUM(gl.credit_amount), 0) AS total_credit,
                        COALESCE(SUM(gl.debit_amount) - SUM(gl.credit_amount), 0) AS balance
                    FROM chart_of_accounts ca
                    LEFT JOIN general_ledger gl ON ca.id = gl.account_id
                    WHERE ca.is_active = 1
                    AND (gl.transaction_date <= ? OR gl.transaction_date IS NULL)
                    GROUP BY ca.id
                    ORDER BY ca.account_code";

            $results = $this->db->fetchAll($sql, [$asAtDate]);

            $totalDebit = 0;
            $totalCredit = 0;

            foreach ($results as $row) {
                $totalDebit += $row['total_debit'];
                $totalCredit += $row['total_credit'];
            }

            return [
                'as_at_date' => $asAtDate,
                'accounts' => $results,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to generate trial balance', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Generate income statement
     */
    public function generateIncomeStatement(string $fromDate, string $toDate): array
    {
        try {
            $revenue = $this->db->fetchAll("
                SELECT ca.account_code, ca.account_name,
                       COALESCE(SUM(gl.credit_amount) - SUM(gl.debit_amount), 0) AS amount
                FROM chart_of_accounts ca
                LEFT JOIN general_ledger gl ON ca.id = gl.account_id
                WHERE ca.account_code BETWEEN '4000' AND '4999'
                AND ca.is_active = 1
                AND (gl.transaction_date BETWEEN ? AND ? OR gl.transaction_date IS NULL)
                GROUP BY ca.id
                ORDER BY ca.account_code
            ", [$fromDate, $toDate]);

            $expenses = $this->db->fetchAll("
                SELECT ca.account_code, ca.account_name,
                       COALESCE(SUM(gl.debit_amount) - SUM(gl.credit_amount), 0) AS amount
                FROM chart_of_accounts ca
                LEFT JOIN general_ledger gl ON ca.id = gl.account_id
                WHERE ca.account_code BETWEEN '5000' AND '5999'
                AND ca.is_active = 1
                AND (gl.transaction_date BETWEEN ? AND ? OR gl.transaction_date IS NULL)
                GROUP BY ca.id
                ORDER BY ca.account_code
            ", [$fromDate, $toDate]);

            $totalRevenue = array_sum(array_column($revenue, 'amount'));
            $totalExpenses = array_sum(array_column($expenses, 'amount'));
            $netIncome = $totalRevenue - $totalExpenses;

            return [
                'period' => ['from' => $fromDate, 'to' => $toDate],
                'revenue' => $revenue,
                'expenses' => $expenses,
                'total_revenue' => $totalRevenue,
                'total_expenses' => $totalExpenses,
                'net_income' => $netIncome
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to generate income statement', ['error' => $e->getMessage()]);
            return [];
        }
    }
}