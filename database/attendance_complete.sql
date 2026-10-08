-- ================================================================
-- DATABASE: student_performance_system
-- MODULE: Enterprise Attendance & Presence Intelligence Module
-- VERSION: 2.0 - Complete
-- ================================================================

USE student_performance_system;

-- ================================================================
-- DISABLE FOREIGN KEY CHECKS
-- ================================================================
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- 1. ATTENDANCE METHODS
-- Stores all possible attendance capture methods
-- ================================================================
DROP TABLE IF EXISTS attendance_methods;
CREATE TABLE attendance_methods (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    method_name VARCHAR(50) NOT NULL COMMENT 'e.g., Fingerprint, RFID, Manual',
    method_code VARCHAR(20) NOT NULL COMMENT 'FP, RFID, MAN, QR, FACIAL',
    method_type ENUM('biometric','card','qr','manual','mobile','tablet','web','api') NOT NULL DEFAULT 'manual',
    requires_hardware TINYINT(1) NOT NULL DEFAULT 0,
    is_biometric TINYINT(1) NOT NULL DEFAULT 0,
    supports_offline TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    description TEXT,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_methods_uuid (uuid),
    UNIQUE KEY uq_attendance_methods_code (school_id, method_code),
    UNIQUE KEY uq_attendance_methods_name (school_id, method_name),
    INDEX idx_am_school_active (school_id, is_active),
    INDEX idx_am_method_type (method_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 2. ATTENDANCE DEVICE TYPES
-- Device type definitions
-- ================================================================
DROP TABLE IF EXISTS attendance_device_types;
CREATE TABLE attendance_device_types (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    type_name VARCHAR(50) NOT NULL,
    type_code VARCHAR(20) NOT NULL,
    category ENUM('fingerprint','facial','rfid','nfc','qr','barcode','hybrid') NOT NULL,
    manufacturer VARCHAR(100) DEFAULT NULL,
    model_pattern VARCHAR(100) DEFAULT NULL,
    supports_offline TINYINT(1) NOT NULL DEFAULT 0,
    supports_biometric TINYINT(1) NOT NULL DEFAULT 0,
    config_schema JSON COMMENT 'Device configuration schema',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_device_types_uuid (uuid),
    UNIQUE KEY uq_attendance_device_types_code (type_code),
    INDEX idx_adt_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 3. ATTENDANCE DEVICES
-- Physical devices (fingerprint scanners, cameras, RFID readers)
-- ================================================================
DROP TABLE IF EXISTS attendance_devices;
CREATE TABLE attendance_devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    campus_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = all campuses',
    device_type_id BIGINT UNSIGNED NOT NULL,
    device_name VARCHAR(100) NOT NULL,
    device_serial VARCHAR(100) NOT NULL,
    device_code VARCHAR(20) NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    port_number INT UNSIGNED DEFAULT NULL,
    mac_address VARCHAR(17) DEFAULT NULL,
    api_key VARCHAR(255) DEFAULT NULL COMMENT 'For device authentication',
    api_secret VARCHAR(255) DEFAULT NULL COMMENT 'For device authentication',
    location VARCHAR(200) DEFAULT NULL,
    latitude DECIMAL(10,8) DEFAULT NULL,
    longitude DECIMAL(11,8) DEFAULT NULL,
    firmware_version VARCHAR(50) DEFAULT NULL,
    hardware_version VARCHAR(50) DEFAULT NULL,
    last_connected TIMESTAMP NULL DEFAULT NULL,
    status ENUM('online','offline','maintenance','inactive') NOT NULL DEFAULT 'offline',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    sync_frequency INT UNSIGNED DEFAULT 60 COMMENT 'Sync interval in seconds',
    last_sync TIMESTAMP NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_devices_uuid (uuid),
    UNIQUE KEY uq_attendance_devices_serial (school_id, device_serial),
    UNIQUE KEY uq_attendance_devices_code (school_id, device_code),
    UNIQUE KEY uq_attendance_devices_api_key (api_key),
    INDEX idx_ad_school_active (school_id, is_active),
    INDEX idx_ad_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 4. ATTENDANCE STATUSES
-- Configurable attendance status definitions
-- ================================================================
DROP TABLE IF EXISTS attendance_statuses;
CREATE TABLE attendance_statuses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    status_name VARCHAR(50) NOT NULL,
    status_code VARCHAR(10) NOT NULL,
    category ENUM('present','absent','late','excused','holiday','unknown') NOT NULL DEFAULT 'unknown',
    is_present TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Counts as present',
    is_absent TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Counts as absent',
    is_excused TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Has valid excuse',
    is_late TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Is late arrival',
    is_default TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Default status',
    color VARCHAR(7) DEFAULT '#6c757d',
    icon VARCHAR(50) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    requires_reason TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Reason required',
    requires_approval TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Approval required',
    notification_required TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Notify parents',
    is_system TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'System defined',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_statuses_uuid (uuid),
    UNIQUE KEY uq_attendance_statuses_code (school_id, status_code),
    UNIQUE KEY uq_attendance_statuses_name (school_id, status_name),
    INDEX idx_ast_school_active (school_id, is_active),
    INDEX idx_ast_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 5. ATTENDANCE LOCATIONS
-- Physical locations where attendance can be taken
-- ================================================================
DROP TABLE IF EXISTS attendance_locations;
CREATE TABLE attendance_locations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    campus_id BIGINT UNSIGNED DEFAULT NULL,
    location_name VARCHAR(100) NOT NULL,
    location_code VARCHAR(20) NOT NULL,
    location_type ENUM('entrance','classroom','office','library','lab','hostel','canteen','sports','assembly','other') NOT NULL DEFAULT 'classroom',
    latitude DECIMAL(10,8) DEFAULT NULL,
    longitude DECIMAL(11,8) DEFAULT NULL,
    radius_meters INT UNSIGNED DEFAULT 50 COMMENT 'GPS radius for geofencing',
    address TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_locations_uuid (uuid),
    UNIQUE KEY uq_attendance_locations_code (school_id, location_code),
    INDEX idx_al_school_active (school_id, is_active),
    INDEX idx_al_location_type (location_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 6. ATTENDANCE GEOFENCES
-- Geofence definitions for location-based attendance
-- ================================================================
DROP TABLE IF EXISTS attendance_geofences;
CREATE TABLE attendance_geofences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    geofence_name VARCHAR(100) NOT NULL,
    geofence_code VARCHAR(20) NOT NULL,
    geofence_type ENUM('circle','polygon','rectangle') NOT NULL DEFAULT 'circle',
    center_lat DECIMAL(10,8) DEFAULT NULL,
    center_lng DECIMAL(11,8) DEFAULT NULL,
    radius_meters INT UNSIGNED DEFAULT 100,
    polygon_points JSON COMMENT 'For polygon geofences',
    allow_checkin TINYINT(1) NOT NULL DEFAULT 1,
    allow_checkout TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_geofences_uuid (uuid),
    UNIQUE KEY uq_attendance_geofences_code (school_id, geofence_code),
    INDEX idx_ag_school_active (school_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 7. ATTENDANCE CALENDARS
-- School calendar configuration
-- ================================================================
DROP TABLE IF EXISTS attendance_calendars;
CREATE TABLE attendance_calendars (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    campus_id BIGINT UNSIGNED DEFAULT NULL,
    calendar_name VARCHAR(100) NOT NULL,
    calendar_code VARCHAR(20) NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    term_id BIGINT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    days_per_week INT UNSIGNED NOT NULL DEFAULT 5,
    school_start_time TIME DEFAULT '07:30:00',
    school_end_time TIME DEFAULT '15:30:00',
    timezone VARCHAR(50) DEFAULT 'Africa/Accra',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_calendars_uuid (uuid),
    UNIQUE KEY uq_attendance_calendars_code (school_id, calendar_code),
    INDEX idx_ac_school_active (school_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 8. ATTENDANCE HOLIDAYS
-- School holidays and non-school days
-- ================================================================
DROP TABLE IF EXISTS attendance_holidays;
CREATE TABLE attendance_holidays (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    campus_id BIGINT UNSIGNED DEFAULT NULL,
    holiday_name VARCHAR(100) NOT NULL,
    holiday_code VARCHAR(20) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_recurring TINYINT(1) NOT NULL DEFAULT 0,
    recurring_month TINYINT UNSIGNED DEFAULT NULL,
    recurring_day TINYINT UNSIGNED DEFAULT NULL,
    holiday_type ENUM('national','religious','school','emergency','other') NOT NULL DEFAULT 'school',
    description TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_holidays_uuid (uuid),
    UNIQUE KEY uq_attendance_holidays_code (school_id, holiday_code),
    INDEX idx_ah_dates (start_date, end_date),
    INDEX idx_ah_school_active (school_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 9. ATTENDANCE SESSIONS
-- Daily attendance sessions (morning, afternoon, evening, etc.)
-- ================================================================
DROP TABLE IF EXISTS attendance_sessions;
CREATE TABLE attendance_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    campus_id BIGINT UNSIGNED DEFAULT NULL,
    class_section_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL for staff attendance',
    calendar_id BIGINT UNSIGNED DEFAULT NULL,
    session_date DATE NOT NULL,
    session_type VARCHAR(50) NOT NULL COMMENT 'morning, afternoon, evening, etc.',
    session_code VARCHAR(20) NOT NULL,
    start_time TIME DEFAULT NULL,
    end_time TIME DEFAULT NULL,
    start_tolerance_minutes INT UNSIGNED DEFAULT 15,
    end_tolerance_minutes INT UNSIGNED DEFAULT 15,
    is_mandatory TINYINT(1) NOT NULL DEFAULT 1,
    total_expected INT UNSIGNED NOT NULL DEFAULT 0,
    total_present INT UNSIGNED NOT NULL DEFAULT 0,
    total_absent INT UNSIGNED NOT NULL DEFAULT 0,
    total_late INT UNSIGNED NOT NULL DEFAULT 0,
    total_excused INT UNSIGNED NOT NULL DEFAULT 0,
    is_completed TINYINT(1) NOT NULL DEFAULT 0,
    completed_date TIMESTAMP NULL DEFAULT NULL,
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    locked_date TIMESTAMP NULL DEFAULT NULL,
    locked_by BIGINT UNSIGNED DEFAULT NULL,
    notes TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_sessions_uuid (uuid),
    UNIQUE KEY uq_attendance_sessions_class_date (school_id, class_section_id, session_date, session_type),
    INDEX idx_ase_date_type (session_date, session_type),
    INDEX idx_ase_class_date (class_section_id, session_date),
    INDEX idx_ase_completed (is_completed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 10. STUDENT ATTENDANCE
-- Individual student attendance records
-- ================================================================
DROP TABLE IF EXISTS student_attendance;
CREATE TABLE student_attendance (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    campus_id BIGINT UNSIGNED DEFAULT NULL,
    session_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    status_id BIGINT UNSIGNED NOT NULL,
    method_id BIGINT UNSIGNED NOT NULL COMMENT 'How attendance was captured',
    device_id BIGINT UNSIGNED DEFAULT NULL,
    location_id BIGINT UNSIGNED DEFAULT NULL,
    check_in_time DATETIME DEFAULT NULL,
    check_out_time DATETIME DEFAULT NULL,
    check_in_latitude DECIMAL(10,8) DEFAULT NULL,
    check_in_longitude DECIMAL(11,8) DEFAULT NULL,
    check_out_latitude DECIMAL(10,8) DEFAULT NULL,
    check_out_longitude DECIMAL(11,8) DEFAULT NULL,
    is_late TINYINT(1) NOT NULL DEFAULT 0,
    late_minutes INT UNSIGNED DEFAULT NULL,
    is_excused TINYINT(1) NOT NULL DEFAULT 0,
    excused_reason TEXT,
    excused_by BIGINT UNSIGNED DEFAULT NULL,
    excused_date TIMESTAMP NULL DEFAULT NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    verified_by BIGINT UNSIGNED DEFAULT NULL,
    verified_date TIMESTAMP NULL DEFAULT NULL,
    is_synced TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Synced with central system',
    sync_date TIMESTAMP NULL DEFAULT NULL,
    sync_token VARCHAR(255) DEFAULT NULL,
    notes TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_student_attendance_uuid (uuid),
    UNIQUE KEY uq_student_attendance_session (session_id, student_id),
    INDEX idx_sa_student_date (student_id, check_in_time),
    INDEX idx_sa_session (session_id),
    INDEX idx_sa_status (status_id),
    INDEX idx_sa_sync (is_synced),
    INDEX idx_sa_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 11. STAFF ATTENDANCE
-- Individual staff attendance records
-- ================================================================
DROP TABLE IF EXISTS staff_attendance;
CREATE TABLE staff_attendance (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    campus_id BIGINT UNSIGNED DEFAULT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    status_id BIGINT UNSIGNED NOT NULL,
    method_id BIGINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED DEFAULT NULL,
    location_id BIGINT UNSIGNED DEFAULT NULL,
    attendance_date DATE NOT NULL,
    check_in_time DATETIME DEFAULT NULL,
    check_out_time DATETIME DEFAULT NULL,
    check_in_latitude DECIMAL(10,8) DEFAULT NULL,
    check_in_longitude DECIMAL(11,8) DEFAULT NULL,
    check_out_latitude DECIMAL(10,8) DEFAULT NULL,
    check_out_longitude DECIMAL(11,8) DEFAULT NULL,
    is_late TINYINT(1) NOT NULL DEFAULT 0,
    late_minutes INT UNSIGNED DEFAULT NULL,
    is_excused TINYINT(1) NOT NULL DEFAULT 0,
    excused_reason TEXT,
    excused_by BIGINT UNSIGNED DEFAULT NULL,
    excused_date TIMESTAMP NULL DEFAULT NULL,
    overtime_hours DECIMAL(4,2) DEFAULT NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    verified_by BIGINT UNSIGNED DEFAULT NULL,
    verified_date TIMESTAMP NULL DEFAULT NULL,
    is_synced TINYINT(1) NOT NULL DEFAULT 1,
    sync_date TIMESTAMP NULL DEFAULT NULL,
    sync_token VARCHAR(255) DEFAULT NULL,
    notes TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_attendance_uuid (uuid),
    UNIQUE KEY uq_staff_attendance_date (staff_id, attendance_date),
    INDEX idx_sf_staff_date (staff_id, attendance_date),
    INDEX idx_sf_sync (is_synced),
    INDEX idx_sf_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 12. ATTENDANCE LOGS (Audit Trail)
-- ================================================================
DROP TABLE IF EXISTS attendance_logs;
CREATE TABLE attendance_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    table_name VARCHAR(50) NOT NULL,
    record_id BIGINT UNSIGNED NOT NULL,
    action_type ENUM('INSERT','UPDATE','DELETE','VIEW') NOT NULL,
    old_data JSON DEFAULT NULL,
    new_data JSON DEFAULT NULL,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_logs_uuid (uuid),
    INDEX idx_table_record (table_name, record_id),
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 13. ATTENDANCE SYNC QUEUE
-- Offline attendance synchronization
-- ================================================================
DROP TABLE IF EXISTS attendance_sync_queue;
CREATE TABLE attendance_sync_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    sync_type ENUM('student','staff','device','biometric','card','qr','manual') NOT NULL,
    sync_operation ENUM('create','update','delete') NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL COMMENT 'Reference ID of the entity',
    entity_data JSON NOT NULL COMMENT 'Full entity data',
    sync_priority INT NOT NULL DEFAULT 0 COMMENT 'Higher = higher priority',
    retry_count INT NOT NULL DEFAULT 0,
    max_retries INT NOT NULL DEFAULT 3,
    status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    error_message TEXT,
    processed_date TIMESTAMP NULL DEFAULT NULL,
    device_id BIGINT UNSIGNED DEFAULT NULL,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_sync_queue_uuid (uuid),
    INDEX idx_asq_status (status),
    INDEX idx_asq_created (created_at),
    INDEX idx_asq_priority (sync_priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 14. ATTENDANCE DEVICE LOGS
-- Raw logs from biometric devices
-- ================================================================
DROP TABLE IF EXISTS attendance_device_logs;
CREATE TABLE attendance_device_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    log_timestamp DATETIME NOT NULL,
    log_type ENUM('checkin','checkout','event','error') NOT NULL DEFAULT 'checkin',
    identifier VARCHAR(100) DEFAULT NULL COMMENT 'Student/Staff identifier',
    raw_data TEXT,
    processed_data JSON DEFAULT NULL,
    match_status ENUM('matched','unmatched','pending') NOT NULL DEFAULT 'pending',
    confidence_score DECIMAL(5,2) DEFAULT NULL,
    matched_student_id BIGINT UNSIGNED DEFAULT NULL,
    matched_staff_id BIGINT UNSIGNED DEFAULT NULL,
    processed TINYINT(1) NOT NULL DEFAULT 0,
    processed_date TIMESTAMP NULL DEFAULT NULL,
    error_message TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_device_logs_uuid (uuid),
    INDEX idx_adt_device_time (device_id, log_timestamp),
    INDEX idx_adt_match_status (match_status),
    INDEX idx_adt_processed (processed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 15. ATTENDANCE NOTIFICATIONS
-- Automated attendance notifications
-- ================================================================
DROP TABLE IF EXISTS attendance_notifications;
CREATE TABLE attendance_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    campus_id BIGINT UNSIGNED DEFAULT NULL,
    student_id BIGINT UNSIGNED DEFAULT NULL,
    staff_id BIGINT UNSIGNED DEFAULT NULL,
    parent_id BIGINT UNSIGNED DEFAULT NULL,
    notification_type ENUM('absence','late','risk','reminder','weekly','monthly','alert','custom') NOT NULL DEFAULT 'absence',
    channel ENUM('sms','email','push','in_app','whatsapp') NOT NULL DEFAULT 'sms',
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    reference_type VARCHAR(50) DEFAULT NULL COMMENT 'e.g., attendance_id, session_id',
    reference_id BIGINT UNSIGNED DEFAULT NULL,
    is_sent TINYINT(1) NOT NULL DEFAULT 0,
    sent_date TIMESTAMP NULL DEFAULT NULL,
    is_delivered TINYINT(1) NOT NULL DEFAULT 0,
    delivered_date TIMESTAMP NULL DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_date TIMESTAMP NULL DEFAULT NULL,
    delivery_status ENUM('pending','sent','failed','delivered') NOT NULL DEFAULT 'pending',
    error_message TEXT,
    retry_count INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_notifications_uuid (uuid),
    INDEX idx_an_student (student_id),
    INDEX idx_an_sent (is_sent),
    INDEX idx_an_type (notification_type),
    INDEX idx_an_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 16. ATTENDANCE ALERTS
-- Generated alerts based on rules
-- ================================================================
DROP TABLE IF EXISTS attendance_alerts;
CREATE TABLE attendance_alerts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    campus_id BIGINT UNSIGNED DEFAULT NULL,
    alert_type ENUM('absent','late','risk','chronic_absent','consecutive_absent','attendance_drop','rule_trigger') NOT NULL,
    severity ENUM('info','warning','critical','emergency') NOT NULL DEFAULT 'warning',
    student_id BIGINT UNSIGNED DEFAULT NULL,
    staff_id BIGINT UNSIGNED DEFAULT NULL,
    rule_id BIGINT UNSIGNED DEFAULT NULL,
    message TEXT NOT NULL,
    recommendation TEXT,
    is_resolved TINYINT(1) NOT NULL DEFAULT 0,
    resolved_date TIMESTAMP NULL DEFAULT NULL,
    resolved_by BIGINT UNSIGNED DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_alerts_uuid (uuid),
    INDEX idx_ala_student (student_id),
    INDEX idx_ala_resolved (is_resolved),
    INDEX idx_ala_severity (severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 17. ATTENDANCE STATISTICS
-- Aggregate attendance statistics
-- ================================================================
DROP TABLE IF EXISTS attendance_statistics;
CREATE TABLE attendance_statistics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    campus_id BIGINT UNSIGNED DEFAULT NULL,
    class_section_id BIGINT UNSIGNED DEFAULT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    term_id BIGINT UNSIGNED NOT NULL,
    statistic_date DATE NOT NULL,
    total_students INT UNSIGNED NOT NULL DEFAULT 0,
    present_students INT UNSIGNED NOT NULL DEFAULT 0,
    absent_students INT UNSIGNED NOT NULL DEFAULT 0,
    late_students INT UNSIGNED NOT NULL DEFAULT 0,
    excused_students INT UNSIGNED NOT NULL DEFAULT 0,
    attendance_rate DECIMAL(5,2) DEFAULT 0.00,
    daily_trend DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Daily trend change',
    is_calculated TINYINT(1) NOT NULL DEFAULT 0,
    calculated_date TIMESTAMP NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_statistics_uuid (uuid),
    UNIQUE KEY uq_attendance_statistics_date (class_section_id, statistic_date),
    INDEX idx_ast_date (statistic_date),
    INDEX idx_ast_class_date (class_section_id, statistic_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 18. ATTENDANCE RISK PROFILES
-- Student risk assessment
-- ================================================================
DROP TABLE IF EXISTS attendance_risk_profiles;
CREATE TABLE attendance_risk_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    campus_id BIGINT UNSIGNED DEFAULT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    term_id BIGINT UNSIGNED NOT NULL,
    risk_level ENUM('low','moderate','high','critical') NOT NULL DEFAULT 'low',
    risk_score DECIMAL(5,2) DEFAULT 0.00 COMMENT '0-100 risk score',
    attendance_percentage DECIMAL(5,2) DEFAULT 0.00,
    consecutive_absences INT UNSIGNED NOT NULL DEFAULT 0,
    total_absences INT UNSIGNED NOT NULL DEFAULT 0,
    total_lates INT UNSIGNED NOT NULL DEFAULT 0,
    declining_trend TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Attendance declining',
    intervention_required TINYINT(1) NOT NULL DEFAULT 0,
    intervention_date TIMESTAMP NULL DEFAULT NULL,
    intervention_type VARCHAR(50) DEFAULT NULL,
    recommendation TEXT,
    notified_guardian TINYINT(1) NOT NULL DEFAULT 0,
    notified_date TIMESTAMP NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_risk_profiles_uuid (uuid),
    UNIQUE KEY uq_attendance_risk_profiles_student (student_id, academic_year_id, term_id),
    INDEX idx_arp_risk (risk_level),
    INDEX idx_arp_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 19. ATTENDANCE RULES
-- Configurable attendance rules
-- ================================================================
DROP TABLE IF EXISTS attendance_rules;
CREATE TABLE attendance_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    campus_id BIGINT UNSIGNED DEFAULT NULL,
    grade_level_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = all levels',
    rule_name VARCHAR(100) NOT NULL,
    rule_code VARCHAR(20) NOT NULL,
    late_threshold_minutes INT UNSIGNED DEFAULT 15,
    absent_threshold_minutes INT UNSIGNED DEFAULT 60,
    max_absences_allowed INT UNSIGNED DEFAULT 10,
    max_lates_allowed INT UNSIGNED DEFAULT 5,
    min_attendance_percentage DECIMAL(5,2) DEFAULT 75.00,
    max_consecutive_absences INT UNSIGNED DEFAULT 3 COMMENT 'Alert after X consecutive absences',
    notification_after_absences INT UNSIGNED DEFAULT 3,
    require_parent_notification TINYINT(1) NOT NULL DEFAULT 1,
    require_doctor_note_after_days INT UNSIGNED DEFAULT 3,
    auto_mark_absent_after_minutes INT UNSIGNED DEFAULT 30,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_rules_uuid (uuid),
    UNIQUE KEY uq_attendance_rules_code (school_id, rule_code),
    INDEX idx_ar_school_active (school_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 20. ATTENDANCE RULE CONDITIONS
-- Complex rule conditions
-- ================================================================
DROP TABLE IF EXISTS attendance_rule_conditions;
CREATE TABLE attendance_rule_conditions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    rule_id BIGINT UNSIGNED NOT NULL,
    condition_type ENUM('and','or','not') NOT NULL DEFAULT 'and',
    field VARCHAR(50) NOT NULL COMMENT 'e.g., total_absences, consecutive_absences',
    operator ENUM('eq','ne','gt','lt','gte','lte','between','in','contains') NOT NULL,
    value TEXT NOT NULL,
    value_type ENUM('number','string','boolean','date','time') NOT NULL DEFAULT 'number',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_rule_conditions_uuid (uuid),
    INDEX idx_arc_rule (rule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 21. ATTENDANCE SUMMARY
-- Student attendance summaries by term
-- ================================================================
DROP TABLE IF EXISTS attendance_summary;
CREATE TABLE attendance_summary (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    campus_id BIGINT UNSIGNED DEFAULT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    term_id BIGINT UNSIGNED NOT NULL,
    class_section_id BIGINT UNSIGNED NOT NULL,
    total_sessions INT UNSIGNED NOT NULL DEFAULT 0,
    present_days INT UNSIGNED NOT NULL DEFAULT 0,
    absent_days INT UNSIGNED NOT NULL DEFAULT 0,
    late_days INT UNSIGNED NOT NULL DEFAULT 0,
    excused_days INT UNSIGNED NOT NULL DEFAULT 0,
    medical_days INT UNSIGNED NOT NULL DEFAULT 0,
    permission_days INT UNSIGNED NOT NULL DEFAULT 0,
    attendance_percentage DECIMAL(5,2) DEFAULT 0.00,
    attendance_score DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Weighted attendance score',
    consecutive_absences INT UNSIGNED NOT NULL DEFAULT 0,
    max_consecutive_absences INT UNSIGNED NOT NULL DEFAULT 0,
    is_calculated TINYINT(1) NOT NULL DEFAULT 0,
    calculated_date TIMESTAMP NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_summary_uuid (uuid),
    UNIQUE KEY uq_attendance_summary_student (student_id, academic_year_id, term_id),
    INDEX idx_as_student (student_id),
    INDEX idx_as_year_term (academic_year_id, term_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 22. ATTENDANCE EXCEPTIONS
-- Special attendance exceptions
-- ================================================================
DROP TABLE IF EXISTS attendance_exceptions;
CREATE TABLE attendance_exceptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    campus_id BIGINT UNSIGNED DEFAULT NULL,
    student_id BIGINT UNSIGNED DEFAULT NULL,
    staff_id BIGINT UNSIGNED DEFAULT NULL,
    exception_date DATE NOT NULL,
    start_time TIME DEFAULT NULL,
    end_time TIME DEFAULT NULL,
    exception_type ENUM('medical','emergency','family','official','other') NOT NULL DEFAULT 'other',
    reason TEXT NOT NULL,
    supporting_document VARCHAR(500) DEFAULT NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    approved_by BIGINT UNSIGNED DEFAULT NULL,
    approved_date TIMESTAMP NULL DEFAULT NULL,
    rejected_by BIGINT UNSIGNED DEFAULT NULL,
    rejected_date TIMESTAMP NULL DEFAULT NULL,
    rejection_reason TEXT,
    notes TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_exceptions_uuid (uuid),
    INDEX idx_ae_student_date (student_id, exception_date),
    INDEX idx_ae_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 23. ATTENDANCE ADJUSTMENTS
-- Manual adjustments to attendance records
-- ================================================================
DROP TABLE IF EXISTS attendance_adjustments;
CREATE TABLE attendance_adjustments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    attendance_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'Reference to student_attendance or staff_attendance',
    attendance_type ENUM('student','staff') NOT NULL,
    old_status_id BIGINT UNSIGNED DEFAULT NULL,
    new_status_id BIGINT UNSIGNED NOT NULL,
    adjustment_reason TEXT NOT NULL,
    adjustment_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    adjusted_by BIGINT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED DEFAULT NULL,
    approved_date TIMESTAMP NULL DEFAULT NULL,
    notes TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_adjustments_uuid (uuid),
    INDEX idx_adj_attendance (attendance_id),
    INDEX idx_adj_date (adjustment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 24. ATTENDANCE APPROVAL WORKFLOWS
-- Workflow definitions for attendance approvals
-- ================================================================
DROP TABLE IF EXISTS attendance_approval_workflows;
CREATE TABLE attendance_approval_workflows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    workflow_name VARCHAR(100) NOT NULL,
    workflow_code VARCHAR(20) NOT NULL,
    entity_type ENUM('exception','adjustment','absence','late','excuse') NOT NULL,
    approval_levels JSON NOT NULL COMMENT 'Array of approval levels',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_approval_workflows_uuid (uuid),
    UNIQUE KEY uq_attendance_approval_workflows_code (school_id, workflow_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 25. ATTENDANCE API TOKENS
-- API tokens for mobile apps and devices
-- ================================================================
DROP TABLE IF EXISTS attendance_api_tokens;
CREATE TABLE attendance_api_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    token_name VARCHAR(100) NOT NULL,
    token_key VARCHAR(255) NOT NULL,
    token_secret VARCHAR(255) NOT NULL,
    token_type ENUM('device','app','user','system') NOT NULL DEFAULT 'app',
    expires_at DATETIME DEFAULT NULL,
    last_used TIMESTAMP NULL DEFAULT NULL,
    ip_whitelist JSON DEFAULT NULL,
    permissions JSON DEFAULT NULL COMMENT 'API permission set',
    is_revoked TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_api_tokens_uuid (uuid),
    UNIQUE KEY uq_attendance_api_tokens_key (token_key),
    INDEX idx_aat_token_key (token_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 26. ATTENDANCE BIOMETRIC TEMPLATES
-- Student biometric data (encrypted)
-- ================================================================
DROP TABLE IF EXISTS attendance_biometric_templates;
CREATE TABLE attendance_biometric_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED DEFAULT NULL,
    staff_id BIGINT UNSIGNED DEFAULT NULL,
    device_type_id BIGINT UNSIGNED NOT NULL,
    biometric_type ENUM('fingerprint','facial','iris','voice') NOT NULL DEFAULT 'fingerprint',
    biometric_template LONGTEXT NOT NULL COMMENT 'Encrypted template data',
    template_hash VARCHAR(255) NOT NULL COMMENT 'Hash for deduplication',
    finger_position ENUM('left_thumb','left_index','left_middle','left_ring','left_pinky','right_thumb','right_index','right_middle','right_ring','right_pinky') DEFAULT NULL,
    template_format VARCHAR(50) DEFAULT NULL COMMENT 'e.g., ISO19794, ANSI378',
    template_version VARCHAR(20) DEFAULT NULL,
    quality_score DECIMAL(5,2) DEFAULT NULL COMMENT 'Biometric quality score',
    enrollment_date DATETIME NOT NULL,
    expiry_date DATE DEFAULT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    enrolled_by BIGINT UNSIGNED DEFAULT NULL,
    notes TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_biometric_templates_uuid (uuid),
    UNIQUE KEY uq_attendance_biometric_student (student_id, biometric_type, finger_position),
    UNIQUE KEY uq_attendance_biometric_staff (staff_id, biometric_type, finger_position),
    INDEX idx_abt_student (student_id),
    INDEX idx_abt_staff (staff_id),
    INDEX idx_abt_type (biometric_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 27. ATTENDANCE RFID CARDS
-- RFID/NFC card management
-- ================================================================
DROP TABLE IF EXISTS attendance_rfid_cards;
CREATE TABLE attendance_rfid_cards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    card_number VARCHAR(50) NOT NULL,
    uid VARCHAR(50) NOT NULL COMMENT 'Card UID',
    student_id BIGINT UNSIGNED DEFAULT NULL,
    staff_id BIGINT UNSIGNED DEFAULT NULL,
    card_type ENUM('student','staff','visitor','temporary') NOT NULL DEFAULT 'student',
    issued_date DATE NOT NULL,
    expiry_date DATE DEFAULT NULL,
    issued_by BIGINT UNSIGNED DEFAULT NULL,
    is_lost TINYINT(1) NOT NULL DEFAULT 0,
    is_blocked TINYINT(1) NOT NULL DEFAULT 0,
    last_used TIMESTAMP NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_rfid_cards_uuid (uuid),
    UNIQUE KEY uq_attendance_rfid_cards_uid (school_id, uid),
    UNIQUE KEY uq_attendance_rfid_cards_number (school_id, card_number),
    INDEX idx_arc_uid (uid),
    INDEX idx_arc_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 28. ATTENDANCE QR CODES
-- QR code generation and management
-- ================================================================
DROP TABLE IF EXISTS attendance_qr_codes;
CREATE TABLE attendance_qr_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED DEFAULT NULL,
    staff_id BIGINT UNSIGNED DEFAULT NULL,
    session_id BIGINT UNSIGNED DEFAULT NULL,
    qr_code VARCHAR(255) NOT NULL,
    qr_content TEXT NOT NULL,
    purpose ENUM('student_checkin','staff_checkin','session_entry','course_attendance') NOT NULL DEFAULT 'student_checkin',
    expires_at DATETIME NOT NULL,
    is_used TINYINT(1) NOT NULL DEFAULT 0,
    used_at DATETIME DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_qr_codes_uuid (uuid),
    UNIQUE KEY uq_attendance_qr_codes_code (qr_code),
    INDEX idx_aqc_qr_code (qr_code),
    INDEX idx_aqc_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 29. ATTENDANCE REPORTS
-- Cached attendance reports
-- ================================================================
DROP TABLE IF EXISTS attendance_reports;
CREATE TABLE attendance_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    report_name VARCHAR(100) NOT NULL,
    report_code VARCHAR(20) NOT NULL,
    report_type ENUM('daily','weekly','monthly','term','year','custom') NOT NULL,
    parameters JSON COMMENT 'Report parameters',
    report_data LONGTEXT COMMENT 'JSON or serialized report data',
    file_path VARCHAR(500) COMMENT 'Path to generated file',
    file_type ENUM('pdf','excel','csv','html') DEFAULT NULL,
    generated_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    generated_by BIGINT UNSIGNED DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_reports_uuid (uuid),
    INDEX idx_arpt_type (report_type),
    INDEX idx_arpt_date (generated_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 30. INSERT SAMPLE DATA
-- ================================================================

-- Attendance Methods
INSERT IGNORE INTO attendance_methods (uuid, school_id, method_name, method_code, method_type, requires_hardware, is_biometric, supports_offline) VALUES
(UUID(), 1, 'Fingerprint', 'FP', 'biometric', 1, 1, 1),
(UUID(), 1, 'Facial Recognition', 'FACIAL', 'biometric', 1, 1, 1),
(UUID(), 1, 'RFID Card', 'RFID', 'card', 1, 0, 1),
(UUID(), 1, 'NFC Card', 'NFC', 'card', 1, 0, 1),
(UUID(), 1, 'QR Code', 'QR', 'qr', 0, 0, 1),
(UUID(), 1, 'Barcode', 'BARCODE', 'qr', 0, 0, 1),
(UUID(), 1, 'Manual Entry', 'MANUAL', 'manual', 0, 0, 1),
(UUID(), 1, 'Mobile App', 'MOBILE', 'mobile', 0, 0, 1),
(UUID(), 1, 'Tablet', 'TABLET', 'tablet', 0, 0, 1),
(UUID(), 1, 'Web Browser', 'WEB', 'web', 0, 0, 1),
(UUID(), 1, 'API Integration', 'API', 'api', 0, 0, 1);

-- Attendance Device Types
INSERT IGNORE INTO attendance_device_types (uuid, type_name, type_code, category, manufacturer, supports_offline, supports_biometric) VALUES
(UUID(), 'Fingerprint Scanner', 'FP_SCAN', 'fingerprint', 'ZKTeco', 1, 1),
(UUID(), 'Facial Recognition', 'FACIAL_CAM', 'facial', 'ZKTeco', 0, 1),
(UUID(), 'RFID Reader', 'RFID_READ', 'rfid', 'HID Global', 1, 0),
(UUID(), 'NFC Reader', 'NFC_READ', 'nfc', 'ACS', 1, 0),
(UUID(), 'QR Scanner', 'QR_SCAN', 'qr', 'Any', 1, 0),
(UUID(), 'Hybrid Device', 'HYBRID', 'hybrid', 'ZKTeco', 1, 1);

-- Attendance Statuses
INSERT IGNORE INTO attendance_statuses (uuid, school_id, status_name, status_code, category, is_present, is_absent, is_excused, is_late, is_default, color, icon, sort_order, notification_required, is_system) VALUES
(UUID(), 1, 'Present', 'P', 'present', 1, 0, 0, 0, 1, '#28a745', '✅', 1, 0, 1),
(UUID(), 1, 'Absent', 'A', 'absent', 0, 1, 0, 0, 0, '#dc3545', '❌', 2, 1, 1),
(UUID(), 1, 'Late', 'L', 'late', 1, 0, 0, 1, 0, '#ffc107', '⏰', 3, 0, 1),
(UUID(), 1, 'Excused', 'E', 'excused', 0, 0, 1, 0, 0, '#17a2b8', '📝', 4, 0, 1),
(UUID(), 1, 'Medical Leave', 'ML', 'excused', 0, 0, 1, 0, 0, '#6f42c1', '🏥', 5, 0, 1),
(UUID(), 1, 'Permission', 'PER', 'excused', 0, 0, 1, 0, 0, '#fd7e14', '📋', 6, 0, 1),
(UUID(), 1, 'Official Assignment', 'OA', 'excused', 0, 0, 1, 0, 0, '#20c997', '📌', 7, 0, 1),
(UUID(), 1, 'Holiday', 'H', 'holiday', 1, 0, 0, 0, 0, '#6c757d', '🎉', 8, 0, 1),
(UUID(), 1, 'Suspended', 'S', 'absent', 0, 1, 0, 0, 0, '#e83e8c', '🚫', 9, 1, 1);

-- Attendance Locations
INSERT IGNORE INTO attendance_locations (uuid, school_id, location_name, location_code, location_type, is_active) VALUES
(UUID(), 1, 'Main School Entrance', 'MAIN_ENT', 'entrance', 1),
(UUID(), 1, 'Administration Block', 'ADMIN', 'office', 1),
(UUID(), 1, 'Primary Block', 'PRIMARY', 'classroom', 1),
(UUID(), 1, 'JHS Block', 'JHS', 'classroom', 1),
(UUID(), 1, 'SHS Block', 'SHS', 'classroom', 1),
(UUID(), 1, 'Library', 'LIBRARY', 'library', 1),
(UUID(), 1, 'Science Lab', 'LAB', 'lab', 1),
(UUID(), 1, 'Assembly Hall', 'ASSEMBLY', 'assembly', 1),
(UUID(), 1, 'Boys Hostel', 'BOYS_HOSTEL', 'hostel', 1),
(UUID(), 1, 'Girls Hostel', 'GIRLS_HOSTEL', 'hostel', 1),
(UUID(), 1, 'Sports Field', 'SPORTS', 'sports', 1);

-- Attendance Rules
INSERT IGNORE INTO attendance_rules (uuid, school_id, rule_name, rule_code, late_threshold_minutes, absent_threshold_minutes, max_absences_allowed, max_lates_allowed, min_attendance_percentage, max_consecutive_absences, notification_after_absences, require_parent_notification) VALUES
(UUID(), 1, 'Default Attendance Rules', 'DEFAULT', 15, 60, 10, 5, 75.00, 3, 3, 1),
(UUID(), 1, 'Creche & Nursery Rules', 'CRECHE', 10, 30, 8, 4, 80.00, 2, 2, 1),
(UUID(), 1, 'Primary School Rules', 'PRIMARY', 15, 60, 10, 5, 75.00, 3, 3, 1),
(UUID(), 1, 'Secondary School Rules', 'SECONDARY', 15, 60, 12, 5, 70.00, 3, 3, 1);

-- Attendance Calendar
INSERT IGNORE INTO attendance_calendars (uuid, school_id, calendar_name, calendar_code, academic_year_id, term_id, start_date, end_date, days_per_week, school_start_time, school_end_time) VALUES
(UUID(), 1, '2026/2027 Academic Calendar', 'CAL_2627', 1, 1, '2026-01-15', '2027-12-15', 5, '07:30:00', '15:30:00');

-- Attendance Holidays
INSERT IGNORE INTO attendance_holidays (uuid, school_id, holiday_name, holiday_code, start_date, end_date, holiday_type) VALUES
(UUID(), 1, 'Independence Day', 'IND', '2026-03-06', '2026-03-06', 'national'),
(UUID(), 1, 'Easter Holidays', 'EASTER', '2026-04-10', '2026-04-14', 'religious'),
(UUID(), 1, 'May Day', 'MAY1', '2026-05-01', '2026-05-01', 'national'),
(UUID(), 1, 'Republic Day', 'REP', '2026-07-01', '2026-07-01', 'national'),
(UUID(), 1, 'Founders Day', 'FOUND', '2026-08-04', '2026-08-04', 'national'),
(UUID(), 1, 'Christmas Holidays', 'XMAS', '2026-12-20', '2027-01-05', 'religious');

-- Attendance API Tokens
INSERT IGNORE INTO attendance_api_tokens (uuid, school_id, token_name, token_key, token_secret, token_type, permissions) VALUES
(UUID(), 1, 'Main Entrance Device', CONCAT('dev_', MD5(RAND())), CONCAT('sec_', MD5(RAND())), 'device', '{"permissions": ["attendance.create", "attendance.read", "device.status"]}'),
(UUID(), 1, 'Staff Mobile App', CONCAT('mobile_', MD5(RAND())), CONCAT('sec_', MD5(RAND())), 'app', '{"permissions": ["attendance.create", "attendance.read", "attendance.update"]}');

-- ================================================================
-- 31. CREATE VIEWS
-- ================================================================

-- View: Student Attendance Detail
CREATE OR REPLACE VIEW vw_student_attendance_detail AS
SELECT 
    s.id AS student_id,
    s.first_name,
    s.last_name,
    s.admission_number,
    gl.level_name,
    cs.section_name,
    ay.year_name,
    at.term_name,
    astatus.status_name AS attendance_status,
    astatus.is_present,
    astatus.is_absent,
    astatus.is_excused,
    astatus.is_late,
    sa.check_in_time,
    sa.check_out_time,
    sa.is_late AS is_student_late,
    am.method_name AS capture_method,
    ad.device_name AS device_name,
    al.location_name AS location,
    ases.session_date,
    sa.is_verified
FROM student_attendance sa
JOIN students s ON sa.student_id = s.id
JOIN attendance_sessions ases ON sa.session_id = ases.id
JOIN class_sections cs ON ases.class_section_id = cs.id
JOIN grade_levels gl ON cs.grade_level_id = gl.id
JOIN academic_years ay ON ases.academic_year_id = ay.id
JOIN academic_terms at ON ases.academic_term_id = at.id
JOIN attendance_statuses astatus ON sa.status_id = astatus.id
LEFT JOIN attendance_methods am ON sa.method_id = am.id
LEFT JOIN attendance_devices ad ON sa.device_id = ad.id
LEFT JOIN attendance_locations al ON sa.location_id = al.id
WHERE sa.is_active = 1
ORDER BY ases.session_date DESC, s.first_name;

-- View: Student Attendance Summary
CREATE OR REPLACE VIEW vw_student_attendance_summary AS
SELECT 
    s.id AS student_id,
    s.first_name,
    s.last_name,
    s.admission_number,
    gl.level_name,
    cs.section_name,
    ay.year_name,
    at.term_name,
    COUNT(DISTINCT ases.id) AS total_sessions,
    SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) AS present_count,
    SUM(CASE WHEN astatus.is_absent = 1 THEN 1 ELSE 0 END) AS absent_count,
    SUM(CASE WHEN astatus.is_late = 1 THEN 1 ELSE 0 END) AS late_count,
    SUM(CASE WHEN astatus.is_excused = 1 THEN 1 ELSE 0 END) AS excused_count,
    ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) AS attendance_percentage
FROM students s
JOIN student_attendance sa ON s.id = sa.student_id
JOIN attendance_sessions ases ON sa.session_id = ases.id
JOIN class_sections cs ON ases.class_section_id = cs.id
JOIN grade_levels gl ON cs.grade_level_id = gl.id
JOIN academic_years ay ON ases.academic_year_id = ay.id
JOIN academic_terms at ON ases.academic_term_id = at.id
JOIN attendance_statuses astatus ON sa.status_id = astatus.id
WHERE sa.is_active = 1 AND s.is_active = 1
GROUP BY s.id, cs.id, ay.id, at.id;

-- View: Risk Analysis Report
CREATE OR REPLACE VIEW vw_attendance_risk_report AS
SELECT 
    s.id AS student_id,
    s.first_name,
    s.last_name,
    s.admission_number,
    gl.level_name,
    cs.section_name,
    SUM(CASE WHEN astatus.is_absent = 1 THEN 1 ELSE 0 END) AS total_absences,
    SUM(CASE WHEN astatus.is_late = 1 THEN 1 ELSE 0 END) AS total_lates,
    COUNT(DISTINCT ases.id) AS total_sessions,
    ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) AS attendance_percentage,
    CASE 
        WHEN ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) >= 80 THEN 'Low'
        WHEN ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) >= 60 THEN 'Moderate'
        WHEN ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) >= 40 THEN 'High'
        ELSE 'Critical'
    END AS risk_level,
    CASE 
        WHEN ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) >= 80 THEN 'Good standing - no intervention needed'
        WHEN ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) >= 60 THEN 'Monitor attendance - inform parents'
        WHEN ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) >= 40 THEN 'Intervention required - parent meeting'
        ELSE 'Urgent intervention required - immediate action'
    END AS recommendation
FROM students s
JOIN student_attendance sa ON s.id = sa.student_id
JOIN attendance_sessions ases ON sa.session_id = ases.id
JOIN class_sections cs ON ases.class_section_id = cs.id
JOIN grade_levels gl ON cs.grade_level_id = gl.id
JOIN attendance_statuses astatus ON sa.status_id = astatus.id
WHERE sa.is_active = 1 AND s.is_active = 1
GROUP BY s.id, cs.id;

-- View: Staff Attendance Summary
CREATE OR REPLACE VIEW vw_staff_attendance_summary AS
SELECT 
    st.id AS staff_id,
    p.first_name,
    p.last_name,
    st.staff_number,
    st.designation,
    COUNT(DISTINCT sa.attendance_date) AS total_days,
    SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) AS present_count,
    SUM(CASE WHEN astatus.is_absent = 1 THEN 1 ELSE 0 END) AS absent_count,
    SUM(CASE WHEN astatus.is_late = 1 THEN 1 ELSE 0 END) AS late_count,
    ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT sa.attendance_date), 0) * 100, 2) AS attendance_percentage
FROM staff st
JOIN people p ON st.person_id = p.id
JOIN staff_attendance sa ON st.id = sa.staff_id
JOIN attendance_statuses astatus ON sa.status_id = astatus.id
WHERE sa.is_active = 1 AND st.is_active = 1
GROUP BY st.id;

-- ================================================================
-- 32. STORED PROCEDURES
-- ================================================================

DELIMITER //

-- Procedure: Calculate Student Attendance Summary
CREATE OR REPLACE PROCEDURE sp_calculate_attendance_summary(
    IN p_student_id BIGINT UNSIGNED,
    IN p_term_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_year_id BIGINT UNSIGNED;
    
    SELECT academic_year_id INTO v_year_id 
    FROM academic_terms 
    WHERE id = p_term_id;
    
    DELETE FROM attendance_summary 
    WHERE student_id = p_student_id 
    AND term_id = p_term_id;
    
    INSERT INTO attendance_summary (
        uuid, school_id, student_id, academic_year_id, term_id, class_section_id,
        total_sessions, present_days, absent_days, late_days, excused_days,
        attendance_percentage, attendance_score, consecutive_absences,
        is_calculated, calculated_date
    )
    SELECT 
        UUID(), 1, p_student_id, v_year_id, p_term_id, 
        (SELECT class_section_id FROM student_enrollments WHERE student_id = p_student_id AND is_active = 1 LIMIT 1),
        COUNT(DISTINCT ases.id) AS total_sessions,
        SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) AS present_days,
        SUM(CASE WHEN astatus.is_absent = 1 THEN 1 ELSE 0 END) AS absent_days,
        SUM(CASE WHEN astatus.is_late = 1 THEN 1 ELSE 0 END) AS late_days,
        SUM(CASE WHEN astatus.is_excused = 1 THEN 1 ELSE 0 END) AS excused_days,
        ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) AS attendance_percentage,
        ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) AS attendance_score,
        0 AS consecutive_absences,
        1 AS is_calculated,
        NOW() AS calculated_date
    FROM student_attendance sa
    JOIN attendance_sessions ases ON sa.session_id = ases.id
    JOIN attendance_statuses astatus ON sa.status_id = astatus.id
    WHERE sa.student_id = p_student_id
    AND ases.academic_term_id = p_term_id
    AND sa.is_active = 1;
    
END //

-- Procedure: Record Biometric Check-in
CREATE OR REPLACE PROCEDURE sp_biometric_checkin(
    IN p_device_id BIGINT UNSIGNED,
    IN p_identifier VARCHAR(100),
    IN p_school_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_student_id BIGINT UNSIGNED;
    DECLARE v_session_id BIGINT UNSIGNED;
    DECLARE v_status_id BIGINT UNSIGNED;
    DECLARE v_method_id BIGINT UNSIGNED;
    
    -- Find student by identifier (admission number or RFID)
    SELECT id INTO v_student_id
    FROM students 
    WHERE (admission_number = p_identifier OR id = p_identifier)
    AND school_id = p_school_id
    AND is_active = 1
    LIMIT 1;
    
    IF v_student_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Student not found';
    END IF;
    
    -- Get current session
    SELECT id INTO v_session_id
    FROM attendance_sessions
    WHERE session_date = CURDATE()
    AND is_completed = 0
    AND is_active = 1
    LIMIT 1;
    
    IF v_session_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No active session found';
    END IF;
    
    -- Get status ID for Present
    SELECT id INTO v_status_id
    FROM attendance_statuses
    WHERE category = 'present'
    AND is_active = 1
    LIMIT 1;
    
    -- Get method ID for Fingerprint
    SELECT id INTO v_method_id
    FROM attendance_methods
    WHERE method_code = 'FP'
    AND is_active = 1
    LIMIT 1;
    
    IF v_method_id IS NULL THEN
        SELECT id INTO v_method_id
        FROM attendance_methods
        WHERE method_type = 'biometric'
        AND is_active = 1
        LIMIT 1;
    END IF;
    
    -- Insert or update attendance
    INSERT INTO student_attendance (
        uuid, school_id, session_id, student_id, status_id, method_id, device_id,
        check_in_time, is_active
    ) VALUES (
        UUID(), p_school_id, v_session_id, v_student_id, v_status_id, v_method_id, p_device_id,
        NOW(), 1
    ) ON DUPLICATE KEY UPDATE
        status_id = v_status_id,
        method_id = v_method_id,
        device_id = p_device_id,
        check_in_time = NOW(),
        updated_at = NOW();
    
    -- Return success
    SELECT v_student_id AS student_id, v_session_id AS session_id;
END //

DELIMITER ;

-- ================================================================
-- 33. ENABLE FOREIGN KEY CHECKS
-- ================================================================
SET FOREIGN_KEY_CHECKS = 1;

-- ================================================================
-- 34. VERIFY TABLES
-- ================================================================
SHOW TABLES LIKE 'attendance%';
SHOW TABLES LIKE '%_attendance';

SELECT '✅ Enterprise Attendance Module - All Tables Created Successfully!' AS Status;