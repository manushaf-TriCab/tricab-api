# Product Module — Phase 2 Architecture Correction

See chat report for full file review. Key decisions:

1. ProductVariant = separate aggregate root
2. Command vs query repository split
3. Archive != soft-delete
4. ProductStatusPolicy = transitions only
5. ProductPublishPolicy = entity publish checks; ProductPublishReadinessEvaluator = Phase 4 cross-aggregate
6. RowVersion on Product + ProductVariant domain entities only
7. Pricing remains internal foundation
