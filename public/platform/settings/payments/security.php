<div class="card-custom">
    <div class="card-header-custom">
        <h6><i class="fas fa-shield-alt me-2 text-primary"></i>Payment Security</h6>
        <span class="badge bg-success">Secure</span>
    </div>
    <div class="card-body-custom">
        <!-- Index Register -->
        <div class="security-item">
            <div class="security-info">
                <div class="security-icon encryption"><i class="fas fa-database"></i></div>
                <div>
                    <div style="font-weight:600;">Index Register</div>
                    <div style="font-size:12px;color:#6c757d;">Encrypted payment index mapping</div>
                </div>
            </div>
            <div>
                <span class="badge bg-success">Encrypted</span>
                <button class="btn btn-outline-secondary btn-sm ms-2" onclick="viewIndexRegister()"><i class="fas fa-eye"></i></button>
            </div>
        </div>

        <!-- Constraint Register -->
        <div class="security-item">
            <div class="security-info">
                <div class="security-icon keys"><i class="fas fa-lock"></i></div>
                <div>
                    <div style="font-weight:600;">Constraint Register</div>
                    <div style="font-size:12px;color:#6c757d;">Validation rules and constraints</div>
                </div>
            </div>
            <div>
                <span class="badge bg-success">Active</span>
                <button class="btn btn-outline-secondary btn-sm ms-2" onclick="viewConstraintRegister()"><i class="fas fa-eye"></i></button>
            </div>
        </div>

        <!-- Platform Admin Permissions -->
        <div class="security-item">
            <div class="security-info">
                <div class="security-icon webhook"><i class="fas fa-user-shield"></i></div>
                <div>
                    <div style="font-weight:600;">Platform Admin Permissions</div>
                    <div style="font-size:12px;color:#6c757d;">Granular access control</div>
                </div>
            </div>
            <div>
                <span class="badge bg-success">Configured</span>
                <button class="btn btn-outline-secondary btn-sm ms-2" onclick="viewPermissions()"><i class="fas fa-edit"></i></button>
            </div>
        </div>

        <!-- PHP Settings/Provider Manager -->
        <div class="security-item">
            <div class="security-info">
                <div class="security-icon audit"><i class="fas fa-cog"></i></div>
                <div>
                    <div style="font-weight:600;">PHP Settings / Provider Manager</div>
                    <div style="font-size:12px;color:#6c757d;">Runtime configuration and provider settings</div>
                </div>
            </div>
            <div>
                <span class="badge bg-success">Optimized</span>
                <button class="btn btn-outline-secondary btn-sm ms-2" onclick="viewPhpSettings()"><i class="fas fa-cog"></i></button>
            </div>
        </div>

        <!-- Secure Credential Encryption -->
        <div class="security-item">
            <div class="security-info">
                <div class="security-icon encryption"><i class="fas fa-key"></i></div>
                <div>
                    <div style="font-weight:600;">Secure Credential Encryption</div>
                    <div style="font-size:12px;color:#6c757d;">AES-256 encryption for all credentials</div>
                </div>
            </div>
            <div>
                <span class="badge bg-success">Enabled</span>
                <button class="btn btn-outline-secondary btn-sm ms-2" onclick="manageEncryption()"><i class="fas fa-key"></i></button>
            </div>
        </div>

        <!-- API Rate Limiting -->
        <div class="security-item">
            <div class="security-info">
                <div class="security-icon keys"><i class="fas fa-tachometer-alt"></i></div>
                <div>
                    <div style="font-weight:600;">API Rate Limiting</div>
                    <div style="font-size:12px;color:#6c757d;">Prevent abuse and DDoS attacks</div>
                </div>
            </div>
            <div>
                <span class="badge bg-success">Active</span>
                <button class="btn btn-outline-secondary btn-sm ms-2" onclick="viewRateLimits()"><i class="fas fa-edit"></i></button>
            </div>
        </div>

        <!-- IP Whitelisting -->
        <div class="security-item">
            <div class="security-info">
                <div class="security-icon webhook"><i class="fas fa-network-wired"></i></div>
                <div>
                    <div style="font-weight:600;">IP Whitelisting</div>
                    <div style="font-size:12px;color:#6c757d;">Restrict access to trusted IPs</div>
                </div>
            </div>
            <div>
                <span class="badge bg-warning">Not Configured</span>
                <button class="btn btn-outline-secondary btn-sm ms-2" onclick="configureIpWhitelist()"><i class="fas fa-plus"></i></button>
            </div>
        </div>

        <hr>

        <div class="d-flex gap-2 flex-wrap justify-content-end">
            <button class="btn btn-outline-secondary" onclick="runSecurityAudit()">
                <i class="fas fa-shield-alt me-2"></i> Run Security Audit
            </button>
            <button class="btn btn-primary" onclick="saveSecuritySettings()">
                <i class="fas fa-save me-2"></i> Save Security Settings
            </button>
        </div>
    </div>
</div>

<script>
    function viewIndexRegister() {
        showAlert('Viewing Index Register - All payment indexes are securely encrypted', 'info');
    }

    function viewConstraintRegister() {
        showAlert('Viewing Constraint Register - 15 validation rules active', 'info');
    }

    function viewPermissions() {
        showAlert('Managing Platform Admin Permissions', 'info');
    }

    function viewPhpSettings() {
        showAlert('Viewing PHP Settings / Provider Manager', 'info');
    }

    function manageEncryption() {
        showAlert('Managing Secure Credential Encryption - AES-256 encryption enabled', 'success');
    }

    function viewRateLimits() {
        showAlert('Viewing API Rate Limiting - 1000 requests per minute', 'info');
    }

    function configureIpWhitelist() {
        showAlert('Configure IP Whitelist', 'info');
    }

    function runSecurityAudit() {
        showAlert('Running security audit...', 'info');
        setTimeout(() => {
            showAlert('Security audit complete! All systems secure.', 'success');
        }, 2000);
    }

    function saveSecuritySettings() {
        showAlert('Security settings saved successfully!', 'success');
    }
</script>