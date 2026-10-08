/**
 * School Settings Loader
 * Loads and saves school settings via API
 */

const API_BASE = 'http://localhost:8000/api/platform/index.php';
const TOKEN = localStorage.getItem('token') || '';

/**
 * Load school settings from API
 * @param {number} schoolId - The school ID
 * @returns {Promise<object>} - Settings grouped by category
 */
async function loadSchoolSettings(schoolId) {
    try {
        const url = `${API_BASE}?endpoint=schools&school_id=${schoolId}&action=settings`;
        const response = await fetch(url, {
            headers: {
                'Authorization': `Bearer ${TOKEN}`,
                'Content-Type': 'application/json'
            }
        });
        
        const result = await response.json();
        
        if (result.success) {
            return result.data || {};
        } else {
            console.error('Error loading settings:', result.message);
            return {};
        }
    } catch (error) {
        console.error('Network error loading settings:', error);
        return {};
    }
}

/**
 * Save school settings
 * @param {number} schoolId - The school ID
 * @param {object} settings - Settings to save
 * @returns {Promise<object>} - API response
 */
async function saveSchoolSettings(schoolId, settings) {
    try {
        const response = await fetch(`${API_BASE}?endpoint=schools&school_id=${schoolId}&action=settings`, {
            method: 'PUT',
            headers: {
                'Authorization': `Bearer ${TOKEN}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(settings)
        });
        
        return await response.json();
    } catch (error) {
        console.error('Error saving settings:', error);
        return { success: false, message: error.message };
    }
}

/**
 * Populate form fields with settings data
 * @param {object} settings - Settings grouped by category
 * @param {string} category - Category to populate (e.g., 'profile')
 */
function populateFormFields(settings, category) {
    const categorySettings = settings[category] || {};
    
    // Find all form inputs with names matching settings keys
    const inputs = document.querySelectorAll(`form input[name], form select[name], form textarea[name]`);
    
    inputs.forEach(input => {
        const name = input.name;
        if (categorySettings[name] !== undefined) {
            input.value = categorySettings[name];
        }
    });
}

/**
 * Collect form data from a form
 * @param {string} formId - Form ID
 * @returns {object} - Collected data
 */
function collectFormData(formId) {
    const form = document.getElementById(formId);
    if (!form) return {};
    
    const formData = new FormData(form);
    const data = {};
    formData.forEach((value, key) => {
        data[key] = value;
    });
    return data;
}

// Export functions for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { loadSchoolSettings, saveSchoolSettings, populateFormFields, collectFormData };
}