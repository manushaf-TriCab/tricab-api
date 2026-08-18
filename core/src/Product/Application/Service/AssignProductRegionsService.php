<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\AssignProductRegionsCommand;
use TriCab\Core\Product\Application\DTO\RegionListCriteria;
use TriCab\Core\Product\Application\Exception\InvalidMarketAssignment;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Contract\ProductMarketRepository;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Replaces region assignments for a product without changing market assignments.
 */
final class AssignProductRegionsService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductMarketRepository $markets,
        private readonly GetRegionsService $regions,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, AssignProductRegionsCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $product = $this->products->findByPublicId($productPublicId);
            if ($product === null) {
                throw ProductNotFound::forPublicId($command->productPublicId);
            }

            $this->assertNoDuplicateRegions($command->regionPublicIds);

            $activeRegionIds = $this->activeRegionPublicIds();
            foreach ($command->regionPublicIds as $regionPublicId) {
                if (!in_array($regionPublicId, $activeRegionIds, true)) {
                    throw InvalidMarketAssignment::unknownRegion($regionPublicId);
                }
            }

            if ($product->status()->isPublished() && $command->regionPublicIds === []) {
                throw InvalidMarketAssignment::publishedRequiresRegion();
            }

            $actorUserId = $this->audit->actorUserId($actor);
            $this->markets->replaceProductRegions($productPublicId, $command->regionPublicIds, $actorUserId);

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-region',
                entityId: null,
                actionCode: 'replaced',
                newValues: ['regionPublicIds' => $command->regionPublicIds],
            );
        });
    }

    /**
     * @param list<string> $regionPublicIds
     */
    private function assertNoDuplicateRegions(array $regionPublicIds): void
    {
        if (count($regionPublicIds) !== count(array_unique($regionPublicIds))) {
            throw InvalidMarketAssignment::duplicateRegions();
        }
    }

    /**
     * @return list<string>
     */
    private function activeRegionPublicIds(): array
    {
        $result = $this->regions->adminList(new RegionListCriteria(
            page: 1,
            pageSize: 100,
            isActive: true,
            sortBy: 'displayOrder',
            sortDirection: 'asc',
        ));

        return array_map(
            static fn ($item): string => $item->publicId,
            $result->items,
        );
    }
}
