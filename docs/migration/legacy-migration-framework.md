# TriCab Legacy Migration Framework

**Status:** Framework only — no live legacy connections, no production table writes, no User Migration.  
**Date:** 2026-08-19  
**Repos:** `tricab-api` (`TriCab\Core\LegacyMigration`) and `tricab-web` (`/admin/migration`).  
**Authoritative identity audit:** `tc-web/docs/migration/user-migration-audit.md` (do not implement User Migration until that audit’s readiness gates are met).  
**Related but separate:** Product catalogue migration (`PRODUCT.MIGRATION.MANAGE`, `/admin/products/migration`, `migration.MigrationBatches`). This framework does **not** replace or modify Product Migration.

---

## Architecture

The framework is a permanent Administration module. Every future legacy migration (Users, Employees, Customers, Contacts, Roles, Permissions, ALI-FLEX, Certificates, RFQs, Quotes, Customer Notes, and later modules) reuses the same pipeline.

```text
Legacy Systems (WordPress, Business DB, Moodle, future ERP/CRM)
        │  read-only extractors (fixtures only in this release)
        ▼
Raw Staging          migration.Legacy*Raw
        ▼
Normalization        CanonicalNormalizer (strips password-like keys)
        ▼
Validation           module-registered validators
        ▼
Conflict Detection   stored decisions (ignore | merge | create-new | replace-existing | manual-mapping)
        ▼
Preview              dry-run report; no writes
        ▼
Operator Approval    required; blocked while conflicts are unresolved
        ▼
Execution            StagingOnlyExecutor → snapshots only
        ▼
Verification         SnapshotVerifier
        ▼
Rollback             single batch; snapshots only; never later batches or manual records
```

### Design principles (enforced)

| Principle | How it is enforced |
|-----------|--------------------|
| Never migrate directly into production tables | `Executor::writesProductionTables()` must be false; `StagingOnlyExecutor` writes `LegacyMigrationSnapshots` only; approve/execute throw `ProductionWriteRefused` otherwise |
| Always stage first | Extract upserts raw rows; items hold raw + canonical JSON |
| Always validate | `validate` is a required lifecycle step |
| Always detect conflicts | Validators may emit `isConflict`; approval is 409 until every conflict has a decision |
| Always produce reports | Preview, execution, rollback, and validation payloads are stored and exportable |
| Always support dry-run | Preview is the dry-run; nothing executes until `approved` |
| Always require operator approval | `execute` only from `approved` (or retry from `failed`) |
| Always support rollback | Rollback deletes **this batch’s** snapshots only |
| Always be resumable | Each stage accepts its own status plus later statuses for replay |
| Always be auditable | Every action writes `LegacyMigrationLogs` (user, time, batch, IP, action, result, duration, old/new status) |
| No destructive operations | Legacy systems are never written; production identity/customer tables are never written |
| Open/Closed | New modules register Extractor, Normalizer, Validator[], Mapper, Executor, Verifier |

### Namespaces (do not collide with Product Migration)

| Layer | Namespace / path |
|-------|------------------|
| Core engine | `TriCab\Core\LegacyMigration` |
| API HTTP | `TriCab\Api\Http\Action\Admin\Migration\LegacyMigrationAdminAction` |
| API store | `TriCab\Api\LegacyMigration\Persistence\InMemoryLegacyMigrationStore` |
| HTTP prefix | `/api/v1/admin/legacy-migration` |
| UI | `/admin/migration` |
| Permission | `MIGRATION.ADMIN` (Administrators only) |
| SQL objects | `migration.LegacyMigration*` and `migration.Legacy*Raw` |

Product Migration keeps `migration.MigrationBatches`, `LegacyProductRaw`, `PRODUCT.MIGRATION.MANAGE`, and `/admin/products/migration`.

### Batch lifecycle (resumable)

`created` → `extracted` → `normalized` → `validated` → `preview` → `approved` → `executing` → `completed`

Also: `failed`, `cancelled`, `rolled-back`.

Retry returns `failed` / `cancelled` to `created`. Cancel is allowed before execute. Rollback is allowed from `completed` (and `executing` if a lock is released).

---

## Database

Script: `database/scripts/development/create-legacy-migration-schema.sql`  
Seed: `database/scripts/development/seed-legacy-migration-permission.sql`  
Local rebuild: `rebuild-local-db.ps1` applies both (schema after product migration; seed after user-management permissions).

Tables live in schema `migration`. Names are prefixed `Legacy*` so they never clash with Product Migration’s `MigrationBatches`.

### Control plane

| Table | Purpose |
|-------|---------|
| `LegacyMigrationSources` | WordPress, Business DB, Moodle, future ERP/CRM, fixture harness; health, version, last sync, counts, errors |
| `LegacyMigrationSourceConnections` | Read-only connection metadata (no live credentials used in this release) |
| `LegacyMigrationBatches` | Batch header, status, counts, approval/rollback actors, `ROWVERSION` |
| `LegacyMigrationBatchItems` | Per-record raw + canonical payload and hash |
| `LegacyMigrationLogs` | Audit: actor, IP, action, result, duration, old/new status |
| `LegacyMigrationConflicts` | Detected conflicts and stored operator decisions |
| `LegacyMigrationWarnings` | Non-blocking findings |
| `LegacyMigrationValidationResults` | Full validator output |
| `LegacyMigrationReports` | Preview / execution / rollback / validation payloads |
| `LegacyMigrationRollbackHistory` | Single-batch rollback records |
| `LegacyMigrationJobs` | Extract / sync job status (resume/retry) |
| `LegacyMigrationExecutionHistory` | Per-record execution actions |
| `LegacyMigrationSnapshots` | Staging output of the framework executor |
| `LegacyMigrationLocks` | Optimistic batch lock during execute |
| `LegacyMigrationStatistics` | Success, duration, volume |
| `LegacyMigrationSettings` | `allowFixtureExtract`, `requireApproval`, `refuseProductionWrites` |

### Raw staging (shared shape)

Each raw row: Legacy ID, Source, Extracted Date, Batch ID, Original JSON, Hash, Status.

`LegacyUsersRaw`, `LegacyUserMetaRaw`, `LegacyCustomersRaw`, `LegacyContactsRaw`, `LegacyEmployeesRaw`, `LegacyRolesRaw`, `LegacyPermissionsRaw`, `LegacyCertificatesRaw`, `LegacyQuotesRaw`, `LegacyRfqsRaw`, `LegacyCustomerNotesRaw`.

---

## Services

All module pipelines go through `LegacyMigrationEngine`. Named facades exist so future HTTP/CQRS handlers can stay thin:

| Service | Responsibility |
|---------|----------------|
| `MigrationBatchService` | Create, list, get, extract (full/incremental/refresh), normalize, preview, approve, cancel, retry |
| `MigrationSourceService` | List sources; health **never opens** WordPress / `tc` / Moodle (`liveConnectionAttempted: false`) |
| `MigrationValidationService` | Run registered validators |
| `MigrationConflictService` | List + resolve (ignore, merge, create-new, replace-existing, manual-mapping) |
| `MigrationExecutionService` | Approved execute → snapshots |
| `MigrationRollbackService` | Roll back one batch’s snapshots |
| `MigrationReportingService` | JSON / CSV / Excel-compatible CSV / PDF-stub exports |
| `MigrationAuditService` | List logs |
| `MigrationStatisticsService` | Overview cards and performance totals |
| `MigrationSettingsService` | Read/update framework flags |

### Default plugins (harness)

| Plugin | Role |
|--------|------|
| `FixtureExtractor` | Sanitized in-process fixtures (`@example.invalid`). Read-only. Never touches legacy DBs. |
| `CanonicalNormalizer` | Copies payload; **drops** `user_pass`, `password`, `passwordhash`, `session_tokens`, `user_activation_key` |
| `RequiredFieldValidator` | Users require `legacyId` + `email`; others require `legacyId` |
| `DuplicateIdentityValidator` | Shared email/legacyId → conflict |
| `StagingMapper` | Maps to staging payload only |
| `StagingOnlyExecutor` | `writesProductionTables() === false` |
| `SnapshotVerifier` | Confirms snapshot count |

Harness modules in `ModuleCatalog` (dependency order): users → employees → customers → contacts → roles → permissions → aliflex → certificates → rfqs → quotes → customer-notes. All `isImplemented() === false`.

---

## API

Permission gate: **`MIGRATION.ADMIN`** on every route. CQRS-style: GET queries, POST commands.

| Method | Path | Command / query |
|--------|------|-----------------|
| GET | `/api/v1/admin/legacy-migration/overview` | Dashboard cards, progress, recent batches, activity, modules |
| GET | `/api/v1/admin/legacy-migration/statistics` | Success rate, duration, volume |
| GET/PUT | `/api/v1/admin/legacy-migration/settings` | Framework flags |
| GET | `/api/v1/admin/legacy-migration/sources` | Source catalogue |
| GET | `/api/v1/admin/legacy-migration/sources/{sourceCode}/health` | Health; never live-connects |
| GET | `/api/v1/admin/legacy-migration/logs` | Audit stream |
| GET | `/api/v1/admin/legacy-migration/conflicts` | All unresolved/resolved conflicts |
| POST | `/api/v1/admin/legacy-migration/conflicts/{conflictPublicId}/resolve` | Store decision |
| GET/POST | `/api/v1/admin/legacy-migration/batches` | List / create |
| GET | `/api/v1/admin/legacy-migration/batches/{batchPublicId}` | Batch header |
| POST | `.../batches/{id}/extract` | Full extract |
| POST | `.../batches/{id}/refresh` | Re-extract |
| POST | `.../batches/{id}/incremental` | Incremental extract |
| POST | `.../batches/{id}/normalize` | Normalize |
| POST | `.../batches/{id}/validate` | Validate + conflicts |
| POST | `.../batches/{id}/preview` | Dry-run summary (no execute) |
| POST | `.../batches/{id}/approve` | Operator approval |
| POST | `.../batches/{id}/execute` | Staging snapshots only |
| POST | `.../batches/{id}/rollback` | This batch only |
| POST | `.../batches/{id}/cancel` | Before execute |
| POST | `.../batches/{id}/retry` | Failed/cancelled → created |
| GET | `.../batches/{id}/conflicts` | Batch conflicts |
| GET | `.../batches/{id}/logs` | Batch audit |
| GET | `.../batches/{id}/reports?type=&format=` | `preview` \| `validation` \| `execution` \| `rollback` × `json` \| `csv` \| `excel` \| `pdf` |

Execute without approval → **409**. Unresolved conflicts → **409** on approve. Production-writing executor → **409** `ProductionWriteRefused`.

Current persistence: **in-memory store** in both memory and Azure SQL API modes. Schema is ready; a PDO `LegacyMigrationStore` is remaining work (see below). Process-local PHP-DI is a singleton, so one PHP process keeps batches for that process only.

`composer.json` maps `TriCab\\Core\\LegacyMigration\\` to `core/src/LegacyMigration/` so the new Core namespace loads without waiting on a path-package recopy (`symlink: false`).

---

## UI

Route: **`/admin/migration`**  
Nav: Administration → Legacy Migration (`MIGRATION.ADMIN`)  
Inner left nav (not global children per module): Overview, Sources, Users, Employees, Customers, Contacts, Roles, Permissions, ALI-FLEX, Certificates, RFQs, Quotes, Customer Notes, Reports, Logs, Settings, Future Modules.

| Page | Path |
|------|------|
| Overview dashboard | `/admin/migration` |
| Migration Sources | `/admin/migration/sources` |
| Module workspace (shared batch pipeline) | `/admin/migration/{users\|employees\|…}` |
| Reports | `/admin/migration/reports` |
| Logs | `/admin/migration/logs` |
| Settings | `/admin/migration/settings` |
| Future Modules / extension guide | `/admin/migration/future` |

Overview cards: Sources, Pending, Valid, Conflicts, Executed, Failed, Rolled Back. Also progress bars, recent batches, latest activity, success rate, average execution time, data volume, dependency graph, registered modules (all harness).

Module pages: create batch → extract / refresh / incremental → normalize → validate → preview → resolve conflicts → approve → execute → rollback / retry / cancel. Preview shows inserted / updated / skipped / warnings / errors / conflicts / estimated time / rollback size. Execution writes **staging snapshots only**.

Sources health opens live WordPress / TC connections when configured. Grant verification uses `MysqlGrantInspector`; `readOnlyVerified` / `selectOnlyGrantVerified` remain false when the MySQL account has write privileges.

### TEMPORARY DEVELOPMENT OVERRIDE

`LEGACY_MIGRATION_ALLOW_WRITE_CAPABLE_READER` exists only to permit local read-only migration discovery while a dedicated database-level SELECT-only account is being provisioned.

Requirements (all must be true):

- `APP_ENV=development`
- `LEGACY_MIGRATION_ALLOW_WRITE_CAPABLE_READER=true`
- `LEGACY_WP_READ_ONLY=true` and `LEGACY_TC_READ_ONLY=true`
- SSL verified and legacy sources connected

When active, health reports `developmentOverrideEnabled: true`, `extractionAllowed: true`, and `safetyLevel: DEVELOPMENT_OVERRIDE` while keeping `safeForExtraction: false` and `selectOnlyGrantVerified: false`.

It must remain disabled in staging and production. Setting the override outside development fails application startup.

Before production user migration:

1. Provision dedicated SELECT-only MySQL credentials.
2. Disable/remove the development override.
3. Verify `selectOnlyGrantVerified=true` for WP and TC.
4. Re-run dry-run validation.

All legacy SQL continues through `GuardedReadOnlyMysqlConnection`; the override does not weaken SQL validation.

---

## Workflow

1. Administrator with `MIGRATION.ADMIN` opens `/admin/migration`.
2. Create a named batch for a module (e.g. `users`). Status `created`.
3. **Extract** (full, refresh, or incremental). Raw rows land in `Legacy*Raw`. Legacy DBs are not contacted in this release (fixtures only).
4. **Normalize**. Password-like keys discarded.
5. **Validate**. Findings become validation rows, warnings, or conflicts.
6. **Preview**. Dry-run summary. `wouldWriteProductionTables` is false. `requiresApproval` is true. Nothing is executed.
7. Resolve every conflict (decision stored).
8. **Approve**.
9. **Execute**. Snapshots only. Lock held for the batch. Resumable from `failed` via retry then re-extract/execute as allowed.
10. **Verify** snapshot count.
11. Optional **rollback** of this batch’s snapshots. Manual users/customers/contacts and later batches are untouched because they were never written.

---

## Security

- **Read-only extractors.** `Extractor::extract()` must not update WordPress, Moodle, or the business database. Fixture extractor ignores filters except as local data selection.
- **No password import.** Canonical normalizer strips hash/session fields. Audit forbids exporting `user_pass` (see user-migration audit).
- **No production identity/customer writes.** Framework executor cannot be pointed at `identity.Users` or `customer.*` without a future, reviewed module PR that still must pass `writesProductionTables()` checks.
- **No auth changes.** Login, Entra, Google, and OTP are untouched.
- **No Product Migration changes.**
- Health checks **do not open** live connections.

---

## Permissions

| Code | Who | What |
|------|-----|------|
| `MIGRATION.ADMIN` | Administrators role only | Entire Legacy Migration UI and API |

Seed does not grant the permission to portal or employee roles. Development catalog registers the code so in-memory identity tests and local admin users can receive it with other administrator permissions.

---

## Rollback

- Scope: **one batch**.
- Mechanism: delete `LegacyMigrationSnapshots` for that `PublicId`; write `LegacyMigrationRollbackHistory`; status `rolled-back`.
- Does not delete or update `identity.Users`, `customer.Customers`, `customer.Contacts`, or any later batch.
- Because this release never writes those tables, rollback cannot affect manual records.

---

## Extension Guide

Future migrations are added **without modifying the engine**.

1. Add a module code to `ModuleCatalog::modules()` with `sortOrder` in dependency order and a `rawTable()` mapping.
2. Implement `LegacyMigrationModule` (or compose `FrameworkModule` with real plugins):
   - `Extractor` — read-only; yield `RawRecord`
   - `Normalizer` — `RawRecord` → `NormalizedRecord`
   - `Validator[]` — findings with severity, error code, description, affected record, recommendation; set `isConflict` when an operator decision is required
   - `Mapper` — staging payload only until a dedicated production writer exists
   - `Executor` — keep `writesProductionTables()` false until a reviewed production writer is approved
   - `Verifier` — post-execute checks
3. `LegacyMigrationModuleRegistry::register($module)` (or extend `DefaultModuleRegistry`).
4. Add inner-nav item in `legacyMigrationNav.ts` and a route under `/admin/migration/:module` (the shared module page already handles known codes).
5. Add a `Legacy*Raw` table if a new record type is needed (same column shape).
6. Do **not** change Product Migration tables or routes.

User Migration specifically must follow `user-migration-audit.md`: WP + Entra (oid not in WP) + `tc` + Moodle; staff login without `@`; no password hashes; unique `NormalizedEmailAddress`; conflicts for duplicate emails and ambiguous customer links.

---

## Future Modules

Registered as harness placeholders (`isImplemented: false`):

Users, Employees, Customers, Contacts, Roles, Permissions, ALI-FLEX, Certificates, RFQs, Quotes, Customer Notes.

Sources registered but unconfigured: WordPress, Business Database, Moodle, Future ERP, Future CRM. Fixture harness is the only “healthy” source.

A new domain (for example inventory or territories) follows the extension guide. The dashboard, batch lifecycle, conflicts, preview, approval, execute, rollback, reports, and audit stay unchanged.

---

## Remaining work before User Migration

This framework is complete as a reusable control plane. User Migration is a **separate module implementation** and must not start until:

1. **Azure SQL `LegacyMigrationStore`** — persist batches across API requests when `USER_REPOSITORY=azure-sql` (schema already exists).
2. **SELECT-only inventory** from WordPress `wp_users` / `wp_usermeta` / `wp_user_roles`, `tc` employee/contact tables, and Moodle — as required by the user-migration audit (live row counts were not measured).
3. **Entra matching strategy** — Microsoft Object ID is not stored in WordPress; staff matching cannot be deterministic from WP data alone.
4. **Read-only WordPress / `tc` / Moodle extractors** — fixtures replaced; still never UPDATE/INSERT on legacy systems.
5. **User-specific validators** — duplicate `NormalizedEmailAddress`, missing email, staff vs customer login (`user_login` with/without `@`), ambiguous `tc.contacts` email links, role mapping from live URE `wp_user_roles`.
6. **Conflict UX for identity merges** — operator decisions stored before approve.
7. **Production writer (later PR)** — still stage → validate → preview → approve; still `writesProductionTables()` gated; never import password hashes; never modify authentication.
8. **Do not couple to Product Migration** — keep `LegacyMigration*` tables and `MIGRATION.ADMIN` separate from `PRODUCT.MIGRATION.MANAGE`.
