-- ============================================
-- PLATFORM & TENANT MANAGEMENT MODULE
-- EduTrack Enterprise ERP
-- Version: 1.0
-- ============================================

USE student_performance_system;

-- ============================================
-- DROP TABLES IN CORRECT ORDER (Child tables first)
-- ============================================
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS tenant_storage_usage;
DROP TABLE IF EXISTS tenant_usage_statistics;
DROP TABLE IF EXISTS platform_audit_logs;
DROP TABLE IF EXISTS platform_sessions;
DROP TABLE IF EXISTS tenant_payment_transactions;
DROP TABLE IF EXISTS tenant_payment_methods;
DROP TABLE IF EXISTS tenant_payments;
DROP TABLE IF EXISTS tenant_invoice_items;
DROP TABLE IF EXISTS tenant_invoices;
DROP TABLE IF EXISTS tenant_billing_accounts;
DROP TABLE IF EXISTS campuses;
DROP TABLE IF EXISTS schools;
DROP TABLE IF EXISTS tenant_modules;
DROP TABLE IF EXISTS tenant_domains;
DROP TABLE IF EXISTS tenant_subscriptions;
DROP TABLE IF EXISTS tenant_settings;
DROP TABLE IF EXISTS tenants;
DROP TABLE IF EXISTS subscription_plans;
DROP TABLE IF EXISTS platform_user_roles;
DROP TABLE IF EXISTS platform_role_permissions;
DROP TABLE IF EXISTS platform_permissions;
DROP TABLE IF EXISTS platform_roles;
DROP TABLE IF EXISTS platform_users;
DROP TABLE IF EXISTS platform_system_settings;
DROP TABLE IF EXISTS languages;
DROP TABLE IF EXISTS currencies;
DROP TABLE IF EXISTS countries;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- 1. COUNTRIES TABLE
-- ============================================
CREATE TABLE countries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    country_name VARCHAR(100) NOT NULL,
    country_code VARCHAR(2) NOT NULL,
    country_code_3 VARCHAR(3) DEFAULT NULL,
    phone_code VARCHAR(10) DEFAULT NULL,
    currency VARCHAR(3) DEFAULT NULL,
    currency_symbol VARCHAR(10) DEFAULT NULL,
    timezone VARCHAR(50) DEFAULT NULL,
    date_format VARCHAR(20) DEFAULT 'Y-m-d',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_countries_uuid (uuid),
    UNIQUE KEY uq_countries_code (country_code),
    INDEX idx_countries_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. CURRENCIES TABLE
-- ============================================
CREATE TABLE currencies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    currency_name VARCHAR(100) NOT NULL,
    currency_code VARCHAR(3) NOT NULL,
    currency_symbol VARCHAR(10) DEFAULT NULL,
    decimal_places INT UNSIGNED DEFAULT 2,
    exchange_rate DECIMAL(15,6) DEFAULT 1.000000,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_currencies_uuid (uuid),
    UNIQUE KEY uq_currencies_code (currency_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. LANGUAGES TABLE
-- ============================================
CREATE TABLE languages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    language_name VARCHAR(100) NOT NULL,
    language_code VARCHAR(10) NOT NULL,
    locale VARCHAR(20) DEFAULT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_languages_uuid (uuid),
    UNIQUE KEY uq_languages_code (language_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. PLATFORM USERS
-- ============================================
CREATE TABLE platform_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(200) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    lockout_until TIMESTAMP NULL DEFAULT NULL,
    last_login TIMESTAMP NULL DEFAULT NULL,
    last_ip VARCHAR(45) DEFAULT NULL,
    password_changed_at TIMESTAMP NULL DEFAULT NULL,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_users_uuid (uuid),
    UNIQUE KEY uq_platform_users_email (email),
    UNIQUE KEY uq_platform_users_username (username),
    INDEX idx_platform_users_is_active (is_active),
    INDEX idx_platform_users_created_by (created_by),
    CONSTRAINT fk_platform_users_created_by FOREIGN KEY (created_by) REFERENCES platform_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. PLATFORM ROLES
-- ============================================
CREATE TABLE platform_roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    role_name VARCHAR(100) NOT NULL,
    role_code VARCHAR(50) NOT NULL,
    description TEXT DEFAULT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_roles_uuid (uuid),
    UNIQUE KEY uq_platform_roles_code (role_code),
    INDEX idx_platform_roles_is_active (is_active),
    CONSTRAINT fk_platform_roles_created_by FOREIGN KEY (created_by) REFERENCES platform_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. PLATFORM PERMISSIONS
-- ============================================
CREATE TABLE platform_permissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    permission_name VARCHAR(100) NOT NULL,
    permission_code VARCHAR(100) NOT NULL,
    resource VARCHAR(100) NOT NULL,
    action VARCHAR(50) NOT NULL,
    description TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_permissions_uuid (uuid),
    UNIQUE KEY uq_platform_permissions_code (permission_code),
    INDEX idx_platform_permissions_resource (resource),
    INDEX idx_platform_permissions_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 7. PLATFORM ROLE PERMISSIONS
-- ============================================
CREATE TABLE platform_role_permissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_role_permissions_uuid (uuid),
    UNIQUE KEY uq_platform_role_perms (role_id, permission_id),
    CONSTRAINT fk_platform_rp_role FOREIGN KEY (role_id) REFERENCES platform_roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_platform_rp_permission FOREIGN KEY (permission_id) REFERENCES platform_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 8. PLATFORM USER ROLES
-- ============================================
CREATE TABLE platform_user_roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_user_roles_uuid (uuid),
    UNIQUE KEY uq_platform_user_role (user_id, role_id),
    CONSTRAINT fk_platform_ur_user FOREIGN KEY (user_id) REFERENCES platform_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_platform_ur_role FOREIGN KEY (role_id) REFERENCES platform_roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 9. PLATFORM SYSTEM SETTINGS
-- ============================================
CREATE TABLE platform_system_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    setting_category VARCHAR(50) NOT NULL DEFAULT 'general',
    data_type VARCHAR(20) DEFAULT 'string',
    is_editable TINYINT(1) NOT NULL DEFAULT 1,
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    description TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_system_settings_uuid (uuid),
    UNIQUE KEY uq_platform_system_settings_key (setting_key),
    INDEX idx_platform_system_settings_category (setting_category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 10. SUBSCRIPTION PLANS
-- ============================================
CREATE TABLE subscription_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    plan_name VARCHAR(100) NOT NULL,
    plan_code VARCHAR(50) NOT NULL,
    description TEXT DEFAULT NULL,
    duration_type ENUM('trial', 'monthly', 'quarterly', 'annual', 'enterprise', 'custom') NOT NULL,
    duration_months INT UNSIGNED DEFAULT NULL,
    price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(3) DEFAULT 'GHS',
    max_students INT UNSIGNED DEFAULT 0,
    max_staff INT UNSIGNED DEFAULT 0,
    max_campuses INT UNSIGNED DEFAULT 1,
    max_storage_mb BIGINT UNSIGNED DEFAULT 10240,
    max_api_calls INT UNSIGNED DEFAULT 10000,
    max_ai_requests INT UNSIGNED DEFAULT 100,
    sms_balance INT UNSIGNED DEFAULT 0,
    email_balance INT UNSIGNED DEFAULT 0,
    is_trial TINYINT(1) NOT NULL DEFAULT 0,
    trial_days INT UNSIGNED DEFAULT 0,
    grace_period_days INT UNSIGNED DEFAULT 7,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subscription_plans_uuid (uuid),
    UNIQUE KEY uq_subscription_plans_code (plan_code),
    INDEX idx_subscription_plans_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 11. TENANTS
-- ============================================
CREATE TABLE tenants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    tenant_name VARCHAR(200) NOT NULL,
    tenant_code VARCHAR(50) NOT NULL,
    legal_name VARCHAR(200) NOT NULL,
    institution_type VARCHAR(100) DEFAULT NULL,
    country_id BIGINT UNSIGNED DEFAULT NULL,
    region VARCHAR(100) DEFAULT NULL,
    district VARCHAR(100) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    digital_address VARCHAR(100) DEFAULT NULL,
    postal_address TEXT DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(200) DEFAULT NULL,
    website VARCHAR(200) DEFAULT NULL,
    logo_url VARCHAR(500) DEFAULT NULL,
    language VARCHAR(10) DEFAULT 'en',
    timezone VARCHAR(50) DEFAULT 'UTC',
    currency VARCHAR(3) DEFAULT 'GHS',
    date_format VARCHAR(20) DEFAULT 'Y-m-d',
    academic_calendar_type VARCHAR(50) DEFAULT 'semester',
    status ENUM('pending', 'active', 'suspended', 'expired', 'deleted') NOT NULL DEFAULT 'pending',
    max_students INT UNSIGNED DEFAULT 0,
    max_staff INT UNSIGNED DEFAULT 0,
    max_campuses INT UNSIGNED DEFAULT 1,
    max_storage_mb BIGINT UNSIGNED DEFAULT 10240,
    max_api_calls INT UNSIGNED DEFAULT 10000,
    max_ai_requests INT UNSIGNED DEFAULT 100,
    sms_balance INT UNSIGNED DEFAULT 0,
    email_balance INT UNSIGNED DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    approved_by BIGINT UNSIGNED DEFAULT NULL,
    approved_at TIMESTAMP NULL DEFAULT NULL,
    suspended_by BIGINT UNSIGNED DEFAULT NULL,
    suspended_at TIMESTAMP NULL DEFAULT NULL,
    suspension_reason TEXT DEFAULT NULL,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenants_uuid (uuid),
    UNIQUE KEY uq_tenants_code (tenant_code),
    INDEX idx_tenants_status (status),
    INDEX idx_tenants_is_active (is_active),
    INDEX idx_tenants_country (country_id),
    CONSTRAINT fk_tenants_approved_by FOREIGN KEY (approved_by) REFERENCES platform_users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tenants_suspended_by FOREIGN KEY (suspended_by) REFERENCES platform_users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tenants_created_by FOREIGN KEY (created_by) REFERENCES platform_users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tenants_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 12. TENANT SETTINGS
-- ============================================
CREATE TABLE tenant_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    setting_category VARCHAR(50) DEFAULT 'general',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_settings_uuid (uuid),
    UNIQUE KEY uq_tenant_settings_key (tenant_id, setting_key),
    INDEX idx_tenant_settings_category (setting_category),
    CONSTRAINT fk_tenant_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 13. TENANT SUBSCRIPTIONS
-- ============================================
CREATE TABLE tenant_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    subscription_number VARCHAR(50) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    renewal_date DATE DEFAULT NULL,
    grace_period_end DATE DEFAULT NULL,
    status ENUM('active', 'expired', 'cancelled', 'suspended', 'grace_period') NOT NULL DEFAULT 'active',
    is_auto_renew TINYINT(1) NOT NULL DEFAULT 1,
    price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(3) DEFAULT 'GHS',
    discount_amount DECIMAL(15,2) DEFAULT 0.00,
    tax_amount DECIMAL(15,2) DEFAULT 0.00,
    total_amount DECIMAL(15,2) DEFAULT 0.00,
    payment_interval VARCHAR(20) DEFAULT 'monthly',
    max_students INT UNSIGNED DEFAULT 0,
    max_staff INT UNSIGNED DEFAULT 0,
    max_campuses INT UNSIGNED DEFAULT 1,
    max_storage_mb BIGINT UNSIGNED DEFAULT 10240,
    max_api_calls INT UNSIGNED DEFAULT 10000,
    max_ai_requests INT UNSIGNED DEFAULT 100,
    sms_balance INT UNSIGNED DEFAULT 0,
    email_balance INT UNSIGNED DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_subscriptions_uuid (uuid),
    UNIQUE KEY uq_tenant_subscriptions_number (subscription_number),
    INDEX idx_tenant_subscriptions_tenant (tenant_id),
    INDEX idx_tenant_subscriptions_plan (plan_id),
    INDEX idx_tenant_subscriptions_status (status),
    INDEX idx_tenant_subscriptions_dates (start_date, end_date),
    CONSTRAINT fk_tenant_subscriptions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_tenant_subscriptions_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 14. TENANT DOMAINS
-- ============================================
CREATE TABLE tenant_domains (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    domain_name VARCHAR(200) NOT NULL,
    domain_type ENUM('subdomain', 'custom') NOT NULL DEFAULT 'subdomain',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    ssl_status ENUM('pending', 'active', 'expired', 'failed') DEFAULT 'pending',
    ssl_expiry DATE DEFAULT NULL,
    dns_verified TINYINT(1) NOT NULL DEFAULT 0,
    verification_token VARCHAR(100) DEFAULT NULL,
    status ENUM('pending', 'active', 'inactive', 'failed') NOT NULL DEFAULT 'pending',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_domains_uuid (uuid),
    UNIQUE KEY uq_tenant_domains_name (domain_name),
    INDEX idx_tenant_domains_tenant (tenant_id),
    INDEX idx_tenant_domains_status (status),
    INDEX idx_tenant_domains_is_primary (is_primary),
    CONSTRAINT fk_tenant_domains_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 15. TENANT MODULES
-- ============================================
CREATE TABLE tenant_modules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    module_code VARCHAR(50) NOT NULL,
    module_name VARCHAR(100) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    settings JSON DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_modules_uuid (uuid),
    UNIQUE KEY uq_tenant_modules_code (tenant_id, module_code),
    CONSTRAINT fk_tenant_modules_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 16. SCHOOLS
-- ============================================
CREATE TABLE schools (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    school_name VARCHAR(200) NOT NULL,
    school_code VARCHAR(50) NOT NULL,
    school_type VARCHAR(50) DEFAULT NULL,
    motto TEXT DEFAULT NULL,
    vision TEXT DEFAULT NULL,
    mission TEXT DEFAULT NULL,
    country_id BIGINT UNSIGNED DEFAULT NULL,
    region VARCHAR(100) DEFAULT NULL,
    district VARCHAR(100) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    digital_address VARCHAR(100) DEFAULT NULL,
    postal_address TEXT DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(200) DEFAULT NULL,
    website VARCHAR(200) DEFAULT NULL,
    logo_url VARCHAR(500) DEFAULT NULL,
    principal_name VARCHAR(200) DEFAULT NULL,
    principal_phone VARCHAR(20) DEFAULT NULL,
    principal_email VARCHAR(200) DEFAULT NULL,
    established_date DATE DEFAULT NULL,
    accreditation_number VARCHAR(100) DEFAULT NULL,
    status ENUM('pending', 'active', 'suspended', 'closed') NOT NULL DEFAULT 'pending',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schools_uuid (uuid),
    UNIQUE KEY uq_schools_code (tenant_id, school_code),
    INDEX idx_schools_tenant (tenant_id),
    INDEX idx_schools_status (status),
    CONSTRAINT fk_schools_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_schools_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 17. CAMPUSES
-- ============================================
CREATE TABLE campuses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    campus_name VARCHAR(200) NOT NULL,
    campus_code VARCHAR(50) NOT NULL,
    address TEXT DEFAULT NULL,
    gps_coordinates VARCHAR(100) DEFAULT NULL,
    status ENUM('active', 'inactive', 'closed') NOT NULL DEFAULT 'active',
    principal_name VARCHAR(200) DEFAULT NULL,
    principal_phone VARCHAR(20) DEFAULT NULL,
    principal_email VARCHAR(200) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(200) DEFAULT NULL,
    capacity INT UNSIGNED DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_campuses_uuid (uuid),
    UNIQUE KEY uq_campuses_code (school_id, campus_code),
    INDEX idx_campuses_school (school_id),
    INDEX idx_campuses_status (status),
    CONSTRAINT fk_campuses_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 18. TENANT BILLING ACCOUNTS
-- ============================================
CREATE TABLE tenant_billing_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    account_number VARCHAR(50) NOT NULL,
    balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(3) DEFAULT 'GHS',
    credit_limit DECIMAL(15,2) DEFAULT 0.00,
    payment_term_days INT UNSIGNED DEFAULT 30,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_billing_accounts_uuid (uuid),
    UNIQUE KEY uq_tenant_billing_accounts_number (account_number),
    INDEX idx_tenant_billing_accounts_tenant (tenant_id),
    CONSTRAINT fk_tenant_billing_accounts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 19. TENANT INVOICES
-- ============================================
CREATE TABLE tenant_invoices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    billing_account_id BIGINT UNSIGNED NOT NULL,
    invoice_number VARCHAR(50) NOT NULL,
    invoice_date DATE NOT NULL,
    due_date DATE NOT NULL,
    subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    balance_due DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(3) DEFAULT 'GHS',
    status ENUM('draft', 'issued', 'paid', 'overdue', 'cancelled') NOT NULL DEFAULT 'draft',
    notes TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_invoices_uuid (uuid),
    UNIQUE KEY uq_tenant_invoices_number (invoice_number),
    INDEX idx_tenant_invoices_tenant (tenant_id),
    INDEX idx_tenant_invoices_billing (billing_account_id),
    INDEX idx_tenant_invoices_status (status),
    CONSTRAINT fk_tenant_invoices_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_tenant_invoices_billing FOREIGN KEY (billing_account_id) REFERENCES tenant_billing_accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 20. TENANT INVOICE ITEMS
-- ============================================
CREATE TABLE tenant_invoice_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_rate DECIMAL(5,2) DEFAULT 0.00,
    tax_amount DECIMAL(15,2) DEFAULT 0.00,
    discount_rate DECIMAL(5,2) DEFAULT 0.00,
    discount_amount DECIMAL(15,2) DEFAULT 0.00,
    reference_type VARCHAR(50) DEFAULT NULL,
    reference_id BIGINT UNSIGNED DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_invoice_items_uuid (uuid),
    INDEX idx_tenant_invoice_items_invoice (invoice_id),
    CONSTRAINT fk_tenant_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES tenant_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 21. TENANT PAYMENTS
-- ============================================
CREATE TABLE tenant_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED DEFAULT NULL,
    payment_number VARCHAR(50) NOT NULL,
    receipt_number VARCHAR(50) DEFAULT NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(3) DEFAULT 'GHS',
    payment_date DATE NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    provider VARCHAR(50) DEFAULT NULL,
    transaction_id VARCHAR(100) DEFAULT NULL,
    reference VARCHAR(100) DEFAULT NULL,
    status ENUM('pending', 'completed', 'failed', 'refunded', 'cancelled') NOT NULL DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_payments_uuid (uuid),
    UNIQUE KEY uq_tenant_payments_number (payment_number),
    INDEX idx_tenant_payments_tenant (tenant_id),
    INDEX idx_tenant_payments_invoice (invoice_id),
    INDEX idx_tenant_payments_status (status),
    CONSTRAINT fk_tenant_payments_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_tenant_payments_invoice FOREIGN KEY (invoice_id) REFERENCES tenant_invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 22. TENANT PAYMENT METHODS
-- ============================================
CREATE TABLE tenant_payment_methods (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    method_name VARCHAR(100) NOT NULL,
    method_code VARCHAR(50) NOT NULL,
    provider VARCHAR(50) DEFAULT NULL,
    config_data JSON DEFAULT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_payment_methods_uuid (uuid),
    UNIQUE KEY uq_tenant_payment_methods_code (tenant_id, method_code),
    CONSTRAINT fk_tenant_payment_methods_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 23. TENANT PAYMENT TRANSACTIONS
-- ============================================
CREATE TABLE tenant_payment_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    transaction_type VARCHAR(50) NOT NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    provider VARCHAR(50) DEFAULT NULL,
    provider_transaction_id VARCHAR(100) DEFAULT NULL,
    request_data JSON DEFAULT NULL,
    response_data JSON DEFAULT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    error_message TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_payment_transactions_uuid (uuid),
    INDEX idx_tenant_payment_transactions_tenant (tenant_id),
    INDEX idx_tenant_payment_transactions_payment (payment_id),
    INDEX idx_tenant_payment_transactions_status (status),
    CONSTRAINT fk_tenant_payment_transactions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_tenant_payment_transactions_payment FOREIGN KEY (payment_id) REFERENCES tenant_payments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 24. PLATFORM SESSIONS
-- ============================================
CREATE TABLE platform_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    session_token VARCHAR(255) NOT NULL,
    refresh_token VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    browser VARCHAR(100) DEFAULT NULL,
    os VARCHAR(100) DEFAULT NULL,
    device VARCHAR(100) DEFAULT NULL,
    login_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expiry_time TIMESTAMP NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_sessions_uuid (uuid),
    UNIQUE KEY uq_platform_sessions_token (session_token),
    INDEX idx_platform_sessions_user (user_id),
    INDEX idx_platform_sessions_expiry (expiry_time),
    CONSTRAINT fk_platform_sessions_user FOREIGN KEY (user_id) REFERENCES platform_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 25. PLATFORM AUDIT LOGS
-- ============================================
CREATE TABLE platform_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    action_type VARCHAR(50) NOT NULL,
    module VARCHAR(50) NOT NULL,
    resource VARCHAR(100) NOT NULL,
    resource_id VARCHAR(100) DEFAULT NULL,
    old_data JSON DEFAULT NULL,
    new_data JSON DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    browser VARCHAR(100) DEFAULT NULL,
    os VARCHAR(100) DEFAULT NULL,
    device VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_audit_logs_uuid (uuid),
    INDEX idx_platform_audit_logs_user (user_id),
    INDEX idx_platform_audit_logs_tenant (tenant_id),
    INDEX idx_platform_audit_logs_action (action_type),
    INDEX idx_platform_audit_logs_module (module),
    INDEX idx_platform_audit_logs_created (created_at),
    CONSTRAINT fk_platform_audit_logs_user FOREIGN KEY (user_id) REFERENCES platform_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 26. TENANT USAGE STATISTICS
-- ============================================
CREATE TABLE tenant_usage_statistics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    metric_date DATE NOT NULL,
    metric_type VARCHAR(50) NOT NULL,
    value BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_usage_statistics_uuid (uuid),
    UNIQUE KEY uq_tenant_usage_metric (tenant_id, metric_date, metric_type),
    INDEX idx_tenant_usage_statistics_tenant (tenant_id),
    INDEX idx_tenant_usage_statistics_date (metric_date),
    CONSTRAINT fk_tenant_usage_statistics_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 27. TENANT STORAGE USAGE
-- ============================================
CREATE TABLE tenant_storage_usage (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    storage_type VARCHAR(50) NOT NULL,
    bytes_used BIGINT UNSIGNED NOT NULL DEFAULT 0,
    file_count INT UNSIGNED NOT NULL DEFAULT 0,
    folder_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_storage_usage_uuid (uuid),
    UNIQUE KEY uq_tenant_storage_type (tenant_id, storage_type),
    CONSTRAINT fk_tenant_storage_usage_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 28. PLATFORM VIEWS
-- ============================================

-- Active Tenants View
CREATE OR REPLACE VIEW vw_active_tenants AS
SELECT 
    t.id,
    t.tenant_name,
    t.tenant_code,
    t.legal_name,
    t.status,
    t.email,
    t.phone,
    t.created_at,
    ts.subscription_number,
    ts.start_date,
    ts.end_date,
    ts.status as subscription_status,
    sp.plan_name,
    DATEDIFF(ts.end_date, CURDATE()) as days_remaining
FROM tenants t
LEFT JOIN tenant_subscriptions ts ON t.id = ts.tenant_id AND ts.is_active = 1
LEFT JOIN subscription_plans sp ON ts.plan_id = sp.id
WHERE t.is_active = 1
AND t.status = 'active';

-- Tenant Subscription Status View
CREATE OR REPLACE VIEW vw_tenant_subscription_status AS
SELECT 
    t.id as tenant_id,
    t.tenant_name,
    t.tenant_code,
    t.status as tenant_status,
    ts.id as subscription_id,
    ts.subscription_number,
    ts.start_date,
    ts.end_date,
    ts.status as subscription_status,
    sp.plan_name,
    sp.duration_type,
    sp.price,
    DATEDIFF(ts.end_date, CURDATE()) as days_remaining,
    CASE 
        WHEN DATEDIFF(ts.end_date, CURDATE()) < 0 THEN 'expired'
        WHEN DATEDIFF(ts.end_date, CURDATE()) < 7 THEN 'expiring_soon'
        ELSE 'active'
    END as subscription_health
FROM tenants t
LEFT JOIN tenant_subscriptions ts ON t.id = ts.tenant_id AND ts.is_active = 1
LEFT JOIN subscription_plans sp ON ts.plan_id = sp.id
WHERE t.is_active = 1;

-- Platform Revenue View
CREATE OR REPLACE VIEW vw_platform_revenue AS
SELECT 
    DATE_FORMAT(payment_date, '%Y-%m') as month,
    YEAR(payment_date) as year,
    MONTH(payment_date) as month_num,
    COUNT(*) as payment_count,
    SUM(amount) as total_revenue,
    AVG(amount) as average_payment,
    MIN(amount) as min_payment,
    MAX(amount) as max_payment,
    currency
FROM tenant_payments
WHERE status = 'completed'
AND is_active = 1
GROUP BY YEAR(payment_date), MONTH(payment_date), currency
ORDER BY year DESC, month_num DESC;

-- Platform Usage Summary View
CREATE OR REPLACE VIEW vw_platform_usage_summary AS
SELECT 
    COUNT(*) as total_tenants,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_tenants,
    SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_tenants,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_tenants,
    SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_tenants,
    SUM(max_students) as total_student_capacity,
    SUM(max_staff) as total_staff_capacity,
    SUM(max_storage_mb) as total_storage_mb,
    SUM(max_api_calls) as total_api_calls
FROM tenants
WHERE is_active = 1;

-- ============================================
-- 29. INSERT DEFAULT DATA
-- ============================================

-- Insert Countries
INSERT IGNORE INTO countries (uuid, country_name, country_code, country_code_3, phone_code, currency, currency_symbol, timezone) VALUES
(UUID(), 'Ghana', 'GH', 'GHA', '+233', 'GHS', 'GH₵', 'Africa/Accra'),
(UUID(), 'Nigeria', 'NG', 'NGA', '+234', 'NGN', '₦', 'Africa/Lagos'),
(UUID(), 'Kenya', 'KE', 'KEN', '+254', 'KES', 'KSh', 'Africa/Nairobi'),
(UUID(), 'South Africa', 'ZA', 'ZAF', '+27', 'ZAR', 'R', 'Africa/Johannesburg'),
(UUID(), 'United Kingdom', 'GB', 'GBR', '+44', 'GBP', '£', 'Europe/London'),
(UUID(), 'United States', 'US', 'USA', '+1', 'USD', '$', 'America/New_York');

-- Insert Currencies
INSERT IGNORE INTO currencies (uuid, currency_name, currency_code, currency_symbol, decimal_places, is_default) VALUES
(UUID(), 'Ghana Cedi', 'GHS', 'GH₵', 2, 1),
(UUID(), 'Nigerian Naira', 'NGN', '₦', 2, 0),
(UUID(), 'Kenyan Shilling', 'KES', 'KSh', 2, 0),
(UUID(), 'South African Rand', 'ZAR', 'R', 2, 0),
(UUID(), 'US Dollar', 'USD', '$', 2, 0),
(UUID(), 'British Pound', 'GBP', '£', 2, 0);

-- Insert Languages
INSERT IGNORE INTO languages (uuid, language_name, language_code, locale, is_default) VALUES
(UUID(), 'English', 'en', 'en_US', 1),
(UUID(), 'French', 'fr', 'fr_FR', 0),
(UUID(), 'Portuguese', 'pt', 'pt_PT', 0),
(UUID(), 'Spanish', 'es', 'es_ES', 0),
(UUID(), 'Arabic', 'ar', 'ar_SA', 0),
(UUID(), 'Swahili', 'sw', 'sw_KE', 0);

-- Insert Platform Roles
INSERT IGNORE INTO platform_roles (uuid, role_name, role_code, description, is_system) VALUES
(UUID(), 'Platform Owner', 'platform_owner', 'Highest authority - owns the platform. Cannot be deleted.', 1),
(UUID(), 'Platform Super Administrator', 'platform_super_admin', 'Full platform management - tenants, subscriptions, billing, domains.', 1),
(UUID(), 'Platform Administrator', 'platform_admin', 'Can manage platform operations but cannot delete Platform Owner.', 1),
(UUID(), 'Platform Finance Officer', 'platform_finance', 'Manages subscriptions, invoices, payments, taxes, revenue, refunds.', 1),
(UUID(), 'Platform Support Officer', 'platform_support', 'Customer support, ticket management, troubleshooting, reset passwords.', 1),
(UUID(), 'Platform Security Officer', 'platform_security', 'Security monitoring, threat detection, audit logs, API security.', 1),
(UUID(), 'Platform Auditor', 'platform_auditor', 'View-only access to platform reports, usage, security, financial reports.', 1);

-- Insert Subscription Plans
INSERT IGNORE INTO subscription_plans (uuid, plan_name, plan_code, description, duration_type, duration_months, price, currency, max_students, max_staff, max_campuses, max_storage_mb, max_api_calls, max_ai_requests, sms_balance, email_balance, is_trial, trial_days, grace_period_days) VALUES
(UUID(), 'Free Trial', 'free_trial', '30-day free trial to explore the platform', 'trial', 1, 0.00, 'GHS', 50, 10, 1, 1024, 1000, 10, 100, 100, 1, 30, 7),
(UUID(), 'Starter Monthly', 'starter_monthly', 'Perfect for small schools starting out', 'monthly', 1, 99.00, 'GHS', 100, 20, 1, 5120, 5000, 50, 500, 500, 0, 0, 7),
(UUID(), 'Starter Annual', 'starter_annual', 'Starter plan billed annually - save 20%', 'annual', 12, 950.00, 'GHS', 100, 20, 1, 5120, 5000, 50, 500, 500, 0, 0, 7),
(UUID(), 'Pro Monthly', 'pro_monthly', 'For growing schools with more students', 'monthly', 1, 199.00, 'GHS', 500, 50, 2, 10240, 20000, 200, 1000, 1000, 0, 0, 7),
(UUID(), 'Pro Annual', 'pro_annual', 'Pro plan billed annually - save 20%', 'annual', 12, 1900.00, 'GHS', 500, 50, 2, 10240, 20000, 200, 1000, 1000, 0, 0, 7),
(UUID(), 'Enterprise Monthly', 'enterprise_monthly', 'For large schools and multi-campus institutions', 'monthly', 1, 499.00, 'GHS', 2000, 200, 5, 51200, 50000, 500, 5000, 5000, 0, 0, 14),
(UUID(), 'Enterprise Annual', 'enterprise_annual', 'Enterprise plan billed annually - save 20%', 'annual', 12, 4790.00, 'GHS', 2000, 200, 5, 51200, 50000, 500, 5000, 5000, 0, 0, 14);

-- Insert Platform System Settings
INSERT IGNORE INTO platform_system_settings (uuid, setting_key, setting_value, setting_category, data_type, description) VALUES
(UUID(), 'platform_name', 'EduTrack EERP', 'general', 'string', 'The name of the platform'),
(UUID(), 'platform_version', '2.0.0', 'general', 'string', 'Current platform version'),
(UUID(), 'default_language', 'en', 'general', 'string', 'Default language for the platform'),
(UUID(), 'default_currency', 'GHS', 'general', 'string', 'Default currency for the platform'),
(UUID(), 'default_timezone', 'Africa/Accra', 'general', 'string', 'Default timezone for the platform'),
(UUID(), 'session_timeout_minutes', '60', 'security', 'integer', 'Session timeout in minutes'),
(UUID(), 'max_login_attempts', '5', 'security', 'integer', 'Maximum login attempts before lockout'),
(UUID(), 'lockout_duration_minutes', '30', 'security', 'integer', 'Account lockout duration in minutes'),
(UUID(), 'enable_tenant_isolation', '1', 'security', 'boolean', 'Enable multi-tenant data isolation'),
(UUID(), 'maintenance_mode', '0', 'system', 'boolean', 'Put the platform in maintenance mode'),
(UUID(), 'backup_frequency', 'daily', 'system', 'string', 'Backup frequency (daily, weekly, monthly)'),
(UUID(), 'backup_retention_days', '30', 'system', 'integer', 'Number of days to retain backups');

-- ============================================
-- 30. INSERT DEFAULT PLATFORM USER (Admin)
-- ============================================
-- Password: Admin@123
-- Hash generated using password_hash('Admin@123', PASSWORD_DEFAULT)
INSERT IGNORE INTO platform_users (uuid, username, email, password_hash, first_name, last_name, phone, is_active, created_at) VALUES
(UUID(), 'admin', 'admin@edutrack.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Platform', 'Administrator', '+233200000000', 1, NOW());

-- Get the user ID and assign roles
SET @admin_id = (SELECT id FROM platform_users WHERE username = 'admin');

-- Assign Platform Owner and Super Admin roles to admin
INSERT IGNORE INTO platform_user_roles (uuid, user_id, role_id) 
SELECT UUID(), @admin_id, id FROM platform_roles WHERE role_code IN ('platform_owner', 'platform_super_admin');

-- ============================================
-- 31. VERIFY TABLES
-- ============================================
SELECT '✅ All Platform & Tenant Management Tables Created Successfully!' AS Status;
SELECT COUNT(*) as total_tables FROM information_schema.tables WHERE table_schema = 'student_performance_system' AND (table_name LIKE 'platform_%' OR table_name LIKE 'tenant_%' OR table_name LIKE 'subscription_%' OR table_name = 'schools' OR table_name = 'campuses' OR table_name = 'countries' OR table_name = 'currencies' OR table_name = 'languages');
SELECT COUNT(*) as platform_users_count FROM platform_users;
SELECT COUNT(*) as platform_roles_count FROM platform_roles;
SELECT COUNT(*) as subscription_plans_count FROM subscription_plans;
SELECT COUNT(*) as countries_count FROM countries;
SELECT COUNT(*) as currencies_count FROM currencies;
SELECT COUNT(*) as languages_count FROM languages;

-- ============================================
-- END OF PLATFORM & TENANT MANAGEMENT MODULE
-- ============================================