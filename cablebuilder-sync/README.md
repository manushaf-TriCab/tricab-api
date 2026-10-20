# cablebuilder-sync

A nightly archive of CableBuilder designs in Azure Blob Storage, run as a WebJob
on the `tc-web` `core` slot. This is step 1 of Jeff's plan: get the data into Blob
Storage first, then define a database schema from what is stored.

CableBuilder's vendor ERP API costs $9K (quoted October 2026), so this reads the
servlet that CableBuilder's own web UI uses, as the first pull did (see
`planning/cablebuilder/README.md` and `planning/reveng-research/04-cablebuilder.md`).
It only reads.

## How it decides what to pull

1. **Change feed.** Dynamics logs every design CableBuilder sends it in
   `CsCbiMessages`. Each night the job reads the messages since the last run and
   takes the item code from `Name` (`DesignBOMVersion: <code>[A0V0L0]`).
   `ConversationName` is often a batch label, so it is not used. A typical day has
   about 100 items, which takes about 2 minutes against CableBuilder.
2. **Sub-designs.** Any sub-design (a BOM line in metres) that a pulled design
   names and the archive lacks is pulled too. This closes the gap of about 1,750
   sub-designs left by the first pull.
3. **Backstop.** Each night 70 pages of the design list (about 14,000 rows, about
   5,600 at alternative 0) are re-checked, so every design is re-checked weekly
   even if it never reaches Dynamics.

Re-pulling an unchanged design writes nothing except a timestamp.
CableBuilder returns byte-identical XML for an unchanged design (checked
2026-10-20), so the SHA-256 of the body is the change signal.

## Blob layout (container `cablebuilder`)

| Path | What |
|---|---|
| `designs/<code>/a<alt>v<ver>/<sha16>.<full\|header>.xml.gz` | One blob per distinct body. The folder is the design's history. `<code>` is percent-encoded, so it is reversible |
| `lookups/<tablecode>/<sha16>.xml.gz` | Lookup tables, same idea |
| `index/designs.jsonl.gz` | Manifest: one line per design, alternative and version, with the current blob, first seen, last checked and earlier blobs |
| `index/lookups.jsonl.gz` | Same for lookup tables |
| `state/changefeed.json`, `state/rotate.json`, `state/nightly.json`, `state/lock.json` | Watermark, backstop cursor, last run, run lock |
| `runs/<utc>-<command>.json` | One summary per run: counts, changed designs, failures |

`header` blobs are designs where `getDesign` failed and only `getDesignHeader`
worked.

## Commands

```
python -m cbsync [--store archive | --store blob:cablebuilder] <command>

status                          counts, watermark, cursor
seed --raw <planning/cablebuilder/raw>   load the August 2026 pull (no CableBuilder calls)
pull CODE ... [--no-recurse]    pull named designs (+ missing sub-designs)
feed --since 2026-10-19         show what Dynamics says changed (no CableBuilder calls)
nightly [--run-key D] [--rotate-pages N]
rotate --pages N
lookups                         all 186 lookup tables
check-schema                    would every archived design fit the [cablebuilder] tables?
load-db                         load the archive into [cablebuilder] (DB_* settings)
```

## Database schema (step 2)

`database/scripts/development/create-cablebuilder-schema.sql` defines a
`[cablebuilder]` schema that the archive can be loaded into. Blob stays the
system of record; the tables are a copy that can be rebuilt from it.

**It goes in `tc`** (decided 2026-10-20). CableBuilder is to be the source of
truth for the product tables, and the catalogue loads
(`Projects/catalog-load/build_azure_product_load.py` for styles and
`build_azure_variant_load.py` for FINCAT designs as `product.ProductVariants`)
can then read `[cablebuilder].[current_designs]` in the same database instead of
the August CSV snapshot. Azure SQL cannot join across databases, which ruled
out a separate one.

Because it shares production, it is kept small. Measured on the August pull:
the full JSON copy of every field would be about 1.5 GB. Keeping JSON only on
`design_versions`, with empty fields dropped, and only on the current body,
brings the schema to about 0.45 GB, roughly flat over time. The child tables'
remaining fields stay in the Blob documents.

As with the other `tc` scripts, the script is reviewed and applied by Manusha
or Jeff. The loader needs a database user that can write to `[cablebuilder]`
only; creating it needs Jeff (`ALTER ANY USER`).

| Table | Grain |
|---|---|
| `design_versions` | one distinct body of a design (code, alternative, version, hash); `is_current` marks the latest. About 40 promoted columns (including `outer_diameter_mm` for product specs), every non-empty field in `attributes_json`, which is set to NULL once a newer body arrives |
| `layers`, `operations`, `resources`, `bom_lines` | the construction, routing and BOM of a design version, typed columns only |
| `lookup_table_versions`, `lookup_rows` | the 186 lookup tables, one JSON array per grid row |
| `sync_runs` | the nightly run records |

Views: `current_designs`, `current_bom_lines`, `current_operations` and
`machines` (the Machine Table as columns). `explode_bom(code, alternative,
quantity)` returns the full multi-level BOM.

The promoted columns were chosen by profiling the August pull: of 476 design
fields, 231 are always empty and about 160 are mostly filled. Planning reads a
few dozen (identity, D365 mapping, units, lengths, weights, status). The rest
stay in `attributes_json`, so promoting one later is an `ALTER` plus a backfill
from that JSON, not a re-pull.

Checked against the data (2026-10-20), all 40,331 archived designs:

- `check-schema`: 40,331 design versions, 54,653 layers, 54,653 operations,
  54,445 resources and 115,121 BOM lines (the same counts as the August CSV
  extract) all fit their columns, with no primary-key collisions. Three data
  quirks found and settled: `bompos_erp` is a position code (`0.510`), not a
  number; 12 lay lengths hold Java's `Long.MAX_VALUE` as "no lay" and load as
  NULL; one BOM line has no item code and is kept.
- The `explode_bom` arithmetic, run over the archive, reproduces the worked
  example in `planning/reveng-research/04-cablebuilder.md`: 1,000 m of
  `KL39X-CAXN/3C2.5BK` needs 65.98 kg of copper rod, matching the design's own
  copper weight, and the same eight other materials. The first version of the
  function lost purchased materials that also have a CableBuilder design record
  (about 219, with no BOM). Leaves are now decided by `item_has_bom`.

- On SQL Server 2022 (a throwaway local container): the script runs cleanly
  twice in a row (idempotent) and creates 8 tables, 4 views and the function.
  With the 25 designs of two real trees and the Machine Table loaded through
  `cbsync/rows.py`, `explode_bom` returns the same nine materials for
  `KL39X-CAXN/3C2.5BK` (65.98 kg copper rod, 7 levels). For
  `CA77X-CASN/5C1.5BK` it shows the missing earth core `LM16X-XXXN/1C1.5EA` as
  an unresolved sub-design (`item_is_design = 0`, unit m) and 51.39 kg of copper,
  the 4/5 the research predicted. `machines` and `current_operations` return the
  expected values.

- After moving to `tc` sizing (JSON on `design_versions` only, non-empty,
  NULL once superseded) and adding `outer_diameter_mm`, the fit check and the
  SQL Server test were re-run with the same results; a design's JSON is about
  4,300 characters.

### Loader

`cbsync/dbload.py` (`python -m cbsync load-db`, or after each nightly run with
`CBSYNC_DB_LOAD=true`) compares the archive manifest with the database and
writes only what differs: a new body is inserted with its children and the
previous current row becomes history; a body seen before (changed and changed
back) is reactivated; an unchanged body only gets `last_checked_at`. Each batch
of 200 designs is one transaction. It runs after the sync has recorded its run,
so a database failure never costs a sync, and the next run catches up.

It connects with the slot's existing `DB_HOST`, `DB_DATABASE`, `DB_PORT` and
`DB_AUTH_MODE` settings (`managed-identity` on the slot; `entra-cli` or
`sql-login` locally). The core slot's identity already has database-wide read
and write in `tc` (measured 2026-10-06), so once the schema is applied no new
database user is needed.

Tested 2026-10-20 on a throwaway SQL Server 2022 container:

- First load of the whole archive: 40,331 designs, 186 lookup tables and the
  run records in 135 s. 523 MB including indexes (`design_versions` 424 MB,
  mostly `attributes_json`; everything else about 100 MB).
- Second load: no changes, 4 s.
- A design edited, then edited back: the edit is inserted and the original
  becomes history (`is_current = 0`, `superseded_at` set, JSON cleared); going
  back reactivates the original row with its JSON and retires the edit. No
  duplicate rows.
- `tests/test_dbload.py` checks that the loader's column lists and the SQL
  script agree, in both directions.

On the slot, `pyodbc` needs unixODBC and the Microsoft ODBC driver. The PHP
platform's `sqlsrv` driver depends on both, so they are expected to be there;
check with `odbcinst -q -d` in the slot's SSH before setting `CBSYNC_DB_LOAD`.

**Before using it as the source of truth for products**, three findings from
the research (`planning/reveng-research/04-cablebuilder.md`) matter:

- CableBuilder's own status is not a release signal: in the August pull every
  design was Draft, 99.5% Unconfirmed and 47% flagged out of date. Which designs
  are published as products needs a rule of TriCab's own (for example FINCAT,
  alternative 0, present in D365 released products).
- About a third of finished-item trees were incomplete in the August pull
  because sub-designs were missing. The nightly sync fetches missing
  sub-designs, so this closes over time; header data for FINCAT designs, which
  is what the product tables use, is not affected.
- 28% of D365 BOMs differ from CableBuilder's current BOM (CableBuilder changed
  since D365 last produced the item). Product data is unaffected; anything
  feeding manufacturing should decide which side wins.

## Settings

| Setting | Use |
|---|---|
| `BLOB_CONNECTION_STRING` | Already on the tc-web slots. `AZURE_STORAGE_CONNECTION_STRING` also works |
| `CB_USER`, `CB_PASSWORD` | CableBuilder sign-in, from a dedicated read-only account. The job signs in at the start of each run and again if the session lapses. Keep the password in a Key Vault reference if one is available |
| `CB_COOKIE` | Fallback when `CB_USER` is not set: a browser `JSESSIONID`, which **expires**. For manual runs |
| `IDENTITY_ENDPOINT`, `IDENTITY_HEADER` | Set by App Service; used for the Dynamics token |
| `D365_TENANT_ID`, `D365_CLIENT_ID`, `D365_CLIENT_SECRET` | Alternative to the managed identity |
| `CBSYNC_ENABLED` | `true` to run. The WebJob ships switched off |
| `CBSYNC_DB_LOAD` | `true` to load the archive into `[cablebuilder]` after each nightly run. Off by default; set it only once the schema is applied to `tc` |
| `DB_HOST`, `DB_DATABASE`, `DB_PORT`, `DB_AUTH_MODE` | Already on the slots; the loader reuses them |
| `CBSYNC_HOUR`, `CBSYNC_ROTATE_PAGES`, `CBSYNC_STORE` | Default `1` (01:00 Melbourne), `70`, `blob:cablebuilder` |

## Deploying on the core slot

The slot's image has `python3` 3.11 but no `venv`, so the dependencies are
vendored into the release, as the product finder's are.
`scripts/build-app-service-release.sh` stages `cbsync/` at `app/cbsync/cbsync`,
vendors `requirements.txt` into `app/cbsync/_vendor` for CPython 3.11 manylinux
x86_64 (about 30 MB, ELF-checked like the finder's), and installs
`deploy/app-service/webjobs/cablebuilder-sync/run.sh` as a continuous WebJob.
`azure-identity` is not vendored: the slot uses `BLOB_CONNECTION_STRING`.

Each night's full output (the sync, then the database load) is written to
`/home/LogFiles/cablebuilder-sync/<Melbourne date>.log`, kept for 60 days and
readable from the slot's SSH console. The WebJob log gets one line a night: a
continuous job's log stops taking output once its process hits the line limit,
and the first night's progress lines alone reached it.

The release ships with the job switched off. Set the app settings, then turn it
on with `CBSYNC_ENABLED=true`. `build-app-service-release.ps1` was already behind
the bash script (it carries neither finance-jobs nor the Python vendoring) and is
not changed.

## Status (2026-10-20)

Done and verified on the core slot:

- **Dynamics access.** The core slot's managed identity (client id
  `e22dea0a-4869-453e-b719-edb06675ad5a`) is registered in D365. `cbsync feed`
  run on the slot read 322 messages, 117 items for 19 October. For manual runs
  elsewhere, `az login --allow-no-subscriptions` as an account with a D365 user
  works; an account without one gets 403.
- **Blob access.** The same run read the archive index through
  `BLOB_CONNECTION_STRING`.
- **Network.** The slot reaches `40.115.95.39:8080` (HTTP 200).
- **Schema.** `create-cablebuilder-schema.sql` was applied to `tc` by Manusha and
  checked read-only: 8 tables, 4 views, `explode_bom`, 16 indexes and
  constraints. `tc-web/slots/core` is in `db_datareader` and `db_datawriter`, so
  the loader can write with the slot's identity.

Still open:

- **ODBC driver on the slot.** Check `odbcinst -q -d` in the core slot's SSH
  before setting `CBSYNC_DB_LOAD=true`.

- **CableBuilder account.** Sign-in is built (`CB_USER`, `CB_PASSWORD`) and
  tested against a fake server only. It needs a dedicated read-only account:
  signing in as the shared one disconnects whoever is using it. The first real
  sign-in shows in the first run's record. The client does not sign out
  (CableBuilder's sign-out request is not known); the session lapses on its own.
- **Licence.** Confirm CableBuilder's agreement allows scheduled read-only access.

## Tests

```
python -m pytest -q   (from cablebuilder-sync/)
```
