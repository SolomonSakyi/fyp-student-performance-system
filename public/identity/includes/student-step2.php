<?php

/**
 * Step 2: Student Photo
 * 
 * @package EduTrack
 * @subpackage Identity
 * @version 2.0
 */
?>
<div class="step-title"><i class="fas fa-camera me-2 text-primary"></i>Student Photo</div>
<p class="step-subtitle">Upload a passport-style photo of the student.</p>

<form id="stepForm" class="step-form">
    <div class="text-center mb-4">
        <div class="photo-upload-container justify-content-center">
            <div class="photo-preview" id="photoPreview">
                <i class="fas fa-user-circle"></i>
            </div>
        </div>
        <div class="mt-3">
            <label class="form-label">Upload Photo</label>
            <input type="file" class="form-control" id="photoInput" name="photo" accept="image/*" style="max-width:300px;margin:0 auto;">
            <div class="form-text mt-2">
                <i class="fas fa-info-circle me-1"></i>
                Supported formats: JPEG, PNG, WebP | Max size: 2MB
            </div>
        </div>
        <div class="mt-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('photoInput').value='';document.getElementById('photoPreview').innerHTML='<i class=\'fas fa-user-circle\'></i>';">
                <i class="fas fa-times me-1"></i> Remove Photo
            </button>
        </div>
    </div>
</form>