# Phase 13.6 — Customer Variant Sell Price Resolver + Modal Integration

**Environment:** `APP_ENV=development` · Server `tricab-mel` · Database `tc` · Production **NO**  
**Date:** 2026-08-26  
**Decision:** `CUSTOMER_VARIANT_PRICING_READY`

---

## A. Existing Modal Architecture

| Piece | Location |
|--------|----------|
| Component | `tricab-web/src/components/customer-product/CustomerProductVariantMatrix.tsx` |
| Datasheet wiring | `CustomerProductDatasheet.tsx` passes `productSlug={detail.slug \|\| detail.primarySlug}` |
| Route/page | Public industrial product route + admin customer-view preview |
| Size state | `activeSizeKey` groups matrix rows by size; modal opens on size click |
| Variant row | Modal list button per `ProductCustomerViewMatrixRow` |
| Variant DTO | Matrix row: `publicId`, `variantName`, `variantCode`, `sku`, `cells` |
| Pricing API | `POST /api/v1/products/{slug}/variants/pricing` |
| Client API | `fetchCustomerVariantPricingBatch` in `src/api/public/products.ts` |

Modal design (size title, technical summary, colour/finish list) **kept**. Commercial strip + selected-row expansion added.

---

## B. Customer Pricing Resolver

| Layer | Type |
|--------|------|
| `ResolveCustomerSellPriceService` | Core application service |
| `ResolveCustomerPricingContextService` / `CustomerPricingContextResolver` | Auth → customer → country/territory/market → sell currency |
| `AzureSqlVariantBaseCostRepository` | Azure `ProductVariantPrices` via TCA-BASE / TCUS-BASE only |
| `MysqlLegacyCustomerMarginRepository` | Read-only `tmktm` + `descript` (isolated) |
| `MysqlLegacyCurrencyFxRepository` | Read-only `cyrte` (AUD/USD/NZD) |
| `CableMarginPrecedenceResolver` | Legacy termkt→ter→mkt→ct + style/ncond precedence |
| `LegacyCustomerSellFormulaContract` | Proven catalogue formula |

**Paths**

1. **Customer context:** `AuthenticatedUser` → portal customer link → `CustomerDetails` (country, territory, market). Browser cannot supply margin/dbco/customerId.
2. **Base cost:** Active Azure `ProductVariantPrices` for approved base list. No raw `tc.price` fallback.
3. **Margin:** Style (BVA→BV), country, territory/market (aliases e.g. VIC-METRO→VIC, ELECTRICAL→ELE), cond=`p`, pt=1615, legacy LEFT-JOIN semantics.
4. **FX:** Identity when currencies match; AUD→NZD via cyrte; unsupported → `FX_NOT_AVAILABLE`.

---

## C. Formula

```
convertedBaseCost = baseCost / fxRate
sellPrice         = convertedBaseCost * 100 / (100 - marginPercent)
```

- Decimal (`bcmath`) throughout commercial calc.
- Display scale: **3** decimal places (`CustomerSellPriceStatus::DISPLAY_SCALE`).
- Anti-pattern `base * (1 + margin/100)` explicitly rejected in tests.
- TAS `cost * 100/99`: **not applied** (`LEGACY_SPECIAL_RULE_REVIEW`); opt-in flag only for staff tooling.

Unit proof: base=100, fx=1, margin=20 → sell=**125.000**.

---

## D. Legacy Parity

Protected artifact: `tmp/phase13.6-customer-pricing-parity.json` (contains base costs — local only).

| Scenario | Result |
|----------|--------|
| AU / VIC / CONSULT1 / AUD (margin 50) | **MATCH** (5 BVA pilot variants) |
| AU / QLD / SW1 / AUD (margin 38) | **MATCH** (different market vs above) |
| AU / VIC / ELE | **MATCH** → `TBC` (no BV tmktm rule for ELE) |
| AU / VIC-METRO / ELECTRICAL | **MATCH** → `TBC` (aliases to VIC/ELE; same) |
| NZ / VIC / CONSULT1 / NZD (TCA AUD + FX) | **MATCH** |
| Guest | **MATCH** → `LOGIN_REQUIRED` |
| US / TCUS | **Not required** — no useful BVA TCUS pilot base cost in this phase |

---

## E. Security

- Customer identity from server auth + linked customer record.
- Rejects body fields: `marginPercent`, `dbco`, `priceListId`, `baseCost`, `customerId`.
- Customer DTO has no base cost / margin / tmktm ids / price-list internals.
- Staff diagnostic (`includeDiagnostic=true`) only with `PRODUCT.PRICING.VIEW`.
- Guests: `LOGIN_REQUIRED` (no anonymous US default).
- No global price cache.
- Legacy MySQL remains read-only.

---

## F. API

| Route | Purpose |
|-------|---------|
| `POST /api/v1/products/{slug}/variants/pricing` | Batch customer-safe sell prices (max 40 ids) |

**Customer item (concept):** `variantId`, `status`, `availability`, `unitPrice?`, `currency?`, `uom`, quantity/length rules, `displayMessage?`, `canAddToRfq`.

**Staff:** nested `customer` + `diagnostic` when permitted.

---

## G. Modal UI

**Files:** `CustomerProductVariantMatrix.tsx`, `customerProductView.css`, `products.ts` (public API), `CustomerProductDatasheet.tsx`.

**States:** RESOLVED · TBC · LOGIN_REQUIRED · CUSTOMER_REQUIRED · PRICE_NOT_AVAILABLE · FX_NOT_AVAILABLE · REVIEW · temporary failure (“Pricing temporarily unavailable”).

**Selected row:** quantity × length → estimated total; Matching Accessories (scroll); Add to RFQ prepares payload **without** trusting client unit price.

---

## H. Performance

- Prices fetched **only when size modal opens**, for visible variants only.
- **One batch POST** per open (not N+1).
- Guests skip network (local LOGIN_REQUIRED).

---

## I. Tests

| Suite | Result |
|-------|--------|
| `ResolveCustomerSellPriceServiceTest` (10) | OK |
| `CustomerVariantPricingBatchActionTest` (3) | OK |
| `CustomerProductVariantMatrix.test.tsx` (5) | OK |
| Live BVA parity smoke | MATCH (see §D) |

---

## J. Database Writes

| Table | Delta |
|-------|-------|
| ProductVariantPrices | **0** (still 20 pilot rows) |
| ProductPrices | **0** |
| CustomerProductPrices | **0** |
| PricingTiers | **0** |
| PriceListCustomers | **0** |

No Phase 13.5; no tmktm migration; no quote mutation.

---

## K. Remaining Business Reviews

| Item | Status |
|------|--------|
| TAS 100/99 rule | `LEGACY_SPECIAL_RULE_REVIEW` — not applied |
| Electrician/distributor TBC | `BUSINESS_POLICY_REVIEW` — map via customer categories when confirmed |
| Guest US pricing | Not enabled; guests → LOGIN_REQUIRED |
| MAKE / setup customer costing | Availability shown; staff MAKE formula separate; catalogue uses normal sell path unless proven otherwise |
| TCASIA | Out of scope |
| Full FX coverage | AUD/USD/NZD only |
| ERP as future source of truth | Unchanged |
| Azure sample markets (`ELECTRICAL`) vs legacy `tmktm` | Many seeded markets have **no** BV margin → correct `TBC` until customer geo codes align with legacy markets that have rules |

---

## HARD STOP

- Do **not** run Phase 13.5 bulk base-cost migration.
- Do **not** wholesale-migrate `tmktm`.
- Do **not** expose base cost on public product DTOs.
- Do **not** enable anonymous pricing without policy approval.

**STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL**

---

## CUSTOMER_VARIANT_PRICING_READY
