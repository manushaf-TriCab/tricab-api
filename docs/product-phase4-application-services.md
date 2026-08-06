# Product Module — Phase 4: Application Services

Status: complete. All deliverables below are implemented, wired, and verified
by automated tests.

## 1. Scope

Phase 4 adds the Application layer for the Product bounded context:

- Command DTOs (`Application/Command/`) — 32 files.
- Application services (`Application/Service/`) — 44 files, including audit
  recording, cross-aggregate publish readiness evaluation, and one static
  command-assembly helper (`SpecificationCommandAssembler`).
- New/extended contracts and shared ports consumed by those services.
- New application-level exceptions — 13 files (including the two pre-existing
  `ProductNotFound` / `ProductVariantNotFound`, extended with 11 new ones).
- Result/report DTOs — `ProductMutationResult`, `ProductCodePreviewResult`,
  `ProductPublishReadinessReport` (+ `Issue`/`Check`).
- `tricab-api` DI wiring, Azure SQL / in-memory adapter updates, and
  optional PDO unique-constraint translation.
- A hand-written in-memory test double suite (`tricab-core/tests/Product/Doubles/`)
  and an application-service test suite
  (`tricab-core/tests/Product/Application/Service/ProductServicesTest.php`).

No Slim routes/controllers, React UI, blob/ERP/migration execution, or
production database changes were made. Phase 5 (HTTP layer) was not started.

## 2. Contract / shared additions (`tricab-core`)

| Addition | File |
|---|---|
| `ApplicationTransaction` port | `src/Shared/Persistence/ApplicationTransaction.php` |
| `ProductPublishReadinessEvaluator::evaluate()` | `src/Product/Contract/ProductPublishReadinessEvaluator.php` |
| `ProductPublishReadinessReport` / `...Issue` / `...Check` DTOs | `src/Product/Application/DTO/` |
| `ProductNotReadyForPublication` (holds the report) | `src/Product/Application/Exception/ProductNotReadyForPublication.php` |
| `ProductClassificationRepository::find*IdByPublicId()` + `assertHierarchyValid()` | `src/Product/Contract/ProductClassificationRepository.php` |
| `ProductSeoRepository::createRedirect()` | `src/Product/Contract/ProductSeoRepository.php` |

New application exceptions: `InvalidProductClassification`,
`ProductNotReadyForPublication`, `ProductSoftDeleted`, `ProductArchived`,
`InvalidSpecificationSet`, `InvalidProductRelationshipAssignment`,
`InvalidMarketAssignment`, `ProductConcurrencyConflict` (thin wrapper around
the domain `ProductConcurrencyException`, translated at every `save()` call
site that takes an `expectedRowVersion`).

## 3. Commands

All commands are `final readonly` DTOs under `Application/Command/`. Update
commands for the `Product` and `ProductVariant` aggregates carry a
`expectedRowVersion` (Base64 string) for optimistic concurrency. Update
commands use merge-patch semantics: `null` means "leave unchanged", except
for `UpdateProductCommand`'s classification block, which is gated by an
explicit `updateClassification` flag (so callers can clear a family/category
by omitting it while requesting an update).

Full list (32): `CreateProductCommand`, `UpdateProductCommand`,
`PublishProductCommand`, `UnpublishProductCommand`, `ArchiveProductCommand`,
`RestoreArchivedProductCommand`, `SoftDeleteProductCommand`,
`RestoreDeletedProductCommand`, `ChangeProductLifecycleCommand`,
`CreateProductVariantCommand`, `UpdateProductVariantCommand`,
`ArchiveProductVariantCommand`, `SoftDeleteProductVariantCommand`,
`RestoreProductVariantCommand`, `AssignProductSpecificationsCommand`,
`AssignVariantSpecificationsCommand`, `AssignProductMarketsCommand`,
`AssignProductIndustriesCommand`, `AssignProductApplicationsCommand`,
`ReplaceProductRelationshipsCommand`, `AddProductMediaCommand`,
`ReorderProductMediaCommand`, `SetPrimaryProductImageCommand`,
`SoftDeleteProductMediaCommand`, `AddProductDocumentCommand`,
`WithdrawProductDocumentCommand`, `SoftDeleteProductDocumentCommand`,
`PreviewProductCodeCommand`, `AssignProductCodeCommand`,
`UpdateProductSeoCommand`, `ChangeProductSlugCommand`,
`UpsertProductPricingCommand`.

## 4. Services

### Core lifecycle
`CreateProductService`, `UpdateProductService`, `PublishProductService`,
`UnpublishProductService`, `ArchiveProductService`,
`RestoreArchivedProductService`, `SoftDeleteProductService`,
`RestoreDeletedProductService`, `ChangeProductLifecycleService`.

- `ArchiveProductService` performs a **business** archive only
  (`Product::archive()`) — it never sets `DeletedAtUtc`. Soft-delete is a
  fully separate workflow (`SoftDeleteProductService`), consistent with the
  entity's documented lifecycle semantics.
- `PublishProductService` runs `ProductPublishPolicy::assertCanPublish()`
  (entity-level: rejects soft-deleted/archived, validates the status
  transition) followed by `ProductPublishReadinessEvaluator::assertReadyToPublish()`
  (cross-aggregate). A `ProductNotReadyForPublication` carrying the full
  report is thrown if any check is an `error`-severity issue; warnings never
  block.
- All row-version-guarded saves catch the domain `ProductConcurrencyException`
  and re-throw the application-level `ProductConcurrencyConflict`.

### Audit + readiness
- `ProductAuditRecorder` mirrors `Identity\UserManagementAuditRecorder`. It
  masks any audit value key containing `price` (case-insensitive substring,
  e.g. `unitPrice`) to `***` before writing.
- `CrossAggregateProductPublishReadinessEvaluator` implements
  `ProductPublishReadinessEvaluator`. Evidence-based checks only:
  - **Errors (block publish)**: missing classification (no family *and* no
    category), no active region assignment, lifecycle `replaced` with no
    `replacement` relationship.
  - **Warnings (do not block)**: no variants, no primary slug, no media, no
    documents, empty short description.
  - `product.name.present` and `publishWindow.valid` are always-true checks
    because `Product`'s constructor already enforces them.

### Variants
`CreateProductVariantService` (archived parents may receive new variants —
documented admin-maintenance case; soft-deleted parents throw
`ProductSoftDeleted`), `UpdateProductVariantService`,
`ArchiveProductVariantService`, `SoftDeleteProductVariantService`,
`RestoreProductVariantService` (single command with a `fromDeletion` flag
selecting `restoreFromArchive()` vs `restoreFromDeletion()`).

### Code
`PreviewProductCodeService` (read-only — never persists; flags
`isUnique: false` with a warning instead of throwing when the generated code
collides), `AssignProductCodeService` (regenerates from the rule set,
checks uniqueness via `ProductRepository::productCodeExists()`, throws
`DuplicateProductCode` pre-emptively, then calls
`ProductCodeRuleRepository::assignPrimaryCode()`).

### Specs / media / documents / markets / relationships / SEO / pricing
- `SpecificationCommandAssembler` (static helper): validates each input spec
  value against its definition — unknown definition, scope mismatch
  (`product` vs `variant` vs `both`), and required-value shape via
  `SpecificationValueValidator` — before producing the `SpecValue[]` shape the
  repository expects. Used by both `AssignProductSpecificationsService` and
  `AssignVariantSpecificationsService`.
- Media: `AddProductMediaService`, `ReorderProductMediaService`,
  `SetPrimaryProductImageService`, `SoftDeleteProductMediaService` (calls
  `ProductMediaRepository::archive()`, named for the business action, not the
  storage verb).
- Documents: `AddProductDocumentService`, `WithdrawProductDocumentService`,
  `SoftDeleteProductDocumentService` — both of the latter call
  `ProductDocumentRepository::archive()` but record distinct audit
  `actionCode`s (`withdrawn` vs `soft-deleted`) to preserve business intent.
- Markets: `AssignProductMarketsService`, `AssignProductIndustriesService`,
  `AssignProductApplicationsService` — validate the supplied public IDs
  against the active lookup lists before calling the corresponding
  `ProductMarketRepository::replaceProduct*()` method.
- `ReplaceProductRelationshipsService` — resolves related products, rejects
  soft-deleted targets (`InvalidProductRelationshipAssignment`), and uses
  `ProductRelationshipPolicy::assertCanAdd()` for self-reference/duplicate
  detection (throws the domain `InvalidProductRelationshipException`).
- SEO: `UpdateProductSeoService` (thin wrapper over
  `ProductSeoRepository::upsertSeo()`), `ChangeProductSlugService` — when the
  *primary, default-region* slug changes, records a 301 redirect from the
  previous slug via `ProductSeoRepository::createRedirect()`. Regional-slug
  redirect history is intentionally not tracked (the read contract only
  resolves the default-region primary slug).
- `UpsertProductPricingService` — internal/admin-only; validates
  `CurrencyCode`/`Money`, calls `ProductPricingRepository::upsert()`, and
  records an audit entry with the unit price masked.

### Queries
`ListAdminProductsService`, `GetAdminProductService`, `GetPublicProductService`,
`SearchProductsService`, `GetProductLookupsService`,
`GetProductPublishReadinessService`, `GetProductAuditHistoryService`,
`GetProductPricingService`, `GetProductVariantsService` — thin delegations to
the existing Phase 2/3 query repositories, translating `null` finds into
`ProductNotFound` / `ProductVariantNotFound` where the caller expects a
single result.

## 5. Result DTOs

- `ProductMutationResult { publicId, rowVersion, statusCode, createdAtUtc, updatedAtUtc }`
  — returned by every mutating Product/Variant service.
- `ProductCodePreviewResult { generatedCode, explanation, validationErrors, warnings, isUnique }`.
- Query services reuse the existing Phase 2/3 read models
  (`ProductAdminDetail`, `ProductPublicDetail`, `ProductSearchResult`, etc.).

## 6. `tricab-api` adaptations

| Change | File(s) |
|---|---|
| `ApplicationTransaction` — Azure SQL (nested-safe, commits/rolls back only the outermost `run()`) | `src/Shared/Persistence/SqlApplicationTransaction.php` |
| `ApplicationTransaction` — in-memory no-op | `src/Shared/Persistence/NoOpApplicationTransaction.php` |
| Classification repo: `find*IdByPublicId()` + `assertHierarchyValid()` | `src/Product/Persistence/AzureSqlProductClassificationRepository.php`, `.../InMemory/InMemoryProductClassificationRepository.php` |
| SEO repo: `createRedirect()` → `product.ProductRedirects` | `src/Product/Persistence/AzureSqlProductSeoRepository.php`, `.../InMemory/InMemoryProductSeoRepository.php` (+ `InMemoryProductStore::redirects()/addRedirect()`) |
| DI wiring — all 39 Phase 4 services autowired, `ProductAuditRecorder`, `ProductPublishReadinessEvaluator` → `CrossAggregateProductPublishReadinessEvaluator`, `ApplicationTransaction` bound per mode | `src/Bootstrap/ContainerFactory.php` |
| Optional unique-constraint → domain exception translation (SQLSTATE `23000`, SQL Server driver codes `2627`/`2601`) | `src/Product/Persistence/UniqueConstraintTranslator.php` (trait), applied to `AzureSqlProductCodeRuleRepository::assignPrimaryCode()` → `DuplicateProductCode`, `AzureSqlProductSeoRepository::upsertSlug()` → `DuplicateProductSlug`, `AzureSqlProductVariantRepository::save()` → `DuplicateVariantSku` |

`ContainerFactory` registers `ApplicationTransaction` as
`SqlApplicationTransaction` when `USER_REPOSITORY=azure-sql` and as
`NoOpApplicationTransaction` for the in-memory branch; every other Phase 4
service is autowired once in the shared definitions block because their
dependencies (repositories, `ApplicationTransaction`, `ProductAuditRecorder`,
`Clock`, `ProductPublishReadinessEvaluator`) are already bound identically in
both branches.

## 7. Tests

### `tricab-core/tests/Product/Doubles/`
`InMemoryProductStore.php` — a single facade class holding all in-memory
state plus 13 nested repository-adapter classes (`InMemoryFakeProduct*Repository`),
mirroring the tricab-api `InMemory*` pattern but living in `tricab-core` so
core tests never depend on the API package or a real database. It implements:
`ProductRepository`, `ProductVariantRepository`, `ProductAuditRepository`,
`ProductClassificationRepository`, `ProductAdminQueryRepository`,
`ProductMediaRepository`, `ProductDocumentRepository`, `ProductMarketRepository`,
`ProductRelationshipRepository`, `ProductSeoRepository`, `ProductPricingRepository`,
`ProductCodeRuleRepository`, `ProductSpecificationRepository`. Row versions
are generated from an incrementing 8-byte packed counter so optimistic
concurrency conflicts are exercisable without any database.

`PassthroughApplicationTransaction.php` — runs the callback directly (no
begin/commit/rollback), the in-core equivalent of `NoOpApplicationTransaction`.

### `tricab-core/tests/Product/Application/Service/ProductServicesTest.php`
18 tests covering every minimum scenario requested:

1. Create product records an audit entry.
2. Update with a stale `expectedRowVersion` throws `ProductConcurrencyConflict`.
3. Archive does not soft-delete.
4. Restore from archive.
5. Soft-delete + restore from deletion.
6. Publish succeeds once classification + an active region are present.
7. Publish fails (`ProductNotReadyForPublication`) with neither.
8. Warnings (no variants/media/documents/slug/short description) do not
   block a publish-ready evaluation.
9. Create variant for a product.
10. Create variant rejected for a soft-deleted parent (`ProductSoftDeleted`).
11. Duplicate variant SKU throws `DuplicateVariantSku`.
12. Code preview never persists (product code remains unassigned afterwards).
13. Code assignment persists (`productCodeExists()` becomes true).
14. Specification scope mismatch throws `InvalidSpecificationSet`.
15. Setting a primary product image flips `isPrimary` on the target media row.
16. Replacing relationships with a self-reference throws
    `InvalidProductRelationshipException` (domain-level, raised by
    `ProductRelationshipPolicy`).
17. Changing the primary slug twice records exactly one redirect
    (old slug → new slug).
18. Pricing preserves decimal precision (`199.995000` → `199.995`) and the
    audit trail masks the unit price (`***`) while never containing the raw
    amount.

### `tricab-api` — `tests/Config/SettingsTest.php`
7 tests: unknown `USER_REPOSITORY` throws; `production` + `memory` throws;
`production` + `memory` allowed only with explicit
`ALLOW_IN_MEMORY_REPOSITORY_IN_PRODUCTION=true` (test harness escape hatch);
`production` default is `azure-sql` when unset; `development` default is
`memory` when unset; `production` + `azure-sql` is allowed; `USER_REPOSITORY`
value comparison is case-insensitive.

## 8. Verification results

```bash
# tricab-core
vendor/bin/phpunit --filter Product
# → Tests: 60, Assertions: 109, OK

vendor/bin/phpstan analyse src/Product --memory-limit=512M
# → 183 files analysed, no errors

# tricab-api
vendor/bin/phpunit
# → Tests: 173, Assertions: 929, Skipped: 1, OK

vendor/bin/phpstan analyse src/Product src/Config/Settings.php src/Shared src/Bootstrap/ContainerFactory.php --memory-limit=512M
# → no errors
```

File counts introduced in this phase: 32 commands, 44 services (including
`ProductAuditRecorder`, `CrossAggregateProductPublishReadinessEvaluator`, and
the `SpecificationCommandAssembler` helper), 11 new application exceptions, 3
new report DTOs (`ProductPublishReadinessReport`/`Issue`/`Check`) plus
`ProductMutationResult` and `ProductCodePreviewResult`, 1 new shared port
(`ApplicationTransaction`), 2 `tricab-api` transaction adapters, 1
unique-constraint-translation trait, 1 in-memory test-double facade (13
nested repository fakes), and 1 application-service test class (18 tests).

## 9. Repository driver configuration

Supported `USER_REPOSITORY` values:

| Value | Environments | Behaviour |
|---|---|---|
| `memory` | development / tests | In-memory Product (and Identity/Customer) repositories |
| `azure-sql` | all, **required default in production** | Azure SQL adapters |

Rules:
- Unknown values fail at startup (`InvalidArgumentException`).
- Production defaults to `azure-sql` when unset (never silently to memory).
- Production + `memory` fails unless
  `ALLOW_IN_MEMORY_REPOSITORY_IN_PRODUCTION=true` (explicit test harness only).

## 10. Deferred work

- Phase 5 Slim routes/controllers / HTTP DTOs
- Phase 6 React API clients
- Phase 7 Admin UI
- Blob uploads, ERP clients, legacy migration jobs
- Production Azure SQL deployment / Phase 1 schema apply
- Live Azure Product integration tests
- Azure AI Search replacement behind `ProductSearchRepository`
