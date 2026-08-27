# Phase 13.7F.4 — Effective Margin Sell Price Report

**Date:** 2026-08-27  
**Decision:** Frontend fix implemented; authenticated browser re-verification required

---

## Diagnostic Output (Section 20)

```
VARIANT: BVA-PAXA/5C50BK
LENGTH: 100m
```

### PH13-PRICE-VALIDATION (automatic margin = 50%, HTTP verified)

| Step | cost | resolved | override | effective | server unitPrice | browser unitPrice |
|------|------|----------|----------|-----------|------------------|-------------------|
| AUTOMATIC | 22.249 | 50 | null | 50 | **44.498** | pending re-verify |
| 13% | 22.249 | 50 | 13 | 13 | **25.573** | pending re-verify |
| 20% | 22.249 | 50 | 20 | 20 | **27.811** | pending re-verify |
| 31% | 22.249 | 50 | 31 | 31 | **32.244** | pending re-verify |
| CLEAR | 22.249 | 50 | null | 50 | **44.498** | pending re-verify |

### BNE-ENR-001 + Riley Admin (HTTP verified)

| Step | cost | resolved | override | effective | server unitPrice | browser unitPrice |
|------|------|----------|----------|-----------|------------------|-------------------|
| AUTOMATIC | — | null | null | null | **TBC (null)** | was **44.498** (stale) |
| 13% | 22.249 | null | 13 | 13 | **25.573** | was **44.498** (stale) |
| 20% | 22.249 | null | 20 | 20 | **27.811** | pending re-verify |
| 31% | 22.249 | null | 31 | 31 | **32.244** | pending re-verify |
| CLEAR | — | null | null | null | **TBC (null)** | pending re-verify |

**First divergence:** After margin override, `staffContext` updated immediately (effective margin 13%) but `pricingById` still held the prior batch result (`unitPrice: 44.498` from a 50%-margin context). UI showed new margin labels + old numeric price.

---

## A. Exact Root Cause

**Frontend stale `pricingById` / token sync — not backend sell-price calculation.**

Trace for BNE + 13% override:

```
Margin dropdown → POST /api/v1/pricing/margin-override → new pricingContextToken ✓
→ POST customer variant pricing batch → ResolveCustomerSellPriceService ✓
→ effectiveMargin=13, unitPrice=25.573 in response ✓
→ pricingById merge ✗ (old 44.498 retained until reload completed; race could restore pre-override batch)
→ selected variant price display ✗ (derived from stale map)
→ estimated total ✗ (44.498 × 100 = 4,449.80)
```

The user's observed `Resolved margin: 50%` + `Effective margin: 13%` + `$44.498` is a **mixed-state** symptom: margin labels from fresh `staffContext`, resolved margin and price from stale batch keyed to a prior token (likely self/PH13 customer with 50% TMMKTM rule). BNE automatic for this variant is **TBC**, not 50%.

---

## B. Cost Source — BVA-PAXA/5C50BK

- **Legacy Cost API** (MAKE path) when length provided
- `baseCost: 22.249` (AUD, per metre at 100 m)
- Margin from **TMMKTM** when automatic (PH13); **STAFF_OVERRIDE** when manual margin set
- BNE has no automatic TMMKTM rule → automatic status **TBC**; override supplies margin and resolves price

---

## C. Pricing Formula

TriCab uses **margin semantics**, not markup:

```
sell = baseCost / fxRate × 100 / (100 - marginPercent)
formulaPath: 'base/fx * 100/(100-margin)'
```

Verification @ cost 22.249, fx=1:

| margin | sell |
|--------|------|
| 50% | 22.249 × 100/50 = **44.498** |
| 13% | 22.249 × 100/87 = **25.573** |
| 20% | 22.249 × 100/80 = **27.811** |
| 31% | 22.249 × 100/69 = **32.244** |

Backend applies `$effectiveMarginPercent = $overrideMarginPercent ?? $resolvedMarginPercent` before formula (see `ResolveCustomerSellPriceService.php` line 285).

---

## D–H. Test Matrix Results (server HTTP)

### D. Automatic — PH13

- Margin: resolved **50%**, effective **50%**
- Server unit price: **AUD 44.498 / m**
- Total @ 100 m: **AUD 4,449.80**

### E. 13% — both customers

- Effective margin: **13%**
- Server unit price: **AUD 25.573 / m**
- Total @ 100 m: **AUD 2,557.30**

### F. 20%

- Effective margin: **20%**
- Server unit price: **AUD 27.811 / m**
- Total @ 100 m: **AUD 2,781.10**

### G. 31%

- Effective margin: **31%**
- Server unit price: **AUD 32.244 / m**
- Total @ 100 m: **AUD 3,224.40**

### H. Clear override

- PH13: effective **50%**, unit price **44.498** restored
- BNE: **TBC**, unit price **null** restored

---

## I. Estimated Total

Frontend now derives total from `authoritativeSelectedPrice.unitPrice × length × quantity`. When context is stale (`staffContext.pricingContextToken !== appliedPricingToken`), price and total show **"Updating price…"** instead of stale values.

---

## J. Sibling Variants

Backend resolves independently per variant ID in batch. Same margin override token applies to all variants in the modal batch; each variant gets its own cost/margin/price. Sibling codes share cost path (Legacy Cost API MAKE) but may differ in base cost.

| Variant | Expected behaviour |
|---------|-------------------|
| BVA-PAXA/5C50BK | Independent price @ 13% = 25.573 (verified HTTP) |
| BVA-PAXD/5C50BK | Independent (own cost in batch) |
| BVA-PAXN/5C50BK | Independent |
| BVA-PJXD/5C50BK | Independent |

Browser sibling verification: **pending re-verify**.

---

## K. TBC Regression

**PASS** (HTTP + PHPUnit)

- BNE automatic → TBC
- 13% override → RESOLVED numeric (25.573)
- Clear → TBC restored

---

## L. Automatic-Margin Regression (50%)

**PASS** (HTTP + PHPUnit on PH13-PRICE-VALIDATION)

- Automatic 50% → 44.498
- Override 13% → 25.573 (different)
- Clear → 44.498 restored

---

## M. Cart Pricing Integrity

Add to Cart disabled while `pricingStale || pricingLoading`. Cart payload uses latest `staffContext.pricingContextToken` after batch completes. **PASS** by code review; browser verify pending.

---

## N. Tests

| Suite | Result |
|-------|--------|
| PHPUnit pricing (`core/tests/Product/Application/Pricing/`) | **42/42 PASS** |
| Vitest customer-product (`src/components/customer-product/`) | **27/27 PASS** |

New/updated:

- `Phase137f4EffectiveMarginSellPriceTest.php` (4 cases: 50% vs 13%, progression, clear, formula)
- `CustomerProductVariantMatrix.test.tsx` — margin override reload by token
- `staffPricingUtils.test.ts` — `isPricingContextStale`, `resolveEffectiveMarginDisplay`

---

## O. Authenticated Browser

**FAIL (automation)** — Cursor browser session not signed in (Entra/passkey required).

User-reported pre-fix evidence (authenticated):

```
Resolved margin: 50%  ← stale batch diagnostic
Selected margin: 13%  ← fresh staffContext
Effective margin: 13% ← fixed in 13.7F.3
Unit price: AUD $44.498 / m  ← stale pricingById (50% context)
Estimated total: AUD $4,449.80
```

**Re-verify steps for user:**

1. Hard-refresh `localhost:5173` (ensure latest `CustomerProductVariantMatrix.tsx` loaded)
2. BNE-ENR-001, Riley Admin, open **5C 50mm²** modal, select **BVA-PAXA/5C50BK**, length **100 m**
3. Automatic should show **TBC** (not 44.498)
4. Set margin **13%** → unit price **25.573**, total **2,557.30**
5. Repeat 20%, 31%, clear

---

## P. DB / Legacy Delta

**0 writes** — read-only verification scripts only.

---

## Fix Summary (tricab-web)

`CustomerProductVariantMatrix.tsx`:

- `appliedPricingToken` tracks last successful batch token
- `isPricingContextStale()` hides prices when token ≠ applied
- Clear `pricingById` on customer/margin change before reload
- `authoritativeSelectedPrice` — derive display only when not stale
- Cancel length debounce on margin override; await batch reload
- Add to Cart disabled during stale/loading state
- Token safety-net effect no longer double-fires on modal open

---

## Final Decision

```
PHASE_13_7F_4_BLOCKED
```

**Blocker:** Authenticated browser confirmation pending. Automated browser cannot complete Entra/passkey login. Server-side pricing path verified correct; frontend fix in place; all automated tests pass.

**Not ready for:**

```
EFFECTIVE_MARGIN_SELL_PRICE_READY
```

until user confirms browser prices change per test matrix above.
