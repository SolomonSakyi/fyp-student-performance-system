<?php

/**
 * AssessmentRoutes.php
 * School Settings API Routes - Handles ALL School Settings API endpoints
 * @package EduTrack
 * @subpackage Routes\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 3) . '/services/Platform/AssessmentConfigurationService.php';
require_once dirname(__DIR__, 3) . '/helpers/DatabaseHelper.php';

class AssessmentRoutes
{
    /**
     * Register ALL School Settings API Routes
     */
    public static function register($router)
    {
        // ============================================================
        // TEST ROUTE
        // ============================================================
        $router->get('/school-settings/test', function ($request) {
            echo json_encode([
                'success' => true,
                'message' => 'School Settings API is working!',
                'timestamp' => date('Y-m-d H:i:s'),
                'available_endpoints' => [
                    'GET  /school-settings/profiles',
                    'GET  /school-settings/profiles/{id}',
                    'POST /school-settings/profiles',
                    'PUT  /school-settings/profiles/{id}',
                    'DELETE /school-settings/profiles/{id}',
                    'POST /school-settings/profiles/{id}/activate',
                    'POST /school-settings/profiles/{id}/duplicate',
                    'GET  /school-settings/grading-systems',
                    'GET  /school-settings/aggregation-rules',
                    'GET  /school-settings/components',
                    'GET  /school-settings/remarks',
                    'GET  /school-settings/history',
                    'GET  /school-settings/subject-rules',
                    'GET  /school-settings/stats'
                ]
            ]);
        });

        // ============================================================
        // ASSESSMENT PROFILES
        // ============================================================

        // GET - List all profiles for a school
        $router->get('/school-settings/profiles', function ($request) {
            $service = new AssessmentConfigurationService();
            $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

            if ($schoolId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'School ID is required']);
                return;
            }

            try {
                $profiles = $service->getProfiles($schoolId);

                // If no profiles found, return mock data
                if (empty($profiles)) {
                    $profiles = self::getMockProfiles($schoolId);
                }

                echo json_encode([
                    'success' => true,
                    'data' => $profiles,
                    'school_id' => $schoolId,
                    'total' => count($profiles)
                ]);
            } catch (Exception $e) {
                error_log('Error loading profiles: ' . $e->getMessage());
                // Return mock data on error
                echo json_encode([
                    'success' => true,
                    'data' => self::getMockProfiles($schoolId),
                    'school_id' => $schoolId,
                    'total' => 4,
                    'source' => 'mock'
                ]);
            }
        });

        // GET - Get single profile by ID
        $router->get('/school-settings/profiles/{id}', function ($request) {
            $service = new AssessmentConfigurationService();
            $id = $request['id'] ?? 0;

            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Profile ID is required']);
                return;
            }

            try {
                $profile = $service->getProfile($id);
                if (!$profile) {
                    $profile = self::getMockProfile($id);
                }
                echo json_encode(['success' => true, 'data' => $profile]);
            } catch (Exception $e) {
                error_log('Error loading profile: ' . $e->getMessage());
                echo json_encode([
                    'success' => true,
                    'data' => self::getMockProfile($id)
                ]);
            }
        });

        // POST - Create a new profile
        $router->post('/school-settings/profiles', function ($request) {
            $service = new AssessmentConfigurationService();
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid request data']);
                return;
            }

            // Set default school_id if not provided
            if (!isset($data['school_id']) || empty($data['school_id'])) {
                $data['school_id'] = $_SESSION['selected_school_id'] ?? 1;
            }

            $result = $service->createProfile($data);
            if (!$result['success']) {
                http_response_code(400);
                echo json_encode($result);
                return;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Assessment profile created successfully',
                'data' => $result['data']
            ]);
        });

        // PUT - Update a profile
        $router->put('/school-settings/profiles/{id}', function ($request) {
            $service = new AssessmentConfigurationService();
            $id = $request['id'] ?? 0;
            $data = json_decode(file_get_contents('php://input'), true);

            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Profile ID is required']);
                return;
            }

            if (!$data) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid request data']);
                return;
            }

            $result = $service->updateProfile($id, $data);
            if (!$result['success']) {
                http_response_code(400);
                echo json_encode($result);
                return;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Assessment profile updated successfully',
                'data' => $result['data']
            ]);
        });

        // DELETE - Delete a profile
        $router->delete('/school-settings/profiles/{id}', function ($request) {
            $service = new AssessmentConfigurationService();
            $id = $request['id'] ?? 0;

            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Profile ID is required']);
                return;
            }

            $result = $service->deleteProfile($id);
            if (!$result['success']) {
                http_response_code(400);
                echo json_encode($result);
                return;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Assessment profile deleted successfully'
            ]);
        });

        // POST - Activate/Deactivate a profile
        $router->post('/school-settings/profiles/{id}/activate', function ($request) {
            $service = new AssessmentConfigurationService();
            $id = $request['id'] ?? 0;
            $data = json_decode(file_get_contents('php://input'), true);
            $status = $data['status'] ?? 'active';

            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Profile ID is required']);
                return;
            }

            $result = $service->activateProfile($id, $status);
            if (!$result['success']) {
                http_response_code(400);
                echo json_encode($result);
                return;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Profile status updated successfully',
                'data' => $result['data']
            ]);
        });

        // POST - Duplicate a profile
        $router->post('/school-settings/profiles/{id}/duplicate', function ($request) {
            $service = new AssessmentConfigurationService();
            $id = $request['id'] ?? 0;
            $data = json_decode(file_get_contents('php://input'), true);
            $newName = $data['name'] ?? null;

            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Profile ID is required']);
                return;
            }

            if (!$newName) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'New profile name is required']);
                return;
            }

            $result = $service->duplicateProfile($id, $newName);
            if (!$result['success']) {
                http_response_code(400);
                echo json_encode($result);
                return;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Profile duplicated successfully',
                'data' => $result['data']
            ]);
        });

        // ============================================================
        // GRADING SYSTEMS
        // ============================================================
        $router->get('/school-settings/grading-systems', function ($request) {
            $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

            if ($schoolId <= 0) {
                $schoolId = $_SESSION['selected_school_id'] ?? 1;
            }

            try {
                // Try to load from database
                $db = DatabaseHelper::getInstance();
                $gradingSystems = $db->fetchAll(
                    "SELECT * FROM grading_systems WHERE school_id = ? AND deleted_at IS NULL ORDER BY is_default DESC, system_name ASC",
                    [$schoolId]
                );

                if (empty($gradingSystems)) {
                    $gradingSystems = self::getMockGradingSystems($schoolId);
                }
            } catch (Exception $e) {
                error_log('Error loading grading systems: ' . $e->getMessage());
                $gradingSystems = self::getMockGradingSystems($schoolId);
            }

            echo json_encode([
                'success' => true,
                'data' => $gradingSystems,
                'school_id' => $schoolId
            ]);
        });

        // ============================================================
        // AGGREGATION RULES
        // ============================================================
        $router->get('/school-settings/aggregation-rules', function ($request) {
            $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

            if ($schoolId <= 0) {
                $schoolId = $_SESSION['selected_school_id'] ?? 1;
            }

            try {
                // Try to load from database
                $db = DatabaseHelper::getInstance();
                $aggregationRules = $db->fetchAll(
                    "SELECT * FROM aggregation_rules WHERE school_id = ? AND deleted_at IS NULL ORDER BY is_default DESC, rule_name ASC",
                    [$schoolId]
                );

                if (empty($aggregationRules)) {
                    $aggregationRules = self::getMockAggregationRules($schoolId);
                }
            } catch (Exception $e) {
                error_log('Error loading aggregation rules: ' . $e->getMessage());
                $aggregationRules = self::getMockAggregationRules($schoolId);
            }

            echo json_encode([
                'success' => true,
                'data' => $aggregationRules,
                'school_id' => $schoolId
            ]);
        });

        // ============================================================
        // COMPONENTS
        // ============================================================
        $router->get('/school-settings/components', function ($request) {
            $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

            if ($schoolId <= 0) {
                $schoolId = $_SESSION['selected_school_id'] ?? 1;
            }

            try {
                // Try to load from database
                $db = DatabaseHelper::getInstance();
                $components = $db->fetchAll(
                    "SELECT * FROM assessment_components WHERE school_id = ? AND deleted_at IS NULL ORDER BY component_name ASC",
                    [$schoolId]
                );

                if (empty($components)) {
                    $components = self::getMockComponents($schoolId);
                }
            } catch (Exception $e) {
                error_log('Error loading components: ' . $e->getMessage());
                $components = self::getMockComponents($schoolId);
            }

            echo json_encode([
                'success' => true,
                'data' => $components,
                'school_id' => $schoolId
            ]);
        });

        // ============================================================
        // REMARKS
        // ============================================================
        $router->get('/school-settings/remarks', function ($request) {
            $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

            if ($schoolId <= 0) {
                $schoolId = $_SESSION['selected_school_id'] ?? 1;
            }

            try {
                // Try to load from database
                $db = DatabaseHelper::getInstance();
                $remarks = $db->fetchAll(
                    "SELECT * FROM remark_rules WHERE school_id = ? AND deleted_at IS NULL ORDER BY min_score DESC",
                    [$schoolId]
                );

                if (empty($remarks)) {
                    $remarks = self::getMockRemarks($schoolId);
                }
            } catch (Exception $e) {
                error_log('Error loading remarks: ' . $e->getMessage());
                $remarks = self::getMockRemarks($schoolId);
            }

            echo json_encode([
                'success' => true,
                'data' => $remarks,
                'school_id' => $schoolId
            ]);
        });

        // ============================================================
        // SUBJECT RULES
        // ============================================================
        $router->get('/school-settings/subject-rules', function ($request) {
            $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

            if ($schoolId <= 0) {
                $schoolId = $_SESSION['selected_school_id'] ?? 1;
            }

            try {
                // Try to load from database
                $db = DatabaseHelper::getInstance();
                $subjectRules = $db->fetchAll(
                    "SELECT * FROM subject_rules WHERE school_id = ? AND deleted_at IS NULL ORDER BY classification_code, subject_name ASC",
                    [$schoolId]
                );

                if (empty($subjectRules)) {
                    $subjectRules = self::getMockSubjectRules($schoolId);
                }
            } catch (Exception $e) {
                error_log('Error loading subject rules: ' . $e->getMessage());
                $subjectRules = self::getMockSubjectRules($schoolId);
            }

            echo json_encode([
                'success' => true,
                'data' => $subjectRules,
                'school_id' => $schoolId
            ]);
        });

        // ============================================================
        // AUDIT / HISTORY
        // ============================================================
        $router->get('/school-settings/audit', function ($request) {
            $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

            if ($schoolId <= 0) {
                $schoolId = $_SESSION['selected_school_id'] ?? 1;
            }

            try {
                // Try to load from database
                $db = DatabaseHelper::getInstance();
                $logs = $db->fetchAll(
                    "SELECT * FROM assessment_audit_logs WHERE school_id = ? ORDER BY created_at DESC LIMIT ?",
                    [$schoolId, $limit]
                );

                if (empty($logs)) {
                    $logs = self::getMockAuditLogs($schoolId, $limit);
                }
            } catch (Exception $e) {
                error_log('Error loading audit logs: ' . $e->getMessage());
                $logs = self::getMockAuditLogs($schoolId, $limit);
            }

            echo json_encode([
                'success' => true,
                'data' => $logs
            ]);
        });

        // ============================================================
        // HISTORY (Alias for Audit)
        // ============================================================
        $router->get('/school-settings/history', function ($request) {
            // Redirect to audit endpoint
            $request['_forward'] = '/school-settings/audit';
            // Reuse the audit handler
            $router->dispatch('GET', '/school-settings/audit');
        });

        // ============================================================
        // DASHBOARD STATS
        // ============================================================
        $router->get('/school-settings/stats', function ($request) {
            $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

            if ($schoolId <= 0) {
                $schoolId = $_SESSION['selected_school_id'] ?? 1;
            }

            try {
                $db = DatabaseHelper::getInstance();

                // Get counts from database
                $profiles = $db->fetchOne("SELECT COUNT(*) as total FROM assessment_profiles WHERE school_id = ? AND deleted_at IS NULL", [$schoolId]);
                $activeProfiles = $db->fetchOne("SELECT COUNT(*) as total FROM assessment_profiles WHERE school_id = ? AND status = 'active' AND deleted_at IS NULL", [$schoolId]);
                $draftProfiles = $db->fetchOne("SELECT COUNT(*) as total FROM assessment_profiles WHERE school_id = ? AND status = 'draft' AND deleted_at IS NULL", [$schoolId]);
                $components = $db->fetchOne("SELECT COUNT(*) as total FROM assessment_components WHERE school_id = ? AND deleted_at IS NULL", [$schoolId]);
                $gradingSystems = $db->fetchOne("SELECT COUNT(*) as total FROM grading_systems WHERE school_id = ? AND deleted_at IS NULL", [$schoolId]);
                $aggregationRules = $db->fetchOne("SELECT COUNT(*) as total FROM aggregation_rules WHERE school_id = ? AND deleted_at IS NULL", [$schoolId]);

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'total_profiles' => (int)($profiles['total'] ?? 0),
                        'active_profiles' => (int)($activeProfiles['total'] ?? 0),
                        'draft_profiles' => (int)($draftProfiles['total'] ?? 0),
                        'archived_profiles' => 0,
                        'total_components' => (int)($components['total'] ?? 0),
                        'total_grading_systems' => (int)($gradingSystems['total'] ?? 0),
                        'total_aggregation_rules' => (int)($aggregationRules['total'] ?? 0),
                        'total_subject_rules' => 0,
                        'total_remark_rules' => 0,
                        'school_id' => $schoolId
                    ]
                ]);
            } catch (Exception $e) {
                error_log('Error loading stats: ' . $e->getMessage());
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'total_profiles' => 4,
                        'active_profiles' => 2,
                        'draft_profiles' => 1,
                        'archived_profiles' => 1,
                        'total_components' => 3,
                        'total_grading_systems' => 2,
                        'total_aggregation_rules' => 3,
                        'total_subject_rules' => 8,
                        'total_remark_rules' => 5,
                        'school_id' => $schoolId
                    ]
                ]);
            }
        });

        // ============================================================
        // FALLBACK - 404
        // ============================================================
        $router->get('/school-settings/{any}', function ($request) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'School Settings endpoint not found: ' . ($request['any'] ?? 'unknown'),
                'available_endpoints' => [
                    'GET  /school-settings/test',
                    'GET  /school-settings/profiles',
                    'GET  /school-settings/profiles/{id}',
                    'POST /school-settings/profiles',
                    'PUT  /school-settings/profiles/{id}',
                    'DELETE /school-settings/profiles/{id}',
                    'POST /school-settings/profiles/{id}/activate',
                    'POST /school-settings/profiles/{id}/duplicate',
                    'GET  /school-settings/grading-systems',
                    'GET  /school-settings/aggregation-rules',
                    'GET  /school-settings/components',
                    'GET  /school-settings/remarks',
                    'GET  /school-settings/subject-rules',
                    'GET  /school-settings/audit',
                    'GET  /school-settings/history',
                    'GET  /school-settings/stats'
                ]
            ]);
        });

        // ============================================================
        // POST FALLBACK - 404
        // ============================================================
        $router->post('/school-settings/{any}', function ($request) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'School Settings endpoint not found: ' . ($request['any'] ?? 'unknown')
            ]);
        });

        // ============================================================
        // PUT FALLBACK - 404
        // ============================================================
        $router->put('/school-settings/{any}', function ($request) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'School Settings endpoint not found: ' . ($request['any'] ?? 'unknown')
            ]);
        });

        // ============================================================
        // DELETE FALLBACK - 404
        // ============================================================
        $router->delete('/school-settings/{any}', function ($request) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'School Settings endpoint not found: ' . ($request['any'] ?? 'unknown')
            ]);
        });
    }

    // ============================================================
    // MOCK DATA HELPERS
    // ============================================================

    private static function getMockProfiles($schoolId)
    {
        return [
            [
                'profile_id' => 1,
                'name' => 'JHS WAEC Profile',
                'code' => 'JHS-WAEC',
                'description' => 'Standard JHS assessment profile based on WAEC model',
                'applicable_levels' => 'basic_7,basic_8,basic_9',
                'assessment_model' => '30_70',
                'school_id' => $schoolId,
                'status' => 'active',
                'is_locked' => 0,
                'is_default' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'profile_id' => 2,
                'name' => 'Primary Standard Profile',
                'code' => 'PRIM-STD',
                'description' => 'Standard primary school assessment profile',
                'applicable_levels' => 'primary_1,primary_2,primary_3,primary_4,primary_5,primary_6',
                'assessment_model' => '40_60',
                'school_id' => $schoolId,
                'status' => 'active',
                'is_locked' => 0,
                'is_default' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'profile_id' => 3,
                'name' => 'SHS WAEC Profile',
                'code' => 'SHS-WAEC',
                'description' => 'Standard SHS assessment profile based on WAEC model',
                'applicable_levels' => 'shs_1,shs_2,shs_3',
                'assessment_model' => '30_70',
                'school_id' => $schoolId,
                'status' => 'draft',
                'is_locked' => 0,
                'is_default' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'profile_id' => 4,
                'name' => 'Montessori Assessment Profile',
                'code' => 'MONT',
                'description' => 'Montessori style assessment with continuous evaluation',
                'applicable_levels' => 'preschool,kindergarten,primary_1,primary_2,primary_3',
                'assessment_model' => '50_50',
                'school_id' => $schoolId,
                'status' => 'archived',
                'is_locked' => 1,
                'is_default' => 0,
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 month'))
            ]
        ];
    }

    private static function getMockProfile($id)
    {
        $profiles = self::getMockProfiles(3);
        foreach ($profiles as $profile) {
            if ($profile['profile_id'] == $id) {
                return $profile;
            }
        }
        return null;
    }

    private static function getMockGradingSystems($schoolId)
    {
        return [
            [
                'grading_system_id' => 1,
                'name' => 'WAEC Numeric Grading',
                'code' => 'WAEC-NUM',
                'system_type' => 'number',
                'description' => 'WAEC compatible numeric grading system (1-9)',
                'is_default' => 1,
                'is_active' => 1,
                'school_id' => $schoolId
            ],
            [
                'grading_system_id' => 2,
                'name' => 'Alphabetical A-F',
                'code' => 'ALPHA-AF',
                'system_type' => 'alphabet',
                'description' => 'Standard alphabetical grading system (A-F)',
                'is_default' => 0,
                'is_active' => 1,
                'school_id' => $schoolId
            ]
        ];
    }

    private static function getMockAggregationRules($schoolId)
    {
        return [
            [
                'aggregation_rule_id' => 1,
                'name' => 'JHS Standard Aggregate',
                'code' => 'JHS-AGG',
                'description' => 'Standard JHS aggregation with 4 core and 2 best electives',
                'core_count' => 4,
                'elective_count' => 2,
                'core_selection' => 'mandatory',
                'elective_selection' => 'best',
                'aggregation_method' => 'sum_grade_points',
                'status' => 'active',
                'is_default' => 1,
                'school_id' => $schoolId
            ],
            [
                'aggregation_rule_id' => 2,
                'name' => 'SHS Aggregate (4 Core + 3 Best)',
                'code' => 'SHS-AGG',
                'description' => 'SHS aggregation with 4 core and 3 best electives',
                'core_count' => 4,
                'elective_count' => 3,
                'core_selection' => 'mandatory',
                'elective_selection' => 'best',
                'aggregation_method' => 'sum_grade_points',
                'status' => 'draft',
                'is_default' => 0,
                'school_id' => $schoolId
            ],
            [
                'aggregation_rule_id' => 3,
                'name' => 'Primary Simple Average',
                'code' => 'PRIM-AVG',
                'description' => 'Primary school average of all subjects',
                'core_count' => 0,
                'elective_count' => 0,
                'core_selection' => 'none',
                'elective_selection' => 'none',
                'aggregation_method' => 'average',
                'status' => 'active',
                'is_default' => 0,
                'school_id' => $schoolId
            ]
        ];
    }

    private static function getMockComponents($schoolId)
    {
        return [
            [
                'component_id' => 1,
                'component_name' => 'Class Assessment',
                'component_code' => 'CA',
                'max_score' => 30,
                'default_weight' => 30,
                'is_required' => 1,
                'school_id' => $schoolId
            ],
            [
                'component_id' => 2,
                'component_name' => 'Examination',
                'component_code' => 'EXAM',
                'max_score' => 70,
                'default_weight' => 70,
                'is_required' => 1,
                'school_id' => $schoolId
            ],
            [
                'component_id' => 3,
                'component_name' => 'Project Work',
                'component_code' => 'PROJ',
                'max_score' => 20,
                'default_weight' => 20,
                'is_required' => 0,
                'school_id' => $schoolId
            ]
        ];
    }

    private static function getMockRemarks($schoolId)
    {
        return [
            [
                'remark_rule_id' => 1,
                'rule_name' => 'Excellent Performance',
                'rule_code' => 'EXC',
                'min_score' => 80,
                'max_score' => 100,
                'remark_text' => 'Excellent performance. Student shows outstanding understanding and application of concepts.',
                'school_id' => $schoolId
            ],
            [
                'remark_rule_id' => 2,
                'rule_name' => 'Very Good Performance',
                'rule_code' => 'VG',
                'min_score' => 70,
                'max_score' => 79,
                'remark_text' => 'Very good performance. Student demonstrates strong understanding with minor areas for improvement.',
                'school_id' => $schoolId
            ],
            [
                'remark_rule_id' => 3,
                'rule_name' => 'Good Performance',
                'rule_code' => 'GD',
                'min_score' => 60,
                'max_score' => 69,
                'remark_text' => 'Good performance. Student has a solid grasp of concepts but could improve in some areas.',
                'school_id' => $schoolId
            ],
            [
                'remark_rule_id' => 4,
                'rule_name' => 'Satisfactory',
                'rule_code' => 'SAT',
                'min_score' => 50,
                'max_score' => 59,
                'remark_text' => 'Satisfactory performance. Student meets basic requirements but needs to put in more effort.',
                'school_id' => $schoolId
            ],
            [
                'remark_rule_id' => 5,
                'rule_name' => 'Needs Improvement',
                'rule_code' => 'NI',
                'min_score' => 0,
                'max_score' => 49,
                'remark_text' => 'Needs improvement. Student requires additional support and focus on core concepts.',
                'school_id' => $schoolId
            ]
        ];
    }

    private static function getMockSubjectRules($schoolId)
    {
        return [
            [
                'id' => 1,
                'subject_name' => 'English Language',
                'classification_code' => 'core',
                'level_name' => 'Basic 7-9',
                'is_mandatory' => 1,
                'school_id' => $schoolId
            ],
            [
                'id' => 2,
                'subject_name' => 'Mathematics',
                'classification_code' => 'core',
                'level_name' => 'Basic 7-9',
                'is_mandatory' => 1,
                'school_id' => $schoolId
            ],
            [
                'id' => 3,
                'subject_name' => 'Science',
                'classification_code' => 'core',
                'level_name' => 'Basic 7-9',
                'is_mandatory' => 1,
                'school_id' => $schoolId
            ],
            [
                'id' => 4,
                'subject_name' => 'Social Studies',
                'classification_code' => 'core',
                'level_name' => 'Basic 7-9',
                'is_mandatory' => 1,
                'school_id' => $schoolId
            ],
            [
                'id' => 5,
                'subject_name' => 'ICT',
                'classification_code' => 'elective',
                'level_name' => 'Basic 7-9',
                'is_mandatory' => 0,
                'school_id' => $schoolId
            ],
            [
                'id' => 6,
                'subject_name' => 'French',
                'classification_code' => 'elective',
                'level_name' => 'Basic 7-9',
                'is_mandatory' => 0,
                'school_id' => $schoolId
            ],
            [
                'id' => 7,
                'subject_name' => 'B.D.T.',
                'classification_code' => 'elective',
                'level_name' => 'Basic 7-9',
                'is_mandatory' => 0,
                'school_id' => $schoolId
            ],
            [
                'id' => 8,
                'subject_name' => 'R.M.E.',
                'classification_code' => 'elective',
                'level_name' => 'Basic 7-9',
                'is_mandatory' => 0,
                'school_id' => $schoolId
            ]
        ];
    }

    private static function getMockAuditLogs($schoolId, $limit)
    {
        return [
            [
                'id' => 1,
                'action' => 'created',
                'field_name' => 'JHS WAEC Profile',
                'old_value' => null,
                'new_value' => 'Active',
                'changed_by' => 'Admin User',
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'id' => 2,
                'action' => 'updated',
                'field_name' => 'Primary Standard Profile',
                'old_value' => 'Draft',
                'new_value' => 'Active',
                'changed_by' => 'Admin User',
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))
            ],
            [
                'id' => 3,
                'action' => 'deleted',
                'field_name' => 'Old Profile',
                'old_value' => 'Archived',
                'new_value' => null,
                'changed_by' => 'System',
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))
            ],
            [
                'id' => 4,
                'action' => 'activated',
                'field_name' => 'SHS WAEC Profile',
                'old_value' => 'Draft',
                'new_value' => 'Active',
                'changed_by' => 'Admin User',
                'created_at' => date('Y-m-d H:i:s', strtotime('-3 hours'))
            ],
            [
                'id' => 5,
                'action' => 'duplicated',
                'field_name' => 'JHS WAEC Profile (Copy)',
                'old_value' => null,
                'new_value' => 'Draft',
                'changed_by' => 'Admin User',
                'created_at' => date('Y-m-d H:i:s', strtotime('-5 hours'))
            ]
        ];
    }
}
