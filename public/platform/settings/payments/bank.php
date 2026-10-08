<div class="card-custom">
    <div class="card-header-custom">
        <h6><i class="fas fa-university me-2 text-primary"></i>Bank API Configuration</h6>
        <span class="badge bg-warning" id="bankStatusBadge">Pending</span>
    </div>
    <div class="card-body-custom">
        <form id="bankConfigForm" onsubmit="saveBankConfig(event)">
            <input type="hidden" id="bankProviderId" value="">

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Bank API Key <span class="required">*</span></label>
                        <input type="text" class="form-control credential" id="bankApiKey" placeholder="Enter Bank API Key" required>
                        <div class="form-text">Provided by your bank partner</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Bank API Secret <span class="required">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control credential" id="bankApiSecret" placeholder="Enter Bank API Secret" required>
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('bankApiSecret')">
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
                        <label class="form-label">Bank Name</label>
                        <select class="form-select" id="bankName">
                            <option value="ghana_commercial_bank">Ghana Commercial Bank</option>
                            <option value="ecobank" selected>Ecobank</option>
                            <option value="standard_chartered">Standard Chartered</option>
                            <option value="access_bank">Access Bank</option>
                            <option value="absa">Absa Bank</option>
                            <option value="fidelity">Fidelity Bank</option>
                            <option value="stanbic">Stanbic Bank</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">API Version</label>
                        <select class="form-select" id="bankApiVersion">
                            <option value="v1">v1</option>
                            <option value="v2" selected>v2</option>
                            <option value="v3">v3</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Base URL <span class="required">*</span></label>
                        <input type="url" class="form-control" id="bankBaseUrl" placeholder="https://api.bank.com/v2" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Account Number</label>
                        <input type="text" class="form-control" id="bankAccountNumber" placeholder="Enter account number">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Account Name</label>
                        <input type="text" class="form-control" id="bankAccountName" placeholder="Enter account name">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Branch Code</label>
                        <input type="text" class="form-control" id="bankBranchCode" placeholder="Enter branch code">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Currency</label>
                        <select class="form-select" id="bankCurrency">
                            <option value="GHS" selected>Ghana Cedi (GHS)</option>
                            <option value="USD">US Dollar (USD)</option>
                            <option value="EUR">Euro (EUR)</option>
                            <option value="GBP">British Pound (GBP)</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Status</label>
                        <select class="form-select" id="bankStatus">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label">Supported Transaction Types</label>
                <div class="d-flex flex-wrap gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="bankTransfer" checked>
                        <label class="form-check-label" for="bankTransfer">Bank Transfer</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="bankDirectDebit">
                        <label class="form-check-label" for="bankDirectDebit">Direct Debit</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="bankCheque">
                        <label class="form-check-label" for="bankCheque">Cheque</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="bankWire">
                        <label class="form-check-label" for="bankWire">Wire Transfer</label>
                    </div>
                </div>
            </div>

            <hr>

            <div class="card-custom" style="background:#f8f9fa;border:1px solid #e9ecef;margin-bottom:16px;">
                <div class="card-header-custom" style="padding:12px 20px;">
                    <h6 style="font-size:14px;font-weight:600;"><i class="fas fa-plug me-2 text-primary"></i>Test Connection</h6>
                </div>
                <div class="card-body-custom" style="padding:16px 20px;">
                    <div class="row align-items-end">
                        <div class="col-md-8">
                            <div class="mb-2 mb-md-0">
                                <label class="form-label">Test Account Number</label>
                                <input type="text" class="form-control" id="testAccountNumber" placeholder="Enter account number to test">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <button type="button" class="btn btn-outline-success w-100" onclick="testBankConnection()">
                                <i class="fas fa-plug me-2"></i> Test Connection
                            </button>
                        </div>
                    </div>
                    <div id="testResult" style="margin-top:12px;display:none;"></div>
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap justify-content-end">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i> Save Configuration
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // ================================================
    // LOAD BANK CONFIGURATION
    // ================================================
    async function loadBankConfig() {
        try {
            const response = await fetch(`${API_BASE}/settings/payments/bank`, {
                headers: getHeaders()
            });
            const result = await response.json();

            if (result.success && result.data) {
                const config = result.data;
                document.getElementById('bankProviderId').value = config.id || '';
                document.getElementById('bankApiKey').value = config.api_key_visible || '';
                document.getElementById('bankName').value = config.additional_config?.bank_name || 'ecobank';
                document.getElementById('bankApiVersion').value = config.additional_config?.api_version || 'v2';
                document.getElementById('bankBaseUrl').value = config.additional_config?.base_url || '';
                document.getElementById('bankAccountNumber').value = config.additional_config?.account_number || '';
                document.getElementById('bankAccountName').value = config.additional_config?.account_name || '';
                document.getElementById('bankBranchCode').value = config.additional_config?.branch_code || '';
                document.getElementById('bankCurrency').value = config.additional_config?.currency || 'GHS';
                document.getElementById('bankStatus').value = config.status || 'active';

                if (config.additional_config?.transaction_types) {
                    const types = config.additional_config.transaction_types;
                    document.getElementById('bankTransfer').checked = types.transfer || false;
                    document.getElementById('bankDirectDebit').checked = types.direct_debit || false;
                    document.getElementById('bankCheque').checked = types.cheque || false;
                    document.getElementById('bankWire').checked = types.wire || false;
                }

                updateBankStatusBadge(config.status);
            }
        } catch (error) {
            console.error('Error loading Bank config:', error);
        }
    }

    // ================================================
    // TEST BANK CONNECTION
    // ================================================
    async function testBankConnection() {
        const testAccount = document.getElementById('testAccountNumber').value;
        const resultDiv = document.getElementById('testResult');

        resultDiv.style.display = 'block';
        resultDiv.innerHTML = `
            <div class="alert alert-info" style="border-radius:10px;border:none;">
                <i class="fas fa-spinner fa-spin me-2"></i> Testing Bank API connection for account ${testAccount || '...'}...
            </div>
        `;

        try {
            const data = {
                api_key: document.getElementById('bankApiKey').value.trim(),
                api_secret: document.getElementById('bankApiSecret').value,
                test_account: testAccount
            };

            const response = await fetch(`${API_BASE}/settings/payments/bank/test`, {
                method: 'POST',
                headers: getHeaders(),
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                resultDiv.innerHTML = `
                    <div class="alert alert-success" style="border-radius:10px;border:none;">
                        <i class="fas fa-check-circle me-2"></i> 
                        <strong>Connection successful!</strong><br>
                        ${result.message || 'Bank API connection verified successfully.'}
                        ${result.response_time ? `<br><span style="font-size:12px;">Response time: ${result.response_time}</span>` : ''}
                    </div>
                `;
                updateBankStatusBadge('active');
            } else {
                resultDiv.innerHTML = `
                    <div class="alert alert-danger" style="border-radius:10px;border:none;">
                        <i class="fas fa-exclamation-circle me-2"></i> 
                        <strong>Connection failed!</strong><br>
                        ${result.message || 'Unable to verify bank API connection. Please check your credentials.'}
                    </div>
                `;
                updateBankStatusBadge('inactive');
            }
        } catch (error) {
            resultDiv.innerHTML = `
                <div class="alert alert-danger" style="border-radius:10px;border:none;">
                    <i class="fas fa-exclamation-circle me-2"></i> 
                    <strong>Connection error!</strong><br>
                    ${error.message}
                </div>
            `;
        }
    }

    // ================================================
    // SAVE BANK CONFIGURATION
    // ================================================
    async function saveBankConfig(event) {
        event.preventDefault();

        const form = document.getElementById('bankConfigForm');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

        try {
            const providerId = document.getElementById('bankProviderId').value;

            const data = {
                provider_code: 'bank',
                provider_name: 'Bank API',
                provider_type: 'bank_transfer',
                api_key: document.getElementById('bankApiKey').value.trim(),
                api_secret: document.getElementById('bankApiSecret').value,
                status: document.getElementById('bankStatus').value,
                additional_config: {
                    bank_name: document.getElementById('bankName').value,
                    api_version: document.getElementById('bankApiVersion').value,
                    base_url: document.getElementById('bankBaseUrl').value.trim(),
                    account_number: document.getElementById('bankAccountNumber').value.trim(),
                    account_name: document.getElementById('bankAccountName').value.trim(),
                    branch_code: document.getElementById('bankBranchCode').value.trim(),
                    currency: document.getElementById('bankCurrency').value,
                    transaction_types: {
                        transfer: document.getElementById('bankTransfer').checked,
                        direct_debit: document.getElementById('bankDirectDebit').checked,
                        cheque: document.getElementById('bankCheque').checked,
                        wire: document.getElementById('bankWire').checked
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
                showAlert('✅ Bank API configuration saved successfully!', 'success');
                document.getElementById('bankProviderId').value = result.provider_id || providerId;
                updateBankStatusBadge(document.getElementById('bankStatus').value);
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
    function updateBankStatusBadge(status) {
        const badge = document.getElementById('bankStatusBadge');
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
        loadBankConfig();
    });
</script>