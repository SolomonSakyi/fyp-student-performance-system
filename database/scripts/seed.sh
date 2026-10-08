#!/bin/bash

# ============================================
# Database Seeder Script
# ============================================

# Configuration
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_NAME="${DB_NAME:-edutrack}"
SEEDERS_DIR="./database/seeders"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}EduTrack Database Seeder Script${NC}"
echo -e "${GREEN}============================================${NC}"

# Check if MySQL client is available
if ! command -v mysql &> /dev/null; then
    echo -e "${RED}Error: mysql command not found.${NC}"
    echo -e "${YELLOW}Please install MySQL client tools.${NC}"
    exit 1
fi

# Build MySQL command
MYSQL_CMD="mysql -h ${DB_HOST} -P ${DB_PORT} -u ${DB_USER}"

if [ -n "$DB_PASS" ]; then
    MYSQL_CMD="${MYSQL_CMD} -p${DB_PASS}"
fi

MYSQL_CMD="${MYSQL_CMD} ${DB_NAME}"

echo -e "${YELLOW}Running seeders...${NC}"
echo -e ""

# Run each seeder file in order
for file in $(ls -1 "$SEEDERS_DIR"/*.sql 2>/dev/null | sort); do
    echo -e "  - $(basename "$file")"
    if ! ${MYSQL_CMD} < "$file" 2>&1; then
        echo -e "${RED}  ✗ Seeder failed: $(basename "$file")${NC}"
        exit 1
    fi
    echo -e "${GREEN}  ✓ Seeder completed: $(basename "$file")${NC}"
done

echo -e ""
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}✓ All seeders completed successfully!${NC}"
echo -e "${GREEN}============================================${NC}"