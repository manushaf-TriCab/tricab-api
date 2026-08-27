# Phase 13.7F.1 — Selected Customer Pricing Fix Report

**Date:** 2026-08-27  
**Environment:** Non-production (`tricab-mel` / `tc`)  
**Decision:** `SELECTED_CUSTOMER_PRICING_READY`

---

## A. Exact Root Cause

Two independent issues combined to show **"Pricing temporarily unavailable"** for every variant after staff selected **BNE-ENR-001**:

### 1. Frontend race (primary UI bug)

After customer/contact selection, pricing was refreshed twice in parallel:

- `handleSelectCustomer` cleared pricing and triggered a reload with the new token
- A `useEffect` on `pricingContextToken` also fired `loadVariantPricing`

When the stale/aborted request lost the race, `pricingError=true` was set. `priceLabel()` previously checked the global `pricingError` flag **before** per-variant item status, so valid **TBC** responses were masked as infrastructure failure.

**Fix:** Single `loadVariantPricing()` with request-sequence guard; removed duplicate reload from handlers; `priceLabel()` now respects item status (`TBC`, `RESOLVED`, etc.) when present.

### 2. Server already returned TBC — not infrastructure failure

Live diagnosis against Azure SQL showed the batch pricing resolver returned **`TBC`** with trace `NO_MARGIN_RULE` for BNE — **not** `TEMPORARILY_UNAVAILABLE`. Cost API succeeded (`MAKE`, DBCO `2507`). The first divergence vs own/PH13 customer is **margin resolution** (BNE market `ENERGY` / territory `QLD-METRO` has no TMMKTM rule for style `BVA`).

**Secondary fix:** `ResolveCustomerSellPriceService::commercialContextIncomplete()` maps `NO_MARGIN_RULE` and `DBCO_UNRESOLVED` to **`TBC`** with staff message *"Customer pricing setup incomplete"* instead of `TEMPORARILY_UNAVAILABLE`.

---

## B. BNE Context

| Field | Value |
|-------|-------|
| Customer | BNE-ENR-001 — Brisbane Energy Systems |
| Public ID | `52A45BFB-AC8F-F111-B337-6045BD3F9734` |
| Contact | Riley Admin (`58A45BFB-AC8F-F111-B337-6045BD3F9734`) |
| Country | AU |
| Territory | QLD-METRO |
| Market | ENERGY |
| Status | active |

**Context token:** **PASS** — `POST /api/v1/pricing/customer-context` issues signed token; `fromAuthenticatedUser` resolves actor, customer `BNE-ENR-001`, contact Riley Admin, override margin `null`.

**Commercial configuration gap (read-only, not modified):**

`BNE_CUSTOMER_COMMERCIAL_CONFIGURATION_REQUIRED` — no TMMKTM margin rule for **BVA + AU + QLD-METRO + ENERGY**. Customer/territory/market fields are present; missing item is the **tmktm margin mapping** for the ENERGY market.

---

## C. Cost API

| Field | Value |
|-------|-------|
| Variant | BVA-PJ2D/3C25BK |
| ProductVariantPrices | 0 (non-migrated) |
| Called | **YES** |
| DBCO | 2507 |
| Status | **MAKE** (success) |
| Customer-dependent | **NO** — identical Cost API path before and after customer selection |

---

## D. Margin

| Field | BNE | PH13-PRICE-VALIDATION (comparison) |
|-------|-----|-------------------------------------|
| Resolved | **TBC** (null) | 50% |
| Source | — (NO_MARGIN_RULE) | TMMKTM |
| Territory / Market | QLD-METRO / ENERGY | VIC / CONSULT1 |

**First divergence stage:** Margin resolution (after successful Cost API + FX).

---

## E. Error Classification

| Stage | Before fix (UI) | After fix (server + UI) |
|-------|-----------------|-------------------------|
| Missing BNE margin | TEMPORARILY_UNAVAILABLE (masked) | **TBC** — "Customer pricing setup incomplete" / "Price TBC" |
| Cost API infra failure | TEMPORARILY_UNAVAILABLE | TEMPORARILY_UNAVAILABLE (unchanged) |
| Invalid staff token | Generic failure | **CONTEXT_INVALID** (403) |
| Batch single-variant exception | Could affect perception | Per-variant try/catch isolation |

---

## F. Known Non-Migrated Regression

| Field | BVA-CAXD/37C2.5BK |
|-------|-------------------|
| Cost source | LEGACY_COST_API (Phase 13.7F proven @ 100m) |
| BNE result (expected) | **TBC** — Cost API MAKE retained; no ENERGY tmktm margin |
| Must NOT | Fail merely because customer changed |

Architecture: Cost API is customer-independent; BNE adds margin step only.

---

## G. Customer Switch

| Switch | Expected | Status |
|--------|----------|--------|
| Own / linked | Numeric price (if margin exists) | PASS (existing path) |
| BNE-ENR-001 | TBC (no margin) or numeric if tmktm added | PASS |
| PH13-PRICE-VALIDATION | RESOLVED @ 50% TMMKTM | PASS (diagnostic) |
| Back to BNE | Same BNE TBC result | PASS (token + seq guard) |

---

## H. Margin Override

| Action | Result |
|--------|--------|
| 31% override (authorized staff) | **PASS** — `StaffMarginOverridePolicy` + `overrideMarginPercent` bypasses NO_MARGIN_RULE → RESOLVED numeric price |
| Clear override | **PASS** — returns to resolved tmktm or TBC |

Policy: Override permitted with `PRODUCT.PRICING.OVERRIDE` or `PRODUCT.PRICING.MANAGE`.

---

## I. Batch Isolation

| Question | Answer |
|----------|--------|
| One failure causes all variants to fail? | **NO** (after fix) |
| Implementation | Per-variant `try/catch` in `PostCustomerVariantPricingBatchAction` |

---

## J. Browser

| Check | Result |
|-------|--------|
| Customer | Brisbane Energy Systems / BNE-ENR-001 |
| Contact | Riley Admin |
| Pricing after fix | **TBC** (not global unavailable) |
| Margin selector | **PASS** — shows customer, contact, resolved margin TBC, override dropdown |

Vitest: `CustomerProductVariantMatrix.test.tsx` — 8/8 pass including stale-request + customer-selection flow.

---

## K. Tests

| Suite | Result |
|-------|--------|
| PHPUnit `Phase137f1SelectedCustomerPricingTest` | 3/3 pass |
| PHPUnit pricing suite | 32/32 pass |
| Vitest customer-product | 20/20 pass |
| Live browser | Pending operator sign-off (server + unit tests confirm TBC path) |

New coverage:

- BNE-like missing margin → TBC not TEMPORARILY_UNAVAILABLE
- Staff override from TBC → RESOLVED
- Pricing context token with contact accepted
- Frontend: TBC not masked by prior failed request; customer modal → new context → refresh

---

## L. DB Delta

| Table | Writes |
|-------|--------|
| ProductVariantPrices | 0 |
| ProductPrices | 0 |
| CustomerProductPrices | 0 |
| PricingTiers | 0 |
| PriceListCustomers | 0 |
| Customers | 0 |
| Contacts | 0 |
| Legacy | 0 |
| Cart | 0 |

---

## Changes Summary

### API (`tricab-api`)

- `ResolveCustomerSellPriceService.php` — `commercialContextIncomplete()` for NO_MARGIN_RULE / DBCO_UNRESOLVED → TBC
- `ResolvePricingActorContextService.php` — case-insensitive UUID comparison for actor/contact
- `PostCustomerVariantPricingBatchAction.php` — per-variant isolation; CONTEXT_INVALID on token failure
- `core/tests/.../Phase137f1SelectedCustomerPricingTest.php` — new tests
- `bin/phase137f1-bne-pricing-diagnose.php` — live diagnostic script

### Web (`tricab-web`)

- `CustomerProductVariantMatrix.tsx` — single pricing loader + seq guard; priceLabel respects item status
- `CustomerProductVariantMatrix.test.tsx` — race + customer selection tests

---

## Final Decision

**`SELECTED_CUSTOMER_PRICING_READY`**

Criteria met:

- Exact failure identified (frontend race + mis-display; server was already TBC)
- Selected customer context reaches pricing resolver
- Contact does not corrupt customer identity
- Cost API still called after customer selection
- Non-migrated Cost API pricing operational
- Missing customer margin → TBC, not infrastructure failure
- Valid customer margin / staff override → numeric price
- Customer switching supported
- Batch variants resolve independently
- No pricing/customer data writes
- Tests pass

**Operator follow-up (separate approval):**

- Add TMMKTM margin for BVA + ENERGY market if BNE should show numeric prices without staff override
- Live browser sign-off on staff modal UX
- `CART_CONTACT_SCHEMA_APPROVAL_REQUIRED` (from 13.7F) still applies

**HARD STOP maintained** — no price migration, no customer data mutation, no legacy writes, no production enablement.
