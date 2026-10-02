#!/usr/bin/env bash
set -euo pipefail

DB_NAME="${1:-lms}"
DB_USER="${2:-lms}"
DB_HOST="${3:-localhost}"
BACKUP_DIR="${4:-/backups/daily}"
RETENTION_DAYS="${5:-30}"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
FILENAME="${BACKUP_DIR}/${DB_NAME}_${TIMESTAMP}.dump"

mkdir -p "$BACKUP_DIR"

pg_dump -U "$DB_USER" -h "$DB_HOST" -d "$DB_NAME" -Fc -f "$FILENAME"

gzip "$FILENAME"

echo "Backup created: ${FILENAME}.gz"

find "$BACKUP_DIR" -name "${DB_NAME}_*.dump.gz" -mtime +"$RETENTION_DAYS" -delete

echo "Old backups (>${RETENTION_DAYS}d) cleaned."
