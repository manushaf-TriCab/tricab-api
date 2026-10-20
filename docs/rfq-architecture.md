# RFQ: architecture, contracts, permissions and service boundaries

Status: **Phase 1** (contracts, permissions and module skeleton), started 28 Sep 2026 before the Phase 0 exit (see [§8](#8-what-phase-1-did-not-settle)).
Companion to `platform/RFQ-IMPLEMENTATION-PLAN.md` and the Phase 0 evidence in `platform/rfq-phase0/`.
Paired branches: `tricab-api@feature/rfq-module`, `tricab-web@feature/rfq-module`.

This document says where the boundaries are, who enforces them, and which source behaviours were kept or changed. Code is the authority for exact values; the file for each rule is named beside it.

---

## 1. What the module owns

RFQ is a bounded module in the Onboarding sense: `core/src/Rfq/` for domain and application code, `src/Http/Action/Rfq/` for HTTP, and one `Module` class holding the permission and role vocabulary.

It owns intake, extraction results, editable review, persisted revisions, queues, pricing snapshots, exports and the controlled 4js handoff. Mailbox intake, notifications, audit and migrated history join in later phases.

**It owns no part of identity.** Records link to a person by `identity.Users.UserId`. The source's `rfq_roles`, `rfq_user_roles`, `rfq_identities` and `sync-directory` are retired, not ported. The source's WordPress `manage_options` backstop, which gave every WordPress administrator every RFQ permission, is not carried.

## 2. Trust boundaries

```text
 Browser (tricab-web)        route guards and allowedActions are USABILITY ONLY
      │  B1 HTTPS + Bearer
      ▼
 RequirePermissionMiddleware  one RFQ.* permission per route        → 403
      ▼
 ResolveRfqActorService       platform user → RfqActor (per request, never cached)
      ▼
 RfqAccessPolicy              every RFQ, read and worked, by permission
      ▼
 RFQ services ──► RfqRepository (Azure SQL, Phase 2; in-memory today)
      │
      ├── B2 loopback ──► rfq_parser (Python, private, Phase 3)
      │                    receives NO actor, role or scope; output untrusted
      ├── B3 ──► private blob storage (documents; generated keys only)
      ├── B4 ──► 4js via durable sync attempts only (Phase 5)
      └── B5 ──► Microsoft Graph via a service identity (Phase 6)
```

| # | Boundary | Enforced by | Refusal |
|---|---|---|---|
| B1 | Browser → API | `RequirePermissionMiddleware`, then `RfqAccessPolicy` | 401 / 403 / 404 |
| B2 | PHP → parser | Loopback bind + internal bearer token; `ParserContract::VERSION` on both sides | typed `RfqParserFailure` |
| B3 | API → documents | Private container; `SourceDocument` never exposes a key or path | — |
| B4 | API → 4js | `SyncAttemptTransitionPolicy`; one attempt per immutable revision | 409 while one is open or uncertain |
| B5 | Worker → Graph | `RFQ.MAIL.INGEST`, held by a service identity and no person | 403 |

**The SPA is not a boundary.** It hides what the server would refuse. Every screen's data comes from a gated endpoint that re-checks.

## 3. Contracts (plan 1.1)

Each contract has a version string in its class docblock. A breaking change adds a new version rather than editing the old one.

### 3.1 Public API

| Contract | Class | Endpoint | Phase |
|---|---|---|---|
| `rfq.access.v1` | `RfqAccessSummary` | `GET /api/v1/rfqs/me` | **1 (live)** |
| `rfq.queue.v1` | `RfqQueuePage`, `RfqSummary` (`rfq.summary.v1`) | `GET /api/v1/rfqs` | **1 (live)** |
| `rfq.detail.v1` | `RfqDetail` | `GET /api/v1/rfqs/{publicId}` | **1 (live)** |
| `rfq.parsed.v1` | `ParsedRfq` | `POST /api/v1/rfqs/parse` (Match Text; stores nothing, parity plan D3) | **E2** |
| `rfq.created.v1` | `CreatedRfq` | `POST /api/v1/rfqs` `{parseToken, header, lines}` (Save as Draft; the rows are the sealed parse, `ParseSeal`) | **E2** |
| `rfq.revision.v1` | `ReviewRevision`, `QuoteHeader` (`rfq.header.v1`), `ParsedLine` (`rfq.line.v1`), `SourceRowRef` (`rfq.source-row.v1`) | `PATCH /api/v1/rfqs/{publicId}` with `expectedRevision` and an optional `header` (`QuoteHeaderEdit`) | 2, 4 |
| `rfq.uploaded.v1` | `UploadedRfq` | `POST /api/v1/rfqs/upload` (files → draft, reads queued) | **3.3** |
| `rfq.document.v1` | `SourceDocument` | detail, `GET /rfqs/{id}/reads`, `GET /rfqs/{id}/documents/{documentId}` | **2.5, 3** |
| `rfq.reader-result.v1` | `ReaderResult` | detail, `GET /rfqs/{id}/reads`, `POST /rfqs/{id}/reads/{readId}` (accept/dismiss) | **3.5** |
| `rfq.screenshot-text.v1` | `ScreenshotText` | `POST /api/v1/rfqs/ocr` (Scan Screenshot: vision OCR text for the rep to review; no lines, nothing stored; parity plan E4.3) | **E4.3** |
| `rfq.line-pricing.v1` | `LinePricing`, `RfqPricingView` | `pricing` on each `rfq.line.v1`, and on `rfq.readiness.v1` as `pricing` (lines, quote total, unpriced count). **Withheld** from anyone without `RFQ.PRICING.VIEW` (Q9, decided 2 Oct); margin and sell-price decisions are refused without it | **E5** |
| `rfq.product-summary.v1` | `RfqExportView` | `POST /api/v1/rfqs/summary` (unsaved) and `POST /api/v1/rfqs/{id}/summary`: the Product Summary over the lines a save would write, in code order. No cost, margin or price in it. The same view writes `quote-lines.csv` (`POST .../exports/quote-lines`) and `summary-quote-lines.csv` (`.../exports/summary-lines`): UTF-8 with BOM, CRLF, formula payloads neutralised; sell price and line total columns only for `RFQ.PRICING.VIEW` holders who send `includePrices` (parity plan E7.1) | **E7.1** |
| `rfq.accessory-candidates.v1` | `AccessoryCandidates` | `POST /api/v1/rfqs/accessories` and `POST /api/v1/rfqs/{id}/accessories` (`RFQ.PRICING.VIEW`): the accessories the `RfqAccessoryEngine` proposes for the cable lines, priced as the lines would be, each with a sealed token (`AccessorySeal`, signed under a key derived from the parser token, tied to the person, 12 h). A save adds one as a new line naming its token (`accessoryToken`); the server's row is the line. **The engine is a port with no implementation yet** (tc-web's `ProductConfigurator` rules over the legacy `tc` database): until one is bound the answer is `engineAvailable: false` with a note, as the source's is without its engine (parity plan E7.2) | **E7.2** |
| `rfq.aliflex-result.v1` | `AliflexResult` | `POST /api/v1/rfqs/{id}/aliflex` (`RFQ.DRAFT.CREATE`): a new draft with the agreed ALI-FLEX alternative of a finished quote (synced to 4js with no change since, or wholly imported), 201; 200 with no draft when no line matches; 409 `RFQ_ALIFLEX_NOT_ELIGIBLE`. The saved quote is converted by the parser (`/v1/aliflex`, the source's `aliflex-map/2`), only the changed lines are priced afresh, the source is never written and nothing goes to 4js. `rfq.detail.v1` carries `aliflex: {eligible, reason}` (parity plan E7.4) | **E7.4** |
| `rfq.pricing.v1` | `PricingSnapshot` | pricing on the saved revision | 4 |
| `rfq.sync-attempt.v1` | `SyncAttemptView` | `syncAttempts` on the RFQ detail (newest 20) and inside `rfq.sync-result.v1`; reconcile and retry PDF are not built | 5 |
| `rfq.sync-result.v1` | `SyncOutcome` | `POST /api/v1/rfqs/{id}/sync` (`RFQ.QUOTE.SYNC`): `succeeded` (200) or `partial`/`failed`/`uncertain` (502), with the quote, the lines by number and code, exclusions, fallback margins and the attempt; off or unconnected is 503 with nothing sent (parity plan E7.3) | **E7.3** |
| `rfq.audit-event.v1` | `RfqAuditEvent` | activity trail | 2.4, 4.5 |
| `rfq.job.v1` | `RfqJob` | internal only; not serialised to the browser | 2.4 |

Conventions carried by all of them:

- **Identifiers.** `publicId` is a UUID and the only id that leaves the server. The source draft id, a platform id and a 4js quote number are three different identifiers and never share a field.
- **People are named, never addressed.** No response carries an email address (invariant 11). The scaffold's `assignee_email` is gone.
- **Money and quantities are decimal strings.** Units are `m` or `U`, the two 4js unit systems. Cuts are kept as `cutLengthMetres` × `cuts` alongside the quoted quantity (invariant 4).
- **Machine output, human decisions and inclusion are separate fields** on a line: `matchStatus`, `approval`, `disposition` (§5.3).
- **Filters only narrow.** `GET /api/v1/rfqs` accepts `q`, `status`, `priority`, `branch` (codes, upper-cased; `none` = no branch), `archived` (`exclude`/`include`/`only`), `mine`, `assignee` (a user id; `none` = unassigned), `fourJs` (`sent`/`notSent`: whether the RFQ carries a 4js quote number), `page` and `pageSize` (≤ 100). An unknown value is a 400 `RFQ_QUERY_INVALID`, not an ignored filter. None of them is an access decision: everyone who reads RFQs reads all of them (§4.3). The page carries `branches`, the codes to filter by, and `assignees` (`{userId, name}`), the people RFQs are assigned to; an assignee address that matches no user is not listed.
- **Queue counts are not in `rfq.queue.v1`.** `total` counts the RFQs under the filters. KPI tiles get their own statistics endpoint in Phase 4.5, not a count derived from one page of rows in the browser.

### 3.2 Parser contract (`rfq-parser.v1`)

`core/src/Rfq/Parser/`. PHP → Python over loopback, JSON:

- **Request** (`ParseRequest`): `contract`, `correlationId`, `deadlineMs`, and either `text` or `documents[]` (`documentPublicId`, display `fileName`, `contentType`, `contentBase64`), plus `options.allowModel` and `options.maxModelCalls`. It names no actor, role, scope or branch. PHP decides `allowModel` from configuration; extracted text cannot turn it on.
- **Response** (`ParseResponse`): `contract`, the same `correlationId`, `lines[]`, `coverage` (`inputRows`, `keptRows`, `excludedRows`, `skippedRows`), `engine` (`version`, `rulesVersion`, `catalogueVersion`), `provider` (`mode`, `modelCalls`, `model`), `degraded` and `notes[]`. It is parsed strictly. The wrong version, a different correlation id or any malformed field is a failure, never a partial result.
- **Coverage must balance.** `isCoverageComplete()` is false unless every input row is kept, excluded or skipped, and an unbalanced parse leaves the RFQ `partial` (§5.2). `coverage.measured` is false where rows cannot be accounted for (PDFs, anything a model read), which is never complete. The counts come from `rfq_parser/accounting.py`; the draft keeps them in `email_meta.parse.coverage`, which the queue reads.
- **Documents** (`POST /v1/parse` with `documents[]`, plan 3.3) are checked by `rfq_parser/intake.py` before any library opens them (type from the bytes, ZIP, sheet and page caps) and parsed in a child process killed at the deadline. Each answers `documents[].readerRoute`: whether a model reader will re-read it, which is PHP's cue to queue a reader job.
- **Reads** (`POST /v1/read`, `ReadRequest`/`ReadResponse`, plan 3.5): one document through the source's `read_one_file` (terra; vision OCR behind it for images), matched with a message context so the translator runs. A document with no reader switched on is `unsupported_format` and the job is stood down; a read with no lines is a typed failure (`provider_rejected`: credentials or a refused request, no retry; `provider_unavailable`: quota, 5xx, unreachable, retried up to 3 attempts). No provider body reaches any message; the parser logs it under the correlation id.
- **Errors** are an envelope, `{contract, correlationId, error: {code, message}}`, read into a typed `RfqParserFailure` (§6.3).

Bytes travel inline, so the parser needs no storage credential. Lines stay as arrays at this boundary. Phase 3 maps them into `ParsedLine` after deterministic validation (invariant 2).

## 4. Permissions and visibility (plan 1.3)

### 4.1 The catalogue

Seeded by `database/scripts/development/seed-rfq-permissions.sql`, module code `rfq`. It is written but not executed against any shared database. `RfqSeedScriptTest` keeps it equal to `Module::PERSON_PERMISSIONS` + `INTEGRATION_PERMISSIONS`.

| Permission | Source key | Gates | Assurance |
|---|---|---|---|
| `RFQ.ACCESS` | (signed-in staff) | module entry, `/rfqs/me`; **no record** | LOW |
| `RFQ.DRAFT.READ` | `drafts.view` | list and open **every** RFQ, reads, sync status, exports; **every employee** | LOW |
| `RFQ.DRAFT.CREATE` | `drafts.view` | intake. **New**: the source lets read create | LOW |
| `RFQ.DRAFT.EDIT` | `drafts.edit` | save revision, resolve reads | LOW |
| `RFQ.DRAFT.CLAIM` | `drafts.claim` | claim unassigned | LOW |
| `RFQ.DRAFT.ASSIGN` | `drafts.assign` | assign, bulk, priority, due date; the *manager* predicate | LOW |
| `RFQ.DRAFT.WORKFLOW` | `drafts.workflow` | workflow status (§5.1) | LOW |
| `RFQ.DRAFT.COMMENT` | `drafts.comment` | comment | LOW |
| `RFQ.DRAFT.ARCHIVE` | `drafts.archive` | archive with reason | LOW |
| `RFQ.DRAFT.RESTORE` | `drafts.restore` | restore | LOW |
| `RFQ.DRAFT.PURGE` | `drafts.delete_permanent` | **nothing yet**: no retention policy | SUBSTANTIAL |
| `RFQ.QUOTE.SYNC` | `drafts.push` | sync, retry PDF | LOW |
| `RFQ.QUOTE.RECONCILE` | `drafts.push` | reconcile an uncertain attempt | SUBSTANTIAL |
| `RFQ.PRICING.VIEW` | `pricing.view` | cost and margin; hide vs withhold is **Q9** | LOW |
| `RFQ.ANALYTICS.READ` | `analytics.team/all` | queue statistics | LOW |
| `RFQ.REPORTS.READ` | `admin.dashboard` | all-branch review metrics | LOW |
| `RFQ.AUDIT.READ` | `audit.view` | activity log | LOW |
| `RFQ.REVIEWLOG.READ` | `review.view` | raw review sessions, row edits (customer text) | SUBSTANTIAL |
| `RFQ.TECHNICAL.READ` | `technical.view`, `health.detail` | diagnostics | LOW |
| `RFQ.ADMIN` | `roles.manage` (module part) | module administration; reads no RFQ | SUBSTANTIAL |
| `RFQ.MAIL.INGEST` | `MAIL_WORKER_TOKEN` | mailbox intake; **service identity only** | SUBSTANTIAL |

`drafts.delete`, a compatibility key that nothing checked, is not carried. Assurance floors are in `PermissionAssurancePolicy`. The four SUBSTANTIAL codes are the ones that act on a live ERP, destroy history, expose raw customer text or administer the module.

**There is no scope.** The source's role-level `draft_scope` (own / branch / all) is not carried, and nor are the `RFQ.SCOPE.BRANCH` / `RFQ.SCOPE.ALL` grants an earlier draft of this catalogue had (owner decision, 28 Sept: as in 4js, anyone can see any quote).

### 4.2 Roles

`seed-rfq-roles.sql` creates three roles and **assigns them to nobody**; people are assigned separately (pilot: 2 admins, 27 sales on tricab-mel, 28 Sept). They are generated from `Module::ROLE_PERMISSIONS`, and each is the one above plus more:

| Role | Adds |
|---|---|
| `RFQ_SALES` | access, read, create, edit, claim, workflow, comment, archive, sync, reconcile |
| `RFQ_ADMIN` | assign, restore, analytics, activity log, reports, pricing view, module admin |
| `RFQ_DEVELOPER` | review logs, diagnostics, purge |

**There is no sales manager** (owner decision, 28 Sept: sales and admin are enough). What the source's manager added sits with `RFQ_ADMIN`, so an admin is the "manager" of `RfqAccessPolicy::isManager()` (a holder of `RFQ.DRAFT.ASSIGN`): assigning, returning work to `new`, priority and due date. The seed retires an `RFQ_SALES_MANAGER` an earlier run created: deactivated with its `RFQ.*` grants, never deleted.

`ADMINISTRATOR` holds `RFQ.ADMIN` only (the TriExpense/Onboarding pattern). **`EMPLOYEE` holds `RFQ.ACCESS` and `RFQ.DRAFT.READ`** (`Module::EMPLOYEE_PERMISSIONS`): every employee reads every RFQ, warehouse staff who sign in by emailed code included, and does nothing else. `GUEST` holds nothing. The permissions seed refuses to run if `EMPLOYEE` ever holds more or `GUEST` holds anything. The source made every signed-in staff member a salesperson; that wider grant is still not carried, and who works RFQs in the pilot is owner question Q8.

### 4.3 Visibility and allowed actions

`RfqAccessPolicy` is ported from `draft_is_mine` and `allowed_draft_actions`.

- **Visible: every RFQ, to anyone holding `RFQ.DRAFT.READ`** (owner decision, 28 Sept: as in 4js, anyone can see any quote). Branch is a queue filter, never a limit. Production already behaves this way: it runs `RFQ_OPEN_DRAFT_ACCESS=true`, which makes the source's scope "all". An unknown or malformed id is 404.
- **Allowed actions** come from the server on every record: `view`; `comment`; if archived, `restore` only; otherwise `claim` (unassigned), `assign` (admin, `RFQ.DRAFT.ASSIGN`), and `workflow`/`edit`/`sync`/`reconcile`/`archive` for **anyone** holding that action's permission (owner decision, 28 Sept: anyone works any RFQ, as production's `RFQ_OPEN_DRAFT_ACCESS=true` allows). Admins keep the manager's wider workflow moves. `purge` is never offered. An employee who holds only read gets `view`.
- **The sales rep is the last worker** (owner decision, 28 Sept). Saving an RFQ's content, or sending it to 4js, makes the worker its assignee and puts their 4js operator code on the quote as `author` (`SalesRepAttribution`). The code comes from `RfqOperatorDirectory` (legacy `descript` desc_type 36, via `LegacyStaffUserMappings`, then the email login with the source's aliases). A worker with no confirmed code never overwrites a real author; a blank author gets the source's upper-cased-login fallback. The save's activity records the assignee change and `author_source`. The creator (`created_by_email`) stays as history. Live: 28 of the 35 staff on live RFQs have a confirmed code. The source set the author only at creation, so this is a deliberate change.

**Branch membership has no source yet** (Q7). It no longer decides access. It is the default for the branch filter and the branch a new RFQ is stamped with, so it matters from Phase 4. `RfqBranchDirectory` is the seam; `UnconfiguredRfqBranchDirectory` answers null for everybody.

## 5. State machines (plan 1.2)

The source keeps six values in one `status` column. Three of them are not workflow. Here the four concerns are separate, each with a pure policy class and tests.

### 5.1 Workflow: `WorkflowStatus`, `WorkflowTransitionPolicy`

`new` · `in_progress` · `blocked` · `completed`. Archive is a flag, not a status.

| Actor | May set |
|---|---|
| assignee (without `RFQ.DRAFT.ASSIGN`) | `in_progress`, `blocked` |
| manager (`RFQ.DRAFT.ASSIGN`) | `new`, `in_progress`, `blocked`; also priority and due date |
| system | `in_progress` (claim, first edit, a new revision after completion), `completed` (a succeeded sync) |

`blocked` needs a reason. An archived RFQ's workflow does not change. Re-setting the current status is allowed.

**Tightening:** nobody sets `completed` by hand. The source's `update_workflow` accepts `synced` from a manager, which marks a quote as sent that never reached 4js.

Source mapping for Phase 7 (`WorkflowStatus::SOURCE_STATUS_MAP`): `new`/`pending` → `new`; `in_progress`, `ready_to_sync`, `failed`, `push_failed` → `in_progress`; `blocked` → `blocked`; `synced` → `completed`. The sync half of `failed`/`synced` travels with the migrated sync attempts, and readiness is recomputed.

### 5.2 Extraction and reads: `ReaderJobStatus`, `ReaderJobTransitionPolicy`, `ExtractionStatePolicy`

A read is `queued` → `running` → `applied` | `proposed` | `failed`, and may be `superseded` while it is queued or running. A stale `running` read goes back to `queued` until it has used 3 attempts, then becomes `failed`. The source's `done` is split into `applied` (the rows replaced an untouched draft) and `proposed` (the rep had edited). Only a person moves `proposed` → `accepted`/`dismissed` or `failed` → `dismissed`.

The RFQ's `ExtractionState` is derived, never set. In order of precedence: `attention` (a failed read or a standing proposal), `reading`, `partial` (coverage does not balance), `complete`. Everything except `complete` blocks the sync.

### 5.3 Lines: `LineMatchStatus`, `LineApproval`, `LineDisposition`, `LineInputType`, `LineApprovalPolicy`

- `matchStatus` is the parser's conclusion. `needs_review`, `ambiguous`, `unresolved`, `no_match` and `accessory` block the sync until a person approves. `needs_clarification` is **advisory**, as production runs it since 25 Sept. That is **Q1**, and one constant changes if production is reverted.
- `approval` is a person's decision. The system may reset it to `unreviewed` and never sets `approved` (invariant 2).
- `disposition` is whether the line is in the quote. Excluded lines are kept with their reason, and approval cannot override exclusion. A heading with no product code is non-commercial and excluded; a heading with a code is not.
- Ungrounded input (`vision_ocr`, `reader_ungrounded`, `unknown`) needs approval, **except** when saved through the editor. That is the source's `reviewed=True` on draft sync. It is preserved as found and **flagged for owner review**: a scan-read line can sync from the editor without anyone approving it.

This is the line gate, not readiness. Full readiness (header, prices, quantities, reads) is recomputed on the server from the saved revision in Phases 4–5.

### 5.4 Sync attempts: `SyncAttemptState`, `SyncHeaderState`, `SyncAttemptTransitionPolicy`

This is ported closely: it is the best-evidenced part of the source (`test_durable_sync`).

- `queued` → `running` → `succeeded` | `failed` | `uncertain`; `queued` → `superseded`; the sweep moves a stale `running` attempt back to `queued`.
- **Uncertain** means the header was `sent` or `unknown` and no quote number came back. It is never retried and never superseded, and no new attempt may start for that RFQ until a person reconciles it: adopt the quote found in 4js (header `confirmed`), or confirm there is none (header `pending`, send again).
- `failed` is refused while a quote may exist without a number. `succeeded` needs a confirmed header.
- **One difference:** the source blocks a new attempt only while one is uncertain, and relies on a per-draft sync lock for queued and running. The platform has no second lock, so the open attempt is the lock.

Unreadable stored values fall back to the reading that hides nothing and claims nothing (`Vocabulary::fromStorage()`). For example, an unreadable attempt state reads as `uncertain`, and an unreadable read status as `failed`.

### 5.5 Sync to 4js (parity plan E7.3)

`RfqSyncService` is the only code that asks 4js to write, and it does so through `RfqQuoteGateway`, an interface of the three requests the source makes (`QOTHDR`, `QOTITEM`, `QOTPDF`). **Every test uses a recording fake. No live adapter is wired in this repository**, because the request and response formats are known here only from the source's code, 4js has no idempotency key and no verified quote lookup, and the owner's decision is that the old tc-web app stays the production quote owner during the pilot. The default binding is `UnwiredRfqQuoteGateway`, which is not connected.

- **Two switches, both refusing with nothing sent:** `RFQ_SYNC_ENABLED` must be exactly `true` (503 `RFQ_SYNC_DISABLED` otherwise, before anything is read), and a connected gateway must be bound (503 `RFQ_SYNC_UNAVAILABLE` otherwise). `GET /api/v1/rfqs/me` carries `sync: {available, reason, code}` so the editor draws the control disabled with the reason.
- **The plan is the parser's** (`POST /v1/sync-plan`, `rfq_parser/quote_sync.py`, the source's `prepare_quote_push` checked on 1,200 random row sets against the pinned source with no difference): readiness with `reviewed=True`, the header, the priced lines, exclusions, and the 30% fallback margin where the customer has no rule. It is made from the **saved** revision; the browser sends only `expectedRevision`.
- **The ledger** (`RfqSyncStore`, in `AzureSqlRfqDraftStore`): `begin` locks the draft row, checks the revision, archive, an open or uncertain attempt (`SyncAttemptTransitionPolicy::mayEnqueue`), settled reads, and a quote already in 4js, applies the sales rep rule (`SalesRepAttribution`: the worker becomes the assignee and the author), takes the sync lock, and writes the attempt (`running`, header `pending`, the plan frozen as compact sorted JSON, its SHA-256, the correlation id and a local idempotency key `draft:revision:sha`) with `sync_queued`, **before 4js is contacted**. The header is `sent` before its request and `confirmed` with its number before the first line; every line is checkpointed; `finishAttempt` settles the attempt and the draft in one transaction (`succeeded` is the system's `completed`; anything else leaves the draft `failed` with the reason and keeps the quote number so a retry raises a new version of the same quote) and writes `sync_finished`.
- **What is not claimed:** at most once by local record, not exactly once. A header that did not complete (or an adapter that threw) is `uncertain` and holds the RFQ (409 `RFQ_SYNC_NEEDS_RECONCILIATION`); a `running` attempt untouched for 15 minutes is closed at the next sync (`sweepStale`: `uncertain` if the header was in flight, else `failed` with the reason). **Not built:** reconciling an uncertain attempt, resuming a half-sent one, retrying only the PDF, a scheduler for the sweep, and the summary quote (`/api/push-to-4js`), which has no draft to record against.

## 6. Service boundaries and deployment (plan 1.7)

### 6.1 The parser process

This follows the Product Finder pattern (`docs/product-finder/DEPLOYMENT.md`): a persistent Python process in the same App Service zip, bound to loopback, with no nginx location, no second App Service and **no new Azure resource**. RFQ work is heavier than finder lookups, so the plan (3.6) calls for workload isolation from the finder: a separate process and port, sized after measuring long requests. The port, worker count and startup changes are Phase 3.6 decisions and are not made here.

- **Endpoints:** `/v1/parse`, `/v1/read`, `/v1/ocr`, `/v1/price`, `/v1/products/styles`, `/v1/readiness`, `/v1/export`, `/v1/sync-plan`, `/v1/aliflex`, `/v1/products/search`, `/v1/health` (process up) and `/v1/ready` (catalogue and rules loaded). `RfqParserClient::isAlive()` and `isReady()` map to the last two.
- **Reader jobs** are PHP's (`RfqReaderService`, `bin/run-rfq-reader-jobs.php`): the worker claims a draft's queued reads, sends each document's bytes to `/v1/read` with the 600 s background deadline (its own client, with a socket to match), and replaces an untouched draft's rows for that file or stores them as a proposal. Nothing schedules it yet. gunicorn's worker timeout is 660 s so a read is never killed mid-answer.
- **Documents** are kept in a private blob container (`BLOB_CONTAINER_RFQ`, default `rfq-documents`) under `drafts/<draft>/<document>`, with a row in `rfq_documents` (`create-rfq-documents-schema.sql`, not yet applied). Downloads stream through the API for anyone who can read the RFQ.
- **Internal authentication:** loopback keeps the parser off the network. A bearer token (`RFQ_PARSER_INTERNAL_TOKEN`, an app setting, never logged and never sent to the browser) keeps other processes on the instance from using it. The parser authorizes nothing else: it gets no actor.
- **Today:** `UndeployedRfqParserClient` refuses every parse as `unavailable`. "No lines" is never the answer to "no parser".

### 6.2 Deadlines and limits (`RfqServiceLimits`, provisional until plan 8.3)

| Limit | Value | Basis |
|---|---|---|
| Interactive parse deadline | 60 s | under the source bridge's 100 s, so the parser says `timeout` before the socket is abandoned |
| Background read deadline | 600 s per document | source reader timeout |
| Transport grace | +5 s on the socket | the parser needs time to answer `timeout` |
| Document / image | 20 MB / 8 MB | source image cap 8 MB; the source had no document cap |
| Documents per intake | 10 | |
| Pasted text | 200,000 characters | |
| Lines per revision | 2,000 | covers the 175+ and 307+ line measurement set with margin |
| Save body | 8 MB | **needs nginx raised** (below) |
| Model calls per parse | 50 | source `OPENROUTER_MAX_CALLS_PER_REQUEST` floor |

Anything slower than the interactive deadline is **enqueued as a job, never waited on**. An HTTP timeout must not lose work or cause a second submission.

**nginx and PHP limits.** nginx's 1 MB default was the boundary behind the source's 413 on saves above about 175 lines. PR #21 (`fix/app-service-request-limits`) allows 25 MB on `/api/v1/rfqs` only and passes PHP `post_max_size=26M` / `upload_max_filesize=20M` through `fastcgi_param PHP_VALUE` (a `.user.ini` is read too late for `post_max_size`). Every other route stays at 1 MB. A chunked body is still held to 1 MB, because nginx checks it in `@tricab_api`; browsers send Content-Length. Verified against nginx 1.22 + php-fpm 8.3 in Docker on 28 Sept; the live measurement is plan 8.3.

### 6.3 Errors and correlation

- **Public errors** use the platform envelope `{success: false, error: {code, message}}`. RFQ codes so far: `RFQ_QUERY_INVALID` (400), `RFQ_NOT_FOUND` (404).
- **Parser failures** are typed (`RfqParserFailure`). `unavailable`, `timeout` and `provider_unavailable` are retryable. `invalid_request`, `unsupported_format` (415), `input_too_large` (413), `provider_rejected`, `contract_mismatch` and `internal_error` are not. Messages are safe to show a person and never carry document content, provider bodies or credentials.
- **Correlation IDs** (`CorrelationId`): an inbound `X-Correlation-Id` is kept only if it is 8–64 plain characters, and replaced otherwise, so a client-chosen value never reaches a log line unchecked. The ID travels to the parser, workers and provider calls, and is stored on jobs and sync attempts. The platform has no request-ID middleware yet; RFQ creates its IDs where work starts.

### 6.4 Queue and table write ownership: a proposal

The source's Python store writes every table. The plan's rule is **one writer per table or job, decided in Phase 1**. Proposal:

| Data | Writer | Notes |
|---|---|---|
| RFQs, revisions, lines, header, documents metadata, comments, audit | **PHP** (`RfqRepository`, Phase 2) | revision + audit + outbox in one transaction |
| Blob content | **PHP** | the parser receives bytes and never writes storage |
| Reader jobs (claim, lease, result) | **PHP worker** calling the parser | the parser is a function; it holds no queue state |
| Parse results | **PHP**, from the parser's response | stored only after validation |
| Sync attempts, 4js calls | **PHP worker** (Phase 5) | the platform attempt ledger (`create-quote-write-idempotency-schema.sql` exists) |
| Notification outbox, mail dedupe | **PHP worker** (Phase 6) | with a claim step; the source's drain had none |
| Correction memory | **PHP**, from the persisted revision only | invariant 10 |

So **Python owns no table**, and the source's import-time DDL and store construction do not come across (plan 3.1). This is a proposal. Two facts it depends on are owner questions: which store is live (**Q5**: MySQL since 11 Sept, or SQLite), which shapes the Phase 7 translator but not this split; and who owns the mailbox (**Q6**: WebJob, Logic App or both). For the pilot the old application keeps the mailbox, so the Phase 6 rows wait for that answer.

### 6.5 Storage schema: the live MySQL shape (owner decision, 28 Sept)

The platform's RFQ tables **follow `tc-mysql-live` table for table**, so the data moves across unchanged. `database/scripts/development/create-rfq-schema.sql` creates the 15 tables in `[rfq]` with MySQL's table, column, index and foreign-key names (`rfq.rfq_draft_quotes`, `rfq.rfq_correction_memory`, …). `drop-rfq-legacy-tables.sql` first removes the seven empty 5 Aug tables, and refuses if any holds a row.

- **Type mapping:** `varchar(n)` → `NVARCHAR(n)`, `text`/`longtext` → `NVARCHAR(MAX)`, `char(n)` → `CHAR(n)`; the numeric types are unchanged. Timestamps stay ISO text, as MySQL has them. There are no `ISJSON` checks, because 18 live row snapshots hold Python's `NaN`.
- **Proof of fit (28 Sept, local SQL Server 2022 in Docker, `SQL_Latin1_General_CP1_CI_AS` like `tricab-mel`):** all 68,482 live rows loaded from one read-only MySQL snapshot, and every row's SHA-256 matched. A deliberate one-space change was caught.
- **Consequences for the design above:** the MySQL shape has no separate revision, comment or document tables. `rfq_draft_quotes.revision` is the revision counter, and history lives in `rfq_draft_activity` and `rfq_draft_row_edits`. Platform needs the source lacks (public IDs, document metadata, the migration ledger) are **added alongside** as new tables or nullable columns, never by reshaping these 15. The §6.4 writers map onto them: RFQs/lines/audit → `rfq_draft_quotes`, `rfq_draft_rows`, `rfq_draft_activity`, `rfq_draft_row_edits`; reader jobs → `rfq_reader_jobs`; sync → `rfq_sync_attempts`; outbox → `rfq_notification_outbox`; mail dedupe → `rfq_mail_audit`.
- **The store (plan 2.4):** `RfqDraftStore` / `AzureSqlRfqDraftStore` write these tables with the source's storage behaviour ported exactly (`core/src/Rfq/Storage`: line identity, parser snapshot, change log, Python's JSON and number text), proven against the source's own output and all live lines. One transaction per write; a save locks the draft row and refuses a stale revision. The status a save leaves is the caller's (readiness, plan 5.3). `rfq_sync_attempts.payload` is written compact by the source (it is hashed); Phase 5 must match that.
- **The queue (plan 2.4):** `AzureSqlRfqRepository` reads these tables for `rfq.queue.v1` / `rfq.detail.v1`, with no scope clause (everyone who reads sees every RFQ) and a `branch` filter. People are matched by email once (identity primary address or verified alias; an address two users claim maps to nobody), which gives each record its owner and assignee: who may *work* it, and the `mine` filter. `StoreProjection` holds the translations: public id = draft id as a UUID; reference `RFQ-` + 8 hex; channel; reader `done` → `proposed`/`applied`; the status map. On live data, 29 of 35 addresses match a user; the 6 that do not own 60 drafts, which everyone still sees, and which only a manager can work until they are assigned.
- **Access tables:** `rfq_roles`, `rfq_user_roles` and `rfq_identities` are carried for the history they hold. Platform access still comes from `RFQ.*` permissions (§4); they grant nothing.

## 7. Where things are

| Concern | Code | Tests |
|---|---|---|
| Permissions, roles | `core/src/Rfq/Module.php`, `database/scripts/development/seed-rfq-*.sql` | `core/tests/Rfq/Domain/RfqModuleTest.php`, `tests/Rfq/RfqSeedScriptTest.php` |
| Visibility and actions | `Domain/Service/RfqAccessPolicy.php` | `RfqAccessPolicyTest.php` |
| State machines | `Domain/Service/*TransitionPolicy.php`, `ExtractionStatePolicy.php`, `LineApprovalPolicy.php` | `core/tests/Rfq/Domain/*` |
| Contracts | `Application/DTO/*`, `Parser/*` | `core/tests/Rfq/Parser/ParserContractTest.php` |
| Endpoints | `src/Http/Action/Rfq/*`, `AppBuilder::registerRfqRoutes()` | `tests/Http/RfqEndpointsTest.php`, `tests/Http/RfqRouteTableTest.php` |

## 8. What Phase 1 did not settle

Phase 1 started before the Phase 0 exit, on the owner's instruction (28 Sep 2026). These are open, and each names what changes when it is answered:

| Q | Question | What moves |
|---|---|---|
| Q1 | Keep or revert the live `needs_clarification` change | `LineMatchStatus::BLOCKING`; one test flips |
| Q5 | Which store is live | **answered 28 Sep: MySQL (`tc-mysql-live`)**; `MAIL_AUTO_PUSH_4JS` and `RFQ_OPEN_DRAFT_ACCESS` are both on. The translator reads live MySQL; the 7 empty Azure SQL `rfq.*` tables from 5 Aug are left untouched |
| Q6 | Who owns the mailbox | the Phase 6 rows of §6.4 |
| Q7 | Where branch membership comes from | an `RfqBranchDirectory` implementation; the default branch filter and new-RFQ branch, **no longer access** |
| Q8 | Who works RFQs in the pilot | **Answered 28 Sept:** every employee reads every RFQ; anyone with the permission works any RFQ; the last worker is the sales rep; roles are sales and admin only. Assigned on tricab-mel: `RFQ_ADMIN` 2, `RFQ_SALES` 27 (`platform/rfq-pilot-roles/`) |
| Q9 | Hide or withhold pricing | **Decided 2 Oct 2026 (assistant's default, owner to confirm): withhold.** `ParsedLine::toArray($withPricing)` and `RfqReadinessView::toArray($withPricing)`; the actions pass `$actor->can(PRICING_VIEW)`. One call site each to reverse |
| Q10 | Unattributed RFQs: manager triage or admin queue | **answered 28 Sept: moot, everyone sees every RFQ**; `branch=none` filters them |
| — | Scan-read lines syncing from the editor without approval (§5.3) | `LineApprovalPolicy::syncBlockReason()` |
| — | Navigation fit of `/admin/rfqs` (plan 1.5) | web routes only |
