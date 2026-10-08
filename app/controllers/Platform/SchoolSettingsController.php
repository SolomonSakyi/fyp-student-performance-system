<?php

/**
 * SchoolSettingsController.php
 * API Controller for School Settings Management
 * 
 * @package EduTrack
 * @subpackage Controllers\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/services/School/SchoolConfigurationService.php';
require_once dirname(__DIR__, 2) . '/services/School/LevelNamingService.php';
require_once dirname(__DIR__, 2) . '/services/School/SubjectService.php';
require_once dirname(__DIR__, 2) . '/services/School/AssessmentConfigurationService.php';
require_once dirname(__DIR__, 2) . '/services/School/GradingConfigurationService.php';
require_once dirname(__DIR__, 2) . '/services/School/AggregationService.php';
require_once dirname(__DIR__, 2) . '/services/School/PromotionService.php';
require_once dirname(__DIR__, 2) . '/services/School/RemarkService.php';
require_once dirname(__DIR__, 2) . '/services/Tenant/TenantContext.php';
require_once dirname(__DIR__, 2) . '/services/Authorization/AuthorizationService.php';

class SchoolSettingsController
{
    private $context;
    private $auth;
    private $configService;
    private $levelService;
    private $subjectService;
    private $assessmentService;
    private $gradingService;
    private $aggregationService;
    private $promotionService;
    private $remarkService;

    public function __construct()
    {
        $this->context = TenantContext::getInstance();
        $this->auth = new AuthorizationService();
        $this->configService = new SchoolConfigurationService();
        $this->levelService = new LevelNamingService();
        $this->subjectService = new SubjectService();
        $this->assessmentService = new AssessmentConfigurationService();
        $this->gradingService = new GradingConfigurationService();
        $this->aggregationService = new AggregationService();
        $this->promotionService = new PromotionService();
        $this->remarkService = new RemarkService();
    }

    // =============================================
    // SCHOOL CONFIGURATION ENDPOINTS
    // =============================================

    /**
     * GET /api/schools/{schoolId}/settings
     * Get school configuration
     */
    public function getConfig($schoolId)
    {
        try {
            $config = $this->configService->getSchoolConfig($schoolId);
            $this->jsonResponse(['success' => true, 'data' => $config]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    /**
     * PUT /api/schools/{schoolId}/settings
     * Update school configuration
     */
    public function updateConfig($schoolId)
    {
        try {
            $data = $this->getRequestData();
            $result = $this->configService->updateSchoolConfig($schoolId, $data);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/schools/{schoolId}/settings/health
     * Get configuration health status
     */
    public function getConfigHealth($schoolId)
    {
        try {
            $health = $this->configService->getConfigHealth($schoolId);
            $this->jsonResponse(['success' => true, 'data' => $health]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    // =============================================
    // LEVEL MANAGEMENT ENDPOINTS
    // =============================================

    /**
     * GET /api/schools/{schoolId}/levels
     * Get all levels for a school
     */
    public function getLevels($schoolId)
    {
        try {
            $levels = $this->levelService->getSchoolLevels($schoolId);
            $stats = $this->levelService->getLevelStats($schoolId);
            $this->jsonResponse([
                'success' => true,
                'data' => $levels,
                'stats' => $stats
            ]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    /**
     * POST /api/schools/{schoolId}/levels
     * Add a new level
     */
    public function addLevel($schoolId)
    {
        try {
            $data = $this->getRequestData();
            $result = $this->levelService->addLevel($schoolId, $data);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/schools/{schoolId}/levels/{levelCode}/display-name
     * Update level display name
     */
    public function updateLevelDisplayName($schoolId, $levelCode)
    {
        try {
            $data = $this->getRequestData();
            $result = $this->levelService->updateLevelDisplayName($schoolId, $levelCode, $data);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/schools/{schoolId}/levels/{levelCode}
     * Remove a level
     */
    public function removeLevel($schoolId, $levelCode)
    {
        try {
            $result = $this->levelService->removeLevel($schoolId, $levelCode);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/schools/available-levels
     * Get available academic levels
     */
    public function getAvailableLevels()
    {
        try {
            $levels = $this->levelService->getAvailableLevels();
            $this->jsonResponse(['success' => true, 'data' => $levels]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =============================================
    // SUBJECT MANAGEMENT ENDPOINTS
    // =============================================

    /**
     * GET /api/schools/{schoolId}/subjects
     * Get all subjects for a school
     */
    public function getSubjects($schoolId)
    {
        try {
            $disciplineId = isset($_GET['discipline_id']) ? (int)$_GET['discipline_id'] : null;
            $subjects = $this->subjectService->getSchoolSubjects($schoolId, $disciplineId);
            $stats = $this->subjectService->getSubjectStats($schoolId);
            $disciplines = $this->subjectService->getDisciplines($schoolId);
            $this->jsonResponse([
                'success' => true,
                'data' => $subjects,
                'stats' => $stats,
                'disciplines' => $disciplines
            ]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    /**
     * GET /api/schools/{schoolId}/subjects/level/{levelId}
     * Get subjects by level
     */
    public function getSubjectsByLevel($schoolId, $levelId)
    {
        try {
            $subjects = $this->subjectService->getSubjectsByLevel($schoolId, $levelId);
            $this->jsonResponse(['success' => true, 'data' => $subjects]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    /**
     * GET /api/schools/{schoolId}/subjects/{subjectId}
     * Get a specific subject
     */
    public function getSubject($schoolId, $subjectId)
    {
        try {
            $subject = $this->subjectService->getSubject($subjectId);
            if (!$subject) {
                $this->jsonResponse(['success' => false, 'message' => 'Subject not found'], 404);
                return;
            }
            $this->jsonResponse(['success' => true, 'data' => $subject]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    /**
     * POST /api/schools/{schoolId}/subjects
     * Add a new subject
     */
    public function addSubject($schoolId)
    {
        try {
            $data = $this->getRequestData();
            $result = $this->subjectService->addSubject($schoolId, $data);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/schools/{schoolId}/subjects/{subjectId}
     * Update a subject
     */
    public function updateSubject($schoolId, $subjectId)
    {
        try {
            $data = $this->getRequestData();
            $result = $this->subjectService->updateSubject($subjectId, $data);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/schools/{schoolId}/subjects/{subjectId}
     * Delete a subject
     */
    public function deleteSubject($schoolId, $subjectId)
    {
        try {
            $result = $this->subjectService->deleteSubject($subjectId);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/schools/{schoolId}/subjects/{subjectId}/assign-level
     * Assign subject to a level
     */
    public function assignSubjectToLevel($schoolId, $subjectId)
    {
        try {
            $data = $this->getRequestData();
            if (empty($data['level_id'])) {
                $this->jsonResponse(['success' => false, 'message' => 'Level ID is required'], 400);
                return;
            }
            $result = $this->subjectService->assignSubjectToLevel($subjectId, $data['level_id'], $data);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/schools/{schoolId}/subjects/{subjectId}/remove-level/{levelId}
     * Remove subject from a level
     */
    public function removeSubjectFromLevel($schoolId, $subjectId, $levelId)
    {
        try {
            $result = $this->subjectService->removeSubjectFromLevel($subjectId, $levelId);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =============================================
    // DISCIPLINE ENDPOINTS
    // =============================================

    /**
     * GET /api/schools/{schoolId}/disciplines
     * Get disciplines for a school
     */
    public function getDisciplines($schoolId)
    {
        try {
            $disciplines = $this->subjectService->getDisciplines($schoolId);
            $this->jsonResponse(['success' => true, 'data' => $disciplines]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    /**
     * POST /api/schools/{schoolId}/disciplines
     * Add a discipline
     */
    public function addDiscipline($schoolId)
    {
        try {
            $data = $this->getRequestData();
            $result = $this->subjectService->addDiscipline($schoolId, $data);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =============================================
    // ASSESSMENT PROFILE ENDPOINTS
    // =============================================

    /**
     * GET /api/schools/{schoolId}/assessment/profiles
     * Get assessment profiles
     */
    public function getAssessmentProfiles($schoolId)
    {
        try {
            $levelId = isset($_GET['level_id']) ? (int)$_GET['level_id'] : null;
            $profiles = $this->assessmentService->getAssessmentProfiles($schoolId, $levelId);
            $stats = $this->assessmentService->getAssessmentStats($schoolId);
            $this->jsonResponse([
                'success' => true,
                'data' => $profiles,
                'stats' => $stats
            ]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    /**
     * GET /api/schools/{schoolId}/assessment/profiles/{profileId}
     * Get a specific assessment profile with components
     */
    public function getAssessmentProfile($schoolId, $profileId)
    {
        try {
            $profile = $this->assessmentService->getAssessmentProfile($profileId);
            if (!$profile) {
                $this->jsonResponse(['success' => false, 'message' => 'Profile not found'], 404);
                return;
            }
            $this->jsonResponse(['success' => true, 'data' => $profile]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    /**
     * POST /api/schools/{schoolId}/assessment/profiles
     * Create assessment profile
     */
    public function createAssessmentProfile($schoolId)
    {
        try {
            $data = $this->getRequestData();
            $result = $this->assessmentService->createAssessmentProfile($schoolId, $data);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/schools/{schoolId}/assessment/profiles/{profileId}
     * Update assessment profile
     */
    public function updateAssessmentProfile($schoolId, $profileId)
    {
        try {
            $data = $this->getRequestData();
            $result = $this->assessmentService->updateAssessmentProfile($profileId, $data);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/schools/{schoolId}/assessment/profiles/{profileId}/validate
     * Validate profile weights
     */
    public function validateProfileWeights($schoolId, $profileId)
    {
        try {
            $result = $this->assessmentService->validateProfileWeights($profileId);
            $this->jsonResponse(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =============================================
    // ASSESSMENT COMPONENT ENDPOINTS
    // =============================================

    /**
     * GET /api/schools/{schoolId}/assessment/components
     * Get assessment components
     */
    public function getAssessmentComponents($schoolId)
    {
        try {
            $components = $this->assessmentService->getComponents($schoolId);
            $this->jsonResponse(['success' => true, 'data' => $components]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    /**
     * POST /api/schools/{schoolId}/assessment/components
     * Create assessment component
     */
    public function createAssessmentComponent($schoolId)
    {
        try {
            $data = $this->getRequestData();
            $result = $this->assessmentService->createComponent($schoolId, $data);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/schools/{schoolId}/assessment/profiles/{profileId}/components
     * Add component to profile
     */
    public function addComponentToProfile($schoolId, $profileId)
    {
        try {
            $data = $this->getRequestData();
            $result = $this->assessmentService->addComponentToProfile($profileId, $data);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/schools/{schoolId}/assessment/profiles/{profileId}/components/{componentId}
     * Remove component from profile
     */
    public function removeComponentFromProfile($schoolId, $profileId, $componentId)
    {
        try {
            $result = $this->assessmentService->removeComponentFromProfile($profileId, $componentId);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =============================================
    // GRADING SYSTEM ENDPOINTS
    // =============================================

    /**
     * GET /api/schools/{schoolId}/grading/systems
     * Get grading systems
     */
    public function getGradingSystems($schoolId)
    {
        try {
            $levelId = isset($_GET['level_id']) ? (int)$_GET['level_id'] : null;
            $systems = $this->gradingService->getGradingSystems($schoolId, $levelId);
            $stats = $this->gradingService->getGradingStats($schoolId);
            $this->jsonResponse([
                'success' => true,
                'data' => $systems,
                'stats' => $stats
            ]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    /**
     * GET /api/schools/{schoolId}/grading/systems/{systemId}
     * Get a specific grading system with scales
     */
    public function getGradingSystem($schoolId, $systemId)
    {
        try {
            $system = $this->gradingService->getGradingSystem($systemId);
            if (!$system) {
                $this->jsonResponse(['success' => false, 'message' => 'Grading system not found'], 404);
                return;
            }
            $this->jsonResponse(['success' => true, 'data' => $system]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    /**
     * POST /api/schools/{schoolId}/grading/systems
     * Create grading system
     */
    public function createGradingSystem($schoolId)
    {
        try {
            $data = $this->getRequestData();
            $result = $this->gradingService->createGradingSystem($schoolId, $data);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/schools/{schoolId}/grading/systems/{systemId}/scales
     * Add grade scale to system
     */
    public function addGradeScale($schoolId, $systemId)
    {
        try {
            $data = $this->getRequestData();
            $result = $this->gradingService->addGradeScale($systemId, $data);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =============================================
    // AGGREGATION RULE ENDPOINTS
    // =============================================

    /**
     * GET /api/schools/{schoolId}/aggregation/rules
     * Get aggregation rules
     */
    public function getAggregationRules($schoolId)
    {
        try {
            $levelId = isset($_GET['level_id']) ? (int)$_GET['level_id'] : null;
            $rules = $this->aggregationService->getAggregationRules($schoolId, $levelId);
            $stats = $this->aggregationService->getAggregationStats($schoolId);
            $this->jsonResponse([
                'success' => true,
                'data' => $rules,
                'stats' => $stats
            ]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    /**
     * POST /api/schools/{schoolId}/aggregation/rules
     * Create aggregation rule
     */
    public function createAggregationRule($schoolId)
    {
        try {
            $data = $this->getRequestData();
            $result = $this->aggregationService->createAggregationRule($schoolId, $data);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =============================================
    // PROMOTION RULE ENDPOINTS
    // =============================================

    /**
     * GET /api/schools/{schoolId}/promotion/rules
     * Get promotion rules
     */
    public function getPromotionRules($schoolId)
    {
        try {
            $levelId = isset($_GET['level_id']) ? (int)$_GET['level_id'] : null;
            $rules = $this->promotionService->getPromotionRules($schoolId, $levelId);
            $stats = $this->promotionService->getPromotionStats($schoolId);
            $this->jsonResponse([
                'success' => true,
                'data' => $rules,
                'stats' => $stats
            ]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    /**
     * POST /api/schools/{schoolId}/promotion/rules
     * Create promotion rule
     */
    public function createPromotionRule($schoolId)
    {
        try {
            $data = $this->getRequestData();
            $result = $this->promotionService->createPromotionRule($schoolId, $data);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/schools/{schoolId}/promotion/evaluate
     * Evaluate student promotion
     */
    public function evaluatePromotion($schoolId)
    {
        try {
            $data = $this->getRequestData();
            if (empty($data['student_id']) || empty($data['rule_id'])) {
                $this->jsonResponse(['success' => false, 'message' => 'Student ID and Rule ID are required'], 400);
                return;
            }
            $result = $this->promotionService->evaluateStudentPromotion($data['student_id'], $data['rule_id']);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =============================================
    // REMARK RULE ENDPOINTS
    // =============================================

    /**
     * GET /api/schools/{schoolId}/remarks/rules
     * Get remark rules
     */
    public function getRemarkRules($schoolId)
    {
        try {
            $levelId = isset($_GET['level_id']) ? (int)$_GET['level_id'] : null;
            $conditionType = isset($_GET['condition_type']) ? $_GET['condition_type'] : null;

            if ($conditionType) {
                $rules = $this->remarkService->getRemarkRulesByType($schoolId, $conditionType);
            } else {
                $rules = $this->remarkService->getRemarkRules($schoolId, $levelId);
            }

            $stats = $this->remarkService->getRemarkStats($schoolId);
            $this->jsonResponse([
                'success' => true,
                'data' => $rules,
                'stats' => $stats
            ]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    /**
     * POST /api/schools/{schoolId}/remarks/rules
     * Create remark rule
     */
    public function createRemarkRule($schoolId)
    {
        try {
            $data = $this->getRequestData();
            $result = $this->remarkService->createRemarkRule($schoolId, $data);
            $this->jsonResponse($result);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/schools/{schoolId}/remarks/generate
     * Generate remark based on score
     */
    public function generateRemark($schoolId)
    {
        try {
            $data = $this->getRequestData();
            if (!isset($data['score'])) {
                $this->jsonResponse(['success' => false, 'message' => 'Score is required'], 400);
                return;
            }

            $levelId = isset($data['level_id']) ? (int)$data['level_id'] : null;
            $context = isset($data['context']) ? $data['context'] : 'general';

            $result = $this->remarkService->generateContextualRemark(
                $schoolId,
                (float)$data['score'],
                $context,
                $levelId
            );

            $this->jsonResponse(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =============================================
    // HELPER METHODS
    // =============================================

    /**
     * Get request data from JSON input
     */
    private function getRequestData(): array
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return [];
        }
        $data = json_decode($input, true);
        return $data ?? [];
    }

    /**
     * Send JSON response
     */
    private function jsonResponse($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Route dispatcher for this controller
     */
    public function dispatch($method, $path, $params = [])
    {
        // Remove leading/trailing slashes and split
        $path = trim($path, '/');
        $parts = explode('/', $path);

        // Extract school_id and additional parameters
        $schoolId = isset($parts[0]) && is_numeric($parts[0]) ? (int)$parts[0] : null;
        $action = $parts[1] ?? '';
        $subAction = $parts[2] ?? '';
        $id = $parts[3] ?? null;

        // Build the route key for matching
        $routeKey = $method . ':' . $action . '/' . $subAction;

        // Route mapping with parameters
        $routes = [
            // Configuration routes
            'GET:settings' => [$this, 'getConfig'],
            'PUT:settings' => [$this, 'updateConfig'],
            'GET:settings/health' => [$this, 'getConfigHealth'],

            // Level routes
            'GET:levels' => [$this, 'getLevels'],
            'POST:levels' => [$this, 'addLevel'],
            'GET:available-levels' => [$this, 'getAvailableLevels'],

            // Subject routes
            'GET:subjects' => [$this, 'getSubjects'],
            'POST:subjects' => [$this, 'addSubject'],
            'GET:disciplines' => [$this, 'getDisciplines'],
            'POST:disciplines' => [$this, 'addDiscipline'],

            // Assessment routes
            'GET:assessment/profiles' => [$this, 'getAssessmentProfiles'],
            'POST:assessment/profiles' => [$this, 'createAssessmentProfile'],
            'GET:assessment/components' => [$this, 'getAssessmentComponents'],
            'POST:assessment/components' => [$this, 'createAssessmentComponent'],

            // Grading routes
            'GET:grading/systems' => [$this, 'getGradingSystems'],
            'POST:grading/systems' => [$this, 'createGradingSystem'],

            // Aggregation routes
            'GET:aggregation/rules' => [$this, 'getAggregationRules'],
            'POST:aggregation/rules' => [$this, 'createAggregationRule'],

            // Promotion routes
            'GET:promotion/rules' => [$this, 'getPromotionRules'],
            'POST:promotion/rules' => [$this, 'createPromotionRule'],
            'POST:promotion/evaluate' => [$this, 'evaluatePromotion'],

            // Remark routes
            'GET:remarks/rules' => [$this, 'getRemarkRules'],
            'POST:remarks/rules' => [$this, 'createRemarkRule'],
            'POST:remarks/generate' => [$this, 'generateRemark'],
        ];

        // Check for direct route match
        $routeKey = $method . ':' . $action . '/' . $subAction;
        if (isset($routes[$routeKey])) {
            $callback = $routes[$routeKey];
            if ($schoolId) {
                $callback($schoolId);
            } else {
                $callback();
            }
            return;
        }

        // Check for routes with ID parameter
        if ($action === 'subjects' && $subAction === '' && $id && is_numeric($id)) {
            // GET /schools/{schoolId}/subjects/{subjectId}
            if ($method === 'GET') {
                $this->getSubject($schoolId, $id);
                return;
            }
            // PUT /schools/{schoolId}/subjects/{subjectId}
            if ($method === 'PUT') {
                $this->updateSubject($schoolId, $id);
                return;
            }
            // DELETE /schools/{schoolId}/subjects/{subjectId}
            if ($method === 'DELETE') {
                $this->deleteSubject($schoolId, $id);
                return;
            }
        }

        // Check for level-specific subject routes
        if ($action === 'subjects' && $subAction === 'level' && $id) {
            // GET /schools/{schoolId}/subjects/level/{levelId}
            if ($method === 'GET') {
                $this->getSubjectsByLevel($schoolId, $id);
                return;
            }
        }

        // Check for assessment profile routes
        if ($action === 'assessment' && $subAction === 'profiles' && $id && is_numeric($id)) {
            // GET /schools/{schoolId}/assessment/profiles/{profileId}
            if ($method === 'GET') {
                $this->getAssessmentProfile($schoolId, $id);
                return;
            }
            // PUT /schools/{schoolId}/assessment/profiles/{profileId}
            if ($method === 'PUT') {
                $this->updateAssessmentProfile($schoolId, $id);
                return;
            }
        }

        // Check for grading system routes
        if ($action === 'grading' && $subAction === 'systems' && $id && is_numeric($id)) {
            // GET /schools/{schoolId}/grading/systems/{systemId}
            if ($method === 'GET') {
                $this->getGradingSystem($schoolId, $id);
                return;
            }
        }

        // Check for level display name routes
        if ($action === 'levels' && $subAction === '' && $id) {
            // PUT /schools/{schoolId}/levels/{levelCode}/display-name
            if ($method === 'PUT' && isset($parts[4]) && $parts[4] === 'display-name') {
                $this->updateLevelDisplayName($schoolId, $id);
                return;
            }
            // DELETE /schools/{schoolId}/levels/{levelCode}
            if ($method === 'DELETE') {
                $this->removeLevel($schoolId, $id);
                return;
            }
        }

        // If no route matched, return 404
        $this->jsonResponse([
            'success' => false,
            'message' => 'Endpoint not found',
            'path' => $path,
            'method' => $method
        ], 404);
    }
}
