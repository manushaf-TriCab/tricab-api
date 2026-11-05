# 02 — Route-level authorization review (TriCab API)

Scope: `src/Bootstrap/AppBuilder.php` route table, global and per-route middleware, `public/index.php`, `public/router.php`, the production nginx front (`deploy/app-service/default`, `deploy/app-service/api/index.php`), dev/E2E routes, identity-management actions, health endpoints.
Method: every `$app->get|post|put|patch|delete|map(...)` statement was extracted by script (849 registrations; `foreach` loops count once, so the real table is larger), per-route `->add(...)` permission resolved to its literal code (constants resolved per function scope), and every route with no permission middleware was read by hand. Read-only; nothing was changed.

## 1. How the authorization stack works (verified)

Global middleware, in `AppBuilder::build` (`src/Bootstrap/AppBuilder.php:328-377`). Slim runs it in reverse order of `add()` (last added runs first), so a request passes through:

`RequestPerformance → ErrorMiddleware → [CustomerContactE2eFailPut, only in E2E mode] → ReferenceDataCacheInvalidation → Cors → DevelopmentAuthentication → BearerTokenAuthentication → JsonException → Routing → BodyParsing → ActAs → CustomerActorStaffRouteGuard → route middleware → action`

* **Authentication** (Bearer / Dev) only attaches a `VerifiedIdentity`. It never refuses a request. **A route with no `->add()` is anonymous** unless the action checks identity itself.
* **`($require)('X')`** (`src/Http/Auth/RequirePermission.php`) creates a `RequirePermissionMiddleware`. That middleware returns 401 without an identity and 403 without permission `X`. It also returns 403 when the session's sign-in assurance is below the floor `PermissionAssurancePolicy` sets for `X`. `$require` and `$requireGuest` are the same class.
* **Per-route ordering.** In `->add($platformAdmin)->add(($require)('ACCOUNT.LOGIN'))` the last `add` runs first. The permission check therefore attaches the user before `RequirePlatformAdministratorMiddleware` reads it. This is correct. In the reverse order the check would fail closed (403), not open.
* **`CustomerActorStaffRouteGuardMiddleware`** sits inside routing, so the matched route is available. It applies when either the decoded, lower-cased request path or the **route pattern** starts with `/api/v1/admin`. It refuses identities that hold a customer-oriented role (`CUSTOMER_*`, `PORTAL_*`, `CUSTOMER_APPLICANT`, `NEWBIE`, except `CUSTOMER_CONTACT_*`) or no staff role (`EMPLOYEE` or `ADMINISTRATOR`).
  * **Bypass attempts. None worked.** The route pattern is checked as well as the path, so percent-encoding (`%61dmin`), double-encoding (it no longer routes), case changes (FastRoute matches case-sensitively, so the route is not found) and trailing slashes (no route) all fail to bypass it.
  * **Limitation: the guard covers only `/api/v1/admin/*`.** Staff modules outside that prefix depend on the permission check alone. These include `/api/v1/finance/*`, `/hr/*`, `/mail/*`, `/tri-expense/*` (including `/tri-expense/admin/*`), `/npr/admin/*`, `/price-lookup/*`, `/rfqs/*`, `/conductor/*`, `/cable-routes/*` and `/catalogues/*`. See F-10.
* **ActAs** swaps in the target user after it re-checks that the caller is an administrator with sufficient sign-in assurance. Assurance stays the caller's. Act-as into an administrator is refused (`StartActAsHandler.php:72`).

---

## 2. Findings

### F-1 — No privilege ceiling on identity-management permissions: any holder of `USER.ROLE.ASSIGN` (or `ROLE.PERMISSION.ASSIGN`) can make themselves ADMINISTRATOR, and lower-tier holders can disable or demote administrators
* **Severity:** Medium today. It becomes **High** as soon as any non-ADMINISTRATOR role is given one of these codes, and the Roles matrix UI lets an administrator do exactly that.
* **Confidence:** Confirmed (code path).
* **Where:**
  * `src/Bootstrap/AppBuilder.php:1453-1471`: the routes are gated by a single permission code each.
  * `core/src/Identity/Application/Service/UserManagement/AssignRoleToUserService.php:28-60`: no check on which role is being granted.
  * `core/src/Identity/Application/Service/UserManagement/AssignPermissionToRoleService.php:27-50`: no check on which permission is being granted, or to which role.
  * `core/src/Identity/Application/Service/UserManagement/RemovePermissionFromRoleService.php:26-50`: no protection for `ADMINISTRATOR` or for `ACCOUNT.LOGIN`.
  * `core/src/Identity/Domain/Service/AdministratorProtectionPolicy.php`: only stops self-lockout and removal of the last administrator.
* **Evidence:**
  ```php
  if (!$this->roles->isRoleActive($role)) { throw InactiveRoleAssignment::...; }
  if (!$this->users->userHasActiveRole($id, $role)) { $this->users->assignRole(...); }   // any role, incl. ADMINISTRATOR
  ```
  Neither the action (`src/Http/Action/Admin/AssignUserRoleAction.php`) nor the service checks whether the actor is an administrator, or whether the role grants more than the actor already holds.
* **Exploit:**
  1. Grant a "Help desk" role `USER.ROLE.ASSIGN` so it can hand out module roles. Its holder calls `POST /api/v1/admin/users/{self}/roles {"roleCode":"ADMINISTRATOR"}` and becomes platform administrator: platform settings, act-as, and every permission mapped to ADMINISTRATOR.
  2. A holder of `ROLE.PERMISSION.ASSIGN` can do the same by adding `USER.ROLE.ASSIGN` (or anything else) to a role they already hold.
  3. A holder of `USER.STATUS.UPDATE` or `USER.ROLE.REMOVE` can disable or demote every administrator except the last.
  4. A holder of `ROLE.PERMISSION.REMOVE` can strip `ACCOUNT.LOGIN` or `USER.*` from `ADMINISTRATOR` and lock all administrators out.
* **Fix:**
  * Only callers that pass `RequirePlatformAdministratorMiddleware` may grant or remove `ADMINISTRATOR`, or add or remove permissions on protected roles (`ProtectedIdentityCatalog::isSystemProtectedRole`).
  * Enforce "no grant beyond your own permissions": a role or permission can only be assigned when the actor holds every permission it confers.
  * Only an administrator may change the status or roles of a user who holds `ADMINISTRATOR`.
  * Refuse to remove `ACCOUNT.LOGIN` or `USER.*`/`ROLE.*` from `ADMINISTRATOR`.

### F-2 — `USER.UPDATE` can rebind an administrator's email address, which leads to takeover of that account through email OTP, Google or Apple auto-linking
* **Severity:** Medium today. High if `USER.UPDATE` is ever delegated to a non-administrator.
* **Confidence:** Likely. The code path was traced; it was not run.
* **Where:**
  * `src/Bootstrap/AppBuilder.php:1450`.
  * `core/src/Identity/Application/Service/UserManagement/UpdateUserService.php:24-50`: changes `EmailAddress` and `NormalizedEmailAddress` for any user, administrators included (`src/Identity/Persistence/AzureSqlUserManagementRepository.php:244-247`).
  * `core/src/Identity/Application/Service/IdentityResolutionService.php:57-99`: a provider identity seen for the first time is auto-linked to the user whose address matches.
  * `src/Identity/Persistence/AzureSqlUserProvisioningRepository.php:166-185`: falls back to the primary address with `purpose = null`, so `ProviderBindingPolicy` is skipped.
  * Guest OTP eligibility reads `Users.NormalizedEmailAddress` directly (`src/Identity/Persistence/AzureSqlGuestLoginEligibilityLookup.php`).
* **Exploit:**
  1. The holder of `USER.UPDATE` sets the target administrator's email to `attacker@gmail.com`.
  2. The attacker then signs in by guest OTP, or with Google. The account is resolved and linked to the administrator, as long as that administrator has no Google subject linked yet.
  3. The attacker now has a LOW-assurance session holding the `ADMINISTRATOR` role. That is enough for every route whose floor is LOW: HR restricted data, Finance, Mail, customers, pricing management, Tri-Expense and others (see F-4).
  4. If passkeys are enabled, the attacker can also reach SUBSTANTIAL assurance (F-3), and with it full platform-admin rights.
* **Fix:**
  * Changing the email of a user who holds `ADMINISTRATOR` (or any role above the actor's) should require platform-administrator rights.
  * A changed primary address should go into `UserEmailAddresses` as an unverified, Entra-only address until it is re-verified.
  * Do not auto-link a new external subject to a staff or administrator account when the match comes from the purpose-less primary-address fallback.

### F-3 — When passkeys are enabled, owning a mailbox is enough to reach "SUBSTANTIAL" assurance, which defeats the route assurance floors (platform admin and `USER.*`/`ROLE.*`)
* **Severity:** High if `PASSKEY_ENABLED=true` in production (the default is off in production; `src/Config/Settings.php:1157`).
* **Confidence:** Needs verification of production configuration. The code path is confirmed.
* **Cross-scope.** This belongs mainly to the authentication review. It is recorded here because the route gates depend on it.
* **Where:**
  * `src/Bootstrap/AppBuilder.php:418-421`: anonymous setup-link and registration routes.
  * `core/src/Identity/Application/Service/RequestGuestPasskeySetupLinkService.php:40-75`: eligibility is only "active and holds `ACCOUNT.LOGIN`", which every staff member and administrator has.
  * `VerifyGuestPasskeySetupTokenService` creates a registration session.
  * `CompletePasskeyAuthenticationService` issues a session with provider `passkey`.
  * `AuthenticationAssurance::BY_PROVIDER`: `PASSKEY => SUBSTANTIAL`.
* **Exploit:**
  1. Anyone who can read an administrator's mailbox (compromised mailbox, mail forwarding, or the F-2 rebinding) requests `POST /api/v1/auth/guest/passkey/setup-link {email}` and follows the emailed link.
  2. They register their own passkey and sign in with it. The session counts as SUBSTANTIAL.
  3. That passes `RequirePlatformAdministratorMiddleware` and every `USER.*`, `ROLE.*`, `PERMISSION.*`, `MIGRATION.ADMIN` and `ONBOARDING.*` administration gate. The "an emailed code must never be enough" rule (`PermissionAssurancePolicy.php:33-36`) is therefore bypassed.
* **Fix:**
  * Do not let an emailed link bootstrap a passkey for staff identities (`EMPLOYEE`/`ADMINISTRATOR`). Require a HIGH (Entra) session to register a staff passkey.
  * Alternatively, record the bootstrap assurance on the passkey and cap it at LOW.

### F-4 — Most sensitive staff modules have no assurance floor, so an emailed OTP, Google or Apple sign-in reaches HR restricted PII, finance payments and bank verification, mail, customer administration and migrations
* **Severity:** Medium.
* **Confidence:** Confirmed. It is a documented design choice, but its reach is wider than the policy comment suggests.
* **Where:**
  * `core/src/Identity/Domain/Service/PermissionAssurancePolicy.php:31-80`: the map lists only identity, onboarding, five RFQ codes, `CUSTOMER.PRICE_AS` and `PRODUCT.PRICING.OVERRIDE`. Everything else defaults to LOW.
* **Codes left at LOW, with their routes:**
  * `HR.EMPLOYEES.READ` and `HR.EMPLOYEES.RESTRICTED.READ`: birth date, home address, leave (`AppBuilder.php:482-497`, `src/Http/Action/Hr/GetHrEmployeeAction.php:51`).
  * `FINANCE.AP2.BANK.VERIFY`, `FINANCE.AP2.PAYMENTS.PREPARE`, `FINANCE.AP2.EXPORT` (post-to-Odoo), `FINANCE.ADMIN` (`AppBuilder.php:600-685`).
  * `MAIL.*` (`:561-582`).
  * `TRIEXPENSE.ACCESS`: the only route gate for bank-feed import, period close and purge (`:1506-1566`).
  * `CUSTOMER.*` administration, including portal-user creation and linking (`:2456-2487`).
  * `IDENTITY.GROUPS.MANAGE` (`:1436-1447`).
  * `AUDIT.USER_MANAGEMENT.READ` (`:1478`).
  * `PRODUCT.MIGRATION.MANAGE`: batch execute and rollback (`:2250-2346`).
  * `PRODUCT.PRICING.MANAGE`.
* **Exploit:** guest OTP (`/api/v1/auth/guest/request-otp`) is open to every active user holding `ACCOUNT.LOGIN`, staff included. Someone who controls only an HR or finance employee's mailbox therefore gets full reach into these modules, with no Microsoft sign-in or MFA.
* **Fix:**
  * Raise these codes to SUBSTANTIAL. HR restricted data, bank verification and payments arguably need HIGH.
  * Alternatively, stop issuing guest OTP sessions to identities holding `EMPLOYEE`/`ADMINISTRATOR`.
  * Add a test that fails when a permission matching `*.ADMIN`, `*.MANAGE`, `HR.*`, `FINANCE.*` or `MAIL.*` has no explicit floor.

### F-5 — `POST /api/v1/documents/pdf` (TriCab-branded order confirmation, quotation and pick-list generator) has no permission: any registered account can call it, including customers, guests and pre-start onboarding candidates
* **Severity:** Medium.
* **Confidence:** Confirmed.
* **Where:**
  * `src/Bootstrap/AppBuilder.php:2490`: no `->add()`.
  * `src/Http/Action/GenerateDocumentPdfAction.php:116-160`: only checks that the identity resolves to a registered user.
  * `docs/document-generation.md` states the contract as an *Entra* bearer token.
* **Evidence:**
  ```php
  $app->post('/api/v1/documents/pdf', GenerateDocumentPdfAction::class);
  ...
  try { $this->resolveUser->handle($verifiedIdentity); } catch (UserNotRegistered) {...}
  return null; // any registered user proceeds
  ```
* **Exploit:**
  1. A customer portal user (Google, Apple or OTP sign-in), or a GUEST onboarding candidate, posts a crafted `.RET.` OCR stream (`tcord.ocr`, `tcqu.ocr`).
  2. They receive a genuine-looking TriCab order confirmation or quotation PDF with arbitrary prices and terms. This enables document forgery and fraud against third parties.
  3. Each call can also take up to 5 MB of server-side rendering.
* **Fix:**
  * Add `->add(($require)('DOCUMENT.GENERATE'))` (a new staff-only code), and require an eligible staff identity (`StaffEligibilityPolicy::isEligible`) or a HIGH (Entra) provider, matching the documented contract.
  * Move the route under `/api/v1/admin` or add a staff check.

### F-6 — Anonymous LLM endpoints with no application-level rate limit
* **Severity:** Medium (cost and DoS).
* **Confidence:** Likely. Gateway or WAF limits were not visible in the repository.
* **Where:**
  * `src/Bootstrap/AppBuilder.php:1815-1816`: `/api/v1/assistant/messages` and `/messages/stream`.
  * `src/Http/Action/AssistantMessageAction.php:43` and `AssistantMessageStreamAction.php:64` (`@set_time_limit(0)`).
  * The nginx stream location has a 3600 s FastCGI read timeout (`deploy/app-service/default`).
* **Exploit:** an unauthenticated script loops on `POST /api/v1/assistant/messages/stream`. Each request starts a Foundry model call plus tool calls to the product-finder service, with no time limit, and runs up model spend. It also ties up PHP-FPM workers for as long as an hour each, which can deny service to the whole API on the shared App Service.
* **Related:** `/api/v1/product-finder` (Python sidecar), `/api/v1/cable-drawings` and `/api/v1/cable-calculator/calculate` are also anonymous and compute-bound.
* **Fix:**
  * Add per-IP and per-session rate limiting (the existing `PasskeyAuthRateLimiter` pattern, or Front Door/WAF).
  * Cap turns and tokens per request, and set a finite execution time.
  * Consider requiring at least a guest session for the streaming endpoint.

### F-7 — AP2 inbox: a holder of the read permission `FINANCE.AP2.MAIL.READ` can rewrite the outbound To/CC/body of a supplier reply and mark it "reviewed"
* **Severity:** Medium (AP payment-redirection or social-engineering vector).
* **Confidence:** Likely. Business intent needs confirming.
* **Where:**
  * `src/Bootstrap/AppBuilder.php:671-672`: `PATCH /api/v1/finance/ap2/mail/{id}` and `POST .../draft` are gated by `MAIL.READ`.
  * `core/src/Finance/Ap2/Service/Ap2MailService.php:135-232`: accepts `draft`, `replyTo` (the To address), `cc`, `ownerUserId`, `status` and `draftReviewed`.
  * `:336`: send requires only `draftReviewed`.
* **Evidence:**
  ```php
  $input->text('draft', 20000); $input->flag('draftReviewed'); $input->addresses('cc', 1000); $input->addresses('replyTo', 320);
  ...
  $changed = $changed->with(draftReviewed: $guarded ? false : $reviewed);  // a 2nd PATCH sets it back to true
  ```
* **Exploit:**
  1. A read-only AP user edits the To/CC and body of a pending supplier reply, for example to add new bank details or redirect it.
  2. A second PATCH sets `draftReviewed: true`.
  3. A colleague holding `MAIL.SEND` sees a "reviewed" draft and sends it.
* **Fix:**
  * Gate PATCH and draft with `FINANCE.AP2.MAIL.SEND`, or with a new `MAIL.EDIT` code.
  * Record who reviewed and refuse to send when the reviewer is the last editor (four-eyes), or require `MAIL.SEND` to set `draftReviewed`.

### F-8 — Other write operations guarded by read permissions
* **Severity:** Low.
* **Confidence:** Confirmed.
* **Write-on-read routes:**
  * `POST /api/v1/finance/task/projects/{id}/save-as-template` needs only `FINANCE.TASK.TASKS.READ` (`AppBuilder.php:763-764`). Yet `POST /project-templates` needs `FINANCE.TASK.SETUP.MANAGE` (`:767`), and `TaskBoardService::saveProjectAsTemplate` → `createProjectTemplate` (`core/src/Finance/Task/Service/TaskBoardService.php:240-255, 373-400`) creates a shared template with no further check. A reader can therefore publish templates every user sees.
  * `PATCH /api/v1/customer-contacts/{publicId}` (complaint register) is gated by `CUSTOMER_CONTACT.READ` (`AppBuilder.php:1633-1634`). The service enforces field-level rules for coordinator, originator and assignee (`UpdateCustomerContactReportService.php:120-140`), so this is defence-in-depth only. Use `CUSTOMER_CONTACT.ACCESS` or `CREATE`.
  * `PUT /api/v1/onboarding/team/forms/{id}/contribution` is gated by `ONBOARDING.SELF.READ` (`:1284`). This contradicts the route-table rule that READ-only means "leaver". A departed manager who keeps READ can still write contributions.
  * `POST /api/v1/admin/products/{id}/soft-delete` is gated by `PRODUCT.UPDATE` while restore needs `PRODUCT.RESTORE` (`:1876`).
  * `POST /api/v1/finance/ap2/copilot` (LLM) is gated by `INVOICES.READ` (`:677`).
* **Fix:** use the write or manage permission for each route above.

### F-9 — Tri-Expense: one coarse `TRIEXPENSE.ACCESS` gate on 57 routes, including admin role assignment, bank feeds, period close and purge
* **Severity:** Low (defence-in-depth).
* **Confidence:** Confirmed at route level. The services do check roles.
* **Where:** `src/Bootstrap/AppBuilder.php:1501-1566`.
* **Coverage:** examples include `PUT /tri-expense/admin/users/{id}/te-roles`, `POST /finance/bank-feeds/import`, `POST /finance/periods/{id}/close` and `POST /receipts/{id}/purge`. Every service checked does call `ExpenseAccessService` or assert (`SetExpenseUserTeRolesService.php:77`). The only services without a check are assemblers and resolvers, not route targets. A single missed check in a future service would expose finance or admin functions to every expense user.
* **Fix:** gate at the route with `TRIEXPENSE.ADMIN`, `TRIEXPENSE.FINANCE` and `TRIEXPENSE.APPROVE` (the codes already exist in `core/src/Expense/Module.php:22-34`), and keep the service checks.

### F-10 — Staff-only modules outside `/api/v1/admin` depend only on permissions; the customer and guest staff guard does not cover them
* **Severity:** Low (defence-in-depth or misconfiguration).
* **Confidence:** Confirmed.
* **Where:** `CustomerActorStaffRouteGuardMiddleware.php:47` (`STAFF_PATH_PREFIX = '/api/v1/admin'`).
* **Uncovered areas:** `/api/v1/finance/admin/*`, `/npr/admin/*` and `/tri-expense/admin/*` look administrative but are not covered. Nor are HR, mail, price lookup (cost data), conductor, cable routes, RFQ (customer text) and the PDF catalogues.
* **Risk:** today no customer or GUEST role holds those codes. Customer roles hold `CUSTOMER.*`, `PRODUCT.READ`, `QUOTE.*`, `ORDER.READ`, `DOCUMENT.READ` and `ACCOUNT.LOGIN`, per `database/scripts/development/seed-customer-roles-and-permissions.sql`. But one mis-click in the Roles matrix, or F-1, would give customers staff data with no second barrier. QuoteOrder already does its own staff check (`QuoteOrderActor.php:63`). The other modules do not.
* **Fix:**
  * Turn the guard into an allowlist of non-staff paths (public, auth, onboarding self-service, cart, quotes, me, activity) and apply staff eligibility everywhere else.
  * Alternatively, add the module prefixes to the guard.

### F-11 — Development authentication is not blocked from talking to the shared Azure SQL identity store; the default identity is a real staff member
* **Severity:** Low in production, because `APP_ENV` defaults to `production` and dev auth needs `APP_ENV=development`. Medium for any internet-reachable non-production host.
* **Confidence:** Needs verification of non-production deployments.
* **Where:**
  * `src/Config/Settings.php:700-703, 1448-1451`.
  * `src/Http/Middleware/DevelopmentAuthenticationMiddleware.php:35-62`.
  * `.env.example:1-9`.
  * `docs/onboarding-operations.md:51` ("an unauthenticated curl is treated as DEV_AUTH_DEFAULT_EMAIL").
* **Problem:**
  * With `APP_ENV=development` and `DEV_AUTH_ENABLED=true`, every request without a Bearer token gets a `MICROSOFT_ENTRA` (HIGH-assurance) identity from `X-Dev-Entra-*` headers. When no headers are sent it defaults to `thilanh@tricab.com`, who is the platform super administrator (`bin/promote-thilan-super-admin.php`).
  * Unlike memory blob mode or E2E mode, nothing refuses this combination with `USER_REPOSITORY=azure-sql`. The docs show developers running it against `tc` on `tricab-mel`.
  * Any developer machine or test slot reachable from a network therefore grants anonymous super-administrator rights over that database.
* **Fix:**
  * Refuse to start when `devAuthEnabled && usesAzureSqlUserRepository()`, mirroring the E2E guard at `Settings.php:1265`.
  * Bind the dev server to localhost.
  * Make the default identity a non-privileged fixture user.

### F-12 — Health endpoints disclose build information and let anonymous callers wake the database
* **Severity:** Low.
* **Confidence:** Confirmed.
* **Where:**
  * `src/Http/Action/HealthAction.php:32-41`: returns `appName` and `revision.api`/`revision.web` commit IDs to anyone.
  * `src/Http/Action/DatabaseHealthAction.php:28-50`: every anonymous call opens Azure SQL. Its own docblock says this "prevents auto-pause".
  * Both are routed publicly (`AppBuilder.php:386-387`; nginx `location = /health/database`).
* **Exploit:** a polling loop keeps the serverless database from pausing, which costs money. Commit IDs help attackers map the deployed version against public repositories or advisories.
* **Fix:**
  * Restrict `/health/database` to App Service health-check source IPs, or require a shared header secret.
  * Drop `revision` from the anonymous payload, or expose it only to authenticated staff.

### F-13 — Production nginx serves WebJob scripts from `/App_Data/` as static files
* **Severity:** Low (information disclosure).
* **Confidence:** Likely.
* **Where:**
  * `scripts/build-app-service-release.ps1:270-326` copies `App_Data/jobs/continuous/*/run.sh` and `settings.job` into the web root.
  * `deploy/app-service/default` denies only `/app`, `/default`, `/startup.sh`, `*.php` and dotfiles. `location /` then does `try_files $uri`.
* **Exploit:** `GET /App_Data/jobs/continuous/rfq-mail/run.sh` returns operational details: mailbox names, paths, enabled feature switches and lock locations.
* **Fix:** add `location ^~ /App_Data/ { deny all; }`, or better, switch to an allowlist of static prefixes.

### F-14 — Informational
* **I-1: no test pins the anonymous route set.** There is no route-table test asserting that every non-allowlisted route carries a permission (only Mail and Finance have such tests). Add one global test with an explicit anonymous allowlist (Appendix A) so a future `->add()` omission fails CI.
* **I-2: `ActAsMiddleware::isActAsPath` compares the raw, undecoded path** (`ActAsMiddleware.php:122-125`). `/api/v1/admin/act-a%73` routes to the act-as endpoints with the switch still applied. This is not exploitable today, because act-as targets are never administrators and the act-as routes require platform admin. Decode the path the way the staff guard does.
* **I-3: `public/router.php`** is used only by the PHP built-in development server. It refuses `..` and serves only files under `public/` (`index.php`, `router.php`, `media/`). Production uses nginx plus `deploy/app-service/api/index.php`. No issue found.
* **I-4: dev and E2E routes are correctly gated.**
  * `/api/v1/dev/passkey-diagnostics` needs `APP_ENV=development`.
  * `/api/v1/dev/product-files/{id}` needs development plus the memory blob store, and the action re-checks.
  * `CustomerContactE2eFailPutMiddleware` refuses to start with `APP_ENV=production` or azure-sql (`Settings.php:1265`).
  * `APP_ENV` defaults to `production`.
* **I-5: platform settings and act-as are correctly gated.** They use `ACCOUNT.LOGIN` plus `RequirePlatformAdministratorMiddleware` (ADMINISTRATOR role and SUBSTANTIAL assurance). `GET /api/v1/admin/dashboard` needs only `ACCOUNT.LOGIN`, but it is behind the staff guard and nulls each section by permission (`GetAdminDashboardAction.php:37-64`).

---

## Appendix A — Routes reachable without any permission middleware (62 registrations)

The script reported 66 routes with no `->add()`. Four of those (`/api/v1/hr/onboarding-links/...`, `AppBuilder.php:504-507`) are false positives: the `$both` closure adds `HR.EMPLOYEES.READ` and `ONBOARDING.PEOPLE.MANAGE`. "In-action auth" means the action itself returns 401 or 403.

| Line | Method | Path | Gate | Verdict |
|---|---|---|---|---|
| 386 | GET | /health | none | OK (liveness). Leaks revision IDs (F-12) |
| 387 | GET | /health/database | none | **Low risk**: anonymous database wake (F-12) |
| 388 | GET | /api/v1/me | in-action 401 | OK |
| 392-394 | POST | /api/v1/auth/guest/request-otp, verify-otp, logout | guest JWT configured | Intended (authentication). Note that staff can use OTP (F-4) |
| 399 | POST | /api/v1/auth/devices/session | guest JWT | Intended (device token exchange) |
| 405 | POST | /api/v1/auth/google | Google configured | Intended. Auto-link by email (F-2) |
| 409-410 | GET/POST | /api/v1/auth/apple/challenge, /auth/apple | Apple configured | Intended |
| 414 | POST | /api/v1/auth/apple/native | configured | Intended |
| 418-419 | POST | /api/v1/auth/guest/passkey/setup-link(/verify) | passkey configured | Intended, but **F-3** |
| 420-423 | POST | /api/v1/auth/passkeys/registration/*, authentication/* | registration-session token or ceremony | Intended (WebAuthn) |
| 429 | GET | /api/v1/dev/passkey-diagnostics | registered only when `APP_ENV=development` | OK in production |
| 434 | GET | /api/v1/dev/product-files/{filePublicId} | development plus memory blob, re-checked in action | OK in production |
| 446-448 | GET/GET/POST | /api/v1/regions, /regions/current, /regions/select | none | Intended (region preference) |
| 449-450 | GET | /api/v1/public/about, /public/contact | none | Intended (public content) |
| 947-948 | GET/POST | /api/v1/finance/ar/public/credit-note-form | token compared by hash in constant time, with expiry (`ArPublicFormService.php:140-160`) | Intended (customer link). OK |
| 1815-1816 | POST | /api/v1/assistant/messages(/stream) | none. STAFF visibility only for Entra identities that are staff and hold `PRODUCT.READ` | **Medium**: no rate limit (F-6) |
| 1817-1820 | GET | /api/v1/products/{slug}, /customer-view, /customer-view-theme, /variants | none | Intended (public catalogue) |
| 1821-1823 | POST | /api/v1/products/{slug}/variants/pricing, /rfq/lines/prepare, /cart/items/prepare | optional user. Client-supplied cost fields are rejected. Staff diagnostics need staff plus `PRODUCT.PRICING.VIEW` | Intended. Cost fields are stripped for non-staff |
| 1824-1828 | GET/POST/PATCH/DELETE/POST | /api/v1/cart, /cart/items, /cart/items/{lineId}, /cart/reprice | in-action 401 (`LOGIN_REQUIRED`) | OK |
| 1829 | POST | /api/v1/pricing/customer-context | in-action 401, then price-as check | OK |
| 1830-1831 | GET | /api/v1/pricing/customers, /customers/{id}/contacts | in-action 401 and `canPriceAs` (staff plus `CUSTOMER.PRICE_AS` at SUBSTANTIAL) | OK. Better as route middleware |
| 1832 | POST | /api/v1/pricing/margin-override | in-action 401 and override check | OK |
| 1833-1838 | GET | /api/v1/products/{slug}/media, /3d, /documents, /relationships, /specifications | none | Intended (public catalogue) |
| 1836 | GET | /api/v1/products/{slug}/documents/{id}/download | listed public documents only, unless the caller holds `PRODUCT.DOCUMENTS.MANAGE` | OK |
| 1839-1840 | GET | /api/v1/product-search, /product-codes/search | none | Intended |
| 1841-1844 | GET | /api/v1/catalogue/panel, /tabs, /tabs/{slug}/tree, /nodes/{id} | none | Intended |
| 1845 | GET | /api/v1/media/images/{token}[/{name}] | signed token, products container, image paths only | OK |
| 1846-1849 | POST/GET | /api/v1/cable-calculator/calculate, /cable-drawings, /product-finder, /product-finder/styles | none | Intended (public tools). Compute-bound, no rate limit (F-6) |
| 2490 | POST | /api/v1/documents/pdf | in-action "any registered user" | **Medium**: needs staff permission (F-5) |

## Appendix B — Permission distribution (849 registrations, resolved)

| Count | Permissions |
|---|---|
| 57 | TRIEXPENSE.ACCESS |
| 51 | PRODUCT.READ |
| 49 | PRODUCT.MIGRATION.MANAGE |
| 40 | PRODUCT.LOOKUPS.MANAGE |
| 31 | PRODUCT.SPECIFICATIONS.MANAGE |
| 30 | PRODUCT.MEDIA.MANAGE |
| 26 | FINANCE.TASK.SETUP.MANAGE |
| 23 | MIGRATION.ADMIN |
| 21 | ACCOUNT.LOGIN (9 of them, the platform-settings and act-as routes, also need `RequirePlatformAdministratorMiddleware`) |
| 66 | none (62 truly anonymous, see Appendix A) |

The remaining routes carry module-specific codes, each held by the module's own roles. The full resolved table can be regenerated with `scratchpad/routes.py` and `scratchpad/resolve.py`.
