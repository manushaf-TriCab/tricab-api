# Catalog-load → `product.*` source mapping

> **Superseded in direction, not in fact.** A decision on 2026-08-07 moves the
> system of record to a cable-native, per-type schema — see
> `product-cable-native-schema-design.md`. Everything below remains an accurate
> record of what is loaded into `product.*` today and how, and the loaders it
> describes become the projection into the new model rather than throwaway
> migration scripts.

## Status

**Inspection: complete.** Live `product.*` schema read 2026-08-07 against
`tricab-mel.database.windows.net/tc`; catalog-load read at
`D:\TriCab\Projects\catalog-load`.

**Seed: applied 2026-08-07.** `tricab-api/database/scripts/development/seed-product-cable-reference.sql`.
Idempotent — re-running produces identical counts. See §11.

**Construction tables: created 2026-08-07.** `tricab-api/database/scripts/development/create-product-construction-schema.sql`.
See §7.1.

**Marine loader: built 2026-08-07, not applied.** `catalog-load/build_azure_product_load.py`
→ `catalog-load/sql/30_azure_product_load.sql`. Verified against the live
database in a rolled-back transaction. See §12 step 3.

**No products migrated. No migration batch created.**

This extends the Phase 12 source inventory (§2.2), which was written before
catalog-load existed and therefore lists `tpro`, `tpro_ext`, `cable_specs` and
`descript` but none of the tables below.

---

## 1. The two databases

| | catalog-load target | `product.*` target |
|---|---|---|
| Engine | MySQL 8 | Azure SQL |
| Host | container `tricab-local` | `tricab-mel.database.windows.net` |
| Database | `tc` | `tc` |
| Grain | style and design | catalog page |

Same name, different databases — stated at `catalog-load/sql/01_schema.sql:46-47`
and `catalog-load/README.md:5-8`. Nothing here runs the MySQL DDL against Azure
SQL; this is a source-to-target mapping for the migration engine.

---

## 2. Grain decision

**One `[product].[Products]` row per `style_key`** — the style code plus its
optional sheath character (`BVA` = BV + A, `NVC` = NV + C, `C1` = LV style with
no sheath variant).

That is already the datasheet entity and already the primary key of
`style_header`. `style_construction` uses the same values in `cable_style`.

**Population: 227 distinct styles.** The CableBuilder header set
(`sql/10_cb_style_header.sql`, 227 rows) is a strict superset of the marine set
(`sql/02_style_header.sql`, 25 rows) — verified, the overlap is exactly 25 and
there are no marine-only keys. A further 398 style keys are deliberately
excluded as custom cables with numeric tails (`review/cb_excluded.csv`).

**`[product].[ProductVariants]` stays empty for now.** The design grain sits in
`cb_design`, which is keyed `(design_code, alternative, version)` while
`ProductVariants` has unique indexes on `VariantCode` and `Sku` only and no
column for alternative or version. `cb_design.style_code` also joins
`style_header.style_code`, *not* `style_key`, so there is no rule yet for
assigning a design to a sheath variant. See open question 2.

---

## 3. Field mapping — `style_header` → `Products`

Direct columns:

| Source | Target | Notes |
|---|---|---|
| `style_key` | `Products.InternalCode` | Unique where not deleted; the natural migration key |
| `style_code` | — | Component of `style_key`; also the `LegacyProductMappings.SourceProductKey` prefix |
| `sheath_code` | — | Component of `style_key`. Identity, not a specification |
| `title` | `Products.ProductName` | Not unique upstream — 15 distinct across the 25 marine rows. `ProductName` has no unique constraint, so this is safe |
| `title_ds` | `Products.ShortDescription` | Datasheet subtitle; distinct per style |
| `application` | `Products.FullDescription` | Prose paragraph |
| `source_file` | `LegacyProductMappings.SourceTable` | Provenance, not product content |
| `design_example` | **not migrated** | `README.md:106`: 20 of 25 are not real product codes. Must not become a `ProductCode` |

Specification columns:

| Source | Definition | Data type | Status |
|---|---|---|---|
| `voltage_code` | `VOLTAGE` | `text` (existing) | Mapped. Stored verbatim — see open question 1 |
| `temp_min_c` | `TEMP-RANGE` | `range` → `ValueMinDecimal` | Mapped. New definition |
| `temp_max_c` | `TEMP-RANGE` | `range` → `ValueMaxDecimal` | Mapped. Same definition, one row |
| `default_colour` | `DEFAULT-COLOUR` | `single-select` | Options seeded (14 codes). Held `needs-review` on provenance only — see open question 4 |

`TEMP-RANGE` uses the schema's `range` type, so both endpoints live in one
`ProductSpecificationValues` row rather than two definitions.

---

## 4. `standard` + `style_standard` → `STANDARD` multi-select

The seed changes the existing `STANDARD` definition from `text` to
`multi-select` and sets `IsFilterable`. This is the only pre-existing row the
seed modifies.

**Why it has to change.** `style_standard` holds 226 marine + 1229 CableBuilder
link rows — eight to ten standards per style. As a `text` definition the
`UNIQUE (ProductId, SpecificationDefinitionId)` constraint permits exactly one
row per product, which forces the standards back into a comma-joined string.
That string is the defect `sql/01_schema.sql:21-24` exists to undo: the source
already carries `'IEC 60332-3-22,  AS/NZS 3808'` with a double space. Filtering
also depends on it — "which cables meet IEC 60754-2" is the stated requirement.

**Shape:** one `ProductSpecificationValues` row per (product, STANDARD), plus N
`ProductSpecificationOptionValues` junction rows.

**21 options seeded** from the `standard` table in `sql/01_schema.sql`.
`OptionLabel` is the exact datasheet token, `OptionValue` the publication title,
`OptionCode` a slug where `/` becomes `_` so `IEC 60331-1/2` cannot collide with
the real standard `IEC 60332-1-2`.

**21 aliases seeded** into `migration.SpecificationOptionAliases` keyed on the
uppercased token, so every marine link row resolves on the first pass without
the engine auto-creating options (Phase 12 §5 rule 5).

### 4.1 Standards not yet in the option list

The marine load is clean: `review/standards_review.csv` is empty — all 25 marine
styles' standards resolve against the 21 seeded options.

The CableBuilder load is not. **24 distinct tokens across 275 link rows** do not
match (`review/cb_standards_unmatched.csv`). They split three ways, and the
distinction matters because two of them are safe and one is not:

**(a) Genuinely missing publications — need new options.** Largest first:

| Token | Link rows |
|---|---|
| `AS/NZS 5000.1` | 65 |
| `AS/NZS 3008.1` | 64 |
| `AS/NZS 5000.3` | 17 |
| `AS/NZS 3013` | 9 |
| `IEC 62930`, `AS/NZS 5033` | 5 each |
| `IEC 60092-354`, `AS/NZS 5000.2`, `VDE 0207/20`, `VDE 0250/602` | 2 each |
| `AS/NZS 1995`, `AS/NZS 4961`, `IEEE 45`, `IEEE 1202`, `IEEE 1580` | 1 each |

`AS/NZS 5000.1` is worth noting twice: it is the biggest single gap **and** it
is the value all 8 placeholder `STANDARD` rows in the live Azure database
already carry. It is clearly real and clearly needed.

**(b) Separator variants of an already-seeded token — safe as aliases:**

| Source token | Seeded option |
|---|---|
| `IEC 61034-1&2` (42 rows) | `IEC 61034-1/2` |

**(c) Look like variants but are not — do not collapse:**

| Source token | Superficially matches | Why not |
|---|---|---|
| `AS/NZS IEC 60332-1` (15) | `IEC 60332-1` | An AS/NZS adoption is a separate published document |
| `AS/NZS IEC 60754` (9), `AS/NZS IEC 60754-2` (2), `AS/NZS IEC 60332-3-22` (2), `AS/NZS IEC 61034-1&2` (3) | IEC originals | Same |
| `NEK TS 606` (14) | `NEK TS606 Ed6` | Source states no edition; the seed asserts Ed6 |
| `IEC 60331` (8) | `IEC 60331-1` / `-2` / `-21` | Unqualified parent, not a part |
| `IEC 60754-1&2` (2) | — | No combined `60754-1/2` option exists; the seed has the two parts separately |

**Triaged 2026-08-07.** `catalog-load/build_standards_disposition.py` writes
`review/cb_standards_disposition.csv`, and `build_azure_standards_seed.py` turns
its confirmed rows into SQL. Re-run the pair as titles land.

| Disposition | Tokens | Styles | State |
|---|---|---|---|
| `alias-existing` | 1 | 42 | **Applied** — `IEC 61034-1&2` → `IEC-61034-1_2` |
| `new-option` | 18 | 207 | Needs a confirmed title |
| `decision-needed` | 3 | 24 | Datasheet owner's call |
| `verify-source` | 2 | 2 | Probable source error |

The CSV carries both a `suggested_title` and a `confirmed_title` column. **Only
`confirmed_title` produces SQL** — the generator ignores suggestions entirely.
Group (c) above is encoded as `decision-needed` so it cannot be collapsed by
accident.

**This does not gate the whole remaining catalogue.** Measured across the 227
resolved styles: 87 clean with standards, 29 citing none at all, 111 blocked —
so **92 styles are clean and not yet loaded**, and the marine loader's approach
extends to them without any standards decision.

---

## 5. `application_spec` → `Applications`

Six boolean facets become seven `Applications` rows, linked per product through
`ProductApplications`.

| Source column | Application code |
|---|---|
| `marine` | `MARINE` |
| `fire` | `FIRE-RES` |
| `vfd` | `VFD` |
| `instrumentation` | `INSTRUMENTATION` |
| `control` | `CONTROL` |
| `telecom` | `TELECOM` |
| *(none)* | `OFFSHORE` |

**`OFFSHORE` is the gap this target closes for free.** The source distinguishes
shipboard from offshore throughout, and `application_spec` has no column for it;
`sql/05_application_spec_patch.sql` has the `ALTER TABLE` and seven `UPDATE`s
written but commented out at its foot precisely because MySQL needed a schema
change. Here it is one more row in a vocabulary table.

The styles the source marks offshore, from that commented block: `PJ`, `FL`,
`UP`, `HT`, `RB`, `FK`, `FZ`, `FE`, `NV`, `NR`, `NJ`, `NT`. The seed adds the
vocabulary only — **the per-style links remain unagreed** and are not seeded.

Minor upstream discrepancy worth correcting at some point: `catalog-load/README.md:113`
describes this as "the `ALTER` and the seven `UPDATE`s". There are twelve.

**Grain note:** `application_spec` is keyed on `style_code` (2 characters) while
Products are keyed on `style_key` (with sheath). One facet row therefore fans
out to every sheath variant of that style code. That is correct behaviour, not a
conflict, but the loader must expand rather than join 1:1.

---

## 6. `style_asset` → `ProductImages`

| Source | Target |
|---|---|
| `asset_type = 'photo'` | `ProductImages` + `MediaTypes.primary-image` |
| `asset_type = 'section'` | `ProductImages` + `MediaTypes.cross-section` |

Both media types already exist. The source holds filesystem paths; the target
expects blob-backed media, so this runs through the Phase 9 upload path
(`ProductBlobStore::copyObject`) rather than a straight column copy. No new
vocabulary needed.

---

## 7. Gaps that need new tables

### 7.1 `style_construction` — no target exists

110 rows across the 25 marine styles: an ordered stack of typed layers, each
with a material paragraph. Layer types in use: `CONDUCTOR`, `INS`, `BED`,
`HQSCR`, `BRAID/AMOUR`, `SHE`.

This **is** catalog-page data — it is the construction table printed on the
datasheet — but `product.*` has no ordered-child-rows-under-a-product concept.
Neither workaround is acceptable:

- `ProductContentSections` — one HTML blob per product. Destroys the
  queryability that `sql/01_schema.sql:21-24` argues for, and re-creates the
  string-rot problem in a new place.
- Positional definitions (`CONSTRUCTION-L1` … `L5`) — caps the stack depth,
  and layer 3 means something different on every style.

**Created 2026-08-07** —
`tricab-api/database/scripts/development/create-product-construction-schema.sql`.
Idempotent; re-running produces identical counts.

Two tables. `[product].[ConstructionLayerTypes]` is a closed vocabulary of 11
layer roles, and `[product].[ProductConstructionLayers]` holds the stack with an
FK to it. A free-text column was rejected for the same reason the catalog's
`standard` table is a closed list: the source already carries a defect a
constraint would catch — `style_construction` writes `BRAID/AMOUR`, with `AMOUR`
for `ARMOUR`, and unconstrained that typo reaches a customer-facing page.

The canonical code is `ARMOUR-BRAID`; `LegacySourceCode` carries `BRAID/AMOUR`
so the loader maps on it and the rename stays auditable.

The 11 roles merge two sources — the six values `style_construction` actually
writes, and the six `sql/01_schema.sql` adds to `descript` type 71 (`HQSCR` is in
both). **Known incomplete:** `descript` type 71 already carried seven roles
before catalog-load extended it, and those seven are not enumerated anywhere
reachable from this repo. Any that are missing will surface as a foreign key
violation on load — a failed insert rather than an unrecognised layer role on a
datasheet.

Verified by test, inside a rolled-back transaction: a non-dense stack (layers 2
and 4, no layer 1) inserts fine; an unknown layer type is rejected by the FK; a
duplicate layer number on one product is rejected by the unique constraint; and
`BRAID/AMOUR` resolves to `ARMOUR-BRAID`.

The shape as built:

```
product.ConstructionLayerTypes
  ConstructionLayerTypeId  PK
  LayerTypeCode            unique   CONDUCTOR, INS, ARMOUR-BRAID, SHE, ...
  LayerTypeName                     display name for the datasheet
  LegacySourceCode         unique   'BRAID/AMOUR' -> ARMOUR-BRAID
  DisplayOrder                      physical build order, centre outward

product.ProductConstructionLayers
  ProductConstructionLayerId  PK
  ProductId                   FK -> product.Products
  ConstructionLayerTypeId     FK -> product.ConstructionLayerTypes
  LayerNumber                 unique with ProductId; CHECK > 0
  MaterialText                NVARCHAR(1000)
```

Both carry the house conventions: `PublicId`, `IsActive`, soft delete, full
audit columns, `RowVersion`, filtered indexes.

**`LayerNumber` is deliberately not dense.** catalog-load holds a layer back when
its standard fails validation and keeps the number, so `BHA` legitimately starts
at layer 2. A gap is information — the loader must not renumber, and layer count
is not a completeness check.

**Coverage caveat:** only the 25 marine styles have a construction stack. The
other 202 have none, so this table starts 89% empty.

### 7.2 CableBuilder manufacturing grain — out of scope for `product.*`

`cb_design_layer`, `cb_design_operation`, `cb_design_resource`,
`cb_design_bom`, `cb_machine`, `cb_extract_failure` are routing, BOM and machine
data. `product.*` has no such concept and should not grow one — a customer
catalog page does not carry a bill of materials or a machine setup time.

These need their own schema (a `manufacturing` schema alongside `product` and
`migration` is the obvious shape). Not designed here.

Two rules carry over from `sql/20_cb_design_schema.sql` regardless of where it
lands:

- Classify on `item_group` (FINCAT / FINMAN / SFG), **never** on the code shape —
  wrong about 9% of the time (`20_cb_design_schema.sql:98`). Only FINCAT is
  customer-facing.
- `cb_design_operation.op_seq` is a sequence number, not a name; the operation
  name is `task` (`20_cb_design_schema.sql:214`).

`cb_extract_failure` maps to `migration.MigrationIssues` and needs no new table.

---

## 8. Known losses

Accepted, or needing a decision — none are silent.

| What | Where | Severity |
|---|---|---|
| `style_standard.seq` — per-style print order | `ProductSpecificationOptionValues` has no ordering column; `SpecificationOptions.DisplayOrder` is global per definition | **Real.** Datasheet standards will print in global order, not source order. Fix is one `DisplayOrder` column on the junction table |
| `standard.verified` | No column on `SpecificationOptions` | Low. Affects the two `1/2` shorthands only |
| `standard.body` (IEC / AS-NZS / NEK) | No column | Low. Worked around by banding `DisplayOrder` — AS/NZS 10-99, IEC 100-899, NEK 900+ |
| `standard.title` | Stored in `OptionValue` | None — round-trip preserved via the alias table |

---

## 9. Carried-over data quality

Inherited from the source. Migrating does not fix these, and the seed does not
pretend otherwise.

- **`IEC 60331-1/2` and `IEC 61034-1/2`** are the source's shorthand for a pair,
  seeded upstream with `verified = 0`. Splitting each into two links is a content
  decision (`sql/01_schema.sql:99-104`).
- **Fourteen drag-fill corrections** are applied in `build_marine_load.py`, not in
  the spreadsheet, and logged to `review/corrections_applied.csv`. Twelve
  AS/NZS values were confirmed with TSD on 2026-08-03; two IEC values are
  *inferred* from the other 23 rows and not separately confirmed. If the
  spreadsheet is reissued clean, the corrections empty out and the mapping is
  unaffected.
- **`default_colour` provenance is weak.** The colour *vocabulary* is confirmed
  (14 codes, seeded), but the per-style *values* are derived from
  `design_example`, which is illustrative. See open question 4.

---

## 10. Open questions

**1. Should `VOLTAGE` become `single-select`?**
It is `text` today and the seed leaves it that way, so the load works and is
lossless. But `voltage_code` is a controlled `descript` type 7201 vocabulary
(`0.6/1KV`, `150/250V`), and a select would make it filterable. Deferred because
the full code list is not in catalog-load — only the two marine values are.

**2. How does a design attach to a style variant?**
`cb_design.style_code` is the leading alpha prefix and joins
`style_header.style_code`; Products are keyed on `style_key`, which includes the
sheath. Nothing in the source resolves a design to a sheath. Blocks any variant
load. Needs a rule from whoever owns the design codes.

**3. What is `ProductSeries` for cable styles?**
Nothing in the source maps onto a clean third level. The seed leaves
`ProductSeriesId` NULL rather than inventing a taxonomy that would have to be
undone.

**4. Is `default_colour` authoritative?** *(labels resolved 2026-08-07)*

The label half of this question is closed. The jacket colour legend supplied on
2026-08-07 gives 14 codes, and `GR` is **green** — grey is the separate code
`GY`. Holding out rather than guessing was correct: `UPA` is panel wire, and
grey would have been the natural guess.

All 14 are seeded as `DEFAULT-COLOUR` options, not just the five the marine
styles use, since this is the published controlled vocabulary:

| | | | |
|---|---|---|---|
| `BK` Black | `BL` Blue | `BR` Brown | `EA` Green / Yellow |
| `GR` Green | `GY` Grey | `OR` Orange | `RE` Red |
| `WH` White | `YE` Yellow | `PI` Pink | `PU` Purple |
| `LB` Light Blue | `RB` Red / Blue | | |

`EA` and `RB` are bi-colour on the legend (green/yellow and red/blue), so their
labels name both rather than picking one. All five codes the marine styles use
(`BK`, `RE`, `BL`, `GR`, `OR`) resolve through the alias table — verified.

**What remains open is provenance.** `sql/01_schema.sql:70` documents the column
as `descript.desc_code` where `desc_type = 40`, but `build_marine_load.py:175`
does not read that lookup — it takes the trailing two letters of
`design_example`, and `README.md:106` says 20 of those 25 values are not real
product codes. So the *values* are as illustrative as the codes they came from,
even though the *vocabulary* is now confirmed.

The field mapping is held at `needs-review` for that reason alone. Flipping it
to `mapped` is a one-word change once someone confirms the source column.

**5. Which category does a multi-facet style get?**
`Products` holds exactly one `ProductCategoryId`, but a style can carry several
`application_spec` facets — style `FK` is fire + instrumentation + control +
telecom. Six `ClassificationMappings` rows are seeded `needs-review` with no
target so the collision surfaces in the Admin UI rather than being decided
silently (Phase 12 §4).

---

## 11. What the seed script does

`tricab-api/database/scripts/development/seed-product-cable-reference.sql` —
idempotent, one transaction, vocabulary and crosswalk rows only. No products,
variants, media or prices.

| Section | Effect |
|---|---|
| 1 | `SpecificationGroups` += `thermal` |
| 2 | `SpecificationDefinitions` += `TEMP-RANGE`, `DEFAULT-COLOUR` |
| 3 | `STANDARD`: `text` → `multi-select`, `IsFilterable = 1` |
| 4 | `SpecificationOptions` += 21 standards |
| 5 | `SpecificationOptions` += 14 jacket colours |
| 6 | `Applications` += 7 |
| 7 | `ProductCategories` += `INSTRUMENTATION`, `VFD`, `SINGLE-CORE` under `CABLE` |
| 8 | `migration.SpecificationOptionAliases` += 35 (21 standards + 14 colours) |
| 9 | `migration.SpecificationFieldMappings` += 7 (4 mapped, 3 needs-review) |
| 10 | `migration.ClassificationMappings` += 6 needs-review |

**Applied 2026-08-07.** Verified counts: 6 groups, 9 definitions, 21 standard
options, 14 colour options, 10 applications, 7 categories, 35 aliases, 7 field
mappings, 6 classification mappings. Re-running produces identical counts.

The standards aliases key on `OptionLabel` (`IEC 60228`), the colour aliases on
`OptionCode` (`BK`) — because that is what each source column actually stores.

Section 9's MERGE is insert-only so a re-run cannot overwrite mappings a human
has edited in the Admin UI. The one targeted `UPDATE` that follows it refreshes
the stale `default_colour` note, and fires only while `MappedByUserId` is NULL.

**One thing to action after applying it.** The `STANDARD` type change strands 8
placeholder `ProductSpecificationValues` rows that hold `ValueText` and no
option link — all of them `AS/NZS 5000.1`. The script lists them and does not
delete them. They need either an option link or removal.

`TransformationRule` values used (`text`, `range-min`, `range-max`,
`option-lookup`, `multi-option-lookup`) are proposed. The engine defaults the
column to `'text'` and does not yet enforce a vocabulary
(`AzureSqlMigrationBatchRepository.php:828`).

---

## 12. Suggested order

1. ~~Apply the seed.~~ **Done 2026-08-07.** Still to do: clear or link the 8
   stranded `STANDARD` values (all `AS/NZS 5000.1`).
2. ~~Resolve the colour labels.~~ **Done 2026-08-07** — 14 codes seeded.
   Remaining: confirm whether `style_header.default_colour` is authoritative,
   then flip its field mapping to `mapped`.
3. Load the **25 marine styles** as Draft products. **Loader built 2026-08-07,
   not yet applied** — `catalog-load/build_azure_product_load.py` generates
   `catalog-load/sql/30_azure_product_load.sql`. Trial-run twice against the
   live database inside a rolled-back transaction: all counts hit their expected
   values (25 products, 25 voltage, 25 temp-range, 226 standard links, 68
   application links, 110 construction layers, 25 legacy mappings) and the
   second run produced no duplicates.
4. ~~Agree and create the construction-layer table (§7.1).~~ **Done 2026-08-07.**
5. Resolve the 24 unmatched standards (§4.1). **Triaged 2026-08-07**;
   `catalog-load/review/cb_standards_disposition.csv` is the worksheet, and the
   one mechanical case is applied. 23 tokens outstanding.

   The measured gate is narrower than §4.1 first suggested: of 227 resolved
   catalog styles, **92 are clean and not yet loaded** — they need no standards
   decisions at all. 111 are blocked. Loading those 92 does not depend on step 5
   finishing.
6. Design the manufacturing schema (§7.2) separately. It is not a `product.*`
   concern.

Step 3 is unblocked and is the obvious next move — it can now carry the
construction stack too. Steps 5 and 6 need content decisions.

**DDL note.** An earlier revision of this doc claimed the construction table was
blocked for want of `CREATE` on `tc`. That was wrong, and the correction matters
for anything else that gets deferred on the same grounds: the Entra identity
holds `db_ddladmin` on the **Azure SQL** `tc` and can create schemas and tables
(the `rfq` schema was made this way). The no-DDL constraint is real but belongs
to the **MySQL** `tc` and a different account. Two databases, one name.
