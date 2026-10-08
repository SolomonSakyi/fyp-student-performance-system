-- ============================================
-- DATABASE: student_performance_system
-- MODULE: Finance Management
-- ============================================

USE student_performance_system;

-- ============================================
-- 1. FEE CATEGORIES TABLE
-- ============================================
DROP TABLE IF EXISTS fee_categories;
CREATE TABLE fee_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    category_name VARCHAR(100) NOT NULL,
    category_code VARCHAR(20) DEFAULT NULL,
    category_type ENUM('tuition','levy','sports','medical','canteen','transport','pta','maintenance','custom') NOT NULL DEFAULT 'tuition',
    is_taxable TINYINT(1) NOT NULL DEFAULT 0,
    is_refundable TINYINT(1) NOT NULL DEFAULT 0,
    is_compulsory TINYINT(1) NOT NULL DEFAULT 1,
    description TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uuid (uuid),
    UNIQUE KEY uq_school_name (school_id, category_name),
    UNIQUE KEY uq_school_code (school_id, category_code),
    INDEX idx_school_active (school_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. FEE STRUCTURES TABLE
-- ============================================
DROP TABLE IF EXISTS fee_structures;
CREATE TABLE fee_structures (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    grade_level_id BIGINT UNSIGNED NOT NULL,
    fee_category_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    academic_term_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(3) NOT NULL DEFAULT 'GHS',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uuid (uuid),
    UNIQUE KEY uq_school_grade_year_term_category (school_id, grade_level_id, academic_year_id, academic_term_id, fee_category_id),
    CONSTRAINT fk_fs_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fs_grade_level FOREIGN KEY (grade_level_id) REFERENCES grade_levels(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fs_category FOREIGN KEY (fee_category_id) REFERENCES fee_categories(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fs_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fs_term FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_school_active (school_id, is_active),
    INDEX idx_grade_level (grade_level_id),
    INDEX idx_category (fee_category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. PAYMENT METHODS TABLE
-- ============================================
DROP TABLE IF EXISTS payment_methods;
CREATE TABLE payment_methods (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    method_name VARCHAR(50) NOT NULL,
    method_code VARCHAR(20) DEFAULT NULL,
    method_type ENUM('cash','bank_transfer','mobile_money','cheque','online','card','other') NOT NULL DEFAULT 'cash',
    requires_reference TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uuid (uuid),
    UNIQUE KEY uq_school_name (school_id, method_name),
    UNIQUE KEY uq_school_code (school_id, method_code),
    INDEX idx_school_active (school_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. STUDENT BILLS TABLE
-- ============================================
DROP TABLE IF EXISTS student_bills;
CREATE TABLE student_bills (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    academic_term_id BIGINT UNSIGNED NOT NULL,
    bill_number VARCHAR(50) NOT NULL,
    bill_date DATE NOT NULL,
    due_date DATE NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    balance_due DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(3) NOT NULL DEFAULT 'GHS',
    bill_status ENUM('draft','issued','partially_paid','paid','overdue','cancelled','void') NOT NULL DEFAULT 'draft',
    issued_by BIGINT UNSIGNED DEFAULT NULL,
    issued_date TIMESTAMP NULL DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uuid (uuid),
    UNIQUE KEY uq_bill_number (school_id, bill_number),
    UNIQUE KEY uq_student_term (school_id, student_id, academic_year_id, academic_term_id),
    CONSTRAINT fk_sb_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sb_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sb_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sb_term FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sb_issued_by FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_student (student_id),
    INDEX idx_bill_status (bill_status),
    INDEX idx_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. STUDENT BILL ITEMS TABLE
-- ============================================
DROP TABLE IF EXISTS student_bill_items;
CREATE TABLE student_bill_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    student_bill_id BIGINT UNSIGNED NOT NULL,
    fee_category_id BIGINT UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uuid (uuid),
    CONSTRAINT fk_sbi_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sbi_bill FOREIGN KEY (student_bill_id) REFERENCES student_bills(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sbi_category FOREIGN KEY (fee_category_id) REFERENCES fee_categories(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_bill (student_bill_id),
    INDEX idx_category (fee_category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. PAYMENTS TABLE
-- ============================================
DROP TABLE IF EXISTS payments;
CREATE TABLE payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    student_bill_id BIGINT UNSIGNED DEFAULT NULL,
    payment_number VARCHAR(50) NOT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(3) NOT NULL DEFAULT 'GHS',
    payment_method_id BIGINT UNSIGNED NOT NULL,
    payment_reference VARCHAR(100) DEFAULT NULL,
    bank_name VARCHAR(100) DEFAULT NULL,
    cheque_number VARCHAR(50) DEFAULT NULL,
    mobile_money_number VARCHAR(20) DEFAULT NULL,
    mobile_money_network VARCHAR(50) DEFAULT NULL,
    card_last_four VARCHAR(4) DEFAULT NULL,
    payment_status ENUM('pending','processing','completed','failed','refunded','void') NOT NULL DEFAULT 'pending',
    received_by BIGINT UNSIGNED DEFAULT NULL,
    receipt_generated TINYINT(1) NOT NULL DEFAULT 0,
    receipt_number VARCHAR(50) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uuid (uuid),
    UNIQUE KEY uq_payment_number (school_id, payment_number),
    UNIQUE KEY uq_receipt_number (school_id, receipt_number),
    CONSTRAINT fk_pay_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pay_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pay_bill FOREIGN KEY (student_bill_id) REFERENCES student_bills(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_pay_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_pay_received_by FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_student (student_id),
    INDEX idx_bill (student_bill_id),
    INDEX idx_payment_status (payment_status),
    INDEX idx_payment_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 7. INSERT SAMPLE DATA
-- ============================================

-- Insert fee categories
INSERT INTO fee_categories (uuid, school_id, category_name, category_code, category_type, is_compulsory) VALUES 
(UUID(), 1, 'Tuition Fee', 'TUITION', 'tuition', 1),
(UUID(), 1, 'Medical & Sports', 'MEDICAL', 'medical', 1),
(UUID(), 1, 'Canteen', 'CANTEEN', 'canteen', 0),
(UUID(), 1, 'PTA Levy', 'PTA', 'pta', 1),
(UUID(), 1, 'Maintenance Levy', 'MAINT', 'maintenance', 1),
(UUID(), 1, 'Bus Levy', 'BUS', 'transport', 0),
(UUID(), 1, 'Development Levy', 'DEV', 'levy', 1),
(UUID(), 1, 'ICT Levy', 'ICT', 'levy', 1),
(UUID(), 1, 'Science Lab Fee', 'SCI_LAB', 'custom', 1),
(UUID(), 1, 'Library Fee', 'LIBRARY', 'custom', 0);

-- Insert payment methods
INSERT INTO payment_methods (uuid, school_id, method_name, method_code, method_type) VALUES 
(UUID(), 1, 'Cash', 'CASH', 'cash'),
(UUID(), 1, 'Bank Transfer', 'BANK', 'bank_transfer'),
(UUID(), 1, 'Mobile Money', 'MOMO', 'mobile_money'),
(UUID(), 1, 'Cheque', 'CHEQUE', 'cheque'),
(UUID(), 1, 'Online Payment', 'ONLINE', 'online');

-- ============================================
-- 8. CREATE INDEXES FOR PERFORMANCE
-- ============================================

-- Fee structures indexes
CREATE INDEX idx_fs_amount ON fee_structures (amount);
CREATE INDEX idx_fs_currency ON fee_structures (currency);

-- Student bills indexes
CREATE INDEX idx_sb_bill_date ON student_bills (bill_date);
CREATE INDEX idx_sb_balance ON student_bills (balance_due);
CREATE INDEX idx_sb_status_balance ON student_bills (bill_status, balance_due);

-- Payments indexes
CREATE INDEX idx_pay_amount ON payments (amount);
CREATE INDEX idx_pay_receipt ON payments (receipt_number);

-- ============================================
-- 9. CREATE VIEWS FOR REPORTS
-- ============================================

-- View: Student fee summary
CREATE OR REPLACE VIEW vw_student_fee_summary AS
SELECT 
    s.id AS student_id,
    s.first_name,
    s.last_name,
    s.admission_number,
    sb.id AS bill_id,
    sb.bill_number,
    sb.total_amount,
    sb.amount_paid,
    sb.balance_due,
    sb.bill_status,
    sb.due_date,
    ay.year_name,
    at.term_name
FROM students s
JOIN student_bills sb ON s.id = sb.student_id
JOIN academic_years ay ON sb.academic_year_id = ay.id
JOIN academic_terms at ON sb.academic_term_id = at.id
WHERE sb.is_active = 1;

-- View: Payment summary by method
CREATE OR REPLACE VIEW vw_payment_summary_by_method AS
SELECT 
    pm.method_name,
    COUNT(p.id) AS payment_count,
    SUM(p.amount) AS total_amount,
    AVG(p.amount) AS average_amount,
    DATE_FORMAT(p.payment_date, '%Y-%m') AS payment_month
FROM payments p
JOIN payment_methods pm ON p.payment_method_id = pm.id
WHERE p.payment_status = 'completed'
GROUP BY pm.method_name, DATE_FORMAT(p.payment_date, '%Y-%m')
ORDER BY payment_month DESC, pm.method_name;

-- ============================================
-- 10. VERIFY TABLES CREATED
-- ============================================
SHOW TABLES LIKE 'fee%';
SHOW TABLES LIKE 'payment%';
SHOW TABLES LIKE 'student_bill%';

SELECT '✅ Finance Module Tables Created Successfully!' AS Status;