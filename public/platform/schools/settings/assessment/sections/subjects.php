<!-- Subject Rules Section -->
<div class="subjects-section">
    <div class="card-custom">
        <div class="card-header-custom">
            <h6><i class="fas fa-book me-2 text-primary"></i>Subject Classification Rules</h6>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" onclick="loadSubjectRules()">
                    <i class="fas fa-sync-alt me-1"></i> Refresh
                </button>
                <button class="btn btn-primary btn-sm" onclick="showAddSubjectRule()">
                    <i class="fas fa-plus me-1"></i> Add Rule
                </button>
            </div>
        </div>
        <div class="card-body-custom">
            <div id="subjectRulesListContainer">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2">Loading subject rules...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Subject Rule Modal -->
<div class="modal fade" id="subjectRuleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-book me-2 text-primary"></i> <span id="subjectRuleModalTitle">Subject Classification Rule</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="subjectRuleForm">
                    <input type="hidden" id="subjectRuleId">
                    <div class="mb-3">
                        <label class="form-label">Subject <span class="required">*</span></label>
                        <select class="form-select" id="srSubject" required>
                            <option value="">Select Subject</option>
                            <option value="1">English Language</option>
                            <option value="2">Mathematics</option>
                            <option value="3">Science</option>
                            <option value="4">Social Studies</option>
                            <option value="5">ICT</option>
                            <option value="6">French</option>
                            <option value="7">B.D.T.</option>
                            <option value="8">R.M.E.</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Classification <span class="required">*</span></label>
                        <select class="form-select" id="srClassification" required>
                            <option value="core">Core</option>
                            <option value="elective">Elective</option>
                            <option value="optional">Optional</option>
                            <option value="enrichment">Enrichment</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Applicable Level</label>
                        <select class="form-select" id="srLevel">
                            <option value="">All Levels</option>
                            <option value="PRIMARY_BASIC_1">Basic 1</option>
                            <option value="PRIMARY_BASIC_2">Basic 2</option>
                            <option value="PRIMARY_BASIC_3">Basic 3</option>
                            <option value="PRIMARY_BASIC_4">Basic 4</option>
                            <option value="PRIMARY_BASIC_5">Basic 5</option>
                            <option value="PRIMARY_BASIC_6">Basic 6</option>
                            <option value="JHS_1">J.H.S. 1</option>
                            <option value="JHS_2">J.H.S. 2</option>
                            <option value="JHS_3">J.H.S. 3</option>
                        </select>
                    </div>
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="srMandatory">
                        <label class="form-check-label" for="srMandatory">Mandatory Subject</label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveSubjectRule()">
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
    function getMockSubjectRules() {
        return [{
                'id': 1,
                'subject_name': 'English Language',
                'classification_code': 'core',
                'level_name': 'Basic 7-9',
                'is_mandatory': 1
            },
            {
                'id': 2,
                'subject_name': 'Mathematics',
                'classification_code': 'core',
                'level_name': 'Basic 7-9',
                'is_mandatory': 1
            },
            {
                'id': 3,
                'subject_name': 'Science',
                'classification_code': 'core',
                'level_name': 'Basic 7-9',
                'is_mandatory': 1
            },
            {
                'id': 4,
                'subject_name': 'Social Studies',
                'classification_code': 'core',
                'level_name': 'Basic 7-9',
                'is_mandatory': 1
            },
            {
                'id': 5,
                'subject_name': 'ICT',
                'classification_code': 'elective',
                'level_name': 'Basic 7-9',
                'is_mandatory': 0
            },
            {
                'id': 6,
                'subject_name': 'French',
                'classification_code': 'elective',
                'level_name': 'Basic 7-9',
                'is_mandatory': 0
            }
        ];
    }

    // ============================================================
    // LOAD SUBJECT RULES
    // ============================================================
    function loadSubjectRules() {
        var container = document.getElementById('subjectRulesListContainer');
        if (!container) return;

        container.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-muted mt-2">Loading subject rules...</p>
            </div>
        `;

        setTimeout(function() {
            var rules = getMockSubjectRules();
            renderSubjectRules(rules);
        }, 300);
    }

    // ============================================================
    // RENDER SUBJECT RULES
    // ============================================================
    function renderSubjectRules(rules) {
        var container = document.getElementById('subjectRulesListContainer');
        if (!container) return;

        if (rules.length === 0) {
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="fas fa-book fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No subject classification rules defined.</p>
                    <button class="btn btn-primary" onclick="showAddSubjectRule()">
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
        var classificationLabels = {
            'core': 'Core Subjects',
            'elective': 'Elective Subjects',
            'optional': 'Optional Subjects'
        };

        for (var type in grouped) {
            var label = classificationLabels[type] || type.charAt(0).toUpperCase() + type.slice(1);
            html += `
                <div class="mb-3">
                    <h6 class="text-muted">${label}</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Level</th>
                                    <th>Mandatory</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
            `;

            grouped[type].forEach(function(rule) {
                html += `
                    <tr>
                        <td><strong>${rule.subject_name}</strong></td>
                        <td><span class="badge bg-secondary">${rule.level_name || 'All Levels'}</span></td>
                        <td>${rule.is_mandatory ? '<span class="badge bg-danger">Yes</span>' : '<span class="badge bg-secondary">No</span>'}</td>
                        <td>
                            <button class="btn btn-outline-primary btn-sm" onclick="editSubjectRule(${rule.id})"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-outline-danger btn-sm" onclick="deleteSubjectRule(${rule.id})"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                `;
            });

            html += `</tbody></table></div></div>`;
        }

        container.innerHTML = html;
    }

    // ============================================================
    // SHOW ADD SUBJECT RULE - OPENS MODAL
    // ============================================================
    function showAddSubjectRule() {
        document.getElementById('subjectRuleId').value = '';
        document.getElementById('subjectRuleModalTitle').textContent = 'Create Subject Classification Rule';
        document.getElementById('srSubject').value = '';
        document.getElementById('srClassification').value = 'core';
        document.getElementById('srLevel').value = '';
        document.getElementById('srMandatory').checked = false;

        var modal = new bootstrap.Modal(document.getElementById('subjectRuleModal'));
        modal.show();
    }

    // ============================================================
    // EDIT SUBJECT RULE - OPENS MODAL WITH DATA
    // ============================================================
    function editSubjectRule(id) {
        var rules = getMockSubjectRules();
        var rule = null;
        for (var i = 0; i < rules.length; i++) {
            if (rules[i].id === id) {
                rule = rules[i];
                break;
            }
        }

        if (rule) {
            document.getElementById('subjectRuleId').value = rule.id;
            document.getElementById('subjectRuleModalTitle').textContent = 'Edit Subject Classification Rule';
            document.getElementById('srSubject').value = rule.subject_name;
            document.getElementById('srClassification').value = rule.classification_code;
            document.getElementById('srLevel').value = rule.level_name || '';
            document.getElementById('srMandatory').checked = rule.is_mandatory;

            var modal = new bootstrap.Modal(document.getElementById('subjectRuleModal'));
            modal.show();
        } else {
            showAlert('Subject rule not found', 'danger');
        }
    }

    // ============================================================
    // SAVE SUBJECT RULE
    // ============================================================
    function saveSubjectRule() {
        var subject = document.getElementById('srSubject').value;
        var classification = document.getElementById('srClassification').value;

        if (!subject || !classification) {
            showAlert('Subject and classification are required', 'danger');
            return;
        }

        showAlert('Subject rule saved successfully!', 'success');
        var modal = bootstrap.Modal.getInstance(document.getElementById('subjectRuleModal'));
        if (modal) modal.hide();
        loadSubjectRules();
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
        loadSubjectRules();
    });
</script>