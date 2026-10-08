# Inventory: Tri-AP

Part of Phase 0, task P0.4 ([FINANCE-PHASE-0.md](../../../../FINANCE-PHASE-0.md)).
Business name Tri-AP (Accounts Payable); the code and portal call it Invoice Flow; portal application key
`invoice-flow`; port 8010. It scans the `ap@tricab.com` mailbox, classifies attachments, extracts invoice fields with a
vision model, matches the vendor to Odoo, drafts and sends vendor email, and runs a prepare-and-approve step.

## 1. Source

| | |
|---|---|
| Directory | `D:\TriCab\finance\_synced-20260918\invoice-flow` |
| Branch, commit | `deploy-button-only` `369fa15` (6 Oct 2026, "Tri-AP: entity tabs read the short company name and hide empty entities") |
| Uncommitted files | 1, as the plan says: `invoice-flow.tar.gz.sha256`, an untracked checksum of a deployment archive. It is not source and was not opened beyond its name |
| Date read | 6 Oct 2026 |
| Not read | the top-level August snapshot `D:\TriCab\finance\invoice-flow`; the unreleased `main` except `server/models.py` for the prefixed table names (`git show main:server/models.py`; nothing checked out) |
| Remote | `origin/main` exists |

Line endings: the plan says `invoice-flow` has mixed CRLF and LF; not examined here.

**The working directory holds live data, not reproduced here:** `.env` (real settings, **not opened**) and
`server/data/invoices.sqlite3` (and uploads), both gitignored. No query was run against the SQLite file and no figure
below comes from it.

Files read: `server/{app,store,extraction,vendor_match,graph_client,migrate_immutable_ids}.py` (the last two for their
interfaces and headers, the first four in full), `.env.example`, `deploy/invoice-flow.service`, `deploy/backup.sh`,
`FEATURE_TODO.md`, `mail-workflow/README.md`, the test file names, and in `src/`: `App.jsx`, `api/base44Client.js`,
`components/layout/Sidebar.jsx`, and the pages and components for API calls and states. `Mailbox.jsx` (669 lines),
`InvoiceDetail.jsx` (497), `UserManagement.jsx` (587) and `Settings.jsx` (433) were read for calls and dialog titles,
not line by line. For roles only: `_work-portal-entra-20261005/server/app.py` (`d333f12`), `ROLE_SEED`.

Size: `server/*.py` 5,198 lines (app 2,714, store 995, extraction 459, graph 443, migrate 338, vendor_match 249); plan §4.1
says 6.5k, which presumably includes the tests (113 tests). 63 route decorators.

## 2. Routes

63 route decorators in `server/app.py`, equal to the plan's 63. Two are the SPA catch-all. The 63 include 6 for sign-in,
18 reference-list routes (companies, cost centres, account categories, mailboxes, vendors) and 5 user-admin routes.

"Who" column (the session is the same Flask cookie as the other apps: `platform_uid` and `platform_session_version`,
checked by a portal `introspect` call once per request and cached in `g`; once the portal is configured a local cookie is
ignored):

- **login**: `login_required`: any signed-in user. **No permission check follows.** In particular `invoice.invoices.read`,
  `invoice.invoices.write`, `invoice.mail.read`, `invoice.mail.send` and `invoice.settings.manage` are seeded in the portal
  but **never checked by this server**.
- **admin**: `admin_required`: with a portal session, `invoice.users.manage`; local session, `role == "admin"`.
- **preparer** and **approver**: `preparer_required` (`invoice.approvals.prepare`) and `approver_required`
  (`invoice.approvals.decide`).
- **scope**: a row rule, `_mailbox_scope` (`app.py:345`). A user whose portal roles all carry a mailbox scope sees only
  invoices and emails of those mailboxes plus invoices with no mailbox (hand captures); a user with any unscoped role,
  and every admin, sees everything. Out of scope reads answer 404.

| # | Method, path | Handler (`server/app.py`) | Who | Reads / writes | Side effects |
|---|---|---|---|---|---|
| 1 | POST `/api/auth/login` | `auth_login` :544 | none | local `users` | sets a session; **403 whenever the portal is configured** (development only) |
| 2 | POST `/api/auth/logout` | `auth_logout` :563 | none | clears the session | none |
| 3 | GET `/api/config` | `app_config` :569 | none | env `FINANCE_PORTAL_PUBLIC_URL` | none |
| 4 | GET `/api/auth/me` | `auth_me` :579 | session | the user with derived `role` (`admin` if `invoice.users.manage`, else `user`), `finance_role` (`approver`, `preparer`, or `operator`), `allowed_mailboxes`, `permissions` | portal introspect |
| 5 | GET `/api/auth/platform/callback` | `platform_callback` :587 | none | writes the session cookie | portal `exchange(code)`; redirect to `/` |
| 6 | POST `/api/auth/change-password` | `auth_change_password` :611 | login | local `users` | 409 under the portal |
| 7 | GET `/api/invoices` | `invoices_list` :646 | login; scope | `invoices` (live, or the Bin with `?deleted=1`), `?status=`, `?id=`, `?fromDate=&toDate=` (local-day window, widened by a day each side and filtered exactly in the browser), `?sort=`, `?limit=` (default 200, max 1,000); adds the sender name from `emails` | **runs `refresh_overdue_statuses`, an UPDATE, inside this read** |
| 8 | GET `/api/invoices/<id>` | `invoices_get` :679 | login; scope | one invoice | the overdue UPDATE |
| 9 | POST `/api/invoices` | `invoices_create` :790 | login | inserts an invoice (manual capture); dates normalised to `yyyy-MM-dd` (400 if unreadable); graded for confidence; Odoo vendor matched automatically | portal reference call for the vendor list |
| 10 | PATCH `/api/invoices/<id>` | `invoices_update` :812 | login; scope | updates whitelisted columns; `status` and `duplicate_status` validated; vendor fields cannot be set here; editing `vendor`, `vendor_email`, `tax_id` or `company` re-matches the vendor unless a person decided; marking "Cleared by user" writes an audit event | portal reference call |
| 11 | DELETE `/api/invoices/<id>` | `invoices_delete` :847 | login; scope | soft delete (`deleted_at`, `deleted_by`, audit event); `?permanent=1` on a binned invoice deletes the row, records a `scan_skips` tombstone, deletes the PDF, audits | file removal |
| 12 | POST `/api/invoices/<id>/restore` | `invoices_restore` :886 | login; scope | clears `deleted_at`; audit | none |
| 13 | GET `/api/companies` | `companies_list` :899 | login | `companies` | none |
| 14 | POST `/api/companies` | `companies_create` :906 | admin | inserts; name required | none |
| 15 | PATCH `/api/companies/<id>` | `companies_update` :918 | admin | updates (whitelisted columns) | none |
| 16 | DELETE `/api/companies/<id>` | `companies_delete` :927 | admin | hard delete (invoices keep the text name) | none |
| 17 | GET `/api/cost-centres` | `cost_centres_list` :940 | login | `cost_centres` | none |
| 18 | POST `/api/cost-centres` | `cost_centres_create` :947 | admin | inserts; code required | none |
| 19 | PATCH `/api/cost-centres/<id>` | `cost_centres_update` :960 | admin | updates | none |
| 20 | DELETE `/api/cost-centres/<id>` | `cost_centres_delete` :969 | admin | hard delete | none |
| 21 | GET `/api/account-categories` | `account_categories_list` :976 | login | `account_categories` | none |
| 22 | POST `/api/account-categories` | `account_categories_create` :983 | admin | inserts; code required | none |
| 23 | PATCH `/api/account-categories/<id>` | `account_categories_update` :995 | admin | updates | none |
| 24 | DELETE `/api/account-categories/<id>` | `account_categories_delete` :1004 | admin | hard delete | none |
| 25 | GET `/api/vendors` | `vendors_list` :1019 | login | the Odoo vendor list (about 1,900 vendors per the code comments), each with the count of live invoices matched to it; 503 if the portal's reference service does not answer | portal reference call `get_list("vendors")` |
| 26 | GET `/api/invoices/<id>/vendor-suggestions` | `invoice_vendor_suggestions` :1032 | login; scope | up to 5 suggestions scored by name, email domain, tax id; `buyer_like` flag when the "vendor" looks like a TriCab entity | portal reference call |
| 27 | PUT `/api/invoices/<id>/vendor-match` | `invoice_vendor_match` :1049 | login; scope | body `odoo_vendor_id` (confirm), or `status: "new"` (needs creating in Odoo), or `status: "auto"` (re-run); records who chose; audit | portal reference call |
| 28 | POST `/api/vendor-matching/run` | `vendor_matching_run` :1097 | login | matches every live invoice not yet decided (never tried or unmatched), optional `invoice_ids`; returns `{checked, matched, unmatched}` | portal reference call; writes many rows |
| 29 | GET `/api/mailboxes` | `mailboxes_list` :1127 | login | with the portal: the app's mailboxes as granted in the Mail Service (`can_read`, `can_send`); local development: the `mailboxes` table | portal `list_mailboxes` |
| 30 | POST `/api/mailboxes` | `mailboxes_create` :1141 | admin | local development only, and only `AP_MAILBOX` | 409 under the portal |
| 31 | PATCH `/api/mailboxes/<id>` | `mailboxes_update` :1154 | admin | as 30 | 409 under the portal |
| 32 | DELETE `/api/mailboxes/<id>` | `mailboxes_delete` :1165 | admin | as 30 | 409 under the portal |
| 33 | GET `/api/emails` | `emails_list` :1175 | login; scope | `emails` in a UTC window from local dates; `?classification=Invoice\|Other`, `?deleted=1`, `limit` default 500 max 1,000, `offset`; adds `invoice_ids` and `duplicate_invoice_ids` per email (**two queries per row**) | none |
| 34 | GET `/api/emails/stats` | `emails_stats` :1196 | login; scope | counts: total, invoice, other, errors | none |
| 35 | GET `/api/emails/<id>/body` | `emails_body` :1206 | login; scope | none | **Graph** `GET /users/<mbx>/messages/<id>` (body, recipients); returns untrusted HTML for an inert viewer. Only `bodyPreview` is stored |
| 36 | GET `/api/emails/<id>/attachments` | `emails_attachments` :1250 | login; scope | none | **Graph** attachment list live (the scan keeps only invoice files); inline images omitted |
| 37 | GET `/api/emails/<id>/attachments/<aid>/file` | `emails_attachment_file` :1287 | login; scope | none | **Graph** attachment bytes; served with `nosniff`; only PDF, common images and plain text render inline; others download; non-PDF inline gets a CSP sandbox |
| 38 | PATCH `/api/emails/<id>` | `emails_update` :1324 | login; scope | sets `classification` (Invoice or Other), reason, `review_status = Verified`; audit | none |
| 39 | POST `/api/emails/bulk` | `emails_bulk` :1355 | login; scope per row | actions `classify`, `bin`, `restore` on up to 1,000 ids; counts skipped | audit per email |
| 40 | GET `/api/users` | `users_list` :1414 | admin | the portal's member list for this app (`list_users`) mapped by `_platform_member`; local `users` table when no portal | portal `list_users` |
| 41 | POST `/api/users/invite` | `users_invite` :1432 | admin | local only; returns a temporary password | 409 under the portal |
| 42 | POST `/api/users/<id>/reset-password` | `users_reset_password` :1463 | admin | local only | 409 under the portal |
| 43 | DELETE `/api/users/<id>` | `users_delete` :1481 | admin | local only | 409 under the portal |
| 44 | PATCH `/api/users/<id>` | `users_update` :1497 | admin | local only (role, name, title, `finance_role`, active, password, `allowed_mailboxes`) | 409 under the portal |
| 45 | POST `/api/upload` | `upload_file` :1543 | login | writes `server/data/uploads/<12 hex>-<secure name>`; extensions pdf, png, jpg, jpeg, tif, tiff; request cap 32 MB | file written |
| 46 | GET `/api/files/<name>` | `serve_file` :1555 | login | reads an uploaded file. **No mailbox-scope check**: any signed-in user who has the stored name can read it | none |
| 47 | POST `/api/extract` | `extract` :1569 | login | none | **OpenRouter** extraction of one uploaded invoice (manual capture); the caller may pass its own prompt |
| 48 | POST `/api/functions/scanMailbox` | `scan_mailbox` :1649 | login; scope | modes `list`, `process`, `complete`: writes `scan_runs`, `emails`, `email_attachments`, `invoices`, `scan_skips` (§2 notes) | **Graph** reads of each mailbox the portal grants read on; per attachment **OpenRouter** classify-and-extract; per email one **OpenRouter** reply suggestion (`AP_SUGGEST_REPLIES`); files written |
| 49 | GET `/api/send-mailboxes` | `send_mailboxes` :2081 | login | none | portal `list_mailboxes` (`can_send`), `AP_MAILBOX` first |
| 50 | POST `/api/emails/<id>/email-draft` | `email_reply_draft` :2125 | login; scope | none | **OpenRouter** reply draft or revision from an instruction |
| 51 | POST `/api/emails/<id>/send-reply` | `email_send_reply` :2142 | login; scope | `outbound_messages` (`Sending`, then `Sent` or `Failed`); audit | **Graph** threaded reply (`createReply`) if the original message is still there and the From mailbox is the one that holds it, else a standalone send; portal send-grant check on the From mailbox; inline attachments up to 3 MB |
| 52 | GET `/api/emails/<id>/outbound-messages` | `email_outbound_messages` :2231 | login; scope | `outbound_messages` (50 newest) | none |
| 53 | POST `/api/invoices/<id>/email-draft` | `invoice_email_draft` :2240 | login; scope | none | **OpenRouter** vendor email draft |
| 54 | POST `/api/invoices/<id>/send-email` | `invoice_send_email` :2260 | login; scope | `outbound_messages`; audit | **Graph** `sendMail` to the vendor, optionally with the invoice PDF and up to 3 MB of extra files; portal send-grant check |
| 55 | GET `/api/invoices/<id>/outbound-messages` | `invoice_outbound_messages` :2338 | login; scope | `outbound_messages` (100 newest) | none |
| 56 | GET `/api/approvers` | `approvers_list` :2433 | login | portal members holding `invoice.approvals.decide` (local `users` with `finance_role = approver` when no portal) | portal `list_members_with_permission` |
| 57 | GET `/api/signing-authority` | `signing_authority_list` :2453 | login | `signing_limits` (active, with an email): a suggestion list for the approval dialog, not a permission check | none |
| 58 | GET `/api/approval-batches` | `approval_batches_list` :2492 | login; scope | up to 200 batches with their invoices, decisions, preparer and decider | none |
| 59 | GET `/api/approval-batches/<id>` | `approval_batches_get` :2503 | login; scope (404 if any invoice is out of scope) | one batch | none |
| 60 | POST `/api/approval-batches` | `approval_batches_create` :2518 | preparer; scope | inserts `approval_batches` and `approval_items`, sets `approval_status = Pending approval` on the invoices, `outbound_messages`, audit | **Graph** `sendMail` from `AP_MAILBOX` to the chosen recipients (default every Approver; up to 20; editable), with a link to `/approvals/<id>`; a send failure is returned as a warning, the batch stands |
| 61 | POST `/api/approval-batches/<id>/decision` | `approval_batches_decide` :2665 | approver | one decision wins: `UPDATE ... WHERE status = 'Pending'`; inserts `approval_decisions`; sets the invoices' `approval_status`; audit | **Graph** `sendMail` to the preparer with the outcome |
| 62 | GET `/` | `spa` :2695 | none | `dist/index.html` | none |
| 63 | GET `/<path>` | `spa` :2696 | none | `dist/<path>` or `index.html`; `api/...` answers 404 JSON | none |

Reconciliation: 63 found, 63 in plan §4.1. Equal.

Behaviour that matters for the port:

- **Scan** (route 48), run by a person from the AP Mailbox page, never by a timer: `list` returns the messages in a period (Melbourne
  or Sydney local days, converted to UTC; see §10) for every mailbox the portal grants `can_read` (409 `already_scanned` unless
  `confirmRescan`); then the browser calls `process` once per message. For each message: stores the email (body preview only),
  and for each real file attachment (pdf, png, jpg, jpeg, tiff; images must be at least 25,600 bytes and not inline; PDFs are exempt
  from both rules), skips ones already captured or tombstoned, hashes the bytes (SHA-256) and treats an identical file already in
  the register as an exact duplicate (no new invoice), otherwise stores the file, asks the model to classify and extract in one call,
  and either creates an invoice or discards the file. A possible business duplicate (same vendor, invoice number, currency and total
  within 0.01) is flagged `Potential` but still captured. `force` (set by "Capture invoices" on ticked emails) captures a file the
  model called "not an invoice", flagged for review. An email in the Bin is left alone on a re-scan.
- **Grading** (`_grade_extraction`): an invoice is `Needs Review` if the invoice number, vendor or total is missing, the model's
  confidence is below `INVOICE_CONFIDENCE_THRESHOLD` (0.80), or net plus tax differs from the total by more than 0.02; otherwise `In
  Progress`. Statuses: `Needs Review`, `In Progress`, `Overdue`, `Completed`, `Exported`; `Overdue` cannot be set by hand.
- **Reads write.** `refresh_overdue_statuses` (`store.py:421`) is an UPDATE run on every `get_row` and `list_rows` of `invoices`:
  open invoices past their due date (a Melbourne calendar date; due dates are compared as text) become `Overdue`, and ones whose due
  date moved go back to `In Progress`.
- **Vendor matching** (`vendor_match.py`): automatic only when certain: same tax id (digits), same vendor email, or equal names after
  removing case, punctuation and legal words with exactly one live vendor; "t/as" names match on either side; a TriCab entity name is
  rejected as a vendor. Weaker candidates (similar name above 0.55, shared email domain, excluding mail providers and Xero) are
  suggestions only. Decisions by a person (`confirmed`, `new`) are never overwritten.
- **Approval** is informational: the notes in `FEATURE_TODO.md` say it does not block the D365 export. Any one Approver completes a
  batch; the first decision wins. Export to D365 is a client-side file (`ExportToolbar`, `exportUtils.js`), not a server route.

## 3. Data

SQLite, `server/data/invoices.sqlite3` (`INVOICE_DATA_DIR`), created at import by `store.init_db()`: `CREATE TABLE IF NOT EXISTS`,
`_MIGRATION_COLUMNS` (adds columns), and **one-time data migrations run on every start** (legacy statuses mapped to the current five,
stored invoice and due dates rewritten to `yyyy-MM-dd`, `email_date` backfilled from the email's received time, `content_hash`
backfilled by hashing files, `companies` seeded from invoice text). No Alembic. Raw `sqlite3`, no foreign keys declared.

Live types: `TEXT`, `REAL`, `INTEGER`. Ids `uuid4().hex`. `created_date` and `updated_date`: ISO text `+00:00`. `email_date` and `emails.received_at`:
Graph's UTC instant (`...Z`), compared as text. Dates read from documents (`invoice_date`, `due_date`) are `yyyy-MM-dd` text. Money
(`net_amount`, `tax_amount`, `total_amount`, signing limits) is `REAL`.

Prefixed names from the September rewrite on `main` (`TABLE_PREFIX = "invoice_"`, never deployed; the live tables are unprefixed):

| Live table | Prefixed name on `main` | Differences between live and `main` |
|---|---|---|
| `invoices` | `invoice_invoices` | live has 9 more columns: `extraction_confidence`, `review_reason`, `deleted_at`, `deleted_by`, `odoo_vendor_id`, `vendor_ref`, `odoo_vendor_name`, `vendor_match`, `vendor_match_note` |
| `companies` | `invoice_companies` | same |
| `mailboxes` | `invoice_mailboxes` | same |
| `users` | `invoice_users` | same |
| `emails` | `invoice_emails` | live adds `deleted_at`, `deleted_by`, `proposed_response` |
| `email_attachments` | `invoice_email_attachments` | same |
| `scan_runs` | `invoice_scan_runs` | same |
| `outbound_messages` | `invoice_outbound_messages` | live adds `cc_addresses`, `email_id` |
| `approval_batches`, `approval_items`, `approval_decisions` | `invoice_approval_*` | same |
| `audit_events` | `invoice_audit_events` | same |
| `scan_skips` | `invoice_scan_skips` | same |
| `cost_centres` | **none on `main`** (proposed `invoice_cost_centres`) | table added after the rewrite |
| `account_categories` | **none on `main`** (proposed `invoice_account_categories`) | table added after the rewrite |
| `signing_limits` | **none on `main`** (proposed `invoice_signing_limits`) | table added after the rewrite |

So the `main` definitions are **out of date by at least nine invoice columns and three tables**; plan §3.2 warns about this and it should be
carried into Phase 6.

### `invoices` (the register; 44 data columns plus `id`, `created_date`, `updated_date`)

Source email: `email_id` (the Graph message id), `email_record_id`, `attachment_id` (Graph attachment id), `mailbox`, `email_date`,
`sender`, `subject`, `attachment` (file name), `attachment_url` (`/api/files/<stored name>`), `content_hash` (SHA-256).
Invoice header: `invoice_no`, `vendor`, `vendor_email`, `tax_id`, `invoice_date`, `due_date`, `po_number`, `currency`, `net_amount`,
`tax_amount`, `total_amount`, `company` (a name from `companies`), `account_category` and `cost_centre` (codes from the lists; no foreign keys).
Workflow: `status` (default `Needs Review`), `notes`, `is_revised` (boolean), `duplicate_status` (`Clear`, `Potential`, `Cleared by user`),
`duplicate_of_invoice_id`, `duplicate_reason`, `approval_status` (`Not requested`, `Pending approval`, `Approved`, `Rejected`),
`extraction_confidence` REAL, `review_reason`, `deleted_at`, `deleted_by` (Bin). Odoo: `odoo_vendor_id` INTEGER, `vendor_ref`,
`odoo_vendor_name`, `vendor_match` (null, `matched`, `confirmed`, `unmatched`, `new`), `vendor_match_note`.
Indexes: `idx_invoices_email (email_id, attachment)`, `idx_invoices_hash (content_hash)`. **None on `deleted_at`, `status`, `mailbox`, `due_date` or `vendor`**,
though lists and the overdue UPDATE filter on them.

### Other tables

- `companies`: `id`, `code`, `name` UNIQUE, `sites`, `former_names`, `is_active`, `created_date`. The entities invoices are billed to; sites and former
  names help the model map a bill-to block.
- `cost_centres`: `id`, `code_no`, `code` UNIQUE, `name`, `is_active`, `created_date`. `account_categories`: `id`, `code` UNIQUE, `name`, `is_active`,
  `created_date`. The CFO's reference lists (18 Sep 2026), admin-managed in Settings.
- `signing_limits`: `id`, `authority_role`, `role_code`, `full_name`, `email`, `d365_position` UNIQUE, `po_inventory`, `po_non_inventory`,
  `expense_claim`, `customer_order`, `credit_notes` (REAL; null means no authority, `99999999999` means unlimited), `is_active`, `created_date`. **No
  write route**: filled by `scripts/seed_reference_lists.py` from a workbook.
- `mailboxes`: `id`, `email` UNIQUE, `label`, `is_active`, `created_date`. Used only when the portal is not configured.
- `users`: `id`, `email` UNIQUE, `full_name`, `job_title`, `role`, `finance_role` (default `preparer`), `password_hash`, `allowed_mailboxes` (**JSON array as
  text**), `must_change_password`, `is_active`, `created_date`. **Local identity; empty or stale under the portal** (§5, §10).
- `emails`: `id`, `graph_message_id` NOT NULL, `mailbox` NOT NULL, `received_at`, `sender_name`, `sender_email`, `subject`, `body_preview`, `web_link`,
  `has_attachments`, `classification` (`Invoice`, `Other`; the stats also count `Error`), `classification_reason`, `classification_confidence`, `review_status`
  (`Unverified`, `Verified`, `Needs review`), `proposed_response`, `scan_run_id`, `deleted_at`, `deleted_by`, `created_date`, `updated_date`.
  UNIQUE (`mailbox`, `graph_message_id`); index `idx_emails_period (received_at, classification)`.
- `email_attachments`: `id`, `email_record_id` NOT NULL, `graph_attachment_id`, `name`, `content_type`, `size`, `content_hash`, `stored_url`, `is_invoice`,
  `invoice_id`, `duplicate_of_invoice_id`, `created_date`, `updated_date`. UNIQUE (`email_record_id`, `graph_attachment_id`); index on `content_hash`.
- `scan_runs`: `id`, `requested_by`, `from_date`, `to_date`, `status` (`Listing`, `Running`, `Complete`, `Failed`), `total_messages`, `processed_messages`,
  `invoice_emails`, `other_emails`, `duplicate_invoices`, `errors`, `created_date`, `updated_date`.
- `outbound_messages`: `id`, `invoice_id`, `email_id`, `approval_batch_id`, `sender_user_id`, `from_mailbox`, `to_addresses` and `cc_addresses` (**JSON arrays
  as text**), `subject`, `body`, `attach_invoice`, `graph_message_id`, `status` (`Sending`, `Sent`, `Failed`), `error`, `sent_at`, `created_date`,
  `updated_date`. One table for vendor email, mailbox replies and approval notifications.
- `approval_batches`: `id`, `prepared_by` (a user id), `status` (`Pending`, `Approved`, `Rejected`), `note`, `decided_by`, `decided_at`, `decision_comment`,
  `created_date`, `updated_date`. `approval_items`: `id`, `batch_id`, `invoice_id`, UNIQUE (`batch_id`, `invoice_id`), index on `batch_id`.
  `approval_decisions`: `id`, `batch_id`, `approver_id`, `approver_name`, `approver_email`, `decision`, `comment`, `created_date`.
- `audit_events`: `id`, `actor_user_id`, `action`, `entity_type`, `entity_id`, `details` (**JSON object as text**), `created_date`. Actions seen:
  `duplicate_override`, `invoice_binned`, `invoice_restored`, `invoice_deleted_permanently`, `vendor_match`, `email_reclassified`, `email_binned`,
  `email_restored`, `email_reply_sent`, `vendor_email_sent`, `approval_requested`, `approval_approved`, `approval_rejected`.
- `scan_skips`: PK (`email_id`, `attachment`). Tombstones so a deleted capture is not re-imported.

### Notes for Azure SQL

- **JSON in text columns:** `users.allowed_mailboxes`, `outbound_messages.to_addresses`, `cc_addresses`, `audit_events.details`.
- **Long text:** `outbound_messages.body`, `emails.body_preview`, `proposed_response`, `invoices.notes`, `review_reason`, `audit_events.details`. The register's sort
  allows any whitelisted column (`list_rows`), including these. The known hazard from plan §13 (an `ORDER BY` on an `NVARCHAR(MAX)` column took 25 s on RDS) is a
  design constraint: the port must allow bounded sort columns only.
- **Timestamps without a zone:** the live text carries offsets; mixed forms are compared as text in several places (`email_date`, `received_at` against
  UTC window bounds, `substr(due_date, 1, 10)`); `_invoice_day_window` uses `COALESCE(email_date, created_date, invoice_date)` and a `GLOB` test. The port needs
  one parsing rule at the boundary.
- **Money is float**; `main` fixed it with `Numeric` for invoices.
- **Writes during reads:** the overdue UPDATE on every invoice read (route 7, 8 and internals); on serverless Azure SQL this adds a write to the read path.
- **Per-row queries:** route 33 runs two queries per email row (`email_invoice_ids`) and route 58 builds each batch with several queries; at up to 1,000
  emails per page that is thousands of round trips, which matters with the serverless database's resume delay.
- **User ids** in `approval_batches.prepared_by`, `decided_by`, `audit_events.actor_user_id`, `outbound_messages.sender_user_id`, `scan_runs.requested_by`
  and `approval_decisions.approver_id` are **portal ids** under SSO.
- Plan §4.1 says a 163 MB nightly archive (17 Sep); not rechecked.

## 4. Files

Invoice PDFs and images are written to `server/data/uploads/<12 hex>-<secure name>` and referenced by `invoices.attachment_url` (and
`email_attachments.stored_url`) as `/api/files/<stored name>`; plan §6 says these move to blob storage. Only attachments the model calls invoices are kept; non-invoice
attachments are deleted after classification, and byte-identical files are never stored a second time. Email bodies are not stored (preview only); bodies
and attachments of other mail are fetched live from Graph when a user opens them. Permanent deletion of an invoice removes its file. Manual captures upload
through route 45. Nightly backup of the SQLite file and uploads to S3 by `deploy/backup.sh` (root cron, `INVOICE_BACKUP_BUCKET`).

## 5. Roles and access

Roles are the portal's (application key `invoice-flow`; `ROLE_SEED`):

| Role key | Label | Permissions |
|---|---|---|
| `operator` | Operator | `invoice.invoices.read`, `invoice.invoices.write`, `invoice.mail.read`, `invoice.mail.send` |
| `preparer` | Preparer | `invoice.invoices.read`, `invoice.approvals.prepare` |
| `approver` | Approver | `invoice.invoices.read`, `invoice.approvals.decide` |
| `admin` | Administrator | the above plus `invoice.invoices.delete`, `invoice.users.manage`, `invoice.settings.manage` |

What the server checks: `invoice.users.manage` (routes 14 to 16 and 18 to 24, 30 to 32, 40 to 44), `invoice.approvals.prepare` (60) and `invoice.approvals.decide` (61).
Everything else needs only a sign-in. `_platform_user` derives `finance_role` as `approver`, else `preparer`, else `operator`; and `role` as `admin` if the
user holds `invoice.users.manage`. **Mailbox scoping** is derived from portal role entries with `scope_type == "mailbox"` (`allowed_mailboxes`, lower-cased); an admin or a user with
any unscoped role is unrestricted. Mailbox read and send rights come from the portal's Mail Service per mailbox (`can_read`, `can_send`), not from permissions.

Browser: the sidebar shows Vendor Invoice Register, AP Mailbox, Bin and Vendors to everyone and Settings to `role == "admin"`. `finance_role` gates the "request approval"
controls (preparers) and the decision controls (approvers). The Users page (`/users`) exists as a route but is in no menu (identity is the portal's).

How the app gets roles: `finance_access` (`FinanceAccessClient`): `exchange`, `introspect`, `list_users`, `list_members_with_permission`;
`finance_mail` (`FinanceMailClient`): `list_mailboxes`; `finance_reference` (`FinanceReferenceClient`, from `shared/tricab_reference_client.py`, optional): `get_list`,
`get`, `synced_at`.

## 6. Background work

- **None scheduled.** There is no timer, cron job or scheduler in `deploy/`: scans, vendor matching and exports are all triggered by people. The nightly backup is the only job.
- **At every start** (every Gunicorn worker): `init_db()` runs the schema and the one-time data migrations in §3, which can hash every file on disk whose invoice has no
  hash and rewrite dates, under the store lock.
- **On read:** the overdue UPDATE (§2).
- One-off: `server/migrate_immutable_ids.py` (done 2 Oct 2026): converted stored Graph message and attachment ids to immutable ids by listing each mailbox twice and joining on id or
  received time and subject; `--plan` reads, `--apply` writes in one transaction. It is not part of the port but its logic is the precedent for Tri-AR.

## 7. External calls

- **TriCab Finance portal** (`FINANCE_PLATFORM_URL`, loopback): code exchange, per-request session check, member lists, Mail Service grants and, **new on 6 Oct**, the
  **reference service**: the Odoo vendor list (`finance_reference`), with the portal holding the only Odoo key. If the portal lacks the reference client, matching is off and the Vendors page returns 503.
- **Microsoft Graph, app-only**, the app's own `GraphClient` (`GRAPH_TENANT_ID`, `GRAPH_CLIENT_ID`, `GRAPH_CLIENT_SECRET`), for the mailboxes the portal grants (`ap@tricab.com`; `ar@` is kept out by not
  granting it): `GET /users/<mbx>/messages` with a `receivedDateTime` filter (the whole mailbox, not only the Inbox), `GET .../messages/<id>` and `.../attachments`, `POST .../sendMail`
  (`saveToSentItems`), `createReply` then patch then send. **Message id form: immutable.** Every request sends `Prefer: IdType="ImmutableId"` (`immutable_ids=True` by
  default, `graph_client.py:144`); stored `graph_message_id` and `graph_attachment_id` were converted on 2 Oct 2026. Sends are not idempotent (no idempotency key).
- **OpenRouter** (`extraction.py`), model `google/gemini-2.5-flash` (`INVOICE_LLM_MODEL`), temperature 0, timeout 60 s (`INVOICE_LLM_TIMEOUT_SECONDS`), no `response_format`
  (the reply is parsed with a brace search): classify-and-extract (one call per attachment; the prompt lists the configured entities, their sites and former names so the model chooses
  one), extract-only (manual capture, the caller may supply a prompt), vendor email draft, mailbox reply draft, and an unprompted reply suggestion per scanned email. **On failure** an
  `ExtractionError` becomes a 502 (drafts, `/api/extract`; **not logged**) or, during a scan, a counted error: `app.py` logs it at ERROR with the attachment name and message id (`Failed to process attachment …`),
  the email is marked "Needs review" and the file is deleted; the reply suggestion returns an empty string and logs a warning. `extraction.py` itself never logs a failed call. One shared OpenRouter key with a small monthly cap (plan §4.5).
- **Odoo**: only through the portal (above).
- **Document Intelligence, audio, D365**: none; the D365 export is a file produced in the browser.

## 8. Settings

Names only (`.env.example` plus every `os.environ` read in `server/`; on the host `/etc/invoice-flow.env`):

| Setting | Controls |
|---|---|
| `SECRET_KEY`, `SESSION_COOKIE_SECURE` | session signing and cookie flag |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | local admin seed (no portal only) |
| `FINANCE_PLATFORM_URL`, `FINANCE_PLATFORM_SHARED_ROOT`, `FINANCE_PLATFORM_APP_KEY`, `FINANCE_PLATFORM_CLIENT_SECRET` | portal SSO, roles, mail grants, reference service |
| `FINANCE_PORTAL_PUBLIC_URL` | browser link for the home button |
| `OPENROUTER_API_KEY`, `OPENROUTER_APP_NAME`, `INVOICE_LLM_MODEL`, `INVOICE_LLM_TIMEOUT_SECONDS` | AI |
| `INVOICE_CONFIDENCE_THRESHOLD` | below this an extraction goes to Needs Review (0.80) |
| `AP_SUGGEST_REPLIES` | `0` turns off the per-email reply suggestion (read in code, not in `.env.example`) |
| `GRAPH_TENANT_ID`, `GRAPH_CLIENT_ID`, `GRAPH_CLIENT_SECRET`, `AP_MAILBOX` | app-only Graph and the default mailbox |
| `INVOICE_MAX_SCAN_MESSAGES`, `INVOICE_MIN_ATTACHMENT_BYTES` | scan cap (0 for none), image size floor (25,600) |
| `APP_BASE_URL` | origin for approval links in mail (falls back to the request's own origin) |
| `APP_TIMEZONE` | local days for scan windows; **default `Australia/Sydney`**, while `store.py` hard-codes `Australia/Melbourne` for overdue (§10) |
| `INTERNAL_EMAIL_DOMAINS` | extra domains treated as TriCab, so a forwarded invoice's sender is not stored as the vendor (default: the `AP_MAILBOX` domain; read in code, not in `.env.example`) |
| `INVOICE_DATA_DIR`, `FLASK_DEBUG` | data folder; development |
| `INVOICE_BACKUP_BUCKET` | `backup.sh` only |

Unlike Tri-AR and Tri-Task, `app.py` loads `.env` **without** `override=True` (`load_dotenv(_ROOT / ".env")`), so a machine-wide variable beats the file.
`invoice-flow.service` runs Gunicorn with 2 workers and 4 threads on `127.0.0.1:8010`. `mail-workflow/` is an IT handover pack (Entra, Exchange scope, validation scripts).

## 9. Screens

React 18.2, React Router 6 `BrowserRouter`, shadcn/ui, TanStack Query used for most reads (`useQuery`, with the Bin count shared between sidebar and page), **sonner** for toasts; one
API file `src/api/base44Client.js` (adapter keeping the Base44 SDK shape plus `invoiceBin`, `invoiceEmail`, `mailboxEmail`, `vendors`, `approvals`, `mailbox`). Layout: a 240 px sidebar
with the portal brand link, "Tri-AP", then Vendor Invoice Register, AP Mailbox, Bin (count of binned invoices plus emails), Vendors, and Settings for admins. Route numbers refer to §2.

| Path | Component | API calls | States and dialogs (from the code; not exhaustive) |
|---|---|---|---|
| `/login` | `Login.jsx` | 1 | local form; refused under the portal |
| `/change-password` | `ChangePassword.jsx` (outside the layout) | 6 | form |
| `/` | `Dashboard.jsx` titled "Vendor Invoice Register", hosting `InvoiceRegister.jsx`, `InvoiceTable.jsx` | 7, 10, 11, 12, 28, 47, 45, 9, 48, 49 | tabs and filters by status, entity (companies; "All", "No entity"), mailbox ("All mailboxes", "Uploaded"); search; sortable table with status pills, duplicate badges and the Odoo vendor cell (`VendorMatchCell`); multi-select with bulk status change ("Could not change every status") and **Request approval** (preparers); "Capture / Scan" buttons: scan period dialog, progress modal, manual capture modal (upload, extract, edit, save); export toolbar (client side); "Open Bin" |
| `/invoices` | redirect to `/` keeping `?status=` | none | |
| `/invoices/:id` | `InvoiceDetail.jsx` | 8, 10, 11, 12, 26, 27, 53, 54, 55, 46, 56 | invoice fields editable (company, cost centre and account category dropdowns from the lists), the PDF ("No attachment on this invoice" when none), duplicate review with an audited override, vendor suggestions and confirm, vendor email dialog (AI draft, From mailbox, To, Cc, attach invoice, extra files, history), approval request dialog (preparers), move to Bin |
| `/mailbox` | `Mailbox.jsx` | 33, 34, 35, 36, 37, 38, 39, 48, 50, 51, 52, 49 | AP Mailbox register of every scanned email (Received, Mailbox, Sender, Subject, Type, Checked, Invoices, Response), type counts for the period, scan controls, ticked-row actions (classify, capture invoices, move to Bin), email viewer dialog (sandboxed body, attachments, open a captured invoice), reply dialog (AI draft, From, To, Cc, attachments, history), Bin view |
| `/bin` | `Bin.jsx` | 7 and 33 with `?deleted=1`, 12, 11 with `?permanent=1`, 39 | binned invoices and binned emails; restore; permanent delete |
| `/vendors` | `Vendors.jsx` | 25, 28 | the Odoo vendor list with invoice counts and the time of the last sync; "Match vendors"; 503 text "The Odoo vendor list is unavailable" |
| `/approvals` | `Approvals.jsx` | 58 | approval requests ("AP Approvals") |
| `/approvals/:id` | `ApprovalDetail.jsx` | 59, 61 | the batch, its invoices and decisions; approve or reject with a comment (approvers); success toast "Approval decision recorded and the Preparer was notified" |
| `/settings` | `Settings.jsx` (admin) | 13 to 24, 29 to 32 | Companies (name, code, sites, former names, active), Cost Centres, Account Categories, AP mailboxes (read-only under the portal) |
| `/users` | `UserManagement.jsx` | 40 to 44 | invite, role and finance role selects, active toggle, mailbox restriction, temporary password dialog; reachable only by typing the URL, and read-only under the portal |
| `*` | `PageNotFound` | none | 404 |

## 10. Open questions

1. **Approval records may lose their people under the portal.** `create_approval_batch` stores the preparer's portal user id, but `_approval_payload`, `_notify_preparer_of_approval_outcome` and `decide_approval` look the
   preparer and approver up in the **local `users` table**, which the portal configuration leaves empty (no seeding, no invite). By the code, the preparer shows as null, the outcome email says "the original Preparer has no email address",
   and `approval_decisions.approver_name` and `approver_email` are stored null (the audit event does carry the approver's name and email from the session). Whether the live table was ever filled with mirror rows
   is not visible here: **check the host data before relying on this**.
2. **No permission is checked on invoice, mail or settings routes**: anyone signed in can edit or bin any invoice in their scope, send vendor mail from any mailbox the portal grants the app, scan, and read every uploaded PDF
   by name (route 46 has no scope check). The seeded permissions `invoice.invoices.*`, `invoice.mail.*` and `invoice.settings.manage` do nothing. The platform's one-permission-per-route model needs a mapping decision.
3. **Time zones disagree.** `APP_TIMEZONE` defaults to `Australia/Sydney` and drives scan windows and the register's day window; the overdue rule is hard-coded to `Australia/Melbourne`. The comments in `app.py` refer to "Sydney" and
   in `store.py` to "Melbourne". Pick one for the port (Tri-Task and Tri-AR use Melbourne).
4. **Reads write** (§2), and **N+1 queries** on the email list (§3); both need redesign for the serverless database.
5. **Manual extraction and draft failures are not logged** (only the scan loop logs, §7); the user sees the 502 text and nothing is recorded server-side.
6. **`signing_limits` has no write path**, only a seed script from a workbook, and the "limits" are suggestions: nothing enforces who may approve what. Plan the data migration of this table and decide whether Phase 6 adds an editor.
7. **Cost centre and account category are stored codes with no enforcement**; export (a client-side file) is the only consumer. Check with Finance what the D365 export format is (the code lives in `src/lib/exportUtils.js`; not read in detail).
8. **Odoo reference service** is new (6 Oct) and optional: the platform needs its own Odoo sync (plan §6 Phase 1) before vendor matching can work; until then invoices stay unmatched. The vendor list's time stamp comes from the portal.
9. **Approval mail skips the send-grant check.** Routes 60 and 61 send from `AP_MAILBOX` with `graph.send_mail` directly, without the portal's `can_send` check that routes 51 and 54 make; revoking the grant in the portal would stop vendor mail but not approval mail.
10. **Sends are not idempotent and there is no Graph message id for sent mail** (`outbound_messages.graph_message_id` exists but is never filled). A double-click could send twice; Tri-Task's idempotency-key pattern is the precedent.
11. **Start-up data migrations run on every start** and read every file lacking a hash; fine on one host, not in an Azure App Service restart storm. The Azure SQL migration should do these once.
12. **`load_dotenv` without `override`** here and with it in the other apps; irrelevant after the port but explains differing behaviour on the dev box.
13. **Duplicate rules:** an exact-file duplicate creates no invoice and no review; a business duplicate (same vendor, number, currency, total within 0.01) is captured and flagged `Potential`. The two paths should be kept.
14. **`/users` route** is unlinked but live; with the portal it is read-only. Remove with the portal's user admin.
15. **Dead or stale under the portal:** `/login`, `/change-password`, local user routes (1, 6, 41 to 44) and local mailbox routes (30 to 32).
16. **Tests:** 113 Python tests across five files (features 72, vendor matching 16, signing limits 12, immutable ids 9, portal link 4) plus two JavaScript tests (`tests/formatters.test.mjs`, `tests/query-cache.test.mjs`). Not run (P0.4 installs nothing). `FEATURE_TODO.md` records an
    unresolved frontend `tsc` baseline (shadcn prop errors), relevant to plan P0.5.
17. **Uncommitted file** is only the checksum of the deployment archive. The plan's "1 uncommitted file" is therefore not a source change.
18. **Differences from the plan:** §4.1 says 6.5k server lines; `server/*.py` is 5.2k without tests. Plan §3.2's trap about `invoice-flow` line endings was not examined. The plan says Tri-AP "stores Graph immutable ids (converted 2 Oct)": confirmed in `graph_client.py` and `migrate_immutable_ids.py`.
