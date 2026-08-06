<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\AssignProductMarketsCommand;
use TriCab\Core\Product\Application\Exception\InvalidMarketAssignment;
use TriCab\Core\Product\Contract\ProductMarketRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Replaces both region and market assignments for a product in one
 * transaction. Both lists are hard replacements (no soft-delete concept
 * applies to these link tables).
 */
final class AssignProductMarketsService
{
    public function __construct(
        private readonly ProductMarketRepository $markets,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, AssignProductMarketsCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);

            $activeRegionIds = array_map(
                static fn ($item): string => $item->publicId,
                $this->markets->listRegions(true),
            );
            foreach ($command->regionPublicIds as $regionPublicId) {
                if (!in_array($regionPublicId, $activeRegionIds, true)) {
                    throw InvalidMarketAssignment::unknownRegion($regionPublicId);
                }
            }

            $activeMarketIds = array_map(
                static fn ($item): string => $item->publicId,
                $this->markets->listMarkets(null, true),
            );
            foreach ($command->marketPublicIds as $marketPublicId) {
                if (!in_array($marketPublicId, $activeMarketIds, true)) {
                    throw InvalidMarketAssignment::unknownMarket($marketPublicId);
                }
            }

            $actorUserId = $this->audit->actorUserId($actor);
            $this->markets->replaceProductRegions($productPublicId, $command->regionPublicIds, $actorUserId);
            $this->markets->replaceProductMarkets($productPublicId, $command->marketPublicIds, $actorUserId);

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-market',
                entityId: null,
                actionCode: 'replaced',
                newValues: [
                    'regionPublicIds' => $command->regionPublicIds,
                    'marketPublicIds' => $command->marketPublicIds,
                ],
            );
        });
    }
}
