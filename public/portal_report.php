<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['portal_student_id'])) {
    header('Location: /portal_login.php');
    exit;
}

require_once __DIR__ . '/../app/services/ReportCardService.php';

$studentId = $_SESSION['portal_student_id'];
$yearId = $_GET['year_id'] ?? null;
$termId = $_GET['term_id'] ?? null;

if (!$yearId || !$termId) {
    echo 'Invalid request.';
    exit;
}

$reportService = new ReportCardService();

try {
    $html = $reportService->generateHTML($studentId, $yearId, $termId);
    echo $html;
} catch (Exception $e) {
    echo '<h3>Error: ' . htmlspecialchars($e->getMessage()) . '</h3>';
    echo '<p><a href="portal_dashboard.php">← Back to Dashboard</a></p>';
}
?>