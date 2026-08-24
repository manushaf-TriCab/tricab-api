<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\UpsertProductPricingCommand;
use TriCab\Core\Product\Application\DTO\ProductPricingSummary;
use TriCab\Core\Product\Contract\ProductPricingRepository;
use TriCab\Core\Product\Domain\ValueObject\CurrencyCode;
use TriCab\Core\Product\Domain\ValueObject\Money;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Internal/admin-only base-cost maintenance. Never exposed via public product reads.
 * Audit masks baseCost values.
 */
final class UpsertProductPricingService
{
    public function __construct(
        private readonly ProductPricingRepository $pricing,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, UpsertProductPricingCommand $command): ProductPricingSummary
    {
        return $this->transaction->run(function () use ($actor, $command): ProductPricingSummary {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $currency = CurrencyCode::fromString($command->currencyCode);
            $baseCost = Money::of($command->baseCost, $currency);

            $actorUserId = $this->audit->actorUserId($actor);

            $saved = $this->pricing->upsert(
                $productPublicId,
                [
                    'currencyCode' => $currency->toString(),
                    'baseCost' => $baseCost->amount(),
                    'minimumQuantity' => $command->minimumQuantity,
                    'priceListPublicId' => $command->priceListPublicId,
                    'regionPublicId' => $command->regionPublicId,
                    'marketPublicId' => $command->marketPublicId,
                    'discountPercent' => $command->discountPercent,
                    'taxInclusive' => $command->taxInclusive,
                    'effectiveFromUtc' => $command->effectiveFromUtc,
                    'effectiveToUtc' => $command->effectiveToUtc,
                    'variantPublicId' => $command->variantPublicId,
                    'priceSourceCode' => $command->priceSourceCode,
                    'createRevision' => $command->createRevision,
                    'pricingPublicId' => $command->pricingPublicId,
                    'expectedRowVersion' => $command->expectedRowVersion,
                    'unitOfMeasurePublicId' => $command->unitOfMeasurePublicId,
                ],
                $actorUserId,
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-pricing',
                entityId: null,
                actionCode: $command->pricingPublicId !== null ? 'updated' : 'created',
                newValues: [
                    'currencyCode' => $saved->currencyCode,
                    'baseCost' => $saved->baseCost,
                    'minimumQuantity' => $saved->minimumQuantity,
                    'variantPublicId' => $saved->variantPublicId,
                    'priceSourceCode' => $saved->priceSourceCode,
                    'unitOfMeasureCode' => $saved->unitOfMeasureCode,
                ],
            );

            return $saved;
        });
    }
}
