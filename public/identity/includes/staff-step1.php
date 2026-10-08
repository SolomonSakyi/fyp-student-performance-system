<?php

/**
 * Staff Registration - Step 1: Staff Identity
 * 
 * @package EduTrack
 * @subpackage Identity\Includes
 * @filepath public/identity/includes/staff-step1.php
 */
?>
<form id="stepForm" class="step-form">
    <input type="hidden" name="step" value="1">

    <div class="form-section">
        <div class="section-title"><i class="fas fa-user"></i> Personal Information</div>
        <div class="row">
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">First Name <span class="required">*</span></label>
                    <input type="text" class="form-control" name="first_name" placeholder="Enter first name" required>
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
                    <input type="text" class="form-control" name="last_name" placeholder="Enter last name" required>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">Preferred Name</label>
                    <input type="text" class="form-control" name="preferred_name" placeholder="Enter preferred name">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">Previous Name</label>
                    <input type="text" class="form-control" name="previous_name" placeholder="Enter previous name if applicable">
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="section-title"><i class="fas fa-calendar-alt"></i> Date of Birth & Gender</div>
        <div class="row">
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">Date of Birth <span class="required">*</span></label>
                    <input type="date" class="form-control" name="date_of_birth" required>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">Place of Birth</label>
                    <input type="text" class="form-control" name="place_of_birth" placeholder="City/Town of birth">
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
    </div>

    <div class="form-section">
        <div class="section-title"><i class="fas fa-globe"></i> Nationality & Origin</div>
        <div class="row">
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">Nationality <span class="required">*</span></label>
                    <select class="form-select" name="nationality" required>
                        <option value="">Select Nationality</option>
                        <option value="Ghanaian">Ghanaian</option>
                        <option value="Nigerian">Nigerian</option>
                        <option value="Kenyan">Kenyan</option>
                        <option value="South African">South African</option>
                        <option value="Other">Other</option>
                    </select>
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
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">Region/State of Birth</label>
                    <input type="text" class="form-control" name="region_of_birth" placeholder="Region or State">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">Preferred Language</label>
                    <select class="form-select" name="preferred_language">
                        <option value="en">English</option>
                        <option value="tw">Twi</option>
                        <option value="ga">Ga</option>
                        <option value="ewe">Ewe</option>
                        <option value="hausa">Hausa</option>
                        <option value="fr">French</option>
                    </select>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">Marital Status</label>
                    <select class="form-select" name="marital_status">
                        <option value="">Select Status</option>
                        <option value="single">Single</option>
                        <option value="married">Married</option>
                        <option value="divorced">Divorced</option>
                        <option value="widowed">Widowed</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof registrationData !== 'undefined' && registrationData.person) {
            const form = document.getElementById('stepForm');
            const data = registrationData.person;
            for (const [key, value] of Object.entries(data)) {
                const field = form.querySelector(`[name="${key}"]`);
                if (field) {
                    field.value = value;
                }
            }
        }
    });
</script>