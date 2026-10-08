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
    $pdfContent = $reportService->generatePDF($studentId, $yearId, $termId);
    
    if (strpos($pdfContent, '<!DOCTYPE html>') !== false || strpos($pdfContent, '<html') !== false) {
        header('Content-Type: text/html; charset=utf-8');
        echo $pdfContent;
        exit;
    }
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="report_card_' . $studentId . '.pdf"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: private, max-age=0, must-revalidate');
    
    echo $pdfContent;
} catch (Exception $e) {
    echo '<h3>Error: ' . htmlspecialchars($e->getMessage()) . '</h3>';
    echo '<p><a href="portal_dashboard.php">← Back to Dashboard</a></p>';
}
?>