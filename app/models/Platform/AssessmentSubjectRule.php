<?php

/**
 * AssessmentSubjectRule.php
 * Assessment Subject Rule Model
 * 
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class AssessmentSubjectRule extends BaseModel
{
    protected $table = 'assessment_subject_rules';

    protected $fillable = [
        'profile_id',
        'subject_classification_id',
        'subject_id',
        'level_id',
        'is_mandatory',
        'applicable_levels'
    ];

    /**
     * Get rules for a profile
     */
    public function getByProfile(int $profileId): array
    {
        $sql = "SELECT r.*, sc.classification_name, sc.classification_code,
                       s.subject_name, s.subject_code,
                       l.level_name, l.level_code
                FROM {$this->table} r
                LEFT JOIN subject_classifications sc ON r.subject_classification_id = sc.id
                LEFT JOIN subjects s ON r.subject_id = s.id
                LEFT JOIN institution_levels l ON r.level_id = l.level_id
                WHERE r.profile_id = ?
                ORDER BY sc.sort_order, s.subject_name";
        return $this->rawFetch($sql, [$profileId]);
    }

    /**
     * Get core subjects for a profile
     */
    public function getCoreSubjects(int $profileId): array
    {
        $sql = "SELECT r.*, s.subject_name, s.subject_code
                FROM {$this->table} r
                JOIN subject_classifications sc ON r.subject_classification_id = sc.id
                JOIN subjects s ON r.subject_id = s.id
                WHERE r.profile_id = ? AND sc.is_core = 1
                ORDER BY s.subject_name";
        return $this->rawFetch($sql, [$profileId]);
    }

    /**
     * Get elective subjects for a profile
     */
    public function getElectiveSubjects(int $profileId): array
    {
        $sql = "SELECT r.*, s.subject_name, s.subject_code
                FROM {$this->table} r
                JOIN subject_classifications sc ON r.subject_classification_id = sc.id
                JOIN subjects s ON r.subject_id = s.id
                WHERE r.profile_id = ? AND sc.is_elective = 1
                ORDER BY s.subject_name";
        return $this->rawFetch($sql, [$profileId]);
    }

    /**
     * Check if a subject is core
     */
    public function isCore(int $profileId, int $subjectId): bool
    {
        $sql = "SELECT 1 FROM {$this->table} r
                JOIN subject_classifications sc ON r.subject_classification_id = sc.id
                WHERE r.profile_id = ? AND r.subject_id = ? AND sc.is_core = 1";
        $result = $this->db->fetchOne($sql, [$profileId, $subjectId]);
        return (bool)$result;
    }

    /**
     * Check if a subject is elective
     */
    public function isElective(int $profileId, int $subjectId): bool
    {
        $sql = "SELECT 1 FROM {$this->table} r
                JOIN subject_classifications sc ON r.subject_classification_id = sc.id
                WHERE r.profile_id = ? AND r.subject_id = ? AND sc.is_elective = 1";
        $result = $this->db->fetchOne($sql, [$profileId, $subjectId]);
        return (bool)$result;
    }

    /**
     * Get subject classification
     */
    public function getClassification(int $profileId, int $subjectId): ?string
    {
        $sql = "SELECT sc.classification_code
                FROM {$this->table} r
                JOIN subject_classifications sc ON r.subject_classification_id = sc.id
                WHERE r.profile_id = ? AND r.subject_id = ?";
        $result = $this->db->fetchOne($sql, [$profileId, $subjectId]);
        return $result ? $result['classification_code'] : null;
    }
}
