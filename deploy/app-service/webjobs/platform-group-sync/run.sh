#!/usr/bin/env bash
# Platform group sync (Tri-Task company plan, Phase 1): an App Service continuous WebJob on the existing
# slot, so no new Azure resource. Brings the members of Entra-linked groups in line with Entra, read-only
# (src/Identity/Groups/GroupSync.php). Platform groups are never touched.
#
# Nothing happens unless IDENTITY_GROUP_SYNC_ENABLED=true is an app setting: each pass exits 3 at once
# and touches no database. It needs the Graph permission GroupMember.Read.All (tenant admin consent);
# without it a pass changes nothing, notes the reason on the linked groups and exits 6.
#
# A pass is tried every 2 hours on weekdays, 06:00-20:00 Melbourne time, and not otherwise, so the serverless
# platform database can still pause overnight and at weekends. After exit 6 (permission not granted or Graph
# not configured: needs a person) the job waits 6 hours before asking again, so it can never become a loop.
#
# Exit codes of bin/sync-platform-groups.php: 0 done, 1 failure (some group failed; the rest synced), 3 switched
# off (silent here), 6 permission not granted / Graph not configured, 75 database resuming.
#
# Safe to run twice at once (a slot swap starts a second instance): each group's sync takes a row lock, and
# applying the same membership twice changes nothing.
set -u
cd /home/site/wwwroot/app || exit 1
while true; do
    wait=7200
    hour=$(TZ=Australia/Melbourne date +%H)
    weekday=$(TZ=Australia/Melbourne date +%u)
    if [ "$weekday" -le 5 ] && [ "$((10#$hour))" -ge 6 ] && [ "$((10#$hour))" -lt 20 ]; then
        php -d memory_limit=512M bin/sync-platform-groups.php --if-enabled
        status=$?
        if [ "$status" -eq 6 ]; then
            echo "[$(date -u +%FT%TZ)] platform group sync: Graph permission not granted or not configured; waiting 6 hours" >&2
            wait=21600
        elif [ "$status" -ne 0 ] && [ "$status" -ne 3 ]; then
            echo "[$(date -u +%FT%TZ)] platform group sync exited $status" >&2
        fi
    fi
    sleep "$wait"
done
