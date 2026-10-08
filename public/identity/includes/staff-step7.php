<?php

/**
 * Staff Registration - Step 7: Qualifications
 * 
 * @package EduTrack
 * @subpackage Identity\Includes
 * @filepath public/identity/includes/staff-step7.php
 */
?>
<form id="stepForm" class="step-form">
    <input type="hidden" name="step" value="7">

    <div class="form-section">
        <div class="section-title"><i class="fas fa-graduation-cap"></i> Add Qualification</div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Qualification Type <span class="required">*</span></label>
                    <select class="form-select" name="qualification_type">
                        <option value="">Select Type</option>
                        <option value="certificate">Certificate</option>
                        <option value="diploma">Diploma</option>
                        <option value="hnd">HND</option>
                        <option value="bachelors">Bachelor's</option>
                        <option value="masters">Master's</option>
                        <option value="doctorate">Doctorate</option>
                        <option value="professional">Professional Qualification</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Award/Certificate</label>
                    <input type="text" class="form-control" name="qualification_award" placeholder="e.g., BSc Computer Science">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Institution <span class="required">*</span></label>
                    <input type="text" class="form-control" name="qualification_institution" placeholder="Institution name">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Programme</label>
                    <input type="text" class="form-control" name="qualification_programme" placeholder="Programme of study">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Field of Study</label>
                    <input type="text" class="form-control" name="qualification_field" placeholder="e.g., Computer Science">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Country</label>
                    <select class="form-select" name="qualification_country">
                        <option value="Ghana">Ghana</option>
                        <option value="Nigeria">Nigeria</option>
                        <option value="Kenya">Kenya</option>
                        <option value="South Africa">South Africa</option>
                        <option value="UK">United Kingdom</option>
                        <option value="USA">United States</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">Start Date</label>
                    <input type="date" class="form-control" name="qualification_start_date">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">Completion Date</label>
                    <input type="date" class="form-control" name="qualification_completion_date">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">Graduation Year</label>
                    <input type="number" class="form-control" name="qualification_year" placeholder="YYYY" min="1950" max="2050">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Grade/Class</label>
                    <input type="text" class="form-control" name="qualification_grade" placeholder="e.g., First Class, Distinction">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Certificate Number</label>
                    <input type="text" class="form-control" name="qualification_cert_number" placeholder="Certificate number">
                </div>
            </div>
        </div>

        <div class="mt-3">
            <button type="button" class="btn btn-primary" id="addQualificationBtn">
                <i class="fas fa-plus me-1"></i> Add Qualification
            </button>
        </div>
    </div>

    <div class="form-section">
        <div class="section-title"><i class="fas fa-list"></i> Qualifications <span class="badge bg-primary" id="qualificationCount">0</span></div>
        <div id="qualificationList">
            <div class="text-muted text-center py-3" style="font-size:13px;" id="noQualificationMessage">
                No qualifications added yet.
            </div>
        </div>
    </div>
</form>