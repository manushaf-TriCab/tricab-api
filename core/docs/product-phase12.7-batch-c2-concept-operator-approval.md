# Phase 12.7 Batch C.2 — Concept Operator Approval + Final Field-Mapping Dry-Run

DRY-RUN ONLY. Guards: `BATCH_C2_APPLY_NOT_APPROVED`, `BATCH_C_APPLY_NOT_APPROVED`.

## Definition count reconciliation

C.1 listed **12** mechanical/dimension `PROPOSED_CREATE` Definitions.
`IND-LSZH-FIRE` was a **separate** special review — **not** in the 12 → **13th** unique candidate if pursued.
C.2 keeps `IND-LSZH-FIRE` as **REVIEW** (compound serialized flags need split analysis).

## Outer diameter

Accessory `outerdia` / `outer dia` must **not** map to `NOM-CABLE-OD`.
Approved create: `OUTER-DIAMETER`.

## Future apply order

1. Validate target
2. Create APPROVED_CREATE Definitions
3. Reload IDs
4. Insert approved + pending style-field mappings
5. Verify uniqueness / readiness
6. **No** specification value writes
