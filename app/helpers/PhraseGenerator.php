<?php

/**
 * PhraseGenerator — auto-generates the five report card phrases
 * (PROFILE, CONDUCT, ATTITUDE, TEACHER'S REMARK, HEAD TEACHER'S REMARK)
 * from a student's discipline, behaviour, attendance, teacher checklist,
 * lateness and academic performance for a given term.
 *
 * @package EduTrack
 * @subpackage Helpers
 * @version 1.0
 * @filepath app/helpers/PhraseGenerator.php
 *
 * WHAT THIS CLASS DOES:
 * - Reads the student's term-scoped inputs from:
 *     student_behaviour, attendance_summaries, results, student_discipline.
 * - Computes a single 0–100 composite score per category:
 *     profile, conduct, attitude, teacher_remark, head_teacher_remark.
 * - Maps each composite to one of six bands:
 *     critical, poor, fair, good, very_good, excellent.
 * - Selects one phrase per category from report_phrases using the
 *   deterministic rule: ORDER BY weight DESC, id ASC LIMIT 1.
 * - Returns an associative array of five phrase strings (or null
 *   per category if the cell is empty).
 *
 * WHAT THIS CLASS DOES NOT DO:
 * - It does not write to any table. No INSERT, UPDATE, DELETE, audit.
 * - It does not cache results across calls.
 * - It does not fall back to another band when a cell is empty.
 *   The report card renders "—" for a null phrase.
 * - It does not compute position in class, grade bands, class/exam
 *   split, promoted-to value, head-teacher signature, or any other
 *   report card field. Those belong to the report card v2.0.
 * - It does not touch promotion_rules, academic_levels, or any table
 *   outside the five read above plus report_phrases.
 *
 * LOCKED DECISIONS (from the report card milestone):
 * - Band thresholds: <40 critical, 40–49 poor, 50–59 fair,
 *   60–69 good, 70–84 very_good, >=85 excellent.
 * - PROFILE: behaviour 40%, attendance 30%, academic 30%.
 *   Discipline caps: any major → cap 39; 2+ moderate or 3+ minor → cap 49.
 * - CONDUCT: student_behaviour.final_score, deduct major 15,
 *   moderate 7, minor 2. Floor 0.
 * - ATTITUDE: checklist 70%, lateness 30%. Lateness multiplier x5.
 * - TEACHER'S REMARK: academic average, +/- 5 for a >=5-point trend.
 * - HEAD TEACHER'S REMARK: pass/fail 60%, conduct 40%.
 *   All pass = 90, some pass = 60, all fail = 30.
 * - Selector: ORDER BY weight DESC, id ASC LIMIT 1.
 *
 * DEPENDENCIES:
 * - app/helpers/DatabaseHelper.php (instance passed in by the caller)
 * - report_phrases table (populated by
 *   2026_10_04_report_phrases_seed.sql)
 */
class PhraseGenerator
{
    /** Neutral fallback score used when an input source is missing. */
    private const NEUTRAL = 60.0;

    /** Band names, in ascending order of quality. */
    private const BANDS = [
        'critical',
        'poor',
        'fair',
        'good',
        'very_good',
        'excellent',
    ];

    /**
     * Generate the five phrases for one student for one term.
     *
     * @param  DatabaseHelper $db
     * @param  int            $tenantId
     * @param  int            $studentId
     * @param  int            $termId
     * @return array {
     *     @type ?string profile
     *     @type ?string conduct
     *     @type ?string attitude
     *     @type ?string teacher_remark
     *     @type ?string head_teacher_remark
     * }
     */
    public static function generate(
        DatabaseHelper $db,
        int $tenantId,
        int $studentId,
        int $termId
    ): array {
        if ($tenantId <= 0 || $studentId <= 0 || $termId <= 0) {
            return self::emptyResult();
        }

        // -------- Read the four source inputs --------
        $behaviour   = self::loadBehaviour($db, $tenantId, $studentId, $termId);
        $attendance  = self::loadAttendance($db, $tenantId, $studentId, $termId);
        $results     = self::loadResults($db, $tenantId, $studentId, $termId);
        $discipline  = self::loadDiscipline($db, $tenantId, $studentId, $termId);

        // -------- Compute composite scores --------
        $scoreProfile  = self::computeProfileScore($behaviour, $attendance, $results, $discipline);
        $scoreConduct  = self::computeConductScore($behaviour, $discipline);
        $scoreAttitude = self::computeAttitudeScore($behaviour, $attendance);
        $scoreTeacher  = self::computeTeacherScore($db, $tenantId, $studentId, $termId, $results);
        $scoreHead     = self::computeHeadScore($results, $scoreConduct);

        // -------- Map to bands --------
        $bandProfile  = self::bandFor($scoreProfile);
        $bandConduct  = self::bandFor($scoreConduct);
        $bandAttitude = self::bandFor($scoreAttitude);
        $bandTeacher  = self::bandFor($scoreTeacher);
        $bandHead     = self::bandFor($scoreHead);

        // -------- Select phrases --------
        return [
            'profile'             => self::selectPhrase($db, $tenantId, 'profile',             $bandProfile),
            'conduct'             => self::selectPhrase($db, $tenantId, 'conduct',             $bandConduct),
            'attitude'            => self::selectPhrase($db, $tenantId, 'attitude',            $bandAttitude),
            'teacher_remark'      => self::selectPhrase($db, $tenantId, 'teacher_remark',      $bandTeacher),
            'head_teacher_remark' => self::selectPhrase($db, $tenantId, 'head_teacher_remark', $bandHead),
        ];
    }

    // =================================================================
    // Source loaders
    // =================================================================

    private static function loadBehaviour(DatabaseHelper $db, int $tenantId, int $studentId, int $termId): ?array
    {
        try {
            $row = $db->fetchOne(
                "SELECT final_score, incidents_minor, incidents_moderate, incidents_major,
                        absence_count, late_count,
                        rating_punctuality, rating_respect, rating_participation,
                        checklist_bonus, status
                   FROM student_behaviour
                  WHERE tenant_id = ? AND student_id = ? AND academic_term_id = ?
                    AND deleted_at IS NULL
                  ORDER BY id DESC
                  LIMIT 1",
                [$tenantId, $studentId, $termId]
            );
            return $row ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    private static function loadAttendance(DatabaseHelper $db, int $tenantId, int $studentId, int $termId): ?array
    {
        try {
            $row = $db->fetchOne(
                "SELECT days_present, days_absent, days_late, days_excused, days_total, status
                   FROM attendance_summaries
                  WHERE tenant_id = ? AND student_id = ? AND academic_term_id = ?
                    AND deleted_at IS NULL
                  ORDER BY id DESC
                  LIMIT 1",
                [$tenantId, $studentId, $termId]
            );
            return $row ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    private static function loadResults(DatabaseHelper $db, int $tenantId, int $studentId, int $termId): array
    {
        try {
            $rows = $db->fetchAll(
                "SELECT raw_score, final_score, is_pass, status
                   FROM results
                  WHERE tenant_id = ? AND student_id = ? AND academic_term_id = ?
                    AND deleted_at IS NULL
                    AND status IN ('calculated', 'published')",
                [$tenantId, $studentId, $termId]
            );
            return $rows ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    private static function loadDiscipline(DatabaseHelper $db, int $tenantId, int $studentId, int $termId): array
    {
        try {
            $rows = $db->fetchAll(
                "SELECT severity, status
                   FROM student_discipline
                  WHERE tenant_id = ? AND student_id = ? AND academic_term_id = ?
                    AND deleted_at IS NULL
                    AND status IN ('reviewed', 'resolved')",
                [$tenantId, $studentId, $termId]
            );
            return $rows ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    // =================================================================
    // Composite score computations
    // =================================================================

    private static function computeProfileScore(?array $behaviour, ?array $attendance, array $results, array $discipline): float
    {
        // Behaviour component
        $behaviourScore = self::NEUTRAL;
        if ($behaviour && $behaviour['final_score'] !== null && $behaviour['final_score'] !== '') {
            $behaviourScore = self::clamp((float)$behaviour['final_score'], 0.0, 100.0);
        }

        // Attendance component
        $attendanceScore = self::NEUTRAL;
        if ($attendance && isset($attendance['days_total']) && (int)$attendance['days_total'] > 0) {
            $present = (int)($attendance['days_present'] ?? 0);
            $total   = (int)$attendance['days_total'];
            $attendanceScore = self::clamp(($present / $total) * 100.0, 0.0, 100.0);
        }

        // Academic component
        $academicScore = self::NEUTRAL;
        $avg = self::averageResults($results);
        if ($avg !== null) {
            $academicScore = self::clamp($avg, 0.0, 100.0);
        }

        $composite = ($behaviourScore * 0.40) + ($attendanceScore * 0.30) + ($academicScore * 0.30);

        // Discipline caps (secondary input)
        $majorCount    = 0;
        $moderateCount = 0;
        $minorCount    = 0;
        foreach ($discipline as $d) {
            switch ((string)($d['severity'] ?? '')) {
                case 'major':
                    $majorCount++;
                    break;
                case 'moderate':
                    $moderateCount++;
                    break;
                case 'minor':
                    $minorCount++;
                    break;
            }
        }
        if ($majorCount >= 1) {
            $composite = min($composite, 39.0);
        } elseif ($moderateCount >= 2 || $minorCount >= 3) {
            $composite = min($composite, 49.0);
        }

        return self::clamp($composite, 0.0, 100.0);
    }

    private static function computeConductScore(?array $behaviour, array $discipline): float
    {
        $base = self::NEUTRAL;
        if ($behaviour && $behaviour['final_score'] !== null && $behaviour['final_score'] !== '') {
            $base = self::clamp((float)$behaviour['final_score'], 0.0, 100.0);
        }

        foreach ($discipline as $d) {
            switch ((string)($d['severity'] ?? '')) {
                case 'major':
                    $base -= 15;
                    break;
                case 'moderate':
                    $base -= 7;
                    break;
                case 'minor':
                    $base -= 2;
                    break;
            }
        }

        return self::clamp($base, 0.0, 100.0);
    }

    private static function computeAttitudeScore(?array $behaviour, ?array $attendance): float
    {
        // Checklist component
        $checklistScore = null;
        if ($behaviour) {
            $ratings = [];
            foreach (['rating_punctuality', 'rating_respect', 'rating_participation'] as $key) {
                if (isset($behaviour[$key]) && $behaviour[$key] !== null && $behaviour[$key] !== '') {
                    $ratings[] = (int)$behaviour[$key];
                }
            }
            if (!empty($ratings)) {
                $avgRating = array_sum($ratings) / count($ratings); // 1..5
                $checklistScore = (($avgRating - 1) / 4.0) * 100.0;
            } elseif (isset($behaviour['checklist_bonus']) && $behaviour['checklist_bonus'] !== null && $behaviour['checklist_bonus'] !== '') {
                $checklistScore = ((float)$behaviour['checklist_bonus'] / 20.0) * 100.0;
            }
        }
        if ($checklistScore === null) {
            $checklistScore = self::NEUTRAL;
        }
        $checklistScore = self::clamp($checklistScore, 0.0, 100.0);

        // Lateness component
        $latenessScore = self::NEUTRAL;
        if ($behaviour && $attendance && isset($attendance['days_total']) && (int)$attendance['days_total'] > 0) {
            $lateCount = (int)($behaviour['late_count'] ?? 0);
            $total     = (int)$attendance['days_total'];
            $latenessScore = 100.0 - (($lateCount / max(1, $total)) * 100.0 * 5.0);
            $latenessScore = self::clamp($latenessScore, 0.0, 100.0);
        }

        return self::clamp(($checklistScore * 0.70) + ($latenessScore * 0.30), 0.0, 100.0);
    }

    private static function computeTeacherScore(
        DatabaseHelper $db,
        int $tenantId,
        int $studentId,
        int $termId,
        array $results
    ): float {
        $avg = self::averageResults($results);
        $base = $avg !== null ? self::clamp($avg, 0.0, 100.0) : self::NEUTRAL;

        // Trend: compare to the prior term of the same academic year.
        try {
            $row = $db->fetchOne(
                "SELECT id FROM academic_terms
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$termId, $tenantId]
            );
            $term = $row ?: null;
            if ($term) {
                $termFull = $db->fetchOne(
                    "SELECT academic_year_id, sort_order FROM academic_terms
                      WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$termId, $tenantId]
                );
                if ($termFull && !empty($termFull['academic_year_id'])) {
                    $prevTerm = $db->fetchOne(
                        "SELECT id FROM academic_terms
                          WHERE tenant_id = ? AND academic_year_id = ?
                            AND sort_order < ?
                            AND deleted_at IS NULL
                          ORDER BY sort_order DESC
                          LIMIT 1",
                        [$tenantId, (int)$termFull['academic_year_id'], (int)$termFull['sort_order']]
                    );
                    if ($prevTerm) {
                        $prevRows = $db->fetchAll(
                            "SELECT raw_score, final_score FROM results
                              WHERE tenant_id = ? AND student_id = ? AND academic_term_id = ?
                                AND deleted_at IS NULL
                                AND status IN ('calculated','published')",
                            [$tenantId, $studentId, (int)$prevTerm['id']]
                        );
                        $prevAvg = self::averageResults($prevRows);
                        if ($prevAvg !== null && $avg !== null) {
                            $delta = $avg - $prevAvg;
                            if ($delta >= 5.0)      $base = min(100.0, $base + 5.0);
                            elseif ($delta <= -5.0) $base = max(0.0,   $base - 5.0);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Trend not computable; no adjustment.
        }

        return self::clamp($base, 0.0, 100.0);
    }

    private static function computeHeadScore(array $results, float $conductScore): float
    {
        // Pass/fail component
        $passFail = self::NEUTRAL;
        if (!empty($results)) {
            $passCount = 0;
            $failCount = 0;
            foreach ($results as $r) {
                if ((int)($r['is_pass'] ?? 0) === 1) $passCount++;
                else                                 $failCount++;
            }
            if ($failCount === 0 && $passCount > 0)      $passFail = 90.0;
            elseif ($passCount === 0 && $failCount > 0)  $passFail = 30.0;
            else                                         $passFail = 60.0;
        }

        return self::clamp(($passFail * 0.60) + ($conductScore * 0.40), 0.0, 100.0);
    }

    // =================================================================
    // Band mapping
    // =================================================================

    private static function bandFor(float $score): string
    {
        if ($score >= 85.0) return 'excellent';
        if ($score >= 70.0) return 'very_good';
        if ($score >= 60.0) return 'good';
        if ($score >= 50.0) return 'fair';
        if ($score >= 40.0) return 'poor';
        return 'critical';
    }

    // =================================================================
    // Phrase selection
    // =================================================================

    private static function selectPhrase(
        DatabaseHelper $db,
        int $tenantId,
        string $category,
        string $band
    ): ?string {
        try {
            $row = $db->fetchOne(
                "SELECT text FROM report_phrases
                  WHERE tenant_id = ?
                    AND category = ?
                    AND band = ?
                    AND is_active = 1
                    AND deleted_at IS NULL
                  ORDER BY weight DESC, id ASC
                  LIMIT 1",
                [$tenantId, $category, $band]
            );
            if (!$row) return null;
            $text = (string)($row['text'] ?? '');
            return $text !== '' ? $text : null;
        } catch (Exception $e) {
            return null;
        }
    }

    // =================================================================
    // Small helpers
    // =================================================================

    private static function averageResults(array $results): ?float
    {
        $sum = 0.0;
        $n = 0;
        foreach ($results as $r) {
            $v = null;
            if (isset($r['final_score']) && $r['final_score'] !== null && $r['final_score'] !== '') {
                $v = (float)$r['final_score'];
            } elseif (isset($r['raw_score']) && $r['raw_score'] !== null && $r['raw_score'] !== '') {
                $v = (float)$r['raw_score'];
            }
            if ($v !== null) {
                $sum += $v;
                $n++;
            }
        }
        return $n > 0 ? $sum / $n : null;
    }

    private static function clamp(float $v, float $min, float $max): float
    {
        if ($v < $min) return $min;
        if ($v > $max) return $max;
        return $v;
    }

    private static function emptyResult(): array
    {
        return [
            'profile'             => null,
            'conduct'             => null,
            'attitude'            => null,
            'teacher_remark'      => null,
            'head_teacher_remark' => null,
        ];
    }
}
