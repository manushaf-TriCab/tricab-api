# Inventory: Portal (TriCab Finance)

Part of Phase 0, task P0.4 ([FINANCE-PHASE-0.md](../../../../FINANCE-PHASE-0.md)).
The portal is the finance suite's identity, access, mail and reference-data service and its launcher. Portal port 8040.
It is the live portal (plan §3.2). Unlike the five apps it is already on SQLAlchemy and SQL Server, and it holds the roles
the platform's Phase 1 permission vocabulary will be taken from.

## 1. Source

| | |
|---|---|
| Directory | `D:\TriCab\finance\_work-portal-entra-20261005` |
| Branch, commit | `master` `d333f12` (6 Oct 2026, "Portal: sort reference lists in Python, not SQL") |
| Uncommitted files | none (`git status --short` empty) |
| Remote | none |
| Date read | 6 Oct 2026 |

Not read: the top-level August snapshots, the other portal directories (`_synced-20260918\tricab-finance-aws` carries the older
portal; the plan marks this one as live), and `releases/` (build archives; the folder was excluded from the file listing and not
opened). `.env` is not present in the tree listing; `.env.example` was read for names.

Files read: `server/{app,models,entra_auth,graph_service,odoo_sync}.py` (`app.py` in full; `odoo_sync.py` and `entra_auth.py`
and `graph_service.py` for structure and settings), `shared/*.py` (method lists), `database/migrations/20261006-odoo-reference.sql`
(header), `deploy/*.service` and `*.timer`, `deploy/DEPLOYMENT_AWS.md` (opening), `docs/SHARED_SERVICES.md` and
`docs/PLATFORM_CUTOVER.md` (headings and the outstanding list), `.env.example`, `package.json` and the front-end pages.

Size: `server/*.py` 3,296 lines (app 2,054, odoo_sync 452, models 328, graph_service 273, entra_auth 181; plan §4.1 says 3.8k, which is
about what `server/` plus `shared/` (404 lines) comes to, 3.7k); 35 route decorators; 33 `def test_` functions in four test files; front-end 734
lines (plan: 0.7k). Stack: Flask, Flask-SQLAlchemy, `pyodbc` for SQL Server (SQLite for tests); React 18 with React Router 7 and a
plain `styles.css`, **no Tailwind and no shadcn**.

## 2. Routes

35 route decorators in `server/app.py` (all inside `register_routes`, which is why `grep ^@app` finds none at column 0), equal to the plan's
35. Two are the SPA catch-all; the other 33 are listed.

"Who" column:

- **session**: `login_required`: the portal's own Flask session (`uid`, `session_version`, `csrf_token`, `auth_method`), 8-hour lifetime,
  cookie `tricab_finance_session`, re-checked against the `users` row on every request (inactive or a changed `session_version` clears it).
- **platform admin**: `platform_admin_required`: session plus `is_platform_admin`.
- **app secret**: the request carries `X-App-Secret` equal to that application's client secret from the environment (`ARGO_CLIENT_SECRET`,
  `INVOICE_CLIENT_SECRET`, `CREDIT_CLIENT_SECRET`, `AR_CLIENT_SECRET`, `TASK_CLIENT_SECRET`), compared with `compare_digest`; an empty
  secret means the application cannot call at all. The application key is in the URL or body.
- **CSRF:** a `before_request` hook requires `X-CSRF-Token` on every POST, PUT, PATCH and DELETE except these endpoints: `auth_login`,
  `sso_exchange`, `sso_introspect`, `delegated_update_access`, `mail_messages_list`, `mail_message_get`, `mail_attachments_list`,
  `mail_send`. (GET routes are never checked.)

| # | Method, path | Handler (`server/app.py`) | Who | Reads / writes | Side effects |
|---|---|---|---|---|---|
| 1 | GET `/api/health` | `health` :1050 | none | `SELECT 1`; reports whether Graph and Microsoft sign-in are configured | none |
| 2 | POST `/api/auth/login` | `auth_login` :1065 | none | `platform_users` (password check); audit | starts a session. Refused with 403 for non-admins when Microsoft sign-in is configured (`PASSWORD_LOGIN` policy) |
| 3 | GET `/api/auth/options` | `auth_options` :1083 | none | env | none |
| 4 | GET `/api/auth/entra/start` | `entra_start` :1092 | none | writes the flow (state, nonce, PKCE verifier, safe `next` path) to the session | redirect to Microsoft sign-in |
| 5 | GET `/api/auth/entra/callback` | `entra_callback` :1107 | none (the return) | looks up the user by the token's email claims; session; audit (`auth.login` or `auth.entra_failed`) | redeems the code at Microsoft (PKCE, client secret); redirect. Nobody is created: an unknown account answers `not_registered` |
| 6 | POST `/api/auth/logout` | `auth_logout` :1154 | session | bumps `session_version` (signs the person out **everywhere**); audit | none |
| 7 | GET `/api/auth/me` | `auth_me` :1164 | session | the user, their applications and per-app access, the CSRF token | none |
| 8 | POST `/api/auth/change-password` | `auth_change_password` :1169 | session | new hash (12 characters minimum); bumps `session_version`; audit | none |
| 9 | GET `/api/sso/launch/<app>` | `sso_launch` :1197 | session; needs access to the app | inserts a one-time `authorization_code` (stored hashed, 60 s); deletes expired codes; audit | **redirect** to the app's `launch_url` plus its callback path with `?code=…&app=<key>` |
| 10 | POST `/api/sso/exchange` | `sso_exchange` :1233 | app secret | marks the code used (atomic UPDATE) | returns the user, `session_version` and the app's access block |
| 11 | POST `/api/sso/introspect` | `sso_introspect` :1275 | app secret | none | `{active, user, access}` for a user id and session version; the apps call this on every request |
| 12 | GET `/api/services/mail/<app>/mailboxes` | `mail_service_mailboxes` :1298 | app secret; optional actor | mailboxes this app may read or send, filtered further by the actor's mailbox scope when `actor_user_id` is given | none |
| 13 | POST `/api/services/mail/<app>/messages/query` | `mail_messages_list` :1346 | app secret; read grant; optional actor | audit (`mail.messages_listed`) | Graph list of a mailbox over a window (at most 31 days and 500 messages by default) |
| 14 | POST `/api/services/mail/<app>/messages/get` | `mail_message_get` :1382 | as 13 | audit with a SHA-256 of the message id | Graph get message |
| 15 | POST `/api/services/mail/<app>/attachments/query` | `mail_attachments_list` :1411 | as 13 | audit | Graph list attachments with bytes |
| 16 | POST `/api/services/mail/<app>/send` | `mail_send` :1445 | app secret; send grant; optional actor | `platform_mail_deliveries` row first (`pending`, then `sent` or `failed`); audit; **idempotency key** per app (200 if the earlier send succeeded, 409 if it did not) | Graph `sendMail`; up to 50 recipients, 500-character subject, 1 MB body, 10 attachments, 3 MB in total; `HTML` or `TEXT`; bodies and files are not kept (only a body hash and the file names) |
| 17 | GET `/api/admin/applications` | `admin_applications` :1617 | platform admin | applications with their roles and permissions | none |
| 18 | GET `/api/admin/mailboxes` | `admin_mailboxes` :1644 | platform admin | mailbox registry with grants | none |
| 19 | POST `/api/admin/mailboxes` | `admin_create_mailbox` :1652 | platform admin | inserts a mailbox and its per-app read and send grants; audit | none |
| 20 | PUT `/api/admin/mailboxes/<id>` | `admin_update_mailbox` :1686 | platform admin | updates the mailbox and replaces its grants; audit | none |
| 21 | GET `/api/admin/mail-deliveries` | `admin_mail_deliveries` :1726 | platform admin | the delivery ledger, newest first, `limit` up to 500 | none |
| 22 | GET `/api/admin/users` | `admin_users` :1740 | platform admin | every user with access per application | none |
| 23 | POST `/api/admin/users` | `admin_create_user` :1747 | platform admin | inserts a user with a temporary password and `must_change_password`; audit | returns the temporary password once, or `sign_in: "microsoft"` when passwords are not usable for that person |
| 24 | PATCH `/api/admin/users/<id>` | `admin_update_user` :1776 | platform admin | name, active, platform admin; cannot deactivate or demote oneself or the last platform admin; deactivation bumps `session_version`; audit | none |
| 25 | POST `/api/admin/users/<id>/reset-password` | `admin_reset_password` :1807 | platform admin | new temporary password; bumps `session_version`; audit | returns the password once |
| 26 | DELETE `/api/admin/users/<id>` | `admin_delete_user` :1821 | platform admin | deletes the user; memberships, role grants and codes cascade; audit events and deliveries keep the id as a plain column | none |
| 27 | PUT `/api/admin/users/<id>/access/<app>` | `admin_update_access` :1845 | platform admin | replaces the membership (`enabled`) and the role grants (each with an optional `scope_type` and `scope_value`); bumps `session_version`; audit | none |
| 28 | GET `/api/app-services/<app>/members` | `application_members` :1872 | app secret + an actor who belongs to the app | members of the app holding a named permission of that app: id, email, name, roles | none |
| 29 | GET `/api/app-admin/<app>/users` | `delegated_users` :1921 | app secret + actor holding a `*.users.manage` permission | the app's members with access detail | none |
| 30 | PUT `/api/app-admin/<app>/users/<id>/access` | `delegated_update_access` :1942 | app secret + actor holding a `*.users.manage` permission | replaces that user's access to this app (same as 27); audit | none |
| 31 | GET `/api/admin/odoo` | `admin_odoo_status` :1974 | platform admin | sync status, counts, recent runs, key expiry | none |
| 32 | POST `/api/admin/odoo/sync` | `admin_odoo_sync` :1979 | platform admin | runs a sync (`auto`, `full`, `incremental`); 409 if one is running; audit | Odoo calls (§7) |
| 33 | GET `/api/services/reference/<app>/<list>` | `reference_list` :1994 | app secret | the copied Odoo list (`companies`, `vendors`, `cost-centres`, `accounts`); live and active only by default, `include_inactive=1` adds archived, `updated_since` returns everything changed since (deleted and archived included) | none. **Sorted in Python**, not SQL (25 s on RDS for 1,864 vendors with an `NVARCHAR(max)` column, 0.05 s unsorted; measured 6 Oct) |
| 34 | GET `/` | `spa` :2040 | none | `dist/index.html` | none |
| 35 | GET `/<path>` | `spa` :2041 | none | `dist/<path>` or `index.html`; `api/...` answers 404 JSON | none |

Reconciliation: 35 found, 35 in plan §4.1. Equal.

Behaviour that matters for the port (it is the model for what the platform's identity must replace):

- **Single use, short-lived SSO codes.** A launch writes a code (the hash only, 60 s); the app exchanges it with its secret; the exchange marks it used with an
  `UPDATE ... WHERE used_at IS NULL` so a race loses. `session_version` is the revocation lever: bumping it signs a person out of every app on their next request.
- **Mail Service** (routes 12 to 16) is the only code that holds the app-only Graph credential for the suite; it enforces per-app, per-mailbox read and send grants
  and, when an actor is passed, the actor's own permission (`MAIL_PERMISSIONS`) and mailbox scope. **Only Tri-Task uses it to send.** Tri-AP, Tri-AR, Tri-Credit
  Notes and Argo Collect call it only to list grants and keep their own Graph credentials.
- **Microsoft sign-in** (routes 4, 5): authorisation code with PKCE against one tenant; the ID token comes straight from the token endpoint over TLS and its claims
  (issuer, audience, tenant, expiry, nonce) are checked, with no JWKS check (the comment in `entra_auth.py` argues this is permitted). Matching is by email from the
  claims (`candidate_emails`) against the existing `platform_users`; **nobody is created by signing in**. Passwords stay for platform admins (`PASSWORD_LOGIN`
  `admins` once Microsoft sign-in is configured).
- **Audit:** every sign-in, access change, mailbox change, mail action, Odoo sync and user change writes `platform_audit_events`.

## 3. Data

SQLAlchemy models in `server/models.py`, all in the SQL Server schema **`platform`**, table names prefixed `platform_`. In production the database is the private RDS
SQL Server Express instance `tricab_finance` (AWS); the schema is created by `AUTO_CREATE_SCHEMA` (`CREATE SCHEMA platform AUTHORIZATION dbo`, then `db.create_all()`)
or by the committed script `database/azure-sql-schema.sql`, which a test (`test_mssql_schema.py`) checks against the models. **Production runs with
`AUTO_CREATE_SCHEMA=false`**; changes are applied by hand with `sqlcmd` (the 6 Oct Odoo tables are `database/migrations/20261006-odoo-reference.sql`, idempotent, with a stated
rollback). There is no Alembic.

Types are already the Azure SQL ones plan §6 asks for: ids are `NVARCHAR(32)` (uuid4 hex), text is `NVARCHAR(n)`, long text `NVARCHAR(MAX)`, every timestamp
**`DATETIMEOFFSET`** (timezone aware; `utc_datetime()`), booleans `BIT`. SQLite is used by the tests with the schema translated away.

| Table (in `platform`) | Columns | Keys, indexes |
|---|---|---|
| `platform_users` | `id`, `email` (320, unique), `full_name` (200), `password_hash` (MAX), `is_active`, `must_change_password`, `is_platform_admin`, `session_version` INT, `created_at`, `updated_at` | PK `id`; unique and index on `email` |
| `platform_applications` | `id`, `key` (80, unique), `name` (120), `acronym` (8), `description` (300), `launch_url` (500), `callback_path` (200, default `/api/auth/platform/callback`), `accent` (20), `sort_order`, `is_active`, `created_at` | unique and index on `key` |
| `platform_memberships` | `id`, `user_id` FK cascade, `application_id` FK cascade, `is_active`, `created_at` | unique (`user_id`, `application_id`) |
| `platform_roles` | `id`, `application_id` FK cascade, `key` (80), `name` (120), `description` (300), `permissions_json` (MAX: a JSON array of permission strings) | unique (`application_id`, `key`) |
| `platform_user_role_grants` | `id`, `user_id` FK cascade, `role_id` FK cascade, `scope_type` (50, default empty), `scope_value` (200, default empty), `created_at` | unique (`user_id`, `role_id`, `scope_type`, `scope_value`) |
| `platform_authorization_codes` | `code_hash` (64, PK), `user_id` FK cascade, `application_id` FK cascade, `expires_at`, `used_at`, `created_at` | PK `code_hash` |
| `platform_audit_events` | `id`, `actor_user_id` (no FK; indexed), `application_key` (default `platform`), `action` (120, indexed), `entity_type`, `entity_id`, `details_json` (MAX JSON), `created_at` | |
| `platform_mailboxes` | `id`, `email` (320, unique), `display_name` (200), `purpose` (300), `is_active`, `created_at`, `updated_at` | |
| `platform_application_mailbox_grants` | `id`, `application_id` FK cascade, `mailbox_id` FK cascade, `can_read`, `can_send`, `created_at`, `updated_at` | unique (`application_id`, `mailbox_id`) |
| `platform_mail_deliveries` | `id`, `application_key` (indexed), `actor_user_id` (no FK; indexed), `mailbox_email` (indexed), `to_addresses_json`, `cc_addresses_json` (MAX JSON), `subject` (500), `body_sha256` (64), `attachment_names_json` (MAX JSON), `idempotency_key` (200), `status` (20, indexed: `pending`, `sent`, `failed`), `error_message` (500), `created_at`, `sent_at` | unique filtered index (`application_key`, `idempotency_key`) `WHERE idempotency_key IS NOT NULL` |
| `platform_odoo_records` | PK (`list_key` 40, `odoo_id` INT), `name` (400), `code` (100), `company_id` INT, `is_active`, `data_json` (MAX JSON), `write_date`, `deleted_at`, `synced_at` | indexes (`list_key`, `name`) and (`list_key`, `write_date`) |
| `platform_odoo_sync_runs` | `id`, `mode`, `trigger`, `status` (indexed), `counts_json` (MAX), `error_message` (1000), `started_at` (indexed), `finished_at` | |

12 tables, as `azure-sql-schema.sql` has 12 `CREATE TABLE`.

**What the data is, for the migration:** users, roles and grants of the five apps (a small set; Tri-Task's eight people and the finance team), the mailbox registry and
grants, the audit and delivery ledgers, and the Odoo copy (about 1,900 vendors plus companies, cost centres and accounts). Plan §4.1 lists the portal's data as users,
roles, mailbox grants and Odoo reference lists.

### Notes for Azure SQL

- **JSON in text columns:** `permissions_json`, `details_json`, the three `*_json` columns of the delivery ledger, `data_json` (every Odoo record's full payload),
  `counts_json`. No native JSON.
- **Long text used in sorting:** `platform_odoo_records.data_json` is `NVARCHAR(MAX)`; sorting rows that carry it took 25 s for 1,864 vendors, which is why the route
  sorts in Python. Any platform replacement must keep the `ORDER BY` off a query that selects `data_json` (or split it into its own table).
- **Timestamps:** all `DATETIMEOFFSET`; nothing without a zone.
- **Identity:** user ids here are **the ids stored in every app** (`preparer_user_id`, `approval_batches.prepared_by`, and so on). Moving identity to the platform's own
  `identity` schema means a mapping from these 32-character ids to the platform's user ids, applied to the app data as it moves (flagged in each inventory).
- The platform database user would need create-and-write rights on a schema it does not own; here the portal's `AUTHORIZATION dbo` assumes a `dbo`-capable account.

## 4. Files

None. The portal writes no files and stores no message content: the delivery ledger keeps a body hash and attachment names only, and the Odoo sync stores JSON in
`platform_odoo_records`. (`server/data/` exists only for the SQLite fallback and is `ReadWritePaths` in the unit.) Backups are the RDS instance's own (seven-day automated backups,
deletion protection, per `deploy/DEPLOYMENT_AWS.md`).

## 5. Roles and access

The portal's model: a **user** has **memberships** in **applications**, and **role grants** within each application, each grant optionally scoped (`scope_type` and
`scope_value`, used so far for a Tri-AP mailbox and a Tri-Credit Notes branch). A role is a named set of permission strings (`platform_roles.permissions_json`). A user's
permissions in an application are the union over their grants. Roles and permissions are **seeded from `ROLE_SEED` in `app.py` at every start** (the code overwrites
names, descriptions and permission lists of existing roles, so a role edited only in the database is reverted on restart). Platform administration is a flag on the user, separate from
application roles, and is the only thing that opens routes 17 to 27 and 31 and 32.

**Applications seeded** (`APPLICATION_SEED`): `argo-collect-flow` (Tri-Collection, COL), `invoice-flow` (Tri-AP, AP), `large-credit-flow-pro` (Tri-Credit Notes, CN),
`peridot-smart-ledger-flow` (Tri-AR, AR), `unique-fin-flow-sync` (Tri-Task, TT), each with a launch URL from `ARGO_APP_URL`, `INVOICE_APP_URL`, `CREDIT_APP_URL`,
`AR_APP_URL`, `TASK_APP_URL`.

**The full role and permission vocabulary** (the input to Phase 1's task "permission and role vocabulary"):

| App | Role key | Permissions |
|---|---|---|
| Argo Collect | `reviewer` | `collect.records.read` |
| | `admin` | `collect.records.read`, `.write`, `.delete`, `collect.mail.send`, `collect.users.manage` |
| Tri-AP | `operator` | `invoice.invoices.read`, `.write`, `invoice.mail.read`, `.send` |
| | `preparer` | `invoice.invoices.read`, `invoice.approvals.prepare` |
| | `approver` | `invoice.invoices.read`, `invoice.approvals.decide` |
| | `admin` | all of the above plus `invoice.invoices.delete`, `invoice.users.manage`, `invoice.settings.manage` |
| Tri-Credit Notes | `sales` | `credit.notes.read`, `.create`, `.update_own` |
| | `branch_manager` | `credit.notes.read`, `.approve` |
| | `finance` | `credit.notes.read`, `.process`, `.delete` |
| | `executive_viewer` | `credit.notes.read`, `credit.reports.read` |
| | `admin` | `credit.notes.read`, `.create`, `.update`, `.delete`, `.approve`, `.process`, `credit.reports.read`, `credit.mail.send`, `credit.users.manage`, `credit.settings.manage` |
| Tri-AR | `reviewer` | `ar.records.read` |
| | `operator` | `ar.records.read`, `ar.records.write` |
| | `admin` | `ar.records.read`, `.write`, `ar.admin` |
| Tri-Task | `user`, `manager`, `admin` | see `tri-task.md` §5 (13 permissions, each role a superset) |

Gaps and quirks in the vocabulary:

- **Mail permissions do not line up with the roles.** `MAIL_PERMISSIONS` names `collect.mail.read`/`send`, `invoice.mail.read`/`send`, `credit.mail.read`/`send` and
  `task.mail.read`/`send`. Of the `read` ones only `invoice.mail.read` is in any role; `collect.mail.read`, `credit.mail.read` and `task.mail.read` are in none. **Tri-AR has
  no entry at all**: the comment in `app.py` says `_mail_actor` indexes the dict directly, so an actor passed for `peridot-smart-ledger-flow` would raise `KeyError` (HTTP
  500). It is harmless while Tri-AR never passes an actor. `credit.mail.send` is in the Administrator role only.
- **Most permissions are not enforced by the apps** (see the Tri-AP, Tri-Credit Notes and Tri-AR inventories). The portal seeds them; the apps check a few.
- **Scopes** in use: `mailbox` (Tri-AP) and `branch` (Tri-Credit Notes). An unscoped grant means unrestricted.
- **Delegated administration** (routes 29, 30): an app's own administrator (any permission ending `.users.manage`) can change members' roles of that app only. The portal does not
  police *which* role may be granted by whom; Tri-Task adds that rule itself (manager cannot grant Administrator).
- **Job titles** are not stored in the portal (`PLATFORM_CUTOVER.md` §9); Tri-Task keeps them in `task_member_profiles`.

How the apps get roles: `shared/tricab_access_client.py` (`FinanceAccessClient`: `exchange`, `introspect`, `list_members_with_permission`, `list_users`,
`update_user_access`), `shared/tricab_mail_client.py` (`FinanceMailClient`: `list_mailboxes`, `list_messages`, `get_message`, `list_attachments`, `send_mail`) and
`shared/tricab_reference_client.py` (`FinanceReferenceClient`: `get_list`, `get`, `synced_at`, `invalidate`; caches per list and refreshes with `updated_since`). On the
host these live in `/opt/tricab-finance/shared/`; the apps import them from there (locally from the sibling `..\tricab-finance-aws`).

## 6. Background work

| Job | Trigger | What it does |
|---|---|---|
| Odoo sync | systemd timer `tricab-odoo-sync.timer`: 2 minutes after boot, then **every 15 minutes** (`OnUnitActiveSec=15min`, randomised by 30 s, `Persistent=true`), running the oneshot `python -m server.odoo_sync --mode auto` as user `tricabfinance` | Copies four Odoo lists (`companies`, `vendors`, `cost-centres`, `accounts`) into `platform_odoo_records`. `auto` does a **full sweep** when the last successful full one is over 20 hours old, else an **incremental** pass on `write_date`; only a full sweep notices deletions and vendors that stopped being vendors. A run marked `running` for more than 15 minutes is abandoned; a concurrent run answers `SyncBusy`. Logs a warning in the last 30 days before the Odoo API key expires (`ODOO_API_KEY_EXPIRES`) |
| Seeding | `_seed_platform` at every start (`create_app`) | upserts applications, roles and permissions; seeds optional mailboxes from `MAILBOX_SEED_JSON`; if there are no users, creates a platform administrator (`ADMIN_EMAIL`, `ADMIN_PASSWORD`, or a generated password written to the log) |
| Schema | at start when `AUTO_CREATE_SCHEMA` is true (the default); **off in production** | `CREATE SCHEMA platform` and `db.create_all()` |

No mailbox scanning and no other timers.

## 7. External calls

- **Microsoft Entra ID (sign-in):** `https://login.microsoftonline.com/<tenant>/oauth2/v2.0/...`, authorisation code with PKCE, scope `openid profile email`, one tenant. Registration
  `42b5265b-e816-4d70-9a5d-f20bcfd7b1dd` per plan §4.5. Settings `ENTRA_TENANT_ID`, `ENTRA_CLIENT_ID`, `ENTRA_CLIENT_SECRET`, `ENTRA_REDIRECT_URI`.
- **Microsoft Graph, app-only** (`graph_service.GraphClient`, `GRAPH_TENANT_ID`, `GRAPH_CLIENT_ID`, `GRAPH_CLIENT_SECRET`): list messages (`/users/<mbx>/messages`, a
  `receivedDateTime` window, `$select` id, subject, from, receivedDateTime, hasAttachments, bodyPreview, webLink), get a message, list attachments, `sendMail`.
  **Message id form: default (mutable).** No `Prefer: IdType="ImmutableId"` header is sent (the client builds `Authorization`, `Accept` and `Content-Type` only). Message
  ids passed through the Mail Service are therefore per-folder ids. No app currently reads mail through this service, so nothing depends on it yet; it matters once the
  platform's mailbox intake is built (plan Phase 1).
- **Odoo.sh** (`ODOO_URL`, `ODOO_API_KEY`, `ODOO_COMPANY_IDS` default `2,4`, `ODOO_API_KEY_EXPIRES`): read-only JSON-2 API, `POST /json/2/<model>/search_read` on
  `res.partner` (vendors: `supplier_rank > 0`), `res.company` (the company ids), `account.analytic.account` and `account.account`, 500 rows per page, 60 s timeout. The key never
  leaves the portal. Failure is recorded on the sync run (`failed`, with the message) and logged at ERROR; the reference route keeps serving the last copy.
- **OpenRouter, Document Intelligence:** none.

## 8. Settings

Names only (`.env.example` plus every `os.environ` read in `server/`; on the host `/etc/tricab-finance.env`):

| Setting | Controls |
|---|---|
| `SECRET_KEY`, `SESSION_COOKIE_SECURE` | session signing (random per process if unset) and the secure flag |
| `SQLSERVER_ODBC_CONNECTION_STRING`, `DATABASE_URL`, `AUTO_CREATE_SCHEMA` | database (ODBC string for SQL Server; a PostgreSQL URL is refused; SQLite if neither); schema creation at start |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | first platform administrator, created only when there are no users |
| `ARGO_APP_URL`, `INVOICE_APP_URL`, `CREDIT_APP_URL`, `AR_APP_URL`, `TASK_APP_URL` | each application's launch address (browser-facing). `AR_APP_URL` and `TASK_APP_URL` are read by the code and **missing from `.env.example`** |
| `ARGO_CLIENT_SECRET`, `INVOICE_CLIENT_SECRET`, `CREDIT_CLIENT_SECRET`, `AR_CLIENT_SECRET`, `TASK_CLIENT_SECRET` | each app's secret for the service routes. `AR_CLIENT_SECRET` and `TASK_CLIENT_SECRET` are missing from `.env.example` |
| `GRAPH_TENANT_ID`, `GRAPH_CLIENT_ID`, `GRAPH_CLIENT_SECRET` | the Mail Service's Graph credential |
| `ENTRA_TENANT_ID`, `ENTRA_CLIENT_ID`, `ENTRA_CLIENT_SECRET`, `ENTRA_REDIRECT_URI` | Microsoft sign-in |
| `PASSWORD_LOGIN` | `all` or `admins`; blank means admins only once Microsoft sign-in is configured |
| `MAILBOX_SEED_JSON` | optional initial mailbox registry |
| `MAIL_MAX_SCAN_MESSAGES` (500), `MAIL_MAX_SCAN_DAYS` (31), `MAIL_MAX_RECIPIENTS` (50), `MAIL_MAX_BODY_BYTES` (1 MB), `MAIL_MAX_ATTACHMENT_BYTES` (3 MB) | Mail Service limits |
| `ODOO_URL`, `ODOO_API_KEY`, `ODOO_COMPANY_IDS`, `ODOO_API_KEY_EXPIRES` | Odoo link (all four missing from `.env.example`) |
| `PORT` | development server, default 8040 |

`SESSION_COOKIE_SAMESITE` is `Lax`; the app trusts one proxy hop (`ProxyFix`). `tricab-finance.service` runs Gunicorn (2 workers, 4 threads, 60 s timeout) on
`127.0.0.1:8040`.

## 9. Screens

React 18.2, React Router 7, plain CSS (`styles.css`); one API helper (`lib/api.js`) that adds `X-CSRF-Token` to non-GET requests. Auth from `AuthContext`
(`/api/auth/me`). Route numbers refer to §2.

| Path | Component | API calls | States and dialogs |
|---|---|---|---|
| `/login` | `Login.jsx` | 3, 2; link to 4 | "Sign in with Microsoft" button when configured; the password form shown always when Microsoft is off, or behind "show password" for administrators when `options.password` is `admins`; error text for `not_registered`, `microsoft_declined`, `microsoft_failed`, `microsoft_unavailable`, `expired`, `deactivated` |
| `/change-password` | `ChangePassword.jsx` | 8 | form; reached by redirect when `must_change_password` (not shown after a Microsoft sign-in) |
| `/` | `Portal.jsx` (inside `AppShell`) | 7; the card links to 9 | "Good day, <first name>" and a card per application the person can open (name, acronym, accent, description, roles); empty "No applications assigned"; an application with no launch URL is shown as not yet connected |
| `/access` (platform admin) | `AccessManagement.jsx` | 22, 17, 23, 24, 25, 26, 27 | "Users": scope tiles (all, platform admins, per-app counts); table of people with status, application access and platform flag; empty "No people match this view."; **new user** dialog; **edit** dialog (name, active, platform admin, and per-application enable, role checkboxes and scopes); **one-time handover** dialog for a temporary password (not shown when the person will use Microsoft); reset password; delete |
| `/services/mail` (platform admin) | `MailService.jsx` | 18, 17, 21, 19, 20 | "Microsoft 365 mail": mailbox registry cards with grants (empty "No shared mailboxes yet"), policy dialog (address, display name, purpose, active, read and send per application), recent delivery ledger (20) |
| `/services/odoo` (platform admin) | `OdooService.jsx` | 31, 32 | "Odoo reference lists": status, key expiry, counts per list, "sync now" with a mode, sync history (empty "No sync has run yet.") |
| `*` | redirect to `/` | none | |

The app shell shows Users, Mail and Odoo links to platform administrators only; every application card is a plain link to `/api/sso/launch/<key>`.

## 10. Open questions

1. **What replaces the portal.** The plan retires the finance portal and takes identity, roles, mailbox grants and the Odoo copy into the platform. The platform already has an `identity`
   schema (plan §5.1) and Microsoft sign-in; whether its roles can express **scoped grants** (`mailbox`, `branch`) and **app-local role sets** is not known and decides Phase 1's
   vocabulary task. The 5 apps' permission lists above are the requirement.
2. **Per-app, per-mailbox grants and the delivery ledger** have no platform counterpart (plan §5.1: "mailbox reading does not exist yet"; RFQ has its own Graph classes). Tri-Task is the
   only consumer of `send` with idempotency and the 409-on-failed-key rule. The platform's design (Phase 1: "mail send, mailbox intake") must decide this.
3. **Immutable ids**: the portal's Mail Service sends no `Prefer: IdType="ImmutableId"` header (§7), so a mailbox intake built on it would inherit Tri-AR's problem. Phase 1's mailbox intake
   should ask for immutable ids from the start.
4. **Missing `MAIL_PERMISSIONS` entry for Tri-AR** (500 on an actor call) and dead `*.mail.read` permissions (§5).
5. **Service routes trust the app with an actor id.** Routes 28 to 30 check the actor's active state and membership but **not `session_version`** (route 16's `_mail_actor` does), so an app holding its
   secret can name any member as the actor. The model is "the app is trusted"; the platform's per-route permission middleware replaces it.
6. **`ROLE_SEED` is authoritative at each start**: role and permission definitions edited in the database are overwritten on restart; there is no role editor in the UI.
7. **Entra sign-in does not verify the ID token signature** (§2). It relies on TLS to the token endpoint; the platform's own sign-in (already production) should be used instead.
8. **Environment names missing from `.env.example`**: `AR_APP_URL`, `TASK_APP_URL`, `AR_CLIENT_SECRET`, `TASK_CLIENT_SECRET`, `ODOO_URL`, `ODOO_API_KEY`, `ODOO_COMPANY_IDS`, `ODOO_API_KEY_EXPIRES`.
9. **Runbook drift**: `deploy/DEPLOYMENT_AWS.md` and the nginx file assume a layout the host no longer uses (`docs/PLATFORM_CUTOVER.md` §9), and the Entra work of 5 Oct is committed here but the plan notes the
   portal has no remote.
10. **Dormant local user rows** remain in each app's SQLite since the August cutover, as the rollback path (§9 of the cutover note). That affects Tri-AP (open question 1 there) and the data
    migrations: each app's `users` table is not an identity source.
11. **Odoo sync under serverless Azure SQL**: a 15-minute timer touches the database all day (full sweeps hold up to 1,900 vendors with their JSON payload), which keeps a serverless database awake;
    price and the 25 s sort hazard (§3) both matter for the platform's own copy. The Odoo company ids default to `2,4` (`falinwa-tricab`); which entities those are is not stated in the code.
12. **Tests:** 33 (`test_entra.py` 12, `test_platform.py` 10, `test_odoo.py` 6, `test_mssql_schema.py` 5). Not run (P0.4 installs nothing).
13. **Differences from the plan:** §4.1 gives 3.8k server lines; `server/*.py` is 3.3k and `shared/` another 0.4k, so the plan's figure includes `shared/`. Everything else for the portal (35 routes, 0.7k front end, data) matches. Plan §3.2 says the
    portal is clean with no remote: confirmed (no uncommitted files, no remote).
