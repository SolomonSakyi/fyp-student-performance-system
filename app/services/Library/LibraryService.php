<?php
/**
 * LibraryService.php
 * 
 * Library Management Service Layer
 * 
 * @package EduTrack
 * @subpackage Services\Library
 */

require_once __DIR__ . '/../../models/Library/LibraryModel.php';
require_once __DIR__ . '/../../helpers/DatabaseHelper.php';

class LibraryService
{
    private $db;
    private $model;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->model = new LibraryModel();
    }

    // ================================================================
    // BOOKS
    // ================================================================

    public function getBooks(array $filters = [], int $page = 1, int $limit = 20): array
    {
        try {
            $offset = ($page - 1) * $limit;
            $books = $this->model->getBooks($filters, $limit, $offset);
            $total = $this->model->countBooks($filters);
            
            return [
                'success' => true,
                'data' => [
                    'books' => $books,
                    'pagination' => [
                        'total' => $total,
                        'per_page' => $limit,
                        'current_page' => $page,
                        'total_pages' => ceil($total / $limit)
                    ]
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function addBook(array $data): array
    {
        try {
            $required = ['book_title', 'book_code', 'branch_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => "Missing required field: $field"];
                }
            }

            $bookId = $this->model->createBook($data);
            return ['success' => true, 'message' => 'Book added successfully', 'book_id' => $bookId];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getBook(int $bookId): array
    {
        try {
            $book = $this->model->getBook($bookId);
            if (!$book) {
                return ['success' => false, 'message' => 'Book not found'];
            }
            $copies = $this->model->getBookCopies($bookId);
            return ['success' => true, 'data' => ['book' => $book, 'copies' => $copies]];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteBook(int $bookId): array
    {
        try {
            $this->model->deleteBook($bookId);
            return ['success' => true, 'message' => 'Book deleted successfully'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // SEARCH BOOKS
    // ================================================================

    public function searchBooks(string $keyword, array $filters = [], int $page = 1, int $limit = 20): array
    {
        try {
            $offset = ($page - 1) * $limit;
            $books = $this->model->searchBooks($keyword, $filters, $limit, $offset);
            
            return [
                'success' => true,
                'data' => $books,
                'total' => count($books),
                'page' => $page,
                'limit' => $limit
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getBookByCode(string $bookCode): array
    {
        try {
            $book = $this->model->getBookByCode($bookCode);
            if (!$book) {
                return ['success' => false, 'message' => 'Book not found'];
            }
            return ['success' => true, 'data' => $book];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getBookBorrowCount(int $bookId): array
    {
        try {
            $count = $this->model->getBookBorrowCount($bookId);
            return ['success' => true, 'data' => $count];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // MEMBERSHIPS
    // ================================================================

    public function createMembership(array $data): array
    {
        try {
            $required = ['branch_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => "Missing required field: $field"];
                }
            }

            if (!empty($data['student_id'])) {
                $existing = $this->model->getMembershipByStudent($data['student_id']);
                if ($existing) {
                    return ['success' => false, 'message' => 'Student already has an active membership'];
                }
            }

            $membershipId = $this->model->createMembership($data);
            return ['success' => true, 'message' => 'Membership created successfully', 'membership_id' => $membershipId];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getMemberships(array $filters = [], int $page = 1, int $limit = 20): array
    {
        try {
            $offset = ($page - 1) * $limit;
            $memberships = $this->model->getMemberships($filters, $limit, $offset);
            return ['success' => true, 'data' => ['memberships' => $memberships]];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getMembershipByStudent(int $studentId): array
    {
        try {
            $membership = $this->model->getMembershipByStudent($studentId);
            if (!$membership) {
                return ['success' => false, 'message' => 'No membership found for this student'];
            }
            $activeLoans = $this->model->getActiveLoans($membership['id']);
            return [
                'success' => true,
                'data' => [
                    'membership' => $membership,
                    'active_loans' => $activeLoans,
                    'loan_count' => count($activeLoans)
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getMemberLoans(int $memberId, int $limit = 20): array
    {
        try {
            $loans = $this->model->getMemberLoans($memberId, $limit);
            return ['success' => true, 'data' => $loans];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // LOANS
    // ================================================================

    public function borrowBook(array $data): array
    {
        try {
            $required = ['membership_id', 'book_copy_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => "Missing required field: $field"];
                }
            }

            $loanId = $this->model->createLoan($data);
            return ['success' => true, 'message' => 'Book borrowed successfully', 'loan_id' => $loanId];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function returnBook(int $loanId, ?string $condition = null): array
    {
        try {
            $this->model->returnBook($loanId, $condition);
            return ['success' => true, 'message' => 'Book returned successfully'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function renewLoan(int $loanId): array
    {
        try {
            $maxLoanDays = 14;
            $newDueDate = date('Y-m-d', strtotime('+' . $maxLoanDays . ' days'));
            $this->db->query("
                UPDATE book_loans SET 
                    due_date = ?,
                    renewed_count = renewed_count + 1,
                    last_renewed_date = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ", [$newDueDate, $loanId]);
            return ['success' => true, 'message' => 'Loan renewed successfully', 'new_due_date' => $newDueDate];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getOverdueLoans(): array
    {
        try {
            $sql = "SELECT bl.*, m.membership_number, s.first_name, s.last_name, s.admission_number,
                           b.book_title, b.book_code, bc.copy_number,
                           DATEDIFF(CURDATE(), bl.due_date) AS days_overdue
                    FROM book_loans bl
                    JOIN library_memberships m ON bl.membership_id = m.id
                    LEFT JOIN students s ON m.student_id = s.id
                    LEFT JOIN book_copies bc ON bl.book_copy_id = bc.id
                    LEFT JOIN books b ON bc.book_id = b.id
                    WHERE bl.status = 'overdue' AND bl.is_active = 1
                    ORDER BY bl.due_date ASC";
            $loans = $this->db->fetchAll($sql);
            return ['success' => true, 'data' => $loans];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getOverdueCount(): array
    {
        try {
            $count = $this->model->getOverdueCount();
            return ['success' => true, 'data' => $count];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // AUTHORS & PUBLISHERS
    // ================================================================

    public function getAuthors(array $filters = []): array
    {
        try {
            $sql = "SELECT * FROM authors WHERE is_active = 1 ORDER BY author_name ASC";
            $authors = $this->db->fetchAll($sql);
            return ['success' => true, 'data' => $authors];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getPublishers(array $filters = []): array
    {
        try {
            $sql = "SELECT * FROM publishers WHERE is_active = 1 ORDER BY publisher_name ASC";
            $publishers = $this->db->fetchAll($sql);
            return ['success' => true, 'data' => $publishers];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function addAuthorToBook(int $bookId, int $authorId, bool $isPrimary = false): array
    {
        try {
            $result = $this->model->addAuthorToBook($bookId, $authorId, $isPrimary);
            if ($result) {
                return ['success' => true, 'message' => 'Author added to book successfully'];
            }
            return ['success' => false, 'message' => 'Failed to add author to book'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function removeAuthorFromBook(int $bookId, int $authorId): array
    {
        try {
            $result = $this->model->removeAuthorFromBook($bookId, $authorId);
            if ($result) {
                return ['success' => true, 'message' => 'Author removed from book successfully'];
            }
            return ['success' => false, 'message' => 'Failed to remove author from book'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // STATISTICS
    // ================================================================

    public function getStatistics(): array
    {
        try {
            $stats = $this->model->getStatistics();
            return ['success' => true, 'data' => $stats];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getDashboardSummary(): array
    {
        try {
            $stats = $this->getStatistics();

            $recentLoans = $this->db->fetchAll("
                SELECT bl.*, s.first_name, s.last_name, b.book_title
                FROM book_loans bl
                LEFT JOIN library_memberships m ON bl.membership_id = m.id
                LEFT JOIN students s ON m.student_id = s.id
                LEFT JOIN book_copies bc ON bl.book_copy_id = bc.id
                LEFT JOIN books b ON bc.book_id = b.id
                WHERE bl.is_active = 1
                ORDER BY bl.created_at DESC
                LIMIT 10
            ");

            return [
                'success' => true,
                'data' => [
                    'statistics' => $stats['data'],
                    'recent_loans' => $recentLoans
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // SETTINGS
    // ================================================================

    public function getSettings(): array
    {
        try {
            $settings = $this->db->fetchAll("SELECT setting_key, setting_value, setting_category FROM library_settings WHERE is_active = 1");
            return ['success' => true, 'data' => $settings];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateSetting(string $key, string $value): array
    {
        try {
            $existing = $this->db->fetchOne("SELECT id FROM library_settings WHERE setting_key = ?", [$key]);
            if ($existing) {
                $this->db->query("UPDATE library_settings SET setting_value = ? WHERE setting_key = ?", [$value, $key]);
            } else {
                $this->db->query("INSERT INTO library_settings (uuid, school_id, setting_key, setting_value, setting_category, is_active)
                                VALUES (UUID(), 1, ?, ?, 'general', 1)", [$key, $value]);
            }
            return ['success' => true, 'message' => 'Setting updated successfully'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}