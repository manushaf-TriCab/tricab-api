# Inventory: Argo Collect

Part of Phase 0, task P0.4 ([FINANCE-PHASE-0.md](../../../../FINANCE-PHASE-0.md)).
Business name Argo Collect; the sidebar and the portal card call it Tri-Collection; the code calls it
Argo Collect; portal application key `argo-collect-flow`; port 8020.

## 1. Source

| | |
|---|---|
| Directory | `D:\TriCab\finance\_synced-20260918\argo-collect-flow` |
| Branch, commit | `deploy-button-only` `4743aa7` (22 Sep 2026, "Send mail only from a mailbox the portal grants") |
| Uncommitted files | 3, as the plan says: `src/components/layout/Sidebar.jsx` (modified: the portal home link now renders the `PortalBrand` component instead of a house icon and text; 5 lines added and 4 removed), `src/components/PortalBrand.css` (new), `src/components/PortalBrand.jsx` (new). Visual only |
| Date read | 6 Oct 2026 |
| Not read | the top-level August snapshot `D:\TriCab\finance\argo-collect-flow`; the unreleased `main`, except `server/models.py` for the prefixed table names and types (`git show main:server/models.py`; nothing checked out) |
| Remote | `origin/main` exists |

**Confirm before relying on this reference.** Plan §3.2 records that Argo Collect's live state on 5 Oct existed only
on the host, in the S3 backup, and in an August local copy. This branch was synced on 18 to 22 Sep; whether it is
what is running on the host has not been checked (the host is out of bounds). It is the owner task "confirm over
SSH what is running on the AWS host".

Files read: `server/{app,store,llm,mailer,table_extract}.py` (all), `.env.example`, `deploy/argo-collect.service`,
`deploy/backup.sh`, the tests' headers, and in `src/`: `App.jsx`, `api/base44Client.js`, `lib/UserRoleContext.jsx`,
`components/layout/Sidebar.jsx` (and its diff) and the pages and components for their API calls and states. For roles
only: `_work-portal-entra-20261005/server/app.py` (`d333f12`), `ROLE_SEED`.

Size: `server/*.py` 1,583 lines including tests (1,373 without; plan §4.1 says 1.3k, agrees); 26 route decorators;
frontend pages and components about 2,500 lines of the 6.4k in the plan (the rest is `components/ui`).

Stale files in the tracked tree: `server/app.py.bak-20260812` and `server/app.py.pre-platform-sso-20260810T0445Z` are
committed, not in use, and are older versions of `app.py`. They are not ported.

## 2. Routes

26 route decorators in `server/app.py`, equal to the plan's 26. Two are the SPA catch-all. The six `entity_*` routes
serve four resources (`accounts`, `actions`, `contacts`, `templates`) through `_ENTITY_TABLES` (`app.py:415`).

"Who" column:

- **perm(X)**: `permission_required(X)` (`app.py:282`). With a portal session the caller's portal permission list must
  contain `X`, else 403. With a local session (portal not configured, development only) only `collect.records.read`
  is open to everyone and anything else needs `role == "admin"`.
- **admin**: `admin_required` (`app.py:299`): with a portal session `collect.users.manage`; local `role == "admin"`.
- **login**: signed in, no permission. The session is a Flask cookie holding `platform_uid` and
  `platform_session_version`, checked by a portal `introspect` call (cached per request in `g`). Once the portal is
  configured a local session cookie is ignored.
- There is **no row-level rule**: every caller with `collect.records.read` sees every account, action and contact.

| # | Method, path | Handler (`server/app.py`) | Who | Reads / writes | Side effects |
|---|---|---|---|---|---|
| 1 | POST `/api/auth/login` | `auth_login` :316 | none | local `users` | sets a session; **403 "Sign in through TriCab Finance" whenever the portal is configured** (development only otherwise) |
| 2 | POST `/api/auth/logout` | `auth_logout` :337 | none | clears session | none |
| 3 | GET `/api/config` | `app_config` :343 | none | env `FINANCE_PORTAL_PUBLIC_URL` | none |
| 4 | GET `/api/auth/me` | `auth_me` :353 | session | the signed-in user: derived `role` (`admin` if the portal grants `collect.users.manage`, else `user`) and `permissions` | portal introspect |
| 5 | GET `/api/auth/platform/callback` | `platform_callback` :364 | none (SSO return) | writes the session cookie | portal `exchange(code)`; redirect to `/` |
| 6 | POST `/api/auth/change-password` | `auth_change_password` :388 | login | local `users` | 409 under the portal |
| 7 | GET `/api/<resource>` | `entity_list` :427 | perm(`collect.records.read`) | `accounts`, `actions`, `contacts`, `templates`; `?sort=` (any column, default `-created_date`), `?limit=` (default 200, max 1000), equality filters on any column | none |
| 8 | GET `/api/<resource>/<id>` | `entity_get` :439 | perm(`collect.records.read`) | one row | none |
| 9 | POST `/api/<resource>` | `entity_create` :451 | perm(`collect.records.write`) | insert (whitelisted columns) | none |
| 10 | POST `/api/<resource>/bulk` | `entity_bulk_create` :460 | perm(`collect.records.write`) | one insert per element, not in one transaction | none |
| 11 | PATCH `/api/<resource>/<id>` | `entity_update` :472 | perm(`collect.records.write`) | update | none |
| 12 | DELETE `/api/<resource>/<id>` | `entity_delete` :483 | perm(`collect.records.delete`) | hard delete. Deleting an account also deletes its actions; deleting an action removes its audio file | file removal |
| 13 | GET `/api/users` | `users_list` :502 | admin | portal member list (`list_users`) mapped by `_platform_member`; local `users` table when no portal | portal `list_users` |
| 14 | POST `/api/users/invite` | `users_invite` :521 | admin | local only; returns a temporary password | 409 under the portal |
| 15 | POST `/api/users/<id>/reset-password` | `users_reset_password` :546 | admin | local only | 409 under the portal |
| 16 | PATCH `/api/users/<id>` | `users_update` :564 | admin | local only; cannot demote the last admin | 409 under the portal |
| 17 | DELETE `/api/users/<id>` | `users_delete` :592 | admin | local only | 409 under the portal |
| 18 | POST `/api/upload` | `upload_file` :609 | perm(`collect.records.write`) | writes the file to `server/data/uploads/<12 hex>-<secure name>`; returns `{file_url: "/api/files/<name>"}`. Extensions allowed: csv, xlsx, xls, wav, mp3, webm, m4a, ogg, pdf, png, jpg, jpeg; 32 MB request cap | file written |
| 19 | GET `/api/files/<name>` | `serve_file` :623 | perm(`collect.records.read`) | reads the file | none |
| 20 | POST `/api/llm` | `llm` :629 | perm(`collect.records.write`) | none | **OpenRouter** with **a prompt supplied by the caller** (§7) |
| 21 | POST `/api/transcribe` | `transcribe` :643 | perm(`collect.records.write`) | reads an uploaded audio file | **OpenRouter** transcription; wav and mp3 only |
| 22 | POST `/api/extract-data` | `extract_data` :657 | perm(`collect.records.write`) | reads an uploaded CSV or XLSX | none (deterministic parse in `table_extract.py`, not an LLM). Always HTTP 200 with `{status: "success"|"error", ...}` |
| 23 | POST `/api/functions/sendEmail` | `fn_send_email` :677 | perm(`collect.mail.send`) | none (no record is kept) | **sends mail**, one recipient per call, text body, from `MAIL_SENDER` after the portal grant check; falls back to Resend if Graph is not configured |
| 24 | POST `/api/functions/purgeDeletedActions` | `fn_purge_deleted_actions` :696 | admin | deletes actions binned more than `COLLECT_BIN_RETENTION_DAYS` days ago | audio files removed |
| 25 | GET `/` | `spa` :703 | none | `dist/index.html` | none |
| 26 | GET `/<path>` | `spa` :704 | none | `dist/<path>` or `index.html`; `api/...` answers 404 JSON | none |

Reconciliation: 26 found, 26 in plan §4.1. Equal.

Behaviour that matters for the port:

- **The Bin is client-driven.** The browser "moves an action to the bin" by patching `deleted_at` to now, and
  restores it by patching `deleted_at` to null. The server purges at start-up and on route 24; the list routes do
  **not** hide binned rows, so the browser filters on `deleted_at`.
- **Spreadsheet import** is parsed by the server (`table_extract.py`): headers are matched case-insensitively with
  spaces turned into underscores, values coerced to the schema's types (number strips `,` and `$`; boolean accepts
  true, yes, 1, y, active). `.xls` is refused; `openpyxl` is needed for `.xlsx` (it is not in `requirements.txt`).
- **Email** goes to any address the browser posts, one call per recipient; the browser loops over the selected
  contacts and stops at the first error. The server does not check the address against the contacts table.

## 3. Data

SQLite, `server/data/collect.sqlite3` (`COLLECT_DATA_DIR`), created at import by `store.init_db()`
(`CREATE TABLE IF NOT EXISTS`, plus one `ALTER TABLE accounts ADD COLUMN company` guarded against a concurrent
worker). **No migration system** on the live branch.

Live column types are `TEXT`, `REAL` and `INTEGER`. Ids are `uuid4().hex`. `created_date` and `updated_date` are ISO 8601
`TEXT` with a `+00:00` offset written by the server. The `users` table has no `updated_date`. Other date and time
columns are strings the browser writes: `action_date` `yyyy-MM-dd`, `action_time` `HH:mm`, `follow_up_date`
`yyyy-MM-dd`, and `deleted_at` a full `toISOString()` value (or null). Booleans are `INTEGER` 0 or 1 returned as real
booleans (`follow_up_required`, `is_active`, `is_default`, `must_change_password`).

| Live table | Prefixed table on `main` (`TABLE_PREFIX = "collect_"`) | Live columns | Indexes (live) | Indexes (`main`) |
|---|---|---|---|---|
| `accounts` | `collect_accounts` | 14 | none | none |
| `actions` | `collect_actions` | 16 | `idx_actions_account (account_id)` | `ix_collect_actions_account_id` |
| `contacts` | `collect_contacts` | 11 | none | none |
| `templates` | `collect_templates` | 9 | none | none |
| `users` | `collect_users` | 11 | `email` unique | `email` unique |

The September rewrite on `main` was never deployed; the live database holds the unprefixed tables. Column comparison (checked by script, live field lists against `main`'s `models.py`): **all 5 tables carry the same columns on both**, so the September definitions are complete for this app; only the types differ (`Numeric(18, 2)` for `outstanding_amount`, `DATETIME2(6)` for `actions.deleted_at`, `NVARCHAR(MAX)` for long text). **No foreign keys**:
`actions.account_id` is a plain column, and the cascade on account delete is done in the route.

- `accounts`: `id` PK; `account_name`, `account_number`, `email`, `phone`, `contact_person`, `address`, `company`,
  `outstanding_amount` (REAL live; `Numeric(18, 2)` on `main`), `currency` (default `AUD`), `status` (default `active`;
  the UI offers `active`, `overdue`, `escalated`, `resolved`), `payment_terms`, `notes`, `created_date`,
  `updated_date`. The UI treats `account_name` and `company` as the lookup fields; there is no unique constraint on
  `account_number` or `account_name`.
- `actions`: `id` PK; `account_id`, `account_name` (a copy), `action_date`, `action_time`, `contact_method`
  (`email`, `phone`, `meeting`, `in-person`, `other`), `contact_person`, `customer_response`, `summary`, `raw_notes`,
  `audio_url` (`/api/files/<name>`), `follow_up_required` (boolean), `follow_up_date`, `deleted_at` (`TEXT` live;
  `DATETIME2(6)` on `main`), `created_date`, `updated_date`.
- `contacts`: `id` PK; `full_name`, `email`, `phone`, `region`, `department`, `role`, `company`, `is_active` (boolean),
  `created_date`, `updated_date`. These are the **recipients of collection emails**, a separate list from the
  accounts' own `email` and `contact_person`.
- `templates`: `id` PK; `name`, `subject`, `body`, `category` (default `general`), `is_default` (boolean),
  `created_date`, `updated_date`.
- `users`: `id` PK; `email` unique; `full_name`, `role` (default `user`), `password_hash`, `position`, `department`,
  `region`, `is_active`, `must_change_password`, `created_date`. Local identity; the platform replaces it.

Long text on `main` (`NVARCHAR(MAX)`): `accounts.address`, `accounts.notes`, `actions.customer_response`,
`actions.summary`, `actions.raw_notes`, `templates.body`, `users.password_hash`. **No JSON columns**: `_clean` would
`json.dumps` a dict or list on write, but no field in this app is one.

### Notes for Azure SQL

- **Sort and filter on any column:** `list_rows` accepts `?sort=` and equality filters on every whitelisted column,
  including the long text ones. The UI sorts by `created_date`, `action_date` and `full_name`. A port must allow only
  bounded sort columns (plan §13).
- **Timestamps without a zone:** live `created_date` carries an offset; on `main` it is naive UTC `DATETIME2(6)`.
  `actions.deleted_at` is the one place where the browser writes a timestamp that the server later compares
  (`deleted_at < cutoff`), as ISO text with `Z` against server text with `+00:00`; a string comparison across those
  two suffixes is only correct while both are UTC, which they are. On a `DATETIME2` column the port must parse the
  browser value.
- **Money is float** live (`outstanding_amount`). Plan §4.1 says 2 accounts on 17 Sep. Not rechecked.
- **Dashboard limits:** the Dashboard loads 50 accounts and 500 actions, so "Total Accounts" stops at 50 (§10).

## 4. Files

Uploads are written to `server/data/uploads/<12 hex>-<secure_filename>` and referenced by `actions.audio_url` as
`/api/files/<name>`; they are served to any signed-in user with `collect.records.read`. Uploads are of two kinds:
voice notes (kept on disk and linked from the action, removed with the action or the account, or when the bin is
purged) and spreadsheet imports (CSV or XLSX; **never deleted** after parsing, so they accumulate in the same folder).
The browser's recorder encodes WAV client-side (`src/lib/audio.js`) because the server accepts only wav and mp3 for
transcription (there is no ffmpeg step here, unlike Tri-Credit Notes). Nightly backup of the SQLite file and the
uploads to S3 by `deploy/backup.sh` (root cron, `COLLECT_BACKUP_BUCKET`).

## 5. Roles and access

Identity and roles are the portal's (application key `argo-collect-flow`). `_platform_user` (`app.py:224`) collapses
the portal answer to two roles: `admin` if the permission list contains `collect.users.manage`, else `user`.

| Portal role | Label | Permissions |
|---|---|---|
| `reviewer` | Reviewer | `collect.records.read` |
| `admin` | Administrator | `collect.records.read`, `collect.records.write`, `collect.records.delete`, `collect.mail.send`, `collect.users.manage` |

The server checks exactly these permissions per route (§2), which is the only one of the five apps that already
enforces permissions route by route. What it does not do is any row-level or per-user restriction.

In the browser, `UserRoleContext` reads `role` from `/api/auth/me`; `isAdmin` (that role is `admin`) switches the
sidebar between two lists: admins get Dashboard, Customer and Email Templates; reviewers get Dashboard and Customer.
The Contacts and Admin pages exist as routes but are in neither list. `isAdmin` is read in one place only (the
sidebar; a search of `src/` finds no other use), so no page or button checks the role in the browser: a reviewer who
types `/admin` or `/contacts`, or who uses a write control, is stopped only by the server's 403. This is a
presentation gap, not a data one.

How the app gets roles: `finance_access` (`FinanceAccessClient` from `shared/tricab_access_client.py` under
`FINANCE_PLATFORM_SHARED_ROOT`): `exchange`, `introspect`, `list_users`; `FinanceMailClient`:
`list_mailboxes` only.

## 6. Background work

- **Start-up purge:** `_purge_expired_actions()` runs at import (every Gunicorn worker start): permanently deletes
  actions whose `deleted_at` is older than `COLLECT_BIN_RETENTION_DAYS` (default 30) and removes their audio files.
- **On demand:** route 24 does the same for an admin.
- **Nightly backup:** root cron, `deploy/backup.sh`.
- No timers, no mailbox scanning, no reminders. Retired with the move: the backup. The purge needs a scheduled job in
  the platform (there is no server process that restarts regularly there).

## 7. External calls

- **TriCab Finance portal:** code exchange, session check, member list, mailbox grant check.
- **Microsoft Graph, app-only** (`mailer.GraphMailer`): `POST /users/<MAIL_SENDER>/sendMail` with `saveToSentItems:
  true`, client credentials from `GRAPH_TENANT_ID`, `GRAPH_CLIENT_ID`, `GRAPH_CLIENT_SECRET`. Plain-text body, one
  recipient per call; an optional `from_name` sets the display name on the `from` field. `MAIL_SENDER` defaults in
  `.env.example` to `ar@tricab.com`, the mailbox Tri-AR scans. **No mailbox is read, so there is no message-id form to
  record.** Before sending, `_send_refusal` asks the portal's Mail Service whether this app may send from that
  mailbox (403 if not, 503 if the portal is down); the check is skipped if Graph is not configured.
- **Resend** (fallback, `https://api.resend.com/emails`): used only when Graph is not configured and `RESEND_API_KEY` is
  set; the default sender can reach only the Resend account owner. The portal grant check does not apply to it.
- **OpenRouter** (`llm.py`): `invoke_llm` (model `COLLECT_LLM_MODEL`, default `google/gemini-2.5-flash`, temperature 0,
  timeout `COLLECT_LLM_TIMEOUT_SECONDS` 60). The schema is only restated in the prompt (no `response_format`), and the
  reply is parsed with a "find the first and last brace" fallback. `transcribe_audio` (`COLLECT_AUDIO_MODEL`, same
  default) takes wav or mp3. The browser builds the prompts: `ActionForm.jsx` sends the "AI Summarize" prompt ("You are
  an accounts receivable assistant. Analyze the following notes…", which extracts date, time, method, person, response,
  summary and follow-up) and a response schema, so `POST /api/llm` runs **any prompt a user with
  `collect.records.write` posts**. **On failure** `LLMError` becomes HTTP 502 with its message; nothing is logged
  server-side (`llm.py` has no logging). `ActionForm.handleSummarize` has no `try` or `catch`, so a failed call
  leaves the Summarise button in its busy state (`summarizing` is never reset) with an unhandled error in the console
  and no message to the user.
- **Odoo, Document Intelligence:** none.

## 8. Settings

Names only (`.env.example`; on the host an environment file for the `argo-collect` service):

| Setting | Controls |
|---|---|
| `SECRET_KEY`, `SESSION_COOKIE_SECURE` | session signing and the secure flag |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | local admin seeded when the `users` table is empty and no portal is configured |
| `FINANCE_PLATFORM_URL`, `FINANCE_PLATFORM_SHARED_ROOT`, `FINANCE_PLATFORM_APP_KEY`, `FINANCE_PLATFORM_CLIENT_SECRET` | portal SSO, roles and mailbox grants |
| `FINANCE_PORTAL_PUBLIC_URL` | browser-facing portal address for the home button |
| `OPENROUTER_API_KEY`, `OPENROUTER_APP_NAME`, `COLLECT_LLM_MODEL`, `COLLECT_AUDIO_MODEL`, `COLLECT_LLM_TIMEOUT_SECONDS` | AI summarise and transcription |
| `GRAPH_TENANT_ID`, `GRAPH_CLIENT_ID`, `GRAPH_CLIENT_SECRET`, `MAIL_SENDER` | app-only Graph and the sending mailbox |
| `RESEND_API_KEY`, `MAIL_FROM` | fallback mail provider |
| `COLLECT_BIN_RETENTION_DAYS` | bin retention, default 30 |
| `COLLECT_DATA_DIR` | folder of the SQLite file and uploads |
| `COLLECT_BACKUP_BUCKET` | `backup.sh` only |

`.env.example` also holds a real-looking browser URL for the portal home button; the value is not recorded here.
`argo-collect.service` runs Gunicorn with 2 workers and 4 threads on `127.0.0.1:8020`. `openpyxl` is imported lazily for
`.xlsx` but is absent from `server/requirements.txt` (Flask, gunicorn, python-dotenv, requests).

## 9. Screens

React 18.2, React Router 6 `BrowserRouter`, no base path, shadcn/ui, TanStack Query configured but the pages load with
`useEffect`; one API file, `src/api/base44Client.js` (an adapter keeping the Base44 SDK shape). `ProtectedRoute` sends an
unauthenticated visitor to `/login` and a user with `must_change_password` to `/change-password`; under the portal a
visitor must arrive through the SSO callback, and `/login` is still the local email and password form that the server
refuses with 403.

Entity names in the browser: `CustomerAccount` is `accounts`, `CollectionAction` is `actions`, `Contact` is `contacts`,
`EmailTemplate` is `templates`, `User` is `users`. Route numbers refer to §2.

| Path | Component | API calls | States and dialogs |
|---|---|---|---|
| `/login` | `Login.jsx` | 1 | form; error banner |
| `/change-password` | `ChangePassword.jsx` | 6 | form |
| `/` | `Dashboard.jsx` | accounts list (50), actions list (500) | spinner; four stat cards (Total Accounts, Overdue, Escalated, Actions Logged; each links to Customer or the log); the Collection Log embedded below (it loads its own 200 actions); "Log Action" button opening a two-step dialog: choose an account, then the action form; after save it goes to the account page |
| `/accounts` | `Accounts.jsx` | accounts list (100), create, patch, bulk, 18, 22 | spinner; empty "No accounts found"; search; status filter (all, active, overdue, escalated, resolved); company filter; inline edit of a row; create dialog; import dialog (upload CSV or XLSX; the records are inserted at once with no preview and no duplicate check, then a banner shows the count, or the error text) |
| `/accounts/:id` | `AccountDetail.jsx` | accounts get, patch, delete; actions filter by `account_id` (100), patch for bin and restore; 23 via the email dialog | header with account details; action timeline ("No collection actions recorded yet"); dialogs: edit account, log action (voice recorder, AI summarise, follow-up), compose email, the Bin (binned actions, restore); `confirm()` before deleting the account or binning an action |
| `/collection-log` | `CollectionLog.jsx` | actions list (200), 23 | table of every action; search, method filter, date range, per-column filters, sort, multi-select for the email dialog; empty "No collection actions found"; compose email dialog. Also embedded in the Dashboard |
| `/templates` | `Templates.jsx` | templates list (50), create, patch, delete | empty "No templates yet. Create one to get started."; create and edit dialogs; `confirm()` before delete |
| `/contacts` | `Contacts.jsx` | contacts list sorted by `full_name` (200), create, patch, delete, bulk, 18, 22 | empty "No contacts found"; create, edit and import dialogs; `confirm()` before delete |
| `/admin` | `Admin.jsx` | 13, 14, 15, 16 | users table ("No users yet"); invite dialog (409 under the portal), edit dialog (role, position, department, region, active), reset password (with `confirm()` and `alert()`); under the portal these writes are refused and the page is a read-only mirror |
| `*` | `PageNotFound` | none | 404 |

Components: `ActionForm` (notes, voice recorder, AI summarise, structured fields, save), `VoiceRecorder` (records
in the browser, uploads WAV, transcribes, appends to the notes; errors by `alert()`), `ActionTimeline`,
`ComposeEmail` (template picker, active contacts as recipients, subject and body, send one call per recipient,
success message then close; errors in the dialog), `AccountForm`. The sidebar and layout: `Sidebar.jsx` (admin and
reviewer lists, collapse button, the portal brand link), `AppLayout.jsx` (sign-out calls `auth.logout`, which
redirects to `/login`).

## 10. Open questions

1. **Is this branch what is running?** See §1. The plan flags Argo Collect specifically; nothing here confirms it.
2. **Dashboard totals are capped.** The Dashboard fetches 50 accounts and 500 actions, so "Total Accounts", "Overdue"
   and "Escalated" count only the newest 50 accounts; Accounts shows up to 100; Collection Log 200. Plan §4.1 says 2
   accounts on 17 Sep, so this is invisible today.
3. **The Bin does not hide itself.** `GET /api/actions` returns binned rows; the browser filters. Anyone calling the API
   sees binned actions, and the port must decide whether the bin is a server concept.
4. **No audit of sent mail.** `sendEmail` keeps no record (no table), allows any recipient address the browser posts, and
   accepts a caller-chosen `from_name`. Compare Tri-Task, which restricts recipients and audits every send.
5. **Mail path:** own Graph credentials plus a portal permission check, with a Resend fallback that skips the check.
   `MAIL_SENDER` is `ar@tricab.com` in the example file, the mailbox Tri-AR reads. The platform's mail design (Phase 1)
   must cover it.
6. **`POST /api/llm` runs a caller-supplied prompt**, so the AI summary prompt lives in the browser. The port should
   move it into the `finance_ai` contract.
7. **AI and transcription failures are silent**: no server logging, and a failed summarise leaves the button stuck
   busy (§7). Transcription errors do reach the user, by `alert()`.
8. **Imports are never cleaned up** from the uploads folder. In blob storage terms: spreadsheets should be temporary.
9. **`openpyxl` missing from `requirements.txt`**; `.xlsx` import works on the host only if it was installed by hand.
   The PHP port needs its own spreadsheet reader (PhpSpreadsheet or similar) and must reproduce the header
   normalisation and coercion in `table_extract.py`.
10. **Presentation gap for reviewers** (§5): no page or control checks the role in the browser, so a read-only user sees
    write controls and can open the Contacts and Admin routes, and meets a 403 on use.
11. **Uniqueness:** none enforced on `account_number`, `account_name`, `contacts.email`, `templates.name`.
12. **Dead or stale under the portal:** `/login`, `/change-password`, local user routes 1, 6 and 14 to 17. Two stale
    `app.py` backups are committed in `server/`.
13. **Tests:** `test_platform_sso.py` (6 tests) and `test_portal_link.py` (4). Not run (P0.4 installs nothing).
14. **Uncommitted visual change** in `Sidebar.jsx`: confirm it belongs in the reference.
15. **Differences from the plan:** none in §4.1 for this app (1.3k server lines, 26 routes).
