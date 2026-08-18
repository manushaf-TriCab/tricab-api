# Phase 12.5+ — Live Legacy MySQL (STRICT READ-ONLY)

## Status

Implemented. Connects the Product Migration Engine to live WordPress / tc MySQL **for inspection and DryRun only**.

## Non-negotiable rules

- Legacy production DBs are **read-only**.
- Allowed SQL only: `SELECT`, `SHOW`, `DESCRIBE`/`DESC`, `EXPLAIN`, `INFORMATION_SCHEMA` (via SELECT).
- Forbidden: `INSERT`/`UPDATE`/`DELETE`/`REPLACE`/`TRUNCATE`/`ALTER`/`CREATE`/`DROP`/`RENAME`/`LOCK`/`CALL`/`SET` and `SELECT … FOR UPDATE` / `INTO OUTFILE`.
- Guard throws `LegacyDatabaseWriteAttemptException` and aborts before execute.
- Every legacy SQL statement is logged (timestamp, repository, method, SQL, ms, rows). **Passwords are never logged.**
- Writes are allowed only to Azure SQL / in-memory **migration staging**.
- Live sources **force `dryRun=true`** on batch create and **block `execute`** (no Product / Variant / Blob / publish).

## Components

| Piece | Location |
|-------|----------|
| SQL guard | `LegacyReadOnlySqlGuard` |
| Connection | `ReadOnlyMysqlConnectionFactory` → `GuardedReadOnlyMysqlConnection` |
| WP repo | `MysqlLegacyWordPressRepository` (`findProducts`, `findAttachments`, `findCategories`, `findSpecifications`, `findRelationships`, `inspectSchema`) |
| tc repo | `MysqlLegacyTcRepository` (`findVariants`, `findSpecifications`, `findCodes`, `findAccessories`, `findDescriptions`, `inspectSchema`) |
| Live extract | `LiveCombinedLegacyProductSource` |
| Inventory | `MysqlLiveInventoryProbe` when `LEGACY_*` configured |
| Inspection API | `GET /api/v1/admin/product-migration/inspect` |

## Env

```
LEGACY_WP_DSN=mysql:host=…;dbname=…;charset=utf8mb4
LEGACY_WP_USER=readonly_user
LEGACY_WP_PASSWORD=
LEGACY_WP_TABLE_PREFIX=wp_
LEGACY_TC_DSN=mysql:host=…;dbname=tc;charset=utf8mb4
LEGACY_TC_USER=readonly_user
LEGACY_TC_PASSWORD=
```

Prefer a MySQL account with **SELECT-only** grants. Application guard still applies if grants are wider.

## Operator DryRun path

1. Set `LEGACY_*` (read-only user).
2. `GET …/product-migration/inspect` — schema/counts + SQL log.
3. Create batch (forced dry-run) with `sourceFilter.styleCode` / `maxRecords`.
4. Extract → Normalize → Validate → DryRun → Reconcile.
5. Confirm DryRun report: `wouldWriteProducts=false`, `wouldWriteBlobs=false`.
6. Do **not** call Execute against live sources (API rejects).

## Verification (automated)

- `LegacyReadOnlySqlGuardTest` — allow/deny matrix
- `LegacyReadOnlyRepositoriesTest` — WP SELECT logging + write abort + live DryRun staging without Product writes

## Out of scope

- Full catalogue execute / publish
- Blob upload
- Any WordPress or tc mutation
