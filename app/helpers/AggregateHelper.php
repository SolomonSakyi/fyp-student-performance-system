<?php
/**
 * Aggregate Helper
 * Handles calculation of academic aggregates for different examination systems
 * (BECE, WASCE, and custom calculations)
 * 
 * @package EduTrack
 * @version 1.0
 */

class AggregateHelper
{
    /**
     * Grade mapping for different examination systems
     */
    private static $gradeMaps = [
        'bece' => [
            'A' => 1, 'A+' => 1, 'A-' => 1,
            'B' => 2, 'B+' => 2, 'B-' => 2,
            'C' => 3, 'C+' => 3, 'C-' => 3,
            'D' => 4, 'D+' => 4, 'D-' => 4,
            'E' => 5, 'E+' => 5, 'E-' => 5,
            'F' => 6, 'F+' => 6, 'F-' => 6,
            'G' => 7, 'G+' => 7, 'G-' => 7,
            'H' => 8, 'H+' => 8, 'H-' => 8,
            'I' => 9, 'I+' => 9, 'I-' => 9,
            '1' => 1, '2' => 2, '3' => 3, '4' => 4,
            '5' => 5, '6' => 6, '7' => 7, '8' => 8, '9' => 9
        ],
        'wasce' => [
            'A1' => 1, 'B2' => 2, 'B3' => 3, 'C4' => 4,
            'C5' => 5, 'C6' => 6, 'D7' => 7, 'E8' => 8, 'F9' => 9
        ],
        'percentage' => []
    ];

    /**
     * Calculate BECE Aggregate
     * 
     * @param array $subjectGrades Array of subject grades with 'subject_id', 'grade', and 'is_core' flags
     * @param int $coreCount Number of core subjects to include (default: 4)
     * @param int $electiveCount Number of best electives to include (default: 2)
     * @return array ['aggregate' => int, 'details' => array]
     */
    public static function calculateBECE($subjectGrades, $coreCount = 4, $electiveCount = 2)
    {
        return self::calculateAggregate($subjectGrades, 'bece', $coreCount, $electiveCount);
    }

    /**
     * Calculate WASCE Aggregate
     * 
     * @param array $subjectGrades Array of subject grades
     * @param int $coreCount Number of core subjects to include (default: 3)
     * @param int $electiveCount Number of best electives to include (default: 3)
     * @return array ['aggregate' => int, 'details' => array]
     */
    public static function calculateWASCE($subjectGrades, $coreCount = 3, $electiveCount = 3)
    {
        return self::calculateAggregate($subjectGrades, 'wasce', $coreCount, $electiveCount);
    }

    /**
     * Calculate Aggregate for any examination system
     * 
     * @param array $subjectGrades Array of subject grades
     * @param string $system Type of examination system ('bece', 'wasce', 'custom')
     * @param int $coreCount Number of core subjects to include
     * @param int $electiveCount Number of best electives to include
     * @return array
     */
    public static function calculateAggregate($subjectGrades, $system = 'bece', $coreCount = 4, $electiveCount = 2)
    {
        $gradeMap = self::$gradeMaps[$system] ?? self::$gradeMaps['bece'];
        
        $cores = [];
        $electives = [];
        $coreDetails = [];
        $electiveDetails = [];
        
        foreach ($subjectGrades as $subject) {
            $grade = $subject['grade'] ?? 'F';
            $gradeValue = $gradeMap[$grade] ?? 9;
            
            $subjectData = [
                'subject_id' => $subject['subject_id'] ?? 0,
                'subject_name' => $subject['subject_name'] ?? 'Unknown',
                'grade' => $grade,
                'grade_value' => $gradeValue
            ];
            
            if ($subject['is_core'] ?? false) {
                $cores[] = $gradeValue;
                $coreDetails[] = $subjectData;
            } else {
                $electives[] = $gradeValue;
                $electiveDetails[] = $subjectData;
            }
        }
        
        array_multisort($electives, SORT_ASC, $electiveDetails);
        $bestElectives = array_slice($electives, 0, $electiveCount);
        $bestElectiveDetails = array_slice($electiveDetails, 0, $electiveCount);
        
        $coreSum = array_sum($cores);
        $electiveSum = array_sum($bestElectives);
        $aggregate = $coreSum + $electiveSum;
        
        return [
            'aggregate' => $aggregate,
            'core_count' => count($cores),
            'core_sum' => $coreSum,
            'core_subjects' => $coreDetails,
            'core_grades' => $cores,
            'elective_count' => count($bestElectives),
            'elective_sum' => $electiveSum,
            'elective_subjects' => $bestElectiveDetails,
            'elective_grades' => $bestElectives,
            'details' => "Core: " . implode(' + ', $cores) . " = " . $coreSum . 
                         " | Best Electives: " . implode(' + ', $bestElectives) . " = " . $electiveSum .
                         " | Aggregate = " . $coreSum . " + " . $electiveSum . " = " . $aggregate,
            'system' => $system
        ];
    }

    /**
     * Calculate aggregate from database records
     */
    public static function calculateFromDatabase($studentId, $academicYearId, $academicTermId, $gradeLevelId, $db)
    {
        $config = $db->fetchOne("
            SELECT * FROM aggregate_calculation_config 
            WHERE grade_level_id = ? AND is_active = 1
        ", [$gradeLevelId]);
        
        if (!$config) {
            $coreCount = 4;
            $electiveCount = 2;
            $system = 'bece';
        } else {
            $coreCount = $config['core_subject_count'];
            $electiveCount = $config['best_elective_count'];
            $system = $config['calculation_type'];
        }
        
        $grades = $db->fetchAll("
            SELECT 
                gc.subject_id,
                gc.grade,
                s.is_core,
                s.subject_name,
                s.subject_type
            FROM grade_calculations gc
            JOIN subjects s ON gc.subject_id = s.id
            WHERE gc.student_id = ?
            AND gc.academic_year_id = ?
            AND gc.academic_term_id = ?
            AND s.is_active = 1
        ", [$studentId, $academicYearId, $academicTermId]);
        
        if (empty($grades)) {
            return ['aggregate' => null, 'error' => 'No grades found'];
        }
        
        $subjectGrades = [];
        foreach ($grades as $grade) {
            $subjectGrades[] = [
                'subject_id' => $grade['subject_id'],
                'subject_name' => $grade['subject_name'],
                'grade' => $grade['grade'],
                'is_core' => (bool)$grade['is_core']
            ];
        }
        
        return self::calculateAggregate($subjectGrades, $system, $coreCount, $electiveCount);
    }

    /**
     * Get grade mapping for a specific system
     */
    public static function getGradeMap($system = 'bece')
    {
        return self::$gradeMaps[$system] ?? self::$gradeMaps['bece'];
    }

    /**
     * Get explanation of aggregate calculation
     */
    public static function getExplanation($system = 'bece')
    {
        $explanations = [
            'bece' => 'BECE Aggregate: Sum of grades from 4 Core subjects + Best 2 Elective subjects. Lower aggregate is better.',
            'wasce' => 'WASCE Aggregate: Sum of grades from 3 Core subjects + Best 3 Elective subjects. Lower aggregate is better.',
            'custom' => 'Custom Aggregate calculation based on configured rules.'
        ];
        return $explanations[$system] ?? $explanations['bece'];
    }
}
?>