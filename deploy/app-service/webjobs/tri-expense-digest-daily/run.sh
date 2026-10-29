#!/usr/bin/env bash
# *** NOT ENABLED / NOT DEPLOYED. Cutover step only; see tc-web/docs/cc-te-migration/TRI-EXPENSES-SCHEDULERS.md ***
# Tri Expenses monthly manager digest: a TRIGGERED WebJob on the existing slot (no new Azure
# resource). Fires daily (settings.job cron, UTC); the dispatcher decides whether it is due:
# Melbourne day-of-month <= TRIEXPENSE_DIGEST_CATCH_UP_DAYS (default 7), and each manager is
# mailed once per month (idempotency key manager+month). Running daily or twice is harmless.
# Second gate: inert (exit 0) unless the app setting TRIEXPENSE_SCHEDULER_ENABLED=true.
# Mail still obeys TRIEXPENSE_EMAIL_ENABLED / TRIEXPENSE_EMAIL_TEST_RECIPIENT.
# Exit: 0 ok, 1 a manager's mail failed (retried next run), 5 another instance holds the lock.
set -u
if [ "${TRIEXPENSE_SCHEDULER_ENABLED:-false}" != "true" ]; then
    echo '{"event":"expense_scheduler","job":"digest","status":"disabled"}'
    exit 0
fi
cd /home/site/wwwroot/app || exit 1
exec php bin/run-tri-expense-schedulers.php --job=digest --catch-up-days="${TRIEXPENSE_DIGEST_CATCH_UP_DAYS:-7}"
