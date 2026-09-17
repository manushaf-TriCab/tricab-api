<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Domain\Mapping;

use TriCab\Core\Product\Migration\Pricing\LegacyCyrteMappingContract;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteCurrencyUnknownException;

/**
 * Bidirectional legacy cyrte id ↔ ISO currency for quote write mapping.
 * Numeric ids stay here — not scattered through the repository.
 */
final class LegacyQuoteCurrencyMapper
{
    public function __construct(
        private readonly LegacyCyrteMappingContract $contract = new LegacyCyrteMappingContract(),
    ) {
    }

    public function legacyIdForCode(string $currencyCode): int
    {
        $normalized = strtoupper(trim($currencyCode));
        $map = [
            'AUD' => LegacyCyrteMappingContract::AUD,
            'USD' => LegacyCyrteMappingContract::USD,
            'NZD' => LegacyCyrteMappingContract::NZD,
        ];
        if (!isset($map[$normalized])) {
            throw QuoteWriteCurrencyUnknownException::forCode($currencyCode);
        }

        return $map[$normalized];
    }

    public function codeForLegacyId(int $currencyId): string
    {
        $code = $this->contract->currencyForId($currencyId);
        if ($code === null) {
            throw QuoteWriteCurrencyUnknownException::forCode((string) $currencyId);
        }

        return $code;
    }

    /**
     * @return list<string>
     */
    public function supportedCodes(): array
    {
        return ['AUD', 'NZD', 'USD'];
    }
}
