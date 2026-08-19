<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\SetProductPricingActiveCommand;
use TriCab\Core\Product\Application\DTO\ProductPricingSummary;
use TriCab\Core\Product\Application\Exception\ProductPricingConcurrencyConflict;
use TriCab\Core\Product\Contract\ProductPricingRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class SetProductPricingActiveService
{
    public function __construct(
        private readonly ProductPricingRepository $pricing,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, SetProductPricingActiveCommand $command): ProductPricingSummary
    {
        return $this->transaction->run(function () use ($actor, $command): ProductPricingSummary {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $actorUserId = $this->audit->actorUserId($actor);

            try {
                $saved = $this->pricing->setActive(
                    $command->pricingPublicId,
                    $command->isActive,
                    $actorUserId,
                    $command->expectedRowVersion,
                );
            } catch (ProductPricingConcurrencyConflict $exception) {
                throw $exception;
            }

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-pricing',
                entityId: null,
                actionCode: $command->isActive ? 'activated' : 'deactivated',
                newValues: [
                    'pricingPublicId' => $saved->publicId,
                    'currencyCode' => $saved->currencyCode,
                    'baseCost' => $saved->baseCost,
                ],
            );

            return $saved;
        });
    }
}
