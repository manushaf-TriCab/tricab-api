# Onboarding — operator and developer guide

How to run, configure, rebuild, operate and roll back the Onboarding module. Work order §7 Phase 8.6.

Companion documents:

- [`onboarding-architecture.md`](onboarding-architecture.md) — trust boundaries, permissions, data classes.
- [`onboarding-completion-feed.md`](onboarding-completion-feed.md) — the Skill Matrix feed contract.
- `platform/ONBOARDING-IMPLEMENTATION-PLAN.md` — phase-by-phase evidence and decisions (outside this repo).
- `platform/ONBOARDING-CUTOVER-PLAN.md` and `platform/ONBOARDING-PRODUCTION-MIGRATION-CHECKLIST.md` —
  the go-live plan and the real-data migration checklist, neither executed.

---

## 1. What runs where

| Piece | Where | Notes |
|---|---|---|
| API routes | `src/Bootstrap/AppBuilder.php` → `registerOnboarding*Routes` | `/api/v1/onboarding/*` (self-service), `/api/v1/admin/onboarding/*` (HR), `/api/v1/integrations/onboarding/completions` (feed) |
| Domain and application code | `core/src/Onboarding/` | Pure rules: status machine, grading, allocation, form rules, migration planning |
| Persistence, HTTP, email, PDFs | `src/Onboarding/`, `src/Http/Action/Onboarding/` | Azure SQL repositories, blob document store, FPDI template stamping |
| Web, trainee side | `tricab-web/src/components/onboarding/` at `/onboarding` | Any signed-in person with `ONBOARDING.SELF.READ` |
| Web, HR side | `tricab-web/src/components/admin/onboarding/` at `/admin/onboarding` | Needs an administrative `ONBOARDING.*` permission **and** passkey or Entra sign-in |
| Command-line jobs | `bin/onboarding-*.php` | Every job that writes is a dry run unless given `--apply` (see §7) |
| Schema | `database/scripts/development/*onboarding*.sql` | Schemas `onboarding`, `onboarding_hr`, plus tables in `audit` and `migration` |

It ships inside the existing single App Service release (`deploy/app-service/`); there is no separate
deployment, container or resource.

---

## 2. Local setup

```bash
# API (tricab-api)
composer install
cp .env.example .env              # then fill in DB_* for tricab-mel (see below)
php -S 127.0.0.1:8080 -t public public/router.php

# Web (tricab-web)
npm install
npm run dev                       # must be port 5173: the MSAL redirect URIs are registered for it
```

Sign in at `http://localhost:5173`, then open `/onboarding` (your own) or `/admin/onboarding` (HR).

- **Database.** The API uses `tc` on `tricab-mel.database.windows.net` with `DB_AUTH_MODE=entra-cli`
  (run `az login` first; the VPN must be up). The server `tricab.database.windows.net` also has a `tc`
  but no `identity` schema, so always check the **host**. A first query after idle can time out while
  the serverless database resumes; retry before suspecting code.
- **Dev auth.** `.env` ships `DEV_AUTH_ENABLED=true`, so an **unauthenticated curl is treated as
  `DEV_AUTH_DEFAULT_EMAIL`**, and a 200 from an admin route with no credentials is dev auth, not an open
  gate. It is honoured only when `APP_ENV` is development, and production has it `false`. Judge
  authorization from the HTTP tests or a real browser session.
- **`.env` beats the process environment.** `FOO=bar php -S …` is ignored unless you also pass
  `-d variables_order=EGPCS`.
- **Documents locally.** `BLOB_AUTH_MODE=memory` keeps generated PDFs and photos in memory, so nothing
  reaches Azure Storage.
- **Email locally.** `GUEST_EMAIL_PROVIDER=development` delivers nothing. Invitations and reminders
  still run their whole path, so the screens behave as in production.

### Quality gates

```bash
# tricab-api
composer validate --strict
php -d memory_limit=2G vendor/bin/phpunit   # the default 128 MB runs out partway through
composer analyse                            # PHPStan

# tricab-web
npm run typecheck && npm run lint && npm test && npm run build
```

Tests built on `LiveAzureSqlSupport` run against `tricab-mel` when the VPN and `az login` are up, and
skip otherwise, so a normal offline run reports about 145 skipped. The two Postgres extractor tests
need `ONBOARDING_MIGRATION_TEST_POSTGRES_URL` (§8) and skip without it.

---

## 3. Configuration

Names only; values live in `.env` locally and in the `tc-web` / `core` slot's app settings in
production. **Nothing new is required for the module to run**: every Onboarding-specific setting has a
safe default.

| Name | Default | Purpose |
|---|---|---|
| `BLOB_CONTAINER_ONBOARDING` | `onboarding` | Private container for generated documents and photos. Never public |
| `ONBOARDING_REMINDER_EMAILS` | *(empty)*, so off | Reminder digests are sent only when this is exactly `on` |
| `ONBOARDING_EMAIL_TEST_RECIPIENT` | *(empty)* | Outside production, every reminder goes to this address instead |
| `ONBOARDING_MIGRATION_SOURCE_DATABASE_URL` | *(unset)* | Postgres URL of the source app, read only by `bin/onboarding-migration.php extract`. Never set on the App Service |
| `ONBOARDING_MIGRATION_TEST_POSTGRES_URL` | *(unset)* | A local Postgres for the extractor's own tests |

Shared platform settings the module depends on, all of which already exist:

- **Database:** `DB_DRIVER`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_AUTH_MODE`, `DB_USERNAME`,
  `DB_PASSWORD`, `DB_ENCRYPT`, `DB_TRUST_SERVER_CERTIFICATE`.
- **Storage:** `BLOB_AUTH_MODE`, `BLOB_ACCOUNT_NAME`, `BLOB_ACCOUNT_KEY` / `BLOB_CONNECTION_STRING`,
  `BLOB_ENDPOINT`, `BLOB_DOWNLOAD_SAS_TTL_SECONDS`.
- **Email:** `GUEST_EMAIL_PROVIDER` (`development` | `postmark` | `microsoft-graph`; production refuses
  `development`), `GRAPH_TENANT_ID`, `GRAPH_CLIENT_ID`, `GRAPH_CLIENT_SECRET`, or the `POSTMARK_*` group.
- **Sign-in:** the existing Entra, guest OTP, passkey, Google and Apple settings, all unchanged.
- **Environment:** `APP_ENV`, `CORS_ALLOWED_ORIGINS`. The CORS allow-list must admit the
  `Idempotency-Key` header; the code does this.

The web app adds no build-time variables.

Training slide images, deck PDFs and the two WHSE videos are served from the **public**
`training-decks` container in the `tricabv2` storage account; each slide stores its absolute https URL.
Personal documents never go there.

---

## 4. Database

### Scripts, in dependency order

All live in `database/scripts/development/`. Each is idempotent (safe to run again).

| # | Script | Creates or changes |
|---|---|---|
| 1 | `create-identity-user-email-addresses.sql` | Platform (Phase P): several verified addresses per person |
| 2 | `create-onboarding-schema.sql` | `onboarding` schema: profiles, content, versions, assignments, attempts, acknowledgements, submissions, documents, status events, evidence triggers |
| 3 | `create-onboarding-hr-schema.sql` | `onboarding_hr`: payroll and restricted-HR fields split off form submissions |
| 4 | `create-onboarding-admin-audit.sql` | `audit.OnboardingAdminEvents` (append-only) |
| 5 | `alter-onboarding-policy-metadata.sql` | Handbook metadata columns on policies |
| 6 | `alter-onboarding-policy-declarations.sql` | `PolicyAcknowledgements.DeclarationJson` (policy sign-off) |
| 7 | `create-onboarding-form-contributions.sql` | `onboarding.FormContributions`: HR and manager parts of a form |
| 8 | `create-onboarding-migration-rows.sql` | `migration.OnboardingMigrationRows`: per-row ledger for migration batches |
| 9 | `seed-onboarding-permissions.sql` | The `ONBOARDING.*` permissions and their role grants |
| 10 | `seed-onboarding-roles.sql` | The `ONBOARDING_ADMIN` role (assigned to nobody) |

Development only, never in production: `seed-onboarding-demo-data.sql` and its inverse
`remove-onboarding-demo-data.sql` (§6).

**All ten are applied to `tc` on `tricab-mel`**, with the owner's approval; the plan records each one.
One exception, checked 25 Sep 2026: the current `seed-onboarding-permissions.sql` adds a ninth
permission, `ONBOARDING.COMPLETIONS.READ`, for the Skill Matrix feed (granted to no role), and that
revision has **not** been re-run, so `tricab-mel` holds eight. Nothing reads the permission until the
feed is connected. Re-running the script is safe.

### Rebuilding a local database

`database/scripts/development/rebuild-local-db.ps1` (Windows, `sqlcmd`, local SQL Server) runs all of
the above in order along with the rest of the platform schema.

### Applying a script to Azure SQL

```bash
php bin/run-azure-sql-script.php database/scripts/development/<file>.sql            # dry run: lists the batches
php bin/run-azure-sql-script.php database/scripts/development/<file>.sql --apply --server=tricab-mel.database.windows.net --database=tc
```

It uses the API's own database authentication, splits on `GO`, refuses a server with no
`[identity].[Users]`, and refuses a destructive script without an explicit acknowledgement. `tc` on
`tricab-mel` is the **production** database: every `--apply` there is an owner decision.

---

## 5. Permissions, roles and HR access

| Permission | Holder | Assurance floor |
|---|---|---|
| `ONBOARDING.ACCESS`, `ONBOARDING.SELF.READ`, `ONBOARDING.SELF.SUBMIT` | `employee`, `GUEST` | LOW (an emailed code is enough) |
| `ONBOARDING.ADMIN`, `.PEOPLE.MANAGE`, `.REVIEW`, `.CONTENT.MANAGE`, `.REPORTS.READ` | `ONBOARDING_ADMIN` (`ADMINISTRATOR` holds `ONBOARDING.ADMIN` only) | SUBSTANTIAL (passkey or Entra) |
| `ONBOARDING.COMPLETIONS.READ` | nobody yet | SUBSTANTIAL |

- **Granting HR access:** Admin → User Management → assign `ONBOARDING_ADMIN`. That records who
  granted it. `bin/onboarding-assign-admins.php` was the one-off bootstrap and is not the ongoing way to
  do it.
- **An admin signed in by emailed code is refused** by the API (403 naming the assurance), whatever
  roles they hold. They need a passkey or Microsoft sign-in.
- **A person must be `active` to sign in.** Most imported staff are `pending` (`AllowsLogin = 0`), and a
  role on a pending account does nothing.
- Nobody may review their own onboarding (`SelfReviewPolicy`).

---

## 6. Synthetic demo accounts

`seed-onboarding-demo-data.sql` creates, for looking at the screens:

- three **pending** people at `@example.test`: Alex Ng, Priya Shah and Jordan Lee (a leaver);
- `demo-` training modules, policies and forms;
- ten assignments across them.

They cannot sign in (pending). Everything carries a `demo-` slug or an `@example.test` address, so it is
unmistakable, and `remove-onboarding-demo-data.sql` removes it. Audit rows are append-only and stay.

**This data is on `tricab-mel` today** (loaded 23 Sep 2026, on request) and must be removed before
go-live; see the cutover plan. Checked 25 Sep 2026: 4 `@example.test` users (the three above plus
Casey, a **disabled** GUEST account left by the Phase 7 rehearsal) and 2 `demo-` modules.

---

## 7. Command-line jobs

All run from the `tricab-api` root with `php bin/<script>`. Output carries counts and user ids, never
addresses, answers or form values.

| Script | Writes? | Use |
|---|---|---|
| `onboarding-send-reminders.php [--dry-run] [--json]` | Sends email, writes `onboarding.NotificationLog` | Daily digest of overdue, due-soon and undated items per person. Off unless `ONBOARDING_REMINDER_EMAILS=on`. At most one reminder per person, item and day, so running it twice is safe |
| `onboarding-maintenance.php [--apply] [--json]` | Deletes spent sign-in codes | Removes email sign-in challenges older than 7 days. Production also needs `--i-understand-this-deletes-data`. The retention review never deletes: every class is `RetentionDays = NULL` |
| `onboarding-import-policies.php --from=DIR [--apply --actor=EMAIL] [--publish-changes]` | Creates or publishes policies | Handbook policies from `onboarding-convert-handbook.php` output |
| `onboarding-import-forms.php [--apply --actor=EMAIL]` | Creates **draft** forms | TriCab's five paper forms. An existing form is never touched |
| `onboarding-import-training.php --source=DIR [--apply --actor=EMAIL]` | Creates **draft** modules | The three training modules from the source app |
| `onboarding-convert-handbook.php --source=DIR --out=DIR` | Local files only | Converts handbook HTML into policy content |
| `onboarding-assign-admins.php [--apply --actor=EMAIL]` | Assigns a role | One-off bootstrap, already done |
| `onboarding-migration.php …` | See §8 | Source-app migration, synthetic data only |

**Nothing is scheduled.** The App Service has no scheduler, and adding one (a WebJob, Logic App or
Function) is an Azure change awaiting approval. Until then, run the reminders and maintenance by hand.
When a scheduler exists, run both once a day. The source app sent reminders at 22:00 UTC, which is
08:00 or 09:00 in Melbourne depending on daylight saving. Both are safe to repeat.

Always pass `--actor=you@tricab.com` to anything that writes, so the audit trail names the person who
made the decision.

---

## 8. Migration from the source app

`bin/onboarding-migration.php` runs `extract → preview → execute → verify → rollback` (`status` at any
point). Production execution is **disabled in code**: `execute` and `rollback` accept only a snapshot
marked synthetic whose every address is at `example.test` or `example.invalid`. Enabling real data needs
its own work order; see `platform/ONBOARDING-PRODUCTION-MIGRATION-CHECKLIST.md`.

```bash
php bin/onboarding-migration.php synthetic --out=var/onboarding-migration/synthetic.json
php bin/onboarding-migration.php preview --snapshot=FILE --actor=you@tricab.com     # records a batch and its fingerprint
php bin/onboarding-migration.php execute --batch=ID --fingerprint=SHA256 --approved --actor=you@tricab.com
php bin/onboarding-migration.php verify --batch=ID
php bin/onboarding-migration.php rollback --batch=ID --actor=you@tricab.com --i-understand-this-removes-migrated-records
```

Exit codes: 0 ok, 2 usage, 3 refused, 4 verification found problems, 5 failed. Snapshots and approved
plans go to `var/onboarding-migration/<batch>/` (git-ignored, mode 0600). A real snapshot holds payroll
and medical data: keep it on the operator's machine only.

The local Postgres used in the rehearsal is a container called `tricab-migration-source`
(`postgres:16-alpine`, port 55432) loaded with the source's `db/schema.sql`. Only the extractor tests
need it.

---

## 9. Rollback

In order of reach, smallest first:

1. **One migration batch:** `onboarding-migration.php rollback` removes only the rows and blobs that
   batch recorded. It refuses a person who has done later work, disables (never deletes) accounts it
   created, and leaves audit rows.
2. **A person or item:** handled in the HR console. Withdrawing allocation removes only untouched
   assignments; nothing recorded is destroyed (invariant 9). A trainee's account is disabled, never
   deleted.
3. **Hide the module without a deploy:** deactivate the `ONBOARDING.*` role mappings in Roles &
   Permissions (or remove `ONBOARDING_ADMIN` from its holders). The API refuses every route and the web
   hides both surfaces. Data is untouched and access returns when the mappings are reactivated.
4. **Code:** redeploy the previous release zip (or revert the merge on `main`, which redeploys through
   `deploy-production.yml`). The schema is additive, so older code runs against it unchanged.
5. **Schema:** there is no drop script, on purpose. The tables hold compliance evidence, their triggers
   refuse updates to it, and audit tables are append-only. Removing them is a separate, owner-approved
   decision, not a rollback step.

---

## 10. Troubleshooting

| Symptom | Usual cause |
|---|---|
| HR sees the console but every action says the sign-in isn't strong enough | Signed in by emailed code. Use a passkey or Microsoft |
| A newly assigned role does nothing | Account is `pending` (`identity.UserStatuses`) |
| `SQLSTATE[07002] COUNT field incorrect` | One named parameter used twice in a statement. Give each use its own name |
| `Incorrect syntax near the keyword 'identity'` | Write `[identity].[Users]`, bracketed |
| `Invalid object name 'identity.Permissions'` | Connected to `tricab.database.windows.net`, not `tricab-mel` |
| Submit, acknowledgement or assessment silently fails in the browser only | CORS preflight dropped `Idempotency-Key` |
| Acknowledging a signed policy fails naming a script | `alter-onboarding-policy-declarations.sql` not applied on that database |
| Works locally, broken on tricab.ai | A setting missing on the `core` slot falls back to a code default. Compare slot setting names with `.env.example` |
| Reminders run but nobody gets an email | `ONBOARDING_REMINDER_EMAILS` is not exactly `on`, or outside production no test recipient is set |
