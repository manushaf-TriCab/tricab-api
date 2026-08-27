# Phase 13.7F.6 — CBXD Negative Margin / Batch Response Diagnosis

**Date:** 2026-08-27  
**Status:** `PHASE_13_7F_6_BLOCKED` (HTTP + unit tests PASS; authenticated browser pending)

---

## A. Exact root cause

**First divergent stage:** Frontend batch state — **not** backend pricing.

Captured HTTP batch for `BVA-CBXD/9C1BK` with BNE + Riley Admin + `-31%` override returns:

```text
status = RESOLVED
unitPrice = 9.382
effectiveMargin = -31
availability = MAKE
```

Browser showed **Pricing temporarily unavailable** because:

1. The modal-open pricing effect depended on `canViewStaffDiag`.
2. When staff permissions hydrated after login, the effect **re-ran**, its cleanup **aborted** the in-flight margin batch, and a failed/empty map left `batchLoadFailed=true` with no `pricingById` entry.
3. `resolveVariantPriceDisplay(undefined, false, true, …)` correctly rendered unavailable for an empty map + failed batch — but the failure was a **superseded/aborted request**, not a backend pricing failure.

This is **Case A** (API RESOLVED, UI unavailable).

---

## B. Variant

`BVA-CBXD/9C1BK` — 9C 1mm² Black

| Layer | Value |
|-------|-------|
| ProductVariant publicId | `299A4365-1492-F111-B336-000D3AE07ABC` |
| Variant.Code | `BVA-CBXD/9C1BK` |
| Product slug (live API) | `flexible-control-lszh-3d-test` |
| Batch request variantId | `299A4365-1492-F111-B336-000D3AE07ABC` |
| Batch response variantId | `299A4365-1492-F111-B336-000D3AE07ABC` |
| Cost API productCode | `BVA-CBXD/9C1BK` (unchanged) |
| pricingById key | `299A4365-1492-F111-B336-000D3AE07ABC` (uppercase GUID) |

No slash/case/spacing transform detected.

---

## C. Working control

`BVA-PAXA/5C50BK` — publicId `839A4365-1492-F111-B336-000D3AE07ABC`

Same customer/contact/token/margin/length session.

| | FAILING (CBXD) | WORKING (PAXA) |
|--|----------------|----------------|
| DBCO | TCA-BASE path / Cost API dbco resolved in session | Same |
| Cost API | MAKE, success @ 100m | MAKE, success @ 100m |
| cost @ -31% | 12.291 | 22.249 |
| override | -31 | -31 |
| effective | -31 | -31 |
| server unitPrice | **9.382** | **16.983** |
| browser (before fix) | unavailable | unavailable (same state bug) |

First divergence: **none on server** — both RESOLVED. Divergence was **frontend batch state only**.

---

## D. Variant identity

All layers match `BVA-CBXD/9C1BK` / GUID above. No alias required.

---

## E. DBCO

| | |
|--|--|
| Resolved? | YES |
| Value | Resolved via TCA-BASE price list for BNE AU context (Cost API called with legacy DBCO) |
| Source | `ResolvePricingDbcoService` + legacy Cost API |

---

## F. Cost API

| | CBXD/9C1BK @ 100m |
|--|---------------------|
| Called? | YES |
| productCode | `BVA-CBXD/9C1BK` |
| status | MAKE |
| calculatedCostPerMeter | 12.291 |
| effectiveCost | 12.291 |
| currency path | AUD / FX IDENTITY=1 |

Not length-specific failure — RESOLVED at 100m (also probed 1m/10m in script infrastructure).

---

## G. Negative margin policy

| Question | Answer |
|----------|--------|
| Allowed minimum | **-40** (`StaffMarginOverridePolicy::MIN`) |
| Allowed maximum | **50** |
| Does API accept -31? | **YES** |
| Context token preserves -31? | **YES** (`overrideMargin: "-31"`) |
| Resolver receives -31? | **YES** (`MARGIN_EFFECTIVE:STAFF_OVERRIDE=-31`) |
| Calculator accepts -31? | **YES** (denominator 131 > 0) |

Negative margins are **intentionally valid** (legacy product page range −40…50).

---

## H. Override payload / token

```json
POST /api/v1/pricing/margin-override
{ "pricingContextToken": "<token>", "margin": -31 }
```

| | |
|--|--|
| HTTP status | 200 |
| response overrideMargin | `"-31"` (integer percent, not −0.31) |
| tokenChanged | true (new signed token issued) |
| batch uses new token | YES |

---

## I. Formula (@ -31%, baseCost 12.291, fx 1)

```text
sell = baseCost / fx × 100 / (100 - marginPercent)
     = 12.291 / 1 × 100 / (100 - (-31))
     = 12.291 × 100 / 131
     = 9.382442748091 → display 9.382
```

No guard rejects negative margin before calculation.

---

## J. Margin matrix (HTTP, length 100m, BNE, CBXD)

| margin | API status | effective | unitPrice | Expected browser |
|--------|------------|-----------|-----------|------------------|
| automatic | TBC | — | — | Customer pricing setup incomplete |
| -31 | RESOLVED | -31 | 9.382 | AUD $9.382 / m |
| -8 | RESOLVED | -8 | 11.380 | numeric |
| 3 | RESOLVED | 3 | 12.671 | numeric |
| 10 | RESOLVED | 10 | 13.656 | numeric |
| 31 | RESOLVED | 31 | 17.813 | numeric |
| 44 | RESOLVED | 44 | 21.948 | numeric |

Failure is **not** negative-only, not threshold-specific, not variant-specific on server.

---

## K. Automatic TBC

**PASS** (HTTP)

```text
status = TBC
displayMessage = Customer pricing setup incomplete
trace includes NO_MARGIN_RULE
```

Not TEMPORARILY_UNAVAILABLE.

---

## L. Batch response (exact -31% failing SKU)

```json
{
  "variantId": "299A4365-1492-F111-B336-000D3AE07ABC",
  "variantCode": "BVA-CBXD/9C1BK",
  "status": "RESOLVED",
  "availability": "MAKE",
  "unitPrice": "9.382",
  "currency": "AUD",
  "overrideMargin": "-31",
  "effectiveMargin": "-31",
  "marginSource": "STAFF_OVERRIDE",
  "baseCost": "12.291",
  "formulaPath": "base/fx * 100/(100-margin)"
}
```

---

## M. Frontend mapping

**FAIL → FIX APPLIED**

| Fix | Purpose |
|-----|---------|
| Remove `canViewStaffDiag` from modal-open effect deps | Stop permission hydration aborting margin batch |
| Skip modal cleanup abort when `staffBusyRef` | Protect handler-owned reload |
| Remove generic recovery retry effect | Avoid reload loops (per phase constraint) |
| Authoritative request tracking in `loadVariantPricing` | Superseded/aborted loads must not set `batchLoadFailed` |
| `loadVariantPricing` returns boolean | Handlers only mark failed when authoritative load fails |

---

## N. Abort / recovery behaviour

**FAIL → FIX APPLIED**

- `AbortError` / superseded seq → ignored, no `batchLoadFailed`
- Genuine network/HTTP failure → `batchLoadFailed=true` only for authoritative request

---

## O. Single-variant modal

**PASS** (Vitest) — single-variant batch with `-31%` RESOLVED displays `AUD $9.382 / m`, not unavailable.

---

## P. Tests

| Suite | Result |
|-------|--------|
| PHPUnit pricing (`core/tests/Product/Application/Pricing/`) | **57/57 PASS** (includes new `Phase137f6CbxdNegativeMarginTest.php`, 11 tests) |
| Vitest customer-product | **32/32 PASS** |

---

## Q. Authenticated browser

**FAIL / BLOCKED** — not re-verified in authenticated browser this session.

---

## R. DB / legacy delta

**None** — read-only diagnosis + frontend state fix only.

---

## Diagnostic script

```bash
php bin/phase137f6-cbxd-negative-margin-diagnose.php [productSlug] [length]
# default: flexible-control-lszh-3d-test 100
```

---

## Fixes delivered

### Backend (`tricab-api`)
- `bin/phase137f6-cbxd-negative-margin-diagnose.php` — HTTP margin matrix + side-by-side CBXD vs PAXA
- `core/tests/Product/Application/Pricing/Phase137f6CbxdNegativeMarginTest.php`

### Frontend (`tricab-web`)
- `CustomerProductVariantMatrix.tsx` — targeted batch state fix (no generic retry loop)
- Tests for `-31%` RESOLVED path and stale `batchLoadFailed` display

---

## Final decision

```text
PHASE_13_7F_6_BLOCKED
```

Not declaring `CBXD_NEGATIVE_MARGIN_PRICING_READY` until authenticated browser confirms:

1. BNE + Riley Admin + `BVA-CBXD/9C1BK` + `-31%` → **AUD $9.382 / m**
2. Automatic (no override) → **Price TBC**
3. No spurious **Pricing temporarily unavailable** after margin change

**Refresh the web app and re-test the exact flow above.**
