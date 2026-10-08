<?php

/**
 * Staff Registration - Step 3: Staff Category
 * 
 * @package EduTrack
 * @subpackage Identity\Includes
 * @filepath public/identity/includes/staff-step3.php
 */
?>
<form id="stepForm" class="step-form">
    <input type="hidden" name="step" value="3">

    <div class="form-section">
        <div class="section-title"><i class="fas fa-tag"></i> Staff Category</div>
        <p class="text-muted" style="font-size:13px;">Select the staff category. This determines the staff profile type.</p>

        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Staff Category <span class="required">*</span></label>
                    <select class="form-select" name="staff_category" required>
                        <option value="">Select Category</option>
                        <option value="teaching">Teaching Staff</option>
                        <option value="administrative">Administrative Staff</option>
                        <option value="finance">Finance / Accounts</option>
                        <option value="ict">ICT / Technical</option>
                        <option value="medical">Medical / Health</option>
                        <option value="library">Library</option>
                        <option value="counselling">Counselling</option>
                        <option value="operations">Operations</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="security">Security</option>
                        <option value="transport">Transport</option>
                        <option value="boarding">Boarding</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Department <span class="required">*</span></label>
                    <select class="form-select" name="department" required>
                        <option value="">Select Department</option>
                        <option value="academic">Academic</option>
                        <option value="administration">Administration</option>
                        <option value="finance">Finance</option>
                        <option value="ict">ICT</option>
                        <option value="hr">Human Resources</option>
                        <option value="science">Science</option>
                        <option value="mathematics">Mathematics</option>
                        <option value="languages">Languages</option>
                        <option value="operations">Operations</option>
                        <option value="health">Health</option>
                        <option value="library">Library</option>
                        <option value="security">Security</option>
                        <option value="transport">Transport</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Position <span class="required">*</span></label>
                    <select class="form-select" name="position" required>
                        <option value="">Select Position</option>
                        <option value="teacher">Teacher</option>
                        <option value="head_teacher">Head Teacher</option>
                        <option value="principal">Principal</option>
                        <option value="vice_principal">Vice Principal</option>
                        <option value="accountant">Accountant</option>
                        <option value="cashier">Cashier</option>
                        <option value="secretary">Secretary</option>
                        <option value="ict_officer">ICT Officer</option>
                        <option value="nurse">Nurse</option>
                        <option value="librarian">Librarian</option>
                        <option value="counsellor">Counsellor</option>
                        <option value="security_officer">Security Officer</option>
                        <option value="driver">Driver</option>
                        <option value="cleaner">Cleaner</option>
                        <option value="administrator">Administrator</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Job Title</label>
                    <input type="text" class="form-control" name="job_title" placeholder="Enter specific job title">
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="section-title"><i class="fas fa-building"></i> Work Location</div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">School <span class="required">*</span></label>
                    <select class="form-select" name="school_id" required>
                        <option value="">Select School</option>
                        <option value="1">School of Computing</option>
                        <option value="2">School of Business</option>
                        <option value="3">School of Engineering</option>
                    </select>
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Campus <span class="required">*</span></label>
                    <select class="form-select" name="campus_id" required>
                        <option value="">Select Campus</option>
                        <option value="1">Main Campus</option>
                        <option value="2">City Campus</option>
                        <option value="3">North Campus</option>
                    </select>
                </div>
            </div>
            <div class="col-12">
                <div class="mb-3">
                    <label class="form-label">Work Location</label>
                    <input type="text" class="form-control" name="work_location" placeholder="Specific work location or office">
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info" style="border-radius:12px;border:none;font-size:13px;">
        <i class="fas fa-info-circle me-2"></i>
        <strong>Note:</strong> Staff category determines what additional profile information will be collected. Teaching staff will have additional teaching-specific fields.
    </div>
</form>