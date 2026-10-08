<?php

/**
 * Staff Registration - Step 6: Professional Information
 * 
 * @package EduTrack
 * @subpackage Identity\Includes
 * @filepath public/identity/includes/staff-step6.php
 */
?>
<form id="stepForm" class="step-form">
    <input type="hidden" name="step" value="6">

    <div class="form-section">
        <div class="section-title"><i class="fas fa-certificate"></i> Professional Information</div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Profession</label>
                    <input type="text" class="form-control" name="profession" placeholder="e.g., Teacher, Accountant">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Specialization</label>
                    <input type="text" class="form-control" name="specialization" placeholder="e.g., Mathematics, Auditing">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Professional Body</label>
                    <input type="text" class="form-control" name="professional_body" placeholder="e.g., GES, ICAG">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Registration Number</label>
                    <input type="text" class="form-control" name="registration_number" placeholder="Professional registration number">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">License Number</label>
                    <input type="text" class="form-control" name="license_number" placeholder="Professional license number">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">License Issue Date</label>
                    <input type="date" class="form-control" name="license_issue_date">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">License Expiry Date</label>
                    <input type="date" class="form-control" name="license_expiry_date">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Professional Status</label>
                    <select class="form-select" name="professional_status">
                        <option value="">Select Status</option>
                        <option value="active">Active</option>
                        <option value="pending">Pending</option>
                        <option value="suspended">Suspended</option>
                        <option value="expired">Expired</option>
                    </select>
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Years of Experience</label>
                    <input type="number" class="form-control" name="years_experience" placeholder="Number of years" min="0">
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="section-title"><i class="fas fa-chalkboard-teacher"></i> Teaching Specific (if applicable)</div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Teaching Specialization</label>
                    <input type="text" class="form-control" name="teaching_specialization" placeholder="e.g., Mathematics, Science">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Subjects Taught</label>
                    <input type="text" class="form-control" name="subjects_taught" placeholder="e.g., Math, English, Science">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Levels Taught</label>
                    <input type="text" class="form-control" name="levels_taught" placeholder="e.g., JHS, SHS, Tertiary">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Is Class Teacher?</label>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="is_class_teacher" value="1">
                        <label class="form-check-label">Yes</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info" style="border-radius:12px;border:none;font-size:13px;">
        <i class="fas fa-info-circle me-2"></i>
        <strong>Note:</strong> Professional information helps verify staff credentials and qualifications.
    </div>
</form>