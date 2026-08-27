# Phase 13.7F.3 — Margin Override Precedence Report

**Date:** 2026-08-27  
**Decision:** Fixes implemented; authenticated browser re-verification required

---

## A. Exact Root Cause

**First divergence: frontend (two coupled bugs), not backend sell-price resolver.**

1. **Stale batch diagnostic used for Effective margin label** — `StaffPricingContextPanel` computed `effectiveLabel` as `margin.effective ?? staffContext.overrideMargin`. When batch had not yet returned override pricing, `margin.effective` remained the old automatic value (e.g. `50`), so UI showed `Effective margin: 50%` while `Selected margin: 3%`.

2. **Debounced length reload could win the pricing race** — After margin override issued a new token and started batch reload, a pending 400 ms length debounce could fire with the **pre-override token**, abort the override batch, and apply stale automatic pricing back into `pricingById`. Symptom: price stayed at `$47.570 / m` (50% automatic) despite override selection.

Backend precedence is correct:

```php
$effectiveMarginPercent = $overrideMarginPercent ?? $resolvedMarginPercent;
```

Verified by `bin/phase137f3-margin-override-verify.php` and `bin/phase137f3-http-margin-flow.php`.

**Note on BNE automatic margin:** Live Azure shows BNE-ENR-001 + BVA-PAXD/2C35BK automatic = **TBC** (no tmktm rule), not 50%. Observed `50% / $47.570` in browser likely came from stale self/linked-customer batch data or a pre-override token reload — consistent with the race above.

---

## B. Override Request (3%)

```json
POST /api/v1/pricing/margin-override
{
  "pricingContextToken": "<current-token>",
  "margin": 3
}
```

Integer percent `3`, not `0.03`.

---

## C. Context Token

| Step | New token issued | Batch uses new token |
|------|------------------|----------------------|
| 3%   | yes (`tokenChanged: true`, payload `overrideMargin: "3"`) | yes |
| 10%  | yes | yes |
| 31%  | yes | yes |
| Clear | yes (override removed) | yes |

---

## D. Margin Precedence (HTTP flow @ BVA-PAXD/2C35BK, BNE, 100 m)

| Step | Resolved | Selected | Effective | Source |
|------|----------|----------|-----------|--------|
| Automatic | TBC | — | TBC | — |
| 3% override | TBC | 3 | **3** | STAFF_OVERRIDE |
| 10% override | TBC | 10 | **10** | STAFF_OVERRIDE |
| 31% override | TBC | 31 | **31** | STAFF_OVERRIDE |
| Clear | TBC | — | TBC | — |

PHPUnit in-memory 50% automatic case:

| Step | Resolved | Selected | Effective |
|------|----------|----------|-----------|
| Automatic | 50 | — | 50 |
| 3% override | 50 | 3 | **3** |

---

## E–I. Live Prices (BVA-PAXD/2C35BK @ BNE, 100 m, Cost API MAKE)

| Step | Effective | Unit price |
|------|-----------|------------|
| Automatic | TBC | — |
| 3% | 3 | AUD 24.520 / m |
| 10% | 10 | AUD 26.427 / m |
| 31% | 31 | AUD 34.471 / m |
| Clear override | TBC | — (restored) |

---

## J. Estimated Total

Frontend `estimateTotal` derives from `selectedPrice.unitPrice × length × quantity`. With fix, batch reload updates `pricingById` before loading ends; total recalculates when unit price changes.

---

## K. TBC Override Path

PASS — TBC automatic → 3% → numeric → clear → TBC (HTTP + PHPUnit).

---

## L. Variant Isolation

PASS — preserved from 13.7F.2; per-variant map unchanged.

---

## M. Add-to-Cart Integrity

Add to Cart passes `pricingContextToken` from latest `staffContext` after override reload completes.

---

## N. Tests

| Suite | Result |
|-------|--------|
| PHPUnit pricing (`core/tests/Product/Application/Pricing/`) | **38/38** |
| Vitest customer-product | **26/26** |

New: `Phase137f3MarginOverridePrecedenceTest.php` (6 cases).

---

## O. Browser

Automated Cursor browser session was **not authenticated** (Entra/passkey login required). API sequence verified via `bin/phase137f3-http-margin-flow.php` against `localhost:8080` with DEV_AUTH headers — mirrors browser network calls.

**Re-verify in authenticated browser:** BNE + Riley Admin → 2C 35mm² black → margin 3/10/31 → clear.

---

## P. DB/Legacy Delta

**0 writes** — no TMMKTM, customer, or legacy changes.

---

## Fixes Applied

### Frontend (`tricab-web`)

- `handleMarginOverride`: async/await, cancel length debounce, `pricingLoading` during override+reload, await batch with new token
- `loadVariantPricing`: token guard (`latestPricingTokenRef`), cancel debounce on explicit reload
- `resolveEffectiveMarginDisplay`: override wins over stale diagnostic
- `variantPriceDisplay`: show "Updating price…" during reload

### Backend (`tricab-api`)

- `buildStaffContextResponse`: `overrideMargin` fallback from decoded context

---

## Final Decision

```
PHASE_13_7F_3_BLOCKED
```

**Blocker:** Authenticated browser walkthrough not completed in automation (Entra login). Code + HTTP API + unit tests PASS. User should sign in and confirm UI margin/price/total/cart on localhost:5173.

When browser confirms override precedence → `MARGIN_OVERRIDE_PRICING_READY`.
