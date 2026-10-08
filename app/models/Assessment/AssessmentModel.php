<?php
/**
 * AssessmentModel.php
 *
 * Enterprise Assessment Model - DATA ACCESS LAYER
 * Handles all assessment-related database operations
 * Contains ALL methods required by AssessmentService
 *
 * @package EduTrack
 * @subpackage Models\Assessment
 * @version 3.0
 */

require_once __DIR__ . '/../../helpers/DatabaseHelper.php';
require_once __DIR__ . '/../../helpers/LoggerHelper.php';

class AssessmentModel
{
    /**
     * @var DatabaseHelper Database connection instance
     */
    private $db;

    /**
     * @var LoggerHelper Logger instance
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
    }

    // ==============================================================
    // 1. GRADING SCALES - CRUD OPERATIONS
    // ==============================================================

    public function getGradingScales(int $schoolId = 1, int $page = 1, int $limit = 50): array
    {
        try {
            $offset = ($page - 1) * $limit;
            $sql = "SELECT * FROM grading_scales 
                    WHERE school_id = ? AND is_active = 1 
                    ORDER BY scale_type, min_value DESC 
                    LIMIT ? OFFSET ?";
            return $this->db->fetchAll($sql, [$schoolId, $limit, $offset]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get grading scales', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getAllGradingScales(int $schoolId = 1): array
    {
        try {
            $sql = "SELECT * FROM grading_scales 
                    WHERE school_id = ? AND is_active = 1 
                    ORDER BY scale_type, min_value DESC";
            return $this->db->fetchAll($sql, [$schoolId]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get all grading scales', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getGradingScaleById(int $id): ?array
    {
        try {
            $sql = "SELECT * FROM grading_scales WHERE id = ? AND is_active = 1";
            return $this->db->fetchOne($sql, [$id]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get grading scale by ID', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function getGradingScalesByType(string $scaleType, int $schoolId = 1): array
    {
        try {
            $sql = "SELECT * FROM grading_scales 
                    WHERE school_id = ? AND scale_type = ? AND is_active = 1 
                    ORDER BY min_value DESC";
            return $this->db->fetchAll($sql, [$schoolId, $scaleType]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get grading scales by type', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getGradeFromPercentage(float $percentage, int $schoolId = 1): ?array
    {
        try {
            $sql = "SELECT * FROM grading_scales 
                    WHERE school_id = ? AND is_active = 1 
                    AND min_value <= ? AND max_value >= ? 
                    ORDER BY min_value DESC 
                    LIMIT 1";
            return $this->db->fetchOne($sql, [$schoolId, $percentage, $percentage]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get grade from percentage', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function createGradingScale(array $data)
    {
        try {
            $required = ['scale_name', 'scale_code', 'min_value', 'max_value'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: {$field}");
                }
            }

            if ($data['min_value'] >= $data['max_value']) {
                throw new Exception('Min value must be less than max value');
            }

            $existing = $this->db->fetchOne(
                "SELECT id FROM grading_scales 
                 WHERE school_id = ? AND scale_code = ? AND scale_type = ? AND is_active = 1",
                [$data['school_id'] ?? 1, $data['scale_code'], $data['scale_type'] ?? 'percentage']
            );
            if ($existing) {
                throw new Exception('Grading scale code already exists for this type');
            }

            $sql = "INSERT INTO grading_scales (
                        uuid, school_id, scale_name, scale_code, scale_type,
                        min_value, max_value, grade_letter, grade_point,
                        description, is_default, is_active, created_by, created_at
                    ) VALUES (
                        UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                    )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['scale_name'],
                $data['scale_code'],
                $data['scale_type'] ?? 'percentage',
                $data['min_value'],
                $data['max_value'],
                $data['grade_letter'] ?? null,
                $data['grade_point'] ?? null,
                $data['description'] ?? null,
                $data['is_default'] ?? 0,
                1,
                $data['created_by'] ?? null
            ]);

            return $this->db->lastInsertId();
        } catch (Exception $e) {
            $this->logger->error('Failed to create grading scale', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function updateGradingScale(int $id, array $data): bool
    {
        try {
            $oldData = $this->getGradingScaleById($id);
            if (!$oldData) {
                throw new Exception('Grading scale not found');
            }

            if (isset($data['min_value']) && isset($data['max_value']) && 
                $data['min_value'] >= $data['max_value']) {
                throw new Exception('Min value must be less than max value');
            }

            $fields = [];
            $params = [];
            $allowedFields = [
                'scale_name', 'scale_code', 'scale_type', 
                'min_value', 'max_value', 'grade_letter', 'grade_point',
                'description', 'is_default', 'is_active'
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "{$field} = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $params[] = $id;
            $sql = "UPDATE grading_scales SET " . implode(', ', $fields) . 
                   ", updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, $params);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update grading scale', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function deleteGradingScale(int $id): bool
    {
        try {
            $inUse = $this->db->fetchOne(
                "SELECT id FROM grading_schemes WHERE grading_scale_id = ? AND is_active = 1",
                [$id]
            );
            if ($inUse) {
                throw new Exception('Cannot delete grading scale as it is in use');
            }

            $sql = "UPDATE grading_scales SET is_active = 0, updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, [$id]);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to delete grading scale', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function setDefaultGradingScale(int $id, int $schoolId = 1): bool
    {
        try {
            $this->db->query(
                "UPDATE grading_scales SET is_default = 0 WHERE school_id = ?",
                [$schoolId]
            );
            $this->db->query(
                "UPDATE grading_scales SET is_default = 1 WHERE id = ? AND school_id = ?",
                [$id, $schoolId]
            );
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to set default grading scale', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ==============================================================
    // 2. GRADING SCHEMES - CRUD OPERATIONS
    // ==============================================================

    public function getGradingSchemes(int $schoolId = 1, int $page = 1, int $limit = 50): array
    {
        try {
            $offset = ($page - 1) * $limit;
            $sql = "SELECT gs.*, 
                           gl.level_name, 
                           gl.level_category,
                           at.term_name,
                           gsc.scale_name as grading_scale_name
                    FROM grading_schemes gs
                    LEFT JOIN grade_levels gl ON gs.grade_level_id = gl.id
                    LEFT JOIN academic_terms at ON gs.term_id = at.id
                    LEFT JOIN grading_scales gsc ON gs.grading_scale_id = gsc.id
                    WHERE gs.school_id = ? AND gs.is_active = 1
                    ORDER BY gs.scheme_name
                    LIMIT ? OFFSET ?";
            return $this->db->fetchAll($sql, [$schoolId, $limit, $offset]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get grading schemes', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getAllGradingSchemes(int $schoolId = 1): array
    {
        try {
            $sql = "SELECT gs.*, 
                           gl.level_name, 
                           gl.level_category,
                           at.term_name,
                           gsc.scale_name as grading_scale_name
                    FROM grading_schemes gs
                    LEFT JOIN grade_levels gl ON gs.grade_level_id = gl.id
                    LEFT JOIN academic_terms at ON gs.term_id = at.id
                    LEFT JOIN grading_scales gsc ON gs.grading_scale_id = gsc.id
                    WHERE gs.school_id = ? AND gs.is_active = 1
                    ORDER BY gs.scheme_name";
            return $this->db->fetchAll($sql, [$schoolId]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get all grading schemes', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getGradingSchemeById(int $id): ?array
    {
        try {
            $sql = "SELECT gs.*, 
                           gl.level_name, 
                           gl.level_category,
                           at.term_name,
                           gsc.scale_name as grading_scale_name
                    FROM grading_schemes gs
                    LEFT JOIN grade_levels gl ON gs.grade_level_id = gl.id
                    LEFT JOIN academic_terms at ON gs.term_id = at.id
                    LEFT JOIN grading_scales gsc ON gs.grading_scale_id = gsc.id
                    WHERE gs.id = ? AND gs.is_active = 1";
            return $this->db->fetchOne($sql, [$id]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get grading scheme by ID', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function getDefaultGradingScheme(int $gradeLevelId, int $schoolId = 1): ?array
    {
        try {
            $sql = "SELECT * FROM grading_schemes 
                    WHERE school_id = ? AND is_active = 1
                    AND (grade_level_id = ? OR grade_level_id IS NULL)
                    ORDER BY grade_level_id DESC, is_default DESC
                    LIMIT 1";
            return $this->db->fetchOne($sql, [$schoolId, $gradeLevelId]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get default grading scheme', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function createGradingScheme(array $data)
    {
        try {
            $required = ['scheme_name', 'scheme_code', 'grading_scale_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: {$field}");
                }
            }

            $existing = $this->db->fetchOne(
                "SELECT id FROM grading_schemes 
                 WHERE school_id = ? AND scheme_code = ? AND is_active = 1",
                [$data['school_id'] ?? 1, $data['scheme_code']]
            );
            if ($existing) {
                throw new Exception('Grading scheme code already exists');
            }

            $scale = $this->getGradingScaleById($data['grading_scale_id']);
            if (!$scale) {
                throw new Exception('Invalid grading scale');
            }

            $sql = "INSERT INTO grading_schemes (
                        uuid, school_id, grade_level_id, term_id,
                        scheme_name, scheme_code, grading_scale_id,
                        passing_mark, credit_hour_base,
                        is_default, is_active, created_by, created_at
                    ) VALUES (
                        UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                    )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['grade_level_id'] ?? null,
                $data['term_id'] ?? null,
                $data['scheme_name'],
                $data['scheme_code'],
                $data['grading_scale_id'],
                $data['passing_mark'] ?? 50.00,
                $data['credit_hour_base'] ?? 1,
                $data['is_default'] ?? 0,
                1,
                $data['created_by'] ?? null
            ]);

            return $this->db->lastInsertId();
        } catch (Exception $e) {
            $this->logger->error('Failed to create grading scheme', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function updateGradingScheme(int $id, array $data): bool
    {
        try {
            $oldData = $this->getGradingSchemeById($id);
            if (!$oldData) {
                throw new Exception('Grading scheme not found');
            }

            if (isset($data['grading_scale_id'])) {
                $scale = $this->getGradingScaleById($data['grading_scale_id']);
                if (!$scale) {
                    throw new Exception('Invalid grading scale');
                }
            }

            $fields = [];
            $params = [];
            $allowedFields = [
                'grade_level_id', 'term_id', 'scheme_name', 'scheme_code',
                'grading_scale_id', 'passing_mark', 'credit_hour_base',
                'is_default', 'is_active'
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "{$field} = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $params[] = $id;
            $sql = "UPDATE grading_schemes SET " . implode(', ', $fields) . 
                   ", updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, $params);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update grading scheme', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function deleteGradingScheme(int $id): bool
    {
        try {
            $inUse = $this->db->fetchOne(
                "SELECT id FROM grading_scheme_details WHERE grading_scheme_id = ? AND is_active = 1",
                [$id]
            );
            if ($inUse) {
                throw new Exception('Cannot delete grading scheme as it is in use');
            }

            $sql = "UPDATE grading_schemes SET is_active = 0, updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, [$id]);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to delete grading scheme', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function setDefaultGradingScheme(int $id, int $schoolId = 1, ?int $gradeLevelId = null, ?int $termId = null): bool
    {
        try {
            $where = "school_id = ?";
            $params = [$schoolId];
            
            if ($gradeLevelId) {
                $where .= " AND grade_level_id = ?";
                $params[] = $gradeLevelId;
            }
            
            if ($termId) {
                $where .= " AND term_id = ?";
                $params[] = $termId;
            }

            $this->db->query(
                "UPDATE grading_schemes SET is_default = 0 WHERE {$where}",
                $params
            );

            $this->db->query(
                "UPDATE grading_schemes SET is_default = 1 WHERE id = ? AND school_id = ?",
                [$id, $schoolId]
            );
            
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to set default grading scheme', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ==============================================================
    // 3. GRADING SCHEME DETAILS - CRUD OPERATIONS
    // ==============================================================

    public function getGradingSchemeDetails(int $schemeId): array
    {
        try {
            $sql = "SELECT gsd.*, 
                           s.subject_name, s.subject_code, s.id as subject_id
                    FROM grading_scheme_details gsd
                    JOIN subjects s ON gsd.subject_id = s.id
                    WHERE gsd.grading_scheme_id = ? AND gsd.is_active = 1
                    ORDER BY s.subject_name";
            return $this->db->fetchAll($sql, [$schemeId]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get grading scheme details', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getGradingSchemeDetailById(int $id): ?array
    {
        try {
            $sql = "SELECT gsd.*, 
                           s.subject_name, s.subject_code
                    FROM grading_scheme_details gsd
                    JOIN subjects s ON gsd.subject_id = s.id
                    WHERE gsd.id = ? AND gsd.is_active = 1";
            return $this->db->fetchOne($sql, [$id]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get grading scheme detail by ID', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function createGradingSchemeDetail(array $data)
    {
        try {
            $required = ['grading_scheme_id', 'subject_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: {$field}");
                }
            }

            $existing = $this->db->fetchOne(
                "SELECT id FROM grading_scheme_details 
                 WHERE grading_scheme_id = ? AND subject_id = ? AND is_active = 1",
                [$data['grading_scheme_id'], $data['subject_id']]
            );
            if ($existing) {
                throw new Exception('Grading scheme detail already exists for this subject');
            }

            $scheme = $this->getGradingSchemeById($data['grading_scheme_id']);
            if (!$scheme) {
                throw new Exception('Invalid grading scheme');
            }

            $subject = $this->db->fetchOne(
                "SELECT id FROM subjects WHERE id = ? AND is_active = 1",
                [$data['subject_id']]
            );
            if (!$subject) {
                throw new Exception('Invalid subject');
            }

            $sql = "INSERT INTO grading_scheme_details (
                        uuid, grading_scheme_id, subject_id,
                        passing_mark, credit_hours, weighting_factor,
                        is_active, created_by, created_at
                    ) VALUES (
                        UUID(), ?, ?, ?, ?, ?, 1, ?, NOW()
                    )";

            $this->db->query($sql, [
                $data['grading_scheme_id'],
                $data['subject_id'],
                $data['passing_mark'] ?? null,
                $data['credit_hours'] ?? null,
                $data['weighting_factor'] ?? 1.00,
                $data['created_by'] ?? null
            ]);

            return $this->db->lastInsertId();
        } catch (Exception $e) {
            $this->logger->error('Failed to create grading scheme detail', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function updateGradingSchemeDetail(int $id, array $data): bool
    {
        try {
            $oldData = $this->getGradingSchemeDetailById($id);
            if (!$oldData) {
                throw new Exception('Grading scheme detail not found');
            }

            $fields = [];
            $params = [];
            $allowedFields = [
                'passing_mark', 'credit_hours', 'weighting_factor', 'is_active'
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "{$field} = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $params[] = $id;
            $sql = "UPDATE grading_scheme_details SET " . implode(', ', $fields) . 
                   ", updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, $params);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update grading scheme detail', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function deleteGradingSchemeDetail(int $id): bool
    {
        try {
            $sql = "UPDATE grading_scheme_details SET is_active = 0, updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, [$id]);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to delete grading scheme detail', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ==============================================================
    // 4. GRADE CALCULATION
    // ==============================================================

    public function calculateGradeFromScore(float $score, int $schemeId, ?int $subjectId = null): ?array
    {
        try {
            $scheme = $this->getGradingSchemeById($schemeId);
            if (!$scheme) {
                return null;
            }

            $detail = null;
            if ($subjectId) {
                $detail = $this->getEffectiveGradingSchemeDetail($schemeId, $subjectId);
            }

            $grade = $this->getGradeFromPercentage($score, $scheme['school_id']);
            if (!$grade) {
                return null;
            }

            $passingMark = $detail['passing_mark'] ?? $scheme['passing_mark'] ?? 50.00;
            $isPassing = $score >= $passingMark;

            return [
                'grade_letter' => $grade['grade_letter'],
                'grade_point' => $grade['grade_point'],
                'percentage' => $score,
                'is_passing' => $isPassing,
                'passing_mark' => $passingMark,
                'scheme_name' => $scheme['scheme_name'],
                'scheme_code' => $scheme['scheme_code']
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to calculate grade from score', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function getEffectiveGradingSchemeDetail(int $schemeId, int $subjectId): ?array
    {
        try {
            $scheme = $this->getGradingSchemeById($schemeId);
            if (!$scheme) {
                return null;
            }

            $detail = $this->db->fetchOne(
                "SELECT * FROM grading_scheme_details 
                 WHERE grading_scheme_id = ? AND subject_id = ? AND is_active = 1",
                [$schemeId, $subjectId]
            );

            return [
                'passing_mark' => $detail['passing_mark'] ?? $scheme['passing_mark'],
                'credit_hours' => $detail['credit_hours'] ?? $scheme['credit_hour_base'],
                'weighting_factor' => $detail['weighting_factor'] ?? 1.00
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to get effective grading scheme detail', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function calculateStudentGPA(int $studentId, int $termId): ?array
    {
        try {
            $results = $this->db->fetchAll(
                "SELECT ar.*, s.credit_hours, s.id as subject_id
                 FROM assessment_results ar
                 JOIN subjects s ON ar.subject_id = s.id
                 WHERE ar.student_id = ? AND ar.academic_term_id = ? 
                 AND ar.is_active = 1 AND ar.is_published = 1",
                [$studentId, $termId]
            );

            if (empty($results)) {
                return null;
            }

            $totalCreditHours = 0;
            $totalGradePoints = 0;
            $totalScore = 0;
            $subjectCount = 0;

            foreach ($results as $result) {
                $creditHours = $result['credit_hours'] ?? 1;
                $gradePoint = $result['grade_point'] ?? 0;
                $score = $result['total_score'] ?? 0;

                $totalCreditHours += $creditHours;
                $totalGradePoints += ($gradePoint * $creditHours);
                $totalScore += $score;
                $subjectCount++;
            }

            $gpa = $totalCreditHours > 0 ? round($totalGradePoints / $totalCreditHours, 2) : 0;
            $averagePercentage = $subjectCount > 0 ? round($totalScore / $subjectCount, 2) : 0;

            return [
                'gpa' => $gpa,
                'cgpa' => $gpa,
                'percentage' => $averagePercentage,
                'total_credit_hours' => $totalCreditHours,
                'total_grade_points' => $totalGradePoints,
                'subject_count' => $subjectCount,
                'subjects' => $results
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to calculate student GPA', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ==============================================================
    // 5. ASSESSMENT TYPES - COMPLETE CRUD
    // ==============================================================

    public function getAssessmentTypes(int $schoolId = 1): array
    {
        try {
            $sql = "SELECT * FROM assessment_types 
                    WHERE school_id = ? AND is_active = 1 
                    ORDER BY sort_order, assessment_name";
            return $this->db->fetchAll($sql, [$schoolId]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get assessment types', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getAssessmentTypeById(int $id): ?array
    {
        try {
            $sql = "SELECT * FROM assessment_types WHERE id = ? AND is_active = 1";
            return $this->db->fetchOne($sql, [$id]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get assessment type by ID', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function createAssessmentType(array $data)
    {
        try {
            $required = ['assessment_name', 'assessment_code'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: {$field}");
                }
            }

            $sql = "INSERT INTO assessment_types (
                        uuid, school_id, assessment_name, assessment_code,
                        assessment_category, weight_percentage, requires_approval,
                        requires_remark, is_compulsory, sort_order, description,
                        is_system, is_active, created_by, created_at
                    ) VALUES (
                        UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                    )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['assessment_name'],
                $data['assessment_code'],
                $data['assessment_category'] ?? 'continuous_assessment',
                $data['weight_percentage'] ?? 0,
                $data['requires_approval'] ?? 0,
                $data['requires_remark'] ?? 1,
                $data['is_compulsory'] ?? 1,
                $data['sort_order'] ?? 0,
                $data['description'] ?? null,
                $data['is_system'] ?? 0,
                1,
                $data['created_by'] ?? null
            ]);

            return $this->db->lastInsertId();
        } catch (Exception $e) {
            $this->logger->error('Failed to create assessment type', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function updateAssessmentType(int $id, array $data): bool
    {
        try {
            $fields = [];
            $params = [];
            $allowedFields = [
                'assessment_name', 'assessment_code', 'assessment_category',
                'weight_percentage', 'requires_approval', 'requires_remark',
                'is_compulsory', 'sort_order', 'description', 'is_active'
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "{$field} = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $params[] = $id;
            $sql = "UPDATE assessment_types SET " . implode(', ', $fields) . 
                   ", updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, $params);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update assessment type', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function deleteAssessmentType(int $id): bool
    {
        try {
            $sql = "UPDATE assessment_types SET is_active = 0, updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, [$id]);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to delete assessment type', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ==============================================================
    // 6. ASSESSMENT COMPONENTS - COMPLETE CRUD
    // ==============================================================

    public function getAssessmentComponents(int $assessmentTypeId): array
    {
        try {
            $sql = "SELECT * FROM assessment_components 
                    WHERE assessment_type_id = ? AND is_active = 1 
                    ORDER BY sort_order, component_name";
            return $this->db->fetchAll($sql, [$assessmentTypeId]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get assessment components', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getAssessmentComponentById(int $id): ?array
    {
        try {
            $sql = "SELECT * FROM assessment_components WHERE id = ? AND is_active = 1";
            return $this->db->fetchOne($sql, [$id]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get assessment component by ID', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function createAssessmentComponent(array $data)
    {
        try {
            $required = ['component_name', 'component_code', 'assessment_type_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: {$field}");
                }
            }

            $sql = "INSERT INTO assessment_components (
                        uuid, school_id, assessment_type_id, component_name,
                        component_code, component_type, default_weight, max_score,
                        is_compulsory, sort_order, description, is_active,
                        created_by, created_at
                    ) VALUES (
                        UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                    )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['assessment_type_id'],
                $data['component_name'],
                $data['component_code'],
                $data['component_type'] ?? 'assignment',
                $data['default_weight'] ?? 0,
                $data['max_score'] ?? 100,
                $data['is_compulsory'] ?? 1,
                $data['sort_order'] ?? 0,
                $data['description'] ?? null,
                1,
                $data['created_by'] ?? null
            ]);

            return $this->db->lastInsertId();
        } catch (Exception $e) {
            $this->logger->error('Failed to create assessment component', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function updateAssessmentComponent(int $id, array $data): bool
    {
        try {
            $fields = [];
            $params = [];
            $allowedFields = [
                'component_name', 'component_code', 'component_type',
                'default_weight', 'max_score', 'is_compulsory',
                'sort_order', 'description', 'is_active'
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "{$field} = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $params[] = $id;
            $sql = "UPDATE assessment_components SET " . implode(', ', $fields) . 
                   ", updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, $params);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update assessment component', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function deleteAssessmentComponent(int $id): bool
    {
        try {
            $sql = "UPDATE assessment_components SET is_active = 0, updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, [$id]);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to delete assessment component', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ==============================================================
    // 7. SUBJECT ASSESSMENTS - CRUD OPERATIONS
    // ==============================================================

    public function getSubjectAssessments(int $classSectionId, int $termId): array
    {
        try {
            $sql = "SELECT sa.*, s.subject_name, s.subject_code,
                           at.assessment_name, at.assessment_category,
                           gs.scheme_name
                    FROM subject_assessments sa
                    JOIN subjects s ON sa.subject_id = s.id
                    JOIN assessment_types at ON sa.assessment_type_id = at.id
                    LEFT JOIN grading_schemes gs ON sa.grading_scheme_id = gs.id
                    WHERE sa.class_section_id = ? AND sa.academic_term_id = ?
                    AND sa.is_active = 1
                    ORDER BY s.subject_name";
            return $this->db->fetchAll($sql, [$classSectionId, $termId]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get subject assessments', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getSubjectAssessmentById(int $id): ?array
    {
        try {
            $sql = "SELECT sa.*, s.subject_name, s.subject_code,
                           at.assessment_name, at.assessment_category
                    FROM subject_assessments sa
                    JOIN subjects s ON sa.subject_id = s.id
                    JOIN assessment_types at ON sa.assessment_type_id = at.id
                    WHERE sa.id = ? AND sa.is_active = 1";
            return $this->db->fetchOne($sql, [$id]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get subject assessment by ID', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function createSubjectAssessment(array $data)
    {
        try {
            $required = ['subject_id', 'class_section_id', 'academic_term_id', 'assessment_type_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: {$field}");
                }
            }

            $sql = "INSERT INTO subject_assessments (
                        uuid, school_id, subject_id, class_section_id,
                        academic_term_id, assessment_type_id, grading_scheme_id,
                        weight_percentage, max_score, passing_score,
                        is_published, is_active, created_by, created_at
                    ) VALUES (
                        UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                    )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['subject_id'],
                $data['class_section_id'],
                $data['academic_term_id'],
                $data['assessment_type_id'],
                $data['grading_scheme_id'] ?? null,
                $data['weight_percentage'] ?? 0,
                $data['max_score'] ?? 100,
                $data['passing_score'] ?? 50,
                0,
                1,
                $data['created_by'] ?? null
            ]);

            return $this->db->lastInsertId();
        } catch (Exception $e) {
            $this->logger->error('Failed to create subject assessment', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function updateSubjectAssessment(int $id, array $data): bool
    {
        try {
            $fields = [];
            $params = [];
            $allowedFields = [
                'grading_scheme_id', 'weight_percentage', 'max_score',
                'passing_score', 'is_published', 'is_active'
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "{$field} = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $params[] = $id;
            $sql = "UPDATE subject_assessments SET " . implode(', ', $fields) . 
                   ", updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, $params);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update subject assessment', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ==============================================================
    // 8. ASSESSMENT MARKS - CRUD OPERATIONS
    // ==============================================================

    public function saveAssessmentMarks(array $data)
    {
        try {
            $required = ['subject_assessment_id', 'student_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: {$field}");
                }
            }

            $existing = $this->db->fetchOne(
                "SELECT id FROM assessment_marks 
                 WHERE subject_assessment_id = ? AND student_id = ? AND is_active = 1",
                [$data['subject_assessment_id'], $data['student_id']]
            );

            if ($existing) {
                $fields = [];
                $params = [];
                $allowedFields = [
                    'score', 'max_score', 'percentage', 'grade_letter',
                    'grade_point', 'remarks', 'is_published', 'entered_by'
                ];

                foreach ($allowedFields as $field) {
                    if (array_key_exists($field, $data)) {
                        $fields[] = "{$field} = ?";
                        $params[] = $data[$field];
                    }
                }

                if (empty($fields)) {
                    return false;
                }

                $params[] = $existing['id'];
                $sql = "UPDATE assessment_marks SET " . implode(', ', $fields) . 
                       ", updated_at = NOW() WHERE id = ?";
                $this->db->query($sql, $params);
                return $existing['id'];
            } else {
                $sql = "INSERT INTO assessment_marks (
                            uuid, school_id, subject_assessment_id, student_id,
                            component_id, score, max_score, percentage,
                            grade_letter, grade_point, remarks, is_published,
                            entered_by, entered_date, is_active, created_at
                        ) VALUES (
                            UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1, NOW()
                        )";

                $this->db->query($sql, [
                    $data['school_id'] ?? 1,
                    $data['subject_assessment_id'],
                    $data['student_id'],
                    $data['component_id'] ?? null,
                    $data['score'] ?? null,
                    $data['max_score'] ?? null,
                    $data['percentage'] ?? null,
                    $data['grade_letter'] ?? null,
                    $data['grade_point'] ?? null,
                    $data['remarks'] ?? null,
                    $data['is_published'] ?? 0,
                    $data['entered_by'] ?? null
                ]);

                return $this->db->lastInsertId();
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to save assessment marks', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getAssessmentMarks(int $subjectAssessmentId): array
    {
        try {
            $sql = "SELECT am.*, s.first_name, s.last_name, s.admission_number
                    FROM assessment_marks am
                    JOIN students s ON am.student_id = s.id
                    WHERE am.subject_assessment_id = ? AND am.is_active = 1
                    ORDER BY s.first_name";
            return $this->db->fetchAll($sql, [$subjectAssessmentId]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get assessment marks', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getClassMarks(int $classSectionId, int $termId): array
    {
        try {
            $sql = "SELECT am.*, s.first_name, s.last_name, s.admission_number,
                           sa.subject_id, sub.subject_name
                    FROM assessment_marks am
                    JOIN students s ON am.student_id = s.id
                    JOIN subject_assessments sa ON am.subject_assessment_id = sa.id
                    JOIN subjects sub ON sa.subject_id = sub.id
                    WHERE sa.class_section_id = ? AND sa.academic_term_id = ?
                    AND am.is_active = 1
                    ORDER BY s.first_name, sub.subject_name";
            return $this->db->fetchAll($sql, [$classSectionId, $termId]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get class marks', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ==============================================================
    // 9. ASSESSMENT RESULTS - CRUD OPERATIONS
    // ==============================================================

    public function calculateAssessmentResults(int $studentId, int $termId): bool
    {
        try {
            $this->db->query(
                "DELETE FROM assessment_results 
                 WHERE student_id = ? AND academic_term_id = ?",
                [$studentId, $termId]
            );

            $sql = "INSERT INTO assessment_results (
                        uuid, school_id, student_id, subject_id, class_section_id,
                        academic_term_id, continuous_assessment_score,
                        continuous_assessment_grade, exam_score, exam_grade,
                        total_score, total_grade, grade_point, credit_hours,
                        gpa_contribution, is_published, is_active, created_at
                    )
                    SELECT 
                        UUID(), 1,
                        s.id AS student_id,
                        sub.id AS subject_id,
                        se.class_section_id,
                        ? AS academic_term_id,
                        ROUND(AVG(CASE WHEN at.assessment_category = 'continuous_assessment' 
                            THEN am.score ELSE NULL END), 2) AS ca_score,
                        NULL AS ca_grade,
                        ROUND(AVG(CASE WHEN at.assessment_category = 'examination' 
                            THEN am.score ELSE NULL END), 2) AS exam_score,
                        NULL AS exam_grade,
                        ROUND(
                            COALESCE(AVG(CASE WHEN at.assessment_category = 'continuous_assessment' 
                                THEN am.score ELSE 0 END), 0) * 0.4 +
                            COALESCE(AVG(CASE WHEN at.assessment_category = 'examination' 
                                THEN am.score ELSE 0 END), 0) * 0.6,
                            2
                        ) AS total_score,
                        NULL AS total_grade,
                        NULL AS grade_point,
                        1 AS credit_hours,
                        NULL AS gpa_contribution,
                        0 AS is_published,
                        1 AS is_active,
                        NOW()
                    FROM students s
                    JOIN student_enrollments se ON s.id = se.student_id
                    JOIN subjects sub ON sub.id = sub.id
                    LEFT JOIN subject_assessments sa ON sa.subject_id = sub.id 
                        AND sa.class_section_id = se.class_section_id 
                        AND sa.academic_term_id = ?
                    LEFT JOIN assessment_marks am ON am.subject_assessment_id = sa.id 
                        AND am.student_id = s.id
                    LEFT JOIN assessment_types at ON sa.assessment_type_id = at.id
                    WHERE s.id = ? AND se.academic_term_id = ? AND s.is_active = 1
                    GROUP BY s.id, sub.id";

            $this->db->query($sql, [$termId, $termId, $studentId, $termId]);

            $this->updateResultGrades($studentId, $termId);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to calculate assessment results', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function updateResultGrades(int $studentId, int $termId): void
    {
        try {
            $results = $this->db->fetchAll(
                "SELECT id, total_score FROM assessment_results 
                 WHERE student_id = ? AND academic_term_id = ? AND is_active = 1",
                [$studentId, $termId]
            );

            foreach ($results as $result) {
                if ($result['total_score'] !== null) {
                    $grade = $this->getGradeFromPercentage((float)$result['total_score']);
                    if ($grade) {
                        $this->db->query(
                            "UPDATE assessment_results SET 
                                total_grade = ?, 
                                grade_point = ?,
                                continuous_assessment_grade = ?,
                                exam_grade = ?
                             WHERE id = ?",
                            [
                                $grade['grade_letter'],
                                $grade['grade_point'],
                                $grade['grade_letter'],
                                $grade['grade_letter'],
                                $result['id']
                            ]
                        );
                    }
                }
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to update result grades', ['error' => $e->getMessage()]);
        }
    }

    public function getStudentResults(int $studentId, int $termId): array
    {
        try {
            $sql = "SELECT ar.*, s.subject_name, s.subject_code,
                           gl.level_name, cs.section_name,
                           ay.year_name, at.term_name
                    FROM assessment_results ar
                    JOIN subjects s ON ar.subject_id = s.id
                    JOIN class_sections cs ON ar.class_section_id = cs.id
                    JOIN grade_levels gl ON cs.grade_level_id = gl.id
                    JOIN academic_years ay ON ar.academic_term_id = ay.id
                    JOIN academic_terms at ON ar.academic_term_id = at.id
                    WHERE ar.student_id = ? AND ar.academic_term_id = ?
                    AND ar.is_active = 1
                    ORDER BY s.subject_name";
            return $this->db->fetchAll($sql, [$studentId, $termId]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get student results', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getClassResults(int $classSectionId, int $termId): array
    {
        try {
            $sql = "SELECT ar.*, s.first_name, s.last_name, s.admission_number,
                           sub.subject_name, sub.subject_code,
                           gl.level_name, cs.section_name
                    FROM assessment_results ar
                    JOIN students s ON ar.student_id = s.id
                    JOIN subjects sub ON ar.subject_id = sub.id
                    JOIN class_sections cs ON ar.class_section_id = cs.id
                    JOIN grade_levels gl ON cs.grade_level_id = gl.id
                    WHERE ar.class_section_id = ? AND ar.academic_term_id = ?
                    AND ar.is_active = 1
                    ORDER BY s.first_name, sub.subject_name";
            return $this->db->fetchAll($sql, [$classSectionId, $termId]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get class results', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function publishResults(int $studentId, int $termId): bool
    {
        try {
            $sql = "UPDATE assessment_results 
                    SET is_published = 1, published_date = NOW() 
                    WHERE student_id = ? AND academic_term_id = ? AND is_active = 1";
            $this->db->query($sql, [$studentId, $termId]);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to publish results', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getPublishedResults(int $studentId, int $termId): array
    {
        try {
            $sql = "SELECT ar.*, s.subject_name, s.subject_code,
                           gl.level_name, cs.section_name,
                           ay.year_name, at.term_name
                    FROM assessment_results ar
                    JOIN subjects s ON ar.subject_id = s.id
                    JOIN class_sections cs ON ar.class_section_id = cs.id
                    JOIN grade_levels gl ON cs.grade_level_id = gl.id
                    JOIN academic_years ay ON ar.academic_term_id = ay.id
                    JOIN academic_terms at ON ar.academic_term_id = at.id
                    WHERE ar.student_id = ? AND ar.academic_term_id = ?
                    AND ar.is_published = 1 AND ar.is_active = 1
                    ORDER BY s.subject_name";
            return $this->db->fetchAll($sql, [$studentId, $termId]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get published results', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ==============================================================
    // 10. DEFAULT GRADING SCALES - TEMPLATES
    // ==============================================================

    public function getDefaultScaleByType(string $scaleType, int $schoolId = 1): ?array
    {
        try {
            $sql = "SELECT * FROM grading_scales 
                    WHERE school_id = ? AND scale_type = ? AND is_default = 1 AND is_active = 1 
                    LIMIT 1";
            return $this->db->fetchOne($sql, [$schoolId, $scaleType]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get default scale by type', ['error' => $e->getMessage(), 'type' => $scaleType]);
            return null;
        }
    }

    public function getScaleTemplate(string $scaleType): array
    {
        $templates = [
            'letter' => [
                'label' => 'Alphabet (A, B, C, D, E, F)',
                'icon' => '🔤',
                'ranges' => [
                    ['min' => 80, 'max' => 100, 'grade' => 'A', 'point' => 4.00],
                    ['min' => 65, 'max' => 79, 'grade' => 'B', 'point' => 3.00],
                    ['min' => 55, 'max' => 64, 'grade' => 'C', 'point' => 2.00],
                    ['min' => 40, 'max' => 54, 'grade' => 'D', 'point' => 1.00],
                    ['min' => 35, 'max' => 39, 'grade' => 'E', 'point' => 0.50],
                    ['min' => 0, 'max' => 34, 'grade' => 'F', 'point' => 0.00]
                ]
            ],
            'number' => [
                'label' => 'Numbers (1, 2, 3, 4, 5, 6)',
                'icon' => '🔢',
                'ranges' => [
                    ['min' => 80, 'max' => 100, 'grade' => '1', 'point' => 4.00],
                    ['min' => 65, 'max' => 79, 'grade' => '2', 'point' => 3.00],
                    ['min' => 55, 'max' => 64, 'grade' => '3', 'point' => 2.00],
                    ['min' => 40, 'max' => 54, 'grade' => '4', 'point' => 1.00],
                    ['min' => 35, 'max' => 39, 'grade' => '5', 'point' => 0.50],
                    ['min' => 0, 'max' => 34, 'grade' => '6', 'point' => 0.00]
                ]
            ],
            'percentage' => [
                'label' => 'Percentage (A, B, C, D, E, F)',
                'icon' => '📊',
                'ranges' => [
                    ['min' => 80, 'max' => 100, 'grade' => 'A', 'point' => 4.00],
                    ['min' => 65, 'max' => 79, 'grade' => 'B', 'point' => 3.00],
                    ['min' => 55, 'max' => 64, 'grade' => 'C', 'point' => 2.00],
                    ['min' => 40, 'max' => 54, 'grade' => 'D', 'point' => 1.00],
                    ['min' => 35, 'max' => 39, 'grade' => 'E', 'point' => 0.50],
                    ['min' => 0, 'max' => 34, 'grade' => 'F', 'point' => 0.00]
                ]
            ],
            'grade_point' => [
                'label' => 'Grade Point (GPA Scale)',
                'icon' => '📈',
                'ranges' => [
                    ['min' => 4.00, 'max' => 5.00, 'grade' => 'A+', 'point' => 5.00],
                    ['min' => 3.50, 'max' => 3.99, 'grade' => 'A', 'point' => 4.00],
                    ['min' => 3.00, 'max' => 3.49, 'grade' => 'B+', 'point' => 3.50],
                    ['min' => 2.50, 'max' => 2.99, 'grade' => 'B', 'point' => 3.00],
                    ['min' => 2.00, 'max' => 2.49, 'grade' => 'C+', 'point' => 2.50],
                    ['min' => 1.50, 'max' => 1.99, 'grade' => 'C', 'point' => 2.00],
                    ['min' => 0.00, 'max' => 1.49, 'grade' => 'F', 'point' => 0.00]
                ]
            ]
        ];

        return $templates[$scaleType] ?? $templates['percentage'];
    }

    public function getAllScaleTemplates(): array
    {
        $types = ['letter', 'number', 'percentage', 'grade_point'];
        $templates = [];
        foreach ($types as $type) {
            $templates[$type] = $this->getScaleTemplate($type);
        }
        return $templates;
    }

    public function createDefaultScales(int $schoolId = 1): array
    {
        $results = ['success' => 0, 'failed' => 0, 'errors' => []];
        $types = ['letter', 'number', 'percentage', 'grade_point'];

        foreach ($types as $type) {
            $template = $this->getScaleTemplate($type);
            $scaleName = $template['label'];
            $scaleCode = strtoupper($type);

            $existing = $this->db->fetchOne(
                "SELECT id FROM grading_scales 
                 WHERE school_id = ? AND scale_code = ? AND is_active = 1",
                [$schoolId, $scaleCode]
            );

            if ($existing) {
                $results['success']++;
                continue;
            }

            try {
                foreach ($template['ranges'] as $row) {
                    $sql = "INSERT INTO grading_scales (
                                uuid, school_id, scale_name, scale_code, scale_type,
                                min_value, max_value, grade_letter, grade_point,
                                is_default, is_active, created_at
                            ) VALUES (
                                UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                            )";

                    $this->db->query($sql, [
                        $schoolId,
                        $scaleName,
                        $scaleCode,
                        $type,
                        $row['min'],
                        $row['max'],
                        $row['grade'],
                        $row['point'],
                        ($type === 'percentage' ? 1 : 0),
                        1
                    ]);
                }
                $results['success']++;
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Failed to create {$type} scale: " . $e->getMessage();
            }
        }

        return $results;
    }

    // ==============================================================
    // 11. SIMPLIFIED GRADE LEVELS
    // ==============================================================

    public function getGradeLevelCategories(): array
    {
        return [
            'pre_school' => '🏫 Pre School',
            'lower_primary' => '📚 Lower Primary',
            'upper_primary' => '📖 Upper Primary',
            'jhs' => '🎓 J.H.S.'
        ];
    }

    public function getGradeLevelsByCategory(string $category): array
    {
        try {
            $sql = "SELECT id, level_name, level_code, promotion_order 
                    FROM grade_levels 
                    WHERE level_category = ? AND is_active = 1 
                    ORDER BY promotion_order";
            return $this->db->fetchAll($sql, [$category]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get grade levels by category', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getGradeLevelsGroupedByCategory(int $schoolId = 1): array
    {
        try {
            $sql = "SELECT id, level_name, level_code, level_category, promotion_order
                    FROM grade_levels 
                    WHERE school_id = ? AND is_active = 1 
                    ORDER BY promotion_order";
            $levels = $this->db->fetchAll($sql, [$schoolId]);
            
            $grouped = [];
            $categories = $this->getGradeLevelCategories();
            
            foreach ($categories as $key => $label) {
                $grouped[$key] = [
                    'category' => $key,
                    'label' => $label,
                    'levels' => []
                ];
            }
            
            foreach ($levels as $level) {
                $category = $level['level_category'] ?? 'other';
                if (!isset($grouped[$category])) {
                    $grouped[$category] = [
                        'category' => $category,
                        'label' => ucfirst(str_replace('_', ' ', $category)),
                        'levels' => []
                    ];
                }
                $grouped[$category]['levels'][] = $level;
            }
            
            return $grouped;
        } catch (Exception $e) {
            $this->logger->error('Failed to get grade levels grouped by category', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getGradeLevelCategoryLabel(int $gradeLevelId): ?string
    {
        try {
            $level = $this->db->fetchOne(
                "SELECT level_category FROM grade_levels WHERE id = ? AND is_active = 1",
                [$gradeLevelId]
            );
            
            if (!$level || !$level['level_category']) {
                return null;
            }
            
            $categories = $this->getGradeLevelCategories();
            return $categories[$level['level_category']] ?? null;
        } catch (Exception $e) {
            $this->logger->error('Failed to get grade level category label', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ==============================================================
    // 12. VALIDATION METHODS
    // ==============================================================

    public function validateGradingScaleData(array $data): array
    {
        $errors = [];

        if (empty($data['scale_name']) || strlen($data['scale_name']) > 100) {
            $errors[] = 'Scale name is required and must not exceed 100 characters';
        }

        if (empty($data['scale_code']) || strlen($data['scale_code']) > 20) {
            $errors[] = 'Scale code is required and must not exceed 20 characters';
        }

        if (!isset($data['min_value']) || !is_numeric($data['min_value'])) {
            $errors[] = 'Min value is required and must be a number';
        }

        if (!isset($data['max_value']) || !is_numeric($data['max_value'])) {
            $errors[] = 'Max value is required and must be a number';
        }

        if (isset($data['min_value']) && isset($data['max_value'])) {
            if ($data['min_value'] >= $data['max_value']) {
                $errors[] = 'Min value must be less than max value';
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public function validateGradingSchemeData(array $data): array
    {
        $errors = [];

        if (empty($data['scheme_name']) || strlen($data['scheme_name']) > 100) {
            $errors[] = 'Scheme name is required and must not exceed 100 characters';
        }

        if (empty($data['scheme_code']) || strlen($data['scheme_code']) > 20) {
            $errors[] = 'Scheme code is required and must not exceed 20 characters';
        }

        if (empty($data['grading_scale_id']) || !is_numeric($data['grading_scale_id'])) {
            $errors[] = 'Grading scale is required';
        }

        if (isset($data['passing_mark']) && (!is_numeric($data['passing_mark']) || 
            $data['passing_mark'] < 0 || $data['passing_mark'] > 100)) {
            $errors[] = 'Passing mark must be a number between 0 and 100';
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public function validateGradingSchemeDetailData(array $data): array
    {
        $errors = [];

        if (empty($data['grading_scheme_id']) || !is_numeric($data['grading_scheme_id'])) {
            $errors[] = 'Grading scheme ID is required';
        }

        if (empty($data['subject_id']) || !is_numeric($data['subject_id'])) {
            $errors[] = 'Subject ID is required';
        }

        if (isset($data['passing_mark']) && (!is_numeric($data['passing_mark']) || 
            $data['passing_mark'] < 0 || $data['passing_mark'] > 100)) {
            $errors[] = 'Passing mark must be a number between 0 and 100';
        }

        if (isset($data['weighting_factor']) && (!is_numeric($data['weighting_factor']) || 
            $data['weighting_factor'] <= 0)) {
            $errors[] = 'Weighting factor must be a positive number';
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }
}