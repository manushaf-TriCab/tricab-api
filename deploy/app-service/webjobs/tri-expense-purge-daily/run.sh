#!/usr/bin/env bash
# *** NOT ENABLED / NOT DEPLOYED. Cutover step only; see tc-web/docs/cc-te-migration/TRI-EXPENSES-SCHEDULERS.md ***
# Tri Expenses 30-day recycle-bin purge: a TRIGGERED WebJob, daily (settings.job cron, UTC).
# DRY RUN by default: it only deletes when the app setting TRIEXPENSE_PURGE_APPLY=true, and in
# production that setting also supplies the explicit acknowledgement flag. Re-running is safe
# (already-deleted rows are gone; WordPress-owned attachments are skipped and reported).
# Second gate: inert (exit 0) unless TRIEXPENSE_SCHEDULER_ENABLED=true.
# Exit: 0 ok, 1 a receipt failed to delete, 5 another instance holds the lock.
set -u
if [ "${TRIEXPENSE_SCHEDULER_ENABLED:-false}" != "true" ]; then
    echo '{"event":"expense_scheduler","job":"purge","status":"disabled"}'
    exit 0
fi
cd /home/site/wwwroot/app || exit 1
args=(--job=purge)
if [ "${TRIEXPENSE_PURGE_APPLY:-false}" = "true" ]; then
    args+=(--purge-apply --i-understand-this-deletes-data)
fi
exec php bin/run-tri-expense-schedulers.php "${args[@]}"
