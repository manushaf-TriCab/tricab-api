# PHASE 13.4 — Controlled TCA Base Cost Pilot Apply

**Status:** INFRASTRUCTURE + DRY-RUN COMPLETE — **no pricing apply executed**  
**Generated:** 2026-08-26

## Decision (pre-apply)

```text
PILOT_APPLY_READY
```

Dry-run only. Apply was **not** requested. Waiting for explicit operator approval before any writes.

---

## 1. Safety

```text
APP_ENV: development
Server: tricab-mel
Database: tc
Production: NO

Legacy writes: 0
Azure pricing writes: 0
Other target writes: 0
```

---

## 2. Pilot preview (style = BVA)

```text
Pilot style: BVA
Candidate variants: 20

PriceList: TCA-BASE
Currency: AUD
dbco: 2507

Identity:
EXACT: 20

Zero cost: 0
Ambiguous: 0
Collisions: 0
Existing target conflicts: 0

Expected INSERT: 20
Expected NO_CHANGE: 0
Expected REVIEW: 0
```

Proposed batch id (example from last dry-run): `pricing-p13.4-tca-BVA-20260826T010022`

Artifact: `tmp/phase13.4-tca-pilot-preview.json`

---

## 3. Reference data

```text
legacy-tc: MISSING
AUD: ACTIVE
TCA-BASE: MISSING

referencePlan.status: CREATED
actions:
  - CREATE_EXTERNAL_SYSTEM_legacy-tc
  - CREATE_PRICELIST_TCA-BASE
  - CREATE_MAPPING_dbco_2507
```

Scoped TCA seed runs **only inside** an approved `--apply` transaction (not TCUS/TCASIA).

---

## 4. Delivered components

| Component | Path |
|-----------|------|
| Ownership codec | `Phase134MigrationOwnership.php` |
| CLI guard | `Phase134PilotCliGuard.php` |
| Candidate select | `SelectTcaPilotCandidatesService.php` |
| Reference plan | `EnsureTcaPricingReferenceDataService.php` |
| Apply (in-memory + orchestration) | `ApplyTcaBaseCostPilotService.php` |
| Rollback | `RollbackTcaBaseCostPilotService.php` |
| Pilot CLI | `bin/product-pricing-phase13.4-pilot.php` |
| Rollback CLI | `bin/product-pricing-phase13.4-rollback.php` |

---

## 5. Tests

```text
Phase 13.2 / 13.2A / 13.3 / 13.4 suite: 46 tests OK
```

Covered: reference create/no-change/conflict, TCA EXACT style filter, create/idempotent no-change, manual conflict, mid-txn rollback, source drift, rollback protection + reapply, side-effect zeros, CLI confirmations.

---

## 6. How to apply (operator only — not run)

```bash
php bin/product-pricing-phase13.4-pilot.php \
  --style=BVA \
  --max-variants=20 \
  --apply \
  --confirm-non-production \
  --confirm-pilot
```

Rollback:

```bash
php bin/product-pricing-phase13.4-rollback.php \
  --batch=<BATCH_ID> \
  --confirm-non-production \
  --confirm-rollback
```

---

## 7. Hard stop

```text
STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL
```

Do **not** bulk-migrate TCA.  
Do **not** apply TCUS/TCASIA.  
Do **not** migrate tmktm / sell prices.  
Do **not** expand after a future successful pilot without a new phase approval.

**Next (only if you explicitly approve apply):** run the apply command above for BVA (≤20), then reconcile + optional rollback drill — still no scope expansion.
