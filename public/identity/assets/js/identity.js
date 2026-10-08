/**
 * Identity Management JavaScript
 */

// ============================================
// API Client
// ============================================

class IdentityAPI {
    constructor(baseUrl = '/api/platform/index.php') {
        this.baseUrl = baseUrl;
        this.token = localStorage.getItem('auth_token') || null;
    }

    async request(endpoint, options = {}) {
        const url = new URL(this.baseUrl, window.location.origin);
        url.searchParams.set('endpoint', 'identity');
        
        if (options.action) {
            url.searchParams.set('action', options.action);
        }
        
        if (options.id) {
            url.searchParams.set('id', options.id);
        }

        if (options.params) {
            Object.keys(options.params).forEach(key => {
                url.searchParams.set(key, options.params[key]);
            });
        }

        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        };

        if (this.token) {
            headers['Authorization'] = `Bearer ${this.token}`;
        }

        const config = {
            method: options.method || 'GET',
            headers: headers,
            credentials: 'include'
        };

        if (options.data) {
            config.body = JSON.stringify(options.data);
        }

        try {
            const response = await fetch(url.toString(), config);
            const data = await response.json();

            if (response.status === 401) {
                window.location.href = '/login';
                return null;
            }

            return data;
        } catch (error) {
            console.error('API Error:', error);
            return { success: false, message: 'Network error: ' + error.message };
        }
    }

    // People
    async getPeople(page = 1, limit = 20, filters = {}) {
        return this.request('', { action: 'list', params: { page, limit, ...filters } });
    }

    async getPerson(id) {
        return this.request('', { action: 'get', id });
    }

    async createPerson(data) {
        return this.request('', { action: 'create', method: 'POST', data });
    }

    async updatePerson(id, data) {
        return this.request('', { action: 'update', id, method: 'POST', data });
    }

    async deletePerson(id) {
        return this.request('', { action: 'delete', id, method: 'POST' });
    }

    async restorePerson(id) {
        return this.request('', { action: 'restore', id, method: 'POST' });
    }

    async getPersonStats() {
        return this.request('', { action: 'stats' });
    }

    async getPersonTypes() {
        return this.request('', { action: 'types' });
    }

    async searchPeople(search, limit = 10) {
        return this.request('', { action: 'search', params: { search, limit } });
    }

    async getPeopleByTenant(tenantId) {
        return this.request('', { action: 'by_tenant', params: { tenant_id: tenantId } });
    }

    async getPersonRoles(id) {
        return this.request('', { action: 'roles', id });
    }

    async getPersonContacts(id) {
        return this.request('', { action: 'contacts', id });
    }

    async getPersonAddress(id) {
        return this.request('', { action: 'address', id });
    }

    async updatePersonStatus(id, status) {
        return this.request('', { action: 'update_status', id, method: 'POST', data: { status } });
    }

    // Documents
    async getDocuments(page = 1, limit = 20, filters = {}) {
        return this.request('', { action: 'documents', params: { page, limit, ...filters } });
    }

    async getDocument(id) {
        return this.request('', { action: 'get_document', id });
    }

    async createDocument(data) {
        return this.request('', { action: 'create_document', method: 'POST', data });
    }

    async updateDocument(id, data) {
        return this.request('', { action: 'update_document', id, method: 'POST', data });
    }

    async deleteDocument(id) {
        return this.request('', { action: 'delete_document', id, method: 'POST' });
    }

    async verifyDocument(id) {
        return this.request('', { action: 'verify_document', id, method: 'POST' });
    }

    async rejectDocument(id, reason = '') {
        return this.request('', { action: 'reject_document', id, method: 'POST', data: { reason } });
    }

    async getDocumentStats(tenantId = null) {
        const params = {};
        if (tenantId) params.tenant_id = tenantId;
        return this.request('', { action: 'doc_stats', params });
    }

    async getDocumentTypes() {
        return this.request('', { action: 'doc_types' });
    }
}

// ============================================
// UTILITY FUNCTIONS
// ============================================

function formatDate(date) {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-GB', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function formatDateTime(date) {
    if (!date) return '-';
    return new Date(date).toLocaleString('en-GB', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function getStatusBadge(status) {
    const map = {
        'active': 'badge-active',
        'inactive': 'badge-inactive',
        'pending': 'badge-pending',
        'suspended': 'badge-suspended',
        'archived': 'badge-archived',
        'verified': 'badge-verified',
        'rejected': 'badge-rejected',
        'expired': 'badge-expired'
    };
    return `<span class="badge ${map[status] || ''}">${status || 'Unknown'}</span>`;
}

function getPersonTypeBadge(type) {
    const map = {
        'student': 'badge-student',
        'teacher': 'badge-teacher',
        'staff': 'badge-staff',
        'admin': 'badge-admin',
        'parent': 'badge-parent',
        'guardian': 'badge-guardian',
        'alumni': 'badge-alumni'
    };
    return `<span class="badge ${map[type] || ''}">${type || 'Individual'}</span>`;
}

function getInitials(firstName, lastName) {
    return (firstName?.charAt(0) || '') + (lastName?.charAt(0) || '');
}

function debounce(func, wait) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

function showAlert(message, type = 'success') {
    const container = document.getElementById('alertContainer');
    if (!container) return;
    
    const alert = document.createElement('div');
    alert.className = `alert alert-${type}`;
    alert.innerHTML = `
        <span>${message}</span>
        <button class="close" onclick="this.parentElement.remove()">&times;</button>
    `;
    
    container.appendChild(alert);
    
    setTimeout(() => {
        if (alert.parentElement) alert.remove();
    }, 5000);
}

function showLoading(show) {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.classList.toggle('active', show);
    }
}

function getQueryParam(param) {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get(param);
}

// ============================================
// EXPOSE FOR GLOBAL USE
// ============================================

window.IdentityAPI = IdentityAPI;
window.formatDate = formatDate;
window.formatDateTime = formatDateTime;
window.getStatusBadge = getStatusBadge;
window.getPersonTypeBadge = getPersonTypeBadge;
window.getInitials = getInitials;
window.debounce = debounce;
window.showAlert = showAlert;
window.showLoading = showLoading;
window.getQueryParam = getQueryParam;