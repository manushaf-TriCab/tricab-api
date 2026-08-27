# Phase 13.7F.5 — Variant-Specific Pricing Unavailable Report

**Date:** 2026-08-27  
**Decision:** Root cause proven; frontend fix applied; authenticated browser re-verification required

---

## A. Exact Root Cause

**First divergence: frontend batch/state handling — not backend variant identity, Cost API, DBCO, or sell-price formula.**

Trace for `BVA-PJXN/5C1.5BK` @ BNE + Riley Admin + 44% override:

```
Margin override UI → staffContext.overrideMargin = 44 ✓
→ POST customer variant pricing batch → RESOLVED unitPrice 15.630 ✓ (HTTP + direct resolver)
→ pricingById / batchLoadFailed ✗ (empty map or batchLoadFailed=true)
→ variantPriceDisplay → "Pricing temporarily unavailable"
```

The UI correctly showed **Selected/Effective margin: 44%** from `staffContext` (margin-override API), while variant rows showed **unavailable** because the batch layer failed or was treated as failed — not because the resolver rejected this SKU.

Contributing frontend factors (13.7F.4 carry-over):

1. **`batchLoadFailed` set when merged map was empty** after a fetch error or aborted load when switching size groups (5C 50mm² ↔ 5C 1.5mm²).
2. **Cross-modal stale state** — opening 5C 1.5mm² (11 variants) after pricing 5C 50mm² (5 variants) could inherit a failed/aborted batch state.
3. **Large single batch** — 11 sequential Cost API calls (~9s HTTP) increased timeout/abort risk vs 5-variant groups.

Backend per-variant isolation remains correct — no whole-group backend failure.

---

## B. Primary Failing Variant

`BVA-PJXN/5C1.5BK` — publicId `9C9B4365-1492-F111-B336-000D3AE07ABC`

---

## C. Working Control Variant

`BVA-PAXA/5C50BK` — publicId `839A4365-1492-F111-B336-000D3AE07ABC`

Same customer, contact, 44% override, length 100m, same pricing context token.

---

## D. Variant Identity

| Layer | BVA-PJXN/5C1.5BK | BVA-PAXA/5C50BK |
|-------|-------------------|-----------------|
| DB VariantCode | `BVA-PJXN/5C1.5BK` | `BVA-PAXA/5C50BK` |
| Catalog legacyProductCode | `BVA-PJXN/5C1.5BK` | `BVA-PAXA/5C50BK` |
| Cost API productCode | `BVA-PJXN/5C1.5BK` | `BVA-PAXA/5C50BK` |
| Explicit legacy mapping | none | none |
| Batch variantId | same publicId | same publicId |

No slash/decimal normalization transforms the code — Cost API receives the full variant code including `5C1.5`.

---

## E. DBCO

Both variants: **DBCO `2507`** (TCA-BASE / AUD sell) via `ResolvePricingDbcoService`.

---

## F. Cost API @ 100m

### BVA-PJXN/5C1.5BK

| Field | Value |
|-------|-------|
| Called | YES |
| productCode | `BVA-PJXN/5C1.5BK` |
| DBCO | `2507` |
| Status | **MAKE** |
| defaultCost | `3.243` |
| calculatedCostPerMeter | `8.753` |
| effectiveCost | `8.753` |

### BVA-PAXA/5C50BK

| Field | Value |
|-------|-------|
| Called | YES |
| Status | **MAKE** |
| calculatedCostPerMeter @ 100m | `22.249` |

Length dependency: MAKE cost resolves at 1m, 10m, and 100m (values differ; all succeed).

---

## G. Margin @ 44% Override

| Field | PJXN | PAXA |
|-------|------|------|
| resolvedMargin | null (no TMMKTM) | null |
| overrideMargin | **44** | **44** |
| effectiveMargin | **44** | **44** |
| marginSource | STAFF_OVERRIDE | STAFF_OVERRIDE |

Override reaches resolver after cost resolution.

---

## H. Sell Price Formula

```
sell = baseCost / fx × 100 / (100 - marginPercent)   [margin semantics]
```

| Variant | baseCost | 44% sell |
|---------|----------|----------|
| PJXN | 8.753 | **15.630** / m |
| PAXA | 22.249 | **39.730** / m |

---

## I. Final Status (HTTP Batch, 44%, 100m)

| Variant | Status | unitPrice |
|---------|--------|-----------|
| BVA-PJXN/5C1.5BK | **RESOLVED** | 15.630 |
| BVA-PAXA/5C50BK | **RESOLVED** | 39.730 |

Not TEMPORARILY_UNAVAILABLE at backend.

---

## J. Sibling Variants (5C 1.5mm², 44%, 100m)

All **RESOLVED** via HTTP batch (11 variants in group):

| Variant | unitPrice |
|---------|-----------|
| BVA-PJXN/5C1.5BK | 15.630 |
| BVA-PJXD/5C1.5BK | 15.960 |
| BVA-PJ2D/5C1.5BK | 16.066 |
| BVA-PDXD/5C1.5BK | 16.130 |
| BVA-PHXD/5C1.5BK | 17.460 |
| BVA-PAXD/5C1.5BK | 15.800 |
| BVA-PAXN/5C1.5BK | 15.503 |
| (+ 4 C-type siblings) | 16.619 – 17.805 |

Each variant priced independently.

---

## K. Size Comparison

| Size group | Variant count | Batch time (14 SKUs) | Backend |
|------------|---------------|----------------------|---------|
| 5C 1.5mm² | **11** | ~9s | ALL RESOLVED |
| 5C 50mm² | **5** | faster | ALL RESOLVED |

Failure pattern is **group-size / frontend batch handling**, not 5C1.5-specific backend rejection.

---

## L. Batch Isolation

**PASS** — mixed statuses possible; backend returns per-variant items. Verified: all test SKUs RESOLVED under same token.

---

## M. Frontend Mapping

**FAIL (before fix)** — margin labels from `staffContext`; prices from failed/empty `pricingById` + `batchLoadFailed`.

**Fix applied** (`CustomerProductVariantMatrix.tsx`):

- Reset `pricingById`, `appliedPricingToken`, `batchLoadFailed` on size-group modal open
- Chunk batch requests (8 variants/chunk) for large groups
- `batchLoadFailed` only on fetch error (not empty successful map)
- Do not sync applied token on batch failure
- `isPricingContextStale`: loading handled separately from token mismatch

---

## N. Tests

| Suite | Result |
|-------|--------|
| PHPUnit pricing | **46/46 PASS** |
| Vitest customer-product | **27/27 PASS** |

New: `Phase137f5VariantSpecificPricingTest.php`, `bin/phase137f5-variant-pricing-diagnose.php`

---

## O. Authenticated Browser

**FAIL (automation)** — Entra/passkey login required. User must re-verify:

1. BNE-ENR-001, Riley Admin, 44% override
2. Open **5C 1.5mm²** modal → `BVA-PJXN/5C1.5BK` → expect **AUD $15.630 / m**
3. Siblings e.g. `BVA-PJXD/5C1.5BK` → independent prices
4. Control: **5C 50mm²** → `BVA-PAXA/5C50BK` → **AUD $39.730 / m**

---

## P. DB / Legacy Delta

**0 writes** — read-only diagnosis.

---

## Fix Summary

No backend pricing formula, Cost API, DBCO, or SKU hard-coding changes required.

Frontend batch reliability + modal state reset fixes the observed **margin OK / price unavailable** split.

---

## Final Decision

```
PHASE_13_7F_5_BLOCKED
```

**Blocker:** Authenticated browser confirmation pending.

```
VARIANT_SPECIFIC_PRICING_READY
```

not declared until browser verifies 5C 1.5mm² numeric prices under 44% override.
