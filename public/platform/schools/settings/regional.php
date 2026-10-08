<!-- Regional & Localization Tab -->
<div class="tab-content active">
    <div class="card-custom">
        <div class="card-header-custom">
            <h6><i class="fas fa-globe me-2 text-primary"></i>Regional & Localization</h6>
            <span class="text-muted" style="font-size:12px;">Configure regional settings for your school</span>
        </div>
        <div class="card-body-custom">
            <form id="regionalForm" method="POST">
                <input type="hidden" name="school_id" value="<?php echo $schoolId; ?>">

                <div class="row">
                    <div class="col-md-6 col-12">
                        <div class="mb-2">
                            <label class="form-label">Country <span class="required">*</span></label>
                            <select class="form-select" id="country" name="regional_country" required>
                                <option value="">Select Country</option>
                                <option value="Ghana" <?php echo ($regional['country'] ?? 'Ghana') == 'Ghana' ? 'selected' : ''; ?>>Ghana</option>
                                <option value="Nigeria" <?php echo ($regional['country'] ?? 'Ghana') == 'Nigeria' ? 'selected' : ''; ?>>Nigeria</option>
                                <option value="Kenya" <?php echo ($regional['country'] ?? 'Ghana') == 'Kenya' ? 'selected' : ''; ?>>Kenya</option>
                                <option value="South Africa" <?php echo ($regional['country'] ?? 'Ghana') == 'South Africa' ? 'selected' : ''; ?>>South Africa</option>
                                <option value="United Kingdom" <?php echo ($regional['country'] ?? 'Ghana') == 'United Kingdom' ? 'selected' : ''; ?>>United Kingdom</option>
                                <option value="United States" <?php echo ($regional['country'] ?? 'Ghana') == 'United States' ? 'selected' : ''; ?>>United States</option>
                                <option value="Canada" <?php echo ($regional['country'] ?? 'Ghana') == 'Canada' ? 'selected' : ''; ?>>Canada</option>
                                <option value="Australia" <?php echo ($regional['country'] ?? 'Ghana') == 'Australia' ? 'selected' : ''; ?>>Australia</option>
                                <option value="India" <?php echo ($regional['country'] ?? 'Ghana') == 'India' ? 'selected' : ''; ?>>India</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6 col-12">
                        <div class="mb-2">
                            <label class="form-label">Country Code</label>
                            <input type="text" class="form-control" id="countryCode" name="regional_country_code"
                                placeholder="e.g., GH"
                                value="<?php echo htmlspecialchars($regional['country_code'] ?? 'GH'); ?>"
                                maxlength="2" style="text-transform:uppercase;">
                            <div class="form-text">2-letter country code (ISO 3166-1)</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 col-12">
                        <div class="mb-2">
                            <label class="form-label">Currency <span class="required">*</span></label>
                            <select class="form-select" id="currency" name="regional_currency" required>
                                <option value="">Select Currency</option>
                                <option value="GHS" <?php echo ($regional['currency'] ?? 'GHS') == 'GHS' ? 'selected' : ''; ?>>GHS - Ghana Cedi</option>
                                <option value="NGN" <?php echo ($regional['currency'] ?? 'GHS') == 'NGN' ? 'selected' : ''; ?>>NGN - Nigerian Naira</option>
                                <option value="KES" <?php echo ($regional['currency'] ?? 'GHS') == 'KES' ? 'selected' : ''; ?>>KES - Kenyan Shilling</option>
                                <option value="ZAR" <?php echo ($regional['currency'] ?? 'GHS') == 'ZAR' ? 'selected' : ''; ?>>ZAR - South African Rand</option>
                                <option value="USD" <?php echo ($regional['currency'] ?? 'GHS') == 'USD' ? 'selected' : ''; ?>>USD - US Dollar</option>
                                <option value="EUR" <?php echo ($regional['currency'] ?? 'GHS') == 'EUR' ? 'selected' : ''; ?>>EUR - Euro</option>
                                <option value="GBP" <?php echo ($regional['currency'] ?? 'GHS') == 'GBP' ? 'selected' : ''; ?>>GBP - British Pound</option>
                                <option value="CAD" <?php echo ($regional['currency'] ?? 'GHS') == 'CAD' ? 'selected' : ''; ?>>CAD - Canadian Dollar</option>
                                <option value="AUD" <?php echo ($regional['currency'] ?? 'GHS') == 'AUD' ? 'selected' : ''; ?>>AUD - Australian Dollar</option>
                                <option value="INR" <?php echo ($regional['currency'] ?? 'GHS') == 'INR' ? 'selected' : ''; ?>>INR - Indian Rupee</option>
                                <option value="JPY" <?php echo ($regional['currency'] ?? 'GHS') == 'JPY' ? 'selected' : ''; ?>>JPY - Japanese Yen</option>
                                <option value="CNY" <?php echo ($regional['currency'] ?? 'GHS') == 'CNY' ? 'selected' : ''; ?>>CNY - Chinese Yuan</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6 col-12">
                        <div class="mb-2">
                            <label class="form-label">Currency Symbol</label>
                            <input type="text" class="form-control" id="currencySymbol" name="regional_currency_symbol"
                                placeholder="e.g., ₵"
                                value="<?php echo htmlspecialchars($regional['currency_symbol'] ?? '₵'); ?>"
                                maxlength="5">
                            <div class="form-text">The symbol used to display currency amounts</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 col-12">
                        <div class="mb-2">
                            <label class="form-label">Timezone <span class="required">*</span></label>
                            <select class="form-select" id="timezone" name="regional_timezone" required>
                                <option value="">Select Timezone</option>
                                <optgroup label="Africa">
                                    <option value="Africa/Accra" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'Africa/Accra' ? 'selected' : ''; ?>>Africa/Accra (GMT+0)</option>
                                    <option value="Africa/Lagos" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'Africa/Lagos' ? 'selected' : ''; ?>>Africa/Lagos (GMT+1)</option>
                                    <option value="Africa/Johannesburg" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'Africa/Johannesburg' ? 'selected' : ''; ?>>Africa/Johannesburg (GMT+2)</option>
                                    <option value="Africa/Nairobi" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'Africa/Nairobi' ? 'selected' : ''; ?>>Africa/Nairobi (GMT+3)</option>
                                    <option value="Africa/Cairo" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'Africa/Cairo' ? 'selected' : ''; ?>>Africa/Cairo (GMT+2)</option>
                                    <option value="Africa/Casablanca" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'Africa/Casablanca' ? 'selected' : ''; ?>>Africa/Casablanca (GMT+1)</option>
                                </optgroup>
                                <optgroup label="Europe">
                                    <option value="Europe/London" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'Europe/London' ? 'selected' : ''; ?>>Europe/London (GMT+0)</option>
                                    <option value="Europe/Paris" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'Europe/Paris' ? 'selected' : ''; ?>>Europe/Paris (GMT+1)</option>
                                    <option value="Europe/Berlin" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'Europe/Berlin' ? 'selected' : ''; ?>>Europe/Berlin (GMT+1)</option>
                                    <option value="Europe/Rome" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'Europe/Rome' ? 'selected' : ''; ?>>Europe/Rome (GMT+1)</option>
                                </optgroup>
                                <optgroup label="Americas">
                                    <option value="America/New_York" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'America/New_York' ? 'selected' : ''; ?>>America/New_York (GMT-5)</option>
                                    <option value="America/Chicago" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'America/Chicago' ? 'selected' : ''; ?>>America/Chicago (GMT-6)</option>
                                    <option value="America/Denver" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'America/Denver' ? 'selected' : ''; ?>>America/Denver (GMT-7)</option>
                                    <option value="America/Los_Angeles" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'America/Los_Angeles' ? 'selected' : ''; ?>>America/Los_Angeles (GMT-8)</option>
                                    <option value="America/Toronto" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'America/Toronto' ? 'selected' : ''; ?>>America/Toronto (GMT-5)</option>
                                </optgroup>
                                <optgroup label="Asia">
                                    <option value="Asia/Dubai" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'Asia/Dubai' ? 'selected' : ''; ?>>Asia/Dubai (GMT+4)</option>
                                    <option value="Asia/Kolkata" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'Asia/Kolkata' ? 'selected' : ''; ?>>Asia/Kolkata (GMT+5:30)</option>
                                    <option value="Asia/Singapore" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'Asia/Singapore' ? 'selected' : ''; ?>>Asia/Singapore (GMT+8)</option>
                                    <option value="Asia/Shanghai" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'Asia/Shanghai' ? 'selected' : ''; ?>>Asia/Shanghai (GMT+8)</option>
                                    <option value="Asia/Tokyo" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'Asia/Tokyo' ? 'selected' : ''; ?>>Asia/Tokyo (GMT+9)</option>
                                </optgroup>
                                <optgroup label="Oceania">
                                    <option value="Australia/Sydney" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'Australia/Sydney' ? 'selected' : ''; ?>>Australia/Sydney (GMT+11)</option>
                                    <option value="Pacific/Auckland" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'Pacific/Auckland' ? 'selected' : ''; ?>>Pacific/Auckland (GMT+12)</option>
                                </optgroup>
                                <optgroup label="UTC">
                                    <option value="UTC" <?php echo ($regional['timezone'] ?? 'Africa/Accra') == 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                </optgroup>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6 col-12">
                        <div class="mb-2">
                            <label class="form-label">Language <span class="required">*</span></label>
                            <select class="form-select" id="language" name="regional_language" required>
                                <option value="">Select Language</option>
                                <option value="en" <?php echo ($regional['language'] ?? 'en') == 'en' ? 'selected' : ''; ?>>English</option>
                                <option value="fr" <?php echo ($regional['language'] ?? 'en') == 'fr' ? 'selected' : ''; ?>>French</option>
                                <option value="es" <?php echo ($regional['language'] ?? 'en') == 'es' ? 'selected' : ''; ?>>Spanish</option>
                                <option value="pt" <?php echo ($regional['language'] ?? 'en') == 'pt' ? 'selected' : ''; ?>>Portuguese</option>
                                <option value="ar" <?php echo ($regional['language'] ?? 'en') == 'ar' ? 'selected' : ''; ?>>Arabic</option>
                                <option value="zh" <?php echo ($regional['language'] ?? 'en') == 'zh' ? 'selected' : ''; ?>>Chinese</option>
                                <option value="hi" <?php echo ($regional['language'] ?? 'en') == 'hi' ? 'selected' : ''; ?>>Hindi</option>
                                <option value="sw" <?php echo ($regional['language'] ?? 'en') == 'sw' ? 'selected' : ''; ?>>Swahili</option>
                                <option value="ha" <?php echo ($regional['language'] ?? 'en') == 'ha' ? 'selected' : ''; ?>>Hausa</option>
                                <option value="yo" <?php echo ($regional['language'] ?? 'en') == 'yo' ? 'selected' : ''; ?>>Yoruba</option>
                                <option value="ig" <?php echo ($regional['language'] ?? 'en') == 'ig' ? 'selected' : ''; ?>>Igbo</option>
                                <option value="tw" <?php echo ($regional['language'] ?? 'en') == 'tw' ? 'selected' : ''; ?>>Twi</option>
                                <option value="ga" <?php echo ($regional['language'] ?? 'en') == 'ga' ? 'selected' : ''; ?>>Ga</option>
                                <option value="ee" <?php echo ($regional['language'] ?? 'en') == 'ee' ? 'selected' : ''; ?>>Ewe</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 col-12">
                        <div class="mb-2">
                            <label class="form-label">Date Format <span class="required">*</span></label>
                            <select class="form-select" id="dateFormat" name="regional_date_format" required>
                                <option value="DD/MM/YYYY" <?php echo ($regional['date_format'] ?? 'DD/MM/YYYY') == 'DD/MM/YYYY' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                                <option value="MM/DD/YYYY" <?php echo ($regional['date_format'] ?? 'DD/MM/YYYY') == 'MM/DD/YYYY' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                                <option value="YYYY-MM-DD" <?php echo ($regional['date_format'] ?? 'DD/MM/YYYY') == 'YYYY-MM-DD' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                                <option value="DD-MM-YYYY" <?php echo ($regional['date_format'] ?? 'DD/MM/YYYY') == 'DD-MM-YYYY' ? 'selected' : ''; ?>>DD-MM-YYYY</option>
                                <option value="MM-DD-YYYY" <?php echo ($regional['date_format'] ?? 'DD/MM/YYYY') == 'MM-DD-YYYY' ? 'selected' : ''; ?>>MM-DD-YYYY</option>
                                <option value="DD MMM YYYY" <?php echo ($regional['date_format'] ?? 'DD/MM/YYYY') == 'DD MMM YYYY' ? 'selected' : ''; ?>>DD MMM YYYY</option>
                                <option value="MMM DD, YYYY" <?php echo ($regional['date_format'] ?? 'DD/MM/YYYY') == 'MMM DD, YYYY' ? 'selected' : ''; ?>>MMM DD, YYYY</option>
                            </select>
                            <div class="form-text">Example: <?php echo date('d/m/Y'); ?> (DD/MM/YYYY)</div>
                        </div>
                    </div>
                    <div class="col-md-6 col-12">
                        <div class="mb-2">
                            <label class="form-label">Time Format</label>
                            <select class="form-select" id="timeFormat" name="regional_time_format">
                                <option value="HH:mm" <?php echo ($regional['time_format'] ?? 'HH:mm') == 'HH:mm' ? 'selected' : ''; ?>>24-hour (HH:mm)</option>
                                <option value="hh:mm A" <?php echo ($regional['time_format'] ?? 'HH:mm') == 'hh:mm A' ? 'selected' : ''; ?>>12-hour (hh:mm AM/PM)</option>
                                <option value="h:mm A" <?php echo ($regional['time_format'] ?? 'HH:mm') == 'h:mm A' ? 'selected' : ''; ?>>12-hour (h:mm AM/PM)</option>
                            </select>
                            <div class="form-text">Example: <?php echo date('H:i'); ?> (24-hour) or <?php echo date('h:i A'); ?> (12-hour)</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 col-12">
                        <div class="mb-2">
                            <label class="form-label">Number Format</label>
                            <select class="form-select" id="numberFormat" name="regional_number_format">
                                <option value="1,000.00" <?php echo ($regional['number_format'] ?? '1,000.00') == '1,000.00' ? 'selected' : ''; ?>>1,000.00 (Comma decimal)</option>
                                <option value="1.000,00" <?php echo ($regional['number_format'] ?? '1,000.00') == '1.000,00' ? 'selected' : ''; ?>>1.000,00 (Dot decimal)</option>
                                <option value="1 000.00" <?php echo ($regional['number_format'] ?? '1,000.00') == '1 000.00' ? 'selected' : ''; ?>>1 000.00 (Space decimal)</option>
                                <option value="1'000.00" <?php echo ($regional['number_format'] ?? '1,000.00') == "1'000.00" ? 'selected' : ''; ?>>1'000.00 (Apostrophe decimal)</option>
                            </select>
                            <div class="form-text">How numbers should be displayed</div>
                        </div>
                    </div>
                    <div class="col-md-6 col-12">
                        <div class="mb-2">
                            <label class="form-label">First Day of Week</label>
                            <select class="form-select" id="firstDayOfWeek" name="regional_first_day_of_week">
                                <option value="Monday" <?php echo ($regional['first_day_of_week'] ?? 'Monday') == 'Monday' ? 'selected' : ''; ?>>Monday</option>
                                <option value="Sunday" <?php echo ($regional['first_day_of_week'] ?? 'Monday') == 'Sunday' ? 'selected' : ''; ?>>Sunday</option>
                                <option value="Saturday" <?php echo ($regional['first_day_of_week'] ?? 'Monday') == 'Saturday' ? 'selected' : ''; ?>>Saturday</option>
                            </select>
                            <div class="form-text">The first day of the week for calendars</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12">
                        <div class="mb-2">
                            <label class="form-label">Academic Year Naming Convention</label>
                            <select class="form-select" id="academicYearNaming" name="regional_academic_year_naming">
                                <option value="YYYY-YYYY" <?php echo ($regional['academic_year_naming'] ?? 'YYYY-YYYY') == 'YYYY-YYYY' ? 'selected' : ''; ?>>2024-2025</option>
                                <option value="YYYY/YYYY" <?php echo ($regional['academic_year_naming'] ?? 'YYYY-YYYY') == 'YYYY/YYYY' ? 'selected' : ''; ?>>2024/2025</option>
                                <option value="YYYY - YYYY" <?php echo ($regional['academic_year_naming'] ?? 'YYYY-YYYY') == 'YYYY - YYYY' ? 'selected' : ''; ?>>2024 - 2025</option>
                                <option value="Academic Year YYYY-YYYY" <?php echo ($regional['academic_year_naming'] ?? 'YYYY-YYYY') == 'Academic Year YYYY-YYYY' ? 'selected' : ''; ?>>Academic Year 2024-2025</option>
                                <option value="AY YYYY-YYYY" <?php echo ($regional['academic_year_naming'] ?? 'YYYY-YYYY') == 'AY YYYY-YYYY' ? 'selected' : ''; ?>>AY 2024-2025</option>
                            </select>
                            <div class="form-text">How academic years should be displayed</div>
                        </div>
                    </div>
                </div>

                <hr class="my-3">

                <div class="d-flex gap-2 flex-wrap justify-content-end">
                    <button type="button" class="btn btn-outline-secondary" onclick="resetRegional()">
                        <i class="fas fa-undo me-2"></i> Reset to Default
                    </button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-save me-2"></i> Save Regional Settings
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change History -->
    <div class="card-custom">
        <div class="card-header-custom">
            <h6><i class="fas fa-history me-2 text-muted"></i>Recent Regional Changes</h6>
            <button class="btn btn-outline-secondary btn-sm" onclick="loadHistory()">
                <i class="fas fa-sync-alt me-1"></i> Refresh
            </button>
        </div>
        <div class="card-body-custom" id="historyContainer">
            <div class="text-center py-3 text-muted">
                <i class="fas fa-clock me-2"></i> Loading history...
            </div>
        </div>
    </div>
</div>

<script>
    // ============================================================
    // CONFIGURATION
    // ============================================================
    const API_BASE = 'http://localhost:8000/api/platform';
    const TOKEN = localStorage.getItem('token') || '';
    const schoolId = <?php echo $schoolId; ?>;

    // ============================================================
    // CURRENCY TO SYMBOL MAPPING
    // ============================================================
    const currencySymbols = {
        'GHS': '₵',
        'NGN': '₦',
        'KES': 'KSh',
        'ZAR': 'R',
        'USD': '$',
        'EUR': '€',
        'GBP': '£',
        'CAD': 'C$',
        'AUD': 'A$',
        'INR': '₹',
        'JPY': '¥',
        'CNY': '¥'
    };

    // ============================================================
    // AUTO-UPDATE CURRENCY SYMBOL
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        const currencySelect = document.getElementById('currency');
        const symbolInput = document.getElementById('currencySymbol');

        // Update symbol when currency changes
        currencySelect.addEventListener('change', function() {
            const selectedCurrency = this.value;
            if (selectedCurrency && currencySymbols[selectedCurrency]) {
                symbolInput.value = currencySymbols[selectedCurrency];
            } else {
                symbolInput.value = '';
            }
        });
    });

    // ============================================================
    // SAVE REGIONAL SETTINGS
    // ============================================================
    document.getElementById('regionalForm').addEventListener('submit', async function(e) {
        e.preventDefault();

        const submitBtn = document.getElementById('submitBtn');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Saving...';

        try {
            const formData = new FormData(this);
            const data = {};
            formData.forEach((value, key) => {
                data[key] = value;
            });

            const response = await fetch(`${API_BASE}/index.php?endpoint=schools/${schoolId}/regional`, {
                method: 'PUT',
                headers: {
                    'Authorization': 'Bearer ' + TOKEN,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                showAlert('Regional settings updated successfully!', 'success');
                loadHistory();
            } else {
                showAlert('❌ ' + (result.message || 'Failed to update regional settings'), 'danger');
            }
        } catch (error) {
            console.error('Error saving regional settings:', error);
            showAlert('Error saving regional settings: ' + error.message, 'danger');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    });

    // ============================================================
    // RESET REGIONAL SETTINGS
    // ============================================================
    function resetRegional() {
        if (!confirm('Are you sure you want to reset all regional settings to default values?')) {
            return;
        }

        fetch(`${API_BASE}/index.php?endpoint=schools/${schoolId}/regional/reset`, {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + TOKEN,
                    'Content-Type': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('Regional settings reset to default!', 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showAlert('❌ ' + (data.message || 'Failed to reset regional settings'), 'danger');
                }
            })
            .catch(error => {
                showAlert('Error resetting regional settings: ' + error.message, 'danger');
            });
    }

    // ============================================================
    // LOAD CHANGE HISTORY
    // ============================================================
    async function loadHistory() {
        const container = document.getElementById('historyContainer');

        try {
            const response = await fetch(`${API_BASE}/index.php?endpoint=schools/${schoolId}/regional/history`, {
                headers: {
                    'Authorization': 'Bearer ' + TOKEN,
                    'Content-Type': 'application/json'
                }
            });

            const result = await response.json();

            if (result.success && result.data && result.data.length > 0) {
                container.innerHTML = result.data.map(item => `
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom" style="font-size:13px;">
                    <div>
                        <span class="badge bg-secondary me-2">${item.setting_key || 'N/A'}</span>
                        <span class="text-muted">${item.old_value || 'Empty'} → ${item.new_value || 'Empty'}</span>
                    </div>
                    <div class="text-muted" style="font-size:12px;">
                        <span>${item.changed_by || 'System'}</span>
                        <span class="ms-2">${item.created_at ? new Date(item.created_at).toLocaleString() : 'N/A'}</span>
                    </div>
                </div>
            `).join('');
            } else {
                container.innerHTML = `
                <div class="text-center py-3 text-muted">
                    <i class="fas fa-clock me-2"></i> No regional changes recorded yet.
                </div>
            `;
            }
        } catch (error) {
            console.error('Error loading history:', error);
            container.innerHTML = `
            <div class="text-center py-3 text-danger">
                <i class="fas fa-exclamation-circle me-2"></i> Failed to load history.
            </div>
        `;
        }
    }

    // ============================================================
    // INITIALIZE
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        loadHistory();
    });
</script>