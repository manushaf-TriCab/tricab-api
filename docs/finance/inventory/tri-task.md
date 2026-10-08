# Inventory: Tri-Task

Part of Phase 0, task P0.4 ([FINANCE-PHASE-0.md](../../../../FINANCE-PHASE-0.md)).
Business name Tri-Task; portal application key `unique-fin-flow-sync`; port 8060.

**Refreshed 7 Oct 2026 for Phase 2 preparation, against `625c900` on `nick-review-20261006`** (Nick Han's 6 Oct review).
The first version was read at `main` `2be4a95`. Everything that changed between the two is listed in
[§11 Changes since 2be4a95](#11-changes-since-2be4a95); the other sections have been updated to match.

## 1. Source

| | |
|---|---|
| Directory | `D:\TriCab\finance\unique-fin-flow-sync` |
| Branch, commit | `nick-review-20261006` `625c900` ("Email popups drawn as Tri-AP's; To editable in the multi-task dialog"). **This is the reference for the port** (plan §3.2). Its parent is `a1b26f4` ("Nick's 6 Oct review: one-email reminders, import, column chooser, shared toolbar"), whose parent is `2be4a95` on `main` |
| Uncommitted files | none (`git status --short` empty, before and after the local run) |
| Date read | 7 Oct 2026 (first read 6 Oct, at `2be4a95`) |
| Deployed | never. There is no live branch to differ from, and the review branch is not on any host |
| Remote | none |

Files read: `server/{app,store,models,db,llm,mailing,summary,platform_client,importer,__init__}.py`,
`server/migrations/{env.py,versions/0001_baseline_task_schema.py,versions/0002_task_notes.py}`, `.env.example`,
`deploy/*`, `HANDBACK.md`, `package.json`, `public/tri-task-import-template.xlsx`, and the pages, hooks, libs and task
components under `src/` (not `components/ui/`). For roles only: `_work-portal-entra-20261005/server/app.py` (`d333f12`),
`ROLE_SEED` and `MAIL_PERMISSIONS`. The running app was also clicked through (screens in
`finance-reference/screenshots/tri-task/`, outside the repositories).

Size, counted on the machine: `server/*.py` 3,453 lines (2,929 at `2be4a95`; plan §4.1 says 3.9k, which
presumably includes tests and scripts); **53 route decorators** (51 at `2be4a95`); 120 `def test_` across six test
files (102 at `2be4a95` by the same count; the first version of this file said 101).

## 2. Routes

53 route decorators in `server/app.py`. Two are the SPA catch-all (`/` and `/<path>`) and two are the same `health()`
function on two paths, so there are 51 handler functions (49 before the review).

"Who" column:

- **session**: `require_permission(...)`. It first runs `_authenticate()`: the Flask session cookie holds
  `platform_uid` and `platform_session_version`, and every request calls the portal's `introspect` (one HTTP call per
  request). 401 if no session or inactive, 503 if the portal is down or SSO is not configured. It then requires
  **at least one** of the listed permissions, else 403.
- **row rule**: a finer check in `store.py` after the decorator. `_visible()` limits every task read to tasks where the
  caller is preparer or reviewer, unless they hold `task.tasks.read_all`. A task the caller cannot see answers 404, not 403.
- **service**: `require_service`, the `SERVICE_SECRET` as `Authorization: Bearer` or `X-Service-Key`.
- Local development without a portal: if `platform.finance_access is None` and `TASK_ALLOW_ANONYMOUS=1`,
  every route admits an anonymous `dev-user` holding all 13 permissions. Without that flag the routes answer 503.

The line number is the decorator's line in `server/app.py` at `625c900`. Rows marked **new** or **changed** are the
ones the review touched.

| # | Method, path | Handler (`server/app.py`) | Who | Reads / writes | Side effects |
|---|---|---|---|---|---|
| 1 | GET `/health` | `health` :207 | none | none | none |
| 2 | GET `/api/health` | `health` :208 | none | none | none |
| 3 | GET `/api/config` | `get_config` :217 | none | env: `FINANCE_PORTAL_PUBLIC_URL`, `APP_TIMEZONE` | none |
| 4 | GET `/api/auth/platform/callback` | `platform_callback` :222 | none (the SSO return) | writes the session cookie | portal `exchange(code)`; redirect to `/` |
| 5 | GET `/api/me` | `me` :241 | session: `task.tasks.read` | portal identity | portal introspect |
| 6 | GET `/api/members` | `list_members` :259 | session: `task.tasks.read` | portal members holding `task.tasks.read`; `task_member_profiles` | portal `list_members_with_permission` |
| 7 | PATCH `/api/members/<id>/profile` | `patch_profile` :265 | session: `task.setup.manage` | upserts `task_member_profiles` | portal member list |
| 8 | GET `/api/members/<id>/access` | `get_member_access` :287 | session: `task.users.manage` | portal user list | portal `list_users` |
| 9 | PUT `/api/members/<id>/access` | `put_member_access` :298 | session: `task.users.manage`; only `task.roles.assign_admin` may grant or change Administrator | writes the user's role **in the portal** | portal `update_user_access` |
| 10 | GET `/api/tasks` | `list_tasks` :333 | session: `task.tasks.read`; row rule | `task_tasks`, `task_dependencies`, project names. `?deleted=1` lists the Bin | none |
| 11 | GET `/api/tasks/<id>` | `get_task` :340 | session: `task.tasks.read`; row rule | same | none |
| 12 | POST `/api/tasks` | `create_task` :346 | session: `task.tasks.create` | inserts `task_tasks` (now with `notes`), `task_dependencies`, `task_history` | portal member list when a preparer or reviewer is named |
| 13 | PATCH `/api/tasks/<id>` | `patch_task` :353 | session: `task.tasks.update` or `update_own`; row rule; `can_edit_task` | updates task (incl. `notes`) and dependencies; `task_history` on a status change | 409 if blocked by unfinished dependencies |
| 14 | POST `/api/tasks/bulk` | `bulk_tasks` :360 | as 13, per row; max 200 rows; all or nothing | as 13 | as 13 |
| 15 | DELETE `/api/tasks/<id>` | `delete_task` :368 | session: `task.tasks.delete` or `delete_own`; `can_delete_task`. `?permanent=1` only from the Bin | soft: sets `deleted_at`, `deleted_by`. Permanent: deletes dependencies, history, task | none |
| 16 | POST `/api/tasks/<id>/restore` | `restore_task` :375 | as 15 | clears `deleted_at` | none |
| 17 | GET `/api/tasks/<id>/history` | `get_task_history` :381 | session: `task.tasks.read`; row rule | `task_history` | none |
| 18 | GET `/api/history` | `get_history` :387 | session: `task.analytics.read` | every `task_history` row, no limit; `?to_status=` | none |
| 19 | GET `/api/analytics` | `get_analytics` :393 | session: `task.analytics.read` | every live task visible to the caller | none |
| 20 | GET `/api/projects` | `list_projects` :403 | session: `task.tasks.read` | `task_projects` plus task counts over the caller's visible tasks. `?deleted=1` for the Bin | none |
| 21 | POST `/api/projects` | `create_project` :409 | session: `task.setup.manage` | inserts; name unique among live projects, case-insensitive | none |
| 22 | PATCH `/api/projects/<id>` | `patch_project` :415 | session: `task.setup.manage` | updates | none |
| 23 | DELETE `/api/projects/<id>` | `delete_project` :421 | session: `task.setup.manage` | soft; permanent only from the Bin and only if no task uses it | none |
| 24 | POST `/api/projects/<id>/restore` | `restore_project` :427 | session: `task.setup.manage` | clears `deleted_at`; 409 on a name clash | none |
| 25 | GET `/api/templates` | `list_templates` :433 | session: `task.tasks.read` | `task_templates` | none |
| 26 | POST `/api/templates` | `create_template` :439 | session: `task.setup.manage` | inserts | portal member list if a person is named |
| 27 | PATCH `/api/templates/<id>` | `patch_template` :446 | session: `task.setup.manage` | updates | as 26 |
| 28 | DELETE `/api/templates/<id>` | `delete_template` :453 | session: `task.setup.manage` | hard delete | none |
| 29 | POST `/api/templates/<id>/create-task` | `template_create_task` :459 | session: `task.tasks.create` | as 12 | portal member list always. **Not called by the UI** (§10) |
| 30 | GET `/api/statement-areas` | `list_areas` :469 | session: `task.tasks.read` | `task_statement_areas` | none |
| 31 | POST `/api/statement-areas` | `create_area` :475 | session: `task.setup.manage` | inserts | none |
| 32 | POST `/api/statement-areas/bulk` | `bulk_areas` :481 | session: `task.setup.manage`; max 100; skips existing names | inserts | none |
| 33 | PATCH `/api/statement-areas/<id>` | `patch_area` :489 | session: `task.setup.manage` | updates | none |
| 34 | DELETE `/api/statement-areas/<id>` | `delete_area` :495 | session: `task.setup.manage`; 409 if a live task uses the name | hard delete | none |
| 35 | GET `/api/entities` | `list_entities` :501 | session: `task.tasks.read` | `task_entities` | none |
| 36 | POST `/api/entities` | `create_entity` :507 | session: `task.setup.manage` | inserts | none |
| 37 | PATCH `/api/entities/<id>` | `patch_entity` :513 | session: `task.setup.manage` | updates | none |
| 38 | DELETE `/api/entities/<id>` | `delete_entity` :519 | session: `task.setup.manage`; 409 if any task uses it | hard delete | none |
| 39 | POST `/api/ai/task-details` | `ai_task_details` :534 | session: `task.tasks.create` | none | OpenRouter: description and requirements for a task name |
| 40 | POST `/api/ai/statement-areas` | `ai_statement_areas` :550 | session: `task.setup.manage` | reads areas | OpenRouter, 90 s timeout: 18 to 24 suggested areas, existing names removed |
| 41 | POST `/api/ai/task-emails` | `ai_task_emails` :567 | session: `task.mail.send`; 1 to 20 tasks; visible tasks only | none | OpenRouter, one call per task (to the task's preparer); portal member list |
| 42 | POST `/api/ai/reminders` | `ai_reminders` :609 | session: `task.tasks.read`; row rule | none | **changed:** OpenRouter, **one** call for the task, one draft addressed to the preparer and the reviewer together (each person once). Answers `{taskName, recipients[{role,email,name}], subject, body}`; with nobody assigned it answers empty and makes no call. It was one draft per person |
| 43 | POST `/api/ai/email-draft` | `ai_email_draft` :635 | session: `task.mail.send`; row rule | none | **changed:** OpenRouter: draft or revise one email from the sender's instruction. `to` may now be one address or several, all of whom must be Tri-Task members (else 400); with none given it is the preparer. Answers also `recipients[]` |
| 44 | GET `/api/send-mailboxes` | `send_mailboxes` :706 | session: `task.mail.send` | none | portal Mail Service `list_mailboxes` for this user |
| 45 | POST `/api/mail/send` | `send_mail` :712 | session: `task.mail.send`; row rule per task; max 20 emails; recipients (to and cc) must be Tri-Task members or summary recipients; sender must be a mailbox the portal grants | `task_outbound_messages` (written `pending`, then `sent` or `failed`) | **sends mail** through the portal Mail Service: HTML body, optional inline attachments (3 MB total, 10 files). Code unchanged by the review; the UI now sends `to` and `cc` as lists (§9) |
| 46 | GET `/api/tasks/<id>/mail` | `task_mail` :783 | session: `task.tasks.read`; row rule (404 for a task the caller cannot see) | `task_outbound_messages` for the task, newest first, **no bodies** (`store.list_outbound`) | none. **New** |
| 47 | POST `/api/import` | `import_spreadsheet` :795 | session: `task.tasks.create`; creating projects also needs `task.setup.manage` | body `{filename, data (base64), dry_run}`; `dry_run` defaults to true and writes nothing. Otherwise inserts projects and tasks (§2 "Import") | none beyond a log line. **New** |
| 48 | GET `/api/settings/report-recipients` | `list_recipients` :821 | session: `task.settings.manage` | `task_report_recipients` | none |
| 49 | POST `/api/settings/report-recipients` | `add_recipient` :827 | session: `task.settings.manage` | inserts; unique email | portal member list (to link a user id) |
| 50 | DELETE `/api/settings/report-recipients/<id>` | `delete_recipient` :838 | session: `task.settings.manage` | hard delete | none |
| 51 | POST `/api/jobs/daily-summary` | `job_daily_summary` :844 | service secret | `task_tasks`, `task_report_recipients`, `task_outbound_messages` | **sends mail**, one per recipient (§6) |
| 52 | GET `/` | `spa` :854 | none | serves `dist/index.html` | none |
| 53 | GET `/<path>` | `spa` :855 | none | serves `dist/<path>` (this is how `/tri-task-import-template.xlsx` is served), else `index.html`; paths starting `api/` answer 404 JSON | none |

Reconciliation: 53 found; plan §4.1 says 51. **Not equal.** The two extra routes are 46 and 47, both added by the review
(`a1b26f4`). §4.1 should read 53 for Tri-Task; it is left as written for Manusha to change.

### Import (route 47, `server/importer.py`)

New in `a1b26f4`. Loads projects and tasks from Nick Han's template.

- **Input:** `.xlsx`, `.xlsm` or `.csv`, at most 5 MB and 1,000 rows. A workbook may have a "Project import template" sheet
  (Project name, Description, Status, Target Date) and a "Task import template" sheet (Entity, Task Name, Project,
  Description, Requirements, Notes, Finance Statement Area, Category, Status, Preparer, Reviewer, Progress %, Due Date,
  Due Date Requested, Mark as Urgent). Sheets are recognised by their headings, not by name or position; a sheet that
  matches neither is skipped, and no usable sheet at all is a 400. Aliases are accepted so an exported CSV loads back.
  A column that is not part of the template is reported as a warning and ignored.
- **Two passes over one code path.** `dry_run=true` (the default) reports each row and writes nothing; the dialog shows
  that first and only then sends the same file with `dry_run=false`.
- **Nothing is guessed.** A row whose entity, preparer, reviewer, date, status, category or progress cannot be read is
  reported ("Not imported") and left out; the others still load. Entities match by name or code, people by full name or
  email (a name matching more than one person is an error). Dates: Excel dates, Excel serial numbers, `yyyy-mm-dd` and
  day-first `dd/mm/yyyy` text. Status `Overdue` is imported as In Progress with a note (overdue is derived).
- **Duplicates.** A task whose (name, project, entity) is already live in the register, whether or not the caller can see
  it, or repeats an earlier row of the file, is skipped. The same file can therefore be loaded twice.
- **Projects.** The Project sheet is read first; a project named only on a task row is created if the caller holds
  `task.setup.manage`, once however many tasks name it, and not at all if none of its tasks will load. An existing live
  project is matched case-insensitively by name.
- **Result:** `{filename, dry_run, projects[], tasks[], warnings[], summary{projects, projects_existing, tasks, tasks_duplicate, errors}}`;
  each line has `row`, `action` (`create`, `created`, `exists`, `duplicate`, `error`), `errors[]`, `warnings[]`.
- Uses `openpyxl` (new line in `server/requirements.txt`), imported only when a workbook is read. Import runs row by row
  through `store.create_project` and `store.create_task`, not in one transaction: a row that fails after the others
  succeed leaves the others in place.
- `public/tri-task-import-template.xlsx` ships with the frontend. Its three example rows name the entity "Tricab Australia"
  and areas written as "Income Statement: Revenue" and "Balance Sheet: Accounts Receivable"; against an empty Tri-Task all
  three are refused for the entity (screenshot 301).

Behaviour to carry into the port, all from `store.py`:

- **Overdue is not stored.** `display_status` returns `overdue` when the task is not completed
  and `due_date` is before today in `APP_TIMEZONE` (default `Australia/Melbourne`). Setting `status` to
  `overdue` is rejected. Stored statuses are `in_progress` and `completed`.
- **Dependencies.** A status change (to completed, or reopening) is refused with 409 and a `blockers` list
  while any dependency is unfinished and not deleted. Cycles are rejected. Creating a task does not run this
  check.
- **History** is written by the server, in the same transaction, on create and on every status change.
- **Bulk update** is one transaction: one bad row rolls back all.
- **Timestamps** leave the API as ISO 8601 with an explicit `+00:00`; stored values are naive UTC.
- **No row caps.** `/api/tasks`, `/api/history` and `/api/analytics` return everything.
- Field naming: the API says `preparer_id`, `reviewer_id`, `created_by_id`; the columns are
  `preparer_user_id`, `reviewer_user_id`, `created_by_user_id`. The API also returns `project` (the name) for
  the pre-rewrite UI, `project_name`, `display_status`, `notes`, and `dependencies` (a list of ids).

## 3. Data

All tables come from Alembic revisions `0001` (`server/migrations/versions/0001_baseline_task_schema.py`) and `0002`
(`0002_task_notes.py`, new); the version table is `task_alembic_version`. The app was never deployed, so there is no live
pre-rewrite table set. The names below are the final prefixed names the plan (§6) keeps, so the "matching prefixed name"
the task asks for is the table name itself.

Conventions: ids are `NVARCHAR(36)` UUID text from `uuid.uuid4()`; bounded text is `Unicode(n)`; long text is
`NVARCHAR(MAX)` on SQL Server; every timestamp is `DATETIME2(6)`, **naive UTC, no zone**; booleans are `BIT`;
dates the UI owns are `NVARCHAR(10)` `yyyy-MM-dd` strings compared lexically. Constraint names follow an
explicit convention (`pk_`, `fk_`, `uq_`, `ix_`). Every table except `task_dependencies`,
`task_report_recipients` and `task_outbound_messages` has a nullable `legacy_id NVARCHAR(36)` (the Base44 id,
used by the importer) with a unique index filtered `WHERE legacy_id IS NOT NULL`.

### `task_tasks`

| Column | Type | Null | Notes |
|---|---|---|---|
| id | NVARCHAR(36) | no | PK `pk_task_tasks` |
| name | NVARCHAR(300) | no | |
| description | NVARCHAR(MAX) | yes | long text |
| requirements | NVARCHAR(MAX) | yes | long text; the AI writes "- " lines |
| **notes** | NVARCHAR(MAX) | yes | **new in revision `0002`** (`ALTER TABLE ... ADD`, nullable, no default). Free text beside Requirements; Nick's template has a Notes column. Not part of templates (the task form drops it in template mode) |
| category | NVARCHAR(20) | no | `routine` or `ad_hoc` |
| finance_statement_area | NVARCHAR(300) | yes | free text matching `task_statement_areas.name`, **not a foreign key** |
| entity_id | NVARCHAR(36) | yes | FK `task_entities.id` |
| project_id | NVARCHAR(36) | yes | FK `task_projects.id` |
| preparer_user_id | NVARCHAR(36) | yes | **portal user id**, no FK |
| reviewer_user_id | NVARCHAR(36) | yes | portal user id, no FK |
| status | NVARCHAR(20) | no | `in_progress` or `completed` |
| progress | INT | no | 0 to 100 |
| due_date | NVARCHAR(10) | yes | `yyyy-MM-dd` string |
| due_date_requested | BIT | no | |
| urgent | BIT | no | |
| completed_at | DATETIME2(6) | yes | |
| created_by_user_id | NVARCHAR(36) | yes | portal user id |
| deleted_at, deleted_by | DATETIME2(6), NVARCHAR(36) | yes | the Bin |
| legacy_id, created_date, updated_date | | yes | |

Indexes: `uq_task_tasks_legacy_id` (filtered), `ix_task_tasks_preparer_user_id`,
`ix_task_tasks_reviewer_user_id`, `ix_task_tasks_deleted_at`, `ix_task_tasks_status_due_date` (status, due_date).

The review changed no other table. The importer reads `task_entities` (name, code), `task_statement_areas`, live
`task_projects` and every live task's (name, project, entity) through two new store functions,
`project_ids_by_name` and `task_identity_keys`; neither adds a column or index.

### `task_dependencies`

`id` PK; `task_id` NOT NULL FK `task_tasks.id`; `depends_on_task_id` NOT NULL FK `task_tasks.id`;
`created_date`. Unique `uq_task_dependencies_task_id_depends_on_task_id`; index
`ix_task_dependencies_depends_on_task_id`. Meaning: `task_id` cannot complete until `depends_on_task_id` has.

### `task_history`

`id` PK; `task_id` NOT NULL FK `task_tasks.id`; `from_status`, `to_status` NVARCHAR(40);
`changed_by_user_id` NVARCHAR(36); `changed_by_name` NVARCHAR(200) (name at the time, copied from the
session); `changed_at` DATETIME2(6); `legacy_id`; `created_date`; `updated_date`. Indexes
`ix_task_history_task_id`, `uq_task_history_legacy_id` (filtered).

### `task_projects`

`id` PK; `name` NVARCHAR(200) NOT NULL; `description` NVARCHAR(MAX); `color` NVARCHAR(20); `status`
NVARCHAR(20) NOT NULL (`planning`, `active`, `completed`, `on_hold`); `target_date` NVARCHAR(10);
`deleted_at`; `deleted_by`; `legacy_id`; `created_date`; `updated_date`. Name uniqueness among live rows is
enforced in code only (case-insensitive), not by an index.

### `task_templates`

`id` PK; `name` NVARCHAR(300) NOT NULL; `description`, `requirements` NVARCHAR(MAX); `category` NVARCHAR(20)
NOT NULL; `finance_statement_area` NVARCHAR(300); `preparer_user_id`, `reviewer_user_id` NVARCHAR(36);
`default_progress` INT NOT NULL; `due_date` NVARCHAR(10); `due_date_requested` BIT NOT NULL; `legacy_id`;
`created_date`; `updated_date`.

### `task_statement_areas`

`id` PK; `name` NVARCHAR(300) NOT NULL; `category` NVARCHAR(30) NOT NULL (`balance_sheet`, `income_statement`,
`cash_flow`, `equity`, `notes`, `other`); `description` NVARCHAR(MAX); `sort_order` INT NOT NULL; `legacy_id`;
`created_date`; `updated_date`. Name uniqueness (case-insensitive) is enforced only in `bulk_create_areas`,
not on single create and not by an index.

### `task_entities`

`id` PK; `name` NVARCHAR(200) NOT NULL; `code` NVARCHAR(40); `description` NVARCHAR(MAX); `color`
NVARCHAR(20); `is_active` BIT NOT NULL; `legacy_id`; `created_date`; `updated_date`.

### `task_member_profiles`

`id` PK; `platform_user_id` NVARCHAR(36) NOT NULL **unique** (`uq_task_member_profiles_platform_user_id`);
`job_title` NVARCHAR(200); `legacy_id`; `created_date`; `updated_date`. The only thing Tri-Task stores about a
person; name and email come from the portal.

### `task_report_recipients`

`id` PK; `email` NVARCHAR(320) NOT NULL unique; `name` NVARCHAR(200); `platform_user_id` NVARCHAR(36);
`created_date`; `updated_date`.

### `task_outbound_messages`

Audit of every send. `id` PK; `kind` NVARCHAR(20) NOT NULL (`task_email`, `reminder`, `daily_summary`);
`task_id` NVARCHAR(36) (**no FK on purpose**, so the audit outlives a permanently deleted task);
`sender_user_id` NVARCHAR(36); `from_mailbox` NVARCHAR(320); `to_addresses`, `cc_addresses`,
`attachment_names` NVARCHAR(MAX) holding **JSON arrays as text**; `subject` NVARCHAR(500); `body` NVARCHAR(MAX)
(the full body, HTML for summaries); `idempotency_key` NVARCHAR(200); `status` NVARCHAR(20) NOT NULL
(`pending`, `sent`, `failed`); `error` NVARCHAR(MAX); `sent_at`; `created_date`; `updated_date`. Indexes
`ix_task_outbound_messages_task_id`, `ix_task_outbound_messages_idempotency_key`. **Now read by route 46**, which lists a
task's rows newest first without the body; it was written only before.

### Notes for Azure SQL

- **JSON in text columns:** `task_outbound_messages.to_addresses`, `cc_addresses`, `attachment_names`. There are
  no native JSON columns anywhere.
- **Long text used in sorting:** none. Every `ORDER BY` is on a bounded column: tasks by `updated_date` then
  `id`; projects, entities and templates by `name` (200 or 300); areas by `sort_order`, `name`; history by
  `changed_at`, `id`; recipients by `email`. The `NVARCHAR(MAX)` hazard in plan §13 does not arise here. The new `notes`
  column is never sorted on by the server; the browser sorts any column as text (`sortTasks`).
- **Timestamps without a zone:** all of them. The API adds `+00:00` on the way out.
- **Dates as strings:** `due_date` and `target_date` (and the template `due_date`); `summary_tasks` and the
  overdue test compare them lexically.
- **Identity:** `preparer_user_id`, `reviewer_user_id`, `created_by_user_id`, `deleted_by`,
  `changed_by_user_id`, `sender_user_id`, `platform_user_id` all hold **portal** user ids. The platform has its
  own identity users, so a mapping is needed when data moves (§10).
- The importer for the Base44 export is `scripts/import_base44.py`, with `data-export/*.csv`. Those files hold
  real names and tasks and must stay out of the platform repository. It is a different thing from the spreadsheet
  import above.

## 4. Files

The application writes nothing to disk except the SQLite file when no database URL is set
(`server/data/tasks.sqlite3`; `TASK_DATA_DIR` moves it). There are **no stored attachments.** Email
attachments arrive in the request as base64 (`{name, content_type, data}`), are decoded in memory by
`mailing.decode_attachments`, are passed to the portal's Mail Service and are not kept; only their names are
recorded in `task_outbound_messages.attachment_names`. Limits: 10 files, 3 MB in total, no `/` or `\` in a name.
The browser enforces the same limits in `AttachmentPicker` (`lib/attachments.js`).

**The import file is the same kind of thing:** base64 in the request, at most 5 MB, parsed in memory, not kept.
`public/tri-task-import-template.xlsx` (10.7 kB) is a static asset in the frontend bundle, not an upload.

`deploy/backup.sh` backs the SQLite file up to S3 nightly; it is retired when the data moves.

## 5. Roles and access

The app has no role table. Roles live in the **portal** (`_work-portal-entra-20261005/server/app.py`
`ROLE_SEED`, application key `unique-fin-flow-sync`). The app reads the caller's permissions from the portal on
every request and reads roles only to label members (`_access_role` picks `admin`, then `manager`, then
`user`). Each role is a superset of the one before.

| Permission | user (Team Member) | manager | admin (Administrator) |
|---|:-:|:-:|:-:|
| `task.tasks.read` | yes | yes | yes |
| `task.tasks.create` (also: **import**) | yes | yes | yes |
| `task.tasks.update_own` | yes | yes | yes |
| `task.tasks.delete_own` | yes | yes | yes |
| `task.setup.manage` (projects, templates, areas, entities, job titles; also: **projects created by an import**) | yes | yes | yes |
| `task.mail.send` | yes | yes | yes |
| `task.tasks.read_all` | | yes | yes |
| `task.tasks.update` | | yes | yes |
| `task.tasks.delete` | | yes | yes |
| `task.users.manage` (change members' roles) | | yes | yes |
| `task.analytics.read` | | | yes |
| `task.roles.assign_admin` | | | yes |
| `task.settings.manage` (daily-summary recipients) | | | yes |

- 13 permissions in `ALL_PERMISSIONS`; the table lists all 13. **The review added none.** Import and the mail history
  reuse `task.tasks.create` and `task.tasks.read`.
- Rules the app enforces itself, because the portal only checks for some `*.users.manage`
  (`put_member_access`): a manager cannot grant Administrator and cannot change someone who
  already holds it; the target must already be a Tri-Task member; the role must be `admin`, `manager` or `user`.
- Visibility: without `read_all`, a person sees only tasks they prepare or review (404 otherwise). HANDBACK
  records one consequence carried over from Base44: a Team Member who allocates a task to two other people
  cannot see it afterwards. **The import ignores visibility when it decides what is a duplicate** (a task someone else
  holds still counts), and it creates tasks with the caller as `created_by`, so a Team Member can import tasks they
  then cannot see.
- The portal's `MAIL_PERMISSIONS` names `task.mail.read` and `task.mail.send` for this app. `task.mail.read` is
  in no seeded role and nothing in Tri-Task uses it. The new mail-history route uses `task.tasks.read`.
- How the app gets roles: `platform_client.py` imports `FinanceAccessClient` and `FinanceMailClient` from
  `shared/tricab_access_client.py` and `shared/tricab_mail_client.py` (on the host
  `/opt/tricab-finance/shared`, locally the sibling `..\tricab-finance-aws`). Calls used: `exchange`,
  `introspect`, `list_members_with_permission`, `list_users`, `update_user_access`.
- Browser side: `useAccessRole` derives `admin` from `task.analytics.read`, `manager` from
  `task.tasks.read_all`, else `user`, for show and hide only. `lib/permissions.js` has a second, role-name based
  set of helpers (`canSeeAnalytics`, `canManageRoles`, `assignableRolesFor`, `filterTasksForRole`). The Import button
  is shown to everyone; the server decides.

## 6. Background work

| Job | Trigger | What it does |
|---|---|---|
| Schema migration | `store.init_db()` runs at **import** of `server.app` (and in `summary.main`), so on every Gunicorn worker start | `alembic upgrade head` against `TASK_DATABASE_URL`, or the SQLite default. Now runs `0001` then `0002` |
| Daily summary | systemd timer `unique-fin-flow-sync-summary.timer`, 08:00 `Australia/Melbourne`, `Persistent=true`, running the oneshot service `python -m server.summary`. The same logic is behind `POST /api/jobs/daily-summary` with `SERVICE_SECRET` | Selects every live, not-completed task with `due_date <= today` (Melbourne). Sends one HTML email per recipient in `task_report_recipients`, from `TASK_MAIL_FROM`, with no actor. Nothing is sent if there are no recipients or nothing is due. Idempotency key `daily-summary:<date>:<email>`; after a failed or interrupted send the retry uses `…:retryN`; a key already `sent` is skipped. Exit code is non-zero on error or partial failure. **Unchanged by the review** |
| Nightly backup | root cron, `deploy/backup.sh` | `sqlite3 .backup`, then S3. Retired with the move |

There is no mailbox scanning and there are no other timers. The browser refetches the task register and the
project list every 60 s while the tab is visible, and on window focus (`useLiveQuery`).

## 7. External calls

- **TriCab Finance portal** (`FINANCE_PLATFORM_URL`, loopback `127.0.0.1:8040`): sign-in code exchange,
  per-request session check, member list, user list and role update, and the **Mail Service**
  (`list_mailboxes`, `send_mail`). Tri-Task holds **no Graph credentials**; all mail goes through the portal.
  Mailbox grants are per app in the portal's Mailbox registry.
- **Graph:** none directly. The sending mailbox is `TASK_MAIL_FROM` (`.env.example` shows
  `financeau@tricab.com`) and must be granted "send" for Tri-Task in the portal. No mailbox is read, so there is
  no Graph message-id form to record. User sends use the idempotency key `task-<kind>:<outbound_id>`.
- **OpenRouter** (`https://openrouter.ai/api/v1/chat/completions`) through one entry point,
  `llm.complete_json`: model `google/gemini-2.5-flash` unless `TASK_LLM_MODEL`, temperature 0.3, strict JSON
  schema, 60 s timeout (90 s for statement areas). **Five prompt purposes**, unchanged in number: task details
  (description and requirements), statement areas, task email, reminder email, email draft or revision from an
  instruction. **Changed:** the reminder and the email-draft prompts now take a list of recipients
  (`llm._addressed_to`): with more than one, the prompt says it is ONE email to all of them, opens with "Hi" and their first
  names, and says what each needs to do; with one it reads as before. User-written task text is wrapped in tags and the
  model is told it is data. **On failure** `LLMError` is logged at ERROR with a context string and the route answers 502
  `{"error": "The AI draft failed: …"}`; the UI shows a destructive toast. A missing key is also an `LLMError`
  (`OPENROUTER_API_KEY is not configured`; no request is made). (Unlike plan §4.5's general statement, failures here do
  reach the logs.) The key is shared by all apps under a small monthly cap. **Found on 7 Oct:** the app's `.env`
  loader is `override=True`, but a user-level `OPENROUTER_API_KEY` is inherited when there is no `.env` (there is
  none locally); a dead machine-wide key therefore sends real requests that come back 401.
- **Odoo, Document Intelligence, audio, S3** (apart from the backup script): none.

## 8. Settings

Environment setting names, no values (`.env.example`; on the host `/etc/unique-fin-flow-sync.env`). **The review
added none.** One new Python dependency, `openpyxl>=3.1` (`server/requirements.txt`).

| Setting | Controls |
|---|---|
| `FINANCE_PLATFORM_URL` | loopback address the backend calls the portal on |
| `FINANCE_PLATFORM_APP_KEY` | portal application key, default `unique-fin-flow-sync` |
| `FINANCE_PLATFORM_CLIENT_SECRET` | the app's secret for the portal (the portal side is `TASK_CLIENT_SECRET`). Absent means SSO is off |
| `FINANCE_PORTAL_PUBLIC_URL` | browser-facing portal address for the home button and sign-out; empty hides the button. A loopback `FINANCE_PLATFORM_URL` is refused as a fallback |
| `FINANCE_PLATFORM_SHARED_ROOT` | where the `shared/` clients are imported from |
| `TASK_ALLOW_ANONYMOUS` | `1` runs without SSO as an admin `dev-user`. Local only |
| `SERVICE_SECRET` | authorises `POST /api/jobs/daily-summary` |
| `TASK_MAIL_FROM` | sending mailbox for emails, reminders and the summary. No fallback in code |
| `OPENROUTER_API_KEY`, `TASK_LLM_MODEL`, `OPENROUTER_APP_NAME` | AI provider key, model, header title |
| `TASK_DATABASE_URL` | SQLAlchemy URL; empty means the SQLite file |
| `TASK_DATA_DIR` | folder of the SQLite file |
| `APP_TIMEZONE` | decides "overdue" and "today"; default `Australia/Melbourne` |
| `SECRET_KEY` | Flask session signing; a random one is generated if unset |
| `SESSION_COOKIE_SECURE` | secure cookie flag |
| `PORT` | dev server port, default 8060 |
| `TASK_SKIP_DOTENV` | tests only: stops `server/__init__.py` loading `.env` |
| `TASK_BACKUP_BUCKET` | `backup.sh` only |

`server/__init__.py` loads `.env` with `override=True`, so `.env` beats the process environment.

## 9. Screens

React 18.2, React Router 6 `BrowserRouter` with no base path, shadcn/ui on Radix, TanStack Query 5. All API
calls are in one file, `src/api/base44Client.js`, an adapter that keeps the old Base44 SDK shape (two additions:
`entities.Task.mail(id)` and `imports.run`). Auth states (`AuthContext`): loading spinner; `signed_out` (the browser is sent
to the portal); `no_access` (`/api/me` answered 403) shows `UserNotRegisteredError` ("Access Restricted"); `ok`.
`Layout.jsx` gives a sticky header with the portal brand link, nav (Dashboard, Projects, Analytics for admins only,
Templates, Statements, Entities, Team), a copy-link button, an "Allocate Task" button and sign-out. The Dashboard uses the
full width; other pages cap at 1400 px. Unknown paths show `PageNotFound`. Screenshots: `finance-reference/screenshots/tri-task/`
(100 files, with `index.md`); the numbers in brackets below are theirs.

Every page shows a spinner while loading and a destructive toast on a failed call. Route numbers refer to §2.

**The toolbar** (`RegisterToolbar.jsx`, new) is shared by the Dashboard and Projects pages: a view switch (Table, Board,
Calendar, Projects), **Bin**, **Import**, **Export CSV**, and **Create Project** as the one filled button. It replaced two
hand-written toolbars that had drifted apart ("make this the same like Projects page", Nick Han). On the Dashboard the mobile
"New Task" button follows it.

| Path | Component | API routes called | States and dialogs |
|---|---|---|---|
| `/` | `pages/Home.jsx` | 10, 12, 13, 14, 15, 6, 35, 20, 21, 41, 45, 44, 42, 43, 46, 47 | Spinner. Empty ("No tasks here." with "Allocate a task") (001 to 004). Status tabs (all, in progress, completed, overdue) with counts (110 to 112). Team and entity summary cards that act as filters (113, 114). Four views: **Table** (100), **Board** (150), **Calendar** (151), **Projects** (152; `?project=<id>` filter chip, 153). **The table** has a "+" at the end of its header row that lists 14 optional or default columns (Entity, Project, Category, Financial Statements, Requirements, Notes, Status, Progress, Due Date, Preparer, Reviewer, Created, Completed, Remind; `#` and Task are always shown) with "Reset to default" (101, 102); the choice is kept in this browser's `localStorage` under `tri-task.register.columns`, and hiding a column clears its filter; any column sorts, as text if it has no special rule (`taskStatus.sortTasks`); column filters on Entity, Project, Financial Statements, Status, Due Date, Preparer, Reviewer (115, 116); mobile shows cards. **Export CSV** (client side) now writes the import template's columns in its order, with a BOM and CRLF, so an export can be edited and loaded back; the Dashboard exports the filtered rows. **Import** opens the import dialog. Create Project dialog (154). Task form dialog (new, edit) now with **Notes** (133, 134). Multi-select dock: reassign, **Email** (120). Dialogs: move to Bin (157), Reassign, Blocked ("This task is blocked", OK only; 156), AI email preview (137), Reminder email (135, 139). For role `user` the list is also filtered in the browser |
| `/projects` | `pages/Projects.jsx` | 20, 10, 21, 22, 23, 47 | Spinner; empty "No projects yet."; status filter tabs; Table, Board, Calendar, Project views (200 to 203); project form dialog (204, 205); "Move this project to the Bin?" alert (206); the shared toolbar, whose **Export CSV** writes the projects list (Project name, Description, Status, Target Date, Tasks, Completed) and whose Import opens the same dialog (306) |
| `/analytics` | `pages/Analytics.jsx` | 19, 6 | Redirects to `/` unless the caller holds `task.analytics.read`. Spinner. Period select (last 7, 30, 90 days, custom range), member select, metric cards, completion timeline chart, per-member table, empty line "No task data for team members yet." (210 to 212) |
| `/templates` | `pages/Templates.jsx` | 25, 6, 26, 27, 28, and 12 for "use template" | Spinner; empty "No templates yet."; template form (template mode, or task mode); "Delete this template?" alert (220 to 224). **"Use Template" opens a task form whose required Project list is empty** ("No projects yet. Use Create Project on the dashboard first."): this page never loads projects (222). Not changed by the review; found on 7 Oct (§10) |
| `/financial-statements` | `pages/FinancialStatements.jsx` | 30, 31, 33, 34, 40, 32 | Spinner; empty "No financial statement items yet…"; category tabs with "No items in this category yet."; add and edit dialog; remove alert; "Suggest with AI" dialog (loading, list with check boxes, add selected; with the AI unavailable it says "No suggestions available." and hides the error) (230 to 237) |
| `/entities` | `pages/Entities.jsx` | 35, 36, 37, 38 | Spinner; empty "No legal entities yet…"; add and edit dialog with colour; remove alert (240 to 244) |
| `/team` | `pages/Team.jsx` | 6, 9, 7 | Spinner; empty "No team members yet. People are added to Tri-Task in TriCab Finance."; role select shown only to holders of `task.users.manage` (Administrator option only for holders of `task.roles.assign_admin`; managers cannot edit an admin); job title dialog; link to `/settings` (060, 250, 251). The role select is too narrow for its text ("Team Mem", "Administrat") at 1440 px |
| `/settings` | `pages/Settings.jsx` | 48, 49, 50 | Redirects to `/` unless `task.settings.manage`. Recipient list with add by email and remove; text says the summary goes at 8:00 am Melbourne time (260) |
| `/bin` | `pages/Bin.jsx` | 10 and 20 with `?deleted=1`, 16, 24, 15 and 23 with `?permanent=1` | Tabs for tasks and projects; spinner; restore; "Delete permanently?" alert (270 to 272) |
| `*` | `PageNotFound` | none | 404 with "Go Home" (090) |

### Dialogs used by the Dashboard, with their calls

- **`TaskForm`**: 39 "Suggest description & requirements"; reads 30.
- **`EmailPreviewDialog`** (multi-select "Email"): **changed.** 41 drafts one email per selected task, addressed to its preparer; each draft now has an
  editable **To** and **CC** as address chips (`AddressInput`, which suggests Tri-Task members), a Subject `Input` and a Message
  `Textarea` drawn as Tri-AP's vendor email dialog (`Label`, `Input`, `Textarea` from the kit); the From picker is a plain
  `<select>` (it was the kit's `Select`); the AI chat panel is the shared `AiDraftPanel`, now titled "AI drafting chat", whose
  button calls 43. **Send is disabled while any email has no recipient** ("One of the emails has no recipient"). Sends through 45.
  If drafting fails the dialog does not open and a "Drafting failed" toast shows (131).
- **`ReminderEmailDialog`** (the mail icon on a row): **rewritten.** One message, not one per person: 42 returns one draft for the
  preparer and the reviewer together, shown in a two-column dialog (AI drafting chat on the left, with "Previous correspondence", the last
  three rows of route 46; the message on the right: From, To, CC, Subject, Message, **Attachments** via `AttachmentPicker`). What is on screen
  is what everyone receives (Nick Han: "it still sends the original one"). If the AI fails the dialog still opens, with To filled with the
  preparer and reviewer, a default subject and an empty body, and a "No AI draft this time" toast (135). Keyed on the task's id, so the
  60-second refetch does not replace a draft being edited. Sends through 45 with `to` and `cc` as lists and the attachments.
- **`ReassignDialog`** (14), **`MailFromSelect`** (44; now says "No sending mailbox is available…" when there is none), **`BlockedStartDialog`**.
- **`ImportDialog`** (47, new): choosing a file previews it at once (`dry_run`), listing each project and task row with "Will be added",
  "Already exists", "Skipped" or "Not imported" and its messages; "Import N tasks and M projects" runs it for real; the same file can be
  chosen again. Links to the template (300 to 306).
- **`AddressInput`**, **`AttachmentPicker`**, **`RegisterToolbar`**: new components, above.

## 10. Open questions

Items 1 to 13 were raised on 6 Oct and are carried forward, updated; 14 onward are new.

1. **Unused code.** `components/tasks/DependenciesPicker.jsx` and `components/tasks/WeeklyCompletionChart.jsx`
   are still imported nowhere. So the UI cannot set dependencies, yet the server enforces them; the Base44 export had
   none, so `task_dependencies` is probably empty. `store.list_outbound` **now has a route** (46). The
   `/api/templates/<id>/create-task` route is tested but the UI uses `Task.create` for "use template". Port or
   drop: needs a decision.
2. **`/api/mail/send` does not store its idempotency key.** `create_outbound` is called without
   `idempotency_key`, so `task_outbound_messages.idempotency_key` is null for user sends; the key
   `task-<kind>:<outbound_id>` goes to the portal only. Only the daily summary stores and reuses keys.
3. **Mail Service replacement.** Resolved in Phase 1 (F1.4: `finance.mailboxes`, `mailbox_grants`, `mail_deliveries`, with the portal's rules).
   What is left is how Tri-Task's routes call it (Phase 2 file).
4. **User ids.** Every person reference is a portal user id. The plan moves identity to the platform, so a
   portal-to-platform user mapping is needed and `task_member_profiles.platform_user_id` (unique) must be
   rewritten. Phase 1 F1.10 built the map table and script. Because Tri-Task was never deployed, **there is no production data to
   migrate**; the question is only which Base44 export, if any, is loaded.
5. **Authorisation shape.** Routes check "any of" a permission list plus row rules in the domain layer; the
   platform checks one permission per route (`RequirePermissionMiddleware`). Routes 13 to 16 accept two
   alternative permissions, and routes 10 to 17, 19, 42, 43, 45 and 46 depend on row rules, so those rules belong in
   the use case, not the route.
6. **Role edits go to the portal** (`update_user_access`, `list_users`). After the move roles belong to the
   platform's identity, so routes 8 and 9 and the Team page's role select need a different backend or a
   redirect to the platform's user admin. Plan §6 says the finance portal's user admin goes away.
7. **`task.mail.read`** is in the portal's `MAIL_PERMISSIONS` for this app but in no seeded role. Probably dead.
8. **`SECRET_KEY` unset** generates a random key per process, so with two Gunicorn workers sessions would not
   survive moving between them. Presumably set on the host; not checked (the host is out of bounds). Irrelevant after the move.
9. **Uniqueness enforced only in code:** project name among live rows; statement-area name on bulk create only;
   entity name not at all. Concurrent requests could duplicate. **The import adds a second writer of projects and tasks** and
   its duplicate rule (name, project, entity) is also code only. Decide whether the platform schema adds indexes.
10. **Binned tasks and dependencies.** `_blockers` ignores a binned dependency, but the cycle check in
    `_set_dependencies` reads every dependency row including those of binned tasks.
11. **Two sets of role helpers in the browser** (`lib/permissions.js` by role name, `useAccessRole` by
    permission). Role `user` is filtered by the server and again by `filterTasksForRole`.
12. **Difference from the plan:** §4.1 gives 3.9k lines of server Python and 51 routes; `server/*.py` is 3.5k and the routes are
    **53**. Everything else in §4.1 for Tri-Task (never deployed) matches.
13. **Tests run on SQLite only.** `scripts/test-engines.sh` (MySQL and SQL Server) was never run, per HANDBACK,
    so the filtered `legacy_id` index and `DATETIME2(6)` behaviour on Azure SQL are untested. No tests were run
    for this inventory. The new `0002` migration is written with `op.batch_alter_table`, which SQL Server handles as a plain `ADD`.
14. **"Use Template" has no projects.** `Templates.jsx` never loads projects, so the task form it opens cannot satisfy its required
    Project field. Exists at `2be4a95` too (the file is unchanged). Port as is, or fix?
15. **Template example rows do not load.** The shipped `tri-task-import-template.xlsx` names entity "Tricab Australia" and statement areas
    written "Income Statement: Revenue"; a real Tri-Task would need an entity with that exact name, and the areas load only as typed text
    ("not in the Statements list; kept as typed"). Which names does the platform use?
16. **Import is not atomic and has no audit row.** Rows are created one by one; a failure part-way leaves the earlier rows. Only a log line
    records an import. Decide whether the platform wants one transaction and an audit event.
17. **The column choice lives in `localStorage`.** It is per browser, not per person. The platform has no user-preference store; keep it
    in the browser (as the source does) or add one?
18. **The status column filter has no Overdue option**, though the status tabs do (115). Probably an oversight; port as is, or add?
19. **Reminders and emails accept any address that is a Tri-Task member or summary recipient, but the To box accepts any text.** The server refuses
    a stray address with a 400 naming it; the dialog does not check first. Port as is?
20. **Reminder history shows only three rows and no body** (route 46 sends no bodies by design, the dialog shows `slice(0, 3)`).
21. **An AI failure while drafting task emails stops the whole batch** (route 41 returns 502 on the first failed call, so the dialog
    never opens); the reminder dialog instead degrades to a hand-written message.
22. **Dead machine-wide key.** See §7: a developer machine's inherited `OPENROUTER_API_KEY` sends real requests during local runs.
    The platform's `FINANCE_OPENROUTER_API_KEY` is separate by design (Phase 1, Q4), so this does not carry over, but local runs of the
    source must blank the variable.

## 11. Changes since 2be4a95

`git diff 2be4a95..625c900` on `nick-review-20261006`: **25 files changed, 1,758 insertions, 482 deletions**; no file under
`src/components/ui/` or `src/lib/utils.js` differs, so the component kit copied into `tricab-web` at F1.6 is still current.

### Commits

| Commit | Date | Message |
|---|---|---|
| `a1b26f4` | 6 Oct 2026 | Nick's 6 Oct review: one-email reminders, import, column chooser, shared toolbar |
| `625c900` | 6 Oct 2026 | Email popups drawn as Tri-AP's; To editable in the multi-task dialog |

### What changed, by section

| Section | Change |
|---|---|
| §1 Source | Reference is now the review branch; 53 routes (was 51); `server/*.py` 3,453 lines (was 2,929); 120 tests (was 102 by the same count) |
| §2 Routes | **Two new:** `GET /api/tasks/<id>/mail` (46) and `POST /api/import` (47). **Two changed:** `POST /api/ai/reminders` (42) now makes one draft for preparer and reviewer together, answering `{taskName, recipients, subject, body}` instead of `{taskName, drafts[]}`; `POST /api/ai/email-draft` (43) accepts several `to` addresses and answers `recipients[]` as well. Routes 46 to 51 renumbered (old 46 to 49 are now 48 to 51); handler line numbers all moved. New module `server/importer.py` (430 lines) |
| §3 Data | New column `task_tasks.notes` (revision `0002`, `NVARCHAR(MAX)` null). Two new read helpers, no new table, index or constraint. `task_outbound_messages` now has a reader |
| §4 Files | The import file (5 MB, in memory) and the static template `public/tri-task-import-template.xlsx` |
| §5 Roles | No new permission. Import needs `task.tasks.create`, and `task.setup.manage` to create a project |
| §6 Jobs | None added or changed. The migration step now has two revisions |
| §7 External | Reminder and email-draft prompts take several recipients; still five purposes. New dependency `openpyxl>=3.1`. Still no Graph credentials in the app |
| §8 Settings | None added |
| §9 Screens | Shared toolbar with Import and Export; table column chooser; Notes field; reminder dialog rewritten as one message with To, CC and attachments; multi-task email dialog redrawn with To, CC and `Label`/`Input`/`Textarea`; AI panel redrawn; Import dialog; Export CSV now uses the template's columns and a BOM; Projects export; any column sorts. New components `RegisterToolbar`, `ImportDialog`, `AddressInput`, `AttachmentPicker` |
| §10 Questions | Items 1, 3, 4, 9, 12, 13 updated; 14 to 22 new |

### Files changed

Server: `app.py` (+114/-), `importer.py` (new, 430), `llm.py`, `models.py` (+1), `store.py` (+21), `requirements.txt` (+1),
`migrations/versions/0002_task_notes.py` (new), tests `test_ai_mail.py` (reworked for one reminder; +18 tests overall in the branch) and
`test_spreadsheet_import.py` (new, 200 lines, 12 import tests and 1 notes test).
Frontend: `api/base44Client.js`, `lib/exportTasks.js`, `lib/taskStatus.js`, `pages/Home.jsx`, `pages/Projects.jsx`, and in `components/tasks/`:
`AddressInput`, `AttachmentPicker`, `ImportDialog`, `RegisterToolbar` (new); `AiDraftPanel`, `EmailPreviewDialog`, `MailFromSelect`,
`ReminderEmailDialog`, `TaskForm`, `TaskTable` (changed). `public/tri-task-import-template.xlsx` (new, binary).

### Verified by running it

Tri-Task ran locally from `625c900` through `run-local.ps1` behind the local portal (SSO round trip as the script's administrator) against a
fresh scratch database, and was clicked through; 100 screenshots at 1440 by 900. The migration ran (`notes` exists), the import preview,
import, repeat-import and refusal paths behaved as §2 describes, and the reminder dialog opened with the AI unavailable. The test suite was not run.
