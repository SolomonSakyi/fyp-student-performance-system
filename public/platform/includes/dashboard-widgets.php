<?php

/**
 * Dashboard Widgets - People Statistics Widget
 * 
 * @package EduTrack
 * @subpackage Platform\Includes
 * @version 2.0
 * @filepath public/platform/includes/dashboard-widgets.php
 */

/**
 * Render People Statistics Widget for Dashboard
 */
function renderPeopleStatsWidget()
{
    $apiBase = getApiBase();
?>
    <div class="col-xl-4 col-lg-6 col-12 mb-4">
        <div class="activity-card" style="height:100%;">
            <div class="card-header">
                <span><i class="fas fa-user-friends me-2 text-primary"></i> People Overview</span>
                <a href="/identity/index.php" class="text-primary small" style="text-decoration:none;">
                    View All <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </div>
            <div class="card-body" id="peopleWidgetStats" style="padding:16px 20px;">
                <div class="text-center py-3 text-muted">
                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                    Loading stats...
                </div>
            </div>
            <div style="padding:0 20px 16px;">
                <div class="d-flex gap-2 flex-wrap">
                    <a href="/identity/create.php" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-plus me-1"></i> Add Person
                    </a>
                    <a href="/identity/students.php" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-user-graduate me-1"></i> Students
                    </a>
                    <a href="/identity/staff.php" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-user-tie me-1"></i> Staff
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Load people stats for dashboard widget
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('peopleWidgetStats');
            if (!container) return;

            const token = localStorage.getItem('token') || '';
            const apiBase = '<?php echo $apiBase; ?>';

            fetch(apiBase + '/index.php?endpoint=identity&action=stats', {
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Content-Type': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success && result.data) {
                        const stats = result.data;
                        container.innerHTML = `
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="p-2 bg-light rounded text-center">
                                    <div class="h5 mb-0 fw-bold text-primary">${stats.total || 0}</div>
                                    <div class="small text-muted">Total People</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-2 bg-light rounded text-center">
                                    <div class="h5 mb-0 fw-bold text-success">${stats.active || 0}</div>
                                    <div class="small text-muted">Active</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-2 bg-light rounded text-center">
                                    <div class="h5 mb-0 fw-bold text-warning">${stats.pending || 0}</div>
                                    <div class="small text-muted">Pending</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-2 bg-light rounded text-center">
                                    <div class="h5 mb-0 fw-bold text-danger">${stats.inactive || 0}</div>
                                    <div class="small text-muted">Inactive</div>
                                </div>
                            </div>
                        </div>
                    `;
                    } else {
                        container.innerHTML = `
                        <div class="text-center py-3 text-muted">
                            <i class="fas fa-exclamation-circle me-2"></i> Failed to load stats
                        </div>
                    `;
                    }
                })
                .catch(error => {
                    console.error('Error loading people stats:', error);
                    container.innerHTML = `
                    <div class="text-center py-3 text-muted">
                        <i class="fas fa-exclamation-circle me-2"></i> Error loading stats
                    </div>
                `;
                });
        });
    </script>
<?php
}

/**
 * Get API base URL
 */
function getApiBase()
{
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    return $protocol . '://' . $host . '/api/platform';
}
?>