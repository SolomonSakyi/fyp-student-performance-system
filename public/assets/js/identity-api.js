/**
 * Identity API Helper
 * Consistent API calls for the People Management module
 * 
 * @package EduTrack
 * @subpackage Assets\JS
 * @version 2.0
 * @filepath public/assets/js/identity-api.js
 */

const IdentityAPI = (function() {
    'use strict';

    // API Base URL
    const API_BASE = '/api/platform/index.php';
    const TOKEN = localStorage.getItem('token') || '';

    /**
     * Get headers for API requests
     */
    function getHeaders() {
        return {
            'Authorization': 'Bearer ' + TOKEN,
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };
    }

    /**
     * Make an API request
     */
    function request(params, method = 'GET', data = null) {
        return new Promise((resolve, reject) => {
            let url = API_BASE + '?' + new URLSearchParams(params).toString();

            const options = {
                method: method,
                headers: getHeaders()
            };

            if (data && (method === 'POST' || method === 'PUT' || method === 'PATCH')) {
                options.body = JSON.stringify(data);
            }

            fetch(url, options)
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        resolve(result);
                    } else {
                        reject(result.message || 'An error occurred');
                    }
                })
                .catch(error => {
                    reject(error.message || 'Network error occurred');
                });
        });
    }

    /**
     * People API Methods
     */
    const People = {
        /**
         * List people with pagination and filters
         */
        list: function(filters = {}, page = 1, limit = 20) {
            const params = {
                endpoint: 'identity',
                action: 'list',
                page: page,
                limit: limit
            };

            if (filters.search) params.search = filters.search;
            if (filters.status) params.status = filters.status;
            if (filters.type) params.type = filters.type;
            if (filters.school_id) params.school_id = filters.school_id;
            if (filters.campus_id) params.campus_id = filters.campus_id;
            if (filters.tenant_id) params.tenant_id = filters.tenant_id;
            if (filters.role) params.role = filters.role;

            return request(params);
        },

        /**
         * Get a single person by ID
         */
        get: function(id) {
            return request({
                endpoint: 'identity',
                action: 'get',
                id: id
            });
        },

        /**
         * Create a new person
         */
        create: function(data) {
            return request({
                endpoint: 'identity',
                action: 'create'
            }, 'POST', data);
        },

        /**
         * Update an existing person
         */
        update: function(id, data) {
            return request({
                endpoint: 'identity',
                action: 'update',
                id: id
            }, 'PUT', data);
        },

        /**
         * Delete a person (soft delete)
         */
        delete: function(id) {
            return request({
                endpoint: 'identity',
                action: 'delete',
                id: id
            }, 'DELETE');
        },

        /**
         * Restore a soft-deleted person
         */
        restore: function(id) {
            return request({
                endpoint: 'identity',
                action: 'restore',
                id: id
            }, 'POST');
        },

        /**
         * Get person statistics
         */
        stats: function() {
            return request({
                endpoint: 'identity',
                action: 'stats'
            });
        },

        /**
         * Get person types
         */
        types: function() {
            return request({
                endpoint: 'identity',
                action: 'types'
            });
        },

        /**
         * Search people (autocomplete)
         */
        search: function(query, limit = 10) {
            return request({
                endpoint: 'identity',
                action: 'search',
                search: query,
                limit: limit
            });
        },

        /**
         * Get person contacts
         */
        contacts: function(id) {
            return request({
                endpoint: 'identity',
                action: 'contacts',
                id: id
            });
        },

        /**
         * Get person address
         */
        address: function(id) {
            return request({
                endpoint: 'identity',
                action: 'address',
                id: id
            });
        },

        /**
         * Get person roles
         */
        roles: function(id) {
            return request({
                endpoint: 'identity',
                action: 'roles',
                id: id
            });
        },

        /**
         * Get people by tenant
         */
        byTenant: function(tenantId) {
            return request({
                endpoint: 'identity',
                action: 'by_tenant',
                tenant_id: tenantId
            });
        },

        /**
         * Update person status
         */
        updateStatus: function(id, status) {
            return request({
                endpoint: 'identity',
                action: 'update_status',
                id: id
            }, 'PUT', { status: status });
        }
    };

    /**
     * Documents API Methods
     */
    const Documents = {
        /**
         * List documents
         */
        list: function(filters = {}, page = 1, limit = 20) {
            const params = {
                endpoint: 'identity',
                action: 'list',
                page: page,
                limit: limit
            };

            if (filters.document_type) params.document_type = filters.document_type;
            if (filters.status) params.status = filters.status;
            if (filters.tenant_id) params.tenant_id = filters.tenant_id;

            return request(params);
        },

        /**
         * Get a single document
         */
        get: function(id) {
            return request({
                endpoint: 'identity',
                action: 'get',
                id: id
            });
        },

        /**
         * Create a document
         */
        create: function(data) {
            return request({
                endpoint: 'identity',
                action: 'create'
            }, 'POST', data);
        },

        /**
         * Update a document
         */
        update: function(id, data) {
            return request({
                endpoint: 'identity',
                action: 'update',
                id: id
            }, 'PUT', data);
        },

        /**
         * Delete a document
         */
        delete: function(id) {
            return request({
                endpoint: 'identity',
                action: 'delete',
                id: id
            }, 'DELETE');
        },

        /**
         * Verify a document
         */
        verify: function(id) {
            return request({
                endpoint: 'identity',
                action: 'verify',
                id: id
            }, 'POST');
        },

        /**
         * Reject a document
         */
        reject: function(id) {
            return request({
                endpoint: 'identity',
                action: 'reject',
                id: id
            }, 'POST');
        }
    };

    /**
     * Relationships API Methods
     */
    const Relationships = {
        /**
         * List relationships
         */
        list: function(filters = {}, page = 1, limit = 20) {
            const params = {
                endpoint: 'relationships',
                action: 'list',
                page: page,
                limit: limit
            };

            if (filters.person_id) params.person_id = filters.person_id;
            if (filters.relationship_type) params.relationship_type = filters.relationship_type;
            if (filters.status) params.status = filters.status;

            return request(params);
        },

        /**
         * Get a single relationship
         */
        get: function(id) {
            return request({
                endpoint: 'relationships',
                action: 'get',
                id: id
            });
        },

        /**
         * Create a relationship
         */
        create: function(data) {
            return request({
                endpoint: 'relationships',
                action: 'create'
            }, 'POST', data);
        },

        /**
         * Update a relationship
         */
        update: function(id, data) {
            return request({
                endpoint: 'relationships',
                action: 'update',
                id: id
            }, 'PUT', data);
        },

        /**
         * Delete a relationship
         */
        delete: function(id) {
            return request({
                endpoint: 'relationships',
                action: 'delete',
                id: id
            }, 'DELETE');
        },

        /**
         * Get relationships by person
         */
        byPerson: function(personId) {
            return request({
                endpoint: 'relationships',
                action: 'by_person',
                person_id: personId
            });
        }
    };

    // Public API
    return {
        People: People,
        Documents: Documents,
        Relationships: Relationships,
        request: request,
        getHeaders: getHeaders
    };

})();

// Export for use in other files
if (typeof module !== 'undefined' && module.exports) {
    module.exports = IdentityAPI;
}