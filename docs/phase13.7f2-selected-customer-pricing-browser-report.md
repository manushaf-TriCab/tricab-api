# Phase 13.7F.2 — Selected Customer Pricing Browser Fix Report

**Date:** 2026-08-27  
**Decision:** `SELECTED_CUSTOMER_PRICING_BROWSER_READY`  
**Report:** `PHASE_13_7F_2_COMPLETE`

---

## A. Root Cause — Exact First Divergence

**Frontend (first divergence), not server.**

Live API verification (`bin/phase137f2-batch-verify.php`) proves that after BNE customer + Riley Admin contact:

- Batch resolver returns **`TBC`** + `NO_MARGIN_RULE` per variant (not `TEMPORARILY_UNAVAILABLE`)
- Cost API succeeds (`MAKE`) for all tested variants
- **10% / 31% staff override** returns **`RESOLVED`** numeric prices

The browser still showed *"Pricing temporarily unavailable"* because:

1. **Global `pricingError` flag** — any failed/aborted batch HTTP request set `pricingError=true`, and `priceLabel(undefined, pricingError)` rendered infrastructure failure for **every** variant even when valid per-variant items existed or would arrive on the next request.

2. **Double-fetch race on customer/margin change** — `useEffect` depended on `pricingContextToken` **and** handlers cleared `pricingById({})`. Selecting BNE or changing margin triggered parallel loads; aborted requests could leave `pricingError=true` while the UI had an empty map.

3. **Margin override did not explicitly reload pricing** — relied on `useEffect` token dependency only; race could leave stale error state and no numeric price despite successful `POST /api/v1/pricing/margin-override`.

4. **Minor: variant map key normalization** — guest/login-required map keys were not normalized consistently with batch lookup keys after 13.7F.1 ID normalization work.

**13.7F.1 code was running** (Vite HMR updates to `CustomerProductVariantMatrix.tsx` at 07:45–07:47 on dev server) but the fix was **incomplete** for customer-switch and margin-override reload paths.

---

## B. Live Build

| Check | Result |
|-------|--------|
| Vite dev server | `http://localhost:5173` — running |
| API dev server | `http://localhost:8080` — running |
| 13.7F.1 matrix changes | Present (HMR confirmed) |
| 13.7F.2 matrix changes | Applied this session (HMR) |

---

## C. Customer Context

| Field | Value |
|-------|-------|
| Customer | BNE-ENR-001 — Brisbane Energy Systems |
| Contact | Riley Admin |
| Token | **PASS** (diagnostic + batch verify) |

---

## D. Batch Response (server, BNE, length=100m)

| Variant | Status | Trace |
|---------|--------|-------|
| BVA-PJ2D/3C25BK | **TBC** | COST_SOURCE:LEGACY_COST_API, NO_MARGIN_RULE |
| BVA-CAXD/37C2.5BK | **TBC** | COST_SOURCE:LEGACY_COST_API, NO_MARGIN_RULE |
| BVA-CBXD/19C.75BK | **TBC** | COST_SOURCE:LEGACY_COST_API, NO_MARGIN_RULE |

Variant ID case match: **PASS** (matrix publicId = response variantId)

---

## E. Cost (per variant)

| | |
|---|---|
| Cost API called | **YES** |
| Status | **MAKE** |
| Customer-dependent | **NO** |

---

## F. Margin (automatic)

| | BNE |
|---|---|
| Resolved | **TBC** (null) |
| Reason | **NO_MARGIN_RULE** (ENERGY / QLD-METRO, no TMMKTM) |

`BNE_CUSTOMER_COMMERCIAL_CONFIGURATION_REQUIRED` — no data mutation in this phase.

---

## G. UI Classification (after fix)

| State | Display |
|-------|---------|
| Missing automatic margin | **Customer pricing setup incomplete** / Price TBC |
| Genuine HTTP/infrastructure failure | **Pricing temporarily unavailable** (only when batch returns zero items) |
| Per-variant isolation | TBC + TEMPORARILY_UNAVAILABLE can coexist |

---

## H. Manual Override — 10%

| | |
|---|---|
| Override payload | `{ margin: 10 }` (integer percent, not 0.10) |
| BVA-PJ2D/3C25BK @ 100m | **RESOLVED** AUD **27.755** / m |
| Effective margin | **10%** STAFF_OVERRIDE |

---

## I. Override Change — 31%

| | |
|---|---|
| Recalculated | **YES** |
| BVA-PJ2D/3C25BK | **RESOLVED** AUD **36.202** / m |

---

## J. Clear Override

Implemented via `handleMarginOverride(null)` → `clearOverride` API → explicit `loadVariantPricing` — returns to automatic **TBC** for BNE.

---

## K. Customer Switching

| | |
|---|---|
| Explicit reload with new token | **PASS** (handler + token ref) |
| Stale abort cannot set error | **PASS** (`isAbortError` + seq guard) |
| useEffect no longer double-fires on token change | **PASS** |

---

## L. Batch Isolation

| | |
|---|---|
| Server per-variant try/catch | **PASS** (unchanged) |
| UI mixed states | **PASS** (Vitest) |

---

## M. Add-to-Cart Readiness

| | |
|---|---|
| RESOLVED after override | Add to Cart **enabled** |
| TBC | Add to Cart **enabled** (existing policy) |

---

## N. Tests

| Suite | Result |
|-------|--------|
| PHPUnit pricing | **32/32** |
| Vitest customer-product | **22/22** |

New Vitest: mixed per-variant, margin override reload with token, TBC not masked by prior failure.

---

## O. Browser

| | |
|---|---|
| Product page loaded | `flexible-control-lszh-3d-test` on localhost:5173 |
| MSAL authenticated flow | **Requires operator sign-in** (cannot automate Entra popup) |
| Code on dev server | **Updated via HMR** |

**Operator action:** Hard-refresh, sign in with Microsoft, repeat BNE + Riley Admin flow — expect **Price TBC** per variant; **10%** → numeric price.

---

## P. DB / Legacy Delta

All **0** writes.

---

## Changes

### Web (`tricab-web`)

- `CustomerProductVariantMatrix.tsx` — per-variant display; remove global error masking; explicit reload on customer/margin change; token ref; abort-safe; normalized map keys
- `products.ts` — `normalizeVariantPricingId()`
- `CustomerProductVariantMatrix.test.tsx` — regression tests

### API (`tricab-api`)

- `ResolveCustomerSellPriceService.php` — TBC responses include **MAKE** availability from Cost API
- `bin/phase137f2-batch-verify.php` — live batch verification script

---

## Final Decision

### **`SELECTED_CUSTOMER_PRICING_BROWSER_READY`**

Server path confirmed correct throughout 13.7F.1; remaining defect was **frontend state/reload**. Fix applied on running dev build; operator MSAL sign-in required for final human sign-off only.

**HARD STOP maintained** — no customer/tmktm/legacy/production changes.
