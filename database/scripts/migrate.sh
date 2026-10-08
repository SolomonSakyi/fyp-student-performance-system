#!/bin/bash

# ============================================
# Database Migration Script
# ============================================

# Configuration
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_NAME="${DB_NAME:-edutrack}"
MIGRATIONS_DIR="./database/migrations"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}EduTrack Database Migration Script${NC}"
echo -e "${GREEN}============================================${NC}"

# Check if MySQL client is available
if ! command -v mysql &> /dev/null; then
    echo -e "${RED}Error: mysql command not found.${NC}"
    echo -e "${YELLOW}Please install MySQL client tools.${NC}"
    exit 1
fi

# Build MySQL command
MYSQL_CMD="mysql -h ${DB_HOST} -P ${DB_PORT} -u ${DB_USER}"

# Add password if set
if [ -n "$DB_PASS" ]; then
    MYSQL_CMD="${MYSQL_CMD} -p${DB_PASS}"
fi

# Add database name
MYSQL_CMD="${MYSQL_CMD} ${DB_NAME}"

# Function to run migrations from a directory
run_migrations() {
    local version_dir=$1
    echo -e "${YELLOW}Running migrations from: ${version_dir}${NC}"
    
    if [ -d "$version_dir" ]; then
        for file in $(ls -1 "$version_dir"/*.sql 2>/dev/null | sort); do
            echo -e "  - $(basename "$file")"
            if ! ${MYSQL_CMD} < "$file" 2>&1; then
                echo -e "${RED}  ✗ Migration failed: $(basename "$file")${NC}"
                echo -e "${RED}  Rolling back...${NC}"
                exit 1
            fi
            echo -e "${GREEN}  ✓ Migration completed: $(basename "$file")${NC}"
        done
    else
        echo -e "${YELLOW}  No migrations found in: ${version_dir}${NC}"
    fi
}

# Check if database exists
echo -e "${YELLOW}Checking if database '${DB_NAME}' exists...${NC}"
DB_EXISTS=$(${MYSQL_CMD} -e "SHOW DATABASES LIKE '${DB_NAME}'" | grep -c "${DB_NAME}")

if [ "$DB_EXISTS" -eq 0 ]; then
    echo -e "${YELLOW}Database '${DB_NAME}' does not exist. Creating...${NC}"
    ${MYSQL_CMD} -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    echo -e "${GREEN}✓ Database created${NC}"
fi

echo -e "${YELLOW}Starting migration process...${NC}"
echo -e ""

# Run migrations in order
run_migrations "${MIGRATIONS_DIR}/v1.0.0"
run_migrations "${MIGRATIONS_DIR}/v1.0.1"
run_migrations "${MIGRATIONS_DIR}/v1.0.2"

echo -e ""
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}✓ All migrations completed successfully!${NC}"
echo -e "${GREEN}============================================${NC}"