<!-- Aggregation Rules Sub-tab -->
<div class="sub-tab-content active">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0"><i class="fas fa-calculator me-2 text-primary"></i>Aggregation Rules</h6>
        <button class="btn btn-primary btn-sm" onclick="createAggregationRule()">
            <i class="fas fa-plus me-2"></i> New Rule
        </button>
    </div>

    <div id="aggregationRulesContainer">
        <div class="text-center py-4 text-muted">
            <i class="fas fa-spinner fa-spin fa-2x d-block mb-2"></i>
            Loading aggregation rules...
        </div>
    </div>
</div>

<script>
    // ============================================================
    // LOAD AGGREGATION RULES
    // ============================================================
    function loadAggregationRules() {
        var container = document.getElementById('aggregationRulesContainer');
        if (!container) return;

        container.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-muted mt-2">Loading aggregation rules...</p>
            </div>
        `;

        // Use AssessmentService if available
        if (typeof AssessmentService !== 'undefined' && AssessmentService !== null) {
            AssessmentService.getAggregationRules()
                .then(function(rules) {
                    renderAggregationRules(rules);
                })
                .catch(function(error) {
                    console.error('Error loading aggregation rules:', error);
                    container.innerHTML = `
                        <div class="alert alert-danger alert-custom">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            Error loading aggregation rules: ${error.message}
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
            renderAggregationRules(getMockAggregationRules());
        }
    }

    // ============================================================
    // RENDER AGGREGATION RULES
    // ============================================================
    function renderAggregationRules(rules) {
        var container = document.getElementById('aggregationRulesContainer');
        if (!container) return;

        if (!rules || rules.length === 0) {
            container.innerHTML = `
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-calculator fa-2x d-block mb-2" style="color:#dee2e6;"></i>
                    <p>No aggregation rules defined.</p>
                    <button class="btn btn-primary btn-sm" onclick="createAggregationRule()">
                        <i class="fas fa-plus me-2"></i> Create First Rule
                    </button>
                </div>
            `;
            return;
        }

        var html = '';
        rules.forEach(function(rule) {
            var ruleId = rule.aggregation_rule_id || rule.id;

            html += `
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom" style="gap:10px;flex-wrap:wrap;">
                    <div>
                        <div style="font-weight:600;">${rule.name || rule.rule_name || 'Unnamed'}</div>
                        <div style="font-size:12px;color:#6c757d;">
                            <span class="badge bg-secondary">${rule.code || rule.rule_code || 'N/A'}</span>
                            <span class="badge bg-info">Core: ${rule.core_count || 0} | Electives: ${rule.elective_count || 0}</span>
                            <span class="badge bg-primary">${rule.aggregation_method || 'sum'}</span>
                            ${rule.is_default ? '<span class="badge bg-primary">Default</span>' : ''}
                        </div>
                    </div>
                    <div style="display:flex;gap:4px;flex-wrap:wrap;">
                        <button class="btn btn-outline-primary btn-sm" onclick="editAggregationRule(${ruleId})"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-outline-info btn-sm" onclick="previewAggregation(${ruleId})"><i class="fas fa-eye"></i></button>
                        <button class="btn btn-outline-danger btn-sm" onclick="deleteAggregationRule(${ruleId})"><i class="fas fa-trash"></i></button>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
    }

    // ============================================================
    // AGGREGATION RULE OPERATIONS
    // ============================================================
    function createAggregationRule() {
        showAlert('Opening aggregation rule editor... (Mock)', 'info');
        // In production, open a modal with the aggregation rule form
    }

    function editAggregationRule(id) {
        showAlert('Editing aggregation rule: ' + id + ' (Mock)', 'info');
        // In production, load the rule data and open modal
    }

    function previewAggregation(id) {
        showAlert('Preview ready! (Mock)', 'success');
        // In production, calculate and display preview
    }

    function deleteAggregationRule(id) {
        if (!confirm('Delete this aggregation rule?')) return;
        showAlert('Aggregation rule deleted! (Mock)', 'success');
        loadAggregationRules();
    }

    // ============================================================
    // INITIALIZE
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(loadAggregationRules, 500);
    });
</script>