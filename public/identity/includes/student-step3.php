<?php

/**
 * Step 3: Parent/Guardian Information
 * 
 * @package EduTrack
 * @subpackage Identity
 * @version 2.0
 */
?>
<div class="step-title"><i class="fas fa-users me-2 text-primary"></i>Parent/Guardian Information</div>
<p class="step-subtitle">Add the student's parent or guardian. You can search for an existing person or create a new one.</p>

<form id="stepForm" class="step-form">
    <!-- Search Existing -->
    <div class="form-section">
        <div class="section-title"><i class="fas fa-search"></i> Search Existing Person</div>
        <div class="row">
            <div class="col-md-8 col-12">
                <div class="mb-2">
                    <div class="input-group">
                        <input type="text" class="form-control" id="parentSearch" placeholder="Search by name, phone, email, or person number...">
                        <button type="button" class="btn btn-primary" id="searchParentBtn">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                    <div class="form-text">Search within <?php echo htmlspecialchars($schoolName); ?></div>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Selected Parent/Guardian</label>
                    <div id="selectedParentDisplay" style="padding:8px 12px;border:2px solid #e9ecef;border-radius:10px;min-height:44px;display:flex;align-items:center;color:#6c757d;">
                        No parent/guardian selected
                    </div>
                    <input type="hidden" id="selectedParentId" name="parent_id" value="">
                    <input type="hidden" id="selectedParentName" name="parent_name" value="">
                </div>
            </div>
        </div>
        <div id="searchResults" class="search-results" style="display:none;"></div>
    </div>

    <hr>

    <!-- Create New -->
    <div class="form-section">
        <div class="section-title"><i class="fas fa-user-plus"></i> Create New Parent/Guardian</div>
        <div class="row">
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">First Name <span class="required">*</span></label>
                    <input type="text" class="form-control" name="guardian_first_name" placeholder="Enter first name">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Middle Name</label>
                    <input type="text" class="form-control" name="guardian_middle_name" placeholder="Enter middle name">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Last Name <span class="required">*</span></label>
                    <input type="text" class="form-control" name="guardian_last_name" placeholder="Enter last name">
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Gender</label>
                    <select class="form-select" name="guardian_gender">
                        <option value="">Select Gender</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" class="form-control" name="guardian_date_of_birth">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Nationality</label>
                    <input type="text" class="form-control" name="guardian_nationality" placeholder="Enter nationality">
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">Phone <span class="required">*</span></label>
                    <input type="tel" class="form-control" name="guardian_phone" placeholder="+233 XX XXX XXXX">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="guardian_email" placeholder="guardian@example.com">
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Relationship Type <span class="required">*</span></label>
                    <select class="form-select" name="guardian_relationship_type">
                        <option value="">Select Relationship</option>
                        <option value="father">Father</option>
                        <option value="mother">Mother</option>
                        <option value="legal_guardian">Legal Guardian</option>
                        <option value="foster_parent">Foster Parent</option>
                        <option value="grandparent">Grandparent</option>
                        <option value="sibling">Sibling</option>
                        <option value="caregiver">Caregiver</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="is_primary_guardian" value="1">
                        <label class="form-check-label">Primary Guardian</label>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="is_emergency_contact" value="1">
                        <label class="form-check-label">Emergency Contact</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="mt-2">
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addGuardian()">
                <i class="fas fa-plus me-1"></i> Add as Guardian
            </button>
        </div>
    </div>

    <!-- Added Guardians List -->
    <div class="form-section" style="margin-top:16px;">
        <div class="section-title"><i class="fas fa-list"></i> Added Guardians</div>
        <div id="guardianList" class="text-muted small">
            <i class="fas fa-info-circle me-1"></i> No guardians added yet. Search and select or create a new guardian above.
        </div>
    </div>
</form>

<script>
    // ================================================
    // ADD GUARDIAN TO LIST
    // ================================================
    let guardians = [];

    function addGuardian() {
        const firstName = document.querySelector('[name="guardian_first_name"]')?.value?.trim();
        const lastName = document.querySelector('[name="guardian_last_name"]')?.value?.trim();
        const relationship = document.querySelector('[name="guardian_relationship_type"]')?.value;
        const phone = document.querySelector('[name="guardian_phone"]')?.value?.trim();

        if (!firstName || !lastName || !relationship) {
            showAlert('Please fill in First Name, Last Name, and Relationship Type.', 'warning');
            return;
        }

        const guardian = {
            id: 'g_' + Date.now(),
            first_name: firstName,
            last_name: lastName,
            relationship: relationship,
            phone: phone || 'N/A',
            is_primary: document.querySelector('[name="is_primary_guardian"]')?.checked || false,
            is_emergency: document.querySelector('[name="is_emergency_contact"]')?.checked || false
        };

        guardians.push(guardian);
        renderGuardianList();

        // Clear form
        document.querySelectorAll('[name^="guardian_"]').forEach(el => {
            if (el.type === 'checkbox') {
                el.checked = false;
            } else {
                el.value = '';
            }
        });

        showAlert('Guardian added successfully!', 'success');
    }

    function removeGuardian(id) {
        guardians = guardians.filter(g => g.id !== id);
        renderGuardianList();
    }

    function renderGuardianList() {
        const container = document.getElementById('guardianList');

        if (guardians.length === 0) {
            container.innerHTML = '<i class="fas fa-info-circle me-1"></i> No guardians added yet. Search and select or create a new guardian above.';
            return;
        }

        let html = '<div class="table-responsive"><table class="table table-sm table-hover">';
        html += '<thead><tr><th>Name</th><th>Relationship</th><th>Phone</th><th>Primary</th><th>Emergency</th><th></th></tr></thead><tbody>';

        guardians.forEach(g => {
            html += `<tr>
                <td><strong>${g.first_name} ${g.last_name}</strong></td>
                <td><span class="badge bg-info">${g.relationship}</span></td>
                <td>${g.phone}</td>
                <td>${g.is_primary ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'}</td>
                <td>${g.is_emergency ? '<span class="badge bg-warning">Yes</span>' : '<span class="badge bg-secondary">No</span>'}</td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeGuardian('${g.id}')">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            </tr>`;
        });

        html += '</tbody></table></div>';
        container.innerHTML = html;
    }
</script>