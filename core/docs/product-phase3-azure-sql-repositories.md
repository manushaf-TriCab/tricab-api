# Product Module — Phase 3: Azure SQL Repository Adapters

Status: **complete — awaiting approval before Phase 4**

## Inspection report

### Existing persistence conventions (reused)

| Concern | Convention |
|---|---|
| Driver | PDO via `sqlsrv` / Azure SQL (`AzureSqlPdoFactory` → `PdoSqlConnection`) |
| Connection | `SqlConnection` interface; lazy PDO from DI when `USER_REPOSITORY=azure-sql` |
| Transactions | `AzureSqlTransaction` trait — begins only when not already in a transaction |
| Value mapping | `AzureSqlValueMapper` (bool/int/string/datetime); Product adds `ProductRowVersionMapper`, `ProductDecimalMapper` |
| PublicId | UNIQUEIDENTIFIER; omit on INSERT (NEWSEQUENTIALID); SELECT as `CONVERT(NVARCHAR(36), …)` |
| Identity | Prefer `OUTPUT INSERTED.…` then reload aggregate |
| Uniqueness | Application-layer existence checks (not SQL error parsing) |
| Soft-delete | `DeletedAtUtc` / `DeletedByUserId`; filter `DeletedAtUtc IS NULL` unless includeDeleted |
| Decimal | NVARCHAR conversion / string bind — **no float** |
| Pagination | `OFFSET … FETCH NEXT …` with pageSize capped by DTO (≤100) |
| Sort | Allowlisted column map only |
| Exceptions | Domain `ProductConcurrencyException`; infra `ProductPersistenceException` (safe messages) |
| Logging | Technical detail left to connection/driver layer; adapters throw safe messages |
| Tests | `FakeSqlConnection` unit tests (primary). Live Azure gated by `RUN_AZURE_INTEGRATION_TESTS=true` |
| DI | `ContainerFactory` azure-sql vs memory branches |

### RowVersion

Product is the **first** module using SQL Server `ROWVERSION`. Tokens are Base64 (`RowVersionToken`). Updates:

```sql
WHERE [ProductId] = :productId AND [RowVersion] = :expectedRowVersion
```

Zero rows → reload; if still present → concurrency conflict; if missing → not found.

### Archive vs soft-delete

- **Archive** = status code `archived` only (no `DeletedAtUtc`)
- **Soft-delete** = `DeletedAtUtc` set
- Public SQL requires `StatusCode = published` (excludes archived) and `DeletedAtUtc IS NULL`
- Admin includes archived unless filtered; deleted only when `includeDeleted`

---

## Files created

### Azure SQL adapters (`tricab-api/src/Product/Persistence/`)

| Path | Purpose | Interface | Tables |
|---|---|---|---|
| `ProductPersistenceException.php` | Safe persistence exceptions | — | — |
| `ProductRowVersionMapper.php` | rowversion ↔ Base64 / SqlBinary | — | — |
| `ProductDecimalMapper.php` | Decimal ↔ string (no float) | — | — |
| `AzureSqlProductRepository.php` | Product command repo | `ProductRepository` | Products, ProductStatuses, ProductLifecycleStatuses, ProductCodes, ProductSlugs |
| `AzureSqlProductVariantRepository.php` | Variant command repo | `ProductVariantRepository` | ProductVariants, Products, ProductStatuses |
| `AzureSqlProductAdminQueryRepository.php` | Admin list/detail DTOs | `ProductAdminQueryRepository` | Products + joins (family/category/series/status/lifecycle/users/media/codes/regions…) |
| `AzureSqlProductPublicQueryRepository.php` | Public detail DTOs | `ProductPublicQueryRepository` | Products, Slugs, Variants, Images, Documents, Industries, Applications |
| `AzureSqlProductSearchRepository.php` | Search + suggest | `ProductSearchRepository` | Products, Variants, Slugs, Codes, Categories, Series, Regions |
| `AzureSqlProductLookupRepository.php` | Admin dropdown lookups | `ProductLookupRepository` | Statuses, Lifecycle, Families, Categories, Series, Regions, Markets, Industries, Applications, MediaTypes, DocumentTypes, Currencies |
| `AzureSqlProductClassificationRepository.php` | Family/category/series | `ProductClassificationRepository` | ProductFamilies, ProductCategories, ProductSeries |
| `AzureSqlProductSpecificationRepository.php` | Spec defs + values | `ProductSpecificationRepository` | Specification*, Product(Variant)SpecificationValues, *OptionValues |
| `AzureSqlProductMediaRepository.php` | Image metadata (+ registry) | `ProductMediaRepository` | ProductImages, ProductMedia, MediaTypes |
| `AzureSqlProductDocumentRepository.php` | Document metadata | `ProductDocumentRepository` | ProductDocuments, DocumentTypes |
| `AzureSqlProductMarketRepository.php` | Regions/markets/industries/applications | `ProductMarketRepository` | Regions, Markets, Industries, Applications, Product* link tables |
| `AzureSqlProductRelationshipRepository.php` | Accessory/related/replacement/cross/up | `ProductRelationshipRepository` | ProductAccessories, RelatedProducts, ReplacementProducts, ProductCrossSells, ProductUpSells |
| `AzureSqlProductSeoRepository.php` | Slugs + SEO | `ProductSeoRepository` | ProductSlugs, ProductSEO |
| `AzureSqlProductCodeRuleRepository.php` | Code rules + assign | `ProductCodeRuleRepository` | ProductCodeRuleSets, ProductCodeRuleSegments, ProductCodes |
| `AzureSqlProductPricingRepository.php` | Internal pricing foundation | `ProductPricingRepository` | ProductPrices, ProductVariantPrices, Currencies, PriceLists |
| `AzureSqlProductAuditRepository.php` | Audit events | `ProductAuditRepository` | ProductAuditEvents |
| `AzureSqlProductIntegrationRepository.php` | Sync status reads | `ProductIntegrationRepository` | ProductIntegrationStatuses, ExternalSystems |
| `AzureSqlLegacyProductMappingRepository.php` | Migration mapping persistence | `LegacyProductMappingRepository` | migration.LegacyProductMappings |

### InMemory (`…/InMemory/`)

`InMemoryProductStore` + adapters for all 18 contracts (command, query, supporting).

### Tests

| Path | Purpose |
|---|---|
| `tests/Infrastructure/ProductPersistenceTest.php` | FakeSqlConnection adapter tests |
| `tests/Infrastructure/ProductRowVersionMapperTest.php` | RowVersion Base64/binary mapping |

### Docs

| Path | Purpose |
|---|---|
| `tricab-core/docs/product-phase3-azure-sql-repositories.md` | This report |

---

## Files modified

| Path | Reason | Summary |
|---|---|---|
| `tricab-api/src/Bootstrap/ContainerFactory.php` | DI | Bind all Product contracts to Azure SQL or InMemory |
| `tricab-api/src/Identity/Persistence/AzureSqlValueMapper.php` | Typing helper | Added `nullableInt()` for safe mixed extraction |

---

## Repository matrix

| Interface | Implementation | Class | Tables | Transaction | RowVersion | Soft-delete |
|---|---|---|---|---|---|---|
| ProductRepository | AzureSql / InMemory | command | Products (+ status/lifecycle; uniqueness via Codes/Slugs) | save() | yes | load includes deleted; filters elsewhere |
| ProductVariantRepository | AzureSql / InMemory | command | ProductVariants | save() | yes | parent must not be soft-deleted |
| ProductAdminQueryRepository | AzureSql / InMemory | query | Products + joins | read-only | token in DTO | default exclude deleted |
| ProductPublicQueryRepository | AzureSql / InMemory | query | Products + public children | read-only | n/a | exclude deleted + archived + non-published |
| ProductSearchRepository | AzureSql / InMemory | query | Products + search joins | read-only | n/a | publishedOnly default |
| ProductLookupRepository | AzureSql / InMemory | lookup | reference tables | read-only | n/a | active + not deleted |
| ProductClassificationRepository | AzureSql / InMemory | command/lookup | Families/Categories/Series | create ops | table RV unused | active filter |
| ProductSpecificationRepository | AzureSql / InMemory | command | Spec* + values | replace* transactional | n/a (diff soft-delete) | soft-delete unused values |
| ProductMediaRepository | AzureSql / InMemory | command | ProductImages (+ ProductMedia) | create/setPrimary/reorder | n/a | `archive()` = soft-delete |
| ProductDocumentRepository | AzureSql / InMemory | command | ProductDocuments | create | n/a | `archive()` = soft-delete |
| ProductMarketRepository | AzureSql / InMemory | command/lookup | Regions/Markets/… + links | replace* transactional | n/a | regions/markets soft; industries/apps hard delete+insert |
| ProductRelationshipRepository | AzureSql / InMemory | command | five relationship tables | replace transactional | n/a | soft-delete then insert |
| ProductSeoRepository | AzureSql / InMemory | command | ProductSlugs, ProductSEO | upsert transactional | n/a | soft-delete aware |
| ProductCodeRuleRepository | AzureSql / InMemory | command/lookup | RuleSets/Segments/Codes | assign transactional | n/a | active filter |
| ProductPricingRepository | AzureSql / InMemory | command (internal) | ProductPrices / VariantPrices | upsert | n/a | soft-delete aware |
| ProductAuditRepository | AzureSql / InMemory | integration | ProductAuditEvents | record insert | n/a | append-only |
| ProductIntegrationRepository | AzureSql / InMemory | integration | ProductIntegrationStatuses | read-only | n/a | — |
| LegacyProductMappingRepository | AzureSql / InMemory | integration | migration.LegacyProductMappings | upsert | n/a | source unique key |

---

## Query catalogue (significant)

| Method | Tables | Filters | Sorts | Expected index | Max size |
|---|---|---|---|---|---|
| AdminQuery::list | Products + status/lifecycle/family/category/series | deleted, archived, status, lifecycle, family/category/series publicId, search, has*, region/market/industry/application, updated range, missing required | allowlisted name/code/category/series/status/lifecycle/created/updated/displayOrder | PK Products; filtered FKs; consider covering for search LIKE | pageSize ≤100 |
| AdminQuery::findDetailByPublicId | Products + lookups | PublicId, not deleted | — | UX PublicId | 1 |
| PublicQuery::findBySlug / findByPublicId | Products, Slugs, children | published, active, publish window, region visibility | — | UX Slugs (Slug,RegionId) where not deleted; PublicId | 1 + children |
| Search::search | Products + category/series | deleted, publishedOnly, exact code/SKU/slug/code, LIKE name/category/series, region | relevance then name | InternalCode; Variant Sku; Slug | pageSize ≤100 |
| Search::suggest | Products, Variants | published prefix | rank | name/code/sku | ≤25 |
| Lookup::loadAdminLookups | reference tables | active, not deleted | DisplayOrder | code unique indexes | unbounded ref sets (small) |

### Spec replace strategy

Diff-based: soft-delete values whose definitions are absent from the new set; upsert by `(ProductId, SpecificationDefinitionId)`; replace multi-option junction rows. Preserves stable value IDs where definition continues.

---

## RowVersion report

| Entity | Behaviour |
|---|---|
| Product | Load `CONVERT(VARBINARY(8), RowVersion)`; update WHERE expected; reload new token; conflict → `ProductConcurrencyException` |
| ProductVariant | Same pattern |
| Supporting entities | Have DB `RowVersion` columns but Phase 2 contracts do not expose independent concurrency tokens |
| Token format | Base64 via `RowVersionToken` / `ProductRowVersionMapper` |
| Conflict | Zero updated rows + record still exists |

---

## Test report

| Suite | Result |
|---|---|
| Product FakeSqlConnection unit tests | **15 passed**, 51 assertions |
| Product RowVersion mapper tests | included above |
| Live Azure Product integration | **Not run** — schema not applied to disposable Azure DB in this phase; gate `RUN_AZURE_INTEGRATION_TESTS=true` exists for Identity; Product live suite deferred until Phase 1 scripts applied to a **non-production** test database |
| PHPStan `src/Product` | **OK — 0 errors** |
| Domain unit tests (Phase 2, trica-core) | unchanged (42 Product tests) |

### Database used by Fake tests

In-memory `FakeSqlConnection` — no real SQL Server. Schema setup/cleanup: N/A.

---

## Schema concerns (documented — not applied)

1. **Media naming**: contract `mediaCategoryCode` maps to `ProductImages.ImageRoleCode` (`gallery`/`hero`/…); registry `ProductMedia.MediaCategoryCode` uses `image`/`video`/…. Adapter maps intentionally.
2. **Media/Document `archive()`**: domain method name is ambiguous; adapters implement **soft-delete of metadata**, not Product archive status.
3. **Documents/CAD/Downloads**: Document repository implements `ProductDocuments` only; CAD/Downloads tables deferred.
4. **Media scope**: Images (+ ProductMedia registry) only; videos/360/AR deferred.
5. **ProductIndustries / ProductApplications**: no `DeletedAtUtc` — replace uses hard DELETE + INSERT (unlike regions/markets).
6. **Legacy mappings**: no PublicId / RowVersion / checksum columns in Phase 1 schema vs broader prompt wishlist — DTO uses integer id; checksum deferred.
7. **ProductRedirects**: table exists; SEO contract has no redirect API — redirect persistence deferred to later contract extension.
8. **Admin search LIKE on ProductName/InternalCode**: may not be sargable; consider later full-text / Azure AI Search (SearchRepository boundary ready).
9. **Suggested index (not applied)**: filtered index on `Products (ProductStatusId, DeletedAtUtc) INCLUDE (…)` for public/admin status filters if plans show scans after load testing.
10. **SQL script vs SSDT**: Phase 1 scripts remain authoritative for adapters; no silent Phase 1 changes.

---

## Naming mismatch note (Media/Document)

| Domain method | Persistence meaning |
|---|---|
| `ProductMediaRepository::archive` | Soft-delete image/media metadata (`DeletedAtUtc`) |
| `ProductDocumentRepository::archive` | Soft-delete document metadata (`DeletedAtUtc`) |

Do **not** reinterpret as Product status=`archived`.

---

## Deferred work

- Phase 4 application / workflow services
- Phase 5 Slim controllers, routes, HTTP DTOs
- Phase 6 React API clients
- Phase 7 Admin UI
- Blob uploads / Azure Blob access
- ERP clients
- Legacy migration job execution
- Production Azure SQL deployment / Phase 1 apply to prod
- Live Azure Product integration test suite (after disposable DB has Phase 1 schema)
- Azure AI Search replacement behind `ProductSearchRepository`
- Product CAD/Downloads/video media adapters
- ProductRedirects contract methods

---

## Completion checklist

1. Persistence patterns inspected — yes  
2. All approved Product repository interfaces have Azure SQL adapters — yes  
3. Product + Variant optimistic concurrency — yes  
4. Admin vs public query separation — yes  
5. Public visibility enforced in SQL — yes  
6. Soft-delete ≠ archive — yes  
7. Parameterised SQL — yes  
8. Allowlisted sorts — yes  
9. No `SELECT *` — yes  
10. Decimal prices without float — yes  
11. Integration tests — FakeSqlConnection suite passing; live Azure Product deferred (documented)  
12. PHPStan `src/Product` — pass  
13. No Slim routes — yes  
14. No React changes — yes  
15. No production DB changes — yes  
16. No migration jobs — yes  
17. Schema concerns documented — yes  

**Stop here. Do not start Phase 4 without approval.**
