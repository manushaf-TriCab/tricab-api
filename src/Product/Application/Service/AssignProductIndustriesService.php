<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\AssignProductIndustriesCommand;
use TriCab\Core\Product\Application\Exception\InvalidMarketAssignment;
use TriCab\Core\Product\Contract\ProductMarketRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Hard replacement of the product's industry links (no soft-delete concept
 * applies to this link table).
 */
final class AssignProductIndustriesService
{
    public function __construct(
        private readonly ProductMarketRepository $markets,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, AssignProductIndustriesCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);

            $activeIndustryIds = array_map(
                static fn ($item): string => $item->publicId,
                $this->markets->listIndustries(true),
            );
            foreach ($command->industryPublicIds as $industryPublicId) {
                if (!in_array($industryPublicId, $activeIndustryIds, true)) {
                    throw InvalidMarketAssignment::unknownIndustry($industryPublicId);
                }
            }

            $actorUserId = $this->audit->actorUserId($actor);
            $this->markets->replaceProductIndustries($productPublicId, $command->industryPublicIds, $actorUserId);

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-industry',
                entityId: null,
                actionCode: 'replaced',
                newValues: ['industryPublicIds' => $command->industryPublicIds],
            );
        });
    }
}
