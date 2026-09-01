# TRICAB CABLE CALCULATOR — EXACT LEGACY PARITY REPORT

Date: 2026-09-01  
Scope: Engineering parity + product resolution separation (no production deploy)

---

## A. Engineering Dataset

| Item | Value |
| --- | --- |
| Source | `tc.rating` + `tc.cable_specs` verified runtime snapshots |
| Path | `tricab-api/resources/cable-calculator/v1/` |
| datasetVersion | `v1` / API metadata `legacy-tc-2026-08` |
| engineVersion | `native-v1` |
| parityBaseline | `LOCAL_LEGACY_PARITY_BASELINE` |
| rating rows | 557 |
| cableSpecs rows | 9338 |
| ratingSha256 | `8c9725037531fb90fc8cadd084e3a8dc65e741a99511756ae1f4d8b19f813704` |
| cableSpecsSha256 | `e63cac808caf7472f349b2cee5e78d65d0ba18e45ef97be6fb382282d6a8c178` |

Product Module is **not** used for sizing.

---

## B. Calculation Parity

| Suite | Result |
| --- | --- |
| Native vs LegacyOracle matrix fixtures | **108 / 108 PASS** |
| Spot loads 1–400 A | **8 / 8 PASS** |
| Spot voltages 24 / 48 / 240 / 400 / 1000 V | **5 / 5 PASS** |
| Presenter / display tests | PASS |
| Product resolution independence tests | PASS |
| fixture-003 | Kept isolated from this gate (historical stale assertion; not used as oracle expected output) |

Failed: **0**  
Blocked: **0** (matrix Erh=0 cases remain excluded as invalid input)

---

## C. 1 A / 100 m Case

See also: `docs/cable-calculator/1a-100m-field-comparison.md`

| Band | Active | Earth | cr | vd | loop Z | frA | frE | space | weight | qty | multiple | product active | product earth |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- | --- |
| 90 XL | 6 | 2.5 | **49** | 0.13 | 1432.1 | 0.86 | 0.34 | 15 | 0.368 → display 0.4 | 400 | 4 | XL-PAXN/1C6OR | LP-XXXD/1C2.5EA |
| 110 KL | 6 | 2.5 | **61** | 0.13 | 1432.1 | 0.79 | 0.34 | 15 | 0.368 → display 0.4 | 400 | 4 | KL-PAXN/1C6OR | LP-XXXD/1C2.5EA |

Display layer also derives drop V = `vd% × voltage / 100` = **0.52 V** from the same vd (not a separate formula).

---

## D. Current Rating Display

**Finding:** Legacy `tc-web/.../functions/ui.php` renders:

```php
$array_90['cr'] . "A"
```

with **no rounding / no nearest-10**.

| Source | 90 °C | 110 °C |
| --- | ---: | ---: |
| Engine / oracle raw | 49 | 61 |
| Legacy HTML | 49A | 61A |
| New display (`CableCalculationPresenter`) | 49A | 61A |
| Screenshot / verbal ~50 / ~60 | approximation | approximation |

Classification of 49↔50 / 61↔60: **LEGACY_HTML_TRANSCRIPTION** (not ENGINE_DIFFERENCE, not DISPLAY_ROUNDING).

Proven display transforms only:

- `ot` → `round(ot)`
- `weight` → `round(weight, 1)`
- `space` → strip trailing `.0`
- `cr` / `vd` / fault / loop → raw

---

## E. Dual Bands

Both bands returned independently for the 1 A / 100 m case:

- **90 °C XL** — own cr / ot / vd / fault / product codes / weight / space / qty
- **110 °C KL** — own cr / ot / vd / fault / product codes / weight / space / qty

UI card renamed from “Comparison / Compare cable sizes” → **Calculation Results / Cable options**.

---

## F. Engineering Fields

All compared parity fields match oracle for matrix + spot cases, including:

`style`, `xa`, `earth_size`, `xae`, `runs`, `cr`, `ot`, `vd`, `fault-loop`, `fr-active`, `fr-earth`, `space`, `weight`, `qty`, `multiple`, `productCodeActive`, `productCodeEarth`, `nc`, `ne`, `earth_ne`.

---

## G. Product Resolution

Coverage audit (`docs/cable-calculator/product-code-coverage-report.md`, `data/cable-calculator/product-code-coverage.json`):

| Metric | Count |
| --- | ---: |
| Total calculator product codes (dataset-derived) | 17995 |
| Active cable codes | 17928 |
| Earth cable codes | 67 |
| Resolved in Product Module | 0* |
| Not migrated | 17995* |
| Ambiguous | 0 |
| Inactive | 0 |

\* Lookup mode for this run: `no-product-db` (local Azure SQL not connected). When DB is unavailable / code missing → `NOT_MIGRATED`. No substitute products.

Resolver: exact `product.ProductCodes.CodeValue` after calculation (`AzureSqlCableCalculatorProductResolver` / `NullCableCalculatorProductResolver`).

---

## H. Missing Product Behaviour

Proven:

1. Null / disabled resolver → HTTP calculation still **COMPLETE**
2. Engineering bands identical whether resolution returns `NOT_MIGRATED` or fake `RESOLVED`
3. API swallows resolver exceptions; calculation still succeeds
4. UI shows “Product catalogue record not yet available” — not a calculation error

---

## I. UI

- Modern TriCalc UI preserved
- Dual band **Calculation Results**
- Primary metrics + expandable **Technical details** (fault, loop Z, space, weight, multiple, qty, product codes)
- Product catalogue block with View product / NOT_MIGRATED neutral message
- Raw engineering values remain authoritative; display fields come from presenter

---

## J. Remaining Parity Differences

**None engineering-significant.**

Documented only:

1. Human screenshot recall of ~50 A / ~60 A vs true legacy HTML `49A` / `61A` (transcription)
2. Product Module coverage incomplete → `NOT_MIGRATED` (expected; does not alter engineering)
3. Coverage earth-code enumeration is limited to LP/LS/UP style rows in specs (generator-based audit; not a calc mismatch)

---

## K. Verdict

**EXACT_LEGACY_ENGINEERING_PARITY**

Acceptance gate notes:

- Calculation independent of Product Module completeness — YES  
- Regression fixtures pass — YES (108/108)  
- Engineering-significant fields match — YES  
- Dual bands / earth / runs / fault / loop Z / product codes match — YES  
- Missing migrated product does not alter result — YES  
- Legacy HTML formatting differences reproduced or documented — YES  

---

## STOP

No production deploy. Awaiting explicit approval for the next phase.
