<?php
/**
 * GradingService.php
 *
 * Enterprise Grading Service Layer - BUSINESS LOGIC ONLY
 * Wraps AssessmentModel methods with business logic
 * NO DUPLICATE DATABASE QUERIES - only calls to Model
 *
 * @package EduTrack
 * @subpackage Services\Grading
 * @version 3.0
 */

require_once __DIR__ . '/../../models/Assessment/AssessmentModel.php';
require_once __DIR__ . '/../../helpers/DatabaseHelper.php';
require_once __DIR__ . '/../../helpers/LoggerHelper.php';

class GradingService
{
    /**
     * @var AssessmentModel Assessment model instance
     */
    private $assessmentModel;

    /**
     * @var DatabaseHelper Database instance
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
        $this->assessmentModel = new AssessmentModel();
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
    }

    // ==============================================================
    // GRADING SCALES - SERVICE WRAPPERS
    // ==============================================================

    public function getGradingScales(int $schoolId = 1, int $page = 1, int $limit = 50): array
    {
        try {
            $scales = $this->assessmentModel->getGradingScales($schoolId, $page, $limit);
            return ['success' => true, 'data' => $scales, 'total' => count($scales)];
        } catch (Exception $e) {
            $this->logger->error('Failed to get grading scales: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function getAllGradingScales(int $schoolId = 1): array
    {
        try {
            $scales = $this->assessmentModel->getAllGradingScales($schoolId);
            return ['success' => true, 'data' => $scales];
        } catch (Exception $e) {
            $this->logger->error('Failed to get all grading scales: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function getGradingScaleById(int $id): array
    {
        try {
            $scale = $this->assessmentModel->getGradingScaleById($id);
            if (!$scale) {
                return ['success' => false, 'message' => 'Grading scale not found'];
            }
            return ['success' => true, 'data' => $scale];
        } catch (Exception $e) {
            $this->logger->error('Failed to get grading scale: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getGradingScalesByType(string $scaleType, int $schoolId = 1): array
    {
        try {
            $scales = $this->assessmentModel->getGradingScalesByType($scaleType, $schoolId);
            return ['success' => true, 'data' => $scales];
        } catch (Exception $e) {
            $this->logger->error('Failed to get grading scales by type: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function createGradingScale(array $data): array
    {
        try {
            $validation = $this->assessmentModel->validateGradingScaleData($data);
            if (!$validation['valid']) {
                return ['success' => false, 'message' => implode(', ', $validation['errors'])];
            }

            $id = $this->assessmentModel->createGradingScale($data);
            if (!$id) {
                return ['success' => false, 'message' => 'Failed to create grading scale'];
            }

            if (isset($data['is_default']) && $data['is_default']) {
                $this->assessmentModel->setDefaultGradingScale($id, $data['school_id'] ?? 1);
            }

            return ['success' => true, 'message' => 'Grading scale created successfully', 'id' => $id];
        } catch (Exception $e) {
            $this->logger->error('Failed to create grading scale: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function createGradingScaleWithRanges(array $data, array $ranges): array
    {
        try {
            $successCount = 0;
            $failedCount = 0;
            $errors = [];
            $ids = [];

            foreach ($ranges as $range) {
                $scaleData = [
                    'school_id' => $data['school_id'] ?? 1,
                    'scale_name' => $data['scale_name'],
                    'scale_code' => $data['scale_code'],
                    'scale_type' => $data['scale_type'] ?? 'percentage',
                    'min_value' => (float)($range['min'] ?? 0),
                    'max_value' => (float)($range['max'] ?? 100),
                    'grade_letter' => trim($range['grade'] ?? ''),
                    'grade_point' => isset($range['point']) ? (float)$range['point'] : null,
                    'description' => $data['description'] ?? null,
                    'is_default' => $data['is_default'] ?? 0,
                    'created_by' => $data['created_by'] ?? null
                ];

                $id = $this->assessmentModel->createGradingScale($scaleData);
                if ($id) {
                    $successCount++;
                    $ids[] = $id;
                } else {
                    $failedCount++;
                    $errors[] = "Failed to create grade level: {$range['grade']}";
                }
            }

            if ($successCount > 0 && isset($data['is_default']) && $data['is_default']) {
                // Set the first created scale as default (or all of them)
                foreach ($ids as $id) {
                    $this->assessmentModel->setDefaultGradingScale($id, $data['school_id'] ?? 1);
                }
            }

            return [
                'success' => $successCount > 0,
                'message' => "Created {$successCount} grade levels, {$failedCount} failed",
                'data' => ['ids' => $ids, 'success_count' => $successCount, 'failed_count' => $failedCount, 'errors' => $errors]
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to create grading scale with ranges: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateGradingScale(int $id, array $data): array
    {
        try {
            $result = $this->assessmentModel->updateGradingScale($id, $data);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to update grading scale'];
            }

            if (isset($data['is_default']) && $data['is_default']) {
                $scale = $this->assessmentModel->getGradingScaleById($id);
                if ($scale) {
                    $this->assessmentModel->setDefaultGradingScale($id, $scale['school_id']);
                }
            }

            return ['success' => true, 'message' => 'Grading scale updated successfully'];
        } catch (Exception $e) {
            $this->logger->error('Failed to update grading scale: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteGradingScale(int $id): array
    {
        try {
            $result = $this->assessmentModel->deleteGradingScale($id);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to delete grading scale'];
            }
            return ['success' => true, 'message' => 'Grading scale deleted successfully'];
        } catch (Exception $e) {
            $this->logger->error('Failed to delete grading scale: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function setDefaultGradingScale(int $id): array
    {
        try {
            $scale = $this->assessmentModel->getGradingScaleById($id);
            if (!$scale) {
                return ['success' => false, 'message' => 'Grading scale not found'];
            }
            $result = $this->assessmentModel->setDefaultGradingScale($id, $scale['school_id']);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to set default grading scale'];
            }
            return ['success' => true, 'message' => 'Default grading scale set successfully'];
        } catch (Exception $e) {
            $this->logger->error('Failed to set default grading scale: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ==============================================================
    // GRADING SCHEMES - SERVICE WRAPPERS
    // ==============================================================

    public function getGradingSchemes(int $schoolId = 1, int $page = 1, int $limit = 50): array
    {
        try {
            $schemes = $this->assessmentModel->getGradingSchemes($schoolId, $page, $limit);
            return ['success' => true, 'data' => $schemes, 'total' => count($schemes)];
        } catch (Exception $e) {
            $this->logger->error('Failed to get grading schemes: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function getAllGradingSchemes(int $schoolId = 1): array
    {
        try {
            $schemes = $this->assessmentModel->getAllGradingSchemes($schoolId);
            return ['success' => true, 'data' => $schemes];
        } catch (Exception $e) {
            $this->logger->error('Failed to get all grading schemes: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function getGradingSchemeById(int $id): array
    {
        try {
            $scheme = $this->assessmentModel->getGradingSchemeById($id);
            if (!$scheme) {
                return ['success' => false, 'message' => 'Grading scheme not found'];
            }
            return ['success' => true, 'data' => $scheme];
        } catch (Exception $e) {
            $this->logger->error('Failed to get grading scheme: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function createGradingScheme(array $data): array
    {
        try {
            $validation = $this->assessmentModel->validateGradingSchemeData($data);
            if (!$validation['valid']) {
                return ['success' => false, 'message' => implode(', ', $validation['errors'])];
            }

            $id = $this->assessmentModel->createGradingScheme($data);
            if (!$id) {
                return ['success' => false, 'message' => 'Failed to create grading scheme'];
            }

            if (isset($data['is_default']) && $data['is_default']) {
                $this->assessmentModel->setDefaultGradingScheme(
                    $id,
                    $data['school_id'] ?? 1,
                    $data['grade_level_id'] ?? null,
                    $data['term_id'] ?? null
                );
            }

            return ['success' => true, 'message' => 'Grading scheme created successfully', 'id' => $id];
        } catch (Exception $e) {
            $this->logger->error('Failed to create grading scheme: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateGradingScheme(int $id, array $data): array
    {
        try {
            $result = $this->assessmentModel->updateGradingScheme($id, $data);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to update grading scheme'];
            }

            if (isset($data['is_default']) && $data['is_default']) {
                $scheme = $this->assessmentModel->getGradingSchemeById($id);
                if ($scheme) {
                    $this->assessmentModel->setDefaultGradingScheme(
                        $id,
                        $scheme['school_id'],
                        $scheme['grade_level_id'] ?? null,
                        $scheme['term_id'] ?? null
                    );
                }
            }

            return ['success' => true, 'message' => 'Grading scheme updated successfully'];
        } catch (Exception $e) {
            $this->logger->error('Failed to update grading scheme: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteGradingScheme(int $id): array
    {
        try {
            $result = $this->assessmentModel->deleteGradingScheme($id);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to delete grading scheme'];
            }
            return ['success' => true, 'message' => 'Grading scheme deleted successfully'];
        } catch (Exception $e) {
            $this->logger->error('Failed to delete grading scheme: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ==============================================================
    // GRADING SCHEME DETAILS - SERVICE WRAPPERS
    // ==============================================================

    public function getGradingSchemeDetails(int $schemeId): array
    {
        try {
            $details = $this->assessmentModel->getGradingSchemeDetails($schemeId);
            return ['success' => true, 'data' => $details];
        } catch (Exception $e) {
            $this->logger->error('Failed to get grading scheme details: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function createGradingSchemeDetail(array $data): array
    {
        try {
            $validation = $this->assessmentModel->validateGradingSchemeDetailData($data);
            if (!$validation['valid']) {
                return ['success' => false, 'message' => implode(', ', $validation['errors'])];
            }

            $id = $this->assessmentModel->createGradingSchemeDetail($data);
            if (!$id) {
                return ['success' => false, 'message' => 'Failed to create grading scheme detail'];
            }

            return ['success' => true, 'message' => 'Subject override added successfully', 'id' => $id];
        } catch (Exception $e) {
            $this->logger->error('Failed to create grading scheme detail: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateGradingSchemeDetail(int $id, array $data): array
    {
        try {
            $result = $this->assessmentModel->updateGradingSchemeDetail($id, $data);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to update grading scheme detail'];
            }
            return ['success' => true, 'message' => 'Subject override updated successfully'];
        } catch (Exception $e) {
            $this->logger->error('Failed to update grading scheme detail: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteGradingSchemeDetail(int $id): array
    {
        try {
            $result = $this->assessmentModel->deleteGradingSchemeDetail($id);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to delete grading scheme detail'];
            }
            return ['success' => true, 'message' => 'Subject override deleted successfully'];
        } catch (Exception $e) {
            $this->logger->error('Failed to delete grading scheme detail: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ==============================================================
    // GRADE CALCULATION - SERVICE WRAPPERS
    // ==============================================================

    public function calculateGrade(float $score, int $schemeId, ?int $subjectId = null): array
    {
        try {
            $result = $this->assessmentModel->calculateGradeFromScore($score, $schemeId, $subjectId);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to calculate grade'];
            }
            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            $this->logger->error('Failed to calculate grade: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function previewGrade(float $score, int $schemeId): array
    {
        try {
            return $this->calculateGrade($score, $schemeId);
        } catch (Exception $e) {
            $this->logger->error('Failed to preview grade: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function calculateStudentGPA(int $studentId, int $termId): array
    {
        try {
            $result = $this->assessmentModel->calculateStudentGPA($studentId, $termId);
            if (!$result) {
                return ['success' => false, 'message' => 'Failed to calculate GPA'];
            }
            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            $this->logger->error('Failed to calculate student GPA: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ==============================================================
    // DEFAULT GRADING SCALES - UI FEATURE
    // ==============================================================

    /**
     * Get default grading scale for a specific type
     */
    public function getDefaultScaleByType(string $scaleType, int $schoolId = 1): array
    {
        try {
            $scale = $this->assessmentModel->getDefaultScaleByType($scaleType, $schoolId);
            if (!$scale) {
                return ['success' => false, 'message' => 'No default scale found for this type'];
            }
            return ['success' => true, 'data' => $scale];
        } catch (Exception $e) {
            $this->logger->error('Failed to get default scale by type: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get scale template for a type (for UI auto-fill)
     */
    public function getScaleTemplate(string $scaleType): array
    {
        return $this->assessmentModel->getScaleTemplate($scaleType);
    }

    /**
     * Get all scale templates (for UI)
     */
    public function getAllScaleTemplates(): array
    {
        return $this->assessmentModel->getAllScaleTemplates();
    }

    /**
     * Get scale with defaults for UI - auto-fills when type is selected
     */
    public function getScaleWithDefaults(string $scaleType, int $schoolId = 1): array
    {
        try {
            $template = $this->assessmentModel->getScaleTemplate($scaleType);
            $existingScales = $this->assessmentModel->getGradingScalesByType($scaleType, $schoolId);
            $defaultScale = $this->assessmentModel->getDefaultScaleByType($scaleType, $schoolId);
            
            return [
                'success' => true,
                'data' => [
                    'template' => $template,
                    'existing_scales' => $existingScales,
                    'default_scale' => $defaultScale,
                    'has_existing' => !empty($existingScales)
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to get scale with defaults: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Create default scales for a school (runs on first setup)
     */
    public function createDefaultScales(int $schoolId = 1): array
    {
        try {
            $results = $this->assessmentModel->createDefaultScales($schoolId);
            return [
                'success' => true,
                'message' => "Created {$results['success']} scales, {$results['failed']} failed",
                'data' => $results
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to create default scales: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ==============================================================
    // SIMPLIFIED GRADE LEVELS - SERVICE WRAPPERS
    // ==============================================================

    public function getGradeLevelCategories(): array
    {
        return $this->assessmentModel->getGradeLevelCategories();
    }

    public function getGradeLevelsByCategory(string $category): array
    {
        try {
            $levels = $this->assessmentModel->getGradeLevelsByCategory($category);
            return ['success' => true, 'data' => $levels];
        } catch (Exception $e) {
            $this->logger->error('Failed to get grade levels by category: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function getGradeLevelsGroupedByCategory(int $schoolId = 1): array
    {
        try {
            $grouped = $this->assessmentModel->getGradeLevelsGroupedByCategory($schoolId);
            return ['success' => true, 'data' => $grouped];
        } catch (Exception $e) {
            $this->logger->error('Failed to get grade levels grouped by category: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function getGradeLevelCategoryLabel(int $gradeLevelId): array
    {
        try {
            $label = $this->assessmentModel->getGradeLevelCategoryLabel($gradeLevelId);
            if (!$label) {
                return ['success' => false, 'message' => 'Category not found'];
            }
            return ['success' => true, 'data' => ['label' => $label]];
        } catch (Exception $e) {
            $this->logger->error('Failed to get grade level category label: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}