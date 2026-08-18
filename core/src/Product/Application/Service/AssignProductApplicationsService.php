<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\AssignProductApplicationsCommand;
use TriCab\Core\Product\Application\Exception\InvalidMarketAssignment;
use TriCab\Core\Product\Contract\ProductMarketRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Hard replacement of the product's application links (no soft-delete
 * concept applies to this link table).
 */
final class AssignProductApplicationsService
{
    public function __construct(
        private readonly ProductMarketRepository $markets,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, AssignProductApplicationsCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);

            $activeApplicationIds = array_map(
                static fn ($item): string => $item->publicId,
                $this->markets->listApplications(true),
            );
            foreach ($command->applicationPublicIds as $applicationPublicId) {
                if (!in_array($applicationPublicId, $activeApplicationIds, true)) {
                    throw InvalidMarketAssignment::unknownApplication($applicationPublicId);
                }
            }

            $actorUserId = $this->audit->actorUserId($actor);
            $this->markets->replaceProductApplications($productPublicId, $command->applicationPublicIds, $actorUserId);

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-application',
                entityId: null,
                actionCode: 'replaced',
                newValues: ['applicationPublicIds' => $command->applicationPublicIds],
            );
        });
    }
}
