<?php

/**
 * RemarkRuleController.php
 * Remark Rule Controller - API Layer
 * @package EduTrack
 * @subpackage Controllers\Platform\Assessment
 * @version 1.0
 */

class RemarkRuleController
{
    public function index()
    {
        header('Content-Type: application/json');
        $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

        $data = [
            ['id' => 1, 'rule_name' => 'Outstanding', 'rule_code' => 'REM-OUT', 'min_score' => 80, 'max_score' => 100, 'remark_text' => 'Outstanding performance. Excellent work!'],
            ['id' => 2, 'rule_name' => 'Very Good', 'rule_code' => 'REM-VG', 'min_score' => 70, 'max_score' => 79, 'remark_text' => 'Very good performance. Keep it up!'],
            ['id' => 3, 'rule_name' => 'Good', 'rule_code' => 'REM-GD', 'min_score' => 60, 'max_score' => 69, 'remark_text' => 'Good performance. Continue working hard.'],
            ['id' => 4, 'rule_name' => 'Satisfactory', 'rule_code' => 'REM-SAT', 'min_score' => 50, 'max_score' => 59, 'remark_text' => 'Satisfactory performance. More effort needed.'],
            ['id' => 5, 'rule_name' => 'Needs Improvement', 'rule_code' => 'REM-NI', 'min_score' => 0, 'max_score' => 49, 'remark_text' => 'Needs significant improvement. Please seek help.'],
        ];

        echo json_encode(['success' => true, 'data' => $data, 'school_id' => $schoolId]);
        exit;
    }

    public function show(int $id)
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => ['id' => $id, 'rule_name' => 'Sample Remark Rule']]);
        exit;
    }

    public function store()
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        echo json_encode(['success' => true, 'message' => 'Remark rule created', 'data' => $data]);
        exit;
    }

    public function update(int $id)
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        echo json_encode(['success' => true, 'message' => 'Remark rule updated', 'data' => array_merge(['id' => $id], $data ?? [])]);
        exit;
    }

    public function destroy(int $id)
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Remark rule deleted', 'data' => ['id' => $id]]);
        exit;
    }

    public function generate()
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        $score = $data['score'] ?? 0;
        $remark = $score >= 80 ? 'Outstanding performance. Excellent work!' : ($score >= 70 ? 'Very good performance. Keep it up!' : ($score >= 60 ? 'Good performance. Continue working hard.' : ($score >= 50 ? 'Satisfactory performance. More effort needed.' :
                        'Needs significant improvement. Please seek help.')));
        echo json_encode(['success' => true, 'data' => ['score' => $score, 'remark' => $remark]]);
        exit;
    }
}
