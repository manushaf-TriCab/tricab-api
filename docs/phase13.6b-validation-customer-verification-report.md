# Phase 13.6B — Validation Customer + RFQ Revalidation + Live Verification

**Decision:** `PHASE_13_6B_BLOCKED`  
**Remaining blocker:** `AUTHENTICATED_BROWSER_MSAL_SESSION_REQUIRED`  
**Date:** 2026-08-26

Everything below the browser-authenticated modal is proven. Guest browser modal is proven. Live HTTP API with DEV→VerifiedIdentity→`thilanh@tricab.com`→`PH13-PRICE-VALIDATION` returns **RESOLVED** with legacy MATCH and no leaks. Interactive Entra login was not available in the automation browser (`Login →` still shown), so UI/API visual numeric match could not be completed.

---

## A. Safety

| Field | Value |
|--------|--------|
| Environment | development |
| Server | tricab-mel |
| Database | tc |
| SUSER | thilanh@tricab.com (Azure AD) |
| Production | **NO** |

Legacy writes: **0**  
Pricing migration writes: **0**

---

## B. Phase 13.4 Baseline

| Check | Result |
|-------|--------|
| Expected | 20 |
| Found | 20 |
| Changed | **NO** |
| Checksum before | `f27805c79846ee6f16942154f5a46aaf912a193545403685afd4212068746485` |
| Checksum after | `f27805c79846ee6f16942154f5a46aaf912a193545403685afd4212068746485` |

---

## C. Validation Customer

| Field | Value |
|-------|--------|
| Created | **YES** (idempotent seed) |
| Customer code | `PH13-PRICE-VALIDATION` |
| Name | Phase 13 Pricing Validation |
| Country | AU |
| Territory | VIC |
| Market | CONSULT1 |
| Currency | AUD |
| User linked | **YES** → existing `thilanh@tricab.com` (no password invented) |
| Existing seeds altered | **NO** (MEL/SYD/BNE untouched) |

Manifest: `tmp/phase13.6b-validation-customer-manifest.json`  
Seed: `bin/seed-phase136b-pricing-validation-customer.php`

---

## D. Context Resolution

Authenticated user (`thilanh@tricab.com`)  
→ customer `PH13-PRICE-VALIDATION`  
→ AU / VIC / CONSULT1 / AUD  

**CUSTOMER_CONTEXT_RESOLVED**

(Also fixed public pricing routes to resolve `VerifiedIdentity` → `AuthenticatedUser` via `ResolveRequestAuthenticatedUser` — Entra Bearer previously left pricing as guest.)

---

## E. BVA Live Pricing (console-safe)

| Variant | API | Legacy parity |
|---------|-----|---------------|
| BVA-CAXD/10C2.5BK | RESOLVED | MATCH |
| BVA-CAXD/11C1.5BK | RESOLVED | MATCH |
| BVA-CAXD/12C1.5BK | RESOLVED | MATCH |
| BVA-CAXD/13C1.5BK | RESOLVED | MATCH |
| BVA-CAXD/13C1BK | RESOLVED | MATCH |

Protected: `tmp/phase13.6b-live-parity.json`, `tmp/phase13.6b-preflight-parity.json`

Live HTTP (DEV headers, same resolver path): 3/3 RESOLVED, leak=NO.

---

## F. End-to-End

| Pair | Result |
|------|--------|
| UI/API (authenticated numeric) | **NOT COMPLETED** (no MSAL session in automation browser) |
| API/Resolver | **MATCH** |
| Resolver/Legacy | **MATCH** 5/5 |
| Overall | **END_TO_END_MISMATCH** (blocked on authenticated UI only) |

Guest UI/API: Sign in to view pricing — **PASS**

---

## G. Modal

| Check | Result |
|-------|--------|
| Guest numeric price | N/A — shows **Sign in to view pricing** (browser PASS) |
| Authenticated numeric price | **PENDING** MSAL login as linked user |
| Quantity / Length / Total | Covered by RFQ prepare + Vitest; UI authenticated pending |
| Matching Accessories | Present |
| Add to RFQ | Calls server prepare; client `0.01` ignored |

---

## H. Availability

Legacy stock uses warehouse status codes (`2302` STOCKED etc.). Azure path had a name heuristic; Phase 13.6B changed default to **UNKNOWN** (MAKE only if name contains MAKE).

**AVAILABILITY_SOURCE_REVIEW**

---

## I. RFQ Revalidation

| Check | Result |
|-------|--------|
| Endpoint | `POST /api/v1/products/{slug}/rfq/lines/prepare` |
| Server re-resolution | **PASS** |
| Client price trusted | **NO** |
| Tampering (`unitPrice=0.01`) | **RFQ_PRICE_TAMPERING_BLOCKED** (ignored; server 19.886; qty2×100→3977.20) |
| TBC handling | Does not become $0.00 |
| Persistence | **RFQ_SNAPSHOT_SCHEMA_REVIEW** (prepare-only; no RFQ schema migration) |

---

## J. Security

| Check | Result |
|-------|--------|
| Base-cost exposure | **NO** |
| Customer impersonation body | **422** |
| Market/margin/dbco/priceList override | **422** |
| MEL-ELEC remains TBC | **PASS** |

---

## K. Regression

| Check | Result |
|-------|--------|
| Guest modal | Sign in to view pricing — **PASS** |
| MEL-ELEC-001 | TBC unchanged — **PASS** |
| Admin BVA Pricing meaning | Unchanged (base cost) — not re-navigated this run; pilot rows unchanged |

---

## L. Tests

| Suite | Result |
|-------|--------|
| PHPUnit sell-price + RFQ prepare | 13 OK |
| PHPUnit pricing batch action | 3 OK |
| Vitest modal | 5 OK |
| Live HTTP smoke | RESOLVED + RFQ tamper PASS |
| Live browser guest | PASS |
| Live browser authenticated | **BLOCKED** (needs Entra login) |

---

## M. Database Delta

| Table | Delta |
|-------|-------|
| ProductVariantPrices | **0** |
| ProductPrices | **0** |
| CustomerProductPrices | **0** |
| PricingTiers | **0** |
| PriceListCustomers | **0** |
| Customers | **+1** (`PH13-PRICE-VALIDATION`) |
| Contacts | **+1** |
| User/customer links | **+1** (to existing thilan user) |
| Legacy / tmktm / cyrte / WP | **0** |
| RFQ persisted records | **0** |

---

## N. Cleanup

Phase 13.6B-owned records: customer + contact + link (manifest listed).  
Cleanup available: documented in seed/`--cleanup-plan`.  
Cleanup executed: **NO**

---

## O. Remaining Reviews

1. **Authenticated browser MSAL session** — sign in as `thilanh@tricab.com`, open BVA size 10C 2.5mm², confirm `AUD $19.886 / m`
2. TAS 100/99
3. NZ / TCUS / TCASIA
4. Authoritative MAKE/STOCKED source
5. RFQ snapshot schema persistence
6. ERP future pricing source

---

## Final Decision

# PHASE_13_6B_BLOCKED

**Blocker:** `AUTHENTICATED_BROWSER_MSAL_SESSION_REQUIRED`

Not a pricing/resolver failure. Operator action: Sign in to the local app as the linked validation user (`thilanh@tricab.com`), re-open BVA modal for a pilot variant, confirm numeric sell price matches live API, then re-run the 13.6A checklist to reach `PHASE_13_6A_LIVE_VERIFIED`.

Do **not** run Phase 13.5 until that UI confirmation is done.

---

**STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL**
