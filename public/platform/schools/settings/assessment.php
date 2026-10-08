<?php

/**
 * Assessment & Grading Settings Dashboard
 * @package EduTrack
 * @subpackage Platform\Schools\Settings
 * @version 1.0
 */

$schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
if (!$schoolId) {
    $schoolId = $_SESSION['selected_school_id'] ?? 1;
}

$activeSection = isset($_GET['section']) ? $_GET['section'] : 'profiles';
?>
<div class="assessment-settings-dashboard">
    <!-- Dashboard Header -->
    <div class="dashboard-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="mb-1"><i class="fas fa-sliders-h me-2 text-primary"></i>Assessment & Grading Settings</h5>
                <p class="text-muted small mb-0">Configure assessment profiles, grading systems, and academic rules</p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
                    <i class="fas fa-sync-alt me-1"></i> Refresh
                </button>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-tabs assessment-nav mb-4" style="border-bottom:2px solid #e9ecf;flex-wrap:nowrap;overflow-x:auto;">
        <li class="nav-item"><a class="nav-link <?php echo $activeSection == 'profiles' ? 'active' : ''; ?>" href="?tab=assessment&section=profiles&school_id=<?php echo $schoolId; ?>"><i class="fas fa-layer-group me-2"></i>Profiles</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $activeSection == 'components' ? 'active' : ''; ?>" href="?tab=assessment&section=components&school_id=<?php echo $schoolId; ?>"><i class="fas fa-cubes me-2"></i>Components</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $activeSection == 'grading' ? 'active' : ''; ?>" href="?tab=assessment&section=grading&school_id=<?php echo $schoolId; ?>"><i class="fas fa-star me-2"></i>Grading</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $activeSection == 'aggregation' ? 'active' : ''; ?>" href="?tab=assessment&section=aggregation&school_id=<?php echo $schoolId; ?>"><i class="fas fa-calculator me-2"></i>Aggregation</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $activeSection == 'subjects' ? 'active' : ''; ?>" href="?tab=assessment&section=subjects&school_id=<?php echo $schoolId; ?>"><i class="fas fa-book me-2"></i>Subject Rules</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $activeSection == 'remarks' ? 'active' : ''; ?>" href="?tab=assessment&section=remarks&school_id=<?php echo $schoolId; ?>"><i class="fas fa-comment-dots me-2"></i>Remarks</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $activeSection == 'history' ? 'active' : ''; ?>" href="?tab=assessment&section=history&school_id=<?php echo $schoolId; ?>"><i class="fas fa-history me-2"></i>History</a></li>
    </ul>

    <!-- ============================================================ -->
    <!-- PROFILES SECTION -->
    <!-- ============================================================ -->
    <div id="section-profiles" class="section-panel <?php echo $activeSection == 'profiles' ? 'active' : ''; ?>">
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-layer-group me-2 text-primary"></i>Assessment Profiles</h6>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm" onclick="loadProfiles()"><i class="fas fa-sync-alt me-1"></i> Refresh</button>
                    <button class="btn btn-primary btn-sm" onclick="showAddProfile()"><i class="fas fa-plus me-1"></i> New Profile</button>
                </div>
            </div>
            <div class="card-body-custom">
                <div class="row g-2 mb-3">
                    <div class="col-md-3 col-6">
                        <div class="stat-card">
                            <div class="stat-number" id="totalProfiles">0</div>
                            <div class="stat-label">Total</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card">
                            <div class="stat-number text-success" id="activeProfiles">0</div>
                            <div class="stat-label">Active</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card">
                            <div class="stat-number text-warning" id="draftProfiles">0</div>
                            <div class="stat-label">Draft</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card">
                            <div class="stat-number text-secondary" id="archivedProfiles">0</div>
                            <div class="stat-label">Archived</div>
                        </div>
                    </div>
                </div>
                <div id="profilesContainer">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="text-muted mt-2">Loading profiles...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- COMPONENTS SECTION -->
    <!-- ============================================================ -->
    <div id="section-components" class="section-panel <?php echo $activeSection == 'components' ? 'active' : ''; ?>">
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-cubes me-2 text-primary"></i>Assessment Components</h6>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm" onclick="loadComponents()"><i class="fas fa-sync-alt me-1"></i> Refresh</button>
                    <button class="btn btn-primary btn-sm" onclick="showAddComponent()"><i class="fas fa-plus me-1"></i> New Component</button>
                </div>
            </div>
            <div class="card-body-custom">
                <div id="componentsListContainer">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="text-muted mt-2">Loading components...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- GRADING SYSTEMS SECTION -->
    <!-- ============================================================ -->
    <div id="section-grading" class="section-panel <?php echo $activeSection == 'grading' ? 'active' : ''; ?>">
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-star me-2 text-primary"></i>Grading Systems</h6>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm" onclick="loadGradingSystems()"><i class="fas fa-sync-alt me-1"></i> Refresh</button>
                    <button class="btn btn-primary btn-sm" onclick="showAddGradingSystem()"><i class="fas fa-plus me-1"></i> New Grading System</button>
                </div>
            </div>
            <div class="card-body-custom">
                <div id="gradingSystemsListContainer">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="text-muted mt-2">Loading grading systems...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- AGGREGATION RULES SECTION -->
    <!-- ============================================================ -->
    <div id="section-aggregation" class="section-panel <?php echo $activeSection == 'aggregation' ? 'active' : ''; ?>">
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-calculator me-2 text-primary"></i>Aggregation Rules</h6>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm" onclick="loadAggregationRules()"><i class="fas fa-sync-alt me-1"></i> Refresh</button>
                    <button class="btn btn-primary btn-sm" onclick="showAddAggregationRule()"><i class="fas fa-plus me-1"></i> New Rule</button>
                </div>
            </div>
            <div class="card-body-custom">
                <div id="aggregationRulesListContainer">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="text-muted mt-2">Loading aggregation rules...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- SUBJECT RULES SECTION -->
    <!-- ============================================================ -->
    <div id="section-subjects" class="section-panel <?php echo $activeSection == 'subjects' ? 'active' : ''; ?>">
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-book me-2 text-primary"></i>Subject Classification Rules</h6>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm" onclick="loadSubjectRules()"><i class="fas fa-sync-alt me-1"></i> Refresh</button>
                    <button class="btn btn-primary btn-sm" onclick="showAddSubjectRule()"><i class="fas fa-plus me-1"></i> Add Rule</button>
                </div>
            </div>
            <div class="card-body-custom">
                <div id="subjectRulesListContainer">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="text-muted mt-2">Loading subject rules...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- REMARKS SECTION -->
    <!-- ============================================================ -->
    <div id="section-remarks" class="section-panel <?php echo $activeSection == 'remarks' ? 'active' : ''; ?>">
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-comment-dots me-2 text-primary"></i>Remark Rules</h6>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm" onclick="loadRemarks()"><i class="fas fa-sync-alt me-1"></i> Refresh</button>
                    <button class="btn btn-primary btn-sm" onclick="showAddRemarkRule()"><i class="fas fa-plus me-1"></i> New Remark Rule</button>
                </div>
            </div>
            <div class="card-body-custom">
                <div id="remarksListContainer">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="text-muted mt-2">Loading remark rules...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- HISTORY SECTION -->
    <!-- ============================================================ -->
    <div id="section-history" class="section-panel <?php echo $activeSection == 'history' ? 'active' : ''; ?>">
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-history me-2 text-primary"></i>Version History</h6>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm" onclick="loadHistory()"><i class="fas fa-sync-alt me-1"></i> Refresh</button>
                </div>
            </div>
            <div class="card-body-custom">
                <div id="historyListContainer">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="text-muted mt-2">Loading history...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Profile Modal (shared) -->
<div class="modal fade" id="profileModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-layer-group me-2 text-primary"></i> <span id="profileModalTitle">Assessment Profile</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="profileForm">
                    <input type="hidden" id="profileId">
                    <input type="hidden" id="profileSchoolId" value="<?php echo $schoolId; ?>">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3"><label class="form-label">Profile Name <span class="required">*</span></label><input type="text" class="form-control" id="profileName" placeholder="e.g., JHS WAEC Profile" required></div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3"><label class="form-label">Profile Code <span class="required">*</span></label><input type="text" class="form-control" id="profileCode" placeholder="e.g., JHS-WAEC" required></div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3"><label class="form-label">Status</label><select class="form-select" id="profileStatus">
                                    <option value="draft">Draft</option>
                                    <option value="active">Active</option>
                                    <option value="archived">Archived</option>
                                </select></div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3"><label class="form-label">Assessment Model</label><select class="form-select" id="profileModel">
                                    <option value="30_70">30% Class / 70% Exam</option>
                                    <option value="40_60">40% CA / 60% Exam</option>
                                    <option value="50_50">50% CA / 50% Exam</option>
                                    <option value="custom">Custom</option>
                                </select></div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-3"><label class="form-label">Applicable Levels <span class="required">*</span></label><select class="form-select" id="profileLevels" multiple style="height:auto;">
                                    <option value="basic_7">Basic 7</option>
                                    <option value="basic_8">Basic 8</option>
                                    <option value="basic_9">Basic 9</option>
                                    <option value="primary_1">Primary 1</option>
                                    <option value="primary_2">Primary 2</option>
                                    <option value="primary_3">Primary 3</option>
                                    <option value="primary_4">Primary 4</option>
                                    <option value="primary_5">Primary 5</option>
                                    <option value="primary_6">Primary 6</option>
                                </select>
                                <div class="form-text">Hold Ctrl/Cmd to select multiple</div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" id="profileDescription" rows="2" placeholder="Brief description"></textarea></div>
                    <hr>
                    <h6 class="mb-2"><i class="fas fa-tasks me-2 text-primary"></i>Assessment Components</h6>
                    <div id="componentsContainer">
                        <div class="text-center py-2 text-muted small">No components configured yet.</div>
                    </div>
                    <div class="mt-2"><button type="button" class="btn btn-outline-primary btn-sm" onclick="addComponentRow()"><i class="fas fa-plus me-1"></i> Add Component</button></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveProfile()"><i class="fas fa-save me-2"></i> Save Profile</button>
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
                    <div class="mb-3"><label class="form-label">Component Name <span class="required">*</span></label><input type="text" class="form-control" id="componentName" placeholder="e.g., Class Assessment" required></div>
                    <div class="mb-3"><label class="form-label">Component Code <span class="required">*</span></label><input type="text" class="form-control" id="componentCode" placeholder="e.g., CA" required></div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3"><label class="form-label">Max Score</label><input type="number" class="form-control" id="componentMaxScore" value="100" step="0.01"></div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3"><label class="form-label">Default Weight (%)</label><input type="number" class="form-control" id="componentWeight" value="0" step="0.01"></div>
                        </div>
                    </div>
                    <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" id="componentDescription" rows="2" placeholder="Brief description"></textarea></div>
                    <div class="form-check mb-3"><input type="checkbox" class="form-check-input" id="componentRequired" checked><label class="form-check-label" for="componentRequired">Required Component</label></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveComponent()"><i class="fas fa-save me-2"></i> Save Component</button>
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
                            <div class="mb-3"><label class="form-label">System Name <span class="required">*</span></label><input type="text" class="form-control" id="gsName" placeholder="e.g., WAEC Numeric" required></div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3"><label class="form-label">System Code <span class="required">*</span></label><input type="text" class="form-control" id="gsCode" placeholder="e.g., WAEC-NUM" required></div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3"><label class="form-label">System Type</label><select class="form-select" id="gsType">
                                    <option value="alphabetical">Alphabetical (A-F)</option>
                                    <option value="numeric">Numeric (1-9)</option>
                                    <option value="percentage">Percentage</option>
                                    <option value="waec">WAEC-Compatible</option>
                                    <option value="custom">Custom</option>
                                </select></div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3"><label class="form-label">Status</label><select class="form-select" id="gsStatus">
                                    <option value="draft">Draft</option>
                                    <option value="active">Active</option>
                                    <option value="archived">Archived</option>
                                </select></div>
                        </div>
                    </div>
                    <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" id="gsDescription" rows="2" placeholder="Description"></textarea></div>
                    <hr>
                    <h6 class="mb-2"><i class="fas fa-table me-2 text-primary"></i>Grade Scales</h6>
                    <div id="gradeScalesContainer">
                        <div class="text-center py-2 text-muted small">No grade scales added yet.</div>
                    </div>
                    <div class="mt-2"><button type="button" class="btn btn-outline-primary btn-sm" onclick="addGradeScaleRow()"><i class="fas fa-plus me-1"></i> Add Grade Scale</button></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveGradingSystem()"><i class="fas fa-save me-2"></i> Save Grading System</button>
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
                    <div class="mb-3"><label class="form-label">Rule Name <span class="required">*</span></label><input type="text" class="form-control" id="arName" placeholder="e.g., JHS Standard Aggregate" required></div>
                    <div class="mb-3"><label class="form-label">Rule Code <span class="required">*</span></label><input type="text" class="form-control" id="arCode" placeholder="e.g., JHS-AGG" required></div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3"><label class="form-label">Core Subjects Required</label><input type="number" class="form-control" id="arCoreCount" value="4" min="0"></div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3"><label class="form-label">Elective Subjects Required</label><input type="number" class="form-control" id="arElectiveCount" value="2" min="0"></div>
                        </div>
                    </div>
                    <div class="mb-3"><label class="form-label">Aggregation Method</label><select class="form-select" id="arMethod">
                            <option value="sum_grade_points">Sum of Grade Points</option>
                            <option value="average">Average</option>
                            <option value="sum_raw_scores">Sum of Raw Scores</option>
                        </select></div>
                    <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" id="arDescription" rows="2" placeholder="Description"></textarea></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveAggregationRule()"><i class="fas fa-save me-2"></i> Save Rule</button>
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
                    <div class="mb-3"><label class="form-label">Subject <span class="required">*</span></label><select class="form-select" id="srSubject" required>
                            <option value="">Select Subject</option>
                            <option value="1">English Language</option>
                            <option value="2">Mathematics</option>
                            <option value="3">Science</option>
                            <option value="4">Social Studies</option>
                            <option value="5">ICT</option>
                            <option value="6">French</option>
                            <option value="7">B.D.T.</option>
                            <option value="8">R.M.E.</option>
                        </select></div>
                    <div class="mb-3"><label class="form-label">Classification <span class="required">*</span></label><select class="form-select" id="srClassification" required>
                            <option value="core">Core</option>
                            <option value="elective">Elective</option>
                            <option value="optional">Optional</option>
                            <option value="enrichment">Enrichment</option>
                        </select></div>
                    <div class="mb-3"><label class="form-label">Applicable Level</label><select class="form-select" id="srLevel">
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
                        </select></div>
                    <div class="form-check mb-3"><input type="checkbox" class="form-check-input" id="srMandatory"><label class="form-check-label" for="srMandatory">Mandatory Subject</label></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveSubjectRule()"><i class="fas fa-save me-2"></i> Save Rule</button>
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
                    <div class="mb-3"><label class="form-label">Rule Name <span class="required">*</span></label><input type="text" class="form-control" id="rrName" placeholder="e.g., Excellent Performance" required></div>
                    <div class="mb-3"><label class="form-label">Rule Code <span class="required">*</span></label><input type="text" class="form-control" id="rrCode" placeholder="e.g., EXC" required></div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3"><label class="form-label">Min Score</label><input type="number" class="form-control" id="rrMinScore" value="0" step="0.01"></div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3"><label class="form-label">Max Score</label><input type="number" class="form-control" id="rrMaxScore" value="100" step="0.01"></div>
                        </div>
                    </div>
                    <div class="mb-3"><label class="form-label">Remark Text <span class="required">*</span></label><textarea class="form-control" id="rrRemarkText" rows="3" placeholder="The remark text..." required></textarea></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveRemarkRule()"><i class="fas fa-save me-2"></i> Save Remark</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- JAVASCRIPT -->
<!-- ============================================================ -->
<script>
    // ============================================================
    // CONFIGURATION
    // ============================================================
    var SCHOOL_ID = <?php echo json_encode($schoolId); ?>;

    // ============================================================
    // SHOW ALERT
    // ============================================================
    function showAlert(message, type) {
        type = type || 'info';
        var container = document.getElementById('alertContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'alertContainer';
            container.style.position = 'fixed';
            container.style.top = '20px';
            container.style.right = '20px';
            container.style.zIndex = '9999';
            container.style.maxWidth = '400px';
            document.body.appendChild(container);
        }
        var colors = {
            success: 'alert-success',
            danger: 'alert-danger',
            warning: 'alert-warning',
            info: 'alert-info'
        };
        var icons = {
            success: 'fa-check-circle',
            danger: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };
        var alertDiv = document.createElement('div');
        alertDiv.className = 'alert ' + (colors[type] || 'alert-info') + ' alert-dismissible fade show alert-custom';
        alertDiv.style.borderRadius = '12px';
        alertDiv.style.boxShadow = '0 4px 20px rgba(0,0,0,0.15)';
        alertDiv.style.marginBottom = '10px';
        alertDiv.innerHTML = '<i class="fas ' + (icons[type] || 'fa-info-circle') + ' me-2"></i> ' + message + '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        container.appendChild(alertDiv);
        setTimeout(function() {
            if (alertDiv.parentNode) {
                alertDiv.classList.remove('show');
                setTimeout(function() {
                    if (alertDiv.parentNode) {
                        alertDiv.parentNode.removeChild(alertDiv);
                    }
                }, 300);
            }
        }, 5000);
    }

    // ============================================================
    // ADD COMPONENT ROW (for profile modal)
    // ============================================================
    function addComponentRow(name, weight) {
        var container = document.getElementById('componentsContainer');
        var emptyMsg = container.querySelector('.text-muted');
        if (emptyMsg) {
            container.innerHTML = '';
        }
        var compName = name || '';
        var compWeight = weight || '';
        var html = `<div class="component-row d-flex gap-2 align-items-center mb-2" style="background:#f8f9fa;padding:8px 12px;border-radius:8px;">
            <input type="text" class="form-control form-control-sm" placeholder="Component Name" value="${compName}" style="flex:2;">
            <input type="number" class="form-control form-control-sm" placeholder="Weight %" value="${compWeight}" style="flex:1;max-width:100px;" step="0.01">
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeComponentRow(this)"><i class="fas fa-times"></i></button>
        </div>`;
        container.insertAdjacentHTML('beforeend', html);
    }

    function removeComponentRow(btn) {
        var row = btn.closest('.component-row');
        if (row) row.remove();
    }

    // ============================================================
    // ADD GRADE SCALE ROW (for grading modal)
    // ============================================================
    function addGradeScaleRow() {
        var container = document.getElementById('gradeScalesContainer');
        if (!container) return;
        var emptyMsg = container.querySelector('.text-muted');
        if (emptyMsg) {
            container.innerHTML = '';
        }
        var html = `<div class="grade-scale-row d-flex gap-2 align-items-center mb-2" style="background:#f8f9fa;padding:8px 12px;border-radius:8px;">
            <input type="text" class="form-control form-control-sm" placeholder="Grade" style="flex:0.5;min-width:60px;">
            <input type="number" class="form-control form-control-sm" placeholder="Min" style="flex:0.5;min-width:60px;" step="0.01">
            <input type="number" class="form-control form-control-sm" placeholder="Max" style="flex:0.5;min-width:60px;" step="0.01">
            <input type="number" class="form-control form-control-sm" placeholder="Points" style="flex:0.5;min-width:60px;" step="0.01">
            <input type="text" class="form-control form-control-sm" placeholder="Remark" style="flex:1;">
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeGradeScaleRow(this)"><i class="fas fa-times"></i></button>
        </div>`;
        container.insertAdjacentHTML('beforeend', html);
    }

    function removeGradeScaleRow(btn) {
        var row = btn.closest('.grade-scale-row');
        if (row) row.remove();
    }

    // ============================================================
    // PROFILES FUNCTIONS
    // ============================================================
    function getMockProfiles() {
        return [{
                'profile_id': 1,
                'name': 'JHS WAEC Profile',
                'code': 'JHS-WAEC',
                'applicable_levels': 'basic_7,basic_8,basic_9',
                'assessment_model': '30_70',
                'status': 'active',
                'is_locked': 0,
                'components': [{
                    'name': 'Class Assessment',
                    'weight': 30
                }, {
                    'name': 'Examination',
                    'weight': 70
                }]
            },
            {
                'profile_id': 2,
                'name': 'Primary Standard Profile',
                'code': 'PRIM-STD',
                'applicable_levels': 'primary_1,primary_2,primary_3,primary_4,primary_5,primary_6',
                'assessment_model': '40_60',
                'status': 'active',
                'is_locked': 0,
                'components': [{
                    'name': 'Continuous Assessment',
                    'weight': 40
                }, {
                    'name': 'Examination',
                    'weight': 60
                }]
            },
            {
                'profile_id': 3,
                'name': 'SHS WAEC Profile',
                'code': 'SHS-WAEC',
                'applicable_levels': 'shs_1,shs_2,shs_3',
                'assessment_model': '30_70',
                'status': 'draft',
                'is_locked': 0,
                'components': [{
                    'name': 'Class Assessment',
                    'weight': 30
                }, {
                    'name': 'Examination',
                    'weight': 70
                }]
            }
        ];
    }

    function loadProfiles() {
        var container = document.getElementById('profilesContainer');
        if (!container) return;
        container.innerHTML = `<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="text-muted mt-2">Loading profiles...</p></div>`;
        setTimeout(function() {
            renderProfiles(getMockProfiles());
        }, 300);
    }

    function renderProfiles(profiles) {
        var container = document.getElementById('profilesContainer');
        if (!container) return;
        var total = 0,
            active = 0,
            draft = 0,
            archived = 0;
        profiles.forEach(function(p) {
            total++;
            if (p.status === 'active') active++;
            else if (p.status === 'draft') draft++;
            else if (p.status === 'archived') archived++;
        });
        document.getElementById('totalProfiles').textContent = total;
        document.getElementById('activeProfiles').textContent = active;
        document.getElementById('draftProfiles').textContent = draft;
        document.getElementById('archivedProfiles').textContent = archived;
        if (profiles.length === 0) {
            container.innerHTML = `<div class="text-center py-5"><i class="fas fa-layer-group fa-3x text-muted mb-3"></i><p class="text-muted">No assessment profiles created yet.</p></div>`;
            return;
        }
        var html = '<div class="row g-3">';
        profiles.forEach(function(profile) {
            var statusBadge = profile.status === 'active' ? 'success' : profile.status === 'draft' ? 'warning' : 'secondary';
            html += `<div class="col-md-6 col-xl-4"><div class="profile-card ${profile.status}"><div class="profile-header"><div class="profile-info"><div class="profile-icon primary"><i class="fas fa-layer-group"></i></div><div><div class="profile-name">${profile.name}</div><div class="profile-type">${profile.code}</div></div></div><span class="badge bg-${statusBadge}">${profile.status}</span></div><div class="profile-meta"><span>${profile.applicable_levels || 'All Levels'}</span><span>${profile.assessment_model || '30/70'}</span></div><div class="profile-components">${profile.components ? profile.components.map(function(c) { return '<span class="component-badge">' + c.name + ' (' + c.weight + '%)</span>'; }).join('') : ''}</div><div class="profile-actions"><button class="btn btn-outline-primary btn-sm" onclick="editProfile(${profile.profile_id})"><i class="fas fa-edit"></i> Edit</button><button class="btn btn-outline-secondary btn-sm" onclick="viewProfile(${profile.profile_id})"><i class="fas fa-eye"></i> View</button><button class="btn btn-outline-success btn-sm" onclick="duplicateProfile(${profile.profile_id})"><i class="fas fa-copy"></i> Duplicate</button>${profile.status === 'draft' ? `<button class="btn btn-outline-success btn-sm" onclick="publishProfile(${profile.profile_id})"><i class="fas fa-check"></i> Publish</button>` : ''}${!profile.is_locked ? `<button class="btn btn-outline-danger btn-sm" onclick="deleteProfile(${profile.profile_id})"><i class="fas fa-trash"></i></button>` : ''}</div></div></div>`;
        });
        html += '</div>';
        container.innerHTML = html;
    }

    function showAddProfile() {
        document.getElementById('profileId').value = '';
        document.getElementById('profileModalTitle').textContent = 'Create Assessment Profile';
        document.getElementById('profileName').value = '';
        document.getElementById('profileCode').value = '';
        document.getElementById('profileDescription').value = '';
        document.getElementById('profileLevels').value = [];
        document.getElementById('profileModel').value = '30_70';
        document.getElementById('profileStatus').value = 'draft';
        document.getElementById('componentsContainer').innerHTML = '<div class="text-center py-2 text-muted small">No components configured yet.</div>';
        var modal = new bootstrap.Modal(document.getElementById('profileModal'));
        modal.show();
    }

    function editProfile(id) {
        var profiles = getMockProfiles();
        var profile = profiles.find(function(p) {
            return p.profile_id === id;
        });
        if (profile) {
            document.getElementById('profileId').value = profile.profile_id;
            document.getElementById('profileModalTitle').textContent = 'Edit Assessment Profile';
            document.getElementById('profileName').value = profile.name;
            document.getElementById('profileCode').value = profile.code;
            document.getElementById('profileDescription').value = profile.description || '';
            document.getElementById('profileLevels').value = profile.applicable_levels ? profile.applicable_levels.split(',') : [];
            document.getElementById('profileModel').value = profile.assessment_model || '30_70';
            document.getElementById('profileStatus').value = profile.status || 'draft';
            document.getElementById('componentsContainer').innerHTML = '';
            if (profile.components) {
                profile.components.forEach(function(comp) {
                    addComponentRow(comp.name, comp.weight);
                });
            } else {
                document.getElementById('componentsContainer').innerHTML = '<div class="text-center py-2 text-muted small">No components configured yet.</div>';
            }
            var modal = new bootstrap.Modal(document.getElementById('profileModal'));
            modal.show();
        }
    }

    function saveProfile() {
        var name = document.getElementById('profileName').value.trim();
        var code = document.getElementById('profileCode').value.trim();
        if (!name || !code) {
            showAlert('Profile name and code are required', 'danger');
            return;
        }
        showAlert('Profile saved successfully!', 'success');
        var modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
        if (modal) modal.hide();
        loadProfiles();
    }

    function deleteProfile(id) {
        if (!confirm('Delete this profile?')) return;
        showAlert('Profile deleted!', 'success');
        loadProfiles();
    }

    function publishProfile(id) {
        if (!confirm('Publish this profile?')) return;
        showAlert('Profile published!', 'success');
        loadProfiles();
    }

    function duplicateProfile(id) {
        var name = prompt('Enter a name for the duplicate:');
        if (!name) return;
        showAlert('Profile duplicated as: ' + name, 'success');
        loadProfiles();
    }

    function viewProfile(id) {
        showAlert('Viewing profile: ' + id, 'info');
    }

    // ============================================================
    // COMPONENTS FUNCTIONS
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

    function loadComponents() {
        var container = document.getElementById('componentsListContainer');
        if (!container) return;
        container.innerHTML = `<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="text-muted mt-2">Loading components...</p></div>`;
        setTimeout(function() {
            renderComponents(getMockComponents());
        }, 300);
    }

    function renderComponents(components) {
        var container = document.getElementById('componentsListContainer');
        if (!container) return;
        if (components.length === 0) {
            container.innerHTML = `<div class="text-center py-5"><i class="fas fa-cubes fa-3x text-muted mb-3"></i><p class="text-muted">No components defined.</p></div>`;
            return;
        }
        var html = '<div class="table-responsive"><table class="table table-hover"><thead><tr><th>Name</th><th>Code</th><th>Max Score</th><th>Default Weight</th><th>Required</th><th>Actions</th></tr></thead><tbody>';
        components.forEach(function(comp) {
            html += `<tr><td><strong>${comp.component_name}</strong></td><td><span class="badge bg-secondary">${comp.component_code}</span></td><td>${comp.max_score}</td><td>${comp.default_weight}%</td><td>${comp.is_required ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'}</td><td><button class="btn btn-outline-primary btn-sm" onclick="editComponent(${comp.component_id})"><i class="fas fa-edit"></i></button><button class="btn btn-outline-danger btn-sm" onclick="deleteComponent(${comp.component_id})"><i class="fas fa-trash"></i></button></td></tr>`;
        });
        html += '</tbody></table></div>';
        container.innerHTML = html;
    }

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

    function editComponent(id) {
        var components = getMockComponents();
        var comp = components.find(function(c) {
            return c.component_id === id;
        });
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
        }
    }

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

    function deleteComponent(id) {
        if (!confirm('Delete this component?')) return;
        showAlert('Component deleted!', 'success');
        loadComponents();
    }

    // ============================================================
    // GRADING SYSTEMS FUNCTIONS
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

    function loadGradingSystems() {
        var container = document.getElementById('gradingSystemsListContainer');
        if (!container) return;
        container.innerHTML = `<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="text-muted mt-2">Loading grading systems...</p></div>`;
        setTimeout(function() {
            renderGradingSystems(getMockGradingSystems());
        }, 300);
    }

    function renderGradingSystems(systems) {
        var container = document.getElementById('gradingSystemsListContainer');
        if (!container) return;
        if (systems.length === 0) {
            container.innerHTML = `<div class="text-center py-5"><i class="fas fa-star fa-3x text-muted mb-3"></i><p class="text-muted">No grading systems created.</p></div>`;
            return;
        }
        var html = '<div class="row g-3">';
        systems.forEach(function(system) {
            var statusBadge = system.status === 'active' ? 'success' : system.status === 'draft' ? 'warning' : 'secondary';
            html += `<div class="col-md-6"><div class="grading-system-card"><div class="d-flex justify-content-between align-items-start"><div><h6 class="mb-1">${system.system_name}</h6><span class="badge bg-secondary">${system.system_code}</span><span class="badge bg-info">${system.system_type}</span><span class="badge bg-${statusBadge}">${system.status}</span>${system.is_default ? '<span class="badge bg-primary">Default</span>' : ''}</div><div><button class="btn btn-outline-primary btn-sm" onclick="editGradingSystem(${system.grading_system_id})"><i class="fas fa-edit"></i></button><button class="btn btn-outline-success btn-sm" onclick="setDefaultGradingSystem(${system.grading_system_id})"><i class="fas fa-check"></i></button></div></div></div></div>`;
        });
        html += '</div>';
        container.innerHTML = html;
    }

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

    function editGradingSystem(id) {
        var systems = getMockGradingSystems();
        var system = systems.find(function(s) {
            return s.grading_system_id === id;
        });
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
        }
    }

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

    function setDefaultGradingSystem(id) {
        if (!confirm('Set this as default?')) return;
        showAlert('Default grading system updated!', 'success');
        loadGradingSystems();
    }

    // ============================================================
    // AGGREGATION RULES FUNCTIONS
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

    function loadAggregationRules() {
        var container = document.getElementById('aggregationRulesListContainer');
        if (!container) return;
        container.innerHTML = `<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="text-muted mt-2">Loading aggregation rules...</p></div>`;
        setTimeout(function() {
            renderAggregationRules(getMockAggregationRules());
        }, 300);
    }

    function renderAggregationRules(rules) {
        var container = document.getElementById('aggregationRulesListContainer');
        if (!container) return;
        if (rules.length === 0) {
            container.innerHTML = `<div class="text-center py-5"><i class="fas fa-calculator fa-3x text-muted mb-3"></i><p class="text-muted">No aggregation rules defined.</p></div>`;
            return;
        }
        var html = '<div class="row g-3">';
        rules.forEach(function(rule) {
            var statusBadge = rule.status === 'active' ? 'success' : rule.status === 'draft' ? 'warning' : 'secondary';
            html += `<div class="col-md-6"><div class="aggregation-rule-card"><div class="d-flex justify-content-between align-items-start"><div><h6 class="mb-1">${rule.rule_name}</h6><span class="badge bg-secondary">${rule.rule_code}</span><span class="badge bg-${statusBadge}">${rule.status}</span></div><div><button class="btn btn-outline-primary btn-sm" onclick="editAggregationRule(${rule.aggregation_rule_id})"><i class="fas fa-edit"></i></button><button class="btn btn-outline-info btn-sm" onclick="previewAggregation(${rule.aggregation_rule_id})"><i class="fas fa-eye"></i></button><button class="btn btn-outline-danger btn-sm" onclick="deleteAggregationRule(${rule.aggregation_rule_id})"><i class="fas fa-trash"></i></button></div></div><div class="aggregation-rule-meta"><span>Core: ${rule.core_count}</span><span>Electives: ${rule.elective_count}</span><span>Method: ${rule.aggregation_method.replace('_', ' ')}</span></div></div></div>`;
        });
        html += '</div>';
        container.innerHTML = html;
    }

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

    function editAggregationRule(id) {
        var rules = getMockAggregationRules();
        var rule = rules.find(function(r) {
            return r.aggregation_rule_id === id;
        });
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
        }
    }

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

    function deleteAggregationRule(id) {
        if (!confirm('Delete this rule?')) return;
        showAlert('Aggregation rule deleted!', 'success');
        loadAggregationRules();
    }

    function previewAggregation(id) {
        showAlert('Preview ready!', 'success');
    }

    // ============================================================
    // SUBJECT RULES FUNCTIONS
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

    function loadSubjectRules() {
        var container = document.getElementById('subjectRulesListContainer');
        if (!container) return;
        container.innerHTML = `<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="text-muted mt-2">Loading subject rules...</p></div>`;
        setTimeout(function() {
            renderSubjectRules(getMockSubjectRules());
        }, 300);
    }

    function renderSubjectRules(rules) {
        var container = document.getElementById('subjectRulesListContainer');
        if (!container) return;
        if (rules.length === 0) {
            container.innerHTML = `<div class="text-center py-5"><i class="fas fa-book fa-3x text-muted mb-3"></i><p class="text-muted">No subject rules defined.</p></div>`;
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
        var html = '',
            labels = {
                'core': 'Core Subjects',
                'elective': 'Elective Subjects'
            };
        for (var type in grouped) {
            var label = labels[type] || type.charAt(0).toUpperCase() + type.slice(1);
            html += `<h6 class="text-muted mt-3 mb-2">${label}</h6><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Subject</th><th>Level</th><th>Mandatory</th><th>Actions</th></tr></thead><tbody>`;
            grouped[type].forEach(function(rule) {
                html += `<tr><td><strong>${rule.subject_name}</strong></td><td><span class="badge bg-secondary">${rule.level_name || 'All Levels'}</span></td><td>${rule.is_mandatory ? '<span class="badge bg-danger">Yes</span>' : '<span class="badge bg-secondary">No</span>'}</td><td><button class="btn btn-outline-primary btn-sm" onclick="editSubjectRule(${rule.id})"><i class="fas fa-edit"></i></button><button class="btn btn-outline-danger btn-sm" onclick="deleteSubjectRule(${rule.id})"><i class="fas fa-trash"></i></button></td></tr>`;
            });
            html += `</tbody></table></div>`;
        }
        container.innerHTML = html;
    }

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

    function editSubjectRule(id) {
        var rules = getMockSubjectRules();
        var rule = rules.find(function(r) {
            return r.id === id;
        });
        if (rule) {
            document.getElementById('subjectRuleId').value = rule.id;
            document.getElementById('subjectRuleModalTitle').textContent = 'Edit Subject Classification Rule';
            document.getElementById('srSubject').value = rule.subject_name;
            document.getElementById('srClassification').value = rule.classification_code;
            document.getElementById('srLevel').value = rule.level_name || '';
            document.getElementById('srMandatory').checked = rule.is_mandatory;
            var modal = new bootstrap.Modal(document.getElementById('subjectRuleModal'));
            modal.show();
        }
    }

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

    function deleteSubjectRule(id) {
        if (!confirm('Delete this rule?')) return;
        showAlert('Subject rule deleted!', 'success');
        loadSubjectRules();
    }

    // ============================================================
    // REMARKS FUNCTIONS
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

    function loadRemarks() {
        var container = document.getElementById('remarksListContainer');
        if (!container) return;
        container.innerHTML = `<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="text-muted mt-2">Loading remark rules...</p></div>`;
        setTimeout(function() {
            renderRemarks(getMockRemarks());
        }, 300);
    }

    function renderRemarks(remarks) {
        var container = document.getElementById('remarksListContainer');
        if (!container) return;
        if (remarks.length === 0) {
            container.innerHTML = `<div class="text-center py-5"><i class="fas fa-comment-dots fa-3x text-muted mb-3"></i><p class="text-muted">No remark rules defined.</p></div>`;
            return;
        }
        var html = '<div class="row g-3">';
        remarks.forEach(function(remark) {
            var scoreRange = (remark.min_score || 0) + ' - ' + (remark.max_score || 100);
            html += `<div class="col-md-6"><div class="remark-card"><div class="d-flex justify-content-between align-items-start"><div><h6 class="mb-1">${remark.rule_name}</h6><span class="badge bg-secondary">${remark.rule_code}</span><span class="badge bg-info">${scoreRange}</span></div><div><button class="btn btn-outline-primary btn-sm" onclick="editRemarkRule(${remark.remark_rule_id})"><i class="fas fa-edit"></i></button><button class="btn btn-outline-danger btn-sm" onclick="deleteRemarkRule(${remark.remark_rule_id})"><i class="fas fa-trash"></i></button></div></div><div class="remark-text">${remark.remark_text}</div></div></div>`;
        });
        html += '</div>';
        container.innerHTML = html;
    }

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

    function editRemarkRule(id) {
        var remarks = getMockRemarks();
        var remark = remarks.find(function(r) {
            return r.remark_rule_id === id;
        });
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
        }
    }

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

    function deleteRemarkRule(id) {
        if (!confirm('Delete this rule?')) return;
        showAlert('Remark rule deleted!', 'success');
        loadRemarks();
    }

    // ============================================================
    // HISTORY FUNCTIONS
    // ============================================================
    function getMockHistory() {
        return [{
                'id': 1,
                'action': 'created',
                'field_name': 'JHS WAEC Profile',
                'old_value': null,
                'new_value': 'Active',
                'changed_by': 'Admin User',
                'created_at': new Date().toISOString()
            },
            {
                'id': 2,
                'action': 'updated',
                'field_name': 'Primary Standard Profile',
                'old_value': 'Draft',
                'new_value': 'Active',
                'changed_by': 'Admin User',
                'created_at': new Date(Date.now() - 3600000).toISOString()
            },
            {
                'id': 3,
                'action': 'created',
                'field_name': 'SHS WAEC Profile',
                'old_value': null,
                'new_value': 'Draft',
                'changed_by': 'Admin User',
                'created_at': new Date(Date.now() - 7200000).toISOString()
            }
        ];
    }

    function loadHistory() {
        var container = document.getElementById('historyListContainer');
        if (!container) return;
        container.innerHTML = `<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="text-muted mt-2">Loading history...</p></div>`;
        setTimeout(function() {
            renderHistory(getMockHistory());
        }, 300);
    }

    function renderHistory(logs) {
        var container = document.getElementById('historyListContainer');
        if (!container) return;
        if (logs.length === 0) {
            container.innerHTML = `<div class="text-center py-5"><i class="fas fa-clock fa-3x text-muted mb-3"></i><p class="text-muted">No changes recorded yet.</p></div>`;
            return;
        }
        var html = `<div class="table-responsive"><table class="table table-hover"><thead><tr><th>Action</th><th>Item</th><th>Details</th><th>User</th><th>Time</th></tr></thead><tbody>`;
        logs.forEach(function(log) {
            var actionBadge = log.action === 'created' ? 'success' : log.action === 'updated' ? 'primary' : log.action === 'deleted' ? 'danger' : 'secondary';
            var details = '';
            if (log.old_value && log.new_value) {
                details = log.old_value + ' → ' + log.new_value;
            } else if (log.new_value) {
                details = '→ ' + log.new_value;
            } else {
                details = '—';
            }
            html += `<tr><td><span class="badge bg-${actionBadge}">${log.action}</span></td><td><strong>${log.field_name}</strong></td><td class="text-muted small">${details}</td><td>${log.changed_by || 'System'}</td><td class="text-muted small">${new Date(log.created_at).toLocaleString()}</td></tr>`;
        });
        html += `</tbody></table></div>`;
        container.innerHTML = html;
    }

    // ============================================================
    // KEYBOARD SHORTCUTS
    // ============================================================
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
            e.preventDefault();
            location.reload();
        }
    });

    // ============================================================
    // INITIALIZE - Load the active section
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        var section = '<?php echo $activeSection; ?>';
        if (section === 'profiles') loadProfiles();
        else if (section === 'components') loadComponents();
        else if (section === 'grading') loadGradingSystems();
        else if (section === 'aggregation') loadAggregationRules();
        else if (section === 'subjects') loadSubjectRules();
        else if (section === 'remarks') loadRemarks();
        else if (section === 'history') loadHistory();
    });
</script>

<style>
    .assessment-nav .nav-link.active {
        color: #4facfe !important;
        background: transparent !important;
        border-bottom: 3px solid #4facfe !important;
        border-radius: 0 !important;
    }

    .assessment-nav .nav-link:hover {
        color: #1a1a2e !important;
        background: #f8f9fa !important;
        border-radius: 8px 8px 0 0 !important;
    }

    .section-panel {
        display: none;
    }

    .section-panel.active {
        display: block;
    }

    .component-row {
        background: #f8f9fa;
        padding: 8px 12px;
        border-radius: 8px;
    }

    .component-row input {
        border: 1px solid #e9ecf;
        border-radius: 6px;
        padding: 6px 10px;
        font-size: 13px;
    }

    .component-row input:focus {
        border-color: #4facfe;
        outline: none;
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

    .required {
        color: #dc3545;
    }

    .stat-card {
        background: #fff;
        border-radius: 10px;
        padding: 12px 16px;
        border: 1px solid #e9ecf;
        text-align: center;
    }

    .stat-card .stat-number {
        font-size: 22px;
        font-weight: 700;
    }

    .stat-card .stat-label {
        font-size: 12px;
        color: #6c757d;
    }

    .profile-card {
        background: #fff;
        border-radius: 12px;
        padding: 16px 20px;
        border: 2px solid #e9ecf;
        transition: all 0.3s;
        height: 100%;
    }

    .profile-card:hover {
        border-color: #4facfe;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        transform: translateY(-2px);
    }

    .profile-card.active {
        border-color: #28a745;
        background: #f8fff9;
    }

    .profile-card.draft {
        border-color: #ffc107;
        background: #fff8e1;
    }

    .profile-card .profile-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 8px;
    }

    .profile-card .profile-info {
        display: flex;
        gap: 12px;
        align-items: center;
    }

    .profile-card .profile-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        color: #fff;
        flex-shrink: 0;
        background: linear-gradient(135deg, #4facfe, #00f2fe);
    }

    .profile-card .profile-name {
        font-weight: 600;
        font-size: 14px;
    }

    .profile-card .profile-type {
        font-size: 12px;
        color: #6c757d;
    }

    .profile-card .profile-meta {
        display: flex;
        gap: 12px;
        font-size: 12px;
        color: #6c757d;
        margin: 8px 0;
        flex-wrap: wrap;
    }

    .profile-card .profile-components {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        margin: 8px 0;
    }

    .profile-card .component-badge {
        background: #f0f2f5;
        padding: 2px 10px;
        border-radius: 12px;
        font-size: 11px;
        color: #495057;
    }

    .profile-card .profile-actions {
        display: flex;
        gap: 4px;
        flex-wrap: wrap;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid #f0f2f5;
    }

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