# PHASE 13.3 — Legacy Base Cost Migration Dry Run

**Status:** COMPLETE (strict zero writes)  
**Generated:** 2026-08-26  
**CLI:** `php bin/product-pricing-phase13.3-dry-run.php --confirm-non-production`

**Decision:**

```text
PHASE_13_4_READY
```

(Means: dry-run is deterministic enough to design a controlled apply/pilot — **not** apply immediately.)

---

## 1. Safety

```text
APP_ENV: development
Server: tricab-mel
Database: tc
Production: NO
Login: thilanh@tricab.com

WordPress writes: 0
Legacy tc writes: 0
Azure ProductPrices writes: 0
Azure ProductVariantPrices writes: 0
Azure pricing writes total: 0
Blob writes: 0
Forbidden write attempts: 3 (INSERT/UPDATE/DELETE samples rejected by LegacyReadOnlySqlGuard)
```

---

## 2. Scope

```text
Included:
2507 / TCA / AUD
2517 / TCUS / USD

Excluded:
2519 / TCASIA  (22,475 source rows skipped)
```

---

## 3. Reference Data

```text
ExternalSystem legacy-tc: MISSING
TCA-BASE: CONTRACT_ONLY
TCUS-BASE: CONTRACT_ONLY

Resolution mode: CONTRACT_ONLY
```

Dry-run used approved **priceListCode / currencyCode / dbco** contracts without physical PriceListId values.

**Seed required before apply:** YES (`SEED_APPROVAL_REQUIRED` for TCA-BASE + TCUS-BASE + legacy-tc mappings). Environment is **not** apply-ready until seeded.

---

## 4. Source Inventory

| Metric | Count |
|--------|------:|
| Legacy source rows scanned | 53,216 |
| dbco 2507 | 22,471 |
| dbco 2517 | 8,270 |
| Excluded 2519 | 22,475 |
| Distinct code+dbco groups (scoped) | 29,760 |

---

## 5. Latest / Duplicate Resolution

| Metric | Count |
|--------|------:|
| Latest logical groups | 29,760 |
| Single latest groups | 29,040 |
| Safe duplicate groups | 720 |
| Collapsed source rows | 981 |
| Differing-cost same-date groups | **0** |

Policy: `LATEST_ONLY` + `SAFE_EQUIVALENT_DUPLICATE` collapse.

---

## 6. Identity Results

| Type | Count |
|------|------:|
| EXACT | 4,022 |
| EXPLICIT | 0 |
| MASK source records | 0 |
| MASK-derived variant resolutions | 0 |
| UNRESOLVED (legacy codes) | 25,738 |
| AMBIGUOUS | 0 |
| Exact-over-mask overrides | 0 |

Notes:
- Exact 4,022 = resolutions (mostly TCA + 2 TCUS), covering **4,020** Azure variants.
- No in-scope Azure variant required mask fallback after exact miss (mask sources exist globally but do not currently attach to the 158 unresolved Azure variants).

---

## 7. Target Variant Coverage

```text
Azure variants:              4,178
with price candidate:        4,020
  READY:                     4,020
  REVIEW:                        0
  BLOCKED:                       0
no candidate:                  158

coverage %:                  96.22
inScopePricingIdentityCoverage: 96.22
```

---

## 8. Mask Analysis

```text
mask source rows matched to Azure variants: 0
mask-derived target variants: 0
exact-over-mask overrides: 0
unused masks: large (legacy XXX codes without in-scope Azure family)
mask collisions: 0
```

Unresolved Azure variants with a usable 2507/2517 mask: **0**.

---

## 9. Cost Quality

Among **materialized** resolutions:

```text
positive: 4,220
zero: 2 (both UNRESOLVED/SKIPPED samples — not READY)
negative: 0
missing: 0
invalid: 0
```

Scoped legacy zero **rows** still present in source (not catalogue-matched):

```text
2507: 47
2517: 674
```

Policy remains: zero ≠ free; zero never READY. Matched READY set has **no** zero costs.

---

## 10. PriceList Breakdown

| PriceList | Currency | Candidate resolutions (materialized) | READY |
|-----------|----------|--------------------------------------:|------:|
| TCA-BASE | AUD | 4,220 | 4,020 |
| TCUS-BASE | USD | 2 | 2 |

**Observation:** Azure catalogue identity overwhelmingly matches **TCA (2507)** base costs. Only **2** TCUS READY candidates. A TCUS pilot needs separate coverage review before broad US apply.

---

## 11. Existing Target Comparison

```text
CREATE_CANDIDATE: 4,022
NO_CHANGE: 0
UPDATE_OR_REVISION_CANDIDATE: 0
MANUAL_DATA_CONFLICT: 0
```

Azure ProductVariantPrices currently empty for these candidates.

---

## 12. Collision Analysis

```text
TARGET_PRICE_COLLISION: 0
DIFFERING_SAME_DATE_COST: 0
```

Detail artifact: `tmp/phase13.3-base-cost-collisions.json` (empty).

---

## 13. Unresolved Review

**Legacy codes** without Azure identity: 25,738 → SKIPPED (`LEGACY_CODE_NOT_RELEVANT_TO_CURRENT_CATALOGUE`). Not migration failures.

**Azure variants** with no 2507/2517 candidate: 158

| Class | Count |
|-------|------:|
| NO_EXACT_OR_MASK | 142 |
| SYNTHETIC_OR_ODD (VC-*, SAMPLE, numeric) | 16 |
| HAS_MASK_IN_2507_2517 | 0 |

Samples: `1.5`, `2.5`, `DBG-V1-2E50`, `VC-…`, `BHA-PATD/1C50BK`, …

Do **not** auto-create explicit mappings.

---

## 14. Zero-Cost Review

```text
zero cost != free
```

Matched READY candidates contain no zeros. Global scoped zeros (721 rows) are almost entirely on unmatched legacy codes → SKIPPED, not READY.

---

## 15. Migration Candidate Summary

| Status | Count | Meaning |
|--------|------:|---------|
| READY | 4,022 | Safe candidates for a later approved apply (mostly TCA) |
| REVIEW | 0 | None among matched targets |
| BLOCKED | 0 | No collisions / ambiguous identities in this dry-run |
| SKIPPED | 25,738 | Out of catalogue / superseded / 2519 excluded at source |

---

## 16. Proposed Apply Contract (Phase 13.4 — not implemented)

For READY only, after seed:

```text
ProductVariantId
PriceListId          // from seeded TCA-BASE / TCUS-BASE
CurrencyId           // AUD / USD
UnitPrice = baseCost
EffectiveFromUtc     // normalized dc (YYYY-MM-DDT00:00:00Z)
EffectiveToUtc = null
Source = legacy-tc
legacy code + dbco + source row ids
```

No customer sell price. No tmktm. No MAKE formula.

---

## 17. Phase 13.4 Blockers

| Item | Class |
|------|--------|
| PriceList / ExternalSystem seed not applied | **PHASE_13_4_PREREQUISITE** |
| Operator apply approval | Required |
| TCUS coverage only 2 READY | BUSINESS / pilot-scope review |
| 158 Azure variants unresolved | Explicit mapping backlog (optional) |
| Migration ownership / source metadata on apply | Design in 13.4 |
| Global unmatched legacy zeros | Non-blocking for READY set |

No identity collisions block a **TCA-focused** pilot once seed + approval exist.

---

## Artifacts

```text
tmp/phase13.3-base-cost-dry-run-summary.json
tmp/phase13.3-base-cost-dry-run-details.json
tmp/phase13.3-base-cost-unresolved.json
tmp/phase13.3-base-cost-zero-review.json
tmp/phase13.3-base-cost-collisions.json
docs/phase13.3-base-cost-dry-run-report.md  (this file)

Engine:
core/src/Product/Migration/Pricing/BuildLegacyBaseCostDryRunService.php
bin/product-pricing-phase13.3-dry-run.php

Tests: Phase133BaseCostDryRunServiceTest (9) + Phase 13.2/13.2A suite — OK
```

---

## FINAL READINESS DECISION

```text
PHASE_13_4_READY
```

Ready only to **design** a controlled non-production pilot apply — not to migrate now.

---

## HARD STOP

```text
STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL
```

Do **not** apply pricing.  
Do **not** seed without approval.  
Do **not** populate ProductVariantPrices.  
Do **not** migrate tmktm / sell prices / MAKE / public pricing.

**Next (only if explicitly approved):**

```text
PHASE 13.4 —
Controlled Base Cost Pilot Apply
NON-PRODUCTION · SCOPED · TRANSACTIONAL · RECONCILABLE · ROLLBACK-SAFE
```

Recommended first pilot scope: **TCA-BASE / 2507 / AUD** READY subset (optionally style-limited).
