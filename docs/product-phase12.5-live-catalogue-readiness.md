# Phase 12.5 — Live Catalogue Readiness

## Status

Implemented (readiness + Azure SQL persistence wired). Builds on approved Phase 12 pilot. **Does not execute full production catalogue migration or auto-publish.**

## Objective

Make the migration engine ready for controlled live-source dry-runs and small approved batches by closing Phase 12 gaps:

1. Live read-only source configuration (WordPress, tc, files)
2. Live inventory / count probes (when credentials present)
3. Azure SQL persistence for migration batches/staging (replace in-memory in azure-sql mode)
4. Catalogue readiness report and Admin/API surface
5. Explicit gates before any live execute

## In scope

- `LiveLegacySourceConfig` from env (`LEGACY_WP_*`, `LEGACY_TC_*`, `LEGACY_FILES_ROOT`)
- Read-only health + optional count inventory (no source mutations)
- Migration schema probe against Azure SQL
- `AzureSqlMigrationBatchRepository` for batch/staging/issue/mapping persistence
- `GET /api/v1/admin/product-migration/readiness`
- Admin readiness panel under Product Migration
- Tests with credentials absent (default) and fixture probes
- Documentation

## Out of scope

- Full production catalogue migration
- Automatic publication
- Customer pricing / quotes / ERP / Azure AI Search
- WordPress retirement
- Production deploy
- Destructive legacy deletes
- Storing DB passwords/tokens/SAS in audit or readiness payloads

## Readiness gates (must be Ready or Waived)

| Gate | Ready when |
|------|------------|
| phase12PilotApproved | Documented approval recorded |
| migrationSchemaApplied | Required `[migration].*` tables exist |
| azureMigrationPersistence | Azure SQL batch repository wired when `USER_REPOSITORY=azure-sql` |
| liveWordpressConfigured | Read-only WP DSN/env present (or explicitly waived for non-live env) |
| liveTcConfigured | Read-only tc DSN/env present (or waived) |
| liveFilesConfigured | `LEGACY_FILES_ROOT` exists and is readable (or waived) |
| liveInventoryCaptured | Counts retrieved once credentials available (`PENDING_LIVE_INVENTORY` cleared) |
| classificationMappingsSeeded | At least one Mapped classification mapping OR open review queue accepted |
| specificationMappingsSeeded | Core field mappings present for known fields (e.g. od → OUTER_DIAMETER) |
| blobInfrastructureReady | Phase 9 blob mode not `disabled` when media/docs will copy |
| noBlockingOpenIssuesOnPilot | Pilot batch issues resolved or accepted |

Overall status: `not-ready` | `ready-for-live-dry-run` | `ready-for-small-batch-execute` | `blocked`

Live execute of non-fixture sources still requires an operator approval separate from this phase.

## Env (read-only; secrets never logged)

```
LEGACY_WP_DSN=
LEGACY_WP_USER=
LEGACY_WP_PASSWORD=
LEGACY_WP_TABLE_PREFIX=wp_
LEGACY_TC_DSN=
LEGACY_TC_USER=
LEGACY_TC_PASSWORD=
LEGACY_FILES_ROOT=
LEGACY_SOURCE_WAIVE_WP=false
LEGACY_SOURCE_WAIVE_TC=false
LEGACY_SOURCE_WAIVE_FILES=false
```

## Completion rules

Phase 12.5 is complete when readiness API/UI work, Azure SQL migration persistence, live config/probes, tests, and docs are done — and **no full production migration has been executed**.


## Results (2026-08-06)

- Phase 12 pilot approval recorded; Phase 12.5 started and delivered readiness surfaces.
- `LiveLegacySourceConfig` + `NullLiveInventoryProbe` (no invented counts; secrets never in readiness payload).
- `GetCatalogueReadinessService` with blocking/non-blocking gates; full catalogue always `false`.
- `AzureSqlMigrationBatchRepository` wired when `USER_REPOSITORY=azure-sql`; in-memory retained otherwise.
- `AzureSqlMigrationSchemaProbe` checks required `[migration].*` tables including `MigrationDryRunReports`.
- `GET /api/v1/admin/product-migration/readiness` + Admin readiness tab.
- **No full production catalogue migration executed.**
- `AzureSqlMigrationBatchRepository` is excluded from API PHPStan level max for now (SQL boundary typing); runtime covered by interface + readiness/endpoint tests.

## Remaining for live dry-run (operator)

1. Apply `create-product-migration-schema.sql` (includes dry-run reports table).
2. Set read-only `LEGACY_WP_*` / `LEGACY_TC_*` / `LEGACY_FILES_ROOT` (or explicit waive flags in non-live envs).
3. Capture live inventory once credentials exist (clears `PENDING_LIVE_INVENTORY`).
4. Seed mapped classification + specification field mappings.
5. Separate operator approval before any non-fixture live execute.

## Live dry-run kickoff (2026-08-06)

- Applied migration schema to Azure SQL `tc`.
- Wired `LEGACY_*_HOST`/`PORT`/`DATABASE` → PDO DSN (env previously used host parts, not DSN).
- Fixed Dotenv `$_ENV` loading for live source config; waived files locally.
- Fixed MySQL `SHOW TABLES LIKE :param` + sqlsrv duplicate named-parameter upserts.
- **First live dry-run (FT, max 5):** batch `FD407D7C-7CC9-43FF-AC42-C39EFD411185`
  - extracted 5 → normalized → validated → dry-run
  - `wouldWriteProducts=false`, `wouldWriteBlobs=false`, `dryRunNoWriteGuarantee=true`
  - counts: 1 product, 2 variants, 0 blocking, 0 unresolved
- Still deferred: classification/spec mapping seeds (needed for small-batch execute only), full catalogue, execute against live.
