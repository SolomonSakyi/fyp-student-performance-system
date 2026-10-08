<!-- Security Settings -->
<div class="card-custom">
    <div class="card-header-custom">
        <h6><i class="fas fa-shield-alt me-2 text-primary"></i>Security Settings</h6>
    </div>
    <div class="card-body-custom">
        <form id="securityForm" onsubmit="saveSecuritySettings(event)">
            <div class="row">
                <div class="col-md-6 col-12">
                    <div class="mb-2">
                        <label class="form-label">Password Minimum Length</label>
                        <input type="number" class="form-control" id="minPasswordLength" value="8" min="6" max="20">
                        <small class="form-text">Minimum characters required for passwords (6-20)</small>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Session Timeout (minutes)</label>
                        <input type="number" class="form-control" id="sessionTimeout" value="60" min="5" max="1440">
                        <small class="form-text">Time before inactive sessions expire (5-1440 minutes)</small>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Max Login Attempts</label>
                        <input type="number" class="form-control" id="maxLoginAttempts" value="5" min="3" max="20">
                        <small class="form-text">Failed login attempts before account lockout (3-20)</small>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Lockout Duration (minutes)</label>
                        <input type="number" class="form-control" id="lockoutDuration" value="30" min="5" max="1440">
                        <small class="form-text">Account lockout duration after max attempts (5-1440 minutes)</small>
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="mb-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="twoFactorAuth" checked>
                            <label class="form-check-label" for="twoFactorAuth">
                                Enable Two-Factor Authentication
                            </label>
                        </div>
                        <small class="form-text">Require 2FA for all admin accounts</small>
                    </div>
                    <div class="mb-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="forceStrongPasswords" checked>
                            <label class="form-check-label" for="forceStrongPasswords">
                                Force Strong Passwords
                            </label>
                        </div>
                        <small class="form-text">Require uppercase, lowercase, numbers, and special characters</small>
                    </div>
                    <div class="mb-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="auditLogging" checked>
                            <label class="form-check-label" for="auditLogging">
                                Enable Audit Logging
                            </label>
                        </div>
                        <small class="form-text">Log all admin actions for security auditing</small>
                    </div>
                    <div class="mb-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="ipWhitelist">
                            <label class="form-check-label" for="ipWhitelist">
                                Enable IP Whitelist
                            </label>
                        </div>
                        <small class="form-text">Restrict admin access to whitelisted IPs</small>
                    </div>
                    <div class="mb-2" id="ipWhitelistInput" style="display:none;">
                        <label class="form-label">Whitelisted IPs</label>
                        <textarea class="form-control textarea" id="whitelistedIps" placeholder="One IP per line&#10;192.168.1.1&#10;10.0.0.1"></textarea>
                        <small class="form-text">Enter one IP address per line</small>
                    </div>
                </div>
            </div>

            <div class="row mt-3">
                <div class="col-md-12 col-12">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Security Warning:</strong> Changing security settings may affect user access. Please review changes carefully.
                    </div>
                </div>
            </div>

            <hr class="my-3">

            <div class="d-flex gap-2 flex-wrap justify-content-end">
                <button type="submit" class="btn btn-primary" id="securitySubmitBtn">
                    <i class="fas fa-save me-2"></i> Save Security Settings
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // ================================================
    // IP WHITELIST TOGGLE
    // ================================================
    document.addEventListener('DOMContentLoaded', function() {
        const ipWhitelistCheckbox = document.getElementById('ipWhitelist');
        const ipInput = document.getElementById('ipWhitelistInput');

        ipWhitelistCheckbox.addEventListener('change', function() {
            ipInput.style.display = this.checked ? 'block' : 'none';
        });
    });

    // ================================================
    // LOAD EXISTING SECURITY SETTINGS
    // ================================================
    async function loadSecuritySettings() {
        try {
            const response = await fetch(`${API_BASE}/index.php?endpoint=settings&action=security`, {
                method: 'GET',
                headers: getHeaders()
            });

            const result = await response.json();

            if (!result.success || !result.data) {
                return;
            }

            const data = result.data;

            if (data.min_password_length) document.getElementById('minPasswordLength').value = data.min_password_length;
            if (data.session_timeout_minutes) document.getElementById('sessionTimeout').value = data.session_timeout_minutes;
            if (data.max_login_attempts) document.getElementById('maxLoginAttempts').value = data.max_login_attempts;
            if (data.lockout_duration_minutes) document.getElementById('lockoutDuration').value = data.lockout_duration_minutes;
            document.getElementById('twoFactorAuth').checked = (data.require_2fa == 1);
            document.getElementById('forceStrongPasswords').checked =
                (data.require_uppercase == 1 && data.require_lowercase == 1 &&
                    data.require_numbers == 1 && data.require_special_chars == 1);
            document.getElementById('auditLogging').checked = (data.audit_logging_enabled == 1);
            document.getElementById('ipWhitelist').checked = (data.ip_whitelist_enabled == 1);
            if (data.ip_whitelist) document.getElementById('whitelistedIps').value = data.ip_whitelist;

            // Show/hide the IP whitelist textarea based on the checkbox
            document.getElementById('ipWhitelistInput').style.display =
                document.getElementById('ipWhitelist').checked ? 'block' : 'none';
        } catch (error) {
            console.error('Error loading security settings:', error);
        }
    }

    // ================================================
    // SAVE SECURITY SETTINGS
    // ================================================
    async function saveSecuritySettings(event) {
        event.preventDefault();

        const submitBtn = document.getElementById('securitySubmitBtn');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

        try {
            const strongPasswordsChecked = document.getElementById('forceStrongPasswords').checked ? 1 : 0;

            const data = {
                min_password_length: parseInt(document.getElementById('minPasswordLength').value) || 8,
                session_timeout_minutes: parseInt(document.getElementById('sessionTimeout').value) || 60,
                max_login_attempts: parseInt(document.getElementById('maxLoginAttempts').value) || 5,
                lockout_duration_minutes: parseInt(document.getElementById('lockoutDuration').value) || 30,
                require_2fa: document.getElementById('twoFactorAuth').checked ? 1 : 0,
                require_uppercase: strongPasswordsChecked,
                require_lowercase: strongPasswordsChecked,
                require_numbers: strongPasswordsChecked,
                require_special_chars: strongPasswordsChecked,
                audit_logging_enabled: document.getElementById('auditLogging').checked ? 1 : 0,
                ip_whitelist_enabled: document.getElementById('ipWhitelist').checked ? 1 : 0,
                ip_whitelist: document.getElementById('whitelistedIps').value
            };

            const response = await fetch(`${API_BASE}/index.php?endpoint=settings&action=security`, {
                method: 'POST',
                headers: getHeaders(),
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                showAlert('Security settings saved successfully!', 'success');
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
    document.addEventListener('DOMContentLoaded', loadSecuritySettings);
</script>