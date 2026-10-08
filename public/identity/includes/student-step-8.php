<?php

/**
 * Step 8: Admission / Enrollment
 * 
 * @package EduTrack
 * @subpackage Identity
 * @version 2.0
 */
?>
<div class="step-title"><i class="fas fa-clipboard-list me-2 text-primary"></i>Admission & Enrollment</div>
<p class="step-subtitle">Complete the student's admission and enrollment details.</p>

<form id="stepForm" class="step-form">
    <!-- Admission Details -->
    <div class="form-section">
        <div class="section-title"><i class="fas fa-user-plus"></i> Admission Details</div>
        <div class="row">
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Admission Number <span class="required">*</span></label>
                    <input type="text" class="form-control" name="admission_number" required placeholder="Enter admission number">
                    <div class="form-text">Leave blank to auto-generate</div>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Admission Date <span class="required">*</span></label>
                    <input type="date" class="form-control" name="admission_date" required>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Admission Type <span class="required">*</span></label>
                    <select class="form-select" name="admission_type" required id="admissionType" onchange="toggleAdmissionType()">
                        <option value="">Select Type</option>
                        <option value="new_admission">New Admission</option>
                        <option value="transfer">Transfer</option>
                        <option value="re_admission">Re-admission</option>
                        <option value="returning">Returning Student</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Previous School (conditional) -->
    <div class="form-section" id="transferFields" style="display:none;">
        <div class="section-title"><i class="fas fa-arrow-right"></i> Previous School Information</div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">Previous School Name</label>
                    <input type="text" class="form-control" name="previous_school" placeholder="Name of previous school">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">Previous School Location</label>
                    <input type="text" class="form-control" name="previous_school_location" placeholder="Location of previous school">
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Previous School Contact</label>
                    <input type="text" class="form-control" name="previous_school_contact" placeholder="Contact number">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Previous Class/Level</label>
                    <input type="text" class="form-control" name="previous_class" placeholder="Previous class or level">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Last Attendance Date</label>
                    <input type="date" class="form-control" name="last_attendance_date">
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">Reason for Leaving</label>
                    <input type="text" class="form-control" name="reason_for_leaving" placeholder="Reason for leaving previous school">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="transfer_certificate_provided" value="1">
                        <label class="form-check-label">Transfer Certificate Provided</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Enrollment Details -->
    <div class="form-section">
        <div class="section-title"><i class="fas fa-edit"></i> Enrollment Details</div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">Entry Level</label>
                    <input type="text" class="form-control" name="entry_level" placeholder="Level at entry">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">Entry Class</label>
                    <input type="text" class="form-control" name="entry_class" placeholder="Class at entry">
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Date Joined School</label>
                    <input type="date" class="form-control" name="date_joined">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Academic Year of Admission</label>
                    <select class="form-select" name="admission_academic_year">
                        <option value="">Select Year</option>
                        <!-- Populated via JavaScript -->
                    </select>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Term of Admission</label>
                    <select class="form-select" name="admission_term">
                        <option value="">Select Term</option>
                        <!-- Populated via JavaScript -->
                    </select>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
    // ================================================
    // TOGGLE ADMISSION TYPE
    // ================================================
    function toggleAdmissionType() {
        const type = document.getElementById('admissionType').value;
        const transferFields = document.getElementById('transferFields');
        if (type === 'transfer') {
            transferFields.style.display = 'block';
        } else {
            transferFields.style.display = 'none';
        }
    }

    // ================================================
    // LOAD ADMISSION DROPDOWNS
    // ================================================
    document.addEventListener('DOMContentLoaded', function() {
        // Populate admission academic year dropdown
        const yearSelect = document.querySelector('[name="admission_academic_year"]');
        if (yearSelect) {
            fetch(API_BASE + '/index.php?endpoint=academic&action=years', {
                    headers: getHeaders()
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success && result.data) {
                        result.data.forEach(year => {
                            const option = document.createElement('option');
                            option.value = year.id;
                            option.textContent = year.name || year.year;
                            yearSelect.appendChild(option);
                        });
                    }
                })
                .catch(error => console.error('Error loading academic years:', error));
        }

        // Populate admission term dropdown
        const termSelect = document.querySelector('[name="admission_term"]');
        if (termSelect) {
            fetch(API_BASE + '/index.php?endpoint=academic&action=terms', {
                    headers: getHeaders()
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success && result.data) {
                        result.data.forEach(term => {
                            const option = document.createElement('option');
                            option.value = term.id;
                            option.textContent = term.name || term.term_name;
                            termSelect.appendChild(option);
                        });
                    }
                })
                .catch(error => console.error('Error loading academic terms:', error));
        }
    });
</script>