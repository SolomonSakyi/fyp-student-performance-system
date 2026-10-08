/**
 * Identity Components
 * Reusable UI components for People Management
 * 
 * @package EduTrack
 * @subpackage Assets\JS
 * @version 2.0
 * @filepath public/assets/js/identity-components.js
 */

const IdentityComponents = (function() {
    'use strict';

    /**
     * Render a status badge
     */
    function renderStatusBadge(status) {
        const labels = {
            'active': 'Active',
            'inactive': 'Inactive',
            'pending': 'Pending',
            'suspended': 'Suspended',
            'archived': 'Archived',
            'graduated': 'Graduated',
            'verified': 'Verified',
            'rejected': 'Rejected',
            'expired': 'Expired'
        };

        const classes = {
            'active': 'badge-status active',
            'inactive': 'badge-status inactive',
            'pending': 'badge-status pending',
            'suspended': 'badge-status suspended',
            'archived': 'badge-status archived',
            'graduated': 'badge-status active',
            'verified': 'badge-status active',
            'rejected': 'badge-status expired',
            'expired': 'badge-status expired'
        };

        const label = labels[status] || status;
        const className = classes[status] || 'badge-status pending';
        return `<span class="${className}">${label}</span>`;
    }

    /**
     * Render a person type badge
     */
    function renderTypeBadge(type) {
        const labels = {
            'student': 'Student',
            'teacher': 'Teacher',
            'staff': 'Staff',
            'admin': 'Admin',
            'parent': 'Parent',
            'guardian': 'Guardian',
            'alumni': 'Alumni',
            'visitor': 'Visitor',
            'contractor': 'Contractor',
            'volunteer': 'Volunteer'
        };

        const classes = {
            'student': 'badge-type student',
            'teacher': 'badge-type teacher',
            'staff': 'badge-type staff',
            'admin': 'badge-type admin',
            'parent': 'badge-type parent',
            'guardian': 'badge-type guardian',
            'alumni': 'badge-type student',
            'visitor': 'badge-type staff',
            'contractor': 'badge-type staff',
            'volunteer': 'badge-type staff'
        };

        const label = labels[type] || type;
        const className = classes[type] || 'badge-type staff';
        return `<span class="${className}">${label}</span>`;
    }

    /**
     * Render a relationship badge
     */
    function renderRelationshipBadge(type) {
        const labels = {
            'parent': 'Parent',
            'guardian': 'Guardian',
            'sibling': 'Sibling',
            'emergency_contact': 'Emergency Contact',
            'spouse': 'Spouse',
            'child': 'Child',
            'other': 'Other'
        };

        const classes = {
            'parent': 'relationship-badge parent',
            'guardian': 'relationship-badge guardian',
            'sibling': 'relationship-badge sibling',
            'emergency_contact': 'relationship-badge emergency',
            'spouse': 'relationship-badge other',
            'child': 'relationship-badge other',
            'other': 'relationship-badge other'
        };

        const label = labels[type] || type;
        const className = classes[type] || 'relationship-badge other';
        return `<span class="${className}">${label}</span>`;
    }

    /**
     * Render a person avatar
     */
    function renderAvatar(person, size = 'md') {
        const sizes = {
            'sm': '36px',
            'md': '48px',
            'lg': '72px',
            'xl': '96px'
        };

        const fontSizes = {
            'sm': '14px',
            'md': '18px',
            'lg': '28px',
            'xl': '36px'
        };

        const sizePx = sizes[size] || '48px';
        const fontSize = fontSizes[size] || '18px';

        const name = getFullName(person);
        const initials = getInitials(name);
        const color = getAvatarColor(person.id || 0);

        return `<div class="person-avatar" style="width:${sizePx};height:${sizePx};border-radius:50%;background:${color};color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:${fontSize};flex-shrink:0;">${initials}</div>`;
    }

    /**
     * Get full name from person object
     */
    function getFullName(person) {
        let name = person.first_name || '';
        if (person.middle_name) name += ' ' + person.middle_name;
        if (person.last_name) name += ' ' + person.last_name;
        return name.trim() || 'Unknown';
    }

    /**
     * Get initials from name
     */
    function getInitials(name) {
        if (!name) return '?';
        const parts = name.trim().split(' ');
        if (parts.length >= 2) {
            return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
        }
        return name.charAt(0).toUpperCase();
    }

    /**
     * Get avatar color based on ID
     */
    function getAvatarColor(id) {
        const colors = [
            '#4facfe', '#43e97b', '#fa709a', '#a18cd1', '#ff6b6b',
            '#fdcb6e', '#fd79a8', '#00cec9', '#0984e3', '#6c5ce7',
            '#e17055', '#00b894', '#fdcb6e', '#e84393', '#00a8ff'
        ];
        return colors[id % colors.length];
    }

    /**
     * Format a date
     */
    function formatDate(dateStr, format = 'M d, Y') {
        if (!dateStr) return 'N/A';
        const date = new Date(dateStr);
        const options = {
            'M d, Y': { month: 'short', day: 'numeric', year: 'numeric' },
            'Y-m-d': { year: 'numeric', month: '2-digit', day: '2-digit' },
            'd/m/Y': { day: '2-digit', month: '2-digit', year: 'numeric' },
            'M d, Y h:i A': { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' }
        };
        return date.toLocaleDateString('en-US', options[format] || options['M d, Y']);
    }

    /**
     * Format a datetime
     */
    function formatDateTime(dateStr) {
        return formatDate(dateStr, 'M d, Y h:i A');
    }

    /**
     * Render a person card (compact)
     */
    function renderPersonCard(person) {
        if (!person) return '';

        const name = getFullName(person);
        const avatar = renderAvatar(person, 'md');
        const typeBadge = renderTypeBadge(person.person_type);
        const statusBadge = renderStatusBadge(person.status);

        let details = [];
        if (person.person_number) {
            details.push(`<span class="detail-item"><i class="fas fa-hashtag"></i> ${person.person_number}</span>`);
        }
        if (person.primary_phone) {
            details.push(`<span class="detail-item"><i class="fas fa-phone"></i> ${person.primary_phone}</span>`);
        }
        if (person.primary_email) {
            details.push(`<span class="detail-item"><i class="fas fa-envelope"></i> ${person.primary_email}</span>`);
        }

        const detailsHtml = details.join(' ');

        return `
            <div class="person-card d-flex align-items-center gap-3 p-3 bg-light rounded">
                ${avatar}
                <div class="flex-grow-1">
                    <div class="fw-semibold">${name}</div>
                    <div class="d-flex flex-wrap gap-2 align-items-center mt-1">
                        ${typeBadge}
                        ${statusBadge}
                        ${detailsHtml}
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render an empty state
     */
    function renderEmptyState(icon, title, message, action = null) {
        let actionHtml = '';
        if (action) {
            actionHtml = `
                <a href="${action.url || '#'}" class="btn btn-primary mt-2">
                    <i class="fas ${action.icon || 'fa-plus'} me-2"></i> ${action.label || 'Add'}
                </a>
            `;
        }

        return `
            <div class="empty-state text-center py-5">
                <div class="empty-icon"><i class="fas ${icon}"></i></div>
                <h5 class="fw-bold mt-3">${title}</h5>
                <p class="text-muted">${message}</p>
                ${actionHtml}
            </div>
        `;
    }

    /**
     * Render a loading spinner
     */
    function renderLoading(message = 'Loading...') {
        return `
            <div class="text-center py-4 text-muted">
                <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                ${message}
            </div>
        `;
    }

    /**
     * Render action buttons
     */
    function renderActionButtons(actions) {
        let html = '<div class="btn-group btn-group-sm" role="group">';
        actions.forEach(action => {
            html += `
                <a href="${action.url || '#'}" 
                   class="btn ${action.class || 'btn-outline-secondary'}" 
                   title="${action.title || ''}"
                   onclick="${action.onclick || ''}">
                    <i class="fas ${action.icon || 'fa-edit'}"></i>
                </a>
            `;
        });
        html += '</div>';
        return html;
    }

    /**
     * Render a progress bar
     */
    function renderProgressBar(percent, label = '') {
        return `
            <div class="progress" style="height:8px;border-radius:10px;background:#f0f2f5;overflow:hidden;">
                <div class="progress-bar" style="width:${percent}%;background:linear-gradient(90deg,#4facfe,#00f2fe);transition:width 0.5s;"></div>
            </div>
            ${label ? `<div class="text-muted small mt-1">${label}</div>` : ''}
        `;
    }

    /**
     * Render a toast notification
     */
    function showToast(message, type = 'info', duration = 5000) {
        const container = document.getElementById('toastContainer') || createToastContainer();
        const colors = {
            success: '#28a745',
            danger: '#dc3545',
            warning: '#f59f00',
            info: '#4facfe'
        };

        const icons = {
            success: 'fa-check-circle',
            danger: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };

        const toast = document.createElement('div');
        toast.className = `toast-custom ${type}`;
        toast.style.cssText = `
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            border-left: 4px solid ${colors[type] || '#4facfe'};
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
            animation: slideIn 0.3s ease;
            max-width: 420px;
            width: 100%;
        `;

        toast.innerHTML = `
            <div class="toast-icon" style="width:36px;height:36px;border-radius:50%;background:${colors[type] || '#4facfe'}22;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:${colors[type] || '#4facfe'};">
                <i class="fas ${icons[type] || 'fa-info-circle'}"></i>
            </div>
            <div class="toast-body" style="flex:1;font-size:14px;font-weight:500;color:#1a1a2e;">${message}</div>
            <button class="toast-close" onclick="this.parentElement.remove()" style="background:none;border:none;font-size:18px;color:#adb5bd;cursor:pointer;padding:0 4px;">&times;</button>
        `;

        container.appendChild(toast);

        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease forwards';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }

    /**
     * Create toast container if it doesn't exist
     */
    function createToastContainer() {
        const container = document.createElement('div');
        container.id = 'toastContainer';
        container.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 420px;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        `;

        // Add animation styles
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { opacity: 0; transform: translateX(30px); }
                to { opacity: 1; transform: translateX(0); }
            }
            @keyframes slideOut {
                from { opacity: 1; transform: translateX(0); }
                to { opacity: 0; transform: translateX(30px); }
            }
        `;
        document.head.appendChild(style);
        document.body.appendChild(container);

        return container;
    }

    /**
     * Person search component
     */
    function createPersonSearch(inputSelector, options = {}) {
        const {
            onSelect = null,
            placeholder = 'Search for a person...',
            minQueryLength = 2,
            limit = 10
        } = options;

        const input = document.querySelector(inputSelector);
        if (!input) return null;

        let timeout = null;
        let resultsContainer = null;

        // Create results container
        resultsContainer = document.createElement('div');
        resultsContainer.className = 'person-search-results';
        resultsContainer.style.cssText = `
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            border: 1px solid #e9ecef;
            max-height: 250px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            margin-top: 4px;
        `;

        // Position the container
        const wrapper = document.createElement('div');
        wrapper.style.position = 'relative';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);
        wrapper.appendChild(resultsContainer);

        // Input event handler
        input.addEventListener('input', function() {
            clearTimeout(timeout);
            const query = this.value.trim();

            if (query.length < minQueryLength) {
                resultsContainer.style.display = 'none';
                return;
            }

            timeout = setTimeout(() => {
                fetch(`/api/platform/index.php?endpoint=identity&action=search&search=${encodeURIComponent(query)}&limit=${limit}`, {
                    headers: {
                        'Authorization': 'Bearer ' + localStorage.getItem('token') || '',
                        'Content-Type': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success && result.data && result.data.length > 0) {
                        resultsContainer.innerHTML = result.data.map(person => `
                            <div class="person-search-item" data-id="${person.id}" style="padding:10px 14px;cursor:pointer;border-bottom:1px solid #f0f2f5;display:flex;align-items:center;gap:12px;transition:background 0.2s;">
                                <div style="width:32px;height:32px;border-radius:50%;background:${getAvatarColor(person.id)};color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:13px;flex-shrink:0;">${getInitials(getFullName(person))}</div>
                                <div class="flex-grow-1">
                                    <div class="fw-medium">${getFullName(person)}</div>
                                    <div class="text-muted small">${person.person_number || ''} ${person.person_type ? '• ' + person.person_type : ''}</div>
                                </div>
                            </div>
                        `).join('');

                        // Add click handlers
                        resultsContainer.querySelectorAll('.person-search-item').forEach(item => {
                            item.addEventListener('click', function() {
                                const id = this.dataset.id;
                                const name = this.querySelector('.fw-medium').textContent;
                                input.value = name;
                                resultsContainer.style.display = 'none';

                                if (onSelect) {
                                    onSelect(id, name);
                                }
                            });

                            item.addEventListener('mouseenter', function() {
                                this.style.background = '#f8f9fa';
                            });
                            item.addEventListener('mouseleave', function() {
                                this.style.background = 'transparent';
                            });
                        });

                        resultsContainer.style.display = 'block';
                    } else {
                        resultsContainer.innerHTML = `
                            <div class="text-center py-3 text-muted" style="font-size:13px;">
                                <i class="fas fa-search me-2"></i> No people found
                            </div>
                        `;
                        resultsContainer.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Search error:', error);
                    resultsContainer.innerHTML = `
                        <div class="text-center py-3 text-danger" style="font-size:13px;">
                            <i class="fas fa-exclamation-circle me-2"></i> Error searching
                        </div>
                    `;
                    resultsContainer.style.display = 'block';
                });
            }, 300);
        });

        // Close results on blur
        input.addEventListener('blur', function() {
            setTimeout(() => {
                resultsContainer.style.display = 'none';
            }, 200);
        });

        // Close on outside click
        document.addEventListener('click', function(event) {
            if (!wrapper.contains(event.target)) {
                resultsContainer.style.display = 'none';
            }
        });

        // Return API
        return {
            input: input,
            results: resultsContainer,
            clear: function() {
                input.value = '';
                resultsContainer.style.display = 'none';
            },
            setValue: function(value) {
                input.value = value;
            }
        };
    }

    // Public API
    return {
        renderStatusBadge: renderStatusBadge,
        renderTypeBadge: renderTypeBadge,
        renderRelationshipBadge: renderRelationshipBadge,
        renderAvatar: renderAvatar,
        getFullName: getFullName,
        getInitials: getInitials,
        getAvatarColor: getAvatarColor,
        formatDate: formatDate,
        formatDateTime: formatDateTime,
        renderPersonCard: renderPersonCard,
        renderEmptyState: renderEmptyState,
        renderLoading: renderLoading,
        renderActionButtons: renderActionButtons,
        renderProgressBar: renderProgressBar,
        showToast: showToast,
        createPersonSearch: createPersonSearch
    };

})();

// Export for use in other files
if (typeof module !== 'undefined' && module.exports) {
    module.exports = IdentityComponents;
}