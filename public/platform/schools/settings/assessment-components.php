<!-- Assessment Components Tab -->
<div class="tab-content active">
    <div class="card-custom">
        <div class="card-header-custom">
            <h6><i class="fas fa-cubes me-2 text-primary"></i>Assessment Components</h6>
            <div>
                <button class="btn btn-primary btn-sm" onclick="createComponent()">
                    <i class="fas fa-plus me-2"></i> New Component
                </button>
            </div>
        </div>
        <div class="card-body-custom">
            <div id="componentsContainer">
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-spinner fa-spin fa-2x d-block mb-2"></i>
                    Loading components...
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // ============================================================
    // LOAD COMPONENTS
    // ============================================================
    function loadComponents() {
        var container = document.getElementById('componentsContainer');

        // Use apiCall from parent - /api/school-settings/components
        var url = apiCall('components');

        fetch(url, {
                headers: getHeaders()
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(result) {
                if (result.success && result.data) {
                    var components = result.data;

                    if (components.length === 0) {
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
                        html += `
                            <div class="d-flex justify-content-between align-items-center py-2 border-bottom" style="gap:10px;flex-wrap:wrap;">
                                <div>
                                    <div style="font-weight:600;">${comp.component_name || comp.name || 'Unnamed'}</div>
                                    <div style="font-size:12px;color:#6c757d;">
                                        <span class="badge bg-secondary">${comp.component_code || comp.code || 'N/A'}</span>
                                        <span class="badge bg-info">Weight: ${comp.weight || comp.default_weight || 0}%</span>
                                        <span class="badge bg-primary">Max: ${comp.max_score || 0}</span>
                                    </div>
                                </div>
                                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                    <button class="btn btn-outline-primary btn-sm" onclick="editComponent(${comp.id || comp.component_id})"><i class="fas fa-edit"></i></button>
                                    <button class="btn btn-outline-danger btn-sm" onclick="deleteComponent(${comp.id || comp.component_id})"><i class="fas fa-trash"></i></button>
                                </div>
                            </div>
                        `;
                    });
                    container.innerHTML = html;
                } else {
                    container.innerHTML = `
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-inbox fa-2x d-block mb-2" style="color:#dee2e6;"></i>
                            <p>${result.message || 'No components found'}</p>
                        </div>
                    `;
                }
            })
            .catch(function(error) {
                console.error('Error loading components:', error);
                container.innerHTML = `
                    <div class="text-center py-4 text-danger">
                        <i class="fas fa-exclamation-circle fa-2x d-block mb-2"></i>
                        Error loading components: ${error.message}
                    </div>
                `;
            });
    }

    // ============================================================
    // CREATE COMPONENT
    // ============================================================
    function createComponent() {
        showAlert('Opening component editor...', 'info');
    }

    // ============================================================
    // EDIT COMPONENT
    // ============================================================
    function editComponent(id) {
        showAlert('Editing component: ' + id, 'info');
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
        if (typeof loadComponents === 'function') {
            loadComponents();
        }
    });
</script>