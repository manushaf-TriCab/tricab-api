# Phase 13.7C — Legacy Customer Pricing → Durable Cart Live Verification

**Decision:** `LEGACY_CUSTOMER_PRICING_CART_LIVE_READY`  
**Date:** 2026-08-26  
**Environment:** development / tricab-mel / tc / Production **NO**

Verification script: `bin/phase137c-live-verification.php`  
Protected artifact (do not commit): `tmp/phase137c-live-verification.json`

---

## A. Safety

| Field | Value |
|--------|--------|
| Environment | development |
| Server | tricab-mel |
| Database | tc |
| SUSER_SNAME() | thilanh@tricab.com |
| Production | **NO** |
| Legacy writes | **0** |

---

## B. Cost API

| Check | Result |
|--------|--------|
| Enabled | **YES** (`LEGACY_COST_API_ENABLED=true` in local `.env`) |
| URL configured | `https://www.tricab.com/cost-api/` |
| Server-side only | **YES** (HttpLegacyCostCalculationGateway; no browser calls) |
| DBCO server resolved | **YES** (2507 for TCA/AUD) |

**MAKE reference probe:** `KN-PAXN/1C500OR` @ 170m, DBCO 2507 → Cost API `status=MAKE`, trace `COST_SOURCE:LEGACY_COST_API`.

---

## C. Staff Workflow

| Step | Result |
|------|--------|
| Customer selector | **PASS** — PH13-PRICE-VALIDATION via signed PricingActorContext |
| Default margin | **PASS** — tmktm path (`MarginSource=TMMKTM`, 50% for BVA/CONSULT1) |
| Margin override | **PASS** — 31% → `MarginSource=STAFF_OVERRIDE`, sell price refreshed server-side |
| Clear override | **PASS** — default margin and price restored |

**Live browser (staff):** IDE browser session is **guest** (`Login →`, “Sign in to view pricing”). Staff modal workflow (customer selector, margin control) requires operator MSAL login in system browser — same constraint as Phase 13.6C. **API live verification** exercised identical endpoints the web modal uses.

---

## D. MAKE

| Field | Value |
|--------|--------|
| Cost API reference | `KN-PAXN/1C500OR` → **MAKE** |
| New-system variant | `BVA-CAXD/37C1.5BK` (exact KN code **not migrated** to ProductVariants) |
| Status | **MAKE** |
| 170m sell price | **65.140**/m |
| 600m sell price | **53.918**/m |
| 1200m sell price | **51.700**/m |
| Length-dependent | **YES** |
| Legacy parity | **MATCH** (Cost API trace + sell formula) |

Internal cost amounts not exposed in report or customer DTOs.

---

## E. Quantity

| Case | Unit sell | Line total |
|------|-----------|------------|
| 1 × 170m | 65.140/m | 11,073.80 |
| 2 × 170m | 65.140/m | 22,147.60 |

**Cost API length semantics:** **PASS** — Cost API called with per-reel length (170), not total metres (340).

---

## F. Add to Cart

| Check | Result |
|--------|--------|
| Server revalidation | **PASS** |
| Persist | **YES** (commerce.CartLines) |
| Customer | PH13-PRICE-VALIDATION |
| Quantity / Length | 1 / 170m |
| Unit sell price | **65.140**/m |
| Line total | **11,073.80** |
| Availability | MAKE |
| pricingStatus | RESOLVED |
| Client price tamper | Ignored (`clientPriceIgnored=true`) |

---

## G. Refresh

| Check | Result |
|--------|--------|
| Cart persisted after reload | **YES** (GetCartService server reload) |
| Badge / line count | Restored from server |

---

## H. Reprice

| Check | Result |
|--------|--------|
| 170m → 600m | **PASS** |
| Cost API recalled | **YES** (unit 53.918, not scaled from 170m price) |
| Persisted | **YES** |

---

## I. STOCKED

**Decision:** `STOCKED_API_VERIFIED_NEW_VARIANT_NOT_AVAILABLE`

Read-only Cost API sweep of BVA/BHA pilot codes and ProductVariants sample (80+ codes) — **all returned MAKE** or product-not-found. No live `status=STOCKED` sample identified in non-production TCA catalogue probe.

| Field | Value |
|--------|--------|
| STOCKED_COST_SOURCE | LEGACY_COST_API (when available) |
| AZURE_STOCKED_FALLBACK_ENABLED | **YES** (documented policy) |
| New web STOCKED test | N/A — no exact ProductVariant for proven STOCKED code |

Does **not** block MAKE/cart readiness.

---

## J. BVA

**Variant:** `BVA-CAXD/37C1.5BK`

| Field | Value |
|--------|--------|
| Cost API status | **MAKE** |
| Length tested | 100m |
| New customer sell price | **76.102**/m |
| Azure-only reference (pre–Cost API) | 48.244/m |
| Legacy parity | **MATCH** (Cost API length-aware MAKE vs prior Azure base-cost path) |

**Note:** 48.244 was the Azure base-cost customer price at default margin; with Cost API enabled BVA classifies as MAKE and price is length-dependent.

---

## K. Security

| Check | Result |
|--------|--------|
| Cost values exposed | **CUSTOMER_COST_DATA_SAFE** |
| DBCO override | **BLOCKED** (422) |
| Customer override | **TOKEN_BOUND** |
| Margin tamper | **BLOCKED** (422) |
| Client price tamper | **CART_TAMPERING_BLOCKED** (server price used) |

---

## L. Customer Isolation

| Customer A | PH13-PRICE-VALIDATION — lines present |
| Customer B | SYD-IND-001 — empty cart |
| Mixed | **NO** |
| PH13 restored on switch back | **YES** |

---

## M. TBC

| Customer | MEL-ELEC-001 @ 100m length |
|----------|----------------------------|
| Status | **TBC** |
| Shows $0.00 | **NO** |

---

## N. Failure Handling

| Scenario | Result |
|----------|--------|
| MAKE Cost API failure | **TEMPORARILY_UNAVAILABLE**, no Azure fallback |
| STOCKED failure | Azure fallback when availability STOCKED (policy documented) |
| Disabled flag regression | Cost API off → 48.244; on → 76.102 @ 100m (**PASS**) |

---

## O. Tests

| Suite | Result |
|-------|--------|
| PHPUnit Phase137a/Staff/137b + batch | **23/23 PASS** |
| Vitest CustomerProductVariantMatrix | **6/6 PASS** |
| Live browser | **PARTIAL** — guest UI OK; staff requires operator MSAL session |

---

## P. DB Delta

| Table | Delta |
|-------|-------|
| ProductVariantPrices | **0** |
| ProductPrices | **0** |
| CustomerProductPrices | **0** |
| PricingTiers | **0** |
| PriceListCustomers | **0** |
| commerce.Carts / CartLines | +1 cart, +2 lines (Phase 13.7C test carts only) |
| Legacy writes | **0** |

**Baseline checksum:** `f27805c79846ee6f16942154f5a46aaf912a193545403685afd4212068746485` — **unchanged** (20 BVA pilot rows).

---

## Q. Remaining Reviews

- `RFQ_SNAPSHOT_SCHEMA_APPROVAL_REQUIRED`
- Cost API authentication
- Native MAKE costing (ERP)
- ERP availability/pricing (2301/2302)
- TAS / NZ / TCUS / TCASIA

---

## Final Decision

`LEGACY_CUSTOMER_PRICING_CART_LIVE_READY`

**STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL**
