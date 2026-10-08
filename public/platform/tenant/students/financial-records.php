<?php

/**
 * Student Financial Records — charges, payments and derived balance
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Students
 * @version 1.0
 * @filepath public/platform/tenant/students/financial-records.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Students financial-records file of the students-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'students_financial_records'
 *       to 'students' so the partial marks Students active and
 *       renders the student sub-menu on this page — consistent
 *       with every other students file.
 *     - A v1.0 [SWEEP] entry was added above this docblock.
 *     - The inline <nav class="sidebar" id="sidebar"> block is
 *       removed and replaced by an include of
 *       app/views/partials/sidebar.php.
 *     - The CSS rule .nav-subgroup-label is added to this file's
 *       <style> block. The .nav-sub, .nav-sub .nav-link, and
 *       .nav-sub .nav-link.active rules, and the two responsive
 *       rules for .nav-sub, were already present in this file and
 *       are preserved unchanged.
 *   Every other line of the file is byte-identical to the
 *   previous version (1.3). The @package tag remains 'EduTrack'.
 *
 * Session S17f decisions:
 *   FS1A  two tables (student_fee_charges + student_fee_payments)
 *   FS2A  editable fee_categories, seeded per tenant
 *   FS3A  charge grain: (student, category, year, term)
 *   FS4A  balance derived on the fly
 *   FS5A  payment: amount, paid_at, method enum, reference, receipt_number, received_by, notes
 *   FS6A  charge: amount, category_id, year, term, description, due_date, notes
 *   FS7A  currency from academic_settings, fall back GHS
 *   FS8A  any staff may charge; only finance-flagged staff may pay
 *   FS9A  immediate effect for both charges and payments
 *   FS10A free soft-delete, audited
 *   FS11A one page per student (tabs Charges/Payments), summary header
 *   FS12A under students/
 *
 * Session S17g-2 additions:
 *   P3  payment recorded → guardian notified with new balance
 *   P5  fee reminder (single + bulk)
 *
 * Session S20 decisions:
 *   SH2A  per-session CSRF token; all POSTs carry it
 *   SH3A  central Security.php helper, loaded via app/bootstrap.php
 *   SH4A  hardened session started inside bootstrap
 *   SH5C  h() on every echoed value
 *   SH6B  tenant_id scoping and deleted_at IS NULL on every query
 *   SH10A generic error messages; real error logged
 */

// ============================================
// S20 — Bootstrap (session, CSRF, headers, HTTPS, DB)
// ============================================
$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/app/bootstrap.php';

require_tenant();

$tenantId     = current_tenant_id();
$schoolId     = (int)($_SESSION['school_id'] ?? 0);
$userId       = current_user_id();
$currentUser  = $_SESSION['user_name'] ?? 'Admin';
$userAvatar   = substr($currentUser, 0, 1);
$isSuperAdmin = is_super_admin();
$isFinance    = is_finance();

$pageTitle   = 'Financial Records - Student 360 Platform';
$currentPage = 'students';

// ============================================
// AJAX: LIVE STUDENT SEARCH (read-only, no CSRF)
// ============================================
if (isset($_GET['ajax_search_students'])) {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $like = '%' . $q . '%';
    try {
        $rows = $db->fetchAll(
            "SELECT s.id, s.student_number,
                    s.first_name, s.middle_name, s.last_name, s.preferred_name
             FROM students s
             WHERE s.tenant_id = ?
               AND s.deleted_at IS NULL
               AND s.is_active = 1
               AND (
                    s.student_number LIKE ?
                 OR s.first_name LIKE ?
                 OR s.middle_name LIKE ?
                 OR s.last_name LIKE ?
                 OR s.preferred_name LIKE ?
                 OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?
                 OR CONCAT(s.first_name, ' ', s.middle_name, ' ', s.last_name) LIKE ?
               )
             ORDER BY s.first_name ASC, s.last_name ASC
             LIMIT 20",
            [$tenantId, $like, $like, $like, $like, $like, $like, $like]
        );
        $items = [];
        foreach ($rows as $r) {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            if ($name === '') $name = 'Student #' . (int)$r['id'];
            $items[] = [
                'id'    => (int)$r['id'],
                'name'  => $name,
                'num'   => (string)$r['student_number'],
                'label' => $name . ' — ' . (string)$r['student_number'],
            ];
        }
        echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log('financial ajax_search error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ============================================
// HELPERS
// ============================================
function uuidv4(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

// h() is defined in Security.php; do not redeclare it here.

function writeAudit($db, int $tenantId, int $userId, string $action, string $resourceType, ?int $resourceId, array $details): void
{
    try {
        $db->insert(
            "INSERT INTO audit_logs (user_id, tenant_id, action, resource_type, resource_id, details, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $userId ?: null,
                $tenantId,
                $action,
                $resourceType,
                $resourceId,
                json_encode($details, JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]
        );
    } catch (Exception $e) {
        error_log('audit_logs write failed: ' . $e->getMessage());
    }
}

function loadActiveSettings($db, int $tenantId): ?array
{
    $row = $db->fetchOne(
        "SELECT * FROM academic_settings
         WHERE tenant_id = ? AND status = 'active' AND deleted_at IS NULL
         ORDER BY version DESC, id DESC LIMIT 1",
        [$tenantId]
    );
    return $row ?: null;
}

function currentYear($db, int $tenantId): ?array
{
    return $db->fetchOne(
        "SELECT * FROM academic_years
         WHERE tenant_id = ? AND is_current = 1 AND deleted_at IS NULL
         ORDER BY id DESC LIMIT 1",
        [$tenantId]
    ) ?: null;
}

function currentTerm($db, int $tenantId): ?array
{
    return $db->fetchOne(
        "SELECT * FROM academic_terms
         WHERE tenant_id = ? AND is_current = 1 AND is_active = 1 AND deleted_at IS NULL
         ORDER BY id DESC LIMIT 1",
        [$tenantId]
    ) ?: null;
}

function methodPill(string $m): string
{
    return [
        'cash'          => 'green',
        'bank_transfer' => 'blue',
        'cheque'        => 'purple',
        'mobile_money'  => 'teal',
        'other'         => 'gray',
    ][$m] ?? 'gray';
}
function methodLabel(string $m): string
{
    return [
        'cash'          => 'Cash',
        'bank_transfer' => 'Bank transfer',
        'cheque'        => 'Cheque',
        'mobile_money'  => 'Mobile money',
        'other'         => 'Other',
    ][$m] ?? ucfirst($m);
}

function money($amount, string $currency): string
{
    return $currency . ' ' . number_format((float)$amount, 2);
}

// ============================================
// CURRENCY (needed by both POST actions and page render)
// ============================================
$__settings = loadActiveSettings($db, $tenantId);
$currency = 'GHS';
if (is_array($__settings) && !empty($__settings['currency'])) {
    $currency = (string)$__settings['currency'];
} elseif (is_array($__settings) && !empty($__settings['currency_code'])) {
    $currency = (string)$__settings['currency_code'];
}
unset($__settings);

// ============================================
// ACTIONS (POST)
// ============================================
$errors   = [];
$formData = [];
$action   = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();          // S20 SH2A — all POSTs must carry the session CSRF token
    $formData = $_POST;

    try {
        // ---------------- CREATE CHARGE ----------------
        if ($action === 'create_charge') {
            $studentId    = (int)($_POST['student_id'] ?? 0);
            $categoryId   = (int)($_POST['category_id'] ?? 0);
            $yearId       = (int)($_POST['academic_year_id'] ?? 0);
            $termId       = (int)($_POST['academic_term_id'] ?? 0);
            $amount       = trim((string)($_POST['amount'] ?? ''));
            $description  = trim((string)($_POST['description'] ?? ''));
            $dueDate      = trim((string)($_POST['due_date'] ?? ''));
            $notes        = trim((string)($_POST['notes'] ?? ''));

            if ($studentId <= 0)  throw new Exception('Student is required.');
            if ($categoryId <= 0) throw new Exception('Category is required.');
            if ($yearId <= 0)     throw new Exception('Academic year is required.');
            if ($amount === '' || !is_numeric($amount) || (float)$amount <= 0) {
                throw new Exception('Amount must be a positive number.');
            }
            $amount = (float)$amount;
            if ($dueDate !== '' && !strtotime($dueDate)) $dueDate = '';

            $stu = $db->fetchOne(
                "SELECT id FROM students WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$studentId, $tenantId]
            );
            if (!$stu) throw new Exception('Student not found.');

            $cat = $db->fetchOne(
                "SELECT id FROM fee_categories WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$categoryId, $tenantId]
            );
            if (!$cat) throw new Exception('Fee category not found.');

            $yr = $db->fetchOne(
                "SELECT id FROM academic_years WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$yearId, $tenantId]
            );
            if (!$yr) throw new Exception('Academic year not found.');

            if ($termId > 0) {
                $t = $db->fetchOne(
                    "SELECT id FROM academic_terms WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$termId, $tenantId]
                );
                if (!$t) $termId = 0;
            }

            $db->beginTransaction();
            $newId = (int)$db->insert(
                "INSERT INTO student_fee_charges
                    (uuid, tenant_id, student_id, category_id,
                     academic_year_id, academic_term_id,
                     amount, description, due_date, notes,
                     created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    uuidv4(),
                    $tenantId,
                    $studentId,
                    $categoryId,
                    $yearId,
                    $termId > 0 ? $termId : null,
                    $amount,
                    $description !== '' ? $description : null,
                    $dueDate !== '' ? $dueDate : null,
                    $notes !== '' ? $notes : null,
                    $userId ?: null,
                ]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.finance.charge_created',
                'student_fee_charges',
                $newId,
                ['student_id' => $studentId, 'category_id' => $categoryId, 'amount' => $amount, 'term_id' => $termId]
            );
            $db->commit();

            $_SESSION['success'] = 'Charge added.';
            header('Location: /platform/tenant/students/financial-records.php?student_id=' . $studentId);
            exit;
        }

        // ---------------- UPDATE CHARGE ----------------
        if ($action === 'update_charge') {
            $id           = (int)($_POST['id'] ?? 0);
            $categoryId   = (int)($_POST['category_id'] ?? 0);
            $amount       = trim((string)($_POST['amount'] ?? ''));
            $description  = trim((string)($_POST['description'] ?? ''));
            $dueDate      = trim((string)($_POST['due_date'] ?? ''));
            $notes        = trim((string)($_POST['notes'] ?? ''));

            if ($id <= 0) throw new Exception('Charge id is required.');
            if ($categoryId <= 0) throw new Exception('Category is required.');
            if ($amount === '' || !is_numeric($amount) || (float)$amount <= 0) {
                throw new Exception('Amount must be a positive number.');
            }
            $amount = (float)$amount;
            if ($dueDate !== '' && !strtotime($dueDate)) $dueDate = '';

            $row = $db->fetchOne(
                "SELECT * FROM student_fee_charges WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Charge not found.');

            $cat = $db->fetchOne(
                "SELECT id FROM fee_categories WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$categoryId, $tenantId]
            );
            if (!$cat) throw new Exception('Fee category not found.');

            $db->beginTransaction();
            $db->execute(
                "UPDATE student_fee_charges
                    SET category_id = ?, amount = ?, description = ?, due_date = ?, notes = ?,
                        updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [
                    $categoryId,
                    $amount,
                    $description !== '' ? $description : null,
                    $dueDate !== '' ? $dueDate : null,
                    $notes !== '' ? $notes : null,
                    $id,
                    $tenantId,
                ]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.finance.charge_updated',
                'student_fee_charges',
                $id,
                ['category_id' => $categoryId, 'amount' => $amount]
            );
            $db->commit();

            $_SESSION['success'] = 'Charge updated.';
            header('Location: /platform/tenant/students/financial-records.php?student_id=' . (int)$row['student_id']);
            exit;
        }

        // ---------------- DELETE CHARGE ----------------
        if ($action === 'delete_charge') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Charge id is required.');

            $row = $db->fetchOne(
                "SELECT * FROM student_fee_charges WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Charge not found.');

            $db->beginTransaction();
            $db->execute(
                "UPDATE student_fee_charges SET deleted_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.finance.charge_deleted',
                'student_fee_charges',
                $id,
                ['student_id' => (int)$row['student_id'], 'amount' => $row['amount']]
            );
            $db->commit();

            $_SESSION['success'] = 'Charge deleted.';
            header('Location: /platform/tenant/students/financial-records.php?student_id=' . (int)$row['student_id']);
            exit;
        }

        // ---------------- CREATE PAYMENT ----------------
        if ($action === 'create_payment') {
            if (!$isFinance && !$isSuperAdmin) {
                throw new Exception('Only finance-flagged staff can record payments.');
            }

            $studentId    = (int)($_POST['student_id'] ?? 0);
            $yearId       = (int)($_POST['academic_year_id'] ?? 0);
            $termId       = (int)($_POST['academic_term_id'] ?? 0);
            $amount       = trim((string)($_POST['amount'] ?? ''));
            $paidAt       = trim((string)($_POST['paid_at'] ?? ''));
            $method       = trim((string)($_POST['method'] ?? 'cash'));
            $reference    = trim((string)($_POST['reference'] ?? ''));
            $receiptNo    = trim((string)($_POST['receipt_number'] ?? ''));
            $notes        = trim((string)($_POST['notes'] ?? ''));

            if ($studentId <= 0) throw new Exception('Student is required.');
            if ($amount === '' || !is_numeric($amount) || (float)$amount <= 0) {
                throw new Exception('Amount must be a positive number.');
            }
            $amount = (float)$amount;
            if ($paidAt === '' || !strtotime($paidAt)) throw new Exception('A valid payment date is required.');
            if (!in_array($method, ['cash', 'bank_transfer', 'cheque', 'mobile_money', 'other'], true)) {
                $method = 'cash';
            }

            $stu = $db->fetchOne(
                "SELECT id FROM students WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$studentId, $tenantId]
            );
            if (!$stu) throw new Exception('Student not found.');

            if ($yearId > 0) {
                $yr = $db->fetchOne(
                    "SELECT id FROM academic_years WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$yearId, $tenantId]
                );
                if (!$yr) $yearId = 0;
            }
            if ($termId > 0) {
                $t = $db->fetchOne(
                    "SELECT id FROM academic_terms WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$termId, $tenantId]
                );
                if (!$t) $termId = 0;
            }

            $db->beginTransaction();
            $newId = (int)$db->insert(
                "INSERT INTO student_fee_payments
                    (uuid, tenant_id, student_id, academic_year_id, academic_term_id,
                     amount, paid_at, method, reference, receipt_number,
                     received_by, notes, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    uuidv4(),
                    $tenantId,
                    $studentId,
                    $yearId > 0 ? $yearId : null,
                    $termId > 0 ? $termId : null,
                    $amount,
                    $paidAt,
                    $method,
                    $reference !== '' ? $reference : null,
                    $receiptNo !== '' ? $receiptNo : null,
                    $userId ?: null,
                    $notes !== '' ? $notes : null,
                    $userId ?: null,
                ]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.finance.payment_created',
                'student_fee_payments',
                $newId,
                ['student_id' => $studentId, 'amount' => $amount, 'method' => $method, 'paid_at' => $paidAt]
            );

            // ---------------------------------------------------------------
            // P3 — Notify guardian on payment with updated balance (PR3A)
            // ---------------------------------------------------------------
            try {
                require_once $projectRoot . '/app/helpers/NotificationHelper.php';

                $charged = (float)($db->getValue(
                    "SELECT COALESCE(SUM(amount),0) FROM student_fee_charges
                      WHERE tenant_id = ? AND student_id = ? AND deleted_at IS NULL",
                    [$tenantId, $studentId]
                ) ?? 0);
                $paidTotal = (float)($db->getValue(
                    "SELECT COALESCE(SUM(amount),0) FROM student_fee_payments
                      WHERE tenant_id = ? AND student_id = ? AND deleted_at IS NULL",
                    [$tenantId, $studentId]
                ) ?? 0);
                $newBalance = $charged - $paidTotal;

                $sn = $db->fetchOne(
                    "SELECT first_name, middle_name, last_name
                       FROM students WHERE id = ? AND tenant_id = ?",
                    [$studentId, $tenantId]
                );
                $stuName = $sn ? trim(($sn['first_name'] ?? '') . ' ' . ($sn['middle_name'] ?? '') . ' ' . ($sn['last_name'] ?? '')) : ('Student #' . $studentId);
                if ($stuName === '') $stuName = 'Student #' . $studentId;

                $g = NotificationHelper::guardianForStudent($db, $tenantId, $studentId);
                if ($g) {
                    NotificationHelper::notify($db, $tenantId, [
                        'recipients'   => ['guardians' => [$g]],
                        'type'         => 'finance',
                        'priority'     => 'normal',
                        'title'        => 'Payment received',
                        'message'      => 'Payment of ' . money($amount, $currency) . ' for ' . $stuName . ' on ' . $paidAt . '. New balance: ' . money($newBalance, $currency) . '.',
                        'link'         => '/platform/tenant/students/financial-records.php?student_id=' . $studentId . '&tab=payments',
                        'related_type' => 'student_fee_payments',
                        'related_id'   => $newId,
                        'created_by'   => $userId,
                    ]);
                }
            } catch (Exception $notifyEx) {
                error_log('P3 notify failed: ' . $notifyEx->getMessage());
            }

            $db->commit();

            $_SESSION['success'] = 'Payment recorded.';
            header('Location: /platform/tenant/students/financial-records.php?student_id=' . $studentId);
            exit;
        }

        // ---------------- UPDATE PAYMENT ----------------
        if ($action === 'update_payment') {
            if (!$isFinance && !$isSuperAdmin) {
                throw new Exception('Only finance-flagged staff can edit payments.');
            }

            $id          = (int)($_POST['id'] ?? 0);
            $amount      = trim((string)($_POST['amount'] ?? ''));
            $paidAt      = trim((string)($_POST['paid_at'] ?? ''));
            $method      = trim((string)($_POST['method'] ?? 'cash'));
            $reference   = trim((string)($_POST['reference'] ?? ''));
            $receiptNo   = trim((string)($_POST['receipt_number'] ?? ''));
            $notes       = trim((string)($_POST['notes'] ?? ''));

            if ($id <= 0) throw new Exception('Payment id is required.');
            if ($amount === '' || !is_numeric($amount) || (float)$amount <= 0) {
                throw new Exception('Amount must be a positive number.');
            }
            $amount = (float)$amount;
            if ($paidAt === '' || !strtotime($paidAt)) throw new Exception('A valid payment date is required.');
            if (!in_array($method, ['cash', 'bank_transfer', 'cheque', 'mobile_money', 'other'], true)) {
                $method = 'cash';
            }

            $row = $db->fetchOne(
                "SELECT * FROM student_fee_payments WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Payment not found.');

            $db->beginTransaction();
            $db->execute(
                "UPDATE student_fee_payments
                    SET amount = ?, paid_at = ?, method = ?, reference = ?, receipt_number = ?, notes = ?,
                        updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [
                    $amount,
                    $paidAt,
                    $method,
                    $reference !== '' ? $reference : null,
                    $receiptNo !== '' ? $receiptNo : null,
                    $notes !== '' ? $notes : null,
                    $id,
                    $tenantId,
                ]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.finance.payment_updated',
                'student_fee_payments',
                $id,
                ['amount' => $amount, 'method' => $method, 'paid_at' => $paidAt]
            );
            $db->commit();

            $_SESSION['success'] = 'Payment updated.';
            header('Location: /platform/tenant/students/financial-records.php?student_id=' . (int)$row['student_id']);
            exit;
        }

        // ---------------- DELETE PAYMENT ----------------
        if ($action === 'delete_payment') {
            if (!$isFinance && !$isSuperAdmin) {
                throw new Exception('Only finance-flagged staff can delete payments.');
            }

            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Payment id is required.');

            $row = $db->fetchOne(
                "SELECT * FROM student_fee_payments WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Payment not found.');

            $db->beginTransaction();
            $db->execute(
                "UPDATE student_fee_payments SET deleted_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.finance.payment_deleted',
                'student_fee_payments',
                $id,
                ['student_id' => (int)$row['student_id'], 'amount' => $row['amount']]
            );
            $db->commit();

            $_SESSION['success'] = 'Payment deleted.';
            header('Location: /platform/tenant/students/financial-records.php?student_id=' . (int)$row['student_id']);
            exit;
        }

        // ---------------- CREATE CATEGORY (quick create) ----------------
        if ($action === 'create_category') {
            $name = trim((string)($_POST['category_name'] ?? ''));
            $code = trim((string)($_POST['category_code'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));

            if ($name === '') throw new Exception('Category name is required.');

            $dup = $db->fetchOne(
                "SELECT id FROM fee_categories
                 WHERE tenant_id = ? AND category_name = ? AND deleted_at IS NULL",
                [$tenantId, $name]
            );
            if ($dup) throw new Exception('A category with that name already exists.');

            $db->beginTransaction();
            $newId = (int)$db->insert(
                "INSERT INTO fee_categories
                    (uuid, tenant_id, category_name, category_code, description,
                     sort_order, is_active, is_system, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 500, 1, 0, ?, NOW(), NOW())",
                [
                    uuidv4(),
                    $tenantId,
                    $name,
                    $code !== '' ? $code : null,
                    $desc !== '' ? $desc : null,
                    $userId ?: null,
                ]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.finance.category_created',
                'fee_categories',
                $newId,
                ['name' => $name]
            );
            $db->commit();

            $_SESSION['success'] = 'Fee category created.';
            $back = (int)($_POST['back_student_id'] ?? 0);
            header('Location: /platform/tenant/students/financial-records.php' . ($back > 0 ? '?student_id=' . $back : ''));
            exit;
        }

        // ---------------- P5: SEND FEE REMINDER (single) ----------------
        if ($action === 'send_fee_reminder') {
            $studentId = (int)($_POST['student_id'] ?? 0);
            $termId    = (int)($_POST['academic_term_id'] ?? 0);

            if ($studentId <= 0) throw new Exception('Student is required.');

            $stu = $db->fetchOne(
                "SELECT id, first_name, middle_name, last_name
                   FROM students WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$studentId, $tenantId]
            );
            if (!$stu) throw new Exception('Student not found.');

            $wC = ["tenant_id = ?", "student_id = ?", "deleted_at IS NULL"];
            $pC = [$tenantId, $studentId];
            if ($termId > 0) {
                $wC[] = "academic_term_id = ?";
                $pC[] = $termId;
            }
            $charged = (float)($db->getValue(
                "SELECT COALESCE(SUM(amount),0) FROM student_fee_charges WHERE " . implode(' AND ', $wC),
                $pC
            ) ?? 0);

            $wP = ["tenant_id = ?", "student_id = ?", "deleted_at IS NULL"];
            $pP = [$tenantId, $studentId];
            if ($termId > 0) {
                $wP[] = "academic_term_id = ?";
                $pP[] = $termId;
            }
            $paid = (float)($db->getValue(
                "SELECT COALESCE(SUM(amount),0) FROM student_fee_payments WHERE " . implode(' AND ', $wP),
                $pP
            ) ?? 0);

            $balance = $charged - $paid;
            if ($balance <= 0) throw new Exception('Nothing to remind — the balance is already cleared.');

            $stuName = trim(($stu['first_name'] ?? '') . ' ' . ($stu['middle_name'] ?? '') . ' ' . ($stu['last_name'] ?? ''));
            if ($stuName === '') $stuName = 'Student #' . $studentId;

            $termName = '';
            if ($termId > 0) {
                $t = $db->fetchOne(
                    "SELECT term_name FROM academic_terms WHERE id = ? AND tenant_id = ?",
                    [$termId, $tenantId]
                );
                if ($t) $termName = $t['term_name'];
            }

            require_once $projectRoot . '/app/helpers/NotificationHelper.php';
            $g = NotificationHelper::guardianForStudent($db, $tenantId, $studentId);
            if (!$g) throw new Exception('No guardian contact found for this student.');

            NotificationHelper::notify($db, $tenantId, [
                'recipients'   => ['guardians' => [$g]],
                'type'         => 'reminder',
                'priority'     => 'normal',
                'title'        => 'Fee reminder — ' . $stuName,
                'message'      => ($termName !== '' ? $termName . ' ' : '') . 'fees for ' . $stuName
                    . ' are outstanding. Balance: ' . money($balance, $currency) . '.',
                'link'         => '/platform/tenant/students/financial-records.php?student_id=' . $studentId,
                'related_type' => 'students',
                'related_id'   => $studentId,
                'created_by'   => $userId,
            ]);

            $_SESSION['success'] = 'Fee reminder sent.';
            header('Location: /platform/tenant/students/financial-records.php?student_id=' . $studentId);
            exit;
        }

        // ---------------- P5 (bulk): SEND FEE REMINDERS TO ALL WITH BALANCE > 0 ----------------
        if ($action === 'send_fee_reminders_bulk') {
            $termId = (int)($_POST['academic_term_id'] ?? 0);

            $students = $db->fetchAll(
                "SELECT DISTINCT s.id
                   FROM students s
                  WHERE s.tenant_id = ? AND s.deleted_at IS NULL AND s.is_active = 1",
                [$tenantId]
            );

            require_once $projectRoot . '/app/helpers/NotificationHelper.php';

            $sent = 0;
            foreach ($students as $s) {
                $sid = (int)$s['id'];

                $wC = ["tenant_id = ?", "student_id = ?", "deleted_at IS NULL"];
                $pC = [$tenantId, $sid];
                if ($termId > 0) {
                    $wC[] = "academic_term_id = ?";
                    $pC[] = $termId;
                }
                $charged = (float)($db->getValue(
                    "SELECT COALESCE(SUM(amount),0) FROM student_fee_charges WHERE " . implode(' AND ', $wC),
                    $pC
                ) ?? 0);

                $wP = ["tenant_id = ?", "student_id = ?", "deleted_at IS NULL"];
                $pP = [$tenantId, $sid];
                if ($termId > 0) {
                    $wP[] = "academic_term_id = ?";
                    $pP[] = $termId;
                }
                $paid = (float)($db->getValue(
                    "SELECT COALESCE(SUM(amount),0) FROM student_fee_payments WHERE " . implode(' AND ', $wP),
                    $pP
                ) ?? 0);

                $balance = $charged - $paid;
                if ($balance <= 0) continue;

                $g = NotificationHelper::guardianForStudent($db, $tenantId, $sid);
                if (!$g) continue;

                $sn = $db->fetchOne(
                    "SELECT first_name, middle_name, last_name FROM students WHERE id = ? AND tenant_id = ?",
                    [$sid, $tenantId]
                );
                $stuName = $sn ? trim(($sn['first_name'] ?? '') . ' ' . ($sn['middle_name'] ?? '') . ' ' . ($sn['last_name'] ?? '')) : ('Student #' . $sid);
                if ($stuName === '') $stuName = 'Student #' . $sid;

                NotificationHelper::notify($db, $tenantId, [
                    'recipients'   => ['guardians' => [$g]],
                    'type'         => 'reminder',
                    'priority'     => 'normal',
                    'title'        => 'Fee reminder — ' . $stuName,
                    'message'      => 'Fees for ' . $stuName . ' are outstanding. Balance: ' . money($balance, $currency) . '.',
                    'link'         => '/platform/tenant/students/financial-records.php?student_id=' . $sid,
                    'related_type' => 'students',
                    'related_id'   => $sid,
                    'created_by'   => $userId,
                ]);
                $sent++;
            }

            $_SESSION['success'] = 'Fee reminders sent to ' . $sent . ' guardian(s).';
            header('Location: /platform/tenant/students/financial-records.php');
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Financial records action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        $back = (int)($_POST['student_id'] ?? 0);
        header('Location: /platform/tenant/students/financial-records.php' . ($back > 0 ? '?student_id=' . $back : ''));
        exit;
    }
}

// ============================================
// FLASH
// ============================================
if (isset($_SESSION['errors'])) {
    $errors = $_SESSION['errors'];
    unset($_SESSION['errors']);
}
$useForm = false;
if (isset($_SESSION['form_data'])) {
    $formData = $_SESSION['form_data'];
    $useForm = true;
    unset($_SESSION['form_data']);
}
$successMessage = null;
if (isset($_SESSION['success'])) {
    $successMessage = $_SESSION['success'];
    unset($_SESSION['success']);
}

// ============================================
// LOAD PAGE DATA
// ============================================
$settings      = loadActiveSettings($db, $tenantId);
$labelAcademic = $settings['label_academic_structure'] ?? 'Academic Year';
$labelTerm     = $settings['label_term'] ?? 'Term';

$studentId   = (int)($_GET['student_id'] ?? 0);
$tab         = trim((string)($_GET['tab'] ?? 'charges'));
if (!in_array($tab, ['charges', 'payments'], true)) $tab = 'charges';

$filterYear  = (int)($_GET['year_id'] ?? 0);
$filterTerm  = (int)($_GET['term_id'] ?? 0);

$years = $db->fetchAll(
    "SELECT id, year_name, is_current FROM academic_years
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY is_current DESC, start_date DESC, id DESC",
    [$tenantId]
);
$terms = $db->fetchAll(
    "SELECT id, term_name, is_current, sort_order FROM academic_terms
     WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL
     ORDER BY is_current DESC, sort_order ASC, id ASC",
    [$tenantId]
);

if ($filterYear <= 0) {
    $cy = currentYear($db, $tenantId);
    if ($cy) $filterYear = (int)$cy['id'];
}
if ($filterYear <= 0 && !empty($years)) $filterYear = (int)$years[0]['id'];

$categories = $db->fetchAll(
    "SELECT * FROM fee_categories
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY sort_order ASC, category_name ASC",
    [$tenantId]
);

$student = null;
if ($studentId > 0) {
    $student = $db->fetchOne(
        "SELECT s.*, c.class_name, c.class_code
         FROM students s
         LEFT JOIN classes c ON s.class_id = c.id
         WHERE s.id = ? AND s.tenant_id = ? AND s.deleted_at IS NULL",
        [$studentId, $tenantId]
    );
}

$charges = [];
$payments = [];
$totalCharged = 0.0;
$totalPaid = 0.0;
$termCharged = 0.0;
$termPaid = 0.0;
$balance = 0.0;
$termBalance = 0.0;

if ($student) {
    // ---- Charges (aliased: ch) ----
    $wC = ["ch.tenant_id = ?", "ch.student_id = ?", "ch.deleted_at IS NULL"];
    $pC = [$tenantId, $studentId];
    if ($filterYear > 0) {
        $wC[] = "ch.academic_year_id = ?";
        $pC[] = $filterYear;
    }
    if ($filterTerm > 0) {
        $wC[] = "ch.academic_term_id = ?";
        $pC[] = $filterTerm;
    }
    $whereC = implode(' AND ', $wC);

    $charges = $db->fetchAll(
        "SELECT ch.*, fc.category_name, fc.category_code,
                ay.year_name, t.term_name
         FROM student_fee_charges ch
         LEFT JOIN fee_categories fc ON ch.category_id = fc.id
         LEFT JOIN academic_years ay ON ch.academic_year_id = ay.id
         LEFT JOIN academic_terms t ON ch.academic_term_id = t.id
         WHERE $whereC
         ORDER BY ch.academic_year_id DESC, ch.academic_term_id DESC, ch.id DESC",
        $pC
    );

    // ---- Payments (aliased: p) ----
    $wP = ["p.tenant_id = ?", "p.student_id = ?", "p.deleted_at IS NULL"];
    $pP = [$tenantId, $studentId];
    if ($filterYear > 0) {
        $wP[] = "p.academic_year_id = ?";
        $pP[] = $filterYear;
    }
    if ($filterTerm > 0) {
        $wP[] = "p.academic_term_id = ?";
        $pP[] = $filterTerm;
    }
    $whereP = implode(' AND ', $wP);

    $payments = $db->fetchAll(
        "SELECT p.*, ay.year_name, t.term_name
         FROM student_fee_payments p
         LEFT JOIN academic_years ay ON p.academic_year_id = ay.id
         LEFT JOIN academic_terms t ON p.academic_term_id = t.id
         WHERE $whereP
         ORDER BY p.paid_at DESC, p.id DESC",
        $pP
    );

    foreach ($charges as $c) $totalCharged += (float)$c['amount'];
    foreach ($payments as $p) $totalPaid += (float)$p['amount'];
    $balance = $totalCharged - $totalPaid;

    if ($filterTerm > 0) {
        foreach ($charges as $c) if ((int)$c['academic_term_id'] === $filterTerm) $termCharged += (float)$c['amount'];
        foreach ($payments as $p) if ((int)$p['academic_term_id'] === $filterTerm) $termPaid += (float)$p['amount'];
        $termBalance = $termCharged - $termPaid;
    }
}

$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

$editCharge = null;
$editPayment = null;
if (($_GET['action'] ?? '') === 'edit_charge' && !empty($_GET['id'])) {
    $editCharge = $db->fetchOne(
        "SELECT * FROM student_fee_charges WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [(int)$_GET['id'], $tenantId]
    );
}
if (($_GET['action'] ?? '') === 'edit_payment' && !empty($_GET['id'])) {
    $editPayment = $db->fetchOne(
        "SELECT * FROM student_fee_payments WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [(int)$_GET['id'], $tenantId]
    );
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="<?php echo h(csrf_token()); ?>">
    <title><?php echo h($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            overflow-x: hidden !important;
            width: 100%;
            max-width: 100%;
            background: #f0f2f5;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            line-height: 1.6;
            color: #1a1a2e;
        }

        .container-fluid {
            padding: 0;
            margin: 0;
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
        }

        .row {
            margin: 0;
            width: 100%;
            max-width: 100%;
        }

        [class*="col-"] {
            padding-left: 12px;
            padding-right: 12px;
        }

        .sidebar-toggle {
            display: none;
            position: fixed;
            top: 14px;
            left: 14px;
            z-index: 1001;
            background: #1a1a2e;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 22px;
            cursor: pointer;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.2);
        }

        .sidebar-toggle:hover {
            background: #2a2a4e;
        }

        .sidebar {
            min-height: 100vh;
            background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
            color: #fff;
            position: fixed;
            width: 260px;
            left: 0;
            top: 0;
            z-index: 1000;
            transition: transform 0.3s ease;
            overflow-y: auto;
            padding: 0;
        }

        .sidebar .sidebar-header {
            padding: 25px 24px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .sidebar .sidebar-header h4 {
            font-weight: 700;
            font-size: 20px;
            margin: 0;
        }

        .sidebar .sidebar-header h4 i {
            color: #4facfe;
        }

        .sidebar .sidebar-header small {
            color: rgba(255, 255, 255, 0.4);
            font-size: 12px;
        }

        .sidebar .nav {
            padding: 16px 12px;
        }

        .sidebar .nav-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.3);
            padding: 0 12px 8px;
            font-weight: 600;
        }

        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.6);
            padding: 10px 16px;
            border-radius: 10px;
            margin: 2px 0;
            transition: all 0.3s;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            text-decoration: none;
        }

        .sidebar .nav-link:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
        }

        .sidebar .nav-link.active {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: #fff;
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.3);
        }

        .sidebar .nav-link i {
            width: 22px;
            text-align: center;
            margin-right: 12px;
            font-size: 15px;
        }

        .sidebar .nav-sub {
            padding-left: 24px;
        }

        .sidebar .nav-sub .nav-link {
            font-size: 13px;
            padding: 8px 14px;
            color: rgba(255, 255, 255, 0.55);
        }

        .sidebar .nav-sub .nav-link.active {
            background: rgba(79, 172, 254, 0.18);
            color: #fff;
            box-shadow: none;
        }

        .sidebar .nav-subgroup-label {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: rgba(255, 255, 255, 0.35);
            padding: 8px 14px 2px;
        }

        .sidebar .sidebar-footer {
            position: absolute;
            bottom: 0;
            width: 100%;
            padding: 20px 24px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(0, 0, 0, 0.2);
        }

        .sidebar .sidebar-footer .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar .sidebar-footer .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            color: #fff;
            flex-shrink: 0;
        }

        .sidebar .sidebar-footer .user-name {
            font-weight: 600;
            font-size: 14px;
        }

        .sidebar .sidebar-footer .user-role {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.4);
        }

        .sidebar .sidebar-footer .logout-btn {
            color: rgba(255, 255, 255, 0.4);
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            font-size: 14px;
        }

        .sidebar .sidebar-footer .logout-btn:hover {
            color: #ff6b6b;
        }

        .main-content {
            margin-left: 260px;
            padding: 24px 32px 40px;
            background: #f0f2f5;
            min-height: 100vh;
            width: calc(100% - 260px);
            max-width: 100%;
            overflow-x: hidden;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 0 24px 0;
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-bar .page-title h1 {
            font-size: 28px;
            font-weight: 800;
            color: #1a1a2e;
            margin: 0;
            letter-spacing: -0.5px;
        }

        .top-bar .page-title h1 i {
            color: #4facfe;
        }

        .top-bar .page-title p {
            color: #6c757d;
            margin: 0;
            font-size: 14px;
        }

        .top-bar .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .top-bar .header-actions .btn {
            border-radius: 12px;
            padding: 8px 20px;
            font-weight: 500;
            font-size: 13px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border: none;
            color: #fff;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 172, 254, 0.4);
            color: #fff;
        }

        .btn-outline-secondary {
            background: transparent;
            border: 2px solid #e9ecef;
            color: #6c757d;
        }

        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: #ced4da;
        }

        .btn-outline-primary {
            background: transparent;
            border: 2px solid #4facfe;
            color: #4facfe;
            border-radius: 10px;
            padding: 6px 14px;
            font-weight: 500;
            font-size: 12px;
        }

        .btn-outline-primary:hover {
            background: #4facfe;
            color: #fff;
        }

        .btn-outline-danger {
            background: transparent;
            border: 2px solid #dc3545;
            color: #dc3545;
            border-radius: 10px;
            padding: 6px 14px;
            font-weight: 500;
            font-size: 12px;
        }

        .btn-outline-danger:hover {
            background: #dc3545;
            color: #fff;
        }

        .btn-outline-warning {
            background: transparent;
            border: 2px solid #ffc107;
            color: #856404;
            border-radius: 10px;
            padding: 6px 14px;
            font-weight: 500;
            font-size: 12px;
        }

        .btn-outline-warning:hover {
            background: #ffc107;
            color: #1a1a2e;
        }

        .btn-outline-info {
            background: transparent;
            border: 2px solid #0891b2;
            color: #0e7490;
            border-radius: 10px;
            padding: 6px 14px;
            font-weight: 500;
            font-size: 12px;
        }

        .btn-outline-info:hover {
            background: #0891b2;
            color: #fff;
        }

        .btn-outline-success {
            background: transparent;
            border: 2px solid #16a34a;
            color: #166534;
            border-radius: 10px;
            padding: 6px 14px;
            font-weight: 500;
            font-size: 12px;
        }

        .btn-outline-success:hover {
            background: #16a34a;
            color: #fff;
        }

        .tenant-banner {
            background: #fff;
            border-radius: 14px;
            padding: 16px 24px;
            margin-bottom: 20px;
            border: 2px solid #4facfe;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            max-width: 1300px;
            margin-left: auto;
            margin-right: auto;
        }

        .tenant-banner .tenant-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .tenant-banner .tenant-info i {
            font-size: 24px;
            color: #4facfe;
        }

        .tenant-banner .tenant-info .tenant-name {
            font-weight: 600;
            font-size: 16px;
            color: #1a1a2e;
        }

        .tenant-banner .tenant-badge {
            background: #e3f0ff;
            color: #0d6efd;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .info-banner {
            background: linear-gradient(135deg, #eef6ff 0%, #e3f0ff 100%);
            border: 2px solid #4facfe;
            border-radius: 14px;
            padding: 16px 24px;
            margin-bottom: 24px;
            max-width: 1300px;
            margin-left: auto;
            margin-right: auto;
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }

        .info-banner .ib-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(79, 172, 254, 0.15);
            color: #0d6efd;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .info-banner .ib-body {
            flex: 1;
        }

        .info-banner .ib-title {
            font-weight: 700;
            font-size: 14px;
            color: #0d6efd;
            margin-bottom: 2px;
        }

        .info-banner .ib-text {
            font-size: 13px;
            color: #495057;
        }

        .filters-bar {
            background: #fff;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            max-width: 1300px;
            margin-left: auto;
            margin-right: auto;
        }

        .filters-bar .fg {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 150px;
            flex: 1;
        }

        .filters-bar .fg label {
            font-size: 12px;
            font-weight: 500;
            color: #1a1a2e;
        }

        .filters-bar .form-select,
        .filters-bar .form-control {
            height: 38px;
            font-size: 13px;
            border-radius: 8px;
            border: 1.5px solid #e9ecef;
        }

        .student-search-wrap {
            position: relative;
        }

        .student-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 1200;
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            max-height: 260px;
            overflow-y: auto;
            display: none;
            margin-top: 4px;
        }

        .student-search-results.open {
            display: block;
        }

        .student-search-results .ssr-item {
            padding: 8px 12px;
            cursor: pointer;
            font-size: 13px;
            border-bottom: 1px solid #f6f2f5;
        }

        .student-search-results .ssr-item:last-child {
            border-bottom: none;
        }

        .student-search-results .ssr-item:hover,
        .student-search-results .ssr-item.active {
            background: #eef6ff;
        }

        .student-search-results .ssr-item .ssr-name {
            font-weight: 600;
            color: #1a1a2e;
        }

        .student-search-results .ssr-item .ssr-num {
            font-size: 11px;
            color: #6c757d;
            font-family: 'Courier New', monospace;
        }

        .student-search-results .ssr-empty {
            padding: 12px;
            font-size: 12px;
            color: #6c757d;
            text-align: center;
        }

        .student-search-clear {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #adb5bd;
            cursor: pointer;
            border: none;
            background: transparent;
            font-size: 14px;
            padding: 0;
        }

        .student-search-clear:hover {
            color: #dc3545;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
            max-width: 1300px;
            margin-left: auto;
            margin-right: auto;
        }

        .summary-cell {
            background: #fff;
            border-radius: 12px;
            padding: 14px 18px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .summary-cell .s-lbl {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-cell .s-val {
            font-weight: 700;
            font-size: 20px;
            color: #1a1a2e;
            font-family: 'Courier New', monospace;
            margin-top: 2px;
        }

        .summary-cell.positive .s-val {
            color: #166534;
        }

        .summary-cell.negative .s-val {
            color: #991b1b;
        }

        .student-card {
            background: #fff;
            border-radius: 14px;
            padding: 18px 24px;
            margin-bottom: 20px;
            border: 2px solid #4facfe;
            display: flex;
            align-items: center;
            gap: 16px;
            max-width: 1300px;
            margin-left: auto;
            margin-right: auto;
        }

        .student-card .sc-avatar {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 20px;
            color: #fff;
            flex-shrink: 0;
        }

        .student-card .sc-info {
            flex: 1;
        }

        .student-card .sc-name {
            font-weight: 700;
            font-size: 18px;
            color: #1a1a2e;
        }

        .student-card .sc-meta {
            font-size: 12px;
            color: #6c757d;
            font-family: 'Courier New', monospace;
        }

        .tabs-nav {
            display: flex;
            gap: 4px;
            margin-bottom: 0;
            max-width: 1300px;
            margin-left: auto;
            margin-right: auto;
        }

        .tabs-nav .tab-btn {
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 600;
            border: none;
            background: transparent;
            color: #6c757d;
            border-radius: 10px 10px 0 0;
            cursor: pointer;
            text-decoration: none;
        }

        .tabs-nav .tab-btn:hover {
            color: #1a1a2e;
        }

        .tabs-nav .tab-btn.active {
            background: #fff;
            color: #4facfe;
            box-shadow: 0 -2px 8px rgba(0, 0, 0, 0.03);
        }

        .tab-panel {
            background: #fff;
            border-radius: 0 14px 14px 14px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            padding: 0;
            overflow: hidden;
            max-width: 1300px;
            margin-left: auto;
            margin-right: auto;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .pill.green {
            background: #d4edda;
            color: #155724;
        }

        .pill.gray {
            background: #e9ecef;
            color: #495057;
        }

        .pill.blue {
            background: #cce5ff;
            color: #004085;
        }

        .pill.purple {
            background: #e8d5f5;
            color: #6f42c1;
        }

        .pill.orange {
            background: #ffe8d9;
            color: #c2410c;
        }

        .pill.red {
            background: #f8d7da;
            color: #721c24;
        }

        .pill.teal {
            background: #d1f2eb;
            color: #0d5c4a;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .data-table thead th {
            background: #f8f9fa;
            padding: 10px 14px;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            border-bottom: 1px solid #e9ecef;
            text-align: left;
            white-space: nowrap;
        }

        .data-table tbody td {
            padding: 10px 14px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .data-table tbody tr:last-child td {
            border-bottom: none;
        }

        .data-table tbody tr:hover {
            background: #fafbfc;
        }

        .amount-cell {
            font-family: 'Courier New', monospace;
            font-weight: 700;
            color: #1a1a2e;
        }

        .amount-cell.negative {
            color: #991b1b;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 48px;
            opacity: 0.3;
            display: block;
            margin-bottom: 16px;
        }

        .empty-state h5 {
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 8px;
        }

        .alert-pro {
            border-radius: 14px;
            border: none;
            padding: 18px 20px 18px 22px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
            max-width: 1300px;
            margin-left: auto;
            margin-right: auto;
        }

        .alert-pro::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            width: 5px;
        }

        .alert-pro .alert-pro-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .alert-pro .alert-pro-content {
            flex: 1;
            padding-top: 2px;
        }

        .alert-pro .alert-pro-title {
            font-weight: 700;
            font-size: 15px;
            margin-bottom: 4px;
        }

        .alert-pro .alert-pro-list {
            margin: 0;
            padding-left: 20px;
            font-size: 13px;
            line-height: 1.7;
        }

        .alert-pro .alert-pro-close {
            background: transparent;
            border: none;
            color: inherit;
            opacity: 0.5;
            font-size: 18px;
            cursor: pointer;
            padding: 0;
            width: 28px;
            height: 28px;
            border-radius: 6px;
        }

        .alert-pro .alert-pro-close:hover {
            opacity: 1;
            background: rgba(0, 0, 0, 0.06);
        }

        .alert-pro.alert-pro-error {
            background: linear-gradient(135deg, #fff5f5 0%, #ffeaea 100%);
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .alert-pro.alert-pro-error::before {
            background: linear-gradient(180deg, #ef4444, #dc2626);
        }

        .alert-pro.alert-pro-error .alert-pro-icon {
            background: rgba(239, 68, 68, 0.12);
            color: #dc2626;
        }

        .alert-pro.alert-pro-success {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .alert-pro.alert-pro-success::before {
            background: linear-gradient(180deg, #22c55e, #16a34a);
        }

        .alert-pro.alert-pro-success .alert-pro-icon {
            background: rgba(34, 197, 94, 0.15);
            color: #16a34a;
        }

        .modal-content {
            border-radius: 16px;
            border: none;
        }

        .modal-header {
            border-bottom: 1px solid #f0f2f5;
            padding: 20px 24px;
        }

        .modal-header h5 {
            font-weight: 700;
            font-size: 17px;
            color: #1a1a2e;
            margin: 0;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            border-top: 1px solid #f0f2f5;
            padding: 16px 24px;
        }

        .form-label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            margin-bottom: 4px;
            display: block;
        }

        .form-control,
        .form-select {
            border-radius: 10px;
            padding: 8px 12px;
            border: 2px solid #e9ecef;
            font-size: 13px;
            width: 100%;
            display: block;
            background: #fff;
            color: #1a1a2e;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
            height: 40px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 4px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        textarea.form-control {
            height: auto;
            min-height: 80px;
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 72px;
                overflow: hidden;
            }

            .sidebar .sidebar-header h4 {
                font-size: 0;
            }

            .sidebar .sidebar-header h4 i {
                font-size: 24px;
            }

            .sidebar .sidebar-header small {
                display: none;
            }

            .sidebar .nav-link span {
                display: none;
            }

            .sidebar .nav-link i {
                margin-right: 0;
                font-size: 18px;
            }

            .sidebar .nav-link {
                text-align: center;
                padding: 12px;
                justify-content: center;
            }

            .sidebar .nav-label {
                display: none;
            }

            .sidebar .nav-sub {
                display: none;
            }

            .sidebar .sidebar-footer .user-info span {
                display: none;
            }

            .sidebar .sidebar-footer .user-info {
                justify-content: center;
            }

            .main-content {
                margin-left: 72px;
                width: calc(100% - 72px);
                padding: 20px;
            }

            .sidebar-toggle {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .sidebar-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
                width: 280px;
                position: fixed;
                z-index: 1000;
                top: 0;
                left: 0;
                height: 100vh;
                overflow-y: auto;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .sidebar .sidebar-header h4 {
                font-size: 20px;
            }

            .sidebar .sidebar-header small {
                display: block;
            }

            .sidebar .nav-link span {
                display: inline;
            }

            .sidebar .nav-link i {
                margin-right: 12px;
                font-size: 15px;
            }

            .sidebar .nav-link {
                text-align: left;
                padding: 10px 16px;
                justify-content: flex-start;
            }

            .sidebar .nav-label {
                display: block;
            }

            .sidebar .nav-sub {
                display: block;
            }

            .sidebar .sidebar-footer .user-info span {
                display: inline;
            }

            .sidebar .sidebar-footer .user-info {
                justify-content: flex-start;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 16px;
                padding-top: 70px;
            }

            .top-bar .page-title h1 {
                font-size: 22px;
            }

            .top-bar .page-title p {
                font-size: 12px;
            }

            .top-bar .header-actions .btn {
                font-size: 12px;
                padding: 6px 12px;
            }

            .filters-bar {
                flex-direction: column;
            }

            .filters-bar .fg {
                width: 100%;
            }

            .data-table {
                font-size: 12px;
            }

            .data-table thead th {
                padding: 8px 10px;
                font-size: 10px;
            }

            .data-table tbody td {
                padding: 8px 10px;
            }

            .tabs-nav {
                overflow-x: auto;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>

            <!-- Sidebar (includes app/views/partials/sidebar.php) -->
            <?php include $projectRoot . '/app/views/partials/sidebar.php'; ?>

            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-money-bill-wave me-2"></i>Financial Records</h1>
                        <p>Per-student fee ledger — charges, payments and balance</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/students/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Students
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo h($tenantName); ?></div>
                            <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                Currency: <strong><?php echo h($currency); ?></strong>
                                <?php if ($student): ?>
                                    · <?php echo count($charges); ?> charge(s) · <?php echo count($payments); ?> payment(s)
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i><?php echo h($isFinance || $isSuperAdmin ? 'Finance access' : 'Charges only'); ?>
                    </span>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert-pro alert-pro-error" id="serverErrorBox">
                        <div class="alert-pro-icon"><i class="fas fa-times-circle"></i></div>
                        <div class="alert-pro-content">
                            <div class="alert-pro-title">Could not complete the request</div>
                            <ul class="alert-pro-list">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo h($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <button type="button" class="alert-pro-close" onclick="document.getElementById('serverErrorBox').remove()" aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>

                <?php if ($successMessage): ?>
                    <div class="alert-pro alert-pro-success" id="serverSuccessBox">
                        <div class="alert-pro-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="alert-pro-content">
                            <div class="alert-pro-title">Success!</div>
                            <div style="font-size:13px;"><?php echo h($successMessage); ?></div>
                        </div>
                        <button type="button" class="alert-pro-close" onclick="document.getElementById('serverSuccessBox').remove()" aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>

                <div class="info-banner">
                    <div class="ib-icon"><i class="fas fa-info-circle"></i></div>
                    <div class="ib-body">
                        <div class="ib-title">How financial records work</div>
                        <div class="ib-text">
                            Pick a student with the search box below. Their ledger shows two tabs:
                            <strong>Charges</strong> (what the school billed) and <strong>Payments</strong>
                            (what the guardian paid). The balance is derived — it is never stored.
                            Any staff member may record a charge; only finance-flagged staff may record
                            or edit a payment.
                        </div>
                    </div>
                </div>

                <form method="GET" action="/platform/tenant/students/financial-records.php" class="filters-bar" id="finFilterForm">
                    <input type="hidden" name="student_id" id="filterStudentId" value="<?php echo (int)$studentId; ?>">
                    <input type="hidden" name="tab" value="<?php echo h($tab); ?>">

                    <div class="fg" style="flex:2;">
                        <label>Student</label>
                        <div class="student-search-wrap">
                            <input type="text" class="form-control" id="filterStudentSearch"
                                autocomplete="off" placeholder="Type name or student number…"
                                value="<?php echo $student ? h(trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')) . ' — ' . $student['student_number']) : ''; ?>">
                            <button type="button" class="student-search-clear" id="filterStudentClear"
                                title="Clear" style="<?php echo $student ? '' : 'display:none;'; ?>">
                                <i class="fas fa-times"></i>
                            </button>
                            <div class="student-search-results" id="filterStudentResults"></div>
                        </div>
                    </div>
                    <div class="fg" style="min-width:130px;">
                        <label><?php echo h($labelAcademic); ?></label>
                        <select name="year_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">All</option>
                            <?php foreach ($years as $y): ?>
                                <option value="<?php echo (int)$y['id']; ?>" <?php echo $filterYear === (int)$y['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($y['year_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg" style="min-width:130px;">
                        <label><?php echo h($labelTerm); ?></label>
                        <select name="term_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">All</option>
                            <?php foreach ($terms as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>" <?php echo $filterTerm === (int)$t['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($t['term_name']); ?><?php if ((int)$t['is_current'] === 1) echo ' ★'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg" style="flex:0 0 auto;">
                        <a href="/platform/tenant/students/financial-records.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                    </div>
                    <div class="fg" style="flex:0 0 auto;">
                        <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#categoryModal">
                            <i class="fas fa-plus me-1"></i> Add category
                        </button>
                    </div>
                    <?php if ($isFinance || $isSuperAdmin): ?>
                        <div class="fg" style="flex:0 0 auto;">
                            <form method="POST" action="/platform/tenant/students/financial-records.php" style="display:inline-block;"
                                onsubmit="return confirm('Send fee reminders to every guardian whose student has an outstanding balance?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="send_fee_reminders_bulk">
                                <input type="hidden" name="academic_term_id" value="<?php echo (int)$filterTerm; ?>">
                                <button type="submit" class="btn btn-outline-warning btn-sm">
                                    <i class="fas fa-paper-plane me-1"></i> Bulk reminders
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </form>

                <?php if (!$student): ?>
                    <div class="tab-panel">
                        <div class="empty-state">
                            <i class="fas fa-user-graduate"></i>
                            <h5>Pick a student</h5>
                            <p>Use the search box above to select the student whose ledger you want to view.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="student-card">
                        <div class="sc-avatar"><?php echo h(strtoupper(substr($student['first_name'] ?: 'S', 0, 1))); ?></div>
                        <div class="sc-info">
                            <div class="sc-name">
                                <?php echo h(trim(($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? '') . ' ' . ($student['last_name'] ?? ''))); ?>
                            </div>
                            <div class="sc-meta">
                                <?php echo h($student['student_number']); ?>
                                <?php if (!empty($student['class_name'])): ?>
                                    · <?php echo h($student['class_name']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="summary-grid">
                        <div class="summary-cell">
                            <div class="s-lbl">Total charged</div>
                            <div class="s-val"><?php echo h(money($totalCharged, $currency)); ?></div>
                        </div>
                        <div class="summary-cell positive">
                            <div class="s-lbl">Total paid</div>
                            <div class="s-val"><?php echo h(money($totalPaid, $currency)); ?></div>
                        </div>
                        <div class="summary-cell <?php echo $balance <= 0 ? 'positive' : 'negative'; ?>">
                            <div class="s-lbl">Balance</div>
                            <div class="s-val"><?php echo h(money($balance, $currency)); ?></div>
                        </div>
                        <?php if ($filterTerm > 0): ?>
                            <div class="summary-cell">
                                <div class="s-lbl">Term charged</div>
                                <div class="s-val"><?php echo h(money($termCharged, $currency)); ?></div>
                            </div>
                            <div class="summary-cell positive">
                                <div class="s-lbl">Term paid</div>
                                <div class="s-val"><?php echo h(money($termPaid, $currency)); ?></div>
                            </div>
                            <div class="summary-cell <?php echo $termBalance <= 0 ? 'positive' : 'negative'; ?>">
                                <div class="s-lbl">Term balance</div>
                                <div class="s-val"><?php echo h(money($termBalance, $currency)); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="tabs-nav">
                        <a href="/platform/tenant/students/financial-records.php?student_id=<?php echo (int)$studentId; ?>&year_id=<?php echo (int)$filterYear; ?>&term_id=<?php echo (int)$filterTerm; ?>&tab=charges"
                            class="tab-btn <?php echo $tab === 'charges' ? 'active' : ''; ?>">
                            <i class="fas fa-file-invoice-dollar me-1"></i> Charges (<?php echo count($charges); ?>)
                        </a>
                        <a href="/platform/tenant/students/financial-records.php?student_id=<?php echo (int)$studentId; ?>&year_id=<?php echo (int)$filterYear; ?>&term_id=<?php echo (int)$filterTerm; ?>&tab=payments"
                            class="tab-btn <?php echo $tab === 'payments' ? 'active' : ''; ?>">
                            <i class="fas fa-receipt me-1"></i> Payments (<?php echo count($payments); ?>)
                        </a>
                    </div>

                    <div class="tab-panel">
                        <?php if ($tab === 'charges'): ?>
                            <div style="padding: 16px 24px; border-bottom: 1px solid #f0f2f5; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                <h5 style="margin:0; font-weight:700; font-size:15px;">Fee charges</h5>
                                <div class="d-flex gap-2 flex-wrap">
                                    <?php if ($balance > 0 && ($isFinance || $isSuperAdmin)): ?>
                                        <form method="POST" action="/platform/tenant/students/financial-records.php" style="display:inline-block;"
                                            onsubmit="return confirm('Send a fee reminder to the guardian?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="send_fee_reminder">
                                            <input type="hidden" name="student_id" value="<?php echo (int)$studentId; ?>">
                                            <input type="hidden" name="academic_term_id" value="<?php echo (int)$filterTerm; ?>">
                                            <button type="submit" class="btn btn-outline-warning btn-sm">
                                                <i class="fas fa-bell me-1"></i> Send reminder
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <a href="/platform/tenant/students/financial-records.php?student_id=<?php echo (int)$studentId; ?>&action=new_charge&year_id=<?php echo (int)$filterYear; ?>&term_id=<?php echo (int)$filterTerm; ?>"
                                        class="btn btn-primary btn-sm">
                                        <i class="fas fa-plus me-1"></i> Add charge
                                    </a>
                                </div>
                            </div>
                            <?php if (empty($charges)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                    <h5>No charges</h5>
                                    <p>Add a charge to begin the ledger.</p>
                                </div>
                            <?php else: ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th style="width:120px;">Date</th>
                                            <th>Category</th>
                                            <th>Description</th>
                                            <th style="width:120px;">Year / Term</th>
                                            <th style="width:110px;">Due</th>
                                            <th style="text-align:right;width:130px;">Amount</th>
                                            <th style="text-align:right;min-width:140px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($charges as $c):
                                            $cid = (int)$c['id'];
                                        ?>
                                            <tr>
                                                <td style="font-family:'Courier New',monospace;font-size:12px;">
                                                    <?php echo h(substr((string)$c['created_at'], 0, 10)); ?>
                                                </td>
                                                <td>
                                                    <span class="pill purple"><?php echo h($c['category_name'] ?: '—'); ?></span>
                                                </td>
                                                <td><?php echo h($c['description'] ?: '—'); ?></td>
                                                <td style="font-size:12px;">
                                                    <?php echo h($c['year_name'] ?: '—'); ?>
                                                    <?php if (!empty($c['term_name'])): ?>
                                                        <div style="font-size:11px;color:#6c757d;"><?php echo h($c['term_name']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="font-size:12px;">
                                                    <?php echo $c['due_date'] ? h($c['due_date']) : '—'; ?>
                                                </td>
                                                <td style="text-align:right;" class="amount-cell">
                                                    <?php echo h(money($c['amount'], $currency)); ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex justify-content-end gap-1 flex-wrap">
                                                        <a href="/platform/tenant/students/financial-records.php?student_id=<?php echo (int)$studentId; ?>&tab=charges&action=edit_charge&id=<?php echo $cid; ?>"
                                                            class="btn btn-outline-primary btn-sm" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <form method="POST" style="display:inline-block;"
                                                            onsubmit="return confirm('Delete this charge? This soft-deletes it.');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete_charge">
                                                            <input type="hidden" name="id" value="<?php echo $cid; ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>

                        <?php else: /* Payments tab */ ?>
                            <div style="padding: 16px 24px; border-bottom: 1px solid #f0f2f5; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                <h5 style="margin:0; font-weight:700; font-size:15px;">Fee payments</h5>
                                <?php if ($isFinance || $isSuperAdmin): ?>
                                    <a href="/platform/tenant/students/financial-records.php?student_id=<?php echo (int)$studentId; ?>&action=new_payment&year_id=<?php echo (int)$filterYear; ?>&term_id=<?php echo (int)$filterTerm; ?>"
                                        class="btn btn-primary btn-sm">
                                        <i class="fas fa-plus me-1"></i> Record payment
                                    </a>
                                <?php else: ?>
                                    <span class="pill orange"><i class="fas fa-lock me-1"></i>Finance access required</span>
                                <?php endif; ?>
                            </div>
                            <?php if (empty($payments)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-receipt"></i>
                                    <h5>No payments</h5>
                                    <p>Record a payment to reduce the balance.</p>
                                </div>
                            <?php else: ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th style="width:110px;">Paid on</th>
                                            <th style="width:130px;">Method</th>
                                            <th style="width:130px;">Reference</th>
                                            <th style="width:130px;">Receipt #</th>
                                            <th style="width:120px;">Year / Term</th>
                                            <th>Notes</th>
                                            <th style="text-align:right;width:130px;">Amount</th>
                                            <th style="text-align:right;min-width:140px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payments as $p):
                                            $pid = (int)$p['id'];
                                        ?>
                                            <tr>
                                                <td style="font-family:'Courier New',monospace;font-size:12px;">
                                                    <?php echo h($p['paid_at']); ?>
                                                </td>
                                                <td>
                                                    <span class="pill <?php echo methodPill($p['method']); ?>">
                                                        <?php echo h(methodLabel($p['method'])); ?>
                                                    </span>
                                                </td>
                                                <td style="font-size:12px;"><?php echo h($p['reference'] ?: '—'); ?></td>
                                                <td style="font-size:12px;font-family:'Courier New',monospace;"><?php echo h($p['receipt_number'] ?: '—'); ?></td>
                                                <td style="font-size:12px;">
                                                    <?php echo h($p['year_name'] ?: '—'); ?>
                                                    <?php if (!empty($p['term_name'])): ?>
                                                        <div style="font-size:11px;color:#6c757d;"><?php echo h($p['term_name']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="font-size:12px;"><?php echo h($p['notes'] ?: '—'); ?></td>
                                                <td style="text-align:right;" class="amount-cell">
                                                    <?php echo h(money($p['amount'], $currency)); ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex justify-content-end gap-1 flex-wrap">
                                                        <?php if ($isFinance || $isSuperAdmin): ?>
                                                            <a href="/platform/tenant/students/financial-records.php?student_id=<?php echo (int)$studentId; ?>&tab=payments&action=edit_payment&id=<?php echo $pid; ?>"
                                                                class="btn btn-outline-primary btn-sm" title="Edit">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                            <form method="POST" style="display:inline-block;"
                                                                onsubmit="return confirm('Delete this payment? This soft-deletes it.');">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="delete_payment">
                                                                <input type="hidden" name="id" value="<?php echo $pid; ?>">
                                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </form>
                                                        <?php else: ?>
                                                            <span style="color:#adb5bd;font-size:12px;">View only</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <?php
    $showChargeModal = (($_GET['action'] ?? '') === 'new_charge') || (bool)$editCharge;
    $isEditCharge = (bool)$editCharge;
    if ($showChargeModal && $student):
    ?>
        <div class="modal fade show" id="chargeModal" tabindex="-1" style="display:block;background:rgba(0,0,0,0.4);">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" action="/platform/tenant/students/financial-records.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="<?php echo $isEditCharge ? 'update_charge' : 'create_charge'; ?>">
                        <input type="hidden" name="student_id" value="<?php echo (int)$studentId; ?>">
                        <?php if ($isEditCharge): ?>
                            <input type="hidden" name="id" value="<?php echo (int)$editCharge['id']; ?>">
                        <?php endif; ?>
                        <div class="modal-header">
                            <h5><i class="fas fa-file-invoice-dollar text-primary me-2"></i><?php echo $isEditCharge ? 'Edit Charge' : 'Add Charge'; ?></h5>
                            <a href="/platform/tenant/students/financial-records.php?student_id=<?php echo (int)$studentId; ?>&tab=charges" class="btn-close"></a>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="ch_category">Category <span class="text-danger">*</span></label>
                                    <select class="form-select" id="ch_category" name="category_id" required>
                                        <option value="">— Pick a category —</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo (int)$cat['id']; ?>"
                                                <?php echo ($isEditCharge && (int)$editCharge['category_id'] === (int)$cat['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($cat['category_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="ch_amount">Amount (<?php echo h($currency); ?>) <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" min="0.01" class="form-control" id="ch_amount" name="amount" required
                                        value="<?php echo $isEditCharge ? h($editCharge['amount']) : ''; ?>">
                                </div>
                                <?php if (!$isEditCharge): ?>
                                    <div class="col-md-6">
                                        <label class="form-label" for="ch_year"><?php echo h($labelAcademic); ?> <span class="text-danger">*</span></label>
                                        <select class="form-select" id="ch_year" name="academic_year_id" required>
                                            <?php foreach ($years as $y): ?>
                                                <option value="<?php echo (int)$y['id']; ?>" <?php echo $filterYear === (int)$y['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h($y['year_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="ch_term"><?php echo h($labelTerm); ?></label>
                                        <select class="form-select" id="ch_term" name="academic_term_id">
                                            <option value="0">(None)</option>
                                            <?php foreach ($terms as $t): ?>
                                                <option value="<?php echo (int)$t['id']; ?>" <?php echo $filterTerm === (int)$t['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h($t['term_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>
                                <div class="col-md-6">
                                    <label class="form-label" for="ch_due">Due date</label>
                                    <input type="date" class="form-control" id="ch_due" name="due_date"
                                        value="<?php echo $isEditCharge && $editCharge['due_date'] ? h($editCharge['due_date']) : ''; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="ch_desc">Description</label>
                                    <input type="text" class="form-control" id="ch_desc" name="description" maxlength="255"
                                        placeholder="e.g. First instalment"
                                        value="<?php echo $isEditCharge ? h($editCharge['description']) : ''; ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="ch_notes">Notes</label>
                                    <textarea class="form-control" id="ch_notes" name="notes"><?php echo $isEditCharge ? h($editCharge['notes']) : ''; ?></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <a href="/platform/tenant/students/financial-records.php?student_id=<?php echo (int)$studentId; ?>&tab=charges" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i> <?php echo $isEditCharge ? 'Save Changes' : 'Add Charge'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php
    $showPaymentModal = (($_GET['action'] ?? '') === 'new_payment') || (bool)$editPayment;
    $isEditPayment = (bool)$editPayment;
    if ($showPaymentModal && $student && ($isFinance || $isSuperAdmin)):
    ?>
        <div class="modal fade show" id="paymentModal" tabindex="-1" style="display:block;background:rgba(0,0,0,0.4);">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" action="/platform/tenant/students/financial-records.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="<?php echo $isEditPayment ? 'update_payment' : 'create_payment'; ?>">
                        <input type="hidden" name="student_id" value="<?php echo (int)$studentId; ?>">
                        <?php if ($isEditPayment): ?>
                            <input type="hidden" name="id" value="<?php echo (int)$editPayment['id']; ?>">
                        <?php endif; ?>
                        <div class="modal-header">
                            <h5><i class="fas fa-receipt text-primary me-2"></i><?php echo $isEditPayment ? 'Edit Payment' : 'Record Payment'; ?></h5>
                            <a href="/platform/tenant/students/financial-records.php?student_id=<?php echo (int)$studentId; ?>&tab=payments" class="btn-close"></a>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="p_amount">Amount (<?php echo h($currency); ?>) <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" min="0.01" class="form-control" id="p_amount" name="amount" required
                                        value="<?php echo $isEditPayment ? h($editPayment['amount']) : ''; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="p_date">Paid on <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="p_date" name="paid_at" required
                                        value="<?php echo $isEditPayment ? h($editPayment['paid_at']) : h(date('Y-m-d')); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="p_method">Method <span class="text-danger">*</span></label>
                                    <select class="form-select" id="p_method" name="method" required>
                                        <?php
                                        $methods = ['cash', 'bank_transfer', 'cheque', 'mobile_money', 'other'];
                                        $currentMethod = $isEditPayment ? $editPayment['method'] : 'cash';
                                        foreach ($methods as $m):
                                        ?>
                                            <option value="<?php echo h($m); ?>" <?php echo $currentMethod === $m ? 'selected' : ''; ?>>
                                                <?php echo h(methodLabel($m)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="p_receipt">Receipt number</label>
                                    <input type="text" class="form-control" id="p_receipt" name="receipt_number" maxlength="50"
                                        value="<?php echo $isEditPayment ? h($editPayment['receipt_number']) : ''; ?>">
                                </div>
                                <?php if (!$isEditPayment): ?>
                                    <div class="col-md-6">
                                        <label class="form-label" for="p_year"><?php echo h($labelAcademic); ?></label>
                                        <select class="form-select" id="p_year" name="academic_year_id">
                                            <option value="0">(None)</option>
                                            <?php foreach ($years as $y): ?>
                                                <option value="<?php echo (int)$y['id']; ?>" <?php echo $filterYear === (int)$y['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h($y['year_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="p_term"><?php echo h($labelTerm); ?></label>
                                        <select class="form-select" id="p_term" name="academic_term_id">
                                            <option value="0">(None)</option>
                                            <?php foreach ($terms as $t): ?>
                                                <option value="<?php echo (int)$t['id']; ?>" <?php echo $filterTerm === (int)$t['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h($t['term_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>
                                <div class="col-12">
                                    <label class="form-label" for="p_ref">Reference</label>
                                    <input type="text" class="form-control" id="p_ref" name="reference" maxlength="100"
                                        placeholder="e.g. bank ref, MoMo transaction id"
                                        value="<?php echo $isEditPayment ? h($editPayment['reference']) : ''; ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="p_notes">Notes</label>
                                    <textarea class="form-control" id="p_notes" name="notes"><?php echo $isEditPayment ? h($editPayment['notes']) : ''; ?></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <a href="/platform/tenant/students/financial-records.php?student_id=<?php echo (int)$studentId; ?>&tab=payments" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i> <?php echo $isEditPayment ? 'Save Changes' : 'Record Payment'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="modal fade" id="categoryModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/students/financial-records.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_category">
                    <input type="hidden" name="back_student_id" value="<?php echo (int)$studentId; ?>">
                    <div class="modal-header">
                        <h5><i class="fas fa-plus-circle text-primary me-2"></i>Add Fee Category</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="cat_name">Category name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="cat_name" name="category_name" maxlength="100" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="cat_code">Short code</label>
                            <input type="text" class="form-control" id="cat_code" name="category_code" maxlength="30">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="cat_desc">Description</label>
                            <textarea class="form-control" id="cat_desc" name="description"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Add Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/notifications-bell.js" defer></script>
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.getElementById('sidebarToggle');
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) document.getElementById('sidebar').classList.remove('open');
        });

        function logout() {
            if (confirm('Are you sure you want to logout?')) window.location.href = '/platform/logout.php';
        }

        function escapeHtml(s) {
            if (s === null || s === undefined) return '';
            return String(s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function makeStudentSearch(opts) {
            const input = document.getElementById(opts.inputId);
            const resultsEl = document.getElementById(opts.resultsId);
            const hiddenInput = opts.hiddenId ? document.getElementById(opts.hiddenId) : null;
            if (!input || !resultsEl) return null;

            let lastQuery = '',
                lastItems = [],
                activeIdx = -1,
                debounce = null,
                abortCtrl = null,
                reqToken = 0;

            function close() {
                resultsEl.classList.remove('open');
                resultsEl.innerHTML = '';
                activeIdx = -1;
            }

            function open(items) {
                lastItems = items;
                if (!items.length) {
                    resultsEl.innerHTML = '<div class="ssr-empty">No students found</div>';
                    resultsEl.classList.add('open');
                    activeIdx = -1;
                    return;
                }
                resultsEl.innerHTML = items.map((it, i) =>
                    `<div class="ssr-item" data-idx="${i}" data-id="${it.id}" data-label="${escapeHtml(it.label)}">
                        <div class="ssr-name">${escapeHtml(it.name)}</div>
                        <div class="ssr-num">${escapeHtml(it.num)}</div>
                    </div>`
                ).join('');
                resultsEl.classList.add('open');
                activeIdx = -1;
            }

            function highlight() {
                resultsEl.querySelectorAll('.ssr-item').forEach(el => el.classList.remove('active'));
                if (activeIdx >= 0) {
                    const el = resultsEl.querySelector(`.ssr-item[data-idx="${activeIdx}"]`);
                    if (el) {
                        el.classList.add('active');
                        el.scrollIntoView({
                            block: 'nearest'
                        });
                    }
                }
            }

            function pick(item) {
                input.value = item.label;
                if (hiddenInput) hiddenInput.value = item.id;
                close();
                if (typeof opts.onPick === 'function') opts.onPick(item);
            }

            function fetchResults(q) {
                if (q === lastQuery) return;
                lastQuery = q;
                if (abortCtrl) abortCtrl.abort();
                abortCtrl = new AbortController();
                const myToken = ++reqToken;
                fetch('/platform/tenant/students/financial-records.php?ajax_search_students=1&q=' + encodeURIComponent(q), {
                        signal: abortCtrl.signal,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (myToken !== reqToken) return;
                        if (!data || !data.ok) {
                            close();
                            return;
                        }
                        open(data.items || []);
                    })
                    .catch(err => {
                        if (err && err.name === 'AbortError') return;
                        close();
                    });
            }
            input.addEventListener('input', function() {
                const q = this.value.trim();
                if (hiddenInput) hiddenInput.value = '';
                if (debounce) clearTimeout(debounce);
                if (q.length < 2) {
                    close();
                    return;
                }
                debounce = setTimeout(() => fetchResults(q), 250);
            });
            input.addEventListener('keydown', function(e) {
                if (!resultsEl.classList.contains('open')) return;
                const count = lastItems.length;
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIdx = (activeIdx + 1) % count;
                    highlight();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIdx = (activeIdx - 1 + count) % count;
                    highlight();
                } else if (e.key === 'Enter') {
                    if (activeIdx >= 0 && lastItems[activeIdx]) {
                        e.preventDefault();
                        pick(lastItems[activeIdx]);
                    }
                } else if (e.key === 'Escape') {
                    close();
                }
            });
            resultsEl.addEventListener('mousedown', function(e) {
                const item = e.target.closest('.ssr-item');
                if (!item) return;
                e.preventDefault();
                const idx = parseInt(item.getAttribute('data-idx'), 10);
                if (lastItems[idx]) pick(lastItems[idx]);
            });
            document.addEventListener('click', function(e) {
                if (!input.contains(e.target) && !resultsEl.contains(e.target)) close();
            });
            return {
                setValue(label, id) {
                    input.value = label || '';
                    if (hiddenInput) hiddenInput.value = id || '';
                }
            };
        }

        (function() {
            const form = document.getElementById('finFilterForm');
            const hiddenId = document.getElementById('filterStudentId');
            const clearBtn = document.getElementById('filterStudentClear');
            if (!form || !hiddenId) return;
            makeStudentSearch({
                inputId: 'filterStudentSearch',
                resultsId: 'filterStudentResults',
                onPick: function(item) {
                    hiddenId.value = item.id;
                    form.submit();
                }
            });
            if (clearBtn) {
                clearBtn.addEventListener('click', function() {
                    document.getElementById('filterStudentSearch').value = '';
                    hiddenId.value = '0';
                    form.submit();
                });
            }
        })();

        const successBox = document.getElementById('serverSuccessBox');
        if (successBox) {
            setTimeout(() => {
                successBox.style.transition = 'opacity 0.3s ease';
                successBox.style.opacity = '0';
                setTimeout(() => successBox.remove(), 300);
            }, 6000);
        }

        function loadUserInfo() {
            const userStr = localStorage.getItem('user');
            if (userStr) {
                try {
                    const user = JSON.parse(userStr);
                    document.getElementById('userName').textContent = user.first_name || 'Admin';
                    document.getElementById('userAvatar').textContent = (user.first_name || 'A').charAt(0);
                    document.getElementById('userRole').textContent = (user.roles || ['Administrator'])[0];
                } catch (e) {}
            }
        }
        document.addEventListener('DOMContentLoaded', loadUserInfo);
    </script>
</body>

</html>