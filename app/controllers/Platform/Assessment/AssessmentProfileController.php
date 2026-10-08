<?php

/**
 * AssessmentProfileController.php
 * Assessment Profile Controller - API Layer
 * @package EduTrack
 * @subpackage Controllers\Platform\Assessment
 * @version 1.0
 */

class AssessmentProfileController
{
    /**
     * GET /api/assessment/profiles
     */
    public function index()
    {
        header('Content-Type: application/json');

        $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

        $profiles = [
            [
                'id' => 1,
                'profile_name' => 'JHS WAEC Profile',
                'profile_code' => 'JHS-WAEC',
                'description' => 'Standard JHS assessment profile based on WAEC model',
                'applicable_levels' => 'basic_7,basic_8,basic_9',
                'school_id' => $schoolId,
                'status' => 'active',
                'is_default' => 1
            ],
            [
                'id' => 2,
                'profile_name' => 'Primary Standard Profile',
                'profile_code' => 'PRIM-STD',
                'description' => 'Standard primary school assessment profile',
                'applicable_levels' => 'primary_1,primary_2,primary_3,primary_4,primary_5,primary_6',
                'school_id' => $schoolId,
                'status' => 'active',
                'is_default' => 0
            ]
        ];

        echo json_encode(['success' => true, 'data' => $profiles, 'school_id' => $schoolId]);
        exit;
    }

    /**
     * GET /api/assessment/profiles/{id}
     * @param int $id
     */
    public function show(int $id)
    {
        header('Content-Type: application/json');

        $profile = [
            'id' => $id,
            'profile_name' => 'Sample Profile ' . $id,
            'profile_code' => 'SAMPLE-' . $id,
            'description' => 'Sample profile for testing',
            'applicable_levels' => 'basic_7,basic_8,basic_9',
            'status' => 'active',
            'is_default' => 0,
            'components' => [
                ['component_id' => 1, 'component_name' => 'Class Assessment', 'weight' => 30],
                ['component_id' => 2, 'component_name' => 'Examination', 'weight' => 70]
            ]
        ];

        echo json_encode(['success' => true, 'data' => $profile]);
        exit;
    }

    /**
     * POST /api/assessment/profiles
     */
    public function store()
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data || empty($data['profile_name']) || empty($data['profile_code']) || empty($data['school_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Profile name, code, and school ID are required']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Profile created successfully',
            'data' => [
                'id' => rand(100, 999),
                'profile_name' => $data['profile_name'],
                'profile_code' => $data['profile_code'],
                'school_id' => $data['school_id'],
                'status' => $data['status'] ?? 'draft'
            ]
        ]);
        exit;
    }

    /**
     * PUT /api/assessment/profiles/{id}
     * @param int $id
     */
    public function update(int $id)
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);

        echo json_encode([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => array_merge(['id' => $id], $data ?? [])
        ]);
        exit;
    }

    /**
     * DELETE /api/assessment/profiles/{id}
     * @param int $id
     */
    public function destroy(int $id)
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Profile deleted successfully', 'data' => ['id' => $id]]);
        exit;
    }

    /**
     * POST /api/assessment/profiles/{id}/activate
     * @param int $id
     */
    public function activate(int $id)
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        $status = $data['status'] ?? 'active';

        echo json_encode([
            'success' => true,
            'message' => 'Profile status updated successfully',
            'data' => ['id' => $id, 'status' => $status]
        ]);
        exit;
    }

    /**
     * POST /api/assessment/profiles/{id}/duplicate
     * @param int $id
     */
    public function duplicate(int $id)
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        $newName = $data['name'] ?? 'Copy of Profile ' . $id;

        echo json_encode([
            'success' => true,
            'message' => 'Profile duplicated successfully',
            'data' => ['id' => rand(100, 999), 'profile_name' => $newName, 'original_id' => $id]
        ]);
        exit;
    }

    /**
     * POST /api/assessment/profiles/{id}/lock
     * @param int $id
     */
    public function lock(int $id)
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Profile locked successfully', 'data' => ['id' => $id, 'status' => 'locked']]);
        exit;
    }

    /**
     * POST /api/assessment/profiles/{id}/unlock
     * @param int $id
     */
    public function unlock(int $id)
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Profile unlocked successfully', 'data' => ['id' => $id, 'status' => 'active']]);
        exit;
    }
}
