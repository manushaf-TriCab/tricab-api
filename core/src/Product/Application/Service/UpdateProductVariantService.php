<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\UpdateProductVariantCommand;
use TriCab\Core\Product\Application\DTO\ProductMutationResult;
use TriCab\Core\Product\Application\Exception\ProductConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\ProductVariantNotFound;
use TriCab\Core\Product\Contract\ProductVariantRepository;
use TriCab\Core\Product\Domain\Exception\ProductConcurrencyException;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Product\Domain\ValueObject\ProductCodeValue;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Product\Domain\ValueObject\Sku;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class UpdateProductVariantService
{
    public function __construct(
        private readonly ProductVariantRepository $variants,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, UpdateProductVariantCommand $command): ProductMutationResult
    {
        return $this->transaction->run(function () use ($actor, $command): ProductMutationResult {
            $publicId = ProductVariantPublicId::fromString($command->publicId);
            $existing = $this->variants->findByPublicId($publicId);
            if ($existing === null) {
                throw ProductVariantNotFound::forPublicId($command->publicId);
            }

            $existing->assertEditable();

            $updated = $existing->withContent(
                variantName: $command->variantName ?? $existing->variantName(),
                status: $command->statusCode !== null
                    ? ProductStatusCode::fromString($command->statusCode)
                    : $existing->status(),
                displayOrder: $command->displayOrder !== null
                    ? DisplayOrder::fromInt($command->displayOrder)
                    : $existing->displayOrder(),
                sku: $command->sku !== null ? Sku::fromString($command->sku) : $existing->sku(),
                variantCode: $command->variantCode !== null
                    ? ProductCodeValue::fromString($command->variantCode)
                    : $existing->variantCode(),
                minimumOrderQuantity: $command->minimumOrderQuantity ?? $existing->minimumOrderQuantity(),
                weightKg: $command->weightKg ?? $existing->weightKg(),
                packagingDescription: $command->packagingDescription ?? $existing->packagingDescription(),
                erpReference: $command->erpReference ?? $existing->erpReference(),
                isActive: $command->isActive ?? $existing->isActive(),
            );

            $expectedRowVersion = RowVersionToken::fromBase64($command->expectedRowVersion);
            $actorUserId = $this->audit->actorUserId($actor);

            try {
                $saved = $this->variants->save($updated, $actorUserId, $expectedRowVersion);
            } catch (ProductConcurrencyException $exception) {
                throw ProductConcurrencyConflict::fromDomainException($exception);
            }

            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-variant',
                entityId: $saved->id()?->toInt(),
                actionCode: 'updated',
                previousValues: ['variantName' => $existing->variantName()],
                newValues: ['variantName' => $saved->variantName()],
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
