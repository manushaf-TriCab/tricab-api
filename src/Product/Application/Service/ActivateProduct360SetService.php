<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\ActivateProduct360SetCommand;
use TriCab\Core\Product\Application\DTO\Product360SetSummary;
use TriCab\Core\Product\Application\Exception\ProductFileUploadException;
use TriCab\Core\Product\Contract\Product360Repository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class ActivateProduct360SetService
{
    public function __construct(
        private readonly Product360Repository $sets,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, ActivateProduct360SetCommand $command): Product360SetSummary
    {
        return $this->transaction->run(function () use ($actor, $command): Product360SetSummary {
            $set = $this->sets->findSetByPublicId($command->setPublicId);
            if ($set === null) {
                throw ProductFileUploadException::invalidState(sprintf('360 set "%s" not found.', $command->setPublicId));
            }

            $activeFrames = array_filter(
                $set->frames,
                static fn ($frame): bool => $frame->uploadStatusCode === UploadStatusCode::ACTIVE
                    || $frame->uploadStatusCode === UploadStatusCode::UPLOADED,
            );

            if (count($activeFrames) < $set->expectedFrameCount) {
                throw ProductFileUploadException::framesIncomplete(
                    $set->publicId,
                    $set->expectedFrameCount,
                    count($activeFrames),
                );
            }

            $activated = $this->sets->activateSet($set->publicId, $this->audit->actorUserId($actor));

            $this->audit->record(
                actor: $actor,
                productPublicId: ProductPublicId::fromString($set->productPublicId),
                entityTypeCode: 'product-360-set',
                entityId: null,
                actionCode: 'activated',
                newValues: ['setPublicId' => $activated->publicId],
            );

            return $activated;
        });
    }
}
