#!/usr/bin/env bash
# CableFit research agent (C3): an App Service continuous WebJob on the existing slot, so no new Azure
# resource. Runs one pass a minute, as the RFQ reader does.
#
# `--if-woken` keeps idle passes off the database: a pass reads the queue only after a research request
# signals, while runs are outstanding, or once a day (CABLEFIT_RESEARCH_IDLE_CHECK_MINUTES), so the
# serverless `tc` database can still pause. Exit 75 is that database resuming.
#
# WebJobs inherit the app settings. The worker takes `var/cablefit-research/pass.lock`, so a second instance
# during a slot swap exits 5 and does no harm. While CABLEFIT_RESEARCH_ENABLED is not true the worker exits 3;
# sleeping here stops the WebJob host restarting it in a tight loop.
set -u
cd /home/site/wwwroot/app || exit 1
while true; do
    php bin/run-cablefit-research.php --runs=3 --if-woken
    status=$?
    if [ "$status" -ne 0 ] && [ "$status" -ne 3 ]; then
        echo "[$(date -u +%FT%TZ)] cablefit research pass exited $status" >&2
    fi
    sleep 60
done
