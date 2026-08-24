<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\LegacyPriceContextVerification;
use TriCab\Core\Product\Domain\ValueObject\PriceListLegacyContext;
use TriCab\Core\Product\Migration\Contract\LegacyTcRepository;

final class VerifyLegacyPriceContextService
{
    public function __construct(
        private readonly LegacyTcRepository $legacyTc,
    ) {
    }

    public function verify(string $sourceCode, string $contextType, string $contextCode): LegacyPriceContextVerification
    {
        $source = strtolower(trim($sourceCode));
        $type = strtolower(trim($contextType));
        $code = trim($contextCode);

        if ($source !== PriceListLegacyContext::SYSTEM_CODE || $type !== PriceListLegacyContext::CONTEXT_TYPE) {
            return new LegacyPriceContextVerification(
                connectionOk: true,
                legacySourceCode: $source,
                legacyContextType: $type,
                legacyContextCode: $code,
                priceRowCount: null,
                distinctCodeCount: null,
                valid: false,
                message: 'Only legacy-tc / dbco verification is supported in Phase 2E.',
            );
        }

        if ($code === '' || !ctype_digit($code)) {
            return new LegacyPriceContextVerification(
                connectionOk: true,
                legacySourceCode: $source,
                legacyContextType: $type,
                legacyContextCode: $code,
                priceRowCount: null,
                distinctCodeCount: null,
                valid: false,
                message: 'dbco must be a numeric legacy debtor company code.',
            );
        }

        try {
            $stats = $this->legacyTc->aggregatePriceContextByDbco((int) $code);
        } catch (\Throwable $exception) {
            return new LegacyPriceContextVerification(
                connectionOk: false,
                legacySourceCode: $source,
                legacyContextType: $type,
                legacyContextCode: $code,
                priceRowCount: null,
                distinctCodeCount: null,
                valid: false,
                message: $exception->getMessage(),
            );
        }

        if ($stats === null) {
            return new LegacyPriceContextVerification(
                connectionOk: true,
                legacySourceCode: $source,
                legacyContextType: $type,
                legacyContextCode: $code,
                priceRowCount: 0,
                distinctCodeCount: 0,
                valid: false,
                message: 'Legacy tc.price table is unavailable or dbco was not found.',
            );
        }

        return new LegacyPriceContextVerification(
            connectionOk: true,
            legacySourceCode: $source,
            legacyContextType: $type,
            legacyContextCode: $code,
            priceRowCount: $stats['priceRowCount'],
            distinctCodeCount: $stats['distinctCodeCount'],
            valid: ($stats['priceRowCount'] ?? 0) > 0,
            message: ($stats['priceRowCount'] ?? 0) > 0 ? null : 'No legacy price rows found for this dbco.',
        );
    }
}
