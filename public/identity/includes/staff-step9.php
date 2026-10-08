<?php

/**
 * Staff Registration - Step 9: Review & Submit
 * 
 * @package EduTrack
 * @subpackage Identity\Includes
 * @filepath public/identity/includes/staff-step9.php
 */
?>
<form id="stepForm" class="step-form">
    <input type="hidden" name="step" value="9">

    <div class="alert alert-success" style="border-radius:12px;border:none;font-size:13px;">
        <i class="fas fa-clipboard-check me-2"></i>
        <strong>Review your information before submitting.</strong>
        <p class="mb-0 mt-1 text-muted">You can go back to previous steps to make changes. All information will be saved securely.</p>
    </div>

    <div class="form-section">
        <div class="section-title"><i class="fas fa-user"></i> Staff Identity</div>
        <div class="table-responsive">
            <table class="table table-bordered" style="font-size:13px;">
                <tbody>
                    <tr>
                        <td style="width:30%;font-weight:600;background:#f8f9fa;">Full Name</td>
                        <td id="reviewName">-</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;background:#f8f9fa;">Date of Birth</td>
                        <td id="reviewDob">-</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;background:#f8f9fa;">Gender</td>
                        <td id="reviewGender">-</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;background:#f8f9fa;">Nationality</td>
                        <td id="reviewNationality">-</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="form-section">
        <div class="section-title"><i class="fas fa-tag"></i> Staff Category</div>
        <div class="table-responsive">
            <table class="table table-bordered" style="font-size:13px;">
                <tbody>
                    <tr>
                        <td style="width:30%;font-weight:600;background:#f8f9fa;">Category</td>
                        <td id="reviewCategory">-</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;background:#f8f9fa;">Department</td>
                        <td id="reviewDepartment">-</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;background:#f8f9fa;">Position</td>
                        <td id="reviewPosition">-</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;background:#f8f9fa;">School / Campus</td>
                        <td id="reviewSchool">-</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="form-section">
        <div class="section-title"><i class="fas fa-briefcase"></i> Employment</div>
        <div class="table-responsive">
            <table class="table table-bordered" style="font-size:13px;">
                <tbody>
                    <tr>
                        <td style="width:30%;font-weight:600;background:#f8f9fa;">Employment Status</td>
                        <td id="reviewEmploymentStatus">-</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;background:#f8f9fa;">Date Hired</td>
                        <td id="reviewDateHired">-</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;background:#f8f9fa;">Supervisor</td>
                        <td id="reviewSupervisor">-</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="form-section">
        <div class="section-title"><i class="fas fa-graduation-cap"></i> Qualifications</div>
        <div id="reviewQualifications">
            <span class="text-muted">No qualifications added</span>
        </div>
    </div>

    <div class="form-check mt-3">
        <input class="form-check-input" type="checkbox" id="confirmCheck" required>
        <label class="form-check-label" for="confirmCheck">
            I confirm that all the information provided is accurate and complete.
        </label>
    </div>
</form>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Load data into review sections
        if (typeof registrationData !== 'undefined') {
            const person = registrationData.person || {};
            const category = registrationData.category || {};
            const employment = registrationData.employment || {};

            document.getElementById('reviewName').textContent =
                (person.first_name || '') + ' ' + (person.last_name || '') || '-';
            document.getElementById('reviewDob').textContent = person.date_of_birth || '-';
            document.getElementById('reviewGender').textContent = person.gender ? person.gender.charAt(0).toUpperCase() +
                person.gender.slice(1) : '-';
            document.getElementById('reviewNationality').textContent = person.nationality || '-';

            document.getElementById('reviewCategory').textContent = category.staff_category || '-';
            document.getElementById('reviewDepartment').textContent = category.department || '-';
            document.getElementById('reviewPosition').textContent = category.position || '-';
            document.getElementById('reviewSchool').textContent = category.school_id || '-';

            document.getElementById('reviewEmploymentStatus').textContent = employment.employment_status || '-';
            document.getElementById('reviewDateHired').textContent = employment.date_hired || '-';
            document.getElementById('reviewSupervisor').textContent = employment.supervisor || '-';

            // Update qualifications review
            if (registrationData.qualifications && registrationData.qualifications.length > 0) {
                const container = document.getElementById('reviewQualifications');
                container.innerHTML = registrationData.qualifications.map(q =>
                    `<div class="badge bg-light text-dark me-1 mb-1 p-2">${q.type} - ${q.institution}</div>`
                ).join('');
            }
        }
    });
</script>