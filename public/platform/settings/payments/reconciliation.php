<div class="card-custom">
    <div class="card-header-custom">
        <h6><i class="fas fa-balance-scale me-2 text-primary"></i>Reconciliation Settings</h6>
    </div>
    <div class="card-body-custom">
        <form id="reconciliationForm">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Auto-Reconciliation</label>
                        <select class="form-select" id="autoReconciliation">
                            <option value="daily" selected>Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="disabled">Disabled</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Reconciliation Time</label>
                        <input type="time" class="form-control" id="reconciliationTime" value="23:00">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Tolerance Percentage</label>
                        <input type="number" class="form-control" id="tolerancePercent" value="0.5" step="0.1">
                        <div class="form-text">Allowed variance percentage</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Reconciliation Report Email</label>
                        <input type="email" class="form-control" id="reconciliationEmail" value="finance@edutrack.com">
                    </div>
                </div>
            </div>

            <hr>

            <div class="d-flex gap-2 flex-wrap justify-content-end">
                <button type="button" class="btn btn-outline-primary" onclick="runReconciliation()">
                    <i class="fas fa-play me-2"></i> Run Reconciliation
                </button>
                <button type="button" class="btn btn-primary" onclick="saveReconciliationSettings()">
                    <i class="fas fa-save me-2"></i> Save Settings
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function runReconciliation() {
        showAlert('Running reconciliation...', 'info');
        setTimeout(() => {
            const match = Math.random() > 0.1;
            if (match) {
                showAlert('Reconciliation complete! All transactions match.', 'success');
            } else {
                showAlert('Reconciliation complete! 5 discrepancies found.', 'warning');
            }
        }, 2000);
    }

    function saveReconciliationSettings() {
        const data = {
            auto_reconciliation: document.getElementById('autoReconciliation').value,
            reconciliation_time: document.getElementById('reconciliationTime').value,
            tolerance_percent: document.getElementById('tolerancePercent').value,
            report_email: document.getElementById('reconciliationEmail').value
        };

        console.log('Saving reconciliation settings:', data);
        showAlert('Reconciliation settings saved successfully!', 'success');
    }
</script>