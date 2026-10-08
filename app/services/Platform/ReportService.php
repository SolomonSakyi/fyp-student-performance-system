/**
* Generate student report card using assessment configuration
*/
public function generateReportCard(int $studentId, int $termId, int $yearId): array
{
// Get student data
$student = $this->studentModel->find($studentId);
if (!$student) {
return ['success' => false, 'message' => 'Student not found'];
}

// Get assessment configuration for this student's level
$config = $this->getAssessmentConfig($student['school_id'], $student['level_id']);

// Get student scores
$scores = $this->getStudentScores($studentId, $termId, $yearId);

// Calculate grades using the configuration
$grades = $this->gradeCalculationService->calculateGrades($scores, $config);

// Calculate aggregate
$aggregate = $this->aggregationService->calculateAggregate($grades, $config);

// Generate remark
$remark = $this->remarkService->generateRemark($config['profile_id'], $aggregate['score']);

// Generate personality profile
$personality = $this->personalityService->generateProfile($config['profile_id'], [
'name' => $student['first_name'] . ' ' . $student['last_name'],
'grades' => $grades,
'attendance' => $this->getStudentAttendance($studentId, $termId)
]);

return [
'success' => true,
'data' => [
'student' => $student,
'scores' => $scores,
'grades' => $grades,
'aggregate' => $aggregate,
'remark' => $remark,
'personality_profile' => $personality['profile'] ?? '',
'config' => $config
]
];
}