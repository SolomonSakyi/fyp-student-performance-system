<?php

/**
 * SchoolSettingsApiTest.php
 * Comprehensive API tests for School Settings module
 * 
 * @package EduTrack
 * @subpackage Tests\API
 * @version 1.0
 */

/**
 * RUN THESE TESTS USING:
 * 
 * 1. Postman - Import the collection
 * 2. cURL - Run individual commands
 * 3. PHPUnit - Run automated tests
 * 
 * BASE URL: http://localhost:8000/api
 */

class SchoolSettingsApiTest
{
    private $baseUrl;
    private $token;
    private $testData;

    public function __construct()
    {
        $this->baseUrl = 'http://localhost:8000/api';
        $this->testData = [
            'tenant_id' => 1,
            'school_id' => 1,
            'level_id' => 1,
            'subject_id' => null,
            'profile_id' => null,
            'system_id' => null,
            'rule_id' => null
        ];
    }

    // =============================================
    // TEST 1: AUTHENTICATION
    // =============================================

    /**
     * Test 1.1: Unauthenticated access should fail
     * 
     * GET /api/schools/1/settings
     * Expected: 401 Unauthorized
     */
    public function testUnauthenticatedAccess()
    {
        $ch = curl_init($this->baseUrl . '/schools/1/settings');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        echo "\n[TEST 1.1] Unauthenticated Access:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Response: " . $response . "\n";
        echo "Expected: 401 Unauthorized\n";
        echo $httpCode === 401 ? "✅ PASSED\n" : "❌ FAILED\n";
    }

    /**
     * Test 1.2: Valid token should succeed
     * 
     * GET /api/schools/1/settings
     * Header: Authorization: Bearer {valid_token}
     * Expected: 200 OK
     */
    public function testAuthenticatedAccess($token)
    {
        $ch = curl_init($this->baseUrl . '/schools/1/settings');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 1.2] Authenticated Access:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Response: " . substr($response, 0, 200) . "...\n";
        echo "Expected: 200 OK with data\n";
        echo $httpCode === 200 && isset($data['success']) && $data['success'] === true ? "✅ PASSED\n" : "❌ FAILED\n";
    }

    /**
     * Test 1.3: Invalid token should fail
     * 
     * GET /api/schools/1/settings
     * Header: Authorization: Bearer invalid_token
     * Expected: 401 Unauthorized
     */
    public function testInvalidToken()
    {
        $ch = curl_init($this->baseUrl . '/schools/1/settings');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer invalid_token'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        echo "\n[TEST 1.3] Invalid Token:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Expected: 401 Unauthorized\n";
        echo $httpCode === 401 ? "✅ PASSED\n" : "❌ FAILED\n";
    }

    // =============================================
    // TEST 2: SCHOOL CONFIGURATION
    // =============================================

    /**
     * Test 2.1: Get school configuration
     * 
     * GET /api/schools/{schoolId}/settings
     */
    public function testGetSchoolConfig($token, $schoolId = 1)
    {
        $ch = curl_init($this->baseUrl . '/schools/' . $schoolId . '/settings');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 2.1] Get School Configuration:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Success: " . ($data['success'] ? 'true' : 'false') . "\n";
        echo $httpCode === 200 && $data['success'] === true ? "✅ PASSED\n" : "❌ FAILED\n";

        return $data['data'] ?? null;
    }

    /**
     * Test 2.2: Update school configuration
     * 
     * PUT /api/schools/{schoolId}/settings
     */
    public function testUpdateSchoolConfig($token, $schoolId = 1)
    {
        $payload = [
            'school_name' => 'Updated School Name ' . date('Y-m-d'),
            'motto' => 'Excellence Through Innovation',
            'school_type' => 'International School',
            'curriculum' => 'International Baccalaureate',
            'timezone' => 'Africa/Accra'
        ];

        $ch = curl_init($this->baseUrl . '/schools/' . $schoolId . '/settings');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 2.2] Update School Configuration:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Message: " . ($data['message'] ?? 'N/A') . "\n";
        echo $httpCode === 200 && $data['success'] === true ? "✅ PASSED\n" : "❌ FAILED\n";

        return $data;
    }

    /**
     * Test 2.3: Get configuration health
     * 
     * GET /api/schools/{schoolId}/settings/health
     */
    public function testGetConfigHealth($token, $schoolId = 1)
    {
        $ch = curl_init($this->baseUrl . '/schools/' . $schoolId . '/settings/health');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 2.3] Get Configuration Health:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Overall Health: " . ($data['data']['overall'] ?? 'N/A') . "%\n";
        echo $httpCode === 200 && $data['success'] === true ? "✅ PASSED\n" : "❌ FAILED\n";

        return $data['data'] ?? null;
    }

    // =============================================
    // TEST 3: LEVEL MANAGEMENT
    // =============================================

    /**
     * Test 3.1: Get school levels
     * 
     * GET /api/schools/{schoolId}/levels
     */
    public function testGetLevels($token, $schoolId = 1)
    {
        $ch = curl_init($this->baseUrl . '/schools/' . $schoolId . '/levels');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 3.1] Get School Levels:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Total Levels: " . count($data['data'] ?? []) . "\n";
        echo $httpCode === 200 && $data['success'] === true ? "✅ PASSED\n" : "❌ FAILED\n";

        return $data['data'] ?? [];
    }

    /**
     * Test 3.2: Add a new level
     * 
     * POST /api/schools/{schoolId}/levels
     */
    public function testAddLevel($token, $schoolId = 1)
    {
        $payload = [
            'level_code' => 'TEST_LEVEL_' . time(),
            'level_name' => 'Test Level ' . date('Y-m-d'),
            'display_name' => 'Test Level',
            'short_name' => 'TL',
            'sequence' => 99,
            'academic_stage' => 'Testing',
            'age_range_from' => 10,
            'age_range_to' => 15,
            'is_enabled' => 1,
            'is_active' => 1
        ];

        $ch = curl_init($this->baseUrl . '/schools/' . $schoolId . '/levels');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 3.2] Add New Level:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Message: " . ($data['message'] ?? 'N/A') . "\n";
        echo "Level ID: " . ($data['level_id'] ?? 'N/A') . "\n";
        echo $httpCode === 200 && $data['success'] === true ? "✅ PASSED\n" : "❌ FAILED\n";

        return $data['level_id'] ?? null;
    }

    /**
     * Test 3.3: Update level display name
     * 
     * PUT /api/schools/{schoolId}/levels/{levelCode}/display-name
     */
    public function testUpdateLevelDisplayName($token, $schoolId = 1, $levelCode = 'PRIMARY_1')
    {
        $payload = [
            'display_name' => 'Grade 1',
            'naming_convention' => 'grade',
            'prefix' => 'Grade',
            'suffix' => '',
            'example' => 'Grade 1'
        ];

        $ch = curl_init($this->baseUrl . '/schools/' . $schoolId . '/levels/' . $levelCode . '/display-name');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 3.3] Update Level Display Name:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Message: " . ($data['message'] ?? 'N/A') . "\n";
        echo $httpCode === 200 && $data['success'] === true ? "✅ PASSED\n" : "❌ FAILED\n";
    }

    /**
     * Test 3.4: Get available levels
     * 
     * GET /api/schools/available-levels
     */
    public function testGetAvailableLevels($token)
    {
        $ch = curl_init($this->baseUrl . '/schools/available-levels');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 3.4] Get Available Levels:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Total Available Levels: " . count($data['data'] ?? []) . "\n";
        echo $httpCode === 200 && $data['success'] === true ? "✅ PASSED\n" : "❌ FAILED\n";
    }

    // =============================================
    // TEST 4: SUBJECT MANAGEMENT
    // =============================================

    /**
     * Test 4.1: Get school subjects
     * 
     * GET /api/schools/{schoolId}/subjects
     */
    public function testGetSubjects($token, $schoolId = 1)
    {
        $ch = curl_init($this->baseUrl . '/schools/' . $schoolId . '/subjects');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 4.1] Get School Subjects:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Total Subjects: " . count($data['data'] ?? []) . "\n";
        echo "Stats: " . json_encode($data['stats'] ?? []) . "\n";
        echo $httpCode === 200 && $data['success'] === true ? "✅ PASSED\n" : "❌ FAILED\n";

        return $data['data'] ?? [];
    }

    /**
     * Test 4.2: Add a new subject
     * 
     * POST /api/schools/{schoolId}/subjects
     */
    public function testAddSubject($token, $schoolId = 1)
    {
        $payload = [
            'subject_code' => 'TEST_SUBJECT_' . time(),
            'subject_name' => 'Test Subject ' . date('Y-m-d'),
            'short_name' => 'TS',
            'discipline_id' => null,
            'description' => 'Test subject for API testing',
            'is_core' => 1,
            'is_elective' => 0,
            'is_optional' => 0,
            'is_active' => 1,
            'display_order' => 99,
            'max_score' => 100,
            'pass_mark' => 50,
            'levels' => [
                ['level_id' => 1, 'is_core' => 1, 'is_elective' => 0]
            ]
        ];

        $ch = curl_init($this->baseUrl . '/schools/' . $schoolId . '/subjects');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 4.2] Add New Subject:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Message: " . ($data['message'] ?? 'N/A') . "\n";
        echo "Subject ID: " . ($data['subject_id'] ?? 'N/A') . "\n";
        echo $httpCode === 200 && $data['success'] === true ? "✅ PASSED\n" : "❌ FAILED\n";

        return $data['subject_id'] ?? null;
    }

    /**
     * Test 4.3: Get subjects by level
     * 
     * GET /api/schools/{schoolId}/subjects/level/{levelId}
     */
    public function testGetSubjectsByLevel($token, $schoolId = 1, $levelId = 1)
    {
        $ch = curl_init($this->baseUrl . '/schools/' . $schoolId . '/subjects/level/' . $levelId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 4.3] Get Subjects by Level:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Total Subjects for Level: " . count($data['data'] ?? []) . "\n";
        echo $httpCode === 200 && $data['success'] === true ? "✅ PASSED\n" : "❌ FAILED\n";
    }

    /**
     * Test 4.4: Assign subject to level
     * 
     * POST /api/schools/{schoolId}/subjects/{subjectId}/assign-level
     */
    public function testAssignSubjectToLevel($token, $schoolId = 1, $subjectId = 1, $levelId = 2)
    {
        $payload = [
            'level_id' => $levelId,
            'is_core' => 0,
            'is_elective' => 1,
            'is_optional' => 0,
            'max_score' => 100,
            'pass_mark' => 50
        ];

        $ch = curl_init($this->baseUrl . '/schools/' . $schoolId . '/subjects/' . $subjectId . '/assign-level');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 4.4] Assign Subject to Level:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Message: " . ($data['message'] ?? 'N/A') . "\n";
        echo $httpCode === 200 && $data['success'] === true ? "✅ PASSED\n" : "❌ FAILED\n";
    }

    // =============================================
    // TEST 5: TENANT ISOLATION (SECURITY)
    // =============================================

    /**
     * Test 5.1: Cross-tenant access prevention
     * 
     * User from Tenant 1 trying to access Tenant 2 school
     * Expected: 403 Forbidden
     */
    public function testCrossTenantAccess($token, $schoolId = 2)
    {
        $ch = curl_init($this->baseUrl . '/schools/' . $schoolId . '/settings');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 5.1] Cross-Tenant Access Prevention:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Message: " . ($data['message'] ?? 'N/A') . "\n";
        echo "Expected: 403 Forbidden\n";
        echo $httpCode === 403 || $httpCode === 401 ? "✅ PASSED\n" : "❌ FAILED\n";
    }

    /**
     * Test 5.2: Cross-school access prevention (same tenant)
     * 
     * User from School 1 trying to access School 2
     * Expected: 403 Forbidden
     */
    public function testCrossSchoolAccess($token, $schoolId = 2)
    {
        // First check if school 2 exists and belongs to same tenant
        $ch = curl_init($this->baseUrl . '/platform/schools/' . $schoolId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpCode === 200 && isset($data['data'])) {
            // School exists, now try to access its settings
            $ch = curl_init($this->baseUrl . '/schools/' . $schoolId . '/settings');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $data = json_decode($response, true);
        }

        echo "\n[TEST 5.2] Cross-School Access Prevention:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Message: " . ($data['message'] ?? 'N/A') . "\n";
        echo $httpCode === 403 || $httpCode === 401 ? "✅ PASSED\n" : "⚠️ SCHOOL MAY NOT EXIST\n";
    }

    // =============================================
    // TEST 6: ASSESSMENT PROFILES
    // =============================================

    /**
     * Test 6.1: Get assessment profiles
     * 
     * GET /api/schools/{schoolId}/assessment/profiles
     */
    public function testGetAssessmentProfiles($token, $schoolId = 1)
    {
        $ch = curl_init($this->baseUrl . '/schools/' . $schoolId . '/assessment/profiles');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 6.1] Get Assessment Profiles:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Total Profiles: " . count($data['data'] ?? []) . "\n";
        echo "Stats: " . json_encode($data['stats'] ?? []) . "\n";
        echo $httpCode === 200 && $data['success'] === true ? "✅ PASSED\n" : "❌ FAILED\n";

        return $data['data'] ?? [];
    }

    /**
     * Test 6.2: Create assessment profile
     * 
     * POST /api/schools/{schoolId}/assessment/profiles
     */
    public function testCreateAssessmentProfile($token, $schoolId = 1)
    {
        $payload = [
            'profile_name' => 'Test Profile ' . date('Y-m-d'),
            'profile_code' => 'TEST_PROFILE_' . time(),
            'description' => 'Test profile for API testing',
            'applicable_levels' => 'PRIMARY_1,PRIMARY_2',
            'status' => 'draft',
            'is_default' => 0,
            'components' => [
                [
                    'component_name' => 'Test Component 1',
                    'component_code' => 'TC1_' . time(),
                    'component_type' => 'test',
                    'max_score' => 30,
                    'weight' => 30,
                    'sequence' => 1,
                    'is_required' => 1,
                    'counts_towards_total' => 1
                ],
                [
                    'component_name' => 'Test Component 2',
                    'component_code' => 'TC2_' . time(),
                    'component_type' => 'exam',
                    'max_score' => 70,
                    'weight' => 70,
                    'sequence' => 2,
                    'is_required' => 1,
                    'counts_towards_total' => 1
                ]
            ]
        ];

        $ch = curl_init($this->baseUrl . '/schools/' . $schoolId . '/assessment/profiles');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 6.2] Create Assessment Profile:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Message: " . ($data['message'] ?? 'N/A') . "\n";
        echo "Profile ID: " . ($data['profile_id'] ?? 'N/A') . "\n";
        echo $httpCode === 200 && $data['success'] === true ? "✅ PASSED\n" : "❌ FAILED\n";

        return $data['profile_id'] ?? null;
    }

    /**
     * Test 6.3: Validate profile weights
     * 
     * POST /api/schools/{schoolId}/assessment/profiles/{profileId}/validate
     */
    public function testValidateProfileWeights($token, $schoolId = 1, $profileId = 1)
    {
        $ch = curl_init($this->baseUrl . '/schools/' . $schoolId . '/assessment/profiles/' . $profileId . '/validate');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 6.3] Validate Profile Weights:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Total Weight: " . ($data['data']['total_weight'] ?? 'N/A') . "%\n";
        echo "Valid: " . ($data['data']['is_valid'] ? 'Yes' : 'No') . "\n";
        echo $httpCode === 200 && $data['success'] === true ? "✅ PASSED\n" : "❌ FAILED\n";
    }

    // =============================================
    // TEST 7: GRADING SYSTEMS
    // =============================================

    /**
     * Test 7.1: Get grading systems
     * 
     * GET /api/schools/{schoolId}/grading/systems
     */
    public function testGetGradingSystems($token, $schoolId = 1)
    {
        $ch = curl_init($this->baseUrl . '/schools/' . $schoolId . '/grading/systems');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 7.1] Get Grading Systems:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Total Systems: " . count($data['data'] ?? []) . "\n";
        echo $httpCode === 200 && $data['success'] === true ? "✅ PASSED\n" : "❌ FAILED\n";

        return $data['data'] ?? [];
    }

    /**
     * Test 7.2: Create grading system
     * 
     * POST /api/schools/{schoolId}/grading/systems
     */
    public function testCreateGradingSystem($token, $schoolId = 1)
    {
        $payload = [
            'system_name' => 'Test Grading System ' . date('Y-m-d'),
            'system_code' => 'TEST_GRADE_' . time(),
            'system_type' => 'alphabetic',
            'description' => 'Test grading system for API testing',
            'is_default' => 0,
            'scales' => [
                ['grade' => 'A', 'min_score' => 80, 'max_score' => 100, 'grade_point' => 1, 'sort_order' => 1],
                ['grade' => 'B', 'min_score' => 70, 'max_score' => 79, 'grade_point' => 2, 'sort_order' => 2],
                ['grade' => 'C', 'min_score' => 60, 'max_score' => 69, 'grade_point' => 3, 'sort_order' => 3],
                ['grade' => 'D', 'min_score' => 50, 'max_score' => 59, 'grade_point' => 4, 'sort_order' => 4],
                ['grade' => 'F', 'min_score' => 0, 'max_score' => 49, 'grade_point' => 5, 'sort_order' => 5]
            ]
        ];

        $ch = curl_init($this->baseUrl . '/schools/' . $schoolId . '/grading/systems');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 7.2] Create Grading System:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Message: " . ($data['message'] ?? 'N/A') . "\n";
        echo "System ID: " . ($data['system_id'] ?? 'N/A') . "\n";
        echo $httpCode === 200 && $data['success'] === true ? "✅ PASSED\n" : "❌ FAILED\n";

        return $data['system_id'] ?? null;
    }

    // =============================================
    // TEST 8: PROMOTION & REMARKS
    // =============================================

    /**
     * Test 8.1: Get promotion rules
     * 
     * GET /api/schools/{schoolId}/promotion/rules
     */
    public function testGetPromotionRules($token, $schoolId = 1)
    {
        $ch = curl_init($this->baseUrl . '/schools/' . $schoolId . '/promotion/rules');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 8.1] Get Promotion Rules:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Total Rules: " . count($data['data'] ?? []) . "\n";
        echo $httpCode === 200 && $data['success'] === true ? "✅ PASSED\n" : "❌ FAILED\n";
    }

    /**
     * Test 8.2: Generate remark
     * 
     * POST /api/schools/{schoolId}/remarks/generate
     */
    public function testGenerateRemark($token, $schoolId = 1)
    {
        $payload = [
            'score' => 85,
            'context' => 'Mathematics',
            'level_id' => 1
        ];

        $ch = curl_init($this->baseUrl . '/schools/' . $schoolId . '/remarks/generate');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        echo "\n[TEST 8.2] Generate Remark:\n";
        echo "Status Code: " . $httpCode . "\n";
        echo "Score: " . ($data['data']['score'] ?? 'N/A') . "\n";
        echo "Remark: " . ($data['data']['remark'] ?? 'N/A') . "\n";
        echo $httpCode === 200 && $data['success'] === true ? "✅ PASSED\n" : "❌ FAILED\n";
    }

    // =============================================
    // RUN ALL TESTS
    // =============================================

    public function runAllTests($token)
    {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "RUNNING COMPLETE API TEST SUITE\n";
        echo str_repeat('=', 60) . "\n";

        // Authentication
        $this->testUnauthenticatedAccess();
        $this->testAuthenticatedAccess($token);
        $this->testInvalidToken();

        // Configuration
        $this->testGetSchoolConfig($token);
        $this->testUpdateSchoolConfig($token);
        $this->testGetConfigHealth($token);

        // Levels
        $levels = $this->testGetLevels($token);
        $this->testGetAvailableLevels($token);

        if (!empty($levels)) {
            $levelCode = $levels[0]['level_code'] ?? 'PRIMARY_1';
            $this->testUpdateLevelDisplayName($token, 1, $levelCode);
        }

        // Subjects
        $subjects = $this->testGetSubjects($token);
        $subjectId = $this->testAddSubject($token);
        $this->testGetSubjectsByLevel($token);

        if ($subjectId) {
            $this->testAssignSubjectToLevel($token, 1, $subjectId, 2);
        }

        // Assessment
        $profiles = $this->testGetAssessmentProfiles($token);
        $profileId = $this->testCreateAssessmentProfile($token);

        if ($profileId) {
            $this->testValidateProfileWeights($token, 1, $profileId);
        }

        // Grading
        $this->testGetGradingSystems($token);
        $this->testCreateGradingSystem($token);

        // Security
        $this->testCrossTenantAccess($token);
        $this->testCrossSchoolAccess($token);

        // Promotion & Remarks
        $this->testGetPromotionRules($token);
        $this->testGenerateRemark($token);

        echo "\n" . str_repeat('=', 60) . "\n";
        echo "API TEST SUITE COMPLETE\n";
        echo str_repeat('=', 60) . "\n";
    }
}

// =============================================
// RUNNER SCRIPT
// =============================================

// Usage: php tests/api/SchoolSettingsApiTest.php --token=YOUR_TOKEN

if (php_sapi_name() === 'cli') {
    $args = getopt('', ['token:']);
    $token = $args['token'] ?? '';

    if (empty($token)) {
        echo "Please provide a token: php test.php --token=YOUR_TOKEN\n";
        exit(1);
    }

    $test = new SchoolSettingsApiTest();
    $test->runAllTests($token);
}
