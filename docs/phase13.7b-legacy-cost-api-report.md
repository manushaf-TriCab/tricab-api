# Phase 13.7B — Legacy Cost API Adapter

**Decision:** `LEGACY_COST_API_INTEGRATION_READY`  
**Date:** 2026-08-26  
**Scope:** Server-side Cost API adapter + sell-price length plumbing + modal length refetch. No ProductVariantPrices writes. No MAKE formula reimplementation.

---

## A. Summary

Interim effective cost for customer sell pricing is loaded from the live legacy Cost API (`https://www.tricab.com/cost-api/`) on the API server only. Browser never calls Cost API. Customer DTOs never include `defaultCost`, `calculatedCostPerMeter`, `baseCost`, `marginPercent`, `dbco`, or `PriceListId`. Formula remains `sell = (effectiveCost/fx) * 100/(100-margin)`.

---

## B. Contracts

| Type | Location |
|------|----------|
| `CostResolution` | `core/src/Product/Application/Pricing/CostResolution.php` |
| `LegacyCostCalculationGateway` | `core/src/Product/Application/Pricing/LegacyCostCalculationGateway.php` |
| `ResolvePricingDbcoService` | `core/src/Product/Application/Pricing/ResolvePricingDbcoService.php` |
| Statuses | `LENGTH_REQUIRED`, `COST_SOURCE_UNAVAILABLE` (+ map MAKE failures → `TEMPORARILY_UNAVAILABLE`) |

DBCO (server-only): TCA-BASE / AUD|NZD → `2507`; TCUS-BASE / USD → `2517`; never `2519`.

---

## C. HTTP Adapter

| Adapter | Role |
|---------|------|
| `HttpLegacyCostCalculationGateway` | GET with `DBCO`, `productCode`, `userInputLength`; validate success/status/codes; request cache; max 1 retry on timeout/5xx |
| `DisabledLegacyCostCalculationGateway` | Always `success=false`, `DISABLED` |
| `FakeLegacyCostCalculationGateway` | Seedable map for tests |

Metrics: `error_log` one-liners `cost_api_success|failure|timeout status=MAKE|STOCKED` — **never** log cost amounts.

Dedicated `StreamJsonHttpClient` timeout from `LEGACY_COST_API_TIMEOUT_SECONDS` (default 8).

---

## D. Settings

```
LEGACY_COST_API_ENABLED=false   # default off; opt-in per env
LEGACY_COST_API_URL=https://www.tricab.com/cost-api/
LEGACY_COST_API_TIMEOUT_SECONDS=8
```

`Settings::isLegacyCostApiEnabled()` → enabled && URL non-empty.

---

## E. ResolveCustomerSellPriceService flow (when enabled)

1. Load Azure base **meta** (variantCode, style, price list, uom, currency) — null → `PRICE_NOT_AVAILABLE`.
2. Resolve DBCO server-side.
3. No length → probe Cost API with length `"1"`:
   - MAKE → `LENGTH_REQUIRED` (length/qty enabled, unitPrice null, canAddToRfq false).
   - STOCKED → use effective cost, continue formula.
4. Length provided → validate &gt; 0; call Cost API with that length.
5. MAKE failure → `TEMPORARILY_UNAVAILABLE` — **no Azure base fallback**.
6. STOCKED failure → optional Azure fallback with trace `COST_API_FAILED_AZURE_STOCKED_FALLBACK`.
7. Success → `costForFormula` = Cost API `effectiveCostPerUnit()`; trace `COST_SOURCE:LEGACY_COST_API`.
8. Existing FX + margin + staff override + formula unchanged.

When disabled: Azure-only behavior unchanged.

---

## F. Call sites (length passed)

- `PrepareCartItemPricingService` / `PrepareRfqLinePricingService`
- `AddCartItemService` / `UpdateCartItemService` / `RepriceCartService`
- `PostCustomerVariantPricingBatchAction` — optional body `length`; `422 INVALID_LENGTH`; still rejects client `dbco`

`LENGTH_REQUIRED` → prepare/add reject (`canAddToCart` false).

---

## G. Frontend (tricab-web)

- `fetchCustomerVariantPricingBatch` accepts optional `length`
- Matrix: debounced (~400ms) refetch on length change
- Displays `Enter length to calculate price` for `LENGTH_REQUIRED`
- Never renders diagnostic cost fields to customers

---

## H. Tests

- `Phase137bLegacyCostApiTest` — MAKE 170 → 226.582 @ 50%; STOCKED defaultCost; LENGTH_REQUIRED; MAKE fail no Azure; staff override; invalid length; customer DTO hygiene; HTTP gateway + cache; STOCKED Azure fallback
- Existing `ResolveCustomerSellPrice*` / `Phase137Staff` / `Phase137a` inject Disabled gateway

---

## I. Live verify

`bin/phase137b-legacy-cost-api-verify.php` (non-prod):

| Check | Result |
|-------|--------|
| MAKE `KN-PAXN/1C500OR` @ 170 vs expected 113.291 | **MATCH** |
| MAKE length variance 170 vs 600 vs 1200 | **PASS** (effective costs differ; amounts not logged) |
| STOCKED live sample | **UNIT_TEST_COVERED** / `STOCKED_SAMPLE_REVIEW` (BVA pilot SKUs return MAKE from Cost API) |
| ProductVariantPrices delta | **0** (or skipped if Azure SQL temporarily unavailable) |

PHPUnit: **37** related tests passed. Vitest (cart + customer-product): **10** passed.

---

## J. Hard-rule compliance

| Rule | Status |
|------|--------|
| Browser never calls Cost API | Pass |
| Customer DTO omits cost/dbco fields | Pass |
| No MAKE formula reimplementation | Pass |
| No ProductVariantPrices migration/write | Pass |
| Formula sell = (cost/fx)*100/(100-margin) | Pass |
| DBCO server-only 2507/2517 | Pass |
| No silent Azure fallback for MAKE | Pass |
| No cost amounts in ordinary logs | Pass |
| Cost values exposed to browser | **NO** |

---

## K. Risks / follow-ups

- Cost API latency under batch modal — request cache + 8s timeout + 1 retry
- Enable flag flips BVA pilot SKUs to Cost API MAKE (length-aware) vs Azure-only metre base
- Live STOCKED SKU sample still review (`STOCKED_SAMPLE_REVIEW`)
- **LEGACY_API_SECURITY_REVIEW** — Cost API currently unauthenticated HTTPS
- Durable cart reprice length-aware after enabling flag
- Later: native `ResolveMakeCostService` / `VariantAvailabilityRepository` replacement

---

## L. Remaining reviews

Cost API auth · ERP/native costing · TCASIA · full TCUS · TAS · RFQ snapshot

---

## Final decision

```
LEGACY_COST_API_INTEGRATION_READY
```

Enable only via `LEGACY_COST_API_ENABLED=true` in non-production `.env`. Do not force-enable production.

**STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL**

