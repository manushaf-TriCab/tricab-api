<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\CompareLegacyBaseCostCommand;
use TriCab\Core\Product\Application\DTO\LegacyBaseCostComparison;
use TriCab\Core\Product\Contract\ProductPricingRepository;
use TriCab\Core\Product\Migration\Contract\LegacyTcRepository;

final class CompareLegacyBaseCostService
{
    public function __construct(
        private readonly ProductPricingRepository $pricing,
        private readonly LegacyTcRepository $legacyTc,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, CompareLegacyBaseCostCommand $command): LegacyBaseCostComparison
    {
        $productPublicId = \TriCab\Core\Product\Domain\ValueObject\ProductPublicId::fromString($command->productPublicId);
        $matrix = $this->pricing->listMatrixByProduct($productPublicId);
        $row = null;
        foreach ($matrix as $candidate) {
            if ($candidate->variantPublicId === $command->variantPublicId) {
                $row = $candidate;
                break;
            }
        }

        $legacyItemCode = $row?->legacyItemCode ?? $row?->variantCode;
        if ($legacyItemCode !== null && str_contains($legacyItemCode, ':')) {
            $legacyItemCode = substr($legacyItemCode, strrpos($legacyItemCode, ':') + 1);
        }

        $legacyPrice = $legacyItemCode !== null && $legacyItemCode !== ''
            ? $this->legacyTc->findLatestBaseCost($legacyItemCode, $command->legacyDebtorCompanyCode)
            : null;

        $difference = null;
        if (
            $row?->baseCost !== null
            && $legacyPrice !== null
            && isset($legacyPrice['cost'])
            && is_numeric($legacyPrice['cost'])
            && is_numeric($row->baseCost)
        ) {
            $difference = bcsub($row->baseCost, (string) $legacyPrice['cost'], 6);
            $difference = rtrim(rtrim($difference, '0'), '.');
        }

        $comparison = new LegacyBaseCostComparison(
            variantPublicId: $command->variantPublicId,
            variantName: $row?->variantName,
            legacyItemCode: $legacyItemCode,
            legacyPriceCode: $legacyItemCode,
            newBaseCost: $row?->baseCost,
            newCurrencyCode: $row?->currencyCode,
            newPriceSourceCode: $row?->priceSourceCode,
            legacyBaseCost: isset($legacyPrice['cost']) ? (string) $legacyPrice['cost'] : null,
            legacyCurrencyCode: $this->debtorCurrency($command->legacyDebtorCompanyCode),
            legacyDebtorCompanyCode: $command->legacyDebtorCompanyCode,
            legacyLastUpdatedUtc: isset($legacyPrice['lastUpdatedUtc']) ? (string) $legacyPrice['lastUpdatedUtc'] : null,
            differenceAmount: $difference,
            legacyAvailable: $legacyPrice !== null,
            message: $legacyPrice === null ? 'Legacy tc.price row not available for this item code.' : null,
        );

        $this->audit->record(
            actor: $actor,
            productPublicId: \TriCab\Core\Product\Domain\ValueObject\ProductPublicId::fromString($command->productPublicId),
            entityTypeCode: 'product-pricing',
            entityId: null,
            actionCode: 'legacy-comparison-viewed',
            newValues: [
                'variantPublicId' => $command->variantPublicId,
                'legacyDebtorCompanyCode' => (string) $command->legacyDebtorCompanyCode,
                'legacyAvailable' => $comparison->legacyAvailable ? 'true' : 'false',
            ],
        );

        return $comparison;
    }

    private function debtorCurrency(int $dbco): string
    {
        return match ($dbco) {
            2517 => 'USD',
            2519 => 'USD',
            default => 'AUD',
        };
    }
}
