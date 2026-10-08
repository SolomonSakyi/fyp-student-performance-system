<?php

/**
 * Ranking Service
 * Calculates class positions based on averages
 */

class RankingService
{
    private $db;
    private $logger;

    public function __construct($db, $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Calculate positions for a class section
     */
    public function calculatePositions($schoolId, $academicYearId, $academicTermId, $classSectionId)
    {
        $sql = "
            SELECT 
                student_id,
                average,
                RANK() OVER (ORDER BY average DESC) AS position,
                COUNT(*) OVER () AS class_size
            FROM grade_calculations
            WHERE school_id = ?
              AND academic_year_id = ?
              AND academic_term_id = ?
              AND class_section_id = ?
              AND is_calculated = 1
              AND average IS NOT NULL
        ";

        $params = [
            $schoolId,
            $academicYearId,
            $academicTermId,
            $classSectionId
        ];

        $results = $this->db->fetchAll($sql, $params);

        // Update each student's position
        foreach ($results as $row) {
            $updateSql = "
                UPDATE grade_calculations
                SET class_position = ?,
                    class_size = ?
                WHERE student_id = ?
                  AND academic_year_id = ?
                  AND academic_term_id = ?
                  AND class_section_id = ?
            ";
            $this->db->query($updateSql, [
                $row['position'],
                $row['class_size'],
                $row['student_id'],
                $academicYearId,
                $academicTermId,
                $classSectionId
            ]);
        }

        return $results;
    }
}