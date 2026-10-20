# RFQ legacy refresh (tc-web → platform)

Until cutover, sales still work RFQs in tc-web, whose store is the MySQL database `tc-mysql-live` / `tc`. This refresh brings those changes into the platform's `[rfq]` tables on tricab-mel. It does not undo work done on the platform.

- **Code:** `src/Rfq/Legacy/LegacyRfqStoreSync.php`
- **CLI:** `bin/sync-rfq-legacy-store.php`
- **WebJob:** `rfq-legacy-sync`
- **Plan:** RFQ plan 7.2–7.4

## The rule

Each run compares three versions of every imported row: tc-web now, the platform now, and tc-web as last imported. The last one is kept in the ledger, `[rfq].[rfq_legacy_import_rows]`.

| tc-web | Platform | Result |
|---|---|---|
| changed | unchanged | tc-web's version is taken |
| unchanged | changed | the platform's version is kept |
| changed | changed | **tc-web wins until cutover**; reported as a conflict |
| deleted | unchanged | deleted |
| deleted | changed, or still referenced | kept; reported as a conflict |
| — | made on the platform (no ledger row) | never touched |

- **An RFQ is one unit.** If tc-web changed its header or any line, the platform takes tc-web's header and exactly tc-web's lines.
- **Logs are merged.** Activity, edit and review logs only ever grow, so they keep rows from both sides.
- **Nothing outside the database runs.** The refresh never calls the parser, pricing, mail or 4js, and imported jobs, notifications and sync attempts keep their states.
- **Attachments are not part of it.** tc-web deletes each upload once it has been read, so there are no files to bring across.

## Running it

```bash
# From tricab-api, with live settings (DB_* and LEGACY_TC_*):
php bin/sync-rfq-legacy-store.php --env=../local-db/env.live            # dry run: what it would do
php bin/sync-rfq-legacy-store.php --env=../local-db/env.live --apply    # refresh
```

- **Output:** one JSON line with counts per table, conflicts (first 200) and seconds per phase.
- **Safety:** an apply is one transaction under a SQL Server application lock, and every written row is checked against tc-web's before COMMIT.
- **Run history:** every run is recorded in `[rfq].[rfq_legacy_import_runs]`.

| Exit | Meaning |
|---|---|
| 0 | Done, or nothing to do |
| 1 | Failure: nothing written; the next pass retries |
| 2 | Refused: no ledger, tc-web's tables changed shape, or rows are no longer the import. **Needs a person.** |
| 3 | Switched off (`--if-enabled` without `RFQ_LEGACY_SYNC_ENABLED=true`) |
| 5 | Another refresh holds the lock |
| 75 | The serverless database is resuming; the next pass retries |

## The WebJob

`rfq-legacy-sync` is a continuous WebJob on the core slot.
- **Schedule:** a pass every 30 minutes on weekdays, 06:00–20:00 Melbourne time, so `tc` can pause overnight and at weekends.
- **Switch:** it does nothing until the app setting `RFQ_LEGACY_SYNC_ENABLED=true` is set. It needs no other settings: it uses the API's database connection and the existing read-only `LEGACY_TC_*`.
- **To stop it:** remove the setting, or set it to anything else.

## History

- **20 Oct 2026, ledger.** `create-rfq-legacy-import-ledger.sql` was applied to tricab-mel. The ledger was adopted from the first import (101,959 rows, snapshot 01:35:51 UTC), and all 17 table digests matched the import report.
- **20 Oct 2026, first refresh (snapshot 07:45 UTC).** 26 RFQs added and 9 changed; 475 lines added and 233 removed; 1,270 line edits and 142 activity entries. No conflicts.
- **20 Oct 2026, second refresh (snapshot 08:15 UTC).** No conflicts.
  - Writing took about 16 seconds inside the transaction.
  - Planning took 26 minutes, but it holds no locks. Planning reads and hashes about 100k rows on each side, and from a laptop at home that is network-bound; it took 6 minutes from the office the same morning.
  - Measure it again from the WebJob, which runs in the same Azure region as both databases.

## Fixing a refusal (exit 2)

- **"tables no longer match":** tc-web added or changed columns or tables.
  1. Mirror the change in a new guarded script, the way `create-rfq-accessory-tables.sql` did for 18 Oct.
  2. Apply it.
  3. Add any new table to `LegacyRfqTables`.
- **"The ledger is empty":** run `--adopt=<import report> --apply` once. Adopt refuses unless the platform rows still equal that import exactly.
