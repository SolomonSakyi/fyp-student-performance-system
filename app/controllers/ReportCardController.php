<?php

/**
 * Report Card Controller
 * Handles report card requests
 */

require_once __DIR__ . '/../services/ReportCardService.php';

class ReportCardController
{
    private $reportCardService;
    private $logger;

    public function __construct()
    {
        $this->reportCardService = new ReportCardService();
        $this->logger = new LoggerHelper();
    }

    /**
     * View report card in browser
     */
    public function view($studentId, $academicYearId, $academicTermId)
    {
        try {
            $html = $this->reportCardService->generateHTML($studentId, $academicYearId, $academicTermId);
            
            // Output HTML with proper headers
            header('Content-Type: text/html; charset=utf-8');
            echo $html;
            
        } catch (Exception $e) {
            echo '<h3>Error: ' . htmlspecialchars($e->getMessage()) . '</h3>';
        }
    }

    /**
     * Download PDF report card
     */
    public function downloadPDF($studentId, $academicYearId, $academicTermId)
    {
        try {
            $pdfContent = $this->reportCardService->generatePDF($studentId, $academicYearId, $academicTermId);
            
            // Check if PDF content is HTML (fallback) or actual PDF
            if (strpos($pdfContent, '<!DOCTYPE html>') !== false || strpos($pdfContent, '<html') !== false) {
                // It's HTML, output as HTML with print button
                header('Content-Type: text/html; charset=utf-8');
                echo $pdfContent;
                return;
            }
            
            // It's a real PDF
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="report_card.pdf"');
            header('Content-Length: ' . strlen($pdfContent));
            header('Cache-Control: private, max-age=0, must-revalidate');
            
            echo $pdfContent;
            
        } catch (Exception $e) {
            echo '<h3>Error: ' . htmlspecialchars($e->getMessage()) . '</h3>';
        }
    }
    
/**
 * Batch report cards for a class
 */
public function batchView($classSectionId, $academicYearId, $academicTermId)
{
    try {
        require_once __DIR__ . '/../services/BatchReportService.php';
        $batchService = new BatchReportService();
        
        $html = $batchService->generateCombinedHTML($classSectionId, $academicYearId, $academicTermId);
        
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        
    } catch (Exception $e) {
        echo '<h3>Error: ' . htmlspecialchars($e->getMessage()) . '</h3>';
    }
}

/**
 * Generate ZIP file with all report cards
 */
public function batchDownload($classSectionId, $academicYearId, $academicTermId)
{
    try {
        require_once __DIR__ . '/../services/BatchReportService.php';
        $batchService = new BatchReportService();
        
        $result = $batchService->generateClassReports($classSectionId, $academicYearId, $academicTermId);
        
        if (!$result['success']) {
            throw new Exception($result['message']);
        }
        
        // Create ZIP file
        $zip = new ZipArchive();
        $filename = 'report_cards_class_' . $classSectionId . '_term_' . $academicTermId . '.zip';
        $zipPath = __DIR__ . '/../../public/reports/' . $filename;
        
        if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
            throw new Exception('Cannot create ZIP file.');
        }
        
        foreach ($result['reports'] as $report) {
            if ($report['success']) {
                $pdfContent = $this->reportCardService->generatePDF(
                    $report['student_id'],
                    $academicYearId,
                    $academicTermId
                );
                $zip->addFromString(
                    $report['student_name'] . '.pdf',
                    $pdfContent
                );
            }
        }
        
        $zip->close();
        
        // Download ZIP file
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($zipPath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        
        readfile($zipPath);
        
        // Clean up
        unlink($zipPath);
        
    } catch (Exception $e) {
        echo '<h3>Error: ' . htmlspecialchars($e->getMessage()) . '</h3>';
    }
}

}