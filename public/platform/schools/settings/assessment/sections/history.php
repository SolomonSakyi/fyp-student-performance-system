<!-- Version History Section -->
<div class="history-section">
    <div class="card-custom">
        <div class="card-header-custom">
            <h6><i class="fas fa-history me-2 text-primary"></i>Version History</h6>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" onclick="loadHistory()">
                    <i class="fas fa-sync-alt me-1"></i> Refresh
                </button>
                <button class="btn btn-outline-secondary btn-sm" onclick="clearHistory()">
                    <i class="fas fa-trash me-1"></i> Clear
                </button>
            </div>
        </div>
        <div class="card-body-custom">
            <div id="historyListContainer">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2">Loading history...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // ============================================================
    // MOCK DATA
    // ============================================================
    function getMockHistory() {
        return [{
                'id': 1,
                'action': 'created',
                'field_name': 'JHS WAEC Profile',
                'old_value': null,
                'new_value': 'Active',
                'changed_by': 'Admin User',
                'created_at': new Date().toISOString()
            },
            {
                'id': 2,
                'action': 'updated',
                'field_name': 'Primary Standard Profile',
                'old_value': 'Draft',
                'new_value': 'Active',
                'changed_by': 'Admin User',
                'created_at': new Date(Date.now() - 3600000).toISOString()
            },
            {
                'id': 3,
                'action': 'created',
                'field_name': 'SHS WAEC Profile',
                'old_value': null,
                'new_value': 'Draft',
                'changed_by': 'Admin User',
                'created_at': new Date(Date.now() - 7200000).toISOString()
            }
        ];
    }

    // ============================================================
    // LOAD HISTORY
    // ============================================================
    function loadHistory() {
        var container = document.getElementById('historyListContainer');
        if (!container) return;

        container.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-muted mt-2">Loading history...</p>
            </div>
        `;

        setTimeout(function() {
            var logs = getMockHistory();
            renderHistory(logs);
        }, 300);
    }

    // ============================================================
    // RENDER HISTORY
    // ============================================================
    function renderHistory(logs) {
        var container = document.getElementById('historyListContainer');
        if (!container) return;

        if (logs.length === 0) {
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="fas fa-clock fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No changes recorded yet.</p>
                </div>
            `;
            return;
        }

        var html = `
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Item</th>
                            <th>Details</th>
                            <th>User</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        logs.forEach(function(log) {
            var actionBadge = log.action === 'created' ? 'success' :
                log.action === 'updated' ? 'primary' :
                log.action === 'deleted' ? 'danger' : 'secondary';

            var details = '';
            if (log.old_value && log.new_value) {
                details = log.old_value + ' → ' + log.new_value;
            } else if (log.new_value) {
                details = '→ ' + log.new_value;
            } else {
                details = '—';
            }

            html += `
                <tr>
                    <td><span class="badge bg-${actionBadge}">${log.action}</span></td>
                    <td><strong>${log.field_name}</strong></td>
                    <td class="text-muted small">${details}</td>
                    <td>${log.changed_by || 'System'}</td>
                    <td class="text-muted small">${new Date(log.created_at).toLocaleString()}</td>
                </tr>
            `;
        });

        html += `</tbody></table></div>`;

        container.innerHTML = html;
    }

    // ============================================================
    // CLEAR HISTORY
    // ============================================================
    function clearHistory() {
        if (!confirm('Clear all history records?')) return;
        showAlert('History cleared!', 'success');
        loadHistory();
    }

    // ============================================================
    // INITIALIZE
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        loadHistory();
    });
</script>