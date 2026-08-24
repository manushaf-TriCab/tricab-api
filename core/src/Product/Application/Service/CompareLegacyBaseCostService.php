<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\CompareLegacyBaseCostCommand;
use TriCab\Core\Product\Application\Command\ResolveLegacyVariantPricingIdentityCommand;
use TriCab\Core\Product\Application\DTO\LegacyBaseCostComparison;
use TriCab\Core\Product\Application\Exception\PriceListNotFound;
use TriCab\Core\Product\Contract\PriceListRepository;
use TriCab\Core\Product\Contract\ProductPricingRepository;
use TriCab\Core\Product\Domain\ValueObject\PriceListLegacyContext;
use TriCab\Core\Product\Migration\Contract\LegacyTcRepository;

final class CompareLegacyBaseCostService
{
    public function __construct(
        private readonly ProductPricingRepository $pricing,
        private readonly LegacyTcRepository $legacyTc,
        private readonly PriceListRepository $priceLists,
        private readonly ResolveLegacyVariantPricingIdentityService $identityResolver,
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

        $priceListPublicId = $command->priceListPublicId;
        if ($priceListPublicId === null || $priceListPublicId === '') {
            throw new \InvalidArgumentException('priceListPublicId is required.');
        }

        $identity = $this->identityResolver->execute(new ResolveLegacyVariantPricingIdentityCommand(
            variantPublicId: $command->variantPublicId,
            priceListPublicId: $priceListPublicId,
        ));

        $dbco = $identity->legacyDbco;
        $legacyCurrency = $dbco !== null ? $this->debtorCurrency($dbco) : null;
        $legacyPriceCode = $identity->legacyPriceCode;

        $legacyPrice = $legacyPriceCode !== null && $dbco !== null
            ? $this->legacyTc->findLatestBaseCost($legacyPriceCode, $dbco)
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

        $message = null;
        if ($legacyPriceCode === null) {
            $message = 'Legacy pricing identity could not be resolved for this variant.';
        } elseif ($legacyPrice === null) {
            $message = 'Legacy tc.price row not available for resolved pricing code.';
        }

        $comparison = new LegacyBaseCostComparison(
            variantPublicId: $command->variantPublicId,
            variantName: $row?->variantName,
            legacyItemCode: $legacyPriceCode,
            legacyPriceCode: $legacyPriceCode,
            newBaseCost: $row?->baseCost,
            newCurrencyCode: $row?->currencyCode,
            newPriceSourceCode: $row?->priceSourceCode,
            legacyBaseCost: isset($legacyPrice['cost']) ? (string) $legacyPrice['cost'] : null,
            legacyCurrencyCode: $legacyCurrency,
            legacyDebtorCompanyCode: $dbco,
            legacyLastUpdatedUtc: isset($legacyPrice['lastUpdatedUtc']) ? (string) $legacyPrice['lastUpdatedUtc'] : null,
            differenceAmount: $difference,
            legacyAvailable: $legacyPrice !== null,
            message: $message,
            resolutionStatus: $identity->resolutionStatus,
            resolutionSource: $identity->resolutionSource,
            priceDataStatus: $identity->priceDataStatus,
        );

        $this->audit->record(
            actor: $actor,
            productPublicId: $productPublicId,
            entityTypeCode: 'product-pricing',
            entityId: null,
            actionCode: 'legacy-comparison-viewed',
            newValues: [
                'variantPublicId' => $command->variantPublicId,
                'legacyDebtorCompanyCode' => $dbco !== null ? (string) $dbco : null,
                'priceListPublicId' => $command->priceListPublicId,
                'resolutionStatus' => $identity->resolutionStatus,
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
