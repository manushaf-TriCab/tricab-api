# Phase 13.7G — Runtime Legacy Cost API Pricing Report

**Date:** 2026-08-27  
**Environment:** `APP_ENV=development`, Azure SQL `tricab-mel/tc`  
**Decision:** `ALL_PUBLISHED_RUNTIME_COST_API_PRICING_READY`

---

## A. Previous behaviour

Only **BVA** showed numeric customer pricing because:

1. Phase 13.4 pilot seeded Azure `ProductVariantPrices` for BVA only.
2. `LEGACY_COST_API_ENABLED=false` (or `legacy-fallback` mode) blocked non-migrated variants.
3. `ResolveCustomerSellPriceService` returned `PRICE_NOT_AVAILABLE` when no Azure base cost existed and Legacy Cost API was not selected.
4. BVA continued on the Azure base-cost path when Cost API was disabled.

There was no product-specific allowlist — the gap was **data + configuration**, not a hard-coded BVA filter.

---

## B. New cost-source rule

Non-production runtime precedence (when `PRICING_COST_SOURCE=legacy-cost-api`):

```text
1. Legacy Cost API (primary, all variants including BVA)
2. Azure ProductVariantPrices — metadata only (price list, availability); NOT used as formula base cost
3. No Azure STOCKED fallback when Cost API fails in primary mode
```

Configuration:

| Setting | Non-prod value | Production default |
|---------|----------------|-------------------|
| `LEGACY_COST_API_ENABLED` | `true` | `false` |
| `PRICING_COST_SOURCE` | `legacy-cost-api` | `legacy-fallback` (when unset) |

Helper: `Settings::isLegacyCostApiPrimaryCostSource()`.

Trace markers: `PRICING_COST_SOURCE_MODE:legacy-cost-api`, `COST_SOURCE:LEGACY_COST_API`, `BASE_META_FROM_CATALOG`.

---

## C. Environment proof

Runtime settings (PHP `Environment::settings()` after `.env` load):

```text
APP_ENV=development
LEGACY_COST_API_ENABLED=true
PRICING_COST_SOURCE=legacy-cost-api
PRIMARY=true
LEGACY_COST_API_URL=https://www.tricab.com/cost-api/
```

API restarted and health-checked: `GET /health` → 200.

---

## D. BVA — Legacy Cost API

| Variant | Cost source | Cost API | DBCO | Status (BNE, no override) |
|---------|-------------|----------|------|---------------------------|
| BVA-CAXD/10C2.5BK | LEGACY_COST_API | YES | 2507 | TBC (no TMMKTM) |
| BVA-CAXD/11C1.5BK | LEGACY_COST_API | YES | 2507 | TBC |

**Staff override (HTTP, BNE-ENR-001 / Riley Admin):**

| Variant | Margin | Status | Unit price | Trace |
|---------|--------|--------|------------|-------|
| BVA-CBXD/9C1BK | -31% | RESOLVED | AUD 9.382/m | `COST_SOURCE:LEGACY_COST_API` |
| BVA-PAXA/5C50BK | -31% | RESOLVED | AUD 16.983/m | `COST_SOURCE:LEGACY_COST_API` |

Azure base cost is **not** used in formula (`BASE_COST_FROM_AZURE` absent from trace).

---

## E. Non-BVA (catalog supplement — no Azure base cost)

`tc` has only **BVA** as a published storefront product. Non-BVA verification uses catalog variants **without** `ProductVariantPrices` (same resolver path as pricing API):

| Style | Variant | Cost source | Availability | Status |
|-------|---------|-------------|--------------|--------|
| BHA | BHA-CATD/10C1.5OR | LEGACY_COST_API | MAKE | TBC |
| BHA | BHA-CATD/7C1.5RE | LEGACY_COST_API | MAKE | TBC |
| BHC | BHC-PAGD/2C1.5OR | LEGACY_COST_API | MAKE | TBC |
| BHC | BHC-PATN/2C1.5BK | LEGACY_COST_API | MAKE | TBC |
| BVC | BVC-CAXD/10C1.5BK | LEGACY_COST_API | MAKE | TBC |
| BVC | BVC-CAXN/5C1.5GY | LEGACY_COST_API | MAKE | TBC |
| BVM | BVM-PAXD/1C16EA | LEGACY_COST_API | MAKE | TBC |
| BVM | BVM-PAXD/1C35EA | LEGACY_COST_API | MAKE | TBC |

Previously these would show “Contact us for pricing” / no numeric price because Azure base cost was missing and Cost API was off.

---

## F. DBCO resolution

All samples resolve DBCO via `ResolvePricingDbcoService` → **2507** for `TCA-BASE` / BNE customer context (not hard-coded in resolver).

---

## G. MAKE / BUY handling

| Path | Result |
|------|--------|
| MAKE (Cost API success) | Availability MAKE, cost from `calculatedCostPerMeter`, continues to margin |
| BUY (Cost API success) | Covered by unit tests (`test_stocked_buy_cost_resolves_in_primary_mode`) |
| Cost API failure | `TEMPORARILY_UNAVAILABLE` — no Azure fallback in primary mode |

Live non-migrated probe: 29/30 Cost API successes (MAKE), 1 UNKNOWN failure isolated.

---

## H. Length pricing (BVA-CAXD/10C2.5BK)

| Length | Cost API called | Cost source | Status |
|--------|-----------------|-------------|--------|
| 1m | YES (`length=1`) | LEGACY_COST_API | TBC |
| 10m | YES (`length=10`) | LEGACY_COST_API | TBC |
| 100m | YES (`length=100`) | LEGACY_COST_API | TBC |

Each length triggers a separate Cost API call (no cross-length reuse).

---

## I. Automatic margins

| Case | Result |
|------|--------|
| BNE + BVA, no TMMKTM | **TBC** (not unavailable) |
| Customer with automatic margin (unit tests) | **RESOLVED** numeric |

---

## J. Staff override

HTTP flow (`bin/phase137f6-cbxd-negative-margin-diagnose.php`):

- BNE automatic margin = TBC
- Select **-31%** → Cost API cost 12.291 → **AUD 9.382/m** RESOLVED
- Policy accepts -31% within [-40, 50]
- Clear override → returns to automatic/TBC (matrix includes `null` margin case)

---

## K. Batch isolation

Unit test `test_batch_isolation_mixed_statuses`: mixed RESOLVED / TBC / TEMPORARILY_UNAVAILABLE in one batch — **PASS**.

Live sequential batch (BNE): per-variant isolation preserved.

---

## L. Performance (sequential resolver, ms)

| Variants | Time |
|----------|------|
| 1 | ~70–87 |
| 5 | ~188–412 |
| 10 | ~972 |

Chunking from Phase 13.7F.5 unchanged on frontend; backend batch endpoint processes variants independently.

---

## M. Frontend state

No frontend changes in 13.7G. Phase 13.7F.3–F.6 protections retained (`pricingContextToken`, merge batch results, stale guards). Vitest customer-product suite: **33/33 PASS**.

---

## N. Cart

Cart prepare path uses same `ResolveCustomerSellPriceService` — Cost API-derived runtime cost applies. No Azure cost persistence added.

---

## O. Tests

| Suite | Result |
|-------|--------|
| PHPUnit pricing (`core/tests/Product/Application/Pricing/` + batch HTTP test) | **69 tests, 306 assertions — OK** |
| Phase 13.7G regression (`Phase137gRuntimeCostApiTest`) | **8 tests, 30 assertions — OK** |
| Vitest customer-product (`tricab-web`) | **33 tests — OK** |

Phase 13.7G regression coverage: BVA+Azure→Cost API, non-BVA no Azure, MAKE, BUY, length, API failure, missing margin→TBC, staff override, batch isolation.

---

## P. Browser

| Check | Result |
|-------|--------|
| BVA product page loads | PASS (`flexible-control-lszh-3d-test`) |
| Variant matrix (9C 1mm² / BVA-CBXD/9C1BK) | PASS |
| Staff pricing UI | Requires sign-in (expected); full staff flow verified via authenticated HTTP API (`phase137f6`, dev Entra headers) |
| Non-BVA storefront products | N/A — only BVA published in `tc`; non-BVA verified via API resolver |

---

## Q. DB / legacy delta

```text
ProductVariantPrices writes: 0
ProductPrices writes: 0
CustomerProductPrices writes: 0
PricingTiers writes: 0
PriceListCustomers writes: 0
Legacy Cost API writes: 0 (read-only)
```

---

## Files changed

- `src/Config/Settings.php` — `PRICING_COST_SOURCE`, defaults, `isLegacyCostApiPrimaryCostSource()`
- `core/src/Product/Application/Pricing/ResolveCustomerSellPriceService.php` — primary Cost API mode
- `src/Bootstrap/ContainerFactory.php` — wire `pricingCostSource`
- `.env.example` — document flags
- `core/tests/Product/Application/Pricing/Phase137gRuntimeCostApiTest.php` — regression suite
- `bin/phase137g-runtime-cost-api-verify.php` — live read-only verification

---

## Final decision

**`ALL_PUBLISHED_RUNTIME_COST_API_PRICING_READY`**

All published variants (and non-migrated catalog variants) attempt pricing through Legacy Cost API regardless of Azure base-cost migration. BVA uses Cost API, not Azure base. Margin/TBC and staff override behaviour preserved. No pricing data migrated or persisted.

**`PHASE_13_7G_COMPLETE`**
