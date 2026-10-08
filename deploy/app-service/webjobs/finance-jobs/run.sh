#!/usr/bin/env bash
# Finance jobs (F1.8): an App Service continuous WebJob on the existing slot, so no new
# Azure resource. Runs one pass a minute of `bin/run-finance-jobs.php`, which runs the
# finance jobs that are due, each once.
#
# WebJobs inherit the app settings. The runner is safe to run twice at once (a due slot
# of a job is claimed in finance.job_runs, so it runs once), so a second instance during a
# slot swap does no harm. While FINANCE_MODULE_ENABLED is not true the runner prints one
# line and exits 0, so this loop is inert and cheap; it does not touch the database then.
set -u
cd /home/site/wwwroot/app || exit 1
while true; do
    php bin/run-finance-jobs.php
    status=$?
    if [ "$status" -ne 0 ]; then
        echo "[$(date -u +%FT%TZ)] finance jobs pass exited $status" >&2
    fi
    sleep 60
done
