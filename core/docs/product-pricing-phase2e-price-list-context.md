# Product Pricing Phase 2E — Price List Context Mapping

## Inspection verdict

| Area | Verdict |
|------|---------|
| `[product].[PriceLists]` | **REUSE** — full schema exists (PK `PriceListId`, `PublicId`, code/name, currency, optional region/market, effective dates, active, soft-delete, `RowVersion`) |
| Legacy `dbco` mapping | **EXTEND** via `[product].[ExternalSystems]` + `[product].[ExternalEntityMappings]` |
| PriceList admin CRUD | **SCHEMA GAP (closed in 2E)** — services/API/UI added |
| Seed for 2507/2517/2519 | **Prepared, not auto-executed** — `database/scripts/development/seed-product-price-lists-phase2e.sql` |
| Pricing tab selector | **EXTEND** — uses lookup bundle `priceLists` |
| Permissions | **REUSE** `PRODUCT.PRICING.VIEW` / `PRODUCT.PRICING.MANAGE` |

No new columns were added to `PriceLists` or price rows. Legacy coupling stays on external mappings.

## PriceList model

Admin-managed pricing context:

- `PublicId`, `PriceListCode`, `PriceListName`
- `CurrencyId` (required)
- `RegionId`, `MarketId` (optional)
- `EffectiveFromUtc`, `EffectiveToUtc` (optional)
- `IsActive`, soft-delete via `DeletedAtUtc`
- `RowVersion` for optimistic concurrency

## Legacy dbco external mapping

Resolution key (authoritative):

```text
source         = legacy-tc
contextType    = dbco
contextCode    = 2507 | 2517 | 2519
```

Stored in `[product].[ExternalEntityMappings]`:

- `ExternalSystemId` → `[product].[ExternalSystems]` where `SystemCode = legacy-tc`
- `EntityTypeCode` = `dbco`
- `ExternalEntityKey` = numeric dbco string
- `InternalEntityId` = `PriceListId`

Unique constraints prevent duplicate active mappings for the same dbco or the same price list.

Friendly labels (`TCA`, `TCUS`, `TCASIA`, example seed names) are presentation only.

## Currency authority

**PriceList currency is authoritative for pricing context.**

When upserting base cost with `priceListPublicId`, the API validates that the submitted `currencyCode` matches the price list currency (`InvalidPriceListCurrency` if not). Price rows still store `CurrencyId`; the price list defines the expected context currency.

Legacy comparison uses price list currency when resolved via `priceListPublicId`; explicit `legacyDebtorCompanyCode` still falls back to application inference (see below).

## Currency inference (documented, not DB-authoritative)

| dbco | Inferred currency | Evidence | Confidence |
|------|-------------------|----------|------------|
| 2507 | AUD | `ProductConfigurator::getDebtCompany` AU/NZ → 2507; Phase 13.2A source verify | **HIGH** — APPROVED for Phase 13.3 |
| 2517 | USD | `getDebtCompany` US → 2517; Phase 13.2A source verify | **HIGH** — APPROVED for Phase 13.3 (supersedes prior LOW) |
| 2519 | USD (unproven) | Staff Price Lookup / RFQ TCASIA only — not catalogue `getDebtCompany` | **LOW** — **EXCLUDED** from Phase 13.3 (`2519_NOT_REQUIRED_FOR_PHASE_13_3`) |

See `tmp/phase13.2a-pricing-reconciliation-report.md`.


## Region relationship

Reuse seeded regions:

- `AU` → Australia (candidate for 2507)
- `AMER` → Americas (candidate for 2517)
- `APAC` → Asia Pacific (candidate for 2519)

`dbco` and Region are separate concepts. Seed script links them as reviewed configuration, not as identity.

## Market relationship

Optional. No assumption that `dbco = market`. Leave unset unless business rules require it.

## New Zealand

No separate NZ `dbco` was found in live `tc.price`. Do not invent TCNZ/NZ dbco in Phase 2E. NZ resolver behaviour remains future work.

## Legacy verification

`POST /api/v1/admin/product-price-lists/{publicId}/verify-legacy`

Uses:

```text
LegacyReadOnlySqlGuard → GuardedReadOnlyMysqlConnection → MysqlLegacyTcRepository::aggregatePriceContextByDbco()
```

Aggregate SELECT only (`COUNT(*)`, `COUNT(DISTINCT code)`). No bulk row load. No legacy writes.

## Permissions

| Permission | Capability |
|------------|------------|
| `PRODUCT.PRICING.VIEW` | List/get/usage/verify |
| `PRODUCT.PRICING.MANAGE` | Create/update/activate/deactivate/archive/restore |

## APIs

| Method | Route |
|--------|-------|
| GET | `/api/v1/admin/product-price-lists` |
| POST | `/api/v1/admin/product-price-lists` |
| GET | `/api/v1/admin/product-price-lists/{publicId}` |
| PUT | `/api/v1/admin/product-price-lists/{publicId}` |
| POST | `/api/v1/admin/product-price-lists/{publicId}/activate` |
| POST | `/api/v1/admin/product-price-lists/{publicId}/deactivate` |
| POST | `/api/v1/admin/product-price-lists/{publicId}/archive` |
| POST | `/api/v1/admin/product-price-lists/{publicId}/restore` |
| GET | `/api/v1/admin/product-price-lists/{publicId}/usage` |
| POST | `/api/v1/admin/product-price-lists/{publicId}/verify-legacy` |

Lookup bundle extended with `priceLists` on `GET /api/v1/admin/product-lookups`.

## Admin UI

Route: `/admin/products/pricing/price-lists`

Product editor Pricing tab: Price List selector with currency/region/dbco context; legacy compare uses selected list when provided.

## Usage protection

Usage counts:

- `ProductPrices` by `PriceListId`
- `ProductVariantPrices` by `PriceListId`
- `PriceListCustomers` assignments

Archive blocked when referenced. No hard delete. `CustomerProductPrices` has no `PriceListId` — customer price count remains 0 by schema design.

## Seed strategy

Script: `database/scripts/development/seed-product-price-lists-phase2e.sql`

- Idempotent MERGE
- Creates `legacy-tc` external system if missing
- Creates three price lists + dbco mappings (2507, 2517, 2519)
- **Not executed automatically** — review currencies/regions/names before running in non-production

## Public isolation

Price lists and base costs remain admin-only. Public product endpoints unchanged; no `dbco`/PriceList leakage.

## Tests

- Core: `ManagePriceListServiceTest`
- API: `ProductPriceListsEndpointsTest`
- Frontend: lookup bundle parse + price list page

## Deferred issues (unchanged)

- 53,216 `tc.price` rows — **not imported**
- 1,127 duplicate `(code, dbco)` groups — future import blocker
- 768 zero-cost rows — data quality, not auto-imported
- ~7,002 mask codes — Phase 2B
- UOM `/m` fallback — Phase 2C
- Customer/public/ERP pricing — out of scope

## STOP

Phase 2E stops here. Next approved phase: **Phase 2C (UOM)** — see [product-pricing-phase2c-uom.md](./product-pricing-phase2c-uom.md), then **Phase 2B (variant legacy identity / mask resolution)**, then controlled base-cost import pilot.
