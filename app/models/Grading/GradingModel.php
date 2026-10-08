<?php
/**
 * GradingModel.php
 * 
 * Enterprise Grading Model
 * Handles all grading-related database operations
 * 
 * @package EduTrack
 * @subpackage Models\Grading
 * @version 1.0
 */

class GradingModel
{
    /**
     * @var DatabaseHelper Database connection instance
     */
    private $db;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    // ================================================================
    // 1. GRADING SYSTEMS - CRUD
    // ================================================================

    /**
     * Get all grading systems
     */
    public function getGradingSystems(int $schoolId = 1, bool $onlyActive = true): array
    {
        try {
            $sql = "SELECT * FROM grading_systems 
                    WHERE school_id = ?";
            $params = [$schoolId];
            
            if ($onlyActive) {
                $sql .= " AND is_active = 1";
            }
            
            $sql .= " ORDER BY system_name";
            
            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get grading system by ID
     */
    public function getGradingSystemById(int $id): ?array
    {
        try {
            $sql = "SELECT * FROM grading_systems WHERE id = ? AND deleted_at IS NULL";
            return $this->db->fetchOne($sql, [$id]);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get grading system by code
     */
    public function getGradingSystemByCode(string $code, int $schoolId = 1): ?array
    {
        try {
            $sql = "SELECT * FROM grading_systems 
                    WHERE school_id = ? AND system_code = ? AND deleted_at IS NULL";
            return $this->db->fetchOne($sql, [$schoolId, $code]);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get default grading system
     */
    public function getDefaultGradingSystem(int $schoolId = 1): ?array
    {
        try {
            $sql = "SELECT * FROM grading_systems 
                    WHERE school_id = ? AND is_default = 1 AND is_active = 1 AND deleted_at IS NULL
                    LIMIT 1";
            return $this->db->fetchOne($sql, [$schoolId]);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Create grading system
     */
    public function createGradingSystem(array $data): int
    {
        try {
            $sql = "INSERT INTO grading_systems (
                uuid, school_id, system_name, system_code,
                system_type, description, is_default, is_active,
                created_by
            ) VALUES (
                UUID(), ?, ?, ?,
                ?, ?, ?, 1,
                ?
            )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['system_name'] ?? '',
                $data['system_code'] ?? '',
                $data['system_type'] ?? 'custom',
                $data['description'] ?? null,
                $data['is_default'] ?? 0,
                $data['created_by'] ?? null
            ]);

            $id = $this->db->lastInsertId();
            $this->logAudit('grading_systems', $id, 'INSERT', null, $data);
            return $id;
        } catch (Exception $e) {
            throw new Exception("Failed to create grading system: " . $e->getMessage());
        }
    }

    /**
     * Update grading system
     */
    public function updateGradingSystem(int $id, array $data): bool
    {
        try {
            $oldData = $this->getGradingSystemById($id);
            
            $fields = array();
            $params = array();
            
            $allowedFields = [
                'system_name', 'system_code', 'system_type',
                'description', 'is_default', 'is_active'
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            // If setting as default, unset others
            if (!empty($data['is_default']) && $oldData) {
                $this->db->query("
                    UPDATE grading_systems 
                    SET is_default = 0, updated_at = NOW() 
                    WHERE school_id = ? AND id != ? AND deleted_at IS NULL
                ", [$oldData['school_id'], $id]);
            }

            $params[] = $id;
            $sql = "UPDATE grading_systems SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";

            $this->db->query($sql, $params);
            $this->logAudit('grading_systems', $id, 'UPDATE', $oldData, $data);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Delete grading system (soft delete)
     */
    public function deleteGradingSystem(int $id): bool
    {
        try {
            $oldData = $this->getGradingSystemById($id);
            $sql = "UPDATE grading_systems SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, [$id]);
            $this->logAudit('grading_systems', $id, 'DELETE', $oldData, null);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Duplicate grading system
     */
    public function duplicateGradingSystem(int $id): int
    {
        try {
            $system = $this->getGradingSystemById($id);
            if (!$system) {
                throw new Exception("Grading system not found");
            }

            // Duplicate system
            $newSystemId = $this->createGradingSystem([
                'school_id' => $system['school_id'],
                'system_name' => $system['system_name'] . ' (Copy)',
                'system_code' => $system['system_code'] . '_COPY',
                'system_type' => $system['system_type'],
                'description' => $system['description'],
                'is_default' => 0,
                'created_by' => $_SESSION['user_id'] ?? null
            ]);

            // Duplicate grading scales
            $scales = $this->getGradingScales($id);
            foreach ($scales as $scale) {
                $this->createGradingScale([
                    'grading_system_id' => $newSystemId,
                    'grade_name' => $scale['grade_name'],
                    'grade_code' => $scale['grade_code'],
                    'grade_letter' => $scale['grade_letter'],
                    'min_score' => $scale['min_score'],
                    'max_score' => $scale['max_score'],
                    'grade_point' => $scale['grade_point'],
                    'is_passing' => $scale['is_passing'],
                    'is_distinction' => $scale['is_distinction'],
                    'sort_order' => $scale['sort_order'],
                    'color' => $scale['color'],
                    'description' => $scale['description']
                ]);
            }

            // Duplicate grade remarks
            $remarks = $this->getGradeRemarks($id);
            foreach ($remarks as $remark) {
                $this->createGradeRemark([
                    'grading_system_id' => $newSystemId,
                    'grade_code' => $remark['grade_code'],
                    'remark_type' => $remark['remark_type'],
                    'remark_text' => $remark['remark_text'],
                    'language' => $remark['language']
                ]);
            }

            return $newSystemId;
        } catch (Exception $e) {
            throw new Exception("Failed to duplicate grading system: " . $e->getMessage());
        }
    }

    // ================================================================
    // 2. GRADING SCALES - CRUD
    // ================================================================

    /**
     * Get grading scales for a system
     */
    public function getGradingScales(int $gradingSystemId): array
    {
        try {
            $sql = "SELECT * FROM grading_scales 
                    WHERE grading_system_id = ? AND deleted_at IS NULL
                    ORDER BY sort_order, min_score DESC";
            return $this->db->fetchAll($sql, [$gradingSystemId]);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get grading scale by ID
     */
    public function getGradingScaleById(int $id): ?array
    {
        try {
            $sql = "SELECT * FROM grading_scales WHERE id = ? AND deleted_at IS NULL";
            return $this->db->fetchOne($sql, [$id]);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get grade by score
     */
    public function getGradeByScore(float $score, int $gradingSystemId): ?array
    {
        try {
            $sql = "SELECT * FROM grading_scales 
                    WHERE grading_system_id = ? 
                    AND min_score <= ? AND max_score >= ?
                    AND deleted_at IS NULL
                    ORDER BY sort_order LIMIT 1";
            return $this->db->fetchOne($sql, [$gradingSystemId, $score, $score]);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Create grading scale
     */
    public function createGradingScale(array $data): int
    {
        try {
            $sql = "INSERT INTO grading_scales (
                uuid, grading_system_id, grade_name, grade_code,
                grade_letter, min_score, max_score, grade_point,
                is_passing, is_distinction, sort_order, color,
                description, is_active
            ) VALUES (
                UUID(), ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, 1
            )";

            $this->db->query($sql, [
                $data['grading_system_id'] ?? 0,
                $data['grade_name'] ?? '',
                $data['grade_code'] ?? '',
                $data['grade_letter'] ?? null,
                $data['min_score'] ?? 0,
                $data['max_score'] ?? 100,
                $data['grade_point'] ?? null,
                $data['is_passing'] ?? 1,
                $data['is_distinction'] ?? 0,
                $data['sort_order'] ?? 0,
                $data['color'] ?? '#6c757d',
                $data['description'] ?? null
            ]);

            $id = $this->db->lastInsertId();
            $this->logAudit('grading_scales', $id, 'INSERT', null, $data);
            return $id;
        } catch (Exception $e) {
            throw new Exception("Failed to create grading scale: " . $e->getMessage());
        }
    }

    /**
     * Update grading scale
     */
    public function updateGradingScale(int $id, array $data): bool
    {
        try {
            $oldData = $this->getGradingScaleById($id);
            
            $fields = array();
            $params = array();
            
            $allowedFields = [
                'grade_name', 'grade_code', 'grade_letter',
                'min_score', 'max_score', 'grade_point',
                'is_passing', 'is_distinction', 'sort_order',
                'color', 'description', 'is_active'
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $params[] = $id;
            $sql = "UPDATE grading_scales SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";

            $this->db->query($sql, $params);
            $this->logAudit('grading_scales', $id, 'UPDATE', $oldData, $data);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Delete grading scale
     */
    public function deleteGradingScale(int $id): bool
    {
        try {
            $oldData = $this->getGradingScaleById($id);
            $sql = "UPDATE grading_scales SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, [$id]);
            $this->logAudit('grading_scales', $id, 'DELETE', $oldData, null);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // ================================================================
    // 3. GRADE REMARKS - CRUD
    // ================================================================

    /**
     * Get grade remarks
     */
    public function getGradeRemarks(int $gradingSystemId, string $gradeCode = null): array
    {
        try {
            $sql = "SELECT * FROM grade_remarks 
                    WHERE grading_system_id = ? AND deleted_at IS NULL";
            $params = [$gradingSystemId];

            if ($gradeCode !== null) {
                $sql .= " AND grade_code = ?";
                $params[] = $gradeCode;
            }

            $sql .= " ORDER BY grade_code, remark_type";

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get grade remark by ID
     */
    public function getGradeRemarkById(int $id): ?array
    {
        try {
            $sql = "SELECT * FROM grade_remarks WHERE id = ? AND deleted_at IS NULL";
            return $this->db->fetchOne($sql, [$id]);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Create grade remark
     */
    public function createGradeRemark(array $data): int
    {
        try {
            $sql = "INSERT INTO grade_remarks (
                uuid, grading_system_id, grade_code,
                remark_type, remark_text, language,
                is_active
            ) VALUES (
                UUID(), ?, ?,
                ?, ?, ?,
                1
            )";

            $this->db->query($sql, [
                $data['grading_system_id'] ?? 0,
                $data['grade_code'] ?? '',
                $data['remark_type'] ?? 'general',
                $data['remark_text'] ?? '',
                $data['language'] ?? 'en'
            ]);

            return $this->db->lastInsertId();
        } catch (Exception $e) {
            throw new Exception("Failed to create grade remark: " . $e->getMessage());
        }
    }

    /**
     * Update grade remark
     */
    public function updateGradeRemark(int $id, array $data): bool
    {
        try {
            $fields = array();
            $params = array();
            
            $allowedFields = [
                'remark_text', 'remark_type', 'language', 'is_active'
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $params[] = $id;
            $sql = "UPDATE grade_remarks SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";

            $this->db->query($sql, $params);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Delete grade remark
     */
    public function deleteGradeRemark(int $id): bool
    {
        try {
            $sql = "UPDATE grade_remarks SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, [$id]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // ================================================================
    // 4. GRADE ASSIGNMENTS - CRUD
    // ================================================================

    /**
     * Get grade assignments for a school
     */
    public function getGradeAssignments(int $schoolId = 1): array
    {
        try {
            $sql = "SELECT ga.*, 
                    gs.system_name, gs.system_code, gs.system_type,
                    gl.level_name,
                    cs.section_name,
                    at.term_name,
                    ay.year_name
                    FROM grade_assignments ga
                    JOIN grading_systems gs ON ga.grading_system_id = gs.id
                    LEFT JOIN grade_levels gl ON ga.grade_level_id = gl.id
                    LEFT JOIN class_sections cs ON ga.class_section_id = cs.id
                    LEFT JOIN academic_terms at ON ga.academic_term_id = at.id
                    LEFT JOIN academic_years ay ON ga.academic_year_id = ay.id
                    WHERE ga.school_id = ? AND ga.deleted_at IS NULL
                    ORDER BY ga.priority DESC, ga.assignment_type";
            return $this->db->fetchAll($sql, [$schoolId]);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get grading system for a class
     */
    public function getGradingSystemForClass(int $classSectionId, int $termId): ?array
    {
        try {
            // Get class info
            $class = $this->db->fetchOne("
                SELECT cs.grade_level_id, cs.school_id
                FROM class_sections cs
                WHERE cs.id = ? AND cs.is_active = 1
            ", [$classSectionId]);

            if (!$class) {
                return null;
            }

            $schoolId = $class['school_id'];
            $gradeLevelId = $class['grade_level_id'];

            // Get year from term
            $term = $this->db->fetchOne("
                SELECT academic_year_id FROM academic_terms WHERE id = ?
            ", [$termId]);

            $yearId = $term['academic_year_id'] ?? null;

            // Find assignment with highest priority
            $sql = "SELECT ga.*, gs.*
                    FROM grade_assignments ga
                    JOIN grading_systems gs ON ga.grading_system_id = gs.id
                    WHERE ga.school_id = ? 
                    AND ga.deleted_at IS NULL
                    AND ga.is_active = 1
                    AND gs.is_active = 1
                    AND gs.deleted_at IS NULL
                    ORDER BY 
                        CASE WHEN ga.class_section_id = ? THEN 1 ELSE 0 END DESC,
                        CASE WHEN ga.grade_level_id = ? THEN 1 ELSE 0 END DESC,
                        CASE WHEN ga.academic_term_id = ? THEN 1 ELSE 0 END DESC,
                        CASE WHEN ga.academic_year_id = ? THEN 1 ELSE 0 END DESC,
                        CASE WHEN ga.assignment_type = 'default' THEN 1 ELSE 0 END DESC,
                        ga.priority DESC
                    LIMIT 1";

            return $this->db->fetchOne($sql, [
                $schoolId,
                $classSectionId,
                $gradeLevelId,
                $termId,
                $yearId
            ]);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Create grade assignment
     */
    public function createGradeAssignment(array $data): int
    {
        try {
            $sql = "INSERT INTO grade_assignments (
                uuid, school_id, grading_system_id,
                grade_level_id, class_section_id,
                academic_term_id, academic_year_id,
                assignment_type, priority, is_active
            ) VALUES (
                UUID(), ?, ?,
                ?, ?,
                ?, ?,
                ?, ?, 1
            )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['grading_system_id'] ?? 0,
                $data['grade_level_id'] ?? null,
                $data['class_section_id'] ?? null,
                $data['academic_term_id'] ?? null,
                $data['academic_year_id'] ?? null,
                $data['assignment_type'] ?? 'default',
                $data['priority'] ?? 0
            ]);

            return $this->db->lastInsertId();
        } catch (Exception $e) {
            throw new Exception("Failed to create grade assignment: " . $e->getMessage());
        }
    }

    /**
     * Delete grade assignment
     */
    public function deleteGradeAssignment(int $id): bool
    {
        try {
            $sql = "UPDATE grade_assignments SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, [$id]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // ================================================================
    // 5. SUBJECT CATEGORIES - CRUD
    // ================================================================

    /**
     * Get all subject categories
     */
    public function getSubjectCategories(int $schoolId = 1): array
    {
        try {
            $sql = "SELECT * FROM subject_categories 
                    WHERE school_id = ? AND deleted_at IS NULL
                    ORDER BY sort_order, category_name";
            return $this->db->fetchAll($sql, [$schoolId]);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get subject category by ID
     */
    public function getSubjectCategoryById(int $id): ?array
    {
        try {
            $sql = "SELECT * FROM subject_categories WHERE id = ? AND deleted_at IS NULL";
            return $this->db->fetchOne($sql, [$id]);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Create subject category
     */
    public function createSubjectCategory(array $data): int
    {
        try {
            $sql = "INSERT INTO subject_categories (
                uuid, school_id, category_name, category_code,
                category_type, is_compulsory, count_in_aggregate,
                sort_order, description, is_active
            ) VALUES (
                UUID(), ?, ?, ?,
                ?, ?, ?,
                ?, ?, 1
            )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['category_name'] ?? '',
                $data['category_code'] ?? '',
                $data['category_type'] ?? 'other',
                $data['is_compulsory'] ?? 0,
                $data['count_in_aggregate'] ?? 1,
                $data['sort_order'] ?? 0,
                $data['description'] ?? null
            ]);

            return $this->db->lastInsertId();
        } catch (Exception $e) {
            throw new Exception("Failed to create subject category: " . $e->getMessage());
        }
    }

    /**
     * Update subject category
     */
    public function updateSubjectCategory(int $id, array $data): bool
    {
        try {
            $fields = array();
            $params = array();
            
            $allowedFields = [
                'category_name', 'category_code', 'category_type',
                'is_compulsory', 'count_in_aggregate',
                'sort_order', 'description', 'is_active'
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $params[] = $id;
            $sql = "UPDATE subject_categories SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";

            $this->db->query($sql, $params);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Delete subject category
     */
    public function deleteSubjectCategory(int $id): bool
    {
        try {
            $sql = "UPDATE subject_categories SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, [$id]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // ================================================================
    // 6. SUBJECT CATEGORY ASSIGNMENTS
    // ================================================================

    /**
     * Get subject category assignments
     */
    public function getSubjectCategoryAssignments(int $subjectId = null, int $categoryId = null): array
    {
        try {
            $sql = "SELECT sca.*, 
                    s.subject_name, s.subject_code,
                    sc.category_name, sc.category_code
                    FROM subject_category_assignments sca
                    JOIN subjects s ON sca.subject_id = s.id
                    JOIN subject_categories sc ON sca.category_id = sc.id
                    WHERE sca.deleted_at IS NULL";
            
            $params = array();
            
            if ($subjectId !== null) {
                $sql .= " AND sca.subject_id = ?";
                $params[] = $subjectId;
            }
            
            if ($categoryId !== null) {
                $sql .= " AND sca.category_id = ?";
                $params[] = $categoryId;
            }
            
            $sql .= " ORDER BY s.subject_name, sc.category_name";
            
            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Assign subject to category
     */
    public function assignSubjectToCategory(array $data): int
    {
        try {
            // Check if already assigned
            $existing = $this->db->fetchOne("
                SELECT id FROM subject_category_assignments 
                WHERE subject_id = ? AND category_id = ? 
                AND (grade_level_id = ? OR (grade_level_id IS NULL AND ? IS NULL))
                AND deleted_at IS NULL
            ", [
                $data['subject_id'] ?? 0,
                $data['category_id'] ?? 0,
                $data['grade_level_id'] ?? null,
                $data['grade_level_id'] ?? null
            ]);

            if ($existing) {
                return $existing['id'];
            }

            $sql = "INSERT INTO subject_category_assignments (
                uuid, subject_id, category_id, grade_level_id,
                is_compulsory, count_in_aggregate, is_active
            ) VALUES (
                UUID(), ?, ?, ?,
                ?, ?, 1
            )";

            $this->db->query($sql, [
                $data['subject_id'] ?? 0,
                $data['category_id'] ?? 0,
                $data['grade_level_id'] ?? null,
                $data['is_compulsory'] ?? 0,
                $data['count_in_aggregate'] ?? 1
            ]);

            return $this->db->lastInsertId();
        } catch (Exception $e) {
            throw new Exception("Failed to assign subject to category: " . $e->getMessage());
        }
    }

    /**
     * Remove subject from category
     */
    public function removeSubjectFromCategory(int $id): bool
    {
        try {
            $sql = "UPDATE subject_category_assignments SET deleted_at = NOW() WHERE id = ?";
            $this->db->query($sql, [$id]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // ================================================================
    // 7. AGGREGATE RULES - CRUD
    // ================================================================

    /**
     * Get all aggregate rules
     */
    public function getAggregateRules(int $schoolId = 1): array
    {
        try {
            $sql = "SELECT ar.*, gl.level_name
                    FROM aggregate_rules ar
                    LEFT JOIN grade_levels gl ON ar.grade_level_id = gl.id
                    WHERE ar.school_id = ? AND ar.deleted_at IS NULL
                    ORDER BY ar.rule_name";
            return $this->db->fetchAll($sql, [$schoolId]);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get aggregate rule by ID
     */
    public function getAggregateRuleById(int $id): ?array
    {
        try {
            $sql = "SELECT * FROM aggregate_rules WHERE id = ? AND deleted_at IS NULL";
            return $this->db->fetchOne($sql, [$id]);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get aggregate rule for a class
     */
    public function getAggregateRuleForClass(int $classSectionId, int $termId): ?array
    {
        try {
            $class = $this->db->fetchOne("
                SELECT cs.grade_level_id, cs.school_id
                FROM class_sections cs
                WHERE cs.id = ? AND cs.is_active = 1
            ", [$classSectionId]);

            if (!$class) {
                return null;
            }

            $sql = "SELECT * FROM aggregate_rules
                    WHERE school_id = ? 
                    AND deleted_at IS NULL
                    AND is_active = 1
                    AND (grade_level_id = ? OR grade_level_id IS NULL)
                    AND (academic_term_id = ? OR academic_term_id IS NULL)
                    ORDER BY grade_level_id DESC, academic_term_id DESC
                    LIMIT 1";

            return $this->db->fetchOne($sql, [
                $class['school_id'],
                $class['grade_level_id'],
                $termId
            ]);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Create aggregate rule
     */
    public function createAggregateRule(array $data): int
    {
        try {
            $sql = "INSERT INTO aggregate_rules (
                uuid, school_id, grade_level_id, academic_term_id,
                rule_name, rule_code, calculation_type,
                core_subjects_count, elective_subjects_count,
                best_elective_selection, total_subjects_count,
                pass_mark, distinction_mark, aggregate_format,
                is_active
            ) VALUES (
                UUID(), ?, ?, ?,
                ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?, ?,
                1
            )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['grade_level_id'] ?? null,
                $data['academic_term_id'] ?? null,
                $data['rule_name'] ?? '',
                $data['rule_code'] ?? '',
                $data['calculation_type'] ?? 'raw_score',
                $data['core_subjects_count'] ?? 0,
                $data['elective_subjects_count'] ?? 0,
                $data['best_elective_selection'] ?? 'highest_score',
                $data['total_subjects_count'] ?? 0,
                $data['pass_mark'] ?? 50.00,
                $data['distinction_mark'] ?? 80.00,
                $data['aggregate_format'] ?? '{score}/{total}'
            ]);

            return $this->db->lastInsertId();
        } catch (Exception $e) {
            throw new Exception("Failed to create aggregate rule: " . $e->getMessage());
        }
    }

    /**
     * Update aggregate rule
     */
    public function updateAggregateRule(int $id, array $data): bool
    {
        try {
            $fields = array();
            $params = array();
            
            $allowedFields = [
                'grade_level_id', 'academic_term_id',
                'rule_name', 'rule_code', 'calculation_type',
                'core_subjects_count', 'elective_subjects_count',
                'best_elective_selection', 'total_subjects_count',
                'pass_mark', 'distinction_mark', 'aggregate_format',
                'is_active'
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $params[] = $id;
            $sql = "UPDATE aggregate_rules SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";

            $this->db->query($sql, $params);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Delete aggregate rule
     */
    public function deleteAggregateRule(int $id): bool
    {
        try {
            $sql = "UPDATE aggregate_rules SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, [$id]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // ================================================================
    // 8. PROMOTION RULES - CRUD
    // ================================================================

    /**
     * Get all promotion rules
     */
    public function getPromotionRules(int $schoolId = 1): array
    {
        try {
            $sql = "SELECT pr.*, gl.level_name
                    FROM promotion_rules pr
                    LEFT JOIN grade_levels gl ON pr.grade_level_id = gl.id
                    WHERE pr.school_id = ? AND pr.deleted_at IS NULL
                    ORDER BY pr.rule_name";
            return $this->db->fetchAll($sql, [$schoolId]);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get promotion rule by ID
     */
    public function getPromotionRuleById(int $id): ?array
    {
        try {
            $sql = "SELECT * FROM promotion_rules WHERE id = ? AND deleted_at IS NULL";
            return $this->db->fetchOne($sql, [$id]);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get promotion rule for a class
     */
    public function getPromotionRuleForClass(int $gradeLevelId, int $yearId): ?array
    {
        try {
            $sql = "SELECT * FROM promotion_rules
                    WHERE school_id = ? 
                    AND deleted_at IS NULL
                    AND is_active = 1
                    AND (grade_level_id = ? OR grade_level_id IS NULL)
                    AND (academic_year_id = ? OR academic_year_id IS NULL)
                    ORDER BY grade_level_id DESC, academic_year_id DESC
                    LIMIT 1";

            return $this->db->fetchOne($sql, [1, $gradeLevelId, $yearId]);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Create promotion rule
     */
    public function createPromotionRule(array $data): int
    {
        try {
            $sql = "INSERT INTO promotion_rules (
                uuid, school_id, grade_level_id, academic_year_id,
                rule_name, rule_code,
                min_average_score, min_aggregate_score,
                min_attendance_percentage, min_behaviour_score,
                compulsory_subjects_passed, total_subjects_passed,
                max_failures_allowed,
                promotion_status_passed, promotion_status_failed,
                conditional_promotion_conditions,
                is_active
            ) VALUES (
                UUID(), ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?,
                ?, ?,
                ?,
                ?, ?,
                ?,
                1
            )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['grade_level_id'] ?? null,
                $data['academic_year_id'] ?? null,
                $data['rule_name'] ?? '',
                $data['rule_code'] ?? '',
                $data['min_average_score'] ?? 50.00,
                $data['min_aggregate_score'] ?? 0,
                $data['min_attendance_percentage'] ?? 75.00,
                $data['min_behaviour_score'] ?? 50.00,
                $data['compulsory_subjects_passed'] ?? 0,
                $data['total_subjects_passed'] ?? 0,
                $data['max_failures_allowed'] ?? 2,
                $data['promotion_status_passed'] ?? 'promoted',
                $data['promotion_status_failed'] ?? 'repeat',
                $data['conditional_promotion_conditions'] ?? null
            ]);

            return $this->db->lastInsertId();
        } catch (Exception $e) {
            throw new Exception("Failed to create promotion rule: " . $e->getMessage());
        }
    }

    /**
     * Update promotion rule
     */
    public function updatePromotionRule(int $id, array $data): bool
    {
        try {
            $fields = array();
            $params = array();
            
            $allowedFields = [
                'grade_level_id', 'academic_year_id',
                'rule_name', 'rule_code',
                'min_average_score', 'min_aggregate_score',
                'min_attendance_percentage', 'min_behaviour_score',
                'compulsory_subjects_passed', 'total_subjects_passed',
                'max_failures_allowed',
                'promotion_status_passed', 'promotion_status_failed',
                'conditional_promotion_conditions',
                'is_active'
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $params[] = $id;
            $sql = "UPDATE promotion_rules SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";

            $this->db->query($sql, $params);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Delete promotion rule
     */
    public function deletePromotionRule(int $id): bool
    {
        try {
            $sql = "UPDATE promotion_rules SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?";
            $this->db->query($sql, [$id]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // ================================================================
    // 9. STUDENT GRADES - COMPUTATION
    // ================================================================

    /**
     * Get student grades for a term
     */
    public function getStudentGrades(int $studentId, int $termId): array
    {
        try {
            $sql = "SELECT sg.*, 
                    s.subject_name, s.subject_code,
                    gl.level_name, cs.section_name,
                    at.term_name, ay.year_name
                    FROM student_grades sg
                    JOIN subjects s ON sg.subject_id = s.id
                    JOIN class_sections cs ON sg.class_section_id = cs.id
                    JOIN grade_levels gl ON cs.grade_level_id = gl.id
                    JOIN academic_terms at ON sg.academic_term_id = at.id
                    JOIN academic_years ay ON at.academic_year_id = ay.id
                    WHERE sg.student_id = ? 
                    AND sg.academic_term_id = ?
                    AND sg.deleted_at IS NULL
                    ORDER BY s.subject_name";
            return $this->db->fetchAll($sql, [$studentId, $termId]);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Save student grade
     */
    public function saveStudentGrade(array $data): int
    {
        try {
            // Check if exists
            $existing = $this->db->fetchOne("
                SELECT id FROM student_grades 
                WHERE student_id = ? AND subject_id = ? AND academic_term_id = ?
                AND deleted_at IS NULL
            ", [$data['student_id'] ?? 0, $data['subject_id'] ?? 0, $data['academic_term_id'] ?? 0]);

            if ($existing) {
                $sql = "UPDATE student_grades SET 
                        raw_score = ?,
                        grade_code = ?,
                        grade_point = ?,
                        is_passing = ?,
                        is_distinction = ?,
                        remarks = ?,
                        is_published = ?,
                        published_date = ?,
                        updated_at = NOW()
                        WHERE id = ?";
                
                $this->db->query($sql, [
                    $data['raw_score'] ?? null,
                    $data['grade_code'] ?? null,
                    $data['grade_point'] ?? null,
                    $data['is_passing'] ?? 1,
                    $data['is_distinction'] ?? 0,
                    $data['remarks'] ?? null,
                    $data['is_published'] ?? 0,
                    $data['published_date'] ?? null,
                    $existing['id']
                ]);

                return $existing['id'];
            } else {
                $sql = "INSERT INTO student_grades (
                    uuid, school_id, student_id, subject_id,
                    academic_term_id, class_section_id,
                    raw_score, grade_code, grade_point,
                    is_passing, is_distinction, remarks,
                    is_calculated, calculated_date,
                    is_published, is_active
                ) VALUES (
                    UUID(), ?, ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    1, NOW(),
                    ?, 1
                )";

                $this->db->query($sql, [
                    $data['school_id'] ?? 1,
                    $data['student_id'] ?? 0,
                    $data['subject_id'] ?? 0,
                    $data['academic_term_id'] ?? 0,
                    $data['class_section_id'] ?? 0,
                    $data['raw_score'] ?? null,
                    $data['grade_code'] ?? null,
                    $data['grade_point'] ?? null,
                    $data['is_passing'] ?? 1,
                    $data['is_distinction'] ?? 0,
                    $data['remarks'] ?? null,
                    $data['is_published'] ?? 0
                ]);

                return $this->db->lastInsertId();
            }
        } catch (Exception $e) {
            throw new Exception("Failed to save student grade: " . $e->getMessage());
        }
    }

    /**
     * Calculate grades for a student
     */
    public function calculateStudentGrades(int $studentId, int $termId): bool
    {
        try {
            // Get grading system for the student's class
            $enrollment = $this->db->fetchOne("
                SELECT se.class_section_id, se.school_id
                FROM student_enrollments se
                WHERE se.student_id = ? AND se.academic_term_id = ? AND se.is_active = 1
            ", [$studentId, $termId]);

            if (!$enrollment) {
                return false;
            }

            $classSectionId = $enrollment['class_section_id'];
            $schoolId = $enrollment['school_id'];

            // Get grading system
            $gradingSystem = $this->getGradingSystemForClass($classSectionId, $termId);
            if (!$gradingSystem) {
                return false;
            }

            $gradingSystemId = $gradingSystem['grading_system_id'];

            // Get assessment results for this student
            $results = $this->db->fetchAll("
                SELECT ar.subject_id, ar.total_score
                FROM assessment_results ar
                WHERE ar.student_id = ? AND ar.academic_term_id = ?
                AND ar.is_active = 1
            ", [$studentId, $termId]);

            foreach ($results as $result) {
                $score = (float)$result['total_score'];
                
                // Get grade from scale
                $grade = $this->getGradeByScore($score, $gradingSystemId);
                
                if ($grade) {
                    $this->saveStudentGrade([
                        'school_id' => $schoolId,
                        'student_id' => $studentId,
                        'subject_id' => $result['subject_id'],
                        'academic_term_id' => $termId,
                        'class_section_id' => $classSectionId,
                        'raw_score' => $score,
                        'grade_code' => $grade['grade_code'],
                        'grade_point' => $grade['grade_point'],
                        'is_passing' => $grade['is_passing'],
                        'is_distinction' => $grade['is_distinction'],
                        'is_calculated' => 1
                    ]);
                }
            }

            return true;
        } catch (Exception $e) {
            throw new Exception("Failed to calculate student grades: " . $e->getMessage());
        }
    }

    /**
     * Calculate grades for a class
     */
    public function calculateClassGrades(int $classSectionId, int $termId): array
    {
        try {
            $students = $this->db->fetchAll("
                SELECT s.id FROM students s
                JOIN student_enrollments se ON s.id = se.student_id
                WHERE se.class_section_id = ? AND se.academic_term_id = ?
                AND se.is_active = 1 AND s.is_active = 1
            ", [$classSectionId, $termId]);

            $calculated = 0;
            $errors = array();

            foreach ($students as $student) {
                try {
                    if ($this->calculateStudentGrades($student['id'], $termId)) {
                        $calculated++;
                    }
                } catch (Exception $e) {
                    $errors[] = "Student {$student['id']}: " . $e->getMessage();
                }
            }

            return [
                'success' => true,
                'calculated' => $calculated,
                'total' => count($students),
                'errors' => $errors
            ];
        } catch (Exception $e) {
            throw new Exception("Failed to calculate class grades: " . $e->getMessage());
        }
    }

    // ================================================================
    // 10. STUDENT AGGREGATES - COMPUTATION
    // ================================================================

    /**
     * Calculate aggregate for a student
     */
    public function calculateStudentAggregate(int $studentId, int $termId): bool
    {
        try {
            // Get student info
            $enrollment = $this->db->fetchOne("
                SELECT se.class_section_id, se.school_id
                FROM student_enrollments se
                WHERE se.student_id = ? AND se.academic_term_id = ? AND se.is_active = 1
            ", [$studentId, $termId]);

            if (!$enrollment) {
                return false;
            }

            $classSectionId = $enrollment['class_section_id'];
            $schoolId = $enrollment['school_id'];

            // Get aggregate rule
            $aggregateRule = $this->getAggregateRuleForClass($classSectionId, $termId);
            if (!$aggregateRule) {
                return false;
            }

            // Get student grades
            $grades = $this->getStudentGrades($studentId, $termId);
            
            $totalRawScore = 0;
            $totalGradePoints = 0;
            $coreSubjects = 0;
            $electiveSubjects = 0;
            $subjectsPassed = 0;
            $subjectsFailed = 0;
            $totalSubjects = count($grades);

            foreach ($grades as $grade) {
                $rawScore = (float)($grade['raw_score'] ?? 0);
                $gradePoint = (float)($grade['grade_point'] ?? 0);
                $isPassing = (int)($grade['is_passing'] ?? 0);

                $totalRawScore += $rawScore;
                $totalGradePoints += $gradePoint;

                if ($isPassing) {
                    $subjectsPassed++;
                } else {
                    $subjectsFailed++;
                }
            }

            $averageScore = $totalSubjects > 0 ? round($totalRawScore / $totalSubjects, 2) : 0;
            $averageGradePoint = $totalSubjects > 0 ? round($totalGradePoints / $totalSubjects, 2) : 0;

            // Save aggregate
            $this->db->query("
                INSERT INTO student_aggregates (
                    uuid, school_id, student_id, academic_term_id,
                    class_section_id, aggregate_rule_id,
                    total_raw_score, total_grade_points,
                    average_score, average_grade_point,
                    core_subjects_count, elective_subjects_count,
                    total_subjects_count, subjects_passed, subjects_failed,
                    is_calculated, calculated_date, is_active
                ) VALUES (
                    UUID(), ?, ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    1, NOW(), 1
                )
                ON DUPLICATE KEY UPDATE
                    total_raw_score = VALUES(total_raw_score),
                    total_grade_points = VALUES(total_grade_points),
                    average_score = VALUES(average_score),
                    average_grade_point = VALUES(average_grade_point),
                    subjects_passed = VALUES(subjects_passed),
                    subjects_failed = VALUES(subjects_failed),
                    is_calculated = 1,
                    calculated_date = NOW()
            ", [
                $schoolId,
                $studentId,
                $termId,
                $classSectionId,
                $aggregateRule['id'],
                $totalRawScore,
                $totalGradePoints,
                $averageScore,
                $averageGradePoint,
                $aggregateRule['core_subjects_count'] ?? 0,
                $aggregateRule['elective_subjects_count'] ?? 0,
                $totalSubjects,
                $subjectsPassed,
                $subjectsFailed
            ]);

            return true;
        } catch (Exception $e) {
            throw new Exception("Failed to calculate student aggregate: " . $e->getMessage());
        }
    }

    /**
     * Calculate aggregates for a class
     */
    public function calculateClassAggregates(int $classSectionId, int $termId): array
    {
        try {
            $students = $this->db->fetchAll("
                SELECT s.id FROM students s
                JOIN student_enrollments se ON s.id = se.student_id
                WHERE se.class_section_id = ? AND se.academic_term_id = ?
                AND se.is_active = 1 AND s.is_active = 1
            ", [$classSectionId, $termId]);

            $calculated = 0;
            $errors = array();

            foreach ($students as $student) {
                try {
                    if ($this->calculateStudentAggregate($student['id'], $termId)) {
                        $calculated++;
                    }
                } catch (Exception $e) {
                    $errors[] = "Student {$student['id']}: " . $e->getMessage();
                }
            }

            return [
                'success' => true,
                'calculated' => $calculated,
                'total' => count($students),
                'errors' => $errors
            ];
        } catch (Exception $e) {
            throw new Exception("Failed to calculate class aggregates: " . $e->getMessage());
        }
    }

    /**
     * Get student aggregate
     */
    public function getStudentAggregate(int $studentId, int $termId): ?array
    {
        try {
            $sql = "SELECT sa.*, 
                    gl.level_name, cs.section_name,
                    at.term_name, ay.year_name
                    FROM student_aggregates sa
                    JOIN class_sections cs ON sa.class_section_id = cs.id
                    JOIN grade_levels gl ON cs.grade_level_id = gl.id
                    JOIN academic_terms at ON sa.academic_term_id = at.id
                    JOIN academic_years ay ON at.academic_year_id = ay.id
                    WHERE sa.student_id = ? 
                    AND sa.academic_term_id = ?
                    AND sa.deleted_at IS NULL";
            return $this->db->fetchOne($sql, [$studentId, $termId]);
        } catch (Exception $e) {
            return null;
        }
    }

    // ================================================================
    // 11. PROMOTION EVALUATION
    // ================================================================

    /**
     * Evaluate promotion for a student
     */
    public function evaluateStudentPromotion(int $studentId, int $yearId): bool
    {
        try {
            // Get student info
            $student = $this->db->fetchOne("
                SELECT s.id, s.school_id, se.class_section_id
                FROM students s
                JOIN student_enrollments se ON s.id = se.student_id
                WHERE s.id = ? AND se.academic_year_id = ? AND se.is_active = 1
            ", [$studentId, $yearId]);

            if (!$student) {
                return false;
            }

            $schoolId = $student['school_id'];
            $classSectionId = $student['class_section_id'];

            // Get grade level
            $class = $this->db->fetchOne("
                SELECT grade_level_id FROM class_sections WHERE id = ?
            ", [$classSectionId]);

            $gradeLevelId = $class['grade_level_id'] ?? null;

            // Get promotion rule
            $promotionRule = $this->getPromotionRuleForClass($gradeLevelId, $yearId);
            if (!$promotionRule) {
                return false;
            }

            // Get aggregate for the year
            $aggregate = $this->db->fetchOne("
                SELECT * FROM student_aggregates 
                WHERE student_id = ? AND academic_term_id IN (
                    SELECT id FROM academic_terms WHERE academic_year_id = ?
                )
                ORDER BY academic_term_id DESC LIMIT 1
            ", [$studentId, $yearId]);

            // Get attendance
            $attendance = $this->db->fetchOne("
                SELECT attendance_percentage FROM attendance_summary 
                WHERE student_id = ? AND academic_year_id = ?
            ", [$studentId, $yearId]);

            $avgScore = $aggregate['average_score'] ?? 0;
            $attendancePercent = $attendance['attendance_percentage'] ?? 0;
            $subjectsPassed = $aggregate['subjects_passed'] ?? 0;
            $subjectsFailed = $aggregate['subjects_failed'] ?? 0;
            $totalSubjects = $aggregate['total_subjects_count'] ?? 0;

            // Determine promotion status
            $status = $this->determinePromotionStatus(
                $avgScore,
                $attendancePercent,
                $subjectsPassed,
                $subjectsFailed,
                $totalSubjects,
                $promotionRule
            );

            // Get next grade level
            $nextLevel = $this->db->fetchOne("
                SELECT id FROM grade_levels 
                WHERE promotion_order = (SELECT promotion_order + 1 FROM grade_levels WHERE id = ?)
                AND school_id = ?
            ", [$gradeLevelId, $schoolId]);

            // Save promotion decision
            $this->db->query("
                INSERT INTO promotion_decisions (
                    uuid, school_id, student_id, academic_year_id,
                    from_grade_level_id, to_grade_level_id,
                    promotion_rule_id, aggregate_id,
                    decision_type, average_score,
                    attendance_percentage,
                    subjects_passed, subjects_failed, total_subjects,
                    is_active
                ) VALUES (
                    UUID(), ?, ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?,
                    ?,
                    ?, ?, ?,
                    1
                )
                ON DUPLICATE KEY UPDATE
                    decision_type = VALUES(decision_type),
                    average_score = VALUES(average_score),
                    attendance_percentage = VALUES(attendance_percentage),
                    subjects_passed = VALUES(subjects_passed),
                    subjects_failed = VALUES(subjects_failed),
                    total_subjects = VALUES(total_subjects),
                    updated_at = NOW()
            ", [
                $schoolId,
                $studentId,
                $yearId,
                $gradeLevelId,
                $nextLevel['id'] ?? null,
                $promotionRule['id'] ?? null,
                $aggregate['id'] ?? null,
                $status,
                $avgScore,
                $attendancePercent,
                $subjectsPassed,
                $subjectsFailed,
                $totalSubjects
            ]);

            return true;
        } catch (Exception $e) {
            throw new Exception("Failed to evaluate student promotion: " . $e->getMessage());
        }
    }

    /**
     * Determine promotion status
     */
    private function determinePromotionStatus(
        float $avgScore,
        float $attendance,
        int $passed,
        int $failed,
        int $total,
        array $rule
    ): string {
        $minAvg = (float)($rule['min_average_score'] ?? 50);
        $minAttendance = (float)($rule['min_attendance_percentage'] ?? 75);
        $maxFailures = (int)($rule['max_failures_allowed'] ?? 2);
        $compulsoryPassed = (int)($rule['compulsory_subjects_passed'] ?? 0);
        $totalPassed = (int)($rule['total_subjects_passed'] ?? 0);

        // Check attendance
        if ($attendance < $minAttendance) {
            return 'probation';
        }

        // Check average
        if ($avgScore >= $minAvg && $failed <= $maxFailures) {
            return $rule['promotion_status_passed'] ?? 'promoted';
        }

        // Check conditional
        if ($avgScore >= ($minAvg * 0.7) && $failed <= ($maxFailures * 1.5)) {
            return 'conditional';
        }

        // Failed
        return $rule['promotion_status_failed'] ?? 'repeat';
    }

    // ================================================================
    // 12. AUDIT LOGGING
    // ================================================================

    /**
     * Log audit entry
     */
    private function logAudit(string $table, int $recordId, string $action, array $oldData = null, array $newData = null): void
    {
        try {
            $sql = "INSERT INTO grading_audit_logs (
                uuid, school_id, table_name, record_id,
                action_type, old_data, new_data, user_id,
                ip_address, user_agent
            ) VALUES (
                UUID(), 1, ?, ?,
                ?, ?, ?, ?,
                ?, ?
            )";

            $this->db->query($sql, [
                $table,
                $recordId,
                $action,
                $oldData ? json_encode($oldData) : null,
                $newData ? json_encode($newData) : null,
                $_SESSION['user_id'] ?? null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            error_log("Audit log error: " . $e->getMessage());
        }
    }

    /**
     * Get audit logs
     */
    public function getAuditLogs(string $table = null, int $recordId = null, int $limit = 100): array
    {
        try {
            $sql = "SELECT * FROM grading_audit_logs";
            $params = array();

            if ($table !== null) {
                $sql .= " WHERE table_name = ?";
                $params[] = $table;
                
                if ($recordId !== null) {
                    $sql .= " AND record_id = ?";
                    $params[] = $recordId;
                }
            }

            $sql .= " ORDER BY created_at DESC LIMIT ?";
            $params[] = $limit;

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            return [];
        }
    }

    // ================================================================
    // 13. REPORT CARD DATA
    // ================================================================

    /**
     * Get report card data for a student
     */
    public function getReportCardData(int $studentId, int $termId): array
    {
        try {
            // Get student info
            $student = $this->db->fetchOne("
                SELECT s.*, gl.level_name, cs.section_name,
                       at.term_name, ay.year_name
                FROM students s
                JOIN student_enrollments se ON s.id = se.student_id
                JOIN class_sections cs ON se.class_section_id = cs.id
                JOIN grade_levels gl ON cs.grade_level_id = gl.id
                JOIN academic_terms at ON se.academic_term_id = at.id
                JOIN academic_years ay ON at.academic_year_id = ay.id
                WHERE s.id = ? AND se.academic_term_id = ?
            ", [$studentId, $termId]);

            if (!$student) {
                return array();
            }

            // Get grades
            $grades = $this->getStudentGrades($studentId, $termId);

            // Get aggregate
            $aggregate = $this->getStudentAggregate($studentId, $termId);

            // Get promotion status
            $yearId = $this->db->fetchOne("
                SELECT academic_year_id FROM academic_terms WHERE id = ?
            ", [$termId]);

            $promotion = null;
            if ($yearId) {
                $promotion = $this->db->fetchOne("
                    SELECT * FROM promotion_decisions 
                    WHERE student_id = ? AND academic_year_id = ?
                    AND deleted_at IS NULL
                ", [$studentId, $yearId['academic_year_id']]);
            }

            return array(
                'student' => $student,
                'grades' => $grades,
                'aggregate' => $aggregate,
                'promotion' => $promotion
            );
        } catch (Exception $e) {
            throw new Exception("Failed to get report card data: " . $e->getMessage());
        }
    }
}