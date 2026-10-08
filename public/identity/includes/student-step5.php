<?php

/**
 * Step 5: Health & Medical Information
 * 
 * @package EduTrack
 * @subpackage Identity
 * @version 2.0
 */
?>
<div class="step-title"><i class="fas fa-heartbeat me-2 text-primary"></i>Health & Medical</div>
<p class="step-subtitle">Provide the student's health and medical information. This information is confidential.</p>

<div class="alert alert-info mb-3">
    <i class="fas fa-lock me-2"></i>
    Health information is confidential and will only be accessible to authorized personnel.
</div>

<form id="stepForm" class="step-form">
    <!-- Basic Health Info -->
    <div class="form-section">
        <div class="section-title"><i class="fas fa-notes-medical"></i> Basic Health Information</div>
        <div class="row">
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Blood Group</label>
                    <select class="form-select" name="blood_group">
                        <option value="">Select Blood Group</option>
                        <option value="A+">A+</option>
                        <option value="A-">A-</option>
                        <option value="B+">B+</option>
                        <option value="B-">B-</option>
                        <option value="AB+">AB+</option>
                        <option value="AB-">AB-</option>
                        <option value="O+">O+</option>
                        <option value="O-">O-</option>
                    </select>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Genotype</label>
                    <select class="form-select" name="genotype">
                        <option value="">Select Genotype</option>
                        <option value="AA">AA</option>
                        <option value="AS">AS</option>
                        <option value="SS">SS</option>
                        <option value="AC">AC</option>
                        <option value="SC">SC</option>
                    </select>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Health Classification</label>
                    <select class="form-select" name="health_classification">
                        <option value="normal">Normal</option>
                        <option value="confidential">Confidential</option>
                        <option value="restricted">Restricted</option>
                        <option value="highly_restricted">Highly Restricted</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Allergies & Conditions -->
    <div class="form-section">
        <div class="section-title"><i class="fas fa-allergies"></i> Allergies & Conditions</div>
        <div class="mb-2">
            <label class="form-label">Allergies</label>
            <textarea class="form-control" name="allergies" rows="2" placeholder="List any allergies (e.g., peanuts, pollen, medication)"></textarea>
        </div>
        <div class="mb-2">
            <label class="form-label">Medical Conditions</label>
            <textarea class="form-control" name="medical_conditions" rows="2" placeholder="List any existing medical conditions"></textarea>
        </div>
        <div class="mb-2">
            <label class="form-label">Chronic Conditions</label>
            <textarea class="form-control" name="chronic_conditions" rows="2" placeholder="List any chronic or long-term conditions"></textarea>
        </div>
    </div>

    <!-- Medications & Alerts -->
    <div class="form-section">
        <div class="section-title"><i class="fas fa-prescription"></i> Medications & Alerts</div>
        <div class="mb-2">
            <label class="form-label">Current Medications</label>
            <textarea class="form-control" name="current_medications" rows="2" placeholder="List any current medications"></textarea>
        </div>
        <div class="mb-2">
            <label class="form-label">Medical Alerts</label>
            <textarea class="form-control" name="medical_alerts" rows="2" placeholder="Any medical alerts (e.g., epilepsy, diabetes)"></textarea>
        </div>
        <div class="mb-2">
            <label class="form-label">Disabilities / Accessibility Requirements</label>
            <textarea class="form-control" name="accessibility_requirements" rows="2" placeholder="Describe any disabilities or accessibility needs"></textarea>
        </div>
    </div>

    <!-- Insurance & Emergency -->
    <div class="form-section">
        <div class="section-title"><i class="fas fa-ambulance"></i> Insurance & Emergency</div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">Health Insurance Provider</label>
                    <input type="text" class="form-control" name="health_insurance_provider" placeholder="Insurance provider name">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">Health Insurance Number</label>
                    <input type="text" class="form-control" name="health_insurance_number" placeholder="Insurance policy number">
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">NHIS Number</label>
                    <input type="text" class="form-control" name="nhis_number" placeholder="NHIS card number">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">Primary Physician</label>
                    <input type="text" class="form-control" name="primary_physician" placeholder="Primary doctor's name">
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">Medical Facility</label>
                    <input type="text" class="form-control" name="medical_facility" placeholder="Preferred hospital/clinic">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">Dietary Restrictions</label>
                    <input type="text" class="form-control" name="dietary_restrictions" placeholder="Any dietary restrictions">
                </div>
            </div>
        </div>
        <div class="mb-2">
            <label class="form-label">Emergency Medical Notes</label>
            <textarea class="form-control" name="emergency_medical_notes" rows="2" placeholder="Additional emergency medical information"></textarea>
        </div>
    </div>
</form>