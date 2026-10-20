#!/usr/bin/env bash
# CableBuilder nightly sync: a continuous WebJob on the tc-web core slot, the
# same pattern as rfq-reader. Wakes hourly; once the Melbourne clock is past
# CBSYNC_HOUR it runs `cbsync nightly` keyed by the local date, so it runs
# once a night however often it wakes, and a second instance during a slot
# swap finds the key done or the lock held and exits.
#
# WebJobs inherit the app settings: BLOB_CONNECTION_STRING (already set),
# CB_COOKIE (CableBuilder session), and the managed identity's
# IDENTITY_ENDPOINT/IDENTITY_HEADER for Dynamics. CBSYNC_ENABLED must be
# "true", so the job ships dark and is switched on with an app setting.
#
# The python lives at app/cbsync with its dependencies vendored beside it,
# because this image has python3 3.11 but no venv (see startup.sh).
set -u
APP=/home/site/wwwroot/app/cbsync
export PYTHONPATH="$APP:$APP/_vendor"
HOUR="${CBSYNC_HOUR:-1}"
STORE="${CBSYNC_STORE:-blob:cablebuilder}"
ROTATE="${CBSYNC_ROTATE_PAGES:-70}"

while true; do
    if [ "${CBSYNC_ENABLED:-false}" = "true" ]; then
        local_hour=$(TZ=Australia/Melbourne date +%-H)
        key=$(TZ=Australia/Melbourne date +%F)
        if [ "$local_hour" -ge "$HOUR" ]; then
            python3 -m cbsync --store "$STORE" nightly --run-key "$key" --rotate-pages "$ROTATE"
            status=$?
            if [ "$status" -ne 0 ]; then
                echo "[$(date -u +%FT%TZ)] cbsync nightly $key exited $status" >&2
            fi
        fi
    fi
    sleep 3600
done
