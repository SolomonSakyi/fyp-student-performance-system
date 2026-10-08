-- ============================================
-- DATABASE: student_performance_system
-- MODULE: Finance Module - Additional Tables
-- ============================================

USE student_performance_system;

-- ============================================
-- 1. CHART OF ACCOUNTS TABLE
-- ============================================
DROP TABLE IF EXISTS chart_of_accounts;
CREATE TABLE chart_of_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    account_code VARCHAR(20) NOT NULL,
    account_name VARCHAR(100) NOT NULL,
    account_type ENUM('asset','liability','equity','revenue','expense') NOT NULL,
    parent_code VARCHAR(20) DEFAULT NULL,
    is_control TINYINT(1) NOT NULL DEFAULT 0,
    normal_balance ENUM('debit','credit') NOT NULL DEFAULT 'debit',
    description TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uuid (uuid),
    UNIQUE KEY uq_account_code (school_id, account_code),
    INDEX idx_account_type (account_type),
    INDEX idx_parent_code (parent_code),
    INDEX idx_school_active (school_id, is_active),
    CONSTRAINT fk_coa_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. JOURNAL ENTRIES TABLE
-- ============================================
DROP TABLE IF EXISTS journal_entries;
CREATE TABLE journal_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    journal_number VARCHAR(50) NOT NULL,
    journal_date DATE NOT NULL,
    description TEXT NOT NULL,
    reference_type VARCHAR(50) DEFAULT NULL,
    reference_id BIGINT UNSIGNED DEFAULT NULL,
    total_debit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_credit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(3) NOT NULL DEFAULT 'GHS',
    status ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft',
    posted_by BIGINT UNSIGNED DEFAULT NULL,
    posted_date TIMESTAMP NULL DEFAULT NULL,
    reversed_by BIGINT UNSIGNED DEFAULT NULL,
    reversed_date TIMESTAMP NULL DEFAULT NULL,
    reversal_reason TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uuid (uuid),
    UNIQUE KEY uq_journal_number (school_id, journal_number),
    INDEX idx_journal_date (journal_date),
    INDEX idx_status (status),
    INDEX idx_reference (reference_type, reference_id),
    INDEX idx_school_active (school_id, is_active),
    CONSTRAINT fk_je_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_je_posted_by FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. JOURNAL ENTRY DETAILS TABLE
-- ============================================
DROP TABLE IF EXISTS journal_entry_details;
CREATE TABLE journal_entry_details (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    journal_entry_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    debit_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    credit_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    description TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uuid (uuid),
    INDEX idx_journal_entry (journal_entry_id),
    INDEX idx_account (account_id),
    CONSTRAINT fk_jed_journal_entry FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_jed_account FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. GENERAL LEDGER TABLE
-- ============================================
DROP TABLE IF EXISTS general_ledger;
CREATE TABLE general_ledger (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    journal_entry_id BIGINT UNSIGNED NOT NULL,
    transaction_date DATE NOT NULL,
    debit_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    credit_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    description TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uuid (uuid),
    INDEX idx_account (account_id),
    INDEX idx_journal_entry (journal_entry_id),
    INDEX idx_transaction_date (transaction_date),
    CONSTRAINT fk_gl_account FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_gl_journal_entry FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. PAYMENT GATEWAY CONFIGS TABLE
-- ============================================
DROP TABLE IF EXISTS payment_gateway_configs;
CREATE TABLE payment_gateway_configs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    gateway_name VARCHAR(100) NOT NULL,
    gateway_code VARCHAR(50) NOT NULL,
    config_data JSON NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_test_mode TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uuid (uuid),
    UNIQUE KEY uq_gateway_code (school_id, gateway_code),
    INDEX idx_school_active (school_id, is_active),
    INDEX idx_is_default (is_default),
    CONSTRAINT fk_pgc_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. PAYMENT GATEWAY LOGS TABLE
-- ============================================
DROP TABLE IF EXISTS payment_gateway_logs;
CREATE TABLE payment_gateway_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    gateway_code VARCHAR(50) NOT NULL,
    transaction_id VARCHAR(100) DEFAULT NULL,
    payment_id BIGINT UNSIGNED DEFAULT NULL,
    action VARCHAR(50) NOT NULL,
    request_data JSON DEFAULT NULL,
    response_data JSON DEFAULT NULL,
    status VARCHAR(50) NOT NULL,
    error_message TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uuid (uuid),
    INDEX idx_gateway_code (gateway_code),
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_payment_id (payment_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    CONSTRAINT fk_pgl_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pgl_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 7. FINANCE SETTINGS TABLE
-- ============================================
DROP TABLE IF EXISTS finance_settings;
CREATE TABLE finance_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    setting_category VARCHAR(50) NOT NULL DEFAULT 'general',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uuid (uuid),
    UNIQUE KEY uq_setting_key (school_id, setting_key),
    INDEX idx_category (setting_category),
    INDEX idx_school_active (school_id, is_active),
    CONSTRAINT fk_fs_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 8. ARREARS LOG TABLE
-- ============================================
DROP TABLE IF EXISTS arrears_log;
CREATE TABLE arrears_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    bill_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    from_academic_year_id BIGINT UNSIGNED NOT NULL,
    from_academic_term_id BIGINT UNSIGNED NOT NULL,
    to_academic_year_id BIGINT UNSIGNED NOT NULL,
    to_academic_term_id BIGINT UNSIGNED NOT NULL,
    forwarded_date DATE NOT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uuid (uuid),
    INDEX idx_student (student_id),
    INDEX idx_bill (bill_id),
    INDEX idx_forwarded_date (forwarded_date),
    CONSTRAINT fk_al_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_al_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_al_bill FOREIGN KEY (bill_id) REFERENCES student_bills(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_al_from_year FOREIGN KEY (from_academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_al_from_term FOREIGN KEY (from_academic_term_id) REFERENCES academic_terms(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_al_to_year FOREIGN KEY (to_academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_al_to_term FOREIGN KEY (to_academic_term_id) REFERENCES academic_terms(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 9. INSERT DEFAULT CHART OF ACCOUNTS
-- ============================================

INSERT INTO chart_of_accounts (uuid, school_id, account_code, account_name, account_type, parent_code, is_control, normal_balance, is_active) VALUES
-- ========================================
-- ASSETS (1000-1999)
-- ========================================
(UUID(), 1, '1000', 'Current Assets', 'asset', NULL, 1, 'debit', 1),
(UUID(), 1, '1100', 'Cash in Hand', 'asset', '1000', 0, 'debit', 1),
(UUID(), 1, '1200', 'Cash at Bank', 'asset', '1000', 0, 'debit', 1),
(UUID(), 1, '1300', 'Accounts Receivable', 'asset', '1000', 0, 'debit', 1),
(UUID(), 1, '1400', 'Prepaid Expenses', 'asset', '1000', 0, 'debit', 1),
(UUID(), 1, '1500', 'Fixed Assets', 'asset', NULL, 1, 'debit', 1),
(UUID(), 1, '1510', 'Land and Buildings', 'asset', '1500', 0, 'debit', 1),
(UUID(), 1, '1520', 'Furniture and Equipment', 'asset', '1500', 0, 'debit', 1),
(UUID(), 1, '1530', 'Vehicles', 'asset', '1500', 0, 'debit', 1),
(UUID(), 1, '1540', 'Accumulated Depreciation', 'asset', '1500', 0, 'credit', 1),

-- ========================================
-- LIABILITIES (2000-2999)
-- ========================================
(UUID(), 1, '2000', 'Current Liabilities', 'liability', NULL, 1, 'credit', 1),
(UUID(), 1, '2100', 'Accounts Payable', 'liability', '2000', 0, 'credit', 1),
(UUID(), 1, '2200', 'Accrued Expenses', 'liability', '2000', 0, 'credit', 1),
(UUID(), 1, '2300', 'Unearned Revenue', 'liability', '2000', 0, 'credit', 1),
(UUID(), 1, '2400', 'Long Term Liabilities', 'liability', NULL, 1, 'credit', 1),
(UUID(), 1, '2410', 'Bank Loans', 'liability', '2400', 0, 'credit', 1),

-- ========================================
-- EQUITY (3000-3999)
-- ========================================
(UUID(), 1, '3000', 'Equity', 'equity', NULL, 1, 'credit', 1),
(UUID(), 1, '3100', 'Capital', 'equity', '3000', 0, 'credit', 1),
(UUID(), 1, '3200', 'Retained Earnings', 'equity', '3000', 0, 'credit', 1),

-- ========================================
-- REVENUE (4000-4999)
-- ========================================
(UUID(), 1, '4000', 'Revenue', 'revenue', NULL, 1, 'credit', 1),
(UUID(), 1, '4100', 'Tuition Fees', 'revenue', '4000', 0, 'credit', 1),
(UUID(), 1, '4200', 'Levy Fees', 'revenue', '4000', 0, 'credit', 1),
(UUID(), 1, '4300', 'Sports Fees', 'revenue', '4000', 0, 'credit', 1),
(UUID(), 1, '4400', 'PTA Fees', 'revenue', '4000', 0, 'credit', 1),
(UUID(), 1, '4500', 'Donations', 'revenue', '4000', 0, 'credit', 1),
(UUID(), 1, '4600', 'Grants', 'revenue', '4000', 0, 'credit', 1),
(UUID(), 1, '4700', 'Miscellaneous Income', 'revenue', '4000', 0, 'credit', 1),

-- ========================================
-- EXPENSES (5000-5999)
-- ========================================
(UUID(), 1, '5000', 'Expenses', 'expense', NULL, 1, 'debit', 1),
(UUID(), 1, '5100', 'Salaries and Wages', 'expense', '5000', 0, 'debit', 1),
(UUID(), 1, '5200', 'Utilities', 'expense', '5000', 0, 'debit', 1),
(UUID(), 1, '5300', 'Teaching Materials', 'expense', '5000', 0, 'debit', 1),
(UUID(), 1, '5400', 'Maintenance and Repairs', 'expense', '5000', 0, 'debit', 1),
(UUID(), 1, '5500', 'Transport', 'expense', '5000', 0, 'debit', 1),
(UUID(), 1, '5600', 'Marketing', 'expense', '5000', 0, 'debit', 1),
(UUID(), 1, '5700', 'Depreciation', 'expense', '5000', 0, 'debit', 1),
(UUID(), 1, '5800', 'Miscellaneous Expenses', 'expense', '5000', 0, 'debit', 1);

-- ============================================
-- 10. INSERT DEFAULT PAYMENT GATEWAY CONFIGS
-- ============================================

INSERT INTO payment_gateway_configs (uuid, school_id, gateway_name, gateway_code, config_data, is_default, is_test_mode, is_active) VALUES
(UUID(), 1, 'Hubtel Payment', 'hubtel', '{"client_id": "", "client_secret": "", "base_url": "https://api.hubtel.com/v1", "callback_url": "https://yourdomain.com/api/finance/callback/hubtel", "cancel_url": "https://yourdomain.com/student/dashboard"}', 0, 1, 1),
(UUID(), 1, 'Paystack Payment', 'paystack', '{"secret_key": "", "public_key": "", "base_url": "https://api.paystack.co", "callback_url": "https://yourdomain.com/api/finance/callback/paystack"}', 0, 1, 1),
(UUID(), 1, 'Flutterwave Payment', 'flutterwave', '{"secret_key": "", "public_key": "", "base_url": "https://api.flutterwave.com/v3", "callback_url": "https://yourdomain.com/api/finance/callback/flutterwave", "logo_url": ""}', 0, 1, 1),
(UUID(), 1, 'MTN Mobile Money', 'mtn_momo', '{"api_key": "", "subscription_key": "", "base_url": "https://sandbox.momodeveloper.mtn.com", "callback_url": "https://yourdomain.com/api/finance/callback/mtn_momo"}', 0, 1, 1);

-- ============================================
-- 11. INSERT DEFAULT FINANCE SETTINGS
-- ============================================

INSERT INTO finance_settings (uuid, school_id, setting_key, setting_value, setting_category, is_active) VALUES
(UUID(), 1, 'invoice_prefix', 'INV-', 'billing', 1),
(UUID(), 1, 'receipt_prefix', 'REC-', 'billing', 1),
(UUID(), 1, 'default_currency', 'GHS', 'general', 1),
(UUID(), 1, 'late_fee_days', '30', 'fees', 1),
(UUID(), 1, 'late_fee_percentage', '5.00', 'fees', 1),
(UUID(), 1, 'reminder_days_before', '7', 'notifications', 1),
(UUID(), 1, 'reminder_days_after', '14', 'notifications', 1),
(UUID(), 1, 'max_installments', '3', 'billing', 1),
(UUID(), 1, 'enable_auto_forward', '1', 'arrears', 1),
(UUID(), 1, 'enable_online_payments', '1', 'payments', 1),
(UUID(), 1, 'enable_sms_notifications', '1', 'notifications', 1),
(UUID(), 1, 'enable_email_notifications', '1', 'notifications', 1),
(UUID(), 1, 'parent_portal_enabled', '1', 'portal', 1),
(UUID(), 1, 'student_portal_enabled', '1', 'portal', 1);

-- ============================================
-- 12. CREATE INDEXES FOR PERFORMANCE
-- ============================================

-- Journal Entries indexes
CREATE INDEX idx_je_posted_date ON journal_entries (posted_date);
CREATE INDEX idx_je_created_by ON journal_entries (created_by);

-- Journal Entry Details indexes
CREATE INDEX idx_jed_debit ON journal_entry_details (debit_amount);
CREATE INDEX idx_jed_credit ON journal_entry_details (credit_amount);

-- General Ledger indexes
CREATE INDEX idx_gl_balance ON general_ledger (balance);
CREATE INDEX idx_gl_account_balance ON general_ledger (account_id, balance);

-- Payment Gateway Logs indexes
CREATE INDEX idx_pgl_action ON payment_gateway_logs (action);
CREATE INDEX idx_pgl_error ON payment_gateway_logs (error_message(100));

-- ============================================
-- 13. CREATE VIEWS FOR FINANCIAL REPORTS
-- ============================================

-- View: Account Balances
CREATE OR REPLACE VIEW vw_account_balances AS
SELECT 
    ca.id AS account_id,
    ca.account_code,
    ca.account_name,
    ca.account_type,
    ca.parent_code,
    COALESCE(SUM(gl.debit_amount), 0) AS total_debit,
    COALESCE(SUM(gl.credit_amount), 0) AS total_credit,
    COALESCE(SUM(gl.debit_amount) - SUM(gl.credit_amount), 0) AS balance,
    ca.normal_balance,
    ca.is_active
FROM chart_of_accounts ca
LEFT JOIN general_ledger gl ON ca.id = gl.account_id
WHERE ca.is_active = 1
GROUP BY ca.id;

-- View: Journal Entry Summary
CREATE OR REPLACE VIEW vw_journal_entry_summary AS
SELECT 
    je.id,
    je.journal_number,
    je.journal_date,
    je.description,
    je.total_debit,
    je.total_credit,
    je.status,
    je.posted_date,
    u.username AS posted_by_name,
    je.created_at
FROM journal_entries je
LEFT JOIN users u ON je.posted_by = u.id
WHERE je.is_active = 1
ORDER BY je.journal_date DESC;

-- View: Student Fee Summary (Consolidated)
CREATE OR REPLACE VIEW vw_student_fee_summary AS
SELECT 
    s.id AS student_id,
    s.first_name,
    s.last_name,
    s.admission_number,
    COUNT(sb.id) AS bill_count,
    SUM(sb.total_amount) AS total_billed,
    SUM(sb.amount_paid) AS total_paid,
    SUM(sb.balance_due) AS total_balance,
    SUM(CASE WHEN sb.bill_status = 'overdue' THEN sb.balance_due ELSE 0 END) AS overdue_amount,
    COUNT(CASE WHEN sb.bill_status = 'overdue' THEN 1 END) AS overdue_count
FROM students s
LEFT JOIN student_bills sb ON s.id = sb.student_id AND sb.is_active = 1
WHERE s.is_active = 1
GROUP BY s.id;

-- ============================================
-- 14. VERIFY TABLES CREATED
-- ============================================

SHOW TABLES LIKE 'chart_of_accounts';
SHOW TABLES LIKE 'journal_entries';
SHOW TABLES LIKE 'journal_entry_details';
SHOW TABLES LIKE 'general_ledger';
SHOW TABLES LIKE 'payment_gateway_configs';
SHOW TABLES LIKE 'payment_gateway_logs';
SHOW TABLES LIKE 'finance_settings';
SHOW TABLES LIKE 'arrears_log';

SELECT '✅ All Finance Module Tables Created Successfully!' AS Status;