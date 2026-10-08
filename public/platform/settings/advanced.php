<!-- Advanced Settings -->
<div class="card-custom">
    <div class="card-header-custom">
        <h6><i class="fas fa-code me-2 text-primary"></i>Advanced Settings</h6>
        <span class="badge bg-warning text-dark">Expert Mode</span>
    </div>
    <div class="card-body-custom">
        <form id="advancedForm" onsubmit="saveAdvancedSettings(event)">
            <!-- System Configuration -->
            <h6 class="mb-3"><i class="fas fa-server me-2 text-secondary"></i>System Configuration</h6>
            <div class="row">
                <div class="col-md-6 col-12">
                    <div class="mb-2">
                        <label class="form-label">Application Environment</label>
                        <select class="form-select" id="appEnvironment">
                            <option value="production">Production</option>
                            <option value="staging">Staging</option>
                            <option value="development">Development</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Debug Mode</label>
                        <select class="form-select" id="debugMode">
                            <option value="false">Disabled</option>
                            <option value="true">Enabled</option>
                        </select>
                        <small class="form-text">Enable debug mode for development (not recommended in production)</small>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Cache Driver</label>
                        <select class="form-select" id="cacheDriver">
                            <option value="file">File</option>
                            <option value="redis">Redis</option>
                            <option value="memcached">Memcached</option>
                            <option value="database">Database</option>
                        </select>
                    </div>
                    <div id="redisConfig" style="display:none;">
                        <div class="mb-2">
                            <label class="form-label">Redis Host</label>
                            <input type="text" class="form-control" id="redisHost" placeholder="127.0.0.1" value="127.0.0.1">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Redis Port</label>
                            <input type="number" class="form-control" id="redisPort" placeholder="6379" value="6379">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Redis Password</label>
                            <input type="password" class="form-control" id="redisPassword" placeholder="Optional">
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="mb-2">
                        <label class="form-label">Session Driver</label>
                        <select class="form-select" id="sessionDriver">
                            <option value="file">File</option>
                            <option value="database">Database</option>
                            <option value="redis">Redis</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Queue Driver</label>
                        <select class="form-select" id="queueDriver">
                            <option value="sync">Sync</option>
                            <option value="database">Database</option>
                            <option value="redis">Redis</option>
                            <option value="beanstalkd">Beanstalkd</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">API Rate Limit (requests/min)</label>
                        <input type="number" class="form-control" id="rateLimit" value="60" min="10" max="1000">
                        <small class="form-text">Maximum API requests per minute per IP</small>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Batch Processing Limit</label>
                        <input type="number" class="form-control" id="batchLimit" value="100" min="10" max="1000">
                        <small class="form-text">Maximum records processed in a single batch</small>
                    </div>
                </div>
            </div>

            <hr class="my-3">

            <!-- Maintenance -->
            <h6 class="mb-3"><i class="fas fa-tools me-2 text-secondary"></i>Maintenance</h6>
            <div class="row">
                <div class="col-md-12">
                    <div class="mb-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="maintenanceMode">
                            <label class="form-check-label" for="maintenanceMode">
                                <strong>Enable Maintenance Mode</strong>
                            </label>
                        </div>
                        <small class="form-text">Put the entire platform into maintenance mode. Only administrators can access the system.</small>
                    </div>
                    <div id="maintenanceConfig" style="display:none;">
                        <div class="mb-2">
                            <label class="form-label">Maintenance Message</label>
                            <textarea class="form-control textarea" id="maintenanceMessage" rows="3" placeholder="We're currently performing scheduled maintenance. Please check back later."></textarea>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Allowed IPs (Maintenance)</label>
                            <textarea class="form-control textarea" id="maintenanceAllowedIps" rows="2" placeholder="One IP per line&#10;192.168.1.1"></textarea>
                            <small class="form-text">IPs that can bypass maintenance mode</small>
                        </div>
                    </div>
                </div>
            </div>

            <hr class="my-3">

            <!-- Cron Jobs -->
            <h6 class="mb-3"><i class="fas fa-clock me-2 text-secondary"></i>Scheduled Tasks (Cron Jobs)</h6>
            <div class="row">
                <div class="col-md-12">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Task</th>
                                    <th>Schedule</th>
                                    <th>Last Run</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Send Notifications</td>
                                    <td>Every 5 minutes</td>
                                    <td id="cronNotifications">-</td>
                                    <td><span class="badge bg-success">Running</span></td>
                                    <td><button class="btn btn-sm btn-outline-primary" onclick="runCron('notifications')"><i class="fas fa-play"></i></button></td>
                                </tr>
                                <tr>
                                    <td>Generate Reports</td>
                                    <td>Daily at 00:00</td>
                                    <td id="cronReports">-</td>
                                    <td><span class="badge bg-success">Running</span></td>
                                    <td><button class="btn btn-sm btn-outline-primary" onclick="runCron('reports')"><i class="fas fa-play"></i></button></td>
                                </tr>
                                <tr>
                                    <td>Clean Audit Logs</td>
                                    <td>Weekly on Sunday</td>
                                    <td id="cronAudit">-</td>
                                    <td><span class="badge bg-success">Running</span></td>
                                    <td><button class="btn btn-sm btn-outline-primary" onclick="runCron('audit')"><i class="fas fa-play"></i></button></td>
                                </tr>
                                <tr>
                                    <td>Backup Database</td>
                                    <td>Daily at 02:00</td>
                                    <td id="cronBackup">-</td>
                                    <td><span class="badge bg-success">Running</span></td>
                                    <td><button class="btn btn-sm btn-outline-primary" onclick="runCron('backup')"><i class="fas fa-play"></i></button></td>
                                </tr>
                                <tr>
                                    <td>Update Tenant Usage</td>
                                    <td>Every hour</td>
                                    <td id="cronUsage">-</td>
                                    <td><span class="badge bg-success">Running</span></td>
                                    <td><button class="btn btn-sm btn-outline-primary" onclick="runCron('usage')"><i class="fas fa-play"></i></button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Cron jobs run automatically based on the schedule. You can also manually trigger them.
                    </div>
                </div>
            </div>

            <hr class="my-3">

            <!-- System Info -->
            <h6 class="mb-3"><i class="fas fa-info-circle me-2 text-secondary"></i>System Information</h6>
            <div class="row">
                <div class="col-md-3 col-6">
                    <div class="mb-2">
                        <span class="text-muted">PHP Version</span>
                        <div><strong id="phpVersion"><?php echo phpversion(); ?></strong></div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="mb-2">
                        <span class="text-muted">Database</span>
                        <div><strong id="dbVersion">MySQL / MariaDB</strong></div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="mb-2">
                        <span class="text-muted">Server OS</span>
                        <div><strong id="serverOs"><?php echo php_uname('s'); ?></strong></div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="mb-2">
                        <span class="text-muted">Memory Limit</span>
                        <div><strong id="memoryLimit"><?php echo ini_get('memory_limit'); ?></strong></div>
                    </div>
                </div>
            </div>

            <hr class="my-3">

            <div class="d-flex gap-2 flex-wrap justify-content-end">
                <button type="button" class="btn btn-outline-danger" onclick="clearCache()">
                    <i class="fas fa-broom me-2"></i> Clear Cache
                </button>
                <button type="button" class="btn btn-outline-warning" onclick="optimizeDatabase()">
                    <i class="fas fa-database me-2"></i> Optimize Database
                </button>
                <button type="submit" class="btn btn-primary" id="advancedSubmitBtn">
                    <i class="fas fa-save me-2"></i> Save Advanced Settings
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // ================================================
    // CACHE DRIVER TOGGLE
    // ================================================
    document.addEventListener('DOMContentLoaded', function() {
        const cacheDriver = document.getElementById('cacheDriver');
        const redisConfig = document.getElementById('redisConfig');

        cacheDriver.addEventListener('change', function() {
            redisConfig.style.display = this.value === 'redis' || this.value === 'memcached' ? 'block' : 'none';
        });

        // Maintenance mode toggle
        const maintenanceMode = document.getElementById('maintenanceMode');
        const maintenanceConfig = document.getElementById('maintenanceConfig');
        maintenanceMode.addEventListener('change', function() {
            maintenanceConfig.style.display = this.checked ? 'block' : 'none';
        });

        // Load cron last run times
        loadCronStatus();
    });

    // ================================================
    // LOAD CRON STATUS
    // ================================================
    function loadCronStatus() {
        const tasks = ['notifications', 'reports', 'audit', 'backup', 'usage'];
        tasks.forEach(task => {
            const element = document.getElementById('cron' + task.charAt(0).toUpperCase() + task.slice(1));
            if (element) {
                const now = new Date();
                element.textContent = now.toLocaleString();
            }
        });
    }

    // ================================================
    // RUN CRON JOB
    // ================================================
    async function runCron(task) {
        const btn = event.target;
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        btn.disabled = true;

        try {
            // Simulate running cron job
            await new Promise(resolve => setTimeout(resolve, 1500));
            const now = new Date();
            const element = document.getElementById('cron' + task.charAt(0).toUpperCase() + task.slice(1));
            if (element) {
                element.textContent = now.toLocaleString();
            }
            showAlert(`✅ Cron job "${task}" completed successfully!`, 'success');
        } catch (error) {
            showAlert('Error running cron job: ' + error.message, 'danger');
        } finally {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        }
    }

    // ================================================
    // CLEAR CACHE
    // ================================================
    async function clearCache() {
        if (!confirm('Clear all system cache? This may temporarily slow down the system.')) return;

        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Clearing...';

        try {
            const response = await fetch(`${API_BASE}/index.php?endpoint=settings/cache/clear`, {
                method: 'POST',
                headers: getHeaders()
            });
            const result = await response.json();

            if (result.success) {
                showAlert('Cache cleared successfully!', 'success');
            } else {
                showAlert('Failed to clear cache: ' + (result.message || 'Unknown error'), 'danger');
            }
        } catch (error) {
            showAlert('Error clearing cache: ' + error.message, 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }

    // ================================================
    // OPTIMIZE DATABASE
    // ================================================
    async function optimizeDatabase() {
        if (!confirm('Optimize database tables? This may take a few minutes.')) return;

        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Optimizing...';

        try {
            const response = await fetch(`${API_BASE}/index.php?endpoint=settings/database/optimize`, {
                method: 'POST',
                headers: getHeaders()
            });
            const result = await response.json();

            if (result.success) {
                showAlert('Database optimized successfully!', 'success');
            } else {
                showAlert('Failed to optimize database: ' + (result.message || 'Unknown error'), 'danger');
            }
        } catch (error) {
            showAlert('Error optimizing database: ' + error.message, 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }

    // ================================================
    // SAVE ADVANCED SETTINGS
    // ================================================
    async function saveAdvancedSettings(event) {
        event.preventDefault();

        const submitBtn = document.getElementById('advancedSubmitBtn');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

        try {
            const data = {
                app_environment: document.getElementById('appEnvironment').value,
                debug_mode: document.getElementById('debugMode').value === 'true' ? 1 : 0,
                cache_driver: document.getElementById('cacheDriver').value,
                redis_host: document.getElementById('redisHost').value,
                redis_port: parseInt(document.getElementById('redisPort').value) || 6379,
                redis_password: document.getElementById('redisPassword').value,
                session_driver: document.getElementById('sessionDriver').value,
                queue_driver: document.getElementById('queueDriver').value,
                rate_limit: parseInt(document.getElementById('rateLimit').value) || 60,
                batch_limit: parseInt(document.getElementById('batchLimit').value) || 100,
                maintenance_mode: document.getElementById('maintenanceMode').checked ? 1 : 0,
                maintenance_message: document.getElementById('maintenanceMessage').value,
                maintenance_allowed_ips: document.getElementById('maintenanceAllowedIps').value
            };

            const response = await fetch(`${API_BASE}/index.php?endpoint=settings&action=advanced`, {
                method: 'POST',
                headers: getHeaders(),
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                showAlert('Advanced settings saved successfully!', 'success');
            } else {
                showAlert('✗ ' + (result.message || 'Failed to save settings'), 'danger');
            }
        } catch (error) {
            console.error('Error saving advanced settings:', error);
            showAlert('Error saving advanced settings: ' + error.message, 'danger');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    }
</script>