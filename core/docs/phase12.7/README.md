# Phase 12.7 artifacts

- `master-data-dry-run.json` — full dry-run (zero Azure/legacy writes)
- `apply-candidates.json` — operations marked PROPOSED_FOR_APPROVAL
- `scoped-apply-result.json` — SpecificationUnits + ACCESSORIES family + migration schema (applied)
- `batch-a-*.json` — Batch A Categories/Series classification review + dry-run (apply **not** run)

Batch A docs: `../product-phase12.7-batch-a-classification.md`

```bash
php bin/apply-product-migration-classification.php --dry-run --batch-a --offline
```

`--apply` is refused until explicit Batch A apply approval.

Earlier scoped master-data apply used:

```bash
php bin/apply-product-migration-master-data.php --apply --scope=... --confirm-non-production
```
