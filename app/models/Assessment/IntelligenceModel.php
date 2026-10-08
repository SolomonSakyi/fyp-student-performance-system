<?php
/**
 * IntelligenceModel.php
 * 
 * Enterprise Academic Intelligence Model
 * Handles AI-driven academic intelligence operations
 * 
 * @package EduTrack
 * @subpackage Models\Assessment
 * @version 2.0
 */

class IntelligenceModel
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
    // INTELLIGENCE RULES
    // ================================================================

    /**
     * Get all intelligence rules
     */
    public function getIntelligenceRules(int $schoolId = 1): array
    {
        try {
            $sql = "SELECT * FROM intelligence_rules 
                    WHERE school_id = ? AND is_active = 1 
                    ORDER BY rule_type, rule_name";
            return $this->db->fetchAll($sql, [$schoolId]);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get rules by type
     */
    public function getRulesByType(string $ruleType, int $schoolId = 1): array
    {
        try {
            $sql = "SELECT * FROM intelligence_rules 
                    WHERE school_id = ? AND rule_type = ? AND is_active = 1 
                    ORDER BY rule_name";
            return $this->db->fetchAll($sql, [$schoolId, $ruleType]);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Create intelligence rule
     */
    public function createIntelligenceRule(array $data): int
    {
        try {
            $sql = "INSERT INTO intelligence_rules (
                uuid, school_id, rule_name, rule_code, rule_type,
                category, condition_type, condition_value,
                output_template, is_default, is_active
            ) VALUES (
                UUID(), ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, 1
            )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['rule_name'],
                $data['rule_code'],
                $data['rule_type'],
                $data['category'] ?? 'academic',
                $data['condition_type'] ?? 'threshold',
                $data['condition_value'],
                $data['output_template'],
                $data['is_default'] ?? 0
            ]);

            return $this->db->lastInsertId();
        } catch (Exception $e) {
            throw new Exception("Failed to create intelligence rule: " . $e->getMessage());
        }
    }

    // ================================================================
    // PERSONALITY SUMMARIES
    // ================================================================

    /**
     * Save personality summary
     */
    public function savePersonalitySummary(array $data): int
    {
        try {
            // Check if exists
            $existing = $this->db->fetchOne("
                SELECT id FROM personality_summaries 
                WHERE student_id = ? AND academic_term_id = ?
            ", [$data['student_id'], $data['academic_term_id']]);

            if ($existing) {
                $sql = "UPDATE personality_summaries SET 
                        personality_type = ?,
                        strengths = ?,
                        weaknesses = ?,
                        learning_style = ?,
                        motivation_level = ?,
                        confidence_level = ?,
                        social_skills = ?,
                        summary = ?,
                        is_published = ?,
                        updated_at = NOW()
                        WHERE id = ?";
                
                $this->db->query($sql, [
                    $data['personality_type'] ?? null,
                    $data['strengths'] ?? null,
                    $data['weaknesses'] ?? null,
                    $data['learning_style'] ?? null,
                    $data['motivation_level'] ?? 'unknown',
                    $data['confidence_level'] ?? 'unknown',
                    $data['social_skills'] ?? 'average',
                    $data['summary'] ?? null,
                    $data['is_published'] ?? 0,
                    $existing['id']
                ]);

                return $existing['id'];
            } else {
                $sql = "INSERT INTO personality_summaries (
                    uuid, school_id, student_id, academic_term_id,
                    personality_type, strengths, weaknesses,
                    learning_style, motivation_level, confidence_level,
                    social_skills, summary, is_published, is_active
                ) VALUES (
                    UUID(), ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, 1
                )";

                $this->db->query($sql, [
                    $data['school_id'] ?? 1,
                    $data['student_id'],
                    $data['academic_term_id'],
                    $data['personality_type'] ?? null,
                    $data['strengths'] ?? null,
                    $data['weaknesses'] ?? null,
                    $data['learning_style'] ?? null,
                    $data['motivation_level'] ?? 'unknown',
                    $data['confidence_level'] ?? 'unknown',
                    $data['social_skills'] ?? 'average',
                    $data['summary'] ?? null,
                    $data['is_published'] ?? 0
                ]);

                return $this->db->lastInsertId();
            }
        } catch (Exception $e) {
            throw new Exception("Failed to save personality summary: " . $e->getMessage());
        }
    }

    /**
     * Get personality summary
     */
    public function getPersonalitySummary(int $studentId, int $termId): ?array
    {
        try {
            $sql = "SELECT * FROM personality_summaries 
                    WHERE student_id = ? AND academic_term_id = ? 
                    AND is_active = 1";
            return $this->db->fetchOne($sql, [$studentId, $termId]);
        } catch (Exception $e) {
            return null;
        }
    }

    // ================================================================
    // BEHAVIOUR SUMMARIES
    // ================================================================

    /**
     * Save behaviour summary
     */
    public function saveBehaviourSummary(array $data): int
    {
        try {
            $existing = $this->db->fetchOne("
                SELECT id FROM behaviour_summaries 
                WHERE student_id = ? AND academic_term_id = ?
            ", [$data['student_id'], $data['academic_term_id']]);

            if ($existing) {
                $sql = "UPDATE behaviour_summaries SET 
                        behaviour_score = ?,
                        behaviour_grade = ?,
                        attendance_score = ?,
                        punctuality_score = ?,
                        participation_score = ?,
                        cooperation_score = ?,
                        responsibility_score = ?,
                        behaviour_summary = ?,
                        class_teacher_remark = ?,
                        headteacher_remark = ?,
                        is_published = ?,
                        updated_at = NOW()
                        WHERE id = ?";
                
                $this->db->query($sql, [
                    $data['behaviour_score'] ?? 0,
                    $data['behaviour_grade'] ?? null,
                    $data['attendance_score'] ?? 0,
                    $data['punctuality_score'] ?? 0,
                    $data['participation_score'] ?? 0,
                    $data['cooperation_score'] ?? 0,
                    $data['responsibility_score'] ?? 0,
                    $data['behaviour_summary'] ?? null,
                    $data['class_teacher_remark'] ?? null,
                    $data['headteacher_remark'] ?? null,
                    $data['is_published'] ?? 0,
                    $existing['id']
                ]);

                return $existing['id'];
            } else {
                $sql = "INSERT INTO behaviour_summaries (
                    uuid, school_id, student_id, academic_term_id,
                    behaviour_score, behaviour_grade,
                    attendance_score, punctuality_score,
                    participation_score, cooperation_score,
                    responsibility_score, behaviour_summary,
                    class_teacher_remark, headteacher_remark,
                    is_published, is_active
                ) VALUES (
                    UUID(), ?, ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, 1
                )";

                $this->db->query($sql, [
                    $data['school_id'] ?? 1,
                    $data['student_id'],
                    $data['academic_term_id'],
                    $data['behaviour_score'] ?? 0,
                    $data['behaviour_grade'] ?? null,
                    $data['attendance_score'] ?? 0,
                    $data['punctuality_score'] ?? 0,
                    $data['participation_score'] ?? 0,
                    $data['cooperation_score'] ?? 0,
                    $data['responsibility_score'] ?? 0,
                    $data['behaviour_summary'] ?? null,
                    $data['class_teacher_remark'] ?? null,
                    $data['headteacher_remark'] ?? null,
                    $data['is_published'] ?? 0
                ]);

                return $this->db->lastInsertId();
            }
        } catch (Exception $e) {
            throw new Exception("Failed to save behaviour summary: " . $e->getMessage());
        }
    }

    /**
     * Get behaviour summary
     */
    public function getBehaviourSummary(int $studentId, int $termId): ?array
    {
        try {
            $sql = "SELECT * FROM behaviour_summaries 
                    WHERE student_id = ? AND academic_term_id = ? 
                    AND is_active = 1";
            return $this->db->fetchOne($sql, [$studentId, $termId]);
        } catch (Exception $e) {
            return null;
        }
    }

    // ================================================================
    // ACADEMIC RISK PROFILES
    // ================================================================

    /**
     * Save academic risk profile
     */
    public function saveRiskProfile(array $data): int
    {
        try {
            $existing = $this->db->fetchOne("
                SELECT id FROM academic_risk_profiles 
                WHERE student_id = ? AND academic_term_id = ?
            ", [$data['student_id'], $data['academic_term_id']]);

            if ($existing) {
                $sql = "UPDATE academic_risk_profiles SET 
                        risk_level = ?,
                        risk_score = ?,
                        academic_performance = ?,
                        attendance_percentage = ?,
                        behaviour_score = ?,
                        subjects_failing = ?,
                        consecutive_failures = ?,
                        declining_trend = ?,
                        alert_reason = ?,
                        recommendation = ?,
                        intervention_required = ?,
                        intervention_type = ?,
                        is_resolved = ?,
                        resolved_date = ?,
                        updated_at = NOW()
                        WHERE id = ?";
                
                $this->db->query($sql, [
                    $data['risk_level'] ?? 'low',
                    $data['risk_score'] ?? 0,
                    $data['academic_performance'] ?? 0,
                    $data['attendance_percentage'] ?? 0,
                    $data['behaviour_score'] ?? 0,
                    $data['subjects_failing'] ?? 0,
                    $data['consecutive_failures'] ?? 0,
                    $data['declining_trend'] ?? 0,
                    $data['alert_reason'] ?? null,
                    $data['recommendation'] ?? null,
                    $data['intervention_required'] ?? 0,
                    $data['intervention_type'] ?? null,
                    $data['is_resolved'] ?? 0,
                    $data['resolved_date'] ?? null,
                    $existing['id']
                ]);

                return $existing['id'];
            } else {
                $sql = "INSERT INTO academic_risk_profiles (
                    uuid, school_id, student_id, academic_term_id,
                    risk_level, risk_score, academic_performance,
                    attendance_percentage, behaviour_score,
                    subjects_failing, consecutive_failures,
                    declining_trend, alert_reason, recommendation,
                    intervention_required, intervention_type,
                    is_resolved, is_active
                ) VALUES (
                    UUID(), ?, ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    0, 1
                )";

                $this->db->query($sql, [
                    $data['school_id'] ?? 1,
                    $data['student_id'],
                    $data['academic_term_id'],
                    $data['risk_level'] ?? 'low',
                    $data['risk_score'] ?? 0,
                    $data['academic_performance'] ?? 0,
                    $data['attendance_percentage'] ?? 0,
                    $data['behaviour_score'] ?? 0,
                    $data['subjects_failing'] ?? 0,
                    $data['consecutive_failures'] ?? 0,
                    $data['declining_trend'] ?? 0,
                    $data['alert_reason'] ?? null,
                    $data['recommendation'] ?? null,
                    $data['intervention_required'] ?? 0,
                    $data['intervention_type'] ?? null
                ]);

                return $this->db->lastInsertId();
            }
        } catch (Exception $e) {
            throw new Exception("Failed to save risk profile: " . $e->getMessage());
        }
    }

    /**
     * Get risk profile
     */
    public function getRiskProfile(int $studentId, int $termId): ?array
    {
        try {
            $sql = "SELECT * FROM academic_risk_profiles 
                    WHERE student_id = ? AND academic_term_id = ? 
                    AND is_active = 1";
            return $this->db->fetchOne($sql, [$studentId, $termId]);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get at-risk students
     * 
     * @param int $termId Term ID
     * @param string|null $riskLevel Risk level filter (optional)
     * @return array
     */
    public function getAtRiskStudents(int $termId, ?string $riskLevel = null): array
    {
        try {
            $sql = "SELECT arp.*, s.first_name, s.last_name, s.admission_number,
                    gl.level_name, cs.section_name
                    FROM academic_risk_profiles arp
                    JOIN students s ON arp.student_id = s.id
                    JOIN class_sections cs ON s.id = cs.id
                    JOIN grade_levels gl ON cs.grade_level_id = gl.id
                    WHERE arp.academic_term_id = ? 
                    AND arp.is_resolved = 0
                    AND arp.is_active = 1";

            $params = [$termId];

            if ($riskLevel) {
                $sql .= " AND arp.risk_level = ?";
                $params[] = $riskLevel;
            }

            $sql .= " ORDER BY arp.risk_score DESC";

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            return [];
        }
    }

    // ================================================================
    // PROMOTION EVALUATIONS
    // ================================================================

    /**
     * Save promotion evaluation
     */
    public function savePromotionEvaluation(array $data): int
    {
        try {
            $existing = $this->db->fetchOne("
                SELECT id FROM promotion_evaluations 
                WHERE student_id = ? AND academic_year_id = ?
            ", [$data['student_id'], $data['academic_year_id']]);

            if ($existing) {
                $sql = "UPDATE promotion_evaluations SET 
                        from_grade_level_id = ?,
                        to_grade_level_id = ?,
                        promotion_type = ?,
                        average_score = ?,
                        passing_subjects = ?,
                        failing_subjects = ?,
                        total_subjects = ?,
                        attendance_percentage = ?,
                        behaviour_score = ?,
                        eligibility_criteria_met = ?,
                        teacher_recommendation = ?,
                        headteacher_approval = ?,
                        headteacher_remarks = ?,
                        graduation_certificate = ?,
                        graduation_date = ?,
                        updated_at = NOW()
                        WHERE id = ?";
                
                $this->db->query($sql, [
                    $data['from_grade_level_id'],
                    $data['to_grade_level_id'] ?? null,
                    $data['promotion_type'] ?? 'promoted',
                    $data['average_score'] ?? 0,
                    $data['passing_subjects'] ?? 0,
                    $data['failing_subjects'] ?? 0,
                    $data['total_subjects'] ?? 0,
                    $data['attendance_percentage'] ?? 0,
                    $data['behaviour_score'] ?? 0,
                    $data['eligibility_criteria_met'] ?? 0,
                    $data['teacher_recommendation'] ?? null,
                    $data['headteacher_approval'] ?? 0,
                    $data['headteacher_remarks'] ?? null,
                    $data['graduation_certificate'] ?? null,
                    $data['graduation_date'] ?? null,
                    $existing['id']
                ]);

                return $existing['id'];
            } else {
                $sql = "INSERT INTO promotion_evaluations (
                    uuid, school_id, student_id, academic_year_id,
                    from_grade_level_id, to_grade_level_id,
                    promotion_type, average_score,
                    passing_subjects, failing_subjects, total_subjects,
                    attendance_percentage, behaviour_score,
                    eligibility_criteria_met, teacher_recommendation,
                    headteacher_approval, headteacher_remarks,
                    graduation_certificate, graduation_date, is_active
                ) VALUES (
                    UUID(), ?, ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?, 1
                )";

                $this->db->query($sql, [
                    $data['school_id'] ?? 1,
                    $data['student_id'],
                    $data['academic_year_id'],
                    $data['from_grade_level_id'],
                    $data['to_grade_level_id'] ?? null,
                    $data['promotion_type'] ?? 'promoted',
                    $data['average_score'] ?? 0,
                    $data['passing_subjects'] ?? 0,
                    $data['failing_subjects'] ?? 0,
                    $data['total_subjects'] ?? 0,
                    $data['attendance_percentage'] ?? 0,
                    $data['behaviour_score'] ?? 0,
                    $data['eligibility_criteria_met'] ?? 0,
                    $data['teacher_recommendation'] ?? null,
                    $data['headteacher_approval'] ?? 0,
                    $data['headteacher_remarks'] ?? null,
                    $data['graduation_certificate'] ?? null,
                    $data['graduation_date'] ?? null
                ]);

                return $this->db->lastInsertId();
            }
        } catch (Exception $e) {
            throw new Exception("Failed to save promotion evaluation: " . $e->getMessage());
        }
    }

    /**
     * Get promotion evaluation
     */
    public function getPromotionEvaluation(int $studentId, int $yearId): ?array
    {
        try {
            $sql = "SELECT pe.*, 
                    fgl.level_name AS from_level,
                    tgl.level_name AS to_level
                    FROM promotion_evaluations pe
                    LEFT JOIN grade_levels fgl ON pe.from_grade_level_id = fgl.id
                    LEFT JOIN grade_levels tgl ON pe.to_grade_level_id = tgl.id
                    WHERE pe.student_id = ? 
                    AND pe.academic_year_id = ?
                    AND pe.is_active = 1";
            return $this->db->fetchOne($sql, [$studentId, $yearId]);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get promotion list
     */
    public function getPromotionList(int $yearId, ?string $promotionType = null): array
    {
        try {
            $sql = "SELECT pe.*, s.first_name, s.last_name, s.admission_number,
                    fgl.level_name AS from_level,
                    tgl.level_name AS to_level
                    FROM promotion_evaluations pe
                    JOIN students s ON pe.student_id = s.id
                    LEFT JOIN grade_levels fgl ON pe.from_grade_level_id = fgl.id
                    LEFT JOIN grade_levels tgl ON pe.to_grade_level_id = tgl.id
                    WHERE pe.academic_year_id = ? 
                    AND pe.is_active = 1";

            $params = [$yearId];

            if ($promotionType) {
                $sql .= " AND pe.promotion_type = ?";
                $params[] = $promotionType;
            }

            $sql .= " ORDER BY s.first_name";

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            return [];
        }
    }

    // ================================================================
    // GPA CALCULATIONS
    // ================================================================

    /**
     * Calculate and save GPA
     */
    public function calculateGPA(int $studentId, int $termId): array
    {
        try {
            // Get results
            $results = $this->db->fetchAll("
                SELECT grade_point, credit_hours 
                FROM assessment_results 
                WHERE student_id = ? AND academic_term_id = ? 
                AND is_active = 1
            ", [$studentId, $termId]);

            $totalCreditHours = 0;
            $totalGradePoints = 0;

            foreach ($results as $result) {
                $creditHours = $result['credit_hours'] ?? 1;
                $gradePoint = $result['grade_point'] ?? 0;

                $totalCreditHours += $creditHours;
                $totalGradePoints += $gradePoint * $creditHours;
            }

            $gpa = $totalCreditHours > 0 ? round($totalGradePoints / $totalCreditHours, 2) : 0;

            // Save GPA
            $this->db->query("
                INSERT INTO gpa_calculations (
                    uuid, school_id, student_id, academic_term_id,
                    total_credit_hours, total_grade_points, gpa,
                    calculation_type, is_calculated, calculated_date, is_active
                ) VALUES (
                    UUID(), 1, ?, ?,
                    ?, ?, ?,
                    'term', 1, NOW(), 1
                )
                ON DUPLICATE KEY UPDATE
                    total_credit_hours = VALUES(total_credit_hours),
                    total_grade_points = VALUES(total_grade_points),
                    gpa = VALUES(gpa),
                    is_calculated = 1,
                    calculated_date = NOW()
            ", [$studentId, $termId, $totalCreditHours, $totalGradePoints, $gpa]);

            return [
                'gpa' => $gpa,
                'total_credit_hours' => $totalCreditHours,
                'total_grade_points' => $totalGradePoints
            ];
        } catch (Exception $e) {
            throw new Exception("Failed to calculate GPA: " . $e->getMessage());
        }
    }

    /**
     * Get student GPA
     */
    public function getStudentGPA(int $studentId, int $termId): ?array
    {
        try {
            $sql = "SELECT * FROM gpa_calculations 
                    WHERE student_id = ? AND academic_term_id = ? 
                    AND is_active = 1";
            return $this->db->fetchOne($sql, [$studentId, $termId]);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get cumulative GPA (CGPA)
     */
    public function getCumulativeGPA(int $studentId): ?array
    {
        try {
            $sql = "SELECT 
                        SUM(total_credit_hours) AS total_credit_hours,
                        SUM(total_grade_points) AS total_grade_points,
                        ROUND(SUM(total_grade_points) / NULLIF(SUM(total_credit_hours), 0), 2) AS cgpa
                    FROM gpa_calculations 
                    WHERE student_id = ? AND is_active = 1";
            return $this->db->fetchOne($sql, [$studentId]);
        } catch (Exception $e) {
            return null;
        }
    }
}