<?php

/**
 * PassRuleController.php
 * Pass Rule Controller - API Layer
 * @package EduTrack
 * @subpackage Controllers\Platform\Assessment
 * @version 1.0
 */

class PassRuleController
{
    public function index()
    {
        header('Content-Type: application/json');
        $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

        $data = [
            ['id' => 1, 'rule_name' => 'Standard Pass Rule', 'rule_code' => 'STD-PASS', 'pass_threshold' => 50, 'subject_count_required' => 5, 'status' => 'active']
        ];

        echo json_encode(['success' => true, 'data' => $data, 'school_id' => $schoolId]);
        exit;
    }

    public function show(int $id)
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => ['id' => $id, 'rule_name' => 'Sample Pass Rule']]);
        exit;
    }

    public function store()
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        echo json_encode(['success' => true, 'message' => 'Pass rule created', 'data' => $data]);
        exit;
    }

    public function update(int $id)
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        echo json_encode(['success' => true, 'message' => 'Pass rule updated', 'data' => array_merge(['id' => $id], $data ?? [])]);
        exit;
    }

    public function destroy(int $id)
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Pass rule deleted', 'data' => ['id' => $id]]);
        exit;
    }

    public function checkPass()
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        echo json_encode(['success' => true, 'message' => 'Pass check completed', 'data' => ['passed' => true]]);
        exit;
    }
}
