# TriCab Platform — Pre-release Security Review

**Date:** 2026-11-05 · **Scope:** `tricab-api` (Slim 4 PHP API, `core/`, Python services, deploy/CI) and `tricab-web` (React SPA) at their current `main` commits (`7487fc1`, `d666d4d`).
**Method:** Static, read-only code review by seven parallel reviewers (authentication, route authorization, object-level access, injection & files, internal modules, secrets/CI/infra, frontend). The High findings were re-checked by hand against the code. No code was run against a live environment.

## Summary

The foundations are solid:
- No SQL injection, command injection, XXE or user-controlled SSRF was found.
- Customers cannot see other customers' quotes, orders or carts, and they cannot change prices.
- Entra token validation is correctly pinned.
- CORS uses an exact allow-list.
- Untrusted email HTML is sanitised twice.
- No live secrets were found in either repo.

The risks are concentrated in a few places:
1. **Staff can sign in without going through Entra**, so MFA and Conditional Access can be bypassed.
2. **Weak spots in admin and staff tooling** let one capability grow into a much bigger one: storage read links, user management, act-as and expense approvals.
3. **Missing browser-side defences:** no CSP or other security headers, and tokens kept in `localStorage`. Together with one stored XSS, this means a single script injection can take over a session.
4. **Deployment and configuration guardrails.**

| Severity | Count |
|---|---|
| Critical | 0 |
| High | 4 (+1 conditional) |
| Medium | 15 |
| Low / Info | ~35 |

---

## Fix before public launch

| # | Action | Findings |
|---|---|---|
| 1 | Require Entra sign-in for every staff/admin account (mark staff emails `corporate` / block OTP, Google, Apple and passkey for them) and require higher assurance for HR, finance, mail, customer-admin and user-admin permissions | H1, M1 |
| 2 | Stop trusting client-supplied `storageContainer`/`publicUrl`/`blobPath`; derive them on the server | H2, M6 |
| 3 | Fix the receipt "Open" preview: force the content type from an allow-list, check file signatures on upload | H3 |
| 4 | Add security headers (CSP, HSTS, frame-ancestors, nosniff, Referrer-Policy) and move the MSAL cache to `sessionStorage` | H3, M8 |
| 5 | Lock the GitHub `production` environment to `main` with required reviewers; move to OIDC and reset the publish profile | H4, M15 |
| 6 | Rate-limit and budget-cap the public AI assistant; restrict `/documents/pdf` to staff | M2, M3 |
| 7 | Enforce portal access, contact and customer status on every customer request | M4 |
| 8 | Upgrade `symfony/serializer` to ≥ 8.1.11; validate `APP_ENV` strictly | M10, M9 |
| 9 | Run gitleaks or trufflehog over the **full** origin history (this copy was a shallow clone) | Limitations |

---

## High

### H1. Staff and admin accounts can sign in without Entra, bypassing MFA and Conditional Access
**Confirmed in code.**
- **What happens:** a staff member's email address can be used with the guest email OTP, Google sign-in, Apple sign-in, or a passkey set up from an emailed link. `IdentityResolutionService` links the sign-in to the existing staff user by email, and that user keeps all their roles.
- **Why the protection doesn't apply:** the rule that a corporate address only accepts Entra (`core/src/Identity/Application/Service/IdentityResolutionService.php:76`) only runs when the address has a `purpose`. Every address has `purpose = NULL`:
  - the backfill writes NULL (`database/scripts/development/create-identity-user-email-addresses.sql:91`);
  - the primary-email fallback has no purpose (`src/Identity/Persistence/AzureSqlUserProvisioningRepository.php:169,185,241`);
  - no code ever writes `corporate`.
- **How far it reaches:** only about 25 permissions require more than the lowest sign-in assurance (`core/src/Identity/Domain/Service/PermissionAssurancePolicy.php:31-85`). HR restricted PII, finance payments and bank verification, mail, TriExpense, customer admin and audit are all reachable with an emailed code.
- **Exploit:** whoever controls a staff mailbox (for example through phishing, with no MFA prompt) or holds an old consumer Google or Apple account registered on the staff address gets that staff member's full platform access. Disabling the user in Entra does not stop them.
- **Makes it worse:**
  - (a) `USER.UPDATE` can change an admin's email to an attacker's address, then sign in by OTP and inherit admin (`UpdateUserService.php:24-50`).
  - (b) If `PASSKEY_ENABLED=true` in production, mailbox access is enough to register a passkey, which counts as "substantial" assurance and passes even the admin gates (`RequestGuestPasskeySetupLinkService.php:40-75`).
- **Fix:**
  - Backfill `purpose='corporate'` for all staff addresses.
  - Make "staff role ⇒ Entra only" a hard rule enforced at sign-in.
  - Require Entra-level assurance for every staff module permission.
  - Make admin email changes require re-verification of the new address and a super-admin approval.

### H2. A product media editor can mint read links for any private file in blob storage
**Confirmed in code (checked by hand).**
- **What happens:**
  - `POST /api/v1/admin/products/{id}/media` (`PRODUCT.MEDIA.MANAGE`, `AppBuilder.php:1894`) stores the client's `storageContainer` and `publicUrl` as sent (`src/Http/Request/Admin/Product/AddProductMediaRequest.php:42-52`, `core/src/Product/Application/Service/AddProductMediaService.php:40-48`).
  - `ProductDevMediaUrlResolver::signPrivateAzureReadUrl` (`src/Product/Storage/ProductDevMediaUrlResolver.php:86-124`) then signs a read SAS for that container and path with the account key.
- **What's exposed:** onboarding documents (payroll, IDs, photos), finance attachments, RFQ documents and expense receipts.
- **Who can reach it:** new media rows default to active, so on a published product the signed link is also returned to **anonymous** users by `GET /api/v1/products/{slug}/media` (`AppBuilder.php:1833`). The attacker needs the blob path; expense paths are predictable (`Y/F/login/login_YmdHis.ext`).
- **Fix:**
  - Ignore client `storageContainer`, `publicUrl` and `storagePath` for media; only accept blobs created through the initiate/complete upload flow, in the products container.
  - Allow-list containers inside the resolver.
  - Use per-container user-delegation SAS (managed identity) instead of the account key.

### H3. Stored XSS through the TriExpense receipt "Open" button, made worse by tokens in localStorage and no CSP
**Confirmed in code (checked by hand).**
- **What happens:**
  - `ReceiptAttachmentPanel.tsx:154-163` (tricab-web) fetches the receipt, wraps it with `URL.createObjectURL(blob)` and opens it in a new tab with whatever Content-Type storage returns. A `blob:` URL runs in the app's own origin.
  - The employee controls that Content-Type: the API checks the declared type only when the upload starts (`InitiateExpenseAttachmentService.php:57-80`). The write SAS doesn't pin the type (`AzureBlobAccountSasIssuer.php:28-92`), and completion only checks that the blob exists (`CompleteExpenseAttachmentService.php:50`).
- **Exploit:** an employee uploads HTML labelled as a `.png` and submits the expense. When the approver or a finance user clicks **Open**, the script runs as TriCab with their session. The MSAL cache is in `localStorage` (`src/auth/msalConfig.ts:87-92`) and there is no CSP, so the script can take tokens and call finance APIs as that user.
- **Fix:**
  - Do what AP2 and AR already do: rebuild the Blob with a type taken from an allow-list (PDF or image, else `application/octet-stream`).
  - On the API: check the stored content type and magic bytes at completion; set `rsct`/`rscd` overrides on read SAS links.
  - Add a CSP and set `cacheLocation: 'sessionStorage'`.

### H4. Production deploy credential is reachable from non-main branch runs (needs a GitHub settings check)
**Likely; depends on repository settings.**
- **What happens:**
  - The `deploy` job is correctly gated to `refs/heads/main` (`.github/workflows/deploy-production.yml:173`).
  - But `website-revision` and `build` (lines 37, 67) run with `environment: production` on any `workflow_dispatch` ref. `docs/deployment/production-pipeline.md` tells people to dispatch from feature branches.
  - Deployment uses a long-lived `AZURE_WEBAPP_PUBLISH_PROFILE` (line 207).
- **Exploit:** if that secret is an environment secret and the `production` environment allows any branch, a repository writer can push a branch with a modified workflow and read the publish profile. That gives write access to the `tc-web` production app at any time.
- **Fix:**
  - Restrict the `production` environment to `main` with required reviewers.
  - Give branch-run jobs a separate environment.
  - Replace the publish profile with OIDC (`azure/login`), turn off SCM basic auth on the App Service, and reset the publish profile.
  - Pin third-party actions to commit SHAs (M15).

### H5 (conditional). User-management permissions have no ceiling
**Confirmed. Medium today; High if any non-super-admin holds these permissions.**
- `USER.ROLE.ASSIGN` can grant ADMINISTRATOR, including to yourself (`AssignRoleToUserService.php:28-60`).
- `ROLE.PERMISSION.ASSIGN` can add any permission to your own role (`AssignPermissionToRoleService.php:27-50`).
- Lower holders can disable or demote other admins and remove `ACCOUNT.LOGIN` from ADMINISTRATOR.
- A TriExpense admin can give themselves the Finance role (`SetExpenseUserTeRolesService.php:56-105`) and then approve their own expenses (see M7).
- **Fix:** only grant what you hold yourself; never change your own roles; ADMINISTRATOR is assigned only by super admins; protect the last admin.

---

## Medium

| # | Finding | Evidence | Fix |
|---|---|---|---|
| M1 | Sensitive permissions have no sign-in assurance requirement: HR restricted PII, finance payments and bank verification, mail, TriExpense, customer admin, groups, audit, migrations | `PermissionAssurancePolicy.php:31-80` | Require Entra-level assurance for all staff modules (pairs with H1) |
| M2 | Public AI assistant: anonymous, no rate limit, `set_time_limit(0)`, writes a DB row per call. Model spend and PHP worker exhaustion | `AppBuilder.php:1815-1816`; `AssistantMessageAction.php:43`, `AssistantMessageStreamAction.php:64` | Per-IP and global rate limits, daily token budget, time cap, WAF rule; bind conversations to a session |
| M3 | Any registered account (customer, self-registered guest, onboarding guest) can generate genuine TriCab quotation, order and pick-list PDFs. A ready-made tool for invoice fraud | `AppBuilder.php:2490`; `GenerateDocumentPdfAction.php:109-160` | Require a staff permission |
| M4 | Disabling portal access, a contact or a customer does not cut off the portal user. They keep seeing quotes, orders and net pricing and keep using the cart | `ResolveAuthenticatedCustomerContextService.php:31`; `AzureSqlCustomerContactUserRepository.php:24-37`; `DisableCustomerPortalAccessService.php:40` | Filter customer scope by portal access, contact and customer status; revoke sessions on disable |
| M5 | Act-as writes are recorded only as the impersonated user. An admin can request and then approve AP2, credit approvals, NPR endorsements or ICAR signatures as two people. Act-as log failures are swallowed. A trusted device registered during act-as gives a 90-day credential for the target with no act-as trail. Reads are not logged | `ActAsMiddleware.php:57,102,164-178`; `RegisterTrustedDeviceAction.php:41,75` | Make act-as read-only for approvals and signatures (or record the real actor in domain audit); fail closed if logging fails; block trusted-device, passkey and session endpoints under act-as |
| M6 | Expense attachment "complete" trusts a client `blobPath`. A user can attach and read a colleague's receipt; the 30-day purge then deletes the victim's blob | `CompleteExpenseAttachmentService.php:50-55`; `PurgeExpenseReceiptService.php:103` | Bind the completion to the path issued at initiation (server-side record) |
| M7 | TriExpense separation of duties: Finance users can approve their own receipts | `ExpenseAccessService.php:232-236` | Block self-approval; require a second approver |
| M8 | No security headers anywhere (CSP, HSTS, X-Frame-Options/frame-ancestors, nosniff, Referrer-Policy); `server_tokens` on; `expose_php` and `display_errors` not set | `deploy/app-service/default:36-39`; `startup.sh` | Add headers in nginx; harden `php.ini` |
| M9 | `APP_ENV` must exactly equal `production`. `Production`, `prod` or `staging` silently switch to non-production defaults (in-memory users, dev mailer, OTP in logs, guessable pricing-token key). `APP_DEBUG`, `DB_TRUST_SERVER_CERTIFICATE=true` and `sql-login` are allowed in production. Dev auth can point at the real Azure SQL with a real super admin (`thilanh@`) as the default identity | `Settings.php:690-697, 910, 941, 1447-1451, 1477`; `ContainerFactory.php:3554-3561` | Accept only an enum of known values; fail startup on unknown ones; forbid debug, trust-cert and dev auth against Azure SQL |
| M10 | `symfony/serializer` 8.1.9 has 3 advisories (CVE-2026-118515, GHSA-pxj9-jh84-vhm5, GHSA-hqp2-7rr8-hf26) and parses unauthenticated passkey JSON | `composer.lock`; `WebauthnLibPasskeyCeremony.php:221` | Upgrade to ≥ 8.1.11; add `composer audit` to CI |
| M11 | TLS certificate verification disabled for the legacy MySQL `tc-mysql-live` even with `verify_ca`/`verify_identity`; the daily HR job pulls payroll PII over it | `MysqlHrEmployeeSource.php:35-36`; `MysqlLegacyRfqSource.php:44-45`; `ReadOnlyMysqlConnectionFactory.php:49-60` | Enable verification with the Azure MySQL CA |
| M12 | Auth rate limits are keyed on `REMOTE_ADDR`, with no real-IP configuration behind App Service, so probably one shared address. About 10 requests could lock out OTP and passkey sign-in for everyone. Counters are non-atomic and per instance | `GuestAuthRequestMetadata.php:21`; `WindowedGuestAuthRateLimiter.php:54-62` | Trust `X-Forwarded-For` only from the App Service front end; use an atomic shared store (Redis/SQL); also limit per account |
| M13 | The Graph app has tenant-wide `Mail.ReadWrite` and `Mail.Send` with no Application Access Policy. A leaked secret means reading and sending as any mailbox | `docs/runbooks/rfq-mail-reader.md:19-22` | Apply RBAC for Applications / Application Access Policy limited to the needed mailboxes; prefer certificate or managed identity |
| M14 | AP2 mail: a `FINANCE.AP2.MAIL.READ` holder can rewrite the To/CC/body of a supplier reply and mark it reviewed. The AP2 Copilot reads mail bodies with only invoice-read permission, so the bank-verifier role can read AP mail | `AppBuilder.php:671-672, 677-678`; `Ap2MailService.php:135-232`; `FoundryAp2Copilot.php:113-127` | Write permission for reply edits; check mail-read in the Copilot |
| M15 | GitHub Actions pinned to mutable tags in jobs holding deploy credentials. PR runs receive deploy keys and a long-lived website read token while `npm ci` runs install scripts. Staging's OIDC identity can swap production | `deploy-production.yml:89,204,240-402`; `pull-request-checks.yml:30-44`; `.github/actions/setup-api/action.yml:16` | Pin to SHAs; use a GitHub App token; separate the staging and production identities |

---

## Low / Info (summary)

**Authentication**
- OTP attempt counter and consumed flag are saved without a concurrency guard, so parallel guesses beat the 5-try cap and a used code can become valid again (`VerifyGuestOtpService.php:47-59`).
- request-otp and the passkey setup link reveal which addresses are registered, through timing and 503 responses.
- A reused previous trusted-device credential extends its own grace window and isn't treated as theft (`TrustedDevice.php:274-277`).
- Entra errors return raw library and JWKS text to the client, there is no `azp` allow-list, and an empty `ENTRA_REQUIRED_SCOPE` would accept ID and app tokens.
- Google sign-in has no nonce. Native Apple sign-in has no nonce and treats a missing `email_verified` as true.
- Passkeys:
  - the setup token isn't consumed when the link is verified;
  - equal signature counters are accepted;
  - revoking a passkey leaves its sessions live;
  - any subdomain origin is accepted;
  - **production passkey settings are locked to tricab.com while the app is served on tricab.ai, so passkeys will not work in production.**
- JWKS cache files are trusted as-is from a writable directory, and an unknown `kid` never forces a refetch.

**Authorization and business logic**
- Write operations guarded by read permissions: finance-task save-as-template, complaint PATCH, onboarding manager PUT, product soft-delete, AP2 copilot (`AppBuilder.php:763,1633,1284,1876,677`).
- One `TRIEXPENSE.ACCESS` gate covers 57 routes, including admin, bank feeds, period close and purge.
- Staff modules outside `/api/v1/admin` have no staff-only guard (`CustomerActorStaffRouteGuardMiddleware.php:47`), so one mis-granted permission exposes all customers.
- Price Lookup's "full list" gate is bypassed with `q=%` or `q=**`.
- Pricing and cart accept unpublished, inactive or out-of-region variants.
- Assistant conversation IDs act as bearer tokens.
- A cart line update isn't tied to its cart's customer during price-as.
- For users linked to several customers, the pricing customer is unpredictable.
- Linking a user to a contact skips the checks that portal-user creation makes.
- No upper bound on cart quantity.
- Finance can purge approved or reconciled receipts.

**Files and exports**
- CSV formula injection in the TriExpense exports (`ExpenseFinanceViews::csvLine`).
- Expense write SAS doesn't limit type or size, and blob names can collide.
- Product write SAS stays valid for 1 hour after acceptance.
- Stable media URLs take their host from `X-Forwarded-Host` when `PUBLIC_API_ORIGIN` is unset.

**Logging, health and deploy**
- Org-wide expense exports are not audited.
- `bin/promote-thilan-super-admin.php` grants admin via SQL with no audit row.
- `/health` exposes build commits; `/health/database` wakes the DB anonymously.
- nginx serves `/App_Data/jobs/*/run.sh` and `settings.job`.
- Python dependencies are unpinned, and `defusedxml` isn't installed although the services parse untrusted documents.
- Dev-only npm vulnerabilities in vite, undici and vitest.

**Frontend**
- Logout leaves the act-as, pricing and passkey tokens in `sessionStorage`.
- The E2E identity-switch mode is gated only by a build variable, and a real staff email ships in the bundle.
- Assistant Markdown allows any https link (a prompt-injection exfiltration route that needs a click).
- No shared URL scheme allow-list for links built from API data.
- Third-party scripts (Google, Apple, CDN) load without SRI.

**Repo hygiene**
- Real staff names and emails committed: `resources/npr/sales-roster.json` (50 staff) and several `bin/*.php` scripts.
- A customer name and phone numbers in a comment in `core/python/rfq_parser/quote_import.py:82-83`.
- A committed `.azure-cli-local/` profile; it held no tokens.
- Infrastructure names disclosed in docs and config.

---

## What was checked and looks correct
- **SQL:** sort columns are allow-listed, LIMIT/TOP values are integers, IN lists and LIKE values are bound. Interpolated SQL uses only server constants.
- **Process execution:** array/list form with no shell and only config-defined arguments.
- **Server-side fetches:** every outbound URL comes from config, so no SSRF.
- **Parsing:** no `unserialize` or XML parsing of request data.
- **PDF generation:** Dompdf runs with remote and PHP disabled. Mail headers have CR/LF stripped.
- **Entra JWT:** tenant-bound JWKS, issuer and tenant pinned, no `alg=none` or algorithm confusion. No automatic provisioning or role grants from tokens.
- **Guest sessions:** server-side, revocable and re-checked on every request. OTP codes are secure random with a 5-minute expiry.
- **CORS:** exact allow-list; `null` origin refused.
- **Staff route guard:** the `/api/v1/admin/*` guard holds against encoding, case and trailing-slash tricks.
- **Customer data:** quotes, orders and carts are scoped to the customer. Prices are computed on the server, client price, margin and cost fields are rejected, and price-as needs a signed, user-bound token.
- **Act-as basics:** Entra admins only, admin targets refused, no chaining, and customers sending the header get a 403.
- **Untrusted email HTML:** sanitised on the server with an allow-list, again with DOMPurify, and shown in a sandboxed iframe. Attachments are served as octet-stream or under a CSP sandbox with nosniff.
- **Approvals:** AP2 approvals check the approver, block self-requests and are invalidated when the item changes. Expense amounts are locked after submission.
- **Public credit-note form:** 256-bit hashed token, expiry, single use and rate limits.
- **AI features:** tools are read-only or go through permissioned routes. CableFit search is domain-limited, mail flagged as possible prompt injection gets no automatic draft, and prompts and tokens are not logged.
- **Python services:** bound to loopback, and their routes need a bearer token that fails closed when unset.
- **Dev auth:** needs `APP_ENV=development` and `DEV_AUTH_ENABLED`; a missing `APP_ENV` means production.
- **Secrets and CI:** secrets come only from the environment, and `.env` is git-ignored. Workflows use `pull_request` (not `_target`) with `contents: read`, and private Composer packages are pinned to commit SHAs.
- **SPA bundle:** no source maps, no secret `VITE_*` variables, and the mail fixtures are excluded from production builds.

---

## Limitations and next steps
- **Shallow clone:** the API history started around Oct 22 and the web history around Oct 23, so about the first 106 API PRs were not scanned for secrets. Run `gitleaks detect` over the full origin history of both repos.
- **No `vendor/` or `node_modules`:** library behaviour (php-jwt, webauthn-lib, symfony/serializer) was judged from documentation and advisories.
- **Static review only:** no dynamic testing.
- **Settings I couldn't see:**
  - Azure: real-IP and front-end configuration, WAF/Front Door rate limits, App Service settings (`APP_ENV`, `PASSKEY_ENABLED`, `APP_DEBUG`), storage account network rules.
  - Entra: the Graph app's permissions and access policies.
  - GitHub: environment protection rules and branch protection.
- **Before go-live:** an external penetration test against staging, focused on H1–H3 after the fixes.

Detailed per-area reports with full code evidence and exploit steps are in the scratchpad `findings/` folder (01-authn … 07-frontend).
