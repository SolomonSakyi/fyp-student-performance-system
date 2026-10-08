<!-- Grading Systems Sub-tab -->
<div class="sub-tab-content active">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0"><i class="fas fa-star me-2 text-primary"></i>Grading Systems</h6>
        <button class="btn btn-primary btn-sm" onclick="createGradingSystem()">
            <i class="fas fa-plus me-2"></i> New Grading System
        </button>
    </div>

    <div id="gradingSystemsContainer">
        <div class="text-center py-4 text-muted">
            <i class="fas fa-spinner fa-spin fa-2x d-block mb-2"></i>
            Loading grading systems...
        </div>
    </div>
</div>

<script>
    // ============================================================
    // LOAD GRADING SYSTEMS
    // ============================================================
    function loadGradingSystems() {
        var container = document.getElementById('gradingSystemsContainer');
        if (!container) return;

        container.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-muted mt-2">Loading grading systems...</p>
            </div>
        `;

        // Use AssessmentService if available
        if (typeof AssessmentService !== 'undefined' && AssessmentService !== null) {
            AssessmentService.getGradingSystems()
                .then(function(systems) {
                    renderGradingSystems(systems);
                })
                .catch(function(error) {
                    console.error('Error loading grading systems:', error);
                    container.innerHTML = `
                        <div class="alert alert-danger alert-custom">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            Error loading grading systems: ${error.message}
                        </div>
                    `;
                });
        } else {
            // Fallback: use mock data
            container.innerHTML = `
                <div class="alert alert-warning alert-custom">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Service not loaded. Using mock data.
                </div>
            `;
            renderGradingSystems(getMockGradingSystems());
        }
    }

    // ============================================================
    // RENDER GRADING SYSTEMS
    // ============================================================
    function renderGradingSystems(systems) {
        var container = document.getElementById('gradingSystemsContainer');
        if (!container) return;

        if (!systems || systems.length === 0) {
            container.innerHTML = `
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-star fa-2x d-block mb-2" style="color:#dee2e6;"></i>
                    <p>No grading systems created yet.</p>
                    <button class="btn btn-primary btn-sm" onclick="createGradingSystem()">
                        <i class="fas fa-plus me-2"></i> Create First Grading System
                    </button>
                </div>
            `;
            return;
        }

        var html = '';
        systems.forEach(function(system) {
            var statusClass = system.status === 'active' ? 'success' :
                system.status === 'draft' ? 'warning' : 'secondary';
            var systemId = system.grading_system_id || system.id;

            html += `
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom" style="gap:10px;flex-wrap:wrap;">
                    <div>
                        <div style="font-weight:600;">${system.name || system.system_name || 'Unnamed'}</div>
                        <div style="font-size:12px;color:#6c757d;">
                            <span class="badge bg-secondary">${system.code || system.system_code || 'N/A'}</span>
                            <span class="badge bg-info">${system.system_type || 'custom'}</span>
                            <span class="badge bg-${statusClass}">${system.status || 'draft'}</span>
                            ${system.is_default ? '<span class="badge bg-primary">Default</span>' : ''}
                        </div>
                    </div>
                    <div style="display:flex;gap:4px;flex-wrap:wrap;">
                        <button class="btn btn-outline-primary btn-sm" onclick="editGradingSystem(${systemId})"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-outline-success btn-sm" onclick="setDefaultGradingSystem(${systemId})"><i class="fas fa-check"></i></button>
                        ${system.status !== 'archived' ? `<button class="btn btn-outline-danger btn-sm" onclick="archiveGradingSystem(${systemId})"><i class="fas fa-archive"></i></button>` : ''}
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
    }

    // ============================================================
    // GRADING SYSTEM OPERATIONS
    // ============================================================
    function createGradingSystem() {
        showAlert('Opening grading system editor... (Mock)', 'info');
        // In production, open a modal with the grading system form
    }

    function editGradingSystem(id) {
        showAlert('Editing grading system: ' + id + ' (Mock)', 'info');
        // In production, load the grading system data and open modal
    }

    function setDefaultGradingSystem(id) {
        if (!confirm('Set this as the default grading system?')) return;
        showAlert('Default grading system updated! (Mock)', 'success');
        loadGradingSystems();
    }

    function archiveGradingSystem(id) {
        if (!confirm('Archive this grading system?')) return;
        showAlert('Grading system archived! (Mock)', 'success');
        loadGradingSystems();
    }

    // ============================================================
    // INITIALIZE
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        // Wait for service to load
        setTimeout(loadGradingSystems, 500);
    });
</script>