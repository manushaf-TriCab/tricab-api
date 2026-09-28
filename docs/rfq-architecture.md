# RFQ: architecture, contracts, permissions and service boundaries

Status: **Phase 1** (contracts, permissions and module skeleton), started 28 Sep 2026 before the Phase 0 exit (see [§8](#8-what-phase-1-did-not-settle)).
Companion to `platform/RFQ-IMPLEMENTATION-PLAN.md` and the Phase 0 evidence in `platform/rfq-phase0/`.
Paired branches: `tricab-api@feature/rfq-module`, `tricab-web@feature/rfq-module`.

This document says where the boundaries are, who enforces them, and which source behaviours were kept or changed. Code is the authority for exact values; the file for each rule is named beside it.

---

## 1. What the module owns

RFQ is a bounded module in the Onboarding sense: `core/src/Rfq/` for domain and application code, `src/Http/Action/Rfq/` for HTTP, and one `Module` class holding the permission and role vocabulary.

It owns intake, extraction results, editable review, persisted revisions, scoped queues, pricing snapshots, exports and the controlled 4js handoff. Mailbox intake, notifications, audit and migrated history join in later phases.

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
 RfqAccessPolicy              scope on every record; out of scope   → 404
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
| `rfq.revision.v1` | `ReviewRevision`, `QuoteHeader` (`rfq.header.v1`), `ParsedLine` (`rfq.line.v1`), `SourceRowRef` (`rfq.source-row.v1`) | `PATCH /api/v1/rfqs/{publicId}` with `expectedRevision` | 2, 4 |
| `rfq.document.v1` | `SourceDocument` | intake, document download | 2.5, 3 |
| `rfq.reader-result.v1` | `ReaderResult` | reads on the detail; resolve proposal | 3 |
| `rfq.pricing.v1` | `PricingSnapshot` | pricing on the saved revision | 4 |
| `rfq.sync-attempt.v1` | `SyncAttemptView` | sync, status, reconcile, retry PDF | 5 |
| `rfq.audit-event.v1` | `RfqAuditEvent` | activity trail | 2.4, 4.5 |
| `rfq.job.v1` | `RfqJob` | internal only; not serialised to the browser | 2.4 |

Conventions carried by all of them:

- **Identifiers.** `publicId` is a UUID and the only id that leaves the server. The source draft id, a platform id and a 4js quote number are three different identifiers and never share a field.
- **People are named, never addressed.** No response carries an email address (invariant 11). The scaffold's `assignee_email` is gone.
- **Money and quantities are decimal strings.** Units are `m` or `U`, the two 4js unit systems. Cuts are kept as `cutLengthMetres` × `cuts` alongside the quoted quantity (invariant 4).
- **Machine output, human decisions and inclusion are separate fields** on a line: `matchStatus`, `approval`, `disposition` (§5.3).
- **Filters narrow and never widen.** `GET /api/v1/rfqs` accepts `q`, `status`, `priority`, `archived` (`exclude`/`include`/`only`), `mine`, `page` and `pageSize` (≤ 100). An unknown value is a 400 `RFQ_QUERY_INVALID`, not an ignored filter. There is no `scope` or `branch` parameter the server trusts.
- **Queue counts are not in `rfq.queue.v1`.** `total` counts what the caller may see under their filters. KPI tiles get their own scoped statistics endpoint in Phase 4.5, not a count derived from one page of rows in the browser.

### 3.2 Parser contract (`rfq-parser.v1`)

`core/src/Rfq/Parser/`. PHP → Python over loopback, JSON:

- **Request** (`ParseRequest`): `contract`, `correlationId`, `deadlineMs`, and either `text` or `documents[]` (`documentPublicId`, display `fileName`, `contentType`, `contentBase64`), plus `options.allowModel` and `options.maxModelCalls`. It names no actor, role, scope or branch. PHP decides `allowModel` from configuration; extracted text cannot turn it on.
- **Response** (`ParseResponse`): `contract`, the same `correlationId`, `lines[]`, `coverage` (`inputRows`, `keptRows`, `excludedRows`, `skippedRows`), `engine` (`version`, `rulesVersion`, `catalogueVersion`), `provider` (`mode`, `modelCalls`, `model`), `degraded` and `notes[]`. It is parsed strictly. The wrong version, a different correlation id or any malformed field is a failure, never a partial result.
- **Coverage must balance.** `isCoverageComplete()` is false unless every input row is kept, excluded or skipped, and an unbalanced parse leaves the RFQ `partial` (§5.2).
- **Errors** are an envelope, `{contract, correlationId, error: {code, message}}`, read into a typed `RfqParserFailure` (§6.3).

Bytes travel inline, so the parser needs no storage credential. Lines stay as arrays at this boundary. Phase 3 maps them into `ParsedLine` after deterministic validation (invariant 2).

## 4. Permissions and scope (plan 1.3)

### 4.1 The catalogue

Seeded by `database/scripts/development/seed-rfq-permissions.sql`, module code `rfq`. It is written but not executed against any shared database. `RfqSeedScriptTest` keeps it equal to `Module::PERSON_PERMISSIONS` + `INTEGRATION_PERMISSIONS`.

| Permission | Source key | Gates | Assurance |
|---|---|---|---|
| `RFQ.ACCESS` | (signed-in staff) | module entry, `/rfqs/me`; **no record** | LOW |
| `RFQ.DRAFT.READ` | `drafts.view` | list, detail, reads, sync status, exports | LOW |
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
| `RFQ.ANALYTICS.READ` | `analytics.team/all` | scoped statistics | LOW |
| `RFQ.REPORTS.READ` | `admin.dashboard` | all-branch review metrics | LOW |
| `RFQ.AUDIT.READ` | `audit.view` | scoped activity log | LOW |
| `RFQ.REVIEWLOG.READ` | `review.view` | raw review sessions, row edits (customer text) | SUBSTANTIAL |
| `RFQ.TECHNICAL.READ` | `technical.view`, `health.detail` | diagnostics | LOW |
| `RFQ.SCOPE.BRANCH` | role `draft_scope = branch` | widen scope to own branch | LOW |
| `RFQ.SCOPE.ALL` | role `draft_scope = all` | widen scope to every branch | LOW |
| `RFQ.ADMIN` | `roles.manage` (module part) | module administration; reads no RFQ | SUBSTANTIAL |
| `RFQ.MAIL.INGEST` | `MAIL_WORKER_TOKEN` | mailbox intake; **service identity only** | SUBSTANTIAL |

`drafts.delete`, a compatibility key that nothing checked, is not carried. Assurance floors are in `PermissionAssurancePolicy`. The four SUBSTANTIAL codes are the ones that act on a live ERP, destroy history, expose raw customer text or administer the module.

**Scope is two extra grants, not a suffix.** The source stores scope on the role. Here, holding neither scope permission means *own*. The alternative (`RFQ.DRAFT.READ.BRANCH` and so on) would triple the route table and still need one resolver.

### 4.2 Roles: a draft for owner review

`seed-rfq-roles.sql` creates four roles and **assigns them to nobody**. They are generated from `Module::ROLE_PERMISSIONS`, and each is the one below plus more:

| Role | Adds |
|---|---|
| `RFQ_SALES` | access, read, create, edit, claim, workflow, comment, archive, sync, reconcile, branch scope |
| `RFQ_SALES_MANAGER` | assign, restore, analytics, activity log |
| `RFQ_ADMIN` | reports, pricing view, all scope, module admin |
| `RFQ_DEVELOPER` | review logs, diagnostics, purge |

`ADMINISTRATOR` holds `RFQ.ADMIN` only (the TriExpense/Onboarding pattern). `EMPLOYEE` and `GUEST` hold nothing, and the permissions seed refuses to run if either ever does. **This is a behaviour change**: the source makes every signed-in staff member a salesperson. Who gets access for the pilot is owner question Q8.

### 4.3 Visibility and allowed actions

`RfqAccessPolicy` is ported from `can_see_draft`, `draft_is_mine` and `allowed_draft_actions`.

- **Effective scope:** `RFQ.SCOPE.ALL` → all; otherwise `RFQ.SCOPE.BRANCH` **and a known branch** → branch; otherwise own. `/rfqs/me` reports both the granted and the effective scope, so "branch-scoped with no branch" is said rather than showing up as an empty queue.
- **Visible:** needs `RFQ.DRAFT.READ`. Then all scope, or owner or assignee is the actor, or branch scope and the same non-empty branch.
- **Out of scope is 404, identical to absent** (source `…cross_branch_concealment`).
- **Allowed actions** come from the server on every record: `view`; `comment`; if archived, `restore` only; otherwise `claim` (unassigned), `assign` (manager), and `workflow`/`edit`/`sync`/`reconcile`/`archive` when the RFQ is assigned to the actor or the actor is a manager, each with its own permission. `purge` is never offered.

Deliberate differences from the source, each covered by a test in `RfqAccessPolicyTest`:

1. **An assignee sees what is assigned to them.** The source matched only the owner, so work assigned across a branch disappeared from the assignee's list.
2. **Unattributed RFQs are visible only at all scope.** The source also showed them to every `drafts.assign` holder in every branch as a triage bucket. That is **Q10**; until it is answered, the narrower rule applies.
3. **No branch means own scope.** The source's reuse of a last-seen branch is not carried: a stale branch is a grant nobody reviewed.
4. **`RFQ_OPEN_DRAFT_ACCESS` is not carried.** If production runs with it on (**Q8** / 0.7 §6.1), matching it means granting `RFQ.SCOPE.ALL` and `RFQ.DRAFT.ASSIGN`: a role decision, not a flag.

**Branch membership has no source** (Q7). `RfqBranchDirectory` is the seam, and `UnconfiguredRfqBranchDirectory` answers null for everybody. Branch scoping becomes real when plan 1.4 settles where branches come from.

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

## 6. Service boundaries and deployment (plan 1.7)

### 6.1 The parser process

This follows the Product Finder pattern (`docs/product-finder/DEPLOYMENT.md`): a persistent Python process in the same App Service zip, bound to loopback, with no nginx location, no second App Service and **no new Azure resource**. RFQ work is heavier than finder lookups, so the plan (3.6) calls for workload isolation from the finder: a separate process and port, sized after measuring long requests. The port, worker count and startup changes are Phase 3.6 decisions and are not made here.

- **Endpoints:** `/v1/parse`, `/v1/health` (process up) and `/v1/ready` (catalogue and rules loaded). `RfqParserClient::isAlive()` and `isReady()` map to them.
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

## 7. Where things are

| Concern | Code | Tests |
|---|---|---|
| Permissions, roles | `core/src/Rfq/Module.php`, `database/scripts/development/seed-rfq-*.sql` | `core/tests/Rfq/Domain/RfqModuleTest.php`, `tests/Rfq/RfqSeedScriptTest.php` |
| Scope and actions | `Domain/Service/RfqAccessPolicy.php` | `RfqAccessPolicyTest.php` |
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
| Q7 | Where branch membership comes from | an `RfqBranchDirectory` implementation; **blocks plan 1.4** |
| Q8 | Who gets pilot access; is `RFQ_OPEN_DRAFT_ACCESS` on | role assignments; possibly all scope |
| Q9 | Hide or withhold pricing | `PricingSnapshot::toArray()` call site; role placement of `RFQ.PRICING.VIEW` |
| Q10 | Unattributed RFQs: manager triage or admin queue | one rule in `RfqAccessPolicy::canSee()` |
| — | Scan-read lines syncing from the editor without approval (§5.3) | `LineApprovalPolicy::syncBlockReason()` |
| — | Navigation fit of `/admin/rfqs` (plan 1.5) | web routes only |
