# Phase 13.6A — Live Authenticated Customer Pricing Verification

**Decision:** `PHASE_13_6A_BLOCKED`  
**Blocker:** `LIVE_CUSTOMER_CONTEXT_NOT_AVAILABLE`  
**Date:** 2026-08-26

Protected artifacts (do not commit):
- `tmp/phase13.6a-pilot-baseline.json`
- `tmp/phase13.6a-live-customer-pricing-parity.json`
- `tmp/phase13.6a-live-verification-summary.json`

Verification script: `bin/phase136a-live-customer-pricing-verify.php`

---

## A. Safety

| Field | Value |
|--------|--------|
| Environment | development |
| Server | tricab-mel |
| Database | tc |
| @@SERVERNAME / DB_NAME() | tricab-mel / tc |
| Production | **NO** |

| Write class | Result |
|-------------|--------|
| Legacy WordPress / tc MySQL | **0** (read-only path only) |
| Pricing migration writes | **0** |
| Customer data writes | **0** |

---

## B. Pilot Baseline

| Check | Result |
|-------|--------|
| BVA pilot prices expected | 20 |
| BVA pilot prices found | **20** |
| PriceList | TCA-BASE |
| Currency | AUD |
| Batch `p134-tca-BVA-20260826T010912` | **20/20** |
| Duplicates | **0** |
| Checksum | `f27805c79846ee6f16942154f5a46aaf912a193545403685afd4212068746485` |
| Changed during this phase | **NO** |

---

## C. Authenticated Customer

Azure non-prod has **exactly 3** customers (seed). None have a market with a proven BV `tmktm` rule:

| Customer | Country | Territory | Market | BVA resolve |
|----------|---------|-----------|--------|-------------|
| MEL-ELEC-001 | AU | VIC-METRO | ELECTRICAL | **TBC** |
| SYD-IND-001 | AU | NSW-METRO | INDUSTRIAL | **TBC** |
| BNE-ENR-001 | AU | QLD-METRO | ENERGY | **TBC** |

Proven RESOLVED markets for BVA (from Phase 13.6 / legacy `tmktm`): `VIC/CONSULT1`, `QLD/SW1`, etc.  
**No existing Azure customer uses those markets.**

User→customer link (sanitized): MEL-ELEC-001 has an active portal contact with a linked user. Other contacts exist without users.

**Sell currency (AU):** AUD

Per phase rules: **did not modify customer commercial data**.

→ **`LIVE_CUSTOMER_CONTEXT_NOT_AVAILABLE`** for authenticated **RESOLVED** live verification.

---

## D. BVA Variants Tested

Pilot variants used (TCA-BASE = YES), console-safe:

| Variant | Live MEL-ELEC status | Parity-only VIC/CONSULT1* |
|---------|----------------------|---------------------------|
| BVA-CAXD/10C2.5BK | TBC MATCH | RESOLVED MATCH |
| BVA-CAXD/11C1.5BK | TBC MATCH | RESOLVED MATCH |
| BVA-CAXD/12C1.5BK | TBC MATCH | RESOLVED MATCH |
| BVA-CAXD/13C1.5BK | TBC MATCH | RESOLVED MATCH |
| BVA-CAXD/13C1BK | TBC MATCH | RESOLVED MATCH |

\*Parity-only context is **not** a live Azure customer row — labeled in the protected artifact. Proves formula/resolver still MATCH when market exists; does **not** satisfy live authenticated RESOLVED requirement.

UOM from pricing path: **m** / `M`.

---

## E. API Verification

| Item | Result |
|------|--------|
| Endpoint | `POST /api/v1/products/{slug}/variants/pricing` |
| Live MEL context HTTP | 200 |
| Items | 5 |
| Statuses | all `TBC` |
| Currency context | AUD |
| UOM | m |
| Forbidden fields in body | `marginPercent`, `dbco`, `baseCost`, `customerId`, `priceListId` → all **422 rejected** |
| Internal data leaked | **NO** (false positive earlier: detector matched substring `unitPrice` vs `UnitPrice`; explicit check shows `baseCost` / `marginPercent` / `dbco` / `priceListId` / `tmktm` / `ProductVariantPrices` **absent**) |
| `$0.00` displayed | **NO** (`unitPrice` null) |

---

## F. End-to-End Verification

| Pair | Result |
|------|--------|
| UI/API | **NOT COMPLETED** (no live RESOLVED customer; local Vite was down during this run) |
| API/Resolver (TBC live) | **MATCH** |
| Resolver/Legacy (parity-only CONSULT1) | **MATCH** 5/5 |
| Overall | **END_TO_END_MISMATCH** (blocked on live RESOLVED customer + UI) |

---

## G. Modal

| Check | Result |
|-------|--------|
| Authenticated RESOLVED price | **FAIL** — blocked (`LIVE_CUSTOMER_CONTEXT_NOT_AVAILABLE`) |
| Quantity / Length / Estimated total | Covered by Vitest with mocked RESOLVED; **not** live-authenticated |
| Matching Accessories | Present in modal (Vitest) |
| Add to RFQ | **RFQ_PRICE_REVALIDATION_PENDING** — prepare payload omits client `unitPrice`; server revalidation not connected |

Guest modal LOGIN_REQUIRED was already confirmed in Phase 13.6 and Vitest; live browser re-check skipped (dev servers not listening).

---

## H. Other States

| State | Result |
|-------|--------|
| Guest | `LOGIN_REQUIRED` MATCH (resolver + Vitest) |
| TBC (real MEL-ELEC-001) | `TBC`, no `$0.00`, no base cost — MATCH |
| Customer without account | not separately exercised this run (seed customers are linked/present) |
| NZ | **NZ_LIVE_CUSTOMER_NOT_AVAILABLE** |
| US | **TCUS_LIVE_VERIFICATION_DEFERRED** |

---

## I. Security

| Check | Result |
|-------|--------|
| Customer override attempts | Rejected 422 |
| Base-cost exposure | Absent from customer DTO JSON |
| Diagnostic permission | Staff-only `includeDiagnostic` + `PRODUCT.PRICING.VIEW` (unchanged from 13.6) |
| Client PriceList mapping | Browser does not supply PriceList; server resolves TCA-BASE |

---

## J. Performance

| Mode | Expected | Status |
|------|----------|--------|
| Authenticated modal open | 1 batch pricing request | Design unchanged; live UI not re-measured (servers down) |
| Guest | 0 pricing requests | Confirmed in Vitest / guest path |

---

## K. Tests

| Suite | Result |
|-------|--------|
| PHPUnit `ResolveCustomerSellPriceServiceTest` | 10 OK |
| PHPUnit `CustomerVariantPricingBatchActionTest` | 3 OK |
| Vitest `CustomerProductVariantMatrix.test.tsx` | 5 OK |
| Live smoke script | Ran; blocker raised |

---

## L. Database Delta

| Table | Delta |
|-------|-------|
| ProductVariantPrices | **0** |
| ProductPrices | **0** |
| CustomerProductPrices | **0** |
| PricingTiers | **0** |
| PriceListCustomers | **0** |
| Customers | **0** |
| tmktm / cyrte / legacy / WordPress | **0** |

Expected: **ALL ZERO** — met.

---

## M. Remaining Issues

1. **`LIVE_CUSTOMER_CONTEXT_NOT_AVAILABLE`** — seed markets (`ELECTRICAL` / `INDUSTRIAL` / `ENERGY`) do not match BV `tmktm` markets (`CONSULT1`, `SW1`, …).
2. **`RFQ_PRICE_REVALIDATION_PENDING`** — RFQ must re-resolve server-side before persistence.
3. Availability heuristic (`VariantName LIKE '%MAKE%' ELSE STOCKED`) is approximate — review against real stock source.
4. NZ / US live customer verification deferred / not available.
5. Live browser modal re-check blocked by local Vite/API not listening in this session.

---

## Final Decision

# PHASE_13_6A_BLOCKED

Cannot claim live authenticated RESOLVED end-to-end until a **real** non-production customer exists with commercial context that resolves BVA margin (e.g. AU / VIC / CONSULT1), without inventing rules or silently rewriting seed markets.

---

## Next-phase recommendation

**B. Phase 13.6B — Live customer context enablement (small, approved)**

Do **not** run Phase 13.5 yet.

13.6B should, with **explicit operator approval** only:

1. Either create **one** non-prod test customer (or approved contact link) with territory/market that already has BV `tmktm` rules (prefer `VIC` + `CONSULT1`), **or** approve a documented mapping of seed `ELECTRICAL` → a real legacy market if that is business-correct (do not invent).
2. Re-run this 13.6A checklist through authenticated modal → UI/API/legacy `END_TO_END_MATCH`.
3. Optionally wire RFQ server revalidation stub.

Only after **PHASE_13_6A_LIVE_VERIFIED** should **A. Phase 13.5 TCA base-cost controlled batch** be considered.

---

**STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL**
