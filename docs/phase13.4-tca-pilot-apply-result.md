# PHASE 13.4 — TCA BVA Pilot Apply Result

**Generated:** 2026-08-26  
**Mode:** APPROVED LIVE PILOT (non-production)

---

## Decision

```text
PILOT_APPLY_SUCCESS
```

---

## Safety

```text
Environment: development
Server: tricab-mel
Database: tc
Production: NO

Legacy writes: 0
Azure pricing writes: 20 (first apply)
Other target writes: 3 reference rows (scoped TCA only)
WordPress writes: 0
Blob writes: 0
```

---

## Reference records

| Record | Result |
|--------|--------|
| ExternalSystem `legacy-tc` | CREATED → ACTIVE |
| PriceList `TCA-BASE` | CREATED → ACTIVE, Currency = AUD |
| Mapping legacy-tc / dbco `2507` → TCA-BASE | CREATED |
| AUD currency | REUSED (not created) |
| TCUS-BASE / TCASIA-BASE | NOT created |
| dbco 2517 / 2519 mappings | NOT created |

Post-seed proof:

```text
legacy-tc = ACTIVE
TCA-BASE = ACTIVE
TCA-BASE currency = AUD
dbco 2507 mapping = TCA-BASE
ForbiddenLists = 0
ForbiddenMaps = 0
```

---

## Pilot

```text
Owning Batch ID: p134-tca-BVA-20260826T010912
Style: BVA
Candidates: 20
PriceList: TCA-BASE
Currency: AUD
dbco: 2507
Identity: EXACT only
```

---

## Transaction (first apply)

```text
Started:  ~2026-08-26T01:09:12Z
Committed: yes
Rolled back: no
```

Single Azure SQL transaction for reference seed + 20 ProductVariantPrices inserts + pre-commit read-back.

---

## Results — first apply

```text
CREATE: 20
NO_CHANGE: 0
BLOCKED: 0
REVIEW: 0
MANUAL_DATA_CONFLICT: 0
```

---

## Reconciliation

### Pre-commit
```text
Exact: 20
Mismatches: 0
PASS
```

### Post-commit (Azure ProductVariantPrices)
```text
Expected: 20
Found: 20
Exact matches: 20
Mismatches: 0
Missing: 0
Unexpected: 0
Duplicates: 0
PASS
```

### Legacy-source (tc.price)
```text
MATCH: 20
SOURCE_CHANGED: 0
MISSING_SOURCE: 0
```

Protected artifact: `tmp/phase13.4-tca-pilot-reconciliation.json`

---

## Side-effect deltas (first apply)

```text
ProductVariantPrices:     +20
ProductPrices:              0
CustomerProductPrices:      0
PricingTiers:               0
PriceListCustomers:         0
```

Current totals after pilot: PVP=20, PP=0, CPP=0, PT=0, PLC=0

---

## Idempotency check

Re-ran the same BVA pilot (`--apply` validation path) after commit.

```text
CREATE: 0
NO_CHANGE: 20
ProductVariantPrices delta: 0
Duplicates: 0
reconciliation: PASS
```

No duplicate ProductVariantPrices rows were created. Ownership remains on the original batch.

---

## Rollback eligibility

```text
Rollback executed: NO (awaiting separate operator approval)
Rollback-protected records: 0
Eligible: 20 batch-owned ProductVariantPrices
```

Exact rollback command (prices only; does **not** remove shared reference data):

```bash
php bin/product-pricing-phase13.4-rollback.php \
  --batch=p134-tca-BVA-20260826T010912 \
  --confirm-non-production \
  --confirm-rollback
```

---

## Hard stop

```text
STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL
```

Do **not**:

- migrate another BVA variant
- migrate another style
- migrate remaining TCA prices
- migrate TCUS / TCASIA
- calculate sell prices
- modify RFQ
- enable public pricing
- execute rollback without a new explicit approval
