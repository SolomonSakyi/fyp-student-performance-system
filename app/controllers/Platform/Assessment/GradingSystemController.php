<?php

/**
 * GradingSystemController.php
 * Grading System Controller - API Layer
 * @package EduTrack
 * @subpackage Controllers\Platform\Assessment
 * @version 1.0
 */

class GradingSystemController
{
    public function index()
    {
        header('Content-Type: application/json');
        $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

        $data = [
            [
                'id' => 1,
                'system_name' => 'WAEC Numeric Grading',
                'system_code' => 'WAEC-NUM',
                'system_type' => 'number',
                'is_default' => 1,
                'is_active' => 1
            ],
            [
                'id' => 2,
                'system_name' => 'Alphabetical A-F',
                'system_code' => 'ALPHA-AF',
                'system_type' => 'alphabet',
                'is_default' => 0,
                'is_active' => 1
            ]
        ];

        echo json_encode(['success' => true, 'data' => $data, 'school_id' => $schoolId]);
        exit;
    }

    public function show(int $id)
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => ['id' => $id, 'system_name' => 'Sample Grading System']]);
        exit;
    }

    public function store()
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        echo json_encode(['success' => true, 'message' => 'Grading system created', 'data' => $data]);
        exit;
    }

    public function update(int $id)
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        echo json_encode(['success' => true, 'message' => 'Grading system updated', 'data' => array_merge(['id' => $id], $data ?? [])]);
        exit;
    }

    public function destroy(int $id)
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Grading system deleted', 'data' => ['id' => $id]]);
        exit;
    }

    public function setDefault(int $id)
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Default grading system updated', 'data' => ['id' => $id]]);
        exit;
    }

    public function getScales(int $id)
    {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => [
                ['grade' => '1', 'min_score' => 80, 'max_score' => 100, 'grade_point' => 1, 'remark' => 'Excellent'],
                ['grade' => '2', 'min_score' => 70, 'max_score' => 79, 'grade_point' => 2, 'remark' => 'Very Good'],
                ['grade' => '3', 'min_score' => 60, 'max_score' => 69, 'grade_point' => 3, 'remark' => 'Good'],
                ['grade' => '4', 'min_score' => 50, 'max_score' => 59, 'grade_point' => 4, 'remark' => 'Satisfactory'],
                ['grade' => '5', 'min_score' => 40, 'max_score' => 49, 'grade_point' => 5, 'remark' => 'Pass'],
                ['grade' => '6', 'min_score' => 0, 'max_score' => 39, 'grade_point' => 6, 'remark' => 'Fail']
            ]
        ]);
        exit;
    }

    public function addScale(int $id)
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        echo json_encode(['success' => true, 'message' => 'Grade scale added', 'data' => array_merge(['grading_system_id' => $id], $data ?? [])]);
        exit;
    }

    public function updateScale(int $id)
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        echo json_encode(['success' => true, 'message' => 'Grade scale updated', 'data' => array_merge(['grade_scale_id' => $id], $data ?? [])]);
        exit;
    }

    public function deleteScale(int $id)
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Grade scale deleted', 'data' => ['grade_scale_id' => $id]]);
        exit;
    }

    public function calculate()
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        $score = $data['score'] ?? 0;
        $grade = $score >= 80 ? '1' : ($score >= 70 ? '2' : ($score >= 60 ? '3' : ($score >= 50 ? '4' : ($score >= 40 ? '5' : '6'))));
        $gradePoint = $score >= 80 ? 1 : ($score >= 70 ? 2 : ($score >= 60 ? 3 : ($score >= 50 ? 4 : ($score >= 40 ? 5 : 6))));
        echo json_encode(['success' => true, 'data' => ['score' => $score, 'grade' => $grade, 'grade_point' => $gradePoint]]);
        exit;
    }
}
