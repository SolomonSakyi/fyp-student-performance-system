<!-- Assessment Components Sub-tab -->
<div class="sub-tab-content active">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0"><i class="fas fa-cubes me-2 text-primary"></i>Assessment Components</h6>
        <button class="btn btn-primary btn-sm" onclick="createComponent()">
            <i class="fas fa-plus me-2"></i> New Component
        </button>
    </div>

    <div id="componentsContainer">
        <div class="text-center py-4 text-muted">
            <i class="fas fa-spinner fa-spin fa-2x d-block mb-2"></i>
            Loading components...
        </div>
    </div>
</div>

<script>
    // ============================================================
    // LOAD COMPONENTS
    // ============================================================
    function loadComponents() {
        var container = document.getElementById('componentsContainer');
        if (!container) return;

        container.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-muted mt-2">Loading components...</p>
            </div>
        `;

        // Use AssessmentService if available
        if (typeof AssessmentService !== 'undefined' && AssessmentService !== null) {
            AssessmentService.getComponents()
                .then(function(components) {
                    renderComponents(components);
                })
                .catch(function(error) {
                    console.error('Error loading components:', error);
                    container.innerHTML = `
                        <div class="alert alert-danger alert-custom">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            Error loading components: ${error.message}
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
            renderComponents(getMockComponents());
        }
    }

    // ============================================================
    // RENDER COMPONENTS
    // ============================================================
    function renderComponents(components) {
        var container = document.getElementById('componentsContainer');
        if (!container) return;

        if (!components || components.length === 0) {
            container.innerHTML = `
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-cubes fa-2x d-block mb-2" style="color:#dee2e6;"></i>
                    <p>No assessment components defined.</p>
                    <button class="btn btn-primary btn-sm" onclick="createComponent()">
                        <i class="fas fa-plus me-2"></i> Create First Component
                    </button>
                </div>
            `;
            return;
        }

        var html = '';
        components.forEach(function(comp) {
            var compId = comp.component_id || comp.id;

            html += `
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom" style="gap:10px;flex-wrap:wrap;">
                    <div>
                        <div style="font-weight:600;">${comp.component_name || comp.name || 'Unnamed'}</div>
                        <div style="font-size:12px;color:#6c757d;">
                            <span class="badge bg-secondary">${comp.component_code || comp.code || 'N/A'}</span>
                            <span class="badge bg-info">Weight: ${comp.default_weight || comp.weight || 0}%</span>
                            <span class="badge bg-primary">Max: ${comp.max_score || 0}</span>
                            ${comp.is_required ? '<span class="badge bg-danger">Required</span>' : ''}
                        </div>
                    </div>
                    <div style="display:flex;gap:4px;flex-wrap:wrap;">
                        <button class="btn btn-outline-primary btn-sm" onclick="editComponent(${compId})"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-outline-danger btn-sm" onclick="deleteComponent(${compId})"><i class="fas fa-trash"></i></button>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
    }

    // ============================================================
    // COMPONENT OPERATIONS
    // ============================================================
    function createComponent() {
        showAlert('Opening component editor... (Mock)', 'info');
        // In production, open a modal with the component form
    }

    function editComponent(id) {
        showAlert('Editing component: ' + id + ' (Mock)', 'info');
        // In production, load the component data and open modal
    }

    function deleteComponent(id) {
        if (!confirm('Delete this component?')) return;
        showAlert('Component deleted! (Mock)', 'success');
        loadComponents();
    }

    // ============================================================
    // INITIALIZE
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(loadComponents, 500);
    });
</script>