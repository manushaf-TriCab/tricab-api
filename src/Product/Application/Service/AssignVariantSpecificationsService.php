<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\AssignVariantSpecificationsCommand;
use TriCab\Core\Product\Contract\ProductSpecificationRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class AssignVariantSpecificationsService
{
    public function __construct(
        private readonly ProductSpecificationRepository $specifications,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, AssignVariantSpecificationsCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $variantPublicId = ProductVariantPublicId::fromString($command->variantPublicId);
            $values = SpecificationCommandAssembler::assemble(
                $this->specifications->listDefinitions(true),
                $command->values,
                'variant',
            );

            $actorUserId = $this->audit->actorUserId($actor);
            $this->specifications->replaceVariantValues($variantPublicId, $values, $actorUserId);

            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'variant-specification',
                entityId: null,
                actionCode: 'replaced',
                newValues: ['count' => count($values)],
            );
        });
    }
}
