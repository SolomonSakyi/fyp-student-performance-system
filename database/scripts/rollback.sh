#!/bin/bash

# ============================================
# Database Rollback Script
# ============================================

# Configuration
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_NAME="${DB_NAME:-edutrack}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${RED}============================================${NC}"
echo -e "${RED}EduTrack Database Rollback Script${NC}"
echo -e "${RED}============================================${NC}"
echo -e "${RED}⚠️  WARNING: This will DROP all tables!${NC}"
echo -e "${RED}⚠️  Proceed with caution!${NC}"
echo -e ""

read -p "Are you sure you want to rollback? (yes/no): " confirm

if [ "$confirm" != "yes" ]; then
    echo -e "${YELLOW}Rollback cancelled.${NC}"
    exit 0
fi

# Build MySQL command
MYSQL_CMD="mysql -h ${DB_HOST} -P ${DB_PORT} -u ${DB_USER}"

if [ -n "$DB_PASS" ]; then
    MYSQL_CMD="${MYSQL_CMD} -p${DB_PASS}"
fi

# Drop all tables
echo -e "${YELLOW}Dropping all tables from database: ${DB_NAME}${NC}"

${MYSQL_CMD} "${DB_NAME}" << EOF
SET FOREIGN_KEY_CHECKS = 0;
SELECT CONCAT('DROP TABLE IF EXISTS \`', table_name, '\`;') 
FROM information_schema.tables 
WHERE table_schema = '${DB_NAME}'
INTO OUTFILE '/tmp/drop_tables.sql';
SOURCE /tmp/drop_tables.sql;
SET FOREIGN_KEY_CHECKS = 1;
EOF

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ All tables dropped successfully${NC}"
    echo -e "${GREEN}✓ Database is now empty${NC}"
else
    echo -e "${RED}✗ Rollback failed!${NC}"
    exit 1
fi

echo -e "${GREEN}============================================${NC}"