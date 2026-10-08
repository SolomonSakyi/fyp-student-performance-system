<!-- Branding Tab -->
<div class="tab-content active">
    <div class="card-custom">
        <div class="card-header-custom">
            <h6><i class="fas fa-palette me-2 text-primary"></i>School Branding</h6>
            <span class="text-muted" style="font-size:12px;">Customize your school's visual identity</span>
        </div>
        <div class="card-body-custom">
            <form id="brandingForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="school_id" value="<?php echo $schoolId; ?>">

                <!-- Colors Section -->
                <h6 class="mb-3"><i class="fas fa-fill-drip me-2 text-primary"></i>Colors</h6>
                <div class="row">
                    <div class="col-md-3 col-6">
                        <div class="mb-2">
                            <label class="form-label">Primary Color</label>
                            <div class="color-picker-wrapper">
                                <input type="color" id="primaryColor" name="brand_primary_color"
                                    value="<?php echo $branding['primary_color'] ?? '#4facfe'; ?>">
                                <input type="text" class="form-control" id="primaryColorText"
                                    value="<?php echo $branding['primary_color'] ?? '#4facfe'; ?>"
                                    style="flex:1;font-family:monospace;font-size:12px;">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="mb-2">
                            <label class="form-label">Secondary Color</label>
                            <div class="color-picker-wrapper">
                                <input type="color" id="secondaryColor" name="brand_secondary_color"
                                    value="<?php echo $branding['secondary_color'] ?? '#00f2fe'; ?>">
                                <input type="text" class="form-control" id="secondaryColorText"
                                    value="<?php echo $branding['secondary_color'] ?? '#00f2fe'; ?>"
                                    style="flex:1;font-family:monospace;font-size:12px;">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="mb-2">
                            <label class="form-label">Accent Color</label>
                            <div class="color-picker-wrapper">
                                <input type="color" id="accentColor" name="brand_accent_color"
                                    value="<?php echo $branding['accent_color'] ?? '#ff6b6b'; ?>">
                                <input type="text" class="form-control" id="accentColorText"
                                    value="<?php echo $branding['accent_color'] ?? '#ff6b6b'; ?>"
                                    style="flex:1;font-family:monospace;font-size:12px;">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="mb-2">
                            <label class="form-label">Success Color</label>
                            <div class="color-picker-wrapper">
                                <input type="color" id="successColor" name="brand_success_color"
                                    value="<?php echo $branding['success_color'] ?? '#28a745'; ?>">
                                <input type="text" class="form-control" id="successColorText"
                                    value="<?php echo $branding['success_color'] ?? '#28a745'; ?>"
                                    style="flex:1;font-family:monospace;font-size:12px;">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="mb-2">
                            <label class="form-label">Warning Color</label>
                            <div class="color-picker-wrapper">
                                <input type="color" id="warningColor" name="brand_warning_color"
                                    value="<?php echo $branding['warning_color'] ?? '#ffc107'; ?>">
                                <input type="text" class="form-control" id="warningColorText"
                                    value="<?php echo $branding['warning_color'] ?? '#ffc107'; ?>"
                                    style="flex:1;font-family:monospace;font-size:12px;">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="mb-2">
                            <label class="form-label">Danger Color</label>
                            <div class="color-picker-wrapper">
                                <input type="color" id="dangerColor" name="brand_danger_color"
                                    value="<?php echo $branding['danger_color'] ?? '#dc3545'; ?>">
                                <input type="text" class="form-control" id="dangerColorText"
                                    value="<?php echo $branding['danger_color'] ?? '#dc3545'; ?>"
                                    style="flex:1;font-family:monospace;font-size:12px;">
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="my-3">

                <!-- Branding Preview -->
                <div class="row">
                    <div class="col-md-12">
                        <div class="mb-3">
                            <label class="form-label">Brand Preview</label>
                            <div class="p-4 rounded" id="brandPreview" style="background:#f8f9fa;border:2px dashed #dee2e6;">
                                <div class="d-flex align-items-center gap-3">
                                    <div id="previewLogo" style="width:60px;height:60px;border-radius:10px;background:linear-gradient(135deg, <?php echo $branding['primary_color'] ?? '#4facfe'; ?>, <?php echo $branding['secondary_color'] ?? '#00f2fe'; ?>);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:20px;">
                                        <?php echo substr($school['school_name'] ?? 'S', 0, 1); ?>
                                    </div>
                                    <div>
                                        <div style="font-weight:700;font-size:18px;color:<?php echo $branding['primary_color'] ?? '#4facfe'; ?>;" id="previewSchoolName">
                                            <?php echo htmlspecialchars($school['school_name'] ?? 'School Name'); ?>
                                        </div>
                                        <div style="font-size:13px;color:#6c757d;" id="previewMotto">
                                            <?php echo htmlspecialchars($branding['motto'] ?? 'Excellence in Education'); ?>
                                        </div>
                                        <div style="display:flex;gap:8px;margin-top:6px;">
                                            <span class="badge" style="background:<?php echo $branding['primary_color'] ?? '#4facfe'; ?>;">Primary</span>
                                            <span class="badge" style="background:<?php echo $branding['secondary_color'] ?? '#00f2fe'; ?>;">Secondary</span>
                                            <span class="badge" style="background:<?php echo $branding['accent_color'] ?? '#ff6b6b'; ?>;">Accent</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="my-3">

                <!-- Logo & Icons -->
                <h6 class="mb-3"><i class="fas fa-image me-2 text-primary"></i>Logos & Icons</h6>
                <div class="row">
                    <div class="col-md-4 col-12">
                        <div class="mb-2">
                            <label class="form-label">School Logo</label>
                            <input type="file" class="form-control" id="schoolLogo" name="school_logo" accept="image/*">
                            <div class="form-text">Recommended: 200x200 PNG or JPG</div>
                            <?php if (!empty($branding['logo_url'])): ?>
                                <div class="mt-2">
                                    <img src="<?php echo htmlspecialchars($branding['logo_url']); ?>" alt="Logo" style="max-width:100px;max-height:100px;border-radius:8px;border:1px solid #e9ecef;">
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4 col-12">
                        <div class="mb-2">
                            <label class="form-label">Favicon</label>
                            <input type="file" class="form-control" id="favicon" name="favicon" accept="image/*">
                            <div class="form-text">Recommended: 32x32 PNG or ICO</div>
                        </div>
                    </div>
                    <div class="col-md-4 col-12">
                        <div class="mb-2">
                            <label class="form-label">Portal Logo</label>
                            <input type="file" class="form-control" id="portalLogo" name="portal_logo" accept="image/*">
                            <div class="form-text">Recommended: 150x50 PNG</div>
                        </div>
                    </div>
                </div>

                <hr class="my-3">

                <!-- Motto & Slogan -->
                <h6 class="mb-3"><i class="fas fa-quote-left me-2 text-primary"></i>Tagline & Messaging</h6>
                <div class="row">
                    <div class="col-md-6 col-12">
                        <div class="mb-2">
                            <label class="form-label">School Motto</label>
                            <input type="text" class="form-control" id="motto" name="brand_motto"
                                placeholder="e.g., Excellence in Education"
                                value="<?php echo htmlspecialchars($branding['motto'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="col-md-6 col-12">
                        <div class="mb-2">
                            <label class="form-label">School Slogan</label>
                            <input type="text" class="form-control" id="slogan" name="brand_slogan"
                                placeholder="e.g., Shaping the Future"
                                value="<?php echo htmlspecialchars($branding['slogan'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <hr class="my-3">

                <!-- Actions -->
                <div class="d-flex gap-2 flex-wrap justify-content-end">
                    <button type="button" class="btn btn-outline-secondary" onclick="resetBranding()">
                        <i class="fas fa-undo me-2"></i> Reset to Default
                    </button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-save me-2"></i> Save Branding
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change History -->
    <div class="card-custom">
        <div class="card-header-custom">
            <h6><i class="fas fa-history me-2 text-muted"></i>Recent Branding Changes</h6>
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
    // COLOR PICKER SYNC
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        // Sync color pickers with text inputs
        const colorInputs = [{
                picker: 'primaryColor',
                text: 'primaryColorText'
            },
            {
                picker: 'secondaryColor',
                text: 'secondaryColorText'
            },
            {
                picker: 'accentColor',
                text: 'accentColorText'
            },
            {
                picker: 'successColor',
                text: 'successColorText'
            },
            {
                picker: 'warningColor',
                text: 'warningColorText'
            },
            {
                picker: 'dangerColor',
                text: 'dangerColorText'
            }
        ];

        colorInputs.forEach(({
            picker,
            text
        }) => {
            const pickerEl = document.getElementById(picker);
            const textEl = document.getElementById(text);

            if (pickerEl && textEl) {
                pickerEl.addEventListener('input', function() {
                    textEl.value = this.value;
                    updatePreview();
                });
                textEl.addEventListener('input', function() {
                    if (this.value.match(/^#[0-9a-fA-F]{6}$/)) {
                        pickerEl.value = this.value;
                        updatePreview();
                    }
                });
            }
        });

        // Update preview on motto/slogan change
        document.getElementById('motto').addEventListener('input', updatePreview);
        document.getElementById('slogan').addEventListener('input', updatePreview);
    });

    // ============================================================
    // UPDATE PREVIEW
    // ============================================================
    function updatePreview() {
        const primary = document.getElementById('primaryColor').value;
        const secondary = document.getElementById('secondaryColor').value;
        const accent = document.getElementById('accentColor').value;
        const motto = document.getElementById('motto').value || 'Excellence in Education';
        const schoolName = document.getElementById('previewSchoolName').textContent;

        const preview = document.getElementById('brandPreview');
        const previewLogo = document.getElementById('previewLogo');
        const previewSchoolName = document.getElementById('previewSchoolName');
        const previewMotto = document.getElementById('previewMotto');

        if (previewLogo) {
            previewLogo.style.background = `linear-gradient(135deg, ${primary}, ${secondary})`;
        }

        if (previewSchoolName) {
            previewSchoolName.style.color = primary;
        }

        if (previewMotto) {
            previewMotto.textContent = motto;
        }

        // Update badges
        const badges = preview.querySelectorAll('.badge');
        if (badges.length >= 3) {
            badges[0].style.background = primary;
            badges[1].style.background = secondary;
            badges[2].style.background = accent;
        }
    }

    // ============================================================
    // SAVE BRANDING
    // ============================================================
    document.getElementById('brandingForm').addEventListener('submit', async function(e) {
        e.preventDefault();

        const submitBtn = document.getElementById('submitBtn');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Saving...';

        try {
            const formData = new FormData(this);
            const data = {};
            formData.forEach((value, key) => {
                // Skip file inputs for now (we'll handle them separately)
                if (!key.includes('logo') && !key.includes('favicon')) {
                    data[key] = value;
                }
            });

            const response = await fetch(`${API_BASE}/index.php?endpoint=schools/${schoolId}/branding`, {
                method: 'PUT',
                headers: {
                    'Authorization': 'Bearer ' + TOKEN,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                showAlert('Branding updated successfully!', 'success');
                loadHistory();
            } else {
                showAlert('❌ ' + (result.message || 'Failed to update branding'), 'danger');
            }
        } catch (error) {
            console.error('Error saving branding:', error);
            showAlert('Error saving branding: ' + error.message, 'danger');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    });

    // ============================================================
    // RESET BRANDING
    // ============================================================
    function resetBranding() {
        if (!confirm('Are you sure you want to reset all branding settings to default values?')) {
            return;
        }

        fetch(`${API_BASE}/index.php?endpoint=schools/${schoolId}/branding/reset`, {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + TOKEN,
                    'Content-Type': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('Branding settings reset to default!', 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showAlert('❌ ' + (data.message || 'Failed to reset branding'), 'danger');
                }
            })
            .catch(error => {
                showAlert('Error resetting branding: ' + error.message, 'danger');
            });
    }

    // ============================================================
    // LOAD CHANGE HISTORY
    // ============================================================
    async function loadHistory() {
        const container = document.getElementById('historyContainer');

        try {
            const response = await fetch(`${API_BASE}/index.php?endpoint=schools/${schoolId}/branding/history`, {
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
                    <i class="fas fa-clock me-2"></i> No branding changes recorded yet.
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
        updatePreview();
    });
</script>