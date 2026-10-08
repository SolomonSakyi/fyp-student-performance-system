<?php
/**
 * Step 10: Registration Confirmation
 * 
 * @package EduTrack
 * @subpackage Identity
 * @version 2.0
 */
?>
<div class="step-title"><i class="fas fa-check-double me-2 text-success"></i>Registration Confirmation</div>
<p class="step-subtitle">Your student registration is ready to be completed.</p>

<form id="stepForm" class="step-form">
    <div class="text-center py-4">
        <div class="mb-4">
            <i class="fas fa-user-graduate" style="font-size:72px;color:#4facfe;opacity:0.5;"></i>
        </div>
        <h3 class="mb-3">Ready to Register Student</h3>
        <p class="text-muted" style="max-width:500px;margin:0 auto;">
            You are about to register a new student. Please verify all information is correct.
            Once confirmed, the student will be added to the system.
        </p>

        <div class="row justify-content-center mt-4">
            <div class="col-md-8">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Note:</strong> After registration, you will be redirected to the student's profile page.
                </div>
            </div>
        </div>

        <div class="mt-4">
            <div class="d-flex justify-content-center gap-3 flex-wrap">
                <a href="?step=1" class="btn btn-outline-secondary">
                    <i class="fas fa-undo me-2"></i> Start Over
                </a>
                <button type="button" class="btn btn-success btn-lg" onclick="submitRegistration()">
                    <i class="fas fa-check me-2"></i> Complete Registration
                </button>
            </div>
        </div>
    </div>
</form>
