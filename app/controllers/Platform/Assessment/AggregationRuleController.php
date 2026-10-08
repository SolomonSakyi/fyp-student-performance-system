<?php

/**
 * AggregationRuleController.php
 * Aggregation Rule Controller - API Layer
 * @package EduTrack
 * @subpackage Controllers\Platform\Assessment
 * @version 1.0
 */

class AggregationRuleController
{
    public function index()
    {
        header('Content-Type: application/json');
        $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

        $data = [
            ['id' => 1, 'rule_name' => 'JHS Standard Aggregate', 'rule_code' => 'JHS-AGG', 'core_count' => 4, 'elective_count' => 2, 'status' => 'active']
        ];

        echo json_encode(['success' => true, 'data' => $data, 'school_id' => $schoolId]);
        exit;
    }

    public function show(int $id)
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => ['id' => $id, 'rule_name' => 'Sample Aggregation Rule']]);
        exit;
    }

    public function store()
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        echo json_encode(['success' => true, 'message' => 'Aggregation rule created', 'data' => $data]);
        exit;
    }

    public function update(int $id)
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        echo json_encode(['success' => true, 'message' => 'Aggregation rule updated', 'data' => array_merge(['id' => $id], $data ?? [])]);
        exit;
    }

    public function destroy(int $id)
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Aggregation rule deleted', 'data' => ['id' => $id]]);
        exit;
    }

    public function calculate()
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        $scores = $data['scores'] ?? [];
        $aggregate = array_sum(array_column($scores, 'grade_point'));
        echo json_encode(['success' => true, 'data' => ['aggregate' => $aggregate]]);
        exit;
    }
}
