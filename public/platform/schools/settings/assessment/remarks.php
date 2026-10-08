<!-- Remarks Sub-tab -->
<div class="sub-tab-content active">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0"><i class="fas fa-comment-dots me-2 text-primary"></i>Remark Rules</h6>
        <button class="btn btn-primary btn-sm" onclick="createRemarkRule()">
            <i class="fas fa-plus me-2"></i> New Remark Rule
        </button>
    </div>

    <div id="remarksContainer">
        <div class="text-center py-4 text-muted">
            <i class="fas fa-spinner fa-spin fa-2x d-block mb-2"></i>
            Loading remark rules...
        </div>
    </div>
</div>

<script>
    // ============================================================
    // LOAD REMARKS
    // ============================================================
    function loadRemarks() {
        var container = document.getElementById('remarksContainer');
        if (!container) return;

        container.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-muted mt-2">Loading remark rules...</p>
            </div>
        `;

        // Use AssessmentService if available
        if (typeof AssessmentService !== 'undefined' && AssessmentService !== null) {
            AssessmentService.getRemarks()
                .then(function(remarks) {
                    renderRemarks(remarks);
                })
                .catch(function(error) {
                    console.error('Error loading remarks:', error);
                    container.innerHTML = `
                        <div class="alert alert-danger alert-custom">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            Error loading remarks: ${error.message}
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
            renderRemarks(getMockRemarks());
        }
    }

    // ============================================================
    // RENDER REMARKS
    // ============================================================
    function renderRemarks(remarks) {
        var container = document.getElementById('remarksContainer');
        if (!container) return;

        if (!remarks || remarks.length === 0) {
            container.innerHTML = `
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-comment-dots fa-2x d-block mb-2" style="color:#dee2e6;"></i>
                    <p>No remark rules defined.</p>
                    <button class="btn btn-primary btn-sm" onclick="createRemarkRule()">
                        <i class="fas fa-plus me-2"></i> Create First Remark
                    </button>
                </div>
            `;
            return;
        }

        var html = '';
        remarks.forEach(function(remark) {
            var remarkId = remark.remark_rule_id || remark.id;
            var scoreRange = (remark.min_score || 0) + ' - ' + (remark.max_score || 100);

            html += `
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom" style="gap:10px;flex-wrap:wrap;">
                    <div style="flex:1;">
                        <div style="font-weight:600;">${remark.rule_name || remark.name || 'Unnamed'}</div>
                        <div style="font-size:12px;color:#6c757d;">
                            <span class="badge bg-secondary">${remark.rule_code || remark.code || 'N/A'}</span>
                            <span class="badge bg-info">${scoreRange}</span>
                        </div>
                        <div style="font-size:12px;color:#495057;margin-top:2px;">
                            ${remark.remark_text || remark.description || ''}
                        </div>
                    </div>
                    <div style="display:flex;gap:4px;flex-wrap:wrap;">
                        <button class="btn btn-outline-primary btn-sm" onclick="editRemarkRule(${remarkId})"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-outline-danger btn-sm" onclick="deleteRemarkRule(${remarkId})"><i class="fas fa-trash"></i></button>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
    }

    // ============================================================
    // REMARK OPERATIONS
    // ============================================================
    function createRemarkRule() {
        showAlert('Opening remark rule editor... (Mock)', 'info');
        // In production, open a modal with the remark rule form
    }

    function editRemarkRule(id) {
        showAlert('Editing remark rule: ' + id + ' (Mock)', 'info');
        // In production, load the remark data and open modal
    }

    function deleteRemarkRule(id) {
        if (!confirm('Delete this remark rule?')) return;
        showAlert('Remark rule deleted! (Mock)', 'success');
        loadRemarks();
    }

    // ============================================================
    // INITIALIZE
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(loadRemarks, 500);
    });
</script>