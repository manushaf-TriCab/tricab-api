# Product Module — Phase 6: React API Clients

Status: complete. Admin and public Product TypeScript clients, AbortController hooks, query-key invalidation pub/sub, permission helpers, and tests are in `tricab-web`. No TanStack Query was added — the frontend already uses hand-rolled hooks.

## 1. Inspection report

| Area | Finding |
|---|---|
| Frontend layout | Flat `src/api`, `src/components/admin/<domain>`, no `features/` folder |
| API client | `src/api/apiClient.ts` — `apiRequest` (Bearer) + `publicApiRequest` (no auth) |
| Query library | **None** — AbortController + `reloadToken` (Customer/User pattern) |
| Query keys | Introduced Product-only `productKeys` + invalidation pub/sub |
| Errors | `ApiError` extended with optional `fields` + `correlationId`; `normalizeApiError` |
| Permissions | Colocated `PRODUCT_PERMISSIONS` + helpers (mirror Customer) |
| Tests | Vitest + Testing Library; fetch stubbing (no MSW) |
| Product mocks | `src/data/productDetails.ts`, `products.ts`, `catalog.json` — marketing only |
| Toast | None — hooks expose `error` strings / `NormalizedApiError` |
| Phase docs | Sibling `tricab-core/tricab-core/docs/` |

## 2. Files created

| Path | Purpose |
|---|---|
| `src/api/productErrors.ts` | Normalised Product/API error model |
| `src/api/admin/products/types.ts` | Request/response TypeScript models |
| `src/api/admin/products/parse.ts` | Envelope + DTO parsers (prices as strings) |
| `src/api/admin/products/productsApi.ts` | Core CRUD, lifecycle, readiness, audit |
| `src/api/admin/products/resourcesApi.ts` | Variants, media, docs, specs, markets, SEO, pricing, codes, lookups |
| `src/api/admin/products/index.ts` | Re-exports |
| `src/api/public/products.ts` | Public slug + search clients |
| `src/api/productPhase6.test.ts` | API + permission + error tests |
| `src/components/admin/products/permissions.ts` | Exact seeded codes + helpers |
| `src/components/admin/products/queryKeys.ts` | Central query keys |
| `src/components/admin/products/invalidation.ts` | Pub/sub invalidation helpers |
| `src/components/admin/products/hooks/*` | Query + mutation hooks |
| `docs/product-phase6-react-api-clients.md` | This document |

## 3. Files modified

| Path | Reason |
|---|---|
| `src/api/apiClient.ts` | Capture `fields` / `correlationId` on `ApiError` |
| `src/data/productDetails.ts` | PHASE 6 keep-temporarily annotation |
| `src/data/products.ts` | PHASE 6 keep-temporarily annotation |

## 4. API client inventory (summary)

Admin (`apiRequest` + Bearer): all Phase 5 routes under `/api/v1/admin/products*`, variants, media, documents, product-codes, product-lookups.

Public (`publicApiRequest`, no auth):

| Method | Endpoint |
|---|---|
| `getPublicProductBySlug` | `GET /api/v1/products/{slug}` |
| `searchPublicProducts` | `GET /api/v1/product-search` |

Pricing is **only** in admin `getProductPricing` / `upsertProductPricing`. Public parsers never map `rowVersion` or price fields.

**API gaps (not invented on the client):** no GET for markets/industries/applications/relationships/specifications collections; no standalone GET variant-by-id; no product-code list GETs; no media/document restore endpoints in Phase 5 AppBuilder.

## 5. Hook inventory

Query hooks: `useAdminProducts`, `useAdminProduct` (exposes `rowVersion`), `useProductPublicationReadiness`, `useProductVariants`, `useProductMedia`, `useProductDocuments`, `useProductSeo`, `useProductPricing` (admin only), `useProductAudit`, `useProductLookups`, `usePublicProduct` (`notFound` on 404), `usePublicProductSearch`, `useDebouncedValue`.

Mutation hooks: create/update/lifecycle/variant/code/spec/media/document/market/relationship/SEO/pricing — see `useProductMutations.ts`. Preview code does **not** invalidate. Concurrency conflicts are **not** retried.

## 6. RowVersion

- Transport: JSON body `expectedRowVersion` (Base64).
- Loaded via `useAdminProduct().rowVersion` / mutation results.
- Conflict: `409 PRODUCT_CONCURRENCY_CONFLICT` → `NormalizedApiError.isConcurrencyConflict`.
- Product vs Variant tokens are separate (variant mutations use variant payload tokens).

## 7. Invalidation matrix (selected)

| Mutation | Invalidates |
|---|---|
| Product create | admin lists |
| Product update / lifecycle | detail, lists, readiness |
| Variant mutations | variants, detail, lists, readiness |
| Media / documents | media/docs, detail, lists, readiness |
| Code assign | detail, lists, readiness |
| Code preview | *(none)* |
| SEO / slug | seo, detail, readiness, old/new public slug keys |
| Pricing | pricing, readiness |

## 8. Mock-data report

| Source | Consumer | Equivalent API | Action |
|---|---|---|---|
| `productDetails.ts` | Marketing product pages | `getPublicProductBySlug` | **Keep** — DTO thinner than UI |
| `products.ts` categories | Marketing nav | Lookups / future catalog | **Keep** |
| `catalog.json` | Cable search UI | Not Phase 5 | **Keep** — design/catalog prototype |
| Admin ModulePlaceholder products | Admin shell | Admin Product APIs | Deferred to Phase 7 UI |

## 9. Error handling

`normalizeApiError` maps validation, 401, 403, 404, duplicates, concurrency, publication blocked, unexpected. Safe API messages only — no SQL exposure.

## 10. Deferred

- Phase 7 Product Admin UI
- Public Product page redesign (wire `usePublicProduct`)
- Blob uploads
- ERP / migration / Azure AI Search
- Live Azure SQL Product frontend E2E
- Production deployment

## 11. Verification

```bash
cd tricab-web
npm test -- src/api/productPhase6.test.ts src/components/admin/products
npm run typecheck
```
