<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\WithdrawProductDocumentCommand;
use TriCab\Core\Product\Contract\ProductDocumentRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Business "withdraw": makes a document unavailable to its intended audience
 * while retaining the audit trail. Uses the repository's archive() (soft-delete)
 * primitive — see SoftDeleteProductDocumentService for the distinct hard
 * removal intent that maps to the same primitive today.
 */
final class WithdrawProductDocumentService
{
    public function __construct(
        private readonly ProductDocumentRepository $documents,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, WithdrawProductDocumentCommand $command): void
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
                actionCode: 'withdrawn',
                newValues: ['documentPublicId' => $command->documentPublicId],
            );
        });
    }
}
