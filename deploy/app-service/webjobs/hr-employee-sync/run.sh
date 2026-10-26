#!/usr/bin/env bash
# HR employee sync (HR employees plan 1.8): an App Service continuous WebJob on the existing slot, so no
# new Azure resource. Copies payroll's tc.employee and tc.dept_manager into the platform's [hr] tables,
# read-only, never deleting (src/Hr/Employees/HrEmployeeSync.php). Payroll refreshes land about
# fortnightly, so once a day is plenty.
#
# Nothing happens unless HR_EMPLOYEE_SYNC_ENABLED=true is an app setting: each pass exits 3 at once.
# A pass is tried between 05:00 and 08:00 Melbourne time and stops for the day once it succeeds (exit 0),
# so the serverless `tc` and platform databases are woken at most a few times a morning and can pause the
# rest of the day. The loop itself touches no database; a failed pass (1, 5, 75) is retried every 15 minutes
# until 08:00, then waits for tomorrow.
#
# Exit codes of bin/sync-hr-employees.php: 0 done, 1 failure, 2 refused (wrong server or tc returned no
# rows; needs a person, tried again next pass), 3 switched off (silent here), 5 another pass holds the lock,
# 75 database resuming. A failed pass writes nothing (one transaction).
#
# Safe to run twice at once (a slot swap starts a second instance): the second pass finds the SQL Server
# application lock taken and exits 5.
set -u
cd /home/site/wwwroot/app || exit 1
done_day=""
while true; do
    hour=$(TZ=Australia/Melbourne date +%H)
    today=$(TZ=Australia/Melbourne date +%F)
    if [ "$today" != "$done_day" ] && [ "$((10#$hour))" -ge 5 ] && [ "$((10#$hour))" -lt 8 ]; then
        php -d memory_limit=512M bin/sync-hr-employees.php --apply --if-enabled
        status=$?
        if [ "$status" -eq 0 ]; then
            done_day="$today"
        elif [ "$status" -ne 3 ]; then
            echo "[$(date -u +%FT%TZ)] hr employee sync exited $status" >&2
        fi
    fi
    sleep 900
done
