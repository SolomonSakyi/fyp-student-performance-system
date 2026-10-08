<!-- Profiles Section -->
<div class="profiles-section">
    <div class="card-custom">
        <div class="card-header-custom">
            <h6><i class="fas fa-layer-group me-2 text-primary"></i>Assessment Profiles</h6>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
                    <i class="fas fa-sync-alt me-1"></i> Refresh
                </button>
                <button class="btn btn-primary btn-sm" onclick="showAddProfile()">
                    <i class="fas fa-plus me-1"></i> New Profile
                </button>
            </div>
        </div>
        <div class="card-body-custom">
            <!-- Stats Row -->
            <div class="row g-2 mb-3">
                <div class="col-md-3 col-6">
                    <div class="stat-card">
                        <div class="stat-number" id="totalProfiles">0</div>
                        <div class="stat-label">Total</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-card">
                        <div class="stat-number text-success" id="activeProfiles">0</div>
                        <div class="stat-label">Active</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-card">
                        <div class="stat-number text-warning" id="draftProfiles">0</div>
                        <div class="stat-label">Draft</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-card">
                        <div class="stat-number text-secondary" id="archivedProfiles">0</div>
                        <div class="stat-label">Archived</div>
                    </div>
                </div>
            </div>

            <!-- Profiles Grid -->
            <div id="profilesContainer">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2">Loading profiles...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function getMockProfiles() {
        return [{
                'profile_id': 1,
                'name': 'JHS WAEC Profile',
                'code': 'JHS-WAEC',
                'description': 'Standard JHS assessment profile based on WAEC model',
                'applicable_levels': 'basic_7,basic_8,basic_9',
                'assessment_model': '30_70',
                'status': 'active',
                'is_locked': 0,
                'is_default': 1,
                'total_weight': 100,
                'created_at': '2026-08-03 10:00:00',
                'components': [{
                        'name': 'Class Assessment',
                        'weight': 30
                    },
                    {
                        'name': 'Examination',
                        'weight': 70
                    }
                ]
            },
            {
                'profile_id': 2,
                'name': 'Primary Standard Profile',
                'code': 'PRIM-STD',
                'description': 'Standard primary school assessment profile',
                'applicable_levels': 'primary_1,primary_2,primary_3,primary_4,primary_5,primary_6',
                'assessment_model': '40_60',
                'status': 'active',
                'is_locked': 0,
                'is_default': 0,
                'total_weight': 100,
                'created_at': '2026-08-03 09:00:00',
                'components': [{
                        'name': 'Continuous Assessment',
                        'weight': 40
                    },
                    {
                        'name': 'Examination',
                        'weight': 60
                    }
                ]
            },
            {
                'profile_id': 3,
                'name': 'SHS WAEC Profile',
                'code': 'SHS-WAEC',
                'description': 'Standard SHS assessment profile based on WAEC model',
                'applicable_levels': 'shs_1,shs_2,shs_3',
                'assessment_model': '30_70',
                'status': 'draft',
                'is_locked': 0,
                'is_default': 0,
                'total_weight': 100,
                'created_at': '2026-08-03 08:00:00',
                'components': [{
                        'name': 'Class Assessment',
                        'weight': 30
                    },
                    {
                        'name': 'Examination',
                        'weight': 70
                    }
                ]
            }
        ];
    }

    function loadProfiles() {
        var container = document.getElementById('profilesContainer');
        container.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-muted mt-2">Loading profiles...</p>
            </div>
        `;

        setTimeout(function() {
            var profiles = getMockProfiles();
            renderProfiles(profiles);
        }, 500);
    }

    function renderProfiles(profiles) {
        var container = document.getElementById('profilesContainer');

        var total = 0,
            active = 0,
            draft = 0,
            archived = 0;
        profiles.forEach(function(p) {
            total++;
            if (p.status === 'active') active++;
            else if (p.status === 'draft') draft++;
            else if (p.status === 'archived') archived++;
        });
        document.getElementById('totalProfiles').textContent = total;
        document.getElementById('activeProfiles').textContent = active;
        document.getElementById('draftProfiles').textContent = draft;
        document.getElementById('archivedProfiles').textContent = archived;

        if (profiles.length === 0) {
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="fas fa-layer-group fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No assessment profiles created yet.</p>
                    <button class="btn btn-primary" onclick="showAddProfile()">
                        <i class="fas fa-plus me-2"></i> Create First Profile
                    </button>
                </div>
            `;
            return;
        }

        var html = '<div class="row g-3">';
        profiles.forEach(function(profile) {
            var statusClass = profile.status === 'active' ? 'active' :
                profile.status === 'draft' ? 'draft' : 'archived';
            var statusBadge = profile.status === 'active' ? 'success' :
                profile.status === 'draft' ? 'warning' : 'secondary';
            var profileId = profile.profile_id;

            html += `
                <div class="col-md-6 col-xl-4">
                    <div class="profile-card ${statusClass}">
                        <div class="profile-header">
                            <div class="profile-info">
                                <div class="profile-icon ${profile.code.includes('JHS') ? 'jhs' : 'primary'}">
                                    <i class="fas ${profile.code.includes('JHS') ? 'fa-graduation-cap' : 'fa-child'}"></i>
                                </div>
                                <div>
                                    <div class="profile-name">${profile.name}</div>
                                    <div class="profile-type">${profile.code}</div>
                                </div>
                            </div>
                            <span class="badge bg-${statusBadge}">${profile.status}</span>
                        </div>
                        <div class="profile-meta">
                            <span><i class="fas fa-layer-group"></i> ${profile.applicable_levels || 'All Levels'}</span>
                            <span><i class="fas fa-percentage"></i> ${profile.assessment_model || '30/70'}</span>
                            ${profile.is_locked ? '<span><i class="fas fa-lock text-secondary"></i> Locked</span>' : ''}
                        </div>
                        <div class="profile-components">
                            ${profile.components ? profile.components.map(function(c) {
                                return '<span class="component-badge">' + c.name + ' (' + c.weight + '%)</span>';
                            }).join('') : ''}
                        </div>
                        <div class="profile-actions">
                            <button class="btn btn-outline-primary btn-sm" onclick="editProfile(${profileId})">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="viewProfile(${profileId})">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button class="btn btn-outline-success btn-sm" onclick="duplicateProfile(${profileId})">
                                <i class="fas fa-copy"></i> Duplicate
                            </button>
                            ${profile.status === 'draft' ? 
                                `<button class="btn btn-outline-success btn-sm" onclick="publishProfile(${profileId})">
                                    <i class="fas fa-check"></i> Publish
                                </button>` : ''
                            }
                            ${!profile.is_locked ? 
                                `<button class="btn btn-outline-danger btn-sm" onclick="deleteProfile(${profileId})">
                                    <i class="fas fa-trash"></i>
                                </button>` : ''
                            }
                        </div>
                    </div>
                </div>
            `;
        });
        html += '</div>';

        container.innerHTML = html;
    }

    function showAddProfile() {
        document.getElementById('profileId').value = '';
        document.getElementById('profileModalTitle').textContent = 'Create Assessment Profile';
        document.getElementById('profileName').value = '';
        document.getElementById('profileCode').value = '';
        document.getElementById('profileDescription').value = '';
        document.getElementById('profileLevels').value = [];
        document.getElementById('profileModel').value = '30_70';
        document.getElementById('profileStatus').value = 'draft';
        document.getElementById('componentsContainer').innerHTML = '<div class="text-center py-2 text-muted small">No components configured yet.</div>';

        var modal = new bootstrap.Modal(document.getElementById('profileModal'));
        modal.show();
    }

    function editProfile(id) {
        var profiles = getMockProfiles();
        var profile = profiles.find(function(p) {
            return p.profile_id === id;
        });

        if (profile) {
            document.getElementById('profileId').value = profile.profile_id;
            document.getElementById('profileModalTitle').textContent = 'Edit Assessment Profile';
            document.getElementById('profileName').value = profile.name;
            document.getElementById('profileCode').value = profile.code;
            document.getElementById('profileDescription').value = profile.description || '';
            document.getElementById('profileLevels').value = profile.applicable_levels ? profile.applicable_levels.split(',') : [];
            document.getElementById('profileModel').value = profile.assessment_model || '30_70';
            document.getElementById('profileStatus').value = profile.status || 'draft';

            var componentsContainer = document.getElementById('componentsContainer');
            componentsContainer.innerHTML = '';
            if (profile.components && profile.components.length > 0) {
                profile.components.forEach(function(comp) {
                    addComponentRow(comp.name, comp.weight);
                });
            } else {
                componentsContainer.innerHTML = '<div class="text-center py-2 text-muted small">No components configured yet.</div>';
            }

            var modal = new bootstrap.Modal(document.getElementById('profileModal'));
            modal.show();
        }
    }

    function saveProfile() {
        var name = document.getElementById('profileName').value.trim();
        var code = document.getElementById('profileCode').value.trim();

        if (!name || !code) {
            showAlert('Profile name and code are required', 'danger');
            return;
        }

        showAlert('Profile saved successfully!', 'success');
        var modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
        if (modal) modal.hide();
        location.reload();
    }

    function deleteProfile(id) {
        if (!confirm('Delete this profile?')) return;
        showAlert('Profile deleted!', 'success');
        location.reload();
    }

    function publishProfile(id) {
        if (!confirm('Publish this profile?')) return;
        showAlert('Profile published!', 'success');
        location.reload();
    }

    function duplicateProfile(id) {
        var name = prompt('Enter a name for the duplicate:');
        if (!name) return;
        showAlert('Profile duplicated as: ' + name, 'success');
        location.reload();
    }

    function viewProfile(id) {
        showAlert('Viewing profile: ' + id, 'info');
    }

    document.addEventListener('DOMContentLoaded', function() {
        loadProfiles();
    });
</script>