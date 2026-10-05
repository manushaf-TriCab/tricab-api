#!/usr/bin/env bash
# RFQ background reader (plan 3.5 / 6.5): an App Service continuous WebJob on the
# existing slot, so no new Azure resource. Runs one pass a minute, as the source's
# `rfq-portal-worker-webjob` did.
#
# WebJobs inherit the app settings. The worker is safe to run twice at once (a
# claim returns only the jobs that call claimed), so a second instance during a
# slot swap does no harm. When the parser is off the worker exits 2; sleeping
# here stops the WebJob host restarting it in a tight loop.
set -u
cd /home/site/wwwroot/app || exit 1
while true; do
    php bin/run-rfq-reader-jobs.php --drafts=1
    status=$?
    if [ "$status" -ne 0 ]; then
        echo "[$(date -u +%FT%TZ)] rfq reader pass exited $status" >&2
    fi
    sleep 60
done
