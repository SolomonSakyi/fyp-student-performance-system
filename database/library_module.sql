-- ================================================================
-- DATABASE: student_performance_system
-- MODULE: Library Management
-- VERSION: 1.0
-- ================================================================

USE student_performance_system;

SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- 1. LIBRARY BRANCHES
-- ================================================================
DROP TABLE IF EXISTS library_branches;
CREATE TABLE library_branches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    branch_name VARCHAR(100) NOT NULL,
    branch_code VARCHAR(20) NOT NULL,
    address TEXT,
    phone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(100) DEFAULT NULL,
    librarian_name VARCHAR(100) DEFAULT NULL,
    opening_time TIME DEFAULT '08:00:00',
    closing_time TIME DEFAULT '17:00:00',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_library_branches_uuid (uuid),
    UNIQUE KEY uq_library_branches_code (school_id, branch_code),
    CONSTRAINT fk_lb_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_lb_school_active (school_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 2. LIBRARY CATEGORIES
-- ================================================================
DROP TABLE IF EXISTS library_categories;
CREATE TABLE library_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    category_name VARCHAR(100) NOT NULL,
    category_code VARCHAR(20) NOT NULL,
    description TEXT,
    parent_id BIGINT UNSIGNED DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_library_categories_uuid (uuid),
    UNIQUE KEY uq_library_categories_code (school_id, category_code),
    CONSTRAINT fk_lc_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_lc_parent FOREIGN KEY (parent_id) REFERENCES library_categories(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_lc_school_active (school_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 3. PUBLISHERS
-- ================================================================
DROP TABLE IF EXISTS publishers;
CREATE TABLE publishers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    publisher_name VARCHAR(100) NOT NULL,
    publisher_code VARCHAR(20) NOT NULL,
    address TEXT,
    phone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(100) DEFAULT NULL,
    website VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_publishers_uuid (uuid),
    UNIQUE KEY uq_publishers_code (school_id, publisher_code),
    CONSTRAINT fk_pub_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_pub_school_active (school_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 4. AUTHORS
-- ================================================================
DROP TABLE IF EXISTS authors;
CREATE TABLE authors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    author_name VARCHAR(100) NOT NULL,
    author_code VARCHAR(20) NOT NULL,
    biography TEXT,
    birth_date DATE DEFAULT NULL,
    death_date DATE DEFAULT NULL,
    nationality VARCHAR(50) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_authors_uuid (uuid),
    UNIQUE KEY uq_authors_code (school_id, author_code),
    CONSTRAINT fk_aut_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_aut_school_active (school_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 5. BOOKS
-- ================================================================
DROP TABLE IF EXISTS books;
CREATE TABLE books (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED DEFAULT NULL,
    publisher_id BIGINT UNSIGNED DEFAULT NULL,
    isbn VARCHAR(20) DEFAULT NULL COMMENT 'International Standard Book Number',
    issn VARCHAR(20) DEFAULT NULL COMMENT 'International Standard Serial Number',
    book_title VARCHAR(255) NOT NULL,
    book_subtitle VARCHAR(255) DEFAULT NULL,
    book_code VARCHAR(50) NOT NULL,
    edition VARCHAR(20) DEFAULT NULL,
    volume VARCHAR(20) DEFAULT NULL,
    year_published YEAR DEFAULT NULL,
    pages INT UNSIGNED DEFAULT NULL,
    language VARCHAR(50) DEFAULT 'English',
    summary TEXT,
    cover_image VARCHAR(500) DEFAULT NULL,
    book_type ENUM('textbook','reference','fiction','non_fiction','magazine','journal','ebook','audiobook') NOT NULL DEFAULT 'textbook',
    acquisition_type ENUM('purchased','donated','exchange','other') NOT NULL DEFAULT 'purchased',
    acquisition_date DATE DEFAULT NULL,
    purchase_price DECIMAL(10,2) DEFAULT NULL,
    replacement_price DECIMAL(10,2) DEFAULT NULL,
    total_copies INT UNSIGNED NOT NULL DEFAULT 1,
    available_copies INT UNSIGNED NOT NULL DEFAULT 1,
    borrowed_copies INT UNSIGNED NOT NULL DEFAULT 0,
    damaged_copies INT UNSIGNED NOT NULL DEFAULT 0,
    lost_copies INT UNSIGNED NOT NULL DEFAULT 0,
    location_shelf VARCHAR(50) DEFAULT NULL,
    location_rack VARCHAR(50) DEFAULT NULL,
    location_row VARCHAR(50) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_books_uuid (uuid),
    UNIQUE KEY uq_books_code (school_id, book_code),
    UNIQUE KEY uq_books_isbn (school_id, isbn),
    CONSTRAINT fk_bk_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_bk_branch FOREIGN KEY (branch_id) REFERENCES library_branches(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_bk_category FOREIGN KEY (category_id) REFERENCES library_categories(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_bk_publisher FOREIGN KEY (publisher_id) REFERENCES publishers(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_bk_school_active (school_id, is_active),
    INDEX idx_bk_title (book_title),
    INDEX idx_bk_isbn (isbn),
    INDEX idx_bk_type (book_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 6. BOOK AUTHORS (Many-to-Many)
-- ================================================================
DROP TABLE IF EXISTS book_authors;
CREATE TABLE book_authors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_book_authors_uuid (uuid),
    UNIQUE KEY uq_book_author (book_id, author_id),
    CONSTRAINT fk_ba_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ba_author FOREIGN KEY (author_id) REFERENCES authors(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ba_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_ba_book (book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 7. BOOK COPIES
-- ================================================================
DROP TABLE IF EXISTS book_copies;
CREATE TABLE book_copies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    copy_number VARCHAR(20) NOT NULL,
    barcode VARCHAR(50) DEFAULT NULL,
    rfid_tag VARCHAR(50) DEFAULT NULL,
    condition ENUM('new','good','fair','poor','damaged','lost') NOT NULL DEFAULT 'good',
    status ENUM('available','borrowed','reserved','damaged','lost','repair') NOT NULL DEFAULT 'available',
    acquisition_date DATE DEFAULT NULL,
    purchase_price DECIMAL(10,2) DEFAULT NULL,
    location_shelf VARCHAR(50) DEFAULT NULL,
    location_rack VARCHAR(50) DEFAULT NULL,
    notes TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_book_copies_uuid (uuid),
    UNIQUE KEY uq_book_copies_number (school_id, copy_number),
    UNIQUE KEY uq_book_copies_barcode (school_id, barcode),
    CONSTRAINT fk_bc_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_bc_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_bc_branch FOREIGN KEY (branch_id) REFERENCES library_branches(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_bc_book (book_id),
    INDEX idx_bc_status (status),
    INDEX idx_bc_barcode (barcode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 8. LIBRARY MEMBERSHIPS
-- ================================================================
DROP TABLE IF EXISTS library_memberships;
CREATE TABLE library_memberships (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED DEFAULT NULL,
    staff_id BIGINT UNSIGNED DEFAULT NULL,
    membership_number VARCHAR(50) NOT NULL,
    membership_type ENUM('student','staff','teacher','parent','community','lifetime') NOT NULL DEFAULT 'student',
    join_date DATE NOT NULL,
    expiry_date DATE DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    max_books_allowed INT UNSIGNED NOT NULL DEFAULT 5,
    max_loan_days INT UNSIGNED NOT NULL DEFAULT 14,
    fine_rate_per_day DECIMAL(10,2) NOT NULL DEFAULT 0.50,
    total_borrowed INT UNSIGNED NOT NULL DEFAULT 0,
    total_fines DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    outstanding_fines DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    notes TEXT,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_library_memberships_uuid (uuid),
    UNIQUE KEY uq_library_memberships_number (school_id, membership_number),
    CONSTRAINT fk_lm_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_lm_branch FOREIGN KEY (branch_id) REFERENCES library_branches(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_lm_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_lm_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_lm_student (student_id),
    INDEX idx_lm_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 9. BOOK LOANS
-- ================================================================
DROP TABLE IF EXISTS book_loans;
CREATE TABLE book_loans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    membership_id BIGINT UNSIGNED NOT NULL,
    book_copy_id BIGINT UNSIGNED NOT NULL,
    loan_number VARCHAR(50) NOT NULL,
    loan_date DATE NOT NULL,
    due_date DATE NOT NULL,
    return_date DATE DEFAULT NULL,
    status ENUM('borrowed','returned','overdue','lost','damaged') NOT NULL DEFAULT 'borrowed',
    fine_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    fine_paid TINYINT(1) NOT NULL DEFAULT 0,
    fine_paid_date DATE DEFAULT NULL,
    renewed_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_renewed_date DATE DEFAULT NULL,
    issued_by BIGINT UNSIGNED DEFAULT NULL,
    received_by BIGINT UNSIGNED DEFAULT NULL,
    notes TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_book_loans_uuid (uuid),
    UNIQUE KEY uq_book_loans_number (school_id, loan_number),
    CONSTRAINT fk_bl_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_bl_membership FOREIGN KEY (membership_id) REFERENCES library_memberships(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_bl_book_copy FOREIGN KEY (book_copy_id) REFERENCES book_copies(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_bl_membership (membership_id),
    INDEX idx_bl_status (status),
    INDEX idx_bl_due_date (due_date),
    INDEX idx_bl_loan_date (loan_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 10. BOOK RESERVATIONS
-- ================================================================
DROP TABLE IF EXISTS book_reservations;
CREATE TABLE book_reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    membership_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    reservation_date DATE NOT NULL,
    expiry_date DATE NOT NULL,
    status ENUM('active','fulfilled','cancelled','expired') NOT NULL DEFAULT 'active',
    fulfilled_by_loan_id BIGINT UNSIGNED DEFAULT NULL,
    notes TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_book_reservations_uuid (uuid),
    CONSTRAINT fk_br_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_br_membership FOREIGN KEY (membership_id) REFERENCES library_memberships(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_br_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_br_loan FOREIGN KEY (fulfilled_by_loan_id) REFERENCES book_loans(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_br_membership (membership_id),
    INDEX idx_br_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 11. FINE TRANSACTIONS
-- ================================================================
DROP TABLE IF EXISTS library_fines;
CREATE TABLE library_fines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    membership_id BIGINT UNSIGNED NOT NULL,
    loan_id BIGINT UNSIGNED DEFAULT NULL,
    fine_number VARCHAR(50) NOT NULL,
    fine_date DATE NOT NULL,
    fine_type ENUM('late','damage','loss','processing') NOT NULL DEFAULT 'late',
    amount DECIMAL(10,2) NOT NULL,
    paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    balance_due DECIMAL(10,2) NOT NULL,
    status ENUM('pending','partial','paid','waived') NOT NULL DEFAULT 'pending',
    paid_date DATE DEFAULT NULL,
    payment_method ENUM('cash','card','mobile','deduct') DEFAULT NULL,
    transaction_reference VARCHAR(100) DEFAULT NULL,
    notes TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_library_fines_uuid (uuid),
    UNIQUE KEY uq_library_fines_number (school_id, fine_number),
    CONSTRAINT fk_lf_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_lf_membership FOREIGN KEY (membership_id) REFERENCES library_memberships(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_lf_loan FOREIGN KEY (loan_id) REFERENCES book_loans(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_lf_membership (membership_id),
    INDEX idx_lf_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 12. LIBRARY SETTINGS
-- ================================================================
DROP TABLE IF EXISTS library_settings;
CREATE TABLE library_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    setting_key VARCHAR(50) NOT NULL,
    setting_value TEXT NOT NULL,
    setting_category ENUM('general','loans','fines','membership','notifications') NOT NULL DEFAULT 'general',
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_library_settings_uuid (uuid),
    UNIQUE KEY uq_library_settings_key (school_id, setting_key),
    CONSTRAINT fk_ls_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 13. INSERT SAMPLE DATA
-- ================================================================

-- Library Branches
INSERT INTO library_branches (uuid, school_id, branch_name, branch_code, address, phone, email, opening_time, closing_time) VALUES
(UUID(), 1, 'Main Library', 'MAIN', 'Main Campus, Block A', '+233 24 400 0001', 'library@school.edu', '08:00:00', '17:00:00'),
(UUID(), 1, 'Junior School Library', 'JUNIOR', 'Junior School Block', '+233 24 400 0002', 'junior.library@school.edu', '08:00:00', '16:00:00');

-- Library Categories
INSERT INTO library_categories (uuid, school_id, category_name, category_code, description) VALUES
(UUID(), 1, 'Fiction', 'FIC', 'Fiction books including novels and short stories'),
(UUID(), 1, 'Non-Fiction', 'NF', 'Non-fiction books including biographies, history, science'),
(UUID(), 1, 'Science', 'SCI', 'Science and technology books'),
(UUID(), 1, 'Mathematics', 'MATH', 'Mathematics books'),
(UUID(), 1, 'English Literature', 'ENG', 'English literature and language books'),
(UUID(), 1, 'History', 'HIST', 'History books'),
(UUID(), 1, 'Geography', 'GEO', 'Geography books'),
(UUID(), 1, 'Reference', 'REF', 'Reference books including dictionaries, encyclopedias');

-- Publishers
INSERT INTO publishers (uuid, school_id, publisher_name, publisher_code, address, phone, email) VALUES
(UUID(), 1, 'Pearson Education', 'PEARSON', 'London, UK', '+44 20 7010 2000', 'info@pearson.com'),
(UUID(), 1, 'Cambridge University Press', 'CUP', 'Cambridge, UK', '+44 1223 326111', 'info@cambridge.org'),
(UUID(), 1, 'Oxford University Press', 'OUP', 'Oxford, UK', '+44 1865 556767', 'info@oup.com'),
(UUID(), 1, 'Penguin Random House', 'PRH', 'New York, USA', '+1 212 572 2100', 'info@penguinrandomhouse.com');

-- Authors
INSERT INTO authors (uuid, school_id, author_name, author_code, biography) VALUES
(UUID(), 1, 'William Shakespeare', 'SHAK', 'English playwright and poet'),
(UUID(), 1, 'Charles Dickens', 'DICK', 'English novelist of the Victorian era'),
(UUID(), 1, 'Jane Austen', 'AUST', 'English novelist known for her romantic fiction'),
(UUID(), 1, 'Mark Twain', 'TWAIN', 'American writer and humorist'),
(UUID(), 1, 'Stephen Hawking', 'HAWK', 'Theoretical physicist and cosmologist');

-- Library Settings
INSERT INTO library_settings (uuid, school_id, setting_key, setting_value, setting_category, is_system) VALUES
(UUID(), 1, 'max_loans_per_student', '5', 'loans', 1),
(UUID(), 1, 'max_loans_per_staff', '10', 'loans', 1),
(UUID(), 1, 'max_loan_days', '14', 'loans', 1),
(UUID(), 1, 'max_renewals', '2', 'loans', 1),
(UUID(), 1, 'fine_per_day_late', '0.50', 'fines', 1),
(UUID(), 1, 'fine_per_day_reminder', '7', 'notifications', 0),
(UUID(), 1, 'auto_reminder_days', '3', 'notifications', 0),
(UUID(), 1, 'library_hours_start', '08:00', 'general', 1),
(UUID(), 1, 'library_hours_end', '17:00', 'general', 1),
(UUID(), 1, 'membership_duration', '365', 'membership', 1);

-- ================================================================
-- 14. CREATE VIEWS
-- ================================================================

-- View: Book Availability
CREATE OR REPLACE VIEW vw_book_availability AS
SELECT 
    b.id AS book_id,
    b.book_title,
    b.book_code,
    b.isbn,
    b.total_copies,
    b.available_copies,
    b.borrowed_copies,
    b.damaged_copies,
    b.lost_copies,
    b.location_shelf,
    GROUP_CONCAT(DISTINCT a.author_name SEPARATOR ', ') AS authors,
    bc.category_name,
    p.publisher_name
FROM books b
LEFT JOIN book_authors ba ON b.id = ba.book_id AND ba.is_active = 1
LEFT JOIN authors a ON ba.author_id = a.id AND a.is_active = 1
LEFT JOIN library_categories bc ON b.category_id = bc.id AND bc.is_active = 1
LEFT JOIN publishers p ON b.publisher_id = p.id AND p.is_active = 1
WHERE b.is_active = 1
GROUP BY b.id;

-- View: Active Loans
CREATE OR REPLACE VIEW vw_active_loans AS
SELECT 
    bl.id AS loan_id,
    bl.loan_number,
    bl.loan_date,
    bl.due_date,
    bl.status,
    bl.fine_amount,
    m.membership_number,
    s.first_name,
    s.last_name,
    s.admission_number,
    b.book_title,
    b.book_code,
    bc.copy_number,
    bc.barcode
FROM book_loans bl
JOIN library_memberships m ON bl.membership_id = m.id
LEFT JOIN students s ON m.student_id = s.id
LEFT JOIN book_copies bc ON bl.book_copy_id = bc.id
LEFT JOIN books b ON bc.book_id = b.id
WHERE bl.status IN ('borrowed', 'overdue')
AND bl.is_active = 1
ORDER BY bl.due_date ASC;

-- View: Overdue Loans
CREATE OR REPLACE VIEW vw_overdue_loans AS
SELECT 
    bl.id AS loan_id,
    bl.loan_number,
    bl.loan_date,
    bl.due_date,
    DATEDIFF(CURDATE(), bl.due_date) AS days_overdue,
    bl.fine_amount,
    m.membership_number,
    s.first_name,
    s.last_name,
    s.admission_number,
    b.book_title,
    b.book_code,
    bc.copy_number
FROM book_loans bl
JOIN library_memberships m ON bl.membership_id = m.id
LEFT JOIN students s ON m.student_id = s.id
LEFT JOIN book_copies bc ON bl.book_copy_id = bc.id
LEFT JOIN books b ON bc.book_id = b.id
WHERE bl.status = 'overdue'
AND bl.is_active = 1
ORDER BY bl.due_date ASC;

-- View: Member Summary
CREATE OR REPLACE VIEW vw_member_summary AS
SELECT 
    m.id AS membership_id,
    m.membership_number,
    m.membership_type,
    m.join_date,
    m.expiry_date,
    m.max_books_allowed,
    m.total_borrowed,
    m.total_fines,
    m.outstanding_fines,
    s.id AS student_id,
    s.first_name,
    s.last_name,
    s.admission_number,
    COUNT(bl.id) AS current_loans,
    SUM(CASE WHEN bl.status = 'overdue' THEN 1 ELSE 0 END) AS overdue_loans
FROM library_memberships m
LEFT JOIN students s ON m.student_id = s.id
LEFT JOIN book_loans bl ON m.id = bl.membership_id AND bl.status IN ('borrowed', 'overdue') AND bl.is_active = 1
WHERE m.is_active = 1
GROUP BY m.id;

-- ================================================================
-- 15. VERIFY TABLES
-- ================================================================

SHOW TABLES LIKE 'library%';
SHOW TABLES LIKE 'book%';
SHOW TABLES LIKE 'authors';
SHOW TABLES LIKE 'publishers';

SELECT '✅ Library Module Tables Created Successfully!' AS Status;

SET FOREIGN_KEY_CHECKS = 1;