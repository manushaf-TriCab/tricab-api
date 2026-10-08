# Inventory: Tri-Credit Notes

Part of Phase 0, task P0.4 ([FINANCE-PHASE-0.md](../../../../FINANCE-PHASE-0.md)).
Business name Tri-Credit Notes; the code and portal call it Credit Flow; portal application key
`large-credit-flow-pro`; port 8030.

## 1. Source

| | |
|---|---|
| Directory | `D:\TriCab\finance\_synced-20260918\large-credit-flow-pro` |
| Branch, commit | `deploy-button-only` `102dd8a` (22 Sep 2026, "Send notifications only from a mailbox the portal grants") |
| Uncommitted files | 3, as the plan says: `src/components/Layout.jsx` (modified: adds the `PortalBrand` import and component in place of the home icon and text; `git diff --ignore-space-at-eol` shows 4 lines changed, the plain diff shows 146 because the file has CRLF endings), `src/components/PortalBrand.css` (new, 24 lines), `src/components/PortalBrand.jsx` (new, 11 lines). All three are visual only: the portal brand button in the sidebar |
| Date read | 6 Oct 2026 |
| Not read | the top-level August snapshot `D:\TriCab\finance\large-credit-flow-pro`; the unreleased `main`, except `server/models.py` for the prefixed table names and types (read with `git show main:server/models.py`; nothing checked out) |
| Remote | `origin/main` exists |

Files read: `server/{app,store,extraction,graph_client}.py` (all), `server/requirements.txt`, `.env.example`,
`deploy/credit-flow.service`, `deploy/backup.sh`, the headings of `deploy/DEPLOYMENT_AWS.md`, and in `src/`: `App.jsx`,
`api/base44Client.js`, `lib/{AuthContext,permissions,constants}.js*`, `components/ProtectedRoute.jsx`, `Layout.jsx`
(diff) and the page and component files for their API calls and states. For roles only:
`_work-portal-entra-20261005/server/app.py` (`d333f12`), `ROLE_SEED` and `MAIL_PERMISSIONS`.

Size: `server/*.py` 1,712 lines (plan §4.1: 1.8k, agrees); 27 route decorators; `src/` pages and components
about 5,000 lines.

**Shape of the app, in one paragraph.** The server is a generic CRUD service over ten tables
(`/api/<resource>`), plus numbering, user administration, upload, two AI calls and one notification route. **The
workflow itself runs in the browser.** Every status change (submit, approve, reject, return for information, send
to Finance, raise credit, close, cancel), the approval and audit history rows, the totals, GST and restocking
arithmetic, the "may this person approve" decision and the overdue rules are written in React pages and then saved
as plain field updates. The server checks only that the caller is signed in (and, for three configuration tables
and the user routes, that they are an admin). This is the main thing to settle before porting (§10).

## 2. Routes

27 route decorators in `server/app.py`, equal to the plan's 27. The HTTP surface is the Base44 SDK's shape
(`entities.X.list/get/create/update/delete/bulkCreate/bulkUpdate/deleteMany`). Two of the 27 are the SPA catch-all.
The seven `entity_*` routes serve nine resources through `_RESOURCES` (`app.py:386`), so they stand for 63 URL and
method combinations.

Sign-in check in the "Who" column:

- **login**: `login_required` (`app.py:267`): `_current_user()` must find a user. With the portal configured
  (`FINANCE_PLATFORM_URL` and the client secret set) the user comes from the Flask session
  (`platform_uid`, `platform_session_version`) and a portal `introspect` call, cached per request in `g`. A local
  session cookie is ignored once the portal is configured. 401 otherwise. **No permission, role or row check
  follows.**
- **admin**: `admin_required` (`app.py:276`): with a portal session the caller needs `credit.users.manage`;
  with a local session `role == "admin"`.
- **admin-write**: the `_require_write_access(True)` check (`app.py:406`) on writes to `branches`,
  `approval-limits` and `user-branch-roles`: `user["role"] == "admin"`, where the role was derived from the portal
  role keys (see §5). Reads of those three are open to any signed-in user.

| # | Method, path | Handler (`server/app.py`) | Who | Reads / writes | Side effects |
|---|---|---|---|---|---|
| 1 | POST `/api/auth/login` | `auth_login` :293 | none | local `users` (email, password hash) | sets a session. **403 "Sign in through TriCab Finance" whenever the portal is configured**, so it is live only in local development |
| 2 | POST `/api/auth/logout` | `auth_logout` :314 | none | clears the session | none |
| 3 | GET `/api/config` | `app_config` :320 | none | env `FINANCE_PORTAL_PUBLIC_URL` | none |
| 4 | GET `/api/auth/me` | `auth_me` :330 | session | the signed-in user, with derived `role`, `branch`, `allowed_branches`, `permissions`, `platform_managed` | portal introspect |
| 5 | GET `/api/auth/platform/callback` | `platform_callback` :338 | none (the SSO return) | writes the session cookie | portal `exchange(code)`; redirect to `/` |
| 6 | POST `/api/auth/change-password` | `auth_change_password` :359 | login | local `users.password_hash` | 409 under the portal; local development only |
| 7 | GET `/api/<resource>` | `entity_list` :437 | login | any of the nine `_RESOURCES`; `?sort=` (any column, default `-created_date`), `?limit=` (default 200, max 2000), and **equality filters on any column** through the other query arguments | none |
| 8 | GET `/api/<resource>/<id>` | `entity_get` :447 | login | one row | none |
| 9 | POST `/api/<resource>` | `entity_create` :459 | login; admin-write for three resources | insert; **an array body is a bulk create**; `credit-notes` rows are stamped with `created_by_id` and `created_by` from the session | none |
| 10 | POST `/api/<resource>/bulk-update` | `entity_bulk_update` :479 | login; admin-write | updates each `{id, ...}` in turn, not in one transaction | none |
| 11 | POST `/api/<resource>/delete-many` | `entity_delete_many` :501 | login; admin-write | `DELETE ... WHERE` on the given equality filter; refuses an empty filter | none |
| 12 | PATCH `/api/<resource>/<id>` | `entity_update` :519 | login; admin-write | update (whitelisted columns only; unknown keys are dropped silently) | none |
| 13 | DELETE `/api/<resource>/<id>` | `entity_delete` :533 | login; admin-write | hard delete. For `credit-notes` also deletes its `product_lines`, `attachments`, `comments`, `approval_history`, `audit_history` rows; for `attachments` also deletes the file on disk | file removal |
| 14 | POST `/api/credit-notes/next-number` | `credit_note_next_number` :563 | login | scans `credit_notes.credit_note_number LIKE 'CN-<BRANCH>-<year>-%'` and returns max + 1, four digits | none (not atomic across processes: see §10) |
| 15 | GET `/api/users` | `users_list` :591 | login | with the portal: the portal's member list for this app (`list_users`), mapped by `_platform_member`, **403 for a caller the portal says is not an app admin**; without it, the local `users` table | portal `list_users` |
| 16 | GET `/api/users/<id>` | `users_get` :613 | login | **local** `users` row (stale under the portal) | none |
| 17 | POST `/api/users/invite` | `users_invite` :622 | admin | local only; returns a temporary password | 409 "Users are managed in TriCab Finance" under the portal |
| 18 | POST `/api/users/<id>/reset-password` | `users_reset_password` :648 | admin | local only | 409 under the portal |
| 19 | PATCH `/api/users/<id>` | `users_update` :666 | admin | local only; cannot demote the last admin | 409 under the portal |
| 20 | DELETE `/api/users/<id>` | `users_delete` :691 | admin | local only | 409 under the portal |
| 21 | POST `/api/upload` | `upload_file` :724 | login | writes the file to `server/data/uploads/<12 hex>-<secure name>`; returns `{file_url: "/api/files/<name>"}`. Allowed extensions: pdf, images, doc, docx, xls, xlsx, csv, txt, msg, eml, webm, ogg, mp3, wav, m4a; request cap 32 MB | file written |
| 22 | GET `/api/files/<name>` | `serve_file` :736 | login | reads the file | none |
| 23 | POST `/api/invoke-llm` | `api_invoke_llm` :742 | login | none | **OpenRouter** with **a prompt supplied by the caller** (§7) |
| 24 | POST `/api/transcribe` | `api_transcribe` :752 | login | reads an uploaded audio file | **OpenRouter** audio transcription; ffmpeg for webm and ogg |
| 25 | POST `/api/functions/sendNotification` | `send_notification` :800 | login | recipients from `user_branch_roles` (branch and role) and, for `finance` and `admin`, the local `users` table; writes an `audit_history` row when a credit note id is given | **sends mail** through the app's own Graph client, one message per recipient, from `NOTIFY_FROM_MAILBOX`, after the portal says the app may send from it |
| 26 | GET `/` | `spa` :858 | none | `dist/index.html` | none |
| 27 | GET `/<path>` | `spa` :859 | none | `dist/<path>` or `index.html`; `api/...` answers 404 JSON | none |

Reconciliation: 27 found, 27 in plan §4.1. Equal.

Behaviour that matters for the port:

- **Credit note numbers** are `CN-<branch code>-<year>-<4 digits>`, for example `CN-MEL-2026-0001`; branch code
  falls back to `GEN`. The year is UTC.
- **Notification recipients** (`_notification_recipients`, `app.py:784`): every `user_branch_roles` row whose
  `branch` and `role` equal the request's branch and target role; for target `finance` or `admin` also every
  local `users` row with that role; the sender is removed. Under the portal the local `users` table is empty
  (`_seed_admin` does not run when the portal is configured), so `finance` and `admin` notifications reach only
  people who also have a `user_branch_roles` row.
- **Failure handling:** a failed send is collected per recipient and returned as `{sent, total, errors}`; the
  browser ignores it (it logs `Notification error` to the console only), so a failed notification is invisible to
  the user. The audit row records `N recipient(s) notified, M failed`.
- **Mail gate:** `_send_refusal` (`app.py:106`) asks the portal's Mail Service for this app's mailboxes and
  refuses unless `NOTIFY_FROM_MAILBOX` is granted `can_send`; if the portal is not configured the check passes.

## 3. Data

SQLite, `server/data/credit-notes.sqlite3` (`CREDIT_DATA_DIR`), created at import by `store.init_db()`
(`CREATE TABLE IF NOT EXISTS`). There is **no migration system** on the live branch: `_MIGRATION_COLUMNS` is empty,
so a schema change means editing the DDL and adding the column to `_MIGRATION_COLUMNS`. Rows leave the store as
plain dicts; only the columns named in `_TABLE_FIELDS` are accepted on write.

Live column types are `TEXT`, `REAL` and `INTEGER` only. Ids are `uuid4().hex` (32 hex characters, no dashes).
`created_date` and `updated_date` are ISO 8601 `TEXT` with a `+00:00` offset written by the server. Every other date
column is a **string whose format the browser decides**: a date input gives `yyyy-MM-dd`, a workflow action gives
`new Date().toISOString()` (with `Z`), and clearing a field gives `""`. Booleans are `INTEGER` 0 or 1 returned as
real booleans (the UI tests `!== false`).

Prefixed names, from the September rewrite on `main` (`server/models.py`, `TABLE_PREFIX = "credit_"`), as the plan
(§6) keeps them. That rewrite was never deployed; the live database holds the unprefixed tables:

Column comparison (checked by script, live `_TABLE_FIELDS` against `main`'s `models.py`): **all 10 tables carry the same columns on both**, so unlike Tri-AP the September definitions are complete for this app. The type differences are the ones listed below (money, dates, long text).

| Live table | Prefixed table on `main` | Live columns | Indexes (live) | Indexes (`main`) |
|---|---|---|---|---|
| `credit_notes` | `credit_credit_notes` | 91 | none | `ix_credit_credit_notes_credit_note_number` |
| `product_lines` | `credit_product_lines` | 12 | `idx_lines_note (credit_note_id)` | `ix_credit_product_lines_credit_note_id` |
| `attachments` | `credit_attachments` | 9 | `idx_attachments_note` | `ix_credit_attachments_credit_note_id` |
| `comments` | `credit_comments` | 8 | `idx_comments_note` | `ix_credit_comments_credit_note_id` |
| `approval_history` | `credit_approval_history` | 9 | `idx_approval_note` | `ix_credit_approval_history_credit_note_id` |
| `audit_history` | `credit_audit_history` | 10 | `idx_audit_note` | `ix_credit_audit_history_credit_note_id` |
| `branches` | `credit_branches` | 8 | none | none |
| `approval_limits` | `credit_approval_limits` | 8 | none | none |
| `user_branch_roles` | `credit_user_branch_roles` | 16 | `idx_ubr_user (user_id)` | `ix_credit_user_branch_roles_user_id` |
| `users` | `credit_users` | 11 | `email` unique | `email` unique |

**No foreign keys anywhere**: the five child tables carry `credit_note_id TEXT` with no constraint, and cascade
deletion is done in the route (`entity_delete`). `user_id` in `user_branch_roles` holds a portal user id.

### `credit_notes` (91 columns)

`id` PK; `created_date`, `updated_date`; then 88 data columns. Grouped, all `TEXT` live unless marked:

- Request: `credit_note_number`, `request_date`, `branch`, `sales_representative`, `status` (default `Draft`),
  `current_owner`, `created_by_name`, `created_by_id` (portal user id), `created_by` (email), `branch_manager`,
  `stage_start_date`, `last_approver_name`, `currency` (default `AUD`).
- Customer and references: `customer_code`, `customer_name`, `customer_contact_name`, `customer_contact_email`,
  `customer_contact_phone`, `tricab_reference_number`, `trace_number`, `delivery_consignment_note_number`,
  `tricab_contact`, `original_invoice_number`, `original_sales_order_number`, `customer_purchase_order_number`.
- Problem and request: `problem_category`, `problem_description`, `requested_action`, `other_action_details`.
- Returns: `goods_being_returned`, `return_arranged_by`, `carrier`, `carrier_booking_reference`,
  `expected_return_date`, `actual_return_date`, `goods_received_back`, `goods_received_date`, `return_warehouse`,
  `stock_condition`, `restockable`, `return_notes`, `goods_return_confirmed`, `goods_return_waiver`.
- Money (`REAL` live; `Numeric(18, 2)` on `main`, except `restocking_fee_percentage` `Numeric(9, 4)`):
  `requested_credit_value`, `approved_credit_value`, `restocking_fee_percentage`, `restocking_fee_amount`,
  `other_adjustment`, `subtotal_ex_gst`, `gst`, `total_credit`, `approved_credit_limit`,
  `final_approved_credit_value`. Money is therefore **floating point on the live database**.
- Finance: `restocking_fee_applicable`, `restocking_fee_explanation`, `override_explanation`, `assessed_by`,
  `assessment_date`, `finance_reviewer`, `finance_review_date`, `erp_credit_note_number`, `erp_posting_date`,
  `credit_raised`, `credit_raised_date`, `customer_account_updated`, `finance_notes`, `closed_by`, `closed_date`.
- Investigation: `root_cause_category`, `root_cause_details`, `corrective_action`, `preventive_action`,
  `corrective_action_owner`, `corrective_action_due_date`, `corrective_action_completed`,
  `corrective_action_completion_date`, `corrective_action_notes`, `car_required`, `car_reference_number`.
- Approval: `bm_decision`, `approved_by`, `approval_date`, `approval_comments`, `rejection_reason`,
  `information_requested`.
- Bin: `is_deleted` (`INTEGER`, default 0), `deleted_by`, `deleted_date`.

Many yes and no flags are `TEXT` holding `"Yes"` or `"No"` (`goods_being_returned`, `goods_received_back`,
`restockable`, `restocking_fee_applicable`, `car_required`, `credit_raised`, `corrective_action_completed`,
`goods_return_confirmed`, `goods_return_waiver`, `customer_account_updated`), not booleans. The only boolean
column is `is_deleted`.

**Long text columns** (`main` uses `NVARCHAR(MAX)` for these 14): `problem_description`, `requested_action`,
`other_action_details`, `return_notes`, `restocking_fee_explanation`, `override_explanation`,
`root_cause_details`, `corrective_action`, `preventive_action`, `corrective_action_notes`, `approval_comments`,
`rejection_reason`, `information_requested`, `finance_notes`. **JSON in a text column:** `requested_action` holds a
JSON array (the UI's multi-select; decoded on read by `_JSON_COLUMNS`) and `user_branch_roles.roles` holds a JSON
array.

### Child tables

- `product_lines`: `id`, `credit_note_id`, `product_code`, `product_description`, `quantity` (REAL; `Numeric(18,4)`
  on `main`), `unit_of_measure`, `original_unit_price` (REAL), `requested_credit_value` (REAL),
  `batch_lot_drum_serial`, `product_return_required`, `created_date`, `updated_date`.
- `attachments`: `id`, `credit_note_id`, `file_url` (`/api/files/<name>`), `file_name`, `document_type`,
  `uploaded_by`, `upload_date`, `created_date`, `updated_date`.
- `comments`: `id`, `credit_note_id`, `user_name`, `comment`, `workflow_stage`, `is_internal_finance` (boolean),
  `created_date`, `updated_date`.
- `approval_history`: `id`, `credit_note_id`, `action`, `user_name`, `decision_date`, `comments`,
  `credit_value_at_decision` (REAL), `created_date`, `updated_date`.
- `audit_history`: `id`, `credit_note_id`, `action`, `field_name`, `previous_value`, `new_value`, `user_name`,
  `timestamp` (client or server ISO string), `created_date`, `updated_date`.
- `branches`: `id`, `branch_code`, `branch_name`, `region`, `branch_manager` (a name), `active_status` (boolean),
  `created_date`, `updated_date`.
- `approval_limits`: `id`, `min_value` (REAL), `max_value` (REAL), `approver_role`, `description`,
  `active_status`, `created_date`, `updated_date`.
- `user_branch_roles`: `id`, `user_id`, `user_name`, `user_email`, `branch` (a name, not an id), `role`,
  `primary_role`, `roles` (JSON text), `approval_limit_min`, `approval_limit_max` (REAL), `approval_manager`,
  `manager` (boolean: this person is a branch manager for this branch), `is_primary`, `active_status`,
  `created_date`, `updated_date`.
- `users`: `id`, `email` unique, `full_name`, `role` (default `sales`), `branch`, `manager`, `active_status`,
  `password_hash`, `must_change_password`, `created_date`, `updated_date`. **Local identity table; the platform
  replaces it.** Holds a password hash column (no secrets are recorded here).

### Notes for Azure SQL

- **Sort and filter on any column:** `list_rows` accepts `?sort=` and equality filters on every whitelisted column,
  including the 14 long text columns. The UI sorts only by `created_date` (the default) and filters only on
  `credit_note_id`, `user_id`, `role` and `branch`, but a port must restrict the allowed sort columns to bounded
  ones (the `ORDER BY` on `NVARCHAR(MAX)` hazard in plan §13).
- **Timestamps without a zone:** the live `created_date` carries an offset (text); on `main` it becomes naive UTC
  `DATETIME2(6)`. All other date and time columns are free-form strings (see above) and need one parsing rule if
  they are ever converted.
- **Money** is float live; `Decimal(18,2)` on `main`, as the plan wants.
- **Child rows without foreign keys** and orphan risk: `bulk-update` is not transactional, and the form's save is
  four to six separate requests (§9), so a failure part-way leaves a credit note with missing lines or history.
- **Data volume:** plan §4.1 says 0 credit notes on 17 Sep. Not rechecked (the host is out of bounds).

## 4. Files

Uploads are written to `server/data/uploads/<12 hex>-<secure_filename>` (`CREDIT_DATA_DIR`). They are referenced by
`attachments.file_url` as `/api/files/<stored name>`, and served by `GET /api/files/<name>` to any signed-in user
(no check that the caller may see the credit note the file belongs to). A voice recording for the AI filler is
uploaded through the same route and then transcribed; the recording stays on disk. Deleting a credit note, or an
attachment, removes the files of its attachments (`entity_delete`); a pending attachment that the user abandons
before saving the form is never removed. Nightly backup of the whole data directory (SQLite plus uploads) to S3 by
`deploy/backup.sh` (root cron, 02:35, `CREDIT_BACKUP_BUCKET`).

## 5. Roles and access

Identity and roles are the portal's (application key `large-credit-flow-pro`). `_platform_user` (`app.py:196`)
turns the portal answer into a single legacy role: the first of `admin`, `finance`, `branch_manager`,
`sales`, `executive_viewer`, in that order, found among the role keys, else `sales`. Branch comes from role entries with
`scope_type == "branch"`; `branch` is set only if exactly one, and `allowed_branches` lists all.

Portal roles and permissions (`ROLE_SEED`):

| Role key | Label | Permissions |
|---|---|---|
| `sales` | Sales | `credit.notes.read`, `credit.notes.create`, `credit.notes.update_own` |
| `branch_manager` | Branch Manager | `credit.notes.read`, `credit.notes.approve` |
| `finance` | Finance | `credit.notes.read`, `credit.notes.process`, `credit.notes.delete` |
| `executive_viewer` | Executive Viewer | `credit.notes.read`, `credit.reports.read` |
| `admin` | Administrator | `credit.notes.read`, `.create`, `.update`, `.delete`, `.approve`, `.process`, `credit.reports.read`, `credit.mail.send`, `credit.users.manage`, `credit.settings.manage` |

What the server actually enforces: **only** `credit.users.manage` (user routes) and `role == "admin"` on writes to
three configuration tables. None of `credit.notes.*`, `credit.reports.read`, `credit.mail.send` or
`credit.settings.manage` is checked anywhere in `server/` (`grep` of the permission names finds only
`credit.users.manage` and the test fixtures). `allowed_branches` is computed and sent to the browser but never
used to filter anything, on either side.

What the browser decides (`lib/permissions.js`, the pages):

- `canApprove`: admin yes; role `sales` no; otherwise the person must hold a `user_branch_roles` row with
  `manager === true` for the note's branch. These rows are **app data, edited in the Administration page**, not
  portal roles. The portal's `branch_manager` role and `credit.notes.approve` permission are not what decides.
- `canProcessFinance`: admin yes; role `finance` only while the status is `Branch Manager Approved`,
  `Finance Review` or `Credit Raised`.
- `canEdit`: any status except `Closed`; the request form is editable by its creator or an admin while `Draft` or
  `More Information Required`.
- Delete to the bin and permanent delete (register): `role` admin or finance. Reports nav item: `finance`, `admin`,
  `general_manager`, `ceo`, `cfo`. The Dashboard shows one of four stat sets by role (`sales`, `branch_manager`,
  `finance`, `admin`; other roles see none). `executive_viewer`, `general_manager`, `ceo` and `cfo` get no role-
  specific dashboard.
- The Administration page lists 7 display roles (Administrator, Finance, Sales Rep, Branch Manager, General
  Manager, CEO, CFO) mapped to the internal roles; under the portal, role and membership edits are blocked (409)
  and users are managed in the portal. Branch assignments, approval limits and branches remain editable here.

Row visibility: every signed-in user can list and read every credit note, attachment, comment and history row
(`/api/credit-notes` returns all, up to the limit); the Dashboard, My Actions and register filter in the browser.

How the app gets roles: `finance_access` (`FinanceAccessClient` from `shared/tricab_access_client.py`, imported from
`FINANCE_PLATFORM_SHARED_ROOT`, default the sibling `tricab-finance-aws`): `exchange`, `introspect`, `list_users`.
`FinanceMailClient` (`shared/tricab_mail_client.py`): `list_mailboxes` only.

## 6. Background work

None in the app: no timers, no scheduled jobs, no mailbox scanning, and no start-up migration beyond
`CREATE TABLE IF NOT EXISTS` at import. The only scheduled item is the nightly backup (root cron, 02:35,
`deploy/backup.sh`), retired with the move. Overdue flags (Submitted more than 3 days, Finance more than 5 days,
goods returns and corrective actions past due, unfinished more than 30 days) are computed in the browser from
`stage_start_date` and the due dates; no reminder mail is sent for them.

## 7. External calls

- **TriCab Finance portal** (`FINANCE_PLATFORM_URL`, loopback): code exchange, session check, member list, and the
  mailbox grant check.
- **Microsoft Graph, app-only**, the app's own `GraphClient` (`graph_client.py`): `POST
  /users/<mailbox>/sendMail` with `saveToSentItems: true`, token from `GRAPH_TENANT_ID`, `GRAPH_CLIENT_ID`,
  `GRAPH_CLIENT_SECRET` (the file says it reuses the Tri-AP registration). Sends from `NOTIFY_FROM_MAILBOX`
  (default `ap@tricab.com`), one message per recipient, HTML. **No mailbox is read, so there is no Graph message-id
  form to record.** Note this is a different path from Tri-Task, which sends through the portal's Mail Service; here
  the portal is only consulted for permission.
- **OpenRouter** (`extraction.py`): `invoke_llm` for text extraction (model `CREDIT_LLM_MODEL`, default
  `google/gemini-2.5-flash`, temperature 0, strict JSON schema off but restated in the prompt, timeout
  `CREDIT_LLM_TIMEOUT_SECONDS` 60) and `transcribe` for audio (model `CREDIT_AUDIO_MODEL`, same default;
  wav and mp3 sent as is, other formats transcoded with `ffmpeg`, which must be installed on the server). The
  browser builds the prompt: `AICreditNoteFiller.jsx` sends a prompt ("You are an assistant that extracts credit note
  request details from unstructured text or a voice transcript…") and a response schema, so `/api/invoke-llm` runs
  **whatever prompt a signed-in caller posts**. **On failure** `ExtractionError` becomes HTTP 502 with the message;
  nothing is logged server-side beyond that (no `log.error` call in `extraction.py`). Contrast Tri-Task, which logs.
- **Odoo, Document Intelligence:** none.

## 8. Settings

Names only (`.env.example`; on the host `/etc/credit-flow.env`):

| Setting | Controls |
|---|---|
| `SECRET_KEY` | Flask session signing (random per process if unset) |
| `SESSION_COOKIE_SECURE` | secure cookie flag |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | local admin seeded when the `users` table is empty **and no portal is configured**; a generated password is written to the log if none is set |
| `FINANCE_PLATFORM_URL`, `FINANCE_PLATFORM_SHARED_ROOT`, `FINANCE_PLATFORM_APP_KEY`, `FINANCE_PLATFORM_CLIENT_SECRET` | portal SSO, role and mailbox-grant calls; unset means local login |
| `FINANCE_PORTAL_PUBLIC_URL` | browser-facing portal address for the home button |
| `OPENROUTER_API_KEY`, `OPENROUTER_APP_NAME`, `CREDIT_LLM_MODEL`, `CREDIT_AUDIO_MODEL`, `CREDIT_LLM_TIMEOUT_SECONDS` | AI filler and transcription |
| `GRAPH_TENANT_ID`, `GRAPH_CLIENT_ID`, `GRAPH_CLIENT_SECRET` | app-only Graph for notification mail |
| `NOTIFY_FROM_MAILBOX` | sending mailbox, default `ap@tricab.com` |
| `APP_BASE_URL` | builds the "Open this request" link in mail; empty gives "log in" text |
| `PORT`, `FLASK_DEBUG` | development server |
| `CREDIT_DATA_DIR` | folder of the SQLite file and uploads |
| `CREDIT_BACKUP_BUCKET` | `backup.sh` only |

`.env.example` also holds one real-looking browser URL for the portal home button; the value is not recorded here.
`credit-flow.service` runs Gunicorn with 2 workers and 4 threads, 120 s timeout, port 8030.

## 9. Screens

React 18.2, React Router 6 `BrowserRouter`, no base path, shadcn/ui, TanStack Query configured but the pages load
with `useEffect`; one API file, `src/api/base44Client.js` (an adapter keeping the Base44 SDK shape).
`AuthProvider` calls `/api/auth/me` once; `ProtectedRoute` sends unauthenticated users to `/login` and, if
`must_change_password`, to `/change-password`. Under the portal the user must arrive through a portal SSO callback
(`/api/auth/platform/callback`); the `/login` page is still the local email and password form, and an attempt to use
it answers the 403 above.

All calls below are the generic entity routes (7 to 13) unless numbered otherwise in §2.

| Path | Component | API calls | States and dialogs |
|---|---|---|---|
| `/login` | `Login.jsx` | 1 | Form; error banner |
| `/change-password` | `ChangePassword.jsx` (outside `Layout`) | 6 | Form; success; error |
| `/` | `Dashboard.jsx` | 4; `credit-notes` list (200) | Spinner. Six stat cards chosen by role: `sales` (My Drafts, Needs My Info, Awaiting Approval, In Finance, Completed, Rejected), `branch_manager` (Awaiting Approval, Returned / Needs Info, Approved / Awaiting Finance, Credits This Month, Overdue Returns, Overdue CA), `finance` (Awaiting Finance, Awaiting Goods Return, Ready to Raise, Raised / Not Closed, Credit This Month, Overdue), `admin` (Total Requests, Open, Closed, Credit This Month, In Finance, Rejected); clicking a card opens the list behind it. "New Credit Note" button |
| `/credit-notes/new`, `/credit-notes/:id/edit` | `CreditNoteForm.jsx` | 4; `branches` list; `credit-notes` get, create, patch; `product-lines` filter, delete-many, bulk create; `attachments` filter, bulk create; `audit-history` create; `approval-history` create; 14; 25; 21; 23; 24 | Spinner. New and edit modes. AI filler panel (paste text, upload a file, or record voice; fills the form). Product line editor (add, remove, totals). Attachment uploader with document types. Buttons Save draft and Submit. Totals and GST computed in the browser. Errors are `alert()` |
| `/credit-notes/:id` | `CreditNoteDetail.jsx` | 4; `credit-notes` get, patch; `product-lines`, `approval-history`, `audit-history` filter and create; `user-branch-roles` filter; 25; `comments` and `attachments` (components) | Spinner; summary panel with stage timeline and days at stage and overdue flag; read-only sections; **action panels shown by status and permission**: branch manager decision (Approved, Rejected, More Information, with required reason or comment rules) when `Submitted`; creator resubmit when `More Information Required`; send to Finance when `Branch Manager Approved`; Finance details and Mark Credit Raised and Close; Cancel; investigation section (root cause and corrective action); comments (with an internal-finance flag); attachments; history tables; PDF export (`lib/creditNotePdf.js`, client side). Errors are `alert()` |
| `/register` | `CreditNoteRegister.jsx` | 4; `credit-notes` list (500), patch, bulk-update, delete; `branches` list | Spinner; Active and Rubbish-bin views; search; filters (branch, status, problem, invoice, date range); per-column filters and sort; multi-select with bulk move to bin, restore and permanent delete (with `confirm()`); empty "No credit notes found" or "Rubbish bin is empty" |
| `/my-actions` | `MyActions.jsx` | 4; `credit-notes` list (500); `user-branch-roles` filter | Pending actions list by role, empty "No pending actions. You're all caught up!"; overdue goods returns and corrective actions for branch managers, finance and admin |
| `/reports` | `Reports.jsx` | `credit-notes` list (500); `branches` list | Filters, summary cards, bar and pie charts (Recharts) over the loaded rows, and "Export Report" (CSV built in the browser) |
| `/administration` | `Administration.jsx`, `UserEditorDialog.jsx`, `RolesTab.jsx` | 15, 19; `branches`, `user-branch-roles`, `approval-limits` full CRUD | Tabs Users, Roles, Branches. Users table ("No users found"), role filter, invite and edit dialog (invite is blocked under the portal), branch assignment rows; Roles tab edits approval limits; Branches tab add, set manager, delete ("No branches found") |
| `*` | `PageNotFound` | none | 404 |

The sidebar (`Layout.jsx`) lists Dashboard and, for finance, admin, general manager, CEO and CFO, Report; New Credit
Note, My Actions and the register are reached from page buttons and cards. The portal home button (the uncommitted
`PortalBrand` change) shows when `FINANCE_PORTAL_PUBLIC_URL` is set.

## 10. Open questions

1. **The workflow has no server side.** Status transitions, who may approve or process, history rows, totals,
   GST (10%), restocking fee, overdue rules and notification triggers are all in the browser; the server accepts any
   whitelisted field update from any signed-in user. A port into PHP modules has to decide whether to reproduce this
   as it is (generic CRUD) or move the rules into use cases, and the plan's "routes match the Python app's behaviour"
   test cannot be applied route by route. Needs a decision before Phase 3.
2. **Approval limits are display-only.** `approval_limits` is edited in Administration but no code reads it when
   approving; the note's `approved_credit_limit` is just the approved amount. Likewise `user_branch_roles.approval_limit_*`.
3. **Portal permissions are mostly unused.** `credit.notes.*`, `credit.reports.read`, `credit.mail.send` and
   `credit.settings.manage` are seeded but never checked; the approving rule is the app's own `manager` flag on
   `user_branch_roles`, not the portal's `branch_manager` role. The platform's per-route permissions and the
   sign-in-strength policy need a mapping from these two worlds. `allowed_branches` is unused. Also, the browser
   knows roles the portal does not have: `general_manager`, `ceo` and `cfo` (Administration offers them; the Reports
   menu item is shown only to `finance`, `admin`, `general_manager`, `ceo`, `cfo`). Under the portal the derived role
   can only be `admin`, `finance`, `branch_manager`, `sales` or `executive_viewer`, so an Executive Viewer (the
   portal role that holds `credit.reports.read`) never sees the Reports item and gets no dashboard cards.
4. **Notification recipients under the portal** miss the finance and admin people who have no `user_branch_roles`
   row (the local `users` table is empty). Whether notifications reach Finance today is unverified.
5. **Failed notifications are silent** (browser ignores the response). Decide whether the port surfaces them.
6. **Mail path differs from Tri-Task**: own Graph credentials plus a portal permission check. The platform's mail
   design (Phase 1) must cover this app's `NOTIFY_FROM_MAILBOX` (`ap@tricab.com`, which Tri-AP also scans and
   TriExpense also sends from).
7. **`POST /api/invoke-llm` runs a caller-supplied prompt**, so the AI filler's prompt lives in the browser. The
   port should move the prompt and schema to the server (the contract `finance_ai` is meant to hold).
8. **Money is float** live and in the `credit_notes` approval comparison logic; `main` fixed that with `Numeric`.
   Migration must convert to `DECIMAL(18,2)` and check rounding of existing rows (plan says the table is almost empty).
9. **Credit note numbering** reads the table then adds one under a per-process lock. Two Gunicorn workers can allocate
   the same number; there is no unique index on `credit_note_number`. Not a problem at near-zero volume; the port
   should use a sequence or a unique index.
10. **Files** are served to any signed-in user and orphaned uploads are never cleaned. The blob store design in
    Phase 1 should key files to the credit note and check access.
11. **Child records are written in several requests** with no transaction (form save is 4 to 6 calls; history rows are
    separate calls), so partial saves are possible.
12. **Dead or stale code under the portal:** `/login` page, `/change-password` page, `ChangePassword`, local user
    routes 6 and 17 to 20 and `GET /api/users/<id>` (reads the stale local table). Remove or keep as dev tools.
13. **No logging of AI failures** in `extraction.py` (the 502 reaches the browser only), unlike Tri-Task.
14. **Tests not run:** `server/tests/test_api.py` is a long script-style test (no `def test_` functions) and
    `test_portal_link.py` has 4; no tests were run (P0.4 installs nothing). `main` has more tests (`test_migration.py`,
    `test_store.py`) that apply to the unreleased rewrite.
15. **Uncommitted `Layout.jsx`** changes the portal button to the new brand component; confirm Manusha wants that in
    the reference.
16. **Differences from the plan:** none found in §4.1 for this app (1.8k server lines, 27 routes, 8.8k of frontend
    JavaScript not recounted). The plan's note that the three `_synced-20260918` apps hold uncommitted files is
    correct (3 files).
