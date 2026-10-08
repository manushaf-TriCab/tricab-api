# Inventory: Tri-AR

Part of Phase 0, task P0.4 ([FINANCE-PHASE-0.md](../../../../FINANCE-PHASE-0.md)).
Business name Tri-AR; the code calls it Peridot Smart Ledger Flow; portal application key
`peridot-smart-ledger-flow`; port 8050. It scans the `ar@tricab.com` mailbox, classifies and summarises each
message with AI, reads remittance advices out of attachments, drafts and sends replies, and runs the tokenised
credit-note form for sales reps.

## 1. Source

| | |
|---|---|
| Directory | `D:\TriCab\finance\peridot-smart-ledger-flow` |
| Branch, commit | `register-updates-2026-09-22` `d62592d` (23 Sep 2026, "Tri-AR: sidebar Bin and Settings, period tiles, Melbourne days, mail sender and attachments"). The other branch is `master` (`03d88ca`, "Import Tri-AR as it stands") |
| Remote | none |
| Uncommitted files | **22**, as the plan says: 18 modified and 4 new. The working tree was read as it stands |
| Date read | 6 Oct 2026 |

The 22 uncommitted files (counts from `git diff --stat --ignore-space-at-eol`, which ignores the CRLF versus LF
noise; the plain diff of several files shows every line changed):

| Kind | File | What changed |
|---|---|---|
| Modified | `server/app.py` (+110 lines) | adds `GET /api/email-records/<id>/body` (original message from Graph), `TRI_CREDIT_NOTES_URL` and `credit_notes_url` in `/api/config`, a 400 on malformed dates in the register list |
| Modified | `server/store.py` (+91) | `email_date` normalised to one UTC form (`YYYY-MM-DDTHH:MM:SSZ`) and rewritten once at start-up (`_normalise_email_dates`), Melbourne local-day bounds for filters and dashboard tiles (`_local_day_bounds`) |
| Modified | `server/graph_client.py` (+16) | scans oldest first so a message cap keeps the oldest unseen mail; `get_message(..., include_recipients)` |
| Modified | `server/scan.py` (+17), `server/tests/test_backend.py` (+145) | matching changes and tests |
| Modified | `.env.example`, `HANDBACK.md`, `deploy/DEPLOYMENT_AWS.md` | new settings and notes |
| Modified | `src/api/base44Client.js`, `src/components/AppLayout.jsx`, `src/components/ScanProgressModal.jsx`, `src/lib/dateRanges.js` (new ranges), `src/lib/exportUtils.js`, `src/lib/portalUrl.js`, `src/pages/EmailDetail.jsx`, `src/pages/Home.jsx`, `src/components/ui/popover.jsx`, `src/components/ui/table.jsx` | in-app original-message viewer, Linked Systems link, date ranges, portal brand, table and popover fixes |
| New | `src/components/ConfirmBinDialog.jsx`, `src/components/EmailBodyFrame.jsx` | the Bin confirmation and the sandboxed viewer for the original message |
| New | `src/components/PortalBrand.css`, `src/components/PortalBrand.jsx` | the portal brand button (also uncommitted in the other apps) |

So the inventory describes the **uncommitted** behaviour where it differs, including the `/body` route. Owner task:
commit these 22 files so there is a fixed reference.

**The working directory holds live data, which this inventory does not reproduce:** `.env` (real settings,
**not opened**), `server/data/ar.sqlite3` (dated 22 Sep) and `server/data/attachments/` (45 record folders, 49 files,
customer remittance documents). All three are gitignored. Nothing from them is quoted here, and nothing in P0.4
needs them. If the screenshots task (P0.6) uses a copy of this data it must stay outside the repositories.

Files read: `server/{app,store,classify,remittance,scan,graph_client,option_defaults}.py` (all),
`.env.example`, `deploy/*.service`, `*.timer`, `backup.sh`, `HANDBACK.md`, the front-end `App.jsx`,
`api/base44Client.js`, `lib/*`, and the pages and components for API calls and states. `Home.jsx` (907 lines) and
`EmailDetail.jsx` (603 lines) were read for calls and dialogs, not line by line. For roles only:
`_work-portal-entra-20261005/server/app.py` (`d333f12`), `ROLE_SEED`.

Size: `server/*.py` 4,430 lines excluding tests (app 1,422, store 1,481, graph 402, classify 430, remittance 359,
scan 242, option_defaults 94); plan §4.1 says 5.5k, which probably includes `tests/test_backend.py` (1,922 lines).
33 route decorators.

## 2. Routes

33 route decorators in `server/app.py`, equal to the plan's 33. Two are the SPA catch-all (`GET /` and `GET /<path>`)
and two are the same `health()` function on two paths.

"Who" column:

- **perm(X)**: `require_permission(X)` (`app.py:202`). Order of checks: (1) **`SERVICE_SECRET`** as a bearer token or
  `X-Service-Key` is accepted **on every one of these routes** and skips every other check; (2) if the portal is not
  configured, the route answers 503 unless `AR_ALLOW_ANONYMOUS=1` (then an anonymous `dev@tricab.com`); (3) otherwise
  the Flask session (`platform_uid`, `platform_session_version`) is checked by a portal `introspect` call on **every
  request** (not cached); (4) the caller needs `X` **or `ar.admin`**, else 403.
- **public**: no sign-in; protected by a per-request token (routes 27 and 28).
- There is **no row-level rule**: every caller with `ar.records.read` sees every record, attachment, outbound message,
  and credit note request.

| # | Method, path | Handler (`server/app.py`) | Who | Reads / writes | Side effects |
|---|---|---|---|---|---|
| 1 | GET `/health` | `health` :260 | none | none | none |
| 2 | GET `/api/health` | `health` :261 | none | none | none |
| 3 | GET `/api/config` | `get_config` :271 | none | env: portal URL, Tri-Credit Notes URL, `AR_MAILBOX`, `APP_BASE_URL` | none |
| 4 | GET `/api/auth/platform/callback` | `platform_callback` :281 | none (SSO return) | writes the session cookie (also `user_email`, `user_name`) | portal `exchange(code)`; redirect to `/` |
| 5 | GET `/api/me` | `me` :304 | perm(`ar.records.read`) | the portal identity and its permission list | portal introspect |
| 6 | GET `/api/email-records` | `list_email_records` :323 | perm(`ar.records.read`) | `email_records`; filters category, follow-up status, status, customer (LIKE), assignee (LIKE), local-day date range, free-text search over six columns (LIKE `%x%`), sort over 14 whitelisted columns, `limit` (default 100, **no maximum**), `offset`; `?deleted=1` lists the Bin | **runs `refresh_overdue_statuses`, an UPDATE, inside this read** |
| 7 | GET `/api/email-records/<id>` | `get_email_record` :362 | perm(`ar.records.read`) | the record plus its attachments and their remittance lines | runs the overdue UPDATE |
| 8 | GET `/api/email-records/<id>/body` | `get_email_record_body` :372 | perm(`ar.records.read`) | none | **Graph** `GET /users/<mailbox>/messages/<id>` (body, recipients) after the portal's read grant check; returns untrusted HTML or text for an inert viewer. 404 if the record did not come from the mailbox |
| 9 | GET `/api/email-records/<id>/attachments/<aid>/file` | `download_attachment` :412 | perm(`ar.records.read`) | reads a stored attachment file; the row must belong to the record and the path must stay inside the attachment folder | served with `nosniff` and `Content-Security-Policy: default-src 'none'; sandbox`; `?inline=1` renders instead of downloading |
| 10 | PATCH `/api/email-records/<id>` | `update_email_record` :457 | perm(`ar.records.write`) | updates any of the 21 `EMAIL_RECORD_FIELDS` (including `body`, `source_message_id`, `mailbox`); only `follow_up_category`, `proposed_response_type` and `follow_up_status` are validated against allowed values | changing `due_date` clears `reminder_sent_at` |
| 11 | GET `/api/assignees` | `list_assignees` :474 | perm(`ar.records.read`) | portal members who hold `ar.records.write` | portal `list_members_with_permission` |
| 12 | GET `/api/settings/options` | `get_option_lists` :503 | perm(`ar.records.read`) | `option_lists` for Category and Method, with usage counts | none |
| 13 | POST `/api/settings/options/<list>` | `create_option` :516 | perm(`ar.admin`) | inserts an option (slug from the label; goes above "other") | none |
| 14 | PATCH `/api/settings/options/<list>/<value>` | `update_option` :525 | perm(`ar.admin`) | label, description, colour, active; a built-in option cannot be archived | none |
| 15 | DELETE `/api/settings/options/<list>/<value>` | `delete_option` :534 | perm(`ar.admin`) | deletes an unused option; archives one that records still carry; built-in options cannot be removed | none |
| 16 | PUT `/api/settings/options/<list>/order` | `reorder_options` :543 | perm(`ar.admin`) | rewrites `sort_order` | none |
| 17 | DELETE `/api/email-records/<id>` | `delete_email_record` :553 | perm(`ar.records.write`) | soft delete (`deleted_at`, `deleted_by`); `?permanent=1` on a binned record deletes the record, attachments, remittance lines and outbound messages, writes a tombstone to `deleted_messages`, and deletes the files | file removal |
| 18 | POST `/api/email-records/<id>/restore` | `restore_email_record` :574 | perm(`ar.records.write`) | clears `deleted_at` and `deleted_by` | none |
| 19 | POST `/api/email-records/<id>/email-draft` | `draft_email_response` :619 | perm(`ar.records.write`) | none | **OpenRouter**: draft or revise a reply from the user's instruction (§7); 502 on failure |
| 20 | GET `/api/email-records/<id>/outbound-messages` | `list_outbound_messages` :637 | perm(`ar.records.read`) | `outbound_messages` for the record | none |
| 21 | GET `/api/send-mailboxes` | `list_send_mailboxes` :680 | perm(`ar.records.read`) | none | portal Mail Service `list_mailboxes`; returns mailboxes with `can_send`, `AR_MAILBOX` first |
| 22 | POST `/api/email-records/<id>/send-response` | `send_email_response` :697 | perm(`ar.records.write`) | writes `outbound_messages` (`sending`, then `sent` or `failed`); sets the record `status = sent`, `follow_up_status = completed`, `final_outcome` | **sends mail** by Graph: a threaded reply (`createReply`, then patch, then send) when replying to the original sender from the mailbox that holds the message, else a standalone `sendMail`; HTML body built from plain text; inline attachments up to 3 MB total; portal send-grant check on the chosen From mailbox |
| 23 | POST `/api/credit-notes` | `create_credit_note` :804 | perm(`ar.records.write`) | inserts `credit_note_requests` with a random token (`secrets.token_urlsafe(32)`) and an expiry (`CREDIT_NOTE_TOKEN_HOURS`, default 72); updates the linked email record | **sends mail** by Graph from `AR_MAILBOX` to the sales rep with the form link. **No portal send-grant check on this send**, and a send failure is logged but the route still answers `success: true` |
| 24 | GET `/api/credit-notes` | `list_credit_notes` :885 | perm(`ar.records.read`) | `credit_note_requests` **including the `token` column**; filters status, customer, rep; `limit` default 100 | none |
| 25 | GET `/api/credit-notes/<id>` | `get_credit_note` :904 | perm(`ar.records.read`) | one row, token included | none |
| 26 | PATCH `/api/credit-notes/<id>` | `update_credit_note` :913 | perm(`ar.records.write`) | updates any `CREDIT_NOTE_FIELDS` (**including `token`, `token_expires_at`**); `status` validated against the 7 allowed | none |
| 27 | GET `/api/public/credit-note-form` | `get_public_credit_note_form` :933 | public: `id` and `token` query arguments | reads one request; token compared with `hmac.compare_digest`; 410 if expired | none |
| 28 | POST `/api/public/credit-note-form` | `submit_public_credit_note_form` :969 | public: `id` and `token` in the body | sets invoice number, amount, reason, rep name, `status = submitted`, `submitted_date`; updates the linked email record. 409 once the status is submitted, under review, approved, rejected or completed | none (no notification is sent that a form was submitted) |
| 29 | POST `/api/scan-emails` | `scan_emails` :1222 | perm(`ar.records.write`) | modes: none (scan since the watermark), `list` (a period; 409 `already_scanned` unless `confirmRescan`), `process` (one message), `complete` (close the run; never moves the watermark). Writes `scan_runs`, `email_records`, `email_attachments`, `remittance_lines` | **Graph reads** of the Inbox folder; **OpenRouter** (Jev and Gemini) per new message; **OpenRouter** extraction per remittance attachment; the portal's read grant is checked first (except `complete`) |
| 30 | GET `/api/scan-runs` | `list_scan_runs` :1381 | perm(`ar.records.read`) | `scan_runs`, newest first, `limit` default 20 | none |
| 31 | GET `/api/dashboard/metrics` | `dashboard_metrics` :1393 | perm(`ar.records.read`) | counts by status and category over the chosen local-day range; `in_bin` unbounded; credit note counts | runs the overdue UPDATE |
| 32 | GET `/` | `serve_frontend` :1406 | none | `dist/index.html` | none |
| 33 | GET `/<path>` | `serve_frontend` :1407 | none | `dist/<path>` or `index.html`. **Unlike the other apps it does not answer 404 for `api/...` paths**: an unknown GET under `/api` returns the SPA page | none |

Reconciliation: 33 found, 33 in plan §4.1. Equal.

Behaviour that matters for the port:

- **Reads write.** `refresh_overdue_statuses` (`store.py:663`) is an `UPDATE` run before routes 6, 7, 31 and inside
  `update_email_record` and the reminder query. An open record whose due date (a Melbourne calendar date) has passed
  becomes `overdue`; one whose due date moved out or was cleared goes back to `in_progress`. `overdue` cannot be set by
  hand. This replaces a scheduler by design ("cheaper than a scheduler and cannot fall behind").
- **Classification** (`classify.enrich_email`): two OpenRouter calls per message. Jev (`typesafe/jev-1.13`, the
  Alpha Decisions API) chooses a category, a response method, and a `needs_review` probability; a record is `needs_review`
  if confidence is below 0.80 (`AR_CONFIDENCE_THRESHOLD`), `needs_review` is at least 0.50 (`AR_REVIEW_THRESHOLD`), or
  the category is `other`; otherwise `in_progress`. Gemini (`google/gemini-2.5-flash`) returns customer name, summary
  and a drafted reply. A failure of either is recorded in `enrichment_errors` (only the count reaches the scan result),
  logged at ERROR, and sends the record to `needs_review`.
- **Remittance extraction** (`remittance.py`): for category `remittance_advice` only (`AR_EXTRACT_ALL_ATTACHMENTS=1` widens
  it), each PDF or image attachment goes to a vision model; the result is a payment header on `email_attachments` and one
  `remittance_lines` row per invoice, plus `reconciles` (do the lines add to the total, within 0.01). Images under
  `AR_MIN_ATTACHMENT_BYTES` (25,600) are skipped. PDFs are always kept.
- **Scan watermark** (`_scan_from_watermark`, `scan.py`): messages are processed oldest first and the watermark advances
  only over an unbroken run of stored messages; period scans never move it. The listing reads the **Inbox folder only**.

## 3. Data

SQLite, `server/data/ar.sqlite3` (`AR_DATA_DIR`), created at import by `store.init_db()`: idempotent
`CREATE TABLE IF NOT EXISTS`, then `_migrate_additive` (adds missing columns, tolerant of two workers racing), seeds
`option_lists` from `option_defaults.py` if empty, and `_normalise_email_dates`. Foreign keys are on
(`PRAGMA foreign_keys = ON`). Raw `sqlite3`, no ORM, no Alembic.

**No SQLAlchemy rewrite exists for this app** (it has no unreleased `main` with prefixed tables), so there are no September
prefixed names to copy. Plan §6 says "plus new `ar_*`"; the right-hand column below is the proposed name, to be confirmed in
Phase 5, not an existing definition.

Live types are `TEXT`, `REAL`, `INTEGER`. Ids are `uuid4().hex`. `created_date` and `updated_date` are `datetime.now(UTC).isoformat()`
text (`...+00:00`, microseconds). `email_date` is the exception: normalised to `YYYY-MM-DDTHH:MM:SSZ` (a UTC instant,
second precision) and compared and sorted as text, which is only correct because every row shares that form.

| Live table | Proposed name | Purpose |
|---|---|---|
| `email_records` | `ar_email_records` | one row per mailbox message |
| `email_attachments` | `ar_email_attachments` | kept attachments and the extracted payment header |
| `remittance_lines` | `ar_remittance_lines` | invoices settled by a payment |
| `outbound_messages` | `ar_outbound_messages` | replies sent from the composer |
| `credit_note_requests` | `ar_credit_note_requests` | the tokenised credit note form workflow |
| `scan_runs` | `ar_scan_runs` | scan history and watermarks |
| `option_lists` | `ar_option_lists` | Category and Method lists (Settings) |
| `deleted_messages` | `ar_deleted_messages` | tombstones for records deleted permanently |

### `email_records`

`id` PK; `email_date` NOT NULL; `customer_name`; `sender_email` NOT NULL; `sender_name`; `subject` NOT NULL; `body`
(plain text, cut to 20,000 characters on ingest); `ai_summary`; `follow_up_category` (value from `option_lists`);
`assigned_person` (an email address or a name, free text); `proposed_response_type` (default `email`); `proposed_response`;
`follow_up_status` (default `in_progress`; `needs_review`, `in_progress`, `overdue`, `completed`); `due_date` (text,
`yyyy-MM-dd`, possibly longer; compared as `substr(due_date, 1, 10)`); `final_outcome`; `source_message_id` **UNIQUE** (the
Graph message id); `mailbox` (default `ar@tricab.com`); `classification_confidence` REAL; `status` (default `new`; also
`reviewed`, `sent`); `assigned_sales_rep_email`; `credit_note_request_id` (no foreign key); `reminder_sent_at`;
`deleted_at`, `deleted_by` (the Bin); `created_date` NOT NULL; `updated_date` NOT NULL. Indexes:
`idx_email_records_source_message_id` (unique), `idx_email_records_category`, `idx_email_records_follow_up_status`,
`idx_email_records_date (email_date)`. **No index on `deleted_at`**, though every list filters on it.

### `email_attachments`

`id` PK; `email_record_id` NOT NULL FK `email_records.id` ON DELETE CASCADE; `file_name` NOT NULL; `stored_name` NOT NULL
(`<record id>/<sanitised name>`); `content_type`; `size_bytes`; `is_remittance` (0/1); `payer_name`; `payment_date`;
`payment_reference`; `currency` (default `AUD`); `total_amount` REAL; `extraction_confidence` REAL; `reconciles` (0/1/NULL);
`extraction_status` (`pending`, `extracted`, `failed`, `skipped`); `extraction_error`; `notes`; `extracted_at`;
`created_date`; `updated_date`. Indexes on `email_record_id` and `extraction_status`.

### `remittance_lines`

`id` PK; `attachment_id` NOT NULL FK `email_attachments.id` ON DELETE CASCADE; `email_record_id` NOT NULL (no FK);
`invoice_number`; `invoice_date`; `gross_amount`, `discount_amount`, `paid_amount` (REAL); `created_date`. Indexes on
`attachment_id` and `invoice_number`.

### `outbound_messages`

`id` PK; `email_record_id` NOT NULL FK ON DELETE CASCADE; `sender_user`; `from_mailbox`; `to_addresses` NOT NULL and
`cc_addresses` (**JSON arrays stored as text**); `subject` NOT NULL; `body` NOT NULL; `threaded` (0/1); `status`
(default `sending`; `sent`, `failed`); `error`; `sent_at`; `created_date`; `updated_date`. Index
`idx_outbound_messages_record (email_record_id, created_date)`. Attachments sent are **not** recorded (no file names).

### `credit_note_requests`

`id` PK; `email_record_id` (no FK); `customer_name` NOT NULL; `customer_email`; `sales_rep_name`; `sales_rep_email` NOT NULL;
`invoice_number`; `credit_amount` REAL (default 0); `reason`; `original_request`; `token` **UNIQUE NOT NULL**;
`token_expires_at` NOT NULL; `status` (default `form_sent`; one of `form_sent`, `awaiting_sales_response`, `submitted`,
`under_review`, `approved`, `rejected`, `completed`); `form_sent_date`; `submitted_date`; `reviewed_at`; `created_date`;
`updated_date`. Indexes: unique on `token`, `idx_credit_note_requests_status`. The token is stored in plain text.

### `scan_runs`

`id` PK; `started_at` NOT NULL; `finished_at`; `mailbox` NOT NULL; `status` NOT NULL (`running`, `completed`, `failed`,
`stopped`); `watermark`; `total_fetched`, `new_records`, `skipped_records`, `failed_enrichments` (INTEGER);
`errors`; `created_date` NOT NULL; `from_date`, `to_date`, `requested_by`, `total_messages`, `processed_messages` (added by
migration for operator scans). Index `idx_scan_runs_mailbox_started (mailbox, started_at)`.

### `option_lists`

PK (`list_name`, `value`); `label` NOT NULL; `description` (the criterion given to the classifier); `color`;
`sort_order`; `active` (0/1); `is_system` (0/1); `created_date`; `updated_date`. `list_name` is `category` or `method`.
Defaults are in `option_defaults.py` (12 categories: remittance advice, credit application, release customer orders, credit limits or terms,
invoice or statement requests, credit notes, overdue collections, compliance questionnaire, internal sales query, marketing,
general inquiry, other; and two methods, phone and email).

### `deleted_messages`

PK `source_message_id`; `deleted_date`. A tombstone so a later scan does not bring a permanently deleted message back.

### Notes for Azure SQL

- **JSON in text:** `outbound_messages.to_addresses`, `cc_addresses`.
- **Long text:** `email_records.body` (up to 20,000 characters), `ai_summary`, `proposed_response`, `final_outcome`; `subject`,
  `customer_name` and others are unbounded in SQLite and need a length decision. **Sorting and searching on long text:**
  the register sorts by up to 14 columns including `subject`, `final_outcome` and `customer_name`, and searches by
  `LIKE '%x%'` over `subject`, `customer_name`, `sender_email`, `sender_name`, `body`, `ai_summary`. On `NVARCHAR(MAX)` that is
  both the `ORDER BY` hazard in plan §13 and a full scan; Phase 5 needs bounded sort columns and a search plan.
- **Timestamps without a zone:** none in the strict sense. `email_date` is a UTC instant in `Z` form, the other
  timestamps carry `+00:00`; `due_date` is a date string. Mixed formats are compared as text in several places
  (`email_date` ranges, `substr(due_date, 1, 10)`, `watermark` as `receivedDateTime gt`), so moving to `DATETIME2` means
  fixing one parsing rule at the boundary.
- **Money:** `remittance_lines.*_amount`, `email_attachments.total_amount`, `credit_note_requests.credit_amount` are float.
- **Writes during reads:** the overdue UPDATE would run on every list call; on Azure SQL serverless that adds a write to a
  read path (§10).
- **Unique id of record:** `source_message_id` UNIQUE is a Graph id that is **not immutable** (§7).
- **Counts:** a local copy of the data (22 Sep) has 45 attachment folders; row counts were not queried (no data was read).

## 4. Files

Attachments are written to `server/data/attachments/<email record id>/<sanitised name>` (`AR_DATA_DIR`), recorded in
`email_attachments.stored_name`, and served by route 9 to any caller with `ar.records.read`. Only PDFs and images
(`.pdf .png .jpg .jpeg .tif .tiff`; images at least 25,600 bytes) that are real file attachments (not inline) are kept. The name is the
sender's, reduced to `[A-Za-z0-9._-]`, cut to 60 characters, **with no unique prefix**: two attachments in one message
that sanitise to the same name overwrite each other on disk while both rows survive, so both point to one file (§10).
Permanent deletion removes the record's files; there is no other cleanup. Sent mail carries attachments inline (3 MB cap),
never stored. Nightly backup of the SQLite file and the attachments folder to S3 by `deploy/backup.sh`
(`AR_BACKUP_BUCKET`, root cron).

## 5. Roles and access

Identity and roles are the portal's (application key `peridot-smart-ledger-flow`). There is no local user table and no
role mapping in the app: the portal's permission list decides.

| Portal role | Label | Permissions |
|---|---|---|
| `reviewer` | Reviewer | `ar.records.read` |
| `operator` | Operator | `ar.records.read`, `ar.records.write` |
| `admin` | Administrator | `ar.records.read`, `ar.records.write`, `ar.admin` |

Enforcement: route by route (§2). `ar.admin` satisfies any permission; Settings changes need `ar.admin`; the Category and Method
lists are readable by anyone signed in. `ar.records.read` and `.write` are the only gate on mail, scanning, bin and
credit note routes: a reviewer cannot write, an operator can send mail from any mailbox the portal grants this app.
`SERVICE_SECRET` bypasses all of it on every `require_permission` route.

In the browser: `Settings.jsx` reads `/api/me` and shows the lists read-only unless the permission list contains `ar.admin`;
`AppLayout` loads `/api/me` only for display; **no page redirects an unauthenticated visitor to the portal**:
`navigateToLogin` and `redirectToLogin` exist but nothing calls them, and `logout` simply goes to `/`. A visitor without a
session sees the shell with every call failing.

Assignees come from the portal (`list_members_with_permission(..., "ar.records.write")`), so "assigned person" is a name or
email chosen from that list. Mailboxes come from the portal's Mail Service grants (`read` to scan, `send` to reply); the
portal's `MAIL_PERMISSIONS` has **no entry for this app**, which is harmless while the app never passes an actor (it does
not).

## 6. Background work

| Job | Trigger | What it does |
|---|---|---|
| Daily scan and reminders | systemd timer `peridot-smart-ledger-flow-scan.timer`, 08:00 `Australia/Melbourne`, `Persistent=true`, running the oneshot service `python -m server.scan` as user `triar` | After the portal's read grant check: lists Inbox messages newer than the watermark (oldest first; `AR_MAX_SCAN_MESSAGES`, 0 means no cap), ingests each (§2 behaviour), advances the watermark over an unbroken run. Then, after a send grant check, emails a reminder for every `overdue` record with no `reminder_sent_at` to `assigned_person` if it contains `@`, else to the mailbox itself, and sets `reminder_sent_at` |
| Operator scan | the register's "Scan Mailbox" button, route 29 in three modes | per message, with progress |
| Start-up | `store.init_db()` at import | additive migrations, option seeding, `email_date` rewrite |
| Nightly backup | root cron, `deploy/backup.sh` | SQLite plus attachments to S3. Retired with the move |

`SERVICE_SECRET` is also accepted by every API route, so the timer could have used HTTP; it does not (it imports the app).
Reminder mail is built with unescaped values (`customer_name`, `subject` from the customer's email are interpolated into
the HTML of the reminder), unlike the other outbound mail, which is escaped (§10).

## 7. External calls

- **TriCab Finance portal** (`FINANCE_PLATFORM_URL`, loopback): code exchange, per-request session check, member list, and
  the Mail Service mailbox grants (`list_mailboxes`).
- **Microsoft Graph, app-only**, the app's own `GraphClient` (client credentials from `GRAPH_TENANT_ID`, `GRAPH_CLIENT_ID`,
  `GRAPH_CLIENT_SECRET`), mailbox `AR_MAILBOX` (default `ar@tricab.com`), plus the From mailboxes the portal grants for send:
  - read: `GET /users/<mbx>/mailFolders/inbox/messages` (`$select` id, subject, from, receivedDateTime, body or not,
    hasAttachments; `$filter` on `receivedDateTime`; follows `@odata.nextLink`), `GET /users/<mbx>/messages/<id>`,
    `GET .../messages/<id>/attachments` (inline base64).
  - send: `POST /users/<mbx>/sendMail` with `saveToSentItems: true`; for replies `POST .../messages/<id>/createReply`, a patch of
    body, cc, subject and attachments, then send.
  - **Message id form: mutable.** No request sends a `Prefer: IdType="ImmutableId"` header and nothing in `server/` or `src/`
    mentions immutable ids. The stored `source_message_id` is therefore the per-folder id; the code itself notes that "an id goes
    stale the moment someone files or deletes the mail", and falls back to a standalone send when `createReply` fails.
    **Answer to plan §13: no, Tri-AR does not store Graph immutable ids** (Tri-AP converted on 2 Oct and its client sets an
    `immutable_ids` flag). Consequences: a message moved out of the Inbox cannot be threaded or re-fetched for the body viewer
    (`/body` returns a 502 with the Graph error), and a message that Graph re-ids after a move could be ingested a second
    time (the dedupe is `UNIQUE (source_message_id)`).
- **OpenRouter**: `typesafe/jev-1.13` through the Alpha Decisions API `POST /api/alpha/decisions` (`AR_JEV_MODEL`;
  category, response method, `needs_review` noul), `google/gemini-2.5-flash` through chat completions (`AR_LLM_MODEL`; `AR_DOCUMENT_MODEL`
  for remittance documents, PDFs sent natively as `file` parts and images as `image_url`). Five purposes: classification, prose
  (customer, summary, draft), reply drafting from an instruction, remittance extraction. **On failure:** a classification or prose
  failure is logged at ERROR with the message id and the record goes to `needs_review`; a draft failure answers 502; an
  extraction failure is stored as `extraction_status = failed` with the error, and logged. (Unlike plan §4.5's general
  statement, failures here do reach the logs.) The prompts are fixed on the server. Model replies are parsed with a brace-search and a
  trailing-comma repair.
- **Odoo, Document Intelligence:** none.

## 8. Settings

Names only (`.env.example` plus every `os.environ` read in `server/`; on the host `/etc/peridot-smart-ledger-flow.env`):

| Setting | Controls |
|---|---|
| `SECRET_KEY`, `SESSION_COOKIE_SECURE` | session signing and cookie flag (random per process if unset) |
| `FINANCE_PLATFORM_URL`, `FINANCE_PLATFORM_SHARED_ROOT`, `FINANCE_PLATFORM_APP_KEY`, `FINANCE_PLATFORM_CLIENT_SECRET` | portal SSO, assignees, mailbox grants |
| `FINANCE_PORTAL_PUBLIC_URL`, `TRI_CREDIT_NOTES_URL` | browser links (home button; Linked Systems) |
| `SERVICE_SECRET` | accepted on every protected route |
| `AR_ALLOW_ANONYMOUS` | local development only: run without SSO as an anonymous user |
| `OPENROUTER_API_KEY`, `OPENROUTER_APP_NAME`, `AR_LLM_MODEL`, `AR_JEV_MODEL`, `AR_DOCUMENT_MODEL`, `AR_LLM_TIMEOUT_SECONDS` | AI models and key. `AR_LLM_TIMEOUT_SECONDS` is in the example file and **not read by any code** |
| `AR_CONFIDENCE_THRESHOLD`, `AR_REVIEW_THRESHOLD` | routing to needs-review (0.80 and 0.50). `AR_REVIEW_THRESHOLD` is read but missing from `.env.example` |
| `GRAPH_TENANT_ID`, `GRAPH_CLIENT_ID`, `GRAPH_CLIENT_SECRET`, `AR_MAILBOX` | app-only Graph and the mailbox |
| `APP_BASE_URL` | origin used in credit note form links; **defaults to `http://127.0.0.1:8050`** if unset |
| `APP_TIMEZONE` | local days for filters and overdue; default `Australia/Melbourne` (but `store.py` hard-codes `Australia/Melbourne` for overdue and tiles) |
| `AR_MAX_SCAN_MESSAGES` | cap per scan, 0 for none |
| `CREDIT_NOTE_TOKEN_HOURS` | form link validity, default 72 |
| `AR_MIN_ATTACHMENT_BYTES`, `AR_EXTRACT_ALL_ATTACHMENTS` | attachment filters |
| `AR_DATA_DIR`, `PORT`, `AR_SKIP_DOTENV` | data folder, dev port, tests |
| `AR_BACKUP_BUCKET` | `backup.sh` only |

The app loads `.env` with `override=True`, so a local `.env` beats the process environment.

## 9. Screens

React 18.2, React Router 6 `BrowserRouter`, shadcn/ui, TanStack Query configured, one API file `src/api/base44Client.js`
(adapter keeping the Base44 SDK shape, plus `replyEmail`, `settings`, `dashboard`, `assignees`). Pages load with `useEffect`.
Route numbers refer to §2. The layout (`AppLayout.jsx`) is a 256 px sidebar: the portal brand link, "Tri-AR", then AR Register,
Credit Notes, Bin (with a count that refreshes on each navigation), a Linked Systems link to Tri-Credit Notes when configured,
and Settings.

| Path | Component | API calls | States and dialogs |
|---|---|---|---|
| `/` | `Home.jsx` | 6 (1,000 rows), 31, 17, 10, 29, 11 (the layout also loads 12 and 6 with `?deleted=1`) | Summary tiles (Total, Awaiting Review, In Progress, Overdue) that follow the chosen period; date presets and a custom range (today, this week, last month, this month, this year and others in `dateRanges.js`); column filters (`FilterHeader`) with pill editors (`PillPicker`, `EditableCell`) for category, method, status and assignee; search; sortable register; row selection with "Mark completed" and "Move to Bin" (confirmation dialog); Export CSV and Export Excel (client side); "Scan Mailbox" opens the period dialog then the progress modal (list, process one by one, pause, stop, "already scanned" warning listing prior runs). Empty: "No email records match the current filters". The scan reports "No emails found in the selected period" or "Scan stopped" |
| `/email/:id` | `EmailDetail.jsx` | 7, 8, 9, 10, 17, 18, 20, 23 | the record: subject, sender, category, status, due date, outcome; AI summary; the original message in a sandboxed frame (`EmailBodyFrame`); attachments with the extracted payment header and invoice lines (extraction status, "reconciles" flag); the reply composer dialog (`ReplyComposer`: From mailbox, To, Cc, subject, body, AI draft box, attachments, history of sent mail); "Send credit note form" dialog (needs the sales rep's email); move to Bin, restore. Toasts for failures |
| `/credit-notes` | `CreditNotes.jsx` | 24 (via `CreditNoteRequest.list`) | read-only. Status filter chips with counts; table of requests with a link to each request's form URL, **built in the browser from the `token` the list returns**; a link to Tri-Credit Notes that is still hard-coded to `http://127.0.0.1:8030` on this page (the sidebar link was fixed, this one was not); empty "No credit note requests in this view". Nothing in the UI changes a request's status, so `approved`, `rejected`, `under_review` and `completed` are reachable only by calling `PATCH /api/credit-notes/<id>` directly |
| `/credit-note-form` | `CreditNoteForm.jsx` | 27, 28 (**public**, no sign-in) | loading; invalid or expired link; form (invoice number, amount, reason, rep name); submitted. The page reads `e.response?.data?.error` (an axios shape) but the client throws an `Error` with `err.data`, so the server's specific messages (expired, already submitted) are never shown: the page always shows its generic text |
| `/bin` | `Bin.jsx` wrapping `RecordBin.jsx` | 6 with `?deleted=1`, 18, 17 with `?permanent=1` | list of binned records; restore; "delete permanently" alert; empty state |
| `/settings` | `Settings.jsx` | 12, 13, 14, 15, 16, 5 | Category and Method lists: add, edit label, description (the classifier's criterion) and colour, reorder, archive, restore; read-only unless `ar.admin`; archived options keep their records |
| `*` | `PageNotFound` | none | 404 |

Two client wrappers have no server route: `EmailRecord.create` posts to `/api/email-records`, which has no POST handler (the app never calls it).

## 10. Open questions

1. **Graph ids are not immutable** (§7): plan §13 answered. Tri-AP's 2 Oct conversion script and approach are the model; for Tri-AR the live
   `source_message_id` values would have to be converted, or the port starts with a new key and a one-off reconciliation. This is the main
   Phase 5 risk for the mailbox work.
2. **The credit note token is exposed to every reader, by design of the UI.** `GET /api/credit-notes` and `/api/credit-notes/<id>` return `token` to any caller with
   `ar.records.read`, and the Credit Notes page uses it to render each form link; anyone with read access can build a working form URL for any open request; `PATCH` lets a writer overwrite `token` and
   `token_expires_at`. Tokens are stored in plain text. The plan's design (single use, expiring, bound to one request) needs the token
   hashed and never returned after creation.
3. **The "single use" rule is the status.** Reuse is blocked only once `status` is submitted or later; a token stays valid for reads until
   expiry. Nothing records who opened it, and no mail tells AR that a form was submitted. The public form is also reachable only from
   the office address today (WAF, plan §5.5), which sales reps outside the office would hit; confirm.
4. **Credit note mail** skips the portal's send-grant check (route 23), swallows send failures, and uses `APP_BASE_URL`, which defaults to
   `http://127.0.0.1:8050`, so a missing setting produces unusable links silently.
5. **Reads write** (§2): the overdue UPDATE on every read. The port should do it in a job or compute `overdue` on read.
6. **`SERVICE_SECRET` works on every protected route**, not only scan; and `ar.admin` stands in for every permission.
7. **No authorisation per record.** Anyone with `ar.records.read` reads every message body, attachment (customer payment documents) and
   token; the plan's permission model (one per route) fits, but the sensitivity of `/attachments/.../file` may deserve its own permission.
8. **Attachment name collisions** overwrite files silently (§4); the port should key by an attachment id.
9. **Reminder email is not HTML-escaped** (`scan.dispatch_overdue_reminders` interpolates customer-supplied `subject` and `customer_name`
   into HTML), unlike the escaped credit note and reply mail. It is sent internally, but it is an injection into a message sent from
   `ar@`.
10. **No unauthenticated-visitor handling** in the browser (§5); the `/api/*` catch-all returns the SPA for unknown GETs.
11. **Search and sort design** for Azure SQL (§3): unbounded text, `LIKE '%x%'` over the body, 14 sortable columns, no index on `deleted_at`.
12. **Inbox-only scanning.** The listing reads one folder (`inbox`); mail the mailbox rules file elsewhere is never seen. Also, a mailbox
    scan always uses `AR_MAILBOX`; the `mailbox` column and the "other mailboxes" work from 23 Sep apply only to sending.
13. **Several settings do not match the code:** `AR_LLM_TIMEOUT_SECONDS` unused; `AR_REVIEW_THRESHOLD` undocumented; `APP_TIMEZONE`
    is read in `app.py` but `store.py` hard-codes Melbourne.
14. **Remittance matching stops at extraction:** the lines are shown, not matched to invoices in Odoo or any ledger. The plan lists "remittance"
    under Phase 5; confirm what is wanted beyond display and the `reconciles` flag.
15. **Tests:** `server/tests/test_backend.py` has 65 `def test_` functions in one 1,922-line file (the HANDBACK says 8 and is stale). Not run
    (P0.4 installs nothing). `.pytest_cache` is in the tree with a `lastfailed` file; its contents were not read.
16. **Differences from the plan:** §4.1 says 5.5k server lines (4.4k excluding the test file). `HANDBACK.md` is dated 20 Sep and describes an
    earlier state (8 tests, no Bin or Settings); this inventory follows the code, not the handback. The plan's §4.5 note that Tri-AR's id
    form "was not checked" is now answered.
