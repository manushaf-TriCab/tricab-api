<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Pricing;

/**
 * Phase 13.2 approved policies for history, duplicates, and zero-cost handling.
 * Pure evaluation — no database writes.
 */
final class LegacyPriceImportPolicy
{
    public const HISTORY_LATEST_ONLY = 'LATEST_ONLY';
    public const HISTORY_FULL = 'FULL_HISTORY';
    public const HISTORY_CURRENT_PLUS = 'CURRENT_PLUS_HISTORY';

    /** Approved Phase 13.2 policy given live evidence (no multi-dc chains per code+dbco). */
    public const APPROVED_HISTORY_POLICY = self::HISTORY_LATEST_ONLY;

    public const DUP_SAFE_EQUIVALENT = 'SAFE_EQUIVALENT_DUPLICATE';
    public const DUP_DIMENSIONAL = 'DIMENSIONAL_PRICE';
    public const DUP_AMBIGUOUS = 'AMBIGUOUS';

    public const ZERO_VALID = 'VALID_ZERO_COST';
    public const ZERO_MISSING_SENTINEL = 'MISSING_COST_SENTINEL';
    public const ZERO_DATA_ERROR = 'DATA_ERROR';
    public const ZERO_REVIEW = 'REVIEW_REQUIRED';

    public const ZERO_POLICY = self::ZERO_REVIEW;

    public function utcFromLegacyDate(?string $dc): ?string
    {
        if ($dc === null || trim($dc) === '') {
            return null;
        }

        if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($dc), $m)) {
            return null;
        }

        return $m[1] . 'T00:00:00Z';
    }

    /**
     * Classify a same-(code, dbco, dc) group.
     *
     * @param list<array{cost: string, br?: ?string, uwt?: ?string, uwtcu?: ?string, setup?: ?string}> $rows
     */
    public function classifyDuplicateGroup(array $rows): string
    {
        if (count($rows) <= 1) {
            return self::DUP_SAFE_EQUIVALENT;
        }

        $costs = [];
        foreach ($rows as $row) {
            $costs[(string) $row['cost']] = true;
        }

        if (count($costs) > 1) {
            return self::DUP_AMBIGUOUS;
        }

        // Live Phase 13.2 evidence: all same-cost groups vary only by br (metadata), not cost identity.
        return self::DUP_SAFE_EQUIVALENT;
    }

    public function zeroCostDecision(string $cost): string
    {
        if (!is_numeric($cost)) {
            return self::ZERO_DATA_ERROR;
        }

        if (bccomp($cost, '0', 6) < 0) {
            return self::ZERO_DATA_ERROR;
        }

        if (bccomp($cost, '0', 6) === 0) {
            return self::ZERO_POLICY;
        }

        return 'VALID_POSITIVE_COST';
    }

    /**
     * Evaluate readiness for Phase 13.3 dry-run (zero writes).
     */
    public function evaluate(LegacyPriceRecord $record, DbcoPriceListMappingContract $dbcoContract, array $identity): PriceImportResolution
    {
        $reasons = [];
        $mapping = $dbcoContract->forDbco($record->dbco);
        $zeroDecision = $this->zeroCostDecision($record->cost);
        $duplicateDecision = self::DUP_SAFE_EQUIVALENT;
        $historyDecision = self::APPROVED_HISTORY_POLICY;

        $priceListCode = $mapping['priceListCode'] ?? null;
        $currencyCode = $mapping['currencyCode'] ?? null;
        $readiness = PriceImportResolution::READY;

        if ($mapping === null) {
            $readiness = PriceImportResolution::BLOCKED;
            $reasons[] = 'Unknown dbco — no PriceList mapping.';
        } elseif (($mapping['phase133InScope'] ?? false) !== true) {
            $readiness = PriceImportResolution::BLOCKED;
            $reasons[] = 'dbco excluded from Phase 13.3 dry-run scope.';
        } elseif ($mapping['decision'] === DbcoPriceListMappingContract::DECISION_REVIEW) {
            $readiness = PriceImportResolution::BLOCKED;
            $reasons[] = 'dbco currency/PriceList mapping is REVIEW (not operator-approved).';
        } elseif ($mapping['decision'] === DbcoPriceListMappingContract::DECISION_EXCLUDED) {
            $readiness = PriceImportResolution::BLOCKED;
            $reasons[] = 'dbco mapping is EXCLUDED from Phase 13.3.';
        } elseif ($mapping['decision'] === DbcoPriceListMappingContract::DECISION_BLOCKED) {
            $readiness = PriceImportResolution::BLOCKED;
            $reasons[] = 'dbco mapping is BLOCKED.';
        } elseif ($currencyCode === null || $currencyCode === '') {
            $readiness = PriceImportResolution::BLOCKED;
            $reasons[] = 'PriceList has no approved currency (no silent AUD fallback).';
        }

        $identityType = (string) ($identity['identityType'] ?? PriceImportResolution::IDENTITY_UNRESOLVED);
        if ($identityType === PriceImportResolution::IDENTITY_UNRESOLVED) {
            $readiness = PriceImportResolution::BLOCKED;
            $reasons[] = 'Variant identity unresolved.';
        } elseif ($identityType === PriceImportResolution::IDENTITY_AMBIGUOUS) {
            $readiness = PriceImportResolution::BLOCKED;
            $reasons[] = 'Variant identity ambiguous.';
        }

        if ($zeroDecision === self::ZERO_REVIEW || $zeroDecision === self::ZERO_MISSING_SENTINEL) {
            $readiness = PriceImportResolution::REVIEW;
            $reasons[] = 'Zero cost requires review — not auto-importable.';
        } elseif ($zeroDecision === self::ZERO_DATA_ERROR) {
            $readiness = PriceImportResolution::BLOCKED;
            $reasons[] = 'Invalid/negative cost.';
        }

        if ($duplicateDecision === self::DUP_AMBIGUOUS) {
            $readiness = PriceImportResolution::BLOCKED;
            $reasons[] = 'Same-date duplicate costs are ambiguous.';
        }

        /** @var list<string> $variantIds */
        $variantIds = array_values(array_map('strval', $identity['productVariantIds'] ?? []));
        /** @var list<string> $identityReasons */
        $identityReasons = array_values(array_map('strval', $identity['reasons'] ?? []));
        $reasons = array_merge($identityReasons, $reasons);

        return new PriceImportResolution(
            sourceRowId: $record->sourceRowId,
            identityType: $identityType,
            productId: isset($identity['productId']) ? (string) $identity['productId'] : null,
            productVariantId: isset($identity['productVariantId']) ? (string) $identity['productVariantId'] : null,
            productVariantIds: $variantIds,
            priceListCode: $priceListCode,
            priceListId: null,
            currencyCode: $currencyCode,
            currencyId: null,
            baseCost: $record->cost,
            effectiveFrom: $this->utcFromLegacyDate($record->dc),
            effectiveTo: null,
            sourceExternalKey: $record->normalizedCode . '@' . $record->dbco,
            historyDecision: $historyDecision,
            zeroCostDecision: $zeroDecision,
            duplicateDecision: $duplicateDecision,
            readiness: $readiness,
            reasons: $reasons,
        );
    }
}
