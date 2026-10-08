<div class="card-custom">
    <div class="card-header-custom">
        <h6><i class="fas fa-mobile-alt me-2 text-primary"></i>Hubtel Configuration</h6>
        <span class="badge bg-warning" id="hubtelStatusBadge">Pending</span>
    </div>
    <div class="card-body-custom">
        <form id="hubtelConfigForm" onsubmit="saveHubtelConfig(event)">
            <input type="hidden" id="hubtelProviderId" value="">

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Client ID <span class="required">*</span></label>
                        <input type="text" class="form-control credential" id="hubtelClientId" placeholder="Enter Hubtel Client ID" required>
                        <div class="form-text">Find this in your Hubtel dashboard</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Client Secret <span class="required">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control credential" id="hubtelClientSecret" placeholder="Enter Hubtel Client Secret" required>
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('hubtelClientSecret')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="form-text">This credential will be encrypted before storage</div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Environment</label>
                        <select class="form-select" id="hubtelEnvironment">
                            <option value="sandbox">Sandbox (Test)</option>
                            <option value="production">Production (Live)</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Status</label>
                        <select class="form-select" id="hubtelStatus">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="maintenance">Maintenance</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label">Supported Payment Methods</label>
                <div class="d-flex flex-wrap gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="hubtelMomo" checked>
                        <label class="form-check-label" for="hubtelMomo">Mobile Money</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="hubtelCard" checked>
                        <label class="form-check-label" for="hubtelCard">Card Payments</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="hubtelBank" checked>
                        <label class="form-check-label" for="hubtelBank">Bank Transfer</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="hubtelQr">
                        <label class="form-check-label" for="hubtelQr">QR Code</label>
                    </div>
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label">Allowed Networks</label>
                <div class="d-flex flex-wrap gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="hubtelMtn" checked>
                        <label class="form-check-label" for="hubtelMtn">MTN</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="hubtelVodafone" checked>
                        <label class="form-check-label" for="hubtelVodafone">Vodafone</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="hubtelTigo" checked>
                        <label class="form-check-label" for="hubtelTigo">Tigo</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="hubtelAirtel">
                        <label class="form-check-label" for="hubtelAirtel">Airtel</label>
                    </div>
                </div>
            </div>

            <hr>

            <div class="d-flex gap-2 flex-wrap justify-content-end">
                <button type="button" class="btn btn-outline-success" onclick="testHubtelConnection()">
                    <i class="fas fa-plug me-2"></i> Test Connection
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i> Save Configuration
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // ================================================
    // LOAD HUBTEL CONFIGURATION
    // ================================================
    async function loadHubtelConfig() {
        try {
            const response = await fetch(`${API_BASE}/settings/payments/hubtel`, {
                headers: getHeaders()
            });
            const result = await response.json();

            if (result.success && result.data) {
                const config = result.data;
                document.getElementById('hubtelProviderId').value = config.id || '';
                document.getElementById('hubtelClientId').value = config.api_key_visible || '';
                document.getElementById('hubtelEnvironment').value = config.environment || 'sandbox';
                document.getElementById('hubtelStatus').value = config.status || 'active';

                if (config.additional_config) {
                    const additional = typeof config.additional_config === 'string' ?
                        JSON.parse(config.additional_config) : config.additional_config;

                    if (additional.methods) {
                        document.getElementById('hubtelMomo').checked = additional.methods.momo || false;
                        document.getElementById('hubtelCard').checked = additional.methods.card || false;
                        document.getElementById('hubtelBank').checked = additional.methods.bank || false;
                        document.getElementById('hubtelQr').checked = additional.methods.qr || false;
                    }

                    if (additional.networks) {
                        document.getElementById('hubtelMtn').checked = additional.networks.mtn || false;
                        document.getElementById('hubtelVodafone').checked = additional.networks.vodafone || false;
                        document.getElementById('hubtelTigo').checked = additional.networks.tigo || false;
                        document.getElementById('hubtelAirtel').checked = additional.networks.airtel || false;
                    }
                }

                updateStatusBadge(config.status);
            }
        } catch (error) {
            console.error('Error loading Hubtel config:', error);
        }
    }

    // ================================================
    // TEST HUBTEL CONNECTION
    // ================================================
    async function testHubtelConnection() {
        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Testing...';

        try {
            const data = {
                client_id: document.getElementById('hubtelClientId').value.trim(),
                client_secret: document.getElementById('hubtelClientSecret').value,
                environment: document.getElementById('hubtelEnvironment').value
            };

            const response = await fetch(`${API_BASE}/settings/payments/hubtel/test`, {
                method: 'POST',
                headers: getHeaders(),
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                showAlert('✅ Hubtel connection successful! API is responding.', 'success');
                updateStatusBadge('active');
            } else {
                showAlert('❌ Hubtel connection failed: ' + (result.message || 'Unknown error'), 'danger');
                updateStatusBadge('inactive');
            }
        } catch (error) {
            showAlert('Error testing connection: ' + error.message, 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }

    // ================================================
    // SAVE HUBTEL CONFIGURATION
    // ================================================
    async function saveHubtelConfig(event) {
        event.preventDefault();

        const form = document.getElementById('hubtelConfigForm');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

        try {
            const providerId = document.getElementById('hubtelProviderId').value;

            const data = {
                provider_code: 'hubtel',
                provider_name: 'Hubtel',
                provider_type: 'mobile_money',
                api_key: document.getElementById('hubtelClientId').value.trim(),
                api_secret: document.getElementById('hubtelClientSecret').value,
                environment: document.getElementById('hubtelEnvironment').value,
                status: document.getElementById('hubtelStatus').value,
                additional_config: {
                    methods: {
                        momo: document.getElementById('hubtelMomo').checked,
                        card: document.getElementById('hubtelCard').checked,
                        bank: document.getElementById('hubtelBank').checked,
                        qr: document.getElementById('hubtelQr').checked
                    },
                    networks: {
                        mtn: document.getElementById('hubtelMtn').checked,
                        vodafone: document.getElementById('hubtelVodafone').checked,
                        tigo: document.getElementById('hubtelTigo').checked,
                        airtel: document.getElementById('hubtelAirtel').checked
                    }
                }
            };

            let url = `${API_BASE}/settings/payments/provider`;
            let method = 'POST';

            if (providerId) {
                url += `/${providerId}`;
                method = 'PUT';
            }

            const response = await fetch(url, {
                method: method,
                headers: getHeaders(),
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                showAlert('✅ Hubtel configuration saved successfully!', 'success');
                document.getElementById('hubtelProviderId').value = result.provider_id || providerId;
                updateStatusBadge(document.getElementById('hubtelStatus').value);
            } else {
                showAlert('❌ ' + (result.message || 'Failed to save configuration'), 'danger');
            }
        } catch (error) {
            showAlert('Error saving configuration: ' + error.message, 'danger');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    }

    // ================================================
    // UPDATE STATUS BADGE
    // ================================================
    function updateStatusBadge(status) {
        const badge = document.getElementById('hubtelStatusBadge');
        if (!badge) return;

        const statusMap = {
            'active': {
                class: 'bg-success',
                text: 'Active'
            },
            'inactive': {
                class: 'bg-danger',
                text: 'Inactive'
            },
            'maintenance': {
                class: 'bg-warning',
                text: 'Maintenance'
            },
            'pending': {
                class: 'bg-warning',
                text: 'Pending'
            }
        };

        const info = statusMap[status] || statusMap['pending'];
        badge.className = `badge ${info.class}`;
        badge.textContent = info.text;
    }

    // ================================================
    // TOGGLE PASSWORD VISIBILITY
    // ================================================
    function togglePasswordVisibility(id) {
        const input = document.getElementById(id);
        const btn = input.parentElement.querySelector('.btn');
        if (input.type === 'password') {
            input.type = 'text';
            btn.innerHTML = '<i class="fas fa-eye-slash"></i>';
        } else {
            input.type = 'password';
            btn.innerHTML = '<i class="fas fa-eye"></i>';
        }
    }

    // ================================================
    // INITIALIZE
    // ================================================
    document.addEventListener('DOMContentLoaded', function() {
        loadHubtelConfig();
    });
</script>