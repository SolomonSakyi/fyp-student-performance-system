<?php

/**
 * Step 4: Contact & Address Information
 * 
 * @package EduTrack
 * @subpackage Identity
 * @version 2.0
 */
?>
<div class="step-title"><i class="fas fa-address-book me-2 text-primary"></i>Contact & Address</div>
<p class="step-subtitle">Provide the student's contact information and residential address.</p>

<form id="stepForm" class="step-form">
    <!-- Contact Information -->
    <div class="form-section">
        <div class="section-title"><i class="fas fa-phone"></i> Contact Information</div>
        <div class="row">
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Primary Phone <span class="required">*</span></label>
                    <input type="tel" class="form-control" name="primary_phone" required placeholder="+233 XX XXX XXXX">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Alternative Phone</label>
                    <input type="tel" class="form-control" name="alternative_phone" placeholder="+233 XX XXX XXXX">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Email <span class="required">*</span></label>
                    <input type="email" class="form-control" name="primary_email" required placeholder="student@example.com">
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">WhatsApp</label>
                    <input type="tel" class="form-control" name="whatsapp" placeholder="+233 XX XXX XXXX">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Digital Address</label>
                    <input type="text" class="form-control" name="digital_address" placeholder="e.g., GA-123-4567">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Contact Type</label>
                    <select class="form-select" name="contact_type">
                        <option value="mobile">Mobile</option>
                        <option value="home">Home</option>
                        <option value="work">Work</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Address Information -->
    <div class="form-section">
        <div class="section-title"><i class="fas fa-map-marker-alt"></i> Address Information</div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">Address Line 1</label>
                    <input type="text" class="form-control" name="address_line1" placeholder="Street address">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">Address Line 2</label>
                    <input type="text" class="form-control" name="address_line2" placeholder="Apartment, suite, etc.">
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">City/Town</label>
                    <input type="text" class="form-control" name="city" placeholder="City or town">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">District</label>
                    <input type="text" class="form-control" name="district" placeholder="District">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Region/State</label>
                    <input type="text" class="form-control" name="region" placeholder="Region or state">
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Country</label>
                    <select class="form-select" name="country">
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
                <div class="mb-2">
                    <label class="form-label">Postal Code</label>
                    <input type="text" class="form-control" name="postal_code" placeholder="Postal code">
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Address Type</label>
                    <select class="form-select" name="address_type">
                        <option value="residential">Residential</option>
                        <option value="postal">Postal</option>
                        <option value="temporary">Temporary</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="mt-2">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_primary_address" value="1" checked>
                <label class="form-check-label">This is the primary address</label>
            </div>
        </div>
    </div>
</form>