<?php

/**
 * School Settings - Academic Structure & Terminology
 * @package EduTrack
 * @subpackage Platform\Schools\Settings
 * @version 1.0
 */

$schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
if (!$schoolId) {
    $schoolId = $_SESSION['selected_school_id'] ?? 1;
}

// Default terminology
$currentTerminology = 'basic';
$hierarchy = [
    ['stage_code' => 'CRECHE', 'stage_name' => 'Creche', 'level_code' => 'CRECHE', 'canonical_name' => 'Creche', 'display_name' => 'Creche', 'short_name' => 'Creche', 'is_active' => 1],
    ['stage_code' => 'NURSERY', 'stage_name' => 'Nursery', 'level_code' => 'NURSERY_1', 'canonical_name' => 'Nursery 1', 'display_name' => 'Nursery 1', 'short_name' => 'N1', 'is_active' => 1],
    ['stage_code' => 'NURSERY', 'stage_name' => 'Nursery', 'level_code' => 'NURSERY_2', 'canonical_name' => 'Nursery 2', 'display_name' => 'Nursery 2', 'short_name' => 'N2', 'is_active' => 1],
    ['stage_code' => 'KINDERGARTEN', 'stage_name' => 'Kindergarten', 'level_code' => 'KINDERGARTEN_1', 'canonical_name' => 'Kindergarten 1', 'display_name' => 'Kindergarten 1', 'short_name' => 'KG1', 'is_active' => 1],
    ['stage_code' => 'KINDERGARTEN', 'stage_name' => 'Kindergarten', 'level_code' => 'KINDERGARTEN_2', 'canonical_name' => 'Kindergarten 2', 'display_name' => 'Kindergarten 2', 'short_name' => 'KG2', 'is_active' => 1],
    ['stage_code' => 'PRIMARY_LEVEL', 'stage_name' => 'Primary Level', 'level_code' => 'PRIMARY_BASIC_1', 'canonical_name' => 'Basic 1', 'display_name' => 'Basic 1', 'short_name' => 'B1', 'is_active' => 1],
    ['stage_code' => 'PRIMARY_LEVEL', 'stage_name' => 'Primary Level', 'level_code' => 'PRIMARY_BASIC_2', 'canonical_name' => 'Basic 2', 'display_name' => 'Basic 2', 'short_name' => 'B2', 'is_active' => 1],
    ['stage_code' => 'PRIMARY_LEVEL', 'stage_name' => 'Primary Level', 'level_code' => 'PRIMARY_BASIC_3', 'canonical_name' => 'Basic 3', 'display_name' => 'Basic 3', 'short_name' => 'B3', 'is_active' => 1],
    ['stage_code' => 'PRIMARY_LEVEL', 'stage_name' => 'Primary Level', 'level_code' => 'PRIMARY_BASIC_4', 'canonical_name' => 'Basic 4', 'display_name' => 'Basic 4', 'short_name' => 'B4', 'is_active' => 1],
    ['stage_code' => 'PRIMARY_LEVEL', 'stage_name' => 'Primary Level', 'level_code' => 'PRIMARY_BASIC_5', 'canonical_name' => 'Basic 5', 'display_name' => 'Basic 5', 'short_name' => 'B5', 'is_active' => 1],
    ['stage_code' => 'PRIMARY_LEVEL', 'stage_name' => 'Primary Level', 'level_code' => 'PRIMARY_BASIC_6', 'canonical_name' => 'Basic 6', 'display_name' => 'Basic 6', 'short_name' => 'B6', 'is_active' => 1],
    ['stage_code' => 'JHS', 'stage_name' => 'J.H.S.', 'level_code' => 'JHS_1', 'canonical_name' => 'J.H.S. 1', 'display_name' => 'J.H.S. 1', 'short_name' => 'JHS1', 'is_active' => 1],
    ['stage_code' => 'JHS', 'stage_name' => 'J.H.S.', 'level_code' => 'JHS_2', 'canonical_name' => 'J.H.S. 2', 'display_name' => 'J.H.S. 2', 'short_name' => 'JHS2', 'is_active' => 1],
    ['stage_code' => 'JHS', 'stage_name' => 'J.H.S.', 'level_code' => 'JHS_3', 'canonical_name' => 'J.H.S. 3', 'display_name' => 'J.H.S. 3', 'short_name' => 'JHS3', 'is_active' => 1]
];

// Load from session if available
if (isset($_SESSION['school_terminology_' . $schoolId])) {
    $currentTerminology = $_SESSION['school_terminology_' . $schoolId];
}

// If terminology is 'class', update display names
if ($currentTerminology === 'class') {
    foreach ($hierarchy as &$level) {
        if (strpos($level['level_code'], 'PRIMARY_BASIC_') === 0) {
            $num = str_replace('PRIMARY_BASIC_', '', $level['level_code']);
            $level['display_name'] = 'Class ' . $num;
            $level['short_name'] = 'C' . $num;
        }
    }
} elseif ($currentTerminology === 'grade') {
    foreach ($hierarchy as &$level) {
        if (strpos($level['level_code'], 'PRIMARY_BASIC_') === 0) {
            $num = str_replace('PRIMARY_BASIC_', '', $level['level_code']);
            $level['display_name'] = 'Grade ' . $num;
            $level['short_name'] = 'G' . $num;
        }
    }
}
?>
<div class="academic-structure-settings">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h5 class="mb-1"><i class="fas fa-sitemap me-2 text-primary"></i>Academic Structure & Terminology</h5>
            <p class="text-muted small mb-0">Configure how academic levels are displayed throughout EduTrack</p>
        </div>
        <div>
            <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
                <i class="fas fa-sync-alt me-1"></i> Refresh
            </button>
        </div>
    </div>

    <!-- Terminology Selection -->
    <div class="card-custom mb-4">
        <div class="card-header-custom">
            <h6><i class="fas fa-tag me-2 text-primary"></i>School Academic Naming</h6>
            <span class="text-muted small">How does your school refer to academic levels?</span>
        </div>
        <div class="card-body-custom">
            <div class="row g-3">
                <div class="col-md-3 col-6">
                    <div class="terminology-option <?php echo $currentTerminology == 'basic' ? 'selected' : ''; ?>"
                        onclick="selectTerminology('basic')">
                        <div class="terminology-card">
                            <div class="terminology-icon"><i class="fas fa-book"></i></div>
                            <div class="terminology-name">Basic</div>
                            <div class="terminology-example">Basic 1, Basic 2, ...</div>
                            <?php if ($currentTerminology == 'basic'): ?>
                                <span class="badge bg-success mt-2 d-block">Active</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="terminology-option <?php echo $currentTerminology == 'class' ? 'selected' : ''; ?>"
                        onclick="selectTerminology('class')">
                        <div class="terminology-card">
                            <div class="terminology-icon"><i class="fas fa-users"></i></div>
                            <div class="terminology-name">Class</div>
                            <div class="terminology-example">Class 1, Class 2, ...</div>
                            <?php if ($currentTerminology == 'class'): ?>
                                <span class="badge bg-success mt-2 d-block">Active</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="terminology-option <?php echo $currentTerminology == 'grade' ? 'selected' : ''; ?>"
                        onclick="selectTerminology('grade')">
                        <div class="terminology-card">
                            <div class="terminology-icon"><i class="fas fa-star"></i></div>
                            <div class="terminology-name">Grade</div>
                            <div class="terminology-example">Grade 1, Grade 2, ...</div>
                            <?php if ($currentTerminology == 'grade'): ?>
                                <span class="badge bg-success mt-2 d-block">Active</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="terminology-option <?php echo $currentTerminology == 'custom' ? 'selected' : ''; ?>"
                        onclick="selectTerminology('custom')">
                        <div class="terminology-card">
                            <div class="terminology-icon"><i class="fas fa-pen"></i></div>
                            <div class="terminology-name">Custom</div>
                            <div class="terminology-example">Define your own</div>
                            <?php if ($currentTerminology == 'custom'): ?>
                                <span class="badge bg-success mt-2 d-block">Active</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div id="terminologyMessage" class="mt-3" style="display:none;"></div>
        </div>
    </div>

    <!-- Academic Structure Tree -->
    <div class="card-custom">
        <div class="card-header-custom">
            <h6><i class="fas fa-sitemap me-2 text-primary"></i>Academic Structure</h6>
            <div>
                <button class="btn btn-outline-secondary btn-sm" onclick="toggleAllLevels(true)">
                    <i class="fas fa-check-double me-1"></i> Enable All
                </button>
                <button class="btn btn-outline-secondary btn-sm" onclick="toggleAllLevels(false)">
                    <i class="fas fa-times me-1"></i> Disable All
                </button>
            </div>
        </div>
        <div class="card-body-custom">
            <div class="academic-tree">
                <?php
                $currentStage = '';
                foreach ($hierarchy as $level):
                    if ($level['stage_code'] !== $currentStage):
                        if ($currentStage !== ''): ?>
            </div>
        <?php endif; ?>
        <div class="stage-group">
            <div class="stage-header" onclick="toggleStage(this)">
                <i class="fas fa-chevron-down stage-toggle"></i>
                <span class="stage-name"><?php echo htmlspecialchars($level['stage_name']); ?></span>
                <span class="stage-count badge bg-secondary"><?php
                                                                $count = 0;
                                                                foreach ($hierarchy as $l) {
                                                                    if ($l['stage_code'] === $level['stage_code']) $count++;
                                                                }
                                                                echo $count;
                                                                ?></span>
            </div>
            <div class="stage-levels">
            <?php
                        $currentStage = $level['stage_code'];
                    endif;
            ?>
            <div class="level-item">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="level-info">
                        <span class="level-name"><?php echo htmlspecialchars($level['display_name'] ?? $level['canonical_name']); ?></span>
                        <span class="level-code text-muted small"><?php echo htmlspecialchars($level['level_code']); ?></span>
                        <?php if ($level['short_name']): ?>
                            <span class="badge bg-secondary"><?php echo htmlspecialchars($level['short_name']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="level-controls">
                        <label class="switch">
                            <input type="checkbox" class="level-toggle"
                                <?php echo $level['is_active'] ? 'checked' : ''; ?>
                                data-level="<?php echo htmlspecialchars($level['level_code']); ?>">
                            <span class="slider round"></span>
                        </label>
                        <?php if ($currentTerminology == 'custom'): ?>
                            <button class="btn btn-outline-primary btn-sm ms-2"
                                onclick="editLevelName('<?php echo htmlspecialchars($level['level_code']); ?>')">
                                <i class="fas fa-edit"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
            </div>
        </div>
        </div>
    </div>
</div>

<!-- Version History -->
<div class="card-custom mt-4">
    <div class="card-header-custom">
        <h6><i class="fas fa-history me-2 text-muted"></i>Terminology Version History</h6>
        <button class="btn btn-outline-secondary btn-sm" onclick="loadVersionHistory()">
            <i class="fas fa-sync-alt me-1"></i> Refresh
        </button>
    </div>
    <div class="card-body-custom">
        <div id="versionHistoryContainer">
            <div class="text-center py-3 text-muted">
                <i class="fas fa-spinner fa-spin me-2"></i> Loading history...
            </div>
        </div>
    </div>
</div>
</div>

<script>
    // ============================================================
    // CONFIGURATION
    // ============================================================
    var SCHOOL_ID = <?php echo json_encode($schoolId); ?>;

    // ============================================================
    // SHOW ALERT (uses parent function if available)
    // ============================================================
    function showAlert(message, type) {
        type = type || 'info';
        // Try to use parent's showAlert
        if (typeof window.parent !== 'undefined' && typeof window.parent.showAlert === 'function') {
            window.parent.showAlert(message, type);
            return;
        }
        // Fallback alert
        alert(message);
    }

    // ============================================================
    // SELECT TERMINOLOGY - Uses session storage (no API call)
    // ============================================================
    function selectTerminology(type) {
        if (!confirm('This will change how academic levels are displayed throughout EduTrack. Continue?')) {
            return;
        }

        // Save to session via AJAX to a simple PHP endpoint
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/platform/schools/settings/save-terminology.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        showAlert('Terminology updated successfully!', 'success');
                        location.reload();
                    } else {
                        showAlert(response.message || 'Error updating terminology', 'danger');
                    }
                } catch (e) {
                    showAlert('Error updating terminology', 'danger');
                }
            } else {
                showAlert('Server error: ' + xhr.status, 'danger');
            }
        };
        xhr.onerror = function() {
            showAlert('Network error. Please try again.', 'danger');
        };
        xhr.send('school_id=' + SCHOOL_ID + '&terminology=' + type);
    }

    // ============================================================
    // TOGGLE LEVEL
    // ============================================================
    document.querySelectorAll('.level-toggle').forEach(function(toggle) {
        toggle.addEventListener('change', function() {
            var levelCode = this.dataset.level;
            var isActive = this.checked ? 1 : 0;

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/platform/schools/settings/save-level.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            showAlert('Level updated successfully', 'success');
                        } else {
                            showAlert(response.message || 'Error updating level', 'danger');
                            this.checked = !this.checked;
                        }
                    } catch (e) {
                        showAlert('Error updating level', 'danger');
                        this.checked = !this.checked;
                    }
                } else {
                    showAlert('Server error: ' + xhr.status, 'danger');
                    this.checked = !this.checked;
                }
            };
            xhr.onerror = function() {
                showAlert('Network error. Please try again.', 'danger');
                this.checked = !this.checked;
            };
            xhr.send('school_id=' + SCHOOL_ID + '&level_code=' + levelCode + '&is_active=' + isActive);
        });
    });

    // ============================================================
    // TOGGLE STAGE
    // ============================================================
    function toggleStage(header) {
        var levels = header.nextElementSibling;
        var toggle = header.querySelector('.stage-toggle');
        if (levels) {
            levels.style.display = levels.style.display === 'none' ? 'block' : 'none';
            toggle.classList.toggle('fa-chevron-down');
            toggle.classList.toggle('fa-chevron-right');
        }
    }

    // ============================================================
    // TOGGLE ALL LEVELS
    // ============================================================
    function toggleAllLevels(active) {
        document.querySelectorAll('.level-toggle').forEach(function(toggle) {
            toggle.checked = active;
            toggle.dispatchEvent(new Event('change'));
        });
    }

    // ============================================================
    // EDIT LEVEL NAME (Custom terminology)
    // ============================================================
    function editLevelName(levelCode) {
        var currentName = prompt('Enter custom name for this level:');
        if (currentName) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/platform/schools/settings/save-level-name.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            showAlert('Level name updated!', 'success');
                            location.reload();
                        } else {
                            showAlert(response.message || 'Error updating level name', 'danger');
                        }
                    } catch (e) {
                        showAlert('Error updating level name', 'danger');
                    }
                } else {
                    showAlert('Server error: ' + xhr.status, 'danger');
                }
            };
            xhr.onerror = function() {
                showAlert('Network error. Please try again.', 'danger');
            };
            xhr.send('school_id=' + SCHOOL_ID + '&level_code=' + levelCode + '&custom_name=' + encodeURIComponent(currentName));
        }
    }

    // ============================================================
    // LOAD VERSION HISTORY
    // ============================================================
    function loadVersionHistory() {
        var container = document.getElementById('versionHistoryContainer');
        container.innerHTML = `
            <div class="text-center py-3 text-muted">
                <i class="fas fa-spinner fa-spin me-2"></i> Loading history...
            </div>
        `;

        // Mock data for now
        setTimeout(function() {
            var html = `
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <div>
                        <span class="badge bg-success me-2">Published</span>
                        <span class="text-muted">Terminology V2</span>
                        <span class="text-muted small ms-2">${document.querySelector('.terminology-option.selected .terminology-name')?.textContent || 'Class'} 1, ${document.querySelector('.terminology-option.selected .terminology-name')?.textContent || 'Class'} 2, ...</span>
                    </div>
                    <div class="text-muted small">
                        <span>Admin User</span>
                        <span class="ms-2">2026-08-01 10:30</span>
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center py-2">
                    <div>
                        <span class="badge bg-secondary me-2">Archived</span>
                        <span class="text-muted">Terminology V1</span>
                        <span class="text-muted small ms-2">Basic 1, Basic 2, ...</span>
                    </div>
                    <div class="text-muted small">
                        <span>Admin User</span>
                        <span class="ms-2">2025-08-01 10:30</span>
                    </div>
                </div>
            `;
            container.innerHTML = html;
        }, 500);
    }

    // ============================================================
    // INITIALIZE
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        loadVersionHistory();
    });
</script>

<style>
    /* Terminology Cards */
    .terminology-option {
        cursor: pointer;
    }

    .terminology-option .terminology-card {
        background: #fff;
        border: 2px solid #e9ecf;
        border-radius: 12px;
        padding: 16px;
        text-align: center;
        transition: all 0.3s;
        height: 100%;
    }

    .terminology-option:hover .terminology-card {
        border-color: #4facfe;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        transform: translateY(-2px);
    }

    .terminology-option.selected .terminology-card {
        border-color: #28a745;
        background: #f8fff9;
    }

    .terminology-option .terminology-icon {
        font-size: 24px;
        color: #4facfe;
        margin-bottom: 8px;
    }

    .terminology-option .terminology-name {
        font-weight: 600;
        font-size: 16px;
    }

    .terminology-option .terminology-example {
        font-size: 12px;
        color: #6c757d;
        margin-top: 4px;
    }

    /* Academic Tree */
    .academic-tree {
        border-left: 2px solid #e9ecf;
        padding-left: 16px;
    }

    .stage-group {
        margin-bottom: 8px;
    }

    .stage-header {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        background: #f8f9fa;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
    }

    .stage-header:hover {
        background: #e9ecf;
    }

    .stage-header .stage-toggle {
        transition: transform 0.3s;
        font-size: 12px;
        color: #6c757d;
    }

    .stage-header .stage-count {
        margin-left: auto;
        font-size: 11px;
    }

    .stage-levels {
        padding-left: 24px;
        margin-top: 4px;
    }

    .level-item {
        padding: 6px 12px;
        border-bottom: 1px solid #f0f2f5;
    }

    .level-item:last-child {
        border-bottom: none;
    }

    .level-item .level-name {
        font-weight: 500;
    }

    .level-item .level-code {
        font-size: 11px;
        color: #6c757d;
        margin-left: 8px;
        font-family: monospace;
    }

    /* Toggle Switch */
    .switch {
        position: relative;
        display: inline-block;
        width: 40px;
        height: 22px;
    }

    .switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: #e9ecf;
        transition: 0.3s;
        border-radius: 22px;
    }

    .slider:before {
        position: absolute;
        content: "";
        height: 16px;
        width: 16px;
        left: 3px;
        bottom: 3px;
        background: #fff;
        transition: 0.3s;
        border-radius: 50%;
    }

    input:checked+.slider {
        background: #28a745;
    }

    input:checked+.slider:before {
        transform: translateX(18px);
    }
</style>