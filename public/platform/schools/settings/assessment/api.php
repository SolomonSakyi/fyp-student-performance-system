<?php

/**
 * Assessment API - Direct endpoint for assessment settings
 * This file handles all assessment API calls directly
 * @package EduTrack
 * @subpackage Platform\Schools\Settings
 * @version 1.0
 */

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get the action from the request
$action = isset($_GET['action']) ? $_GET['action'] : '';
$schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

// If no school_id, use default
if ($schoolId <= 0) {
    $schoolId = 1;
}

// Helper function to send JSON response
function sendJsonResponse($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ============================================================
// MOCK DATA
// ============================================================

function getMockProfiles($schoolId)
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
            'total_weight' => 100,
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
            'total_weight' => 100,
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
            'total_weight' => 100,
            'created_at' => date('Y-m-d H:i:s')
        ]
    ];
}

function getMockGradingSystems($schoolId)
{
    return [
        [
            'grading_system_id' => 1,
            'name' => 'WAEC Numeric Grading',
            'code' => 'WAEC-NUM',
            'system_type' => 'numeric',
            'description' => 'WAEC compatible numeric grading system (1-9)',
            'is_default' => 1,
            'status' => 'active',
            'school_id' => $schoolId
        ],
        [
            'grading_system_id' => 2,
            'name' => 'Alphabetical A-F',
            'code' => 'ALPHA-AF',
            'system_type' => 'alphabetical',
            'description' => 'Standard alphabetical grading system (A-F)',
            'is_default' => 0,
            'status' => 'active',
            'school_id' => $schoolId
        ]
    ];
}

function getMockAggregationRules($schoolId)
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
        ]
    ];
}

function getMockComponents($schoolId)
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
        ]
    ];
}

function getMockRemarks($schoolId)
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
        ]
    ];
}

function getMockAuditLogs($schoolId)
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
            'action' => 'created',
            'field_name' => 'SHS WAEC Profile',
            'old_value' => null,
            'new_value' => 'Draft',
            'changed_by' => 'Admin User',
            'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))
        ]
    ];
}

// ============================================================
// ROUTE HANDLER
// ============================================================

switch ($action) {
    case 'profiles':
        // GET profiles
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            sendJsonResponse([
                'success' => true,
                'data' => getMockProfiles($schoolId),
                'school_id' => $schoolId,
                'total' => count(getMockProfiles($schoolId))
            ]);
        }
        break;

    case 'grading-systems':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            sendJsonResponse([
                'success' => true,
                'data' => getMockGradingSystems($schoolId),
                'school_id' => $schoolId
            ]);
        }
        break;

    case 'aggregation-rules':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            sendJsonResponse([
                'success' => true,
                'data' => getMockAggregationRules($schoolId),
                'school_id' => $schoolId
            ]);
        }
        break;

    case 'components':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            sendJsonResponse([
                'success' => true,
                'data' => getMockComponents($schoolId),
                'school_id' => $schoolId
            ]);
        }
        break;

    case 'remarks':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            sendJsonResponse([
                'success' => true,
                'data' => getMockRemarks($schoolId),
                'school_id' => $schoolId
            ]);
        }
        break;

    case 'audit':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            sendJsonResponse([
                'success' => true,
                'data' => getMockAuditLogs($schoolId)
            ]);
        }
        break;

    case 'test':
        sendJsonResponse([
            'success' => true,
            'message' => 'Assessment API is working!',
            'timestamp' => date('Y-m-d H:i:s'),
            'school_id' => $schoolId,
            'available_actions' => [
                'profiles',
                'grading-systems',
                'aggregation-rules',
                'components',
                'remarks',
                'audit',
                'test'
            ]
        ]);
        break;

    default:
        sendJsonResponse([
            'success' => false,
            'message' => 'Unknown action: ' . $action,
            'available_actions' => [
                'profiles',
                'grading-systems',
                'aggregation-rules',
                'components',
                'remarks',
                'audit',
                'test'
            ]
        ], 404);
        break;
}
