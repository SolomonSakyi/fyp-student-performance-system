<!-- Grading Systems Section -->
<div class="grading-section">
    <div class="card-custom">
        <div class="card-header-custom">
            <h6><i class="fas fa-star me-2 text-primary"></i>Grading Systems</h6>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" onclick="loadGradingSystems()">
                    <i class="fas fa-sync-alt me-1"></i> Refresh
                </button>
                <button class="btn btn-primary btn-sm" onclick="showAddGradingSystem()">
                    <i class="fas fa-plus me-1"></i> New Grading System
                </button>
            </div>
        </div>
        <div class="card-body-custom">
            <div id="gradingSystemsListContainer">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2">Loading grading systems...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Grading System Modal -->
<div class="modal fade" id="gradingSystemModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-star me-2 text-primary"></i> <span id="gradingSystemModalTitle">Grading System</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="gradingSystemForm">
                    <input type="hidden" id="gradingSystemId">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">System Name <span class="required">*</span></label>
                                <input type="text" class="form-control" id="gsName" placeholder="e.g., WAEC Numeric" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">System Code <span class="required">*</span></label>
                                <input type="text" class="form-control" id="gsCode" placeholder="e.g., WAEC-NUM" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">System Type</label>
                                <select class="form-select" id="gsType">
                                    <option value="alphabetical">Alphabetical (A-F)</option>
                                    <option value="numeric">Numeric (1-9)</option>
                                    <option value="percentage">Percentage</option>
                                    <option value="waec">WAEC-Compatible</option>
                                    <option value="custom">Custom</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" id="gsStatus">
                                    <option value="draft">Draft</option>
                                    <option value="active">Active</option>
                                    <option value="archived">Archived</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" id="gsDescription" rows="2" placeholder="Description"></textarea>
                    </div>
                    <hr>
                    <h6 class="mb-2"><i class="fas fa-table me-2 text-primary"></i>Grade Scales</h6>
                    <div id="gradeScalesContainer">
                        <div class="text-center py-2 text-muted small">No grade scales added yet.</div>
                    </div>
                    <div class="mt-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="addGradeScaleRow()">
                            <i class="fas fa-plus me-1"></i> Add Grade Scale
                        </button>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveGradingSystem()">
                    <i class="fas fa-save me-2"></i> Save Grading System
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // ============================================================
    // MOCK DATA
    // ============================================================
    function getMockGradingSystems() {
        return [{
                'grading_system_id': 1,
                'system_name': 'WAEC Numeric Grading',
                'system_code': 'WAEC-NUM',
                'system_type': 'numeric',
                'is_default': 1,
                'status': 'active'
            },
            {
                'grading_system_id': 2,
                'system_name': 'Alphabetical A-F',
                'system_code': 'ALPHA-AF',
                'system_type': 'alphabetical',
                'is_default': 0,
                'status': 'active'
            }
        ];
    }

    // ============================================================
    // LOAD GRADING SYSTEMS
    // ============================================================
    function loadGradingSystems() {
        var container = document.getElementById('gradingSystemsListContainer');
        if (!container) return;

        container.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-muted mt-2">Loading grading systems...</p>
            </div>
        `;

        setTimeout(function() {
            var systems = getMockGradingSystems();
            renderGradingSystems(systems);
        }, 300);
    }

    // ============================================================
    // RENDER GRADING SYSTEMS
    // ============================================================
    function renderGradingSystems(systems) {
        var container = document.getElementById('gradingSystemsListContainer');
        if (!container) return;

        if (systems.length === 0) {
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="fas fa-star fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No grading systems created yet.</p>
                    <button class="btn btn-primary" onclick="showAddGradingSystem()">
                        <i class="fas fa-plus me-2"></i> Create First Grading System
                    </button>
                </div>
            `;
            return;
        }

        var html = '<div class="row g-3">';
        systems.forEach(function(system) {
            var statusBadge = system.status === 'active' ? 'success' :
                system.status === 'draft' ? 'warning' : 'secondary';
            var systemId = system.grading_system_id;

            html += `
                <div class="col-md-6">
                    <div class="grading-system-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1">${system.system_name}</h6>
                                <span class="badge bg-secondary">${system.system_code}</span>
                                <span class="badge bg-info">${system.system_type}</span>
                                <span class="badge bg-${statusBadge}">${system.status}</span>
                                ${system.is_default ? '<span class="badge bg-primary">Default</span>' : ''}
                            </div>
                            <div class="d-flex gap-1">
                                <button class="btn btn-outline-primary btn-sm" onclick="editGradingSystem(${systemId})"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-outline-success btn-sm" onclick="setDefaultGradingSystem(${systemId})"><i class="fas fa-check"></i></button>
                            </div>
                        </div>
                        <div class="text-muted small mt-1">${system.description || ''}</div>
                    </div>
                </div>
            `;
        });
        html += '</div>';

        container.innerHTML = html;
    }

    // ============================================================
    // ADD GRADE SCALE ROW
    // ============================================================
    function addGradeScaleRow() {
        var container = document.getElementById('gradeScalesContainer');
        if (!container) return;

        var emptyMsg = container.querySelector('.text-muted');
        if (emptyMsg) {
            container.innerHTML = '';
        }

        var html = `
            <div class="grade-scale-row d-flex gap-2 align-items-center mb-2" style="background:#f8f9fa;padding:8px 12px;border-radius:8px;">
                <input type="text" class="form-control form-control-sm" placeholder="Grade" style="flex:0.5;min-width:60px;">
                <input type="number" class="form-control form-control-sm" placeholder="Min" style="flex:0.5;min-width:60px;" step="0.01">
                <input type="number" class="form-control form-control-sm" placeholder="Max" style="flex:0.5;min-width:60px;" step="0.01">
                <input type="number" class="form-control form-control-sm" placeholder="Points" style="flex:0.5;min-width:60px;" step="0.01">
                <input type="text" class="form-control form-control-sm" placeholder="Remark" style="flex:1;">
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeGradeScaleRow(this)"><i class="fas fa-times"></i></button>
            </div>
        `;
        container.insertAdjacentHTML('beforeend', html);
    }

    function removeGradeScaleRow(btn) {
        var row = btn.closest('.grade-scale-row');
        if (row) row.remove();
    }

    // ============================================================
    // SHOW ADD GRADING SYSTEM - OPENS MODAL
    // ============================================================
    function showAddGradingSystem() {
        document.getElementById('gradingSystemId').value = '';
        document.getElementById('gradingSystemModalTitle').textContent = 'Create Grading System';
        document.getElementById('gsName').value = '';
        document.getElementById('gsCode').value = '';
        document.getElementById('gsType').value = 'numeric';
        document.getElementById('gsStatus').value = 'draft';
        document.getElementById('gsDescription').value = '';
        document.getElementById('gradeScalesContainer').innerHTML = '<div class="text-center py-2 text-muted small">No grade scales added yet.</div>';

        var modal = new bootstrap.Modal(document.getElementById('gradingSystemModal'));
        modal.show();
    }

    // ============================================================
    // EDIT GRADING SYSTEM - OPENS MODAL WITH DATA
    // ============================================================
    function editGradingSystem(id) {
        var systems = getMockGradingSystems();
        var system = null;
        for (var i = 0; i < systems.length; i++) {
            if (systems[i].grading_system_id === id) {
                system = systems[i];
                break;
            }
        }

        if (system) {
            document.getElementById('gradingSystemId').value = system.grading_system_id;
            document.getElementById('gradingSystemModalTitle').textContent = 'Edit Grading System';
            document.getElementById('gsName').value = system.system_name;
            document.getElementById('gsCode').value = system.system_code;
            document.getElementById('gsType').value = system.system_type;
            document.getElementById('gsStatus').value = system.status || 'draft';
            document.getElementById('gsDescription').value = system.description || '';

            var modal = new bootstrap.Modal(document.getElementById('gradingSystemModal'));
            modal.show();
        } else {
            showAlert('Grading system not found', 'danger');
        }
    }

    // ============================================================
    // SAVE GRADING SYSTEM
    // ============================================================
    function saveGradingSystem() {
        var name = document.getElementById('gsName').value.trim();
        var code = document.getElementById('gsCode').value.trim();

        if (!name || !code) {
            showAlert('System name and code are required', 'danger');
            return;
        }

        showAlert('Grading system saved successfully!', 'success');
        var modal = bootstrap.Modal.getInstance(document.getElementById('gradingSystemModal'));
        if (modal) modal.hide();
        loadGradingSystems();
    }

    // ============================================================
    // SET DEFAULT GRADING SYSTEM
    // ============================================================
    function setDefaultGradingSystem(id) {
        if (!confirm('Set this as the default grading system?')) return;
        showAlert('Default grading system updated!', 'success');
        loadGradingSystems();
    }

    // ============================================================
    // INITIALIZE
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        loadGradingSystems();
    });
</script>

<style>
    .grading-system-card {
        background: #fff;
        border-radius: 10px;
        padding: 16px 20px;
        border: 1px solid #e9ecf;
        transition: all 0.3s;
        height: 100%;
    }

    .grading-system-card:hover {
        border-color: #4facfe;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
    }

    .grade-scale-row {
        background: #f8f9fa;
        padding: 8px 12px;
        border-radius: 8px;
    }

    .grade-scale-row input {
        border: 1px solid #e9ecf;
        border-radius: 6px;
        padding: 6px 10px;
        font-size: 13px;
    }

    .grade-scale-row input:focus {
        border-color: #4facfe;
        outline: none;
    }
</style>