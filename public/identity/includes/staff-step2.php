<?php

/**
 * Staff Registration - Step 2: Staff Photo
 * 
 * @package EduTrack
 * @subpackage Identity\Includes
 * @filepath public/identity/includes/staff-step2.php
 */
?>
<form id="stepForm" class="step-form">
    <input type="hidden" name="step" value="2">

    <div class="form-section">
        <div class="section-title"><i class="fas fa-camera"></i> Staff Photo</div>
        <p class="text-muted" style="font-size:13px;">Upload a professional passport-style photo. Supported: JPEG, PNG, WebP. Max: 5MB.</p>

        <div class="photo-upload-area" id="photoUploadArea">
            <div class="icon"><i class="fas fa-cloud-upload-alt"></i></div>
            <div class="title">Drag & drop a photo here</div>
            <div class="subtitle">or click to browse files</div>
            <input type="file" class="form-control" id="photoInput" accept="image/jpeg,image/png,image/webp">
        </div>

        <div class="photo-preview" id="photoPreview">
            <img id="previewImage" src="" alt="Staff Photo">
            <div class="photo-actions">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.removePhoto()">
                    <i class="fas fa-trash"></i> Remove
                </button>
                <button type="button" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('photoInput').click()">
                    <i class="fas fa-sync-alt"></i> Replace
                </button>
            </div>
        </div>
    </div>

    <div class="alert alert-info" style="border-radius:12px;border:none;font-size:13px;">
        <i class="fas fa-info-circle me-2"></i>
        <strong>Photo Guidelines:</strong> Use a clear, professional photo with a plain background. Face should be clearly visible. No hats or sunglasses.
    </div>
</form>