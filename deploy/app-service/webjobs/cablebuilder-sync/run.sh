#!/usr/bin/env bash
# CableBuilder nightly sync: a continuous WebJob on the tc-web core slot, the
# same pattern as rfq-reader. Wakes hourly; once the Melbourne clock is past
# CBSYNC_HOUR it runs `cbsync nightly` keyed by the local date, so it runs
# once a night however often it wakes, and a second instance during a slot
# swap finds the key done or the lock held and exits.
#
# WebJobs inherit the app settings: BLOB_CONNECTION_STRING (already set),
# CB_USER/CB_PASSWORD (CableBuilder sign-in), and the managed identity's
# IDENTITY_ENDPOINT/IDENTITY_HEADER for Dynamics. CBSYNC_ENABLED must be
# "true", so the job ships dark and is switched on with an app setting.
#
# The python lives at app/cbsync with its dependencies vendored beside it,
# because this image has python3 but no venv (see startup.sh). Anything the
# finder's tree holds at the identical version (cryptography, cffi, ...) is left
# out of cbsync/_vendor at build time and found in python/_vendor, last on the path.
set -u
APP=/home/site/wwwroot/app/cbsync
# Wheels are per CPython minor version (cp312 on PHP|8.5; the release no longer ships cp311).
PYTAG="cp$(python3 -c 'import sys; print("%d%d" % sys.version_info[:2])')"
export PYTHONPATH="$APP:$APP/_vendor/$PYTAG:/home/site/wwwroot/app/python/_vendor/$PYTAG"
# Without this, Python block-buffers stdout into the WebJob log pipe, so a run's
# output only appears when it ends, and never if a restart (any app-setting
# change) kills it mid-run.
export PYTHONUNBUFFERED=1
HOUR="${CBSYNC_HOUR:-1}"
STORE="${CBSYNC_STORE:-blob:cablebuilder}"
ROTATE="${CBSYNC_ROTATE_PAGES:-70}"
# The WebJob log stops taking output once a continuous job's process has
# written its line limit, and this process lives for weeks, so each night's
# full output goes to its own file here; the WebJob log gets one line a night.
LOG_DIR=/home/LogFiles/cablebuilder-sync
mkdir -p "$LOG_DIR"

while true; do
    if [ "${CBSYNC_ENABLED:-false}" = "true" ]; then
        local_hour=$(TZ=Australia/Melbourne date +%-H)
        key=$(TZ=Australia/Melbourne date +%F)
        if [ "$local_hour" -ge "$HOUR" ]; then
            log="$LOG_DIR/$key.log"
            before=$(stat -c %s "$log" 2>/dev/null || echo 0)
            echo "[$(date -u +%FT%TZ)] cbsync nightly $key" >>"$log"
            python3 -m cbsync --store "$STORE" nightly --run-key "$key" --rotate-pages "$ROTATE" >>"$log" 2>&1
            status=$?
            echo "[$(date -u +%FT%TZ)] exited $status" >>"$log"
            # Hourly wake-ups that find the night done or locked stay out of the WebJob log.
            if [ "$status" -ne 0 ]; then
                echo "[$(date -u +%FT%TZ)] cbsync nightly $key exited $status; see $log" >&2
            elif tail -c +"$((before + 1))" "$log" | grep -q '^run record:'; then
                echo "[$(date -u +%FT%TZ)] cbsync nightly $key done; see $log"
            fi
            find "$LOG_DIR" -name '*.log' -mtime +60 -delete
        fi
    fi
    sleep 3600
done
