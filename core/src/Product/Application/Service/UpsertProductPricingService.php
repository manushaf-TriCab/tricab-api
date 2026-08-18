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
 * Internal/admin-only pricing. Never exposed via public product reads.
 * The unit price is masked (`***`) in audit records — only the currency and
 * minimum quantity are recorded in clear text.
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
            $unitPrice = Money::of($command->unitPrice, $currency);

            $actorUserId = $this->audit->actorUserId($actor);

            $saved = $this->pricing->upsert(
                $productPublicId,
                [
                    'currencyCode' => $currency->toString(),
                    'unitPrice' => $unitPrice->amount(),
                    'minimumQuantity' => $command->minimumQuantity,
                    'priceListPublicId' => $command->priceListPublicId,
                    'regionPublicId' => $command->regionPublicId,
                    'marketPublicId' => $command->marketPublicId,
                    'discountPercent' => $command->discountPercent,
                    'taxInclusive' => $command->taxInclusive,
                    'effectiveFromUtc' => $command->effectiveFromUtc,
                    'effectiveToUtc' => $command->effectiveToUtc,
                    'variantPublicId' => $command->variantPublicId,
                ],
                $actorUserId,
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-pricing',
                entityId: null,
                actionCode: 'upserted',
                newValues: [
                    'currencyCode' => $saved->currencyCode,
                    'unitPrice' => $saved->unitPrice,
                    'minimumQuantity' => $saved->minimumQuantity,
                ],
            );

            return $saved;
        });
    }
}
