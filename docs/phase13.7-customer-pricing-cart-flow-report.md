# Phase 13.7 — Legacy Customer Pricing Workflow + Cart Integration

**Decision:** `CUSTOMER_PRICING_CART_FLOW_BLOCKED`  
**Primary blocker:** `CART_SCHEMA_APPROVAL_REQUIRED`  
**Date:** 2026-08-26

---

## A. Legacy Workflow Audit

| Concern | Finding |
|---------|---------|
| Margin | Integer **−40…50** (`single-cable-1.php`); default from `tmktm.mgnr_lo`; manual change is **client-side only** (does not write tmktm) |
| Customer selection | Session `switch_customer` via `UserEmulator`; full page reload recalculates |
| Price recalculation | Server on reload; client on margin change via `(price×100)/(100−margin)` |
| Quantity/length | Reels × metres → total metres |
| Add | `ajax-addtocart.php` → `QuoteManagement` |
| Cart | DB `tqoth` / `tqoti` / `tqote` + session quote id |
| STOCKED/MAKE | `tpro.stkst` / warehouse `tstkwh_p.st`; **2302=STOCKED**, **2301=MAKE** |

---

## B. New Architecture (implemented)

| Piece | Status |
|-------|--------|
| `ResolvePricingActorContextService` | Done — SELF vs STAFF_SELECTED |
| HMAC `StaffPricingContextTokenCodec` | Done — no permanent profile mutation |
| Margin override policy −40…50 | Done — `StaffMarginOverridePolicy` |
| `ResolveCustomerSellPriceService` override path | Done |
| `POST /api/v1/pricing/customer-context` | Done |
| `POST /api/v1/pricing/margin-override` | Done |
| Pricing batch accepts context token | Done |
| `POST …/cart/items/prepare` | Done — server-authoritative snapshot |
| Durable cart tables | **Not created** — awaiting approval |
| VariantAvailabilityRepository (2302/2301) | **Not wired** — still `AVAILABILITY_SOURCE_REVIEW` |
| MAKE cost service | **Not implemented** — `MAKE_COST_FEATURE` review |

---

## C. Role Matrix

| Capability | Customer | Staff CUSTOMER.READ / PRICE_AS | Pricing OVERRIDE/MANAGE | Admin PRICING.VIEW |
|------------|----------|--------------------------------|-------------------------|--------------------|
| Select customer | No | Yes | Yes | — |
| View margin (diag) | No | No | Yes (with VIEW) | Yes |
| Override margin | No | No | Yes | — |
| View base cost | No | No | Yes (diag) | Yes |
| Add to cart prepare | Yes (own) | Yes (price-as) | Yes | Yes |

Permissions catalog notes: `CUSTOMER.PRICE_AS`, `PRODUCT.PRICING.OVERRIDE` added to development catalog; runtime also accepts `CUSTOMER.READ` / `PRODUCT.PRICING.MANAGE`.

---

## D. Exact Price Formula

```
convertedBaseCost = baseCost / fxRate
sellPrice = convertedBaseCost * 100 / (100 - effectiveMargin)
```

Default effectiveMargin = tmktm resolved.  
Staff override (authorized) replaces effectiveMargin only; **tmktm not written**.

Example base 24.122 @ 50% → **48.244**.  
Same base @ 31% override → **34.959**.

---

## E. Customer Selection

- Search: existing `GET /api/v1/admin/customers?search=`
- Select: `POST /api/v1/pricing/customer-context` → signed token
- Lifetime: token TTL 8h; clear returns to self context
- Does **not** change permanent user↔customer links

---

## F. Margin

- Legacy allowed: integers −40…50  
- Resolved: tmktm  
- Override: permission + range validation → `INVALID_MARGIN_OVERRIDE`  
- Clear override restores tmktm path

---

## G. Availability

| Source | Confidence |
|--------|------------|
| Azure name heuristic / UNKNOWN default | **AVAILABILITY_SOURCE_REVIEW** |
| Legacy 2301/2302 warehouse codes | Proven in legacy; **not yet wired** to Azure sell path |

---

## H. Cart

| Item | Status |
|------|--------|
| Existing UI shell | Reused (`cartStore`, Navbar badge) |
| Server prepare | Implemented |
| Durable storage | **CART_SCHEMA_APPROVAL_REQUIRED** — see `docs/phase13.7-cart-schema-proposal.md` |
| Endpoints planned | GET/POST/PATCH/DELETE `/api/v1/cart*` after approval |

---

## I. RFQ Boundary

Cart prepare ≠ RFQ.  
RFQ prepare remains; both ignore client unitPrice.  
Frozen RFQ snapshot still `RFQ_SNAPSHOT_SCHEMA_REVIEW`.

---

## J. UI

- Ordinary customer: no customer/margin selectors; qty/length; Add to Cart + Add to RFQ  
- Staff: compact “Pricing for” + search + optional margin override  
- Cart badge updated from prepare snapshot (non-authoritative staging until schema)

---

## K. Security

| Test | Result |
|------|--------|
| Client unitPrice 0.01 on cart prepare | Ignored (`clientPriceTrusted=false`) — unit |
| Body customerId / marginPercent on pricing | 422 |
| Token actor mismatch | Forbidden |
| Override without permission | Forbidden |
| Base cost in ordinary DTO | Not present |

---

## L. Tests

| Suite | Result |
|-------|--------|
| PHPUnit Phase137 + sell-price + RFQ prepare | **17 OK** |
| Vitest modal | Not re-run this pass (API/UI extended; prior suite still relevant) |
| Live browser E2E | Not claimed (13.6C auth browser still a separate concern) |

---

## M. Database Delta

| Table | Delta |
|-------|-------|
| ProductVariantPrices | **0** |
| ProductPrices | **0** |
| CustomerProductPrices | **0** |
| PricingTiers | **0** |
| Cart tables | **0** (not created) |
| Legacy writes | **0** |

MEL-ELEC / SYD-IND / BNE-ENR unchanged.

---

## N. Exact BVA Verification

| Variant | BVA-CAXD/37C1.5BK |
|---------|------------------|
| Default customer pricing (formula unit test @ 50%) | **MATCH** 48.244 |
| Staff margin override 31% (unit) | **MATCH** 34.959 |
| Add to cart prepare | **PASS** (server snapshot; persistence gated) |
| Cart durable server price | **FAIL / BLOCKED** — schema approval |

Live Azure PH13 check for 37C1.5BK not re-executed in this pass (CLI Azure timeout risk); pilot membership confirmed in Phase 13.4 artifacts (base 24.122).

---

## O. Remaining Reviews

1. **CART_SCHEMA_APPROVAL_REQUIRED** — apply proposed commerce tables  
2. Wire legacy stock 2301/2302 via `VariantAvailabilityRepository`  
3. MAKE cost (`ResolveMakeCostService`)  
4. TAS / NZ / TCUS / TCASIA  
5. Durable RFQ snapshot  
6. Seed `CUSTOMER.PRICE_AS` / `PRODUCT.PRICING.OVERRIDE` into non-prod roles  
7. Authenticated Cursor-browser E2E (13.6C)

---

## Final Decision

# CUSTOMER_PRICING_CART_FLOW_BLOCKED

**Blocker:** `CART_SCHEMA_APPROVAL_REQUIRED`

Staff price-as + margin override + server cart **prepare** are in place.  
Durable Add-to-Cart persistence must not proceed until the schema proposal is explicitly approved.

Do **not** run Phase 13.5.

**STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL**
