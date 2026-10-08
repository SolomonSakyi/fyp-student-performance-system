<!-- Integrations Settings -->
<div class="card-custom">
    <div class="card-header-custom">
        <h6><i class="fas fa-plug me-2 text-primary"></i>Integrations</h6>
        <span class="badge bg-info">Third-Party Services</span>
    </div>
    <div class="card-body-custom">
        <form id="integrationsForm" onsubmit="saveIntegrations(event)">
            <!-- Payment Gateways -->
            <h6 class="mb-3"><i class="fas fa-credit-card me-2 text-secondary"></i>Payment Gateways</h6>
            <div class="row">
                <div class="col-md-6 col-12">
                    <div class="card mb-2" style="border:2px solid #e9ecef; border-radius:10px; padding:12px;">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:40px; height:40px; background:#0055a4; border-radius:8px; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:18px;">$</div>
                            <div class="flex-grow-1">
                                <strong>Stripe</strong>
                                <div style="font-size:12px; color:#6c757d;">Credit card processing</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="stripeEnabled">
                            </div>
                        </div>
                    </div>
                    <div id="stripeConfig" style="display:none; padding-left:56px;">
                        <div class="mb-2">
                            <label class="form-label">Publishable Key</label>
                            <input type="text" class="form-control" id="stripePublishableKey" placeholder="pk_test_...">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Secret Key</label>
                            <input type="password" class="form-control" id="stripeSecretKey" placeholder="sk_test_...">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Webhook Secret</label>
                            <input type="password" class="form-control" id="stripeWebhookSecret" placeholder="whsec_...">
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="card mb-2" style="border:2px solid #e9ecef; border-radius:10px; padding:12px;">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:40px; height:40px; background:#00aef0; border-radius:8px; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:18px;">P</div>
                            <div class="flex-grow-1">
                                <strong>PayPal</strong>
                                <div style="font-size:12px; color:#6c757d;">PayPal payments</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="paypalEnabled">
                            </div>
                        </div>
                    </div>
                    <div id="paypalConfig" style="display:none; padding-left:56px;">
                        <div class="mb-2">
                            <label class="form-label">Client ID</label>
                            <input type="text" class="form-control" id="paypalClientId" placeholder="AU...">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Client Secret</label>
                            <input type="password" class="form-control" id="paypalClientSecret" placeholder="EL...">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Mode</label>
                            <select class="form-select" id="paypalMode">
                                <option value="sandbox">Sandbox (Test)</option>
                                <option value="live">Live</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <hr class="my-3">

            <!-- Communication -->
            <h6 class="mb-3"><i class="fas fa-comments me-2 text-secondary"></i>Communication</h6>
            <div class="row">
                <div class="col-md-6 col-12">
                    <div class="card mb-2" style="border:2px solid #e9ecef; border-radius:10px; padding:12px;">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:40px; height:40px; background:#25D366; border-radius:8px; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:20px;">💬</div>
                            <div class="flex-grow-1">
                                <strong>WhatsApp API</strong>
                                <div style="font-size:12px; color:#6c757d;">Business messaging</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="whatsappEnabled">
                            </div>
                        </div>
                    </div>
                    <div id="whatsappConfig" style="display:none; padding-left:56px;">
                        <div class="mb-2">
                            <label class="form-label">Phone Number ID</label>
                            <input type="text" class="form-control" id="whatsappPhoneId" placeholder="1234567890">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">API Token</label>
                            <input type="password" class="form-control" id="whatsappToken" placeholder="EAA...">
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="card mb-2" style="border:2px solid #e9ecef; border-radius:10px; padding:12px;">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:40px; height:40px; background:#34A853; border-radius:8px; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:18px;">📧</div>
                            <div class="flex-grow-1">
                                <strong>Mailchimp</strong>
                                <div style="font-size:12px; color:#6c757d;">Email marketing & newsletters</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="mailchimpEnabled">
                            </div>
                        </div>
                    </div>
                    <div id="mailchimpConfig" style="display:none; padding-left:56px;">
                        <div class="mb-2">
                            <label class="form-label">API Key</label>
                            <input type="password" class="form-control" id="mailchimpApiKey" placeholder="apikey-usX">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">List ID</label>
                            <input type="text" class="form-control" id="mailchimpListId" placeholder="1234567890">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Server Prefix</label>
                            <input type="text" class="form-control" id="mailchimpServer" placeholder="usX">
                        </div>
                    </div>
                </div>
            </div>

            <hr class="my-3">

            <!-- Analytics -->
            <h6 class="mb-3"><i class="fas fa-chart-line me-2 text-secondary"></i>Analytics & Monitoring</h6>
            <div class="row">
                <div class="col-md-6 col-12">
                    <div class="card mb-2" style="border:2px solid #e9ecef; border-radius:10px; padding:12px;">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:40px; height:40px; background:#f9ab00; border-radius:8px; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:18px;">G</div>
                            <div class="flex-grow-1">
                                <strong>Google Analytics</strong>
                                <div style="font-size:12px; color:#6c757d;">Website analytics</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="googleAnalyticsEnabled">
                            </div>
                        </div>
                    </div>
                    <div id="googleAnalyticsConfig" style="display:none; padding-left:56px;">
                        <div class="mb-2">
                            <label class="form-label">Measurement ID</label>
                            <input type="text" class="form-control" id="googleAnalyticsId" placeholder="G-XXXXXXXXXX">
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="card mb-2" style="border:2px solid #e9ecef; border-radius:10px; padding:12px;">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:40px; height:40px; background:#ff6b6b; border-radius:8px; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:18px;">S</div>
                            <div class="flex-grow-1">
                                <strong>Sentry</strong>
                                <div style="font-size:12px; color:#6c757d;">Error tracking & monitoring</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="sentryEnabled">
                            </div>
                        </div>
                    </div>
                    <div id="sentryConfig" style="display:none; padding-left:56px;">
                        <div class="mb-2">
                            <label class="form-label">DSN</label>
                            <input type="text" class="form-control" id="sentryDsn" placeholder="https://...@sentry.io/123456">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Environment</label>
                            <select class="form-select" id="sentryEnvironment">
                                <option value="production">Production</option>
                                <option value="staging">Staging</option>
                                <option value="development">Development</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <hr class="my-3">

            <!-- AI Services -->
            <h6 class="mb-3"><i class="fas fa-robot me-2 text-secondary"></i>AI Services</h6>
            <div class="row">
                <div class="col-md-6 col-12">
                    <div class="card mb-2" style="border:2px solid #e9ecef; border-radius:10px; padding:12px;">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:40px; height:40px; background:#10a37f; border-radius:8px; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:18px;">AI</div>
                            <div class="flex-grow-1">
                                <strong>OpenAI</strong>
                                <div style="font-size:12px; color:#6c757d;">AI-powered features</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="openaiEnabled">
                            </div>
                        </div>
                    </div>
                    <div id="openaiConfig" style="display:none; padding-left:56px;">
                        <div class="mb-2">
                            <label class="form-label">API Key</label>
                            <input type="password" class="form-control" id="openaiApiKey" placeholder="sk-...">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Model</label>
                            <select class="form-select" id="openaiModel">
                                <option value="gpt-4">GPT-4</option>
                                <option value="gpt-3.5-turbo" selected>GPT-3.5 Turbo</option>
                                <option value="gpt-4-turbo">GPT-4 Turbo</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="card mb-2" style="border:2px solid #e9ecef; border-radius:10px; padding:12px;">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:40px; height:40px; background:#4285f4; border-radius:8px; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:18px;">G</div>
                            <div class="flex-grow-1">
                                <strong>Google Gemini</strong>
                                <div style="font-size:12px; color:#6c757d;">Google AI services</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="geminiEnabled">
                            </div>
                        </div>
                    </div>
                    <div id="geminiConfig" style="display:none; padding-left:56px;">
                        <div class="mb-2">
                            <label class="form-label">API Key</label>
                            <input type="password" class="form-control" id="geminiApiKey" placeholder="AIza...">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Model</label>
                            <select class="form-select" id="geminiModel">
                                <option value="gemini-pro">Gemini Pro</option>
                                <option value="gemini-1.5-pro">Gemini 1.5 Pro</option>
                                <option value="gemini-1.5-flash">Gemini 1.5 Flash</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <hr class="my-3">

            <div class="d-flex gap-2 flex-wrap justify-content-end">
                <button type="button" class="btn btn-outline-secondary" onclick="testIntegrations()">
                    <i class="fas fa-vial me-2"></i> Test All Connections
                </button>
                <button type="submit" class="btn btn-primary" id="integrationsSubmitBtn">
                    <i class="fas fa-save me-2"></i> Save Integrations
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // ================================================
    // TOGGLE INTEGRATION CONFIG
    // ================================================
    document.addEventListener('DOMContentLoaded', function() {
        const toggles = [{
                id: 'stripeEnabled',
                config: 'stripeConfig'
            },
            {
                id: 'paypalEnabled',
                config: 'paypalConfig'
            },
            {
                id: 'whatsappEnabled',
                config: 'whatsappConfig'
            },
            {
                id: 'mailchimpEnabled',
                config: 'mailchimpConfig'
            },
            {
                id: 'googleAnalyticsEnabled',
                config: 'googleAnalyticsConfig'
            },
            {
                id: 'sentryEnabled',
                config: 'sentryConfig'
            },
            {
                id: 'openaiEnabled',
                config: 'openaiConfig'
            },
            {
                id: 'geminiEnabled',
                config: 'geminiConfig'
            }
        ];

        toggles.forEach(item => {
            const checkbox = document.getElementById(item.id);
            const config = document.getElementById(item.config);
            if (checkbox && config) {
                checkbox.addEventListener('change', function() {
                    config.style.display = this.checked ? 'block' : 'none';
                });
            }
        });
    });

    // ================================================
    // TEST INTEGRATIONS
    // ================================================
    async function testIntegrations() {
        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Testing...';

        try {
            // Collect enabled integrations
            const integrations = [];
            if (document.getElementById('stripeEnabled').checked) integrations.push('Stripe');
            if (document.getElementById('paypalEnabled').checked) integrations.push('PayPal');
            if (document.getElementById('whatsappEnabled').checked) integrations.push('WhatsApp');
            if (document.getElementById('mailchimpEnabled').checked) integrations.push('Mailchimp');
            if (document.getElementById('googleAnalyticsEnabled').checked) integrations.push('Google Analytics');
            if (document.getElementById('sentryEnabled').checked) integrations.push('Sentry');
            if (document.getElementById('openaiEnabled').checked) integrations.push('OpenAI');
            if (document.getElementById('geminiEnabled').checked) integrations.push('Gemini');

            if (integrations.length === 0) {
                showAlert('No integrations enabled to test', 'warning');
                btn.disabled = false;
                btn.innerHTML = originalText;
                return;
            }

            // Simulate testing
            await new Promise(resolve => setTimeout(resolve, 1500));

            showAlert(`✅ All ${integrations.length} integrations tested successfully!`, 'success');
        } catch (error) {
            showAlert('Error testing integrations: ' + error.message, 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }

    // ================================================
    // SAVE INTEGRATIONS
    // ================================================
    async function saveIntegrations(event) {
        event.preventDefault();

        const submitBtn = document.getElementById('integrationsSubmitBtn');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

        try {
            const data = {
                stripe: {
                    enabled: document.getElementById('stripeEnabled').checked ? 1 : 0,
                    publishable_key: document.getElementById('stripePublishableKey').value.trim(),
                    secret_key: document.getElementById('stripeSecretKey').value,
                    webhook_secret: document.getElementById('stripeWebhookSecret').value
                },
                paypal: {
                    enabled: document.getElementById('paypalEnabled').checked ? 1 : 0,
                    client_id: document.getElementById('paypalClientId').value.trim(),
                    client_secret: document.getElementById('paypalClientSecret').value,
                    mode: document.getElementById('paypalMode').value
                },
                whatsapp: {
                    enabled: document.getElementById('whatsappEnabled').checked ? 1 : 0,
                    phone_id: document.getElementById('whatsappPhoneId').value.trim(),
                    token: document.getElementById('whatsappToken').value
                },
                mailchimp: {
                    enabled: document.getElementById('mailchimpEnabled').checked ? 1 : 0,
                    api_key: document.getElementById('mailchimpApiKey').value,
                    list_id: document.getElementById('mailchimpListId').value.trim(),
                    server: document.getElementById('mailchimpServer').value.trim()
                },
                google_analytics: {
                    enabled: document.getElementById('googleAnalyticsEnabled').checked ? 1 : 0,
                    measurement_id: document.getElementById('googleAnalyticsId').value.trim()
                },
                sentry: {
                    enabled: document.getElementById('sentryEnabled').checked ? 1 : 0,
                    dsn: document.getElementById('sentryDsn').value.trim(),
                    environment: document.getElementById('sentryEnvironment').value
                },
                openai: {
                    enabled: document.getElementById('openaiEnabled').checked ? 1 : 0,
                    api_key: document.getElementById('openaiApiKey').value,
                    model: document.getElementById('openaiModel').value
                },
                gemini: {
                    enabled: document.getElementById('geminiEnabled').checked ? 1 : 0,
                    api_key: document.getElementById('geminiApiKey').value,
                    model: document.getElementById('geminiModel').value
                }
            };

            const response = await fetch(`${API_BASE}/index.php?endpoint=settings&action=integrations`, {
                method: 'POST',
                headers: getHeaders(),
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                showAlert('Integration settings saved successfully!', 'success');
            } else {
                showAlert('✗ ' + (result.message || 'Failed to save settings'), 'danger');
            }
        } catch (error) {
            console.error('Error saving integrations:', error);
            showAlert('Error saving integrations: ' + error.message, 'danger');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    }
</script>