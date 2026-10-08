<!-- Overview Section -->
<div class="overview-section">
    <!-- Quick Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon blue"><i class="fas fa-layer-group"></i></div>
                    <div>
                        <div class="stat-number" id="statTotalProfiles">0</div>
                        <div class="stat-label">Total Profiles</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                    <div>
                        <div class="stat-number" id="statActiveProfiles">0</div>
                        <div class="stat-label">Active Profiles</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon orange"><i class="fas fa-pen"></i></div>
                    <div>
                        <div class="stat-number" id="statDraftProfiles">0</div>
                        <div class="stat-label">Draft Profiles</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon purple"><i class="fas fa-lock"></i></div>
                    <div>
                        <div class="stat-number" id="statLockedProfiles">0</div>
                        <div class="stat-label">Locked Profiles</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="card-custom mb-4">
        <div class="card-header-custom">
            <h6><i class="fas fa-bolt me-2 text-warning"></i>Quick Actions</h6>
        </div>
        <div class="card-body-custom">
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary btn-sm" onclick="location.href='?tab=assessment&school_id=<?php echo $schoolId; ?>&section=profiles'">
                    <i class="fas fa-layer-group me-1"></i> Manage Profiles
                </button>
                <button class="btn btn-outline-primary btn-sm" onclick="location.href='?tab=assessment&school_id=<?php echo $schoolId; ?>&section=grading'">
                    <i class="fas fa-star me-1"></i> Grading Systems
                </button>
                <button class="btn btn-outline-primary btn-sm" onclick="location.href='?tab=assessment&school_id=<?php echo $schoolId; ?>&section=aggregation'">
                    <i class="fas fa-calculator me-1"></i> Aggregation Rules
                </button>
                <button class="btn btn-outline-primary btn-sm" onclick="location.href='?tab=assessment&school_id=<?php echo $schoolId; ?>&section=subjects'">
                    <i class="fas fa-book me-1"></i> Subject Rules
                </button>
                <button class="btn btn-outline-secondary btn-sm" onclick="showAlert('Preview calculation coming soon!', 'info')">
                    <i class="fas fa-eye me-1"></i> Preview
                </button>
            </div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="card-custom">
        <div class="card-header-custom">
            <h6><i class="fas fa-clock me-2 text-muted"></i>Recent Activity</h6>
            <button class="btn btn-outline-secondary btn-sm" onclick="loadRecentActivity()">
                <i class="fas fa-sync-alt me-1"></i> Refresh
            </button>
        </div>
        <div class="card-body-custom">
            <div id="recentActivityContainer">
                <div class="text-center py-3 text-muted">
                    <i class="fas fa-spinner fa-spin me-2"></i> Loading activity...
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // ============================================================
    // LOAD OVERVIEW DATA
    // ============================================================
    function loadOverviewData() {
        loadStats();
        loadRecentActivity();
    }

    // ============================================================
    // LOAD STATS
    // ============================================================
    function loadStats() {
        // For now, use mock data
        // In production, fetch from API
        var stats = {
            total: 4,
            active: 2,
            draft: 1,
            locked: 1
        };

        document.getElementById('statTotalProfiles').textContent = stats.total;
        document.getElementById('statActiveProfiles').textContent = stats.active;
        document.getElementById('statDraftProfiles').textContent = stats.draft;
        document.getElementById('statLockedProfiles').textContent = stats.locked;

        // Update badge
        var badge = document.getElementById('totalProfilesBadge');
        if (badge) {
            badge.textContent = stats.total;
        }
    }

    // ============================================================
    // LOAD RECENT ACTIVITY
    // ============================================================
    function loadRecentActivity() {
        var container = document.getElementById('recentActivityContainer');

        // Mock data
        var activities = [{
                action: 'created',
                item: 'JHS WAEC Profile',
                user: 'Admin',
                time: '2 minutes ago'
            },
            {
                action: 'updated',
                item: 'Primary Standard Profile',
                user: 'Admin',
                time: '1 hour ago'
            },
            {
                action: 'activated',
                item: 'SHS WAEC Profile',
                user: 'Admin',
                time: '3 hours ago'
            }
        ];

        if (activities.length === 0) {
            container.innerHTML = `
                <div class="text-center py-3 text-muted">
                    <i class="fas fa-inbox me-2"></i> No recent activity
                </div>
            `;
            return;
        }

        var html = '';
        activities.forEach(function(item) {
            var icon = item.action === 'created' ? 'fa-plus-circle text-success' :
                item.action === 'updated' ? 'fa-edit text-primary' :
                item.action === 'activated' ? 'fa-check-circle text-success' :
                'fa-arrow-right text-secondary';

            html += `
                <div class="d-flex align-items-center py-2 border-bottom" style="gap:12px;">
                    <i class="fas ${icon}"></i>
                    <div style="flex:1;">
                        <div style="font-weight:500;">${item.item}</div>
                        <div style="font-size:12px;color:#6c757d;">
                            ${item.action} by ${item.user}
                        </div>
                    </div>
                    <div style="font-size:12px;color:#6c757d;">${item.time}</div>
                </div>
            `;
        });

        container.innerHTML = html;
    }

    // ============================================================
    // INITIALIZE
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        loadOverviewData();
    });
</script>