<?php

/**
 * AssessmentComponentController.php
 * Assessment Component Controller - API Layer
 * @package EduTrack
 * @subpackage Controllers\Platform\Assessment
 * @version 1.0
 */

class AssessmentComponentController
{
    /**
     * GET /api/assessment/components
     */
    public function index()
    {
        header('Content-Type: application/json');
        $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

        $components = [
            ['id' => 1, 'component_name' => 'Class Assessment', 'component_code' => 'CA', 'default_weight' => 30, 'max_score' => 30],
            ['id' => 2, 'component_name' => 'Examination', 'component_code' => 'EXAM', 'default_weight' => 70, 'max_score' => 70],
            ['id' => 3, 'component_name' => 'Class Exercise', 'component_code' => 'CE', 'default_weight' => 0, 'max_score' => 100],
            ['id' => 4, 'component_name' => 'Home Work', 'component_code' => 'HW', 'default_weight' => 0, 'max_score' => 100],
            ['id' => 5, 'component_name' => 'Project Work', 'component_code' => 'PW', 'default_weight' => 0, 'max_score' => 100],
        ];

        echo json_encode(['success' => true, 'data' => $components, 'school_id' => $schoolId]);
        exit;
    }

    /**
     * GET /api/assessment/components/{id}
     * @param int $id
     */
    public function show(int $id)
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => ['id' => $id, 'component_name' => 'Sample Component']]);
        exit;
    }

    /**
     * POST /api/assessment/components
     */
    public function store()
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        echo json_encode(['success' => true, 'message' => 'Component created', 'data' => $data]);
        exit;
    }

    /**
     * PUT /api/assessment/components/{id}
     * @param int $id
     */
    public function update(int $id)
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        echo json_encode(['success' => true, 'message' => 'Component updated', 'data' => array_merge(['id' => $id], $data ?? [])]);
        exit;
    }

    /**
     * DELETE /api/assessment/components/{id}
     * @param int $id
     */
    public function destroy(int $id)
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Component deleted', 'data' => ['id' => $id]]);
        exit;
    }

    /**
     * GET /api/assessment/profiles/{profileId}/components
     * @param int $profileId
     */
    public function getProfileComponents(int $profileId)
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => ['profile_id' => $profileId, 'components' => []]]);
        exit;
    }

    /**
     * POST /api/assessment/profiles/{profileId}/components
     * @param int $profileId
     */
    public function attachToProfile(int $profileId)
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        echo json_encode(['success' => true, 'message' => 'Component attached to profile', 'data' => ['profile_id' => $profileId, 'component_id' => $data['component_id'] ?? null]]);
        exit;
    }

    /**
     * PUT /api/assessment/profiles/{profileId}/components/{componentId}
     * @param int $profileId
     * @param int $componentId
     */
    public function updateComponentWeight(int $profileId, int $componentId)
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        echo json_encode(['success' => true, 'message' => 'Component weight updated', 'data' => ['profile_id' => $profileId, 'component_id' => $componentId, 'weight' => $data['weight'] ?? 0]]);
        exit;
    }

    /**
     * DELETE /api/assessment/profiles/{profileId}/components/{componentId}
     * @param int $profileId
     * @param int $componentId
     */
    public function detachFromProfile(int $profileId, int $componentId)
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Component detached from profile', 'data' => ['profile_id' => $profileId, 'component_id' => $componentId]]);
        exit;
    }
}
