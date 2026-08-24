# Product Pricing Phase 2C — Authoritative Unit of Measure (UOM)

## Inspection verdict

| Area | Verdict |
|------|---------|
| Hardcoded `M`/`m` in Phase 1 | **REPLACED** — resolution hierarchy at read time |
| `[product].[SpecificationUnits]` | **REUSE** — same master table as specification units |
| Schema | **EXTEND** — additive FK columns only |
| Legacy `tc.price.uwt` | **OUT OF SCOPE** — no import |
| Public / customer pricing UOM | **OUT OF SCOPE** |

## Resolution hierarchy

At read time (matrix, summaries, history):

```text
price-row SpecificationUnitId
    → variant PricingUnitId
    → product PricingUnitId
    → system default (M / m)
```

Price-row and variant overrides are optional. When unset, the next level applies.

## Schema (additive)

Script: `database/scripts/development/alter-product-pricing-uom-phase2c.sql`

| Table | Column | Purpose |
|-------|--------|---------|
| `Products` | `PricingUnitId` | Product default pricing UOM |
| `ProductVariants` | `PricingUnitId` | Optional variant override |
| `ProductPrices` | `SpecificationUnitId` | Optional row-level override |
| `ProductVariantPrices` | `SpecificationUnitId` | Optional row-level override |

All FK → `[product].[SpecificationUnits]`.

## Seed units (review only)

Script: `database/scripts/development/seed-product-pricing-units-phase2c.sql`

| Code | Name | Symbol |
|------|------|--------|
| M | Metre | m |
| EA | Each | ea |
| FT | Foot | ft |
| PK | Pack | pk |

**Not auto-executed.** Run manually after reviewing reference data.

## API

| Method | Path | Permission |
|--------|------|------------|
| GET | `/api/v1/admin/products/{id}/pricing` | `PRODUCT.PRICING.VIEW` |
| PUT | `/api/v1/admin/products/{id}/pricing` | `PRODUCT.PRICING.MANAGE` |
| PUT | `/api/v1/admin/products/{id}/pricing/default-unit` | `PRODUCT.PRICING.MANAGE` |

### Upsert pricing body (extended)

```json
{
  "currencyCode": "AUD",
  "baseCost": "12.50",
  "unitOfMeasurePublicId": "<uuid|null>"
}
```

`unitOfMeasurePublicId: null` on insert inherits product default. On update, clears row override.

### Set product default body

```json
{
  "pricingUnitPublicId": "<uuid|null>"
}
```

`null` clears product default → system metre fallback.

### Pricing overview (extended)

```json
{
  "defaultUnitOfMeasurePublicId": "...",
  "defaultUnitOfMeasureCode": "M",
  "defaultUnitOfMeasureSymbol": "m",
  "defaultUnitSource": "product"
}
```

`defaultUnitSource`: `product` | `system-default`

### Lookup bundle (extended)

`GET /api/v1/admin/product-lookups` includes `pricingUnits[]` with `publicId`, `code`, `name`, `symbol`.

Cache key bumped to `admin:product-lookups:v2`.

## Admin UI

Pricing tab (`ProductEditorPanels`):

- Product default UOM selector (overview)
- Per-record UOM on base-cost upsert form
- Matrix UOM column uses resolved symbol

## Errors

| Code | When |
|------|------|
| `INVALID_PRICING_UNIT` | Unknown or inactive unit public ID |

## Out of scope (Phase 2C)

- Legacy `tc.price.uwt` import
- Customer / public sell-price UOM
- FX conversion
- Variant default UOM admin UI (schema ready; product default + row override shipped)

## Next phases

Per live validation roadmap:

1. ~~Phase 2E — PriceList / dbco~~ (complete)
2. **Phase 2C — UOM** (this phase)
3. Phase 2B — Variant legacy identity / mask resolution
4. Controlled base-cost import pilot
