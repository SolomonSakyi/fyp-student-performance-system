<!-- Appearance Settings -->
<div class="card-custom">
    <div class="card-header-custom">
        <h6><i class="fas fa-palette me-2 text-primary"></i>Appearance Settings</h6>
        <span class="badge bg-info">Branding & UI</span>
    </div>
    <div class="card-body-custom">
        <form id="appearanceForm" onsubmit="saveAppearanceSettings(event)">
            <!-- Branding -->
            <h6 class="mb-3"><i class="fas fa-building me-2 text-secondary"></i>Branding</h6>
            <div class="row">
                <div class="col-md-6 col-12">
                    <div class="mb-2">
                        <label class="form-label">Platform Name <span class="required">*</span></label>
                        <input type="text" class="form-control" id="brandPlatformName" placeholder="EduTrack" value="EduTrack" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Platform Tagline</label>
                        <input type="text" class="form-control" id="brandTagline" placeholder="Smart Education Management" value="Smart Education Management">
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="mb-2">
                        <label class="form-label">Logo URL</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="logoUrl" placeholder="/assets/images/logo.png">
                            <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('logoUpload').click()">
                                <i class="fas fa-upload"></i>
                            </button>
                            <input type="file" id="logoUpload" accept="image/*" style="display:none;" onchange="previewLogo(event)">
                        </div>
                        <small class="form-text">Upload a logo or enter a URL</small>
                    </div>
                    <div class="mb-2" id="logoPreviewContainer" style="display:none;">
                        <img id="logoPreview" src="" alt="Logo Preview" style="max-height:60px; border:1px solid #e9ecef; border-radius:8px; padding:4px;">
                        <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="removeLogo()">
                            <i class="fas fa-times"></i> Remove
                        </button>
                    </div>
                </div>
            </div>

            <hr class="my-3">

            <!-- Colors -->
            <h6 class="mb-3"><i class="fas fa-palette me-2 text-secondary"></i>Color Scheme</h6>
            <div class="row">
                <div class="col-md-3 col-6">
                    <div class="mb-2">
                        <label class="form-label">Primary Color</label>
                        <div class="input-group">
                            <input type="color" class="form-control form-control-color" id="primaryColor" value="#4facfe" style="width:50px; padding:2px; height:38px;">
                            <input type="text" class="form-control" id="primaryColorHex" value="#4facfe" style="flex:1;">
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="mb-2">
                        <label class="form-label">Secondary Color</label>
                        <div class="input-group">
                            <input type="color" class="form-control form-control-color" id="secondaryColor" value="#00f2fe" style="width:50px; padding:2px; height:38px;">
                            <input type="text" class="form-control" id="secondaryColorHex" value="#00f2fe" style="flex:1;">
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="mb-2">
                        <label class="form-label">Success Color</label>
                        <div class="input-group">
                            <input type="color" class="form-control form-control-color" id="successColor" value="#28a745" style="width:50px; padding:2px; height:38px;">
                            <input type="text" class="form-control" id="successColorHex" value="#28a745" style="flex:1;">
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="mb-2">
                        <label class="form-label">Danger Color</label>
                        <div class="input-group">
                            <input type="color" class="form-control form-control-color" id="dangerColor" value="#dc3545" style="width:50px; padding:2px; height:38px;">
                            <input type="text" class="form-control" id="dangerColorHex" value="#dc3545" style="flex:1;">
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-md-12">
                    <div class="d-flex gap-3 align-items-center">
                        <span class="badge" style="background:#4facfe; color:#fff; padding:8px 16px;">Primary</span>
                        <span class="badge" style="background:#00f2fe; color:#fff; padding:8px 16px;">Secondary</span>
                        <span class="badge" style="background:#28a745; color:#fff; padding:8px 16px;">Success</span>
                        <span class="badge" style="background:#dc3545; color:#fff; padding:8px 16px;">Danger</span>
                        <span class="text-muted" style="font-size:12px;">Live preview of your colors</span>
                    </div>
                </div>
            </div>

            <hr class="my-3">

            <!-- Theme -->
            <h6 class="mb-3"><i class="fas fa-moon me-2 text-secondary"></i>Theme & Layout</h6>
            <div class="row">
                <div class="col-md-4 col-12">
                    <div class="mb-2">
                        <label class="form-label">Default Theme</label>
                        <select class="form-select" id="defaultTheme">
                            <option value="light">Light</option>
                            <option value="dark">Dark</option>
                            <option value="auto">Auto (System)</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-4 col-12">
                    <div class="mb-2">
                        <label class="form-label">Sidebar Style</label>
                        <select class="form-select" id="sidebarStyle">
                            <option value="default">Default</option>
                            <option value="compact">Compact</option>
                            <option value="collapsible">Collapsible</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-4 col-12">
                    <div class="mb-2">
                        <label class="form-label">Font Family</label>
                        <select class="form-select" id="fontFamily">
                            <option value="inter">Inter</option>
                            <option value="roboto">Roboto</option>
                            <option value="open-sans">Open Sans</option>
                            <option value="poppins">Poppins</option>
                            <option value="system">System Default</option>
                        </select>
                    </div>
                </div>
            </div>

            <hr class="my-3">

            <!-- Favicon -->
            <h6 class="mb-3"><i class="fas fa-image me-2 text-secondary"></i>Favicon</h6>
            <div class="row">
                <div class="col-md-12">
                    <div class="mb-2">
                        <label class="form-label">Favicon URL</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="faviconUrl" placeholder="/assets/images/favicon.ico">
                            <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('faviconUpload').click()">
                                <i class="fas fa-upload"></i>
                            </button>
                            <input type="file" id="faviconUpload" accept="image/*" style="display:none;" onchange="previewFavicon(event)">
                        </div>
                        <small class="form-text">Upload a favicon (ICO, PNG, SVG) or enter a URL</small>
                    </div>
                    <div id="faviconPreviewContainer" style="display:none;">
                        <img id="faviconPreview" src="" alt="Favicon Preview" style="max-height:32px; border:1px solid #e9ecef; border-radius:4px; padding:4px;">
                        <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="removeFavicon()">
                            <i class="fas fa-times"></i> Remove
                        </button>
                    </div>
                </div>
            </div>

            <hr class="my-3">

            <div class="d-flex gap-2 flex-wrap justify-content-end">
                <button type="button" class="btn btn-outline-secondary" onclick="resetAppearance()">
                    <i class="fas fa-undo me-2"></i> Reset to Defaults
                </button>
                <button type="submit" class="btn btn-primary" id="appearanceSubmitBtn">
                    <i class="fas fa-save me-2"></i> Save Appearance Settings
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Color Picker Sync -->
<script>
    // ================================================
    // COLOR PICKER SYNC
    // ================================================
    document.addEventListener('DOMContentLoaded', function() {
        // Sync color picker with hex input
        const colorInputs = ['primaryColor', 'secondaryColor', 'successColor', 'dangerColor'];
        colorInputs.forEach(id => {
            const picker = document.getElementById(id);
            const hexInput = document.getElementById(id + 'Hex');
            if (picker && hexInput) {
                picker.addEventListener('input', function() {
                    hexInput.value = this.value;
                });
                hexInput.addEventListener('input', function() {
                    picker.value = this.value;
                });
            }
        });
    });

    // ================================================
    // LOGO PREVIEW
    // ================================================
    function previewLogo(event) {
        const file = event.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('logoPreview').src = e.target.result;
                document.getElementById('logoPreviewContainer').style.display = 'block';
                document.getElementById('logoUrl').value = e.target.result;
            };
            reader.readAsDataURL(file);
        }
    }

    function removeLogo() {
        document.getElementById('logoPreview').src = '';
        document.getElementById('logoPreviewContainer').style.display = 'none';
        document.getElementById('logoUrl').value = '';
        document.getElementById('logoUpload').value = '';
    }

    // ================================================
    // FAVICON PREVIEW
    // ================================================
    function previewFavicon(event) {
        const file = event.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('faviconPreview').src = e.target.result;
                document.getElementById('faviconPreviewContainer').style.display = 'block';
                document.getElementById('faviconUrl').value = e.target.result;
            };
            reader.readAsDataURL(file);
        }
    }

    function removeFavicon() {
        document.getElementById('faviconPreview').src = '';
        document.getElementById('faviconPreviewContainer').style.display = 'none';
        document.getElementById('faviconUrl').value = '';
        document.getElementById('faviconUpload').value = '';
    }

    // ================================================
    // RESET APPEARANCE
    // ================================================
    function resetAppearance() {
        if (!confirm('Reset all appearance settings to defaults?')) return;

        document.getElementById('brandPlatformName').value = 'EduTrack';
        document.getElementById('brandTagline').value = 'Smart Education Management';
        document.getElementById('logoUrl').value = '';
        document.getElementById('logoPreviewContainer').style.display = 'none';
        document.getElementById('primaryColor').value = '#4facfe';
        document.getElementById('primaryColorHex').value = '#4facfe';
        document.getElementById('secondaryColor').value = '#00f2fe';
        document.getElementById('secondaryColorHex').value = '#00f2fe';
        document.getElementById('successColor').value = '#28a745';
        document.getElementById('successColorHex').value = '#28a745';
        document.getElementById('dangerColor').value = '#dc3545';
        document.getElementById('dangerColorHex').value = '#dc3545';
        document.getElementById('defaultTheme').value = 'light';
        document.getElementById('sidebarStyle').value = 'default';
        document.getElementById('fontFamily').value = 'inter';
        document.getElementById('faviconUrl').value = '';
        document.getElementById('faviconPreviewContainer').style.display = 'none';

        showAlert('Appearance settings reset to defaults', 'success');
    }

    // ================================================
    // LOAD EXISTING APPEARANCE SETTINGS
    // ================================================
    async function loadAppearanceSettings() {
        try {
            const response = await fetch(`${API_BASE}/index.php?endpoint=settings&action=appearance`, {
                method: 'GET',
                headers: getHeaders()
            });

            const result = await response.json();

            if (!result.success || !result.data) {
                return;
            }

            const data = result.data;

            if (data.platform_name) document.getElementById('brandPlatformName').value = data.platform_name;
            if (data.tagline) document.getElementById('brandTagline').value = data.tagline;
            if (data.logo_url) document.getElementById('logoUrl').value = data.logo_url;
            if (data.favicon_url) document.getElementById('faviconUrl').value = data.favicon_url;

            // Color fields: set both the picker and the hex text input.
            // The save handler reads the hex input, so both must be set
            // or the picker and the saved value will disagree.
            if (data.primary_color) {
                document.getElementById('primaryColor').value = data.primary_color;
                document.getElementById('primaryColorHex').value = data.primary_color;
            }
            if (data.secondary_color) {
                document.getElementById('secondaryColor').value = data.secondary_color;
                document.getElementById('secondaryColorHex').value = data.secondary_color;
            }
            if (data.success_color) {
                document.getElementById('successColor').value = data.success_color;
                document.getElementById('successColorHex').value = data.success_color;
            }
            if (data.danger_color) {
                document.getElementById('dangerColor').value = data.danger_color;
                document.getElementById('dangerColorHex').value = data.danger_color;
            }

            if (data.default_theme) document.getElementById('defaultTheme').value = data.default_theme;
            if (data.sidebar_style) document.getElementById('sidebarStyle').value = data.sidebar_style;
            if (data.font_family) document.getElementById('fontFamily').value = data.font_family;
        } catch (error) {
            console.error('Error loading appearance settings:', error);
        }
    }

    // ================================================
    // SAVE APPEARANCE SETTINGS
    // ================================================
    async function saveAppearanceSettings(event) {
        event.preventDefault();

        const submitBtn = document.getElementById('appearanceSubmitBtn');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

        try {
            const data = {
                platform_name: document.getElementById('brandPlatformName').value.trim(),
                tagline: document.getElementById('brandTagline').value.trim(),
                logo_url: document.getElementById('logoUrl').value.trim(),
                primary_color: document.getElementById('primaryColorHex').value,
                secondary_color: document.getElementById('secondaryColorHex').value,
                success_color: document.getElementById('successColorHex').value,
                danger_color: document.getElementById('dangerColorHex').value,
                default_theme: document.getElementById('defaultTheme').value,
                sidebar_style: document.getElementById('sidebarStyle').value,
                font_family: document.getElementById('fontFamily').value,
                favicon_url: document.getElementById('faviconUrl').value.trim()
            };

            const response = await fetch(`${API_BASE}/index.php?endpoint=settings&action=appearance`, {
                method: 'POST',
                headers: getHeaders(),
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                showAlert('Appearance settings saved successfully!', 'success');
                // Apply theme changes immediately
                applyTheme(data);
            } else {
                showAlert('✗ ' + (result.message || 'Failed to save settings'), 'danger');
            }
        } catch (error) {
            console.error('Error saving appearance:', error);
            showAlert('Error saving appearance settings: ' + error.message, 'danger');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    }

    // ================================================
    // APPLY THEME
    // ================================================
    function applyTheme(data) {
        // Apply primary color to sidebar active state
        const style = document.createElement('style');
        style.id = 'dynamic-theme';
        style.textContent = `
            .sidebar .nav-link.active {
                background: ${data.primary_color} !important;
            }
            .btn-primary {
                background: ${data.primary_color} !important;
                border-color: ${data.primary_color} !important;
            }
            .btn-primary:hover {
                background: ${adjustColor(data.primary_color, -20)} !important;
                border-color: ${adjustColor(data.primary_color, -20)} !important;
            }
            .stat-card .stat-icon.blue {
                background: ${data.primary_color}22 !important;
                color: ${data.primary_color} !important;
            }
            .badge.bg-primary {
                background: ${data.primary_color} !important;
            }
            .badge.bg-success {
                background: ${data.success_color} !important;
            }
            .badge.bg-danger {
                background: ${data.danger_color} !important;
            }
        `;

        // Remove existing dynamic theme
        const existing = document.getElementById('dynamic-theme');
        if (existing) existing.remove();
        document.head.appendChild(style);
    }

    // ================================================
    // ADJUST COLOR (Helper)
    // ================================================
    function adjustColor(hex, percent) {
        // Simple color adjustment for hover states
        let r = parseInt(hex.slice(1, 3), 16);
        let g = parseInt(hex.slice(3, 5), 16);
        let b = parseInt(hex.slice(5, 7), 16);

        r = Math.max(0, Math.min(255, r + percent));
        g = Math.max(0, Math.min(255, g + percent));
        b = Math.max(0, Math.min(255, b + percent));

        return `#${r.toString(16).padStart(2,'0')}${g.toString(16).padStart(2,'0')}${b.toString(16).padStart(2,'0')}`;
    }

    // Load existing settings when page loads
    document.addEventListener('DOMContentLoaded', loadAppearanceSettings);
</script>