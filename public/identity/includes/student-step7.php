<?php

/**
 * Step 7: Academic Assignment
 * 
 * @package EduTrack
 * @subpackage Identity
 * @version 2.0
 */
?>
<div class="step-title"><i class="fas fa-graduation-cap me-2 text-primary"></i>Academic Assignment</div>
<p class="step-subtitle">Assign the student to the appropriate academic context.</p>

<form id="stepForm" class="step-form">
    <div class="form-section">
        <div class="section-title"><i class="fas fa-school"></i> School & Campus</div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">School <span class="required">*</span></label>
                    <select class="form-select" name="school_id" required>
                        <option value="<?php echo $schoolId; ?>" selected><?php echo htmlspecialchars($schoolName); ?></option>
                    </select>
                    <div class="form-text">Student will be registered under this school</div>
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">Campus <span class="required">*</span></label>
                    <select class="form-select" name="campus_id" required id="campusSelect">
                        <option value="">Select Campus</option>
                        <!-- Populated via JavaScript -->
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="section-title"><i class="fas fa-book"></i> Academic Context</div>
        <div class="row">
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Academic Year <span class="required">*</span></label>
                    <select class="form-select" name="academic_year" required id="academicYearSelect">
                        <option value="">Select Academic Year</option>
                        <!-- Populated via JavaScript -->
                    </select>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Academic Term <span class="required">*</span></label>
                    <select class="form-select" name="academic_term" required id="academicTermSelect">
                        <option value="">Select Academic Term</option>
                        <!-- Populated via JavaScript -->
                    </select>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="mb-2">
                    <label class="form-label">Level/Grade <span class="required">*</span></label>
                    <select class="form-select" name="level" required id="levelSelect">
                        <option value="">Select Level</option>
                        <!-- Populated via JavaScript -->
                    </select>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">Class</label>
                    <input type="text" class="form-control" name="class_name" placeholder="e.g., JHS 1A">
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">Programme / Curriculum</label>
                    <select class="form-select" name="programme" id="programmeSelect">
                        <option value="">Select Programme</option>
                        <!-- Populated via JavaScript -->
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="section-title"><i class="fas fa-user-tag"></i> Student Classification</div>
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">Student Type <span class="required">*</span></label>
                    <select class="form-select" name="student_type" required>
                        <option value="">Select Type</option>
                        <option value="full_time">Full-time</option>
                        <option value="part_time">Part-time</option>
                        <option value="distance">Distance Learning</option>
                        <option value="exchange">Exchange</option>
                        <option value="special">Special</option>
                    </select>
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="mb-2">
                    <label class="form-label">Student Status <span class="required">*</span></label>
                    <select class="form-select" name="student_status" required>
                        <option value="">Select Status</option>
                        <option value="applicant">Applicant</option>
                        <option value="pending">Pending</option>
                        <option value="active">Active</option>
                        <option value="transferred">Transferred</option>
                        <option value="withdrawn">Withdrawn</option>
                        <option value="suspended">Suspended</option>
                        <option value="graduated">Graduated</option>
                        <option value="completed">Completed</option>
                        <option value="archived">Archived</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
    // ================================================
    // LOAD DROPDOWNS
    // ================================================
    document.addEventListener('DOMContentLoaded', function() {
        loadCampuses();
        loadAcademicYears();
        loadAcademicTerms();
        loadLevels();
        loadProgrammes();
    });

    function loadCampuses() {
        const select = document.getElementById('campusSelect');
        if (!select) return;

        fetch(API_BASE + '/index.php?endpoint=campuses&action=list&school_id=' + SCHOOL_ID, {
                headers: getHeaders()
            })
            .then(response => response.json())
            .then(result => {
                if (result.success && result.data) {
                    result.data.forEach(campus => {
                        const option = document.createElement('option');
                        option.value = campus.id;
                        option.textContent = campus.campus_name;
                        select.appendChild(option);
                    });
                }
            })
            .catch(error => console.error('Error loading campuses:', error));
    }

    function loadAcademicYears() {
        const select = document.getElementById('academicYearSelect');
        if (!select) return;

        fetch(API_BASE + '/index.php?endpoint=academic&action=years', {
                headers: getHeaders()
            })
            .then(response => response.json())
            .then(result => {
                if (result.success && result.data) {
                    result.data.forEach(year => {
                        const option = document.createElement('option');
                        option.value = year.id;
                        option.textContent = year.name || year.year;
                        select.appendChild(option);
                    });
                }
            })
            .catch(error => console.error('Error loading academic years:', error));
    }

    function loadAcademicTerms() {
        const select = document.getElementById('academicTermSelect');
        if (!select) return;

        fetch(API_BASE + '/index.php?endpoint=academic&action=terms', {
                headers: getHeaders()
            })
            .then(response => response.json())
            .then(result => {
                if (result.success && result.data) {
                    result.data.forEach(term => {
                        const option = document.createElement('option');
                        option.value = term.id;
                        option.textContent = term.name || term.term_name;
                        select.appendChild(option);
                    });
                }
            })
            .catch(error => console.error('Error loading academic terms:', error));
    }

    function loadLevels() {
        const select = document.getElementById('levelSelect');
        if (!select) return;

        fetch(API_BASE + '/index.php?endpoint=levels&action=list', {
                headers: getHeaders()
            })
            .then(response => response.json())
            .then(result => {
                if (result.success && result.data) {
                    result.data.forEach(level => {
                        const option = document.createElement('option');
                        option.value = level.id;
                        option.textContent = level.name || level.level_name;
                        select.appendChild(option);
                    });
                }
            })
            .catch(error => console.error('Error loading levels:', error));
    }

    function loadProgrammes() {
        const select = document.getElementById('programmeSelect');
        if (!select) return;

        fetch(API_BASE + '/index.php?endpoint=programmes&action=list', {
                headers: getHeaders()
            })
            .then(response => response.json())
            .then(result => {
                if (result.success && result.data) {
                    result.data.forEach(programme => {
                        const option = document.createElement('option');
                        option.value = programme.id;
                        option.textContent = programme.name || programme.programme_name;
                        select.appendChild(option);
                    });
                }
            })
            .catch(error => console.error('Error loading programmes:', error));
    }
</script>