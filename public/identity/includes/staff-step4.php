<?php

/**
 * Staff Registration - Step 4: Employment Information
 * 
 * @package EduTrack
 * @subpackage Identity\Includes
 * @filepath public/identity/includes/staff-step4.php
 */
?>
<form id="stepForm" class="step-form">
    <input type="hidden" name="step" value="4">

    <div class="form-section">
        <div class="section-title"><i class="fas fa-briefcase"></i> Employment Details</div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Employment Status <span class="required">*</span></label>
                    <select class="form-select" name="employment_status" required>
                        <option value="">Select Status</option>
                        <option value="permanent">Permanent</option>
                        <option value="temporary">Temporary</option>
                        <option value="contract">Contract</option>
                        <option value="part_time">Part-Time</option>
                        <option value="casual">Casual</option>
                        <option value="intern">Intern</option>
                        <option value="volunteer">Volunteer</option>
                        <option value="consultant">Consultant</option>
                    </select>
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Employment Type</label>
                    <select class="form-select" name="employment_type">
                        <option value="">Select Type</option>
                        <option value="full_time">Full Time</option>
                        <option value="part_time">Part Time</option>
                        <option value="contract">Contract</option>
                        <option value="casual">Casual</option>
                    </select>
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Date Hired <span class="required">*</span></label>
                    <input type="date" class="form-control" name="date_hired" required>
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Date Started</label>
                    <input type="date" class="form-control" name="date_started">
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="section-title"><i class="fas fa-clock"></i> Probation & Confirmation</div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Probation Start</label>
                    <input type="date" class="form-control" name="probation_start">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Probation End</label>
                    <input type="date" class="form-control" name="probation_end">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Confirmation Date</label>
                    <input type="date" class="form-control" name="confirmation_date">
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="section-title"><i class="fas fa-file-contract"></i> Contract Details</div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Contract Start</label>
                    <input type="date" class="form-control" name="contract_start">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Contract End</label>
                    <input type="date" class="form-control" name="contract_end">
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="section-title"><i class="fas fa-user-tie"></i> Supervisor</div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Supervisor</label>
                    <input type="text" class="form-control" name="supervisor" placeholder="Supervisor's name">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Reporting Manager</label>
                    <input type="text" class="form-control" name="reporting_manager" placeholder="Reporting manager's name">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Job Grade</label>
                    <input type="text" class="form-control" name="job_grade" placeholder="e.g., Grade 1, Senior">
                </div>
            </div>
        </div>
    </div>
</form>