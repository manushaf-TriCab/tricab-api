<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\UnpublishProductCommand;
use TriCab\Core\Product\Application\DTO\ProductMutationResult;
use TriCab\Core\Product\Application\Exception\ProductConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Domain\Exception\ProductConcurrencyException;
use TriCab\Core\Product\Domain\Service\ProductStatusPolicy;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class UnpublishProductService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
        private readonly ProductStatusPolicy $statusPolicy = new ProductStatusPolicy(),
    ) {
    }

    public function execute(ActorContext $actor, UnpublishProductCommand $command): ProductMutationResult
    {
        return $this->transaction->run(function () use ($actor, $command): ProductMutationResult {
            $publicId = ProductPublicId::fromString($command->publicId);
            $existing = $this->products->findByPublicId($publicId);
            if ($existing === null) {
                throw ProductNotFound::forPublicId($command->publicId);
            }

            $existing->assertNotSoftDeleted();
            $this->statusPolicy->assertTransitionAllowed($existing->status(), ProductStatusCode::unpublished());

            $unpublished = $existing->withStatus(ProductStatusCode::unpublished());
            $expectedRowVersion = RowVersionToken::fromBase64($command->expectedRowVersion);
            $actorUserId = $this->audit->actorUserId($actor);

            try {
                $saved = $this->products->save($unpublished, $actorUserId, $expectedRowVersion);
            } catch (ProductConcurrencyException $exception) {
                throw ProductConcurrencyConflict::fromDomainException($exception);
            }

            $this->audit->record(
                actor: $actor,
                productPublicId: $saved->publicId(),
                entityTypeCode: 'product',
                entityId: $saved->id()?->toInt(),
                actionCode: 'unpublished',
                previousValues: ['statusCode' => $existing->status()->toString()],
                newValues: ['statusCode' => $saved->status()->toString()],
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
