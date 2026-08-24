# Phase 12.7 Batch A — Classification (Categories + Series)

**Mode:** REVIEW + DRY-RUN  
**Target:** tricab-mel / tc (NON-PRODUCTION)  
**Apply status:** NOT RUN — waiting for explicit Batch A apply approval  

This package establishes internal Product classification master-data *proposals* and migration mappings. It does **not** migrate Products or Variants.

## 1. Existing target classification

Inspected from discovery cache (+ Phase 12.7 ACCESSORIES family apply):

| Entity | Codes |
| --- | --- |
| Families | `CABLE`, `PLUG`, `ACCESSORIES` |
| Categories | `FLEX-POWER`, `CTRL-MULTI`, `IND-PLUG`, `IND-SOCKET`, `INSTRUMENTATION`, `VFD`, `SINGLE-CORE` |
| Series | `TC-FLEX`, `J-SERIES` |

Preserve baseline: Products **118**, Variants **4178**, Catalogue Nodes **97**.

Live Azure login timed out during this Batch A run; dry-run used the discovery snapshot plus the confirmed ACCESSORIES family from the scoped Phase 12.7 apply. Re-run with live Azure when connectivity allows (`php bin/apply-product-migration-classification.php --dry-run --batch-a`).

## 2. Legacy taxonomy inventory

Source: Phase 12.6 `category-mappings.proposed.json` (WordPress product taxonomies for cable + accessory; certificate CPT not treated as Product classification).

## 3. Semantic term classification

Every legacy term is classified into one of:

`PRODUCT_CATEGORY | APPLICATION | INDUSTRY | VOLTAGE | REGION | MARKETING_GROUP | CATALOGUE_NAVIGATION | LEGACY_METADATA | OBSOLETE | UNKNOWN`

Only `PRODUCT_CATEGORY` may become an internal `ProductCategory`.

Offline dry-run semantic counts (approx.):

| Type | Count |
| --- | ---: |
| PRODUCT_CATEGORY | 128 |
| NOT product (voltage/nav/app/…) | 77 decisioned as NOT_A_PRODUCT_CATEGORY |
| OBSOLETE → REJECT | 2 |
| VOLTAGE | 37 |
| APPLICATION | 19 |
| CATALOGUE_NAVIGATION | 15 |
| REGION | 4 |

## 4. Category decisions

| Decision | Count |
| --- | ---: |
| APPROVED_MAP_EXISTING | 1 |
| APPROVED_CREATE | 0 |
| REVIEW | 127 |
| NOT_A_PRODUCT_CATEGORY | 77 |
| REJECT | 2 |
| CONFLICT | 0 |

**APPROVED_MAP_EXISTING:** WordPress `VFD/VSD` (term 373) → existing `VFD` (exact normalized name). Duplicate VFD/VSD terms demoted to REVIEW.

**APPROVED_CREATE:** 0 — no automatic creates. Operator must approve create with Family + stable code.

## 5. Category duplicates / conflicts

22 normalized duplicate groups (e.g. repeated `110V`, `Power & Control` / `Power and Control`, duplicate `Glands`). Voltage duplicates → `NOT_A_PRODUCT_CATEGORY`. Product-category duplicates stay REVIEW for operator consolidation. No silent `-2` suffixing.

## 6. Family assignment

Suggested Family for PRODUCT_CATEGORY candidates:

- Accessories-like terms (glands, lugs, heat shrink, connectors…) → `ACCESSORIES`
- Plug/socket product terms → `PLUG`
- Otherwise → `CABLE`

Suggestions are **not** auto-approved. `PLUG` and `ACCESSORIES` remain separate.

## 7. Series eligibility rules

A style becomes Series only when:

1. Product identity valid  
2. style_code valid (not blank/comma/junk)  
3. Family known  
4. Category approved  
5. Style represents a real series  
6. No unresolved collision  

Numeric `tc` styles (1, 10, 11…) → REVIEW (`numeric_tc_cable_style_uncertain_not_auto_series`). Composite styles (`B3C, B3M`) → INVALID.

## 8. Series decisions

| Decision | Count |
| --- | ---: |
| APPROVED_MAP_EXISTING | 0 |
| APPROVED_CREATE | 0 |
| REVIEW | 390 |
| INVALID | 0 (in current 390 proposal set; quarantine rules covered by tests) |
| SOURCE_ONLY | 0 |
| REJECT | 0 |

All 390 remain REVIEW because parent Category is unresolved. Existing `TC-FLEX` / `J-SERIES` were not present as exact style codes in the proposal set.

## 9. Invalid / composite style handling

Implemented in `StyleCodeNormalizer` + Batch A series planner. Never split comma values. Never invent Series from numeric IDs alone.

## 10. Product classification preview

Chain: Family → Category → Series. Statuses: `CLASSIFICATION_READY | CLASSIFICATION_REVIEW | CLASSIFICATION_INVALID`.

Current artifact product rows are sparse → preview mostly REVIEW. Full product-level preview improves when live readiness product rows are available; classification still does not mutate Products.

## 11. Review UI

Admin → Product Migration → **batch-a** tab:

- Categories / Series / Product Preview / Conflicts / Dry-run  
- Operator actions require `PRODUCT.MIGRATION.MANAGE`  
- Master-data create at apply time additionally requires `PRODUCT.LOOKUPS.MANAGE`  
- Bulk validate only for evidence-equivalent rows; refuses large series bulk

## 12. Mapping persistence

Decisions write through existing `migration.ClassificationMappings` (source system, legacy key, taxonomy, target family/category/series public IDs, status, notes with decision/reason/approvedAt).

## 13. Dry-run

```bash
php bin/apply-product-migration-classification.php --dry-run --batch-a --confirm-non-production
# offline / no Azure:
php bin/apply-product-migration-classification.php --dry-run --batch-a --offline
```

`--apply` is **refused** until separate explicit Batch A apply approval.

Artifacts: `core/docs/phase12.7/batch-a-*.json`

## 14. Approved apply sets

- `batch-a-approved-categories.json` — only APPROVED_MAP_EXISTING / APPROVED_CREATE  
- `batch-a-approved-series.json` — only approved rows with resolved Category  

Current: **1** category map, **0** series.

## 15. Apply result

Not run.

## 16. Idempotency

Dry-run planning is deterministic. After a future approved apply, second apply must report zero unexpected creates (covered by planning tests; apply executor not enabled).

## 17. Readiness before/after

| Metric | Before Batch A | After (projected, apply not run) |
| --- | ---: | ---: |
| Products READY | 24 | 24 (unchanged — classification mappings alone do not promote READY) |
| Categories mapped | 0 | 1 (if apply approved for VFD map) |
| Series mapped | 0 | 0 |
| Full catalogue dry-run | NOT READY | NOT READY |

## 18. Remaining classification blockers

1. Category unresolved (~127 PRODUCT_CATEGORY candidates)  
2. Series unresolved (390 pending approved Category)  
3. Accessory identity still 0 (`product_code` join) — out of Batch A scope  
4. Specifications / d–v mappings — Batch B/C  

## 19. Recommendation for Batch B

Do **not** start Batch B until:

1. Operators finish Category review for true PRODUCT_CATEGORY terms  
2. Explicit Batch A apply approval (if any approved create/map rows)  
3. Series approvals only after Category parents resolve  

Batch B = Specification Masters (separate approval).

## Tests

`core/tests/Product/Migration/BatchA/PlanBatchAClassificationServiceTest.php` — semantic classification, category decisions, duplicates, series parent requirement, bulk safety, idempotent planning.

Frontend: `ProductMigrationPage.test.tsx` Batch A views.

## Batch A.2 — Final Category Approval + Series Resolution

**Mode:** Category operator-approval package + Series re-evaluation + live dry-run plan  
**Apply status:** NOT RUN (`BATCH_A_APPLY_NOT_APPROVED`)  
**CLI:** `php bin/resolve-batch-a2-classification.php --confirm-non-production`

### Live target (revalidated)

Families 3 · Categories 7 · Series 2 · Products 118 · Variants 4178 · Catalogue Nodes 97  
Legacy writes: 0

### Category evidence highlights

- **Conduits & Fittings vs Flexible Conduit:** 10/10 Flexible Conduit products overlap Conduits & Fittings → `KEEP_CONDUITS_FITTINGS_ONLY` (Flexible Conduit → NOT_A_PRODUCT_CATEGORY).
- **Glands vs Conduit Glands:** 0 shared products; Glands (D-series cable glands) remain PROPOSED_CREATE; Conduit Glands (F01–F04) → REVIEW (absorb into conduits fittings / series).
- **Bootlace / Ferrule:** WP child of Terminals → REVIEW as Series-under-Terminals (not a second Category).
- **Cable maps (APPROVED_MAP_EXISTING, not applied):** Flexible Cables → FLEX-POWER; Instrumentation* → INSTRUMENTATION; Power and Control → CTRL-MULTI; VFD aliases → VFD.
- **VFD:** multiple **source mappings** → **one** target Category `VFD` (not applied).
- **APPROVED_CREATE:** 0 — operator must Approve Create in UI for PROPOSED_CREATE accessories.

### Series

390 candidates re-evaluated. With cable Category maps unlocked, 54 styles become PROPOSED_CREATE (parent Category approved via MAP_EXISTING). Remainder blocked on unresolved Categories. No auto APPROVED_CREATE. Composite/numeric protections retained.

### Artifacts

- `core/docs/phase12.7/batch-a2-category-final.json`
- `core/docs/phase12.7/batch-a2-series-resolution.json`
- `core/docs/phase12.7/batch-a2-dry-run.json`
- `core/docs/phase12.7/batch-a-approved-categories.json` (MAP_EXISTING / APPROVED_CREATE only)
- `core/docs/phase12.7/batch-a-approved-series.json`

### Recommendation

CONTINUE CLASSIFICATION REVIEW until operator Approve Create for accessory Categories and Series, then re-run live dry-run before any `--apply`.


## Batch A.3 — Operator Approval + Final Apply Package

**CLI:** `php bin/resolve-batch-a3-operator-approval.php --confirm-non-production`  
**Apply:** COMPLETED (controlled non-production apply — see section below)

- Techno Connectors Family decision: **PLUG** (J90–J97 plug/socket/junction; WP parent Plugs & Sockets).
- Seventh PROPOSED_CREATE row was duplicate **GLANDS** term 542 → one target create code.
- Operator package approved 6 unique Category creates + eligible Series creates under approved Categories.
- Readiness split: `CLASSIFICATION_READY` vs `FULL_PRODUCT_MIGRATION_READY` (Accessories identity blocker).
- Artifacts: `batch-a3-final-package.json`, `batch-a3-operator-decisions.json`, `batch-a-classification-ready-products.json`, `batch-a-classification-review-products.json`, `batch-a3-dry-run.json`.

## Batch A Controlled Non-Production Apply

**Timestamp (UTC):** 2026-08-21T00:12:38Z (apply start)  
**Target:** tricab-mel / tc / NON-PRODUCTION  
**CLI:** `php bin/apply-product-migration-classification.php --apply --batch-a --confirm-non-production --batch-a-apply-approved`

### Pre-apply counts

| Metric | Count |
| --- | ---: |
| Families | 3 |
| Categories | 7 |
| Series | 2 |
| Products | 118 |
| Variants | 4178 |
| Catalogue Nodes | 97 |

### Approved scope applied

- 27 APPROVED_MAP_EXISTING Category source mappings
- 6 unique Category creates (7 APPROVED_CREATE source rows; duplicate GLANDS → one target)
- 34 Category mapping rows inserted
- 95 APPROVED_CREATE Series created
- 95 Series mapping rows inserted
- Product / Variant / Catalogue / Spec / Pricing / Media / Document mutations: **0**
- Legacy WordPress / tc writes: **0**

### Categories created

| Code | Name | Family |
| --- | --- | --- |
| CONDUITS-FITTINGS | Conduits & Fittings | ACCESSORIES |
| TECHNO-CONNECTORS | Techno Connectors | PLUG |
| TERMINALS | Terminals | ACCESSORIES |
| GLANDS | Glands | ACCESSORIES |
| LINKS | Links | ACCESSORIES |
| PLUG-SOCKET-LEADS | Plug-Socket Leads | ACCESSORIES |

### Post-apply counts

| Metric | Count |
| --- | ---: |
| Families | 3 |
| Categories | 13 |
| Series | 97 |
| Products | 118 |
| Variants | 4178 |
| Catalogue Nodes | 97 |

### Idempotency

Second identical apply: **PASS** (0 Category creates, 0 Series creates, 0 mapping inserts, 0 mapping updates).

### Post-apply readiness (live recalculation)

| Metric | Count |
| --- | ---: |
| CATEGORY_READY | 95 |
| SERIES_READY | 95 |
| CLASSIFICATION_READY | 95 |
| CLASSIFICATION_REVIEW | 295 |
| CLASSIFICATION_INVALID | 0 |
| FULL_PRODUCT_MIGRATION_READY | 54 |
| FULL_PRODUCT_MIGRATION_BLOCKED | 336 |
| ACCESSORY_IDENTITY blockers | 41 |

Associated legacy variant sum for classification-ready products: **7901** (analysis only — not imported).

### Artifacts

- `core/docs/phase12.7/batch-a-apply-report.json`
- Regenerated readiness via `bin/resolve-batch-a3-operator-approval.php` after apply

### Next action

**STOP — WAIT FOR EXPLICIT APPROVAL.** Batch B / Product / Variant / Spec / Pricing / Media migration not started.

