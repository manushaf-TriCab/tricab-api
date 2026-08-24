# Phase 12.7 — Pre-Migration Master Data Fix & Seed

**Status:** DRY-RUN COMPLETE — **STOP AND WAIT FOR APPLY APPROVAL**  
**Date:** 2026-08-20  
**Mode:** Target plan + dry-run only. Legacy MySQL read-only. **No Azure writes applied.** No Product/Variant/media/price migration.

CLI: `php bin/apply-product-migration-master-data.php --dry-run`  
Artifact: `core/docs/phase12.7/master-data-dry-run.json`

Phase 12.6 approval **does not** authorize target database mutation.

---

## 1. Phase 12.6 baseline

Verified from `core/docs/phase12.6-mappings/readiness-report.json`:

| Metric | Before |
|--------|------:|
| Product READY | 24 |
| Product REVIEW | 205 |
| Product SOURCE_ONLY | 211 |
| Product INVALID | 8 |
| Variant READY | 0 |
| Variant REVIEW | 18,975 |
| Variant BLOCKED | 20 |
| Families mapped | 1 (`CABLE`) |
| Categories mapped | 0 |
| Series mapped | 0 |
| Definitions mapped | 0 |
| Units available | NO |
| Media filesystem | NO |
| Catalogue nodes | 97 KEEP |

---

## 2. Target schema inspection

| Field | Value |
|-------|-------|
| Server | `tricab-mel` |
| Database | `tc` |
| Environment gate | **NON-PRODUCTION** |
| Existing Families | `CABLE`, `PLUG` |
| Catalogue nodes | 97 |
| Products (count query) | preserved (no deletes) |
| `product.SpecificationUnits` | **MISSING** |

---

## 3. SpecificationUnits root cause

**Classification: `SCHEMA_NOT_APPLIED`**

Evidence:

1. Phase 11 / `create-product-schema.sql` defines `[product].[SpecificationUnits]`.
2. Phase 2C `alter-product-pricing-uom-phase2c.sql` and `seed-product-pricing-units-phase2c.sql` FK/MERGE against that table.
3. Validated Azure target returns `Invalid object name 'product.SpecificationUnits'`.
4. Other product masters exist (Families, Categories, Series, Definitions, CatalogueNodes) — partial product schema was applied **without** Units.

Not: wrong database (product data present); not an invented alternate unit table.

---

## 4. Unit repair / seed (prepared, not applied)

Scripts (additive/idempotent):

- `database/scripts/development/alter-product-specification-units-phase12.7.sql`
- `database/scripts/development/seed-product-specification-units-phase12.7.sql`

Proposed seed set (11):

| Code | Symbol | Source |
|------|--------|--------|
| M | m | Phase 2C pricing default + length |
| EA | ea | Phase 2C |
| FT | ft | Phase 2C |
| PK | pk | Phase 2C |
| MM | mm | Phase 12.6 specs |
| MM2 | mm² | Phase 12.6 |
| KG | kg | Phase 12.6 |
| KG_PER_KM | kg/km | Phase 12.6 |
| A | A | Phase 12.6 |
| V | V | Phase 12.6 |
| C | °C | Phase 12.6 |

Canonicalization: single metre row `M` / `m` — no duplicate metre/meter.

Pricing UOM FKs (`Products.PricingUnitId`, `ProductVariants.PricingUnitId`, price tables) remain compatible once the table exists.

---

## 5. Family decisions

| Code | Decision | Priority |
|------|----------|----------|
| CABLE | APPROVED_MAP_EXISTING | REQUIRED |
| ACCESSORIES | **PROPOSED_FOR_APPROVAL** create | REQUIRED |
| PLUG | **KEEP_SEPARATE** (≠ Accessories) | REQUIRED |

---

## 6. Category decisions

Do **not** create 207 Categories.

| Decision | Count |
|----------|------:|
| APPROVED_CREATE | 0 |
| APPROVED_MAP_EXISTING | 2 |
| REVIEW | 140 |
| NOT_A_PRODUCT_CATEGORY | 63 |
| REJECT | 2 |

Voltage / application / navigation / region / obsolete terms are not ProductCategories.

---

## 7. Series decisions

| Decision | Count |
|----------|------:|
| APPROVED_CREATE | 0 |
| APPROVED_MAP_EXISTING | 0 |
| REVIEW | 390 |

Series require valid identity + Family + **approved Category**. None auto-created.

---

## 8. Specification Group decisions

Reuse existing target groups. Dry-run create count: **0**.

---

## 9. Definition decisions

| Decision | Count |
|----------|------:|
| APPROVED_CREATE | 0 |
| APPROVED_MAP_EXISTING | 0 |
| REVIEW | 62 |

Scope/data-type remain REVIEW until operator evidence approval.

---

## 10. d/v mapping decisions

Schema prep: `alter-migration-spec-field-style-phase12.7.sql` adds `LegacyStyleCode` (style-specific uniqueness).

| Decision | Count |
|----------|------:|
| PROPOSED_FOR_APPROVAL (SUGGESTED, style-specific) | 759 |
| REVIEW (uncertain / global) | 5 |

If applied later, rows persist in `migration.SpecificationFieldMappings` as **needs-review** until explicitly mapped — not PHP switches. Global `d1=…` forbidden.

---

## 11–13. Standards / Approvals / Core Colours

| Area | Decision |
|------|----------|
| Standards | 78 candidates REVIEW — no silent alias merge |
| Approvals | 207 candidates REVIEW (certificate CPT derived) |
| Core Colours | **RAW_TEXT_REQUIRED** — keep CORE-COLOURS long text; no single-select force |

Certificates remain DOCUMENT / APPROVAL EVIDENCE — not Product master data; no file migration.

---

## 14. Option decisions

191 option/alias proposals → **REVIEW**. No fuzzy auto-create.

---

## 15. Catalogue decisions

| Metric | Value |
|--------|------:|
| Existing nodes KEEP | 97 |
| Nodes created | 0 |
| Nodes modified | 0 |
| Nodes deleted | 0 |
| Proposed nodes REVIEW | 2 |
| Placements approved | 0 |

Catalogue Navigation remains separate from Family/Category/Series.

---

## 16. Accessory identity investigation

Live re-check:

| Check | Result |
|-------|--------|
| `accessory_specs.product_code = tpro_ext.product_code` | **0 hits** |
| Trim/case-insensitive join | investigated in repository |
| WP style = tpro_ext.style assumption | **NOT used** |
| Prefix / name matching | **FORBIDDEN** |

**Outcome: `EXPLICIT_MAPPING_REQUIRED`** for accessory_specs population (~2,130). Do not manufacture automated match rules to inflate readiness %.

---

## 17. tpro exclusions

Validated rule retained:

| Rule | Status |
|------|--------|
| Joined `tpro.ida = tpro_ext.product_code` | REVIEW Variant candidates |
| tpro-only (~81,247) | **EXCLUDED** commerce/historical/stock |
| `LEFT(ida,3)` | FORBIDDEN |

---

## 18. Filesystem status

| Check | Result |
|-------|--------|
| LEGACY_FILES_ROOT | **UNAVAILABLE** (waived) |
| Media readiness | BLOCKED |
| Document readiness | BLOCKED |

Master-data planning continued; full catalogue migration readiness remains blocked on files when asset reconciliation is required.

---

## 19. Dry-run report

```text
Target: tricab-mel / tc (NON-PRODUCTION)
Mode: dry-run
Azure mutations: 0
Legacy mutations: 0
Proposed for approval: 773
  - SpecificationUnits schema + 11 unit seeds
  - ACCESSORIES Family
  - 759 style-specific d/v mapping rows (pending apply approval)
  - LegacyStyleCode schema alter
Review/skipped: 1140
Conflicts: 0
```

Artifact: `core/docs/phase12.7/master-data-dry-run.json`  
Candidates: `core/docs/phase12.7/apply-candidates.json`

---

## 20. Applied target changes

**NOT RUN** — awaiting explicit operator approval.

Apply gate (when approved):

```bash
# 1) Write YES to core/docs/phase12.7/APPLY_APPROVED.txt
# 2) php bin/apply-product-migration-master-data.php --apply --confirm-non-production
# 3) Manually execute GO-batched SQL scripts in SSMS against confirmed non-prod DB
```

---

## 21. Idempotency

Scripts use `IF OBJECT_ID` / `MERGE` / `IF NOT EXISTS`. Second successful apply should yield 0 unexpected inserts after first successful apply.

---

## 22. Before/after readiness

Apply was **not** executed, so readiness was **not** re-run. After-state equals before-state:

| Metric | Before 12.7 | After 12.7 | Delta |
|--------|------------:|-----------:|------:|
| Products READY | 24 | 24 | 0 |
| Products REVIEW | 205 | 205 | 0 |
| Products SOURCE_ONLY | 211 | 211 | 0 |
| Products INVALID | 8 | 8 | 0 |
| Variants READY | 0 | 0 | 0 |
| Variants REVIEW | 18,975 | 18,975 | 0 |
| Variants BLOCKED | 20 | 20 | 0 |
| Families mapped | 1 | 1 | 0 |
| Categories mapped | 0 | 0 | 0 |
| Series mapped | 0 | 0 | 0 |
| Definitions mapped | 0 | 0 | 0 |
| Units available | NO | NO | — |
| Media filesystem | NO | NO | — |
| Documents filesystem | NO | NO | — |

---

## 23. Remaining REVIEW reasons (Products)

From Phase 12.6 identity (unchanged until apply + remapping):

| Reason group | Approx |
|--------------|-------:|
| Exact style but classification/Series incomplete | ~199 EXACT_STYLE → REVIEW |
| Accessory identity unresolved | included in REVIEW/SOURCE_ONLY |
| Composite/comma style | 6 (quarantined) |
| SOURCE_ONLY (no tc style match) | 211 |
| INVALID blank style | 8 |

Variants: nearly all REVIEW pending parent Product READY + mapping coverage; 20 BLOCKED (missing style / duplicate codes).

---

## 24. READY subset

Still the **24** explicit-mapping Products from Phase 12.6. Export remains in Phase 12.6 identity artifact. **Not migrated** in 12.7.

After units + ACCESSORIES + approved mappings apply, re-run `build-catalogue-mapping-readiness.php` to refresh READY subset.

---

## 25. Remaining migration blockers

1. ~~SpecificationUnits not applied~~ → **RESOLVED** (scoped apply)
2. ~~ACCESSORIES Family not created~~ → **RESOLVED** (scoped apply)
3. Categories/Series not operator-approved (0 creates)
4. Accessory identity requires explicit mappings
5. LEGACY_FILES_ROOT unavailable
6. d/v mappings proposed but not applied/approved as `mapped` (schema ready; **0 mapping rows inserted**)
7. Full catalogue dry-run still NOT READY

---

## 26. Recommendation for Phase 12.8

**Do not start Phase 12.8 yet.**

Next: operator-review packages (Batch A Classification, Batch B Spec masters, Batch C d/v mappings) — see Scoped Apply section below.

---

## Tests

| Suite | Result |
|-------|--------|
| `PlanProductMigrationMasterDataServiceTest` | PASS |
| `ScopedMasterDataApplyPlannerTest` | PASS |
| Phase 12.6 readiness unit tests | PASS |
| Migration-affected Vitest | PASS (2) |
| Dry-run CLI live | PASS (0 writes) |
| Scoped apply + idempotency | PASS |

Unrelated pre-existing: `ProductMigrationEngineTest::test_identity_matcher_never_uses_name_alone` expects `requiresReview=true` but `ProductIdentityMatchResult::none()` returns `false` / `Low` by design. Frontend `tsc` failures in price-list / lookup fixtures are outside this scoped apply.

---

# Scoped Apply — Approved Structural Blockers

**Date:** 2026-08-20  
**Status:** APPLIED + VERIFIED + IDEMPOTENT — **STOP** (no further mapping batches)

## 1. Approval scope

Applied **only**:

| Scope | Approved |
|-------|----------|
| SpecificationUnits schema | YES |
| 11-unit seed (M, EA, FT, PK, MM, MM2, KG, KG_PER_KM, A, V, C) | YES |
| ACCESSORIES Family | YES |
| Style-specific mapping schema (`LegacyStyleCode`) | YES |
| 759 d/v mapping **rows** | **NO** |
| Categories / Series / Definitions / Options | **NO** |
| Products / Variants / media / prices | **NO** |

CLI (scoped; broad apply refused without `--scope`):

```bash
php bin/apply-product-migration-master-data.php \
  --dry-run \
  --scope=specification-units,accessories-family,migration-spec-field-schema

php bin/apply-product-migration-master-data.php \
  --apply \
  --scope=specification-units,accessories-family,migration-spec-field-schema \
  --confirm-non-production
```

## 2. Target environment

| Field | Value |
|-------|-------|
| Server | `tricab-mel` |
| Database | `tc` |
| Environment | **NON-PRODUCTION** |
| Mismatch gate | STOP if not tricab-mel/tc |

## 3. Scoped dry-run (immediate pre-apply)

| Action | Count |
|--------|------:|
| CREATE_TABLE | 1 |
| ALTER_TABLE | 1 |
| INSERT | 12 |
| UPDATE | 0 |
| SKIP | 0 |
| CONFLICT | 0 |
| DROP_CONSTRAINT | 1 (unique key replace only) |
| CREATE_INDEX | 1 |
| Product/Variant/price mutations | **0** |
| Legacy writes | **0** |

Artifact: `core/docs/phase12.7/scoped-apply-plan.json`

## 4. SpecificationUnits schema report

Created `[product].[SpecificationUnits]` matching Phase 11:

- Columns: SpecificationUnitId, PublicId, UnitCode, UnitName, Symbol, Description, DisplayOrder, IsActive, audit fields, Deleted*, RowVersion
- PK: SpecificationUnitId
- UQ: PublicId, UnitCode
- CK: Name/Symbol not empty
- FK: CreatedBy/UpdatedBy/DeletedBy → identity.Users
- NO DROP TABLE, NO Product DELETE/UPDATE, NO Product/Variant create

Compatible with Phase 2C PricingUnitId / SpecificationUnitId FKs once table exists.

## 5. Unit seed report

| Code | Name | Symbol | Meaning | Exists before? | Action |
|------|------|--------|---------|----------------|--------|
| M | Metre | m | metre (pricing default + length) | NO | INSERT |
| EA | Each | ea | each | NO | INSERT |
| FT | Foot | ft | foot | NO | INSERT |
| PK | Pack | pk | pack | NO | INSERT |
| MM | Millimetre | mm | millimetre | NO | INSERT |
| MM2 | Square millimetre | mm² | cross-section | NO | INSERT |
| KG | Kilogram | kg | kilogram | NO | INSERT |
| KG_PER_KM | Kilogram per km | kg/km | kg/km | NO | INSERT |
| A | Ampere | A | ampere | NO | INSERT |
| V | Volt | V | volt | NO | INSERT |
| C | Degree Celsius | °C | temperature | NO | INSERT |

Second apply: all **SKIP** (0 new).

## 6. ACCESSORIES Family report

| Field | Value |
|-------|-------|
| Code | ACCESSORIES |
| Name | Accessories |
| Display order | 30 |
| Active | yes |
| Existing conflict | none |
| Action | CREATED |
| PLUG | KEEP_SEPARATE (unchanged) |
| Products reclassified | **0** |

## 7. Migration style-mapping schema report

| Change | Result |
|--------|--------|
| ADD LegacyStyleCode NVARCHAR(50) NULL | APPLIED |
| DROP UQ_migration_SpecificationFieldMappings_Source | APPLIED (constraint only) |
| CREATE unique index SourceStyle | APPLIED |
| d/v mapping row inserts | **0** |

## 8. Apply result

**APPLIED successfully.** Artifacts: `scoped-apply-result.json`. Legacy writes: 0.

## 9. Idempotency result

Second scoped apply: **PASS** — CREATE_TABLE 0, INSERT 0, SKIP 15, unexpected inserts 0.

## 10. Product/Variant count verification

| Metric | Before | After |
|--------|-------:|------:|
| Products | 118 | 118 |
| Variants | 4178 | 4178 |
| Catalogue Nodes | 97 | 97 |
| ProductPrices | 0 | 0 |
| ProductVariantPrices | 0 | 0 |

## 11. Quality gates

| Gate | Result |
|------|--------|
| Scoped MasterData / Readiness PHPUnit | PASS (13) |
| Migration Vitest | PASS (2) |
| PHPStan (touched readiness/planner) | minor nullCoalesce noise only |
| Full migration PHPUnit | 1 pre-existing identity-matcher expectation mismatch (unrelated) |
| Frontend tsc full | pre-existing price-list / lookup fixture errors (unrelated) |

## 12. Phase 12.6 readiness rerun

`php bin/build-catalogue-mapping-readiness.php` → legacyWrites=0, fullCatalogueDryRun=**NOT READY**

## 13. Before/after comparison

| Metric | Before | After | Delta |
|--------|-------:|------:|------:|
| Product READY | 24 | 24 | 0 |
| Product REVIEW | 205 | 205 | 0 |
| Product SOURCE_ONLY | 211 | 211 | 0 |
| Product INVALID | 8 | 8 | 0 |
| Variant READY | 0 | 0 | 0 |
| Variant REVIEW | 18,975 | 18,975 | 0 |
| Variant BLOCKED | 20 | 20 | 0 |
| Families mapped | 1 | **2** | +1 |
| Categories mapped | 0 | 0 | 0 |
| Series mapped | 0 | 0 | 0 |
| Definitions mapped | 0 | 0 | 0 |
| Units available | NO | **YES** | — |
| ACCESSORIES Family | NO | **YES** | — |
| d/v schema | NO | **YES** | — |

## 14. Remaining blockers

1. Category mapping (~140 REVIEW + 63 not ProductCategory)
2. Series mapping (390 REVIEW)
3. Specification Definition approval (62 REVIEW)
4. d/v mapping **data** approval (759 suggested + 5 review; schema ready)
5. Accessory identity (product_code join still 0 → EXPLICIT_MAPPING_REQUIRED)
6. Standards/Approvals option mapping (REVIEW)
7. Media filesystem UNAVAILABLE
8. Documents filesystem BLOCKED
9. Catalogue placements REVIEW

## 15. Next approval packages

### Batch A — Classification
Category mappings · Series mappings

### Batch B — Specification Masters
Definitions · Standards · Approvals · Option aliases

### Batch C — Style-Specific d/v Mappings
759 suggested · 5 review (schema already applied)

Accessory identity and LEGACY_FILES_ROOT remain separate tracks.

**STOP — do not start Phase 12.8 or Batch A/B/C without explicit approval.**
