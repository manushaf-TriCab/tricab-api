<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\SetProductDefaultPricingUnitCommand;
use TriCab\Core\Product\Application\DTO\ProductPricingOverview;
use TriCab\Core\Product\Contract\ProductPricingRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class SetProductDefaultPricingUnitService
{
    public function __construct(
        private readonly ProductPricingRepository $pricing,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, SetProductDefaultPricingUnitCommand $command): ProductPricingOverview
    {
        return $this->transaction->run(function () use ($actor, $command): ProductPricingOverview {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $actorUserId = $this->audit->actorUserId($actor);

            $overview = $this->pricing->setProductDefaultPricingUnit(
                $productPublicId,
                $command->pricingUnitPublicId,
                $actorUserId,
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-pricing-unit',
                entityId: null,
                actionCode: $command->pricingUnitPublicId === null ? 'cleared' : 'updated',
                newValues: [
                    'pricingUnitPublicId' => $command->pricingUnitPublicId,
                    'defaultUnitOfMeasureCode' => $overview->defaultUnitOfMeasureCode,
                ],
            );

            return $overview;
        });
    }
}
