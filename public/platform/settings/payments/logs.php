<div class="card-custom">
    <div class="card-header-custom">
        <h6><i class="fas fa-list me-2 text-primary"></i>Payment Audit Logs</h6>
        <div>
            <button class="btn btn-outline-secondary btn-sm me-1" onclick="exportLogs()">
                <i class="fas fa-download me-1"></i> Export
            </button>
            <button class="btn btn-outline-danger btn-sm" onclick="clearLogs()">
                <i class="fas fa-trash me-1"></i> Clear
            </button>
        </div>
    </div>
    <div class="card-body-custom">
        <!-- Filters -->
        <div class="row mb-3">
            <div class="col-md-3">
                <input type="text" class="form-control" id="logSearch" placeholder="Search logs..." style="height:38px;">
            </div>
            <div class="col-md-3">
                <select class="form-select" id="logType" style="height:38px;">
                    <option value="">All Types</option>
                    <option value="payment">Payment</option>
                    <option value="webhook">Webhook</option>
                    <option value="security">Security</option>
                    <option value="configuration">Configuration</option>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select" id="logStatus" style="height:38px;">
                    <option value="">All Status</option>
                    <option value="success">Success</option>
                    <option value="failed">Failed</option>
                    <option value="pending">Pending</option>
                </select>
            </div>
            <div class="col-md-3">
                <input type="date" class="form-control" id="logDate" style="height:38px;">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table" style="font-size:13px;">
                <thead>
                    <tr style="background:#f8f9fa;">
                        <th>Time</th>
                        <th>Type</th>
                        <th>Event</th>
                        <th>User</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>2024-01-15 14:23:45</td>
                        <td><span class="badge bg-primary">Payment</span></td>
                        <td>Payment #PAY-2024-001</td>
                        <td>admin@edutrack.com</td>
                        <td><span class="badge bg-success">Success</span></td>
                        <td><button class="btn btn-outline-primary btn-sm" onclick="viewLogDetails('PAY-2024-001')"><i class="fas fa-eye"></i></button></td>
                    </tr>
                    <tr>
                        <td>2024-01-15 13:15:20</td>
                        <td><span class="badge bg-info">Webhook</span></td>
                        <td>Webhook received - payment.success</td>
                        <td>system</td>
                        <td><span class="badge bg-success">Success</span></td>
                        <td><button class="btn btn-outline-primary btn-sm" onclick="viewLogDetails('WEB-2024-001')"><i class="fas fa-eye"></i></button></td>
                    </tr>
                    <tr>
                        <td>2024-01-15 12:00:00</td>
                        <td><span class="badge bg-warning">Security</span></td>
                        <td>API key rotated</td>
                        <td>admin@edutrack.com</td>
                        <td><span class="badge bg-success">Success</span></td>
                        <td><button class="btn btn-outline-primary btn-sm" onclick="viewLogDetails('SEC-2024-001')"><i class="fas fa-eye"></i></button></td>
                    </tr>
                    <tr>
                        <td>2024-01-15 11:30:15</td>
                        <td><span class="badge bg-secondary">Config</span></td>
                        <td>Provider configuration updated</td>
                        <td>admin@edutrack.com</td>
                        <td><span class="badge bg-success">Success</span></td>
                        <td><button class="btn btn-outline-primary btn-sm" onclick="viewLogDetails('CFG-2024-001')"><i class="fas fa-eye"></i></button></td>
                    </tr>
                    <tr>
                        <td>2024-01-15 10:45:30</td>
                        <td><span class="badge bg-danger">Payment</span></td>
                        <td>Payment #PAY-2024-002</td>
                        <td>user@example.com</td>
                        <td><span class="badge bg-danger">Failed</span></td>
                        <td><button class="btn btn-outline-primary btn-sm" onclick="viewLogDetails('PAY-2024-002')"><i class="fas fa-eye"></i></button></td>
                    </tr>
                    <tr>
                        <td>2024-01-15 09:20:00</td>
                        <td><span class="badge bg-info">Webhook</span></td>
                        <td>Webhook delivery failed - payment.failed</td>
                        <td>system</td>
                        <td><span class="badge bg-danger">Failed</span></td>
                        <td><button class="btn btn-outline-primary btn-sm" onclick="viewLogDetails('WEB-2024-002')"><i class="fas fa-eye"></i></button></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="d-flex justify-content-between align-items-center mt-3">
            <span style="font-size:13px;color:#6c757d;">Showing 1-6 of 45 entries</span>
            <nav>
                <ul class="pagination pagination-sm" style="margin:0;">
                    <li class="page-item disabled"><a class="page-link" href="#">Previous</a></li>
                    <li class="page-item active"><a class="page-link" href="#">1</a></li>
                    <li class="page-item"><a class="page-link" href="#">2</a></li>
                    <li class="page-item"><a class="page-link" href="#">3</a></li>
                    <li class="page-item"><a class="page-link" href="#">Next</a></li>
                </ul>
            </nav>
        </div>
    </div>
</div>

<script>
    function viewLogDetails(id) {
        showAlert('Viewing log details for: ' + id, 'info');
    }

    function exportLogs() {
        showAlert('Exporting logs...', 'info');
        setTimeout(() => {
            showAlert('Logs exported successfully!', 'success');
        }, 1500);
    }

    function clearLogs() {
        if (confirm('Are you sure you want to clear all logs?')) {
            showAlert('Logs cleared successfully!', 'danger');
        }
    }
</script>