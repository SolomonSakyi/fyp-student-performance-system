#!/bin/bash

# ============================================
# Database Backup Script
# ============================================

# Configuration
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_NAME="${DB_NAME:-edutrack}"
BACKUP_DIR="./database/backups"
DATE=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="${BACKUP_DIR}/edutrack_backup_${DATE}.sql"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}EduTrack Database Backup Script${NC}"
echo -e "${GREEN}============================================${NC}"

# Create backup directory if it doesn't exist
if [ ! -d "$BACKUP_DIR" ]; then
    echo -e "${YELLOW}Creating backup directory: ${BACKUP_DIR}${NC}"
    mkdir -p "$BACKUP_DIR"
fi

# Check if mysqldump is available
if ! command -v mysqldump &> /dev/null; then
    echo -e "${RED}Error: mysqldump command not found.${NC}"
    echo -e "${YELLOW}Please install MySQL client tools.${NC}"
    exit 1
fi

# Build mysqldump command
DUMP_CMD="mysqldump -h ${DB_HOST} -P ${DB_PORT} -u ${DB_USER}"

# Add password if set
if [ -n "$DB_PASS" ]; then
    DUMP_CMD="${DUMP_CMD} -p${DB_PASS}"
fi

# Add database name
DUMP_CMD="${DUMP_CMD} ${DB_NAME}"

echo -e "${YELLOW}Starting backup of database: ${DB_NAME}${NC}"
echo -e "${YELLOW}Backup file: ${BACKUP_FILE}${NC}"

# Perform the backup
${DUMP_CMD} > "${BACKUP_FILE}"

# Check if backup was successful
if [ $? -eq 0 ]; then
    # Compress the backup
    gzip "${BACKUP_FILE}"
    echo -e "${GREEN}✓ Backup completed successfully!${NC}"
    echo -e "${GREEN}✓ Compressed backup: ${BACKUP_FILE}.gz${NC}"
    
    # Show file size
    FILE_SIZE=$(du -h "${BACKUP_FILE}.gz" | cut -f1)
    echo -e "${GREEN}✓ File size: ${FILE_SIZE}${NC}"
    
    # Keep only last 7 backups
    echo -e "${YELLOW}Cleaning up old backups (keeping last 7)...${NC}"
    cd "$BACKUP_DIR" && ls -t *.gz | tail -n +8 | xargs -r rm --
    echo -e "${GREEN}✓ Cleanup completed${NC}"
else
    echo -e "${RED}✗ Backup failed!${NC}"
    exit 1
fi

echo -e "${GREEN}============================================${NC}"