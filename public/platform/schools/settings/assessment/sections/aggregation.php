<!-- Aggregation Rules Section -->
<div class="aggregation-section">
    <div class="card-custom">
        <div class="card-header-custom">
            <h6><i class="fas fa-calculator me-2 text-primary"></i>Aggregation Rules</h6>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" onclick="loadAggregationRules()">
                    <i class="fas fa-sync-alt me-1"></i> Refresh
                </button>
                <button class="btn btn-primary btn-sm" onclick="showAddAggregationRule()">
                    <i class="fas fa-plus me-1"></i> New Rule
                </button>
            </div>
        </div>
        <div class="card-body-custom">
            <div id="aggregationRulesListContainer">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2">Loading aggregation rules...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Aggregation Rule Modal -->
<div class="modal fade" id="aggregationRuleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-calculator me-2 text-primary"></i> <span id="aggregationRuleModalTitle">Aggregation Rule</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="aggregationRuleForm">
                    <input type="hidden" id="aggregationRuleId">
                    <div class="mb-3">
                        <label class="form-label">Rule Name <span class="required">*</span></label>
                        <input type="text" class="form-control" id="arName" placeholder="e.g., JHS Standard Aggregate" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Rule Code <span class="required">*</span></label>
                        <input type="text" class="form-control" id="arCode" placeholder="e.g., JHS-AGG" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Core Subjects Required</label>
                                <input type="number" class="form-control" id="arCoreCount" value="4" min="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Elective Subjects Required</label>
                                <input type="number" class="form-control" id="arElectiveCount" value="2" min="0">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Aggregation Method</label>
                        <select class="form-select" id="arMethod">
                            <option value="sum_grade_points">Sum of Grade Points</option>
                            <option value="average">Average</option>
                            <option value="sum_raw_scores">Sum of Raw Scores</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" id="arDescription" rows="2" placeholder="Description"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveAggregationRule()">
                    <i class="fas fa-save me-2"></i> Save Rule
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // ============================================================
    // MOCK DATA
    // ============================================================
    function getMockAggregationRules() {
        return [{
                'aggregation_rule_id': 1,
                'rule_name': 'JHS Standard Aggregate',
                'rule_code': 'JHS-AGG',
                'core_count': 4,
                'elective_count': 2,
                'aggregation_method': 'sum_grade_points',
                'status': 'active'
            },
            {
                'aggregation_rule_id': 2,
                'rule_name': 'SHS Aggregate (4 Core + 3 Best)',
                'rule_code': 'SHS-AGG',
                'core_count': 4,
                'elective_count': 3,
                'aggregation_method': 'sum_grade_points',
                'status': 'draft'
            }
        ];
    }

    // ============================================================
    // LOAD AGGREGATION RULES
    // ============================================================
    function loadAggregationRules() {
        var container = document.getElementById('aggregationRulesListContainer');
        if (!container) return;

        container.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-muted mt-2">Loading aggregation rules...</p>
            </div>
        `;

        setTimeout(function() {
            var rules = getMockAggregationRules();
            renderAggregationRules(rules);
        }, 300);
    }

    // ============================================================
    // RENDER AGGREGATION RULES
    // ============================================================
    function renderAggregationRules(rules) {
        var container = document.getElementById('aggregationRulesListContainer');
        if (!container) return;

        if (rules.length === 0) {
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="fas fa-calculator fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No aggregation rules defined.</p>
                    <button class="btn btn-primary" onclick="showAddAggregationRule()">
                        <i class="fas fa-plus me-2"></i> Create First Rule
                    </button>
                </div>
            `;
            return;
        }

        var html = '<div class="row g-3">';
        rules.forEach(function(rule) {
            var statusBadge = rule.status === 'active' ? 'success' :
                rule.status === 'draft' ? 'warning' : 'secondary';
            var ruleId = rule.aggregation_rule_id;

            html += `
                <div class="col-md-6">
                    <div class="aggregation-rule-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1">${rule.rule_name}</h6>
                                <span class="badge bg-secondary">${rule.rule_code}</span>
                                <span class="badge bg-${statusBadge}">${rule.status}</span>
                            </div>
                            <div class="d-flex gap-1">
                                <button class="btn btn-outline-primary btn-sm" onclick="editAggregationRule(${ruleId})"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-outline-info btn-sm" onclick="previewAggregation(${ruleId})"><i class="fas fa-eye"></i></button>
                                <button class="btn btn-outline-danger btn-sm" onclick="deleteAggregationRule(${ruleId})"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                        <div class="aggregation-rule-meta">
                            <span>Core: ${rule.core_count}</span>
                            <span>Electives: ${rule.elective_count}</span>
                            <span>Method: ${rule.aggregation_method.replace('_', ' ')}</span>
                        </div>
                        <div class="text-muted small">${rule.description || ''}</div>
                    </div>
                </div>
            `;
        });
        html += '</div>';

        container.innerHTML = html;
    }

    // ============================================================
    // SHOW ADD AGGREGATION RULE - OPENS MODAL
    // ============================================================
    function showAddAggregationRule() {
        document.getElementById('aggregationRuleId').value = '';
        document.getElementById('aggregationRuleModalTitle').textContent = 'Create Aggregation Rule';
        document.getElementById('arName').value = '';
        document.getElementById('arCode').value = '';
        document.getElementById('arCoreCount').value = 4;
        document.getElementById('arElectiveCount').value = 2;
        document.getElementById('arMethod').value = 'sum_grade_points';
        document.getElementById('arDescription').value = '';

        var modal = new bootstrap.Modal(document.getElementById('aggregationRuleModal'));
        modal.show();
    }

    // ============================================================
    // EDIT AGGREGATION RULE - OPENS MODAL WITH DATA
    // ============================================================
    function editAggregationRule(id) {
        var rules = getMockAggregationRules();
        var rule = null;
        for (var i = 0; i < rules.length; i++) {
            if (rules[i].aggregation_rule_id === id) {
                rule = rules[i];
                break;
            }
        }

        if (rule) {
            document.getElementById('aggregationRuleId').value = rule.aggregation_rule_id;
            document.getElementById('aggregationRuleModalTitle').textContent = 'Edit Aggregation Rule';
            document.getElementById('arName').value = rule.rule_name;
            document.getElementById('arCode').value = rule.rule_code;
            document.getElementById('arCoreCount').value = rule.core_count;
            document.getElementById('arElectiveCount').value = rule.elective_count;
            document.getElementById('arMethod').value = rule.aggregation_method;
            document.getElementById('arDescription').value = rule.description || '';

            var modal = new bootstrap.Modal(document.getElementById('aggregationRuleModal'));
            modal.show();
        } else {
            showAlert('Aggregation rule not found', 'danger');
        }
    }

    // ============================================================
    // SAVE AGGREGATION RULE
    // ============================================================
    function saveAggregationRule() {
        var name = document.getElementById('arName').value.trim();
        var code = document.getElementById('arCode').value.trim();

        if (!name || !code) {
            showAlert('Rule name and code are required', 'danger');
            return;
        }

        showAlert('Aggregation rule saved successfully!', 'success');
        var modal = bootstrap.Modal.getInstance(document.getElementById('aggregationRuleModal'));
        if (modal) modal.hide();
        loadAggregationRules();
    }

    // ============================================================
    // DELETE AGGREGATION RULE
    // ============================================================
    function deleteAggregationRule(id) {
        if (!confirm('Delete this aggregation rule?')) return;
        showAlert('Aggregation rule deleted!', 'success');
        loadAggregationRules();
    }

    // ============================================================
    // PREVIEW AGGREGATION
    // ============================================================
    function previewAggregation(id) {
        showAlert('Preview ready!', 'success');
    }

    // ============================================================
    // INITIALIZE
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        loadAggregationRules();
    });
</script>

<style>
    .aggregation-rule-card {
        background: #fff;
        border-radius: 10px;
        padding: 16px 20px;
        border: 1px solid #e9ecf;
        transition: all 0.3s;
        height: 100%;
    }

    .aggregation-rule-card:hover {
        border-color: #4facfe;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
    }

    .aggregation-rule-meta {
        display: flex;
        gap: 16px;
        font-size: 13px;
        color: #6c757d;
        margin: 8px 0;
    }
</style>