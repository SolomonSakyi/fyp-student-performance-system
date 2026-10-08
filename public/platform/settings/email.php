<!-- Email Settings -->
<div class="card-custom">
    <div class="card-header-custom">
        <h6><i class="fas fa-envelope me-2 text-primary"></i>Email / SMTP Settings</h6>
    </div>
    <div class="card-body-custom">
        <form id="emailForm" onsubmit="saveEmailSettings(event)">
            <div class="row">
                <div class="col-md-6 col-12">
                    <div class="mb-2">
                        <label class="form-label">SMTP Host <span class="required">*</span></label>
                        <input type="text" class="form-control" id="smtpHost" placeholder="smtp.gmail.com" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">SMTP Port <span class="required">*</span></label>
                        <input type="number" class="form-control" id="smtpPort" placeholder="587" value="587" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">SMTP Username <span class="required">*</span></label>
                        <input type="text" class="form-control" id="smtpUsername" placeholder="username@example.com" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">SMTP Password <span class="required">*</span></label>
                        <input type="password" class="form-control" id="smtpPassword" placeholder="Enter password" required>
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="mb-2">
                        <label class="form-label">From Email <span class="required">*</span></label>
                        <input type="email" class="form-control" id="fromEmail" placeholder="noreply@edutrack.com" required>
                        <small class="form-text">Email address used as the sender</small>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">From Name <span class="required">*</span></label>
                        <input type="text" class="form-control" id="fromName" placeholder="EduTrack Platform" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Encryption</label>
                        <select class="form-select" id="smtpEncryption">
                            <option value="tls">TLS</option>
                            <option value="ssl">SSL</option>
                            <option value="none">None</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <button type="button" class="btn btn-outline-primary" onclick="testEmailConnection()">
                            <i class="fas fa-paper-plane me-2"></i> Test Connection
                        </button>
                        <small class="form-text d-block mt-1">Test the SMTP connection before saving</small>
                    </div>
                </div>
            </div>
            <hr class="my-3">
            <div class="d-flex gap-2 flex-wrap justify-content-end">
                <button type="submit" class="btn btn-primary" id="emailSubmitBtn">
                    <i class="fas fa-save me-2"></i> Save Email Settings
                </button>
            </div>
        </form>
    </div>
</div>
<script>
    // ================================================
    // VALIDATE EMAIL FORM
    // ================================================
    function validateEmailForm() {
        const host = document.getElementById('smtpHost').value.trim();
        const port = document.getElementById('smtpPort').value.trim();
        const username = document.getElementById('smtpUsername').value.trim();
        const password = document.getElementById('smtpPassword').value;
        const fromEmail = document.getElementById('fromEmail').value.trim();
        const fromName = document.getElementById('fromName').value.trim();
        const encryption = document.getElementById('smtpEncryption').value;

        // SMTP Host
        if (host === '') {
            return {
                valid: false,
                message: 'SMTP Host is required.'
            };
        }
        const isLocalhost = (host === 'localhost' || host === '127.0.0.1' || host === '::1');
        if (!isLocalhost) {
            if (host.includes(' ')) {
                return {
                    valid: false,
                    message: 'SMTP Host must not contain spaces.'
                };
            }
            if (host.startsWith('.') || host.endsWith('.')) {
                return {
                    valid: false,
                    message: 'SMTP Host must not start or end with a dot.'
                };
            }
            if (host.includes('/') || host.includes(':') || host.includes('@')) {
                return {
                    valid: false,
                    message: 'SMTP Host must not contain /, :, or @.'
                };
            }
            if (!host.includes('.')) {
                return {
                    valid: false,
                    message: 'SMTP Host must contain a dot, e.g. smtp.gmail.com.'
                };
            }
        }

        // SMTP Port
        if (port === '') {
            return {
                valid: false,
                message: 'SMTP Port is required.'
            };
        }
        const portNum = parseInt(port, 10);
        if (isNaN(portNum) || portNum < 1 || portNum > 65535) {
            return {
                valid: false,
                message: 'SMTP Port must be a number between 1 and 65535.'
            };
        }

        // SMTP Username
        if (username === '') {
            return {
                valid: false,
                message: 'SMTP Username is required.'
            };
        }

        // SMTP Password
        if (password === '') {
            return {
                valid: false,
                message: 'SMTP Password is required.'
            };
        }

        // From Email
        if (fromEmail === '') {
            return {
                valid: false,
                message: 'From Email is required.'
            };
        }
        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailPattern.test(fromEmail)) {
            return {
                valid: false,
                message: 'From Email must be a valid email address.'
            };
        }

        // From Name
        if (fromName === '') {
            return {
                valid: false,
                message: 'From Name is required.'
            };
        }

        // Encryption
        if (encryption !== 'tls' && encryption !== 'ssl' && encryption !== 'none') {
            return {
                valid: false,
                message: 'Encryption must be TLS, SSL, or None.'
            };
        }

        return {
            valid: true
        };
    }

    // ================================================
    // TEST EMAIL CONNECTION
    // ================================================
    async function testEmailConnection() {
        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Testing...';

        try {
            const check = validateEmailForm();
            if (!check.valid) {
                showAlert('✗ ' + check.message, 'danger');
                return;
            }

            const data = {
                host: document.getElementById('smtpHost').value.trim(),
                port: parseInt(document.getElementById('smtpPort').value) || 587,
                username: document.getElementById('smtpUsername').value.trim(),
                password: document.getElementById('smtpPassword').value,
                encryption: document.getElementById('smtpEncryption').value
            };

            const response = await fetch(`${API_BASE}/index.php?endpoint=settings&action=email_test`, {
                method: 'POST',
                headers: getHeaders(),
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                showAlert('SMTP connection successful!', 'success');
            } else {
                showAlert('✗ Connection failed: ' + (result.message || 'Unknown error'), 'danger');
            }
        } catch (error) {
            console.error('Error testing connection:', error);
            showAlert('Error testing connection: ' + error.message, 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }

    // ================================================
    // LOAD EXISTING EMAIL SETTINGS
    // ================================================
    async function loadEmailSettings() {
        try {
            const response = await fetch(`${API_BASE}/index.php?endpoint=settings&action=email`, {
                method: 'GET',
                headers: getHeaders()
            });

            const result = await response.json();

            if (!result.success || !result.data) {
                return;
            }

            const data = result.data;

            if (data.smtp_host) document.getElementById('smtpHost').value = data.smtp_host;
            if (data.smtp_port) document.getElementById('smtpPort').value = data.smtp_port;
            if (data.smtp_username) document.getElementById('smtpUsername').value = data.smtp_username;
            if (data.smtp_password) document.getElementById('smtpPassword').value = data.smtp_password;
            if (data.from_email) document.getElementById('fromEmail').value = data.from_email;
            if (data.from_name) document.getElementById('fromName').value = data.from_name;
            if (data.smtp_encryption) document.getElementById('smtpEncryption').value = data.smtp_encryption;
        } catch (error) {
            console.error('Error loading email settings:', error);
        }
    }

    // ================================================
    // SAVE EMAIL SETTINGS
    // ================================================
    async function saveEmailSettings(event) {
        event.preventDefault();

        const submitBtn = document.getElementById('emailSubmitBtn');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

        try {
            const check = validateEmailForm();
            if (!check.valid) {
                showAlert('✗ ' + check.message, 'danger');
                return;
            }

            const data = {
                smtp_host: document.getElementById('smtpHost').value.trim(),
                smtp_port: parseInt(document.getElementById('smtpPort').value) || 587,
                smtp_username: document.getElementById('smtpUsername').value.trim(),
                smtp_password: document.getElementById('smtpPassword').value,
                from_email: document.getElementById('fromEmail').value.trim(),
                from_name: document.getElementById('fromName').value.trim(),
                smtp_encryption: document.getElementById('smtpEncryption').value
            };

            const response = await fetch(`${API_BASE}/index.php?endpoint=settings&action=email`, {
                method: 'POST',
                headers: getHeaders(),
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                showAlert('Email settings saved successfully!', 'success');
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
    document.addEventListener('DOMContentLoaded', loadEmailSettings);
</script>