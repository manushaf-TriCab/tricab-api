# Product Pricing Phase 1 — Live `tc` Validation Report

**Generated:** 2026-08-20 (UTC)  
**Scope:** Read-only inspection only. No legacy writes, no Azure SQL pricing writes, no migrations.  
**Connectors used:** `LegacyReadOnlySqlGuard` → `GuardedReadOnlyMysqlConnection` → live `tc` MySQL; Azure SQL via existing `SqlConnection` (Entra CLI, read-only queries).

---

## Executive summary

| Area | Result |
|------|--------|
| **Base-cost source (`tc.price.cost`)** | Confirmed live; latest-`dc` rule matches Phase 1 `findLatestBaseCost()` for exact item codes |
| **Variant identity → legacy item code** | **Works** when `ProductVariant.VariantCode` equals `tc.price.code` (verified on cable samples) |
| **LegacyProductMappings** | **Product-level style keys only** (100 rows, 0 variant-level). Not usable alone for variant base-cost lookup |
| **New-system pricing rows** | **0** `ProductVariantPrices` / **0** `ProductPrices` / **0** `PriceLists` in validated Azure SQL environment |
| **Public cost exposure** | **No blocker** — public DTOs and tests exclude pricing fields |
| **Customer sell price / tmktm / FX** | Validated at schema + sample level only; WordPress PHP not in this repo — sell-price reproduction **not fully executed** |

### Architecture verdict: **CORRECT WITH MINOR GAPS**

Phase 1 correctly models the **base-cost layer** (`UnitPrice` = legacy `tc.price.cost` for a debtor company context). Gaps remain in **PriceList/dbco mapping**, **variant-level legacy mappings**, **UOM**, and **ambiguous duplicate `price` rows** at the same `dc`.

### Recommended next phase: **E → C → B**

1. **E.** Establish `PriceList` ↔ `dbco` (2507/2517/2519) mappings and seed admin context  
2. **C.** Add real UOM per product type (replace hardcoded `M`/`m`)  
3. **B.** Fix/enrich variant-level `LegacyProductMappings` before bulk base-cost import  

---

## 1. Live `tc.price` schema

**Method:** `DESCRIBE price`, `SHOW INDEX FROM price`, aggregate `SELECT`s via guarded connection.

| Column | SQL type (live) | Notes |
|--------|-----------------|-------|
| `my_row_id` | bigint unsigned | **Primary key** (surrogate) |
| `code` | varchar | Item / mask price code |
| `cost` | decimal | Base cost (validated as cost layer, not sell price) |
| `dc` | date | Effective / change date — used for “latest price” |
| `dbco` | int | Debtor company code |
| `br` | int | Appears in duplicate rows at same `dc` (e.g. reel/batch dimension) |
| `uwt` | int | Unit weight / packaging dimension (co-occurring with duplicates) |
| `setup` | int | Setup id — part of composite index |
| `uwtcu` | int | Present |

**Indexes (live):**

- `PRIMARY` on `my_row_id`
- `idx_price_code_dbco_setup` on (`code`, `dbco`, `setup`)

**Volume:**

| Metric | Value |
|--------|------:|
| Total rows | 53,216 |
| Distinct `dbco` values | 3 (2507, 2517, 2519) |
| `dc` range | 2026-07-10 → 2026-08-06 |
| Duplicate (`code`, `dbco`) groups | **1,127** |
| NULL `cost` | 0 |
| Zero `cost` | **768** |
| Negative `cost` | 0 |

**Confirmed columns from audit:** `code`, `dbco`, `dc`, `cost`, `setup`, `uwt`, `br` — all present on live DB.

---

## 2. Debtor company (`dbco`) findings

| dbco | Row count | Distinct `code` | Label (audit) | DB-confirmed label |
|------|----------:|----------------:|---------------|-------------------|
| 2507 | 22,471 | 21,944 | TCA | **Code-derived only** — no description table joined in this validation |
| 2517 | 8,270 | 7,816 | TCUS | **Code-derived only** |
| 2519 | 22,475 | 21,948 | TCASIA | **Code-derived only** |

No evidence of a separate NZ debtor company in live `dbco` values.

Phase 1 code maps currencies as: `2517`/`2519` → USD, default → AUD (`CompareLegacyBaseCostService::debtorCurrency()`).

---

## 3. Latest-price rule (`ORDER BY dc DESC`)

**WordPress rule audited:** latest `dc` → current base cost.

**Phase 1 implementation:** `MysqlLegacyTcRepository::findLatestBaseCost()` — `ORDER BY dc DESC LIMIT 1`.

### Validated behaviours

| Case | Live evidence |
|------|---------------|
| Single history row | Common for exact item codes |
| Multiple historical rows | Observed; latest `dc` selects current cost |
| Same `dc`, multiple rows | **Frequent** — e.g. `BVA-XXXD/3C16` @ dbco 2507 has **5 rows** at max `dc` differing by `br` (cost **identical** at 13.846) |
| Latest cost zero | 768 zero-cost rows exist; lookup returns numeric zero — treat as data quality flag |
| Duplicate latest with **different** costs | Requires case-by-case review; not silently resolved in this report |

**Ambiguity note:** Index includes `setup`; Phase 1 lookup does **not** filter by `setup`/`br`/`uwt`. When multiple rows share max `dc` but **same** `cost`, result is stable. When costs differ at max `dc`, result is **AMBIGUOUS** — current code picks one row arbitrarily (`LIMIT 1`).

---

## 4. Product / Variant → legacy item code

**Azure SQL environment validated:** `tricab-mel.database.windows.net` / database `tc`.

### LegacyProductMappings

| Metric | Value |
|--------|------:|
| Total `tc` mappings | 100 |
| Variant-level (`ProductVariantId` NOT NULL) | **0** |
| Product-level only | **100** |
| Example keys | `DFA`, `DVA`, `EGA` (3-letter **style** codes) |

**Implication:** Mappings do **not** currently link variants to `tc.price.code`. Phase 1 comparison falls back to `VariantCode` / SKU (`CompareLegacyBaseCostService`).

### Sample products inspected

| Product type | Example | Variants | Mapping | tc.price lookup |
|--------------|---------|----------|---------|-----------------|
| Cable (many variants) | Flexible Control LSZH (`9970E6F0-…`) | 485 | None at variant level | **Exact match** via `VariantCode` → `tc.price.code` |
| Legacy-mapped product | Style `DFA` | — | Product-level `DFA` | Price rows are **`DFA-PATD/…` item codes**, not `DFA` alone |
| RRA in tpro | `RRA-CAXD/10A16BK` | — | — | **No** `price` row for exact code; mask rows exist under `RRA-XXXD/…` |
| User UI product (localhost) | RRA (`AE70E6F0-…`) | — | Not found in validated Azure DB | Local/dev DB may differ |

### Variant → legacy cost samples (Flexible Control LSZH)

| Variant code (lookup) | Legacy cost dbco 2507 | Legacy `dc` | New base cost |
|-----------------------|----------------------:|-------------|---------------|
| `BVA-PAXD/1C4BK` | 1.958 | 2026-07-28 | MISSING_NEW |
| `BVA-PAXD/1C6BK` | 2.486 | 2026-07-28 | MISSING_NEW |
| `BVA-PA2D/1C10BK` | 3.312 | 2026-07-28 | MISSING_NEW |

**Conclusion:** For configured cable variants, **`VariantCode` = `tc.price.code` is the authoritative join** today. `LegacyProductMappings` are migration placeholders at **style** granularity, not pricing keys.

---

## 5. Exact item-code pricing

**Live `tpro` columns:** `ida` is the item identifier (no `product_code` / `style` column on live `tpro`).

| Match type | Rule | Result |
|------------|------|--------|
| Exact item code | `tc.price.code` = `tpro.ida` / `ProductVariant.VariantCode` | **Primary rule — validated** |
| Style prefix | `DFA` mapping vs `DFA-PATD/1C120BK` prices | Mapping key ≠ price code |
| Mask fallback | `RRA-XXXD/…`, `BVA-XXXD/…` | **7,002** distinct mask-like codes (~24% of codes) |

Example: `RRA-CAXD/10A16BK` exists in `tpro` but has **no** exact `price` row; mask family `RRA-XXXD/*` does.

Phase 1 **does not** implement mask generation or fallback (by design for this validation).

---

## 6. Mask pricing

| Metric | Value |
|--------|------:|
| Distinct codes containing `XXX` | 7,002 |
| Distinct non-mask codes | 21,982 |

Mask rows often carry **multiple history rows** per (`code`, `dbco`) (e.g. 6 rows for `BVA-XXXD/3C16` @ 2517).

**WordPress precedence (from audit, not re-run here):** exact item code first, then mask substitution. Phase 1 Core uses **exact code only** in `findLatestBaseCost()`.

---

## 7. Base-cost semantics

| Layer | Role | Validated |
|-------|------|-----------|
| `tc.price.cost` | Base cost in debtor-company currency context | Yes — used by Phase 1 |
| FX (`tc.cyrte`) | Converts base for display/sell | Schema confirmed; not applied in Phase 1 storage |
| `tmktm` margins | Customer sell price | Schema confirmed; **not** in Phase 1 |
| WordPress sell price | Customer-facing | **Not reproduced** — WordPress theme PHP not available in `tricab-api` repo |

Staff Price Lookup and MAKE-length costing are **out of scope** for Phase 1 base-cost storage.

---

## 8. New vs legacy base-cost comparison

**Azure SQL state (validated environment):**

| Table | Rows |
|-------|-----:|
| `ProductVariantPrices` | 0 |
| `ProductPrices` | 0 |
| `PriceLists` | 0 |

**Classification for all sampled variants:** `MISSING_NEW` (no stored base costs to compare).

When populated, comparison path is:

```text
ProductVariantPrices.UnitPrice  ↔  tc.price.cost
(via VariantCode / legacyItemCode + legacyDebtorCompanyCode)
```

`CompareLegacyBaseCostService` computes `bcsub` difference — appropriate for validation.

---

## 9. PriceList / region / market mapping

| Concept | Status |
|---------|--------|
| `PriceLists` table | **Missing** (no rows) |
| dbco 2507 / 2517 / 2519 → PriceList | **Missing** |
| Region / Market linkage | Schema supports FKs; **not populated** for pricing |
| Phase 1 admin UI | Allows optional `priceListPublicId` on upsert |

**Verdict:** **Partially mapped in schema only.** Cannot yet model TCA/TCUS/TCASIA debtor contexts in production data.

---

## 10. Currency / FX (`tc.cyrte`)

**Live sample (read-only):**

| id | cyrte | de (effective) | Interpretation (audit) |
|----|------:|----------------|------------------------|
| 3200 | 1.0000 | null | AUD base |
| 3201 | 1.4225 | 2026-08-01 | USD rate (example) |
| 3202 | 1.09613 | 2023-01-03 | Historical |

**Audited formula:** `converted cost = tc.price.cost / cyrte`

Phase 1 **does not** apply FX when storing `UnitPrice` — correct for base-cost-only phase.

---

## 11. Customer context (`tc.contacts`, `tc.cust`)

Tables exist; row counts collected. **No customer PII reported.**

Purpose validated conceptually: customer → country → territory → market drives **tmktm** margin selection in legacy WordPress (not Phase 1).

---

## 12. `tc.tmktm`

| Field | Present | Distinct values (live) |
|-------|---------|----------------------:|
| `stylg` | Yes | 221 |
| `cntry` | Yes | 2 |
| `ter` | Yes | 8 |
| `mkt` | Yes | 96 |
| `pt` | Yes | 2 |
| `cond` | Yes | 3 |
| `mgnr_lo` | Yes | 59 |
| `mgnr_hi` | Yes | 59 |
| `min` / `max` | Present | **0** non-null distinct |

**Total rows:** 40,309

Sample rows show `mgnr_lo` = `mgnr_hi` in many cases. WordPress margin precedence (`getMarginSql`, etc.) **not traced** — source not in repository.

---

## 13–15. Sell-price calculation & customer scenarios

**Status:** **Not fully validated in this run.**

| Scenario | Expected legacy behaviour | Phase 1 |
|----------|---------------------------|---------|
| A — TriCab staff | cost / FX, no margin | N/A (no public pricing) |
| B — Customer | cost / FX → tmktm margin → sell | **Not implemented** (correct) |
| C — Different market | Margin changes | **Not implemented** |
| D — No margin | TBC / unavailable | **Not implemented** |

TAS adjustment (`cost × 100 / 99`) — **not generalized**; requires WordPress code inspection.

---

## 16. Quantity behaviour

No evidence in Phase 1 that quantity tiers affect **stored base cost**. `MinimumQuantity` exists on price rows; `PricingTiers` table exists in schema but is **dormant**.

Legacy catalogue quantity → line total (`qty × unit price`) — **unchanged expectation**.

---

## 17. UOM

| Product type | Expected UOM | Phase 1 |
|--------------|--------------|---------|
| Cable | metre (`M`) | Hardcoded `DEFAULT_UOM_CODE = 'M'`, symbol `m` |
| Accessory | each / pack | **Same fallback** |
| US foot display | foot | **Not derived** |

**Verdict:** `M`/`m` fallback is **TEMPORARY** for accessories and US-foot products; **SAFE** as default for AU/NZ cable metre pricing only.

---

## 18. Phase 1 implementation review

### `UnitPrice` = base cost

**Safe and unambiguous** for admin-only storage **if** developers treat `UnitPrice` / `baseCost` as **internal cost**, never customer sell price.

Docblock on `UpsertProductPricingService` states this explicitly.

### Product-level vs variant-level pricing

**Variant pricing is authoritative** for configured cable SKUs (validated by live code ↔ price matches).

Product-level price would only suit single-SKU products or defaults — **not** 485-variant cables.

### PriceLists

Schema-ready; **data missing**. Required before multi-dbco admin workflows.

### Effective dating

New system supports `EffectiveFromUtc` / `EffectiveToUtc` — **improvement** over legacy “latest dc only”, preserves history in `ProductVariantPrices`.

### CustomerProductPrices / PricingTiers

Should remain **dormant** until sell-price resolver exists.

---

## 19. Semantic risks (code review)

| Location | Risk | Severity |
|----------|------|----------|
| `ProductManagementDtoSerializer::pricingSummary()` | Exposes both `baseCost` and `unitPrice` (same value) | Low — admin only |
| `UpsertProductPricingService` | Named `baseCost` in command | Low |
| Public `ProductManagementDtoSerializer::publicDetail()` | **No pricing fields** | Safe |
| Admin React (`ProductEditorPanels.tsx`) | Labels “Base cost” | Safe if route stays admin-auth |

**High-priority rule:** Never reuse admin pricing DTOs on public routes. Current separation is correct.

---

## 20. Public data leak test

| Endpoint | Pricing fields | Result |
|----------|----------------|--------|
| `GET /api/v1/products/{slug}` | Excluded by serializer | **Pass** (unit test + code review) |
| Product search | No pricing in DTOs | **Pass** |
| Customer Product View | No pricing in DTOs | **Pass** |
| Admin `GET …/pricing` | Intentional | Allowed |

**Blockers:** None identified for public cost exposure.

---

## 21. Price Lookup comparison

Not executed against live WordPress Price Lookup UI in this run.

For **STOCK** items, expected chain: `tc.price.cost` → FX/UOM → staff display.

For **MAKE** items, setup/length adjustments may differ — **not** a Phase 1 base-cost defect.

---

## 22. Validation matrix (sample)

Values shown are **legacy base cost** (dbco 2507, AUD context). New base cost unavailable (no rows).

| Variant / code | New base cost | Legacy cost | Match | dbco | Currency | UOM | Mapping | Notes |
|----------------|-------------:|------------:|-------|------|----------|-----|---------|-------|
| `BVA-PAXD/1C4BK` | — | 1.958 | MISSING_NEW | 2507 | AUD* | m† | VariantCode | Exact price hit |
| `BVA-PAXD/1C6BK` | — | 2.486 | MISSING_NEW | 2507 | AUD* | m† | VariantCode | Exact price hit |
| `BVA-PA2D/1C10BK` | — | 3.312 | MISSING_NEW | 2507 | AUD* | m† | VariantCode | Exact price hit |
| `RRA-CAXD/10A16BK` | — | — | MISSING_LEGACY | — | — | m† | tpro only | No exact price; mask family exists |
| `RRA-XXXD/1C10` (mask) | — | 4.164 | N/A | 2507 | AUD* | m† | Mask | Not used by Phase 1 |
| Style `DFA` (mapping) | — | — | AMBIGUOUS | — | — | m† | Product map | Key ≠ item code |

\*Currency inferred from dbco mapping in code, not stored on `price` row.  
†Hardcoded Phase 1 default.

---

## 23. Blockers

| # | Blocker | Severity |
|---|---------|----------|
| 1 | No `PriceLists` / dbco context in Azure SQL | **High** for multi-company admin pricing |
| 2 | `LegacyProductMappings` are product/style-level only | **Medium** for migration tooling |
| 3 | 1,127 duplicate (`code`,`dbco`) groups; 768 zero costs | **Medium** data quality |
| 4 | Mask codes not supported in Phase 1 lookup | **Medium** for RRA-style gaps |
| 5 | Public cost leak | **None** |

---

## 24. Phase 2 recommendation

| Priority | Option | Rationale |
|----------|--------|-----------|
| 1 | **E — PriceList / dbco mappings** | Required to model 2507/2517/2519 in new system |
| 2 | **C — UOM support** | Hardcoded metre is incorrect for accessories |
| 3 | **B — Variant legacy mappings** | Style-level maps insufficient for pricing admin |
| 4 | **A — Read-only sell-price resolver** | Only after base-cost import path is stable |
| — | **D — ERP authority** | Deferred |

**Do not:** migrate `tc.price` bulk data, enable customer pricing on website, or replace Price Lookup until Phase 1 base-cost path is populated and reviewed.

---

## Appendix A — Validation method

- Live queries executed through `GuardedReadOnlyMysqlConnection` with `LegacyReadOnlySqlGuard` (SELECT/SHOW/DESCRIBE/INFORMATION_SCHEMA only).
- One-off harness: `bin/legacy-pricing-live-validation.php` (tc aggregates).
- Azure read queries via existing DI `SqlConnection` (no writes).
- WordPress margin functions cited in audit **not present** in `tricab-api` / `tricab-web` repositories searched.

---

## Appendix B — Environment notes

- **Legacy MySQL:** `tc-mysql-live.mysql.database.azure.com` / database `tc` (read-only user).
- **Azure SQL:** `tricab-mel.database.windows.net` / database `tc`.
- Localhost product `AE70E6F0-F091-F111-B337-D00D3AE094E4` (RRA) was **not** found in validated Azure SQL; local dev may use a different database snapshot.

---

*End of report. Awaiting approval before any migration, sync, or Phase 2 implementation.*
