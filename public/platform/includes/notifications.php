<?php

/**
 * Identity Module - Notification Templates
 * 
 * @package EduTrack
 * @subpackage Identity\Includes
 * @version 2.0
 * @filepath public/identity/includes/notifications.php
 */

/**
 * Render a success notification
 */
function renderSuccessNotification($message, $dismissible = true)
{
    $dismiss = $dismissible ? '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' : '';
    return '
        <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius:12px;border:none;box-shadow:0 4px 20px rgba(0,0,0,0.06);">
            <i class="fas fa-check-circle me-2"></i> ' . htmlspecialchars($message) . '
            ' . $dismiss . '
        </div>
    ';
}

/**
 * Render an error notification
 */
function renderErrorNotification($message, $dismissible = true)
{
    $dismiss = $dismissible ? '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' : '';
    return '
        <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius:12px;border:none;box-shadow:0 4px 20px rgba(0,0,0,0.06);">
            <i class="fas fa-exclamation-circle me-2"></i> ' . htmlspecialchars($message) . '
            ' . $dismiss . '
        </div>
    ';
}

/**
 * Render a warning notification
 */
function renderWarningNotification($message, $dismissible = true)
{
    $dismiss = $dismissible ? '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' : '';
    return '
        <div class="alert alert-warning alert-dismissible fade show" role="alert" style="border-radius:12px;border:none;box-shadow:0 4px 20px rgba(0,0,0,0.06);">
            <i class="fas fa-exclamation-triangle me-2"></i> ' . htmlspecialchars($message) . '
            ' . $dismiss . '
        </div>
    ';
}

/**
 * Render an info notification
 */
function renderInfoNotification($message, $dismissible = true)
{
    $dismiss = $dismissible ? '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' : '';
    return '
        <div class="alert alert-info alert-dismissible fade show" role="alert" style="border-radius:12px;border:none;box-shadow:0 4px 20px rgba(0,0,0,0.06);">
            <i class="fas fa-info-circle me-2"></i> ' . htmlspecialchars($message) . '
            ' . $dismiss . '
        </div>
    ';
}

/**
 * Render a confirmation dialog
 */
function renderConfirmationDialog($title, $message, $confirmText = 'Confirm', $cancelText = 'Cancel')
{
    return '
        <div class="modal fade" id="confirmationModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,0.15);">
                    <div class="modal-body text-center" style="padding:32px;">
                        <div style="font-size:48px;color:#fa709a;margin-bottom:16px;">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <h5 class="fw-bold">' . htmlspecialchars($title) . '</h5>
                        <p class="text-muted">' . htmlspecialchars($message) . '</p>
                    </div>
                    <div class="modal-footer" style="border-top:none;padding:0 32px 32px;justify-content:center;gap:12px;">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="border-radius:10px;padding:8px 28px;font-weight:500;">
                            ' . htmlspecialchars($cancelText) . '
                        </button>
                        <button type="button" class="btn btn-danger" id="confirmActionBtn" style="border-radius:10px;padding:8px 28px;font-weight:500;">
                            ' . htmlspecialchars($confirmText) . '
                        </button>
                    </div>
                </div>
            </div>
        </div>
    ';
}

/**
 * Render a loading spinner with overlay
 */
function renderLoadingOverlay($message = 'Processing...')
{
    return '
        <div id="loadingOverlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:16px;">
            <div class="spinner-border text-light" style="width:48px;height:48px;" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div style="color:#fff;font-weight:500;font-size:16px;">' . htmlspecialchars($message) . '</div>
        </div>
    ';
}

/**
 * Render a quick stats card
 */
function renderQuickStatsCard($label, $value, $icon, $color = 'primary')
{
    $colors = [
        'primary' => 'blue',
        'success' => 'green',
        'warning' => 'yellow',
        'danger' => 'red',
        'info' => 'purple'
    ];

    $colorClass = $colors[$color] ?? 'blue';

    return '
        <div class="stat-card">
            <div class="stat-icon ' . $colorClass . '"><i class="fas ' . $icon . '"></i></div>
            <div class="stat-info">
                <div class="stat-number">' . number_format($value) . '</div>
                <div class="stat-label">' . htmlspecialchars($label) . '</div>
            </div>
        </div>
    ';
}

/**
 * Render a filter dropdown
 */
function renderFilterDropdown($name, $options, $selected = '', $label = '')
{
    $html = '';
    if ($label) {
        $html .= '<label class="form-label small fw-medium mb-1">' . htmlspecialchars($label) . '</label>';
    }

    $html .= '<select class="form-select" name="' . htmlspecialchars($name) . '" id="filter_' . htmlspecialchars($name) . '">';
    foreach ($options as $value => $display) {
        $selectedAttr = ($value == $selected) ? 'selected' : '';
        $html .= '<option value="' . htmlspecialchars($value) . '" ' . $selectedAttr . '>' . htmlspecialchars($display) . '</option>';
    }
    $html .= '</select>';

    return $html;
}

/**
 * Render a pagination component
 */
function renderPagination($currentPage, $totalPages, $totalItems, $perPage, $baseUrl = '')
{
    if ($totalPages <= 1) {
        return '<div class="text-muted small">Showing ' . number_format($totalItems) . ' items</div>';
    }

    $start = (($currentPage - 1) * $perPage) + 1;
    $end = min($currentPage * $perPage, $totalItems);

    $html = '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">';
    $html .= '<div class="text-muted small">Showing ' . number_format($start) . ' to ' . number_format($end) . ' of ' . number_format($totalItems) . ' entries</div>';
    $html .= '<nav><ul class="pagination pagination-sm mb-0">';

    // Previous
    $html .= '<li class="page-item ' . ($currentPage <= 1 ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $baseUrl . '&page=' . ($currentPage - 1) . '"><i class="fas fa-chevron-left"></i></a>';
    $html .= '</li>';

    // Page numbers
    for ($i = 1; $i <= $totalPages; $i++) {
        if ($i == $currentPage) {
            $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } elseif ($i == 1 || $i == $totalPages || abs($i - $currentPage) <= 2) {
            $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '&page=' . $i . '">' . $i . '</a></li>';
        } elseif ($i == 2 && $currentPage > 4) {
            $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        } elseif ($i == $totalPages - 1 && $currentPage < $totalPages - 3) {
            $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
    }

    // Next
    $html .= '<li class="page-item ' . ($currentPage >= $totalPages ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $baseUrl . '&page=' . ($currentPage + 1) . '"><i class="fas fa-chevron-right"></i></a>';
    $html .= '</li>';

    $html .= '</ul></nav></div>';

    return $html;
}
