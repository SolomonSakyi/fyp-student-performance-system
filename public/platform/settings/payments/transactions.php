<div class="card-custom">
    <div class="card-header-custom">
        <h6><i class="fas fa-cog me-2 text-primary"></i>Transaction Rules</h6>
    </div>
    <div class="card-body-custom">
        <form id="transactionRulesForm">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Minimum Transaction Amount</label>
                        <div class="input-group">
                            <span class="input-group-text">₵</span>
                            <input type="number" class="form-control" id="minAmount" value="1.00" step="0.01">
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Maximum Transaction Amount</label>
                        <div class="input-group">
                            <span class="input-group-text">₵</span>
                            <input type="number" class="form-control" id="maxAmount" value="10000.00" step="0.01">
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Transaction Timeout (seconds)</label>
                        <input type="number" class="form-control" id="transactionTimeout" value="60">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Retry Attempts</label>
                        <input type="number" class="form-control" id="retryAttempts" value="3">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Daily Transaction Limit</label>
                        <div class="input-group">
                            <span class="input-group-text">₵</span>
                            <input type="number" class="form-control" id="dailyLimit" value="50000.00" step="0.01">
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Monthly Transaction Limit</label>
                        <div class="input-group">
                            <span class="input-group-text">₵</span>
                            <input type="number" class="form-control" id="monthlyLimit" value="500000.00" step="0.01">
                        </div>
                    </div>
                </div>
            </div>

            <hr>

            <div class="mb-2">
                <label class="form-label">Transaction Validation Rules</label>
                <div class="d-flex flex-wrap gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="validateBvn" checked>
                        <label class="form-check-label" for="validateBvn">Validate BVN/ID</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="validateEmail" checked>
                        <label class="form-check-label" for="validateEmail">Validate Email</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="validatePhone" checked>
                        <label class="form-check-label" for="validatePhone">Validate Phone</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="validateAge" checked>
                        <label class="form-check-label" for="validateAge">Validate Age (18+)</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="validateAddress">
                        <label class="form-check-label" for="validateAddress">Validate Address</label>
                    </div>
                </div>
            </div>

            <hr>

            <div class="d-flex gap-2 flex-wrap justify-content-end">
                <button type="button" class="btn btn-primary" onclick="saveTransactionRules()">
                    <i class="fas fa-save me-2"></i> Save Rules
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function saveTransactionRules() {
        const data = {
            min_amount: document.getElementById('minAmount').value,
            max_amount: document.getElementById('maxAmount').value,
            timeout: document.getElementById('transactionTimeout').value,
            retry_attempts: document.getElementById('retryAttempts').value,
            daily_limit: document.getElementById('dailyLimit').value,
            monthly_limit: document.getElementById('monthlyLimit').value,
            validation_rules: {
                bvn: document.getElementById('validateBvn').checked,
                email: document.getElementById('validateEmail').checked,
                phone: document.getElementById('validatePhone').checked,
                age: document.getElementById('validateAge').checked,
                address: document.getElementById('validateAddress').checked
            }
        };

        console.log('Saving transaction rules:', data);
        showAlert('Transaction rules saved successfully!', 'success');
    }
</script>