#!/usr/bin/env bash
# RFQ legacy refresh (RFQ plan 7.2-7.4): an App Service continuous WebJob on the existing slot, so no new
# Azure resource. Brings tc-web's RFQ changes into the platform's [rfq] tables until cutover, keeping work
# done on the platform; where both sides changed an RFQ, tc-web wins and the run reports it
# (src/Rfq/Legacy/LegacyRfqStoreSync.php).
#
# Nothing happens unless RFQ_LEGACY_SYNC_ENABLED=true is an app setting: each pass exits 3 at once.
# Passes run every 30 minutes on weekdays, 06:00-20:00 Melbourne time, and not otherwise, so the
# serverless `tc` database can still pause overnight and at weekends.
#
# Exit codes of bin/sync-rfq-legacy-store.php: 0 done, 1 failure, 2 refused (tables changed or no
# ledger; needs a person), 3 switched off (silent here), 5 another pass holds the lock, 75 database
# resuming. A failed pass writes nothing (one transaction), so the next pass simply tries again.
#
# Safe to run twice at once (a slot swap starts a second instance): the second pass finds the
# SQL Server application lock taken and exits 5.
set -u
cd /home/site/wwwroot/app || exit 1
while true; do
    hour=$(TZ=Australia/Melbourne date +%H)
    weekday=$(TZ=Australia/Melbourne date +%u)
    if [ "$weekday" -le 5 ] && [ "$((10#$hour))" -ge 6 ] && [ "$((10#$hour))" -lt 20 ]; then
        php -d memory_limit=1G bin/sync-rfq-legacy-store.php --apply --if-enabled
        status=$?
        if [ "$status" -ne 0 ] && [ "$status" -ne 3 ]; then
            echo "[$(date -u +%FT%TZ)] rfq legacy refresh exited $status" >&2
        fi
    fi
    sleep 1800
done
