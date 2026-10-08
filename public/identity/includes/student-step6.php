<?php

/**
 * Step 6: Identity Documents
 * 
 * @package EduTrack
 * @subpackage Identity
 * @version 2.0
 */
?>
<div class="step-title"><i class="fas fa-id-card me-2 text-primary"></i>Identity Documents</div>
<p class="step-subtitle">Upload and manage the student's identity documents.</p>

<div class="alert alert-info mb-3">
    <i class="fas fa-info-circle me-2"></i>
    All uploaded documents are securely stored and only accessible to authorized personnel.
</div>

<form id="stepForm" class="step-form">
    <div class="form-section">
        <div class="section-title"><i class="fas fa-list"></i> Document List</div>
        <div id="documentsContainer">
            <div class="text-muted text-center py-2">
                <i class="fas fa-plus-circle me-1"></i>
                Click "Add Document" to upload identity documents.
            </div>
        </div>
        <div class="mt-2">
            <button type="button" class="btn btn-outline-primary btn-sm" id="addDocumentBtn">
                <i class="fas fa-plus me-1"></i> Add Document
            </button>
        </div>
    </div>
</form>

<script>
    // ================================================
    // ADD DOCUMENT ROW
    // ================================================
    function addDocumentRow() {
        const container = document.getElementById('documentsContainer');
        const emptyMsg = container.querySelector('.text-muted');
        if (emptyMsg) emptyMsg.remove();

        const html = `
            <div class="document-row row g-2 mb-2" style="background:#f8f9fa;padding:12px;border-radius:10px;">
                <div class="col-md-3 col-12">
                    <select class="form-select form-select-sm" name="document_type[]">
                        <option value="">Select Type</option>
                        <option value="ghana_card">Ghana Card</option>
                        <option value="birth_certificate">Birth Certificate</option>
                        <option value="passport">Passport</option>
                        <option value="nhis">NHIS / Health Insurance</option>
                        <option value="school_id">School Identification</option>
                        <option value="previous_school_record">Previous School Record</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="col-md-3 col-12">
                    <input type="text" class="form-control form-control-sm" name="document_number[]" placeholder="Document Number">
                </div>
                <div class="col-md-2 col-12">
                    <input type="date" class="form-control form-control-sm" name="document_issue_date[]" placeholder="Issue Date">
                </div>
                <div class="col-md-2 col-12">
                    <input type="date" class="form-control form-control-sm" name="document_expiry[]" placeholder="Expiry Date">
                </div>
                <div class="col-md-1 col-12">
                    <input type="file" class="form-control form-control-sm" name="document_file[]" accept=".pdf,.jpg,.png">
                </div>
                <div class="col-md-1 col-12">
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeDocumentRow(this)">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        `;
        container.insertAdjacentHTML('beforeend', html);
    }

    function removeDocumentRow(btn) {
        const row = btn.closest('.document-row');
        if (row) row.remove();

        // Show empty message if no documents
        const container = document.getElementById('documentsContainer');
        if (container.querySelectorAll('.document-row').length === 0) {
            container.innerHTML = `
                <div class="text-muted text-center py-2">
                    <i class="fas fa-plus-circle me-1"></i>
                    Click "Add Document" to upload identity documents.
                </div>
            `;
        }
    }
</script>