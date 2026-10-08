<!-- Assessment Components Section -->
<div class="components-section">
    <div class="card-custom">
        <div class="card-header-custom">
            <h6><i class="fas fa-cubes me-2 text-primary"></i>Assessment Components</h6>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" onclick="loadComponents()">
                    <i class="fas fa-sync-alt me-1"></i> Refresh
                </button>
                <button class="btn btn-primary btn-sm" onclick="showAddComponent()">
                    <i class="fas fa-plus me-1"></i> New Component
                </button>
            </div>
        </div>
        <div class="card-body-custom">
            <div id="componentsListContainer">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2">Loading components...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Component Modal -->
<div class="modal fade" id="componentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cubes me-2 text-primary"></i> <span id="componentModalTitle">Assessment Component</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="componentForm">
                    <input type="hidden" id="componentId">
                    <div class="mb-3">
                        <label class="form-label">Component Name <span class="required">*</span></label>
                        <input type="text" class="form-control" id="componentName" placeholder="e.g., Class Assessment" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Component Code <span class="required">*</span></label>
                        <input type="text" class="form-control" id="componentCode" placeholder="e.g., CA" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Max Score</label>
                                <input type="number" class="form-control" id="componentMaxScore" value="100" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Default Weight (%)</label>
                                <input type="number" class="form-control" id="componentWeight" value="0" step="0.01">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" id="componentDescription" rows="2" placeholder="Brief description"></textarea>
                    </div>
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="componentRequired" checked>
                        <label class="form-check-label" for="componentRequired">Required Component</label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveComponent()">
                    <i class="fas fa-save me-2"></i> Save Component
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // ============================================================
    // MOCK DATA
    // ============================================================
    function getMockComponents() {
        return [{
                'component_id': 1,
                'component_name': 'Class Assessment',
                'component_code': 'CA',
                'max_score': 30,
                'default_weight': 30,
                'is_required': 1
            },
            {
                'component_id': 2,
                'component_name': 'Examination',
                'component_code': 'EXAM',
                'max_score': 70,
                'default_weight': 70,
                'is_required': 1
            },
            {
                'component_id': 3,
                'component_name': 'Project Work',
                'component_code': 'PROJ',
                'max_score': 20,
                'default_weight': 20,
                'is_required': 0
            }
        ];
    }

    // ============================================================
    // LOAD COMPONENTS
    // ============================================================
    function loadComponents() {
        var container = document.getElementById('componentsListContainer');
        if (!container) return;

        container.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-muted mt-2">Loading components...</p>
            </div>
        `;

        setTimeout(function() {
            var components = getMockComponents();
            renderComponents(components);
        }, 300);
    }

    // ============================================================
    // RENDER COMPONENTS
    // ============================================================
    function renderComponents(components) {
        var container = document.getElementById('componentsListContainer');
        if (!container) return;

        if (components.length === 0) {
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="fas fa-cubes fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No assessment components defined.</p>
                    <button class="btn btn-primary" onclick="showAddComponent()">
                        <i class="fas fa-plus me-2"></i> Create First Component
                    </button>
                </div>
            `;
            return;
        }

        var html = '<div class="table-responsive"><table class="table table-hover">';
        html += `<thead><tr>
            <th>Name</th>
            <th>Code</th>
            <th>Max Score</th>
            <th>Default Weight</th>
            <th>Required</th>
            <th>Actions</th>
        </tr></thead><tbody>`;

        components.forEach(function(comp) {
            html += `
                <tr>
                    <td><strong>${comp.component_name}</strong></td>
                    <td><span class="badge bg-secondary">${comp.component_code}</span></td>
                    <td>${comp.max_score}</td>
                    <td>${comp.default_weight}%</td>
                    <td>${comp.is_required ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'}</td>
                    <td>
                        <button class="btn btn-outline-primary btn-sm" onclick="editComponent(${comp.component_id})"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-outline-danger btn-sm" onclick="deleteComponent(${comp.component_id})"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
            `;
        });

        html += '</tbody></table></div>';
        container.innerHTML = html;
    }

    // ============================================================
    // SHOW ADD COMPONENT - OPENS MODAL
    // ============================================================
    function showAddComponent() {
        document.getElementById('componentId').value = '';
        document.getElementById('componentModalTitle').textContent = 'Create Assessment Component';
        document.getElementById('componentName').value = '';
        document.getElementById('componentCode').value = '';
        document.getElementById('componentMaxScore').value = 100;
        document.getElementById('componentWeight').value = 0;
        document.getElementById('componentDescription').value = '';
        document.getElementById('componentRequired').checked = true;

        var modal = new bootstrap.Modal(document.getElementById('componentModal'));
        modal.show();
    }

    // ============================================================
    // EDIT COMPONENT - OPENS MODAL WITH DATA
    // ============================================================
    function editComponent(id) {
        var components = getMockComponents();
        var comp = null;
        for (var i = 0; i < components.length; i++) {
            if (components[i].component_id === id) {
                comp = components[i];
                break;
            }
        }

        if (comp) {
            document.getElementById('componentId').value = comp.component_id;
            document.getElementById('componentModalTitle').textContent = 'Edit Assessment Component';
            document.getElementById('componentName').value = comp.component_name;
            document.getElementById('componentCode').value = comp.component_code;
            document.getElementById('componentMaxScore').value = comp.max_score;
            document.getElementById('componentWeight').value = comp.default_weight;
            document.getElementById('componentDescription').value = comp.description || '';
            document.getElementById('componentRequired').checked = comp.is_required;

            var modal = new bootstrap.Modal(document.getElementById('componentModal'));
            modal.show();
        } else {
            showAlert('Component not found', 'danger');
        }
    }

    // ============================================================
    // SAVE COMPONENT
    // ============================================================
    function saveComponent() {
        var name = document.getElementById('componentName').value.trim();
        var code = document.getElementById('componentCode').value.trim();

        if (!name || !code) {
            showAlert('Component name and code are required', 'danger');
            return;
        }

        showAlert('Component saved successfully!', 'success');
        var modal = bootstrap.Modal.getInstance(document.getElementById('componentModal'));
        if (modal) modal.hide();
        loadComponents();
    }

    // ============================================================
    // DELETE COMPONENT
    // ============================================================
    function deleteComponent(id) {
        if (!confirm('Delete this component?')) return;
        showAlert('Component deleted!', 'success');
        loadComponents();
    }

    // ============================================================
    // INITIALIZE
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        loadComponents();
    });
</script>