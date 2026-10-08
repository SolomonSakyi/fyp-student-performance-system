<!-- General Settings -->
<div class="card-custom">
    <div class="card-header-custom">
        <h6><i class="fas fa-sliders-h me-2 text-primary"></i>General Settings</h6>
    </div>
    <div class="card-body-custom">
        <form id="generalForm" onsubmit="saveGeneralSettings(event)">
            <div class="row">
                <div class="col-md-6 col-12">
                    <div class="mb-2">
                        <label class="form-label">Platform Name <span class="required">*</span></label>
                        <input type="text" class="form-control" id="platformName" placeholder="EduTrack" value="EduTrack" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Platform URL <span class="required">*</span></label>
                        <input type="url" class="form-control" id="platformUrl" placeholder="https://edutrack.com" value="http://localhost:8000" required>
                        <small class="form-text">The base URL of your platform</small>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Default Language</label>
                        <select class="form-select" id="defaultLanguage">
                            <option value="en">English</option>
                            <option value="fr">French</option>
                            <option value="es">Spanish</option>
                            <option value="pt">Portuguese</option>
                            <option value="ar">Arabic</option>
                            <option value="zh">Chinese</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Default Timezone</label>
                        <select class="form-select" id="defaultTimezone">
                            <option value="UTC">UTC</option>
                            <option value="Africa/Accra" selected>Africa/Accra (GMT+0)</option>
                            <option value="Africa/Lagos">Africa/Lagos (GMT+1)</option>
                            <option value="Africa/Johannesburg">Africa/Johannesburg (GMT+2)</option>
                            <option value="America/New_York">America/New_York (GMT-5)</option>
                            <option value="America/Los_Angeles">America/Los_Angeles (GMT-8)</option>
                            <option value="Europe/London">Europe/London (GMT+0)</option>
                            <option value="Europe/Paris">Europe/Paris (GMT+1)</option>
                            <option value="Asia/Dubai">Asia/Dubai (GMT+4)</option>
                            <option value="Asia/Singapore">Asia/Singapore (GMT+8)</option>
                            <option value="Australia/Sydney">Australia/Sydney (GMT+11)</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="mb-2">
                        <label class="form-label">Default Currency</label>
                        <select class="form-select" id="defaultCurrency">
                            <option value="USD">USD - US Dollar</option>
                            <option value="GHS" selected>GHS - Ghana Cedi</option>
                            <option value="NGN">NGN - Nigerian Naira</option>
                            <option value="KES">KES - Kenyan Shilling</option>
                            <option value="ZAR">ZAR - South African Rand</option>
                            <option value="EUR">EUR - Euro</option>
                            <option value="GBP">GBP - British Pound</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Date Format</label>
                        <select class="form-select" id="dateFormat">
                            <option value="Y-m-d">YYYY-MM-DD</option>
                            <option value="d/m/Y">DD/MM/YYYY</option>
                            <option value="m/d/Y">MM/DD/YYYY</option>
                            <option value="d M Y">DD Mon YYYY</option>
                            <option value="M d Y">Mon DD YYYY</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Academic Calendar Type</label>
                        <select class="form-select" id="academicCalendarType">
                            <option value="semester">Semester</option>
                            <option value="trimester">Trimester</option>
                            <option value="quarter">Quarter</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="allowRegistration" checked>
                            <label class="form-check-label" for="allowRegistration">
                                Allow Tenant Registration
                            </label>
                        </div>
                        <small class="form-text">Allow new tenants to register on the platform</small>
                    </div>
                </div>
            </div>

            <hr class="my-3">

            <div class="d-flex gap-2 flex-wrap justify-content-end">
                <button type="submit" class="btn btn-primary" id="generalSubmitBtn">
                    <i class="fas fa-save me-2"></i> Save General Settings
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // ================================================
    // LOAD EXISTING SETTINGS
    // ================================================
    async function loadGeneralSettings() {
        try {
            const response = await fetch(`${API_BASE}/index.php?endpoint=settings&action=general`, {
                method: 'GET',
                headers: getHeaders()
            });

            const result = await response.json();

            if (!result.success || !result.data) {
                return;
            }

            const data = result.data;

            if (data.platform_name) document.getElementById('platformName').value = data.platform_name;
            if (data.platform_url) document.getElementById('platformUrl').value = data.platform_url;
            if (data.default_language) document.getElementById('defaultLanguage').value = data.default_language;
            if (data.default_timezone) document.getElementById('defaultTimezone').value = data.default_timezone;
            if (data.default_currency) document.getElementById('defaultCurrency').value = data.default_currency;
            if (data.date_format) document.getElementById('dateFormat').value = data.date_format;
            if (data.academic_calendar_type) document.getElementById('academicCalendarType').value = data.academic_calendar_type;
            if (data.allow_registration !== undefined) {
                document.getElementById('allowRegistration').checked = (data.allow_registration == 1);
            }
        } catch (error) {
            console.error('Error loading general settings:', error);
        }
    }

    // ================================================
    // SAVE GENERAL SETTINGS
    // ================================================
    async function saveGeneralSettings(event) {
        event.preventDefault();

        const submitBtn = document.getElementById('generalSubmitBtn');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

        try {
            const data = {
                platform_name: document.getElementById('platformName').value.trim(),
                platform_url: document.getElementById('platformUrl').value.trim(),
                default_language: document.getElementById('defaultLanguage').value,
                default_timezone: document.getElementById('defaultTimezone').value,
                default_currency: document.getElementById('defaultCurrency').value,
                date_format: document.getElementById('dateFormat').value,
                academic_calendar_type: document.getElementById('academicCalendarType').value,
                allow_registration: document.getElementById('allowRegistration').checked ? 1 : 0
            };

            const response = await fetch(`${API_BASE}/index.php?endpoint=settings&action=general`, {
                method: 'POST',
                headers: getHeaders(),
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                showAlert('General settings saved successfully!', 'success');
            } else {
                showAlert('✗ ' + (result.message || 'Failed to save settings'), 'danger');
            }
        } catch (error) {
            console.error('Error saving settings:', error);
            showAlert('Error saving settings: ' + error.message, 'danger');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    }

    // Load existing settings when page loads
    document.addEventListener('DOMContentLoaded', loadGeneralSettings);
</script>