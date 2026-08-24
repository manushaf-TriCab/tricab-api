# TriCab Product Pricing — Phase 2B: Legacy Variant Pricing Identity

## Inspection summary

| Capability | Existing | Decision |
|------------|----------|----------|
| Product/style legacy identity | `migration.LegacyProductMappings` (100 product mappings, 0 variant pricing) | **REUSE** for migration only |
| Variant legacy pricing identity | None | **NEW** via `ExternalEntityMappings` |
| PriceList / dbco context | Phase 2E `ExternalEntityMappings` + `PriceListLegacyContext` | **REUSE** |
| Exact code matching | `CompareLegacyBaseCostService` (VariantCode fallback) | **EXTEND** via resolver |
| Mask resolution | Not implemented | **NEW** evidence-based rule |
| Admin mapping UI | Pricing tab | **EXTEND** |

## Schema decision

**No new columns on `ProductVariants`.**

Explicit variant → legacy price code mappings stored in:

```text
[product].[ExternalEntityMappings]
  SystemCode      = legacy-tc
  EntityTypeCode  = variant-pricing-item
  InternalEntityId = ProductVariantId
  ExternalEntityKey = tc.price.code
```

Product/style `LegacyProductMappings` remain unchanged.

## Identity separation

| Layer | Example |
|-------|---------|
| Marketing/style | `BVA`, `RRA` |
| Variant/item | `BVA-PAXD/1C4BK` |
| Pricing identity | `BVA-PAXD/1C4BK` or mask `RRA-XXXD/10A16BK` |

## Resolution precedence

1. **EXPLICIT** — operator-approved `ExternalEntityMapping`
2. **EXACT_CODE** — `VariantCode` exists in `tc.price` for PriceList dbco
3. **MASK** — single verified mask candidate (cable rule below)
4. **UNRESOLVED** / **AMBIGUOUS** — never silent fallback

Identity (code + dbco) is separate from price revision selection (`PRICE_REVISION_AMBIGUOUS`, `ZERO_COST`).

## Mask rule (evidence-based)

Cable family pattern validated against live tc validation:

```text
{STYLE}-{TTTT}/{suffix}  →  {STYLE}-XXX{lastCharOfTTTT}/{suffix}
RRA-CAXD/10A16BK         →  RRA-XXXD/10A16BK
```

Implemented in `LegacyPricingMaskGenerator`. Candidates verified against legacy before acceptance.

## PriceList context

`resolveLegacyPricingIdentity(variantPublicId, priceListPublicId)` resolves dbco from PriceList mapping (Phase 2E). No arbitrary dbco from React.

## Core services

- `ResolveLegacyVariantPricingIdentityService`
- `ManageVariantLegacyPricingIdentityService` (explicit mapping CRUD + verify)
- `AuditLegacyVariantPricingIdentityService` (paginated product audit)
- `CompareLegacyBaseCostService` — now uses resolved identity

## API endpoints

| Method | Path | Permission |
|--------|------|------------|
| GET | `/api/v1/admin/products/{id}/pricing/legacy-identities?priceListPublicId=` | VIEW |
| POST | `/api/v1/admin/products/{id}/pricing/legacy-identity-audit` | VIEW |
| GET | `/api/v1/admin/product-variants/{id}/pricing/legacy-identity?priceListPublicId=` | VIEW |
| POST | `/api/v1/admin/product-variants/{id}/pricing/legacy-identity/verify` | VIEW |
| PUT | `/api/v1/admin/product-variants/{id}/pricing/legacy-identity` | MANAGE |
| DELETE | `/api/v1/admin/product-variants/{id}/pricing/legacy-identity` | MANAGE |

Legacy comparison requires `priceListPublicId`.

## Admin UI

Product Editor → **Pricing** tab:

- Price list context selector
- Legacy identity badge per variant
- Identity detail panel with verify / save / remove explicit mapping
- Legacy comparison uses resolved code

## Permissions

- `PRODUCT.PRICING.VIEW` — resolve, list, audit, compare
- `PRODUCT.PRICING.MANAGE` — explicit mapping create/remove

## Security

Public APIs unchanged — no legacy codes, dbco, or costs exposed.

## Performance

Per-variant resolution batches exact + mask codes in one legacy query (`inspectPricingCodes`). Product audit scans paginated matrix rows (default limit 100).

Live catalogue audit: `bin/legacy-variant-pricing-identity-audit.php` (read-only, gated on legacy DSN).

## Migration readiness

A variant is **IDENTITY_READY** when PriceList resolves dbco and legacy pricing code resolves uniquely. **IMPORT_READY** adds valid latest revision, non-ambiguous cost, UOM — deferred to Phase 3.

## Tests

- `LegacyPricingMaskGeneratorTest`
- `LegacyVariantPricingIdentityServiceTest` (explicit, exact, mask, zero-cost)

## Out of scope (Phase 2B)

- No `tc.price` bulk import
- No Azure pricing writes from legacy
- No customer/public pricing
- No legacy MySQL writes

## Remaining blockers

- Live read-only catalogue-wide audit report (run gated script against dev/staging)
- Cross-dbco consistency report (2507 / 2517 / 2519) — script scaffold in audit bin
- Additional mask families if non-cable products require different rules

**STOP — await approval before Phase 3 controlled import pilot.**
