<?php

/**
 * Academic Engine Controller
 * Handles HTTP requests for processing academic results
 */

// Include required services (using correct relative paths)
require_once __DIR__ . '/../services/GradingService.php';
require_once __DIR__ . '/../services/RemarkGenerationService.php';
require_once __DIR__ . '/../services/AcademicEngineService.php';
require_once __DIR__ . '/../helpers/LoggerHelper.php';
require_once __DIR__ . '/../helpers/DatabaseHelper.php';

class AcademicEngineController
{
    private $logger;

    public function __construct()
    {
        $this->logger = new LoggerHelper();
    }

    /**
     * Process results for a class section
     */
    public function processClass($schoolId, $academicYearId, $academicTermId, $classSectionId)
    {
        try {
            $this->logger->info("Processing class: " . $classSectionId);

            $engine = new AcademicEngineService(
                $schoolId,
                $academicYearId,
                $academicTermId,
                $classSectionId
            );

            $result = $engine->process();

            return [
                'success' => true,
                'data' => $result
            ];

        } catch (Exception $e) {
            $this->logger->error('Class processing failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Process all classes for a term
     */
    public function processTerm($schoolId, $academicYearId, $academicTermId)
    {
        try {
            $this->logger->info("Processing term: " . $academicTermId);

            // Get all active class sections for this term
            $db = DatabaseHelper::getInstance();
            $sql = "
                SELECT DISTINCT class_section_id 
                FROM student_enrollments 
                WHERE school_id = ? 
                  AND academic_year_id = ? 
                  AND academic_term_id = ? 
                  AND enrollment_status = 'Active'
            ";
            $classSections = $db->fetchAll($sql, [$schoolId, $academicYearId, $academicTermId]);

            $results = [];
            foreach ($classSections as $class) {
                $engine = new AcademicEngineService(
                    $schoolId,
                    $academicYearId,
                    $academicTermId,
                    $class['class_section_id']
                );
                $results[] = $engine->process();
            }

            return [
                'success' => true,
                'data' => $results
            ];

        } catch (Exception $e) {
            $this->logger->error('Term processing failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}