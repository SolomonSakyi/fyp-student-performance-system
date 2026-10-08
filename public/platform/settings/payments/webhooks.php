<div class="card-custom">
    <div class="card-header-custom">
        <h6><i class="fas fa-bolt me-2 text-primary"></i>Webhook Management</h6>
        <button class="btn btn-primary btn-sm" onclick="showAddWebhookModal()">
            <i class="fas fa-plus me-2"></i> Add Webhook
        </button>
    </div>
    <div class="card-body-custom">
        <div class="webhook-status mb-3">
            <div class="status-icon success"><i class="fas fa-check-circle"></i></div>
            <div>
                <div style="font-weight:600;">Webhook System Active</div>
                <div style="font-size:12px;color:#6c757d;">All webhooks are processing normally</div>
            </div>
            <div style="margin-left:auto;font-size:12px;color:#28a745;">
                <i class="fas fa-circle" style="font-size:8px;"></i> 100% uptime
            </div>
        </div>

        <div class="table-responsive">
            <table class="table" style="font-size:13px;">
                <thead>
                    <tr style="background:#f8f9fa;">
                        <th>Event</th>
                        <th>URL</th>
                        <th>Provider</th>
                        <th>Status</th>
                        <th>Last Delivery</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="badge bg-primary">payment.success</span></td>
                        <td><code style="font-size:11px;">https://yourdomain.com/webhook/payment/success</code></td>
                        <td>Hubtel</td>
                        <td><span class="badge bg-success">Active</span></td>
                        <td>2 min ago</td>
                        <td>
                            <button class="btn btn-outline-primary btn-sm" onclick="testWebhook('payment.success')"><i class="fas fa-plug"></i></button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="editWebhook('payment.success')"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-outline-danger btn-sm" onclick="deleteWebhook('payment.success')"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="badge bg-warning">payment.failed</span></td>
                        <td><code style="font-size:11px;">https://yourdomain.com/webhook/payment/failed</code></td>
                        <td>Hubtel</td>
                        <td><span class="badge bg-success">Active</span></td>
                        <td>15 min ago</td>
                        <td>
                            <button class="btn btn-outline-primary btn-sm" onclick="testWebhook('payment.failed')"><i class="fas fa-plug"></i></button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="editWebhook('payment.failed')"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-outline-danger btn-sm" onclick="deleteWebhook('payment.failed')"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="badge bg-info">payment.refund</span></td>
                        <td><code style="font-size:11px;">https://yourdomain.com/webhook/payment/refund</code></td>
                        <td>Bank API</td>
                        <td><span class="badge bg-success">Active</span></td>
                        <td>1 hour ago</td>
                        <td>
                            <button class="btn btn-outline-primary btn-sm" onclick="testWebhook('payment.refund')"><i class="fas fa-plug"></i></button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="editWebhook('payment.refund')"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-outline-danger btn-sm" onclick="deleteWebhook('payment.refund')"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="badge bg-secondary">subscription.renewal</span></td>
                        <td><code style="font-size:11px;">https://yourdomain.com/webhook/subscription/renewal</code></td>
                        <td>Flutterwave</td>
                        <td><span class="badge bg-warning">Pending</span></td>
                        <td>3 hours ago</td>
                        <td>
                            <button class="btn btn-outline-primary btn-sm" onclick="testWebhook('subscription.renewal')"><i class="fas fa-plug"></i></button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="editWebhook('subscription.renewal')"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-outline-danger btn-sm" onclick="deleteWebhook('subscription.renewal')"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <hr>
        <h6 style="font-weight:600;font-size:14px;margin-bottom:12px;"><i class="fas fa-history me-2 text-primary"></i>Recent Webhook Deliveries</h6>
        <div class="table-responsive">
            <table class="table" style="font-size:12px;">
                <thead>
                    <tr style="background:#f8f9fa;">
                        <th>ID</th>
                        <th>Event</th>
                        <th>Status</th>
                        <th>Attempts</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>#WH-2024-001</td>
                        <td>payment.success</td>
                        <td><span class="badge bg-success">Delivered</span></td>
                        <td>1</td>
                        <td>2 min ago</td>
                    </tr>
                    <tr>
                        <td>#WH-2024-002</td>
                        <td>payment.failed</td>
                        <td><span class="badge bg-success">Delivered</span></td>
                        <td>1</td>
                        <td>15 min ago</td>
                    </tr>
                    <tr>
                        <td>#WH-2024-003</td>
                        <td>payment.refund</td>
                        <td><span class="badge bg-danger">Failed</span></td>
                        <td>3</td>
                        <td>45 min ago</td>
                    </tr>
                    <tr>
                        <td>#WH-2024-004</td>
                        <td>subscription.renewal</td>
                        <td><span class="badge bg-warning">Retrying</span></td>
                        <td>2</td>
                        <td>1 hour ago</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Webhook Modal -->
<div class="modal fade" id="addWebhookModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,0.15);">
            <div class="modal-header" style="border-bottom:1px solid #f0f2f5;padding:16px 24px;">
                <h5 class="modal-title"><i class="fas fa-plus me-2 text-primary"></i>Add Webhook</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:24px;">
                <form id="webhookForm">
                    <div class="mb-2">
                        <label class="form-label">Event <span class="required">*</span></label>
                        <select class="form-select" id="webhookEvent" required>
                            <option value="">Select Event</option>
                            <option value="payment.success">Payment Success</option>
                            <option value="payment.failed">Payment Failed</option>
                            <option value="payment.pending">Payment Pending</option>
                            <option value="payment.refund">Payment Refund</option>
                            <option value="subscription.created">Subscription Created</option>
                            <option value="subscription.renewal">Subscription Renewal</option>
                            <option value="subscription.cancelled">Subscription Cancelled</option>
                            <option value="invoice.paid">Invoice Paid</option>
                            <option value="invoice.overdue">Invoice Overdue</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Provider <span class="required">*</span></label>
                        <select class="form-select" id="webhookProvider" required>
                            <option value="">Select Provider</option>
                            <option value="hubtel">Hubtel</option>
                            <option value="bank">Bank API</option>
                            <option value="paystack">Paystack</option>
                            <option value="flutterwave">Flutterwave</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Webhook URL <span class="required">*</span></label>
                        <input type="url" class="form-control" id="webhookUrl" placeholder="https://yourdomain.com/webhook/endpoint" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Secret Key</label>
                        <input type="text" class="form-control credential" id="webhookSecret" placeholder="Enter webhook secret for verification">
                        <div class="form-text">Used to verify webhook signatures</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Status</label>
                        <select class="form-select" id="webhookStatus">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="border-top:1px solid #f0f2f5;padding:16px 24px;">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveWebhook()"><i class="fas fa-save me-2"></i> Save Webhook</button>
            </div>
        </div>
    </div>
</div>

<script>
    function showAddWebhookModal() {
        document.getElementById('webhookEvent').value = '';
        document.getElementById('webhookProvider').value = '';
        document.getElementById('webhookUrl').value = '';
        document.getElementById('webhookSecret').value = '';
        document.getElementById('webhookStatus').value = 'active';
        const modal = new bootstrap.Modal(document.getElementById('addWebhookModal'));
        modal.show();
    }

    function saveWebhook() {
        const form = document.getElementById('webhookForm');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const data = {
            event: document.getElementById('webhookEvent').value,
            provider: document.getElementById('webhookProvider').value,
            url: document.getElementById('webhookUrl').value,
            secret: document.getElementById('webhookSecret').value,
            status: document.getElementById('webhookStatus').value
        };

        console.log('Saving webhook:', data);
        showAlert('Webhook added successfully!', 'success');
        document.getElementById('addWebhookModal').querySelector('.btn-close').click();
    }

    function testWebhook(event) {
        showAlert('Testing webhook for event: ' + event, 'info');
        setTimeout(() => {
            showAlert('Webhook test successful!', 'success');
        }, 1500);
    }

    function editWebhook(event) {
        showAlert('Editing webhook: ' + event, 'info');
    }

    function deleteWebhook(event) {
        if (confirm('Are you sure you want to delete webhook: ' + event + '?')) {
            showAlert('Webhook deleted: ' + event, 'danger');
        }
    }
</script>