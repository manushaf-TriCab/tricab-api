<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\AssignProductSpecificationsCommand;
use TriCab\Core\Product\Contract\ProductSpecificationRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Validates each specification value against its definition (scope + data
 * type shape) before replacing the full product-level value set in one
 * transaction. Range/min-max enforcement is deferred: the read contract
 * (ProductSpecificationRepository::listDefinitions) does not currently expose
 * minimumValue/maximumValue/decimalPrecision, only scope and data type.
 */
final class AssignProductSpecificationsService
{
    public function __construct(
        private readonly ProductSpecificationRepository $specifications,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, AssignProductSpecificationsCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $values = SpecificationCommandAssembler::assemble(
                $this->specifications->listDefinitions(true),
                $command->values,
                'product',
            );

            $actorUserId = $this->audit->actorUserId($actor);
            $this->specifications->replaceProductValues($productPublicId, $values, $actorUserId);

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-specification',
                entityId: null,
                actionCode: 'replaced',
                newValues: ['count' => count($values)],
            );
        });
    }
}
