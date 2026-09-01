# Cable Calculator Product Code Coverage

Generated: 2026-08-31T22:37:12+00:00

## Dataset

- datasetVersion: `v1`
- baselineLabel: `LOCAL_LEGACY_PARITY_BASELINE`
- lookupMode: `no-product-db`

## Summary

| Metric | Count |
| --- | ---: |
| Total calculator product codes | 17995 |
| Active cable codes | 17928 |
| Earth cable codes | 67 |
| Resolved in Product Module | 0 |
| Not migrated | 17995 |
| Ambiguous | 0 |
| Inactive | 0 |
| Error | 0 |

## Notes

- Codes are derived from `tc.cable_specs` snapshot rows via `CableProductCodeGenerator`.
- Lookup uses exact `product.ProductCodes.CodeValue` identity — no nearest-size substitution.
- When Product Module DB is unavailable (`lookupMode=no-product-db`), all codes are reported as `NOT_MIGRATED`.
- Engineering calculation remains valid regardless of these resolution statuses.

## Sample not-migrated codes
- `B1-PAXA/4C10BK` (ACTIVE B1)
- `B1-PAXA/4C120BK` (ACTIVE B1)
- `B1-PAXA/4C150BK` (ACTIVE B1)
- `B1-PAXA/4C16BK` (ACTIVE B1)
- `B1-PAXA/4C185BK` (ACTIVE B1)
- `B1-PAXA/4C240BK` (ACTIVE B1)
- `B1-PAXA/4C25BK` (ACTIVE B1)
- `B1-PAXA/4C35BK` (ACTIVE B1)
- `B1-PAXA/4C50BK` (ACTIVE B1)
- `B1-PAXA/4C70BK` (ACTIVE B1)
- `B1-PAXA/4C95BK` (ACTIVE B1)
- `B1-PAXN/4C10OR` (ACTIVE B1)
- `B1-PAXN/4C120OR` (ACTIVE B1)
- `B1-PAXN/4C150OR` (ACTIVE B1)
- `B1-PAXN/4C16OR` (ACTIVE B1)
- `B1-PAXN/4C185OR` (ACTIVE B1)
- `B1-PAXN/4C240OR` (ACTIVE B1)
- `B1-PAXN/4C25OR` (ACTIVE B1)
- `B1-PAXN/4C35OR` (ACTIVE B1)
- `B1-PAXN/4C50OR` (ACTIVE B1)
- `B1-PAXN/4C70OR` (ACTIVE B1)
- `B1-PAXN/4C95OR` (ACTIVE B1)
- `B3-PAXA/4C10BK` (ACTIVE B3)
- `B3-PAXA/4C120BK` (ACTIVE B3)
- `B3-PAXA/4C150BK` (ACTIVE B3)
