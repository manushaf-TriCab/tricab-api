# Phase 12 — Legacy Product Import and Migration Engine

## Status

**Step 1 (Source Inspection): Complete** — inventory below.  
**Steps 2–35 (Implementation): Implemented** — engine, APIs, Admin UI, fixture dry-run/pilot path. **No production migration executed. No auto-publish.**

**Pilot approval:** Approved 2026-08-06. Proceed to **Phase 12.5 — Live Catalogue Readiness** (not full production migration).

---

## 1. Inspection report

### Dual-source Product model (Confirmed)

| Layer | System | Role |
|-------|--------|------|
| Identity / marketing | WordPress DB `tricab` (`wp_*`) + ACF | Product pages, titles, slugs, categories, images, certificates metadata |
| Variants / commerce | MySQL business DB `tc` | SKU rows (`tpro` / `tpro_ext`), accessory specs, cable specs, price, stock |
| Rich HTML | Filesystem `access-topinfo/{style}.php` | Style-keyed marketing partials |
| Static files | `/thumbs/`, `wp-content/uploads/`, datasheets, certificates | Images, PDFs, installation guides |

**Join key (Confirmed):** ACF `style_code` (string) links WordPress CPT rows to `tc` rows and filesystem assets. **There is no database FK** between WordPress and `tc`.

**Live row counts:** `PENDING_LIVE_INVENTORY` — read-only credentials were not available in prior discovery; do not invent volumes.

Evidence bases: `tc-web/legacy-tricab-website-investigation.md`, `tc-web/docs/legacy-to-new-mapping.md`, `tc-web/docs/wordpress-*`, `tc-web/docs/acf-field-inventory.md`, `tc-web/docs/database.md`, theme `classes/data/product.php`, `AccSpecs.php`, `ProductConfigurator.php`, Phase 1 Product schema `[migration].[LegacyProductMappings]`.

---

## 2. Source inventory

### 2.1 WordPress (`tricab`, prefix `wp_`)

| Source system | Source table / path | Record type | Primary identifier | Product identity field | Variant identity field | Style-code field | File references | Relationship fields | Expected target | Confidence |
|---------------|---------------------|-------------|--------------------|------------------------|------------------------|------------------|-----------------|---------------------|-----------------|------------|
| wordpress | `wp_posts` (`post_type=cable`) | Product (marketing) | `ID` | `ID` + `post_name` | — (variants in `tc`) | ACF `style_code` via postmeta | Featured / ACF image attachment IDs | `wp_term_relationships`; accessory refs in theme/meta | `[product].[Products]` Draft + slugs/SEO | Confirmed |
| wordpress | `wp_posts` (`post_type=accessory`) | Product (marketing) | `ID` | `ID` + `post_name` | — | ACF `style_code` | ACF `section_1` / `section_2` images | Category terms | `[product].[Products]` Draft | Confirmed |
| wordpress | `wp_posts` (`post_type=certificate`) | Document / product-linked cert | `ID` | via ACF `styles` CSV of style codes | — | `styles` (CSV) | ACF `certificate_upload`, `certificate_thumbnail` | styles → products by style_code | `[product].[ProductDocuments]` (+ media) | Confirmed structure; **ambiguous** whether always document-only |
| wordpress | `wp_posts` (`post_type=attachment`) | Media/document binary meta | `ID` | via `post_parent` or ACF refs | — | indirect | `_wp_attached_file`, `guid`, `_wp_attachment_metadata` | `post_parent` | Phase 9 Blob + `ProductMedia` / `ProductDocuments` | Confirmed |
| wordpress | `wp_postmeta` | ACF / meta values | `(post_id, meta_key)` | parent post | — | `style_code`, `industry_code` | image/file meta values = attachment IDs | serialized checkboxes | Specs / descriptions / media links | Confirmed |
| wordpress | `wp_terms` + `wp_term_taxonomy` + `wp_term_relationships` | Classification | `term_id` / `term_taxonomy_id` | — | — | — | — | `object_id` → post | Map to Family/Category/Series (**manual mapping only**) | Confirmed taxonomy=`category` for CPTs |
| wordpress | `wp_termmeta` | Category display ACF | `(term_id, meta_key)` | — | — | — | — | — | Regional/category content helpers | Confirmed |
| wordpress | ACF field groups (code) | Field definitions | field key `field_*` | — | — | defines `style_code` etc. | — | — | Informs SpecificationFieldMappings | Confirmed (84 fields in 4 groups) |

**CPT rewrite slugs (Confirmed):** `/cable/{slug}/`, `/accessory/{slug}/`, `/certificate/{slug}/`.

**Region behaviour (Confirmed):** `?rgn=` query / session — prefer one Product → multiple regions, not duplicate Products, unless genuinely different products (unresolved conflicts → manual review).

### 2.2 Legacy `tc` database

| Source system | Source table / path | Record type | Primary identifier | Product identity field | Variant identity field | Style-code field | File references | Relationship fields | Expected target | Confidence |
|---------------|---------------------|-------------|--------------------|------------------------|------------------------|------------------|-----------------|---------------------|-----------------|------------|
| tc | `tpro` | Variant / SKU master | `ida` (product code) | style prefix / WP style_code | `ida` | `style` / substr of code | image conventions by code | joins to price, stock | `[product].[ProductVariants]` + codes/SKUs | Confirmed usage; **full column inventory PENDING_LIVE** |
| tc | `tpro_a` / `tpro_ext*` | Variant extensions / staging | code joins | style | product_code / ida | `style` | — | tpro joins | Variant attributes | Confirmed referenced; schema PENDING_LIVE |
| tc | `accessory_specs` | Accessory variant specs | `product_code` (+ style) | style_code | `product_code` | style (3-char typical) | — | joined in ProductConfigurator | Variant specification values | Confirmed columns via AccSpecs.php |
| tc | `cable_specs` / `cable_specs_dev` | Cable technical rows | composite (`cable_style`, `nc`, `xa`, `ct`, …) | `cable_style` | composite | `cable_style` | — | joined to tpro_ext | Variant specification values | Confirmed usage in theme SQL |
| tc | `spec_labels` | Spec column labels by style | `style` | style | — | `style` | — | — | Display metadata / mapping hints | Confirmed |
| tc | `style_specs` / `rating` / `pr_style_prop` | Style-level ratings | style-keyed | style | — | style | — | — | Product-level specs (after mapping) | Likely; PENDING_LIVE |
| tc | `descript` | Lookup codes (styles, markets, …) | code | — | — | style names | — | — | Classification / market mapping aids | Confirmed referenced |
| tc | `price` | Pricing by code + debtor | `(code, dbco)` | — | `code` | — | — | — | **Out of scope** (customer pricing) unless separately approved | Confirmed defer |
| tc | `stock_inventory` / `tstkwh_p` / `pssf` | Stock | sku/warehouse | — | stk / ida | — | — | — | **Out of scope** for Phase 12 product master | Confirmed defer |
| tc | `product_rules` / `rules` / `product_detail` / `product_sort` | Configurator | varies | style | — | style | — | — | Manual review / later coding rules | Confirmed referenced; ambiguous target |
| tc | Quote tables `tqoth`/`tqoti` | Quote history | — | — | — | — | — | — | **Out of scope** | Confirmed |

**AccSpecs columns (Confirmed from AccSpecs.php):**  
`nom_conductor`, `min_cable`, `max_cable`, `imax`, `volts`, `tensile`, `torque`, `spool_len`, `weight`, `d1`–`d7`, `v1`–`v4`.

**tpro identity (Confirmed from theme SQL):** `ida` as product/SKU code; style often `substr(ida,1,3)` for accessories; cable style/core/conductor fields on `tpro` / `tpro_ext`.

### 2.3 Legacy files

| Source system | Source table / path | Record type | Primary identifier | Product identity field | Variant identity field | Style-code field | File references | Relationship fields | Expected target | Confidence |
|---------------|---------------------|-------------|--------------------|------------------------|------------------------|------------------|-----------------|---------------------|-----------------|------------|
| files | `wp-content/uploads/` | WP media files | relative path / attachment ID | via attachment meta | — | indirect | path on disk | — | Azure Blob via Phase 9 | Confirmed |
| files | `/thumbs/` | Product images, datasheets, installation PDFs | filename | style/code in filename | optional SKU in name | `{style}` / `{style}-gr.jpg` | path | — | Media/Documents + Blob | Confirmed conventions |
| files | `/thumbs/data_sheet/` | Datasheets | filename | style map | — | style | PDF | — | Document type Datasheet | Confirmed |
| files | `/thumbs/installation/TCIM-ACC-{style}.pdf` | Installation guides | style | style | — | style | PDF | — | InstallationGuide | Confirmed pattern |
| files | `/thumbs/certificates/` (+ ACF uploads) | Certificates | filename / attachment | styles CSV | — | styles | PDF | — | Certificate | Confirmed |
| files | `access-topinfo/{style}.php` | Regional/marketing HTML | style filename | style | — | style | may embed image paths | — | ProductRegionalContent / fullDescription | Confirmed |
| files | Theme filesystem `{style_code}-gr.jpg` conventions | Image not always in WP library | path | style_code | — | style_code | path | — | Media; missing → warning | Confirmed |

---

## 3. Confirmed join rules

1. **WP cable/accessory → tc variants:** `wp_postmeta.style_code` = string match to `tc` style (`tpro.style`, `cable_specs.cable_style`, accessory style prefix / `accessory_specs`).
2. **WP certificate → products:** ACF `styles` CSV of style codes → products sharing those styles (many-to-many documents).
3. **Files → products:** filename / folder conventions keyed by `style_code` or product code; WP attachments via meta attachment ID → `_wp_attached_file`.
4. **Categories → classification:** `wp_term_relationships` on CPT → `category` taxonomy tree; **must map manually** to Family/Category/Series (no auto-create).
5. **Identity matching order (engine rule):** LegacyProductMappings → exact source → exact style → exact product code → exact SKU → exact normalized slug → approved composite → manual review. **Never name-only.**

---

## 4. Ambiguous areas (stop and report — no silent decisions)

| Area | Ambiguity | Required action |
|------|-----------|-----------------|
| Certificate CPT | Document-only vs product entity | Manual review / mapping |
| `industry_code` vs `style_code` | Secondary identity; may collide | Prefer style_code; industry_code as secondary key only with approved rule |
| Regional duplicate WP pages | Same product vs different products | Prefer one Product + regions; conflicts → review |
| `tpro` vs `tpro_ext` vs generated mask codes | Which row is authoritative SKU | Validate on live sample; do not guess |
| Accessory series letter → Family/Category | Theme `$seriesTitles` is display, not Product Family | ClassificationMappings UI |
| Spec fields `d1`–`d7`, `v1`–`v4` | Meaning varies by style via `spec_labels` | Per-style SpecificationFieldMappings; unknown → review |
| Ambiguous numeric text (`approx 25mm`, `n/a`) | Cannot coerce | ManualReview / BlockingError |
| Duplicate product codes / slugs | Uniqueness | Report Conflict; **never** auto-suffix |
| Missing thumbs / uploads | File absent | Warning + traceable issue; no fake Blob |
| Live column lists / row counts | Not queried | `PENDING_LIVE_INVENTORY` before production extract |
| Price / quotes / stock | Commerce history | Out of scope Phase 12 |

---

## 5. Source-to-target mapping (summary)

| Legacy source | Target | Transformation | Validation | Manual review when |
|---------------|--------|----------------|------------|--------------------|
| WP cable/accessory post | Product (Draft) | CanonicalProduct from title/slug/ACF | Required style_code; slug unique | Missing style; ambiguous match |
| WP categories | Family/Category/Series | ClassificationMappings only | Mapping status=Mapped | Unmapped term |
| ACF / cable_specs / accessory_specs fields | Specification values | SpecificationFieldMappings + transformer | Typed rules; no silent coerce | Unknown field/option; ambiguous value |
| Option text (e.g. T/C) | SpecificationOption | Option aliases | Known alias only | Unknown option (no auto-create) |
| `tpro.ida` / SKU | ProductCode / Variant SKU | Preserve; normalize only by approved rules | Uniqueness | Duplicate / invalid |
| `post_name` + CPT path | ProductSlug + ProductRedirect | Prefer preserve slug; 301 if path changes | Unique slug/region | Conflict |
| Attachments / thumbs | ProductMedia / Documents + Blob | Phase 9 upload; checksum; HEAD verify | Source exists; malware scan policy | Missing file; ambiguous doc type |
| Accessory / related refs | ProductAccessories / RelatedProducts | After both ends resolved | No self/dupes | Unresolved target |
| Certificates | Documents (Certificate) | Classify by ACF + path; not extension alone | Type mapping | Ambiguous type |

---

## 6. Existing migration foundation (Phase 1)

| Object | Status |
|--------|--------|
| Schema `[migration]` | Exists |
| `[migration].[LegacyProductMappings]` | Exists (SourceSystemCode, SourceProductKey, ProductId, ProductVariantId, MigrationStatusCode, …) |
| `LegacyProductMappingRepository` | Contract + Azure SQL + InMemory |
| Permission `PRODUCT.MIGRATION.MANAGE` | Seeded + DevelopmentUserManagementCatalog |
| Batch / raw staging / issues | **Not yet** (planned Steps 2–3) |

---

## 7. Recommended pilot (Step 34 — dry-run only until approved)

One WordPress Product (e.g. style `FT` or accessory `J09`) + several `tc` variants + specs + primary image + one document + one region + one accessory relationship + slug/redirect.  
**Do not run full catalogue. Do not publish. Do not use production credentials in automated tests.**

---

## 8. Deferred work

- Full production migration execution  
- Customer pricing migration  
- ERP integration  
- Azure AI Search  
- WordPress retirement  
- Production deployment  
- Destructive deletion of legacy data  

---

## 9. Implementation checklist (post-approval)

Steps 2–33 and 35 from the Phase 12 brief: batch management, raw staging, canonical models, identity, classification, specs, aliases, codes, slugs, regions, media/docs (Phase 9 Blob), relationships, dependency order, dry-run, idempotency, manual review, execute, reconcile, rollback, Draft publication strategy, Admin UI, APIs (`PRODUCT.MIGRATION.MANAGE`), connectors, services, transactions, audit, performance, tests, documentation updates with dry-run/pilot reports.

---

*Inventory complete. Waiting for approval before continuing migration engine implementation or any pilot execute against non-fixture sources.*

---

## 10. Implementation reuse notes (from Phase 11 patterns)

Captured for Steps 2–35 after inventory approval. Do not treat as authorization to run production migration.

| Concern | Reuse |
|---------|--------|
| DI | `ContainerFactory.php` — `\DI\autowire` services/actions; flip AzureSql vs InMemory repos |
| Draft products | `CreateProductCommand` / `CreateProductRequest` default `statusCode=draft`, `lifecycleStatusCode=concept` |
| Audit | `ProductAuditRecorder` → `[product].[ProductAuditEvents]`; use `sourceCode: 'migration'`; mask credentials/SAS |
| Blob copy | `ProductBlobStore::copyObject` (Phase 9 port; upload services use put/head — migration should call copy explicitly) |
| Routes | Mirror specification admin block in `AppBuilder.php`; gate with `PRODUCT.MIGRATION.MANAGE` |
| Transactions | Nest writes in `ApplicationTransaction` / `AzureSqlTransaction` (nested-safe); one Product aggregate per txn |
| Legacy mappings | Extend `LegacyProductMappingRepository` + InMemory store key pattern |
| Permission | Already in `DevelopmentUserManagementCatalog` + seed script |

Current `[migration]` tables before Phase 12 engine: `LegacyProductMappings`, `LegacyCustomerUserMappings` only.

---

## 11. Implementation report

### Schema
- Script: `database/scripts/development/create-product-migration-schema.sql`
- Tables: MigrationBatches, Legacy*Raw staging, CanonicalProducts, ClassificationMappings, SpecificationFieldMappings, SpecificationOptionAliases, MigrationIssues, MigrationReconciliationReports, MigrationAuditEvents
- Extended LegacyProductMappings with batch/hash/entity columns when absent

### Core
- Canonical models under `Product/Migration/Canonical`
- Identity: `ProductIdentityMatcher`, `ProductVariantClassifier` (no name-only matching)
- Transform: `SpecificationValueTransformer` (rejects ambiguous values)
- Mapping: `OptionAliasResolver`
- Sources: Fixture + WordPress/tc/files connector abstractions (read-only; Phase 12 uses fixture for tests/pilot)
- Orchestrator: `ProductMigrationService` (extract → normalize → validate → dry-run → execute → cancel/retry/rollback → reconcile → review/mappings)
- Dry-run: `wouldWriteProducts=false`, `wouldWriteBlobs=false`
- Execute: Draft only; idempotent via source hash mappings; rollback protects manually edited rows

### API (`PRODUCT.MIGRATION.MANAGE`)
- `/api/v1/admin/product-migration/batches` (+ extract/normalize/validate/dry-run/execute/cancel/retry/rollback/issues/reconciliation)
- `/api/v1/admin/product-migration/review` (+ resolve)
- `/api/v1/admin/product-migration/mappings/{classifications|specifications|options}`

### Admin UI
- `/admin/products/migration`, `/batches/:id`, `/review`, `/mappings`
- Gated by `PRODUCT.MIGRATION.MANAGE`

### Pilot (fixture only)
- Style `FT` WordPress cable + tc variants + OD spec + image + datasheet + accessory relationship + classification term
- Dry-run report downloadable as JSON (CSV via `?format=csv`)
- Publication default verified: `draft`

### Deferred
- Full production migration, live WP/tc credentials, Azure SQL staging repository (schema ready; in-memory used for Phase 12 runtime), pricing/ERP/AI Search/WordPress retirement/production deploy

---

## 12. Pilot result (fixture — executed)

**Ran:** 2026-08-06T00:27:05+00:00  
**Environment:** non-production fixture + in-memory target  
**Production migration executed:** `False`

### Dry-run
- No Product/Blob writes: `True`
- Products discovered: **1**
- Variants discovered: **2**
- Needing review: **2**
- Missing files: **0**
- Orphan relationships: **1**
- Blocking errors: **0**

Full JSON: `docs/product-phase12-pilot-ft-report.json`

### Execute (in-memory only)
- Batch status: **completed**
- Imported: **1**
- All Draft: **True**
- Publication default: **draft**

### Comparison
| Legacy | New |
|--------|-----|
| `/cable/flexi-tuff/` | Admin Draft from `wordpress:wp_posts:3812` |
| Style `FT`, 2 variants | Variants staged under Product |
| `/cable/flexi-tuff/` | Redirect to `/products/flexi-tuff` (not live-published) |

### Still waiting
Full catalogue / live WordPress+tc migration requires live read-only credentials and a separate explicit approval. Not run.

---

## 13. Pilot approval

Approved. Fixture pilot accepted. Full live catalogue import remains blocked until Phase 12.5 readiness gates pass and a separate execute approval is given.

