# HR employee sync (payroll → platform)

Payroll's export lands in the old app's database `tc-mysql-live` / `tc` as `employee` and `dept_manager`. This sync copies it into the platform's `[hr]` tables on tricab-mel. It is a read-only mirror: it never writes to `tc`, never deletes platform rows, and edits are made in payroll.

- **Code:** `src/Hr/Employees/HrEmployeeSync.php`
- **CLI:** `bin/sync-hr-employees.php`
- **WebJob:** `hr-employee-sync`
- **Plan:** `platform/plans/HR-EMPLOYEES-PLAN.md` (items 1.4–1.8)

## The rule

- **Upsert by employee code.** A row whose source hash is unchanged is not rewritten.
- **Two tables per person.** `[hr].[Employees]` holds the directory fields. Date of birth, home address, mobile, personal email and leave balances go only to `[hr].[EmployeeRestrictedDetails]`.
- **Work vs personal email.** An address ending `@tricab.com` is the work email. Any other address is personal and is stored as restricted.
- **Current** means the employee's `update_date` equals the newest `update_date` in `tc.employee` (the latest payroll refresh).
- **Short-refresh guard.** If the new current set is under 80% of the previous one (`HrEmployeeSync::SHORT_REFRESH_RATIO`), no `IsCurrent` flag changes. Other fields are still copied and the run is recorded as `guarded` with a warning. A short export must not turn staff into leavers.
- **Never deleted.** A row that has left `tc.employee` gets `MissingFromSourceAt` (once) and stops being current. It stays, with its restricted details.
- **Managers** are kept as the manager's login text, even when it matches no employee. `dept_manager` is mirrored into `[hr].[DepartmentManagers]` (upsert only).
- **Platform user link.** `UserId` comes from `[migration].[LegacyStaffUserMappings]` (the staff migration's login to user mapping), matched on login, case-insensitively. Never by email, and it never creates users. Employees the staff migration did not map stay unlinked and are counted in `unmatchedLogins`.

## Running it

```bash
# From tricab-api, with live settings (DB_* and LEGACY_TC_*):
php bin/sync-hr-employees.php --env=../local-db/env.live            # dry run: what it would do, writes nothing
php bin/sync-hr-employees.php --env=../local-db/env.live --apply    # sync
```

- **Output:** one JSON line of counts (read, inserted, updated, unchanged, missing, current, unmatched logins, latest payroll date, department managers, warning). It never contains an employee's values.
- **Safety:** every statement to `tc` passes the legacy read-only guard (`ReadOnlyLegacyQuery`); anything that is not a SELECT fails the run. An apply is one transaction under a SQL Server application lock.
- **Run history:** every apply is recorded in `[hr].[SyncRuns]`; a failed apply is recorded as `failed` with the error. A dry run records nothing.

| Exit | Meaning |
|---|---|
| 0 | Done, or nothing to do |
| 1 | Failure: nothing written; the next pass retries |
| 2 | Refused: the `[hr]` schema is not on this database, or `tc.employee` came back empty. **Needs a person.** |
| 3 | Switched off (`--if-enabled` without `HR_EMPLOYEE_SYNC_ENABLED=true`) |
| 5 | Another sync holds the lock |
| 75 | The serverless database is resuming; the next pass retries |

## The WebJob

`hr-employee-sync` is a continuous WebJob on the core slot.
- **Schedule:** it tries a pass between 05:00 and 08:00 Melbourne time (the script checks `TZ=Australia/Melbourne`; `settings.job` carries no schedule) and stops for the day after the first success. A failed pass is retried every 15 minutes until 08:00. Payroll refreshes about fortnightly, so once a day is plenty. The loop touches no database, so `tc` and the platform database can pause the rest of the day. There is no wake-file gate, unlike the RFQ reader, because the job does not poll.
- **Switch:** it does nothing until the app setting `HR_EMPLOYEE_SYNC_ENABLED=true` is set. It needs no other settings: it uses the API's database connection and the existing read-only `LEGACY_TC_*`. It is off by default.
- **To stop it:** remove the setting, or set it to anything else.

## Reading a run

- `status: guarded` with a warning: the newest refresh was short. Check with payroll (the export owner is not known yet), and the next full refresh clears it by itself.
- `unmatchedLogins` counts current employees with no platform user; `unmatchedLoginsAll` counts everyone.
- `rowsMissing` is the number of employees newly flagged as gone from `tc.employee` in this run.

## Department map (phase 4)

Raw department and factory values are mapped to clean names in `[hr].[DepartmentMap]` (script `create-hr-department-map.sql`, apply before the API that reads it). HR edits it on the Department map page; the sync never touches it. Reading needs `HR.EMPLOYEES.READ`, editing needs `HR.EMPLOYEES.SYNC`, and every change is a row in `[audit].[HrDepartmentMapEvents]`. An unmapped value shows as itself in the directory and filters.
