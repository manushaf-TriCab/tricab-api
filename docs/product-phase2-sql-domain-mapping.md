# Product Module — Phase 2 SQL-to-Domain Mapping

## Aggregate boundaries (corrected)

| Aggregate root | Associated / owned | Command repository | Query repository |
|----------------|--------------------|--------------------|------------------|
| **Product** | ProductCode (via code rules), classification FKs | `ProductRepository` | `ProductAdminQueryRepository`, `ProductPublicQueryRepository` |
| **ProductVariant** | Variant specs/codes/prices by association | `ProductVariantRepository` | Admin query variant summaries |
| Classification lookups | Family / Category / Series | `ProductClassificationRepository` | `ProductLookupRepository` |
| Specification | Definitions + values | `ProductSpecificationRepository` | (admin detail joins) |
| Media | Images / Videos / 360 / AR registry | `ProductMediaRepository` | Media summaries on queries |
| Document | Documents / CAD / Downloads | `ProductDocumentRepository` | Document summaries on queries |
| Market assignment | Regions / Markets / Industries / Applications | `ProductMarketRepository` | Lookups + admin detail |
| Relationship | Accessories / Related / Replacement / Cross/Up-sell | `ProductRelationshipRepository` | — |
| Pricing (internal) | Price lists / prices — **no public exposure** | `ProductPricingRepository` | Admin pricing summaries only |
| SEO | Slugs / SEO / Redirects / content | `ProductSeoRepository` | Public slug resolution |
| Integration | External systems / sync | `ProductIntegrationRepository` | — |
| Audit | ProductAuditEvents | `ProductAuditRepository` | — |
| Migration | LegacyProductMappings | `LegacyProductMappingRepository` | — |

**ProductVariant is a separate aggregate root**, associated by `ProductId`, with its own repository and RowVersion.

## RowVersion API encoding

`RowVersionToken` transfers SQL Server `rowversion` as **Base64 of the 8-byte binary**.
Repository `update` / `changeStatus` / `archive` / `restore` accept `expectedRowVersion`.
Zero-row updates must throw `ProductConcurrencyException` (Phase 3 adapter).

## Archive vs soft-delete (corrected)

| Concept | Domain | Persistence |
|---------|--------|-------------|
| Archived | `status=archived` via `archive()` | No `DeletedAtUtc` |
| Soft-deleted | `softDelete()` sets `DeletedAtUtc` + `DeletedByUserId` | Excluded from normal queries |
| Inactive | `IsActive=false` | Independent flag |
| Restore archive | `restoreFromArchive()` → draft | Status only |
| Restore deletion | `restoreFromDeletion()` | Clears delete markers |

Customer module does not soft-delete; Product uses Phase 1 columns with corrected semantics.

## Table mapping (all Phase 1 product tables)

| SQL table | Domain representation | Notes |
|-----------|----------------------|-------|
| product.ProductStatuses | Lookup (`ProductStatusCode` seeded VO + LookupItem) | DB-extensible; domain policies use seeded codes |
| product.ProductLifecycleStatuses | Lookup (`ProductLifecycleStatusCode`) | Same pattern |
| product.MediaTypes | Lookup | ProductLookupRepository |
| product.DocumentTypes | Lookup | ProductLookupRepository |
| product.Currencies | Lookup (`CurrencyCode` VO) | |
| product.ProductFamilies | Entity `ProductFamily` | Classification aggregate |
| product.ProductCategories | Entity `ProductCategory` | |
| product.ProductSeries | Entity `ProductSeries` | |
| product.Products | Entity `Product` + Admin/Public DTOs | Aggregate root |
| product.ProductVariants | Entity `ProductVariant` + VariantSummary | |
| product.ProductCodes | Entity `ProductCode` | |
| product.ProductCodeRuleSets | Repository-internal / RuleSet array | Not a rich entity yet |
| product.ProductCodeRuleSegments | Repository-internal | Assembled by ProductCodeSegmentAssembler |
| product.SpecificationGroups | Lookup / SpecDefinition groupCode | |
| product.SpecificationDefinitions | Entity `SpecificationDefinition` | |
| product.SpecificationOptions | Embedded in SpecDefinition DTO | |
| product.ProductSpecificationValues | SpecValue payload + validator | |
| product.ProductVariantSpecificationValues | SpecValue payload + validator | |
| product.ProductSpecificationOptionValues | Junction — repository-internal | Multi-select |
| product.ProductVariantSpecificationOptionValues | Junction — repository-internal | |
| product.ProductImages | MediaSummary + MediaStoragePath | Typed media |
| product.ProductVideos | MediaSummary | |
| product.ProductARModels | MediaSummary | |
| product.ProductCADFiles | Document/Media metadata | Document repo |
| product.ProductDownloads | Document/Media metadata | Document repo |
| product.Product360Sets | Media aggregate child | Media repo |
| product.Product360Frames | Media aggregate child | Media repo |
| product.ProductDocuments | DocumentSummary | |
| product.ProductMedia | Media registry summary | |
| product.Regions | Lookup | |
| product.Markets | Lookup | |
| product.Industries | Lookup | |
| product.Applications | Lookup | |
| product.ProductRegions | Market assignment | ProductMarketRepository |
| product.ProductMarkets | Market assignment | |
| product.ProductIndustries | Link table | |
| product.ProductApplications | Link table | |
| product.ProductAccessories | Entity `ProductRelationship` | type=accessory |
| product.RelatedProducts | Entity `ProductRelationship` | type=related |
| product.ReplacementProducts | Entity `ProductRelationship` | type=replacement |
| product.ProductCrossSells | Entity `ProductRelationship` | type=cross-sell |
| product.ProductUpSells | Entity `ProductRelationship` | type=up-sell |
| product.PriceLists | Pricing repo-internal | No public exposure |
| product.PricingTiers | Lookup / pricing-internal | |
| product.PriceListCustomers | Pricing-internal | Customer FK |
| product.ProductPrices | Entity `ProductPrice` | Admin only |
| product.ProductVariantPrices | Entity `ProductPrice` (variant) | Admin only |
| product.CustomerProductPrices | Pricing-internal | Admin only |
| product.ProductSlugs | Entity `ProductSlugAssignment` | |
| product.ProductSEO | SeoPayload DTO | |
| product.ProductRegionalContent | SEO/market repo-internal | |
| product.ProductContentSections | SEO repo-internal | |
| product.ProductRedirects | SEO repo-internal | |
| product.ExternalSystems | Integration lookup | |
| product.ExternalEntityMappings | Integration-internal | |
| product.ProductIntegrationStatuses | IntegrationStatus DTO | |
| product.ProductSyncLogs | Audit-like integration log | No domain entity |
| product.ProductAuditEvents | ProductAuditEntry | |
| migration.LegacyProductMappings | LegacyProductMappingDetails | Migration only |

## Phase 1 schema notes (non-blocking)

1. **PK naming**: `RelatedProductsId` / similar plural PKs are awkward but workable; optional rename to `RelatedProductId` later.
2. **Soft-delete vs Customer**: intentional divergence.
3. **Status `archived` + `DeletedAtUtc`**: redundant signals; domain keeps both in sync on archive/restore.
4. **No blob infrastructure**: media paths are metadata-only (`MediaStoragePath`).
5. **Pricing FKs to customer**: require `[customer]` schema before applying Phase 1 SQL.

No blocking defect requiring SQL changes in Phase 2.
