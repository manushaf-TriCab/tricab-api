<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Pricing;

use TriCab\Core\Product\Application\Pricing\CurrencyFxRateRepository;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\Product\Migration\Pricing\LegacyCyrteMappingContract;

/** Read-only cyrte FX rates for proven currencies only. */
final class MysqlLegacyCurrencyFxRepository implements CurrencyFxRateRepository
{
    public function __construct(
        private readonly LegacyReadOnlyConnection $tc,
        private readonly LegacyCyrteMappingContract $cyrte = new LegacyCyrteMappingContract(),
    ) {
    }

    public function findLatestRate(string $sellCurrencyCode): ?array
    {
        $currency = strtoupper(trim($sellCurrencyCode));
        $id = match ($currency) {
            'AUD' => LegacyCyrteMappingContract::AUD,
            'USD' => LegacyCyrteMappingContract::USD,
            'NZD' => LegacyCyrteMappingContract::NZD,
            default => null,
        };
        if ($id === null || $this->cyrte->currencyForId($id) === null) {
            return null;
        }

        $rows = $this->tc->query(
            'SELECT `cyrte` AS rate FROM `cyrte` WHERE `id` = :id ORDER BY `de` DESC LIMIT 1',
            ['id' => $id],
            'customer-pricing',
            'cyrte',
        );
        if ($rows === [] || !isset($rows[0]['rate']) || !is_numeric((string) $rows[0]['rate'])) {
            return null;
        }

        return [
            'rate' => (string) $rows[0]['rate'],
            'source' => 'cyrte:' . $id,
            'cyrteId' => $id,
        ];
    }
}
