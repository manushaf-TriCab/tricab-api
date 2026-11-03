#!/usr/bin/env bash
# Cable routes data refresh (Production module plan 3.2, decision D1): an App Service continuous WebJob on the
# existing slot, so no new Azure resource. Pulls the production orders, BOM lines, route operations, released
# products and purchase-order lines from Dynamics 365 OData, rebuilds the SQLite store in a temp file next to it,
# checks it, and swaps it in with one rename (core/python/cable_routes/refresh.py). Replaces the hand upload.
#
# Nothing happens unless CABLE_ROUTES_REFRESH_ENABLED=true is an app setting: each pass exits 3 at once, silently.
# Needs CABLE_ROUTES_DB_PATH (already set) and Dynamics access: the slot's managed identity (IDENTITY_ENDPOINT),
# which a D365 administrator must allow to read ProductionOrderHeaders, ProductionOrderBillOfMaterialLines,
# ProductionOrderRouteOperations, ReleasedProductsV2 and PurchaseOrderLinesV2; or D365_TENANT_ID/D365_CLIENT_ID/
# D365_CLIENT_SECRET. Optional: D365_URL, D365_LEGAL_ENTITY (AU1O), CABLE_ROUTES_REFRESH_HOUR (3),
# CABLE_ROUTES_REFRESH_MIN_RATIO (0.8).
#
# Once a day from CABLE_ROUTES_REFRESH_HOUR (Melbourne) for three hours. A pass that succeeds (exit 0) or is refused
# (2: the new store looked collapsed, needs a person, so retrying would only repeat it) stops for the day; a failure
# or an unreachable Dynamics (1, 4) is retried every 15 minutes inside the window, then waits for tomorrow.
#
# Exit codes of `python3 -m cable_routes refresh`: 0 swapped, 1 failure, 2 refused, 3 switched off, 4 Dynamics
# unreachable or refused, 5 another pass holds the lock. The live store is untouched in every case but 0.
#
# Each pass's full output goes to /home/LogFiles/cable-routes-refresh/<Melbourne date>.log (kept 60 days); the
# WebJob log gets one line per pass, because a continuous job's log stops taking output after a line limit.
set -u
APP=/home/site/wwwroot/app
export PYTHONPATH="$APP/python"
export PYTHONUNBUFFERED=1
HOUR="${CABLE_ROUTES_REFRESH_HOUR:-3}"
case "$HOUR" in ''|*[!0-9]*) HOUR=3 ;; esac
LOG_DIR=/home/LogFiles/cable-routes-refresh
mkdir -p "$LOG_DIR"
done_day=""
while true; do
    hour=$(TZ=Australia/Melbourne date +%H)
    today=$(TZ=Australia/Melbourne date +%F)
    if [ "$today" != "$done_day" ] && [ "$((10#$hour))" -ge "$((10#$HOUR))" ] && [ "$((10#$hour))" -lt "$((10#$HOUR + 3))" ] \
        && [ "${CABLE_ROUTES_REFRESH_ENABLED:-false}" = "true" ]; then
        log="$LOG_DIR/$today.log"
        echo "[$(date -u +%FT%TZ)] cable routes refresh" >>"$log"
        python3 -m cable_routes refresh --if-enabled >>"$log" 2>&1
        status=$?
        echo "[$(date -u +%FT%TZ)] exited $status" >>"$log"
        case "$status" in
            0) done_day="$today"; echo "[$(date -u +%FT%TZ)] cable routes refresh done: $(grep 'refresh ok' "$log" | tail -1)" ;;
            2) done_day="$today"; echo "[$(date -u +%FT%TZ)] cable routes refresh REFUSED, live store kept; see $log" >&2 ;;
            3|5) ;;
            *) echo "[$(date -u +%FT%TZ)] cable routes refresh exited $status; see $log" >&2 ;;
        esac
        find "$LOG_DIR" -name '*.log' -mtime +60 -delete
    fi
    sleep 900
done
