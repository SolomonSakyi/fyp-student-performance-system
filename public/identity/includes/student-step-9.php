<?php

/**
 * Step 9: Review
 * 
 * @package EduTrack
 * @subpackage Identity
 * @version 2.0
 */
?>
<div class="step-title"><i class="fas fa-check-circle me-2 text-primary"></i>Review & Confirm</div>
<p class="step-subtitle">Review all the information before completing the registration.</p>

<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle me-2"></i>
    Please carefully review all information. Changes can be made by going back to the previous steps.
</div>

<form id="stepForm" class="step-form">
    <div id="reviewContent">
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading review data...</span>
            </div>
            <p class="text-muted mt-2">Loading registration data...</p>
        </div>
    </div>
</form>

<script>
    // ================================================
    // LOAD REVIEW DATA
    // ================================================
    document.addEventListener('DOMContentLoaded', function() {
        loadReviewData();
    });

    function loadReviewData() {
        const container = document.getElementById('reviewContent');

        // Collect all data from session storage
        let allData = {};
        for (let i = 1; i <= 10; i++) {
            try {
                const stored = sessionStorage.getItem('student_registration_' + i);
                if (stored) {
                    const data = JSON.parse(stored);
                    Object.assign(allData, data);
                }
            } catch (e) {
                console.warn('Could not load step data for step ' + i, e);
            }
        }

        // Check if we have any data
        const hasData = Object.keys(allData).length > 0;

        if (!hasData) {
            container.innerHTML = `
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-inbox" style="font-size:40px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                    <p>No registration data found. Please go back and complete the previous steps.</p>
                    <a href="?step=1" class="btn btn-primary mt-2">
                        <i class="fas fa-arrow-left me-2"></i> Start Registration
                    </a>
                </div>
            `;
            return;
        }

        // Build review HTML
        let html = `
            <div class="row">
                <div class="col-md-6 col-12">
                    <div class="form-section">
                        <div class="section-title"><i class="fas fa-user"></i> Personal Information</div>
                        <div class="info-grid">
                            <div class="info-item"><span class="label">First Name</span><span class="value">${allData.first_name || 'N/A'}</span></div>
                            <div class="info-item"><span class="label">Middle Name</span><span class="value">${allData.middle_name || 'N/A'}</span></div>
                            <div class="info-item"><span class="label">Last Name</span><span class="value">${allData.last_name || 'N/A'}</span></div>
                            <div class="info-item"><span class="label">Date of Birth</span><span class="value">${allData.date_of_birth || 'N/A'}</span></div>
                            <div class="info-item"><span class="label">Gender</span><span class="value">${allData.gender || 'N/A'}</span></div>
                            <div class="info-item"><span class="label">Nationality</span><span class="value">${allData.nationality || 'N/A'}</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="form-section">
                        <div class="section-title"><i class="fas fa-phone"></i> Contact Information</div>
                        <div class="info-grid">
                            <div class="info-item"><span class="label">Primary Phone</span><span class="value">${allData.primary_phone || 'N/A'}</span></div>
                            <div class="info-item"><span class="label">Alternative Phone</span><span class="value">${allData.alternative_phone || 'N/A'}</span></div>
                            <div class="info-item"><span class="label">Email</span><span class="value">${allData.primary_email || 'N/A'}</span></div>
                            <div class="info-item"><span class="label">Address</span><span class="value">${allData.address_line1 || 'N/A'} ${allData.address_line2 || ''}</span></div>
                            <div class="info-item"><span class="label">City</span><span class="value">${allData.city || 'N/A'}</span></div>
                            <div class="info-item"><span class="label">Region</span><span class="value">${allData.region || 'N/A'}</span></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 col-12">
                    <div class="form-section">
                        <div class="section-title"><i class="fas fa-heartbeat"></i> Health Information</div>
                        <div class="info-grid">
                            <div class="info-item"><span class="label">Blood Group</span><span class="value">${allData.blood_group || 'N/A'}</span></div>
                            <div class="info-item"><span class="label">Genotype</span><span class="value">${allData.genotype || 'N/A'}</span></div>
                            <div class="info-item"><span class="label">Allergies</span><span class="value">${allData.allergies || 'None'}</span></div>
                            <div class="info-item"><span class="label">Medical Conditions</span><span class="value">${allData.medical_conditions || 'None'}</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="form-section">
                        <div class="section-title"><i class="fas fa-graduation-cap"></i> Academic Information</div>
                        <div class="info-grid">
                            <div class="info-item"><span class="label">Campus</span><span class="value">${allData.campus_id || 'N/A'}</span></div>
                            <div class="info-item"><span class="label">Academic Year</span><span class="value">${allData.academic_year || 'N/A'}</span></div>
                            <div class="info-item"><span class="label">Level</span><span class="value">${allData.level || 'N/A'}</span></div>
                            <div class="info-item"><span class="label">Student Type</span><span class="value">${allData.student_type || 'N/A'}</span></div>
                            <div class="info-item"><span class="label">Student Status</span><span class="value">${allData.student_status || 'N/A'}</span></div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Add guardians if present
        if (allData.guardian_first_name) {
            html += `
                <div class="row">
                    <div class="col-12">
                        <div class="form-section">
                            <div class="section-title"><i class="fas fa-users"></i> Guardian Information</div>
                            <div class="info-grid">
                                <div class="info-item"><span class="label">Name</span><span class="value">${allData.guardian_first_name || ''} ${allData.guardian_last_name || ''}</span></div>
                                <div class="info-item"><span class="label">Relationship</span><span class="value">${allData.guardian_relationship_type || 'N/A'}</span></div>
                                <div class="info-item"><span class="label">Phone</span><span class="value">${allData.guardian_phone || 'N/A'}</span></div>
                                <div class="info-item"><span class="label">Email</span><span class="value">${allData.guardian_email || 'N/A'}</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        // Add admission details if present
        if (allData.admission_number) {
            html += `
                <div class="row">
                    <div class="col-12">
                        <div class="form-section">
                            <div class="section-title"><i class="fas fa-clipboard-list"></i> Admission Information</div>
                            <div class="info-grid">
                                <div class="info-item"><span class="label">Admission Number</span><span class="value">${allData.admission_number || 'N/A'}</span></div>
                                <div class="info-item"><span class="label">Admission Date</span><span class="value">${allData.admission_date || 'N/A'}</span></div>
                                <div class="info-item"><span class="label">Admission Type</span><span class="value">${allData.admission_type || 'N/A'}</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        container.innerHTML = html;
    }
</script>

<style>
    .info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px 16px;
    }

    .info-item {
        display: flex;
        flex-direction: column;
        padding: 4px 0;
        border-bottom: 1px solid #f8f9fa;
    }

    .info-item .label {
        font-size: 11px;
        color: #6c757d;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .info-item .value {
        font-size: 14px;
        color: #1a1a2e;
        font-weight: 500;
    }

    @media (max-width: 768px) {
        .info-grid {
            grid-template-columns: 1fr;
        }
    }
</style>