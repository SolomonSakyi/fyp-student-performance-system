# EduTrack People Registration - Database Migration

## Version: 1.0
## Date: 2025-01-01

---

## Overview

This migration creates all database tables required for the People Registration subsystem (EIS-01.3).

---

## Table of Contents

| Section | Tables | Description |
|---------|--------|-------------|
| 1 | Core People Tables | `people`, `person_contacts`, `person_addresses`, `person_photos`, `person_documents`, `document_types`, `person_relationships`, `relationship_types` |
| 2 | Student Tables | `student_profiles`, `guardian_profiles`, `student_guardian_relationships` |
| 3 | Health Tables | `health_profiles` |
| 4 | Staff Tables | `staff_profiles`, `staff_categories`, `departments`, `positions`, `staff_qualifications`, `staff_professional_records`, `staff_training_records`, `staff_employment_history`, `teaching_profiles` |
| 5 | Seed Data | Default configuration data |
| 6 | Audit | `audit_logs` |

---

## Installation

### Option 1: MySQL Command Line

```bash
mysql -u root -p student_performance_system < database/migrations/2025_01_01_000001_create_people_tables.sql