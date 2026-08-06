<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\CreateProduct360SetCommand;
use TriCab\Core\Product\Application\DTO\Product360SetSummary;
use TriCab\Core\Product\Application\Exception\ProductFileUploadException;
use TriCab\Core\Product\Contract\Product360Repository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class CreateProduct360SetService
{
    public function __construct(
        private readonly Product360Repository $sets,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, CreateProduct360SetCommand $command): Product360SetSummary
    {
        return $this->transaction->run(function () use ($actor, $command): Product360SetSummary {
            if ($command->expectedFrameCount < 1) {
                throw ProductFileUploadException::invalidState('expectedFrameCount must be at least 1.');
            }

            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $created = $this->sets->createSet(
                $productPublicId,
                [
                    'title' => $command->title,
                    'expectedFrameCount' => $command->expectedFrameCount,
                    'displayOrder' => $command->displayOrder,
                ],
                $this->audit->actorUserId($actor),
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-360-set',
                entityId: null,
                actionCode: 'created',
                newValues: [
                    'setPublicId' => $created->publicId,
                    'expectedFrameCount' => $created->expectedFrameCount,
                ],
            );

            return $created;
        });
    }
}
