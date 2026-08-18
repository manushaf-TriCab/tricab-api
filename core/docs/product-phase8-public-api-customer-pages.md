# Product Module — Phase 8: Public Product API Expansion and Customer Product Pages

Status: complete. Public Product detail is enriched for customer pages, nested public GETs are exposed, React public clients cover the new contract, and industrial product routes prefer the public API (with local mock fallback for unpublished demos).

## 1. Scope

| In scope | Out of scope |
|---|---|
| Enrich `ProductPublicDetail` (`productCode`, `thumbnailUrl`, `relationships`, `specifications`) | Public pricing / stock |
| Nested public GETs by slug | Blob uploads |
| React public clients + parsers | Azure AI Search |
| Industrial customer page redesign wired to public API | ERP / legacy migration |
| No auth on public routes; no `rowVersion` / ERP / pricing leakage | Cable `catalog.json` replacement |

## 2. Public API inventory

| Method | Path | Action |
|---|---|---|
| GET | `/api/v1/products/{slug}` | `GetPublicProductBySlugAction` (enriched detail) |
| GET | `/api/v1/products/{slug}/variants` | `GetPublicProductVariantsAction` |
| GET | `/api/v1/products/{slug}/media` | `GetPublicProductMediaAction` |
| GET | `/api/v1/products/{slug}/documents` | `GetPublicProductDocumentsAction` |
| GET | `/api/v1/products/{slug}/relationships` | `GetPublicProductRelationshipsAction` |
| GET | `/api/v1/products/{slug}/specifications` | `GetPublicProductSpecificationsAction` |
| GET | `/api/v1/product-search` | `SearchPublicProductsAction` (unchanged) |

Optional query: `regionCode` (document filtering / region visibility).

Nested responses use `{ items: [...] }`. Nested resources are sliced from the same public detail hydration (thin HTTP; no pricing).

## 3. Public detail fields

`ProductPublicDetail` now includes:

- `productCode`, `thumbnailUrl`
- `variants`, `media`, `documents` (existing)
- `applicationNames`, `industryNames` (existing)
- `relationships[]` — related published products only (`relatedProductPublicId`, `relatedProductName`, `relationshipTypeCode`, `relatedSlug`, `displayOrder`)
- `specifications[]` — display-safe values (`code`, `name`, `groupName`, `displayValue`, `unit`)

Still excluded: `rowVersion`, pricing, ERP/legacy IDs, audit, restricted documents.

### Persistence notes

- **InMemory**: hydrates codes, relationships, and specs from the product store.
- **Azure SQL**: relationships union published rows from `ProductAccessories`, `RelatedProducts`, `ReplacementProducts`, `ProductCrossSells`, `ProductUpSells`. Specs use `DefinitionCode` / `DefinitionName` / value columns (not invented `ProductRelationships` / `SpecificationCode` columns).

## 4. React clients

| Client | Path |
|---|---|
| Types | `src/api/admin/products/types.ts` — `PublicProductDetail` + relationship/spec items |
| Parsers | `src/api/admin/products/parse.ts` — ignores admin-only fields |
| API | `src/api/public/products.ts` — detail + nested list helpers via `publicApiRequest` |
| Hook | `usePublicProduct` (existing) — industrial route primary load |

## 5. Customer pages

| Piece | Behaviour |
|---|---|
| `IndustrialProductRoute` | `usePublicProduct(slug)` → API view; on `PRODUCT_NOT_FOUND` only, marketing mock fallback |
| `customerProductView.ts` | Maps public detail (and mock) into one page view model |
| `ProductDetailPage` | Brand/code hero, description, applications/industries, grouped specs, related products, documents, variants |
| `ProductVariantTable` | Variant / code / SKU / status / qty — **no price or stock** |
| `PlugsSocketsPanel` | Navigates to `/products/plugs-sockets/industrial/{slug}` for every card |

Cable product routes remain on `catalog.json` (unchanged).

## 6. Tests

```bash
# tricab-api
vendor/bin/phpunit --filter Product          # 41 tests
vendor/bin/phpstan analyse src/Http/Action/PublicProduct \
  src/Product/Persistence/AzureSqlProductPublicQueryRepository.php \
  src/Product/Persistence/InMemory/InMemoryProductPublicQueryRepository.php \
  src/Http/Action/Admin/ProductManagementDtoSerializer.php \
  src/Bootstrap/AppBuilder.php --memory-limit=512M

# tricab-web
npm test
npm run typecheck
```

Results at completion:

- Product PHPUnit: **41** pass
- PHPStan (Phase 8 surfaces): clean
- Frontend suite: **66** pass
- TypeScript: pass

## 7. Deferred

- Production Azure AI Search behind `ProductSearchRepository`
- Public pricing / authenticated customer price lists
- Blob-backed media/document delivery
- Removing marketing mock fallback once catalog is fully published
- Cable series pages off `catalog.json`
