# PHASE 13.4A — Admin Pricing Read Path Fix

**Generated:** 2026-08-26  
**Environment:** development / tricab-mel / tc · Production: NO

---

## A. Root Cause

**Classification: D (bad error mapping) + missing Phase 2C UOM columns (not region).**

| Layer | Detail |
|-------|--------|
| Frontend | `GET /api/v1/admin/products/{productPublicId}/pricing` via `getProductPricing` → `useProductPricing` → `PricingPanel` |
| Action | `GetProductPricingAction` |
| Services | `GetProductPricingService` + `GetProductPricingOverviewService` + `GetProductPricingMatrixService` |
| Repository | `AzureSqlProductPricingRepository::listMatrixByProduct` / list pricing |
| Underlying SQL | References `ProductVariantPrices.SpecificationUnitId`, `Products.PricingUnitId`, `ProductVariants.PricingUnitId` |
| Driver error | `Invalid column name 'SpecificationUnitId'` (SQL 207) |
| Error mapper | `ProductManagementErrorMapper` mapped **any** `isInvalidObjectReference()` to: *"missing a required region table or column…"* |

Region tables were **not** the failure. The UI message was a misclassified UOM schema gap.

---

## B. Region Schema

| Object | Expected by pricing? | Exists | Status |
|--------|---------------------|--------|--------|
| `product.Regions` | Optional LEFT JOIN only | YES | OK |
| `product.ProductRegions` | No (base-cost read) | YES | OK |
| `product.Markets` | Optional LEFT JOIN | YES | OK |
| `product.ProductMarkets` | No | YES | OK |
| `product.MarketRegions` | No | NO | N/A for pricing |
| `Products.PricingUnitId` | YES (UOM resolve) | YES (after fix) | Applied |
| `ProductVariants.PricingUnitId` | YES | YES (after fix) | Applied |
| `ProductVariantPrices.SpecificationUnitId` | YES | YES (after fix) | Applied |
| `ProductPrices.SpecificationUnitId` | YES (product prices) | YES (after fix) | Applied |
| `product.SpecificationUnits` | YES | YES (pre-existing) | OK |

```text
REGION_SCHEMA_CURRENT
```

UOM columns were applied from existing idempotent script:

`database/scripts/development/alter-product-pricing-uom-phase2c.sql`

No region migration was required or run.

---

## C. Pricing Data Verification

```text
BVA ProductId: 9970E6F0-F091-F111-B337-000D3AE094E4
BVA variants total: 485
TCA-BASE prices expected: 20
TCA-BASE prices found: 20
Batch p134-tca-BVA-20260826T010912 owned: 20

Data changed: NO
```

Pilot UnitPrice / EffectiveFrom / ownership untouched.

API vs DB cost match: **20 / 20**.

---

## D. Fix

| Change | Reason |
|--------|--------|
| Applied Phase 2C UOM ALTER (nullable FKs) | Pricing SQL already required these columns |
| `ProductManagementErrorMapper` | Only emit region-migration wording when driver message mentions region objects; otherwise generic schema mismatch |

Architecture impact: none on price identity. Base cost remains PriceList + Currency (TCA-BASE / AUD). Region stays optional metadata, not required for admin base-cost reads.

---

## E. API Verification (live)

```text
Status: OK
PriceList: TCA-BASE (ACTIVE)
Currency: AUD
Rows (items): 20
Matrix TCA with cost: 20
Lookups priceLists: includes TCA-BASE / AUD / dbco 2507
```

---

## F. UI Verification

```text
BVA Pricing: service path restored (GET …/pricing succeeds)
TCA-BASE selector: present in lookups
Rows visible: Vitest Pricing tab renders TCA row + selector
Region error: absent (mapper + schema fixed)
```

Refresh Admin → Products → BVA → Pricing in the browser to confirm the live session (API already green).

---

## G. Tests

```text
PHP: ProductManagementErrorMapperSchemaTest — 2 OK
PHP: ProductPricingUomServiceTest — 2 OK
Vitest: parse.pricing.test.ts + ProductDetailsPage Pricing tab — 12 OK
```

---

## H. Database Delta (this phase)

```text
ProductVariantPrices: 0 (still 20 total; pilot unchanged)
ProductPrices: 0
CustomerProductPrices: 0
PricingTiers: 0
PriceListCustomers: 0
```

Schema-only additive columns; no pricing row writes.

---

## I. Phase 13.5 Readiness

```text
PHASE_13_5_READY
```

Requires separate explicit operator approval before any bulk TCA apply.

---

```text
STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL
```
