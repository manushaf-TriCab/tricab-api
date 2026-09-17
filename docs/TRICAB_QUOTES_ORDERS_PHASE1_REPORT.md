# TRICAB_QUOTES_ORDERS_PHASE1_REPORT

**Phase:** Quotes & Orders — Phase 1 READ-ONLY domain foundation  
**Date:** 2026-09-17  
**Scope:** tricab-api only (no web/mobile UI, no Create Quote, no schema writes)

> Discovery report `TRICAB_QUOTES_ORDERS_DISCOVERY_REPORT` was referenced but **not found in the repo**. This phase used legacy `QuoteManagement` / quote-history UI in tc-web plus prior live RO table verification notes (~138k quotes on `tc.tqoth`).

---

## A. Data source found

| Fact | Value |
|------|--------|
| Authoritative store | Legacy MySQL database `tc` |
| Access path | `LEGACY_TC_*` via `LiveLegacySourceConfig` → `LegacyReadOnlyConnection` |
| Read-only guard | `LegacyReadOnlySqlGuard` (`LEGACY_TC_READ_ONLY=true`) |
| Quote header | `tc.tqoth` (production; **not** `tqoth_dev`) |
| Quote lines | `tc.tqoti` |
| Line packs/comments | `tc.tqote` |
| Orders | `tc.customer_orders` (`quote_id` → quote) |
| Supporting | `tc.cust`, `tc.contacts`, `tc.descript` |
| Volume | ~138k quote headers (discovery / live RO) |

Azure SQL is **not** an operational copy of quote history.

---

## B. Azure quote-table verification

| Check | Result |
|-------|--------|
| Operational `tqoth` / `tqoti` / `tqote` / `customer_orders` on Azure SQL | **Not present** as live quote tables |
| Staging shell | `migration.LegacyQuotesRaw` (empty staging only) |
| Conclusion | Azure = **NO COPY** of quote history for Phase 1 reads |

No Azure schema inspection writes were performed in this phase.

---

## C. Legacy connection mechanism

Existing bridge (same pattern as TriExpense legacy read):

1. `LiveLegacySourceConfig::fromEnvironment()` (`LEGACY_TC_DSN` / host parts + user/password)
2. `LegacyReadOnlyConnection` (guarded MySQL)
3. `LegacyReadOnlySqlGuard::assertReadOnly()` before every query
4. Feature flag: `QUOTES_ORDERS_LEGACY_READ_ENABLED` → `Settings::$quotesOrdersLegacyReadEnabled` (default **false**)

When flag is false or TC connection cannot be resolved → `NullQuoteOrderRepository` + services throw `QuoteOrderSourceUnavailable` when the flag is off.

---

## D. Source chosen

**Chosen:** Legacy MySQL `tc` read-only for all Phase 1 search/detail.

**Not chosen:** Azure SQL (no operational quote tables).

Replaceable boundary: `TriCab\Core\QuoteOrder\Contract\QuoteOrderRepository` so a future Azure adapter can swap without changing HTTP contracts.

---

## E. Domain architecture

```
core/src/QuoteOrder/          Domain + application (no tqoth names in public DTOs)
src/QuoteOrder/               Infrastructure (MySQL / InMemory / Null / mapper)
src/Http/Action/QuoteOrder/   HTTP actions + serializer
```

| Layer | Types |
|-------|--------|
| Domain VOs | `QuoteStatus`, `QuoteOrderType`, `MoneyTotal` |
| Contract | `QuoteOrderRepository` |
| Application DTOs | `QuoteOrderSummary`, `QuoteDetails`, `QuoteItemDetails`, `OrderDetails`, `QuoteOrderSearchCriteria`, `QuoteOrderSearchResult`, `QuoteOrderVisibilityScope`, `QuoteOrderActor` |
| Services | `QuoteOrderAccessService`, `SearchQuoteOrdersService`, `GetQuoteService`, `GetOrderService` |
| Exceptions | `QuoteNotFound`, `OrderNotFound`, `QuoteOrderAccessDenied`, `QuoteOrderSourceUnavailable` |
| Infra | `MysqlLegacyQuoteOrderRepository`, `InMemoryQuoteOrderRepository`, `NullQuoteOrderRepository`, `LegacyQuoteOrderMapper` |

Permission constant: `QuoteOrder\Module::PERMISSION_READ = 'QUOTE.READ'` (existing catalog; **no new permission rows seeded**).

---

## F. Legacy → Domain field mapping

| Legacy | Public DTO field |
|--------|------------------|
| `tqoth.id` | `quoteId` / list `id` (quotes) |
| `customer_orders.po_id` | `orderId` / list `id` (orders) |
| `tqoth.de` | `date` |
| `tqoth.dvalid` | `validDate` |
| `tqoth.cref` | `reference` (quotes) |
| `customer_orders.customer_po_no` / `reference` | `reference` (orders) |
| `tqoth.st` | `statusCode` (+ mapped `status` / `statusLabel`) |
| `tqoth.tnet` | `total` + `totalStatus` via `MoneyTotal` |
| `tqoth.cy` | `currency` (3200→AUD, 3201→USD, 1006807→NZD) |
| `cust.code` / `cust.name` | `customerCode` / `customerName` |
| `descript.desc_name` | `representative` |
| `contacts.name` (+ `cus_gen`) | `raisedBy` / `contactName` |
| `tqoti.*` | `QuoteItemDetails` (ZZDEL lines excluded) |
| `tqote.*` | `QuoteItemPackDetails` |

Public JSON never exposes `tqoth`, `tnet`, `cata`, etc.

---

## G. Status mapping

| Code | `status` | `statusLabel` |
|------|----------|---------------|
| 1705 | `draft` | Draft |
| 1703 | `finalized` | Finalized |
| 1710 | `open` | Open |
| 1711 | `closed` | Closed |
| other | `unknown` | Unknown (`statusCode` preserved) |

No additional codes invented.

---

## H. QOT/ORD mapping

| Rule | Behaviour |
|------|-----------|
| `customer_orders.quote_id` present | `type=order`, list `id` = `po_id` |
| No order row | `type=quote`, list `id` = quote id |

Unlike legacy UNION (which can emit both a QOT and ORD row for the same quote), Phase 1 classifies each header **once**.

---

## I. Visibility/security mapping

| Actor | Behaviour |
|-------|-----------|
| Missing `QUOTE.READ` | Denied (`RequirePermission` + `QuoteOrderAccessService`) |
| Staff (`EMPLOYEE`) / `ADMINISTRATOR` with `QUOTE.READ` | Search allowed; optional filters applied in repository |
| Customer-portal scoped (linked customers, not staff/admin) | Restricted by server-side `allowedCustomerCodes` |
| Portal + empty `allowedCustomerCodes` | **Empty results** (not client-filterable) |

**Phase 1B unknown:** Azure `customerPublicId` → legacy `cust.code` resolution is not implemented. HTTP builds actors with empty `allowedCustomerCodes`, so portal users correctly get empty lists until 1B.

**Partial:** Full legacy region/territory parity is **not** reproduced (no territory tables invented). Administrators get broader search; staff with `QUOTE.READ` can search with optional filters.

Client query filters are **never** trusted for authorization.

---

## J. API endpoints

| Method | Path | Permission |
|--------|------|------------|
| GET | `/api/v1/quotes-orders` | `QUOTE.READ` |
| GET | `/api/v1/quotes/{id}` | `QUOTE.READ` |
| GET | `/api/v1/orders/{id}` | `QUOTE.READ` |

**No** POST / PATCH / DELETE.

Search query params: `reference`, `customerCode`, `representative`, `from`, `to`, `type=all|quote|order`, `status`, `page`, `pageSize` (default 25, max 100).

When `QUOTES_ORDERS_LEGACY_READ_ENABLED=false` → HTTP **503** `QUOTE_ORDER_SOURCE_UNAVAILABLE`.

---

## K. Example API responses

List (`GET /api/v1/quotes-orders`) — shape inside `data`:

```json
{
  "items": [{
    "id": "100146010",
    "type": "quote",
    "quoteId": "100146010",
    "orderId": null,
    "date": "2026-09-17",
    "raisedBy": null,
    "representative": "Alex Rep",
    "customerCode": "ACME01",
    "customerName": "Acme Pty",
    "reference": "Q-100146010",
    "status": "open",
    "statusCode": "1710",
    "statusLabel": "Open",
    "total": "504.19",
    "totalStatus": "resolved",
    "currency": "AUD"
  }],
  "page": 1,
  "pageSize": 25,
  "total": 123
}
```

TBC (`tnet` 0.00): `"total": null`, `"totalStatus": "pending"` (never the string `"TBC"`).

---

## L. Files changed

### Created (core)

- `core/src/QuoteOrder/Module.php`
- `core/src/QuoteOrder/Domain/ValueObject/{QuoteStatus,QuoteOrderType,MoneyTotal}.php`
- `core/src/QuoteOrder/Contract/QuoteOrderRepository.php`
- `core/src/QuoteOrder/Application/DTO/*`
- `core/src/QuoteOrder/Application/Service/{QuoteOrderAccessService,SearchQuoteOrdersService,GetQuoteService,GetOrderService}.php`
- `core/src/QuoteOrder/Application/Exception/*`
- `core/tests/QuoteOrder/Domain/QuoteOrderValueObjectTest.php`

### Created (api)

- `src/QuoteOrder/Legacy/{MysqlLegacyQuoteOrderRepository,NullQuoteOrderRepository}.php`
- `src/QuoteOrder/Persistence/InMemoryQuoteOrderRepository.php`
- `src/QuoteOrder/Mapping/LegacyQuoteOrderMapper.php`
- `src/Http/Action/QuoteOrder/{ListQuoteOrdersAction,GetQuoteAction,GetOrderAction,QuoteOrderDtoSerializer}.php`
- `src/Http/Auth/{ResolveQuoteOrderActorService,QuoteOrderErrorMapper}.php`
- `src/Http/Request/QuoteOrder/QuoteOrderListQuery.php`
- `tests/QuoteOrder/QuoteOrderServicesTest.php`
- `tests/Http/QuoteOrderEndpointsTest.php`
- `docs/TRICAB_QUOTES_ORDERS_PHASE1_REPORT.md`

### Modified

- `src/Config/Settings.php` — `quotesOrdersLegacyReadEnabled`
- `.env.example` — `QUOTES_ORDERS_LEGACY_READ_ENABLED`
- `src/Bootstrap/ContainerFactory.php` — repository + services + actions
- `src/Bootstrap/AppBuilder.php` — `registerQuoteOrderRoutes`

Unrelated WIP preserved. **tricab-web / mobile not modified.**

---

## M. Tests added/results

```text
./vendor/bin/phpunit --filter QuoteOrder
OK — 18 tests, 98 assertions
```

Coverage includes: status mapping, QOT vs ORD, TBC totals, filters (customer/reference/date/type/status), customerCode independent of dates, pagination, quote details/lines, order details, unauthorized (no `QUOTE.READ`), portal empty codes, HTTP authz + InMemory list/get, source-disabled 503.

**No MySQL writes** in tests (InMemory fixtures only).

---

## N. Legacy parity differences

| Topic | Legacy | Phase 1 |
|-------|--------|---------|
| Customer code filter | Coupled to `$endDate != ''` (defect) | **Independent of dates** (intentional) |
| Result cap | Client DataTables / ~300 UNION rows | Server pagination (`page`/`pageSize`) |
| QOT+ORD duplicate rows | UNION can list both | Unique type classification |
| Region/territory | Complex staff rules | **PARTIAL** — not fully ported |
| Portal customer codes | Session/`cus_op` | Empty until Phase 1B Azure→legacy map |
| TBC display | String `TBC` | `total=null`, `totalStatus=pending` |

---

## O. Unknowns/blockers

1. **Phase 1B:** Azure customer public id → legacy `cust.code` mapping not available → portal visibility returns empty by design.
2. **Staff territory/region:** Full parity needs trusted territory data; not invented here.
3. **Live MySQL smoke:** Not required for unit tests; enable `QUOTES_ORDERS_LEGACY_READ_ENABLED` + `LEGACY_TC_*` in a non-prod environment to smoke-test `MysqlLegacyQuoteOrderRepository`.
4. Discovery report file missing from repo (content reconstructed from legacy code + prior verification notes).

No credential or configuration writes were required to complete the foundation.

---

## P. Database writes performed

**NONE**
