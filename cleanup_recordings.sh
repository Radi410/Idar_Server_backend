#!/bin/bash
# /usr/local/bin/cleanup_recordings.sh

OUTPUT_DIR="/recordings"
RETENTION_HOURS=48
LOG_FILE="/var/log/idar_cleanup.log"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting cleanup..." >> "$LOG_FILE"

# Delete files older than 48 hours
find "$OUTPUT_DIR" -name "*.wav" -type f -mmin +$((RETENTION_HOURS * 60)) -delete -print >> "$LOG_FILE" 2>&1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Cleanup completed" >> "$LOG_FILE"
