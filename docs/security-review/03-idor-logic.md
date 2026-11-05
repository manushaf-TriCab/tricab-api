# 03 — Object-level authorization (IDOR/BOLA) and business logic

Scope: customer-facing and multi-tenant data in `tricab-api` (`src/`, `core/src/`), with the SPA (`tricab-web`) checked for context only.
Method: I traced each route from `src/Bootstrap/AppBuilder.php` through the action and service to the repository or SQL. This was a read-only review. Nothing was executed against a live database.

## Role / permission model (context for every finding)

- `GUEST` has only `ACCOUNT.LOGIN`. It is self-provisioned through an email OTP (`database/scripts/development/seed-guest-role-and-login-permission.sql`).
- `CUSTOMER_USER` has `ACCOUNT.LOGIN, CUSTOMER.READ, CUSTOMER.CONTACT.READ, PRODUCT.READ, QUOTE.READ, ORDER.READ, DOCUMENT.READ`. `CUSTOMER_ADMIN` adds `CUSTOMER.UPDATE`, `CUSTOMER.CONTACT.*`, `CUSTOMER.USER.*`, `CUSTOMER.PORTAL_ACCESS.MANAGE` and `QUOTE.CREATE` (`seed-customer-roles-and-permissions.sql:253-280`).
- Several of these codes are also staff codes, such as `PRODUCT.READ` and `QUOTE.READ`. `/api/v1/admin/*` is protected by `CustomerActorStaffRouteGuardMiddleware` (role-based `StaffEligibilityPolicy`), so customer roles cannot use admin routes even when they hold the same permission codes. The non-admin route that reuses a customer code (`/api/v1/quotes-orders`, `QUOTE.READ`) is scoped correctly in the service layer (see "Checked and OK").
- A customer's tenant scope is derived on the server from `customer.CustomerContactUsers` links (`ResolveAuthenticatedCustomerContextService`). It is never taken from client input. That part is good. Finding 1 covers what this derivation does **not** check.

---

## Findings

### 1. Disabling portal access, a contact, or a customer does not revoke a portal user's data access
- **Severity:** Medium  **Confidence:** Confirmed (code trace)
- **Where:**
  - `core/src/Customer/Application/Service/ResolveAuthenticatedCustomerContextService.php:31`: scope comes from `listActiveLinksForUser()` alone.
  - `src/Customer/Persistence/AzureSqlCustomerContactUserRepository.php:24-37, 169`: `LINK_SELECT_SQL ... WHERE l.[UserId] = :userId AND l.[IsActive] = 1`. It joins `CustomerContacts` and `Customers` but filters on neither `cc.IsActive`, `cc.PortalAccessStatusCode` nor customer status.
  - `core/src/Customer/Application/Service/DisableCustomerPortalAccessService.php:40`: only `changePortalAccessStatus(...)` is called. The link and the user are left untouched.
  - `core/src/Customer/Application/Service/ChangeCustomerContactStatusService.php` and `ChangeCustomerStatusService.php` only flip `IsActive`.
  - `core/src/Customer/Application/Service/ResolveAuthenticatedCustomerCodesService.php:40-45` maps every linked customer to its `CustomerCode` with no `isActive` check.
  - The only consumers of `PortalAccessStatus` are the admin list, filter and counter queries (`grep PortalAccessStatus`). No authorization path reads it.
- **Evidence:**
  ```php
  $links = $this->links->listActiveLinksForUser(UserId::fromInt($userId));   // no contact/customer/portal status
  ...
  return $this->contacts->changePortalAccessStatus(publicId: $id, status: $target, ...); // "suspended" only
  ```
- **Exploit scenario:** Staff suspend a departed buyer's portal access (`PATCH /api/v1/admin/customer-contacts/{id}/portal-access {"enabled":false}`), or deactivate the contact or the whole customer. The buyer's `CUSTOMER_USER` account stays active and linked, so they can still:
  - call `GET /api/v1/quotes-orders`, `GET /api/v1/quotes/{id}`, `/quotes/{id}/document` and `/orders/{id}` and read every quote and order of that customer, including totals, line prices and contact details;
  - get the customer's net sell prices through `POST /api/v1/products/{slug}/variants/pricing`;
  - keep using `/api/v1/cart`.
  The UI shows the contact as "suspended", so admins believe access is gone.
- **Fix:**
  - Enforce access status in `listActiveLinksForUser` (or in `ResolveAuthenticatedCustomerContextService`): require `cc.IsActive = 1 AND cc.PortalAccessStatusCode = 'active' AND c.IsActive = 1`.
  - Make the disable flows also deactivate the link, or set the user's status to disabled.
  - Add a regression test for "suspended contact ⇒ empty quote scope and CUSTOMER_REQUIRED pricing".

### 2. Act-as (impersonation) writes are attributed only to the impersonated user, which bypasses segregation of duties on approvals and signatures
- **Severity:** Medium  **Confidence:** Confirmed (design); impact depends on who holds `ADMINISTRATOR`
- **Where:**
  - `src/Http/Middleware/ActAsMiddleware.php:102`: the request's `AuthenticatedUser` is replaced with the target.
  - `ActAsMiddleware.php:164-176`: only method, route, path, status and duration are logged. The body and the object IDs are not.
  - `ActingAdminAttribute` is read only in `RecordActivitySignalAction` and `GetAuthenticatedUserAction` (`grep -r ActingAdminAttribute src`). No domain write records the acting admin.
  - Separation checks compare against the target identity only. Examples: `src/CustomerContactReport/Persistence/AzureSqlCustomerContactReportRepository.php:854-870` (approver must be the current approver and must not be the requester), `core/src/Npr/Application/Service/NprWorkflowService.php:151-159` (manager endorsement with a signed declaration), and the CCR ICAR signature and AP2 approvals.
- **Exploit scenario:** An administrator raises a CCR credit approval request as themselves. They then start an act-as session as the designated approver (`POST /api/v1/admin/act-as`) and call `POST /api/v1/customer-contacts/{id}/approvals/credit/decision` with the `X-Act-As-Session` header. The self-approval check passes because the actor is now the approver, and the approval is stored as made by the approver. NPR "signed" endorsements (`declaration: true`) and ICAR signatures can be produced the same way. Log notes, RFQ edits and cart or pricing actions written during act-as are likewise indistinguishable from the real user's.
- **Fix:**
  - Block approval, decision, signature and endorsement routes when `ActingAdminAttribute` is present, or make impersonation read-only by default.
  - Otherwise, persist `ActingAdminUserId` / `ActAsSessionId` on the domain audit and history rows, and include the object identifiers in the act-as action log.

### 3. Any signed-in guest can generate official TriCab quotation and order-confirmation PDFs with arbitrary content
- **Severity:** Medium  **Confidence:** Confirmed
- **Where:**
  - `src/Bootstrap/AppBuilder.php:2490`: `$app->post('/api/v1/documents/pdf', GenerateDocumentPdfAction::class);`. There is no permission middleware, and the route is not under `/admin`, so the staff guard does not apply.
  - `src/Http/Action/GenerateDocumentPdfAction.php:110-150`: `authenticate()` only checks that the identity resolves to *a* registered user.
  - `src/DocumentGeneration/GenerateOcrPdfService.php:18-22`: the templates are `tcqu.ocr → quotation.pdf`, `tcord.ocr → order-confirmation.pdf` and `tcpick.ocr → pick-list.pdf`.
- **Exploit scenario:** An attacker self-registers as `GUEST` through the email OTP (only `ACCOUNT.LOGIN` is needed). They POST a crafted 4js `.ocr` stream (Content-Type `text/plain`) that names any customer, prices, bank or payment instructions. The response is a server-rendered PDF on the genuine TriCab quotation or order-confirmation template, which is ideal for invoice-redirection or BEC fraud against TriCab customers.
- **Fix:** Require a staff permission (for example `QUOTE.CREATE` together with `StaffEligibilityPolicy::isEligible`), or move the route under `/api/v1/admin`. Customer-facing quote PDFs should only come from `GET /api/v1/quotes/{id}/document`, which is already scoped to the caller's customers.

### 4. Customer pricing and cart accept any non-deleted variant, including unpublished, archived, inactive or out-of-region products; the `{slug}` in the URL is ignored
- **Severity:** Low (business logic / information disclosure)  **Confidence:** Confirmed (SQL); exploitability needs variant UUIDs
- **Where:**
  - `src/Product/Pricing/AzureSqlVariantCommercialCatalogRepository.php:21-75`: `WHERE v.[PublicId] = :variantPublicId AND v.[DeletedAtUtc] IS NULL`, joined to `Products ... AND p.[DeletedAtUtc] IS NULL`. There is no `v.IsActive`, no product status `published`, no `PublishAtUtc/UnpublishAtUtc` window and no `ProductRegions` visibility check. By contrast, the public catalogue queries in `AzureSqlProductCustomerViewRepository.php` do all of these.
  - `src/Commerce/Persistence/AzureSqlCartRepository.php:509-517` (`resolveProductVariantId`): only `DeletedAtUtc IS NULL`.
  - `src/Http/Action/PublicProduct/PostCustomerVariantPricingBatchAction.php:64-94`: `variantIds[]` is taken from the body and never checked against `{slug}`. `PostCartItemAction` and `PostPrepare*PricingAction` behave the same way.
- **Exploit scenario:** A `CUSTOMER_USER` sends `POST /api/v1/products/any-slug/variants/pricing {"variantIds":[<uuid of a draft or withdrawn variant>]}`. The response is their net sell price for a product that is not yet launched, has been withdrawn, or is not sold in their market. They can then add it to the cart with `POST /api/v1/cart/items`. Variant UUIDs are unguessable but can leak from earlier pages, old carts, staff-shared links, or RFQ and quote tooling.
- **Fix:** Add the same publication, active and region predicates used by the public catalogue repositories to `findCommercialCatalog` and `resolveProductVariantId`, at least when the context is not `MODE_STAFF_SELECTED`. Also assert that each variant belongs to the product resolved from `{slug}`.

### 5. Price Lookup "full list" gate is bypassable with `%`, `**` or `?`
- **Severity:** Low  **Confidence:** Likely (static trace)
- **Where:**
  - `core/src/PriceLookup/Application/Service/SearchPriceLookupService.php:46-53` gates only the exact query `'*'` behind `PRODUCT.PRICING.MANAGE`, with a limit of 10.
  - Every other query goes through `PriceLookupSearchPattern::toLikePattern` (`core/src/PriceLookup/Domain/PriceLookupSearchPattern.php:12-24`). That function maps `*`→`%` and `?`→`_` and does not escape a literal `%` or `_`. The resulting search runs with `SEARCH_LIMIT` = 50.
- **Exploit scenario:** A staff user with only `PRICE_LOOKUP.READ` calls `GET /api/v1/price-lookup/search?q=%25&company=…` (or `q=**`). They get 50 rows of internal **cost** prices, which is more than the 10 rows that management's own full-list query returns.
- **Fix:** Escape `%`, `_` and `[` in user input, reject queries that are only wildcards (or that have fewer than N literal characters), and apply the `PRODUCT.PRICING.MANAGE` check to any pattern that starts with a wildcard.

### 6. Product Assistant conversations are not bound to their owner
- **Severity:** Low  **Confidence:** Confirmed (code); exploitability needs the conversation UUID
- **Where:**
  - `src/Assistant/Infrastructure/AzureSqlAssistantConversationRepository.php:20-35`: `getOrCreate()` loads any conversation by `PublicId` when the region and visibility match. The table has no owner, user or session column.
  - The routes `POST /api/v1/assistant/messages[/stream]` are unauthenticated (`AppBuilder.php:1815-1816`).
- **Exploit scenario:** If a conversation ID leaks (shared screenshot, logs, browser history on a shared device), anyone can continue that conversation. The last 12 turns are fed back to the model, so the attacker can ask "what did I ask before?" and read the victim's prior questions, which may include project or customer details. Staff conversations need STAFF visibility, which limits cross-tier reads.
- **Fix:** Store an owner (user ID, or a random per-browser secret for anonymous users) with the conversation and require it on reuse. Otherwise, start a new conversation.

### 7. Cart line update and reprice are not bound to the cart's customer context (staff price-as)
- **Severity:** Low  **Confidence:** Likely
- **Where:** `core/src/Commerce/Application/Service/UpdateCartItemService.php:60-127`. The line is looked up by owner only (`findLineByPublicId($lineId, $user->userId)`). It is repriced with whatever context the current `pricingContextToken` represents and written back to the *original* cart, which may belong to a different customer.
- **Exploit scenario:** A staff user with `CUSTOMER.PRICE_AS` and `PRODUCT.PRICING.OVERRIDE` builds a cart for customer A. They then `PATCH /api/v1/cart/items/{lineId}` while holding a token for customer B with a −40 % margin override. A's cart line now carries B's or the overridden price, while `MarginSource` and the fingerprint describe B. This matters only once a cart-to-quote route is exposed. `SaveQuoteService` currently reprices at submit (`SaveQuoteService.php:118-146`), which mitigates it.
- **Fix:** Reject the update when the line's cart customer, pricing mode or currency differs from the resolved context (or reprice with the cart's own customer).

### 8. Multi-customer portal users get a non-deterministic pricing customer
- **Severity:** Low  **Confidence:** Confirmed
- **Where:**
  - `core/src/Product/Application/Pricing/ResolveCustomerPricingContextService.php:35` uses `$portal->customerPublicIds[0]`.
  - The link query (`AzureSqlCustomerContactUserRepository.php:24-37, 169`) has no `ORDER BY` and ignores `IsPrimary`.
- **Impact:** A user linked to two customers (for example a distributor and a subsidiary) may be priced as either one from request to request. They can see the other entity's contract prices and add to the "wrong" customer's cart. All of this stays within the user's own entitlements, so it is integrity and confusion rather than cross-tenant access.
- **Fix:** Order by `IsPrimary DESC, LinkedAtUtc` and let the user select from their linked customers explicitly. Validate that selection against the link set.

### 9. `LinkCustomerContactToUserService` links any user without checking contact or customer status or the user's role
- **Severity:** Low (admin-only; hardening)  **Confidence:** Confirmed
- **Where:** `core/src/Customer/Application/Service/LinkCustomerContactToUserService.php:28-52`. Compare `CreateCustomerPortalUserService`, which does check `isActive` and the customer-facing role.
- **Impact:** A staff member with `CUSTOMER.USER.LINK` can attach a self-registered `GUEST` (or any user) to an inactive contact or customer. Because of Finding 1, that user immediately gets the customer's pricing, cart and (if given `QUOTE.READ`) quote scope. Linking a user ID to the wrong customer is also how cross-tenant data exposure would happen in practice, so the operation deserves stronger validation and audit.
- **Fix:** Apply the same preconditions as `CreateCustomerPortalUserService`: active contact, active customer, a customer-facing role on the target user, and the target user not staff.

### 10. Staff-only modules rely solely on permission codes, without a staff-eligibility check (defence in depth)
- **Severity:** Info / Low  **Confidence:** Confirmed (design)
- **Where:** These non-`/admin` staff modules are gated only by `RequirePermission`:
  - `/api/v1/log-notes*` (`CUSTOMER_NOTE.READ`): every note for every customer, with no territory or owner scope (`core/src/LogNote/Application/Service/GetLogNoteService.php`, `ListLogNotesService.php`);
  - `/api/v1/price-lookup/*` (cost prices);
  - `/api/v1/catalogues*`;
  - `/api/v1/customer-contacts*`;
  - `/api/v1/rfqs*`.
  
  The staff route guard covers only `/api/v1/admin`. A role-misconfiguration that grants, for example, `CUSTOMER_NOTE.READ` or `PRICE_LOOKUP.READ` to a customer-oriented role would expose all tenants' notes or cost prices. This is a realistic risk given how many permission names start with `CUSTOMER`.
- **Fix:** Add `StaffEligibilityPolicy::isEligible` to these route groups (or extend `CustomerActorStaffRouteGuardMiddleware` to a list of staff route prefixes). Separately, decide whether log notes and quotes need territory scoping for staff ("staffSearchAll" is documented as PARTIAL parity in `QuoteOrderAccessService`).

### 11. Pricing-context token shares the guest JWT secret and falls back to a predictable secret
- **Severity:** Info  **Confidence:** Confirmed
- **Where:** `src/Bootstrap/ContainerFactory.php:3554-3561`. The secret is `guestJwtSecret`, or `hash('sha256','tricab-dev-pricing-context|'.appEnv)` when that is empty.
- **Impact:** The token formats differ, so a guest JWT cannot be replayed as a pricing token, and the server still re-checks `CUSTOMER.PRICE_AS`, staff eligibility and actor binding. Forging a token therefore gives no escalation today. Key reuse and a deterministic fallback are still weak hygiene.
- **Fix:** Use a dedicated `PRICING_CONTEXT_SECRET` and fail closed when it is missing outside development.

### 12. No upper bounds on cart quantity or length
- **Severity:** Info  **Confidence:** Confirmed
- **Where:** `core/src/Commerce/Application/Service/AddCartItemService.php:77` (only `< 1` is rejected) and `CartLineMath::normalizeLength` (only `> 0` is required).
- **Impact:** Very large values produce huge `LineTotal` values or decimal-overflow 500 errors, and enable cart-total abuse in any downstream quote. They cannot produce negative or zero prices (zero or negative prices become TBC).
- **Fix:** Enforce business maximums for quantity, length and quantity × length.

---

## Areas checked that look correct

- **Quotes & Orders** (`/api/v1/quotes-orders`, `/quotes/{id}`, `/quotes/{id}/document`, `/orders/{id}`):
  - Sequential legacy integer IDs, but scoped server-side. `QuoteOrderAccessService` makes non-staff portal-scoped even with zero links. `visibilitySql()` adds `c.code IN (...)` on both list and detail (`src/QuoteOrder/Legacy/MysqlLegacyQuoteOrderRepository.php:435-461`).
  - A client `customerCode` can only narrow results (it is a LIKE prefix inside the IN-list). Unauthorized IDs return 404.
  - Line items expose `nprc`/`net` but not cost (`cprc`). The order lookup re-checks through the quote's customer.
- **Quote writes** (`SaveQuoteService`): not routed over HTTP. It reprices on the server, checks customer eligibility (portal users only for linked customers), and makes idempotency keys actor-scoped with a request-hash check. The concurrent same-key race is closed by the MySQL `PRIMARY KEY(platform_operation_id)` correlation insert inside the same transaction.
- **Cart and pricing:**
  - Line lookups are owner-scoped (`AzureSqlCartRepository.php:543-573`).
  - Client `unitPrice`, `marginPercent`, `dbco`, `baseCost` and `customerId` are rejected.
  - Prices come only from the server. Customer DTOs strip cost and margin, and staff diagnostics require staff role plus `PRODUCT.PRICING.VIEW`.
  - Price-as and margin-override require a staff role plus a dedicated permission, plus HMAC-signed, actor-bound, 8-hour tokens. The contact must belong to the customer, and the margin is bounded between −40 and 50.
  - Currency derives from customer country, not from the client or region.
- **Public products:** publication, active, window and region filters are applied to detail, customer view, variants, documents (`AccessCode='public'`) and the style-family list. Public serializers exclude internal, legacy and cost fields. Restricted-document download requires `PRODUCT.DOCUMENTS.MANAGE`. Region is client-selectable but only changes catalogue visibility, not prices.
- **Admin surface:** `/api/v1/admin/*` uses a role-based staff guard (percent-decoding aware). Customer and contact update DTOs use explicit field allowlists (no mass assignment), and `CreateCustomerPortalUserService` restricts the role to customer-facing roles.
- **CustomerContactReport:** the visibility object (read-all, creator, assignee or approver) is applied to get, list, export, update and attachments. Update has a strict server-owned field denylist plus a per-role field allowlist. Complete, settlement and approval check the assignee or approver and self-approval on the server. Close and reopen are permission-only by design.
- **RFQ / Quotegen:** `RfqAccessPolicy::canSee` and `allowedActions(EDIT)` are enforced in services (document download is bound to the draft). The Quotegen agent's writes go through `RfqReviewService::save`, which requires EDIT. Pricing redaction applies to both history and model context.
- **NPR:** the own, manager and TSD views are policy-checked (`canReadOwn`, `asManager`, `canReadAsTsd`). Self-endorsement is blocked.
- **Passkeys / trusted devices:** rename and revoke check ownership (`ManageUserPasskeysService::owned`, `RevokeTrustedDeviceService::revokeCurrent`). The list is per-user.
- **Act-as session handling:** admin-only, cannot target admins or self, session bound to the admin, 30-minute expiry, re-validated each request (Finding 2 covers attribution only).
- **LogNote create:** rejects client identity fields and checks that the contact belongs to the customer.
- **AR public credit-note form:** hashed token, expiry, single use, rate limit, atomic submit.
