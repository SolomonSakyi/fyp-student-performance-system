<?php
/**
 * LibraryModel.php
 * 
 * Library Management Model
 * 
 * @package EduTrack
 * @subpackage Models\Library
 */

class LibraryModel
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    // ================================================================
    // BOOKS - CRUD Operations
    // ================================================================

    public function createBook(array $data): int
    {
        try {
            $sql = "INSERT INTO books (
                uuid, school_id, branch_id, category_id, publisher_id,
                isbn, book_title, book_code, edition, year_published,
                pages, language, summary, book_type, total_copies,
                available_copies, location_shelf, is_active
            ) VALUES (
                UUID(), ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, 1
            )";

            $params = [
                $data['school_id'] ?? 1,
                $data['branch_id'],
                $data['category_id'] ?? null,
                $data['publisher_id'] ?? null,
                $data['isbn'] ?? null,
                $data['book_title'],
                $data['book_code'],
                $data['edition'] ?? null,
                $data['year_published'] ?? null,
                $data['pages'] ?? null,
                $data['language'] ?? 'English',
                $data['summary'] ?? null,
                $data['book_type'] ?? 'textbook',
                $data['total_copies'] ?? 1,
                $data['total_copies'] ?? 1,
                $data['location_shelf'] ?? null
            ];

            $this->db->query($sql, $params);
            $bookId = $this->db->lastInsertId();

            if (isset($data['total_copies']) && $data['total_copies'] > 0) {
                for ($i = 1; $i <= $data['total_copies']; $i++) {
                    $copyNumber = $data['book_code'] . '-' . str_pad($i, 3, '0', STR_PAD_LEFT);
                    $sql = "INSERT INTO book_copies (
                        uuid, school_id, book_id, branch_id, copy_number, barcode,
                        book_condition, status, is_active
                    ) VALUES (
                        UUID(), ?, ?, ?, ?, ?,
                        'new', 'available', 1
                    )";
                    $this->db->query($sql, [
                        $data['school_id'] ?? 1,
                        $bookId,
                        $data['branch_id'],
                        $copyNumber,
                        $copyNumber
                    ]);
                }
            }

            return $bookId;

        } catch (Exception $e) {
            throw new Exception("Failed to create book: " . $e->getMessage());
        }
    }

    public function getBook(int $bookId): ?array
    {
        try {
            $sql = "SELECT b.*, bc.category_name, p.publisher_name, lb.branch_name
                    FROM books b
                    LEFT JOIN library_categories bc ON b.category_id = bc.id
                    LEFT JOIN publishers p ON b.publisher_id = p.id
                    LEFT JOIN library_branches lb ON b.branch_id = lb.id
                    WHERE b.id = ? AND b.is_active = 1";
            return $this->db->fetchOne($sql, [$bookId]);
        } catch (Exception $e) {
            return null;
        }
    }

    public function getBookByCode(string $bookCode): ?array
    {
        try {
            $sql = "SELECT * FROM books WHERE book_code = ? AND is_active = 1 LIMIT 1";
            return $this->db->fetchOne($sql, [$bookCode]);
        } catch (Exception $e) {
            return null;
        }
    }

    public function getBooks(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        try {
            $sql = "SELECT b.*, bc.category_name, p.publisher_name, lb.branch_name
                    FROM books b
                    LEFT JOIN library_categories bc ON b.category_id = bc.id
                    LEFT JOIN publishers p ON b.publisher_id = p.id
                    LEFT JOIN library_branches lb ON b.branch_id = lb.id
                    WHERE b.is_active = 1";
            $params = [];

            if (!empty($filters['search'])) {
                $sql .= " AND (b.book_title LIKE ? OR b.book_code LIKE ? OR b.isbn LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            $sql .= " ORDER BY b.book_title ASC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            return $this->db->fetchAll($sql, $params);

        } catch (Exception $e) {
            return [];
        }
    }

    public function countBooks(array $filters = []): int
    {
        try {
            $sql = "SELECT COUNT(*) as total FROM books b WHERE b.is_active = 1";
            $params = [];

            if (!empty($filters['search'])) {
                $sql .= " AND (b.book_title LIKE ? OR b.book_code LIKE ? OR b.isbn LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            $result = $this->db->fetchOne($sql, $params);
            return $result['total'] ?? 0;

        } catch (Exception $e) {
            return 0;
        }
    }

    public function searchBooks(string $keyword, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        try {
            $sql = "SELECT b.*, bc.category_name, p.publisher_name, lb.branch_name
                    FROM books b
                    LEFT JOIN library_categories bc ON b.category_id = bc.id
                    LEFT JOIN publishers p ON b.publisher_id = p.id
                    LEFT JOIN library_branches lb ON b.branch_id = lb.id
                    WHERE b.is_active = 1
                    AND (b.book_title LIKE ? OR b.book_code LIKE ? OR b.isbn LIKE ? OR b.summary LIKE ?)";
            
            $searchTerm = '%' . $keyword . '%';
            $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];

            if (!empty($filters['category_id'])) {
                $sql .= " AND b.category_id = ?";
                $params[] = $filters['category_id'];
            }

            if (!empty($filters['branch_id'])) {
                $sql .= " AND b.branch_id = ?";
                $params[] = $filters['branch_id'];
            }

            if (!empty($filters['book_type'])) {
                $sql .= " AND b.book_type = ?";
                $params[] = $filters['book_type'];
            }

            $sql .= " ORDER BY b.book_title ASC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            return $this->db->fetchAll($sql, $params);

        } catch (Exception $e) {
            return [];
        }
    }

    public function updateBook(int $bookId, array $data): bool
    {
        try {
            $fields = [];
            $params = [];
            $allowedFields = ['book_title', 'book_code', 'isbn', 'edition', 'pages', 'language', 'summary'];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $params[] = $bookId;
            $sql = "UPDATE books SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, $params);
            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    public function deleteBook(int $bookId): bool
    {
        try {
            $sql = "UPDATE books SET is_active = 0, updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, [$bookId]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function getBookCopies(int $bookId): array
    {
        try {
            $sql = "SELECT * FROM book_copies WHERE book_id = ? AND is_active = 1 ORDER BY copy_number";
            return $this->db->fetchAll($sql, [$bookId]);
        } catch (Exception $e) {
            return [];
        }
    }

    public function getBookBorrowCount(int $bookId): int
    {
        try {
            $result = $this->db->fetchOne("
                SELECT COUNT(bl.id) as count
                FROM book_loans bl
                JOIN book_copies bc ON bl.book_copy_id = bc.id
                WHERE bc.book_id = ? AND bl.is_active = 1
            ", [$bookId]);
            return $result['count'] ?? 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    // ================================================================
    // MEMBERSHIPS
    // ================================================================

    public function createMembership(array $data): int
    {
        try {
            $membershipNumber = 'MEM-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

            $sql = "INSERT INTO library_memberships (
                uuid, school_id, branch_id, student_id,
                membership_number, membership_type, join_date, expiry_date,
                max_books_allowed, max_loan_days, fine_rate_per_day, is_active
            ) VALUES (
                UUID(), ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, 1
            )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['branch_id'],
                $data['student_id'] ?? null,
                $membershipNumber,
                $data['membership_type'] ?? 'student',
                $data['join_date'] ?? date('Y-m-d'),
                $data['expiry_date'] ?? date('Y-m-d', strtotime('+1 year')),
                $data['max_books_allowed'] ?? 5,
                $data['max_loan_days'] ?? 14,
                $data['fine_rate_per_day'] ?? 0.50
            ]);

            return $this->db->lastInsertId();

        } catch (Exception $e) {
            throw new Exception("Failed to create membership: " . $e->getMessage());
        }
    }

    public function getMembershipByStudent(int $studentId): ?array
    {
        try {
            $sql = "SELECT * FROM library_memberships 
                    WHERE student_id = ? AND is_active = 1 
                    ORDER BY id DESC LIMIT 1";
            return $this->db->fetchOne($sql, [$studentId]);
        } catch (Exception $e) {
            return null;
        }
    }

    public function getMemberships(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        try {
            $sql = "SELECT m.*, s.first_name, s.last_name, s.admission_number, lb.branch_name
                    FROM library_memberships m
                    LEFT JOIN students s ON m.student_id = s.id
                    LEFT JOIN library_branches lb ON m.branch_id = lb.id
                    WHERE m.is_active = 1";
            $params = [];

            if (!empty($filters['search'])) {
                $sql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR m.membership_number LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            $sql .= " ORDER BY m.created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            return $this->db->fetchAll($sql, $params);

        } catch (Exception $e) {
            return [];
        }
    }

    public function getMemberLoans(int $memberId, int $limit = 20): array
    {
        try {
            $sql = "SELECT bl.*, b.book_title, b.book_code, bc.copy_number
                    FROM book_loans bl
                    JOIN book_copies bc ON bl.book_copy_id = bc.id
                    JOIN books b ON bc.book_id = b.id
                    WHERE bl.membership_id = ? AND bl.is_active = 1
                    ORDER BY bl.created_at DESC
                    LIMIT ?";
            return $this->db->fetchAll($sql, [$memberId, $limit]);
        } catch (Exception $e) {
            return [];
        }
    }

    // ================================================================
    // LOANS
    // ================================================================

    public function createLoan(array $data): int
    {
        try {
            $loanNumber = 'LN-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

            $sql = "INSERT INTO book_loans (
                uuid, school_id, membership_id, book_copy_id,
                loan_number, loan_date, due_date, status,
                issued_by, is_active
            ) VALUES (
                UUID(), ?, ?, ?,
                ?, ?, ?, 'borrowed',
                ?, 1
            )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['membership_id'],
                $data['book_copy_id'],
                $loanNumber,
                $data['loan_date'] ?? date('Y-m-d'),
                date('Y-m-d', strtotime('+14 days')),
                $data['issued_by'] ?? null
            ]);

            $loanId = $this->db->lastInsertId();

            $this->db->query("UPDATE book_copies SET status = 'borrowed' WHERE id = ?", [$data['book_copy_id']]);
            $this->db->query("UPDATE library_memberships SET total_borrowed = total_borrowed + 1 WHERE id = ?", [$data['membership_id']]);

            return $loanId;

        } catch (Exception $e) {
            throw new Exception("Failed to create loan: " . $e->getMessage());
        }
    }

    public function returnBook(int $loanId, ?string $condition = null): bool
    {
        try {
            $loan = $this->db->fetchOne("SELECT * FROM book_loans WHERE id = ? AND is_active = 1", [$loanId]);
            if (!$loan) {
                throw new Exception("Loan not found");
            }

            $fine = 0;
            if ($loan['due_date'] < date('Y-m-d')) {
                $daysOverdue = (strtotime(date('Y-m-d')) - strtotime($loan['due_date'])) / (60 * 60 * 24);
                $fine = $daysOverdue * 0.50;
                $fine = round($fine, 2);
            }

            $this->db->query("UPDATE book_loans SET return_date = ?, status = 'returned', fine_amount = ? WHERE id = ?", 
                [date('Y-m-d'), $fine, $loanId]);

            $copyStatus = $condition && $condition !== 'good' ? $condition : 'available';
            $this->db->query("UPDATE book_copies SET status = ? WHERE id = ?", [$copyStatus, $loan['book_copy_id']]);

            if ($fine > 0) {
                $this->db->query("UPDATE library_memberships SET outstanding_fines = outstanding_fines + ? WHERE id = ?", 
                    [$fine, $loan['membership_id']]);
            }

            return true;

        } catch (Exception $e) {
            throw new Exception("Failed to return book: " . $e->getMessage());
        }
    }

    public function getActiveLoans(int $membershipId): array
    {
        try {
            $sql = "SELECT bl.*, bc.copy_number, bc.barcode, b.book_title, b.book_code
                    FROM book_loans bl
                    JOIN book_copies bc ON bl.book_copy_id = bc.id
                    JOIN books b ON bc.book_id = b.id
                    WHERE bl.membership_id = ? 
                    AND bl.status IN ('borrowed', 'overdue')
                    AND bl.is_active = 1
                    ORDER BY bl.due_date ASC";
            return $this->db->fetchAll($sql, [$membershipId]);
        } catch (Exception $e) {
            return [];
        }
    }

    public function getOverdueCount(): int
    {
        try {
            $result = $this->db->fetchOne("SELECT COUNT(*) as count FROM book_loans WHERE status = 'overdue' AND is_active = 1");
            return $result['count'] ?? 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    // ================================================================
    // AUTHORS
    // ================================================================

    public function addAuthorToBook(int $bookId, int $authorId, bool $isPrimary = false): bool
    {
        try {
            $sql = "INSERT INTO book_authors (uuid, school_id, book_id, author_id, is_primary, is_active)
                    VALUES (UUID(), 1, ?, ?, ?, 1)";
            $this->db->query($sql, [$bookId, $authorId, $isPrimary ? 1 : 0]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function removeAuthorFromBook(int $bookId, int $authorId): bool
    {
        try {
            $sql = "UPDATE book_authors SET is_active = 0 WHERE book_id = ? AND author_id = ?";
            $this->db->query($sql, [$bookId, $authorId]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // ================================================================
    // STATISTICS
    // ================================================================

    public function getStatistics(): array
    {
        try {
            $stats = [];
            $result = $this->db->fetchOne("SELECT COUNT(*) as count FROM books WHERE is_active = 1");
            $stats['total_books'] = $result['count'] ?? 0;

            $result = $this->db->fetchOne("SELECT COUNT(*) as count FROM library_memberships WHERE is_active = 1");
            $stats['total_members'] = $result['count'] ?? 0;

            $result = $this->db->fetchOne("SELECT COUNT(*) as count FROM book_loans WHERE status IN ('borrowed', 'overdue') AND is_active = 1");
            $stats['active_loans'] = $result['count'] ?? 0;

            $result = $this->db->fetchOne("SELECT COUNT(*) as count FROM book_loans WHERE status = 'overdue' AND is_active = 1");
            $stats['overdue_loans'] = $result['count'] ?? 0;

            $result = $this->db->fetchOne("SELECT SUM(outstanding_fines) as total FROM library_memberships WHERE is_active = 1");
            $stats['total_fines'] = $result['total'] ?? 0;

            return $stats;

        } catch (Exception $e) {
            return [];
        }
    }

    public function getSetting(string $key, $default = null)
    {
        try {
            $result = $this->db->fetchOne("SELECT setting_value FROM library_settings WHERE setting_key = ? AND is_active = 1", [$key]);
            return $result['setting_value'] ?? $default;
        } catch (Exception $e) {
            return $default;
        }
    }
}