# TriCab Product Module — Phase 8.5 Stabilisation Report

**Status:** Stabilisation complete — awaiting approval before Phase 9 (Azure Blob / file management).  
**Date:** 2026-08-05  
**Database target:** `tricab-mel.database.windows.net` / database `tc` (disposable non-production). **Production was not modified.**

---

## 1. Database apply report

Scripts applied (go-sqlcmd, `ActiveDirectoryAzCli`), in order:

| # | Script | Result |
|---|---|---|
| 1 | `database/scripts/development/create-product-schema.sql` | Applied — 58 `product.*` tables |
| 2 | `database/scripts/development/seed-product-reference-data.sql` | Applied — statuses, media/document types, currencies, regions, markets |
| 3 | `database/scripts/development/seed-product-roles-and-permissions.sql` | Applied — 17 `PRODUCT.*` permissions; Administrator linked |
| 4 | `database/scripts/development/seed-product-catalogue-sample.sql` | Applied — sample families/categories/series/specs/code rule |

**Not applied:** production, Blob, ERP, Azure AI Search, legacy migration jobs.

---

## 2. Test-data inventory

### Reference / classification (sample seed)

| Kind | Codes |
|---|---|
| Families (2) | `CABLE`, `PLUG` |
| Categories (4) | `FLEX-POWER`, `CTRL-MULTI`, `IND-PLUG`, `IND-SOCKET` |
| Series (2) | `TC-FLEX`, `J-SERIES` |
| Spec definitions (7) | `VOLTAGE`, `CURRENT`, `CORES`, `CSA`, `SHEATH`, `STANDARD`, `IP-RATING` |
| Industries (3) | `MINING`, `MANUFACTURING`, `INFRA` |
| Applications (3) | `TEMP-POWER`, `MOBILE-EQ`, `FIXED-INSTALL` |
| Code rule | `PHASE85-SIMPLE` → `TC-{suffix}` |

### Products (representative; not full legacy catalogue)

- Durable public anchor: **`phase85-sample-flex-cable`** (`TC-SAMPLE`) — 2 published variants, specs, media, public datasheet, AU/NZ regions, accessory relationship.
- Additional Phase 8.5 E2E leftovers: ~15 published accessories/flex cables from workflow runs; ~18 drafts (including intentionally unpublished drafts).

Images/documents are **metadata only** (CDN example URLs / storage paths). No Blob upload.

---

## 3. End-to-end workflow report

Live path validated:

`React Admin UI clients` → `Slim Product APIs` → `application services` → `Azure SQL repositories` → `Azure SQL product schema` → `public Product APIs` → customer-safe payloads.

Harness: `tricab-api/tests/Integration/AzureSqlProductCatalogueStabilisationTest.php`  
Gate: `RUN_AZURE_INTEGRATION_TESTS=true`

| # | Workflow | Result |
|---|---|---|
| 1–2 | Create / update product | Pass |
| 3 | Variant create / update | Pass |
| 4 | RowVersion conflict → 409 | Pass |
| 5 | Product code preview / assign | Pass |
| 6 | Product specifications | Pass |
| 7–8 | Media / document metadata | Pass |
| 9–11 | Markets/regions, industries/apps, relationships | Pass |
| 12–14 | SEO/slug, readiness, publish | Pass |
| 15–17 | Public detail, nested endpoints, search | Pass |
| 18–19 | Archive/restore, soft-delete (+ restore attempt) | Pass |
| 20–21 | Permissions / public exposure checks | Pass (see matrices) |
| 22 | Timing assertions (&lt; 15s each timed step) | Pass |

**Final run:** `OK (1 test, 102 assertions)`.

---

## 4. Failed scenarios (recorded during stabilisation)

| Failure | Request / symptom | Root cause | Fix |
|---|---|---|---|
| Media create 503 | `POST .../media` `mediaCategoryCode=image` | Mapped to `ProductImages.ImageRoleCode`; CHECK allows `primary\|gallery\|…` only | Domain `ImageRoleCode`; Admin defaults `primary` / `primary-image`; E2E corrected |
| Accessory assign 422 | relationships without requirement | Domain requires `accessoryRequirementCode` | E2E + Admin RelationshipsPanel send `optional`/`required`/`compatible` |
| Public variants empty | variants created as `draft` | Public SQL filters `published` variants only | E2E creates published variants |
| Public `?regionCode=` 503 | `GET /products/{slug}?regionCode=AU` | sqlsrv rejects duplicate `:regionCode` | Distinct `:slugRegionCode` / `:productRegionCode` |
| Public search 503 | `GET /product-search?query=…` | Duplicate `:exactQuery` / `:likeQuery` / suggest `:likePrefix` | Unique named params per occurrence |
| Admin media/docs defaults | `IMAGE`/`PRIMARY`, `DATASHEET`/`PUBLIC` | Case/code mismatch vs seed + CHECK | Lowercase seeded codes / image roles |
| Earlier Phase 8.5 defects (pre-summary) | markets UQ, SEO/pricing PDO, unused UPDATE binds, lookup gaps | sqlsrv / schema adapter issues | Already fixed in Azure adapters + lookups |

---

## 5. Fixes applied (Phase 1–8 defects only)

### Core (`tricab-core`)

- `ImageRoleCode`, `DocumentAccessCode` value objects
- `AddProductMediaService` / `AddProductDocumentService` validate role, access, storage path before persistence
- Unit coverage in `ProductValueObjectsTest`

### API (`tricab-api`)

- `AzureSqlProductPublicQueryRepository` — region filter parameter uniqueness
- `AzureSqlProductSearchRepository` — search/suggest parameter uniqueness
- Live integration harness `AzureSqlProductCatalogueStabilisationTest`
- Catalogue sample seed script (families/categories/series/specs/rule)

### Web (`tricab-web`)

- Mock fallback gated: **PROD always off**; local DEV on (overridable via `VITE_PUBLIC_PRODUCT_MOCK_FALLBACK`)
- Admin media/document defaults + relationship accessory requirement + lowercase relationship types
- `publicProductMockFallback.test.ts`

---

## 6. API performance report

Measured on live Azure SQL E2E (single-run, cold token acquisition included in first step):

| Step | Time (ms) |
|---|---|
| Lookups | ~3383 (includes Entra CLI token + first connection) |
| Create product | ~213 |
| Publish | ~354 |
| Public detail | ~276 |
| Public search | ~31 |
| Admin list (`pageSize=25`) | ~118 |

All timed steps &lt; 15s gate. Complex product detail (variants + specs + docs + relationships) acceptable for current volume. Search is fast on small catalogue; AI Search still deferred.

---

## 7. SQL / index concerns

1. **sqlsrv named-parameter uniqueness** — recurring class of defects; any SQL with the same `:name` twice fails as `DATABASE_UNAVAILABLE`. Prefer distinct names or branched SQL.
2. **`ProductRegions` / `ProductMarkets` unique keys** are not soft-delete filtered — adapters hard-delete-then-insert on replace.
3. **Variant unique indexes** can surface as `DUPLICATE_VARIANT_SKU` even for `VariantCode` collisions — error mapping is imprecise.
4. **Public variant visibility** requires variant `statusCode=published` (parent published is not enough).
5. **ImageRoleCode vs MediaCategoryCode** — API field `mediaCategoryCode` means image *role* for image rows; registry category `image` is stored separately on `ProductMedia`.
6. Soft-delete restore after E2E may leave primary product deleted while accessories remain published — durable sample slug `phase85-sample-flex-cable` is the stable public anchor.

---

## 8. Permission verification matrix

| Concern | Finding |
|---|---|
| Soft-delete permitted by `PRODUCT.UPDATE` | **Confirmed** — `POST .../soft-delete` gated by `PRODUCT.UPDATE` (not a dedicated delete permission). Intentional current design; flag if product owners want tighter control before production. |
| Pricing GET | `PRODUCT.PRICING.VIEW` — 403 without it (`AdminProductEndpointsTest`) |
| Pricing PUT | `PRODUCT.PRICING.MANAGE` |
| Audit GET | `PRODUCT.AUDIT.VIEW` — 403 without it |
| Public routes | No admin auth; customer-safe serializer |
| In-memory repos in production | **Blocked** — `Settings` throws if `APP_ENV=production` and `USER_REPOSITORY=memory` |
| Soft-deleted products | Admin get → 404 without explicit restore path; public → 404 |

---

## 9. Public data exposure report

Validated on published sample + E2E assertions:

| Field / concern | Public behaviour |
|---|---|
| Correct slug / product code / thumbnail | Present |
| Specs, published variants, public documents, relationships | Present |
| Restricted documents | Excluded (`AccessCode = public` only) |
| Draft / archived | 404 |
| Region filter | Visible for AU; hidden (404) for AMER when not assigned |
| `rowVersion`, `pricing`, `erpReference`, `legacyProductId` | Not present on public detail |

---

## 10. Accessibility and responsive report

| Area | Notes |
|---|---|
| Mobile Product page | Existing layout uses responsive Tailwind; not redesigned in 8.5. Spot-check recommended on `/products/.../phase85-sample-flex-cable` before release. |
| Accessibility | Loading/error empty states present; accessory requirement `<select>` has `aria-label`. Full WCAG audit **not** completed in this phase. |
| Broken images/docs | Metadata URLs point at `cdn.example.test` — expect broken media until Phase 9 Blob; UI should tolerate missing assets (empty/broken img). |
| Empty states | Admin panels show empty copy; public not-found navigates away / error UI when mock fallback off. |
| Pagination | Admin list + public search pagination exercised via API; UI pagination unchanged. |

---

## 11. Remaining release blockers

Before production Product catalogue go-live:

1. **Do not apply Product schema to production** until a controlled migration plan exists.
2. **Clean disposable `tc` E2E leftovers** or isolate a dedicated catalogue sandbox DB.
3. **Decide whether soft-delete should remain under `PRODUCT.UPDATE`** or move to a dedicated permission.
4. **Phase 9 Blob** required before real images/documents (current CDN example URLs are placeholders).
5. Manual **mobile + a11y** pass on public Product page.
6. Staging must run with **live API only** (mock fallback already disabled for production builds).
7. Consider a shared sqlsrv helper to detect/prevent duplicate named parameters in repositories.

---

## 12. Explicitly out of scope (not started)

- Azure Blob upload / SAS / virus scan / thumbnails  
- ERP integration  
- Azure AI Search  
- Full legacy catalogue migration  
- Admin UI redesign  

---

## Next step (requires approval)

**Phase 9 — Azure Blob Storage and Product File Management**

Secure upload initiation, SAS or backend-mediated uploads, MIME/extension/size validation, virus-scanning strategy, image metadata + thumbnails, product image/PDF/360/AR uploads, replace/delete workflows.

**Stop here until Phase 9 is approved.**
