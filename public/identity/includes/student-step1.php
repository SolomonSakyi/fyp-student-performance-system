<?php

/**
 * Step 1: Student Identity
 * 
 * @package EduTrack
 * @subpackage Identity
 * @version 2.0
 */
?>
<div class="step-title"><i class="fas fa-user me-2 text-primary"></i>Student Identity</div>
<p class="step-subtitle">Enter the student's basic personal information.</p>

<form id="stepForm" class="step-form">
    <div class="row">
        <div class="col-md-4 col-12">
            <div class="mb-3">
                <label class="form-label">First Name <span class="required">*</span></label>
                <input type="text" class="form-control" name="first_name" required placeholder="Enter first name">
            </div>
        </div>
        <div class="col-md-4 col-12">
            <div class="mb-3">
                <label class="form-label">Middle Name</label>
                <input type="text" class="form-control" name="middle_name" placeholder="Enter middle name">
            </div>
        </div>
        <div class="col-md-4 col-12">
            <div class="mb-3">
                <label class="form-label">Last Name <span class="required">*</span></label>
                <input type="text" class="form-control" name="last_name" required placeholder="Enter last name">
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4 col-12">
            <div class="mb-3">
                <label class="form-label">Preferred Name</label>
                <input type="text" class="form-control" name="preferred_name" placeholder="Enter preferred name">
                <div class="form-text">What the student prefers to be called</div>
            </div>
        </div>
        <div class="col-md-4 col-12">
            <div class="mb-3">
                <label class="form-label">Date of Birth <span class="required">*</span></label>
                <input type="date" class="form-control" name="date_of_birth" required>
            </div>
        </div>
        <div class="col-md-4 col-12">
            <div class="mb-3">
                <label class="form-label">Gender <span class="required">*</span></label>
                <select class="form-select" name="gender" required>
                    <option value="">Select Gender</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="other">Other</option>
                    <option value="prefer_not_to_say">Prefer not to say</option>
                </select>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4 col-12">
            <div class="mb-3">
                <label class="form-label">Nationality</label>
                <input type="text" class="form-control" name="nationality" placeholder="Enter nationality">
            </div>
        </div>
        <div class="col-md-4 col-12">
            <div class="mb-3">
                <label class="form-label">Place of Birth</label>
                <input type="text" class="form-control" name="place_of_birth" placeholder="Enter place of birth">
            </div>
        </div>
        <div class="col-md-4 col-12">
            <div class="mb-3">
                <label class="form-label">Preferred Language</label>
                <input type="text" class="form-control" name="preferred_language" placeholder="Enter preferred language">
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4 col-12">
            <div class="mb-3">
                <label class="form-label">Previous Name</label>
                <input type="text" class="form-control" name="previous_name" placeholder="Enter previous name if applicable">
                <div class="form-text">If the student has previously used a different name</div>
            </div>
        </div>
        <div class="col-md-4 col-12">
            <div class="mb-3">
                <label class="form-label">Country of Birth</label>
                <select class="form-select" name="country_of_birth">
                    <option value="">Select Country</option>
                    <option value="Ghana">Ghana</option>
                    <option value="Nigeria">Nigeria</option>
                    <option value="Kenya">Kenya</option>
                    <option value="South Africa">South Africa</option>
                    <option value="United Kingdom">United Kingdom</option>
                    <option value="United States">United States</option>
                    <option value="Other">Other</option>
                </select>
            </div>
        </div>
        <div class="col-md-4 col-12">
            <div class="mb-3">
                <label class="form-label">Region/State of Birth</label>
                <input type="text" class="form-control" name="region_of_birth" placeholder="Enter region or state">
            </div>
        </div>
    </div>
</form>