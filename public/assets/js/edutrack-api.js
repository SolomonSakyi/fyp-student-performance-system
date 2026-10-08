/**
 * EduTrack API SDK
 * Frontend JavaScript library for API integration
 * 
 * @package EduTrack
 * @subpackage Assets\JS
 * @version 1.0
 */

class EduTrackAPI {
    constructor(config = {}) {
        this.baseUrl = config.baseUrl || '/api/platform';
        this.token = config.token || localStorage.getItem('edutrack_token') || '';
        this.tenantId = config.tenantId || localStorage.getItem('edutrack_tenant_id') || null;
        this.schoolId = config.schoolId || localStorage.getItem('edutrack_school_id') || null;
        this.campusId = config.campusId || localStorage.getItem('edutrack_campus_id') || null;
    }

    // =============================================
    // AUTHENTICATION
    // =============================================

    /**
     * Login
     */
    async login(username, password) {
        const response = await this._request('POST', '/auth/login', { username, password });
        if (response.success && response.data.token) {
            this.token = response.data.token;
            this.tenantId = response.data.user.tenant_id;
            localStorage.setItem('edutrack_token', this.token);
            localStorage.setItem('edutrack_tenant_id', this.tenantId);
        }
        return response;
    }

    /**
     * Logout
     */
    async logout() {
        const response = await this._request('POST', '/auth/logout');
        this.token = '';
        localStorage.removeItem('edutrack_token');
        localStorage.removeItem('edutrack_tenant_id');
        localStorage.removeItem('edutrack_school_id');
        localStorage.removeItem('edutrack_campus_id');
        return response;
    }

    /**
     * Refresh token
     */
    async refreshToken() {
        const response = await this._request('POST', '/auth/refresh');
        if (response.success && response.data.token) {
            this.token = response.data.token;
            localStorage.setItem('edutrack_token', this.token);
        }
        return response;
    }

    /**
     * Get current user
     */
    async getCurrentUser() {
        return this._request('GET', '/auth/me');
    }

    // =============================================
    // SCHOOL CONFIGURATION
    // =============================================

    /**
     * Get school configuration
     */
    async getSchoolConfig(schoolId = null) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('GET', `/schools/${id}/settings`);
    }

    /**
     * Update school configuration
     */
    async updateSchoolConfig(schoolId, data) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('PUT', `/schools/${id}/settings`, data);
    }

    /**
     * Get configuration health
     */
    async getConfigHealth(schoolId = null) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('GET', `/schools/${id}/settings/health`);
    }

    // =============================================
    // LEVEL MANAGEMENT
    // =============================================

    /**
     * Get school levels
     */
    async getLevels(schoolId = null) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('GET', `/schools/${id}/levels`);
    }

    /**
     * Add level
     */
    async addLevel(schoolId, data) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('POST', `/schools/${id}/levels`, data);
    }

    /**
     * Update level display name
     */
    async updateLevelDisplayName(schoolId, levelCode, data) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('PUT', `/schools/${id}/levels/${levelCode}/display-name`, data);
    }

    /**
     * Remove level
     */
    async removeLevel(schoolId, levelCode) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('DELETE', `/schools/${id}/levels/${levelCode}`);
    }

    /**
     * Get available levels (system)
     */
    async getAvailableLevels() {
        return this._request('GET', '/schools/available-levels');
    }

    // =============================================
    // SUBJECT MANAGEMENT
    // =============================================

    /**
     * Get school subjects
     */
    async getSubjects(schoolId = null, disciplineId = null) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        let url = `/schools/${id}/subjects`;
        if (disciplineId) url += `?discipline_id=${disciplineId}`;
        return this._request('GET', url);
    }

    /**
     * Add subject
     */
    async addSubject(schoolId, data) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('POST', `/schools/${id}/subjects`, data);
    }

    /**
     * Get subject
     */
    async getSubject(schoolId, subjectId) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('GET', `/schools/${id}/subjects/${subjectId}`);
    }

    /**
     * Update subject
     */
    async updateSubject(schoolId, subjectId, data) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('PUT', `/schools/${id}/subjects/${subjectId}`, data);
    }

    /**
     * Delete subject
     */
    async deleteSubject(schoolId, subjectId) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('DELETE', `/schools/${id}/subjects/${subjectId}`);
    }

    /**
     * Get subjects by level
     */
    async getSubjectsByLevel(schoolId, levelId) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('GET', `/schools/${id}/subjects/level/${levelId}`);
    }

    /**
     * Assign subject to level
     */
    async assignSubjectToLevel(schoolId, subjectId, data) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('POST', `/schools/${id}/subjects/${subjectId}/assign-level`, data);
    }

    /**
     * Remove subject from level
     */
    async removeSubjectFromLevel(schoolId, subjectId, levelId) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('DELETE', `/schools/${id}/subjects/${subjectId}/remove-level/${levelId}`);
    }

    // =============================================
    // DISCIPLINE MANAGEMENT
    // =============================================

    /**
     * Get disciplines
     */
    async getDisciplines(schoolId = null) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('GET', `/schools/${id}/disciplines`);
    }

    /**
     * Add discipline
     */
    async addDiscipline(schoolId, data) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('POST', `/schools/${id}/disciplines`, data);
    }

    // =============================================
    // ASSESSMENT MANAGEMENT
    // =============================================

    /**
     * Get assessment profiles
     */
    async getAssessmentProfiles(schoolId = null, levelId = null) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        let url = `/schools/${id}/assessment/profiles`;
        if (levelId) url += `?level_id=${levelId}`;
        return this._request('GET', url);
    }

    /**
     * Create assessment profile
     */
    async createAssessmentProfile(schoolId, data) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('POST', `/schools/${id}/assessment/profiles`, data);
    }

    /**
     * Get assessment profile
     */
    async getAssessmentProfile(schoolId, profileId) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('GET', `/schools/${id}/assessment/profiles/${profileId}`);
    }

    /**
     * Update assessment profile
     */
    async updateAssessmentProfile(schoolId, profileId, data) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('PUT', `/schools/${id}/assessment/profiles/${profileId}`, data);
    }

    /**
     * Validate profile weights
     */
    async validateProfileWeights(schoolId, profileId) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('POST', `/schools/${id}/assessment/profiles/${profileId}/validate`);
    }

    // =============================================
    // GRADING SYSTEMS
    // =============================================

    /**
     * Get grading systems
     */
    async getGradingSystems(schoolId = null, levelId = null) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        let url = `/schools/${id}/grading/systems`;
        if (levelId) url += `?level_id=${levelId}`;
        return this._request('GET', url);
    }

    /**
     * Create grading system
     */
    async createGradingSystem(schoolId, data) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('POST', `/schools/${id}/grading/systems`, data);
    }

    /**
     * Get grading system
     */
    async getGradingSystem(schoolId, systemId) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('GET', `/schools/${id}/grading/systems/${systemId}`);
    }

    /**
     * Add grade scale
     */
    async addGradeScale(schoolId, systemId, data) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('POST', `/schools/${id}/grading/systems/${systemId}/scales`, data);
    }

    // =============================================
    // AGGREGATION RULES
    // =============================================

    /**
     * Get aggregation rules
     */
    async getAggregationRules(schoolId = null, levelId = null) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        let url = `/schools/${id}/aggregation/rules`;
        if (levelId) url += `?level_id=${levelId}`;
        return this._request('GET', url);
    }

    /**
     * Create aggregation rule
     */
    async createAggregationRule(schoolId, data) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('POST', `/schools/${id}/aggregation/rules`, data);
    }

    // =============================================
    // PROMOTION RULES
    // =============================================

    /**
     * Get promotion rules
     */
    async getPromotionRules(schoolId = null, levelId = null) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        let url = `/schools/${id}/promotion/rules`;
        if (levelId) url += `?level_id=${levelId}`;
        return this._request('GET', url);
    }

    /**
     * Create promotion rule
     */
    async createPromotionRule(schoolId, data) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('POST', `/schools/${id}/promotion/rules`, data);
    }

    /**
     * Evaluate student promotion
     */
    async evaluatePromotion(schoolId, data) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('POST', `/schools/${id}/promotion/evaluate`, data);
    }

    // =============================================
    // REMARK RULES
    // =============================================

    /**
     * Get remark rules
     */
    async getRemarkRules(schoolId = null, levelId = null, conditionType = null) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        let url = `/schools/${id}/remarks/rules`;
        const params = [];
        if (levelId) params.push(`level_id=${levelId}`);
        if (conditionType) params.push(`condition_type=${conditionType}`);
        if (params.length) url += '?' + params.join('&');
        return this._request('GET', url);
    }

    /**
     * Create remark rule
     */
    async createRemarkRule(schoolId, data) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('POST', `/schools/${id}/remarks/rules`, data);
    }

    /**
     * Generate remark
     */
    async generateRemark(schoolId, data) {
        const id = schoolId || this.schoolId;
        if (!id) throw new Error('School ID required');
        return this._request('POST', `/schools/${id}/remarks/generate`, data);
    }

    // =============================================
    // PRIVATE METHODS
    // =============================================

    /**
     * Make API request
     */
    async _request(method, endpoint, data = null) {
        const url = this.baseUrl + endpoint;
        const headers = {
            'Content-Type': 'application/json'
        };

        if (this.token) {
            headers['Authorization'] = 'Bearer ' + this.token;
        }

        const options = {
            method: method,
            headers: headers
        };

        if (data && ['POST', 'PUT', 'DELETE'].includes(method)) {
            options.body = JSON.stringify(data);
        }

        try {
            const response = await fetch(url, options);
            const result = await response.json();

            // If token expired, try to refresh
            if (response.status === 401 && result.message === 'Invalid token') {
                const refreshResult = await this.refreshToken();
                if (refreshResult.success) {
                    // Retry with new token
                    headers['Authorization'] = 'Bearer ' + this.token;
                    options.headers = headers;
                    const retryResponse = await fetch(url, options);
                    return retryResponse.json();
                }
            }

            return result;
        } catch (error) {
            console.error('API Error:', error);
            return {
                success: false,
                message: error.message || 'Network error'
            };
        }
    }
}

// Create global instance
window.EduTrackAPI = EduTrackAPI;