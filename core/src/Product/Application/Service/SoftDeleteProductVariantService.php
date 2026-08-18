<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\SoftDeleteProductVariantCommand;
use TriCab\Core\Product\Application\DTO\ProductMutationResult;
use TriCab\Core\Product\Application\Exception\ProductConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\ProductVariantNotFound;
use TriCab\Core\Product\Contract\ProductVariantRepository;
use TriCab\Core\Product\Domain\Exception\ProductConcurrencyException;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

final class SoftDeleteProductVariantService
{
    public function __construct(
        private readonly ProductVariantRepository $variants,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function execute(ActorContext $actor, SoftDeleteProductVariantCommand $command): ProductMutationResult
    {
        return $this->transaction->run(function () use ($actor, $command): ProductMutationResult {
            $publicId = ProductVariantPublicId::fromString($command->publicId);
            $existing = $this->variants->findByPublicId($publicId);
            if ($existing === null) {
                throw ProductVariantNotFound::forPublicId($command->publicId);
            }

            $actorUserId = $this->audit->actorUserId($actor);
            $deleted = $existing->softDelete($this->clock->now(), $actorUserId);
            $expectedRowVersion = RowVersionToken::fromBase64($command->expectedRowVersion);

            try {
                $saved = $this->variants->save($deleted, $actorUserId, $expectedRowVersion);
            } catch (ProductConcurrencyException $exception) {
                throw ProductConcurrencyConflict::fromDomainException($exception);
            }

            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-variant',
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
