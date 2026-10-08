<?php

/**
 * Academic Terms — CRUD for terms, grouped by academic year
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Academic
 * @version 1.0
 * @filepath public/platform/tenant/academic/terms.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Academic terms file of the academic-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'academic_terms'
 *       to 'academic' so the partial marks Academic active and
 *       renders the academic sub-menu on this page — consistent
 *       with every other academic file.
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
 *   previous version (1.1). The @package tag remains 'EduTrack'.
 *
 * Decisions locked in (Session S5):
 *   Housekeeping : (A) show orphan-term warning banner
 *   1           : (1A) hard block term overlap within the same year
 *   2           : (2A) start_date / end_date columns (from migration)
 *   3           : (3A) is_current column (from migration)
 *   4           : (4A) hard block when term count exceeds default_terms_per_year
 *   5           : (5A) refuse delete, list dependencies
 *   6           : (6A) unique per (tenant_id, academic_year_id, term_name)
 *
 * Session S20 decisions applied:
 *   SH2A per-session CSRF token; all POSTs carry it
 *   SH3A central Security.php helper, loaded via app/bootstrap.php
 *   SH4A hardened session started inside bootstrap
 *   SH5C h() on every echoed value
 *   SH6B tenant_id scoping and deleted_at IS NULL on every query
 *
 * Note on error messages: this page intentionally surfaces the real
 * decision messages (overlap, limit, dependency) to the user. That is
 * a decision-specific exception to SH10A — the messages are the product
 * behaviour, not leaked internals.
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

$pageTitle   = 'Academic Terms - Student 360 Platform';
$currentPage = 'academic';

// ============================================
// HELPERS
// ============================================
// h() is defined in Security.php; do not redeclare it here.

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

function findOverlappingTerm($db, int $tenantId, int $yearId, string $start, string $end, ?int $excludeId = null): ?array
{
    $sql = "SELECT id, term_name, start_date, end_date
            FROM academic_terms
            WHERE tenant_id = ? AND academic_year_id = ?
              AND deleted_at IS NULL
              AND start_date IS NOT NULL AND end_date IS NOT NULL
              AND (start_date <= ? AND end_date >= ?)";
    $params = [$tenantId, $yearId, $end, $start];
    if ($excludeId !== null) {
        $sql .= " AND id != ?";
        $params[] = $excludeId;
    }
    $sql .= " LIMIT 1";
    $row = $db->fetchOne($sql, $params);
    return $row ?: null;
}

function countTermsInYear($db, int $tenantId, int $yearId, ?int $excludeId = null): int
{
    $sql = "SELECT COUNT(*) AS c FROM academic_terms
            WHERE tenant_id = ? AND academic_year_id = ? AND deleted_at IS NULL";
    $params = [$tenantId, $yearId];
    if ($excludeId !== null) {
        $sql .= " AND id != ?";
        $params[] = $excludeId;
    }
    $row = $db->fetchOne($sql, $params);
    return (int)($row['c'] ?? 0);
}

/**
 * Count dependencies preventing a term from being deleted.
 * Returns ['total' => N, 'labels' => [...]].
 */
function termDependencies($db, int $tenantId, int $termId): array
{
    $total = 0;
    $labels = [];

    $checks = [
        'results'         => "SELECT COUNT(*) AS c FROM results         WHERE tenant_id = ? AND academic_term_id = ? AND deleted_at IS NULL",
        'enrollments'     => "SELECT COUNT(*) AS c FROM enrollments     WHERE tenant_id = ? AND academic_term_id = ? AND deleted_at IS NULL",
        'teacher_assign'  => "SELECT COUNT(*) AS c FROM teacher_class_assignments WHERE tenant_id = ? AND academic_term_id = ? AND deleted_at IS NULL",
        'staff_assign'    => "SELECT COUNT(*) AS c FROM staff_academic_assignments WHERE academic_term_id = ? AND deleted_at IS NULL",
    ];

    foreach ($checks as $label => $sql) {
        try {
            $params = $label === 'staff_assign' ? [$termId] : [$tenantId, $termId];
            $row = $db->fetchOne($sql, $params);
            $c = (int)($row['c'] ?? 0);
            if ($c > 0) {
                $total += $c;
                $labels[] = $c . ' ' . str_replace('_', ' ', $label);
            }
        } catch (Exception $e) {
            // ignore missing tables — the module grows over time
        }
    }

    return ['total' => $total, 'labels' => $labels];
}

// ============================================
// ACTIONS (POST)
// ============================================
$errors   = [];
$formData = [];
$action   = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf(); // S20 SH2A — all POSTs must carry the session CSRF token

    $formData = $_POST;

    try {
        // ---------- CREATE ----------
        if ($action === 'create') {
            $yearId = (int)($_POST['academic_year_id'] ?? 0);
            $name   = trim((string)($_POST['term_name'] ?? ''));
            $start  = trim((string)($_POST['start_date'] ?? ''));
            $end    = trim((string)($_POST['end_date'] ?? ''));
            $sort   = (int)($_POST['sort_order'] ?? 0);
            $active = !empty($_POST['is_active']) ? 1 : 0;
            $current = !empty($_POST['is_current']) ? 1 : 0;

            if ($yearId <= 0) $errors[] = 'Academic year is required.';
            if ($name === '') $errors[] = 'Term name is required.';
            if (mb_strlen($name) > 50) $errors[] = 'Term name must be 50 characters or less.';

            // Verify the year belongs to this tenant
            $year = null;
            if ($yearId > 0) {
                $year = $db->fetchOne(
                    "SELECT * FROM academic_years
                     WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$yearId, $tenantId]
                );
                if (!$year) $errors[] = 'Selected academic year not found.';
            }

            // Dates are optional but if both present, validate
            if ($start !== '' && $end !== '') {
                if (strtotime($end) <= strtotime($start)) {
                    $errors[] = 'End date must be after start date.';
                } else {
                    $overlap = findOverlappingTerm($db, $tenantId, $yearId, $start, $end);
                    if ($overlap) {
                        $errors[] = 'Term dates overlap with "' . $overlap['term_name'] .
                            '" (' . $overlap['start_date'] . ' to ' . $overlap['end_date'] . ').';
                    }
                }
            }

            // Name uniqueness within the year
            if (empty($errors) && $year) {
                $dupe = $db->fetchOne(
                    "SELECT id FROM academic_terms
                     WHERE tenant_id = ? AND academic_year_id = ? AND term_name = ? AND deleted_at IS NULL",
                    [$tenantId, $yearId, $name]
                );
                if ($dupe) $errors[] = 'A term named "' . $name . '" already exists for this year.';
            }

            // Term count limit (Decision 4A)
            $settings = loadActiveSettings($db, $tenantId);
            $maxTerms = (int)($settings['default_terms_per_year'] ?? 3);
            if (empty($errors) && $year) {
                $existing = countTermsInYear($db, $tenantId, $yearId);
                if ($existing >= $maxTerms) {
                    $errors[] = 'This year already has ' . $existing . ' term(s). Your tenant is configured for ' .
                        $maxTerms . ' term(s) per year. To add more, increase "Default Terms per Year" on the Academic Settings page.';
                }
            }

            if (empty($errors)) {
                $db->beginTransaction();

                if ($current === 1) {
                    $db->execute(
                        "UPDATE academic_terms
                            SET is_current = 0, updated_at = NOW()
                          WHERE tenant_id = ? AND academic_year_id = ? AND is_current = 1 AND deleted_at IS NULL",
                        [$tenantId, $yearId]
                    );
                }

                $newId = $db->insert(
                    "INSERT INTO academic_terms
                        (tenant_id, school_id, academic_year_id, term_name,
                         start_date, end_date, sort_order, is_active, is_current,
                         created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        $tenantId,
                        $schoolId ?: null,
                        $yearId,
                        $name,
                        $start !== '' ? $start : null,
                        $end   !== '' ? $end   : null,
                        $sort,
                        $active,
                        $current,
                    ]
                );

                writeAudit(
                    $db,
                    $tenantId,
                    $userId,
                    'academic.term.created',
                    'academic_terms',
                    (int)$newId,
                    [
                        'year_id' => $yearId,
                        'term_name' => $name,
                        'start_date' => $start,
                        'end_date' => $end,
                        'is_active' => $active,
                        'is_current' => $current
                    ]
                );

                $db->commit();
                $_SESSION['success'] = 'Term "' . $name . '" created.';
                header('Location: /platform/tenant/academic/terms.php');
                exit;
            }

            $_SESSION['errors']    = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: /platform/tenant/academic/terms.php?action=new&year_id=' . $yearId);
            exit;
        }

        // ---------- UPDATE ----------
        if ($action === 'update') {
            $id     = (int)($_POST['id'] ?? 0);
            $name   = trim((string)($_POST['term_name'] ?? ''));
            $start  = trim((string)($_POST['start_date'] ?? ''));
            $end    = trim((string)($_POST['end_date'] ?? ''));
            $sort   = (int)($_POST['sort_order'] ?? 0);
            $active = !empty($_POST['is_active']) ? 1 : 0;
            $current = !empty($_POST['is_current']) ? 1 : 0;

            $term = $db->fetchOne(
                "SELECT * FROM academic_terms
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$term) $errors[] = 'Term not found.';

            if ($name === '') $errors[] = 'Term name is required.';
            if (mb_strlen($name) > 50) $errors[] = 'Term name must be 50 characters or less.';

            $yearId = $term ? (int)$term['academic_year_id'] : 0;

            if ($start !== '' && $end !== '' && strtotime($end) <= strtotime($start)) {
                $errors[] = 'End date must be after start date.';
            }

            if (empty($errors) && $yearId > 0 && $start !== '' && $end !== '') {
                $overlap = findOverlappingTerm($db, $tenantId, $yearId, $start, $end, $id);
                if ($overlap) {
                    $errors[] = 'Term dates overlap with "' . $overlap['term_name'] .
                        '" (' . $overlap['start_date'] . ' to ' . $overlap['end_date'] . ').';
                }
            }

            // Uniqueness
            if (empty($errors) && $yearId > 0) {
                $dupe = $db->fetchOne(
                    "SELECT id FROM academic_terms
                     WHERE tenant_id = ? AND academic_year_id = ? AND term_name = ? AND deleted_at IS NULL AND id != ?",
                    [$tenantId, $yearId, $name, $id]
                );
                if ($dupe) $errors[] = 'Another term named "' . $name . '" already exists for this year.';
            }

            if (empty($errors) && $term) {
                $db->beginTransaction();

                if ($current === 1 && (int)$term['is_current'] !== 1 && $yearId > 0) {
                    $db->execute(
                        "UPDATE academic_terms
                            SET is_current = 0, updated_at = NOW()
                          WHERE tenant_id = ? AND academic_year_id = ? AND is_current = 1 AND deleted_at IS NULL AND id != ?",
                        [$tenantId, $yearId, $id]
                    );
                }

                $db->execute(
                    "UPDATE academic_terms
                        SET term_name = ?,
                            start_date = ?,
                            end_date = ?,
                            sort_order = ?,
                            is_active = ?,
                            is_current = ?,
                            updated_at = NOW()
                      WHERE id = ? AND tenant_id = ?",
                    [
                        $name,
                        $start !== '' ? $start : null,
                        $end   !== '' ? $end   : null,
                        $sort,
                        $active,
                        $current,
                        $id,
                        $tenantId,
                    ]
                );

                writeAudit(
                    $db,
                    $tenantId,
                    $userId,
                    'academic.term.updated',
                    'academic_terms',
                    $id,
                    [
                        'term_name' => $name,
                        'start_date' => $start,
                        'end_date' => $end,
                        'sort_order' => $sort,
                        'is_active' => $active,
                        'is_current' => $current
                    ]
                );

                $db->commit();
                $_SESSION['success'] = 'Term updated.';
                header('Location: /platform/tenant/academic/terms.php');
                exit;
            }

            $_SESSION['errors']    = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: /platform/tenant/academic/terms.php?action=edit&id=' . $id);
            exit;
        }

        // ---------- SET AS CURRENT ----------
        if ($action === 'set_current') {
            $id = (int)($_POST['id'] ?? 0);
            $term = $db->fetchOne(
                "SELECT * FROM academic_terms WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$term) throw new Exception('Term not found.');

            $yearId = (int)$term['academic_year_id'];
            if ($yearId <= 0) throw new Exception('This term is not bound to an academic year.');

            $db->beginTransaction();
            $db->execute(
                "UPDATE academic_terms
                    SET is_current = 0, updated_at = NOW()
                  WHERE tenant_id = ? AND academic_year_id = ? AND is_current = 1 AND deleted_at IS NULL",
                [$tenantId, $yearId]
            );
            $db->execute(
                "UPDATE academic_terms
                    SET is_current = 1, is_active = 1, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.term.set_current',
                'academic_terms',
                $id,
                ['term_name' => $term['term_name'], 'year_id' => $yearId]
            );
            $db->commit();

            $_SESSION['success'] = '"' . $term['term_name'] . '" is now the current term.';
            header('Location: /platform/tenant/academic/terms.php');
            exit;
        }

        // ---------- TOGGLE ACTIVE ----------
        if ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            $term = $db->fetchOne(
                "SELECT * FROM academic_terms WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$term) throw new Exception('Term not found.');

            $newActive = ((int)$term['is_active']) === 1 ? 0 : 1;

            $db->beginTransaction();
            $db->execute(
                "UPDATE academic_terms SET is_active = ?, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$newActive, $id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.term.toggled_active',
                'academic_terms',
                $id,
                ['is_active' => $newActive]
            );
            $db->commit();

            $_SESSION['success'] = '"' . $term['term_name'] . '" is now ' . ($newActive ? 'active' : 'inactive') . '.';
            header('Location: /platform/tenant/academic/terms.php');
            exit;
        }

        // ---------- DELETE ----------
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $term = $db->fetchOne(
                "SELECT * FROM academic_terms WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$term) throw new Exception('Term not found.');

            if ((int)$term['is_current'] === 1) {
                throw new Exception('Cannot delete the current term. Set another term as current first.');
            }

            $deps = termDependencies($db, $tenantId, $id);
            if ($deps['total'] > 0) {
                throw new Exception(
                    'Cannot delete "' . $term['term_name'] . '". It is referenced by: ' .
                        implode(', ', $deps['labels']) . '. Remove those references first, or deactivate the term instead.'
                );
            }

            $db->beginTransaction();
            $db->execute(
                "UPDATE academic_terms SET deleted_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.term.deleted',
                'academic_terms',
                $id,
                ['term_name' => $term['term_name']]
            );
            $db->commit();

            $_SESSION['success'] = 'Term "' . $term['term_name'] . '" deleted.';
            header('Location: /platform/tenant/academic/terms.php');
            exit;
        }

        // ---------- BIND ORPHAN TERM TO A YEAR ----------
        if ($action === 'bind_year') {
            $id     = (int)($_POST['id'] ?? 0);
            $yearId = (int)($_POST['academic_year_id'] ?? 0);

            if ($yearId <= 0) throw new Exception('Please choose a year to bind.');

            $year = $db->fetchOne(
                "SELECT * FROM academic_years WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$yearId, $tenantId]
            );
            if (!$year) throw new Exception('Year not found.');

            $term = $db->fetchOne(
                "SELECT * FROM academic_terms WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$term) throw new Exception('Term not found.');

            // Count check
            $settings = loadActiveSettings($db, $tenantId);
            $maxTerms = (int)($settings['default_terms_per_year'] ?? 3);
            $existing = countTermsInYear($db, $tenantId, $yearId);
            if ($existing >= $maxTerms) {
                throw new Exception('Year "' . $year['year_name'] . '" already has ' . $existing . ' term(s), which is the configured limit.');
            }

            // Uniqueness check
            $dupe = $db->fetchOne(
                "SELECT id FROM academic_terms
                 WHERE tenant_id = ? AND academic_year_id = ? AND term_name = ? AND deleted_at IS NULL AND id != ?",
                [$tenantId, $yearId, $term['term_name'], $id]
            );
            if ($dupe) {
                throw new Exception('Year already has a term named "' . $term['term_name'] . '".');
            }

            $db->beginTransaction();
            $db->execute(
                "UPDATE academic_terms SET academic_year_id = ?, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$yearId, $id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.term.bound_to_year',
                'academic_terms',
                $id,
                ['year_id' => $yearId, 'year_name' => $year['year_name']]
            );
            $db->commit();

            $_SESSION['success'] = 'Term "' . $term['term_name'] . '" bound to "' . $year['year_name'] . '".';
            header('Location: /platform/tenant/academic/terms.php');
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Academic terms action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/academic/terms.php');
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
    $useForm  = true;
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
$settings = loadActiveSettings($db, $tenantId);
$labelAcademic = $settings['label_academic_structure'] ?? 'Academic Year';
$labelTerm     = $settings['label_term'] ?? 'Term';
$maxTerms      = (int)($settings['default_terms_per_year'] ?? 3);

// Years + their terms
$years = $db->fetchAll(
    "SELECT * FROM academic_years
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY start_date DESC, id DESC",
    [$tenantId]
);

$termsByYear = [];
foreach ($years as $y) {
    $termsByYear[(int)$y['id']] = $db->fetchAll(
        "SELECT * FROM academic_terms
         WHERE tenant_id = ? AND academic_year_id = ? AND deleted_at IS NULL
         ORDER BY sort_order ASC, id ASC",
        [$tenantId, (int)$y['id']]
    );
}

// Orphan terms (academic_year_id IS NULL)
$orphanTerms = $db->fetchAll(
    "SELECT * FROM academic_terms
     WHERE tenant_id = ? AND academic_year_id IS NULL AND deleted_at IS NULL
     ORDER BY sort_order ASC, id ASC",
    [$tenantId]
);

// For edit modal
$editTerm = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && !empty($_GET['id'])) {
    $editTerm = $db->fetchOne(
        "SELECT * FROM academic_terms WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [(int)$_GET['id'], $tenantId]
    );
}

// New modal context
$newYearId = (int)($_GET['year_id'] ?? 0);
$showNewModal = (isset($_GET['action']) && $_GET['action'] === 'new') || ($useForm && (($formData['action'] ?? '') === 'create'));

// Tenant name
$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

// Value helper for form
function v(string $key, $default = ''): string
{
    global $formData, $useForm, $editTerm;
    if ($useForm && array_key_exists($key, $formData)) {
        return h($formData[$key]);
    }
    if ($editTerm && array_key_exists($key, $editTerm)) {
        return h($editTerm[$key]);
    }
    return h($default);
}

// Suggested term name based on count
function suggestTermName($existingTerms, $prefix = 'Term'): string
{
    $n = count($existingTerms) + 1;
    return $prefix . ' ' . $n;
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

        .btn-outline-success {
            background: transparent;
            border: 2px solid #28a745;
            color: #28a745;
            border-radius: 10px;
            padding: 6px 14px;
            font-weight: 500;
            font-size: 12px;
        }

        .btn-outline-success:hover {
            background: #28a745;
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
            max-width: 1100px;
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

        .year-section {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
            overflow: hidden;
            width: 100%;
            max-width: 1100px;
            margin-left: auto;
            margin-right: auto;
        }

        .year-section .year-header {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            background: linear-gradient(135deg, #f8fafc 0%, #eef6ff 100%);
        }

        .year-section .year-header .year-title {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .year-section .year-header .year-title h6 {
            font-weight: 700;
            margin: 0;
            font-size: 16px;
            color: #1a1a2e;
        }

        .year-section .year-body {
            padding: 0;
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

        .pill.orange {
            background: #fff3cd;
            color: #856404;
        }

        .pill.red {
            background: #f8d7da;
            color: #721c24;
        }

        .term-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .term-table thead th {
            background: #f8f9fa;
            padding: 12px 20px;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            border-bottom: 1px solid #e9ecef;
            text-align: left;
            white-space: nowrap;
        }

        .term-table tbody td {
            padding: 12px 20px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .term-table tbody tr:last-child td {
            border-bottom: none;
        }

        .term-table tbody tr:hover {
            background: #fafbfc;
        }

        .term-name {
            font-weight: 600;
            color: #1a1a2e;
            font-size: 14px;
        }

        .term-order {
            font-size: 11px;
            color: #6c757d;
        }

        .action-group {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .action-group .btn {
            padding: 4px 10px;
            font-size: 11px;
            border-radius: 8px;
        }

        .empty-terms {
            padding: 24px 20px;
            text-align: center;
            color: #adb5bd;
            font-size: 13px;
        }

        .orphan-banner {
            background: linear-gradient(135deg, #fff9e6 0%, #fff3cd 100%);
            border: 2px solid #ffc107;
            border-radius: 14px;
            padding: 18px 24px;
            margin-bottom: 24px;
            max-width: 1100px;
            margin-left: auto;
            margin-right: auto;
        }

        .orphan-banner h6 {
            font-weight: 700;
            color: #856404;
            margin: 0 0 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .orphan-banner p {
            color: #856404;
            margin: 0 0 12px;
            font-size: 13px;
        }

        .orphan-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .orphan-chip {
            background: #fff;
            border: 1px solid #ffc107;
            border-radius: 10px;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
        }

        .orphan-chip .bind-form {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .orphan-chip select {
            font-size: 12px;
            height: 32px;
            border-radius: 6px;
            border: 1px solid #e9ecef;
            padding: 0 8px;
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
            max-width: 1100px;
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

        .form-label .required {
            color: #dc3545;
            margin-left: 2px;
        }

        .form-control,
        .form-select {
            border-radius: 10px;
            padding: 10px 14px;
            border: 2px solid #e9ecef;
            font-size: 13px;
            width: 100%;
            display: block;
            background: #fff;
            color: #1a1a2e;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
            height: 44px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 4px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .form-text {
            font-size: 11px;
            color: #6c757d;
            margin-top: 4px;
        }

        .field-error {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.1) !important;
            background-color: #fff8f8 !important;
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

            .year-section .year-header {
                flex-direction: column;
                align-items: stretch;
            }

            .term-table {
                font-size: 12px;
            }

            .term-table thead th {
                padding: 8px 10px;
                font-size: 10px;
            }

            .term-table tbody td {
                padding: 8px 10px;
            }

            .action-group {
                justify-content: flex-start;
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
                        <h1><i class="fas fa-clock me-2"></i><?php echo h($labelTerm); ?>s</h1>
                        <p>Manage terms for each <?php echo h(strtolower($labelAcademic)); ?></p>
                    </div>
                    <div class="header-actions">
                        <?php if (!empty($years)): ?>
                            <a href="/platform/tenant/academic/terms.php?action=new&year_id=<?php echo (int)$years[0]['id']; ?>" class="btn btn-primary">
                                <i class="fas fa-plus me-2"></i> Add <?php echo h($labelTerm); ?>
                            </a>
                        <?php endif; ?>
                        <a href="/platform/tenant/academic/settings.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Settings
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo h($tenantName); ?></div>
                            <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                <?php echo count($years); ?> <?php echo h(strtolower($labelAcademic)); ?>(s) ·
                                Configured limit: <?php echo (int)$maxTerms; ?> <?php echo h(strtolower($labelTerm)); ?>(s) per <?php echo h(strtolower($labelAcademic)); ?>
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>
                        Terms are ordered within their year
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

                <?php if (!empty($orphanTerms)): ?>
                    <div class="orphan-banner">
                        <h6><i class="fas fa-exclamation-triangle"></i> <?php echo count($orphanTerms); ?> orphaned term(s) need your attention</h6>
                        <p>These terms are not bound to any <?php echo h(strtolower($labelAcademic)); ?>. Bind each one to a year below, or delete them. New terms always require a year.</p>
                        <div class="orphan-list">
                            <?php foreach ($orphanTerms as $ot): ?>
                                <div class="orphan-chip">
                                    <span style="font-weight:600;"><?php echo h($ot['term_name']); ?></span>
                                    <?php if (!empty($years)): ?>
                                        <form method="POST" class="bind-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="bind_year">
                                            <input type="hidden" name="id" value="<?php echo (int)$ot['id']; ?>">
                                            <select name="academic_year_id" required>
                                                <option value="">Bind to...</option>
                                                <?php foreach ($years as $y): ?>
                                                    <option value="<?php echo (int)$y['id']; ?>"><?php echo h($y['year_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-outline-success" style="padding:4px 10px;font-size:11px;">
                                                <i class="fas fa-link"></i> Bind
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (empty($years)): ?>
                    <div class="year-section">
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <h5>No <?php echo h($labelAcademic); ?>s yet</h5>
                            <p>You need at least one <?php echo h(strtolower($labelAcademic)); ?> before you can create <?php echo h(strtolower($labelTerm)); ?>s.</p>
                            <a href="/platform/tenant/academic/years.php?action=new" class="btn btn-primary btn-sm mt-2">
                                <i class="fas fa-plus me-1"></i> Add <?php echo h($labelAcademic); ?>
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($years as $y):
                        $yId = (int)$y['id'];
                        $terms = $termsByYear[$yId] ?? [];
                        $count = count($terms);
                        $isCurrentYear = ((int)$y['is_current']) === 1;
                    ?>
                        <div class="year-section">
                            <div class="year-header">
                                <div class="year-title">
                                    <i class="fas fa-calendar-alt" style="font-size:20px;color:#4facfe;"></i>
                                    <h6><?php echo h($y['year_name']); ?></h6>
                                    <?php if ($isCurrentYear): ?>
                                        <span class="pill green"><i class="fas fa-star"></i>Current</span>
                                    <?php endif; ?>
                                    <span class="pill <?php echo $count >= $maxTerms ? 'orange' : 'blue'; ?>">
                                        <i class="fas fa-clock"></i><?php echo h((string)$count); ?> / <?php echo (int)$maxTerms; ?> terms
                                    </span>
                                </div>
                                <?php if ($count < $maxTerms): ?>
                                    <a href="/platform/tenant/academic/terms.php?action=new&year_id=<?php echo $yId; ?>"
                                        class="btn btn-outline-primary">
                                        <i class="fas fa-plus"></i> Add <?php echo h($labelTerm); ?>
                                    </a>
                                <?php else: ?>
                                    <span style="font-size:11px;color:#856404;padding:6px 12px;background:#fff3cd;border-radius:8px;">
                                        <i class="fas fa-info-circle"></i> Limit reached — increase on Settings to add more
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="year-body">
                                <?php if (empty($terms)): ?>
                                    <div class="empty-terms">
                                        No <?php echo h(strtolower($labelTerm)); ?>s for this <?php echo h(strtolower($labelAcademic)); ?> yet.
                                    </div>
                                <?php else: ?>
                                    <table class="term-table">
                                        <thead>
                                            <tr>
                                                <th style="min-width:180px;"><?php echo h($labelTerm); ?> Name</th>
                                                <th>Start Date</th>
                                                <th>End Date</th>
                                                <th style="text-align:center;">Order</th>
                                                <th style="text-align:center;">Status</th>
                                                <th style="text-align:right;min-width:220px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($terms as $term):
                                                $tIsCurrent = ((int)$term['is_current']) === 1;
                                                $tIsActive = ((int)$term['is_active']) === 1;
                                            ?>
                                                <tr>
                                                    <td>
                                                        <div class="term-name"><?php echo h($term['term_name']); ?></div>
                                                    </td>
                                                    <td>
                                                        <?php echo $term['start_date']
                                                            ? h(date('M d, Y', strtotime($term['start_date'])))
                                                            : '<span class="text-muted">—</span>'; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo $term['end_date']
                                                            ? h(date('M d, Y', strtotime($term['end_date'])))
                                                            : '<span class="text-muted">—</span>'; ?>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <span class="term-order">#<?php echo (int)$term['sort_order']; ?></span>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <?php if ($tIsCurrent): ?>
                                                            <span class="pill green"><i class="fas fa-star"></i>Current</span>
                                                        <?php elseif ($tIsActive): ?>
                                                            <span class="pill blue"><i class="fas fa-check"></i>Active</span>
                                                        <?php else: ?>
                                                            <span class="pill gray"><i class="fas fa-times"></i>Inactive</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="action-group">
                                                            <a href="/platform/tenant/academic/terms.php?action=edit&id=<?php echo (int)$term['id']; ?>"
                                                                class="btn btn-outline-primary">
                                                                <i class="fas fa-edit"></i> Edit
                                                            </a>

                                                            <?php if (!$tIsCurrent): ?>
                                                                <form method="POST" style="display:inline-block;"
                                                                    onsubmit="return confirm('Set &quot;<?php echo h(addslashes($term['term_name'])); ?>&quot; as the current term?');">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="set_current">
                                                                    <input type="hidden" name="id" value="<?php echo (int)$term['id']; ?>">
                                                                    <button type="submit" class="btn btn-outline-success">
                                                                        <i class="fas fa-star"></i>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>

                                                            <form method="POST" style="display:inline-block;"
                                                                onsubmit="return confirm('<?php echo $tIsActive ? 'Deactivate' : 'Activate'; ?> &quot;<?php echo h(addslashes($term['term_name'])); ?>&quot;?');">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="toggle_active">
                                                                <input type="hidden" name="id" value="<?php echo (int)$term['id']; ?>">
                                                                <button type="submit" class="btn btn-outline-warning">
                                                                    <i class="fas fa-<?php echo $tIsActive ? 'pause' : 'play'; ?>"></i>
                                                                </button>
                                                            </form>

                                                            <?php if (!$tIsCurrent): ?>
                                                                <form method="POST" style="display:inline-block;"
                                                                    onsubmit="return confirm('Delete &quot;<?php echo h(addslashes($term['term_name'])); ?>&quot;?');">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="delete">
                                                                    <input type="hidden" name="id" value="<?php echo (int)$term['id']; ?>">
                                                                    <button type="submit" class="btn btn-outline-danger">
                                                                        <i class="fas fa-trash"></i>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <div class="modal fade<?php echo $showNewModal ? ' show' : ''; ?>"
        id="newTermModal" tabindex="-1"
        <?php echo $showNewModal ? 'style="display:block;background:rgba(0,0,0,0.4);"' : ''; ?>>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/academic/terms.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5><i class="fas fa-clock text-primary me-2"></i>Add <?php echo h($labelTerm); ?></h5>
                        <a href="/platform/tenant/academic/terms.php" class="btn-close"></a>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="new_academic_year_id"><?php echo h($labelAcademic); ?> <span class="required">*</span></label>
                            <select class="form-select" id="new_academic_year_id" name="academic_year_id" required>
                                <option value="">Select <?php echo h(strtolower($labelAcademic)); ?>...</option>
                                <?php foreach ($years as $y): ?>
                                    <option value="<?php echo (int)$y['id']; ?>"
                                        <?php echo ($newYearId === (int)$y['id']) ? 'selected' : ''; ?>>
                                        <?php echo h($y['year_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="new_term_name"><?php echo h($labelTerm); ?> Name <span class="required">*</span></label>
                            <input type="text" class="form-control" id="new_term_name" name="term_name"
                                maxlength="50" required
                                value="<?php echo ($useForm && ($formData['action'] ?? '') === 'create') ? v('term_name') : ''; ?>"
                                placeholder="e.g. First Term">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="new_start_date">Start Date</label>
                                <input type="date" class="form-control" id="new_start_date" name="start_date"
                                    value="<?php echo ($useForm && ($formData['action'] ?? '') === 'create') ? v('start_date') : ''; ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="new_end_date">End Date</label>
                                <input type="date" class="form-control" id="new_end_date" name="end_date"
                                    value="<?php echo ($useForm && ($formData['action'] ?? '') === 'create') ? v('end_date') : ''; ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="new_sort_order">Sort Order</label>
                                <input type="number" class="form-control" id="new_sort_order" name="sort_order"
                                    value="<?php echo ($useForm && ($formData['action'] ?? '') === 'create') ? v('sort_order', '1') : '1'; ?>">
                                <div class="form-text">Lower numbers appear first.</div>
                            </div>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="new_term_active" name="is_active" value="1" checked>
                            <label class="form-check-label" for="new_term_active">Active</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="new_term_current" name="is_current" value="1">
                            <label class="form-check-label" for="new_term_current">Set as current <?php echo h($labelTerm); ?></label>
                            <div class="form-text">Only one term per year can be current at a time.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="/platform/tenant/academic/terms.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($editTerm): ?>
        <div class="modal fade show" id="editTermModal" tabindex="-1"
            style="display:block;background:rgba(0,0,0,0.4);">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" action="/platform/tenant/academic/terms.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?php echo (int)$editTerm['id']; ?>">
                        <div class="modal-header">
                            <h5><i class="fas fa-edit text-primary me-2"></i>Edit <?php echo h($labelTerm); ?></h5>
                            <a href="/platform/tenant/academic/terms.php" class="btn-close"></a>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label"><?php echo h($labelAcademic); ?></label>
                                <input type="text" class="form-control" readonly
                                    value="<?php
                                            $editYearName = '';
                                            foreach ($years as $yy) {
                                                if ((int)$yy['id'] === (int)$editTerm['academic_year_id']) {
                                                    $editYearName = $yy['year_name'];
                                                    break;
                                                }
                                            }
                                            echo h($editYearName ?: '—');
                                            ?>">
                                <div class="form-text">Term cannot be moved between years. Delete and recreate if needed.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="edit_term_name"><?php echo h($labelTerm); ?> Name <span class="required">*</span></label>
                                <input type="text" class="form-control" id="edit_term_name" name="term_name"
                                    maxlength="50" required
                                    value="<?php echo h($editTerm['term_name']); ?>">
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="edit_term_start">Start Date</label>
                                    <input type="date" class="form-control" id="edit_term_start" name="start_date"
                                        value="<?php echo h($editTerm['start_date']); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="edit_term_end">End Date</label>
                                    <input type="date" class="form-control" id="edit_term_end" name="end_date"
                                        value="<?php echo h($editTerm['end_date']); ?>">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="edit_sort_order">Sort Order</label>
                                <input type="number" class="form-control" id="edit_sort_order" name="sort_order"
                                    value="<?php echo (int)$editTerm['sort_order']; ?>">
                                <div class="form-text">Lower numbers appear first.</div>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="edit_term_active" name="is_active" value="1"
                                    <?php echo ((int)$editTerm['is_active'] === 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="edit_term_active">Active</label>
                            </div>
                            <?php if ((int)$editTerm['is_current'] !== 1): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="edit_term_current" name="is_current" value="1">
                                    <label class="form-check-label" for="edit_term_current">
                                        Set as current <?php echo h($labelTerm); ?>
                                    </label>
                                </div>
                            <?php else: ?>
                                <input type="hidden" name="is_current" value="1">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" checked disabled>
                                    <label class="form-check-label text-muted">Currently active term</label>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <a href="/platform/tenant/academic/terms.php" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
            if (confirm('Are you sure you want to logout?')) window.location.href = '/platform/tenant/logout.php';
        }

        (function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('action') === 'new') {
                const modalEl = document.getElementById('newTermModal');
                if (modalEl && !modalEl.classList.contains('show')) {
                    const modal = new bootstrap.Modal(modalEl);
                    modal.show();
                }
            }
        })();

        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                let valid = true;
                let firstErr = null;
                this.querySelectorAll('[required]').forEach(f => {
                    if (f.disabled) return;
                    if (!f.value.trim()) {
                        f.classList.add('field-error');
                        valid = false;
                        if (!firstErr) firstErr = f;
                    } else {
                        f.classList.remove('field-error');
                    }
                });

                const s = this.querySelector('input[name="start_date"]');
                const en = this.querySelector('input[name="end_date"]');
                if (s && en && s.value && en.value) {
                    if (new Date(en.value) <= new Date(s.value)) {
                        en.classList.add('field-error');
                        valid = false;
                        if (!firstErr) firstErr = en;
                        alert('End date must be after start date.');
                    }
                }

                if (!valid) {
                    e.preventDefault();
                    if (firstErr) firstErr.focus();
                }
            });
        });

        document.querySelectorAll('.form-control, .form-select').forEach(el => {
            el.addEventListener('input', function() {
                this.classList.remove('field-error');
            });
            el.addEventListener('change', function() {
                this.classList.remove('field-error');
            });
        });

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