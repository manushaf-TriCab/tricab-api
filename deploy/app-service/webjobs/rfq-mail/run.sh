#!/usr/bin/env bash
# RFQ mail reader (RFQ plan M3/M4): an App Service continuous WebJob on the existing slot, so no new Azure
# resource. One pass a minute over melbourne@'s Inbox, shadow mode: classify and record, no drafts.
#
# A pass asks Microsoft Graph what changed and touches the serverless `tc` database only when Graph
# returns messages (plus a rare heartbeat), so `tc` can still pause. Nothing happens unless both
# RFQ_MAIL_READ_ENABLED=true and RFQ_MAIL_MODE=shadow|live are app settings; until then each pass exits 3
# at once, without reading anything.
#
# Exit codes of bin/run-rfq-mail-reader.php (docs/runbooks/rfq-mail-reader.md):
#   0  pass done / nothing to do      1  unexpected failure     2  switched on but not configured
#   3  paused by design (silent here) 4  Graph refused/unreachable   5  another pass holds the lock
#  75  database resuming (cursor unchanged; the next pass retries)
# On 1, 2, 4, 5 and 75 the delta cursor is where the last finished page left it, so nothing is lost.
#
# Safe to run twice at once (a slot swap starts a second instance): a lock file under var/rfq-mail/
# makes the second pass exit 5, and the recorder dedupes on internetMessageId anyway. Sleeping here
# stops the WebJob host restarting the script in a tight loop.
set -u
cd /home/site/wwwroot/app || exit 1
while true; do
    php bin/run-rfq-mail-reader.php
    status=$?
    if [ "$status" -ne 0 ] && [ "$status" -ne 3 ]; then
        echo "[$(date -u +%FT%TZ)] rfq mail pass exited $status" >&2
    fi
    sleep 60
done
