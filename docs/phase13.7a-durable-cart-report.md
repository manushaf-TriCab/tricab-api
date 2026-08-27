# Phase 13.7A — Durable Cart Schema + Persistence Report

**Decision: `CUSTOMER_PRICING_CART_FLOW_READY`**

STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL before production traffic or Phase 13.5.

---

## A. Safety

| Check | Result |
|-------|--------|
| APP_ENV | development |
| Server | tricab-mel |
| Database | tc |
| Production | NO |
| Legacy writes | **0** |

## B. Schema

| Item | Status |
|------|--------|
| Migration | `database/scripts/development/create-commerce-cart-schema.sql` |
| Apply | `bin/ensure-commerce-cart-schema.php` → **CART_SCHEMA_APPLIED** |
| Rollback | `database/scripts/development/rollback-commerce-cart-schema.sql` |
| Tables | `commerce.Carts`, `commerce.CartLines` |
| Length | **DECIMAL(18,3)** |
| RowVersion | YES (both tables) |
| Soft delete | YES |
| Unique OPEN cart | owner + effective customer + pricing mode + currency |
| No baseCost / PriceList / tmktm columns | YES |

## C. Cart Domain

- Entities: `Cart`, `CartLine`
- Repository: `CartRepository` → `AzureSqlCartRepository` / `InMemoryCartRepository`
- Services: Get / Add / Update / Remove / Reprice / SwitchCartCustomer
- Fingerprint + `CartLineMath` (bcmul)
- Availability stub: `UnknownVariantAvailabilityRepository` (**AVAILABILITY_SOURCE_REVIEW**)

## D. APIs

| Method | Path |
|--------|------|
| GET | `/api/v1/cart` |
| POST | `/api/v1/cart/items` |
| PATCH | `/api/v1/cart/items/{lineId}` |
| DELETE | `/api/v1/cart/items/{lineId}` |
| POST | `/api/v1/cart/reprice` |

Forbidden body keys (422): `unitPrice`, `lineTotal`, `marginPercent`, `dbco`, `priceListId`, `baseCost`, `customerId`

## E. Pricing Authority

- Sell price: `ResolveCustomerSellPriceService` only
- Customer: `PricingActorContextResolver` + staff HMAC token
- Margin override: authorized staff only → `MarginSource=STAFF_OVERRIDE`
- Client prices never trusted (`clientPriceTrusted=false`)

## F. Customer Isolation

- Separate OPEN cart per `(OwnerUserId, EffectiveCustomerId, PricingMode, CurrencyCode)`
- Staff customer switch → different active cart (no mixed lines)

## G. Cart Persistence

- Azure SQL durable rows
- Frontend: server GET drives badge; localStorage is non-authoritative cache only
- Live add persisted; counts after: carts=1, lines=1

## H. Exact BVA Test

| Field | Value |
|-------|-------|
| Variant | BVA-CAXD/37C1.5BK |
| Qty × Length | 1 × 100 |
| Add | **PASS** |
| Persist | **PASS** |
| Server unit price | **48.244** (MATCH vs 50% margin formula) |
| Line total | **4824.40** (MATCH) |
| Client `0.01` | ignored (`clientPriceIgnored=true`) |
| Effective customer | PH13-PRICE-VALIDATION |

## I. Margin Override (31%)

Unit tests: **PASS** (34.959 / 3495.90, `STAFF_OVERRIDE`)

## J. TBC

Unit tests: **PASS** — null unit/line totals; no $0.00

## K. Security

| Case | Result |
|------|--------|
| Client price tampering | ignored / 422 forbidden fields |
| Customer impersonation | blocked (token + actor) |
| Margin tampering | forbidden body / server override only |
| Token mismatch | 403 |
| Cart ownership | owner-scoped queries |

## L. Repricing

`POST /cart/reprice` — UNCHANGED / PRICE_CHANGED / TBC paths covered in unit tests

## M. Cart → RFQ

Boundary preserved (Request Quote CTA → server reprice).  
**RFQ_SNAPSHOT_SCHEMA_APPROVAL_REQUIRED** remains for frozen RFQ storage.

## N. Tests

| Suite | Result |
|-------|--------|
| PHPUnit Phase137a | 9 passed |
| PHPUnit Phase137Staff | 4 passed |
| Vitest cart drawer | 2 passed |
| Live Azure add | PASS |

## O. Database Delta

| Table | Delta |
|-------|-------|
| ProductVariantPrices | **0** (still 20) |
| ProductPrices | **0** |
| CustomerProductPrices | **0** |
| PricingTiers | **0** |
| PriceListCustomers | **0** |
| commerce.Carts | **1** (test) |
| commerce.CartLines | **1** (test) |
| Pilot checksum | `f27805c79846ee6f16942154f5a46aaf912a193545403685afd4212068746485` **MATCH** |
| Legacy | **0** |

## P. Remaining Reviews

- MAKE costing
- Authoritative stock (2301/2302) — not wired; stub UNKNOWN
- RFQ snapshot schema
- TAS / NZ / TCUS / TCASIA / ERP source

---

## Final Decision

```
CUSTOMER_PRICING_CART_FLOW_READY
```

STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL
