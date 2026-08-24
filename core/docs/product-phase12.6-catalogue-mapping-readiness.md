# Phase 12.6 — Legacy Catalogue Mapping & Migration Readiness

**Status:** COMPLETE — **STOP AND WAIT FOR APPROVAL**  
**Date:** 2026-08-20  
**Mode:** Read-only WordPress + tc via `LegacyReadOnlySqlGuard`. No Product/Variant/media/price migration. No Azure Product deletes. No automatic master-data creates.

Live run: `php bin/build-catalogue-mapping-readiness.php`  
Queries: **232** · **3.1s** · `legacyWrites=0` · `fullCatalogueDryRun=NOT READY`

Artifacts: `core/docs/phase12.6-mappings/`

---

## 1. Executive verdict

| Gate | Result |
|------|--------|
| Legacy writes | **NO** |
| Product migration | **NO** |
| Variant migration | **NO** |
| Media migration | **NO** |
| Pricing migration | **NO** |
| Full catalogue dry-run | **NOT READY** |

**Next phase (after approval only):** Phase 12.7 — Pre-Migration Master Data Fix & Seed  
**Then:** Phase 12.8 — Full Product Catalogue Migration Dry Run  
Pricing resumes only after Product/Variant migration and reconciliation.

Engine reuse: Phase 12 `ProductMigrationService` + discovery + mapping tables. **No second migration engine.**

---

## 2. Identity readiness

Statuses: `READY | REVIEW | BLOCKED | SOURCE_ONLY | INVALID | EXCLUDED`

| Status | Count |
|--------|------:|
| READY | 24 |
| REVIEW | 205 |
| BLOCKED | 0 |
| SOURCE_ONLY | 211 |
| INVALID | 8 |
| EXCLUDED | 0 |
| **Total WP products** | **448** |

Match methods (allowed only):

| Method | Count |
|--------|------:|
| EXPLICIT_MAPPING | 24 |
| EXACT_STYLE | 199 |
| NO_MATCH | 211 |
| INVALID | 14 |

Forbidden heuristics **not used**: `LEFT(tpro.ida,3)`, name-only matching.

Comma/multi style codes quarantined as **REVIEW** (6 posts; not auto-split). Blank styles **INVALID** (8).

Identity READY today means existing Azure `LegacyProductId`/style collision with explicit mapping evidence. Exact tc style match alone remains **REVIEW** until classification/Series/specs clear.

---

## 3. Accessory identity

Strategy (deterministic join proposal — not assumed equal to WP `style_code`):

```text
accessory_specs.product_code = tpro_ext.product_code → tpro_ext.style
```

Live evidence:

| Signal | Value |
|--------|------:|
| accessory_specs rows | 2,130 |
| tpro_ext alpha styles | 223 |
| exact product_code join | **0** |
| status | **REVIEW** |

**Finding:** Live `accessory_specs.product_code` does **not** currently join to `tpro_ext.product_code` (0 hits). Do **not** assume `WP accessory style_code = tpro_ext.style`. Operator must approve an alternate key (trim/case, description tables, or explicit mapping table) in Phase 12.7.

---

## 4. Family mappings

| Source rule | Affected | Existing | Proposed | Status |
|-------------|--------:|----------|----------|--------|
| `post_type=cable` | 232 | `CABLE` | `CABLE` | READY |
| `post_type=accessory` | 216 | — | `ACCESSORIES` | REVIEW |
| `post_type=certificate` | 290 | — | — | EXCLUDED |

Accessories Family is **not** auto-created.

---

## 5. Category mappings

| Metric | Value |
|--------|------:|
| WP taxonomy terms reviewed | 207 |
| Auto-created Categories | **0** |
| Mapping status | REVIEW / SOURCE_ONLY / EXCLUDED by concept |

Concept classes: internal category candidate, voltage, application, industry, customer navigation, certificate region, obsolete.

Duplicate/HTML-entity/spacing variants produce **canonicalization proposals only** — no automatic merge.

---

## 6. Series mappings

| Metric | Value |
|--------|------:|
| Proposed Series (valid styles) | 390 |
| Existing Series matches | 0 |
| Status | REVIEW |
| Auto-create | **false** |

Comma/numeric/blank styles excluded from Series proposals.

---

## 7. SpecificationUnits status

| Check | Result |
|-------|--------|
| Expected | `[product].[SpecificationUnits]` from Phase 11 / `create-product-schema.sql` |
| Actual | **MISSING** (`Invalid object name 'product.SpecificationUnits'`) |
| Severity | **MIGRATION_BLOCKER** |
| Apply in 12.6 | **NO** |

Additive plan (Phase 12.7 approval):

1. Apply idempotent `IF OBJECT_ID ... CREATE TABLE` block for SpecificationUnits.
2. Seed canonical units (`mm`, `mm²`, `kg`, `kg/km`, `A`, `V`, `°C`, `m`, …).
3. Do not drop existing Product data.
4. Keep Pricing UOM conceptually separate even if FKs share the registry.

---

## 8. Specification mappings

| Status | Count |
|--------|------:|
| MATCHED | 0 |
| NEW_DEFINITION_REQUIRED | 24 |
| REVIEW | 38 |

Heuristic name matches are **SUGGESTED only** — never operator-approved.

---

## 9. d1–d7 / v1–v4 mappings

Keyed by **legacy style + source field** (no global `d1 = …` unless proven).

| Metric | Count |
|--------|------:|
| Style-field combinations | 764 |
| Resolved (SUGGESTED from `spec_labels`) | 759 |
| Review | 5 |
| Unmapped | 0 |

Migration must still block values until operators approve SUGGESTED → mapped.

---

## 10. Product vs Variant scope

Accessory field evidence (`accessory_specs` × `tpro_ext.style`) reports candidate PRODUCT vs VARIANT scope per field with usage distribution. Shared values are **not** auto-promoted to Product scope. Status: **REVIEW**.

---

## 11. Units

| Metric | Value |
|--------|------:|
| Candidate unit mappings prepared | 9 |
| Mapped to existing SpecificationUnit | 0 |
| Status | **BLOCKED** (table missing) |

---

## 12. Options

| Metric | Value |
|--------|------:|
| Proposed option values (from tc audits) | 191 |
| Auto-create | **false** |
| Status | REVIEW |

---

## 13. Standards

Exploded from `standards`, `standards_content`, `standards_content_al`.

| Metric | Value |
|--------|------:|
| Distinct parsed candidates | 78 |
| Auto-merge aliases | **false** |

---

## 14. Approvals

No dedicated Product `approvals` meta key. Candidates derived from certificate CPT titles/categories.

| Metric | Value |
|--------|------:|
| Distinct candidates | 207 |
| Auto-merge | **false** |

---

## 15. Core Colours

Target definition `CORE-COLOURS` (long text) retained.

Live ACF `core_colours*` scalar values: **empty**. Colour matrices appear to be attachment/ID driven (`control_core_*`, `multi_core_*`, `single_core_*`). Classification:

| Class | Implication |
|-------|-------------|
| RAW_TEXT_REQUIRED | Default until filesystem/matrix reconstruction proven |
| VISUAL_EDITOR_COMPATIBLE | Only after round-trip proof |
| Do not force | `CODE_CORE_COLOUR` single-select for full matrices |

---

## 16. Certificate classification

290 certificate posts — **not Products** (`EXCLUDED` from Product migration).

| Classification | Count |
|----------------|------:|
| SHARED_DOCUMENT | 261 |
| APPROVAL_EVIDENCE | 20 |
| UNRESOLVED | 9 |
| PRODUCT_DOCUMENT | 0 |

No certificate migration in this phase.

---

## 17. Catalogue Navigation reconciliation

Internal classification remains separate from customer navigation.

| Action | Count |
|--------|------:|
| Existing CatalogueNodes KEEP | 97 |
| Proposed ADD (frontend panel gaps) | 2 |
| Auto rebuild/delete | **NO** |

Frontend panel sources (Products, Special Applications, Accessories, ALI-FLEX, Plugs & Sockets, Industrial, …) included in reconciliation. Existing node images preserved.

---

## 18. Product placements

Accessory products proposed for Accessories node placement (**REVIEW**). Placements are separate from internal Category assignment. One Product may map to several nodes.

---

## 19. Media readiness

| Check | Result |
|-------|--------|
| `LEGACY_FILES_ROOT` | **UNAVAILABLE** (waived) |
| Media readiness | **BLOCKED** |
| Uploads | **NO** |

Proposed roles: `primary`, `gallery`, `cross-section`, `diagram` (uncertain → REVIEW when FS available).

---

## 20. Document readiness

| Check | Result |
|-------|--------|
| Status | **BLOCKED** (depends on filesystem) |
| Copy files | **NO** |

Targets when ready: ProductDocuments / shared / certificate evidence / exclude.

---

## 21. Existing Azure Product reconciliation

| Classification | Count |
|----------------|------:|
| REAL_OR_MIGRATED | 100 |
| DEVELOPMENT_SAMPLE | 11 |
| DEBUG | 7 |
| MANUAL_REAL | 0 |
| UNKNOWN | 0 |
| **Deleted** | **0** |

Deletion permission is **never** inferred from classification.

---

## 22. tpro exclusions

| Population | Count | Intent |
|------------|------:|--------|
| tpro rows | 92,620 | Not 92,620 Products |
| tpro_ext rows | 18,995 | Variant candidates |
| Joined (`ida = product_code`) | 11,383 | REVIEW candidates |
| tpro-only | 81,247 | **EXCLUDED** (commerce/historical/stock) |
| Prefix identity | Forbidden | INVALID if attempted |

---

## 23. Product readiness

Eligibility for READY: identity + classification + Series + required Product specs + no unresolved collision.

Media/documents tracked separately; Draft-before-assets is allowed architecturally, but media is currently **BLOCKED** for full catalogue readiness.

Partial READY subset policy: supported for a future controlled migration; **not executed** in 12.6.

---

## 24. Variant readiness

| Status | Count |
|--------|------:|
| READY | 0 |
| REVIEW | 18,975 |
| BLOCKED | 20 |
| EXCLUDED | 0 |

READY requires parent Product READY + valid unique VariantCode + mapped Variant specs/units/options + no collision.

---

## 25. Mapping coverage (dimension scores)

| Dimension | Score | Status |
|-----------|------:|--------|
| Product identity | 5.4% | NOT_READY |
| Classification | 50% | NOT_READY |
| Series | 0% | NOT_READY |
| Product specifications | 0% | NOT_READY |
| Variant identity | 0% | NOT_READY |
| Variant specifications (d/v suggested) | 99.3% | NOT_READY (await approval) |
| Units | 0% | **BLOCKED** |
| Options | 0% | NOT_READY |
| Catalogue placement | 0% | NOT_READY |
| Media | 0% | **BLOCKED** |
| Documents | 0% | **BLOCKED** |
| Existing collision | MATCH 24 / CREATE 424 | REVIEW |

---

## 26. Blockers

| Priority | Code |
|----------|------|
| MIGRATION_BLOCKER | SPECIFICATION_UNITS_MISSING |
| MIGRATION_BLOCKER | MEDIA_FILES_UNAVAILABLE |
| MIGRATION_BLOCKER | DV_FIELD_MAPPINGS_INCOMPLETE (approval pending) |
| REQUIRED | ACCESSORIES_FAMILY_MISSING |
| REQUIRED | WP_TAXONOMY_NOT_CLASSIFICATION |
| REQUIRED | ACCESSORY_PRODUCT_CODE_JOIN_ZERO |
| REQUIRED | IDENTITY_MATCH_RATE (45.7% exact style; many SOURCE_ONLY) |

---

## 27. Proposed master-data changes (do not execute)

| Item | Priority |
|------|----------|
| Create/seed `SpecificationUnits` | MIGRATION_BLOCKER |
| Create Family `ACCESSORIES` | REQUIRED |
| Operator-selected Categories (not 1:1 with WP terms) | REQUIRED |
| Operator-selected Series for valid styles | REQUIRED |
| New Specification Definitions | REQUIRED |
| Specification Options / aliases | RECOMMENDED |
| Catalogue node ADD/UPDATE reviews | RECOMMENDED |
| Cosmetic category label merges | OPTIONAL |

---

## 28. Performance

| Metric | Value |
|--------|------:|
| Queries | 232 |
| Duration | 3.1s |
| Pattern | Batched aggregates / joins (no per-Product chatty loops) |
| Persistence | JSON staging under `phase12.6-mappings/` (Admin reuses API; no legacy write-back) |

---

## 29. Tests

| Suite | Result |
|-------|--------|
| `StyleCodeNormalizerTest` | PASS (comma→REVIEW, blank→INVALID, numeric→REVIEW, statuses explicit) |
| `CatalogueMappingReadinessServiceTest` | PASS (offline NOT READY, units/media BLOCKED, no writes) |
| Existing legacy read-only guard tests | Remain required green |

Admin UI: `/admin/products/migration` → **mapping** tab (section filters + READY/REVIEW/… queue). Permission: `PRODUCT.MIGRATION.MANAGE`.  
API: `GET /api/v1/admin/product-migration/catalogue-mapping-readiness`

---

## 30. Recommendation for next phase

1. **STOP** — do not run Product migration dry-run.
2. Approve Phase **12.7** master-data fix/seed for:
   - SpecificationUnits schema + seeds
   - Accessories Family
   - Operator-approved classification/Series/spec/option mappings
   - Accessory identity join resolution
3. Mount `LEGACY_FILES_ROOT` before clearing media/document blockers.
4. Only then Phase **12.8** controlled Product catalogue dry-run on the READY subset.
5. Pricing migration remains stopped until catalogue migration reconciles.

---

## Completion criteria checklist

1. Legacy read-only — YES  
2. Existing engine reused — YES  
3. Explicit readiness statuses — YES  
4. No prefix identity — YES  
5. Comma styles quarantined — YES  
6. Accessory strategy explicit — YES (join currently 0 → REVIEW)  
7–9. Family/Category/Series proposals — YES (no auto-create)  
10. SpecificationUnits understood + plan — YES (not applied)  
11–13. Spec / d/v / scope — YES  
14–17. Units / Standards / Approvals / Core Colours — YES  
18–20. Certificates / Catalogue separate / existing nav preserved — YES  
21–22. Azure Products classified not deleted; tpro filtered — YES  
23–25. Product/Variant readiness + READY subset identifiable — YES  
26–28. No Product/price/media migration — YES  
29. Tests pass for new readiness suite — YES  
30. Master-data proposal reviewable — YES  
