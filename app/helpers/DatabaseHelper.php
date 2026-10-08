<?php

/**
 * Database Helper - Database connection and query helper
 *
 * @package EduTrack
 * @filepath app/helpers/DatabaseHelper.php
 * @version 2.1  (added inTransaction() passthrough)
 */

// Load config if not already loaded
if (!defined('DB_HOST')) {
    require_once dirname(__DIR__, 2) . '/config/config.php';
}

class DatabaseHelper
{
    private static $instance = null;
    private $pdo = null;
    private $host;
    private $dbname;
    private $user;
    private $pass;

    private function __construct()
    {
        $this->host = DB_HOST;
        $this->dbname = DB_NAME;
        $this->user = DB_USER;
        $this->pass = DB_PASS;

        try {
            $this->pdo = new PDO(
                "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4",
                $this->user,
                $this->pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            // If database doesn't exist, create it
            if (strpos($e->getMessage(), 'Unknown database') !== false) {
                $this->createDatabase();
            } else {
                die('Database connection failed: ' . $e->getMessage());
            }
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function createDatabase()
    {
        try {
            $pdo = new PDO(
                "mysql:host={$this->host};charset=utf8mb4",
                $this->user,
                $this->pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$this->dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$this->dbname}`");

            // Create schema
            $this->createSchema($pdo);

            // Reconnect to the database
            $this->pdo = new PDO(
                "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4",
                $this->user,
                $this->pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (PDOException $e) {
            die('Failed to create database: ' . $e->getMessage());
        }
    }

    private function createSchema($pdo)
    {
        $schema = "
            -- ============================================
            -- CORE TABLES
            -- ============================================

            -- Tenants table
            CREATE TABLE IF NOT EXISTS tenants (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                tenant_name VARCHAR(100) NOT NULL,
                legal_name VARCHAR(100),
                tenant_code VARCHAR(20) UNIQUE NOT NULL,
                email VARCHAR(100) NOT NULL,
                phone VARCHAR(20),
                address TEXT,
                website VARCHAR(100),
                logo VARCHAR(255),
                status ENUM('active', 'inactive', 'pending', 'suspended') DEFAULT 'pending',
                settings JSON,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                INDEX idx_tenant_code (tenant_code),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Schools table
            CREATE TABLE IF NOT EXISTS schools (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                tenant_id INT NOT NULL,
                school_name VARCHAR(100) NOT NULL,
                school_code VARCHAR(20) UNIQUE NOT NULL,
                email VARCHAR(100),
                phone VARCHAR(20),
                address TEXT,
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                INDEX idx_tenant_id (tenant_id),
                INDEX idx_school_code (school_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Campuses table
            CREATE TABLE IF NOT EXISTS campuses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                tenant_id INT NOT NULL,
                school_id INT NOT NULL,
                campus_name VARCHAR(100) NOT NULL,
                campus_code VARCHAR(20) UNIQUE NOT NULL,
                address TEXT,
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
                INDEX idx_tenant_id (tenant_id),
                INDEX idx_school_id (school_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- ============================================
            -- USERS & AUTHENTICATION
            -- ============================================

            -- Platform Users table
            CREATE TABLE IF NOT EXISTS platform_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                tenant_id INT DEFAULT NULL,
                username VARCHAR(50) UNIQUE NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                first_name VARCHAR(50) NOT NULL,
                last_name VARCHAR(50) NOT NULL,
                phone VARCHAR(20),
                is_active TINYINT(1) DEFAULT 1,
                last_login DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
                INDEX idx_tenant_id (tenant_id),
                INDEX idx_email (email),
                INDEX idx_username (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Platform User Roles table
            CREATE TABLE IF NOT EXISTS platform_user_roles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                role_id INT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES platform_users(id) ON DELETE CASCADE,
                UNIQUE KEY unique_user_role (user_id, role_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Roles table
            CREATE TABLE IF NOT EXISTS roles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                role_name VARCHAR(50) UNIQUE NOT NULL,
                description TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- ============================================
            -- PERSONS (BASE TABLE FOR STAFF & STUDENTS)
            -- ============================================

            -- Persons table
            CREATE TABLE IF NOT EXISTS persons (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                tenant_id INT NOT NULL,
                first_name VARCHAR(50) NOT NULL,
                middle_name VARCHAR(50),
                last_name VARCHAR(50) NOT NULL,
                preferred_name VARCHAR(50),
                display_name VARCHAR(100),
                date_of_birth DATE,
                gender ENUM('Male', 'Female', 'Other'),
                nationality VARCHAR(50),
                religion VARCHAR(50),
                primary_phone VARCHAR(20),
                secondary_phone VARCHAR(20),
                email VARCHAR(100),
                secondary_email VARCHAR(100),
                work_email VARCHAR(100),
                work_phone VARCHAR(20),
                address TEXT,
                town_city VARCHAR(50),
                district VARCHAR(50),
                region VARCHAR(50),
                gps_address VARCHAR(50),
                post_address VARCHAR(50),
                home_town VARCHAR(50),
                marital_status VARCHAR(20),
                profile_photo_url VARCHAR(255),
                created_by INT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                INDEX idx_tenant_id (tenant_id),
                INDEX idx_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- ============================================
            -- STAFF TABLES
            -- ============================================

            -- Staff table - FIXED with uuid column
            CREATE TABLE IF NOT EXISTS staff (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uuid VARCHAR(36) DEFAULT NULL UNIQUE,
                person_id INT NOT NULL,
                tenant_id INT NOT NULL,
                school_id INT NULL,
                campus_id INT NULL,
                staff_number VARCHAR(50) UNIQUE NOT NULL,
                staff_category_id INT NULL,
                staff_type_id INT NULL,
                employment_type_id INT NULL,
                staff_status_id INT NULL,
                department_id INT NULL,
                designation_id INT NULL,
                job_title VARCHAR(100),
                reporting_manager_id INT NULL,
                work_location VARCHAR(100),
                hire_date DATE,
                joining_date DATE,
                probation_start_date DATE,
                probation_end_date DATE,
                confirmation_date DATE,
                contract_start_date DATE,
                contract_end_date DATE,
                is_teaching_staff TINYINT(1) DEFAULT 0,
                max_teaching_hours INT DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                platform_user_id INT NULL,
                qualifications TEXT,
                specialties TEXT,
                notes TEXT,
                created_by INT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL,
                FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
                FOREIGN KEY (staff_category_id) REFERENCES staff_categories(id) ON DELETE SET NULL,
                FOREIGN KEY (staff_type_id) REFERENCES staff_types(id) ON DELETE SET NULL,
                FOREIGN KEY (employment_type_id) REFERENCES employment_types(id) ON DELETE SET NULL,
                FOREIGN KEY (staff_status_id) REFERENCES staff_statuses(id) ON DELETE SET NULL,
                FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
                FOREIGN KEY (designation_id) REFERENCES designations(id) ON DELETE SET NULL,
                INDEX idx_tenant_id (tenant_id),
                INDEX idx_school_id (school_id),
                INDEX idx_staff_number (staff_number),
                INDEX idx_uuid (uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Staff Categories table
            CREATE TABLE IF NOT EXISTS staff_categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category_name VARCHAR(50) NOT NULL UNIQUE,
                is_active TINYINT(1) DEFAULT 1,
                sort_order INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Staff Types table
            CREATE TABLE IF NOT EXISTS staff_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                staff_category_id INT NOT NULL,
                type_name VARCHAR(50) NOT NULL,
                is_active TINYINT(1) DEFAULT 1,
                sort_order INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                FOREIGN KEY (staff_category_id) REFERENCES staff_categories(id) ON DELETE CASCADE,
                INDEX idx_category_id (staff_category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Employment Types table
            CREATE TABLE IF NOT EXISTS employment_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employment_type_name VARCHAR(50) NOT NULL UNIQUE,
                is_contract TINYINT(1) DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Staff Statuses table
            CREATE TABLE IF NOT EXISTS staff_statuses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                status_name VARCHAR(50) NOT NULL UNIQUE,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Departments table
            CREATE TABLE IF NOT EXISTS departments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                department_name VARCHAR(100) NOT NULL,
                school_id INT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL,
                INDEX idx_school_id (school_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Designations table
            CREATE TABLE IF NOT EXISTS designations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                designation_name VARCHAR(100) NOT NULL,
                school_id INT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL,
                INDEX idx_school_id (school_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Staff Emergency Contacts
            CREATE TABLE IF NOT EXISTS staff_emergency_contacts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                staff_id INT NOT NULL,
                contact_name VARCHAR(100) NOT NULL,
                relationship VARCHAR(50) NOT NULL,
                primary_phone VARCHAR(20) NOT NULL,
                secondary_phone VARCHAR(20),
                email VARCHAR(100),
                address TEXT,
                is_primary TINYINT(1) DEFAULT 0,
                sort_order INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
                INDEX idx_staff_id (staff_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Staff Qualifications
            CREATE TABLE IF NOT EXISTS staff_qualifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                staff_id INT NOT NULL,
                qualification_level INT,
                qualification_name VARCHAR(100) NOT NULL,
                major_field VARCHAR(100),
                institution_name VARCHAR(100) NOT NULL,
                country VARCHAR(50),
                year_of_graduation INT,
                verification_status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
                verified_by INT,
                verified_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
                INDEX idx_staff_id (staff_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Staff Licenses
            CREATE TABLE IF NOT EXISTS staff_licenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                staff_id INT NOT NULL,
                license_type INT,
                license_name VARCHAR(100) NOT NULL,
                issuing_authority VARCHAR(100) NOT NULL,
                license_number VARCHAR(50) NOT NULL,
                issue_date DATE,
                expiration_date DATE,
                endorsements TEXT,
                verification_status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
                verified_by INT,
                verified_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
                INDEX idx_staff_id (staff_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Staff Academic Assignments
            CREATE TABLE IF NOT EXISTS staff_academic_assignments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                staff_id INT NOT NULL,
                assignment_type ENUM('class_teacher', 'subject_teacher', 'special_education') NOT NULL,
                academic_year_id INT,
                academic_term_id INT,
                class_id INT,
                stream_id INT,
                subject_ids TEXT,
                class_ids TEXT,
                stream_ids TEXT,
                discipline_type VARCHAR(50),
                discipline_name VARCHAR(100),
                is_primary TINYINT(1) DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                created_by INT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
                INDEX idx_staff_id (staff_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Staff Payroll
            CREATE TABLE IF NOT EXISTS staff_payroll (
                id INT AUTO_INCREMENT PRIMARY KEY,
                staff_id INT NOT NULL,
                bank_name VARCHAR(100),
                account_name VARCHAR(100),
                account_number VARCHAR(50),
                bank_branch VARCHAR(100),
                bank_sort_code VARCHAR(20),
                ssnit_number VARCHAR(50),
                tax_id VARCHAR(50),
                payment_method ENUM('bank_transfer', 'cheque', 'cash', 'mobile_money') DEFAULT 'bank_transfer',
                salary_grade VARCHAR(50),
                salary_level VARCHAR(50),
                basic_salary DECIMAL(15,2),
                effective_date DATE,
                is_active TINYINT(1) DEFAULT 1,
                created_by INT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
                INDEX idx_staff_id (staff_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Staff Biometric Data
            CREATE TABLE IF NOT EXISTS staff_biometric_data (
                id INT AUTO_INCREMENT PRIMARY KEY,
                staff_id INT NOT NULL,
                biometric_template TEXT,
                biometric_type VARCHAR(50) DEFAULT 'fingerprint',
                finger_position VARCHAR(50),
                template_format VARCHAR(50) DEFAULT 'ISO_19794_2',
                quality_score INT DEFAULT 0,
                is_primary TINYINT(1) DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                captured_by INT,
                captured_at DATETIME,
                notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
                INDEX idx_staff_id (staff_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Staff Signatures
            CREATE TABLE IF NOT EXISTS staff_signatures (
                id INT AUTO_INCREMENT PRIMARY KEY,
                staff_id INT NOT NULL,
                signature_data TEXT,
                signature_type VARCHAR(50) DEFAULT 'captured',
                mime_type VARCHAR(50) DEFAULT 'image/png',
                is_primary TINYINT(1) DEFAULT 1,
                is_active TINYINT(1) DEFAULT 1,
                captured_by INT,
                captured_at DATETIME,
                notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
                INDEX idx_staff_id (staff_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Staff Biographic Data
            CREATE TABLE IF NOT EXISTS staff_biographic_data (
                id INT AUTO_INCREMENT PRIMARY KEY,
                staff_id INT NOT NULL,
                height_cm DECIMAL(5,2),
                weight_kg DECIMAL(5,2),
                blood_type VARCHAR(5),
                eye_color VARCHAR(20),
                hair_color VARCHAR(20),
                skin_tone VARCHAR(20),
                distinguishing_marks TEXT,
                allergies TEXT,
                medical_conditions TEXT,
                emergency_medical_notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
                INDEX idx_staff_id (staff_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Staff Onboarding Checklist
            CREATE TABLE IF NOT EXISTS staff_onboarding (
                id INT AUTO_INCREMENT PRIMARY KEY,
                staff_id INT NOT NULL,
                checklist_key VARCHAR(50) NOT NULL,
                checklist_label VARCHAR(100) NOT NULL,
                is_completed TINYINT(1) DEFAULT 0,
                completed_by INT,
                completed_at DATETIME,
                notes TEXT,
                sort_order INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
                INDEX idx_staff_id (staff_id),
                UNIQUE KEY uk_staff_checklist (staff_id, checklist_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- ============================================
            -- ACADEMIC TABLES
            -- ============================================

            -- Classes table
            CREATE TABLE IF NOT EXISTS classes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                tenant_id INT NOT NULL,
                school_id INT NOT NULL,
                class_name VARCHAR(50) NOT NULL,
                class_code VARCHAR(20) UNIQUE NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
                INDEX idx_tenant_id (tenant_id),
                INDEX idx_school_id (school_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Subjects table
            CREATE TABLE IF NOT EXISTS subjects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                tenant_id INT NOT NULL,
                subject_name VARCHAR(100) NOT NULL,
                subject_code VARCHAR(20) NOT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                INDEX idx_tenant_id (tenant_id),
                UNIQUE KEY uk_subject_code (subject_code, tenant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Academic Years table
            CREATE TABLE IF NOT EXISTS academic_years (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                tenant_id INT NOT NULL,
                year_name VARCHAR(50) NOT NULL,
                start_date DATE,
                end_date DATE,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                INDEX idx_tenant_id (tenant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Academic Terms table
            CREATE TABLE IF NOT EXISTS academic_terms (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                tenant_id INT NOT NULL,
                academic_year_id INT NOT NULL,
                term_name VARCHAR(50) NOT NULL,
                term_number INT DEFAULT 1,
                start_date DATE,
                end_date DATE,
                sort_order INT DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
                INDEX idx_tenant_id (tenant_id),
                INDEX idx_academic_year_id (academic_year_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Streams table
            CREATE TABLE IF NOT EXISTS streams (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                tenant_id INT NOT NULL,
                stream_name VARCHAR(50) NOT NULL,
                stream_code VARCHAR(20),
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                INDEX idx_tenant_id (tenant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- ============================================
            -- LOOKUP TABLES
            -- ============================================

            -- Qualification Levels table
            CREATE TABLE IF NOT EXISTS qualification_levels (
                id INT AUTO_INCREMENT PRIMARY KEY,
                level_name VARCHAR(50) NOT NULL UNIQUE,
                is_active TINYINT(1) DEFAULT 1,
                sort_order INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- Identification Types table
            CREATE TABLE IF NOT EXISTS identification_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type_name VARCHAR(50) NOT NULL UNIQUE,
                is_active TINYINT(1) DEFAULT 1,
                sort_order INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- License Types table
            CREATE TABLE IF NOT EXISTS license_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type_name VARCHAR(50) NOT NULL UNIQUE,
                requires_teaching TINYINT(1) DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                sort_order INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- ============================================
            -- TRIGGERS
            -- ============================================

            -- Trigger for tenants table to auto-generate UUID
            DROP TRIGGER IF EXISTS before_insert_tenants;
            DELIMITER //
            CREATE TRIGGER before_insert_tenants
            BEFORE INSERT ON tenants
            FOR EACH ROW
            BEGIN
                IF NEW.uuid IS NULL OR NEW.uuid = '' THEN
                    SET NEW.uuid = UUID();
                END IF;
            END//
            DELIMITER ;

            -- Trigger for staff table to auto-generate UUID
            DROP TRIGGER IF EXISTS before_insert_staff;
            DELIMITER //
            CREATE TRIGGER before_insert_staff
            BEFORE INSERT ON staff
            FOR EACH ROW
            BEGIN
                IF NEW.uuid IS NULL OR NEW.uuid = '' OR NEW.uuid = ' ' THEN
                    SET NEW.uuid = UUID();
                END IF;
            END//
            DELIMITER ;

            -- Trigger for platform_users table to auto-generate UUID
            DROP TRIGGER IF EXISTS before_insert_platform_users;
            DELIMITER //
            CREATE TRIGGER before_insert_platform_users
            BEFORE INSERT ON platform_users
            FOR EACH ROW
            BEGIN
                IF NEW.uuid IS NULL OR NEW.uuid = '' THEN
                    SET NEW.uuid = UUID();
                END IF;
            END//
            DELIMITER ;

            -- Trigger for persons table to auto-generate UUID
            DROP TRIGGER IF EXISTS before_insert_persons;
            DELIMITER //
            CREATE TRIGGER before_insert_persons
            BEFORE INSERT ON persons
            FOR EACH ROW
            BEGIN
                IF NEW.uuid IS NULL OR NEW.uuid = '' THEN
                    SET NEW.uuid = UUID();
                END IF;
            END//
            DELIMITER ;

            -- Trigger for schools table to auto-generate UUID
            DROP TRIGGER IF EXISTS before_insert_schools;
            DELIMITER //
            CREATE TRIGGER before_insert_schools
            BEFORE INSERT ON schools
            FOR EACH ROW
            BEGIN
                IF NEW.uuid IS NULL OR NEW.uuid = '' THEN
                    SET NEW.uuid = UUID();
                END IF;
            END//
            DELIMITER ;
        ";

        // Split and execute schema statements
        $statements = array_filter(array_map('trim', explode(';', $schema)));
        foreach ($statements as $statement) {
            if (!empty($statement)) {
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    error_log('Schema creation error: ' . $e->getMessage() . ' in: ' . substr($statement, 0, 200));
                }
            }
        }

        // ============================================
        // INSERT DEFAULT DATA
        // ============================================

        // Insert default roles
        try {
            $pdo->exec("INSERT IGNORE INTO roles (id, role_name, description) VALUES 
                (1, 'Super Admin', 'Super Administrator with global access'),
                (2, 'Platform Admin', 'Platform Administrator with tenant management access'),
                (3, 'Tenant Admin', 'Tenant Administrator with full tenant access'),
                (4, 'School Admin', 'School Administrator with school-level access'),
                (5, 'Teacher', 'Teacher with classroom access'),
                (6, 'Staff', 'General staff member'),
                (7, 'Parent', 'Parent with student access')");
        } catch (PDOException $e) {
            error_log('Role creation error: ' . $e->getMessage());
        }

        // Insert default staff categories
        try {
            $pdo->exec("INSERT IGNORE INTO staff_categories (id, category_name, sort_order) VALUES 
                (1, 'Teaching Staff', 1),
                (2, 'Non-Teaching Staff', 2)");
        } catch (PDOException $e) {
            error_log('Staff categories creation error: ' . $e->getMessage());
        }

        // Insert default staff types
        try {
            $pdo->exec("INSERT IGNORE INTO staff_types (id, staff_category_id, type_name, sort_order) VALUES 
                (1, 1, 'Teacher', 1),
                (2, 1, 'Lecturer', 2),
                (3, 1, 'Instructor', 3),
                (4, 1, 'Professor', 4),
                (5, 2, 'Administrative Staff', 5),
                (6, 2, 'Support Staff', 6),
                (7, 2, 'Technical Staff', 7),
                (8, 2, 'Management', 8)");
        } catch (PDOException $e) {
            error_log('Staff types creation error: ' . $e->getMessage());
        }

        // Insert default employment types
        try {
            $pdo->exec("INSERT IGNORE INTO employment_types (id, employment_type_name, is_contract) VALUES 
                (1, 'Permanent', 0),
                (2, 'Fixed-Term Contract', 1),
                (3, 'Part-Time', 0),
                (4, 'Casual', 0),
                (5, 'Intern', 0)");
        } catch (PDOException $e) {
            error_log('Employment types creation error: ' . $e->getMessage());
        }

        // Insert default staff statuses
        try {
            $pdo->exec("INSERT IGNORE INTO staff_statuses (id, status_name) VALUES 
                (1, 'Active'),
                (2, 'On Leave'),
                (3, 'Suspended'),
                (4, 'Terminated'),
                (5, 'Resigned'),
                (6, 'Retired')");
        } catch (PDOException $e) {
            error_log('Staff statuses creation error: ' . $e->getMessage());
        }

        // Insert default qualification levels
        try {
            $pdo->exec("INSERT IGNORE INTO qualification_levels (id, level_name, sort_order) VALUES 
                (1, 'High School Diploma', 1),
                (2, 'Certificate', 2),
                (3, 'Diploma', 3),
                (4, 'Bachelor\'s Degree', 4),
                (5, 'Postgraduate Diploma', 5),
                (6, 'Master\'s Degree', 6),
                (7, 'Doctorate (PhD)', 7),
                (8, 'Professional Certification', 8)");
        } catch (PDOException $e) {
            error_log('Qualification levels creation error: ' . $e->getMessage());
        }

        // Insert default identification types
        try {
            $pdo->exec("INSERT IGNORE INTO identification_types (id, type_name, sort_order) VALUES 
                (1, 'National ID', 1),
                (2, 'Passport', 2),
                (3, 'Driver\'s License', 3),
                (4, 'Work ID', 4),
                (5, 'Student ID', 5),
                (6, 'Voter ID', 6)");
        } catch (PDOException $e) {
            error_log('Identification types creation error: ' . $e->getMessage());
        }

        // Insert default license types
        try {
            $pdo->exec("INSERT IGNORE INTO license_types (id, type_name, requires_teaching) VALUES 
                (1, 'Teaching License', 1),
                (2, 'Professional Certification', 0),
                (3, 'Trade License', 0),
                (4, 'Practice License', 0)");
        } catch (PDOException $e) {
            error_log('License types creation error: ' . $e->getMessage());
        }

        // Insert default departments
        try {
            $pdo->exec("INSERT IGNORE INTO departments (id, department_name, is_active) VALUES 
                (1, 'Administration', 1),
                (2, 'Finance', 1),
                (3, 'Human Resources', 1),
                (4, 'IT Services', 1),
                (5, 'Maintenance', 1)");
        } catch (PDOException $e) {
            error_log('Departments creation error: ' . $e->getMessage());
        }

        // Insert default designations
        try {
            $pdo->exec("INSERT IGNORE INTO designations (id, designation_name, is_active) VALUES 
                (1, 'Principal', 1),
                (2, 'Vice Principal', 1),
                (3, 'Head of Department', 1),
                (4, 'Senior Teacher', 1),
                (5, 'Teacher', 1),
                (6, 'Administrative Officer', 1),
                (7, 'Accountant', 1),
                (8, 'IT Officer', 1)");
        } catch (PDOException $e) {
            error_log('Designations creation error: ' . $e->getMessage());
        }

        error_log('Database schema created successfully with all tables and triggers.');
    }

    // ============================================
    // DATABASE HELPER METHODS
    // ============================================

    public function getPDO()
    {
        return $this->pdo;
    }

    public function getValue($sql, $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function fetchOne($sql, $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    public function fetchAll($sql, $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function insert($sql, $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $this->pdo->lastInsertId();
    }

    public function execute($sql, $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function beginTransaction()
    {
        return $this->pdo->beginTransaction();
    }

    public function commit()
    {
        return $this->pdo->commit();
    }

    public function rollBack()
    {
        return $this->pdo->rollBack();
    }

    /**
     * Check whether a transaction is currently active on the underlying PDO.
     * Thin passthrough to PDO::inTransaction() so callers do not need to reach
     * for getPDO() just to guard a rollBack().
     */
    public function inTransaction()
    {
        return $this->pdo->inTransaction();
    }

    public function lastInsertId()
    {
        return $this->pdo->lastInsertId();
    }

    public function generateUuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Ensure staff table trigger exists
     * This method can be called to verify and recreate the trigger if needed
     */
    public function ensureStaffTriggerExists()
    {
        try {
            $triggerExists = $this->getValue(
                "SELECT COUNT(*) FROM information_schema.TRIGGERS 
                 WHERE TRIGGER_NAME = 'before_insert_staff' 
                 AND EVENT_OBJECT_TABLE = 'staff'"
            );

            if (!$triggerExists) {
                $this->execute("DROP TRIGGER IF EXISTS before_insert_staff");
                $this->execute("
                    CREATE TRIGGER before_insert_staff
                    BEFORE INSERT ON staff
                    FOR EACH ROW
                    BEGIN
                        IF NEW.uuid IS NULL OR NEW.uuid = '' OR NEW.uuid = ' ' THEN
                            SET NEW.uuid = UUID();
                        END IF;
                    END
                ");
                error_log("before_insert_staff trigger recreated by ensureStaffTriggerExists");
                return true;
            }
            return true;
        } catch (Exception $e) {
            error_log("Failed to ensure staff trigger exists: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fix staff table UUID column
     */
    public function fixStaffUuidColumn()
    {
        try {
            // Check if column exists
            $columnExists = $this->getValue(
                "SELECT COUNT(*) FROM information_schema.COLUMNS 
                 WHERE TABLE_NAME = 'staff' AND COLUMN_NAME = 'uuid'"
            );

            if ($columnExists) {
                // Modify column to allow NULL
                $this->execute("ALTER TABLE staff MODIFY uuid VARCHAR(36) DEFAULT NULL");
                // Remove default
                $this->execute("ALTER TABLE staff ALTER COLUMN uuid DROP DEFAULT");
                // Update empty strings to NULL
                $this->execute("UPDATE staff SET uuid = NULL WHERE uuid = '' OR uuid = ' '");
                error_log("Staff UUID column fixed");
                return true;
            } else {
                // Add column
                $this->execute("ALTER TABLE staff ADD COLUMN uuid VARCHAR(36) DEFAULT NULL UNIQUE");
                error_log("Staff UUID column added");
                return true;
            }
        } catch (Exception $e) {
            error_log("Failed to fix staff UUID column: " . $e->getMessage());
            return false;
        }
    }
}
