# Pre-Product Migration — Legacy Catalogue & Specification Master Discovery

**Status:** DISCOVERY COMPLETE — **NOT READY** for full Product catalogue migration.  
**Date:** 2026-08-20  
**Mode:** Read-only (WordPress + tc via `LegacyReadOnlySqlGuard`). No legacy writes. No master-data creates. No Product migration executed.

Pricing migration work is **stopped**. This phase does not import prices.

Live run: `php bin/discover-legacy-master-data.php`  
Queries: **163** · Rows inspected via aggregations (not per-product) · **2.676s** · `legacyWrites=0`

Proposed mapping artifacts (review only, not applied):

- `core/docs/pre-migration-mappings/discovery-report.json`
- `family-mappings.proposed.json`
- `category-mappings.proposed.json`
- `series-mappings.proposed.json`
- `specification-definitions.proposed.json`
- `tpro-vs-tpro-ext.proposed.json`

---

## 1. Inspection of existing Phase 12 infrastructure

Reused. No second migration engine was built.

| Capability | Existing | Decision |
|------------|----------|----------|
| Live WP/tc read-only | `MysqlLegacyWordPressRepository`, `MysqlLegacyTcRepository`, SQL guard | **EXTEND** with batched discover* methods |
| Inspect API | `GET /product-migration/inspect` | **KEEP** |
| Master discovery | New `DiscoverLegacyMasterDataService` | **ADD** on existing inspect path |
| Classification mappings | `migration.ClassificationMappings` | **REUSE** — do not auto-seed |
| Spec field mappings | `migration.SpecificationFieldMappings` | **REUSE** |
| Option aliases | `migration` option alias tables | **REUSE** |
| Product identity | `LegacyProductMappings` (100 style-level rows) | **KEEP** — not variant pricing identity |
| Admin UI | Product Migration page | **EXTEND** with Discovery tab |
| Family/Category/Series | Admin classification services | **COMPARE ONLY** |
| Catalogue navigation | Separate from classification | **PRESERVE** — do not merge trees |

---

## 2. WordPress Product structure

Live `wp_posts` inventory (all statuses):

| post_type | total | Product-related? |
|-----------|------:|------------------|
| attachment | 1,967 | Media/documents |
| revision | 408 | Ignore |
| **certificate** | **290** | Documents / approvals (not Products) |
| **cable** | **232** | **Yes — Product** |
| **accessory** | **216** | **Yes — Product** |
| page | 171 | No |
| nav_menu_item | 132 | Customer navigation |
| slider / matrix_logos / post | 24 | No |

**Product-related CPTs found:** `cable`, `accessory`, `certificate`, `attachment`. No additional product CPT (no `product` post type).

Publishable marketing products ≈ **448** (`cable` + `accessory`). Distinct ACF `style_code` values: **396**.

---

## 3. WordPress taxonomy

**207** term usages on cable/accessory/certificate posts. Taxonomy in use: **`category`** (no separate product_cat).

This tree is **not** Family → Category → Series.

Observed mixes:

- Certificate regions: NZ (147), AU (142), US (108) — **document/region**, not Product Family
- Accessory merchandising: PowerSure, Industrial, Cable Ties, Lugs, Heat Shrink
- Cable filters: voltage (110V, 450/750V, 0.6/1kV), Flexible Cables, Construction/Building
- HTML-encoded names (`Conduits &amp; Fittings`)

**Recommendation (do not auto-map):**

| Legacy taxonomy use | Proposed target | Status |
|---------------------|-----------------|--------|
| `post_type=cable` | Product Family **Cables** (`CABLE`) | EXISTING_MATCH |
| `post_type=accessory` | Product Family **Accessories** | NEW_REQUIRED |
| Voltage / construction terms | Specs or catalogue facets | AMBIGUOUS |
| Accessory shop categories | Catalogue nodes and/or Categories | MERGE_REVIEW |
| AU/NZ/US on certificates | Regions + ProductDocuments | NOT Family |
| `News`, `Kennards Store` | IGNORE / obsolete | OBSOLETE_REVIEW |

Naïve parent=0 → Family scoring (11 roots, 1 existing match) is **invalid** and must not be applied.

---

## 4. ACF / `wp_postmeta` inventory

**62** public meta keys on product-related posts. Highest usage:

| meta_key | products | inferred | candidate |
|----------|--------:|----------|-----------|
| standards | 490 | empty/unknown | STANDARDS |
| style_code | 440 | text | identity / Series |
| small_desc | 440 | text | short description |
| sub_title | 440 | text | product subtitle |
| search_keywords | 391 | | search |
| certificate_upload | 287 | number (attachment id) | ProductDocuments |
| styles | 287 | CSV style codes | certificate → products |
| issuing_organisation | 287 | text | Approvals / document meta |
| industry_code | 230 | | secondary identity — do not replace style_code |
| construction_content / standards_content / compliance_content | 53 | HTML | product-level long text |
| *-image-* / section_1 / section_2 | 210–213 | | media |
| degrees / kilovolts / hcl_emission / oxygen_index | 90 | | thermal / electrical / compliance |

No ACF key named `family` or `series`. Series is **`style_code`**.

---

## 5. tc Product tables

**54** product-related tables (including backups). Authoritative live tables:

| table | rows | PK | Product key | Variant key |
|-------|-----:|----|-------------|-------------|
| **tpro** | 92,620 | `id` | none (commerce SKU) | **`ida`** |
| **tpro_ext** | 18,995 | `product_code,style` | **`style`** | **`product_code`** |
| **cable_specs** | 9,820 | `my_row_id` | `cable_style` | composite nc/xa/ct |
| **accessory_specs** | 2,130 | `product_code` | 3-char prefix of code | `product_code` |
| **spec_labels** | 1,064 | `style,dimension` | `style` | — |
| **descript** | 5,209 | `desc_id` | lookup | — |
| **rating** | 557 | `id` | style-level | — |
| **product_sort / product_detail / product_rules** | small | | configurator | later |

Backups (`tpro_backup`, `cable_specs_dev`, `updated_cable_data_*`, etc.) are **out of scope**.

---

## 6. tpro vs tpro_ext authority

**Do not choose one table globally.**

Join used after live DESCRIBE (not `id`):

```text
tpro.ida = tpro_ext.product_code
```

| Metric | Count |
|--------|------:|
| tpro rows | 92,620 |
| tpro_ext rows | 18,995 |
| matched on ida = product_code | 11,383 |
| tpro only | 81,247 |
| tpro_ext only | 8,276 |

| Field | tpro coverage (matched) | tpro_ext coverage | conflicts | recommended authority |
| ----- | ----------------------: | ----------------: | --------: | --------------------- |
| SKU / item code | `ida` | `product_code` | n/a | **tpro.ida** (identity) |
| Style | *(no style column)* | `style` | — | **tpro_ext.style** |
| Sheath / cores / conductor | — | sheath, no_core, core, conductor_type, config_core | — | **tpro_ext** |
| colour | `col` 974 | `colour` 11,327 | 974 | **tpro_ext.colour** (review conflicts) |
| weight | `uwt` 1,048 | `weight` 7,946 | 904 | **tpro_ext.weight** for construction; `uwt` may be commerce unit weight |
| description | `nm` 11,383 | `desc2` 10,142 | 10,260 | **WordPress** marketing; tpro.nm commerce; desc2 technical — **AMBIGUOUS** |
| braid/armour | `br` 1 | `armour` 11,383 | 1 | **tpro_ext.armour** |
| price/stock | cprc, stka, … | — | — | **tpro** (out of Product master / pricing later) |

~81k tpro-only rows are SKU/commerce history (including accessories and unused codes), not 81k extra Products.

---

## 7. WordPress ↔ tc join (`style_code`)

**Confirmed join key:** ACF `style_code` ↔ `tpro_ext.style` / `cable_specs.cable_style` / accessory `product_code` prefix. No FK.

### Cable-oriented join (tpro_ext.style ∪ cable_specs.cable_style)

| | Count |
|--|------:|
| WordPress style codes | 396 |
| tc style codes (ext + cable_specs) | 324 |
| Exact matches | 181 |
| WordPress-only | 215 |
| tc-only | 143 |
| Case-only differences | 0 |
| Trailing/leading whitespace | 0 |
| Match % (WP → those tc lists) | **45.7%** |

WordPress-only includes:

- Accessory codes `A01`…`G15` (expected in `accessory_specs` / `tpro.ida`, not always `tpro_ext.style`)
- Invalid identity: `B3C, B3M`, `M3C, M3M` (comma-separated multi-codes)
- `TC12`, PowerSure `PS1A`…

tc-only includes numeric `cable_specs.cable_style` values (`1`,`10`,`11`) which are **not** WP style codes.

### Earlier 3-char `tpro.ida` prefix join (rejected as identity)

~**95.2%** WP match because almost every 3-character WP code appears as an `ida` prefix — including junk prefixes (`.`, `??1`, `100`). **Do not use `LEFT(ida,3)` as Product identity.**

**Proposed normalization (matching only, do not persist mutated values):** `UPPER(TRIM(style_code))`. Split comma lists into **AMBIGUOUS** review rows. Restrict cable join to `tpro_ext.style` and accessory join to `accessory_specs`.

---

## 8. Product Families

| Proposed Family | Legacy source | Existing | Status |
|-----------------|---------------|----------|--------|
| Cables | `post_type=cable` (232) | `CABLE` / Cables | **EXISTING_MATCH** |
| Accessories | `post_type=accessory` (216) | none | **NEW_REQUIRED** |
| Plugs & Sockets | WP accessory subtree + existing `PLUG` | `PLUG` | EXISTING_MATCH (subset of Accessories — **AMBIGUOUS hierarchy**) |

WP taxonomy roots (`News`, `Kennards Store`, `Tape`) are **not** Families.

Azure today: 2 families (`CABLE`, `PLUG`). `PLUG` unused-or-narrow vs live accessory catalogue.

---

## 9. Categories

Azure: 7 categories (Flexible Power, Control Multicore, VFD, Instrumentation, Single Insulated, Industrial Plugs/Sockets).

WP: **196** child `category` terms. Auto-match **0.5%**. Duplicates: two `110V` terms under different parents; `Flexible Instrumentation…` name collisions; HTML entities.

**Do not force** voltage or certificate terms into a Family. Operator mapping required (`ClassificationMappings`).

---

## 10. Product Series

**Recommendation:** one Series per valid `style_code` (e.g. `BVA`, `FT`, `J09`).

| | |
|--|--|
| WP style codes | 396 |
| Azure series | 2 (`TC-FLEX`, `J-SERIES`) |
| EXISTING_MATCH | 0 |
| NEW_REQUIRED | ~396 minus invalid comma-codes |

Pilot mappings already exist as Products (`BVA`, `DFA`, `RRA`, …) at Product level, **not** as `ProductSeries` rows.

---

## 11–13. Catalogue navigation (separate)

Internal: Family → Category → Series → Product.  
Customer: Catalogue Tab → Node → placements.

**Do not collapse these.**

Legacy customer tree evidence:

- tricab-web `PRODUCT_CATEGORIES` (Accessories, ALI-FLEX, Marine IEC LSZH, …)
- WP menus: `Primary` (5 items), `share` (126 items) — not the product panel
- Existing CatalogueTabs/Nodes: Azure query not fully captured this run (`SpecificationUnits` missing aborted later keys). **Do not delete** existing navigation.

Classification vs navigation:

| Node action | Rule |
|-------------|------|
| KEEP | All current CatalogueTabs/Nodes until operator review |
| ADD | Missing panel categories (Solar, VFD, Marine, …) after mapping |
| MERGE_REVIEW | Accessories vs Plugs & Sockets |
| UPDATE | Names/images vs live WP |
| OBSOLETE_REVIEW | None automatically |

---

## 14. Catalogue images

| Source | Finding |
|--------|---------|
| WP attachments on cables | 953 files / 215 products |
| Accessories | 38 / 13 products |
| Certificates | 385 / 279 posts |
| `LEGACY_FILES_ROOT` | Waived / empty — filesystem existence **not** verified |
| Blob upload | Not performed |

Recommended source later: WP `_wp_attached_file` + `/thumbs/{style}-gr.jpg` conventions from Phase 12. Missing-file counts require files root.

---

## 15–17. Specification universe, groups, definitions

Azure Specification Groups (use these first): Electrical, Mechanical, Construction, Compliance, Packaging, Thermal.

Azure already has **37** definitions including Voltage, Current, cores, CSA, sheath, STANDARD, APPROVALS, CORE-COLOURS, OD/weight variant measures, jacket options.

WP meta vs definitions: **62** keys → 2 EXISTING_DEFINITION, 24 NEW_DEFINITION_REQUIRED, 36 AMBIGUOUS (discovery heuristic only).

tc structural specs (variant-level cables): `cable_specs` columns `nc, xa, od, wght, A15c…, core_con, br, cdia, ins, …`.

Accessory specs: `nom_conductor, min_cable, max_cable, imax, volts, d1–d7, v1–v4, weight, tensile, torque`.

---

## 18. d1–d7 / v1–v4

**Meaning changes by style.** `spec_labels` (1,064 rows) is the label authority. Not globally `d1=diameter`.

| Field | Distinct styles | Example labels (not unique) |
|-------|----------------:|-----------------------------|
| d1 | 184 | Thread OD mm, Pins, Current Rating A, Width mm |
| d2 | 179 | Length mm, Rating Amp, Thread Length |
| d3 | 129 | Voltage V, Barrel mm, Length m |
| d4 | 87 | Phases, Setback mm, Body (H) |
| d5 | 56 | Width, Length (L), Thickness |
| d6 | 31 | Barrel Ø mm, Inner Ø |
| d7 | 13 | Ød mm, Weight kg/unit, Max Cu mm² |
| v1 | 55 | Phase, Cable Colour, Crimp Die, Thread Size |
| v2 | 16 | Parent Connector, Contact, Voltage |
| v3 | 12 | Colour |
| v4 | 2 | Conductor mm² |

Migration must use **per-style SpecificationFieldMappings**. Unknown style+dimension → review. Never assume one definition per `d*`.

---

## 19. Units

Discovered in labels/content: mm, mm², kg, kg/km, g, A, Amp, V, ° (degrees), kV, m, ft lbs, kgf.

Azure `product.SpecificationUnits` **does not exist** on this database (query failed). Phase 2C pricing UOM is a **different** concept even if a shared table is added later.

**UNIT_UNMAPPED** until SpecificationUnits is applied/seeded.

---

## 20–23. Options, Standards, Approvals, Core Colours

- **Standards:** WP `standards` + `standards_content` (490 + 53). Unique canonical list not exploded this run (serialized/HTML). Requires a dedicated parse pass. Existing definition `STANDARD` (multi-select, product).
- **Approvals:** Certificate CPT `issuing_organisation` (287) is the live approval org list. Existing `APPROVALS` multi-select. Aliases (DNV-GL vs DNVGL) **not merged**.
- **Certificates:** CPT is **shared documents** keyed by ACF `styles` CSV → many Products. Also attachment files. Combination: ProductDocuments + Approvals options, **not** standalone Products.
- **Core Colours:** Azure `CORE-COLOURS` is **long-text, product scope** — can preserve `1C: BK` / `2C: BR BL` without the UI editor. `CODE_CORE_COLOUR` is single-select variant — **cannot** hold full matrices. Do not simplify combinations. tc `cable_specs.core_con` / `tpro_ext.spec_core_config` need a value dump in the next fix/seed phase.

---

## 24. Product vs Variant specifications

| Scope | Evidence | Examples |
|-------|----------|----------|
| PRODUCT_LEVEL | One WP post per style; construction HTML; standards; certificates | construction_content, STANDARDS, APPROVALS, jacket/sheath type, style_code |
| VARIANT_LEVEL | Many `tpro.ida` / `cable_specs` rows per style | nc, xa, od, weight, current (A15c), accessory d1–d7 |
| CONDITIONAL | spec_labels per style | d1–d7, v1–v4 |
| UNKNOWN | industry_code vs style_code; tpro.nm vs WP title | review |

---

## 25. Variant inventory

Using **`tpro_ext` grouped by style** (not `LEFT(ida,3)`):

| | |
|--|--:|
| Styles | 301 |
| Variant rows | 18,995 |
| Max variants on one style | 1,302 |
| 1 variant | 36 |
| 2–10 | 94 |
| 11–50 | 76 |
| >50 | 95 |

`tpro` has **92,620** `ida` SKUs; many will not become ProductVariants (commerce-only / unmatched).

Accessory variants: **2,130** `accessory_specs` rows.

---

## 26. Code / slug quality

| Issue | Action |
|-------|--------|
| Comma style_codes (`B3C, B3M`) | INVALID — split or manual map; never auto-suffix |
| Numeric cable_style (`1`,`10`) | Not WP identity |
| Duplicate WP titles / instrumentation names | Match on style_code only |
| Case/whitespace | None material |
| Azure InternalCode uniqueness | Pilot styles already used as InternalCode |

Deterministic matching: mapping table → exact style → exact ida → slug. **Never name-only. Never auto-suffix.**

---

## 27–28. Media and documents

| | Count |
|--|------:|
| Cable attachments | 953 |
| Accessory attachments | 38 |
| Certificate attachments | 385 |
| File existence | **Unknown** (files root waived) |
| Blob copies | 0 |

Certificate resolution: **Product documents + shared certificates + Approvals metadata**, not a Product CPT.

---

## 29. Existing Azure SQL Products

**118** Products. **Do not delete automatically.**

| Class | Count | Rule |
|-------|------:|------|
| MIGRATED (has LegacyProductMapping) | **100** | KEEP — Phase 12 pilots |
| DEVELOPMENT_SAMPLE (Phase85 / sample names) | **11** | KEEP until operator purge |
| UNKNOWN (`Dbg *` test rows) | **7** | KEEP / later OBSOLETE_REVIEW |

---

## 30–31. Reconciliation and coverage

Calculated from this live run (not invented):

| Area | Result |
|------|--------|
| WP cable+accessory posts | 448 |
| WP style codes | 396 |
| tc tpro_ext styles | 301 |
| WP ↔ tpro_ext/cable_specs exact | 181 (45.7% of WP codes) |
| Product identity (style, cables+accessories, mixed sources) | **NOT READY** |
| Family mapping (post_type rule) | Cables ready; Accessories NEW |
| Category mapping | **0.5%** auto |
| Series mapping | **0%** vs Azure series |
| Spec definition heuristic | **3.2%** of WP meta keys |
| Units table | missing |
| Catalogue placement | not measured (files/nav incomplete) |

---

## 32. Migration block statuses

Any ambiguous mapping stays a review issue. No silent master create (`LEGACY_MIGRATION_ALLOW_AUTO_*` already false).

---

## 33. Proposed mapping files

Written under `core/docs/pre-migration-mappings/`. **Not activated.**

---

## 34. Admin UI

Product Migration → **Discovery** tab calls `GET /api/v1/admin/product-migration/master-data-discovery` (`PRODUCT.MIGRATION.MANAGE`). Inspection only.

---

## 35. Performance

163 guarded SELECT/SHOW/DESCRIBE/INFORMATION_SCHEMA statements. Aggregations and IN batches. No 1-query-per-product loop. 2.7s.

---

## 36. Ambiguities / blockers / recommended next phase

**FULL PRODUCT MIGRATION: NOT READY**

Blocking issues:

1. WP `category` tree is not Family/Category/Series — operator classification mappings required.
2. Accessories Family missing; `style_code` join for accessories not the same as `tpro_ext.style`.
3. Invalid WP style_codes (comma lists) and numeric tc cable_style values.
4. d1–d7 / v1–v4 are style-specific — mappings must be per style.
5. `SpecificationUnits` not present on Azure SQL.
6. Core colour / standards / approvals unique-value audits not fully exploded (HTML/serialized).
7. Filesystem not mounted — media/document missing counts unknown.
8. 81k tpro-only SKUs must not become Products.
9. Existing 118 Azure Products include samples/debug — no automatic delete.
10. Catalogue navigation vs WP/panel tree not reconciled.

**Recommended next (after approval):** Pre-Migration Master Data Fix & Seed — Families/Categories/Series/options/units + reviewed mapping files only. Then a **dry-run** catalogue extract. Pricing migration stays stopped.
