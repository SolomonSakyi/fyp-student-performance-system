/**
 * Assessment Service Layer
 * Handles all assessment data operations with support for both mock and real API
 * @package EduTrack
 * @subpackage Platform\Schools\Settings
 * @version 1.0
 */

// ============================================================
// ASSESSMENT SERVICE - Complete Implementation
// ============================================================
var AssessmentService = {
    // ============================================================
    // CONFIGURATION
    // ============================================================
    useMockData: true,  // Set to false when API is ready
    apiBase: window.location.origin + '/api/assessment',
    token: localStorage.getItem('token') || '',
    schoolId: null,

    // ============================================================
    // INITIALIZE
    // ============================================================
    init: function(schoolId) {
        this.schoolId = schoolId;
        console.log('Assessment Service initialized with school_id:', schoolId);
        console.log('Using mock data:', this.useMockData);
        return this;
    },

    // ============================================================
    // API HELPERS
    // ============================================================
    getHeaders: function() {
        return {
            'Authorization': 'Bearer ' + this.token,
            'Content-Type': 'application/json'
        };
    },

    handleResponse: function(response) {
        if (!response.ok) {
            throw new Error('API request failed: ' + response.status);
        }
        return response.json();
    },

    // ============================================================
    // MOCK DATA GENERATORS
    // ============================================================
    getMockProfiles: function() {
        return [
            {
                'profile_id': 1,
                'name': 'JHS WAEC Profile',
                'code': 'JHS-WAEC',
                'description': 'Standard JHS assessment profile based on WAEC model',
                'applicable_levels': 'basic_7,basic_8,basic_9',
                'assessment_model': '30_70',
                'school_id': this.schoolId,
                'status': 'active',
                'is_locked': 0,
                'is_default': 1,
                'total_weight': 100,
                'created_at': new Date().toISOString()
            },
            {
                'profile_id': 2,
                'name': 'Primary Standard Profile',
                'code': 'PRIM-STD',
                'description': 'Standard primary school assessment profile',
                'applicable_levels': 'primary_1,primary_2,primary_3,primary_4,primary_5,primary_6',
                'assessment_model': '40_60',
                'school_id': this.schoolId,
                'status': 'active',
                'is_locked': 0,
                'is_default': 0,
                'total_weight': 100,
                'created_at': new Date(Date.now() - 3600000).toISOString()
            },
            {
                'profile_id': 3,
                'name': 'SHS WAEC Profile',
                'code': 'SHS-WAEC',
                'description': 'Standard SHS assessment profile based on WAEC model',
                'applicable_levels': 'shs_1,shs_2,shs_3',
                'assessment_model': '30_70',
                'school_id': this.schoolId,
                'status': 'draft',
                'is_locked': 0,
                'is_default': 0,
                'total_weight': 100,
                'created_at': new Date(Date.now() - 7200000).toISOString()
            },
            {
                'profile_id': 4,
                'name': 'Montessori Assessment Profile',
                'code': 'MONT',
                'description': 'Montessori style assessment with continuous evaluation',
                'applicable_levels': 'preschool,kindergarten',
                'assessment_model': '50_50',
                'school_id': this.schoolId,
                'status': 'archived',
                'is_locked': 1,
                'is_default': 0,
                'total_weight': 100,
                'created_at': new Date(Date.now() - 86400000).toISOString()
            }
        ];
    },

    getMockGradingSystems: function() {
        return [
            {
                'grading_system_id': 1,
                'name': 'WAEC Numeric Grading',
                'code': 'WAEC-NUM',
                'system_type': 'numeric',
                'description': 'WAEC compatible numeric grading system (1-9)',
                'is_default': 1,
                'status': 'active',
                'school_id': this.schoolId
            },
            {
                'grading_system_id': 2,
                'name': 'Alphabetical A-F',
                'code': 'ALPHA-AF',
                'system_type': 'alphabetical',
                'description': 'Standard alphabetical grading system (A-F)',
                'is_default': 0,
                'status': 'active',
                'school_id': this.schoolId
            },
            {
                'grading_system_id': 3,
                'name': 'Percentage Based',
                'code': 'PCT-BASED',
                'system_type': 'percentage',
                'description': 'Simple percentage based grading',
                'is_default': 0,
                'status': 'draft',
                'school_id': this.schoolId
            }
        ];
    },

    getMockAggregationRules: function() {
        return [
            {
                'aggregation_rule_id': 1,
                'name': 'JHS Standard Aggregate',
                'code': 'JHS-AGG',
                'description': 'Standard JHS aggregation with 4 core and 2 best electives',
                'core_count': 4,
                'elective_count': 2,
                'core_selection': 'mandatory',
                'elective_selection': 'best',
                'aggregation_method': 'sum_grade_points',
                'status': 'active',
                'is_default': 1,
                'school_id': this.schoolId
            },
            {
                'aggregation_rule_id': 2,
                'name': 'SHS Aggregate (4 Core + 3 Best)',
                'code': 'SHS-AGG',
                'description': 'SHS aggregation with 4 core and 3 best electives',
                'core_count': 4,
                'elective_count': 3,
                'core_selection': 'mandatory',
                'elective_selection': 'best',
                'aggregation_method': 'sum_grade_points',
                'status': 'draft',
                'is_default': 0,
                'school_id': this.schoolId
            }
        ];
    },

    getMockComponents: function() {
        return [
            {
                'component_id': 1,
                'component_name': 'Class Assessment',
                'component_code': 'CA',
                'max_score': 30,
                'default_weight': 30,
                'is_required': 1,
                'school_id': this.schoolId
            },
            {
                'component_id': 2,
                'component_name': 'Examination',
                'component_code': 'EXAM',
                'max_score': 70,
                'default_weight': 70,
                'is_required': 1,
                'school_id': this.schoolId
            },
            {
                'component_id': 3,
                'component_name': 'Project Work',
                'component_code': 'PROJ',
                'max_score': 20,
                'default_weight': 20,
                'is_required': 0,
                'school_id': this.schoolId
            }
        ];
    },

    getMockRemarks: function() {
        return [
            {
                'remark_rule_id': 1,
                'rule_name': 'Excellent Performance',
                'rule_code': 'EXC',
                'min_score': 80,
                'max_score': 100,
                'remark_text': 'Excellent performance. Student shows outstanding understanding and application of concepts.',
                'school_id': this.schoolId
            },
            {
                'remark_rule_id': 2,
                'rule_name': 'Very Good Performance',
                'rule_code': 'VG',
                'min_score': 70,
                'max_score': 79,
                'remark_text': 'Very good performance. Student demonstrates strong understanding with minor areas for improvement.',
                'school_id': this.schoolId
            },
            {
                'remark_rule_id': 3,
                'rule_name': 'Good Performance',
                'rule_code': 'GD',
                'min_score': 60,
                'max_score': 69,
                'remark_text': 'Good performance. Student has a solid grasp of concepts but could improve in some areas.',
                'school_id': this.schoolId
            },
            {
                'remark_rule_id': 4,
                'rule_name': 'Satisfactory',
                'rule_code': 'SAT',
                'min_score': 50,
                'max_score': 59,
                'remark_text': 'Satisfactory performance. Student meets basic requirements but needs to put in more effort.',
                'school_id': this.schoolId
            },
            {
                'remark_rule_id': 5,
                'rule_name': 'Needs Improvement',
                'rule_code': 'NI',
                'min_score': 0,
                'max_score': 49,
                'remark_text': 'Needs improvement. Student requires additional support and focus on core concepts.',
                'school_id': this.schoolId
            }
        ];
    },

    getMockAuditLogs: function() {
        return [
            {
                'id': 1,
                'action': 'created',
                'field_name': 'JHS WAEC Profile',
                'old_value': null,
                'new_value': 'Active',
                'changed_by': 'Admin User',
                'created_at': new Date().toISOString()
            },
            {
                'id': 2,
                'action': 'updated',
                'field_name': 'Primary Standard Profile',
                'old_value': 'Draft',
                'new_value': 'Active',
                'changed_by': 'Admin User',
                'created_at': new Date(Date.now() - 3600000).toISOString()
            },
            {
                'id': 3,
                'action': 'created',
                'field_name': 'SHS WAEC Profile',
                'old_value': null,
                'new_value': 'Draft',
                'changed_by': 'Admin User',
                'created_at': new Date(Date.now() - 7200000).toISOString()
            }
        ];
    },

    // ============================================================
    // PROFILE CRUD OPERATIONS
    // ============================================================

    /**
     * Get all profiles for a school
     */
    getProfiles: function() {
        var self = this;

        if (this.useMockData) {
            return new Promise(function(resolve) {
                setTimeout(function() {
                    resolve(self.getMockProfiles());
                }, 300);
            });
        }

        return fetch(this.apiBase + '/profiles?school_id=' + this.schoolId, {
            headers: this.getHeaders()
        })
        .then(this.handleResponse)
        .then(function(response) {
            return response.data || [];
        });
    },

    /**
     * Get a single profile by ID
     */
    getProfile: function(profileId) {
        var self = this;

        if (this.useMockData) {
            return new Promise(function(resolve) {
                setTimeout(function() {
                    var profiles = self.getMockProfiles();
                    var profile = null;
                    for (var i = 0; i < profiles.length; i++) {
                        if (profiles[i].profile_id === profileId) {
                            profile = profiles[i];
                            break;
                        }
                    }
                    resolve(profile);
                }, 300);
            });
        }

        return fetch(this.apiBase + '/profiles/' + profileId, {
            headers: this.getHeaders()
        })
        .then(this.handleResponse)
        .then(function(response) {
            return response.data || null;
        });
    },

    /**
     * Create a new profile
     */
    createProfile: function(data) {
        var self = this;

        if (this.useMockData) {
            return new Promise(function(resolve) {
                setTimeout(function() {
                    var profiles = self.getMockProfiles();
                    var newId = profiles.length + 1;
                    var newProfile = {
                        'profile_id': newId,
                        'name': data.name,
                        'code': data.code,
                        'description': data.description || '',
                        'applicable_levels': data.applicable_levels || '',
                        'assessment_model': data.assessment_model || '30_70',
                        'school_id': self.schoolId,
                        'status': data.status || 'draft',
                        'is_locked': 0,
                        'is_default': 0,
                        'total_weight': 100,
                        'created_at': new Date().toISOString()
                    };
                    resolve({
                        success: true,
                        message: 'Profile created successfully (Mock)',
                        data: { profile_id: newId }
                    });
                }, 500);
            });
        }

        return fetch(this.apiBase + '/profiles', {
            method: 'POST',
            headers: this.getHeaders(),
            body: JSON.stringify(data)
        })
        .then(this.handleResponse);
    },

    /**
     * Update an existing profile
     */
    updateProfile: function(profileId, data) {
        if (this.useMockData) {
            return new Promise(function(resolve) {
                setTimeout(function() {
                    resolve({
                        success: true,
                        message: 'Profile updated successfully (Mock)',
                        data: { profile_id: profileId }
                    });
                }, 500);
            });
        }

        return fetch(this.apiBase + '/profiles/' + profileId, {
            method: 'PUT',
            headers: this.getHeaders(),
            body: JSON.stringify(data)
        })
        .then(this.handleResponse);
    },

    /**
     * Delete a profile
     */
    deleteProfile: function(profileId) {
        if (this.useMockData) {
            return new Promise(function(resolve) {
                setTimeout(function() {
                    resolve({
                        success: true,
                        message: 'Profile deleted successfully (Mock)'
                    });
                }, 500);
            });
        }

        return fetch(this.apiBase + '/profiles/' + profileId, {
            method: 'DELETE',
            headers: this.getHeaders()
        })
        .then(this.handleResponse);
    },

    /**
     * Activate a profile
     */
    activateProfile: function(profileId) {
        if (this.useMockData) {
            return new Promise(function(resolve) {
                setTimeout(function() {
                    resolve({
                        success: true,
                        message: 'Profile activated successfully (Mock)'
                    });
                }, 500);
            });
        }

        return fetch(this.apiBase + '/profiles/' + profileId + '/activate', {
            method: 'POST',
            headers: this.getHeaders(),
            body: JSON.stringify({ status: 'active' })
        })
        .then(this.handleResponse);
    },

    /**
     * Deactivate a profile
     */
    deactivateProfile: function(profileId) {
        if (this.useMockData) {
            return new Promise(function(resolve) {
                setTimeout(function() {
                    resolve({
                        success: true,
                        message: 'Profile deactivated successfully (Mock)'
                    });
                }, 500);
            });
        }

        return fetch(this.apiBase + '/profiles/' + profileId + '/activate', {
            method: 'POST',
            headers: this.getHeaders(),
            body: JSON.stringify({ status: 'inactive' })
        })
        .then(this.handleResponse);
    },

    /**
     * Duplicate a profile
     */
    duplicateProfile: function(profileId, newName) {
        if (this.useMockData) {
            return new Promise(function(resolve) {
                setTimeout(function() {
                    resolve({
                        success: true,
                        message: 'Profile duplicated successfully (Mock)',
                        data: { profile_id: profileId + 100 }
                    });
                }, 500);
            });
        }

        return fetch(this.apiBase + '/profiles/' + profileId + '/duplicate', {
            method: 'POST',
            headers: this.getHeaders(),
            body: JSON.stringify({ name: newName })
        })
        .then(this.handleResponse);
    },

    // ============================================================
    // GRADING SYSTEMS
    // ============================================================
    getGradingSystems: function() {
        var self = this;

        if (this.useMockData) {
            return new Promise(function(resolve) {
                setTimeout(function() {
                    resolve(self.getMockGradingSystems());
                }, 300);
            });
        }

        return fetch(this.apiBase + '/grading-systems?school_id=' + this.schoolId, {
            headers: this.getHeaders()
        })
        .then(this.handleResponse)
        .then(function(response) {
            return response.data || [];
        });
    },

    // ============================================================
    // AGGREGATION RULES
    // ============================================================
    getAggregationRules: function() {
        var self = this;

        if (this.useMockData) {
            return new Promise(function(resolve) {
                setTimeout(function() {
                    resolve(self.getMockAggregationRules());
                }, 300);
            });
        }

        return fetch(this.apiBase + '/aggregation-rules?school_id=' + this.schoolId, {
            headers: this.getHeaders()
        })
        .then(this.handleResponse)
        .then(function(response) {
            return response.data || [];
        });
    },

    // ============================================================
    // COMPONENTS
    // ============================================================
    getComponents: function() {
        var self = this;

        if (this.useMockData) {
            return new Promise(function(resolve) {
                setTimeout(function() {
                    resolve(self.getMockComponents());
                }, 300);
            });
        }

        return fetch(this.apiBase + '/components?school_id=' + this.schoolId, {
            headers: this.getHeaders()
        })
        .then(this.handleResponse)
        .then(function(response) {
            return response.data || [];
        });
    },

    // ============================================================
    // REMARKS
    // ============================================================
    getRemarks: function() {
        var self = this;

        if (this.useMockData) {
            return new Promise(function(resolve) {
                setTimeout(function() {
                    resolve(self.getMockRemarks());
                }, 300);
            });
        }

        return fetch(this.apiBase + '/remarks?school_id=' + this.schoolId, {
            headers: this.getHeaders()
        })
        .then(this.handleResponse)
        .then(function(response) {
            return response.data || [];
        });
    },

    // ============================================================
    // AUDIT LOGS
    // ============================================================
    getAuditLogs: function(limit) {
        var self = this;
        limit = limit || 20;

        if (this.useMockData) {
            return new Promise(function(resolve) {
                setTimeout(function() {
                    var logs = self.getMockAuditLogs();
                    if (limit && logs.length > limit) {
                        logs = logs.slice(0, limit);
                    }
                    resolve(logs);
                }, 300);
            });
        }

        return fetch(this.apiBase + '/audit?school_id=' + this.schoolId + '&limit=' + limit, {
            headers: this.getHeaders()
        })
        .then(this.handleResponse)
        .then(function(response) {
            return response.data || [];
        });
    },

    // ============================================================
    // STATS / DASHBOARD DATA
    // ============================================================
    getStats: function() {
        var self = this;

        if (this.useMockData) {
            return new Promise(function(resolve) {
                setTimeout(function() {
                    var profiles = self.getMockProfiles();
                    var total = profiles.length;
                    var active = 0;
                    var draft = 0;
                    var locked = 0;
                    var archived = 0;

                    profiles.forEach(function(p) {
                        if (p.status === 'active') active++;
                        else if (p.status === 'draft') draft++;
                        else if (p.status === 'archived') archived++;
                        if (p.is_locked) locked++;
                    });

                    resolve({
                        total_profiles: total,
                        active_profiles: active,
                        draft_profiles: draft,
                        archived_profiles: archived,
                        locked_profiles: locked,
                        total_grading_systems: self.getMockGradingSystems().length,
                        total_aggregation_rules: self.getMockAggregationRules().length,
                        total_components: self.getMockComponents().length,
                        total_remarks: self.getMockRemarks().length
                    });
                }, 300);
            });
        }

        return fetch(this.apiBase + '/stats?school_id=' + this.schoolId, {
            headers: this.getHeaders()
        })
        .then(this.handleResponse)
        .then(function(response) {
            return response.data || {};
        });
    }
};