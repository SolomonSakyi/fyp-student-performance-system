<?php
/**
 * AssessmentModel.php
 *
 * Enterprise Assessment Model - COMPLETE IMPLEMENTATION
 * Handles all assessment-related database operations
 *
 * @package EduTrack
 * @subpackage Models\Assessment
 * @version 2.0
 */

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
    // 1. GRADING SCALES - COMPLETE CRUD
    // ==============================================================

    /**
     * Get all grading scales with pagination
     */
    public function getGradingScales(int $schoolId = 1, int $page = 1, int $limit = 50): array
    {
        try {
            $offset = ($page - 1) * $limit;
            $sql = "SELECT * FROM grading_scales 
                    WHERE school_id = ? AND is_active = 1 
                    ORDER BY min_value DESC 
                    LIMIT ? OFFSET ?";
            return $this->db->fetchAll($sql, [$schoolId, $limit, $offset]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get grading scales: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all grading scales without pagination (for dropdowns)
     */
    public function getAllGradingScales(int $schoolId = 1): array
    {
        try {
            $sql = "SELECT * FROM grading_scales 
                    WHERE school_id = ? AND is_active = 1 
                    ORDER BY min_value DESC";
            return $this->db->fetchAll($sql, [$schoolId]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get all grading scales: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get grading scale by ID
     */
    public function getGradingScaleById(int $id): ?array
    {
        try {
            $sql = "SELECT * FROM grading_scales WHERE id = ? AND is_active = 1";
            return $this->db->fetchOne($sql, [$id]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get grading scale by ID: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get grade from percentage score
     */
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
            $this->logger->error('Failed to get grade from percentage: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get grade point from percentage
     */
    public function getGradePointFromPercentage(float $percentage, int $schoolId = 1): ?float
    {
        try {
            $grade = $this->getGradeFromPercentage($percentage, $schoolId);
            return $grade['grade_point'] ?? null;
        } catch (Exception $e) {
            $this->logger->error('Failed to get grade point: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get grade letter from percentage
     */
    public function getGradeLetterFromPercentage(float $percentage, int $schoolId = 1): ?string
    {
        try {
            $grade = $this->getGradeFromPercentage($percentage, $schoolId);
            return $grade['grade_letter'] ?? null;
        } catch (Exception $e) {
            $this->logger->error('Failed to get grade letter: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create grading scale
     */
    public function createGradingScale(array $data)
    {
        try {
            // Validate required fields
            $required = ['scale_name', 'scale_code', 'min_value', 'max_value'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: {$field}");
                }
            }

            // Validate min/max values
            if ($data['min_value'] >= $data['max_value']) {
                throw new Exception('Min value must be less than max value');
            }

            // Check for duplicate code
            $existing = $this->db->fetchOne(
                "SELECT id FROM grading_scales 
                 WHERE school_id = ? AND scale_code = ? AND is_active = 1",
                [$data['school_id'] ?? 1, $data['scale_code']]
            );
            if ($existing) {
                throw new Exception('Grading scale code already exists');
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

            $id = $this->db->lastInsertId();
            $this->logAudit('grading_scales', $id, 'INSERT', null, $data);
            return $id;
        } catch (Exception $e) {
            $this->logger->error('Failed to create grading scale: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update grading scale
     */
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

            $this->logAudit('grading_scales', $id, 'UPDATE', $oldData, $data);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update grading scale: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete grading scale (soft delete)
     */
    public function deleteGradingScale(int $id): bool
    {
        try {
            $oldData = $this->getGradingScaleById($id);
            if (!$oldData) {
                throw new Exception('Grading scale not found');
            }

            $inUse = $this->db->fetchOne(
                "SELECT id FROM grading_schemes WHERE grading_scale_id = ? AND is_active = 1",
                [$id]
            );
            if ($inUse) {
                throw new Exception('Cannot delete grading scale as it is in use');
            }

            $sql = "UPDATE grading_scales SET is_active = 0, updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, [$id]);
            $this->logAudit('grading_scales', $id, 'DELETE', $oldData, null);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to delete grading scale: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Set grading scale as default
     */
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
            $this->logger->error('Failed to set default grading scale: ' . $e->getMessage());
            return false;
        }
    }

    // ==============================================================
    // 2. GRADING SCHEMES - COMPLETE CRUD
    // ==============================================================

    /**
     * Get all grading schemes with pagination
     */
    public function getGradingSchemes(int $schoolId = 1, int $page = 1, int $limit = 50): array
    {
        try {
            $offset = ($page - 1) * $limit;
            $sql = "SELECT gs.*, 
                           gl.level_name, 
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
            $this->logger->error('Failed to get grading schemes: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all grading schemes without pagination (for dropdowns)
     */
    public function getAllGradingSchemes(int $schoolId = 1): array
    {
        try {
            $sql = "SELECT gs.*, 
                           gl.level_name, 
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
            $this->logger->error('Failed to get all grading schemes: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get grading scheme by ID
     */
    public function getGradingSchemeById(int $id): ?array
    {
        try {
            $sql = "SELECT gs.*, 
                           gl.level_name, 
                           at.term_name,
                           gsc.scale_name as grading_scale_name
                    FROM grading_schemes gs
                    LEFT JOIN grade_levels gl ON gs.grade_level_id = gl.id
                    LEFT JOIN academic_terms at ON gs.term_id = at.id
                    LEFT JOIN grading_scales gsc ON gs.grading_scale_id = gsc.id
                    WHERE gs.id = ? AND gs.is_active = 1";
            return $this->db->fetchOne($sql, [$id]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get grading scheme by ID: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get default grading scheme for a grade level
     */
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
            $this->logger->error('Failed to get default grading scheme: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get default grading scheme for a term and grade level
     */
    public function getDefaultGradingSchemeForTerm(int $gradeLevelId, int $termId, int $schoolId = 1): ?array
    {
        try {
            $sql = "SELECT * FROM grading_schemes 
                    WHERE school_id = ? AND is_active = 1
                    AND (grade_level_id = ? OR grade_level_id IS NULL)
                    AND (term_id = ? OR term_id IS NULL)
                    ORDER BY grade_level_id DESC, term_id DESC, is_default DESC
                    LIMIT 1";
            return $this->db->fetchOne($sql, [$schoolId, $gradeLevelId, $termId]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get default grading scheme for term: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create grading scheme
     */
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

            $id = $this->db->lastInsertId();
            $this->logAudit('grading_schemes', $id, 'INSERT', null, $data);
            return $id;
        } catch (Exception $e) {
            $this->logger->error('Failed to create grading scheme: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update grading scheme
     */
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

            $this->logAudit('grading_schemes', $id, 'UPDATE', $oldData, $data);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update grading scheme: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete grading scheme (soft delete)
     */
    public function deleteGradingScheme(int $id): bool
    {
        try {
            $oldData = $this->getGradingSchemeById($id);
            if (!$oldData) {
                throw new Exception('Grading scheme not found');
            }

            $inUse = $this->db->fetchOne(
                "SELECT id FROM grading_scheme_details WHERE grading_scheme_id = ? AND is_active = 1",
                [$id]
            );
            if ($inUse) {
                throw new Exception('Cannot delete grading scheme as it is in use');
            }

            $sql = "UPDATE grading_schemes SET is_active = 0, updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, [$id]);
            $this->logAudit('grading_schemes', $id, 'DELETE', $oldData, null);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to delete grading scheme: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Set grading scheme as default
     */
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
            $this->logger->error('Failed to set default grading scheme: ' . $e->getMessage());
            return false;
        }
    }

    // ==============================================================
    // 3. GRADING SCHEME DETAILS - COMPLETE CRUD
    // ==============================================================

    /**
     * Get grading scheme details by scheme ID
     */
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
            $this->logger->error('Failed to get grading scheme details: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get grading scheme detail by ID
     */
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
            $this->logger->error('Failed to get grading scheme detail by ID: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get subject-specific grading scheme detail
     */
    public function getSubjectGradingSchemeDetail(int $schemeId, int $subjectId): ?array
    {
        try {
            $sql = "SELECT * FROM grading_scheme_details 
                    WHERE grading_scheme_id = ? AND subject_id = ? AND is_active = 1";
            return $this->db->fetchOne($sql, [$schemeId, $subjectId]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get subject grading scheme detail: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get effective grading scheme detail with fallback to scheme defaults
     */
    public function getEffectiveGradingSchemeDetail(int $schemeId, int $subjectId): ?array
    {
        try {
            $scheme = $this->getGradingSchemeById($schemeId);
            if (!$scheme) {
                return null;
            }

            $detail = $this->getSubjectGradingSchemeDetail($schemeId, $subjectId);
            
            if ($detail) {
                return [
                    'passing_mark' => $detail['passing_mark'] ?? $scheme['passing_mark'],
                    'credit_hours' => $detail['credit_hours'] ?? $scheme['credit_hour_base'],
                    'weighting_factor' => $detail['weighting_factor'] ?? 1.00,
                ];
            }

            return [
                'passing_mark' => $scheme['passing_mark'],
                'credit_hours' => $scheme['credit_hour_base'],
                'weighting_factor' => 1.00,
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to get effective grading scheme detail: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create grading scheme detail
     */
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

            $id = $this->db->lastInsertId();
            $this->logAudit('grading_scheme_details', $id, 'INSERT', null, $data);
            return $id;
        } catch (Exception $e) {
            $this->logger->error('Failed to create grading scheme detail: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update grading scheme detail
     */
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

            $this->logAudit('grading_scheme_details', $id, 'UPDATE', $oldData, $data);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update grading scheme detail: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete grading scheme detail (soft delete)
     */
    public function deleteGradingSchemeDetail(int $id): bool
    {
        try {
            $oldData = $this->getGradingSchemeDetailById($id);
            if (!$oldData) {
                throw new Exception('Grading scheme detail not found');
            }

            $sql = "UPDATE grading_scheme_details SET is_active = 0, updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, [$id]);
            $this->logAudit('grading_scheme_details', $id, 'DELETE', $oldData, null);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to delete grading scheme detail: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Bulk create grading scheme details
     */
    public function bulkCreateGradingSchemeDetails(int $schemeId, array $subjectIds, array $defaults = []): array
    {
        $results = ['success' => 0, 'failed' => 0, 'errors' => []];
        
        try {
            foreach ($subjectIds as $subjectId) {
                $data = [
                    'grading_scheme_id' => $schemeId,
                    'subject_id' => $subjectId,
                    'passing_mark' => $defaults['passing_mark'] ?? null,
                    'credit_hours' => $defaults['credit_hours'] ?? null,
                    'weighting_factor' => $defaults['weighting_factor'] ?? 1.00,
                    'created_by' => $defaults['created_by'] ?? null
                ];
                
                $result = $this->createGradingSchemeDetail($data);
                if ($result) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Failed to add subject ID: {$subjectId}";
                }
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to bulk create grading scheme details: ' . $e->getMessage());
            $results['errors'][] = $e->getMessage();
        }
        
        return $results;
    }

    /**
     * Bulk update grading scheme details
     */
    public function bulkUpdateGradingSchemeDetails(array $details): array
    {
        $results = ['success' => 0, 'failed' => 0, 'errors' => []];
        
        try {
            foreach ($details as $detail) {
                if (!isset($detail['id'])) {
                    $results['failed']++;
                    $results['errors'][] = 'Missing ID for detail';
                    continue;
                }
                
                $id = $detail['id'];
                unset($detail['id']);
                
                $result = $this->updateGradingSchemeDetail($id, $detail);
                if ($result) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Failed to update detail ID: {$id}";
                }
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to bulk update grading scheme details: ' . $e->getMessage());
            $results['errors'][] = $e->getMessage();
        }
        
        return $results;
    }

    // ==============================================================
    // 4. GRADE CALCULATION HELPERS
    // ==============================================================

    /**
     * Calculate grade for a score using a grading scheme
     */
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

            $scaleId = $scheme['grading_scale_id'];
            $scale = $this->getGradingScaleById($scaleId);
            if (!$scale) {
                return null;
            }

            $percentage = $score;

            $grade = $this->getGradeFromPercentage($percentage, $scheme['school_id']);
            if (!$grade) {
                return null;
            }

            $passingMark = $detail['passing_mark'] ?? $scheme['passing_mark'] ?? 50.00;
            $isPassing = $percentage >= $passingMark;

            return [
                'grade_letter' => $grade['grade_letter'],
                'grade_point' => $grade['grade_point'],
                'percentage' => $percentage,
                'is_passing' => $isPassing,
                'passing_mark' => $passingMark,
                'scale' => $scale,
                'scheme' => $scheme,
                'detail' => $detail
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to calculate grade from score: ' . $e->getMessage());
            return null;
        }
    }

    // ==============================================================
    // 5. GPA CALCULATION - FIXED METHOD
    // ==============================================================

    /**
     * Calculate GPA for a student's results
     *
     * @param int $studentId Student ID
     * @param int $termId Term ID
     * @return array|null
     */
    public function calculateStudentGPA(int $studentId, int $termId): ?array
    {
        try {
            // Get student's assessment results for this term
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
                'cgpa' => $gpa, // Simplified - should calculate cumulative from history
                'percentage' => $averagePercentage,
                'total_credit_hours' => $totalCreditHours,
                'total_grade_points' => $totalGradePoints,
                'subject_count' => $subjectCount,
                'subjects' => $results
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to calculate student GPA: ' . $e->getMessage());
            return null;
        }
    }

    // ==============================================================
    // 6. AUDIT LOGGING
    // ==============================================================

    /**
     * Log audit trail entry
     */
    private function logAudit(string $tableName, int $recordId, string $actionType, ?array $oldData = null, ?array $newData = null): bool
    {
        try {
            $sql = "INSERT INTO assessment_audit_logs (
                        uuid, school_id, table_name, record_id, action_type,
                        old_data, new_data, user_id, ip_address, user_agent, created_at
                    ) VALUES (
                        UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                    )";

            $this->db->query($sql, [
                1,
                $tableName,
                $recordId,
                $actionType,
                $oldData ? json_encode($oldData) : null,
                $newData ? json_encode($newData) : null,
                $_SESSION['user_id'] ?? null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to log audit: ' . $e->getMessage());
            return false;
        }
    }

    // ==============================================================
    // 7. VALIDATION HELPERS
    // ==============================================================

    /**
     * Validate grading scale data
     */
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

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate grading scheme data
     */
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

        if (isset($data['passing_mark']) && (!is_numeric($data['passing_mark']) || $data['passing_mark'] < 0 || $data['passing_mark'] > 100)) {
            $errors[] = 'Passing mark must be a number between 0 and 100';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate grading scheme detail data
     */
    public function validateGradingSchemeDetailData(array $data): array
    {
        $errors = [];

        if (empty($data['grading_scheme_id']) || !is_numeric($data['grading_scheme_id'])) {
            $errors[] = 'Grading scheme ID is required';
        }

        if (empty($data['subject_id']) || !is_numeric($data['subject_id'])) {
            $errors[] = 'Subject ID is required';
        }

        if (isset($data['passing_mark']) && (!is_numeric($data['passing_mark']) || $data['passing_mark'] < 0 || $data['passing_mark'] > 100)) {
            $errors[] = 'Passing mark must be a number between 0 and 100';
        }

        if (isset($data['weighting_factor']) && (!is_numeric($data['weighting_factor']) || $data['weighting_factor'] <= 0)) {
            $errors[] = 'Weighting factor must be a positive number';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}