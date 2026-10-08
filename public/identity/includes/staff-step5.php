<?php

/**
 * Staff Registration - Step 5: Contact & Address
 * 
 * @package EduTrack
 * @subpackage Identity\Includes
 * @filepath public/identity/includes/staff-step5.php
 */
?>
<form id="stepForm" class="step-form">
    <input type="hidden" name="step" value="5">

    <div class="form-section">
        <div class="section-title"><i class="fas fa-phone"></i> Contact Information</div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Primary Mobile <span class="required">*</span></label>
                    <input type="tel" class="form-control" name="primary_mobile" placeholder="+233 XX XXX XXXX" required>
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Alternative Mobile</label>
                    <input type="tel" class="form-control" name="alt_mobile" placeholder="+233 XX XXX XXXX">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">Work Phone</label>
                    <input type="tel" class="form-control" name="work_phone" placeholder="Work phone number">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">Personal Email</label>
                    <input type="email" class="form-control" name="personal_email" placeholder="personal@example.com">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">Work Email</label>
                    <input type="email" class="form-control" name="work_email" placeholder="staff@school.edu">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">WhatsApp</label>
                    <input type="tel" class="form-control" name="whatsapp" placeholder="WhatsApp number">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Other Contact</label>
                    <input type="text" class="form-control" name="other_contact" placeholder="Other contact method">
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="section-title"><i class="fas fa-map-pin"></i> Address Information</div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Address Type</label>
                    <select class="form-select" name="address_type">
                        <option value="residential">Residential</option>
                        <option value="postal">Postal</option>
                        <option value="temporary">Temporary</option>
                    </select>
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Is Primary Address?</label>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="is_primary_address" value="1" checked>
                        <label class="form-check-label">Yes</label>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <div class="mb-3">
                    <label class="form-label">Address Line 1 <span class="required">*</span></label>
                    <input type="text" class="form-control" name="address_line_1" placeholder="Street address" required>
                </div>
            </div>
            <div class="col-12">
                <div class="mb-3">
                    <label class="form-label">Address Line 2</label>
                    <input type="text" class="form-control" name="address_line_2" placeholder="Apartment, suite">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">City/Town <span class="required">*</span></label>
                    <input type="text" class="form-control" name="city" placeholder="City or town" required>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">District</label>
                    <input type="text" class="form-control" name="district" placeholder="District">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">Region/State</label>
                    <input type="text" class="form-control" name="region" placeholder="Region or state">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">Country</label>
                    <select class="form-select" name="country">
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
                    <label class="form-label">Postal Code</label>
                    <input type="text" class="form-control" name="postal_code" placeholder="Postal code">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-3">
                    <label class="form-label">Digital Address</label>
                    <input type="text" class="form-control" name="digital_address" placeholder="e.g., GA-123-4567">
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="section-title"><i class="fas fa-exclamation-triangle"></i> Emergency Contact</div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Emergency Contact Name <span class="required">*</span></label>
                    <input type="text" class="form-control" name="emergency_contact_name" placeholder="Full name" required>
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Relationship</label>
                    <input type="text" class="form-control" name="emergency_relationship" placeholder="e.g., Spouse, Parent">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Emergency Phone <span class="required">*</span></label>
                    <input type="tel" class="form-control" name="emergency_phone" placeholder="+233 XX XXX XXXX" required>
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Alternative Emergency Phone</label>
                    <input type="tel" class="form-control" name="emergency_alt_phone" placeholder="Alternative number">
                </div>
            </div>
        </div>
    </div>
</form>