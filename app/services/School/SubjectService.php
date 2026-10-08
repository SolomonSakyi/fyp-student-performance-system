<?php

/**
 * SubjectService.php
 * Service for managing school subjects and disciplines
 * 
 * @package EduTrack
 * @subpackage Services\School
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/helpers/DatabaseHelper.php';
require_once dirname(__DIR__, 2) . '/services/Tenant/TenantContext.php';
require_once dirname(__DIR__, 2) . '/services/Authorization/AuthorizationService.php';

class SubjectService
{
    private $db;
    private $context;
    private $auth;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->context = TenantContext::getInstance();
        $this->auth = new AuthorizationService();
    }

    /**
     * Get all subjects for a school
     */
    public function getSchoolSubjects(int $schoolId, ?int $disciplineId = null): array
    {
        if (!$this->auth->canAccessSchool($schoolId)) {
            throw new Exception('Unauthorized access to school subjects');
        }

        $sql = "SELECT 
                    ss.*,
                    sd.discipline_name,
                    sd.discipline_code,
                    COUNT(DISTINCT sla.level_id) as level_count
                FROM school_subjects ss
                LEFT JOIN school_disciplines sd ON ss.discipline_id = sd.id
                LEFT JOIN subject_level_assignments sla ON ss.id = sla.subject_id
                WHERE ss.school_id = ? AND ss.deleted_at IS NULL";

        $params = [$schoolId];

        if ($disciplineId) {
            $sql .= " AND ss.discipline_id = ?";
            $params[] = $disciplineId;
        }

        $sql .= " GROUP BY ss.id ORDER BY ss.display_order ASC, ss.subject_name ASC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get subjects for a specific level
     */
    public function getSubjectsByLevel(int $schoolId, int $levelId): array
    {
        if (!$this->auth->canAccessSchool($schoolId)) {
            throw new Exception('Unauthorized access to school subjects');
        }

        $sql = "SELECT 
                    ss.*,
                    sla.is_core as is_core_at_level,
                    sla.is_elective as is_elective_at_level,
                    sla.is_optional as is_optional_at_level,
                    sla.max_score as level_max_score,
                    sla.pass_mark as level_pass_mark,
                    sla.is_active as is_active_at_level
                FROM school_subjects ss
                JOIN subject_level_assignments sla ON ss.id = sla.subject_id
                WHERE ss.school_id = ? 
                  AND sla.level_id = ? 
                  AND ss.deleted_at IS NULL
                  AND sla.deleted_at IS NULL
                ORDER BY ss.display_order ASC, ss.subject_name ASC";

        return $this->db->fetchAll($sql, [$schoolId, $levelId]);
    }

    /**
     * Get a specific subject
     */
    public function getSubject(int $subjectId): ?array
    {
        $sql = "SELECT * FROM school_subjects WHERE id = ? AND deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$subjectId]);
    }

    /**
     * Add a new subject
     */
    public function addSubject(int $schoolId, array $data): array
    {
        if (!$this->auth->canManageSchool($schoolId)) {
            throw new Exception('Unauthorized to manage school subjects');
        }

        $errors = $this->validateSubjectData($data);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // Check for duplicate subject code
        $existing = $this->db->fetchOne(
            "SELECT id FROM school_subjects WHERE school_id = ? AND subject_code = ? AND deleted_at IS NULL",
            [$schoolId, $data['subject_code']]
        );

        if ($existing) {
            return [
                'success' => false,
                'errors' => ['Subject code already exists']
            ];
        }

        $this->db->beginTransaction();

        try {
            // Insert subject
            $sql = "INSERT INTO school_subjects (
                        school_id, discipline_id, subject_code, subject_name, short_name,
                        description, is_core, is_elective, is_optional, is_active,
                        display_order, max_score, pass_mark, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $this->db->query($sql, [
                $schoolId,
                $data['discipline_id'] ?? null,
                $data['subject_code'],
                $data['subject_name'],
                $data['short_name'] ?? '',
                $data['description'] ?? '',
                $data['is_core'] ?? 0,
                $data['is_elective'] ?? 0,
                $data['is_optional'] ?? 0,
                $data['is_active'] ?? 1,
                $data['display_order'] ?? 0,
                $data['max_score'] ?? 100,
                $data['pass_mark'] ?? 50
            ]);

            $subjectId = $this->db->lastInsertId();

            // Assign to levels if provided
            if (!empty($data['levels'])) {
                foreach ($data['levels'] as $level) {
                    $this->assignSubjectToLevel($subjectId, $level['level_id'], [
                        'is_core' => $level['is_core'] ?? 0,
                        'is_elective' => $level['is_elective'] ?? 0,
                        'is_optional' => $level['is_optional'] ?? 0,
                        'max_score' => $level['max_score'] ?? null,
                        'pass_mark' => $level['pass_mark'] ?? null
                    ]);
                }
            }

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Subject added successfully',
                'subject_id' => $subjectId
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update a subject
     */
    public function updateSubject(int $subjectId, array $data): array
    {
        $subject = $this->getSubject($subjectId);
        if (!$subject) {
            return ['success' => false, 'errors' => ['Subject not found']];
        }

        if (!$this->auth->canManageSchool($subject['school_id'])) {
            throw new Exception('Unauthorized to manage school subjects');
        }

        $sql = "UPDATE school_subjects SET ";
        $updates = [];
        $params = [];

        $allowedFields = [
            'discipline_id',
            'subject_name',
            'short_name',
            'description',
            'is_core',
            'is_elective',
            'is_optional',
            'is_active',
            'display_order',
            'max_score',
            'pass_mark'
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($updates)) {
            return ['success' => false, 'errors' => ['No fields to update']];
        }

        $sql .= implode(', ', $updates);
        $sql .= ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        $params[] = $subjectId;

        $this->db->query($sql, $params);

        return [
            'success' => true,
            'message' => 'Subject updated successfully'
        ];
    }

    /**
     * Assign subject to a level
     */
    public function assignSubjectToLevel(int $subjectId, int $levelId, array $data): array
    {
        $sql = "INSERT INTO subject_level_assignments (
                    subject_id, level_id, is_core, is_elective, is_optional,
                    max_score, pass_mark, is_active, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE
                    is_core = VALUES(is_core),
                    is_elective = VALUES(is_elective),
                    is_optional = VALUES(is_optional),
                    max_score = VALUES(max_score),
                    pass_mark = VALUES(pass_mark),
                    is_active = 1,
                    updated_at = NOW()";

        $this->db->query($sql, [
            $subjectId,
            $levelId,
            $data['is_core'] ?? 0,
            $data['is_elective'] ?? 0,
            $data['is_optional'] ?? 0,
            $data['max_score'] ?? null,
            $data['pass_mark'] ?? null
        ]);

        return ['success' => true];
    }

    /**
     * Remove subject from a level
     */
    public function removeSubjectFromLevel(int $subjectId, int $levelId): array
    {
        $sql = "UPDATE subject_level_assignments 
                SET deleted_at = NOW(), is_active = 0
                WHERE subject_id = ? AND level_id = ? AND deleted_at IS NULL";

        $this->db->query($sql, [$subjectId, $levelId]);

        return ['success' => true];
    }

    /**
     * Delete a subject (soft delete)
     */
    public function deleteSubject(int $subjectId): array
    {
        $subject = $this->getSubject($subjectId);
        if (!$subject) {
            return ['success' => false, 'errors' => ['Subject not found']];
        }

        // Check if subject is in use
        $inUse = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM assessment_marks WHERE subject_id = ? AND deleted_at IS NULL",
            [$subjectId]
        );

        if (($inUse['count'] ?? 0) > 0) {
            return [
                'success' => false,
                'errors' => ['Subject has assessment records and cannot be deleted']
            ];
        }

        $sql = "UPDATE school_subjects SET deleted_at = NOW(), is_active = 0 WHERE id = ? AND deleted_at IS NULL";
        $this->db->query($sql, [$subjectId]);

        return [
            'success' => true,
            'message' => 'Subject deleted successfully'
        ];
    }

    /**
     * Get disciplines for a school
     */
    public function getDisciplines(int $schoolId): array
    {
        if (!$this->auth->canAccessSchool($schoolId)) {
            throw new Exception('Unauthorized access to school disciplines');
        }

        $sql = "SELECT * FROM school_disciplines 
                WHERE school_id = ? AND deleted_at IS NULL 
                ORDER BY display_order ASC, discipline_name ASC";

        return $this->db->fetchAll($sql, [$schoolId]);
    }

    /**
     * Add a discipline
     */
    public function addDiscipline(int $schoolId, array $data): array
    {
        if (!$this->auth->canManageSchool($schoolId)) {
            throw new Exception('Unauthorized to manage school disciplines');
        }

        $errors = $this->validateDisciplineData($data);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $sql = "INSERT INTO school_disciplines (
                    school_id, discipline_code, discipline_name, description,
                    display_order, is_active, created_at
                ) VALUES (?, ?, ?, ?, ?, 1, NOW())";

        $this->db->query($sql, [
            $schoolId,
            $data['discipline_code'],
            $data['discipline_name'],
            $data['description'] ?? '',
            $data['display_order'] ?? 0
        ]);

        return [
            'success' => true,
            'message' => 'Discipline added successfully',
            'discipline_id' => $this->db->lastInsertId()
        ];
    }

    /**
     * Get subject statistics
     */
    public function getSubjectStats(int $schoolId): array
    {
        $stats = $this->db->fetchOne(
            "SELECT 
                COUNT(*) as total_subjects,
                SUM(CASE WHEN is_core = 1 THEN 1 ELSE 0 END) as core_subjects,
                SUM(CASE WHEN is_elective = 1 THEN 1 ELSE 0 END) as elective_subjects,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_subjects
            FROM school_subjects
            WHERE school_id = ? AND deleted_at IS NULL",
            [$schoolId]
        );

        return [
            'total' => (int)($stats['total_subjects'] ?? 0),
            'core' => (int)($stats['core_subjects'] ?? 0),
            'elective' => (int)($stats['elective_subjects'] ?? 0),
            'active' => (int)($stats['active_subjects'] ?? 0)
        ];
    }

    /**
     * Validate subject data
     */
    private function validateSubjectData(array $data): array
    {
        $errors = [];

        if (empty($data['subject_code'])) {
            $errors[] = 'Subject code is required';
        }

        if (empty($data['subject_name'])) {
            $errors[] = 'Subject name is required';
        }

        if (isset($data['max_score']) && $data['max_score'] <= 0) {
            $errors[] = 'Max score must be greater than 0';
        }

        if (isset($data['pass_mark']) && $data['pass_mark'] < 0) {
            $errors[] = 'Pass mark cannot be negative';
        }

        return $errors;
    }

    /**
     * Validate discipline data
     */
    private function validateDisciplineData(array $data): array
    {
        $errors = [];

        if (empty($data['discipline_code'])) {
            $errors[] = 'Discipline code is required';
        }

        if (empty($data['discipline_name'])) {
            $errors[] = 'Discipline name is required';
        }

        return $errors;
    }

    /**
     * Get core/elective subject breakdown for a level
     */
    public function getLevelSubjectBreakdown(int $schoolId, int $levelId): array
    {
        $sql = "SELECT 
                    ss.id,
                    ss.subject_name,
                    ss.subject_code,
                    sla.is_core,
                    sla.is_elective,
                    sla.is_optional,
                    sla.max_score,
                    sla.pass_mark
                FROM school_subjects ss
                JOIN subject_level_assignments sla ON ss.id = sla.subject_id
                WHERE ss.school_id = ? 
                  AND sla.level_id = ? 
                  AND ss.deleted_at IS NULL
                  AND sla.deleted_at IS NULL
                ORDER BY ss.display_order ASC";

        return $this->db->fetchAll($sql, [$schoolId, $levelId]);
    }
}
