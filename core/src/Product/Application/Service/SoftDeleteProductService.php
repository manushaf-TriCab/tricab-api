<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\SoftDeleteProductCommand;
use TriCab\Core\Product\Application\DTO\ProductMutationResult;
use TriCab\Core\Product\Application\Exception\ProductConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Domain\Exception\ProductConcurrencyException;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

final class SoftDeleteProductService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function execute(ActorContext $actor, SoftDeleteProductCommand $command): ProductMutationResult
    {
        return $this->transaction->run(function () use ($actor, $command): ProductMutationResult {
            $publicId = ProductPublicId::fromString($command->publicId);
            $existing = $this->products->findByPublicId($publicId);
            if ($existing === null) {
                throw ProductNotFound::forPublicId($command->publicId);
            }

            $actorUserId = $this->audit->actorUserId($actor);
            $deleted = $existing->softDelete($this->clock->now(), $actorUserId);
            $expectedRowVersion = RowVersionToken::fromBase64($command->expectedRowVersion);

            try {
                $saved = $this->products->save($deleted, $actorUserId, $expectedRowVersion);
            } catch (ProductConcurrencyException $exception) {
                throw ProductConcurrencyConflict::fromDomainException($exception);
            }

            $this->audit->record(
                actor: $actor,
                productPublicId: $saved->publicId(),
                entityTypeCode: 'product',
                entityId: $saved->id()?->toInt(),
                actionCode: 'soft-deleted',
            );

            return new ProductMutationResult(
                publicId: $saved->publicId()->toString(),
                rowVersion: $saved->rowVersion()?->toBase64() ?? '',
                statusCode: $saved->status()->toString(),
                createdAtUtc: null,
                updatedAtUtc: (new DateTimeImmutable())->format(DATE_ATOM),
            );
        });
    }
}
