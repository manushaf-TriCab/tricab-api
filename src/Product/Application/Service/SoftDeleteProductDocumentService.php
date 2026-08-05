<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\SoftDeleteProductDocumentCommand;
use TriCab\Core\Product\Contract\ProductDocumentRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class SoftDeleteProductDocumentService
{
    public function __construct(
        private readonly ProductDocumentRepository $documents,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, SoftDeleteProductDocumentCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $actorUserId = $this->audit->actorUserId($actor);

            $this->documents->archive($productPublicId, $command->documentPublicId, $actorUserId);

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-document',
                entityId: null,
                actionCode: 'soft-deleted',
                newValues: ['documentPublicId' => $command->documentPublicId],
            );
        });
    }
}
