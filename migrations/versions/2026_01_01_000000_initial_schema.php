<?php

/**
 * Migration: Initial Schema - All tables for EduTrack
 * 
 * @package EduTrack
 * @subpackage Migrations
 * @version 2.0
 */

class Migration20260101000000 implements MigrationInterface
{
    public function up($db)
    {
        // Tenants table
        $db->execute("CREATE TABLE IF NOT EXISTS tenants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            uuid VARCHAR(36) UNIQUE NOT NULL,
            tenant_name VARCHAR(100) NOT NULL,
            tenant_code VARCHAR(20) UNIQUE NOT NULL,
            email VARCHAR(100) NOT NULL,
            phone VARCHAR(20),
            address TEXT,
            city VARCHAR(100),
            region VARCHAR(100),
            logo VARCHAR(255),
            status ENUM('active', 'inactive', 'suspended', 'pending') DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            INDEX idx_tenant_code (tenant_code),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Schools table
        $db->execute("CREATE TABLE IF NOT EXISTS schools (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Campuses table
        $db->execute("CREATE TABLE IF NOT EXISTS campuses (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Platform Users table
        $db->execute("CREATE TABLE IF NOT EXISTS platform_users (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Roles table
        $db->execute("CREATE TABLE IF NOT EXISTS roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role_name VARCHAR(50) UNIQUE NOT NULL,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Insert default roles
        $db->execute("INSERT IGNORE INTO roles (id, role_name, description) VALUES 
            (1, 'Admin', 'Administrator with full access'),
            (2, 'Super Admin', 'Super Administrator with global access')");

        // Platform User Roles table
        $db->execute("CREATE TABLE IF NOT EXISTS platform_user_roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            role_id INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES platform_users(id) ON DELETE CASCADE,
            FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            UNIQUE KEY unique_user_role (user_id, role_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Persons table
        $db->execute("CREATE TABLE IF NOT EXISTS persons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            uuid VARCHAR(36) UNIQUE NOT NULL,
            tenant_id INT NOT NULL,
            first_name VARCHAR(50) NOT NULL,
            middle_name VARCHAR(50),
            last_name VARCHAR(50) NOT NULL,
            date_of_birth DATE,
            gender ENUM('Male', 'Female', 'Other'),
            nationality VARCHAR(50),
            religion VARCHAR(50),
            primary_phone VARCHAR(20),
            secondary_phone VARCHAR(20),
            email VARCHAR(100),
            address TEXT,
            town_city VARCHAR(50),
            district VARCHAR(50),
            region VARCHAR(50),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            INDEX idx_tenant_id (tenant_id),
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Staff table
        $db->execute("CREATE TABLE IF NOT EXISTS staff (
            id INT AUTO_INCREMENT PRIMARY KEY,
            person_id INT NOT NULL,
            tenant_id INT NOT NULL,
            school_id INT NULL,
            campus_id INT NULL,
            staff_number VARCHAR(50) UNIQUE NOT NULL,
            staff_type ENUM('Teaching', 'Non-Teaching') DEFAULT 'Teaching',
            department VARCHAR(100),
            position VARCHAR(100),
            join_date DATE,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL,
            FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
            INDEX idx_tenant_id (tenant_id),
            INDEX idx_school_id (school_id),
            INDEX idx_staff_number (staff_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Students table
        $db->execute("CREATE TABLE IF NOT EXISTS students (
            id INT AUTO_INCREMENT PRIMARY KEY,
            uuid VARCHAR(36) UNIQUE NOT NULL,
            tenant_id INT NOT NULL,
            school_id INT NULL,
            campus_id INT NULL,
            class_id INT NULL,
            student_number VARCHAR(50) UNIQUE NOT NULL,
            first_name VARCHAR(50) NOT NULL,
            middle_name VARCHAR(50),
            last_name VARCHAR(50) NOT NULL,
            date_of_birth DATE,
            gender ENUM('Male', 'Female', 'Other'),
            nationality VARCHAR(50),
            religion VARCHAR(50),
            primary_phone VARCHAR(20),
            secondary_phone VARCHAR(20),
            email VARCHAR(100),
            address TEXT,
            town_city VARCHAR(50),
            district VARCHAR(50),
            region VARCHAR(50),
            enrollment_date DATE,
            enrollment_status ENUM('Active', 'Inactive', 'Transferred', 'Graduated', 'Suspended') DEFAULT 'Active',
            guardian_name VARCHAR(100),
            guardian_phone VARCHAR(20),
            guardian_alt_phone VARCHAR(20),
            guardian_relationship VARCHAR(50),
            guardian_email VARCHAR(100),
            guardian_address TEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_by INT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL,
            FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
            INDEX idx_tenant_id (tenant_id),
            INDEX idx_school_id (school_id),
            INDEX idx_student_number (student_number),
            INDEX idx_enrollment_status (enrollment_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Classes table
        $db->execute("CREATE TABLE IF NOT EXISTS classes (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Campus Settings table
        $db->execute("CREATE TABLE IF NOT EXISTS campus_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            campus_id INT NOT NULL UNIQUE,
            campus_phone VARCHAR(20),
            campus_email VARCHAR(100),
            campus_website VARCHAR(255),
            principal_name VARCHAR(100),
            vice_principal_name VARCHAR(100),
            school_hours_start TIME,
            school_hours_end TIME,
            timezone VARCHAR(50) DEFAULT 'Africa/Accra',
            language VARCHAR(20) DEFAULT 'en',
            currency VARCHAR(10) DEFAULT 'GHS',
            academic_year_start DATE,
            academic_year_end DATE,
            term_system ENUM('Term', 'Semester', 'Quarter') DEFAULT 'Term',
            terms_per_year INT DEFAULT 3,
            max_students_per_class INT DEFAULT 40,
            use_guardian_portal TINYINT(1) DEFAULT 1,
            enable_online_registration TINYINT(1) DEFAULT 1,
            enable_parent_app TINYINT(1) DEFAULT 1,
            google_maps_api_key VARCHAR(255),
            gps_coordinates VARCHAR(100),
            logo_url VARCHAR(255),
            favicon_url VARCHAR(255),
            header_color VARCHAR(7) DEFAULT '#1a1a2e',
            footer_color VARCHAR(7) DEFAULT '#1a1a2e',
            accent_color VARCHAR(7) DEFAULT '#4facfe',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down($db)
    {
        $tables = ['campus_settings', 'students', 'staff', 'persons', 'platform_user_roles', 'platform_users', 'classes', 'campuses', 'schools', 'tenants', 'roles'];
        foreach ($tables as $table) {
            $db->execute("DROP TABLE IF EXISTS `$table`");
        }
    }
}

interface MigrationInterface
{
    public function up($db);
    public function down($db);
}
