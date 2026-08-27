# Phase 13.7E — Staff Pricing Modal UX Finalisation

**Decision:** `STAFF_PRICING_MODAL_READY`  
**Date:** 2026-08-27  
**Scope:** Frontend/UI only (tricab-web)

---

## A. Root UX Issue

When **resolved tmktm margin** was **48%**, the **Margin override** `<select>` could also display **48%**, implying an explicit `STAFF_OVERRIDE` when none was active. Resolved margin and override state were not visually separated, and customer search was always visible.

---

## B. Files Changed

| File | Change |
|------|--------|
| `src/components/customer-product/StaffPricingContextPanel.tsx` | New pricing context panel |
| `src/components/customer-product/staffPricingUtils.ts` | Margin state helpers |
| `src/components/customer-product/CustomerProductVariantMatrix.tsx` | Layout reorder, panel integration, price refresh |
| `src/components/customer-product/customerProductView.css` | Staff panel + modal layout styles |
| `src/api/public/products.ts` | Parse batch `overrideMargin` from server context |
| `src/components/customer-product/staffPricingUtils.test.ts` | Unit tests |
| `src/components/customer-product/StaffPricingContextPanel.test.tsx` | Panel tests |
| `src/components/customer-product/CustomerProductVariantMatrix.test.tsx` | Staff regression + layout fix |

---

## C. Customer Selector

| Feature | Status |
|---------|--------|
| Search (on demand) | **PASS** — hidden until **Select customer** / **Change customer** |
| Selected state | **PASS** — name + code block |
| Clear / change | **PASS** — **Change customer**, **Use own / linked customer** |

---

## D. Margin States

| State | UI |
|-------|-----|
| Resolved | Separate read-only row from diagnostic (`resolvedMarginPercent`) |
| Override | Only when signed context `overrideMargin` is set |
| Effective | Shown only when override active |
| Clear | **Clear override** → `POST /api/v1/pricing/margin-override` with `clear: true` |
| No override dropdown | **Resolved (tmktm)** (`value=""`) — never pre-selects 48% |

---

## E. Permission Behaviour

| Permission | Control |
|------------|---------|
| `CUSTOMER.PRICE_AS` / `CUSTOMER.READ` | Pricing context panel |
| `PRODUCT.PRICING.OVERRIDE` / `PRODUCT.PRICING.MANAGE` | Override dropdown |
| `PRODUCT.PRICING.VIEW` | Resolved margin diagnostic display |
| Ordinary customer | No staff controls |

---

## F. SAMAUS Verification

Live browser re-check recommended after deploy. Expected:

| Check | Expected |
|-------|----------|
| Selected | Samsung Australia - Melbourne VIC / SAMAUS |
| Resolved margin | 48% (from server diagnostic) |
| No override UI | **Resolved (tmktm)** — not 48% in dropdown |
| 31% override | Override + Effective 31%, price refresh |
| Clear | Returns to Resolved (tmktm), tmktm price |

---

## G. Ordinary Customer Regression

**PASS** — Vitest confirms no Pricing context / margin controls without staff permissions.

---

## H. Tests

| Suite | Result |
|-------|--------|
| `staffPricingUtils.test.ts` | 6/6 PASS |
| `StaffPricingContextPanel.test.tsx` | 3/3 PASS |
| `CustomerProductVariantMatrix.test.tsx` | 7/7 PASS |

---

## I. Database Delta

**0** — no API, pricing, or customer data changes.

---

## Final Decision

`STAFF_PRICING_MODAL_READY`

**STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL**
