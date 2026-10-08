<!-- Version History Sub-tab -->
<div class="sub-tab-content active">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0"><i class="fas fa-history me-2 text-primary"></i>Version History</h6>
        <button class="btn btn-outline-secondary btn-sm" onclick="loadHistory()">
            <i class="fas fa-sync-alt me-1"></i> Refresh
        </button>
    </div>

    <div id="historyContainer">
        <div class="text-center py-3 text-muted">
            <i class="fas fa-spinner fa-spin me-2"></i> Loading history...
        </div>
    </div>
</div>

<script>
    // ============================================================
    // LOAD HISTORY
    // ============================================================
    function loadHistory() {
        var container = document.getElementById('historyContainer');
        if (!container) return;

        container.innerHTML = `
            <div class="text-center py-3 text-muted">
                <i class="fas fa-spinner fa-spin me-2"></i> Loading history...
            </div>
        `;

        // Use AssessmentService if available
        if (typeof AssessmentService !== 'undefined' && AssessmentService !== null) {
            AssessmentService.getAuditLogs(20)
                .then(function(logs) {
                    renderHistory(logs);
                })
                .catch(function(error) {
                    console.error('Error loading history:', error);
                    container.innerHTML = `
                        <div class="alert alert-danger alert-custom">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            Error loading history: ${error.message}
                        </div>
                    `;
                });
        } else {
            container.innerHTML = `
                <div class="alert alert-warning alert-custom">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Service not loaded. Using mock data.
                </div>
            `;
            renderHistory(getMockAuditLogs());
        }
    }

    // ============================================================
    // RENDER HISTORY
    // ============================================================
    function renderHistory(logs) {
        var container = document.getElementById('historyContainer');
        if (!container) return;

        if (!logs || logs.length === 0) {
            container.innerHTML = `
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-clock fa-3x d-block mb-3" style="color:#dee2e6;"></i>
                    <p>No assessment changes recorded yet.</p>
                    <p class="small">Changes will appear here when you create or modify assessment configurations.</p>
                </div>
            `;
            return;
        }

        var html = '';
        logs.forEach(function(item) {
            var actionClass = item.action === 'created' ? 'success' :
                item.action === 'updated' ? 'primary' :
                item.action === 'deleted' ? 'danger' : 'secondary';

            html += `
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom" style="font-size:13px;">
                    <div>
                        <span class="badge bg-${actionClass} me-2">${item.action || 'update'}</span>
                        <span class="text-muted">${item.field_name || 'Profile'}</span>
                        ${item.old_value ? `<span class="text-muted">${item.old_value} → ${item.new_value}</span>` : ''}
                    </div>
                    <div class="text-muted" style="font-size:12px;text-align:right;">
                        <div>${item.changed_by || 'System'}</div>
                        <div>${item.created_at ? new Date(item.created_at).toLocaleString() : 'N/A'}</div>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
    }

    // ============================================================
    // INITIALIZE
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(loadHistory, 500);
    });
</script>