<!-- Subject Rules Tab -->
<div class="tab-content active">
    <div class="card-custom">
        <div class="card-header-custom">
            <h6><i class="fas fa-book me-2 text-primary"></i>Subject Classification Rules</h6>
            <div>
                <button class="btn btn-primary btn-sm" onclick="addSubjectRule()">
                    <i class="fas fa-plus me-2"></i> Add Rule
                </button>
            </div>
        </div>
        <div class="card-body-custom">
            <div id="subjectRulesContainer">
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-spinner fa-spin fa-2x d-block mb-2"></i>
                    Loading subject rules...
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // ============================================================
    // LOAD SUBJECT RULES
    // ============================================================
    function loadSubjectRules() {
        var container = document.getElementById('subjectRulesContainer');

        // Use apiCall from parent - /api/school-settings/subject-rules
        var url = apiCall('subject-rules');

        fetch(url, {
                headers: getHeaders()
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(result) {
                if (result.success && result.data) {
                    var rules = result.data;

                    if (rules.length === 0) {
                        container.innerHTML = `
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-book fa-2x d-block mb-2" style="color:#dee2e6;"></i>
                                <p>No subject classification rules defined.</p>
                                <button class="btn btn-primary btn-sm" onclick="addSubjectRule()">
                                    <i class="fas fa-plus me-2"></i> Add First Rule
                                </button>
                            </div>
                        `;
                        return;
                    }

                    var grouped = {};
                    rules.forEach(function(rule) {
                        var type = rule.classification_code || 'other';
                        if (!grouped[type]) {
                            grouped[type] = [];
                        }
                        grouped[type].push(rule);
                    });

                    var html = '';
                    for (var type in grouped) {
                        html += `
                            <div class="mb-3">
                                <h6 class="text-muted">${type.toUpperCase()}</h6>
                        `;
                        grouped[type].forEach(function(item) {
                            html += `
                                <div class="d-flex justify-content-between align-items-center py-2 border-bottom" style="font-size:13px;">
                                    <div>
                                        <strong>${item.subject_name || 'Unknown Subject'}</strong>
                                        <span class="badge bg-secondary ms-2">${item.level_name || 'All Levels'}</span>
                                        ${item.is_mandatory ? '<span class="badge bg-danger ms-1">Mandatory</span>' : ''}
                                    </div>
                                    <div>
                                        <button class="btn btn-outline-secondary btn-sm" onclick="editSubjectRule(${item.id || item.subject_rule_id})"><i class="fas fa-edit"></i></button>
                                        <button class="btn btn-outline-danger btn-sm" onclick="deleteSubjectRule(${item.id || item.subject_rule_id})"><i class="fas fa-trash"></i></button>
                                    </div>
                                </div>
                            `;
                        });
                        html += `</div>`;
                    }
                    container.innerHTML = html;
                } else {
                    container.innerHTML = `
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-inbox fa-2x d-block mb-2" style="color:#dee2e6;"></i>
                            <p>${result.message || 'No subject rules found'}</p>
                        </div>
                    `;
                }
            })
            .catch(function(error) {
                console.error('Error loading subject rules:', error);
                container.innerHTML = `
                    <div class="text-center py-4 text-danger">
                        <i class="fas fa-exclamation-circle fa-2x d-block mb-2"></i>
                        Error loading subject rules: ${error.message}
                    </div>
                `;
            });
    }

    // ============================================================
    // ADD SUBJECT RULE
    // ============================================================
    function addSubjectRule() {
        showAlert('Opening subject rule editor...', 'info');
    }

    // ============================================================
    // EDIT SUBJECT RULE
    // ============================================================
    function editSubjectRule(id) {
        showAlert('Editing subject rule: ' + id, 'info');
    }

    // ============================================================
    // DELETE SUBJECT RULE
    // ============================================================
    function deleteSubjectRule(id) {
        if (!confirm('Delete this subject rule?')) return;
        showAlert('Subject rule deleted!', 'success');
        loadSubjectRules();
    }

    // ============================================================
    // INITIALIZE
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof loadSubjectRules === 'function') {
            loadSubjectRules();
        }
    });
</script>