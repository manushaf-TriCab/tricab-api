# Product Module — Phase 5: Slim HTTP APIs

Status: complete. Admin and public Product endpoints are wired in `tricab-api`, following the Customer management HTTP patterns from Phase 5 Customer work.

## 1. Inspection report

| Area | Status | Notes |
|---|---|---|
| Error mapping | Done | `ProductManagementErrorMapper` maps all Phase 4 application/domain exceptions to stable JSON codes |
| Request validation | Done | 21 admin + 1 public request classes under `Http/Request/Admin/Product/` and `Http/Request/Public/Product/` |
| DTO serialization | Done | `ProductManagementDtoSerializer` — admin detail/list, mutation ack, public detail, search, readiness, pricing, media, documents, variants, codes, audit, lookups |
| Admin actions | Done | 42 Slim invokable actions under `Http/Action/Admin/` |
| Public actions | Done | `GetPublicProductBySlugAction`, `SearchPublicProductsAction` |
| Routes | Done | `AppBuilder::registerAdminProductRoutes`, `registerPublicProductRoutes` |
| DI | Done | All actions + error mapper autowired in `ContainerFactory`; `ProductSubResourceResolver` for standalone media/document routes |
| Tests | Done | `AdminProductEndpointsTest` (16 cases), `PublicProductEndpointsTest` (4 cases) |
| Blob upload | Deferred | Media/document endpoints accept metadata + storage paths only |
| ERP / migration HTTP | Deferred | No integration or legacy migration execution endpoints |

## 2. Endpoint inventory

### Public (no permission middleware)

| Method | Path | Action |
|---|---|---|
| GET | `/api/v1/products/{slug}` | `GetPublicProductBySlugAction` |
| GET | `/api/v1/product-search` | `SearchPublicProductsAction` |

### Admin — product aggregate

| Method | Path | Permission |
|---|---|---|
| GET | `/api/v1/admin/products` | `PRODUCT.READ` |
| POST | `/api/v1/admin/products` | `PRODUCT.CREATE` |
| GET | `/api/v1/admin/products/{productPublicId}` | `PRODUCT.READ` |
| PATCH | `/api/v1/admin/products/{productPublicId}` | `PRODUCT.UPDATE` |
| POST | `.../publish` | `PRODUCT.PUBLISH` |
| POST | `.../unpublish` | `PRODUCT.PUBLISH` |
| POST | `.../archive` | `PRODUCT.ARCHIVE` |
| POST | `.../restore-archive` | `PRODUCT.RESTORE` |
| POST | `.../soft-delete` | `PRODUCT.UPDATE` |
| POST | `.../restore-deletion` | `PRODUCT.RESTORE` |
| PATCH | `.../lifecycle` | `PRODUCT.UPDATE` |
| GET | `.../publication-readiness` | `PRODUCT.READ` |
| GET | `.../audit` | `PRODUCT.AUDIT.VIEW` |
| GET | `/api/v1/admin/product-lookups` | `PRODUCT.READ` |

### Admin — nested resources

| Method | Path | Permission |
|---|---|---|
| GET/POST | `.../variants` | `PRODUCT.READ` / `PRODUCT.VARIANTS.MANAGE` |
| GET/POST | `.../media` | `PRODUCT.READ` / `PRODUCT.MEDIA.MANAGE` |
| POST | `.../media/reorder` | `PRODUCT.MEDIA.MANAGE` |
| GET/POST | `.../documents` | `PRODUCT.READ` / `PRODUCT.DOCUMENTS.MANAGE` |
| PUT | `.../markets`, `.../industries`, `.../applications` | `PRODUCT.UPDATE` |
| PUT | `.../relationships` | `PRODUCT.RELATIONSHIPS.MANAGE` |
| PUT | `.../specifications` | `PRODUCT.SPECIFICATIONS.MANAGE` |
| GET/PUT | `.../seo` | `PRODUCT.READ` / `PRODUCT.UPDATE` |
| POST | `.../slugs` | `PRODUCT.UPDATE` |
| GET/PUT | `.../pricing` | `PRODUCT.PRICING.VIEW` / `PRODUCT.PRICING.MANAGE` |
| PATCH | `/api/v1/admin/product-variants/{variantPublicId}` | `PRODUCT.VARIANTS.MANAGE` |
| POST | `.../product-variants/{variantPublicId}/archive\|soft-delete\|restore` | `PRODUCT.VARIANTS.MANAGE` |
| PUT | `.../product-variants/{variantPublicId}/specifications` | `PRODUCT.SPECIFICATIONS.MANAGE` |
| POST | `/api/v1/admin/product-media/{mediaPublicId}/set-primary\|soft-delete` | `PRODUCT.MEDIA.MANAGE` |
| POST | `/api/v1/admin/product-documents/{documentPublicId}/withdraw\|soft-delete` | `PRODUCT.DOCUMENTS.MANAGE` |
| POST | `/api/v1/admin/product-codes/preview\|assign` | `PRODUCT.CODES.MANAGE` |

## 3. Permission matrix highlights

- **No `PRODUCT.DELETE` permission** — soft-delete uses `PRODUCT.UPDATE`; business archive uses `PRODUCT.ARCHIVE`.
- **Markets, industries, applications, SEO, slugs** — `PRODUCT.UPDATE` (not separate manage codes).
- **Pricing read/write** — `PRODUCT.PRICING.VIEW` / `PRODUCT.PRICING.MANAGE`.
- **Audit history** — `PRODUCT.AUDIT.VIEW`.
- Development default admin (`DevelopmentUserManagementCatalog`) includes all `PRODUCT.*` codes for in-memory testing.

## 4. Public exposure

`ProductPublicDetail` and search results exclude:

- `rowVersion`, internal numeric IDs
- ERP / legacy / pricing fields
- Soft-deleted and unpublished products (enforced by `ProductPublicQueryRepository` / `ProductSearchRepository`)

Public routes have **no** `RequirePermission` middleware. Unauthenticated requests receive `404 PRODUCT_NOT_FOUND` (unknown slug) or `200` (search), not `401`.

## 5. Concurrency

- Mutations on Product/Variant aggregates require `{ "expectedRowVersion": "<base64>" }` in the JSON body.
- Stale tokens return `409 PRODUCT_CONCURRENCY_CONFLICT`.
- No `If-Match` header support.

## 6. Validation

- Request bodies validated via `RequestFields` / `QueryParams` helpers → `422 VALIDATION_FAILED`.
- List query aliases supported: `status`→`statusCode`, `lifecycleStatus`→`lifecycleStatusCode`, `archived`→`isArchived`, `sort`→`sortBy`, `direction`→`sortDirection`, `familyId`→`familyPublicId`, etc.
- Public search requires `query` parameter; `publishedOnly` is forced `true`.

## 7. Tests

```bash
cd tricab-api
vendor/bin/phpunit --filter Product   # 39 tests (16 admin HTTP + 4 public HTTP + 19 infrastructure)
```

Coverage includes: 401/403, create 201, list/detail, rowVersion update, concurrency 409, archive≠soft-delete, soft-delete 404, publish not ready 422, readiness report, variant duplicate SKU 409, code preview, pricing/audit permission gates, public no-auth access, public field exclusion, invalid sort 422.

## 8. Verification

```bash
vendor/bin/phpunit --filter Product
vendor/bin/phpstan analyse src/Http/Auth/ProductManagementErrorMapper.php \
  src/Http/Action/Admin/ProductManagementDtoSerializer.php \
  src/Http/Action/Admin/ListProductsAction.php \
  src/Http/Action/Admin/CreateProductAction.php \
  src/Http/Request/Admin/Product \
  src/Bootstrap/AppBuilder.php --memory-limit=512M
vendor/bin/phpunit   # full suite: 193 tests
```

## 9. Deferred

- Phase 6 React API clients
- Phase 7 Admin UI
- Blob upload endpoints
- ERP sync / legacy migration HTTP
- Live Azure SQL integration tests for Product HTTP
- Azure AI Search behind `ProductSearchRepository`
