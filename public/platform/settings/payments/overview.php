<div class="card-custom">
    <div class="card-header-custom">
        <h6><i class="fas fa-chart-simple me-2 text-primary"></i>Payment Overview</h6>
        <span class="badge bg-success">All Systems Operational</span>
    </div>
    <div class="card-body-custom">
        <div class="row" id="overviewStats">
            <div class="col-md-3 col-6 mb-3">
                <div class="stat-box">
                    <div class="stat-number" style="color:#4facfe;" id="activeProviders">0</div>
                    <div class="stat-label">Active Providers</div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="stat-box">
                    <div class="stat-number" style="color:#28a745;" id="paymentMethods">0</div>
                    <div class="stat-label">Payment Methods</div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="stat-box">
                    <div class="stat-number" style="color:#ffc107;" id="totalTransactions">0</div>
                    <div class="stat-label">Total Transactions</div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="stat-box">
                    <div class="stat-number" style="color:#6c5ce7;" id="totalRevenue">₵ 0.00</div>
                    <div class="stat-label">Total Revenue</div>
                </div>
            </div>
        </div>

        <hr>

        <div class="row">
            <div class="col-md-6 mb-3">
                <h6 style="font-weight:600;font-size:14px;margin-bottom:12px;"><i class="fas fa-heartbeat me-2 text-primary"></i>Provider Health Status</h6>
                <div id="healthStatus">
                    <div class="text-center py-3">
                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                        <span class="ms-2 text-muted">Loading provider status...</span>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <h6 style="font-weight:600;font-size:14px;margin-bottom:12px;"><i class="fas fa-bolt me-2 text-warning"></i>Recent Activity</h6>
                <div id="recentActivity">
                    <div class="text-muted text-center py-2">
                        <i class="fas fa-info-circle me-2"></i>No recent activity
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // ================================================
    // OVERVIEW - Load with fallback to mock data
    // ================================================
    (function() {
        'use strict';

        // Mock data for when API is not available
        const mockProviders = [{
                id: 1,
                provider_name: 'Hubtel',
                provider_type: 'mobile_money',
                status: 'active'
            },
            {
                id: 2,
                provider_name: 'Bank API',
                provider_type: 'bank_transfer',
                status: 'pending'
            },
            {
                id: 3,
                provider_name: 'Paystack',
                provider_type: 'card',
                status: 'inactive'
            }
        ];

        const mockAuditLogs = [{
                created_at: new Date().toISOString(),
                action_type: 'UPDATE',
                resource: 'Provider Configuration'
            },
            {
                created_at: new Date(Date.now() - 3600000).toISOString(),
                action_type: 'CREATE',
                resource: 'Payment Setting'
            },
            {
                created_at: new Date(Date.now() - 7200000).toISOString(),
                action_type: 'TEST',
                resource: 'Hubtel Connection'
            }
        ];

        // Get API base from parent or use default
        const API_BASE_URL = typeof API_BASE !== 'undefined' ? API_BASE : 'http://localhost:8000/api/platform';
        const TENANT_ID_VAL = typeof TENANT_ID !== 'undefined' ? TENANT_ID : 1;
        const SCHOOL_ID_VAL = typeof SCHOOL_ID !== 'undefined' ? SCHOOL_ID : 1;

        function getHeaders() {
            return {
                'Content-Type': 'application/json',
                'X-Tenant-ID': TENANT_ID_VAL,
                'X-School-ID': SCHOOL_ID_VAL
            };
        }

        // Use parent showAlert if available
        function showNotification(message, type) {
            if (typeof showAlert === 'function') {
                showAlert(message, type);
            } else {
                console.log('[' + (type || 'info') + ']', message);
            }
        }

        function renderHealthStatus(providers) {
            const container = document.getElementById('healthStatus');
            if (!container) return;

            if (!providers || providers.length === 0) {
                container.innerHTML = `
                    <div class="text-muted text-center py-2">
                        <i class="fas fa-info-circle me-2"></i>No providers configured
                    </div>
                `;
                return;
            }

            let html = '';
            providers.forEach(p => {
                const isActive = p.status === 'active';
                const color = isActive ? '#28a745' : '#dc3545';
                const statusText = isActive ? 'Operational' : 'Inactive';
                html += `
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <span>${p.provider_name || 'Unknown'}</span>
                        <div>
                            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${color};margin-right:8px;"></span>
                            <span style="font-size:12px;color:${color};">${statusText}</span>
                        </div>
                    </div>
                `;
            });
            container.innerHTML = html;
        }

        function renderRecentActivity(logs) {
            const container = document.getElementById('recentActivity');
            if (!container) return;

            if (!logs || logs.length === 0) {
                container.innerHTML = `
                    <div class="text-muted text-center py-2">
                        <i class="fas fa-info-circle me-2"></i>No recent activity
                    </div>
                `;
                return;
            }

            let html = '';
            logs.forEach(log => {
                const time = log.created_at ? new Date(log.created_at).toLocaleTimeString() : 'N/A';
                const type = log.action_type || 'Unknown';
                const iconMap = {
                    'UPDATE': 'fa-pen',
                    'CREATE': 'fa-plus',
                    'DELETE': 'fa-trash',
                    'TEST': 'fa-vial',
                    'LOGIN': 'fa-sign-in-alt',
                    'LOGOUT': 'fa-sign-out-alt'
                };
                const icon = iconMap[type] || 'fa-info';
                html += `
                    <div class="d-flex align-items-center gap-2 py-2 border-bottom" style="font-size:13px;">
                        <i class="fas ${icon} text-primary" style="width:16px;"></i>
                        <span>${log.resource || 'Action'} - ${type}</span>
                        <span style="color:#6c757d;font-size:11px;margin-left:auto;">${time}</span>
                    </div>
                `;
            });
            container.innerHTML = html;
        }

        async function loadOverviewData() {
            try {
                // Try to fetch from API with timeout
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 5000);

                const response = await fetch(API_BASE_URL + '/settings/payments/providers', {
                    headers: getHeaders(),
                    signal: controller.signal
                });
                clearTimeout(timeoutId);

                if (response.ok) {
                    const result = await response.json();
                    if (result.success && result.data) {
                        const providers = result.data;
                        const active = providers.filter(p => p.status === 'active');
                        document.getElementById('activeProviders').textContent = active.length;
                        document.getElementById('paymentMethods').textContent = providers.length || 0;
                        renderHealthStatus(providers);
                        return;
                    }
                }
                // If API fails, use mock data
                useMockData();
            } catch (error) {
                console.log('API unavailable, using mock data:', error.message);
                useMockData();
            }
        }

        function useMockData() {
            // Use mock data
            const active = mockProviders.filter(p => p.status === 'active');
            document.getElementById('activeProviders').textContent = active.length;
            document.getElementById('paymentMethods').textContent = mockProviders.length || 0;
            renderHealthStatus(mockProviders);
            renderRecentActivity(mockAuditLogs);

            // Show a subtle notification that using demo data
            const container = document.getElementById('healthStatus');
            if (container) {
                const note = document.createElement('div');
                note.style.cssText = 'font-size:11px;color:#6c757d;margin-top:8px;font-style:italic;';
                note.textContent = '⚠️ Using demo data - API connection not available';
                container.appendChild(note);
            }
        }

        // ================================================
        // INITIALIZE
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            // Short delay to let page render
            setTimeout(loadOverviewData, 300);
        });
    })();
</script>