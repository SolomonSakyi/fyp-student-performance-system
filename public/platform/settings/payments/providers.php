<div class="card-custom">
    <div class="card-header-custom">
        <h6><i class="fas fa-plug me-2 text-primary"></i>Payment Providers</h6>
        <button class="btn btn-primary btn-sm" onclick="showAddProviderModal()">
            <i class="fas fa-plus me-2"></i> Add Provider
        </button>
    </div>
    <div class="card-body-custom">
        <div id="providerList">
            <div class="text-center py-4">
                <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                <span class="ms-2 text-muted">Loading providers...</span>
            </div>
        </div>
    </div>
</div>

<!-- Provider Configuration Modal -->
<div class="modal fade" id="providerConfigModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,0.15);">
            <div class="modal-header" style="border-bottom:1px solid #f0f2f5;padding:16px 24px;">
                <h5 class="modal-title"><i class="fas fa-cog me-2 text-primary"></i> <span id="providerConfigTitle">Configure Provider</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:24px;">
                <form id="providerConfigForm">
                    <input type="hidden" id="configProviderId">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-2">
                                <label class="form-label">Provider Name <span class="required">*</span></label>
                                <input type="text" class="form-control" id="configProviderName" placeholder="e.g., Hubtel" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-2">
                                <label class="form-label">Provider Type <span class="required">*</span></label>
                                <select class="form-select" id="configProviderType" required>
                                    <option value="">Select Type</option>
                                    <option value="mobile_money">Mobile Money</option>
                                    <option value="card">Card Payment</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="multi_currency">Multi-Currency</option>
                                    <option value="custom">Custom</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-2">
                                <label class="form-label">API Key / Client ID <span class="required">*</span></label>
                                <input type="text" class="form-control credential" id="configApiKey" placeholder="Enter API Key" required>
                                <div class="form-text">This credential will be encrypted before storage</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-2">
                                <label class="form-label">API Secret / Client Secret <span class="required">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control credential" id="configApiSecret" placeholder="Enter API Secret" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('configApiSecret')">
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
                                <select class="form-select" id="configEnvironment">
                                    <option value="sandbox">Sandbox (Test)</option>
                                    <option value="production">Production (Live)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-2">
                                <label class="form-label">Status</label>
                                <select class="form-select" id="configStatus">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="pending">Pending</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Description</label>
                        <textarea class="form-control textarea" id="configDescription" placeholder="Provider description" rows="2"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="border-top:1px solid #f0f2f5;padding:16px 24px;">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveProviderConfig()"><i class="fas fa-save me-2"></i> Save Configuration</button>
            </div>
        </div>
    </div>
</div>

<script>
    // ================================================
    // LOAD PROVIDERS
    // ================================================
    async function loadProviders() {
        try {
            const response = await fetch(`${API_BASE}/settings/payments/providers`, {
                headers: getHeaders()
            });
            const result = await response.json();

            const container = document.getElementById('providerList');

            if (result.success && result.data && result.data.length > 0) {
                let html = '';
                const providers = result.data;

                providers.forEach((provider) => {
                    const isActive = provider.status === 'active';
                    const iconClass = provider.provider_type === 'mobile_money' ? 'hubtel' :
                        provider.provider_type === 'bank_transfer' ? 'bank' :
                        provider.provider_type === 'card' ? 'paystack' : 'flutterwave';
                    const icon = provider.provider_type === 'mobile_money' ? 'fa-mobile-alt' :
                        provider.provider_type === 'bank_transfer' ? 'fa-university' :
                        provider.provider_type === 'card' ? 'fa-credit-card' : 'fa-globe';

                    html += `
                        <div class="provider-card ${isActive ? 'active' : 'inactive'}">
                            <div class="provider-header">
                                <div class="provider-info">
                                    <div class="provider-icon ${iconClass}"><i class="fas ${icon}"></i></div>
                                    <div>
                                        <div class="provider-name">${provider.provider_name}</div>
                                        <div class="provider-type">${provider.provider_type}</div>
                                    </div>
                                </div>
                                <div>
                                    <span class="provider-status ${isActive ? 'active' : 'inactive'}">${isActive ? 'Active' : 'Inactive'}</span>
                                </div>
                            </div>
                            <div class="provider-actions">
                                <button class="btn btn-outline-primary btn-sm" onclick="editProvider(${provider.id})"><i class="fas fa-cog me-1"></i> Configure</button>
                                <button class="btn btn-outline-success btn-sm" onclick="testProvider(${provider.id})"><i class="fas fa-plug me-1"></i> Test</button>
                                <button class="btn btn-outline-secondary btn-sm" onclick="toggleProvider(${provider.id})"><i class="fas ${isActive ? 'fa-pause' : 'fa-play'} me-1"></i> ${isActive ? 'Pause' : 'Activate'}</button>
                                <button class="btn btn-outline-danger btn-sm" onclick="deleteProvider(${provider.id})"><i class="fas fa-trash me-1"></i></button>
                            </div>
                        </div>
                    `;
                });

                container.innerHTML = html;
                document.getElementById('providerCount').textContent = providers.length;
            } else {
                container.innerHTML = `
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-plug fa-2x mb-2 d-block"></i>
                        No payment providers configured yet.
                        <br><button class="btn btn-primary btn-sm mt-2" onclick="showAddProviderModal()">Add Provider</button>
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error loading providers:', error);
            document.getElementById('providerList').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    Failed to load providers: ${error.message}
                </div>
            `;
        }
    }

    // ================================================
    // EDIT PROVIDER
    // ================================================
    async function editProvider(providerId) {
        try {
            const response = await fetch(`${API_BASE}/settings/payments/provider/${providerId}`, {
                headers: getHeaders()
            });
            const result = await response.json();

            if (result.success && result.data) {
                const provider = result.data;
                document.getElementById('configProviderId').value = provider.id;
                document.getElementById('providerConfigTitle').textContent = `Configure ${provider.provider_name}`;
                document.getElementById('configProviderName').value = provider.provider_name;
                document.getElementById('configProviderType').value = provider.provider_type;
                document.getElementById('configApiKey').value = provider.api_key_visible || '';
                document.getElementById('configApiSecret').value = '';
                document.getElementById('configEnvironment').value = provider.environment || 'sandbox';
                document.getElementById('configStatus').value = provider.status || 'pending';
                document.getElementById('configDescription').value = provider.description || '';

                const modal = new bootstrap.Modal(document.getElementById('providerConfigModal'));
                modal.show();
            }
        } catch (error) {
            showAlert('Error loading provider: ' + error.message, 'danger');
        }
    }

    // ================================================
    // SHOW ADD PROVIDER MODAL
    // ================================================
    function showAddProviderModal() {
        document.getElementById('configProviderId').value = '';
        document.getElementById('providerConfigTitle').textContent = 'Add Payment Provider';
        document.getElementById('configProviderName').value = '';
        document.getElementById('configProviderType').value = '';
        document.getElementById('configApiKey').value = '';
        document.getElementById('configApiSecret').value = '';
        document.getElementById('configEnvironment').value = 'sandbox';
        document.getElementById('configStatus').value = 'pending';
        document.getElementById('configDescription').value = '';

        const modal = new bootstrap.Modal(document.getElementById('providerConfigModal'));
        modal.show();
    }

    // ================================================
    // SAVE PROVIDER CONFIG
    // ================================================
    async function saveProviderConfig() {
        const form = document.getElementById('providerConfigForm');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const providerId = document.getElementById('configProviderId').value;

        const data = {
            provider_code: document.getElementById('configProviderName').value.toLowerCase().replace(/\s+/g, '_'),
            provider_name: document.getElementById('configProviderName').value,
            provider_type: document.getElementById('configProviderType').value,
            api_key: document.getElementById('configApiKey').value.trim(),
            api_secret: document.getElementById('configApiSecret').value,
            environment: document.getElementById('configEnvironment').value,
            status: document.getElementById('configStatus').value,
            description: document.getElementById('configDescription').value
        };

        try {
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
                showAlert('✅ Provider configuration saved successfully!', 'success');
                document.getElementById('providerConfigModal').querySelector('.btn-close').click();
                loadProviders();
            } else {
                showAlert('❌ ' + (result.message || 'Failed to save provider'), 'danger');
            }
        } catch (error) {
            showAlert('Error saving provider: ' + error.message, 'danger');
        }
    }

    // ================================================
    // TEST PROVIDER
    // ================================================
    async function testProvider(providerId) {
        showAlert('Testing connection...', 'info');

        try {
            const response = await fetch(`${API_BASE}/settings/payments/provider/${providerId}/test`, {
                method: 'POST',
                headers: getHeaders()
            });

            const result = await response.json();

            if (result.success) {
                showAlert('✅ Connection test successful!', 'success');
            } else {
                showAlert('❌ Connection test failed: ' + (result.message || 'Unknown error'), 'danger');
            }
        } catch (error) {
            showAlert('Error testing connection: ' + error.message, 'danger');
        }
    }

    // ================================================
    // TOGGLE PROVIDER
    // ================================================
    async function toggleProvider(providerId) {
        try {
            const response = await fetch(`${API_BASE}/settings/payments/provider/${providerId}`, {
                headers: getHeaders()
            });
            const result = await response.json();

            if (result.success && result.data) {
                const currentStatus = result.data.status;
                const newStatus = currentStatus === 'active' ? 'inactive' : 'active';

                const updateResponse = await fetch(`${API_BASE}/settings/payments/provider/${providerId}`, {
                    method: 'PUT',
                    headers: getHeaders(),
                    body: JSON.stringify({
                        ...result.data,
                        status: newStatus
                    })
                });

                const updateResult = await updateResponse.json();

                if (updateResult.success) {
                    showAlert(`✅ Provider ${newStatus === 'active' ? 'activated' : 'paused'} successfully!`, 'success');
                    loadProviders();
                } else {
                    showAlert('❌ ' + (updateResult.message || 'Failed to update provider status'), 'danger');
                }
            }
        } catch (error) {
            showAlert('Error toggling provider: ' + error.message, 'danger');
        }
    }

    // ================================================
    // DELETE PROVIDER
    // ================================================
    async function deleteProvider(providerId) {
        if (!confirm('Are you sure you want to delete this provider?')) {
            return;
        }

        try {
            const response = await fetch(`${API_BASE}/settings/payments/provider/${providerId}`, {
                method: 'DELETE',
                headers: getHeaders()
            });

            const result = await response.json();

            if (result.success) {
                showAlert('✅ Provider deleted successfully!', 'success');
                loadProviders();
            } else {
                showAlert('❌ ' + (result.message || 'Failed to delete provider'), 'danger');
            }
        } catch (error) {
            showAlert('Error deleting provider: ' + error.message, 'danger');
        }
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
        loadProviders();
    });
</script>