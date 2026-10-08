<!-- General Settings Tab -->
<div class="tab-content active">
    <div class="card-custom">
        <div class="card-header-custom">
            <h6><i class="fas fa-cog me-2 text-primary"></i>General Settings</h6>
            <span class="text-muted" style="font-size:12px;">Basic school information and preferences</span>
        </div>
        <div class="card-body-custom">
            <form id="generalSettingsForm">
                <div class="row">
                    <div class="col-md-6 col-12">
                        <div class="mb-2">
                            <label class="form-label">School Name <span class="required">*</span></label>
                            <input type="text" class="form-control" id="schoolName" placeholder="Enter school name" value="My School">
                        </div>
                    </div>
                    <div class="col-md-6 col-12">
                        <div class="mb-2">
                            <label class="form-label">School Code</label>
                            <input type="text" class="form-control" id="schoolCode" placeholder="e.g., SCH-001" value="SCH-001">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 col-12">
                        <div class="mb-2">
                            <label class="form-label">School Type</label>
                            <select class="form-select" id="schoolType">
                                <option value="public">Public</option>
                                <option value="private">Private</option>
                                <option value="international">International</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6 col-12">
                        <div class="mb-2">
                            <label class="form-label">Status</label>
                            <select class="form-select" id="schoolStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label">Address</label>
                    <textarea class="form-control" id="schoolAddress" rows="2" placeholder="School address">123 Education Avenue, Accra, Ghana</textarea>
                </div>
                <div class="d-flex gap-2 flex-wrap justify-content-end">
                    <button type="button" class="btn btn-primary" onclick="saveGeneralSettings()">
                        <i class="fas fa-save me-2"></i> Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function saveGeneralSettings() {
    showAlert('General settings saved successfully!', 'success');
}
</script>