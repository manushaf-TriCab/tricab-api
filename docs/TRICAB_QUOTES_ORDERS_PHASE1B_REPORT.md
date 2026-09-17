# TRICAB_QUOTES_ORDERS_PHASE1B_REPORT

**Phase:** Quotes & Orders — Phase 1B Identity Mapping + Visibility + Live RO Smoke  
**Date:** 2026-09-17  
**Scope:** tricab-api only  

---

## A. Phase 1 checkpoint

| Item | Value |
|------|--------|
| Commit | `a61379c706605d7ee01069d5c96c96158f7d019c` |
| Message | `feat(quotes): add read-only quotes and orders foundation` |
| Author | Thilan Lakshitha \<thilanh@tricab.com\> |
| Pushed | **No** |

Excluded from Phase 1 commit: unrelated `.gitignore`, `bin/phase4j-*`, `bin/tricab-fix-local-api.command`.

---

## B. Feature flag validation

| Check | Result |
|-------|--------|
| Env key | `QUOTES_ORDERS_LEGACY_READ_ENABLED` |
| Default | **false** (`Settings::fromEnvironment`) |
| Production | Remains false unless explicitly set (not changed in this phase) |
| Effect when true | Wires `MysqlLegacyQuoteOrderRepository` via existing `LEGACY_TC_*` RO connection |
| Effect when false | `NullQuoteOrderRepository` → HTTP **503** `QUOTE_ORDER_SOURCE_UNAVAILABLE` |
| DB writes when enabled | **None** — SELECT only + `LegacyReadOnlySqlGuard` |
| Hard-coded true | **No** |

Local smoke enabled the flag **in-process only** (`putenv` / `$_ENV` for the smoke script). `.env` was not modified to leave production/default false.

---

## C. Customer identity mapping

**Exact chain (reused, no new tables):**

```
AuthenticatedUser
  → ResolveAuthenticatedCustomerContextService
      → customerPublicIds (CustomerContactUser links)
  → ResolveAuthenticatedCustomerCodesService (NEW, mirrors market-codes pattern)
      → CustomerRepository::findDetailsByPublicId
      → Customers.CustomerCode
  → QuoteOrderActor.allowedCustomerCodes
  → QuoteOrderVisibilityScope / MySQL `cust.code IN (...)`
```

`Customers.CustomerCode` is the authoritative bridge to legacy `tc.cust.code`.

**Reused components:** `ResolveAuthenticatedCustomerContextService`, `CustomerRepository` / AzureSqlCustomerRepository, existing contact-user links.

---

## D. Staff visibility mapping

| Actor | Rule |
|-------|------|
| Missing `QUOTE.READ` | Denied |
| `ADMINISTRATOR` + `QUOTE.READ` | `staffSearchAll=true` (management-equivalent) |
| `EMPLOYEE` + `QUOTE.READ` | `staffSearchAll=true` until trusted staff region/territory assignment exists on identity |
| Customer/portal + mapped codes | Only those `CustomerCode`s |
| Customer/portal + no codes | Empty list / quote·order not found |

**Legacy territory/region parity:** Still **PARTIAL**. `[identity].[Users]` has no Country/Territory. No staff territory assignment table found. Inventing filters from client `region=` / `territory=` / `representative=` is forbidden; those params only narrow an already-authorized set.

**NZ special rule / raised-by:** Not ported (requires legacy staff identity fields not present in new model). Documented as remaining unknown.

---

## E. Permissions

| Item | Decision |
|------|----------|
| Entry permission | Existing `QUOTE.READ` |
| `QUOTE.READ_ALL` | **Not added** — absent from catalog; would need Azure permission seed writes |
| Broader staff scope | Role-based (`EMPLOYEE` / `ADMINISTRATOR`) + `QUOTE.READ` |
| DB permission inserts | **NONE** (stopped; not required for 1B) |

---

## F. Security validation

| Path | Behaviour |
|------|-----------|
| List | Portal IN-list / empty; staff search-all; `constrainCriteria` blocks customerCode expansion |
| `GET /quotes/{id}` | Visibility SQL + `canViewCustomerCode`; unauthorized → **QuoteNotFound** (enumeration-safe) |
| `GET /orders/{id}` | Same; unauthorized → **OrderNotFound** |
| Malformed IDs | Not found (non-digit rejected) |
| ID enumeration tests | Added (foreign quote/order → not found; filter cannot expand) |

---

## G. Live smoke (non-prod process flag)

Repository: `MysqlLegacyQuoteOrderRepository`  
Flag in smoke process only: `true`  
`LEGACY_TC_READ_ONLY=true`

| Check | Result |
|-------|--------|
| List pageSize=5 | **OK** — 5 items, total ≈ **138103** |
| Newest rows | Quotes dated 2026-09-17, status `1710`/`open`, totals resolved |
| Quote detail | id `100146013`, **4** lines, total resolved |
| Order detail | id `500023924`, quoteId `100143271`, nested quote present |
| Customer PII in report | Codes/IDs only (no names/emails logged beyond necessary) |

---

## H. Legacy parity matrix (tiny sample)

| FIELD | LEGACY | NEW API | RESULT |
|-------|--------|---------|--------|
| QUOTE ID | tqoth.id | quoteId | **MATCH** |
| DATE | tqoth.de | date | **MATCH** |
| TYPE | order if customer_orders.quote_id else quote | type | **MATCH** (list sample was quotes) |
| STATUS CODE | tqoth.st | statusCode | **MATCH** |
| TOTAL / TBC | tnet; 0 → TBC UI | total / totalStatus pending | **MATCH** (resolved amounts) / **INTENTIONAL DIFFERENCE** (API never returns string `TBC`) |
| CUSTOMER CODE | cust.code | customerCode | **MATCH** (via Azure CustomerCode for portal) |
| REFERENCE | cref / PO fields | reference | **MATCH** (mapper) |
| customerCode×endDate bug | Coupled | Independent | **INTENTIONAL DIFFERENCE** |

---

## I. Performance findings

- Search uses SQL `COUNT(*)` + `LIMIT/OFFSET` (does not load 138k rows into PHP).
- Smoke: 5 items returned while total ≫ 5.
- No legacy MySQL indexes added. If slow under heavy filters, consider reporting an index later — **not applied**.

---

## J. Failure behaviour

| Case | Behaviour |
|------|-----------|
| Flag disabled | 503 / `QuoteOrderSourceUnavailable` |
| Legacy query failure | `QuoteOrderSourceUnavailable::queryFailed()` (no SQL/host leak) |
| Malformed quote/order id | Not found |
| Unauthorized quote/order | Not found (portal) |
| pageSize > 100 | Criteria validation error |
| Unknown status code | Mapped as `unknown` / `Unknown` with raw statusCode preserved |

---

## K. Files changed (Phase 1B)

**Created**
- `core/src/Customer/Application/Service/ResolveAuthenticatedCustomerCodesService.php`
- `core/tests/Customer/Application/Service/ResolveAuthenticatedCustomerCodesServiceTest.php`
- `docs/TRICAB_QUOTES_ORDERS_PHASE1B_REPORT.md`

**Updated**
- `src/Http/Auth/ResolveQuoteOrderActorService.php`
- `core/src/QuoteOrder/Application/DTO/QuoteOrderVisibilityScope.php`
- `core/src/QuoteOrder/Application/Service/QuoteOrderAccessService.php`
- `core/src/QuoteOrder/Application/Service/SearchQuoteOrdersService.php`
- `core/src/QuoteOrder/Application/Service/GetQuoteService.php`
- `core/src/QuoteOrder/Application/Service/GetOrderService.php`
- `core/src/QuoteOrder/Module.php`
- `src/QuoteOrder/Persistence/InMemoryQuoteOrderRepository.php`
- `src/Bootstrap/ContainerFactory.php`
- `tests/QuoteOrder/QuoteOrderServicesTest.php`

---

## L. Tests

```text
./vendor/bin/phpunit --filter 'QuoteOrder|ResolveAuthenticatedCustomerCodes'
OK — 24 tests, 111 assertions
```

Broader: `tests/Http/QuoteOrderEndpointsTest.php` + `MeEndpointTest.php` re-run as part of validation.

---

## M. Remaining unknowns/blockers

1. Staff **region/territory/NZ/raised-by** parity blocked until trusted staff assignment data exists in new identity model.
2. Portal users still empty if Azure customer has null `CustomerCode` or missing contact-user links.
3. HTTP live smoke with real Entra tokens not run (repository-level smoke used instead).

---

## N. Database writes performed

**NONE**

## O. Production changes

**NONE** (flag remains default false; smoke was process-local)

## P. Web/Mobile changes

**NONE**
