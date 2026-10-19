#!/usr/bin/env bash
# NPR workflow email retry sweep (plan N2.4): an App Service continuous WebJob on the
# existing slot, so no new Azure resource. A pass a minute, like rfq-reader.
#
# `--if-woken` keeps idle passes off the database: a pass looks at the outbox only after a
# failed send signals (the wake file under NPR_MAIL_WAKE_PATH), while retryable rows are
# outstanding, or once a day, so the serverless `tc` database can still pause. With
# NPR_MAIL_MODE=off the sweep exits 3 without touching the database; sleeping here stops
# the WebJob host restarting it in a tight loop.
set -u
cd /home/site/wwwroot/app || exit 1
while true; do
    php bin/run-npr-mail-sweep.php --if-woken
    status=$?
    if [ "$status" -ne 0 ] && [ "$status" -ne 3 ]; then
        echo "[$(date -u +%FT%TZ)] npr mail sweep exited $status" >&2
    fi
    sleep 60
done
