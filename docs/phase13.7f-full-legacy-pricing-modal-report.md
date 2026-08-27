# Phase 13.7F — Full Legacy Pricing Modal Report

**Date:** 2026-08-27  
**Decision:** `FULL_LEGACY_PRICING_MODAL_BLOCKED` (pending operator live sign-off)

---

## A. Customer Search Root Cause

The inline customer search called `GET /api/v1/admin/customers`, which requires **`CUSTOMER.READ`**. Staff pricing users typically hold **`CUSTOMER.PRICE_AS`** only. Failed requests were swallowed in the UI (`catch(() => setCustomerHits([]))`), so typing produced **no visible list**.

Secondary UX factor: search required **≥2 characters**; a single character such as `b` intentionally returned nothing (now surfaced as “Type at least 2 characters” in the separate modal).

---

## B. Customer Search Fix

| Item | Change |
|------|--------|
| API | `GET /api/v1/pricing/customers?search=` gated on **`CUSTOMER.PRICE_AS`** |
| Frontend | `searchPricingCustomers()` — no admin route |
| UX | Separate **`CustomerSelectionModal`** (z-index 1200, not clipped by variant modal) |
| Debounce | 300ms |
| Min chars | 2, with explicit hint |

---

## C. Contact Selection

| Item | Change |
|------|--------|
| API | `GET /api/v1/pricing/customers/{id}/contacts` (PRICE_AS) |
| Context | `StaffPricingContextTokenCodec` stores optional `contactPublicId` |
| POST | `/api/v1/pricing/customer-context` accepts `contactId` |
| UI | Modal step 2 — radio contact list, **Use this customer** |
| Display | Variant modal shows customer + contact compact block |

**Cart note:** `CART_CONTACT_SCHEMA_APPROVAL_REQUIRED` — durable cart schema has no `SelectedContactId`; contact is retained in the signed pricing context token for the workflow session.

---

## D. Margin Selector

| Item | Status |
|------|--------|
| Resolved margin row | Visible for all `CUSTOMER.PRICE_AS` staff |
| Dropdown label | **Margin** (default **Resolved (tmktm)**) |
| Override labels | Selected margin / Effective margin when explicit override |
| Permission | Dropdown requires `PRODUCT.PRICING.OVERRIDE` or `PRODUCT.PRICING.MANAGE` |
| Clear override | Restores tmktm effective margin |

---

## E. Cost API Architecture Fix

**BEFORE:** `ProductVariantPrices` required → else `PRICE_NOT_AVAILABLE` → Cost API never called.

**AFTER:** `VariantCommercialCatalogRepository` loads `ProductVariants` + optional LEFT JOIN prices → deterministic `legacyProductCode` → Cost API when `LEGACY_COST_API_ENABLED=true`.

**Azure base price required when Cost API enabled:** **NO**

**Azure path when Cost API disabled:** unchanged (requires migrated base cost).

Key files:
- `VariantCommercialCatalogRepository` / `AzureSqlVariantCommercialCatalogRepository`
- `ResolveCustomerSellPriceService` refactored to `buildCommercialBase()`

---

## F. Non-Migrated Test

Using `PH13-PRICE-VALIDATION` customer (SAMAUS not present in current dev DB):

| Variant | Cost API | Sell price | Result |
|---------|----------|------------|--------|
| `BVA-CAXD/37C2.5BK` | LEGACY_COST_API / MAKE | AUD 94.568 @ 100m | **PASS** |
| `BVA-CAXD/3C2.5BK` | LEGACY_COST_API / MAKE | resolved numeric | **PASS** |
| `BVA-CAXD/4C1.5BK` | LEGACY_COST_API / MAKE | resolved numeric | **PASS** |

`NON_MIGRATED_COST_API_PRICING_PASS` — API verification via `bin/phase137f-non-migrated-verify.php` (with valid margin customer).

---

## G. Migrated BVA Test

`BVA-CAXD/37C1.5BK` with Cost API enabled: customer cost source **LEGACY_COST_API** (not Azure base for sell price). Admin migrated base cost row unchanged.

---

## H. Modal UX

| Item | Status |
|------|--------|
| Separate customer modal | **PASS** (implementation) |
| Contact step | **PASS** (implementation) |
| Margin selector | **PASS** (implementation) |
| Raw JSON removed | **PASS** — `PriceResolutionPanel` collapsed formatted panel |

Live browser sign-off on staff workstation: **PENDING**.

---

## I. Security

| Check | Result |
|-------|--------|
| Base cost customer leak | **NO** (staff panel only; base cost only with VIEW) |
| Customer impersonation | **BLOCKED** (signed token + actor match) |
| Margin tamper | **BLOCKED** (server override endpoint) |
| Client price trusted | **NO** |

---

## J. Tests

| Suite | Result |
|-------|--------|
| PHPUnit pricing (Phase137b + resolver) | **38/38 PASS** |
| Vitest customer-product | **19/19 PASS** |
| Live browser | **NOT RUN** this session |

---

## K. DB Delta

| Table | Delta |
|-------|-------|
| ProductVariantPrices | **0** |
| ProductPrices | **0** |
| CustomerProductPrices | **0** |
| Customers | **0** |

---

## Final Decision

`FULL_LEGACY_PRICING_MODAL_BLOCKED`

**Reason:** Implementation and automated tests complete; operator live browser verification (SAMAUS search/contacts in target environment, modal walkthrough) not executed in this session. Cart contact persistence requires schema approval before durable cart owns `SelectedContactId`.

**STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL**
