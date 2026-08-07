# Cable-native product schema — design

## Status

**Proposal. Nothing built.** Decisions marked *Agreed* were taken in discussion
on 2026-08-07. Everything else is proposed or open.

This document is written to be picked up cold, without the conversation that
produced it. Section 3 records the current state of the database so a fresh
session knows what already exists.

| | |
|---|---|
| Supersedes | the `product.*` schema as the *system of record* — not as the published surface, see §7 |
| Depends on | `product-catalog-load-source-mapping.md` (what is loaded today, and how) |
| Blocked on | two inputs and two decisions — §10 |

---

## 1. The case for changing

### 1.1 The current schema was not derived from the product data

`tricab-api/database/scripts/development/create-product-schema.sql` says so in
its own header:

> **NOTE:** No approved ERD file was present in-repo. This schema is derived from
> the Phase 1 Product Module prompt and aligned to existing Customer/Identity
> conventions.

Phase 12's source inventory (§2.2) confirms the omission from the other side: it
lists `tpro`, `tpro_ext`, `cable_specs`, `descript` — and nothing from
CableBuilder, because catalog-load did not exist when it was written.

This is not a criticism of the work; it is a statement of what it was built
from. It is a generic PIM, and it does the generic PIM parts well.

### 1.2 The legacy data always had per-type schemas

The strongest argument is that the distinction being proposed is not new. It
existed in the source and the current schema flattened it:

| Legacy table | Key | Attributes |
|---|---|---|
| `cable_specs` | composite `(cable_style, nc, xa, ct, …)` | conductor and core geometry |
| `accessory_specs` | `product_code` | `min_cable`, `max_cable`, `imax`, `tensile`, `torque`, `spool_len`, `nom_conductor` |

`min_cable` / `max_cable` is the cable outside-diameter range a gland accepts.
`torque` is an installation figure. Neither is meaningful on a cable. **An
accessory cannot be defined in terms of cable features.**

The legacy WordPress site agreed: separate custom post types `cable`,
`accessory`, `certificate`, with separate URL namespaces.

### 1.3 The failure mode is already visible in the data

`accessory_specs` carries `d1`–`d7` and `v1`–`v4` — positional columns whose
meaning is looked up **per style** in a `spec_labels` table. Phase 12 §4 lists
them as ambiguous, requiring per-style mapping and manual review.

That is what forcing heterogeneous products into one shape produces. It is
already in the data, and a generic specification model preserves it rather than
resolving it.

### 1.4 It is grain, not only attributes

The deeper mismatch. Product types differ in **identity and depth**:

| Type | Identity | Depth |
|---|---|---|
| cable | style (+ sheath) → design (+ alternative, + version) | two levels, versioned |
| accessory | `product_code` *is* the SKU | one level |

`Products → ProductVariants` is a single fixed depth and fits neither.
`ProductVariants` has no column for alternative or version, so the design grain —
the thing a factory actually builds and a customer actually buys — cannot be
represented at all.

### 1.5 The generic model has already needed a domain table

During the 2026-08-07 load, `product.ProductConstructionLayers` and
`product.ConstructionLayerTypes` had to be created because an ordered layer stack
has no shape in the generic PIM. The seam was already showing.

---

## 2. What is *not* wrong with `product.*`

Stated plainly so this is not read as a rewrite-everything proposal.

Working and worth keeping: uniform audit columns, soft delete and `RowVersion`;
`PublicId` separated from the surrogate key; typed specification columns rather
than a JSON blob; filtered indexes; and the whole publishing and commerce
surface — pricing, media/blob (Phase 9), documents, slugs, SEO, redirects,
regions, markets, industries, applications, audit events, sync logs.

catalog-load has **no** answer for any of that. This proposal keeps it. See §7.

---

## 3. Current state of the database (2026-08-07)

So a fresh session does not re-derive it.

`tricab-mel.database.windows.net` / `tc`, schema `product` (58 tables) and
`migration`. The Entra identity `manushaf@tricab.com` holds `db_ddladmin`, so
DDL is available. The database is serverless and auto-pauses — first connection
after idle may need `-l 60`.

Loaded by `catalog-load/build_azure_product_load.py`:

| Table | Rows | Note |
|---|---:|---|
| `product.Products` | 133 | 100 cable styles + 33 pre-existing placeholders |
| `product.ProductSpecificationValues` | 246 | voltage 25, temp-range 98, standards parents 100 |
| `product.ProductSpecificationOptionValues` | 811 | standard links |
| `product.ProductApplications` | 213 | |
| `product.ProductConstructionLayers` | 110 | marine styles only |
| `product.SpecificationOptions` | 35 | 21 standards + 14 jacket colours |
| `product.ConstructionLayerTypes` | 11 | |
| `migration.LegacyProductMappings` | 100 | `style_key` → ProductId |
| `migration.SpecificationOptionAliases` | 36 | |

Facts that matter for migration:

- **75 of the 100 products carry placeholder names.** CableBuilder has no
  equivalent of the short marketing `title`, so `title_ds` was copied into
  `ProductName` against a `NOT NULL` column. They need real names before
  anything publishes, under any schema.
- **All 100 are Draft / concept.** Nothing is published.
- 29 further styles are held for having no `title_ds` at all; `NVC` is held on
  an unresolved standard.
- 23 standards tokens remain unconfirmed —
  `catalog-load/review/cb_standards_disposition.csv`.
- Everything was loaded by generated, re-runnable scripts. **Nothing here is
  hand-authored content.**

---

## 4. The proposed model

*Agreed 2026-08-07: a shared product core with per-type sub-schemas.*

```
core.ProductTypes          cable | accessory | lead | service
core.Product               one row per product, whatever the type
  ProductId, PublicId, ProductTypeId -> core.ProductTypes
  Code, Name, ShortDescription, FullDescription
  Status, Lifecycle, Family/Category/Series
  + publishing and commerce (see §7)

cable.Style                style_key grain
  -> cable.Design          (design_code, alternative, version)
  -> cable.ConstructionLayer
  -> cable.StyleStandard

accessory.Spec             product_code grain, named columns

lead.Assembly              -> lead.AssemblyComponent

service                    no type table — reserved only
```

### 4.1 Invariants

**One type per product, enforced by the database.** Give `core.Product` a unique
key on `(ProductId, ProductTypeId)` and have each type table carry a composite
foreign key to it, with its own `ProductTypeId` fixed by a CHECK. An accessory
row then physically cannot attach to a cable product. Convention is not enough
here — this is the constraint the whole design rests on.

**Type tables must not drift back into EAV.** If a type table needs a lookup to
know what a column means, it has reproduced `spec_labels`. Name every column.

**The core must be able to render a list without joining type tables.** Search,
browse and price lists span types. Name, code, family, thumbnail and status live
on the core. Type-specific filters (voltage, gland range) are scoped to a
selected type in the UI.

---

## 5. Per-type detail

### 5.1 cable

Two levels, because that is the source grain.

- **`cable.Style`** — `style_key` (style code + optional sheath character). The
  datasheet entity. Carries `title_ds`, application text, voltage, temperature
  range, default colour.
- **`cable.Design`** — PK `(design_code, alternative, version)`. The buildable
  and sellable thing. `item_group` (FINCAT / FINMAN / SFG) classifies it;
  **never infer this from the code shape — wrong ~9% of the time**
  (`catalog-load/sql/20_cb_design_schema.sql:98`).
- **`cable.ConstructionLayer`** — ordered, `LayerNumber` deliberately not dense;
  a gap is information, not corruption. Layer roles are a closed vocabulary.
  Already built as `product.ConstructionLayerTypes` / `ProductConstructionLayers`
  and portable as-is.
- **`cable.StyleStandard`** — link rows **with `Seq`**. Per-style print order is
  currently lost because `ProductSpecificationOptionValues` has no ordering
  column; a native table fixes it. Standards themselves stay a closed list —
  the foreign key is the guard that stops a fabricated standards number
  reaching a datasheet.

Unresolved from §1.4: how a design attaches to a sheath variant.
`cb_design.style_code` is the leading alpha prefix and joins `Style.style_code`,
not `style_key`. Nothing in the source resolves a design to a sheath. **This
blocks any design-level load** and needs a rule from whoever owns design codes.

### 5.2 accessory

One level. `product_code` is the SKU.

Columns from Phase 12 §2.2, **to be verified against `AccSpecs.php` and the live
`accessory_specs` table before any DDL**: `nom_conductor`, `min_cable`,
`max_cable`, `imax`, `volts`, `tensile`, `torque`, `spool_len`, `weight`, plus
`d1`–`d7` and `v1`–`v4`.

**The `d`/`v` columns must be resolved, not carried over.** Read `spec_labels`
per style, work out what each position means, and give it a real column. If two
styles genuinely use a position for different things, that is two columns with
one nullable per style — not one column with a lookup. Retiring `spec_labels`
is a deliverable of this work, not a side effect.

### 5.3 lead — an assembly

*Agreed 2026-08-07: a lead is an assembly, not a standalone type.*

Components reference the **deepest identity of each type**. A real lead is a
specific cable *design* — 3C2.5 black — terminated with specific accessory
*product codes*. Referencing the style would lose the size and colour.

```
lead.Assembly              ProductId -> core.Product
                           overall length, tolerance, …

lead.AssemblyComponent
  LeadProductId
  Position, RoleCode         CABLE | TERMINATION_A | TERMINATION_B | ACCESSORY
  CableDesignId      NULL -> cable.Design
  AccessoryProductId NULL -> core.Product
  Quantity, LengthMm
  CHECK: exactly one component FK is non-null
```

Two typed nullable FKs with an exactly-one CHECK, **not** a polymorphic
`(ComponentType, ComponentId)` pair — this keeps real referential integrity.

Write the CHECK carefully. `CK_product_ProductCodes_Owner` in the current schema
is the broken version of this exact pattern: three branches that read as an
exclusive choice but reduce to "at least one non-null", because the third branch
permits both.

**Keep this distinct from `cb_design_bom`.** That is CableBuilder's
manufacturing bill — how the factory builds a cable. A lead assembly is a
*catalog* statement of what a customer buys. They will look similar and mean
different things; conflating them puts manufacturing structure on a catalog page.

### 5.4 service

*Agreed 2026-08-07: reserved only.*

A row in `core.ProductTypes`. **No type table, no columns.** The term came from
Jeff without elaboration; inventing attributes now would be guessing, and a
wrong guess is harder to remove than an absent one.

Open, low urgency: confirm services *are* catalog products. If they are
installation, testing or drum hire, they may belong in quoting — sold, but not
catalog entries with slugs and media. Reserving the type costs nothing either
way; building on it before that conversation does.

---

## 6. What each type does *not* get

Deliberate, to stop the model sprawling:

- Manufacturing grain — `cb_design_layer`, `_operation`, `_resource`,
  `_bom`, `cb_machine` — is **not** part of this. It is routing and BOM data and
  belongs in its own schema (`manufacturing`), read by a scheduler, not by a
  catalog page. Out of scope here, still needed.
- `ProductCodes` from `design_example`: not migrated. 20 of 25 are not real
  product codes; a fabricated code on a catalog page is worse than none.

---

## 7. What stays in `product.*`

`product.*` stops being the system of record and becomes the **published
surface**. Everything below is kept and not rebuilt:

pricing (`PriceLists`, `PricingTiers`, `CustomerProductPrices`,
`ProductPrices`), media and blob metadata (Phase 9 — `ProductImages`,
`ProductMedia`, `ProductDocuments`, upload sessions, SAS, scanning),
`ProductSlugs`, `ProductSEO`, `ProductRedirects`, `ProductRegionalContent`,
`Regions`, `Markets`, `Industries`, `Applications`, `ProductAuditEvents`,
`ProductSyncLogs`, `ExternalEntityMappings`, and the `migration` schema.

The projection direction is **cable/accessory/lead → core → published surface**.
The loaders built on 2026-08-07 become that projection rather than a one-off
migration.

---

## 8. Impact on the web app — smaller than it looks

Measured 2026-08-07.

| Surface | Lines | Coupling |
|---|---:|---|
| Public API client (`src/api/public/products.ts`) | 136 | none — slug + publicId only |
| Public components (4 files) | 246 | none |
| Admin API clients | 2,894 | contract-level |
| Admin components + hooks | ~7,250 | **shaped by the schema** |

**The public site is schema-agnostic and survives the change untouched.** It
addresses everything by slug and `publicId`, and a 493-line `parse.ts` translates
wire JSON into typed objects. The public DTO is already presentation-shaped:

```ts
interface PublicProductSpecificationItem {
  code: string; name: string; groupName: string | null
  displayValue: string | null   // pre-rendered
  unit: string | null
}
```

`displayValue` is a rendered string. The UI does not know specifications are
stored as EAV, so it will not notice when they are not. Each type projects into
the same contract.

The admin UI is the real cost — but a large share of it exists *only* to manage
the generic EAV: `ProductEditorPanels.tsx` (1,535),
`SpecificationManagementPage.tsx` (601), `ClassificationManagementPage.tsx`
(561), `specificationApi.ts` (390). With typed columns those are mostly deleted,
not ported. The migration UI, publication-readiness panel and blob handling are
workflow and survive.

*Caveat: line counts with a structural read, including tests. Not a port
estimate. `ProductEditorPanels.tsx` and the hooks were not reviewed line by
line — do that before committing to a number.*

---

## 9. Migration path for what is already loaded

**Recommendation: reload from source, do not migrate rows.**

Every one of the 100 products was produced by a generated, re-runnable script
from `catalog-load`. None is hand-authored. All are Draft. 75 carry placeholder
names that have to be replaced regardless. Migrating them would preserve
`PublicId` values that nothing external depends on yet.

Retarget the generators at the new schema and re-run. Keep
`migration.LegacyProductMappings` so `style_key` → product identity survives the
change.

The one thing worth preserving deliberately: the standards option list, colour
vocabulary, and the alias table in `migration.SpecificationOptionAliases` — 36
rows of confirmed crosswalk that took real effort and are schema-independent.

---

## 10. Blocked on

**Inputs needed**

1. The real `accessory_specs` column list, from `AccSpecs.php` and the live
   table — not the Phase 12 summary quoted in §5.2.
2. `spec_labels` contents, to resolve `d1`–`d7` / `v1`–`v4` into named columns.

**Decisions needed**

3. How a cable design attaches to a sheath variant (§5.1). Blocks the design
   level entirely.
4. Confirmation that services are catalog products (§5.4). Low urgency.

**Carried over, unrelated to this design**

5. 23 standards tokens outstanding —
   `catalog-load/review/cb_standards_disposition.csv`.
6. 75 placeholder product names.
7. Whether `style_header.default_colour` is authoritative; its values derive from
   `design_example`, which is illustrative.

---

## 11. Anti-patterns to avoid

- **Do not use CableBuilder `item_nature` as the type discriminator.** It looks
  like a product type but describes process nature on layers — `Sheathing`,
  `Lay Up`, `Insulation`, `Braiding`, `Wire Armour`. The nearest real classifier
  is `ph_erp_itemgroupid` (FINCAT / FINMAN / SFG), and that is
  catalog-vs-manufactured, not cable-vs-accessory.
- **Do not carry `d1`–`d7` / `v1`–`v4` across.** §5.2.
- **Do not let a type table become EAV with a new name.** If it needs a lookup
  to interpret a column, start again.
- **Do not model a lead as a peer of cable and accessory.** §5.3.
- **Do not put a `CableId` on accessory for "fits cable X".** Compatibility is a
  relationship; `product.ProductAccessories` already models it.
- **Do not rebuild pricing, media, SEO or slugs.** §7.

---

## 12. Suggested sequence

1. Gather the two inputs (§10.1–2). Nothing accurate can be drafted for
   `accessory` without them.
2. Draft DDL for `core.ProductTypes` + `core.Product` and the type-integrity
   composite FK. Small, and it settles the invariant everything else depends on.
3. `cable.*` — port `ConstructionLayer` / `ConstructionLayerTypes` as-is, add
   `Style` and `StyleStandard` with `Seq`. Hold `Design` until decision §10.3.
4. Retarget `catalog-load/build_azure_product_load.py` and re-run. Same two
   gates: a name, and all-or-nothing standards.
5. `accessory.*` once the columns are known.
6. `lead.*` last — it references the other two.
7. `manufacturing` schema separately (§6). Not part of this.
