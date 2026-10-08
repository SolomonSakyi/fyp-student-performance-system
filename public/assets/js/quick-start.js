/**
 * Quick Start - Example usage of EduTrack API
 */

// Initialize API
const api = new EduTrackAPI({
    baseUrl: '/api/platform'
});

// =============================================
// AUTHENTICATION
// =============================================

async function loginExample() {
    const result = await api.login('admin', 'password');
    if (result.success) {
        console.log('Logged in successfully!');
        console.log('Token:', api.token);
        console.log('User:', result.data.user);
    } else {
        console.error('Login failed:', result.message);
    }
}

// =============================================
// SCHOOL CONFIGURATION
// =============================================

async function getSchoolConfigExample() {
    const result = await api.getSchoolConfig(1);
    if (result.success) {
        console.log('School Config:', result.data);
    } else {
        console.error('Failed:', result.message);
    }
}

async function updateSchoolConfigExample() {
    const result = await api.updateSchoolConfig(1, {
        school_name: 'ABC International School',
        motto: 'Excellence Through Innovation',
        school_type: 'International School'
    });
    if (result.success) {
        console.log('Updated successfully!');
    } else {
        console.error('Failed:', result.message);
    }
}

// =============================================
// LEVELS
// =============================================

async function getLevelsExample() {
    const result = await api.getLevels(1);
    if (result.success) {
        console.log('Levels:', result.data);
        console.log('Stats:', result.stats);
    } else {
        console.error('Failed:', result.message);
    }
}

async function addLevelExample() {
    const result = await api.addLevel(1, {
        level_code: 'PRIMARY_1',
        level_name: 'Primary 1',
        display_name: 'Grade 1',
        sequence: 1,
        academic_stage: 'Primary'
    });
    if (result.success) {
        console.log('Level added:', result.level_id);
    } else {
        console.error('Failed:', result.message);
    }
}

// =============================================
// SUBJECTS
// =============================================

async function getSubjectsExample() {
    const result = await api.getSubjects(1);
    if (result.success) {
        console.log('Subjects:', result.data);
        console.log('Stats:', result.stats);
        console.log('Disciplines:', result.disciplines);
    } else {
        console.error('Failed:', result.message);
    }
}

async function addSubjectExample() {
    const result = await api.addSubject(1, {
        subject_code: 'MATH101',
        subject_name: 'Mathematics',
        is_core: 1,
        max_score: 100,
        pass_mark: 50
    });
    if (result.success) {
        console.log('Subject added:', result.subject_id);
    } else {
        console.error('Failed:', result.message);
    }
}

// =============================================
// ASSESSMENT
// =============================================

async function getAssessmentProfilesExample() {
    const result = await api.getAssessmentProfiles(1);
    if (result.success) {
        console.log('Assessment Profiles:', result.data);
        console.log('Stats:', result.stats);
    } else {
        console.error('Failed:', result.message);
    }
}

async function createAssessmentProfileExample() {
    const result = await api.createAssessmentProfile(1, {
        profile_name: 'Standard JHS Profile',
        profile_code: 'JHS-STD',
        components: [
            { component_name: 'Class Score', max_score: 30, weight: 30 },
            { component_name: 'Examination', max_score: 70, weight: 70 }
        ]
    });
    if (result.success) {
        console.log('Profile created:', result.profile_id);
    } else {
        console.error('Failed:', result.message);
    }
}

// =============================================
// GRADING SYSTEMS
// =============================================

async function createGradingSystemExample() {
    const result = await api.createGradingSystem(1, {
        system_name: 'WAEC Numeric',
        system_code: 'WAEC',
        system_type: 'numeric',
        is_default: 1,
        scales: [
            { grade: 'A1', min_score: 80, max_score: 100, grade_point: 1, sort_order: 1 },
            { grade: 'B2', min_score: 70, max_score: 79, grade_point: 2, sort_order: 2 },
            { grade: 'B3', min_score: 60, max_score: 69, grade_point: 3, sort_order: 3 },
            { grade: 'C4', min_score: 55, max_score: 59, grade_point: 4, sort_order: 4 },
            { grade: 'C5', min_score: 50, max_score: 54, grade_point: 5, sort_order: 5 },
            { grade: 'C6', min_score: 45, max_score: 49, grade_point: 6, sort_order: 6 },
            { grade: 'D7', min_score: 40, max_score: 44, grade_point: 7, sort_order: 7 },
            { grade: 'E8', min_score: 35, max_score: 39, grade_point: 8, sort_order: 8 },
            { grade: 'F9', min_score: 0, max_score: 34, grade_point: 9, sort_order: 9 }
        ]
    });
    if (result.success) {
        console.log('Grading system created:', result.system_id);
    } else {
        console.error('Failed:', result.message);
    }
}

// =============================================
// REMARKS
// =============================================

async function generateRemarkExample() {
    const result = await api.generateRemark(1, {
        score: 85,
        context: 'Mathematics',
        level_id: 1
    });
    if (result.success) {
        console.log('Remark:', result.data.remark);
        console.log('Score:', result.data.score);
    } else {
        console.error('Failed:', result.message);
    }
}

// =============================================
// RUN ALL EXAMPLES
// =============================================

async function runAllExamples() {
    console.log('=== EduTrack API Examples ===');
    
    // First, login
    await loginExample();
    
    // Then run other examples
    await getSchoolConfigExample();
    await updateSchoolConfigExample();
    await getLevelsExample();
    await getSubjectsExample();
    await getAssessmentProfilesExample();
    await generateRemarkExample();
}

// Export for use
window.runAllExamples = runAllExamples;