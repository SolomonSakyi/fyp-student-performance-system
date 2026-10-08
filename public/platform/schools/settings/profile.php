<?php
// Load settings from database
$db = DatabaseHelper::getInstance();
$settings = $db->fetchAll(
    "SELECT setting_key, setting_value FROM school_settings WHERE school_id = ? AND setting_group = 'profile' AND deleted_at IS NULL",
    [$schoolId]
);

$profileData = [];
foreach ($settings as $row) {
    $profileData[$row['setting_key']] = $row['setting_value'];
}
?>
<div class="card-custom">
    <div class="card-header-custom">
        <h6><i class="fas fa-building me-2 text-primary"></i>School Profile</h6>
    </div>
    <div class="card-body-custom">
        <form method="POST" action="">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">School Name</label>
                        <input type="text" class="form-control" name="school_name"
                            value="<?php echo htmlspecialchars($profileData['school_name'] ?? $school['school_name'] ?? ''); ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">School Code</label>
                        <input type="text" class="form-control" name="school_code"
                            value="<?php echo htmlspecialchars($profileData['school_code'] ?? $school['school_code'] ?? ''); ?>">
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email"
                            value="<?php echo htmlspecialchars($profileData['email'] ?? $school['email'] ?? ''); ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" name="phone"
                            value="<?php echo htmlspecialchars($profileData['phone'] ?? $school['phone'] ?? ''); ?>">
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </form>
    </div>
</div>