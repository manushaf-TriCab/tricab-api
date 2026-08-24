# Phase 12.7 Batch C — Style-specific Specification Field Mapping

Generated: AVAILABLE @ tricab-mel / tc

## Why d/v fields are style-specific

Legacy `d1`–`d7` / `v1`–`v4` are **not** globally meaningful. The same position means different concepts per style (e.g. J01+d1 → Pins, D01+d1 → Thread OD). Mapping identity is:

`SourceSystem + StyleCode + SourceField → SpecificationDefinition`

## Source evidence

- Discovery: `core/docs/phase12.6-mappings/dv-field-mappings.proposed.json`
- Labels: legacy `spec_labels` (via discovery)
- Values: `accessory_specs` samples
- Target Definitions: live tricab-mel/tc (42 after Batch B)

## Mapping precedence

1. Existing explicit style-field mapping (conflict if different target)
2. Exact approved Definition semantic match
3. High-confidence label → Definition table
4. PROPOSED_MAP / REVIEW
5. REJECT for global `*` style rows

## Operator approval

- `APPROVED_MAP` only when target Definition exists and is not deferred (LENGTH/WIDTH/etc.)
- Group approval only when all members share label + target + compatible type/unit
- Artifacts: `batch-c-approved-field-mappings.json`, `batch-c-review-field-mappings.json`, `batch-c-conflicts.json`

## Dry-run

No target writes. Guard: `BATCH_C_APPLY_NOT_APPROVED`.

## Future apply

```
php bin/apply-product-migration-specification-masters.php --apply --batch-c --confirm-non-production --batch-c-apply-approved
```

(Not implemented until explicit approval.)

## Next action

STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL
