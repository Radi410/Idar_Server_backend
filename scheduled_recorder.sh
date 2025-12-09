#!/bin/bash
# /usr/local/bin/scheduled_recorder.sh

OUTPUT_DIR="/recordings"
STREAM_URL="https://stream.radionova.no/ogg"
LOG_FILE="/var/log/idar_recorder.log"

mkdir -p "$OUTPUT_DIR"

log_message() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

# Function to get next recording window
get_next_slot() {
    local current_hour=$(date +%H)
    local current_hour_int=$((10#$current_hour))
    
    # Recording windows: 06-08, 08-10, 10-12, 12-14, 14-16, 16-18, 18-20, 20-22, 22-24
    # Find next even hour >= 6 and < 24
    
    if [ $current_hour_int -lt 6 ]; then
        echo "06"
    elif [ $current_hour_int -ge 22 ]; then
        # After 22:00, next slot is 06:00 tomorrow
        echo "wait_until_morning"
    else
        # Round up to next even hour
        local next_hour=$(( (current_hour_int / 2 + 1) * 2 ))
        printf "%02d" $next_hour
    fi
}

# Wait until the start of next recording window
wait_for_next_slot() {
    local next_slot=$(get_next_slot)
    
    if [ "$next_slot" = "wait_until_morning" ]; then
        local tomorrow=$(date -d "tomorrow" +%Y-%m-%d)
        local target_time="${tomorrow} 06:00:00"
        log_message "Waiting until tomorrow morning at 06:00..."
    else
        local today=$(date +%Y-%m-%d)
        local target_time="${today} ${next_slot}:00:00"
    fi
    
    local target_epoch=$(date -d "$target_time" +%s)
    local current_epoch=$(date +%s)
    local sleep_seconds=$((target_epoch - current_epoch))
    
    if [ $sleep_seconds -gt 0 ]; then
        log_message "Sleeping for $sleep_seconds seconds until $target_time"
        sleep $sleep_seconds
    fi
}

record_chunk() {
    local start_time=$(date +%Y-%m-%d_%H%M)
    local filename="${OUTPUT_DIR}/${start_time}.wav"
    
    log_message "Starting recording: $filename"
    
    ffmpeg -i "$STREAM_URL" \
        -t 7200 \
        -acodec pcm_s16le \
        -ar 44100 \
        -ac 2 \
        "$filename" 2>&1 | tee -a "$LOG_FILE"
    
    if [ $? -eq 0 ]; then
        log_message "Successfully completed recording: $filename"
    else
        log_message "ERROR: Recording failed for $filename"
    fi
}

# Main loop
log_message "Idar recorder started"

while true; do
    current_hour=$(date +%H)
    current_hour_int=$((10#$current_hour))
    
    # Check if we're in recording window (06:00-23:59)
    if [ $current_hour_int -ge 6 ] && [ $current_hour_int -lt 24 ]; then
        # Check if we're at an even hour (start of 2-hour window)
        if [ $((current_hour_int % 2)) -eq 0 ]; then
            record_chunk
        else
            # We're at odd hour, wait for next even hour
            wait_for_next_slot
        fi
    else
        # Outside recording window, wait until 06:00
        wait_for_next_slot
    fi
done
