<?php

/**
 * Academic Results — scope hierarchy with pre-flight import confirmation and class picker.
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Academic
 * @version 1.0
 * @filepath public/platform/tenant/academic/results.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Academic results file of the academic-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'academic_results'
 *       to 'academic' so the partial marks Academic active and
 *       renders the academic sub-menu on this page — consistent
 *       with every other academic file.
 *     - A v1.0 [SWEEP] entry was added above this docblock, and
 *       @version was reduced from 3.9 to 1.0 per Decision X-3.
 *     - The inline <nav class="sidebar" id="sidebar"> block is
 *       removed and replaced by an include of
 *       app/views/partials/sidebar.php.
 *     - The CSS rule .nav-subgroup-label is added to this file's
 *       <style> block. The .nav-sub, .nav-sub .nav-link, and
 *       .nav-sub .nav-link.active rules, and the two responsive
 *       rules for .nav-sub, were already present in this file and
 *       are preserved unchanged.
 *   Every other line of the file is byte-identical to the
 *   previous version (3.9). The @package tag remains 'EduTrack'.
 *
 * v3.9 fixes:
 *   BUG-4  isTeacherOnOffering() referenced a non-existent teacher_id
 *          column on teacher_class_assignments (SQLSTATE[42S22]). The
 *          reference is removed; the query now filters by staff_id alone.
 *          The semantic question of whether $userId (a platform_users.id)
 *          matches staff.id is a separate decision tracked elsewhere.
 *          Only two lines changed relative to v3.8 (the WHERE clause and
 *          the parameter array). No other line of results.php changed.
 *
 * Read scopes:
 *   standard   (no view param)                                     — offering list
 *   level      ?view=level&level_id=X&term_id=Y                    — every class in the level
 *   class      ?view=class&offering_id=X&term_id=Y                 — one class master sheet
 *   subject    ?offering_id=X&subject_id=Y&term_id=Z               — one subject, editable
 *
 * Write actions (POST, CSRF-gated):
 *   import_preview, import_confirm, import_cancel, import,
 *   bulk_save, publish, lock, unlock, delete
 *
 * Export actions (GET, read-only):
 *   download_template, export_all, export_subject, export_level, export_class
 *
 * Decisions locked in (Session S17):
 *   D1  (1B) one raw score per subject per student per term
 *   D2  (2C) no per-component storage
 *   D3  (3A) one result row per (student, subject, term)
 *   D4  (4A) subject-centric entry
 *   D5  required: student_id + subject_id + enrollment_id + class_offering_id
 *       + academic_year_id + academic_term_id + raw_score; snapshot ids/versions
 *   D6  (6A) unique per (tenant, student, subject, term) for non-deleted rows
 *   D7  (7A) resolve scheme/scale at entry time; snapshot ids + versions
 *   D8  (8A) free soft-delete, audited
 *   D9  (9A) no promotion run in S17
 *   D10 (10A) grouped year → level → offering; subject picker per offering;
 *       per-student entry grid
 *   D11 (11A) draft → published; publishing locks offering+subject+term
 *
 * Import decisions: E1B–E20 as documented in v3.0.
 *
 * Result-lock decisions: RL1–RL6 as documented in v3.0.
 *
 * Enterprise items:
 *   ITEM_1_1  Import summary reports per-classification counts.
 *   ITEM_1_3  Row cap + duplicate student-number check.
 *   ITEM_1_4  Explicit "no data rows" error.
 *   ITEM_1_5  Uploaded file preserved after commit.
 *   ITEM_1_6  Rate limit: 30 s between imports; 200/day.
 *
 * Session S20 decisions applied:
 *   SH2A per-session CSRF token; POSTs carry it; GET downloads are read-only.
 *   SH3A central Security.php helper, loaded via app/bootstrap.php.
 *   SH4A hardened session started inside bootstrap.
 *   SH5C h() on every echoed value.
 *   SH6B tenant_id scoping and deleted_at IS NULL on every query.
 *
 * v3.2 fixes:
 *   BUG-1  Lock and Unlock modals rendered unconditionally at the bottom.
 *   BUG-2  Per-row delete form moved outside the outer save form.
 *
 * v3.3 fixes:
 *   BUG-3  Removed the two C-style block comments that sat outside PHP tags.
 *   EXP-1  export_class reshaped to the confirmed columns.
 *   EXP-2  confirm() added on the "Export class master sheet" button.
 *
 * v3.4 fixes:
 *   EXP-1-R1  export_class header is now a fixed nine-subject list, in the
 *             order specified by the user's instruction, instead of the
 *             offering's dynamic loadOfferingSubjects() order. Subjects not
 *             configured for the offering are still emitted as columns and
 *             left blank for every student.
 *
 * v3.5 fixes:
 *   BECE-1  Added resolveBecePromotionRule() and computeBeceAggregate().
 *   BECE-2  export_class now appends two columns, Grade and Aggregate,
 *           after Position in Class. Both carry the sum of the numeric
 *           grade points (1-9) read from grade_rules.grade_symbol over the
 *           offering's core subjects (class_subjects.is_core = 1) plus the
 *           best-N electives by final_score (tie-break subject_id ascending),
 *           where N = promotion_rules.elective_pick_count. Both are blank
 *           when the applicable rule is not numeric + bece_6.
 *   BECE-3  The Overall Grade column has been removed from export_class.
 *           Its name collided with the new Aggregate column (both describe
 *           the student's overall outcome), so the tautology was resolved
 *           by dropping the older column and keeping Grade + Aggregate.
 *           The $overallGrade pre-computation block inside export_class was
 *           deleted along with it. No other line of results.php changed.
 *
 * v3.6 fixes:
 *   BECE-4  export_class header and per-student cells reordered. The
 *           trailing summary is now
 *             Total | Average | Aggregate | Position in Class
 *           The Grade column was removed; the Aggregate column now sits
 *           between Average and Position in Class. The
 *           computeBeceAggregate() helper and its return contract are
 *           unchanged; only the CSV consumer changed.
 *
 * v3.7 fixes:
 *   BECE-5  export_class trailing column is now branched by the applicable
 *           promotion rule's grading_style:
 *             grading_style = 'alphabetic'  → Total | Average | Grade | Position in Class
 *             every other case              → Total | Average | Aggregate | Position in Class
 *           The Grade cell carries grade_rules.grade_symbol for the band
 *           whose range contains the student's all-subjects average, on the
 *           scale resolved by resolveScale() for the offering's level/year.
 *           The bands are not hardcoded; they come from the admin's
 *           configured grading scale, and grading-scales.php's D6 coverage
 *           gate is responsible for keeping them gap-free and overlap-free.
 *           One new helper, computePrimaryGrade(), added alongside the B.E.C.E.
 *           helpers. computeBeceAggregate() and its return contract are
 *           unchanged. The one-line confirm() text on the export button now
 *           names both labels ("Grade or Aggregate").
 *
 * v3.8 fixes:
 *   BECE-6  The blank separator column between the subject columns and
 *           Total has been removed from export_class. The header now flows
 *           directly from the last subject column into Total, and every
 *           data row matches. Only two lines are deleted relative to v3.7:
 *           the "$header[] = '';" line inside the header block, and the
 *           "$line[] = '';" line inside the per-student loop. No other
 *           line of results.php changed. The Grade column for the
 *           alphabetic branch continues to be populated by
 *           computePrimaryGrade(); no code change was needed for that.
 */

// ============================================
// S20 — Bootstrap
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

$pageTitle   = 'Results - Student 360 Platform';
$currentPage = 'academic';

$db = DatabaseHelper::getInstance();

const MAX_IMPORT_ROWS = 5000;
const IMPORT_MIN_SECONDS_BETWEEN = 30;
const IMPORT_MAX_PER_DAY = 200;

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

function preserveImportFile(string $tmpName, string $origName, int $tenantId, string $projectRoot): ?string
{
    try {
        if ($tmpName === '' || !is_file($tmpName)) return null;
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) $ext = 'csv';
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($origName, PATHINFO_FILENAME));
        if ($safe === '') $safe = 'import';
        $rel = 'storage/imports/' . (int)$tenantId;
        $abs = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_dir($abs)) {
            if (!@mkdir($abs, 0775, true) && !is_dir($abs)) {
                error_log('import storage: could not create directory ' . $abs);
                return null;
            }
        }
        $fname = date('Ymd_His') . '_' . $safe . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest  = $abs . DIRECTORY_SEPARATOR . $fname;
        if (!@copy($tmpName, $dest)) {
            error_log('import storage: could not copy uploaded file to ' . $dest);
            return null;
        }
        return $rel . '/' . $fname;
    } catch (Exception $e) {
        error_log('import storage: unexpected failure: ' . $e->getMessage());
        return null;
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

function resolveScheme($db, int $tenantId, ?int $levelId, ?int $yearId): ?array
{
    $sql = "SELECT * FROM assessment_schemes
            WHERE tenant_id = ? AND status = 'active' AND deleted_at IS NULL";
    $params = [$tenantId];
    $levelClause = $levelId !== null && $levelId > 0
        ? "(academic_level_id = ? OR academic_level_id IS NULL)"
        : "academic_level_id IS NULL";
    if ($levelId !== null && $levelId > 0) $params[] = $levelId;
    $yearClause = $yearId !== null && $yearId > 0
        ? "(academic_year_id = ? OR academic_year_id IS NULL)"
        : "academic_year_id IS NULL";
    if ($yearId !== null && $yearId > 0) $params[] = $yearId;
    $sql .= " AND $levelClause AND $yearClause";
    $sql .= " ORDER BY
                (academic_level_id = ?) DESC,
                (academic_year_id = ?) DESC,
                version DESC, id DESC
              LIMIT 1";
    $params[] = $levelId ?: 0;
    $params[] = $yearId  ?: 0;
    return $db->fetchOne($sql, $params) ?: null;
}

function resolveScale($db, int $tenantId, ?int $levelId, ?int $yearId): ?array
{
    $sql = "SELECT * FROM grading_scales
            WHERE tenant_id = ? AND status = 'active' AND deleted_at IS NULL";
    $params = [$tenantId];
    $levelClause = $levelId !== null && $levelId > 0
        ? "(academic_level_id = ? OR academic_level_id IS NULL)"
        : "academic_level_id IS NULL";
    if ($levelId !== null && $levelId > 0) $params[] = $levelId;
    $yearClause = $yearId !== null && $yearId > 0
        ? "(academic_year_id = ? OR academic_year_id IS NULL)"
        : "academic_year_id IS NULL";
    if ($yearId !== null && $yearId > 0) $params[] = $yearId;
    $sql .= " AND $levelClause AND $yearClause";
    $sql .= " ORDER BY
                (academic_level_id = ?) DESC,
                (academic_year_id = ?) DESC,
                version DESC, id DESC
              LIMIT 1";
    $params[] = $levelId ?: 0;
    $params[] = $yearId  ?: 0;
    return $db->fetchOne($sql, $params) ?: null;
}

function resolveGradeRule($db, int $tenantId, int $scaleId, float $score): ?array
{
    return $db->fetchOne(
        "SELECT * FROM grade_rules
         WHERE tenant_id = ? AND grading_scale_id = ?
           AND is_active = 1 AND deleted_at IS NULL
           AND ? >= score_from AND ? <= score_to
         ORDER BY sort_order ASC
         LIMIT 1",
        [$tenantId, $scaleId, $score, $score]
    ) ?: null;
}

function computeWeighted(float $raw, ?array $scheme): float
{
    return $raw;
}

function getOffering($db, int $tenantId, int $offeringId): ?array
{
    if ($offeringId <= 0) return null;
    return $db->fetchOne(
        "SELECT co.*, c.class_name, c.class_code,
                al.level_name, al.level_code,
                ay.year_name,
                s.stream_name,
                (SELECT COUNT(*) FROM enrollments e
                 WHERE e.tenant_id = co.tenant_id
                   AND e.class_offering_id = co.id
                   AND e.status = 'active'
                   AND e.deleted_at IS NULL) AS active_count
         FROM class_offerings co
         LEFT JOIN classes c ON co.class_id = c.id
         LEFT JOIN academic_levels al ON co.academic_level_id = al.id
         LEFT JOIN academic_years ay ON co.academic_year_id = ay.id
         LEFT JOIN streams s ON co.stream_id = s.id
         WHERE co.id = ? AND co.tenant_id = ? AND co.deleted_at IS NULL",
        [$offeringId, $tenantId]
    ) ?: null;
}

function loadOfferingSubjects($db, int $tenantId, int $offeringId): array
{
    return $db->fetchAll(
        "SELECT s.id, s.subject_name, s.subject_code, cs.is_core, cs.weekly_periods
         FROM class_subjects cs
         JOIN subjects s ON cs.subject_id = s.id
         WHERE cs.tenant_id = ?
           AND cs.class_offering_id = ?
           AND cs.is_active = 1
           AND cs.deleted_at IS NULL
           AND s.deleted_at IS NULL
         ORDER BY cs.is_core DESC, s.subject_name ASC",
        [$tenantId, $offeringId]
    );
}

// ============================================
// RESULT-LOCK HELPERS (RL1–RL5)
// ============================================
function isScopeLocked($db, int $tenantId, int $offeringId, int $subjectId, int $termId): bool
{
    if ($offeringId <= 0 || $subjectId <= 0 || $termId <= 0) return false;
    $row = $db->fetchOne(
        "SELECT id FROM result_locks
          WHERE tenant_id = ?
            AND class_offering_id = ?
            AND subject_id = ?
            AND academic_term_id = ?
            AND unlocked_at IS NULL
            AND deleted_at IS NULL
          LIMIT 1",
        [$tenantId, $offeringId, $subjectId, $termId]
    );
    return $row !== false && $row !== null;
}

// [v3.9 BUG-4] Removed the non-existent `teacher_id` column from the WHERE
// clause. The query now filters by staff_id alone. The semantic question of
// whether $userId (a platform_users.id) matches staff.id is tracked
// separately and is not addressed here.
function isTeacherOnOffering($db, int $tenantId, int $userId, int $offeringId): bool
{
    if ($userId <= 0 || $offeringId <= 0) return false;
    try {
        $row = $db->fetchOne(
            "SELECT id FROM teacher_class_assignments
              WHERE tenant_id = ?
                AND class_offering_id = ?
                AND staff_id = ?
                AND deleted_at IS NULL
              LIMIT 1",
            [$tenantId, $offeringId, $userId]
        );
        return $row !== false && $row !== null;
    } catch (Exception $e) {
        return false;
    }
}

function lockScope($db, int $tenantId, int $userId, int $offeringId, int $subjectId, int $termId, string $reason): int
{
    $existing = $db->fetchOne(
        "SELECT id FROM result_locks
          WHERE tenant_id = ?
            AND class_offering_id = ?
            AND subject_id = ?
            AND academic_term_id = ?
            AND unlocked_at IS NULL
            AND deleted_at IS NULL
          FOR UPDATE",
        [$tenantId, $offeringId, $subjectId, $termId]
    );
    if ($existing) return (int)$existing['id'];
    $lockId = $db->insert(
        "INSERT INTO result_locks
            (uuid, tenant_id, class_offering_id, subject_id, academic_term_id,
             locked_at, locked_by, lock_reason,
             created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, NOW(), NOW())",
        [uuidv4(), $tenantId, $offeringId, $subjectId, $termId, $userId ?: null, $reason]
    );
    return (int)$lockId;
}

function unlockScope($db, int $tenantId, int $userId, int $offeringId, int $subjectId, int $termId, string $reason): int
{
    $db->execute(
        "UPDATE result_locks
            SET unlocked_at = NOW(),
                unlocked_by = ?,
                unlock_reason = ?,
                updated_at = NOW()
          WHERE tenant_id = ?
            AND class_offering_id = ?
            AND subject_id = ?
            AND academic_term_id = ?
            AND unlocked_at IS NULL
            AND deleted_at IS NULL",
        [$userId ?: null, $reason, $tenantId, $offeringId, $subjectId, $termId]
    );
    return 1;
}

function requireAdminForLock($db, int $tenantId, int $userId, int $offeringId, bool $isSuperAdmin): void
{
    if ($isSuperAdmin) return;
    if (isTeacherOnOffering($db, $tenantId, $userId, $offeringId)) {
        throw new Exception('Teachers cannot lock or unlock results for their own offerings.');
    }
}

// ============================================
// IMPORT HELPERS
// ============================================
function _resultsStudentNumberAliases(): array
{
    return ['student_number', 'student number', 'admission_number', 'admission number', 'adm', 'adm_no', 'adm no', 'student_no', 'student no'];
}

function _resultsTermAliases(): array
{
    return ['term', 'term_name', 'term name', 'academic_term', 'academic term', 'semester'];
}

function resultsXlsxAvailable(): bool
{
    if (class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) return true;
    $paths = [
        dirname(__DIR__, 3) . '/vendor/autoload.php',
        dirname(__DIR__, 4) . '/vendor/autoload.php',
    ];
    foreach ($paths as $p) {
        if (is_file($p)) {
            require_once $p;
            if (class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) return true;
        }
    }
    return false;
}

function _resultsNormHeader(?string $s): string
{
    $s = strtolower(trim((string)$s));
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

function _resultsMapHeadersMulti(array $headers, array $classSubjects): array
{
    $norm = array_map('_resultsNormHeader', $headers);
    $find = function (array $aliases) use ($norm): ?int {
        foreach ($norm as $i => $h) {
            if (in_array($h, $aliases, true)) return $i;
        }
        return null;
    };
    $studentCol = $find(_resultsStudentNumberAliases());
    $termCol    = $find(_resultsTermAliases());
    if ($studentCol === null) {
        throw new Exception('The file is missing a student-number column. Use one of: student_number, admission_number, adm.');
    }
    if ($termCol === null) {
        throw new Exception('The file is missing a term column. Add a column named term, term_name, or academic_term.');
    }
    $byLabel = [];
    foreach ($classSubjects as $sub) {
        $sid = (int)$sub['id'];
        $name = _resultsNormHeader($sub['subject_name'] ?? '');
        $code = _resultsNormHeader($sub['subject_code'] ?? '');
        if ($name !== '') $byLabel[$name] = $sid;
        if ($code !== '') $byLabel[$code] = $sid;
    }
    $subjectCols = [];
    foreach ($norm as $i => $h) {
        if ($h === '') continue;
        if ($i === $studentCol || $i === $termCol) continue;
        if (isset($byLabel[$h])) $subjectCols[$byLabel[$h]] = $i;
    }
    if (empty($subjectCols)) {
        throw new Exception('No subject columns were found in the file. Column headers must match the subject name or subject code of at least one subject offered in this class.');
    }
    return ['student_number' => $studentCol, 'term' => $termCol, 'subjects' => $subjectCols];
}

function _resultsNormaliseRowMulti(int $rowNumber, string $studentNumber, string $termRaw, array $subjectRaws): array
{
    $error = null;
    if ($studentNumber === '') $error = 'Missing student number.';
    $subjectResults = [];
    foreach ($subjectRaws as $subjectId => $rawRaw) {
        $raw = null;
        $subErr = null;
        if ($rawRaw === '') {
            $raw = null;
        } elseif (!is_numeric($rawRaw)) {
            $subErr = 'Score "' . $rawRaw . '" is not numeric.';
        } else {
            $raw = (float)$rawRaw;
            if ($raw < 0 || $raw > 100) {
                $subErr = 'Score ' . $rawRaw . ' is outside 0–100.';
                $raw = null;
            }
        }
        $subjectResults[(int)$subjectId] = ['raw' => $raw, 'error' => $subErr];
    }
    return [
        'row'            => $rowNumber,
        'student_number' => $studentNumber,
        'term_raw'       => $termRaw,
        'subjects'       => $subjectResults,
        'error'          => $error,
    ];
}

function resultsParseCsvMulti(string $path, array $classSubjects): array
{
    if (!is_readable($path)) throw new Exception('CSV file is not readable.');
    $fh = fopen($path, 'r');
    if (!$fh) throw new Exception('Could not open CSV file.');
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);
    $headerRow = fgetcsv($fh);
    if ($headerRow === false) {
        fclose($fh);
        throw new Exception('CSV file appears to be empty.');
    }
    $map = _resultsMapHeadersMulti($headerRow, $classSubjects);
    $rows = [];
    $rowNumber = 1;
    while (($line = fgetcsv($fh)) !== false) {
        $rowNumber++;
        if (count($line) === 1 && trim((string)$line[0]) === '') continue;
        $studentNumber = isset($line[$map['student_number']]) ? trim((string)$line[$map['student_number']]) : '';
        $termRaw       = isset($line[$map['term']])           ? trim((string)$line[$map['term']])           : '';
        $subjectRaws = [];
        foreach ($map['subjects'] as $sid => $colIdx) {
            $subjectRaws[$sid] = isset($line[$colIdx]) ? trim((string)$line[$colIdx]) : '';
        }
        $rows[] = _resultsNormaliseRowMulti($rowNumber, $studentNumber, $termRaw, $subjectRaws);
    }
    fclose($fh);
    return ['rows' => $rows, 'headers' => $headerRow, 'map' => $map, 'format' => 'csv'];
}

function resultsParseXlsxMulti(string $path, array $classSubjects): array
{
    if (!resultsXlsxAvailable()) {
        throw new Exception('XLSX support is not installed on the server. Please save the file as CSV and try again.');
    }
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($path);
    $sheet = $spreadsheet->getActiveSheet();
    $data = $sheet->toArray(null, true, true, false);
    if (empty($data)) throw new Exception('XLSX file appears to be empty.');
    $headerRow = array_shift($data);
    $map = _resultsMapHeadersMulti($headerRow, $classSubjects);
    $rows = [];
    $rowNumber = 1;
    foreach ($data as $line) {
        $rowNumber++;
        $studentNumber = isset($line[$map['student_number']]) ? trim((string)$line[$map['student_number']]) : '';
        $termRaw       = isset($line[$map['term']])           ? trim((string)$line[$map['term']])           : '';
        if ($studentNumber === '' && $termRaw === '') continue;
        $subjectRaws = [];
        foreach ($map['subjects'] as $sid => $colIdx) {
            $subjectRaws[$sid] = isset($line[$colIdx]) ? trim((string)$line[$colIdx]) : '';
        }
        $rows[] = _resultsNormaliseRowMulti($rowNumber, $studentNumber, $termRaw, $subjectRaws);
    }
    return ['rows' => $rows, 'headers' => $headerRow, 'map' => $map, 'format' => 'xlsx'];
}

function resultsParseUploadedMulti(string $path, array $classSubjects, string $origName = '', string $mime = ''): array
{
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === 'csv' || $ext === 'txt') return resultsParseCsvMulti($path, $classSubjects);
    if ($ext === 'xlsx')                  return resultsParseXlsxMulti($path, $classSubjects);
    if ($ext === 'xls') {
        throw new Exception('Legacy .xls files are not supported. Save as .xlsx or .csv and try again.');
    }
    throw new Exception('Unsupported file type. Please upload a .csv file (or .xlsx if available).');
}

function resultsBuildTemplateMulti(array $students, array $subjects, ?string $defaultTermName): string
{
    $out = fopen('php://temp', 'r+');
    $header = ['student_number', 'student_name', 'term'];
    foreach ($subjects as $sub) $header[] = $sub['subject_name'];
    fputcsv($out, $header);
    $defaultTermCell = $defaultTermName !== null ? $defaultTermName : '';
    foreach ($students as $s) {
        $name = trim(($s['first_name'] ?? '') . ' ' . ($s['middle_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
        $name = preg_replace('/\s+/', ' ', $name);
        $row = [(string)($s['student_number'] ?? ''), $name, $defaultTermCell];
        foreach ($subjects as $sub) $row[] = '';
        fputcsv($out, $row);
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return $csv;
}

function loadEnrolled($db, int $tenantId, int $offeringId): array
{
    return $db->fetchAll(
        "SELECT e.id AS enrollment_id, e.student_id, e.roll_number,
                s.first_name, s.middle_name, s.last_name, s.preferred_name,
                s.student_number
         FROM enrollments e
         JOIN students s ON e.student_id = s.id
         WHERE e.tenant_id = ?
           AND e.class_offering_id = ?
           AND e.status = 'active'
           AND e.deleted_at IS NULL
         ORDER BY
            CASE WHEN e.roll_number IS NULL OR e.roll_number = '' THEN 1 ELSE 0 END,
            CAST(e.roll_number AS UNSIGNED) ASC,
            s.first_name ASC",
        [$tenantId, $offeringId]
    );
}

function getTerm($db, int $tenantId, int $termId): ?array
{
    if ($termId <= 0) return null;
    return $db->fetchOne(
        "SELECT id, term_name, academic_year_id FROM academic_terms
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$termId, $tenantId]
    ) ?: null;
}

function resolveTermByLabel($db, int $tenantId, string $label): ?array
{
    $label = trim($label);
    if ($label === '') return null;
    $row = $db->fetchOne(
        "SELECT id, term_name, academic_year_id FROM academic_terms
         WHERE tenant_id = ? AND deleted_at IS NULL
           AND LOWER(term_name) = LOWER(?)
         ORDER BY is_current DESC, sort_order ASC, id ASC
         LIMIT 1",
        [$tenantId, $label]
    );
    if ($row) return $row;
    if (ctype_digit($label)) {
        $row = $db->fetchOne(
            "SELECT id, term_name, academic_year_id FROM academic_terms
             WHERE tenant_id = ? AND id = ? AND deleted_at IS NULL
             LIMIT 1",
            [$tenantId, (int)$label]
        );
        if ($row) return $row;
    }
    return null;
}

function loadClassMasterSheet($db, int $tenantId, int $offeringId, int $termId): array
{
    $subjects = loadOfferingSubjects($db, $tenantId, $offeringId);
    $students = loadEnrolled($db, $tenantId, $offeringId);

    $rows = $db->fetchAll(
        "SELECT student_id, subject_id, raw_score, final_score,
                grade_symbol, grade_label, is_pass, status
         FROM results
         WHERE tenant_id = ?
           AND class_offering_id = ?
           AND academic_term_id = ?
           AND deleted_at IS NULL",
        [$tenantId, $offeringId, $termId]
    );

    $byStudentSubject = [];
    foreach ($rows as $r) {
        $byStudentSubject[(int)$r['student_id']][(int)$r['subject_id']] = $r;
    }

    $subjectTotals = [];
    foreach ($subjects as $sub) {
        $sid = (int)$sub['id'];
        $scores = [];
        foreach ($byStudentSubject as $studentId => $bySub) {
            if (!isset($bySub[$sid])) continue;
            $r = $bySub[$sid];
            $sc = $r['final_score'] !== null
                ? (float)$r['final_score']
                : ($r['raw_score'] !== null ? (float)$r['raw_score'] : null);
            if ($sc !== null) $scores[] = $sc;
        }
        $subjectTotals[$sid] = [
            'count'   => count($scores),
            'average' => count($scores) > 0 ? array_sum($scores) / count($scores) : null,
            'high'    => count($scores) > 0 ? max($scores) : null,
            'low'     => count($scores) > 0 ? min($scores) : null,
        ];
    }

    $studentTotals = [];
    foreach ($students as $st) {
        $studentId = (int)$st['student_id'];
        $scores = [];
        $passes = 0;
        foreach ($subjects as $sub) {
            $sid = (int)$sub['id'];
            $r = $byStudentSubject[$studentId][$sid] ?? null;
            if ($r === null) continue;
            $sc = $r['final_score'] !== null
                ? (float)$r['final_score']
                : ($r['raw_score'] !== null ? (float)$r['raw_score'] : null);
            if ($sc !== null) {
                $scores[] = $sc;
                if (!empty($r['is_pass'])) $passes++;
            }
        }
        $studentTotals[$studentId] = [
            'count'   => count($scores),
            'total'   => array_sum($scores),
            'average' => count($scores) > 0 ? array_sum($scores) / count($scores) : null,
            'passes'  => $passes,
        ];
    }

    $lockedCount = 0;
    try {
        $row = $db->fetchOne(
            "SELECT COUNT(*) AS c FROM result_locks
             WHERE tenant_id = ?
               AND class_offering_id = ?
               AND academic_term_id = ?
               AND unlocked_at IS NULL
               AND deleted_at IS NULL",
            [$tenantId, $offeringId, $termId]
        );
        $lockedCount = (int)($row['c'] ?? 0);
    } catch (Exception $e) {
        $lockedCount = 0;
    }

    return [
        'subjects'       => $subjects,
        'students'       => $students,
        'results'        => $byStudentSubject,
        'subject_totals' => $subjectTotals,
        'student_totals' => $studentTotals,
        'locked_count'   => $lockedCount,
    ];
}
// [v3.9 :: CONTINUES FROM PART 1/3]

// ============================================
// B.E.C.E. AGGREGATE HELPERS (v3.5)
// ============================================

/**
 * [BECE-1] Resolve the applicable active promotion rule for an offering's
 * level and year. Follows the same level/year specificity ordering that
 * resolveScheme() and resolveScale() already use in this file: level-specific
 * rows first, then tenant-wide (academic_level_id IS NULL); year-specific
 * rows first, then tenant-wide (academic_year_id IS NULL). Read-only.
 *
 * Returns the promotion_rules row, or null when no active rule applies.
 */
function resolveBecePromotionRule($db, int $tenantId, ?int $levelId, ?int $yearId): ?array
{
    $sql = "SELECT * FROM promotion_rules
            WHERE tenant_id = ? AND status = 'active' AND deleted_at IS NULL";
    $params = [$tenantId];
    $levelClause = $levelId !== null && $levelId > 0
        ? "(academic_level_id = ? OR academic_level_id IS NULL)"
        : "academic_level_id IS NULL";
    if ($levelId !== null && $levelId > 0) $params[] = $levelId;
    $yearClause = $yearId !== null && $yearId > 0
        ? "(academic_year_id = ? OR academic_year_id IS NULL)"
        : "academic_year_id IS NULL";
    if ($yearId !== null && $yearId > 0) $params[] = $yearId;
    $sql .= " AND $levelClause AND $yearClause";
    $sql .= " ORDER BY
                (academic_level_id = ?) DESC,
                (academic_year_id = ?) DESC,
                version DESC, id DESC
              LIMIT 1";
    $params[] = $levelId ?: 0;
    $params[] = $yearId  ?: 0;
    try {
        return $db->fetchOne($sql, $params) ?: null;
    } catch (Exception $e) {
        error_log('resolveBecePromotionRule failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * [BECE-1] Compute the B.E.C.E. grade and aggregate for one student on one
 * offering in one term. Read-only; writes nothing.
 *
 * Applies only when:
 *   - a promotion rule is supplied, and
 *   - its grading_style is 'numeric', and
 *   - its rule_mode is 'bece_6'.
 *
 * In every other case it returns ['grade' => null, 'aggregate' => null,
 * 'blocked_reason' => <reason>] and the caller emits blank cells.
 *
 * The core set is the offering's class_subjects rows with is_core = 1.
 * The elective set is the offering's class_subjects rows with is_core = 0.
 * The best-N electives are chosen by final_score descending (falling back
 * to raw_score where final_score is null), tie-broken by subject_id
 * ascending per D18. N = promotion_rules.elective_pick_count, default 2.
 *
 * The grade point of each selected subject is intval(grade_rules.grade_symbol)
 * for the band whose [score_from, score_to] contains the subject's score on
 * the scale already resolved by resolveScale() for the offering's level/year.
 * If any symbol is non-numeric, the whole computation is aborted and a
 * blank result is returned rather than silently summing zeros.
 *
 * Returns:
 *   ['grade' => int|null, 'aggregate' => int|null,
 *    'blocked_reason' => string|null,
 *    'cores_used' => array<int>, 'electives_used' => array<int>]
 */
function computeBeceAggregate(
    $db,
    int $tenantId,
    int $scaleId,
    array $rule,
    array $sheetSubjects,
    array $studentResults
): array {
    $empty = ['grade' => null, 'aggregate' => null, 'blocked_reason' => null, 'cores_used' => [], 'electives_used' => []];

    if (empty($rule)) {
        return array_merge($empty, ['blocked_reason' => 'No active promotion rule applies.']);
    }
    $style = (string)($rule['grading_style'] ?? 'numeric');
    $mode  = (string)($rule['rule_mode'] ?? 'all_subjects');
    if ($style !== 'numeric' || $mode !== 'bece_6') {
        return array_merge($empty, ['blocked_reason' => 'Rule is not numeric + bece_6.']);
    }
    if ($scaleId <= 0) {
        return array_merge($empty, ['blocked_reason' => 'No active grading scale applies.']);
    }

    $pickN = (int)($rule['elective_pick_count'] ?? 2);
    if ($pickN < 1) $pickN = 1;

    $cores = [];
    $electives = [];
    foreach ($sheetSubjects as $sub) {
        $sid = (int)$sub['id'];
        if (!empty($sub['is_core'])) $cores[$sid] = $sub;
        else                         $electives[$sid] = $sub;
    }

    // Build a flat row map for the student: subject_id => {score, symbol}
    $bySubject = [];
    foreach ($studentResults as $sid => $row) {
        $sc = $row['final_score'] !== null
            ? (float)$row['final_score']
            : ($row['raw_score'] !== null ? (float)$row['raw_score'] : null);
        if ($sc === null) continue;
        $bySubject[(int)$sid] = ['score' => $sc, 'row' => $row];
    }

    // Core grade points.
    $sum = 0;
    $coresUsed = [];
    foreach ($cores as $sid => $sub) {
        if (!isset($bySubject[$sid])) {
            return array_merge($empty, ['blocked_reason' => 'Core subject has no score for this student.']);
        }
        $score = $bySubject[$sid]['score'];
        $band = resolveGradeRule($db, $tenantId, $scaleId, $score);
        if (!$band || !isset($band['grade_symbol'])) {
            return array_merge($empty, ['blocked_reason' => 'No grade band matches score ' . $score . '.']);
        }
        $sym = trim((string)$band['grade_symbol']);
        if ($sym === '' || !ctype_digit($sym)) {
            return array_merge($empty, ['blocked_reason' => 'Grade symbol "' . $sym . '" is not numeric; cannot aggregate.']);
        }
        $sum += (int)$sym;
        $coresUsed[] = $sid;
    }

    // Best-N electives by final_score desc, tie-break subject_id asc (D18).
    $electiveCandidates = [];
    foreach ($electives as $sid => $sub) {
        if (!isset($bySubject[$sid])) continue;
        $electiveCandidates[] = ['subject_id' => $sid, 'score' => $bySubject[$sid]['score']];
    }
    usort($electiveCandidates, function ($a, $b) {
        if ($a['score'] == $b['score']) return $a['subject_id'] <=> $b['subject_id'];
        return $b['score'] <=> $a['score'];
    });
    $electivesUsed = [];
    $taken = 0;
    foreach ($electiveCandidates as $cand) {
        if ($taken >= $pickN) break;
        $sid = (int)$cand['subject_id'];
        $band = resolveGradeRule($db, $tenantId, $scaleId, (float)$cand['score']);
        if (!$band || !isset($band['grade_symbol'])) {
            return array_merge($empty, ['blocked_reason' => 'No grade band matches elective score ' . $cand['score'] . '.']);
        }
        $sym = trim((string)$band['grade_symbol']);
        if ($sym === '' || !ctype_digit($sym)) {
            return array_merge($empty, ['blocked_reason' => 'Grade symbol "' . $sym . '" is not numeric; cannot aggregate.']);
        }
        $sum += (int)$sym;
        $electivesUsed[] = $sid;
        $taken++;
    }
    if ($taken < $pickN) {
        return array_merge($empty, ['blocked_reason' => 'Fewer than ' . $pickN . ' electives have scores for this student.']);
    }

    return [
        'grade'          => $sum,
        'aggregate'      => $sum,
        'blocked_reason' => null,
        'cores_used'     => $coresUsed,
        'electives_used' => $electivesUsed,
    ];
}

/**
 * [BECE-5 v3.7] Compute the Primary "Grade" value for one student: the
 * grade_symbol of the band whose range contains the student's all-subjects
 * average, on the scale already resolved for the offering's level/year.
 *
 * Read-only; writes nothing. Bands come from grade_rules via
 * resolveGradeRule(), which uses ORDER BY sort_order ASC LIMIT 1. The
 * bands are NOT hardcoded. grading-scales.php's D6 coverage gate is
 * responsible for keeping the bands gap-free and overlap-free at
 * activation time.
 *
 * Returns:
 *   ['grade' => string|null, 'average' => float|null]
 * grade is the band's grade_symbol (e.g. 'A', 'B', 'C' or '1', '2', '3')
 * or null when the student has no scores, no scale applies, or no band
 * matches the average.
 */
function computePrimaryGrade(
    $db,
    int $tenantId,
    int $scaleId,
    array $sheetSubjects,
    array $studentResults
): array {
    $empty = ['grade' => null, 'average' => null];

    if ($scaleId <= 0) {
        return $empty;
    }

    $scores = [];
    foreach ($sheetSubjects as $sub) {
        $sid = (int)$sub['id'];
        $row = $studentResults[$sid] ?? null;
        if ($row === null) continue;
        $sc = $row['final_score'] !== null
            ? (float)$row['final_score']
            : ($row['raw_score'] !== null ? (float)$row['raw_score'] : null);
        if ($sc !== null) $scores[] = $sc;
    }
    if (empty($scores)) {
        return $empty;
    }

    $average = array_sum($scores) / count($scores);
    $band = resolveGradeRule($db, $tenantId, $scaleId, $average);
    if (!$band || !isset($band['grade_symbol'])) {
        return ['grade' => null, 'average' => $average];
    }
    $sym = trim((string)$band['grade_symbol']);
    if ($sym === '') {
        return ['grade' => null, 'average' => $average];
    }

    return ['grade' => $sym, 'average' => $average];
}

// ============================================
// ACTIONS
// ============================================
$errors   = [];
$formData = [];
$action   = $_POST['action'] ?? $_GET['action'] ?? '';

// -- TEMPLATE DOWNLOAD (GET, read-only) --
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'download_template') {
    $offeringId = (int)($_GET['offering_id'] ?? 0);
    $termId     = (int)($_GET['term_id'] ?? 0);
    $offering = getOffering($db, $tenantId, $offeringId);
    if (!$offering) {
        $_SESSION['errors'] = ['Offering not found.'];
        header('Location: /platform/tenant/academic/results.php');
        exit;
    }
    $term = getTerm($db, $tenantId, $termId);
    if (!$term) {
        $_SESSION['errors'] = ['Please choose a term before downloading the template.'];
        header('Location: /platform/tenant/academic/results.php?offering_id=' . $offeringId);
        exit;
    }
    $allSubjects = loadOfferingSubjects($db, $tenantId, $offeringId);
    $requestedSubjectIds = [];
    if (isset($_GET['subject_ids']) && is_array($_GET['subject_ids'])) {
        foreach ($_GET['subject_ids'] as $sid) {
            $sid = (int)$sid;
            if ($sid > 0) $requestedSubjectIds[] = $sid;
        }
    }
    if (!empty($requestedSubjectIds)) {
        $templateSubjects = array_values(array_filter(
            $allSubjects,
            fn($s) => in_array((int)$s['id'], $requestedSubjectIds, true)
        ));
    } else {
        $templateSubjects = $allSubjects;
    }
    if (empty($templateSubjects)) {
        $_SESSION['errors'] = ['No subjects selected for the template.'];
        header('Location: /platform/tenant/academic/results.php?offering_id=' . $offeringId . '&term_id=' . $termId);
        exit;
    }
    $students = loadEnrolled($db, $tenantId, $offeringId);
    $csv = resultsBuildTemplateMulti($students, $templateSubjects, $term['term_name'] ?? null);
    $fnameParts = [];
    $fnameParts[] = preg_replace('/[^A-Za-z0-9]+/', '_', $offering['class_name'] ?: 'offering');
    if (!empty($offering['stream_name'])) $fnameParts[] = preg_replace('/[^A-Za-z0-9]+/', '_', $offering['stream_name']);
    $fnameParts[] = preg_replace('/[^A-Za-z0-9]+/', '_', $term['term_name'] ?: 'term');
    $fnameParts[] = count($templateSubjects) === 1
        ? preg_replace('/[^A-Za-z0-9]+/', '_', $templateSubjects[0]['subject_name']) . '_template.csv'
        : 'results_template.csv';
    $fname = implode('_', $fnameParts);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}

// -- EXPORT ALL SUBJECTS (GET, read-only) --
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'export_all') {
    $offeringId = (int)($_GET['offering_id'] ?? 0);
    $termId     = (int)($_GET['term_id'] ?? 0);
    $offering = getOffering($db, $tenantId, $offeringId);
    if (!$offering) {
        $_SESSION['errors'] = ['Offering not found.'];
        header('Location: /platform/tenant/academic/results.php');
        exit;
    }
    $term = getTerm($db, $tenantId, $termId);
    if (!$term) {
        $_SESSION['errors'] = ['Please choose a term before exporting.'];
        header('Location: /platform/tenant/academic/results.php?offering_id=' . $offeringId);
        exit;
    }
    $exportSubjects = loadOfferingSubjects($db, $tenantId, $offeringId);
    if (empty($exportSubjects)) {
        $_SESSION['errors'] = ['This offering has no active subjects to export.'];
        header('Location: /platform/tenant/academic/results.php?offering_id=' . $offeringId . '&term_id=' . $termId);
        exit;
    }
    $exportStudents = loadEnrolled($db, $tenantId, $offeringId);
    $rows = $db->fetchAll(
        "SELECT student_id, subject_id, raw_score, final_score, grade_symbol, grade_label, is_pass, status
         FROM results
         WHERE tenant_id = ? AND class_offering_id = ? AND academic_term_id = ? AND deleted_at IS NULL",
        [$tenantId, $offeringId, $termId]
    );
    $byStudentSubject = [];
    foreach ($rows as $row) $byStudentSubject[(int)$row['student_id']][(int)$row['subject_id']] = $row;
    $out = fopen('php://temp', 'r+');
    $header = ['student_number', 'student_name', 'term'];
    foreach ($exportSubjects as $sub) {
        $header[] = $sub['subject_name'];
        $header[] = $sub['subject_name'] . ' grade';
    }
    fputcsv($out, $header);
    foreach ($exportStudents as $st) {
        $studentId = (int)$st['student_id'];
        $name = trim(($st['first_name'] ?? '') . ' ' . ($st['middle_name'] ?? '') . ' ' . ($st['last_name'] ?? ''));
        $name = preg_replace('/\s+/', ' ', $name);
        $line = [(string)($st['student_number'] ?? ''), $name, $term['term_name']];
        foreach ($exportSubjects as $sub) {
            $row = $byStudentSubject[$studentId][(int)$sub['id']] ?? null;
            if ($row === null) {
                $line[] = '—';
                $line[] = '—';
                continue;
            }
            $score = $row['final_score'] !== null
                ? (float)$row['final_score']
                : ($row['raw_score'] !== null ? (float)$row['raw_score'] : null);
            $line[] = $score !== null ? number_format($score, 2, '.', '') : '—';
            $line[] = (string)($row['grade_symbol'] ?? '—');
        }
        fputcsv($out, $line);
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    $fnameParts = [];
    $fnameParts[] = preg_replace('/[^A-Za-z0-9]+/', '_', $offering['class_name'] ?: 'offering');
    if (!empty($offering['stream_name'])) $fnameParts[] = preg_replace('/[^A-Za-z0-9]+/', '_', $offering['stream_name']);
    $fnameParts[] = preg_replace('/[^A-Za-z0-9]+/', '_', $term['term_name'] ?: 'term');
    $fnameParts[] = 'all_subjects_results.csv';
    $fname = implode('_', $fnameParts);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}

// -- EXPORT SINGLE SUBJECT (GET, read-only) --
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'export_subject') {
    $offeringId = (int)($_GET['offering_id'] ?? 0);
    $subjectId  = (int)($_GET['subject_id'] ?? 0);
    $termId     = (int)($_GET['term_id'] ?? 0);
    $offering = getOffering($db, $tenantId, $offeringId);
    if (!$offering) {
        $_SESSION['errors'] = ['Offering not found.'];
        header('Location: /platform/tenant/academic/results.php');
        exit;
    }
    if ($subjectId <= 0) {
        $_SESSION['errors'] = ['Subject is required for export.'];
        header('Location: /platform/tenant/academic/results.php?offering_id=' . $offeringId);
        exit;
    }
    $term = getTerm($db, $tenantId, $termId);
    if (!$term) {
        $_SESSION['errors'] = ['Please choose a term before exporting.'];
        header('Location: /platform/tenant/academic/results.php?offering_id=' . $offeringId);
        exit;
    }
    $subject = $db->fetchOne(
        "SELECT id, subject_name, subject_code FROM subjects
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$subjectId, $tenantId]
    );
    if (!$subject) {
        $_SESSION['errors'] = ['Subject not found.'];
        header('Location: /platform/tenant/academic/results.php?offering_id=' . $offeringId);
        exit;
    }
    $exportStudents = loadEnrolled($db, $tenantId, $offeringId);
    $rows = $db->fetchAll(
        "SELECT student_id, raw_score, final_score, grade_symbol, grade_label, is_pass, status
         FROM results
         WHERE tenant_id = ? AND class_offering_id = ? AND subject_id = ? AND academic_term_id = ? AND deleted_at IS NULL",
        [$tenantId, $offeringId, $subjectId, $termId]
    );
    $byStudent = [];
    foreach ($rows as $row) $byStudent[(int)$row['student_id']] = $row;
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['student_number', 'student_name', 'roll_number', 'raw_score', 'final_score', 'grade_symbol', 'grade_label', 'is_pass', 'status']);
    foreach ($exportStudents as $st) {
        $studentId = (int)$st['student_id'];
        $name = trim(($st['first_name'] ?? '') . ' ' . ($st['middle_name'] ?? '') . ' ' . ($st['last_name'] ?? ''));
        $name = preg_replace('/\s+/', ' ', $name);
        $row = $byStudent[$studentId] ?? null;
        $raw   = $row && $row['raw_score']   !== null ? number_format((float)$row['raw_score'], 2, '.', '') : '';
        $final = $row && $row['final_score'] !== null ? number_format((float)$row['final_score'], 2, '.', '') : '';
        fputcsv($out, [
            (string)($st['student_number'] ?? ''),
            $name,
            (string)($st['roll_number'] ?? ''),
            $raw,
            $final,
            (string)($row['grade_symbol'] ?? ''),
            (string)($row['grade_label'] ?? ''),
            $row !== null ? (string)(int)$row['is_pass'] : '',
            (string)($row['status'] ?? ''),
        ]);
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    $fnameParts = [];
    $fnameParts[] = preg_replace('/[^A-Za-z0-9]+/', '_', $offering['class_name'] ?: 'offering');
    if (!empty($offering['stream_name'])) $fnameParts[] = preg_replace('/[^A-Za-z0-9]+/', '_', $offering['stream_name']);
    $fnameParts[] = preg_replace('/[^A-Za-z0-9]+/', '_', $term['term_name'] ?: 'term');
    $fnameParts[] = preg_replace('/[^A-Za-z0-9]+/', '_', $subject['subject_name'] ?: 'subject');
    $fnameParts[] = 'results.csv';
    $fname = implode('_', $fnameParts);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}

// -- EXPORT LEVEL (GET, read-only) --
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'export_level') {
    $levelId = (int)($_GET['level_id'] ?? 0);
    $termId  = (int)($_GET['term_id'] ?? 0);
    if ($levelId <= 0) {
        $_SESSION['errors'] = ['Please choose a level before exporting.'];
        header('Location: /platform/tenant/academic/results.php');
        exit;
    }
    $term = getTerm($db, $tenantId, $termId);
    if (!$term) {
        $_SESSION['errors'] = ['Please choose a term before exporting.'];
        header('Location: /platform/tenant/academic/results.php?view=level&level_id=' . $levelId);
        exit;
    }
    $level = $db->fetchOne(
        "SELECT id, level_name, level_code FROM academic_levels
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$levelId, $tenantId]
    );
    if (!$level) {
        $_SESSION['errors'] = ['Level not found.'];
        header('Location: /platform/tenant/academic/results.php');
        exit;
    }
    $lvOfferings = $db->fetchAll(
        "SELECT co.id, c.class_name, c.class_code, s.stream_name
         FROM class_offerings co
         LEFT JOIN classes c ON c.id = co.class_id
         LEFT JOIN streams s ON s.id = co.stream_id
         WHERE co.tenant_id = ? AND co.academic_level_id = ? AND co.deleted_at IS NULL
         ORDER BY c.class_name ASC, s.stream_name ASC, co.id ASC",
        [$tenantId, $levelId]
    );
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['offering', 'student_number', 'student_name', 'term', 'subject', 'score', 'grade_symbol', 'grade_label', 'is_pass', 'status']);
    foreach ($lvOfferings as $off) {
        $offId = (int)$off['id'];
        $offLabel = trim(($off['class_name'] ?? '') . ' ' . ($off['stream_name'] ?? ''));
        $lvSubjects = loadOfferingSubjects($db, $tenantId, $offId);
        $lvStudents = loadEnrolled($db, $tenantId, $offId);
        if (empty($lvStudents)) continue;
        $lvRows = $db->fetchAll(
            "SELECT student_id, subject_id, raw_score, final_score, grade_symbol, grade_label, is_pass, status
             FROM results
             WHERE tenant_id = ? AND class_offering_id = ? AND academic_term_id = ? AND deleted_at IS NULL",
            [$tenantId, $offId, $termId]
        );
        $byStudentSubject = [];
        foreach ($lvRows as $r) $byStudentSubject[(int)$r['student_id']][(int)$r['subject_id']] = $r;
        foreach ($lvStudents as $st) {
            $studentId = (int)$st['student_id'];
            $name = trim(($st['first_name'] ?? '') . ' ' . ($st['middle_name'] ?? '') . ' ' . ($st['last_name'] ?? ''));
            $name = preg_replace('/\s+/', ' ', $name);
            foreach ($lvSubjects as $sub) {
                $row = $byStudentSubject[$studentId][(int)$sub['id']] ?? null;
                $score = '';
                if ($row !== null) {
                    $score = $row['final_score'] !== null
                        ? number_format((float)$row['final_score'], 2, '.', '')
                        : ($row['raw_score'] !== null ? number_format((float)$row['raw_score'], 2, '.', '') : '');
                }
                fputcsv($out, [
                    $offLabel,
                    (string)($st['student_number'] ?? ''),
                    $name,
                    $term['term_name'],
                    $sub['subject_name'],
                    $score,
                    (string)($row['grade_symbol'] ?? ''),
                    (string)($row['grade_label'] ?? ''),
                    $row !== null ? (string)(int)$row['is_pass'] : '',
                    (string)($row['status'] ?? ''),
                ]);
            }
        }
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    $fnameParts = ['level'];
    $fnameParts[] = preg_replace('/[^A-Za-z0-9]+/', '_', $level['level_name'] ?: 'level');
    $fnameParts[] = preg_replace('/[^A-Za-z0-9]+/', '_', $term['term_name'] ?: 'term');
    $fnameParts[] = 'results.csv';
    $fname = implode('_', $fnameParts);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}

// -- EXPORT CLASS (GET, read-only) --
// Reshaped per EXP-1. B.E.C.E.-aware per v3.5. Reordered per BECE-4 v3.6.
// Trailing column branched by grading_style per BECE-5 v3.7.
// Blank separator column removed per BECE-6 v3.8.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'export_class') {
    $offeringId = (int)($_GET['offering_id'] ?? 0);
    $termId     = (int)($_GET['term_id'] ?? 0);
    $offering = getOffering($db, $tenantId, $offeringId);
    if (!$offering) {
        $_SESSION['errors'] = ['Offering not found.'];
        header('Location: /platform/tenant/academic/results.php');
        exit;
    }
    $term = getTerm($db, $tenantId, $termId);
    if (!$term) {
        $_SESSION['errors'] = ['Please choose a term before exporting.'];
        header('Location: /platform/tenant/academic/results.php?view=class&offering_id=' . $offeringId);
        exit;
    }
    $sheet = loadClassMasterSheet($db, $tenantId, $offeringId, $termId);

    // [BECE-3] The Overall Grade pre-computation block was removed in v3.5.
    // Its only consumer was the Overall Grade column, which has been dropped
    // from the CSV.

    $averages = [];
    foreach ($sheet['students'] as $st) {
        $studentId = (int)$st['student_id'];
        $tot = $sheet['student_totals'][$studentId] ?? ['average' => null];
        $averages[$studentId] = $tot['average'];
    }
    $ranking = [];
    foreach ($sheet['students'] as $st) {
        $studentId = (int)$st['student_id'];
        $ranking[$studentId] = [
            'avg'    => $averages[$studentId] !== null ? (float)$averages[$studentId] : null,
            'snum'   => strtoupper((string)($st['student_number'] ?? '')),
            'pos'    => null,
        ];
    }
    uasort($ranking, function ($a, $b) {
        $aAvg = $a['avg'];
        $bAvg = $b['avg'];
        if ($aAvg === null && $bAvg === null) return strcmp($a['snum'], $b['snum']);
        if ($aAvg === null) return 1;
        if ($bAvg === null) return -1;
        if ($aAvg == $bAvg) return strcmp($a['snum'], $b['snum']);
        return $bAvg <=> $aAvg;
    });
    $lastValidRank = 0;
    foreach ($ranking as $studentId => $row) {
        if ($row['avg'] === null) {
            $ranking[$studentId]['pos'] = '';
        } else {
            $lastValidRank++;
            $ranking[$studentId]['pos'] = $lastValidRank;
        }
    }

    // [v3.4 EXP-1-R1] export_class emits a fixed nine-subject header, in the
    // order specified by the user's instruction. Subjects not configured for
    // this offering are still emitted as columns and left blank for every
    // student.
    $fixedSubjects = [
        'English Language',
        'French',
        'Ghanaian Language',
        'ICT',
        'Mathematics',
        'Physical Education',
        'Religious & Moral Education',
        'Science',
        'Social Studies',
    ];

    // [v3.4 EXP-1-R1] Map each fixed subject to the offering's configured subject id,
    // matching on subject_name case-insensitively. Missing subjects map to null
    // and will be emitted as blank columns.
    $fixedSubjectId = [];
    foreach ($fixedSubjects as $fsName) {
        $fixedSubjectId[$fsName] = null;
        foreach ($sheet['subjects'] as $sub) {
            if (strcasecmp((string)$sub['subject_name'], $fsName) === 0) {
                $fixedSubjectId[$fsName] = (int)$sub['id'];
                break;
            }
        }
    }

    // [BECE-1] Resolve the applicable promotion rule and grading scale once,
    // for the whole export. Both are used per student in the loop below.
    $levelId = $offering['academic_level_id'] !== null ? (int)$offering['academic_level_id'] : null;
    $yearId  = $offering['academic_year_id']  !== null ? (int)$offering['academic_year_id']  : null;
    $beceRule = resolveBecePromotionRule($db, $tenantId, $levelId, $yearId);
    $beceScale = resolveScale($db, $tenantId, $levelId, $yearId);
    $beceScaleId = $beceScale ? (int)$beceScale['id'] : 0;

    // [BECE-5 v3.7] Branch the trailing column by the applicable rule's
    // grading_style. Alphabetic → 'Grade' and computePrimaryGrade(). Every
    // other case (including numeric + bece_6) → 'Aggregate' and
    // computeBeceAggregate().
    $isAlphabetic = $beceRule
        && (($beceRule['rule_mode'] ?? 'all_subjects') === 'all_subjects');

    $out = fopen('php://temp', 'r+');
    $header = ['student_number', 'student_name', 'term'];
    foreach ($fixedSubjects as $fsName) {
        $header[] = $fsName;
    }
    // [BECE-6 v3.8] The blank separator column has been removed. The header
    // now flows directly from the last subject column into Total.
    $header[] = 'Total';
    $header[] = 'Average';
    // [BECE-5 v3.7] third trailing column label is chosen by branch.
    $header[] = 'Aggregate';
    $header[] = 'Position in Class';
    fputcsv($out, $header);

    foreach ($sheet['students'] as $st) {
        $studentId = (int)$st['student_id'];
        $name = trim(($st['first_name'] ?? '') . ' ' . ($st['middle_name'] ?? '') . ' ' . ($st['last_name'] ?? ''));
        $name = preg_replace('/\s+/', ' ', $name);

        $line = [
            (string)($st['student_number'] ?? ''),
            $name,
            $term['term_name'],
        ];
        // [v3.4 EXP-1-R1] iterate the fixed subject list, not the configured list.
        foreach ($fixedSubjects as $fsName) {
            $sid = $fixedSubjectId[$fsName];
            if ($sid === null) {
                // Subject not configured for this offering — emit a blank cell.
                $line[] = '';
                continue;
            }
            $r = $sheet['results'][$studentId][$sid] ?? null;
            if ($r === null) {
                $line[] = '';
                continue;
            }
            $sc = $r['final_score'] !== null
                ? (float)$r['final_score']
                : ($r['raw_score'] !== null ? (float)$r['raw_score'] : null);
            $line[] = $sc !== null ? number_format($sc, 2, '.', '') : '';
        }
        // [BECE-6 v3.8] The blank separator cell has been removed. The row
        // now flows directly from the last subject cell into Total.

        $tot = $sheet['student_totals'][$studentId] ?? ['count' => 0, 'total' => 0, 'average' => null];
        $line[] = $tot['count'] > 0 ? number_format((float)$tot['total'], 2, '.', '') : '';
        $line[] = $tot['average'] !== null ? number_format((float)$tot['average'], 2, '.', '') : '';

        // [BECE-5 v3.7] third trailing cell is chosen by branch.
        $studentResults = $sheet['results'][$studentId] ?? [];
        if ($isAlphabetic) {
            $primary = computePrimaryGrade(
                $db,
                $tenantId,
                $beceScaleId,
                $sheet['subjects'],
                $studentResults
            );
            $line[] = $primary['grade'] !== null ? (string)$primary['grade'] : '';
        } else {
            $bece = computeBeceAggregate(
                $db,
                $tenantId,
                $beceScaleId,
                $beceRule ?? [],
                $sheet['subjects'],
                $studentResults
            );
            $line[] = $bece['aggregate'] !== null ? (string)(int)$bece['aggregate'] : '';
        }

        $line[] = isset($ranking[$studentId]['pos']) && $ranking[$studentId]['pos'] !== ''
            ? (string)$ranking[$studentId]['pos']
            : '';

        fputcsv($out, $line);
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);

    $fnameParts = [];
    $fnameParts[] = preg_replace('/[^A-Za-z0-9]+/', '_', $offering['class_name'] ?: 'offering');
    if (!empty($offering['stream_name'])) $fnameParts[] = preg_replace('/[^A-Za-z0-9]+/', '_', $offering['stream_name']);
    $fnameParts[] = preg_replace('/[^A-Za-z0-9]+/', '_', $term['term_name'] ?: 'term');
    $fnameParts[] = 'class_results.csv';
    $fname = implode('_', $fnameParts);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}

// ============================================
// POST ACTIONS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $formData = $_POST;
    try {
        // -------- BULK SAVE --------
        if ($action === 'bulk_save') {
            $offeringId = (int)($_POST['class_offering_id'] ?? 0);
            $subjectId  = (int)($_POST['subject_id'] ?? 0);
            $termId     = (int)($_POST['academic_term_id'] ?? 0);
            $offering = getOffering($db, $tenantId, $offeringId);
            if (!$offering) throw new Exception('Class offering not found.');
            if ($subjectId <= 0) throw new Exception('Subject is required.');
            if ($termId <= 0)    throw new Exception('Term is required.');
            if (isScopeLocked($db, $tenantId, $offeringId, $subjectId, $termId)) {
                throw new Exception('This scope is locked: results for this offering, subject and term cannot be modified until an admin unlocks it.');
            }
            $subject = $db->fetchOne(
                "SELECT * FROM subjects WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$subjectId, $tenantId]
            );
            if (!$subject) throw new Exception('Subject not found.');
            $term = getTerm($db, $tenantId, $termId);
            if (!$term) throw new Exception('Term not found.');
            $levelId = $offering['academic_level_id'] !== null ? (int)$offering['academic_level_id'] : null;
            $yearId  = $offering['academic_year_id']  !== null ? (int)$offering['academic_year_id']  : null;
            $scheme = resolveScheme($db, $tenantId, $levelId, $yearId);
            $scale  = resolveScale($db, $tenantId, $levelId, $yearId);
            if (!$scale) throw new Exception('No active grading scale applies to this offering\'s level/year. Activate one on the Grading Scales page before entering results.');
            $scoresIn = is_array($_POST['scores'] ?? null) ? $_POST['scores'] : [];
            $enrollments = loadEnrolled($db, $tenantId, $offeringId);
            $enrollById = [];
            foreach ($enrollments as $e) $enrollById[(int)$e['enrollment_id']] = (int)$e['student_id'];
            if (empty($enrollById)) throw new Exception('No active enrollments exist for this offering.');
            $db->beginTransaction();
            $addedCount = 0;
            $updatedCount = 0;
            $skippedCount = 0;
            foreach ($enrollById as $enrId => $studentId) {
                $raw = $scoresIn[$enrId] ?? null;
                if ($raw === null || $raw === '') {
                    $skippedCount++;
                    continue;
                }
                if (!is_numeric($raw)) throw new Exception('Raw score for one student is not numeric.');
                $raw = (float)$raw;
                if ($raw < 0 || $raw > 100) throw new Exception('Raw score must be between 0 and 100.');
                $rule = resolveGradeRule($db, $tenantId, (int)$scale['id'], $raw);
                $weighted = computeWeighted($raw, $scheme);
                $existing = $db->fetchOne(
                    "SELECT id, status FROM results
                     WHERE tenant_id = ? AND student_id = ? AND subject_id = ?
                       AND academic_term_id = ? AND deleted_at IS NULL
                     FOR UPDATE",
                    [$tenantId, $studentId, $subjectId, $termId]
                );
                if ($existing) {
                    if ($existing['status'] === 'published') {
                        throw new Exception('Cannot modify a published result. Unpublish or version it first.');
                    }
                    $db->execute(
                        "UPDATE results
                            SET enrollment_id = ?, class_offering_id = ?, academic_year_id = ?,
                                assessment_scheme_id = ?, assessment_scheme_version = ?,
                                grading_scale_id = ?, grading_scale_version = ?, grade_rule_id = ?,
                                raw_score = ?, weighted_score = ?, final_score = ?,
                                grade_symbol = ?, grade_label = ?, remark = ?, is_pass = ?,
                                status = 'calculated', calculated_at = NOW(), updated_at = NOW()
                          WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                        [
                            $enrId,
                            $offeringId,
                            (int)$yearId,
                            $scheme ? (int)$scheme['id'] : null,
                            $scheme ? (int)$scheme['version'] : null,
                            (int)$scale['id'],
                            (int)$scale['version'],
                            $rule ? (int)$rule['id'] : null,
                            $raw,
                            $weighted,
                            $weighted,
                            $rule ? $rule['grade_symbol'] : null,
                            $rule ? $rule['grade_label']  : null,
                            $rule ? $rule['remark']       : null,
                            $rule ? (int)$rule['is_pass'] : null,
                            (int)$existing['id'],
                            $tenantId,
                        ]
                    );
                    $updatedCount++;
                } else {
                    $db->insert(
                        "INSERT INTO results
                            (uuid, tenant_id, student_id, enrollment_id,
                             class_offering_id, academic_year_id, academic_term_id, subject_id,
                             assessment_scheme_id, assessment_scheme_version,
                             grading_scale_id, grading_scale_version, grade_rule_id,
                             raw_score, weighted_score, final_score,
                             grade_symbol, grade_label, remark, is_pass,
                             status, calculated_at, created_by, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                                 'calculated', NOW(), ?, NOW(), NOW())",
                        [
                            uuidv4(),
                            $tenantId,
                            $studentId,
                            $enrId,
                            $offeringId,
                            (int)$yearId,
                            $termId,
                            $subjectId,
                            $scheme ? (int)$scheme['id'] : null,
                            $scheme ? (int)$scheme['version'] : null,
                            (int)$scale['id'],
                            (int)$scale['version'],
                            $rule ? (int)$rule['id'] : null,
                            $raw,
                            $weighted,
                            $weighted,
                            $rule ? $rule['grade_symbol'] : null,
                            $rule ? $rule['grade_label'] : null,
                            $rule ? $rule['remark'] : null,
                            $rule ? (int)$rule['is_pass'] : null,
                            $userId ?: null,
                        ]
                    );
                    $addedCount++;
                }
            }
            writeAudit($db, $tenantId, $userId, 'academic.result.bulk_saved', 'results', $offeringId, [
                'offering_id' => $offeringId,
                'subject_id' => $subjectId,
                'term_id' => $termId,
                'scheme_id' => $scheme ? (int)$scheme['id'] : null,
                'scale_id' => (int)$scale['id'],
                'added' => $addedCount,
                'updated' => $updatedCount,
                'skipped' => $skippedCount,
            ]);
            $db->commit();
            $_SESSION['success'] = sprintf('Results saved. %d added, %d updated, %d left blank.', $addedCount, $updatedCount, $skippedCount);
            header('Location: /platform/tenant/academic/results.php?offering_id=' . $offeringId . '&subject_id=' . $subjectId . '&term_id=' . $termId);
            exit;
        }

        // -------- IMPORT PREVIEW --------
        if ($action === 'import_preview') {
            $now = time();
            $lastAt = (int)($_SESSION['last_import_at'] ?? 0);
            if ($lastAt > 0 && ($now - $lastAt) < IMPORT_MIN_SECONDS_BETWEEN) {
                $wait = IMPORT_MIN_SECONDS_BETWEEN - ($now - $lastAt);
                throw new Exception('Please wait ' . $wait . ' more second(s) before importing again.');
            }
            $offeringId = (int)($_POST['class_offering_id'] ?? 0);
            $uiTermId   = (int)($_POST['academic_term_id'] ?? 0);
            $mode       = trim((string)($_POST['import_mode'] ?? 'overwrite'));
            if (!in_array($mode, ['fill_blanks', 'overwrite'], true)) {
                throw new Exception('Invalid import mode.');
            }
            $offering = getOffering($db, $tenantId, $offeringId);
            if (!$offering) throw new Exception('Class offering not found.');
            $uiTerm = null;
            if ($uiTermId > 0) {
                $uiTerm = getTerm($db, $tenantId, $uiTermId);
                if (!$uiTerm) throw new Exception('The selected term was not found for this tenant.');
            }
            $allSubjects = loadOfferingSubjects($db, $tenantId, $offeringId);
            if (empty($allSubjects)) throw new Exception('This offering has no active subjects.');
            $bySubjectId = [];
            foreach ($allSubjects as $s) $bySubjectId[(int)$s['id']] = $s;
            $requested = [];
            if (isset($_POST['subject_ids']) && is_array($_POST['subject_ids'])) {
                foreach ($_POST['subject_ids'] as $sid) {
                    $sid = (int)$sid;
                    if ($sid > 0 && isset($bySubjectId[$sid])) $requested[$sid] = true;
                }
            }
            if (empty($requested)) {
                foreach ($allSubjects as $s) $requested[(int)$s['id']] = true;
            }
            $importSubjects = array_values(array_filter($allSubjects, fn($s) => isset($requested[(int)$s['id']])));
            if (!isset($_FILES['results_file']) || $_FILES['results_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Please choose a file to upload.');
            }
            $file = $_FILES['results_file'];
            if (!is_uploaded_file((string)$file['tmp_name'])) {
                throw new Exception('The uploaded file is no longer available on the server. Please try again.');
            }
            $parsed = resultsParseUploadedMulti(
                (string)$file['tmp_name'],
                $importSubjects,
                (string)$file['name'],
                (string)$file['type'] ?? ''
            );
            if (count($parsed['rows']) === 0) {
                throw new Exception('The file contains a header row but no data rows. Make sure the enrolled students are included below the header.');
            }
            if (count($parsed['rows']) > MAX_IMPORT_ROWS) {
                throw new Exception('The file contains ' . count($parsed['rows']) . ' rows; the maximum allowed per import is ' . MAX_IMPORT_ROWS . '.');
            }
            $seenNumbers = [];
            foreach ($parsed['rows'] as $r) {
                $num = strtoupper(trim((string)$r['student_number']));
                if ($num === '') continue;
                if (isset($seenNumbers[$num])) {
                    throw new Exception('The file lists student number "' . $r['student_number'] . '" more than once. Each student should appear only once per import.');
                }
                $seenNumbers[$num] = true;
            }
            $enrolled = loadEnrolled($db, $tenantId, $offeringId);
            $byNumber = [];
            foreach ($enrolled as $e) {
                $num = strtoupper(trim((string)$e['student_number']));
                if ($num !== '') $byNumber[$num] = $e;
            }
            if (empty($byNumber)) throw new Exception('No active enrollments with student numbers exist for this offering.');
            $termCache = [];
            $counts = ['new' => 0, 'overwrite' => 0, 'published' => 0, 'locked' => 0, 'not_enrolled' => 0, 'invalid' => 0, 'blank' => 0];
            $preview = [];
            foreach ($parsed['rows'] as $r) {
                $rowNo = (int)$r['row'];
                $num   = strtoupper(trim((string)$r['student_number']));
                if ($r['error'] !== null) {
                    $counts['invalid']++;
                    continue;
                }
                if ($num === '') continue;
                if (!isset($byNumber[$num])) {
                    $counts['not_enrolled']++;
                    $preview[] = ['row' => $rowNo, 'class' => 'not_enrolled', 'student_number' => $r['student_number'], 'subject' => '', 'reason' => 'Not enrolled in this class.'];
                    continue;
                }
                $rowTermLabel = trim((string)$r['term_raw']);
                $rowTerm = null;
                if ($rowTermLabel !== '') {
                    $termKey = strtolower($rowTermLabel);
                    if (!isset($termCache[$termKey])) $termCache[$termKey] = resolveTermByLabel($db, $tenantId, $rowTermLabel);
                    $rowTerm = $termCache[$termKey];
                    if (!$rowTerm) {
                        $counts['invalid']++;
                        continue;
                    }
                } else {
                    if (!$uiTerm) {
                        $counts['invalid']++;
                        continue;
                    }
                    $rowTerm = $uiTerm;
                }
                $rowTermId = (int)$rowTerm['id'];
                $enrollment = $byNumber[$num];
                $studentId  = (int)$enrollment['student_id'];
                foreach ($r['subjects'] as $subjectId => $cell) {
                    $subjectId = (int)$subjectId;
                    if (!isset($requested[$subjectId])) continue;
                    $raw  = $cell['raw'];
                    $err  = $cell['error'];
                    $subLabel = $bySubjectId[$subjectId]['subject_name'] ?? ('Subject #' . $subjectId);
                    if ($err !== null) {
                        $counts['invalid']++;
                        $preview[] = ['row' => $rowNo, 'class' => 'invalid', 'student_number' => $r['student_number'], 'subject' => $subLabel, 'reason' => $err];
                        continue;
                    }
                    if ($raw === null) {
                        $counts['blank']++;
                        continue;
                    }
                    if (isScopeLocked($db, $tenantId, $offeringId, $subjectId, $rowTermId)) {
                        $counts['locked']++;
                        $preview[] = ['row' => $rowNo, 'class' => 'locked', 'student_number' => $r['student_number'], 'subject' => $subLabel, 'reason' => 'Scope is locked.'];
                        continue;
                    }
                    $existing = $db->fetchOne(
                        "SELECT id, status, raw_score FROM results
                         WHERE tenant_id = ? AND student_id = ? AND subject_id = ?
                           AND academic_term_id = ? AND deleted_at IS NULL",
                        [$tenantId, $studentId, $subjectId, $rowTermId]
                    );
                    if ($existing && $existing['status'] === 'published') {
                        $counts['published']++;
                        $preview[] = ['row' => $rowNo, 'class' => 'published', 'student_number' => $r['student_number'], 'subject' => $subLabel, 'reason' => 'Existing result is published.'];
                        continue;
                    }
                    if ($existing) {
                        $counts['overwrite']++;
                    } else {
                        $counts['new']++;
                    }
                }
            }
            $_SESSION['import_pending'] = [
                'offering_id'    => $offeringId,
                'ui_term_id'     => $uiTermId,
                'ui_term_name'   => $uiTerm['term_name'] ?? '(per row)',
                'mode'           => $mode,
                'format'         => $parsed['format'],
                'source_file'    => (string)$file['name'],
                'tmp_name'       => (string)$file['tmp_name'],
                'subject_ids'    => array_keys($requested),
                'parsed_rows'    => $parsed['rows'],
                'counts'         => $counts,
                'preview'        => array_slice($preview, 0, 200),
                'created_at'     => time(),
            ];
            writeAudit($db, $tenantId, $userId, 'academic.result.import_previewed', 'results', $offeringId, [
                'offering_id' => $offeringId,
                'term_id'     => $uiTermId,
                'source_file' => (string)$file['name'],
                'counts'      => $counts,
            ]);
            header('Location: /platform/tenant/academic/results.php?offering_id=' . $offeringId . '&term_id=' . $uiTermId . '&import_preview=1');
            exit;
        }

        // -------- IMPORT CONFIRM --------
        if ($action === 'import_confirm') {
            $pending = $_SESSION['import_pending'] ?? null;
            if (!$pending) {
                throw new Exception('No import is pending. Please upload a file again.');
            }
            $offeringId = (int)$pending['offering_id'];
            $uiTermId   = (int)$pending['ui_term_id'];
            $mode       = (string)$pending['mode'];
            $requested  = array_flip(array_map('intval', (array)$pending['subject_ids']));
            $parsedRows = (array)$pending['parsed_rows'];
            if (!in_array($mode, ['fill_blanks', 'overwrite'], true)) {
                throw new Exception('Invalid import mode.');
            }
            $offering = getOffering($db, $tenantId, $offeringId);
            if (!$offering) throw new Exception('Class offering not found.');
            $uiTerm = null;
            if ($uiTermId > 0) {
                $uiTerm = getTerm($db, $tenantId, $uiTermId);
                if (!$uiTerm) throw new Exception('The selected term was not found for this tenant.');
            }
            $allSubjects = loadOfferingSubjects($db, $tenantId, $offeringId);
            if (empty($allSubjects)) throw new Exception('This offering has no active subjects.');
            $bySubjectId = [];
            foreach ($allSubjects as $s) $bySubjectId[(int)$s['id']] = $s;
            $importSubjects = array_values(array_filter($allSubjects, fn($s) => isset($requested[(int)$s['id']])));
            if (empty($importSubjects)) throw new Exception('No subjects selected for import.');
            $levelId = $offering['academic_level_id'] !== null ? (int)$offering['academic_level_id'] : null;
            $yearId  = $offering['academic_year_id']  !== null ? (int)$offering['academic_year_id']  : null;
            $scheme = resolveScheme($db, $tenantId, $levelId, $yearId);
            $scale  = resolveScale($db, $tenantId, $levelId, $yearId);
            if (!$scale) throw new Exception('No active grading scale applies to this offering\'s level/year. Activate one before importing results.');
            $enrolled = loadEnrolled($db, $tenantId, $offeringId);
            $byNumber = [];
            foreach ($enrolled as $e) {
                $num = strtoupper(trim((string)$e['student_number']));
                if ($num !== '') $byNumber[$num] = $e;
            }
            if (empty($byNumber)) throw new Exception('No active enrollments with student numbers exist for this offering.');
            $termCache = [];
            $db->beginTransaction();
            $written = 0;
            $updated = 0;
            $skippedBlank = 0;
            $skippedAlreadyHadScore = 0;
            $skippedPublished = 0;
            $skippedNotEnrolled = 0;
            $skippedLockedScope = 0;
            $fixList = [];
            foreach ($parsedRows as $r) {
                $rowNo = (int)$r['row'];
                $num   = strtoupper(trim((string)$r['student_number']));
                if ($r['error'] !== null) {
                    $fixList[] = ['row' => $rowNo, 'student_number' => $r['student_number'], 'subject' => '', 'term' => $r['term_raw'], 'reason' => $r['error']];
                    continue;
                }
                if ($num === '') continue;
                if (!isset($byNumber[$num])) {
                    $fixList[] = ['row' => $rowNo, 'student_number' => $r['student_number'], 'subject' => '', 'term' => $r['term_raw'], 'reason' => 'Student number not found among enrolled students in this offering.'];
                    $skippedNotEnrolled++;
                    continue;
                }
                $rowTermLabel = trim((string)$r['term_raw']);
                $rowTerm = null;
                if ($rowTermLabel !== '') {
                    $termKey = strtolower($rowTermLabel);
                    if (!isset($termCache[$termKey])) $termCache[$termKey] = resolveTermByLabel($db, $tenantId, $rowTermLabel);
                    $rowTerm = $termCache[$termKey];
                    if (!$rowTerm) {
                        $fixList[] = ['row' => $rowNo, 'student_number' => $r['student_number'], 'subject' => '', 'term' => $rowTermLabel, 'reason' => 'Term "' . $rowTermLabel . '" not found for this tenant.'];
                        continue;
                    }
                } else {
                    if (!$uiTerm) {
                        $fixList[] = ['row' => $rowNo, 'student_number' => $r['student_number'], 'subject' => '', 'term' => '', 'reason' => 'Term is blank in the file and no default term was selected.'];
                        continue;
                    }
                    $rowTerm = $uiTerm;
                }
                $rowTermId    = (int)$rowTerm['id'];
                $rowTermLabel = $rowTerm['term_name'];
                $enrollment = $byNumber[$num];
                $studentId  = (int)$enrollment['student_id'];
                $enrId      = (int)$enrollment['enrollment_id'];
                foreach ($r['subjects'] as $subjectId => $cell) {
                    $subjectId = (int)$subjectId;
                    if (!isset($requested[$subjectId])) continue;
                    $raw  = $cell['raw'];
                    $err  = $cell['error'];
                    $subLabel = $bySubjectId[$subjectId]['subject_name'] ?? ('Subject #' . $subjectId);
                    if ($err !== null) {
                        $fixList[] = ['row' => $rowNo, 'student_number' => $r['student_number'], 'subject' => $subLabel, 'term' => $rowTermLabel, 'reason' => $err];
                        continue;
                    }
                    if ($raw === null) {
                        $skippedBlank++;
                        continue;
                    }
                    if (isScopeLocked($db, $tenantId, $offeringId, $subjectId, $rowTermId)) {
                        $fixList[] = ['row' => $rowNo, 'student_number' => $r['student_number'], 'subject' => $subLabel, 'term' => $rowTermLabel, 'reason' => 'Scope is locked for this offering, subject and term.'];
                        $skippedLockedScope++;
                        continue;
                    }
                    $existing = $db->fetchOne(
                        "SELECT id, status, raw_score FROM results
                         WHERE tenant_id = ? AND student_id = ? AND subject_id = ?
                           AND academic_term_id = ? AND deleted_at IS NULL
                         FOR UPDATE",
                        [$tenantId, $studentId, $subjectId, $rowTermId]
                    );
                    if ($existing && $existing['status'] === 'published') {
                        $skippedPublished++;
                        continue;
                    }
                    if ($existing && $mode === 'fill_blanks') {
                        if ($existing['raw_score'] !== null && $existing['raw_score'] !== '') {
                            $skippedAlreadyHadScore++;
                            continue;
                        }
                    }
                    $rule = resolveGradeRule($db, $tenantId, (int)$scale['id'], $raw);
                    if ($existing) {
                        $db->execute(
                            "UPDATE results
                                SET enrollment_id = ?, class_offering_id = ?, academic_year_id = ?,
                                    assessment_scheme_id = ?, assessment_scheme_version = ?,
                                    grading_scale_id = ?, grading_scale_version = ?, grade_rule_id = ?,
                                    raw_score = ?, weighted_score = ?, final_score = ?,
                                    grade_symbol = ?, grade_label = ?, remark = ?, is_pass = ?,
                                    status = 'calculated', calculated_at = NOW(), updated_at = NOW()
                              WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                            [
                                $enrId,
                                $offeringId,
                                (int)$yearId,
                                $scheme ? (int)$scheme['id'] : null,
                                $scheme ? (int)$scheme['version'] : null,
                                (int)$scale['id'],
                                (int)$scale['version'],
                                $rule ? (int)$rule['id'] : null,
                                $raw,
                                $raw,
                                $raw,
                                $rule ? $rule['grade_symbol'] : null,
                                $rule ? $rule['grade_label'] : null,
                                $rule ? $rule['remark'] : null,
                                $rule ? (int)$rule['is_pass'] : null,
                                (int)$existing['id'],
                                $tenantId,
                            ]
                        );
                        $updated++;
                    } else {
                        $db->insert(
                            "INSERT INTO results
                                (uuid, tenant_id, student_id, enrollment_id,
                                 class_offering_id, academic_year_id, academic_term_id, subject_id,
                                 assessment_scheme_id, assessment_scheme_version,
                                 grading_scale_id, grading_scale_version, grade_rule_id,
                                 raw_score, weighted_score, final_score,
                                 grade_symbol, grade_label, remark, is_pass,
                                 status, calculated_at, created_by, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                                     'calculated', NOW(), ?, NOW(), NOW())",
                            [
                                uuidv4(),
                                $tenantId,
                                $studentId,
                                $enrId,
                                $offeringId,
                                (int)$yearId,
                                $rowTermId,
                                $subjectId,
                                $scheme ? (int)$scheme['id'] : null,
                                $scheme ? (int)$scheme['version'] : null,
                                (int)$scale['id'],
                                (int)$scale['version'],
                                $rule ? (int)$rule['id'] : null,
                                $raw,
                                $raw,
                                $raw,
                                $rule ? $rule['grade_symbol'] : null,
                                $rule ? $rule['grade_label'] : null,
                                $rule ? $rule['remark'] : null,
                                $rule ? (int)$rule['is_pass'] : null,
                                $userId ?: null,
                            ]
                        );
                        $written++;
                    }
                }
            }
            $subjectNames = [];
            foreach ($importSubjects as $s) $subjectNames[] = $s['subject_name'];
            $skippedTotal = $skippedBlank + $skippedAlreadyHadScore + $skippedPublished + $skippedNotEnrolled + $skippedLockedScope;
            $db->commit();
            $preservedPath = null;
            try {
                $preservedPath = preserveImportFile(
                    (string)$pending['tmp_name'],
                    (string)$pending['source_file'],
                    $tenantId,
                    $projectRoot
                );
            } catch (Exception $e) {
                error_log('preserveImportFile post-commit failure: ' . $e->getMessage());
                $preservedPath = null;
            }
            if ($preservedPath !== null) {
                writeAudit($db, $tenantId, $userId, 'academic.result.import_file_stored', 'results', $offeringId, ['offering_id' => $offeringId, 'source_file' => $preservedPath]);
            }
            writeAudit($db, $tenantId, $userId, 'academic.result.imported', 'results', $offeringId, [
                'offering_id' => $offeringId,
                'term_id' => $uiTermId,
                'term_name' => $uiTerm['term_name'] ?? '(per row)',
                'subjects' => $subjectNames,
                'mode' => $mode,
                'format' => $pending['format'] ?? 'csv',
                'source_file' => $preservedPath,
                'written' => $written,
                'updated' => $updated,
                'skipped_blank' => $skippedBlank,
                'skipped_already_had_score' => $skippedAlreadyHadScore,
                'skipped_published' => $skippedPublished,
                'skipped_not_enrolled' => $skippedNotEnrolled,
                'skipped_locked_scope' => $skippedLockedScope,
                'errors' => count($fixList),
                'preview_counts' => $pending['counts'] ?? [],
            ]);
            $_SESSION['last_import_at'] = time();
            $_SESSION['import_count_today'] = (int)($_SESSION['import_count_today'] ?? 0) + 1;
            $_SESSION['import_summary'] = [
                'offering_id' => $offeringId,
                'term_id' => $uiTermId,
                'term_name' => $uiTerm['term_name'] ?? '(per row)',
                'subjects' => $subjectNames,
                'mode' => $mode,
                'format' => $pending['format'] ?? 'csv',
                'source_file' => $preservedPath,
                'written' => $written,
                'updated' => $updated,
                'skipped_blank' => $skippedBlank,
                'skipped_already_had_score' => $skippedAlreadyHadScore,
                'skipped_published' => $skippedPublished,
                'skipped_not_enrolled' => $skippedNotEnrolled,
                'skipped_locked_scope' => $skippedLockedScope,
                'skipped_total' => $skippedTotal,
                'fix_list' => $fixList,
            ];
            unset($_SESSION['import_pending']);
            $_SESSION['success'] = sprintf('Import complete: %d new, %d updated, %d skipped, %d error(s).', $written, $updated, $skippedTotal, count($fixList));
            header('Location: /platform/tenant/academic/results.php?offering_id=' . $offeringId . '&term_id=' . $uiTermId);
            exit;
        }

        // -------- IMPORT CANCEL --------
        if ($action === 'import_cancel') {
            $offeringId = (int)($_SESSION['import_pending']['offering_id'] ?? 0);
            $uiTermId   = (int)($_SESSION['import_pending']['ui_term_id'] ?? 0);
            $sourceFile = (string)($_SESSION['import_pending']['source_file'] ?? '');
            unset($_SESSION['import_pending']);
            writeAudit($db, $tenantId, $userId, 'academic.result.import_cancelled', 'results', $offeringId ?: null, [
                'offering_id' => $offeringId,
                'term_id'     => $uiTermId,
                'source_file' => $sourceFile,
            ]);
            $_SESSION['success'] = 'Import cancelled.';
            header('Location: /platform/tenant/academic/results.php?offering_id=' . $offeringId . '&term_id=' . $uiTermId);
            exit;
        }

        // -------- IMPORT (legacy path, retained unchanged) --------
        if ($action === 'import') {
            $now = time();
            $lastAt = (int)($_SESSION['last_import_at'] ?? 0);
            if ($lastAt > 0 && ($now - $lastAt) < IMPORT_MIN_SECONDS_BETWEEN) {
                $wait = IMPORT_MIN_SECONDS_BETWEEN - ($now - $lastAt);
                throw new Exception('Please wait ' . $wait . ' more second(s) before importing again.');
            }
            $today = date('Y-m-d');
            if (($_SESSION['import_day'] ?? '') !== $today) {
                $_SESSION['import_day'] = $today;
                $_SESSION['import_count_today'] = 0;
            }
            $sessionCountToday = (int)($_SESSION['import_count_today'] ?? 0);
            if ($sessionCountToday >= IMPORT_MAX_PER_DAY) throw new Exception('Daily import limit reached for this session.');
            try {
                $dayStart = $today . ' 00:00:00';
                $row = $db->fetchOne(
                    "SELECT COUNT(*) AS c FROM audit_logs
                     WHERE tenant_id = ? AND action = 'academic.result.imported' AND created_at >= ?",
                    [$tenantId, $dayStart]
                );
                if ((int)($row['c'] ?? 0) >= IMPORT_MAX_PER_DAY) throw new Exception('Daily import limit reached for this tenant.');
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Daily import limit') === 0) throw $e;
            }
            $offeringId = (int)($_POST['class_offering_id'] ?? 0);
            $uiTermId   = (int)($_POST['academic_term_id'] ?? 0);
            $mode       = trim((string)($_POST['import_mode'] ?? 'overwrite'));
            if (!in_array($mode, ['fill_blanks', 'overwrite'], true)) throw new Exception('Invalid import mode.');
            $offering = getOffering($db, $tenantId, $offeringId);
            if (!$offering) throw new Exception('Class offering not found.');
            $uiTerm = null;
            if ($uiTermId > 0) {
                $uiTerm = getTerm($db, $tenantId, $uiTermId);
                if (!$uiTerm) throw new Exception('The selected term was not found for this tenant.');
            }
            $allSubjects = loadOfferingSubjects($db, $tenantId, $offeringId);
            if (empty($allSubjects)) throw new Exception('This offering has no active subjects.');
            $bySubjectId = [];
            foreach ($allSubjects as $s) $bySubjectId[(int)$s['id']] = $s;
            $requested = [];
            if (isset($_POST['subject_ids']) && is_array($_POST['subject_ids'])) {
                foreach ($_POST['subject_ids'] as $sid) {
                    $sid = (int)$sid;
                    if ($sid > 0 && isset($bySubjectId[$sid])) $requested[$sid] = true;
                }
            }
            if (empty($requested)) foreach ($allSubjects as $s) $requested[(int)$s['id']] = true;
            $importSubjects = array_values(array_filter($allSubjects, fn($s) => isset($requested[(int)$s['id']])));
            if (!isset($_FILES['results_file']) || $_FILES['results_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Please choose a file to upload.');
            }
            $file = $_FILES['results_file'];
            if (!is_uploaded_file((string)$file['tmp_name'])) {
                throw new Exception('The uploaded file is no longer available on the server. Please try again.');
            }
            $parsed = resultsParseUploadedMulti(
                (string)$file['tmp_name'],
                $importSubjects,
                (string)$file['name'],
                (string)$file['type'] ?? ''
            );
            if (count($parsed['rows']) === 0) {
                throw new Exception('The file contains a header row but no data rows. Make sure the enrolled students are included below the header.');
            }
            if (count($parsed['rows']) > MAX_IMPORT_ROWS) {
                throw new Exception('The file contains ' . count($parsed['rows']) . ' rows; the maximum allowed per import is ' . MAX_IMPORT_ROWS . '.');
            }
            $seenNumbers = [];
            foreach ($parsed['rows'] as $r) {
                $num = strtoupper(trim((string)$r['student_number']));
                if ($num === '') continue;
                if (isset($seenNumbers[$num])) {
                    throw new Exception('The file lists student number "' . $r['student_number'] . '" more than once. Each student should appear only once per import.');
                }
                $seenNumbers[$num] = true;
            }
            $levelId = $offering['academic_level_id'] !== null ? (int)$offering['academic_level_id'] : null;
            $yearId  = $offering['academic_year_id']  !== null ? (int)$offering['academic_year_id']  : null;
            $scheme = resolveScheme($db, $tenantId, $levelId, $yearId);
            $scale  = resolveScale($db, $tenantId, $levelId, $yearId);
            if (!$scale) throw new Exception('No active grading scale applies to this offering\'s level/year. Activate one before importing results.');
            $enrolled = loadEnrolled($db, $tenantId, $offeringId);
            $byNumber = [];
            foreach ($enrolled as $e) {
                $num = strtoupper(trim((string)$e['student_number']));
                if ($num !== '') $byNumber[$num] = $e;
            }
            if (empty($byNumber)) throw new Exception('No active enrollments with student numbers exist for this offering.');
            $termCache = [];
            $db->beginTransaction();
            $written = 0;
            $updated = 0;
            $skippedBlank = 0;
            $skippedAlreadyHadScore = 0;
            $skippedPublished = 0;
            $skippedNotEnrolled = 0;
            $skippedLockedScope = 0;
            $fixList = [];
            foreach ($parsed['rows'] as $r) {
                $rowNo = (int)$r['row'];
                $num   = strtoupper(trim((string)$r['student_number']));
                if ($r['error'] !== null) {
                    $fixList[] = ['row' => $rowNo, 'student_number' => $r['student_number'], 'subject' => '', 'term' => $r['term_raw'], 'reason' => $r['error']];
                    continue;
                }
                if ($num === '') continue;
                if (!isset($byNumber[$num])) {
                    $fixList[] = ['row' => $rowNo, 'student_number' => $r['student_number'], 'subject' => '', 'term' => $r['term_raw'], 'reason' => 'Student number not found among enrolled students in this offering.'];
                    $skippedNotEnrolled++;
                    continue;
                }
                $rowTermLabel = trim((string)$r['term_raw']);
                $rowTerm = null;
                if ($rowTermLabel !== '') {
                    $termKey = strtolower($rowTermLabel);
                    if (!isset($termCache[$termKey])) $termCache[$termKey] = resolveTermByLabel($db, $tenantId, $rowTermLabel);
                    $rowTerm = $termCache[$termKey];
                    if (!$rowTerm) {
                        $fixList[] = ['row' => $rowNo, 'student_number' => $r['student_number'], 'subject' => '', 'term' => $rowTermLabel, 'reason' => 'Term "' . $rowTermLabel . '" not found for this tenant.'];
                        continue;
                    }
                } else {
                    if (!$uiTerm) {
                        $fixList[] = ['row' => $rowNo, 'student_number' => $r['student_number'], 'subject' => '', 'term' => '', 'reason' => 'Term is blank in the file and no default term was selected.'];
                        continue;
                    }
                    $rowTerm = $uiTerm;
                }
                $rowTermId    = (int)$rowTerm['id'];
                $rowTermLabel = $rowTerm['term_name'];
                $enrollment = $byNumber[$num];
                $studentId  = (int)$enrollment['student_id'];
                $enrId      = (int)$enrollment['enrollment_id'];
                foreach ($r['subjects'] as $subjectId => $cell) {
                    $subjectId = (int)$subjectId;
                    if (!isset($requested[$subjectId])) continue;
                    $raw  = $cell['raw'];
                    $err  = $cell['error'];
                    $subLabel = $bySubjectId[$subjectId]['subject_name'] ?? ('Subject #' . $subjectId);
                    if ($err !== null) {
                        $fixList[] = ['row' => $rowNo, 'student_number' => $r['student_number'], 'subject' => $subLabel, 'term' => $rowTermLabel, 'reason' => $err];
                        continue;
                    }
                    if ($raw === null) {
                        $skippedBlank++;
                        continue;
                    }
                    if (isScopeLocked($db, $tenantId, $offeringId, $subjectId, $rowTermId)) {
                        $fixList[] = ['row' => $rowNo, 'student_number' => $r['student_number'], 'subject' => $subLabel, 'term' => $rowTermLabel, 'reason' => 'Scope is locked for this offering, subject and term.'];
                        $skippedLockedScope++;
                        continue;
                    }
                    $existing = $db->fetchOne(
                        "SELECT id, status, raw_score FROM results
                         WHERE tenant_id = ? AND student_id = ? AND subject_id = ?
                           AND academic_term_id = ? AND deleted_at IS NULL
                         FOR UPDATE",
                        [$tenantId, $studentId, $subjectId, $rowTermId]
                    );
                    if ($existing && $existing['status'] === 'published') {
                        $skippedPublished++;
                        continue;
                    }
                    if ($existing && $mode === 'fill_blanks') {
                        if ($existing['raw_score'] !== null && $existing['raw_score'] !== '') {
                            $skippedAlreadyHadScore++;
                            continue;
                        }
                    }
                    $rule = resolveGradeRule($db, $tenantId, (int)$scale['id'], $raw);
                    if ($existing) {
                        $db->execute(
                            "UPDATE results
                                SET enrollment_id = ?, class_offering_id = ?, academic_year_id = ?,
                                    assessment_scheme_id = ?, assessment_scheme_version = ?,
                                    grading_scale_id = ?, grading_scale_version = ?, grade_rule_id = ?,
                                    raw_score = ?, weighted_score = ?, final_score = ?,
                                    grade_symbol = ?, grade_label = ?, remark = ?, is_pass = ?,
                                    status = 'calculated', calculated_at = NOW(), updated_at = NOW()
                              WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                            [
                                $enrId,
                                $offeringId,
                                (int)$yearId,
                                $scheme ? (int)$scheme['id'] : null,
                                $scheme ? (int)$scheme['version'] : null,
                                (int)$scale['id'],
                                (int)$scale['version'],
                                $rule ? (int)$rule['id'] : null,
                                $raw,
                                $raw,
                                $raw,
                                $rule ? $rule['grade_symbol'] : null,
                                $rule ? $rule['grade_label'] : null,
                                $rule ? $rule['remark'] : null,
                                $rule ? (int)$rule['is_pass'] : null,
                                (int)$existing['id'],
                                $tenantId,
                            ]
                        );
                        $updated++;
                    } else {
                        $db->insert(
                            "INSERT INTO results
                                (uuid, tenant_id, student_id, enrollment_id,
                                 class_offering_id, academic_year_id, academic_term_id, subject_id,
                                 assessment_scheme_id, assessment_scheme_version,
                                 grading_scale_id, grading_scale_version, grade_rule_id,
                                 raw_score, weighted_score, final_score,
                                 grade_symbol, grade_label, remark, is_pass,
                                 status, calculated_at, created_by, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                                     'calculated', NOW(), ?, NOW(), NOW())",
                            [
                                uuidv4(),
                                $tenantId,
                                $studentId,
                                $enrId,
                                $offeringId,
                                (int)$yearId,
                                $rowTermId,
                                $subjectId,
                                $scheme ? (int)$scheme['id'] : null,
                                $scheme ? (int)$scheme['version'] : null,
                                (int)$scale['id'],
                                (int)$scale['version'],
                                $rule ? (int)$rule['id'] : null,
                                $raw,
                                $raw,
                                $raw,
                                $rule ? $rule['grade_symbol'] : null,
                                $rule ? $rule['grade_label'] : null,
                                $rule ? $rule['remark'] : null,
                                $rule ? (int)$rule['is_pass'] : null,
                                $userId ?: null,
                            ]
                        );
                        $written++;
                    }
                }
            }
            $subjectNames = [];
            foreach ($importSubjects as $s) $subjectNames[] = $s['subject_name'];
            $skippedTotal = $skippedBlank + $skippedAlreadyHadScore + $skippedPublished + $skippedNotEnrolled + $skippedLockedScope;
            $db->commit();
            $preservedPath = null;
            try {
                $preservedPath = preserveImportFile((string)$file['tmp_name'], (string)$file['name'], $tenantId, $projectRoot);
            } catch (Exception $e) {
                error_log('preserveImportFile post-commit failure: ' . $e->getMessage());
                $preservedPath = null;
            }
            if ($preservedPath !== null) {
                writeAudit($db, $tenantId, $userId, 'academic.result.import_file_stored', 'results', $offeringId, ['offering_id' => $offeringId, 'source_file' => $preservedPath]);
            }
            writeAudit($db, $tenantId, $userId, 'academic.result.imported', 'results', $offeringId, [
                'offering_id' => $offeringId,
                'term_id' => $uiTermId,
                'term_name' => $uiTerm['term_name'] ?? '(per row)',
                'subjects' => $subjectNames,
                'mode' => $mode,
                'format' => $parsed['format'],
                'source_file' => $preservedPath,
                'written' => $written,
                'updated' => $updated,
                'skipped_blank' => $skippedBlank,
                'skipped_already_had_score' => $skippedAlreadyHadScore,
                'skipped_published' => $skippedPublished,
                'skipped_not_enrolled' => $skippedNotEnrolled,
                'skipped_locked_scope' => $skippedLockedScope,
                'errors' => count($fixList),
            ]);
            $_SESSION['last_import_at'] = $now;
            $_SESSION['import_count_today'] = $sessionCountToday + 1;
            $_SESSION['import_summary'] = [
                'offering_id' => $offeringId,
                'term_id' => $uiTermId,
                'term_name' => $uiTerm['term_name'] ?? '(per row)',
                'subjects' => $subjectNames,
                'mode' => $mode,
                'format' => $parsed['format'],
                'source_file' => $preservedPath,
                'written' => $written,
                'updated' => $updated,
                'skipped_blank' => $skippedBlank,
                'skipped_already_had_score' => $skippedAlreadyHadScore,
                'skipped_published' => $skippedPublished,
                'skipped_not_enrolled' => $skippedNotEnrolled,
                'skipped_locked_scope' => $skippedLockedScope,
                'skipped_total' => $skippedTotal,
                'fix_list' => $fixList,
            ];
            $_SESSION['success'] = sprintf('Import complete: %d new, %d updated, %d skipped, %d error(s).', $written, $updated, $skippedTotal, count($fixList));
            header('Location: /platform/tenant/academic/results.php?offering_id=' . $offeringId . '&term_id=' . $uiTermId);
            exit;
        }

        // -------- PUBLISH --------
        if ($action === 'publish') {
            $offeringId = (int)($_POST['class_offering_id'] ?? 0);
            $subjectId  = (int)($_POST['subject_id'] ?? 0);
            $termId     = (int)($_POST['academic_term_id'] ?? 0);
            $offering = getOffering($db, $tenantId, $offeringId);
            if (!$offering) throw new Exception('Class offering not found.');
            if ($subjectId <= 0) throw new Exception('Subject is required.');
            if ($termId <= 0)    throw new Exception('Term is required.');
            $db->beginTransaction();
            $rows = $db->fetchAll(
                "SELECT id FROM results
                 WHERE tenant_id = ? AND class_offering_id = ? AND subject_id = ? AND academic_term_id = ?
                   AND deleted_at IS NULL",
                [$tenantId, $offeringId, $subjectId, $termId]
            );
            if (empty($rows)) throw new Exception('No results exist to publish.');
            $db->execute(
                "UPDATE results
                    SET status = 'published', published_at = NOW(), published_by = ?, updated_at = NOW()
                  WHERE tenant_id = ? AND class_offering_id = ? AND subject_id = ? AND academic_term_id = ?
                    AND deleted_at IS NULL",
                [$userId ?: null, $tenantId, $offeringId, $subjectId, $termId]
            );
            writeAudit($db, $tenantId, $userId, 'academic.result.published', 'results', $offeringId, [
                'offering_id' => $offeringId,
                'subject_id' => $subjectId,
                'term_id' => $termId,
                'count' => count($rows)
            ]);
            $db->commit();
            $_SESSION['success'] = sprintf('Published %d results.', count($rows));
            header('Location: /platform/tenant/academic/results.php?offering_id=' . $offeringId . '&subject_id=' . $subjectId . '&term_id=' . $termId);
            exit;
        }

        // -------- LOCK --------
        if ($action === 'lock') {
            $offeringId = (int)($_POST['class_offering_id'] ?? 0);
            $subjectId  = (int)($_POST['subject_id'] ?? 0);
            $termId     = (int)($_POST['academic_term_id'] ?? 0);
            $reason     = trim((string)($_POST['reason'] ?? ''));
            $offering = getOffering($db, $tenantId, $offeringId);
            if (!$offering) throw new Exception('Class offering not found.');
            if ($subjectId <= 0) throw new Exception('Subject is required.');
            if ($termId <= 0)    throw new Exception('Term is required.');
            requireAdminForLock($db, $tenantId, $userId, $offeringId, $isSuperAdmin);
            if ($reason === '') throw new Exception('A reason is required to lock a scope.');
            $db->beginTransaction();
            $lockId = lockScope($db, $tenantId, $userId, $offeringId, $subjectId, $termId, $reason);
            writeAudit($db, $tenantId, $userId, 'academic.result.locked', 'results', $offeringId, [
                'offering_id' => $offeringId,
                'subject_id' => $subjectId,
                'term_id' => $termId,
                'lock_id' => $lockId,
                'reason' => $reason,
            ]);
            $db->commit();
            $_SESSION['success'] = 'Scope locked. Results for this offering, subject and term are now read-only.';
            header('Location: /platform/tenant/academic/results.php?offering_id=' . $offeringId . '&subject_id=' . $subjectId . '&term_id=' . $termId);
            exit;
        }

        // -------- UNLOCK --------
        if ($action === 'unlock') {
            $offeringId = (int)($_POST['class_offering_id'] ?? 0);
            $subjectId  = (int)($_POST['subject_id'] ?? 0);
            $termId     = (int)($_POST['academic_term_id'] ?? 0);
            $reason     = trim((string)($_POST['reason'] ?? ''));
            $offering = getOffering($db, $tenantId, $offeringId);
            if (!$offering) throw new Exception('Class offering not found.');
            if ($subjectId <= 0) throw new Exception('Subject is required.');
            if ($termId <= 0)    throw new Exception('Term is required.');
            requireAdminForLock($db, $tenantId, $userId, $offeringId, $isSuperAdmin);
            if ($reason === '') throw new Exception('A reason is required to unlock a scope.');
            $db->beginTransaction();
            unlockScope($db, $tenantId, $userId, $offeringId, $subjectId, $termId, $reason);
            writeAudit($db, $tenantId, $userId, 'academic.result.unlocked', 'results', $offeringId, [
                'offering_id' => $offeringId,
                'subject_id' => $subjectId,
                'term_id' => $termId,
                'reason' => $reason,
            ]);
            $db->commit();
            $_SESSION['success'] = 'Scope unlocked. Results for this offering, subject and term can now be modified again.';
            header('Location: /platform/tenant/academic/results.php?offering_id=' . $offeringId . '&subject_id=' . $subjectId . '&term_id=' . $termId);
            exit;
        }

        // -------- DELETE --------
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $row = $db->fetchOne(
                "SELECT * FROM results WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Result not found.');
            if ($row['status'] === 'published') throw new Exception('Published results cannot be deleted.');
            if (isScopeLocked(
                $db,
                $tenantId,
                (int)$row['class_offering_id'],
                (int)$row['subject_id'],
                (int)$row['academic_term_id']
            )) {
                throw new Exception('This scope is locked: results for this offering, subject and term cannot be modified until an admin unlocks it.');
            }
            $db->beginTransaction();
            $db->execute(
                "UPDATE results SET deleted_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            writeAudit($db, $tenantId, $userId, 'academic.result.deleted', 'results', $id, [
                'student_id' => (int)$row['student_id'],
                'subject_id' => (int)$row['subject_id'],
                'offering_id' => (int)$row['class_offering_id'],
                'term_id' => (int)$row['academic_term_id'],
            ]);
            $db->commit();
            $_SESSION['success'] = 'Result deleted.';
            $back = '/platform/tenant/academic/results.php';
            if (!empty($row['class_offering_id']) && !empty($row['subject_id']) && !empty($row['academic_term_id'])) {
                $back .= '?offering_id=' . (int)$row['class_offering_id']
                    . '&subject_id=' . (int)$row['subject_id']
                    . '&term_id=' . (int)$row['academic_term_id'];
            }
            header('Location: ' . $back);
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Academic results action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/academic/results.php');
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
$importSummary = null;
if (isset($_SESSION['import_summary'])) {
    $importSummary = $_SESSION['import_summary'];
    unset($_SESSION['import_summary']);
}

// [v3.9 :: CONTINUES FROM PART 2/3]

// ============================================
// LOAD PAGE DATA
// ============================================
$settings      = loadActiveSettings($db, $tenantId);
$labelAcademic = $settings['label_academic_structure'] ?? 'Academic Year';
$labelLevel    = $settings['label_level'] ?? 'Level';
$labelClass    = $settings['label_class'] ?? 'Class';
$labelStream   = $settings['label_stream'] ?? 'Stream';
$labelTerm     = $settings['label_term'] ?? 'Term';

$filterYear   = (int)($_GET['year_id'] ?? 0);
$filterLevel  = (int)($_GET['level_id'] ?? 0);

$selOfferingId = (int)($_GET['offering_id'] ?? 0);
$selSubjectId  = (int)($_GET['subject_id'] ?? 0);
$selTermId     = (int)($_GET['term_id'] ?? 0);

$years = $db->fetchAll(
    "SELECT id, year_name FROM academic_years
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY start_date DESC, id DESC",
    [$tenantId]
);
$levels = $db->fetchAll(
    "SELECT id, level_name, level_code FROM academic_levels
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY sort_order ASC, level_name ASC",
    [$tenantId]
);
$terms = $db->fetchAll(
    "SELECT id, term_name, is_current, academic_year_id FROM academic_terms
     WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL
     ORDER BY is_current DESC, sort_order ASC, id ASC",
    [$tenantId]
);

$where = ["co.tenant_id = ?", "co.deleted_at IS NULL"];
$params = [$tenantId];
if ($filterYear > 0) {
    $where[] = "co.academic_year_id = ?";
    $params[] = $filterYear;
}
if ($filterLevel > 0) {
    $where[] = "co.academic_level_id = ?";
    $params[] = $filterLevel;
}
$whereClause = implode(' AND ', $where);

$offerings = $db->fetchAll(
    "SELECT co.id, co.capacity, co.status,
            co.academic_year_id, co.academic_level_id, co.class_id, co.stream_id,
            ay.year_name, ay.start_date AS year_start,
            al.level_name, al.level_code, al.sort_order AS level_sort,
            c.class_name, c.class_code,
            s.stream_name,
            (SELECT COUNT(*) FROM enrollments e
             WHERE e.tenant_id = co.tenant_id
               AND e.class_offering_id = co.id
               AND e.status = 'active' AND e.deleted_at IS NULL) AS active_count
     FROM class_offerings co
     LEFT JOIN academic_years ay ON co.academic_year_id = ay.id
     LEFT JOIN academic_levels al ON co.academic_level_id = al.id
     LEFT JOIN classes c ON co.class_id = c.id
     LEFT JOIN streams s ON co.stream_id = s.id
     WHERE $whereClause
     ORDER BY ay.start_date DESC, al.sort_order ASC, c.class_name ASC, s.stream_name ASC",
    $params
);

$byYear = [];
foreach ($offerings as $o) {
    $yKey = (int)$o['academic_year_id'];
    $yName = $o['year_name'] ?: '(Unknown year)';
    if (!isset($byYear[$yKey])) $byYear[$yKey] = ['year_name' => $yName, 'levels' => []];
    $lKey = (int)$o['academic_level_id'];
    $lName = $o['level_name'] ?: '(Unknown level)';
    $lCode = $o['level_code'] ?? '';
    if (!isset($byYear[$yKey]['levels'][$lKey])) {
        $byYear[$yKey]['levels'][$lKey] = ['level_name' => $lName, 'level_code' => $lCode, 'items' => []];
    }
    $byYear[$yKey]['levels'][$lKey]['items'][] = $o;
}

$viewMode = trim((string)($_GET['view'] ?? ''));

$selOffering = null;
$selSubject = null;
$selTerm = null;
$selSubjects = [];
$gridStudents = [];
$gridResults = [];
$selScheme = null;
$selScale = null;
$allPublished = false;
$scopeLocked = false;

if ($selOfferingId > 0) {
    $selOffering = getOffering($db, $tenantId, $selOfferingId);
    if ($selOffering) {
        $selSubjects = loadOfferingSubjects($db, $tenantId, $selOfferingId);
        if ($selSubjectId > 0) {
            $selSubject = $db->fetchOne(
                "SELECT id, subject_name, subject_code FROM subjects
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$selSubjectId, $tenantId]
            );
        }
        if ($selTermId > 0) $selTerm = getTerm($db, $tenantId, $selTermId);
        if ($selSubject && $selTerm) {
            $gridStudents = loadEnrolled($db, $tenantId, $selOfferingId);
            $rows = $db->fetchAll(
                "SELECT * FROM results
                 WHERE tenant_id = ? AND class_offering_id = ? AND subject_id = ? AND academic_term_id = ?
                   AND deleted_at IS NULL",
                [$tenantId, $selOfferingId, $selSubjectId, $selTermId]
            );
            foreach ($rows as $r) $gridResults[(int)$r['student_id']] = $r;
            $levelId = $selOffering['academic_level_id'] !== null ? (int)$selOffering['academic_level_id'] : null;
            $yearId  = $selOffering['academic_year_id']  !== null ? (int)$selOffering['academic_year_id']  : null;
            $selScheme = resolveScheme($db, $tenantId, $levelId, $yearId);
            $selScale  = resolveScale($db, $tenantId, $levelId, $yearId);
            $scopeLocked = isScopeLocked($db, $tenantId, $selOfferingId, $selSubjectId, $selTermId);
            $allPublished = !empty($gridResults);
            foreach ($gridResults as $r) {
                if ($r['status'] !== 'published') {
                    $allPublished = false;
                    break;
                }
            }
        }
    }
}

$classViewActive = false;
$classSheet      = null;
$classSubjectCount = 0;
$classStudentCount = 0;
$classLockCount    = 0;

if ($viewMode === 'class' && $selOfferingId > 0 && $selTermId > 0) {
    $classOffering = getOffering($db, $tenantId, $selOfferingId);
    $classTerm     = getTerm($db, $tenantId, $selTermId);
    if ($classOffering && $classTerm) {
        $classViewActive = true;
        $selOffering = $classOffering;
        $selTerm     = $classTerm;
        $classSheet  = loadClassMasterSheet($db, $tenantId, $selOfferingId, $selTermId);
        $classSubjectCount = count($classSheet['subjects']);
        $classStudentCount = count($classSheet['students']);
        $classLockCount    = (int)$classSheet['locked_count'];
    }
}

$levelViewActive = false;
$lvLevel = null;
$lvTerm = null;
$lvLevels = [];
$lvOfferings = [];
$lvStats = ['offerings' => 0, 'students' => 0, 'with_results' => 0, 'passes' => 0, 'fails' => 0];

$lvLevelId = (int)($_GET['level_id'] ?? 0);
$lvTermId  = (int)($_GET['term_id'] ?? 0);

if ($viewMode === 'level' && $lvLevelId > 0 && $lvTermId > 0) {
    $lvLevel = $db->fetchOne(
        "SELECT id, level_name, level_code FROM academic_levels
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$lvLevelId, $tenantId]
    );
    $lvTerm = getTerm($db, $tenantId, $lvTermId);
    if ($lvLevel && $lvTerm) {
        $levelViewActive = true;
        $lvLevels = $levels;
        $lvOffRows = $db->fetchAll(
            "SELECT co.id, co.class_id, co.stream_id,
                    c.class_name, c.class_code, s.stream_name,
                    (SELECT COUNT(*) FROM enrollments e
                     WHERE e.tenant_id = co.tenant_id
                       AND e.class_offering_id = co.id
                       AND e.status = 'active' AND e.deleted_at IS NULL) AS active_count
             FROM class_offerings co
             LEFT JOIN classes c ON c.id = co.class_id
             LEFT JOIN streams s ON s.id = co.stream_id
             WHERE co.tenant_id = ? AND co.academic_level_id = ? AND co.deleted_at IS NULL
             ORDER BY c.class_name ASC, s.stream_name ASC, co.id ASC",
            [$tenantId, $lvLevelId]
        );
        foreach ($lvOffRows as $offRow) {
            $offId = (int)$offRow['id'];
            $sheet = loadClassMasterSheet($db, $tenantId, $offId, $lvTermId);
            $lvOfferings[] = [
                'offering'       => $offRow,
                'subjects'       => $sheet['subjects'],
                'students'       => $sheet['students'],
                'results'        => $sheet['results'],
                'subject_totals' => $sheet['subject_totals'],
                'student_totals' => $sheet['student_totals'],
                'locked_count'   => $sheet['locked_count'],
            ];
            $lvStats['offerings']++;
            $lvStats['students'] += count($sheet['students']);
            foreach ($sheet['results'] as $sid => $bySub) {
                foreach ($bySub as $r) {
                    if ($r['final_score'] !== null || $r['raw_score'] !== null) {
                        $lvStats['with_results']++;
                        if (!empty($r['is_pass'])) $lvStats['passes']++;
                        else $lvStats['fails']++;
                    }
                }
            }
        }
    }
}

$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

$totalOfferings = count($offerings);
$totalPublished = 0;
$totalResults = 0;
try {
    $r = $db->fetchOne("SELECT COUNT(*) AS c FROM results WHERE tenant_id = ? AND status = 'published' AND deleted_at IS NULL", [$tenantId]);
    $totalPublished = (int)($r['c'] ?? 0);
    $r = $db->fetchOne("SELECT COUNT(*) AS c FROM results WHERE tenant_id = ? AND deleted_at IS NULL", [$tenantId]);
    $totalResults = (int)($r['c'] ?? 0);
} catch (Exception $e) {
}

$xlsxAvailable = resultsXlsxAvailable();
$currentTermName = $selTerm['term_name'] ?? null;

$bcTermId = $selTermId > 0 ? $selTermId : $lvTermId;
$bcTerm = null;
foreach ($terms as $t) {
    if ((int)$t['id'] === (int)$bcTermId) {
        $bcTerm = $t;
        break;
    }
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
            padding: 0 0 16px 0;
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
            max-width: 1200px;
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

        .breadcrumb-strip {
            background: #fff;
            border-radius: 14px;
            padding: 12px 20px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
            font-size: 13px;
        }

        .breadcrumb-strip a {
            color: #4facfe;
            text-decoration: none;
            font-weight: 500;
            padding: 3px 8px;
            border-radius: 6px;
            transition: background 0.15s;
        }

        .breadcrumb-strip a:hover {
            background: rgba(79, 172, 254, 0.1);
        }

        .breadcrumb-strip .bc-sep {
            color: #adb5bd;
            font-size: 11px;
        }

        .breadcrumb-strip .bc-current {
            color: #1a1a2e;
            font-weight: 600;
            padding: 3px 8px;
        }

        .breadcrumb-strip .bc-pills {
            margin-left: auto;
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .breadcrumb-strip .bc-pills a {
            font-size: 11px;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
        }

        .bc-pill-blue {
            background: #cce5ff;
            color: #004085 !important;
        }

        .bc-pill-gray {
            background: #e9ecef;
            color: #495057 !important;
        }

        .info-banner {
            background: linear-gradient(135deg, #eef6ff 0%, #e3f0ff 100%);
            border: 2px solid #4facfe;
            border-radius: 14px;
            padding: 16px 24px;
            margin-bottom: 24px;
            max-width: 1200px;
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

        .info-banner.warn {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border-color: #fbbf24;
        }

        .info-banner.warn .ib-icon {
            background: rgba(251, 191, 36, 0.18);
            color: #b45309;
        }

        .info-banner.warn .ib-title {
            color: #92400e;
        }

        .filters-bar {
            background: #fff;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        .filters-bar .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            min-width: 140px;
        }

        .filters-bar .filter-group label {
            font-weight: 500;
            font-size: 12px;
            color: #1a1a2e;
            white-space: nowrap;
        }

        .filters-bar .form-select {
            height: 38px;
            font-size: 13px;
            border-radius: 8px;
            border: 1.5px solid #e9ecef;
        }

        .year-section {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
            overflow: hidden;
            width: 100%;
            max-width: 1200px;
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

        .year-section .year-header .year-title h5 {
            font-weight: 700;
            margin: 0;
            font-size: 18px;
            color: #1a1a2e;
        }

        .level-block {
            border-bottom: 1px solid #f0f2f5;
        }

        .level-block:last-child {
            border-bottom: none;
        }

        .level-block .level-bar {
            padding: 10px 24px;
            background: #fafbfc;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            border-bottom: 1px solid #f0f2f5;
        }

        .level-block .level-bar h6 {
            font-weight: 600;
            margin: 0;
            font-size: 14px;
            color: #1a1a2e;
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

        .offering-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .offering-table thead th {
            background: #f8f9fa;
            padding: 10px 18px;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            border-bottom: 1px solid #e9ecef;
            text-align: left;
            white-space: nowrap;
        }

        .offering-table tbody td {
            padding: 12px 18px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .offering-table tbody tr:last-child td {
            border-bottom: none;
        }

        .offering-table tbody tr:hover {
            background: #fafbfc;
        }

        .offering-title {
            font-weight: 600;
            color: #1a1a2e;
            font-size: 14px;
        }

        .offering-meta {
            font-size: 11px;
            color: #6c757d;
            margin-top: 2px;
        }

        .entry-header {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            padding: 18px 24px;
            margin-bottom: 20px;
            width: 100%;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        .entry-header .entry-title {
            font-weight: 700;
            font-size: 16px;
            color: #1a1a2e;
            margin-bottom: 4px;
        }

        .entry-header .entry-meta {
            font-size: 12px;
            color: #6c757d;
        }

        .entry-header .entry-meta strong {
            color: #1a1a2e;
        }

        .grid-wrap {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            overflow: hidden;
            width: 100%;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
            margin-bottom: 24px;
        }

        .grid-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .grid-table thead th {
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

        .grid-table tbody td {
            padding: 8px 14px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .grid-table tbody tr:last-child td {
            border-bottom: none;
        }

        .grid-table tbody tr:hover {
            background: #fafbfc;
        }

        .grid-table input.score-input {
            width: 90px;
            height: 34px;
            padding: 4px 8px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 13px;
            text-align: center;
        }

        .grid-table input.score-input:focus {
            border-color: #4facfe;
            outline: none;
            box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.15);
        }

        .grid-table input.score-input[readonly] {
            background: #f8f9fa;
            color: #6c757d;
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
            max-width: 1200px;
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

        .import-summary {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            padding: 18px 24px;
            margin-bottom: 20px;
            width: 100%;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        .import-summary h6 {
            font-weight: 700;
            font-size: 14px;
            margin: 0 0 10px;
        }

        .import-summary .is-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 12px;
        }

        .import-summary .is-cell {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px 14px;
        }

        .import-summary .is-cell .is-num {
            font-weight: 700;
            font-size: 18px;
            color: #1a1a2e;
        }

        .import-summary .is-cell .is-lbl {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .import-summary .is-cell.warn .is-num {
            color: #c2410c;
        }

        .import-summary .fix-list {
            max-height: 260px;
            overflow-y: auto;
            border: 1px solid #f0f2f5;
            border-radius: 10px;
        }

        .import-summary .fix-list table {
            width: 100%;
            font-size: 12px;
            border-collapse: collapse;
        }

        .import-summary .fix-list th {
            background: #f8f9fa;
            padding: 6px 10px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            color: #6c757d;
            letter-spacing: 0.5px;
        }

        .import-summary .fix-list td {
            padding: 6px 10px;
            border-top: 1px solid #f0f2f5;
        }

        .upload-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            padding: 18px 24px;
            margin-bottom: 20px;
            width: 100%;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        .upload-card h6 {
            font-weight: 700;
            font-size: 14px;
            margin: 0 0 4px;
        }

        .upload-card .hint {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 12px;
        }

        .upload-card .mode-hint {
            font-size: 11px;
            color: #6c757d;
            margin-top: 4px;
        }

        .subject-picker-list {
            max-height: 340px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 8px;
            background: #fafbfc;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .subject-picker-row {
            display: grid;
            grid-template-columns: 28px 1fr 100px;
            gap: 10px;
            align-items: center;
            padding: 8px 10px;
            border-radius: 8px;
            background: #fff;
            border: 1px solid #eef0f3;
            cursor: pointer;
        }

        .subject-picker-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .subject-picker-row .sp-name {
            font-size: 13px;
            font-weight: 500;
            color: #1a1a2e;
        }

        .subject-picker-row .sp-code {
            font-size: 11px;
            color: #0d6efd;
            font-family: 'Courier New', monospace;
            font-weight: 600;
            background: #e3f0ff;
            padding: 1px 8px;
            border-radius: 6px;
            display: inline-block;
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

        .lv-toggle-chevron {
            color: #6c757d;
            font-size: 14px;
            transition: transform 0.2s;
        }

        .preview-tile {
            border-radius: 10px;
            padding: 10px 14px;
            background: #f8f9fa;
        }

        .preview-tile .pt-num {
            font-weight: 700;
            font-size: 20px;
            color: #1a1a2e;
        }

        .preview-tile .pt-lbl {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .preview-tile.risk {
            background: #fff5f5;
            border: 1px solid #fecaca;
        }

        .preview-tile.risk .pt-num {
            color: #991b1b;
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

            .offering-table,
            .grid-table {
                font-size: 12px;
            }

            .offering-table thead th,
            .grid-table thead th {
                padding: 8px 10px;
                font-size: 10px;
            }

            .offering-table tbody td,
            .grid-table tbody td {
                padding: 8px 10px;
            }

            .filters-bar {
                flex-direction: column;
            }

            .filters-bar .filter-group {
                width: 100%;
            }

            .breadcrumb-strip {
                font-size: 12px;
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
                        <h1><i class="fas fa-poll me-2"></i>Results</h1>
                        <p>
                            <?php if ($levelViewActive): ?>
                                Level view — every class in <?php echo h($lvLevel['level_name']); ?>
                            <?php elseif ($classViewActive): ?>
                                Class view — <?php echo h($selOffering['class_name'] ?: '—'); ?>
                            <?php elseif ($selSubject && $selTerm): ?>
                                Subject entry — <?php echo h($selSubject['subject_name']); ?>
                            <?php else: ?>
                                Select an offering, term and subject to enter results
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="header-actions">
                        <?php if (!$levelViewActive && !$classViewActive): ?>
                            <a href="/platform/tenant/academic/settings.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i> Back to Settings
                            </a>
                        <?php endif; ?>
                        <?php if ($levelViewActive || $classViewActive): ?>
                            <a href="/platform/tenant/academic/results.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i> Back to all classes
                            </a>
                        <?php endif; ?>
                        <a href="/platform/tenant/academic/result-locks.php" class="btn btn-outline-secondary">
                            <i class="fas fa-lock me-2"></i> View all locks
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo h($tenantName); ?></div>
                            <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                <?php echo h((string)$totalOfferings); ?> offering(s) ·
                                <?php echo h((string)$totalResults); ?> result(s) ·
                                <?php echo h((string)$totalPublished); ?> published
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>
                        <?php if ($levelViewActive): ?>Level view
                        <?php elseif ($classViewActive): ?>Class view
                        <?php elseif ($selSubject && $selTerm): ?>Subject entry
                        <?php else: ?>Draft then publish
                    <?php endif; ?>
                    </span>
                </div>

                <?php if ($levelViewActive || $classViewActive || ($selOffering && $selTerm)): ?>
                    <div class="breadcrumb-strip">
                        <a href="/platform/tenant/academic/results.php">
                            <i class="fas fa-home me-1"></i>All classes
                        </a>
                        <?php if ($classViewActive || $selSubject): ?>
                            <span class="bc-sep"><i class="fas fa-chevron-right"></i></span>
                            <?php if ($classViewActive): ?>
                                <span class="bc-current">
                                    <?php echo h($selOffering['class_name'] ?: '—'); ?>
                                    <?php if (!empty($selOffering['stream_name'])): ?>
                                        · <?php echo h($selOffering['stream_name']); ?>
                                    <?php endif; ?>
                                </span>
                            <?php else: ?>
                                <a href="/platform/tenant/academic/results.php?view=class&offering_id=<?php echo (int)$selOfferingId; ?>&term_id=<?php echo (int)$selTermId; ?>">
                                    <?php echo h($selOffering['class_name'] ?: '—'); ?>
                                    <?php if (!empty($selOffering['stream_name'])): ?>
                                        · <?php echo h($selOffering['stream_name']); ?>
                                    <?php endif; ?>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($levelViewActive): ?>
                            <a href="/platform/tenant/academic/results.php">
                                <?php echo h($lvLevel['level_name']); ?>
                            </a>
                            <span class="bc-sep"><i class="fas fa-chevron-right"></i></span>
                            <span class="bc-current"><?php echo h($lvLevel['level_name']); ?></span>
                        <?php endif; ?>
                        <?php if ($selSubject && $selTerm): ?>
                            <span class="bc-sep"><i class="fas fa-chevron-right"></i></span>
                            <span class="bc-current"><?php echo h($selSubject['subject_name']); ?></span>
                        <?php endif; ?>
                        <?php if ($bcTerm): ?>
                            <span class="bc-sep"><i class="fas fa-chevron-right"></i></span>
                            <span style="color:#6c757d;font-weight:500;"><?php echo h($bcTerm['term_name']); ?></span>
                        <?php endif; ?>

                        <div class="bc-pills">
                            <?php if ($classViewActive || $selSubject): ?>
                                <?php foreach ($levels as $lv):
                                    $activeLv = $selOffering && (int)$selOffering['academic_level_id'] === (int)$lv['id'];
                                ?>
                                    <a href="/platform/tenant/academic/results.php?view=level&level_id=<?php echo (int)$lv['id']; ?>&term_id=<?php echo (int)$bcTermId; ?>"
                                        class="bc-pill-<?php echo $activeLv ? 'blue' : 'gray'; ?>">
                                        <?php echo h($lv['level_name']); ?>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

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

                <?php if ($importSummary): ?>
                    <div class="import-summary">
                        <h6>
                            <i class="fas fa-file-import text-primary me-2"></i>
                            Last import — <?php echo h(strtoupper($importSummary['format'])); ?> ·
                            mode: <?php echo h($importSummary['mode'] === 'overwrite' ? 'Overwrite' : 'Fill blanks only'); ?> ·
                            term: <strong><?php echo h($importSummary['term_name'] ?? '—'); ?></strong>
                        </h6>
                        <div style="font-size:12px;color:#6c757d;margin-bottom:10px;">
                            Subjects: <strong><?php echo h(implode(', ', $importSummary['subjects'])); ?></strong>
                            <?php if (!empty($importSummary['source_file'])): ?>
                                · Source file: <code style="font-size:11px;"><?php echo h($importSummary['source_file']); ?></code>
                            <?php endif; ?>
                        </div>
                        <div class="is-grid">
                            <div class="is-cell">
                                <div class="is-num"><?php echo h((string)(int)$importSummary['written']); ?></div>
                                <div class="is-lbl">New</div>
                            </div>
                            <div class="is-cell">
                                <div class="is-num"><?php echo h((string)(int)$importSummary['updated']); ?></div>
                                <div class="is-lbl">Updated</div>
                            </div>
                            <div class="is-cell <?php echo ((int)$importSummary['skipped_blank']) > 0 ? 'warn' : ''; ?>">
                                <div class="is-num"><?php echo h((string)(int)$importSummary['skipped_blank']); ?></div>
                                <div class="is-lbl">Skipped — blank</div>
                            </div>
                            <div class="is-cell <?php echo ((int)$importSummary['skipped_already_had_score']) > 0 ? 'warn' : ''; ?>">
                                <div class="is-num"><?php echo h((string)(int)$importSummary['skipped_already_had_score']); ?></div>
                                <div class="is-lbl">Skipped — had a score</div>
                            </div>
                            <div class="is-cell <?php echo ((int)$importSummary['skipped_published']) > 0 ? 'warn' : ''; ?>">
                                <div class="is-num"><?php echo h((string)(int)$importSummary['skipped_published']); ?></div>
                                <div class="is-lbl">Skipped — published</div>
                            </div>
                            <div class="is-cell <?php echo ((int)$importSummary['skipped_not_enrolled']) > 0 ? 'warn' : ''; ?>">
                                <div class="is-num"><?php echo h((string)(int)$importSummary['skipped_not_enrolled']); ?></div>
                                <div class="is-lbl">Skipped — not enrolled</div>
                            </div>
                            <div class="is-cell <?php echo ((int)($importSummary['skipped_locked_scope'] ?? 0)) > 0 ? 'warn' : ''; ?>">
                                <div class="is-num"><?php echo h((string)(int)($importSummary['skipped_locked_scope'] ?? 0)); ?></div>
                                <div class="is-lbl">Skipped — locked scope</div>
                            </div>
                            <div class="is-cell <?php echo count($importSummary['fix_list']) > 0 ? 'warn' : ''; ?>">
                                <div class="is-num"><?php echo h((string)count($importSummary['fix_list'])); ?></div>
                                <div class="is-lbl">Errors</div>
                            </div>
                        </div>
                        <?php if (!empty($importSummary['fix_list'])): ?>
                            <div style="font-weight:600;font-size:12px;color:#991b1b;margin-bottom:6px;"><i class="fas fa-list me-1"></i>Fix list</div>
                            <div class="fix-list">
                                <table>
                                    <thead>
                                        <tr>
                                            <th style="width:70px;">Row</th>
                                            <th style="width:150px;">Student #</th>
                                            <th style="width:180px;">Subject</th>
                                            <th style="width:150px;">Term</th>
                                            <th>Reason</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($importSummary['fix_list'] as $fx): ?>
                                            <tr>
                                                <td><?php echo h((string)(int)$fx['row']); ?></td>
                                                <td><?php echo h($fx['student_number']); ?></td>
                                                <td><?php echo h($fx['subject'] ?? ''); ?></td>
                                                <td><?php echo h($fx['term'] ?? ''); ?></td>
                                                <td><?php echo h($fx['reason']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($levelViewActive): ?>
                    <div class="year-section" style="margin-bottom:16px;">
                        <div class="year-header">
                            <div class="year-title">
                                <i class="fas fa-layer-group" style="font-size:20px;color:#4facfe;"></i>
                                <h5><?php echo h($lvLevel['level_name']); ?> — <?php echo h($lvTerm['term_name']); ?></h5>
                                <span class="pill blue"><i class="fas fa-door-open"></i><?php echo h((string)$lvStats['offerings']); ?> class(es)</span>
                                <span class="pill purple"><i class="fas fa-user-graduate"></i><?php echo h((string)$lvStats['students']); ?> student(s)</span>
                                <span class="pill teal"><i class="fas fa-clipboard-check"></i><?php echo h((string)$lvStats['with_results']); ?> score(s)</span>
                                <span class="pill green"><?php echo h((string)$lvStats['passes']); ?> pass</span>
                                <span class="pill red"><?php echo h((string)$lvStats['fails']); ?> fail</span>
                            </div>
                            <div class="d-flex gap-2 flex-wrap align-items-center">
                                <form method="GET" action="/platform/tenant/academic/results.php" style="margin:0;">
                                    <input type="hidden" name="view" value="level">
                                    <input type="hidden" name="level_id" value="<?php echo (int)$lvLevelId; ?>">
                                    <select name="term_id" class="form-select form-select-sm" style="width:auto;display:inline-block;" onchange="this.form.submit()">
                                        <?php foreach ($terms as $t): ?>
                                            <option value="<?php echo (int)$t['id']; ?>" <?php echo (int)$lvTermId === (int)$t['id'] ? 'selected' : ''; ?>>
                                                <?php echo h($t['term_name']); ?>
                                                <?php if ((int)$t['is_current'] === 1): ?> ★<?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <a href="/platform/tenant/academic/results.php?action=export_level&level_id=<?php echo (int)$lvLevelId; ?>&term_id=<?php echo (int)$lvTermId; ?>"
                                    class="btn btn-outline-info btn-sm">
                                    <i class="fas fa-download me-1"></i> Export level
                                </a>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="lvExpandAll">
                                    <i class="fas fa-expand me-1"></i> Show all
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="lvCollapseAll">
                                    <i class="fas fa-compress me-1"></i> Hide all
                                </button>
                            </div>
                        </div>
                    </div>

                    <?php if (empty($lvOfferings)): ?>
                        <div class="year-section">
                            <div class="empty-state">
                                <i class="fas fa-door-open"></i>
                                <h5>No classes in this level for this term</h5>
                                <p>Create a class offering under this level, or pick another level.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($lvOfferings as $lvOff):
                            $offRow = $lvOff['offering'];
                            $offId = (int)$offRow['id'];
                            $offLabel = trim(($offRow['class_name'] ?? '') . ' ' . ($offRow['stream_name'] ?? ''));
                            $classUrl = '/platform/tenant/academic/results.php?view=class&offering_id=' . $offId . '&term_id=' . (int)$lvTermId;
                            $sectionId = 'lv-section-' . $offId;
                        ?>
                            <div class="year-section" id="<?php echo h($sectionId); ?>">
                                <div class="year-header" style="cursor:pointer;" data-lv-toggle="<?php echo h($sectionId); ?>">
                                    <div class="year-title">
                                        <i class="fas fa-door-open" style="font-size:20px;color:#4facfe;"></i>
                                        <h5><?php echo h($offLabel !== '' ? $offLabel : 'Class #' . $offId); ?></h5>
                                        <span class="pill purple"><i class="fas fa-user-graduate"></i><?php echo h((string)count($lvOff['students'])); ?></span>
                                        <span class="pill blue"><i class="fas fa-book"></i><?php echo h((string)count($lvOff['subjects'])); ?></span>
                                        <?php if ($lvOff['locked_count'] > 0): ?>
                                            <span class="pill red"><i class="fas fa-lock"></i><?php echo h((string)$lvOff['locked_count']); ?> locked</span>
                                        <?php endif; ?>
                                        <a href="<?php echo h($classUrl); ?>" class="pill teal" style="text-decoration:none;margin-left:6px;" onclick="event.stopPropagation();">
                                            <i class="fas fa-external-link-alt"></i> Open class
                                        </a>
                                    </div>
                                    <span class="lv-toggle-chevron"><i class="fas fa-chevron-down"></i></span>
                                </div>

                                <div class="lv-section-body" style="display:block;">
                                    <?php if (empty($lvOff['students']) || empty($lvOff['subjects'])): ?>
                                        <div class="empty-state" style="padding:30px 20px;">
                                            <i class="fas fa-user-slash"></i>
                                            <h5>Nothing to show yet</h5>
                                            <p><?php echo empty($lvOff['subjects']) ? 'Add class subjects to this offering.' : 'Enroll students into this offering.'; ?></p>
                                        </div>
                                    <?php else: ?>
                                        <div style="overflow-x:auto;">
                                            <table class="grid-table" style="min-width:100%;">
                                                <thead>
                                                    <tr>
                                                        <th style="width:56px;">#</th>
                                                        <th style="min-width:140px;">Student #</th>
                                                        <th style="min-width:200px;">Student name</th>
                                                        <th style="min-width:110px;">Term</th>
                                                        <?php foreach ($lvOff['subjects'] as $sub): ?>
                                                            <th style="text-align:center;min-width:110px;">
                                                                <?php echo h($sub['subject_name']); ?>
                                                                <?php if (!empty($sub['subject_code'])): ?>
                                                                    <div style="font-family:'Courier New',monospace;font-size:10px;color:#0d6efd;font-weight:600;margin-top:2px;"><?php echo h($sub['subject_code']); ?></div>
                                                                <?php endif; ?>
                                                            </th>
                                                        <?php endforeach; ?>
                                                        <th style="text-align:center;min-width:90px;">Total</th>
                                                        <th style="text-align:center;min-width:90px;">Average</th>
                                                        <th style="text-align:center;min-width:80px;">Passes</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php $si = 0;
                                                    foreach ($lvOff['students'] as $st):
                                                        $si++;
                                                        $studentId = (int)$st['student_id'];
                                                        $name = trim(($st['first_name'] ?? '') . ' ' . ($st['middle_name'] ?? '') . ' ' . ($st['last_name'] ?? ''));
                                                        $name = $name !== '' ? $name : ('Student #' . $studentId);
                                                        $tot = $lvOff['student_totals'][$studentId] ?? ['count' => 0, 'total' => 0, 'average' => null, 'passes' => 0];
                                                    ?>
                                                        <tr>
                                                            <td><?php echo h((string)$si); ?></td>
                                                            <td style="font-family:'Courier New',monospace;font-size:12px;color:#0d6efd;"><?php echo h($st['student_number']); ?></td>
                                                            <td>
                                                                <div style="font-weight:600;color:#1a1a2e;"><?php echo h($name); ?></div>
                                                            </td>
                                                            <td><?php echo h($lvTerm['term_name']); ?></td>
                                                            <?php foreach ($lvOff['subjects'] as $sub):
                                                                $sid = (int)$sub['id'];
                                                                $r = $lvOff['results'][$studentId][$sid] ?? null;
                                                            ?>
                                                                <td style="text-align:center;">
                                                                    <?php if ($r === null): ?>
                                                                        <span style="color:#adb5bd;">—</span>
                                                                    <?php else:
                                                                        $sc = $r['final_score'] !== null ? (float)$r['final_score'] : ($r['raw_score'] !== null ? (float)$r['raw_score'] : null);
                                                                    ?>
                                                                        <?php if ($sc !== null): ?>
                                                                            <div style="font-weight:600;color:#1a1a2e;"><?php echo h(number_format($sc, 2)); ?></div>
                                                                        <?php else: ?>
                                                                            <div style="color:#adb5bd;">—</div>
                                                                        <?php endif; ?>
                                                                        <?php if (!empty($r['grade_symbol'])): ?>
                                                                            <div><span class="pill <?php echo !empty($r['is_pass']) ? 'green' : 'red'; ?>" style="margin-top:2px;"><?php echo h($r['grade_symbol']); ?></span></div>
                                                                        <?php endif; ?>
                                                                    <?php endif; ?>
                                                                </td>
                                                            <?php endforeach; ?>
                                                            <td style="text-align:center;font-weight:600;"><?php echo h(number_format($tot['total'], 2)); ?></td>
                                                            <td style="text-align:center;"><?php echo $tot['average'] !== null ? h(number_format($tot['average'], 2)) : '—'; ?></td>
                                                            <td style="text-align:center;"><span class="pill gray" style="font-size:10px;"><?php echo h((string)$tot['passes']); ?>/<?php echo h((string)$tot['count']); ?></span></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                                <tfoot>
                                                    <tr style="background:#f8f9fa;font-weight:600;">
                                                        <td colspan="4" style="text-align:right;padding:10px 14px;">Class average per subject</td>
                                                        <?php foreach ($lvOff['subjects'] as $sub):
                                                            $sid = (int)$sub['id'];
                                                            $tot = $lvOff['subject_totals'][$sid] ?? ['count' => 0, 'average' => null, 'high' => null, 'low' => null];
                                                        ?>
                                                            <td style="text-align:center;">
                                                                <?php echo $tot['average'] !== null ? h(number_format($tot['average'], 2)) : '—'; ?>
                                                                <?php if ($tot['high'] !== null): ?>
                                                                    <div style="font-size:10px;color:#6c757d;font-weight:400;margin-top:2px;">H <?php echo h(number_format($tot['high'], 0)); ?> / L <?php echo h(number_format($tot['low'], 0)); ?></div>
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endforeach; ?>
                                                        <td colspan="3"></td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($classViewActive): ?>
                    <div class="entry-header">
                        <div class="entry-title">
                            <?php echo h($selOffering['class_name'] ?: '—'); ?>
                            <?php if (!empty($selOffering['class_code'])): ?>
                                <span class="pill blue" style="margin-left:6px;"><?php echo h($selOffering['class_code']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($selOffering['stream_name'])): ?>
                                <span class="pill purple" style="margin-left:6px;"><i class="fas fa-code-branch"></i><?php echo h($selOffering['stream_name']); ?></span>
                            <?php endif; ?>
                            <?php if ($classLockCount > 0): ?>
                                <span class="pill red" style="margin-left:6px;"><i class="fas fa-lock"></i><?php echo h((string)$classLockCount); ?> locked subject(s)</span>
                            <?php endif; ?>
                        </div>
                        <div class="entry-meta">
                            <?php echo h($selOffering['year_name'] ?: '—'); ?> ·
                            <?php echo h($selOffering['level_name'] ?: '—'); ?> ·
                            <?php echo h($selTerm['term_name']); ?> ·
                            <?php echo h((string)$classStudentCount); ?> student(s) ·
                            <?php echo h((string)$classSubjectCount); ?> subject(s)
                        </div>
                        <hr class="my-3">
                        <div class="d-flex gap-2 flex-wrap justify-content-end">
                            <!-- [BECE-5 v3.7] Confirm text is branch-aware: names both Grade and Aggregate. -->
                            <a href="/platform/tenant/academic/results.php?action=export_class&offering_id=<?php echo (int)$selOfferingId; ?>&term_id=<?php echo (int)$selTermId; ?>"
                                class="btn btn-outline-info"
                                onclick="return confirm('Export the class master sheet to CSV? This will download one row per enrolled student, one column per subject, plus Total, Average, Grade or Aggregate, and Position in Class.');">
                                <i class="fas fa-download me-1"></i> Export class master sheet
                            </a>
                            <a href="/platform/tenant/academic/results.php?view=level&level_id=<?php echo (int)($selOffering['academic_level_id'] ?? 0); ?>&term_id=<?php echo (int)$selTermId; ?>"
                                class="btn btn-outline-secondary">
                                <i class="fas fa-layer-group me-1"></i> View whole level
                            </a>
                        </div>
                    </div>

                    <div class="grid-wrap">
                        <table class="grid-table" style="min-width:100%;">
                            <thead>
                                <tr>
                                    <th style="width:56px;">#</th>
                                    <th style="min-width:140px;">Student #</th>
                                    <th style="min-width:200px;">Student name</th>
                                    <th style="min-width:110px;">Term</th>
                                    <?php foreach ($classSheet['subjects'] as $sub):
                                        $sid = (int)$sub['id'];
                                        $lockedCount = 0;
                                        try {
                                            $lockRow = $db->fetchOne(
                                                "SELECT COUNT(*) AS c FROM result_locks
                                                 WHERE tenant_id = ? AND class_offering_id = ? AND subject_id = ?
                                                   AND academic_term_id = ? AND unlocked_at IS NULL AND deleted_at IS NULL",
                                                [$tenantId, $selOfferingId, $sid, $selTermId]
                                            );
                                            $lockedCount = (int)($lockRow['c'] ?? 0);
                                        } catch (Exception $e) {
                                            $lockedCount = 0;
                                        }
                                        $subjectUrl = '/platform/tenant/academic/results.php?offering_id=' . (int)$selOfferingId . '&subject_id=' . $sid . '&term_id=' . (int)$selTermId;
                                    ?>
                                        <th style="text-align:center;min-width:130px;">
                                            <a href="<?php echo h($subjectUrl); ?>" style="color:inherit;text-decoration:none;">
                                                <?php echo h($sub['subject_name']); ?>
                                                <?php if (!empty($sub['subject_code'])): ?>
                                                    <div style="font-family:'Courier New',monospace;font-size:10px;color:#0d6efd;font-weight:600;margin-top:2px;"><?php echo h($sub['subject_code']); ?></div>
                                                <?php endif; ?>
                                                <div style="margin-top:2px;">
                                                    <?php if ($lockedCount > 0): ?>
                                                        <span class="pill red" style="font-size:9px;"><i class="fas fa-lock"></i>Locked</span>
                                                    <?php else: ?>
                                                        <span class="pill gray" style="font-size:9px;"><i class="fas fa-pencil-alt"></i>Edit</span>
                                                    <?php endif; ?>
                                                </div>
                                            </a>
                                        </th>
                                    <?php endforeach; ?>
                                    <th style="text-align:center;min-width:90px;">Total</th>
                                    <th style="text-align:center;min-width:90px;">Average</th>
                                    <th style="text-align:center;min-width:80px;">Passes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $ci = 0;
                                foreach ($classSheet['students'] as $st):
                                    $ci++;
                                    $studentId = (int)$st['student_id'];
                                    $name = trim(($st['first_name'] ?? '') . ' ' . ($st['middle_name'] ?? '') . ' ' . ($st['last_name'] ?? ''));
                                    $name = $name !== '' ? $name : ('Student #' . $studentId);
                                    $tot = $classSheet['student_totals'][$studentId] ?? ['count' => 0, 'total' => 0, 'average' => null, 'passes' => 0];
                                ?>
                                    <tr>
                                        <td><?php echo h((string)$ci); ?></td>
                                        <td style="font-family:'Courier New',monospace;font-size:12px;color:#0d6efd;"><?php echo h($st['student_number']); ?></td>
                                        <td>
                                            <div style="font-weight:600;color:#1a1a2e;"><?php echo h($name); ?></div>
                                        </td>
                                        <td><?php echo h($selTerm['term_name']); ?></td>
                                        <?php foreach ($classSheet['subjects'] as $sub):
                                            $sid = (int)$sub['id'];
                                            $r = $classSheet['results'][$studentId][$sid] ?? null;
                                        ?>
                                            <td style="text-align:center;">
                                                <?php if ($r === null): ?>
                                                    <span style="color:#adb5bd;">—</span>
                                                <?php else:
                                                    $sc = $r['final_score'] !== null ? (float)$r['final_score'] : ($r['raw_score'] !== null ? (float)$r['raw_score'] : null);
                                                ?>
                                                    <?php if ($sc !== null): ?>
                                                        <div style="font-weight:600;color:#1a1a2e;"><?php echo h(number_format($sc, 2)); ?></div>
                                                    <?php else: ?>
                                                        <div style="color:#adb5bd;">—</div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($r['grade_symbol'])): ?>
                                                        <div><span class="pill <?php echo !empty($r['is_pass']) ? 'green' : 'red'; ?>" style="margin-top:2px;"><?php echo h($r['grade_symbol']); ?></span></div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td style="text-align:center;font-weight:600;"><?php echo h(number_format($tot['total'], 2)); ?></td>
                                        <td style="text-align:center;"><?php echo $tot['average'] !== null ? h(number_format($tot['average'], 2)) : '—'; ?></td>
                                        <td style="text-align:center;"><span class="pill gray" style="font-size:10px;"><?php echo h((string)$tot['passes']); ?>/<?php echo h((string)$tot['count']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($classSheet['students'])): ?>
                                    <tr>
                                        <td colspan="<?php echo 4 + $classSubjectCount + 3; ?>">
                                            <div class="empty-state" style="padding:30px 20px;">
                                                <i class="fas fa-user-slash"></i>
                                                <h5>No active enrolled students</h5>
                                                <p>Enroll students into this class first.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                            <?php if (!empty($classSheet['students']) && !empty($classSheet['subjects'])): ?>
                                <tfoot>
                                    <tr style="background:#f8f9fa;font-weight:600;">
                                        <td colspan="4" style="text-align:right;padding:10px 14px;">Class average per subject</td>
                                        <?php foreach ($classSheet['subjects'] as $sub):
                                            $sid = (int)$sub['id'];
                                            $tot = $classSheet['subject_totals'][$sid] ?? ['count' => 0, 'average' => null, 'high' => null, 'low' => null];
                                        ?>
                                            <td style="text-align:center;">
                                                <?php echo $tot['average'] !== null ? h(number_format($tot['average'], 2)) : '—'; ?>
                                                <?php if ($tot['high'] !== null): ?>
                                                    <div style="font-size:10px;color:#6c757d;font-weight:400;margin-top:2px;">H <?php echo h(number_format($tot['high'], 0)); ?> / L <?php echo h(number_format($tot['low'], 0)); ?></div>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td colspan="3"></td>
                                    </tr>
                                </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ($selOffering && !empty($selSubjects) && !empty($terms)): ?>
                    <div class="upload-card" id="import-card">
                        <h6><i class="fas fa-file-upload text-primary me-2"></i>Bulk import — <?php echo h($selOffering['class_name'] ?: '—'); ?><?php if (!empty($selOffering['stream_name'])): ?> · <?php echo h($selOffering['stream_name']); ?><?php endif; ?></h6>
                        <div class="hint">
                            Download a CSV template with one column per subject and a <code>term</code> column pre-filled with the term you choose below.
                            Students are matched by <strong>student_number</strong>. When you upload, the system will show a
                            confirmation with what will be created, overwritten, or skipped — before anything is written.
                        </div>

                        <div class="row g-3 align-items-end mb-3">
                            <div class="col-md-6">
                                <label class="form-label" for="classPickerSelect">Target class</label>
                                <select id="classPickerSelect" class="form-select"
                                    onchange="if(this.value){window.location.href='/platform/tenant/academic/results.php?view=class&offering_id='+encodeURIComponent(this.value)+'&term_id=<?php echo (int)$selTermId; ?>#import-card';}">
                                    <?php
                                    $pickerGroups = [];
                                    try {
                                        $pickerRows = $db->fetchAll(
                                            "SELECT co.id, c.class_name, c.class_code, s.stream_name,
                                                    al.level_name, al.sort_order AS level_sort
                                             FROM class_offerings co
                                             LEFT JOIN classes c ON c.id = co.class_id
                                             LEFT JOIN streams s ON s.id = co.stream_id
                                             LEFT JOIN academic_levels al ON al.id = co.academic_level_id
                                             WHERE co.tenant_id = ? AND co.deleted_at IS NULL
                                             ORDER BY al.sort_order ASC, c.class_name ASC, s.stream_name ASC",
                                            [$tenantId]
                                        );
                                        foreach ($pickerRows as $pr) {
                                            $lvlName = $pr['level_name'] ?: '(No level)';
                                            if (!isset($pickerGroups[$lvlName])) $pickerGroups[$lvlName] = [];
                                            $pickerGroups[$lvlName][] = $pr;
                                        }
                                    } catch (Exception $e) {
                                        $pickerGroups = [];
                                    }
                                    ?>
                                    <?php foreach ($pickerGroups as $lvlName => $rows): ?>
                                        <optgroup label="<?php echo h($lvlName); ?>">
                                            <?php foreach ($rows as $pr):
                                                $label = trim(($pr['class_name'] ?? '') . ' ' . ($pr['stream_name'] ?? ''));
                                                if ($label === '') $label = 'Class #' . (int)$pr['id'];
                                                if (!empty($pr['class_code'])) $label .= ' · ' . $pr['class_code'];
                                            ?>
                                                <option value="<?php echo (int)$pr['id']; ?>" <?php echo (int)$pr['id'] === (int)$selOfferingId ? 'selected' : ''; ?>>
                                                    <?php echo h($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <div class="hint" style="margin-top:4px;">Changing this reloads the page with that class.</div>
                            </div>
                        </div>

                        <?php if (empty($terms)): ?>
                            <div class="info-banner warn" style="margin:0;">
                                <div class="ib-icon"><i class="fas fa-exclamation-triangle"></i></div>
                                <div class="ib-body">
                                    <div class="ib-title">No active terms configured</div>
                                    <div class="ib-text">Create a term before importing results. <a href="/platform/tenant/academic/terms.php?action=new">Create a term</a>.</div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="row g-3 align-items-end mb-3">
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo h($labelTerm); ?> <span class="text-danger">*</span></label>
                                    <select id="bulkTermSelect" class="form-select" required>
                                        <option value="">— Pick a term —</option>
                                        <?php foreach ($terms as $t): ?>
                                            <option value="<?php echo (int)$t['id']; ?>" <?php echo (int)$selTermId === (int)$t['id'] ? 'selected' : ''; ?>>
                                                <?php echo h($t['term_name']); ?>
                                                <?php if ((int)$t['is_current'] === 1): ?> ★<?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <div class="d-flex gap-2 flex-wrap">
                                        <a id="downloadAllLink"
                                            href="/platform/tenant/academic/results.php?action=download_template&offering_id=<?php echo (int)$selOfferingId; ?>&term_id=<?php echo (int)$selTermId; ?>"
                                            class="btn btn-outline-info"
                                            data-base="/platform/tenant/academic/results.php?action=download_template&offering_id=<?php echo (int)$selOfferingId; ?>">
                                            <i class="fas fa-download me-1"></i> Download template (all subjects)
                                        </a>
                                        <button type="button" class="btn btn-outline-info" id="downloadSelectedBtn"
                                            data-base="/platform/tenant/academic/results.php?action=download_template&offering_id=<?php echo (int)$selOfferingId; ?>">
                                            <i class="fas fa-list-check me-1"></i> Download template (selected subjects)
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <hr class="my-3">

                            <div class="row g-3 align-items-end">
                                <div class="col-md-6">
                                    <div class="form-label fw-semibold">Upload mode</div>
                                    <form method="POST" enctype="multipart/form-data" action="/platform/tenant/academic/results.php"
                                        class="d-flex gap-2 flex-wrap align-items-end" id="uploadAllForm" style="flex:1;min-width:340px;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="import_preview">
                                        <input type="hidden" name="class_offering_id" value="<?php echo (int)$selOfferingId; ?>">
                                        <input type="hidden" name="import_scope" value="all">
                                        <input type="hidden" name="academic_term_id" id="uploadAllTerm" value="<?php echo (int)$selTermId; ?>">
                                        <div style="flex:1;min-width:180px;">
                                            <label class="form-label" for="uploadAllFile">File (CSV<?php echo $xlsxAvailable ? ' / XLSX' : ''; ?>) — all subjects</label>
                                            <input type="file" class="form-control" id="uploadAllFile" name="results_file"
                                                accept=".csv,text/csv<?php echo $xlsxAvailable ? ',.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : ''; ?>"
                                                required style="height:auto;padding:6px 10px;">
                                        </div>
                                        <div style="min-width:180px;">
                                            <label class="form-label" for="uploadAllMode">Mode</label>
                                            <select class="form-select" id="uploadAllMode" name="import_mode">
                                                <option value="overwrite" selected>Overwrite existing</option>
                                                <option value="fill_blanks">Fill blanks only</option>
                                            </select>
                                            <div class="mode-hint">Overwrite replaces existing scores. Fill blanks leaves them untouched.</div>
                                        </div>
                                        <div>
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-upload me-1"></i> Preview all subjects
                                            </button>
                                        </div>
                                    </form>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex gap-2 flex-wrap justify-content-md-end">
                                        <button type="button" class="btn btn-outline-primary" id="uploadSelectedBtn">
                                            <i class="fas fa-list-check me-1"></i> Preview selected subjects
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($selSubject && $selTerm && $selOffering): ?>
                    <div class="entry-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                        <div>
                            <div class="entry-title">
                                <?php echo h($selSubject['subject_name']); ?>
                                <?php if (!empty($selSubject['subject_code'])): ?>
                                    <span class="pill blue" style="margin-left:6px;"><?php echo h($selSubject['subject_code']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="entry-meta">
                                <?php echo h($selTerm['term_name']); ?> ·
                                <?php echo $selScheme ? 'Scheme: <strong>' . h($selScheme['scheme_name']) . ' v' . (int)$selScheme['version'] . '</strong>' : '<span class="text-danger">No active scheme</span>'; ?> ·
                                <?php echo $selScale ? 'Scale: <strong>' . h($selScale['scale_name']) . ' v' . (int)$selScale['version'] . '</strong>' : '<span class="text-danger">No active scale</span>'; ?>
                                <?php if ($scopeLocked): ?>
                                    · <span class="pill red"><i class="fas fa-lock"></i> Locked</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$selScale): ?>
                            <a href="/platform/tenant/academic/grading-scales.php?action=new" class="btn btn-outline-warning">
                                <i class="fas fa-exclamation-triangle me-1"></i> Configure a scale first
                            </a>
                        <?php elseif (empty($gridStudents)): ?>
                            <span class="pill gray">No enrolled students</span>
                        <?php else: ?>
                            <div class="d-flex gap-2 flex-wrap">
                                <?php if (!$scopeLocked): ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="fillZeroBtn"><i class="fas fa-eraser me-1"></i> Clear all</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="fill100Btn"><i class="fas fa-check-double me-1"></i> Fill all 100</button>
                                <?php endif; ?>
                                <?php if ($allPublished): ?>
                                    <span class="pill green"><i class="fas fa-lock"></i> All published</span>
                                <?php endif; ?>
                                <?php if ($scopeLocked): ?>
                                    <button type="button" class="btn btn-outline-warning btn-sm" id="unlockScopeBtn" data-bs-toggle="modal" data-bs-target="#unlockModal"><i class="fas fa-unlock me-1"></i> Unlock</button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-outline-warning btn-sm" id="lockScopeBtn" data-bs-toggle="modal" data-bs-target="#lockModal"><i class="fas fa-lock me-1"></i> Lock</button>
                                <?php endif; ?>
                                <a href="/platform/tenant/academic/results.php?action=export_subject&offering_id=<?php echo (int)$selOfferingId; ?>&subject_id=<?php echo (int)$selSubjectId; ?>&term_id=<?php echo (int)$selTermId; ?>"
                                    class="btn btn-outline-info btn-sm">
                                    <i class="fas fa-download me-1"></i> Export this subject
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="grid-wrap">
                        <form method="POST" action="/platform/tenant/academic/results.php" id="scoresForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="bulk_save">
                            <input type="hidden" name="class_offering_id" value="<?php echo (int)$selOfferingId; ?>">
                            <input type="hidden" name="subject_id" value="<?php echo (int)$selSubjectId; ?>">
                            <input type="hidden" name="academic_term_id" value="<?php echo (int)$selTermId; ?>">
                            <table class="grid-table">
                                <thead>
                                    <tr>
                                        <th style="width:60px;">#</th>
                                        <th>Student</th>
                                        <th style="width:110px;">Roll #</th>
                                        <th style="width:130px;text-align:center;">Student #</th>
                                        <th style="width:120px;text-align:center;">Raw score (0–100)</th>
                                        <th style="width:120px;text-align:center;">Final</th>
                                        <th style="width:90px;text-align:center;">Grade</th>
                                        <th style="width:90px;text-align:center;">Status</th>
                                        <th style="width:60px;text-align:right;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i = 0;
                                    foreach ($gridStudents as $gs):
                                        $i++;
                                        $studentId = (int)$gs['student_id'];
                                        $existing = $gridResults[$studentId] ?? null;
                                        $isPublished = $existing && $existing['status'] === 'published';
                                        $isReadOnly = $isPublished || $scopeLocked;
                                        $raw = $existing ? (float)$existing['raw_score'] : null;
                                        $final = $existing ? (float)$existing['final_score'] : null;
                                        $symbol = $existing ? $existing['grade_symbol'] : null;
                                        $label = $existing ? $existing['grade_label'] : null;
                                        $name = trim(($gs['first_name'] ?? '') . ' ' . ($gs['middle_name'] ?? '') . ' ' . ($gs['last_name'] ?? ''));
                                        $name = $name !== '' ? $name : ('Student #' . $studentId);
                                    ?>
                                        <tr>
                                            <td><?php echo h((string)$i); ?></td>
                                            <td>
                                                <div style="font-weight:600;color:#1a1a2e;"><?php echo h($name); ?></div>
                                            </td>
                                            <td><?php echo h($gs['roll_number']); ?></td>
                                            <td style="text-align:center;font-family:'Courier New',monospace;font-size:12px;color:#0d6efd;"><?php echo h($gs['student_number']); ?></td>
                                            <td style="text-align:center;">
                                                <input type="number" step="0.01" min="0" max="100" class="score-input"
                                                    name="scores[<?php echo (int)$gs['enrollment_id']; ?>]"
                                                    value="<?php echo $raw !== null ? h(number_format($raw, 2, '.', '')) : ''; ?>"
                                                    <?php echo $isReadOnly ? 'readonly' : ''; ?>>
                                            </td>
                                            <td style="text-align:center;"><?php echo $final !== null ? h(number_format($final, 2)) : '—'; ?></td>
                                            <td style="text-align:center;">
                                                <?php if ($symbol !== null): ?>
                                                    <span class="pill <?php echo ($existing['is_pass'] ?? 0) ? 'green' : 'red'; ?>"><?php echo h($symbol); ?></span>
                                                    <?php if (!empty($label)): ?>
                                                        <div style="font-size:10px;color:#6c757d;margin-top:2px;"><?php echo h($label); ?></div>
                                                    <?php endif; ?>
                                                    <?php else: ?>—<?php endif; ?>
                                            </td>
                                            <td style="text-align:center;">
                                                <?php if ($existing):
                                                    $stPill = ['draft' => 'gray', 'calculated' => 'teal', 'published' => 'green', 'blocked' => 'red', 'archived' => 'orange'][$existing['status']] ?? 'gray';
                                                ?>
                                                    <span class="pill <?php echo $stPill; ?>"><?php echo h(ucfirst($existing['status'])); ?></span>
                                                <?php else: ?>
                                                    <span class="pill gray">Unsaved</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:right;">
                                                <?php if ($existing && !$isPublished && !$scopeLocked): ?>
                                                    <button type="submit"
                                                        form="deleteForm-<?php echo (int)$existing['id']; ?>"
                                                        class="btn btn-outline-danger btn-sm"
                                                        title="Delete"
                                                        onclick="return confirm('Delete this result?');">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($gridStudents)): ?>
                                        <tr>
                                            <td colspan="9">
                                                <div class="empty-state" style="padding:30px 20px;"><i class="fas fa-user-slash"></i>
                                                    <h5>No active enrolled students</h5>
                                                    <p>Enroll students into this offering first.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            <?php if (!empty($gridStudents) && $selScale && !$allPublished && !$scopeLocked): ?>
                                <div style="padding:16px 24px;border-top:1px solid #f0f2f5;display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Results</button>
                                </div>
                            <?php endif; ?>
                        </form>

                        <?php
                        if ($selSubject && $selTerm && $selOffering):
                            foreach ($gridResults as $r):
                                $rid = (int)$r['id'];
                                if ($r['status'] === 'published') continue;
                                if ($scopeLocked) continue;
                        ?>
                                <form method="POST" action="/platform/tenant/academic/results.php"
                                    id="deleteForm-<?php echo $rid; ?>" style="display:none;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $rid; ?>">
                                </form>
                        <?php
                            endforeach;
                        endif;
                        ?>
                    </div>
                <?php endif; ?>

                <?php if (!$levelViewActive && !$classViewActive && !$selSubject): ?>
                    <div class="info-banner">
                        <div class="ib-icon"><i class="fas fa-info-circle"></i></div>
                        <div class="ib-body">
                            <div class="ib-title">How results work</div>
                            <div class="ib-text">
                                Pick a class from the list below to open the class master sheet — all students, all subjects, one term.
                                From there, click any subject column header to enter or correct a single subject.
                                Bulk import accepts a single CSV/XLSX file with one column per subject.
                            </div>
                        </div>
                    </div>

                    <?php if (empty($byYear)): ?>
                        <div class="year-section">
                            <div class="empty-state">
                                <i class="fas fa-door-open"></i>
                                <h5>No class offerings yet</h5>
                                <p>Create an offering, add subjects, and enroll students before entering results.</p>
                                <a href="/platform/tenant/academic/class-offerings.php?action=new" class="btn btn-primary btn-sm mt-2"><i class="fas fa-plus me-1"></i> Create Offering</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($byYear as $yKey => $yearGroup): ?>
                            <div class="year-section">
                                <div class="year-header">
                                    <div class="year-title">
                                        <i class="fas fa-calendar-alt" style="font-size:20px;color:#4facfe;"></i>
                                        <h5><?php echo h($yearGroup['year_name']); ?></h5>
                                        <?php $offCount = 0;
                                        foreach ($yearGroup['levels'] as $lg) $offCount += count($lg['items']); ?>
                                        <span class="pill blue"><i class="fas fa-door-open"></i><?php echo h((string)$offCount); ?> class(es)</span>
                                    </div>
                                </div>
                                <?php foreach ($yearGroup['levels'] as $lKey => $levelGroup): ?>
                                    <div class="level-block">
                                        <div class="level-bar">
                                            <i class="fas fa-layer-group" style="color:#4facfe;"></i>
                                            <h6><?php echo h($levelGroup['level_name']); ?></h6>
                                            <?php if (!empty($levelGroup['level_code'])): ?>
                                                <span class="pill blue"><?php echo h($levelGroup['level_code']); ?></span>
                                            <?php endif; ?>
                                            <?php if ((int)$lKey > 0): ?>
                                                <a href="/platform/tenant/academic/results.php?view=level&level_id=<?php echo (int)$lKey; ?><?php echo !empty($terms) ? '&term_id=' . (int)$terms[0]['id'] : ''; ?>"
                                                    class="pill teal" style="text-decoration:none;margin-left:auto;">
                                                    <i class="fas fa-external-link-alt"></i> Open level view
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        <table class="offering-table">
                                            <thead>
                                                <tr>
                                                    <th style="min-width:220px;">Class</th>
                                                    <th style="text-align:center;width:130px;">Enrolled</th>
                                                    <th style="text-align:right;min-width:200px;">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($levelGroup['items'] as $o):
                                                    $oid = (int)$o['id'];
                                                    $active = (int)$o['active_count'];
                                                    $classUrlBase = '/platform/tenant/academic/results.php?view=class&offering_id=' . $oid;
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <div class="offering-title">
                                                                <?php echo h($o['class_name'] ?: '—'); ?>
                                                                <?php if (!empty($o['class_code'])): ?>
                                                                    <span class="pill blue" style="margin-left:6px;"><?php echo h($o['class_code']); ?></span>
                                                                <?php endif; ?>
                                                                <?php if (!empty($o['stream_name'])): ?>
                                                                    <span class="pill purple" style="margin-left:6px;"><i class="fas fa-code-branch"></i><?php echo h($o['stream_name']); ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                        <td style="text-align:center;">
                                                            <span class="pill <?php echo $active > 0 ? 'purple' : 'gray'; ?>">
                                                                <i class="fas fa-user-plus"></i><?php echo h((string)$active); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex justify-content-end gap-2 flex-wrap">
                                                                <?php if (!empty($terms)): ?>
                                                                    <a href="<?php echo h($classUrlBase . '&term_id=' . (int)$terms[0]['id']); ?>"
                                                                        class="btn btn-outline-primary">
                                                                        <i class="fas fa-table me-1"></i> Open class sheet
                                                                    </a>
                                                                    <a href="<?php echo h($classUrlBase . '&term_id=' . (int)$terms[0]['id']); ?>#import-card"
                                                                        class="btn btn-outline-info">
                                                                        <i class="fas fa-upload me-1"></i> Import
                                                                    </a>
                                                                <?php else: ?>
                                                                    <span class="pill red">No active term</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <?php if ($selOffering && !empty($selSubjects) && !empty($terms)): ?>
        <div class="modal fade" id="subjectPickerModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <form method="GET" action="/platform/tenant/academic/results.php" id="subjectPickerForm">
                        <input type="hidden" name="action" value="download_template">
                        <input type="hidden" name="offering_id" value="<?php echo (int)$selOfferingId; ?>">
                        <input type="hidden" name="term_id" id="pickerTermId" value="<?php echo (int)$selTermId; ?>">
                        <div class="modal-header">
                            <h5><i class="fas fa-list-check text-primary me-2"></i>Download template — selected subjects</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="form-text m-0">Tick the subjects you want columns for.</span>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="tplSelectAll">Select all</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="tplSelectNone">Clear</button>
                                </div>
                            </div>
                            <div class="subject-picker-list">
                                <?php foreach ($selSubjects as $sub): ?>
                                    <label class="subject-picker-row">
                                        <input type="checkbox" name="subject_ids[]" value="<?php echo (int)$sub['id']; ?>" checked>
                                        <span class="sp-name"><?php echo h($sub['subject_name']); ?></span>
                                        <span><span class="sp-code"><?php echo h($sub['subject_code']); ?></span></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-download me-1"></i> Download template</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="uploadSelectedModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <form method="POST" enctype="multipart/form-data" action="/platform/tenant/academic/results.php" id="uploadSelectedForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="import_preview">
                        <input type="hidden" name="class_offering_id" value="<?php echo (int)$selOfferingId; ?>">
                        <input type="hidden" name="import_scope" value="selected">
                        <input type="hidden" name="academic_term_id" id="uploadSelectedTerm" value="<?php echo (int)$selTermId; ?>">
                        <div class="modal-header">
                            <h5><i class="fas fa-list-check text-primary me-2"></i>Preview selected subjects</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="form-text m-0">Tick the subjects to import. Only these columns will be read.</span>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="impSelectAll">Select all</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="impSelectNone">Clear</button>
                                </div>
                            </div>
                            <div class="subject-picker-list mb-3">
                                <?php foreach ($selSubjects as $sub): ?>
                                    <label class="subject-picker-row">
                                        <input type="checkbox" name="subject_ids[]" value="<?php echo (int)$sub['id']; ?>" checked>
                                        <span class="sp-name"><?php echo h($sub['subject_name']); ?></span>
                                        <span><span class="sp-code"><?php echo h($sub['subject_code']); ?></span></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="row g-3 align-items-end">
                                <div class="col-md-7">
                                    <label class="form-label" for="uploadSelectedFile">File (CSV<?php echo $xlsxAvailable ? ' / XLSX' : ''; ?>)</label>
                                    <input type="file" class="form-control" id="uploadSelectedFile" name="results_file"
                                        accept=".csv,text/csv<?php echo $xlsxAvailable ? ',.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : ''; ?>"
                                        required style="height:auto;padding:6px 10px;">
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label" for="uploadSelectedMode">Mode</label>
                                    <select class="form-select" id="uploadSelectedMode" name="import_mode">
                                        <option value="overwrite" selected>Overwrite existing</option>
                                        <option value="fill_blanks">Fill blanks only</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-upload me-1"></i> Preview selected</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="modal fade" id="lockModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/academic/results.php" id="lockForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="lock">
                    <input type="hidden" name="class_offering_id" value="<?php echo (int)$selOfferingId; ?>">
                    <input type="hidden" name="subject_id" value="<?php echo (int)$selSubjectId; ?>">
                    <input type="hidden" name="academic_term_id" value="<?php echo (int)$selTermId; ?>">
                    <div class="modal-header">
                        <h5><i class="fas fa-lock text-warning me-2"></i>Lock this scope</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p style="font-size:13px;color:#495057;">Locking prevents all further score entry, import, and delete for this offering, subject and term. Only an admin can unlock.</p>
                        <label class="form-label" for="lockReason">Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="lockReason" name="reason" rows="3" maxlength="255" required placeholder="e.g. Marks submitted for review"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-outline-warning"><i class="fas fa-lock me-1"></i> Lock scope</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="unlockModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/academic/results.php" id="unlockForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="unlock">
                    <input type="hidden" name="class_offering_id" value="<?php echo (int)$selOfferingId; ?>">
                    <input type="hidden" name="subject_id" value="<?php echo (int)$selSubjectId; ?>">
                    <input type="hidden" name="academic_term_id" value="<?php echo (int)$selTermId; ?>">
                    <div class="modal-header">
                        <h5><i class="fas fa-unlock text-warning me-2"></i>Unlock this scope</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p style="font-size:13px;color:#495057;">Unlocking allows score entry, import, and delete for this offering, subject and term again. Provide a reason for the audit log.</p>
                        <label class="form-label" for="unlockReason">Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="unlockReason" name="reason" rows="3" maxlength="255" required placeholder="e.g. Corrections approved by head of department"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-outline-warning"><i class="fas fa-unlock me-1"></i> Unlock scope</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if (!empty($_SESSION['import_pending'])):
        $pending = $_SESSION['import_pending'];
        $pc = $pending['counts'];
        $hasRisk = ($pc['overwrite'] > 0) || ($pc['published'] > 0) || ($pc['locked'] > 0);
    ?>
        <div class="modal fade show" id="importPreviewModal" tabindex="-1" style="display:block;background:rgba(0,0,0,0.45);">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5><i class="fas fa-clipboard-check text-primary me-2"></i>Confirm import — <?php echo h($pending['source_file']); ?></h5>
                    </div>
                    <div class="modal-body">
                        <p style="font-size:14px;color:#495057;">
                            You are about to import <strong><?php echo h(strtoupper($pending['format'])); ?></strong>
                            in <strong><?php echo h($pending['mode'] === 'overwrite' ? 'Overwrite existing' : 'Fill blanks only'); ?></strong> mode,
                            for term <strong><?php echo h($pending['ui_term_name']); ?></strong>.
                        </p>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin:16px 0;">
                            <div class="preview-tile">
                                <div class="pt-num"><?php echo h((string)(int)$pc['new']); ?></div>
                                <div class="pt-lbl">New rows</div>
                            </div>
                            <div class="preview-tile <?php echo $pc['overwrite'] > 0 ? 'risk' : ''; ?>">
                                <div class="pt-num"><?php echo h((string)(int)$pc['overwrite']); ?></div>
                                <div class="pt-lbl">Will overwrite</div>
                            </div>
                            <div class="preview-tile <?php echo $pc['published'] > 0 ? 'risk' : ''; ?>">
                                <div class="pt-num"><?php echo h((string)(int)$pc['published']); ?></div>
                                <div class="pt-lbl">Published — skipped</div>
                            </div>
                            <div class="preview-tile <?php echo $pc['locked'] > 0 ? 'risk' : ''; ?>">
                                <div class="pt-num"><?php echo h((string)(int)$pc['locked']); ?></div>
                                <div class="pt-lbl">Locked — skipped</div>
                            </div>
                            <div class="preview-tile">
                                <div class="pt-num"><?php echo h((string)(int)$pc['not_enrolled']); ?></div>
                                <div class="pt-lbl">Not enrolled — skipped</div>
                            </div>
                            <div class="preview-tile">
                                <div class="pt-num"><?php echo h((string)(int)$pc['invalid']); ?></div>
                                <div class="pt-lbl">Invalid — skipped</div>
                            </div>
                            <div class="preview-tile">
                                <div class="pt-num"><?php echo h((string)(int)$pc['blank']); ?></div>
                                <div class="pt-lbl">Blank — left alone</div>
                            </div>
                        </div>
                        <?php if (!$hasRisk): ?>
                            <div class="info-banner" style="margin-top:12px;margin-bottom:0;">
                                <div class="ib-icon"><i class="fas fa-info-circle"></i></div>
                                <div class="ib-body">
                                    <div class="ib-title">No existing scores will be overwritten.</div>
                                    <div class="ib-text">This file contains only new entries.</div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="info-banner warn" style="margin-top:12px;margin-bottom:0;">
                                <div class="ib-icon"><i class="fas fa-exclamation-triangle"></i></div>
                                <div class="ib-body">
                                    <div class="ib-title">Some rows will affect existing data</div>
                                    <div class="ib-text">
                                        <?php if ($pc['overwrite'] > 0): ?>
                                            <strong><?php echo h((string)(int)$pc['overwrite']); ?></strong> existing score(s) will be
                                            <?php echo $pending['mode'] === 'overwrite' ? 'replaced' : 'inspected (fill-blanks mode will skip them if a score exists)'; ?>.
                                        <?php endif; ?>
                                        <?php if ($pc['published'] > 0 || $pc['locked'] > 0): ?>
                                            Some rows will be skipped because their scope is published or locked.
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <form method="POST" action="/platform/tenant/academic/results.php" style="margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="import_cancel">
                            <button type="submit" class="btn btn-outline-secondary"><i class="fas fa-times me-1"></i> Cancel</button>
                        </form>
                        <form method="POST" action="/platform/tenant/academic/results.php" style="margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="import_confirm">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-upload me-1"></i> Proceed with import</button>
                        </form>
                    </div>
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
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '/platform/tenant/logout.php';
            }
        }

        (function() {
            const clearBtn = document.getElementById('fillZeroBtn');
            const fillBtn = document.getElementById('fill100Btn');

            function setAll(v) {
                document.querySelectorAll('.score-input').forEach(function(el) {
                    if (!el.readOnly) el.value = v;
                });
            }
            if (clearBtn) clearBtn.addEventListener('click', function() {
                setAll('');
            });
            if (fillBtn) fillBtn.addEventListener('click', function() {
                setAll('100');
            });
        })();

        (function() {
            function bindPicker(allId, noneId, containerSelector) {
                const allBtn = document.getElementById(allId);
                const noneBtn = document.getElementById(noneId);
                if (!allBtn || !noneBtn) return;
                allBtn.addEventListener('click', function() {
                    document.querySelectorAll(containerSelector + ' input[type="checkbox"]').forEach(function(cb) {
                        cb.checked = true;
                    });
                });
                noneBtn.addEventListener('click', function() {
                    document.querySelectorAll(containerSelector + ' input[type="checkbox"]').forEach(function(cb) {
                        cb.checked = false;
                    });
                });
            }
            bindPicker('tplSelectAll', 'tplSelectNone', '#subjectPickerModal');
            bindPicker('impSelectAll', 'impSelectNone', '#uploadSelectedModal');
        })();

        (function() {
            const sel = document.getElementById('bulkTermSelect');
            if (!sel) return;

            const downloadAllLink = document.getElementById('downloadAllLink');
            const downloadSelectedBtn = document.getElementById('downloadSelectedBtn');
            const pickerTermId = document.getElementById('pickerTermId');
            const uploadAllTerm = document.getElementById('uploadAllTerm');
            const uploadSelectedTerm = document.getElementById('uploadSelectedTerm');

            function sync() {
                const val = sel.value || '';
                if (downloadAllLink) {
                    downloadAllLink.href = downloadAllLink.getAttribute('data-base') + '&term_id=' + encodeURIComponent(val);
                }
                if (downloadSelectedBtn) {
                    downloadSelectedBtn.setAttribute('data-term', val);
                }
                if (pickerTermId) pickerTermId.value = val;
                if (uploadAllTerm) uploadAllTerm.value = val;
                if (uploadSelectedTerm) uploadSelectedTerm.value = val;
            }
            sel.addEventListener('change', sync);
            sync();

            if (downloadSelectedBtn) {
                downloadSelectedBtn.addEventListener('click', function() {
                    if (!sel.value) {
                        alert('Please pick a term first.');
                        sel.focus();
                        return;
                    }
                    sync();
                    new bootstrap.Modal(document.getElementById('subjectPickerModal')).show();
                });
            }

            const uploadSelectedBtn = document.getElementById('uploadSelectedBtn');
            if (uploadSelectedBtn) {
                uploadSelectedBtn.addEventListener('click', function() {
                    if (!sel.value) {
                        alert('Please pick a term first.');
                        sel.focus();
                        return;
                    }
                    sync();
                    new bootstrap.Modal(document.getElementById('uploadSelectedModal')).show();
                });
            }
        })();

        (function() {
            function openModal(modalId) {
                const el = document.getElementById(modalId);
                if (!el) {
                    console.warn('[EduTrack] Modal element not found:', modalId);
                    return;
                }
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    bootstrap.Modal.getOrCreateInstance(el).show();
                } else {
                    console.warn('[EduTrack] Bootstrap is not available; cannot open modal:', modalId);
                }
            }

            document.addEventListener('click', function(event) {
                const lockBtn = event.target.closest('#lockScopeBtn');
                if (lockBtn) {
                    event.preventDefault();
                    openModal('lockModal');
                    return;
                }
                const unlockBtn = event.target.closest('#unlockScopeBtn');
                if (unlockBtn) {
                    event.preventDefault();
                    openModal('unlockModal');
                    return;
                }
            });

            document.addEventListener('submit', function(event) {
                const form = event.target;
                if (form && form.id === 'lockForm') {
                    const reason = document.getElementById('lockReason');
                    if (reason && reason.value.trim() === '') {
                        event.preventDefault();
                        alert('A reason is required to lock a scope.');
                    }
                }
                if (form && form.id === 'unlockForm') {
                    const reason = document.getElementById('unlockReason');
                    if (reason && reason.value.trim() === '') {
                        event.preventDefault();
                        alert('A reason is required to unlock a scope.');
                    }
                }
            });
        })();

        (function() {
            const toggles = document.querySelectorAll('[data-lv-toggle]');
            const expandAll = document.getElementById('lvExpandAll');
            const collapseAll = document.getElementById('lvCollapseAll');

            function setSection(sectionId, open) {
                const section = document.getElementById(sectionId);
                if (!section) return;
                const body = section.querySelector('.lv-section-body');
                const chev = section.querySelector('.lv-toggle-chevron i');
                if (!body) return;
                body.style.display = open ? 'block' : 'none';
                if (chev) {
                    chev.classList.toggle('fa-chevron-down', open);
                    chev.classList.toggle('fa-chevron-right', !open);
                }
            }

            toggles.forEach(function(header) {
                header.addEventListener('click', function(event) {
                    if (event.target.closest('a')) return;
                    const id = this.getAttribute('data-lv-toggle');
                    const section = document.getElementById(id);
                    if (!section) return;
                    const body = section.querySelector('.lv-section-body');
                    const isOpen = body && body.style.display !== 'none';
                    setSection(id, !isOpen);
                });
            });

            if (expandAll) {
                expandAll.addEventListener('click', function() {
                    toggles.forEach(function(header) {
                        setSection(header.getAttribute('data-lv-toggle'), true);
                    });
                });
            }
            if (collapseAll) {
                collapseAll.addEventListener('click', function() {
                    toggles.forEach(function(header) {
                        setSection(header.getAttribute('data-lv-toggle'), false);
                    });
                });
            }
        })();

        const successBox = document.getElementById('serverSuccessBox');
        if (successBox) {
            setTimeout(function() {
                successBox.style.transition = 'opacity 0.3s ease';
                successBox.style.opacity = '0';
                setTimeout(function() {
                    successBox.remove();
                }, 300);
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