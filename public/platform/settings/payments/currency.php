<div class="card-custom">
    <div class="card-header-custom">
        <h6><i class="fas fa-dollar-sign me-2 text-primary"></i>Currency Settings</h6>
    </div>
    <div class="card-body-custom">
        <form id="currencyForm">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Default Currency <span class="required">*</span></label>
                        <select class="form-select" id="defaultCurrency" required>
                            <option value="GHS" selected>Ghana Cedi (GHS)</option>
                            <option value="USD">US Dollar (USD)</option>
                            <option value="EUR">Euro (EUR)</option>
                            <option value="GBP">British Pound (GBP)</option>
                            <option value="NGN">Nigerian Naira (NGN)</option>
                            <option value="KES">Kenyan Shilling (KES)</option>
                            <option value="ZAR">South African Rand (ZAR)</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Currency Symbol Position</label>
                        <select class="form-select" id="currencyPosition">
                            <option value="left" selected>Left (e.g., ₵100)</option>
                            <option value="right">Right (e.g., 100₵)</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Decimal Places</label>
                        <select class="form-select" id="decimalPlaces">
                            <option value="0">0 (No decimals)</option>
                            <option value="1">1 (e.g., ₵100.5)</option>
                            <option value="2" selected>2 (e.g., ₵100.50)</option>
                            <option value="3">3 (e.g., ₵100.500)</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Thousands Separator</label>
                        <select class="form-select" id="thousandsSeparator">
                            <option value="," selected>Comma (,)</option>
                            <option value=".">Dot (.)</option>
                            <option value=" ">Space</option>
                            <option value="'">Apostrophe (')</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Decimal Separator</label>
                        <select class="form-select" id="decimalSeparator">
                            <option value="." selected>Dot (.)</option>
                            <option value=",">Comma (,)</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Currency Format</label>
                        <select class="form-select" id="currencyFormat">
                            <option value="symbol" selected>Symbol (₵100.00)</option>
                            <option value="code">Code (GHS 100.00)</option>
                            <option value="symbol_code">Symbol + Code (₵ 100.00 GHS)</option>
                        </select>
                    </div>
                </div>
            </div>

            <hr>

            <div class="row">
                <div class="col-md-12">
                    <h6 style="font-weight:600;font-size:14px;margin-bottom:12px;"><i class="fas fa-exchange-alt me-2 text-primary"></i>Exchange Rate Settings</h6>
                </div>
                <div class="col-md-4">
                    <div class="mb-2">
                        <label class="form-label">Auto-Update Rates</label>
                        <select class="form-select" id="autoUpdateRates">
                            <option value="daily" selected>Daily</option>
                            <option value="hourly">Hourly</option>
                            <option value="weekly">Weekly</option>
                            <option value="manual">Manual Only</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="mb-2">
                        <label class="form-label">Exchange Rate API</label>
                        <select class="form-select" id="exchangeRateApi">
                            <option value="fixer" selected>Fixer.io</option>
                            <option value="openexchangerates">Open Exchange Rates</option>
                            <option value="currencylayer">Currency Layer</option>
                            <option value="exchangerate">ExchangeRate-API</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="mb-2">
                        <label class="form-label">API Key</label>
                        <input type="text" class="form-control credential" id="exchangeApiKey" placeholder="Enter exchange rate API key">
                    </div>
                </div>
            </div>

            <hr>

            <div class="d-flex gap-2 flex-wrap justify-content-end">
                <button type="button" class="btn btn-outline-secondary" onclick="refreshExchangeRates()">
                    <i class="fas fa-sync-alt me-2"></i> Refresh Rates
                </button>
                <button type="button" class="btn btn-primary" onclick="saveCurrencySettings()">
                    <i class="fas fa-save me-2"></i> Save Settings
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function refreshExchangeRates() {
        showAlert('Refreshing exchange rates...', 'info');
        setTimeout(() => {
            showAlert('Exchange rates updated successfully!', 'success');
        }, 1500);
    }

    function saveCurrencySettings() {
        const form = document.getElementById('currencyForm');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const data = {
            default_currency: document.getElementById('defaultCurrency').value,
            symbol_position: document.getElementById('currencyPosition').value,
            decimal_places: document.getElementById('decimalPlaces').value,
            thousands_separator: document.getElementById('thousandsSeparator').value,
            decimal_separator: document.getElementById('decimalSeparator').value,
            currency_format: document.getElementById('currencyFormat').value,
            auto_update_rates: document.getElementById('autoUpdateRates').value,
            exchange_rate_api: document.getElementById('exchangeRateApi').value,
            exchange_api_key: document.getElementById('exchangeApiKey').value
        };

        console.log('Saving currency settings:', data);
        showAlert('Currency settings saved successfully!', 'success');
    }
</script>