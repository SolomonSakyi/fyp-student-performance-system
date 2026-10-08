<?php

/**
 * Staff Registration - Step 8: Identity Documents
 * 
 * @package EduTrack
 * @subpackage Identity\Includes
 * @filepath public/identity/includes/staff-step8.php
 */
?>
<form id="stepForm" class="step-form">
    <input type="hidden" name="step" value="8">

    <div class="form-section">
        <div class="section-title"><i class="fas fa-plus"></i> Add Identity Document</div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Document Type <span class="required">*</span></label>
                    <select class="form-select" id="documentType">
                        <option value="">Select Document Type</option>
                        <option value="ghana_card">Ghana Card</option>
                        <option value="passport">Passport</option>
                        <option value="birth_certificate">Birth Certificate</option>
                        <option value="professional_id">Professional ID</option>
                        <option value="professional_license">Professional License</option>
                        <option value="employment_contract">Employment Contract</option>
                        <option value="qualification_certificate">Qualification Certificate</option>
                        <option value="police_clearance">Police Clearance</option>
                        <option value="medical_clearance">Medical Clearance</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Document Number <span class="required">*</span></label>
                    <input type="text" class="form-control" id="documentNumber" placeholder="Enter document number">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-3">
                    <label class="form-label">Issuing Authority</label>
                    <input type="text" class="form-control" id="issuingAuthority" placeholder="e.g., National Identification Authority">
                </div>
            </div>
            <div class="col-md-3 col-12">
                <div class="mb-3">
                    <label class="form-label">Issue Date</label>
                    <input type="date" class="form-control" id="issueDate">
                </div>
            </div>
            <div class="col-md-3 col-12">
                <div class="mb-3">
                    <label class="form-label">Expiry Date</label>
                    <input type="date" class="form-control" id="expiryDate">
                </div>
            </div>
        </div>

        <div class="mt-3">
            <button type="button" class="btn btn-primary" id="addDocumentBtn">
                <i class="fas fa-plus me-1"></i> Add Document
            </button>
        </div>
    </div>

    <div class="form-section">
        <div class="section-title"><i class="fas fa-id-card"></i> Documents <span class="badge bg-primary" id="documentCount">0</span></div>
        <div id="documentList">
            <div class="text-muted text-center py-3" style="font-size:13px;" id="noDocumentMessage">
                No documents added yet.
            </div>
        </div>
    </div>

    <div class="alert alert-info" style="border-radius:12px;border:none;font-size:13px;">
        <i class="fas fa-info-circle me-2"></i>
        <strong>Note:</strong> Document verification may be required. Uploaded documents will be stored securely.
    </div>
</form>