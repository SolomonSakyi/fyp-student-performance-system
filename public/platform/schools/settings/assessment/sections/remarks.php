<!-- Remarks Section -->
<div class="remarks-section">
    <div class="card-custom">
        <div class="card-header-custom">
            <h6><i class="fas fa-comment-dots me-2 text-primary"></i>Remark Rules</h6>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" onclick="loadRemarks()">
                    <i class="fas fa-sync-alt me-1"></i> Refresh
                </button>
                <button class="btn btn-primary btn-sm" onclick="showAddRemarkRule()">
                    <i class="fas fa-plus me-1"></i> New Remark Rule
                </button>
            </div>
        </div>
        <div class="card-body-custom">
            <div id="remarksListContainer">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2">Loading remark rules...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Remark Rule Modal -->
<div class="modal fade" id="remarkRuleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-comment-dots me-2 text-primary"></i> <span id="remarkRuleModalTitle">Remark Rule</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="remarkRuleForm">
                    <input type="hidden" id="remarkRuleId">
                    <div class="mb-3">
                        <label class="form-label">Rule Name <span class="required">*</span></label>
                        <input type="text" class="form-control" id="rrName" placeholder="e.g., Excellent Performance" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Rule Code <span class="required">*</span></label>
                        <input type="text" class="form-control" id="rrCode" placeholder="e.g., EXC" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Min Score</label>
                                <input type="number" class="form-control" id="rrMinScore" value="0" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Max Score</label>
                                <input type="number" class="form-control" id="rrMaxScore" value="100" step="0.01">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remark Text <span class="required">*</span></label>
                        <textarea class="form-control" id="rrRemarkText" rows="3" placeholder="The remark text..." required></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveRemarkRule()">
                    <i class="fas fa-save me-2"></i> Save Remark
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // ============================================================
    // MOCK DATA
    // ============================================================
    function getMockRemarks() {
        return [{
                'remark_rule_id': 1,
                'rule_name': 'Excellent Performance',
                'rule_code': 'EXC',
                'min_score': 80,
                'max_score': 100,
                'remark_text': 'Excellent performance. Student shows outstanding understanding.'
            },
            {
                'remark_rule_id': 2,
                'rule_name': 'Very Good Performance',
                'rule_code': 'VG',
                'min_score': 70,
                'max_score': 79,
                'remark_text': 'Very good performance. Student demonstrates strong understanding.'
            },
            {
                'remark_rule_id': 3,
                'rule_name': 'Good Performance',
                'rule_code': 'GD',
                'min_score': 60,
                'max_score': 69,
                'remark_text': 'Good performance. Student has a solid grasp of concepts.'
            }
        ];
    }

    // ============================================================
    // LOAD REMARKS
    // ============================================================
    function loadRemarks() {
        var container = document.getElementById('remarksListContainer');
        if (!container) return;

        container.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-muted mt-2">Loading remark rules...</p>
            </div>
        `;

        setTimeout(function() {
            var remarks = getMockRemarks();
            renderRemarks(remarks);
        }, 300);
    }

    // ============================================================
    // RENDER REMARKS
    // ============================================================
    function renderRemarks(remarks) {
        var container = document.getElementById('remarksListContainer');
        if (!container) return;

        if (remarks.length === 0) {
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="fas fa-comment-dots fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No remark rules defined.</p>
                    <button class="btn btn-primary" onclick="showAddRemarkRule()">
                        <i class="fas fa-plus me-2"></i> Create First Remark
                    </button>
                </div>
            `;
            return;
        }

        var html = '<div class="row g-3">';
        remarks.forEach(function(remark) {
            var scoreRange = (remark.min_score || 0) + ' - ' + (remark.max_score || 100);
            var remarkId = remark.remark_rule_id;

            html += `
                <div class="col-md-6">
                    <div class="remark-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1">${remark.rule_name}</h6>
                                <span class="badge bg-secondary">${remark.rule_code}</span>
                                <span class="badge bg-info">${scoreRange}</span>
                            </div>
                            <div class="d-flex gap-1">
                                <button class="btn btn-outline-primary btn-sm" onclick="editRemarkRule(${remarkId})"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-outline-danger btn-sm" onclick="deleteRemarkRule(${remarkId})"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                        <div class="remark-text">${remark.remark_text}</div>
                    </div>
                </div>
            `;
        });
        html += '</div>';

        container.innerHTML = html;
    }

    // ============================================================
    // SHOW ADD REMARK RULE - OPENS MODAL
    // ============================================================
    function showAddRemarkRule() {
        document.getElementById('remarkRuleId').value = '';
        document.getElementById('remarkRuleModalTitle').textContent = 'Create Remark Rule';
        document.getElementById('rrName').value = '';
        document.getElementById('rrCode').value = '';
        document.getElementById('rrMinScore').value = 0;
        document.getElementById('rrMaxScore').value = 100;
        document.getElementById('rrRemarkText').value = '';

        var modal = new bootstrap.Modal(document.getElementById('remarkRuleModal'));
        modal.show();
    }

    // ============================================================
    // EDIT REMARK RULE - OPENS MODAL WITH DATA
    // ============================================================
    function editRemarkRule(id) {
        var remarks = getMockRemarks();
        var remark = null;
        for (var i = 0; i < remarks.length; i++) {
            if (remarks[i].remark_rule_id === id) {
                remark = remarks[i];
                break;
            }
        }

        if (remark) {
            document.getElementById('remarkRuleId').value = remark.remark_rule_id;
            document.getElementById('remarkRuleModalTitle').textContent = 'Edit Remark Rule';
            document.getElementById('rrName').value = remark.rule_name;
            document.getElementById('rrCode').value = remark.rule_code;
            document.getElementById('rrMinScore').value = remark.min_score;
            document.getElementById('rrMaxScore').value = remark.max_score;
            document.getElementById('rrRemarkText').value = remark.remark_text;

            var modal = new bootstrap.Modal(document.getElementById('remarkRuleModal'));
            modal.show();
        } else {
            showAlert('Remark rule not found', 'danger');
        }
    }

    // ============================================================
    // SAVE REMARK RULE
    // ============================================================
    function saveRemarkRule() {
        var name = document.getElementById('rrName').value.trim();
        var code = document.getElementById('rrCode').value.trim();
        var text = document.getElementById('rrRemarkText').value.trim();

        if (!name || !code || !text) {
            showAlert('Name, code, and remark text are required', 'danger');
            return;
        }

        showAlert('Remark rule saved successfully!', 'success');
        var modal = bootstrap.Modal.getInstance(document.getElementById('remarkRuleModal'));
        if (modal) modal.hide();
        loadRemarks();
    }

    // ============================================================
    // DELETE REMARK RULE
    // ============================================================
    function deleteRemarkRule(id) {
        if (!confirm('Delete this remark rule?')) return;
        showAlert('Remark rule deleted!', 'success');
        loadRemarks();
    }

    // ============================================================
    // INITIALIZE
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        loadRemarks();
    });
</script>

<style>
    .remark-card {
        background: #fff;
        border-radius: 10px;
        padding: 16px 20px;
        border: 1px solid #e9ecf;
        transition: all 0.3s;
        height: 100%;
    }

    .remark-card:hover {
        border-color: #4facfe;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
    }

    .remark-text {
        font-size: 13px;
        color: #495057;
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px solid #f0f2f5;
    }
</style>